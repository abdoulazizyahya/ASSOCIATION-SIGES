<?php
// =====================================================================
//  bd/lib/sauvegarde_auto.php — sauvegarde HEBDOMADAIRE automatique de
//  l'annuaire et de toutes les écoles, sans cron (25/10/2026).
// =====================================================================
//  Sur l'hébergement mutualisé, le cron cPanel de bd/assoc/sauvegarder_php.php
//  n'est pas toujours configuré. Filet : quand le propriétaire ou un
//  superadmin ouvre le tableau de bord de l'association et que la dernière
//  sauvegarde automatique date de plus de SAUVEGARDE_AUTO_JOURS jours, la
//  sauvegarde est lancée APRÈS l'envoi de la page (fastcgi_finish_request) :
//  personne n'attend. Un verrou empêche deux exécutions simultanées.
//  Même format et même rétention que le cron : bd/sauvegardes/<AAAAMMJJ_HHMMSS>/.
// =====================================================================

const SAUVEGARDE_AUTO_JOURS = 7;

function sauvegarde_auto_dossier(): string { return __DIR__ . '/../sauvegardes'; }

/** Date (timestamp) de la dernière sauvegarde automatique ou du cron, ou null. */
function sauvegarde_auto_derniere(): ?int {
    $d = glob(sauvegarde_auto_dossier() . '/[0-9]*_[0-9]*', GLOB_ONLYDIR) ?: [];
    if (!$d) return null;
    rsort($d);
    $dt = DateTimeImmutable::createFromFormat('Ymd_His', basename($d[0]));
    return $dt ? $dt->getTimestamp() : (int) @filemtime($d[0]);
}

function sauvegarde_auto_due(): bool {
    $t = sauvegarde_auto_derniere();
    return $t === null || $t < time() - SAUVEGARDE_AUTO_JOURS * 86400;
}

/** Lance la sauvegarde complète maintenant (même script que le cron). Retour : texte du rapport. */
function sauvegarde_auto_executer(): string {
    $verrou = sauvegarde_auto_dossier() . '/.sauvegarde_auto.lock';
    if (!is_dir(sauvegarde_auto_dossier())) @mkdir(sauvegarde_auto_dossier(), 0775, true);
    // Filet si le .htaccess racine (bd/ interdit) n'est pas en place : jamais de dump téléchargeable
    if (!is_file(sauvegarde_auto_dossier() . '/.htaccess')) @file_put_contents(sauvegarde_auto_dossier() . '/.htaccess', "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n");
    $h = @fopen($verrou, 'c');
    if (!$h || !flock($h, LOCK_EX | LOCK_NB)) return "Une sauvegarde est déjà en cours.\n";
    @set_time_limit(0);
    @ignore_user_abort(true);
    if (!defined('SAUVEGARDE_AUTO_INTERNE')) define('SAUVEGARDE_AUTO_INTERNE', true);
    ob_start();
    try { (function () { require __DIR__ . '/../assoc/sauvegarder_php.php'; })(); }
    catch (\Throwable $e) { echo "Erreur : " . $e->getMessage() . "\n"; }
    $rapport = (string) ob_get_clean();
    flock($h, LOCK_UN); fclose($h);
    return $rapport;
}

/** À appeler en fin de page : si la sauvegarde est due, la lance après l'envoi de la réponse. */
function sauvegarde_auto_planifier(): void {
    if (!sauvegarde_auto_due()) return;
    register_shutdown_function(function () {
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();   // la page est déjà affichée
        else return;   // sans PHP-FPM, on ne fait pas attendre l'utilisateur : bouton « Sauvegarder maintenant »
        $r = sauvegarde_auto_executer();
        if (function_exists('journaliser_action')) {
            try { journaliser_action('sauvegarde_auto', null, preg_match('/=== (\d+ OK, \d+ KO)/', $r, $m) ? $m[1] : trim($r)); } catch (\Throwable $e) {}
        }
    });
}
