<?php
// secondaire/pages/paiements_prives/excel_etat_classe.php — Export Excel
// de l'état des paiements PRIVÉS d'une classe — porté de
// pages/finances/excel_etat_classe.php (primaire), adapté au schéma
// secondaire.
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

$id_classe = (int) ($_GET['classe'] ?? 0);
$classe    = db_one("SELECT * FROM classe WHERE id=?", [$id_classe]);
if (!$classe) die('Classe introuvable.');

$annee     = get_annee_active();
$id_annee  = (int) ($annee['id'] ?? 0);
$val_annee = $annee['val_annee'] ?? ($annee['libelle'] ?? '');
$etab_brut = get_etablissement();
$etab      = etab_pour_pdf($etab_brut);

$eleves = prive_finances_du_par_eleve($id_annee, $id_classe);
$payes = [];
foreach (db_all(
    "SELECT id_eleve, SUM(montant_paiement) AS paye FROM paiement_prive WHERE id_classe=? AND id_annee=? GROUP BY id_eleve",
    [$id_classe, $id_annee]
) as $r) { $payes[(int) $r['id_eleve']] = (float) $r['paye']; }

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Etat des paiements');

$logo_src_path  = !empty($etab['logo']) ? __DIR__ . '/../../../assets/uploads/' . $etab['logo'] : '';
$filigrane_path = __DIR__ . '/../../../assets/uploads/filigrane_excel_' . md5((string) ($etab['logo'] ?? '')) . '.png'; // un fichier PAR logo : un nom fixe partagé mélangeait les écoles
if ($logo_src_path && generer_filigrane_excel($logo_src_path, $filigrane_path)) {
    $sheet->setBackgroundImage(file_get_contents($filigrane_path));
}

$lettre = fn(int $c) => Coordinate::stringFromColumnIndex($c);
$col_total = 6;

$texte_etab = "REGION DE L'ADAMAOUA\n" .
    ($etab['departement_fr'] ?? 'DEPARTEMENT DE LA VINA') . "\n" .
    ($etab['arrondissement_fr'] ?? 'ARRONDISSEMENT DE MBE') . "\n" .
    '***********';
$nom_etab = strtoupper($etab['nom_fr'] ?? 'ÉTABLISSEMENT');
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
$sheet->setCellValue('A5', xl_safe('ÉTAT DES PAIEMENTS PRIVÉS — ' . mb_strtoupper($classe['designation'])));
$sheet->getStyle('A5')->getFont()->setBold(true)->setSize(15);
$sheet->getStyle('A5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->mergeCells("A6:{$lettre($col_total)}6");
$sheet->setCellValue('A6', 'Année ' . $val_annee . ' — Frais dû par élève : ' . number_format($eleves[0]['du'] ?? 0.0, 0, ',', ' ') . ' FCFA');
$sheet->getStyle('A6')->getFont()->setBold(true)->setSize(11);
$sheet->getStyle('A6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$row = 8;
$headers = ['Matricule', 'Nom et prénom', 'Dû', 'Payé', 'Solde', 'Statut'];
foreach ($headers as $i => $hh) $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . $row, $hh);
$sheet->getStyle("A{$row}:F{$row}")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
$sheet->getStyle("A{$row}:F{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1A3C6B');
$row++;
$hdr_row = $row - 1;

$total_paye = 0.0; $total_du = 0.0;
foreach ($eleves as $e) {
    $paye  = $payes[(int) $e['id']] ?? 0.0;
    $solde = $e['du'] - $paye;
    $total_paye += $paye;
    $total_du   += $e['du'];
    $statut = $solde <= 0 ? 'Soldé' : ($paye > 0 ? 'Partiel' : 'Impayé');
    $nom = $e['nom'] . ' ' . ($e['prenom'] ?? '');
    $sheet->setCellValue("A{$row}", xl_safe($e['matricule']));
    $sheet->setCellValue("B{$row}", xl_safe($nom));
    $sheet->setCellValue("C{$row}", (float) $e['du']);
    $sheet->setCellValue("D{$row}", (float) $paye);
    $sheet->setCellValue("E{$row}", (float) $solde);
    $sheet->setCellValue("F{$row}", $statut);
    $row++;
}
$sheet->setCellValue("B{$row}", 'TOTAL (' . count($eleves) . ')');
$sheet->setCellValue("C{$row}", $total_du);
$sheet->setCellValue("D{$row}", $total_paye);
$sheet->setCellValue("E{$row}", $total_du - $total_paye);
$sheet->getStyle("B{$row}:E{$row}")->getFont()->setBold(true);
$sheet->getStyle("B{$row}:E{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D6EAF8');

if ($eleves) {
    $sheet->getStyle("A{$hdr_row}:F{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $sheet->getStyle("C" . ($hdr_row + 1) . ":E{$row}")->getNumberFormat()->setFormatCode('#,##0');
}
$sheet->getColumnDimension('A')->setWidth(14);
$sheet->getColumnDimension('B')->setWidth(36);
$sheet->getColumnDimension('C')->setWidth(14);
$sheet->getColumnDimension('D')->setWidth(14);
$sheet->getColumnDimension('E')->setWidth(14);
$sheet->getColumnDimension('F')->setWidth(14);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="etat_paiements_prive_' . preg_replace('/[^A-Za-z0-9]/', '_', $classe['designation']) . '.xlsx"');
header('Cache-Control: max-age=0');
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
