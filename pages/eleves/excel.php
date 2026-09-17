<?php
// Export Excel (.xlsx) de la liste des élèves — mêmes filtres que
// pages/eleves/liste.php et pdf/liste_eleves.php (classe, année active).
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/../../vendor/autoload.php';
exiger_connexion();

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$id_classe = (int)($_GET['classe'] ?? 0);

$annee     = get_annee_active();
$val_annee = $annee['val_annee'] ?? '';
$etab      = get_etablissement();

$where  = ["i.val_annee = ?", "e.statut='actif'"];
$params = [$val_annee];
if ($id_classe) { $where[] = "i.IDClasses=?"; $params[] = $id_classe; }

// Cloisonnement enseignant : export limité à ses classes (FR ∪ AR).
$ids_classes_vis = classes_ids_visibles($val_annee, 'union');
if ($ids_classes_vis !== null) {
    if ($id_classe && !in_array($id_classe, $ids_classes_vis, true)) {
        http_response_code(403);
        exit('Accès refusé : cette classe ne fait pas partie de vos affectations.');
    }
    if (!$ids_classes_vis) {
        $where[] = '1=0';
    } elseif (!$id_classe) {
        $where[] = 'i.IDClasses IN (' . implode(',', array_fill(0, count($ids_classes_vis), '?')) . ')';
        $params  = array_merge($params, $ids_classes_vis);
    }
}
$sql_where = 'WHERE ' . implode(' AND ', $where);

$eleves = db_all(
    "SELECT e.id_eleve, e.Mat_elv, e.niu, e.Nom_elv, e.Prenom_elv, e.Sexe_elv, e.Date_naiss_elv, e.Lieu_naiss_elv,
            c.DesignationClasses AS classe, i.Statut_elv
     FROM eleve e
     JOIN inscrire i ON i.id_eleve = e.id_eleve
     LEFT JOIN classe c ON c.IDClasses = i.IDClasses
     $sql_where
     ORDER BY e.Nom_elv, e.Prenom_elv", $params);

$classe = $id_classe ? db_one("SELECT * FROM classe WHERE IDClasses=?", [$id_classe]) : null;

$sp    = new Spreadsheet();
$sheet = $sp->getActiveSheet();
$sheet->setTitle('Élèves');

// ── En-tête établissement ─────────────────────────────────────
$sheet->mergeCells('A1:H1');
$sheet->setCellValue('A1', xl_safe($etab['Nom_Etab_Fr'] ?? APP_NOM));
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->mergeCells('A2:H2');
$sheet->setCellValue('A2', 'Liste des élèves — Année scolaire ' . $val_annee . ' — Classe : ' . ($classe['DesignationClasses'] ?? 'Toutes'));
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
$r = $ligne_entete + 1;
$no = 1;
foreach ($eleves as $e) {
    $sheet->setCellValue([1, $r], $no++);
    $sheet->setCellValue([2, $r], xl_safe($e['Mat_elv']));
    $sheet->setCellValue([3, $r], xl_safe($e['niu'] ?: '—'));
    $sheet->setCellValue([4, $r], xl_safe(mb_strtoupper($e['Nom_elv'])));
    $sheet->setCellValue([5, $r], xl_safe($e['Prenom_elv'] ?: ''));
    $sheet->setCellValue([6, $r], stripos($e['Sexe_elv'], 'F') === 0 ? 'F' : 'M');
    $sheet->setCellValue([7, $r], date_fr($e['Date_naiss_elv']));
    $sheet->setCellValue([8, $r], xl_safe($e['Lieu_naiss_elv'] ?: '—'));
    $sheet->setCellValue([9, $r], xl_safe($e['classe'] ?: '—'));
    $sheet->setCellValue([10, $r], libelle_statut_insc($e['Statut_elv']));
    $r++;
}

$plage_donnees = 'A' . $ligne_entete . ':' . chr(64 + count($colonnes)) . ($r - 1);
$sheet->getStyle($plage_donnees)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

foreach (range('A', chr(64 + count($colonnes))) as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// ── Pied : total / effectifs ────────────────────────────────────
$total = count($eleves);
$nb_m  = count(array_filter($eleves, fn($e) => stripos($e['Sexe_elv'], 'M') === 0));
$nb_f  = $total - $nb_m;
$sheet->setCellValue([1, $r + 1], "Total : $total élève(s) — Garçons : $nb_m — Filles : $nb_f");
$sheet->getStyle('A' . ($r + 1))->getFont()->setBold(true);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="liste_eleves_' . date('Ymd') . '.xlsx"');
header('Cache-Control: max-age=0');
$writer = new Xlsx($sp);
$writer->save('php://output');
