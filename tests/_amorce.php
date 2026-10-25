<?php
// tests/_amorce.php — base commune des tests (ligne de commande, poste local
// seulement). Chaque test : require __DIR__ . '/_amorce.php'; puis ok()/egal().
// Les écritures se font dans une transaction annulée (test_transaction()), ou
// dans des bases temporaires zz_test_* supprimées à la fin.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$_SERVER += ['HTTP_HOST' => 'localhost', 'REMOTE_ADDR' => '127.0.0.1', 'REQUEST_METHOD' => 'GET'];
$_SERVER['SCRIPT_NAME'] = '/SIGES/association/tests.php';   // pas de redirection de connexion d'une école
chdir(__DIR__ . '/..');
require_once 'config/config.php';
require_once 'noyau/connexion.php';
require_once 'noyau/fonctions.php';
session_init();

$GLOBALS['_t_ok'] = 0; $GLOBALS['_t_ko'] = 0;
function ok(bool $cond, string $quoi): void {
    $cond ? $GLOBALS['_t_ok']++ : $GLOBALS['_t_ko']++;
    echo ($cond ? '  ✔ ' : '  ✘ ') . $quoi . "\n";
}
function egal($attendu, $obtenu, string $quoi): void {
    ok($attendu === $obtenu, $quoi . ($attendu === $obtenu ? '' : ' — attendu ' . var_export($attendu, true) . ', obtenu ' . var_export($obtenu, true)));
}
/** Test ignoré (donnée locale absente) : ni succès ni échec. */
function passer(string $pourquoi): void { echo "  – ignoré : $pourquoi\n"; exit(0); }

/** Ouvre la base d'une école (code annuaire) avec une session ADMIN de cette école. Retour : ligne etablissement ou null. */
function ecole(string $code, string $role = 'ADMIN'): ?array {
    $e = assoc_one("SELECT * FROM etablissement WHERE code=?", [$code]);
    if (!$e) return null;
    $_SESSION['membre'] = ['id' => 1, 'proprietaire' => 1, 'role' => 'superadmin'];
    basculer_base_ecole($e);
    $u = $e['type_enseignement'] === 'secondaire' ? db_one("SELECT * FROM utilisateur WHERE role=? LIMIT 1", [$role]) : null;
    unset($_SESSION['membre']);
    if ($u) {
        $_SESSION['user_id'] = $u['id'];
        $_SESSION['user'] = ['id' => $u['id'], 'nom' => $u['nom'], 'prenom' => $u['prenom'], 'role' => $role, 'login' => $u['login'],
                             'matricule_ens' => $u['matricule_ens'], 'compte_systeme' => false];
    }
    return $e;
}
/** Exécute $f dans une transaction toujours annulée. */
function test_transaction(callable $f): void {
    global $link;
    mysqli_begin_transaction($link);
    try { $f(); } finally { mysqli_rollback($link); }
}
register_shutdown_function(function () {
    echo "  → {$GLOBALS['_t_ok']} réussi(s), {$GLOBALS['_t_ko']} échec(s)\n";
    if ($GLOBALS['_t_ko'] > 0) exit(1);
});
