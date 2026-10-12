<?php
// =====================================================================
//  bd/assoc/migrer_chemins_arborescence.php
//  Réécrit les chemins de pages ENREGISTRÉS EN BASE après la
//  réorganisation de l'arborescence du 12/10/2026 (pages/ → primaire/pages/,
//  secondaire/pages/ → secondaire/commun|general/pages/…) :
//    - portail  : acces_regle.cle ;
//    - écoles   : acces_utilisateur.cle (primaire), notification.lien (secondaire).
//  Facultatif : le code convertit déjà ces anciennes valeurs à la lecture
//  (noyau/chemins_historiques.php::chemin_actuel()). Rejouable sans risque.
//  Sécurité : en HTTP, jeton obligatoire ?token=<BACKUP_TOKEN>.
//  Usage :
//    /bd/assoc/migrer_chemins_arborescence.php?token=XXX         (aperçu)
//    /bd/assoc/migrer_chemins_arborescence.php?token=XXX&go=1    (applique)
//    php bd/assoc/migrer_chemins_arborescence.php [--go]
// =====================================================================
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../noyau/connexion_assoc.php';

$est_cli = (PHP_SAPI === 'cli');
if (!$est_cli) {
    header('Content-Type: text/plain; charset=utf-8');
    $token = (string) ($_GET['token'] ?? '');
    if (!defined('BACKUP_TOKEN') || BACKUP_TOKEN === '' || !hash_equals(BACKUP_TOKEN, $token)) {
        http_response_code(403);
        die("Accès refusé : jeton invalide (BACKUP_TOKEN de config.local.php).\n");
    }
}
$go = $est_cli ? in_array('--go', $argv ?? [], true) : (($_GET['go'] ?? '') === '1');
if (!annuaire_dispo()) die("Annuaire indisponible.\n");
echo "=== Chemins de pages enregistrés en base ===\n" . ($go ? "MODE APPLICATION\n\n" : "MODE APERÇU (ajouter go=1 / --go)\n\n");

// Portail : acces_regle.cle (clé primaire composite : DELETE + INSERT IGNORE
// pour ne jamais heurter une règle déjà enregistrée sous le nouveau chemin).
$n = 0;
foreach (assoc_all("SELECT * FROM acces_regle") as $r) {
    $neuf = chemin_actuel($r['cle']);
    if ($neuf === $r['cle']) continue;
    $n++;
    echo "  portail : {$r['cle']}  →  $neuf\n";
    if ($go) {
        assoc_exec("UPDATE IGNORE acces_regle SET cle=? WHERE id_etablissement=? AND portee=? AND cible=? AND cle=?",
                   [$neuf, $r['id_etablissement'], $r['portee'], $r['cible'], $r['cle']]);
        assoc_exec("DELETE FROM acces_regle WHERE id_etablissement=? AND portee=? AND cible=? AND cle=?",
                   [$r['id_etablissement'], $r['portee'], $r['cible'], $r['cle']]);
    }
}
echo "Portail : $n règle(s)\n\n";

foreach (assoc_all("SELECT id, code, nom, type_enseignement FROM etablissement ORDER BY code") as $e) {
    try {
        avec_ecole((int) $e['id'], function (mysqli $l) use ($e, $go) {
            $t = function (string $table) use ($l): bool {
                return (int) (ecole_one($l, "SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?", [$table])['n'] ?? 0) > 0;
            };
            $n = 0;
            if ($t('acces_utilisateur')) {
                foreach (ecole_all($l, "SELECT id_user, cle FROM acces_utilisateur") as $r) {
                    $neuf = chemin_actuel($r['cle']);
                    if ($neuf === $r['cle']) continue;
                    $n++;
                    if ($go) {
                        ecole_exec($l, "UPDATE IGNORE acces_utilisateur SET cle=? WHERE id_user=? AND cle=?", [$neuf, $r['id_user'], $r['cle']]);
                        ecole_exec($l, "DELETE FROM acces_utilisateur WHERE id_user=? AND cle=?", [$r['id_user'], $r['cle']]);
                    }
                }
            }
            if ($t('notification')) {
                foreach (ecole_all($l, "SELECT id, lien FROM notification WHERE lien LIKE 'pages/%' OR lien LIKE 'secondaire/pages/%'") as $r) {
                    $l0 = (string) $r['lien'];
                    $neuf = chemin_actuel(strncmp($l0, 'pages/', 6) === 0 ? 'secondaire/' . $l0 : $l0);
                    $n++;
                    if ($go) ecole_exec($l, "UPDATE notification SET lien=? WHERE id=?", [$neuf, $r['id']]);
                }
            }
            echo str_pad($e['code'], 8) . " {$e['nom']} : $n valeur(s)\n";
        });
    } catch (\Throwable $ex) {
        echo str_pad($e['code'], 8) . " ✗ " . $ex->getMessage() . "\n";
    }
}
echo "\n=== Terminé. ===\n";
