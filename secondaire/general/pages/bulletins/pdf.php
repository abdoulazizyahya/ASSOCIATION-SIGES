<?php
/**
 * Bulletin scolaire — modèle LTM (trimestriel APC, secondaire général)
 * GET : eleve=ID, trim=ID_TRIMESTRE, annee=ID_ANNEE, seq=ID_SEQ (optionnel)
 * Toute la classe (même bulletin exactement, un élève à la suite de l'autre,
 * 30/10/2026) : classe=ID au lieu de eleve, + ordre=alpha|merite, serie=ID
 * (classe mixte) — appelé par pdf_classe.php. Le rendu d'un élève est dans
 * _bulletin_corps.php, ses fonctions dans _bulletin_fonctions.php.
 */
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
aiguiller_page_technique('secondaire/technique/pages/bulletins/pdf.php');   // école technique : notes par séquence
// Modèle de bulletin du cycle (Paramètres, migration v28) : APC ↔ « Matières »
// (aussi pour la vérification par QR code, qui arrive ici).
if ((int) ($_GET['classe'] ?? 0) && !(int) ($_GET['eleve'] ?? 0)) {
    sec_aiguiller_modele((int) $_GET['classe'], 'secondaire/general/pages/bulletins_matieres/pdf_classe.php', 'secondaire/general/pages/bulletins/pdf_classe.php', false);
} else {
    sec_aiguiller_modele((int) db_val("SELECT id_classe FROM inscription WHERE id_eleve=? AND id_annee=?", [(int) ($_GET['eleve'] ?? 0), (int) ($_GET['annee'] ?? 0)]), 'secondaire/general/pages/bulletins_matieres/pdf.php', 'secondaire/general/pages/bulletins/pdf.php', false);
}
require_once __DIR__ . '/../../../commun/pdf/verif_lib.php';

// Accès public via le QR code du bulletin (jeton "vh" = hash de vérification
// déjà calculé pour CE bulletin précis) : la personne qui scanne n'a pas de
// compte dans le système et ne doit pas avoir à s'y connecter pour consulter
// le document qu'elle vient de vérifier (voir verif/bulletin.php). En dehors
// de ce cas, comportement inchangé : connexion normale exigée.
$vh_verif = (string)($_GET['vh'] ?? '');
$acces_public = false;
if ($vh_verif !== '' && (int)($_GET['eleve'] ?? 0) > 0) {
    $eleve_verif = db_one("SELECT niu, matricule FROM eleve WHERE id=?", [(int)$_GET['eleve']]);
    if ($eleve_verif) {
        $vue_verif     = ((int)($_GET['seq'] ?? 0) > 0) ? 'seq' : 'trim';
        $periode_verif = $vue_verif === 'seq' ? (int)$_GET['seq'] : (int)($_GET['trim'] ?? 0);
        $acces_public  = hash_equals(bulletin_verif_hash((int)$_GET['eleve'], $vue_verif, $periode_verif, id_affichage_eleve($eleve_verif)), $vh_verif);
    }
}
$id_classe_bulletins = (int) ($_GET['classe'] ?? 0);
$mode_classe = $id_classe_bulletins > 0 && (int) ($_GET['eleve'] ?? 0) === 0;
if (!$acces_public) {
    exiger_connexion();
    // Hors QR code : administration ou professeur principal de la classe
    // (un identifiant d'élève modifié à la main dans l'adresse est refusé).
    if ($mode_classe) {
        $role_b = role_connecte();
        $admin_b = in_array($role_b, ['ADMIN', 'PROVISEUR', 'FONDATEUR', 'CENSEUR', 'MEMBRE_ASSOCIATION'], true)
            || (function_exists('est_visite_association') && est_visite_association())
            || (function_exists('est_proprietaire_ecole') && est_proprietaire_ecole());
        if (!$admin_b && !in_array($id_classe_bulletins, sec_classes_ids_visibles(true) ?? [], true)) {
            sec_refuser_acces('Accès refusé : seuls l\'administration et le professeur principal de la classe peuvent imprimer ces bulletins.');
        }
    } else {
        exiger_acces_bulletin_secondaire((int) ($_GET['eleve'] ?? 0));
    }
}
require_once __DIR__ . '/../../../commun/pdf/fpdf.php';
require_once __DIR__ . '/../../../commun/pdf/header_pdf.php';

