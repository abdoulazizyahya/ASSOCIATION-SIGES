<?php
// secondaire/pdf/prive_journal.php — Journal de caisse PDF (PAIEMENT
// PRIVÉ) — porté de pdf/finances_journal.php (primaire), adapté au schéma
// secondaire. Pas de recherche par agent enseignant (utilisateur.nom/prenom
// directement, pas de jointure enseignant).
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'SECRETAIRE', 'INTENDANT']);

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
$id_annee  = (int) ($annee['id'] ?? 0);
$val_annee = $annee['val_annee'] ?? ($annee['libelle'] ?? '');

$params = [$id_annee, $date_debut, $date_fin];
$sql = "SELECT p.id, p.date_paiement, p.montant_paiement, p.mode_paiement,
               e.matricule, e.nom, e.prenom, c.designation,
               o.nom_obligation, u.nom AS agent_nom, u.prenom AS agent_prenom
        FROM paiement_prive p
        JOIN eleve e ON e.id = p.id_eleve
        JOIN classe c ON c.id = p.id_classe
        LEFT JOIN obligation_privee o ON o.id = p.id_obligation
        LEFT JOIN utilisateur u ON u.id = p.id_utilisateur
        WHERE p.id_annee = ? AND p.date_paiement BETWEEN ? AND ?";
if ($id_classe) { $sql .= " AND p.id_classe = ?"; $params[] = $id_classe; }
if ($mode_paiement) { $sql .= " AND p.mode_paiement = ?"; $params[] = $mode_paiement; }
$sql .= " ORDER BY p.date_paiement, p.id";
$lignes = db_all($sql, $params);

$classe_nom = $id_classe ? (string) db_val("SELECT designation FROM classe WHERE id=?", [$id_classe]) : '';

$jours = [];
$total_general = 0.0;
foreach ($lignes as $l) {
    $jours[$l['date_paiement']]['lignes'][] = $l;
    $jours[$l['date_paiement']]['total'] = ($jours[$l['date_paiement']]['total'] ?? 0.0) + (float) $l['montant_paiement'];
    $total_general += (float) $l['montant_paiement'];
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
pdf_bandeau($pdf, 'JOURNAL DE CAISSE — PAIEMENT PRIVÉ', 'CASH JOURNAL — PRIVATE', $pw, 12);

$pdf->SetFont('Arial', '', 8.5);
$sous_titre = 'Période du ' . date_fr($date_debut) . ' au ' . date_fr($date_fin) . '   —   Année scolaire : ' . $val_annee;
if ($classe_nom) $sous_titre .= '   —   Classe : ' . $classe_nom;
$pdf->Cell(0, 5, pdf_u($sous_titre), 0, 1, 'L');
$pdf->Ln(2);

$w = [18, 50, 24, 24, 22, 28];
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
        $pdf->Cell($w[0], 5, pdf_u(finances_numero_recu((int) $l['id'])), 1, 0, 'C');
        $pdf->Cell($w[1], 5, pdf_u($l['nom'] . ' ' . ($l['prenom'] ?? '')), 1, 0, 'L');
        $pdf->Cell($w[2], 5, pdf_u($l['designation']), 1, 0, 'C');
        $pdf->Cell($w[3], 5, pdf_u($l['nom_obligation'] ?: '—'), 1, 0, 'L');
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
$pdf->Cell($w_sign, 5, pdf_u(mb_strtoupper($etab['chef_etablissement'] ?: 'LE PROVISEUR') . ','), 0, 1, 'R');

pdf_copyright($pdf, $pw, $ph);
$pdf->Output($dl ? 'D' : 'I', 'journal_caisse_prive_' . $date_debut . '_' . $date_fin . '.pdf');
} catch (Throwable $e) {
    pdf_erreur_generation($e);
}
