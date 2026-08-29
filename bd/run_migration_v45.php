<?php
// =====================================================================
//  jaynitaare_v2 — Runner de la migration v45 (mysqli procédural, idempotent)
//  php bd/run_migration_v45.php   (ou via navigateur)
//  1) Rôle COMPTABLE (Agent financier). 2) Récupération de mot de passe par
//  2 questions secrètes (question_secrete + user_question_secrete). Voir
//  bd/migration_v45.sql.
// =====================================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';

header('Content-Type: text/plain; charset=utf-8');
echo "=== Migration v45 — jaynitaare_v2 (mysqli) ===\n\n";

function run(string $label, string $sql, array $ignoreCodes = []): void {
    global $link;
    try {
        mysqli_query($link, $sql);
        echo "OK  $label (" . mysqli_affected_rows($link) . " ligne(s))\n";
    } catch (mysqli_sql_exception $e) {
        if (in_array($e->getCode(), $ignoreCodes, true)) {
            echo "--  $label - déjà en place (ignoré)\n";
        } else {
            echo "ERR $label - [" . $e->getCode() . "] " . $e->getMessage() . "\n";
        }
    }
}

$DEJA_LA = [1060, 1061, 1826, 1005, 1050, 1062];

run("Rôle COMPTABLE", "INSERT IGNORE INTO `fonction` (`id_fonction`) VALUES ('COMPTABLE')", $DEJA_LA);

run(
    "Table question_secrete",
    "CREATE TABLE IF NOT EXISTS `question_secrete` (
      `id`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `libelle` VARCHAR(255) NOT NULL,
      `actif`   TINYINT(1) NOT NULL DEFAULT 1,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    $DEJA_LA
);

// Semis du catalogue de questions — seulement s'il est encore vide (une
// exécution répétée du runner ne doit jamais dupliquer les 10 questions).
$nb_questions = (int) db_val("SELECT COUNT(*) FROM question_secrete");
if ($nb_questions === 0) {
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
        db_exec("INSERT INTO question_secrete (libelle) VALUES (?)", [$q]);
    }
    echo "OK  Catalogue de 10 questions secrètes inséré\n";
} else {
    echo "--  Catalogue de questions secrètes - déjà en place ($nb_questions question(s), ignoré)\n";
}

run(
    "Table user_question_secrete",
    "CREATE TABLE IF NOT EXISTS `user_question_secrete` (
      `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `id_user`     INT NOT NULL,
      `id_question` INT UNSIGNED NOT NULL,
      `reponse_hash` VARCHAR(255) NOT NULL,
      `maj_le`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uk_user_question` (`id_user`, `id_question`),
      KEY `idx_uqs_question` (`id_question`),
      CONSTRAINT `fk_uqs_user` FOREIGN KEY (`id_user`)
          REFERENCES `user` (`id_user`) ON DELETE CASCADE ON UPDATE CASCADE,
      CONSTRAINT `fk_uqs_question` FOREIGN KEY (`id_question`)
          REFERENCES `question_secrete` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    $DEJA_LA
);

echo "\n=== Vérification ===\n";
echo "Rôles disponibles : " . implode(', ', array_column(db_all("SELECT id_fonction FROM fonction"), 'id_fonction')) . "\n";
echo "Questions secrètes : " . (int) db_val("SELECT COUNT(*) FROM question_secrete") . "\n";

echo "\nMigration v45 terminée.\n";
