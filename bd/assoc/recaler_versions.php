<?php
// =====================================================================
//  bd/assoc/recaler_versions.php — recale schema_version_etab sur la
//  VRAIE version de schéma de chaque base école.
// =====================================================================
//  Pourquoi : si schema_version_etab ne reflète plus l'état réel d'une base
//  (ex. toutes les écoles primaires remises à v1 le 25/09/2026 alors que
//  leurs bases étaient bien plus récentes), migrer_toutes_ecoles.php et le
//  bouton « Migrer » du portail rejouent des migrations déjà appliquées et
//  échouent dès la première (« colonne déjà existante »).
//
//  Méthode : chaque bd/migration_vN.sql (bd/secondaire/ pour une école
//  secondaire) est lu pour en extraire des INDICES vérifiables dans la base :
//    - ADD [COLUMN] `c`            -> la colonne existe
//    - ADD [UNIQUE] INDEX|KEY `k`  -> l'index existe
//    - CREATE TABLE `t`            -> la table existe
//    - DROP COLUMN|TABLE|INDEX `x` -> l'objet n'existe plus
//    - MODIFY/CHANGE `c` enum(...) -> la colonne accepte toutes ces valeurs
//  Un objet ajouté puis supprimé par une migration ultérieure (ou l'inverse)
//  n'est pas utilisé comme indice. Une migration sans indice (UPDATE de
//  données seul, etc.) est « indéterminée » et ne bloque pas.
//  Version réelle = la plus haute N telle que toutes les migrations
//  vérifiables <= N sont appliquées.
//  PRIMAIRE : les écoles partent du schéma de référence schema_ref_ecole.sql
//  (état v50, voir migrer_toutes_ecoles.php) — les migrations <= 50 (dont
//  d'anciennes migrations ABZ_MBE sans rapport avec ce schéma) y sont
//  intégrées et ne sont jamais vérifiées : seules v51+ le sont.
//
//  Usage :  php bd/assoc/recaler_versions.php                 (affiche, ne modifie RIEN)
//           php bd/assoc/recaler_versions.php --appliquer     (écrit schema_version_etab)
//           php bd/assoc/recaler_versions.php --ecole=EC1     (une seule école)
//           php bd/assoc/recaler_versions.php --detail        (indices manquants par migration)
//  Lecture seule sur les bases écoles ; seule écriture (avec --appliquer) :
//  la ligne schema_version_etab de l'annuaire.
// =====================================================================
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Script en ligne de commande uniquement.\n"); }

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';

require_once __DIR__ . '/../lib/recalage_versions.php';   // logique partagée avec association/versions.php

$appliquer = in_array('--appliquer', $argv, true);
$detail    = in_array('--detail', $argv, true);
$filtre    = null;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--ecole=')) { $filtre = substr($a, 8); continue; }
    if (!in_array($a, ['--appliquer', '--detail'], true)) exit("Option inconnue « $a » (--appliquer, --detail, --ecole=CODE). Rien n'a été fait.\n");
}

echo "=== Recalage des versions de schéma ===\n", $appliquer ? "MODE APPLICATION\n\n" : "MODE LECTURE (rien n'est modifié — ajoutez --appliquer)\n\n";

foreach (rv_analyser($filtre) as $r) {
    echo str_pad($r['code'], 8), " ", $r['nom'], " ({$r['type']})\n";
    if (!$r['joignable']) { echo "   base injoignable — ignorée\n\n"; continue; }
    echo "   enregistrée : v", $r['actuel'] ?? '—', "   réelle détectée : v{$r['reelle']}",
         $r['a_appliquer'] ? " → à appliquer : v" . implode(', v', $r['a_appliquer']) : " (à jour)", "\n";
    if ($r['partielles']) echo "   ⚠ migration(s) PARTIELLEMENT présente(s) : v", implode(', v', $r['partielles']), " — à examiner avant de migrer\n";
    if ($r['trous'])      echo "   ℹ déjà présentes plus loin (seront rejouées sans effet si idempotentes) : v", implode(', v', $r['trous']), "\n";
    if ($detail) foreach ($r['manque'] as $v => $m) if ($v > $r['reelle']) echo "      v$v manque : ", implode(' ; ', array_slice($m, 0, 4)), count($m) > 4 ? ' …' : '', "\n";
    if ($appliquer && $r['actuel'] !== $r['reelle']) {
        echo rv_recaler($r) ? "   ✔ schema_version_etab : v" . ($r['actuel'] ?? '—') . " → v{$r['reelle']}\n"
                            : "   ✗ NON recalée (migration partielle à examiner d'abord)\n";
    }
    echo "\n";
}
echo "=== Terminé. ===\n";