//function u(string $s): string { return utf8_decode($s); }
function u(string $s): string {
	//return utf8_decode($s);
	return mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
}

// Incruste la photo de l'élève au centre de l'image QR déjà générée (demande
// explicite), sur un fond blanc pour ne pas casser la lecture des modules
// environnants. Le niveau de correction d'erreur du QR ('qr-h', ~30%) tolère
// une zone occultée d'environ 20% de la surface sans empêcher le scan. Ne
// fait rien si $photo_path est vide ou illisible (le QR reste utilisable seul).
function qr_incruster_photo($qr_img, string $photo_path): void {
    if ($photo_path === '' || !is_file($photo_path)) return;
    $photo = @imagecreatefromstring(file_get_contents($photo_path));
    if (!$photo) return;

    $w = imagesx($qr_img); $h = imagesy($qr_img);
    $logo_size = (int)round($w * 0.22);
    $pad = (int)round($w * 0.012);
    $box = $logo_size + $pad * 2;
    $bx = (int)(($w - $box) / 2);
    $by = (int)(($h - $box) / 2);

    $blanc = imagecolorallocate($qr_img, 255, 255, 255);
    imagefilledrectangle($qr_img, $bx, $by, $bx + $box - 1, $by + $box - 1, $blanc);

    $pw = imagesx($photo); $ph = imagesy($photo);
    $cote = min($pw, $ph);
    $sx = (int)(($pw - $cote) / 2); $sy = (int)(($ph - $cote) / 2);
    imagecopyresampled($qr_img, $photo, $bx + $pad, $by + $pad, $sx, $sy, $logo_size, $logo_size, $cote, $cote);
    imagedestroy($photo);
}

// Ajuste un texte à une largeur de cellule donnée : réduit d'abord la taille
// de police par petits pas (jamais en dessous de $taille_min), puis, si ça
// ne suffit toujours pas, tronque mot par mot depuis la fin (jamais au
// milieu d'un mot) en ajoutant une ellipse. Retourne [taille_a_utiliser,
// texte_final] — à l'appelant de faire SetFont() avec la taille retournée
// avant d'écrire la cellule, et de restaurer sa taille de police d'origine
// après. Évite tout débordement (ex. noms d'enseignants longs) quelle que
// soit la largeur de colonne réellement disponible.
function fpdf_texte_ajuste(FPDF $pdf, string $texte, float $largeur_max, float $taille_max, float $taille_min = 4.0): array {
    $marge = 1.2; // marge interne gauche+droite approximative d'une Cell()
    $tient = function (string $t) use ($pdf, $largeur_max, $marge): bool {
        return $pdf->GetStringWidth(u($t)) <= $largeur_max - $marge;
    };

    $taille = $taille_max;
    $pdf->SetFont('Arial', '', $taille);
    while ($taille > $taille_min && !$tient($texte)) {
        $taille -= 0.25;
        $pdf->SetFont('Arial', '', $taille);
    }
    if (!$tient($texte)) {
        $mots = explode(' ', $texte);
        while (count($mots) > 1 && !$tient(implode(' ', $mots) . '…')) {
            array_pop($mots);
        }
        $texte = implode(' ', $mots);
        while (mb_strlen($texte) > 1 && !$tient($texte . '…')) {
            $texte = mb_substr($texte, 0, -1);
        }
        $texte .= '…';
    }
    return [$taille, $texte];
}

