<?php
// secondaire/commun/pages/eleves/excel.php — Export Excel (.xlsx) de la liste des élèves —
// mêmes filtres que secondaire/commun/pages/eleves/liste.php (classe, année active, statut actif).
require_once __DIR__ . '/../../../../config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
require_once __DIR__ . '/../../../../vendor/autoload.php';
exiger_connexion();

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$id_classe = (int) ($_GET['classe'] ?? 0);

$annee    = get_annee_active();
$id_annee = (int) ($annee['id'] ?? 0);
$etab     = get_etablissement();

$where  = ["i.id_annee = ?", "e.statut='actif'"];
$params = [$id_annee];
// Même périmètre que secondaire/commun/pages/eleves/liste.php : un
// enseignant / SG n'exporte que les classes qui lui sont visibles.
$ids_classes_vis = sec_classes_ids_visibles();
if ($ids_classes_vis !== null) {
    if ($id_classe && !in_array($id_classe, $ids_classes_vis, true)) $id_classe = 0;
    if (!$ids_classes_vis) {
        $where[] = '1=0';
    } else {
        $where[] = 'i.id_classe IN (' . implode(',', array_fill(0, count($ids_classes_vis), '?')) . ')';
        $params  = array_merge($params, $ids_classes_vis);
    }
}
if ($id_classe) { $where[] = "i.id_classe=?"; $params[] = $id_classe; }
$sql_where = 'WHERE ' . implode(' AND ', $where);

$eleves = db_all(
    "SELECT e.id, e.matricule, e.niu, e.nom, e.prenom, e.sexe, e.date_naiss, e.lieu_naiss,
            c.designation AS classe, i.statut
     FROM eleve e
     JOIN inscription i ON i.id_eleve = e.id
     LEFT JOIN classe c ON c.id = i.id_classe
     $sql_where
     ORDER BY e.nom, e.prenom",
    $params
);

$classe = $id_classe ? db_one("SELECT * FROM classe WHERE id=?", [$id_classe]) : null;

$sp    = new Spreadsheet();
$sheet = $sp->getActiveSheet();
$sheet->setTitle('Élèves');

// ── En-tête établissement ─────────────────────────────────────
$sheet->mergeCells('A1:J1');
$sheet->setCellValue('A1', $etab['nom_fr'] ?? APP_NOM);
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->mergeCells('A2:J2');
$sheet->setCellValue('A2', 'Liste des élèves — Année scolaire ' . ($annee['libelle'] ?? '') . ' — Classe : ' . ($classe['designation'] ?? 'Toutes'));
$sheet->getStyle('A2')->getFont()->setItalic(true)->setSize(10);
$sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// ── En-tête colonnes ──────────────────────────────────────────
$colonnes = ['N°', 'Matricule', 'NIU', 'Nom', 'Prénom(s)', 'Sexe', 'Date naiss.', 'Lieu naiss.', 'Classe', 'Statut'];
$ligne_entete = 4;
foreach ($colonnes as $i => $lbl) {
    $sheet->setCellValue([$i + 1, $ligne_entete], $lbl);
}
$plage_entete = 'A' . $ligne_entete . ':' . chr(64 + count($colonnes)) . $ligne_entete;
$sheet->getStyle($plage_entete)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
$sheet->getStyle($plage_entete)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1E4FD8');
$sheet->getStyle($plage_entete)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// ── Lignes ────────────────────────────────────────────────────
$r  = $ligne_entete + 1;
$no = 1;
foreach ($eleves as $e) {
    $sheet->setCellValue([1, $r], $no++);
    $sheet->setCellValue([2, $r], $e['matricule']);
    $sheet->setCellValue([3, $r], $e['niu'] ?: '—');
    $sheet->setCellValue([4, $r], mb_strtoupper($e['nom']));
    $sheet->setCellValue([5, $r], $e['prenom'] ?: '');
    $sheet->setCellValue([6, $r], $e['sexe']);
    $sheet->setCellValue([7, $r], $e['date_naiss'] ? date('d/m/Y', strtotime($e['date_naiss'])) : '—');
    $sheet->setCellValue([8, $r], $e['lieu_naiss'] ?: '—');
    $sheet->setCellValue([9, $r], $e['classe'] ?: '—');
    $sheet->setCellValue([10, $r], $e['statut'] ?: '—');
    $r++;
}

$plage_donnees = 'A' . $ligne_entete . ':' . chr(64 + count($colonnes)) . ($r - 1);
$sheet->getStyle($plage_donnees)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

foreach (range('A', chr(64 + count($colonnes))) as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// ── Pied : total / effectifs ────────────────────────────────────
$total = count($eleves);
$nb_m  = count(array_filter($eleves, fn($e) => $e['sexe'] === 'M'));
$nb_f  = $total - $nb_m;
$sheet->setCellValue([1, $r + 1], "Total : $total élève(s) — Garçons : $nb_m — Filles : $nb_f");
$sheet->getStyle('A' . ($r + 1))->getFont()->setBold(true);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="liste_eleves_' . date('Ymd') . '.xlsx"');
header('Cache-Control: max-age=0');
$writer = new Xlsx($sp);
$writer->save('php://output');
