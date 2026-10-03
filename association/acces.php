<?php
// association/acces.php — Module « Privilèges » : régler, par école, ce que
// chaque rôle ou chaque compte peut voir / faire (Masqué · Lecture · Écriture)
// sur chaque menu / sous-menu. Superadmin uniquement.
// Stockage : promeducam_assoc.acces_regle — appliqué côté école par
// ecole_contexte.php::regles_centrales() / niveau_central().
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
require_once __DIR__ . '/_layout.php';
exiger_superadmin_association();

// MEMBRE_ASSOCIATION : rôle synthétique d'un Membre/Superviseur de
// l'association en visite dans cette école (association/entrer_ecole.php).
// Un Administrateur (superadmin) n'est jamais concerné par ce module —
// voir regles_centrales(). Par défaut, Utilisateurs et Paramètres école y
// sont masqués (bd/assoc/maj_assoc.php / assoc_seeder_masque_visite()) ;
// réglable ici comme n'importe quel rôle. Demande explicite du 23/09/2026.
$ecoles = assoc_all("SELECT id, code, nom, type_enseignement FROM etablissement WHERE actif = 1 ORDER BY nom");

$id_etab = (int) ($_GET['etab'] ?? $_POST['etab'] ?? 0);
$portee  = ($_GET['portee'] ?? $_POST['portee'] ?? 'role') === 'user' ? 'user' : 'role';
$cible   = trim($_GET['cible'] ?? $_POST['cible'] ?? '');

$etab = $id_etab ? assoc_one("SELECT * FROM etablissement WHERE id = ?", [$id_etab]) : null;
// Menu et rôles du TYPE de l'école choisie : une école secondaire a son
// propre menu (secondaire/pages/…) et ses propres rôles — avec le menu du
// primaire, les règles posées ici ne correspondaient à aucune de ses pages.
$secondaire = ($etab['type_enseignement'] ?? 'primaire') === 'secondaire';
$ROLES = $secondaire
    ? ['ADMIN', 'PROVISEUR', 'CENSEUR', 'SG', 'INTENDANT', 'SECRETAIRE', 'ENSEIGNANT', 'FONDATEUR', 'MEMBRE_ASSOCIATION']
    : ['DIRECTEUR', 'FONDATEUR', 'COMPTABLE', 'SECRETAIRE', 'ENSEIGNANT', 'MEMBRE_ASSOCIATION'];

// Comptes de l'école choisie (pour la portée « compte »).
$comptes = [];
if ($etab) {
    try {
        $comptes = avec_ecole($id_etab, fn($l) => ecole_all($l, $secondaire
            ? "SELECT login AS login_user, role AS id_fonction, nom AS nom_ens, prenom AS prenom_ens
               FROM utilisateur ORDER BY role, nom"
            : "SELECT u.login_user, e.id_fonction, e.nom_ens, e.prenom_ens
               FROM user u JOIN enseignant e ON e.matricule_ens = u.matricule_ens
               ORDER BY e.id_fonction, e.nom_ens"));
    } catch (\Throwable $e) {
        $comptes = [];
    }
}

// École secondaire : son menu garde PAIEMENT PRIVÉ ou PAIEMENT PUBLIQUE
// selon SON statut (etablissement.statut dans sa base) — lu ici, puisque
// aucune école n'est « ouverte » dans le portail.
if ($secondaire && $etab) {
    try {
        $GLOBALS['statut_ecole_menu'] = (string) (avec_ecole($id_etab, fn($l) => ecole_one($l, "SELECT statut FROM etablissement LIMIT 1"))['statut'] ?? 'public');
    } catch (\Throwable $e) {
        $GLOBALS['statut_ecole_menu'] = 'public';
    }
}
$menu = require __DIR__ . '/../layout/' . ($secondaire ? 'menu_secondaire.php' : 'menu.php');
$statut_page = $GLOBALS['statut_ecole_menu'] ?? null;
unset($GLOBALS['statut_ecole_menu']);

// ── Enregistrement ──────────────────────────────────────────────────
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['op'] ?? '') === 'save') {
    csrf_verifier();
    if (!$etab || $cible === '') {
        $msg = 'Sélectionnez une école et une cible.';
    } else {
        // Clés valides = groupes + entrées réellement présentes dans le menu.
        $valides = [];
        foreach ($menu as $g => $items) {
            $valides['grp:' . $g] = true;
            foreach ($items as $it) {
                if (($it[0] ?? '') === '--') continue;
                $valides[$it[1]] = true;
            }
        }
        $regles = [];
        foreach ((array) ($_POST['regle'] ?? []) as $cle => $niv) {
            $cle = (string) $cle;
            if (isset($valides[$cle]) && in_array($niv, ['masque', 'lecture', 'ecriture'], true)) {
                $regles[$cle] = $niv;
            }
        }
        acces_regle_definir($id_etab, $portee, $cible, $regles);
        journaliser_action('privilege_regle', $id_etab,
            "$portee:$cible — " . count($regles) . ' règle(s)');
        $msg = count($regles)
            ? count($regles) . ' règle(s) enregistrée(s) pour « ' . $cible . ' » (' . $etab['code'] . ').'
            : 'Toutes les règles ont été levées pour « ' . $cible . ' » (retour au défaut du rôle).';
    }
}

