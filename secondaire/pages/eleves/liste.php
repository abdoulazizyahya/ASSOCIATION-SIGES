<?php
// secondaire/pages/eleves/liste.php — liste des élèves (école secondaire).
// Adapté de LAM_ABZ/pages/eleves/liste.php aux helpers SIGES ; simplifié
// pour cette 1ʳᵉ étape (pas encore d'export PDF/cartes scolaires — voir
// LAM_ABZ pour le modèle complet quand ce sera le tour de ces écrans).
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_connexion();

$annee    = get_annee_active();
$id_annee = (int) ($annee['id'] ?? 0);

$q         = trim($_GET['q'] ?? '');
$id_classe = (int) ($_GET['classe'] ?? 0);
$id_serie  = (int) ($_GET['serie'] ?? 0);
require_once __DIR__ . '/../../../pages/eleves/_photos_lib.php';
// Onglet « Photos par classe » (commun primaire/secondaire,
// pages/eleves/_eleves_photos.php) — mêmes rôles que la fiche élève.
$peut_gerer = in_array(role_connecte(), photos_roles(), true)
           && !(function_exists('est_lecture_seule') && est_lecture_seule());
$statut    = in_array($_GET['statut'] ?? '', ['desactive', 'photos'], true) ? $_GET['statut'] : 'actif';
if ($statut === 'photos' && !$peut_gerer) $statut = 'actif';
$vue_photos = $statut === 'photos';
$page      = max(1, (int) ($_GET['page'] ?? 1));
$pp        = 25;
$offset    = ($page - 1) * $pp;

$where  = ['e.statut = ?'];
$params = [$vue_photos ? 'actif' : $statut];
if ($q !== '') {
    $where[]  = '(e.matricule LIKE ? OR e.niu LIKE ? OR e.nom LIKE ? OR e.prenom LIKE ?)';
    $like     = "%$q%";
    $params   = array_merge($params, [$like, $like, $like, $like]);
}
if ($id_classe) { $where[] = 'i.id_classe = ?'; $params[] = $id_classe; }
if ($id_serie)  { $where[] = 'i.id_serie = ?';  $params[] = $id_serie; }
$sql_where = 'WHERE ' . implode(' AND ', $where);

$total = (int) db_val(
    "SELECT COUNT(*) FROM eleve e
     LEFT JOIN inscription i ON i.id_eleve=e.id AND i.id_annee=?
     LEFT JOIN classe c ON c.id=i.id_classe
     $sql_where",
    array_merge([$id_annee], $params)
);
$eleves = db_all(
    "SELECT e.*, c.designation AS classe, s.libelle AS serie
     FROM eleve e
     LEFT JOIN inscription i ON i.id_eleve=e.id AND i.id_annee=?
     LEFT JOIN classe c ON c.id=i.id_classe
     LEFT JOIN serie s ON s.id=i.id_serie
     $sql_where
     ORDER BY e.nom, e.prenom
     LIMIT $pp OFFSET $offset",
    array_merge([$id_annee], $params)
);
$classes = db_all(
    "SELECT c.id, c.designation, (
        SELECT COUNT(*) FROM inscription i JOIN eleve e ON e.id=i.id_eleve
        WHERE i.id_classe=c.id AND i.id_annee=? AND e.statut='actif'
     ) AS nb
     FROM classe c WHERE c.archivee=0 ORDER BY c.ordre, c.designation",
    [$id_annee]
);
$series = db_all("SELECT * FROM serie ORDER BY libelle");
$nb_pages = max(1, (int) ceil($total / $pp));
$base = APP_URL . '/secondaire/pages/eleves/liste.php?' . http_build_query(array_filter([
    'q' => $q, 'classe' => $id_classe ?: null, 'serie' => $id_serie ?: null,
    'statut' => $statut !== 'actif' ? $statut : null,
]));

$titre_page = 'Élèves';
require_once __DIR__ . '/../../../layout/header.php';
?>

<div class="page-titre d-flex justify-content-between align-items-center">
  <div>
    <h4><i class="bi bi-people me-1 text-primary"></i>Élèves</h4>
    <div class="sub"><?= $total ?> élève(s) <?= $statut === 'actif' ? 'actif(s)' : 'désactivé(s)' ?></div>
  </div>
  <div class="d-flex gap-2">
    <?php if (in_array(role_connecte(), ['ADMIN', 'PROVISEUR', 'FONDATEUR', 'SECRETAIRE'], true)): ?>
    <a href="<?= APP_URL ?>/secondaire/pages/eleves/import.php" class="btn btn-outline-primary btn-sm" data-ajax-nav>
      <i class="bi bi-file-earmark-excel me-1"></i>Importer
    </a>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/secondaire/pages/eleves/form.php" class="btn btn-primary btn-sm">
      <i class="bi bi-plus-lg me-1"></i>Nouvel élève
    </a>
  </div>
