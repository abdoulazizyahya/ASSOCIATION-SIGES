<?php
// header.php — inclus en haut de chaque page
// Variables attendues : $titre_page (optionnel)
// Adapté du layout ABZ_MBE (même design system : sidebar accordéon,
// thèmes de couleur, topbar) mais avec le menu réel de jaynitaare (école
// maternelle/primaire — Directeur/Enseignant/Secrétaire, pas de
// Proviseur/Censeur/SG/Intendant) et sans le bloc notifications/signatures
// numériques (tables non présentes dans ce schéma — pas encore nécessaire
// tant que le module Bulletins/PDF n'est pas construit).
$etab      = get_etablissement();
$annee_act = get_annee_active();
$seq_act   = get_sequence_active();
$user      = utilisateur_connecte();
$role      = role_connecte();

// Rôles "effectifs" pour le filtrage du menu — au-delà du seul $role, un
// compte COMPTABLE (Agent financier) affecté à enseigner une classe cette
// année (agent_est_aussi_enseignant(), fonctions.php) hérite aussi de la
// visibilité ENSEIGNANT (demande explicite du 22/08/2026 : Discipline et
// Pédagogie ne s'affichent à un Agent financier que s'il est EN MÊME TEMPS
// enseignant). N'affecte que l'affichage du menu — les pages elles-mêmes
// gardent leurs propres exiger_role()/exiger_connexion().
$roles_effectifs = [$role];
if ($role !== 'ENSEIGNANT' && agent_est_aussi_enseignant()) {
    $roles_effectifs[] = 'ENSEIGNANT';
}

// Année RÉELLEMENT active (Etat_annee_scolaire=1), pas juste le repli de
// get_annee_active() sur l'année la plus récente — demande explicite du
// 18/08/2026 : tant qu'aucune année n'est explicitement activée, les menus
// Discipline/Pédagogie doivent être bloqués (modale, voir layout/footer.php)
// au clic plutôt que de laisser naviguer vers des pages qui travailleraient
// silencieusement sur une année non choisie par l'admin.
$annee_reellement_active = !empty($annee_act['Etat_annee_scolaire']);
$groupes_annee_requise   = ['Discipline', 'Pédagogie'];

// Page courante pour activer le bon lien du menu
$page_courante    = basename($_SERVER['PHP_SELF'], '.php');
$dossier_courant  = basename(dirname($_SERVER['PHP_SELF']));

// Menu latéral : [label, url, icône, rôles autorisés (vide = tous)]


