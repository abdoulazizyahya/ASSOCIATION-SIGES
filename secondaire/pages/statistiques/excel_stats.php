<?php
/**
 * Export Excel (.xlsx) des statistiques avancées (Section / Niveau /
 * Matière / Classe enrichi) — même en-tête (logo + filigrane) que
 * secondaire/pages/conseil_classe/excel_releve_commun.php, mêmes données que
 * secondaire/pages/statistiques/index.php et secondaire/pages/statistiques/pdf_stats.php.
 * GET : onglet (section|niveau|matiere|eleves), vue, seq, trim, classe
 */
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../conseil_classe/excel_releve_commun.php';
exiger_connexion();

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

$role     = role_connecte();
$is_admin = in_array($role, ['ADMIN', 'PROVISEUR', 'CENSEUR']);
$is_ens   = ($role === 'ENSEIGNANT');
$mat_ens  = $is_ens ? get_matricule_ens_connecte() : null;
if (!$is_admin && !$is_ens) die('Accès refusé.');

$annee_act = get_annee_active();
$id_annee  = (int)($annee_act['id'] ?? 0);
$val_annee = $annee_act['libelle'] ?? '';
$etab      = get_etablissement();

// Séquence active de l'année — toujours celle EN COURS, jamais un choix
// parmi les séquences passées (même principe que le trimestre ci-dessous).
$seq_active = db_one(
    "SELECT s.*, t.id AS id_trim, t.libelle AS trim_lib
     FROM sequence s JOIN trimestre t ON t.id=s.id_trim
     WHERE s.active=1 AND t.id_annee=? LIMIT 1",
    [$id_annee]
);

$onglet    = in_array($_GET['onglet'] ?? '', ['section', 'niveau', 'matiere', 'eleves'], true) ? $_GET['onglet'] : 'section';
$vue       = $_GET['vue']    ?? 'seq';
$id_seq    = (int)($seq_active['id'] ?? 0);
$id_trim   = (int)($seq_active['id_trim'] ?? 0);
$id_classe = (int)($_GET['classe'] ?? 0);

// Trimestre actif (compétences/APC) — remplace la séquence active comme
// proxy de "la période en cours" (voir prompt_continuite du 07/08/2026,
// Phase 6). "Séquence" et "Trimestre" pointent tous deux vers ce trimestre.
$trim_comp_actif = get_trimestre_actif();
$id_trim_comp     = (int)($trim_comp_actif['id'] ?? 0);
if ($vue !== 'annee' && !$id_trim_comp) die('Aucun trimestre actif.');
$trims_calc_stats = $vue === 'annee'
    ? array_column(db_all("SELECT id FROM trimestre WHERE id_annee=? ORDER BY ordre", [$id_annee]), 'id')
    : ($id_trim_comp ? [$id_trim_comp] : []);

if ($is_admin) {
    $classes = db_all("SELECT c.* FROM classe c JOIN inscription i ON i.id_classe=c.id AND i.id_annee=? WHERE c.archivee=0 GROUP BY c.id ORDER BY c.ordre,c.designation", [$id_annee]);
} else {
    $classes = db_all("SELECT DISTINCT c.* FROM classe c JOIN dispenser d ON d.IDClasses=c.id AND d.matricule_ens=? AND d.val_annee=? WHERE c.archivee=0 ORDER BY c.ordre,c.designation", [$mat_ens, $val_annee]);
}
if ($id_classe) $classes = array_filter($classes, fn($c) => (int)$c['id'] === $id_classe);

$titres = [
    'section' => 'Bilan par section', 'niveau' => 'Bilan par niveau',
    'matiere' => 'Bilan par matière (école)', 'eleves' => 'Résultats par classe',
];
$bilan_cols = ['classes', 'moy_lt10', 'moy_ge10', 'felicit', 'encourag', 'tab', 'avert_trav', 'blame_trav'];
$labels_cols = ['classes' => 'Classés', 'moy_lt10' => 'Moy<10', 'moy_ge10' => 'Moy>=10', 'felicit' => 'Félicit.', 'encourag' => 'Encour.', 'tab' => 'T.H', 'avert_trav' => 'Avert.T', 'blame_trav' => 'Blâme T.'];

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Statistiques');

