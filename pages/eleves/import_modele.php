<?php
// ── Modèle Excel téléchargeable pour l'import d'élèves ──────────
// Feuille 1 : colonnes à remplir + une ligne d'exemple, avec listes
// déroulantes réelles (validation de données Excel) pour Arrondissement,
// Classe et Statut — valeurs puisées dans la feuille 2 (jamais saisies à
// la main, donc jamais de faute de frappe qui ferait échouer le rapprochement
// à l'import).
// - Matricule (1ère colonne) : laissé vide pour génération automatique, ou
//   renseigné pour importer un élève avec un matricule déjà attribué
//   (migration depuis un autre système).
// - Arrondissement : une seule colonne (plus de "Département" séparé), au
//   format "Intitulé (Département)" pour lever toute ambiguïté entre
//   arrondissements homonymes de départements différents.
// Feuille 2 : listes de référence (classes, arrondissements groupés par
// département/région, statuts) — sert à la fois de dropdown ET de rappel
// visuel.
// Feuille 3 : fiche établissement — TOUTES les colonnes de la table
// `etablissement` (identité, localisation administrative, direction, en-tête
// bilingue FR/AR piste arabe) + logo en image, voir get_etablissement() —
// purement informatif, jamais lu par import.php.
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/../../vendor/autoload.php';
exiger_role(['DIRECTEUR','SECRETAIRE']);

$val_annee = get_annee_active()['val_annee'] ?? '';

use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$sp    = new Spreadsheet();
$sheet = $sp->getActiveSheet();
$sheet->setTitle('Élèves à importer');

$colonnes = [
    'Matricule', 'Nom*', 'Prénom(s)', 'Nom en arabe', 'Sexe (M/F)', 'Date de naissance (AAAA-MM-JJ)',
    'Lieu de naissance', 'Arrondissement', 'Adresse', 'NIU', 'Classe', 'Statut',
];
foreach ($colonnes as $i => $lbl) {
    $sheet->setCellValue([$i + 1, 1], $lbl);
}
$plage_entete = 'A1:' . chr(64 + count($colonnes)) . '1';
$sheet->getStyle($plage_entete)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
$sheet->getStyle($plage_entete)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1E4FD8');
$sheet->getStyle($plage_entete)->getAlignment()->setWrapText(true);

// Ligne d'exemple (Matricule laissé vide : génération automatique à l'import)
$exemple = ['', 'DOUKOURE', 'Awa', '', 'F', '2016-03-12', 'Ngaoundéré', 'Ngaoundéré 1er (Vina)', 'Quartier Baladji', '', 'SIL', 'Nouveau'];
foreach ($exemple as $i => $val) { $sheet->setCellValue([$i + 1, 2], $val); }
$sheet->getStyle('A2:' . chr(64 + count($colonnes)) . '2')->getFont()->setItalic(true)->getColor()->setRGB('9CA3AF');

foreach (range('A', chr(64 + count($colonnes))) as $col) {
    $sheet->getColumnDimension($col)->setWidth(20);
}
$sheet->getColumnDimension('A')->setWidth(14);
$sheet->getColumnDimension('D')->setWidth(22);
$sheet->getColumnDimension('F')->setWidth(24);
$sheet->getColumnDimension('H')->setWidth(30);
$sheet->getColumnDimension('L')->setWidth(16);
$sheet->freezePane('A2');

// ── Feuille 2 : listes de référence + source des listes déroulantes ──
$sheetListes = $sp->createSheet();
$sheetListes->setTitle('Listes de référence');
$sheetListes->setCellValue('A1', 'Classes');
$sheetListes->setCellValue('B1', 'Arrondissements (groupés par département/région)');
$sheetListes->setCellValue('C1', 'Statut');
$sheetListes->getStyle('A1:C1')->getFont()->setBold(true);

$classes = db_all(
    "SELECT DesignationClasses FROM classe c LEFT JOIN niveau n ON n.LibelleNiveau=c.Niveau
     ORDER BY n.OrdreNiveau, c.DesignationClasses"
);
$r = 2;
foreach ($classes as $c) { $sheetListes->setCellValue('A' . $r, $c['DesignationClasses']); $r++; }
$derniere_ligne_classes = max(2, $r - 1);

