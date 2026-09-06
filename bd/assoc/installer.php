<?php
// =====================================================================
//  bd/assoc/installer.php
//  Met en place la base centrale « annuaire » (DB_NAME_ASSOC, défaut
//  promeducam_assoc) :
//    1. crée la base + les tables (bd/assoc/schema_assoc.sql) ;
//    2. enregistre l'école n°1 (EC1) à partir de la ligne `etablissement`
//       de la base actuelle (DB_NAME) ;
//    3. cale schema_version_etab(EC1) sur la dernière migration connue ;
//    4. crée un compte membre « admin » si aucun membre n'existe.
//
//  Idempotent : relançable sans risque (INSERT ... ON DUPLICATE / IGNORE,
//  CREATE TABLE IF NOT EXISTS).
//
//  Usage :  php bd/assoc/installer.php [mot_de_passe_admin]
//  (défaut du mot de passe : « association » — À CHANGER ensuite)
// =====================================================================

require_once __DIR__ . '/../../config.php';

$est_cli = (PHP_SAPI === 'cli');
if (!$est_cli) header('Content-Type: text/plain; charset=utf-8');
$nl = "\n";

function out(string $s) { echo $s . "\n"; }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$pwd_admin = $est_cli
    ? ($argv[1] ?? 'association')
    : ($_GET['pwd'] ?? 'association');

out("=== Installation de la base centrale « annuaire » (DB_NAME_ASSOC) ===\n");

// ── 1. Schéma ──────────────────────────────────────────────────────
$srv = mysqli_connect(DB_HOST, DB_USER, DB_PASS);
mysqli_set_charset($srv, 'utf8mb4');

$sql = file_get_contents(__DIR__ . '/schema_assoc.sql');
if ($sql === false) { out('ERREUR : schema_assoc.sql introuvable.'); exit(1); }
// La base annuaire suit la constante DB_NAME_ASSOC (le SQL porte un
// marqueur {{DB_NAME_ASSOC}} pour rester indépendant du nom).
$sql = str_replace('{{DB_NAME_ASSOC}}', DB_NAME_ASSOC, $sql);

if (mysqli_multi_query($srv, $sql)) {
    do { /* consommer tous les jeux de résultats */ } while (mysqli_next_result($srv));
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
    $stmt = mysqli_prepare($assoc,
        "INSERT INTO membre (login, pwd_hash, nom, prenom, actif)
         VALUES ('admin', ?, 'Administrateur', 'Association', 1)");
    mysqli_stmt_bind_param($stmt, 's', $hash);
    mysqli_stmt_execute($stmt);
    $id_membre = mysqli_stmt_insert_id($stmt);
    mysqli_stmt_close($stmt);
    // plein_acces=1 + id_etablissement NULL : ce 1er compte est le
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