$menu = [
    'Principal' => [
        ['Tableau de bord', 'dashboard.php', 'speedometer2', []],
    ],
    'Scolarité' => [
        ['Élèves',  'pages/eleves/liste.php',  'people',    []],
        ['Classes', 'pages/classes/liste.php', 'door-open', ['DIRECTEUR','SECRETAIRE','COMPTABLE']],
    ],
	
    // Finances : remontée au-dessus de Pédagogie (demande explicite) — le
    // recouvrement des frais est un usage quotidien de la Secrétaire, plus
    // fréquent que la saisie des notes côté navigation. Réorganisée le
    // 15/08/2026 en 2 sous-sections (même convention de séparateur ['--', ..]
    // que Pédagogie/Arabe) : Gestion des inscriptions (tout ce qui existait
    // déjà — versements des élèves) et Gestion des dépenses (nouveau module,
    // migration v33 — décaissements de l'établissement par catégorie,
    // prélevés sur les montants encaissés côté inscriptions, voir
    // fonctions.php::solde_caisse()).
    'Finances' => [
        ['--', 'Gestion des inscriptions'],
        ['Paiements',        'pages/finances/versement.php',    'cash-coin',            ['DIRECTEUR','SECRETAIRE','COMPTABLE']],
        ['Journal de caisse','pages/finances/journal.php',       'journal-text',         ['DIRECTEUR','SECRETAIRE','COMPTABLE']],
        ['État par classe',  'pages/finances/etat_classe.php',   'list-check',           ['DIRECTEUR','SECRETAIRE','COMPTABLE']],
        ['Répartition par classe', 'pages/finances/repartition_classes.php', 'pie-chart-fill', ['DIRECTEUR','SECRETAIRE','COMPTABLE']],
        ['Impayés',          'pages/finances/impayes.php',       'exclamation-triangle', ['DIRECTEUR','SECRETAIRE','COMPTABLE']],
        ['Cas sociaux',      'pages/finances/cas_sociaux.php',   'heart',                ['DIRECTEUR','SECRETAIRE','COMPTABLE']],
        ['Statistiques',     'pages/finances/statistiques.php',  'bar-chart-line',       ['DIRECTEUR','COMPTABLE']],
        ['Obligations',      'pages/finances/obligations.php',   'card-checklist',       ['DIRECTEUR','COMPTABLE']],

        ['--', 'Gestion des dépenses'],
        ['Nouvelle dépense',     'pages/depenses/saisie.php',       'dash-circle',    ['DIRECTEUR','SECRETAIRE','COMPTABLE']],
        ['Journal des dépenses', 'pages/depenses/journal.php',      'journal-minus',  ['DIRECTEUR','SECRETAIRE','COMPTABLE']],
        ['Répartition par catégorie', 'pages/depenses/repartition_categories.php', 'pie-chart-fill', ['DIRECTEUR','SECRETAIRE','COMPTABLE']],
        ['Statistiques',         'pages/depenses/statistiques.php', 'pie-chart',      ['DIRECTEUR','COMPTABLE']],
        ['Catégories',           'pages/depenses/categories.php',   'tags',           ['DIRECTEUR','COMPTABLE']],
    ],
	// Discipline/Pédagogie : rôles listés explicitement (au lieu de [] = tous)
    // depuis le 22/08/2026 — COMPTABLE (Agent financier) n'a normalement rien
    // à y faire, sauf s'il est EN MÊME TEMPS enseignant (voir $roles_effectifs
    // plus haut, agent_est_aussi_enseignant() dans fonctions.php). Ne PAS
    // ajouter 'COMPTABLE' ici : c'est justement ce que $roles_effectifs
    // ajoute dynamiquement le cas échéant, sans jamais l'accorder d'office.
    'Discipline' => [
        ['Absences',   'pages/absences/index.php',    'person-x', ['DIRECTEUR','ENSEIGNANT','SECRETAIRE']],
    ],
    // Pédagogie : les deux pistes de notation coexistent (l'école est
    // bilingue français/arabe) et sont volontairement regroupées séparément —
    // toute la piste française d'abord, puis un séparateur « Arabe », puis la
    // piste arabe avec les MÊMES entrées. Les deux pistes ont des modèles de
    // calcul différents (compétences+barème côté français, matière+coefficient
    // côté arabe — voir notes_apc.php / notes_apc_arabe.php) mais les mêmes
    // fonctionnalités. Un séparateur s'écrit ['--', 'Libellé'] (pas un lien).
    'Pédagogie' => [

        ['Saisie des notes',       'pages/notes/index.php',       'pencil-square', ['DIRECTEUR','ENSEIGNANT','SECRETAIRE']],
      /*  ['Absences',               'pages/absences/index.php',    'person-x',  []],*/
        ['Notes justifiées',       'pages/notes/absence_justifiee.php', 'person-x', ['DIRECTEUR','ENSEIGNANT','SECRETAIRE']],
        ['Annulation d\'évaluation','pages/notes/annulation_evaluation.php', 'calendar-x', ['DIRECTEUR']],
		['Bulletins',              'pages/bulletins/index.php',   'file-earmark-text', ['DIRECTEUR','ENSEIGNANT','SECRETAIRE']],
        ['Statistiques',           'pages/statistiques/index.php','bar-chart-line', ['DIRECTEUR','ENSEIGNANT','SECRETAIRE']],
        ['Conseil de classe',      'pages/conseil_classe/index.php', 'mortarboard', ['DIRECTEUR','ENSEIGNANT','SECRETAIRE']],
        ['Tableau d\'honneur/ Relevé',    'pages/statistiques/documents.php', 'files', ['DIRECTEUR','ENSEIGNANT','SECRETAIRE']],

        ['Résultat annuel',        'pages/resultat_annuel/index.php', 'trophy', ['DIRECTEUR','ENSEIGNANT','SECRETAIRE']],
		['Groupes de compétences', 'pages/competences/liste.php', 'diagram-3', ['DIRECTEUR','ENSEIGNANT','SECRETAIRE']],


        ['--', 'Arabe'],
        ['Saisie des notes',    'pages/notes_arabe/index.php',            'pencil-square',     ['DIRECTEUR','ENSEIGNANT','SECRETAIRE']],
        ['Notes justifiées',    'pages/notes_arabe/absence_justifiee.php','person-x',          ['DIRECTEUR','ENSEIGNANT','SECRETAIRE']],
		['Bulletins',           'pages/bulletins_arabe/index.php',        'file-earmark-text', ['DIRECTEUR','ENSEIGNANT','SECRETAIRE']],
        // 'Classement' (pages/notes_arabe/classement.php) retiré du menu (14/08/2026) —
        // redondant : rang+moyenne par élève déjà dans Bulletins ci-dessus, stats de
        // classe (effectif/moy./admis/taux réussite/1er/dernier) déjà dans Statistiques
        // ci-dessous, recalcul déjà automatique à chaque enregistrement de notes
        // (pages/notes_arabe/index.php). Fichier conservé tel quel, juste plus lié ici.
        ['Statistiques',        'pages/statistiques_arabe/index.php',     'bar-chart-line',    ['DIRECTEUR','ENSEIGNANT','SECRETAIRE']],
        ['Documents de classe', 'pages/statistiques_arabe/documents.php', 'files',             ['DIRECTEUR','ENSEIGNANT','SECRETAIRE']],
		['Conseil de classe',   'pages/conseil_classe_arabe/index.php',   'mortarboard',       ['DIRECTEUR','ENSEIGNANT','SECRETAIRE']],
        ['Résultat annuel',     'pages/resultat_annuel_arabe/index.php',  'trophy',            ['DIRECTEUR','ENSEIGNANT','SECRETAIRE']],
        ['Groupes de matières', 'pages/matieres_arabe/liste.php',         'diagram-3',         ['DIRECTEUR','ENSEIGNANT','SECRETAIRE']],
    ],
    // RH complète (15/08/2026) : fiche + contrats + congés + paie. Les
    // pages ci-dessous vivent dans pages/enseignants/ (RH) et pages/paie/
    // (paie à proprement parler) — regroupées dans la même section menu, la
    // gestion complète (fiches, grille, paie, avances) reste DIRECTEUR
    // uniquement (personnel/salaires = plus sensible que les élèves dans ce
    // projet). 'Mes informations' et 'Mes bulletins de paie' sont le
    // libre-service (identité/contact/banque en écriture, bulletins en
    // lecture seule) ouvert à DIRECTEUR/ENSEIGNANT/SECRETAIRE/COMPTABLE —
    // demande explicite du 22/08/2026 : chaque rôle non-Directeur ne voit
    // QUE ses propres informations dans ce menu, jamais la liste du
    // personnel ni la paie des autres (pages/enseignants/mon_profil.php,
    // pages/paie/mes_bulletins.php).
    'Ressources humaines' => [
        ['Enseignant(e)s',        'pages/enseignants/liste.php', 'person-badge',   ['DIRECTEUR']],
        ['Grille salariale',      'pages/paie/grille.php',       'table',          ['DIRECTEUR']],
        ['Paie',                  'pages/paie/index.php',        'cash-stack',     ['DIRECTEUR']],
        ['Avances sur salaire',   'pages/paie/avances.php',      'cash',           ['DIRECTEUR']],
        ['Mes informations',      'pages/enseignants/mon_profil.php', 'person-vcard', ['DIRECTEUR','ENSEIGNANT','SECRETAIRE','COMPTABLE']],
        ['Mes bulletins de paie', 'pages/paie/mes_bulletins.php','receipt',        ['ENSEIGNANT','SECRETAIRE','COMPTABLE']],
    ],
    // Paramètres : 'Mon compte' (login/mot de passe/questions secrètes,
    // profil.php) est un libre-service ouvert à TOUS (demande explicite du
    // 22/08/2026) — 'Configurations'/'Utilisateurs' restent DIRECTEUR
    // uniquement (paramétrage global de l'établissement, gestion des
    // comptes d'autrui).
    'Paramètres' => [
        ['Mon compte',        'profil.php',                    'key', []],
        ['Configurations',    'pages/parametres/index.php',    'gear',        ['DIRECTEUR']],
        ['Utilisateurs',      'pages/utilisateurs/liste.php',  'person-gear', ['DIRECTEUR']],
    ],
	
	
];