// Format "Intitulé (Département)" : lève l'ambiguïté entre arrondissements
// homonymes de départements différents, et permet à l'import de retrouver
// directement id_arrondissement sans passer par une colonne "Département"
// séparée (voir import.php).
$arrondissements = db_all(
    "SELECT CONCAT(a.intitule_arrond, ' (', d.intitule_depart, ')') AS lbl
     FROM arrondissement a
     JOIN departement d ON d.code_depart = a.code_depart
     JOIN region r ON r.id_region = d.code_region
     ORDER BY r.intitule_region, d.intitule_depart, a.intitule_arrond"
);
$r = 2;
foreach ($arrondissements as $a) { $sheetListes->setCellValue('B' . $r, $a['lbl']); $r++; }
$derniere_ligne_arrondissements = max(2, $r - 1);

$sheetListes->setCellValue('C2', 'Nouveau');
$sheetListes->setCellValue('C3', 'Redoublant');

$sheetListes->getColumnDimension('A')->setWidth(20);
$sheetListes->getColumnDimension('B')->setWidth(34);
$sheetListes->getColumnDimension('C')->setWidth(16);

// ── Feuille 3 : données de l'établissement (identité + logo) ───────────
// Purement informatif (rappel de quel établissement/quelle année ce fichier
// concerne, utile une fois le fichier téléchargé/archivé hors du logiciel)
// — jamais lue par l'import (import.php ne lit que la feuille 1).
$sheetEtab = $sp->createSheet();
$sheetEtab->setTitle('Établissement');
$etab = get_etablissement();

$sheetEtab->setCellValue('A1', 'Fiche établissement');
$sheetEtab->getStyle('A1')->getFont()->setBold(true)->setSize(14);
$sheetEtab->mergeCells('A1:B1');

// Toutes les données de la table `etablissement` (demande explicite du
// 20/08/2026 — pas juste un sous-ensemble), regroupées comme dans l'écran
// Configurations > Établissement (pages/parametres/index.php) : Identité,
// Localisation administrative, Direction, En-tête bilingue piste arabe. Les
// champs blob (photo_etab/filigranne_etab, non exposés dans l'UI, jamais
// utilisés) sont exclus — le logo est repris séparément en image ci-dessous.
$sections_etab = [
    'Identité' => [
        ['Nom (FR)',        $etab['Nom_Etab_Fr'] ?? ''],
        ['Nom (EN)',        $etab['Nom_Etab_An'] ?? ''],
        ['Sigle',           $etab['Initial_Etab'] ?? ''],
        ['Immatriculation', $etab['Immatriculation_Etab'] ?? ''],
        ['Boîte postale',   $etab['boite_postal'] ?? ''],
        ['Ville',           $etab['ville_etab'] ?? ''],
        ['Lieu-dit',        $etab['lieu_etab'] ?? ''],
        ['Téléphone',       $etab['tel_etab'] ?? ''],
        ['Email',           $etab['email_etab'] ?? ''],
    ],
    'Localisation administrative' => [
        // Libellés corrigés le 20/08/2026 — delegation_regional_fr/en et
        // delegation_departemental_fr/en contiennent en réalité le
        // Département et l'Arrondissement (noms de colonnes trompeurs
        // hérités du legacy, voir fonctions.php::etab_pour_pdf()), à ne pas
        // confondre avec les champs "Délégation régionale/départementale"
        // de la section "En-tête bilingue" plus bas — contenu réellement
        // différent (nom d'office de délégation, pas le simple nom du
        // département/arrondissement).
        ['Pays (FR)', $etab['pays_etab_fr'] ?? ''], ['Country (EN)', $etab['pays_etab_en'] ?? ''],
        ['Région (FR)', $etab['region_etab_fr'] ?? ''], ['Region (EN)', $etab['region_etab_en'] ?? ''],
        ['Département (FR)', $etab['delegation_regional_fr'] ?? ''], ['Department (EN)', $etab['delegation_regional_en'] ?? ''],
        ['Arrondissement (FR)', $etab['delegation_departemental_fr'] ?? ''], ['Arrondissement (EN)', $etab['delegation_departemental_en'] ?? ''],
    ],
    'Direction' => [
        ['Fonction du dirigeant (FR)', $etab['fonction_dirigeant_fr'] ?? ''],
        ['Fonction du dirigeant (EN)', $etab['fonction_dirigeant_en'] ?? ''],
    ],
    "En-tête bilingue — bulletins/certificats piste arabe" => [
        ['République (FR)', $etab['republique_fr'] ?? ''], ['République (AR)', $etab['republique_ar'] ?? ''],
        ['Devise (FR)', $etab['devise_fr'] ?? ''], ['Devise (AR)', $etab['devise_ar'] ?? ''],
        ['Ministère (FR)', $etab['ministere_fr'] ?? ''], ['Ministère (AR)', $etab['ministere_ar'] ?? ''],
        ['Délégation régionale (FR)', $etab['delegation_reg_fr'] ?? ''], ['Délégation régionale (AR)', $etab['delegation_reg_ar'] ?? ''],
        ['Délégation départementale (FR)', $etab['delegation_dep_fr'] ?? ''], ['Délégation départementale (AR)', $etab['delegation_dep_ar'] ?? ''],
        ['Arrondissement (FR)', $etab['arrondissement_fr'] ?? ''], ['Arrondissement (AR)', $etab['arrondissement_ar'] ?? ''],
        ["Nom de l'école (FR)", $etab['ecole_fr'] ?? ''], ["Nom de l'école (AR)", $etab['ecole_ar'] ?? ''],
    ],
    'Contexte de ce fichier' => [
        ['Année scolaire', $val_annee],
    ],
];

