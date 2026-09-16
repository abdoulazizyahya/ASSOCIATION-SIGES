<?php
// secondaire/pages/paiements/pdf_statut.php — PDF de l'onglet "Statut par frais"
// (secondaire/pages/paiements/rapport.php?onglet=statut) : élèves d'une classe ayant
// réglé un frais précis, et ceux ne l'ayant pas réglé, sur le même modèle
// que pdf_insolvables.php (signature Intendant optionnelle, jamais
// automatique).
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'CENSEUR', 'INTENDANT']);
require_once __DIR__ . '/../../pdf/fpdf.php';
require_once __DIR__ . '/../../pdf/header_pdf.php';

$annee_active  = get_annee_active();
$id_annee      = (int)($annee_active['id'] ?? 0);
$id_classe     = (int)($_GET['classe']     ?? 0);
$id_obligation = (int)($_GET['obligation'] ?? 0);
$dl            = ($_GET['dl'] ?? '0') === '1';

$classe     = db_one("SELECT * FROM classe WHERE id=?", [$id_classe]);
$obligation = db_one("SELECT * FROM obligation_frais WHERE id=?", [$id_obligation]);
if (!$classe || !$obligation) { die('Classe ou frais introuvable.'); }

$eleves = db_all(
    "SELECT e.*,
            (SELECT SUM(p.montant) FROM paiement_frais p WHERE p.id_eleve=e.id AND p.id_obligation=? AND p.id_annee=?) AS paye,
            (SELECT MAX(p.date_paiement) FROM paiement_frais p WHERE p.id_eleve=e.id AND p.id_obligation=? AND p.id_annee=?) AS derniere_date,
            (SELECT op.libelle FROM paiement_frais p JOIN operateur_paiement op ON op.id=p.id_operateur
             WHERE p.id_eleve=e.id AND p.id_obligation=? AND p.id_annee=? ORDER BY p.id DESC LIMIT 1) AS dernier_operateur
     FROM eleve e
     JOIN inscription i ON i.id_eleve=e.id AND i.id_classe=? AND i.id_annee=?
     WHERE e.statut='actif' ORDER BY e.nom, e.prenom",
    [$id_obligation, $id_annee, $id_obligation, $id_annee, $id_obligation, $id_annee, $id_classe, $id_annee]
);
$ont_paye = []; $nont_pas_paye = [];
foreach ($eleves as $e) {
    $paye  = (float) ($e['paye'] ?? 0);
    $solde = round((float) $obligation['montant'] - $paye, 2);
    if ($solde <= 0) { $e['paye'] = $paye; $ont_paye[] = $e; }
    else { $e['solde'] = $solde; $nont_pas_paye[] = $e; }
}

$etab = get_etablissement();
$pdf  = new FPDF('P', 'mm', 'A4');
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 15);
$pdf->AddPage();
$pw = $pdf->GetPageWidth();
$ml = 10;
$uw = $pw - 20;

pdf_filigrane($pdf, $etab, $pw, $pdf->GetPageHeight());
pdf_entete($pdf, $etab, $pw);
pdf_bandeau($pdf, 'STATUT DE PAIEMENT PAR FRAIS', 'Payment Status by Fee', $pw);

$pdf->SetFont('Arial', 'B', 8.5);
$pdf->SetX($ml);
$pdf->Cell($uw, 5, pdf_u(
    'Classe : ' . $classe['designation'] . '   —   Frais : ' . $obligation['libelle'] .
    '   —   Montant : ' . number_format((float)$obligation['montant'], 0, ',', ' ') . ' F' .
    '   —   Effectif : ' . count($eleves)
), 0, 1);
$pdf->Ln(2);

$pdf->SetFont('Arial', 'B', 9);
$pdf->SetX($ml);
$pdf->Cell($uw, 6, pdf_u('ONT PAYÉ (' . count($ont_paye) . ')'), 0, 1);
$col_w = [10, $uw - 10 - 30 - 28 - 30, 30, 28, 30];
$pdf->SetFillColor(60, 160, 90);
$pdf->SetTextColor(255);
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetX($ml);
$pdf->Cell($col_w[0], 6, pdf_u('N°'), 1, 0, 'C', true);
$pdf->Cell($col_w[1], 6, pdf_u('Nom et prénom'), 1, 0, 'L', true);
$pdf->Cell($col_w[2], 6, pdf_u('Matricule'), 1, 0, 'C', true);
$pdf->Cell($col_w[3], 6, pdf_u('Date'), 1, 0, 'C', true);
$pdf->Cell($col_w[4], 6, pdf_u('Montant'), 1, 1, 'R', true);
$pdf->SetTextColor(0);
$pdf->SetFont('Arial', '', 8);
$fill = false; $no = 1;
foreach ($ont_paye as $e) {
    $pdf->SetFillColor(232, 248, 237);
    $pdf->SetX($ml);
    $pdf->Cell($col_w[0], 5.5, (string)$no, 'B', 0, 'C', $fill);
    $pdf->Cell($col_w[1], 5.5, pdf_u($e['nom'] . ' ' . ($e['prenom'] ?? '')), 'B', 0, 'L', $fill);
    $pdf->Cell($col_w[2], 5.5, pdf_u($e['matricule']), 'B', 0, 'C', $fill);
    $pdf->Cell($col_w[3], 5.5, $e['derniere_date'] ? date('d/m/Y', strtotime($e['derniere_date'])) : '', 'B', 0, 'C', $fill);
    $pdf->Cell($col_w[4], 5.5, number_format($e['paye'], 0, ',', ' '), 'B', 1, 'R', $fill);
    $fill = !$fill; $no++;
}
if (!$ont_paye) {
    $pdf->SetX($ml);
    $pdf->Cell($uw, 6, pdf_u('Aucun élève à jour pour ce frais.'), 1, 1, 'C');
}
$pdf->Ln(4);

