<?php
// =====================================================================
//  jaynitaare_v2 — Runner de la migration v50 (mysqli procédural, idempotent)
//  php bd/run_migration_v50.php   (ou via navigateur)
//  Voir bd/migration_v50.sql.
// =====================================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';

header('Content-Type: text/plain; charset=utf-8');
echo "=== Migration v50 — jaynitaare_v2 (mysqli) ===\n\n";

$existe = db_val("SHOW TABLES LIKE 'classe_matiere_arabe'");
if (!$existe) {
    echo "--  classe_matiere_arabe déjà absente (migration déjà appliquée)\n";
} else {
    $avant = db_val("SELECT COUNT(*) FROM matiere_niveau_arabe");
    db_exec(
        "INSERT IGNORE INTO matiere_niveau_arabe (code_niveau, id_mat, ordre, actif)
         SELECT DISTINCT c.Niveau, cma.id_mat, cma.ordre, 1
         FROM classe_matiere_arabe cma
         JOIN classe c ON c.IDClasses = cma.code_classe"
    );
    $apres = db_val("SELECT COUNT(*) FROM matiere_niveau_arabe");
    echo 'OK  Backfill matiere_niveau_arabe : +' . ((int) $apres - (int) $avant) . " ligne(s)\n";

    db_exec("DROP TABLE `classe_matiere_arabe`");
    echo "OK  Table classe_matiere_arabe supprimée\n";
}

echo "\n=== Vérification ===\n";
echo "Table classe_matiere_arabe : " . (db_val("SHOW TABLES LIKE 'classe_matiere_arabe'") ? "ENCORE PRÉSENTE (erreur)" : "absente (attendu)") . "\n";
echo "Lignes matiere_niveau_arabe : " . (int) db_val("SELECT COUNT(*) FROM matiere_niveau_arabe") . "\n";
foreach (db_all("SELECT DISTINCT Niveau FROM classe ORDER BY Niveau") as $n) {
    $niv = $n['Niveau'];
    $nb = (int) db_val("SELECT COUNT(*) FROM matiere_niveau_arabe WHERE code_niveau=? AND actif=1", [$niv]);
    echo "  Niveau $niv : $nb matière(s) assignée(s)\n";
}

echo "\nMigration v50 terminée.\n";
