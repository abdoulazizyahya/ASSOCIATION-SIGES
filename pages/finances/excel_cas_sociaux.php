<?php
// pages/finances/excel_cas_sociaux.php — Export Excel des cas sociaux,
// mêmes filtres et mêmes calculs que pages/finances/cas_sociaux.php.
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../conseil_classe/excel_releve_commun.php'; // generer_filigrane_excel()
exiger_role(['DIRECTEUR', 'SECRETAIRE', 'COMPTABLE']);

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

$annee     = get_annee_active();
$val_annee = $annee['val_annee'] ?? '';
$etab_brut = get_etablissement();
$etab      = etab_pour_pdf($etab_brut);

$niveau_f    = $_GET['niveau'] ?? '';
$id_classe_f = (int) ($_GET['classe'] ?? 0);

$paye_par_eleve = [];
foreach (db_all("SELECT id_eleve, SUM(montant_paiement) AS paye FROM paiement_frais WHERE val_annee=? GROUP BY id_eleve", [$val_annee]) as $r) {
    $paye_par_eleve[(int) $r['id_eleve']] = (float) $r['paye'];
}

$tous_eleves = finances_du_par_eleve($val_annee, $id_classe_f ?: null, $id_classe_f ? null : ($niveau_f ?: null));

$cas_sociaux = [];
$total_normal = 0.0; $total_du = 0.0; $total_paye = 0.0;
foreach ($tous_eleves as $e) {
    if (!$e['cas_social']) continue;
    $paye = $paye_par_eleve[(int) $e['id_eleve']] ?? 0.0;
    $cas_sociaux[] = $e + ['paye' => $paye, 'solde' => $e['du'] - $paye];
    $total_normal += $e['montant_normal'];
    $total_du     += $e['du'];
    $total_paye   += $paye;
}
usort($cas_sociaux, fn($a, $b) => $b['pourcentage'] <=> $a['pourcentage']);

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Cas sociaux');

$logo_src_path  = !empty($etab['logo']) ? __DIR__ . '/../../assets/uploads/' . $etab['logo'] : '';
$filigrane_path = __DIR__ . '/../../assets/uploads/filigrane_excel.png';
if ($logo_src_path && generer_filigrane_excel($logo_src_path, $filigrane_path)) {
    $sheet->setBackgroundImage(file_get_contents($filigrane_path));
}

$lettre = fn(int $c) => Coordinate::stringFromColumnIndex($c);
$col_total = 8;

$texte_etab = "REGION DE L'ADAMAOUA\n" .
    ($etab['departement_fr'] ?? 'DEPARTEMENT DE LA VINA') . "\n" .
    ($etab['arrondissement_fr'] ?? 'ARRONDISSEMENT DE MBE') . "\n" .
    '***********';
$nom_etab = strtoupper($etab['nom_fr'] ?? 'GSBI LES POUSSINS DE JAYNITAARE');
$tiers = max(1, (int) floor($col_total / 3));
foreach ([[1, $tiers], [2 * $tiers + 1, $col_total]] as [$c1, $c2]) {
    $sheet->mergeCells("{$lettre($c1)}1:{$lettre($c2)}4");
    $rt = new RichText();
    $r1 = $rt->createTextRun($texte_etab . "\n");
    $r1->getFont()->setSize(9);
    $r2 = $rt->createTextRun($nom_etab);
    $r2->getFont()->setBold(true)->setSize(11);
    $cell = $sheet->getCell("{$lettre($c1)}1");
    $cell->setValue($rt);
    $cell->getStyle()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
}
$sheet->mergeCells("{$lettre($tiers + 1)}1:{$lettre(2 * $tiers)}4");
if ($logo_src_path && is_file($logo_src_path)) {
    $drawing = new Drawing();
    $drawing->setName('Logo');
    $drawing->setPath($logo_src_path);
    $drawing->setHeight(70);
    $drawing->setCoordinates($lettre($tiers + 1) . '1');
    $drawing->setOffsetX(4);
    $drawing->setOffsetY(4);
    $drawing->setWorksheet($sheet);
}

$sheet->mergeCells("A5:{$lettre($col_total)}5");
$sheet->setCellValue('A5', 'ÉLÈVES CAS SOCIAUX');
$sheet->getStyle('A5')->getFont()->setBold(true)->setSize(16);
$sheet->getStyle('A5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->mergeCells("A6:{$lettre($col_total)}6");
$sheet->setCellValue('A6', 'Année ' . $val_annee . ($niveau_f !== '' ? ' — Niveau ' . $niveau_f : ''));
$sheet->getStyle('A6')->getFont()->setBold(true)->setSize(11);
$sheet->getStyle('A6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$row = 8;
$headers = ['Matricule', 'Nom et prénom', 'Classe', 'Réduction', 'Montant normal', 'Dû', 'Payé', 'Solde'];
foreach ($headers as $i => $hh) $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . $row, $hh);
$sheet->getStyle("A{$row}:H{$row}")->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFFFF'));
$sheet->getStyle("A{$row}:H{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1A3C6B');
$row++;
$hdr_row = $row - 1;

foreach ($cas_sociaux as $l) {
    $sheet->setCellValue("A{$row}", xl_safe($l['Mat_elv']));
    $sheet->setCellValue("B{$row}", xl_safe($l['Nom_elv'] . ' ' . ($l['Prenom_elv'] ?? '')));
    $sheet->setCellValue("C{$row}", xl_safe($l['DesignationClasses']));
    $sheet->setCellValue("D{$row}", '-' . rtrim(rtrim(number_format($l['pourcentage'], 2, '.', ''), '0'), '.') . '%');
    $sheet->setCellValue("E{$row}", (float) $l['montant_normal']);
    $sheet->setCellValue("F{$row}", (float) $l['du']);
    $sheet->setCellValue("G{$row}", (float) $l['paye']);
    $sheet->setCellValue("H{$row}", (float) $l['solde']);
    $row++;
}
$sheet->setCellValue("C{$row}", 'TOTAL (' . count($cas_sociaux) . ')');
$sheet->setCellValue("E{$row}", $total_normal);
$sheet->setCellValue("F{$row}", $total_du);
$sheet->setCellValue("G{$row}", $total_paye);
$sheet->setCellValue("H{$row}", $total_du - $total_paye);
$sheet->getStyle("C{$row}:H{$row}")->getFont()->setBold(true);
$sheet->getStyle("C{$row}:H{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D6EAF8');

if ($cas_sociaux) {
    $sheet->getStyle("A{$hdr_row}:H{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $sheet->getStyle("E" . ($hdr_row + 1) . ":H{$row}")->getNumberFormat()->setFormatCode('#,##0');
}
$sheet->getColumnDimension('A')->setWidth(14);
$sheet->getColumnDimension('B')->setWidth(30);
$sheet->getColumnDimension('C')->setWidth(16);
foreach (['D', 'E', 'F', 'G', 'H'] as $col) $sheet->getColumnDimension($col)->setWidth(15);

$chemin_sig = signature_etablissement_chemin();
if (($_GET['signature'] ?? '0') === '1' && $chemin_sig) {
    $sig_row = $row + 2;
    $sig_drawing = new Drawing();
    $sig_drawing->setName('Signature');
    $sig_drawing->setPath($chemin_sig);
    $sig_drawing->setHeight(50);
    $sig_drawing->setCoordinates("B{$sig_row}");
    $sig_drawing->setWorksheet($sheet);
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="cas_sociaux_' . date('Ymd') . '.xlsx"');
header('Cache-Control: max-age=0');
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