$pdf->SetFont('Arial', 'B', 9);
$pdf->SetX($ml);
$pdf->Cell($uw, 6, pdf_u("N'ONT PAS PAYÉ (" . count($nont_pas_paye) . ')'), 0, 1);
$col_w2 = [10, $uw - 10 - 40 - 30, 40, 30];
$pdf->SetFillColor(200, 60, 60);
$pdf->SetTextColor(255);
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetX($ml);
$pdf->Cell($col_w2[0], 6, pdf_u('N°'), 1, 0, 'C', true);
$pdf->Cell($col_w2[1], 6, pdf_u('Nom et prénom'), 1, 0, 'L', true);
$pdf->Cell($col_w2[2], 6, pdf_u('Matricule'), 1, 0, 'C', true);
$pdf->Cell($col_w2[3], 6, pdf_u('Solde dû'), 1, 1, 'R', true);
$pdf->SetTextColor(0);
$pdf->SetFont('Arial', '', 8);
$fill = false; $no = 1; $total_du = 0;
foreach ($nont_pas_paye as $e) {
    $pdf->SetFillColor(253, 235, 235);
    $pdf->SetX($ml);
    $pdf->Cell($col_w2[0], 5.5, (string)$no, 'B', 0, 'C', $fill);
    $pdf->Cell($col_w2[1], 5.5, pdf_u($e['nom'] . ' ' . ($e['prenom'] ?? '')), 'B', 0, 'L', $fill);
    $pdf->Cell($col_w2[2], 5.5, pdf_u($e['matricule']), 'B', 0, 'C', $fill);
    $pdf->Cell($col_w2[3], 5.5, number_format($e['solde'], 0, ',', ' '), 'B', 1, 'R', $fill);
    $total_du += $e['solde'];
    $fill = !$fill; $no++;
}
if (!$nont_pas_paye) {
    $pdf->SetX($ml);
    $pdf->Cell($uw, 6, pdf_u('Tous les élèves de cette classe sont à jour pour ce frais.'), 1, 1, 'C');
} else {
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetX($ml);
    $pdf->Cell($col_w2[0] + $col_w2[1] + $col_w2[2], 6, pdf_u('TOTAL DÛ'), 1, 0, 'R');
    $pdf->Cell($col_w2[3], 6, number_format($total_du, 0, ',', ' '), 1, 1, 'R');
}

// ── Lieu, date et signature de l'Intendant (bas de page, à droite) ────
$ph = $pdf->GetPageHeight();
$pdf->Ln(10);
if ($pdf->GetY() > $ph - 30) $pdf->AddPage();
$w_sign = $uw * 0.4;
$x_sign = $pw - 25 - $w_sign;
$pdf->SetFont('Arial', '', 9);
$pdf->SetX($x_sign);
$pdf->Cell($w_sign, 5, pdf_u('Fait à/Done at ' . ($etab['ville'] ?? '') . ', le/on ' . date('d/m/Y')), 0, 1, 'R');
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetX($x_sign);
$pdf->Cell($w_sign, 5, pdf_u("L'INTENDANT,"), 0, 1, 'R');

if (($_GET['signature'] ?? '0') === '1') {
    $sig_w = 24;
    $sy = $pdf->GetY() + 1;
    $sx = $x_sign + ($w_sign - $sig_w) / 2;
    pdf_signature_appliquer($pdf, 'statut_paiement', 'intendant', 0, 0, $pw, $ph, [
        'x_pct' => $sx / $pw * 100, 'y_pct' => $sy / $ph * 100, 'w_pct' => $sig_w / $pw * 100, 'h_pct' => null,
    ]);
}

$pdf->Output($dl ? 'D' : 'I', 'statut_' . preg_replace('/\s+/', '_', $classe['designation']) . '.pdf');
