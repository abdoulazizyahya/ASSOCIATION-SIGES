<?php
/**
 * Fiche STATISTIQUE ANNUELLE d'une classe (NV/RED, résultats par tranche de
 * moyenne, appréciations, moyenne max/min, observation du professeur
 * principal) — même forme que secondaire/pages/statistiques/pdf_stat_classe.php
 * (séquence/trimestre), mais moyenne annuelle = moyenne des moyennes
 * pondérées des 3 trimestres (même méthode que secondaire/pages/bulletins/pdf_annuel.php).
 * GET : classe, annee, dl (0|1)
 */
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_connexion();

function u(string $s): string {
    return mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
}

$role     = role_connecte();
$is_admin = in_array($role, ['ADMIN', 'PROVISEUR', 'FONDATEUR', 'CENSEUR']) || $role === 'MEMBRE_ASSOCIATION';
$is_ens   = ($role === 'ENSEIGNANT');
$mat_ens  = $is_ens ? get_matricule_ens_connecte() : null;
if (!$is_admin && !$is_ens) die('Acces non autorise.');

$id_classe = (int)($_GET['classe'] ?? 0);
$id_annee  = (int)($_GET['annee']  ?? 0);
$dl        = ($_GET['dl'] ?? '0') === '1';

if (!$id_classe) die('Classe manquante.');

$annee_act = get_annee_active();
if (!$id_annee) $id_annee = (int)($annee_act['id'] ?? 0);
$val_annee = $annee_act['libelle'] ?? '';

if ($is_ens && $mat_ens) {
    $ok = db_val(
        "SELECT COUNT(*) FROM enseignat_principal WHERE matricule_ens=? AND IDClasses=? AND val_annee=?",
        [$mat_ens, $id_classe, $val_annee]
    );
    if (!$ok) die('Acces refuse.');
}

$classe = db_one("SELECT * FROM classe WHERE id=?", [$id_classe]);
if (!$classe) die('Classe introuvable.');

$etab = get_etablissement();

// ── Élèves de la classe (avec statut d'inscription pour NV/RED) ────────
$eleves = db_all(
    "SELECT e.id, e.nom, e.prenom, e.sexe, e.matricule, i.statut
     FROM eleve e JOIN inscription i ON i.id_eleve=e.id AND i.id_classe=? AND i.id_annee=?
     WHERE e.statut='actif'",
    [$id_classe, $id_annee]
);
$nb_inscrits = count($eleves);

// ── Professeur principal ────────────────────────────────────────────────
$pp = db_one(
    "SELECT e.nom_ens, e.prenom_ens FROM enseignat_principal ep
     JOIN enseignant e ON e.matricule_ens=ep.matricule_ens
     WHERE ep.IDClasses=? AND ep.val_annee=? LIMIT 1",
    [$id_classe, $val_annee]
);
$nom_pp = $pp ? trim($pp['nom_ens'] . ' ' . ($pp['prenom_ens'] ?? '')) : '-';

// ── Trimestres de l'année (jusqu'à 3) — les compétences (chantier APC)
// sont scopées par trimestre entier, sans sous-division par séquence : la
// moyenne annuelle = moyenne des moyennes trimestrielles existantes, même
// méthode que secondaire/pages/bulletins/pdf_annuel.php (voir prompt_continuite,
// mise à jour du 07/08/2026, Phase 6). ──────────────────────────────────
// Règles 1/2/3/4 appliquées via le moteur commun (fonctions.php).
$moys = calc_moys_classe_periode_comp($id_classe, $id_annee, 'annee', 0, array_column($eleves, 'id')); // eid => moyenne annuelle

// ── NV / RED par genre ──────────────────────────────────────────────────
$nv = ['M' => 0, 'F' => 0]; $red = ['M' => 0, 'F' => 0];
foreach ($eleves as $el) {
    $sx = (strtoupper($el['sexe'] ?? '') === 'F') ? 'F' : 'M';
    if (strtolower($el['statut'] ?? '') === 'redoublant') $red[$sx]++; else $nv[$sx]++;
}
$eff_total = ['M' => $nv['M'] + $red['M'], 'F' => $nv['F'] + $red['F']];
$eff_total['T'] = $eff_total['M'] + $eff_total['F'];
$nv['T'] = $nv['M'] + $nv['F']; $red['T'] = $red['M'] + $red['F'];

