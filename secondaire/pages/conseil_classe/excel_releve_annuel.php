<?php
/**
 * RELEVE DE NOTES DES EVALUATIONS — export Excel (.xlsx), version ANNUELLE
 * (voir excel_releve.php pour le détail de la mise en forme commune,
 * excel_releve_commun.php). Moyenne générale/par matière = moyenne des 3
 * moyennes trimestrielles (même méthode que pdf_releve_annuel.php /
 * secondaire/pages/bulletins/pdf_annuel.php).
 * GET : classe, annee
 */
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
require_once __DIR__ . '/../../../vendor/autoload.php';
exiger_connexion();

$role     = role_connecte();
$is_admin = in_array($role, ['ADMIN', 'PROVISEUR', 'CENSEUR']);
$is_ens   = ($role === 'ENSEIGNANT');
$mat_ens  = $is_ens ? get_matricule_ens_connecte() : null;
if (!$is_admin && !$is_ens) die('Acces non autorise.');

$id_classe = (int)($_GET['classe'] ?? 0);
$id_annee  = (int)($_GET['annee']  ?? 0);
$ordre     = in_array($_GET['ordre'] ?? '', ['alpha', 'merite']) ? $_GET['ordre'] : 'alpha';

if (!$id_classe) die('Classe manquante.');

$annee_act = get_annee_active();
if (!$id_annee) $id_annee = (int)($annee_act['id'] ?? 0);
$val_annee = $annee_act['libelle'] ?? '';

if ($is_ens && $mat_ens) {
    $ok = db_val(
        "SELECT COUNT(*) FROM enseignat_principal WHERE matricule_ens=? AND IDClasses=? AND val_annee=?",
        [$mat_ens, $id_classe, $val_annee]
    );
    if (!$ok) die('Acces refuse.');
}

$classe = db_one("SELECT * FROM classe WHERE id=?", [$id_classe]);
if (!$classe) die('Classe introuvable.');

$etab = get_etablissement();

$eleves = db_all(
    "SELECT e.id, e.matricule, e.nom, e.prenom FROM eleve e
     JOIN inscription i ON i.id_eleve=e.id AND i.id_classe=? AND i.id_annee=?
     WHERE e.statut='actif'",
    [$id_classe, $id_annee]
);
$nb_inscrits = count($eleves);

$disciplines = db_all(
    "SELECT d.id_mat, d.coef, d.ordre, m.libelle AS matiere
     FROM discipline d JOIN matiere m ON m.id=d.id_mat AND m.actif=1
     WHERE d.IDClasses=? ORDER BY d.id_groupe, d.ordre, m.libelle",
    [$id_classe]
);
$nb_mat = count($disciplines);

// Les compétences (chantier APC) sont scopées par trimestre entier — la
// moyenne annuelle = moyenne des moyennes trimestrielles existantes, même
// méthode que pdf_releve_annuel.php (voir prompt_continuite, 07/08/2026).
$trimestres = db_all("SELECT * FROM trimestre WHERE id_annee=? ORDER BY ordre", [$id_annee]);
$trimestres = array_pad(array_slice($trimestres, 0, 3), 3, null);

$moys_gen_par_trim = [[], [], []];
$moys_mat_par_trim = [[], [], []];
foreach ([0, 1, 2] as $i) {
    $t = $trimestres[$i];
    if (!$t) continue;
    $dcomp = pv_charger_donnees_comp($id_classe, (int)$t['id'], $id_annee);
    if (empty($dcomp['competences_par_mat'])) continue;
    foreach ($eleves as $el) {
        $eid = (int)$el['id'];
        [$m, $classable] = pv_moy_generale_comp(
            $eid, $dcomp['disciplines'], $dcomp['competences_par_mat'], $dcomp['notes_idx'], $dcomp['notes_count'],
            $dcomp['nb_inscrits'], $dcomp['mats_avec_notes'], $dcomp['nb_mats_avec_notes']
        );
        if ($classable && $m !== null) $moys_gen_par_trim[$i][$eid] = $m;
        foreach ($disciplines as $d) {
            $mm = pv_moy_matiere_comp(
                $eid, $dcomp['competences_par_mat'][$d['id_mat']] ?? [], $dcomp['notes_idx'], $dcomp['notes_count'], $dcomp['nb_inscrits']
            );
            if ($mm !== null) $moys_mat_par_trim[$i][$eid][$d['id_mat']] = $mm;
        }
    }
}
$moys = []; $moys_mat = [];
foreach ($eleves as $el) {
    $eid = (int)$el['id'];
    $vals = [];
    foreach ([0, 1, 2] as $i) { if (isset($moys_gen_par_trim[$i][$eid])) $vals[] = $moys_gen_par_trim[$i][$eid]; }
    if (!empty($vals)) $moys[$eid] = array_sum($vals) / count($vals);
    foreach ($disciplines as $d) {
        $vm = [];
        foreach ([0, 1, 2] as $i) { if (isset($moys_mat_par_trim[$i][$eid][$d['id_mat']])) $vm[] = $moys_mat_par_trim[$i][$eid][$d['id_mat']]; }
        $moys_mat[$eid][$d['id_mat']] = !empty($vm) ? array_sum($vm) / count($vm) : null;
    }
}
arsort($moys);
$nb_classes_ = count($moys);
$rangs = []; $rg = 1;
foreach ($moys as $eid => $m) { $rangs[$eid] = $rg++; }

$decisions_idx = [];
$rows = db_all("SELECT * FROM decision_conseil WHERE id_classe=? AND id_annee=? AND type='annee' AND id_trim=0", [$id_classe, $id_annee]);
foreach ($rows as $r) $decisions_idx[(int)$r['id_eleve']] = $r;

if ($ordre === 'merite') {
    usort($eleves, function ($a, $b) use ($moys) {
        $ma = $moys[(int)$a['id']] ?? null; $mb = $moys[(int)$b['id']] ?? null;
        if ($ma === null && $mb === null) return 0;
        if ($ma === null) return 1;
        if ($mb === null) return -1;
        return $mb <=> $ma;
    });
} else {
    usort($eleves, fn($a, $b) => strcmp($a['nom'] . ' ' . ($a['prenom'] ?? ''), $b['nom'] . ' ' . ($b['prenom'] ?? '')));
}

require_once __DIR__ . '/excel_releve_commun.php';
genererExcelReleve($etab, $classe, 'ANNUELLE', $val_annee, $disciplines, $eleves, $moys, $moys_mat, $rangs, $nb_classes_, $decisions_idx, 'releve_annuel_' . preg_replace('/\W+/', '_', $classe['designation'] ?? 'classe'));
