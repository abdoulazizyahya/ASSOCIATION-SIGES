<?php
// =====================================================================
//  bd/assoc/fix_dbnames_camoo.php
//  Recale etablissement.db_name (annuaire) sur les bases réellement
//  créées chez l'hébergeur mutualisé, d'après le préfixe de DB_NAME_ASSOC.
//  À lancer UNE FOIS après l'import du dump « assoc » (les db_name qu'il
//  contient sont ceux de l'ancien serveur : promeducam_xxx).
//
//  Sécurité : HTTP → jeton  ?token=<BACKUP_TOKEN>.
//  Usage : /bd/assoc/fix_dbnames_camoo.php?token=XXX        (aperçu)
//          /bd/assoc/fix_dbnames_camoo.php?token=XXX&go=1   (applique)
//  À SUPPRIMER après usage.
// =====================================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion_assoc.php';

$est_cli = (PHP_SAPI === 'cli');
if (!$est_cli) header('Content-Type: text/plain; charset=utf-8');

if (!$est_cli) {
    $token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
    if (!defined('BACKUP_TOKEN') || BACKUP_TOKEN === '' || !hash_equals(BACKUP_TOKEN, $token)) {
        http_response_code(403);
        die("Accès refusé : jeton invalide (BACKUP_TOKEN de config.local.php).\n");
    }
}
$go = $est_cli ? in_array('--go', $argv, true) : !empty($_GET['go']);

// Préfixe (« beeroc11073_ ») déduit de DB_NAME_ASSOC.
$p = strrpos(DB_NAME_ASSOC, '_');
$prefixe = $p !== false ? substr(DB_NAME_ASSOC, 0, $p + 1) : '';
if ($prefixe === '') die("✗ Préfixe indéductible de DB_NAME_ASSOC. Abandon.\n");

// code établissement → suffixe de base créé chez l'hébergeur.
$MAP = [
    'EC1'    => 'ec1',
    'EFAGM'  => 'efagm',
    'EXG001' => 'exg',
    'IR01'   => 'ir01',
    'JAK'    => 'jak',
    'MHM1'   => 'mhm1',
];

echo "=== Recalage des db_name (préfixe « $prefixe ») ===\n";
echo $go ? "Mode : APPLIQUE\n" : "Mode : APERÇU (ajouter &go=1)\n";
echo str_repeat('-', 56) . "\n";

if (!annuaire_dispo()) die("✗ Annuaire indisponible (dump assoc importé ?).\n");

$lignes = assoc_all("SELECT id, code, db_name, nom FROM etablissement ORDER BY id");
if (!$lignes) die("✗ Aucun établissement dans l'annuaire.\n");

$maj = 0;
foreach ($lignes as $e) {
    $code = strtoupper(trim($e['code']));
    $suf  = $MAP[$code] ?? strtolower(preg_replace('~[^A-Za-z0-9]~', '', $code));
    $cible = $prefixe . $suf;
    $etat = $e['db_name'] === $cible ? 'OK' : ($e['db_name'] . '  →  ' . $cible);
    echo sprintf("  #%-3d %-8s %s\n", $e['id'], $code, $etat);
    if ($e['db_name'] !== $cible && $go) {
        assoc_exec("UPDATE etablissement SET db_name = ?, sous_domaine = NULL WHERE id = ?", [$cible, $e['id']]);
        $maj++;
    }
}
if ($go) {
    assoc_exec("UPDATE membre_acces SET plein_acces = 1 WHERE id_etablissement IS NULL");
    echo "\n✔ $maj db_name mis à jour ; membre_acces global → plein_acces.\n";
    echo "Ensuite : /bd/assoc/maj_assoc.php puis /bd/assoc/migrer_toutes_ecoles.php\n";
    echo "Puis SUPPRIME ce fichier.\n";
} else {
    echo "\n(aucune écriture — ajoute &go=1)\n";
}
