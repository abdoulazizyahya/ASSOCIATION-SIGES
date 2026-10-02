<?php
// ── Fonctions utilitaires globales ────────────────────────────
// Adapté du projet ABZ_MBE (mêmes conventions : mysqli préparé via
// connexion.php, CSRF, sessions, flash) mais branché sur le VRAI schéma de
// jaynitaare_v2_bd (colonnes Mat_elv/IDClasses/val_annee...), pas sur celui
// d'ABZ_MBE — ce sont deux systèmes différents (décision explicite, voir
// prompt_continuite_jaynitaare_v2.md).

// ── Champs de formulaire encodés (pare-feu de l'hébergeur) ────────────
// Le pare-feu ModSecurity (OWASP CRS) de l'hébergeur mutualisé (Camoo)
// rejetait en 403 des enregistrements ordinaires (fiche élève…) en prenant
// un texte saisi pour une injection SQL (« SQLI=5 », constaté le
// 02/10/2026). layout/footer.php regroupe donc, juste avant l'envoi, les
// champs texte d'un formulaire POST dans UN champ `_formb64` (base64 d'une
// liste [nom, valeur]) que le pare-feu ne sait pas interpréter ; on le
// redéploie ici dans $_POST, exactement comme PHP l'aurait fait (parse_str
// gère nom[], nom[cle]…). Les pages n'ont rien à changer. Sans JavaScript
// ou pour un formulaire exclu (data-sans-encodage), rien ne change.
if (!empty($_POST['_formb64']) && is_string($_POST['_formb64'])) {
    $paires = json_decode((string) base64_decode($_POST['_formb64'], true), true);
    unset($_POST['_formb64'], $_REQUEST['_formb64']);
    if (is_array($paires)) {
        $morceaux = [];
        foreach ($paires as $p) {
            if (is_array($p) && count($p) === 2 && is_string($p[0]) && $p[0] !== '' && is_scalar($p[1])) {
                $morceaux[] = rawurlencode($p[0]) . '=' . rawurlencode((string) $p[1]);
            }
        }
        parse_str(implode('&', $morceaux), $decode);
        // Ce qui est arrivé EN CLAIR (csrf, bouton cliqué ajouté après coup
        // par soumettreFormulaireAjax…) reste prioritaire.
        $_POST    = array_replace_recursive($decode, $_POST);
        $_REQUEST = array_replace_recursive($decode, $_REQUEST);
    }
    unset($paires, $morceaux, $decode, $p);
}

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
    error_log('[PDF] ' . ($_SERVER['REQUEST_URI'] ?? '') . ' : ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
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
    // Licence (bd/lib/licence.php) : blocage anti-brute-force — coupe une
    // session DÉJÀ OUVERTE immédiatement (pas seulement à la prochaine
    // connexion, voir login.php pour ce cas-là), demande explicite du
    // 13/09/2026. Seule exemption : se déconnecter reste toujours possible.
    if ($script_courant !== 'logout.php' && function_exists('licence_bloque') && licence_bloque()) {
        session_destroy();
        header('Location: ' . APP_URL . '/login.php?bloque=1');
        exit;
    }
    // Questions secrètes (récupération de mot de passe) : DÉSORMAIS
    // facultatives, jamais bloquantes (demande explicite du 24/09/2026 —
    // avant cette date, redirection forcée côté primaire tant que non
    // configurées ; côté secondaire, absentes du tout). Un bandeau
    // dismissible (layout/header.php) invite à les configurer sans empêcher
    // l'accès aux pages — voir configurer_securite.php, utilisateur_a_questions().
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
    if ($m === null) {
        $secondaire = function_exists('type_enseignement_courant') && type_enseignement_courant() === 'secondaire';
        $m = require __DIR__ . ($secondaire ? '/layout/menu_secondaire.php' : '/layout/menu.php');
    }
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
    // Visite association « Administrateur » : accès complet, hors module
    // Privilèges (inchangé). Membre/Superviseur : DÉSORMAIS soumis à ce
    // module comme n'importe quel rôle (Utilisateurs/Paramètres masqués par
    // défaut, voir assoc_seeder_masque_visite()) — demande du 23/09/2026.
    if (function_exists('est_visite_association_administrateur') && est_visite_association_administrateur()) return true;
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

/**
 * Le compte connecté peut-il gérer CE compte (rôle, mot de passe,
 * activation, suppression, privilèges) ? — 01/10/2026 : un DIRECTEUR ne
 * gère que SON personnel (agent financier, secrétaire, enseignant…), jamais
 * un autre Directeur ni le Fondateur ; ces comptes relèvent du fondateur
 * (menu « Directeur ») et de l'association. Son propre compte : mot de passe
 * seulement (rôle / statut / suppression déjà refusés sur soi-même).
 * Fondateur et membre de l'association en visite : inchangés.
 */
function compte_gerable(string $fonction_cible, int $id_user_cible): bool {
    if (function_exists('est_visite_association') && est_visite_association()) return true;
    if (function_exists('est_fondateur') && est_fondateur()) return true;
    if (role_connecte() !== 'DIRECTEUR') return false;
    if ($id_user_cible === (int) ($_SESSION['user_id'] ?? 0)) return true;
    return !in_array($fonction_cible, ['DIRECTEUR', 'FONDATEUR'], true);
}

/** Même règle pour une FICHE du personnel (matricule_ens) : modification, activation. */
function fiche_gerable(int $matricule_ens, ?string $fct = null): bool {
    if (function_exists('est_visite_association') && est_visite_association()) return true;
    if (function_exists('est_fondateur') && est_fondateur()) return true;
    if (role_connecte() !== 'DIRECTEUR') return false;
    if ((string) $matricule_ens === (string) matricule_ens_courant()) return true;
    $fct = $fct ?? (string) db_val("SELECT id_fonction FROM enseignant WHERE matricule_ens=?", [$matricule_ens]);
    return !in_array($fct, ['DIRECTEUR', 'FONDATEUR'], true);
}

/** Message affiché quand compte_gerable() refuse. */
function refus_compte_non_gerable(string $fonction_cible): string {
    return 'Le compte d\'un(e) ' . mb_strtolower(libelle_role($fonction_cible))
         . ' ne peut être modifié que par le fondateur ou l\'association. Vous gérez uniquement votre personnel.';
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

// Alias de compatibilité LAM_ABZ (même nom/signature que fonctions.php côté
// source, ?int) — utilisé par les modules secondaire copiés en masse le
// 16/09/2026 (absences, bulletins, conseil_classe, discipline, statistiques,
// tableau_honneur…). Évite de retoucher 24 fichiers un par un juste pour un
// renommage ; garde le typage int de LAM_ABZ plutôt que le ?string de
// matricule_ens_courant() (primaire) pour ne pas fausser une comparaison
// stricte côté appelant.
function get_matricule_ens_connecte(): ?int {
    $mat = matricule_ens_courant();
    return $mat !== null ? (int) $mat : null;
}

/**
 * À la création d'une classe (secondaire), copie les matières (groupe de
 * compétence, coefficient, ordre) d'une classe SŒUR déjà existante — même
 * niveau, même section (Fr/An) — vers la nouvelle, via la table `discipline`
 * existante (pas de table de programme séparée : les affectations réelles
 * DÉJÀ saisies pour ce niveau, onglet « Affectation par classe », en tiennent
 * lieu). INSERT IGNORE : n'écrase jamais un réglage déjà présent sur la
 * classe cible. Sans effet (retourne 0) si aucune classe de ce niveau/
 * section n'a encore de matière assignée — l'admin affecte alors la
 * première classe du niveau normalement (onglet « Affectation par classe »),
 * les suivantes en hériteront automatiquement. Demande explicite du
 * 25/09/2026 (menu/fonctionnement identiques à LAM_ABZ, sans écran de
 * configuration supplémentaire).
 */
function secondaire_copier_matieres_niveau(int $id_classe, ?string $code_niveau, ?string $libelle_section): int {
    if ($id_classe <= 0 || !$code_niveau) return 0;
    $classe_source = db_val(
        "SELECT c.id FROM classe c
         WHERE c.code_niveau=? AND c.libelle_section<=>? AND c.id<>? AND c.archivee=0
           AND EXISTS (SELECT 1 FROM discipline d WHERE d.IDClasses=c.id)
         ORDER BY c.id LIMIT 1",
        [$code_niveau, $libelle_section, $id_classe]
    );
    if (!$classe_source) return 0;

    $rows = db_all("SELECT id_mat, id_groupe, coef, ordre FROM discipline WHERE IDClasses=?", [(int) $classe_source]);
    foreach ($rows as $r) {
        db_exec(
            "INSERT IGNORE INTO discipline (id_mat, IDClasses, id_groupe, coef, ordre) VALUES (?,?,?,?,?)",
            [$r['id_mat'], $id_classe, $r['id_groupe'], $r['coef'], $r['ordre']]
        );
    }
    return count($rows);
}

/**
 * Coefficient/ordre réels par (niveau, matière), extraits une fois pour
 * toutes de la base de référence LAM_ABZ le 25/09/2026 (bd/secondaire/
 * reference_coefficients.php, 267 couples). Utilisé par
 * secondaire_deriver_matieres_competences() pour ne plus affecter un
 * coefficient par défaut (1) mais la VRAIE valeur — demande explicite du
 * 25/09/2026 (« les coef ne sont pas corrects... il faut copier LAM_ABZ »).
 */
function secondaire_reference_coefficients(): array {
    static $ref = null;
    if ($ref === null) {
        $f = __DIR__ . '/bd/secondaire/reference_coefficients.php';
        $ref = is_file($f) ? (require $f) : [];
    }
    return $ref;
}

/**
 * Dérive les matières d'une classe (secondaire) à partir des COMPÉTENCES
 * déjà configurées pour son niveau (onglet « Compétences par trimestre »,
 * table `competence` : matière + code_niveau + trimestre — indépendante de
 * toute classe, donc disponible AVANT qu'une classe existe). Une matière
 * ayant au moins une compétence pour ce niveau est considérée enseignée à
 * ce niveau. Groupe de compétence = celui de la section de la classe
 * (`groupe.id_section`, un seul groupe par section) ; coefficient/ordre =
 * la vraie valeur si connue (secondaire_reference_coefficients(), extraite
 * de LAM_ABZ), sinon 1 par défaut — ajustable ensuite depuis l'onglet
 * « Affectation par classe » (édition déjà possible par matière). INSERT
 * IGNORE : jamais d'écrasement d'un réglage déjà présent. Demande
 * explicite du 25/09/2026.
 */
function secondaire_deriver_matieres_competences(int $id_classe, ?string $code_niveau, ?string $libelle_section): int {
    if ($id_classe <= 0 || !$code_niveau) return 0;
    $id_groupe = $libelle_section !== null && $libelle_section !== ''
        ? db_val("SELECT id_groupe_comp FROM groupe WHERE id_section=? LIMIT 1", [$libelle_section])
        : db_val("SELECT id_groupe_comp FROM groupe ORDER BY id_groupe_comp LIMIT 1");
    if (!$id_groupe) return 0;

    $sql = "SELECT DISTINCT c.id_matiere, m.libelle
            FROM competence c
            JOIN matiere m ON m.id = c.id_matiere
            WHERE c.code_niveau = ?";
    $params = [$code_niveau];
    if ($libelle_section !== null && $libelle_section !== '') {
        $sql .= " AND m.libelle_section = ?";
        $params[] = $libelle_section;
    }
    $matieres = db_all($sql, $params);
    $reference = secondaire_reference_coefficients();
    foreach ($matieres as $m) {
        [$coef, $ordre] = $reference[$code_niveau . '|' . $m['libelle']] ?? [1, 1];
        db_exec(
            "INSERT IGNORE INTO discipline (id_mat, IDClasses, id_groupe, coef, ordre) VALUES (?,?,?,?,?)",
            [$m['id_matiere'], $id_classe, (int) $id_groupe, $coef, (string) $ordre]
        );
    }
    return count($matieres);
}

/**
 * Point d'entrée unique : remplit automatiquement les matières d'une
 * classe (secondaire) qui n'en a encore aucune — d'abord depuis une classe
 * sœur déjà affectée (coefficients réels déjà saisis), sinon depuis les
 * compétences déjà configurées pour le niveau (coefficient par défaut 1).
 * Appelée à la création d'une classe (secondaire/pages/classes/form.php)
 * et dans l'onglet « Affectation par classe » dès qu'une classe sans
 * aucune matière est sélectionnée. Demande explicite du 25/09/2026.
 */
function secondaire_auto_matieres_classe(int $id_classe, ?string $code_niveau, ?string $libelle_section): int {
    if ($id_classe <= 0 || (int) db_val("SELECT COUNT(*) FROM discipline WHERE IDClasses=?", [$id_classe])) {
        return 0;
    }
    $n = secondaire_copier_matieres_niveau($id_classe, $code_niveau, $libelle_section);
    return $n > 0 ? $n : secondaire_deriver_matieres_competences($id_classe, $code_niveau, $libelle_section);
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
    // Rôle COMPTABLE (et la double-casquette qu'il décrit) n'existe pas
    // dans le schéma secondaire — enseignat_classe non plus. No-op.
    if (function_exists('type_enseignement_courant') && type_enseignement_courant() === 'secondaire') return false;
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

// ── Contrôle d'accès par classe / élève — ÉCOLE SECONDAIRE ─────────────
// Équivalent secondaire de classes_ids_visibles()/exiger_acces_*() ci-dessus
// (schéma différent : dispenser / enseignat_principal / sg, année = libellé).
// Empêche d'ouvrir la fiche, la liste, la carte, le certificat ou le
// bulletin d'un élève hors de son périmètre en modifiant l'identifiant dans
// la barre d'adresse (?id=, ?eleve=, ?classe=).
//   ENSEIGNANT : classes où il enseigne (dispenser) + dont il est PP ;
//   SG         : ses classes (table sg) + celles où il enseigne / est PP ;
//   autres     : non restreints (null) — leurs pages restent gardées par rôle.
// $pp_seulement : seulement les classes dont il est professeur principal
// (règle des bulletins, comme secondaire/pages/bulletins/pdf_classe.php).
function sec_classes_ids_visibles(bool $pp_seulement = false): ?array {
    $role = role_connecte();
    if (!in_array($role, ['ENSEIGNANT', 'SG'], true)) return null;
    $mat   = matricule_ens_courant();
    if (!$mat) return [];
    $annee = get_annee_active()['libelle'] ?? '';
    $sql   = ["SELECT IDClasses FROM enseignat_principal WHERE matricule_ens=? AND val_annee=?"];
    if (!$pp_seulement) {
        $sql[] = "SELECT IDClasses FROM dispenser WHERE matricule_ens=? AND val_annee=?";
        if ($role === 'SG') $sql[] = "SELECT IDClasses FROM sg WHERE matricule_ens=? AND val_annee=?";
    }
    $ids = [];
    foreach ($sql as $q) foreach (db_all($q, [$mat, $annee]) as $r) $ids[(int) $r['IDClasses']] = true;
    return array_keys($ids);
}

function sec_refuser_acces(string $message): never {
    http_response_code(403);
    die('<div style="font-family:sans-serif;padding:2rem;color:#b91c1c">' . h($message) . '</div>');
}

function sec_classe_eleve(int $id_eleve): int {
    return (int) db_val("SELECT id_classe FROM inscription WHERE id_eleve=? AND id_annee=?",
                        [$id_eleve, (int) (get_annee_active()['id'] ?? 0)]);
}

function exiger_acces_classe_secondaire(int $id_classe): void {
    if ($id_classe <= 0) return;
    $ids = sec_classes_ids_visibles();
    if ($ids !== null && !in_array($id_classe, $ids, true)) sec_refuser_acces('Accès refusé : cette classe ne fait pas partie de vos classes.');
}

function exiger_acces_eleve_secondaire(int $id_eleve): void {
    if ($id_eleve <= 0) return;
    $ids = sec_classes_ids_visibles();
    if ($ids !== null && !in_array(sec_classe_eleve($id_eleve), $ids, true)) sec_refuser_acces('Accès refusé : cet élève ne fait pas partie de vos classes.');
}

// Bulletin individuel : administration, ou professeur principal de la classe
// de l'élève — mêmes personnes que pour le bulletin de classe.
function exiger_acces_bulletin_secondaire(int $id_eleve): void {
    $role = role_connecte();
    if (in_array($role, ['ADMIN', 'PROVISEUR', 'FONDATEUR', 'CENSEUR', 'MEMBRE_ASSOCIATION'], true)
        || (function_exists('est_visite_association') && est_visite_association())) return;
    if ($role === 'ENSEIGNANT' && in_array(sec_classe_eleve($id_eleve), sec_classes_ids_visibles(true) ?? [], true)) return;
    sec_refuser_acces('Accès refusé : seuls l\'administration et le professeur principal de la classe peuvent consulter ce bulletin.');
}

// ── Questions secrètes (récupération de mot de passe — migration_v45) ────

function utilisateur_a_questions(int $id_user): bool {
    if (!$id_user) return false;
    if (function_exists('type_enseignement_courant') && type_enseignement_courant() === 'secondaire') {
        return (int) db_val("SELECT COUNT(*) FROM utilisateur_question_secrete WHERE id_utilisateur = ?", [$id_user]) >= 2;
    }
    return (int) db_val("SELECT COUNT(*) FROM user_question_secrete WHERE id_user = ?", [$id_user]) >= 2;
}

function normaliser_reponse(string $r): string {
    return mb_strtolower(trim($r), 'UTF-8');
}

function exiger_role(array $roles): void {
    exiger_connexion();
    // Membre association en visite.
    if (function_exists('est_visite_association') && est_visite_association()) {
        // Administrateur (superadmin/propriétaire) : lecture accordée sur
        // toutes les pages, comportement inchangé (« visiter toutes les
        // infos »). Les écritures restent bloquées par csrf_verifier() /
        // db_exec() (est_lecture_seule()).
        if (function_exists('est_visite_association_administrateur') && est_visite_association_administrateur()) {
            return;
        }
        // Membre / Superviseur : bloqué pour de vrai (pas juste en lecture
        // seule) si la page est masquée pour son rôle synthétique
        // MEMBRE_ASSOCIATION — Utilisateurs/Paramètres école par défaut,
        // configurable depuis association/acces.php. Demande du 23/09/2026 :
        // ces rubriques ne doivent pas être VUES, pas seulement non-modifiables.
        if (function_exists('niveau_central_page_courante') && niveau_central_page_courante() === 'masque') {
            http_response_code(403);
            die('<div style="font-family:sans-serif;padding:2rem;color:#b45309">
                 Cette rubrique n\'est pas accessible à votre profil de visite.</div>');
        }
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
    // Licence (bd/lib/licence.php) : message CONVIVIAL tôt, avant même
    // d'atteindre l'action de la page — db_exec() (connexion.php) reste le
    // filet de sécurité bas niveau (chaque écriture SQL individuellement),
    // mais SANS ce garde ici, une licence expirée faisait planter la page
    // avec une trace technique brute (RuntimeException non attrapée) au
    // lieu d'un message clair (bug réel trouvé en test le 13/09/2026).
    // Toutes les exemptions (propriétaire, annuaire association, continuité
    // de compte, page Licence elle-même) sont centralisées dans
    // licence_ecriture_bloquee() (bd/lib/licence.php).
    if (function_exists('licence_ecriture_bloquee') && licence_ecriture_bloquee()) {
        die('<div style="font-family:sans-serif;padding:2rem;color:#8a1c1c">'
          . 'Licence expirée — l\'application est en lecture seule. Contactez le propriétaire de l\'association pour renouveler '
          . '(menu Paramètres &gt; Licence).</div>');
    }
    $token = $_POST['csrf'] ?? $_GET['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
        die('Requête invalide (CSRF).');
    }
}

// ── Liens signés (paramètres non modifiables dans la barre d'adresse) ──
// Un lien généré par url_signee() porte un code « t » calculé côté serveur
// (HMAC-SHA256 avec une clé propre à la SESSION) sur son contexte et ses
// paramètres : changer ?classe=17 en ?classe=21 à la main invalide le code et
// exiger_lien_signe() refuse la page. $contexte regroupe les pages qui
// partagent le même lien (ex. 'finances_classe' : écran + PDF + Excel).
// Limite assumée : un lien signé n'est valable que pendant la session (un
// favori ne marche plus après déconnexion — rouvrir depuis le menu).
// Complément, PAS un remplacement, des contrôles de rôle / de classe.
function lien_signature(string $contexte, array $params): string {
    session_init();
    if (empty($_SESSION['cle_liens'])) $_SESSION['cle_liens'] = bin2hex(random_bytes(32));
    ksort($params);
    $params = array_map('strval', $params);
    return substr(hash_hmac('sha256', $contexte . '|' . http_build_query($params), $_SESSION['cle_liens']), 0, 20);
}

// URL complète (APP_URL + chemin relatif) avec les paramètres et leur code.
function url_signee(string $chemin, string $contexte, array $params): string {
    return APP_URL . '/' . ltrim($chemin, '/') . '?' . http_build_query($params + ['t' => lien_signature($contexte, $params)]);
}

function exiger_lien_signe(string $contexte, array $params): void {
    if (hash_equals(lien_signature($contexte, $params), (string) ($_GET['t'] ?? ''))) return;
    http_response_code(403);
    die('<div style="font-family:sans-serif;padding:2rem;color:#b91c1c">Lien invalide ou modifié. '
      . 'Rouvrez cette page depuis le menu de l\'application.</div>');
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

// get_annee_active() / get_etablissement() : appelées de nombreuses fois par
// page (en-tête, pied de page, PDF, fonctions) — résultat mémorisé le temps
// de la requête, par base d'école, vidé à toute écriture sur la table (voir
// db_cache_cle() / db_cache_vider(), connexion.php).
function get_annee_active(): array {
    $cle = db_cache_cle('annee_active');
    return $GLOBALS['_db_cache_globales'][$cle] ??= get_annee_active_sans_cache();
}

function get_annee_active_sans_cache(): array {
    // École secondaire : annee_scolaire.active/.libelle (pas Etat_annee_
    // scolaire/val_annee) — mêmes clés val_annee/Etat_annee_scolaire
    // rajoutées en alias pour que TOUT le reste de l'appli (header.php,
    // dashboard.php, etc.) continue de lire les mêmes clés sans distinguo.
    if (function_exists('type_enseignement_courant') && type_enseignement_courant() === 'secondaire') {
        $a = db_one("SELECT * FROM annee_scolaire WHERE active=1 LIMIT 1")
            ?? db_one("SELECT * FROM annee_scolaire ORDER BY libelle DESC LIMIT 1");
        if (!$a) {
            // Aucune année scolaire : rien ne provisionne encore ce
            // schéma à la création d'une école secondaire (voir commit
            // fb21462). Sans repli, get_annee_active()['id'] reste NULL
            // pour toujours -> aucune inscription possible nulle part
            // (bug réel constaté le 15/09/2026 : élève créé, mais jamais
            // inscrit, faute d'année). Auto-amorçage à l'usage plutôt
            // qu'un écran dédié (hors scope de cette étape) : convention
            // août->juillet, comme le seed primaire (bd/assoc/
            // provisionner_ecole_neuve()).
            $an = (int) date('n') >= 8 ? (int) date('Y') : (int) date('Y') - 1;
            $libelle = $an . '-' . ($an + 1);
            db_exec("INSERT IGNORE INTO annee_scolaire (libelle, active) VALUES (?, 1)", [$libelle]);
            $a = db_one("SELECT * FROM annee_scolaire WHERE libelle=?", [$libelle]);
        }
        if (!$a) return ['val_annee' => '—', 'Etat_annee_scolaire' => 0, 'id' => null];
        $a['val_annee'] = $a['libelle'];
        $a['Etat_annee_scolaire'] = $a['active'];
        return $a;
    }
    return db_one("SELECT * FROM annee_scolaire WHERE Etat_annee_scolaire=1 LIMIT 1")
        ?? db_one("SELECT * FROM annee_scolaire ORDER BY val_annee DESC LIMIT 1")
        ?? ['val_annee' => '—', 'Etat_annee_scolaire' => 0];
}

function get_etablissement(): array {
    // Contexte neutre (multi-école, aucune choisie) : aucune identité d'école
    // — la page appelante doit afficher un habillage générique (login.php).
    if (function_exists('est_contexte_neutre') && est_contexte_neutre()) return [];
    $cle = db_cache_cle('etablissement');
    return $GLOBALS['_db_cache_globales'][$cle] ??= get_etablissement_sans_cache();
}

function get_etablissement_sans_cache(): array {
    $e = db_one("SELECT * FROM etablissement LIMIT 1") ?? [];
    // École secondaire (schema_ref_ecole_secondaire.sql, porté de LAM_ABZ) :
    // colonnes nom_fr/sigle au lieu de Nom_Etab_Fr/Initial_Etab — alias
    // ajoutés ICI plutôt que de réécrire chaque lecture éparpillée dans
    // layout/header.php etc. (logo est déjà le même nom des deux côtés).
    if ($e && function_exists('type_enseignement_courant') && type_enseignement_courant() === 'secondaire') {
        $e['Nom_Etab_Fr'] = $e['nom_fr'] ?? '';
        $e['Initial_Etab'] = $e['sigle'] ?? '';
    }
    return $e;
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

// ── Filigrane généré depuis le logo de l'école (association/index.php) ──
// Chemin relatif (sous assets/uploads/) du filigrane dérivé d'un logo :
// même dossier, même nom, suffixe _filigrane.png (toujours PNG — canal
// alpha nécessaire, indépendamment du format d'origine du logo).
function chemin_filigrane_logo(string $chemin_logo): string {
    $dir  = pathinfo($chemin_logo, PATHINFO_DIRNAME);
    $nom  = pathinfo($chemin_logo, PATHINFO_FILENAME);
    $pref = ($dir !== '' && $dir !== '.') ? $dir . '/' : '';
    return $pref . $nom . '_filigrane.png';
}

// Génère (si absent ou périmé) une version « filigrane » d'un logo école :
// niveau de gris + transparence, taille plafonnée. Utilisée en fond de
// case sur association/index.php (une par école, dérivée de SON logo).
// Idempotent et best-effort (jamais fatal — un logo illisible ne doit pas
// casser la page) : retourne true si le fichier de destination existe à la
// fin de l'appel, qu'il vienne d'être généré ou qu'il soit déjà à jour.
function generer_filigrane_logo(string $source_abs, string $dest_abs): bool {
    if (!is_file($source_abs)) return false;
    if (is_file($dest_abs) && filemtime($dest_abs) >= filemtime($source_abs)) return true;
    if (!extension_loaded('gd')) return false;

    $ext = strtolower(pathinfo($source_abs, PATHINFO_EXTENSION));
    $src = match ($ext) {
        'png'         => @imagecreatefrompng($source_abs),
        'jpg', 'jpeg' => @imagecreatefromjpeg($source_abs),
        default       => null,
    };
    if (!$src) return false;

    // Taille plafonnée : le filigrane n'a pas besoin d'être plus grand que
    // ce qu'affiche la case école, pas la peine d'alourdir assets/uploads/.
    $max = 500;
    $w = imagesx($src);
    $h = imagesy($src);
    $ratio = min(1, $max / max($w, $h));
    $nw = max(1, (int) round($w * $ratio));
    $nh = max(1, (int) round($h * $ratio));

    $dst = imagecreatetruecolor($nw, $nh);
    imagealphablending($dst, true);
    imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($src);

    // Niveaux de gris + alpha réduit (~18% d'opacité) directement dans le
    // fichier généré : le fond de case n'a besoin d'aucun style CSS
    // d'opacité, l'image est DÉJÀ un filigrane.
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagefilter($dst, IMG_FILTER_GRAYSCALE);
    imagefilter($dst, IMG_FILTER_COLORIZE, 0, 0, 0, 105);

    $dir = dirname($dest_abs);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $ok = imagepng($dst, $dest_abs);
    imagedestroy($dst);
    return $ok;
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

// Même principe que pdf_fill(), pour la couleur de trait/bordure
// (SetDrawColor($r,$g,$b) — ex. cadre de page, bordure de la pilule titre).
function pdf_draw($pdf, string $cle): void {
    [$r, $g, $b] = couleur_pdf($cle);
    $pdf->SetDrawColor($r, $g, $b);
}

// Palette par défaut — SEULE source de vérité pour les 5 rôles visuels du
// bulletin secondaire (secondaire/pages/bulletins/pdf.php, bulletin
// individuel) — reprise par bd/secondaire/migration_v3.sql /
// bd/assoc/seed_ref_ecole_secondaire.sql pour le remplissage initial, et par
// secondaire/pages/parametres/index.php (onglet « Couleurs bulletin ») pour
// la maquette interactive et le bouton « Réinitialiser ». Volontairement
// distincte des 10 rôles primaire (pdf_couleur, table par école — un même
// nom `cle` sur des écoles de types différents n'implique pas le même sens
// visuel) : entete_tableau_classe et ligne_total_groupe (présents côté
// ABZ_MBE, dont ce bulletin est porté) sont omis tant que pdf_classe.php et
// le regroupement par matière ne sont pas branchés sur cette table.
function pdf_couleurs_defaut_secondaire(): array {
    return [
        'bandeau_titre'             => ['libelle' => "Bandeau titre (pilule d'en-tête)", 'r' => 219, 'g' => 228, 'b' => 245],
        'bordure_marque'            => ['libelle' => 'Bordure / couleur de marque', 'r' => 26, 'g' => 60, 'b' => 107],
        'entete_tableau_individuel' => ['libelle' => 'En-tête du tableau de compétences', 'r' => 26, 'g' => 60, 'b' => 107],
        'bandeau_section'           => ['libelle' => 'Bandeaux de section (Disciplines/Travail/Profil/Résultats, matière, décision)', 'r' => 216, 'g' => 210, 'b' => 248],
        'ligne_echec'               => ['libelle' => 'Surlignage moyenne insuffisante (< 10/20)', 'r' => 255, 'g' => 235, 'b' => 235],
    ];
}

function get_sequence_active(): array {
    // École secondaire : sequence.active/.libelle + trimestre.id/.libelle
    // (pas etat/libelle_seq/id_trim/libelle_trim) — alias libelle_seq
    // rajouté (lu par header.php) comme pour get_annee_active() ci-dessus.
    if (function_exists('type_enseignement_courant') && type_enseignement_courant() === 'secondaire') {
        $s = db_one(
            "SELECT s.*, t.libelle AS libelle_trim
             FROM sequence s JOIN trimestre t ON t.id = s.id_trim
             WHERE s.active = 1 LIMIT 1"
        ) ?? [];
        if ($s) $s['libelle_seq'] = $s['libelle'];
        return $s;
    }
    return db_one(
        "SELECT s.*, t.libelle_trim
         FROM sequence s JOIN trimestre t ON t.id_trim = s.id_trim
         WHERE s.etat = 1 LIMIT 1"
    ) ?? [];
}

// Applique le gabarit de compétences (bd/assoc/seed_competences_secondaire.sql,
// indexé par libellé de matière + niveau + ordre de trimestre) aux trimestres
// de l'année donnée. Idempotent (NOT EXISTS) ; ne touche jamais à une
// compétence existante. Retourne le nombre de compétences ajoutées.
// Nécessite les matières/niveaux de référence (seed_ref_ecole_secondaire.sql) :
// une matière ou un niveau absent est simplement ignoré.
function appliquer_competences_ref_secondaire(int $id_annee): int {
    global $link;
    if ($id_annee <= 0) return 0;
    if (function_exists('est_lecture_seule') && est_lecture_seule()) return 0;
    $sql = @file_get_contents(__DIR__ . '/bd/assoc/seed_competences_secondaire.sql');
    if ($sql === false || trim($sql) === '') return 0;
    $sql = str_replace(':ID_ANNEE:', (string) $id_annee, $sql);
    try {
        if (!mysqli_query($link, $sql)) {
            error_log('appliquer_competences_ref_secondaire: ' . mysqli_error($link));
            return 0;
        }
        return max(0, mysqli_affected_rows($link));
    } catch (\Throwable $e) {
        error_log('appliquer_competences_ref_secondaire: ' . $e->getMessage());
        return 0;
    }
}

// Crée (si absentes) les 3 trimestres et leurs 2 séquences chacun d'une
// année scolaire secondaire donnée — structure seulement, AUCUN active=1
// décidé ici (voir activer_trimestre()/activer_sequence() ci-dessous) :
// appelée aussi bien pour l'année ACTIVE (bootstrap paresseux au fil de
// get_trimestre_actif()) que pour une année tout juste CRÉÉE et pas encore
// active (secondaire/pages/parametres/index.php::annee_creer — demande
// explicite du 17/09/2026 : « les trimestres et leurs séquences doivent
// être créés comme ce modèle existant » dès la création de l'année, pas
// seulement à son activation). Idempotent (vérifie l'existant avant
// d'insérer), rejouable sans dupliquer.
function provisionner_trimestres_annee(int $id_annee): void {
    if (!$id_annee) return;
    if (!db_val("SELECT COUNT(*) FROM trimestre WHERE id_annee=?", [$id_annee])) {
        foreach (['Trimestre 1', 'Trimestre 2', 'Trimestre 3'] as $i => $lib) {
            db_exec("INSERT INTO trimestre (libelle, ordre, id_annee, active) VALUES (?, ?, ?, 0)",
                    [$lib, $i + 1, $id_annee]);
        }
        // Compétences par matière/niveau/trimestre : liées aux trimestres de
        // l'année, donc perdues avec elle — on repart du gabarit de référence
        // (une seule fois, à la création des trimestres : ne réinjecte jamais
        // ce qu'une école aurait volontairement supprimé ensuite).
        appliquer_competences_ref_secondaire($id_annee);
    }
    // Chaque trimestre est subdivisé en 2 séquences — système d'évaluation
    // historique de LAM_ABZ (table `sequence`), toujours utilisé par
    // secondaire/pages/notes/ (onglets élève/copie/non saisies) alors même
    // que l'onglet « par classe » est déjà passé aux compétences par
    // trimestre. Vérifié indépendamment de l'existence des trimestres —
    // une année dont les trimestres existaient déjà (créés par un appel
    // antérieur) doit quand même obtenir ses séquences (bug réel constaté
    // le 16/09/2026, étape 9/Notes, quand ce contrôle était imbriqué dans
    // la seule branche « trimestre absent »).
    if (!db_val("SELECT COUNT(*) FROM sequence s JOIN trimestre t ON t.id=s.id_trim WHERE t.id_annee=?", [$id_annee])) {
        $trims = db_all("SELECT id FROM trimestre WHERE id_annee=? ORDER BY ordre", [$id_annee]);
        foreach ($trims as $tr) {
            foreach (['Séquence 1', 'Séquence 2'] as $j => $lib_s) {
                db_exec("INSERT INTO sequence (libelle, ordre, active, id_trim) VALUES (?, ?, 0, ?)",
                        [$lib_s, $j + 1, $tr['id']]);
            }
        }
    }
}

// Bascule le trimestre ACTIF, GLOBALEMENT (une seule année scolaire est
// active à la fois — annee_scolaire.active — donc un seul trimestre doit
// l'être aussi dans toute la base) : désactive systématiquement tous les
// autres avant d'activer celui-ci. Sans ce nettoyage global, changer
// d'année active (secondaire/pages/parametres/index.php::annee_activer)
// laissait le trimestre actif de l'ANCIENNE année marqué actif pour
// toujours (bug latent, corrigé au passage de ce même correctif).
function activer_trimestre(int $id_trimestre): void {
    db_exec("UPDATE trimestre SET active=0");
    db_exec("UPDATE trimestre SET active=1 WHERE id=?", [$id_trimestre]);
}

// Idem pour la séquence active — get_sequence_active() (fonctions.php) fait
// une requête GLOBALE (WHERE sequence.active=1, jamais filtrée par année) :
// il ne doit donc jamais y avoir plus d'une séquence active à la fois dans
// toute la base, sous peine de résultat ambigu (LIMIT 1 sans ORDER BY sur
// plusieurs lignes actives = ligne arbitraire, potentiellement celle d'une
// année qui n'est plus active).
function activer_sequence(int $id_sequence): void {
    db_exec("UPDATE sequence SET active=0");
    db_exec("UPDATE sequence SET active=1 WHERE id=?", [$id_sequence]);
}

// École secondaire uniquement (schema_ref_ecole_secondaire.sql) : le
// trimestre actif de l'année active, utilisé par le module Matières (onglet
// « Compétences par trimestre », porté de LAM_ABZ). Sans repli, une école
// neuve n'a aucun trimestre (table `trimestre` jamais provisionnée par
// charger_schema_ecole()) -> onglet vide et inutilisable, comme le bug
// constaté le 15/09/2026 sur get_annee_active() sans année scolaire : même
// auto-amorçage à l'usage plutôt qu'un écran dédié (hors scope de cette
// étape). Nom volontairement distinct de sequences_trimestre_actif() (même
// fichier, système du PRIMAIRE) — deux systèmes non interchangeables.
function get_trimestre_actif(): array {
    $annee = get_annee_active();
    if (empty($annee['id'])) return [];
    $id_annee = (int) $annee['id'];
    provisionner_trimestres_annee($id_annee);

    $t = db_one(
        "SELECT t.*, a.libelle AS annee_lib FROM trimestre t
         JOIN annee_scolaire a ON a.id=t.id_annee
         WHERE t.id_annee=? AND t.active=1 LIMIT 1",
        [$id_annee]
    );
    if (!$t) {
        // Les trimestres existent déjà (provisionnés ici ou dès la création
        // de l'année — annee_creer) mais aucun n'est actif pour CETTE
        // année (ex. année tout juste activée) — bascule vers le 1er par
        // ordre. activer_trimestre() nettoie aussi l'actif d'une AUTRE
        // année au passage (voir sa docstring).
        $premier_id = db_val("SELECT id FROM trimestre WHERE id_annee=? ORDER BY ordre LIMIT 1", [$id_annee]);
        if ($premier_id) {
            activer_trimestre((int) $premier_id);
            $t = db_one(
                "SELECT t.*, a.libelle AS annee_lib FROM trimestre t
                 JOIN annee_scolaire a ON a.id=t.id_annee
                 WHERE t.id=?",
                [$premier_id]
            );
        }
    }

    // Même bascule pour la séquence active — voir activer_sequence().
    $seq_active_ok = db_val(
        "SELECT COUNT(*) FROM sequence s JOIN trimestre tr ON tr.id=s.id_trim WHERE tr.id_annee=? AND s.active=1",
        [$id_annee]
    );
    if (!$seq_active_ok) {
        $premiere_seq_id = db_val(
            "SELECT s.id FROM sequence s JOIN trimestre tr ON tr.id=s.id_trim
             WHERE tr.id_annee=? ORDER BY tr.ordre, s.ordre LIMIT 1",
            [$id_annee]
        );
        if ($premiere_seq_id) activer_sequence((int) $premiere_seq_id);
    }

    return $t ?: [];
}

// Désactive toute séquence dont la date de fin est passée, puis active
// celle couvrant aujourd'hui s'il n'y en a plus aucune d'active — porté de
// LAM_ABZ/fonctions.php. No-op tant que date_debut/date_fin ne sont pas
// renseignées (pas encore d'écran Paramètres secondaire pour ça) : la
// séquence choisie par le bootstrap de get_trimestre_actif() reste active.
function auto_activer_sequences(): void {
    db_exec("UPDATE sequence SET active=0 WHERE date_fin IS NOT NULL AND date_fin < CURDATE()");
    $nb_active = (int) db_val("SELECT COUNT(*) FROM sequence WHERE active=1");
    if ($nb_active === 0) {
        $a = db_one(
            "SELECT id FROM sequence
             WHERE date_debut IS NOT NULL AND date_fin IS NOT NULL
               AND date_debut <= CURDATE() AND date_fin >= CURDATE()
             ORDER BY date_debut DESC LIMIT 1"
        );
        if ($a) db_exec("UPDATE sequence SET active=1 WHERE id=?", [$a['id']]);
    }
}

// ── Cote/appréciation sur 20 (secondaire, système APC de LAM_ABZ) ──────
// Portées telles quelles depuis LAM_ABZ/fonctions.php — utilisées par
// secondaire/pages/notes/ (saisie par classe/élève).
function appreciation($note): array {
    $n = ($note === null || $note === '') ? -1 : (float) $note;
    if ($n < 0)  return ['COTE' => '',   'APPR1_FR' => '',                          'APPR2_FR' => '',    'APPR1_EN' => '',                             'APPR2_EN' => ''];
    if ($n < 10) return ['COTE' => 'D',  'APPR1_FR' => 'Compétences non acquises',  'APPR2_FR' => 'CNA', 'APPR1_EN' => 'Competences Not Acquired',      'APPR2_EN' => 'CNA'];
    if ($n < 12) return ['COTE' => 'C',  'APPR1_FR' => 'Compétences moy. acquises', 'APPR2_FR' => 'CMA', 'APPR1_EN' => 'Competences Avg Acquired',      'APPR2_EN' => 'CAA'];
    if ($n < 14) return ['COTE' => 'C+', 'APPR1_FR' => 'Compétences acquises',      'APPR2_FR' => 'CA',  'APPR1_EN' => 'Competences Acquired',          'APPR2_EN' => 'CA'];
    if ($n < 15) return ['COTE' => 'B',  'APPR1_FR' => 'Compétences bien acquises', 'APPR2_FR' => 'CBA', 'APPR1_EN' => 'Competences Well Acquired',     'APPR2_EN' => 'CWA'];
    if ($n < 16) return ['COTE' => 'B+', 'APPR1_FR' => 'Compétences bien acquises', 'APPR2_FR' => 'CBA', 'APPR1_EN' => 'Competences Well Acquired',     'APPR2_EN' => 'CWA'];
    if ($n < 18) return ['COTE' => 'A',  'APPR1_FR' => 'Compétences TB acquises',   'APPR2_FR' => 'CTBA','APPR1_EN' => 'Competences Very Well Acquired','APPR2_EN' => 'CVWA'];
    return            ['COTE' => 'A+', 'APPR1_FR' => 'Compétences TB acquises',   'APPR2_FR' => 'CTBA','APPR1_EN' => 'Competences Very Well Acquired','APPR2_EN' => 'CVWA'];
}

function appr_color(string $cote): string {
    if ($cote === 'A+' || $cote === 'A') return '#15803d';
    if ($cote === 'B+' || $cote === 'B') return '#1d4ed8';
    if ($cote === 'C+')                   return '#ca8a04';
    if ($cote === 'C')                    return '#d97706';
    if ($cote === 'D')                    return '#dc2626';
    return '#374151';
}

function appr_badge(array $a): string {
    if ($a['COTE'] === '') return '';
    $col = appr_color($a['COTE']);
    return '<span style="color:' . $col . ';font-weight:700">' . h($a['COTE']) . '</span>'
         . ' <span style="color:#374151">' . h($a['APPR1_FR']) . '</span>'
         . ' <span style="color:#9ca3af;font-size:.7rem">(' . h($a['APPR2_FR']) . ')</span>';
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
        journaliser_mouvement_classe($eid, $nouvelle_annee, null, $classe_dest, 'inscription');
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

// Identifiant d'affichage d'un élève (matricule — clé métier de `eleve`).
// Type-aware depuis le 26/09/2026 : appelée par ~12 fichiers secondaire
// (bulletins, cartes, certificat de scolarité, tableau d'honneur, fiche/
// liste élèves…) qui recevaient jusqu'ici une chaîne vide en permanence
// (colonne Mat_elv inexistante côté secondaire, qui utilise `matricule` —
// bug réel constaté le 26/09/2026 en corrigeant le QR du certificat de
// scolarité : le matricule signé dans le QR était toujours '').
/**
 * Nom du chef d'établissement (directeur / principal / proviseur) tel
 * qu'enregistré dans Ressources humaines, pour les documents officiels
 * (certificat de scolarité…). Ordre de recherche :
 *   1. l'utilisateur connecté s'il est lui-même le chef (sa fiche « Mes
 *      informations ») ;
 *   2. le compte d'accès DIRECTEUR (primaire) / PROVISEUR (secondaire)
 *      actif, via sa fiche personnel liée (matricule_ens) ;
 *   3. le personnel dont la fonction est directeur / directrice / principal
 *      / proviseur (en poste, avec un accès actif en priorité) ;
 *   4. (secondaire) le nom saisi sur le compte PROVISEUR lui-même.
 * Les libellés génériques (« Direction (Principal) », « Le Directeur »…)
 * posés par l'installation sont ignorés : '' si aucun vrai nom trouvé.
 */
function nom_chef_etablissement(): string {
    static $cache = [];
    $cle = db_cache_cle('chef') . '|' . (string) matricule_ens_courant();
    if (isset($cache[$cle])) return $cache[$cle];

    $generique = fn(string $n): bool => trim(preg_replace(
        '/\b(le|la|l|du|de|des|general|générale?|direction|directeur|directrice|principale?|proviseure?|chef|etablissement|établissement)\b|[^\p{L}]+/iu',
        '', $n)) === '';
    $retenir = function (?string $n) use ($generique): ?string {
        $n = trim(preg_replace('/\s+/', ' ', (string) $n));
        // Fiche créée d'office avec l'école (« DIRECTEUR <nom de l'école> »,
        // « Direction SAB1 »…) : un vrai nom ne commence pas par un titre.
        $premier = preg_split('/[^\p{L}]+/u', $n, -1, PREG_SPLIT_NO_EMPTY)[0] ?? '';
        return ($n !== '' && !$generique($n) && !$generique($premier)) ? $n : null;
    };
    $sec = function_exists('type_enseignement_courant') && type_enseignement_courant() === 'secondaire';
    $nom = null;
    try {
        $mat = matricule_ens_courant();
        if ($mat !== null && in_array(role_connecte(), ['DIRECTEUR', 'PROVISEUR'], true)) {
            $nom = $retenir(db_val("SELECT CONCAT_WS(' ', nom_ens, prenom_ens) FROM enseignant WHERE matricule_ens=?", [$mat]));
        }
        if ($nom === null && $sec) {
            foreach (db_all(
                "SELECT CONCAT_WS(' ', e.nom_ens, e.prenom_ens) AS fiche, CONCAT_WS(' ', u.nom, u.prenom) AS compte
                 FROM utilisateur u LEFT JOIN enseignant e ON e.matricule_ens = u.matricule_ens
                 WHERE u.role = 'PROVISEUR' ORDER BY u.actif DESC, u.id DESC") as $r) {
                if (($nom = $retenir($r['fiche'])) !== null) break;
            }
            if ($nom === null) {
                foreach (db_all(
                    "SELECT CONCAT_WS(' ', e.nom_ens, e.prenom_ens) AS n, e.id_fonction FROM enseignant e
                     LEFT JOIN utilisateur u ON u.matricule_ens = e.matricule_ens
                     WHERE e.id_fonction IS NOT NULL
                     ORDER BY COALESCE(u.actif, 0) DESC, e.matricule_ens DESC") as $r) {
                    if (fonction_est_chef($r['id_fonction']) && ($nom = $retenir($r['n'])) !== null) break;
                }
            }
            if ($nom === null) {
                foreach (db_all("SELECT CONCAT_WS(' ', nom, prenom) AS n FROM utilisateur
                                 WHERE role = 'PROVISEUR' ORDER BY actif DESC, id DESC") as $r) {
                    if (($nom = $retenir($r['n'])) !== null) break;
                }
            }
        } elseif ($nom === null) {
            foreach (db_all(
                "SELECT CONCAT_WS(' ', e.nom_ens, e.prenom_ens) AS n FROM enseignant e
                 LEFT JOIN user u ON u.matricule_ens = e.matricule_ens
                 WHERE e.id_fonction = 'DIRECTEUR'
                 ORDER BY (COALESCE(e.statut_ens, 'actif') = 'actif') DESC,
                          COALESCE(u.actif, 0) DESC, e.matricule_ens DESC") as $r) {
                if (($nom = $retenir($r['n'])) !== null) break;
            }
        }
    } catch (Throwable $e) {
        $nom = null;   // table/colonne absente sur une base non migrée : jamais fatal
    }
    return $cache[$cle] = (string) $nom;
}

// ── Un et un seul chef d'établissement par école (01/10/2026) ──────────
// Primaire : personnel de fonction DIRECTEUR. Secondaire : compte de rôle
// PROVISEUR (+ la fonction RH libre, voir fonction_est_chef()). Nommer un
// nouveau chef depuis Fondateur → Directeur REMPLACE l'ancien (rétrogradé
// en enseignant) ; partout ailleurs une 2e nomination est refusée.

/** Fonction RH libre (secondaire) désignant le chef : « Principal », « Le Proviseur »… */
function fonction_est_chef(?string $fonction): bool {
    $f = mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $fonction)));
    $f = preg_replace('/^(le |la |l\')/u', '', $f);
    return in_array($f, ['directeur', 'directrice', 'principal', 'principale', 'proviseur', 'proviseure'], true);
}

/**
 * Chef(s) d'établissement enregistré(s), actifs ou non — normalement 0 ou 1.
 * Chaque ligne : ['cle' => matricule_ens (primaire) | id utilisateur
 * (secondaire), 'nom' => « NOM Prénom »]. $sauf : clé à ignorer (la fiche
 * ou le compte en cours de modification).
 */
function chefs_etablissement(?int $sauf = null): array {
    $sec = function_exists('type_enseignement_courant') && type_enseignement_courant() === 'secondaire';
    $rows = $sec
        ? db_all("SELECT id AS cle, CONCAT_WS(' ', UPPER(nom), prenom) AS nom FROM utilisateur WHERE role='PROVISEUR' ORDER BY actif DESC, id DESC")
        : db_all("SELECT matricule_ens AS cle, CONCAT_WS(' ', UPPER(nom_ens), prenom_ens) AS nom FROM enseignant WHERE id_fonction='DIRECTEUR'
                  ORDER BY (COALESCE(statut_ens,'actif')='actif') DESC, matricule_ens DESC");
    return array_values(array_filter($rows, fn($r) => $sauf === null || (int) $r['cle'] !== $sauf));
}

/** Message de refus si un autre chef existe déjà ('' sinon). */
function refus_second_chef(?int $sauf = null): string {
    $autres = chefs_etablissement($sauf);
    if (!$autres) return '';
    $poste = libelle_role(type_enseignement_courant() === 'secondaire' ? 'PROVISEUR' : 'DIRECTEUR');
    return 'Une école ne peut avoir qu\'un seul ' . mb_strtolower($poste) . ' : « ' . trim($autres[0]['nom'])
         . ' » l\'est déjà. Pour le remplacer, le fondateur passe par le menu « Directeur » (l\'ancien sera rétrogradé).';
}

function id_affichage_eleve(array $eleve): string {
    if (function_exists('type_enseignement_courant') && type_enseignement_courant() === 'secondaire') {
        return (string) ($eleve['matricule'] ?? '');
    }
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
        // Rôles école SECONDAIRE (utilisateur.role, schema_ref_ecole_secondaire.sql
        // — vocabulaire LAM_ABZ, distinct des rôles primaire ci-dessus).
        // PROVISEUR reste le rôle réel en base (permissions inchangées) —
        // "Principal(e)" est juste l'étiquette affichée pour un établissement
        // secondaire PRIVÉ (etablissement.statut, migration v4 secondaire),
        // vocabulaire camerounais : Proviseur = public, Principal = privé.
        // Demande explicite du 22/09/2026.
        'ADMIN'      => 'Administrateur',
        'PROVISEUR'  => (function_exists('type_enseignement_courant') && type_enseignement_courant() === 'secondaire'
                          && (get_etablissement()['statut'] ?? 'public') === 'prive')
                         ? 'Principal(e)' : 'Proviseur(e)',
        'CENSEUR'    => 'Censeur(e)',
        'SG'         => 'Surveillant(e) Général(e)',
        'INTENDANT'  => 'Intendant(e)',
        default      => $role,
    };
}

// ── Fonctions assignables à un membre du personnel (pages/enseignants/
//    form.php + save.php — même liste des deux côtés) ─────────────────
// FONDATEUR est TOUJOURS exclu pour un Directeur (attribué uniquement par
// le superadmin association, association/personnel/affecter.php — même
// principe que pages/utilisateurs/liste.php::changer_role) — sauf pour le
// superadmin association LUI-MÊME (visite écriture d'une école), qui doit
// pouvoir l'attribuer directement depuis cet écran. SUPERADMIN/PROPRIETAIRE
// filtrés par précaution : ce sont des concepts association, jamais des
// lignes de la table `fonction` d'une école, mais si l'un y apparaissait un
// jour par erreur, il ne doit jamais être assignable ici. Demande explicite
// du 15/09/2026.
function fonctions_assignables(): array {
    $superadmin = function_exists('est_superadmin_association') && est_superadmin_association();
    $exclues    = $superadmin ? ['SUPERADMIN', 'PROPRIETAIRE'] : ['SUPERADMIN', 'PROPRIETAIRE', 'FONDATEUR'];
    return array_values(array_diff(
        array_column(db_all("SELECT id_fonction FROM fonction ORDER BY id_fonction"), 'id_fonction'),
        $exclues
    ));
}

// ── Matricule élève — école SECONDAIRE (schema_ref_ecole_secondaire.sql,
//    porté de LAM_ABZ) ─────────────────────────────────────────────────
// Format simple <SIGLE><AA><4 chiffres>, ex. « CE260001 » — porté tel quel
// de LAM_ABZ::gen_matricule(). Nom distinct de gen_matricule() (primaire,
// plus haut dans ce fichier — format/périmètre configurables, table
// matricule_config) : deux systèmes de matricule différents, jamais
// interchangeables, une confusion de nom aurait été dangereuse ici.
function gen_matricule_secondaire(): string {
    $etab  = db_one("SELECT sigle FROM etablissement LIMIT 1");
    $sigle = preg_replace('/[^A-Z0-9]/', '', strtoupper($etab['sigle'] ?? 'SEC'));
    $annee = date('y');
    $next  = (int) db_val("SELECT COALESCE(MAX(id), 0) + 1 FROM eleve");
    do {
        $mat    = $sigle . $annee . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
        $existe = db_val("SELECT COUNT(*) FROM eleve WHERE matricule = ?", [$mat]);
        $next++;
    } while ($existe);
    return $mat;
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
//
// PAS de comptage de versements/groupes ici (demande explicite du
// 13/09/2026) : le reçu (voir pdf/recu_lib.php) est un document PAR ÉLÈVE
// qui regroupe TOUS ses versements de l'année — un élève ayant fait
// plusieurs paiements distincts sur la période n'aura jamais qu'UN SEUL
// reçu à l'impression. Le nombre de reçus imprimés = COUNT() du résultat
// de cette fonction, jamais une somme de versements.
function finances_eleves_payes_periode(string $val_annee, string $debut, string $fin, int $id_classe = 0, int $id_eleve = 0): array {
    $where  = ['i.val_annee=?', 'p.date_paiement BETWEEN ? AND ?'];
    $params = [$val_annee, $debut, $fin];
    if ($id_classe) { $where[] = 'i.IDClasses=?'; $params[] = $id_classe; }
    if ($id_eleve)  { $where[] = 'i.id_eleve=?';  $params[] = $id_eleve; }

    return db_all(
        "SELECT i.id_eleve, i.IDClasses, e.Nom_elv, e.Prenom_elv, e.Mat_elv, c.DesignationClasses,
                SUM(p.montant_paiement) AS montant_periode
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

// ── PAIEMENT PRIVÉ (secondaire) ──────────────────────────────
// Port du module Finances/Dépenses du PRIMAIRE (pages/finances/*,
// pages/depenses/*) sous secondaire/pages/paiements_prives/ +
// secondaire/pages/depenses_privees/, comptabilité 100% indépendante de
// PAIEMENT PUBLIQUE (obligation_frais/paiement_frais, porté de LAM_ABZ) —
// tables dédiées obligation_privee/paiement_prive/depense_privee/
// categorie_depense_privee. Demande explicite du 17/09/2026. Pas de concept
// "Cas social" ici (absent du schéma secondaire, hors périmètre) : le
// montant dû n'est jamais réduit, contrairement à finances_du_par_eleve()
// (primaire) ci-dessus. `finances_numero_recu()`/`finances_id_versement()`/
// `finances_numero_recu_eleve()`/`finances_numero_bon()`/
// `finances_modes_paiement()`/`finances_mode_paiement_normalise()`/
// `finances_mode_paiement_badge()`/`finances_mode_paiement_libelle()`
// (ci-dessus) sont génériques (ne lisent aucune table précise) — réutilisées
// telles quelles par le module privé, aucun doublon nécessaire.

// Solde de caisse PRIVÉ (encaissé - dépensé) de l'année scolaire donnée —
// même principe que solde_caisse() (primaire) mais sur paiement_prive/
// depense_privee, id_annee entier (pas val_annee texte).
function prive_solde_caisse(int $id_annee): float {
    $encaisse = (float) (db_val("SELECT COALESCE(SUM(montant_paiement),0) FROM paiement_prive WHERE id_annee=?", [$id_annee]) ?? 0);
    $depense  = (float) (db_val("SELECT COALESCE(SUM(montant),0) FROM depense_privee WHERE id_annee=?", [$id_annee]) ?? 0);
    return $encaisse - $depense;
}

// Liste des élèves actifs inscrits (année/classe/niveau donnés) avec, pour
// chacun, le montant dû PRIVÉ (somme des obligation_privee de son niveau,
// jamais réduit) — source de vérité unique pour état par classe/impayés/
// statistiques/répartition par classe du module privé.
function prive_finances_du_par_eleve(int $id_annee, ?int $id_classe = null, ?string $code_niveau = null): array {
    $where  = ["e.statut='actif'", 'i.id_annee=?'];
    $params = [$id_annee];
    if ($id_classe)  { $where[] = 'c.id=?';           $params[] = $id_classe; }
    if ($code_niveau) { $where[] = 'c.code_niveau=?'; $params[] = $code_niveau; }
    $eleves = db_all(
        "SELECT e.id, c.id AS id_classe, c.code_niveau, c.designation, e.matricule, e.nom, e.prenom
         FROM eleve e
         JOIN inscription i ON i.id_eleve=e.id
         JOIN classe c ON c.id=i.id_classe
         WHERE " . implode(' AND ', $where) . "
         ORDER BY e.nom, e.prenom",
        $params
    );

    $du_par_niveau = [];
    foreach (db_all("SELECT code_niveau, SUM(montant_obligation) AS total FROM obligation_privee GROUP BY code_niveau") as $r) {
        $du_par_niveau[$r['code_niveau']] = (float) $r['total'];
    }
    foreach ($eleves as &$e) {
        $e['du'] = $du_par_niveau[$e['code_niveau']] ?? 0.0;
    }
    unset($e);
    return $eleves;
}

// Élèves ayant effectué au moins un versement PRIVÉ dans [$debut,$fin]
// (bornes incluses, 'Y-m-d') — même principe que finances_eleves_payes_periode()
// (primaire) mais sur paiement_prive, id_annee/id_classe entiers.
function prive_finances_eleves_payes_periode(int $id_annee, string $debut, string $fin, int $id_classe = 0, int $id_eleve = 0): array {
    $where  = ['i.id_annee=?', 'p.date_paiement BETWEEN ? AND ?'];
    $params = [$id_annee, $debut, $fin];
    if ($id_classe) { $where[] = 'i.id_classe=?'; $params[] = $id_classe; }
    if ($id_eleve)  { $where[] = 'i.id_eleve=?';  $params[] = $id_eleve; }

    return db_all(
        "SELECT i.id_eleve, i.id_classe, e.nom, e.prenom, e.matricule, c.designation,
                SUM(p.montant_paiement) AS montant_periode
         FROM inscription i
         JOIN eleve e ON e.id = i.id_eleve
         JOIN classe c ON c.id = i.id_classe
         JOIN paiement_prive p ON p.id_eleve = i.id_eleve AND p.id_annee = i.id_annee
         WHERE " . implode(' AND ', $where) . "
         GROUP BY i.id_eleve, i.id_classe, e.nom, e.prenom, e.matricule, c.designation
         ORDER BY c.designation, e.nom, e.prenom",
        $params
    );
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
// ── Configuration du matricule (par école, migration v52 ; mode
//    'aleatoire' migration v58) ─────────────────────────────────────
//  Table `matricule_config` à ligne unique (id=1). Renvoie des valeurs par
//  défaut si la table/la ligne est absente (base pas encore migrée) : le
//  défaut '{AA}{NIV}{SEQ}' / longueur_seq=3 reproduit EXACTEMENT l'ancien
//  gen_matricule() (« 25P001 »). Trois modes : 'auto' (séquentiel), 'manuel'
//  (saisie libre), 'aleatoire' (même format que 'auto', mais {SEQ} tiré au
//  hasard plutôt qu'incrémenté — configurable par le DIRECTEUR/SECRETAIRE/
//  FONDATEUR de l'école, ou l'association en visite écriture).
function matricule_config(): array {
    static $c = null;
    if ($c === null) {
        $def = ['mode' => 'auto', 'format' => '{AA}{NIV}{SEQ}', 'longueur_seq' => 3, 'sequence_par' => 'annee_niveau'];
        try {
            $row = db_one("SELECT mode, format, longueur_seq, sequence_par FROM matricule_config WHERE id=1");
        } catch (\Throwable $e) { $row = null; }
        $c = $row ? array_merge($def, array_filter($row, fn($v) => $v !== null && $v !== '')) : $def;
        $c['mode']         = in_array($c['mode'], ['manuel', 'aleatoire'], true) ? $c['mode'] : 'auto';
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
    // {SEQ} accepte AU MOINS $lseq chiffres (jamais un nombre exact) : une
    // fois l'espace à $lseq chiffres saturé (ex. 999 élèves sur 3 chiffres),
    // la séquence continue naturellement sur 4 chiffres — sinon MAX() ne
    // verrait plus jamais ces matricules « débordés » et régénérerait sans
    // fin le même candidat déjà pris (bug réel constaté : école à 942/999
    // matricules 3 chiffres utilisés, écriture bloquée en boucle).
    $an_wild  = $cfg['sequence_par'] === 'globale';
    $niv_wild = in_array($cfg['sequence_par'], ['annee', 'globale'], true);
    $regex = '^';
    foreach (preg_split('/(\{AAAA\}|\{AA\}|\{NIV\}|\{SEQ\})/', $cfg['format'], -1, PREG_SPLIT_DELIM_CAPTURE) as $p) {
        $regex .= match ($p) {
            '{AAAA}' => $an_wild ? '[0-9]{4}' : preg_quote($an),
            '{AA}'   => $an_wild ? '[0-9]{2}' : preg_quote($aa),
            '{NIV}'  => $niv_wild ? '[MP]' : $niv,
            '{SEQ}'  => '[0-9]{' . $lseq . ',}',
            default  => preg_quote($p),
        };
    }
    $regex .= '$';

    // Extraction de la séquence : jusqu'à la fin du préfixe SEULEMENT (pas de
    // longueur fixe) — un matricule débordé (4+ chiffres) doit être lu en
    // entier, pas tronqué à $lseq chiffres.
    $max = (int) db_val(
        "SELECT MAX(CAST(SUBSTRING(Mat_elv, ?) AS UNSIGNED)) FROM eleve WHERE Mat_elv REGEXP ?",
        [strlen($prefixe) + 1, $regex]
    );

    // Mode 'aleatoire' : {SEQ} est tiré au hasard dans l'espace à $lseq
    // chiffres (jamais deux matricules consécutifs devinables), avec
    // re-vérification à chaque tentative — même logique anti-collision que
    // le mode 'auto' ci-dessous, juste un tirage au lieu d'un incrément. Si
    // l'espace aléatoire est presque saturé (300 tirages sans succès), on
    // retombe sur le filet séquentiel du mode 'auto' juste en dessous plutôt
    // que de boucler indéfiniment.
    if ($cfg['mode'] === 'aleatoire') {
        $borne = (10 ** $lseq) - 1;
        for ($tentative = 0; $tentative < 300; $tentative++) {
            $n = random_int(0, $borne);
            $candidat = $prefixe . str_pad((string) $n, $lseq, '0', STR_PAD_LEFT) . $suffixe;
            if (!db_val("SELECT COUNT(*) FROM eleve WHERE Mat_elv=?", [$candidat])) {
                return $candidat;
            }
        }
    }

    // Incrément séquentiel avec re-vérification À CHAQUE tentative (jamais un
    // repli aléatoire à l'aveugle : dans un espace presque saturé, un tirage
    // aléatoire unique a de fortes chances de retomber sur un matricule déjà
    // pris, et l'INSERT échoue alors sans filet — plus aucun élève ne peut
    // être créé tant que le hasard ne tombe pas juste). Le pas au-delà de
    // $lseq chiffres n'est plus re-formaté à largeur fixe (str_pad ne
    // tronque pas — 1000 reste "1000", pas "000").
    for ($tentative = 0; $tentative < 200; $tentative++) {
        $n = $max + 1 + $tentative;
        $candidat = $prefixe . str_pad((string) $n, $lseq, '0', STR_PAD_LEFT) . $suffixe;
        if (!db_val("SELECT COUNT(*) FROM eleve WHERE Mat_elv=?", [$candidat])) {
            return $candidat;
        }
    }
    // Improbable après 200 tentatives consécutives : dernier repli, mais
    // toujours vérifié plutôt que renvoyé aveuglément.
    do {
        $matricule = $prefixe . ($max + 1 + random_int(1, 999999)) . $suffixe;
    } while (db_val("SELECT COUNT(*) FROM eleve WHERE Mat_elv=?", [$matricule]));
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
    // École secondaire : colonnes déjà nommées comme la sortie attendue
    // (region_fr/departement_fr/arrondissement_fr/nom_fr...) — schéma
    // secondaire (schema_ref_ecole_secondaire.sql), pas de "lieu" séparé
    // (repli sur ville) ni de titre de direction bilingue distinct. Ajouté
    // pour le module PAIEMENT PRIVÉ (secondaire/pdf/prive_*.php, demande
    // explicite du 17/09/2026) — réutilise les mêmes fonctions de rendu
    // génériques (pdf_entete()/pdf_bandeau()/pdf_copyright(), pdf/header_pdf.php)
    // que le module Finances du primaire, aucun doublon de dessin nécessaire.
    if (function_exists('type_enseignement_courant') && type_enseignement_courant() === 'secondaire') {
        return [
            'nom_fr' => $etab['nom_fr'] ?? '', 'nom_en' => $etab['nom_en'] ?? '', 'sigle' => $etab['sigle'] ?? '',
            'immatriculation' => $etab['immatriculation'] ?? '', 'boite_postale' => $etab['boite_postale'] ?? '',
            'telephone' => $etab['telephone'] ?? '', 'email' => $etab['email'] ?? '', 'ville' => $etab['ville'] ?? '',
            'lieu' => $etab['ville'] ?? '',
            'region_fr' => $etab['region_fr'] ?? '', 'departement_fr' => $etab['departement_fr'] ?? '',
            'arrondissement_fr' => $etab['arrondissement_fr'] ?? '',
            'region_en' => $etab['region_en'] ?? '', 'division_en' => $etab['division_en'] ?? '',
            'subdivision_en' => $etab['subdivision_en'] ?? '',
            'chef_etablissement' => $etab['chef_etablissement'] ?? '', 'chef_etablissement_en' => $etab['chef_etablissement_en'] ?? '',
            'logo' => $etab['logo'] ?? '',
        ];
    }
    return [
        'nom_fr'            => $etab['Nom_Etab_Fr'] ?? '',
        'nom_en'            => $etab['Nom_Etab_An'] ?? '',
        'sigle'             => $etab['Initial_Etab'] ?? '',
        'immatriculation'   => $etab['Immatriculation_Etab'] ?? '',
        'boite_postale'     => $etab['boite_postal'] ?? '',
        'telephone'         => $etab['tel_etab'] ?? '',
        'email'             => $etab['email_etab'] ?? '',
        'ville'             => $etab['ville_etab'] ?? '',
        // Localité de signature des documents ("Fait à ..., le ...") : la
        // VILLE de l'établissement en priorité (demande du 01/10/2026 —
        // « Fait à Général » apparaissait avec le lieu-dit), le lieu-dit
        // (etab.lieu_etab, ex. « Bamyanga ») seulement si la ville est vide.
        'lieu'              => trim((string) ($etab['ville_etab'] ?? '')) ?: ($etab['lieu_etab'] ?? ''),
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

/**
 * Titre du chef d'établissement pour les documents du personnel, en
 * [français, anglais] : celui saisi dans les paramètres de l'école
 * (fonction_dirigeant_* au primaire, chef_etablissement* au secondaire),
 * sinon « Le Directeur / The Director » (primaire) ou « Le Proviseur /
 * The Principal » (secondaire).  = sortie de etab_pour_pdf().
 */
function titres_chef_pdf(array $etab): array {
    $sec = function_exists('type_enseignement_courant') && type_enseignement_courant() === 'secondaire';
    $casse = fn(string $t): string => mb_convert_case(mb_strtolower(trim($t)), MB_CASE_TITLE);
    $fr = trim((string) ($etab['chef_etablissement'] ?? ''));
    $en = trim((string) ($etab['chef_etablissement_en'] ?? ''));
    return [$fr !== '' ? $casse($fr) : ($sec ? 'Le Proviseur' : 'Le Directeur'),
            $en !== '' ? $casse($en) : ($sec ? 'The Principal' : 'The Director')];
}

/** « Ngaoundéré, » (ville de l'école, sinon lieu-dit) ou '' si aucun. */
function lieu_signature_pdf(array $etab): string {
    $l = trim((string) (($etab['ville'] ?? '') ?: ($etab['lieu'] ?? '')));
    return $l !== '' ? $l . ', ' : '';
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

/**
 * Photo recadrée reçue par POST : de préférence comme vrai FICHIER
 * (multipart, champ $champ_fichier — Blob JPEG envoyé par le navigateur),
 * sinon l'ancien champ texte base64 $champ_b64. Le texte base64 (plusieurs
 * centaines de Ko de « data:image/jpeg;base64,… ») est bloqué en 403 par
 * le pare-feu ModSecurity de certains hébergeurs mutualisés (Camoo,
 * constaté le 02/10/2026) alors qu'un fichier passe. Binaire ou null.
 */
function photo_postee(string $champ_fichier = 'photo_fichier', string $champ_b64 = 'photo_b64'): ?string {
    $f = $_FILES[$champ_fichier] ?? null;
    if ($f && ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file($f['tmp_name'])) {
        if ($f['size'] <= 0 || $f['size'] > 2 * 1024 * 1024) return null;
        $data = file_get_contents($f['tmp_name']);
        return $data !== false && $data !== '' ? $data : null;
    }
    return decoder_photo_b64($_POST[$champ_b64] ?? null);
}

/**
 * Journalise un mouvement de classe d'un élève (table mouvement_classe,
 * migration v60) : 'inscription' (première classe de l'année),
 * 'changement' (passage d'une classe à une autre en cours d'année),
 * 'retrait' (désinscription de l'année). Jamais bloquant : base pas encore
 * migrée -> rien n'est enregistré, l'inscription elle-même se fait quand même.
 */
function journaliser_mouvement_classe(int $id_eleve, string $val_annee, ?int $avant, ?int $apres, string $type): void {
    if ($avant !== null && $avant === $apres) return;   // pas de mouvement réel
    try {
        db_exec("INSERT INTO mouvement_classe (id_eleve, val_annee, classe_avant, classe_apres, type_mvt, date_mvt, auteur)
                 VALUES (?, ?, ?, ?, ?, NOW(), ?)",
                [$id_eleve, $val_annee, $avant, $apres, $type, $_SESSION['user']['login'] ?? null]);
    } catch (\Throwable $e) {
        // table absente (migration v60 non appliquée) : historique non tenu
    }
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

// ══════════════════════════════════════════════════════════════════
// Moteur de calcul secondaire (moyennes/mentions/résultats/signatures/
// paiements/notifications/demandes) — porté depuis LAM_ABZ/fonctions.php
// le 16/09/2026 (étape 10, vérification module par module de la copie en
// masse). Fonctions purement secondaire, aucun branchement primaire/
// secondaire nécessaire (LAM_ABZ EST déjà le schéma secondaire).
// ══════════════════════════════════════════════════════════════════
// ==== eleve_solde_obligation ====
// Solde restant dû pour un élève sur une obligation donnée, pour une année.
function eleve_solde_obligation(int $id_eleve, int $id_obligation, int $id_annee): float {
    $montant = (float) db_val("SELECT montant FROM obligation_frais WHERE id = ?", [$id_obligation]);
    $paye    = (float) db_val(
        "SELECT COALESCE(SUM(montant),0) FROM paiement_frais WHERE id_eleve=? AND id_obligation=? AND id_annee=?",
        [$id_eleve, $id_obligation, $id_annee]
    );
    return round($montant - $paye, 2);
}

// ==== eleve_obligations_annee ====
// cycle de son niveau, ou son niveau précis), avec solde calculé par ligne.
function eleve_obligations_annee(int $id_eleve, string $code_niveau, int $id_annee): array {
    $id_cycle = db_val("SELECT id_cycle FROM niveau WHERE code_niveau = ?", [$code_niveau]);
    $obligations = db_all(
        "SELECT * FROM obligation_frais
         WHERE id_annee=? AND actif=1
           AND (portee='etablissement' OR (portee='cycle' AND id_cycle=?) OR (portee='niveau' AND code_niveau=?))
         ORDER BY libelle",
        [$id_annee, $id_cycle, $code_niveau]
    );
    foreach ($obligations as &$o) {
        $paye = (float) db_val(
            "SELECT COALESCE(SUM(montant),0) FROM paiement_frais WHERE id_eleve=? AND id_obligation=? AND id_annee=?",
            [$id_eleve, $o['id'], $id_annee]
        );
        $o['paye']  = $paye;
        $o['solde'] = round((float)$o['montant'] - $paye, 2);
    }
    unset($o);
    return $obligations;
}

// ==== generer_numero_recu ====
// (jamais recalculé à l'impression — voir prompt_continuite pour le bug MANWI évité).
function generer_numero_recu(int $id_annee): string {
    $libelle = db_val("SELECT libelle FROM annee_scolaire WHERE id = ?", [$id_annee]) ?: date('Y');
    preg_match('/(\d{4})\D*(\d{4})?$/', (string)$libelle, $m);
    $aa   = substr($m[2] ?? ($m[1] ?? date('Y')), -2);
    $next = (int) db_val("SELECT COUNT(*) + 1 FROM paiement_frais WHERE id_annee = ?", [$id_annee]);
    do {
        $numero = str_pad((string)$next, 4, '0', STR_PAD_LEFT) . '/' . $aa;
        $existe = db_val("SELECT COUNT(*) FROM paiement_frais WHERE numero_recu = ?", [$numero]);
        $next++;
    } while ($existe);
    return $numero;
}

// ==== get_reglage_paiement ====
// get_reglage_mention_bulletin() (jamais de casse avant configuration).
function get_reglage_paiement(int $id_annee): array {
    $defaut = [
        'montant_frais_operateur' => 200.0,
        'couleur_fond_1' => '#FFF6C8', 'couleur_fond_2' => '#FFCDD2', 'couleur_fond_3' => '#CDE8CD',
    ];
    $r = db_one("SELECT * FROM reglage_paiement WHERE id_annee = ?", [$id_annee]);
    return $r ? array_merge($defaut, array_intersect_key($r, $defaut)) : $defaut;
}

// ==== xl_safe ====
// Neutralise l'injection de formule Excel/CSV (OWASP) sur une cellule
// PhpSpreadsheet dont le contenu vient d'un champ texte libre saisi par un
// utilisateur (observation, motif, raison, nom, adresse, etc.) : si la chaîne
// commence par =, +, -, @ (ou tabulation/retour chariot), Excel/LibreOffice
// peut l'interpréter comme une formule à l'ouverture chez un autre membre du
// personnel. Préfixée d'une apostrophe, elle s'affiche comme texte brut, sans
// rien changer visuellement (l'apostrophe n'apparaît pas dans la cellule).
// Ne touche jamais les valeurs non-chaîne (int/float/null/RichText/objets) —
// utilisée uniquement sur les valeurs qui PEUVENT être du texte libre, jamais
// sur des libellés fixes écrits en dur dans le code (jamais dangereux).
function xl_safe($valeur) {
    if (!is_string($valeur) || $valeur === '') return $valeur;
    return preg_match('/^[=+\-@\t\r]/', $valeur) ? ("'" . $valeur) : $valeur;
}

// ==== hex_vers_rgb ====
// valeur stockée est invalide (ne casse jamais l'affichage du reçu).
function hex_vers_rgb(string $hex): array {
    $hex = ltrim($hex, '#');
    if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) return [255, 255, 255];
    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
}

// ==== nombre_en_lettres_fcfa ====
// dizaines irrégulières (soixante-dix, quatre-vingt(s), quatre-vingt-dix).
function nombre_en_lettres_fcfa(float $montant): string {
    $n = (int) round($montant);
    if ($n === 0) return 'zéro';
    $negatif = $n < 0;
    $n = abs($n);

    $unites = ['', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf',
               'dix', 'onze', 'douze', 'treize', 'quatorze', 'quinze', 'seize',
               'dix-sept', 'dix-huit', 'dix-neuf'];
    $dizaines = ['', '', 'vingt', 'trente', 'quarante', 'cinquante', 'soixante', '', 'quatre-vingt', ''];

    // 0-99
    $lettres_deux_chiffres = function (int $x) use ($unites, $dizaines): string {
        if ($x < 20) return $unites[$x];
        $d = intdiv($x, 10);
        $u = $x % 10;
        if ($d === 7 || $d === 9) { // soixante-dix / quatre-vingt-dix (60-79, 90-99 basés sur 60/80 + 10-19)
            $base = $d === 7 ? 'soixante' : 'quatre-vingt';
            return $u === 0 ? $base . '-dix' : $base . '-' . $unites[10 + $u];
        }
        if ($u === 0) return $dizaines[$d] . ($d === 8 ? 's' : '');
        if ($u === 1 && $d !== 8) return $dizaines[$d] . '-et-un';
        return $dizaines[$d] . '-' . $unites[$u];
    };

    // 0-999 — "cent" reste ici toujours invariable (jamais "deux cents"),
    // conforme à l'orthographe déjà utilisée par les documents MANWI repris
    // à l'identique (ex. "7700" → "sept mille sept cent", pas "...cents").
    $lettres_trois_chiffres = function (int $x) use ($lettres_deux_chiffres): string {
        $c = intdiv($x, 100);
        $reste = $x % 100;
        if ($c === 0) return $lettres_deux_chiffres($reste);
        $mot_cent = $c === 1 ? 'cent' : $lettres_deux_chiffres($c) . ' cent';
        return $reste === 0 ? $mot_cent : $mot_cent . ' ' . $lettres_deux_chiffres($reste);
    };

    $groupes = [
        [1_000_000_000, 'milliard'],
        [1_000_000,     'million'],
        [1_000,         'mille'],
    ];

    $parties = [];
    foreach ($groupes as [$valeur, $mot]) {
        $q = intdiv($n, $valeur);
        $n %= $valeur;
        if ($q === 0) continue;
        if ($mot === 'mille') {
            $parties[] = $q === 1 ? 'mille' : $lettres_trois_chiffres($q) . ' mille';
        } else {
            $parties[] = ($q === 1 ? 'un' : $lettres_trois_chiffres($q)) . ' ' . $mot . ($q > 1 ? 's' : '');
        }
    }
    if ($n > 0 || !$parties) $parties[] = $lettres_trois_chiffres($n);

    return ucfirst(trim(($negatif ? 'moins ' : '') . implode(' ', $parties)));
}

// ==== get_reglage_mention_bulletin ====
// pour l'année, pour que le bulletin ne casse jamais avant paramétrage.
function get_reglage_mention_bulletin(int $id_annee): array {
    $defaut = [
        'moy_tableau_honneur'        => 12.0,
        'heures_max_tableau_honneur' => 8,
        'moy_encouragement'          => 14.0,
        'moy_felicitation'           => 15.0,
        'moy_avert_travail_min'      => 5.0,
        'moy_avert_travail_max'      => 7.3,
        'moy_blame_travail_max'      => 5.0,
        'heures_avert_conduite_min'  => 5,
        'heures_avert_conduite_max'  => 10,
        'heures_blame_conduite_min'  => 10,
    ];
    $r = db_one("SELECT * FROM reglage_mention_bulletin WHERE id_annee = ?", [$id_annee]);
    return $r ? array_merge($defaut, array_intersect_key($r, $defaut)) : $defaut;
}

// ==== bulletin_tableau_honneur ====
function bulletin_tableau_honneur(float $moy, float $heur, array $r): string {
    if ($moy >= $r['moy_tableau_honneur'] && $heur <= $r['heures_max_tableau_honneur']) return 'Oui';
    if ($moy >= $r['moy_tableau_honneur']) return 'Refuse';
    return '---';
}

// ==== bulletin_encouragement ====
function bulletin_encouragement(float $moy, float $heur, array $r): string {
    return (bulletin_tableau_honneur($moy, $heur, $r) === 'Oui' && $moy >= $r['moy_encouragement']) ? 'Oui' : '---';
}

// ==== bulletin_felicitation ====
function bulletin_felicitation(float $moy, float $heur, array $r): string {
    return (bulletin_tableau_honneur($moy, $heur, $r) === 'Oui' && $moy >= $r['moy_felicitation']) ? 'Oui' : '---';
}

// ==== bulletin_avert_travail ====
function bulletin_avert_travail(float $moy, array $r): string {
    return ($moy >= $r['moy_avert_travail_min'] && $moy <= $r['moy_avert_travail_max']) ? 'Oui' : '---';
}

// ==== bulletin_blame_travail ====
function bulletin_blame_travail(float $moy, array $r): string {
    return ($moy < $r['moy_blame_travail_max']) ? 'Oui' : '---';
}

// ==== bulletin_avert_conduite ====
function bulletin_avert_conduite(float $heur, array $r): string {
    return ($heur >= $r['heures_avert_conduite_min'] && $heur < $r['heures_avert_conduite_max']) ? 'OUI' : '---';
}

// ==== bulletin_blame_conduite ====
function bulletin_blame_conduite(float $heur, array $r): string {
    return ($heur >= $r['heures_blame_conduite_min']) ? 'OUI' : '---';
}

// ==== eleve_absence_trimestre ====
// donné, lues dans la table `absence` (alimentée par pages/discipline/index.php).
function eleve_absence_trimestre(string $matricule, int $id_trim, int $id_classe, string $val_annee): array {
    $r = db_one(
        "SELECT SUM(nbre_heure_jus) AS jus, SUM(nbre_heure_non_jus) AS non_jus
         FROM absence WHERE mat_elv=? AND id_trim=? AND IDClasses=? AND val_annee=?",
        [$matricule, $id_trim, $id_classe, $val_annee]
    );
    $jus     = (float)($r['jus']     ?? 0);
    $non_jus = (float)($r['non_jus'] ?? 0);
    return ['jus' => $jus, 'non_jus' => $non_jus, 'total' => $jus + $non_jus];
}

// ==== eleve_absence_annuelle ====
// pour un futur bulletin/rapport annuel — non branché ailleurs pour l'instant).
function eleve_absence_annuelle(string $matricule, int $id_classe, int $id_annee): array {
    $val_annee  = (string) db_val("SELECT libelle FROM annee_scolaire WHERE id = ?", [$id_annee]);
    $trimestres = db_all("SELECT id FROM trimestre WHERE id_annee = ? ORDER BY ordre", [$id_annee]);
    $par_trim = []; $tot_jus = 0; $tot_non_jus = 0;
    foreach ($trimestres as $t) {
        $a = eleve_absence_trimestre($matricule, (int)$t['id'], $id_classe, $val_annee);
        $par_trim[(int)$t['id']] = $a;
        $tot_jus += $a['jus']; $tot_non_jus += $a['non_jus'];
    }
    return ['par_trimestre' => $par_trim, 'jus' => $tot_jus, 'non_jus' => $tot_non_jus, 'total' => $tot_jus + $tot_non_jus];
}

// ==== sauver_photo ====
// Retourne le nom du fichier ou null
function sauver_photo(?string $photo_b64): ?string {
    // Base64 (depuis Cropper.js)
    if (!empty($photo_b64) && str_starts_with($photo_b64, 'data:image/')) {
        if (preg_match('/data:image\/(\w+);base64,(.+)/s', $photo_b64, $m)) {
            $ext  = strtolower($m[1]) === 'png' ? 'png' : 'jpg';
            $data = base64_decode($m[2]);
            if ($data && strlen($data) <= 2 * 1024 * 1024) {
                $nom = 'elv_' . bin2hex(random_bytes(8)) . '.' . $ext;
                file_put_contents(UPLOAD_DIR . $nom, $data);
                return $nom;
            }
        }
        return null;
    }
    // Upload classique
    if (!empty($_FILES['photo']['tmp_name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $ext_ok = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
        $ext    = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        $fi     = finfo_open(FILEINFO_MIME_TYPE);
        $mime   = finfo_file($fi, $_FILES['photo']['tmp_name']);
        finfo_close($fi);
        if (isset($ext_ok[$ext]) && $ext_ok[$ext] === $mime && $_FILES['photo']['size'] <= 2 * 1024 * 1024) {
            $nom = 'elv_' . bin2hex(random_bytes(8)) . '.' . $ext;
            move_uploaded_file($_FILES['photo']['tmp_name'], UPLOAD_DIR . $nom);
            return $nom;
        }
    }
    return null;
}

// ==== get_signature_titulaires ====
// (INTENDANT) — voir pdf/header_pdf.php::pdf_signature_appliquer().
function get_signature_titulaires(): array {
    $rows = db_all("SELECT * FROM signature_titulaire");
    $out = [];
    foreach ($rows as $r) $out[$r['code']] = $r;
    return $out;
}

// ==== signature_chemin ====
function signature_chemin(string $code): ?string {
    $fichier = db_val("SELECT fichier FROM signature_titulaire WHERE code=?", [$code]);
    if (empty($fichier)) return null;
    $chemin = __DIR__ . '/assets/uploads/' . $fichier;
    return is_file($chemin) ? $chemin : null;
}

// ==== signature_configuree ====
function signature_configuree(string $code): bool {
    return signature_chemin($code) !== null;
}

// ==== signature_role_autorisee ====
// role_gestion (superviseur de dernier recours).
function signature_role_autorisee(string $code, string $role): bool {
    if ($role === 'ADMIN') return true;
    return (get_signature_titulaires()[$code]['role_gestion'] ?? null) === $role;
}

// ==== signature_chemin_enseignant ====
// signature, pas un rôle fixe unique).
function signature_chemin_enseignant(string $matricule_ens): ?string {
    $fichier = db_val("SELECT signature FROM enseignant WHERE matricule_ens=?", [$matricule_ens]);
    if (empty($fichier)) return null;
    $chemin = __DIR__ . '/assets/uploads/' . $fichier;
    return is_file($chemin) ? $chemin : null;
}

// ==== signature_chemin_pp_classe ====
// convention que enseignat_principal.val_annee ailleurs dans le projet).
function signature_chemin_pp_classe(int $id_classe, string $val_annee): ?string {
    $mat = db_val("SELECT matricule_ens FROM enseignat_principal WHERE IDClasses=? AND val_annee=?", [$id_classe, $val_annee]);
    return $mat ? signature_chemin_enseignant($mat) : null;
}

// ==== signature_traiter_transparence ====
// et rendu opaque, d'où un fond noir au lieu de transparent à l'affichage.
function signature_traiter_transparence(string $chemin_source, string $chemin_dest, int $seuil_haut = 245, int $seuil_bas = 200): bool {
    $src = @imagecreatefromstring(file_get_contents($chemin_source));
    if (!$src) return false;
    imagealphablending($src, false);
    imagesavealpha($src, true);
    $w = imagesx($src); $h = imagesy($src);
    $dst = imagecreatetruecolor($w, $h);
    imagesavealpha($dst, true);
    imagealphablending($dst, false);
    $vide = imagecolorallocatealpha($dst, 255, 255, 255, 127);
    imagefill($dst, 0, 0, $vide);
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $rgba = imagecolorat($src, $x, $y);
            $alpha_src = ($rgba >> 24) & 0x7F;
            $r = ($rgba >> 16) & 0xFF; $g = ($rgba >> 8) & 0xFF; $b = $rgba & 0xFF;
            $luminosite = ($r + $g + $b) / 3;
            if ($luminosite >= $seuil_haut) {
                $alpha_blanc = 127;
            } elseif ($luminosite <= $seuil_bas) {
                $alpha_blanc = 0;
            } else {
                $alpha_blanc = (int) round(127 * ($luminosite - $seuil_bas) / ($seuil_haut - $seuil_bas));
            }
            $alpha = max($alpha_src, $alpha_blanc);
            imagesetpixel($dst, $x, $y, imagecolorallocatealpha($dst, $r, $g, $b, $alpha));
        }
    }
    // PNG_FILTER_NONE (pas de filtrage adaptatif par ligne) : le lecteur PNG
    // de pdf/fpdf.php (bibliothèque à ne pas modifier) ne décode pas les
    // filtres de scanline PNG (Sub/Up/Average/Paeth) — avec le filtrage
    // adaptatif par défaut de GD, la majorité des lignes d'une image
    // détaillée (signature scannée) sont filtrées autrement que "None" et le
    // canal alpha/couleur ressort corrompu (fond noir au lieu de transparent).
    // Forcer "None" partout élimine le problème à la source, fichier plus
    // gros mais toujours largement raisonnable pour une signature.
    $ok = imagepng($dst, $chemin_dest, -1, PNG_FILTER_NONE);
    imagedestroy($src); imagedestroy($dst);
    return $ok;
}

// ==== get_signature_position ====
// n'a encore été enregistrée pour ce document précis.
function get_signature_position(string $type_document, string $code, array $defaut): array {
    $pos = db_one(
        "SELECT x_pct, y_pct, w_pct, h_pct FROM signature_position WHERE type_document=? AND code_signature=?",
        [$type_document, $code]
    );
    return $pos ?? $defaut;
}

// ==== notifier ====
/** Crée une notification pour un utilisateur donné. */
function notifier(int $id_utilisateur, string $message, string $lien = ''): void {
    if (!$id_utilisateur) return;
    db_exec("INSERT INTO notification (id_utilisateur, message, lien) VALUES (?,?,?)",
            [$id_utilisateur, $message, $lien ?: null]);
}

// ==== notifier_role ====
/** Crée la même notification pour tous les utilisateurs actifs d'un rôle donné. */
function notifier_role(string $role, string $message, string $lien = ''): void {
    $users = db_all("SELECT id FROM utilisateur WHERE role=? AND actif=1", [$role]);
    foreach ($users as $u) {
        notifier((int)$u['id'], $message, $lien);
    }
}

// ==== notifications_non_lues_count ====
/** Nombre de notifications non lues pour un utilisateur. */
function notifications_non_lues_count(int $id_utilisateur): int {
    if (!$id_utilisateur) return 0;
    return (int)db_val("SELECT COUNT(*) FROM notification WHERE id_utilisateur=? AND lue=0", [$id_utilisateur]);
}

// ==== notifications_utilisateur ====
/** Dernières notifications d'un utilisateur (les plus récentes en premier). */
function notifications_utilisateur(int $id_utilisateur, int $limite = 8): array {
    if (!$id_utilisateur) return [];
    $limite = max(1, min(50, $limite));
    return db_all("SELECT * FROM notification WHERE id_utilisateur=? ORDER BY date_creation DESC LIMIT $limite",
                  [$id_utilisateur]);
}

// ==== notifications_tout_marquer_lu ====
/** Marque toutes les notifications d'un utilisateur comme lues. */
function notifications_tout_marquer_lu(int $id_utilisateur): void {
    if (!$id_utilisateur) return;
    db_exec("UPDATE notification SET lue=1 WHERE id_utilisateur=?", [$id_utilisateur]);
}

// ==== libelle_type_demande ====
/** Libellé lisible d'un type de document de demande. */
function libelle_type_demande(string $type): string {
    return match ($type) {
        'attestation' => 'Attestation de présence effective',
        'prise'       => 'Certificat de prise de service',
        'reprise'     => 'Certificat de reprise de service',
        default       => $type,
    };
}

// ==== libelle_statut_demande ====
/** [libellé, classe badge Bootstrap, icône Bootstrap Icons] pour un statut de demande. */
function libelle_statut_demande(string $statut): array {
    return match ($statut) {
        'en_attente_censeur'   => ['En attente du Censeur',   'warning',  'hourglass-split'],
        'en_attente_proviseur' => ['En attente du Proviseur', 'info',     'hourglass-split'],
        'rejetee_censeur'      => ['Rejetée par le Censeur',  'danger',   'x-circle'],
        'rejetee_proviseur'    => ['Rejetée par le Proviseur','danger',   'x-circle'],
        'validee'               => ['Validée — disponible',   'success',  'check-circle'],
        default                 => [$statut, 'secondary', 'question-circle'],
    };
}

// ==== demande_validee_existe ====
/**
 * Vérifie qu'une demande de document VALIDÉE existe pour cet enseignant et ce type.
 * Utilisé pour n'autoriser un enseignant à consulter/imprimer que les documents
 * effectivement validés par le circuit Censeur → Proviseur.
 */
function demande_validee_existe(string $mat, string $type_document): bool {
    if (!$mat) return false;
    return (bool)db_val(
        "SELECT COUNT(*) FROM demande_document WHERE matricule_ens=? AND type_document=? AND statut='validee'",
        [$mat, $type_document]
    );
}

// ==== identite_ens_manquants ====
function identite_ens_manquants(array $e): array {
    $requis = [
        'civilite_ens' => 'Civilité', 'sexe_ens' => 'Sexe',
        'date_naiss' => 'Date de naissance', 'lieu_naiss' => 'Lieu de naissance',
        'region_origine' => "Région d'origine", 'departement_origine' => 'Département d\'origine',
        'tel_ens' => 'Téléphone',
    ];
    $manquants = [];
    foreach ($requis as $champ => $label) {
        if (empty($e[$champ])) $manquants[] = $label;
    }
    return $manquants;
}

// ==== pv_note_effective ====
/**
 * Note "effective" d'un élève dans une matière pour une séquence donnée :
 * - note saisie -> [valeur, false]
 * - absence justifiée -> [null, true]  (exclue du calcul de moyenne)
 * - absent non justifié (assez d'élèves notés pour le déduire) -> [0.0, false]
 * - sinon (donnée manquante) -> [null, false]
 */
function pv_note_effective(int $eid, int $id_mat, int $sid, array $notes_idx, array $abs_just, array $notes_count, int $nb_inscrits): array {
    if (isset($notes_idx[$eid][$id_mat][$sid])) return [$notes_idx[$eid][$id_mat][$sid], false];
    if (isset($abs_just[$eid][$id_mat][$sid])) return [null, true];
    $cnt = $notes_count[$id_mat][$sid] ?? 0;
    if ($nb_inscrits > 0 && $cnt >= ceil($nb_inscrits / 2)) return [0.0, false];
    return [null, false];
}

// ==== pv_moy_matiere ====
/** Moyenne d'un élève dans une matière, sur un ensemble de séquences donné (trimestre ou année). */
function pv_moy_matiere(int $eid, int $id_mat, array $seq_ids, array $notes_idx, array $abs_just, array $notes_count, int $nb_inscrits): ?float {
    $tot = 0; $cnt = 0;
    foreach ($seq_ids as $sid) {
        [$v, $exclu] = pv_note_effective($eid, $id_mat, $sid, $notes_idx, $abs_just, $notes_count, $nb_inscrits);
        if ($exclu) continue;
        if ($v !== null) { $tot += $v; $cnt++; }
    }
    return $cnt > 0 ? $tot / $cnt : null;
}

// ==== pv_moy_generale ====
/**
 * Moyenne générale pondérée par coefficient d'un élève, sur un ensemble de
 * séquences (trimestre ou année complète).
 * @return array [moyenne|null, classable(bool), coefficient_total]
 */
function pv_moy_generale(int $eid, array $disciplines, array $seq_ids, array $notes_idx, array $abs_just, array $notes_count, int $nb_inscrits, array $mats_avec_notes, int $nb_mats_avec_notes): array {
    $tot = 0; $coef = 0; $nb_data = 0;
    foreach ($disciplines as $d) {
        if (!in_array($d['id_mat'], $mats_avec_notes)) continue;
        $avg = pv_moy_matiere($eid, $d['id_mat'], $seq_ids, $notes_idx, $abs_just, $notes_count, $nb_inscrits);
        if ($avg !== null) { $tot += $avg * $d['coef']; $coef += $d['coef']; $nb_data++; }
    }
    $moy = $coef > 0 ? $tot / $coef : null;
    $classable = $nb_mats_avec_notes > 0 && $nb_data >= ceil($nb_mats_avec_notes / 2);
    return [$moy, $classable, $coef];
}

// ==== pv_tableau_honneur ====
/** Mentions/appréciations du conseil, à partir de la moyenne et des heures d'absence non justifiées. */
function pv_tableau_honneur(?float $moy, int $abs_nj): string {
    if ($moy === null) return '—';
    if ($moy >= 12 && $abs_nj <= 8) return 'Oui';
    if ($moy >= 12 && $abs_nj > 8)  return 'Refusé (absences)';
    return '—';
}

// ==== pv_encouragement ====
function pv_encouragement(?float $moy, int $abs_nj): string {
    return (pv_tableau_honneur($moy, $abs_nj) === 'Oui' && $moy >= 14) ? 'Oui' : '—';
}

// ==== pv_felicitation ====
function pv_felicitation(?float $moy, int $abs_nj): string {
    return (pv_tableau_honneur($moy, $abs_nj) === 'Oui' && $moy >= 15) ? 'Oui' : '—';
}

// ==== pv_avertissement_travail ====
function pv_avertissement_travail(?float $moy): string {
    return ($moy !== null && $moy >= 5 && $moy <= 7.30) ? 'Oui' : '—';
}

// ==== pv_blame_travail ====
function pv_blame_travail(?float $moy): string {
    return ($moy !== null && $moy < 5) ? 'Oui' : '—';
}

// ==== pv_mention_trimestre ====
/** Mention synthétique unique à afficher pour un trimestre (la plus "forte" applicable). */
function pv_mention_trimestre(?float $moy, int $abs_nj): string {
    if (pv_felicitation($moy, $abs_nj) === 'Oui')   return 'Félicitations';
    if (pv_encouragement($moy, $abs_nj) === 'Oui')  return 'Encouragements';
    if (pv_tableau_honneur($moy, $abs_nj) === 'Oui') return "Tableau d'honneur";
    if (pv_blame_travail($moy) === 'Oui')            return 'Blâme (travail)';
    if (pv_avertissement_travail($moy) === 'Oui')    return 'Avertissement (travail)';
    return 'RAS';
}

// ==== pv_annules_trimestre ====
/**
 * Trimestres annulés pour une classe donnée — Règle 4 : un élève dont le
 * trimestre est annulé n'est JAMAIS concerné par le zéro automatique
 * (Règle 1), n'est JAMAIS "non classé" (Règle 2) et n'a AUCUNE moyenne
 * calculée pour ce trimestre (exclu du diviseur annuel, Règle 3). Source de
 * vérité unique : la table `trimestre_annulation` (aucun état mis en cache
 * en mémoire entre deux appels — chaque calcul relit la base) ; annuler_
 * trimestre_eleves()/retablir_trimestre_eleves() écrivent directement dans
 * cette même table, donc un recalcul juste après une (dés)annulation, même
 * dans la même requête, voit toujours l'état à jour.
 * @return array eid => true
 */
function pv_annules_trimestre(int $id_classe, int $id_annee, int $id_trim): array {
    if (!$id_trim) return [];
    $rows = db_all(
        "SELECT ta.id_eleve FROM trimestre_annulation ta
         JOIN inscription i ON i.id_eleve=ta.id_eleve AND i.id_classe=? AND i.id_annee=?
         WHERE ta.id_trim=?",
        [$id_classe, $id_annee, $id_trim]
    );
    $out = [];
    foreach ($rows as $r) { $out[(int)$r['id_eleve']] = true; }
    return $out;
}

// ==== annuler_trimestre_eleves ====
/** Annule le trimestre $id_trim pour les élèves $eleve_ids (motif optionnel,
 *  traçabilité qui/quand via id_utilisateur+date_creation). Idempotent —
 *  un élève déjà annulé voit juste son motif/auteur/date mis à jour. */
function annuler_trimestre_eleves(array $eleve_ids, int $id_trim, ?string $motif, int $id_utilisateur): int {
    $n = 0;
    foreach ($eleve_ids as $eid) {
        db_exec(
            "INSERT INTO trimestre_annulation (id_eleve, id_trim, motif, id_utilisateur, date_creation)
             VALUES (?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE motif=VALUES(motif), id_utilisateur=VALUES(id_utilisateur), date_creation=VALUES(date_creation)",
            [(int)$eid, $id_trim, $motif !== '' ? $motif : null, $id_utilisateur ?: null]
        );
        $n++;
    }
    return $n;
}

// ==== retablir_trimestre_eleves ====
/** Rétablit (supprime l'annulation de) $id_trim pour les élèves $eleve_ids. */
function retablir_trimestre_eleves(array $eleve_ids, int $id_trim): int {
    if (empty($eleve_ids)) return 0;
    $in = implode(',', array_fill(0, count($eleve_ids), '?'));
    db_exec("DELETE FROM trimestre_annulation WHERE id_trim=? AND id_eleve IN ($in)", array_merge([$id_trim], array_map('intval', $eleve_ids)));
    return count($eleve_ids);
}

// ==== pv_note_effective_comp ====
/** Note "effective" d'un élève pour une compétence donnée (pas de notion
 *  d'absence justifiée par compétence pour l'instant, voir Phase 6).
 *  Règle 1 : taux de participation de la classe sur CETTE compétence
 *  (élèves actifs notés / effectif actif total) — si ≥50%, note manquante
 *  = 0 (barème compté) ; sinon la compétence est ignorée pour cet élève
 *  (ni barème ni points). */
function pv_note_effective_comp(int $eid, int $id_comp, array $notes_idx, array $notes_count, int $nb_inscrits): ?float {
    if (isset($notes_idx[$eid][$id_comp])) return $notes_idx[$eid][$id_comp];
    $cnt = $notes_count[$id_comp] ?? 0;
    if ($nb_inscrits > 0 && $cnt >= ceil($nb_inscrits / 2)) return 0.0; // absent majoritaire = 0
    return null;
}

// ==== pv_moy_matiere_comp ====
/** Moyenne d'un élève dans une matière (groupe de compétence), sur les
 *  compétences d'un trimestre donné — équivalent compétences de pv_moy_matiere().
 *  Signature ?float inchangée (nombreux appelants existants) — voir
 *  pv_matiere_vraie_note_comp() pour le signal Règle 2 séparé. */
function pv_moy_matiere_comp(int $eid, array $competences, array $notes_idx, array $notes_count, int $nb_inscrits): ?float {
    $tot = 0; $cnt = 0;
    foreach ($competences as $c) {
        $v = pv_note_effective_comp($eid, (int)$c['id'], $notes_idx, $notes_count, $nb_inscrits);
        if ($v !== null) { $tot += $v; $cnt++; }
    }
    return $cnt > 0 ? $tot / $cnt : null;
}

// ==== pv_matiere_vraie_note_comp ====
/** Règle 2 : l'élève a-t-il personnellement composé (vraie note saisie,
 *  pas un zéro automatique Règle 1) au moins une compétence de cette
 *  matière ce trimestre ? Utilisé par pv_moy_generale_comp() pour le seuil
 *  de classement — fonction séparée plutôt qu'un changement de signature
 *  de pv_moy_matiere_comp() (appelée telle quelle par les bulletins/relevés
 *  pour l'affichage des moyennes par matière). */
function pv_matiere_vraie_note_comp(int $eid, array $competences, array $notes_idx): bool {
    foreach ($competences as $c) {
        if (isset($notes_idx[$eid][(int)$c['id']])) return true;
    }
    return false;
}

// ==== pv_moy_generale_comp ====
/**
 * Moyenne générale pondérée par coefficient d'un élève, sur les
 * compétences d'un trimestre — équivalent compétences de pv_moy_generale().
 * @param array $competences_par_mat [id_mat => [{id,...}, ...]]
 * @param array $annules eid => true (Règle 4, voir pv_annules_trimestre())
 * @return array [moyenne|null, classable(bool) — Règle 2, coefficient_total, annule(bool) — Règle 4]
 */
function pv_moy_generale_comp(int $eid, array $disciplines, array $competences_par_mat, array $notes_idx, array $notes_count, int $nb_inscrits, array $mats_avec_notes, int $nb_mats_avec_notes, array $annules = []): array {
    if (!empty($annules[$eid])) return [null, false, 0.0, true]; // Règle 4 : jamais de moyenne, jamais "non classé"
    $tot = 0; $coef = 0; $nb_reelles = 0;
    foreach ($disciplines as $d) {
        if (!in_array($d['id_mat'], $mats_avec_notes)) continue;
        $comps = $competences_par_mat[$d['id_mat']] ?? [];
        $avg = pv_moy_matiere_comp($eid, $comps, $notes_idx, $notes_count, $nb_inscrits);
        if ($avg !== null) { $tot += $avg * $d['coef']; $coef += $d['coef']; }
        if (pv_matiere_vraie_note_comp($eid, $comps, $notes_idx)) $nb_reelles++;
    }
    $moy = $coef > 0 ? $tot / $coef : null;
    // Règle 2 : classé seulement si l'élève a personnellement composé (vraie
    // note, pas un zéro auto) au moins 50% des matières notées de la classe.
    $classable = $nb_mats_avec_notes > 0 && $nb_reelles >= ceil($nb_mats_avec_notes / 2);
    return [$moy, $classable, $coef, false];
}

// ==== pv_charger_donnees_comp ====
/**
 * Charge en un bloc tout ce qu'il faut (disciplines actives, compétences
 * du trimestre par matière, notes indexées, compteurs, matières notées)
 * pour calculer les moyennes d'une classe sur UN trimestre compétences —
 * factorise la requête déjà dupliquée dans bull_moys_classe_comp()
 * (pages/bulletins/index.php) et évite de la retripler dans chaque module
 * Cluster A/B du chantier Phase 6.
 * @return array{disciplines:array, competences_par_mat:array, notes_idx:array, notes_count:array, mats_avec_notes:array, nb_mats_avec_notes:int, nb_inscrits:int, annules:array}
 */
function pv_charger_donnees_comp(int $id_classe, int $id_trim, int $id_annee): array {
    $disciplines = db_all(
        "SELECT d.id_mat, d.coef FROM discipline d
         JOIN matiere m ON m.id=d.id_mat AND m.actif=1
         WHERE d.IDClasses=?", [$id_classe]
    );
    $nb_inscrits = (int) db_val(
        "SELECT COUNT(*) FROM eleve e
         JOIN inscription i ON i.id_eleve=e.id AND i.id_classe=? AND i.id_annee=?
         WHERE e.statut='actif'",
        [$id_classe, $id_annee]
    );
    $annules = pv_annules_trimestre($id_classe, $id_annee, $id_trim);
    $vide = [
        'disciplines' => $disciplines, 'competences_par_mat' => [], 'notes_idx' => [],
        'notes_count' => [], 'mats_avec_notes' => [], 'nb_mats_avec_notes' => 0,
        'nb_inscrits' => $nb_inscrits, 'annules' => $annules,
    ];
    if (empty($disciplines) || !$id_trim) return $vide;

    $code_niveau = db_val("SELECT code_niveau FROM classe WHERE id=?", [$id_classe]);
    $competences_par_mat = [];
    foreach ($disciplines as $d) {
        $competences_par_mat[$d['id_mat']] = db_all(
            "SELECT id FROM competence WHERE id_matiere=? AND code_niveau=? AND id_trim=? ORDER BY ordre",
            [$d['id_mat'], $code_niveau, $id_trim]
        );
    }
    $all_comp_ids = [];
    foreach ($competences_par_mat as $comps) { foreach ($comps as $c) { $all_comp_ids[] = (int)$c['id']; } }
    if (empty($all_comp_ids)) return $vide + ['competences_par_mat' => $competences_par_mat];

    $in_c = implode(',', array_fill(0, count($all_comp_ids), '?'));
    $all_notes = db_all(
        "SELECT n.id_eleve, n.id_competence, n.valeur FROM note n
         JOIN inscription i ON i.id_eleve=n.id_eleve AND i.id_annee=? AND i.id_classe=?
         JOIN eleve el ON el.id=n.id_eleve AND el.statut='actif'
         WHERE n.id_competence IN ($in_c)",
        array_merge([$id_annee, $id_classe], $all_comp_ids)
    );
    $notes_idx = []; $notes_count = [];
    foreach ($all_notes as $row) {
        $notes_idx[(int)$row['id_eleve']][(int)$row['id_competence']] = (float)$row['valeur'];
        $c = (int)$row['id_competence'];
        $notes_count[$c] = ($notes_count[$c] ?? 0) + 1;
    }
    $mats_avec_notes = [];
    foreach ($disciplines as $d) {
        foreach ($competences_par_mat[$d['id_mat']] ?? [] as $c) {
            if (($notes_count[(int)$c['id']] ?? 0) > 0) { $mats_avec_notes[] = $d['id_mat']; break; }
        }
    }
    return [
        'disciplines' => $disciplines, 'competences_par_mat' => $competences_par_mat,
        'notes_idx' => $notes_idx, 'notes_count' => $notes_count,
        'mats_avec_notes' => $mats_avec_notes, 'nb_mats_avec_notes' => count($mats_avec_notes),
        'nb_inscrits' => $nb_inscrits, 'annules' => $annules,
    ];
}

// ==== pv_moy_annuelle_comp ====
/**
 * Moyenne annuelle d'un élève (Règle 3) — somme des moyennes trimestrielles
 * (0 pour un trimestre où l'élève est "non classé") divisée par le nombre de
 * trimestres RÉELLEMENT ÉVALUÉS pour la classe (au moins une note saisie ce
 * trimestre-là), PAS par le nombre de trimestres ayant une moyenne pour cet
 * élève. Un trimestre pas encore commencé pour toute la classe (aucune note
 * nulle part) ne compte pas encore dans le diviseur ; un trimestre annulé
 * pour cet élève (Règle 4) n'y compte JAMAIS, même si la classe a été
 * évaluée dessus.
 * @param array $par_trim liste indexée par trimestre, dans l'ordre de
 *        l'année, de ['moy'=>?float,'classable'=>bool,'annule'=>bool,'evalue'=>bool]
 *        ('evalue' = la classe a au moins une note ce trimestre, indépendamment de cet élève)
 * @return array{moy: ?float, nb_evalues: int}
 */
function pv_moy_annuelle_comp(array $par_trim): array {
    $somme = 0.0; $diviseur = 0;
    foreach ($par_trim as $t) {
        if (!empty($t['annule']) || empty($t['evalue'])) continue;
        $diviseur++;
        if (!empty($t['classable']) && $t['moy'] !== null) $somme += $t['moy'];
        // sinon (non classé mais évalué, pas annulé) : compte 0 dans la somme — Règle 3.
    }
    return ['moy' => $diviseur > 0 ? $somme / $diviseur : null, 'nb_evalues' => $diviseur];
}

// ==== calc_moys_classe_periode_comp ====
/**
 * eid => moyenne pour une période (un trimestre ou l'année entière),
 * Règles 1/2/3/4 appliquées — factorise le bloc jusqu'ici dupliqué
 * (annuel : trimestres chargés un par un + pv_moy_annuelle_comp() ; sinon :
 * un seul pv_charger_donnees_comp()) dans tous les modules qui n'ont besoin
 * QUE de la moyenne par élève, pas du détail M/F/T de
 * calc_bilan_classe_genre_comp() (tableau d'honneur, statistiques,
 * conseil de classe, résultat annuel — 16/08/2026).
 * @param string $vue 'annee' ou 'trimestre'
 * @param array $eleve_ids ids des élèves actifs de la classe (déjà chargés par l'appelant)
 * @return array eid => moyenne
 */
function calc_moys_classe_periode_comp(int $id_classe, int $id_annee, string $vue, int $id_trim, array $eleve_ids): array {
    if (empty($eleve_ids)) return [];

    if ($vue === 'annee') {
        $trimestres = db_all("SELECT id FROM trimestre WHERE id_annee=? ORDER BY ordre", [$id_annee]);
        $d_par_trim = [];
        foreach ($trimestres as $t) { $d_par_trim[] = pv_charger_donnees_comp($id_classe, (int)$t['id'], $id_annee); }

        $moys = [];
        foreach ($eleve_ids as $eid) {
            $eid = (int)$eid;
            $par_trim = [];
            foreach ($d_par_trim as $d) {
                [$m, $classable, , $annule] = pv_moy_generale_comp(
                    $eid, $d['disciplines'], $d['competences_par_mat'], $d['notes_idx'], $d['notes_count'],
                    $d['nb_inscrits'], $d['mats_avec_notes'], $d['nb_mats_avec_notes'], $d['annules']
                );
                $par_trim[] = ['moy' => $m, 'classable' => $classable, 'annule' => $annule, 'evalue' => $d['nb_mats_avec_notes'] > 0];
            }
            $r = pv_moy_annuelle_comp($par_trim);
            if ($r['moy'] !== null) $moys[$eid] = $r['moy'];
        }
        return $moys;
    }

    if (!$id_trim) return [];
    $d = pv_charger_donnees_comp($id_classe, $id_trim, $id_annee);
    if (empty($d['competences_par_mat'])) return [];
    $moys = [];
    foreach ($eleve_ids as $eid) {
        $eid = (int)$eid;
        [$m, $classable] = pv_moy_generale_comp(
            $eid, $d['disciplines'], $d['competences_par_mat'], $d['notes_idx'], $d['notes_count'],
            $d['nb_inscrits'], $d['mats_avec_notes'], $d['nb_mats_avec_notes'], $d['annules']
        );
        if ($classable && $m !== null) $moys[$eid] = $m;
    }
    return $moys;
}

// ==== calc_bilan_classe_genre_comp ====
/**
 * Bilan M/F/T d'une classe pour un trimestre compétences donné —
 * équivalent compétences de calc_bilan_classe_genre() (voir sa docblock
 * pour le détail des colonnes retournées).
 */
function calc_bilan_classe_genre_comp(int $id_classe, int $id_annee, string $val_annee, string $vue, int $id_trim): array {
    $zero = ['M' => 0, 'F' => 0, 'T' => 0];
    $vide = [
        'classes' => $zero, 'moy_lt10' => $zero, 'moy_ge10' => $zero,
        'felicit' => $zero, 'encourag' => $zero, 'tab' => $zero,
        'avert_trav' => $zero, 'blame_trav' => $zero,
    ];
    $eleves = db_all(
        "SELECT e.id, e.sexe, e.matricule FROM eleve e
         JOIN inscription i ON i.id_eleve=e.id AND i.id_classe=? AND i.id_annee=?
         WHERE e.statut='actif'",
        [$id_classe, $id_annee]
    );
    if (empty($eleves)) return $vide;
    if ($vue !== 'annee' && !$id_trim) return $vide;

    // Règles 1/2/3/4 appliquées via le moteur commun (voir sa docblock).
    $moys = calc_moys_classe_periode_comp($id_classe, $id_annee, $vue, $id_trim, array_column($eleves, 'id'));

    $bilan = $vide;
    foreach ($eleves as $el) {
        $eid = (int)$el['id'];
        if (!isset($moys[$eid])) continue;
        $moy = $moys[$eid];
        $sx  = (strtoupper($el['sexe'] ?? '') === 'F') ? 'F' : 'M';

        $bilan['classes'][$sx]++; $bilan['classes']['T']++;
        if ($moy >= 10) { $bilan['moy_ge10'][$sx]++; $bilan['moy_ge10']['T']++; }
        else            { $bilan['moy_lt10'][$sx]++; $bilan['moy_lt10']['T']++; }

        $abs_nj = $vue === 'annee'
            ? (int) eleve_absence_annuelle($el['matricule'] ?? '', $id_classe, $id_annee)['non_jus']
            : (int) eleve_absence_trimestre($el['matricule'] ?? '', $id_trim, $id_classe, $val_annee)['non_jus'];
        if (pv_felicitation($moy, $abs_nj) === 'Oui')    { $bilan['felicit'][$sx]++; $bilan['felicit']['T']++; }
        if (pv_encouragement($moy, $abs_nj) === 'Oui')   { $bilan['encourag'][$sx]++; $bilan['encourag']['T']++; }
        if (pv_tableau_honneur($moy, $abs_nj) === 'Oui') { $bilan['tab'][$sx]++; $bilan['tab']['T']++; }
        if (pv_avertissement_travail($moy) === 'Oui')    { $bilan['avert_trav'][$sx]++; $bilan['avert_trav']['T']++; }
        if (pv_blame_travail($moy) === 'Oui')            { $bilan['blame_trav'][$sx]++; $bilan['blame_trav']['T']++; }
    }
    return $bilan;
}

// ==== calc_bilan_classe_genre ====
/**
 * Bilan M/F/T d'une classe pour une période donnée (une ou plusieurs
 * séquences) — mêmes colonnes que le tableau "par section" fourni par
 * l'utilisateur (fichier TEST_PV_CALCUL.xlsx, onglet INDUSTRIELLE) :
 * classés, moyenne<10, moyenne>=10, félicitations, encouragements, tableau
 * d'honneur, avertissement travail, blâme travail — chacun décliné en
 * Masculin/Féminin/Total. Réutilisé tel quel par la vue HTML (onglets
 * Section/Niveau/Classe de pages/statistiques/index.php) et par les
 * exports PDF/Excel, pour ne pas tripler cette logique déjà éprouvée dans
 * pages/statistiques/pdf_stat_classe.php.
 *
 * @return array{classes: array, moy_lt10: array, moy_ge10: array, felicit: array, encourag: array, tab: array, avert_trav: array, blame_trav: array}
 *         chaque valeur est ['M'=>int,'F'=>int,'T'=>int].
 */
function calc_bilan_classe_genre(int $id_classe, int $id_annee, string $val_annee, int $id_trim, array $seq_ids): array {
    $zero = ['M' => 0, 'F' => 0, 'T' => 0];
    $vide = [
        'classes' => $zero, 'moy_lt10' => $zero, 'moy_ge10' => $zero,
        'felicit' => $zero, 'encourag' => $zero, 'tab' => $zero,
        'avert_trav' => $zero, 'blame_trav' => $zero,
    ];
    $eleves = db_all(
        "SELECT e.id, e.sexe, e.matricule FROM eleve e
         JOIN inscription i ON i.id_eleve=e.id AND i.id_classe=? AND i.id_annee=?
         WHERE e.statut='actif'",
        [$id_classe, $id_annee]
    );
    $nb_inscrits = count($eleves);
    if ($nb_inscrits === 0 || empty($seq_ids)) return $vide;

    $disciplines = db_all("SELECT id_mat, coef FROM discipline WHERE IDClasses=?", [$id_classe]);
    $in_ph = implode(',', array_fill(0, count($seq_ids), '?'));
    $notes_idx = []; $abs_just = []; $notes_count = [];
    $all_notes = db_all(
        "SELECT n.id_eleve, n.id_matiere, n.id_seq, n.valeur FROM note n
         JOIN inscription i ON i.id_eleve=n.id_eleve AND i.id_annee=? AND i.id_classe=?
         WHERE n.id_seq IN ($in_ph)",
        array_merge([$id_annee, $id_classe], $seq_ids)
    );
    foreach ($all_notes as $row) {
        $notes_idx[(int)$row['id_eleve']][(int)$row['id_matiere']][(int)$row['id_seq']] = (float)$row['valeur'];
        $notes_count[(int)$row['id_matiere']][(int)$row['id_seq']] = ($notes_count[(int)$row['id_matiere']][(int)$row['id_seq']] ?? 0) + 1;
    }
    $all_abs = db_all("SELECT id_eleve, id_matiere, id_seq FROM absence_justifiee WHERE id_seq IN ($in_ph) AND justifie=1", $seq_ids);
    foreach ($all_abs as $row) {
        $abs_just[(int)$row['id_eleve']][(int)$row['id_matiere']][(int)$row['id_seq']] = 1;
    }
    $mats_avec_notes = [];
    foreach ($disciplines as $d) {
        foreach ($seq_ids as $sid) {
            if (($notes_count[$d['id_mat']][$sid] ?? 0) > 0) { $mats_avec_notes[] = $d['id_mat']; break; }
        }
    }
    $nb_man = count($mats_avec_notes);

    $bilan = $vide;
    foreach ($eleves as $el) {
        $eid = (int)$el['id'];
        $sx  = (strtoupper($el['sexe'] ?? '') === 'F') ? 'F' : 'M';
        [$moy, $classable] = pv_moy_generale($eid, $disciplines, $seq_ids, $notes_idx, $abs_just, $notes_count, $nb_inscrits, $mats_avec_notes, $nb_man);
        if (!$classable || $moy === null) continue;

        $bilan['classes'][$sx]++; $bilan['classes']['T']++;
        if ($moy >= 10) { $bilan['moy_ge10'][$sx]++; $bilan['moy_ge10']['T']++; }
        else            { $bilan['moy_lt10'][$sx]++; $bilan['moy_lt10']['T']++; }

        $abs_nj = (int) eleve_absence_trimestre($el['matricule'] ?? '', $id_trim, $id_classe, $val_annee)['non_jus'];
        if (pv_felicitation($moy, $abs_nj) === 'Oui')    { $bilan['felicit'][$sx]++; $bilan['felicit']['T']++; }
        if (pv_encouragement($moy, $abs_nj) === 'Oui')   { $bilan['encourag'][$sx]++; $bilan['encourag']['T']++; }
        if (pv_tableau_honneur($moy, $abs_nj) === 'Oui') { $bilan['tab'][$sx]++; $bilan['tab']['T']++; }
        if (pv_avertissement_travail($moy) === 'Oui')    { $bilan['avert_trav'][$sx]++; $bilan['avert_trav']['T']++; }
        if (pv_blame_travail($moy) === 'Oui')            { $bilan['blame_trav'][$sx]++; $bilan['blame_trav']['T']++; }
    }
    return $bilan;
}

// ==== libelle_annee_suivante ====
/**
 * Dérive textuellement le libellé de l'année scolaire SUIVANTE à partir du
 * libellé de l'année active (ex. "2025/2026" -> "2026/2027"), sans dépendre
 * d'une ligne annee_scolaire "suivante" qui n'existe généralement pas
 * encore en base à ce stade. Repose sur les 2 premiers nombres à 4
 * chiffres du libellé (peu importe le séparateur) ; si le format ne
 * correspond pas, le libellé d'origine est retourné tel quel.
 */
function libelle_annee_suivante(string $libelle): string {
    if (!preg_match('/(\d{4})(\D+)(\d{4})/', $libelle, $m, PREG_OFFSET_CAPTURE)) {
        return $libelle;
    }
    [$n1, $pos1] = $m[1];
    $sep = $m[2][0];
    [$n2, $pos2] = $m[3];
    $avant = substr($libelle, 0, $pos1);
    $apres = substr($libelle, $pos2 + strlen($n2));
    return $avant . ((int)$n1 + 1) . $sep . ((int)$n2 + 1) . $apres;
}

// ==== colonnes_resultat_classe ====
/**
 * Catalogue des colonnes disponibles pour les onglets "Résultat par
 * classe" et "Meilleurs élèves" du module Résultat annuel — 'classe'
 * n'est incluse que pour "Meilleurs élèves" (toute l'école), inutile sur
 * une liste déjà filtrée à une seule classe.
 */
function colonnes_resultat_classe(bool $avec_classe = false): array {
    $cols = [
        'no'   => 'N°',
        'niu'  => 'NIU',
        'nom'  => 'Nom et Prénoms',
        'date' => 'Date naiss.',
        'lieu' => 'Lieu naiss.',
        'sexe' => 'Sexe',
    ];
    if ($avec_classe) $cols['classe'] = 'Classe';
    return $cols + [
        't1'          => 'Moy. 1er trim.',
        't2'          => 'Moy. 2e trim.',
        't3'          => 'Moy. 3e trim.',
        'abs'         => "Heures d'absence (non just.)",
        'moy_an'      => 'Moyenne annuelle',
        'rang'        => 'Rang',
        'decision'    => 'Décision',
        'classe_suiv' => 'Classe suivante',
        'obs'         => 'Notes',
    ];
}

// ==== colonnes_liste_provisoire ====
/** Catalogue des colonnes de l'onglet "Liste provisoire" — jeu réduit et
 *  indépendant de colonnes_resultat_classe(). */
function colonnes_liste_provisoire(): array {
    return [
        'no'     => 'N°',
        'niu'    => 'NIU',
        'nom'    => 'Nom et Prénoms',
        'date'   => 'Date naiss.',
        'lieu'   => 'Lieu naiss.',
        'sexe'   => 'Sexe',
        'statut' => 'Statut',
    ];
}

// ==== libelle_moy_trim ====
/** Libellé d'une moyenne trimestrielle dans les tableaux "Résultat annuel"
 *  (colonnes t1/t2/t3 de calc_resultat_annuel_comp()) — distingue "Annulé"
 *  (Règle 4), "Non classé" (Règle 2) et "—" (trimestre pas encore évalué)
 *  d'une vraie moyenne chiffrée. */
function libelle_moy_trim(array $row, int $i): string {
    $statut = $row['moy_t_statut'][$i] ?? null;
    if ($statut === 'annule')     return 'Annulé';
    if ($statut === 'non_classe') return 'N.C.';
    $v = $row['moy_t'][$i] ?? null;
    return $v !== null ? number_format($v, 2) : '—';
}

// ==== valeur_colonne_resultat ====
/** Valeur affichable d'une colonne pour une ligne de calc_resultat_annuel_comp(). */
function valeur_colonne_resultat(string $col, array $row, int $no): string {
    switch ($col) {
        case 'no':          return (string)$no;
        case 'niu':         return id_affichage_eleve($row);
        case 'nom':         return strtoupper($row['nom']) . ' ' . ($row['prenom'] ?? '');
        case 'date':        return $row['date_naiss'] ? date('d/m/Y', strtotime($row['date_naiss'])) : '—';
        case 'lieu':        return $row['lieu_naiss'] ?: '—';
        case 'sexe':        return $row['sexe'] ?? '—';
        case 'classe':      return $row['classe_designation'] ?? '—';
        case 't1':          return libelle_moy_trim($row, 0);
        case 't2':          return libelle_moy_trim($row, 1);
        case 't3':          return libelle_moy_trim($row, 2);
        case 'abs':         return $row['abs_non_just'] !== null ? number_format($row['abs_non_just'], 1) : '—';
        case 'moy_an':      return $row['moy_annuelle'] !== null ? number_format($row['moy_annuelle'], 2) : '—';
        case 'rang':        return $row['rang'] !== null ? ($row['rang'] . 'e') : '—';
        case 'decision':    return $row['decision'] ?: '—';
        case 'classe_suiv': return $row['classe_suivante_designation'] ?? '—';
        case 'obs':         return $row['observation'] ?: '';
        default:            return '—';
    }
}

// ==== valeur_colonne_provisoire ====
/** Valeur affichable d'une colonne pour une ligne de calc_liste_provisoire_comp(). */
function valeur_colonne_provisoire(string $col, array $row, int $no): string {
    switch ($col) {
        case 'no':     return (string)$no;
        case 'niu':    return id_affichage_eleve($row);
        case 'nom':    return strtoupper($row['nom']) . ' ' . ($row['prenom'] ?? '');
        case 'date':   return $row['date_naiss'] ? date('d/m/Y', strtotime($row['date_naiss'])) : '—';
        case 'lieu':   return $row['lieu_naiss'] ?: '—';
        case 'sexe':   return $row['sexe'] ?? '—';
        case 'statut': return $row['statut'] ?? '—';
        default:       return '—';
    }
}

// ==== trier_resultat_affichage ====
/**
 * Trie une COPIE de $rows pour l'AFFICHAGE seulement (alpha ou mérite) —
 * ne touche jamais au champ 'rang', déjà figé au mérite par
 * calc_resultat_annuel_comp() : seul l'ORDRE D'ITÉRATION change, jamais le
 * rang lui-même. $rows est déjà en ordre mérite en entrée (voir
 * calc_resultat_annuel_comp()), donc $tri==='merite' ne fait rien.
 */
function trier_resultat_affichage(array $rows, string $tri): array {
    if ($tri === 'alpha') {
        usort($rows, fn($a, $b) => strcmp(
            trim(($a['nom'] ?? '') . ' ' . ($a['prenom'] ?? '')),
            trim(($b['nom'] ?? '') . ' ' . ($b['prenom'] ?? ''))
        ));
    }
    return $rows;
}

// ==== calc_resultat_annuel_comp ====
/**
 * Calcule, pour chaque élève actif d'UNE classe ($id_classe>0) ou de TOUTES
 * les classes actives de l'année ($id_classe=0 — palmarès établissement) :
 * sa moyenne par trimestre, sa moyenne annuelle (moyenne des moyennes
 * trimestrielles existantes — jamais une pondération par coefficient
 * global), ses heures d'absence annuelles NON JUSTIFIÉES, sa décision de
 * fin d'année et sa classe suivante :
 *
 *  - Décision : si le conseil de classe a enregistré une décision annuelle
 *    (decision_conseil, type='annee') pour cet élève, elle fait foi telle
 *    quelle (decision_source='enregistree') ; sinon, valeur par défaut
 *    (decision_source='auto') : moyenne annuelle >= 10 => Admis, sinon
 *    Redoublement — jamais Exclu/Abandon par défaut (décisions
 *    disciplinaires/administratives, saisie humaine explicite obligatoire) ;
 *    si la moyenne annuelle est null et aucune décision enregistrée =>
 *    "Non classé".
 *  - Classe suivante : Redoublement => la classe actuelle elle-même ;
 *    Admis avec next_classe enregistrée => cette classe ; Admis sans
 *    next_classe enregistrée (y compris un Admis auto-calculé) =>
 *    littéralement "(à définir)", jamais une classe devinée ; Exclu/
 *    Abandon/Non classé => "—".
 *
 * Retourne un tableau à plat, TOUJOURS trié par moyenne annuelle
 * décroissante avec le rang déjà calculé DANS LE PÉRIMÈTRE DEMANDÉ (une
 * classe, ou l'école entière) — le tri d'affichage alpha/mérite est une
 * affaire de l'appelant (trier_resultat_affichage()), jamais de cette
 * fonction : le rang lui-même ne bouge jamais avec l'ordre d'affichage.
 */
function calc_resultat_annuel_comp(int $id_annee, int $id_classe = 0): array {
    $classes = $id_classe
        ? db_all("SELECT * FROM classe WHERE id=?", [$id_classe])
        : db_all(
            "SELECT c.* FROM classe c
             JOIN inscription i ON i.id_classe=c.id AND i.id_annee=?
             WHERE c.archivee=0 GROUP BY c.id ORDER BY c.ordre, c.designation",
            [$id_annee]
          );
    if (empty($classes)) return [];

    // Désignations de toutes les classes — résout next_classe sans requête
    // répétée par élève.
    $designations = [];
    foreach (db_all("SELECT id, designation FROM classe") as $c) { $designations[(int)$c['id']] = $c['designation']; }

    $trimestres = db_all("SELECT id FROM trimestre WHERE id_annee=? ORDER BY ordre", [$id_annee]);

    $out = [];
    foreach ($classes as $c) {
        $cid = (int)$c['id'];
        $eleves = db_all(
            "SELECT e.* FROM eleve e
             JOIN inscription i ON i.id_eleve=e.id AND i.id_classe=? AND i.id_annee=?
             WHERE e.statut='actif'",
            [$cid, $id_annee]
        );
        if (empty($eleves)) continue;

        $dcomp_par_trim = [];
        foreach ($trimestres as $t) {
            $dcomp_par_trim[] = pv_charger_donnees_comp($cid, (int)$t['id'], $id_annee);
        }

        // [i][eid] => ['moy'=>?float,'classable'=>bool,'annule'=>bool,'evalue'=>bool] — Règles 1/2/4 par trimestre.
        $par_trim_par_eleve = [];
        foreach ($dcomp_par_trim as $i => $d) {
            foreach ($eleves as $el) {
                $eid = (int)$el['id'];
                [$m, $classable, , $annule] = empty($d['competences_par_mat']) ? [null, false, 0.0, !empty($d['annules'][$eid])] : pv_moy_generale_comp(
                    $eid, $d['disciplines'], $d['competences_par_mat'], $d['notes_idx'], $d['notes_count'],
                    $d['nb_inscrits'], $d['mats_avec_notes'], $d['nb_mats_avec_notes'], $d['annules']
                );
                $par_trim_par_eleve[$i][$eid] = ['moy' => $m, 'classable' => $classable, 'annule' => $annule, 'evalue' => $d['nb_mats_avec_notes'] > 0];
            }
        }

        $decisions_idx = [];
        foreach (db_all("SELECT * FROM decision_conseil WHERE id_classe=? AND id_annee=? AND type='annee'", [$cid, $id_annee]) as $r) {
            $decisions_idx[(int)$r['id_eleve']] = $r;
        }

        foreach ($eleves as $el) {
            $eid   = (int)$el['id'];
            $trims_eleve = [
                $par_trim_par_eleve[0][$eid] ?? ['moy' => null, 'classable' => false, 'annule' => false, 'evalue' => false],
                $par_trim_par_eleve[1][$eid] ?? ['moy' => null, 'classable' => false, 'annule' => false, 'evalue' => false],
                $par_trim_par_eleve[2][$eid] ?? ['moy' => null, 'classable' => false, 'annule' => false, 'evalue' => false],
            ];
            // moy_t : affichage par trimestre (moyenne si classé, null sinon —
            // le statut détaillé, pour distinguer "Annulé"/"Non classé"/"—",
            // est dans moy_t_statut).
            $moy_t = array_map(fn($t) => ($t['classable'] && $t['moy'] !== null) ? $t['moy'] : null, $trims_eleve);
            $moy_t_statut = array_map(function ($t) {
                if ($t['annule']) return 'annule';
                if ($t['classable'] && $t['moy'] !== null) return 'classe';
                if (!$t['evalue']) return 'vide';
                return 'non_classe';
            }, $trims_eleve);
            // Règle 3 : moyenne annuelle = somme / nb de trimestres réellement
            // évalués pour la classe (pas le nb de trimestres ayant une
            // moyenne pour CET élève) — voir pv_moy_annuelle_comp().
            $r3 = pv_moy_annuelle_comp($trims_eleve);
            $moy_annuelle = $r3['moy'];

            $abs_non_just = (float) eleve_absence_annuelle($el['matricule'] ?? '', $cid, $id_annee)['non_jus'];

            $dec = $decisions_idx[$eid] ?? null;
            if ($dec) {
                $decision        = $dec['decision'];
                $observation     = $dec['observation'];
                $decision_source = 'enregistree';
                $next_classe_id  = $dec['next_classe'] !== null ? (int)$dec['next_classe'] : null;
            } elseif ($moy_annuelle !== null) {
                $decision        = $moy_annuelle >= 10 ? 'Admis' : 'Redoublement';
                $observation     = null;
                $decision_source = 'auto';
                $next_classe_id  = null;
            } else {
                $decision        = 'Non classé';
                $observation     = null;
                $decision_source = 'auto';
                $next_classe_id  = null;
            }

            if ($decision === 'Redoublement') {
                $classe_suivante_designation = $c['designation'];
            } elseif ($decision === 'Admis') {
                $classe_suivante_designation = $next_classe_id !== null
                    ? ($designations[$next_classe_id] ?? '(à définir)')
                    : '(à définir)';
            } else {
                $classe_suivante_designation = '—';
            }

            $out[] = [
                'id' => $eid, 'matricule' => $el['matricule'], 'niu' => $el['niu'] ?? null,
                'nom' => $el['nom'], 'prenom' => $el['prenom'] ?? null, 'sexe' => $el['sexe'] ?? null,
                'date_naiss' => $el['date_naiss'] ?? null, 'lieu_naiss' => $el['lieu_naiss'] ?? null,
                'id_classe' => $cid, 'classe_designation' => $c['designation'],
                'moy_t' => $moy_t, 'moy_t_statut' => $moy_t_statut, 'abs_non_just' => $abs_non_just,
                'moy_annuelle' => $moy_annuelle, 'nb_trim_evalues' => $r3['nb_evalues'], 'rang' => null,
                'decision' => $decision, 'decision_source' => $decision_source,
                'observation' => $observation,
                'next_classe_id' => $next_classe_id,
                'classe_suivante_designation' => $classe_suivante_designation,
            ];
        }
    }

    usort($out, function ($a, $b) {
        if ($a['moy_annuelle'] === null && $b['moy_annuelle'] === null) return 0;
        if ($a['moy_annuelle'] === null) return 1;
        if ($b['moy_annuelle'] === null) return -1;
        return $b['moy_annuelle'] <=> $a['moy_annuelle'];
    });
    $r = 1;
    foreach ($out as &$row) {
        if ($row['moy_annuelle'] !== null) { $row['rang'] = $r++; }
    }
    unset($row);

    return $out;
}

// ==== calc_liste_provisoire_comp ====
/**
 * Construit l'effectif prévisionnel d'une classe pour l'année SUIVANTE :
 * les redoublants de cette classe (statut 'RED') + les élèves admis
 * d'AUTRES classes dont la décision déjà enregistrée précise cette classe
 * comme classe suivante (statut 'NV'). Repose EXCLUSIVEMENT sur des
 * décisions du conseil déjà enregistrées (decision_conseil, type='annee')
 * — un élève encore seulement auto-calculé (jamais examiné par le
 * conseil) n'apparaît jamais ici puisque sa destination réelle est
 * inconnue.
 * La moyenne annuelle (uniquement pour le tri "Mérite" — jamais affichée,
 * absente du jeu de colonnes réduit de cet onglet) est recalculée à la
 * volée via calc_resultat_annuel_comp(), mais SEULEMENT pour les classes
 * D'ORIGINE réellement concernées (jamais un balayage de tout
 * l'établissement).
 */
function calc_liste_provisoire_comp(int $id_annee, int $id_classe_cible): array {
    $classe_cible = db_one("SELECT * FROM classe WHERE id=?", [$id_classe_cible]);
    if (!$classe_cible) return [];

    $decisions_red = db_all(
        "SELECT * FROM decision_conseil WHERE id_classe=? AND id_annee=? AND type='annee' AND decision='Redoublement'",
        [$id_classe_cible, $id_annee]
    );
    $decisions_nv = db_all(
        "SELECT * FROM decision_conseil WHERE id_annee=? AND type='annee' AND decision='Admis' AND next_classe=?",
        [$id_annee, $id_classe_cible]
    );
    if (empty($decisions_red) && empty($decisions_nv)) return [];

    // Regroupe les élèves à récupérer par classe D'ORIGINE (id_classe de la
    // décision) — calc_resultat_annuel_comp() n'est appelée qu'une fois par
    // classe d'origine réellement concernée, jamais pour tout l'établissement.
    $eleves_par_classe_origine = [];
    foreach ($decisions_red as $d) { $eleves_par_classe_origine[(int)$d['id_classe']]['RED'][] = (int)$d['id_eleve']; }
    foreach ($decisions_nv  as $d) { $eleves_par_classe_origine[(int)$d['id_classe']]['NV'][]  = (int)$d['id_eleve']; }

    $out = [];
    foreach ($eleves_par_classe_origine as $id_classe_origine => $groupes) {
        $par_eleve = [];
        foreach (calc_resultat_annuel_comp($id_annee, $id_classe_origine) as $r) { $par_eleve[$r['id']] = $r; }

        foreach (['RED', 'NV'] as $statut) {
            foreach ($groupes[$statut] ?? [] as $eid) {
                $r = $par_eleve[$eid] ?? null;
                if (!$r) continue; // élève plus inscrit/actif cette année : ignoré
                $out[] = [
                    'id' => $r['id'], 'matricule' => $r['matricule'], 'niu' => $r['niu'],
                    'nom' => $r['nom'], 'prenom' => $r['prenom'], 'sexe' => $r['sexe'],
                    'date_naiss' => $r['date_naiss'], 'lieu_naiss' => $r['lieu_naiss'],
                    'statut' => $statut, 'moy_annuelle_origine' => $r['moy_annuelle'],
                ];
            }
        }
    }

    usort($out, fn($a, $b) => strcmp(trim($a['nom'] . ' ' . ($a['prenom'] ?? '')), trim($b['nom'] . ' ' . ($b['prenom'] ?? ''))));

    return $out;
}

