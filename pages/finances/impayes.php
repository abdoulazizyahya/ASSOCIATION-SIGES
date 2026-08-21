<?php
// pages/finances/impayes.php — Finances > Impayés : liste dédiée,
// filtrable (niveau/classe/statut) et imprimable des élèves en solde
// débiteur — outil de travail quotidien pour la Secrétaire (contrairement à
// pages/finances/statistiques.php, vue stratégique réservée au Directeur).
// Le montant dû par élève = somme de toutes les obligations de son niveau
// (voir pages/finances/obligations.php), comme le reste du module.
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_role(['DIRECTEUR', 'SECRETAIRE']);

$annee     = get_annee_active();
$val_annee = $annee['val_annee'] ?? '';

$niveau_f = $_GET['niveau'] ?? '';
$id_classe_f = (int) ($_GET['classe'] ?? 0);
$statut_f = in_array($_GET['statut'] ?? '', ['impaye', 'partiel'], true) ? $_GET['statut'] : 'tous';

$niveaux = db_all("SELECT LibelleNiveau, OrdreNiveau FROM niveau WHERE actif=1 ORDER BY OrdreNiveau");
$classes = db_all(
    "SELECT c.IDClasses, c.DesignationClasses, c.Niveau, n.OrdreNiveau FROM classe c
     LEFT JOIN niveau n ON n.LibelleNiveau = c.Niveau
     ORDER BY n.OrdreNiveau, c.DesignationClasses"
);

$paye_par_eleve = [];
foreach (db_all("SELECT id_eleve, SUM(montant_paiement) AS paye FROM paiement_frais WHERE val_annee=? GROUP BY id_eleve", [$val_annee]) as $r) {
    $paye_par_eleve[(int) $r['id_eleve']] = (float) $r['paye'];
}

// Montant dû par élève (après réduction "Cas social" éventuelle,
// migration_v39) — voir finances_du_par_eleve() (fonctions.php).
$tous_eleves = finances_du_par_eleve($val_annee, $id_classe_f ?: null, $id_classe_f ? null : ($niveau_f ?: null));

$impayes = [];
foreach ($tous_eleves as $e) {
    $du    = $e['du'];
    $paye  = $paye_par_eleve[(int) $e['id_eleve']] ?? 0.0;
    $solde = $du - $paye;
    if ($solde <= 0.009) continue;
    if ($statut_f === 'impaye' && $paye > 0.009) continue;
    if ($statut_f === 'partiel' && $paye <= 0.009) continue;
    $impayes[] = $e + ['paye' => $paye, 'solde' => $solde];
}
usort($impayes, fn($a, $b) => $b['solde'] <=> $a['solde']);
$total_solde = array_sum(array_column($impayes, 'solde'));

$es_partiel = isset($_GET['partiel']);
if (!$es_partiel) {
    $titre_page = 'Impayés';
    require_once __DIR__ . '/../../layout/header.php';
} else {
    header('Content-Type: text/html; charset=utf-8');
}
?>

<div id="impayes-zone">

<div class="page-titre d-flex justify-content-between align-items-center flex-wrap gap-2">
  <div>
    <h4><i class="bi bi-exclamation-triangle me-1 text-danger"></i>Finances — Impayés</h4>
    <div class="sub">Année <?= h($val_annee) ?> — <?= count($impayes) ?> élève<?= count($impayes) > 1 ? 's' : '' ?> concerné<?= count($impayes) > 1 ? 's' : '' ?></div>
  </div>
  <div class="d-flex gap-2">
    <button type="button" class="btn btn-outline-danger btn-sm" onclick="ouvrirImpayesPdf()">
      <i class="bi bi-file-earmark-pdf me-1"></i>Aperçu PDF
    </button>
    <a class="btn btn-outline-success btn-sm" id="lienExcelImpayes" href="#">
      <i class="bi bi-file-earmark-excel me-1"></i>Excel
    </a>
  </div>
</div>

