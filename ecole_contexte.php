<?php
// ── Contexte « établissement courant » (multi-établissement) ─────────
//  Résout, à chaque requête, DANS QUELLE base école on travaille :
//    1. sous-domaine        ecole1.assoc.cm            (production)
//    2. paramètre ?ec=CODE  (pages publiques verif_*, visiteur anonyme)
//    3. session             (login école OU visite d'un membre asso)
//    4. repli               école n°1 (compat mono-école / login direct)
//
//  Inclus par connexion.php APRÈS connexion_assoc.php. Sans effet si
//  l'annuaire est absent (installation mono-école historique).
//
//  Expose :
//    resoudre_etablissement() : ?array   ligne annuaire, ou null (contexte asso)
//    ecole_courante()         : ?array   idem, après résolution (global $ETAB_COURANT)
//    est_contexte_association(): bool     page sous /association/
//    est_visite_association()  : bool     un membre a « ouvert » une école
//    est_lecture_seule()       : bool     écritures interdites (visite asso)

require_once __DIR__ . '/config.php';

// Démarre la session avec EXACTEMENT les mêmes paramètres que
// fonctions.php::session_init() (nom + path dédiés à cette app). Doit
// tourner avant tout envoi de sortie ; fonctions.php::session_init()
// deviendra alors un no-op (il teste session_status()).
function ecole_session_demarrer(): void {
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        session_name('JAYNITAARE_SESSID');
        session_set_cookie_params([
            'path'     => APP_URL . '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

// Page servie depuis le dossier de l'interface association ?
function est_contexte_association(): bool {
    $s = $_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? '');
    return strpos($s, '/association/') !== false;
}

// Un membre de l'association a « ouvert » une école pour la consulter.
function est_visite_association(): bool {
    ecole_session_demarrer();
    return !empty($_SESSION['visite_asso']);
}

// Visite association de niveau « Administrateur » (superadmin/propriétaire) :
// seule catégorie qui garde l'accès complet, sans passer par le module
// Privilèges — voir regles_centrales(), menu_acces_autorise(), exiger_role().
// Un Membre / Superviseur en visite (posé par entrer_ecole.php) est
// désormais restreint comme n'importe quel rôle par ce module (Utilisateurs
// / Paramètres masqués par défaut). Demande explicite du 23/09/2026.
function est_visite_association_administrateur(): bool {
    ecole_session_demarrer();
    return est_visite_association() && (($_SESSION['visite_asso_niveau'] ?? 'administrateur') === 'administrateur');
}

// Rôle « FONDATEUR » : consulte toute son école. Il peut ENREGISTRER les
// personnes et la structure (élèves, personnel, directeur, comptes,
// classes, niveaux…) mais PAS l'argent ni les notes : paiements, dépenses,
// paie, saisie de notes, bulletins, conseils, statistiques et résultats
// restent en LECTURE SEULE pour lui (demande explicite du 10/09/2026).
function est_fondateur(): bool {
    ecole_session_demarrer();
    return ($_SESSION['user']['role'] ?? '') === 'FONDATEUR';
}

// Séparation des pouvoirs (11/09/2026) : dans les modules « argent »
// (finances / dépenses / paie), seul l'agent financier (COMPTABLE) écrit —
// tous les autres profils (directeur, fondateur, secrétaire, membre
// association…) restent en LECTURE SEULE, sauf les pages de CONFIGURATION
// (frais/obligations, catégories de dépense) ouvertes en plus au directeur
// et au fondateur. Dans les modules « pédagogie » (notes, bulletins,
// conseils, statistiques, résultats, compétences, matières arabe,
// absences), seuls enseignant(e) / secrétaire écrivent (+ un agent
// financier EN MÊME TEMPS affecté à enseigner, agent_est_aussi_enseignant()).
// Le reste (élèves, personnel, comptes, classes, niveaux, paramètres…) n'est
// pas restreint ici : chaque page garde son propre exiger_role().
// Une règle centrale « Privilèges » (association/acces.php) peut lever ou
// durcir ce défaut par école — cf. est_lecture_seule(), qui l'applique AVANT
// de consulter cette fonction.
//
// $role : rôle testé (défaut = rôle connecté). Renvoie true si l'écriture
// est permise sur la page courante pour ce profil.
function ecriture_module_permise(?string $role = null, ?string $script = null, ?bool $secondaire = null): bool {
    $role   = $role ?? (function_exists('role_connecte') ? role_connecte() : '');
    // $script / $secondaire : évaluer une AUTRE page / un autre type d'école
    // que la requête en cours (association/acces.php, colonne « Défaut »).
    $secondaire = $secondaire ?? (function_exists('type_enseignement_courant') && type_enseignement_courant() === 'secondaire');
    // École secondaire : rôles (ADMIN/PROVISEUR/CENSEUR/SG/SECRETAIRE/
    // ENSEIGNANT/INTENDANT) et pages (secondaire/pages/...) sans rapport avec
    // la matrice primaire ci-dessous (DIRECTEUR/FONDATEUR/COMPTABLE/
    // ENSEIGNANT/SECRETAIRE). Bug réel constaté le 16/09/2026 (étape 9,
    // Notes) : les préfixes de chemin plus bas (ex. '/pages/notes/') matchent
    // AUSSI secondaire/pages/notes/... par simple sous-chaîne — ADMIN n'étant
    // dans aucune des listes de rôles primaire, l'écriture des notes était
    // bloquée pour tout rôle secondaire sauf ENSEIGNANT/SECRETAIRE (mêmes
    // noms que côté primaire, par coïncidence). Chaque page secondaire gère
    // déjà ses propres restrictions (exiger_role() + logique interne
    // is_admin/is_ens dans secondaire/pages/notes/, etc.) — pas de
    // restriction supplémentaire ici, comme pour la « structure » primaire
    // (retour true en bas de fonction).
    if ($secondaire) {
        return true;
    }
    $script = str_replace('\\', '/', $script ?? ($_SERVER['SCRIPT_NAME'] ?? ''));
    $base   = basename($script);

    // Toujours autorisées, quel que soit le module.
    if (in_array($base, ['directeur.php', 'configurer_securite.php', 'profil.php'], true)) {
        return true;
    }

    $rel = ltrim(preg_replace('~^.*/(?=pages/)~', '', $script), '/');

    // ── Modules « argent » : seul l'agent financier écrit ────────────
    $modules_argent = ['/pages/finances/', '/pages/depenses/', '/pages/paie/'];
    $config_argent  = [
        'pages/finances/obligations.php', 'pages/finances/excel_obligations.php',
        'pages/depenses/categories.php',
    ];
    foreach ($modules_argent as $frag) {
        if (strpos($script, $frag) !== false) {
            if (in_array($rel, $config_argent, true)) {
                return in_array($role, ['DIRECTEUR', 'FONDATEUR', 'COMPTABLE', 'MEMBRE_ASSOCIATION'], true);
            }
            return $role === 'COMPTABLE';
        }
    }

    // ── Modules « pédagogie » : enseignant(e) / secrétaire écrivent ──
    //  Exception « configuration » : les groupes de compétences / matières
    //  ET leurs barèmes (structure pédagogique — pas la saisie de notes au
    //  jour le jour) restent ouverts au directeur et au fondateur, comme les
    //  niveaux/classes/élèves (demande explicite du 11/09/2026).
    $modules_pedagogie = [
        '/pages/notes/', '/pages/notes_arabe/',
        '/pages/bulletins/', '/pages/bulletins_arabe/',
        '/pages/conseil_classe/', '/pages/conseil_classe_arabe/',
        '/pages/statistiques/', '/pages/statistiques_arabe/',
        '/pages/resultat_annuel/', '/pages/resultat_annuel_arabe/',
        '/pages/competences/', '/pages/matieres_arabe/',
        '/pages/absences/',
    ];
    $config_pedagogie = ['pages/competences/liste.php', 'pages/matieres_arabe/liste.php'];
    foreach ($modules_pedagogie as $frag) {
        if (strpos($script, $frag) !== false) {
            if (in_array($rel, $config_pedagogie, true) && in_array($role, ['DIRECTEUR', 'FONDATEUR'], true)) {
                return true;
            }
            if (in_array($role, ['ENSEIGNANT', 'SECRETAIRE'], true)) return true;
            // Un agent financier EN MÊME TEMPS affecté à enseigner garde la
            // main sur SES classes (même règle que le menu, header.php).
            return $role === 'COMPTABLE' && function_exists('agent_est_aussi_enseignant') && agent_est_aussi_enseignant();
        }
    }

    // Structure (élèves, personnel, comptes, classes, niveaux, paramètres,
    // dossiers…) : pas de restriction ici — gouvernée par exiger_role().
    return true;
}

// Alias historique (appelé par est_ecriture_deleguee, csrf_verifier…).
function fondateur_ecriture_permise(): bool {
    return ecriture_module_permise('FONDATEUR');
}

// « Peut voir les données financières » (montants, soldes, encaissements) —
// tableau de bord + menu Finances/Dépenses/Paie. Le DIRECTEUR et l'ENSEIGNANT
// en sont exclus par défaut ; le FONDATEUR, l'agent financier, la secrétaire,
// le propriétaire et une visite association les voient.
function capacite_finances(): bool {
    if (est_visite_association() || est_proprietaire_association()) return true;
    if (est_fondateur()) return true;
    // Rôles secondaire distincts du primaire (COMPTABLE/SECRETAIRE) — mêmes
    // rôles déjà autorisés sur le module Paiements secondaire, voir
    // exiger_role() dans secondaire/pages/paiements/*.php. Sans cette
    // branche, un PROVISEUR/CENSEUR/INTENDANT (staff qui voit déjà les
    // paiements dans son propre module) ne voyait jamais le bloc Finances
    // du tableau de bord — bug réel constaté le 21/09/2026.
    if (function_exists('type_enseignement_courant') && type_enseignement_courant() === 'secondaire') {
        return in_array(role_connecte(), ['ADMIN', 'PROVISEUR', 'CENSEUR', 'INTENDANT'], true);
    }
    return in_array(role_connecte(), ['COMPTABLE', 'SECRETAIRE'], true);
}

// Écritures interdites (lecture seule). Ordre : le propriétaire n'est jamais
// bridé ; une règle centrale « Privilèges » prime ensuite ; puis un menu
// retiré (deny-list locale / rôle sans accès par défaut) force la lecture
// seule sans bloquer la page (11/09/2026) ; enfin les défauts par module
// (visite association, argent = agent financier, pédagogie = enseignant(e)
// / secrétaire).
function est_lecture_seule(): bool {
    if (est_proprietaire_association()) return false;

    $c = niveau_central_page_courante();          // 'masque'|'lecture'|'ecriture'|null
    if ($c === 'ecriture')             return false;
    if ($c === 'lecture' || $c === 'masque') return true;

    if (est_visite_association()) {
        if (empty($_SESSION['visite_asso_ecriture'])) return true;
        return !ecriture_module_permise('MEMBRE_ASSOCIATION');
    }

    // Un menu retiré (par le directeur via acces_utilisateur, ou parce que le
    // rôle n'a par défaut aucune entrée de menu pour cette page) : la page
    // reste consultable, mais en lecture seule.
    if (function_exists('acces_page_lecture_seule') && acces_page_lecture_seule()) {
        return true;
    }

    return !ecriture_module_permise(role_connecte());
}

/**
 * Niveau d'accès PAR DÉFAUT (sans règle « Privilèges ») d'un rôle sur une
 * entrée de menu : 'masque' | 'lecture' | 'ecriture'. Reproduit la logique
 * appliquée dans l'école — menu (layout/header.php : fondateur et visite
 * association voient tout, sinon rôle listé dans l'entrée), puis
 * est_lecture_seule() (visite association = lecture ; entrée hors du rôle =
 * lecture ; modules argent / pédagogie, ecriture_module_permise()).
 * Sert à afficher un « Défaut » explicite dans association/acces.php.
 */
function acces_niveau_defaut(string $role, array $roles_entree, string $url, bool $secondaire): string {
    $dans_role = empty($roles_entree) || in_array($role, $roles_entree, true);
    $voit_tout = in_array($role, ['FONDATEUR', 'MEMBRE_ASSOCIATION'], true);
    if (!$dans_role && !$voit_tout) return 'masque';
    if ($role === 'MEMBRE_ASSOCIATION') return 'lecture';     // visite en lecture seule par défaut
    if (!$dans_role && $secondaire) return 'lecture';         // fondateur hors de son périmètre
    return ecriture_module_permise($role, '/' . ltrim($url, '/'), $secondaire) ? 'ecriture' : 'lecture';
}

// ── Règles « Privilèges » centrales (association/acces.php) ──────────
//  Table promeducam_assoc.acces_regle : par école, par rôle OU par compte,
//  un niveau ('masque' | 'lecture' | 'ecriture') sur un groupe de menu
//  ('grp:Nom') ou une entrée précise (son url). Absence de règle = défaut
//  du rôle. Voir connexion_assoc.php::acces_regle_pour().

/** Règles applicables à l'utilisateur connecté dans l'école courante. */
function regles_centrales(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    if (!function_exists('acces_regle_pour')) return $cache = [];
    // Un membre association « Administrateur » (superadmin/propriétaire) en
    // visite n'est jamais restreint par ce module. Un Membre / Superviseur
    // EST concerné (rôle synthétique MEMBRE_ASSOCIATION, voir
    // entrer_ecole.php) — c'est justement ce module qui masque par défaut
    // Utilisateurs / Paramètres pour lui (bd/assoc/maj_assoc.php).
    if (est_visite_association() && est_visite_association_administrateur()) return $cache = [];
    $ec = ecole_courante();
    $id = $ec['id'] ?? null;
    if (!$id) return $cache = [];
    return $cache = acces_regle_pour(
        (int) $id,
        role_connecte(),
        $_SESSION['user']['login'] ?? null
    );
}

/** Niveau central pour un couple (groupe, url), ou null si aucune règle. */
function niveau_central(string $groupe, string $url): ?string {
    $r = regles_centrales();
    return $r[$url] ?? $r['grp:' . $groupe] ?? null;
}

/** Niveau central de la page en cours d'affichage (résout groupe + url via le menu). */
function niveau_central_page_courante(): ?string {
    if (!function_exists('menu_definition') || !function_exists('page_courante_relative')) return null;
    $r = regles_centrales();
    if (!$r) return null;

    $rel = page_courante_relative();
    if ($rel === '') return null;

    // Index url → groupe, et dossier → [url => groupe] (même logique que
    // acces_page_lecture_seule(), fonctions.php).
    static $index = null;
    if ($index === null) {
        $index = ['url' => [], 'dossier' => []];
        foreach (menu_definition() as $groupe => $items) {
            foreach ($items as $it) {
                if (($it[0] ?? '') === '--') continue;
                $u = $it[1];
                $index['url'][$u] = $groupe;
                $d = strpos($u, '/') !== false ? basename(dirname($u)) : '';
                if ($d !== '') $index['dossier'][$d][$u] = $groupe;
            }
        }
    }

    if (isset($index['url'][$rel])) {
        return niveau_central($index['url'][$rel], $rel);
    }
    // Page « fille » d'un dossier de module : on prend la règle du groupe si
    // toutes les entrées du dossier appartiennent au même groupe.
    $d = strpos($rel, '/') !== false ? basename(dirname($rel)) : '';
    if ($d !== '' && !empty($index['dossier'][$d])) {
        $niveaux = [];
        foreach ($index['dossier'][$d] as $u => $g) {
            $niveaux[] = niveau_central($g, $u);
        }
        // priorité masque > lecture > ecriture ; null si aucune règle
        foreach (['masque', 'lecture', 'ecriture'] as $prio) {
            if (in_array($prio, $niveaux, true)) return $prio;
        }
    }
    return null;
}

// « Écriture déléguée » : l'utilisateur n'a pas de rôle école classique
// (DIRECTEUR/SECRETAIRE/…) mais est un membre association entré en mode
// écriture OU un FONDATEUR, ET la page courante lui autorise l'écriture
// (est_lecture_seule() = false). Les gabarits s'en servent pour AFFICHER
// les boutons d'action qui seraient sinon réservés à un rôle local — le
// blocage réel des écritures reste csrf_verifier() / db_exec().
function est_ecriture_deleguee(): bool {
    if (est_lecture_seule()) return false;
    return est_visite_association() || est_fondateur();
}

// Propriétaire de l'association : compte fondateur (membre.proprietaire=1).
// Au-dessus du superadmin — SEUL habilité à accorder ou retirer le niveau
// superadmin à un autre membre (association/membres/voir.php). Colonne
// ajoutée par bd/assoc/maj_assoc.php : son absence est tolérée (false).
function est_proprietaire_association(): bool {
    if (!annuaire_dispo() || !est_membre_association()) return false;
    $m = membre_connecte();
    try {
        return (bool) assoc_val("SELECT proprietaire FROM membre WHERE id=?", [$m['id'] ?? 0]);
    } catch (\Throwable $e) {
        return false;   // colonne pas encore présente
    }
}

// Superadmin de l'association : membre disposant d'un accès GLOBAL en
// écriture (membre_acces : id_etablissement NULL + plein_acces=1), ou le
// propriétaire (qui l'est toujours, même si sa ligne d'accès a été retirée
// par erreur — anti-verrouillage). Seul habilité à créer une école,
// affecter un agent à une école, frapper un NIU.
function est_superadmin_association(): bool {
    if (!annuaire_dispo() || !est_membre_association()) return false;
    if (est_proprietaire_association()) return true;
    $m = membre_connecte();
    return (bool) assoc_val(
        "SELECT COUNT(*) FROM membre_acces
         WHERE id_membre=? AND actif=1 AND id_etablissement IS NULL AND plein_acces=1",
        [$m['id'] ?? 0]
    );
}

// Garde des pages d'écriture de l'interface association (création d'école,
// affectation d'un agent, registre NIU).
function exiger_superadmin_association(): void {
    exiger_membre_association();
    if (!est_superadmin_association()) {
        http_response_code(403);
        die('<div style="font-family:sans-serif;padding:2rem;color:#b45309">
             Action réservée au superadministrateur de l\'association.</div>');
    }
}

// Garde des OPÉRATIONS SENSIBLES SUR LES BASES : restauration, import,
// vidage, réinitialisation, création/suppression de base, suppression
// d'établissement. Réservées au PROPRIÉTAIRE (compte fondateur). Un
// administrateur « simple » (superadmin sans être propriétaire) ne peut
// que consulter et SAUVEGARDER — jamais remplacer ni détruire des données.
function exiger_proprietaire_association(): void {
    exiger_membre_association();
    if (!est_proprietaire_association()) {
        http_response_code(403);
        die('<div style="font-family:sans-serif;padding:2rem;color:#b45309">
             Opération réservée au <b>propriétaire</b> de l\'association
             (restauration / import / vidage / suppression de base).<br>
             Un administrateur peut uniquement <b>sauvegarder</b> une base.</div>');
    }
}

// Sous-domaine de la requête (« ecole1 » pour ecole1.assoc.cm), ou ''.
function _sous_domaine_requete(): string {
    $host = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]);
    if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) return '';
    // Hôte canonique unique de l'app (déploiement 1 sous-domaine) : aucune
    // résolution par sous-domaine, l'école est choisie au login / via ?ec=.
    if (defined('APP_HOTE') && APP_HOTE !== '' && $host === strtolower(APP_HOTE)) return '';
    $parts = explode('.', $host);
    if (count($parts) < 2) return '';                       // « localhost »
    $sub = $parts[0];
    return in_array($sub, ['www', 'localhost', 'admin', 'assoc'], true) ? '' : $sub;
}

