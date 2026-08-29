<?php
// pages/finances/cas_sociaux.php — Finances > Cas sociaux : liste dédiée,
// filtrable (niveau/classe), des élèves marqués "Cas social" (fiche élève >
// Informations complémentaires, migration_v39) — pourcentage de réduction,
// montant normal, montant réellement dû après réduction, payé et solde.
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_role(['DIRECTEUR', 'SECRETAIRE', 'COMPTABLE']);

$annee     = get_annee_active();
$val_annee = $annee['val_annee'] ?? '';

$niveau_f    = $_GET['niveau'] ?? '';
$id_classe_f = (int) ($_GET['classe'] ?? 0);

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

// Montant dû par élève (après réduction "Cas social") — voir
// finances_du_par_eleve() (fonctions.php) — puis filtre sur cas_social=true.
$tous_eleves = finances_du_par_eleve($val_annee, $id_classe_f ?: null, $id_classe_f ? null : ($niveau_f ?: null));

$cas_sociaux = [];
$total_normal = 0.0; $total_du = 0.0; $total_paye = 0.0; $total_reduction = 0.0;
foreach ($tous_eleves as $e) {
    if (!$e['cas_social']) continue;
    $paye = $paye_par_eleve[(int) $e['id_eleve']] ?? 0.0;
    $reduction = $e['montant_normal'] - $e['du'];
    $cas_sociaux[] = $e + ['paye' => $paye, 'solde' => $e['du'] - $paye, 'reduction' => $reduction];
    $total_normal    += $e['montant_normal'];
    $total_du        += $e['du'];
    $total_paye      += $paye;
    $total_reduction += $reduction;
}
usort($cas_sociaux, fn($a, $b) => $b['pourcentage'] <=> $a['pourcentage']);

$es_partiel = isset($_GET['partiel']);
if (!$es_partiel) {
    $titre_page = 'Cas sociaux';
    require_once __DIR__ . '/../../layout/header.php';
} else {
    header('Content-Type: text/html; charset=utf-8');
}
?>

<div id="cas-sociaux-zone">

<div class="page-titre d-flex justify-content-between align-items-center flex-wrap gap-2">
  <div>
    <h4><i class="bi bi-heart me-1 text-primary"></i>Finances — Cas sociaux</h4>
    <div class="sub">Année <?= h($val_annee) ?> — <?= count($cas_sociaux) ?> élève<?= count($cas_sociaux) > 1 ? 's' : '' ?> concerné<?= count($cas_sociaux) > 1 ? 's' : '' ?></div>
  </div>
  <div class="d-flex gap-2">
    <button type="button" class="btn btn-outline-danger btn-sm" onclick="ouvrirCasSociauxPdf()">
      <i class="bi bi-file-earmark-pdf me-1"></i>Aperçu PDF
    </button>
    <a class="btn btn-outline-success btn-sm" id="lienExcelCasSociaux" href="#">
      <i class="bi bi-file-earmark-excel me-1"></i>Excel
    </a>
  </div>
</div>