$eff_classe = ['M' => 0, 'F' => 0];
foreach ($eleves as $el) {
    if (isset($moys[$el['id']])) {
        $sx = (strtoupper($el['sexe'] ?? '') === 'F') ? 'F' : 'M';
        $eff_classe[$sx]++;
    }
}
$eff_classe['T'] = $eff_classe['M'] + $eff_classe['F'];

// ── Moyenne générale de la classe ───────────────────────────────────────
$moy_classe = !empty($moys) ? array_sum($moys) / count($moys) : null;

// ── Répartition RESULTATS par tranche de moyenne, par genre ────────────
$brackets = ['ge14' => 0, 'ge13' => 0, 'ge12' => 0, 'ge10' => 0, 'ge08' => 0, 'lt08' => 0, 'lt10' => 0];
$res = ['M' => $brackets, 'F' => $brackets];
foreach ($eleves as $el) {
    if (!isset($moys[$el['id']])) continue;
    $m  = $moys[$el['id']];
    $sx = (strtoupper($el['sexe'] ?? '') === 'F') ? 'F' : 'M';
    if ($m >= 14) $res[$sx]['ge14']++;
    elseif ($m >= 13) $res[$sx]['ge13']++;
    elseif ($m >= 12) $res[$sx]['ge12']++;
    if ($m >= 10) $res[$sx]['ge10']++;
    if ($m >= 8 && $m < 10) $res[$sx]['ge08']++;
    if ($m < 8) { $res[$sx]['lt08']++; $res[$sx]['lt10']++; }
    elseif ($m < 10) $res[$sx]['lt10']++;
}
foreach ($brackets as $k => $v) { $res['T'][$k] = $res['M'][$k] + $res['F'][$k]; }

// ── Appréciations (fonctions pv_* de fonctions.php) + taux réussite/échec,
// par genre — absences NON justifiées cumulées sur les 3 trimestres. ────
$appr_keys = ['felicit' => 0, 'encourag' => 0, 'tab' => 0, 'avert_trav' => 0, 'blame_trav' => 0];
$appr = ['M' => $appr_keys, 'F' => $appr_keys];
$admis = ['M' => 0, 'F' => 0];
foreach ($eleves as $el) {
    if (!isset($moys[$el['id']])) continue;
    $m  = $moys[$el['id']];
    $sx = (strtoupper($el['sexe'] ?? '') === 'F') ? 'F' : 'M';
    $abs_nj = 0;
    foreach ($trimestres as $t) {
        if (!$t) continue;
        $abs_nj += (int) eleve_absence_trimestre($el['matricule'] ?? '', (int)$t['id'], $id_classe, $val_annee)['non_jus'];
    }
    if (pv_felicitation($m, $abs_nj) === 'Oui')        $appr[$sx]['felicit']++;
    if (pv_encouragement($m, $abs_nj) === 'Oui')       $appr[$sx]['encourag']++;
    if (pv_tableau_honneur($m, $abs_nj) === 'Oui')     $appr[$sx]['tab']++;
    if (pv_avertissement_travail($m) === 'Oui')        $appr[$sx]['avert_trav']++;
    if (pv_blame_travail($m) === 'Oui')                $appr[$sx]['blame_trav']++;
    if ($m >= 10) $admis[$sx]++;
}
foreach ($appr_keys as $k => $v) { $appr['T'][$k] = $appr['M'][$k] + $appr['F'][$k]; }
$admis['T'] = $admis['M'] + $admis['F'];
$taux_reussite = ['M' => null, 'F' => null, 'T' => null];
$taux_echec    = ['M' => null, 'F' => null, 'T' => null];
foreach (['M', 'F', 'T'] as $g) {
    $taux_reussite[$g] = $eff_classe[$g] > 0 ? round($admis[$g] / $eff_classe[$g] * 100, 2) : 0.0;
    $taux_echec[$g]    = $eff_classe[$g] > 0 ? round(($eff_classe[$g] - $admis[$g]) / $eff_classe[$g] * 100, 2) : 0.0;
}

