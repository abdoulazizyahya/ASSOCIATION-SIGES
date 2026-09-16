<?php
// association/index.php — portail : liste des établissements de l'association
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
require_once __DIR__ . '/_layout.php';
exiger_membre_association();

$ecoles = assoc_all("SELECT * FROM etablissement ORDER BY actif DESC, nom");
$superadmin   = est_superadmin_association();
$proprietaire = est_proprietaire_association();

asso_haut('Établissements de l\'association');
?>
<?php if ($superadmin): ?>
<div class="d-flex flex-wrap gap-2 mb-3">
  <a href="<?= APP_URL ?>/association/etablissement_nouveau.php" class="btn btn-primary btn-sm">
    <i class="bi bi-plus-lg me-1"></i>Nouvel établissement
  </a>
</div>
<?php endif; ?>

<div class="row g-3">
  <?php foreach ($ecoles as $e): ?>
    <div class="col-12 col-md-6">
      <?php $logo_url = assoc_ecole_logo_url($e['logo'] ?? null); ?>
      <div class="ecole-card h-100 position-relative <?= $e['actif'] ? '' : 'opacity-50' ?>">
        <div class="d-flex align-items-start gap-3">
          <div class="flex-grow-1">
            <a href="<?= APP_URL ?>/association/etablissement.php?id=<?= (int) $e['id'] ?>"
               class="fw-bold text-decoration-none stretched-link">
              <?= h($e['nom']) ?>
            </a>
            <div class="small text-muted2">
              <span class="badge badge-soft me-1"><?= h($e['code']) ?></span>
              <?= h($e['ville'] ?? '') ?>
              <?php if (!$e['actif']): ?><span class="text-warning ms-1">(inactive)</span><?php endif; ?>
            </div>
            <?php if ($proprietaire): ?>
              <div class="small text-muted2 mt-1"><i class="bi bi-database me-1"></i><?= h($e['db_name']) ?></div>
            <?php endif; ?>
            <div class="small mt-1 fst-italic" style="color:#dc2626">
              <?= $e['type_enseignement'] === 'secondaire' ? 'Secondaire' : 'Primaire' ?>
            </div>
          </div>
          <div class="flex-shrink-0 d-flex align-items-center justify-content-center"
               style="width:96px;height:96px;border-radius:12px;overflow:hidden;background:#fff;border:1px solid var(--border)">
            <?php if ($logo_url): ?>
              <img src="<?= h($logo_url) ?>" alt="Logo <?= h($e['code']) ?>"
                   style="max-width:100%;max-height:100%;object-fit:contain">
            <?php else: ?>
              <span class="fw-bold text-muted2" style="font-size:1rem"><?= h(mb_substr($e['sigle'] ?: $e['code'], 0, 5)) ?></span>
            <?php endif; ?>
          </div>
        </div>
        <?php if ($e['actif']): ?>
        <div class="mt-3 d-flex gap-2 position-relative" style="z-index:2">
          <a href="<?= APP_URL ?>/association/etablissement.php?id=<?= (int) $e['id'] ?>"
             class="btn btn-outline-light btn-sm">
            <i class="bi bi-bar-chart me-1"></i>Fiche
          </a>
          <a href="<?= APP_URL ?>/association/entrer_ecole.php?id=<?= (int) $e['id'] ?>"
             class="btn btn-primary btn-sm">
            <i class="bi bi-box-arrow-in-right me-1"></i>Ouvrir (lecture seule)
          </a>
        </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$ecoles): ?>
    <div class="col-12"><div class="asso-card text-muted2">Aucun établissement enregistré.</div></div>
  <?php endif; ?>
</div>
<?php asso_bas();
