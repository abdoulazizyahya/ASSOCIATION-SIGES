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
    // Connexion SANS choix de base : la base « école courante » est
    // sélectionnée juste après par la résolution multi-établissement.
    $link = mysqli_connect(DB_HOST, DB_USER, DB_PASS);
    mysqli_set_charset($link, 'utf8mb4');
} catch (mysqli_sql_exception $e) {
    die('<div style="font-family:sans-serif;padding:2rem;color:red">
         <b>Erreur de connexion à la base de données :</b><br>' . $e->getMessage() . '
         </div>');
}

// ── Résolution de l'établissement courant (multi-établissement) ──────
//  L'annuaire association est optionnel : s'il est absent, $ETAB_COURANT
//  reste null et on retombe sur DB_NAME (installation mono-école).
require_once __DIR__ . '/connexion_assoc.php';   // $link_assoc (ou null) + assoc_*
require_once __DIR__ . '/ecole_contexte.php';

/** @var array|null $ETAB_COURANT  Ligne annuaire de l'école active (null = contexte association ou annuaire absent). */
$ETAB_COURANT = annuaire_dispo() ? resoudre_etablissement() : null;

if ($ETAB_COURANT) {
    $bd_active = $ETAB_COURANT['db_name'];
} elseif (annuaire_dispo() && est_contexte_association()) {
    $bd_active = DB_NAME_ASSOC;           // l'interface association travaille dans l'annuaire
} else {
    $bd_active = DB_NAME;                 // repli mono-école
}
mysqli_select_db($link, $bd_active);

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
    // Filet de sécurité : en visite association (lecture seule), aucune
    // écriture dans une base école — même hors formulaire (csrf_verifier()
    // couvre déjà tous les POST). Les SELECT restent permis (db_all/one/val).
    if (function_exists('est_lecture_seule') && est_lecture_seule()
        && preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE|ALTER|DROP|CREATE)\b/i', $sql)) {
        throw new RuntimeException('Visite association en lecture seule — écriture refusée.');
    }
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
