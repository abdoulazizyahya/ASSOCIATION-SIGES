<?php
// association/journal.php — journal d'audit du réseau : connexions,
// déconnexions, échecs et actions sensibles de TOUS les comptes (membres
// de l'association ET comptes d'école), avec appareil et localisation.
// Superadmin uniquement. Voir bd/lib/audit.php + connexion_assoc.php.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../bd/lib/audit_vue.php';
exiger_superadmin_association();

// Purge manuelle (POST) — retire les entrées au-delà de la rétention.
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['op'] ?? '') === 'purge') {
    csrf_verifier();
    $mois = defined('AUDIT_RETENTION_MOIS') ? (int) AUDIT_RETENTION_MOIS : 12;
    $n = audit_journal_purger($mois);
    journaliser_action('journal_purge', null, "$n entrées > $mois mois");
    $msg = "$n entrée(s) de plus de $mois mois supprimée(s).";
}

$f = [
    'id_etab'   => (int) ($_GET['etab'] ?? 0) ?: null,
    'acteur'    => trim($_GET['acteur'] ?? '') ?: null,
    'type'      => in_array($_GET['type'] ?? '', ['membre', 'user', 'inconnu'], true) ? $_GET['type'] : null,
    'role'      => trim($_GET['role'] ?? '') ?: null,
    'evenement' => trim($_GET['evenement'] ?? '') ?: null,
    'action'    => trim($_GET['action'] ?? '') ?: null,
    'appareil'  => trim($_GET['appareil'] ?? '') ?: null,
    'pays'      => trim($_GET['pays'] ?? '') ?: null,
    'depuis'    => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['depuis'] ?? '') ? $_GET['depuis'] : null,
    'jusqua'    => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['jusqua'] ?? '') ? $_GET['jusqua'] : null,
];
$page = max(1, (int) ($_GET['page'] ?? 1));
$j    = audit_journal($f, $page, 60);

$filtres = audit_journal_filtres();
$ecoles  = assoc_all("SELECT id, code, nom FROM etablissement ORDER BY nom");

$qs = fn(array $extra) => http_build_query(array_filter(array_merge([
    'etab' => $f['id_etab'], 'acteur' => $f['acteur'], 'type' => $f['type'], 'role' => $f['role'],
    'evenement' => $f['evenement'], 'action' => $f['action'], 'appareil' => $f['appareil'],
    'pays' => $f['pays'], 'depuis' => $f['depuis'], 'jusqua' => $f['jusqua'],
], $extra), fn($v) => $v !== null && $v !== '' && $v !== 0));

asso_haut('Journal d\'audit');
?>
<?php if ($msg): ?><div class="alert alert-success py-2 small"><?= h($msg) ?></div><?php endif; ?>

<form method="get" class="asso-card mb-3">
  <div class="row g-2 align-items-end">
    <div class="col-6 col-md-3">
      <label class="form-label small">Acteur (login ou nom)</label>
      <input type="text" name="acteur" value="<?= h($f['acteur'] ?? '') ?>" class="form-control form-control-sm" placeholder="ex. directeur">
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small">Type de compte</label>
      <select name="type" class="form-select form-select-sm">
        <option value="">— tous —</option>
        <option value="user"    <?= $f['type'] === 'user' ? 'selected' : '' ?>>Compte d'école</option>
        <option value="membre"  <?= $f['type'] === 'membre' ? 'selected' : '' ?>>Membre association</option>
        <option value="inconnu" <?= $f['type'] === 'inconnu' ? 'selected' : '' ?>>Inconnu (échec)</option>
      </select>
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small">Rôle</label>
      <select name="role" class="form-select form-select-sm">
        <option value="">— tous —</option>
        <?php foreach ($filtres['role'] as $r): ?>
          <option value="<?= h($r) ?>" <?= $f['role'] === $r ? 'selected' : '' ?>><?= h(audit_vue_role($r)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small">Évènement</label>
      <select name="evenement" class="form-select form-select-sm">
        <option value="">— tous —</option>
        <?php foreach (['connexion', 'connexion_echec', 'deconnexion', 'action'] as $e): ?>
          <option value="<?= $e ?>" <?= $f['evenement'] === $e ? 'selected' : '' ?>><?= h(audit_vue_evenement($e)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-3">
      <label class="form-label small">Action</label>
      <select name="action" class="form-select form-select-sm">
        <option value="">— toutes —</option>
        <?php foreach ($filtres['action'] as $a): ?>
          <option value="<?= h($a) ?>" <?= $f['action'] === $a ? 'selected' : '' ?>><?= h(audit_vue_action($a)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-3">
      <label class="form-label small">École</label>
      <select name="etab" class="form-select form-select-sm">
        <option value="">— toutes —</option>
        <?php foreach ($ecoles as $e): ?>
          <option value="<?= (int) $e['id'] ?>" <?= $f['id_etab'] == $e['id'] ? 'selected' : '' ?>><?= h($e['code'] . ' — ' . $e['nom']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small">Appareil</label>
      <select name="appareil" class="form-select form-select-sm">
        <option value="">— tous —</option>
        <?php foreach ($filtres['appareil'] as $a): ?>
          <option value="<?= h($a) ?>" <?= $f['appareil'] === $a ? 'selected' : '' ?>><?= h(ucfirst($a)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if ($filtres['pays']): ?>
    <div class="col-6 col-md-2">
      <label class="form-label small">Pays</label>
      <select name="pays" class="form-select form-select-sm">
        <option value="">— tous —</option>
        <?php foreach ($filtres['pays'] as $p): ?>
          <option value="<?= h($p) ?>" <?= $f['pays'] === $p ? 'selected' : '' ?>><?= h($p) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
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
      <th>Date</th><th>Acteur</th><th>Rôle</th><th>École</th>
      <th>Évènement / Action</th><th>Appareil</th><th>Localisation</th><th>IP</th>
    </tr></thead>
    <tbody>
      <?php foreach ($j['lignes'] as $l): ?>
        <tr>
          <td class="text-nowrap text-muted2"><?= h(date('d/m/Y H:i', strtotime($l['date']))) ?></td>
          <td><?= h($l['acteur_login'] ?? '—') ?></td>
          <td><?= h(audit_vue_role($l['role'] ?? '')) ?></td>
          <td><?= $l['etab_code'] ? '<span class="font-monospace">' . h($l['etab_code']) . '</span>' : '<span class="text-muted2">—</span>' ?></td>
          <td><?= audit_vue_evenement_badge($l) ?></td>
          <td><?= audit_vue_appareil($l) ?></td>
          <td class="text-muted2"><?= audit_vue_localisation($l) ?></td>
          <td class="text-muted2 font-monospace" style="font-size:.72rem"><?= h($l['ip'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$j['lignes']): ?>
        <tr><td colspan="8" class="text-center text-muted2 py-3">Aucune entrée pour ces critères.</td></tr>
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

<?php $mois = defined('AUDIT_RETENTION_MOIS') ? (int) AUDIT_RETENTION_MOIS : 12; ?>
<form method="post" class="mt-3" onsubmit="return confirm('Supprimer définitivement les entrées de plus de <?= $mois ?> mois ?');">
  <input type="hidden" name="csrf" value="<?= h(csrf_generer()) ?>">
  <input type="hidden" name="op" value="purge">
  <button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash3 me-1"></i>Purger les entrées &gt; <?= $mois ?> mois</button>
</form>
<?php asso_bas();
