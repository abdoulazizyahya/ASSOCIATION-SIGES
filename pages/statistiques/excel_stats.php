<?php
/**
 * Export Excel (.xlsx) des statistiques — port d'ABZ_MBE à l'identique
 * (même en-tête logo+filigrane, mêmes couleurs/mise en forme exactes que le
 * fichier de référence : en-tête de groupe B7CDF3, ligne SOUS-TOTAL DEC58A,
 * bordures fines) — seules les DONNÉES viennent de notes_apc.php (jaynitaare :
 * compétences par classe/niveau, pas matière/section comme ABZ_MBE).
 * GET : onglet (niveau|competence|eleves), vue (trim|annee), trim, classe
 */
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/../../notes_apc.php';
require_once __DIR__ . '/../../vendor/autoload.php';
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

$annee_act = get_annee_active();
$val_annee = $annee_act['val_annee'] ?? '';
$etab_brut = get_etablissement();
$etab      = etab_pour_pdf($etab_brut);

$onglet    = in_array($_GET['onglet'] ?? '', ['niveau', 'non_evalue', 'competences', 'eleves'], true) ? $_GET['onglet'] : 'niveau';
$vue       = in_array($_GET['vue'] ?? '', ['trim', 'annee'], true) ? $_GET['vue'] : 'trim';
$id_trim   = (int) ($_GET['trim'] ?? 0);
$id_classe = (int) ($_GET['classe'] ?? 0);
exiger_acces_classe($id_classe, $val_annee, 'fr');   // cloisonnement enseignant
if ($vue === 'trim' && !$id_trim) die('Aucun trimestre sélectionné.');
// Onglets « Par classe/Résultats » et « Par compétence / Évaluation ».
// $seq_stat (demande du 18/08/2026) : id_seq précis choisi sur la page web —
// repli sur la séquence active si absent (anciens liens/favoris).
$niveau_f  = $_GET['niveau'] ?? '';
$mode_eval = in_array($_GET['mode'] ?? '', ['seq', 'trim'], true) ? $_GET['mode'] : 'trim';
$seq_act_xls_top = get_sequence_active();
$id_seq_stat = (int) ($_GET['seq_stat'] ?? ($seq_act_xls_top['id_seq'] ?? 0));

$classes = filtrer_classes_visibles(db_all(
    "SELECT c.IDClasses, c.DesignationClasses, c.Niveau, n.OrdreNiveau
     FROM classe c
     LEFT JOIN niveau n ON n.LibelleNiveau = c.Niveau
     JOIN inscrire i ON i.IDClasses = c.IDClasses AND i.val_annee = ?
     GROUP BY c.IDClasses, c.DesignationClasses, c.Niveau, n.OrdreNiveau
     ORDER BY n.OrdreNiveau, c.DesignationClasses",
    [$val_annee]
), $val_annee, 'fr');
if ($id_classe)           $classes = array_values(array_filter($classes, fn($c) => (int) $c['IDClasses'] === $id_classe));
elseif ($niveau_f !== '') $classes = array_values(array_filter($classes, fn($c) => $c['Niveau'] === $niveau_f));

$titres = ['niveau' => 'Bilan par niveau', 'non_evalue' => 'Non évalué', 'competences' => 'Par compétence / Évaluation', 'eleves' => 'Résultats par classe'];
// Demande du 18/08/2026 : Félicit./Encour./T.H/Avert.T/Blâme T retirés de
// l'onglet Par niveau, remplacés par 7 tranches de moyenne plus fines
// (Classés conservé en tête) — voir bilan_classe_genre() (notes_apc.php).
$bilan_cols  = ['classes', 'tr_0_7', 'tr_7_10', 'tr_10_12', 'tr_12_14', 'tr_14_16', 'tr_16_18', 'tr_18_20'];
$labels_cols = [
    'classes' => 'Classés', 'tr_0_7' => 'Moy<7', 'tr_7_10' => '7<=Moy<10', 'tr_10_12' => '10<=Moy<12',
    'tr_12_14' => '12<=Moy<14', 'tr_14_16' => '14<=Moy<16', 'tr_16_18' => '16<=Moy<18', 'tr_18_20' => '18<=Moy',
];

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Statistiques');

