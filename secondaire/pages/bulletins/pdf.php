<?php
/**
 * Bulletin scolaire — modèle LTM
 * GET : eleve=ID, trim=ID_TRIMESTRE, annee=ID_ANNEE, seq=ID_SEQ (optionnel)
 */
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
require_once __DIR__ . '/../../pdf/verif_lib.php';

// Accès public via le QR code du bulletin (jeton "vh" = hash de vérification
// déjà calculé pour CE bulletin précis) : la personne qui scanne n'a pas de
// compte dans le système et ne doit pas avoir à s'y connecter pour consulter
// le document qu'elle vient de vérifier (voir verif_bulletin.php). En dehors
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
if (!$acces_public) {
    exiger_connexion();
}
require_once __DIR__ . '/../../pdf/fpdf.php';
require_once __DIR__ . '/../../pdf/header_pdf.php';

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

// ── Paramètres ────────────────────────────────────────────────────
$id_eleve = (int)($_GET['eleve'] ?? 0);
$id_trim  = (int)($_GET['trim']  ?? 0);
$id_seq_u = (int)($_GET['seq']   ?? 0);
$id_annee = (int)($_GET['annee'] ?? 0);

if (!$id_eleve) die('Parametre eleve manquant.');

$eleve = db_one("SELECT * FROM eleve WHERE id=?", [$id_eleve]);
if (!$eleve) die('Eleve introuvable.');

$annee     = db_one("SELECT * FROM annee_scolaire WHERE id=?", [$id_annee]);
$val_annee = $annee['libelle'] ?? '';

$insc = db_one(
    "SELECT i.*, c.designation AS classe, c.id AS id_classe
     FROM inscription i JOIN classe c ON c.id=i.id_classe
     WHERE i.id_eleve=? AND i.id_annee=?",
    [$id_eleve, $id_annee]
);
if (!$insc) die('Inscription introuvable.');

// ── Trimestre à afficher ────────────────────────────────────────────
// Chantier APC (voir prompt_continuite_ABZ_MBE_1.md) : le bulletin est
// désormais toujours scopé au trimestre entier, les compétences n'ayant
// pas d'équivalent "séquence unique". Le paramètre ?seq= n'est conservé
// que pour résoudre le trimestre depuis un ancien lien (compatibilité),
// et pour construire l'URL de vérification QR historique (voir §8).
$is_seq = ($id_seq_u > 0);
if ($is_seq) {
    $id_trim = (int) db_val("SELECT id_trim FROM sequence WHERE id=?", [$id_seq_u]) ?: $id_trim;
}
$trim_info = db_one("SELECT * FROM trimestre WHERE id=?", [$id_trim]);
if (!$trim_info) die('Trimestre introuvable.');
$titre_bull = strtoupper($trim_info['libelle'] ?? '');

// ── Établissement ─────────────────────────────────────────────────
$etab = get_etablissement();

// ── Prof principal ────────────────────────────────────────────────
$pp = db_one(
    "SELECT e.nom_ens, e.prenom_ens FROM enseignat_principal ep
     JOIN enseignant e ON e.matricule_ens=ep.matricule_ens
     WHERE ep.IDClasses=? AND ep.val_annee=? LIMIT 1",
    [$insc['id_classe'], $val_annee]
);
$nom_pp = $pp ? trim($pp['nom_ens'] . ' ' . ($pp['prenom_ens'] ?? '')) : '-';

// ── Tuteur ────────────────────────────────────────────────────────
$tuteur = db_one("SELECT * FROM tuteur WHERE id_eleve=? LIMIT 1", [$id_eleve]);
$contacts_parents = $tuteur
    ? trim(($tuteur['nom'] ?? '') . ' ' . ($tuteur['prenom'] ?? '') . ' - ' . ($tuteur['telephone'] ?? ''))
    : '';

// ── Disciplines ───────────────────────────────────────────────────
$disciplines = db_all(
    "SELECT d.id_mat, d.coef, d.ordre, m.libelle AS matiere,
            g.libelle_groupe_comp AS groupe_lib, d.id_groupe,
            TRIM(CONCAT(e.nom_ens,' ',COALESCE(e.prenom_ens,''))) AS enseignant
     FROM discipline d
     JOIN matiere m ON m.id=d.id_mat AND m.actif=1
     LEFT JOIN groupe g ON g.id_groupe_comp=d.id_groupe
     LEFT JOIN dispenser disp ON disp.id_mat=d.id_mat AND disp.IDClasses=d.IDClasses AND disp.val_annee=?
     LEFT JOIN enseignant e ON e.matricule_ens=disp.matricule_ens
     WHERE d.IDClasses=?
     ORDER BY d.id_groupe, d.ordre, m.libelle",
    [$val_annee, $insc['id_classe']]
);

// ── Élèves inscrits dans la classe ───────────────────────────────
$all_enrolled = db_all(
    "SELECT e.id FROM eleve e
     JOIN inscription i ON i.id_eleve=e.id AND i.id_classe=? AND i.id_annee=?
     WHERE e.statut='actif'",
    [$insc['id_classe'], $id_annee]
);
$enrolled_ids = array_column($all_enrolled, 'id');
$nb_inscrits  = count($enrolled_ids);

// ── Compétences du trimestre, par matière ───────────────────────────
// Une compétence est définie une seule fois par niveau (voir chantier APC) :
// scopée par matière + niveau de la classe + trimestre, nombre variable.
$code_niveau = db_val("SELECT code_niveau FROM classe WHERE id=?", [$insc['id_classe']]);
$competences_par_mat = []; // [id_mat] => [ {id, libelle, ordre}, ... ]
foreach ($disciplines as $d) {
    $competences_par_mat[$d['id_mat']] = db_all(
        "SELECT * FROM competence WHERE id_matiere=? AND code_niveau=? AND id_trim=? ORDER BY ordre",
        [$d['id_mat'], $code_niveau, $id_trim]
    );
}
$all_comp_ids = [];
foreach ($competences_par_mat as $comps) { foreach ($comps as $c) { $all_comp_ids[] = (int)$c['id']; } }

// ── Notes brutes (par compétence) ───────────────────────────────────
$all_notes_raw = [];
if ($all_comp_ids) {
    $in_c = implode(',', array_fill(0, count($all_comp_ids), '?'));
    $all_notes_raw = db_all(
        "SELECT n.id_eleve, n.id_matiere, n.id_competence, n.valeur
         FROM note n
         JOIN inscription i ON i.id_eleve=n.id_eleve AND i.id_annee=? AND i.id_classe=?
         JOIN eleve el ON el.id=n.id_eleve AND el.statut='actif'
         WHERE n.id_competence IN ($in_c)",
        array_merge([$id_annee, $insc['id_classe']], $all_comp_ids)
    );
}
// notes_idx[eid][id_comp] = valeur
$notes_idx = [];
foreach ($all_notes_raw as $row) {
    $notes_idx[(int)$row['id_eleve']][(int)$row['id_competence']] = (float)$row['valeur'];
}
$abs_just = []; // plus de justification par compétence pour l'instant (voir §Phase 6)

// ── Nombre de notes par compétence (seuil 50% "absent = 0") ────────
$notes_count = []; // [id_comp] = nb élèves avec note
foreach ($all_notes_raw as $row) {
    $c = (int)$row['id_competence'];
    $notes_count[$c] = ($notes_count[$c] ?? 0) + 1;
}

// ── Fonctions helpers ─────────────────────────────────────────────
function note_eff_comp(int $eid, int $id_comp, array $ni, array $nc, int $nb_ins): ?float {
    if (isset($ni[$eid][$id_comp])) return $ni[$eid][$id_comp];
    $cnt = $nc[$id_comp] ?? 0;
    if ($nb_ins > 0 && $cnt >= ceil($nb_ins / 2)) return 0.0; // absent = 0
    return null;
}

// Moyenne d'une matière (groupe de compétence) pour un élève = moyenne
// arithmétique simple des notes de ses compétences évaluées ce trimestre
// (même algorithme que DANFILI Note_Moy_trim_par_Gpe_comp() — voir analyse
// du 03/08/2026 dans le prompt de continuité).
function mat_avg_comp(int $eid, array $comps, array $ni, array $nc, int $nb_ins): ?float {
    $tot = 0; $cnt = 0;
    foreach ($comps as $c) {
        $v = note_eff_comp($eid, (int)$c['id'], $ni, $nc, $nb_ins);
        if ($v !== null) { $tot += $v; $cnt++; }
    }
    return $cnt > 0 ? $tot / $cnt : null;
}

