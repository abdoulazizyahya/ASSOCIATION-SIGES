<?php
// pages/depenses/statistiques.php — Dépenses > Statistiques : vue
// d'ensemble du solde de caisse (encaissé - dépensé) et répartition des
// dépenses par catégorie et par mois, pour l'année scolaire active.
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_role(['DIRECTEUR']);

$annee     = get_annee_active();
$val_annee = $annee['val_annee'] ?? '';

$total_encaisse = (float) (db_val("SELECT COALESCE(SUM(montant_paiement),0) FROM paiement_frais WHERE val_annee=?", [$val_annee]) ?? 0);
$total_depense  = (float) (db_val("SELECT COALESCE(SUM(montant),0) FROM depense WHERE val_annee=?", [$val_annee]) ?? 0);
$solde          = $total_encaisse - $total_depense;

$par_categorie = db_all(
    "SELECT cd.libelle, COUNT(d.id_depense) AS nb, COALESCE(SUM(d.montant),0) AS total
     FROM categorie_depense cd
     LEFT JOIN depense d ON d.id_categorie = cd.id_categorie AND d.val_annee = ?
     GROUP BY cd.id_categorie
     ORDER BY total DESC",
    [$val_annee]
);

$par_mois = db_all(
    "SELECT DATE_FORMAT(date_depense, '%Y-%m') AS mois, COUNT(*) AS nb, SUM(montant) AS total
     FROM depense WHERE val_annee = ?
     GROUP BY mois ORDER BY mois",
    [$val_annee]
);

$titre_page = 'Statistiques des dépenses';
require_once __DIR__ . '/../../layout/header.php';

$mois_fr = [1=>'Janvier',2=>'Février',3=>'Mars',4=>'Avril',5=>'Mai',6=>'Juin',7=>'Juillet',8=>'Août',9=>'Septembre',10=>'Octobre',11=>'Novembre',12=>'Décembre'];
?>

<div class="page-titre d-flex justify-content-between align-items-center flex-wrap gap-2">
  <div>
    <h4><i class="bi bi-pie-chart me-1 text-primary"></i>Dépenses — Statistiques</h4>
    <div class="sub">Année <?= h($val_annee) ?></div>
  </div>
  <div class="d-flex gap-2">
    <button type="button" class="btn btn-outline-danger btn-sm" onclick="afficherApercu('<?= APP_URL ?>/pdf/depenses_statistiques.php', 'Bilan des dépenses', null, 'portrait')">
      <i class="bi bi-file-earmark-pdf me-1"></i>Aperçu PDF
    </button>
    <a class="btn btn-outline-success btn-sm" href="<?= APP_URL ?>/pages/depenses/excel_statistiques.php">
      <i class="bi bi-file-earmark-excel me-1"></i>Excel
    </a>
  </div>
</div>

<div class="row g-2 mb-3">
  <div class="col-md-4">
    <div class="card text-center py-3" style="border-left:4px solid #15803d">
      <div class="text-muted" style="font-size:.7rem">TOTAL ENCAISSÉ</div>
      <div class="fw-bold text-success" style="font-size:1.4rem"><?= number_format($total_encaisse, 0, ',', ' ') ?> F</div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card text-center py-3" style="border-left:4px solid #dc2626">
      <div class="text-muted" style="font-size:.7rem">TOTAL DÉPENSÉ</div>
      <div class="fw-bold text-danger" style="font-size:1.4rem"><?= number_format($total_depense, 0, ',', ' ') ?> F</div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card text-center py-3" style="border-left:4px solid <?= $solde >= 0 ? '#1e4fd8' : '#dc2626' ?>">
      <div class="text-muted" style="font-size:.7rem">SOLDE DE CAISSE</div>
      <div class="fw-bold" style="font-size:1.4rem;color:<?= $solde >= 0 ? '#1e4fd8' : '#dc2626' ?>"><?= number_format($solde, 0, ',', ' ') ?> F</div>
    </div>
  </div>
</div>

<div class="row g-2">
  <div class="col-md-6">
    <div class="card h-100" style="border:1px solid #e5e7eb">
      <div class="card-header py-2 px-3" style="background:#f8faff;border-bottom:1px solid #e5e7eb">
        <span class="fw-bold" style="font-size:.8rem;color:#374151">Répartition par catégorie</span>
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0" style="font-size:.78rem">
          <thead style="background:#fbfbfd"><tr><th>Catégorie</th><th class="text-end">Nb</th><th class="text-end">Total</th><th class="text-end">%</th></tr></thead>
          <tbody>
            <?php if (!$par_categorie): ?>
              <tr><td colspan="4" class="text-center text-muted py-3">Aucune donnée.</td></tr>
            <?php else: foreach ($par_categorie as $c): $pct = $total_depense > 0 ? round((float) $c['total'] / $total_depense * 100, 1) : 0; ?>
              <tr>
                <td><?= h($c['libelle']) ?></td>
                <td class="text-end"><?= (int) $c['nb'] ?></td>
                <td class="text-end fw-semibold"><?= number_format((float) $c['total'], 0, ',', ' ') ?> F</td>
                <td class="text-end text-muted"><?= $pct ?>%</td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
          <?php if ($par_categorie): ?>
          <tfoot>
            <tr class="fw-bold" style="background:#f8faff">
              <td>Total</td>
              <td class="text-end"><?= array_sum(array_column($par_categorie, 'nb')) ?></td>
              <td class="text-end"><?= number_format($total_depense, 0, ',', ' ') ?> F</td>
              <td class="text-end">100%</td>
            </tr>
          </tfoot>
          <?php endif; ?>
        </table>
      </div>
    </div>
  </div>

  <div class="col-md-6">
    <div class="card h-100" style="border:1px solid #e5e7eb">
      <div class="card-header py-2 px-3" style="background:#f8faff;border-bottom:1px solid #e5e7eb">
        <span class="fw-bold" style="font-size:.8rem;color:#374151">Répartition par mois</span>
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0" style="font-size:.78rem">
          <thead style="background:#fbfbfd"><tr><th>Mois</th><th class="text-end">Nb</th><th class="text-end">Total</th></tr></thead>
          <tbody>
            <?php if (!$par_mois): ?>
              <tr><td colspan="3" class="text-center text-muted py-3">Aucune donnée.</td></tr>
            <?php else: foreach ($par_mois as $m): [$an, $mo] = explode('-', $m['mois']); ?>
              <tr>
                <td><?= h($mois_fr[(int) $mo] ?? $mo) . ' ' . h($an) ?></td>
                <td class="text-end"><?= (int) $m['nb'] ?></td>
                <td class="text-end fw-semibold"><?= number_format((float) $m['total'], 0, ',', ' ') ?> F</td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../layout/footer.php'; ?>
