<?php
// pages/finances/repartition_classes.php — Finances > Répartition par classe :
// un état global (toutes les classes en une seule liste, contrairement à
// etat_classe.php qui détaille une classe à la fois) montrant l'apport
// (montant encaissé) de chaque classe et son poids en % du total encaissé
// de l'établissement pour l'année active.
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_role(['DIRECTEUR', 'SECRETAIRE', 'COMPTABLE']);

$annee     = get_annee_active();
$val_annee = $annee['val_annee'] ?? '';

$classes = db_all(
    "SELECT c.IDClasses, c.DesignationClasses, c.Niveau, n.OrdreNiveau FROM classe c
     LEFT JOIN niveau n ON n.LibelleNiveau = c.Niveau
     ORDER BY n.OrdreNiveau, c.DesignationClasses"
);

$nb_par_classe = [];
foreach (db_all(
    "SELECT i.IDClasses, COUNT(DISTINCT i.id_eleve) AS nb FROM inscrire i
     JOIN eleve e ON e.id_eleve = i.id_eleve AND e.statut='actif'
     WHERE i.val_annee = ? GROUP BY i.IDClasses",
    [$val_annee]
) as $r) { $nb_par_classe[(int) $r['IDClasses']] = (int) $r['nb']; }

$paye_par_classe = [];
foreach (db_all(
    "SELECT classe, SUM(montant_paiement) AS paye FROM paiement_frais
     WHERE val_annee = ? GROUP BY classe",
    [$val_annee]
) as $r) { $paye_par_classe[(int) $r['classe']] = (float) $r['paye']; }

// Montant dû par élève (après réduction "Cas social" éventuelle,
// migration_v39) — voir finances_du_par_eleve() (fonctions.php), regroupé
// par classe pour cette page.
$du_par_classe = [];
foreach (finances_du_par_eleve($val_annee) as $e) {
    $du_par_classe[(int) $e['IDClasses']] = ($du_par_classe[(int) $e['IDClasses']] ?? 0.0) + $e['du'];
}

$lignes = [];
$total_apport_general = 0.0;
$total_du_general     = 0.0;
foreach ($classes as $c) {
    $id_classe = (int) $c['IDClasses'];
    $nb        = $nb_par_classe[$id_classe] ?? 0;
    $apport    = $paye_par_classe[$id_classe] ?? 0.0;
    $du        = $du_par_classe[$id_classe] ?? 0.0;
    $lignes[]  = ['classe' => $c['DesignationClasses'], 'niveau' => $c['Niveau'], 'nb' => $nb, 'du' => $du, 'apport' => $apport];
    $total_apport_general += $apport;
    $total_du_general     += $du;
}
// Pourcentage de chaque classe dans le total encaissé de l'établissement.
foreach ($lignes as &$l) {
    $l['pct']  = $total_apport_general > 0 ? round($l['apport'] / $total_apport_general * 100, 1) : 0.0;
    $l['taux'] = $l['du'] > 0 ? round($l['apport'] / $l['du'] * 100, 1) : 0.0;
}
unset($l);
// Classement par apport décroissant (les classes qui contribuent le plus en premier).
usort($lignes, fn($a, $b) => $b['apport'] <=> $a['apport']);

$titre_page = 'Répartition par classe';
require_once __DIR__ . '/../../layout/header.php';
?>

<div class="page-titre d-flex justify-content-between align-items-center flex-wrap gap-2">
  <div>
    <h4><i class="bi bi-pie-chart-fill me-1 text-primary"></i>Finances — Répartition par classe</h4>
    <div class="sub">Année <?= h($val_annee) ?> — Apport et poids de chaque classe dans les encaissements</div>
  </div>
  <div class="d-flex gap-2">
    <button type="button" class="btn btn-outline-danger btn-sm" onclick="afficherApercu('<?= APP_URL ?>/pdf/finances_repartition_classes.php', 'Répartition par classe', null, 'portrait')">
      <i class="bi bi-file-earmark-pdf me-1"></i>Aperçu PDF
    </button>
    <a class="btn btn-outline-success btn-sm" href="<?= APP_URL ?>/pages/finances/excel_repartition_classes.php">
      <i class="bi bi-file-earmark-excel me-1"></i>Excel
    </a>
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
          <th>Classe</th>
          <th class="text-center">Élèves</th>
          <th class="text-end">Dû</th>
          <th class="text-end">Apport (encaissé)</th>
          <th class="text-center">Taux de recouvrement</th>
          <th style="width:200px">% du total encaissé</th>
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

<?php require_once __DIR__ . '/../../layout/footer.php'; ?>
