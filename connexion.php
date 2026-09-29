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

// ── Séparation primaire / secondaire : URL du mauvais module → bloquée ──
//  (schema_ref_ecole_secondaire.sql, porté de LAM_ABZ — noms de tables/
//  colonnes différents du schéma primaire partout, ex. annee_scolaire.
//  active au lieu de Etat_annee_scolaire, sequence.date_fin qui n'existe
//  pas côté primaire — id_seq/libelle_seq/etat) : une page du mauvais
//  module plante dès son premier appel spécifique (get_annee_active(),
//  menu_definition(), auto_activer_sequences()…). Interception ICI, avant
//  que quoi que ce soit de spécifique à l'autre module ne s'exécute — bugs
//  réels constatés le 15/09/2026 (pages/** sur une école secondaire) puis
//  le 21/09/2026 (secondaire/** sur une école primaire — Fatal error
//  mysqli_sql_exception "Champ 'date_fin' inconnu", auto_activer_sequences()
//  appelée par secondaire/pages/parametres/index.php et .../notes/index.php
//  sur la table `sequence` d'une base primaire).
//  Laissés passer dans les deux sens (déjà rendus compatibles, voir
//  fonctions.php : get_annee_active()/get_sequence_active()/
//  get_etablissement()/menu_definition() type-aware) : login.php,
//  logout.php, dashboard.php, profil.php, configurer_securite.php et
//  mot_de_passe_oublie.php (racine, type-aware, certains branchent vers un
//  fichier secondaire/*_contenu.php dédié) et ajax/
//  (racine, partagé avec les deux modules — endpoints JSON appelés en
//  fetch() par des pages secondaire, ex. secondaire/pages/paiements/
//  index.php ou secondaire/pages/enseignants/mon_profil.php ; bloqués ici
//  jusqu'au 17/09/2026, la garde renvoyait alors du HTML à un fetch() qui
//  attendait du JSON — cassé silencieusement côté JS, jamais un Fatal error
//  visible). Chaque fichier ajax/ effectivement utilisé côté secondaire est
//  rendu type-aware individuellement (voir ses propres commentaires) — ne
//  PAS supposer qu'un ajax/ non encore vérifié fonctionne pour autant.
//  type_enseignement_courant() (et non $ETAB_COURANT directement) : couvre
//  aussi le mono-école SANS annuaire (ECOLE_TYPE_SOLO, voir install.php
//  mode « école unique » et ecole_contexte.php) — sans quoi ce garde-fou ne
//  se déclenchait jamais pour une telle installation.
if (function_exists('type_enseignement_courant')) {
    $_rel = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $_app = rtrim((string) parse_url(APP_URL, PHP_URL_PATH), '/');
    if ($_app !== '' && strpos($_rel, $_app . '/') === 0) $_rel = substr($_rel, strlen($_app) + 1);
    $_rel = ltrim($_rel, '/');
    // Séance photo au téléphone (QR code) : page type-aware
    // (pages/eleves/_photos_lib.php) — commune aux deux types, sinon un
    // téléphone pas encore connecté (contexte primaire par défaut) serait
    // renvoyé au tableau de bord avant d'atteindre la connexion.
    $_commun = in_array($_rel, ['login.php', 'logout.php', 'dashboard.php', 'profil.php', 'configurer_securite.php', 'mot_de_passe_oublie.php', 'questions_securite_reporter.php',
                                'pages/eleves/photos_seance.php', 'secondaire/pages/eleves/photos_seance.php',
                                // Vérification publique du certificat de scolarité (QR) : type-aware
                                // — sans cela, un QR d'école secondaire tombait sur « En construction ».
                                'verif_scolarite.php'], true) || str_starts_with($_rel, 'ajax/');
    if (!$_commun) {
        $_secondaire = type_enseignement_courant() === 'secondaire';
        if ($_secondaire && !str_starts_with($_rel, 'secondaire/')) {
            // École secondaire sur une page pages/** (module primaire, pas
            // encore rendue compatible) : page d'attente plutôt qu'un Fatal error.
            require __DIR__ . '/secondaire_en_construction.php';
            exit;
        }
        if (!$_secondaire && str_starts_with($_rel, 'secondaire/')) {
            // École primaire sur une page secondaire/** : rien à y faire,
            // retour au tableau de bord (type-aware) plutôt qu'un Fatal error.
            header('Location: ' . APP_URL . '/dashboard.php');
            exit;
        }
    }
}

// ── Helper interne : prépare, lie les paramètres, exécute ────────────
//  Tous les paramètres sont liés en type « s » (chaîne) : MySQL applique
//  la conversion implicite pour les entiers/dates, et une valeur PHP null
//  est correctement transmise comme NULL SQL — comportement identique aux
//  requêtes préparées PDO utilisées auparavant.
function _db_stmt(string $sql, array $params) {
    global $link;
    $t0   = microtime(true);
    $stmt = mysqli_prepare($link, $sql);
    if ($params) {
        $types = str_repeat('s', count($params));
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    // Compteur de requêtes / temps SQL de la page — affiché en local
    // uniquement (layout/footer.php, db_stats_html()) pour repérer les
    // pages les plus lourdes. Coût négligeable en production.
    $GLOBALS['_db_stats']['n']  = ($GLOBALS['_db_stats']['n'] ?? 0) + 1;
    $GLOBALS['_db_stats']['ms'] = ($GLOBALS['_db_stats']['ms'] ?? 0) + (microtime(true) - $t0) * 1000;
    return $stmt;
}

// Données « globales » relues très souvent dans une même page
// (get_etablissement(), get_annee_active() — fonctions.php) : mémorisées le
// temps de la requête HTTP, PAR BASE D'ÉCOLE (la base peut changer en cours
// de page : login.php, portail association). Vidé automatiquement par
// db_exec() à toute écriture sur ces tables.
function db_cache_cle(string $nom): string {
    $db = function_exists('ecole_courante') ? (string) (ecole_courante()['db_name'] ?? '') : '';
    return $nom . '@' . $db;
}
function db_cache_vider(): void {
    $GLOBALS['_db_cache_globales'] = [];
}

// Indicateur « N requêtes SQL / X ms » (pied de page) — seulement quand
// l'application tourne sur le poste lui-même (localhost), jamais en ligne.
function db_stats_html(): string {
    $hote = explode(':', $_SERVER['HTTP_HOST'] ?? '')[0];
    if (!in_array($hote, ['localhost', '127.0.0.1', '::1'], true)) return '';
    $s = $GLOBALS['_db_stats'] ?? ['n' => 0, 'ms' => 0];
    $total = isset($_SERVER['REQUEST_TIME_FLOAT']) ? (microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000 : 0;
    return '<div style="position:fixed;left:6px;bottom:6px;z-index:2000;background:rgba(15,26,58,.85);color:#fff;'
         . 'font:11px/1.4 monospace;padding:2px 7px;border-radius:4px;pointer-events:none" title="Visible en local uniquement">'
         . (int) $s['n'] . ' requêtes SQL · ' . number_format($s['ms'], 1) . ' ms SQL · ' . number_format($total, 0) . ' ms page</div>';
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
    if (preg_match('/\b(etablissement|annee_scolaire)\b/i', $sql)) db_cache_vider();
    return (int) $n;
}

// Retourne le dernier id auto-incrémenté inséré
function db_last_id(): int {
    global $link;
    return (int) mysqli_insert_id($link);
}
