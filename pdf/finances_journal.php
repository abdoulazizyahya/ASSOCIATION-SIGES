<?php
// pdf/finances_journal.php — Journal de caisse PDF (registre chronologique,
// sous-total par jour) — mêmes filtres que pages/finances/journal.php.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
exiger_role(['DIRECTEUR', 'SECRETAIRE']);

require_once __DIR__ . '/fpdf.php';
require_once __DIR__ . '/header_pdf.php';

$dl            = ($_GET['dl'] ?? '0') === '1';
$date_debut    = $_GET['debut'] ?? date('Y-m-01');
$date_fin      = $_GET['fin'] ?? date('Y-m-d');
$id_classe     = (int) ($_GET['classe'] ?? 0);
$mode_paiement = $_GET['mode'] ?? '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_debut)) $date_debut = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_fin))   $date_fin   = date('Y-m-d');
if ($mode_paiement && !isset(finances_modes_paiement()[$mode_paiement])) $mode_paiement = '';

$annee     = get_annee_active();
$val_annee = $annee['val_annee'] ?? '';

$params = [$val_annee, $date_debut, $date_fin];
$sql = "SELECT p.id_pay, p.date_paiement, p.montant_paiement, p.mode_paiement,
               e.Mat_elv, e.Nom_elv, e.Prenom_elv, c.DesignationClasses,
               o.nom_obligation, ag.nom_ens, ag.prenom_ens
        FROM paiement_frais p
        JOIN eleve e ON e.id_eleve = p.id_eleve
        JOIN classe c ON c.IDClasses = p.classe
        LEFT JOIN obligation o ON o.id_obligation = p.id_obligation
        LEFT JOIN user u ON u.id_user = p.id_utilisateur
        LEFT JOIN enseignant ag ON ag.matricule_ens = u.matricule_ens
        WHERE p.val_annee = ? AND p.date_paiement BETWEEN ? AND ?";
if ($id_classe) { $sql .= " AND p.classe = ?"; $params[] = $id_classe; }
if ($mode_paiement) { $sql .= " AND p.mode_paiement = ?"; $params[] = $mode_paiement; }
$sql .= " ORDER BY p.date_paiement, p.id_pay";
$lignes = db_all($sql, $params);

$classe_nom = $id_classe ? (string) db_val("SELECT DesignationClasses FROM classe WHERE IDClasses=?", [$id_classe]) : '';

$jours = [];
$total_general = 0.0;
foreach ($lignes as $l) {
    $jours[$l['date_paiement']]['lignes'][] = $l;
    $jours[$l['date_paiement']]['total'] = ($jours[$l['date_paiement']]['total'] ?? 0.0) + (float) $l['montant_paiement'];
    $total_general += (float) $l['montant_paiement'];
}

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
pdf_bandeau($pdf, 'JOURNAL DE CAISSE', 'CASH JOURNAL', $pw, 12);

$pdf->SetFont('Arial', '', 8.5);
$sous_titre = 'Période du ' . date_fr($date_debut) . ' au ' . date_fr($date_fin) . '   —   Année scolaire : ' . $val_annee;
if ($classe_nom) $sous_titre .= '   —   Classe : ' . $classe_nom;
$pdf->Cell(0, 5, pdf_u($sous_titre), 0, 1, 'L');
$pdf->Ln(2);

$w = [18, 50, 24, 24, 22, 28]; // N° reçu / Élève / Classe / Frais / Mode / Montant
foreach ($jours as $jour => $grp) {
    if ($pdf->GetY() > $ph - 35) { $pdf->AddPage(); pdf_filigrane($pdf, $etab, $pw, $ph); }

    $pdf->SetFont('Arial', 'B', 8.5);
    $pdf->SetFillColor(226, 232, 240);
    $pdf->Cell(array_sum($w), 6, pdf_u(mb_strtoupper(date_fr($jour))), 1, 1, 'L', true);

    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->SetFillColor(30, 79, 216); $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell($w[0], 5.5, pdf_u('N° reçu'), 1, 0, 'C', true);
    $pdf->Cell($w[1], 5.5, pdf_u('Élève'), 1, 0, 'C', true);
    $pdf->Cell($w[2], 5.5, pdf_u('Classe'), 1, 0, 'C', true);
    $pdf->Cell($w[3], 5.5, pdf_u('Frais'), 1, 0, 'C', true);
    $pdf->Cell($w[4], 5.5, pdf_u('Mode'), 1, 0, 'C', true);
    $pdf->Cell($w[5], 5.5, pdf_u('Montant'), 1, 1, 'C', true);
    $pdf->SetTextColor(0); $pdf->SetFont('Arial', '', 7.5);

    foreach ($grp['lignes'] as $l) {
        if ($pdf->GetY() > $ph - 20) { $pdf->AddPage(); pdf_filigrane($pdf, $etab, $pw, $ph); }
        $pdf->Cell($w[0], 5, pdf_u(finances_numero_recu((int) $l['id_pay'])), 1, 0, 'C');
        $pdf->Cell($w[1], 5, pdf_u($l['Nom_elv'] . ' ' . ($l['Prenom_elv'] ?? '')), 1, 0, 'L');
        $pdf->Cell($w[2], 5, pdf_u($l['DesignationClasses']), 1, 0, 'C');
        $pdf->Cell($w[3], 5, pdf_u($l['nom_obligation'] ?: 'Non ventilé'), 1, 0, 'L');
        $pdf->Cell($w[4], 5, pdf_u(finances_mode_paiement_libelle($l['mode_paiement'] ?? null)), 1, 0, 'C');
        $pdf->Cell($w[5], 5, number_format((float) $l['montant_paiement'], 0, ',', ' '), 1, 1, 'R');
    }
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->SetFillColor(214, 234, 248);
    $pdf->Cell($w[0] + $w[1] + $w[2] + $w[3] + $w[4], 5.5, pdf_u('SOUS-TOTAL DU JOUR (' . count($grp['lignes']) . ')'), 1, 0, 'R', true);
    $pdf->Cell($w[5], 5.5, number_format($grp['total'], 0, ',', ' '), 1, 1, 'R', true);
    $pdf->Ln(2);
}

if (!$jours) {
    $pdf->SetFont('Arial', 'I', 9);
    $pdf->Cell(0, 8, pdf_u('Aucun versement sur cette période.'), 0, 1, 'C');
}

if ($pdf->GetY() > $ph - 25) { $pdf->AddPage(); pdf_filigrane($pdf, $etab, $pw, $ph); }
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetFillColor(30, 79, 216); $pdf->SetTextColor(255, 255, 255);
$pdf->Cell(array_sum($w) - $w[5], 7, pdf_u('TOTAL GÉNÉRAL (' . count($lignes) . ' versement(s))'), 1, 0, 'R', true);
$pdf->Cell($w[5], 7, number_format($total_general, 0, ',', ' ') . ' F', 1, 1, 'R', true);
$pdf->SetTextColor(0);

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
    pdf_signature_appliquer_jn($pdf, 'finances_journal', 0, 0, $pw, $ph, [
        'x_pct' => ($x_sign + ($w_sign - 22) / 2) / $pw * 100,
        'y_pct' => ($pdf->GetY() + 1) / $ph * 100,
        'w_pct' => 22 / $pw * 100, 'h_pct' => null,
    ]);
}

pdf_copyright($pdf, $pw, $ph);
$pdf->Output($dl ? 'D' : 'I', 'journal_caisse_' . $date_debut . '_' . $date_fin . '.pdf');
} catch (Throwable $e) {
    pdf_erreur_generation($e);
}
