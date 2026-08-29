<?php
// association/index.php — portail : liste des établissements de l'association
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
require_once __DIR__ . '/_layout.php';
exiger_membre_association();

$ecoles = assoc_all("SELECT * FROM etablissement ORDER BY actif DESC, nom");

asso_haut('Établissements de l\'association');
?>
<div class="d-flex flex-wrap gap-2 mb-3">
  <a href="<?= APP_URL ?>/association/niu/index.php" class="btn btn-outline-light btn-sm">
    <i class="bi bi-person-vcard me-1"></i>Registre NIU
  </a>
  <a href="<?= APP_URL ?>/association/personnel/liste.php" class="btn btn-outline-light btn-sm">
    <i class="bi bi-people me-1"></i>Personnel
  </a>
</div>

<div class="row g-3">
  <?php foreach ($ecoles as $e): ?>
    <div class="col-12 col-md-6">
      <div class="ecole-card h-100 <?= $e['actif'] ? '' : 'opacity-50' ?>">
        <div class="d-flex align-items-start gap-2">
          <div class="flex-grow-1">
            <div class="fw-bold"><?= h($e['nom']) ?></div>
            <div class="small text-muted2">
              <span class="badge badge-soft me-1"><?= h($e['code']) ?></span>
              <?= h($e['ville'] ?? '') ?>
              <?php if (!$e['actif']): ?><span class="text-warning ms-1">(inactive)</span><?php endif; ?>
            </div>
            <div class="small text-muted2 mt-1"><i class="bi bi-database me-1"></i><?= h($e['db_name']) ?></div>
          </div>
        </div>
        <?php if ($e['actif']): ?>
        <div class="mt-3 d-flex gap-2">
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
