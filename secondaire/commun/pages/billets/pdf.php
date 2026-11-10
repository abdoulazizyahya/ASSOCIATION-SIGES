<?php
// secondaire/commun/pages/billets/pdf.php — impression d'un billet : une
// feuille A4, SOUCHE en haut (gardée par l'école), ligne de découpe, VOLET
// en bas (remis à l'élève / à l'agent). Primaire et secondaire.
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
require_once __DIR__ . '/_billets_lib.php';
exiger_role(billets_roles());

$b = billet_charger((int) ($_GET['id'] ?? 0));
if (!$b) die('Billet introuvable.');
$T = BILLET_TYPES[$b['type']] ?? BILLET_TYPES['sortie'];

require_once __DIR__ . '/../../pdf/fpdf.php';
require_once __DIR__ . '/../../pdf/header_pdf.php';
$etab = etab_pour_pdf(get_etablissement());

$pdf = new FPDF('P', 'mm', 'A4');
$pdf->SetMargins(12, 10, 12);
$pdf->SetAutoPageBreak(false);
$pdf->AddPage();
$pw = $pdf->GetPageWidth(); $uw = $pw - 24;
$hex = ltrim($T['couleur'], '#');
$rgb = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
$dfr = fn(?string $d) => $d ? date('d/m/Y', strtotime($d)) : '';

$partie = function (float $y0, string $partie) use ($pdf, $b, $T, $etab, $pw, $uw, $rgb, $dfr) {
    pdf_entete($pdf, $etab, $pw, 12, $y0, 0.78);
    $y = max($pdf->GetY(), $y0 + 24) + 2;
    // Bandeau titre (couleur du type) + numéro
    $pdf->SetFillColor(...$rgb);
    $pdf->Rect(12, $y, $uw, 9, 'F');
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->SetXY(14, $y);
    $pdf->Cell($uw - 60, 9, pdf_u(mb_strtoupper($T['libelle']) . ' — ' . $partie), 0, 0, 'L');
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(44, 9, pdf_u('N° ' . $b['numero']), 0, 0, 'R');
    $pdf->SetTextColor(0, 0, 0);
    if ($b['annule']) {   // billet annulé : mention bien visible
        $pdf->SetTextColor(200, 0, 0); $pdf->SetFont('Arial', 'B', 26);
        $pdf->SetXY(12, $y + 30); $pdf->Cell($uw, 12, pdf_u('ANNULÉ'), 0, 0, 'C'); $pdf->SetTextColor(0, 0, 0);
    }
    $y += 12;
    // Lignes d'information
    $lignes = [];
    $lignes[] = [$T['pour'] === 'eleve' ? 'Élève' : 'Nom', $b['nom']];
    if ($b['classe']) $lignes[] = [$T['pour'] === 'eleve' ? 'Classe' : 'Fonction', $b['classe']];
    if ($b['identifiant']) $lignes[] = [$T['pour'] === 'eleve' ? 'Matricule / NIU' : 'Matricule', $b['identifiant']];
    $lignes[] = [$b['type'] === 'autorisation' ? 'Période' : 'Date', $b['type'] === 'autorisation' && $b['date_fin'] && $b['date_fin'] !== $b['date_billet']
        ? 'du ' . $dfr($b['date_billet']) . ' au ' . $dfr($b['date_fin']) : $dfr($b['date_billet'])];
    // Heures : le libellé du 1er champ, la 2e heure dans la valeur (libellé court, rien ne déborde).
    $lignes[] = [$T['heure'], trim(($b['heure'] ?: '') . ($T['heure2'] !== '' && $b['heure2'] ? '   —   ' . $T['heure2'] . ' ' . $b['heure2'] : ''))];
    if ($T['personne'] !== '') $lignes[] = [$T['personne'], (string) $b['personne']];
    $lignes[] = ['Motif', (string) $b['motif']];
    foreach ($lignes as [$lib, $val]) {
        $pdf->SetXY(14, $y);
        $pdf->SetFont('Arial', '', 9.5); $pdf->SetTextColor(90, 90, 90);
        $pdf->Cell(48, 6.6, pdf_u($lib), 0, 0, 'L');
        $pdf->SetFont('Arial', 'B', 10.5); $pdf->SetTextColor(0, 0, 0);
        $val = trim($val);
        if ($val === '') { pdf_ligne_pointillee($pdf, 62, $y + 5.2, 12 + $uw, $y + 5.2); }
        else $pdf->Cell($uw - 50, 6.6, pdf_u(mb_substr($val, 0, 95)), 0, 0, 'L');
        $pdf->SetDrawColor(220, 226, 232); $pdf->Line(14, $y + 6.6, 12 + $uw, $y + 6.6); $pdf->SetDrawColor(0, 0, 0);
        $y += 7.2;
    }
    // Signatures
    $y += 3;
    $pdf->SetFont('Arial', '', 8.5); $pdf->SetTextColor(90, 90, 90);
    $pdf->SetXY(14, $y);
    $pdf->Cell($uw / 2, 4.5, pdf_u('Fait à ' . lieu_edition_documents() . ', le ' . $dfr(substr((string) $b['cree_le'], 0, 10))), 0, 0, 'L');
    $pdf->SetXY(12 + $uw / 2, $y);
    $pdf->Cell($uw / 2 - 2, 4.5, pdf_u($partie === 'VOLET' ? ($T['pour'] === 'eleve' ? 'Visa du parent / tuteur' : 'Visa de l\'intéressé(e)') : 'Émis par : ' . (string) $b['cree_par']), 0, 0, 'R');
    $pdf->SetTextColor(0, 0, 0); $pdf->SetFont('Arial', 'B', 9.5);
    $pdf->SetXY(14, $y + 6);
    $pdf->Cell($uw / 2, 5, pdf_u($b['signataire'] ?: ed_titre_chef()), 0, 0, 'L');
};

$partie(8, 'SOUCHE');
// Ligne de découpe
$yc = 148.5;
$pdf->SetDrawColor(120, 120, 120);
pdf_ligne_pointillee($pdf, 6, $yc, $pw - 6, $yc, 2, 1.5);
$pdf->SetFont('Arial', 'I', 7.5); $pdf->SetTextColor(110, 110, 110);
$pdf->SetXY(0, $yc - 4.2); $pdf->Cell($pw, 4, pdf_u('Souche à conserver par l\'établissement'), 0, 0, 'C');
$pdf->SetXY(0, $yc + 0.6); $pdf->Cell($pw, 4, pdf_u('- - - découper ici - - -  Volet à remettre'), 0, 0, 'C');
$pdf->SetTextColor(0, 0, 0); $pdf->SetDrawColor(0, 0, 0);
$partie($yc + 7, 'VOLET');

$pdf->Output('I', 'billet_' . $b['numero'] . '.pdf');
