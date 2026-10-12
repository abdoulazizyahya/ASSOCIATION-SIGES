<?php
/**
 * RELEVE DE NOTES — feuille VIERGE, export Excel (.xlsx). Même contenu que
 * pdf_releve_vierge.php : champs Matière/Enseignant vides, colonnes EVAL 1
 * à EVAL 6 vides, liste des élèves de la classe. "Toutes les classes"
 * génère un classeur avec UN ONGLET PAR CLASSE.
 * GET : classe (0 = toutes les classes)
 */
require_once __DIR__ . '/../../../../config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
require_once __DIR__ . '/../../../../vendor/autoload.php';
exiger_connexion();

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

$role     = role_connecte();
$is_admin = (in_array($role, ['ADMIN', 'PROVISEUR', 'FONDATEUR', 'CENSEUR'], true) || $role === 'MEMBRE_ASSOCIATION');
$is_ens   = ($role === 'ENSEIGNANT');
$mat_ens  = $is_ens ? get_matricule_ens_connecte() : null;
if (!$is_admin && !$is_ens) die('Acces non autorise.');

$annee_act = get_annee_active();
$id_annee  = (int)($annee_act['id'] ?? 0);
$val_annee = $annee_act['libelle'] ?? '';
$etab      = get_etablissement();

$id_classe_demande = (int)($_GET['classe'] ?? 0);

