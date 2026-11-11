<?php
// secondaire/commun/pages/impression/registre.php — Registre de conservation
// des notes (10/11/2026) : pour une classe et un trimestre, la moyenne de
// chaque élève dans chaque matière, sa moyenne générale et son rang, à signer
// et à archiver. Secondaire général (moteur des bulletins : pv_* / calc_*).
// GET : classe, periode (t<ID>).
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
require_once __DIR__ . '/_impression_lib.php';
require_once __DIR__ . '/../analyse/_analyse_lib.php';
exiger_role(imp_roles());

if (!ed_secondaire()) die('Registre disponible pour le secondaire.');
$id_classe = (int) ($_GET['classe'] ?? 0);
$periodes = array_values(array_filter(an_periodes(), fn($p) => $p['cle'] !== 'annee'));
$periode = $periodes[0] ?? null;
foreach ($periodes as $p) if ($p['cle'] === ($_GET['periode'] ?? '')) $periode = $p;
if (!$id_classe || !$periode) die('Classe ou trimestre manquant.');
$classe = db_one("SELECT designation FROM classe WHERE id=?", [$id_classe]);
$an = ed_annee();

$d = pv_charger_donnees_comp($id_classe, (int) $periode['id'], (int) $an['cle']);
$mats = [];
foreach (db_all("SELECT id, libelle, code FROM matiere") as $m) $mats[(int) $m['id']] = $m;
$cols = [];
foreach ($d['disciplines'] as $disc) $cols[] = ['id' => (int) $disc['id_mat'], 'coef' => $disc['coef'], 'lib' => trim((string) ($mats[(int) $disc['id_mat']]['code'] ?? '')) ?: trim(preg_replace('/\s*\(.*?\)\s*/u', ' ', (string) ($mats[(int) $disc['id_mat']]['libelle'] ?? '?')))];
$eleves = ed_eleves($id_classe);
$moys = an_moyennes($id_classe, $periode); arsort($moys);
$rang = []; $r = 0; foreach ($moys as $eid => $m) $rang[$eid] = ++$r;

