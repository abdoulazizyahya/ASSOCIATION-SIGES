<?php
// =====================================================================
//  jaynitaare_v2 — bd/sync_arrondissements.php
//  Script de synchronisation réutilisable : importe la liste officielle
//  des arrondissements (CSV/Excel exporté en CSV) dans la table
//  `arrondissement`, sans jamais dupliquer ni supprimer automatiquement.
//
//  Usage (ligne de commande uniquement) :
//      php bd/sync_arrondissements.php --fichier=chemin.csv [--appliquer] [--seuil=2]
//
//  Colonnes attendues dans le CSV (en-tête, insensible aux accents/casse) :
//      departement ; arrondissement   (une colonne "region" est tolérée
//      mais non utilisée — le département suffit à retrouver la région
//      par jointure, cf. le principe "jamais de duplication d'info").
//  Délimiteur ; ou , auto-détecté sur la première ligne.
//
//  Méthode (2 passes, jamais de suppression automatique) :
//   1. Résolution du département : correspondance EXACTE après
//      normalisation (accents retirés via table manuelle strtr — pas
//      iconv, peu fiable selon plateforme/forme Unicode source — et
//      ordinaux romains ramenés en chiffres arabes, cf. bd/lib/
//      lieux_normalisation.php). À défaut, correspondance FLOUE
//      (Levenshtein, ≥4 caractères, uniquement si un seul candidat est
//      sous le seuil). Département introuvable/ambigu → ligne signalée,
//      ignorée (jamais de département deviné).
//   2. Résolution de l'arrondissement PARMI CEUX DÉJÀ EN BASE pour ce
//      département (même méthode) : déjà présent → rien à faire (jamais
//      de mise à jour silencieuse d'un nom existant, même approchant) ;
//      ambigu → signalé, ignoré ; sinon → nouvelle ligne à créer.
//   3. Par défaut (sans --appliquer) : dry-run, rapport seul, AUCUNE
//      écriture. Avec --appliquer : écriture réelle. Sauvegarder
//      d'abord, exemple : mysqldump jaynitaare_v2_bd arrondissement
//      departement region > backup_lieux.sql
//
//  Rien n'est jamais supprimé par ce script, à aucun moment — les libellés
//  qui ne correspondent à rien (ni exact ni flou) sont seulement listés
//  dans le rapport ; la décision (créer manuellement ? corriger le CSV ?
//  ignorer ?) reste humaine.
// =====================================================================

if (PHP_SAPI !== 'cli') {
    die("Ce script s'exécute uniquement en ligne de commande :\n  php bd/sync_arrondissements.php --fichier=chemin.csv [--appliquer]\n");
}

require __DIR__ . '/../config.php';
require __DIR__ . '/../connexion.php';
require __DIR__ . '/lib/lieux_normalisation.php';

$options   = getopt('', ['fichier:', 'appliquer', 'seuil::']);
$fichier   = $options['fichier'] ?? null;
$appliquer = array_key_exists('appliquer', $options);
$seuil     = isset($options['seuil']) ? (int) $options['seuil'] : 2;

if (!$fichier || !is_file($fichier)) {
    echo "Usage : php bd/sync_arrondissements.php --fichier=chemin.csv [--appliquer] [--seuil=2]\n";
    echo "  --fichier    Chemin du CSV (colonnes : departement, arrondissement)\n";
    echo "  --appliquer  Sans cette option : aperçu seul (dry-run), aucune écriture\n";
    echo "  --seuil      Distance de Levenshtein max pour la correspondance floue (défaut 2)\n";
    if ($fichier) echo "\nFichier introuvable : $fichier\n";
    exit(1);
}

echo "════════════════════════════════════════════════════════\n";
echo $appliquer
    ? " MODE APPLICATION — écriture réelle en base.\n   (sauvegarde préalable recommandée — voir en-tête du script)\n"
    : " MODE APERÇU (dry-run) — aucune écriture en base.\n   Relancez avec --appliquer pour exécuter réellement.\n";
echo "════════════════════════════════════════════════════════\n\n";

// ── Lecture CSV (BOM UTF-8 + délimiteur auto-détecté) ───────────────
$brut = file_get_contents($fichier);
if ($brut === false) { echo "Impossible de lire le fichier.\n"; exit(1); }
if (substr($brut, 0, 3) === "\xEF\xBB\xBF") $brut = substr($brut, 3); // BOM Excel
$premiere_ligne = strtok($brut, "\r\n");
$delimiteur     = substr_count($premiere_ligne, ';') > substr_count($premiere_ligne, ',') ? ';' : ',';

$handle = fopen('php://memory', 'r+');
fwrite($handle, $brut);
rewind($handle);

