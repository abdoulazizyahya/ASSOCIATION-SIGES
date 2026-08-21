<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_role(['DIRECTEUR']);

$q      = trim($_GET['q'] ?? '');
$statut = in_array($_GET['statut'] ?? '', ['actif', 'inactif', 'tous'], true) ? $_GET['statut'] : 'actif';

$where  = ['1=1'];
$params = [];
if ($q !== '') {
    $where[] = "(nom_ens LIKE ? OR prenom_ens LIKE ? OR mat_ens LIKE ?)";
    $like = "%$q%";
    array_push($params, $like, $like, $like);
}
if ($statut !== 'tous') {
    $where[] = "COALESCE(statut_ens,'actif') = ?";
    $params[] = $statut;
}

$enseignants = db_all(
    "SELECT * FROM enseignant WHERE " . implode(' AND ', $where) . " ORDER BY id_fonction, nom_ens", $params
);

$es_partiel = isset($_GET['partiel']);
if (!$es_partiel) {
    $titre_page = 'Enseignant(e)s';
    require_once __DIR__ . '/../../layout/header.php';
} else {
    header('Content-Type: text/html; charset=utf-8');
}
?>

<div id="enseignants-zone">

<div class="page-titre">
  <div>
    <h4><i class="bi bi-person-badge me-1 text-primary"></i>Personnel</h4>
    <div class="sub"><?= count($enseignants) ?> membre(s) du personnel</div>
  </div>
  <a href="<?= APP_URL ?>/pages/enseignants/form.php" class="btn btn-primary btn-sm">
    <i class="bi bi-person-plus me-1"></i>Nouveau membre du personnel
  </a>
</div>

<div class="card mb-2">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end" data-ajax-nav-form>
      <div class="col-md-4">
        <div class="input-group input-group-sm">
          <span class="input-group-text"><i class="bi bi-search"></i></span>
          <input type="text" name="q" class="form-control" placeholder="Nom, matricule…" value="<?= h($q) ?>">
        </div>
      </div>
      <div class="col-md-3">
        <select name="statut" class="form-select form-select-sm" data-ajax-nav-auto>
          <option value="actif" <?= $statut === 'actif' ? 'selected' : '' ?>>Actifs seulement</option>
          <option value="inactif" <?= $statut === 'inactif' ? 'selected' : '' ?>>Inactifs seulement</option>
          <option value="tous" <?= $statut === 'tous' ? 'selected' : '' ?>>Tous</option>
        </select>
      </div>
      <div class="col-auto d-flex gap-1">
        <button class="btn btn-primary btn-sm"><i class="bi bi-search"></i></button>
        <?php if ($q): ?><a data-ajax-nav href="<?= APP_URL ?>/pages/enseignants/liste.php?statut=<?= $statut ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-lg"></i></a><?php endif; ?>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-abz table-hover align-middle mb-0">
      <thead>
        <tr>
          <th>Nom</th><th>Fonction</th><th>Grade</th><th>Sexe</th><th>Téléphone</th><th>Statut</th><th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($enseignants)): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">
            <i class="bi bi-inbox" style="font-size:2rem;opacity:.3;display:block;margin-bottom:.4rem"></i>Aucun membre du personnel trouvé.
          </td></tr>
        <?php else: foreach ($enseignants as $e):
          $actif = ($e['statut_ens'] ?? 'actif') === 'actif';
          $grade = $e['id_grade'] ? db_val("SELECT libelle_grade FROM grade_enseignant WHERE code_grade=?", [$e['id_grade']]) : null;
        ?>
          <tr>
            <td>
              <div class="d-flex align-items-center gap-2">
                <div class="avatar"><?= h(mb_strtoupper(mb_substr($e['nom_ens'], 0, 1))) ?></div>
                <a href="<?= APP_URL ?>/pages/enseignants/voir.php?mat=<?= (int) $e['matricule_ens'] ?>"
                   class="fw-semibold text-decoration-none" style="font-size:.82rem">
                  <?= h(mb_strtoupper($e['nom_ens'])) ?> <?= h($e['prenom_ens'] ?? '') ?>
                </a>
              </div>
            </td>
            <td><span class="badge-code"><?= h(libelle_role($e['id_fonction'] ?? '')) ?></span></td>
            <td style="font-size:.78rem"><?= h($grade ?: '—') ?></td>
            <td style="font-size:.78rem"><?= h($e['sexe_ens'] ?: '—') ?></td>
            <td style="font-size:.78rem"><?= h($e['tel_ens'] ?: '—') ?></td>
            <td>
              <span class="badge" style="background:<?= $actif ? '#dcfce7' : '#fee2e2' ?>;color:<?= $actif ? '#166534' : '#991b1b' ?>;font-size:.7rem">
                <?= $actif ? 'Actif' : 'Inactif' ?>
              </span>
            </td>
            <td class="text-end">
              <a href="<?= APP_URL ?>/pages/enseignants/voir.php?mat=<?= (int) $e['matricule_ens'] ?>"
                 class="btn btn-sm" style="background:#eef2ff;color:#1e4fd8;padding:3px 7px" title="Voir">
                <i class="bi bi-eye" style="font-size:.78rem"></i>
              </a>
              <a href="<?= APP_URL ?>/pages/enseignants/form.php?mat=<?= (int) $e['matricule_ens'] ?>"
                 class="btn btn-sm" style="background:#eef7ee;color:#15803d;padding:3px 7px" title="Modifier">
                <i class="bi bi-pencil-square" style="font-size:.78rem"></i>
              </a>
              <form method="post" action="<?= APP_URL ?>/pages/enseignants/statut.php?mat=<?= (int) $e['matricule_ens'] ?>" class="d-inline"
                    data-ajax-post-form onsubmit="return confirm('<?= $actif ? 'Désactiver' : 'Réactiver' ?> ce membre du personnel ?')">
                <?= csrf_champ() ?>
                <button type="submit" class="btn btn-sm" style="background:<?= $actif ? '#fef2f2' : '#eef7ee' ?>;color:<?= $actif ? '#dc2626' : '#15803d' ?>;padding:3px 7px" title="<?= $actif ? 'Désactiver' : 'Réactiver' ?>">
                  <i class="bi bi-<?= $actif ? 'x-circle' : 'check-circle' ?>" style="font-size:.78rem"></i>
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

</div><!-- /#enseignants-zone -->
<?php if ($es_partiel) exit; // rien de plus dans une réponse AJAX partielle. ?>

<?php
$ajax_zone_id = 'enseignants-zone'; // voir layout/footer.php — initAjaxZone() y est appelé
require_once __DIR__ . '/../../layout/footer.php';
