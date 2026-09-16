<?php
/**
 * Génération commune du classeur Excel "RELEVE DE NOTES DES EVALUATIONS"
 * — utilisée par excel_releve.php (séquence/trimestre) et
 * excel_releve_annuel.php. Reproduit la mise en forme de pdf_releve.php /
 * pdf_releve_annuel.php : en-tête (région/département/arrondissement +
 * nom établissement, répété à gauche et à droite du logo — français
 * uniquement, comme le PDF), titre, filigrane en fond de page (logo
 * éclairci, comme le PDF — voir generer_filigrane_excel()), tableau
 * matières (nom vertical + coefficient) / MOY / RANG (avec "e" en
 * exposant, vraie mise en forme superscript Excel) / OBSERVATIONS.
 */

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

if (!function_exists('fmt2')) {
    function fmt2(?float $v): string {
        if ($v === null) return '';
        $s = number_format($v, 2, '.', '');
        [$i, $d] = explode('.', $s);
        return str_pad($i, 2, '0', STR_PAD_LEFT) . '.' . $d;
    }
}

// Filigrane : même logo éclairci que le PDF (assets/uploads/filigrane_logo.png,
// généré/mis en cache par secondaire/pages/statistiques/pdf_stat_classe.php), mais
// replacé sur un grand canevas blanc (~ format A4 paysage) pour qu'une fois
// utilisé comme image de fond de feuille (tuilée par Excel), il apparaisse
// comme UNE seule grande image centrée sur la zone visible/imprimée, au
// lieu d'un pavage visible de petits logos répétés.
function generer_filigrane_excel(string $logo_path, string $dest_path): bool {
    if (!is_file($logo_path)) return false;
    if (is_file($dest_path) && filemtime($dest_path) >= filemtime($logo_path)) return true;
    $src = @imagecreatefromstring(file_get_contents($logo_path));
    if (!$src) return false;
    $sw = imagesx($src); $sh = imagesy($src);

    // Canevas ~ proportions A4 paysage à une résolution d'écran courante.
    $cw = 1600; $ch = 1131;
    $canvas = imagecreatetruecolor($cw, $ch);
    $blanc = imagecolorallocate($canvas, 255, 255, 255);
    imagefill($canvas, 0, 0, $blanc);

    // Logo redimensionné à ~55% de la largeur du canevas, très éclairci
    // (mélange à 90% vers le blanc, identique au filigrane du PDF).
    $target_w = (int) round($cw * 0.55);
    $target_h = (int) round($target_w * $sh / $sw);
    $resized = imagecreatetruecolor($target_w, $target_h);
    imagecopyresampled($resized, $src, 0, 0, 0, 0, $target_w, $target_h, $sw, $sh);

    $blanc_frac = 0.90;
    for ($y = 0; $y < $target_h; $y++) {
        for ($x = 0; $x < $target_w; $x++) {
            $rgb = imagecolorat($resized, $x, $y);
            $r = (int) round((($rgb >> 16) & 0xFF) * (1 - $blanc_frac) + 255 * $blanc_frac);
            $g = (int) round((($rgb >> 8)  & 0xFF) * (1 - $blanc_frac) + 255 * $blanc_frac);
            $b = (int) round(($rgb & 0xFF)          * (1 - $blanc_frac) + 255 * $blanc_frac);
            imagesetpixel($resized, $x, $y, imagecolorallocate($resized, $r, $g, $b));
        }
    }
    $dx = (int) round(($cw - $target_w) / 2);
    $dy = (int) round(($ch - $target_h) / 2);
    imagecopy($canvas, $resized, $dx, $dy, 0, 0, $target_w, $target_h);

    imagepng($canvas, $dest_path);
    imagedestroy($src); imagedestroy($resized); imagedestroy($canvas);
    return true;
}