// ── Moyenne max/min + nom du premier/dernier ────────────────────────────
$moy_max = null; $moy_min = null; $nom_premier = ''; $nom_dernier = '';
if (!empty($moys)) {
    $eid_max = array_keys($moys, max($moys))[0];
    $eid_min = array_keys($moys, min($moys))[0];
    $moy_max = $moys[$eid_max]; $moy_min = $moys[$eid_min];
    foreach ($eleves as $el) {
        if ((int)$el['id'] === (int)$eid_max) $nom_premier = strtoupper($el['nom']) . ' ' . ($el['prenom'] ?? '');
        if ((int)$el['id'] === (int)$eid_min) $nom_dernier = strtoupper($el['nom']) . ' ' . ($el['prenom'] ?? '');
    }
}

function appreciation_classe_stat(?float $m): string {
    if ($m === null) return '';
    if ($m >= 16) return 'Excellent';
    if ($m >= 14) return 'Très Bien';
    if ($m >= 12) return 'Bien';
    if ($m >= 10) return 'Assez Bien';
    if ($m >= 8)  return 'Moyenne';
    return 'Faible';
}
$appreciation_classe = appreciation_classe_stat($moy_classe);

function fmt2(?float $v): string {
    if ($v === null) return '';
    $s = number_format($v, 2, '.', '');
    [$i, $d] = explode('.', $s);
    return str_pad($i, 2, '0', STR_PAD_LEFT) . '.' . $d;
}

// ── Génération PDF ──────────────────────────────────────────────────────
require_once __DIR__ . '/../../pdf/fpdf.php';
require_once __DIR__ . '/../../pdf/header_pdf.php'; // pour pdf_filigrane()

$pdf = new FPDF('P', 'mm', 'A4');
$pdf->SetMargins(8, 8, 8);
$pdf->SetAutoPageBreak(true, 4);
$pdf->AddPage();
$pw = $pdf->GetPageWidth();
$ph = $pdf->GetPageHeight();
$ml = 8; $mr = 8;
$uw = $pw - $ml - $mr;

// Pas de cadre de page (demande explicite, contrairement aux bulletins).

// Filigrane : une seule grande image, centrée horizontalement ET
// verticalement sur la page. Fonction centralisée dans pdf/header_pdf.php.
pdf_filigrane($pdf, $etab, $pw, $ph, 190);

// ── En-tête bilingue (identique aux bulletins) ──────────────────────────
$col3 = $uw / 3;
$y0   = 8;
$pdf->SetXY($ml, $y0);
$pdf->SetFont('Arial', '', 6.5);
$pdf->MultiCell($col3, 3.5, u(
    "REPUBLIQUE DU CAMEROUN\nPaix - Travail - Patrie\n***************\n" .
    ($etab['region_fr'] ?? "REGION DE L'ADAMAOUA") . "\n" .
    ($etab['departement_fr'] ?? 'DEPARTEMENT DE LA VINA') . "\n" .
    ($etab['arrondissement_fr'] ?? 'ARRONDISSEMENT DE MBE') . "\n" .
    strtoupper($etab['nom_fr'] ?? 'LYCEE TECHNIQUE DE MBE') . "\n" .
    "B.P. " . ($etab['boite_postale'] ?? '') . " Tel.: " . ($etab['telephone'] ?? '') . "\n" .
    ($etab['email'] ?? '')
), 0, 'C');

$logo_src_path = !empty($etab['logo']) ? __DIR__ . '/../../../assets/uploads/' . $etab['logo'] : '';
$logo_w = 22; $logo_h = $logo_w;
if ($logo_src_path && is_file($logo_src_path)) {
    $dim = @getimagesize($logo_src_path);
    if ($dim && $dim[0] > 0) $logo_h = $logo_w * $dim[1] / $dim[0];
}
$logo_x = $ml + $col3 + ($col3 - $logo_w) / 2;
$logo_y = $y0 + (25 - $logo_h) / 2;
if ($logo_src_path && is_file($logo_src_path)) {
    $pdf->Image($logo_src_path, $logo_x, $logo_y, $logo_w);
}

