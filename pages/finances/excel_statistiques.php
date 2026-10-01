<?php
// pages/finances/excel_statistiques.php — Export Excel du bilan financier
// (totaux + par niveau + par type + évolution mensuelle), mêmes calculs que
// pages/finances/statistiques.php et pdf/finances_statistiques.php.
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../conseil_classe/excel_releve_commun.php'; // generer_filigrane_excel()
exiger_role(['DIRECTEUR', 'COMPTABLE']);

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

$nb_eleves_par_niveau = [];
foreach (db_all(
    "SELECT c.Niveau, COUNT(DISTINCT i.id_eleve) AS nb
     FROM inscrire i JOIN classe c ON c.IDClasses = i.IDClasses
     JOIN eleve e ON e.id_eleve = i.id_eleve AND e.statut='actif'
     WHERE i.val_annee = ? GROUP BY c.Niveau",
    [$val_annee]
) as $r) { $nb_eleves_par_niveau[$r['Niveau']] = (int) $r['nb']; }

// Montant dû par élève (après réduction "Cas social" éventuelle,
// migration_v39) — voir finances_du_par_eleve() (fonctions.php).
$tous_eleves_du = finances_du_par_eleve($val_annee);
$du_reel_par_niveau = [];
foreach ($tous_eleves_du as $e) {
    $du_reel_par_niveau[$e['Niveau']] = ($du_reel_par_niveau[$e['Niveau']] ?? 0.0) + $e['du'];
}
$paye_par_niveau = [];
foreach (db_all(
    "SELECT c.Niveau, SUM(p.montant_paiement) AS paye FROM paiement_frais p
     JOIN classe c ON c.IDClasses = p.classe WHERE p.val_annee=? GROUP BY c.Niveau",
    [$val_annee]
) as $r) { $paye_par_niveau[$r['Niveau']] = (float) $r['paye']; }

// Tous les niveaux actifs, avec ou sans classe (voir pages/finances/statistiques.php).
$niveaux = db_all(
    "SELECT LibelleNiveau, OrdreNiveau FROM niveau WHERE actif=1 ORDER BY OrdreNiveau"
);
$stats_niveau = []; $total_du_general = 0.0; $total_paye_general = 0.0;
foreach ($niveaux as $n) {
    $code = $n['LibelleNiveau'];
    $nb   = $nb_eleves_par_niveau[$code] ?? 0;
    $du   = $du_reel_par_niveau[$code] ?? 0.0;
    $paye = $paye_par_niveau[$code] ?? 0.0;
    $stats_niveau[] = ['niveau' => $code, 'nb' => $nb, 'du' => $du, 'paye' => $paye];
    $total_du_general += $du; $total_paye_general += $paye;
}
$solde_general = $total_du_general - $total_paye_general;
$taux_general  = $total_du_general > 0 ? round($total_paye_general / $total_du_general * 100, 1) : 0;

$obligations_toutes = db_all("SELECT * FROM obligation");
$obligations_par_niveau_liste = [];
foreach ($obligations_toutes as $o) $obligations_par_niveau_liste[$o['niveau_obligation']][] = $o;

$par_type = [];
foreach ($obligations_toutes as $o) {
    $nb = $nb_eleves_par_niveau[$o['niveau_obligation']] ?? 0;
    $par_type[$o['nom_obligation']]['du'] = ($par_type[$o['nom_obligation']]['du'] ?? 0.0) + (float) $o['montant_obligation'] * $nb;
    $par_type[$o['nom_obligation']]['paye'] = $par_type[$o['nom_obligation']]['paye'] ?? 0.0;
}
foreach ($tous_eleves_du as $e) {
    if (!$e['cas_social']) continue;
    foreach ($obligations_par_niveau_liste[$e['Niveau']] ?? [] as $o) {
        $par_type[$o['nom_obligation']]['du'] -= round((float) $o['montant_obligation'] * $e['pourcentage'] / 100, 2);
    }
}
foreach (db_all(
    "SELECT o.nom_obligation, SUM(p.montant_paiement) AS paye FROM paiement_frais p
     JOIN obligation o ON o.id_obligation=p.id_obligation WHERE p.val_annee=? GROUP BY o.nom_obligation",
    [$val_annee]
) as $r) { $par_type[$r['nom_obligation']]['paye'] = ($par_type[$r['nom_obligation']]['paye'] ?? 0.0) + (float) $r['paye']; }

