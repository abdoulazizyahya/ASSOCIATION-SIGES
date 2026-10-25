<?php
// Les deux schémas de référence sont à jour de toutes les migrations, et
// rejouer toute la série dessus ne s'arrête plus (« déjà présent » sauté).
require __DIR__ . '/_amorce.php';
require_once 'bd/lib/recalage_versions.php';

foreach (['primaire' => 'bd/assoc/schema_ref_ecole.sql', 'secondaire' => 'bd/assoc/schema_ref_ecole_secondaire.sql'] as $type => $ref) {
    $db = 'zz_test_mig_' . $type;
    $srv = mysqli_connect(DB_HOST, DB_USER, DB_PASS);
    mysqli_query($srv, "DROP DATABASE IF EXISTS $db");
    mysqli_query($srv, "CREATE DATABASE $db CHARACTER SET utf8mb4");
    mysqli_select_db($srv, $db);
    if (mysqli_multi_query($srv, file_get_contents($ref))) { do { if ($r = mysqli_store_result($srv)) mysqli_free_result($r); } while (mysqli_more_results($srv) && mysqli_next_result($srv)); }
    egal('', mysqli_error($srv), "$type : schéma de référence chargé sans erreur");

    $rv = rv_analyser_ecole(['id' => 0, 'code' => 'T', 'nom' => 'T', 'db_name' => $db, 'type_enseignement' => $type, 'version' => 0]);
    egal($rv['derniere'], $rv['reelle'], "$type : le schéma de référence contient toutes les migrations (v{$rv['derniere']})"
        . ($rv['manque'] ? ' — manque ' . json_encode($rv['manque'], JSON_UNESCAPED_UNICODE) : ''));

    $erreurs = [];
    foreach (rv_migrations($type) as $v => $f) {
        if ($v <= RV_BASE[$type] || $v === 64) continue;   // v64 : données arabes (exige les niveaux du seed)
        try { migration_executer($srv, file_get_contents($f)); } catch (\Throwable $ex) { $erreurs[] = "v$v : " . $ex->getMessage(); }
    }
    ok(!$erreurs, "$type : toute la série rejouée sans arrêt" . ($erreurs ? ' — ' . implode(' ; ', $erreurs) : ''));
    mysqli_query($srv, "DROP DATABASE $db");
    mysqli_close($srv);
}

// Découpage des instructions : « ; » dans une chaîne ou un commentaire
egal(3, count(migration_instructions("SELECT 'a;b';\n-- x; y\nSELECT 1; /* ; */ SELECT \"c;\";")), 'découpage SQL respecte chaînes et commentaires');
