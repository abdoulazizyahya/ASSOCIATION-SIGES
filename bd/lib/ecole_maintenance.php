<?php
// bd/lib/ecole_maintenance.php — Opérations de maintenance sur la base
// d'UNE école, pilotées depuis l'interface association (superadmin) :
//
//   ecole_dump_sql($db, $w)         : dump logique SQL, écrit via un
//                                     callable $w(string) (flux HTTP).
//   ecole_dump_vers_fichier($db,$f) : idem, écrit dans un fichier
//                                     (.sql ou .sql.gz — gzip auto d'après
//                                     l'extension, ou forcé par $gzip).
//   ecole_importer_sql($db, $sql)   : REMPLACE tout le contenu de la base
//                                     par le dump fourni. Backup de
//                                     sécurité écrit avant toute destruction.
//   ecole_vider($id_etab)           : remet la base à l'état « école
//                                     neuve » (schéma de référence
//                                     rechargé, aucune donnée). Backup
//                                     de sécurité écrit avant.
//
// La suppression complète d'une école (base + annuaire) reste dans
// connexion_assoc.php::supprimer_etablissement().
//
// Toutes les opérations destructrices refusent les bases protégées
// (_bases_protegees() : information_schema, mysql, DB_NAME, DB_NAME_ASSOC…).

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion_assoc.php';

const ECOLE_MAINT_DIR_BACKUP = __DIR__ . '/../sauvegardes';

// ── Garde-fous ─────────────────────────────────────────────────────
// Bases SYSTÈME jamais touchées par ces opérations. Volontairement plus
// restreint que _bases_protegees() : DB_NAME n'y figure PAS, car en
// mono→multi la base historique (promeducam_jaynitaare) EST l'école n°1
// de l'annuaire et doit rester exportable / sauvegardable / restaurable.
// La destruction complète (supprimer_etablissement) garde, elle, la
// protection _bases_protegees() de connexion_assoc.php.
function ecole_maint_bases_systeme(): array {
    $p = ['information_schema', 'mysql', 'performance_schema', 'sys', 'phpmyadmin'];
    if (defined('DB_NAME_ASSOC')) $p[] = DB_NAME_ASSOC;
    return array_map('strtolower', $p);
}

// Valide le nom et refuse les bases système. NE vérifie PAS l'existence.
function ecole_maint_garde_nom(string $db): void {
    $db = trim($db);
    if ($db === '' || !preg_match('/^[A-Za-z0-9_]+$/', $db)) {
        throw new RuntimeException("Nom de base invalide : « $db ».");
    }
    if (in_array(strtolower($db), ecole_maint_bases_systeme(), true)) {
        throw new RuntimeException("Base « $db » système (annuaire / MySQL) — opération refusée.");
    }
}

