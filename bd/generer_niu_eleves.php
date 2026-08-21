<?php
// =====================================================================
//  jaynitaare_v2 — bd/generer_niu_eleves.php
//  Attribution rétroactive, EN UNE FOIS, d'un NIU (fonctions.php::gen_niu())
//  à tous les élèves actuellement en base qui n'en ont pas encore, en
//  considérant l'année scolaire 2026/2027 (PAS l'année active réelle en
//  base, encore 2025/2026 au moment d'écrire ce script — demande
//  explicite : ce lot doit porter le code année "26" de 2026/2027,
//  indépendamment de l'année scolaire active du moment).
//
//  Numérotation : séquentielle sur 4 chiffres (0001, 0002, ...), dans
//  l'ordre id_eleve croissant (ordre d'enregistrement — eleve n'a pas de
//  colonne date de création), en reprenant après le plus haut numéro déjà
//  utilisé sur ce préfixe s'il y en a (même logique MAX() que gen_niu(),
//  jamais de COUNT() qui se déciderait mal après une suppression).
//
//  Idempotent : ignore les élèves qui ont déjà un NIU (relancer le script
//  ne touche donc que les nouveaux arrivants sans NIU, sans jamais écraser
//  un NIU déjà attribué — officiel ou déjà généré).
//
//  Par défaut : dry-run (aperçu, aucune écriture). --appliquer pour
//  exécuter réellement. Sauvegardez d'abord (mysqldump eleve).
//
//  Usage : php bd/generer_niu_eleves.php [--appliquer] [--annee=2026/2027]
// =====================================================================

if (PHP_SAPI !== 'cli') {
    die("Ce script s'exécute uniquement en ligne de commande :\n  php bd/generer_niu_eleves.php [--appliquer]\n");
}

require __DIR__ . '/../config.php';
require __DIR__ . '/../connexion.php';
require __DIR__ . '/../fonctions.php';

$options   = getopt('', ['appliquer', 'annee::']);
$appliquer = array_key_exists('appliquer', $options);
$val_annee = $options['annee'] ?? '2026/2027';

if (!preg_match('#^\d{4}/\d{4}$#', $val_annee)) {
    die("Format d'année invalide : \"$val_annee\" (attendu AAAA/AAAA, ex. 2026/2027).\n");
}

echo "════════════════════════════════════════════════════════\n";
echo $appliquer
    ? " MODE APPLICATION — écriture réelle en base.\n   (sauvegarde préalable recommandée — mysqldump eleve)\n"
    : " MODE APERÇU (dry-run) — aucune écriture en base.\n   Relancez avec --appliquer pour exécuter réellement.\n";
echo " Année scolaire considérée pour le code NIU : $val_annee\n";
echo "════════════════════════════════════════════════════════\n\n";

// Même construction de préfixe que fonctions.php::gen_niu(), mais avec
// l'année FIGÉE ci-dessus plutôt que l'année scolaire active en base.
$initial_etab = get_etablissement()['Initial_Etab'] ?? '';
$code_an      = substr(explode('/', $val_annee)[0] ?: $val_annee, 2, 2);
$base         = 'PMC' . strtoupper(trim($initial_etab)) . $code_an;
echo "Préfixe utilisé : $base + NNNN\n\n";

// Reprend après le plus haut numéro déjà utilisé sur ce préfixe (0 si aucun).
$max = (int) db_val(
    "SELECT MAX(CAST(SUBSTRING(niu, ?) AS UNSIGNED)) FROM eleve WHERE niu REGEXP ?",
    [strlen($base) + 1, '^' . preg_quote($base) . '[0-9]{4}$']
);

$eleves = db_all(
    "SELECT id_eleve, Mat_elv, Nom_elv, Prenom_elv FROM eleve
     WHERE niu IS NULL OR niu = ''
     ORDER BY id_eleve"
);

if (!$eleves) {
    echo "Aucun élève sans NIU — rien à faire.\n";
    exit(0);
}

echo "Élèves sans NIU à traiter : " . count($eleves) . "\n\n";

$attribues = 0;
foreach ($eleves as $e) {
    $max++;
    $niu = $base . str_pad((string) $max, 4, '0', STR_PAD_LEFT);
    $nom_complet = trim($e['Nom_elv'] . ' ' . $e['Prenom_elv']);
    echo ($appliquer ? '  + attribué : ' : '  + à attribuer : ') .
        "#{$e['id_eleve']} {$e['Mat_elv']} ($nom_complet) → $niu\n";

    if ($appliquer) {
        db_exec("UPDATE eleve SET niu=? WHERE id_eleve=?", [$niu, (int) $e['id_eleve']]);
    }
    $attribues++;
}

echo "\n════════════════════════════════════════════════════════\n";
echo " RAPPORT\n";
echo "════════════════════════════════════════════════════════\n";
echo "Élèves traités : $attribues\n";
echo $appliquer
    ? "NIU écrits en base, du {$base}" . str_pad((string) ($max - $attribues + 1), 4, '0', STR_PAD_LEFT) .
      " au {$base}" . str_pad((string) $max, 4, '0', STR_PAD_LEFT) . ".\n"
    : "\nRelancez avec --appliquer pour écrire réellement ces $attribues NIU en base.\n";
echo "\n✅ Terminé.\n";
