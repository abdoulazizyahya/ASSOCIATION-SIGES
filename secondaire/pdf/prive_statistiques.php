<?php
// secondaire/pdf/prive_statistiques.php — Bilan financier PDF PRIVÉ (totaux
// + par niveau + par type de frais + par mode + évolution mensuelle) —
// porté de pdf/finances_statistiques.php (primaire), adapté au schéma
// secondaire.
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'INTENDANT']);

require_once __DIR__ . '/fpdf.php';
require_once __DIR__ . '/header_pdf.php';

$dl = ($_GET['dl'] ?? '0') === '1';

$annee     = get_annee_active();
$id_annee  = (int) ($annee['id'] ?? 0);
$val_annee = $annee['val_annee'] ?? ($annee['libelle'] ?? '');

$nb_eleves_par_niveau = [];
foreach (db_all(
    "SELECT c.code_niveau, COUNT(DISTINCT i.id_eleve) AS nb
     FROM inscription i JOIN classe c ON c.id = i.id_classe
     JOIN eleve e ON e.id = i.id_eleve AND e.statut='actif'
     WHERE i.id_annee = ? GROUP BY c.code_niveau",
    [$id_annee]
) as $r) { $nb_eleves_par_niveau[$r['code_niveau']] = (int) $r['nb']; }

$tous_eleves_du = prive_finances_du_par_eleve($id_annee);
$du_reel_par_niveau = [];
foreach ($tous_eleves_du as $e) {
    $du_reel_par_niveau[$e['code_niveau']] = ($du_reel_par_niveau[$e['code_niveau']] ?? 0.0) + $e['du'];
}
$paye_par_niveau = [];
foreach (db_all(
    "SELECT c.code_niveau, SUM(p.montant_paiement) AS paye FROM paiement_prive p
     JOIN classe c ON c.id = p.id_classe WHERE p.id_annee=? GROUP BY c.code_niveau",
    [$id_annee]
) as $r) { $paye_par_niveau[$r['code_niveau']] = (float) $r['paye']; }

$niveaux = db_all("SELECT code_niveau, libelle_niv, ordre_niveau FROM niveau ORDER BY ordre_niveau");
$stats_niveau = []; $total_du_general = 0.0; $total_paye_general = 0.0;
foreach ($niveaux as $n) {
    $code = $n['code_niveau'];
    $nb   = $nb_eleves_par_niveau[$code] ?? 0;
    $du   = $du_reel_par_niveau[$code] ?? 0.0;
    $paye = $paye_par_niveau[$code] ?? 0.0;
    if ($nb === 0 && $du <= 0 && $paye <= 0) continue;
    $stats_niveau[] = ['niveau' => $n['libelle_niv'] ?: $code, 'nb' => $nb, 'du' => $du, 'paye' => $paye];
    $total_du_general += $du; $total_paye_general += $paye;
}
$solde_general = $total_du_general - $total_paye_general;
$taux_general  = $total_du_general > 0 ? round($total_paye_general / $total_du_general * 100, 1) : 0;

$obligations_toutes = db_all("SELECT * FROM obligation_privee");
$par_type = [];
foreach ($obligations_toutes as $o) {
    $nb = $nb_eleves_par_niveau[$o['code_niveau']] ?? 0;
    $par_type[$o['nom_obligation']]['du'] = ($par_type[$o['nom_obligation']]['du'] ?? 0.0) + (float) $o['montant_obligation'] * $nb;
    $par_type[$o['nom_obligation']]['paye'] = $par_type[$o['nom_obligation']]['paye'] ?? 0.0;
}
foreach (db_all(
    "SELECT o.nom_obligation, SUM(p.montant_paiement) AS paye FROM paiement_prive p
     JOIN obligation_privee o ON o.id=p.id_obligation WHERE p.id_annee=? GROUP BY o.nom_obligation",
    [$id_annee]
) as $r) { $par_type[$r['nom_obligation']]['paye'] = ($par_type[$r['nom_obligation']]['paye'] ?? 0.0) + (float) $r['paye']; }

