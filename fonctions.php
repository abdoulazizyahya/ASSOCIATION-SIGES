<?php
// ── Fonctions utilitaires globales ────────────────────────────
// Adapté du projet ABZ_MBE (mêmes conventions : mysqli préparé via
// connexion.php, CSRF, sessions, flash) mais branché sur le VRAI schéma de
// jaynitaare_v2_bd (colonnes Mat_elv/IDClasses/val_annee...), pas sur celui
// d'ABZ_MBE — ce sont deux systèmes différents (décision explicite, voir
// prompt_continuite_jaynitaare_v2.md).

// Démarre la session si pas déjà démarrée
// Nom de cookie + path dédiés à CETTE application : plusieurs projets PHP
// indépendants tournent sur le même hôte (localhost/ABZ_MBE/, .../LAM_ABZ/,
// .../jaynitaare_v2/...). Par défaut PHP utilise le même nom de cookie
// (PHPSESSID) avec path=/ pour tout le monde -> le navigateur envoie le même
// cookie à toutes ces applications, la dernière visitée écrasant la session
// des autres. On isole donc explicitement le cookie de session par nom ET
// par chemin, avant tout session_start() (les paramètres de cookie doivent
// être fixés avant le démarrage de la session).
function session_init() {
    if (session_status() === PHP_SESSION_NONE) {
        session_name('JAYNITAARE_SESSID');
        session_set_cookie_params([
            'path'     => APP_URL . '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

// ── Adresse réseau du serveur (QR codes de vérification) ──────────────
// $_SERVER['HTTP_HOST'] vaut "localhost" quand l'application est ouverte
// depuis le poste serveur lui-même — une valeur inutilisable dans un QR
// code : un téléphone qui scanne "localhost" essaie de se connecter à
// LUI-MÊME, pas au serveur (échec silencieux au niveau réseau, ou pire —
// si un autre service y répond — une page/erreur incohérente). Utilisée
// par les *_verif_base_url() de pdf/verif_*_lib.php pour construire les
// URLs encodées dans les QR des bulletins/reçus/attestations.
//
// Retourne l'hôte à utiliser : inchangé si ce n'est pas localhost/127.0.0.1
// (accès déjà via une vraie adresse réseau) ; sinon la constante
// SERVEUR_LAN_HOST si définie dans config.php (à ne renseigner QUE si le
// serveur a une IP LAN fixe — sinon la laisser commentée : une valeur figée
// devient fausse dès que le réseau change et fait échouer tous les scans) ;
// sinon détection automatique de l'IP LAN réelle de la machine.
function hote_verif_reseau(): string {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $nom_hote = explode(':', $host)[0]; // retire un éventuel :port
    if (!in_array($nom_hote, ['localhost', '127.0.0.1', '::1'], true)) {
        return $host; // déjà une vraie adresse réseau — inchangé
    }
    if (defined('SERVEUR_LAN_HOST') && SERVEUR_LAN_HOST !== '') {
        return SERVEUR_LAN_HOST;
    }
    $ip = detecter_ip_lan();
    return $ip ?? $host;
}

// Détection de l'IP LAN réelle de la machine (interface qui porte la route
// par défaut — celle qu'un téléphone sur le même WiFi doit joindre).
// Recalculée à chaque appel PENDANT une requête donnée mais mémoïsée pour
// cette requête (static) : le réseau ne change pas en cours de requête, mais
// il PEUT changer entre deux générations de documents (partage de connexion
// coupé/rétabli, changement de WiFi...) — c'est justement le cas à gérer.
function detecter_ip_lan(): ?string {
    static $cache = false;
    if ($cache !== false) return $cache;

    $valide = static function ($ip): bool {
        return is_string($ip)
            && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            && !str_starts_with($ip, '127.')
            && $ip !== '0.0.0.0';
    };

    // 1) Socket UDP "connecté" vers une cible externe : AUCUN paquet n'est
    //    réellement émis (UDP sans handshake), mais l'OS choisit l'interface
    //    de sortie selon la table de routage et lui attribue une IP locale
    //    qu'on lit ici. Fonctionne SANS Internet tant qu'une passerelle est
    //    configurée (cas normal en WiFi/LAN, même box sans accès Internet).
    foreach (['8.8.8.8:53', '1.1.1.1:53', '192.168.1.1:53'] as $cible) {
        $s = @stream_socket_client("udp://$cible", $errno, $errstr, 1);
        if (!$s) continue;
        $nom = @stream_socket_get_name($s, false); // "IP:port"
        fclose($s);
        if ($nom && ($pos = strrpos($nom, ':')) !== false) {
            $ip = substr($nom, 0, $pos);
            if ($valide($ip)) return $cache = $ip;
        }
    }

    // 2) Repli : résolution du nom d'hôte de la machine.
    $ip = @gethostbyname(gethostname());
    if ($valide($ip)) return $cache = $ip;

    // 3) Dernier repli (Windows) : première IPv4 privée retournée par ipconfig.
    if (stripos(PHP_OS, 'WIN') === 0) {
        $sortie = @shell_exec('ipconfig');
        if ($sortie && preg_match_all('/IPv4[^:]*:\s*([0-9]{1,3}(?:\.[0-9]{1,3}){3})/i', $sortie, $m)) {
            foreach ($m[1] as $ip) {
                if ($valide($ip)) return $cache = $ip;
            }
        }
    }

    return $cache = null;
}

// Page d'erreur conviviale pour un échec de GÉNÉRATION de PDF (FPDF/TCPDF
// lève une Exception standard en cas de souci — image en cache corrompue/
// incomplète, etc., voir hote_verif_reseau() ci-dessus) — à utiliser dans un
// catch(Throwable) enveloppant la génération, dans TOUT fichier PDF
// accessible publiquement via un jeton "vh" (scan de QR code : bulletins,
// reçus, attestations, tableau d'honneur...). Un visiteur anonyme ne doit
// JAMAIS voir un fatal error brut ; le personnel connecté voit en plus le
// message technique. Termine toujours la requête (never revient).
function pdf_erreur_generation(Throwable $e): never {
    http_response_code(500);
    $connecte = est_connecte();
    $adresse_reseau = 'http://' . hote_verif_reseau() . APP_URL . '/';
    ?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Document indisponible — <?= h(APP_NOM) ?></title>
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/bootstrap/css/bootstrap.min.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
  <style>
    body { background:#0f1a3a; min-height:100vh; display:flex; align-items:center; justify-content:center; font-family:system-ui,sans-serif; }
    .verif-card { background:#fff; border-radius:16px; padding:2.2rem 1.8rem; max-width:460px; width:92%; text-align:center; box-shadow:0 10px 40px rgba(0,0,0,.35); }
    .verif-icon { font-size:3.2rem; }
  </style>
</head>
<body>
  <div class="verif-card">
    <div class="verif-icon text-warning"><i class="bi bi-wifi-off"></i></div>
    <h4 class="mt-2 mb-2">Document momentanément indisponible</h4>
    <p class="text-muted mb-2">
      Si vous avez scanné ce code depuis un téléphone ou une tablette,
      vérifiez qu'il est bien connecté au <strong>même réseau Wi-Fi</strong> que
      l'ordinateur de l'établissement, puis réessayez.
    </p>
    <p class="text-muted mb-3" style="font-size:.85rem">
      Depuis un appareil déjà sur ce réseau, utilisez toujours cette adresse
      (pas « localhost », qui ne fonctionne que sur l'ordinateur lui-même) :<br>
      <code><?= h($adresse_reseau) ?></code>
    </p>
    <?php if ($connecte): ?>
    <div class="alert alert-light border text-start" style="font-size:.78rem">
      <strong>Détail technique (visible car connecté) :</strong><br>
      <?= h($e->getMessage()) ?>
    </div>
    <?php endif; ?>
  </div>
</body>
</html>
    <?php
    exit;
}

// ── Authentification ─────────────────────────────────────────
// $_SESSION['user'] = ['id'=>id_user, 'matricule_ens'=>.., 'nom'=>.., 'prenom'=>..,
//                       'role'=>id_fonction ('DIRECTEUR'|'ENSEIGNANT'|'SECRETAIRE'), 'login'=>..]

function est_connecte(): bool {
    session_init();
    return !empty($_SESSION['user_id']);
}

// Configuration des 2 questions secrètes obligatoire dès la 1ère connexion
// (demande explicite du 22/08/2026, même principe qu'ABZ_MBE) : tant qu'un
// compte n'a pas ses 2 questions, exiger_connexion() le redirige vers
// configurer_securite.php avant de le laisser accéder à quoi que ce soit
// d'autre. Liste d'exemption courte et explicite (jamais de boucle de
// redirection possible) : la page de configuration elle-même et la
// déconnexion.
function exiger_connexion(): void {
    // Membre de l'association en visite lecture seule (est entré dans une
    // école depuis le portail association/) : session valide, pas de compte
    // user local, pas de questions secrètes à configurer.
    if (function_exists('est_visite_association') && est_visite_association()) {
        return;
    }
    if (!est_connecte()) {
        header('Location: ' . APP_URL . '/login.php');
        exit;
    }
    $script_courant = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $exemptes = ['configurer_securite.php', 'logout.php'];
    if (!in_array($script_courant, $exemptes, true) && !utilisateur_a_questions((int) ($_SESSION['user_id'] ?? 0))) {
        header('Location: ' . APP_URL . '/configurer_securite.php');
        exit;
    }
    // Privilèges par utilisateur / règle centrale / rôle sans accès à ce
    // menu : NE bloque plus la page (demande explicite du 11/09/2026 — un
    // menu « retiré » doit rester consultable, seule l'écriture disparaît).
    // Voir acces_page_lecture_seule(), consultée par est_lecture_seule().
}

// ── Privilèges par utilisateur (menus / sous-menus retirés) ──────────
//  Le Directeur — ou un superadmin association entré en écriture — peut
//  RETIRER à un compte l'accès à un groupe de menu entier ('grp:Nom') ou à
//  une entrée précise (son url). Deny-list : une ligne dans
//  acces_utilisateur = une clé refusée ; aucune ligne = accès complet
//  selon le rôle (comportement historique). Ne concerne QUE les comptes
//  école locaux — jamais une visite association ni le FONDATEUR (qui
//  voient déjà tout en lecture seule). UI : pages/utilisateurs/acces.php.

/** Définition unique du menu latéral (layout/menu.php), avec cache statique. */
function menu_definition(): array {
    static $m = null;
    if ($m === null) $m = require __DIR__ . '/layout/menu.php';
    return $m;
}

/**
 * Clés (grp:… ou url) refusées à un compte.
 * [] si aucun refus, si la table n'existe pas encore (migration v53 non
 * appliquée) ou si l'appelant n'est pas un compte école local.
 */
function acces_refuses_utilisateur(?int $id_user = null): array {
    static $cache = [];
    $id_user = $id_user ?? (int) ($_SESSION['user_id'] ?? 0);
    if ($id_user <= 0) return [];
    if (array_key_exists($id_user, $cache)) return $cache[$id_user];
    $refuses = [];
    try {
        foreach (db_all("SELECT cle FROM acces_utilisateur WHERE id_user=?", [$id_user]) as $r) {
            $refuses[$r['cle']] = true;
        }
    } catch (\Throwable $e) {
        // table pas encore migrée — aucune restriction
    }
    return $cache[$id_user] = $refuses;
}

/**
 * Le groupe / l'entrée de menu donnés sont-ils autorisés au compte connecté ?
 * Utilisé par layout/header.php pour masquer les entrées retirées.
 */
function menu_acces_autorise(string $groupe, string $url): bool {
    if (function_exists('est_visite_association') && est_visite_association()) return true;
    // Règle « Privilèges » centrale : 'masque' cache l'entrée pour ce
    // rôle/compte (y compris le fondateur). 'lecture'/'ecriture' = octroi,
    // géré côté header.php (révèle une entrée hors périmètre de rôle).
    if (function_exists('niveau_central') && niveau_central($groupe, $url) === 'masque') return false;
    // La deny-list locale par compte (acces_utilisateur) ne masque plus le
    // menu (demande explicite du 11/09/2026) : l'entrée reste visible et
    // cliquable, seule l'écriture disparaît (est_lecture_seule() via
    // acces_page_lecture_seule() ; bandeau + boutons masqués, layout/
    // header.php + assets/css/style.css). Pour la cacher réellement, poser
    // une règle centrale 'masque' (association/acces.php) plutôt qu'une
    // entrée acces_utilisateur.
    return true;
}

/** Chemin de la page courante relatif à la racine de l'app (ex. « pages/eleves/liste.php »). */
function page_courante_relative(): string {
    $s    = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? ''));
    $base = rtrim((string) parse_url(APP_URL, PHP_URL_PATH), '/');
    if ($base !== '' && strpos($s, $base . '/') === 0) $s = substr($s, strlen($base) + 1);
    return ltrim($s, '/');
}

/**
 * Libellé de menu de la page courante (ex. « Saisie des notes »), ou null
 * si la page n'est pas une entrée directe du menu. Utilisé par le bandeau
 * « lecture seule » (layout/header.php) pour nommer la rubrique concernée.
 */
function page_menu_label(): ?string {
    $rel = page_courante_relative();
    if ($rel === '') return null;
    foreach (menu_definition() as $items) {
        foreach ($items as $it) {
            if (($it[0] ?? '') === '--') continue;
            if (($it[1] ?? '') === $rel) return $it[0];
        }
    }
    return null;
}

/**
 * La page courante appartient-elle à un menu / sous-menu retiré au compte
 * connecté (deny-list acces_utilisateur) OU son rôle n'y a par défaut aucune
 * entrée de menu ? Ne BLOQUE plus la page depuis le 11/09/2026 (demande
 * explicite) : le retour sert uniquement à forcer la LECTURE SEULE
 * (est_lecture_seule()) — la page reste consultable, les boutons
 * d'enregistrement disparaissent / les écritures sont refusées en amont.
 * 'dashboard.php', 'profil.php' et 'logout.php' ne sont jamais concernés
 * (anti-verrouillage — voir aussi pages/utilisateurs/acces.php).
 */
function acces_page_lecture_seule(): bool {
    if (function_exists('est_visite_association') && est_visite_association()) return false;

    $rel = page_courante_relative();
    if ($rel === '') return false;
    if (in_array(basename($rel), ['dashboard.php', 'profil.php', 'logout.php'], true)) return false;

    $fondateur = function_exists('est_fondateur') && est_fondateur();
    $refuses   = acces_refuses_utilisateur();                 // deny-list locale (par compte)

    // Rôles « effectifs » (mêmes règles que le rendu du menu, header.php).
    $role      = function_exists('role_connecte') ? role_connecte() : '';
    $roles_eff = $role !== '' ? [$role] : [];
    if ($role !== 'ENSEIGNANT' && function_exists('agent_est_aussi_enseignant') && agent_est_aussi_enseignant()) {
        $roles_eff[] = 'ENSEIGNANT';
    }

    // Index url / dossier → [groupe, rôles autorisés de l'entrée].
    static $index = null;
    if ($index === null) {
        $index = ['url' => [], 'dossier' => []];
        foreach (menu_definition() as $groupe => $items) {
            foreach ($items as $it) {
                if (($it[0] ?? '') === '--') continue;
                $meta = ['g' => $groupe, 'roles' => $it[3] ?? []];
                $u = $it[1];
                $index['url'][$u] = $meta;
                $d = strpos($u, '/') !== false ? basename(dirname($u)) : '';
                if ($d !== '') $index['dossier'][$d][$u] = $meta;
            }
        }
    }

    // Une entrée est « interdite » au profil courant si la deny-list locale la
    // retire OU si son rôle n'y figure pas — sauf fondateur (lecture globale,
    // déjà géré par ailleurs, donc jamais forcé lecture seule PAR CE biais).
    $interdite = function (string $u, array $meta) use ($refuses, $roles_eff, $fondateur): bool {
        $g = $meta['g'];
        if (isset($refuses['grp:' . $g]) || isset($refuses[$u])) return true;
        if ($fondateur) return false;
        $roles = $meta['roles'];
        return !empty($roles) && !array_intersect($roles_eff, $roles);
    };

    if (isset($index['url'][$rel])) return $interdite($rel, $index['url'][$rel]);

    // Page « fille » d'un dossier de module : lecture seule si TOUTES les
    // entrées de menu de ce dossier sont interdites au profil.
    $d = strpos($rel, '/') !== false ? basename(dirname($rel)) : '';
    if ($d !== '' && !empty($index['dossier'][$d])) {
        foreach ($index['dossier'][$d] as $u => $meta) {
            if (!$interdite($u, $meta)) return false;
        }
        return true;
    }
    return false;
}

function utilisateur_connecte(): array {
    session_init();
    return $_SESSION['user'] ?? [];
}

function role_connecte(): string {
    return $_SESSION['user']['role'] ?? '';
}

/** matricule_ens de l'utilisateur connecté (null si personne connectée). */
function matricule_ens_courant(): ?string {
    session_init();
    $mat = $_SESSION['user']['matricule_ens'] ?? null;
    return $mat !== null && $mat !== '' ? (string) $mat : null;
}

// Un compte non-ENSEIGNANT (ex. COMPTABLE) est-il par ailleurs affecté à
// enseigner une classe cette année ? (demande explicite du 22/08/2026 : les
// menus Discipline/Pédagogie ne s'affichent pour un Agent financier QUE
// s'il est EN MÊME TEMPS enseignant). Le rôle du compte reste unique
// (user.matricule_ens -> enseignant.id_fonction, un seul rôle "officiel"),
// donc le signal factuel retenu est une affectation réelle dans
// enseignat_classe/enseignat_classe_arabe pour l'année active — pas un 2e
// rôle qui n'existe pas dans ce modèle de données.
function agent_est_aussi_enseignant(): bool {
    $mat = matricule_ens_courant();
    if (!$mat) return false;
    $val_annee = get_annee_active()['val_annee'] ?? '';
    if (!$val_annee) return false;
    $nb = (int) db_val("SELECT COUNT(*) FROM enseignat_classe WHERE matricule_ens=? AND val_annee=?", [$mat, $val_annee]);
    if ($nb > 0) return true;
    return (int) db_val("SELECT COUNT(*) FROM enseignat_classe_arabe WHERE matricule_ens=? AND val_annee=?", [$mat, $val_annee]) > 0;
}

// ── Restriction des classes visibles par un enseignant (piste française
// « compétences » uniquement — pages/enseignants/liste.php, onglet
// « Affectation des classes », demande explicite du 29/08/2026) ───────────
// DIRECTEUR/SECRETAIRE continuent de tout voir (retour null = « pas de
// restriction ») ; un ENSEIGNANT (ou un COMPTABLE affecté à enseigner,
// agent_est_aussi_enseignant() ci-dessus) ne voit que les classes où il a
// été affecté (enseignat_classe) pour l'année en cours. Ne concerne QUE
// pages/notes(_arabe)/… non — piste arabe hors scope, voir enseignat_classe
// vs enseignat_classe_arabe.
// $piste : 'fr' -> enseignat_classe ; 'ar' -> enseignat_classe_arabe ;
// 'union' (défaut) -> les deux. Les enseignant(e)s FR et AR sont des
// personnes distinctes, affectées séparément (pages/enseignants/liste.php,
// onglet Affectation) : une page de la piste française ne doit filtrer que
// sur enseignat_classe, une page arabe que sur enseignat_classe_arabe.
// DIRECTEUR / SECRETAIRE / FONDATEUR ne sont jamais restreints (null).
function classes_ids_visibles(string $val_annee, string $piste = 'union'): ?array {
    if (in_array(role_connecte(), ['DIRECTEUR', 'SECRETAIRE'], true)) return null;
    if (function_exists('est_fondateur') && est_fondateur()) return null;
    $mat = matricule_ens_courant();
    if (!$mat) return [];
    $tables = match ($piste) {
        'fr'    => ['enseignat_classe'],
        'ar'    => ['enseignat_classe_arabe'],
        default => ['enseignat_classe', 'enseignat_classe_arabe'],
    };
    $ids = [];
    foreach ($tables as $t) {
        foreach (db_all("SELECT IDClasses FROM $t WHERE matricule_ens=? AND val_annee=?", [$mat, $val_annee]) as $r) {
            $ids[(int) $r['IDClasses']] = true;
        }
    }
    return array_map('intval', array_keys($ids));
}

// Filtre une liste de classes déjà chargée (tableaux avec clé 'IDClasses')
// selon classes_ids_visibles() — à appeler juste après le db_all() qui
// construit le <select>/la liste de classes d'une page Pédagogie/Discipline,
// jamais sur des requêtes Finances/RH (non concernées).
function filtrer_classes_visibles(array $classes, string $val_annee, string $piste = 'union'): array {
    $ids = classes_ids_visibles($val_annee, $piste);
    if ($ids === null) return $classes;
    return array_values(array_filter($classes, fn(array $c): bool => in_array((int) $c['IDClasses'], $ids, true)));
}

// Garde SERVEUR : refuse net (403) l'accès d'un(e) enseignant(e) à une
// classe hors de son périmètre — à appeler dès qu'une page reçoit un
// identifiant de classe en paramètre (?classe=, IDClasses…), APRÈS les
// gardes de rôle. $id_classe 0/vide = pas de classe encore choisie (laissé
// passer : les listes sont déjà filtrées par filtrer_classes_visibles()).
function exiger_acces_classe(int $id_classe, string $val_annee, string $piste = 'union'): void {
    if ($id_classe <= 0) return;
    $ids = classes_ids_visibles($val_annee, $piste);
    if ($ids === null) return;                       // rôle non restreint
    if (!in_array($id_classe, $ids, true)) {
        http_response_code(403);
        die('<div style="font-family:sans-serif;padding:2rem;color:#b91c1c">
             Accès refusé : cette classe ne fait pas partie de vos affectations.</div>');
    }
}

// Idem, à partir d'un élève : résout sa classe pour l'année active via
// `inscrire` puis délègue à exiger_acces_classe(). Un élève non inscrit
// cette année est invisible pour un(e) enseignant(e) restreint(e).
function exiger_acces_eleve(int $id_eleve, string $piste = 'union'): void {
    $val_annee = get_annee_active()['val_annee'] ?? '';
    $ids = classes_ids_visibles($val_annee, $piste);
    if ($ids === null) return;                       // rôle non restreint
    if ($id_eleve <= 0) return;
    $classes_eleve = array_map('intval', array_column(
        db_all("SELECT IDClasses FROM inscrire WHERE id_eleve=? AND val_annee=?", [$id_eleve, $val_annee]),
        'IDClasses'
    ));
    if (!array_intersect($classes_eleve, $ids)) {
        http_response_code(403);
        die('<div style="font-family:sans-serif;padding:2rem;color:#b91c1c">
             Accès refusé : cet élève ne fait pas partie de vos classes.</div>');
    }
}

// ── Questions secrètes (récupération de mot de passe — migration_v45) ────

function utilisateur_a_questions(int $id_user): bool {
    if (!$id_user) return false;
    return (int) db_val("SELECT COUNT(*) FROM user_question_secrete WHERE id_user = ?", [$id_user]) >= 2;
}

function normaliser_reponse(string $r): string {
    return mb_strtolower(trim($r), 'UTF-8');
}

function exiger_role(array $roles): void {
    exiger_connexion();
    // Membre association en visite : lecture accordée sur toutes les pages
    // (« visiter toutes les infos »). Les écritures restent bloquées par
    // csrf_verifier() / db_exec() (est_lecture_seule()).
    if (function_exists('est_visite_association') && est_visite_association()) {
        return;
    }
    // Règle « Privilèges » centrale (association/acces.php) : un octroi
    // 'lecture' ou 'ecriture' sur cette page ouvre l'accès à ce rôle/compte
    // même s'il n'est pas dans la liste blanche. L'écriture reste gouvernée
    // par est_lecture_seule().
    if (function_exists('niveau_central_page_courante')
        && in_array(niveau_central_page_courante(), ['lecture', 'ecriture'], true)) {
        return;
    }
    // FONDATEUR : accès en LECTURE à toutes les pages de son école (mêmes
    // écritures bloquées en aval). Sa seule page d'écriture — directeur.php —
    // pose sa propre garde exiger_role(['FONDATEUR']) qui passe par ici aussi.
    if (function_exists('est_fondateur') && est_fondateur() && !in_array('FONDATEUR', $roles, true)) {
        return;
    }
    if (!in_array(role_connecte(), $roles, true)) {
        die('<div style="font-family:sans-serif;padding:2rem;color:red">
             Accès refusé. Vous n\'avez pas les droits nécessaires.</div>');
    }
}

// Garde d'accès SERVEUR (pas seulement le masquage de menu de
// layout/header.php) pour tout le module Pédagogie/Discipline — notes,
// absences, bulletins, statistiques, conseils de classe, résultats annuels,
// compétences/matières, pistes française ET arabe. Verrouillage explicite
// demandé le 22/08/2026 (suite à l'ajout du rôle COMPTABLE) : un Agent
// financier ne doit PAS pouvoir accéder à ces pages même en devinant l'URL
// directe, sauf s'il est EN MÊME TEMPS affecté à enseigner une classe cette
// année (agent_est_aussi_enseignant()) — exactement la même règle que la
// visibilité du menu, appliquée ici côté serveur pour ne plus dépendre
// uniquement de l'affichage. À appeler à la place de exiger_connexion() (pas
// en plus : l'appelle déjà en interne).
function exiger_acces_pedagogie(): void {
    exiger_connexion();
    if (function_exists('est_visite_association') && est_visite_association()) return;
    if (function_exists('niveau_central_page_courante')
        && in_array(niveau_central_page_courante(), ['lecture', 'ecriture'], true)) return;
    if (function_exists('est_fondateur') && est_fondateur()) return;
    if (in_array(role_connecte(), ['DIRECTEUR', 'ENSEIGNANT', 'SECRETAIRE'], true)) return;
    if (agent_est_aussi_enseignant()) return;
    die('<div style="font-family:sans-serif;padding:2rem;color:red">
         Accès refusé. Vous n\'avez pas les droits nécessaires.</div>');
}

// Écarte explicitement UN rôle précis d'une page par ailleurs ouverte à tous
// les connectés (exiger_connexion()) — utilisé pour les 3 documents élève
// que le profil COMPTABLE (Agent financier) ne doit jamais imprimer (fiche
// PDF, certificat de scolarité, carte scolaire — demande explicite du
// 22/08/2026), sans avoir à réénumérer DIRECTEUR/ENSEIGNANT/SECRETAIRE (et
// tout futur rôle) dans un exiger_role() à liste blanche. À appeler APRÈS
// exiger_connexion() (suppose déjà un utilisateur connecté).
function interdire_role(string $role_interdit, string $message = 'Accès refusé.'): void {
    if (function_exists('est_visite_association') && est_visite_association()) return;
    if (role_connecte() === $role_interdit) {
        die('<div style="font-family:sans-serif;padding:2rem;color:red">' . h($message) . '</div>');
    }
}

// ── Garde d'accès : année scolaire RÉELLEMENT active ─────────────────
// Contrairement à get_annee_active() (qui retombe volontairement sur l'année
// la plus récente pour ne jamais casser un calcul déjà en cours), cette
// garde exige qu'une année soit EXPLICITEMENT activée (Etat_annee_scolaire=1)
// — demande explicite du 18/08/2026 : tant qu'aucune année n'est active, tout
// le module Pédagogie (menus Discipline + Pédagogie) doit être inaccessible,
// jamais une page qui travaillerait silencieusement sur "la dernière année en
// date" sans que l'admin l'ait choisie. Redirige vers le tableau de bord avec
// un message flash si aucune année n'est active — protection SERVEUR en
// complément du blocage JS au clic sur le menu (voir layout/header.php et
// layout/footer.php, modale #modalAnneeInactive), pour un accès direct par
// URL qui contournerait le clic.
function exiger_annee_active(): void {
    exiger_connexion();
    $active = db_val("SELECT COUNT(*) FROM annee_scolaire WHERE Etat_annee_scolaire=1");
    if (!$active) {
        flash_set('erreur', "Aucune année scolaire active — activez une année dans Paramètres pour accéder à ce module.");
        rediriger('dashboard.php');
    }
}

// ── Messages flash ────────────────────────────────────────────

function flash_set(string $type, string $msg): void {
    session_init();
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function flash_get(): ?array {
    session_init();
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

function flash_html(): string {
    $f = flash_get();
    if (!$f) return '';
    $map = [
        'succes'  => ['success', 'check-circle'],
        'erreur'  => ['danger',  'exclamation-triangle'],
        'info'    => ['info',    'info-circle'],
        'alerte'  => ['warning', 'exclamation-circle'],
    ];
    [$bs, $icon] = $map[$f['type']] ?? ['secondary', 'info-circle'];
    return '<div class="alert alert-' . $bs . ' alert-dismissible fade show d-flex align-items-center gap-2 py-2" role="alert">
              <i class="bi bi-' . $icon . '"></i>
              <span>' . h($f['msg']) . '</span>
              <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
            </div>';
}

// ── Sécurité ──────────────────────────────────────────────────

// Échappe les sorties HTML
function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Nom d'élève avec son nom arabe entre parenthèses (eleve.Nom_arabe_elv,
// v27 — pas toujours renseigné, ~224/271). Utilisé sur les pages de saisie
// de notes (FR + arabe) pour repérer l'élève dans les 2 écritures. Retourne
// du HTML déjà échappé, prêt à échoïr directement (jamais re-passer par h()).
// $riche=false : texte brut (échappé quand même) pour un contexte sans HTML
// (ex. <option> d'un <select>) — pas de <span dir="rtl"> dans ce cas.
function nom_eleve_aff(?string $nom_fr, ?string $prenom_fr, ?string $nom_ar, bool $riche = true): string {
    $fr = trim(mb_strtoupper((string) $nom_fr) . ' ' . (string) $prenom_fr);
    $ar = trim((string) $nom_ar);
    if ($ar === '') return h($fr);
    if (!$riche) return h($fr . ' (' . $ar . ')');
    return h($fr) . ' <span class="text-muted" dir="rtl" lang="ar" style="font-weight:400;font-size:.92em">(' . h($ar) . ')</span>';
}

// Nettoie une entrée POST
function post(string $key, string $default = ''): string {
    return trim((string)($_POST[$key] ?? $default));
}

// Jeton CSRF
function csrf_generer(): string {
    session_init();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(20));
    }
    return $_SESSION['csrf'];
}

function csrf_champ(): string {
    return '<input type="hidden" name="csrf" value="' . csrf_generer() . '">';
}

function csrf_verifier(): void {
    session_init();
    // Visite association en lecture seule : tout traitement de formulaire
    // (POST) est refusé — point de contrôle unique, tous les enregistrements
    // de l'application passent par ici. Filet complémentaire : db_exec().
    if (function_exists('est_lecture_seule') && est_lecture_seule()) {
        $motif = (function_exists('est_visite_association') && est_visite_association())
            ? "Visite association — consultation en lecture seule."
            : "Cette rubrique est en consultation seule pour votre profil "
              . "(l'enregistrement revient à l'agent financier / aux enseignant(e)s).";
        die('<div style="font-family:sans-serif;padding:2rem;color:#b45309">'
          . h($motif) . ' Aucune modification n\'est possible ici.</div>');
    }
    $token = $_POST['csrf'] ?? $_GET['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
        die('Requête invalide (CSRF).');
    }
}

// ── Redirection ───────────────────────────────────────────────

function rediriger(string $url): void {
    header('Location: ' . APP_URL . '/' . ltrim($url, '/'));
    exit;
}

// ── Dates ─────────────────────────────────────────────────────
// Les dates de naissance héritées (Date_naiss_elv/date_naiss_ens) sont
// stockées en varchar, pas toujours au format ISO — strtotime() reste
// tolérant sur les formats courants (YYYY-MM-DD, DD/MM/YYYY...).
function date_fr(?string $d): string {
    if (!$d) return '—';
    $t = strtotime($d);
    return $t ? date('d/m/Y', $t) : h($d);
}

// ── Données globales souvent utilisées ───────────────────────

function get_annee_active(): array {
    return db_one("SELECT * FROM annee_scolaire WHERE Etat_annee_scolaire=1 LIMIT 1")
        ?? db_one("SELECT * FROM annee_scolaire ORDER BY val_annee DESC LIMIT 1")
        ?? ['val_annee' => '—', 'Etat_annee_scolaire' => 0];
}

function get_etablissement(): array {
    // Contexte neutre (multi-école, aucune choisie) : aucune identité d'école
    // — la page appelante doit afficher un habillage générique (login.php).
    if (function_exists('est_contexte_neutre') && est_contexte_neutre()) return [];
    return db_one("SELECT * FROM etablissement LIMIT 1") ?? [];
}

// ── Isolation des fichiers uploadés par école (multi-établissement) ──────
// Les logos / signatures / documents étaient écrits sous des noms FIXES
// (assets/uploads/logo_etab.jpg…) → en multi-école, l'upload d'une école
// ÉCRASAIT celui d'une autre. On préfixe désormais par un sous-dossier
// dédié à l'école courante : assets/uploads/etab/<code>/… . En mono-école
// (annuaire absent) le préfixe est vide → comportement historique inchangé.
// Le chemin relatif retourné est stocké TEL QUEL dans etablissement.logo /
// etablissement.signature : tous les lecteurs existants (« assets/uploads/ »
// . $etab['logo']) continuent de fonctionner sans modification.
function upload_prefixe_etab(): string {
    $e = function_exists('ecole_courante') ? ecole_courante() : null;
    $code = $e['code'] ?? '';
    return $code !== '' ? 'etab/' . strtolower(preg_replace('/[^a-z0-9]/i', '', $code)) . '/' : '';
}

// Crée si besoin le sous-dossier d'upload de l'école courante et retourne
// son chemin absolu (avec / final). $base = racine assets/uploads.
function upload_dir_etab(string $base): string {
    $dir = rtrim($base, '/\\') . '/' . upload_prefixe_etab();
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

// ── Couleurs personnalisables du bulletin PDF (table pdf_couleur) ───
// Mémoïsé (une seule requête par génération de PDF, même en mode lot —
// pas 1 requête par appel SetFillColor() ni par élève imprimé). Clé
// inconnue ou pas encore migrée (table absente) -> couleur de secours
// blanche plutôt qu'une erreur, pour ne jamais casser un PDF existant.
function couleur_pdf(string $cle): array {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db_all("SELECT cle, r, g, b FROM pdf_couleur") as $c) {
                $cache[$c['cle']] = [(int) $c['r'], (int) $c['g'], (int) $c['b']];
            }
        } catch (Throwable $e) {
            // Table pas encore créée (avant migration v30) — cache vide,
            // repli sur blanc ci-dessous pour chaque clé demandée.
        }
    }
    return $cache[$cle] ?? [255, 255, 255];
}

// Applique une couleur de fond nommée sur un objet PDF (FPDF ou TCPDF —
// les deux exposent SetFillColor($r,$g,$b)).
function pdf_fill($pdf, string $cle): void {
    [$r, $g, $b] = couleur_pdf($cle);
    $pdf->SetFillColor($r, $g, $b);
}

function get_sequence_active(): array {
    return db_one(
        "SELECT s.*, t.libelle_trim
         FROM sequence s JOIN trimestre t ON t.id_trim = s.id_trim
         WHERE s.etat = 1 LIMIT 1"
    ) ?? [];
}

// ── Passage en classe supérieure automatique (migration_v36) ───────────
// Appelée depuis pages/parametres/index.php (onglet=annees, actions
// annee_creer ET annee_activer — l'utilisateur a dit « après avoir créé OU
// activé », les deux déclenchent). Lit `decision_conseil_annuel` de l'année
// qui vient de se terminer (source unique de vérité, voir migration_v36.sql
// et resultat_annuel_valider_classe() dans pages/resultat_annuel/commun.php
// qui la matérialise pour les décisions automatiques moyenne>=10) et
// inscrit chaque élève dans $nouvelle_annee : Admis -> next_classe avec
// Statut_elv='Non' (pas redoublant) ; Redoublement -> SA MÊME classe avec
// Statut_elv='Oui' (redoublant, mêmes valeurs que le select "Statut
// scolaire" de pages/eleves/form.php). Exclu/Abandon/Admis sans
// classe_suivante configurée : jamais réinscrits automatiquement, décision
// humaine requise (le secrétariat les inscrira à la main si besoin).
// Idempotent : un élève déjà inscrit (n'importe quelle classe) pour
// $nouvelle_annee n'est jamais retouché — sûr à rappeler plusieurs fois
// (création ET activation de la même année) ou après une inscription
// manuelle déjà faite par le secrétariat.
function appliquer_promotions_annee(string $annee_precedente, string $nouvelle_annee): array {
    if ($annee_precedente === '' || $nouvelle_annee === '' || $annee_precedente === $nouvelle_annee) {
        return ['inscrits' => 0, 'ignores' => 0];
    }
    $decisions = db_all(
        "SELECT dc.id_eleve, dc.decision, dc.next_classe, dc.classe AS classe_origine
         FROM decision_conseil_annuel dc
         JOIN eleve e ON e.id_eleve = dc.id_eleve AND e.statut = 'actif'
         WHERE dc.val_annee = ?",
        [$annee_precedente]
    );

    $nb_inscrits = 0; $nb_ignores = 0;
    foreach ($decisions as $d) {
        $eid = (int) $d['id_eleve'];

        $deja = (int) db_val("SELECT COUNT(*) FROM inscrire WHERE id_eleve=? AND val_annee=?", [$eid, $nouvelle_annee]);
        if ($deja > 0) { $nb_ignores++; continue; }

        if ($d['decision'] === 'Admis' && !empty($d['next_classe'])) {
            $classe_dest = (int) $d['next_classe'];
            $statut = 'Non';
        } elseif ($d['decision'] === 'Redoublement') {
            $classe_dest = (int) $d['classe_origine'];
            $statut = 'Oui';
        } else {
            // Admis sans classe suivante connue, Exclu, Abandon : jamais
            // réinscrit automatiquement.
            $nb_ignores++;
            continue;
        }
        db_exec(
            "INSERT INTO inscrire (id_eleve, IDClasses, val_annee, Date_Inscrire, Statut_elv) VALUES (?, ?, ?, CURDATE(), ?)",
            [$eid, $classe_dest, $nouvelle_annee, $statut]
        );
        $nb_inscrits++;
    }
    return ['inscrits' => $nb_inscrits, 'ignores' => $nb_ignores];
}

// ── Configuration pédagogique (barème par compétence) — report automatique
//    vers une nouvelle année ─────────────────────────────────────────────
// `discipline` (barème orale/écrite/pratique/savoir_etre + actif, par classe
// + compétence + ANNÉE) devait jusqu'ici être ressaisi intégralement à
// chaque nouvelle année scolaire (pages/competences/liste.php écrit toujours
// avec `annee_scol = année active`) — demande explicite du 18/08/2026 :
// « la configuration de compétence ne devrait pas se faire par année, une
// fois configurée elle doit s'appliquer à toutes les années ». Plutôt que de
// retirer la dimension année du schéma (utile si l'établissement fait un
// jour évoluer un barème d'une année sur l'autre — cas réel possible, jamais
// empêché), cette fonction REPORTE automatiquement la configuration de
// l'année précédente vers la nouvelle dès sa création/activation — même
// principe et mêmes points d'appel que appliquer_promotions_annee()
// ci-dessus. Idempotente et non destructive : seules les lignes (classe,
// compétence) qui n'existent PAS ENCORE pour $nouvelle_annee sont copiées —
// une compétence déjà reconfigurée à la main pour la nouvelle année n'est
// jamais écrasée.
function reporter_bareme_annee(string $annee_precedente, string $nouvelle_annee): int {
    if ($annee_precedente === '' || $nouvelle_annee === '' || $annee_precedente === $nouvelle_annee) {
        return 0;
    }
    $rows = db_all(
        "SELECT d.* FROM discipline d
         WHERE d.annee_scol = ?
           AND NOT EXISTS (
               SELECT 1 FROM discipline d2
               WHERE d2.IDClasses = d.IDClasses AND d2.id_comp = d.id_comp AND d2.annee_scol = ?
           )",
        [$annee_precedente, $nouvelle_annee]
    );
    foreach ($rows as $r) {
        db_exec(
            "INSERT INTO discipline (IDClasses, id_comp, annee_scol, orale, ecrite, pratique, savoir_etre, total_points, actif)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [(int) $r['IDClasses'], (int) $r['id_comp'], $nouvelle_annee, $r['orale'], $r['ecrite'], $r['pratique'], $r['savoir_etre'], $r['total_points'], (int) $r['actif']]
        );
    }
    return count($rows);
}

// ── Barème de référence (gabarit APC standard livré avec l'application) ──
// `bareme_reference` (code_niveau, id_comp, points…) est chargée par
// bd/assoc/seed_ref_ecole.sql à la création / au vidage d'une école. Elle
// sert de GABARIT DE DÉPART : quand une classe est créée, ou quand la
// première année scolaire d'une école neuve est ouverte, on en dérive les
// lignes `discipline` (barème de travail, par classe et par année) qui
// n'existent pas encore. Jamais destructif — un barème déjà saisi n'est
// pas touché. No-op si `bareme_reference` est absente (ancienne install).
function bareme_reference_dispo(): bool {
    static $ok = null;
    if ($ok === null) {
        $ok = (bool) db_val(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'bareme_reference'"
        );
    }
    return $ok;
}

// Crée les lignes `discipline` manquantes pour $val_annee à partir de
// `bareme_reference`, éventuellement limité à certaines classes.
// Retourne le nombre de lignes créées.
function appliquer_bareme_reference(string $val_annee, ?array $ids_classes = null): int {
    if ($val_annee === '' || !bareme_reference_dispo()) return 0;

    $filtre = '';
    if ($ids_classes !== null) {
        $ids = array_filter(array_map('intval', $ids_classes));
        if (!$ids) return 0;
        $filtre = ' AND c.IDClasses IN (' . implode(',', $ids) . ')';
    }
    return db_exec(
        "INSERT INTO discipline (IDClasses, id_comp, annee_scol, orale, ecrite, pratique, savoir_etre, total_points, actif)
         SELECT c.IDClasses, b.id_comp, ?, b.orale, b.ecrite, b.pratique, b.savoir_etre, b.total_points, b.actif
         FROM bareme_reference b
         JOIN classe c ON c.Niveau = b.code_niveau
         WHERE NOT EXISTS (
             SELECT 1 FROM discipline d
             WHERE d.IDClasses = c.IDClasses AND d.id_comp = b.id_comp AND d.annee_scol = ?
         )" . $filtre,
        [$val_annee, $val_annee]
    );
}

// ── Section (Fr/An) d'une classe ou d'un niveau — bascule d'affichage ───
// Un niveau anglophone (niveau.Section='An') n'affecte QUE l'affichage des
// libellés de compétences/groupes (le jeu langue='An', simple jumeau
// bilingue de code_comp/ordre_affichage — voir notes_apc.php) : la saisie
// et le calcul restent TOUJOURS sur le jeu langue='Fr' (competences_classe(),
// discipline, composer_sequence). Point d'entrée UNIQUE pour cette bascule
// — plusieurs endroits du code (saisie de notes, relevés, barème par
// niveau, bulletins) la réimplémentaient chacun séparément avant le
// 26/08/2026, avec le risque qu'un endroit oublie la bascule et affiche du
// français pour une classe/un niveau anglophone (plusieurs cas trouvés et
// corrigés ce jour-là) — centralisé ici pour que les prochains endroits
// n'aient plus à la redéfinir. bulletin_trimestriel.php/bulletin_annuel.php
// gardent leur propre cache local historique (déjà correct, non touché
// pour ne pas risquer une régression sur du code qui fonctionne).
function section_niveau(string $code_niveau): string {
    static $cache = [];
    if (!array_key_exists($code_niveau, $cache)) {
        $cache[$code_niveau] = (string) (db_val("SELECT Section FROM niveau WHERE LibelleNiveau=?", [$code_niveau]) ?: 'Fr');
    }
    return $cache[$code_niveau];
}
function section_classe(int $id_classe): string {
    static $cache = [];
    if (!array_key_exists($id_classe, $cache)) {
        $cache[$id_classe] = (string) (db_val(
            "SELECT n.Section FROM classe c JOIN niveau n ON n.LibelleNiveau = c.Niveau WHERE c.IDClasses=?",
            [$id_classe]
        ) ?: 'Fr');
    }
    return $cache[$id_classe];
}
// Libellé à afficher pour une ligne competences_classe()/bareme_par_niveau()
// (nom_comp_en si section anglophone et libellé EN disponible, sinon repli
// sur le français — un jumeau EN manquant ne doit jamais produire un blanc).
function libelle_comp_affiche(array $c, string $section): string {
    return ($section === 'An' && !empty($c['nom_comp_en'])) ? $c['nom_comp_en'] : $c['nom_comp'];
}
function libelle_groupe_comp_affiche(array $c, string $section): string {
    if ($section !== 'An') return $c['libelle_groupe_comp'];
    return !empty($c['libelle_groupe_comp_en']) ? $c['libelle_groupe_comp_en'] : $c['libelle_groupe_comp'];
}

// ── Visibilité pédagogique (compétences / groupes) par niveau ───
// Ajouté avec pages/competences/liste.php (onglet Barème par niveau) : une
// compétence peut être désactivée pour un niveau donné (`discipline.actif`,
// répliqué sur toutes les classes du niveau comme le reste du barème), et
// dans ce cas elle ne doit plus apparaître nulle part — saisie de notes,
// bulletins, statistiques (modules futurs, pas encore construits). Ces deux
// fonctions centralisent la règle pour que ces futurs modules l'appliquent
// tous de la même façon plutôt que de la réécrire à chaque endroit.
//
// Un niveau non encore configuré dans `discipline`/`groupe_competence_niveau`
// est traité comme "tout actif" (comportement par défaut, rétrocompatible
// avec les données existantes avant l'ajout de ces indicateurs).

function competence_est_active(int $id_comp, string $code_niveau, string $val_annee): bool {
    $ligne = db_one(
        "SELECT d.actif FROM discipline d
         JOIN classe c ON c.IDClasses = d.IDClasses
         WHERE d.id_comp = ? AND c.Niveau = ? AND d.annee_scol = ?
         LIMIT 1",
        [$id_comp, $code_niveau, $val_annee]
    );
    return $ligne === null ? true : (bool) $ligne['actif'];
}

function groupe_competence_est_visible(int $id_groupe_comp, string $code_niveau, string $val_annee): bool {
    // 1) Bascule manuelle (onglet « Groupes par niveau ») : si le groupe est
    //    explicitement associé mais désactivé pour ce niveau, invisible net.
    //    Depuis le 21/08/2026, l'écran d'admin n'écrit plus jamais actif=0
    //    (une seule case « Assigné » : cochée -> actif=1, décochée -> ligne
    //    supprimée — l'ancienne distinction associé/actif n'apportait rien
    //    en pratique, voir pages/competences/liste.php) ; cette branche reste
    //    en place pour rester correcte sur d'éventuelles lignes actif=0
    //    encore présentes en base d'avant ce changement.
    $assoc = db_one(
        "SELECT actif FROM groupe_competence_niveau WHERE code_niveau=? AND id_groupe_comp=?",
        [$code_niveau, $id_groupe_comp]
    );
    if ($assoc !== null && !$assoc['actif']) return false;

    // 2) Règle automatique : si le groupe a au moins une compétence et
    //    qu'elles sont TOUTES désactivées pour ce niveau, le groupe se
    //    masque de lui-même (pas d'écriture en base, recalculé à la lecture).
    $competences = db_all("SELECT id_comp FROM competence WHERE id_groupe_comp=?", [$id_groupe_comp]);
    if (!$competences) return true;
    foreach ($competences as $c) {
        if (competence_est_active((int) $c['id_comp'], $code_niveau, $val_annee)) return true;
    }
    return false;
}

// ── Barème complet d'un niveau, groupé par groupe de compétences assigné ──
// Extrait le 21/08/2026 de pages/competences/liste.php (onglet Barème) pour
// être réutilisé tel quel par les exports PDF/Excel (pdf/bareme_niveau.php,
// pages/competences/excel_bareme.php) sans dupliquer la logique en 3
// endroits. Seuls les groupes ASSIGNÉS au niveau (onglet « Groupes par
// niveau », voir migration_v44) apparaissent — 'assigne' vaut false si aucun
// groupe n'est assigné, auquel cas 'groupes' reste vide (appelant : inviter
// à assigner d'abord, jamais rien inventer/afficher par défaut).
// $id_classe_valeurs : classe dont les valeurs `discipline` sont lues (par
// défaut la plus ancienne classe du niveau) — permet à pages/competences/
// liste.php de prévisualiser "copier le barème d'un autre niveau" (les
// groupes restent ceux du niveau CIBLE, seules les valeurs viennent d'une
// autre classe) sans dupliquer cette fonction pour ce seul besoin.
function bareme_par_niveau(string $code_niveau, string $val_annee, ?int $id_classe_valeurs = null): array {
    $resultat = ['classes' => [], 'groupes' => [], 'divergent' => false, 'assigne' => false];

    // « Groupes par niveau » (pages/competences/liste.php) filtre les groupes
    // proposés par SECTION du niveau : un niveau anglophone n'y assigne QUE
    // des groupes langue='An' (jamais les 'Fr'). Mais seul le côté langue=
    // 'Fr' porte réellement la notation (discipline/composer_sequence ne
    // référencent jamais les id_comp du côté 'An' — commentaire plus haut).
    // Pour un niveau anglophone, on résout donc les groupes assignés vers
    // leur équivalent 'Fr' via ordre_affichage (convention explicite du
    // formulaire de l'onglet Groupes : un groupe Fr et son homologue An
    // partagent le même ordre) — sinon les groupes assignés ('An') ne
    // matchaient jamais rien ici et le barème restait vide malgré
    // l'assignation (bug signalé le 26/08/2026).
    $ordres_assignes = array_map('intval', array_column(
        array_filter(
            db_all(
                "SELECT gcn.id_groupe_comp, gcn.actif, g.ordre_affichage
                 FROM groupe_competence_niveau gcn
                 JOIN groupe_competence g ON g.id_groupe_comp = gcn.id_groupe_comp
                 WHERE gcn.code_niveau=?",
                [$code_niveau]
            ),
            fn($a) => (int) $a['actif'] === 1
        ),
        'ordre_affichage'
    ));
    $resultat['assigne'] = !empty($ordres_assignes);
    if (!$ordres_assignes) return $resultat;

    $classes_du_niveau = db_all("SELECT IDClasses, DesignationClasses FROM classe WHERE Niveau=? ORDER BY IDClasses", [$code_niveau]);
    $resultat['classes'] = $classes_du_niveau;
    if (!$classes_du_niveau) return $resultat;

    $ids_classes = array_column($classes_du_niveau, 'IDClasses');
    $id_classe_valeurs ??= (int) $ids_classes[0]; // classe la plus ancienne du niveau = valeurs affichées par défaut

    // Libellés à AFFICHER : le côté langue='Fr' reste la source de vérité
    // pour id_comp/code_comp/discipline (seul côté noté, voir plus haut) —
    // mais un niveau anglophone doit voir ses libellés en anglais, pas en
    // français (bug signalé le 26/08/2026 : « Barème par niveau » montrait
    // toujours le français pour un niveau An, même si « Groupes par niveau »
    // avait bien les bons groupes 'An' assignés). Jumeau EN résolu par
    // ordre_affichage (groupe) puis code_comp (compétence dans ce groupe EN
    // précis — pas un simple `code_comp=code_comp` global, qui matcherait
    // aussi le côté Fr lui-même si les codes se recoupent entre groupes).
    $section_niveau = (string) (db_val("SELECT Section FROM niveau WHERE LibelleNiveau=?", [$code_niveau]) ?: 'Fr');
    $section_en     = $section_niveau === 'An';

    $in_ord = implode(',', array_fill(0, count($ordres_assignes), '?'));
    $bareme = db_all(
        "SELECT c.id_comp, c.code_comp, c.nom_comp,
                g.id_groupe_comp, g.libelle_groupe_comp, g.ordre_affichage,
                g_en.libelle_groupe_comp AS libelle_groupe_comp_en, c_en.nom_comp AS nom_comp_en,
                d.orale, d.ecrite, d.pratique, d.savoir_etre, d.total_points, d.actif
         FROM competence c
         JOIN groupe_competence g ON g.id_groupe_comp = c.id_groupe_comp
         LEFT JOIN groupe_competence g_en ON g_en.ordre_affichage = g.ordre_affichage AND g_en.langue = 'An'
         LEFT JOIN competence c_en ON c_en.id_groupe_comp = g_en.id_groupe_comp AND c_en.code_comp = c.code_comp
         LEFT JOIN discipline d ON d.id_comp = c.id_comp AND d.IDClasses = ? AND d.annee_scol = ?
         WHERE g.langue = 'Fr' AND g.ordre_affichage IN ($in_ord)
         ORDER BY g.ordre_affichage, c.code_comp",
        array_merge([$id_classe_valeurs, $val_annee], $ordres_assignes)
    );
    foreach ($bareme as &$b) {
        $b['nom_comp_affiche'] = ($section_en && !empty($b['nom_comp_en'])) ? $b['nom_comp_en'] : $b['nom_comp'];
    }
    unset($b);

    if (count($ids_classes) > 1) {
        $in    = implode(',', array_fill(0, count($ids_classes), '?'));
        $check = db_all(
            "SELECT id_comp, COUNT(DISTINCT CONCAT(orale,'/',ecrite,'/',pratique,'/',savoir_etre,'/',actif)) AS nb_variantes
             FROM discipline WHERE IDClasses IN ($in) AND annee_scol=?
             GROUP BY id_comp HAVING nb_variantes > 1",
            array_merge($ids_classes, [$val_annee])
        );
        $resultat['divergent'] = !empty($check);
    }

    $groupes = [];
    foreach ($bareme as $b) {
        $idg = (int) $b['id_groupe_comp'];
        if (!isset($groupes[$idg])) {
            $libelle_grp = ($section_en && !empty($b['libelle_groupe_comp_en'])) ? $b['libelle_groupe_comp_en'] : $b['libelle_groupe_comp'];
            $groupes[$idg] = ['libelle' => $libelle_grp, 'ordre' => (int) $b['ordre_affichage'], 'lignes' => []];
        }
        $groupes[$idg]['lignes'][] = $b;
    }
    foreach ($groupes as &$grp) {
        // Un groupe se masque tout seul si TOUTES ses compétences sont
        // désactivées pour ce niveau (case "Active" décochée) — pas encore
        // de ligne discipline pour une compétence = active par défaut
        // (colonne DEFAULT 1, valeur créée au premier enregistrement).
        $a_une_active = false;
        foreach ($grp['lignes'] as $ligne) {
            if ($ligne['actif'] === null || (int) $ligne['actif'] === 1) { $a_une_active = true; break; }
        }
        $grp['a_une_active'] = $a_une_active;
        $grp['visible']      = $a_une_active;
    }
    unset($grp);
    $resultat['groupes'] = $groupes;
    return $resultat;
}

// ── Piste arabe — barème par niveau (Oral/Écrit/Pratique). Groupé par
// matiere_arabe.id_groupe. Bucket clé 0 = matière sans groupe.
function bareme_matiere_par_niveau(string $code_niveau, string $val_annee, ?int $id_classe_valeurs = null): array {
    $resultat = ['classes' => [], 'groupes' => [], 'divergent' => false, 'assigne' => false];

    $matieres_assignees_ids = array_map('intval', array_column(
        array_filter(
            db_all("SELECT id_mat, actif FROM matiere_niveau_arabe WHERE code_niveau=?", [$code_niveau]),
            fn($a) => (int) $a['actif'] === 1
        ),
        'id_mat'
    ));
    $resultat['assigne'] = !empty($matieres_assignees_ids);
    if (!$matieres_assignees_ids) return $resultat;

    $classes_du_niveau = db_all("SELECT IDClasses, DesignationClasses FROM classe WHERE Niveau=? ORDER BY IDClasses", [$code_niveau]);
    $resultat['classes'] = $classes_du_niveau;
    if (!$classes_du_niveau) return $resultat;

    $ids_classes = array_column($classes_du_niveau, 'IDClasses');
    $id_classe_valeurs ??= (int) $ids_classes[0]; // classe la plus ancienne du niveau = valeurs affichées par défaut

    $in_mat = implode(',', array_fill(0, count($matieres_assignees_ids), '?'));
    $bareme = db_all(
        "SELECT m.id_mat, m.matiere_fr, m.matiere_ar, m.id_groupe, mn.ordre,
                d.orale, d.ecrite, d.pratique, d.total_points, d.actif
         FROM matiere_arabe m
         JOIN matiere_niveau_arabe mn ON mn.id_mat = m.id_mat AND mn.code_niveau = ?
         LEFT JOIN discipline_arabe d ON d.id_mat = m.id_mat AND d.IDClasses = ? AND d.annee_scol = ?
         WHERE m.id_mat IN ($in_mat)
         ORDER BY mn.ordre, m.matiere_fr",
        array_merge([$code_niveau, $id_classe_valeurs, $val_annee], $matieres_assignees_ids)
    );

    if (count($ids_classes) > 1) {
        $in    = implode(',', array_fill(0, count($ids_classes), '?'));
        $check = db_all(
            "SELECT id_mat, COUNT(DISTINCT CONCAT(orale,'/',ecrite,'/',pratique,'/',actif)) AS nb_variantes
             FROM discipline_arabe WHERE IDClasses IN ($in) AND annee_scol=?
             GROUP BY id_mat HAVING nb_variantes > 1",
            array_merge($ids_classes, [$val_annee])
        );
        $resultat['divergent'] = !empty($check);
    }

    $groupes = [];
    foreach ($bareme as $b) {
        $idg = $b['id_groupe'] !== null ? (int) $b['id_groupe'] : 0; // 0 = bucket "غير مصنفة" (non classée)
        if (!isset($groupes[$idg])) {
            $groupes[$idg] = ['libelle' => $idg === 0 ? 'غير مصنفة' : '', 'lignes' => []];
        }
        $groupes[$idg]['lignes'][] = $b;
    }
    // Libellé arabe en priorité, repli sur le français si vide.
    $ids_grp_reels = array_filter(array_keys($groupes), fn($k) => $k !== 0);
    if ($ids_grp_reels) {
        $in_g = implode(',', array_fill(0, count($ids_grp_reels), '?'));
        foreach (db_all("SELECT id_groupe, nom_groupe_fr, nom_groupe_ar FROM groupe_matiere_arabe WHERE id_groupe IN ($in_g)", array_values($ids_grp_reels)) as $g) {
            $groupes[(int) $g['id_groupe']]['libelle'] = $g['nom_groupe_ar'] ?: $g['nom_groupe_fr'];
        }
    }
    foreach ($groupes as &$grp) {
        // Pas encore de ligne discipline_arabe pour une matière = active par
        // défaut (comme discipline côté français, colonne DEFAULT 1).
        $a_une_active = false;
        foreach ($grp['lignes'] as $ligne) {
            if ($ligne['actif'] === null || (int) $ligne['actif'] === 1) { $a_une_active = true; break; }
        }
        $grp['a_une_active'] = $a_une_active;
        $grp['visible']      = $a_une_active;
    }
    unset($grp);
    $resultat['groupes'] = $groupes;
    return $resultat;
}

// ── Piste arabe — copie le barème (discipline_arabe) d'un niveau vers des
// classes ciblées (défaut : toutes celles du niveau). Classe de référence =
// la plus ancienne du niveau ; no-op si elle n'a pas encore de barème.
function synchroniser_bareme_niveau_arabe(string $code_niveau, ?array $ids_classes = null): void {
    $classes_du_niveau = array_column(db_all("SELECT IDClasses FROM classe WHERE Niveau=? ORDER BY IDClasses", [$code_niveau]), 'IDClasses');
    if (!$classes_du_niveau) return;
    $id_classe_ref = (int) $classes_du_niveau[0];
    $ids_classes ??= $classes_du_niveau;

    $annee     = get_annee_active();
    $val_annee = $annee['val_annee'] ?? '';
    if ($val_annee === '') return;

    $bareme_ref = db_all(
        "SELECT id_mat, orale, ecrite, pratique, total_points, actif FROM discipline_arabe WHERE IDClasses=? AND annee_scol=?",
        [$id_classe_ref, $val_annee]
    );
    if (!$bareme_ref) return;

    foreach ($ids_classes as $id_classe) {
        if ((int) $id_classe === $id_classe_ref) continue;
        foreach ($bareme_ref as $b) {
            db_exec(
                "INSERT INTO discipline_arabe (IDClasses, id_mat, annee_scol, orale, ecrite, pratique, total_points, actif)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE orale=VALUES(orale), ecrite=VALUES(ecrite), pratique=VALUES(pratique),
                                         total_points=VALUES(total_points), actif=VALUES(actif)",
                [(int) $id_classe, (int) $b['id_mat'], $val_annee, (float) $b['orale'], (float) $b['ecrite'], (float) $b['pratique'], (float) $b['total_points'], (int) $b['actif']]
            );
        }
    }
}

// Identifiant d'affichage d'un élève (matricule — clé métier de `eleve`)
function id_affichage_eleve(array $eleve): string {
    return (string)($eleve['Mat_elv'] ?? '');
}

// ── Statut d'inscription (Redoublant ?) ──────────────────────────
// `inscrire.Statut_elv` est un champ historique Oui/Non (« Redoublant * »
// dans le formulaire legacy, voir jaynitaare/php/form_enreg_eleve.php) — pas
// un statut à 4 valeurs (Nouveau/Ancien/Redoublant/Transféré). Seules 2
// valeurs canoniques sont désormais acceptées en écriture : 'Oui' (redoublant)
// et 'Non' (nouveau/non-redoublant, valeur par défaut).
function normaliser_statut_insc(?string $s): string {
    $s = mb_strtoupper(trim((string)$s));
    return in_array($s, ['OUI', 'RED', 'REDOUBLANT'], true) ? 'Oui' : 'Non';
}

function libelle_statut_insc(?string $s): string {
    return $s === 'Oui' ? 'Redoublant' : 'Nouveau';
}

// ── Pagination ────────────────────────────────────────────────

function pagination_html(int $page, int $total_pages, string $url_base): string {
    if ($total_pages <= 1) return '';
    $html = '<nav><ul class="pagination pagination-sm justify-content-center mb-0">';
    $prev = max(1, $page - 1);
    $next = min($total_pages, $page + 1);
    $sep  = strpos($url_base, '?') !== false ? '&' : '?';

    $html .= '<li class="page-item ' . ($page === 1 ? 'disabled' : '') . '">';
    $html .= '<a class="page-link" href="' . $url_base . $sep . 'page=' . $prev . '">‹</a></li>';

    $debut = max(1, $page - 2);
    $fin   = min($total_pages, $page + 2);
    for ($i = $debut; $i <= $fin; $i++) {
        $html .= '<li class="page-item ' . ($i === $page ? 'active' : '') . '">';
        $html .= '<a class="page-link" href="' . $url_base . $sep . 'page=' . $i . '">' . $i . '</a></li>';
    }

    $html .= '<li class="page-item ' . ($page === $total_pages ? 'disabled' : '') . '">';
    $html .= '<a class="page-link" href="' . $url_base . $sep . 'page=' . $next . '">›</a></li>';
    $html .= '</ul></nav>';
    return $html;
}

// ── Libellés de rôle (affichage) ───────────────────────────────
// jaynitaare n'a que 3 fonctions de personnel (table `fonction`), pas les
// 7 rôles d'ABZ_MBE (pas de Proviseur/Censeur/SG/Intendant — c'est une école
// maternelle/primaire dirigée par un Directeur).
function libelle_role(string $role): string {
    return match ($role) {
        'DIRECTEUR'  => 'Directeur/Directrice',
        'FONDATEUR'  => 'Fondateur/Fondatrice',
        'ENSEIGNANT' => 'Enseignant(e)',
        'SECRETAIRE' => 'Secrétaire',
        'COMPTABLE'  => 'Agent financier / Comptable',
        'MEMBRE_ASSOCIATION' => 'Membre de l\'association',
        default      => $role,
    };
}

// ── Finances : numéro de reçu ───────────────────────────────
// Dérivé de id_pay (paiement_frais) — ou de id_versement quand plusieurs
// lignes appartiennent au même versement (cotisation répartie sur
// plusieurs frais) — plutôt que stocké dans une colonne séparée : pas de
// séquence à maintenir, jamais de collision, cohérent même après
// suppression d'un versement.
function finances_numero_recu(int $id_pay): string {
    return 'REC-' . str_pad((string) $id_pay, 6, '0', STR_PAD_LEFT);
}

// Identifiant de groupe d'une ligne paiement_frais : id_versement quand la
// ligne fait partie d'un versement réparti sur plusieurs frais, sinon son
// propre id_pay (ligne historique ou versement non réparti). Un même
// versement — même réparti — n'a ainsi qu'UN SEUL numéro de reçu (demande
// explicite du 12/09/2026).
function finances_id_versement(array $p): int {
    return (int) ($p['id_versement'] ?: $p['id_pay']);
}

// Reçu de paiement PAR ÉLÈVE (pages/finances/recu.php, demande explicite du
// 15/08/2026 : « le reçu doit être par élève avec un seul numéro par élève »)
// — remplace le modèle précédent où chaque versement (id_pay) avait son
// propre numéro. Dérivé de id_eleve (stable, jamais réattribué) + les 2
// derniers chiffres du 1er millésime de l'année scolaire — même format que
// le modèle de référence fourni (ex. "0207/25") : NNNN/YY. Un même élève
// garde donc le même numéro sur tous ses reçus tant que l'année scolaire ne
// change pas (repris tel quel, aucune séquence séparée à maintenir).
function finances_numero_recu_eleve(int $id_eleve, string $val_annee): string {
    $annee_courte = substr($val_annee, 2, 2) ?: substr($val_annee, 0, 2);
    return str_pad((string) $id_eleve, 4, '0', STR_PAD_LEFT) . '/' . $annee_courte;
}

// Élèves ayant effectué au moins un versement dans [$debut,$fin] (bornes
// incluses, dates 'Y-m-d') — pages/finances/versement.php (onglet
// « Imprimer les reçus ») et pages/finances/recus_lot.php (impression
// groupée), demande explicite du 13/09/2026 : reçus imprimables en lot
// pour une date/période donnée, par classe ou par élève, ou pour toutes
// les classes. $id_classe/$id_eleve = 0 pour « toutes les classes »/« tous
// les élèves ». Classe renvoyée = classe COURANTE de l'élève (via
// `inscrire`), pas celle enregistrée sur le versement au moment du
// paiement (un élève transféré en cours d'année doit apparaître dans sa
// classe actuelle, cohérent avec le reste de la page) — pas de filtre sur
// le statut de l'élève : un versement réel garde son droit à un reçu même
// si l'élève est devenu inactif depuis.
function finances_eleves_payes_periode(string $val_annee, string $debut, string $fin, int $id_classe = 0, int $id_eleve = 0): array {
    $where  = ['i.val_annee=?', 'p.date_paiement BETWEEN ? AND ?'];
    $params = [$val_annee, $debut, $fin];
    if ($id_classe) { $where[] = 'i.IDClasses=?'; $params[] = $id_classe; }
    if ($id_eleve)  { $where[] = 'i.id_eleve=?';  $params[] = $id_eleve; }

    return db_all(
        "SELECT i.id_eleve, i.IDClasses, e.Nom_elv, e.Prenom_elv, e.Mat_elv, c.DesignationClasses,
                SUM(p.montant_paiement) AS montant_periode,
                COUNT(DISTINCT COALESCE(p.id_versement, p.id_pay)) AS nb_versements
         FROM inscrire i
         JOIN eleve e ON e.id_eleve = i.id_eleve
         JOIN classe c ON c.IDClasses = i.IDClasses
         JOIN paiement_frais p ON p.id_eleve = i.id_eleve AND p.val_annee = i.val_annee
         WHERE " . implode(' AND ', $where) . "
         GROUP BY i.id_eleve, i.IDClasses, e.Nom_elv, e.Prenom_elv, e.Mat_elv, c.DesignationClasses
         ORDER BY c.DesignationClasses, e.Nom_elv, e.Prenom_elv",
        $params
    );
}

// Même principe que finances_numero_recu() mais pour les dépenses (module
// Gestion des dépenses, migration v33) — dérivé de id_depense, pas de
// séquence séparée à maintenir.
function finances_numero_bon(int $id_depense): string {
    return 'BON-' . str_pad((string) $id_depense, 6, '0', STR_PAD_LEFT);
}

// ── Finances : mode de paiement (migration_v43) ─────────────────
// Chaque versement (paiement_frais.mode_paiement) précise comment l'argent a
// été reçu — Espèces par défaut (les versements historiques, antérieurs à
// cette colonne, sont tous en espèces), ou un opérateur mobile/bancaire.
// Liste centralisée ici (libellé + icône Bootstrap Icons + couleur) pour que
// TOUTE l'application (formulaire de saisie, historique, journal de caisse,
// statistiques, exports PDF/Excel) affiche exactement les mêmes
// libellés/icônes, sans dupliquer cette liste page par page.
function finances_modes_paiement(): array {
    return [
        'ESPECES'      => ['libelle' => 'Espèces',          'icone' => 'cash-coin',  'couleur' => '#198754', 'texte' => '#ffffff'],
        'ORANGE_MONEY' => ['libelle' => 'Orange Money',     'icone' => 'phone-fill', 'couleur' => '#FF6600', 'texte' => '#ffffff'],
        'MOMO'         => ['libelle' => 'MTN Mobile Money', 'icone' => 'phone-fill', 'couleur' => '#FFCB05', 'texte' => '#212529'],
        'BANQUE'       => ['libelle' => 'Virement bancaire','icone' => 'bank',       'couleur' => '#0d6efd', 'texte' => '#ffffff'],
        'AUTRE'        => ['libelle' => 'Autre',            'icone' => 'three-dots', 'couleur' => '#6c757d', 'texte' => '#ffffff'],
    ];
}

// Ramène un code de mode de paiement quelconque (potentiellement NULL/vide/
// inconnu) à une clé valide de finances_modes_paiement() — ESPECES par
// défaut, cohérent avec le DEFAULT SQL de la colonne.
function finances_mode_paiement_normalise(?string $code): string {
    $modes = finances_modes_paiement();
    return ($code && isset($modes[$code])) ? $code : 'ESPECES';
}

// Badge HTML (icône + libellé, couleur dédiée à chaque opérateur) pour un
// mode de paiement — réutilisé dans l'historique des versements, le journal
// de caisse, etc.
function finances_mode_paiement_badge(?string $code): string {
    $m = finances_modes_paiement()[finances_mode_paiement_normalise($code)];
    return '<span class="badge" style="background:' . $m['couleur'] . ';color:' . $m['texte'] . '">'
        . '<i class="bi bi-' . $m['icone'] . ' me-1"></i>' . h($m['libelle']) . '</span>';
}

// Libellé simple (sans HTML) — exports Excel/PDF où seul le texte compte.
function finances_mode_paiement_libelle(?string $code): string {
    return finances_modes_paiement()[finances_mode_paiement_normalise($code)]['libelle'];
}

// Solde de caisse disponible pour l'année scolaire donnée : total encaissé
// (versements élèves) - total dépensé (module Dépenses). Calculé à la volée
// (pas de colonne stockée qui pourrait diverger) — utilisé par la page de
// saisie d'une dépense pour avertir si le montant saisi dépasserait ce qui a
// réellement été encaissé.
function solde_caisse(string $val_annee): float {
    $encaisse = (float) (db_val("SELECT COALESCE(SUM(montant_paiement),0) FROM paiement_frais WHERE val_annee=?", [$val_annee]) ?? 0);
    $depense  = (float) (db_val("SELECT COALESCE(SUM(montant),0) FROM depense WHERE val_annee=?", [$val_annee]) ?? 0);
    return $encaisse - $depense;
}

// ── Cas social (migration_v39) ──────────────────────────────
// Remplace l'ancien select "Indigent ou né de parents indigent" (colonne
// info_supplementaires.indigent, conservée en base mais retirée de l'écran)
// par une case à cocher "Cas social" + un pourcentage de réduction appliqué
// au montant dû sur les paiements de frais (pages/finances/*).

// Pourcentage de réduction "Cas social" d'UN élève (0 si non concerné) —
// pages à un seul élève (versement.php, recu.php) où une requête groupée
// serait disproportionnée.
function eleve_pourcentage_reduction(int $id_eleve): float {
    $info = db_one("SELECT cas_social, pourcentage_reduction FROM info_supplementaires WHERE id_eleve=?", [$id_eleve]);
    if (!$info || !$info['cas_social']) return 0.0;
    return (float) $info['pourcentage_reduction'];
}

// Pourcentages de réduction "Cas social" de PLUSIEURS élèves en une seule
// requête (id_eleve => pourcentage), pour les états agrégés (par classe,
// niveau, établissement) — évite le N+1. $ids_eleve vide = tous les cas
// sociaux actifs, tous élèves confondus (utilisé par le rapport dédié).
function cas_sociaux_reductions(array $ids_eleve = []): array {
    $where  = 'cas_social = 1';
    $params = [];
    if ($ids_eleve) {
        $in = implode(',', array_fill(0, count($ids_eleve), '?'));
        $where .= " AND id_eleve IN ($in)";
        $params = $ids_eleve;
    }
    $out = [];
    foreach (db_all("SELECT id_eleve, pourcentage_reduction FROM info_supplementaires WHERE $where", $params) as $r) {
        $out[(int) $r['id_eleve']] = (float) $r['pourcentage_reduction'];
    }
    return $out;
}

// Liste des élèves actifs inscrits (année/classe/niveau donnés — l'un ou
// l'autre des filtres, ou aucun pour tout l'établissement) avec, pour
// chacun, le montant dû NORMAL (somme des obligations de son niveau) et le
// montant dû APRÈS réduction "Cas social" — source de vérité unique pour
// tous les états financiers (état par classe, impayés, statistiques,
// répartition par classe, cas sociaux) : évite que chaque page réapplique
// à sa façon le pourcentage de réduction.
function finances_du_par_eleve(string $val_annee, ?int $id_classe = null, ?string $niveau = null): array {
    $where  = ["e.statut='actif'", 'i.val_annee=?'];
    $params = [$val_annee];
    if ($id_classe) { $where[] = 'c.IDClasses=?'; $params[] = $id_classe; }
    if ($niveau)    { $where[] = 'c.Niveau=?';    $params[] = $niveau; }
    $eleves = db_all(
        "SELECT e.id_eleve, c.IDClasses, c.Niveau, c.DesignationClasses, e.Mat_elv, e.Nom_elv, e.Prenom_elv
         FROM eleve e
         JOIN inscrire i ON i.id_eleve=e.id_eleve
         JOIN classe c ON c.IDClasses=i.IDClasses
         WHERE " . implode(' AND ', $where) . "
         ORDER BY e.Nom_elv, e.Prenom_elv",
        $params
    );

    $du_par_niveau = [];
    foreach (db_all("SELECT niveau_obligation, SUM(montant_obligation) AS total FROM obligation GROUP BY niveau_obligation") as $r) {
        $du_par_niveau[$r['niveau_obligation']] = (float) $r['total'];
    }
    $reductions = cas_sociaux_reductions(array_column($eleves, 'id_eleve'));

    foreach ($eleves as &$e) {
        $id     = (int) $e['id_eleve'];
        $normal = $du_par_niveau[$e['Niveau']] ?? 0.0;
        $pct    = $reductions[$id] ?? 0.0;
        $e['montant_normal'] = $normal;
        $e['cas_social']     = $pct > 0;
        $e['pourcentage']    = $pct;
        $e['du']             = $pct > 0 ? round($normal * (1 - $pct / 100), 2) : $normal;
    }
    unset($e);
    return $eleves;
}

// ── Génération de matricule ─────────────────────────────────
// Port fidèle de generer_matricule() (jaynitaare/php/mes_fonctions.php:268) :
// AA + [M|P] + NNN, où AA = 2 derniers chiffres du début de l'année scolaire
// active, M/P selon que le niveau choisi est "M" (Maternelle) ou non (tout
// le primaire — SIL/I/CP/II/CE1/CE2/III/CM1/CM2 — utilisait "P" dans
// l'original), NNN sur 3 chiffres.
//
// NNN = MAX() du numéro déjà utilisé sur ce même préfixe AA+[M|P] +1 — PAS
// un COUNT() (même correctif que gen_niu() ci-dessous, même bug identifié
// par l'utilisateur) : un COUNT() se décale dès qu'un élève est supprimé
// (le compte diminue) et peut alors régénérer un matricule déjà attribué à
// un élève encore présent en base (collision). MAX() ne regarde que les
// matricules réellement en base : la suite reprend toujours après le plus
// grand numéro encore utilisé, jamais en dessous, donc jamais de doublon
// même après une ou plusieurs suppressions. Repli sur un nombre aléatoire à
// 3 chiffres si malgré tout ce matricule existe déjà (ex. deux
// enregistrements simultanés), même filet de sécurité qu'avant.
// ── Configuration du matricule (par école, migration v52) ────────────
//  Table `matricule_config` à ligne unique (id=1). Renvoie des valeurs par
//  défaut si la table/la ligne est absente (base pas encore migrée) : le
//  défaut '{AA}{NIV}{SEQ}' / longueur_seq=3 reproduit EXACTEMENT l'ancien
//  gen_matricule() (« 25P001 »).
function matricule_config(): array {
    static $c = null;
    if ($c === null) {
        $def = ['mode' => 'auto', 'format' => '{AA}{NIV}{SEQ}', 'longueur_seq' => 3, 'sequence_par' => 'annee_niveau'];
        try {
            $row = db_one("SELECT mode, format, longueur_seq, sequence_par FROM matricule_config WHERE id=1");
        } catch (\Throwable $e) { $row = null; }
        $c = $row ? array_merge($def, array_filter($row, fn($v) => $v !== null && $v !== '')) : $def;
        $c['mode']         = $c['mode'] === 'manuel' ? 'manuel' : 'auto';
        $c['longueur_seq'] = max(1, min(8, (int) $c['longueur_seq']));
        // {SEQ} obligatoire en mode auto (sinon numéro impossible à placer).
        if (strpos($c['format'], '{SEQ}') === false) $c['format'] = '{AA}{NIV}{SEQ}';
    }
    return $c;
}

/** Le matricule est-il saisi à la main (et éventuellement laissé vide) ? */
function matricule_manuel(): bool {
    return matricule_config()['mode'] === 'manuel';
}

/** Aperçu lisible d'un format de matricule (écran de configuration). */
function matricule_exemple(string $format, int $lseq, string $val_annee = '2025/2026'): string {
    $an = explode('/', $val_annee)[0] ?: $val_annee;
    return strtr($format, [
        '{AAAA}' => $an, '{AA}' => substr($an, -2), '{NIV}' => 'P',
        '{SEQ}'  => str_pad('1', max(1, $lseq), '0', STR_PAD_LEFT),
    ]);
}

// ── Génération de matricule ─────────────────────────────────
//  Piloté par matricule_config() : le format est une suite de littéraux et
//  de jetons {AA} {AAAA} {NIV} {SEQ}. Le préfixe (tout avant {SEQ}, jetons
//  résolus) a une longueur fixe -> {SEQ} est extrait par SUBSTRING pour
//  calculer le MAX (jamais un COUNT : se décalerait à chaque suppression et
//  pourrait recréer un matricule déjà attribué). `sequence_par` élargit le
//  périmètre du compteur : par (année, niveau) [défaut, historique], par
//  année seule, ou global. Repli aléatoire en cas de collision (2
//  enregistrements simultanés). Mode 'manuel' -> chaîne vide (le matricule
//  est alors saisi, ou laissé vide, dans le formulaire).
function gen_matricule(string $val_annee, string $niveau): string {
    $cfg = matricule_config();
    if ($cfg['mode'] === 'manuel') return '';

    $an   = explode('/', $val_annee)[0] ?: $val_annee;
    $aa   = substr($an, -2);
    $niv  = (trim($niveau) === 'M') ? 'M' : 'P';
    $lseq = (int) $cfg['longueur_seq'];

    [$avant, $apres] = array_pad(explode('{SEQ}', $cfg['format'], 2), 2, '');
    $resoudre = fn(string $s) => strtr($s, ['{AAAA}' => $an, '{AA}' => $aa, '{NIV}' => $niv]);
    $prefixe = $resoudre($avant);
    $suffixe = $resoudre($apres);

    // Regex REGEXP : jetons -> classes selon le périmètre de séquence.
    $an_wild  = $cfg['sequence_par'] === 'globale';
    $niv_wild = in_array($cfg['sequence_par'], ['annee', 'globale'], true);
    $regex = '^';
    foreach (preg_split('/(\{AAAA\}|\{AA\}|\{NIV\}|\{SEQ\})/', $cfg['format'], -1, PREG_SPLIT_DELIM_CAPTURE) as $p) {
        $regex .= match ($p) {
            '{AAAA}' => $an_wild ? '[0-9]{4}' : preg_quote($an),
            '{AA}'   => $an_wild ? '[0-9]{2}' : preg_quote($aa),
            '{NIV}'  => $niv_wild ? '[MP]' : $niv,
            '{SEQ}'  => '[0-9]{' . $lseq . '}',
            default  => preg_quote($p),
        };
    }
    $regex .= '$';

    $max = (int) db_val(
        "SELECT MAX(CAST(SUBSTRING(Mat_elv, ?, ?) AS UNSIGNED)) FROM eleve WHERE Mat_elv REGEXP ?",
        [strlen($prefixe) + 1, $lseq, $regex]
    );
    $matricule = $prefixe . str_pad((string) ($max + 1), $lseq, '0', STR_PAD_LEFT) . $suffixe;

    if (db_val("SELECT COUNT(*) FROM eleve WHERE Mat_elv=?", [$matricule])) {
        $alea = random_int(1, (10 ** $lseq) - 1);
        $matricule = $prefixe . str_pad((string) $alea, $lseq, '0', STR_PAD_LEFT) . $suffixe;
    }
    return $matricule;
}

// ── Génération de NIU ────────────────────────────────────────
// Format demandé : $prefixe (par défaut "PMC") + Initial_Etab
// (etablissement.Initial_Etab) + 2 derniers chiffres du DÉBUT de l'année
// scolaire ACTIVE (annee_scolaire.val_annee, même convention que
// gen_matricule() ci-dessus — PAS la date système : une machine à l'heure/
// date fausse ne doit jamais pouvoir décaler le NIU) + numéro d'ordre sur
// 4 chiffres. Exemple (Initial_Etab="JY1", val_annee="2025/2026") :
// PMCJY1250001.
//
// Numéro d'ordre = nombre d'élèves déjà enregistrés cette année scolaire
// (1er élève -> 0001, 59e -> 0059, etc.), calculé par MAX() du numéro déjà
// utilisé cette année +1 — JAMAIS par COUNT(niu) : un COUNT() se décale dès
// qu'un élève est supprimé (le compte diminue) et peut alors régénérer un
// NIU déjà attribué à un élève encore présent en base (collision). MAX() ne
// regarde que les NIU réellement en base : la suite reprend toujours après
// le plus grand numéro encore utilisé, jamais en dessous, donc jamais de
// doublon même après une ou plusieurs suppressions. Repli sur un numéro
// aléatoire à 4 chiffres si malgré tout ce NIU existe déjà (ex. deux
// enregistrements simultanés), même filet de sécurité que gen_matricule().
// Multi-établissement : quand l'annuaire association est présent, le NIU est
// FRAPPÉ AU CENTRAL (séquence globale par préfixe sur jaynitaare_assoc.
// eleve_niu, et non plus la seule table `eleve` locale) — un élève ne peut
// alors avoir qu'un seul NIU quelle que soit l'école. $reserver=true insère
// aussitôt une ligne `eleve_niu` (statut « reserve ») pour verrouiller le
// numéro ; le pré-remplissage du formulaire (form.php) appelle avec
// $reserver=false pour ne PAS créer de réservation orpheline.
// Sans annuaire : comportement mono-école historique (séquence locale).
function gen_niu(string $initial_etab, string $prefixe = 'PMC', bool $reserver = true): string {
    $val_annee = get_annee_active()['val_annee'] ?? '';
    $code_an   = substr(explode('/', $val_annee)[0] ?: $val_annee, 2, 2);
    $base      = $prefixe . strtoupper(trim($initial_etab)) . $code_an;
    $regex     = '^' . preg_quote($base) . '[0-9]{4}$';

    if (function_exists('annuaire_dispo') && annuaire_dispo()) {
        $ecole  = function_exists('ecole_courante') ? ecole_courante() : null;
        $id_e   = $ecole['id'] ?? null;
        $par    = 'ecole:' . ($ecole['code'] ?? '?');
        for ($essai = 0; $essai < 6; $essai++) {
            $max = (int) assoc_val(
                "SELECT MAX(CAST(SUBSTRING(niu, ?) AS UNSIGNED)) FROM eleve_niu WHERE niu REGEXP ?",
                [strlen($base) + 1, $regex]
            );
            $n   = $essai < 3 ? $max + 1 : random_int(1, 9999);
            $niu = $base . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
            if (!$reserver) return $niu;
            try {
                assoc_exec(
                    "INSERT INTO eleve_niu (niu, id_etab_origine, id_etab_courant, statut, cree_par)
                     VALUES (?, ?, ?, 'reserve', ?)",
                    [$niu, $id_e, $id_e, $par]
                );
                assoc_exec(
                    "INSERT INTO eleve_niu_mouvement (niu, id_etab_cible, type, par) VALUES (?, ?, 'creation', ?)",
                    [$niu, $id_e, $par]
                );
                return $niu;
            } catch (\Throwable $e) {
                // collision de clé primaire : le compteur a bougé, on retente
            }
        }
        return $niu; // très improbable : on rend le dernier candidat calculé
    }

    // ── Repli mono-école (annuaire absent) : séquence locale sur `eleve` ──
    $max = (int) db_val(
        "SELECT MAX(CAST(SUBSTRING(niu, ?) AS UNSIGNED)) FROM eleve WHERE niu REGEXP ?",
        [strlen($base) + 1, $regex]
    );
    $niu = $base . str_pad((string)($max + 1), 4, '0', STR_PAD_LEFT);
    if (db_val("SELECT COUNT(*) FROM eleve WHERE niu=?", [$niu])) {
        $niu = $base . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
    }
    return $niu;
}

// ── Adaptateur PDF (pdf/header_pdf.php vient d'ABZ_MBE) ────────
// pdf_entete() attend des clés (nom_fr, region_fr, boite_postale, logo...)
// différentes de celles du schéma jaynitaare (Nom_Etab_Fr, region_etab_fr,
// boite_postal, logo...) — traduit une seule fois ici, réutilisé par tous
// les futurs générateurs PDF plutôt que de dupliquer le mapping partout.
function etab_pour_pdf(array $etab): array {
    return [
        'nom_fr'            => $etab['Nom_Etab_Fr'] ?? '',
        'nom_en'            => $etab['Nom_Etab_An'] ?? '',
        'sigle'             => $etab['Initial_Etab'] ?? '',
        'immatriculation'   => $etab['Immatriculation_Etab'] ?? '',
        'boite_postale'     => $etab['boite_postal'] ?? '',
        'telephone'         => $etab['tel_etab'] ?? '',
        'email'             => $etab['email_etab'] ?? '',
        'ville'             => $etab['ville_etab'] ?? '',
        // Localité de signature des bulletins ("Fait à ..., le ...") —
        // distincte de la ville de l'établissement (etab.lieu_etab dans le
        // schéma jaynitaare, ex. "Bamyanga" ≠ ville_etab "Ngaoundere" sur la
        // vraie fiche établissement) : c'est bien ce champ que BULLETIN_
        // ANNUEL_CLASSE.php (jaynitaare legacy) affiche à cet endroit.
        'lieu'              => $etab['lieu_etab'] ?? '',
        'region_fr'         => $etab['region_etab_fr'] ?? '',
        // `departement_fr`/`arrondissement_fr` : colonnes réelles de
        // `etablissement` — pas `delegation_regional_fr`/
        // `delegation_departemental_fr`, qui n'existent plus.
        'departement_fr'    => $etab['departement_fr'] ?? '',
        'arrondissement_fr' => $etab['arrondissement_fr'] ?? '',
        'region_en'         => $etab['region_etab_en'] ?? '',
        'division_en'       => $etab['departement_en'] ?? '',
        'subdivision_en'    => $etab['arrondissement_en'] ?? '',
        'chef_etablissement'=> $etab['fonction_dirigeant_fr'] ?? '',
        'chef_etablissement_en' => $etab['fonction_dirigeant_en'] ?? '',
        'logo'              => $etab['logo'] ?? '',
    ];
}

// Chemin de la signature numérique de l'établissement (Directeur — un seul
// signataire dans jaynitaare, contrairement au catalogue multi-signataires
// signature_titulaire d'ABZ_MBE) ou null si non configurée.
function signature_etablissement_chemin(): ?string {
    $etab = get_etablissement();
    if (empty($etab['signature'])) return null;
    $chemin = __DIR__ . '/assets/uploads/' . $etab['signature'];
    return is_file($chemin) ? $chemin : null;
}

// Position/taille enregistrée (en % du cadre) pour un type de document donné
// — un seul signataire dans jaynitaare, donc pas de code_signature comme
// dans ABZ_MBE (juste type_document en clé). Retourne $defaut si jamais
// configurée (le document ne casse jamais avant paramétrage).
function signature_position_lookup(string $type_document, array $defaut): array {
    $pos = db_one("SELECT x_pct, y_pct, w_pct, h_pct FROM signature_position WHERE type_document=?", [$type_document]);
    return $pos ?: $defaut;
}

// ── Photo élève (BLOB) ──────────────────────────────────────
// Décode une image envoyée en base64 (upload classique relu en JS, ou
// recadrage Cropper.js) en binaire prêt à écrire dans eleve.Photo_elv.
// Retourne null si absente/invalide/trop grande (2 Mo max, même limite
// qu'ABZ_MBE pour sauver_photo()).
function decoder_photo_b64(?string $photo_b64): ?string {
    if (empty($photo_b64) || !str_starts_with($photo_b64, 'data:image/')) return null;
    if (!preg_match('/data:image\/(\w+);base64,(.+)/s', $photo_b64, $m)) return null;
    $data = base64_decode($m[2]);
    if (!$data || strlen($data) > 2 * 1024 * 1024) return null;
    return $data;
}

// ── Dossier élève (pièces jointes) ──────────────────────────

function libelle_type_dossier(string $type): string {
    return match ($type) {
        'acte_naissance'      => 'Acte de naissance',
        'carnet_vaccination'  => 'Carnet de vaccination',
        'bulletin'            => 'Bulletin',
        'document_transfert'  => 'Document de transfert',
        'photo_4x4'           => 'Photo 4×4',
        default                => 'Autre document',
    };
}

// Sauvegarde un scan (PDF ou image) uploadé dans assets/uploads/dossiers_eleves/,
// avec validation MIME réelle (finfo, pas l'extension déclarée) — mêmes
// contrôles que sauver_photo(), format supplémentaire (PDF), 750 Ko max.
// Retourne le nom de fichier généré, ou null si absent/invalide.
function sauver_document_dossier(string $champ_fichier): ?string {
    if (empty($_FILES[$champ_fichier]['tmp_name']) || $_FILES[$champ_fichier]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $ext_ok = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'];
    $ext    = strtolower(pathinfo($_FILES[$champ_fichier]['name'], PATHINFO_EXTENSION));
    $fi     = finfo_open(FILEINFO_MIME_TYPE);
    $mime   = finfo_file($fi, $_FILES[$champ_fichier]['tmp_name']);
    finfo_close($fi);
    if (!isset($ext_ok[$ext]) || $ext_ok[$ext] !== $mime || $_FILES[$champ_fichier]['size'] > 750 * 1024) {
        return null;
    }
    // Nom aléatoire + sous-dossier par école (multi-établissement) : le
    // chemin relatif « <code>/doc_xxx.ext » est stocké dans dossier_eleve.fichier,
    // les lecteurs (dossier_fichier.php / dossier_supprimer.php) le concatènent
    // tel quel à assets/uploads/dossiers_eleves/.
    $base = __DIR__ . '/assets/uploads/dossiers_eleves';
    $dir  = upload_dir_etab($base);
    $fichier = bin2hex(random_bytes(10)) . '.' . $ext;
    move_uploaded_file($_FILES[$champ_fichier]['tmp_name'], $dir . 'doc_' . $fichier);
    return upload_prefixe_etab() . 'doc_' . $fichier;
}

// Vérifie qu'un BLOB est réellement une image décodable — nécessaire car le
// legacy jaynitaare a un bug connu (form_info_complementaire.php écrivait
// parfois la date de naissance formatée dans Photo_elv au lieu de laisser la
// photo intacte) : certaines lignes "Photo_elv IS NOT NULL" ne contiennent
// en réalité qu'une chaîne de quelques octets ("27/07/2019"), pas une image.
// Sans ce filtre, FPDF::Image() lève une exception fatale à la génération
// d'un PDF pour un tel élève.
function blob_est_image(?string $blob): bool {
    if ($blob === null || $blob === '') return false;
    $fi   = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_buffer($fi, $blob);
    finfo_close($fi);
    return $mime !== false && str_starts_with($mime, 'image/');
}

// Écrit Photo_elv dans un fichier temporaire pour FPDF::Image() (qui a
// besoin d'un chemin de fichier, pas de données binaires en mémoire) —
// retourne null si absente ou invalide (voir blob_est_image()), auquel cas
// l'appelant doit dessiner un cadre vide/placeholder à la place.
function photo_eleve_fichier_temp(?string $blob, int $id_eleve): ?string {
    if (!blob_est_image($blob)) return null;
    $fi  = finfo_open(FILEINFO_MIME_TYPE);
    $ext = match (finfo_buffer($fi, $blob)) {
        'image/png'  => 'png',
        'image/webp' => 'webp',
        default      => 'jpg',
    };
    finfo_close($fi);
    $chemin = sys_get_temp_dir() . '/jn_photo_' . $id_eleve . '.' . $ext;
    file_put_contents($chemin, $blob);
    return $chemin;
}

// Photo d'un élève : Photo_elv est stockée en BLOB dans la base (héritage de
// l'ancien système), pas en fichier — servie via pages/eleves/photo.php.
// Repli sur un avatar générique garçon/fille selon le sexe si absente, même
// logique que ABZ_MBE (assets/img/avatars/garcon.png|fille.png). $id_eleve
// est la vraie clé technique de l'élève (voir migration id_eleve) — jamais
// Mat_elv (matricule, purement un champ métier affiché).
function url_photo_eleve(int $id_eleve, bool $a_photo, string $sexe): string {
    if ($a_photo) return APP_URL . '/pages/eleves/photo.php?id=' . $id_eleve;
    $avatar = (stripos($sexe, 'F') === 0) ? 'fille.png' : 'garcon.png';
    return APP_URL . '/assets/img/avatars/' . $avatar;
}
