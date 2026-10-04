<?php
// =====================================================================
//  bd/assoc/installer.php
//  Installe / répare une application SIGES :
//    0. crée la BASE DE L'ÉCOLE N°1 (DB_NAME) si elle est absente ou vide :
//       schéma de référence + données de référence + classes standard +
//       première année scolaire + barème + un compte DIRECTEUR pour se
//       connecter ;
//    1. crée la base centrale « annuaire » (DB_NAME_ASSOC) + ses tables ;
//    2. y enregistre l'école n°1 (EC1) ;
//    3. cale schema_version_etab(EC1) sur la dernière migration connue ;
//    4. crée un compte membre « admin » (propriétaire) si aucun n'existe.
//
//  Idempotent : relançable sans risque.
//
//  Usage :  php bd/assoc/installer.php [mot_de_passe_admin]
//  (défaut du mot de passe : « association » — À CHANGER ensuite ;
//   le même mot de passe sert au 1er compte DIRECTEUR « admin » de l'école)
// =====================================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion_assoc.php';   // charger_schema_ecole()

$est_cli = (PHP_SAPI === 'cli');
if (!$est_cli) header('Content-Type: text/plain; charset=utf-8');
$nl = "\n";

function out(string $s) { echo $s . "\n"; }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$pwd_admin = $est_cli
    ? ($argv[1] ?? 'association')
    : ($_GET['pwd'] ?? 'association');

out("=== Installation SIGES ===\n");

$srv = mysqli_connect(DB_HOST, DB_USER, DB_PASS);
mysqli_set_charset($srv, 'utf8mb4');

// ── 0. Base de l'école n°1 (DB_NAME) ───────────────────────────────
//  Créée si absente, ou peuplée si présente mais vide. Sur une base déjà
//  garnie (colonne `etablissement` existante), on ne touche à rien.
$db_ecole   = DB_NAME;
$db_ecole_e = mysqli_real_escape_string($srv, $db_ecole);
$existe = (int) mysqli_fetch_row(mysqli_query($srv,
    "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='$db_ecole_e'"))[0];
