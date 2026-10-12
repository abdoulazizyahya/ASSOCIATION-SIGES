<?php
// secondaire/commun/pages/enseignants/liste.php — annuaire du personnel (école
// secondaire). Simplifié par rapport à LAM_ABZ/pages/enseignants/index.php
// pour cette étape (pas encore d'export PDF dossier/attestation/prise de
// service — voir LAM_ABZ pour le modèle complet le moment venu).
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'FONDATEUR', 'CENSEUR']);

$q = trim($_GET['q'] ?? '');
$where = ''; $params = [];
if ($q !== '') {
    $where  = 'WHERE e.nom_ens LIKE ? OR e.prenom_ens LIKE ? OR e.matricule_ens LIKE ?';
    $params = ["%$q%", "%$q%", "%$q%"];
}
$enseignants = db_all("SELECT e.* FROM enseignant e $where ORDER BY e.nom_ens, e.prenom_ens", $params);

$titre_page = 'Enseignants';
require_once __DIR__ . '/../../../../noyau/layout/header.php';
?>

<div class="page-titre d-flex justify-content-between align-items-center">
  <div>
    <h4><i class="bi bi-person-badge me-1 text-primary"></i>Enseignants</h4>
    <div class="sub"><?= count($enseignants) ?> membre(s) du personnel</div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <?php $u_lot = APP_URL . '/secondaire/commun/pages/enseignants/pdf_dossier_lot.php' . ($q !== '' ? '?q=' . urlencode($q) : ''); ?>
    <button type="button" class="btn btn-outline-primary btn-sm"
            onclick="afficherApercu(<?= h(json_encode($u_lot)) ?>, <?= h(json_encode($q !== '' ? 'Dossiers administratifs — résultats de la recherche' : 'Dossiers administratifs — tous')) ?>, 'dossier_enseignant', 'portrait')">
      <i class="bi bi-files me-1"></i><?= $q !== '' ? 'Dossiers (résultats)' : 'Tous les dossiers' ?>
    </button>
    <a href="<?= APP_URL ?>/secondaire/commun/pages/enseignants/form.php" class="btn btn-primary btn-sm">
      <i class="bi bi-plus-lg me-1"></i>Nouveau membre
    </a>
  </div>
</div>

<?= flash_html() ?>

<div class="card mb-2">
  <div class="card-body py-2">
    <form method="get" class="d-flex gap-2">
      <input type="search" name="q" value="<?= h($q) ?>" placeholder="Nom, prénom, matricule…" class="form-control form-control-sm" style="max-width:320px">
      <button class="btn btn-outline-primary btn-sm"><i class="bi bi-search"></i></button>
      <?php if ($q): ?><a href="<?= APP_URL ?>/secondaire/commun/pages/enseignants/liste.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-lg"></i></a><?php endif; ?>
    </form>
  </div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-abz table-hover align-middle mb-0">
      <thead>
        <tr>
          <th>Matricule</th>
          <th>Nom et prénom</th>
          <th>Fonction / Grade</th>
          <th>Téléphone</th>
          <th>Email</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($enseignants)): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">
            <i class="bi bi-inbox" style="font-size:2rem;opacity:.3;display:block;margin-bottom:.4rem"></i>
            Aucun membre du personnel.
          </td></tr>
        <?php else: foreach ($enseignants as $e): ?>
          <tr>
            <td><span class="badge-code"><?= h((string) $e['matricule_ens']) ?></span></td>
            <td>
              <a href="<?= APP_URL ?>/secondaire/commun/pages/enseignants/voir.php?id=<?= (int) $e['matricule_ens'] ?>"
                 class="fw-semibold text-decoration-none" style="font-size:.82rem">
                <?= h(($e['civilite_ens'] ? $e['civilite_ens'] . ' ' : '') . mb_strtoupper($e['nom_ens']) . ' ' . ($e['prenom_ens'] ?? '')) ?>
              </a>
            </td>
            <td style="font-size:.78rem">
              <?= h($e['id_fonction'] ?: '—') ?>
              <?php if ($e['id_grade']): ?><br><span class="text-muted"><?= h($e['id_grade']) ?></span><?php endif; ?>
            </td>
            <td style="font-size:.78rem"><?= h($e['tel_ens'] ?: '—') ?></td>
            <td style="font-size:.78rem"><?= h($e['mail_ens'] ?: '—') ?></td>
            <td class="text-end">
              <a href="<?= APP_URL ?>/secondaire/commun/pages/enseignants/voir.php?id=<?= (int) $e['matricule_ens'] ?>"
                 class="btn btn-sm" style="background:#eef2ff;color:#1e4fd8;padding:3px 7px">
                <i class="bi bi-eye" style="font-size:.78rem"></i>
              </a>
              <a href="<?= APP_URL ?>/secondaire/commun/pages/enseignants/form.php?id=<?= (int) $e['matricule_ens'] ?>"
                 class="btn btn-sm btn-light" style="padding:3px 7px">
                <i class="bi bi-pencil" style="font-size:.78rem"></i>
              </a>
              <!-- Documents administratifs (portés d'ABZ_MBE) : aperçu avant impression -->
              <div class="dropdown d-inline">
                <button class="btn btn-sm btn-light dropdown-toggle" style="padding:3px 7px" data-bs-toggle="dropdown" title="Documents">
                  <i class="bi bi-file-earmark-text" style="font-size:.78rem"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end" style="font-size:.8rem">
                  <?php foreach (['attestation' => 'Attestation de présence', 'prise_service' => 'Prise de service',
                                  'reprise_service' => 'Reprise de service', 'dossier' => 'Dossier administratif'] as $t => $lib): ?>
                    <li><a class="dropdown-item" href="<?= APP_URL ?>/secondaire/commun/pages/enseignants/apercu.php?id=<?= urlencode((string) $e['matricule_ens']) ?>&type=<?= $t ?>"><?= h($lib) ?></a></li>
                  <?php endforeach; ?>
                </ul>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../../../../noyau/layout/footer.php'; ?>
