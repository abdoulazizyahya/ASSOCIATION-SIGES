<?php
// pdf/finances_etat_classe.php — État PDF des paiements d'une classe
// (mirroir de list_of_payment.php du vrai jaynitaare legacy). Style simple
// (comme pdf/fiche_eleve.php), pas le style MANWI/ABZ_MBE (pdf_pilule,
// dégradés, reçus multi-copies) — volontairement pas utilisé ici.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
exiger_role(['DIRECTEUR', 'SECRETAIRE', 'COMPTABLE']);

require_once __DIR__ . '/fpdf.php';
require_once __DIR__ . '/header_pdf.php';

$id_classe = (int) ($_GET['classe'] ?? 0);
$dl        = ($_GET['dl'] ?? '0') === '1';
$classe    = db_one("SELECT * FROM classe WHERE IDClasses=?", [$id_classe]);
if (!$classe) die('Classe introuvable.');

$annee     = get_annee_active();
$val_annee = $annee['val_annee'] ?? '';

// Montant dû par élève (après réduction "Cas social" éventuelle,
// migration_v39) — voir finances_du_par_eleve() (fonctions.php).
$eleves = finances_du_par_eleve($val_annee, $id_classe);
$montant_normal_niveau = $eleves[0]['montant_normal'] ?? 0.0;
$payes = [];
foreach (db_all(
    "SELECT id_eleve, SUM(montant_paiement) AS paye FROM paiement_frais WHERE classe=? AND val_annee=? GROUP BY id_eleve",
    [$id_classe, $val_annee]
) as $r) { $payes[(int) $r['id_eleve']] = (float) $r['paye']; }

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
pdf_bandeau($pdf, 'ÉTAT DES PAIEMENTS — ' . mb_strtoupper($classe['DesignationClasses']), 'FEE PAYMENT STATUS', $pw, 12);

$pdf->SetFont('Arial', '', 8.5);
$pdf->Cell(0, 5, pdf_u('Année scolaire : ' . $val_annee . '   —   Frais normaux par élève (toutes obligations) : ' . number_format($montant_normal_niveau, 0, ',', ' ') . ' FCFA (avant réduction "Cas social" éventuelle)'), 0, 1, 'L');
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
    $paye  = $payes[(int) $e['id_eleve']] ?? 0.0;
    $solde = $e['du'] - $paye;
    $total_paye += $paye;
    $total_du   += $e['du'];
    $statut = $solde <= 0 ? 'Soldé' : ($paye > 0 ? 'Partiel' : 'Impayé');
    $nom = $e['Nom_elv'] . ' ' . ($e['Prenom_elv'] ?? '') . ($e['cas_social'] ? ' (Cas social -' . rtrim(rtrim(number_format($e['pourcentage'], 2, '.', ''), '0'), '.') . '%)' : '');
    $pdf->Cell($w[0], 5, (string) $n, 1, 0, 'C');
    $pdf->Cell($w[1], 5, pdf_u($e['Mat_elv']), 1, 0, 'C');
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

// ── Lieu, date et signature (bas de page) ─────────────────────
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
    pdf_signature_appliquer_jn($pdf, 'finances_etat_classe', 0, 0, $pw, $ph, [
        'x_pct' => ($x_sign + ($w_sign - 22) / 2) / $pw * 100,
        'y_pct' => ($pdf->GetY() + 1) / $ph * 100,
        'w_pct' => 22 / $pw * 100, 'h_pct' => null,
    ]);
}

pdf_copyright($pdf, $pw, $ph);
$pdf->Output($dl ? 'D' : 'I', 'etat_paiements_' . preg_replace('/[^A-Za-z0-9]/', '_', $classe['DesignationClasses']) . '.pdf');
} catch (Throwable $e) {
    pdf_erreur_generation($e);
}
