<?php
// association/versions.php — Recalage des versions de schéma (propriétaire
// seul). Équivalent UI de bd/assoc/recaler_versions.php, pour les
// hébergements sans ligne de commande (Camoo) : compare la version
// ENREGISTRÉE de chaque école (schema_version_etab) à la version RÉELLE
// détectée dans sa base (colonnes / tables / index / valeurs ajoutés par
// chaque migration, logique partagée bd/lib/recalage_versions.php), et
// corrige l'enregistrement. Ne modifie JAMAIS les bases écoles : seule la
// ligne schema_version_etab de l'annuaire est écrite.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
require_once __DIR__ . '/../bd/lib/recalage_versions.php';
require_once __DIR__ . '/_layout.php';
exiger_proprietaire_association();

@set_time_limit(120);
$msg = ''; $lignes = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $code   = trim((string) ($_POST['code'] ?? ''));
    $cibles = match ($_POST['op'] ?? '') {
        'recaler_une'  => $code !== '' ? rv_analyser($code) : [],
        'recaler_tout' => rv_analyser(),
        default        => [],
    };
    foreach ($cibles as $r) {
        if ($r['actuel'] === $r['reelle']) continue;
        if (rv_recaler($r)) {
            $lignes[] = "✅ {$r['code']} — v" . ($r['actuel'] ?? '—') . " → v{$r['reelle']}";
            journaliser_action('recalage_version', $r['id'], 'v' . ($r['actuel'] ?? '-') . ' -> v' . $r['reelle']);
        } else {
            $lignes[] = "⚠️ {$r['code']} — non recalée (" . (!$r['joignable'] ? 'base injoignable' : 'migration partielle à examiner') . ')';
        }
    }
    $msg = $lignes ? 'Recalage terminé.' : 'Rien à recaler.';
}

$ecoles = rv_analyser();   // relu APRÈS un éventuel recalage
$a_recaler = array_filter($ecoles, 'rv_peut_recaler');

asso_haut('Versions de schéma — vérification');
$csrf = csrf_generer();
?>
<p class="small text-muted2 mb-2">
  <a href="<?= APP_URL ?>/association/migrations.php" class="text-decoration-none"><i class="bi bi-arrow-left me-1"></i>Migrations</a>
</p>

<?php if ($msg): ?>
  <div class="alert alert-success py-2 small"><?= h($msg) ?></div>
  <?php if ($lignes): ?><pre class="asso-card small" style="white-space:pre-wrap"><?= h(implode("\n", $lignes)) ?></pre><?php endif; ?>
<?php endif; ?>

<p class="small text-muted2">
  Compare, pour chaque école, la version <strong>enregistrée</strong> dans l'annuaire à la version <strong>réelle</strong>
  détectée dans sa base (colonnes, tables, index et valeurs ajoutés par chaque migration).
  Si elles diffèrent, le bouton « Migrer » rejouerait des migrations déjà appliquées (ou en sauterait) :
  <strong>recaler</strong> corrige seulement le numéro enregistré — aucune base école n'est modifiée.
  Au primaire, le schéma de référence est v<?= RV_BASE['primaire'] ?> : seules les migrations suivantes sont vérifiées.
</p>

<div class="asso-card p-0 mb-3">
  <div class="table-responsive">
  <table class="table table-sm mb-0 align-middle" style="font-size:.85rem">
    <thead><tr class="text-muted2"><th>École</th><th>Enregistrée</th><th>Réelle</th><th>Remarques</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($ecoles as $r): $ecart = $r['joignable'] && $r['actuel'] !== $r['reelle']; ?>
        <tr>
          <td>
            <?= h($r['nom']) ?> <span class="badge badge-soft ms-1"><?= h($r['code']) ?></span>
            <div class="small text-muted2"><?= h($r['type']) ?></div>
          </td>
          <td class="<?= $ecart ? 'text-warning fw-semibold' : '' ?>">v<?= $r['actuel'] ?? '—' ?></td>
          <td><?= $r['joignable'] ? 'v' . $r['reelle'] : '<span class="text-danger">base injoignable</span>' ?></td>
          <td class="small">
            <?php if (!$r['joignable']): ?>—
            <?php else: ?>
              <?php if (!$ecart): ?><span class="text-success"><i class="bi bi-check-circle me-1"></i>cohérent</span><?php endif; ?>
              <?php if ($r['a_appliquer']): ?>
                <div class="text-muted2">à appliquer ensuite : <?= h(implode(', ', array_map(fn($v) => "v$v", $r['a_appliquer']))) ?></div>
              <?php endif; ?>
              <?php if ($r['partielles']): ?>
                <div class="text-danger"><i class="bi bi-exclamation-triangle me-1"></i>partiellement présente(s) :
                  <?= h(implode(', ', array_map(fn($v) => "v$v", $r['partielles']))) ?> — à examiner avant tout recalage</div>
                <?php foreach ($r['partielles'] as $v): ?>
                  <div class="text-muted2">v<?= $v ?> manque : <?= h(implode(' ; ', array_slice($r['manque'][$v] ?? [], 0, 4))) ?></div>
                <?php endforeach; ?>
              <?php endif; ?>
              <?php if ($r['trous']): ?>
                <div class="text-muted2">déjà présentes plus loin : <?= h(implode(', ', array_map(fn($v) => "v$v", $r['trous']))) ?></div>
              <?php endif; ?>
            <?php endif; ?>
          </td>
          <td class="text-end">
            <?php if (rv_peut_recaler($r)): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Recaler <?= h($r['code']) ?> de v<?= $r['actuel'] ?? '—' ?> à v<?= $r['reelle'] ?> ? (seul le numéro enregistré change)');">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="op" value="recaler_une">
              <input type="hidden" name="code" value="<?= h($r['code']) ?>">
              <button class="btn btn-warning btn-sm"><i class="bi bi-wrench-adjustable me-1"></i>Recaler</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<?php if ($a_recaler): ?>
<form method="post" onsubmit="return confirm('Recaler les <?= count($a_recaler) ?> école(s) dont la version enregistrée est fausse ?');">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
  <input type="hidden" name="op" value="recaler_tout">
  <button class="btn btn-warning btn-sm"><i class="bi bi-wrench-adjustable me-1"></i>Recaler toutes les écoles concernées (<?= count($a_recaler) ?>)</button>
</form>
<?php else: ?>
  <div class="alert alert-success py-2 small"><i class="bi bi-check-circle me-1"></i>Toutes les versions enregistrées correspondent à l'état réel des bases.</div>
<?php endif; ?>
<?php asso_bas();
