<?php
// pages/depenses/saisie.php — Dépenses > Nouvelle dépense : enregistrement
// des décaissements de l'établissement (`depense`, migration v33), classés
// par catégorie. Les dépenses sont effectuées à partir des montants
// encaissés lors des versements élèves (paiement_frais) — le solde de
// caisse disponible (solde_caisse(), fonctions.php) est affiché en direct ;
// une dépense qui le ferait passer sous zéro déclenche un AVERTISSEMENT
// (pas un blocage — l'établissement peut avoir d'autres ressources non
// suivies ici, mais doit le voir clairement avant de confirmer).
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_role(['DIRECTEUR', 'SECRETAIRE']);

$annee     = get_annee_active();
$val_annee = $annee['val_annee'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $action = post('action');

    if ($action === 'creer') {
        $id_categorie  = (int) post('id_categorie');
        $libelle       = post('libelle');
        $montant       = (float) str_replace([' ', ','], ['', '.'], post('montant'));
        $date_depense  = post('date_depense') ?: date('Y-m-d');
        $beneficiaire  = post('beneficiaire') ?: null;
        $observation   = post('observation') ?: null;

        if (!$id_categorie || $libelle === '' || $montant <= 0 || !$val_annee) {
            flash_set('erreur', 'Catégorie, libellé et montant (positif) sont requis.');
            rediriger('pages/depenses/saisie.php');
        }

        db_exec(
            "INSERT INTO depense (id_categorie, libelle, montant, date_depense, val_annee, id_utilisateur, beneficiaire, observation)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [$id_categorie, $libelle, $montant, $date_depense, $val_annee, utilisateur_connecte()['id'] ?? null, $beneficiaire, $observation]
        );
        flash_set('succes', 'Dépense enregistrée — ' . finances_numero_bon((int) db_last_id()) . '.');
        rediriger('pages/depenses/saisie.php');
    }

    if ($action === 'supprimer') {
        $id = (int) post('id_depense');
        db_exec("DELETE FROM depense WHERE id_depense=?", [$id]);
        flash_set('succes', 'Dépense supprimée.');
        rediriger('pages/depenses/saisie.php');
    }
}

$categories = db_all("SELECT * FROM categorie_depense ORDER BY libelle");

$solde = solde_caisse($val_annee);

$recentes = db_all(
    "SELECT d.*, cd.libelle AS categorie_libelle, ag.nom_ens, ag.prenom_ens
     FROM depense d
     JOIN categorie_depense cd ON cd.id_categorie = d.id_categorie
     LEFT JOIN user u ON u.id_user = d.id_utilisateur
     LEFT JOIN enseignant ag ON ag.matricule_ens = u.matricule_ens
     WHERE d.val_annee = ?
     ORDER BY d.date_depense DESC, d.id_depense DESC
     LIMIT 20",
    [$val_annee]
);

$es_partiel = isset($_GET['partiel']);
if (!$es_partiel) {
    $titre_page = 'Nouvelle dépense';
    require_once __DIR__ . '/../../layout/header.php';
} else {
    header('Content-Type: text/html; charset=utf-8');
}
?>

<div id="depense-saisie-zone">

<div class="page-titre d-flex justify-content-between align-items-center flex-wrap gap-2">
  <div>
    <h4><i class="bi bi-dash-circle me-1 text-primary"></i>Dépenses — Nouvelle dépense</h4>
    <div class="sub">Année <?= h($val_annee) ?></div>
  </div>
  <div class="card text-center py-1 px-3" style="border-color:<?= $solde >= 0 ? '#86efac' : '#fca5a5' ?>">
    <div class="text-muted" style="font-size:.66rem">SOLDE DE CAISSE DISPONIBLE</div>
    <div class="fw-bold" style="font-size:1.1rem;color:<?= $solde >= 0 ? '#15803d' : '#dc2626' ?>">
      <?= number_format($solde, 0, ',', ' ') ?> F
    </div>
  </div>
</div>

<?php if (!$categories): ?>
  <div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle me-1"></i>Créez d'abord une catégorie de dépense
    (<a href="<?= APP_URL ?>/pages/depenses/categories.php">Catégories</a>) avant d'enregistrer une dépense.
  </div>
<?php else: ?>

