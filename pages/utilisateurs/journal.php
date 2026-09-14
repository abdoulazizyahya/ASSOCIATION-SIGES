<?php
// pages/utilisateurs/journal.php — Journal d'audit de l'établissement.
//  Le Directeur / Fondateur consulte les connexions et actions sensibles
//  de SON école uniquement (directeur, enseignants, secrétaire, agent
//  financier…) : qui, quand, depuis quel appareil et quelle localisation.
//  Données centralisées dans promeducam_assoc.journal_audit — voir
//  bd/lib/audit.php + connexion_assoc.php::audit_journal().
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/../../bd/lib/audit_vue.php';
exiger_role(['DIRECTEUR', 'FONDATEUR']);

$id_etab = null;
if (function_exists('ecole_courante')) $id_etab = ecole_courante()['id'] ?? null;
if ($id_etab === null) $id_etab = $_SESSION['ecole']['id'] ?? null;
$id_etab = $id_etab !== null ? (int) $id_etab : null;

$dispo = (function_exists('annuaire_dispo') && annuaire_dispo()) && $id_etab !== null;

// Nommée $crit (et non $f) : layout/header.php, inclus plus bas en
// require_once DANS CETTE MÊME PORTÉE (pas une fonction), utilise déjà $f
// comme variable de boucle interne (nom de fichier de menu) — un $f ici
// aurait été écrasé par une simple chaîne après le require, provoquant
// plus loin un `$f['role']` = "accès à un offset de type string sur une
// string" (TypeError fatal, bug réel constaté le 15/09/2026 : le select
// Rôle plantait toute la page).
$crit = [
    'acteur'    => trim($_GET['acteur'] ?? '') ?: null,
    'role'      => trim($_GET['role'] ?? '') ?: null,
    'evenement' => trim($_GET['evenement'] ?? '') ?: null,
    'action'    => trim($_GET['action'] ?? '') ?: null,
    'appareil'  => trim($_GET['appareil'] ?? '') ?: null,
    'depuis'    => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['depuis'] ?? '') ? $_GET['depuis'] : null,
    'jusqua'    => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['jusqua'] ?? '') ? $_GET['jusqua'] : null,
];
$page = max(1, (int) ($_GET['page'] ?? 1));
$j    = $dispo ? audit_journal($crit, $page, 60, $id_etab)
              : ['lignes' => [], 'total' => 0, 'page' => 1, 'pages' => 1];

// Valeurs de filtre restreintes à cette école.
$filtres = ['role' => [], 'action' => [], 'appareil' => []];
if ($dispo) {
    foreach (audit_journal(['evenement' => null], 1, 1000, $id_etab)['lignes'] as $l) {
        if (!empty($l['role']))        $filtres['role'][$l['role']] = 1;
        if (!empty($l['action']))      $filtres['action'][$l['action']] = 1;
        if (!empty($l['ua_appareil'])) $filtres['appareil'][$l['ua_appareil']] = 1;
    }
    foreach ($filtres as &$v) { $v = array_keys($v); sort($v); }
    unset($v);
}

$qs = fn(array $extra) => http_build_query(array_filter(array_merge([
    'acteur' => $crit['acteur'], 'role' => $crit['role'], 'evenement' => $crit['evenement'],
    'action' => $crit['action'], 'appareil' => $crit['appareil'], 'depuis' => $crit['depuis'], 'jusqua' => $crit['jusqua'],
], $extra), fn($v) => $v !== null && $v !== ''));

$titre_page = 'Journal d\'audit';
require_once __DIR__ . '/../../layout/header.php';
?>

<div class="page-titre">
  <h4><i class="bi bi-shield-check me-1 text-primary"></i>Journal d'audit de l'établissement</h4>
  <div class="sub">Connexions et actions sensibles des comptes de cette école — <?= (int) $j['total'] ?> entrée(s)</div>
</div>

<?php if (!$dispo): ?>
  <div class="alert alert-warning">Le journal d'audit n'est pas disponible (annuaire association absent).</div>
<?php else: ?>

