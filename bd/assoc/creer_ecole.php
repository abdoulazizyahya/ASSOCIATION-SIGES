<?php
// =====================================================================
//  bd/assoc/creer_ecole.php
//  Cree une nouvelle ecole : base MySQL + schema de reference + ligne
//  dans l'annuaire + version de schema calee sur la derniere migration.
//
//  Usage :
//    php bd/assoc/creer_ecole.php <code> "<nom>" [sous_domaine] [sigle] [ville]
//  Exemple :
//    php bd/assoc/creer_ecole.php EC2 "Ecole Al Nour" ecole2 ALN Ngaoundere
//
//  La base s'appelle jaynitaare_ecole_<code minuscule>. Idempotent sur
//  l'annuaire (ON DUPLICATE) ; refuse d'ecraser une base existante.
// =====================================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion_assoc.php';

if (PHP_SAPI !== 'cli') { header('Content-Type: text/plain; charset=utf-8'); }
$a = $argv ?? [];

$code = strtoupper(trim($a[1] ?? ($_GET['code'] ?? '')));
$nom  = trim($a[2] ?? ($_GET['nom'] ?? ''));
$sous = trim($a[3] ?? ($_GET['sous_domaine'] ?? '')) ?: null;
$sigle = trim($a[4] ?? ($_GET['sigle'] ?? '')) ?: null;
$ville = trim($a[5] ?? ($_GET['ville'] ?? '')) ?: null;

if (!preg_match('/^[A-Z0-9]{2,10}$/', $code) || $nom === '') {
    die("Usage : php bd/assoc/creer_ecole.php <code A-Z0-9> \"<nom>\" [sous_domaine] [sigle] [ville]\n");
}
if (!annuaire_dispo()) { die("Annuaire absent — lancez bd/assoc/installer.php.\n"); }

$db = 'jaynitaare_ecole_' . strtolower($code);
echo "=== Creation ecole $code -> base $db ===\n";

$srv = mysqli_connect(DB_HOST, DB_USER, DB_PASS);
mysqli_set_charset($srv, 'utf8mb4');

$existe = mysqli_fetch_row(mysqli_query($srv,
    "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='" . mysqli_real_escape_string($srv, $db) . "'"))[0];
if ($existe) { die("ERREUR : la base $db existe deja. Abandon (aucune ecrasure).\n"); }

mysqli_query($srv, "CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
mysqli_select_db($srv, $db);
echo "OK  base creee\n";

$sql = file_get_contents(__DIR__ . '/schema_ref_ecole.sql');
if ($sql === false) { die("ERREUR : schema_ref_ecole.sql introuvable.\n"); }
if (mysqli_multi_query($srv, $sql)) {
    do { /* consommer */ } while (mysqli_next_result($srv));
}
$nb = (int) mysqli_fetch_row(mysqli_query($srv,
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$db'"))[0];
echo "OK  schema charge : $nb tables\n";

// Version de schema = derniere migration connue
$vmax = 0;
foreach (glob(__DIR__ . '/../migration_v*.sql') as $f) {
    if (preg_match('/migration_v(\d+)\.sql$/', $f, $m)) $vmax = max($vmax, (int) $m[1]);
}

// Ligne annuaire
$stmt = mysqli_prepare($link_assoc,
    "INSERT INTO etablissement (code, sous_domaine, db_name, nom, sigle, ville, actif)
     VALUES (?, ?, ?, ?, ?, ?, 1)
     ON DUPLICATE KEY UPDATE nom=VALUES(nom), sigle=VALUES(sigle), ville=VALUES(ville),
                             sous_domaine=VALUES(sous_domaine)");
mysqli_stmt_bind_param($stmt, 'ssssss', $code, $sous, $db, $nom, $sigle, $ville);
mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);
$id = (int) mysqli_fetch_row(mysqli_query($link_assoc, "SELECT id FROM etablissement WHERE code='$code'"))[0];

mysqli_query($link_assoc,
    "INSERT INTO schema_version_etab (id_etablissement, version) VALUES ($id, $vmax)
     ON DUPLICATE KEY UPDATE version=GREATEST(version, VALUES(version))");

echo "OK  annuaire : etablissement #$id ($code), schema_version=$vmax\n";
echo "\n=== Termine. Pensez a creer un premier compte DIRECTEUR (via l'interface\n";
echo "    association > Personnel > Affecter, ou directement en base). ===\n";