function resoudre_etablissement(): ?array {
    if (!annuaire_dispo()) return null;

    // Contexte association : on travaille dans la base annuaire, pas d'école.
    if (est_contexte_association()) return null;

    // 1. Sous-domaine (production)
    if ($sub = _sous_domaine_requete()) {
        $e = assoc_one("SELECT * FROM etablissement WHERE sous_domaine=? AND actif=1", [$sub]);
        if ($e) return $e;
    }

    // 2. ?ec=CODE — pages publiques de vérification (aucune écriture de session)
    if (!empty($_GET['ec'])) {
        $e = assoc_one("SELECT * FROM etablissement WHERE code=? AND actif=1", [(string) $_GET['ec']]);
        if ($e) return $e;
    }

    // 3. Session — login école ou visite d'un membre association
    ecole_session_demarrer();
    if (!empty($_SESSION['ecole']['id'])) {
        $e = assoc_one("SELECT * FROM etablissement WHERE id=? AND actif=1", [(int) $_SESSION['ecole']['id']]);
        if ($e) return $e;
    }

    // 4. Repli : école n°1 — UNIQUEMENT en installation mono-école (une
    //    seule école active). Dès qu'il y a plusieurs établissements, aucune
    //    école par défaut : la page d'accueil / de connexion reste NEUTRE
    //    (pas de logo ni de nom d'école) tant que l'utilisateur n'en a pas
    //    choisi une (login.php), et les pages publiques exigent ?ec=.
    $nb_actives = (int) assoc_val("SELECT COUNT(*) FROM etablissement WHERE actif=1");
    if ($nb_actives > 1) return null;

    return assoc_one("SELECT * FROM etablissement WHERE code='EC1' AND actif=1")
        ?? assoc_one("SELECT * FROM etablissement WHERE actif=1 ORDER BY id LIMIT 1");
}

