<?php
// pages/paiements/pdf_stats.php — PDF des 3 sous-onglets tabulaires de
// "Statistiques avancées" (pages/paiements/rapport.php?onglet=stats) :
// période globale (évolution mensuelle), par frais, par opérateur. Un seul
// gabarit paramétré par &type=periode|frais|operateur (mêmes filtres
// classe/période que l'écran), sur le modèle de pdf_rapport.php.
// Le 4e sous-onglet (recouvrement par classe) a son propre générateur —
// voir pdf_stats_recouvrement.php (colonnes trop différentes pour être
// mélangées dans le même gabarit sans le complexifier inutilement).
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'CENSEUR', 'INTENDANT']);
require_once __DIR__ . '/../../pdf/fpdf.php';
require_once __DIR__ . '/../../pdf/header_pdf.php';

$annee      = get_annee_active();
$id_annee   = (int)($annee['id'] ?? 0);
$type       = in_array($_GET['type'] ?? '', ['periode', 'frais', 'operateur'], true) ? $_GET['type'] : 'periode';
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

$total_general = (float) db_val("SELECT COALESCE(SUM(p.montant),0) FROM paiement_frais p WHERE $sql_where", $params);

if ($type === 'frais') {
    $titre_fr = 'RÉPARTITION DES PAIEMENTS PAR FRAIS'; $titre_en = 'Payments by Fee';
    $entete_col1 = 'Frais';
    $lignes = db_all(
        "SELECT o.libelle AS lib, SUM(p.montant) AS total, COUNT(*) AS nb
         FROM paiement_frais p JOIN obligation_frais o ON o.id = p.id_obligation
         WHERE $sql_where GROUP BY o.libelle ORDER BY total DESC", $params
    );
} elseif ($type === 'operateur') {
    $titre_fr = 'RÉPARTITION DES PAIEMENTS PAR OPÉRATEUR'; $titre_en = 'Payments by Payment Channel';
    $entete_col1 = 'Opérateur';
    $lignes = db_all(
        "SELECT op.libelle AS lib, SUM(p.montant) AS total, COUNT(*) AS nb
         FROM paiement_frais p JOIN operateur_paiement op ON op.id = p.id_operateur
         WHERE $sql_where GROUP BY op.libelle ORDER BY total DESC", $params
    );
} else {
    $titre_fr = 'ÉTAT DES PAIEMENTS — ÉVOLUTION MENSUELLE'; $titre_en = 'Monthly Payments Summary';
    $entete_col1 = 'Mois';
    $lignes_brutes = db_all(
        "SELECT DATE_FORMAT(p.date_paiement, '%Y-%m') AS mois, SUM(p.montant) AS total, COUNT(*) AS nb
         FROM paiement_frais p WHERE $sql_where GROUP BY mois ORDER BY mois", $params
    );
    $lignes = array_map(fn($l) => ['lib' => date('m/Y', strtotime($l['mois'] . '-01')), 'total' => $l['total'], 'nb' => $l['nb']], $lignes_brutes);
}

$etab = get_etablissement();
// Enveloppé dans un try/catch — voir fonctions.php::pdf_erreur_generation()
// (jamais de fatal error brut ; ce document est public via QR et/ou
// consulté par du personnel qui ne doit pas voir de trace technique).
try {
$pdf  = new FPDF('P', 'mm', 'A4');
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 15);
$pdf->AddPage();
$pw = $pdf->GetPageWidth();
$ml = 10;
$uw = $pw - 20;

$reglage_couleurs = get_reglage_paiement($id_annee);
pdf_fond_degrade($pdf, 0, 0, $pw, $pdf->GetPageHeight(), [
    hex_vers_rgb($reglage_couleurs['couleur_fond_1']), hex_vers_rgb($reglage_couleurs['couleur_fond_2']), hex_vers_rgb($reglage_couleurs['couleur_fond_3']),
]);
pdf_filigrane($pdf, $etab, $pw, $pdf->GetPageHeight());
pdf_entete($pdf, $etab, $pw);
pdf_bandeau($pdf, $titre_fr, $titre_en, $pw);