$xr = $ml + $col3 * 2;
$pdf->SetXY($xr, $y0);
$pdf->SetFont('Arial', '', 6.5);
$pdf->MultiCell($col3, 3.5, u(
    "REPUBLIC OF CAMEROON\nPeace - Work - Fatherland\n***************\n" .
    ($etab['region_en'] ?? 'ADAMAWA REGION') . "\n" .
    ($etab['division_en'] ?? 'VINA DIVISION') . "\n" .
    ($etab['subdivision_en'] ?? 'MBE SUBDIVISION') . "\n" .
    strtoupper($etab['nom_en'] ?? 'GTHS OF MBE') . "\n" .
    "P.O. BOX. " . ($etab['boite_postale'] ?? '') . " Phone: " . ($etab['telephone'] ?? '') . "\n" .
    ($etab['email'] ?? '')
), 0, 'C');

$pdf->SetY(max($pdf->GetY(), 33));
$pdf->SetFont('Arial', '', 7);
$pdf->SetX($ml);
$pdf->Cell($uw / 2, 4, u('IMMATRICULATION: ' . ($etab['immatriculation'] ?? '')), 0, 0, 'L');
$pdf->Cell($uw / 2, 4, u('REGISTRATION: ' . ($etab['immatriculation'] ?? '')), 0, 1, 'R');

$pdf->Ln(1);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell($uw, 5, u('Annee scolaire: ' . $val_annee), 0, 1, 'C');

// ── Titre (texte souligné, sans bandeau — conforme au modèle fourni) ────
$pdf->SetFont('Arial', 'BU', 13);
$pdf->Cell($uw, 8, u('STATISTIQUE : Annuelle'), 0, 1, 'C');
$pdf->Ln(1);

// ── Classe (gauche) + petit tableau NV/RED/EFFECTIF (droite) ───────────
$y_bloc1  = $pdf->GetY();
$w0 = 27; $w1 = 10;
$w_petit  = $w0 + 3 * $w1;
$x_petit  = $ml + $uw - $w_petit;
$h_row    = 5;

$pdf->SetXY($x_petit, $y_bloc1);
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetFillColor(230, 230, 230);
$pdf->Cell($w0, $h_row, '', 1, 0, 'C');
$pdf->Cell($w1, $h_row, 'M', 1, 0, 'C', true);
$pdf->Cell($w1, $h_row, 'F', 1, 0, 'C', true);
$pdf->Cell($w1, $h_row, 'T', 1, 1, 'C', true);

$lignes_petit = [
    ['NV', $nv, false],
    ['RED', $red, false],
    ['EFFECTIF TOTAL', $eff_total, true],
    ['EFFECTIF CLASSE', $eff_classe, true],
];
foreach ($lignes_petit as [$lbl, $vals, $shade]) {
    $pdf->SetX($x_petit);
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->Cell($w0, $h_row, u($lbl), 1, 0, 'L', $shade);
    $pdf->SetFont('Arial', '', 7.5);
    $pdf->Cell($w1, $h_row, (string)$vals['M'], 1, 0, 'C', $shade);
    $pdf->Cell($w1, $h_row, (string)$vals['F'], 1, 0, 'C', $shade);
    $pdf->Cell($w1, $h_row, (string)$vals['T'], 1, 1, 'C', $shade);
}
$y_apres_petit = $pdf->GetY();

$pdf->SetXY($ml, $y_bloc1 + 6);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell($uw - $w_petit - 4, 6, u('Classe : ' . $classe['designation']), 0, 1, 'L');

$pdf->SetY(max($pdf->GetY(), $y_apres_petit));
$pdf->Ln(2);

// ── Moyenne générale / Professeur principal ─────────────────────────────
$pdf->SetX($ml);
$pdf->SetFont('Arial', 'B', 9.5);
$pdf->Cell($uw, 5.5, u('Moyenne generale de la classe : ' . fmt2($moy_classe) . ' / 20'), 0, 1, 'L');
$pdf->SetX($ml);
$pdf->Cell($uw, 5.5, u('Professeur principal : ' . strtoupper($nom_pp)), 0, 1, 'L');
$pdf->Ln(1.5);

// ── Tableaux RESULTATS / APPRECIATIONS côte à côte ──────────────────────
$y_tab   = $pdf->GetY();
$w_tab   = ($uw - 4) / 2;
$w_lbl   = $w_tab - 3 * 17;
$w_val   = 17;
$h_tr    = 5.5;
$x_res   = $ml;
$x_appr  = $ml + $w_tab + 4;

