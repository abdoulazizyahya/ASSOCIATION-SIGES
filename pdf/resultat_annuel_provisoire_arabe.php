<?php
// ── PDF : Liste provisoire (piste arabe, effectif prévisionnel année suivante) ─
// Miroir de pdf/resultat_annuel_provisoire.php.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
require_once __DIR__ . '/../pages/resultat_annuel_arabe/commun_arabe.php';
exiger_connexion();

require_once __DIR__ . '/fpdf.php';
require_once __DIR__ . '/header_pdf.php';

$id_classe = (int) ($_GET['classe'] ?? 0);
$ordre     = ($_GET['ordre'] ?? 'alpha') === 'merite' ? 'merite' : 'alpha';
$dl        = ($_GET['dl'] ?? '0') === '1';
if (!$id_classe) die('Classe manquante.');

$annee     = get_annee_active();
$val_annee = $annee['val_annee'] ?? '';
$etab_brut = get_etablissement();
$etab      = etab_pour_pdf($etab_brut);

$classe = db_one("SELECT DesignationClasses FROM classe WHERE IDClasses=?", [$id_classe]);
if (!$classe) die('Classe introuvable.');
$val_annee_suivante = resultat_annuel_libelle_annee_suivante_arabe($val_annee);
$lignes = resultat_annuel_provisoire_classe_arabe($id_classe, $val_annee, $ordre);

// Enveloppé dans un try/catch — voir fonctions.php::pdf_erreur_generation()
// (jamais de fatal error brut ; ce document est public via QR et/ou
// consulté par du personnel qui ne doit pas voir de trace technique).
try {
$pdf = new FPDF('P', 'mm', 'A4');
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 15);
$pdf->AddPage();
$pw = $pdf->GetPageWidth(); $ph = $pdf->GetPageHeight(); $uw = $pw - 20;

pdf_filigrane($pdf, $etab, $pw, $ph);
pdf_entete($pdf, $etab, $pw, 10);
pdf_bandeau($pdf, 'LISTE PROVISOIRE (ARABE) — ' . mb_strtoupper($classe['DesignationClasses']), 'PROVISIONAL LIST (ARABIC TRACK)', $pw, 10);

$pdf->SetFont('Arial', 'B', 9);
$pdf->SetX(10);
$pdf->Cell($uw, 5, pdf_u("Effectif prévisionnel $val_annee_suivante — redoublants + admis d'autres classes"), 0, 1, 'C');
$pdf->Ln(2);

$cols = [['N°', 10], ['Matricule', 26], ['Nom et prénoms', 0], ['Date naiss.', 24], ['Lieu naiss.', 36], ['Sexe', 14], ['Statut', 20]];
$fixed = array_sum(array_column($cols, 1));
$cols[2][1] = $uw - $fixed;

$pdf->SetFont('Arial', 'B', 7.5);
$pdf->SetFillColor(30, 79, 216); $pdf->SetTextColor(255);
$pdf->SetX(10);
foreach ($cols as $c) $pdf->Cell($c[1], 7, pdf_u($c[0]), 1, 0, 'C', true);
$pdf->Ln();
$pdf->SetTextColor(0);
$pdf->SetFont('Arial', '', 7.5);

$no = 1;
foreach ($lignes as $l) {
    if ($pdf->GetY() > $ph - 15) { $pdf->AddPage(); pdf_filigrane($pdf, $etab, $pw, $ph); }
    $e = $l['eleve'];
    $fill = ($no % 2 === 0);
    $pdf->SetFillColor(234, 244, 251);
    $dn = $e['Date_naiss_elv'] ? date('d/m/Y', strtotime($e['Date_naiss_elv'])) : '—';
    $vals = [(string) $no, $e['Mat_elv'] ?? '', mb_strtoupper($e['Nom_elv']) . ' ' . ($e['Prenom_elv'] ?? ''), $dn, $e['Lieu_naiss_elv'] ?: '—',
        stripos($e['Sexe_elv'] ?? '', 'F') === 0 ? 'F' : 'M', $l['statut_code']];
    $pdf->SetX(10);
    foreach ($cols as $i => $c) { $pdf->Cell($c[1], 6, pdf_u((string) $vals[$i]), 1, 0, $i === 2 ? 'L' : 'C', $fill); }
    $pdf->Ln();
    $no++;
}
if (empty($lignes)) {
    $pdf->SetX(10);
    $pdf->Cell($uw, 8, pdf_u('Aucun élève — aucune décision du Conseil de Classe enregistrée pour cette classe.'), 1, 1, 'C');
}

// Copyright standard du système (pdf/header_pdf.php) — texte unique sur
// tous les PDF du projet, voir pdf_copyright().
pdf_copyright($pdf, $pw, $ph);

$pdf->Output($dl ? 'D' : 'I', 'liste_provisoire_arabe_' . $classe['DesignationClasses'] . '.pdf');
} catch (Throwable $e) {
    pdf_erreur_generation($e);
}