function fmt_note_b(?float $v): string {
    if ($v === null) return '';
    $s = rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    // Zéro de tête sur la partie entière (demande explicite) : "4" -> "04",
    // "8.75" -> "08.75" — appliqué à toutes les notes/NxC/moyennes du bulletin.
    [$int, $dec] = array_pad(explode('.', $s, 2), 2, null);
    $int = str_pad($int, 2, '0', STR_PAD_LEFT);
    return $dec !== null ? "$int.$dec" : $int;
}
function mention_gen_b(float $a): string {
    if ($a >= 16) return 'Excellent'; if ($a >= 14) return 'Tres bien';
    if ($a >= 12) return 'Bien'; if ($a >= 10) return 'Assez bien';
    if ($a >= 8)  return 'Passable'; return 'Insuffisant';
}

// ── Décisions du conseil de classe (fournies par l'utilisateur) ────
// Les fonctions Tableau_honneur()/Encouragement()/Felicitation()/
// Avertissement_travail()/Blame_travail()/Avertissement_Conduite_eleve()/
// Blame_Conduite_eleve() ont été déplacées dans fonctions.php (préfixe
// bulletin_*), avec leurs seuils désormais lus dans
// reglage_mention_bulletin (configurable par l'administrateur, voir
// secondaire/pages/parametres/index.php onglet "Mentions") au lieu d'être codés en
// dur — voir section 5 plus bas pour l'appel (get_reglage_mention_bulletin()
// + $heur_jus/$heur_nj lus via eleve_absence_trimestre()).
function rang_eleve_b(float $avg, array $avgs_sorted): int {
    $r = 1; foreach ($avgs_sorted as $a) { if ($a > $avg) $r++; else break; } return $r;
}

// ── Matières avec au moins une compétence notée (pour seuil 50% classement) ──
$mats_avec_notes = [];
foreach ($disciplines as $d) {
    foreach ($competences_par_mat[$d['id_mat']] ?? [] as $c) {
        if (($notes_count[(int)$c['id']] ?? 0) > 0) { $mats_avec_notes[] = $d['id_mat']; break; }
    }
}
$nb_mats_avec_notes = count($mats_avec_notes);

// Calcul moyenne+classement d'un élève : retourne [moy, est_classe, coef_total]
function moy_eleve_bull(int $eid, array $discs, array $competences_par_mat, array $ni, array $nc, int $nb_ins, array $mats_avec_notes, int $nb_mats_an): array {
    $tot = 0; $coef = 0; $nb_data = 0;
    foreach ($discs as $d) {
        if (!in_array($d['id_mat'], $mats_avec_notes)) continue;
        $avg = mat_avg_comp($eid, $competences_par_mat[$d['id_mat']] ?? [], $ni, $nc, $nb_ins);
        if ($avg !== null) { $tot += $avg * $d['coef']; $coef += $d['coef']; $nb_data++; }
    }
    $moy = $coef > 0 ? $tot / $coef : null;
    $classe_ok = $nb_mats_an > 0 && $nb_data >= ceil($nb_mats_an / 2);
    return [$moy, $classe_ok, $coef];
}

// Moyennes classe (pour rang)
$all_moys = [];
foreach ($enrolled_ids as $eid) {
    [$m, $ok] = moy_eleve_bull($eid, $disciplines, $competences_par_mat, $notes_idx, $notes_count, $nb_inscrits, $mats_avec_notes, $nb_mats_avec_notes);
    if ($ok && $m !== null) $all_moys[$eid] = $m;
}
arsort($all_moys);

// Données élève courant
[$moy_generale, $est_classe, $total_coef_eleve] = moy_eleve_bull($id_eleve, $disciplines, $competences_par_mat, $notes_idx, $notes_count, $nb_inscrits, $mats_avec_notes, $nb_mats_avec_notes);
$rang_general = null;
if ($est_classe && $moy_generale !== null) {
    $rang_general = 1;
    foreach ($all_moys as $m) { if ($m > $moy_generale) $rang_general++; else break; }
}
$moy_premier   = !empty($all_moys) ? max($all_moys) : null;
$moy_dernier   = !empty($all_moys) ? min($all_moys) : null;
$moy_classe    = !empty($all_moys) ? array_sum($all_moys) / count($all_moys) : null;
$nb_classes    = count($all_moys);
$nb_admis      = count(array_filter($all_moys, fn($m) => $m >= 10));
$taux_reussite = $nb_classes > 0 ? round($nb_admis / $nb_classes * 100, 2) : 0;

// Stats par matière (min/avg/max/rang) — moyenne de chaque élève sur les
// compétences de cette matière ce trimestre.
$mat_stats = [];
foreach ($disciplines as $d) {
    $id_mat = $d['id_mat'];
    $avgs = [];
    foreach ($enrolled_ids as $eid) {
        $avg = mat_avg_comp($eid, $competences_par_mat[$id_mat] ?? [], $notes_idx, $notes_count, $nb_inscrits);
        if ($avg !== null) $avgs[] = $avg;
    }
    if (empty($avgs)) {
        $mat_stats[$id_mat] = ['min' => null, 'avg' => null, 'max' => null, 'avgs_sorted' => []];
    } else {
        rsort($avgs);
        $mat_stats[$id_mat] = ['min' => min($avgs), 'avg' => array_sum($avgs) / count($avgs), 'max' => max($avgs), 'avgs_sorted' => $avgs];
    }
}
// Le tableau "Rappel Eval1/Eval2" de l'ancien système par séquences n'a pas
// d'équivalent en compétences (pas de sous-période au sein d'un trimestre) —
// supprimé, voir §RESULTATS DE L'ELEVE plus bas.

// ── Génération PDF ────────────────────────────────────────────────
$pdf = new PDF_LTM('P', 'mm', 'A4');
$pdf->SetMargins(8, 8, 8);
// Marge de saut de page alignée sur le cadre extérieur (4mm) — la mention
// copyright est dessinée volontairement tout en bas, juste au-dessus du
// cadre ; avec l'ancienne marge de 8mm, FPDF déclenchait un saut de page
// automatique dès qu'on dessinait quoi que ce soit sous cette limite,
// envoyant le copyright seul sur une 2e page (bug corrigé).
$pdf->SetAutoPageBreak(true, 4);
$pdf->AddPage();
$pw = $pdf->GetPageWidth();
$ph = $pdf->GetPageHeight();
$ml = 8; $mr = 8;
$uw = $pw - $ml - $mr; // 194

pdf_filigrane($pdf, $etab, $pw, $ph);

// Cadre extérieur à coins arrondis (comme le modèle papier)
$pdf->SetDrawColor(0, 0, 0);
$pdf->SetLineWidth(0.4);
$pdf->RoundedRect(4, 4, $pw - 8, $ph - 8, 4, 'D');
$pdf->SetLineWidth(0.2);

// ── 1. En-tête bilingue ──────────────────────────────────────────
$col3 = $uw / 3;
$y0   = 8;

$pdf->SetXY($ml, $y0);
$pdf->SetFont('Arial', '', 6.5);
$pdf->MultiCell($col3, 3.0, u(
    "REPUBLIQUE DU CAMEROUN\nPaix - Travail - Patrie\n***************\n" .
    ($etab['region_fr'] ?? "VOTRE REGION ICI") . "\n" .
    ($etab['departement_fr'] ?? 'DEPARTEMENT ') . "\n" .
    ($etab['arrondissement_fr'] ?? 'ARRONDISSEMENT') . "\n" .
    strtoupper($etab['nom_fr'] ?? 'NOM ECOLE ') . "\n" .
    "B.P. " . ($etab['boite_postale'] ?? 'XX') . " ville Tel.: " . ($etab['telephone'] ?? '') . "\n" .
    ($etab['email'] ?? 'adresse email')
), 0, 'C');

// Logo agrandi et centré horizontalement ET verticalement dans la bande du
// milieu (demande explicite, était calé en haut) — hauteur réelle déduite du
// ratio de l'image pour un centrage vertical exact quel que soit son format.
$logo_path = !empty($etab['logo']) ? __DIR__ . '/../../../assets/uploads/' . $etab['logo'] : '';
$logo_bande_h = 21; // hauteur du bloc en-tête (y0=8 à 29, voir plus bas)
$logo_w = 28;
$logo_h = $logo_w;
if ($logo_path && is_file($logo_path)) {
    $dim = @getimagesize($logo_path);
    if ($dim && $dim[0] > 0) $logo_h = $logo_w * $dim[1] / $dim[0];
}
$logo_x = $ml + $col3 + ($col3 - $logo_w) / 2;
$logo_y = $y0 + ($logo_bande_h - $logo_h) / 2;
if ($logo_path && is_file($logo_path)) {
    $pdf->Image($logo_path, $logo_x, $logo_y+5, $logo_w);
} else {
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetXY($logo_x, $y0 + ($logo_bande_h - 16) / 2);
    $pdf->Cell($logo_w, 16, u($etab['sigle'] ?? 'LTM'), 1, 0, 'C');
}