// Sous-classe FPDF (fpdf.php n'est pas modifié) ajoutant les rectangles
// à coins arrondis utilisés pour le cadre de page et le bandeau du titre.
class PDF_LTM extends FPDF
{
    function RoundedRect($x, $y, $w, $h, $r, $style = '')
    {
        $k = $this->k;
        $hp = $this->h;
        if ($style === 'F') { $op = 'f'; }
        elseif ($style === 'FD' || $style === 'DF') { $op = 'B'; }
        else { $op = 'S'; }
        $myArc = 4 / 3 * (sqrt(2) - 1);
        $this->_out(sprintf('%.2F %.2F m', ($x + $r) * $k, ($hp - $y) * $k));
        $xc = $x + $w - $r; $yc = $y + $r;
        $this->_out(sprintf('%.2F %.2F l', $xc * $k, ($hp - $y) * $k));
        $this->_arcLTM($xc + $r * $myArc, $yc - $r, $xc + $r, $yc - $r * $myArc, $xc + $r, $yc);
        $xc = $x + $w - $r; $yc = $y + $h - $r;
        $this->_out(sprintf('%.2F %.2F l', ($x + $w) * $k, ($hp - $yc) * $k));
        $this->_arcLTM($xc + $r, $yc + $r * $myArc, $xc + $r * $myArc, $yc + $r, $xc, $yc + $r);
        $xc = $x + $r; $yc = $y + $h - $r;
        $this->_out(sprintf('%.2F %.2F l', $xc * $k, ($hp - ($y + $h)) * $k));
        $this->_arcLTM($xc - $r * $myArc, $yc + $r, $xc - $r, $yc + $r * $myArc, $xc - $r, $yc);
        $xc = $x + $r; $yc = $y + $r;
        $this->_out(sprintf('%.2F %.2F l', $x * $k, ($hp - $yc) * $k));
        $this->_arcLTM($xc - $r, $yc - $r * $myArc, $xc - $r * $myArc, $yc - $r, $xc, $yc - $r);
        $this->_out($op);
    }

    private function _arcLTM($x1, $y1, $x2, $y2, $x3, $y3)
    {
        $h = $this->h;
        $this->_out(sprintf(
            '%.2F %.2F %.2F %.2F %.2F %.2F c ',
            $x1 * $this->k, ($h - $y1) * $this->k,
            $x2 * $this->k, ($h - $y2) * $this->k,
            $x3 * $this->k, ($h - $y3) * $this->k
        ));
    }
}


require_once __DIR__ . '/_bulletin_fonctions.php';

// Élèves à imprimer : un seul (?eleve=), ou toute la classe (?classe=) dans
// l'ordre alphabétique ou de mérite, éventuellement une seule série (LV2).
if ($mode_classe) {
    $id_annee_b = (int) ($_GET['annee'] ?? 0);
    $id_serie_b = (int) ($_GET['serie'] ?? 0);
    $ids_bulletins = array_map('intval', array_column(db_all(
        "SELECT e.id FROM eleve e
         JOIN inscription i ON i.id_eleve=e.id AND i.id_classe=? AND i.id_annee=?
         WHERE e.statut='actif'" . ($id_serie_b ? ' AND i.id_serie=?' : '') . "
         ORDER BY e.nom, e.prenom",
        $id_serie_b ? [$id_classe_bulletins, $id_annee_b, $id_serie_b] : [$id_classe_bulletins, $id_annee_b]), 'id'));
    if (!$ids_bulletins) die('Aucun eleve dans cette classe (pour cette série).');
    if (($_GET['ordre'] ?? '') === 'merite') {
        // Même moyenne que le bulletin (rang de classe) ; non classés à la fin.
        $id_trim_b = (int) ($_GET['trim'] ?? 0) ?: (int) db_val("SELECT id_trim FROM sequence WHERE id=?", [(int) ($_GET['seq'] ?? 0)]);
        $dc = pv_charger_donnees_comp($id_classe_bulletins, $id_trim_b, $id_annee_b);
        $moys_b = [];
        foreach ($ids_bulletins as $eid) {
            [$m, $ok] = pv_moy_generale_comp($eid, $dc['disciplines'], $dc['competences_par_mat'], $dc['notes_idx'], $dc['notes_count'],
                                              $dc['nb_inscrits'], $dc['mats_avec_notes'], $dc['nb_mats_avec_notes'], $dc['annules']);
            $moys_b[$eid] = ($ok && $m !== null) ? $m : -1;
        }
        usort($ids_bulletins, fn($a, $b) => $moys_b[$b] <=> $moys_b[$a]);
    }
} else {
    $ids_bulletins = [(int) ($_GET['eleve'] ?? 0)];
}

foreach ($ids_bulletins as $id_eleve_bulletin) {
    require __DIR__ . '/_bulletin_corps.php';
}
// ── Sortie ────────────────────────────────────────────────────────
$mode     = ($_GET['dl'] ?? '') === '1' ? 'D' : 'I';
$filename = $mode_classe
    ? 'bulletins_' . preg_replace('/\W+/', '_', ($insc['classe'] ?? 'classe') . '_' . $titre_bull) . '.pdf'
    : 'bulletin_' . ($eleve['matricule'] ?? $id_eleve) . '_' . preg_replace('/\W+/', '_', $titre_bull) . '.pdf';
$pdf->Output($mode, $filename);
