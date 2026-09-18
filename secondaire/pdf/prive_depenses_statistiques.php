<?php
// secondaire/pdf/prive_depenses_statistiques.php — Bilan des dépenses
// PRIVÉES PDF (encaissé/dépensé/solde + par catégorie + évolution
// mensuelle) — porté de pdf/depenses_statistiques.php (primaire), adapté
// au schéma secondaire.
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'INTENDANT']);

require_once __DIR__ . '/fpdf.php';
require_once __DIR__ . '/header_pdf.php';

$dl = ($_GET['dl'] ?? '0') === '1';

$annee     = get_annee_active();
$id_annee  = (int) ($annee['id'] ?? 0);
$val_annee = $annee['val_annee'] ?? ($annee['libelle'] ?? '');

$total_encaisse = (float) (db_val("SELECT COALESCE(SUM(montant_paiement),0) FROM paiement_prive WHERE id_annee=?", [$id_annee]) ?? 0);
$total_depense  = (float) (db_val("SELECT COALESCE(SUM(montant),0) FROM depense_privee WHERE id_annee=?", [$id_annee]) ?? 0);
$solde          = $total_encaisse - $total_depense;

$par_categorie = db_all(
    "SELECT cd.libelle, COUNT(d.id) AS nb, COALESCE(SUM(d.montant),0) AS total
     FROM categorie_depense_privee cd
     LEFT JOIN depense_privee d ON d.id_categorie = cd.id AND d.id_annee = ?
     GROUP BY cd.id
     ORDER BY total DESC",
    [$id_annee]
);

$mois_fr = [1=>'Janv', 2=>'Févr', 3=>'Mars', 4=>'Avr', 5=>'Mai', 6=>'Juin', 7=>'Juil', 8=>'Août', 9=>'Sept', 10=>'Oct', 11=>'Nov', 12=>'Déc'];
$par_mois = [];
foreach (db_all(
    "SELECT DATE_FORMAT(date_depense,'%Y-%m') AS mois, COUNT(*) AS nb, SUM(montant) AS total FROM depense_privee
     WHERE id_annee=? GROUP BY mois ORDER BY mois",
    [$id_annee]
) as $r) {
    $num = (int) substr($r['mois'], 5, 2);
    $par_mois[] = ['label' => ($mois_fr[$num] ?? $r['mois']) . ' ' . substr($r['mois'], 0, 4), 'nb' => (int) $r['nb'], 'total' => (float) $r['total']];
}

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
pdf_bandeau($pdf, 'BILAN DES DEPENSES — PAIEMENT PRIVE', 'EXPENSE STATEMENT — PRIVATE', $pw, 12);

$pdf->SetFont('Arial', '', 8.5);
$pdf->Cell(0, 5, pdf_u('Année scolaire : ' . $val_annee), 0, 1, 'L');
$pdf->Ln(2);

$w3 = ($pw - 24) / 3;
$pdf->SetFont('Arial', 'B', 8);
foreach ([['TOTAL ENCAISSÉ', $total_encaisse], ['TOTAL DÉPENSÉ', $total_depense], ['SOLDE DE CAISSE', $solde]] as [$lbl, $val]) {
    $pdf->SetFillColor(226, 232, 240);
    $pdf->Cell($w3, 6, pdf_u($lbl), 1, 0, 'C', true);
}
$pdf->Ln();
$pdf->SetFont('Arial', '', 11);
$pdf->SetTextColor(21, 128, 61);
$pdf->Cell($w3, 8, number_format($total_encaisse, 0, ',', ' ') . ' F', 1, 0, 'C');
$pdf->SetTextColor(220, 38, 38);
$pdf->Cell($w3, 8, number_format($total_depense, 0, ',', ' ') . ' F', 1, 0, 'C');
$pdf->SetTextColor($solde >= 0 ? 30 : 220, $solde >= 0 ? 79 : 38, $solde >= 0 ? 216 : 38);
$pdf->Cell($w3, 8, number_format($solde, 0, ',', ' ') . ' F', 1, 1, 'C');
$pdf->SetTextColor(0);
$pdf->Ln(4);

$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(0, 6, pdf_u('Répartition par catégorie'), 0, 1, 'L');
$w = [70, 24, 44, 34];
$pdf->SetFont('Arial', 'B', 7.5);
$pdf->SetFillColor(220, 38, 38); $pdf->SetTextColor(255, 255, 255);
$pdf->Cell($w[0], 6, pdf_u('Catégorie'), 1, 0, 'C', true);
$pdf->Cell($w[1], 6, pdf_u('Nb'), 1, 0, 'C', true);
$pdf->Cell($w[2], 6, pdf_u('Total'), 1, 0, 'C', true);
$pdf->Cell($w[3], 6, pdf_u('% du total'), 1, 1, 'C', true);
$pdf->SetTextColor(0); $pdf->SetFont('Arial', '', 7.5);
foreach ($par_categorie as $c) {
    $pct = $total_depense > 0 ? round((float) $c['total'] / $total_depense * 100, 1) : 0;
    $pdf->Cell($w[0], 5, pdf_u($c['libelle']), 1, 0, 'L');
    $pdf->Cell($w[1], 5, (string) $c['nb'], 1, 0, 'C');
    $pdf->Cell($w[2], 5, number_format((float) $c['total'], 0, ',', ' '), 1, 0, 'R');
    $pdf->Cell($w[3], 5, $pct . ' %', 1, 1, 'C');
}
$pdf->Ln(4);

if ($pdf->GetY() > $ph - 60) { $pdf->AddPage(); pdf_filigrane($pdf, $etab, $pw, $ph); }
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(0, 6, pdf_u('Évolution mensuelle des dépenses'), 0, 1, 'L');
$w = [56, 28, 88];
$pdf->SetFont('Arial', 'B', 7.5);
$pdf->SetFillColor(220, 38, 38); $pdf->SetTextColor(255, 255, 255);
$pdf->Cell($w[0], 6, pdf_u('Mois'), 1, 0, 'C', true);
$pdf->Cell($w[1], 6, pdf_u('Nb'), 1, 0, 'C', true);
$pdf->Cell($w[2], 6, pdf_u('Total dépensé'), 1, 1, 'C', true);
$pdf->SetTextColor(0); $pdf->SetFont('Arial', '', 7.5);
foreach ($par_mois as $m) {
    $pdf->Cell($w[0], 5, pdf_u($m['label']), 1, 0, 'L');
    $pdf->Cell($w[1], 5, (string) $m['nb'], 1, 0, 'C');
    $pdf->Cell($w[2], 5, number_format($m['total'], 0, ',', ' '), 1, 1, 'R');
}
if (!$par_mois) {
    $pdf->SetFont('Arial', 'I', 8);
    $pdf->Cell(array_sum($w), 6, pdf_u('Aucune dépense enregistrée cette année.'), 1, 1, 'C');
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
$pdf->Output($dl ? 'D' : 'I', 'bilan_depenses_prive_' . preg_replace('/[^A-Za-z0-9]/', '_', $val_annee) . '.pdf');
} catch (Throwable $e) {
    pdf_erreur_generation($e);
}
