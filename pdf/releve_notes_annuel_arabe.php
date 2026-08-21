<?php
// ── PDF : Relevé de notes annuel d'une classe (piste arabe) ─────────
// Miroir de pdf/releve_notes_annuel.php — mais moyenne ANNUELLE de chaque
// matière (note_matiere_annuelle_arabe()) et classement annuel
// (classement_annuel_classe_arabe()), décision de fin d'année si déjà
// statuée (decision_conseil_annuel_arabe, bd/migration_v9.sql).
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
require_once __DIR__ . '/../notes_apc_arabe.php';
exiger_connexion();

require_once __DIR__ . '/fpdf.php';
require_once __DIR__ . '/header_pdf.php';

$id_classe = (int) ($_GET['classe'] ?? 0);
$dl        = ($_GET['dl'] ?? '0') === '1';
if (!$id_classe) die('Paramètre classe manquant.');

$classe = db_one("SELECT * FROM classe WHERE IDClasses=?", [$id_classe]);
if (!$classe) die('Classe introuvable.');

$annee     = get_annee_active();
$val_annee = $annee['val_annee'] ?? '';
$etab_brut = get_etablissement();
$etab      = etab_pour_pdf($etab_brut);

// Préchargement en masse (optimisation, voir notes_apc_arabe.php).
precharger_notes_sequence_classe_arabe($id_classe);

$matieres   = matieres_classe_arabe($id_classe);
$classement = classement_annuel_classe_arabe($id_classe, $val_annee);
$decisions  = db_all("SELECT id_eleve, decision, observation FROM decision_conseil_annuel_arabe WHERE classe=? AND val_annee=?", [$id_classe, $val_annee]);
$dec_idx = [];
foreach ($decisions as $d) { $dec_idx[(int) $d['id_eleve']] = $d; }

