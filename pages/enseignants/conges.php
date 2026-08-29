<?php
// pages/enseignants/conges.php — Congés / absences d'un membre du personnel.
// `deduit_paie=1` (coché uniquement pour "Sans solde"/"Absence non
// justifiée" en pratique) est ce qui relie ce module à la paie —
// jours_absence_deductibles() (paie_fonctions.php) ne compte QUE les congés
// VALIDÉS avec cette case cochée, jamais un Congé payé/Maladie/Maternité.
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/../../paie_fonctions.php';
// DIRECTEUR uniquement, même politique que le reste de pages/enseignants/*
// (liste.php/voir.php) — données de personnel traitées comme plus sensibles
// que les données élèves (SECRETAIRE y a accès, pas ici), déjà le cas avant
// ce module.
exiger_role(['DIRECTEUR']);

$mat = (int) ($_GET['mat'] ?? 0);
$ens = db_one("SELECT matricule_ens, nom_ens, prenom_ens FROM enseignant WHERE matricule_ens=?", [$mat]);
if (!$ens) { flash_set('erreur', 'Membre du personnel introuvable.'); rediriger('pages/enseignants/liste.php'); }

$TYPES = ['Congé payé', 'Maladie', 'Maternité', 'Sans solde', 'Absence non justifiée', 'Autre'];
// Ces 2 types déduisent la paie par défaut (case pré-cochée à la saisie,
// mais reste modifiable — voir JS plus bas) : un Congé payé/Maladie/
// Maternité n'a normalement pas vocation à réduire le salaire.
$TYPES_DEDUCTIBLES_DEFAUT = ['Sans solde', 'Absence non justifiée'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $action = post('action');

    if ($action === 'ajouter') {
        $type        = post('type_conge');
        $date_debut  = post('date_debut');
        $date_fin    = post('date_fin');
        $motif       = post('motif') ?: null;
        $deduit_paie = post('deduit_paie') === '1' ? 1 : 0;

        if (!in_array($type, $TYPES, true) || !$date_debut || !$date_fin || $date_fin < $date_debut) {
            flash_set('erreur', 'Type, dates (fin ≥ début) sont requis.');
        } else {
            $nb_jours = jours_conge_dans_periode($date_debut, $date_fin, $date_debut, $date_fin);
            db_exec(
                "INSERT INTO conge_enseignant (matricule_ens, type_conge, date_debut, date_fin, nb_jours, motif, statut, deduit_paie, id_utilisateur)
                 VALUES (?, ?, ?, ?, ?, ?, 'Validé', ?, ?)",
                [$mat, $type, $date_debut, $date_fin, $nb_jours, $motif, $deduit_paie, utilisateur_connecte()['id'] ?? null]
            );
            flash_set('succes', "Congé enregistré ($nb_jours jour(s)).");
        }
    }

    if ($action === 'supprimer') {
        $id = (int) post('id');
        db_exec("DELETE FROM conge_enseignant WHERE id=? AND matricule_ens=?", [$id, $mat]);
        flash_set('succes', 'Congé supprimé.');
    }

    rediriger('pages/enseignants/conges.php?mat=' . $mat);
}

$conges = db_all("SELECT * FROM conge_enseignant WHERE matricule_ens=? ORDER BY date_debut DESC, id DESC", [$mat]);

$es_partiel = isset($_GET['partiel']);
if (!$es_partiel) {
    $titre_page = 'Congés / Absences';
    require_once __DIR__ . '/../../layout/header.php';
} else {
    header('Content-Type: text/html; charset=utf-8');
}
?>

<div id="conges-zone">

<div class="page-titre">
  <div>
    <h4><i class="bi bi-calendar-x me-1 text-primary"></i>Congés / Absences</h4>
    <div class="sub"><?= h(mb_strtoupper($ens['nom_ens'])) ?> <?= h($ens['prenom_ens'] ?? '') ?></div>
  </div>
  <a href="<?= APP_URL ?>/pages/enseignants/voir.php?mat=<?= $mat ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left me-1"></i>Retour à la fiche
  </a>
</div>

<div class="card mb-2">
  <div class="card-header py-2" style="background:#f8faff"><span class="fw-semibold" style="font-size:.82rem">Nouveau congé / absence</span></div>
  <div class="card-body">
    <form method="post" data-ajax-post-form>
      <?= csrf_champ() ?>
      <input type="hidden" name="action" value="ajouter">
      <div class="row g-2 align-items-end">
        <div class="col-md-3">
          <label class="form-label">Type</label>
          <select name="type_conge" id="type_conge" class="form-select form-select-sm" required onchange="majDeduction()">
            <?php foreach ($TYPES as $t): ?>
              <option value="<?= h($t) ?>"><?= h(libelle_type_conge($t)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Début</label>
          <input type="date" name="date_debut" class="form-control form-control-sm" required value="<?= date('Y-m-d') ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label">Fin</label>
          <input type="date" name="date_fin" class="form-control form-control-sm" required value="<?= date('Y-m-d') ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Motif</label>
          <input type="text" name="motif" class="form-control form-control-sm" placeholder="optionnel">
        </div>
        <div class="col-md-2">
          <button class="btn btn-primary btn-sm w-100"><i class="bi bi-check-lg me-1"></i>Ajouter</button>
        </div>
        <div class="col-12">
          <div class="form-check">
            <input type="checkbox" name="deduit_paie" id="deduit_paie" value="1" class="form-check-input">
            <label class="form-check-label" for="deduit_paie" style="font-size:.8rem">
              Impacte la paie (déduction sur le prochain bulletin — normalement réservé à « Sans solde »/« Absence non justifiée »)
            </label>
          </div>
        </div>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-abz table-hover align-middle mb-0" style="font-size:.82rem">
      <thead><tr><th>Type</th><th>Début</th><th>Fin</th><th class="text-center">Jours</th><th>Motif</th><th class="text-center">Paie</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
        <?php if (!$conges): ?>
          <tr><td colspan="7" class="text-center text-muted py-3">Aucun congé enregistré.</td></tr>
        <?php else: foreach ($conges as $c): ?>
          <tr>
            <td><?= h(libelle_type_conge($c['type_conge'])) ?></td>
            <td><?= date_fr($c['date_debut']) ?></td>
            <td><?= date_fr($c['date_fin']) ?></td>
            <td class="text-center"><?= (int) $c['nb_jours'] ?></td>
            <td class="text-muted"><?= h($c['motif'] ?: '—') ?></td>
            <td class="text-center">
              <?php if ($c['deduit_paie']): ?>
                <span class="badge" style="background:#fee2e2;color:#991b1b;font-size:.68rem">Déduit</span>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td class="text-end">
              <form method="post" class="d-inline" data-ajax-post-form onsubmit="return confirm('Supprimer ce congé ?')">
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

<script>
// var (pas const) : ce script est réexécuté à chaque rechargement AJAX de
// la zone (voir injecterHtmlDansZone(), layout/footer.php) — une
// redéclaration via const lèverait une erreur au 2e rechargement.
var TYPES_DEDUCTIBLES = <?= json_encode($TYPES_DEDUCTIBLES_DEFAUT) ?>;
function majDeduction() {
    var type = document.getElementById('type_conge').value;
    document.getElementById('deduit_paie').checked = TYPES_DEDUCTIBLES.includes(type);
}
majDeduction();
</script>

</div><!-- /#conges-zone -->
<?php if ($es_partiel) exit; // rien de plus dans une réponse AJAX partielle. ?>

<?php
$ajax_zone_id = 'conges-zone'; // voir layout/footer.php — initAjaxZone() y est appelé
require_once __DIR__ . '/../../layout/footer.php';