require_once __DIR__ . '/../../pdf/fpdf.php';
require_once __DIR__ . '/../../pdf/header_pdf.php';
$etab = etab_pour_pdf(get_etablissement());
$pdf = new FPDF('L', 'mm', 'A4'); require_once __DIR__ . '/../../../../noyau/partage/apparence_docs.php'; app_appliquer_doc($pdf, 'documents');   // Apparence des documents
$pdf->SetMargins(8, 8, 8); $pdf->SetAutoPageBreak(false); $pdf->AliasNbPages();
$pw = 297; $uw = 281;
$MAR = [26, 39, 68];
$w_no = 8; $w_nom = 58; $w_moy = 16; $w_rg = 11;
$w_mat = count($cols) ? min(18, ($uw - $w_no - $w_nom - $w_moy - $w_rg) / count($cols)) : 18;
$entete = function (bool $premiere) use ($pdf, $etab, $pw, $classe, $periode, $an, $cols, $w_no, $w_nom, $w_mat, $w_moy, $w_rg, $MAR) {
    $pdf->AddPage();
    if ($premiere) { pdf_entete($pdf, $etab, $pw, 8); pdf_bandeau($pdf, 'REGISTRE DE CONSERVATION DES NOTES', ($classe['designation'] ?? '') . ' — ' . $periode['libelle'] . ' — ' . $an['libelle'], $pw, 8); }
    else { $pdf->SetFont('Arial', 'I', 8); $pdf->Cell(0, 5, pdf_u('Registre de conservation des notes — ' . ($classe['designation'] ?? '') . ' — ' . $periode['libelle'] . ' (suite)'), 0, 1); }
    $pdf->SetFillColor(...$MAR); $pdf->SetTextColor(255, 255, 255); $pdf->SetFont('Arial', 'B', 7);
    $pdf->Cell($w_no, 9, pdf_u('N°'), 1, 0, 'C', true); $pdf->Cell($w_nom, 9, pdf_u('Nom et prénom(s)'), 1, 0, 'C', true);
    foreach ($cols as $c) { $x = $pdf->GetX(); $y = $pdf->GetY(); $pdf->Cell($w_mat, 9, '', 1, 0, 'C', true);
        $pdf->SetXY($x, $y + 0.6); $lib_c = $c['lib']; $t = 6.2; $pdf->SetFontSize($t);
        while ($t > 4.6 && $pdf->GetStringWidth(pdf_u($lib_c)) > $w_mat - 1) { $t -= 0.2; $pdf->SetFontSize($t); }
        while (mb_strlen($lib_c) > 3 && $pdf->GetStringWidth(pdf_u($lib_c . '.')) > $w_mat - 1) $lib_c = mb_substr($lib_c, 0, -1);
        if ($lib_c !== $c['lib']) $lib_c = rtrim($lib_c) . '.';
        $pdf->Cell($w_mat, 4, pdf_u($lib_c), 0, 0, 'C'); $pdf->SetFontSize(6.2); $pdf->SetXY($x, $y + 4.4); $pdf->Cell($w_mat, 4, pdf_u('coef ' . $c['coef']), 0, 0, 'C'); $pdf->SetXY($x + $w_mat, $y); $pdf->SetFontSize(7); }
    $pdf->Cell($w_moy, 9, pdf_u('Moy. gén.'), 1, 0, 'C', true); $pdf->Cell($w_rg, 9, pdf_u('Rang'), 1, 1, 'C', true);
    $pdf->SetTextColor(0, 0, 0);
};
$entete(true);
$n = 0; $fill = false;
foreach ($eleves as $e) {
    if ($pdf->GetY() + 6 > 194) { $entete(false); }
    $eid = (int) $e['id']; $n++;
    $pdf->SetFillColor(243, 246, 251); $pdf->SetFont('Arial', '', 7.5);
    $pdf->Cell($w_no, 5.6, $n, 1, 0, 'C', $fill);
    $nom = ed_nom($e); for ($t = 7.5; $t > 5.5 && $pdf->GetStringWidth(pdf_u($nom)) > $w_nom - 2; $t -= 0.25) $pdf->SetFontSize($t);
    $pdf->Cell($w_nom, 5.6, pdf_u($nom), 1, 0, 'L', $fill); $pdf->SetFontSize(7.5);
    foreach ($cols as $c) {
        $v = pv_moy_matiere_comp($eid, $d['competences_par_mat'][$c['id']] ?? [], $d['notes_idx'], $d['notes_count'], $d['nb_inscrits']);
        if ($v !== null && $v < 10) $pdf->SetTextColor(192, 57, 43);
        $pdf->Cell($w_mat, 5.6, $v === null ? '' : number_format((float) $v, 2, ',', ''), 1, 0, 'C', $fill);
        $pdf->SetTextColor(0, 0, 0);
    }
    $m = $moys[$eid] ?? null;
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->Cell($w_moy, 5.6, $m === null ? '' : number_format($m, 2, ',', ''), 1, 0, 'C', $fill);
    $pdf->Cell($w_rg, 5.6, isset($rang[$eid]) ? $rang[$eid] . 'e' : '', 1, 1, 'C', $fill);
    $fill = !$fill;
}
// Signatures
if ($pdf->GetY() + 26 > 200) $entete(false);
$pdf->Ln(4); $pdf->SetFont('Arial', '', 8.5);
$y = $pdf->GetY();
foreach (['Le professeur principal', 'Le censeur', ed_titre_chef()] as $i => $lib) {
    $pdf->SetXY(8 + $i * ($uw / 3), $y); $pdf->Cell($uw / 3, 5, pdf_u($lib), 0, 2, 'C');
    $pdf->SetFont('Arial', 'I', 7); $pdf->Cell($uw / 3, 4, pdf_u('(nom, date et signature)'), 0, 0, 'C'); $pdf->SetFont('Arial', '', 8.5);
}
$pdf->SetFont('Arial', 'I', 7); $pdf->SetTextColor(110, 110, 110);
$pdf->SetXY(8, 200); $pdf->Cell($uw / 2, 4, pdf_u('Édité le ' . date('d/m/Y à H:i') . ' — moyennes calculées comme sur les bulletins'), 0, 0, 'L');
$pdf->Cell($uw / 2, 4, pdf_u('Page ' . $pdf->PageNo() . ' / {nb}'), 0, 0, 'R');
$pdf->Output('I', 'registre_notes_' . date('Ymd') . '.pdf');
