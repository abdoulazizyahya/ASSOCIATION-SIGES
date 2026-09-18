<?php
// secondaire/pages/depenses_privees/excel_journal.php — Export Excel du
// journal des dépenses PRIVÉES — porté de pages/depenses/excel_journal.php
// (primaire), adapté au schéma secondaire.
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

$date_debut   = $_GET['debut'] ?? date('Y-m-01');
$date_fin     = $_GET['fin'] ?? date('Y-m-d');
$id_categorie = (int) ($_GET['categorie'] ?? 0);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_debut)) $date_debut = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_fin))   $date_fin   = date('Y-m-d');

$params = [$id_annee, $date_debut, $date_fin];
$sql = "SELECT d.id, d.date_depense, d.montant, d.libelle, d.beneficiaire, d.observation,
               cd.libelle AS categorie_libelle, u.nom, u.prenom
        FROM depense_privee d
        JOIN categorie_depense_privee cd ON cd.id = d.id_categorie
        LEFT JOIN utilisateur u ON u.id = d.id_utilisateur
        WHERE d.id_annee = ? AND d.date_depense BETWEEN ? AND ?";
if ($id_categorie) { $sql .= " AND d.id_categorie = ?"; $params[] = $id_categorie; }
$sql .= " ORDER BY d.date_depense, d.id";
$lignes = db_all($sql, $params);

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Journal des dépenses');

$logo_src_path  = !empty($etab['logo']) ? __DIR__ . '/../../../assets/uploads/' . $etab['logo'] : '';
$filigrane_path = __DIR__ . '/../../../assets/uploads/filigrane_excel.png';
if ($logo_src_path && generer_filigrane_excel($logo_src_path, $filigrane_path)) {
    $sheet->setBackgroundImage(file_get_contents($filigrane_path));
}

$lettre = fn(int $c) => Coordinate::stringFromColumnIndex($c);
$col_total = 7;

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
$sheet->setCellValue('A5', 'JOURNAL DES DÉPENSES — PAIEMENT PRIVÉ');
$sheet->getStyle('A5')->getFont()->setBold(true)->setSize(16);
$sheet->getStyle('A5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$categorie_nom = $id_categorie ? (string) db_val("SELECT libelle FROM categorie_depense_privee WHERE id=?", [$id_categorie]) : '';
$sous_titre = 'Du ' . date_fr($date_debut) . ' au ' . date_fr($date_fin) . ' — Année ' . $val_annee . ($categorie_nom ? ' — Catégorie ' . $categorie_nom : '');
$sheet->mergeCells("A6:{$lettre($col_total)}6");
$sheet->setCellValue('A6', $sous_titre);
$sheet->getStyle('A6')->getFont()->setBold(true)->setSize(11);
$sheet->getStyle('A6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$row = 8;
$headers = ['Date', 'N° bon', 'Catégorie', 'Libellé', 'Bénéficiaire', 'Enregistré par', 'Montant'];
foreach ($headers as $i => $hh) $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . $row, $hh);
$sheet->getStyle("A{$row}:G{$row}")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
$sheet->getStyle("A{$row}:G{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DC2626');
$row++;
$hdr_row = $row - 1;

$total = 0.0;
foreach ($lignes as $l) {
    $sheet->setCellValue("A{$row}", date_fr($l['date_depense']));
    $sheet->setCellValue("B{$row}", finances_numero_bon((int) $l['id']));
    $sheet->setCellValue("C{$row}", xl_safe($l['categorie_libelle']));
    $sheet->setCellValue("D{$row}", xl_safe($l['libelle']));
    $sheet->setCellValue("E{$row}", xl_safe($l['beneficiaire'] ?: ''));
    $sheet->setCellValue("F{$row}", xl_safe($l['nom'] ? trim($l['prenom'] . ' ' . $l['nom']) : ''));
    $sheet->setCellValue("G{$row}", (float) $l['montant']);
    $total += (float) $l['montant'];
    $row++;
}
$last_data_row = $row - 1;

$sheet->setCellValue("F{$row}", 'TOTAL (' . count($lignes) . ')');
$sheet->setCellValue("G{$row}", $total);
$sheet->getStyle("F{$row}:G{$row}")->getFont()->setBold(true);
$sheet->getStyle("F{$row}:G{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FCE2E2');

if ($lignes) {
    $sheet->getStyle("A{$hdr_row}:G{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $sheet->getStyle("G" . ($hdr_row + 1) . ":G{$row}")->getNumberFormat()->setFormatCode('#,##0');
}
$sheet->getColumnDimension('A')->setWidth(12);
$sheet->getColumnDimension('B')->setWidth(12);
$sheet->getColumnDimension('C')->setWidth(20);
$sheet->getColumnDimension('D')->setWidth(28);
$sheet->getColumnDimension('E')->setWidth(20);
$sheet->getColumnDimension('F')->setWidth(18);
$sheet->getColumnDimension('G')->setWidth(14);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="journal_depenses_prive_' . $date_debut . '_' . $date_fin . '.xlsx"');
header('Cache-Control: max-age=0');
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
