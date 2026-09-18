<?php
// secondaire/pages/paiements_prives/repartition_classes.php — PAIEMENT
// PRIVÉ > Répartition par classe : porté de pages/finances/repartition_classes.php
// (primaire), adapté au schéma secondaire.
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'SECRETAIRE', 'INTENDANT']);

$annee     = get_annee_active();
$id_annee  = (int) ($annee['id'] ?? 0);
$val_annee = $annee['val_annee'] ?? ($annee['libelle'] ?? '');

$classes = db_all(
    "SELECT c.id, c.designation, c.code_niveau, n.ordre_niveau FROM classe c
     LEFT JOIN niveau n ON n.code_niveau = c.code_niveau
     WHERE c.archivee=0 ORDER BY n.ordre_niveau, c.designation"
);

$nb_par_classe = [];
foreach (db_all(
    "SELECT i.id_classe, COUNT(DISTINCT i.id_eleve) AS nb FROM inscription i
     JOIN eleve e ON e.id = i.id_eleve AND e.statut='actif'
     WHERE i.id_annee = ? GROUP BY i.id_classe",
    [$id_annee]
) as $r) { $nb_par_classe[(int) $r['id_classe']] = (int) $r['nb']; }

$paye_par_classe = [];
foreach (db_all(
    "SELECT id_classe, SUM(montant_paiement) AS paye FROM paiement_prive
     WHERE id_annee = ? GROUP BY id_classe",
    [$id_annee]
) as $r) { $paye_par_classe[(int) $r['id_classe']] = (float) $r['paye']; }

$du_par_classe = [];
foreach (prive_finances_du_par_eleve($id_annee) as $e) {
    $du_par_classe[(int) $e['id_classe']] = ($du_par_classe[(int) $e['id_classe']] ?? 0.0) + $e['du'];
}

$lignes = [];
$total_apport_general = 0.0;
$total_du_general     = 0.0;
foreach ($classes as $c) {
    $id_classe = (int) $c['id'];
    $nb        = $nb_par_classe[$id_classe] ?? 0;
    $apport    = $paye_par_classe[$id_classe] ?? 0.0;
    $du        = $du_par_classe[$id_classe] ?? 0.0;
    $lignes[]  = ['classe' => $c['designation'], 'niveau' => $c['code_niveau'], 'nb' => $nb, 'du' => $du, 'apport' => $apport];
    $total_apport_general += $apport;
    $total_du_general     += $du;
}
foreach ($lignes as &$l) {
    $l['pct']  = $total_apport_general > 0 ? round($l['apport'] / $total_apport_general * 100, 1) : 0.0;
    $l['taux'] = $l['du'] > 0 ? round($l['apport'] / $l['du'] * 100, 1) : 0.0;
}
unset($l);
usort($lignes, fn($a, $b) => $b['apport'] <=> $a['apport']);

$titre_page = 'Répartition par classe (privé)';
require_once __DIR__ . '/../../../layout/header.php';
?>

<div class="page-titre d-flex justify-content-between align-items-center flex-wrap gap-2">
  <div>
    <h4><i class="bi bi-pie-chart-fill me-1 text-primary"></i>Paiement privé — Répartition par classe</h4>
    <div class="sub">Année <?= h($val_annee) ?> — Apport et poids de chaque classe dans les encaissements</div>
  </div>
  <div class="d-flex gap-2">
    <button type="button" class="btn btn-outline-danger btn-sm" onclick="afficherApercu('<?= APP_URL ?>/secondaire/pdf/prive_repartition_classes.php', 'Répartition par classe', null, 'portrait')">
      <i class="bi bi-file-earmark-pdf me-1"></i>Aperçu PDF
    </button>
  </div>
</div>

<div class="card text-center py-2 mb-2" style="max-width:280px">
  <div class="text-muted" style="font-size:.68rem">TOTAL ENCAISSÉ — TOUTES CLASSES</div>
  <div class="fw-bold text-success" style="font-size:1.2rem"><?= number_format($total_apport_general, 0, ',', ' ') ?> F</div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-abz table-hover align-middle mb-0" style="font-size:.82rem">
      <thead>
        <tr>
          <th>Classe</th><th class="text-center">Élèves</th><th class="text-end">Dû</th>
          <th class="text-end">Apport (encaissé)</th><th class="text-center">Taux de recouvrement</th><th style="width:200px">% du total encaissé</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$lignes): ?>
          <tr><td colspan="6" class="text-center text-muted py-3">Aucune classe pour cette année.</td></tr>
        <?php else: foreach ($lignes as $l): ?>
          <tr>
            <td class="fw-semibold"><?= h($l['classe']) ?></td>
            <td class="text-center"><?= $l['nb'] ?></td>
            <td class="text-end"><?= number_format($l['du'], 0, ',', ' ') ?> F</td>
            <td class="text-end fw-bold text-success"><?= number_format($l['apport'], 0, ',', ' ') ?> F</td>
            <td class="text-center"><?= h((string) $l['taux']) ?> %</td>
            <td>
              <div class="d-flex align-items-center gap-2">
                <div class="progress flex-grow-1" style="height:14px">
                  <div class="progress-bar bg-primary" style="width:<?= min(100, $l['pct']) ?>%"></div>
                </div>
                <span style="font-size:.72rem;min-width:38px" class="text-end"><?= h((string) $l['pct']) ?>%</span>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
      <?php if ($lignes): ?>
      <tfoot>
        <tr class="fw-bold" style="background:#f8faff">
          <td>TOTAL (<?= count($lignes) ?> classe<?= count($lignes) > 1 ? 's' : '' ?>)</td>
          <td class="text-center"><?= array_sum(array_column($lignes, 'nb')) ?></td>
          <td class="text-end"><?= number_format($total_du_general, 0, ',', ' ') ?> F</td>
          <td class="text-end"><?= number_format($total_apport_general, 0, ',', ' ') ?> F</td>
          <td colspan="2" class="text-center">100 %</td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../../../layout/footer.php'; ?>
