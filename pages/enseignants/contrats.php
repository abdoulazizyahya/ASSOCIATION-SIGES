<?php
// pages/enseignants/contrats.php — Historique des contrats d'un membre du
// personnel : ajout + clôture (un seul contrat "actif" à la fois, au sens
// métier — rien n'empêche techniquement d'en avoir plusieurs, mais la
// clôture automatique de l'ancien à l'ajout d'un nouveau évite l'oubli).
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/../../paie_fonctions.php';
exiger_role(['DIRECTEUR']);

$mat = (int) ($_GET['mat'] ?? 0);
$ens = db_one("SELECT matricule_ens, nom_ens, prenom_ens FROM enseignant WHERE matricule_ens=?", [$mat]);
if (!$ens) { flash_set('erreur', 'Membre du personnel introuvable.'); rediriger('pages/enseignants/liste.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $action = post('action');

    if ($action === 'ajouter') {
        $type       = post('type_contrat');
        $date_debut = post('date_debut');
        $date_fin   = post('date_fin') ?: null;
        $remarques  = post('remarques') ?: null;

        if (!$type || !$date_debut) {
            flash_set('erreur', 'Type de contrat et date de début sont requis.');
        } else {
            // Clôture les contrats encore marqués actifs — un nouveau
            // contrat en remplace implicitement un ancien (embauche
            // renouvelée), évite d'avoir 2 contrats "actifs" en même temps
            // par simple oubli de décocher l'ancien.
            db_exec("UPDATE contrat_enseignant SET actif=0 WHERE matricule_ens=? AND actif=1", [$mat]);
            db_exec(
                "INSERT INTO contrat_enseignant (matricule_ens, type_contrat, date_debut, date_fin, actif, remarques) VALUES (?, ?, ?, ?, 1, ?)",
                [$mat, $type, $date_debut, $date_fin, $remarques]
            );
            flash_set('succes', 'Contrat ajouté.');
        }
    }

    if ($action === 'cloturer') {
        $id = (int) post('id');
        db_exec("UPDATE contrat_enseignant SET actif=0, date_fin=COALESCE(date_fin, CURDATE()) WHERE id=? AND matricule_ens=?", [$id, $mat]);
        flash_set('succes', 'Contrat clôturé.');
    }

    if ($action === 'supprimer') {
        $id = (int) post('id');
        db_exec("DELETE FROM contrat_enseignant WHERE id=? AND matricule_ens=?", [$id, $mat]);
        flash_set('succes', 'Contrat supprimé.');
    }

    rediriger('pages/enseignants/contrats.php?mat=' . $mat);
}

$contrats = db_all("SELECT * FROM contrat_enseignant WHERE matricule_ens=? ORDER BY date_debut DESC, id DESC", [$mat]);

$es_partiel = isset($_GET['partiel']);
if (!$es_partiel) {
    $titre_page = 'Contrats';
    require_once __DIR__ . '/../../layout/header.php';
} else {
    header('Content-Type: text/html; charset=utf-8');
}
?>

<div id="contrats-zone">

<div class="page-titre">
  <div>
    <h4><i class="bi bi-file-earmark-text me-1 text-primary"></i>Contrats</h4>
    <div class="sub"><?= h(mb_strtoupper($ens['nom_ens'])) ?> <?= h($ens['prenom_ens'] ?? '') ?></div>
  </div>
  <a href="<?= APP_URL ?>/pages/enseignants/voir.php?mat=<?= $mat ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left me-1"></i>Retour à la fiche
  </a>
</div>

<div class="card mb-2">
  <div class="card-header py-2" style="background:#f8faff"><span class="fw-semibold" style="font-size:.82rem">Nouveau contrat</span></div>
  <div class="card-body">
    <form method="post" data-ajax-post-form>
      <?= csrf_champ() ?>
      <input type="hidden" name="action" value="ajouter">
      <div class="row g-2 align-items-end">
        <div class="col-md-3">
          <label class="form-label">Type de contrat</label>
          <select name="type_contrat" class="form-select form-select-sm" required>
            <?php foreach (['Permanent', 'Vacataire', 'CDD', 'Stagiaire'] as $t): ?>
              <option value="<?= $t ?>"><?= h(libelle_type_contrat($t)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Date de début</label>
          <input type="date" name="date_debut" class="form-control form-control-sm" required value="<?= date('Y-m-d') ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Date de fin (optionnel)</label>
          <input type="date" name="date_fin" class="form-control form-control-sm">
        </div>
        <div class="col-md-3">
          <button class="btn btn-primary btn-sm w-100"><i class="bi bi-check-lg me-1"></i>Ajouter</button>
        </div>
        <div class="col-12">
          <label class="form-label">Remarques</label>
          <input type="text" name="remarques" class="form-control form-control-sm" placeholder="optionnel">
        </div>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-abz table-hover align-middle mb-0" style="font-size:.82rem">
      <thead><tr><th>Type</th><th>Début</th><th>Fin</th><th>Statut</th><th>Remarques</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
        <?php if (!$contrats): ?>
          <tr><td colspan="6" class="text-center text-muted py-3">Aucun contrat enregistré.</td></tr>
        <?php else: foreach ($contrats as $c): ?>
          <tr>
            <td><?= h(libelle_type_contrat($c['type_contrat'])) ?></td>
            <td><?= date_fr($c['date_debut']) ?></td>
            <td><?= $c['date_fin'] ? date_fr($c['date_fin']) : '—' ?></td>
            <td>
              <span class="badge" style="background:<?= $c['actif'] ? '#dcfce7' : '#f3f4f6' ?>;color:<?= $c['actif'] ? '#166534' : '#6b7280' ?>;font-size:.7rem">
                <?= $c['actif'] ? 'Actif' : 'Clôturé' ?>
              </span>
            </td>
            <td class="text-muted"><?= h($c['remarques'] ?: '—') ?></td>
            <td class="text-end">
              <?php if ($c['actif']): ?>
                <form method="post" class="d-inline" data-ajax-post-form onsubmit="return confirm('Clôturer ce contrat ?')">
                  <?= csrf_champ() ?>
                  <input type="hidden" name="action" value="cloturer">
                  <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                  <button class="btn btn-sm btn-light" style="padding:2px 6px" title="Clôturer"><i class="bi bi-x-circle text-warning" style="font-size:.78rem"></i></button>
                </form>
              <?php endif; ?>
              <form method="post" class="d-inline" data-ajax-post-form onsubmit="return confirm('Supprimer ce contrat ?')">
                <?= csrf_champ() ?>
                <input type="hidden" name="action" value="supprimer">
                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                <button class="btn btn-sm btn-light" style="padding:2px 6px" title="Supprimer"><i class="bi bi-trash text-danger" style="font-size:.78rem"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

</div><!-- /#contrats-zone -->
<?php if ($es_partiel) exit; // rien de plus dans une réponse AJAX partielle. ?>

<?php
$ajax_zone_id = 'contrats-zone'; // voir layout/footer.php — initAjaxZone() y est appelé
require_once __DIR__ . '/../../layout/footer.php';