</div>

<?= flash_html() ?>

<ul class="nav nav-tabs mb-2">
  <?php $qs_tab = array_filter(['q' => $q, 'classe' => $id_classe ?: null, 'serie' => $id_serie ?: null]); ?>
  <li class="nav-item">
    <a class="nav-link <?= $statut === 'actif' ? 'active' : '' ?>" href="?<?= http_build_query($qs_tab) ?>">
      <i class="bi bi-check-circle me-1"></i>Actifs
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $statut === 'desactive' ? 'active' : '' ?>"
       href="?<?= http_build_query(array_merge($qs_tab, ['statut' => 'desactive'])) ?>">
      <i class="bi bi-slash-circle me-1"></i>Désactivés
    </a>
  </li>
  <?php if ($peut_gerer): ?>
  <li class="nav-item">
    <a class="nav-link <?= $vue_photos ? 'active' : '' ?>"
       href="?<?= http_build_query(array_merge($qs_tab, ['statut' => 'photos'])) ?>">
      <i class="bi bi-camera me-1"></i>Photos par classe
    </a>
  </li>
  <?php endif; ?>
</ul>

<div class="card mb-2">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-12 col-md-5">
        <label class="form-label">Recherche</label>
        <div class="input-group input-group-sm">
          <span class="input-group-text"><i class="bi bi-search"></i></span>
          <input type="text" name="q" class="form-control" placeholder="Nom, NIU, matricule…" value="<?= h($q) ?>">
        </div>
      </div>
      <div class="col-md-3">
        <label class="form-label">Classe</label>
        <select name="classe" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="">— Toutes les classes —</option>
          <?php foreach ($classes as $c): ?>
            <option value="<?= (int) $c['id'] ?>" <?= $id_classe === (int) $c['id'] ? 'selected' : '' ?>>
              <?= h($c['designation']) ?> (<?= (int) $c['nb'] ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label">Série / LV2</label>
        <select name="serie" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="">— Toutes —</option>
          <?php foreach ($series as $s): ?>
            <option value="<?= (int) $s['id'] ?>" <?= $id_serie === (int) $s['id'] ? 'selected' : '' ?>>
              <?= h($s['libelle']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($statut !== 'actif'): ?><input type="hidden" name="statut" value="<?= h($statut) ?>"><?php endif; ?>
      <div class="col-auto d-flex gap-1">
        <button class="btn btn-primary btn-sm"><i class="bi bi-search"></i></button>
        <?php if ($q || $id_classe || $id_serie): ?>
          <a href="<?= APP_URL ?>/secondaire/pages/eleves/liste.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-lg"></i></a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<?php if ($vue_photos): ?>
  <?php require __DIR__ . '/../../../pages/eleves/_eleves_photos.php'; ?>
<?php else: ?>

<!-- Barre d'actions groupées (démasquée par JS dès qu'une case est cochée) -->
<div class="card mb-2 d-none" id="barreMasse">
  <div class="card-body py-2 d-flex flex-wrap align-items-center gap-2">
    <span class="fw-semibold" style="font-size:.82rem"><span id="nbSelection">0</span> sélectionné(s)</span>
    <?php if ($statut === 'actif'): ?>
      <button type="button" class="btn btn-sm" style="background:#fff3cd;color:#856404" onclick="masseDesactiver()">
        <i class="bi bi-toggle-on me-1"></i>Désactiver
      </button>
    <?php else: ?>
      <button type="button" class="btn btn-sm" style="background:#d1fae5;color:#065f46" onclick="masseReactiver()">
        <i class="bi bi-toggle-off me-1"></i>Réactiver
      </button>
    <?php endif; ?>
    <div class="d-flex align-items-center gap-1">
      <select id="selClasseCible" class="form-select form-select-sm" style="width:auto;max-width:220px">
        <option value="">— Transférer vers —</option>
        <?php foreach ($classes as $c): ?>
          <option value="<?= (int) $c['id'] ?>"><?= h($c['designation']) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="button" class="btn btn-sm btn-outline-primary" onclick="masseTransferer()">
        <i class="bi bi-arrow-left-right me-1"></i>Transférer
      </button>
    </div>
    <div class="d-flex align-items-center gap-1">
      <select id="selSerieCible" class="form-select form-select-sm" style="width:auto;max-width:200px">
        <option value="">— Aucune série —</option>
        <?php foreach ($series as $s): ?>
          <option value="<?= (int) $s['id'] ?>"><?= h($s['libelle']) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="button" class="btn btn-sm btn-outline-primary" onclick="masseAffecterSerie()">
        <i class="bi bi-translate me-1"></i>Affecter série/LV2
      </button>
    </div>
    <button type="button" class="btn btn-sm btn-outline-danger ms-auto" onclick="masseSupprimer()">
      <i class="bi bi-trash me-1"></i>Supprimer définitivement
    </button>
  </div>
</div>
<form id="formMasseAction" method="post">
  <?= csrf_champ() ?>
  <input type="hidden" name="q" value="<?= h($q) ?>">
  <input type="hidden" name="classe" value="<?= (int) $id_classe ?>">
  <input type="hidden" name="statut" value="<?= h($statut) ?>">
</form>

<div class="card">
  <div class="table-responsive">
    <table class="table table-abz table-hover align-middle mb-0">
      <thead>
        <tr>
          <th style="width:28px"><input type="checkbox" onchange="toggleTous(this)"></th>
          <th style="width:34px">#</th>
          <th>Élève</th>
          <th>Matricule</th>
          <th>Sexe</th>
          <th>Classe</th>
          <th>Série/LV2</th>
          <th>Date naiss.</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($eleves)): ?>
          <tr><td colspan="9" class="text-center text-muted py-4">
            <i class="bi bi-inbox" style="font-size:2rem;opacity:.3;display:block;margin-bottom:.4rem"></i>
            Aucun élève trouvé.
          </td></tr>
        <?php else: $no = $offset + 1; foreach ($eleves as $e): ?>
          <tr>
            <td><input type="checkbox" class="chk-eleve" value="<?= (int) $e['id'] ?>" onchange="majBarreMasse()"></td>
            <td class="text-muted" style="font-size:.72rem"><?= $no++ ?></td>
            <td>
              <a href="<?= APP_URL ?>/secondaire/pages/eleves/voir.php?id=<?= (int) $e['id'] ?>"
                 class="fw-semibold text-decoration-none" style="font-size:.82rem">
                <?= h(mb_strtoupper($e['nom']) . ' ' . ($e['prenom'] ?? '')) ?>
              </a>
            </td>
            <td><span class="badge-code"><?= h($e['matricule']) ?></span></td>
            <td><?= $e['sexe'] === 'F' ? '<span class="badge-f">F</span>' : '<span class="badge-m">M</span>' ?></td>
            <td style="font-size:.78rem"><?= h($e['classe'] ?? '—') ?></td>
            <td style="font-size:.78rem"><?= $e['serie'] ? h($e['serie']) : '<span class="text-muted">—</span>' ?></td>
            <td style="font-size:.78rem;color:#6b7280"><?= h(date_fr($e['date_naiss'])) ?></td>
            <td>
              <div class="d-flex justify-content-end gap-1">
                <a href="<?= APP_URL ?>/secondaire/pages/eleves/voir.php?id=<?= (int) $e['id'] ?>"
                   class="btn btn-sm" style="background:#eef2ff;color:#1e4fd8;padding:3px 7px">
                  <i class="bi bi-eye" style="font-size:.78rem"></i>
                </a>
                <a href="<?= APP_URL ?>/secondaire/pages/eleves/form.php?id=<?= (int) $e['id'] ?>"
                   class="btn btn-sm btn-light" style="padding:3px 7px">
                  <i class="bi bi-pencil" style="font-size:.78rem"></i>
                </a>
                <?php if ($statut === 'actif'): ?>
                  <a href="<?= APP_URL ?>/secondaire/pages/eleves/statut.php?id=<?= (int) $e['id'] ?>&csrf=<?= csrf_generer() ?>"
                     class="btn btn-sm" style="background:#fff3cd;color:#856404;padding:3px 7px"
                     title="Désactiver" onclick="return confirm('Désactiver cet élève ?')">
                    <i class="bi bi-toggle-on" style="font-size:.78rem"></i>
                  </a>
                <?php else: ?>
                  <a href="<?= APP_URL ?>/secondaire/pages/eleves/statut.php?id=<?= (int) $e['id'] ?>&csrf=<?= csrf_generer() ?>"
                     class="btn btn-sm" style="background:#d1fae5;color:#065f46;padding:3px 7px"
                     title="Réactiver" onclick="return confirm('Réactiver cet élève ?')">
                    <i class="bi bi-toggle-off" style="font-size:.78rem"></i>
                  </a>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($nb_pages > 1): ?>
  <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
    <div class="text-muted" style="font-size:.75rem">Page <?= $page ?> / <?= $nb_pages ?> · <?= $total ?> élève(s)</div>
    <?= pagination_html($page, $nb_pages, $base) ?>
  </div>
