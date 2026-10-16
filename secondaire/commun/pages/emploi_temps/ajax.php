<?php
// secondaire/commun/pages/emploi_temps/ajax.php — actions de la saisie manuelle.
//   GET  action=grille&classe=      → fragment HTML de la grille éditable
//   GET  action=dispo&service=&duree= (ou mat/ens/classe) → état de chaque case (ok / souhaitee / conflit)
//   POST action=placer | deplacer | supprimer | verrou | salle (csrf)
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
require_once __DIR__ . '/_edt_lib.php';
exiger_role(EDT_ROLES_GESTION);

$a = edt_annee_id();
$act = $_REQUEST['action'] ?? '';
$json = function (array $d): void { header('Content-Type: application/json; charset=utf-8'); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; };

if ($act === 'grille') {
    header('Content-Type: text/html; charset=utf-8');
    echo edt_grille_html($a, 'classe', (int) ($_GET['classe'] ?? 0), ['editable' => edt_peut_ecrire()]);
    exit;
}

/** Paramètres d'un cours à placer : depuis un service, ou libre (matière + enseignant). */
function edt_ajax_cours(int $a): ?array {
    // Séance existante (déplacement) : mêmes matière, enseignant, salle, classe
    $ids = array_values(array_filter(array_map('intval', explode(',', (string) ($_GET['deplace'] ?? '')))));
    if ($ids) {
        $s = db_one("SELECT * FROM edt_seance WHERE id_annee=? AND id=?", [$a, $ids[0]]);
        if (!$s) return null;
        return ['classes' => [(int) $s['id_classe'] => $s['id_service'] ? (int) $s['id_service'] : null], 'id_mat' => (int) $s['id_mat'],
                'ens' => $s['matricule_ens'] !== null ? (int) $s['matricule_ens'] : null, 'salle' => $s['id_salle'] ? (int) $s['id_salle'] : null, 'commun' => $s['code_commun'] ?: null];
    }
    $sid = (int) ($_REQUEST['service'] ?? 0);
    if ($sid) {
        $s = db_one("SELECT * FROM edt_service WHERE id=? AND id_annee=?", [$sid, $a]);
        if (!$s) return null;
        $classes = [(int) $s['id_classe'] => (int) $s['id']];
        if ($s['code_commun']) foreach (db_all("SELECT id, id_classe FROM edt_service WHERE id_annee=? AND code_commun=?", [$a, $s['code_commun']]) as $x) $classes[(int) $x['id_classe']] = (int) $x['id'];
        return ['classes' => $classes, 'id_mat' => (int) $s['id_mat'], 'ens' => $s['matricule_ens'] !== null ? (int) $s['matricule_ens'] : null,
                'salle' => $s['id_salle'] ? (int) $s['id_salle'] : null, 'commun' => $s['code_commun'] ?: null];
    }
    $c = (int) ($_REQUEST['classe'] ?? 0); $m = (int) ($_REQUEST['mat'] ?? 0);
    if (!$c || !$m) return null;
    return ['classes' => [$c => null], 'id_mat' => $m, 'ens' => (int) ($_REQUEST['ens'] ?? 0) ?: null, 'salle' => (int) ($_REQUEST['salle'] ?? 0) ?: null, 'commun' => null];
}
/** Cours commun : le message indique la classe concernée. */
function edt_ajax_prefixe(EdtModele $mod, array $cours, int $c, array $p): array {
    if (count($cours['classes']) > 1 && $p['type'] !== 'enseignant') $p['msg'] = ($mod->classes[$c]['designation'] ?? '') . ' : ' . $p['msg'];
    return $p;
}
/** Créneaux d'un bloc de $duree périodes commençant à $cr (null si la journée est trop courte). */
function edt_ajax_creneaux(EdtModele $mod, int $cr, int $duree): ?array {
    $rg = $mod->cours[$cr]['rang'] ?? 0;
    $out = [];
    for ($k = 0; $k < $duree; $k++) { if (!isset($mod->rangId[$rg + $k])) return null; $out[] = $mod->rangId[$rg + $k]; }
    return $out;
}

