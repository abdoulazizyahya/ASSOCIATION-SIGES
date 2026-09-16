<?php
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_role(['ADMIN','PROVISEUR','CENSEUR','INTENDANT']);
require_once __DIR__ . '../../pdf/fpdf.php';
require_once __DIR__ . '../../pdf/header_pdf.php';

$annee_active  = get_annee_active();
$id_annee      = (int)($annee_active['id'] ?? 0);
$id_classe     = (int)($_GET['classe']     ?? 0);
$id_obligation = (int)($_GET['obligation'] ?? 0);
$dl            = ($_GET['dl'] ?? '0') === '1';

$annee  = $annee_active;
$classe = db_one("SELECT * FROM classe WHERE id=?", [$id_classe]);
if (!$annee || !$classe) { die('Classe ou année introuvable.'); }

$obligation_filtre = $id_obligation ? db_one("SELECT * FROM obligation_frais WHERE id=?", [$id_obligation]) : null;

$eleves = db_all(
    "SELECT e.* FROM eleve e
     JOIN inscription i ON i.id_eleve=e.id AND i.id_classe=? AND i.id_annee=?
     WHERE e.statut='actif' ORDER BY e.nom, e.prenom",
    [$id_classe, $id_annee]
);
$insolvables = [];
foreach ($eleves as $e) {
    if ($id_obligation) {
        $dette = eleve_solde_obligation((int)$e['id'], $id_obligation, $id_annee);
    } else {
        $dette = 0.0;
        foreach (eleve_obligations_annee((int)$e['id'], $classe['code_niveau'], $id_annee) as $o) { $dette += $o['solde']; }
    }
    if ($dette > 0) { $e['dette'] = round($dette, 2); $insolvables[] = $e; }
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
pdf_bandeau($pdf, 'LISTE DES ÉLÈVES EN DÉFAUT DE PAIEMENT', 'Students with Outstanding Balance', $pw);

$pdf->SetFont('Arial', 'B', 8.5);
$pdf->SetX($ml);
$pdf->Cell($uw, 5, pdf_u(
    'Classe : ' . $classe['designation'] . '   —   Année : ' . $annee['libelle'] .
    '   —   Frais : ' . ($obligation_filtre['libelle'] ?? 'Tous') .
    '   —   Effectif : ' . count($eleves) . '   —   Impayés : ' . count($insolvables)
), 0, 1);
$pdf->Ln(2);

$col_w = [10, $uw - 10 - 40 - 30, 40, 30];
$pdf->SetFillColor(30, 79, 216);
$pdf->SetTextColor(255);
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetX($ml);
$pdf->Cell($col_w[0], 6, pdf_u('N°'), 1, 0, 'C', true);
$pdf->Cell($col_w[1], 6, pdf_u('Nom et prénom'), 1, 0, 'L', true);
$pdf->Cell($col_w[2], 6, pdf_u('Matricule'), 1, 0, 'C', true);
$pdf->Cell($col_w[3], 6, pdf_u('Solde dû'), 1, 1, 'R', true);
$pdf->SetTextColor(0);

$pdf->SetFont('Arial', '', 8);
$fill = false; $no = 1; $total = 0;
foreach ($insolvables as $e) {
    $pdf->SetFillColor(234, 244, 251);
    $pdf->SetX($ml);
    $pdf->Cell($col_w[0], 5.5, (string)$no, 'B', 0, 'C', $fill);
    $pdf->Cell($col_w[1], 5.5, pdf_u($e['nom'] . ' ' . ($e['prenom'] ?? '')), 'B', 0, 'L', $fill);
    $pdf->Cell($col_w[2], 5.5, pdf_u($e['matricule']), 'B', 0, 'C', $fill);
    $pdf->Cell($col_w[3], 5.5, number_format($e['dette'], 0, ',', ' '), 'B', 0, 'R', $fill);
    $pdf->Ln();
    $total += $e['dette'];
    $fill = !$fill; $no++;
}
if (!$insolvables) {
    $pdf->SetX($ml);
    $pdf->Cell($uw, 6, pdf_u('Aucun impayé — tous les élèves de cette classe sont à jour.'), 1, 1, 'C');
} else {
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetX($ml);
    $pdf->Cell($col_w[0] + $col_w[1] + $col_w[2], 6, pdf_u('TOTAL DÛ'), 1, 0, 'R');
    $pdf->Cell($col_w[3], 6, number_format($total, 0, ',', ' '), 1, 1, 'R');
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

// Signature numérique de l'Intendant (sur demande uniquement, jamais automatique).
if (($_GET['signature'] ?? '0') === '1') {
    $sig_w = 24;
    $sy = $pdf->GetY() + 1;
    $sx = $x_sign + ($w_sign - $sig_w) / 2;
    pdf_signature_appliquer($pdf, 'insolvables_paiement', 'intendant', 0, 0, $pw, $ph, [
        'x_pct' => $sx / $pw * 100, 'y_pct' => $sy / $ph * 100, 'w_pct' => $sig_w / $pw * 100, 'h_pct' => null,
    ]);
}

$pdf->Output($dl ? 'D' : 'I', 'impayes_' . preg_replace('/\s+/', '_', $classe['designation']) . '.pdf');