// État d'une base sur le serveur : ['existe'=>bool, 'tables'=>int, 'mo'=>float].
function ecole_base_etat(string $db): array {
    $srv = mysqli_connect(DB_HOST, DB_USER, DB_PASS);
    mysqli_set_charset($srv, 'utf8mb4');
    $q = mysqli_escape_string($srv, $db);
    $existe = (int) mysqli_fetch_row(mysqli_query($srv,
        "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='$q'"))[0] > 0;
    $tables = 0; $mo = 0.0;
    if ($existe) {
        $r = mysqli_query($srv,
            "SELECT COUNT(*) t, COALESCE(ROUND(SUM(data_length+index_length)/1024/1024,1),0) mo
             FROM information_schema.tables WHERE table_schema='$q'");
        if ($r && ($row = mysqli_fetch_assoc($r))) { $tables = (int) $row['t']; $mo = (float) $row['mo']; }
    }
    mysqli_close($srv);
    return ['existe' => $existe, 'tables' => $tables, 'mo' => $mo];
}

// Garde des opérations destructrices : nom valide, non protégée, ET existante.
function ecole_maint_garde_base(string $db): void {
    ecole_maint_garde_nom($db);
    if (!ecole_base_etat($db)['existe']) {
        throw new RuntimeException("La base « $db » n'existe pas sur le serveur.");
    }
}

function ecole_maint_lien(string $db): mysqli {
    $l = mysqli_connect(DB_HOST, DB_USER, DB_PASS, $db);
    mysqli_set_charset($l, 'utf8mb4');
    return $l;
}

// Répertoire des sauvegardes (créé si absent).
function ecole_maint_dir_backup(): string {
    $dir = ECOLE_MAINT_DIR_BACKUP;
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

// ── Dump logique, écrit via un callable $w(string) ─────────────────
//  Reprend la logique éprouvée de bd/assoc/sauvegarder_php.php
//  (structure + données, écriture au fil de l'eau, vues après les tables).
function ecole_dump_sql(string $db, callable $w): void {
    $l = ecole_maint_lien($db);
    try {
        $w("-- Export SIGES de `$db` — " . date('c') . " (bd/lib/ecole_maintenance.php)\n\n");
        $w("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

        $tables = [];
        $r = mysqli_query($l, "SELECT table_name AS n, table_type AS t FROM information_schema.tables
                               WHERE table_schema = DATABASE() ORDER BY table_name");
        while ($row = mysqli_fetch_assoc($r)) $tables[$row['n']] = $row['t'];

        // 1) Tables : structure + données
        foreach ($tables as $t => $type) {
            if ($type === 'VIEW') continue;

            $cr = mysqli_fetch_assoc(mysqli_query($l, "SHOW CREATE TABLE `$t`"));
            $w("\n-- --------------------------------------------------------\n");
            $w("DROP TABLE IF EXISTS `$t`;\n" . ($cr['Create Table'] ?? '') . ";\n\n");

            $cn = mysqli_query($l, "SELECT column_name FROM information_schema.columns
                                    WHERE table_schema = DATABASE() AND table_name = '"
                                    . mysqli_real_escape_string($l, $t) . "' ORDER BY ordinal_position");
            $noms = [];
            while ($c = mysqli_fetch_row($cn)) $noms[] = $c[0];
            $cols = '`' . implode('`,`', $noms) . '`';

            $res = mysqli_query($l, "SELECT * FROM `$t`", MYSQLI_USE_RESULT);
            $buf = []; $len = 0;
            while ($row = mysqli_fetch_row($res)) {
                $vals = [];
                foreach ($row as $v) {
                    $vals[] = ($v === null) ? 'NULL' : "'" . mysqli_real_escape_string($l, $v) . "'";
                }
                $tuple = '(' . implode(',', $vals) . ')';
                $buf[] = $tuple; $len += strlen($tuple);
                if ($len > 256 * 1024) {
                    $w("INSERT INTO `$t` ($cols) VALUES " . implode(",\n", $buf) . ";\n");
                    $buf = []; $len = 0;
                }
            }
            mysqli_free_result($res);
            if ($buf) $w("INSERT INTO `$t` ($cols) VALUES " . implode(",\n", $buf) . ";\n");
        }

        // 2) Vues (après les tables), DEFINER retiré (non portable)
        foreach ($tables as $t => $type) {
            if ($type !== 'VIEW') continue;
            $cr  = mysqli_fetch_assoc(mysqli_query($l, "SHOW CREATE VIEW `$t`"));
            $ddl = preg_replace('/DEFINER=`[^`]*`@`[^`]*` /', '', $cr['Create View'] ?? '');
            $w("\nDROP VIEW IF EXISTS `$t`;\n" . $ddl . ";\n");
        }

        $w("\nSET FOREIGN_KEY_CHECKS=1;\n");
    } finally {
        mysqli_close($l);
    }
}

// ── Dump vers un fichier (.sql ou .sql.gz) ─────────────────────────
function ecole_dump_vers_fichier(string $db, string $fichier, ?bool $gzip = null): void {
    if ($gzip === null) {
        $gzip = (bool) preg_match('/\.gz$/i', $fichier);
    }
    if ($gzip && !function_exists('gzopen')) $gzip = false;

    $fh = $gzip ? gzopen($fichier, 'wb6') : fopen($fichier, 'wb');
    if (!$fh) throw new RuntimeException("Ouverture impossible : $fichier");
    $w = $gzip
        ? function (string $s) use ($fh) { gzwrite($fh, $s); }
        : function (string $s) use ($fh) { fwrite($fh, $s); };
    try {
        ecole_dump_sql($db, $w);
    } finally {
        $gzip ? gzclose($fh) : fclose($fh);
    }
}

// Backup de sécurité horodaté de $db → chemin du fichier écrit.
function ecole_maint_backup_securite(string $db, string $motif): string {
    $ext  = function_exists('gzopen') ? '.sql.gz' : '.sql';
    $base = preg_replace('/[^A-Za-z0-9_]/', '', $db);
    $chemin = ecole_maint_dir_backup() . '/' . $motif . '_' . $base . '_' . date('Ymd_His') . $ext;
    ecole_dump_vers_fichier($db, $chemin);
    if (!is_file($chemin) || filesize($chemin) < 100) {
        throw new RuntimeException("Backup de sécurité vide ou non écrit ($chemin).");
    }
    return $chemin;
}

// Lecture d'un fichier .gz en mémoire (dumps école : petite taille).
function ecole_maint_lire_gz(string $f) {
    if (!function_exists('gzopen')) return false;
    $h = @gzopen($f, 'rb');
    if (!$h) return false;
    $s = '';
    while (!gzeof($h)) $s .= gzread($h, 262144);
    gzclose($h);
    return $s;
}

// Supprime toutes les tables et vues de la base sélectionnée sur $l.
function ecole_maint_drop_tout(mysqli $l): void {
    mysqli_query($l, "SET FOREIGN_KEY_CHECKS=0");
    $res  = mysqli_query($l, "SELECT table_name AS n, table_type AS t FROM information_schema.tables
                              WHERE table_schema = DATABASE()");
    $drop = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $n = str_replace('`', '', $r['n']);
        $drop[] = $r['t'] === 'VIEW'
            ? "DROP VIEW IF EXISTS `$n`"
            : "DROP TABLE IF EXISTS `$n`";
    }
    foreach ($drop as $q) mysqli_query($l, $q);
    mysqli_query($l, "SET FOREIGN_KEY_CHECKS=1");
}

// ── Import : remplace INTÉGRALEMENT le contenu de $db par $sql ──────
//  $sql : contenu SQL déjà décompressé (dump produit par ecole_dump_sql
//  ou par mysqldump). Un backup de sécurité de l'état courant est écrit
//  AVANT toute destruction ; en cas d'échec, son chemin est renvoyé pour
//  restauration manuelle.
//  Retour : ['ok'=>bool, 'message'=>string, 'backup'=>?string, 'tables'=>int]
function ecole_importer_sql(string $db, string $sql): array {
    try {
        ecole_maint_garde_base($db);
    } catch (\Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage(), 'backup' => null, 'tables' => 0];
    }

    if (trim($sql) === '') {
        return ['ok' => false, 'message' => 'Fichier vide ou illisible.', 'backup' => null, 'tables' => 0];
    }
    // Garde-fou : le dump doit ressembler à une base école SIGES.
    if (stripos($sql, 'CREATE TABLE') === false
        || (stripos($sql, '`eleve`') === false && stripos($sql, '`etablissement`') === false)) {
        return ['ok' => false, 'backup' => null, 'tables' => 0,
                'message' => "Ce fichier ne ressemble pas à un export de base école SIGES "
                           . "(aucune table `eleve` / `etablissement` détectée)."];
    }

    // 1) Backup de sécurité de l'état courant.
    try {
        $backup = ecole_maint_backup_securite($db, 'avant_import');
    } catch (\Throwable $e) {
        return ['ok' => false, 'backup' => null, 'tables' => 0,
                'message' => "Backup de sécurité impossible ({$e->getMessage()}) — import annulé, base inchangée."];
    }

    // 2) Purge + chargement.
    try {
        $l = ecole_maint_lien($db);
        ecole_maint_drop_tout($l);

        if (mysqli_multi_query($l, $sql)) {
            do { /* consommer tous les jeux de résultats */ } while (mysqli_next_result($l));
        }
        if (mysqli_errno($l)) {
            $err = mysqli_error($l);
            mysqli_close($l);
            return ['ok' => false, 'backup' => $backup, 'tables' => 0,
                    'message' => "Erreur SQL pendant l'import : $err. La base peut être incomplète — "
                               . "restaurez le backup de sécurité (" . basename($backup) . ")."];
        }
        $nb = (int) mysqli_fetch_row(mysqli_query($l,
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()"))[0];
        mysqli_close($l);
    } catch (\Throwable $e) {
        return ['ok' => false, 'backup' => $backup, 'tables' => 0,
                'message' => "Import interrompu : {$e->getMessage()} — "
                           . "restaurez le backup de sécurité (" . basename($backup) . ")."];
    }

    if ($nb < 10) {
        return ['ok' => false, 'backup' => $backup, 'tables' => $nb,
                'message' => "Import terminé mais base incomplète ($nb tables) — "
                           . "restaurez le backup de sécurité (" . basename($backup) . ")."];
    }

    return ['ok' => true, 'backup' => $backup, 'tables' => $nb,
            'message' => "Base « $db » remplacée par l'import ($nb tables). "
                       . "Backup de l'état précédent : " . basename($backup)];
}

// ── Vidage : base ramenée à l'état « école neuve » ─────────────────
//  Schéma de référence (bd/assoc/schema_ref_ecole.sql) rechargé, ligne
//  `etablissement` locale ré-amorcée depuis l'annuaire, AUCUNE autre
//  donnée. Backup de sécurité écrit avant.
//  Retour : ['ok'=>bool, 'message'=>string, 'backup'=>?string, 'tables'=>int]
function ecole_vider(int $id_etab): array {
    if (!annuaire_dispo()) {
        return ['ok' => false, 'message' => 'Annuaire association absent.', 'backup' => null, 'tables' => 0];
    }
    $e = assoc_one("SELECT * FROM etablissement WHERE id=?", [$id_etab]);
    if (!$e) {
        return ['ok' => false, 'message' => 'Établissement introuvable.', 'backup' => null, 'tables' => 0];
    }
    $db = $e['db_name'];
    try {
        ecole_maint_garde_base($db);
    } catch (\Throwable $ex) {
        return ['ok' => false, 'message' => $ex->getMessage(), 'backup' => null, 'tables' => 0];
    }

    try {
        $backup = ecole_maint_backup_securite($db, 'avant_vidage');
    } catch (\Throwable $ex) {
        return ['ok' => false, 'backup' => null, 'tables' => 0,
                'message' => "Backup de sécurité impossible ({$ex->getMessage()}) — vidage annulé, base inchangée."];
    }

    try {
        $l = ecole_maint_lien($db);
        ecole_maint_drop_tout($l);
        $seed = [
            'nom'    => $e['nom'],
            'nom_en' => null,
            'sigle'  => $e['sigle'] ?: null,
            'ville'  => $e['ville'] ?: null,
        ];
        $nb = charger_schema_ecole($l, $seed);   // connexion_assoc.php
        mysqli_close($l);
    } catch (\Throwable $ex) {
        return ['ok' => false, 'backup' => $backup, 'tables' => 0,
                'message' => "Vidage interrompu : {$ex->getMessage()} — "
                           . "restaurez le backup de sécurité (" . basename($backup) . ")."];
    }

    return ['ok' => true, 'backup' => $backup, 'tables' => $nb,
            'message' => "Base « $db » vidée et réinitialisée au schéma de référence ($nb tables). "
                       . "Backup de l'état précédent : " . basename($backup)];
}

// ── Création de la base d'une école déjà inscrite à l'annuaire ─────
//  Cas d'usage : la ligne `etablissement` existe mais sa base MySQL est
//  absente (base jamais provisionnée, supprimée à la main, restauration
//  partielle…). L'école devient utilisable sans repasser par la création
//  d'établissement. Charge le schéma de référence et amorce la ligne
//  `etablissement` locale depuis l'annuaire ; cale schema_version_etab
//  sur la dernière migration connue (comme creer_etablissement()).
//
//  Ne crée RIEN si la base existe déjà avec des tables (utiliser Vider /
//  Importer pour la réinitialiser). Une base existante mais vide est
//  simplement peuplée.
//
//  Retour : ['ok'=>bool, 'message'=>string, 'tables'=>int]
function ecole_creer_base(int $id_etab): array {
    if (!annuaire_dispo()) {
        return ['ok' => false, 'message' => 'Annuaire association absent.', 'tables' => 0];
    }
    $e = assoc_one("SELECT * FROM etablissement WHERE id=?", [$id_etab]);
    if (!$e) {
        return ['ok' => false, 'message' => 'Établissement introuvable.', 'tables' => 0];
    }
    $db = $e['db_name'];
    try {
        ecole_maint_garde_nom($db);
    } catch (\Throwable $ex) {
        return ['ok' => false, 'message' => $ex->getMessage(), 'tables' => 0];
    }

    $etat = ecole_base_etat($db);
    if ($etat['existe'] && $etat['tables'] > 0) {
        return ['ok' => false, 'tables' => $etat['tables'],
                'message' => "La base « $db » existe déjà et contient {$etat['tables']} tables — "
                           . "utilisez « Vider » ou « Importer » pour la réinitialiser."];
    }

    $pool_mode = defined('ECOLE_POOL_ACTIF') && ECOLE_POOL_ACTIF;
    $seed = [
        'nom'    => $e['nom'],
        'nom_en' => null,
        'sigle'  => $e['sigle'] ?: null,
        'ville'  => $e['ville'] ?: null,
    ];

    try {
        if (!$etat['existe']) {
            if ($pool_mode) {
                return ['ok' => false, 'tables' => 0,
                        'message' => "Mode pool actif : la base « $db » doit être créée dans le cPanel "
                                   . "avant de pouvoir être initialisée ici."];
            }
            $srv = mysqli_connect(DB_HOST, DB_USER, DB_PASS);
            mysqli_set_charset($srv, 'utf8mb4');
            mysqli_query($srv, "CREATE DATABASE `" . str_replace('`', '', $db)
                             . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            mysqli_select_db($srv, $db);
            $nb = charger_schema_ecole($srv, $seed);   // connexion_assoc.php
            mysqli_close($srv);
        } else {
            // Base présente mais vide : on la peuple simplement.
            $l = ecole_maint_lien($db);
            $nb = charger_schema_ecole($l, $seed);
            mysqli_close($l);
        }
    } catch (\Throwable $ex) {
        return ['ok' => false, 'tables' => 0,
                'message' => "Création de la base interrompue : " . $ex->getMessage()];
    }

    // Version de schéma = dernière migration connue (idem creer_etablissement).
    $vmax = 0;
    foreach (glob(__DIR__ . '/../migration_v*.sql') ?: [] as $f) {
        if (preg_match('/migration_v(\d+)\.sql$/', $f, $m)) $vmax = max($vmax, (int) $m[1]);
    }
    try {
        assoc_exec(
            "INSERT INTO schema_version_etab (id_etablissement, version) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE version=GREATEST(version, VALUES(version))",
            [$id_etab, $vmax]
        );
    } catch (\Throwable $ex) { /* table absente en mono-école : sans effet */ }

    return ['ok' => true, 'tables' => $nb,
            'message' => "Base « $db » créée et initialisée au schéma de référence "
                       . "($nb tables, schéma v$vmax). L'école « {$e['nom']} » est maintenant opérationnelle."];
}

// ═══════════════════════════════════════════════════════════════════
//  Sauvegarde COMPLÈTE (SQL + fichiers uploadés) et restauration
// ═══════════════════════════════════════════════════════════════════

// Slug de dossier d'upload d'une école (= upload_prefixe_etab() sans le
// « etab/ » ni le « / » final) : code en minuscules, alphanumérique.
function ecole_maint_slug_upload(string $code): string {
    return strtolower(preg_replace('/[^a-z0-9]/i', '', $code));
}

// Dossiers de fichiers uploadés propres à une école :
//   [chemin absolu => préfixe dans l'archive].
function ecole_maint_dossiers_uploads(string $code): array {
    $slug = ecole_maint_slug_upload($code);
    if ($slug === '') return [];
    $racine = realpath(__DIR__ . '/../../assets/uploads') ?: (__DIR__ . '/../../assets/uploads');
    return [
        $racine . '/etab/' . $slug                => 'uploads/etab/' . $slug,
        $racine . '/dossiers_eleves/etab/' . $slug => 'uploads/dossiers_eleves/etab/' . $slug,
    ];
}

// Ajoute récursivement le contenu d'un dossier à une archive ZIP ouverte.
function ecole_maint_zip_ajouter(\ZipArchive $zip, string $dir, string $prefixe): int {
    if (!is_dir($dir)) return 0;
    $n = 0;
    $it = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $item) {
        $rel = str_replace('\\', '/', substr($item->getPathname(), strlen($dir) + 1));
        if ($item->isDir()) {
            $zip->addEmptyDir($prefixe . '/' . $rel);
        } else {
            $zip->addFile($item->getPathname(), $prefixe . '/' . $rel);
            $n++;
        }
    }
    return $n;
}

// Export COMPLET d'une école vers un .zip : dump.sql.gz + manifest.json +
// uploads/… (logos, signatures, pièces de dossier). Les photos d'élèves
// sont en BLOB dans la table `eleve` → déjà dans le dump SQL.
//  Retour : ['ok'=>bool, 'message'=>string, 'fichiers'=>int, 'octets'=>int]
function ecole_export_zip(int $id_etab, string $dest_zip): array {
    if (!annuaire_dispo()) {
        return ['ok' => false, 'message' => 'Annuaire association absent.', 'fichiers' => 0, 'octets' => 0];
    }
    $e = assoc_one("SELECT * FROM etablissement WHERE id=?", [$id_etab]);
    if (!$e) return ['ok' => false, 'message' => 'Établissement introuvable.', 'fichiers' => 0, 'octets' => 0];
    $db = $e['db_name'];
    try { ecole_maint_garde_base($db); }
    catch (\Throwable $ex) { return ['ok' => false, 'message' => $ex->getMessage(), 'fichiers' => 0, 'octets' => 0]; }

    $tmp_sql = tempnam(sys_get_temp_dir(), 'siges_expsql_');
    $nb_fichiers = 0;
    try {
        ecole_dump_vers_fichier($db, $tmp_sql, function_exists('gzopen'));

        $zip = new \ZipArchive();
        if ($zip->open($dest_zip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Impossible de créer l'archive $dest_zip.");
        }
        $nom_dump = function_exists('gzopen') ? 'dump.sql.gz' : 'dump.sql';
        $zip->addFile($tmp_sql, $nom_dump);

        foreach (ecole_maint_dossiers_uploads($e['code']) as $abs => $prefixe) {
            $nb_fichiers += ecole_maint_zip_ajouter($zip, $abs, $prefixe);
        }

        $zip->addFromString('manifest.json', json_encode([
            'type'     => 'siges-export-ecole',
            'code'     => $e['code'],
            'db_name'  => $db,
            'nom'      => $e['nom'],
            'dump'     => $nom_dump,
            'fichiers' => $nb_fichiers,
            'date'     => date('c'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $zip->close();
    } catch (\Throwable $ex) {
        @unlink($tmp_sql);
        @unlink($dest_zip);
        return ['ok' => false, 'message' => "Export interrompu : " . $ex->getMessage(), 'fichiers' => 0, 'octets' => 0];
    }
    @unlink($tmp_sql);

    return ['ok' => true, 'fichiers' => $nb_fichiers, 'octets' => (int) @filesize($dest_zip),
            'message' => "Archive créée ($nb_fichiers fichier(s) joints)."];
}

// Restauration d'une école depuis un .zip produit par ecole_export_zip() :
//  1) ecole_importer_sql() (qui écrit son propre backup de sécurité) ;
//  2) restauration des fichiers uploadés (le dossier de l'école est purgé
//     puis réécrit d'après l'archive).
//  Retour : ['ok','message','backup','tables','fichiers']
function ecole_importer_zip(int $id_etab, string $zip_path): array {
    if (!annuaire_dispo()) {
        return ['ok' => false, 'message' => 'Annuaire absent.', 'backup' => null, 'tables' => 0, 'fichiers' => 0];
    }
    $e = assoc_one("SELECT * FROM etablissement WHERE id=?", [$id_etab]);
    if (!$e) return ['ok' => false, 'message' => 'Établissement introuvable.', 'backup' => null, 'tables' => 0, 'fichiers' => 0];
    $db = $e['db_name'];

    if (!is_file($zip_path)) {
        return ['ok' => false, 'message' => 'Archive introuvable.', 'backup' => null, 'tables' => 0, 'fichiers' => 0];
    }
    $zip = new \ZipArchive();
    if ($zip->open($zip_path) !== true) {
        return ['ok' => false, 'message' => 'Archive .zip illisible.', 'backup' => null, 'tables' => 0, 'fichiers' => 0];
    }

    $nom_dump = null;
    foreach (['dump.sql.gz', 'dump.sql'] as $cand) {
        if ($zip->locateName($cand) !== false) { $nom_dump = $cand; break; }
    }
    if ($nom_dump === null) {
        $zip->close();
        return ['ok' => false, 'message' => "Archive invalide : aucun dump.sql(.gz).", 'backup' => null, 'tables' => 0, 'fichiers' => 0];
    }
    $sql = $zip->getFromName($nom_dump);
    if (substr($nom_dump, -3) === '.gz' && $sql !== false) {
        $sql = @gzdecode($sql);
    }
    if ($sql === false || $sql === null || trim($sql) === '') {
        $zip->close();
        return ['ok' => false, 'message' => "Dump illisible dans l'archive.", 'backup' => null, 'tables' => 0, 'fichiers' => 0];
    }

    $res = ecole_importer_sql($db, $sql);
    if (!$res['ok']) {
        $zip->close();
        return $res + ['fichiers' => 0];
    }

    $racine = realpath(__DIR__ . '/../../assets/uploads') ?: (__DIR__ . '/../../assets/uploads');
    $racine = str_replace('\\', '/', $racine);
    $slug   = ecole_maint_slug_upload($e['code']);
    $nb_fichiers = 0;
    if ($slug !== '') {
        foreach (["$racine/etab/$slug", "$racine/dossiers_eleves/etab/$slug"] as $d) {
            ecole_maint_rmdir_recursif($d);
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nom = $zip->getNameIndex($i);
            if (strpos($nom, 'uploads/') !== 0 || substr($nom, -1) === '/') continue;
            $cible = str_replace('\\', '/', $racine . '/' . substr($nom, strlen('uploads/')));
            // Garde-fou : rester sous assets/uploads/…/etab/<slug>/.
            if (strpos($cible, $racine . '/') !== 0 || strpos($cible, '/etab/' . $slug . '/') === false
                || strpos($cible, '/..') !== false) {
                continue;
            }
            @mkdir(dirname($cible), 0775, true);
            $data = $zip->getFromIndex($i);
            if ($data !== false && @file_put_contents($cible, $data) !== false) $nb_fichiers++;
        }
    }
    $zip->close();

    return [
        'ok'       => true,
        'backup'   => $res['backup'],
        'tables'   => $res['tables'],
        'fichiers' => $nb_fichiers,
        'message'  => "Restauration complète de « {$e['nom']} » : {$res['tables']} tables + $nb_fichiers fichier(s). "
                    . "Backup de l'état précédent : " . basename((string) $res['backup']),
    ];
}

// Suppression récursive d'un dossier (best-effort).
function ecole_maint_rmdir_recursif(string $dir): void {
    if (!is_dir($dir)) return;
    $it = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
}

// ── Sauvegarde manuelle « à la demande » depuis l'interface ────────
//  Écrit dans bd/sauvegardes/manuel_<horo>/<code>_<db>.(zip|sql.gz) — donc
//  visible par assoc_derniere_sauvegarde() (glob « */* » du cockpit).
//  $avec_fichiers = true → archive .zip complète ; false → dump .sql.gz seul.
//  Retour : ['ok'=>bool, 'message'=>string, 'fichier'=>?string, 'octets'=>int]
function ecole_sauvegarder(int $id_etab, bool $avec_fichiers = true): array {
    if (!annuaire_dispo()) {
        return ['ok' => false, 'message' => 'Annuaire absent.', 'fichier' => null, 'octets' => 0];
    }
    $e = assoc_one("SELECT * FROM etablissement WHERE id=?", [$id_etab]);
    if (!$e) return ['ok' => false, 'message' => 'Établissement introuvable.', 'fichier' => null, 'octets' => 0];
    $db = $e['db_name'];
    try { ecole_maint_garde_base($db); }
    catch (\Throwable $ex) { return ['ok' => false, 'message' => $ex->getMessage(), 'fichier' => null, 'octets' => 0]; }

    $dir = ecole_maint_dir_backup() . '/manuel_' . date('Ymd_His');
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return ['ok' => false, 'message' => "Impossible de créer $dir.", 'fichier' => null, 'octets' => 0];
    }
    $prefixe = strtolower(preg_replace('/[^a-z0-9]/i', '', $e['code'])) . '_' . $db;

    try {
        if ($avec_fichiers) {
            $fichier = $dir . '/' . $prefixe . '.zip';
            $r = ecole_export_zip($id_etab, $fichier);
            if (!$r['ok']) return ['ok' => false, 'message' => $r['message'], 'fichier' => null, 'octets' => 0];
        } else {
            $fichier = $dir . '/' . $prefixe . '.sql' . (function_exists('gzopen') ? '.gz' : '');
            ecole_dump_vers_fichier($db, $fichier);
        }
    } catch (\Throwable $ex) {
        return ['ok' => false, 'message' => "Sauvegarde interrompue : " . $ex->getMessage(), 'fichier' => null, 'octets' => 0];
    }

    return ['ok' => true, 'fichier' => $fichier, 'octets' => (int) @filesize($fichier),
            'message' => "Sauvegarde écrite : " . basename(dirname($fichier)) . '/' . basename($fichier)];
}

// ── Rétention : purge des sauvegardes de sécurité anciennes ────────
//  Ne touche QUE les fichiers avant_*_ (import/vidage/migration/suppression)
//  à la racine de bd/sauvegardes/ ; laisse les dossiers horodatés intacts.
//  Retour : nombre de fichiers supprimés.
function ecole_maint_purger_backups(int $jours = 45): int {
    $racine = ECOLE_MAINT_DIR_BACKUP;
    if (!is_dir($racine)) return 0;
    $limite = time() - $jours * 86400;
    $n = 0;
    foreach (glob($racine . '/avant_*.{sql,sql.gz}', GLOB_BRACE) ?: [] as $f) {
        if (is_file($f) && @filemtime($f) < $limite && @unlink($f)) $n++;
    }
    return $n;
}

// Liste les sauvegardes disponibles pour une école (dossiers horodatés de
// bd/sauvegardes/ + backups de sécurité avant_*), plus récentes d'abord.
//  Retour : [['chemin','nom','type','date','octets'], …]
function ecole_maint_sauvegardes(string $code, string $db): array {
    $racine = ECOLE_MAINT_DIR_BACKUP;
    if (!is_dir($racine)) return [];
    $slug = strtolower(preg_replace('/[^a-z0-9]/i', '', $code));
    $out  = [];

    foreach (glob($racine . '/*/*') ?: [] as $f) {
        if (!is_file($f)) continue;
        $bn = basename($f);
        if (strpos($bn, $db) === false && ($slug === '' || strpos($bn, $slug . '_') !== 0)) continue;
        $dossier = basename(dirname($f));
        $out[] = [
            'chemin' => $f, 'nom' => $dossier . '/' . $bn,
            'type'   => strpos($dossier, 'manuel_') === 0 ? 'manuelle' : 'quotidienne',
            'date'   => (int) @filemtime($f), 'octets' => (int) @filesize($f),
        ];
    }
    foreach (glob($racine . '/avant_*') ?: [] as $f) {
        if (!is_file($f) || strpos(basename($f), $db) === false) continue;
        $motif = preg_match('/^avant_([a-z]+)_/', basename($f), $m) ? $m[1] : 'sécurité';
        $out[] = [
            'chemin' => $f, 'nom' => basename($f),
            'type'   => 'sécurité (' . $motif . ')',
            'date'   => (int) @filemtime($f), 'octets' => (int) @filesize($f),
        ];
    }
    usort($out, fn($a, $b) => $b['date'] <=> $a['date']);
    return $out;
}