// ── En-tête (logo + filigrane, même mise en forme que le relevé de notes) ──
$logo_src_path  = !empty($etab['logo']) ? __DIR__ . '/../../assets/uploads/' . $etab['logo'] : '';
$filigrane_path = __DIR__ . '/../../assets/uploads/filigrane_excel_' . md5((string) ($etab['logo'] ?? '')) . '.png'; // un fichier PAR logo : un nom fixe partagé mélangeait les écoles
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
$sheet->setCellValue('A5', 'STATISTIQUES SCOLAIRES');
$sheet->getStyle('A5')->getFont()->setBold(true)->setSize(16);
$sheet->getStyle('A5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->mergeCells("A6:{$lettre($col_total)}6");
$sheet->setCellValue('A6', strtoupper($titres[$onglet]) . ' — ' . $val_annee);
$sheet->getStyle('A6')->getFont()->setBold(true)->setSize(11);
$sheet->getStyle('A6')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$row = 8;

// ── Table "bilan" M/F/T (Niveau) — mêmes couleurs exactes qu'ABZ_MBE ──────
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
        $sheet->setCellValue("A{$row}", xl_safe($l['classe']));
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
    // $seqs_override (demande du 18/08/2026) : même bascule « Évaluation en
    // cours » que les autres onglets — voir bilan_classe_genre().
    $seqs_override = ($vue === 'trim' && $mode_eval === 'seq' && $id_seq_stat) ? [$id_seq_stat] : null;
    $niveaux_map = array_column(db_all("SELECT LibelleNiveau, OrdreNiveau FROM niveau ORDER BY OrdreNiveau"), 'OrdreNiveau', 'LibelleNiveau');
    $groupes = [];
    foreach ($classes as $c) { $groupes[$c['Niveau'] ?: 'Non défini'][] = $c; }
    uksort($groupes, fn($a, $b) => ($niveaux_map[$a] ?? 999) <=> ($niveaux_map[$b] ?? 999));
    foreach ($groupes as $niveau => $classes_niveau) {
        $lignes = [];
        foreach ($classes_niveau as $c) {
            $lignes[] = ['classe' => $c['DesignationClasses'], 'bilan' => bilan_classe_genre((int) $c['IDClasses'], $val_annee, $vue, $id_trim, $seqs_override)];
        }
        excel_stats_tableau_bilan($sheet, $row, 'Niveau ' . $niveau, $lignes, $bilan_cols, $labels_cols);
    }
    foreach (range(1, count($bilan_cols) * 3 + 1) as $ci) { $sheet->getColumnDimensionByColumn($ci)->setWidth($ci === 1 ? 22 : 8); }

} elseif ($onglet === 'non_evalue') {
    // Non évalué (demande du 18/08/2026, suite) : liste NOMINATIVE, par
    // classe, des élèves inscrits/actifs qui n'entrent pas dans « Élèves
    // évalués » (onglet Par classe/Résultats), avec la raison — explique
    // l'écart Scolarité > Élèves vs Statistiques. Voir
    // eleves_non_evalues_classe() (notes_apc.php).
    $classes_xls = $classes;
    if ($niveau_f !== '' && !$id_classe) $classes_xls = array_values(array_filter($classes_xls, fn($c) => $c['Niveau'] === $niveau_f));

    $total_inscrits_xls = 0; $lignes_ne = [];
    foreach ($classes_xls as $c2) {
        $nb = (int) db_val(
            "SELECT COUNT(*) FROM inscrire i JOIN eleve e ON e.id_eleve=i.id_eleve WHERE i.IDClasses=? AND i.val_annee=? AND e.statut='actif'",
            [(int) $c2['IDClasses'], $val_annee]
        );
        $total_inscrits_xls += $nb;
        foreach (eleves_non_evalues_classe((int) $c2['IDClasses'], $val_annee, $vue, $id_trim) as $e) {
            $lignes_ne[] = $e + ['classe' => $c2['DesignationClasses']];
        }
    }
    $total_evalues_xls = $total_inscrits_xls - count($lignes_ne);

    $sheet->setCellValue("A{$row}", "Effectif inscrit : {$total_inscrits_xls}   —   Élèves évalués : {$total_evalues_xls}   —   Non évalué (écart) : " . count($lignes_ne));
    $sheet->getStyle("A{$row}")->getFont()->setBold(true);
    $row += 2;

    $headers = ['Classe', 'Matricule', 'Nom et prénom', 'Sexe', 'Raison'];
    foreach ($headers as $i => $h) $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . $row, $h);
    $sheet->getStyle("A{$row}:E{$row}")->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFFFF'));
    $sheet->getStyle("A{$row}:E{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1A3C6B');
    $row++;
    $hdr_row = $row - 1;
    foreach ($lignes_ne as $e) {
        $sheet->setCellValue("A{$row}", xl_safe($e['classe']));
        $sheet->setCellValue("B{$row}", xl_safe($e['Mat_elv']));
        $sheet->setCellValue("C{$row}", xl_safe($e['Nom_elv'] . ' ' . ($e['Prenom_elv'] ?? '')));
        $sheet->setCellValue("D{$row}", stripos($e['Sexe_elv'] ?? '', 'F') === 0 ? 'F' : 'M');
        $sheet->setCellValue("E{$row}", xl_safe($e['raison']));
        $row++;
    }
    if ($lignes_ne) {
        $sheet->getStyle("A{$hdr_row}:E" . ($row - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }
    $sheet->getColumnDimension('A')->setWidth(20);
    $sheet->getColumnDimension('B')->setWidth(14);
    $sheet->getColumnDimension('C')->setWidth(34);
    $sheet->getColumnDimension('D')->setWidth(8);
    $sheet->getColumnDimension('E')->setWidth(32);

} elseif ($onglet === 'competences') {
    // Par compétence / Évaluation (ex Enseignant, demande du 18/08/2026) :
    // code en tête, Nb évalués/Échoués/Admis/Taux ventilés F/M/T, filtre
    // Niveau en plus de Classe, Évaluation en cours ou Trimestre entier.
    // Une table par classe, l'une sous l'autre (même principe que le PDF).
    $classes_xls = $classes;
    if ($niveau_f !== '' && !$id_classe) $classes_xls = array_values(array_filter($classes_xls, fn($c) => $c['Niveau'] === $niveau_f));

    if ($vue === 'annee') {
        $seqs_choisis = array_column(db_all("SELECT s.id_seq FROM sequence s JOIN trimestre t ON t.id_trim = s.id_trim WHERE t.id_annee = ?", [$val_annee]), 'id_seq');
    } elseif ($mode_eval === 'seq') {
        $seqs_choisis = $id_seq_stat ? [$id_seq_stat] : [];
    } else {
        $seqs_choisis = sequences_du_trimestre($id_trim);
    }

    $groupes_comp = ['Nb évalués' => 'nb', 'Échoués' => 'echoues', 'Admis' => 'admis', 'Taux réussite' => 'taux'];
    $col_c_total = 4 + count($groupes_comp) * 3; // Code/Compétence/Barème/Moyenne + 4 groupes × F/M/T

    foreach ($classes_xls as $c2) {
        $stats = stats_par_competence_genre([(int) $c2['IDClasses']], $val_annee, $seqs_choisis);
        if (empty($stats)) continue;

        $sheet->mergeCells("A{$row}:{$lettre($col_c_total)}{$row}");
        $sheet->setCellValue("A{$row}", xl_safe($c2['DesignationClasses']));
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(11);
        $row++;

        $hdr1 = $row; $hdr2 = $row + 1;
        foreach (['A' => 'Code', 'B' => 'Compétence', 'C' => 'Barème', 'D' => 'Moyenne'] as $col => $lbl) {
            $sheet->mergeCells("{$col}{$hdr1}:{$col}{$hdr2}");
            $sheet->setCellValue("{$col}{$hdr1}", $lbl);
        }
        $c = 5;
        foreach (array_keys($groupes_comp) as $lbl) {
            $sheet->mergeCells("{$lettre($c)}{$hdr1}:{$lettre($c + 2)}{$hdr1}");
            $sheet->setCellValue("{$lettre($c)}{$hdr1}", $lbl);
            $sheet->setCellValue("{$lettre($c)}{$hdr2}", 'F');
            $sheet->setCellValue("{$lettre($c + 1)}{$hdr2}", 'M');
            $sheet->setCellValue("{$lettre($c + 2)}{$hdr2}", 'T');
            $c += 3;
        }
        $sheet->getStyle("A{$hdr1}:{$lettre($col_c_total)}{$hdr1}")->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFFFF'));
        $sheet->getStyle("A{$hdr1}:{$lettre($col_c_total)}{$hdr1}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1A3C6B');
        $sheet->getStyle("A{$hdr2}:{$lettre($col_c_total)}{$hdr2}")->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFFFF'));
        $sheet->getStyle("A{$hdr2}:{$lettre($col_c_total)}{$hdr2}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1A3C6B');
        $sheet->getStyle("A{$hdr1}:{$lettre($col_c_total)}{$hdr2}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $row = $hdr2 + 1;

        foreach ($stats as $s) {
            $sheet->setCellValue("A{$row}", xl_safe($s['code'] ?: '—'));
            $sheet->setCellValue("B{$row}", xl_safe($s['competence']));
            $sheet->setCellValue("C{$row}", '/' . (int) $s['bareme']);
            if ($s['moy']['T'] !== null) $sheet->setCellValue("D{$row}", (float) fmt2($s['moy']['T']));
            $col = 5;
            foreach ($groupes_comp as $k) {
                foreach (['F', 'M', 'T'] as $g) {
                    $val = $k === 'taux' ? $s[$k][$g] . '%' : $s[$k][$g];
                    $sheet->setCellValue("{$lettre($col)}{$row}", $val);
                    $col++;
                }
            }
            $row++;
        }
        $sheet->getStyle("A{$hdr1}:{$lettre($col_c_total)}" . ($row - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $row += 2;
    }
    $sheet->getColumnDimension('A')->setWidth(10);
    $sheet->getColumnDimension('B')->setWidth(40);
    foreach (range(3, $col_c_total) as $ci) $sheet->getColumnDimensionByColumn($ci)->setWidth(10);

} elseif ($onglet === 'eleves') {
    // Demande du 17/08/2026 (+ suivi) : Félicitations/Encouragements/Avert./
    // Blâme retirés (seul T.H reste) ; Recalés/Admis/T.H/Taux de réussite
    // ventilés par genre (F/G/T) — Moy Gen reste une seule valeur (colonne
    // à part, pas de ventilation par genre). Ligne TOTAL en pied de tableau.
    // $seqs_override (demande du 18/08/2026) : même bascule « Évaluation en
    // cours » que la page web — voir stats_classe()/bilan_classe_genre().
    $seqs_override = ($vue === 'trim' && $mode_eval === 'seq' && $id_seq_stat) ? [$id_seq_stat] : null;
    $groupes_lbl = ['Recalés' => 'moy_lt10', 'Admis' => 'moy_ge10', 'T.H' => 'tab'];
    $col_e_total = 7 + (count($groupes_lbl) + 1) * 3; // 7 colonnes fixes (dont Moy Gen) + (3 groupes + Taux) × F/G/T

    $hdr1 = $row; $hdr2 = $row + 1;
    foreach (['A' => 'Classe', 'B' => 'Effectif', 'C' => 'F', 'D' => 'M', 'E' => 'Moy. 1er', 'F' => 'Moy. dernier', 'G' => 'Moy Gen'] as $col => $lbl) {
        $sheet->mergeCells("{$col}{$hdr1}:{$col}{$hdr2}");
        $sheet->setCellValue("{$col}{$hdr1}", $lbl);
    }
    $c = 8;
    foreach (array_merge(array_keys($groupes_lbl), ['Taux réussite']) as $lbl) {
        $sheet->mergeCells("{$lettre($c)}{$hdr1}:{$lettre($c + 2)}{$hdr1}");
        $sheet->setCellValue("{$lettre($c)}{$hdr1}", $lbl);
        $sheet->setCellValue("{$lettre($c)}{$hdr2}", 'F');
        $sheet->setCellValue("{$lettre($c + 1)}{$hdr2}", 'M');
        $sheet->setCellValue("{$lettre($c + 2)}{$hdr2}", 'T');
        $c += 3;
    }
    $sheet->getStyle("A{$hdr1}:{$lettre($col_e_total)}{$hdr1}")->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFFFF'));
    $sheet->getStyle("A{$hdr1}:{$lettre($col_e_total)}{$hdr1}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1A3C6B');
    $sheet->getStyle("A{$hdr2}:{$lettre($col_e_total)}{$hdr2}")->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFFFF'));
    $sheet->getStyle("A{$hdr2}:{$lettre($col_e_total)}{$hdr2}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1A3C6B');
    $sheet->getStyle("A{$hdr1}:{$lettre($col_e_total)}{$hdr2}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
    $sheet->getStyle("A{$hdr1}:{$lettre($col_e_total)}{$hdr2}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $row = $hdr2 + 1;
    $hdr_row = $hdr1;

    $g_classes = ['M' => 0, 'F' => 0, 'T' => 0]; $g_admis = ['M' => 0, 'F' => 0, 'T' => 0]; $g_tab = ['M' => 0, 'F' => 0, 'T' => 0];
    $g_nb = 0; $g_filles = 0; $g_garcons = 0; $g_moy_somme = 0.0;
    foreach ($classes as $c2) {
        $st = stats_classe((int) $c2['IDClasses'], $val_annee, $vue, $id_trim, $seqs_override);
        $b  = bilan_classe_genre((int) $c2['IDClasses'], $val_annee, $vue, $id_trim, $seqs_override);
        foreach (['M', 'F', 'T'] as $g) { $g_classes[$g] += $b['classes'][$g]; $g_admis[$g] += $b['moy_ge10'][$g]; $g_tab[$g] += $b['tab'][$g]; }
        $g_nb += $st['nb']; $g_filles += $st['filles']; $g_garcons += $st['garcons'];
        if ($b['moy_gen']['T'] !== null) $g_moy_somme += $b['moy_gen']['T'] * $b['classes']['T'];

        $sheet->setCellValue("A{$row}", xl_safe($c2['DesignationClasses']));
        $sheet->setCellValue("B{$row}", $st['nb']);
        $sheet->setCellValue("C{$row}", $st['filles']);
        $sheet->setCellValue("D{$row}", $st['garcons']);
        if ($st['premier'] !== null) $sheet->setCellValue("E{$row}", (float) fmt2($st['premier']));
        if ($st['dernier'] !== null) $sheet->setCellValue("F{$row}", (float) fmt2($st['dernier']));
        if ($b['moy_gen']['T'] !== null) $sheet->setCellValue("G{$row}", (float) fmt2($b['moy_gen']['T']));
        $col = 8;
        foreach ($groupes_lbl as $k) {
            foreach (['F', 'M', 'T'] as $g) { $sheet->setCellValue("{$lettre($col)}{$row}", $b[$k][$g]); $col++; }
        }
        foreach (['F', 'M', 'T'] as $g) {
            $taux = $b['classes'][$g] > 0 ? round($b['moy_ge10'][$g] / $b['classes'][$g] * 100, 1) : 0;
            $sheet->setCellValue("{$lettre($col)}{$row}", $taux . '%');
            $col++;
        }
        $row++;
    }
    $sheet->getStyle("A{$hdr_row}:{$lettre($col_e_total)}" . ($row - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

    // ── Ligne TOTAL (somme pour les effectifs, moyenne pondérée pour Moy Gen/Taux) ──
    $sheet->setCellValue("A{$row}", 'TOTAL');
    $sheet->setCellValue("B{$row}", $g_nb);
    $sheet->setCellValue("C{$row}", $g_filles);
    $sheet->setCellValue("D{$row}", $g_garcons);
    if ($g_classes['T'] > 0) $sheet->setCellValue("G{$row}", (float) fmt2($g_moy_somme / $g_classes['T']));
    $col = 8;
    foreach (['F', 'M', 'T'] as $g) { $sheet->setCellValue("{$lettre($col)}{$row}", $g_classes[$g] - $g_admis[$g]); $col++; }
    foreach (['F', 'M', 'T'] as $g) { $sheet->setCellValue("{$lettre($col)}{$row}", $g_admis[$g]); $col++; }
    foreach (['F', 'M', 'T'] as $g) { $sheet->setCellValue("{$lettre($col)}{$row}", $g_tab[$g]); $col++; }
    foreach (['F', 'M', 'T'] as $g) {
        $taux = $g_classes[$g] > 0 ? round($g_admis[$g] / $g_classes[$g] * 100, 1) : 0;
        $sheet->setCellValue("{$lettre($col)}{$row}", $taux . '%');
        $col++;
    }
    $sheet->getStyle("A{$row}:{$lettre($col_e_total)}{$row}")->getFont()->setBold(true);
    $sheet->getStyle("A{$row}:{$lettre($col_e_total)}{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D6EAF8');
    $sheet->getStyle("A{$row}:{$lettre($col_e_total)}{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

    $sheet->getColumnDimension('A')->setWidth(30);
    foreach (range(2, $col_e_total) as $ci) $sheet->getColumnDimensionByColumn($ci)->setWidth(10);
    $sheet->getColumnDimension('E')->setWidth(8); // Moy. 1er
    $sheet->getColumnDimension('F')->setWidth(8); // Moy. dernier
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
header('Content-Disposition: attachment;filename="stats_' . $onglet . '_' . date('Ymd') . '.xlsx"');
header('Cache-Control: max-age=0');
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
