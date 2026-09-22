<?php
// secondaire/pages/eleves/import_modele.php — modèle Excel téléchargeable
// pour l'import d'élèves (école secondaire), même principe que
// pages/eleves/import_modele.php (primaire) mais adapté au schéma secondaire
// (schema_ref_ecole_secondaire.sql, porté de LAM_ABZ) : pas d'arrondissement
// en table de référence (eleve.region_naiss/departement_naiss/
// arrondissement_naiss sont du texte libre, pas des FK), classes identifiées
// par leur designation (pas de Niveau en clair sur `classe`, juste
// code_niveau -> table `niveau`). Demande explicite du 17/09/2026.
// Feuille 1 : colonnes à remplir + une ligne d'exemple, avec listes
// déroulantes (Classe, Statut) puisées dans la feuille 2.
// Feuille 2 : listes de référence (classes, statuts).
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
require_once __DIR__ . '/../../../vendor/autoload.php';
exiger_role(['ADMIN', 'PROVISEUR', 'FONDATEUR', 'SECRETAIRE']);

use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$sp    = new Spreadsheet();
$sheet = $sp->getActiveSheet();
$sheet->setTitle('Élèves à importer');

$colonnes = [
    'Matricule', 'Nom*', 'Prénom(s)', 'Sexe (M/F)', 'Date de naissance (AAAA-MM-JJ)',
    'Lieu de naissance', 'Région de naissance', 'Département de naissance', 'Arrondissement de naissance',
    'Adresse', 'Téléphone', 'NIU', 'Classe', 'Statut',
];
foreach ($colonnes as $i => $lbl) {
    $sheet->setCellValue([$i + 1, 1], $lbl);
}
$plage_entete = 'A1:' . chr(64 + count($colonnes)) . '1';
$sheet->getStyle($plage_entete)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
$sheet->getStyle($plage_entete)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1E4FD8');
$sheet->getStyle($plage_entete)->getAlignment()->setWrapText(true);

// Ligne d'exemple (Matricule laissé vide : génération automatique à l'import)
$exemple = ['', 'ABOUBAKAR', 'Fatimatou', 'F', '2011-05-20', 'Ngaoundéré', 'Adamaoua', 'Vina', 'Ngaoundéré 1er', 'Quartier Baladji', '699000000', '', '6eme A', 'Nouveau'];
foreach ($exemple as $i => $val) { $sheet->setCellValue([$i + 1, 2], $val); }
$sheet->getStyle('A2:' . chr(64 + count($colonnes)) . '2')->getFont()->setItalic(true)->getColor()->setRGB('9CA3AF');

foreach (range('A', chr(64 + count($colonnes))) as $col) {
    $sheet->getColumnDimension($col)->setWidth(18);
}
$sheet->getColumnDimension('A')->setWidth(14);
$sheet->getColumnDimension('F')->setWidth(22);
$sheet->freezePane('A2');

// ── Feuille 2 : listes de référence + source des listes déroulantes ──
$sheetListes = $sp->createSheet();
$sheetListes->setTitle('Listes de référence');
$sheetListes->setCellValue('A1', 'Classes');
$sheetListes->setCellValue('B1', 'Statut');
$sheetListes->getStyle('A1:B1')->getFont()->setBold(true);

$classes = db_all(
    "SELECT c.designation FROM classe c
     LEFT JOIN niveau n ON n.code_niveau = c.code_niveau
     WHERE c.archivee = 0
     ORDER BY n.ordre_niveau, c.ordre, c.designation"
);
$r = 2;
foreach ($classes as $c) { $sheetListes->setCellValue('A' . $r, xl_safe($c['designation'])); $r++; }
$derniere_ligne_classes = max(2, $r - 1);

foreach (['Nouveau', 'Ancien', 'Redoublant', 'Transféré'] as $i => $s) {
    $sheetListes->setCellValue('B' . (2 + $i), $s);
}

$sheetListes->getColumnDimension('A')->setWidth(20);
$sheetListes->getColumnDimension('B')->setWidth(16);

// ── Listes déroulantes sur la feuille 1 (lignes 2 à 300) ────────────────
function appliquer_liste_deroulante(
    \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $feuille,
    string $colonne, int $premiere_ligne, int $derniere_ligne, string $formule
): void {
    for ($l = $premiere_ligne; $l <= $derniere_ligne; $l++) {
        $dv = $feuille->getCell($colonne . $l)->getDataValidation();
        $dv->setType(DataValidation::TYPE_LIST);
        $dv->setErrorStyle(DataValidation::STYLE_WARNING);
        $dv->setAllowBlank(true);
        $dv->setShowDropDown(true); // nom trompeur de PhpSpreadsheet : true = flèche affichée
        $dv->setShowInputMessage(true);
        $dv->setShowErrorMessage(true);
        $dv->setErrorTitle('Valeur non reconnue');
        $dv->setError("Merci de choisir une valeur dans la liste déroulante (ou de laisser la cellule vide).");
        $dv->setFormula1($formule);
    }
}

$derniere_ligne_saisie = 300;
appliquer_liste_deroulante($sheet, 'M', 2, $derniere_ligne_saisie, "'Listes de référence'!\$A\$2:\$A\$$derniere_ligne_classes");
appliquer_liste_deroulante($sheet, 'N', 2, $derniere_ligne_saisie, "'Listes de référence'!\$B\$2:\$B\$5");

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="modele_import_eleves_secondaire.xlsx"');
header('Cache-Control: max-age=0');
$writer = new Xlsx($sp);
$writer->save('php://output');
