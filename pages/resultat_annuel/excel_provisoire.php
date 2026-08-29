<?php
// ── Excel : Liste provisoire (effectif prévisionnel année suivante) ──
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/commun.php';
require_once __DIR__ . '/../conseil_classe/excel_releve_commun.php';
exiger_acces_pedagogie();

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

$id_classe = (int) ($_GET['classe'] ?? 0);
$ordre     = ($_GET['ordre'] ?? 'alpha') === 'merite' ? 'merite' : 'alpha';
if (!$id_classe) die('Classe manquante.');

$annee_act = get_annee_active();
$val_annee = $annee_act['val_annee'] ?? '';
$etab_brut = get_etablissement();
$etab      = etab_pour_pdf($etab_brut);
$classe = db_one("SELECT DesignationClasses FROM classe WHERE IDClasses=?", [$id_classe]);
if (!$classe) die('Classe introuvable.');
$val_annee_suivante = resultat_annuel_libelle_annee_suivante($val_annee);
$lignes = resultat_annuel_provisoire_classe($id_classe, $val_annee, $ordre);

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Liste provisoire');

$logo_src_path  = !empty($etab['logo']) ? __DIR__ . '/../../assets/uploads/' . $etab['logo'] : '';
$filigrane_path = __DIR__ . '/../../assets/uploads/filigrane_excel.png';
if ($logo_src_path && generer_filigrane_excel($logo_src_path, $filigrane_path)) {
    $sheet->setBackgroundImage(file_get_contents($filigrane_path));
}

$headers = ['N°', 'Matricule', 'Nom et prénoms', 'Date naiss.', 'Lieu naiss.', 'Sexe', 'Statut'];
$col_total = count($headers);
$lettre = fn(int $c) => Coordinate::stringFromColumnIndex($c);

$texte_etab = "REGION DE L'ADAMAOUA\n" . ($etab['departement_fr'] ?? 'DEPARTEMENT DE LA VINA') . "\n" . ($etab['arrondissement_fr'] ?? '') . "\n***********";
$nom_etab = strtoupper($etab['nom_fr'] ?? '');
$tiers = max(1, (int) floor($col_total / 3));
foreach ([[1, $tiers], [2 * $tiers + 1, $col_total]] as [$c1, $c2]) {
    $sheet->mergeCells("{$lettre($c1)}1:{$lettre($c2)}4");
    $rt = new RichText();
    $r1 = $rt->createTextRun($texte_etab . "\n"); $r1->getFont()->setSize(9);
    $r2 = $rt->createTextRun($nom_etab); $r2->getFont()->setBold(true)->setSize(11);
    $cell = $sheet->getCell("{$lettre($c1)}1");
    $cell->setValue($rt);
    $cell->getStyle()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
}
$sheet->mergeCells("{$lettre($tiers + 1)}1:{$lettre(2 * $tiers)}4");
if ($logo_src_path && is_file($logo_src_path)) {
    $drawing = new Drawing();
    $drawing->setName('Logo'); $drawing->setPath($logo_src_path); $drawing->setHeight(70);
    $drawing->setCoordinates($lettre($tiers + 1) . '1'); $drawing->setOffsetX(4); $drawing->setOffsetY(4);
    $drawing->setWorksheet($sheet);
}

$sheet->mergeCells("A5:{$lettre($col_total)}5");
$sheet->setCellValue('A5', 'LISTE PROVISOIRE');
$sheet->getStyle('A5')->getFont()->setBold(true)->setSize(16);
$sheet->getStyle('A5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->mergeCells("A6:{$lettre($col_total)}6");
$sheet->setCellValue('A6', mb_strtoupper($classe['DesignationClasses']) . ' — Effectif prévisionnel ' . $val_annee_suivante);
$sheet->getStyle('A6')->getFont()->setBold(true)->setSize(11);
$sheet->getStyle('A6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$row = 8;
foreach ($headers as $i => $h) $sheet->setCellValue($lettre($i + 1) . $row, $h);
$sheet->getStyle("A{$row}:{$lettre($col_total)}{$row}")->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFFFF'));
$sheet->getStyle("A{$row}:{$lettre($col_total)}{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1A3C6B');
$hdr_row = $row; $row++;

foreach ($lignes as $i => $l) {
    $e = $l['eleve'];
    $sheet->setCellValue("A{$row}", $i + 1);
    $sheet->setCellValue("B{$row}", $e['Mat_elv'] ?? '');
    $sheet->setCellValue("C{$row}", mb_strtoupper($e['Nom_elv']) . ' ' . ($e['Prenom_elv'] ?? ''));
    $sheet->setCellValue("D{$row}", $e['Date_naiss_elv'] ? date('d/m/Y', strtotime($e['Date_naiss_elv'])) : '—');
    $sheet->setCellValue("E{$row}", $e['Lieu_naiss_elv'] ?: '—');
    $sheet->setCellValue("F{$row}", stripos($e['Sexe_elv'] ?? '', 'F') === 0 ? 'F' : 'M');
    $sheet->setCellValue("G{$row}", $l['statut_code']);
    $row++;
}
$sheet->getStyle("A{$hdr_row}:{$lettre($col_total)}" . ($row - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
$sheet->getColumnDimension('A')->setWidth(6);
$sheet->getColumnDimension('C')->setWidth(30);
foreach (['B', 'D', 'E', 'F', 'G'] as $col) $sheet->getColumnDimension($col)->setWidth(16);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="liste_provisoire_' . date('Ymd') . '.xlsx"');
header('Cache-Control: max-age=0');
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