// ── Règles actuelles du couple (école, portée, cible) ───────────────
$actuel = [];
if ($etab && $cible !== '') {
    foreach (acces_regle_lister($id_etab) as $r) {
        if ($r['portee'] === $portee && $r['cible'] === $cible) $actuel[$r['cle']] = $r['niveau'];
    }
}

// Rôle concerné (portée compte : le rôle de ce compte).
$role_cible = $cible;
if ($portee === 'user') {
    $role_cible = '';
    foreach ($comptes as $c) if ($c['login_user'] === $cible) { $role_cible = (string) $c['id_fonction']; break; }
}
// Niveau par défaut EXPLICITE de chaque entrée pour ce rôle (masqué /
// lecture seule / écriture) — ecole_contexte.php::acces_niveau_defaut().
$defaut_niveau = fn(array $it): string => acces_niveau_defaut($role_cible, $it[3] ?? [], $it[1], $secondaire);
$NIVEAUX = [
    'ecriture' => ['Écriture',      'bi-pencil-square', '#166534', '#dcfce7'],
    'lecture'  => ['Lecture seule', 'bi-eye',           '#92400e', '#fef3c7'],
    'masque'   => ['Masqué',        'bi-eye-slash',     '#475569', '#e2e8f0'],
];
$badge = fn(string $n): string => '<span class="badge niv-badge" style="color:' . $NIVEAUX[$n][2] . ';background:' . $NIVEAUX[$n][3] . '">'
    . '<i class="bi ' . $NIVEAUX[$n][1] . ' me-1"></i>' . $NIVEAUX[$n][0] . '</span>';

$qs = fn(array $x) => http_build_query(array_merge(
    ['etab' => $id_etab, 'portee' => $portee, 'cible' => $cible], $x));

asso_haut('Privilèges par école');
?>
<?php if ($msg): ?><div class="alert alert-info py-2 small"><?= h($msg) ?></div><?php endif; ?>

<form method="get" class="asso-card mb-3">
  <div class="row g-2 align-items-end">
    <div class="col-12 col-md-4">
      <label class="form-label small">École</label>
      <select name="etab" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="">— choisir —</option>
        <?php foreach ($ecoles as $e): ?>
          <option value="<?= (int) $e['id'] ?>" <?= $id_etab === (int) $e['id'] ? 'selected' : '' ?>>
            <?= h($e['code'] . ' — ' . $e['nom'] . (($e['type_enseignement'] ?? '') === 'secondaire' ? ' (secondaire)' : '')) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-3">
      <label class="form-label small">Portée</label>
      <select name="portee" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="role" <?= $portee === 'role' ? 'selected' : '' ?>>Par rôle</option>
        <option value="user" <?= $portee === 'user' ? 'selected' : '' ?>>Par compte</option>
      </select>
    </div>
    <div class="col-6 col-md-4">
      <label class="form-label small"><?= $portee === 'user' ? 'Compte' : 'Rôle' ?></label>
      <select name="cible" class="form-select form-select-sm" onchange="this.form.submit()" <?= $etab ? '' : 'disabled' ?>>
        <option value="">— choisir —</option>
        <?php if ($portee === 'role'): ?>
          <?php foreach ($ROLES as $r): ?>
            <option value="<?= h($r) ?>" <?= $cible === $r ? 'selected' : '' ?>><?= h(libelle_role($r)) ?></option>
          <?php endforeach; ?>
        <?php else: ?>
          <?php foreach ($comptes as $c): ?>
            <option value="<?= h($c['login_user']) ?>" <?= $cible === $c['login_user'] ? 'selected' : '' ?>>
              <?= h($c['login_user'] . ' — ' . mb_strtoupper($c['nom_ens']) . ' ' . ($c['prenom_ens'] ?? '') . ' (' . libelle_role($c['id_fonction']) . ')') ?>
            </option>
          <?php endforeach; ?>
        <?php endif; ?>
      </select>
    </div>
  </div>