// Répartition par mode de paiement (migration v43) — même calcul que
// pages/finances/statistiques.php.
$par_mode = [];
foreach (db_all(
    "SELECT mode_paiement, SUM(montant_paiement) AS total, COUNT(*) AS nb FROM paiement_frais
     WHERE val_annee=? GROUP BY mode_paiement",
    [$val_annee]
) as $r) {
    $code = finances_mode_paiement_normalise($r['mode_paiement']);
    $par_mode[$code] = ['total' => (float) $r['total'], 'nb' => (int) $r['nb']];
}
foreach (finances_modes_paiement() as $code => $m) { $par_mode[$code] ??= ['total' => 0.0, 'nb' => 0]; }

$mois_fr = [1=>'Janv', 2=>'Févr', 3=>'Mars', 4=>'Avr', 5=>'Mai', 6=>'Juin', 7=>'Juil', 8=>'Août', 9=>'Sept', 10=>'Oct', 11=>'Nov', 12=>'Déc'];
$evolution_mensuelle = [];
foreach (db_all(
    "SELECT LEFT(date_paiement,7) AS mois, SUM(montant_paiement) AS total FROM paiement_frais
     WHERE val_annee=? GROUP BY LEFT(date_paiement,7) ORDER BY mois",
    [$val_annee]
) as $r) {
    $num = (int) substr($r['mois'], 5, 2);
    $evolution_mensuelle[] = ['label' => ($mois_fr[$num] ?? $r['mois']) . ' ' . substr($r['mois'], 0, 4), 'total' => (float) $r['total']];
}

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Bilan financier');

$logo_src_path  = !empty($etab['logo']) ? __DIR__ . '/../../assets/uploads/' . $etab['logo'] : '';
$filigrane_path = __DIR__ . '/../../assets/uploads/filigrane_excel_' . md5((string) ($etab['logo'] ?? '')) . '.png'; // un fichier PAR logo : un nom fixe partagé mélangeait les écoles
if ($logo_src_path && generer_filigrane_excel($logo_src_path, $filigrane_path)) {
    $sheet->setBackgroundImage(file_get_contents($filigrane_path));
}

$lettre = fn(int $c) => Coordinate::stringFromColumnIndex($c);
$col_total = 5;

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
$sheet->setCellValue('A5', 'BILAN FINANCIER');
$sheet->getStyle('A5')->getFont()->setBold(true)->setSize(16);
$sheet->getStyle('A5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->mergeCells("A6:{$lettre($col_total)}6");
$sheet->setCellValue('A6', 'Année scolaire ' . $val_annee);
$sheet->getStyle('A6')->getFont()->setBold(true)->setSize(11);
$sheet->getStyle('A6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$row = 8;

// ── Totaux généraux ──
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

// ── Par niveau ──
$sheet->setCellValue("A{$row}", 'PAR NIVEAU');
$sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12);
$row++;
$hdr = $row;
foreach (['Niveau', 'Élèves', 'Dû', 'Payé', 'Recouvrement'] as $i => $hh) $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . $row, $hh);
$sheet->getStyle("A{$hdr}:E{$hdr}")->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFFFF'));
$sheet->getStyle("A{$hdr}:E{$hdr}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1A3C6B');
$row++;
foreach ($stats_niveau as $s) {
    $t = $s['du'] > 0 ? round($s['paye'] / $s['du'] * 100) : 0;
    $sheet->setCellValue("A{$row}", 'Niveau ' . $s['niveau']);
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

// ── Par type de frais ──
$sheet->setCellValue("A{$row}", 'PAR TYPE DE FRAIS');
$sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12);
$row++;
$hdr = $row;
foreach (['Frais', 'Dû', 'Payé (ventilé)'] as $i => $hh) $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . $row, $hh);
$sheet->getStyle("A{$hdr}:C{$hdr}")->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFFFF'));
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

// ── Par mode de paiement ──
$sheet->setCellValue("A{$row}", 'PAR MODE DE PAIEMENT');
$sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12);
$row++;
$hdr = $row;
foreach (['Mode', 'Versements', 'Total encaissé', 'Part'] as $i => $hh) $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . $row, $hh);
$sheet->getStyle("A{$hdr}:D{$hdr}")->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFFFF'));
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

// ── Évolution mensuelle ──
$sheet->setCellValue("A{$row}", 'ÉVOLUTION MENSUELLE DES ENCAISSEMENTS');
$sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12);
$row++;
$hdr = $row;
foreach (['Mois', 'Total encaissé'] as $i => $hh) $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . $row, $hh);
$sheet->getStyle("A{$hdr}:B{$hdr}")->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFFFF'));
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
header('Content-Disposition: attachment;filename="bilan_financier_' . preg_replace('/[^A-Za-z0-9]/', '_', $val_annee) . '.xlsx"');
header('Cache-Control: max-age=0');
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
