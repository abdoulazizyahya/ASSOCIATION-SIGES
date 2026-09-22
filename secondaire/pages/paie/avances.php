<?php
// secondaire/pages/paie/avances.php — Avances sur salaire, porté de
// pages/paie/avances.php (primaire) à l'identique — voir secondaire/pages/
// paie/index.php pour la note sur paie_fonctions.php.
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
require_once __DIR__ . '/../../../paie_fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'FONDATEUR', 'INTENDANT']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $action = post('action');
    $mat    = (int) post('mat');

    if ($action === 'octroyer') {
        $montant = (float) str_replace([' ', ','], ['', '.'], post('montant'));
        $date    = post('date_avance') ?: date('Y-m-d');
        $motif   = post('motif') ?: null;
        if (!$mat || $montant <= 0) {
            flash_set('erreur', 'Enseignant et montant (positif) sont requis.');
        } else {
            db_exec(
                "INSERT INTO avance_salaire (matricule_ens, montant, date_avance, motif, id_utilisateur) VALUES (?, ?, ?, ?, ?)",
                [$mat, $montant, $date, $motif, utilisateur_connecte()['id'] ?? null]
            );
            flash_set('succes', 'Avance enregistrée.');
        }
    }

    if ($action === 'supprimer') {
        $id = (int) post('id');
        $deja_rembourse = (float) (db_val("SELECT COALESCE(SUM(montant),0) FROM remboursement_avance WHERE id_avance=?", [$id]) ?? 0);
        if ($deja_rembourse > 0.009) {
            flash_set('erreur', 'Impossible de supprimer : cette avance a déjà été partiellement/totalement déduite sur un bulletin de paie.');
        } else {
            db_exec("DELETE FROM avance_salaire WHERE id=?", [$id]);
            flash_set('succes', 'Avance supprimée.');
        }
    }

    rediriger('secondaire/pages/paie/avances.php?mat=' . $mat);
}

$mat = (int) ($_GET['mat'] ?? 0);
$enseignants = db_all("SELECT matricule_ens, nom_ens, prenom_ens FROM enseignant ORDER BY nom_ens");
$ens = $mat ? db_one("SELECT matricule_ens, nom_ens, prenom_ens FROM enseignant WHERE matricule_ens=?", [$mat]) : null;

$historique = $mat ? db_all("SELECT * FROM avance_salaire WHERE matricule_ens=? ORDER BY date_avance DESC, id DESC", [$mat]) : [];
foreach ($historique as &$a) { $a['solde'] = solde_avance((int) $a['id']); }
unset($a);
$total_du = array_sum(array_column($historique, 'solde'));

$es_partiel = isset($_GET['partiel']);
if (!$es_partiel) {
    $titre_page = 'Avances sur salaire';
    require_once __DIR__ . '/../../../layout/header.php';
} else {
    header('Content-Type: text/html; charset=utf-8');
}
?>

<div id="avances-zone">

<div class="page-titre">
  <h4><i class="bi bi-cash me-1 text-primary"></i>Avances sur salaire</h4>
</div>

<div class="card mb-2">
  <div class="card-body py-2">
    <label class="form-label">Enseignant</label>
    <select id="selEns" class="form-select form-select-sm">
      <option value="">— Choisir —</option>
      <?php foreach ($enseignants as $e): ?>
        <option value="<?= (int) $e['matricule_ens'] ?>" <?= $mat === (int) $e['matricule_ens'] ? 'selected' : '' ?>>
          <?= h(mb_strtoupper($e['nom_ens'])) ?> <?= h($e['prenom_ens'] ?? '') ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
</div>

<?php if ($ens): ?>
<div class="card mb-2">
  <div class="card-body py-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div class="fw-bold" style="font-size:.95rem"><?= h(mb_strtoupper($ens['nom_ens'])) ?> <?= h($ens['prenom_ens'] ?? '') ?></div>
    <div><span class="text-muted" style="font-size:.72rem">TOTAL DÛ</span> <span class="fw-bold" style="color:<?= $total_du > 0 ? '#dc2626' : '#16a34a' ?>"><?= number_format($total_du, 0, ',', ' ') ?> F</span></div>
  </div>
</div>

<div class="card mb-2">
  <div class="card-header py-2" style="background:#f8faff"><span class="fw-semibold" style="font-size:.82rem">Octroyer une avance</span></div>
  <div class="card-body">
    <form method="post" class="row g-2 align-items-end" data-ajax-post-form>
      <?= csrf_champ() ?>
      <input type="hidden" name="action" value="octroyer">
      <input type="hidden" name="mat" value="<?= $mat ?>">
      <div class="col-md-3">
        <label class="form-label">Montant (FCFA)</label>
        <input type="number" name="montant" class="form-control form-control-sm" min="1" step="1" required>
      </div>
      <div class="col-md-3">
        <label class="form-label">Date</label>
        <input type="date" name="date_avance" class="form-control form-control-sm" required value="<?= date('Y-m-d') ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Motif</label>
        <input type="text" name="motif" class="form-control form-control-sm" placeholder="optionnel">
      </div>
      <div class="col-md-2">
        <button class="btn btn-primary btn-sm w-100"><i class="bi bi-check-lg me-1"></i>Octroyer</button>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header py-2" style="background:#f8faff"><span class="fw-semibold" style="font-size:.82rem">Historique</span></div>
  <div class="table-responsive">
    <table class="table table-abz table-hover align-middle mb-0" style="font-size:.82rem">
      <thead><tr><th>Date</th><th>Motif</th><th class="text-end">Montant</th><th class="text-end">Déjà remboursé</th><th class="text-end">Solde dû</th><th class="text-end">Action</th></tr></thead>
      <tbody>
        <?php if (!$historique): ?>
          <tr><td colspan="6" class="text-center text-muted py-3">Aucune avance enregistrée.</td></tr>
        <?php else: foreach ($historique as $a):
          $rembourse = (float) $a['montant'] - $a['solde'];
        ?>
          <tr>
            <td><?= date_fr($a['date_avance']) ?></td>
            <td class="text-muted"><?= h($a['motif'] ?: '—') ?></td>
            <td class="text-end"><?= number_format((float) $a['montant'], 0, ',', ' ') ?> F</td>
            <td class="text-end"><?= number_format($rembourse, 0, ',', ' ') ?> F</td>
            <td class="text-end fw-bold" style="color:<?= $a['solde'] > 0 ? '#dc2626' : '#16a34a' ?>"><?= number_format($a['solde'], 0, ',', ' ') ?> F</td>
            <td class="text-end">
              <form method="post" class="d-inline" data-ajax-post-form onsubmit="return confirm('Supprimer cette avance ?')">
                <?= csrf_champ() ?>
                <input type="hidden" name="action" value="supprimer">
                <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                <input type="hidden" name="mat" value="<?= $mat ?>">
                <button class="btn btn-sm btn-light" style="padding:2px 6px" title="Supprimer"><i class="bi bi-trash text-danger" style="font-size:.78rem"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<script>
document.getElementById('selEns').addEventListener('change', function() {
    var url = '<?= APP_URL ?>/secondaire/pages/paie/avances.php' + (this.value ? '?mat=' + this.value : '');
    chargerPartiel(url, 'avances-zone');
});
</script>

</div><!-- /#avances-zone -->
<?php if ($es_partiel) exit; // rien de plus dans une réponse AJAX partielle. ?>

<?php
$ajax_zone_id = 'avances-zone'; // voir layout/footer.php — initAjaxZone() y est appelé
require_once __DIR__ . '/../../../layout/footer.php';