if ($is_ens && $mat_ens) {
    $classes = db_all(
        "SELECT DISTINCT c.* FROM classe c
         JOIN dispenser d ON d.IDClasses=c.id AND d.matricule_ens=? AND d.val_annee=?
         WHERE c.archivee=0 ORDER BY c.ordre, c.designation",
        [$mat_ens, $val_annee]
    );
} else {
    $classes = $id_annee
        ? db_all("SELECT c.* FROM classe c
                  JOIN inscription i ON i.id_classe=c.id AND i.id_annee=?
                  WHERE c.archivee=0 GROUP BY c.id ORDER BY c.ordre, c.designation", [$id_annee])
        : [];
}
if (empty($classes)) die('Aucune classe accessible.');

if ($id_classe_demande > 0) {
    $classes_a_generer = array_values(array_filter($classes, fn($c) => (int)$c['id'] === $id_classe_demande));
    if (empty($classes_a_generer)) die('Acces refuse a cette classe.');
} else {
    $classes_a_generer = $classes;
}

require_once __DIR__ . '/../../../commun/pages/conseil_classe/excel_releve_commun.php'; // generer_filigrane_excel()

function genererFeuilleReleveVierge(Spreadsheet $spreadsheet, int $idx, array $etab, array $classe, array $eleves, string $val_annee, ?string $filigrane_path): void {
    if ($idx === 0) {
        $sheet = $spreadsheet->getActiveSheet();
    } else {
        $sheet = $spreadsheet->createSheet();
    }
    $titre_feuille = preg_replace('/[\\\\\/\?\*\[\]\:]/', ' ', $classe['designation'] ?? ('Classe ' . ($idx + 1)));
    $sheet->setTitle(mb_substr($titre_feuille, 0, 31));

    $lettre = fn(int $c) => Coordinate::stringFromColumnIndex($c);
    $col_no = 1; $col_nom = 2; $col_eval0 = 3; $nb_eval = 6;
    $col_total = $col_eval0 + $nb_eval - 1;

    if ($filigrane_path && is_file($filigrane_path)) {
        excel_filigrane($sheet, $filigrane_path);
    }

    $tiers = max(1, (int) floor($col_total / 3));
    $col_g_debut = 1; $col_g_fin = $tiers;
    $col_c_debut = $tiers + 1; $col_c_fin = 2 * $tiers;
    $col_d_debut = 2 * $tiers + 1; $col_d_fin = $col_total;

    $texte_fr = "REPUBLIQUE DU CAMEROUN\n" .
        ($etab['region_fr']         ?? "REGION DE L'ADAMAOUA") . "\n" .
        ($etab['departement_fr']    ?? 'DEPARTEMENT DE LA VINA') . "\n" .
        ($etab['arrondissement_fr'] ?? 'ARRONDISSEMENT DE MBE');
    $texte_en = "REPUBLIC OF CAMEROON\n" .
        ($etab['region_en']      ?? 'ADAMAWA REGION') . "\n" .
        ($etab['division_en']    ?? 'VINA DIVISION') . "\n" .
        ($etab['subdivision_en'] ?? 'MBE SUBDIVISION');

    $sheet->mergeCells("{$lettre($col_g_debut)}1:{$lettre($col_g_fin)}3");
    $rt = new RichText();
    $r1 = $rt->createTextRun($texte_fr . "\n"); $r1->getFont()->setSize(7);
    $r2 = $rt->createTextRun(strtoupper($etab['nom_fr'] ?? 'LYCEE TECHNIQUE DE MBE')); $r2->getFont()->setBold(true)->setSize(10);
    $sheet->getCell("{$lettre($col_g_debut)}1")->setValue($rt);
    $sheet->getStyle("{$lettre($col_g_debut)}1")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);

    $sheet->mergeCells("{$lettre($col_d_debut)}1:{$lettre($col_d_fin)}3");
    $rt2 = new RichText();
    $r3 = $rt2->createTextRun($texte_en . "\n"); $r3->getFont()->setSize(7);
    $r4 = $rt2->createTextRun(strtoupper($etab['nom_en'] ?? 'GTHS OF MBE')); $r4->getFont()->setBold(true)->setSize(10);
    $sheet->getCell("{$lettre($col_d_debut)}1")->setValue($rt2);
    $sheet->getStyle("{$lettre($col_d_debut)}1")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);

    $logo_path = !empty($etab['logo']) ? __DIR__ . '/../../../../assets/uploads/' . $etab['logo'] : '';
    if ($logo_path && is_file($logo_path)) {
        $drawing = new Drawing();
        $drawing->setName('Logo');
        $drawing->setPath($logo_path);
        $drawing->setHeight(55);
        $drawing->setCoordinates("{$lettre($col_c_debut)}1");
        $drawing->setOffsetX(4);
        $drawing->setOffsetY(4);
        $drawing->setWorksheet($sheet);
    }
    $sheet->mergeCells("{$lettre($col_c_debut)}1:{$lettre($col_c_fin)}3");

    $sheet->mergeCells("A4:{$lettre($col_total)}4");
    $sheet->setCellValue('A4', 'RELEVE DE NOTES');
    $sheet->getStyle('A4')->getFont()->setBold(true)->setSize(13);
    $sheet->getStyle('A4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('A4')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setRGB('2979FF');

    $sheet->mergeCells("A5:{$lettre($col_total)}5");
    $sheet->setCellValue('A5', 'GRADE REPORT');
    $sheet->getStyle('A5')->getFont()->setItalic(true)->setBold(true)->setSize(9)->getColor()->setRGB('1E5ADC');
    $sheet->getStyle('A5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $milieu = (int) floor($col_total / 2);
    $sheet->mergeCells("A6:{$lettre($milieu)}6");
    $sheet->setCellValue('A6', 'Annee scolaire: ' . $val_annee);
    $sheet->getStyle('A6')->getFont()->setBold(true)->setSize(10);
    $sheet->mergeCells("{$lettre($milieu + 1)}6:{$lettre($col_total)}6");
    $sheet->setCellValue("{$lettre($milieu + 1)}6", 'Classe : ' . ($classe['designation'] ?? ''));
    $sheet->getStyle("{$lettre($milieu + 1)}6")->getFont()->setBold(true)->setSize(10);
    $sheet->getStyle("{$lettre($milieu + 1)}6")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

    $sheet->mergeCells("A7:{$lettre($col_total)}7");
    $sheet->setCellValue('A7', 'MATIERE : ');
    $sheet->getStyle('A7')->getFont()->setBold(true)->setSize(9);
    $sheet->getStyle("A7")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_DOTTED);

    $sheet->mergeCells("A8:{$lettre($col_total)}8");
    $sheet->setCellValue('A8', 'ENSEIGNANT : ');
    $sheet->getStyle('A8')->getFont()->setBold(true)->setSize(9);
    $sheet->getStyle("A8")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_DOTTED);

    $row_hdr = 10; $row_data0 = 11;
    $sheet->setCellValue("{$lettre($col_no)}{$row_hdr}", 'N°');
    $sheet->setCellValue("{$lettre($col_nom)}{$row_hdr}", 'NOM ET PRENOMS');
    for ($i = 1; $i <= $nb_eval; $i++) {
        $sheet->setCellValue("{$lettre($col_eval0 + $i - 1)}{$row_hdr}", 'EVAL ' . $i);
    }
    $sheet->getStyle("{$lettre($col_no)}{$row_hdr}:{$lettre($col_total)}{$row_hdr}")
        ->getFont()->setBold(true)->setColor(new Color('FFFFFFFF'));
    $sheet->getStyle("{$lettre($col_no)}{$row_hdr}:{$lettre($col_total)}{$row_hdr}")
        ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('4191E1');
    $sheet->getStyle("{$lettre($col_no)}{$row_hdr}:{$lettre($col_total)}{$row_hdr}")
        ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
    $sheet->getRowDimension($row_hdr)->setRowHeight(17);

    $sheet->getColumnDimension($lettre($col_no))->setWidth(6);
    $sheet->getColumnDimension($lettre($col_nom))->setWidth(39);
    for ($i = 0; $i < $nb_eval; $i++) $sheet->getColumnDimension($lettre($col_eval0 + $i))->setWidth(10);

    $n = 1; $row = $row_data0;
    foreach ($eleves as $el) {
        $sheet->setCellValue("{$lettre($col_no)}{$row}", $n);
        $sheet->setCellValue("{$lettre($col_nom)}{$row}", strtoupper($el['nom']) . ' ' . ($el['prenom'] ?? ''));
        $sheet->getStyle("{$lettre($col_nom)}{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $n++; $row++;
    }
    $last_row = max($row - 1, $row_data0);
    if (empty($eleves)) {
        $sheet->mergeCells("{$lettre($col_no)}{$row_data0}:{$lettre($col_total)}{$row_data0}");
        $sheet->setCellValue("{$lettre($col_no)}{$row_data0}", 'Aucun eleve inscrit.');
        $sheet->getStyle("{$lettre($col_no)}{$row_data0}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    $sheet->getStyle("{$lettre($col_no)}{$row_hdr}:{$lettre($col_total)}{$last_row}")
        ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $sheet->getStyle("{$lettre($col_no)}{$row_data0}:{$lettre($col_total)}{$last_row}")
        ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
    for ($r = $row_data0; $r <= $last_row; $r++) $sheet->getRowDimension($r)->setRowHeight(16);

    $row_pied = $last_row + 2;
    $sheet->mergeCells("{$lettre($col_no)}{$row_pied}:{$lettre($col_total)}{$row_pied}");
    $sheet->setCellValue("{$lettre($col_no)}{$row_pied}", 'Copyright © SIGES ABZ - ' . $val_annee);
    $sheet->getStyle("{$lettre($col_no)}{$row_pied}")->getFont()->setSize(7)->getColor()->setRGB('5A5A5A');

    $sheet->getPageSetup()->setFitToWidth(1)->setFitToHeight(0);
    $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_PORTRAIT);
    $sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4);
}

$logo_src_path  = !empty($etab['logo']) ? __DIR__ . '/../../../../assets/uploads/' . $etab['logo'] : '';
$filigrane_path = __DIR__ . '/../../../../assets/uploads/filigrane_excel.png';
if (!($logo_src_path && generer_filigrane_excel($logo_src_path, $filigrane_path))) $filigrane_path = null;

$spreadsheet = new Spreadsheet();
foreach ($classes_a_generer as $idx => $classe) {
    $eleves = db_all(
        "SELECT e.id, e.nom, e.prenom FROM eleve e
         JOIN inscription i ON i.id_eleve=e.id AND i.id_classe=? AND i.id_annee=?
         WHERE e.statut='actif' ORDER BY e.nom, e.prenom",
        [$classe['id'], $id_annee]
    );
    genererFeuilleReleveVierge($spreadsheet, $idx, $etab, $classe, $eleves, $val_annee, $filigrane_path);
}
$spreadsheet->setActiveSheetIndex(0);

$suffix = $id_classe_demande ? preg_replace('/\W+/', '_', $classes_a_generer[0]['designation'] ?? 'classe') : 'toutes_classes';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="releve_vierge_' . $suffix . '.xlsx"');
header('Cache-Control: max-age=0');
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
