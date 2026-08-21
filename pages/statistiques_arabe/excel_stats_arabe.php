<?php
/**
 * Export Excel (.xlsx) des statistiques — piste arabe. Miroir de
 * pages/statistiques/excel_stats.php (même en-tête logo+filigrane, mêmes
 * couleurs exactes qu'ABZ_MBE : groupe B7CDF3, sous-total DEC58A) — seules
 * les DONNÉES viennent de notes_apc_arabe.php (matière+coefficient, pas
 * compétences). GET : onglet (niveau|matiere|eleves), vue (trim|annee), trim, classe
 */
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/../../notes_apc_arabe.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../conseil_classe/excel_releve_commun.php'; // generer_filigrane_excel(), fmt2()
exiger_connexion();

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

$annee_act = get_annee_active();
$val_annee = $annee_act['val_annee'] ?? '';
$etab_brut = get_etablissement();
$etab      = etab_pour_pdf($etab_brut);

$onglet    = in_array($_GET['onglet'] ?? '', ['niveau', 'matiere', 'eleves'], true) ? $_GET['onglet'] : 'niveau';
$vue       = in_array($_GET['vue'] ?? '', ['trim', 'annee'], true) ? $_GET['vue'] : 'trim';
$id_trim   = (int) ($_GET['trim'] ?? 0);
$id_classe = (int) ($_GET['classe'] ?? 0);
if ($vue === 'trim' && !$id_trim) die('Aucun trimestre sélectionné.');

$classes = db_all(
    "SELECT c.IDClasses, c.DesignationClasses, c.Niveau, n.OrdreNiveau
     FROM classe c
     LEFT JOIN niveau n ON n.LibelleNiveau = c.Niveau
     JOIN inscrire i ON i.IDClasses = c.IDClasses AND i.val_annee = ?
     GROUP BY c.IDClasses, c.DesignationClasses, c.Niveau, n.OrdreNiveau
     ORDER BY n.OrdreNiveau, c.DesignationClasses",
    [$val_annee]
);
if ($id_classe) $classes = array_values(array_filter($classes, fn($c) => (int) $c['IDClasses'] === $id_classe));

$titres = ['niveau' => 'Bilan par niveau (arabe)', 'matiere' => 'Bilan par matière (école, arabe)', 'eleves' => 'Résultats par classe (arabe)'];
$bilan_cols  = ['classes', 'moy_lt10', 'moy_ge10', 'felicit', 'encourag', 'tab', 'avert_trav', 'blame_trav'];
$labels_cols = ['classes' => 'Classés', 'moy_lt10' => 'Moy<10', 'moy_ge10' => 'Moy>=10', 'felicit' => 'Félicit.', 'encourag' => 'Encour.', 'tab' => 'T.H', 'avert_trav' => 'Avert.T', 'blame_trav' => 'Blâme T.'];

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Statistiques arabe');

// ── En-tête (logo + filigrane, même mise en forme que le relevé de notes) ──
$logo_src_path  = !empty($etab['logo']) ? __DIR__ . '/../../assets/uploads/' . $etab['logo'] : '';
$filigrane_path = __DIR__ . '/../../assets/uploads/filigrane_excel.png';
if ($logo_src_path && generer_filigrane_excel($logo_src_path, $filigrane_path)) {
    $sheet->setBackgroundImage(file_get_contents($filigrane_path));
}

