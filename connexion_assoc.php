<?php
// ── Connexion à la base centrale « jaynitaare_assoc » (annuaire) ─────
//  Fournit $link_assoc (mysqli) + les helpers assoc_all / assoc_one /
//  assoc_val / assoc_exec / assoc_last_id — mêmes signatures que les
//  db_* de connexion.php, mais sur la connexion annuaire.
//
//  IMPORTANT : l'annuaire est OPTIONNEL. Tant que bd/assoc/installer.php
//  n'a pas tourné (base absente), $link_assoc vaut null et l'application
//  retombe sur l'installation mono-école historique (DB_NAME). Aucune
//  erreur fatale ici : le multi-établissement se greffe sans casser
//  l'existant.
//
//  À inclure APRÈS config.php (constante DB_NAME_ASSOC).

require_once __DIR__ . '/config.php';

if (!defined('DB_NAME_ASSOC')) {
    define('DB_NAME_ASSOC', 'jaynitaare_assoc');
}

/** @var mysqli|null $link_assoc  Connexion annuaire, ou null si absente. */
$link_assoc = null;
try {
    // mysqli_report est déjà passé en mode exception par connexion.php si
    // celui-ci a été inclus avant ; on le force ici au cas où ce fichier
    // serait inclus seul (runners bd/assoc/*).
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $link_assoc = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME_ASSOC);
    mysqli_set_charset($link_assoc, 'utf8mb4');
} catch (mysqli_sql_exception $e) {
    // Base annuaire absente / inaccessible — mode mono-école, pas d'erreur.
    $link_assoc = null;
}

/** L'annuaire association est-il disponible sur cette installation ? */
function annuaire_dispo(): bool {
    global $link_assoc;
    return $link_assoc instanceof mysqli;
}

// ── Helper interne : prépare, lie en « s », exécute (cf. connexion.php) ──
function _assoc_stmt(string $sql, array $params) {
    global $link_assoc;
    $stmt = mysqli_prepare($link_assoc, $sql);
    if ($params) {
        mysqli_stmt_bind_param($stmt, str_repeat('s', count($params)), ...$params);
    }
    mysqli_stmt_execute($stmt);
    return $stmt;
}

function assoc_all(string $sql, array $params = []): array {
    $stmt = _assoc_stmt($sql, $params);
    $res  = mysqli_stmt_get_result($stmt);
    $rows = $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];
    mysqli_stmt_close($stmt);
    return $rows;
}