/** Contexte « neutre » : annuaire présent, plusieurs écoles, aucune choisie. */
function est_contexte_neutre(): bool {
    global $ETAB_COURANT;
    return annuaire_dispo() && !est_contexte_association() && $ETAB_COURANT === null;
}

/** Ligne annuaire de l'école courante (null en contexte association). */
function ecole_courante(): ?array {
    global $ETAB_COURANT;
    return $ETAB_COURANT ?: null;
}

// Type pédagogique de l'école courante : 'primaire' ou 'secondaire'.
// Détermine quel module de pages/menu/dashboard/schéma s'applique — voir
// bd/assoc/schema_ref_ecole_secondaire.sql et le dossier secondaire/.
// Hors contexte multi-établissement (annuaire absent — installation mono-
// école, voir install.php mode « école unique »), il n'y a pas de ligne
// annuaire pour porter ce type : on retombe sur la constante ECOLE_TYPE_SOLO
// (config.php / config.local.php), 'primaire' par défaut (comportement
// historique inchangé pour toute installation existante).
function type_enseignement_courant(): string {
    $t = ecole_courante()['type_enseignement'] ?? null;
    if ($t !== null) return $t;
    return (defined('ECOLE_TYPE_SOLO') && ECOLE_TYPE_SOLO === 'secondaire') ? 'secondaire' : 'primaire';
}

