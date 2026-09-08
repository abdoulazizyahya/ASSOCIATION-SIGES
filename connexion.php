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

// Nombre d'écoles actives dans l'annuaire (0 sur une installation neuve où
// l'annuaire existe mais aucune école n'a encore été créée).
$_nb_ecoles = annuaire_dispo()
    ? (int) assoc_val("SELECT COUNT(*) FROM etablissement WHERE actif=1")
    : 0;

if ($ETAB_COURANT) {
    $bd_active = $ETAB_COURANT['db_name'];
} elseif (annuaire_dispo() && est_contexte_association()) {
    $bd_active = DB_NAME_ASSOC;           // interface /association/ : on travaille dans l'annuaire
} elseif (annuaire_dispo() && est_contexte_neutre() && $_nb_ecoles > 0) {
    // Contexte neutre AVEC au moins une école : page de connexion / pages
    // publiques sans ?ec=. On pointe l'annuaire (login.php liste les écoles).
    // Aucune requête « école » n'est émise avant basculer_base_ecole().
    $bd_active = DB_NAME_ASSOC;
} else {
    $bd_active = DB_NAME;                 // repli mono-école (ou annuaire sans école)
}

// La base cible peut ne pas exister sur une installation incomplète
// (annuaire présent mais aucune école, base école n°1 jamais créée…).
// On affiche alors des instructions claires au lieu d'un « Fatal error ».
$_db_ok = true;
try {
    mysqli_select_db($link, $bd_active);
} catch (\Throwable $e) {
    $_db_ok = false;
}
if (!$_db_ok) {
    // Aucune config d'environnement (config.local.php) : rien n'a jamais été
    // installé -> assistant de première installation (choix du nom
    // d'association, création des bases). La page 503 ci-dessous ne sert que
    // pour une install PARTIELLEMENT cassée (config présente, base disparue).
    $_script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if (!is_file(__DIR__ . '/config.local.php') && $_script !== 'install.php') {
        header('Location: ' . APP_URL . '/install.php');
        exit;
    }
    $_annu = annuaire_dispo();
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Installation à finaliser</title>'
       . '<div style="max-width:640px;margin:12vh auto;font:15px/1.6 Segoe UI,system-ui,sans-serif;color:#1e2a3a">'
       . '<h1 style="font:600 22px Georgia,serif;color:#1a2744">Installation à finaliser</h1>'
       . '<p>La base de données <code style="background:#f2f0e8;padding:1px 5px;border-radius:4px">'
       . htmlspecialchars($bd_active) . '</code> est introuvable — l\'application n\'a pas encore d\'école configurée.</p>';
    if ($_annu && $_nb_ecoles === 0) {
        echo '<p>L\'annuaire association est en place mais <b>aucune école</b> n\'y est enregistrée.</p>'
           . '<p><b>Pour créer la première école :</b></p>'
           . '<pre style="background:#f2f0e8;padding:12px;border-radius:6px;overflow:auto">php bd/assoc/installer.php [mot_de_passe_admin]</pre>'
           . '<p>ou, si l\'annuaire est déjà installé, ouvrez <a href="' . htmlspecialchars(APP_URL) . '/association/">l\'Espace association</a> &rarr; <i>Nouvel établissement</i>.</p>';
    } else {
        echo '<p><b>Pour installer l\'application :</b></p>'
           . '<pre style="background:#f2f0e8;padding:12px;border-radius:6px;overflow:auto">php bd/assoc/installer.php [mot_de_passe_admin]</pre>'
           . '<p>Ce script crée la base de l\'école n°1 (schéma + données de référence + classes standard + première année) et un compte <b>DIRECTEUR</b> pour la connexion.</p>';
    }
    echo '<p style="color:#6b7280;font-size:13px">Détail des étapes : <code>bd/assoc/DEPLOIEMENT.md</code>.</p></div>';
    exit;
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