$lettre = fn(int $c) => Coordinate::stringFromColumnIndex($c);
$col_total = max(10, count($bilan_cols) * 3 + 1);

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
$sheet->setCellValue('A5', 'STATISTIQUES SCOLAIRES — PISTE ARABE');
$sheet->getStyle('A5')->getFont()->setBold(true)->setSize(16);
$sheet->getStyle('A5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->mergeCells("A6:{$lettre($col_total)}6");
$sheet->setCellValue('A6', strtoupper($titres[$onglet]) . ' — ' . $val_annee);
$sheet->getStyle('A6')->getFont()->setBold(true)->setSize(11);
$sheet->getStyle('A6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$row = 8;

// ── Table "bilan" M/F/T (Niveau) — mêmes couleurs exactes qu'ABZ_MBE ──────
function excel_stats_arabe_tableau_bilan(&$sheet, int &$row, string $titre, array $lignes, array $bilan_cols, array $labels_cols): void {
    $lettre = fn(int $c) => Coordinate::stringFromColumnIndex($c);
    $col_total = count($bilan_cols) * 3 + 1;
    $BLEU_GROUPE = 'B7CDF3';
    $OR_SOUSTOTAL = 'DEC58A';

    $sheet->mergeCells("A{$row}:{$lettre($col_total)}{$row}");
    $sheet->setCellValue("A{$row}", strtoupper($titre));
    $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12);
    $sheet->getStyle("A{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1A3C6B');
    $sheet->getStyle("A{$row}")->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFFFF'));
    $row++;

    $hdr1 = $row; $hdr2 = $row + 1;
    $sheet->mergeCells("A{$hdr1}:A{$hdr2}");
    $sheet->setCellValue("A{$hdr1}", 'Classe');
    $c = 2;
    foreach ($bilan_cols as $k) {
        $sheet->mergeCells("{$lettre($c)}{$hdr1}:{$lettre($c + 2)}{$hdr1}");
        $sheet->setCellValue("{$lettre($c)}{$hdr1}", $labels_cols[$k]);
        $sheet->setCellValue("{$lettre($c)}{$hdr2}", 'M');
        $sheet->setCellValue("{$lettre($c + 1)}{$hdr2}", 'F');
        $sheet->setCellValue("{$lettre($c + 2)}{$hdr2}", 'T');
        $c += 3;
    }
    $sheet->getStyle("A{$hdr1}:{$lettre($col_total)}{$hdr1}")->getFont()->setBold(true)->setSize(11);
    $sheet->getStyle("A{$hdr1}:{$lettre($col_total)}{$hdr1}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($BLEU_GROUPE);
    $sheet->getStyle("A{$hdr2}:{$lettre($col_total)}{$hdr2}")->getFont()->setBold(true)->setSize(11);
    $sheet->getStyle("A{$hdr2}:{$lettre($col_total)}{$hdr2}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($OR_SOUSTOTAL);
    $sheet->getStyle("A{$hdr1}:{$lettre($col_total)}{$hdr2}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
    $row = $hdr2 + 1;

    $zero = ['M' => 0, 'F' => 0, 'T' => 0];
    $sous_total = array_fill_keys($bilan_cols, $zero);
    foreach ($lignes as $l) {
        $sheet->setCellValue("A{$row}", $l['classe']);
        $c = 2;
        foreach ($bilan_cols as $k) {
            $b = $l['bilan'][$k];
            $sheet->setCellValue("{$lettre($c)}{$row}", $b['M']);
            $sheet->setCellValue("{$lettre($c + 1)}{$row}", $b['F']);
            $sheet->setCellValue("{$lettre($c + 2)}{$row}", $b['T']);
            foreach (['M', 'F', 'T'] as $g) $sous_total[$k][$g] += $b[$g];
            $c += 3;
        }
        $row++;
    }
    $sheet->setCellValue("A{$row}", 'SOUS-TOTAL');
    $sheet->getStyle("A{$row}:{$lettre($col_total)}{$row}")->getFont()->setBold(true);
    $sheet->getStyle("A{$row}:{$lettre($col_total)}{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($OR_SOUSTOTAL);
    $c = 2;
    foreach ($bilan_cols as $k) {
        $sheet->setCellValue("{$lettre($c)}{$row}", $sous_total[$k]['M']);
        $sheet->setCellValue("{$lettre($c + 1)}{$row}", $sous_total[$k]['F']);
        $sheet->setCellValue("{$lettre($c + 2)}{$row}", $sous_total[$k]['T']);
        $c += 3;
    }
    $sheet->getStyle("A{$hdr1}:{$lettre($col_total)}{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $sheet->getStyle("A{$hdr1}:{$lettre($col_total)}{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $row += 2;
}

if ($onglet === 'niveau') {
    $niveaux_map = array_column(db_all("SELECT LibelleNiveau, OrdreNiveau FROM niveau ORDER BY OrdreNiveau"), 'OrdreNiveau', 'LibelleNiveau');
    $groupes = [];
    foreach ($classes as $c) { $groupes[$c['Niveau'] ?: 'Non défini'][] = $c; }
    uksort($groupes, fn($a, $b) => ($niveaux_map[$a] ?? 999) <=> ($niveaux_map[$b] ?? 999));
    foreach ($groupes as $niveau => $classes_niveau) {
        $lignes = [];
        foreach ($classes_niveau as $c) {
            $lignes[] = ['classe' => $c['DesignationClasses'], 'bilan' => bilan_classe_genre_arabe((int) $c['IDClasses'], $val_annee, $vue, $id_trim)];
        }
        excel_stats_arabe_tableau_bilan($sheet, $row, 'Niveau ' . $niveau, $lignes, $bilan_cols, $labels_cols);
    }
    foreach (range(1, count($bilan_cols) * 3 + 1) as $ci) { $sheet->getColumnDimensionByColumn($ci)->setWidth($ci === 1 ? 22 : 8); }

} elseif ($onglet === 'matiere') {
    $stats = stats_par_matiere_arabe(array_map('intval', array_column($classes, 'IDClasses')), $val_annee, $vue, $id_trim);
    $headers = ['Matière', 'Nb évalués', 'Moyenne', 'Min', 'Max', 'Taux réussite'];
    foreach ($headers as $i => $h) $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . $row, $h);
    $sheet->getStyle("A{$row}:F{$row}")->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFFFF'));
    $sheet->getStyle("A{$row}:F{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1A3C6B');
    $row++;
    $hdr_row = $row - 1;
    foreach ($stats as $s) {
        $sheet->setCellValue("A{$row}", $s['matiere']);
        $sheet->setCellValue("B{$row}", $s['nb']);
        if ($s['moy'] !== null) $sheet->setCellValue("C{$row}", (float) fmt2($s['moy']));
        if ($s['min'] !== null) $sheet->setCellValue("D{$row}", (float) fmt2($s['min']));
        if ($s['max'] !== null) $sheet->setCellValue("E{$row}", (float) fmt2($s['max']));
        $sheet->setCellValue("F{$row}", $s['taux'] . '%');
        $row++;
    }
    $sheet->getStyle("A{$hdr_row}:F" . ($row - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    foreach (['A' => 50, 'B' => 12, 'C' => 12, 'D' => 10, 'E' => 10, 'F' => 14] as $col => $w) $sheet->getColumnDimension($col)->setWidth($w);

} elseif ($onglet === 'eleves') {
    $headers = ['Classe', 'Effectif', 'Filles', 'Garçons', 'Moyenne', 'Premier', 'Dernier', 'Admis', 'Taux réussite', 'Félicit.', 'Encour.', 'T.H', 'Avert.T', 'Blâme T.'];
    foreach ($headers as $i => $h) $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . $row, $h);
    $sheet->getStyle("A{$row}:N{$row}")->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFFFF'));
    $sheet->getStyle("A{$row}:N{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1A3C6B');
    $row++;
    $hdr_row = $row - 1;
    foreach ($classes as $c) {
        $st = stats_classe_arabe((int) $c['IDClasses'], $val_annee, $vue, $id_trim);
        $b  = bilan_classe_genre_arabe((int) $c['IDClasses'], $val_annee, $vue, $id_trim);
        $sheet->setCellValue("A{$row}", $c['DesignationClasses']);
        $sheet->setCellValue("B{$row}", $st['nb']);
        $sheet->setCellValue("C{$row}", $st['filles']);
        $sheet->setCellValue("D{$row}", $st['garcons']);
        if ($st['moy'] !== null)     $sheet->setCellValue("E{$row}", (float) fmt2($st['moy']));
        if ($st['premier'] !== null) $sheet->setCellValue("F{$row}", (float) fmt2($st['premier']));
        if ($st['dernier'] !== null) $sheet->setCellValue("G{$row}", (float) fmt2($st['dernier']));
        $sheet->setCellValue("H{$row}", $st['admis']);
        $sheet->setCellValue("I{$row}", $st['taux'] . '%');
        $sheet->setCellValue("J{$row}", $b['felicit']['T']);
        $sheet->setCellValue("K{$row}", $b['encourag']['T']);
        $sheet->setCellValue("L{$row}", $b['tab']['T']);
        $sheet->setCellValue("M{$row}", $b['avert_trav']['T']);
        $sheet->setCellValue("N{$row}", $b['blame_trav']['T']);
        $row++;
    }
    $sheet->getStyle("A{$hdr_row}:N" . ($row - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $sheet->getColumnDimension('A')->setWidth(24);
    foreach (range('B', 'N') as $col) $sheet->getColumnDimension($col)->setWidth(11);
}

// Signature numérique (sur demande uniquement, jamais automatique).
$chemin_sig = signature_etablissement_chemin();
if (($_GET['signature'] ?? '0') === '1' && $chemin_sig) {
    $row += 2;
    $sig_drawing = new Drawing();
    $sig_drawing->setName('Signature');
    $sig_drawing->setPath($chemin_sig);
    $sig_drawing->setHeight(50);
    $sig_drawing->setCoordinates("B{$row}");
    $sig_drawing->setWorksheet($sheet);
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="stats_arabe_' . $onglet . '_' . date('Ymd') . '.xlsx"');
header('Cache-Control: max-age=0');
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
