<?php
/**
 * Export Excel (.xlsx) — Meilleurs élèves (palmarès de l'établissement,
 * toutes classes confondues). Réservé à l'administration. Mêmes colonnes/
 * valeurs que pdf/resultat_meilleurs.php, rendu partagé dans
 * excel_resultat_commun.php.
 * GET : annee, n, cols, align
 */
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../conseil_classe/excel_releve_commun.php';
require_once __DIR__ . '/excel_resultat_commun.php';
exiger_role(['ADMIN', 'PROVISEUR', 'FONDATEUR', 'CENSEUR']);

$id_annee = (int)($_GET['annee'] ?? 0);
$n        = max(1, (int)($_GET['n'] ?? 10));
$cols     = explode(',', $_GET['cols']  ?? '');
$aligns   = explode(',', $_GET['align'] ?? '');

$etab      = get_etablissement();
$annee_act = get_annee_active();
$val_annee = $annee_act['libelle'] ?? '';

$rows = array_slice(calc_resultat_annuel_comp($id_annee, 0), 0, $n);

$def_cols = colonnes_resultat_classe(true);
$sel = [];
foreach ($cols as $i => $k) {
    if (!isset($def_cols[$k])) continue;
    $al = in_array($aligns[$i] ?? '', ['L', 'C', 'R'], true) ? $aligns[$i] : 'L';
    $sel[$k] = [$def_cols[$k], $al];
}
if (empty($sel)) { foreach ($def_cols as $k => $lbl) $sel[$k] = [$lbl, 'L']; }

genererExcelResultat(
    $etab,
    "Meilleurs élèves de l'établissement",
    "Top $n élève(s) — toutes classes confondues",
    'Année scolaire : ' . $val_annee,
    $sel,
    $rows,
    'valeur_colonne_resultat',
    'meilleurs_eleves'
);
