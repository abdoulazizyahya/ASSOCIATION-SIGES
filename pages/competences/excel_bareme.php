<?php
// pages/competences/excel_bareme.php — Export Excel du barème par niveau
// (onglet Barème de liste.php), groupé par groupe de compétences assigné —
// même logique que l'écran et l'export PDF (fonctions.php::bareme_par_niveau()).
// GET : niveau=X (un seul niveau, une seule feuille) — OU sans "niveau"
// (tous les niveaux utilisés, une feuille par niveau). Demande explicite du
// 21/08/2026.
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../conseil_classe/excel_releve_commun.php'; // generer_filigrane_excel()
exiger_acces_pedagogie(); // même politique d'accès que l'écran (lecture) — pages/competences/liste.php

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

$f_niveau  = trim((string) ($_GET['niveau'] ?? ''));
$annee     = get_annee_active();
$val_annee = $annee['val_annee'] ?? '';

$niveaux_liste = db_all(
    "SELECT LibelleNiveau, OrdreNiveau FROM niveau WHERE actif = 1 ORDER BY OrdreNiveau"
);
if ($f_niveau) {
    $niveaux_liste = array_values(array_filter($niveaux_liste, fn($n) => $n['LibelleNiveau'] === $f_niveau));
    if (!$niveaux_liste) die('Niveau introuvable.');
}
if (!$niveaux_liste) die('Aucun niveau à exporter.');

$etab_brut = get_etablissement();
$etab      = etab_pour_pdf($etab_brut);

$logo_src_path  = !empty($etab['logo']) ? __DIR__ . '/../../assets/uploads/' . $etab['logo'] : '';
$filigrane_path = __DIR__ . '/../../assets/uploads/filigrane_excel.png';
$a_filigrane     = $logo_src_path && generer_filigrane_excel($logo_src_path, $filigrane_path);

$lettre = fn(int $c) => Coordinate::stringFromColumnIndex($c);