$xr = $ml + $col3 * 2;
$pdf->SetXY($xr, $y0);
$pdf->SetFont('Arial', '', 6.5);
$pdf->MultiCell($col3, 3.0, u(
    "REPUBLIC OF CAMEROON\nPeace - Work - Fatherland\n***************\n" .
    ($etab['region_en'] ?? 'ADAMAWA REGION') . "\n" .
    ($etab['division_en'] ?? 'VINA DIVISION') . "\n" .
    ($etab['subdivision_en'] ?? 'MBE SUBDIVISION') . "\n" .
    strtoupper($etab['nom_en'] ?? 'GTHS OF MBE') . "\n" .
    "P.O. BOX. " . ($etab['boite_postale'] ?? '32') . " Mbe  Phone: " . ($etab['telephone'] ?? '') . "\n" .
    ($etab['email'] ?? 'lyceetechniquembe@yahoo.fr')
),
0, 'C');

$pdf->SetY(max($pdf->GetY(), 29));
$pdf->SetFont('Arial', 'I', 6.5);
$pdf->SetX($ml);
$pdf->Cell($uw, 3.2, u('IMMATRICULATION : ' . ($etab['immatriculation'] ?? '2JH1TEFD110316102')), 0, 1, 'C');

// ── 2. Titre (bandeau en pilule, comme le modèle) ──────────────────
// Bandeau titre compacté (demande explicite : gagner de la place du haut
// jusqu'au bas du bulletin plutôt que de trop réduire la police du tableau
// des compétences).
$y_titre = $pdf->GetY();
$h_titre = 6;
pdf_fill($pdf, 'bandeau_titre'); // bleu très clair par défaut, personnalisable (Réglages > Couleurs bulletin)
pdf_draw($pdf, 'bordure_marque'); // bleu marine par défaut, personnalisable
$pdf->SetLineWidth(0.3);
$pdf->RoundedRect($ml, $y_titre, $uw, $h_titre, $h_titre / 2, 'FD');
$pdf->SetXY($ml, $y_titre-1);
$pdf->SetFont('Arial', 'BI', 14);
$pdf->SetTextColor(26, 60, 107);
$pdf->Cell($uw, $h_titre, u('BULLETIN SCOLAIRE DU ' . $titre_bull), 0, 1, 'C');
$pdf->SetFont('Arial', 'I', 10);
$pdf->ln(-1.5);
$pdf->Cell($uw, $h_titre * 0.35, u('Term Report '), 2, 1, 'C');
$pdf->SetTextColor(0, 0, 0);
$pdf->SetLineWidth(0.2);
$pdf->SetFont('Arial', 'B', 8.5);
$pdf->SetX($ml);
$pdf->Cell($uw, 3.8, u('Année scolaire : ' . $val_annee), 0, 1, 'C');

// ── 3. Infos élève ───────────────────────────────────────────────
$pdf->Ln(0.3);

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

// Dessine un rang ("2e/22", "2e", ...) avec le "e" ordinal en exposant
// (demande explicite : tous les rangs affichés) au lieu du "e" en taille
// normale. $rang=null dessine simplement "-". $suffixe est ce qui suit le
// "e" (ex. "/22", " / 22", ou "" si le rang est affiché seul). Le "e" est
// dessiné à ~62% de $taille et surélevé d'environ 12% de $taille (converti
// pt→mm) — ne gère PAS l'ajustement automatique de taille : passer une
// $taille déjà déterminée (ex. via cell2l_taille_ajustee() sur la version
// texte brut "2e/22", qui donne une largeur légèrement supérieure à ce qui
// sera réellement dessiné ici, donc jamais de débordement). Laisse le
// curseur à (x+$w, y) — comme un Cell(ln=0) — à l'appelant de gérer la
// suite (Ln(), SetX()...).
function cell_rang(FPDF $pdf, float $w, float $h, ?int $rang, string $suffixe, int $border, string $align, float $taille, bool $bold = true): void {
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
    $w2 = $pdf->GetStringWidth(u($suffixe));
    $pdf->SetFont('Arial', $style, $taille_e);
    $we = $pdf->GetStringWidth('e');
    $total = $w1 + $we + $w2;
    if ($align === 'C')     { $x0 = $x + ($w - $total) / 2; }
    elseif ($align === 'R') { $x0 = $x + $w - $total - 1.2; }
    else                    { $x0 = $x + 1.2; }

    $pdf->SetFont('Arial', $style, $taille);
    $pdf->SetXY($x0, $y);
    $pdf->Cell($w1, $h, $part1, 0, 0, 'L');

    $raise = $taille * 0.12; // décalage vertical approximatif (mm)
    $pdf->SetXY($x0 + $w1, $y - $raise);
    $pdf->SetFont('Arial', $style, $taille_e);
    $pdf->Cell($we, $h, 'e', 0, 0, 'L');

    $pdf->SetXY($x0 + $w1 + $we, $y);
    $pdf->SetFont('Arial', $style, $taille);
    $pdf->Cell($w2, $h, u($suffixe), 0, 0, 'L');

    $pdf->SetXY($x + $w, $y);
}

// Réduit par petits pas la taille de police d'un texte (dans le style donné,
// B/I/etc. — l'empattement gras/italique change la largeur réelle du texte)
// jusqu'à ce qu'il tienne dans $largeur_max, jamais en dessous de $taille_min.
// Utilisé par cell2l() pour que $taille_fr/$taille_en soient des tailles
// MAXIMALES : on peut les augmenter sans risque, chaque cellule reçoit alors
// automatiquement la plus grande taille qui tient réellement dans sa largeur.
function cell2l_taille_ajustee(FPDF $pdf, string $texte, string $style, float $largeur_max, float $taille_max, float $taille_min): float {
    $taille = $taille_max;
    $pdf->SetFont('Arial', $style, $taille);
    while ($taille > $taille_min && $pdf->GetStringWidth(u($texte)) > $largeur_max) {
        $taille -= 0.25;
        $pdf->SetFont('Arial', $style, $taille);
    }
    return $taille;
}

// Cellule à 2 lignes (libellé FR en gras + traduction EN en italique, plus
// petite, juste en dessous) — reproduit le style bilingue du modèle de
// bulletin fourni par l'utilisateur (section basse : DISCIPLINES/TRAVAIL,
// PROFIL DE LA CLASSE, RESULTATS DE L'ELEVE). $en = '' pour une cellule à
// une seule ligne (centrée verticalement, ex. MOYENNE/RANG). Repositionne le
// curseur en fin de cellule comme le ferait un Cell() normal (ln=0).
// $taille_fr/$taille_en sont des MAXIMUMS (demande explicite : polices plus
// grandes mais sans jamais déborder du cadre) — réduits automatiquement si
// besoin par cell2l_taille_ajustee(), avec un plancher de lisibilité minimal.
function cell2l(FPDF $pdf, float $w, float $h, string $fr, string $en, string $align = 'L', bool $fill = false, float $taille_fr = 6, float $taille_en = 4.3, ?array $fill_color = null, bool $tight = false): void {
    $x = $pdf->GetX(); $y = $pdf->GetY();
    if ($fill) { $fill_color = $fill_color ?? couleur_pdf('bandeau_section'); $pdf->SetFillColor($fill_color[0], $fill_color[1], $fill_color[2]); $pdf->Rect($x, $y, $w, $h, 'F'); }
    $pdf->Rect($x, $y, $w, $h);
    // Marge de sécurité (offset 0.8+0.4 + cMargin interne de Cell()) — même
    // principe que fpdf_texte_ajuste() ailleurs dans ce fichier.
    $dispo = $w - 2.4;
    if ($en === '') {
        $taille_fr = cell2l_taille_ajustee($pdf, $fr, 'B', $dispo, $taille_fr, 5.5);
        $pdf->SetFont('Arial', 'B', $taille_fr);
        $pdf->SetXY($x + 0.8, $y);
        $pdf->Cell($w - 1.2, $h, u($fr), 0, 0, $align);
    } else {
        $taille_fr = cell2l_taille_ajustee($pdf, $fr, 'B', $dispo, $taille_fr, 5.5);
        $taille_en = cell2l_taille_ajustee($pdf, $en, 'I', $dispo, $taille_en, 4.5);
        // Interligne FR/EN resserré (demande explicite) : quasiment plus de
        // marge morte entre les 2 lignes, pour laisser le plus de place
        // possible au texte lui-même. $tight (demande explicite, case
        // d'identification élève) va plus loin : répartition stricte 50/50
        // sans aucun décalage haut, pour 2 lignes de MÊME taille de police.
        if ($tight) {
            [$y_fr, $h_fr, $y_en, $h_en] = [$y, $h * 0.5, $y + $h * 0.5, $h * 0.5];
        } else {
            [$y_fr, $h_fr, $y_en, $h_en] = [$y + $h * 0.02, $h * 0.52, $y + $h * 0.54, $h * 0.44];
        }
        $pdf->SetFont('Arial', 'B', $taille_fr);
        $pdf->SetXY($x + 0.8, $y_fr);
        $pdf->Cell($w - 1.2, $h_fr, u($fr), 0, 0, $align);
        $pdf->SetFont('Arial', 'I', $taille_en);
        $pdf->SetXY($x + 0.8, $y_en);
        $pdf->Cell($w - 1.2, $h_en, u($en), 0, 0, $align);
    }
    $pdf->SetXY($x + $w, $y);
}

