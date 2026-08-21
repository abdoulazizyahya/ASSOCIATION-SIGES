<?php
// ── Connexion mysqli PROCÉDURALE (inclure une seule fois) ─────────────
//  Fournit la variable globale $link et les helpers db_all / db_one /
//  db_val / db_exec / db_last_id — mêmes signatures que la version PDO
//  précédente, afin que toutes les pages consommatrices restent inchangées.
require_once __DIR__ . '/config.php';

// mysqli lève des exceptions (mysqli_sql_exception ⊂ Exception) en cas
// d'erreur : on conserve ainsi le même modèle try/catch que l'ancienne
// version PDO (profil.php, runners de migration…).
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $link = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    mysqli_set_charset($link, 'utf8mb4');
} catch (mysqli_sql_exception $e) {
    die('<div style="font-family:sans-serif;padding:2rem;color:red">
         <b>Erreur de connexion à la base de données :</b><br>' . $e->getMessage() . '
         </div>');
}

// ── Helper interne : prépare, lie les paramètres, exécute ────────────
//  Tous les paramètres sont liés en type « s » (chaîne) : MySQL applique
//  la conversion implicite pour les entiers/dates, et une valeur PHP null
//  est correctement transmise comme NULL SQL — comportement identique aux
//  requêtes préparées PDO utilisées auparavant.
function _db_stmt(string $sql, array $params) {
    global $link;
    $stmt = mysqli_prepare($link, $sql);
    if ($params) {
        $types = str_repeat('s', count($params));
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    return $stmt;
}

// Retourne toutes les lignes d'une requête (tableau de tableaux associatifs)
function db_all(string $sql, array $params = []): array {
    $stmt = _db_stmt($sql, $params);
    $res  = mysqli_stmt_get_result($stmt);
    $rows = $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];
    mysqli_stmt_close($stmt);
    return $rows;
}

// Retourne une seule ligne (tableau associatif) ou null
function db_one(string $sql, array $params = []) {
    $stmt = _db_stmt($sql, $params);
    $res  = mysqli_stmt_get_result($stmt);
    $row  = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

// Retourne une valeur scalaire (première colonne, première ligne)
function db_val(string $sql, array $params = []) {
    $stmt = _db_stmt($sql, $params);
    $res  = mysqli_stmt_get_result($stmt);
    $val  = null;
    if ($res && ($r = mysqli_fetch_row($res))) $val = $r[0];
    mysqli_stmt_close($stmt);
    return $val;
}

// Exécute INSERT/UPDATE/DELETE — retourne le nombre de lignes affectées
function db_exec(string $sql, array $params = []): int {
    $stmt = _db_stmt($sql, $params);
    $n    = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    return (int) $n;
}

// Retourne le dernier id auto-incrémenté inséré
function db_last_id(): int {
    global $link;
    return (int) mysqli_insert_id($link);
}
