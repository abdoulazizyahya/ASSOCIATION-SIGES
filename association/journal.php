<?php
// association/journal.php — journal d'audit des membres (connexions,
// visites d'écoles, créations/modifications/suppressions d'établissements,
// export/import/vidage de bases). Superadmin uniquement.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
require_once __DIR__ . '/_layout.php';
exiger_superadmin_association();

// Purge manuelle (POST) — retire les entrées de plus de 12 mois.
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['op'] ?? '') === 'purge') {
    csrf_verifier();
    $n = assoc_journal_purger(12);
    journaliser_action('journal_purge', null, "$n entrées > 12 mois");
    $msg = "$n entrée(s) de plus de 12 mois supprimée(s).";
}

$f = [
    'membre' => (int) ($_GET['membre'] ?? 0) ?: null,
    'etab'   => (int) ($_GET['etab'] ?? 0) ?: null,
    'action' => trim($_GET['action'] ?? '') ?: null,
    'depuis' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['depuis'] ?? '') ? $_GET['depuis'] : null,
    'jusqua' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['jusqua'] ?? '') ? $_GET['jusqua'] : null,
];
$page = max(1, (int) ($_GET['page'] ?? 1));
$j    = assoc_journal($f, $page, 50);

$membres = assoc_all("SELECT id, login FROM membre ORDER BY login");
$ecoles  = assoc_all("SELECT id, code, nom FROM etablissement ORDER BY nom");
$actions = assoc_journal_actions();

// Libellés lisibles des actions connues.
$LABELS = [
    'connexion_membre'       => 'Connexion membre',
    'visite_ecole'           => 'Entrée dans une école',
    'sortie_ecole'           => 'Sortie d\'une école',
    'etablissement_creation' => 'Création d\'établissement',
    'etablissement_modifie'  => 'Modification d\'établissement',
    'etablissement_supprime' => 'Suppression d\'établissement',
    'ecole_bd_export'        => 'Export de base',
    'ecole_bd_import'        => 'Import de base',
    'ecole_bd_import_echec'  => 'Import de base (échec)',
    'ecole_bd_vidage'        => 'Vidage de base',
    'ecole_bd_creation'      => 'Création de la base',
    'ecole_bd_restauration'  => 'Restauration de base',
    'niu_frappe'             => 'Attribution NIU',
    'niu_creation'           => 'Création NIU',
    'niu_transfert'          => 'Transfert NIU',
    'niu_reintegration'      => 'Réintégration NIU',
    'niu_sortie'             => 'Sortie NIU du réseau',
    'niu_fusion'             => 'Fusion de NIU',
    'personnel_affectation'  => 'Affectation de personnel',
    'membre_creation'        => 'Création de membre',
    'membre_modifie'         => 'Modification de membre',
    'membre_mdp'             => 'Réinitialisation mot de passe',
    'membre_acces'           => 'Changement d\'accès membre',
    'membre_2fa_on'          => 'Activation 2FA',
    'membre_2fa_off'         => 'Désactivation 2FA',
    'migration_ecole'        => 'Migration de schéma',
    'journal_purge'          => 'Purge du journal',
];
$lib = fn($a) => $LABELS[$a] ?? $a;

// Conserve les filtres dans les liens de pagination.
$qs = fn(array $extra) => http_build_query(array_filter(array_merge([
    'membre' => $f['membre'], 'etab' => $f['etab'], 'action' => $f['action'],
    'depuis' => $f['depuis'], 'jusqua' => $f['jusqua'],
], $extra), fn($v) => $v !== null && $v !== '' && $v !== 0));

asso_haut('Journal d\'audit');
?>
<?php if ($msg): ?><div class="alert alert-success py-2 small"><?= h($msg) ?></div><?php endif; ?>

