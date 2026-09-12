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

// Visite d'un membre de l'association : accès en lecture à TOUT (« visiter
// toutes les infos par école »). Le menu montre alors toutes les entrées
// quel que soit leur liste de rôles ; les écritures restent bloquées
// (est_lecture_seule() : csrf_verifier / db_exec).
$visite_asso   = function_exists('est_visite_association') && est_visite_association();
$est_fondateur = function_exists('est_fondateur') && est_fondateur();
// FONDATEUR : voit tout le menu de son école (comme une visite association),
// en lecture seule — sa seule action est la page « Directeur ».
$menu_voit_tout = $visite_asso || $est_fondateur;
$lecture_seule = function_exists('est_lecture_seule') && est_lecture_seule();

// Bandeau « schéma en retard » (propriétaire uniquement) — demande
// explicite du 13/09/2026 : après un déploiement, le propriétaire est
// prévenu qu'une/des écoles n'ont pas reçu les dernières migrations, mais
// RIEN n'est appliqué automatiquement (voir connexion_assoc.php::
// assoc_migrations_en_retard()) — le clic « Migrer » sur
// /association/migrations.php reste toujours requis.
$migr_retard = (function_exists('est_proprietaire_association') && est_proprietaire_association()
    && function_exists('assoc_migrations_en_retard')) ? assoc_migrations_en_retard() : [];

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


