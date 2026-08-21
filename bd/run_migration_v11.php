<?php
// =====================================================================
//  ABZ_MBE — Runner de la migration v11 (mysqli procédural, idempotent)
//  À ouvrir dans le navigateur :
//      http://localhost/ABZ_MBE/bd/run_migration_v11.php
//
//  1) Ajoute `utilisateur.email` (colonne manquante, référencée par
//     pages/utilisateurs/form.php et profil.php mais absente en base).
//  2) Crée `question_secrete` (catalogue, 10 questions) et
//     `utilisateur_question_secrete` (2 réponses hashées par utilisateur).
//  3) Hache tous les mots de passe encore en clair dans `utilisateur`
//     (password_hash) — relançable sans risque : un mot de passe déjà
//     hashé (détecté via password_get_info) n'est jamais re-haché.
// =====================================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';   // fournit $link + helpers db_*

header('Content-Type: text/plain; charset=utf-8');
echo "=== Migration v11 — ABZ_MBE (mysqli) ===\n\n";

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

// ── (A) Colonne utilisateur.email ────────────────────────────────────
// 1060 = Duplicate column name → colonne déjà présente, on ignore.
run("utilisateur.email",
    "ALTER TABLE `utilisateur` ADD COLUMN `email` VARCHAR(150) NULL DEFAULT NULL", [1060]);

// ── (B) Catalogue question_secrete ───────────────────────────────────
run("Table `question_secrete`",
    "CREATE TABLE IF NOT EXISTS `question_secrete` (
        `id`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `libelle` VARCHAR(255) NOT NULL,
        `actif`   TINYINT(1) NOT NULL DEFAULT 1,
        PRIMARY KEY (`id`)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$questions = [
    'Quel est le nom de jeune fille de votre mère ?',
    'Quelle est votre ville de naissance ?',
    'Quel est le nom de votre premier animal de compagnie ?',
    "Quel est le nom de votre meilleur ami d'enfance ?",
    'Quel est le nom de votre école primaire ?',
    'Quel est votre plat préféré ?',
    'Quel est le prénom de votre grand-père paternel ?',
    'Quel est le nom de votre premier employeur ?',
    'Quelle est votre couleur préférée ?',
    'Quel surnom vous donnait-on enfant ?',
];
foreach ($questions as $q) {
    $exists = db_val("SELECT COUNT(*) FROM question_secrete WHERE libelle = ?", [$q]);
    if (!$exists) db_exec("INSERT INTO question_secrete (libelle) VALUES (?)", [$q]);
}
echo "✓ Catalogue de questions (" . count($questions) . " questions)\n";

// ── (C) Réponses des utilisateurs ────────────────────────────────────
run("Table `utilisateur_question_secrete`",
    "CREATE TABLE IF NOT EXISTS `utilisateur_question_secrete` (
        `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `id_utilisateur` INT UNSIGNED NOT NULL,
        `id_question`    INT UNSIGNED NOT NULL,
        `reponse_hash`   VARCHAR(255) NOT NULL,
        `maj_le`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_user_question` (`id_utilisateur`, `id_question`),
        KEY `idx_uqs_question` (`id_question`),
        CONSTRAINT `fk_uqs_utilisateur` FOREIGN KEY (`id_utilisateur`)
            REFERENCES `utilisateur` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT `fk_uqs_question` FOREIGN KEY (`id_question`)
            REFERENCES `question_secrete` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ── (D) Hashage des mots de passe existants ──────────────────────────
echo "\n--- Hashage des mots de passe ---\n";
$rows   = db_all("SELECT id, login, mot_de_passe FROM utilisateur");
$nbHash = 0;
$nbSkip = 0;
foreach ($rows as $r) {
    $info = password_get_info($r['mot_de_passe']);
    if (!empty($info['algo'])) { $nbSkip++; continue; } // déjà hashé (algo reconnu — NULL/0 selon version PHP sinon)
    $hash = password_hash($r['mot_de_passe'], PASSWORD_DEFAULT);
    db_exec("UPDATE utilisateur SET mot_de_passe = ? WHERE id = ?", [$hash, $r['id']]);
    $nbHash++;
}
echo "✓ Mots de passe hachés : $nbHash\n";
echo "⚠ Déjà hachés (ignorés) : $nbSkip\n";

// ── Récapitulatif ────────────────────────────────────────────────────
echo "\n=== Vérification ===\n";
echo "Colonnes utilisateur : " . implode(', ', array_column(db_all("SHOW COLUMNS FROM utilisateur"), 'Field')) . "\n";
echo "Questions au catalogue : " . db_val("SELECT COUNT(*) FROM question_secrete") . " (attendu 10)\n";
echo "Utilisateurs total : " . db_val("SELECT COUNT(*) FROM utilisateur") . "\n";

echo "\n✅ Migration v11 terminée. Vous pouvez supprimer ce fichier après usage.\n";
