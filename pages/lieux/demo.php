<?php
// pages/lieux/demo.php — Page de démonstration/test du widget cascade
// région → département → arrondissement (assets/js/lieu-cascade.js) +
// des endpoints ajax/departements_par_region.php et
// ajax/arrondissements_par_departement.php.
//
// ⚠️ Page technique de vérification, volontairement NON reliée au menu
// (layout/header.php) — sert à valider l'infrastructure avant de la câbler
// sur une vraie fiche (élève/enseignant/tuteur), reporté à une session
// ultérieure une fois la table `arrondissement` peuplée (voir bd/
// sync_arrondissements.php). Accessible via l'URL directe uniquement.
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_connexion();

$regions = db_all("SELECT id_region, intitule_region FROM region ORDER BY intitule_region");

$titre_page = 'Démo — Lieu (région/département/arrondissement)';
require_once __DIR__ . '/../../layout/header.php';
?>

<div class="page-titre">
  <h4><i class="bi bi-geo-alt me-1 text-primary"></i>Démo — Sélecteur de lieu en cascade</h4>
  <div class="sub">Page technique de vérification — non reliée au menu.</div>
</div>

<div class="alert alert-light border py-2 mb-3" style="font-size:.8rem">
  <i class="bi bi-info-circle me-1"></i>
  La table <code>arrondissement</code> est vide tant que la liste officielle n'a pas été importée
  (<code>bd/sync_arrondissements.php</code>) — chaque département affichera donc
  « Aucun arrondissement enregistré » et basculera automatiquement sur « Autre, préciser ».
  C'est le comportement attendu à ce stade.
</div>

<div class="card" style="max-width:640px">
  <div class="card-body">
    <div class="row g-compact">
      <div class="col-md-4">
        <label class="form-label">Région</label>
        <select id="sel_region" class="form-select form-select-sm">
          <option value="">— Choisir —</option>
          <?php foreach ($regions as $r): ?>
            <option value="<?= (int) $r['id_region'] ?>"><?= h($r['intitule_region']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Département</label>
        <select id="sel_departement" class="form-select form-select-sm" disabled>
          <option value="">— Choisir une région d'abord —</option>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Arrondissement</label>
        <select id="sel_arrondissement" name="id_arrondissement" class="form-select form-select-sm" disabled>
          <option value="">— Choisir un département d'abord —</option>
        </select>
      </div>
      <div class="col-md-12">
        <input type="text" id="inp_lieu_libre" name="lieu_libre" class="form-control form-control-sm mt-2"
               style="display:none" placeholder="Préciser le lieu (non répertorié dans la liste officielle)">
      </div>
    </div>

    <div class="mt-3 p-2" style="background:#f8faff;border-radius:8px;font-size:.78rem">
      <strong>Ce qui serait soumis au serveur :</strong>
      <div id="apercu_soumission" class="text-muted mt-1">—</div>
    </div>
  </div>
</div>

<script src="<?= APP_URL ?>/assets/js/lieu-cascade.js"></script>
<script>
initLieuCascade({
    selRegion:         document.getElementById('sel_region'),
    selDepartement:    document.getElementById('sel_departement'),
    selArrondissement: document.getElementById('sel_arrondissement'),
    inputLibre:        document.getElementById('inp_lieu_libre'),
    appUrl:            '<?= APP_URL ?>',
});

// Aperçu en direct de ce qui serait réellement envoyé — illustre le
// contrat "une seule information" (id_arrondissement OU texte libre).
function majApercu() {
    const selArr = document.getElementById('sel_arrondissement');
    const libre  = document.getElementById('inp_lieu_libre');
    const div    = document.getElementById('apercu_soumission');
    if (selArr.value && selArr.value !== '__autre__') {
        div.innerHTML = 'id_arrondissement = <code>' + selArr.value + '</code> (' + selArr.options[selArr.selectedIndex].textContent + ')';
    } else if (libre.value.trim() !== '') {
        div.innerHTML = 'lieu_libre = <code>' + libre.value.trim() + '</code>';
    } else {
        div.textContent = '—';
    }
}
document.getElementById('sel_arrondissement').addEventListener('change', majApercu);
document.getElementById('inp_lieu_libre').addEventListener('input', majApercu);
</script>

<?php require_once __DIR__ . '/../../layout/footer.php'; ?>
