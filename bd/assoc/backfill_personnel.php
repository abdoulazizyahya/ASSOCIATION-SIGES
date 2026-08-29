<?php
// =====================================================================
//  bd/assoc/backfill_personnel.php
//  Peuple jaynitaare_assoc.personnel + personnel_affectation à partir de
//  la table `enseignant` (et `user`) de CHAQUE école de l'annuaire.
//
//  Cle `personnel.matricule` :
//    - enseignant.mat_ens s'il est renseigne (matricule officiel, suppose
//      stable entre ecoles) ;
//    - sinon « <code_ecole>-<matricule_ens_local> » (stable par poste).
//
//  Idempotent. Dry-run par defaut. --appliquer pour ecrire.
//  Usage :  php bd/assoc/backfill_personnel.php [--appliquer]
// =====================================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion_assoc.php';

if (PHP_SAPI !== 'cli') header('Content-Type: text/plain; charset=utf-8');
$appliquer = in_array('--appliquer', $argv ?? [], true) || isset($_GET['appliquer']);
if (!annuaire_dispo()) { die("Annuaire absent.\n"); }

echo "=== Backfill personnel ===\n";
echo $appliquer ? "MODE APPLICATION\n\n" : "MODE APERCU — --appliquer pour ecrire\n\n";

$ajout_p = 0; $ajout_a = 0;

foreach (assoc_all("SELECT id, code, nom FROM etablissement ORDER BY id") as $e) {
    $agents = avec_ecole((int) $e['id'], function ($l) {
        $r = mysqli_query($l,
            "SELECT en.matricule_ens, en.mat_ens, en.nom_ens, en.prenom_ens, en.sexe_ens,
                    en.date_naiss_ens, en.tel_ens, en.mail_ens, en.id_fonction, en.statut_ens,
                    u.id_user
             FROM enseignant en
             LEFT JOIN user u ON u.matricule_ens = en.matricule_ens");
        return mysqli_fetch_all($r, MYSQLI_ASSOC);
    });
    echo str_pad($e['code'], 8) . " {$e['nom']} : " . count($agents) . " agent(s)\n";

    foreach ($agents as $a) {
        $matricule = trim((string) $a['mat_ens']) !== ''
            ? trim($a['mat_ens'])
            : $e['code'] . '-' . $a['matricule_ens'];

        if (!assoc_val("SELECT COUNT(*) FROM personnel WHERE matricule=?", [$matricule])) {
            $ajout_p++;
            if ($appliquer) {
                assoc_exec(
                    "INSERT INTO personnel (matricule, nom, prenom, date_naissance, sexe, tel, email, statut)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                    [$matricule, $a['nom_ens'], $a['prenom_ens'], $a['date_naiss_ens'], $a['sexe_ens'],
                     $a['tel_ens'], $a['mail_ens'], $a['statut_ens'] === 'inactif' ? 'inactif' : 'actif']
                );
            }
        }

        $existe_aff = assoc_val(
            "SELECT COUNT(*) FROM personnel_affectation WHERE matricule=? AND id_etablissement=?",
            [$matricule, $e['id']]
        );
        if (!$existe_aff) {
            $ajout_a++;
            if ($appliquer) {
                assoc_exec(
                    "INSERT INTO personnel_affectation
                        (matricule, id_etablissement, fonction, matricule_ens_local, id_user_local, date_debut, actif)
                     VALUES (?, ?, ?, ?, ?, CURDATE(), ?)",
                    [$matricule, $e['id'], $a['id_fonction'] ?: 'ENSEIGNANT',
                     $a['matricule_ens'], $a['id_user'], $a['statut_ens'] === 'inactif' ? 0 : 1]
                );
            }
        }
    }
}

echo "\n" . ($appliquer ? "Personnel inseres : $ajout_p — affectations : $ajout_a\n"
                         : "Personnel a inserer : $ajout_p — affectations : $ajout_a\n");
echo "\n=== Termine. ===\n";
