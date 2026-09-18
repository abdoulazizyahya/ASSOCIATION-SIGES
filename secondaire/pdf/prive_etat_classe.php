<?php
// secondaire/pdf/prive_etat_classe.php — État PDF des paiements PRIVÉS
// d'une classe — porté de pdf/finances_etat_classe.php (primaire), adapté
// au schéma secondaire.
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'SECRETAIRE', 'INTENDANT']);

require_once __DIR__ . '/fpdf.php';
require_once __DIR__ . '/header_pdf.php';

$id_classe = (int) ($_GET['classe'] ?? 0);
$dl        = ($_GET['dl'] ?? '0') === '1';
$classe    = db_one("SELECT * FROM classe WHERE id=?", [$id_classe]);
if (!$classe) die('Classe introuvable.');

$annee     = get_annee_active();
$id_annee  = (int) ($annee['id'] ?? 0);
$val_annee = $annee['val_annee'] ?? ($annee['libelle'] ?? '');

$eleves = prive_finances_du_par_eleve($id_annee, $id_classe);
$montant_du_niveau = $eleves[0]['du'] ?? 0.0;
$payes = [];
foreach (db_all(
    "SELECT id_eleve, SUM(montant_paiement) AS paye FROM paiement_prive WHERE id_classe=? AND id_annee=? GROUP BY id_eleve",
    [$id_classe, $id_annee]
) as $r) { $payes[(int) $r['id_eleve']] = (float) $r['paye']; }

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
pdf_bandeau($pdf, 'ÉTAT DES PAIEMENTS PRIVÉS — ' . mb_strtoupper($classe['designation']), 'PRIVATE FEE PAYMENT STATUS', $pw, 12);

$pdf->SetFont('Arial', '', 8.5);
$pdf->Cell(0, 5, pdf_u('Année scolaire : ' . $val_annee . '   —   Frais dû par élève (toutes obligations) : ' . number_format($montant_du_niveau, 0, ',', ' ') . ' FCFA'), 0, 1, 'L');
$pdf->Ln(2);

$w = [8, 24, 63, 25, 25, 22, 13];
$pdf->SetFont('Arial', 'B', 7.5);
$pdf->SetFillColor(30, 79, 216); $pdf->SetTextColor(255, 255, 255);
$pdf->Cell($w[0], 6, 'N°', 1, 0, 'C', true);
$pdf->Cell($w[1], 6, pdf_u('Matricule'), 1, 0, 'C', true);
$pdf->Cell($w[2], 6, pdf_u('Nom et prénom'), 1, 0, 'C', true);
$pdf->Cell($w[3], 6, pdf_u('Dû'), 1, 0, 'C', true);
$pdf->Cell($w[4], 6, pdf_u('Payé'), 1, 0, 'C', true);
$pdf->Cell($w[5], 6, pdf_u('Solde'), 1, 0, 'C', true);
$pdf->Cell($w[6], 6, pdf_u('Statut'), 1, 1, 'C', true);
$pdf->SetTextColor(0); $pdf->SetFont('Arial', '', 7.5);

$n = 0; $total_paye = 0.0; $total_du = 0.0;
foreach ($eleves as $e) {
    $n++;
    $paye  = $payes[(int) $e['id']] ?? 0.0;
    $solde = $e['du'] - $paye;
    $total_paye += $paye;
    $total_du   += $e['du'];
    $statut = $solde <= 0 ? 'Soldé' : ($paye > 0 ? 'Partiel' : 'Impayé');
    $nom = $e['nom'] . ' ' . ($e['prenom'] ?? '');
    $pdf->Cell($w[0], 5, (string) $n, 1, 0, 'C');
    $pdf->Cell($w[1], 5, pdf_u($e['matricule']), 1, 0, 'C');
    $pdf->Cell($w[2], 5, pdf_u($nom), 1, 0, 'L');
    $pdf->Cell($w[3], 5, number_format($e['du'], 0, ',', ' '), 1, 0, 'R');
    $pdf->Cell($w[4], 5, number_format($paye, 0, ',', ' '), 1, 0, 'R');
    $pdf->Cell($w[5], 5, number_format($solde, 0, ',', ' '), 1, 0, 'R');
    $pdf->Cell($w[6], 5, pdf_u($statut), 1, 1, 'C');
}
$pdf->SetFont('Arial', 'B', 7.5);
$pdf->SetFillColor(214, 234, 248);
$pdf->Cell($w[0] + $w[1] + $w[2], 6, pdf_u('TOTAL (' . count($eleves) . ' élève(s))'), 1, 0, 'R', true);
$pdf->Cell($w[3], 6, number_format($total_du, 0, ',', ' '), 1, 0, 'R', true);
$pdf->Cell($w[4], 6, number_format($total_paye, 0, ',', ' '), 1, 0, 'R', true);
$pdf->Cell($w[5], 6, number_format($total_du - $total_paye, 0, ',', ' '), 1, 0, 'R', true);
$pdf->Cell($w[6], 6, '', 1, 1, 'C', true);

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
$pdf->Output($dl ? 'D' : 'I', 'etat_paiements_prive_' . preg_replace('/[^A-Za-z0-9]/', '_', $classe['designation']) . '.pdf');
} catch (Throwable $e) {
    pdf_erreur_generation($e);
}
