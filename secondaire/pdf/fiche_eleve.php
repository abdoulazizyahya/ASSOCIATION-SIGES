<?php
// ── PDF : Fiche individuelle d'un élève ──────────────────────
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_connexion();

require_once __DIR__ . '/fpdf.php';
require_once __DIR__ . '/header_pdf.php';

$id    = (int)($_GET['id'] ?? 0);
exiger_acces_eleve_secondaire($id);   // enseignant / SG : seulement leurs classes
$dl    = ($_GET['dl'] ?? '0') === '1';
$eleve = db_one("SELECT * FROM eleve WHERE id=?", [$id]);
if (!$eleve) die('Élève introuvable.');

$inscriptions = db_all(
    "SELECT i.*, c.designation AS classe, a.libelle AS annee
     FROM inscription i
     JOIN classe c ON c.id=i.id_classe
     JOIN annee_scolaire a ON a.id=i.id_annee
     WHERE i.id_eleve=? ORDER BY a.libelle DESC", [$id]);

$tuteurs = db_all("SELECT * FROM tuteur WHERE id_eleve=?", [$id]);
$etab    = get_etablissement();
$annee   = db_one("SELECT * FROM annee_scolaire WHERE active=1") ?? ['libelle'=>'—'];

$pdf = new FPDF('P', 'mm', 'A4');
$pdf->SetMargins(12, 10, 12);
$pdf->SetAutoPageBreak(true, 15);
$pdf->AddPage();
$pw = $pdf->GetPageWidth();

pdf_filigrane($pdf, $etab, $pw, $pdf->GetPageHeight());
pdf_entete($pdf, $etab, $pw, 12);
pdf_bandeau($pdf, 'FICHE ÉLÈVE', 'STUDENT RECORD', $pw, 12);

$y_body = $pdf->GetY();

// ── Photo ──────────────────────────────────────────────────────
$photo_path = !empty($eleve['photo']) ? photo_sec_fichier_tmp((int) $eleve['id']) : '';
if ($photo_path && is_file($photo_path)) {
    $pdf->Image($photo_path, 12, $y_body, 28, 34);
} else {
    $pdf->Rect(12, $y_body, 28, 34);
    $pdf->SetFont('Arial', 'I', 6.5);
    $pdf->SetXY(12, $y_body + 14);
    $pdf->Cell(28, 5, 'Pas de photo', 0, 0, 'C');
}

// ── Infos personnelles ─────────────────────────────────────────
$xi = 42; $wi = $pw - $xi - 12;
$pdf->SetXY($xi, $y_body);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell($wi, 7, pdf_u(strtoupper($eleve['nom']) . ' ' . ($eleve['prenom'] ?? '')), 0, 1, 'L');

$champs = [
    'NIU'               => id_affichage_eleve($eleve),
    'Sexe'              => $eleve['sexe'] === 'M' ? 'Masculin' : 'Féminin',
    'Date de naissance' => $eleve['date_naiss'] ? date('d/m/Y', strtotime($eleve['date_naiss'])) : '—',
    'Lieu de naissance' => $eleve['lieu_naiss'] ?? '—',
    'Téléphone'         => $eleve['telephone'] ?? '—',
    'Adresse'           => $eleve['adresse'] ?? '—',
];
foreach ($champs as $lbl => $val) {
    $pdf->SetX($xi);
    $pdf->SetFont('Arial', 'B', 7.5); $pdf->Cell(42, 5, pdf_u($lbl . ' :'), 0, 0);
    $pdf->SetFont('Arial', '', 7.5);  $pdf->Cell($wi - 42, 5, pdf_u((string)$val), 0, 1);
}

$pdf->SetY($y_body + 36);

// ── Historique scolaire ────────────────────────────────────────
$pdf->SetFont('Arial', 'B', 8.5);
$pdf->SetFillColor(214, 234, 248);
$pdf->Cell(0, 6, pdf_u('  Historique scolaire'), 'LRB', 1, 'L', true);
$pdf->SetFont('Arial', 'B', 7.5);
$pdf->SetFillColor(30, 79, 216); $pdf->SetTextColor(255, 255, 255);
$pdf->Cell(40, 5, pdf_u('Année'), 1, 0, 'C', true);
$pdf->Cell(60, 5, 'Classe', 1, 0, 'C', true);
$pdf->Cell(40, 5, 'Statut', 1, 1, 'C', true);
$pdf->SetTextColor(0); $pdf->SetFont('Arial', '', 7.5);
foreach ($inscriptions as $i) {
    $pdf->Cell(40, 5, pdf_u((string)$i['annee']), 1, 0, 'C');
    $pdf->Cell(60, 5, pdf_u((string)$i['classe']), 1, 0, 'L');
    $pdf->Cell(40, 5, pdf_u((string)$i['statut']), 1, 1, 'C');
}

// ── Tuteurs ────────────────────────────────────────────────────
if ($tuteurs) {
    $pdf->Ln(3);
    $pdf->SetFont('Arial', 'B', 8.5);
    $pdf->SetFillColor(214, 234, 248);
    $pdf->Cell(0, 6, pdf_u('  Parents / Tuteurs'), 'LRB', 1, 'L', true);
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->SetFillColor(30, 79, 216); $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(25, 5, 'Lien', 1, 0, 'C', true);
    $pdf->Cell(70, 5, pdf_u('Nom et prénom'), 1, 0, 'C', true);
    $pdf->Cell(45, 5, pdf_u('Téléphone'), 1, 1, 'C', true);
    $pdf->SetTextColor(0); $pdf->SetFont('Arial', '', 7.5);
    foreach ($tuteurs as $t) {
        $pdf->Cell(25, 5, pdf_u($t['lien'] ?? '—'), 1, 0, 'C');
        $pdf->Cell(70, 5, pdf_u($t['nom'] . ' ' . ($t['prenom'] ?? '')), 1, 0, 'L');
        $pdf->Cell(45, 5, $t['telephone'] ?? '—', 1, 1, 'C');
    }
}

// ── Lieu, date et signature du chef d'établissement (bas de page) ──────
$ph = $pdf->GetPageHeight();
$pdf->Ln(8);
if ($pdf->GetY() > $ph - 30) { $pdf->AddPage(); pdf_filigrane($pdf, $etab, $pw, $ph); }
$w_sign = ($pw - 24) * 0.4;
$x_sign = $pw - 12 - $w_sign;
$pdf->SetFont('Arial', '', 9);
$pdf->SetX($x_sign);
$pdf->Cell($w_sign, 5, pdf_u('Fait à ' . ($etab['ville'] ?? '') . ', le ' . date('d/m/Y')), 0, 1, 'R');
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetX($x_sign);
$pdf->Cell($w_sign, 5, pdf_u(strtoupper($etab['chef_etablissement'] ?? 'LE PROVISEUR') . ','), 0, 1, 'R');

// Signature numérique (sur demande uniquement, jamais automatique).
if (($_GET['signature'] ?? '0') === '1') {
    $sig_w = 22;
    $sy = $pdf->GetY() + 1;
    $sx = $x_sign + ($w_sign - $sig_w) / 2;
    pdf_signature_appliquer($pdf, 'fiche_eleve', 'chef_etablissement', 0, 0, $pw, $ph, [
        'x_pct' => $sx / $pw * 100, 'y_pct' => $sy / $ph * 100, 'w_pct' => $sig_w / $pw * 100, 'h_pct' => null,
    ]);
}

$pdf->Output($dl ? 'D' : 'I', 'fiche_' . $eleve['matricule'] . '.pdf');
