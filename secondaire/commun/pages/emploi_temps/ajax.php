<?php
// secondaire/commun/pages/emploi_temps/ajax.php — actions de la saisie manuelle.
//   GET  action=grille&classe=      → fragment HTML de la grille éditable
//   GET  action=dispo&service=&duree= (ou mat/ens/classe) → état de chaque case (ok / souhaitee / conflit)
//   POST action=placer | deplacer | supprimer | verrou | salle | enseignant | decommun (csrf)
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
require_once __DIR__ . '/_edt_lib.php';
exiger_role(EDT_ROLES_GESTION);

$a = edt_annee_id();
$act = $_REQUEST['action'] ?? '';
$json = function (array $d): void { $GLOBALS['edt_reponse'] = $d; header('Content-Type: application/json; charset=utf-8'); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; };

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
/**
 * Les seuls problèmes durs sont « enseignant déjà dans une classe du même niveau,
 * même matière » : un cours commun est possible. Retour : ids des séances de ces
 * autres classes (vide sinon).
 */
function edt_ajax_commun_seul(array $pb): array {
    $ids = [];
    foreach ($pb as $p) {
        if ($p['niveau'] === 'souhaitee') continue;
        if ($p['type'] !== 'enseignant' || empty($p['commun_possible'])) return [];
        $ids[] = (int) $p['id_seance'];
    }
    return array_values(array_unique($ids));
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
        $msg = array_values(array_unique(array_column($pb, 'msg')));
        if ($etat === 'conflit' && edt_ajax_commun_seul($pb)) { $etat = 'commun'; $msg[] = 'Même cours, même niveau : cliquez pour en faire un cours commun.'; }
        $cases["$j|$cr"] = ['etat' => $etat, 'msg' => $msg];
    }
    $json(['ok' => true, 'cases' => $cases]);
}

// ── Écritures ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !edt_peut_ecrire()) $json(['ok' => false, 'msg' => 'Action non autorisée.']);
csrf_verifier();
$mod = new EdtModele($a);
// Annuler : une action réussie empile la photo des jours touchés (edt_annuler_* dans _edt_lib.php)
register_shutdown_function(function () use ($a) {
    if (!empty($GLOBALS['edt_snap']) && !empty($GLOBALS['edt_reponse']['ok'])) edt_annuler_empiler($a, $GLOBALS['edt_snap']);
});
if ($act === 'annuler') {
    $lib = edt_annuler_derniere($a);
    $json($lib === null ? ['ok' => false, 'msg' => 'Rien à annuler.'] : ['ok' => true, 'msg' => "Annulé : $lib"]);
}
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
    foreach ($cours['classes'] as $c => $sid) foreach ($crs as $cr) {
        if ($cours['commun'] !== null && db_val("SELECT 1 FROM edt_seance WHERE id_annee=? AND id_classe=? AND jour=? AND id_creneau=? AND code_commun=?", [$a, $c, $j, $cr, $cours['commun']])) continue;
        db_exec("INSERT INTO edt_seance (id_annee,id_classe,sous_groupe,jour,id_creneau,id_service,id_mat,matricule_ens,id_salle,code_commun,verrou,origine) VALUES (?,?,?,?,?,?,?,?,?,?,?,'manuel')",
                [$a, $c, $sg, $j, $cr, $sid, $cours['id_mat'], $cours['ens'], $cours['salle'], $cours['commun'], $verrou]);
    }
    return [true, array_values($pb), false];
}

