<?php
// =====================================================================
//  bd/assoc/pool_enregistrer.php
//  Enregistre dans jaynitaare_assoc.bd_pool des bases MySQL VIDES créées
//  à l'avance (cPanel / hébergement mutualisé où PHP ne peut pas faire
//  CREATE DATABASE). La création d'une école (creer_etablissement, si
//  ECOLE_POOL_ACTIF) consomme ensuite une de ces bases.
//
//  Pré-requis pour CHAQUE base : créée au cPanel, l'utilisateur MySQL de
//  config.local.php ajouté dessus avec ALL PRIVILEGES, et AUCUNE table.
//
//  Usage HTTP :
//    /bd/assoc/pool_enregistrer.php?db=promeduca_pool01,promeduca_pool02
//    /bd/assoc/pool_enregistrer.php                 (liste l'état du pool)
//  Usage CLI :
//    php bd/assoc/pool_enregistrer.php promeduca_pool01 promeduca_pool02
// =====================================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion_assoc.php';

if (PHP_SAPI !== 'cli') header('Content-Type: text/plain; charset=utf-8');

if (!annuaire_dispo()) { die("Annuaire absent (bd/assoc/installer.php).\n"); }

// S'assurer que la table bd_pool existe (schema_assoc.sql peut ne pas
// avoir été rejoué depuis l'ajout de la table).
assoc_exec(
    "CREATE TABLE IF NOT EXISTS bd_pool (
        db_name varchar(64) NOT NULL,
        etat enum('libre','consomme') NOT NULL DEFAULT 'libre',
        id_etablissement int DEFAULT NULL,
        cree_le datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        consomme_le datetime DEFAULT NULL,
        PRIMARY KEY (db_name),
        KEY k_etat (etat)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

// Liste des noms de base à enregistrer
$noms = [];
if (PHP_SAPI === 'cli') {
    $noms = array_slice($argv ?? [], 1);
} elseif (!empty($_GET['db'])) {
    $noms = preg_split('/[,\s]+/', (string) $_GET['db'], -1, PREG_SPLIT_NO_EMPTY);
}
$noms = array_values(array_unique(array_filter(array_map('trim', $noms))));

echo "=== Pool de bases écoles ===\n\n";

foreach ($noms as $db) {
    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $db)) {
        echo "  IGNORÉ  « $db » — nom invalide.\n";
        continue;
    }
    if (assoc_val("SELECT COUNT(*) FROM bd_pool WHERE db_name=?", [$db])) {
        echo "  déjà là  $db\n";
        continue;
    }
    // Connexion + base réellement vide ?
    try {
        $l = mysqli_connect(DB_HOST, DB_USER, DB_PASS, $db);
        mysqli_set_charset($l, 'utf8mb4');
        $nb = (int) mysqli_fetch_row(mysqli_query($l,
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()"))[0];
        mysqli_close($l);
    } catch (\Throwable $e) {
        echo "  ÉCHEC   $db — connexion impossible : " . $e->getMessage() . "\n";
        continue;
    }
    if ($nb > 0) {
        echo "  REFUSÉ  $db — contient déjà $nb table(s) (doit être vide).\n";
        continue;
    }
    assoc_exec("INSERT INTO bd_pool (db_name) VALUES (?)", [$db]);
    echo "  AJOUTÉ  $db\n";
}

// État courant du pool
$libres = (int) assoc_val("SELECT COUNT(*) FROM bd_pool WHERE etat='libre'");
$conso  = (int) assoc_val("SELECT COUNT(*) FROM bd_pool WHERE etat='consomme'");
echo "\n--- Pool : $libres libre(s), $conso consommée(s) ---\n";
foreach (assoc_all("SELECT db_name, etat, id_etablissement, consomme_le FROM bd_pool ORDER BY db_name") as $r) {
    echo sprintf("  %-24s %-10s %s\n", $r['db_name'], $r['etat'],
        $r['etat'] === 'consomme' ? "→ école #{$r['id_etablissement']} ({$r['consomme_le']})" : '');
}
if (!$noms) {
    echo "\n(Astuce : ?db=promeduca_pool01,promeduca_pool02 pour en enregistrer.)\n";
}
