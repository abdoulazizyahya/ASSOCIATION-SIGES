<?php
/**
 * PDF de tous les bulletins ANNUELS d'une classe (un élève par page) — jumeau
 * de pdf_classe.php, voir ce fichier pour les sections communes. Couvre les
 * 3 trimestres de l'année au lieu d'une séquence/un trimestre.
 * GET : classe, annee, ordre (alpha|merite), dl (0|1)
 */
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_connexion();

function uc(string $s): string {
	//return utf8_decode($s);
	return mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
}

// Incruste la photo de l'élève au centre de l'image QR déjà générée (demande
// explicite) — voir secondaire/pages/bulletins/pdf.php pour le jumeau
// (qr_incruster_photo(), même logique).
function qr_incruster_photo_c($qr_img, string $photo_path): void {
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
// avant d'écrire la cellule. Voir secondaire/pages/bulletins/pdf.php pour le jumeau de
// cette fonction (même logique, gardées séparées car chaque fichier a sa
// propre fonction d'encodage : u() ici s'appelle uc()).
function fpdf_texte_ajuste_c(FPDF $pdf, string $texte, float $largeur_max, float $taille_max, float $taille_min = 4.0): array {
    $marge = 1.2;
    $tient = function (string $t) use ($pdf, $largeur_max, $marge): bool {
        return $pdf->GetStringWidth(uc($t)) <= $largeur_max - $marge;
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

// Case à cocher façon PV (voir secondaire/pages/bulletins/pdf.php pour le jumeau).
function checkbox_ltm_c(FPDF $pdf, float $x, float $y, bool $checked, float $s = 3): void {
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->Rect($x, $y, $s, $s);
    if ($checked) {
        $pdf->SetLineWidth(0.35);
        $pdf->Line($x + 0.4, $y + 0.4, $x + $s - 0.4, $y + $s - 0.4);
        $pdf->Line($x + $s - 0.4, $y + 0.4, $x + 0.4, $y + $s - 0.4);
        $pdf->SetLineWidth(0.2);
    }
}

// Compte le nombre de lignes PHYSIQUES qu'occupera un texte dans un
// MultiCell() de largeur $w (reproduit l'algorithme de retour à la ligne de
// FPDF — mot par mot — pour prévoir la hauteur réelle de l'appréciation
// AVANT de dessiner) — voir secondaire/pages/bulletins/pdf_annuel.php pour le jumeau
// (texte_nb_lignes(), même logique).
function texte_nb_lignes_c(FPDF $pdf, string $texte, float $w, string $style, float $taille): int {
    $pdf->SetFont('Arial', $style, $taille);
    $dispo = $w - 2;
    $nb = 0;
    foreach (explode("\n", $texte) as $para) {
        if ($para === '') { $nb++; continue; }
        $ligne = '';
        foreach (explode(' ', $para) as $mot) {
            $essai = $ligne === '' ? $mot : $ligne . ' ' . $mot;
            if ($ligne !== '' && $pdf->GetStringWidth(uc($essai)) > $dispo) {
                $nb++;
                $ligne = $mot;
            } else {
                $ligne = $essai;
            }
        }
        $nb++;
    }
    return max(1, $nb);
}

// Dessine un rang avec le "e" ordinal en exposant (demande explicite) —
// voir secondaire/pages/bulletins/pdf.php pour le jumeau (cell_rang(), même logique).
function cell_rang_c(FPDF $pdf, float $w, float $h, ?int $rang, string $suffixe, int $border, string $align, float $taille, bool $bold = true): void {
    $x = $pdf->GetX(); $y = $pdf->GetY();
    $style = $bold ? 'B' : '';
    if ($border) { $pdf->Rect($x, $y, $w, $h); }
    if ($rang === null) {
        $pdf->SetFont('Arial', $style, $taille);
        $pdf->SetXY($x, $y);
        $pdf->Cell($w, $h, '-', 0, 0, $align);
        $pdf->SetXY($x + $w, $y);
        return;
    }

    $part1 = (string)$rang;
    $taille_e = round($taille * 0.62, 2);
    $pdf->SetFont('Arial', $style, $taille);
    $w1 = $pdf->GetStringWidth($part1);
    $w2 = $pdf->GetStringWidth(uc($suffixe));
    $pdf->SetFont('Arial', $style, $taille_e);
    $we = $pdf->GetStringWidth('e');
    $total = $w1 + $we + $w2;
    if ($align === 'C')     { $x0 = $x + ($w - $total) / 2; }
    elseif ($align === 'R') { $x0 = $x + $w - $total - 1.2; }
    else                    { $x0 = $x + 1.2; }

    $pdf->SetFont('Arial', $style, $taille);
    $pdf->SetXY($x0, $y);
    $pdf->Cell($w1, $h, $part1, 0, 0, 'L');

    $raise = $taille * 0.12;
    $pdf->SetXY($x0 + $w1, $y - $raise);
    $pdf->SetFont('Arial', $style, $taille_e);
    $pdf->Cell($we, $h, 'e', 0, 0, 'L');

    $pdf->SetXY($x0 + $w1 + $we, $y);
    $pdf->SetFont('Arial', $style, $taille);
    $pdf->Cell($w2, $h, uc($suffixe), 0, 0, 'L');

    $pdf->SetXY($x + $w, $y);
}

// Réduit par petits pas la taille de police d'un texte (dans le style donné)
// jusqu'à ce qu'il tienne dans $largeur_max — voir secondaire/pages/bulletins/pdf.php
// pour le jumeau (cell2l_taille_ajustee(), même logique).
function cell2l_taille_ajustee_c(FPDF $pdf, string $texte, string $style, float $largeur_max, float $taille_max, float $taille_min): float {
    $taille = $taille_max;
    $pdf->SetFont('Arial', $style, $taille);
    while ($taille > $taille_min && $pdf->GetStringWidth(uc($texte)) > $largeur_max) {
        $taille -= 0.25;
        $pdf->SetFont('Arial', $style, $taille);
    }
    return $taille;
}

// Cellule à 2 lignes bilingue FR (gras) / EN (italique) — voir
// secondaire/pages/bulletins/pdf.php pour le jumeau (cell2l(), même logique).
// $taille_fr/$taille_en sont des MAXIMUMS (demande explicite) — réduits
// automatiquement si besoin par cell2l_taille_ajustee_c().
function cell2l_c(FPDF $pdf, float $w, float $h, string $fr, string $en, string $align = 'L', bool $fill = false, float $taille_fr = 6, float $taille_en = 4.3, array $fill_color = [216, 210, 248], bool $tight = false): void {
    $x = $pdf->GetX(); $y = $pdf->GetY();
    if ($fill) { $pdf->SetFillColor($fill_color[0], $fill_color[1], $fill_color[2]); $pdf->Rect($x, $y, $w, $h, 'F'); }
    $pdf->Rect($x, $y, $w, $h);
    $dispo = $w - 2.4;
    if ($en === '') {
        $taille_fr = cell2l_taille_ajustee_c($pdf, $fr, 'B', $dispo, $taille_fr, 5.5);
        $pdf->SetFont('Arial', 'B', $taille_fr);
        $pdf->SetXY($x + 0.8, $y);
        $pdf->Cell($w - 1.2, $h, uc($fr), 0, 0, $align);
    } else {
        $taille_fr = cell2l_taille_ajustee_c($pdf, $fr, 'B', $dispo, $taille_fr, 5.5);
        $taille_en = cell2l_taille_ajustee_c($pdf, $en, 'I', $dispo, $taille_en, 4.5);
        // Interligne FR/EN resserré (demande explicite) — voir
        // secondaire/pages/bulletins/pdf.php pour le jumeau (même logique). $tight :
        // répartition stricte 50/50 sans décalage haut (case identification
        // élève, FR/EN de même taille, demande explicite).
        if ($tight) {
            [$y_fr, $h_fr, $y_en, $h_en] = [$y, $h * 0.5, $y + $h * 0.5, $h * 0.5];
        } else {
            [$y_fr, $h_fr, $y_en, $h_en] = [$y + $h * 0.02, $h * 0.52, $y + $h * 0.54, $h * 0.44];
        }
        $pdf->SetFont('Arial', 'B', $taille_fr);
        $pdf->SetXY($x + 0.8, $y_fr);
        $pdf->Cell($w - 1.2, $h_fr, uc($fr), 0, 0, $align);
        $pdf->SetFont('Arial', 'I', $taille_en);
        $pdf->SetXY($x + 0.8, $y_en);
        $pdf->Cell($w - 1.2, $h_en, uc($en), 0, 0, $align);
    }
    $pdf->SetXY($x + $w, $y);
}

// Libellé court d'un trimestre par sa position (1er/2e/3e) — voir
// secondaire/pages/bulletins/pdf_annuel.php pour le jumeau (trimestre_court()).
function trimestre_court_c(int $position): string {
    return $position . ($position === 1 ? 'er' : 'e') . ' Trim';
}

$role     = role_connecte();
$is_admin = in_array($role, ['ADMIN', 'PROVISEUR', 'FONDATEUR', 'CENSEUR']) || $role === 'MEMBRE_ASSOCIATION';
$is_ens   = ($role === 'ENSEIGNANT');
$mat_ens  = $is_ens ? get_matricule_ens_connecte() : null;

if (!$is_admin && !$is_ens) die('Acces non autorise.');

$id_classe = (int)($_GET['classe'] ?? 0);
$id_annee  = (int)($_GET['annee']  ?? 0);
$id_serie  = (int)($_GET['serie']  ?? 0);
$ordre     = in_array($_GET['ordre'] ?? '', ['alpha', 'merite']) ? $_GET['ordre'] : 'alpha';
$dl        = ($_GET['dl'] ?? '0') === '1';

if (!$id_classe) die('Classe manquante.');

$annee_act = get_annee_active();
$val_annee = $annee_act['libelle'] ?? '';

// Sécurité PP
if ($is_ens && $mat_ens) {
    $ok = db_val(
        "SELECT COUNT(*) FROM enseignat_principal WHERE matricule_ens=? AND IDClasses=? AND val_annee=?",
        [$mat_ens, $id_classe, $val_annee]
    );
    if (!$ok) die('Acces refuse.');
}

// Élèves de la classe — ?serie= restreint l'IMPRESSION à une série/LV2 (classe
// mixte) sans toucher au calcul des rangs/moyennes ci-dessous (`$all_moys`),
// basé sur $enrolled_ids (TOUTE la classe) : un bulletin filtré affiche donc
// le vrai rang de classe. Même principe que pdf_classe.php (bulletin trimestriel).
$eleves = db_all(
    "SELECT e.id, e.nom, e.prenom FROM eleve e
     JOIN inscription i ON i.id_eleve=e.id AND i.id_classe=? AND i.id_annee=?
     WHERE e.statut='actif'" . ($id_serie ? ' AND i.id_serie=?' : '') . "
     ORDER BY e.nom, e.prenom",
    $id_serie ? [$id_classe, $id_annee, $id_serie] : [$id_classe, $id_annee]
);
if (empty($eleves)) die('Aucun eleve dans cette classe (pour cette série).');

// ── Trimestres de l'année (toujours 3 colonnes) — chantier APC (voir
// secondaire/pages/bulletins/pdf_annuel.php pour l'explication, même logique) : complété
// à 3 emplacements même si un trimestre n'est pas encore créé, pour rester
// consultable sans note (demande explicite). Les compétences n'ont pas
// d'équivalent "séquence" : chaque trimestre est chargé en bloc plus bas via
// pv_charger_donnees_comp() (même pattern que
// secondaire/pages/conseil_classe/pdf_releve_annuel.php, Phase 6).
$trimestres = db_all("SELECT * FROM trimestre WHERE id_annee=? ORDER BY ordre", [$id_annee]);
$trimestres = array_pad(array_slice($trimestres, 0, 3), 3, null);

$titre_bull = 'BILAN ANNUEL';

require_once __DIR__ . '/../../pdf/fpdf.php';
require_once __DIR__ . '/../../pdf/header_pdf.php'; // pour pdf_filigrane()

// Sous-classe FPDF ajoutant les rectangles à coins arrondis (cadre de page,
// bandeau du titre) — voir secondaire/pages/bulletins/pdf.php pour le jumeau (même
// classe, dupliquée ici car ce fichier n'inclut pas l'autre), nécessaire pour
// que les deux bulletins (individuel et par classe) aient exactement la même
// forme (demande explicite). Déclarée après le require de fpdf.php : une
// classe qui hérite d'une classe non encore chargée provoque une erreur fatale.
class PDF_LTM_C extends FPDF
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

$etab = get_etablissement();

// Données communes de la classe
$disciplines = db_all(
    "SELECT d.id_mat, d.coef, d.ordre, m.libelle AS matiere,
            g.libelle_groupe_comp AS groupe_lib, d.id_groupe,
            TRIM(CONCAT(e.nom_ens,' ',COALESCE(e.prenom_ens,''))) AS enseignant
     FROM discipline d JOIN matiere m ON m.id=d.id_mat AND m.actif=1
     LEFT JOIN groupe g ON g.id_groupe_comp=d.id_groupe
     LEFT JOIN dispenser disp ON disp.id_mat=d.id_mat AND disp.IDClasses=d.IDClasses AND disp.val_annee=?
     LEFT JOIN enseignant e ON e.matricule_ens=disp.matricule_ens
     WHERE d.IDClasses=? ORDER BY d.id_groupe, d.ordre, m.libelle",
    [$val_annee, $id_classe]
);

$pp = db_one(
    "SELECT e.nom_ens, e.prenom_ens FROM enseignat_principal ep JOIN enseignant e ON e.matricule_ens=ep.matricule_ens WHERE ep.IDClasses=? AND ep.val_annee=? LIMIT 1",
    [$id_classe, $val_annee]
);
$nom_pp = $pp ? trim($pp['nom_ens'] . ' ' . ($pp['prenom_ens'] ?? '')) : '-';

$all_enrolled = db_all(
    "SELECT el.id FROM eleve el JOIN inscription i ON i.id_eleve=el.id AND i.id_classe=? AND i.id_annee=? WHERE el.statut='actif'",
    [$id_classe, $id_annee]
);
$enrolled_ids = array_column($all_enrolled, 'id');
$nb_inscrits  = count($enrolled_ids);

// ── Chargement compétences par trimestre ────────────────────────────
// Chantier APC : chaque trimestre est chargé séparément via
// pv_charger_donnees_comp() (fonctions.php) — même pattern que
// secondaire/pages/bulletins/pdf_annuel.php / secondaire/pages/conseil_classe/pdf_releve_annuel.php
// (Phase 6). $dcomp_par_trim[$i] est null si ce trimestre n'existe pas encore.
$dcomp_par_trim = [];
foreach ([0, 1, 2] as $i) {
    $t = $trimestres[$i];
    $dcomp_par_trim[$i] = $t ? pv_charger_donnees_comp($id_classe, (int)$t['id'], $id_annee) : null;
}

// Moyenne annuelle d'une matière = moyenne des moyennes trimestrielles de
// cette matière (trimestres sans note ignorés) — voir
// secondaire/pages/bulletins/pdf_annuel.php pour le jumeau (mat_avg_trim_annuel_comp()).
// Retourne [avg_t1, avg_t2, avg_t3, avg_annuel].
function mat_avg_trim_annuel_c(int $eid, int $id_mat, array $dcomp_par_trim): array {
    $par_trim = [];
    foreach ($dcomp_par_trim as $dc) {
        $par_trim[] = $dc ? pv_moy_matiere_comp($eid, $dc['competences_par_mat'][$id_mat] ?? [], $dc['notes_idx'], $dc['notes_count'], $dc['nb_inscrits']) : null;
    }
    $valides = array_filter($par_trim, fn($v) => $v !== null);
    $annuel  = !empty($valides) ? array_sum($valides) / count($valides) : null;
    return array_merge($par_trim, [$annuel]);
}

function checkbox_ltm(FPDF $pdf, float $x, float $y, bool $checked, float $s = 3): void {
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->Rect($x, $y, $s, $s);
    if ($checked) {
        $pdf->SetLineWidth(0.35);
        $pdf->Line($x + 0.4, $y + 0.4, $x + $s - 0.4, $y + $s - 0.4);
        $pdf->Line($x + $s - 0.4, $y + 0.4, $x + 0.4, $y + $s - 0.4);
        $pdf->SetLineWidth(0.2);
    }
}
//function u(string $s): string { return utf8_decode($s); }
function u(string $s): string {
	//return utf8_decode($s);
	return mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
}
// Mentions automatiques par période — voir secondaire/pages/bulletins/pdf_annuel.php
// pour le détail (même logique, seuils fournis par l'utilisateur).
function bulletin_mentions_periode_c(?float $moy, int $heur_nj): array {
    if ($moy === null) {
        return ['tab' => '', 'encourag' => false, 'felicit' => false, 'avert_trav' => false, 'blame_trav' => false, 'avert_cond' => false, 'blame_cond' => false];
    }
    $tab = $moy >= 12 ? ($heur_nj <= 10 ? 'oui' : 'refuse') : '';
    return [
        'tab'        => $tab,
        'encourag'   => $tab === 'oui' && $moy >= 14,
        'felicit'    => $tab === 'oui' && $moy >= 15,
        'avert_trav' => $moy >= 5 && $moy <= 7.30,
        'blame_trav' => $moy < 5,
        'avert_cond' => $heur_nj >= 8 && $heur_nj < 15,
        'blame_cond' => $heur_nj >= 15,
    ];
}

// Appréciation du conseil de fin d'année, générée automatiquement — voir
// secondaire/pages/bulletins/pdf_annuel.php pour le détail (même logique).
function bulletin_appreciation_annuelle_c(array $m, array $matieres_faibles): string {
    $lignes = [];
    if ($m['blame_trav'])           $lignes[] = 'Blâme pour mauvais travail';
    elseif ($m['avert_trav'])       $lignes[] = 'Avertissement pour mauvais travail';
    elseif ($m['tab'] === 'oui')    $lignes[] = "Tableau d'honneur accordé pour bon travail";
    elseif ($m['tab'] === 'refuse') $lignes[] = "Tableau d'honneur refusé pour trop d'absences";

    if ($m['encourag']) $lignes[] = 'Encouragements pour résultats satisfaisants';
    if ($m['felicit'])  $lignes[] = 'Félicitations pour excellent travail';

    if ($m['blame_cond'])     $lignes[] = 'Blâme pour mauvaise conduite';
    elseif ($m['avert_cond']) $lignes[] = 'Avertissement pour mauvaise conduite';
    else                      $lignes[] = 'Bonne conduite dans l\'ensemble';

    if (!empty($matieres_faibles)) {
        $lignes[] = "Des efforts s'imposent en : " . implode(', ', $matieres_faibles);
    }
    return implode("\n", $lignes);
}

// Moyennes de classe PAR TRIMESTRE (calculées une seule fois, réutilisées à
// la fois pour la moyenne annuelle ci-dessous et pour le Rappel de chaque
// élève dans la boucle plus bas) — voir secondaire/pages/bulletins/pdf_annuel.php pour
// le détail (même logique). Trimestre sans compétence : classement vide,
// affiché en tiret (demande explicite : consultable même sans note).
$class_moys_par_trim = [[], [], []];
// [i][eid] = statut complet (Règle 3 a besoin de CHAQUE élève à CHAQUE
// trimestre, pas seulement des moyennes des élèves classés) — voir
// secondaire/pages/bulletins/pdf_annuel.php pour le détail (même logique).
$statut_par_trim = [[], [], []];
$moy_prem_t = [null, null, null];
$moy_dern_t = [null, null, null];
foreach ([0, 1, 2] as $i) {
    $dc = $dcomp_par_trim[$i];
    $evalue = $dc && ($dc['nb_mats_avec_notes'] ?? 0) > 0;
    if (!$dc || empty($dc['competences_par_mat'])) continue;
    foreach ($enrolled_ids as $eid) {
        [$m, $ok, , $annule] = pv_moy_generale_comp($eid, $dc['disciplines'], $dc['competences_par_mat'], $dc['notes_idx'], $dc['notes_count'], $dc['nb_inscrits'], $dc['mats_avec_notes'], $dc['nb_mats_avec_notes'], $dc['annules']);
        if ($ok && $m !== null) $class_moys_par_trim[$i][$eid] = $m;
        $statut_par_trim[$i][$eid] = ['moy' => $m, 'classable' => $ok, 'annule' => $annule, 'evalue' => $evalue];
    }
    arsort($class_moys_par_trim[$i]);
    $moy_prem_t[$i] = !empty($class_moys_par_trim[$i]) ? max($class_moys_par_trim[$i]) : null;
    $moy_dern_t[$i] = !empty($class_moys_par_trim[$i]) ? min($class_moys_par_trim[$i]) : null;
}

// Classement par trimestre (nb admis, moyenne de classe, taux de réussite) —
// voir secondaire/pages/bulletins/pdf_annuel.php pour le détail (même logique).
$nb_classes_t = [0, 0, 0]; $nb_admis_t = [0, 0, 0]; $moy_classe_t = [null, null, null]; $taux_reussite_t = [0, 0, 0];
foreach ([0, 1, 2] as $i) {
    $moys_i = $class_moys_par_trim[$i];
    $nb_classes_t[$i] = count($moys_i);
    $moy_classe_t[$i] = $nb_classes_t[$i] > 0 ? array_sum($moys_i) / $nb_classes_t[$i] : null;
    $nb_admis_t[$i]   = count(array_filter($moys_i, fn($m) => $m >= 10));
    $taux_reussite_t[$i] = $nb_classes_t[$i] > 0 ? round($nb_admis_t[$i] / $nb_classes_t[$i] * 100, 2) : 0;
}

// Moyenne annuelle de chaque élève — Règle 3 (pv_moy_annuelle_comp(),
// fonctions.php) : voir secondaire/pages/bulletins/pdf_annuel.php pour le détail.
$defaut_statut = ['moy' => null, 'classable' => false, 'annule' => false, 'evalue' => false];
$all_moys = [];
foreach ($enrolled_ids as $eid) {
    $par_trim = [
        $statut_par_trim[0][$eid] ?? $defaut_statut,
        $statut_par_trim[1][$eid] ?? $defaut_statut,
        $statut_par_trim[2][$eid] ?? $defaut_statut,
    ];
    $r = pv_moy_annuelle_comp($par_trim);
    if ($r['moy'] !== null) $all_moys[$eid] = $r['moy'];
}
arsort($all_moys);
$moy_premier    = !empty($all_moys) ? max($all_moys) : null;
$moy_dernier    = !empty($all_moys) ? min($all_moys) : null;
$moy_classe_all = !empty($all_moys) ? array_sum($all_moys) / count($all_moys) : null;
$nb_classes_c   = count($all_moys);
$nb_admis       = count(array_filter($all_moys, fn($m) => $m >= 10));
$taux_reussite  = $nb_classes_c > 0 ? round($nb_admis / $nb_classes_c * 100, 2) : 0;

// Tri par mérite
if ($ordre === 'merite') {
    usort($eleves, function ($a, $b) use ($all_moys) {
        $ma = $all_moys[$a['id']] ?? 0;
        $mb = $all_moys[$b['id']] ?? 0;
        return $mb <=> $ma;
    });
}

// Stats par matière — sur la moyenne ANNUELLE de chaque matière (moyenne de
// ses 3 moyennes trimestrielles, voir mat_avg_trim_annuel_c()).
$mat_stats = [];
foreach ($disciplines as $d) {
    $avgs = [];
    foreach ($enrolled_ids as $eid) {
        [, , , $avg] = mat_avg_trim_annuel_c($eid, $d['id_mat'], $dcomp_par_trim);
        if ($avg !== null) $avgs[] = $avg;
    }
    rsort($avgs);
    $mat_stats[$d['id_mat']] = empty($avgs)
        ? ['min' => null, 'avg' => null, 'max' => null, 'avgs_sorted' => []]
        : ['min' => min($avgs), 'avg' => array_sum($avgs) / count($avgs), 'max' => max($avgs), 'avgs_sorted' => $avgs];
}

// Plus de regroupement par groupe_lib ni de ligne TOTAL par groupe (chantier
// APC — abandonnés en Phase 5 pour le bulletin trimestriel individuel, voir
// secondaire/pages/bulletins/pdf.php, harmonisé ici et dans pdf_classe.php/pdf_annuel.php) :
// une ligne par matière directement, dans l'ordre de $disciplines.

// Helpers
$fmt = function (?float $v): string {
    if ($v === null) return '';
    $s = rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    // Zéro de tête sur la partie entière (demande explicite) : "4" -> "04",
    // "8.75" -> "08.75" — appliqué à toutes les notes/NxC/moyennes du bulletin,
    // identique à secondaire/pages/bulletins/pdf.php (fmt_note_b()).
    [$int, $dec] = array_pad(explode('.', $s, 2), 2, null);
    $int = str_pad($int, 2, '0', STR_PAD_LEFT);
    return $dec !== null ? "$int.$dec" : $int;
};
// Texte complet, jamais abrégé/coupé (cohérent avec secondaire/pages/bulletins/pdf.php) :
// la largeur de cMEN a été vérifiée pour accueillir ces libellés sur 2 lignes.
$mention_mat = fn(?float $a): string => $a === null ? '' : ($a >= 14 ? "Compétences TB\nacquises" : ($a >= 12 ? "Compétences\nbien acquises" : ($a >= 10 ? "Compétences\nacquises" : "Compétences\nnon acquises")));
$lettre_grp  = fn(?float $a): string => $a === null ? '' : ($a >= 16 ? 'A' : ($a >= 14 ? 'B' : ($a >= 12 ? 'C+' : ($a >= 10 ? 'C' : ($a >= 8 ? 'D' : 'E')))));
$mention_gen = fn(?float $a): string => $a === null ? '' : ($a >= 16 ? 'Excellent' : ($a >= 14 ? 'Tres bien' : ($a >= 12 ? 'Bien' : ($a >= 10 ? 'Assez bien' : ($a >= 8 ? 'Passable' : 'Insuffisant')))));
$rang_eleve  = function (float $avg, array $sorted): int { $r = 1; foreach ($sorted as $a) { if ($a > $avg) $r++; else break; } return $r; };

// Constantes mise en page
$ml = 8; $mr = 8;
$h_bas = 7;
// cMEN réduite au profit de cENS (demande explicite, encore réduite lors
// d'un 2e passage) — vérifié par un test MultiCell réel : "Compétences TB"
// tient toujours sur exactement 2 lignes à 20mm (3 lignes dès 19mm, ce qui
// casserait l'alignement avec row_h/2) — voir secondaire/pages/bulletins/pdf_annuel.php.
$cD   = 52; $cCF = 9; $cNXC = 13; $cRG = 9; $cMEN = 20; $cMIN = 9; $cMOY = 9; $cMAX = 9;
// Toujours 3 colonnes trimestre (T1/T2/T3) + 1 colonne MOY ANNUELLE — voir
// secondaire/pages/bulletins/pdf_annuel.php pour le détail (même logique, jamais de
// mode séquence unique dans un bulletin annuel).
$cTR        = 11;
$cENS_min   = 12;
$reste_fixe = $cD + $cTR + $cCF + $cNXC + $cRG + $cMEN + $cMIN + $cMOY + $cMAX + $cENS_min;
$cEval      = min(10, max(0, floor((194 - $reste_fixe) / 3)));
$cENS       = 194 - $cD - $cEval * 3 - $cTR - $cCF - $cNXC - $cRG - $cMEN - $cMIN - $cMOY - $cMAX;

$pdf = new PDF_LTM_C('P', 'mm', 'A4');
$pdf->SetMargins($ml, $ml, $mr);
// Marge de saut de page alignée sur le cadre extérieur (4mm) — voir
// secondaire/pages/bulletins/pdf.php pour l'explication (le copyright, dessiné tout en
// bas juste au-dessus du cadre, déclenchait sinon un saut de page automatique).
$pdf->SetAutoPageBreak(true, 4);
$pw   = $pdf->GetPageWidth();
$uw   = $pw - $ml - $mr;
$col3 = $uw / 3;

foreach ($eleves as $el) {
    $id_eleve = (int)$el['id'];
    $eleve    = db_one("SELECT * FROM eleve WHERE id=?", [$id_eleve]);
    if (!$eleve) continue;
    $insc = db_one(
        "SELECT i.*, c.designation AS classe FROM inscription i JOIN classe c ON c.id=i.id_classe WHERE i.id_eleve=? AND i.id_annee=?",
        [$id_eleve, $id_annee]
    );
    if (!$insc) continue;
    $tuteur = db_one("SELECT * FROM tuteur WHERE id_eleve=? LIMIT 1", [$id_eleve]);
    $contacts_parents = $tuteur ? trim(($tuteur['nom'] ?? '') . ' ' . ($tuteur['prenom'] ?? '') . ' ' . ($tuteur['telephone'] ?? '')) : '';

    // Calculs élève — moyenne annuelle déjà calculée globalement (méthode
    // retenue : moyenne des 3 moyennes trimestrielles, voir plus haut).
    $moy_gen  = $all_moys[$id_eleve] ?? null;
    $rang_gen = null;
    if ($moy_gen !== null) {
        $rang_gen = 1;
        foreach ($all_moys as $m) { if ($m > $moy_gen) $rang_gen++; else break; }
    }

    // Rappel par trimestre (3 colonnes) — lu dans les classements par
    // trimestre déjà calculés globalement (voir plus haut), pas recalculé
    // élève par élève.
    $mr_t = [null, null, null]; $rr_t = [null, null, null];
    foreach ([0, 1, 2] as $i) {
        $mr_t[$i] = $class_moys_par_trim[$i][$id_eleve] ?? null;
        if ($mr_t[$i] !== null) {
            $rr = 1;
            foreach ($class_moys_par_trim[$i] as $m) { if ($m > $mr_t[$i]) $rr++; else break; }
            $rr_t[$i] = $rr;
        }
    }

    // Heures d'absence par trimestre ET cumulées sur l'année, pour CET
    // élève — calculées ici, AVANT la génération de sa page (déplacé depuis
    // après le tableau des disciplines), pour connaître le nombre réel de
    // lignes de son appréciation avant de dimensionner $row_h (demande
    // explicite : hauteur de ligne des matières calculée automatiquement
    // selon l'espace réellement disponible, sans sur-réserver une
    // estimation forfaitaire) — voir secondaire/pages/bulletins/pdf_annuel.php pour le
    // jumeau (même logique).
    $heur_jus_t_c = [0, 0, 0]; $heur_nj_t_c = [0, 0, 0];
    foreach ($trimestres as $i => $t) {
        if (!$t) continue;
        $abs_reelle_i = eleve_absence_trimestre($eleve['matricule'] ?? '', (int)$t['id'], $id_classe, $val_annee);
        $heur_jus_t_c[$i] = (int) $abs_reelle_i['jus'];
        $heur_nj_t_c[$i]  = (int) $abs_reelle_i['non_jus'];
    }
    $heur_jus_c = array_sum($heur_jus_t_c);
    $heur_nj_c  = array_sum($heur_nj_t_c);

    // Mentions par trimestre et pour l'année (voir bulletin_mentions_periode_c()).
    $mentions_t_c = [];
    foreach ([0, 1, 2] as $i) { $mentions_t_c[$i] = bulletin_mentions_periode_c($mr_t[$i], $heur_nj_t_c[$i]); }
    $mentions_an_c = bulletin_mentions_periode_c($moy_gen, $heur_nj_c);

    // Matières où la moyenne annuelle est insuffisante (<10).
    $matieres_faibles_c = [];
    foreach ($disciplines as $d) {
        $avg_an_d_c = mat_avg_trim_annuel_c($id_eleve, (int)$d['id_mat'], $dcomp_par_trim);
        $avg_an_d_c = end($avg_an_d_c);
        if ($avg_an_d_c !== null && $avg_an_d_c < 10) $matieres_faibles_c[] = $d['matiere'];
    }
    $appreciation_annuelle_c = $moy_gen !== null ? bulletin_appreciation_annuelle_c($mentions_an_c, $matieres_faibles_c) : '';

    // ── NOUVELLE PAGE ─────────────────────────────────────────────
    $pdf->AddPage();
    $y0 = $ml;

    pdf_filigrane($pdf, $etab, $pw, $pdf->GetPageHeight());

    // Cadre extérieur à coins arrondis — identique à secondaire/pages/bulletins/pdf.php
    // (demande explicite : les deux bulletins doivent avoir exactement la même forme).
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(0.4);
    $pdf->RoundedRect(4, 4, $pw - 8, $pdf->GetPageHeight() - 8, 4, 'D');
    $pdf->SetLineWidth(0.2);

    // En-tête bilingue
    $pdf->SetXY($ml, $y0);
    $pdf->SetFont('Arial', '', 6.5);
    $pdf->MultiCell($col3, 3.5, uc(
        "REPUBLIQUE DU CAMEROUN\nPaix - Travail - Patrie\n***************\n" .
        ($etab['region_fr'] ?? "REGION DE L'ADAMAOUA") . "\n" .
        ($etab['departement_fr'] ?? 'DEPARTEMENT DE LA VINA') . "\n" .
        ($etab['arrondissement_fr'] ?? 'ARRONDISSEMENT DE MBE') . "\n" .
        strtoupper($etab['nom_fr'] ?? 'LYCEE TECHNIQUE DE MBE') . "\n" .
        "B.P. " . ($etab['boite_postale'] ?? '32') . " Mbe  Tel.: " . ($etab['telephone'] ?? '') . "\n" .
        ($etab['email'] ?? '')
    ), 0, 'C');

    // Logo agrandi et centré horizontalement ET verticalement (demande
    // explicite, était calé en haut) — identique à secondaire/pages/bulletins/pdf.php.
    $logo_path = !empty($etab['logo']) ? __DIR__ . '/../../../assets/uploads/' . $etab['logo'] : '';
    $logo_bande_h = 25; // hauteur du bloc en-tête (y0=8 à 33, voir plus bas)
    $logo_w = 28;
    $logo_h = $logo_w;
    if ($logo_path && is_file($logo_path)) {
        $dim = @getimagesize($logo_path);
        if ($dim && $dim[0] > 0) $logo_h = $logo_w * $dim[1] / $dim[0];
    }
    $logo_x = $ml + $col3 + ($col3 - $logo_w) / 2;
    $logo_y = $y0 + ($logo_bande_h - $logo_h) / 2;
    if ($logo_path && is_file($logo_path)) {
        $pdf->Image($logo_path, $logo_x, $logo_y+3, $logo_w);
    } else {
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetXY($logo_x, $y0 + ($logo_bande_h - 16) / 2);
        $pdf->Cell($logo_w, 16, uc($etab['sigle'] ?? 'LTM'), 1, 0, 'C');
    }

    $xr = $ml + $col3 * 2;
    $pdf->SetXY($xr, $y0);
    $pdf->SetFont('Arial', '', 6.5);
    $pdf->MultiCell($col3, 3.5, uc(
        "REPUBLIC OF CAMEROON\nPeace - Work - Fatherland\n***************\n" .
        ($etab['region_en'] ?? 'ADAMAWA REGION') . "\n" .
        ($etab['division_en'] ?? 'VINA DIVISION') . "\n" .
        ($etab['subdivision_en'] ?? 'MBE SUBDIVISION') . "\n" .
        strtoupper($etab['nom_en'] ?? 'GTHS OF MBE') . "\n" .
        "P.O. BOX. " . ($etab['boite_postale'] ?? '32') . " Mbe  Phone: " . ($etab['telephone'] ?? '') . "\n" .
        ($etab['email'] ?? '')
    ),
    0, 'C');

    $pdf->SetY(max($pdf->GetY(), 33)-2.5);
    $pdf->SetFont('Arial', 'I', 7);
    $pdf->SetX($ml);
    $pdf->Cell($uw, 4, uc('IMMATRICULATION : ' . ($etab['immatriculation'] ?? '')), 0, 1, 'C');

    // Titre FR + traduction EN en italique en dessous (demande explicite) —
    // voir secondaire/pages/bulletins/pdf_annuel.php pour le jumeau (même logique).
    $y_titre = $pdf->GetY();
    $h_titre = 9.5;
    $pdf->SetFillColor(219, 228, 245);
    $pdf->SetDrawColor(26, 60, 107);
    $pdf->SetLineWidth(0.3);
    $pdf->RoundedRect($ml, $y_titre, $uw, $h_titre, $h_titre / 2, 'FD');
    $pdf->SetXY($ml, $y_titre + 0.3);
    $pdf->SetFont('Arial', 'BI', 17.5);
    $pdf->SetTextColor(26, 60, 107);
    $pdf->Cell($uw, $h_titre * 0.6, uc('BULLETIN DE NOTES ANNUEL'), 0, 2, 'C');
    $pdf->SetFont('Arial', 'I', 11);
    $pdf->Cell($uw, $h_titre * 0.38, uc('Annual Report Card'), 0, 1, 'C');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetLineWidth(0.2);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetX($ml);
    $pdf->Cell($uw, 5, uc('Année scolaire : ' . $val_annee), 0, 1, 'C');

    // Infos élève
    $pdf->Ln(0.5);

    $photo_w = 26; $photo_h = 29.5;
    $x_photo = $ml; $y_photo = $pdf->GetY();
    $x_info  = $ml + $photo_w; $w_info = $uw - $photo_w;

    // Photo de l'élève si disponible, sinon avatar par défaut selon le sexe —
    // identique à secondaire/pages/bulletins/pdf.php (demande explicite).
    $photo_path_c  = !empty($eleve['photo']) ? __DIR__ . '/../../../assets/uploads/eleves/' . $eleve['photo'] : '';
    $photo_reelle_c = $photo_path_c && is_file($photo_path_c);
    if (!$photo_reelle_c) {
        $avatar_fichier_c = (strtoupper($eleve['sexe'] ?? '') === 'F') ? 'fille.png' : 'garcon.png';
        $photo_path_c = __DIR__ . '/../../../assets/img/avatars/' . $avatar_fichier_c;
    }
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->Rect($x_photo, $y_photo, $photo_w, $photo_h);
    if (is_file($photo_path_c)) {
        $pdf->Image($photo_path_c, $x_photo + 0.5, $y_photo + 0.5, $photo_w - 1, $photo_h - 1);
    }

    $gris = [230, 230, 230];
    $pdf->SetFillColor(230, 230, 230);
    $pdf->SetFont('Arial', 'BI', 18.2);

    $wL1 = [24, 34, 18, 14, 18, 60]; // somme = 168 = $w_info
	$pdf->SetXY($x_info, $y_photo);
	cell2l_c($pdf, 30, 5.5, 'CLASSE :', 'Class', 'L', true, 8, 7.5, $gris, true);
	$pdf->SetFont('Arial', 'I', 8);
	$pdf->Cell(45, 5.5, u($insc['classe'] ?? ''), 1, 0, 'L');
	cell2l_c($pdf, $wL1[2], 5.5, 'EFFECTIF :', 'Size', 'L', true, 8, 7.5, $gris, true);
	$pdf->SetFont('Arial', 'I', 8);
	$pdf->Cell($wL1[3], 5.5, (string)$nb_inscrits, 1, 0, 'C');
	cell2l_c($pdf, 41, 5.5, 'IDENTIFIANT UNIQUE (NIU) :', 'ID No.', 'L', true, 8, 7.5, $gris, true);
	$pdf->SetFont('Arial', 'I', 8);

	$pdf->Cell(20, 5.5, id_affichage_eleve($eleve), 1, 1, 'C');
	$pdf->ln(0.5);
	$pdf->SetX($x_info);
	//$pdf->SetFont('Arial', 'B', 17);
	cell2l_c($pdf, 30, 5.5, 'NOM ET PRENOMS :', 'ID No.', 'L', true, 8, 7.5, $gris, true);
	//	cell2l($pdf, 26, 5.5, 'NOM ET PRENOMS :', 'Name', 'L', true, 28, 7.5, $gris, true);
	$pdf->SetFont('Arial', 'BI', 9);
	$pdf->Cell(110, 5.5, u(strtoupper($eleve['nom']) . ' ' . ($eleve['prenom'] ?? '')), 1, 0, 'L');
	cell2l_c($pdf, 16, 5.5, 'GENRE :', 'Gender', 'L', true, 8, 7.5, $gris, true);
	$pdf->SetFont('Arial', 'I', 8);
	$pdf->Cell(12, 5.5, $eleve['sexe'] ?? '', 1, 1, 'C');

	$pdf->ln(0.5);
	$pdf->SetX($x_info);
	cell2l_c($pdf, 18, 5.5, 'NE(E) LE :', 'Born on', 'L', true, 8, 7.5, $gris, true);
	$pdf->SetFont('Arial', 'I', 8);
	$dnaiss = $eleve['date_naiss'] ? date('d/m/Y', strtotime($eleve['date_naiss'])) : '';
	$pdf->Cell(22, 5.5, $dnaiss, 1, 0, 'C');
	$pdf->SetFont('Arial', 'B', 8);
	$pdf->Cell(10, 5.5, 'A/at', 1, 0, 'C', true);
	$pdf->SetFont('Arial', 'I', 8);
	$pdf->Cell(64, 5.5, u($eleve['lieu_naiss'] ?? ''), 1, 0, 'L');
	cell2l_c($pdf, 26, 5.5, 'REDOUBLANT :', 'Repeater', 'L', true, 8, 7.5, $gris, true);
	$is_redoub = (strtolower($insc['statut'] ?? '') === 'redoublant');
	$y_row3 = $pdf->GetY();
	$x_row3 = $pdf->GetX();
	$pdf->Cell(28, 5.5, '', 1, 1, 'L');
	$pdf->SetFont('Arial', 'I', 8);
	$pdf->SetXY($x_row3 + 1, $y_row3 + 1);
	$pdf->Cell(7, 3, 'Oui', 0, 0, 'L');
	checkbox_ltm($pdf, $x_row3 + 8, $y_row3 + 1, $is_redoub);
	$pdf->SetXY($x_row3 + 15, $y_row3 + 1);
	$pdf->Cell(7, 3, 'Non', 0, 0, 'L');
	checkbox_ltm($pdf, $x_row3 + 23, $y_row3 + 1, !$is_redoub);

	//$pdf->ln(20);
	$pdf->SetXY($x_info, $y_row3 + 6);
	//$pdf->ln();
	cell2l_c($pdf, 30, 5.5, 'PROF PRINCIPAL :', 'Class Teacher', 'L', true, 8, 7.5, $gris, true);
	$pdf->SetFont('Arial', 'I', 8);
	$pdf->Cell(138, 5.5, u($nom_pp), 1, 1, 'L');

	$pdf->ln(0.5);
	$pdf->SetX($x_info);
	cell2l_c($pdf, 42, 5.5, 'NOM ET CONTACTS DES PARENTS :', "Parent's Name & Contact", 'L', true, 8, 7.5, $gris, true);
	$pdf->SetFont('Arial', '', 7);
	$pdf->Cell(126, 5.5, u($contacts_parents), 1, 1, 'L');

	$pdf->SetY(max($pdf->GetY(), $y_photo + $photo_h));

    // En-tête tableau disciplines
    $pdf->Ln(1);
    $y_table0 = $pdf->GetY();

    // Hauteur harmonisée RECAPITULATIF DISCIPLINES/RESULTATS DE L'ELEVE
    // (bandeau inclus) — voir secondaire/pages/bulletins/pdf_annuel.php pour le jumeau
    // (même logique). $h_dec = hauteur des lignes DECISION DU CONSEIL DE
    // CLASSE (PROMU/REDOUBLE/EXCLU + 6 motifs). $h_bas ne sert plus que pour
    // l'en-tête DECISION/OBSERVATIONS.
    $h_ligne = 5;
    $h_dec = 3.2;
    // 13 = marge bas + place réservée à la mention copyright.
    // 13 = marge bas + copyright ; +22 = QR code désormais centré en bas de
    // page (demande explicite), réservés hors du cadre OBSERVATIONS.
    $bas_dispo_c = $pdf->GetPageHeight() - 13 - 22;

    // Hauteur de ligne du tableau ajustée dynamiquement (demande explicite :
    // le bulletin d'un élève ne doit jamais dépasser une seule page) — voir
    // secondaire/pages/bulletins/pdf_annuel.php pour le détail du calcul (même logique) :
    // 9 lignes ($h_ligne) pour RECAPITULATIF/RESULTATS (bandeau + en-tête
    // colonnes + 7 lignes), puis hauteur RÉELLE de l'appréciation générée
    // pour CET élève (nombre de lignes effectif, calculé plus haut avant
    // "NOUVELLE PAGE") + décision du conseil de classe (9 lignes) pour ne
    // jamais déborder sur une 2e page. Le QR code et le copyright ne sont
    // PAS recomptés ici : $bas_dispo_c réserve déjà 22+13mm pour eux (bug
    // corrigé — un ancien +25mm faisait doublon avec cette réservation et
    // écrasait inutilement la hauteur de ligne disponible pour le tableau
    // des disciplines).
    $n_lignes_appr_c = texte_nb_lignes_c($pdf, $appreciation_annuelle_c ?: '-', $uw * 0.55, '', 7.5);
    $h_appreciation_c = 2 + $n_lignes_appr_c * 4;
    $footer_fixe_c  = 1 + 9 * $h_ligne + 1 + $h_bas + $h_appreciation_c + 9 * $h_dec;
    $n_lignes_tab_c = count($disciplines);
    $row_h = $n_lignes_tab_c > 0
        ? max(3.2, min(4.5, ($bas_dispo_c - $y_table0 - 5.5 - $footer_fixe_c) / $n_lignes_tab_c))
        : 4.5;

    $pdf->SetFont('Arial', 'B', 6.5);
    $pdf->SetFillColor(0, 0, 0);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetX($ml);
    $pdf->Cell($cD, 5.5, 'DISCIPLINES', 1, 0, 'C', true);
    foreach ([1, 2, 3] as $pos) {
        $pdf->Cell($cEval, 5.5, uc(trimestre_court_c($pos)), 1, 0, 'C', true);
    }
    $pdf->Cell($cTR, 5.5, uc('MOY AN.'), 1, 0, 'C', true);
    $pdf->Cell($cCF,  5.5, 'COEF',     1, 0, 'C', true);
    $pdf->Cell($cNXC, 5.5, '(NXC)',    1, 0, 'C', true);
    $pdf->Cell($cRG,  5.5, 'RANG',     1, 0, 'C', true);
    $pdf->Cell($cMEN, 5.5, 'MENTIONS', 1, 0, 'C', true);
    $pdf->Cell($cMIN, 5.5, 'MIN',      1, 0, 'C', true);
    $pdf->Cell($cMOY, 5.5, 'MOY',      1, 0, 'C', true);
    $pdf->Cell($cMAX, 5.5, 'MAX',      1, 0, 'C', true);
    $pdf->Cell($cENS, 5.5, uc('ENSEIGNANTS'), 1, 1, 'C', true);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFillColor(255, 255, 255);

    // Une ligne par matière — chantier APC : plus de regroupement par
    // groupe_lib ni de ligne TOTAL par groupe (harmonisé avec pdf.php/
    // pdf_classe.php/pdf_annuel.php).
    foreach ($disciplines as $d) {
        $id_mat = (int)$d['id_mat'];
        // $avg_t = [avg_t1, avg_t2, avg_t3] (moyennes trimestrielles de
        // cette matière), $avg = moyenne annuelle (moyenne des 3).
        $avg_t3v = mat_avg_trim_annuel_c($id_eleve, $id_mat, $dcomp_par_trim);
        $avg     = array_pop($avg_t3v);
        $avg_t   = $avg_t3v;
        $stat   = $mat_stats[$id_mat] ?? ['min' => null, 'avg' => null, 'max' => null, 'avgs_sorted' => []];
        $rg     = ($avg !== null && !empty($stat['avgs_sorted'])) ? $rang_eleve($avg, $stat['avgs_sorted']) : null;
        $nxc    = $avg !== null ? $avg * $d['coef'] : null;
        $men    = $mention_mat($avg);

        $bg = ($avg !== null && $avg < 10) ? [255, 235, 235] : [255, 255, 255];
        $pdf->SetFillColor(...$bg);
        // Nom de la matière/compétence : taille agrandie par défaut (8pt)
        // pour les noms courts, réduite automatiquement (jusqu'à 5.5pt)
        // pour les noms longs afin de ne jamais déborder (demande
        // explicite) — identique à secondaire/pages/bulletins/pdf.php.
        [, $mat_lib_c] = fpdf_texte_ajuste_c($pdf, $d['matiere'], $cD, 8.0, 5.5);
        $pdf->SetX($ml);
        $pdf->Cell($cD, $row_h, uc($mat_lib_c), 1, 0, 'L', true);
        $pdf->SetFont('Arial', '', 6.5);

        // Valeurs de notes agrandies pour la lisibilité (demande
        // explicite), sauf MENTIONS et ENSEIGNANTS qui restent inchangées.
        // 3 colonnes trimestre (moyenne de la matière pour ce trimestre)
        // + MOY ANNUELLE.
        $pdf->SetFont('Arial', 'B', 8);
        foreach ($avg_t as $at) {
            $pdf->Cell($cEval, $row_h, $at !== null ? $fmt($at) : '', 1, 0, 'C', true);
        }
        if ($avg !== null) {
            $avg >= 10 ? $pdf->SetTextColor(0, 100, 0) : $pdf->SetTextColor(180, 0, 0);
        }
        $pdf->Cell($cTR, $row_h, $avg !== null ? $fmt($avg) : '', 1, 0, 'C', true);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Arial', '', 6.5);

        // Pas de note = matière non comptée dans les totaux : coefficient
        // laissé vide (cohérent avec secondaire/pages/bulletins/pdf.php).
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell($cCF,  $row_h, $avg !== null ? (string)$d['coef'] : '', 1, 0, 'C', true);
        $pdf->Cell($cNXC, $row_h, $nxc !== null ? $fmt($nxc) : '', 1, 0, 'C', true);
        $pdf->Cell($cRG,  $row_h, $rg !== null ? (string)$rg : '', 1, 0, 'C', true);
        $pdf->SetFont('Arial', '', 6.5);

        if ($men === '') {
            // Pas de mention : Cell() normale à la hauteur exacte de la
            // ligne (une MultiCell vide dessinerait un cadre plus court
            // que le reste de la ligne, aspect "fragmenté").
            $pdf->Cell($cMEN, $row_h, '', 1, 0, 'C', true);
        } else {
            $xm = $pdf->GetX(); $ym = $pdf->GetY();
            // $men contient toujours exactement 2 lignes (\n) : hauteur de
            // ligne = row_h/2 pile pour que le total corresponde à la
            // hauteur des cellules voisines. Taille inchangée (demande
            // explicite : colonne MENTIONS exclue de l'agrandissement).
            $pdf->MultiCell($cMEN, $row_h / 2, uc($men), 1, 'C', true);
            $pdf->SetXY($xm + $cMEN, $ym);
        }

        $pdf->SetFont('Arial', 'B', 7.5);
        $pdf->Cell($cMIN, $row_h, $stat['min'] !== null ? $fmt($stat['min']) : '', 1, 0, 'C', true);
        $pdf->Cell($cMOY, $row_h, $stat['avg'] !== null ? $fmt($stat['avg']) : '', 1, 0, 'C', true);
        $pdf->Cell($cMAX, $row_h, $stat['max'] !== null ? $fmt($stat['max']) : '', 1, 0, 'C', true);
        $pdf->SetFont('Arial', '', 6.5);
        // Nom de l'enseignant : réduit la taille de police si besoin
        // (jusqu'à 4pt) avant de tronquer mot par mot en dernier recours.
        // Taille inchangée (demande explicite : colonne ENSEIGNANTS
        // exclue de l'agrandissement).
        [$taille_ens, $ens_lib] = fpdf_texte_ajuste_c($pdf, $d['enseignant'] ?? '', $cENS, 5.5, 4.0);
        $pdf->Cell($cENS, $row_h, uc($ens_lib), 1, 1, 'L', true);
    }

    // ── Récapitulatif disciplines + Résultats de l'élève (bilan annuel) ──
    // Voir secondaire/pages/bulletins/pdf_annuel.php pour le jumeau (même logique et
    // même mise en page, modèle fourni par l'utilisateur — image).
    // $heur_jus_t_c/$heur_nj_t_c/$mentions_t_c/$mentions_an_c/
    // $matieres_faibles_c/$appreciation_annuelle_c sont désormais calculés
    // plus haut (avant "NOUVELLE PAGE"), pour que $row_h (juste en dessous)
    // connaisse le nombre réel de lignes de l'appréciation.
    $pdf->Ln(1);
    $y_bas = $pdf->GetY();

    // RECAPITULATIF DISCIPLINES réduit à la place juste nécessaire pour des
    // valeurs à 4 chiffres max (demande explicite) ; RESULTATS DE L'ELEVE
    // agrandi d'autant — voir secondaire/pages/bulletins/pdf_annuel.php pour le détail.
    $wRecap  = 70;
    $wResult = 89;
    $wFar    = $uw - $wRecap - $wResult;

    // Sous-titre EN agrandi (7→8.5, demande explicite) — voir
    // secondaire/pages/bulletins/pdf_annuel.php pour le détail.
    $pdf->SetXY($ml, $y_bas);
    cell2l_c($pdf, $wRecap,  $h_ligne, 'RECAPITULATIF DISCIPLINES', 'Subjects Summary',    'C', true, 9, 8.5);
    cell2l_c($pdf, $wResult, $h_ligne, "RESULTATS DE L'ELEVE",      'Student Performance', 'C', true, 9, 8.5);
    $pdf->Ln($h_ligne);

    // Colonne des intitulés réduite (34→29mm) au profit des colonnes de
    // valeurs — voir secondaire/pages/bulletins/pdf_annuel.php pour le détail.
    $w_rec_lbl = 29; $w_rec_val = ($wRecap - $w_rec_lbl) / 4; // 10.25
    $w_res_lbl = 28; $w_res_val = ($wResult - $w_res_lbl) / 4; // ~15.25

    // Ligne d'en-têtes de colonnes (1er/2e/3e trimestre + Annuel) — demande
    // explicite. Taille UNIFORME sur les 4 cellules de chaque tableau : dans
    // RECAPITULATIF (colonnes étroites, réservées aux nombres à 4 chiffres),
    // "1er Trim" ne tient qu'à 5.5pt max — voir secondaire/pages/bulletins/pdf_annuel.php
    // pour le détail. RESULTATS DE L'ELEVE, colonnes bien plus larges, reste à 8.5.
    $pdf->SetXY($ml, $y_bas + $h_ligne);
    $pdf->Cell($w_rec_lbl, $h_ligne, '', 1, 0, 'C');
    foreach ([1, 2, 3] as $pos) { cell2l_c($pdf, $w_rec_val, $h_ligne, trimestre_court_c($pos), '', 'C', false, 5.5, 4); }
    cell2l_c($pdf, $w_rec_val, $h_ligne, 'Annuel', '', 'C', false, 5.5, 4);
    $pdf->SetXY($ml + $wRecap, $y_bas + $h_ligne);
    $pdf->Cell($w_res_lbl, $h_ligne, '', 1, 0, 'C');
    foreach ([1, 2, 3] as $pos) { cell2l_c($pdf, $w_res_val, $h_ligne, trimestre_court_c($pos), '', 'C', false, 8.5, 4); }
    cell2l_c($pdf, $w_res_val, $h_ligne, 'Annuel', '', 'C', false, 8.5, 4);

    // Police des libellés alignée sur OBSERVATIONS (8.5/7.5), demande explicite.
    $recap_rows_c = [
        ['num',  'Absences NJ. (h)',  'Unjustified Abs.', [$heur_nj_t_c[0], $heur_nj_t_c[1], $heur_nj_t_c[2]], $heur_nj_c],
        ['num',  'Absences Jus. (h)', 'Justified Abs.',   [$heur_jus_t_c[0], $heur_jus_t_c[1], $heur_jus_t_c[2]], $heur_jus_c],
        ['bool', 'Blâme conduite',    'Conduct reprimand', [$mentions_t_c[0]['blame_cond'], $mentions_t_c[1]['blame_cond'], $mentions_t_c[2]['blame_cond']], null],
        ['bool', 'Blâme travail',     'Work reprimand',    [$mentions_t_c[0]['blame_trav'], $mentions_t_c[1]['blame_trav'], $mentions_t_c[2]['blame_trav']], null],
        ['bool', "Tab. d'Honneur",    'Honor Roll',        [$mentions_t_c[0]['tab'] === 'oui', $mentions_t_c[1]['tab'] === 'oui', $mentions_t_c[2]['tab'] === 'oui'], null],
        ['bool', 'Encouragements',    'Encouragements',    [$mentions_t_c[0]['encourag'], $mentions_t_c[1]['encourag'], $mentions_t_c[2]['encourag']], null],
        ['bool', 'Félicitations',     'Congratulations',   [$mentions_t_c[0]['felicit'], $mentions_t_c[1]['felicit'], $mentions_t_c[2]['felicit']], null],
    ];
    foreach ($recap_rows_c as $i => $rr) {
        [$type, $fr, $en, $vals3, $annuel] = $rr;
        $pdf->SetXY($ml, $y_bas + 2 * $h_ligne + $i * $h_ligne);
        cell2l_c($pdf, $w_rec_lbl, $h_ligne, $fr, $en, 'L', false, 8.5, 7.5);
        if ($type === 'num') {
            foreach ($vals3 as $v) {
                $taille_v = cell2l_taille_ajustee_c($pdf, (string)$v, 'B', $w_rec_val - 2.4, 8.5, 6);
                $pdf->SetFont('Arial', 'B', $taille_v);
                $pdf->Cell($w_rec_val, $h_ligne, (string)$v, 1, 0, 'C');
            }
            $taille_v = cell2l_taille_ajustee_c($pdf, (string)$annuel, 'B', $w_rec_val - 2.4, 8.5, 6);
            $pdf->SetFont('Arial', 'B', $taille_v);
            $pdf->Cell($w_rec_val, $h_ligne, (string)$annuel, 1, 0, 'C');
        } else {
            foreach ($vals3 as $v) {
                $x0 = $pdf->GetX(); $y0 = $pdf->GetY();
                $pdf->Rect($x0, $y0, $w_rec_val, $h_ligne);
                checkbox_ltm_c($pdf, $x0 + $w_rec_val / 2 - 1.25, $y0 + $h_ligne / 2 - 1.25, (bool)$v, 2.5);
                $pdf->SetXY($x0 + $w_rec_val, $y0);
            }
            $pdf->Cell($w_rec_val, $h_ligne, '', 1, 0, 'C');
        }
        $pdf->SetFont('Arial', '', 6.5);
    }

    $fmt_rang_c = fn($r, $n) => $r !== null ? $r . 'e/' . $n : '-'; // pour le calcul de taille uniquement
    $fmt_pct_c  = fn($v) => $v !== null ? $v . '%' : '-';
    $result_rows_c = [
        ['Moyenne',          'Average',          'txt',  [$fmt($mr_t[0]),  $fmt($mr_t[1]),  $fmt($mr_t[2]),  $fmt($moy_gen)], true],
        ['Rang',             'Rank',             'rang', [[$rr_t[0], $nb_classes_t[0]], [$rr_t[1], $nb_classes_t[1]], [$rr_t[2], $nb_classes_t[2]], [$rang_gen, $nb_classes_c]], false],
        ['Moy. du premier',  'Highest average',  'txt',  [$fmt($moy_prem_t[0]), $fmt($moy_prem_t[1]), $fmt($moy_prem_t[2]), $fmt($moy_premier)], false],
        ['Moy. du dernier',  'Lowest average',   'txt',  [$fmt($moy_dern_t[0]), $fmt($moy_dern_t[1]), $fmt($moy_dern_t[2]), $fmt($moy_dernier)], false],
        ["Nb d'Admis",       'Number of passes', 'txt',  [(string)$nb_admis_t[0], (string)$nb_admis_t[1], (string)$nb_admis_t[2], (string)$nb_admis], false],
        ['Moy. Gén. Classe', 'Class average',    'txt',  [$fmt($moy_classe_t[0]), $fmt($moy_classe_t[1]), $fmt($moy_classe_t[2]), $fmt($moy_classe_all)], false],
        ['Taux de réussite', 'Success rate',     'txt',  [$fmt_pct_c($taux_reussite_t[0]), $fmt_pct_c($taux_reussite_t[1]), $fmt_pct_c($taux_reussite_t[2]), $fmt_pct_c($taux_reussite)], true],
    ];
    foreach ($result_rows_c as $i => $rr) {
        [$fr, $en, $type, $vals4, $bold] = $rr;
        $pdf->SetXY($ml + $wRecap, $y_bas + 2 * $h_ligne + $i * $h_ligne);
        cell2l_c($pdf, $w_res_lbl, $h_ligne, $fr, $en, 'L', false, 8.5, 7.5);
        if ($type === 'rang') {
            foreach ($vals4 as [$r, $n]) {
                $txt = $fmt_rang_c($r, $n);
                $taille_v = cell2l_taille_ajustee_c($pdf, $txt, 'B', $w_res_val - 2.4, 8.5, 6);
                cell_rang_c($pdf, $w_res_val, $h_ligne, $r, '/' . $n, 1, 'C', $taille_v);
            }
        } else {
            foreach ($vals4 as $txt) {
                $taille_v = cell2l_taille_ajustee_c($pdf, $txt, 'B', $w_res_val - 2.4, 8.5, 6);
                $pdf->SetFont('Arial', 'B', $taille_v);
                $pdf->Cell($w_res_val, $h_ligne, $txt, 1, 0, 'C');
            }
        }
        $pdf->SetFont('Arial', '', 6.5);
    }

    // Bloc MOYENNE ANNUELLE / RANG / COTE — 9 lignes désormais (1 bandeau +
    // 1 en-tête colonnes + 7 lignes).
    $xFar = $ml + $wRecap + $wResult;
    $hFar = (9 * $h_ligne) / 6;
    $moy_str_c  = $moy_gen !== null ? $fmt($moy_gen) . ' / 20' : '- / 20';
    $rang_str_c = $rang_gen !== null ? $rang_gen . 'e / ' . $nb_classes_c : '-';
    $cote_str_c = $moy_gen !== null ? $lettre_grp($moy_gen) : '-';

    // Traductions EN ajoutées, italique, même taille que le reste du texte
    // anglais de ces tableaux (7.5) — voir secondaire/pages/bulletins/pdf_annuel.php.
    $pdf->SetXY($xFar, $y_bas);
    cell2l_c($pdf, $wFar, $hFar, 'MOYENNE ANNUELLE', 'Annual Average', 'C', true, 9, 7.5);
    $pdf->Ln($hFar); $pdf->SetX($xFar);
    $taille_moy_c = cell2l_taille_ajustee_c($pdf, $moy_str_c, 'B', $wFar - 2.4, 13, 8);
    $pdf->SetFont('Arial', 'B', $taille_moy_c);
    $pdf->SetTextColor(0, 130, 0);
    $pdf->Cell($wFar, $hFar, uc($moy_str_c), 1, 1, 'C');
    $pdf->SetTextColor(0, 0, 0);

    $pdf->SetX($xFar);
    cell2l_c($pdf, $wFar, $hFar, 'RANG', 'Rank', 'C', true, 9, 7.5);
    $pdf->Ln($hFar); $pdf->SetX($xFar);
    $taille_rang_c = cell2l_taille_ajustee_c($pdf, $rang_str_c, 'B', $wFar - 2.4, 11, 7);
    cell_rang_c($pdf, $wFar, $hFar, $rang_gen, ' / ' . $nb_classes_c, 1, 'C', $taille_rang_c);
    $pdf->Ln($hFar); $pdf->SetX($xFar);

    $pdf->SetX($xFar);
    cell2l_c($pdf, $wFar, $hFar, 'COTE', 'Grade', 'C', true, 9, 7.5);
    $pdf->Ln($hFar); $pdf->SetX($xFar);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->SetTextColor(0, 130, 0);
    $pdf->Cell($wFar, $hFar, uc($cote_str_c), 1, 1, 'C');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Arial', '', 6.5);

    $pdf->SetY($y_bas + 9 * $h_ligne);

    // Décision de fin d'année
    $pdf->Ln(1);
    $yd = $pdf->GetY();
    $wDec = $uw * 0.55; $wObs = $uw - $wDec;
    $pdf->SetXY($ml, $yd);
    cell2l_c($pdf, $wDec, $h_bas, "DECISION DES CONSEILS DE FIN D'ANNEE", 'End of year council decisions', 'C', true, 10.5, 8.5);
    cell2l_c($pdf, $wObs, $h_bas, "OBSERVATIONS DU CHEF D'ETABLISSEMENT", "Principal's remarks", 'C', true, 10.5, 8.5);
    $pdf->Ln($h_bas);

    $yd2 = $pdf->GetY();
    // Plancher réduit à 30mm — juste assez pour la date/signature (section 7
    // ci-dessous) — au lieu de l'ancien 70mm qui dépassait $bas_dispo_c (donc
    // chevauchait le QR) dès que le tableau des disciplines laissait moins de
    // 70mm de reste (voir secondaire/pages/bulletins/pdf_annuel.php pour le détail).
    $h_obs_box_c = max(30, $bas_dispo_c - $yd2);

    // Appréciation générée automatiquement (texte libre, centré, sans cadre).
    $pdf->SetFont('Arial', '', 7.5);
    $pdf->SetXY($ml, $yd2 + 2);
    $pdf->MultiCell($wDec, 4, uc($appreciation_annuelle_c ?: '-'), 0, 'C');

    // DECISION DU CONSEIL DE CLASSE — cochée et complétée AUTOMATIQUEMENT
    // pour CET élève (demande explicite) si le conseil de classe annuel a
    // déjà été saisi et enregistré (module secondaire/pages/conseil_classe/, table
    // decision_conseil) — voir secondaire/pages/bulletins/pdf_annuel.php pour le détail
    // (même logique). Sinon tout reste vide/décoché.
    $decision_row_c = db_one(
        "SELECT dc.decision, dc.observation, c2.designation AS next_classe_designation
         FROM decision_conseil dc
         LEFT JOIN classe c2 ON c2.id = dc.next_classe
         WHERE dc.id_eleve=? AND dc.id_annee=? AND dc.type='annee' AND dc.id_trim=0",
        [$id_eleve, $id_annee]
    );
    $dec_val_c         = $decision_row_c['decision'] ?? null;
    $dec_next_classe_c = $decision_row_c['next_classe_designation'] ?? '';
    $dec_observation_c = $decision_row_c['observation'] ?? '';

    $y_cc = $pdf->GetY() + 2;
    $h_cc_titre = 6;
    $w_cc = $wDec * 0.62;
    $pdf->SetFillColor(219, 228, 245);
    $pdf->SetDrawColor(26, 60, 107);
    $pdf->SetLineWidth(0.3);
    $pdf->RoundedRect($ml, $y_cc, $w_cc, $h_cc_titre, $h_cc_titre / 2, 'FD');
    $pdf->SetXY($ml, $y_cc + 0.3);
    $pdf->SetFont('Arial', 'BI', 7.5);
    $pdf->SetTextColor(26, 60, 107);
    $pdf->Cell($w_cc, $h_cc_titre / 2, uc('DECISION DU CONSEIL DE CLASSE'), 0, 2, 'C');
    $pdf->SetFont('Arial', 'I', 6);
    $pdf->Cell($w_cc, $h_cc_titre / 2 - 0.3, 'Class council decision', 0, 1, 'C');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetLineWidth(0.2);

    $y_cc2 = $y_cc + $h_cc_titre + 2;
    $cc_lignes_c = [
        ['PROMU(E) EN',        false, $dec_val_c === 'Admis',        $dec_val_c === 'Admis' ? $dec_next_classe_c : ''],
        ['REDOUBLE LA',        false, $dec_val_c === 'Redoublement', $dec_val_c === 'Redoublement' ? ($insc['classe'] ?? '') : ''],
        ["EXCLU(E) POUR",      false, $dec_val_c === 'Exclu',        $dec_val_c === 'Exclu' ? (string)$dec_observation_c : ''],
        ['AGE', true, false, ''],
        ['TRAVAIL INSUFFISANT', true, false, ''],
        ['CONDUITE DEPLORABLE', true, false, ''],
        ['NE PEUT TRIPLER', true, false, ''],
        ["TROP D'ABSENCES", true, false, ''],
        ['ABANDON', true, $dec_val_c === 'Abandon', ''],
    ];
    foreach ($cc_lignes_c as $i => $cl) {
        [$lib, $indente, $checked, $valeur] = $cl;
        $y_row = $y_cc2 + $i * $h_dec;
        $x_chk = $ml + ($indente ? 8 : 2);
        checkbox_ltm_c($pdf, $x_chk, $y_row + $h_dec / 2 - 1, $checked, 2);
        $pdf->SetXY($x_chk + 3.5, $y_row);
        $pdf->SetFont('Arial', $indente ? '' : 'B', $indente ? 6.5 : 7);
        $suffixe = $indente ? '' : ' ' . ($valeur !== '' ? $valeur : '..........................');
        $pdf->Cell($wDec - ($x_chk - $ml) - 3.5, $h_dec, uc($lib . $suffixe), 0, 1, 'L');
    }
    $pdf->SetFont('Arial', '', 6.5);

    // Cadre OBSERVATIONS — bordure haute seulement (demande explicite : plus
    // de bordure gauche/droite/basse), le bandeau de titre au-dessus fait
    // déjà office de délimitation visuelle.
    $pdf->SetFont('Arial', '', 6);
    $pdf->SetXY($ml + $wDec, $yd2);
    $pdf->MultiCell($wObs, $h_obs_box_c, '', 'T', 'L');

    // Signature
    $pdf->SetFont('Arial', '', 8.5);
    $pdf->SetXY($ml + $wDec, $yd2 + 6);
    $pdf->Cell($wObs, 5.5, uc('Mbé, le ' . date('d-m-Y') . '.'), 0, 1, 'C');
    $pdf->SetX($ml + $wDec);
    $pdf->SetFont('Arial', 'I', 7.5);
    $pdf->Cell($wObs, 4.5, 'On', 0, 1, 'C');
    $pdf->Ln(2.5);
    $pdf->SetX($ml + $wDec);
    $pdf->SetFont('Arial', 'B', 8.5);
    $pdf->Cell($wObs, 5.5, u(strtoupper($etab['chef_etablissement'] ?? 'LE PROVISEUR') . ','), 0, 1, 'C');
    $pdf->SetX($ml + $wDec);
    $pdf->SetFont('Arial', 'I', 7.5);
    $pdf->Cell($wObs, 4.5, u($etab['chef_etablissement_en'] ?? 'The Principal'), 0, 1, 'C');

    // Signature numérique (uniquement si demandée à l'impression — jamais
    // automatique — et si l'admin en a configuré une dans les paramètres).
    if (($_GET['signature'] ?? '0') === '1') {
        $sig_w = 22;
        $ph = $pdf->GetPageHeight();
        $sx = $ml + $wDec + ($wObs - $sig_w) / 2;
        $sy = $pdf->GetY() + 0.5;
        pdf_signature_appliquer($pdf, 'bulletin_annuel_classe', 'chef_etablissement', 0, 0, $pw, $ph, [
            'x_pct' => $sx / $pw * 100, 'y_pct' => $sy / $ph * 100, 'w_pct' => $sig_w / $pw * 100, 'h_pct' => null,
        ]);
    }

    // QR code de vérification d'authenticité — centré horizontalement sur la
    // page, tout en bas, à 2-3mm au-dessus du copyright (demande explicite,
    // déplacé hors du cadre OBSERVATIONS), avec la photo de l'élève incrustée
    // au centre.
    require_once __DIR__ . '/../../pdf/verif_lib.php';
    require_once __DIR__ . '/../../pdf/qrcode.php';

    $verif_url_c = bulletin_verif_url($id_eleve, 'annee', $id_annee, id_affichage_eleve($eleve));
    $qr_tmp_c = tempnam(sys_get_temp_dir(), 'abzqr_') . '.png';
    try {
        $qr_gen_c = new QRCode($verif_url_c, ['s' => 'qr-h']);
        $qr_img_c = $qr_gen_c->render_image();
        qr_incruster_photo_c($qr_img_c, $photo_path_c);
        imagepng($qr_img_c, $qr_tmp_c);
        imagedestroy($qr_img_c);

        $qr_size_c = 22;
        $qr_x_c = ($pw - $qr_size_c) / 2; // centré sur la largeur de la page
        $qr_y_c = ($pdf->GetPageHeight() - 9) - 2.5 - $qr_size_c; // 2.5mm au-dessus du copyright
        $pdf->Image($qr_tmp_c, $qr_x_c, $qr_y_c, $qr_size_c, $qr_size_c, 'PNG');
    } finally {
        if (is_file($qr_tmp_c)) unlink($qr_tmp_c);
    }

    // Mention copyright en bas de page, centrée, à l'intérieur du cadre
    // extérieur (demande explicite) — identique à secondaire/pages/bulletins/pdf.php.
    $pdf->SetFont('Arial', '', 6.5);
    $pdf->SetXY($ml, $pdf->GetPageHeight() - 9);
    $pdf->Cell($uw, 4, uc('Copyright © SIGES ABZ   , E-mail: abdoulazizyahya@gmail.com'), 0, 1, 'C');
}

// Sortie
$mode   = $dl ? 'D' : 'I';
$cl_db  = db_one("SELECT designation FROM classe WHERE id=?", [$id_classe]);
$suffix = preg_replace('/\W+/', '_', $cl_db['designation'] ?? 'classe');
$pdf->Output($mode, 'bulletins_' . $suffix . '.pdf');