// Photo légèrement réduite (demande explicite : gagner de la place du haut
// jusqu'au bas du bulletin plutôt que de trop réduire la police du tableau
// des compétences).
$photo_w = 20;
$photo_h = 22;
$x_photo = $ml;
$y_photo = $pdf->GetY();
$x_info  = $ml + $photo_w;
$w_info  = $uw - $photo_w;

// Photo de l'élève si disponible en base, sinon avatar par défaut selon le
// sexe (masculin/féminin) qui indique visuellement l'emplacement réservé à
// la photo — plus de case grise vide "PHOTO" (demande explicite).
$photo_path  = !empty($eleve['photo']) ? __DIR__ . '/../../../assets/uploads/eleves/' . $eleve['photo'] : '';
$photo_reelle = $photo_path && is_file($photo_path);
if (!$photo_reelle) {
    $avatar_fichier = (strtoupper($eleve['sexe'] ?? '') === 'F') ? 'fille.png' : 'garcon.png';
    $photo_path = __DIR__ . '/../../../assets/img/avatars/' . $avatar_fichier;
}
$pdf->SetDrawColor(0, 0, 0);
$pdf->Rect($x_photo, $y_photo, $photo_w, $photo_h);
if (is_file($photo_path)) {
    $pdf->Image($photo_path, $x_photo + 0.5, $y_photo + 0.5, $photo_w - 1, $photo_h - 1);
}

$gris = [230, 230, 230];
$pdf->SetFillColor(230, 230, 230);
$pdf->SetFont('Arial', 'B', 7);

$wL1 = [24, 34, 18, 14, 18, 60]; // somme = 168 = $w_info
$pdf->SetXY($x_info, $y_photo);
cell2l($pdf, 30, 4.7, 'CLASSE :', 'Class', 'L', true, 8, 7.5, $gris, true);
$pdf->SetFont('Arial', 'I', 8);
$pdf->Cell(45, 4.7, u($insc['classe'] ?? ''), 1, 0, 'L');
cell2l($pdf, $wL1[2], 4.7, 'EFFECTIF :', 'Size', 'L', true, 8, 7.5, $gris, true);
$pdf->SetFont('Arial', 'I', 8);
$pdf->Cell($wL1[3], 4.7, (string)$nb_inscrits, 1, 0, 'C');
cell2l($pdf, 41, 4.7, 'IDENTIFIANT UNIQUE (NIU) :', 'ID No.', 'L', true, 8, 7.5, $gris, true);
$pdf->SetFont('Arial', 'I', 8);

$pdf->Cell(20, 4.7, id_affichage_eleve($eleve), 1, 1, 'C');
$pdf->ln(0.2);
$pdf->SetX($x_info);
//$pdf->SetFont('Arial', 'B', 17);
cell2l($pdf, 30, 4.7, 'NOM ET PRENOMS :', 'ID No.', 'L', true, 8, 7.5, $gris, true);
//	cell2l($pdf, 26, 4.7, 'NOM ET PRENOMS :', 'Name', 'L', true, 28, 7.5, $gris, true);
$pdf->SetFont('Arial', 'BI', 9);
$pdf->Cell(110, 4.7, u(strtoupper($eleve['nom']) . ' ' . ($eleve['prenom'] ?? '')), 1, 0, 'L');
cell2l($pdf, 16, 4.7, 'GENRE :', 'Gender', 'L', true, 8, 7.5, $gris, true);
$pdf->SetFont('Arial', 'I', 8);
$pdf->Cell(12, 4.7, $eleve['sexe'] ?? '', 1, 1, 'C');

$pdf->ln(0.2);
$pdf->SetX($x_info);
cell2l($pdf, 18, 4.7, 'NE(E) LE :', 'Born on', 'L', true, 8, 7.5, $gris, true);
$pdf->SetFont('Arial', 'I', 8);
$dnaiss = $eleve['date_naiss'] ? date('d/m/Y', strtotime($eleve['date_naiss'])) : '';
$pdf->Cell(22, 4.7, $dnaiss, 1, 0, 'C');
$pdf->SetFont('Arial', 'B', 8);
$pdf->Cell(10, 4.7, 'A/at', 1, 0, 'C', true);
$pdf->SetFont('Arial', 'I', 8);
$pdf->Cell(64, 4.7, u($eleve['lieu_naiss'] ?? ''), 1, 0, 'L');
cell2l($pdf, 26, 4.7, 'REDOUBLANT :', 'Repeater', 'L', true, 8, 7.5, $gris, true);
$is_redoub = (strtolower($insc['statut'] ?? '') === 'redoublant');
$y_row3 = $pdf->GetY();
$x_row3 = $pdf->GetX();
$pdf->Cell(28, 4.7, '', 1, 1, 'L');
$pdf->SetFont('Arial', 'I', 8);
$pdf->SetXY($x_row3 + 1, $y_row3 + 1);
$pdf->Cell(7, 3, 'Oui', 0, 0, 'L');
checkbox_ltm($pdf, $x_row3 + 8, $y_row3 + 1, $is_redoub);
$pdf->SetXY($x_row3 + 15, $y_row3 + 1);
$pdf->Cell(7, 3, 'Non', 0, 0, 'L');
checkbox_ltm($pdf, $x_row3 + 23, $y_row3 + 1, !$is_redoub);

//$pdf->ln(20);
$pdf->SetXY($x_info, $y_row3 + 5.0);
//$pdf->ln();
cell2l($pdf, 30, 4.7, 'PROF PRINCIPAL :', 'Class Teacher', 'L', true, 8, 7.5, $gris, true);
$pdf->SetFont('Arial', 'I', 8);
$pdf->Cell(138, 4.7, u($nom_pp), 1, 1, 'L');

$pdf->ln(0.2);
$pdf->SetX($x_info);
cell2l($pdf, 42, 4.7, 'NOM ET CONTACTS DES PARENTS :', "Parent's Name & Contact", 'L', true, 8, 7.5, $gris, true);
$pdf->SetFont('Arial', '', 7);
$pdf->Cell(126, 4.7, u($contacts_parents), 1, 1, 'L');

$pdf->SetY(max($pdf->GetY(), $y_photo + $photo_h));

// ── 4. Tableau des compétences ──────────────────────────────────────
// Reproduit le modèle fourni par l'utilisateur (others/Form 1.pdf pour la
// section anglophone, others/6eme A.pdf pour la section francophone) :
// une ligne d'en-tête "COMPETENCE N: <MATIERE>" (matière = groupe de
// compétence) avec le nom de l'enseignant, suivie d'une ligne par
// compétence de cette matière (texte complet + note brute /20). La
// moyenne, le coefficient, le total pondéré, la cote, l'intervalle
// [Min-Max] de la classe et l'appréciation sont calculés une fois par
// matière et affichés sur toute la hauteur du groupe (cellules fusionnées
// dessinées manuellement, FPDF ne supportant pas le rowspan nativement).
// Remplace l'ancien regroupement ENSEIGNEMENT GÉNÉRAL/PROFESSIONNEL/AUTRES
// (absent du modèle de référence) et les colonnes par séquence.
$pdf->Ln(0.6);
$y_table0 = $pdf->GetY();
$hdr_h = 4.6;

// Espacements compactés (demande explicite, 2e passe : la police des
// compétences reste prioritaire sur l'espacement — resserrer encore plus
// le haut et le bas du bulletin plutôt que de continuer à réduire la
// police).
$h_bas = 5;
$h_ligne = 4;
$h_dec = 2.4;
$decisions = [
    ['Satisfaisant, doit persévérer', 'Satisfactory, must persevere'],
    ['Travail en baisse', 'Work falling'],
    ['Travail insuffisant', 'Insufficient work'],
    ['Attention à la conduite', 'Watch out for behavior'],
    ["Trop d'absences", 'too much absences'],
    ["Risque l'exclusion définitive", 'Risk the definitive exclusion'],
    ['Exclusion', 'Exclusion'],
];
// Revenu à $ph - 13 (une tentative à $ph - 10 privait le bloc signature/date
// (offsets fixes dans la boîte OBSERVATIONS) de sa place habituelle et
// provoquait un débordement sur une 2e page presque vide).
$bas_dispo = $ph - 13; // marge bas + mention copyright

