<?php
// =====================================================================
//  bd/assoc/backfill_niu.php
//  Peuple le registre central jaynitaare_assoc.eleve_niu à partir des
//  NIU déjà présents dans la table `eleve` de CHAQUE école de l'annuaire.
//
//  - statut « actif », id_etab_courant = école où l'élève est trouvé
//  - signale (sans fusionner) les NIU présents dans PLUSIEURS écoles et
//    les élèves d'identité proche (nom+prénom+date) portant des NIU
//    différents.
//
//  Idempotent. Par défaut : dry-run. --appliquer pour écrire.
//  Usage :  php bd/assoc/backfill_niu.php [--appliquer]
// =====================================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion_assoc.php';

if (PHP_SAPI !== 'cli') header('Content-Type: text/plain; charset=utf-8');
$appliquer = in_array('--appliquer', $argv ?? [], true) || isset($_GET['appliquer']);

if (!annuaire_dispo()) { die("Annuaire absent — lancez d'abord bd/assoc/installer.php\n"); }

echo "=== Backfill registre NIU central ===\n";
echo $appliquer ? "MODE APPLICATION\n\n" : "MODE APERCU (dry-run) — --appliquer pour ecrire\n\n";

$ecoles = assoc_all("SELECT id, code, nom, db_name FROM etablissement ORDER BY id");
$vu = [];          // niu => [écoles]
$identites = [];   // "nom|prenom|date" => [niu]
$ajouts = 0;

foreach ($ecoles as $e) {
    $lignes = avec_ecole((int) $e['id'], function ($l) {
        $r = mysqli_query($l,
            "SELECT niu, Nom_elv, Prenom_elv, Sexe_elv, Date_naiss_elv, Lieu_naiss_elv
             FROM eleve WHERE niu IS NOT NULL AND niu <> ''");
        return mysqli_fetch_all($r, MYSQLI_ASSOC);
    });
    echo str_pad($e['code'], 8) . " {$e['nom']} : " . count($lignes) . " NIU\n";

    foreach ($lignes as $r) {
        $niu = trim($r['niu']);
        $vu[$niu][] = $e['code'];
        $cle = mb_strtolower(trim($r['Nom_elv'] . '|' . $r['Prenom_elv'] . '|' . $r['Date_naiss_elv']));
        $identites[$cle][$niu] = true;

        $existe = assoc_val("SELECT COUNT(*) FROM eleve_niu WHERE niu=?", [$niu]);
        if (!$existe) {
            $ajouts++;
            if ($appliquer) {
                assoc_exec(
                    "INSERT INTO eleve_niu (niu, nom, prenom, date_naissance, sexe, lieu_naissance,
                            id_etab_origine, id_etab_courant, statut, cree_par)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'actif', 'backfill')",
                    [$niu, $r['Nom_elv'], $r['Prenom_elv'], $r['Date_naiss_elv'], $r['Sexe_elv'],
                     $r['Lieu_naiss_elv'], $e['id'], $e['id']]
                );
            }
        } elseif ($appliquer) {
            assoc_exec("UPDATE eleve_niu SET id_etab_courant=? WHERE niu=? AND id_etab_courant IS NULL",
                [$e['id'], $niu]);
        }
    }
}

echo "\n" . ($appliquer ? "Insérés : $ajouts\n" : "À insérer : $ajouts\n");

echo "\n--- NIU présents dans PLUSIEURS écoles ---\n";
$n = 0;
foreach ($vu as $niu => $codes) {
    if (count(array_unique($codes)) > 1) { echo "  $niu : " . implode(', ', $codes) . "\n"; $n++; }
}
echo $n ? "  ($n cas — à arbitrer manuellement)\n" : "  aucun\n";

echo "\n--- Identités proches avec NIU différents ---\n";
$n = 0;
foreach ($identites as $cle => $nius) {
    if (count($nius) > 1) { echo "  [$cle] : " . implode(', ', array_keys($nius)) . "\n"; $n++; }
}
echo $n ? "  ($n cas — doublons potentiels)\n" : "  aucun\n";

echo "\n=== Terminé. ===\n";