<form method="get" class="asso-card mb-3">
  <div class="row g-2 align-items-end">
    <div class="col-6 col-md-3">
      <label class="form-label small">Membre</label>
      <select name="membre" class="form-select form-select-sm">
        <option value="">— tous —</option>
        <?php foreach ($membres as $m): ?>
          <option value="<?= (int) $m['id'] ?>" <?= $f['membre'] == $m['id'] ? 'selected' : '' ?>><?= h($m['login']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-3">
      <label class="form-label small">École</label>
      <select name="etab" class="form-select form-select-sm">
        <option value="">— toutes —</option>
        <?php foreach ($ecoles as $e): ?>
          <option value="<?= (int) $e['id'] ?>" <?= $f['etab'] == $e['id'] ? 'selected' : '' ?>><?= h($e['code'] . ' — ' . $e['nom']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small">Action</label>
      <select name="action" class="form-select form-select-sm">
        <option value="">— toutes —</option>
        <?php foreach ($actions as $a): ?>
          <option value="<?= h($a) ?>" <?= $f['action'] === $a ? 'selected' : '' ?>><?= h($lib($a)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-3 col-md-2">
      <label class="form-label small">Du</label>
      <input type="date" name="depuis" value="<?= h($f['depuis'] ?? '') ?>" class="form-control form-control-sm">
    </div>
    <div class="col-3 col-md-2">
      <label class="form-label small">Au</label>
      <input type="date" name="jusqua" value="<?= h($f['jusqua'] ?? '') ?>" class="form-control form-control-sm">
    </div>
    <div class="col-12">
      <button class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1"></i>Filtrer</button>
      <a href="<?= APP_URL ?>/association/journal.php" class="btn btn-outline-light btn-sm">Réinitialiser</a>
      <span class="small text-muted2 ms-2"><?= (int) $j['total'] ?> entrée(s)</span>
    </div>
  </div>
</form>

<div class="asso-card p-0">
  <div class="table-responsive">
  <table class="table table-sm mb-0 align-middle" style="font-size:.82rem">
    <thead><tr class="text-muted2">
      <th>Date</th><th>Membre</th><th>Action</th><th>École</th><th>Détail</th><th>IP</th>
    </tr></thead>
    <tbody>
      <?php foreach ($j['lignes'] as $l): ?>
        <tr>
          <td class="text-nowrap text-muted2"><?= h(date('d/m/Y H:i', strtotime($l['date']))) ?></td>
          <td><?= h($l['membre_login'] ?? '—') ?></td>
          <td><span class="badge badge-soft"><?= h($lib($l['action'])) ?></span></td>
          <td><?= $l['etab_code'] ? '<span class="font-monospace">' . h($l['etab_code']) . '</span>' : '<span class="text-muted2">—</span>' ?></td>
          <td class="text-muted2"><?= h($l['cible'] ?? '') ?></td>
          <td class="text-muted2 font-monospace small"><?= h($l['ip'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$j['lignes']): ?>
        <tr><td colspan="6" class="text-center text-muted2 py-3">Aucune entrée pour ces critères.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<?php if ($j['pages'] > 1): ?>
  <div class="d-flex gap-2 mt-2 align-items-center small">
    <?php if ($j['page'] > 1): ?>
      <a class="btn btn-outline-light btn-sm" href="?<?= h($qs(['page' => $j['page'] - 1])) ?>">← Précédent</a>
    <?php endif; ?>
    <span class="text-muted2">Page <?= (int) $j['page'] ?> / <?= (int) $j['pages'] ?></span>
    <?php if ($j['page'] < $j['pages']): ?>
      <a class="btn btn-outline-light btn-sm" href="?<?= h($qs(['page' => $j['page'] + 1])) ?>">Suivant →</a>
    <?php endif; ?>
  </div>
<?php endif; ?>

<form method="post" class="mt-3" onsubmit="return confirm('Supprimer définitivement les entrées de plus de 12 mois ?');">
  <input type="hidden" name="csrf" value="<?= h(csrf_generer()) ?>">
  <input type="hidden" name="op" value="purge">
  <button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash3 me-1"></i>Purger les entrées &gt; 12 mois</button>
</form>
<?php asso_bas();