$entetes = fgetcsv($handle, 0, $delimiteur);
if (!$entetes) { echo "Fichier vide ou illisible.\n"; exit(1); }
$entetes_norm = array_map(fn($h) => lieu_normaliser((string) $h), $entetes);
$idx_dept = array_search('departement', $entetes_norm, true);
$idx_arr  = array_search('arrondissement', $entetes_norm, true);
if ($idx_dept === false || $idx_arr === false) {
    echo "Colonnes attendues introuvables — il faut au moins 'departement' et 'arrondissement' en en-tête.\n";
    echo "En-têtes trouvées : " . implode(', ', $entetes) . "\n";
    exit(1);
}

// ── Référentiel département en mémoire (une seule lecture) ─────────
$departements = db_all("SELECT code_depart AS id, intitule_depart AS nom FROM departement");

$compte = ['deja' => 0, 'crees' => 0, 'dept_non_resolus' => [], 'ambigus' => []];
// Doublons EXACTS au sein même du CSV (ex. saisi deux fois) : suivis en
// mémoire pour un rapport juste même en dry-run, où rien n'est encore écrit
// et où une simple relecture de la base ne verrait donc pas les lignes
// précédentes du même passage.
$planifies = [];
$ligne_num = 1;

while (($ligne = fgetcsv($handle, 0, $delimiteur)) !== false) {
    $ligne_num++;
    $texte_dept = trim((string) ($ligne[$idx_dept] ?? ''));
    $texte_arr  = trim((string) ($ligne[$idx_arr] ?? ''));
    if ($texte_arr === '') continue; // rien à importer sur cette ligne (nom d'arrondissement vide)

    // 1) Résoudre le département
    $res_dept = lieu_trouver_correspondance($texte_dept, $departements, $seuil);
    if (!$res_dept['candidat']) {
        $detail = $res_dept['statut'] === 'ambigu'
            ? ' — AMBIGU entre ' . implode(', ', array_column($res_dept['concurrents'], 'nom'))
            : '';
        $compte['dept_non_resolus'][] = "L$ligne_num : département « $texte_dept » introuvable (arrondissement « $texte_arr »)$detail";
        continue;
    }
    $code_depart = (int) $res_dept['candidat']['id'];

    // Déjà planifié plus haut dans ce même CSV ?
    $cle_norm = $code_depart . '|' . lieu_normaliser($texte_arr);
    if (isset($planifies[$cle_norm])) {
        $compte['deja']++;
        continue;
    }

    // 2) Résoudre l'arrondissement PARMI CEUX DÉJÀ EN BASE pour ce département
    $existants = db_all("SELECT code_arrond AS id, intitule_arrond AS nom FROM arrondissement WHERE code_depart=?", [$code_depart]);
    $res_arr   = lieu_trouver_correspondance($texte_arr, $existants, $seuil);

    if ($res_arr['statut'] === 'ambigu') {
        $compte['ambigus'][] = "L$ligne_num : arrondissement « $texte_arr » (dépt. {$res_dept['candidat']['nom']}) — AMBIGU entre " .
            implode(', ', array_column($res_arr['concurrents'], 'nom'));
        continue;
    }
    if ($res_arr['candidat']) {
        $compte['deja']++;
        continue; // déjà présent (exact ou coquille reconnue) — jamais réécrit silencieusement
    }

    // 3) Aucune correspondance : nouvel arrondissement
    $compte['crees']++;
    $planifies[$cle_norm] = true;
    echo ($appliquer ? '  + créé  : ' : '  + à créer : ') . "{$res_dept['candidat']['nom']} > $texte_arr\n";
    if ($appliquer) {
        db_exec(
            "INSERT INTO arrondissement (intitule_arrond, code_depart) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE intitule_arrond = intitule_arrond", // no-op de sécurité (idempotence)
            [$texte_arr, $code_depart]
        );
    }
}
fclose($handle);

// ── Rapport ──────────────────────────────────────────────────────
echo "\n════════════════════════════════════════════════════════\n";
echo " RAPPORT\n";
echo "════════════════════════════════════════════════════════\n";
echo "Déjà présents (inchangés)              : {$compte['deja']}\n";
echo ($appliquer ? "Créés" : "À créer") . "                                  : {$compte['crees']}\n";
echo "Départements non résolus (ignorés)     : " . count($compte['dept_non_resolus']) . "\n";
foreach ($compte['dept_non_resolus'] as $l) echo "   - $l\n";
echo "Arrondissements ambigus (ignorés)      : " . count($compte['ambigus']) . "\n";
foreach ($compte['ambigus'] as $l) echo "   - $l\n";

if (!$appliquer && $compte['crees'] > 0) {
    echo "\nRelancez avec --appliquer pour créer réellement ces {$compte['crees']} arrondissement(s).\n";
}
if ($compte['dept_non_resolus'] || $compte['ambigus']) {
    echo "\nRien n'est supprimé ni deviné automatiquement pour les lignes ci-dessus — corrigez le CSV\n";
    echo "ou traitez-les manuellement, puis relancez le script (il ne recréera pas les doublons).\n";
}
echo "\n✅ Terminé.\n";
