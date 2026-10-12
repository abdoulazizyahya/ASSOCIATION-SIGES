<?php
// secondaire/commun/pages/eleves/form.php — création/modification d'un élève
// (école secondaire). Simplifié pour cette 1ʳᵉ étape par rapport à LAM_ABZ
// (pas encore de photo/recadrage Cropper.js, ni de cascade région→
// département→arrondissement — eleve.region_naiss/departement_naiss/
// arrondissement_naiss sont de simples champs texte dans ce schéma, pas
// des FK comme côté primaire, donc de simples <input> suffisent).
require_once __DIR__ . '/../../../../config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'FONDATEUR', 'CENSEUR', 'SG', 'SECRETAIRE']);

$id    = (int) ($_GET['id'] ?? 0);
$eleve = $id ? db_one("SELECT * FROM eleve WHERE id=?", [$id]) : null;
if ($id && !$eleve) { flash_set('erreur', 'Élève introuvable.'); rediriger('secondaire/commun/pages/eleves/liste.php'); }

$annee       = get_annee_active();
$id_annee    = (int) ($annee['id'] ?? 0);
$classes     = db_all("SELECT * FROM classe WHERE archivee=0 ORDER BY ordre, designation");
$series      = db_all("SELECT * FROM serie ORDER BY libelle");
$inscription = $eleve ? db_one("SELECT * FROM inscription WHERE id_eleve=? AND id_annee=?", [$eleve['id'], $id_annee]) : null;
$ve          = fn(string $k) => h($eleve[$k] ?? '');

$titre_page = $eleve ? 'Modifier un élève' : 'Nouvel élève';
require_once __DIR__ . '/../../../../noyau/layout/header.php';
?>

<div class="d-flex align-items-center gap-2 mb-3">
  <a href="<?= APP_URL ?>/secondaire/commun/pages/eleves/liste.php" class="btn btn-sm btn-light"><i class="bi bi-arrow-left"></i></a>
  <div>
    <h4 class="mb-0" style="font-size:1.05rem;font-weight:700"><?= h($titre_page) ?></h4>
    <div class="sub"><?= $eleve ? h($eleve['matricule']) : 'Remplissez le formulaire' ?></div>
  </div>
</div>

<?= flash_html() ?>