if ($act === 'placer') {
    $cours = edt_ajax_cours($a);
    // Une case = une heure (25/10/2026) : deux heures consécutives du même cours
    // sont fusionnées à l'affichage (blocs). Le déplacement garde la durée du bloc.
    $j = (int) ($_POST['jour'] ?? 0); $cr = (int) ($_POST['cr'] ?? 0);
    if (!$cours || !in_array($j, $mod->jours, true) || !isset($mod->cours[$cr])) $json(['ok' => false, 'msg' => 'Données incomplètes.']);
    $crs = [$cr];
    $GLOBALS['edt_snap'] = edt_annuler_capturer($a, [$j], 'placement ' . ($mod->mat[$cours['id_mat']] ?? '') . ' ' . (EDT_JOURS[$j] ?? '') . ' ' . ($mod->cours[$cr]['libelle'] ?? ''));
    $sg = trim((string) ($_POST['sous_groupe'] ?? ''));
    $c0 = (int) array_key_first($cours['classes']);
    // Nouveau cours (matière + enseignant choisis dans la page) : rattaché au service existant s'il y en a un
    $libre = $cours['classes'][$c0] === null;
    if ($libre) $cours['classes'][$c0] = (int) db_val("SELECT id FROM edt_service WHERE id_annee=? AND id_classe=? AND id_mat=? AND matricule_ens<=>?", [$a, $c0, $cours['id_mat'], $cours['ens']]) ?: null;

    // Enseignant déjà dans une classe du même niveau, même matière : on demande s'il s'agit d'un cours commun
    $pb = [];
    foreach ($cours['classes'] as $c => $_s)
        foreach ($mod->problemes(['id_classe' => $c, 'id_mat' => $cours['id_mat'], 'matricule_ens' => $cours['ens'], 'id_salle' => $cours['salle'], 'jour' => $j, 'creneaux' => $crs, 'code_commun' => $cours['commun'], 'sous_groupe' => $sg]) as $p) $pb[] = $p;
    $cand = array_filter(edt_ajax_commun_seul($pb), fn($id) => isset($mod->seances[$id]));
    if ($cand) {
        $autres = array_values(array_unique(array_map(fn($id) => $mod->classes[$mod->seances[$id]['id_classe']]['designation'] ?? '?', $cand)));
        if (($_POST['commun'] ?? '') !== '1')
            $json(['ok' => false, 'commun' => true, 'msg' => ($mod->ens[$cours['ens']] ?? "L'enseignant") . ' est déjà occupé à cette heure en ' . implode(', ', $autres)
                . ' pour le même cours (' . ($mod->mat[$cours['id_mat']] ?? '') . ', même niveau). Ces classes sont-elles communes ?']);
        $code = $cours['commun'];
        foreach ($cand as $id) $code = $code ?? $mod->seances[$id]['code_commun'];
        $code = $code ?? ('C' . substr(md5(uniqid('', true)), 0, 8));
        db_exec("UPDATE edt_seance SET code_commun=? WHERE id_annee=? AND id IN (" . implode(',', array_map('intval', $cand)) . ")", [$code, $a]);
        $cours['commun'] = $code;
        $mod = new EdtModele($a);
    }
    [$ok, $pb, $forcable] = edt_ajax_inserer($a, $mod, $cours, $j, $crs, [], $forcer, $sg);
    $sidOut = $cours['classes'][$c0];
    if ($ok && $libre) {
        if (!$cours['classes'][$c0]) {
            db_exec("INSERT INTO edt_service (id_annee,id_classe,id_mat,matricule_ens,heures,duree_min,duree_max) VALUES (?,?,?,?,1,1,2)", [$a, $c0, $cours['id_mat'], $cours['ens']]);
            $sid = $sidOut = (int) db_val("SELECT LAST_INSERT_ID()");
            db_exec("UPDATE edt_seance SET id_service=? WHERE id_annee=? AND id_classe=? AND jour=? AND id_creneau=? AND id_mat=? AND id_service IS NULL", [$sid, $a, $c0, $j, $cr, $cours['id_mat']]);
        }
    }
    // Le volume horaire du cours suit ce qui est placé dans la grille (jamais diminué ici)
    if ($ok && $sidOut) db_exec("UPDATE edt_service s SET s.heures=GREATEST(s.heures, (SELECT COUNT(*) FROM edt_seance q WHERE q.id_service=s.id)) WHERE s.id=?", [$sidOut]);
    $json(['ok' => $ok, 'problemes' => array_values(array_unique(array_column($pb, 'msg'))), 'forcable' => $forcable, 'service' => $sidOut]);
}

if (!$ids) $json(['ok' => false, 'msg' => 'Aucune séance.']);
$seances = db_all("SELECT * FROM edt_seance WHERE id_annee=? AND id IN (" . implode(',', $ids) . ")", [$a]);
if (!$seances) $json(['ok' => false, 'msg' => 'Séance introuvable.']);
$s0 = $seances[0];
$GLOBALS['edt_snap'] = edt_annuler_capturer($a, array_merge(array_column($seances, 'jour'), $act === 'deplacer' ? [(int) ($_POST['jour'] ?? 0)] : []),
    (['supprimer' => 'suppression', 'deplacer' => 'déplacement', 'enseignant' => 'changement d\'enseignant', 'salle' => 'changement de salle', 'verrou' => 'verrou', 'decommun' => 'retrait du cours commun'][$act] ?? $act)
    . ' ' . ($mod->mat[(int) $s0['id_mat']] ?? '') . ' ' . (EDT_JOURS[(int) $s0['jour']] ?? ''));

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
if ($act === 'enseignant') {
    // Changer ou retirer (0) l'enseignant d'une séance ; un cours commun change dans toutes ses classes.
    $e = (int) ($_POST['matricule_ens'] ?? 0) ?: null;
    $crs = array_map(fn($s) => (int) $s['id_creneau'], $seances);
    $lot = $s0['code_commun']
        ? db_all("SELECT * FROM edt_seance WHERE id_annee=? AND code_commun=? AND jour=? AND id_creneau IN (" . implode(',', $crs) . ")", [$a, $s0['code_commun'], $s0['jour']])
        : $seances;
    $idsLot = array_map(fn($s) => (int) $s['id'], $lot);
    if ($e) {
        $pb = array_values(array_filter($mod->problemes(['id_classe' => (int) $s0['id_classe'], 'id_mat' => (int) $s0['id_mat'], 'matricule_ens' => $e, 'id_salle' => null,
                                                         'jour' => (int) $s0['jour'], 'creneaux' => $crs, 'code_commun' => $s0['code_commun']], $idsLot),
                                       fn($p) => $p['type'] === 'enseignant' || $p['niveau'] === 'obligatoire'));
        if ($pb && !$forcer) $json(['ok' => false, 'problemes' => array_values(array_unique(array_column($pb, 'msg'))), 'forcable' => true]);
    }
    foreach ($lot as $s)
        db_exec("UPDATE edt_seance SET matricule_ens=?, origine='manuel', id_service=COALESCE((SELECT id FROM edt_service WHERE id_annee=? AND id_classe=? AND id_mat=? AND matricule_ens<=>? LIMIT 1), id_service) WHERE id=?",
                [$e, $a, (int) $s['id_classe'], (int) $s['id_mat'], $e, (int) $s['id']]);
    $json(['ok' => true]);
}
if ($act === 'decommun') {
    // Cette classe suit désormais seule ce cours (les autres classes gardent leur séance commune).
    db_exec("UPDATE edt_seance SET code_commun=NULL WHERE id_annee=? AND id IN (" . implode(',', $ids) . ")", [$a]);
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
