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
require_once __DIR__ . '/bd/lib/licence.php';    // db_exec() ci-dessous applique le fail-closed

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

// ── École secondaire : module pages/menu/dashboard pas encore construit ──
//  (schema_ref_ecole_secondaire.sql, porté de LAM_ABZ — noms de tables/
//  colonnes différents du schéma primaire partout, ex. annee_scolaire.
//  active au lieu de Etat_annee_scolaire) : TOUTE page « primaire » plante
//  dès son premier appel (même layout/header.php, dès get_annee_active()).
//  Interception ICI, avant que quoi que ce soit de primaire-spécifique ne
//  s'exécute, plutôt que de laisser un Fatal error s'afficher — bug réel
//  constaté le 15/09/2026 en créant une vraie école secondaire (CE) et en
//  cliquant dessus. logout.php et login.php exemptés (jamais concernés :
//  logout.php ne touche à aucune donnée école, login.php n'affiche que le
//  formulaire avant toute authentification).
if ($ETAB_COURANT
    && (($ETAB_COURANT['type_enseignement'] ?? 'primaire') === 'secondaire')
    && !in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), ['login.php', 'logout.php'], true)
) {
    require __DIR__ . '/secondaire_en_construction.php';
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

// Une colonne existe-t-elle dans la base école courante ? (cache statique)
// Utile pour rester tolérant aux bases pas encore migrées (ex. user.actif,
// user.derniere_connexion — migration_v54).
function db_colonne_existe(string $table, string $colonne): bool {
    static $cache = [];
    $cle = $table . '.' . $colonne;
    if (!array_key_exists($cle, $cache)) {
        try {
            $cache[$cle] = (bool) db_val(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?",
                [$table, $colonne]
            );
        } catch (\Throwable $e) {
            $cache[$cle] = false;
        }
    }
    return $cache[$cle];
}

// Exécute INSERT/UPDATE/DELETE — retourne le nombre de lignes affectées
function db_exec(string $sql, array $params = []): int {
    // Filet de sécurité : contexte en lecture seule (visite association SANS
    // droit d'écriture, ou FONDATEUR sur une rubrique notes/finances), aucune
    // écriture dans la base école — même hors formulaire (csrf_verifier()
    // couvre déjà tous les POST). Les SELECT restent permis (db_all/one/val).
    if (function_exists('est_lecture_seule') && est_lecture_seule()
        && preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE|ALTER|DROP|CREATE)\b/i', $sql)) {
        throw new RuntimeException('Contexte en lecture seule — écriture refusée.');
    }
    // Licence (bd/lib/licence.php, migration v56) : POINT D'ENTRÉE UNIQUE du
    // blocage en écriture par licence expirée — demande explicite du
    // 13/09/2026. Hors annuaire association (base courante = DB_NAME_ASSOC,
    // qui a son propre cycle de vie indépendant de toute école) et hors les
    // 4 tables de licence elles-mêmes (sinon impossible de renouveler /
    // sortir du blocage). Le propriétaire n'est jamais bridé (comme pour
    // est_lecture_seule() ci-dessus) : c'est l'autorité de la licence, pas
    // sa cible. licence_etat() est fail-closed : toute anomalie renvoie
    // 'expiree', jamais 'ok' par défaut.
    // Scripts de CONTINUITÉ D'ACCÈS AU COMPTE toujours exemptés (bugs réels
    // trouvés en test le 13/09/2026) : configurer_securite.php (étape
    // OBLIGATOIRE avant tout accès, exiger_connexion()/fonctions.php — sans
    // cette exemption, un compte pas encore configuré restait bloqué en
    // boucle infinie, jamais capable d'enregistrer ses 2 questions
    // secrètes) et mot_de_passe_oublie.php (un directeur/fondateur qui a
    // oublié son mot de passe doit pouvoir le réinitialiser pour ensuite
    // atteindre la page Licence et renouveler — sinon double blocage sans
    // issue). Ni l'un ni l'autre n'écrit de données métier.
    //
    // ⚠ La base COURANTE est relue ICI via SELECT DATABASE() (pas la
    // variable $bd_active posée une fois à l'inclusion de connexion.php) :
    // basculer_base_ecole() (ecole_contexte.php, appelée par login.php après
    // le choix d'établissement) ne met PAS à jour $bd_active en changeant de
    // base sur $link — un $bd_active resté à DB_NAME_ASSOC aurait fait
    // sauter le gate en entier pour toute écriture faite juste après un
    // changement d'école dans la même requête (bug réel trouvé en test :
    // la connexion elle-même passait alors que la licence était expirée).
    global $link;
    $db_courante = null;
    if (preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE|ALTER|DROP|CREATE)\b/i', $sql) && $link instanceof mysqli) {
        $_r = @mysqli_query($link, 'SELECT DATABASE()');
        $db_courante = $_r ? (mysqli_fetch_row($_r)[0] ?? null) : null;
    }
    if ($db_courante !== null
        && $db_courante !== DB_NAME_ASSOC
        && function_exists('licence_etat')
        && !(function_exists('est_proprietaire_association') && est_proprietaire_association())
        && !in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), ['configurer_securite.php', 'mot_de_passe_oublie.php'], true)
        && !preg_match('/\blicence(_historique|_securite|_cles_utilisees)?\b/i', $sql)
    ) {
        if (licence_etat()['etat'] === 'expiree') {
            throw new RuntimeException('Licence expirée — écriture refusée. Contactez le propriétaire pour renouveler.');
        }
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
