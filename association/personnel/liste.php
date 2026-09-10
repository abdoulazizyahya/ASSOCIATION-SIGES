<?php
// association/personnel/liste.php — page à onglets :
//   • « Registre »  : personnel central de l'association + affectations (inchangé).
//   • « Comptes »    : (superadmin) tous les comptes de connexion `user` de
//     toutes les écoles — réinitialiser le mot de passe, activer/désactiver
//     (user.actif, migration v54), changer le rôle, supprimer, créer.
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/../_layout.php';
exiger_membre_association();

$superadmin = est_superadmin_association();
$onglet = ($_GET['onglet'] ?? '') === 'comptes' && $superadmin ? 'comptes' : 'registre';
$msg = ''; $err = '';

// ── Actions sur un compte école (onglet « Comptes ») ────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $superadmin) {
    csrf_verifier();
    $onglet = 'comptes';
    $op      = $_POST['op'] ?? '';
    $id_etab = (int) ($_POST['id_etab'] ?? 0);

    if ($op === 'creer') {
        $r = assoc_compte_ecole_creer($id_etab, $_POST['login'] ?? '', (string) ($_POST['pwd'] ?? ''), (int) ($_POST['matricule_ens'] ?? 0));
        if ($r['ok']) journaliser_action('compte_ecole_creer', $id_etab, trim($_POST['login'] ?? ''));
    } else {
        $r = assoc_compte_ecole_action($id_etab, (int) ($_POST['id_user'] ?? 0), $op, [
            'pwd'  => $_POST['pwd'] ?? '',
            'role' => $_POST['role'] ?? '',
        ]);
        if ($r['ok']) journaliser_action('compte_ecole_' . $op, $id_etab, ($r['message'] ?? ''));
    }
    $msg = $r['ok'] ? $r['message'] : ''; $err = $r['ok'] ? '' : $r['message'];
}

// ── Onglet « Registre » ────────────────────────────────────────────
$q = trim($_GET['q'] ?? '');
$where = '1'; $params = [];
if ($q !== '') { $where = "(p.matricule LIKE ? OR p.nom LIKE ? OR p.prenom LIKE ?)"; $params = ["%$q%", "%$q%", "%$q%"]; }
$gens = assoc_all(
    "SELECT p.*,
            GROUP_CONCAT(CONCAT(e.code, ':', a.fonction, IF(a.actif, '', ' (clôturée)')) SEPARATOR ' · ') AS affectations
     FROM personnel p
     LEFT JOIN personnel_affectation a ON a.matricule = p.matricule
     LEFT JOIN etablissement e ON e.id = a.id_etablissement
     WHERE $where
     GROUP BY p.matricule ORDER BY p.nom, p.prenom", $params
);

// ── Onglet « Comptes » ─────────────────────────────────────────────
$ecoles = $superadmin ? assoc_all("SELECT id, code, nom FROM etablissement WHERE actif=1 ORDER BY nom") : [];
$comptes = ['lignes' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'stats' => []];
$filtre  = ['etab' => (int) ($_GET['etab'] ?? 0), 'q' => trim($_GET['cq'] ?? ''), 'role' => $_GET['role'] ?? '', 'statut' => $_GET['statut'] ?? ''];
if ($onglet === 'comptes' && $superadmin) {
    $comptes = assoc_comptes_systeme($filtre, max(1, (int) ($_GET['p'] ?? 1)), 60);
}

asso_haut('Personnel');
$csrf = csrf_generer();
$ong_url = fn(string $o) => APP_URL . '/association/personnel/liste.php?onglet=' . $o;
?>
<?php if ($msg): ?><div class="alert alert-success py-2 small"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-warning py-2 small"><?= h($err) ?></div><?php endif; ?>

<?php if ($superadmin): ?>
<ul class="nav nav-tabs mb-3" style="border-bottom:2px solid var(--border)">
  <li class="nav-item"><a class="nav-link <?= $onglet === 'registre' ? 'active' : '' ?>" href="<?= $ong_url('registre') ?>"><i class="bi bi-person-badge me-1"></i>Registre</a></li>
  <li class="nav-item"><a class="nav-link <?= $onglet === 'comptes' ? 'active' : '' ?>" href="<?= $ong_url('comptes') ?>"><i class="bi bi-key me-1"></i>Comptes<?php if (!empty($comptes['stats']['total'])): ?> <span class="badge badge-soft"><?= (int) $comptes['stats']['total'] ?></span><?php endif; ?></a></li>
