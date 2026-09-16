<?php
/**
 * Export Excel (.xlsx) — Résultat par classe (Admis/Redoublants/Exclus/
 * Toute la classe). Mêmes colonnes/valeurs que pdf/resultat_classe.php,
 * rendu partagé dans excel_resultat_commun.php.
 * GET : annee, classe, filtre (admis|redoublement|exclu|tous), cols, align
 */
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../conseil_classe/excel_releve_commun.php';
require_once __DIR__ . '/excel_resultat_commun.php';
exiger_connexion();

$role     = role_connecte();
$is_admin = in_array($role, ['ADMIN', 'PROVISEUR', 'CENSEUR']);
$is_ens   = ($role === 'ENSEIGNANT');
$mat_ens  = $is_ens ? get_matricule_ens_connecte() : null;
if (!$is_admin && !$is_ens) die('Acces refuse.');

$id_annee  = (int)($_GET['annee']  ?? 0);
$id_classe = (int)($_GET['classe'] ?? 0);
$filtre    = in_array($_GET['filtre'] ?? '', ['admis', 'redoublement', 'exclu', 'tous'], true) ? $_GET['filtre'] : 'tous';
$cols      = explode(',', $_GET['cols']  ?? '');
$aligns    = explode(',', $_GET['align'] ?? '');

if (!$id_classe) die('Classe manquante.');

if ($is_ens && $mat_ens) {
    $val_annee_ens = (string) db_val("SELECT libelle FROM annee_scolaire WHERE id=?", [$id_annee]);
    $ok = db_val(
        "SELECT COUNT(*) FROM enseignat_principal WHERE matricule_ens=? AND IDClasses=? AND val_annee=?",
        [$mat_ens, $id_classe, $val_annee_ens]
    );
    if (!$ok) die('Acces refuse (professeur principal uniquement).');
}

$etab      = get_etablissement();
$annee_act = get_annee_active();
$val_annee = $annee_act['libelle'] ?? '';
$classe    = db_one("SELECT * FROM classe WHERE id=?", [$id_classe]);
if (!$classe) die('Classe introuvable.');

$filtre_map      = ['admis' => 'Admis', 'redoublement' => 'Redoublement', 'exclu' => 'Exclu'];
$filtre_decision = $filtre_map[$filtre] ?? null;
$filtre_titre    = ['admis' => 'Liste des admis', 'redoublement' => 'Liste des redoublants', 'exclu' => 'Liste des exclus', 'tous' => 'Résultat de la classe'][$filtre];

$rows = calc_resultat_annuel_comp($id_annee, $id_classe);
if ($filtre_decision) {
    $rows = array_values(array_filter($rows, fn($r) => $r['decision'] === $filtre_decision));
}

$def_cols = colonnes_resultat_classe(false);
$sel = [];
foreach ($cols as $i => $k) {
    if (!isset($def_cols[$k])) continue;
    $al = in_array($aligns[$i] ?? '', ['L', 'C', 'R'], true) ? $aligns[$i] : 'L';
    $sel[$k] = [$def_cols[$k], $al];
}
if (empty($sel)) { foreach ($def_cols as $k => $lbl) $sel[$k] = [$lbl, 'L']; }

genererExcelResultat(
    $etab,
    $filtre_titre,
    'Classe : ' . $classe['designation'],
    'Année scolaire : ' . $val_annee,
    $sel,
    $rows,
    'valeur_colonne_resultat',
    'resultat_' . $filtre . '_' . preg_replace('/\W+/', '_', $classe['designation'] ?? 'classe')
);
