<?php
// association/etablissement_demarrage.php — checklist « école opérationnelle »
// pour une école donnée : ce qui est fait, ce qui manque, avec les liens
// d'action. Lisible par tout membre ; les liens d'action supposent le
// superadmin (les pages cibles le vérifient).
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
require_once __DIR__ . '/_layout.php';
exiger_membre_association();

$id = (int) ($_GET['id'] ?? 0);
$e  = $id ? assoc_one("SELECT * FROM etablissement WHERE id=?", [$id]) : null;
if (!$e) { asso_haut('Établissement introuvable'); asso_bas(); exit; }

$items   = etablissement_checklist($id);
$faits   = array_filter($items, fn($i) => $i['fait'] === true);
$total   = count($items);
$restant = array_filter($items, fn($i) => $i['fait'] !== true);

asso_haut('Démarrage — ' . $e['nom']);
?>
<a href="<?= APP_URL ?>/association/etablissement.php?id=<?= (int) $e['id'] ?>" class="small text-decoration-none">← Fiche</a>

<div class="asso-card mt-2" style="max-width:640px">
  <div class="d-flex align-items-center justify-content-between mb-2">
    <div class="fw-bold">
      <?php if (!$restant): ?>
        <span class="text-success"><i class="bi bi-check-circle-fill me-1"></i>École opérationnelle</span>
      <?php else: ?>
        <span class="text-warning"><i class="bi bi-list-check me-1"></i><?= count($faits) ?> / <?= $total ?> — <?= count($restant) ?> point(s) à traiter</span>
      <?php endif; ?>
    </div>
    <span class="badge badge-soft"><?= h($e['code']) ?></span>
  </div>

  <ul class="list-unstyled mb-0">
    <?php foreach ($items as $i): ?>
      <li class="d-flex align-items-center gap-2 py-2 border-bottom" style="border-color:#23304d!important">
        <?php if ($i['fait'] === true): ?>
          <i class="bi bi-check-circle-fill text-success"></i>
        <?php elseif ($i['fait'] === null): ?>
          <i class="bi bi-question-circle text-muted2"></i>
        <?php else: ?>
          <i class="bi bi-circle text-warning"></i>
        <?php endif; ?>
        <div class="flex-grow-1">
          <?= h($i['label']) ?>
          <?php if ($i['aide']): ?><span class="small text-muted2">· <?= h($i['aide']) ?></span><?php endif; ?>
        </div>
        <?php if (!empty($i['lien']) && $i['fait'] !== true): ?>
          <a href="<?= h($i['lien']) ?>" class="btn btn-outline-light btn-sm"><?= h($i['lien_txt']) ?></a>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>

  <div class="small text-muted2 mt-3">
    Après ces étapes : le fondateur et le directeur se connectent sur
    <span class="font-monospace"><?= h(APP_URL) ?>/login.php</span> en choisissant « <?= h($e['nom']) ?> »
    et finalisent la configuration dans l'école (Configurations, classes, matières, barèmes).
  </div>
</div>
<?php asso_bas();
