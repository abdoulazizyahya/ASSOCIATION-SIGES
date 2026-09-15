<?php
// secondaire/pages/classes/liste.php — liste des classes (école secondaire).
// Porté de LAM_ABZ/pages/classes/liste.php, URLs rebranchées sous
// secondaire/. niveau/section_classe/filiere/serie ne sont pas encore
// peuplées pour une école secondaire neuve (voir commit fb21462) : les
// filtres/colonnes correspondants restent vides tant que ces tables de
// référence n'ont pas été saisies manuellement ou provisionnées (étape
// suivante du plan) — sans effet sur la création/consultation des classes
// elles-mêmes (designation/effectif_max/ordre suffisent).
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'CENSEUR']);

$annee    = get_annee_active();
$id_annee = (int) ($annee['id'] ?? 0);

$f_section = trim($_GET['section'] ?? '');
$f_niveau  = trim($_GET['niveau'] ?? '');

$where  = ['c.archivee=0'];
$params = [$id_annee, $id_annee, $id_annee];
if ($f_section) { $where[] = 'c.libelle_section=?'; $params[] = $f_section; }
if ($f_niveau)  { $where[] = 'c.code_niveau=?'; $params[] = $f_niveau; }
$sql_where = implode(' AND ', $where);

$classes = db_all(
    "SELECT c.*,
       n.libelle_niv,
       s.libelle AS serie_libelle,
       f.libelle AS filiere_libelle,
       (SELECT COUNT(*) FROM inscription i JOIN eleve e ON e.id=i.id_eleve
        WHERE i.id_classe=c.id AND i.id_annee=? AND e.statut='actif') AS nb_eleves,
       (SELECT SUM(e2.sexe='M') FROM inscription i2 JOIN eleve e2 ON e2.id=i2.id_eleve
        WHERE i2.id_classe=c.id AND i2.id_annee=? AND e2.statut='actif') AS nb_m,
       (SELECT SUM(e3.sexe='F') FROM inscription i3 JOIN eleve e3 ON e3.id=i3.id_eleve
        WHERE i3.id_classe=c.id AND i3.id_annee=? AND e3.statut='actif') AS nb_f
     FROM classe c
     LEFT JOIN niveau n ON n.code_niveau=c.code_niveau
     LEFT JOIN serie s  ON s.id=c.id_serie
     LEFT JOIN filiere f ON f.id=c.id_filiere
     WHERE $sql_where
     ORDER BY c.libelle_section, c.ordre, c.designation",
    $params
);

$sections = db_all("SELECT * FROM section_classe ORDER BY libelle_section");
$niv_params = [];
$niv_where  = 'c.archivee=0';
if ($f_section) { $niv_where .= ' AND c.libelle_section=?'; $niv_params[] = $f_section; }
$niveaux_dispo = db_all(
    "SELECT DISTINCT n.code_niveau, n.libelle_niv
     FROM classe c JOIN niveau n ON n.code_niveau=c.code_niveau
     WHERE $niv_where ORDER BY n.ordre_niveau",
    $niv_params
);

$titre_page = 'Classes';
require_once __DIR__ . '/../../../layout/header.php';
?>

<div class="page-titre d-flex justify-content-between align-items-center">
  <div>
    <h4><i class="bi bi-door-open me-1 text-primary"></i>Classes</h4>
    <div class="sub"><?= count($classes) ?> classe(s)</div>
  </div>
  <a href="<?= APP_URL ?>/secondaire/pages/classes/form.php" class="btn btn-primary btn-sm">
    <i class="bi bi-plus-lg me-1"></i>Nouvelle classe
  </a>
</div>

<?= flash_html() ?>