if ($act === 'dispo') {
    $cours = edt_ajax_cours($a);
    if (!$cours) $json(['ok' => false]);
    $duree = max(1, min(9, (int) ($_GET['duree'] ?? 1)));
    $mod = new EdtModele($a);
    $ignorer = array_filter(explode(',', (string) ($_GET['ignorer'] ?? '')), 'strlen');
    $cases = [];
    foreach ($mod->jours as $j) foreach ($mod->cours as $cr => $_) {
        $crs = edt_ajax_creneaux($mod, $cr, $duree);
        if (!$crs) { $cases["$j|$cr"] = ['etat' => 'conflit', 'msg' => ['La journée se termine avant la fin de la séance']]; continue; }
        $pb = [];
        foreach ($cours['classes'] as $c => $_s)
            foreach ($mod->problemes(['id_classe' => $c, 'id_mat' => $cours['id_mat'], 'matricule_ens' => $cours['ens'], 'id_salle' => $cours['salle'], 'jour' => $j, 'creneaux' => $crs, 'code_commun' => $cours['commun']], $ignorer) as $p) $pb[] = edt_ajax_prefixe($mod, $cours, $c, $p);
        $etat = 'ok';
        foreach ($pb as $p) { if ($p['niveau'] !== 'souhaitee') { $etat = 'conflit'; break; } $etat = 'souhaitee'; }
        $cases["$j|$cr"] = ['etat' => $etat, 'msg' => array_values(array_unique(array_column($pb, 'msg')))];
    }
    $json(['ok' => true, 'cases' => $cases]);
}

// ── Écritures ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !edt_peut_ecrire()) $json(['ok' => false, 'msg' => 'Action non autorisée.']);
csrf_verifier();
$mod = new EdtModele($a);
$ids = array_values(array_filter(array_map('intval', explode(',', (string) ($_POST['ids'] ?? '')))));
$forcer = ($_POST['forcer'] ?? '') === '1';

/** Insère un bloc après contrôle. Retour [ok, problemes]. */
function edt_ajax_inserer(int $a, EdtModele $mod, array $cours, int $j, array $crs, array $ignorer, bool $forcer, string $sg = '', int $verrou = 0): array {
    $pb = [];
    foreach ($cours['classes'] as $c => $_s)
        foreach ($mod->problemes(['id_classe' => $c, 'id_mat' => $cours['id_mat'], 'matricule_ens' => $cours['ens'], 'id_salle' => $cours['salle'], 'jour' => $j, 'creneaux' => $crs, 'code_commun' => $cours['commun'], 'sous_groupe' => $sg], $ignorer) as $p) $pb[] = edt_ajax_prefixe($mod, $cours, $c, $p);
    $bloquant = array_filter($pb, fn($p) => $p['type'] === 'classe');
    if ($bloquant) return [false, array_values($pb), false];
    $durs = array_filter($pb, fn($p) => $p['niveau'] !== 'souhaitee');
    if ($durs && !$forcer) return [false, array_values($pb), true];
    foreach ($cours['classes'] as $c => $sid) foreach ($crs as $cr)
        db_exec("INSERT INTO edt_seance (id_annee,id_classe,sous_groupe,jour,id_creneau,id_service,id_mat,matricule_ens,id_salle,code_commun,verrou,origine) VALUES (?,?,?,?,?,?,?,?,?,?,?,'manuel')",
                [$a, $c, $sg, $j, $cr, $sid, $cours['id_mat'], $cours['ens'], $cours['salle'], $cours['commun'], $verrou]);
    return [true, array_values($pb), false];
}

if ($act === 'placer') {
    $cours = edt_ajax_cours($a);
    $j = (int) ($_POST['jour'] ?? 0); $cr = (int) ($_POST['cr'] ?? 0); $duree = max(1, min(9, (int) ($_POST['duree'] ?? 1)));
    if (!$cours || !in_array($j, $mod->jours, true) || !isset($mod->cours[$cr])) $json(['ok' => false, 'msg' => 'Données incomplètes.']);
    $crs = edt_ajax_creneaux($mod, $cr, $duree);
    if (!$crs) $json(['ok' => false, 'msg' => 'La séance dépasse la fin de la journée.']);
    [$ok, $pb, $forcable] = edt_ajax_inserer($a, $mod, $cours, $j, $crs, [], $forcer, trim((string) ($_POST['sous_groupe'] ?? '')));
    $json(['ok' => $ok, 'problemes' => array_values(array_unique(array_column($pb, 'msg'))), 'forcable' => $forcable]);
}

if (!$ids) $json(['ok' => false, 'msg' => 'Aucune séance.']);
$seances = db_all("SELECT * FROM edt_seance WHERE id_annee=? AND id IN (" . implode(',', $ids) . ")", [$a]);
if (!$seances) $json(['ok' => false, 'msg' => 'Séance introuvable.']);
$s0 = $seances[0];

