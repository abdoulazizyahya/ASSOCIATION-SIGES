<?php
// pages/finances/excel_repartition_classes.php — Export Excel de la
// répartition des encaissements par classe, mêmes données que
// pages/finances/repartition_classes.php.
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../conseil_classe/excel_releve_commun.php'; // generer_filigrane_excel()
exiger_role(['DIRECTEUR', 'SECRETAIRE']);

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

$classes = db_all(
    "SELECT c.IDClasses, c.DesignationClasses, c.Niveau, n.OrdreNiveau FROM classe c
     LEFT JOIN niveau n ON n.LibelleNiveau = c.Niveau
     ORDER BY n.OrdreNiveau, c.DesignationClasses"
);
$nb_par_classe = [];
foreach (db_all(
    "SELECT i.IDClasses, COUNT(DISTINCT i.id_eleve) AS nb FROM inscrire i
     JOIN eleve e ON e.id_eleve = i.id_eleve AND e.statut='actif'
     WHERE i.val_annee = ? GROUP BY i.IDClasses",
    [$val_annee]
) as $r) { $nb_par_classe[(int) $r['IDClasses']] = (int) $r['nb']; }
$paye_par_classe = [];
foreach (db_all(
    "SELECT classe, SUM(montant_paiement) AS paye FROM paiement_frais WHERE val_annee = ? GROUP BY classe",
    [$val_annee]
) as $r) { $paye_par_classe[(int) $r['classe']] = (float) $r['paye']; }

// Montant dû par élève (après réduction "Cas social" éventuelle,
// migration_v39) — voir finances_du_par_eleve() (fonctions.php), regroupé
// par classe pour cette page.
$du_par_classe = [];
foreach (finances_du_par_eleve($val_annee) as $e) {
    $du_par_classe[(int) $e['IDClasses']] = ($du_par_classe[(int) $e['IDClasses']] ?? 0.0) + $e['du'];
}

$lignes = [];
$total_apport_general = 0.0;
$total_du_general     = 0.0;
foreach ($classes as $c) {
    $id_classe = (int) $c['IDClasses'];
    $nb        = $nb_par_classe[$id_classe] ?? 0;
    $apport    = $paye_par_classe[$id_classe] ?? 0.0;
    $du        = $du_par_classe[$id_classe] ?? 0.0;
    $lignes[]  = ['classe' => $c['DesignationClasses'], 'nb' => $nb, 'du' => $du, 'apport' => $apport];
    $total_apport_general += $apport;
    $total_du_general     += $du;
}
foreach ($lignes as &$l) {
    $l['pct']  = $total_apport_general > 0 ? round($l['apport'] / $total_apport_general * 100, 1) : 0.0;
    $l['taux'] = $l['du'] > 0 ? round($l['apport'] / $l['du'] * 100, 1) : 0.0;
}
unset($l);
usort($lignes, fn($a, $b) => $b['apport'] <=> $a['apport']);

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Répartition par classe');

$logo_src_path  = !empty($etab['logo']) ? __DIR__ . '/../../assets/uploads/' . $etab['logo'] : '';
$filigrane_path = __DIR__ . '/../../assets/uploads/filigrane_excel.png';
if ($logo_src_path && generer_filigrane_excel($logo_src_path, $filigrane_path)) {
    $sheet->setBackgroundImage(file_get_contents($filigrane_path));
}

$lettre = fn(int $c) => Coordinate::stringFromColumnIndex($c);
$col_total = 6;

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
$sheet->setCellValue('A5', 'RÉPARTITION DES ENCAISSEMENTS PAR CLASSE');
$sheet->getStyle('A5')->getFont()->setBold(true)->setSize(15);
$sheet->getStyle('A5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->mergeCells("A6:{$lettre($col_total)}6");
$sheet->setCellValue('A6', 'Année scolaire ' . $val_annee . ' — Total encaissé : ' . number_format($total_apport_general, 0, ',', ' ') . ' F');
$sheet->getStyle('A6')->getFont()->setBold(true)->setSize(11);
$sheet->getStyle('A6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$row = 8;
$headers = ['Classe', 'Élèves', 'Dû', 'Apport (encaissé)', 'Taux de recouvrement', '% du total'];
foreach ($headers as $i => $hh) $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . $row, $hh);
$sheet->getStyle("A{$row}:F{$row}")->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFFFF'));
$sheet->getStyle("A{$row}:F{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1A3C6B');
$row++;
$hdr_row = $row - 1;

foreach ($lignes as $l) {
    $sheet->setCellValue("A{$row}", $l['classe']);
    $sheet->setCellValue("B{$row}", $l['nb']);
    $sheet->setCellValue("C{$row}", $l['du']);
    $sheet->setCellValue("D{$row}", $l['apport']);
    $sheet->setCellValue("E{$row}", $l['taux'] . '%');
    $sheet->setCellValue("F{$row}", $l['pct'] . '%');
    $row++;
}
$last_data_row = $row - 1;

$sheet->setCellValue("A{$row}", 'TOTAL (' . count($lignes) . ' classe(s))');
$sheet->setCellValue("B{$row}", array_sum(array_column($lignes, 'nb')));
$sheet->setCellValue("C{$row}", $total_du_general);
$sheet->setCellValue("D{$row}", $total_apport_general);
$sheet->setCellValue("F{$row}", '100%');
$sheet->getStyle("A{$row}:F{$row}")->getFont()->setBold(true);
$sheet->getStyle("A{$row}:F{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D6EAF8');

if ($lignes) {
    $sheet->getStyle("A{$hdr_row}:F{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $sheet->getStyle("C" . ($hdr_row + 1) . ":D{$row}")->getNumberFormat()->setFormatCode('#,##0');
}
$sheet->getColumnDimension('A')->setWidth(24);
$sheet->getColumnDimension('B')->setWidth(12);
$sheet->getColumnDimension('C')->setWidth(16);
$sheet->getColumnDimension('D')->setWidth(18);
$sheet->getColumnDimension('E')->setWidth(20);
$sheet->getColumnDimension('F')->setWidth(14);

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
header('Content-Disposition: attachment;filename="repartition_classes_' . preg_replace('/[^A-Za-z0-9]/', '_', $val_annee) . '.xlsx"');
header('Cache-Control: max-age=0');
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