// Icône + couleur distinctive par groupe de menu (repère visuel indépendant du thème)
$groupe_style = [
    'Principal'            => ['grid-1x2-fill',     '#38bdf8'],
	 'Discipline'           => ['shield-exclamation', '#f87171'],
    'Scolarité'            => ['mortarboard-fill',  '#60a5fa'],
    'Pédagogie'            => ['journal-richtext',  '#a78bfa'],
    /*'Disciplines'           => ['shield-exclamation', '#f87171'],*/
    'Finances'             => ['cash-coin',         '#34d399'],
    'Ressources humaines'  => ['person-badge-fill', '#f472b6'],
    'Paramètres'       => ['gear-fill',         '#94a3b8'],
	 
	
];

// Recense, pour chaque dossier de module, les fichiers distincts réellement
// présents dans le menu — permet de savoir s'il faut le nom de fichier exact
// (plusieurs entrées dans le même dossier) ou toute la section (une seule).
$dossiers_fichiers = [];
foreach ($menu as $items) {
    foreach ($items as $it) {
        if ($it[0] === '--') continue; // séparateur, pas un lien
        $p = explode('/', $it[1]);
        $f = pathinfo(end($p), PATHINFO_FILENAME);
        $d = count($p) > 1 ? $p[count($p) - 2] : '';
        if ($d !== '') $dossiers_fichiers[$d][$f] = true;
    }
}

