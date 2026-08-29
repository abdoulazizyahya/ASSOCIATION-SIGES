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
