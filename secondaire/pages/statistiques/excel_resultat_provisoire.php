<?php
/**
 * Export Excel (.xlsx) — Liste provisoire (effectif prévisionnel de
 * l'année suivante). Mêmes colonnes/valeurs que pdf/resultat_provisoire.php,
 * rendu partagé dans excel_resultat_commun.php.
 * GET : annee, classe (classe CIBLE), cols, align
 */
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../conseil_classe/excel_releve_commun.php';
require_once __DIR__ . '/excel_resultat_commun.php';
exiger_connexion();

$role     = role_connecte();
$is_admin = in_array($role, ['ADMIN', 'PROVISEUR', 'FONDATEUR', 'CENSEUR']) || $role === 'MEMBRE_ASSOCIATION';
$is_ens   = ($role === 'ENSEIGNANT');
$mat_ens  = $is_ens ? get_matricule_ens_connecte() : null;
if (!$is_admin && !$is_ens) die('Acces refuse.');

$id_annee        = (int)($_GET['annee']  ?? 0);
$id_classe_cible = (int)($_GET['classe'] ?? 0);
$cols            = explode(',', $_GET['cols']  ?? '');
$aligns          = explode(',', $_GET['align'] ?? '');

if (!$id_classe_cible) die('Classe manquante.');

if ($is_ens && $mat_ens) {
    $val_annee_ens = (string) db_val("SELECT libelle FROM annee_scolaire WHERE id=?", [$id_annee]);
    $ok = db_val(
        "SELECT COUNT(*) FROM enseignat_principal WHERE matricule_ens=? AND IDClasses=? AND val_annee=?",
        [$mat_ens, $id_classe_cible, $val_annee_ens]
    );
    if (!$ok) die('Acces refuse (professeur principal uniquement).');
}

$etab         = get_etablissement();
$annee_act    = get_annee_active();
$val_annee    = $annee_act['libelle'] ?? '';
$classe_cible = db_one("SELECT * FROM classe WHERE id=?", [$id_classe_cible]);
if (!$classe_cible) die('Classe introuvable.');
$annee_suivante_lib = libelle_annee_suivante((string)$val_annee);

$rows = calc_liste_provisoire_comp($id_annee, $id_classe_cible);

$def_cols = colonnes_liste_provisoire();
$sel = [];
foreach ($cols as $i => $k) {
    if (!isset($def_cols[$k])) continue;
    $al = in_array($aligns[$i] ?? '', ['L', 'C', 'R'], true) ? $aligns[$i] : 'L';
    $sel[$k] = [$def_cols[$k], $al];
}
if (empty($sel)) { foreach ($def_cols as $k => $lbl) $sel[$k] = [$lbl, 'L']; }

$nb_red = count(array_filter($rows, fn($r) => $r['statut'] === 'RED'));
$nb_nv  = count(array_filter($rows, fn($r) => $r['statut'] === 'NV'));

genererExcelResultat(
    $etab,
    'Liste provisoire',
    'Classe : ' . $classe_cible['designation'] . ' — Année ' . $annee_suivante_lib,
    "Redoublants (RED) : $nb_red    Nouveaux (NV) : $nb_nv    Total : " . count($rows),
    $sel,
    $rows,
    'valeur_colonne_provisoire',
    'liste_provisoire_' . preg_replace('/\W+/', '_', $classe_cible['designation'] ?? 'classe'),
    "Cette liste ne reprend que les décisions déjà enregistrées au Conseil de Classe. Un élève admis mais pas encore examiné (destination inconnue) n'y figure pas tant que sa décision n'est pas enregistrée."
);