<div class="card mb-2">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end" id="form-filtre" data-ajax-nav-form>
      <div class="col-6 col-md-3">
        <label class="form-label">Niveau</label>
        <select name="niveau" class="form-select form-select-sm">
          <option value="">— Tous les niveaux —</option>
          <?php foreach ($niveaux as $n): ?>
            <option value="<?= h($n['LibelleNiveau']) ?>" <?= $niveau_f === $n['LibelleNiveau'] ? 'selected' : '' ?>>Niveau <?= h($n['LibelleNiveau']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label">Classe</label>
        <select name="classe" class="form-select form-select-sm">
          <option value="">— Toutes les classes —</option>
          <?php foreach ($classes as $c): ?>
            <option value="<?= (int) $c['IDClasses'] ?>" <?= $id_classe_f === (int) $c['IDClasses'] ? 'selected' : '' ?>><?= h($c['DesignationClasses']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label">Statut</label>
        <select name="statut" class="form-select form-select-sm">
          <option value="tous" <?= $statut_f === 'tous' ? 'selected' : '' ?>>Tous les impayés</option>
          <option value="impaye" <?= $statut_f === 'impaye' ? 'selected' : '' ?>>Impayé total (0 F versé)</option>
          <option value="partiel" <?= $statut_f === 'partiel' ? 'selected' : '' ?>>Partiel</option>
        </select>
      </div>
      <div class="col-6 col-md-3">
        <button class="btn btn-primary btn-sm w-100"><i class="bi bi-funnel me-1"></i>Filtrer</button>
      </div>
    </form>
  </div>
</div>

<div class="card text-center py-2 mb-2" style="max-width:280px">
  <div class="text-muted" style="font-size:.68rem">TOTAL RESTANT DÛ (SÉLECTION)</div>
  <div class="fw-bold text-danger" style="font-size:1.2rem"><?= number_format($total_solde, 0, ',', ' ') ?> F</div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-abz table-hover align-middle mb-0" style="font-size:.82rem">
      <thead><tr><th>Matricule</th><th>Nom et prénom</th><th>Classe</th><th class="text-end">Dû</th><th class="text-end">Payé</th><th class="text-end">Solde</th><th class="text-center">Statut</th><th class="text-center">Action</th></tr></thead>
      <tbody>
        <?php foreach ($impayes as $i): ?>
          <tr>
            <td><?= h($i['Mat_elv']) ?></td>
            <td>
              <?= h($i['Nom_elv'] . ' ' . ($i['Prenom_elv'] ?? '')) ?>
              <?php if ($i['cas_social']): ?>
                <span class="badge bg-info text-dark ms-1" style="font-size:.62rem" title="Cas social — réduction appliquée au montant dû">
                  Cas social -<?= h(rtrim(rtrim(number_format($i['pourcentage'], 2, '.', ''), '0'), '.')) ?>%
                </span>
              <?php endif; ?>
            </td>
            <td><?= h($i['DesignationClasses']) ?></td>
            <td class="text-end"><?= number_format($i['du'], 0, ',', ' ') ?> F</td>
            <td class="text-end"><?= number_format($i['paye'], 0, ',', ' ') ?> F</td>
            <td class="text-end fw-bold text-danger"><?= number_format($i['solde'], 0, ',', ' ') ?> F</td>
            <td class="text-center">
              <?php if ($i['paye'] <= 0.009): ?>
                <span class="badge bg-danger">Impayé</span>
              <?php else: ?>
                <span class="badge" style="background:#fef3c7;color:#92400e">Partiel</span>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <a href="<?= APP_URL ?>/pages/finances/versement.php?classe=<?= (int) $i['IDClasses'] ?>&eleve=<?= (int) $i['id_eleve'] ?>" class="btn btn-sm btn-light" style="padding:2px 7px" title="Encaisser">
                <i class="bi bi-cash-coin" style="font-size:.75rem"></i>
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$impayes): ?><tr><td colspan="8" class="text-center text-muted py-3">Aucun élève en impayé pour cette sélection.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
function urlAvecFiltres(base) {
    const p = new URLSearchParams({ niveau: '<?= h($niveau_f) ?>', classe: '<?= $id_classe_f ?>', statut: '<?= h($statut_f) ?>' });
    return base + '?' + p.toString();
}
function ouvrirImpayesPdf() {
    afficherApercu(urlAvecFiltres('<?= APP_URL ?>/pdf/finances_impayes.php'), 'Impayés', null, 'portrait');
}
document.getElementById('lienExcelImpayes').href = urlAvecFiltres('<?= APP_URL ?>/pages/finances/excel_impayes.php');
</script>

</div><!-- /#impayes-zone -->
<?php if ($es_partiel) exit; // rien de plus dans une réponse AJAX partielle. ?>

<?php
$ajax_zone_id = 'impayes-zone'; // voir layout/footer.php — initAjaxZone() y est appelé
require_once __DIR__ . '/../../layout/footer.php';