<form method="post" action="<?= APP_URL ?>/secondaire/commun/pages/eleves/save.php">
  <?= csrf_champ() ?>
  <input type="hidden" name="id" value="<?= (int) ($eleve['id'] ?? 0) ?>">
  <input type="hidden" name="id_annee" value="<?= $id_annee ?>">

  <div class="row g-2">
    <div class="col-12">
      <div class="card mb-2">
        <div class="card-body">
          <div class="section-titre"><i class="bi bi-person me-1"></i>Informations personnelles</div>
          <div class="row g-compact">
            <div class="col-md-6">
              <label class="form-label">Nom <span class="text-danger">*</span></label>
              <input type="text" name="nom" class="form-control" required value="<?= $ve('nom') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Prénom(s)</label>
              <input type="text" name="prenom" class="form-control" value="<?= $ve('prenom') ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Sexe</label>
              <select name="sexe" class="form-select">
                <option value="M" <?= ($eleve['sexe'] ?? 'M') === 'M' ? 'selected' : '' ?>>Masculin</option>
                <option value="F" <?= ($eleve['sexe'] ?? '') === 'F' ? 'selected' : '' ?>>Féminin</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Date de naissance</label>
              <input type="date" name="date_naiss" class="form-control" value="<?= $ve('date_naiss') ?>">
            </div>
            <div class="col-md-5">
              <label class="form-label">Lieu de naissance</label>
              <input type="text" name="lieu_naiss" class="form-control" value="<?= $ve('lieu_naiss') ?>">
            </div>
            <?php
              // Listes en cascade région → département → arrondissement
              // (tables region/departement/arrondissement, enregistrées par
              // NOM comme avant). Une valeur déjà saisie absente des listes
              // (ancienne saisie libre) reste proposée et sélectionnée.
              $regions_geo = db_all("SELECT nom FROM region ORDER BY nom");
              $region_act  = (string) ($eleve['region_naiss'] ?? '');
            ?>
            <div class="col-md-4">
              <label class="form-label">Région de naissance</label>
              <select name="region_naiss" id="selRegionNaiss" class="form-select">
                <option value="">— Choisir —</option>
                <?php $vu = false; foreach ($regions_geo as $r): $vu = $vu || $r['nom'] === $region_act; ?>
                  <option value="<?= h($r['nom']) ?>" <?= $r['nom'] === $region_act ? 'selected' : '' ?>><?= h($r['nom']) ?></option>
                <?php endforeach; ?>
                <?php if ($region_act !== '' && !$vu): ?>
                  <option value="<?= h($region_act) ?>" selected><?= h($region_act) ?></option>
                <?php endif; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Département de naissance</label>
              <select name="departement_naiss" id="selDeptNaiss" class="form-select">
                <option value="">— Choisir une région d'abord —</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Arrondissement de naissance</label>
              <select name="arrondissement_naiss" id="selArrNaiss" class="form-select">
                <option value="">— Choisir un département d'abord —</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">NIU</label>
              <input type="text" name="niu" class="form-control" value="<?= $ve('niu') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Téléphone</label>
              <input type="text" name="telephone" class="form-control" value="<?= $ve('telephone') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Adresse</label>
              <input type="text" name="adresse" class="form-control" value="<?= $ve('adresse') ?>">
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-body">
          <div class="section-titre"><i class="bi bi-journal-check me-1"></i>Inscription — <?= h($annee['val_annee'] ?? '—') ?></div>
          <div class="row g-compact">
            <div class="col-md-7">
              <label class="form-label">Classe</label>
              <select name="id_classe" class="form-select">
                <option value="">— Non inscrit —</option>
                <?php foreach ($classes as $c): ?>
                  <option value="<?= (int) $c['id'] ?>" <?= (int) ($inscription['id_classe'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>
                    <?= h($c['designation']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-5">
              <label class="form-label">Statut scolaire</label>
              <select name="statut_insc" class="form-select">
                <?php foreach (['Nouveau', 'Ancien', 'Redoublant', 'Transféré'] as $s): ?>
                  <option <?= ($inscription['statut'] ?? '') === $s ? 'selected' : '' ?>><?= $s ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-5">
              <label class="form-label">Série / LV2 <span class="text-muted" style="font-weight:400">(classe mixte)</span></label>
              <select name="id_serie" class="form-select">
                <option value="">— Aucune —</option>
                <?php foreach ($series as $s): ?>
                  <option value="<?= (int) $s['id'] ?>" <?= (int) ($inscription['id_serie'] ?? 0) === (int) $s['id'] ? 'selected' : '' ?>>
                    <?= h($s['libelle']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="mt-3 d-flex gap-2">
    <button class="btn btn-primary btn-sm px-4"><i class="bi bi-check-lg me-1"></i>Enregistrer</button>
    <a href="<?= APP_URL ?>/secondaire/commun/pages/eleves/liste.php" class="btn btn-light btn-sm">Annuler</a>
  </div>
</form>

<script>
(function () {
  var selRegion = document.getElementById('selRegionNaiss');
  var selDept   = document.getElementById('selDeptNaiss');
  var selArr    = document.getElementById('selArrNaiss');
  var deptInit  = <?= json_encode((string) ($eleve['departement_naiss'] ?? '')) ?>;
  var arrInit   = <?= json_encode((string) ($eleve['arrondissement_naiss'] ?? '')) ?>;

  function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
  // Remplit une liste ; la valeur à présélectionner est ajoutée si absente.
  function remplir(sel, items, vide, choix) {
    var html = '<option value="">' + vide + '</option>', trouve = false;
    items.forEach(function (it) {
      var s = it.nom === choix; trouve = trouve || s;
      html += '<option value="' + esc(it.nom) + '"' + (s ? ' selected' : '') + '>' + esc(it.nom) + '</option>';
    });
    if (choix && !trouve) html += '<option value="' + esc(choix) + '" selected>' + esc(choix) + '</option>';
    sel.innerHTML = html;
  }
  function charger(url, sel, vide, choix, ensuite) {
    sel.innerHTML = '<option value="">Chargement…</option>';
    fetch(url).then(function (r) { return r.json(); })
      .then(function (items) { remplir(sel, Array.isArray(items) ? items : [], vide, choix); if (ensuite) ensuite(); })
      .catch(function () { remplir(sel, [], 'Erreur de chargement', choix); });
  }
  function departements(choixDept, choixArr) {
    if (!selRegion.value) { remplir(selDept, [], "— Choisir une région d'abord —", choixDept); arrondissements(choixArr); return; }
    charger('<?= APP_URL ?>/noyau/ajax/departements_par_region.php?nom_region=' + encodeURIComponent(selRegion.value),
            selDept, '— Choisir —', choixDept, function () { arrondissements(choixArr); });
  }
  function arrondissements(choixArr) {
    if (!selDept.value) { remplir(selArr, [], "— Choisir un département d'abord —", choixArr); return; }
    charger('<?= APP_URL ?>/noyau/ajax/arrondissements_par_departement.php?departement=' + encodeURIComponent(selDept.value),
            selArr, '— Choisir —', choixArr);
  }
  selRegion.addEventListener('change', function () { departements('', ''); });
  selDept.addEventListener('change', function () { arrondissements(''); });
  departements(deptInit, arrInit);
})();
</script>

<?php require_once __DIR__ . '/../../../../noyau/layout/footer.php'; ?>