</ul>
<?php endif; ?>

<?php if ($onglet === 'registre'): ?>
<!-- ═══════════════ REGISTRE ═══════════════ -->
<form method="get" class="mb-3" style="max-width:340px">
  <input type="text" name="q" value="<?= h($q) ?>" class="form-control form-control-sm" placeholder="matricule, nom…" data-filtre>
</form>
<div class="asso-card p-0">
  <table class="table table-sm mb-0 align-middle" style="font-size:.83rem">
    <thead><tr><th>Matricule</th><th>Nom</th><th>Statut</th><th>Affectations</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($gens as $p): ?>
        <tr>
          <td class="font-monospace"><?= h($p['matricule']) ?></td>
          <td><?= h(trim($p['nom'] . ' ' . $p['prenom'])) ?></td>
          <td><span class="badge badge-soft"><?= h($p['statut']) ?></span></td>
          <td class="small text-muted2"><?= h($p['affectations'] ?: '—') ?></td>
          <td class="text-end">
            <a class="btn btn-outline-light btn-sm py-0" href="<?= APP_URL ?>/association/personnel/fiche.php?m=<?= urlencode($p['matricule']) ?>">Fiche</a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$gens): ?><tr><td colspan="5" class="text-center text-muted2 py-3">Aucun agent.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<p class="small text-muted2 mt-2">
  <?php if ($superadmin): ?>
  <a href="<?= APP_URL ?>/association/personnel/affecter.php" class="btn btn-primary btn-sm">
    <i class="bi bi-arrow-left-right me-1"></i>Affecter un agent à une école
  </a>
  <?php endif; ?>
</p>

<?php elseif ($onglet === 'comptes' && $superadmin): ?>
<!-- ═══════════════ COMPTES ═══════════════ -->
<?php $s = $comptes['stats']; ?>
<div class="d-flex flex-wrap gap-2 mb-3" style="font-size:.8rem">
  <span class="badge badge-soft"><?= (int) ($s['total'] ?? 0) ?> compte(s)</span>
  <span class="badge bg-success"><?= (int) ($s['actifs'] ?? 0) ?> actif(s)</span>
  <span class="badge bg-secondary"><?= (int) ($s['inactifs'] ?? 0) ?> désactivé(s)</span>
  <span class="badge bg-warning text-dark"><?= (int) ($s['dormants'] ?? 0) ?> sans connexion &gt; 90 j</span>
</div>

<form method="get" class="row g-2 mb-3" style="font-size:.85rem">
  <input type="hidden" name="onglet" value="comptes">
  <div class="col-sm-3">
    <select name="etab" class="form-select form-select-sm">
      <option value="0">Toutes les écoles</option>
      <?php foreach ($ecoles as $e): ?><option value="<?= (int) $e['id'] ?>" <?= $filtre['etab'] === (int) $e['id'] ? 'selected' : '' ?>><?= h($e['code'] . ' — ' . $e['nom']) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="col-sm-2">
    <select name="role" class="form-select form-select-sm">
      <option value="">Tous les rôles</option>
      <?php foreach (['DIRECTEUR' => 'Directeur', 'ENSEIGNANT' => 'Enseignant(e)', 'SECRETAIRE' => 'Secrétaire', 'COMPTABLE' => 'Comptable', 'FONDATEUR' => 'Fondateur'] as $k => $v): ?>
        <option value="<?= $k ?>" <?= $filtre['role'] === $k ? 'selected' : '' ?>><?= h($v) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-sm-2">
    <select name="statut" class="form-select form-select-sm">
      <option value="">Tous statuts</option>
      <option value="actif"   <?= $filtre['statut'] === 'actif' ? 'selected' : '' ?>>Actifs</option>
      <option value="inactif" <?= $filtre['statut'] === 'inactif' ? 'selected' : '' ?>>Désactivés</option>
      <option value="dormant" <?= $filtre['statut'] === 'dormant' ? 'selected' : '' ?>>Dormants</option>
    </select>
  </div>
  <div class="col-sm-3">
    <input type="text" name="cq" value="<?= h($filtre['q']) ?>" class="form-control form-control-sm" placeholder="nom, identifiant…">
  </div>
  <div class="col-sm-2"><button class="btn btn-primary btn-sm w-100">Filtrer</button></div>