$pdf->SetFont('Arial', 'B', 8);
$pdf->SetFillColor(26, 60, 107);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetXY($x_res, $y_tab);
$pdf->Cell($w_lbl, $h_tr, u('RESULTATS'), 1, 0, 'L', true);
$pdf->Cell($w_val, $h_tr, 'M', 1, 0, 'C', true);
$pdf->Cell($w_val, $h_tr, 'F', 1, 0, 'C', true);
$pdf->Cell($w_val, $h_tr, u('TOTAL'), 1, 1, 'C', true);
$pdf->SetXY($x_appr, $y_tab);
$pdf->Cell($w_lbl, $h_tr, u('APPRECIATIONS'), 1, 0, 'L', true);
$pdf->Cell($w_val, $h_tr, 'M', 1, 0, 'C', true);
$pdf->Cell($w_val, $h_tr, 'F', 1, 0, 'C', true);
$pdf->Cell($w_val, $h_tr, u('TOTAL'), 1, 1, 'C', true);
$pdf->SetTextColor(0, 0, 0);

$lignes_res = [
    ['Moyenne >= 14', $res['M']['ge14'], $res['F']['ge14'], $res['T']['ge14'], false],
    ['14 > Moyenne >= 13', $res['M']['ge13'], $res['F']['ge13'], $res['T']['ge13'], false],
    ['13 > Moyenne >= 12', $res['M']['ge12'], $res['F']['ge12'], $res['T']['ge12'], false],
    ['Total Moyenne >= 10', $res['M']['ge10'], $res['F']['ge10'], $res['T']['ge10'], true],
    ['10 > Moyenne >= 08', $res['M']['ge08'], $res['F']['ge08'], $res['T']['ge08'], false],
    ['Moyenne < 08', $res['M']['lt08'], $res['F']['lt08'], $res['T']['lt08'], false],
    ['Total Moyenne < 10', $res['M']['lt10'], $res['F']['lt10'], $res['T']['lt10'], true],
];
$fmt_pct = fn(float $v): string => number_format($v, 2, '.', '') . ' %';
$lignes_appr = [
    ['Felicitations', (string)$appr['M']['felicit'], (string)$appr['F']['felicit'], (string)$appr['T']['felicit'], false],
    ['Encouragements', (string)$appr['M']['encourag'], (string)$appr['F']['encourag'], (string)$appr['T']['encourag'], false],
    ["Tableau d'honneur", (string)$appr['M']['tab'], (string)$appr['F']['tab'], (string)$appr['T']['tab'], false],
    ['Taux de reussite', $fmt_pct($taux_reussite['M']), $fmt_pct($taux_reussite['F']), $fmt_pct($taux_reussite['T']), true],
    ['Avertissement Travail', (string)$appr['M']['avert_trav'], (string)$appr['F']['avert_trav'], (string)$appr['T']['avert_trav'], false],
    ['Blame Travail', (string)$appr['M']['blame_trav'], (string)$appr['F']['blame_trav'], (string)$appr['T']['blame_trav'], false],
    ["Pourcentage d'echec", $fmt_pct($taux_echec['M']), $fmt_pct($taux_echec['F']), $fmt_pct($taux_echec['T']), true],
];

$y_row = $y_tab + $h_tr;
for ($i = 0; $i < 7; $i++) {
    [$lbl_r, $vm_r, $vf_r, $vt_r, $bold_r] = $lignes_res[$i];
    [$lbl_a, $vm_a, $vf_a, $vt_a, $bold_a] = $lignes_appr[$i];

    $pdf->SetXY($x_res, $y_row);
    $pdf->SetFont('Arial', $bold_r ? 'B' : '', 7.5);
    $pdf->SetFillColor(224, 231, 243);
    $pdf->Cell($w_lbl, $h_tr, u($lbl_r), 1, 0, 'L', $bold_r);
    $pdf->Cell($w_val, $h_tr, (string)$vm_r, 1, 0, 'C', $bold_r);
    $pdf->Cell($w_val, $h_tr, (string)$vf_r, 1, 0, 'C', $bold_r);
    $pdf->Cell($w_val, $h_tr, (string)$vt_r, 1, 1, 'C', $bold_r);

    $pdf->SetXY($x_appr, $y_row);
    $pdf->SetFont('Arial', $bold_a ? 'B' : '', 7.5);
    $pdf->Cell($w_lbl, $h_tr, u($lbl_a), 1, 0, 'L', $bold_a);
    $pdf->Cell($w_val, $h_tr, (string)$vm_a, 1, 0, 'C', $bold_a);
    $pdf->Cell($w_val, $h_tr, (string)$vf_a, 1, 0, 'C', $bold_a);
    $pdf->Cell($w_val, $h_tr, (string)$vt_a, 1, 1, 'C', $bold_a);

    $y_row += $h_tr;
}
$pdf->SetY($y_row);
$pdf->Ln(3);

