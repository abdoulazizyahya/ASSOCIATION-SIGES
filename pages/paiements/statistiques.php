<?php
// pages/paiements/statistiques.php — "Statistiques avancées" du module
// paiements, page dédiée (menu Finances → Statistiques avancées), séparée
// de pages/paiements/rapport.php pour ne pas mélanger ses propres onglets
// (Paiements/Statut par frais/Insolvables) avec ceux-ci. 4 sous-onglets
// (chacun exportable PDF + Excel) : Période globale (total + évolution
// mensuelle), Par frais, Par opérateur, Recouvrement par classe. Les 3
// premiers partagent les mêmes filtres classe/période ; le 4e n'a qu'un
// filtre classe (le montant "dû théorique" n'est pas borné dans le temps).
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'CENSEUR', 'INTENDANT']);

$annee_active = get_annee_active();
$id_annee     = (int)($annee_active['id'] ?? 0);

$classes = $id_annee
    ? db_all("SELECT c.* FROM classe c
              JOIN inscription i ON i.id_classe=c.id AND i.id_annee=?
              WHERE c.archivee=0 GROUP BY c.id ORDER BY c.ordre, c.designation", [$id_annee])
    : [];

$sous_onglet = in_array($_GET['sous'] ?? '', ['periode', 'frais', 'operateur', 'recouvrement'], true) ? $_GET['sous'] : 'periode';
$id_classe   = (int)($_GET['classe'] ?? 0);
$date_debut  = trim($_GET['debut'] ?? '');
$date_fin    = trim($_GET['fin'] ?? '');

$where  = ['p.id_annee = ?'];
$params = [$id_annee];
if ($id_classe)  { $where[] = 'p.id_classe = ?';      $params[] = $id_classe; }
if ($date_debut) { $where[] = 'p.date_paiement >= ?'; $params[] = $date_debut; }
if ($date_fin)   { $where[] = 'p.date_paiement <= ?'; $params[] = $date_fin; }
$sql_where_stats = implode(' AND ', $where);

$total_general = (float) db_val("SELECT COALESCE(SUM(p.montant),0) FROM paiement_frais p WHERE $sql_where_stats", $params);

if ($sous_onglet === 'periode') {
    $par_mois = db_all(
        "SELECT DATE_FORMAT(p.date_paiement, '%Y-%m') AS mois, SUM(p.montant) AS total, COUNT(*) AS nb
         FROM paiement_frais p WHERE $sql_where_stats GROUP BY mois ORDER BY mois", $params
    );
}
if ($sous_onglet === 'frais') {
    $par_frais = db_all(
        "SELECT o.libelle, SUM(p.montant) AS total, COUNT(*) AS nb
         FROM paiement_frais p JOIN obligation_frais o ON o.id=p.id_obligation
         WHERE $sql_where_stats GROUP BY o.libelle ORDER BY total DESC", $params
    );
}
if ($sous_onglet === 'operateur') {
    $par_operateur = db_all(
        "SELECT op.libelle, SUM(p.montant) AS total, COUNT(*) AS nb
         FROM paiement_frais p JOIN operateur_paiement op ON op.id=p.id_operateur
         WHERE $sql_where_stats GROUP BY op.libelle ORDER BY total DESC", $params
    );
}
if ($sous_onglet === 'recouvrement') {
    $classes_effectif = db_all(
        "SELECT c.id, c.designation, c.code_niveau, COUNT(DISTINCT e.id) AS effectif
         FROM classe c
         JOIN inscription i ON i.id_classe=c.id AND i.id_annee=?
         JOIN eleve e ON e.id=i.id_eleve AND e.statut='actif'
         WHERE c.archivee=0 " . ($id_classe ? 'AND c.id=?' : '') . "
         GROUP BY c.id ORDER BY c.ordre, c.designation",
        $id_classe ? [$id_annee, $id_classe] : [$id_annee]
    );
    $paye_par_classe = [];
    foreach (db_all("SELECT id_classe, SUM(montant) AS total FROM paiement_frais WHERE id_annee=? GROUP BY id_classe", [$id_annee]) as $r) {
        $paye_par_classe[$r['id_classe']] = (float) $r['total'];
    }
    $recouvrement = [];
    foreach ($classes_effectif as $c) {
        $id_cycle = db_val("SELECT id_cycle FROM niveau WHERE code_niveau=?", [$c['code_niveau']]);
        $montant_du_unitaire = (float) db_val(
            "SELECT COALESCE(SUM(montant),0) FROM obligation_frais WHERE id_annee=? AND actif=1
             AND (portee='etablissement' OR (portee='cycle' AND id_cycle=?) OR (portee='niveau' AND code_niveau=?))",
            [$id_annee, $id_cycle, $c['code_niveau']]
        );
        $total_du   = $montant_du_unitaire * $c['effectif'];
        $total_paye = $paye_par_classe[$c['id']] ?? 0.0;
        $recouvrement[] = [
            'classe' => $c['designation'], 'effectif' => $c['effectif'],
            'total_du' => $total_du, 'total_paye' => $total_paye,
            'taux' => $total_du > 0 ? min(100, $total_paye / $total_du * 100) : 0,
        ];
    }
}

$titre_page = 'Statistiques avancées';
require_once __DIR__ . '/../../layout/header.php';
?>
<div class="page-titre d-flex justify-content-between align-items-center">
  <h4><i class="bi bi-bar-chart-line me-1 text-primary"></i><?= h($titre_page) ?></h4>
</div>

<ul class="nav nav-pills mb-2" style="font-size:.85rem">
  <?php foreach ([
      'periode'      => 'Période globale',
      'frais'        => 'Par frais',
      'operateur'    => 'Par opérateur',
      'recouvrement' => 'Recouvrement par classe',
  ] as $key => $label): ?>
  <li class="nav-item">
    <a class="nav-link <?= $sous_onglet === $key ? 'active' : '' ?>" href="<?= APP_URL ?>/pages/paiements/statistiques.php?sous=<?= $key ?>">
      <?= h($label) ?>
    </a>
  </li>
  <?php endforeach; ?>
</ul>

<div class="card mb-2">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end">
      <input type="hidden" name="sous" value="<?= h($sous_onglet) ?>">
      <div class="col-md-3">
        <label class="form-label">Classe</label>
        <select name="classe" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="">— Toutes —</option>
          <?php foreach ($classes as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $id_classe === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['designation']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($sous_onglet !== 'recouvrement'): ?>
      <div class="col-md-2">
        <label class="form-label">Du</label>
        <input type="date" name="debut" class="form-control form-control-sm" value="<?= h($date_debut) ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label">Au</label>
        <input type="date" name="fin" class="form-control form-control-sm" value="<?= h($date_fin) ?>">
      </div>
      <div class="col-md-2">
        <button class="btn btn-primary btn-sm w-100"><i class="bi bi-search me-1"></i>Filtrer</button>
      </div>
      <?php else: ?>
      <div class="col-md-2">
        <button class="btn btn-primary btn-sm w-100"><i class="bi bi-search me-1"></i>Filtrer</button>
      </div>
      <?php endif; ?>
      <div class="col-md-3 d-flex gap-2">
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="ouvrirApercuStats()">
          <i class="bi bi-file-earmark-pdf me-1"></i>PDF
        </button>
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="exportExcelStats()">
          <i class="bi bi-file-earmark-excel me-1"></i>Excel
        </button>
      </div>
    </form>
  </div>
</div>

<?php if ($sous_onglet === 'periode'): ?>
<div class="row g-2 mb-2">
  <div class="col-md-3">
    <div class="card text-center py-3">
      <div class="text-muted" style="font-size:.75rem">TOTAL SUR LA SÉLECTION</div>
      <div class="fs-4 fw-bold text-primary"><?= number_format($total_general, 0, ',', ' ') ?> F</div>
    </div>
  </div>
</div>
<div class="card">
  <div class="card-header py-2" style="background:#f8faff"><span class="fw-semibold" style="font-size:.82rem">Évolution mensuelle</span></div>
  <div class="table-responsive">
    <table class="table table-abz table-hover align-middle mb-0" id="tblStats" style="font-size:.8rem">
      <thead><tr><th>Mois</th><th class="text-end">Versements</th><th class="text-end">Total</th></tr></thead>
      <tbody>
        <?php foreach ($par_mois as $m): ?>
        <tr><td><?= h(date('m/Y', strtotime($m['mois'] . '-01'))) ?></td><td class="text-end"><?= (int)$m['nb'] ?></td><td class="text-end fw-semibold"><?= number_format((float)$m['total'], 0, ',', ' ') ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$par_mois): ?><tr><td colspan="3" class="text-center text-muted py-3">Aucun paiement.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif ($sous_onglet === 'frais'): ?>
<div class="card">
  <div class="card-header py-2" style="background:#f8faff"><span class="fw-semibold" style="font-size:.82rem">Répartition par frais</span></div>
  <div class="table-responsive">
    <table class="table table-abz table-hover align-middle mb-0" id="tblStats" style="font-size:.8rem">
      <thead><tr><th>Frais</th><th class="text-end">Versements</th><th class="text-end">Total</th></tr></thead>
      <tbody>
        <?php foreach ($par_frais as $f): ?>
        <tr><td><?= h($f['libelle']) ?></td><td class="text-end"><?= (int)$f['nb'] ?></td><td class="text-end fw-semibold"><?= number_format((float)$f['total'], 0, ',', ' ') ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$par_frais): ?><tr><td colspan="3" class="text-center text-muted py-3">Aucun paiement.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif ($sous_onglet === 'operateur'): ?>