// Définition unique (layout/menu.php), partagée avec l'enforcement des
// privilèges par utilisateur — fonctions.php::menu_definition().
$menu = function_exists('menu_definition') ? menu_definition() : (require __DIR__ . '/menu.php');

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
  <title><?= h($titre_page ?? APP_NOM) ?> — <?= h($etab['Initial_Etab'] ?: (defined('ASSOC_NOM') && ASSOC_NOM !== '' ? ASSOC_NOM : 'SIGES')) ?></title>
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
<body data-annee-active="<?= $annee_reellement_active ? '1' : '0' ?>" class="<?= $lecture_seule ? 'lecture-seule' : '' ?>">
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
        $visibles = array_filter($items, function($it) use ($roles_effectifs, $menu_voit_tout, $groupe) {
            if ($it[0] === '--') return true;
            // Règle « Privilèges » centrale : un octroi 'lecture'/'ecriture'
            // révèle une entrée hors du périmètre de rôle par défaut ; un
            // 'masque' est appliqué par menu_acces_autorise() ci-dessous.
            $nc = function_exists('niveau_central') ? niveau_central($groupe, $it[1]) : null;
            $par_role = $menu_voit_tout || empty($it[3])
                || array_intersect($roles_effectifs, $it[3])
                || in_array($nc, ['lecture', 'ecriture'], true);
            if (!$par_role) return false;
            // Privilèges par utilisateur (local) + 'masque' central.
            return !function_exists('menu_acces_autorise') || menu_acces_autorise($groupe, $it[1]);
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

    <?php if ($visite_asso): ?>
    <!-- Bandeau : visite d'un membre de l'association -->
    <?php
      $ecoles_asso = assoc_all("SELECT id, code, nom FROM etablissement WHERE actif=1 ORDER BY nom");
      // École courante (id annuaire) + droit d'écriture du membre sur elle
      // (superadmin, ou membre_acces.plein_acces) — pour le bouton bascule.
      $ec_id = (int) (function_exists('ecole_courante') && ecole_courante() ? ecole_courante()['id'] : ($_SESSION['ecole']['id'] ?? 0));
      $peut_ecrire_visite = false;
      if ($ec_id && function_exists('est_superadmin_association')) {
          if (est_superadmin_association()) {
              $peut_ecrire_visite = true;
          } else {
              $_mid = membre_connecte()['id'] ?? 0;
              $peut_ecrire_visite = $_mid && (int) assoc_val(
                  "SELECT COALESCE(MAX(plein_acces),0) FROM membre_acces
                   WHERE id_membre=? AND actif=1 AND (id_etablissement IS NULL OR id_etablissement=?)",
                  [$_mid, $ec_id]);
          }
      }
    ?>
    <div class="d-flex flex-wrap align-items-center gap-2 px-3 py-1"
         style="<?= $lecture_seule
             ? 'background:#fff8e6;border-bottom:1px solid #f0dca0;color:#7a5b00'
             : 'background:#fde8e8;border-bottom:1px solid #f5b5b5;color:#8a1c1c' ?>;font-size:.8rem">
      <span>
        <i class="bi bi-<?= $lecture_seule ? 'eye' : 'pencil-square' ?> me-1"></i>
        <strong>Visite association</strong>
        <?= $lecture_seule ? '— lecture seule' : '— LECTURE / ÉCRITURE (modifications enregistrées)' ?></span>
      <?php if (count($ecoles_asso) > 1): ?>
      <div class="dropdown">
        <button class="btn btn-sm btn-light border py-0 px-2 dropdown-toggle" data-bs-toggle="dropdown" style="font-size:.78rem">
          <?= h($etab['Nom_Etab_Fr'] ?? '') ?>
        </button>
        <!-- z-index > .abz-topbar (1030, sticky) : sinon les écoles listées
             sous le bandeau passent DERRIÈRE la topbar et sont invisibles.
             max-height + scroll : la liste peut être longue (réseau). -->
        <ul class="dropdown-menu" style="font-size:.82rem;z-index:1040;max-height:min(70vh,420px);overflow-y:auto">
          <?php $mode_actuel = $lecture_seule ? '&mode=lecture' : ''; // '' = écriture par défaut (superadmin/plein_acces) ?>
          <?php foreach ($ecoles_asso as $ea): ?>
            <li><a class="dropdown-item" href="<?= APP_URL ?>/association/entrer_ecole.php?id=<?= (int) $ea['id'] ?><?= $mode_actuel ?>">
              <?= h($ea['nom']) ?>
            </a></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>

      <?php if ($ec_id && !$lecture_seule): ?>
        <a href="<?= APP_URL ?>/association/entrer_ecole.php?id=<?= $ec_id ?>&amp;mode=lecture"
           class="btn btn-sm btn-light border py-0 px-2" style="font-size:.78rem"
           title="Consulter sans risque de modification">
          <i class="bi bi-eye me-1"></i>Lecture seule
        </a>
      <?php elseif ($ec_id && $lecture_seule && $peut_ecrire_visite): ?>
        <a href="<?= APP_URL ?>/association/entrer_ecole.php?id=<?= $ec_id ?>"
           class="btn btn-sm btn-warning border-0 py-0 px-2" style="font-size:.78rem"
           onclick="return confirm('Passer en mode ÉCRITURE ? Les modifications seront enregistrées dans cette école.');">
          <i class="bi bi-pencil-square me-1"></i>Passer en écriture
        </a>
      <?php endif; ?>

      <a href="<?= APP_URL ?>/association/sortir_ecole.php" class="ms-auto btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:.78rem">
        <i class="bi bi-arrow-left me-1"></i>Retour association
      </a>
    </div>
    <?php elseif ($est_fondateur): ?>
    <!-- Bandeau : fondateur. Il enregistre les personnes / la structure ;
         notes et finances restent en lecture seule ($lecture_seule reflète
         la page courante — voir ecole_contexte.php::fondateur_ecriture_permise). -->
    <div class="d-flex flex-wrap align-items-center gap-2 px-3 py-1"
         style="background:#fff8e6;border-bottom:1px solid #f0dca0;font-size:.8rem;color:#7a5b00">
      <span>
        <i class="bi bi-<?= $lecture_seule ? 'eye' : 'pencil-square' ?> me-1"></i>
        <strong>Espace fondateur</strong>
        <?= $lecture_seule
            ? '— consultation seule (notes &amp; finances)'
            : '— enregistrement autorisé · notes et finances en lecture seule' ?>
      </span>
      <a href="<?= APP_URL ?>/pages/fondateur/directeur.php" class="ms-auto btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:.78rem">
        <i class="bi bi-person-badge me-1"></i>Gérer le directeur
      </a>
    </div>
    <?php elseif (!empty($user) && $lecture_seule):
      // Bandeau générique : cette page précise est en lecture seule pour le
      // compte connecté (rôle par défaut sur un module argent/pédagogie,
      // règle « Privilèges » centrale, ou entrée retirée via acces_utilisateur
      // — voir ecole_contexte.php::est_lecture_seule()). Le menu reste
      // visible et cliquable (demande explicite du 11/09/2026) : seule
      // l'écriture disparaît sur CETTE page (boutons masqués, assets/css/
      // style.css ; refus réel côté serveur, csrf_verifier()/db_exec()).
      $label_rubrique = function_exists('page_menu_label') ? page_menu_label() : null;
    ?>
    <div class="d-flex flex-wrap align-items-center gap-2 px-3 py-1"
         style="background:#fff8e6;border-bottom:1px solid #f0dca0;font-size:.8rem;color:#7a5b00">
      <span>
        <i class="bi bi-eye me-1"></i>
        <strong>Lecture seule<?= $label_rubrique ? ' — ' . h($label_rubrique) : '' ?></strong>
        — vous pouvez consulter cette page, mais pas y enregistrer.
      </span>
    </div>
    <?php endif; ?>

    <?php if ($migr_retard): ?>
    <!-- Bandeau schéma en retard (propriétaire uniquement) — jamais de
         migration automatique, juste un rappel + lien direct. Indépendant
         des 3 bandeaux ci-dessus (peut s'afficher en même temps). -->
    <div class="d-flex flex-wrap align-items-center gap-2 px-3 py-1"
         style="background:#fff3cd;border-bottom:1px solid #ffe69c;color:#664d03;font-size:.8rem">
      <span>
        <i class="bi bi-database-gear me-1"></i>
        <strong>Schéma en retard</strong>
        — <?= count($migr_retard) ?> école(s) (<?= h(implode(', ', array_map(fn($e) => $e['code'], $migr_retard))) ?>)
        n'ont pas encore reçu les dernières migrations : certaines nouveautés peuvent ne pas fonctionner tant que ce n'est pas fait.
      </span>
      <a href="<?= APP_URL ?>/association/migrations.php" class="ms-auto btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:.78rem">
        <i class="bi bi-arrow-right-circle me-1"></i>Voir les migrations
      </a>
    </div>
    <?php endif; ?>

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
