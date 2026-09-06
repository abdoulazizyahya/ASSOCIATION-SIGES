<?php
// association/dashboard.php — cockpit superadmin : effectifs consolidés
// de toutes les écoles + état de santé (base joignable, schéma à jour,
// année active, directeur affecté, fraîcheur de la sauvegarde).
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
require_once __DIR__ . '/_layout.php';
exiger_superadmin_association();

$c   = assoc_cockpit();
$tot = $c['total'];

function _il_y_a(?int $ts): string {
    if ($ts === null) return 'jamais';
    $d = time() - $ts;
    if ($d < 3600)  return 'il y a ' . max(1, (int) ($d / 60)) . ' min';
    if ($d < 86400) return 'il y a ' . (int) ($d / 3600) . ' h';
    return 'il y a ' . (int) ($d / 86400) . ' j';
}

asso_haut('Tableau de bord');
?>
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3"><div class="asso-card text-center">
    <div class="h3 fw-bold mb-0"><?= (int) $tot['eleves'] ?></div>
    <div class="small text-muted2">Élèves (toutes écoles)</div>
    <div class="small text-muted2"><?= (int) $tot['g'] ?> G / <?= (int) $tot['f'] ?> F</div>
  </div></div>
  <div class="col-6 col-md-3"><div class="asso-card text-center">
    <div class="h3 fw-bold mb-0"><?= (int) $tot['ecoles_actives'] ?><span class="text-muted2 fs-6">/<?= (int) $tot['ecoles'] ?></span></div>
    <div class="small text-muted2">Écoles actives</div>
  </div></div>
  <div class="col-6 col-md-3"><div class="asso-card text-center">
    <div class="h3 fw-bold mb-0"><?= (int) $tot['classes'] ?></div>
    <div class="small text-muted2">Classes</div>
  </div></div>
  <div class="col-6 col-md-3"><div class="asso-card text-center">
    <div class="h3 fw-bold mb-0"><?= (int) $tot['enseignants'] ?></div>
    <div class="small text-muted2">Enseignant(e)s</div>
  </div></div>
</div>

<?php if ($tot['alertes'] > 0): ?>
  <div class="alert alert-warning py-2 small">
    <i class="bi bi-exclamation-triangle me-1"></i>
    <strong><?= (int) $tot['alertes'] ?></strong> point(s) d'attention sur l'ensemble des écoles — voir la colonne « État ».
  </div>
<?php else: ?>
  <div class="alert alert-success py-2 small"><i class="bi bi-check-circle me-1"></i>Toutes les écoles actives sont opérationnelles.</div>
<?php endif; ?>

<div class="asso-card p-0">
  <div class="table-responsive">
  <table class="table table-dark table-sm mb-0 align-middle" style="font-size:.83rem">
    <thead>
      <tr class="text-muted2">
        <th>École</th>
        <th class="text-end">Élèves</th>
        <th class="text-end">Classes</th>
        <th class="text-end">Ens.</th>
        <th>Schéma</th>
        <th>Sauvegarde</th>
        <th>Taille</th>
        <th>État</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($c['ecoles'] as $r): $e = $r['etab']; ?>
        <tr class="<?= $e['actif'] ? '' : 'opacity-50' ?>">
          <td>
            <a href="<?= APP_URL ?>/association/etablissement.php?id=<?= (int) $e['id'] ?>" class="text-decoration-none">
              <?= h($e['nom']) ?>
            </a>
            <span class="badge badge-soft ms-1"><?= h($e['code']) ?></span>
            <?php if (!$e['actif']): ?><span class="text-warning small ms-1">(inactive)</span><?php endif; ?>
            <div class="small text-muted2 font-monospace"><?= h($e['db_name']) ?></div>
          </td>
          <td class="text-end">
            <?php if ($r['joignable']): ?>
              <?= (int) $r['eleves'] ?>
              <div class="small text-muted2"><?= h((string) ($r['annee'] ?? '—')) ?></div>
            <?php else: ?><span class="text-muted2">—</span><?php endif; ?>
          </td>
          <td class="text-end"><?= $r['joignable'] ? (int) $r['classes'] : '—' ?></td>
          <td class="text-end"><?= $r['joignable'] ? (int) $r['enseignants'] : '—' ?></td>
          <td>
            <?php if ($r['version_ok']): ?>
              <span class="text-success">v<?= (int) $r['version'] ?></span>
            <?php else: ?>
              <span class="text-warning">v<?= (int) $r['version'] ?> ⟶ v<?= (int) $c['migration_max'] ?></span>
            <?php endif; ?>
          </td>
          <td class="<?= ($r['sauvegarde'] !== null && $r['sauvegarde'] > time() - 172800) ? 'text-muted2' : 'text-warning' ?>">
            <?= h(_il_y_a($r['sauvegarde'])) ?>
          </td>
          <td class="text-muted2"><?= $r['db_mo'] > 0 ? h((string) $r['db_mo']) . ' Mo' : '—' ?></td>
          <td>
            <?php if (!$r['alertes']): ?>
              <span class="text-success"><i class="bi bi-check-circle"></i></span>
            <?php else: ?>
              <?php foreach ($r['alertes'] as $a): ?>
                <span class="badge bg-warning text-dark d-block mb-1" style="font-weight:500"><?= h($a) ?></span>
              <?php endforeach; ?>
              <a href="<?= APP_URL ?>/association/etablissement_demarrage.php?id=<?= (int) $e['id'] ?>" class="small">checklist →</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$c['ecoles']): ?>
        <tr><td colspan="8" class="text-center text-muted2 py-3">Aucun établissement.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<div class="small text-muted2 mt-2">
  Migrations de schéma : <span class="font-monospace">php bd/assoc/migrer_toutes_ecoles.php --dry-run</span> ·
  Sauvegardes : <span class="font-monospace">php bd/assoc/sauvegarder_toutes_ecoles.php --gzip</span>
</div>
<?php asso_bas();
