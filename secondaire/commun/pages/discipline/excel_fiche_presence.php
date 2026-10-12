<?php
/**
 * FICHE DE PRESENCE — export Excel (.xlsx), même grille et même mise en
 * forme que pdf_fiche_presence.php (en-tête bilingue, logo, filigrane,
 * SEMAINE DU../AU.. en blanc, tableau LUNDI..VENDREDI x T1-T9/T5, cases
 * vides à remplir à la main ou à l'écran). "Toutes les classes" génère un
 * classeur avec UN ONGLET PAR CLASSE (voir excel_releve_vierge.php pour le
 * même principe).
 * GET : classe (0 = toutes les classes accessibles)
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

$role      = role_connecte();
$annee_act = get_annee_active();
$id_annee  = (int)($annee_act['id'] ?? 0);
$val_annee = $annee_act['libelle'] ?? '';
$etab      = get_etablissement();

$full_access = (in_array($role, ['ADMIN', 'CENSEUR', 'PROVISEUR', 'FONDATEUR'], true) || $role === 'MEMBRE_ASSOCIATION');
$classes_access = [];
if ($full_access) {
    $classes_access = db_all("SELECT * FROM classe WHERE archivee=0 ORDER BY ordre, designation");
} elseif ($role === 'SG') {
    $mat_ens = get_matricule_ens_connecte();
    $classes_access = db_all(
        "SELECT c.* FROM sg JOIN classe c ON c.id=sg.IDClasses
         WHERE sg.matricule_ens=? AND sg.val_annee=? AND c.archivee=0",
        [$mat_ens, $val_annee]
    );
} elseif ($role === 'ENSEIGNANT') {
    $mat_ens = get_matricule_ens_connecte();
    $classes_access = db_all(
        "SELECT c.* FROM enseignat_principal ep JOIN classe c ON c.id=ep.IDClasses
         WHERE ep.matricule_ens=? AND ep.val_annee=? AND c.archivee=0",
        [$mat_ens, $val_annee]
    );
}
$ids_ok = array_column($classes_access, 'id');
if (empty($ids_ok)) die('Acces non autorise.');

$id_classe_demande = (int)($_GET['classe'] ?? 0);
if ($id_classe_demande > 0) {
    $classes_a_generer = array_values(array_filter($classes_access, fn($c) => (int)$c['id'] === $id_classe_demande));
    if (empty($classes_a_generer)) die('Acces refuse a cette classe.');
} else {
    $classes_a_generer = $classes_access; // toutes les classes accessibles
}

$jours = ['LUNDI' => 9, 'MARDI' => 9, 'MERCREDI' => 5, 'JEUDI' => 9, 'VENDREDI' => 9];

require_once __DIR__ . '/../conseil_classe/excel_releve_commun.php'; // generer_filigrane_excel()

function genererFeuilleFichePresence(Spreadsheet $spreadsheet, int $idx, array $etab, array $classe, array $eleves, string $val_annee, ?string $filigrane_path, array $jours): void {
    $sheet = $idx === 0 ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
    $titre_feuille = preg_replace('/[\\\\\/\?\*\[\]\:]/', ' ', $classe['designation'] ?? ('Classe ' . ($idx + 1)));
    $sheet->setTitle(mb_substr($titre_feuille, 0, 31));

    $col_no  = 1; $col_nom = 2; $col_sub0 = 3;
    $nb_sub  = array_sum($jours);
    $col_total = $col_sub0 + $nb_sub;
    $lettre  = fn(int $c) => Coordinate::stringFromColumnIndex($c);

    if ($filigrane_path) {
        excel_filigrane($sheet, $filigrane_path);
    }

    // ── En-tête bilingue (identique aux relevés, réparti sur toute la largeur) ─
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
    $r1 = $rt->createTextRun($texte_fr . "\n");
    $r1->getFont()->setSize(7);
    $r2 = $rt->createTextRun(strtoupper($etab['nom_fr'] ?? 'LYCEE TECHNIQUE DE MBE'));
    $r2->getFont()->setBold(true)->setSize(10);
    $sheet->getCell("{$lettre($col_g_debut)}1")->setValue($rt);
    $sheet->getStyle("{$lettre($col_g_debut)}1")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);

    $sheet->mergeCells("{$lettre($col_d_debut)}1:{$lettre($col_d_fin)}3");
    $rt2 = new RichText();
    $r3 = $rt2->createTextRun($texte_en . "\n");
    $r3->getFont()->setSize(7);
    $r4 = $rt2->createTextRun(strtoupper($etab['nom_en'] ?? 'GTHS OF MBE'));
    $r4->getFont()->setBold(true)->setSize(10);
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

    // ── Titre ─────────────────────────────────────────────────────────────
    $sheet->mergeCells("A4:{$lettre($col_total)}4");
    $sheet->setCellValue('A4', 'FICHE DE PRESENCE');
    $sheet->getStyle('A4')->getFont()->setBold(true)->setSize(15);
    $sheet->getStyle('A4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $sheet->mergeCells("A5:{$lettre($col_total)}5");
    $sheet->setCellValue('A5', 'ATTENDANCE RECORD');
    $sheet->getStyle('A5')->getFont()->setItalic(true)->setSize(9);
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
    $sheet->setCellValue('A7', 'SEMAINE DU ' . str_repeat('.', 30) . ' AU ' . str_repeat('.', 30));
    $sheet->getStyle('A7')->getFont()->setBold(true)->setSize(10);
    $sheet->getStyle('A7')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    // ── En-tête du tableau (2 lignes) ────────────────────────────────────────
    $row_hdr1 = 9; $row_hdr2 = 10; $row_data0 = 11;
    $sheet->mergeCells("{$lettre($col_no)}{$row_hdr1}:{$lettre($col_no)}{$row_hdr2}");
    $sheet->setCellValue("{$lettre($col_no)}{$row_hdr1}", 'N°');
    $sheet->mergeCells("{$lettre($col_nom)}{$row_hdr1}:{$lettre($col_nom)}{$row_hdr2}");
    $sheet->setCellValue("{$lettre($col_nom)}{$row_hdr1}", 'NOMS ET PRENOMS');
    $sheet->mergeCells("{$lettre($col_total)}{$row_hdr1}:{$lettre($col_total)}{$row_hdr2}");
    $sheet->setCellValue("{$lettre($col_total)}{$row_hdr1}", 'TOTAL');

    $c = $col_sub0;
    foreach ($jours as $jour => $nb) {
        $c1 = $c; $c2 = $c + $nb - 1;
        $sheet->mergeCells("{$lettre($c1)}{$row_hdr1}:{$lettre($c2)}{$row_hdr1}");
        $sheet->setCellValue("{$lettre($c1)}{$row_hdr1}", $jour);
        for ($t = 1; $t <= $nb; $t++) {
            $sheet->setCellValue("{$lettre($c1 + $t - 1)}{$row_hdr2}", 'T' . $t);
            $sheet->getColumnDimension($lettre($c1 + $t - 1))->setWidth(3);
        }
        $c += $nb;
    }
    $sheet->getStyle("{$lettre($col_no)}{$row_hdr1}:{$lettre($col_total)}{$row_hdr2}")
        ->getFont()->setBold(true)->setColor(new Color('FF0F2D5A'));
    $sheet->getStyle("{$lettre($col_no)}{$row_hdr1}:{$lettre($col_total)}{$row_hdr2}")
        ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('B3E0FF');
    $sheet->getStyle("{$lettre($col_no)}{$row_hdr1}:{$lettre($col_total)}{$row_hdr2}")
        ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);

    $sheet->getRowDimension($row_hdr1)->setRowHeight(17);
    $sheet->getRowDimension($row_hdr2)->setRowHeight(17);
    $sheet->getColumnDimension($lettre($col_no))->setWidth(4);
    $sheet->getColumnDimension($lettre($col_nom))->setWidth(25);
    $sheet->getColumnDimension($lettre($col_total))->setWidth(7);

    // ── Lignes élèves (cases vides à remplir) ────────────────────────────────
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

    $sheet->getStyle("{$lettre($col_no)}{$row_hdr1}:{$lettre($col_total)}{$last_row}")
        ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $sheet->getStyle("{$lettre($col_no)}{$row_data0}:{$lettre($col_total)}{$last_row}")
        ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
    for ($r = $row_data0; $r <= $last_row; $r++) $sheet->getRowDimension($r)->setRowHeight(17);

    // ── Pied de page (copyright, comme le PDF) ───────────────────────────────
    $row_pied = $last_row + 2;
    $sheet->mergeCells("{$lettre($col_no)}{$row_pied}:{$lettre($col_total)}{$row_pied}");
    $sheet->setCellValue("{$lettre($col_no)}{$row_pied}", 'Copyright © SIGES ABZ - ' . $val_annee);
    $sheet->getStyle("{$lettre($col_no)}{$row_pied}")->getFont()->setSize(7)->getColor()->setRGB('5A5A5A');

    $sheet->getPageSetup()->setFitToWidth(1)->setFitToHeight(0);
    $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
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
    genererFeuilleFichePresence($spreadsheet, $idx, $etab, $classe, $eleves, $val_annee, $filigrane_path, $jours);
}
$spreadsheet->setActiveSheetIndex(0);

$suffix = $id_classe_demande ? preg_replace('/\W+/', '_', $classes_a_generer[0]['designation'] ?? 'classe') : 'toutes_classes';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="fiche_presence_' . $suffix . '.xlsx"');
header('Cache-Control: max-age=0');
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
