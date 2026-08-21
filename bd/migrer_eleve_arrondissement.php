<?php
// =====================================================================
//  jaynitaare_v2 — bd/migrer_eleve_arrondissement.php
//  Rapproche eleve.arrondissement_elv (texte libre historique) vers
//  eleve.id_arrondissement (FK, ajoutée par bd/migration_v5.sql), main-
//  tenant que la table `arrondissement` est peuplée (360 lignes, voir
//  prompt_continuite_jaynitaare_v2.md).
//
//  Méthode (même principe que bd/sync_arrondissements.php — 2 passes,
//  jamais de suppression) :
//   1. Correspondance EXACTE après normalisation (bd/lib/lieux_
//      normalisation.php), sur l'ENSEMBLE des 360 arrondissements (pas
//      filtré par département — eleve n'a plus de département depuis
//      migration_v5.sql, et aucune collision nationale de libellé n'existe
//      dans le référentiel, vérifié).
//   2. À défaut, correspondance FLOUE (mêmes règles que sync_
//      arrondissements.php — Levenshtein, ≥4 caractères, candidat unique).
//   3. Si résolu → id_arrondissement renseigné, arrondissement_elv VIDÉ
//      (n'est plus nécessaire, l'info est maintenant structurée). Sinon →
//      la fiche est laissée intacte (arrondissement_elv conservé tel
//      quel), signalée dans le rapport — jamais de suppression, jamais de
//      champ vidé sans remplacement trouvé.
//
//  Par défaut : dry-run (aperçu, aucune écriture). --appliquer pour
//  exécuter réellement. Sauvegardez d'abord (mysqldump eleve).
//
//  Usage : php bd/migrer_eleve_arrondissement.php [--appliquer] [--seuil=2]
// =====================================================================

if (PHP_SAPI !== 'cli') {
    die("Ce script s'exécute uniquement en ligne de commande :\n  php bd/migrer_eleve_arrondissement.php [--appliquer]\n");
}

require __DIR__ . '/../config.php';
require __DIR__ . '/../connexion.php';
require __DIR__ . '/lib/lieux_normalisation.php';

$options   = getopt('', ['appliquer', 'seuil::']);
$appliquer = array_key_exists('appliquer', $options);
$seuil     = isset($options['seuil']) ? (int) $options['seuil'] : 2;

echo "════════════════════════════════════════════════════════\n";
echo $appliquer
    ? " MODE APPLICATION — écriture réelle en base.\n   (sauvegarde préalable recommandée — mysqldump eleve)\n"
    : " MODE APERÇU (dry-run) — aucune écriture en base.\n   Relancez avec --appliquer pour exécuter réellement.\n";
echo "════════════════════════════════════════════════════════\n\n";

$candidats = db_all("SELECT code_arrond AS id, intitule_arrond AS nom FROM arrondissement");
if (!$candidats) {
    echo "Table `arrondissement` vide — rien à faire.\n";
    exit(1);
}

$eleves = db_all(
    "SELECT id_eleve, Mat_elv, Nom_elv, arrondissement_elv
     FROM eleve
     WHERE id_arrondissement IS NULL AND arrondissement_elv IS NOT NULL AND arrondissement_elv <> ''
     ORDER BY id_eleve"
);

$compte = ['exact' => 0, 'flou' => 0, 'non_resolus' => [], 'ambigus' => []];

foreach ($eleves as $e) {
    $res = lieu_trouver_correspondance($e['arrondissement_elv'], $candidats, $seuil);

    if ($res['statut'] === 'ambigu') {
        $compte['ambigus'][] = "#{$e['id_eleve']} ({$e['Mat_elv']}) « {$e['arrondissement_elv']} » — AMBIGU entre " .
            implode(', ', array_column($res['concurrents'], 'nom'));
        continue;
    }
    if (!$res['candidat']) {
        $compte['non_resolus'][] = "#{$e['id_eleve']} ({$e['Mat_elv']}) « {$e['arrondissement_elv']} »";
        continue;
    }

    $compte[$res['statut']]++;
    $marque = $res['statut'] === 'flou' ? " [flou, distance {$res['distance']}]" : '';
    echo ($appliquer ? '  + lié   : ' : '  + à lier : ') .
        "#{$e['id_eleve']} {$e['Mat_elv']} : « {$e['arrondissement_elv']} » → {$res['candidat']['nom']}$marque\n";

    if ($appliquer) {
        db_exec(
            "UPDATE eleve SET id_arrondissement=?, arrondissement_elv=NULL WHERE id_eleve=?",
            [(int) $res['candidat']['id'], $e['id_eleve']]
        );
    }
}

echo "\n════════════════════════════════════════════════════════\n";
echo " RAPPORT\n";
echo "════════════════════════════════════════════════════════\n";
echo "Fiches examinées (arrondissement_elv non vide, pas déjà liées) : " . count($eleves) . "\n";
echo "Résolues exactement                    : {$compte['exact']}\n";
echo "Résolues par correspondance floue      : {$compte['flou']}\n";
echo "Non résolues (laissées telles quelles) : " . count($compte['non_resolus']) . "\n";
foreach ($compte['non_resolus'] as $l) echo "   - $l\n";
echo "Ambiguës (laissées telles quelles)     : " . count($compte['ambigus']) . "\n";
foreach ($compte['ambigus'] as $l) echo "   - $l\n";

if (!$appliquer && ($compte['exact'] + $compte['flou']) > 0) {
    echo "\nRelancez avec --appliquer pour lier réellement ces " . ($compte['exact'] + $compte['flou']) . " fiche(s).\n";
}
echo "\nRien n'est supprimé pour les lignes non résolues/ambiguës ci-dessus — leur arrondissement_elv\n";
echo "reste tel quel (texte libre), à corriger manuellement ou à relancer plus tard si besoin.\n";
echo "\n✅ Terminé.\n";
