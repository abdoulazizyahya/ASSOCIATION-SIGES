<?php
// =====================================================================
//  jaynitaare_v2 — Runner de la migration v44 (mysqli procédural, idempotent)
//  php bd/run_migration_v44.php   (ou via navigateur)
//  Rétro-remplissage de groupe_competence_niveau pour les niveaux déjà
//  configurés via `discipline` (I/II/III) mais jamais associés
//  explicitement via l'onglet « Groupes par niveau ». Voir bd/migration_v44.sql.
// =====================================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';

header('Content-Type: text/plain; charset=utf-8');
echo "=== Migration v44 — jaynitaare_v2 (mysqli) ===\n\n";

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

$DEJA_LA = [1060, 1061, 1826, 1005, 1050];

run(
    "groupe_competence_niveau (rattrapage depuis discipline)",
    "INSERT IGNORE INTO `groupe_competence_niveau` (`code_niveau`, `id_groupe_comp`, `actif`)
     SELECT DISTINCT c.Niveau, g.id_groupe_comp, 1
     FROM `discipline` d
     JOIN `competence` comp ON comp.id_comp = d.id_comp
     JOIN `groupe_competence` g ON g.id_groupe_comp = comp.id_groupe_comp
     JOIN `classe` c ON c.IDClasses = d.IDClasses
     JOIN `annee_scolaire` a ON a.val_annee = d.annee_scol AND a.Etat_annee_scolaire = 1
     WHERE g.langue = 'Fr'",
    $DEJA_LA
);

echo "\n=== Vérification (par niveau) ===\n";
foreach (db_all("SELECT DISTINCT Niveau FROM classe ORDER BY Niveau") as $n) {
    $nb = (int) db_val("SELECT COUNT(*) FROM groupe_competence_niveau WHERE code_niveau=?", [$n['Niveau']]);
    echo "  Niveau {$n['Niveau']} : $nb groupe(s) assigné(s)\n";
}

echo "\nMigration v44 terminée.\n";