$r = 3;
foreach ($sections_etab as $titre_section => $champs) {
    $sheetEtab->setCellValue('A' . $r, $titre_section);
    $sheetEtab->mergeCells('A' . $r . ':B' . $r);
    $sheetEtab->getStyle('A' . $r)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $sheetEtab->getStyle('A' . $r)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1E4FD8');
    $r++;
    foreach ($champs as [$label, $valeur]) {
        $sheetEtab->setCellValue('A' . $r, $label);
        $sheetEtab->getStyle('A' . $r)->getFont()->setBold(true);
        $sheetEtab->setCellValue('B' . $r, $valeur);
        // Valeurs arabes affichées de droite à gauche, comme dans le
        // formulaire d'origine (dir="rtl" — pages/parametres/index.php).
        if (str_ends_with($label, '(AR)')) {
            $sheetEtab->getStyle('B' . $r)->getAlignment()->setReadOrder(Alignment::READORDER_RTL)->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }
        $r++;
    }
    $r++; // ligne vide entre sections
}
$sheetEtab->getColumnDimension('A')->setWidth(32);
$sheetEtab->getColumnDimension('B')->setWidth(38);
$sheetEtab->getColumnDimension('D')->setWidth(20);
$sheetEtab->freezePane('A2');

$logo_chemin = !empty($etab['logo']) ? __DIR__ . '/../../assets/uploads/' . $etab['logo'] : '';
if ($logo_chemin && is_file($logo_chemin)) {
    $drawing = new Drawing();
    $drawing->setName('Logo');
    $drawing->setPath($logo_chemin);
    $drawing->setHeight(110);
    $drawing->setCoordinates('D1');
    $drawing->setOffsetX(6);
    $drawing->setOffsetY(6);
    $drawing->setWorksheet($sheetEtab);
}

// ── Application des listes déroulantes sur la feuille 1 (lignes 2 à 300,
//     largement suffisant pour un import en masse) ──────────────────────
function appliquer_liste_deroulante(
    \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $feuille,
    string $colonne, int $premiere_ligne, int $derniere_ligne, string $formule
): void {
    for ($l = $premiere_ligne; $l <= $derniere_ligne; $l++) {
        $dv = $feuille->getCell($colonne . $l)->getDataValidation();
        $dv->setType(DataValidation::TYPE_LIST);
        $dv->setErrorStyle(DataValidation::STYLE_WARNING);
        $dv->setAllowBlank(true);
        $dv->setShowDropDown(true); // nom trompeur de PhpSpreadsheet : true = flèche affichée (writer inverse la valeur dans le XML)
        $dv->setShowInputMessage(true);
        $dv->setShowErrorMessage(true);
        $dv->setErrorTitle('Valeur non reconnue');
        $dv->setError("Merci de choisir une valeur dans la liste déroulante (ou de laisser la cellule vide).");
        $dv->setFormula1($formule);
    }
}

$derniere_ligne_saisie = 300;
appliquer_liste_deroulante($sheet, 'H', 2, $derniere_ligne_saisie, "'Listes de référence'!\$B\$2:\$B\$$derniere_ligne_arrondissements");
appliquer_liste_deroulante($sheet, 'K', 2, $derniere_ligne_saisie, "'Listes de référence'!\$A\$2:\$A\$$derniere_ligne_classes");
appliquer_liste_deroulante($sheet, 'L', 2, $derniere_ligne_saisie, "'Listes de référence'!\$C\$2:\$C\$3");

$sp->setActiveSheetIndex(0);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="modele_import_eleves.xlsx"');
header('Cache-Control: max-age=0');
$writer = new Xlsx($sp);
$writer->save('php://output');