<?php endif; ?>

<?php endif; // fin liste vs. onglet « Photos par classe » ?>

<?php if ($peut_gerer) require __DIR__ . '/../../../pages/eleves/_photos_outils.php'; ?>

<script>
// Actions groupées (sélection multiple) — secondaire/pages/eleves/liste.php.
function getCheckedIds() {
  return Array.prototype.slice.call(document.querySelectorAll('.chk-eleve:checked')).map(function (c) { return c.value; });
}
function majBarreMasse() {
  var n = getCheckedIds().length;
  document.getElementById('nbSelection').textContent = n;
  document.getElementById('barreMasse').classList.toggle('d-none', n === 0);
}
function toggleTous(cb) {
  document.querySelectorAll('.chk-eleve').forEach(function (el) { el.checked = cb.checked; });
  majBarreMasse();
}
function masseSubmit(actionUrl, extra) {
  var ids = getCheckedIds();
  if (!ids.length) { alert('Sélectionnez au moins un élève.'); return; }
  var f = document.getElementById('formMasseAction');
  f.action = actionUrl;
  f.querySelectorAll('input[name="ids[]"]').forEach(function (el) { el.remove(); });
  ids.forEach(function (id) {
    var inp = document.createElement('input');
    inp.type = 'hidden'; inp.name = 'ids[]'; inp.value = id;
    f.appendChild(inp);
  });
  Object.keys(extra || {}).forEach(function (k) {
    var el = f.querySelector('[name="' + k + '"]');
    if (!el) { el = document.createElement('input'); el.type = 'hidden'; el.name = k; f.appendChild(el); }
    el.value = extra[k];
  });
  f.submit();
}
function masseDesactiver() {
  if (confirm('Désactiver ' + getCheckedIds().length + ' élève(s) sélectionné(s) ?')) {
    masseSubmit('<?= APP_URL ?>/secondaire/pages/eleves/statut_masse.php', { vers: 'desactive' });
  }
}
function masseReactiver() {
  if (confirm('Réactiver ' + getCheckedIds().length + ' élève(s) sélectionné(s) ?')) {
    masseSubmit('<?= APP_URL ?>/secondaire/pages/eleves/statut_masse.php', { vers: 'actif' });
  }
}
function masseSupprimer() {
  if (confirm('Supprimer DÉFINITIVEMENT ' + getCheckedIds().length + ' élève(s) ? Action IRRÉVERSIBLE : leurs notes, absences, paiements et inscriptions seront aussi effacés définitivement. Préférez « Désactiver » si vous n\'êtes pas certain.')) {
    masseSubmit('<?= APP_URL ?>/secondaire/pages/eleves/supprimer_masse.php', {});
  }
}
function masseTransferer() {
  var sel = document.getElementById('selClasseCible');
  if (!sel.value) { alert('Choisissez une classe de destination.'); return; }
  if (confirm('Transférer ' + getCheckedIds().length + ' élève(s) vers ' + sel.options[sel.selectedIndex].text.trim() + ' ?')) {
    masseSubmit('<?= APP_URL ?>/secondaire/pages/eleves/transferer_masse.php', { id_classe_cible: sel.value });
  }
}
function masseAffecterSerie() {
  var sel = document.getElementById('selSerieCible');
  var libelle = sel.value ? sel.options[sel.selectedIndex].text.trim() : 'Aucune série';
  if (confirm('Affecter la série « ' + libelle + ' » à ' + getCheckedIds().length + ' élève(s) ?')) {
    masseSubmit('<?= APP_URL ?>/secondaire/pages/eleves/affecter_serie_masse.php', { id_serie_cible: sel.value });
  }
}
</script>

<?php require_once __DIR__ . '/../../../layout/footer.php'; ?>