<div class="card">
  <div class="card-header py-2" style="background:#f8faff"><span class="fw-semibold" style="font-size:.82rem">Répartition par opérateur</span></div>
  <div class="table-responsive">
    <table class="table table-abz table-hover align-middle mb-0" id="tblStats" style="font-size:.8rem">
      <thead><tr><th>Opérateur</th><th class="text-end">Versements</th><th class="text-end">Total</th><th class="text-end">%</th></tr></thead>
      <tbody>
        <?php foreach ($par_operateur as $o): $pct = $total_general > 0 ? (float)$o['total'] / $total_general * 100 : 0; ?>
        <tr><td><?= h($o['libelle']) ?></td><td class="text-end"><?= (int)$o['nb'] ?></td><td class="text-end fw-semibold"><?= number_format((float)$o['total'], 0, ',', ' ') ?></td><td class="text-end"><?= number_format($pct, 1) ?>%</td></tr>
        <?php endforeach; ?>
        <?php if (!$par_operateur): ?><tr><td colspan="4" class="text-center text-muted py-3">Aucun paiement.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php else /* recouvrement */: ?>
<div class="card">
  <div class="card-header py-2" style="background:#f8faff"><span class="fw-semibold" style="font-size:.82rem">Taux de recouvrement par classe</span></div>
  <div class="table-responsive">
    <table class="table table-abz table-hover align-middle mb-0" id="tblStats" style="font-size:.8rem">
      <thead><tr><th>Classe</th><th class="text-end">Effectif</th><th class="text-end">Dû (théorique)</th><th class="text-end">Payé</th><th class="text-end">Taux</th></tr></thead>
      <tbody>
        <?php foreach ($recouvrement as $r): ?>
        <tr>
          <td><?= h($r['classe']) ?></td>
          <td class="text-end"><?= (int)$r['effectif'] ?></td>
          <td class="text-end"><?= number_format($r['total_du'], 0, ',', ' ') ?></td>
          <td class="text-end"><?= number_format($r['total_paye'], 0, ',', ' ') ?></td>
          <td class="text-end fw-bold <?= $r['taux'] >= 80 ? 'text-success' : ($r['taux'] >= 50 ? 'text-warning' : 'text-danger') ?>"><?= number_format($r['taux'], 0) ?>%</td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$recouvrement): ?><tr><td colspan="5" class="text-center text-muted py-3">Aucune classe.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<div class="alert alert-light border mt-2" style="font-size:.78rem">
  <i class="bi bi-info-circle me-1"></i>Le montant « dû (théorique) » suppose que chaque élève actif de la classe doit régler l'intégralité des frais qui s'appliquent à son niveau/cycle — il ne tient pas compte d'éventuelles exonérations individuelles.