<div class="card mb-2">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end" id="form-filtre" data-ajax-nav-form>
      <div class="col-6 col-md-4">
        <label class="form-label">Niveau</label>
        <select name="niveau" class="form-select form-select-sm">
          <option value="">— Tous les niveaux —</option>
          <?php foreach ($niveaux as $n): ?>
            <option value="<?= h($n['LibelleNiveau']) ?>" <?= $niveau_f === $n['LibelleNiveau'] ? 'selected' : '' ?>>Niveau <?= h($n['LibelleNiveau']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-4">
        <label class="form-label">Classe</label>
        <select name="classe" class="form-select form-select-sm">
          <option value="">— Toutes les classes —</option>
          <?php foreach ($classes as $c): ?>
            <option value="<?= (int) $c['IDClasses'] ?>" <?= $id_classe_f === (int) $c['IDClasses'] ? 'selected' : '' ?>><?= h($c['DesignationClasses']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-4">
        <button class="btn btn-primary btn-sm w-100"><i class="bi bi-funnel me-1"></i>Filtrer</button>
      </div>
    </form>
  </div>
</div>

<div class="row g-2 mb-2">
  <div class="col-6 col-md-3">
    <div class="card text-center py-2"><div class="text-muted" style="font-size:.68rem">MONTANT NORMAL (SÉLECTION)</div><div class="fw-bold" style="font-size:1.05rem;text-decoration:line-through;color:#6b7280"><?= number_format($total_normal, 0, ',', ' ') ?> F</div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card text-center py-2"><div class="text-muted" style="font-size:.68rem">RÉDUCTION TOTALE ACCORDÉE</div><div class="fw-bold text-info" style="font-size:1.05rem"><?= number_format($total_reduction, 0, ',', ' ') ?> F</div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card text-center py-2"><div class="text-muted" style="font-size:.68rem">MONTANT DÛ (RÉDUIT)</div><div class="fw-bold" style="font-size:1.05rem"><?= number_format($total_du, 0, ',', ' ') ?> F</div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card text-center py-2"><div class="text-muted" style="font-size:.68rem">ENCAISSÉ</div><div class="fw-bold text-success" style="font-size:1.05rem"><?= number_format($total_paye, 0, ',', ' ') ?> F</div></div>
  </div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-abz table-hover align-middle mb-0" style="font-size:.82rem">
      <thead><tr>
        <th>Matricule</th><th>Nom et prénom</th><th>Classe</th>
        <th class="text-center">Réduction</th>
        <th class="text-end">Montant normal</th>
        <th class="text-end">Montant dû</th>
        <th class="text-end">Payé</th>
        <th class="text-end">Solde</th>
        <th class="text-center">Action</th>
      </tr></thead>
      <tbody>
        <?php foreach ($cas_sociaux as $l): ?>
          <tr>
            <td><?= h($l['Mat_elv']) ?></td>
            <td><?= h($l['Nom_elv'] . ' ' . ($l['Prenom_elv'] ?? '')) ?></td>
            <td><?= h($l['DesignationClasses']) ?></td>
            <td class="text-center"><span class="badge bg-info text-dark">-<?= h(rtrim(rtrim(number_format($l['pourcentage'], 2, '.', ''), '0'), '.')) ?>%</span></td>
            <td class="text-end text-muted" style="text-decoration:line-through"><?= number_format($l['montant_normal'], 0, ',', ' ') ?> F</td>
            <td class="text-end fw-bold"><?= number_format($l['du'], 0, ',', ' ') ?> F</td>
            <td class="text-end"><?= number_format($l['paye'], 0, ',', ' ') ?> F</td>
            <td class="text-end fw-bold" style="color:<?= $l['solde'] > 0 ? '#dc2626' : '#16a34a' ?>"><?= number_format($l['solde'], 0, ',', ' ') ?> F</td>
            <td class="text-center">
              <a href="<?= APP_URL ?>/pages/finances/versement.php?classe=<?= (int) $l['IDClasses'] ?>&eleve=<?= (int) $l['id_eleve'] ?>" class="btn btn-sm btn-light" style="padding:2px 7px" title="Voir/encaisser">
                <i class="bi bi-cash-coin" style="font-size:.75rem"></i>
              </a>
              <a href="<?= APP_URL ?>/pages/eleves/voir.php?id=<?= (int) $l['id_eleve'] ?>" class="btn btn-sm btn-light" style="padding:2px 7px" title="Fiche élève">
                <i class="bi bi-person-vcard" style="font-size:.75rem"></i>
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$cas_sociaux): ?><tr><td colspan="9" class="text-center text-muted py-3">Aucun cas social pour cette sélection.</td></tr><?php endif; ?>
      </tbody>
      <?php if ($cas_sociaux): ?>
      <tfoot><tr class="fw-bold">
        <td colspan="4">TOTAL (<?= count($cas_sociaux) ?>)</td>
        <td class="text-end"><?= number_format($total_normal, 0, ',', ' ') ?> F</td>
        <td class="text-end"><?= number_format($total_du, 0, ',', ' ') ?> F</td>
        <td class="text-end"><?= number_format($total_paye, 0, ',', ' ') ?> F</td>
        <td class="text-end"><?= number_format($total_du - $total_paye, 0, ',', ' ') ?> F</td>
        <td></td>
      </tr></tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>

<script>
function urlAvecFiltres(base) {
    const p = new URLSearchParams({ niveau: '<?= h($niveau_f) ?>', classe: '<?= $id_classe_f ?>' });
    return base + '?' + p.toString();
}
function ouvrirCasSociauxPdf() {
    afficherApercu(urlAvecFiltres('<?= APP_URL ?>/pdf/finances_cas_sociaux.php'), 'Cas sociaux', null, 'portrait');
}
document.getElementById('lienExcelCasSociaux').href = urlAvecFiltres('<?= APP_URL ?>/pages/finances/excel_cas_sociaux.php');
</script>

</div><!-- /#cas-sociaux-zone -->
<?php if ($es_partiel) exit; // rien de plus dans une réponse AJAX partielle. ?>

<?php
$ajax_zone_id = 'cas-sociaux-zone'; // voir layout/footer.php — initAjaxZone() y est appelé
require_once __DIR__ . '/../../layout/footer.php';
