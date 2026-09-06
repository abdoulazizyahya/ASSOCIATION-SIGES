<?php
// ── Excel : Résultat annuel (par classe OU établissement) ────────────
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/commun.php';
require_once __DIR__ . '/../conseil_classe/excel_releve_commun.php'; // generer_filigrane_excel(), fmt2()
exiger_acces_pedagogie();

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

$scope     = in_array($_GET['scope'] ?? '', ['classe', 'etablissement'], true) ? $_GET['scope'] : 'classe';
$id_classe = (int) ($_GET['classe'] ?? 0);
$filtre    = in_array($_GET['filtre'] ?? '', ['admis', 'redoublants', 'exclus', 'tous'], true) ? $_GET['filtre'] : 'tous';
$limite    = max(0, (int) ($_GET['limite'] ?? 10));
$ordre     = ($_GET['ordre'] ?? 'merite') === 'alpha' ? 'alpha' : 'merite';
if ($scope === 'etablissement') exiger_role(['DIRECTEUR']);

$annee_act = get_annee_active();
$val_annee = $annee_act['val_annee'] ?? '';
$etab_brut = get_etablissement();
$etab      = etab_pour_pdf($etab_brut);

$avec_classe = $scope === 'etablissement';
if ($scope === 'classe') {
    if (!$id_classe) die('Classe manquante.');
    exiger_acces_classe($id_classe, $val_annee, 'fr');   // cloisonnement enseignant
    $classe = db_one("SELECT DesignationClasses FROM classe WHERE IDClasses=?", [$id_classe]);
    $lignes = resultat_annuel_filtrer(resultat_annuel_lignes_classe($id_classe, $val_annee, $ordre), $filtre);
    $sous_titre = mb_strtoupper($classe['DesignationClasses'] ?? '') . ' — ' . ucfirst($filtre === 'tous' ? 'toute la classe' : $filtre);
} else {
    $lignes = resultat_annuel_lignes_etablissement($val_annee, $limite, $ordre);
    $sous_titre = 'PALMARÈS ÉTABLISSEMENT — ' . ($limite > 0 ? 'Top ' . $limite : 'Tous les classés');
}

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Resultat annuel');

$logo_src_path  = !empty($etab['logo']) ? __DIR__ . '/../../assets/uploads/' . $etab['logo'] : '';
$filigrane_path = __DIR__ . '/../../assets/uploads/filigrane_excel.png';
if ($logo_src_path && generer_filigrane_excel($logo_src_path, $filigrane_path)) {
    $sheet->setBackgroundImage(file_get_contents($filigrane_path));
}

$headers = ['N°', 'Matricule', 'Nom et prénoms'];
if ($avec_classe) $headers[] = 'Classe';
$headers = array_merge($headers, ['Sexe', 'Moy. T1', 'Moy. T2', 'Moy. T3', "Heures d'abs.", 'Moy. annuelle', 'Rang', 'Décision', 'Classe suivante']);
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
$sheet->setCellValue('A5', 'RÉSULTAT ANNUEL');
$sheet->getStyle('A5')->getFont()->setBold(true)->setSize(16);
$sheet->getStyle('A5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->mergeCells("A6:{$lettre($col_total)}6");
$sheet->setCellValue('A6', $sous_titre . ' — ' . $val_annee);
$sheet->getStyle('A6')->getFont()->setBold(true)->setSize(11);
$sheet->getStyle('A6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$row = 8;
foreach ($headers as $i => $h) $sheet->setCellValue($lettre($i + 1) . $row, $h);
$sheet->getStyle("A{$row}:{$lettre($col_total)}{$row}")->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFFFF'));
$sheet->getStyle("A{$row}:{$lettre($col_total)}{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1A3C6B');
$hdr_row = $row; $row++;

foreach ($lignes as $i => $l) {
    $e = $l['eleve'];
    $c = 1;
    $sheet->setCellValue($lettre($c++) . $row, $i + 1);
    $sheet->setCellValue($lettre($c++) . $row, $e['Mat_elv'] ?? '');
    $sheet->setCellValue($lettre($c++) . $row, mb_strtoupper($e['Nom_elv']) . ' ' . ($e['Prenom_elv'] ?? ''));
    if ($avec_classe) $sheet->setCellValue($lettre($c++) . $row, $l['classe'] ?? '—');
    $sheet->setCellValue($lettre($c++) . $row, stripos($e['Sexe_elv'] ?? '', 'F') === 0 ? 'F' : 'M');
    foreach ([0, 1, 2] as $ti) {
        if ($l['moy_t'][$ti] !== null) $sheet->setCellValue($lettre($c) . $row, (float) fmt2($l['moy_t'][$ti]));
        $c++;
    }
    $sheet->setCellValue($lettre($c++) . $row, $l['abs_nj']);
    if ($l['moy_annuelle'] !== null) $sheet->setCellValue($lettre($c) . $row, (float) fmt2($l['moy_annuelle']));
    $c++;
    $sheet->setCellValue($lettre($c++) . $row, $l['rang'] !== null ? $l['rang'] . 'e/' . $l['nb_classes'] : '—');
    $sheet->setCellValue($lettre($c++) . $row, $l['decision']);
    $sheet->setCellValue($lettre($c++) . $row, $l['classe_suivante'] ?? '—');
    $row++;
}
$sheet->getStyle("A{$hdr_row}:{$lettre($col_total)}" . ($row - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
$sheet->getColumnDimension('A')->setWidth(6);
$sheet->getColumnDimension('C')->setWidth(28);
foreach (range('D', $lettre($col_total)) as $col) $sheet->getColumnDimension($col)->setWidth(12);

$chemin_sig = signature_etablissement_chemin();
if (($_GET['signature'] ?? '0') === '1' && $chemin_sig) {
    $row += 2;
    $sig = new Drawing();
    $sig->setName('Signature'); $sig->setPath($chemin_sig); $sig->setHeight(50);
    $sig->setCoordinates("B{$row}"); $sig->setWorksheet($sheet);
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="resultat_annuel_' . $scope . '_' . date('Ymd') . '.xlsx"');
header('Cache-Control: max-age=0');
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
