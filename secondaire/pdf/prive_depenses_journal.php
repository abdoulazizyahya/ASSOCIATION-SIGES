<?php
// secondaire/pdf/prive_depenses_journal.php — Journal des dépenses PRIVÉES
// PDF — porté de pdf/depenses_journal.php (primaire), adapté au schéma
// secondaire.
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'SECRETAIRE', 'INTENDANT']);

require_once __DIR__ . '/fpdf.php';
require_once __DIR__ . '/header_pdf.php';

$dl           = ($_GET['dl'] ?? '0') === '1';
$date_debut   = $_GET['debut'] ?? date('Y-m-01');
$date_fin     = $_GET['fin'] ?? date('Y-m-d');
$id_categorie = (int) ($_GET['categorie'] ?? 0);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_debut)) $date_debut = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_fin))   $date_fin   = date('Y-m-d');

$annee     = get_annee_active();
$id_annee  = (int) ($annee['id'] ?? 0);
$val_annee = $annee['val_annee'] ?? ($annee['libelle'] ?? '');

$params = [$id_annee, $date_debut, $date_fin];
$sql = "SELECT d.id, d.date_depense, d.montant, d.libelle, d.beneficiaire,
               cd.libelle AS categorie_libelle, u.nom, u.prenom
        FROM depense_privee d
        JOIN categorie_depense_privee cd ON cd.id = d.id_categorie
        LEFT JOIN utilisateur u ON u.id = d.id_utilisateur
        WHERE d.id_annee = ? AND d.date_depense BETWEEN ? AND ?";
if ($id_categorie) { $sql .= " AND d.id_categorie = ?"; $params[] = $id_categorie; }
$sql .= " ORDER BY d.date_depense, d.id";
$lignes = db_all($sql, $params);

$categorie_nom = $id_categorie ? (string) db_val("SELECT libelle FROM categorie_depense_privee WHERE id=?", [$id_categorie]) : '';

$jours = [];
$total_general = 0.0;
foreach ($lignes as $l) {
    $jours[$l['date_depense']]['lignes'][] = $l;
    $jours[$l['date_depense']]['total'] = ($jours[$l['date_depense']]['total'] ?? 0.0) + (float) $l['montant'];
    $total_general += (float) $l['montant'];
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
pdf_bandeau($pdf, 'JOURNAL DES DEPENSES — PAIEMENT PRIVE', 'EXPENSE JOURNAL — PRIVATE', $pw, 12);

$pdf->SetFont('Arial', '', 8.5);
$sous_titre = 'Période du ' . date_fr($date_debut) . ' au ' . date_fr($date_fin) . '   —   Année scolaire : ' . $val_annee;
if ($categorie_nom) $sous_titre .= '   —   Catégorie : ' . $categorie_nom;
$pdf->Cell(0, 5, pdf_u($sous_titre), 0, 1, 'L');
$pdf->Ln(2);

$w = [22, 38, 62, 30, 29];
foreach ($jours as $jour => $grp) {
    if ($pdf->GetY() > $ph - 35) { $pdf->AddPage(); pdf_filigrane($pdf, $etab, $pw, $ph); }

    $pdf->SetFont('Arial', 'B', 8.5);
    $pdf->SetFillColor(226, 232, 240);
    $pdf->Cell(array_sum($w), 6, pdf_u(mb_strtoupper(date_fr($jour))), 1, 1, 'L', true);

    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->SetFillColor(220, 38, 38); $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell($w[0], 5.5, pdf_u('N° bon'), 1, 0, 'C', true);
    $pdf->Cell($w[1], 5.5, pdf_u('Catégorie'), 1, 0, 'C', true);
    $pdf->Cell($w[2], 5.5, pdf_u('Libellé'), 1, 0, 'C', true);
    $pdf->Cell($w[3], 5.5, pdf_u('Bénéficiaire'), 1, 0, 'C', true);
    $pdf->Cell($w[4], 5.5, pdf_u('Montant'), 1, 1, 'C', true);
    $pdf->SetTextColor(0); $pdf->SetFont('Arial', '', 7.5);

    foreach ($grp['lignes'] as $l) {
        if ($pdf->GetY() > $ph - 20) { $pdf->AddPage(); pdf_filigrane($pdf, $etab, $pw, $ph); }
        $pdf->Cell($w[0], 5, pdf_u(finances_numero_bon((int) $l['id'])), 1, 0, 'C');
        $pdf->Cell($w[1], 5, pdf_u($l['categorie_libelle']), 1, 0, 'L');
        $pdf->Cell($w[2], 5, pdf_u($l['libelle']), 1, 0, 'L');
        $pdf->Cell($w[3], 5, pdf_u($l['beneficiaire'] ?: '—'), 1, 0, 'L');
        $pdf->Cell($w[4], 5, number_format((float) $l['montant'], 0, ',', ' '), 1, 1, 'R');
    }
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->SetFillColor(252, 226, 226);
    $pdf->Cell($w[0] + $w[1] + $w[2] + $w[3], 5.5, pdf_u('SOUS-TOTAL DU JOUR (' . count($grp['lignes']) . ')'), 1, 0, 'R', true);
    $pdf->Cell($w[4], 5.5, number_format($grp['total'], 0, ',', ' '), 1, 1, 'R', true);
    $pdf->Ln(2);
}

if (!$jours) {
    $pdf->SetFont('Arial', 'I', 9);
    $pdf->Cell(0, 8, pdf_u('Aucune dépense sur cette période.'), 0, 1, 'C');
}

if ($pdf->GetY() > $ph - 25) { $pdf->AddPage(); pdf_filigrane($pdf, $etab, $pw, $ph); }
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetFillColor(220, 38, 38); $pdf->SetTextColor(255, 255, 255);
$pdf->Cell(array_sum($w) - $w[4], 7, pdf_u('TOTAL GÉNÉRAL (' . count($lignes) . ' dépense(s))'), 1, 0, 'R', true);
$pdf->Cell($w[4], 7, number_format($total_general, 0, ',', ' ') . ' F', 1, 1, 'R', true);
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
$pdf->Output($dl ? 'D' : 'I', 'journal_depenses_prive_' . $date_debut . '_' . $date_fin . '.pdf');
} catch (Throwable $e) {
    pdf_erreur_generation($e);
}
