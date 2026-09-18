<?php
// secondaire/pages/paiements_prives/excel_statistiques.php — Export Excel
// du bilan financier PRIVÉ (totaux + par niveau + par type + par mode +
// évolution mensuelle) — porté de pages/finances/excel_statistiques.php
// (primaire), adapté au schéma secondaire.
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

$annee     = get_annee_active();
$id_annee  = (int) ($annee['id'] ?? 0);
$val_annee = $annee['val_annee'] ?? ($annee['libelle'] ?? '');
$etab_brut = get_etablissement();
$etab      = etab_pour_pdf($etab_brut);

$nb_eleves_par_niveau = [];
foreach (db_all(
    "SELECT c.code_niveau, COUNT(DISTINCT i.id_eleve) AS nb
     FROM inscription i JOIN classe c ON c.id = i.id_classe
     JOIN eleve e ON e.id = i.id_eleve AND e.statut='actif'
     WHERE i.id_annee = ? GROUP BY c.code_niveau",
    [$id_annee]
) as $r) { $nb_eleves_par_niveau[$r['code_niveau']] = (int) $r['nb']; }

$tous_eleves_du = prive_finances_du_par_eleve($id_annee);
$du_reel_par_niveau = [];
foreach ($tous_eleves_du as $e) {
    $du_reel_par_niveau[$e['code_niveau']] = ($du_reel_par_niveau[$e['code_niveau']] ?? 0.0) + $e['du'];
}
$paye_par_niveau = [];
foreach (db_all(
    "SELECT c.code_niveau, SUM(p.montant_paiement) AS paye FROM paiement_prive p
     JOIN classe c ON c.id = p.id_classe WHERE p.id_annee=? GROUP BY c.code_niveau",
    [$id_annee]
) as $r) { $paye_par_niveau[$r['code_niveau']] = (float) $r['paye']; }

$niveaux = db_all("SELECT code_niveau, libelle_niv, ordre_niveau FROM niveau ORDER BY ordre_niveau");
$stats_niveau = []; $total_du_general = 0.0; $total_paye_general = 0.0;
foreach ($niveaux as $n) {
    $code = $n['code_niveau'];
    $nb   = $nb_eleves_par_niveau[$code] ?? 0;
    $du   = $du_reel_par_niveau[$code] ?? 0.0;
    $paye = $paye_par_niveau[$code] ?? 0.0;
    if ($nb === 0 && $du <= 0 && $paye <= 0) continue;
    $stats_niveau[] = ['niveau' => $n['libelle_niv'] ?: $code, 'nb' => $nb, 'du' => $du, 'paye' => $paye];
    $total_du_general += $du; $total_paye_general += $paye;
}
$solde_general = $total_du_general - $total_paye_general;
$taux_general  = $total_du_general > 0 ? round($total_paye_general / $total_du_general * 100, 1) : 0;

$obligations_toutes = db_all("SELECT * FROM obligation_privee");
$par_type = [];
foreach ($obligations_toutes as $o) {
    $nb = $nb_eleves_par_niveau[$o['code_niveau']] ?? 0;
    $par_type[$o['nom_obligation']]['du'] = ($par_type[$o['nom_obligation']]['du'] ?? 0.0) + (float) $o['montant_obligation'] * $nb;
    $par_type[$o['nom_obligation']]['paye'] = $par_type[$o['nom_obligation']]['paye'] ?? 0.0;
}
foreach (db_all(
    "SELECT o.nom_obligation, SUM(p.montant_paiement) AS paye FROM paiement_prive p
     JOIN obligation_privee o ON o.id=p.id_obligation WHERE p.id_annee=? GROUP BY o.nom_obligation",
    [$id_annee]
) as $r) { $par_type[$r['nom_obligation']]['paye'] = ($par_type[$r['nom_obligation']]['paye'] ?? 0.0) + (float) $r['paye']; }