// Largeurs colonnes (somme = $uw = 194mm)
$cD      = 82;  // COMPÉTENCES ÉVALUÉES / NOM DE L'ENSEIGNANT
$cN      = 12;  // N/20 (note brute de chaque compétence)
$cM      = 12;  // M/20 (moyenne de la matière, fusionnée)
$cCF     = 10;  // Coef (fusionnée)
$cMXC    = 12;  // MxC (fusionnée)
$cCOTE   = 10;  // Cote (fusionnée)
$cMINMAX = 20;  // [Min-Max] (fusionnée)
$cAPPR   = $uw - $cD - $cN - $cM - $cCF - $cMXC - $cCOTE - $cMINMAX; // Appréciations et visa

// Nombre de lignes de texte qu'occupera une compétence dans la colonne $cD
// à une taille de police donnée (simulation du retour à la ligne de FPDF).
function comp_wrap_lignes(FPDF $pdf, string $texte, float $largeur, float $taille): int {
    $pdf->SetFont('Arial', '', $taille);
    $mots = preg_split('/\s+/', trim($texte));
    $lignes = 1; $cur = '';
    foreach ($mots as $mot) {
        $essai = $cur === '' ? $mot : $cur . ' ' . $mot;
        if ($pdf->GetStringWidth(u($essai)) > $largeur - 2.4 && $cur !== '') {
            $lignes++; $cur = $mot;
        } else {
            $cur = $essai;
        }
    }
    return max(1, $lignes);
}

// Taille de police + hauteur de ligne des compétences ajustées ENSEMBLE et
// dynamiquement (demande explicite : le bulletin doit tenir sur une seule
// page, quel que soit le nombre de compétences — un élève peut en avoir
// beaucoup plus qu'un autre selon la matière/le trimestre). Réduire la
// police diminue aussi le nombre de lignes nécessaires (texte plus étroit
// par ligne), donc les deux leviers agissent dans le même sens — on réduit
// par petits pas jusqu'à obtenir une hauteur de ligne cohérente avec la
// taille de police (h_unit >= ~0.40 x taille, seuil empirique de lisibilité
// minimale), avec un plancher de police à 6.5pt (= la police de base utilisée
// partout ailleurs dans ce bulletin) — l'espace manquant au-delà
// de ce plancher doit venir de la compression des marges/espacements du
// reste du bulletin (haut/bas de page), pas d'une police illisible.
$footer_fixe = 1 + 6 * $h_ligne + 1 + $h_bas + count($decisions) * $h_dec;
// Marge de sécurité de 6mm : le bloc date/signature (section 7, plus bas)
// a des décalages fixes non capturés par $footer_fixe — sans cette marge,
// une petite imprécision de prédiction du nombre de lignes suffit à faire
// déborder ce bloc sur une 2e page presque vide.
$dispo_table = $bas_dispo - $y_table0 - $hdr_h - $footer_fixe - 8;

// Taille de départ relevée à 7.5pt (demande explicite du 17/09/2026) — repli
// automatique vers un plancher de 7pt (voir plus bas) si une classe a trop
// de compétences pour tenir sur une page à 7.5pt.
$taille_comp = 7.5;
$lignes_par_mat = []; // [id_mat] => [ [id_comp, nb_lignes], ... ]
$total_lignes = 0;
$h_unit = 4;
while (true) {
    $lignes_par_mat = [];
    $total_lignes = 0;
    foreach ($disciplines as $d) {
        $id_mat = (int)$d['id_mat'];
        $lignes_par_mat[$id_mat] = [];
        $comps = $competences_par_mat[$id_mat] ?? [];
        if (empty($comps)) { $total_lignes += 2; continue; } // en-tête + 1 ligne "aucune compétence"
        $total_lignes += 1;
        foreach ($comps as $c) {
            $nl = comp_wrap_lignes($pdf, $c['libelle'], $cD, $taille_comp);
            $lignes_par_mat[$id_mat][] = [(int)$c['id'], $nl];
            $total_lignes += $nl;
        }
    }
    $h_unit = $total_lignes > 0 ? $dispo_table / $total_lignes : 4;
    // Plancher de police à 7pt (demande explicite : rester lisible — la
    // priorité va à la compression des espacements du bulletin plutôt qu'à
    // la réduction de la police des compétences).
    if ($h_unit >= $taille_comp * 0.42 || $taille_comp <= 7.0) break;
    $taille_comp -= 0.25;
}
// Filet de sécurité absolu (ne devrait jamais être atteint si la boucle
// ci-dessus a convergé, mais évite toute hauteur nulle/négative en cas de
// classe extrêmement chargée en compétences).
// Le plancher est volontairement bas (1.6, pas 2.6) : un plancher trop haut
// ignorerait le budget réellement disponible et ferait déborder le bloc
// date/signature (section 7) sur une page presque vide — mieux vaut que
// $h_unit suive fidèlement $dispo_table (garantissant une seule page) que
// de forcer une hauteur de ligne "confortable" hors budget.
$h_unit = max(1.6, min(4.6, $h_unit));
$pdf->SetFont('Arial', '', 6.5);

// En-tête tableau
$pdf->SetFont('Arial', 'B', 6.3);
pdf_fill($pdf, 'entete_tableau_individuel');
$pdf->SetTextColor(255, 255, 255);
$pdf->SetX($ml);
$pdf->Cell($cD, $hdr_h, u("COMPÉTENCES ÉVALUÉES / NOM DE L'ENSEIGNANT"), 1, 0, 'C', true);
$pdf->Cell($cN,      $hdr_h, 'N/20',   1, 0, 'C', true);
$pdf->Cell($cM,      $hdr_h, 'M/20',   1, 0, 'C', true);
$pdf->Cell($cCF,     $hdr_h, 'Coef',   1, 0, 'C', true);
$pdf->Cell($cMXC,    $hdr_h, 'MxC',    1, 0, 'C', true);
$pdf->Cell($cCOTE,   $hdr_h, 'Cote',   1, 0, 'C', true);
$pdf->Cell($cMINMAX, $hdr_h, u('[Min-Max]'), 1, 0, 'C', true);
$pdf->Cell($cAPPR,   $hdr_h, u("Appréciations et visa de l'enseignant"), 1, 1, 'C', true);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFillColor(255, 255, 255);

