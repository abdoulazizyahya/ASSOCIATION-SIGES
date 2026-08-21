<?php
// =====================================================================
//  ABZ_MBE — Runner de la migration v21 (mysqli procédural, idempotent)
//  À ouvrir dans le navigateur :
//      http://localhost/ABZ_MBE/bd/run_migration_v21.php
//
//  Crée signature_titulaire (chef_etablissement/intendant/president_apee)
//  et signature_position (position/taille par type de document), puis
//  copie l'ancienne etablissement.signature (v20) vers signature_titulaire.
// =====================================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';

header('Content-Type: text/plain; charset=utf-8');
echo "=== Migration v21 — ABZ_MBE (mysqli) ===\n\n";

function run(string $label, string $sql, array $ignoreCodes = []): void {
    global $link;
    try {
        mysqli_query($link, $sql);
        echo "✓ $label\n";
    } catch (mysqli_sql_exception $e) {
        if (in_array($e->getCode(), $ignoreCodes, true)) {
            echo "⚠ $label — déjà en place (ignoré)\n";
        } else {
            echo "✗ $label — [" . $e->getCode() . "] " . $e->getMessage() . "\n";
        }
    }
}

run("Table signature_titulaire", "
    CREATE TABLE IF NOT EXISTS `signature_titulaire` (
      `code` VARCHAR(30) NOT NULL,
      `libelle` VARCHAR(100) NOT NULL,
      `fichier` VARCHAR(255) NULL DEFAULT NULL,
      `role_gestion` VARCHAR(30) NOT NULL,
      PRIMARY KEY (`code`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
");

run("Table signature_position", "
    CREATE TABLE IF NOT EXISTS `signature_position` (
      `type_document` VARCHAR(40) NOT NULL,
      `code_signature` VARCHAR(30) NOT NULL,
      `x_pct` DECIMAL(6,3) NOT NULL,
      `y_pct` DECIMAL(6,3) NOT NULL,
      `w_pct` DECIMAL(6,3) NOT NULL,
      `h_pct` DECIMAL(6,3) NULL DEFAULT NULL,
      PRIMARY KEY (`type_document`, `code_signature`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
");

// 1062 = Duplicate entry (déjà seedé)
run("Seed chef_etablissement",
    "INSERT INTO signature_titulaire (code, libelle, role_gestion) VALUES ('chef_etablissement', \"Chef d'établissement (Proviseur)\", 'ADMIN_CENSEUR')",
    [1062]);
run("Seed intendant",
    "INSERT INTO signature_titulaire (code, libelle, role_gestion) VALUES ('intendant', 'Intendant', 'INTENDANT')",
    [1062]);
run("Seed president_apee",
    "INSERT INTO signature_titulaire (code, libelle, role_gestion) VALUES ('president_apee', \"Président de l'APEE\", 'INTENDANT')",
    [1062]);

// Reprise de l'ancienne signature v20 (etablissement.signature) si elle
// existe et que la nouvelle table n'a pas encore de fichier pour
// chef_etablissement — ne jamais écraser une signature déjà reconfigurée
// via le nouveau mécanisme.
$ancienne = db_val("SELECT signature FROM etablissement WHERE id=1");
if (!empty($ancienne)) {
    $deja = db_val("SELECT fichier FROM signature_titulaire WHERE code='chef_etablissement'");
    if (empty($deja)) {
        db_exec("UPDATE signature_titulaire SET fichier=? WHERE code='chef_etablissement'", [$ancienne]);
        echo "✓ Signature v20 reprise pour chef_etablissement ($ancienne)\n";
    } else {
        echo "⚠ chef_etablissement a déjà un fichier — signature v20 non écrasée\n";
    }
} else {
    echo "⚠ Aucune signature v20 à reprendre (etablissement.signature vide)\n";
}

echo "\n=== Vérification ===\n";
print_r(db_all("SELECT * FROM signature_titulaire"));

echo "\n✅ Migration v21 terminée. Vous pouvez supprimer ce fichier après usage.\n";