$par_mode = [];
foreach (db_all(
    "SELECT mode_paiement, SUM(montant_paiement) AS total, COUNT(*) AS nb FROM paiement_prive
     WHERE id_annee=? GROUP BY mode_paiement",
    [$id_annee]
) as $r) {
    $code = finances_mode_paiement_normalise($r['mode_paiement']);
    $par_mode[$code] = ['total' => (float) $r['total'], 'nb' => (int) $r['nb']];
}
foreach (finances_modes_paiement() as $code => $m) { $par_mode[$code] ??= ['total' => 0.0, 'nb' => 0]; }

$mois_fr = [1=>'Janv', 2=>'Févr', 3=>'Mars', 4=>'Avr', 5=>'Mai', 6=>'Juin', 7=>'Juil', 8=>'Août', 9=>'Sept', 10=>'Oct', 11=>'Nov', 12=>'Déc'];
$evolution_mensuelle = [];
foreach (db_all(
    "SELECT DATE_FORMAT(date_paiement,'%Y-%m') AS mois, SUM(montant_paiement) AS total FROM paiement_prive
     WHERE id_annee=? GROUP BY mois ORDER BY mois",
    [$id_annee]
) as $r) {
    $num = (int) substr($r['mois'], 5, 2);
    $evolution_mensuelle[] = ['label' => ($mois_fr[$num] ?? $r['mois']) . ' ' . substr($r['mois'], 0, 4), 'total' => (float) $r['total']];
}

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Bilan financier');

$logo_src_path  = !empty($etab['logo']) ? __DIR__ . '/../../../assets/uploads/' . $etab['logo'] : '';
$filigrane_path = __DIR__ . '/../../../assets/uploads/filigrane_excel.png';
if ($logo_src_path && generer_filigrane_excel($logo_src_path, $filigrane_path)) {
    $sheet->setBackgroundImage(file_get_contents($filigrane_path));
}