if ($act === 'supprimer') {
    $n = db_exec("DELETE FROM edt_seance WHERE id_annee=? AND id IN (" . implode(',', $ids) . ")", [$a]);
    if (($_POST['commun'] ?? '') === '1' && $s0['code_commun'])
        $n += db_exec("DELETE FROM edt_seance WHERE id_annee=? AND code_commun=? AND jour=? AND id_creneau IN (" . implode(',', array_map(fn($s) => (int) $s['id_creneau'], $seances)) . ")", [$a, $s0['code_commun'], $s0['jour']]);
    $json(['ok' => true, 'n' => $n]);
}
if ($act === 'verrou') {
    $v = (int) !$s0['verrou'];
    db_exec("UPDATE edt_seance SET verrou=? WHERE id_annee=? AND id IN (" . implode(',', $ids) . ")", [$v, $a]);
    $json(['ok' => true, 'verrou' => $v]);
}
if ($act === 'salle') {
    $sa = (int) ($_POST['id_salle'] ?? 0) ?: null;
    $crs = array_map(fn($s) => (int) $s['id_creneau'], $seances);
    $pb = $sa ? $mod->problemes(['id_classe' => (int) $s0['id_classe'], 'id_mat' => (int) $s0['id_mat'], 'matricule_ens' => $s0['matricule_ens'], 'id_salle' => $sa, 'jour' => (int) $s0['jour'], 'creneaux' => $crs, 'code_commun' => $s0['code_commun']], $ids) : [];
    $pb = array_values(array_filter($pb, fn($p) => $p['type'] === 'salle'));
    if ($pb && !$forcer) $json(['ok' => false, 'problemes' => array_column($pb, 'msg'), 'forcable' => true]);
    db_exec("UPDATE edt_seance SET id_salle=? WHERE id_annee=? AND id IN (" . implode(',', $ids) . ")", [$sa, $a]);
    $json(['ok' => true]);
}
if ($act === 'deplacer') {
    // Contrôle AVANT toute écriture, puis mise à jour sur place (les identifiants ne changent pas).
    // Un cours commun se déplace avec toutes ses classes.
    usort($seances, fn($x, $y) => ($mod->cours[$x['id_creneau']]['rang'] ?? 0) <=> ($mod->cours[$y['id_creneau']]['rang'] ?? 0));
    $j = (int) ($_POST['jour'] ?? 0); $cr = (int) ($_POST['cr'] ?? 0);
    $crs = edt_ajax_creneaux($mod, $cr, count($seances));
    if (!$crs || !in_array($j, $mod->jours, true)) $json(['ok' => false, 'msg' => 'Position impossible.']);
    $anciens = array_map(fn($s) => (int) $s['id_creneau'], $seances);
    $lot = $seances;
    if ($s0['code_commun'])
        $lot = db_all("SELECT * FROM edt_seance WHERE id_annee=? AND code_commun=? AND jour=? AND id_creneau IN (" . implode(',', $anciens) . ")", [$a, $s0['code_commun'], $s0['jour']]);
    $idsLot = array_map(fn($s) => (int) $s['id'], $lot);
    $parClasse = [];
    foreach ($lot as $s) $parClasse[(int) $s['id_classe']] = $s;
    $pb = [];
    foreach ($parClasse as $c => $s)
        foreach ($mod->problemes(['id_classe' => $c, 'id_mat' => (int) $s['id_mat'], 'matricule_ens' => $s['matricule_ens'] !== null ? (int) $s['matricule_ens'] : null,
                                  'id_salle' => $s['id_salle'] ? (int) $s['id_salle'] : null, 'jour' => $j, 'creneaux' => $crs, 'code_commun' => $s['code_commun'] ?: null,
                                  'sous_groupe' => (string) $s['sous_groupe']], $idsLot) as $p)
            $pb[] = edt_ajax_prefixe($mod, ['classes' => $parClasse], $c, $p);
    $msgs = array_values(array_unique(array_column($pb, 'msg')));
    if (array_filter($pb, fn($p) => $p['type'] === 'classe')) $json(['ok' => false, 'problemes' => $msgs, 'forcable' => false]);
    if (array_filter($pb, fn($p) => $p['niveau'] !== 'souhaitee') && !$forcer) $json(['ok' => false, 'problemes' => $msgs, 'forcable' => true]);
    $nouv = array_combine($anciens, $crs);               // ancien créneau => nouveau
    // en deux temps (jour provisoire 0) pour ne jamais heurter la clé unique en glissant d'une période
    db_exec("UPDATE edt_seance SET jour=0 WHERE id_annee=? AND id IN (" . implode(',', $idsLot) . ")", [$a]);
    foreach ($lot as $s) db_exec("UPDATE edt_seance SET jour=?, id_creneau=?, origine='manuel' WHERE id=?", [$j, $nouv[(int) $s['id_creneau']], (int) $s['id']]);
    $json(['ok' => true, 'problemes' => $msgs, 'forcable' => false]);
}
$json(['ok' => false, 'msg' => 'Action inconnue.']);
