<?php
// association/dashboard.php — cockpit superadmin : effectifs consolidés
// de toutes les écoles + état de santé (base joignable, schéma à jour,
// année active, directeur affecté, fraîcheur de la sauvegarde).
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../noyau/connexion.php';
require_once __DIR__ . '/../noyau/fonctions.php';
require_once __DIR__ . '/_layout.php';
exiger_superadmin_association();
require_once __DIR__ . '/../bd/lib/sauvegarde_auto.php';

// Sauvegarde complète à la demande (même contenu que la sauvegarde hebdomadaire automatique)
$rapport_sauv = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sauvegarder_tout') {
    csrf_verifier();
    $rapport_sauv = sauvegarde_auto_executer();
    journaliser_action('sauvegarde_auto', null, 'manuelle : ' . (preg_match('/=== (\d+ OK, \d+ KO)/', $rapport_sauv, $m) ? $m[1] : 'voir rapport'));
}

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

<?php $der_sauv = sauvegarde_auto_derniere(); $sauv_due = sauvegarde_auto_due(); ?>
<div class="asso-card mb-3 d-flex flex-wrap align-items-center gap-2 py-2">
  <i class="bi bi-database-check fs-5 <?= $sauv_due ? 'text-warning' : 'text-success' ?>"></i>
  <div class="small"><strong>Sauvegarde complète</strong> (annuaire + toutes les écoles) :
    <?= $der_sauv ? 'dernière le ' . date('d/m/Y à H:i', $der_sauv) . ' (' . _il_y_a($der_sauv) . ')' : 'aucune pour l\'instant' ?>.
    <span class="text-muted2">Automatique chaque semaine, lancée en arrière-plan à l'ouverture de ce tableau de bord ; 14 dernières conservées.</span></div>
  <form method="post" class="ms-auto m-0"><?= csrf_champ() ?><input type="hidden" name="action" value="sauvegarder_tout">
    <button class="btn btn-sm btn-outline-primary" onclick="this.disabled=true;this.form.submit();this.innerHTML='Sauvegarde en cours…'"><i class="bi bi-save me-1"></i>Sauvegarder maintenant</button></form>
</div>
<?php if ($rapport_sauv !== null): ?><pre class="asso-card small mb-3" style="white-space:pre-wrap"><?= h($rapport_sauv) ?></pre><?php endif; ?>

<div class="asso-card p-0">
  <div class="table-responsive">
  <table class="table table-sm mb-0 align-middle" style="font-size:.83rem">
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
            <a href="<?= APP_URL ?>/association/ecole_bd_sauvegarder.php?id=<?= (int) $e['id'] ?>"
               class="text-decoration-none" title="Sauvegarder maintenant"
               style="color:inherit"><?= h(_il_y_a($r['sauvegarde'])) ?> <i class="bi bi-clock" style="font-size:.7rem;opacity:.6"></i></a>
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
if ($rapport_sauv === null) sauvegarde_auto_planifier();   // hebdomadaire, après l'envoi de la page