$num_mat = 0;
foreach ($disciplines as $d) {
    $num_mat++;
    $id_mat = (int)$d['id_mat'];
    $comps  = $competences_par_mat[$id_mat] ?? [];
    $avg    = mat_avg_comp($id_eleve, $comps, $notes_idx, $notes_count, $nb_inscrits);
    $stat   = $mat_stats[$id_mat] ?? ['min' => null, 'avg' => null, 'max' => null];
    $nxc    = ($avg !== null) ? $avg * $d['coef'] : null;
    $appr   = appreciation($avg);

    $bg = ($avg !== null && $avg < 10) ? couleur_pdf('ligne_echec') : [255, 255, 255];

    // Ligne d'en-tête de la matière : "COMPETENCE N: MATIERE" + enseignant,
    // en pleine largeur, fond lavande (même convention que le reste du
    // bulletin — cell2l()).
    $y_hdr = $pdf->GetY();
    $pdf->SetXY($ml, $y_hdr);
    pdf_fill($pdf, 'bandeau_section');
    [$t_mat, $lib_mat] = fpdf_texte_ajuste($pdf, 'COMPETENCE ' . $num_mat . ': ' . mb_strtoupper($d['matiere']), $cD + $cN, 7.5, 5.5);
    $pdf->SetFont('Arial', 'B', $t_mat);
    $pdf->Cell($cD + $cN, $h_unit, u($lib_mat), 1, 0, 'L', true);
    [$t_ens, $lib_ens] = fpdf_texte_ajuste($pdf, $d['enseignant'] ?? '', $uw - $cD - $cN, 7, 5);
    $pdf->SetFont('Arial', '', $t_ens);
    $pdf->Cell($uw - $cD - $cN, $h_unit, u($lib_ens), 1, 1, 'R', true);
    $pdf->SetFont('Arial', '', $taille_comp);

    $y_grp0 = $pdf->GetY();

    if (empty($comps)) {
        $pdf->SetX($ml);
        $pdf->SetFillColor(...$bg);
        $pdf->Cell($cD, $h_unit, u('Aucune compétence définie pour ce trimestre.'), 1, 0, 'L', true);
        $pdf->Cell($cN, $h_unit, '', 1, 1, 'C', true);
        $h_grp = $h_unit;
    } else {
        $h_grp = 0;
        foreach ($lignes_par_mat[$id_mat] as [$id_comp, $nb_lignes]) {
            $comp = null;
            foreach ($comps as $c) { if ((int)$c['id'] === $id_comp) { $comp = $c; break; } }
            $h_row = $nb_lignes * $h_unit; // budget MAX pour cette compétence

            $x0 = $ml; $y0 = $pdf->GetY();
            $pdf->SetFillColor(...$bg);
            $pdf->SetFont('Arial', '', $taille_comp);
            if ($nb_lignes > 1) {
                // Compétence sur 2+ lignes : interligne resserré (proportionnel
                // à la police, plafonné à $h_unit pour ne jamais dépasser le
                // budget) au lieu de $h_row/$nb_lignes — sinon chaque ligne
                // hérite de la part généreuse d'une case pleine ligne et le
                // texte paraît anormalement aéré. La hauteur RÉELLE de la case
                // ($h_row_reel) suit ce resserrement — sinon le cadre garde
                // l'ancienne hauteur budgétée et un espace vide apparaît sous
                // le texte resserré. Demande explicite du 16/09/2026.
                $h_texte    = min($h_unit, max(1.6, $taille_comp * 0.42));
                $h_row_reel = $nb_lignes * $h_texte;
                $pdf->Rect($x0, $y0, $cD, $h_row_reel, 'DF');
                $pdf->SetXY($x0, $y0);
                $pdf->MultiCell($cD, $h_texte, u($comp['libelle'] ?? ''), 0, 'L', false);
            } else {
                $h_row_reel = $h_row;
                $pdf->MultiCell($cD, $h_row, u($comp['libelle'] ?? ''), 1, 'L', true);
            }
            $h_grp += $h_row_reel;
            $pdf->SetXY($x0 + $cD, $y0);

            $v = note_eff_comp($id_eleve, $id_comp, $notes_idx, $notes_count, $nb_inscrits);
            $pdf->SetFont('Arial', 'B', 7.5);
            if ($v !== null) { if ($v < 10) $pdf->SetTextColor(180, 0, 0); else $pdf->SetTextColor(0, 100, 0); }
            $pdf->Cell($cN, $h_row_reel, $v !== null ? fmt_note_b($v) : '', 1, 0, 'C', true);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetXY($x0, $y0 + $h_row_reel);
        }
    }

    // Bloc fusionné (M/20, Coef, MxC, Cote, [Min-Max], Appréciation) sur
    // toute la hauteur du groupe de compétences (positionnement manuel —
    // FPDF n'a pas de rowspan natif). Une bordure PAR colonne (demande
    // explicite, comme sur le modèle de référence) plutôt qu'un seul cadre
    // englobant les 6 colonnes.
    $x_fus = $ml + $cD + $cN;
    $pdf->SetFillColor(...$bg);
    $x_col = $x_fus;
    foreach ([$cM, $cCF, $cMXC, $cCOTE, $cMINMAX, $cAPPR] as $c_larg) {
        $pdf->Rect($x_col, $y_grp0, $c_larg, $h_grp, 'DF');
        $x_col += $c_larg;
    }

    $centrer_y = fn(float $h_texte) => $y_grp0 + max(0, ($h_grp - $h_texte) / 2);

    $pdf->SetFont('Arial', 'B', 8);
    if ($avg !== null) { if ($avg >= 10) $pdf->SetTextColor(0, 100, 0); else $pdf->SetTextColor(180, 0, 0); }
    $pdf->SetXY($x_fus, $centrer_y(4));
    $pdf->Cell($cM, 4, $avg !== null ? fmt_note_b($avg) : '', 0, 0, 'C');
    $pdf->SetTextColor(0, 0, 0);

    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->SetXY($x_fus + $cM, $centrer_y(4));
    $pdf->Cell($cCF, 4, $avg !== null ? (string)$d['coef'] : '', 0, 0, 'C');

    $pdf->SetXY($x_fus + $cM + $cCF, $centrer_y(4));
    $pdf->Cell($cMXC, 4, $nxc !== null ? fmt_note_b($nxc) : '', 0, 0, 'C');

    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetXY($x_fus + $cM + $cCF + $cMXC, $centrer_y(4));
    $pdf->Cell($cCOTE, 4, u($appr['COTE']), 0, 0, 'C');

    $pdf->SetFont('Arial', '', 6.5);
    $minmax = ($stat['min'] !== null) ? fmt_note_b($stat['min']) . ' - ' . fmt_note_b($stat['max']) : '';
    $pdf->SetXY($x_fus + $cM + $cCF + $cMXC + $cCOTE, $centrer_y(4));
    $pdf->Cell($cMINMAX, 4, $minmax, 0, 0, 'C');

    // Appréciation bilingue : texte complet si la place le permet (>= 5mm de
    // hauteur), sinon abrégé (CTBA/CVWA...) sur une seule ligne auto-réduite,
    // pour ne jamais déborder d'un groupe à une seule compétence courte.
    $x_appr = $x_fus + $cM + $cCF + $cMXC + $cCOTE + $cMINMAX;
    if ($appr['COTE'] !== '') {
        if ($h_grp >= 5) {
            $h2 = min(3.4, $h_grp / 2);
            $y_txt = $centrer_y($h2 * 2);
            $t_fr = cell2l_taille_ajustee($pdf, $appr['APPR1_FR'], 'B', $cAPPR - 2, 6, 4.2);
            $pdf->SetFont('Arial', 'B', $t_fr);
            $pdf->SetXY($x_appr, $y_txt);
            $pdf->Cell($cAPPR, $h2, u($appr['APPR1_FR']), 0, 0, 'C');
            $t_en = cell2l_taille_ajustee($pdf, $appr['APPR1_EN'], 'I', $cAPPR - 2, 5.2, 3.8);
            $pdf->SetFont('Arial', 'I', $t_en);
            $pdf->SetXY($x_appr, $y_txt + $h2);
            $pdf->Cell($cAPPR, $h2, u($appr['APPR1_EN']), 0, 0, 'C');
        } else {
            $abrege = $appr['APPR2_FR'] . ' / ' . $appr['APPR2_EN'];
            $t_ab = cell2l_taille_ajustee($pdf, $abrege, 'B', $cAPPR - 2, 5.5, 3.5);
            $pdf->SetFont('Arial', 'B', $t_ab);
            $pdf->SetXY($x_appr, $centrer_y(3));
            $pdf->Cell($cAPPR, 3, u($abrege), 0, 0, 'C');
        }
    }

    $pdf->SetFillColor(255, 255, 255);
    $pdf->SetFont('Arial', '', 6.5);
    $pdf->SetXY($ml, $y_grp0 + $h_grp);
}

// ── 5. Section bas : reproduit le modèle fourni par l'utilisateur ──
// (bandeaux DISCIPLINES | TRAVAIL | PROFIL DE LA CLASSE | RESULTATS DE
// L'ELEVE, chaque libellé bilingue FR (gras) / EN (italique) sur 2 lignes,
// via cell2l() défini plus haut.)
$pdf->Ln(1);
$y_bas    = $pdf->GetY();
// TRAVAIL agrandi (demande explicite : "Tableau d'honneur" trop à l'étroit)
// au détriment de PROFIL DE LA CLASSE, dont les libellés tiennent déjà
// largement à taille max avec moins de place (vérifié).
$wB_left  = 70;
$wB_mid   = 54;
$wB_right = $uw - $wB_left - $wB_mid; // 70, inchangé
// $h_bas déjà déclaré avant le tableau (section 4), pour le calcul de $row_h.

// Heures d'absence RÉELLES du trimestre, lues dans la table `absence`
// (fonctions.php::eleve_absence_trimestre(), alimentée par
// secondaire/pages/discipline/index.php).
$abs_reelle = eleve_absence_trimestre($eleve['matricule'] ?? '', $id_trim, $insc['id_classe'], $val_annee);
$heur_jus   = (int) $abs_reelle['jus'];
$heur_nj    = (int) $abs_reelle['non_jus'];

// Seuils des mentions automatiques : configurables par l'administrateur
// (secondaire/pages/parametres/index.php, onglet "Mentions") au lieu d'être codés en dur.
$reglage_mention = get_reglage_mention_bulletin($id_annee);
$tab_honneur = bulletin_tableau_honneur($moy_generale ?? 0, $heur_nj, $reglage_mention) === 'Oui';
$encourag    = bulletin_encouragement($moy_generale ?? 0, $heur_nj, $reglage_mention) === 'Oui';
$felicit     = bulletin_felicitation($moy_generale ?? 0, $heur_nj, $reglage_mention) === 'Oui';
$avert_cond  = bulletin_avert_conduite($heur_nj, $reglage_mention) === 'OUI';
$avert_trav  = bulletin_avert_travail($moy_generale ?? 0, $reglage_mention) === 'Oui';
$blame_cond  = bulletin_blame_conduite($heur_nj, $reglage_mention) === 'OUI';
$blame_trav  = bulletin_blame_travail($moy_generale ?? 0, $reglage_mention) === 'Oui';

