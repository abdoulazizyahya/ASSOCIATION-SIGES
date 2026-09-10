<?php
// =====================================================================
//  bd/assoc/desactiver_2fa.php
//  Désactive la double authentification (TOTP) de l'espace association :
//  remet membre.totp_actif = 0 et membre.totp_secret = NULL pour tous les
//  membres (ou un seul via ?login=). Un membre pourra la réactiver
//  volontairement depuis association/securite.php.
//
//  ⚠ Le flag define('ASSOC_2FA_SUPERADMIN_OBLIGATOIRE', false) de
//    config.local.php empêche seulement d'IMPOSER la 2FA à un superadmin ;
//    il n'éteint PAS une 2FA déjà active — c'est le rôle de ce script.
//
//  Sécurité : en HTTP, jeton obligatoire ?token=<BACKUP_TOKEN>
//  (défini dans config.local.php). En CLI, aucun jeton.
//
//  Usage HTTP :
//    /bd/assoc/desactiver_2fa.php?token=XXX            (aperçu)
//    /bd/assoc/desactiver_2fa.php?token=XXX&go=1       (applique)
//    ...&login=admin                                   (un seul compte)
//  Usage CLI :
//    php bd/assoc/desactiver_2fa.php [--go] [--login=admin]
//
//  À SUPPRIMER du serveur après usage.
// =====================================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion_assoc.php';

mysqli_report(MYSQLI_REPORT_OFF);
$est_cli = (PHP_SAPI === 'cli');
if (!$est_cli) header('Content-Type: text/plain; charset=utf-8');

$args = array_slice($argv ?? [], 1);
$opt = function (string $k, $def = null) use ($args, $est_cli) {
    if ($est_cli) {
        foreach ($args as $a) {
            if ($a === "--$k") return true;
            if (strpos($a, "--$k=") === 0) return substr($a, strlen($k) + 3);
        }
        return $def;
    }
    return $_GET[$k] ?? $_POST[$k] ?? $def;
};

// ── Contrôle d'accès (HTTP) ────────────────────────────────────────
if (!$est_cli) {
    $token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
    if (!defined('BACKUP_TOKEN') || BACKUP_TOKEN === '' || !hash_equals(BACKUP_TOKEN, $token)) {
        http_response_code(403);
        die("Acces refuse : jeton invalide (BACKUP_TOKEN de config.local.php).\n");
    }
}

if (!annuaire_dispo()) {
    die("Annuaire indisponible — rien a faire (la 2FA n'existe que cote association).\n");
}

$go    = (bool) $opt('go');
$login = trim((string) ($opt('login') ?: ''));

echo "=== Desactivation de la double authentification (espace association) ===\n";
echo "Base annuaire : " . DB_NAME_ASSOC . "\n";
echo $login !== '' ? "Cible : compte « $login »\n" : "Cible : TOUS les membres\n";
echo $go ? "Mode : APPLICATION\n" : "Mode : APERCU (ajouter &go=1 pour appliquer)\n";
echo str_repeat('-', 60) . "\n";

$where  = "totp_actif = 1 OR totp_secret IS NOT NULL";
$params = [];
if ($login !== '') { $where = "login = ? AND ($where)"; $params[] = $login; }

try {
    $concernes = assoc_all("SELECT id, login, nom, prenom, totp_actif FROM membre WHERE $where ORDER BY login", $params);
} catch (\Throwable $e) {
    die("La colonne membre.totp_actif n'existe pas : la 2FA n'est pas installee, rien a faire.\n");
}

if (!$concernes) {
    echo "Aucun compte n'a la double authentification active. Rien a faire.\n";
    exit;
}

foreach ($concernes as $c) {
    echo sprintf("  %-20s %s %s  (totp_actif=%d)\n",
        $c['login'], $c['prenom'] ?? '', $c['nom'] ?? '', (int) $c['totp_actif']);
}
echo str_repeat('-', 60) . "\n";

if (!$go) {
    echo count($concernes) . " compte(s) seront reinitialises. Ajoute &go=1 (ou --go) pour appliquer.\n";
    exit;
}

$maj = "totp_actif = 0, totp_secret = NULL";
if ($login !== '') {
    assoc_exec("UPDATE membre SET $maj WHERE login = ?", [$login]);
} else {
    assoc_exec("UPDATE membre SET $maj WHERE totp_actif = 1 OR totp_secret IS NOT NULL");
}

// Purge des tentatives echouees eventuelles (anti-blocage au login).
try { assoc_exec("DELETE FROM login_echec"); } catch (\Throwable $e) { /* table absente : sans effet */ }

echo "OK — double authentification desactivee pour " . count($concernes) . " compte(s).\n";
echo "Chaque membre peut la reactiver volontairement depuis association/securite.php.\n";
echo "\nPense a SUPPRIMER ce fichier du serveur.\n";
