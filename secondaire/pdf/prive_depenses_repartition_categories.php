<?php
// secondaire/pdf/prive_depenses_repartition_categories.php — Répartition
// PDF des dépenses PRIVÉES par catégorie — porté de
// pdf/depenses_repartition_categories.php (primaire), adapté au schéma
// secondaire.
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'SECRETAIRE', 'INTENDANT']);

require_once __DIR__ . '/fpdf.php';
require_once __DIR__ . '/header_pdf.php';

$dl = ($_GET['dl'] ?? '0') === '1';

$annee     = get_annee_active();
$id_annee  = (int) ($annee['id'] ?? 0);
$val_annee = $annee['val_annee'] ?? ($annee['libelle'] ?? '');

$lignes = db_all(
    "SELECT cd.libelle, COUNT(d.id) AS nb, COALESCE(SUM(d.montant),0) AS total
     FROM categorie_depense_privee cd
     LEFT JOIN depense_privee d ON d.id_categorie = cd.id AND d.id_annee = ?
     GROUP BY cd.id
     ORDER BY total DESC",
    [$id_annee]
);
$total_general = array_sum(array_column($lignes, 'total'));
foreach ($lignes as &$l) {
    $l['total'] = (float) $l['total'];
    $l['pct']   = $total_general > 0 ? round($l['total'] / $total_general * 100, 1) : 0.0;
}
unset($l);

$etab_brut = get_etablissement();
$etab      = etab_pour_pdf($etab_brut);

try {
$pdf = new FPDF('P', 'mm', 'A4');
$pdf->SetMargins(12, 10, 12);
$pdf->SetAutoPageBreak(true, 15);
$pdf->AddPage();
$pw = $pdf->GetPageWidth();
$ph = $pdf->GetPageHeight();

pdf_filigrane($pdf, $etab, $pw, $ph);
pdf_entete($pdf, $etab, $pw, 12);
pdf_bandeau($pdf, 'REPARTITION DES DEPENSES PRIVEES PAR CATEGORIE', 'PRIVATE EXPENSE BREAKDOWN BY CATEGORY', $pw, 12);

$pdf->SetFont('Arial', '', 8.5);
$pdf->Cell(0, 5, pdf_u('Année scolaire : ' . $val_annee . ' — Total dépensé : ' . number_format($total_general, 0, ',', ' ') . ' F'), 0, 1, 'L');
$pdf->Ln(2);

$w = [70, 30, 42, 34];
$pdf->SetFont('Arial', 'B', 7.5);
$pdf->SetFillColor(220, 38, 38); $pdf->SetTextColor(255, 255, 255);
$pdf->Cell($w[0], 6, pdf_u('Catégorie'), 1, 0, 'C', true);
$pdf->Cell($w[1], 6, pdf_u('Nb dépenses'), 1, 0, 'C', true);
$pdf->Cell($w[2], 6, pdf_u('Montant dépensé'), 1, 0, 'C', true);
$pdf->Cell($w[3], 6, pdf_u('% du total'), 1, 1, 'C', true);
$pdf->SetTextColor(0); $pdf->SetFont('Arial', '', 7.5);

foreach ($lignes as $l) {
    if ($pdf->GetY() > $ph - 20) { $pdf->AddPage(); pdf_filigrane($pdf, $etab, $pw, $ph); }
    $pdf->Cell($w[0], 5, pdf_u($l['libelle']), 1, 0, 'L');
    $pdf->Cell($w[1], 5, (string) $l['nb'], 1, 0, 'C');
    $pdf->Cell($w[2], 5, number_format($l['total'], 0, ',', ' '), 1, 0, 'R');
    $pdf->Cell($w[3], 5, $l['pct'] . ' %', 1, 1, 'C');
}
if (!$lignes) {
    $pdf->SetFont('Arial', 'I', 8);
    $pdf->Cell(array_sum($w), 6, pdf_u('Aucune catégorie de dépense.'), 1, 1, 'C');
} else {
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->SetFillColor(252, 226, 226);
    $pdf->Cell($w[0] + $w[1], 5.5, pdf_u('TOTAL (' . count($lignes) . ' catégorie(s))'), 1, 0, 'R', true);
    $pdf->Cell($w[2], 5.5, number_format($total_general, 0, ',', ' '), 1, 0, 'R', true);
    $pdf->Cell($w[3], 5.5, '100 %', 1, 1, 'C', true);
}

$pdf->Ln(8);
if ($pdf->GetY() > $ph - 30) { $pdf->AddPage(); pdf_filigrane($pdf, $etab, $pw, $ph); }
$w_sign = ($pw - 24) * 0.4;
$x_sign = $pw - 12 - $w_sign;
$pdf->SetFont('Arial', '', 9);
$pdf->SetX($x_sign);
$pdf->Cell($w_sign, 5, pdf_u('Fait à ' . (($etab['lieu'] ?: $etab['ville']) ?: '') . ', le ' . date('d/m/Y')), 0, 1, 'R');
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetX($x_sign);
$pdf->Cell($w_sign, 5, pdf_u(mb_strtoupper($etab['chef_etablissement'] ?: 'LE PROVISEUR') . ','), 0, 1, 'R');

pdf_copyright($pdf, $pw, $ph);
$pdf->Output($dl ? 'D' : 'I', 'repartition_depenses_prive_' . preg_replace('/[^A-Za-z0-9]/', '_', $val_annee) . '.pdf');
} catch (Throwable $e) {
    pdf_erreur_generation($e);
}
