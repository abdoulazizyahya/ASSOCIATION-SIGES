<?php
// pdf/depenses_categories.php — Catalogue des catégories de dépenses PDF,
// mêmes données que pages/depenses/categories.php.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
exiger_role(['DIRECTEUR', 'COMPTABLE']);

require_once __DIR__ . '/fpdf.php';
require_once __DIR__ . '/header_pdf.php';

$dl = ($_GET['dl'] ?? '0') === '1';

$categories = db_all(
    "SELECT cd.*, COUNT(d.id_depense) AS nb_depenses, COALESCE(SUM(d.montant),0) AS total_depense
     FROM categorie_depense cd
     LEFT JOIN depense d ON d.id_categorie = cd.id_categorie
     GROUP BY cd.id_categorie
     ORDER BY cd.libelle"
);

$etab_brut = get_etablissement();
$etab      = etab_pour_pdf($etab_brut);

// Enveloppé dans un try/catch — voir fonctions.php::pdf_erreur_generation()
// (jamais de fatal error brut ; ce document est public via QR et/ou
// consulté par du personnel qui ne doit pas voir de trace technique).
try {
$pdf = new FPDF('P', 'mm', 'A4');
$pdf->SetMargins(12, 10, 12);
$pdf->SetAutoPageBreak(true, 15);
$pdf->AddPage();
$pw = $pdf->GetPageWidth();
$ph = $pdf->GetPageHeight();

pdf_filigrane($pdf, $etab, $pw, $ph);
pdf_entete($pdf, $etab, $pw, 12);
pdf_bandeau($pdf, 'CATALOGUE DES CATEGORIES DE DEPENSES', 'EXPENSE CATEGORIES', $pw, 12);

$pdf->SetFont('Arial', '', 8.5);
$pdf->Cell(0, 5, pdf_u('Total : ' . count($categories) . ' catégorie(s)'), 0, 1, 'L');
$pdf->Ln(2);

$w = [50, 66, 22, 34];
$pdf->SetFont('Arial', 'B', 7.5);
$pdf->SetFillColor(220, 38, 38); $pdf->SetTextColor(255, 255, 255);
$pdf->Cell($w[0], 6, pdf_u('Libellé'), 1, 0, 'C', true);
$pdf->Cell($w[1], 6, pdf_u('Description'), 1, 0, 'C', true);
$pdf->Cell($w[2], 6, pdf_u('Nb'), 1, 0, 'C', true);
$pdf->Cell($w[3], 6, pdf_u('Total dépensé'), 1, 1, 'C', true);
$pdf->SetTextColor(0); $pdf->SetFont('Arial', '', 7.5);

$total_general = 0.0;
foreach ($categories as $c) {
    if ($pdf->GetY() > $ph - 20) { $pdf->AddPage(); pdf_filigrane($pdf, $etab, $pw, $ph); }
    $pdf->Cell($w[0], 5, pdf_u($c['libelle']), 1, 0, 'L');
    $pdf->Cell($w[1], 5, pdf_u($c['description'] ?: '—'), 1, 0, 'L');
    $pdf->Cell($w[2], 5, (string) $c['nb_depenses'], 1, 0, 'C');
    $pdf->Cell($w[3], 5, number_format((float) $c['total_depense'], 0, ',', ' '), 1, 1, 'R');
    $total_general += (float) $c['total_depense'];
}
if (!$categories) {
    $pdf->SetFont('Arial', 'I', 8);
    $pdf->Cell(array_sum($w), 6, pdf_u('Aucune catégorie de dépense.'), 1, 1, 'C');
} else {
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->SetFillColor(252, 226, 226);
    $pdf->Cell($w[0] + $w[1] + $w[2], 5.5, pdf_u('TOTAL GÉNÉRAL'), 1, 0, 'R', true);
    $pdf->Cell($w[3], 5.5, number_format($total_general, 0, ',', ' '), 1, 1, 'R', true);
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
$pdf->Cell($w_sign, 5, pdf_u(mb_strtoupper($etab['chef_etablissement'] ?: 'LE DIRECTEUR') . ','), 0, 1, 'R');

pdf_copyright($pdf, $pw, $ph);
$pdf->Output($dl ? 'D' : 'I', 'categories_depenses.pdf');
} catch (Throwable $e) {
    pdf_erreur_generation($e);
}
