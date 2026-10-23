<?php
// =====================================================================
//  bd/assoc/comptes_par_defaut.php
//  Rattrapage (demande du 13/10/2026) : donne à CHAQUE école de l'annuaire
//  les comptes par défaut d'une école neuve (comptes_defaut_definitions() :
//  mot de passe = identifiant) et le compte PROPRIÉTAIRE.
//
//  - Un rôle déjà tenu par un compte actif n'est pas recréé.
//  - Identifiant déjà pris : suffixe numérique (censeur2, …).
//  - Compte propriétaire déjà présent (même identifiant) : marqué compte
//    système (masqué aux autres comptes) SANS toucher à son mot de passe.
//
//  Idempotent. Aperçu par défaut, --go pour écrire.
//  Usage :  php bd/assoc/comptes_par_defaut.php [--go]
//  En HTTP (mutualisé sans shell), jeton obligatoire :
//    /bd/assoc/comptes_par_defaut.php?token=<BACKUP_TOKEN>         (aperçu)
//    /bd/assoc/comptes_par_defaut.php?token=<BACKUP_TOKEN>&go=1    (applique)
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
$appliquer = $est_cli ? in_array('--go', $argv ?? [], true) : (($_GET['go'] ?? '') === '1');
if (!annuaire_dispo()) { die("Annuaire absent.\n"); }

echo "=== Comptes par défaut + compte propriétaire ===\n";
echo $appliquer ? "MODE APPLICATION\n\n" : "MODE APERÇU — --go (ou &go=1) pour écrire\n\n";

foreach (assoc_all("SELECT id, code, nom, type_enseignement FROM etablissement ORDER BY id") as $e) {
    $type = $e['type_enseignement'] === 'secondaire' ? 'secondaire' : 'primaire';
    echo str_pad($e['code'], 8) . " {$e['nom']} ($type)\n";
    try {
        avec_ecole((int) $e['id'], function (mysqli $l) use ($type, $appliquer) {
            $sec = $type === 'secondaire';
            if ($sec && !ecole_one($l, "SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE()
                                        AND table_name='utilisateur' AND column_name='compte_systeme'")) {
                echo "    ! migration v16 non appliquée — école ignorée\n";
                return;
            }
            // statut public / privé (primaire : migration v62, privé si la colonne manque) — décide du compte fondateur
            $a_statut = ecole_one($l, "SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='etablissement' AND column_name='statut'");
            $statut = $a_statut ? ((ecole_one($l, "SELECT statut FROM etablissement LIMIT 1")['statut'] ?? '') === 'prive' ? 'prive' : 'public') : ($sec ? 'public' : 'prive');

            // Rôles déjà tenus par un compte actif (hors compte propriétaire).
            $tenus = array_column(ecole_all($l, $sec
                ? "SELECT DISTINCT role FROM utilisateur WHERE actif=1 AND compte_systeme=0"
                : "SELECT DISTINCT e.id_fonction AS role FROM user u JOIN enseignant e ON e.matricule_ens=u.matricule_ens
                   WHERE COALESCE(e.statut_ens,'actif') NOT IN ('systeme','desactive','inactif')"), 'role');

            foreach (comptes_defaut_definitions($type, $statut) as $c) {
                if (in_array($c['role'], $tenus, true)) continue;
                $login = $c['login']; $n = 2;
                while (compte_login_existe($l, $type, $login)) $login = $c['login'] . $n++;
                echo "    + {$c['role']} : $login (mot de passe = identifiant)\n";
                if ($appliquer) creer_compte_ecole($l, $type, $c['role'], $login, password_hash($login, PASSWORD_DEFAULT), $c['nom'], $c['prenom']);
            }

            foreach (array_keys(comptes_proprietaires()) as $plogin) {
            if (!compte_login_existe($l, $type, $plogin)) {
                echo "    + compte propriétaire $plogin\n";
                if ($appliquer) creer_compte_proprietaire_ecole($l, $type);
            } elseif ($sec) {
                $u = ecole_one($l, "SELECT id, role, compte_systeme FROM utilisateur WHERE login=?", [$plogin]);
                if (!(int) $u['compte_systeme'] || $u['role'] !== 'ADMIN') {
                    echo "    ~ compte propriétaire existant (#{$u['id']}, {$u['role']}) : passé en compte système ADMIN, mot de passe inchangé\n";
                    if ($appliquer) ecole_exec($l, "UPDATE utilisateur SET compte_systeme=1, role='ADMIN', actif=1 WHERE id=?", [$u['id']]);
                }
            } else {
                $u = ecole_one($l, "SELECT e.matricule_ens, e.statut_ens FROM user u JOIN enseignant e ON e.matricule_ens=u.matricule_ens
                                    WHERE u.login_user=?", [$plogin]);
                if ($u && $u['statut_ens'] !== 'systeme') {
                    echo "    ~ compte propriétaire existant : fiche passée au statut système, mot de passe inchangé\n";
                    if ($appliquer) ecole_exec($l, "UPDATE enseignant SET statut_ens='systeme' WHERE matricule_ens=?", [$u['matricule_ens']]);
                }
            }
            }
        });
    } catch (Throwable $t) {
        echo "    ! " . $t->getMessage() . "\n";
    }
}
echo "\nTerminé.\n";