<div class="row g-2" style="align-items:flex-start">

  <div class="col-md-4">
    <div class="card h-100" style="border:1px solid #c7d2fe">
      <div class="card-header py-2 px-3" style="background:linear-gradient(135deg,#eef2ff,#e0e7ff);border-bottom:1px solid #c7d2fe">
        <span class="fw-bold" style="font-size:.8rem;color:#312e81">
          <i class="bi bi-plus-circle-fill me-1"></i>Enregistrer une dépense
        </span>
      </div>
      <div class="card-body p-3">
        <form method="post" id="form-dep" data-ajax-post-form>
          <?= csrf_champ() ?>
          <input type="hidden" name="action" value="creer">
          <div class="mb-2">
            <label class="form-label" style="font-size:.75rem">Catégorie</label>
            <select name="id_categorie" class="form-select form-select-sm" required>
              <option value="">— Choisir —</option>
              <?php foreach ($categories as $c): ?>
                <option value="<?= (int) $c['id_categorie'] ?>"><?= h($c['libelle']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-2">
            <label class="form-label" style="font-size:.75rem">Libellé / motif</label>
            <input type="text" name="libelle" class="form-control form-control-sm" maxlength="200" placeholder="ex. Achat de craies, Facture ENEO..." required>
          </div>
          <div class="row g-2 mb-2">
            <div class="col-6">
              <label class="form-label" style="font-size:.75rem">Montant (FCFA)</label>
              <input type="number" name="montant" id="dep_montant" class="form-control form-control-sm" min="1" step="1" required>
            </div>
            <div class="col-6">
              <label class="form-label" style="font-size:.75rem">Date</label>
              <input type="date" name="date_depense" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required>
            </div>
          </div>
          <div class="mb-2">
            <label class="form-label" style="font-size:.75rem">Bénéficiaire / fournisseur (optionnel)</label>
            <input type="text" name="beneficiaire" class="form-control form-control-sm" maxlength="150">
          </div>
          <div class="mb-2">
            <label class="form-label" style="font-size:.75rem">Observation (optionnel)</label>
            <textarea name="observation" class="form-control form-control-sm" rows="2" maxlength="255"></textarea>
          </div>
          <div id="dep-alerte-solde" class="alert alert-warning py-2 d-none" style="font-size:.75rem">
            <i class="bi bi-exclamation-triangle me-1"></i>
            Ce montant dépasse le solde de caisse disponible (<?= number_format($solde, 0, ',', ' ') ?> F) — vérifiez avant de confirmer.
          </div>
          <button type="submit" class="btn btn-sm btn-primary w-100"><i class="bi bi-check-lg me-1"></i>Enregistrer la dépense</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-md-8">
    <div class="card h-100" style="border:1px solid #e5e7eb">
      <div class="card-header py-2 px-3 d-flex justify-content-between align-items-center" style="background:#f8faff;border-bottom:1px solid #e5e7eb">
        <span class="fw-bold" style="font-size:.8rem;color:#374151">Dépenses récentes</span>
        <a href="<?= APP_URL ?>/pages/depenses/journal.php" class="btn btn-sm btn-outline-secondary" style="font-size:.72rem">
          <i class="bi bi-journal-text me-1"></i>Journal complet
        </a>
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0" style="font-size:.78rem">
          <thead style="background:#fbfbfd">
            <tr>
              <th>Bon</th><th>Date</th><th>Catégorie</th><th>Libellé</th>
              <th class="text-end">Montant</th><th>Par</th><th style="width:40px"></th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$recentes): ?>
              <tr><td colspan="7" class="text-center text-muted py-3">Aucune dépense enregistrée pour cette année.</td></tr>
            <?php else: foreach ($recentes as $d): ?>
              <tr>
                <td class="text-muted"><?= h(finances_numero_bon((int) $d['id_depense'])) ?></td>
                <td><?= h(date_fr($d['date_depense'])) ?></td>
                <td><span class="badge bg-light text-dark border"><?= h($d['categorie_libelle']) ?></span></td>
                <td><?= h($d['libelle']) ?></td>
                <td class="text-end fw-semibold text-danger"><?= number_format((float) $d['montant'], 0, ',', ' ') ?> F</td>
                <td><?= $d['nom_ens'] ? h(trim($d['prenom_ens'] . ' ' . $d['nom_ens'])) : '<span class="text-muted">—</span>' ?></td>
                <td class="text-end">
                  <form method="post" data-ajax-post-form onsubmit="return confirm('Supprimer cette dépense ?')">
                    <?= csrf_champ() ?>
                    <input type="hidden" name="action" value="supprimer">
                    <input type="hidden" name="id_depense" value="<?= (int) $d['id_depense'] ?>">
                    <button type="submit" class="btn btn-sm btn-light text-danger" style="padding:2px 6px">
                      <i class="bi bi-trash" style="font-size:.68rem"></i>
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
document.getElementById('dep_montant').addEventListener('input', function () {
    var solde = <?= json_encode($solde) ?>;
    var alerte = document.getElementById('dep-alerte-solde');
    alerte.classList.toggle('d-none', !(parseFloat(this.value) > solde));
});
</script>

<?php endif; // categories ?>

</div><!-- /#depense-saisie-zone -->
<?php if ($es_partiel) exit; // rien de plus dans une réponse AJAX partielle. ?>

<?php
$ajax_zone_id = 'depense-saisie-zone'; // voir layout/footer.php — initAjaxZone() y est appelé
require_once __DIR__ . '/../../layout/footer.php';
