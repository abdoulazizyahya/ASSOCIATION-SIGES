<?php
// secondaire/pages/depenses_privees/excel_categories.php — Export Excel du
// catalogue des catégories de dépenses PRIVÉES — porté de
// pages/depenses/excel_categories.php (primaire), adapté au schéma
// secondaire.
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../conseil_classe/excel_releve_commun.php'; // generer_filigrane_excel()
exiger_role(['ADMIN', 'PROVISEUR', 'INTENDANT']);

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

$categories = db_all(
    "SELECT cd.*, COUNT(d.id) AS nb_depenses, COALESCE(SUM(d.montant),0) AS total_depense
     FROM categorie_depense_privee cd
     LEFT JOIN depense_privee d ON d.id_categorie = cd.id
     GROUP BY cd.id
     ORDER BY cd.libelle"
);

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Catégories de dépenses');

$logo_src_path  = !empty($etab['logo']) ? __DIR__ . '/../../../assets/uploads/' . $etab['logo'] : '';
$filigrane_path = __DIR__ . '/../../../assets/uploads/filigrane_excel_' . md5((string) ($etab['logo'] ?? '')) . '.png'; // un fichier PAR logo : un nom fixe partagé mélangeait les écoles
if ($logo_src_path && generer_filigrane_excel($logo_src_path, $filigrane_path)) {
    $sheet->setBackgroundImage(file_get_contents($filigrane_path));
}

$lettre = fn(int $c) => Coordinate::stringFromColumnIndex($c);
$col_total = 4;

$texte_etab = "REGION DE L'ADAMAOUA\n" .
    ($etab['departement_fr'] ?? 'DEPARTEMENT DE LA VINA') . "\n" .
    ($etab['arrondissement_fr'] ?? 'ARRONDISSEMENT DE MBE') . "\n" .
    '***********';
$nom_etab = strtoupper($etab['nom_fr'] ?? 'ÉTABLISSEMENT');
$sheet->mergeCells("A1:A4");
$rt = new RichText();
$r1 = $rt->createTextRun($texte_etab . "\n");
$r1->getFont()->setSize(9);
$r2 = $rt->createTextRun($nom_etab);
$r2->getFont()->setBold(true)->setSize(11);
$cell = $sheet->getCell('A1');
$cell->setValue($rt);
$cell->getStyle()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
$sheet->mergeCells("B1:C4");
if ($logo_src_path && is_file($logo_src_path)) {
    $drawing = new Drawing();
    $drawing->setName('Logo');
    $drawing->setPath($logo_src_path);
    $drawing->setHeight(70);
    $drawing->setCoordinates('B1');
    $drawing->setOffsetX(4);
    $drawing->setOffsetY(4);
    $drawing->setWorksheet($sheet);
}
$sheet->mergeCells("D1:D4");

$sheet->mergeCells("A5:{$lettre($col_total)}5");
$sheet->setCellValue('A5', 'CATALOGUE DES CATÉGORIES DE DÉPENSES PRIVÉES');
$sheet->getStyle('A5')->getFont()->setBold(true)->setSize(15);
$sheet->getStyle('A5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$row = 7;
$headers = ['Libellé', 'Description', 'Nb dépenses', 'Total dépensé'];
foreach ($headers as $i => $hh) $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . $row, $hh);
$sheet->getStyle("A{$row}:D{$row}")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
$sheet->getStyle("A{$row}:D{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DC2626');
$row++;
$hdr_row = $row - 1;

$total_general = 0.0;
foreach ($categories as $c) {
    $sheet->setCellValue("A{$row}", xl_safe($c['libelle']));
    $sheet->setCellValue("B{$row}", xl_safe($c['description'] ?: ''));
    $sheet->setCellValue("C{$row}", (int) $c['nb_depenses']);
    $sheet->setCellValue("D{$row}", (float) $c['total_depense']);
    $total_general += (float) $c['total_depense'];
    $row++;
}
$sheet->setCellValue("C{$row}", 'TOTAL (' . count($categories) . ' catégorie(s))');
$sheet->setCellValue("D{$row}", $total_general);
$sheet->getStyle("C{$row}:D{$row}")->getFont()->setBold(true);
$sheet->getStyle("C{$row}:D{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FCE2E2');

if ($categories) {
    $sheet->getStyle("A{$hdr_row}:D{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $sheet->getStyle("D" . ($hdr_row + 1) . ":D{$row}")->getNumberFormat()->setFormatCode('#,##0');
}
$sheet->getColumnDimension('A')->setWidth(24);
$sheet->getColumnDimension('B')->setWidth(34);
$sheet->getColumnDimension('C')->setWidth(16);
$sheet->getColumnDimension('D')->setWidth(16);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="categories_depenses_prive.xlsx"');
header('Cache-Control: max-age=0');
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
