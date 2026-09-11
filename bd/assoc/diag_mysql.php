<?php
// =====================================================================
//  bd/assoc/diag_mysql.php
//  Diagnostic de la connexion MySQL *telle que l'application la voit*
//  (identifiants de config.local.php). Répond à : « mes bases existent-
//  elles et sont-elles utilisables par SIGES ? » — indépendamment de ce
//  qu'affiche le phpMyAdmin de l'hébergeur (qui se connecte, lui, avec un
//  autre utilisateur interne).
//
//  HTTP : /bd/assoc/diag_mysql.php?token=<BACKUP_TOKEN>
//  CLI  : php bd/assoc/diag_mysql.php
//  À SUPPRIMER après usage.
// =====================================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion_assoc.php';

mysqli_report(MYSQLI_REPORT_OFF);   // on gère les erreurs à la main

$est_cli = (PHP_SAPI === 'cli');
if (!$est_cli) header('Content-Type: text/plain; charset=utf-8');

if (!$est_cli) {
    $token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
    if (!defined('BACKUP_TOKEN') || BACKUP_TOKEN === '' || !hash_equals(BACKUP_TOKEN, $token)) {
        http_response_code(403);
        die("Accès refusé : jeton invalide (BACKUP_TOKEN de config.local.php).\n");
    }
}

echo "=== Diagnostic MySQL (vue de l'application) ===\n";
echo "DB_HOST = " . DB_HOST . "\n";
echo "DB_USER = " . DB_USER . "\n";
echo "DB_PASS = " . (DB_PASS === '' ? "(VIDE !)" : "(défini, " . strlen(DB_PASS) . " car.)") . "\n";
echo "DB_NAME_ASSOC = " . DB_NAME_ASSOC . "\n";
echo str_repeat('-', 60) . "\n";

// 1) Connexion serveur (sans base).
$srv = @mysqli_connect(DB_HOST, DB_USER, DB_PASS);
if (!$srv) {
    echo "✗ Connexion serveur IMPOSSIBLE : (" . mysqli_connect_errno() . ") "
       . mysqli_connect_error() . "\n\n";
    echo "  → Si « using password: NO » : config.local.php absent ou DB_PASS vide.\n";
    echo "  → Si « Access denied ... (using password: YES) » : mauvais mot de passe,\n";
    echo "     ou l'utilisateur " . DB_USER . " n'existe pas / pas de droits.\n";
    exit(1);
}
mysqli_set_charset($srv, 'utf8mb4');
echo "✔ Connexion serveur OK\n";

$row = mysqli_fetch_row(mysqli_query($srv, "SELECT CURRENT_USER(), USER(), VERSION()"));
echo "  CURRENT_USER() = {$row[0]}\n";
echo "  USER()         = {$row[1]}\n";
echo "  VERSION()      = {$row[2]}\n";

// 2) Bases visibles par cet utilisateur.
echo "\nSHOW DATABASES :\n";
$vues = [];
if ($r = mysqli_query($srv, "SHOW DATABASES")) {
    while ($x = mysqli_fetch_row($r)) { $vues[] = $x[0]; echo "  · {$x[0]}\n"; }
}
echo "  (" . count($vues) . " base(s))\n";

// 3) Droits.
echo "\nSHOW GRANTS :\n";
if ($r = mysqli_query($srv, "SHOW GRANTS")) {
    while ($x = mysqli_fetch_row($r)) echo "  {$x[0]}\n";
}

// 4) Test ciblé des bases attendues (préfixe + suffixes connus).
$p = strrpos(DB_NAME_ASSOC, '_');
$prefixe = $p !== false ? substr(DB_NAME_ASSOC, 0, $p + 1) : '';
$attendues = [DB_NAME_ASSOC];
foreach (['ec1', 'efagm', 'exg', 'ir01', 'jak', 'mhm1'] as $s) $attendues[] = $prefixe . $s;

echo "\nTest des bases attendues (connexion + nb tables) :\n";
foreach ($attendues as $db) {
    $l = @mysqli_connect(DB_HOST, DB_USER, DB_PASS, $db);
    if (!$l) {
        echo sprintf("  ✗ %-28s : %s\n", $db, mysqli_connect_error());
        continue;
    }
    $n = 0;
    if ($rr = mysqli_query($l, "SHOW TABLES")) $n = mysqli_num_rows($rr);
    $visible = in_array($db, $vues, true) ? "" : "  (absente de SHOW DATABASES mais joignable)";
    echo sprintf("  ✔ %-28s : %d table(s)%s\n", $db, $n, $visible);
    mysqli_close($l);
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "Lecture :\n";
echo "  • Si les bases 'ec1'…'mhm1' répondent ✔ ici → SIGES fonctionnera,\n";
echo "    même si le phpMyAdmin de Camoo ne les montre pas (autre utilisateur).\n";
echo "  • Si elles répondent ✗ 'Unknown database' → elles ne sont pas créées :\n";
echo "    refais-les dans le panneau Camoo, rattache " . DB_USER . " (tous droits).\n";
echo "  • Si ✗ 'access denied' → " . DB_USER . " existe mais n'est pas rattaché à la base.\n";