// Construit une feuille "Barème — Niveau X" dans $spreadsheet, sur le même
// modèle d'en-tête que les autres exports Excel du projet (bloc établissement
// + logo, titre centré) — voir pages/depenses/excel_categories.php.
function ecrire_feuille_bareme_excel(
    \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array $etab, string $code_niveau, string $val_annee,
    ?string $logo_src_path, bool $a_filigrane, string $filigrane_path, callable $lettre
): void {
    $donnees      = bareme_par_niveau($code_niveau, $val_annee);
    $noms_classes = implode(', ', array_column($donnees['classes'], 'DesignationClasses'));

    // Titre de feuille limité à 31 caractères par Excel — "Niveau X" tient large.
    $sheet->setTitle(mb_substr('Niveau ' . $code_niveau, 0, 31));

    if ($a_filigrane) {
        $sheet->setBackgroundImage(file_get_contents($filigrane_path));
    }

    $col_total = 7; // A..G (Code, Compétence, Oral, Écrit, Pratique, Savoir-être, Total)

    $texte_etab = mb_strtoupper($etab['region_fr'] ?? "REGION DE L'ADAMAOUA") . "\n" .
        mb_strtoupper($etab['departement_fr'] ?? 'DEPARTEMENT DE LA VINA') . "\n" .
        mb_strtoupper($etab['arrondissement_fr'] ?? 'ARRONDISSEMENT DE NGAOUNDERE I') . "\n" .
        '***********';
    $nom_etab = mb_strtoupper($etab['nom_fr'] ?? 'GSBI LES POUSSINS DE JAYNITAARE');
    $sheet->mergeCells('A1:B4');
    $rt = new RichText();
    $r1 = $rt->createTextRun($texte_etab . "\n");
    $r1->getFont()->setSize(8);
    $r2 = $rt->createTextRun($nom_etab);
    $r2->getFont()->setBold(true)->setSize(10);
    $cell = $sheet->getCell('A1');
    $cell->setValue($rt);
    $cell->getStyle()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
    $sheet->mergeCells('C1:D4');
    if ($logo_src_path && is_file($logo_src_path)) {
        $drawing = new Drawing();
        $drawing->setName('Logo');
        $drawing->setPath($logo_src_path);
        $drawing->setHeight(65);
        $drawing->setCoordinates('C1');
        $drawing->setOffsetX(4);
        $drawing->setOffsetY(4);
        $drawing->setWorksheet($sheet);
    }
    $sheet->mergeCells("E1:{$lettre($col_total)}4");

    $sheet->mergeCells("A5:{$lettre($col_total)}5");
    $sheet->setCellValue('A5', 'BARÈME DE NOTATION — NIVEAU ' . mb_strtoupper($code_niveau));
    $sheet->getStyle('A5')->getFont()->setBold(true)->setSize(14);
    $sheet->getStyle('A5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $sheet->mergeCells("A6:{$lettre($col_total)}6");
    $sheet->setCellValue('A6', ($noms_classes ? 'Classes : ' . $noms_classes . '  —  ' : '') . 'Année scolaire : ' . $val_annee);
    $sheet->getStyle('A6')->getFont()->setItalic(true)->setSize(9);
    $sheet->getStyle('A6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $row = 8;
    if (!$donnees['assigne']) {
        $sheet->setCellValue("A{$row}", "Aucun groupe de compétences n'est assigné à ce niveau — barème non configuré.");
        $sheet->getStyle("A{$row}")->getFont()->setItalic(true)->getColor()->setRGB('92400E');
        $sheet->getColumnDimension('A')->setWidth(70);
        return;
    }

    $headers = ['Code', 'Compétence', 'Oral', 'Écrit', 'Pratique', 'Savoir-être', 'Total'];
    if (!$donnees['groupes']) {
        $sheet->setCellValue("A{$row}", 'Aucune compétence en français définie.');
        $sheet->getStyle("A{$row}")->getFont()->setItalic(true);
        return;
    }

    foreach ($donnees['groupes'] as $grp) {
        $sheet->mergeCells("A{$row}:{$lettre($col_total)}{$row}");
        $libelle_grp = $grp['libelle'] . (!$grp['visible'] ? ' — masqué (toutes compétences désactivées)' : '');
        $sheet->setCellValue("A{$row}", xl_safe($libelle_grp));
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $sheet->getStyle("A{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D6EAF8');
        $row++;

        foreach ($headers as $i => $h) $sheet->setCellValue($lettre($i + 1) . $row, $h);
        $sheet->getStyle("A{$row}:{$lettre($col_total)}{$row}")->getFont()->setBold(true)->setColor(new Color('FFFFFFFF'));
        $sheet->getStyle("A{$row}:{$lettre($col_total)}{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1E4FD8');
        $hdr_row = $row;
        $row++;

        foreach ($grp['lignes'] as $ligne) {
            $active = $ligne['actif'] === null || (int) $ligne['actif'] === 1;
            $sheet->setCellValue("A{$row}", xl_safe($ligne['code_comp']));
            $sheet->setCellValue("B{$row}", xl_safe($ligne['nom_comp_affiche'] ?? $ligne['nom_comp']));
            $sheet->setCellValue("C{$row}", $ligne['orale'] !== null ? (float) $ligne['orale'] : 0);
            $sheet->setCellValue("D{$row}", $ligne['ecrite'] !== null ? (float) $ligne['ecrite'] : 0);
            $sheet->setCellValue("E{$row}", $ligne['pratique'] !== null ? (float) $ligne['pratique'] : 0);
            $sheet->setCellValue("F{$row}", $ligne['savoir_etre'] !== null ? (float) $ligne['savoir_etre'] : 0);
            $sheet->setCellValue("G{$row}", $ligne['total_points'] !== null ? (float) $ligne['total_points'] : 0);
            $sheet->getStyle("G{$row}")->getFont()->setBold(true);
            if (!$active) {
                $sheet->getStyle("A{$row}:{$lettre($col_total)}{$row}")->getFont()->getColor()->setRGB('9CA3AF');
            }
            $row++;
        }
        $sheet->getStyle("A{$hdr_row}:{$lettre($col_total)}" . ($row - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("C" . ($hdr_row + 1) . ":G" . ($row - 1))->getNumberFormat()->setFormatCode('0.0');
        $row++; // ligne vide entre groupes
    }

    $sheet->getColumnDimension('A')->setWidth(10);
    $sheet->getColumnDimension('B')->setWidth(52);
    foreach (['C', 'D', 'E', 'F', 'G'] as $col) $sheet->getColumnDimension($col)->setWidth(12);
    $sheet->freezePane('A' . 8);
}

$spreadsheet = new Spreadsheet();
foreach ($niveaux_liste as $i => $n) {
    $sheet = $i === 0 ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
    ecrire_feuille_bareme_excel($sheet, $etab, $n['LibelleNiveau'], $val_annee, $logo_src_path ?: null, $a_filigrane, $filigrane_path, $lettre);
}
$spreadsheet->setActiveSheetIndex(0);

$nom_fichier = $f_niveau
    ? 'bareme_niveau_' . preg_replace('/[^A-Za-z0-9]/', '_', $f_niveau) . '.xlsx'
    : 'bareme_tous_niveaux_' . date('Ymd') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $nom_fichier . '"');
header('Cache-Control: max-age=0');
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
