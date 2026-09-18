<?php
// secondaire/pages/depenses_privees/journal.php — PAIEMENT PRIVÉ > Journal
// des dépenses : porté de pages/depenses/journal.php (primaire), adapté au
// schéma secondaire.
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'SECRETAIRE', 'INTENDANT']);

$annee     = get_annee_active();
$id_annee  = (int) ($annee['id'] ?? 0);
$val_annee = $annee['val_annee'] ?? ($annee['libelle'] ?? '');

$date_debut   = $_GET['debut'] ?? date('Y-m-01');
$date_fin     = $_GET['fin'] ?? date('Y-m-d');
$id_categorie = (int) ($_GET['categorie'] ?? 0);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_debut)) $date_debut = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_fin))   $date_fin   = date('Y-m-d');

$categories = db_all("SELECT * FROM categorie_depense_privee ORDER BY libelle");

$params = [$id_annee, $date_debut, $date_fin];
$sql = "SELECT d.*, cd.libelle AS categorie_libelle, u.nom, u.prenom
        FROM depense_privee d
        JOIN categorie_depense_privee cd ON cd.id = d.id_categorie
        LEFT JOIN utilisateur u ON u.id = d.id_utilisateur
        WHERE d.id_annee = ? AND d.date_depense BETWEEN ? AND ?";
if ($id_categorie) { $sql .= " AND d.id_categorie = ?"; $params[] = $id_categorie; }
$sql .= " ORDER BY d.date_depense, d.id";
$lignes = db_all($sql, $params);

$jours = [];
$total_general = 0.0;
foreach ($lignes as $l) {
    $jours[$l['date_depense']]['lignes'][] = $l;
    $jours[$l['date_depense']]['total'] = ($jours[$l['date_depense']]['total'] ?? 0.0) + (float) $l['montant'];
    $total_general += (float) $l['montant'];
}

$es_partiel = isset($_GET['partiel']);
if (!$es_partiel) {
    $titre_page = 'Journal des dépenses (privé)';
    require_once __DIR__ . '/../../../layout/header.php';
} else {
    header('Content-Type: text/html; charset=utf-8');
}
?>

<div id="journal-depenses-privees-zone">

<div class="page-titre d-flex justify-content-between align-items-center flex-wrap gap-2">
  <div>
    <h4><i class="bi bi-journal-minus me-1 text-primary"></i>Paiement privé — Journal des dépenses</h4>
    <div class="sub">Année <?= h($val_annee) ?> — <?= count($lignes) ?> dépense<?= count($lignes) > 1 ? 's' : '' ?></div>
  </div>
  <div class="d-flex gap-2">
    <button type="button" class="btn btn-outline-danger btn-sm" onclick="ouvrirJournalDepensesPrivePdf()">
      <i class="bi bi-file-earmark-pdf me-1"></i>Aperçu PDF
    </button>
  </div>
</div>

<div class="card mb-2">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end" data-ajax-nav-form>
      <div class="col-6 col-md-3">
        <label class="form-label">Du</label>
        <input type="date" name="debut" class="form-control form-control-sm" value="<?= h($date_debut) ?>">
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label">Au</label>
        <input type="date" name="fin" class="form-control form-control-sm" value="<?= h($date_fin) ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Catégorie</label>
        <select name="categorie" class="form-select form-select-sm">
          <option value="">— Toutes les catégories —</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?= (int) $c['id'] ?>" <?= $id_categorie === (int) $c['id'] ? 'selected' : '' ?>><?= h($c['libelle']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <button class="btn btn-primary btn-sm w-100"><i class="bi bi-funnel me-1"></i>Filtrer</button>
      </div>
    </form>
  </div>
</div>

<div class="card text-center py-2 mb-2" style="max-width:280px">
  <div class="text-muted" style="font-size:.68rem">TOTAL DÉPENSÉ SUR LA PÉRIODE</div>
  <div class="fw-bold text-danger" style="font-size:1.2rem"><?= number_format($total_general, 0, ',', ' ') ?> F</div>
</div>

<?php if (!$jours): ?>
  <div class="alert alert-light text-muted text-center py-4">Aucune dépense sur cette période<?= $id_categorie ? ' pour cette catégorie' : '' ?>.</div>
<?php else: foreach ($jours as $jour => $grp): ?>
  <div class="card mb-2">
    <div class="card-header py-2 d-flex justify-content-between align-items-center" style="background:#f8faff">
      <span class="fw-bold" style="font-size:.82rem"><i class="bi bi-calendar3 me-1 text-primary"></i><?= h(date_fr($jour)) ?></span>
      <span class="badge bg-light text-dark border">Sous-total : <?= number_format($grp['total'], 0, ',', ' ') ?> F (<?= count($grp['lignes']) ?>)</span>
    </div>
    <div class="table-responsive">
      <table class="table table-sm table-hover mb-0" style="font-size:.78rem">
        <thead style="background:#fbfbfd"><tr><th>N° bon</th><th>Catégorie</th><th>Libellé</th><th>Bénéficiaire</th><th class="text-end">Montant</th><th>Enregistré par</th></tr></thead>
        <tbody>
          <?php foreach ($grp['lignes'] as $l): ?>
            <tr>
              <td class="text-muted"><?= h(finances_numero_bon((int) $l['id'])) ?></td>
              <td><span class="badge bg-light text-dark border"><?= h($l['categorie_libelle']) ?></span></td>
              <td><?= h($l['libelle']) ?></td>
              <td><?= h($l['beneficiaire'] ?: '—') ?></td>
              <td class="text-end fw-semibold text-danger"><?= number_format((float) $l['montant'], 0, ',', ' ') ?> F</td>
              <td><?= $l['nom'] ? h(trim($l['prenom'] . ' ' . $l['nom'])) : '<span class="text-muted">—</span>' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endforeach; endif; ?>

<script>
function urlAvecFiltresDepensesPrive(base) {
    const p = new URLSearchParams({ debut: '<?= h($date_debut) ?>', fin: '<?= h($date_fin) ?>', categorie: '<?= $id_categorie ?>' });
    return base + '?' + p.toString();
}
function ouvrirJournalDepensesPrivePdf() {
    afficherApercu(urlAvecFiltresDepensesPrive('<?= APP_URL ?>/secondaire/pdf/prive_depenses_journal.php'), 'Journal des dépenses', null, 'portrait');
}
</script>

</div><!-- /#journal-depenses-privees-zone -->
<?php if ($es_partiel) exit; ?>

<?php
$ajax_zone_id = 'journal-depenses-privees-zone';
require_once __DIR__ . '/../../../layout/footer.php';