// ── Bascule de la base « école courante » ───────────────────────────
//  Pose $_SESSION['ecole'], met à jour $ETAB_COURANT et RE-sélectionne la
//  base sur $link (connexion.php a déjà tourné à l'inclusion de la page —
//  cette fonction est appelée plus tard, à la soumission d'un formulaire
//  de choix d'école ou à l'entrée d'un membre dans une école).
function basculer_base_ecole(array $etab): void {
    global $link, $ETAB_COURANT;
    ecole_session_demarrer();
    $_SESSION['ecole'] = [
        'id'      => (int) $etab['id'],
        'code'    => $etab['code'],
        'db_name' => $etab['db_name'],
        'nom'     => $etab['nom'],
    ];
    $ETAB_COURANT = $etab;
    if ($link instanceof mysqli) {
        mysqli_select_db($link, $etab['db_name']);
    }
}

// ── Membre de l'association connecté au portail ─────────────────────
function membre_connecte(): array {
    ecole_session_demarrer();
    return $_SESSION['membre'] ?? [];
}

function est_membre_association(): bool {
    ecole_session_demarrer();
    return !empty($_SESSION['membre']['id']);
}

/** Garde des pages sous association/ (hors login). */
function exiger_membre_association(): void {
    if (!annuaire_dispo()) {
        die('<div style="font-family:sans-serif;padding:2rem;color:red">
             Annuaire association non installé (bd/assoc/installer.php).</div>');
    }
    if (!est_membre_association()) {
        header('Location: ' . APP_URL . '/association/login.php');
        exit;
    }
    // 2FA obligatoire pour les superadmins : tant qu'elle n'est pas activée,
    // toutes les pages association renvoient vers securite.php (le drapeau est
    // posé à la connexion — association/login.php). logout et securite.php
    // eux-mêmes restent accessibles pour éviter tout enfermement.
    if (!empty($_SESSION['forcer_2fa'])) {
        $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
        if (!in_array($script, ['securite.php', 'logout.php'], true)) {
            header('Location: ' . APP_URL . '/association/securite.php');
            exit;
        }
    }
}

/**
 * Journalise une ACTION (visite d'école, écriture, opération sensible…).
 * Adaptateur vers le journal d'audit unifié (bd/lib/audit.php) : l'acteur
 * (membre association OU compte d'école), l'appareil et la localisation
 * sont résolus automatiquement par audit_log().
 */
function journaliser_action(string $action, ?int $id_etab = null, ?string $cible = null): void {
    if (!annuaire_dispo()) return;
    require_once __DIR__ . '/bd/lib/audit.php';
    audit_log('action', ['action' => $action, 'id_etab' => $id_etab, 'cible' => $cible]);
}

// ── Registre NIU central (jaynitaare_assoc.eleve_niu) ───────────────
//  Appelés depuis les pages « école » (pages/eleves/save.php) pour tenir
//  à jour l'identité et l'école courante d'un élève au niveau association.
//  Sans annuaire : no-op (mode mono-école).
//
//  $ident : ['nom','prenom','date_naiss','sexe','lieu_naiss']

function niu_enregistrer_inscription(string $niu, array $ident): void {
    if (!annuaire_dispo() || trim($niu) === '') return;
    $ec = ecole_courante();
    $id_e = $ec['id'] ?? null;
    $p = [
        $ident['nom'] ?? null, $ident['prenom'] ?? null, $ident['date_naiss'] ?? null,
        $ident['sexe'] ?? null, $ident['lieu_naiss'] ?? null,
    ];
    if (assoc_val("SELECT COUNT(*) FROM eleve_niu WHERE niu=?", [$niu])) {
        assoc_exec(
            "UPDATE eleve_niu SET nom=?, prenom=?, date_naissance=?, sexe=?, lieu_naissance=?,
                    id_etab_courant=?, statut='actif'
             WHERE niu=?",
            [...$p, $id_e, $niu]
        );
    } else {
        // NIU hors registre (repli mono-école antérieur, import, saisie manuelle)
        assoc_exec(
            "INSERT INTO eleve_niu (niu, nom, prenom, date_naissance, sexe, lieu_naissance,
                    id_etab_origine, id_etab_courant, statut)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'actif')",
            [$niu, ...$p, $id_e, $id_e]
        );
    }
    assoc_exec(
        "INSERT INTO eleve_niu_mouvement (niu, id_etab_cible, type, par) VALUES (?, ?, 'inscription', ?)",
        [$niu, $id_e, 'ecole:' . ($ec['code'] ?? '?')]
    );
}