</form>

<div class="asso-card p-0 mb-3">
  <div class="table-responsive">
  <table class="table table-sm mb-0 align-middle" style="font-size:.83rem">
    <thead><tr class="text-muted2">
      <th>École</th><th>Identifiant</th><th>Personne</th><th>Rôle</th><th>Statut</th><th>Dernière connexion</th><th class="text-end">Actions</th>
    </tr></thead>
    <tbody>
      <?php foreach ($comptes['lignes'] as $c): ?>
        <tr class="<?= $c['actif'] ? '' : 'opacity-50' ?>">
          <td class="small"><span class="font-monospace"><?= h($c['ecole_code']) ?></span></td>
          <td class="font-monospace"><?= h($c['login']) ?></td>
          <td><?= h($c['nom']) ?></td>
          <td><span class="badge badge-soft"><?= h($c['role_lib']) ?></span></td>
          <td>
            <?php if (!$c['actif']): ?><span class="text-warning small">désactivé</span>
            <?php elseif ($c['dormant']): ?><span class="text-warning small" title="Aucune connexion depuis plus de 90 jours">dormant</span>
            <?php else: ?><span class="text-success small">actif</span><?php endif; ?>
          </td>
          <td class="small text-muted2"><?= $c['derniere_connexion'] ? h(date('d/m/Y H:i', strtotime($c['derniere_connexion']))) : '—' ?></td>
          <td class="text-end">
            <div class="dropdown d-inline-block">
              <button class="btn btn-outline-light btn-sm dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></button>
              <ul class="dropdown-menu dropdown-menu-end" style="font-size:.85rem;min-width:240px">
                <li class="px-2 pt-1"><span class="small text-muted2"><?= h($c['ecole_nom']) ?></span></li>
                <li><hr class="dropdown-divider my-1"></li>
                <li>
                  <form method="post" class="px-2 py-1" onsubmit="return confirm('Réinitialiser le mot de passe de « <?= h($c['login']) ?> » ?');">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="op" value="reset_mdp">
                    <input type="hidden" name="id_etab" value="<?= $c['ecole_id'] ?>">
                    <input type="hidden" name="id_user" value="<?= $c['id_user'] ?>">
                    <div class="input-group input-group-sm">
                      <input name="pwd" type="text" class="form-control" placeholder="Nouveau mot de passe" required minlength="4" autocomplete="off">
                      <button class="btn btn-outline-warning">OK</button>
                    </div>
                  </form>
                </li>
                <li>
                  <form method="post" class="px-2 py-1">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="op" value="role">
                    <input type="hidden" name="id_etab" value="<?= $c['ecole_id'] ?>">
                    <input type="hidden" name="id_user" value="<?= $c['id_user'] ?>">
                    <div class="input-group input-group-sm">
                      <select name="role" class="form-select">
                        <?php foreach (assoc_roles_console() as $k => $v): ?><option value="<?= $k ?>" <?= $c['role'] === $k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?>
                      </select>
                      <button class="btn btn-outline-secondary">Rôle</button>
                    </div>
                  </form>
                </li>
                <li><hr class="dropdown-divider my-1"></li>
                <li>
                  <form method="post">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="op" value="<?= $c['actif'] ? 'desactiver' : 'activer' ?>">
                    <input type="hidden" name="id_etab" value="<?= $c['ecole_id'] ?>">
                    <input type="hidden" name="id_user" value="<?= $c['id_user'] ?>">
                    <button class="dropdown-item <?= $c['actif'] ? 'text-warning' : 'text-success' ?>">
                      <?= $c['actif'] ? 'Désactiver le compte' : 'Réactiver le compte' ?>
                    </button>
                  </form>
                </li>
                <li>
                  <form method="post" onsubmit="return confirm('Supprimer le compte de connexion « <?= h($c['login']) ?> » ? (la fiche personnel est conservée)');">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="op" value="supprimer">
                    <input type="hidden" name="id_etab" value="<?= $c['ecole_id'] ?>">
                    <input type="hidden" name="id_user" value="<?= $c['id_user'] ?>">
                    <button class="dropdown-item text-danger">Supprimer le compte</button>
                  </form>
                </li>
                <li><hr class="dropdown-divider my-1"></li>
                <li><a class="dropdown-item" href="<?= APP_URL ?>/association/entrer_ecole.php?id=<?= $c['ecole_id'] ?>&amp;mode=lecture"><i class="bi bi-box-arrow-in-right me-1"></i>Ouvrir l'école (lecture)</a></li>
              </ul>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$comptes['lignes']): ?><tr><td colspan="7" class="text-center text-muted2 py-3">Aucun compte pour ce filtre.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<?php if ($comptes['pages'] > 1): ?>