<div class="card mb-2">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-3">
        <label class="form-label">Section</label>
        <select name="section" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="">— Toutes —</option>
          <?php foreach ($sections as $s): ?>
            <option value="<?= h($s['libelle_section']) ?>" <?= $f_section === $s['libelle_section'] ? 'selected' : '' ?>>
              <?= h($s['libelle_section']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Niveau</label>
        <select name="niveau" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="">— Tous —</option>
          <?php foreach ($niveaux_dispo as $n): ?>
            <option value="<?= h($n['code_niveau']) ?>" <?= $f_niveau === $n['code_niveau'] ? 'selected' : '' ?>>
              <?= h($n['libelle_niv']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($f_section || $f_niveau): ?>
        <div class="col-auto">
          <a href="<?= APP_URL ?>/secondaire/pages/classes/liste.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-lg"></i></a>
        </div>
      <?php endif; ?>
    </form>
  </div>
</div>

<?php
$par_section = [];
foreach ($classes as $c) {
    $sec = $c['libelle_section'] ?: '(Sans section)';
    $par_section[$sec][] = $c;
}
?>

<?php foreach ($par_section as $sec => $items): ?>
<div class="mb-3">
  <div class="section-titre mb-1">
    <i class="bi bi-collection me-1"></i>Section <?= h($sec) ?>
    <span class="text-muted" style="font-weight:400;font-size:.75rem"> — <?= count($items) ?> classe(s)</span>
  </div>
  <div class="card">
    <div class="table-responsive">
      <table class="table table-abz table-hover align-middle mb-0">
        <thead>
          <tr>
            <th>Désignation</th>
            <th>Niveau</th>
            <th>Filière</th>
            <th>Série</th>
            <th class="text-center">G</th>
            <th class="text-center">F</th>
            <th class="text-center fw-bold">Total</th>
            <th class="text-center text-muted">Max</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($items as $c): ?>
          <tr>
            <td class="fw-semibold"><?= h($c['designation']) ?></td>
            <td style="font-size:.78rem"><?= $c['libelle_niv'] ? h($c['libelle_niv']) : '<span class="text-muted">—</span>' ?></td>
            <td style="font-size:.78rem"><?= $c['filiere_libelle'] ? h($c['filiere_libelle']) : '<span class="text-muted">—</span>' ?></td>
            <td style="font-size:.78rem"><?= $c['serie_libelle'] ? h($c['serie_libelle']) : '<span class="text-muted">—</span>' ?></td>
            <td class="text-center"><span class="badge-m"><?= (int) $c['nb_m'] ?></span></td>
            <td class="text-center"><span class="badge-f"><?= (int) $c['nb_f'] ?></span></td>
            <td class="text-center fw-bold"><?= (int) $c['nb_eleves'] ?></td>
            <td class="text-center text-muted" style="font-size:.78rem"><?= (int) $c['effectif_max'] ?></td>
            <td class="text-end">
              <a href="<?= APP_URL ?>/secondaire/pages/eleves/liste.php?classe=<?= (int) $c['id'] ?>"
                 class="btn btn-sm" style="background:#eef2ff;color:#1e4fd8;padding:3px 7px" title="Élèves">
                <i class="bi bi-people" style="font-size:.78rem"></i>
              </a>
              <a href="<?= APP_URL ?>/secondaire/pages/classes/form.php?id=<?= (int) $c['id'] ?>"
                 class="btn btn-sm btn-light" style="padding:3px 7px" title="Modifier">
                <i class="bi bi-pencil" style="font-size:.78rem"></i>
              </a>
              <a href="<?= APP_URL ?>/secondaire/pages/classes/supprimer.php?id=<?= (int) $c['id'] ?>&csrf=<?= csrf_generer() ?>"
                 class="btn btn-sm btn-light text-danger" style="padding:3px 7px" title="Archiver"
                 onclick="return confirm('Archiver cette classe ?')">
                <i class="bi bi-archive" style="font-size:.78rem"></i>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endforeach; ?>

<?php if (empty($classes)): ?>
  <div class="alert alert-light text-muted text-center py-4">
    <i class="bi bi-inbox" style="font-size:2rem;display:block;opacity:.3;margin-bottom:.5rem"></i>
    Aucune classe trouvée.
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../../layout/footer.php'; ?>
