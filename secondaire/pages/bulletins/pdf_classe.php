<?php
/**
 * PDF de tous les bulletins d'une classe (un élève par page)
 * GET : classe, trim|seq, annee, ordre (alpha|merite), dl (0|1)
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

$role     = role_connecte();
$is_admin = in_array($role, ['ADMIN', 'PROVISEUR', 'CENSEUR']);
$is_ens   = ($role === 'ENSEIGNANT');
$mat_ens  = $is_ens ? get_matricule_ens_connecte() : null;

if (!$is_admin && !$is_ens) die('Acces non autorise.');

$id_classe = (int)($_GET['classe'] ?? 0);
$id_trim   = (int)($_GET['trim']   ?? 0);
$id_seq    = (int)($_GET['seq']    ?? 0);
$id_annee  = (int)($_GET['annee']  ?? 0);
$ordre     = in_array($_GET['ordre'] ?? '', ['alpha', 'merite']) ? $_GET['ordre'] : 'alpha';
$dl        = ($_GET['dl'] ?? '0') === '1';

if (!$id_classe) die('Classe manquante.');
if (!$id_trim && !$id_seq) die('Periode manquante.');

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

// Élèves de la classe
$eleves = db_all(
    "SELECT e.id, e.nom, e.prenom FROM eleve e
     JOIN inscription i ON i.id_eleve=e.id AND i.id_classe=? AND i.id_annee=?
     WHERE e.statut='actif' ORDER BY e.nom, e.prenom",
    [$id_classe, $id_annee]
);
if (empty($eleves)) die('Aucun eleve dans cette classe.');

// ── Trimestre à afficher ────────────────────────────────────────────
// Chantier APC (voir prompt_continuite_ABZ_MBE_1.md) : le bulletin de classe
// est désormais toujours scopé au trimestre entier, les compétences n'ayant
// pas d'équivalent "séquence unique". Le paramètre ?seq= n'est conservé que
// pour résoudre le trimestre depuis un ancien lien (compatibilité) et pour
// construire l'URL de vérification QR historique — identique à
// secondaire/pages/bulletins/pdf.php (même logique, voir ce fichier pour le jumeau).
$is_seq = ($id_seq > 0);
if ($is_seq) {
    $id_trim = (int) db_val("SELECT id_trim FROM sequence WHERE id=?", [$id_seq]) ?: $id_trim;
}
$trim_info  = db_one("SELECT * FROM trimestre WHERE id=?", [$id_trim]);
if (!$trim_info) die('Trimestre introuvable.');
$titre_bull = strtoupper($trim_info['libelle'] ?? '');

require_once __DIR__ . '../../pdf/fpdf.php';
require_once __DIR__ . '../../pdf/header_pdf.php'; // pour pdf_filigrane()

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

// Données communes de la classe — chantier APC (voir prompt_continuite) :
// pv_charger_donnees_comp() (fonctions.php) factorise le chargement des
// compétences/notes du trimestre, déjà utilisé par conseil_classe/statistiques
// (Phase 6). Elle ne renvoie que id_mat/coef pour les disciplines, insuffisant
// pour l'affichage classe par classe (libellé matière, enseignant) : requête
// séparée ci-dessous pour ces champs d'affichage.
$comp_data           = pv_charger_donnees_comp($id_classe, $id_trim, $id_annee);
$competences_par_mat = $comp_data['competences_par_mat'];
$notes_idx           = $comp_data['notes_idx'];
$notes_count         = $comp_data['notes_count'];
$mats_avec_notes     = $comp_data['mats_avec_notes'];
$nb_man              = $comp_data['nb_mats_avec_notes'];
$nb_inscrits         = $comp_data['nb_inscrits'];
$annules             = $comp_data['annules']; // Règle 4 — annulation de trimestre

$disciplines = db_all(
    "SELECT d.id_mat, d.coef, d.ordre, m.libelle AS matiere,
            TRIM(CONCAT(e.nom_ens,' ',COALESCE(e.prenom_ens,''))) AS enseignant
     FROM discipline d JOIN matiere m ON m.id=d.id_mat AND m.actif=1
     LEFT JOIN dispenser disp ON disp.id_mat=d.id_mat AND disp.IDClasses=d.IDClasses AND disp.val_annee=?
     LEFT JOIN enseignant e ON e.matricule_ens=disp.matricule_ens
     WHERE d.IDClasses=? ORDER BY d.ordre, m.libelle",
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

// Moyennes classe
$all_moys = [];
foreach ($enrolled_ids as $eid) {
    [$m, $ok] = pv_moy_generale_comp($eid, $disciplines, $competences_par_mat, $notes_idx, $notes_count, $nb_inscrits, $mats_avec_notes, $nb_man, $annules);
    if ($ok && $m !== null) $all_moys[$eid] = $m;
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

// Stats par matière
$mat_stats = [];
foreach ($disciplines as $d) {
    $avgs = [];
    foreach ($enrolled_ids as $eid) {
        $avg = pv_moy_matiere_comp($eid, $competences_par_mat[$d['id_mat']] ?? [], $notes_idx, $notes_count, $nb_inscrits);
        if ($avg !== null) $avgs[] = $avg;
    }
    rsort($avgs);
    $mat_stats[$d['id_mat']] = empty($avgs)
        ? ['min' => null, 'avg' => null, 'max' => null, 'avgs_sorted' => []]
        : ['min' => min($avgs), 'avg' => array_sum($avgs) / count($avgs), 'max' => max($avgs), 'avgs_sorted' => $avgs];
}

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
$mention_gen = fn(?float $a): string => $a === null ? '' : ($a >= 16 ? 'Excellent' : ($a >= 14 ? 'Tres bien' : ($a >= 12 ? 'Bien' : ($a >= 10 ? 'Assez bien' : ($a >= 8 ? 'Passable' : 'Insuffisant')))));
$rang_eleve  = function (float $avg, array $sorted): int { $r = 1; foreach ($sorted as $a) { if ($a > $avg) $r++; else break; } return $r; };

// Constantes mise en page — un seul trimestre, pas de sous-colonnes par
// séquence (chantier APC, voir secondaire/pages/bulletins/pdf.php pour le jumeau) :
// toujours la disposition à colonne unique ("cNOT"), plus de branche
// séquences multiples.
$ml = 8; $mr = 8;
$h_bas = 7;
// cMEN réduite au profit de cENS (demande explicite) — vérifié : tient toujours.
$cD   = 52; $cCF = 9; $cNXC = 13; $cRG = 9; $cMEN = 22; $cMIN = 9; $cMOY = 9; $cMAX = 9;
$cNOT = 15;
$cENS = 194 - $cD - $cNOT - $cCF - $cNXC - $cRG - $cMEN - $cMIN - $cMOY - $cMAX; // 43

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

    // Calculs élève
    [$moy_gen, $est_classe, $total_coef_eleve] = pv_moy_generale_comp($id_eleve, $disciplines, $competences_par_mat, $notes_idx, $notes_count, $nb_inscrits, $mats_avec_notes, $nb_man);
    $rang_gen = null;
    if ($est_classe && $moy_gen !== null) {
        $rang_gen = 1;
        foreach ($all_moys as $m) { if ($m > $moy_gen) $rang_gen++; else break; }
    }

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
        $pdf->Image($logo_path, $logo_x, $logo_y+5, $logo_w);
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

    $pdf->SetY(max($pdf->GetY(), 33));
    $pdf->SetFont('Arial', 'I', 7);
    $pdf->SetX($ml);
    $pdf->Cell($uw, 4, uc('IMMATRICULATION : ' . ($etab['immatriculation'] ?? '')), 0, 1, 'C');

    // Titre en bandeau pilule — identique à secondaire/pages/bulletins/pdf.php (était un
    // simple rectangle sans remplissage, autre différence de forme corrigée).
    $y_titre = $pdf->GetY();
    $h_titre = 8;
    $pdf->SetFillColor(219, 228, 245);
    $pdf->SetDrawColor(26, 60, 107);
    $pdf->SetLineWidth(0.3);
    $pdf->RoundedRect($ml, $y_titre, $uw, $h_titre, $h_titre / 2, 'FD');
    $pdf->SetXY($ml, $y_titre-1);
    $pdf->SetFont('Arial', 'BI', 12);
    $pdf->SetTextColor(26, 60, 107);
    $pdf->Cell($uw, $h_titre, uc('BULLETIN SCOLAIRE DU ' . $titre_bull), 0, 1, 'C');
    $pdf->SetFont('Arial', 'I', 11);
	$pdf->ln(-1.7);
	$pdf->Cell($uw, $h_titre * 0.35, uc('Term Report '), 2, 1, 'C');
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
    $pdf->SetFont('Arial', 'B', 7);

    $wL1 = [24, 34, 18, 14, 18, 60]; // somme = 168 = $w_info
	$pdf->SetXY($x_info, $y_photo);
	cell2l_c($pdf, 30, 5.5, 'CLASSE :', 'Class', 'L', true, 8, 7.5, $gris, true);
	$pdf->SetFont('Arial', 'I', 8);
	$pdf->Cell(45, 5.5, uc($insc['classe'] ?? ''), 1, 0, 'L');
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
	$pdf->Cell(110, 5.5, uc(strtoupper($eleve['nom']) . ' ' . ($eleve['prenom'] ?? '')), 1, 0, 'L');
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
	$pdf->Cell(64, 5.5, uc($eleve['lieu_naiss'] ?? ''), 1, 0, 'L');
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
	$pdf->Cell(138, 5.5, uc($nom_pp), 1, 1, 'L');

	$pdf->ln(0.5);
	$pdf->SetX($x_info);
	cell2l_c($pdf, 42, 5.5, 'NOM ET CONTACTS DES PARENTS :', "Parent's Name & Contact", 'L', true, 8, 7.5, $gris, true);
	$pdf->SetFont('Arial', '', 7);
	$pdf->Cell(126, 5.5, uc($contacts_parents), 1, 1, 'L');

	$pdf->SetY(max($pdf->GetY(), $y_photo + $photo_h));

    // En-tête tableau disciplines
    $pdf->Ln(1);
    $y_table0 = $pdf->GetY();

    // $decs/$h_dec déclarés ici (avant le tableau, au lieu de juste avant la
    // section décision) pour pouvoir calculer $row_h ci-dessous — voir
    // secondaire/pages/bulletins/pdf.php pour le jumeau (même logique).
    // Hauteur harmonisée de toute la section DISCIPLINES/TRAVAIL/PROFIL/
    // RESULTATS DE L'ELEVE (bandeau inclus, Rappel/Eval, MOYENNE/RANG) —
    // demande explicite. $h_bas ne sert plus que pour DECISION/OBSERVATIONS.
    $h_ligne = 5;
    $h_dec = 3.2;
    $decs = [
        ['Satisfaisant, doit persévérer', 'Satisfactory, must persevere'],
        ['Travail en baisse', 'Work falling'],
        ['Travail insuffisant', 'Insufficient work'],
        ['Attention à la conduite', 'Watch out for behavior'],
        ["Trop d'absences", 'too much absences'],
        ["Risque l'exclusion définitive", 'Risk the definitive exclusion'],
        ['Exclusion', 'Exclusion'],
    ];
    // 13 = marge bas + place réservée à la mention copyright.
    $bas_dispo_c = $pdf->GetPageHeight() - 13;

    // Hauteur de ligne du tableau ajustée dynamiquement (demande explicite :
    // le bulletin d'un élève ne doit jamais dépasser une seule page) — voir
    // secondaire/pages/bulletins/pdf.php pour le détail du calcul (même logique).
    // (Le bandeau utilise désormais aussi $h_ligne — hauteur harmonisée,
    // demande explicite — d'où 6*$h_ligne. $h_bas ne reste utilisé que pour
    // l'en-tête DECISION/OBSERVATIONS.)
    $footer_fixe_c  = 1 + 6 * $h_ligne + 1 + $h_bas + count($decs) * $h_dec;
    $n_lignes_tab_c = count($disciplines);
    $row_h = $n_lignes_tab_c > 0
        ? max(3.2, min(4.5, ($bas_dispo_c - $y_table0 - 5.5 - $footer_fixe_c) / $n_lignes_tab_c))
        : 4.5;

    $pdf->SetFont('Arial', 'B', 6.5);
    $pdf->SetFillColor(0, 0, 0);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetX($ml);
    $pdf->Cell($cD, 5.5, 'DISCIPLINES', 1, 0, 'C', true);
    $pdf->Cell($cNOT, 5.5, uc($titre_bull), 1, 0, 'C', true);
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
    // groupe_lib ni de ligne TOTAL par groupe (abandonnés en Phase 5 pour le
    // bulletin individuel, voir secondaire/pages/bulletins/pdf.php, harmonisé ici).
    // Plus de colonnes par séquence : une seule période (le trimestre), la
    // moyenne de compétences de la matière tient lieu de "note".
    foreach ($disciplines as $d) {
        $id_mat = (int)$d['id_mat'];
        $avg    = pv_moy_matiere_comp($id_eleve, $competences_par_mat[$id_mat] ?? [], $notes_idx, $notes_count, $nb_inscrits);
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

        // Valeur de note agrandie pour la lisibilité (demande explicite),
        // sauf MENTIONS et ENSEIGNANTS qui restent inchangées.
        $ns = $avg !== null ? $fmt($avg) : '';
        $pdf->SetFont('Arial', 'B', 8);
        if ($avg !== null && $avg < 10) $pdf->SetTextColor(180, 0, 0);
        elseif ($avg !== null)          $pdf->SetTextColor(0, 100, 0);
        $pdf->Cell($cNOT, $row_h, $ns, 1, 0, 'C', true);
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

    // Section bas : reproduit le modèle fourni par l'utilisateur (même
    // structure que secondaire/pages/bulletins/pdf.php — voir ce fichier pour le détail
    // des proportions, gardées identiques ici pour la cohérence visuelle).
    $pdf->Ln(1);
    $y_bas = $pdf->GetY();
    // TRAVAIL agrandi (demande explicite : "Tableau d'honneur" trop à
    // l'étroit) au détriment de PROFIL DE LA CLASSE, dont les libellés
    // tiennent déjà largement à taille max avec moins de place (vérifié).
    $wBl = 70; $wBm = 54; $wBr = $uw - $wBl - $wBm; // 70, inchangé

    // Heures d'absence RÉELLES du trimestre, lues dans la table `absence`
    // (fonctions.php::eleve_absence_trimestre(), même fonction partagée que
    // secondaire/pages/bulletins/pdf.php).
    $abs_reelle_c = eleve_absence_trimestre($el['matricule'] ?? '', $id_trim, $id_classe, $val_annee);
    $heur_jus_c   = (int) $abs_reelle_c['jus'];
    $heur_nj_c    = (int) $abs_reelle_c['non_jus'];

    // Mentions automatiques — seuils configurables par l'administrateur
    // (secondaire/pages/parametres/index.php, onglet "Mentions"), mêmes fonctions
    // partagées que secondaire/pages/bulletins/pdf.php (fonctions.php::bulletin_*).
    $reglage_mention_c = get_reglage_mention_bulletin($id_annee);
    $tab_honneur_c = bulletin_tableau_honneur($moy_gen ?? 0, $heur_nj_c, $reglage_mention_c) === 'Oui';
    $encourag_c    = bulletin_encouragement($moy_gen ?? 0, $heur_nj_c, $reglage_mention_c) === 'Oui';
    $felicit_c     = bulletin_felicitation($moy_gen ?? 0, $heur_nj_c, $reglage_mention_c) === 'Oui';
    $avert_cond_c  = bulletin_avert_conduite($heur_nj_c, $reglage_mention_c) === 'OUI';
    $avert_trav_c  = bulletin_avert_travail($moy_gen ?? 0, $reglage_mention_c) === 'Oui';
    $blame_cond_c  = bulletin_blame_conduite($heur_nj_c, $reglage_mention_c) === 'OUI';
    $blame_trav_c  = bulletin_blame_travail($moy_gen ?? 0, $reglage_mention_c) === 'Oui';

    // Hauteur harmonisée avec toutes les lignes de cette section, y compris
    // Rappel/Eval et MOYENNE (demande explicite) — même hauteur $h_ligne,
    // même plafond de police (9/7) que les libellés juste en dessous.
    $w_dis = 36; $w_trav = $wBl - $w_dis; // 36 / 34
    $pdf->SetXY($ml, $y_bas);
    cell2l_c($pdf, $w_dis,  $h_ligne, 'DISCIPLINES', 'Disciplines', 'C', true, 9, 7);
    cell2l_c($pdf, $w_trav, $h_ligne, 'TRAVAIL',     'Work',        'C', true, 9, 7);
    cell2l_c($pdf, $wBm,    $h_ligne, 'PROFIL DE LA CLASSE',   'Class profile',   'C', true, 9, 7);
    cell2l_c($pdf, $wBr,    $h_ligne, "RESULTATS DE L'ELEVE", 'Student results', 'C', true, 9, 7);
    $pdf->Ln($h_ligne);

    $w_dis_lbl = 26; $w_dis_val = $w_dis - $w_dis_lbl;   // 26 / 10
    $w_tr_chk  = 6;  $w_tr_lbl  = $w_trav - $w_tr_chk;   // 6 / 28
    // Colonne valeur réduite (demande explicite : trop large pour des
    // nombres courts comme "14.32" ou "45%", vérifié) au profit du libellé.
    $w_pr_lbl  = round($wBm * 0.72); $w_pr_val = $wBm - $w_pr_lbl; // ~39 / 15

    $left_rows = [
        ['Absences Jus.', 'Justified Abs.', (string)$heur_jus_c, "Tableau d'honneur", 'Roll of honor', true,  $tab_honneur_c],
        ['Absences NJ.',  'Unjustified Abs', (string)$heur_nj_c,  'Encouragement',     'Encouragement', true,  $encourag_c],
        ['Exclusion(jrs)', 'Exclu.', '---',        'Félicitations',     'Congratulation', false, $felicit_c],
        ['Avert. conduite', 'Warning Behavior', null, 'Avert. Travail', 'Worning Work', false, $avert_trav_c, $avert_cond_c],
        ['Blâme conduite',  'Blame Behavior',   null, 'Blâme Travail',  'Blame Behavior', false, $blame_trav_c, $blame_cond_c],
    ];
    $prof_rows = [
        ['Moy. de la classe', 'Class Average',     $fmt($moy_classe_all)],
        ['Moy. du premier',   'Average of First',  $fmt($moy_premier)],
        ['Moy. du dernier',   'Average of Last',   $fmt($moy_dernier)],
        ['Effectif Classé',   'Classified Enroll.', (string)$nb_classes_c],
        ['Taux de réussite',  'Success Rate',      $taux_reussite . '%'],
    ];
    // Taille des libellés DISCIPLINES/TRAVAIL/PROFIL agrandie (demande
    // explicite), auto-ajustée par cell2l_c() donc jamais en débordement.
    foreach ($left_rows as $i => $lr) {
        $y0r = $pdf->GetY();
        $pdf->SetXY($ml, $y0r);
        cell2l_c($pdf, $w_dis_lbl, $h_ligne, $lr[0], $lr[1], 'L', false, 9, 7);
        if ($lr[2] === null) {
            $pdf->Cell($w_dis_val, $h_ligne, '', 1, 0, 'C');
            checkbox_ltm_c($pdf, $ml + $w_dis_lbl + $w_dis_val / 2 - 1.25, $y0r + $h_ligne / 2 - 1.25, $lr[7] ?? false, 2.5);
        } else {
            // Nombre d'heures d'absence : en gras, taille agrandie (demande explicite).
            $pdf->SetFont('Arial', $lr[5] ? 'B' : '', $lr[5] ? 8.5 : 7.5);
            $pdf->Cell($w_dis_val, $h_ligne, $lr[2], 1, 0, 'C');
            $pdf->SetFont('Arial', '', 6.5);
        }
        checkbox_ltm_c($pdf, $pdf->GetX() + 1.5, $y0r + $h_ligne / 2 - 1.25, $lr[6], 2.5);
        $pdf->SetX($pdf->GetX() + $w_tr_chk);
        cell2l_c($pdf, $w_tr_lbl, $h_ligne, $lr[3], $lr[4], 'L', false, 9, 7);

        $pr = $prof_rows[$i];
        $pdf->SetXY($ml + $wBl, $y0r);
        cell2l_c($pdf, $w_pr_lbl, $h_ligne, $pr[0], $pr[1], 'L', false, 9, 7);
        // Moyennes du profil de classe : en gras, taille agrandie mais auto-
        // ajustée (colonne réduite, demande explicite : ne doit jamais déborder).
        $taille_pr_c = cell2l_taille_ajustee_c($pdf, $pr[2], 'B', $w_pr_val - 2.4, 8.5, 6);
        $pdf->SetFont('Arial', 'B', $taille_pr_c);
        $pdf->Cell($w_pr_val, $h_ligne, $pr[2], 1, 0, 'C');
        $pdf->SetFont('Arial', '', 6.5);
        $pdf->Ln($h_ligne);
    }

    // RESULTATS DE L'ELEVE : GENERAL TOTAL / COEFFICIENT / MOYENNE / RANG /
    // APPRECIATION — chantier APC : plus de tableau "Rappel Eval1/Eval2"
    // (sans équivalent en compétences), le bloc occupe toute la largeur $wBr,
    // identique à secondaire/pages/bulletins/pdf.php (harmonisé ici).
    $xR = $ml + $wBl + $wBm;
    $y_res0 = $y_bas + $h_ligne;
    $total_general = ($moy_gen !== null && $total_coef_eleve > 0) ? $moy_gen * $total_coef_eleve : null;
    $moy_str     = $moy_gen !== null ? $fmt($moy_gen) . ' / 20' : '- / 20';
    $rang_str    = $rang_gen !== null ? $rang_gen . 'e / ' . $nb_classes_c : '-';
    $mention_str = $moy_gen !== null ? $mention_gen($moy_gen) : '';

    $res_rows2 = [
        ['GENERAL TOTAL', 'Total général', $total_general !== null ? $fmt($total_general) : '-', false],
        ['COEFFICIENT',   'Coefficient',   $total_coef_eleve > 0 ? (string)$total_coef_eleve : '-', false],
    ];
    $pdf->SetXY($xR, $y_res0);
    foreach ($res_rows2 as $rr) {
        cell2l_c($pdf, $wBr * 0.55, $h_ligne, $rr[0], $rr[1], 'L', false, 9, 7);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell($wBr * 0.45, $h_ligne, uc($rr[2]), 1, 1, 'C');
        $pdf->SetFont('Arial', '', 6.5);
        $pdf->SetX($xR);
    }
    cell2l_c($pdf, $wBr * 0.55, $h_ligne, 'MOYENNE', 'Average', 'L', false, 9, 7);
    $taille_moy_c = cell2l_taille_ajustee_c($pdf, $moy_str, 'B', $wBr * 0.45 - 2.4, 11, 7);
    $pdf->SetFont('Arial', 'B', $taille_moy_c);
    $pdf->SetTextColor(0, 130, 0);
    $pdf->Cell($wBr * 0.45, $h_ligne, uc($moy_str), 1, 1, 'C');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetX($xR);
    cell2l_c($pdf, $wBr * 0.55, $h_ligne, 'RANG', 'Rank', 'L', false, 9, 7);
    $taille_rang_c = cell2l_taille_ajustee_c($pdf, $rang_str, 'B', $wBr * 0.45 - 2.4, 10, 6);
    cell_rang_c($pdf, $wBr * 0.45, $h_ligne, $rang_gen, ' / ' . $nb_classes_c, 1, 'C', $taille_rang_c);
    $pdf->Ln($h_ligne);
    $pdf->SetX($xR);
    cell2l_c($pdf, $wBr * 0.55, $h_ligne, 'APPRECIATION', 'Appreciation', 'L', false, 9, 7);
    $pdf->SetFont('Arial', 'B', 8.5);
    $pdf->SetTextColor(0, 130, 0);
    $pdf->Cell($wBr * 0.45, $h_ligne, uc($mention_str), 1, 1, 'C');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Arial', '', 6.5);

    $pdf->SetY(max($y_res0 + 4 * $h_ligne, $y_bas + 6 * $h_ligne));

    // Décision du conseil
    $pdf->Ln(1);
    $yd = $pdf->GetY();
    $wDec = $uw * 0.55; $wObs = $uw - $wDec;
    $pdf->SetXY($ml, $yd);
    // Police agrandie (demande explicite : c'est la partie la plus importante du bulletin).
    cell2l_c($pdf, $wDec, $h_bas, 'DECISION DU CONSEIL DE CLASSE ET DE DISCIPLINE', 'Decision of the class advice and discipline', 'C', true, 10.5, 8.5);
    cell2l_c($pdf, $wObs, $h_bas, "OBSERVATIONS DU CHEF D'ETABLISSEMENT", "Principal's remarks", 'C', true, 10.5, 8.5);
    $pdf->Ln($h_bas);
    // $decs/$h_dec déjà déclarés avant le tableau (voir plus haut).
    $yd2 = $pdf->GetY();
    // Le cadre OBSERVATIONS occupe tout l'espace restant jusqu'au bas de page
    // (proportionnel à ce qu'il reste, pas une hauteur fixe). Le QR code de
    // vérification est désormais dessiné À L'INTÉRIEUR de ce même cadre (voir
    // plus bas), donc plus besoin de réserver de place en dessous. Ne descend
    // jamais sous la hauteur de la liste DECISION. $bas_dispo_c déjà déclaré
    // avant le tableau.
    $h_obs_box_c = max(count($decs) * $h_dec, $bas_dispo_c - $yd2);
    foreach ($decs as $i => $dec) {
        $y_row = $yd2 + $i * $h_dec;
        // Mêmes règles automatiques que secondaire/pages/bulletins/pdf.php.
        $checked = ($i === 0 && $moy_gen !== null && $moy_gen >= 10)
                || ($i === 2 && $moy_gen !== null && $moy_gen < 8)
                || ($i === 3 && ($avert_cond_c || $blame_cond_c));
        // Case à cocher réduite au minimum lisible (demande explicite).
        checkbox_ltm_c($pdf, $ml + 1.2, $y_row + $h_dec / 2 - 1, $checked, 2);
        $pdf->SetXY($ml + 5.5, $y_row);
        $pdf->SetFont('Arial', 'B', 7.5);
        $txt_fr = uc($dec[0] . ' / ');
        $w_fr   = $pdf->GetStringWidth($txt_fr);
        $pdf->Cell($w_fr, $h_dec, $txt_fr, 0, 0, 'L');
        // Texte anglais agrandi (demande explicite) — même taille que le
        // français, seul le style (italique) les distingue.
        $pdf->SetFont('Arial', 'I', 7.5);
        $pdf->Cell($wDec - 7.5 - $w_fr, $h_dec, uc($dec[1]), 0, 1, 'L');
        $pdf->Rect($ml, $y_row, $wDec, $h_dec);
    }
    // Dernière cellule (vide) qui complète la liste DECISION jusqu'au bas du
    // cadre OBSERVATIONS d'en face, pour que les deux colonnes finissent à la
    // même hauteur — c'est DANS cette dernière cellule, aligné à droite,
    // qu'est dessiné le QR code de vérification (demande explicite), et non
    // plus dans le cadre OBSERVATIONS.
    $y_fill_c   = $yd2 + count($decs) * $h_dec;
    $h_rempli_c = $h_obs_box_c - count($decs) * $h_dec;
    if ($h_rempli_c > 0.01) {
        $pdf->Rect($ml, $y_fill_c, $wDec, $h_rempli_c);
    }

    // QR code de vérification d'authenticité — dans la dernière cellule de
    // DECISION, aligné à droite, avec la photo de l'élève incrustée au
    // centre. Plus de légende "Scannez..." sous le QR (supprimée, demande explicite).
    require_once __DIR__ . '../../pdf/verif_lib.php';
    require_once __DIR__ . '../../pdf/qrcode.php';

    if ($h_rempli_c > 15) {
        $verif_url_c = bulletin_verif_url(
            $id_eleve,
            $is_seq ? 'seq' : 'trim',
            $is_seq ? $id_seq : $id_trim,
            id_affichage_eleve($eleve)
        );
        $photo_path_c = !empty($eleve['photo']) ? __DIR__ . '/../../../assets/uploads/eleves/' . $eleve['photo'] : '';

        $qr_tmp_c = tempnam(sys_get_temp_dir(), 'abzqr_') . '.png';
        try {
            $qr_gen_c = new QRCode($verif_url_c, ['s' => 'qr-h']); // qr-h = correction d'erreur élevée (impression papier)
            $qr_img_c = $qr_gen_c->render_image();
            qr_incruster_photo_c($qr_img_c, $photo_path_c);
            imagepng($qr_img_c, $qr_tmp_c);
            imagedestroy($qr_img_c);

            $qr_size_c = min(26, $wDec - 6, $h_rempli_c - 4);
            $qr_x_c    = $ml + $wDec - $qr_size_c - 3; // aligné à droite
            // Aligné en bas, à 2mm de la ligne finale du tableau (demande
            // explicite) — identique à secondaire/pages/bulletins/pdf.php.
            $qr_y_c    = $y_fill_c + $h_rempli_c - $qr_size_c - 2;
            $pdf->Image($qr_tmp_c, $qr_x_c, $qr_y_c, $qr_size_c, $qr_size_c, 'PNG');
        } finally {
            if (is_file($qr_tmp_c)) unlink($qr_tmp_c);
        }
    }

    $pdf->SetFont('Arial', '', 6);
    $pdf->SetXY($ml + $wDec, $yd2);
    $pdf->MultiCell($wObs, $h_obs_box_c, '', 1, 'L');

    // Signature — remontée et centrée (demande explicite). Décalage fixe
    // (pas proportionnel à $h_obs_box_c, qui peut désormais être grand) pour
    // que le bloc reste en haut du cadre quelle que soit sa hauteur réelle.
    // Police agrandie (demande explicite).
    $pdf->SetFont('Arial', '', 8.5);
    $pdf->SetXY($ml + $wDec, $yd2 + 6);
    $pdf->Cell($wObs, 5.5, uc('Mbé, le ' . date('d-m-Y') . '.'), 0, 1, 'C');
    $pdf->SetX($ml + $wDec);
    $pdf->SetFont('Arial', 'I', 7.5);
    $pdf->Cell($wObs, 4.5, 'On', 0, 1, 'C');
    $pdf->Ln(2.5);
    $pdf->SetX($ml + $wDec);
    $pdf->SetFont('Arial', 'B', 8.5);
    $pdf->Cell($wObs, 5.5, 'LE PROVISEUR,', 0, 1, 'C');
    $pdf->SetX($ml + $wDec);
    $pdf->SetFont('Arial', 'I', 7.5);
    $pdf->Cell($wObs, 4.5, 'The Principal', 0, 1, 'C');

    // Signature numérique (uniquement si demandée à l'impression — jamais
    // automatique — et si l'admin en a configuré une dans les paramètres).
    if (($_GET['signature'] ?? '0') === '1') {
        $sig_w = 22;
        $ph = $pdf->GetPageHeight();
        $sx = $ml + $wDec + ($wObs - $sig_w) / 2;
        $sy = $pdf->GetY() + 0.5;
        pdf_signature_appliquer($pdf, 'bulletin_trimestriel_classe', 'chef_etablissement', 0, 0, $pw, $ph, [
            'x_pct' => $sx / $pw * 100, 'y_pct' => $sy / $ph * 100, 'w_pct' => $sig_w / $pw * 100, 'h_pct' => null,
        ]);
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