<?php
// =====================================================================
//  bd/assoc/rattacher_ecoles_camoo.php
//  Rattache à l'annuaire DEUX bases école DÉJÀ CRÉÉES et DÉJÀ PEUPLÉES
//  chez l'hébergeur (renommées depuis le pool + import phpMyAdmin des
//  données réelles) : COLLEGE MINHADJOUL MOUSLIM (secondaire) et son
//  annexe GSBPI MINHADJOUL MOUSLIM (primaire). Contrairement à
//  « Nouvel établissement », ce script NE TOUCHE PAS au contenu des
//  bases école (pas de CREATE DATABASE, pas de charger_schema_ecole(),
//  pas de creer_comptes_defaut_ecole()) : les données et les comptes y
//  sont déjà présents (copiés depuis l'installation LAN). Il se
//  contente d'inscrire (ou de recaler) la ligne annuaire + la version
//  de schéma, comme le ferait la fin de creer_etablissement().
//
//  Sécurité : en HTTP, jeton obligatoire ?token=<BACKUP_TOKEN>. N'accepte
//  que des db_name commençant par le même préfixe que DB_NAME_ASSOC
//  (ex. « beeroc11073_ »).
//
//  Usage :
//    /bd/assoc/rattacher_ecoles_camoo.php?token=XXX        (aperçu)
//    /bd/assoc/rattacher_ecoles_camoo.php?token=XXX&go=1    (applique)
//    php bd/assoc/rattacher_ecoles_camoo.php [--go]
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
$go = $est_cli ? in_array('--go', $argv ?? [], true) : !empty($_GET['go']);

// Préfixe autorisé (« beeroc11073_ »), déduit de DB_NAME_ASSOC.
$p = strrpos(DB_NAME_ASSOC, '_');
$prefixe = $p !== false ? substr(DB_NAME_ASSOC, 0, $p + 1) : '';
if ($prefixe === '') die("✗ Préfixe indéductible de DB_NAME_ASSOC. Abandon.\n");

if (!annuaire_dispo()) die("✗ Annuaire indisponible.\n");

// ── Les 2 écoles à rattacher (valeurs reprises de l'annuaire LAN) ────
$ECOLES = [
    [
        'code'    => 'MHJ2',
        'nom'     => 'COLLEGE MINHADJOUL MOUSLIM',
        'sigle'   => 'COLLEGE MINHADJOUL MOUSLIM',
        'ville'   => 'NGAOUNDERE',
        'type'    => 'secondaire',
        'db_name' => 'beeroc11073_collegeminhadjoulmouslim',
    ],
    [
        'code'    => 'MHMAN',
        'nom'     => 'GSBPI MINHADJOUL MOUSLIM ANNEXE',
        'sigle'   => 'GSBPI MINHADJ ANNEX',
        'ville'   => 'NGAOUNDERE',
        'type'    => 'primaire',
        'db_name' => 'beeroc11073_gsbpiminhadjoulmouslimannex',
    ],
];

echo "=== Rattachement d'écoles existantes à l'annuaire (préfixe « $prefixe ») ===\n";
echo $go ? "Mode : APPLIQUE\n" : "Mode : APERÇU (ajouter &go=1)\n";
echo str_repeat('-', 66) . "\n";

foreach ($ECOLES as $e) {
    echo "\n▶ {$e['code']} — {$e['nom']} ({$e['type']}) → {$e['db_name']}\n";

    if (strpos($e['db_name'], $prefixe) !== 0) {
        echo "  ✗ « {$e['db_name']} » hors préfixe « $prefixe » — ignoré (sécurité)\n";
        continue;
    }

    // La base doit déjà exister et être peuplée (pas vide, pas absente).
    $l = @mysqli_connect(DB_HOST, DB_USER, DB_PASS, $e['db_name']);
    if (!$l) {
        echo "  ✗ connexion impossible : " . mysqli_connect_error() . " — vérifie que la base existe et que "
           . DB_USER . " y a accès.\n";
        continue;
    }
    mysqli_set_charset($l, 'utf8mb4');
    $nbt = 0;
    if ($r = mysqli_query($l, 'SHOW TABLES')) $nbt = mysqli_num_rows($r);
    if ($nbt < 10) {
        echo "  ✗ base quasi vide ($nbt table(s)) — import des données pas terminé ? ignoré.\n";
        mysqli_close($l);
        continue;
    }
    echo "  État de la base : $nbt table(s) — OK\n";
    mysqli_close($l);

    $existant = assoc_one("SELECT id, db_name FROM etablissement WHERE code=?", [$e['code']]);
    $conflitDb = assoc_one("SELECT id FROM etablissement WHERE db_name=? AND code<>?", [$e['db_name'], $e['code']]);
    if ($conflitDb) {
        echo "  ✗ la base « {$e['db_name']} » est déjà référencée par un autre établissement (#{$conflitDb['id']}) — ignoré.\n";
        continue;
    }

    if (!$go) {
        echo $existant
            ? "  Aperçu : établissement #{$existant['id']} déjà présent — db_name recalée « {$existant['db_name']} » → « {$e['db_name']} » si différente.\n"
            : "  Aperçu : nouvelle ligne annuaire à créer.\n";
        continue;
    }

    if ($existant) {
        assoc_exec(
            "UPDATE etablissement SET db_name=?, nom=?, sigle=?, ville=?, type_enseignement=?, actif=1 WHERE id=?",
            [$e['db_name'], $e['nom'], $e['sigle'], $e['ville'], $e['type'], $existant['id']]
        );
        $id = (int) $existant['id'];
        echo "  ↺ établissement #$id mis à jour (db_name → {$e['db_name']}).\n";
    } else {
        assoc_exec(
            "INSERT INTO etablissement (code, db_name, nom, sigle, ville, actif, type_enseignement)
             VALUES (?, ?, ?, ?, ?, 1, ?)",
            [$e['code'], $e['db_name'], $e['nom'], $e['sigle'], $e['ville'], $e['type']]
        );
        $id = (int) assoc_val("SELECT id FROM etablissement WHERE code=?", [$e['code']]);
        echo "  ✔ établissement #$id créé dans l'annuaire.\n";
    }

    // Version de schéma = dernière migration connue de son type (les
    // bases importées viennent d'une installation LAN déjà à jour).
    $vmax = 0;
    foreach (assoc_migrations_disponibles($e['type']) as $v => $f) { $vmax = max($vmax, $v); }
    assoc_exec(
        "INSERT INTO schema_version_etab (id_etablissement, version) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE version=GREATEST(version, VALUES(version))",
        [$id, $vmax]
    );
    echo "  ✔ schema_version_etab → v$vmax\n";
}

if ($go) {
    assoc_exec("UPDATE membre_acces SET plein_acces = 1 WHERE id_etablissement IS NULL");
    regenerer_portail_accueil_best_effort();
    echo "\n" . str_repeat('=', 66) . "\n";
    echo "Terminé. Ensuite :\n";
    echo "  1. /bd/assoc/migrer_toutes_ecoles.php?dry=1  (vérifier qu'aucune migration ne manque)\n";
    echo "  2. Se connecter sur chaque école (comptes déjà importés) pour valider l'accès.\n";
    echo "  3. SUPPRIMER ce fichier (bd/assoc/rattacher_ecoles_camoo.php).\n";
} else {
    echo "\n(aucune écriture — ajoute &go=1 pour appliquer)\n";
}