// ── Affectation d'un agent dans la base d'une école ─────────────────
//  Écrit dans la base école CIBLE (via avec_ecole) : crée/retrouve la
//  ligne `enseignant`, crée le compte `user` si absent, puis enregistre
//  l'affectation au central. Retourne [ok, message, login, mdp_temporaire].
//
//  $ident : ['nom','prenom','sexe','date_naiss','tel','email']
function affecter_agent(string $matricule, int $id_etab_cible, string $fonction, array $ident): array {
    if (!annuaire_dispo()) return [false, 'Annuaire indisponible.', null, null];
    $fonction = in_array($fonction, ['DIRECTEUR', 'FONDATEUR', 'ENSEIGNANT', 'SECRETAIRE', 'COMPTABLE'], true) ? $fonction : 'ENSEIGNANT';

    // 1. personnel central (créé si absent)
    if (!assoc_val("SELECT COUNT(*) FROM personnel WHERE matricule=?", [$matricule])) {
        assoc_exec(
            "INSERT INTO personnel (matricule, nom, prenom, date_naissance, sexe, tel, email)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$matricule, $ident['nom'] ?? '', $ident['prenom'] ?? null, $ident['date_naiss'] ?? null,
             $ident['sexe'] ?? null, $ident['tel'] ?? null, $ident['email'] ?? null]
        );
    }

    // 2. écriture dans la base école cible
    $mdp_clair = bin2hex(random_bytes(4));   // mot de passe temporaire
    $res = avec_ecole($id_etab_cible, function (mysqli $l) use ($ident, $fonction, $matricule, $mdp_clair) {
        // enseignant existant ? (mat_ens = matricule central, sinon identité)
        $ex = ecole_one($l, "SELECT matricule_ens FROM enseignant WHERE mat_ens=? LIMIT 1", [$matricule])
           ?? ecole_one($l, "SELECT matricule_ens FROM enseignant WHERE nom_ens=? AND COALESCE(prenom_ens,'')=? LIMIT 1",
                        [$ident['nom'] ?? '', $ident['prenom'] ?? '']);
        if ($ex) {
            $mat_ens = (int) $ex['matricule_ens'];
            ecole_exec($l, "UPDATE enseignant SET id_fonction=?, statut_ens='actif' WHERE matricule_ens=?",
                       [$fonction, $mat_ens]);
        } else {
            ecole_exec($l,
                "INSERT INTO enseignant (nom_ens, prenom_ens, sexe_ens, date_naiss_ens, tel_ens, mail_ens,
                        mat_ens, id_fonction, statut_ens)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'actif')",
                [$ident['nom'] ?? '', $ident['prenom'] ?? null, $ident['sexe'] ?? null, $ident['date_naiss'] ?? null,
                 $ident['tel'] ?? null, $ident['email'] ?? null, $matricule, $fonction]
            );
            $mat_ens = (int) mysqli_insert_id($l);
        }

        // compte user ?
        $u = ecole_one($l, "SELECT id_user, login_user FROM user WHERE matricule_ens=? LIMIT 1", [$mat_ens]);
        if ($u) {
            return ['mat_ens' => $mat_ens, 'id_user' => (int) $u['id_user'],
                    'login' => $u['login_user'], 'nouveau_compte' => false];
        }
        $slug = strtolower(preg_replace('/[^a-z0-9]/i', '',
            substr($ident['prenom'] ?? '', 0, 1) . ($ident['nom'] ?? 'agent')));
        $slug  = $slug !== '' ? $slug : 'agent';
        $login = $slug; $i = 1;
        while (ecole_one($l, "SELECT id_user FROM user WHERE login_user=?", [$login])) { $login = $slug . (++$i); }
        ecole_exec($l, "INSERT INTO user (login_user, pwd_user, matricule_ens) VALUES (?, ?, ?)",
                   [$login, password_hash($mdp_clair, PASSWORD_DEFAULT), $mat_ens]);
        return ['mat_ens' => $mat_ens, 'id_user' => (int) mysqli_insert_id($l),
                'login' => $login, 'nouveau_compte' => true];
    });

    // 3. affectation centrale (upsert)
    $aff = assoc_one("SELECT id FROM personnel_affectation WHERE matricule=? AND id_etablissement=?",
                     [$matricule, $id_etab_cible]);
    if ($aff) {
        assoc_exec(
            "UPDATE personnel_affectation SET fonction=?, matricule_ens_local=?, id_user_local=?,
                    date_fin=NULL, actif=1 WHERE id=?",
            [$fonction, $res['mat_ens'], $res['id_user'], $aff['id']]
        );
    } else {
        assoc_exec(
            "INSERT INTO personnel_affectation
                (matricule, id_etablissement, fonction, matricule_ens_local, id_user_local, date_debut, actif)
             VALUES (?, ?, ?, ?, ?, CURDATE(), 1)",
            [$matricule, $id_etab_cible, $fonction, $res['mat_ens'], $res['id_user']]
        );
    }

    $m = "Affecté. Compte « {$res['login']} »";
    $m .= $res['nouveau_compte'] ? " créé — mot de passe temporaire : $mdp_clair (à changer)." : " (compte existant réutilisé).";
    return [true, $m, $res['login'], $res['nouveau_compte'] ? $mdp_clair : null];
}