// ── En-tête (logo + filigrane, même mise en forme que le relevé de notes) ──
$logo_src_path  = !empty($etab['logo']) ? __DIR__ . '/../../../assets/uploads/' . $etab['logo'] : '';
$filigrane_path = __DIR__ . '/../../../assets/uploads/filigrane_excel.png';
if ($logo_src_path && generer_filigrane_excel($logo_src_path, $filigrane_path)) {
    $sheet->setBackgroundImage(file_get_contents($filigrane_path));
}

$lettre = fn(int $c) => Coordinate::stringFromColumnIndex($c);
$col_total = max(10, count($bilan_cols) * 3 + 1);

$texte_etab = "REGION DE L'ADAMAOUA\n" .
    ($etab['departement_fr'] ?? 'DEPARTEMENT DE LA VINA') . "\n" .
    ($etab['arrondissement_fr'] ?? 'ARRONDISSEMENT DE MBE') . "\n" .
    '***********';
$nom_etab = strtoupper($etab['nom_fr'] ?? 'LYCEE TECHNIQUE DE MBE');
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
$sheet->setCellValue('A5', 'STATISTIQUES SCOLAIRES');
$sheet->getStyle('A5')->getFont()->setBold(true)->setSize(16);
$sheet->getStyle('A5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->mergeCells("A6:{$lettre($col_total)}6");
$sheet->setCellValue('A6', strtoupper($titres[$onglet]) . ' — ' . $val_annee);
$sheet->getStyle('A6')->getFont()->setBold(true)->setSize(11);
$sheet->getStyle('A6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$row = 8;

// ── Table "bilan" M/F/T (Section / Niveau) : une section par bloc — mêmes
// couleurs EXACTES que le fichier Excel de référence fourni par
// l'utilisateur (TEST_PV_CALCUL.xlsx, onglet INDUSTRIELLE, inspecté via
// PhpSpreadsheet) : en-tête de groupe B7CDF3 (bleu clair), sous-en-tête
// M/F/T et ligne SOUS-TOTAL DEC58A (or/beige), texte noir gras (pas blanc),
// bordures fines. ──────────────────────────────────────────────────────
function excel_stats_tableau_bilan(&$sheet, int &$row, string $titre, array $lignes, array $bilan_cols, array $labels_cols): void {
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
    $sheet->getStyle("A" . ($hdr1) . ":{$lettre($col_total)}{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $sheet->getStyle("A{$hdr1}:{$lettre($col_total)}{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $row += 2;
}

if ($onglet === 'section' || $onglet === 'niveau') {
    $all_classes = db_all("SELECT c.* FROM classe c JOIN inscription i ON i.id_classe=c.id AND i.id_annee=? WHERE c.archivee=0 GROUP BY c.id ORDER BY c.ordre, c.designation", [$id_annee]);
    if ($onglet === 'section') {
        $groupes = [];
        foreach ($all_classes as $c) { $groupes[$c['libelle_section'] ?? 'Non définie'][] = $c; }
        ksort($groupes);
    } else {
        $niveaux_ref = db_all("SELECT code_niveau, libelle_niv FROM niveau ORDER BY ordre_niveau");
        $niveaux_map = array_column($niveaux_ref, 'libelle_niv', 'code_niveau');
        $ordre_niv   = array_column($niveaux_ref, 'libelle_niv');
        $groupes = [];
        foreach ($all_classes as $c) {
            $niv = $niveaux_map[$c['code_niveau']] ?? ($c['code_niveau'] ?: 'Non défini');
            $groupes[$niv][] = $c;
        }
        uksort($groupes, function ($a, $b) use ($ordre_niv) {
            $ia = array_search($a, $ordre_niv); $ib = array_search($b, $ordre_niv);
            if ($ia === false) $ia = 999;
            if ($ib === false) $ib = 999;
            return $ia <=> $ib;
        });
    }
    foreach ($groupes as $titre_groupe => $classes_groupe) {
        $lignes = [];
        foreach ($classes_groupe as $c) {
            $lignes[] = ['classe' => $c['designation'], 'bilan' => calc_bilan_classe_genre_comp((int)$c['id'], $id_annee, $val_annee, $vue, $id_trim_comp)];
        }
        excel_stats_tableau_bilan($sheet, $row, $titre_groupe, $lignes, $bilan_cols, $labels_cols);
    }
    foreach (range(1, count($bilan_cols) * 3 + 1) as $ci) { $sheet->getColumnDimensionByColumn($ci)->setWidth($ci === 1 ? 22 : 8); }

} elseif ($onglet === 'matiere') {
    $classe_ids_mat = array_column($classes, 'id');
    $mat_stats = [];
    if (!empty($classe_ids_mat) && !empty($trims_calc_stats)) {
        $in_c = implode(',', array_fill(0, count($classe_ids_mat), '?'));
        $in_t = implode(',', array_fill(0, count($trims_calc_stats), '?'));
        $rows = db_all(
            "SELECT n.id_matiere, n.id_eleve, n.valeur, m.libelle AS matiere
             FROM note n
             JOIN competence comp ON comp.id = n.id_competence AND comp.id_trim IN ($in_t)
             JOIN inscription i ON i.id_eleve=n.id_eleve AND i.id_annee=? AND i.id_classe IN ($in_c)
             JOIN classe cl ON cl.id=i.id_classe AND cl.code_niveau=comp.code_niveau
             JOIN matiere m ON m.id=n.id_matiere AND m.actif=1
             JOIN discipline d ON d.id_mat=n.id_matiere AND d.IDClasses=i.id_classe",
            array_merge($trims_calc_stats, [$id_annee], $classe_ids_mat)
        );
        $par_mat = [];
        foreach ($rows as $r) {
            $par_mat[$r['id_matiere']]['libelle'] = $r['matiere'];
            $par_mat[$r['id_matiere']]['vals'][$r['id_eleve']][] = (float)$r['valeur'];
        }
        foreach ($par_mat as $info) {
            $avgs = [];
            foreach ($info['vals'] as $vs) { $avgs[] = array_sum($vs) / count($vs); }
            $nb = count($avgs);
            $admis = count(array_filter($avgs, fn($a) => $a >= 10));
            $mat_stats[] = [
                'matiere' => $info['libelle'], 'nb' => $nb,
                'moy' => $nb > 0 ? array_sum($avgs) / $nb : null,
                'min' => $nb > 0 ? min($avgs) : null, 'max' => $nb > 0 ? max($avgs) : null,
                'taux' => $nb > 0 ? round($admis / $nb * 100, 1) : 0,
            ];
        }
        usort($mat_stats, fn($a, $b) => strcmp($a['matiere'], $b['matiere']));
    }
    $headers = ['Matière', 'Nb notes', 'Moyenne', 'Min', 'Max', 'Taux réussite'];
    foreach ($headers as $i => $h) $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . $row, $h);
    $sheet->getStyle("A{$row}:F{$row}")->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFFFF'));
    $sheet->getStyle("A{$row}:F{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1A3C6B');
    $row++;
    foreach ($mat_stats as $ms) {
        $sheet->setCellValue("A{$row}", $ms['matiere']);
        $sheet->setCellValue("B{$row}", $ms['nb']);
        if ($ms['moy'] !== null) $sheet->setCellValue("C{$row}", (float) fmt2($ms['moy']));
        if ($ms['min'] !== null) $sheet->setCellValue("D{$row}", (float) fmt2($ms['min']));
        if ($ms['max'] !== null) $sheet->setCellValue("E{$row}", (float) fmt2($ms['max']));
        $sheet->setCellValue("F{$row}", $ms['taux'] . '%');
        $row++;
    }
    $sheet->getStyle("A8:F" . ($row - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    foreach (['A' => 50, 'B' => 12, 'C' => 12, 'D' => 10, 'E' => 10, 'F' => 14] as $col => $w) $sheet->getColumnDimension($col)->setWidth($w);

} elseif ($onglet === 'eleves') {
    $classes_show = $classes;
    $headers = ['Classe', 'Effectif', 'Filles', 'Garçons', 'Moyenne', 'Premier', 'Dernier', 'Admis', 'Taux réussite', 'Félicit.', 'Encour.', 'T.H', 'Avert.T', 'Blâme T.'];
    foreach ($headers as $i => $h) $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . $row, $h);
    $sheet->getStyle("A{$row}:N{$row}")->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFFFF'));
    $sheet->getStyle("A{$row}:N{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1A3C6B');
    $row++;
    $hdr_row = $row - 1;
    foreach ($classes_show as $c) {
        // Compétences (par matière) du trimestre actif — ou des trimestres
        // de l'année fusionnés en vue annuelle (chantier APC).
        $eleves = db_all("SELECT e.id, e.sexe FROM eleve e JOIN inscription i ON i.id_eleve=e.id AND i.id_classe=? AND i.id_annee=? WHERE e.statut='actif'", [$c['id'], $id_annee]);
        // Règles 1/2/3/4 appliquées via le moteur commun (fonctions.php) —
        // remplace l'ancien calcul local (moyennes pondérées à la main sur les
        // compétences des trimestres fusionnées, sans zéro auto/seuil de
        // classement/annulation).
        $moys = calc_moys_classe_periode_comp((int)$c['id'], $id_annee, $vue, $id_trim_comp, array_column($eleves, 'id'));
        $filles = count(array_filter($eleves, fn($el) => $el['sexe'] === 'F'));
        $garcons = count($eleves) - $filles;
        $nb = count($eleves);
        $admis = count(array_filter($moys, fn($m) => $m >= 10));
        $bilan = calc_bilan_classe_genre_comp((int)$c['id'], $id_annee, $val_annee, $vue, $id_trim_comp);
        $sheet->setCellValue("A{$row}", $c['designation']);
        $sheet->setCellValue("B{$row}", $nb);
        $sheet->setCellValue("C{$row}", $filles);
        $sheet->setCellValue("D{$row}", $garcons);
        if (!empty($moys)) $sheet->setCellValue("E{$row}", (float) fmt2(array_sum($moys) / count($moys)));
        if (!empty($moys)) $sheet->setCellValue("F{$row}", (float) fmt2(max($moys)));
        if (!empty($moys)) $sheet->setCellValue("G{$row}", (float) fmt2(min($moys)));
        $sheet->setCellValue("H{$row}", $admis);
        // Règle 2 : taux de réussite sur les classés (count($moys)), pas l'effectif total.
        $sheet->setCellValue("I{$row}", (!empty($moys) ? round($admis / count($moys) * 100, 1) : 0) . '%');
        $sheet->setCellValue("J{$row}", $bilan['felicit']['T']);
        $sheet->setCellValue("K{$row}", $bilan['encourag']['T']);
        $sheet->setCellValue("L{$row}", $bilan['tab']['T']);
        $sheet->setCellValue("M{$row}", $bilan['avert_trav']['T']);
        $sheet->setCellValue("N{$row}", $bilan['blame_trav']['T']);
        $row++;
    }
    $sheet->getStyle("A{$hdr_row}:N" . ($row - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    foreach (['A' => 24] as $col => $w) $sheet->getColumnDimension($col)->setWidth($w);
    foreach (range('B', 'N') as $col) $sheet->getColumnDimension($col)->setWidth(11);
}

// Signature numérique (sur demande uniquement, jamais automatique) — image
// fixe en bas du document, aucune modale de positionnement pour l'Excel
// (PhpSpreadsheet ne permet pas de prévisualisation glisser-déposer côté
// serveur, contrairement aux PDF prévisualisés en iframe).
if (($_GET['signature'] ?? '0') === '1' && signature_configuree('chef_etablissement')) {
    $row += 2;
    $sig_drawing = new Drawing();
    $sig_drawing->setName('Signature');
    $sig_drawing->setPath(signature_chemin('chef_etablissement'));
    $sig_drawing->setHeight(50);
    $sig_drawing->setCoordinates("B{$row}");
    $sig_drawing->setWorksheet($sheet);
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="stats_' . $onglet . '_' . date('Ymd') . '.xlsx"');
header('Cache-Control: max-age=0');
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