<div class="card mb-2">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-6 col-md-3">
        <label class="form-label">Acteur (login ou nom)</label>
        <input type="text" name="acteur" value="<?= h($crit['acteur'] ?? '') ?>" class="form-control form-control-sm">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Rôle</label>
        <select name="role" class="form-select form-select-sm">
          <option value="">— tous —</option>
          <?php foreach ($filtres['role'] as $r): ?>
            <option value="<?= h($r) ?>" <?= $crit['role'] === $r ? 'selected' : '' ?>><?= h(audit_vue_role($r)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Évènement</label>
        <select name="evenement" class="form-select form-select-sm">
          <option value="">— tous —</option>
          <?php foreach (['connexion', 'connexion_echec', 'deconnexion', 'action'] as $e): ?>
            <option value="<?= $e ?>" <?= $crit['evenement'] === $e ? 'selected' : '' ?>><?= h(audit_vue_evenement($e)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label">Action</label>
        <select name="action" class="form-select form-select-sm">
          <option value="">— toutes —</option>
          <?php foreach ($filtres['action'] as $a): ?>
            <option value="<?= h($a) ?>" <?= $crit['action'] === $a ? 'selected' : '' ?>><?= h(audit_vue_action($a)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Appareil</label>
        <select name="appareil" class="form-select form-select-sm">
          <option value="">— tous —</option>
          <?php foreach ($filtres['appareil'] as $a): ?>
            <option value="<?= h($a) ?>" <?= $crit['appareil'] === $a ? 'selected' : '' ?>><?= h(ucfirst($a)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-3 col-md-2">
        <label class="form-label">Du</label>
        <input type="date" name="depuis" value="<?= h($crit['depuis'] ?? '') ?>" class="form-control form-control-sm">
      </div>
      <div class="col-3 col-md-2">
        <label class="form-label">Au</label>
        <input type="date" name="jusqua" value="<?= h($crit['jusqua'] ?? '') ?>" class="form-control form-control-sm">
      </div>
      <div class="col-12">
        <button class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1"></i>Filtrer</button>
        <a href="<?= APP_URL ?>/pages/utilisateurs/journal.php" class="btn btn-outline-secondary btn-sm">Réinitialiser</a>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="table-responsive">
  <table class="table table-sm table-hover mb-0 align-middle" style="font-size:.85rem">
    <thead class="table-light"><tr>
      <th>Date</th><th>Acteur</th><th>Rôle</th><th>Évènement / Action</th>
      <th>Appareil</th><th>Localisation</th><th>IP</th>
    </tr></thead>
    <tbody>
      <?php foreach ($j['lignes'] as $l): ?>
        <tr>
          <td class="text-nowrap text-muted"><?= h(date('d/m/Y H:i', strtotime($l['date']))) ?></td>
          <td>
            <?= h($l['acteur_login'] ?? '—') ?>
            <?php if (!empty($l['acteur_nom'])): ?><span class="text-muted d-block" style="font-size:.75rem"><?= h($l['acteur_nom']) ?></span><?php endif; ?>
          </td>
          <td><?= h(audit_vue_role($l['role'] ?? '')) ?></td>
          <td><?= audit_vue_evenement_badge($l) ?></td>
          <td><?= audit_vue_appareil($l) ?></td>
          <td class="text-muted"><?= audit_vue_localisation($l) ?></td>
          <td class="text-muted font-monospace" style="font-size:.75rem"><?= h($l['ip'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$j['lignes']): ?>
        <tr><td colspan="7" class="text-center text-muted py-3">Aucune entrée pour ces critères.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<?php if ($j['pages'] > 1): ?>
  <div class="d-flex gap-2 mt-2 align-items-center small">
    <?php if ($j['page'] > 1): ?>
      <a class="btn btn-outline-secondary btn-sm" href="?<?= h($qs(['page' => $j['page'] - 1])) ?>">← Précédent</a>
    <?php endif; ?>
    <span class="text-muted">Page <?= (int) $j['page'] ?> / <?= (int) $j['pages'] ?></span>
    <?php if ($j['page'] < $j['pages']): ?>
      <a class="btn btn-outline-secondary btn-sm" href="?<?= h($qs(['page' => $j['page'] + 1])) ?>">Suivant →</a>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php endif; /* $dispo */ ?>

<?php require_once __DIR__ . '/../../layout/footer.php'; ?>
