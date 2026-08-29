<?php
// =====================================================================
//  jaynitaare_v2 — Runner de la migration v47 (mysqli procédural, idempotent)
//  php bd/run_migration_v47.php   (ou via navigateur)
// =====================================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';

header('Content-Type: text/plain; charset=utf-8');
echo "=== Migration v47 — jaynitaare_v2 (mysqli) ===\n\n";

db_exec(
    "INSERT IGNORE INTO `pdf_couleur` (`cle`, `libelle`, `r`, `g`, `b`) VALUES
     ('entete_tableau_arabe', 'En-tête du tableau de compétences (bulletin trim. arabe)', 0, 153, 153),
     ('groupe_tableau_arabe', 'Bandeaux de groupe du tableau de compétences (bulletin trim. arabe)', 252, 213, 180),
     ('totaux_tableau_arabe', 'Ligne TOTAUX du tableau de compétences (bulletin trim. arabe)', 197, 217, 241)"
);
echo "OK  pdf_couleur (entete_tableau_arabe / groupe_tableau_arabe / totaux_tableau_arabe)\n";

echo "\n=== Vérification ===\n";
foreach (['entete_tableau_arabe', 'groupe_tableau_arabe', 'totaux_tableau_arabe'] as $cle) {
    $row = db_one("SELECT * FROM pdf_couleur WHERE cle=?", [$cle]);
    echo "$cle : " . ($row ? "{$row['r']},{$row['g']},{$row['b']}" : 'MANQUANTE') . "\n";
}

echo "\nMigration v47 terminée.\n";
