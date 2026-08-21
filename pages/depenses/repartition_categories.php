<?php
// pages/depenses/repartition_categories.php — Dépenses > Répartition par
// catégorie : toutes les catégories en une seule liste avec la part
// (montant dépensé) et le % du total dépensé de chacune — miroir, côté
// décaissements, de pages/finances/repartition_classes.php (apport et %
// par classe côté encaissements).
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_role(['DIRECTEUR', 'SECRETAIRE']);

$annee     = get_annee_active();
$val_annee = $annee['val_annee'] ?? '';

$lignes = db_all(
    "SELECT cd.libelle, COUNT(d.id_depense) AS nb, COALESCE(SUM(d.montant),0) AS total
     FROM categorie_depense cd
     LEFT JOIN depense d ON d.id_categorie = cd.id_categorie AND d.val_annee = ?
     GROUP BY cd.id_categorie
     ORDER BY total DESC",
    [$val_annee]
);
$total_general = array_sum(array_column($lignes, 'total'));
foreach ($lignes as &$l) {
    $l['total'] = (float) $l['total'];
    $l['pct']   = $total_general > 0 ? round($l['total'] / $total_general * 100, 1) : 0.0;
}
unset($l);

$titre_page = 'Répartition par catégorie';
require_once __DIR__ . '/../../layout/header.php';
?>

<div class="page-titre d-flex justify-content-between align-items-center flex-wrap gap-2">
  <div>
    <h4><i class="bi bi-pie-chart-fill me-1 text-primary"></i>Dépenses — Répartition par catégorie</h4>
    <div class="sub">Année <?= h($val_annee) ?> — Part de chaque catégorie dans les dépenses</div>
  </div>
  <div class="d-flex gap-2">
    <button type="button" class="btn btn-outline-danger btn-sm" onclick="afficherApercu('<?= APP_URL ?>/pdf/depenses_repartition_categories.php', 'Répartition par catégorie', null, 'portrait')">
      <i class="bi bi-file-earmark-pdf me-1"></i>Aperçu PDF
    </button>
    <a class="btn btn-outline-success btn-sm" href="<?= APP_URL ?>/pages/depenses/excel_repartition_categories.php">
      <i class="bi bi-file-earmark-excel me-1"></i>Excel
    </a>
  </div>
</div>

<div class="card text-center py-2 mb-2" style="max-width:280px">
  <div class="text-muted" style="font-size:.68rem">TOTAL DÉPENSÉ — TOUTES CATÉGORIES</div>
  <div class="fw-bold text-danger" style="font-size:1.2rem"><?= number_format($total_general, 0, ',', ' ') ?> F</div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-abz table-hover align-middle mb-0" style="font-size:.82rem">
      <thead>
        <tr>
          <th>Catégorie</th>
          <th class="text-center">Nb dépenses</th>
          <th class="text-end">Montant dépensé</th>
          <th style="width:220px">% du total dépensé</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$lignes): ?>
          <tr><td colspan="4" class="text-center text-muted py-3">Aucune catégorie de dépense.</td></tr>
        <?php else: foreach ($lignes as $l): ?>
          <tr>
            <td class="fw-semibold"><?= h($l['libelle']) ?></td>
            <td class="text-center"><?= (int) $l['nb'] ?></td>
            <td class="text-end fw-bold text-danger"><?= number_format($l['total'], 0, ',', ' ') ?> F</td>
            <td>
              <div class="d-flex align-items-center gap-2">
                <div class="progress flex-grow-1" style="height:14px">
                  <div class="progress-bar bg-danger" style="width:<?= min(100, $l['pct']) ?>%"></div>
                </div>
                <span style="font-size:.72rem;min-width:38px" class="text-end"><?= h((string) $l['pct']) ?>%</span>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
      <?php if ($lignes): ?>
      <tfoot>
        <tr class="fw-bold" style="background:#fef2f2">
          <td>TOTAL (<?= count($lignes) ?> catégorie<?= count($lignes) > 1 ? 's' : '' ?>)</td>
          <td class="text-center"><?= array_sum(array_column($lignes, 'nb')) ?></td>
          <td class="text-end"><?= number_format($total_general, 0, ',', ' ') ?> F</td>
          <td class="text-center">100 %</td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../../layout/footer.php'; ?>