// ── En-têtes des 4 bandeaux (fond lavande, FR/EN) ──────────────────
// Hauteur harmonisée avec toutes les lignes de cette section, y compris
// Rappel/Eval et MOYENNE (demande explicite : plus de bandeau plus haut que
// le reste) — même hauteur $h_ligne, même plafond de police (9/7) que les
// libellés DISCIPLINES/TRAVAIL/PROFIL juste en dessous.
$pdf->SetXY($ml, $y_bas);
$w_dis = 36; $w_trav = $wB_left - $w_dis; // 36 / 34
cell2l($pdf, $w_dis,  $h_ligne, 'DISCIPLINES', 'Disciplines', 'C', true, 9, 7);
cell2l($pdf, $w_trav, $h_ligne, 'TRAVAIL',     'Work',        'C', true, 9, 7);
cell2l($pdf, $wB_mid,   $h_ligne, 'PROFIL DE LA CLASSE', 'Class profile',   'C', true, 9, 7);
cell2l($pdf, $wB_right, $h_ligne, "RESULTATS DE L'ELEVE", 'Student results', 'C', true, 9, 7);
$pdf->Ln($h_ligne);

// ── Ligne par ligne : DISCIPLINES | TRAVAIL | PROFIL DE LA CLASSE ──
$w_dis_lbl = 26; $w_dis_val = $w_dis - $w_dis_lbl;   // 26 / 10
$w_tr_chk  = 6;  $w_tr_lbl  = $w_trav - $w_tr_chk;   // 6 / 28
// Colonne valeur réduite (demande explicite : trop large pour des nombres
// courts comme "14.32" ou "45%", vérifié) au profit du libellé.
$w_pr_lbl  = round($wB_mid * 0.72); $w_pr_val = $wB_mid - $w_pr_lbl; // ~39 / 15

$left_rows = [
    ['Absences Jus.', 'Justified Abs.', (string)$heur_jus, "Tableau d'honneur", 'Roll of honor', $tab_honneur, null, true],
    ['Absences NJ.',  'Unjustified Abs', (string)$heur_nj,  'Encouragement',     'Encouragement', $encourag,    null, true],
    ['Exclusion(jrs)', 'Exclu.', '---',                       'Félicitations',     'Congratulation', $felicit, null, false],
    ['Avert. conduite', 'Warning Behavior', null,             'Avert. Travail',   'Worning Work',   $avert_trav, $avert_cond, false],
    ['Blâme conduite',  'Blame Behavior',   null,             'Blâme Travail',    'Blame Behavior',  $blame_trav, $blame_cond, false],
];
$prof_rows = [
    ['Moy. de la classe', 'Class Average',     $moy_classe  !== null ? fmt_note_b($moy_classe)  : '-'],
    ['Moy. du premier',   'Average of First',  $moy_premier !== null ? fmt_note_b($moy_premier) : '-'],
    ['Moy. du dernier',   'Average of Last',   $moy_dernier !== null ? fmt_note_b($moy_dernier) : '-'],
    ['Effectif Classé',   'Classified Enroll.', (string)$nb_classes],
    ['Taux de réussite',  'Success Rate',      $taux_reussite . '%'],
];
// Taille des libellés DISCIPLINES/TRAVAIL/PROFIL agrandie (demande
// explicite), auto-ajustée par cell2l() donc jamais en débordement.
foreach ($left_rows as $i => $lr) {
    $y0r = $pdf->GetY();
    $pdf->SetXY($ml, $y0r);
    cell2l($pdf, $w_dis_lbl, $h_ligne, $lr[0], $lr[1], 'L', false, 9, 7);
    if ($lr[2] === null) {
        $pdf->Cell($w_dis_val, $h_ligne, '', 1, 0, 'C');
        checkbox_ltm($pdf, $ml + $w_dis_lbl + $w_dis_val / 2 - 1.25, $y0r + $h_ligne / 2 - 1.25, $lr[6] ?? false, 2.5);
    } else {
        // Nombre d'heures d'absence : en gras, taille agrandie (demande
        // explicite — c'est une donnée clé du bulletin).
        $pdf->SetFont('Arial', $lr[7] ? 'B' : '', $lr[7] ? 8.5 : 7.5);
        $pdf->Cell($w_dis_val, $h_ligne, $lr[2], 1, 0, 'C');
        $pdf->SetFont('Arial', '', 6.5);
    }
    checkbox_ltm($pdf, $pdf->GetX() + 1.5, $y0r + $h_ligne / 2 - 1.25, $lr[5], 2.5);
    $pdf->SetX($pdf->GetX() + $w_tr_chk);
    cell2l($pdf, $w_tr_lbl, $h_ligne, $lr[3], $lr[4], 'L', false, 9, 7);

    $pr = $prof_rows[$i];
    $pdf->SetXY($ml + $wB_left, $y0r);
    cell2l($pdf, $w_pr_lbl, $h_ligne, $pr[0], $pr[1], 'L', false, 9, 7);
    // Moyennes du profil de classe : en gras, taille agrandie mais auto-
    // ajustée (colonne réduite, demande explicite : ne doit jamais déborder).
    $taille_pr = cell2l_taille_ajustee($pdf, $pr[2], 'B', $w_pr_val - 2.4, 8.5, 6);
    $pdf->SetFont('Arial', 'B', $taille_pr);
    $pdf->Cell($w_pr_val, $h_ligne, $pr[2], 1, 0, 'C');
    $pdf->SetFont('Arial', '', 6.5);
    $pdf->Ln($h_ligne);
}

// ── RESULTATS DE L'ELEVE : GENERAL TOTAL / COEFFICIENT / MOYENNE / RANG /
//    APPRECIATION ─────────────────────────────────────────────────────
// Reproduit le bloc "STUDENT'S RESULTS" du modèle de référence — plus de
// tableau "Rappel Eval1/Eval2" (sans équivalent en compétences, voir §4),
// le bloc occupe donc toute la largeur $wB_right.
$xR = $ml + $wB_left + $wB_mid;
$y_res0 = $y_bas + $h_ligne;
$total_general = ($moy_generale !== null && $total_coef_eleve > 0) ? $moy_generale * $total_coef_eleve : null;
$moy_str     = $moy_generale !== null ? fmt_note_b($moy_generale) . ' / 20' : '- / 20';
$rang_str    = $rang_general !== null ? $rang_general . 'e / ' . $nb_classes : '-';
$mention_str = $moy_generale !== null ? mention_gen_b($moy_generale) : '';

$res_rows2 = [
    ['GENERAL TOTAL', 'Total général', $total_general !== null ? fmt_note_b($total_general) : '-', false],
    ['COEFFICIENT',   'Coefficient',   $total_coef_eleve > 0 ? (string)$total_coef_eleve : '-', false],
];
$pdf->SetXY($xR, $y_res0);
foreach ($res_rows2 as $rr) {
    cell2l($pdf, $wB_right * 0.55, $h_ligne, $rr[0], $rr[1], 'L', false, 9, 7);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell($wB_right * 0.45, $h_ligne, u($rr[2]), 1, 1, 'C');
    $pdf->SetFont('Arial', '', 6.5);
    $pdf->SetX($xR);
}
cell2l($pdf, $wB_right * 0.55, $h_ligne, 'MOYENNE', 'Average', 'L', false, 9, 7);
$taille_moy = cell2l_taille_ajustee($pdf, $moy_str, 'B', $wB_right * 0.45 - 2.4, 11, 7);
$pdf->SetFont('Arial', 'B', $taille_moy);
$pdf->SetTextColor(0, 130, 0);
$pdf->Cell($wB_right * 0.45, $h_ligne, u($moy_str), 1, 1, 'C');
$pdf->SetTextColor(0, 0, 0);
$pdf->SetX($xR);
cell2l($pdf, $wB_right * 0.55, $h_ligne, 'RANG', 'Rank', 'L', false, 9, 7);
$taille_rang = cell2l_taille_ajustee($pdf, $rang_str, 'B', $wB_right * 0.45 - 2.4, 10, 6);
cell_rang($pdf, $wB_right * 0.45, $h_ligne, $rang_general, ' / ' . $nb_classes, 1, 'C', $taille_rang);
$pdf->Ln($h_ligne);
$pdf->SetX($xR);
cell2l($pdf, $wB_right * 0.55, $h_ligne, 'APPRECIATION', 'Appreciation', 'L', false, 9, 7);
$pdf->SetFont('Arial', 'B', 8.5);
$pdf->SetTextColor(0, 130, 0);
$pdf->Cell($wB_right * 0.45, $h_ligne, u($mention_str), 1, 1, 'C');
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('Arial', '', 6.5);

