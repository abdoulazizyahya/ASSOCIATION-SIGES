<?php
// =====================================================================
//  bd/assoc/migrer_toutes_ecoles.php
//  Applique les migrations de schema manquantes a CHAQUE base ecole de
//  l'annuaire, en suivant jaynitaare_assoc.schema_version_etab.
//
//  Convention (multi-etablissement) : a partir de v51, chaque
//  bd/migration_v<N>.sql doit etre AUTOSUFFISANT (DDL + backfill en SQL
//  pur, idempotent si possible) — plus de logique dans un run_migration_
//  v<N>.php separe, qui ne tournerait que sur une seule base.
//
//  Les ecoles creees par creer_ecole.php partent du schema de reference
//  (schema_ref_ecole.sql, etat v50) : les migrations <= 50 ne sont jamais
//  rejouees.
//
//  Usage :  php bd/assoc/migrer_toutes_ecoles.php [--dry-run]
// =====================================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion_assoc.php';

if (PHP_SAPI !== 'cli') header('Content-Type: text/plain; charset=utf-8');
$dry = in_array('--dry-run', $argv ?? [], true) || isset($_GET['dry']);
if (!annuaire_dispo()) { die("Annuaire absent.\n"); }

// Migrations disponibles sur le disque
$dispo = [];
foreach (glob(__DIR__ . '/../migration_v*.sql') as $f) {
    if (preg_match('/migration_v(\d+)\.sql$/', $f, $m)) $dispo[(int) $m[1]] = $f;
}
ksort($dispo);
$vmax = $dispo ? max(array_keys($dispo)) : 0;

echo "=== Migration multi-ecoles ===\n";
echo $dry ? "MODE DRY-RUN\n" : "MODE APPLICATION\n";
echo "Derniere migration disponible : v$vmax\n\n";

foreach (assoc_all("SELECT id, code, nom, db_name, type_enseignement FROM etablissement WHERE actif=1 ORDER BY id") as $e) {
    $ver = (int) (assoc_val("SELECT version FROM schema_version_etab WHERE id_etablissement=?", [$e['id']]) ?? 0);
    echo str_pad($e['code'], 8) . " {$e['nom']}  (v$ver)";

    if (($e['type_enseignement'] ?? 'primaire') === 'secondaire') {
        // Série de migrations ci-dessus = PRIMAIRE uniquement — pas encore
        // de série secondaire (voir plan « Intégration du secondaire »).
        echo "  — secondaire, pas de série de migrations dédiée pour l'instant\n";
        continue;
    }

    $a_faire = array_filter(array_keys($dispo), fn($v) => $v > $ver);
    if (!$a_faire) { echo "  — a jour\n"; continue; }
    echo "  -> " . implode(', ', array_map(fn($v) => "v$v", $a_faire)) . "\n";
    if ($dry) continue;

    $l = mysqli_connect(DB_HOST, DB_USER, DB_PASS, $e['db_name']);
    mysqli_set_charset($l, 'utf8mb4');
    foreach ($a_faire as $v) {
        $sql = file_get_contents($dispo[$v]);
        try {
            if (mysqli_multi_query($l, $sql)) {
                do { /* consommer */ } while (mysqli_next_result($l));
            }
            // erreur eventuelle du dernier statement
            if (mysqli_errno($l)) throw new RuntimeException(mysqli_error($l));
            assoc_exec("UPDATE schema_version_etab SET version=? WHERE id_etablissement=?", [$v, $e['id']]);
            // Licence (migration v56) : période d'ESSAI de 60 jours pour une
            // école EXISTANTE qui vient de recevoir les tables de licence —
            // sans ça elle serait immédiatement bloquée en écriture (aucune
            // ligne `licence` = 'expiree', fail-closed). Voir aussi le même
            // bootstrap dans connexion_assoc.php::charger_schema_ecole()
            // (nouvelles écoles) et assoc_migrer_ecole() (bouton Migrer).
            if ($v === 56) {
                require_once __DIR__ . '/../lib/licence.php';
                $lic_debut = date('Y-m-d');
                $lic_fin   = date('Y-m-d', strtotime('+60 days'));
                $lic_sig   = licence_signature($lic_fin, null, 'active');
                $stl = mysqli_prepare($l,
                    "INSERT INTO licence (cle_licence, date_debut, date_expiration, statut, derniere_modification_par, date_derniere_modification, signature)
                     VALUES (NULL, ?, ?, 'active', 'systeme:migration_v56', NOW(), ?)");
                mysqli_stmt_bind_param($stl, 'sss', $lic_debut, $lic_fin, $lic_sig);
                mysqli_stmt_execute($stl);
                mysqli_stmt_close($stl);
                echo "   licence : essai 60 jours amorce (jusqu'au $lic_fin)\n";
            }
            echo "   OK v$v\n";
        } catch (\Throwable $ex) {
            echo "   ECHEC v$v : " . $ex->getMessage() . "\n";
            echo "   (arret pour cette ecole — corrigez puis relancez)\n";
            break;
        }
    }
    mysqli_close($l);
}

echo "\n=== Termine. ===\n";