$par_mode = [];
foreach (db_all(
    "SELECT mode_paiement, SUM(montant_paiement) AS total, COUNT(*) AS nb FROM paiement_prive
     WHERE id_annee=? GROUP BY mode_paiement",
    [$id_annee]
) as $r) {
    $code = finances_mode_paiement_normalise($r['mode_paiement']);
    $par_mode[$code] = ['total' => (float) $r['total'], 'nb' => (int) $r['nb']];
}
foreach (finances_modes_paiement() as $code => $m) { $par_mode[$code] ??= ['total' => 0.0, 'nb' => 0]; }

$mois_fr = [1=>'Janv', 2=>'Févr', 3=>'Mars', 4=>'Avr', 5=>'Mai', 6=>'Juin', 7=>'Juil', 8=>'Août', 9=>'Sept', 10=>'Oct', 11=>'Nov', 12=>'Déc'];
$evolution_mensuelle = [];
foreach (db_all(
    "SELECT DATE_FORMAT(date_paiement,'%Y-%m') AS mois, SUM(montant_paiement) AS total FROM paiement_prive
     WHERE id_annee=? GROUP BY mois ORDER BY mois",
    [$id_annee]
) as $r) {
    $num = (int) substr($r['mois'], 5, 2);
    $evolution_mensuelle[] = ['label' => ($mois_fr[$num] ?? $r['mois']) . ' ' . substr($r['mois'], 0, 4), 'total' => (float) $r['total']];
}

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
pdf_bandeau($pdf, 'BILAN FINANCIER — PAIEMENT PRIVÉ', 'FINANCIAL STATEMENT — PRIVATE', $pw, 12);

$pdf->SetFont('Arial', '', 8.5);
$pdf->Cell(0, 5, pdf_u('Année scolaire : ' . $val_annee), 0, 1, 'L');
$pdf->Ln(2);

$w4 = ($pw - 24) / 4;
$pdf->SetFont('Arial', 'B', 8);
foreach ([
    ['TOTAL DÛ', $total_du_general],
    ['TOTAL ENCAISSÉ', $total_paye_general],
    ['RESTE À RECOUVRER', $solde_general],
] as [$lbl, $val]) {
    $pdf->SetFillColor(226, 232, 240);
    $pdf->Cell($w4, 6, pdf_u($lbl), 1, 0, 'C', true);
}
$pdf->Cell($w4, 6, pdf_u('TAUX'), 1, 1, 'C', true);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell($w4, 7, number_format($total_du_general, 0, ',', ' ') . ' F', 1, 0, 'C');
$pdf->Cell($w4, 7, number_format($total_paye_general, 0, ',', ' ') . ' F', 1, 0, 'C');
$pdf->Cell($w4, 7, number_format($solde_general, 0, ',', ' ') . ' F', 1, 0, 'C');
$pdf->Cell($w4, 7, $taux_general . ' %', 1, 1, 'C');
$pdf->Ln(4);

$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(0, 6, pdf_u('Par niveau'), 0, 1, 'L');
$w = [30, 24, 44, 44, 44];
$pdf->SetFont('Arial', 'B', 7.5);
$pdf->SetFillColor(30, 79, 216); $pdf->SetTextColor(255, 255, 255);
$pdf->Cell($w[0], 6, pdf_u('Niveau'), 1, 0, 'C', true);
$pdf->Cell($w[1], 6, pdf_u('Élèves'), 1, 0, 'C', true);
$pdf->Cell($w[2], 6, pdf_u('Dû'), 1, 0, 'C', true);
$pdf->Cell($w[3], 6, pdf_u('Payé'), 1, 0, 'C', true);
$pdf->Cell($w[4], 6, pdf_u('Recouvrement'), 1, 1, 'C', true);
$pdf->SetTextColor(0); $pdf->SetFont('Arial', '', 7.5);
foreach ($stats_niveau as $s) {
    $t = $s['du'] > 0 ? round($s['paye'] / $s['du'] * 100) : 0;
    $pdf->Cell($w[0], 5, pdf_u($s['niveau']), 1, 0, 'L');
    $pdf->Cell($w[1], 5, (string) $s['nb'], 1, 0, 'C');
    $pdf->Cell($w[2], 5, number_format($s['du'], 0, ',', ' '), 1, 0, 'R');
    $pdf->Cell($w[3], 5, number_format($s['paye'], 0, ',', ' '), 1, 0, 'R');
    $pdf->Cell($w[4], 5, $t . ' %', 1, 1, 'C');
}
$pdf->Ln(4);