// ── Moyenne Max/Min + noms ───────────────────────────────────────────────
$pdf->SetX($ml);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell($uw / 2, 5.5, u('Moyenne Max : ' . fmt2($moy_max) . ' /20'), 0, 0, 'L');
$pdf->Cell($uw / 2, 5.5, u('Nom du premier : ' . $nom_premier), 0, 1, 'L');
$pdf->SetX($ml);
$pdf->Cell($uw / 2, 5.5, u('Moyenne Min : ' . fmt2($moy_min) . ' /20'), 0, 0, 'L');
$pdf->Cell($uw / 2, 5.5, u('Nom du dernier : ' . $nom_dernier), 0, 1, 'L');
$pdf->Ln(2);

$pdf->SetX($ml);
$pdf->Cell($uw, 5.5, u('Appreciation de la classe : ' . $appreciation_classe), 0, 1, 'L');
$pdf->Ln(4);

// ── Observation générale du professeur principal ────────────────────────
$pdf->SetFont('Arial', 'BU', 10);
$pdf->Cell($uw, 6, u('OBSERVATION GENERALE DU PROFESSEUR PRINCIPAL'), 0, 1, 'C');
$pdf->Ln(2);
$pdf->SetFont('Arial', '', 9);
for ($i = 0; $i < 5; $i++) {
    $pdf->SetX($ml);
    $pdf->Cell($uw, 7, str_repeat('.', 175), 0, 1, 'L');
}
$pdf->Ln(10);

$pdf->SetFont('Arial', '', 9);
$pdf->SetX($ml);
$pdf->Cell($uw, 5, u('Mbe, le .................................'), 0, 1, 'R');
$pdf->Ln(2);
// Deux colonnes : Professeur Principal (signataire du bulletin/de la classe,
// sans image possible — nom variable par classe/enseignant) + contre-signature
// du chef d'établissement (image configurable, sur demande uniquement).
$y_sig = $pdf->GetY();
$pdf->SetFont('Arial', 'BU', 9);
$pdf->Cell($uw / 2, 5, u('SIGNATURE DU PROFESSEUR PRINCIPAL,'), 0, 0, 'C');
$pdf->Cell($uw / 2, 5, u(strtoupper($etab['chef_etablissement'] ?? 'LE PROVISEUR') . ','), 0, 1, 'C');

if (($_GET['signature'] ?? '0') === '1') {
    $sig_w = 22;
    $ph = $pdf->GetPageHeight();
    $sy = $y_sig + 6;

    $sig_pp = signature_chemin_pp_classe($id_classe, $val_annee);
    pdf_signature_image_directe($pdf, $sig_pp, $ml + ($uw / 2 - $sig_w) / 2, $sy, $sig_w);

    $sx = $ml + $uw / 2 + ($uw / 2 - $sig_w) / 2;
    pdf_signature_appliquer($pdf, 'stat_classe_annuel', 'chef_etablissement', 0, 0, $pw, $ph, [
        'x_pct' => $sx / $pw * 100, 'y_pct' => $sy / $ph * 100, 'w_pct' => $sig_w / $pw * 100, 'h_pct' => null,
    ]);
}

// ── Sortie ────────────────────────────────────────────────────────────
$mode   = $dl ? 'D' : 'I';
$suffix = preg_replace('/\W+/', '_', $classe['designation'] ?? 'classe');
$pdf->Output($mode, 'stat_annuel_' . $suffix . '.pdf');