<div class="d-flex gap-1 mb-3">
  <?php for ($i = 1; $i <= $comptes['pages']; $i++): ?>
    <a class="btn btn-sm <?= $i === $comptes['page'] ? 'btn-primary' : 'btn-outline-light' ?>"
       href="?<?= h(http_build_query(array_merge($_GET, ['onglet' => 'comptes', 'p' => $i]))) ?>"><?= $i ?></a>
  <?php endfor; ?>
</div>
<?php endif; ?>

<!-- Créer un compte -->
<div class="asso-card" style="max-width:620px">
  <div class="fw-bold mb-2"><i class="bi bi-person-plus me-1"></i>Créer un compte de connexion</div>
  <form method="post" class="row g-2" id="form-creer-compte">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="op" value="creer">
    <div class="col-sm-6">
      <label class="form-label small">École</label>
      <select name="id_etab" id="cc_etab" class="form-select form-select-sm" required>
        <option value="">— Choisir —</option>
        <?php foreach ($ecoles as $e): ?><option value="<?= (int) $e['id'] ?>"><?= h($e['code'] . ' — ' . $e['nom']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-sm-6">
      <label class="form-label small">Membre du personnel (sans compte)</label>
      <select name="matricule_ens" id="cc_pers" class="form-select form-select-sm" required disabled>
        <option value="">— Choisir d'abord une école —</option>
      </select>
    </div>
    <div class="col-sm-6">
      <label class="form-label small">Identifiant</label>
      <input name="login" class="form-control form-control-sm font-monospace" required pattern="[A-Za-z0-9._-]{3,50}">
    </div>
    <div class="col-sm-6">
      <label class="form-label small">Mot de passe provisoire</label>
      <input name="pwd" type="text" class="form-control form-control-sm" required minlength="4" autocomplete="off">
    </div>
    <div class="col-12 mt-1">
      <button class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Créer le compte</button>
      <span class="small text-muted2 ms-2">La personne configurera ses questions et changera son mot de passe à la 1<sup>re</sup> connexion.</span>
    </div>
  </form>
</div>
<script>
(function () {
  var etab = document.getElementById('cc_etab'),
      pers = document.getElementById('cc_pers');
  if (!etab) return;
  etab.addEventListener('change', function () {
    pers.innerHTML = '<option value="">Chargement…</option>'; pers.disabled = true;
    if (!this.value) { pers.innerHTML = '<option value="">— Choisir d\'abord une école —</option>'; return; }
    fetch('<?= APP_URL ?>/association/personnel/personnel_libre_json.php?ec=' + encodeURIComponent(this.value))
      .then(function (r) { return r.json(); })
      .then(function (d) {
        pers.innerHTML = '<option value="">— Choisir —</option>';
        (d.agents || d || []).forEach(function (a) {
          var o = document.createElement('option');
          o.value = a.matricule_ens || a.matricule;
          o.textContent = (a.nom_ens || a.nom || '') + ' ' + (a.prenom_ens || a.prenom || '') + (a.id_fonction ? ' — ' + a.id_fonction : '');
          pers.appendChild(o);
        });
        pers.disabled = false;
        if (pers.options.length <= 1) pers.innerHTML = '<option value="">Tout le personnel a déjà un compte</option>';
      })
      .catch(function () { pers.innerHTML = '<option value="">Erreur de chargement</option>'; });
  });
})();
</script>

<?php endif; // onglet ?>
<?php asso_bas();
