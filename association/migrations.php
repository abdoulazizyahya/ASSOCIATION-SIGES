<?php
// association/migrations.php — état du schéma de chaque école vs la
// dernière migration du dépôt, et application des migrations manquantes
// (avec sauvegarde de sécurité préalable). Superadmin uniquement.
// Équivalent UI de bd/assoc/migrer_toutes_ecoles.php.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
require_once __DIR__ . '/_layout.php';
exiger_superadmin_association();

$msg = ''; $err = ''; $detail = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    @set_time_limit(300);
    $backup = !isset($_POST['sans_backup']);
    $cibles = [];
    if (($_POST['op'] ?? '') === 'migrer_une') {
        $cibles = [(int) ($_POST['id'] ?? 0)];
    } elseif (($_POST['op'] ?? '') === 'migrer_tout') {
        $cibles = array_column(assoc_all("SELECT id FROM etablissement WHERE actif=1"), 'id');
    }
    $lignes = [];
    foreach ($cibles as $cid) {
        $r = assoc_migrer_ecole((int) $cid, $backup);
        $nom = assoc_val("SELECT code FROM etablissement WHERE id=?", [$cid]);
        $lignes[] = ($r['ok'] ? '✅ ' : '⚠️ ') . $nom . ' — ' . $r['message'];
        journaliser_action('migration_ecole', (int) $cid, ($r['ok'] ? 'OK ' : 'ECHEC ') . implode(',', $r['appliquees']));
    }
    $detail = implode("\n", $lignes);
    $msg = 'Traitement terminé.';
}

$etat = assoc_migrations_etat();

asso_haut('Migrations de schéma');
$csrf = csrf_generer();
?>
<?php if ($msg): ?>
  <div class="alert alert-success py-2 small"><?= h($msg) ?></div>
  <?php if ($detail): ?><pre class="asso-card small" style="white-space:pre-wrap"><?= h($detail) ?></pre><?php endif; ?>
<?php endif; ?>
<?php if ($err): ?><div class="alert alert-warning py-2 small"><?= h($err) ?></div><?php endif; ?>

<p class="small text-muted2">
  Dernière migration du dépôt : <strong>v<?= (int) $etat['vmax'] ?></strong>
  (<?= (int) $etat['nb_migrations'] ?> fichiers <span class="font-monospace">bd/migration_v*.sql</span>).
  Une <strong>sauvegarde de sécurité</strong> de la base est écrite dans
  <span class="font-monospace">bd/sauvegardes/</span> avant toute application.
</p>

<div class="asso-card p-0 mb-3">
  <div class="table-responsive">
  <table class="table table-dark table-sm mb-0 align-middle" style="font-size:.85rem">
    <thead><tr class="text-muted2"><th>École</th><th>Schéma</th><th>À appliquer</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($etat['ecoles'] as $e): ?>
        <tr class="<?= $e['actif'] ? '' : 'opacity-50' ?>">
          <td>
            <a href="<?= APP_URL ?>/association/etablissement.php?id=<?= (int) $e['id'] ?>" class="text-decoration-none"><?= h($e['nom']) ?></a>
            <span class="badge badge-soft ms-1"><?= h($e['code']) ?></span>
            <div class="small text-muted2 font-monospace"><?= h($e['db_name']) ?></div>
          </td>
          <td><?= $e['a_jour'] ? '<span class="text-success">v' . (int) $e['version'] . ' — à jour</span>'
                               : '<span class="text-warning">v' . (int) $e['version'] . '</span>' ?></td>
          <td class="small text-muted2">
            <?= $e['retard'] ? h(implode(', ', array_map(fn($v) => "v$v", $e['retard']))) : '—' ?>
          </td>
          <td class="text-end">
            <?php if (!$e['a_jour'] && $e['actif']): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Appliquer <?= count($e['retard']) ?> migration(s) à <?= h($e['code']) ?> ? Une sauvegarde sera faite avant.');">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="op" value="migrer_une">
              <input type="hidden" name="id" value="<?= (int) $e['id'] ?>">
              <button class="btn btn-primary btn-sm"><i class="bi bi-arrow-up-circle me-1"></i>Migrer</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<?php $en_retard = array_filter($etat['ecoles'], fn($e) => !$e['a_jour'] && $e['actif']); ?>
<?php if ($en_retard): ?>
<form method="post" class="d-flex align-items-center gap-3"
      onsubmit="return confirm('Migrer TOUTES les écoles actives en retard (<?= count($en_retard) ?>) ?');">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
  <input type="hidden" name="op" value="migrer_tout">
  <button class="btn btn-primary btn-sm"><i class="bi bi-arrow-up-circle me-1"></i>Migrer toutes les écoles en retard</button>
  <label class="small text-muted2"><input type="checkbox" name="sans_backup"> sans sauvegarde préalable (déconseillé)</label>
</form>
<?php else: ?>
  <div class="alert alert-success py-2 small"><i class="bi bi-check-circle me-1"></i>Toutes les écoles actives sont à jour.</div>
<?php endif; ?>
<?php asso_bas();
