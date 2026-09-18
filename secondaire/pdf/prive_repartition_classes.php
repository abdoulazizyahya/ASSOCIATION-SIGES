<?php
// secondaire/pdf/prive_repartition_classes.php — Répartition PDF des
// encaissements PRIVÉS par classe — porté de
// pdf/finances_repartition_classes.php (primaire), adapté au schéma
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

$classes = db_all(
    "SELECT c.id, c.designation, c.code_niveau, n.ordre_niveau FROM classe c
     LEFT JOIN niveau n ON n.code_niveau = c.code_niveau
     WHERE c.archivee=0 ORDER BY n.ordre_niveau, c.designation"
);
$nb_par_classe = [];
foreach (db_all(
    "SELECT i.id_classe, COUNT(DISTINCT i.id_eleve) AS nb FROM inscription i
     JOIN eleve e ON e.id = i.id_eleve AND e.statut='actif'
     WHERE i.id_annee = ? GROUP BY i.id_classe",
    [$id_annee]
) as $r) { $nb_par_classe[(int) $r['id_classe']] = (int) $r['nb']; }
$paye_par_classe = [];
foreach (db_all(
    "SELECT id_classe, SUM(montant_paiement) AS paye FROM paiement_prive WHERE id_annee = ? GROUP BY id_classe",
    [$id_annee]
) as $r) { $paye_par_classe[(int) $r['id_classe']] = (float) $r['paye']; }

$du_par_classe = [];
foreach (prive_finances_du_par_eleve($id_annee) as $e) {
    $du_par_classe[(int) $e['id_classe']] = ($du_par_classe[(int) $e['id_classe']] ?? 0.0) + $e['du'];
}

$lignes = [];
$total_apport_general = 0.0;
$total_du_general     = 0.0;
foreach ($classes as $c) {
    $id_classe = (int) $c['id'];
    $nb        = $nb_par_classe[$id_classe] ?? 0;
    $apport    = $paye_par_classe[$id_classe] ?? 0.0;
    $du        = $du_par_classe[$id_classe] ?? 0.0;
    $lignes[]  = ['classe' => $c['designation'], 'nb' => $nb, 'du' => $du, 'apport' => $apport];
    $total_apport_general += $apport;
    $total_du_general     += $du;
}
foreach ($lignes as &$l) {
    $l['pct']  = $total_apport_general > 0 ? round($l['apport'] / $total_apport_general * 100, 1) : 0.0;
    $l['taux'] = $l['du'] > 0 ? round($l['apport'] / $l['du'] * 100, 1) : 0.0;
}
unset($l);
usort($lignes, fn($a, $b) => $b['apport'] <=> $a['apport']);

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
pdf_bandeau($pdf, 'REPARTITION DES ENCAISSEMENTS PRIVES PAR CLASSE', 'PRIVATE REVENUE BREAKDOWN BY CLASS', $pw, 12);

$pdf->SetFont('Arial', '', 8.5);
$pdf->Cell(0, 5, pdf_u('Année scolaire : ' . $val_annee . ' — Total encaissé : ' . number_format($total_apport_general, 0, ',', ' ') . ' F'), 0, 1, 'L');
$pdf->Ln(2);

$w = [46, 20, 30, 34, 26, 20];
$pdf->SetFont('Arial', 'B', 7.5);
$pdf->SetFillColor(30, 79, 216); $pdf->SetTextColor(255, 255, 255);
$pdf->Cell($w[0], 6, pdf_u('Classe'), 1, 0, 'C', true);
$pdf->Cell($w[1], 6, pdf_u('Élèves'), 1, 0, 'C', true);
$pdf->Cell($w[2], 6, pdf_u('Dû'), 1, 0, 'C', true);
$pdf->Cell($w[3], 6, pdf_u('Apport (encaissé)'), 1, 0, 'C', true);
$pdf->Cell($w[4], 6, pdf_u('Recouvrement'), 1, 0, 'C', true);
$pdf->Cell($w[5], 6, pdf_u('% du total'), 1, 1, 'C', true);
$pdf->SetTextColor(0); $pdf->SetFont('Arial', '', 7.5);

foreach ($lignes as $l) {
    if ($pdf->GetY() > $ph - 20) { $pdf->AddPage(); pdf_filigrane($pdf, $etab, $pw, $ph); }
    $pdf->Cell($w[0], 5, pdf_u($l['classe']), 1, 0, 'L');
    $pdf->Cell($w[1], 5, (string) $l['nb'], 1, 0, 'C');
    $pdf->Cell($w[2], 5, number_format($l['du'], 0, ',', ' '), 1, 0, 'R');
    $pdf->Cell($w[3], 5, number_format($l['apport'], 0, ',', ' '), 1, 0, 'R');
    $pdf->Cell($w[4], 5, $l['taux'] . ' %', 1, 0, 'C');
    $pdf->Cell($w[5], 5, $l['pct'] . ' %', 1, 1, 'C');
}
if (!$lignes) {
    $pdf->SetFont('Arial', 'I', 8);
    $pdf->Cell(array_sum($w), 6, pdf_u('Aucune classe pour cette année.'), 1, 1, 'C');
} else {
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->SetFillColor(214, 234, 248);
    $pdf->Cell($w[0] + $w[1], 5.5, pdf_u('TOTAL (' . count($lignes) . ' classe(s))'), 1, 0, 'R', true);
    $pdf->Cell($w[2], 5.5, number_format($total_du_general, 0, ',', ' '), 1, 0, 'R', true);
    $pdf->Cell($w[3], 5.5, number_format($total_apport_general, 0, ',', ' '), 1, 0, 'R', true);
    $pdf->Cell($w[4] + $w[5], 5.5, '100 %', 1, 1, 'C', true);
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
$pdf->Output($dl ? 'D' : 'I', 'repartition_classes_prive_' . preg_replace('/[^A-Za-z0-9]/', '_', $val_annee) . '.pdf');
} catch (Throwable $e) {
    pdf_erreur_generation($e);
}
