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
// SERVEUR_LAN_HOST si définie dans config.php (ex.
// define('SERVEUR_LAN_HOST', '192.168.1.50') — à renseigner si la
// détection automatique se trompe, plusieurs cartes réseau/VPN actif) ;
// sinon détection automatique de l'IP LAN de la machine Windows.
function hote_verif_reseau(): string {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $nom_hote = explode(':', $host)[0]; // retire un éventuel :port
    if (!in_array($nom_hote, ['localhost', '127.0.0.1', '::1'], true)) {
        return $host; // déjà une vraie adresse réseau — inchangé
    }
    if (defined('SERVEUR_LAN_HOST') && SERVEUR_LAN_HOST !== '') {
        return SERVEUR_LAN_HOST;
    }
    static $ip_detectee = null;
    if ($ip_detectee === null) {
        $ip = @gethostbyname(gethostname());
        $ip_detectee = ($ip && $ip !== gethostname() && $ip !== '127.0.0.1') ? $ip : $host;
    }
    return $ip_detectee;
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
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
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

function exiger_connexion(): void {
    if (!est_connecte()) {
        header('Location: ' . APP_URL . '/login.php');
        exit;
    }
}

function utilisateur_connecte(): array {
    session_init();
    return $_SESSION['user'] ?? [];
}

function role_connecte(): string {
    return $_SESSION['user']['role'] ?? '';
}

function exiger_role(array $roles): void {
    exiger_connexion();
    if (!in_array(role_connecte(), $roles, true)) {
        die('<div style="font-family:sans-serif;padding:2rem;color:red">
             Accès refusé. Vous n\'avez pas les droits nécessaires.</div>');
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
    return db_one("SELECT * FROM etablissement LIMIT 1") ?? [];
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

    $groupes_assignes_ids = array_map('intval', array_column(
        array_filter(
            db_all("SELECT id_groupe_comp, actif FROM groupe_competence_niveau WHERE code_niveau=?", [$code_niveau]),
            fn($a) => (int) $a['actif'] === 1
        ),
        'id_groupe_comp'
    ));
    $resultat['assigne'] = !empty($groupes_assignes_ids);
    if (!$groupes_assignes_ids) return $resultat;

    $classes_du_niveau = db_all("SELECT IDClasses, DesignationClasses FROM classe WHERE Niveau=? ORDER BY IDClasses", [$code_niveau]);
    $resultat['classes'] = $classes_du_niveau;
    if (!$classes_du_niveau) return $resultat;

    $ids_classes = array_column($classes_du_niveau, 'IDClasses');
    $id_classe_valeurs ??= (int) $ids_classes[0]; // classe la plus ancienne du niveau = valeurs affichées par défaut

    $in_grp = implode(',', array_fill(0, count($groupes_assignes_ids), '?'));
    $bareme = db_all(
        "SELECT c.id_comp, c.code_comp, c.nom_comp,
                g.id_groupe_comp, g.libelle_groupe_comp, g.ordre_affichage,
                d.orale, d.ecrite, d.pratique, d.savoir_etre, d.total_points, d.actif
         FROM competence c
         JOIN groupe_competence g ON g.id_groupe_comp = c.id_groupe_comp
         LEFT JOIN discipline d ON d.id_comp = c.id_comp AND d.IDClasses = ? AND d.annee_scol = ?
         WHERE g.langue = 'Fr' AND g.id_groupe_comp IN ($in_grp)
         ORDER BY g.ordre_affichage, c.code_comp",
        array_merge([$id_classe_valeurs, $val_annee], $groupes_assignes_ids)
    );

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
            $groupes[$idg] = ['libelle' => $b['libelle_groupe_comp'], 'ordre' => (int) $b['ordre_affichage'], 'lignes' => []];
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
        'ENSEIGNANT' => 'Enseignant(e)',
        'SECRETAIRE' => 'Secrétaire',
        default      => $role,
    };
}

// ── Finances : numéro de reçu ───────────────────────────────
// Dérivé de id_pay (paiement_frais) plutôt que stocké dans une colonne
// séparée : pas de séquence à maintenir, jamais de collision, cohérent même
// après suppression d'un versement.
function finances_numero_recu(int $id_pay): string {
    return 'REC-' . str_pad((string) $id_pay, 6, '0', STR_PAD_LEFT);
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
function gen_matricule(string $val_annee, string $niveau): string {
    $code_an  = substr(explode('/', $val_annee)[0] ?: $val_annee, 2, 2);
    $code_niv = (trim($niveau) === 'M') ? 'M' : 'P';
    $base     = $code_an . $code_niv;

    $max = (int) db_val(
        "SELECT MAX(CAST(SUBSTRING(Mat_elv, ?) AS UNSIGNED)) FROM eleve WHERE Mat_elv REGEXP ?",
        [strlen($base) + 1, '^' . preg_quote($base) . '[0-9]{3}$']
    );
    $matricule = $base . str_pad((string)($max + 1), 3, '0', STR_PAD_LEFT);

    if (db_val("SELECT COUNT(*) FROM eleve WHERE Mat_elv=?", [$matricule])) {
        $matricule = $base . random_int(100, 999);
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
function gen_niu(string $initial_etab, string $prefixe = 'PMC'): string {
    $val_annee = get_annee_active()['val_annee'] ?? '';
    $code_an   = substr(explode('/', $val_annee)[0] ?: $val_annee, 2, 2);
    $base      = $prefixe . strtoupper(trim($initial_etab)) . $code_an;

    $max = (int) db_val(
        "SELECT MAX(CAST(SUBSTRING(niu, ?) AS UNSIGNED)) FROM eleve WHERE niu REGEXP ?",
        [strlen($base) + 1, '^' . preg_quote($base) . '[0-9]{4}$']
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
        // Correction du 20/08/2026 : ces deux lignes étaient inversées depuis
        // le portage initial (mapping fait par ressemblance de nom de colonne
        // — "departemental" ~ "departement_fr" — sans vérifier le contenu
        // réel). En base, `delegation_regional_fr` contient en fait le
        // DÉPARTEMENT (ex. "DEPARTEMENT DE LA VINA") et
        // `delegation_departemental_fr` contient en fait l'ARRONDISSEMENT
        // (ex. "ARRONDISEMNET DE NGAOUNDERE I") — noms de colonnes trompeurs
        // hérités du legacy jaynitaare, non révisés avant migration_v31 (voir
        // aussi pages/parametres/index.php, libellés du formulaire renommés
        // en même temps). Sans cette correction, tous les documents piste
        // FR/EN (bulletins, attestations, cartes, exports Excel — tout ce qui
        // passe par cette fonction) affichaient Département et Arrondissement
        // permutés.
        'departement_fr'    => $etab['delegation_regional_fr'] ?? '',
        'arrondissement_fr' => $etab['delegation_departemental_fr'] ?? '',
        'region_en'         => $etab['region_etab_en'] ?? '',
        'division_en'       => $etab['delegation_regional_en'] ?? '',
        'subdivision_en'    => $etab['delegation_departemental_en'] ?? '',
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
    $nom = 'doc_' . bin2hex(random_bytes(10)) . '.' . $ext;
    $dir = __DIR__ . '/assets/uploads/dossiers_eleves/';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    move_uploaded_file($_FILES[$champ_fichier]['tmp_name'], $dir . $nom);
    return $nom;
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