$pdf->SetY(max($y_res0 + 4 * $h_ligne, $y_bas + 6 * $h_ligne));

// ── 6. Décision du conseil ────────────────────────────────────────
$pdf->Ln(1);
$y_dec = $pdf->GetY();
$w_dec = $uw * 0.55; $w_obs = $uw - $w_dec;
$pdf->SetXY($ml, $y_dec);
// Police agrandie (demande explicite : c'est la partie la plus importante du bulletin).
cell2l($pdf, $w_dec, $h_bas, 'DECISION DU CONSEIL DE CLASSE ET DE DISCIPLINE', 'Decision of the class advice and discipline', 'C', true, 10.5, 8.5);
cell2l($pdf, $w_obs, $h_bas, "OBSERVATIONS DU CHEF D'ETABLISSEMENT", "Principal's remarks", 'C', true, 10.5, 8.5);
$pdf->Ln($h_bas);

// $decisions, $h_dec et $bas_dispo déjà déclarés avant le tableau (section 4).
$x_dec = $ml; $x_obs = $ml + $w_dec; $y_dec2 = $pdf->GetY();
// Le cadre OBSERVATIONS occupe tout l'espace restant jusqu'au bas de page
// (proportionnel à ce qu'il reste, pas une hauteur fixe). Le QR code de
// vérification est désormais dessiné À L'INTÉRIEUR de ce même cadre (voir
// plus bas, section 8), donc plus besoin de réserver de place en dessous.
// Ne descend jamais sous la hauteur de la liste DECISION.
$h_obs_box = max(count($decisions) * $h_dec, $bas_dispo - $y_dec2);
foreach ($decisions as $i => $dec) {
    $y_row = $y_dec2 + $i * $h_dec;
    // Cases cochées automatiquement sur des règles définies :
    // 0 = moyenne >= 10 (admis) ; 2 = moyenne < 8 (travail insuffisant) ;
    // 3 = avertissement ou blâme de conduite déclenché (voir $avert_cond/$blame_cond).
    // Les autres (absences, exclusion) nécessitent une saisie manuelle du conseil.
    $checked = ($i === 0 && $moy_generale !== null && $moy_generale >= 10)
            || ($i === 2 && $moy_generale !== null && $moy_generale < 8)
            || ($i === 3 && ($avert_cond || $blame_cond));
    // Case à cocher réduite au minimum lisible (demande explicite).
    checkbox_ltm($pdf, $x_dec + 1.2, $y_row + $h_dec / 2 - 1, $checked, 2);
    $pdf->SetXY($x_dec + 5.5, $y_row);
    $pdf->SetFont('Arial', 'B', 7.5);
    $txt_fr = u($dec[0] . ' / ');
    $w_fr   = $pdf->GetStringWidth($txt_fr);
    $pdf->Cell($w_fr, $h_dec, $txt_fr, 0, 0, 'L');
    // Texte anglais agrandi pour la lisibilité (demande explicite) — même
    // taille que le français (7.5), seul le style (italique) les distingue.
    $pdf->SetFont('Arial', 'I', 7.5);
    $pdf->Cell($w_dec - 7.5 - $w_fr, $h_dec, u($dec[1]), 0, 1, 'L');
    $pdf->Rect($x_dec, $y_row, $w_dec, $h_dec); // bordure complète de la ligne
}
// Dernière cellule (vide) qui complète la liste DECISION jusqu'au bas du
// cadre OBSERVATIONS d'en face, pour que les deux colonnes finissent à la
// même hauteur — c'est DANS cette dernière cellule, aligné à droite, qu'est
// dessiné le QR code de vérification (demande explicite), et non plus dans
// le cadre OBSERVATIONS.
$y_fill   = $y_dec2 + count($decisions) * $h_dec;
$h_rempli = $h_obs_box - count($decisions) * $h_dec;
if ($h_rempli > 0.01) {
    $pdf->Rect($x_dec, $y_fill, $w_dec, $h_rempli);
}

// QR code de vérification d'authenticité — dans la dernière cellule de
// DECISION, aligné à droite, avec la photo de l'élève incrustée au centre
// (le niveau de correction d'erreur qr-h tolère ~30% de perte, une photo
// centrée sur ~20% de la surface reste donc scannable). Plus de légende
// "Scannez..." sous le QR (supprimée, demande explicite).
require_once __DIR__ . '/../../pdf/verif_lib.php';
require_once __DIR__ . '/../../pdf/qrcode.php';

if ($h_rempli > 15) {
    $verif_url = bulletin_verif_url(
        $id_eleve,
        $is_seq ? 'seq' : 'trim',
        $is_seq ? $id_seq_u : $id_trim,
        id_affichage_eleve($eleve)
    );

    $qr_tmp = tempnam(sys_get_temp_dir(), 'abzqr_') . '.png';
    try {
        $qr_gen = new QRCode($verif_url, ['s' => 'qr-h']); // qr-h = correction d'erreur élevée (impression papier)
        $qr_img = $qr_gen->render_image();
        qr_incruster_photo($qr_img, $photo_path ?: '');
        imagepng($qr_img, $qr_tmp);
        imagedestroy($qr_img);

        $qr_size = min(26, $w_dec - 6, $h_rempli - 4);
        $qr_x    = $x_dec + $w_dec - $qr_size - 3; // aligné à droite
        // Aligné en bas, à 2mm de la ligne finale du tableau (demande
        // explicite) — plus haut ancré en haut de la cellule restante.
        $qr_y    = $y_fill + $h_rempli - $qr_size - 2;
        $pdf->Image($qr_tmp, $qr_x, $qr_y, $qr_size, $qr_size, 'PNG');
    } finally {
        if (is_file($qr_tmp)) unlink($qr_tmp);
    }
}

$pdf->SetFont('Arial', '', 6);
$pdf->SetXY($x_obs, $y_dec2);
$pdf->MultiCell($w_obs, $h_obs_box, '', 1, 'L');

// ── 7. Date et signature (à l'intérieur du cadre Observations) ────
// Remonté et centré (demande explicite), au lieu d'être bas et aligné à droite.
// Décalage fixe (pas proportionnel à $h_obs_box, qui peut désormais être
// grand) pour que le bloc reste bien en haut du cadre quelle que soit sa
// hauteur réelle. Police agrandie (demande explicite).
$pdf->SetFont('Arial', '', 8.5);
$pdf->SetXY($x_obs, $y_dec2 + 6);
$pdf->Cell($w_obs, 5.5, u('Mbé, le ' . date('d-m-Y') . '.'), 0, 1, 'C');
$pdf->SetX($x_obs);
$pdf->SetFont('Arial', 'I', 7.5);
$pdf->Cell($w_obs, 4.5, 'On', 0, 1, 'C');
$pdf->Ln(2.5);
$pdf->SetX($x_obs);
$pdf->SetFont('Arial', 'B', 8.5);
$pdf->Cell($w_obs, 5.5, u(strtoupper($etab['chef_etablissement'] ?? 'LE PROVISEUR') . ','), 0, 1, 'C');
$pdf->SetX($x_obs);
$pdf->SetFont('Arial', 'I', 7.5);
$pdf->Cell($w_obs, 4.5, u($etab['chef_etablissement_en'] ?? 'The Principal'), 0, 1, 'C');

// Signature numérique (uniquement si demandée à l'impression — jamais
// automatique — et si l'admin en a configuré une dans les paramètres).
if (($_GET['signature'] ?? '0') === '1') {
    $sig_w = 22;
    $sx = $x_obs + ($w_obs - $sig_w) / 2;
    $sy = $pdf->GetY() + 0.5;
    pdf_signature_appliquer($pdf, 'bulletin_trimestriel', 'chef_etablissement', 0, 0, $pw, $ph, [
        'x_pct' => $sx / $pw * 100, 'y_pct' => $sy / $ph * 100, 'w_pct' => $sig_w / $pw * 100, 'h_pct' => null,
    ]);
}

$pdf->SetY($y_dec2 + $h_obs_box);

// Mention copyright en bas de page, centrée, à l'intérieur du cadre extérieur
// (demande explicite).
$pdf->SetFont('Arial', '', 6.5);
$pdf->SetXY($ml, $ph - 9);
$pdf->Cell($uw, 4, u('Copyright © SIGES ABZ   , E-mail: abdoulazizyahya@gmail.com'), 0, 1, 'C');

// ── Sortie ────────────────────────────────────────────────────────
$mode     = ($_GET['dl'] ?? '') === '1' ? 'D' : 'I';
$filename = 'bulletin_' . ($eleve['matricule'] ?? $id_eleve) . '_' . preg_replace('/\W+/', '_', $titre_bull) . '.pdf';
$pdf->Output($mode, $filename);