// Clôt une affectation (désactive le compte user dans la base école source).
function cloturer_affectation(int $id_affectation): void {
    if (!annuaire_dispo()) return;
    $a = assoc_one("SELECT * FROM personnel_affectation WHERE id=?", [$id_affectation]);
    if (!$a) return;
    assoc_exec("UPDATE personnel_affectation SET actif=0, date_fin=CURDATE() WHERE id=?", [$id_affectation]);
    if ($a['matricule_ens_local']) {
        avec_ecole((int) $a['id_etablissement'], function ($l) use ($a) {
            $st = mysqli_prepare($l, "UPDATE enseignant SET statut_ens='inactif' WHERE matricule_ens=?");
            mysqli_stmt_bind_param($st, 's', $a['matricule_ens_local']);
            mysqli_stmt_execute($st);
            mysqli_stmt_close($st);
        });
    }
}

function niu_synchroniser_identite(string $niu, array $ident): void {
    if (!annuaire_dispo() || trim($niu) === '') return;
    if (!assoc_val("SELECT COUNT(*) FROM eleve_niu WHERE niu=?", [$niu])) return;
    assoc_exec(
        "UPDATE eleve_niu SET nom=?, prenom=?, date_naissance=?, sexe=?, lieu_naissance=? WHERE niu=?",
        [
            $ident['nom'] ?? null, $ident['prenom'] ?? null, $ident['date_naiss'] ?? null,
            $ident['sexe'] ?? null, $ident['lieu_naiss'] ?? null, $niu,
        ]
    );
}
