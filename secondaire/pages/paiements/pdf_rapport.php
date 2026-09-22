<?php
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_role(['ADMIN','PROVISEUR', 'FONDATEUR','CENSEUR','INTENDANT']);
require_once __DIR__ . '/../../pdf/fpdf.php';
require_once __DIR__ . '/../../pdf/header_pdf.php';

$annee      = get_annee_active();
$id_annee   = (int)($annee['id'] ?? 0);
$id_classe  = (int)($_GET['classe'] ?? 0);
$date_debut = trim($_GET['debut'] ?? '');
$date_fin   = trim($_GET['fin'] ?? '');
$dl         = ($_GET['dl'] ?? '0') === '1';

if (!$annee) { die('Aucune année active.'); }
$classe = $id_classe ? db_one("SELECT * FROM classe WHERE id=?", [$id_classe]) : null;

$where  = ['p.id_annee = ?'];
$params = [$id_annee];
if ($id_classe)  { $where[] = 'p.id_classe = ?';      $params[] = $id_classe; }
if ($date_debut) { $where[] = 'p.date_paiement >= ?'; $params[] = $date_debut; }
if ($date_fin)   { $where[] = 'p.date_paiement <= ?'; $params[] = $date_fin; }
$sql_where = implode(' AND ', $where);

$lignes_brutes = db_all(
    "SELECT c.id AS id_classe, c.designation AS classe, o.libelle AS frais, SUM(p.montant) AS total
     FROM paiement_frais p
     JOIN classe c ON c.id = p.id_classe
     JOIN obligation_frais o ON o.id = p.id_obligation
     WHERE $sql_where
     GROUP BY c.id, o.libelle
     ORDER BY c.ordre, c.designation, o.libelle",
    $params
);

$colonnes = [];
$pivot    = [];
foreach ($lignes_brutes as $l) {
    if (!in_array($l['frais'], $colonnes, true)) $colonnes[] = $l['frais'];
    $pivot[$l['id_classe']]['classe']               = $l['classe'];
    $pivot[$l['id_classe']]['valeurs'][$l['frais']] = (float)$l['total'];
}
sort($colonnes);
$grand_total = 0;
foreach ($pivot as &$p) { $p['total'] = array_sum($p['valeurs']); $grand_total += $p['total']; }
unset($p);

$etab = get_etablissement();
$pdf  = new FPDF($colonnes && count($colonnes) > 4 ? 'L' : 'P', 'mm', 'A4');
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 15);
$pdf->AddPage();
$pw = $pdf->GetPageWidth();
$ml = 10;
$uw = $pw - 20;

pdf_filigrane($pdf, $etab, $pw, $pdf->GetPageHeight());
pdf_entete($pdf, $etab, $pw);
pdf_bandeau($pdf, 'RAPPORT DES PAIEMENTS', 'Payments Report', $pw);

$pdf->SetFont('Arial', 'B', 8.5);
$pdf->SetX($ml);
$periode = ($date_debut || $date_fin)
    ? ('Du ' . ($date_debut ?: '…') . ' au ' . ($date_fin ?: '…'))
    : 'Toute la période';
$pdf->Cell($uw, 5, pdf_u(
    'Année : ' . $annee['libelle'] . '   —   Classe : ' . ($classe['designation'] ?? 'Toutes') . '   —   ' . $periode
), 0, 1);
$pdf->Ln(2);

// Largeurs dynamiques : colonne "Classe" + N colonnes de frais + "Total",
// mises à l'échelle pour tenir exactement dans la largeur imprimable quel
// que soit le nombre de frais rencontrés (jamais de débordement).
$col_classe = 40; $col_total = 24;
$nb_frais   = max(count($colonnes), 1);
$col_frais  = ($uw - $col_classe - $col_total) / $nb_frais;

$pdf->SetFillColor(30, 79, 216);
$pdf->SetTextColor(255);
$pdf->SetFont('Arial', 'B', 7.5);
$pdf->SetX($ml);
$pdf->Cell($col_classe, 6, pdf_u('Classe'), 1, 0, 'L', true);
foreach ($colonnes as $col) { $pdf->Cell($col_frais, 6, pdf_u($col), 1, 0, 'C', true); }
$pdf->Cell($col_total, 6, pdf_u('Total'), 1, 1, 'R', true);
$pdf->SetTextColor(0);

$pdf->SetFont('Arial', '', 7.5);
$fill = false;
foreach ($pivot as $p) {
    $pdf->SetFillColor(234, 244, 251);
    $pdf->SetX($ml);
    $pdf->Cell($col_classe, 5.5, pdf_u($p['classe']), 'B', 0, 'L', $fill);
    foreach ($colonnes as $col) {
        $v = $p['valeurs'][$col] ?? 0;
        $pdf->Cell($col_frais, 5.5, $v ? number_format($v, 0, ',', ' ') : '-', 'B', 0, 'R', $fill);
    }
    $pdf->Cell($col_total, 5.5, number_format($p['total'], 0, ',', ' '), 'B', 1, 'R', $fill);
    $fill = !$fill;
}
if (!$pivot) {
    $pdf->SetX($ml);
    $pdf->Cell($uw, 6, pdf_u('Aucun paiement pour cette sélection.'), 1, 1, 'C');
} else {
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->SetX($ml);
    $pdf->Cell($col_classe, 6, pdf_u('TOTAL GÉNÉRAL'), 1, 0, 'L');
    foreach ($colonnes as $col) {
        $s = 0; foreach ($pivot as $p) { $s += $p['valeurs'][$col] ?? 0; }
        $pdf->Cell($col_frais, 6, number_format($s, 0, ',', ' '), 1, 0, 'R');
    }
    $pdf->Cell($col_total, 6, number_format($grand_total, 0, ',', ' '), 1, 1, 'R');
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
    pdf_signature_appliquer($pdf, 'rapport_paiement', 'intendant', 0, 0, $pw, $ph, [
        'x_pct' => $sx / $pw * 100, 'y_pct' => $sy / $ph * 100, 'w_pct' => $sig_w / $pw * 100, 'h_pct' => null,
    ]);
}

$pdf->Output($dl ? 'D' : 'I', 'rapport_paiements_' . date('Ymd') . '.pdf');
