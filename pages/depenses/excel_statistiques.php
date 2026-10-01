<?php
// pages/depenses/excel_statistiques.php — Export Excel du bilan des
// dépenses (totaux + par catégorie + par mois), miroir de
// pages/depenses/excel_journal.php / pdf/depenses_statistiques.php.
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../conseil_classe/excel_releve_commun.php';
exiger_role(['DIRECTEUR']);

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

$total_encaisse = (float) (db_val("SELECT COALESCE(SUM(montant_paiement),0) FROM paiement_frais WHERE val_annee=?", [$val_annee]) ?? 0);
$total_depense  = (float) (db_val("SELECT COALESCE(SUM(montant),0) FROM depense WHERE val_annee=?", [$val_annee]) ?? 0);
$solde          = $total_encaisse - $total_depense;

$par_categorie = db_all(
    "SELECT cd.libelle, COUNT(d.id_depense) AS nb, COALESCE(SUM(d.montant),0) AS total
     FROM categorie_depense cd
     LEFT JOIN depense d ON d.id_categorie = cd.id_categorie AND d.val_annee = ?
     GROUP BY cd.id_categorie ORDER BY total DESC",
    [$val_annee]
);

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Bilan des dépenses');

$logo_src_path  = !empty($etab['logo']) ? __DIR__ . '/../../assets/uploads/' . $etab['logo'] : '';
$filigrane_path = __DIR__ . '/../../assets/uploads/filigrane_excel_' . md5((string) ($etab['logo'] ?? '')) . '.png'; // un fichier PAR logo : un nom fixe partagé mélangeait les écoles
if ($logo_src_path && generer_filigrane_excel($logo_src_path, $filigrane_path)) {
    $sheet->setBackgroundImage(file_get_contents($filigrane_path));
}

$lettre = fn(int $c) => Coordinate::stringFromColumnIndex($c);
$col_total = 4;

$texte_etab = "REGION DE L'ADAMAOUA\n" .
    ($etab['departement_fr'] ?? 'DEPARTEMENT DE LA VINA') . "\n" .
    ($etab['arrondissement_fr'] ?? 'ARRONDISSEMENT DE MBE') . "\n" .
    '***********';
$nom_etab = strtoupper($etab['nom_fr'] ?? 'GSBI LES POUSSINS DE JAYNITAARE');
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
$sheet->setCellValue('A5', 'BILAN DES DÉPENSES');
$sheet->getStyle('A5')->getFont()->setBold(true)->setSize(16);
$sheet->getStyle('A5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->mergeCells("A6:{$lettre($col_total)}6");
$sheet->setCellValue('A6', 'Année scolaire ' . $val_annee);
$sheet->getStyle('A6')->getFont()->setBold(true)->setSize(11);
$sheet->getStyle('A6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$row = 8;
$sheet->setCellValue("A{$row}", 'Total encaissé');
$sheet->setCellValue("B{$row}", $total_encaisse);
$sheet->getStyle("B{$row}")->getFont()->getColor()->setRGB('15803D');
$row++;
$sheet->setCellValue("A{$row}", 'Total dépensé');
$sheet->setCellValue("B{$row}", $total_depense);
$sheet->getStyle("B{$row}")->getFont()->getColor()->setRGB('DC2626');
$row++;
$sheet->setCellValue("A{$row}", 'Solde de caisse');
$sheet->setCellValue("B{$row}", $solde);
$sheet->getStyle("A{$row}:B{$row}")->getFont()->setBold(true);
$sheet->getStyle("B" . ($row - 2) . ":B{$row}")->getNumberFormat()->setFormatCode('#,##0');
$row += 2;

$sheet->setCellValue("A{$row}", 'Répartition par catégorie');
$sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12);
$row++;
$headers = ['Catégorie', 'Nb dépenses', 'Total', '% du total'];
foreach ($headers as $i => $hh) $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . $row, $hh);
$sheet->getStyle("A{$row}:D{$row}")->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFFFF'));
$sheet->getStyle("A{$row}:D{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DC2626');
$row++;
$hdr_row = $row - 1;

foreach ($par_categorie as $c) {
    $pct = $total_depense > 0 ? round((float) $c['total'] / $total_depense * 100, 1) : 0;
    $sheet->setCellValue("A{$row}", xl_safe($c['libelle']));
    $sheet->setCellValue("B{$row}", (int) $c['nb']);
    $sheet->setCellValue("C{$row}", (float) $c['total']);
    $sheet->setCellValue("D{$row}", $pct . '%');
    $row++;
}
$last_row = $row - 1;
if ($par_categorie) {
    $sheet->getStyle("A{$hdr_row}:D{$last_row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $sheet->getStyle("C" . ($hdr_row + 1) . ":C{$last_row}")->getNumberFormat()->setFormatCode('#,##0');
}
$sheet->getColumnDimension('A')->setWidth(28);
$sheet->getColumnDimension('B')->setWidth(14);
$sheet->getColumnDimension('C')->setWidth(16);
$sheet->getColumnDimension('D')->setWidth(12);

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
header('Content-Disposition: attachment;filename="bilan_depenses_' . preg_replace('/[^A-Za-z0-9]/', '_', $val_annee) . '.xlsx"');
header('Cache-Control: max-age=0');
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