if ($pdf->GetY() > $ph - 60) { $pdf->AddPage(); pdf_filigrane($pdf, $etab, $pw, $ph); }
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(0, 6, pdf_u('Par type de frais'), 0, 1, 'L');
$w = [80, 43, 43];
$pdf->SetFont('Arial', 'B', 7.5);
$pdf->SetFillColor(30, 79, 216); $pdf->SetTextColor(255, 255, 255);
$pdf->Cell($w[0], 6, pdf_u('Frais'), 1, 0, 'C', true);
$pdf->Cell($w[1], 6, pdf_u('Dû'), 1, 0, 'C', true);
$pdf->Cell($w[2], 6, pdf_u('Payé'), 1, 1, 'C', true);
$pdf->SetTextColor(0); $pdf->SetFont('Arial', '', 7.5);
foreach ($par_type as $nom => $t) {
    $pdf->Cell($w[0], 5, pdf_u($nom), 1, 0, 'L');
    $pdf->Cell($w[1], 5, number_format($t['du'], 0, ',', ' '), 1, 0, 'R');
    $pdf->Cell($w[2], 5, number_format($t['paye'], 0, ',', ' '), 1, 1, 'R');
}
$pdf->Ln(4);

if ($pdf->GetY() > $ph - 60) { $pdf->AddPage(); pdf_filigrane($pdf, $etab, $pw, $ph); }
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(0, 6, pdf_u('Par mode de paiement'), 0, 1, 'L');
$w = [70, 35, 41, 20];
$pdf->SetFont('Arial', 'B', 7.5);
$pdf->SetFillColor(30, 79, 216); $pdf->SetTextColor(255, 255, 255);
$pdf->Cell($w[0], 6, pdf_u('Mode'), 1, 0, 'C', true);
$pdf->Cell($w[1], 6, pdf_u('Versements'), 1, 0, 'C', true);
$pdf->Cell($w[2], 6, pdf_u('Total encaissé'), 1, 0, 'C', true);
$pdf->Cell($w[3], 6, pdf_u('Part'), 1, 1, 'C', true);
$pdf->SetTextColor(0); $pdf->SetFont('Arial', '', 7.5);
foreach ($par_mode as $code => $pm) {
    $part = $total_paye_general > 0 ? round($pm['total'] / $total_paye_general * 100) : 0;
    $pdf->Cell($w[0], 5, pdf_u(finances_mode_paiement_libelle($code)), 1, 0, 'L');
    $pdf->Cell($w[1], 5, (string) $pm['nb'], 1, 0, 'C');
    $pdf->Cell($w[2], 5, number_format($pm['total'], 0, ',', ' '), 1, 0, 'R');
    $pdf->Cell($w[3], 5, $part . ' %', 1, 1, 'C');
}
$pdf->Ln(4);

if ($pdf->GetY() > $ph - 60) { $pdf->AddPage(); pdf_filigrane($pdf, $etab, $pw, $ph); }
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(0, 6, pdf_u('Évolution mensuelle des encaissements'), 0, 1, 'L');
$w = [83, 83];
$pdf->SetFont('Arial', 'B', 7.5);
$pdf->SetFillColor(30, 79, 216); $pdf->SetTextColor(255, 255, 255);
$pdf->Cell($w[0], 6, pdf_u('Mois'), 1, 0, 'C', true);
$pdf->Cell($w[1], 6, pdf_u('Total encaissé'), 1, 1, 'C', true);
$pdf->SetTextColor(0); $pdf->SetFont('Arial', '', 7.5);
foreach ($evolution_mensuelle as $m) {
    $pdf->Cell($w[0], 5, pdf_u($m['label']), 1, 0, 'L');
    $pdf->Cell($w[1], 5, number_format($m['total'], 0, ',', ' '), 1, 1, 'R');
}
if (!$evolution_mensuelle) {
    $pdf->SetFont('Arial', 'I', 8);
    $pdf->Cell(array_sum($w), 6, pdf_u('Aucun versement enregistré cette année.'), 1, 1, 'C');
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
$pdf->Output($dl ? 'D' : 'I', 'bilan_financier_prive_' . preg_replace('/[^A-Za-z0-9]/', '_', $val_annee) . '.pdf');
} catch (Throwable $e) {
    pdf_erreur_generation($e);
}
