<?php
// pages/finances/excel_obligations.php — Export Excel du catalogue des
// frais par niveau, mêmes données que pages/finances/obligations.php.
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../conseil_classe/excel_releve_commun.php'; // generer_filigrane_excel()
exiger_role(['DIRECTEUR']);

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

$etab_brut = get_etablissement();
$etab      = etab_pour_pdf($etab_brut);

$obligations = db_all(
    "SELECT o.*, n.OrdreNiveau FROM obligation o
     LEFT JOIN niveau n ON n.LibelleNiveau = o.niveau_obligation
     ORDER BY n.OrdreNiveau, o.nom_obligation"
);

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Obligations');

$logo_src_path  = !empty($etab['logo']) ? __DIR__ . '/../../assets/uploads/' . $etab['logo'] : '';
$filigrane_path = __DIR__ . '/../../assets/uploads/filigrane_excel.png';
if ($logo_src_path && generer_filigrane_excel($logo_src_path, $filigrane_path)) {
    $sheet->setBackgroundImage(file_get_contents($filigrane_path));
}

$lettre = fn(int $c) => Coordinate::stringFromColumnIndex($c);
$col_total = 3;

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
$sheet->setCellValue('A5', 'CATALOGUE DES FRAIS PAR NIVEAU');
$sheet->getStyle('A5')->getFont()->setBold(true)->setSize(15);
$sheet->getStyle('A5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$row = 7;
$headers = ['Niveau', 'Libellé', 'Montant'];
foreach ($headers as $i => $hh) $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . $row, $hh);
$sheet->getStyle("A{$row}:C{$row}")->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFFFF'));
$sheet->getStyle("A{$row}:C{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1A3C6B');
$row++;
$hdr_row = $row - 1;

$total_general = 0.0;
foreach ($obligations as $o) {
    $sheet->setCellValue("A{$row}", 'Niveau ' . $o['niveau_obligation']);
    $sheet->setCellValue("B{$row}", $o['nom_obligation']);
    $sheet->setCellValue("C{$row}", (float) $o['montant_obligation']);
    $total_general += (float) $o['montant_obligation'];
    $row++;
}
$sheet->setCellValue("B{$row}", 'TOTAL (' . count($obligations) . ' obligation(s))');
$sheet->setCellValue("C{$row}", $total_general);
$sheet->getStyle("B{$row}:C{$row}")->getFont()->setBold(true);
$sheet->getStyle("B{$row}:C{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D6EAF8');

if ($obligations) {
    $sheet->getStyle("A{$hdr_row}:C{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $sheet->getStyle("C" . ($hdr_row + 1) . ":C{$row}")->getNumberFormat()->setFormatCode('#,##0');
}
$sheet->getColumnDimension('A')->setWidth(14);
$sheet->getColumnDimension('B')->setWidth(30);
$sheet->getColumnDimension('C')->setWidth(16);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="obligations_frais.xlsx"');
header('Cache-Control: max-age=0');
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