function assoc_one(string $sql, array $params = []) {
    $stmt = _assoc_stmt($sql, $params);
    $res  = mysqli_stmt_get_result($stmt);
    $row  = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

function assoc_val(string $sql, array $params = []) {
    $stmt = _assoc_stmt($sql, $params);
    $res  = mysqli_stmt_get_result($stmt);
    $val  = null;
    if ($res && ($r = mysqli_fetch_row($res))) $val = $r[0];
    mysqli_stmt_close($stmt);
    return $val;
}

function assoc_exec(string $sql, array $params = []): int {
    $stmt = _assoc_stmt($sql, $params);
    $n    = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    return (int) $n;
}

function assoc_last_id(): int {
    global $link_assoc;
    return (int) mysqli_insert_id($link_assoc);
}

// ── Exécution d'un traitement DANS la base d'une autre école ─────────
//  Ouvre une connexion dédiée vers la base de l'école $id_etab, passe le
//  mysqli à $fn, referme, retourne la valeur de $fn. Utilisé par
//  l'interface association pour écrire dans une base école (affectation
//  d'un enseignant, création d'un élève à partir d'un NIU, agrégations).
function avec_ecole(int $id_etab, callable $fn) {
    $e = assoc_one("SELECT db_name FROM etablissement WHERE id=?", [$id_etab]);
    if (!$e) {
        throw new RuntimeException("École #$id_etab introuvable dans l'annuaire.");
    }
    $l = mysqli_connect(DB_HOST, DB_USER, DB_PASS, $e['db_name']);
    mysqli_set_charset($l, 'utf8mb4');
    try {
        return $fn($l);
    } finally {
        mysqli_close($l);
    }
}

// ── Helpers requête sur une connexion école arbitraire ($l = mysqli) ──
//  Utilisés dans les callbacks avec_ecole(). Paramètres liés en « s ».
function ecole_all(mysqli $l, string $sql, array $params = []): array {
    $st = mysqli_prepare($l, $sql);
    if ($params) mysqli_stmt_bind_param($st, str_repeat('s', count($params)), ...$params);
    mysqli_stmt_execute($st);
    $r = mysqli_stmt_get_result($st);
    $rows = $r ? mysqli_fetch_all($r, MYSQLI_ASSOC) : [];
    mysqli_stmt_close($st);
    return $rows;
}
function ecole_one(mysqli $l, string $sql, array $params = []): ?array {
    return ecole_all($l, $sql, $params)[0] ?? null;
}
function ecole_exec(mysqli $l, string $sql, array $params = []): int {
    $st = mysqli_prepare($l, $sql);
    if ($params) mysqli_stmt_bind_param($st, str_repeat('s', count($params)), ...$params);
    mysqli_stmt_execute($st);
    $n = mysqli_stmt_affected_rows($st);
    mysqli_stmt_close($st);
    return (int) $n;
}

// ── Création d'un nouvel établissement ──────────────────────────────
//  Point unique : crée la base MySQL `jaynitaare_ecole_<code>`, y charge
//  le schéma de référence (bd/assoc/schema_ref_ecole.sql), amorce la
//  ligne `etablissement` locale (sinon dashboard/PDF en erreur), inscrit
//  l'école dans l'annuaire et cale sa version de schéma sur la dernière
//  migration connue. Utilisé par l'interface association
//  (association/etablissement_nouveau.php) ET par la CLI
//  (bd/assoc/creer_ecole.php).
//
//  $in : ['code','nom','nom_en'?,'sigle'?,'ville'?,'sous_domaine'?,'par'?]
//  Retour : ['ok'=>bool, 'message'=>string, 'id'=>?int, 'db_name'=>?string]
function creer_etablissement(array $in): array {
    $code = strtoupper(trim($in['code'] ?? ''));
    $nom  = trim($in['nom'] ?? '');
    $nomEn = trim($in['nom_en'] ?? '') ?: null;
    $sigle = trim($in['sigle'] ?? '') ?: null;
    $ville = trim($in['ville'] ?? '') ?: null;
    $sous  = trim($in['sous_domaine'] ?? '') ?: null;

    if (!preg_match('/^[A-Z0-9]{2,10}$/', $code)) {
        return ['ok' => false, 'message' => 'Code invalide : 2 à 10 caractères A–Z ou 0–9.', 'id' => null, 'db_name' => null];
    }
    if ($nom === '') {
        return ['ok' => false, 'message' => 'Le nom de l\'établissement est obligatoire.', 'id' => null, 'db_name' => null];
    }
    if ($sous !== null && !preg_match('/^[a-z0-9-]{2,63}$/', $sous)) {
        return ['ok' => false, 'message' => 'Sous-domaine invalide : lettres minuscules, chiffres et tirets.', 'id' => null, 'db_name' => null];
    }
    if (!annuaire_dispo()) {
        return ['ok' => false, 'message' => 'Annuaire association absent (bd/assoc/installer.php).', 'id' => null, 'db_name' => null];
    }

    $db = 'jaynitaare_ecole_' . strtolower($code);

    // Doublons annuaire (code / base / sous-domaine)
    if (assoc_val("SELECT COUNT(*) FROM etablissement WHERE code=?", [$code])) {
        return ['ok' => false, 'message' => "Le code « $code » est déjà utilisé.", 'id' => null, 'db_name' => null];
    }
    if (assoc_val("SELECT COUNT(*) FROM etablissement WHERE db_name=?", [$db])) {
        return ['ok' => false, 'message' => "La base « $db » est déjà référencée.", 'id' => null, 'db_name' => null];
    }
    if ($sous !== null && assoc_val("SELECT COUNT(*) FROM etablissement WHERE sous_domaine=?", [$sous])) {
        return ['ok' => false, 'message' => "Le sous-domaine « $sous » est déjà utilisé.", 'id' => null, 'db_name' => null];
    }

    $schema = @file_get_contents(__DIR__ . '/bd/assoc/schema_ref_ecole.sql');
    if ($schema === false || trim($schema) === '') {
        return ['ok' => false, 'message' => 'Schéma de référence introuvable (bd/assoc/schema_ref_ecole.sql).', 'id' => null, 'db_name' => null];
    }

    try {
        $srv = mysqli_connect(DB_HOST, DB_USER, DB_PASS);
        mysqli_set_charset($srv, 'utf8mb4');

        $existe = (int) mysqli_fetch_row(mysqli_query($srv,
            "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='"
            . mysqli_real_escape_string($srv, $db) . "'"))[0];
        if ($existe) {
            return ['ok' => false, 'message' => "La base « $db » existe déjà sur le serveur — abandon (aucun écrasement).", 'id' => null, 'db_name' => null];
        }

        mysqli_query($srv, "CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        mysqli_select_db($srv, $db);

        // Chargement du schéma de référence
        if (mysqli_multi_query($srv, $schema)) {
            do { /* consommer tous les jeux de résultats */ } while (mysqli_next_result($srv));
        }
        $nbTables = (int) mysqli_fetch_row(mysqli_query($srv,
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='"
            . mysqli_real_escape_string($srv, $db) . "'"))[0];
        if ($nbTables < 10) {
            mysqli_query($srv, "DROP DATABASE `$db`");
            return ['ok' => false, 'message' => "Échec du chargement du schéma ($nbTables tables) — base supprimée.", 'id' => null, 'db_name' => null];
        }

        // Amorce de la ligne `etablissement` locale (sans elle : dashboard,
        // en-têtes PDF et page Configurations en erreur « colonne indéfinie »).
        $st = mysqli_prepare($srv,
            "INSERT INTO etablissement (IDEtablissement, Nom_Etab_Fr, Nom_Etab_An, Initial_Etab, ville_etab)
             VALUES (1, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($st, 'ssss', $nom, $nomEn, $sigle, $ville);
        mysqli_stmt_execute($st);
        mysqli_stmt_close($st);

        mysqli_close($srv);
    } catch (\Throwable $e) {
        // Nettoyage best-effort si la base a été partiellement créée.
        try {
            $c = mysqli_connect(DB_HOST, DB_USER, DB_PASS);
            mysqli_query($c, "DROP DATABASE IF EXISTS `$db`");
            mysqli_close($c);
        } catch (\Throwable $e2) { /* ignore */ }
        return ['ok' => false, 'message' => 'Erreur lors de la création de la base : ' . $e->getMessage(), 'id' => null, 'db_name' => null];
    }

    // Version de schéma = dernière migration connue
    $vmax = 0;
    foreach (glob(__DIR__ . '/bd/migration_v*.sql') as $f) {
        if (preg_match('/migration_v(\d+)\.sql$/', $f, $m)) $vmax = max($vmax, (int) $m[1]);
    }

    // Ligne annuaire
    assoc_exec(
        "INSERT INTO etablissement (code, sous_domaine, db_name, nom, sigle, ville, actif)
         VALUES (?, ?, ?, ?, ?, ?, 1)",
        [$code, $sous, $db, $nom, $sigle, $ville]
    );
    $id = (int) assoc_val("SELECT id FROM etablissement WHERE code=?", [$code]);

    assoc_exec(
        "INSERT INTO schema_version_etab (id_etablissement, version) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE version=GREATEST(version, VALUES(version))",
        [$id, $vmax]
    );

    return [
        'ok'      => true,
        'message' => "Établissement « $nom » créé (base $db, schéma v$vmax). "
                   . "Créez maintenant un compte DIRECTEUR via Personnel → Affecter.",
        'id'      => $id,
        'db_name' => $db,
    ];
}
