<?php
// ── PDF : Fiche statistique d'une classe pour un trimestre (piste arabe) ─
// Miroir de pdf/fiche_statistique_classe.php — statistiques_classe_trimestre_arabe().
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
require_once __DIR__ . '/../notes_apc_arabe.php';
exiger_connexion();

require_once __DIR__ . '/fpdf.php';
require_once __DIR__ . '/header_pdf.php';

$id_classe = (int) ($_GET['classe'] ?? 0);
$id_trim   = (int) ($_GET['trim'] ?? 0);
$dl        = ($_GET['dl'] ?? '0') === '1';
if (!$id_classe || !$id_trim) die('Paramètres classe/trim manquants.');

$classe = db_one("SELECT * FROM classe WHERE IDClasses=?", [$id_classe]);
if (!$classe) die('Classe introuvable.');
$trimestre = db_one("SELECT * FROM trimestre WHERE id_trim=?", [$id_trim]);
if (!$trimestre) die('Trimestre introuvable.');

$annee     = get_annee_active();
$val_annee = $annee['val_annee'] ?? '';
$etab_brut = get_etablissement();
$etab      = etab_pour_pdf($etab_brut);

$s = statistiques_classe_trimestre_arabe($id_classe, $id_trim, $val_annee);

// Enveloppé dans un try/catch — voir fonctions.php::pdf_erreur_generation()
// (jamais de fatal error brut ; ce document est public via QR et/ou
// consulté par du personnel qui ne doit pas voir de trace technique).
try {
$pdf = new FPDF('P', 'mm', 'A4');
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 15);
$pdf->AddPage();
$pw = $pdf->GetPageWidth();
$ph = $pdf->GetPageHeight();
$uw = $pw - 20;

pdf_filigrane($pdf, $etab, $pw, $ph);
pdf_entete($pdf, $etab, $pw, 10);
pdf_bandeau($pdf, 'FICHE STATISTIQUE DE CLASSE (ARABE)', 'CLASS STATISTICAL REPORT (ARABIC TRACK)', $pw, 10);

$pdf->SetFont('Arial', 'B', 9);
$pdf->SetX(10);
$pdf->Cell($uw, 4.5, pdf_u('Classe : ' . $classe['DesignationClasses'] . '   —   ' . $trimestre['libelle_trim'] . '   —   Année : ' . $val_annee), 0, 1, 'C');
$pdf->SetFont('Arial', 'I', 7);
$pdf->SetX(10);
$pdf->Cell($uw, 3.5, 'Class / Term / School year', 0, 1, 'C');
$pdf->Ln(2);

// ── Effectifs ──────────────────────────────────────────────────────
$w4 = $uw / 4;
$pdf->SetX(10);
pdf_cell_bilingue($pdf, $w4, 9, 'Effectif total', 'Total headcount', 1, 'C', true, 8, 6);
pdf_cell_bilingue($pdf, $w4, 9, 'Garçons', 'Boys', 1, 'C', true, 8, 6);
pdf_cell_bilingue($pdf, $w4, 9, 'Filles', 'Girls', 1, 'C', true, 8, 6);
pdf_cell_bilingue($pdf, $w4, 9, 'Nouv. / Red.', 'New / Repeat', 1, 'C', true, 8, 6);
$pdf->Ln(9);
$pdf->SetX(10);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell($w4, 8, (string) $s['effectif_total']['T'], 1, 0, 'C');
$pdf->Cell($w4, 8, (string) $s['effectif_total']['M'], 1, 0, 'C');
$pdf->Cell($w4, 8, (string) $s['effectif_total']['F'], 1, 0, 'C');
$pdf->Cell($w4, 8, $s['nv']['T'] . ' / ' . $s['red']['T'], 1, 1, 'C');
$pdf->Ln(3);

// ── Résultats généraux ──────────────────────────────────────────────
$pdf->SetX(10);
pdf_cell_bilingue($pdf, $w4, 9, 'Moyenne de classe', 'Class average', 1, 'C', true, 8, 6);
pdf_cell_bilingue($pdf, $w4, 9, 'Taux de réussite', 'Pass rate', 1, 'C', true, 8, 6);
pdf_cell_bilingue($pdf, $w4, 9, '1er de classe', 'Top of class', 1, 'C', true, 8, 6);
pdf_cell_bilingue($pdf, $w4, 9, 'Dernier', 'Last', 1, 'C', true, 8, 6);
$pdf->Ln(9);
$pdf->SetX(10);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell($w4, 8, pdf_u($s['moy_classe'] !== null ? number_format((float) $s['moy_classe'], 2) . '/20' : '—'), 1, 0, 'C');
$pdf->Cell($w4, 8, pdf_u($s['taux_reussite']['T'] !== null ? $s['taux_reussite']['T'] . '%' : '—'), 1, 0, 'C');
$pdf->SetFont('Arial', '', 7.5);
$pdf->Cell($w4, 8, pdf_u(mb_strimwidth($s['nom_premier'] ?: '—', 0, 26, '…') . ' (' . ($s['moy_premier'] !== null ? number_format((float) $s['moy_premier'], 2) : '—') . ')'), 1, 0, 'C');
$pdf->Cell($w4, 8, pdf_u(mb_strimwidth($s['nom_dernier'] ?: '—', 0, 26, '…') . ' (' . ($s['moy_dernier'] !== null ? number_format((float) $s['moy_dernier'], 2) : '—') . ')'), 1, 1, 'C');
$pdf->Ln(1);
$pdf->SetX(10);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell($uw, 5, pdf_u('Appréciation de la classe : ' . ($s['appreciation_classe'] ?: '—')), 0, 1, 'L');
$pdf->Ln(3);

