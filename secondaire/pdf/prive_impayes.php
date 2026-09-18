<?php
// secondaire/pdf/prive_impayes.php — Liste PDF des élèves en impayé PRIVÉ —
// porté de pdf/finances_impayes.php (primaire), adapté au schéma secondaire.
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'SECRETAIRE', 'INTENDANT']);

require_once __DIR__ . '/fpdf.php';
require_once __DIR__ . '/header_pdf.php';

$dl          = ($_GET['dl'] ?? '0') === '1';
$niveau_f    = $_GET['niveau'] ?? '';
$id_classe_f = (int) ($_GET['classe'] ?? 0);
$statut_f    = in_array($_GET['statut'] ?? '', ['impaye', 'partiel'], true) ? $_GET['statut'] : 'tous';

$annee     = get_annee_active();
$id_annee  = (int) ($annee['id'] ?? 0);
$val_annee = $annee['val_annee'] ?? ($annee['libelle'] ?? '');

$paye_par_eleve = [];
foreach (db_all("SELECT id_eleve, SUM(montant_paiement) AS paye FROM paiement_prive WHERE id_annee=? GROUP BY id_eleve", [$id_annee]) as $r) {
    $paye_par_eleve[(int) $r['id_eleve']] = (float) $r['paye'];
}

$tous_eleves = prive_finances_du_par_eleve($id_annee, $id_classe_f ?: null, $id_classe_f ? null : ($niveau_f ?: null));

$impayes = [];
foreach ($tous_eleves as $e) {
    $du    = $e['du'];
    $paye  = $paye_par_eleve[(int) $e['id']] ?? 0.0;
    $solde = $du - $paye;
    if ($solde <= 0.009) continue;
    if ($statut_f === 'impaye' && $paye > 0.009) continue;
    if ($statut_f === 'partiel' && $paye <= 0.009) continue;
    $impayes[] = $e + ['paye' => $paye, 'solde' => $solde];
}
usort($impayes, fn($a, $b) => $b['solde'] <=> $a['solde']);
$total_solde = array_sum(array_column($impayes, 'solde'));

$classe_nom = $id_classe_f ? (string) db_val("SELECT designation FROM classe WHERE id=?", [$id_classe_f]) : '';
$libelles_statut = ['tous' => 'Tous les impayés', 'impaye' => 'Impayé total', 'partiel' => 'Partiel'];

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
pdf_bandeau($pdf, 'ÉLÈVES EN IMPAYÉ — PAIEMENT PRIVÉ', 'UNPAID FEES — PRIVATE', $pw, 12);

$pdf->SetFont('Arial', '', 8.5);
$sous_titre = 'Année scolaire : ' . $val_annee . '   —   Statut : ' . $libelles_statut[$statut_f];
if ($niveau_f !== '') $sous_titre .= '   —   Niveau ' . $niveau_f;
if ($classe_nom)      $sous_titre .= '   —   Classe ' . $classe_nom;
$pdf->Cell(0, 5, pdf_u($sous_titre), 0, 1, 'L');
$pdf->Ln(2);

$w = [10, 28, 65, 25, 25, 27];
$pdf->SetFont('Arial', 'B', 7.5);
$pdf->SetFillColor(30, 79, 216); $pdf->SetTextColor(255, 255, 255);
$pdf->Cell($w[0], 6, 'N°', 1, 0, 'C', true);
$pdf->Cell($w[1], 6, pdf_u('Matricule'), 1, 0, 'C', true);
$pdf->Cell($w[2], 6, pdf_u('Nom et prénom'), 1, 0, 'C', true);
$pdf->Cell($w[3], 6, pdf_u('Classe'), 1, 0, 'C', true);
$pdf->Cell($w[4], 6, pdf_u('Payé'), 1, 0, 'C', true);
$pdf->Cell($w[5], 6, pdf_u('Solde'), 1, 1, 'C', true);
$pdf->SetTextColor(0); $pdf->SetFont('Arial', '', 7.5);

$n = 0;
foreach ($impayes as $i) {
    $n++;
    if ($pdf->GetY() > $ph - 20) { $pdf->AddPage(); pdf_filigrane($pdf, $etab, $pw, $ph); }
    $pdf->Cell($w[0], 5, (string) $n, 1, 0, 'C');
    $pdf->Cell($w[1], 5, pdf_u($i['matricule']), 1, 0, 'C');
    $pdf->Cell($w[2], 5, pdf_u($i['nom'] . ' ' . ($i['prenom'] ?? '')), 1, 0, 'L');
    $pdf->Cell($w[3], 5, pdf_u($i['designation']), 1, 0, 'C');
    $pdf->Cell($w[4], 5, number_format($i['paye'], 0, ',', ' '), 1, 0, 'R');
    $pdf->Cell($w[5], 5, number_format($i['solde'], 0, ',', ' '), 1, 1, 'R');
}
if (!$impayes) {
    $pdf->SetFont('Arial', 'I', 9);
    $pdf->Cell(array_sum($w), 8, pdf_u('Aucun élève en impayé pour cette sélection.'), 1, 1, 'C');
}
$pdf->SetFont('Arial', 'B', 7.5);
$pdf->SetFillColor(214, 234, 248);
$pdf->Cell($w[0] + $w[1] + $w[2] + $w[3] + $w[4], 6, pdf_u('TOTAL RESTANT DÛ (' . count($impayes) . ' élève(s))'), 1, 0, 'R', true);
$pdf->Cell($w[5], 6, number_format($total_solde, 0, ',', ' '), 1, 1, 'R', true);

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
$pdf->Output($dl ? 'D' : 'I', 'impayes_prive_' . date('Ymd') . '.pdf');
} catch (Throwable $e) {
    pdf_erreur_generation($e);
}
