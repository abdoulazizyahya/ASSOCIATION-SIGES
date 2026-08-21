<?php
// =====================================================================
//  ABZ_MBE — Runner de la migration v15 (mysqli procédural, idempotent)
//  À ouvrir dans le navigateur :
//      http://localhost/ABZ_MBE/bd/run_migration_v15.php
//
//  Corrige 12 libellés corrompus (filiere, niveau, serie) lors d'un
//  import historique — les '?' avaient remplacé des caractères accentués
//  non convertibles. Texte restauré depuis SAVE/bd/migration_v2.sql
//  (source d'origine, non touchée par la corruption).
//  Idempotent : si déjà corrigé, les UPDATE n'ont simplement aucun effet.
// =====================================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';

header('Content-Type: text/plain; charset=utf-8');
echo "=== Migration v15 — ABZ_MBE (mysqli) ===\n\n";

echo "--- Avant correction ---\n";
foreach (db_all("SELECT id, libelle FROM filiere WHERE id='GEN'") as $r) echo "filiere.$r[id]: $r[libelle]\n";
foreach (db_all("SELECT code_niveau, libelle_niv FROM niveau WHERE code_niveau IN ('1A','2A','3A','4A','1ere')") as $r) echo "niveau.$r[code_niveau]: $r[libelle_niv]\n";
foreach (db_all("SELECT id, libelle FROM serie WHERE id BETWEEN 1 AND 6") as $r) echo "serie.$r[id]: $r[libelle]\n";

$updates = [
    ["UPDATE `filiere` SET `libelle` = ? WHERE `id` = ?", ['Générale', 'GEN']],
    ["UPDATE `niveau` SET `libelle_niv` = ? WHERE `code_niveau` = ?", ['1ère Année', '1A']],
    ["UPDATE `niveau` SET `libelle_niv` = ? WHERE `code_niveau` = ?", ['2ème Année', '2A']],
    ["UPDATE `niveau` SET `libelle_niv` = ? WHERE `code_niveau` = ?", ['3ème Année', '3A']],
    ["UPDATE `niveau` SET `libelle_niv` = ? WHERE `code_niveau` = ?", ['4ème Année', '4A']],
    ["UPDATE `niveau` SET `libelle_niv` = ? WHERE `code_niveau` = ?", ['Première', '1ere']],
    ["UPDATE `serie` SET `libelle` = ? WHERE `id` = ?", ['G1 – Comptabilité', 1]],
    ["UPDATE `serie` SET `libelle` = ? WHERE `id` = ?", ['G2 – Action commerciale', 2]],
    ["UPDATE `serie` SET `libelle` = ? WHERE `id` = ?", ['EEI – Electrotechnique', 3]],
    ["UPDATE `serie` SET `libelle` = ? WHERE `id` = ?", ['MAI – Maintenance industrielle', 4]],
    ["UPDATE `serie` SET `libelle` = ? WHERE `id` = ?", ['GMC – Génie mécanique', 5]],
    ["UPDATE `serie` SET `libelle` = ? WHERE `id` = ?", ['Générale', 6]],
];

echo "\n--- Application ---\n";
foreach ($updates as [$sql, $params]) {
    $n = db_exec($sql, $params);
    echo ($n ? "✓" : "⚠ (déjà correct ou introuvable)") . " $sql  [" . implode(', ', $params) . "]\n";
}

echo "\n--- Après correction ---\n";
foreach (db_all("SELECT id, libelle FROM filiere WHERE id='GEN'") as $r) echo "filiere.$r[id]: $r[libelle]\n";
foreach (db_all("SELECT code_niveau, libelle_niv FROM niveau WHERE code_niveau IN ('1A','2A','3A','4A','1ere')") as $r) echo "niveau.$r[code_niveau]: $r[libelle_niv]\n";
foreach (db_all("SELECT id, libelle FROM serie WHERE id BETWEEN 1 AND 6") as $r) echo "serie.$r[id]: $r[libelle]\n";

$restant = (int) db_val("
    SELECT
      (SELECT COUNT(*) FROM filiere WHERE CONVERT(libelle USING binary) LIKE '%??%') +
      (SELECT COUNT(*) FROM niveau  WHERE CONVERT(libelle_niv USING binary) LIKE '%??%') +
      (SELECT COUNT(*) FROM serie   WHERE CONVERT(libelle USING binary) LIKE '%??%')
");
echo "\nLignes encore corrompues (attendu 0) : $restant\n";

echo "\n✅ Migration v15 terminée. Vous pouvez supprimer ce fichier après usage.\n";
