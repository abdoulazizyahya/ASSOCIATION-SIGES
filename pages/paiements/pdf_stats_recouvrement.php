<?php
// pages/paiements/pdf_stats_recouvrement.php — PDF du sous-onglet "Taux de
// recouvrement par classe" de Statistiques avancées (colonnes différentes
// des 3 autres sous-onglets — voir pdf_stats.php — d'où un fichier séparé).
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'CENSEUR', 'INTENDANT']);
require_once __DIR__ . '/../../pdf/fpdf.php';
require_once __DIR__ . '/../../pdf/header_pdf.php';

$annee     = get_annee_active();
$id_annee  = (int)($annee['id'] ?? 0);
$id_classe = (int)($_GET['classe'] ?? 0);
$dl        = ($_GET['dl'] ?? '0') === '1';

if (!$annee) { die('Aucune année active.'); }
$classe = $id_classe ? db_one("SELECT * FROM classe WHERE id=?", [$id_classe]) : null;

$classes_effectif = db_all(
    "SELECT c.id, c.designation, c.code_niveau, COUNT(DISTINCT e.id) AS effectif
     FROM classe c
     JOIN inscription i ON i.id_classe=c.id AND i.id_annee=?
     JOIN eleve e ON e.id=i.id_eleve AND e.statut='actif'
     WHERE c.archivee=0 " . ($id_classe ? 'AND c.id=?' : '') . "
     GROUP BY c.id ORDER BY c.ordre, c.designation",
    $id_classe ? [$id_annee, $id_classe] : [$id_annee]
);
$paye_par_classe = [];
foreach (db_all("SELECT id_classe, SUM(montant) AS total FROM paiement_frais WHERE id_annee=? GROUP BY id_classe", [$id_annee]) as $r) {
    $paye_par_classe[$r['id_classe']] = (float) $r['total'];
}
$recouvrement = []; $total_du_general = 0; $total_paye_general = 0;
foreach ($classes_effectif as $c) {
    $id_cycle = db_val("SELECT id_cycle FROM niveau WHERE code_niveau=?", [$c['code_niveau']]);
    $montant_du_unitaire = (float) db_val(
        "SELECT COALESCE(SUM(montant),0) FROM obligation_frais WHERE id_annee=? AND actif=1
         AND (portee='etablissement' OR (portee='cycle' AND id_cycle=?) OR (portee='niveau' AND code_niveau=?))",
        [$id_annee, $id_cycle, $c['code_niveau']]
    );
    $total_du   = $montant_du_unitaire * $c['effectif'];
    $total_paye = $paye_par_classe[$c['id']] ?? 0.0;
    $recouvrement[] = [
        'classe' => $c['designation'], 'effectif' => $c['effectif'],
        'total_du' => $total_du, 'total_paye' => $total_paye,
        'taux' => $total_du > 0 ? min(100, $total_paye / $total_du * 100) : 0,
    ];
    $total_du_general += $total_du;
    $total_paye_general += $total_paye;
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
pdf_bandeau($pdf, 'TAUX DE RECOUVREMENT PAR CLASSE', 'Collection Rate by Class', $pw);

$pdf->SetFont('Arial', 'B', 8.5);
$pdf->SetX($ml);
$pdf->Cell($uw, 5, pdf_u('Année : ' . $annee['libelle'] . '   —   Classe : ' . ($classe['designation'] ?? 'Toutes')), 0, 1);
$pdf->Ln(2);

$col = [$uw - 20 - 35 - 35 - 20, 20, 35, 35, 20];
$pdf->SetFillColor(30, 79, 216);
$pdf->SetTextColor(255);
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetX($ml);
$pdf->Cell($col[0], 6, pdf_u('Classe'), 1, 0, 'L', true);
$pdf->Cell($col[1], 6, pdf_u('Effectif'), 1, 0, 'C', true);
$pdf->Cell($col[2], 6, pdf_u('Dû (théorique)'), 1, 0, 'R', true);
$pdf->Cell($col[3], 6, pdf_u('Payé'), 1, 0, 'R', true);
$pdf->Cell($col[4], 6, pdf_u('Taux'), 1, 1, 'C', true);
$pdf->SetTextColor(0);

$pdf->SetFont('Arial', '', 8);
$fill = false;
foreach ($recouvrement as $r) {
    $pdf->SetFillColor(234, 244, 251);
    $pdf->SetX($ml);
    $pdf->Cell($col[0], 5.5, pdf_u($r['classe']), 'B', 0, 'L', $fill);
    $pdf->Cell($col[1], 5.5, (string)$r['effectif'], 'B', 0, 'C', $fill);
    $pdf->Cell($col[2], 5.5, number_format($r['total_du'], 0, ',', ' '), 'B', 0, 'R', $fill);
    $pdf->Cell($col[3], 5.5, number_format($r['total_paye'], 0, ',', ' '), 'B', 0, 'R', $fill);
    $pdf->Cell($col[4], 5.5, number_format($r['taux'], 0) . '%', 'B', 1, 'C', $fill);
    $fill = !$fill;
}
if (!$recouvrement) {
    $pdf->SetX($ml);
    $pdf->Cell($uw, 6, pdf_u('Aucune classe pour cette sélection.'), 1, 1, 'C');
} else {
    $taux_general = $total_du_general > 0 ? min(100, $total_paye_general / $total_du_general * 100) : 0;
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetX($ml);
    $pdf->Cell($col[0] + $col[1], 6, pdf_u('TOTAL GÉNÉRAL'), 1, 0, 'R');
    $pdf->Cell($col[2], 6, number_format($total_du_general, 0, ',', ' '), 1, 0, 'R');
    $pdf->Cell($col[3], 6, number_format($total_paye_general, 0, ',', ' '), 1, 0, 'R');
    $pdf->Cell($col[4], 6, number_format($taux_general, 0) . '%', 1, 1, 'C');
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
    pdf_signature_appliquer($pdf, 'stats_paiement_recouvrement', 'intendant', 0, 0, $pw, $ph, [
        'x_pct' => $sx / $pw * 100, 'y_pct' => $sy / $ph * 100, 'w_pct' => $sig_w / $pw * 100, 'h_pct' => null,
    ]);
}

$pdf->Output($dl ? 'D' : 'I', 'recouvrement_' . date('Ymd') . '.pdf');
} catch (Throwable $e) {
    pdf_erreur_generation($e);
}