/**
 * @param array $etab Établissement (get_etablissement())
 * @param array $classe Ligne classe
 * @param string $titre_periode ex. "TRIMESTRE : 1ER TRIMESTRE" / "SEQUENCE : ..." / "ANNUELLE"
 * @param string $val_annee Libellé année scolaire
 * @param array $disciplines [id_mat, coef, matiere, ...]
 * @param array $eleves Lignes élèves (id, matricule, nom, prenom), déjà triées par moyenne décroissante
 * @param array $moys eid => moyenne générale (float|null)
 * @param array $moys_mat eid => [id_mat => moyenne matière (float|null)]
 * @param array $rangs eid => rang (int)
 * @param int $nb_classes_ effectif classé (dénominateur du rang)
 * @param array $decisions_idx eid => ligne decision_conseil (ou absent)
 * @param string $suffix_fichier Nom de fichier sans extension
 */
function genererExcelReleve(
    array $etab, array $classe, string $titre_periode, string $val_annee,
    array $disciplines, array $eleves, array $moys, array $moys_mat,
    array $rangs, int $nb_classes_, array $decisions_idx, string $suffix_fichier
): void {
    $nb_mat = count($disciplines);
    $col_no = 1; $col_mat_id = 2; $col_nom = 3;
    $col_premiere_matiere = 4;
    $col_moy   = $col_premiere_matiere + $nb_mat;
    $col_rang  = $col_moy + 1;
    $col_obs   = $col_rang + 1;
    $col_total = $col_obs;

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Releve');

    // ── Filigrane (fond de page) ────────────────────────────────────────
    $logo_src_path  = !empty($etab['logo']) ? __DIR__ . '/../../../assets/uploads/' . $etab['logo'] : '';
    $filigrane_path = __DIR__ . '/../../../assets/uploads/filigrane_excel.png';
    if ($logo_src_path && generer_filigrane_excel($logo_src_path, $filigrane_path)) {
        // setBackgroundImage tuile l'image sur toute la feuille — l'image a
        // été construite avec beaucoup de marge blanche autour du logo pour
        // qu'un seul exemplaire couvre la zone de données visible/imprimée.
        // Attend les OCTETS de l'image (file_get_contents), pas un chemin —
        // sinon échoue silencieusement (getimagesizefromstring() sur un
        // chemin ne détecte aucune image, le filigrane est alors ignoré).
        $sheet->setBackgroundImage(file_get_contents($filigrane_path));
    }

    // ── En-tête bilingue... non, français uniquement (identique au PDF) —
    // colonnes gauche/centre(logo)/droite réparties sur le nombre total de
    // colonnes du tableau. ──────────────────────────────────────────────
    $tiers = max(1, (int) floor($col_total / 3));
    $col_g_debut = 1; $col_g_fin = $tiers;
    $col_c_debut = $tiers + 1; $col_c_fin = 2 * $tiers;
    $col_d_debut = 2 * $tiers + 1; $col_d_fin = $col_total;

    $lettre = fn(int $c) => Coordinate::stringFromColumnIndex($c);
    $texte_etab = "REGION DE L'ADAMAOUA\n" .
        ($etab['departement_fr'] ?? 'DEPARTEMENT DE LA VINA') . "\n" .
        ($etab['arrondissement_fr'] ?? 'ARRONDISSEMENT DE MBE') . "\n" .
        '***********';
    $nom_etab = strtoupper($etab['nom_fr'] ?? 'LYCEE TECHNIQUE DE MBE');

    foreach ([[$col_g_debut, $col_g_fin], [$col_d_debut, $col_d_fin]] as [$c1, $c2]) {
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

    $logo_path = !empty($etab['logo']) ? __DIR__ . '/../../../assets/uploads/' . $etab['logo'] : '';
    if ($logo_path && is_file($logo_path)) {
        $drawing = new Drawing();
        $drawing->setName('Logo');
        $drawing->setPath($logo_path);
        $drawing->setHeight(70);
        $drawing->setCoordinates($lettre($col_c_debut) . '1');
        $drawing->setOffsetX(4);
        $drawing->setOffsetY(4);
        $drawing->setWorksheet($sheet);
    }
    $sheet->mergeCells("{$lettre($col_c_debut)}1:{$lettre($col_c_fin)}4");

    // ── Titre ────────────────────────────────────────────────────────────
    $sheet->mergeCells("A5:{$lettre($col_total)}5");
    $sheet->setCellValue('A5', 'RELEVE DE NOTES DES EVALUATIONS');
    $sheet->getStyle('A5')->getFont()->setBold(true)->setSize(16);
    $sheet->getStyle('A5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $sheet->mergeCells("A6:{$lettre($col_total)}6");
    $sheet->setCellValue('A6', $titre_periode);
    $sheet->getStyle('A6')->getFont()->setBold(true)->setSize(11);
    $sheet->getStyle('A6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $milieu = (int) floor($col_total / 2);
    $sheet->mergeCells("A7:{$lettre($milieu)}7");
    $sheet->setCellValue('A7', 'CLASSE : ' . ($classe['designation'] ?? ''));
    $sheet->getStyle('A7')->getFont()->setBold(true)->setSize(10);
    $sheet->mergeCells("{$lettre($milieu + 1)}7:{$lettre($col_total)}7");
    $sheet->setCellValue("{$lettre($milieu + 1)}7", 'ANNEE SCOLAIRE : ' . $val_annee);
    $sheet->getStyle("{$lettre($milieu + 1)}7")->getFont()->setBold(true)->setSize(10);
    $sheet->getStyle("{$lettre($milieu + 1)}7")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

    // ── En-tête du tableau ───────────────────────────────────────────────
    $row_hdr1 = 9; $row_hdr2 = 10; $row_data0 = 11;
    $sheet->mergeCells("{$lettre($col_no)}{$row_hdr1}:{$lettre($col_no)}{$row_hdr2}");
    $sheet->setCellValue("{$lettre($col_no)}{$row_hdr1}", 'N°');
    $sheet->mergeCells("{$lettre($col_mat_id)}{$row_hdr1}:{$lettre($col_mat_id)}{$row_hdr2}");
    $sheet->setCellValue("{$lettre($col_mat_id)}{$row_hdr1}", 'MATRICULE');
    $sheet->mergeCells("{$lettre($col_nom)}{$row_hdr1}:{$lettre($col_nom)}{$row_hdr2}");
    $sheet->setCellValue("{$lettre($col_nom)}{$row_hdr1}", 'NOM ET PRENOMS');
    $sheet->mergeCells("{$lettre($col_moy)}{$row_hdr1}:{$lettre($col_moy)}{$row_hdr2}");
    $sheet->setCellValue("{$lettre($col_moy)}{$row_hdr1}", 'MOY /20');
    $sheet->mergeCells("{$lettre($col_rang)}{$row_hdr1}:{$lettre($col_rang)}{$row_hdr2}");
    $sheet->setCellValue("{$lettre($col_rang)}{$row_hdr1}", 'RANG');
    $sheet->mergeCells("{$lettre($col_obs)}{$row_hdr1}:{$lettre($col_obs)}{$row_hdr2}");
    $sheet->setCellValue("{$lettre($col_obs)}{$row_hdr1}", 'OBSERVATIONS');

    foreach ($disciplines as $i => $d) {
        $c = $col_premiere_matiere + $i;
        $sheet->setCellValue("{$lettre($c)}{$row_hdr1}", $d['matiere']);
        $sheet->getStyle("{$lettre($c)}{$row_hdr1}")->getAlignment()->setTextRotation(90)->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sheet->setCellValue("{$lettre($c)}{$row_hdr2}", (int)$d['coef']);
        $sheet->getStyle("{$lettre($c)}{$row_hdr2}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getColumnDimension($lettre($c))->setWidth(6.5);
    }
    $sheet->getStyle("{$lettre($col_no)}{$row_hdr1}:{$lettre($col_total)}{$row_hdr2}")
        ->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFFFF'));
    $sheet->getStyle("{$lettre($col_no)}{$row_hdr1}:{$lettre($col_total)}{$row_hdr2}")
        ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1A3C6B');
    foreach ([$col_no, $col_mat_id, $col_nom, $col_moy, $col_rang, $col_obs] as $c) {
        $sheet->getStyle("{$lettre($c)}{$row_hdr1}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
    }
    $sheet->getRowDimension($row_hdr1)->setRowHeight(90);
    $sheet->getColumnDimension($lettre($col_no))->setWidth(4);
    $sheet->getColumnDimension($lettre($col_mat_id))->setWidth(11);
    $sheet->getColumnDimension($lettre($col_nom))->setWidth(26);
    $sheet->getColumnDimension($lettre($col_moy))->setWidth(8);
    $sheet->getColumnDimension($lettre($col_rang))->setWidth(9);
    $sheet->getColumnDimension($lettre($col_obs))->setWidth(20);

    // ── Lignes élèves ────────────────────────────────────────────────────
    $n = 1; $row = $row_data0;
    foreach ($eleves as $el) {
        $eid = (int)$el['id'];
        $sheet->setCellValue("{$lettre($col_no)}{$row}", $n);
        $sheet->setCellValue("{$lettre($col_mat_id)}{$row}", $el['matricule'] ?? '');
        $sheet->setCellValue("{$lettre($col_nom)}{$row}", strtoupper($el['nom']) . ' ' . ($el['prenom'] ?? ''));
        $sheet->getStyle("{$lettre($col_nom)}{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        foreach ($disciplines as $i => $d) {
            $c = $col_premiere_matiere + $i;
            $v = $moys_mat[$eid][$d['id_mat']] ?? null;
            if ($v !== null) $sheet->setCellValue("{$lettre($c)}{$row}", (float) fmt2($v));
        }
        $moy = $moys[$eid] ?? null;
        if ($moy !== null) $sheet->setCellValue("{$lettre($col_moy)}{$row}", (float) fmt2($moy));
        $sheet->getStyle("{$lettre($col_moy)}{$row}")->getFont()->setBold(true);

        $rang = $rangs[$eid] ?? null;
        if ($rang !== null) {
            $rt = new RichText();
            $rt->createTextRun((string)$rang);
            $sup = $rt->createTextRun('e');
            $sup->getFont()->setSuperscript(true);
            $rt->createTextRun(' /' . $nb_classes_);
            $sheet->getCell("{$lettre($col_rang)}{$row}")->setValue($rt);
        } else {
            $sheet->setCellValue("{$lettre($col_rang)}{$row}", '-');
        }
        $sheet->getStyle("{$lettre($col_rang)}{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $deja = $decisions_idx[$eid] ?? null;
        $obs = $deja ? trim((string)($deja['decision'] ?? '') . (!empty($deja['observation']) ? ' - ' . $deja['observation'] : '')) : '';
        $sheet->setCellValue("{$lettre($col_obs)}{$row}", $obs);

        if ($n % 2 === 0) {
            $sheet->getStyle("{$lettre($col_no)}{$row}:{$lettre($col_total)}{$row}")
                ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F0F0F6');
        }
        $n++; $row++;
    }
    $last_row = $row - 1;

    // Bordures + centrage par défaut sur tout le tableau (en-tête + données)
    $sheet->getStyle("{$lettre($col_no)}{$row_hdr1}:{$lettre($col_total)}{$last_row}")
        ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $sheet->getStyle("{$lettre($col_no)}{$row_data0}:{$lettre($col_total)}{$last_row}")
        ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
    $sheet->getStyle("{$lettre($col_nom)}{$row_data0}:{$lettre($col_nom)}{$last_row}")
        ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet->getStyle("{$lettre($col_obs)}{$row_data0}:{$lettre($col_obs)}{$last_row}")
        ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $sheet->getDefaultRowDimension()->setRowHeight(15);
    for ($r = $row_data0; $r <= $last_row; $r++) $sheet->getRowDimension($r)->setRowHeight(15);

    $sheet->getPageSetup()->setFitToWidth(1)->setFitToHeight(0);
    $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);

    // Signature numérique (sur demande uniquement, jamais automatique) —
    // image fixe en bas du document, pas de modale de positionnement pour
    // l'Excel (voir secondaire/pages/statistiques/excel_stats.php pour le même principe).
    if (($_GET['signature'] ?? '0') === '1' && signature_configuree('chef_etablissement')) {
        $sig_row = $last_row + 2;
        $sig_drawing = new Drawing();
        $sig_drawing->setName('Signature');
        $sig_drawing->setPath(signature_chemin('chef_etablissement'));
        $sig_drawing->setHeight(50);
        $sig_drawing->setCoordinates("{$lettre($col_obs)}{$sig_row}");
        $sig_drawing->setWorksheet($sheet);
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $suffix_fichier . '.xlsx"');
    header('Cache-Control: max-age=0');
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}
