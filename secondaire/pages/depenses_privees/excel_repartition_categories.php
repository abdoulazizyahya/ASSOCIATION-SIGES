<?php
// secondaire/pages/depenses_privees/excel_repartition_categories.php —
// Export Excel de la répartition des dépenses PRIVÉES par catégorie —
// porté de pages/depenses/excel_repartition_categories.php (primaire),
// adapté au schéma secondaire.
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../conseil_classe/excel_releve_commun.php'; // generer_filigrane_excel()
exiger_role(['ADMIN', 'PROVISEUR', 'SECRETAIRE', 'INTENDANT']);

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

$annee     = get_annee_active();
$id_annee  = (int) ($annee['id'] ?? 0);
$val_annee = $annee['val_annee'] ?? ($annee['libelle'] ?? '');
$etab_brut = get_etablissement();
$etab      = etab_pour_pdf($etab_brut);

$lignes = db_all(
    "SELECT cd.libelle, COUNT(d.id) AS nb, COALESCE(SUM(d.montant),0) AS total
     FROM categorie_depense_privee cd
     LEFT JOIN depense_privee d ON d.id_categorie = cd.id AND d.id_annee = ?
     GROUP BY cd.id
     ORDER BY total DESC",
    [$id_annee]
);
$total_general = array_sum(array_column($lignes, 'total'));
foreach ($lignes as &$l) {
    $l['total'] = (float) $l['total'];
    $l['pct']   = $total_general > 0 ? round($l['total'] / $total_general * 100, 1) : 0.0;
}
unset($l);

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Répartition par catégorie');

$logo_src_path  = !empty($etab['logo']) ? __DIR__ . '/../../../assets/uploads/' . $etab['logo'] : '';
$filigrane_path = __DIR__ . '/../../../assets/uploads/filigrane_excel.png';
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
$sheet->setCellValue('A5', 'RÉPARTITION DES DÉPENSES PRIVÉES PAR CATÉGORIE');
$sheet->getStyle('A5')->getFont()->setBold(true)->setSize(15);
$sheet->getStyle('A5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->mergeCells("A6:{$lettre($col_total)}6");
$sheet->setCellValue('A6', 'Année scolaire ' . $val_annee . ' — Total dépensé : ' . number_format($total_general, 0, ',', ' ') . ' F');
$sheet->getStyle('A6')->getFont()->setBold(true)->setSize(11);
$sheet->getStyle('A6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$row = 8;
$headers = ['Catégorie', 'Nb dépenses', 'Montant dépensé', '% du total'];
foreach ($headers as $i => $hh) $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . $row, $hh);
$sheet->getStyle("A{$row}:D{$row}")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
$sheet->getStyle("A{$row}:D{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DC2626');
$row++;
$hdr_row = $row - 1;

foreach ($lignes as $l) {
    $sheet->setCellValue("A{$row}", xl_safe($l['libelle']));
    $sheet->setCellValue("B{$row}", (int) $l['nb']);
    $sheet->setCellValue("C{$row}", $l['total']);
    $sheet->setCellValue("D{$row}", $l['pct'] . '%');
    $row++;
}
$last_data_row = $row - 1;

$sheet->setCellValue("A{$row}", 'TOTAL (' . count($lignes) . ' catégorie(s))');
$sheet->setCellValue("B{$row}", array_sum(array_column($lignes, 'nb')));
$sheet->setCellValue("C{$row}", $total_general);
$sheet->setCellValue("D{$row}", '100%');
$sheet->getStyle("A{$row}:D{$row}")->getFont()->setBold(true);
$sheet->getStyle("A{$row}:D{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FCE2E2');

if ($lignes) {
    $sheet->getStyle("A{$hdr_row}:D{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $sheet->getStyle("C" . ($hdr_row + 1) . ":C{$row}")->getNumberFormat()->setFormatCode('#,##0');
}
$sheet->getColumnDimension('A')->setWidth(28);
$sheet->getColumnDimension('B')->setWidth(14);
$sheet->getColumnDimension('C')->setWidth(18);
$sheet->getColumnDimension('D')->setWidth(12);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="repartition_depenses_prive_' . preg_replace('/[^A-Za-z0-9]/', '_', $val_annee) . '.xlsx"');
header('Cache-Control: max-age=0');
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