</div>
<?php endif; ?>

<script src="<?= APP_URL ?>/assets/vendor/xlsx/xlsx.full.min.js"></script>
<script>
const STATS_SOUS_ONGLET = <?= json_encode($sous_onglet) ?>;
const STATS_TITRES = {
    periode: 'Statistiques — Période globale', frais: 'Statistiques — Par frais',
    operateur: 'Statistiques — Par opérateur', recouvrement: 'Statistiques — Recouvrement par classe',
};
function ouvrirApercuStats() {
    let url;
    if (STATS_SOUS_ONGLET === 'recouvrement') {
        url = <?= json_encode(APP_URL . '/pages/paiements/pdf_stats_recouvrement.php?classe=' . $id_classe) ?>;
    } else {
        url = <?= json_encode(APP_URL . '/pages/paiements/pdf_stats.php?type=' . $sous_onglet . '&classe=' . $id_classe . '&debut=' . $date_debut . '&fin=' . $date_fin) ?>;
    }
    afficherApercu(url, STATS_TITRES[STATS_SOUS_ONGLET], 'stats_paiement_' + STATS_SOUS_ONGLET);
}
function exportExcelStats() {
    const rows = [];
    const table = document.getElementById('tblStats');
    table.querySelectorAll('thead th').forEach((th, i) => { rows[0] = rows[0] || []; rows[0][i] = th.textContent.trim(); });
    table.querySelectorAll('tbody tr').forEach(tr => {
        const c = tr.querySelectorAll('td');
        if (!c.length) return;
        rows.push([...c].map(td => td.textContent.trim()));
    });
    const ws = XLSX.utils.aoa_to_sheet(rows);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Statistiques');
    XLSX.writeFile(wb, 'statistiques_' + STATS_SOUS_ONGLET + '.xlsx');
}
</script>

<?php require_once __DIR__ . '/../../layout/footer.php'; ?>
