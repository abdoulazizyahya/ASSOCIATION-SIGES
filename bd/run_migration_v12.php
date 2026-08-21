<?php
// =====================================================================
//  ABZ_MBE — Runner de la migration v12 (mysqli procédural, idempotent)
//  À ouvrir dans le navigateur :
//      http://localhost/ABZ_MBE/bd/run_migration_v12.php
//
//  Module Paiement des frais de scolarité :
//  - Ajoute le rôle INTENDANT à utilisateur.role.
//  - Crée obligation_frais (catalogue des frais par niveau/année),
//    operateur_paiement (canaux de paiement, seedé), paiement_frais
//    (versements — paiement échelonné par frais), apee_config
//    (métadonnées de signature pour les reçus APEE).
// =====================================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';   // fournit $link + helpers db_*

header('Content-Type: text/plain; charset=utf-8');
echo "=== Migration v12 — ABZ_MBE (mysqli) ===\n\n";

/* Exécute un DDL/DML en tolérant certains codes d'erreur MySQL « bénins ». */
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

// ── (A) Rôle INTENDANT ───────────────────────────────────────────────
run("utilisateur.role += INTENDANT",
    "ALTER TABLE `utilisateur`
     MODIFY COLUMN `role` ENUM('ADMIN','PROVISEUR','CENSEUR','SG','SECRETAIRE','ENSEIGNANT','INTENDANT')
     NOT NULL DEFAULT 'SECRETAIRE'");

// ── (B) Catalogue obligation_frais ───────────────────────────────────
run("Table `obligation_frais`",
    "CREATE TABLE IF NOT EXISTS `obligation_frais` (
        `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `libelle`     VARCHAR(150) NOT NULL,
        `code_niveau` VARCHAR(25) NOT NULL,
        `montant`     DECIMAL(10,2) NOT NULL,
        `id_annee`    INT UNSIGNED NOT NULL,
        `actif`       TINYINT(1) NOT NULL DEFAULT 1,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_obligation` (`libelle`, `code_niveau`, `id_annee`),
        KEY `idx_obligation_niveau` (`code_niveau`),
        KEY `idx_obligation_annee` (`id_annee`),
        CONSTRAINT `fk_obligation_niveau` FOREIGN KEY (`code_niveau`) REFERENCES `niveau` (`code_niveau`) ON UPDATE CASCADE,
        CONSTRAINT `fk_obligation_annee` FOREIGN KEY (`id_annee`) REFERENCES `annee_scolaire` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");

// ── (C) Canaux de paiement ───────────────────────────────────────────
run("Table `operateur_paiement`",
    "CREATE TABLE IF NOT EXISTS `operateur_paiement` (
        `id`      VARCHAR(20) NOT NULL,
        `libelle` VARCHAR(60) NOT NULL,
        PRIMARY KEY (`id`)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");

$operateurs = [
    'CASH'     => 'Espèces',
    'CAMPOST'  => 'CAMPOST',
    'AFRILAND' => 'Afriland First Bank',
    'ECOBANK'  => 'Ecobank',
    'EU'       => 'Express Union',
    'MOMO'     => 'MTN Mobile Money',
    'OM'       => 'Orange Money',
    'UBA'      => 'UBA',
];
foreach ($operateurs as $id => $libelle) {
    db_exec("INSERT IGNORE INTO operateur_paiement (id, libelle) VALUES (?, ?)", [$id, $libelle]);
}
echo "✓ Canaux de paiement (" . count($operateurs) . ")\n";

// ── (D) Transactions paiement_frais ──────────────────────────────────
run("Table `paiement_frais`",
    "CREATE TABLE IF NOT EXISTS `paiement_frais` (
        `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `id_eleve`       INT UNSIGNED NOT NULL,
        `id_classe`      INT UNSIGNED NOT NULL,
        `id_annee`       INT UNSIGNED NOT NULL,
        `id_obligation`  INT UNSIGNED NOT NULL,
        `id_operateur`   VARCHAR(20) NOT NULL,
        `montant`        DECIMAL(10,2) NOT NULL,
        `ref_paiement`   VARCHAR(50) NULL DEFAULT NULL,
        `date_paiement`  DATE NOT NULL,
        `id_utilisateur` INT UNSIGNED NOT NULL,
        `numero_recu`    VARCHAR(20) NOT NULL,
        `cree_le`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_numero_recu` (`numero_recu`),
        KEY `idx_pf_eleve` (`id_eleve`, `id_annee`, `id_obligation`),
        KEY `idx_pf_classe` (`id_classe`, `id_annee`),
        CONSTRAINT `fk_pf_eleve`      FOREIGN KEY (`id_eleve`)       REFERENCES `eleve` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT `fk_pf_classe`     FOREIGN KEY (`id_classe`)      REFERENCES `classe` (`id`) ON UPDATE CASCADE,
        CONSTRAINT `fk_pf_annee`      FOREIGN KEY (`id_annee`)       REFERENCES `annee_scolaire` (`id`) ON UPDATE CASCADE,
        CONSTRAINT `fk_pf_obligation` FOREIGN KEY (`id_obligation`)  REFERENCES `obligation_frais` (`id`) ON UPDATE CASCADE,
        CONSTRAINT `fk_pf_operateur`  FOREIGN KEY (`id_operateur`)   REFERENCES `operateur_paiement` (`id`) ON UPDATE CASCADE,
        CONSTRAINT `fk_pf_utilisateur` FOREIGN KEY (`id_utilisateur`) REFERENCES `utilisateur` (`id`) ON UPDATE CASCADE
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");

// ── (E) Métadonnées APEE ─────────────────────────────────────────────
run("Table `apee_config`",
    "CREATE TABLE IF NOT EXISTS `apee_config` (
        `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `id_annee`       INT UNSIGNED NOT NULL,
        `nom_president`  VARCHAR(150) NULL DEFAULT NULL,
        `nom_tresorier`  VARCHAR(150) NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_apee_annee` (`id_annee`),
        CONSTRAINT `fk_apee_annee` FOREIGN KEY (`id_annee`) REFERENCES `annee_scolaire` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");

// ── Récapitulatif ────────────────────────────────────────────────────
echo "\n=== Vérification ===\n";
echo "Rôles utilisateur : " . db_val("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='utilisateur' AND COLUMN_NAME='role'") . "\n";
echo "Canaux de paiement en base : " . db_val("SELECT COUNT(*) FROM operateur_paiement") . " (attendu 8)\n";
echo "Tables créées : obligation_frais, paiement_frais, apee_config\n";

echo "\n✅ Migration v12 terminée. Vous pouvez supprimer ce fichier après usage.\n";