$a_etab = 0;
if ($existe) {
    $a_etab = (int) mysqli_fetch_row(mysqli_query($srv,
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema='$db_ecole_e' AND table_name='etablissement'"))[0];
}

if (!$a_etab) {
    if (!$existe) {
        mysqli_query($srv, "CREATE DATABASE `" . str_replace('`', '', $db_ecole)
                         . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        out("OK  Base école « $db_ecole » créée.");
    }
    $le = mysqli_connect(DB_HOST, DB_USER, DB_PASS, $db_ecole);
    mysqli_set_charset($le, 'utf8mb4');

    // Schéma de référence + données de référence + classes standard + 1re
    // année + barème (charger_schema_ecole -> charger_seed_ref_ecole ->
    // provisionner_ecole_neuve).
    $nb_tables = charger_schema_ecole($le, [
        'nom'    => 'École n°1',
        'nom_en' => null,
        'sigle'  => null,
        'ville'  => null,
    ]);
    out("OK  École n°1 initialisée ($nb_tables tables : schéma + référence + classes + année + barème).");

    // Compte DIRECTEUR « admin » pour se connecter à l'école (login.php
    // joint `user` -> `enseignant`).
    if (!(int) mysqli_fetch_row(mysqli_query($le, "SELECT COUNT(*) FROM user"))[0]) {
        mysqli_query($le,
            "INSERT INTO enseignant (nom_ens, prenom_ens, id_fonction, statut_ens)
             VALUES ('Directeur', 'Général', 'DIRECTEUR', 'actif')");
        $mat = (int) mysqli_insert_id($le);
        $st  = mysqli_prepare($le,
            "INSERT INTO user (login_user, pwd_user, matricule_ens) VALUES ('admin', ?, ?)");
        $hash_dir = password_hash($pwd_admin, PASSWORD_DEFAULT);
        mysqli_stmt_bind_param($st, 'si', $hash_dir, $mat);
        mysqli_stmt_execute($st);
        mysqli_stmt_close($st);
        out("OK  Compte DIRECTEUR école créé : login « admin » / mot de passe « $pwd_admin »");
    }
    mysqli_close($le);
} else {
    out("--  Base école « $db_ecole » déjà garnie — étape 0 ignorée.");
}

out("");
out("=== Base centrale « annuaire » (DB_NAME_ASSOC) ===\n");

// ── 1. Schéma ──────────────────────────────────────────────────────

$sql = file_get_contents(__DIR__ . '/schema_assoc.sql');
if ($sql === false) { out('ERREUR : schema_assoc.sql introuvable.'); exit(1); }
// La base annuaire suit la constante DB_NAME_ASSOC (le SQL porte un
// marqueur {{DB_NAME_ASSOC}} pour rester indépendant du nom).
$sql = str_replace('{{DB_NAME_ASSOC}}', DB_NAME_ASSOC, $sql);

if (mysqli_multi_query($srv, $sql)) {
    do { if ($r = mysqli_store_result($srv)) mysqli_free_result($r); } while (mysqli_more_results($srv) && mysqli_next_result($srv));
}
out('OK  Base + tables créées (schema_assoc.sql).');

$assoc = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME_ASSOC);
mysqli_set_charset($assoc, 'utf8mb4');

// ── 2. École n°1 (EC1) depuis la base actuelle ─────────────────────
$src = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
mysqli_set_charset($src, 'utf8mb4');
$res  = mysqli_query($src, "SELECT Nom_Etab_Fr, Initial_Etab, ville_etab, logo FROM etablissement LIMIT 1");
$etab = mysqli_fetch_assoc($res) ?: [];
mysqli_close($src);

$nom   = $etab['Nom_Etab_Fr']  ?: 'École n°1';
$sigle = $etab['Initial_Etab'] ?: null;
$ville = $etab['ville_etab']   ?: null;
$logo  = $etab['logo']         ?: null;

$stmt = mysqli_prepare($assoc,
    "INSERT INTO etablissement (code, sous_domaine, db_name, nom, sigle, ville, logo, actif)
     VALUES ('EC1', NULL, ?, ?, ?, ?, ?, 1)
     ON DUPLICATE KEY UPDATE nom=VALUES(nom), sigle=VALUES(sigle),
                             ville=VALUES(ville), logo=VALUES(logo)");
$dbn = DB_NAME;
mysqli_stmt_bind_param($stmt, 'sssss', $dbn, $nom, $sigle, $ville, $logo);
mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

$id_ec1 = (int) mysqli_fetch_row(mysqli_query($assoc,
    "SELECT id FROM etablissement WHERE code='EC1'"))[0];
out("OK  École n°1 enregistrée : #$id_ec1 — « $nom » (base $dbn).");

// ── 3. Version de schéma de référence ─────────────────────────────
//  Dernière migration présente dans bd/migration_v*.sql
$vmax = 0;
foreach (glob(__DIR__ . '/../migration_v*.sql') as $f) {
    if (preg_match('/migration_v(\d+)\.sql$/', $f, $m)) $vmax = max($vmax, (int) $m[1]);
}
mysqli_query($assoc,
    "INSERT INTO schema_version_etab (id_etablissement, version)
     VALUES ($id_ec1, $vmax)
     ON DUPLICATE KEY UPDATE version=GREATEST(version, VALUES(version))");
out("OK  schema_version_etab(EC1) = $vmax.");

// ── 4. Compte membre initial ─────────────────────────────────────
$nb_membres = (int) mysqli_fetch_row(mysqli_query($assoc, "SELECT COUNT(*) FROM membre"))[0];
if ($nb_membres === 0) {
    $hash = password_hash($pwd_admin, PASSWORD_DEFAULT);
    // proprietaire=1 : ce 1er compte est le PROPRIÉTAIRE de l'association —
    // seul habilité à accorder/retirer le niveau superadmin à d'autres
    // membres (cf. ecole_contexte.php::est_proprietaire_association()).
    $stmt = mysqli_prepare($assoc,
        "INSERT INTO membre (login, pwd_hash, nom, prenom, actif, proprietaire)
         VALUES ('admin', ?, 'Administrateur', 'Association', 1, 1)");
    mysqli_stmt_bind_param($stmt, 's', $hash);
    mysqli_stmt_execute($stmt);
    $id_membre = mysqli_stmt_insert_id($stmt);
    mysqli_stmt_close($stmt);
    // plein_acces=1 + id_etablissement NULL : ce 1er compte est aussi le
    // SUPERADMIN de l'association (crée les écoles, affecte les agents,
    // frappe les NIU — cf. ecole_contexte.php::est_superadmin_association()).
    mysqli_query($assoc,
        "INSERT INTO membre_acces (id_membre, id_etablissement, plein_acces, actif)
         VALUES ($id_membre, NULL, 1, 1)");
    out("OK  Compte membre créé : login « admin » / mot de passe « $pwd_admin »");
    out("    ⚠  Changez ce mot de passe après la première connexion.");
} else {
    out("--  $nb_membres compte(s) membre déjà présent(s) — création ignorée.");
}

out("\n=== Terminé. ===");