function lien_actif(string $url): string {
    global $page_courante, $dossier_courant, $dossiers_fichiers;
    $parts   = explode('/', $url);
    $fichier = pathinfo(end($parts), PATHINFO_FILENAME);
    $dossier = count($parts) > 1 ? $parts[count($parts) - 2] : '';
    if ($dossier !== '') {
        if ($dossier !== $dossier_courant) return '';
        $nb_entrees = count($dossiers_fichiers[$dossier] ?? []);
        if ($nb_entrees > 1) {
            return $fichier === $page_courante ? 'active' : '';
        }
        return 'active';
    }
    return $fichier === $page_courante ? 'active' : '';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($titre_page ?? APP_NOM) ?> — <?= h($etab['Initial_Etab'] ?: 'Jaynitaare') ?></title>
  <?php if (!empty($etab['logo']) && is_file(__DIR__ . '/../assets/uploads/' . $etab['logo'])): ?>
    <link rel="icon" href="<?= APP_URL ?>/assets/uploads/<?= h($etab['logo']) ?>">
  <?php endif; ?>
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/bootstrap/css/bootstrap.min.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/inter/inter.css">
  <?php
  // Cache-buster automatique (mtime du fichier) : sans lui, le navigateur
  // peut servir indéfiniment une VERSION EN CACHE de style.css même après
  // une modification côté serveur — a réellement causé un bug visible
  // (bouton .sidebar-close affiché avec le style par défaut du navigateur
  // au lieu d'être masqué, faute d'avoir chargé la règle CSS à jour). La
  // query string change automatiquement à chaque édition du fichier, donc
  // chaque déploiement invalide le cache tout seul, sans y penser.
  $style_css_path = __DIR__ . '/../assets/css/style.css';
  $style_css_ver  = is_file($style_css_path) ? filemtime($style_css_path) : time();
  ?>
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css?v=<?= $style_css_ver ?>">
</head>
<body data-annee-active="<?= $annee_reellement_active ? '1' : '0' ?>">
<div class="abz-shell">

  <!-- ═══ SIDEBAR ═══ -->
  <aside class="abz-sidebar" id="sidebar">
    <button type="button" class="sidebar-close" id="sidebarClose" aria-label="Fermer le menu">
      <i class="bi bi-x-lg"></i>
    </button>
    <div class="abz-brand">
      <?php if (!empty($etab['logo']) && is_file(__DIR__ . '/../assets/uploads/' . $etab['logo'])): ?>
        <div class="brand-logo brand-logo-img">
          <img src="<?= APP_URL ?>/assets/uploads/<?= h($etab['logo']) ?>" alt="<?= h($etab['Initial_Etab'] ?: 'Logo') ?>">
        </div>
      <?php else: ?>
        <div class="brand-logo"><?= h($etab['Initial_Etab'] ?: 'JN') ?></div>
      <?php endif; ?>
      <div>
        <div class="brand-name"><?= h($etab['Nom_Etab_Fr'] ?? APP_NOM) ?></div>
        <div class="brand-sub">Gestion scolaire</div>
      </div>
    </div>

    <nav class="abz-nav">
      <?php foreach ($menu as $groupe => $items): ?>
        <?php
        $visibles = array_filter($items, function($it) use ($roles_effectifs) {
            return $it[0] === '--' || empty($it[3]) || array_intersect($roles_effectifs, $it[3]);
        });
        // Un séparateur seul (tous les liens qui le suivent masqués par les
        // rôles) ne doit pas afficher une section vide avec juste un titre.
        $a_des_liens = false;
        foreach ($visibles as $it) { if ($it[0] !== '--') { $a_des_liens = true; break; } }
        if (!$a_des_liens) continue;
        $section_active = false;
        foreach ($visibles as $it) { if ($it[0] !== '--' && lien_actif($it[1]) === 'active') { $section_active = true; break; } }
        [$icone_grp, $couleur_grp] = $groupe_style[$groupe] ?? ['circle-fill', '#94a3b8'];
        ?>
        <div class="nav-section <?= $section_active ? 'ouvert' : '' ?>" style="--c:<?= $couleur_grp ?>"
             data-annee-requise="<?= in_array($groupe, $groupes_annee_requise, true) ? '1' : '0' ?>">
          <div class="nav-groupe" onclick="toggleNavSection(this)">
            <span class="nav-groupe-icone"><i class="bi bi-<?= $icone_grp ?>"></i></span>
            <span class="nav-groupe-label"><?= h($groupe) ?></span>
            <i class="bi bi-chevron-down nav-groupe-chevron"></i>
          </div>
          <div class="nav-sousmenu">
            <?php foreach ($visibles as $it): ?>
              <?php if ($it[0] === '--'): ?>
                <div class="nav-separateur"><span><?= h($it[1]) ?></span></div>
              <?php else: ?>
                <a href="<?= APP_URL . '/' . $it[1] ?>" class="nav-lien <?= lien_actif($it[1]) ?>">
                  <i class="bi bi-<?= $it[2] ?>"></i>
                  <span><?= h($it[0]) ?></span>
                </a>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </nav>

    <div class="abz-sidebar-footer">
      <a href="<?= APP_URL ?>/logout.php" class="nav-lien text-danger-light">
        <i class="bi bi-box-arrow-right"></i><span>Déconnexion</span>
      </a>
    </div>
  </aside>
  <div class="abz-backdrop" id="backdrop"></div>

  <!-- ═══ CONTENU PRINCIPAL ═══ -->
  <div class="abz-main">

    <!-- Topbar -->
    <header class="abz-topbar">
      <button class="abz-burger" id="burger"><i class="bi bi-list"></i></button>

      <div class="topbar-etab d-none d-md-block">
        <?= h($etab['Nom_Etab_Fr'] ?? APP_NOM) ?>
      </div>

      <div class="ms-auto d-flex align-items-center gap-2">
        <?php if (!empty($seq_act)): ?>
          <!-- Double-clic -> config/activation des évaluations (pages/parametres/
               index.php?onglet=evaluations), réservée au DIRECTEUR (exiger_role()
               y refuse sèchement l'accès aux autres rôles, inutile de proposer le
               raccourci si la page derrière refusera de toute façon). -->
          <span class="badge-seq"<?= $role === 'DIRECTEUR'
              ? ' style="cursor:pointer" title="Double-cliquer pour configurer/activer les évaluations" ondblclick="location.href=\'' . APP_URL . '/pages/parametres/index.php?onglet=evaluations\'"'
              : '' ?>>
            <i class="bi bi-pencil-square me-1"></i><?= h($seq_act['libelle_seq']) ?>
          </span>
        <?php endif; ?>
        <?php if (!empty($annee_act['val_annee']) && $annee_act['val_annee'] !== '—'): ?>
          <span class="badge-annee">
            <i class="bi bi-calendar3 me-1"></i><?= h($annee_act['val_annee']) ?>
          </span>
        <?php endif; ?>

        <div class="dropdown">
          <button class="btn btn-light btn-sm dropdown-toggle d-flex align-items-center gap-2 py-1" data-bs-toggle="dropdown">
            <span class="avatar-mini"><?= h(mb_strtoupper(mb_substr($user['nom'] ?? '?', 0, 1))) ?></span>
            <span class="d-none d-sm-inline" style="font-size:.82rem">
              <?= h(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? '')) ?>
            </span>
          </button>
          <ul class="dropdown-menu dropdown-menu-end">
            <li>
              <span class="dropdown-item-text text-muted" style="font-size:.75rem">
                <?= h(libelle_role($user['role'] ?? '')) ?>
              </span>
            </li>
            <li><hr class="dropdown-divider my-1"></li>
            <li><a class="dropdown-item text-danger" href="<?= APP_URL ?>/logout.php">
              <i class="bi bi-box-arrow-right me-2"></i>Déconnexion
            </a></li>
          </ul>
        </div>
      </div>
    </header>

    <!-- Zone principale -->
    <main class="abz-content">
      <!-- #flash-zone : rafraîchi automatiquement par soumettreFormulaireAjax()
           (layout/footer.php) après un enregistrement en AJAX — permet au
           message de succès/erreur de rester visible sans recharger la page,
           même quand il est déclenché hors de la zone AJAX (#ajax_zone_id). -->
      <div id="flash-zone"><?= flash_html() ?></div>
