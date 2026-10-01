<?php
/**
 * Rendu Excel partagé par excel_resultat_classe.php, excel_resultat_meilleurs.php
 * et excel_resultat_provisoire.php — même principe que
 * secondaire/pages/conseil_classe/excel_releve_commun.php : logo + filigrane en
 * en-tête, tableau à colonnes configurables (cocher/ordre/alignement
 * choisis dans secondaire/pages/statistiques/resultat_annuel.php), une ligne par
 * élève via un formateur de valeur passé en paramètre (jamais une 4e
 * implémentation des mêmes règles métier — voir fonctions.php::
 * valeur_colonne_resultat()/valeur_colonne_provisoire()).
 */
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * @param array    $sel      [$cle => [$libelle, $alignement 'L'|'C'|'R']], dans l'ordre choisi.
 * @param array    $rows     Lignes de données (calc_resultat_annuel_comp() ou calc_liste_provisoire_comp()).
 * @param callable $value_fn function(string $col, array $row, int $no): string
 * @param ?string  $note_pied Texte d'avertissement affiché sous le tableau (onglet "Liste provisoire").
 */
function genererExcelResultat(array $etab, string $titre_principal, string $sous_titre, string $meta, array $sel, array $rows, callable $value_fn, string $nom_fichier, ?string $note_pied = null): void {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Résultat');

    $logo_src_path  = !empty($etab['logo']) ? __DIR__ . '/../../../assets/uploads/' . $etab['logo'] : '';
    $filigrane_path = __DIR__ . '/../../../assets/uploads/filigrane_excel_' . md5((string) ($etab['logo'] ?? '')) . '.png'; // un fichier PAR logo : un nom fixe partagé mélangeait les écoles
    if ($logo_src_path && generer_filigrane_excel($logo_src_path, $filigrane_path)) {
        $sheet->setBackgroundImage(file_get_contents($filigrane_path));
    }

    $nb_cols   = max(count($sel), 1);
    $col_total = max($nb_cols, 8);
    $lettre    = fn(int $c) => Coordinate::stringFromColumnIndex($c);

    // ── En-tête établissement (logo + nom/adresse) ───────────────────────
    $sheet->mergeCells("C1:{$lettre($col_total)}3");
    $rt = new RichText();
    $r1 = $rt->createTextRun(mb_strtoupper($etab['nom_fr'] ?? APP_NOM, 'UTF-8') . "\n");
    $r1->getFont()->setBold(true)->setSize(13);
    $r2 = $rt->createTextRun((string)($etab['adresse'] ?? ''));
    $r2->getFont()->setSize(9);
    $cell = $sheet->getCell('C1');
    $cell->setValue($rt);
    $cell->getStyle()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
    if ($logo_src_path && is_file($logo_src_path)) {
        $drawing = new Drawing();
        $drawing->setName('Logo');
        $drawing->setPath($logo_src_path);
        $drawing->setHeight(60);
        $drawing->setCoordinates('A1');
        $drawing->setOffsetX(4);
        $drawing->setOffsetY(4);
        $drawing->setWorksheet($sheet);
    }

    $row = 5;
    $sheet->mergeCells("A{$row}:{$lettre($col_total)}{$row}");
    $sheet->setCellValue("A{$row}", mb_strtoupper($titre_principal, 'UTF-8'));
    $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(14);
    $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $row++;

    $sheet->mergeCells("A{$row}:{$lettre($col_total)}{$row}");
    $sheet->setCellValue("A{$row}", $sous_titre);
    $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(11);
    $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $row++;

    $sheet->mergeCells("A{$row}:{$lettre($col_total)}{$row}");
    $sheet->setCellValue("A{$row}", $meta);
    $sheet->getStyle("A{$row}")->getFont()->setItalic(true)->setSize(9);
    $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $row += 2;

    // ── En-tête du tableau ────────────────────────────────────────────
    $header_row = $row;
    $c = 1;
    foreach ($sel as [$lbl, ]) {
        $sheet->setCellValue("{$lettre($c)}{$header_row}", $lbl);
        $c++;
    }
    $sheet->getStyle("A{$header_row}:{$lettre($nb_cols)}{$header_row}")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $sheet->getStyle("A{$header_row}:{$lettre($nb_cols)}{$header_row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1E4FD8');
    $sheet->getStyle("A{$header_row}:{$lettre($nb_cols)}{$header_row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
    $row++;

    // ── Lignes ────────────────────────────────────────────────────────
    $align_map = ['L' => Alignment::HORIZONTAL_LEFT, 'C' => Alignment::HORIZONTAL_CENTER, 'R' => Alignment::HORIZONTAL_RIGHT];
    $no = 1;
    foreach ($rows as $r) {
        $c = 1;
        foreach ($sel as $k => [$lbl, $al]) {
            $cellRef = "{$lettre($c)}{$row}";
            $sheet->setCellValue($cellRef, xl_safe($value_fn($k, $r, $no)));
            $sheet->getStyle($cellRef)->getAlignment()->setHorizontal($align_map[$al] ?? Alignment::HORIZONTAL_LEFT);
            $c++;
        }
        $row++; $no++;
    }
    $last_row = $row - 1;

    if ($last_row >= $header_row) {
        $sheet->getStyle("A{$header_row}:{$lettre($nb_cols)}{$last_row}")
            ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }

    if ($note_pied !== null) {
        $row++;
        $sheet->mergeCells("A{$row}:{$lettre($col_total)}{$row}");
        $sheet->setCellValue("A{$row}", $note_pied);
        $sheet->getStyle("A{$row}")->getFont()->setItalic(true)->setSize(8.5);
        $sheet->getStyle("A{$row}")->getAlignment()->setWrapText(true);
    }

    foreach (range(1, $nb_cols) as $c) {
        $sheet->getColumnDimension($lettre($c))->setAutoSize(true);
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $nom_fichier . '_' . date('Ymd') . '.xlsx"');
    header('Cache-Control: max-age=0');
    (new Xlsx($spreadsheet))->save('php://output');
    exit;
}