</form>

<?php if ($etab && $cible !== ''): ?>
<form method="post" class="asso-card">
  <input type="hidden" name="csrf" value="<?= h(csrf_generer()) ?>">
  <input type="hidden" name="op"   value="save">
  <input type="hidden" name="etab"   value="<?= $id_etab ?>">
  <input type="hidden" name="portee" value="<?= h($portee) ?>">
  <input type="hidden" name="cible"  value="<?= h($cible) ?>">

  <p class="small text-muted2 mb-2">
    École <strong><?= h($etab['code']) ?></strong> (<?= $secondaire ? 'secondaire' . ($statut_page === 'prive' ? ', privé : PAIEMENT PRIVÉ' : ', public : PAIEMENT PUBLIQUE') : 'primaire' ?>) —
    <?= $portee === 'user' ? 'compte' : 'rôle' ?>
    <strong><?= h($portee === 'role' ? libelle_role($cible) : $cible . ($role_cible !== '' ? ' (' . libelle_role($role_cible) . ')' : '')) ?></strong>.
  </p>
  <div class="small mb-3 d-flex flex-wrap gap-3 align-items-center">
    <span><?= $badge('ecriture') ?> visible, peut enregistrer / modifier</span>
    <span><?= $badge('lecture') ?> visible, consultation seulement</span>
    <span><?= $badge('masque') ?> n'apparaît pas dans le menu</span>
  </div>
  <p class="small text-muted2 mb-3">
    <strong>Défaut</strong> = ce que ce rôle a sans aucune règle. Une <strong>règle</strong> le remplace
    (y compris pour <em>accorder</em> un accès au-delà du rôle). Une règle sur une entrée prime sur celle du groupe.
    <strong>Résultat</strong> = ce qui s'appliquera réellement.
  </p>

  <div class="table-responsive">
  <table class="table table-sm align-middle mb-0" style="font-size:.83rem" id="table-acces">
    <thead><tr class="text-muted2"><th>Menu / entrée</th><th style="width:140px">Défaut</th><th style="width:210px">Règle</th><th style="width:140px">Résultat</th></tr></thead>
    <tbody>
      <?php foreach ($menu as $groupe => $items): ?>
        <?php
          $cg = 'grp:' . $groupe; $ng = $actuel[$cg] ?? '';
          $entrees = array_values(array_filter($items, fn($it) => ($it[0] ?? '') !== '--'));
          $compte = array_count_values(array_map($defaut_niveau, $entrees)) + ['ecriture' => 0, 'lecture' => 0, 'masque' => 0];
        ?>
        <tr class="table-light ligne-groupe" data-groupe="<?= h($cg) ?>">
          <td class="fw-bold"><i class="bi bi-collection me-1"></i><?= h($groupe) ?> <span class="text-muted2 fw-normal">(tout le groupe)</span></td>
          <td class="small text-muted2" style="line-height:1.3">
            <?php foreach (['ecriture' => 'écriture', 'lecture' => 'lecture', 'masque' => 'masqué'] as $n => $lib): ?>
              <?php if ($compte[$n]): ?><span style="color:<?= $NIVEAUX[$n][2] ?>"><?= $compte[$n] ?> <?= $lib ?></span><br><?php endif; ?>
            <?php endforeach; ?>
          </td>
          <td>
            <select name="regle[<?= h($cg) ?>]" class="form-select form-select-sm sel-regle">
              <option value="">Défaut de chaque entrée</option>
              <option value="ecriture" <?= $ng === 'ecriture' ? 'selected' : '' ?>>Écriture (tout le groupe)</option>
              <option value="lecture"  <?= $ng === 'lecture'  ? 'selected' : '' ?>>Lecture seule (tout le groupe)</option>
              <option value="masque"   <?= $ng === 'masque'   ? 'selected' : '' ?>>Masqué (tout le groupe)</option>
            </select>
          </td>
          <td class="small text-muted2">voir chaque entrée</td>
        </tr>
        <?php foreach ($entrees as $it): ?>
          <?php $cle = $it[1]; $nv = $actuel[$cle] ?? ''; $def = $defaut_niveau($it); ?>
          <tr class="ligne-entree" data-groupe="<?= h($cg) ?>" data-defaut="<?= $def ?>">
            <td class="ps-4"><i class="bi bi-<?= h($it[2]) ?> me-1 text-muted2"></i><?= h($it[0]) ?>
              <span class="text-muted2 d-block" style="font-size:.72rem"><?= h($cle) ?></span></td>
            <td><?= $badge($def) ?></td>
            <td>
              <select name="regle[<?= h($cle) ?>]" class="form-select form-select-sm sel-regle">
                <option value="">Défaut (<?= h(mb_strtolower($NIVEAUX[$def][0])) ?>)</option>
                <option value="ecriture" <?= $nv === 'ecriture' ? 'selected' : '' ?>>Écriture</option>
                <option value="lecture"  <?= $nv === 'lecture'  ? 'selected' : '' ?>>Lecture seule</option>
                <option value="masque"   <?= $nv === 'masque'   ? 'selected' : '' ?>>Masqué</option>
              </select>
            </td>
            <td class="cell-resultat"></td>
          </tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <template id="tpl-badges">
    <?php foreach (array_keys($NIVEAUX) as $n): ?><span data-niv="<?= $n ?>"><?= $badge($n) ?></span><?php endforeach; ?>
  </template>
  <script>
  // Résultat effectif, recalculé à chaque changement : règle de l'entrée,
  // sinon règle du groupe, sinon défaut du rôle (ecole_contexte.php::niveau_central()).
  (function () {
    const tpl = document.getElementById('tpl-badges').content;
    const badge = n => tpl.querySelector('[data-niv="' + n + '"]').innerHTML;
    const table = document.getElementById('table-acces');
    function maj() {
      const grp = {};
      table.querySelectorAll('tr.ligne-groupe').forEach(tr => grp[tr.dataset.groupe] = tr.querySelector('select').value);
      table.querySelectorAll('tr.ligne-entree').forEach(tr => {
        const propre = tr.querySelector('select').value;
        const n = propre || grp[tr.dataset.groupe] || tr.dataset.defaut;
        const cell = tr.querySelector('.cell-resultat');
        cell.innerHTML = badge(n) + ((propre || grp[tr.dataset.groupe]) && n !== tr.dataset.defaut
          ? '<span class="d-block text-muted2" style="font-size:.7rem">modifié par la règle</span>' : '');
      });
    }
    table.addEventListener('change', e => { if (e.target.classList.contains('sel-regle')) maj(); });
    maj();
  })();
  </script>

  <div class="mt-3 d-flex gap-2">
    <button class="btn btn-primary btn-sm"><i class="bi bi-check2 me-1"></i>Enregistrer</button>
    <a href="<?= APP_URL ?>/association/acces.php?<?= h($qs([])) ?>" class="btn btn-outline-light btn-sm">Annuler</a>
  </div>
</form>
<?php elseif ($etab): ?>
  <div class="asso-card small text-muted2">Choisissez un rôle ou un compte à configurer.</div>
<?php else: ?>
  <div class="asso-card small text-muted2">Choisissez une école pour commencer.</div>
<?php endif; ?>
<?php asso_bas();
