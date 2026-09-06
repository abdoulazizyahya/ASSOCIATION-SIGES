<?php
// ── Shell de l'interface association (portail multi-établissement) ──
//  MÊME système de design que l'app école (assets/css/style.css) :
//  thème clair, marine + or, sidebar fixe + topbar collante + contenu
//  en cartes, titres Georgia — voir assets/css/association.css.
//
//  API inchangée :
//    asso_haut(string $titre, bool $avec_nav = true)  — ouvre la page
//    asso_bas()                                       — la ferme
//
//  $avec_nav = false → page d'authentification centrée, sans sidebar
//  (association/login.php).

// Entrées de navigation : [label, icône bootstrap, chemin relatif à
// /association/, préfixes qui activent l'entrée, superadmin seulement ?].
function _asso_nav_entrees(): array {
    return [
        ['Établissements',  'buildings',      'index.php',           ['index.php', 'etablissement', 'ecole_bd_'], false],
        ['Tableau de bord', 'speedometer2',   'dashboard.php',       ['dashboard.php'],                           true],
        ['Migrations',      'database-gear',  'migrations.php',      ['migrations.php'],                          true],
        ['Membres',         'people',         'membres/index.php',   ['membres/'],                               true],
        ['Journal',         'journal-text',   'journal.php',         ['journal.php'],                            true],
        ['Registre NIU',    'person-vcard',   'niu/index.php',       ['niu/'],                                   false],
        ['Personnel',       'person-badge',   'personnel/liste.php', ['personnel/'],                             false],
        ['Sécurité',        'shield-lock',    'securite.php',        ['securite.php'],                            false],
    ];
}

// Chemin de la page courante, relatif à /association/ (ex. « membres/voir.php »).
function _asso_chemin_courant(): string {
    $s = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $p = strpos($s, '/association/');
    return $p === false ? basename($s) : substr($s, $p + strlen('/association/'));
}

function asso_haut(string $titre, bool $avec_nav = true): void {
    $GLOBALS['_asso_avec_nav'] = $avec_nav;

    $m          = function_exists('membre_connecte') ? membre_connecte() : [];
    $superadmin = function_exists('est_superadmin_association') && est_superadmin_association();
    $nom_membre = trim(($m['prenom'] ?? '') . ' ' . ($m['nom'] ?? '')) ?: 'Membre';
    $initiale   = mb_strtoupper(mb_substr($m['prenom'] ?? ($m['nom'] ?? '?'), 0, 1));

    $courant = _asso_chemin_courant();
    $css_ver = @filemtime(__DIR__ . '/../assets/css/association.css') ?: time();
    ?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($titre) ?> — Association</title>
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/bootstrap/css/bootstrap.min.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/inter/inter.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/association.css?v=<?= $css_ver ?>">
</head>
<body>
<?php if (!$avec_nav): ?>
  <div class="asso-auth">
    <div class="auth-box">
      <div class="auth-brand">
        <span class="brand-mark"><i class="bi bi-mortarboard-fill"></i></span>
        <span class="t">ASSOCIATION</span>
      </div>
<?php else: ?>
  <div class="asso-shell">

    <aside class="asso-sidebar" id="assoSidebar">
      <div class="asso-brand">
        <span class="brand-mark"><i class="bi bi-mortarboard-fill"></i></span>
        <div>
          <div class="brand-name">ASSOCIATION</div>
          <div class="brand-sub">Portail multi-établissement</div>
        </div>
      </div>

      <nav class="asso-nav">
        <div class="nav-label">Navigation</div>
        <?php foreach (_asso_nav_entrees() as [$label, $icone, $href, $prefixes, $super_only]): ?>
          <?php if ($super_only && !$superadmin) continue; ?>
          <?php
            $actif = false;
            foreach ($prefixes as $pref) {
                if ($courant === $pref || strpos($courant, $pref) === 0) { $actif = true; break; }
            }
          ?>
          <a href="<?= APP_URL ?>/association/<?= $href ?>" class="<?= $actif ? 'active' : '' ?>">
            <i class="bi bi-<?= $icone ?>"></i><span><?= h($label) ?></span>
          </a>
        <?php endforeach; ?>
      </nav>

      <div class="asso-sidebar-footer">
        <div class="asso-user">
          <span class="avatar"><?= h($initiale ?: 'M') ?></span>
          <div>
            <div class="u-name"><?= h($nom_membre) ?></div>
            <div class="u-role"><?= $superadmin ? 'Superadministrateur' : 'Membre' ?></div>
          </div>
        </div>
        <a href="<?= APP_URL ?>/association/logout.php" class="logout">
          <i class="bi bi-box-arrow-right"></i><span>Déconnexion</span>
        </a>
      </div>
    </aside>
    <div class="asso-backdrop" id="assoBackdrop"></div>

    <div class="asso-main">
      <header class="asso-topbar">
        <button type="button" class="asso-burger" id="assoBurger" aria-label="Menu"><i class="bi bi-list"></i></button>
        <span class="tb-title d-none d-sm-inline">Portail association</span>
        <span class="tb-badge ms-auto"><i class="bi bi-diagram-3 me-1"></i><?= $superadmin ? 'Superadmin' : 'Membre' ?></span>
      </header>

      <div class="asso-content">
        <div class="asso-wrap">
          <div id="flash-zone"><?= function_exists('flash_html') ? flash_html() : '' ?></div>
          <h1 class="asso-page-title"><?= h($titre) ?></h1>
<?php endif; ?>
<?php
}

function asso_bas(): void {
    $avec_nav = $GLOBALS['_asso_avec_nav'] ?? true;
    ?>
<?php if (!$avec_nav): ?>
    </div><!-- .auth-box -->
  </div><!-- .asso-auth -->
<?php else: ?>
        </div><!-- .asso-wrap -->
      </div><!-- .asso-content -->
    </div><!-- .asso-main -->
  </div><!-- .asso-shell -->
  <script>
    (function () {
      var b = document.getElementById('assoBurger'),
          bd = document.getElementById('assoBackdrop');
      function close(){ document.body.classList.remove('asso-nav-open'); }
      if (b) b.addEventListener('click', function () { document.body.classList.toggle('asso-nav-open'); });
      if (bd) bd.addEventListener('click', close);
      document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
    })();
  </script>
<?php endif; ?>
<script src="<?= APP_URL ?>/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
}