$pdf->SetFont('Arial', 'B', 8.5);
$pdf->SetX($ml);
$periode = ($date_debut || $date_fin) ? ('Du ' . ($date_debut ?: '…') . ' au ' . ($date_fin ?: '…')) : 'Toute la période';
$pdf->Cell($uw, 5, pdf_u(
    'Année : ' . $annee['libelle'] . '   —   Classe : ' . ($classe['designation'] ?? 'Toutes') . '   —   ' . $periode .
    '   —   Total : ' . number_format($total_general, 0, ',', ' ') . ' F'
), 0, 1);
$pdf->Ln(2);

$col1 = $uw - 50 - 40;
$pdf->SetFillColor(30, 79, 216);
$pdf->SetTextColor(255);
$pdf->SetFont('Arial', 'B', 8.5);
$pdf->SetX($ml);
$pdf->Cell($col1, 6, pdf_u($entete_col1), 1, 0, 'L', true);
$pdf->Cell(50, 6, pdf_u('Versements'), 1, 0, 'C', true);
$pdf->Cell(40, 6, pdf_u('Montant'), 1, 1, 'R', true);
$pdf->SetTextColor(0);

$pdf->SetFont('Arial', '', 8.5);
$fill = false;
foreach ($lignes as $l) {
    $pdf->SetFillColor(234, 244, 251);
    $pdf->SetX($ml);
    $pdf->Cell($col1, 5.5, pdf_u((string)$l['lib']), 'B', 0, 'L', $fill);
    $pdf->Cell(50, 5.5, (string)(int)$l['nb'], 'B', 0, 'C', $fill);
    $pdf->Cell(40, 5.5, number_format((float)$l['total'], 0, ',', ' '), 'B', 1, 'R', $fill);
    $fill = !$fill;
}
if (!$lignes) {
    $pdf->SetX($ml);
    $pdf->Cell($uw, 6, pdf_u('Aucun paiement pour cette sélection.'), 1, 1, 'C');
} else {
    $pdf->SetFont('Arial', 'B', 8.5);
    $pdf->SetX($ml);
    $pdf->Cell($col1 + 50, 6, pdf_u('TOTAL GÉNÉRAL'), 1, 0, 'R');
    $pdf->Cell(40, 6, number_format($total_general, 0, ',', ' '), 1, 1, 'R');
}

// ── Lieu, date et signature de l'Intendant (bas de page, à droite) ────
$ph = $pdf->GetPageHeight();
$pdf->Ln(10);
if ($pdf->GetY() > $ph - 30) $pdf->AddPage();
$w_sign = $uw * 0.4;
$x_sign = $pw - 25 - $w_sign;
$pdf->SetFont('Arial', '', 9);
$pdf->SetX($x_sign);
$pdf->Cell($w_sign, 5, pdf_u('Fait à/Done at ' . (($etab['lieu'] ?: $etab['ville']) ?? '') . ', le/on ' . date('d/m/Y')), 0, 1, 'R');
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetX($x_sign);
$pdf->Cell($w_sign, 5, pdf_u("L'INTENDANT,"), 0, 1, 'R');

if (($_GET['signature'] ?? '0') === '1') {
    $sig_w = 24;
    $sy = $pdf->GetY() + 1;
    $sx = $x_sign + ($w_sign - $sig_w) / 2;
    pdf_signature_appliquer($pdf, 'stats_paiement_' . $type, 'intendant', 0, 0, $pw, $ph, [
        'x_pct' => $sx / $pw * 100, 'y_pct' => $sy / $ph * 100, 'w_pct' => $sig_w / $pw * 100, 'h_pct' => null,
    ]);
}

$pdf->Output($dl ? 'D' : 'I', 'statistiques_' . $type . '_' . date('Ymd') . '.pdf');
} catch (Throwable $e) {
    pdf_erreur_generation($e);
}
