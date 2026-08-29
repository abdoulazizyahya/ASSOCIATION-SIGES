<?php
// pdf/finances_cas_sociaux.php — Liste PDF des élèves "Cas social", mêmes
// filtres que pages/finances/cas_sociaux.php.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
exiger_role(['DIRECTEUR', 'SECRETAIRE', 'COMPTABLE']);

require_once __DIR__ . '/fpdf.php';
require_once __DIR__ . '/header_pdf.php';

$dl          = ($_GET['dl'] ?? '0') === '1';
$niveau_f    = $_GET['niveau'] ?? '';
$id_classe_f = (int) ($_GET['classe'] ?? 0);

$annee     = get_annee_active();
$val_annee = $annee['val_annee'] ?? '';

$paye_par_eleve = [];
foreach (db_all("SELECT id_eleve, SUM(montant_paiement) AS paye FROM paiement_frais WHERE val_annee=? GROUP BY id_eleve", [$val_annee]) as $r) {
    $paye_par_eleve[(int) $r['id_eleve']] = (float) $r['paye'];
}

$tous_eleves = finances_du_par_eleve($val_annee, $id_classe_f ?: null, $id_classe_f ? null : ($niveau_f ?: null));

$cas_sociaux = [];
$total_normal = 0.0; $total_du = 0.0; $total_paye = 0.0;
foreach ($tous_eleves as $e) {
    if (!$e['cas_social']) continue;
    $paye = $paye_par_eleve[(int) $e['id_eleve']] ?? 0.0;
    $cas_sociaux[] = $e + ['paye' => $paye, 'solde' => $e['du'] - $paye];
    $total_normal += $e['montant_normal'];
    $total_du     += $e['du'];
    $total_paye   += $paye;
}
usort($cas_sociaux, fn($a, $b) => $b['pourcentage'] <=> $a['pourcentage']);

$classe_nom = $id_classe_f ? (string) db_val("SELECT DesignationClasses FROM classe WHERE IDClasses=?", [$id_classe_f]) : '';

$etab_brut = get_etablissement();
$etab      = etab_pour_pdf($etab_brut);

// Enveloppé dans un try/catch — voir fonctions.php::pdf_erreur_generation()
// (jamais de fatal error brut).
try {
$pdf = new FPDF('P', 'mm', 'A4');
$pdf->SetMargins(12, 10, 12);
$pdf->SetAutoPageBreak(true, 15);
$pdf->AddPage();
$pw = $pdf->GetPageWidth();
$ph = $pdf->GetPageHeight();

pdf_filigrane($pdf, $etab, $pw, $ph);
pdf_entete($pdf, $etab, $pw, 12);
pdf_bandeau($pdf, 'ÉLÈVES CAS SOCIAUX', 'STUDENTS ON FEE REDUCTION', $pw, 12);

$pdf->SetFont('Arial', '', 8.5);
$sous_titre = 'Année scolaire : ' . $val_annee;
if ($niveau_f !== '') $sous_titre .= '   —   Niveau ' . $niveau_f;
if ($classe_nom)      $sous_titre .= '   —   Classe ' . $classe_nom;
$pdf->Cell(0, 5, pdf_u($sous_titre), 0, 1, 'L');
$pdf->Ln(2);

$w = [8, 22, 55, 25, 18, 24, 24, 24];
$pdf->SetFont('Arial', 'B', 7);
$pdf->SetFillColor(30, 79, 216); $pdf->SetTextColor(255, 255, 255);
$pdf->Cell($w[0], 6, 'N°', 1, 0, 'C', true);
$pdf->Cell($w[1], 6, pdf_u('Matricule'), 1, 0, 'C', true);
$pdf->Cell($w[2], 6, pdf_u('Nom et prénom'), 1, 0, 'C', true);
$pdf->Cell($w[3], 6, pdf_u('Classe'), 1, 0, 'C', true);
$pdf->Cell($w[4], 6, pdf_u('Réduc.'), 1, 0, 'C', true);
$pdf->Cell($w[5], 6, pdf_u('Dû'), 1, 0, 'C', true);
$pdf->Cell($w[6], 6, pdf_u('Payé'), 1, 0, 'C', true);
$pdf->Cell($w[7], 6, pdf_u('Solde'), 1, 1, 'C', true);
$pdf->SetTextColor(0); $pdf->SetFont('Arial', '', 7);

$n = 0;
foreach ($cas_sociaux as $l) {
    $n++;
    if ($pdf->GetY() > $ph - 20) { $pdf->AddPage(); pdf_filigrane($pdf, $etab, $pw, $ph); }
    $pdf->Cell($w[0], 5, (string) $n, 1, 0, 'C');
    $pdf->Cell($w[1], 5, pdf_u($l['Mat_elv']), 1, 0, 'C');
    $pdf->Cell($w[2], 5, pdf_u($l['Nom_elv'] . ' ' . ($l['Prenom_elv'] ?? '')), 1, 0, 'L');
    $pdf->Cell($w[3], 5, pdf_u($l['DesignationClasses']), 1, 0, 'C');
    $pdf->Cell($w[4], 5, '-' . rtrim(rtrim(number_format($l['pourcentage'], 2, '.', ''), '0'), '.') . '%', 1, 0, 'C');
    $pdf->Cell($w[5], 5, number_format($l['du'], 0, ',', ' '), 1, 0, 'R');
    $pdf->Cell($w[6], 5, number_format($l['paye'], 0, ',', ' '), 1, 0, 'R');
    $pdf->Cell($w[7], 5, number_format($l['solde'], 0, ',', ' '), 1, 1, 'R');
}
if (!$cas_sociaux) {
    $pdf->SetFont('Arial', 'I', 9);
    $pdf->Cell(array_sum($w), 8, pdf_u('Aucun cas social pour cette sélection.'), 1, 1, 'C');
} else {
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->SetFillColor(214, 234, 248);
    $pdf->Cell($w[0] + $w[1] + $w[2] + $w[3] + $w[4], 6, pdf_u('TOTAL (' . count($cas_sociaux) . ' élève(s))'), 1, 0, 'R', true);
    $pdf->Cell($w[5], 6, number_format($total_du, 0, ',', ' '), 1, 0, 'R', true);
    $pdf->Cell($w[6], 6, number_format($total_paye, 0, ',', ' '), 1, 0, 'R', true);
    $pdf->Cell($w[7], 6, number_format($total_du - $total_paye, 0, ',', ' '), 1, 1, 'R', true);
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

if (($_GET['signature'] ?? '0') === '1') {
    pdf_signature_appliquer_jn($pdf, 'finances_cas_sociaux', 0, 0, $pw, $ph, [
        'x_pct' => ($x_sign + ($w_sign - 22) / 2) / $pw * 100,
        'y_pct' => ($pdf->GetY() + 1) / $ph * 100,
        'w_pct' => 22 / $pw * 100, 'h_pct' => null,
    ]);
}

pdf_copyright($pdf, $pw, $ph);
$pdf->Output($dl ? 'D' : 'I', 'cas_sociaux_' . date('Ymd') . '.pdf');
} catch (Throwable $e) {
    pdf_erreur_generation($e);
}