$lettre = fn(int $c) => Coordinate::stringFromColumnIndex($c);
$col_total = 5;

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
$sheet->setCellValue('A5', 'BILAN FINANCIER — PAIEMENT PRIVÉ');
$sheet->getStyle('A5')->getFont()->setBold(true)->setSize(16);
$sheet->getStyle('A5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->mergeCells("A6:{$lettre($col_total)}6");
$sheet->setCellValue('A6', 'Année scolaire ' . $val_annee);
$sheet->getStyle('A6')->getFont()->setBold(true)->setSize(11);
$sheet->getStyle('A6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$row = 8;

$sheet->setCellValue("A{$row}", 'TOTAL DÛ'); $sheet->setCellValue("B{$row}", (float) $total_du_general);
$row++;
$sheet->setCellValue("A{$row}", 'TOTAL ENCAISSÉ'); $sheet->setCellValue("B{$row}", (float) $total_paye_general);
$row++;
$sheet->setCellValue("A{$row}", 'RESTE À RECOUVRER'); $sheet->setCellValue("B{$row}", (float) $solde_general);
$row++;
$sheet->setCellValue("A{$row}", 'TAUX DE RECOUVREMENT'); $sheet->setCellValue("B{$row}", $taux_general . ' %');
$sheet->getStyle("A8:A{$row}")->getFont()->setBold(true);
$sheet->getStyle("B8:B" . ($row - 1))->getNumberFormat()->setFormatCode('#,##0');
$row += 2;

$sheet->setCellValue("A{$row}", 'PAR NIVEAU');
$sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12);
$row++;
$hdr = $row;
foreach (['Niveau', 'Élèves', 'Dû', 'Payé', 'Recouvrement'] as $i => $hh) $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . $row, $hh);
$sheet->getStyle("A{$hdr}:E{$hdr}")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
$sheet->getStyle("A{$hdr}:E{$hdr}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1A3C6B');
$row++;
foreach ($stats_niveau as $s) {
    $t = $s['du'] > 0 ? round($s['paye'] / $s['du'] * 100) : 0;
    $sheet->setCellValue("A{$row}", xl_safe($s['niveau']));
    $sheet->setCellValue("B{$row}", $s['nb']);
    $sheet->setCellValue("C{$row}", (float) $s['du']);
    $sheet->setCellValue("D{$row}", (float) $s['paye']);
    $sheet->setCellValue("E{$row}", $t . ' %');
    $row++;
}
if ($stats_niveau) {
    $sheet->getStyle("A{$hdr}:E" . ($row - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $sheet->getStyle("C" . ($hdr + 1) . ":D" . ($row - 1))->getNumberFormat()->setFormatCode('#,##0');
}
$row += 2;

$sheet->setCellValue("A{$row}", 'PAR TYPE DE FRAIS');
$sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12);
$row++;
$hdr = $row;
foreach (['Frais', 'Dû', 'Payé'] as $i => $hh) $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . $row, $hh);
$sheet->getStyle("A{$hdr}:C{$hdr}")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
$sheet->getStyle("A{$hdr}:C{$hdr}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1A3C6B');
$row++;
foreach ($par_type as $nom => $t) {
    $sheet->setCellValue("A{$row}", xl_safe($nom));
    $sheet->setCellValue("B{$row}", (float) $t['du']);
    $sheet->setCellValue("C{$row}", (float) $t['paye']);
    $row++;
}
if ($par_type) {
    $sheet->getStyle("A{$hdr}:C" . ($row - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $sheet->getStyle("B" . ($hdr + 1) . ":C" . ($row - 1))->getNumberFormat()->setFormatCode('#,##0');
}
$row += 2;

$sheet->setCellValue("A{$row}", 'PAR MODE DE PAIEMENT');
$sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12);
$row++;
$hdr = $row;
foreach (['Mode', 'Versements', 'Total encaissé', 'Part'] as $i => $hh) $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . $row, $hh);
$sheet->getStyle("A{$hdr}:D{$hdr}")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
$sheet->getStyle("A{$hdr}:D{$hdr}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1A3C6B');
$row++;
foreach ($par_mode as $code => $pm) {
    $part = $total_paye_general > 0 ? round($pm['total'] / $total_paye_general * 100) : 0;
    $sheet->setCellValue("A{$row}", finances_mode_paiement_libelle($code));
    $sheet->setCellValue("B{$row}", $pm['nb']);
    $sheet->setCellValue("C{$row}", (float) $pm['total']);
    $sheet->setCellValue("D{$row}", $part . ' %');
    $row++;
}
if ($par_mode) {
    $sheet->getStyle("A{$hdr}:D" . ($row - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $sheet->getStyle("C" . ($hdr + 1) . ":C" . ($row - 1))->getNumberFormat()->setFormatCode('#,##0');
}
$row += 2;

$sheet->setCellValue("A{$row}", 'ÉVOLUTION MENSUELLE DES ENCAISSEMENTS');
$sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12);
$row++;
$hdr = $row;
foreach (['Mois', 'Total encaissé'] as $i => $hh) $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . $row, $hh);
$sheet->getStyle("A{$hdr}:B{$hdr}")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
$sheet->getStyle("A{$hdr}:B{$hdr}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1A3C6B');
$row++;
foreach ($evolution_mensuelle as $m) {
    $sheet->setCellValue("A{$row}", $m['label']);
    $sheet->setCellValue("B{$row}", (float) $m['total']);
    $row++;
}
if ($evolution_mensuelle) {
    $sheet->getStyle("A{$hdr}:B" . ($row - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $sheet->getStyle("B" . ($hdr + 1) . ":B" . ($row - 1))->getNumberFormat()->setFormatCode('#,##0');
}

$sheet->getColumnDimension('A')->setWidth(28);
foreach (['B', 'C', 'D', 'E'] as $col) $sheet->getColumnDimension($col)->setWidth(16);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="bilan_financier_prive_' . preg_replace('/[^A-Za-z0-9]/', '_', $val_annee) . '.xlsx"');
header('Cache-Control: max-age=0');
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