// Enveloppé dans un try/catch — voir fonctions.php::pdf_erreur_generation()
// (jamais de fatal error brut ; ce document est public via QR et/ou
// consulté par du personnel qui ne doit pas voir de trace technique).
try {
$pdf = new FPDF('L', 'mm', 'A4');
$pdf->SetMargins(8, 8, 8);
$pdf->SetAutoPageBreak(true, 12);
$pdf->AddPage();
$pw = $pdf->GetPageWidth();
$ph = $pdf->GetPageHeight();
$uw = $pw - 16;

pdf_filigrane($pdf, $etab, $pw, $ph);
pdf_entete($pdf, $etab, $pw, 8, null, 0.85);
pdf_bandeau($pdf, 'RELEVÉ DE NOTES ANNUEL (ARABE)', 'ANNUAL GRADE SHEET (ARABIC TRACK)', $pw, 8);

$pdf->SetFont('Arial', 'B', 8.5);
$pdf->SetX(8);
$pdf->Cell($uw, 4.5, pdf_u('Classe : ' . $classe['DesignationClasses'] . '   —   Année scolaire : ' . $val_annee), 0, 1, 'C');
$pdf->Ln(2);

$w_no = 8; $w_nom = 40; $w_moy = 14; $w_rang = 12; $w_dec = 24;
$w_reste = $uw - $w_no - $w_nom - $w_moy - $w_rang - $w_dec;
$nb_mat = count($matieres);
$w_mat = $nb_mat > 0 ? $w_reste / $nb_mat : 0;

$code_mat = fn(string $nom): string => mb_strtoupper(mb_substr(preg_replace('/[^\p{L}]/u', '', $nom), 0, 3));

$pdf->SetX(8);
$pdf->SetFont('Arial', 'B', 6.5);
$pdf->SetFillColor(30, 79, 216); $pdf->SetTextColor(255, 255, 255);
$pdf->Cell($w_no, 14, pdf_u('N°'), 1, 0, 'C', true);
$pdf->Cell($w_nom, 14, pdf_u('Nom et prénoms'), 1, 0, 'C', true);
foreach ($matieres as $m) { $pdf->Cell($w_mat, 14, pdf_u($code_mat($m['matiere_fr'])), 1, 0, 'C', true); }
$pdf->Cell($w_moy, 14, pdf_u('Moy.'), 1, 0, 'C', true);
$pdf->Cell($w_rang, 14, 'Rang', 1, 0, 'C', true);
$pdf->Cell($w_dec, 14, pdf_u('Décision'), 1, 1, 'C', true);
$pdf->SetTextColor(0);

$pdf->SetFont('Arial', '', 6);
$pdf->SetX(8);
$legende = [];
foreach ($matieres as $m) { $legende[] = $code_mat($m['matiere_fr']) . '=' . mb_strimwidth($m['matiere_fr'], 0, 22, '…'); }
$pdf->MultiCell($uw, 3, pdf_u(implode('  |  ', $legende)), 0, 'L');
$pdf->Ln(1);

$pdf->SetFont('Arial', '', 7);
$no = 1; $fill = false;
foreach ($classement['lignes'] as $l) {
    if ($pdf->GetY() > $ph - 15) { $pdf->AddPage(); pdf_filigrane($pdf, $etab, $pw, $ph); }
    $id_eleve = (int) $l['id_eleve'];
    $pdf->SetX(8);
    $pdf->SetFillColor(234, 244, 251);
    $pdf->Cell($w_no, 5.5, (string) $no, 1, 0, 'C', $fill);
    $pdf->Cell($w_nom, 5.5, pdf_u(mb_strimwidth(mb_strtoupper($l['Nom_elv']) . ' ' . ($l['Prenom_elv'] ?? ''), 0, 28, '…')), 1, 0, 'L', $fill);
    foreach ($matieres as $m) {
        $note = note_matiere_annuelle_arabe($id_eleve, (int) $m['id_mat'], $id_classe, $val_annee);
        $val  = $note['moyenne'] !== null ? number_format($note['moyenne'], 1) : '—';
        $pdf->Cell($w_mat, 5.5, pdf_u($val), 1, 0, 'C', $fill);
    }
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->Cell($w_moy, 5.5, pdf_u($l['moy'] !== null ? number_format((float) $l['moy'], 2) : '—'), 1, 0, 'C', $fill);
    $pdf->SetFont('Arial', '', 7);
    $pdf->Cell($w_rang, 5.5, pdf_u((string) $l['rang']), 1, 0, 'C', $fill);
    $dec = $dec_idx[$id_eleve]['decision'] ?? '';
    $pdf->Cell($w_dec, 5.5, pdf_u($dec ?: '—'), 1, 1, 'C', $fill);
    $no++; $fill = !$fill;
}

$pdf->Ln(6);
if ($pdf->GetY() > $ph - 20) { $pdf->AddPage(); pdf_filigrane($pdf, $etab, $pw, $ph); }
$w_sign = $uw * 0.35;
$x_sign = $pw - 8 - $w_sign;
$pdf->SetFont('Arial', '', 8);
$pdf->SetXY($x_sign, $pdf->GetY());
$pdf->Cell($w_sign, 4.5, pdf_u('Fait à ' . (($etab['lieu'] ?: $etab['ville']) ?: '') . ', le ' . date('d/m/Y')), 0, 1, 'R');
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetX($x_sign);
$pdf->Cell($w_sign, 4.5, pdf_u(mb_strtoupper($etab['chef_etablissement'] ?: 'LE DIRECTEUR') . ','), 0, 1, 'R');

if (($_GET['signature'] ?? '0') === '1') {
    pdf_signature_appliquer_jn($pdf, 'releve_notes_annuel_arabe', 0, 0, $pw, $ph, [
        'x_pct' => ($x_sign + ($w_sign - 22) / 2) / $pw * 100,
        'y_pct' => ($pdf->GetY() + 1) / $ph * 100,
        'w_pct' => 22 / $pw * 100, 'h_pct' => null,
    ]);
}

// Copyright standard du système (pdf/header_pdf.php) — texte unique sur
// tous les PDF du projet, voir pdf_copyright().
pdf_copyright($pdf, $pw, $ph);
$pdf->Output($dl ? 'D' : 'I', 'releve_annuel_arabe_' . $classe['DesignationClasses'] . '.pdf');
} catch (Throwable $e) {
    pdf_erreur_generation($e);
}