// ── Tableau : répartition par tranche de moyenne ────────────────────
$pdf->SetX(10);
pdf_cell_bilingue($pdf, $uw, 8, 'RÉPARTITION PAR TRANCHE DE MOYENNE', 'BREAKDOWN BY AVERAGE RANGE', 1, 'C', true, 8, 6);
$pdf->Ln(8);
// ">=" plutôt que "≥" — Windows-1252 (encodage FPDF, voir pdf_u()) ne
// contient pas ce caractère (même piège que la version française).
$tranches_lbl = ['ge16' => '>= 16', 'ge14' => '14 – 15,99', 'ge12' => '12 – 13,99', 'ge10' => '10 – 11,99', 'ge08' => '8 – 9,99', 'lt08' => '< 8'];
$w_lbl = $uw * 0.4; $w_col = ($uw - $w_lbl) / 3;
$pdf->SetX(10);
$pdf->SetFont('Arial', 'B', 7.5);
$pdf->SetFillColor(30, 79, 216); $pdf->SetTextColor(255, 255, 255);
$pdf->Cell($w_lbl, 6, 'Tranche', 1, 0, 'L', true);
$pdf->Cell($w_col, 6, 'G', 1, 0, 'C', true);
$pdf->Cell($w_col, 6, 'F', 1, 0, 'C', true);
$pdf->Cell($w_col, 6, 'Total', 1, 1, 'C', true);
$pdf->SetTextColor(0); $pdf->SetFont('Arial', '', 7.5);
foreach ($tranches_lbl as $k => $lbl) {
    $pdf->SetX(10);
    $pdf->Cell($w_lbl, 5.5, pdf_u($lbl), 1, 0, 'L');
    $pdf->Cell($w_col, 5.5, (string) $s['tranches']['M'][$k], 1, 0, 'C');
    $pdf->Cell($w_col, 5.5, (string) $s['tranches']['F'][$k], 1, 0, 'C');
    $pdf->Cell($w_col, 5.5, (string) $s['tranches']['T'][$k], 1, 1, 'C');
}
$pdf->Ln(3);

// ── Tableau : mentions ───────────────────────────────────────────────
$pdf->SetX(10);
pdf_cell_bilingue($pdf, $uw, 8, 'MENTIONS', 'HONORS / SANCTIONS', 1, 'C', true, 8, 6);
$pdf->Ln(8);
$mentions_lbl = ['felicitations' => 'Félicitations', 'encouragement' => 'Encouragement', 'tableau_honneur' => "Tableau d'honneur", 'avertissement' => 'Avertissement travail', 'blame' => 'Blâme travail'];
$pdf->SetX(10);
$pdf->SetFont('Arial', 'B', 7.5);
$pdf->SetFillColor(30, 79, 216); $pdf->SetTextColor(255, 255, 255);
$pdf->Cell($w_lbl, 6, 'Mention', 1, 0, 'L', true);
$pdf->Cell($w_col, 6, 'G', 1, 0, 'C', true);
$pdf->Cell($w_col, 6, 'F', 1, 0, 'C', true);
$pdf->Cell($w_col, 6, 'Total', 1, 1, 'C', true);
$pdf->SetTextColor(0); $pdf->SetFont('Arial', '', 7.5);
foreach ($mentions_lbl as $k => $lbl) {
    $pdf->SetX(10);
    $pdf->Cell($w_lbl, 5.5, pdf_u($lbl), 1, 0, 'L');
    $pdf->Cell($w_col, 5.5, (string) $s['mentions']['M'][$k], 1, 0, 'C');
    $pdf->Cell($w_col, 5.5, (string) $s['mentions']['F'][$k], 1, 0, 'C');
    $pdf->Cell($w_col, 5.5, (string) $s['mentions']['T'][$k], 1, 1, 'C');
}

// ── Lieu, date et signature du Directeur ─────────────────────────
$pdf->Ln(8);
if ($pdf->GetY() > $ph - 28) { $pdf->AddPage(); pdf_filigrane($pdf, $etab, $pw, $ph); }
$w_sign = $uw * 0.4;
$x_sign = $pw - 10 - $w_sign;
$pdf->SetFont('Arial', '', 9);
$pdf->SetXY($x_sign, $pdf->GetY());
$pdf->Cell($w_sign, 5, pdf_u('Fait à ' . (($etab['lieu'] ?: $etab['ville']) ?: '') . ', le ' . date('d/m/Y')), 0, 1, 'R');
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetX($x_sign);
$pdf->Cell($w_sign, 5, pdf_u(mb_strtoupper($etab['chef_etablissement'] ?: 'LE DIRECTEUR') . ','), 0, 1, 'R');

if (($_GET['signature'] ?? '0') === '1') {
    pdf_signature_appliquer_jn($pdf, 'fiche_statistique_classe_arabe', 0, 0, $pw, $ph, [
        'x_pct' => ($x_sign + ($w_sign - 22) / 2) / $pw * 100,
        'y_pct' => ($pdf->GetY() + 1) / $ph * 100,
        'w_pct' => 22 / $pw * 100, 'h_pct' => null,
    ]);
}

// Copyright standard du système (pdf/header_pdf.php) — texte unique sur
// tous les PDF du projet, voir pdf_copyright().
pdf_copyright($pdf, $pw, $ph);
$pdf->Output($dl ? 'D' : 'I', 'fiche_stat_arabe_' . $classe['DesignationClasses'] . '_T' . $id_trim . '.pdf');
} catch (Throwable $e) {
    pdf_erreur_generation($e);
}
