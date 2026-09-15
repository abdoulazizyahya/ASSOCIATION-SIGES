<?php
// secondaire/pages/eleves/form.php — création/modification d'un élève
// (école secondaire). Simplifié pour cette 1ʳᵉ étape par rapport à LAM_ABZ
// (pas encore de photo/recadrage Cropper.js, ni de cascade région→
// département→arrondissement — eleve.region_naiss/departement_naiss/
// arrondissement_naiss sont de simples champs texte dans ce schéma, pas
// des FK comme côté primaire, donc de simples <input> suffisent).
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'CENSEUR', 'SG', 'SECRETAIRE']);

$id    = (int) ($_GET['id'] ?? 0);
$eleve = $id ? db_one("SELECT * FROM eleve WHERE id=?", [$id]) : null;
if ($id && !$eleve) { flash_set('erreur', 'Élève introuvable.'); rediriger('secondaire/pages/eleves/liste.php'); }

$annee       = get_annee_active();
$id_annee    = (int) ($annee['id'] ?? 0);
$classes     = db_all("SELECT * FROM classe WHERE archivee=0 ORDER BY ordre, designation");
$inscription = $eleve ? db_one("SELECT * FROM inscription WHERE id_eleve=? AND id_annee=?", [$eleve['id'], $id_annee]) : null;
$ve          = fn(string $k) => h($eleve[$k] ?? '');

$titre_page = $eleve ? 'Modifier un élève' : 'Nouvel élève';
require_once __DIR__ . '/../../../layout/header.php';
?>

<div class="d-flex align-items-center gap-2 mb-3">
  <a href="<?= APP_URL ?>/secondaire/pages/eleves/liste.php" class="btn btn-sm btn-light"><i class="bi bi-arrow-left"></i></a>
  <div>
    <h4 class="mb-0" style="font-size:1.05rem;font-weight:700"><?= h($titre_page) ?></h4>
    <div class="sub"><?= $eleve ? h($eleve['matricule']) : 'Remplissez le formulaire' ?></div>
  </div>
</div>

<?= flash_html() ?>

<form method="post" action="<?= APP_URL ?>/secondaire/pages/eleves/save.php">
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
            <div class="col-md-4">
              <label class="form-label">Région de naissance</label>
              <input type="text" name="region_naiss" class="form-control" value="<?= $ve('region_naiss') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Département de naissance</label>
              <input type="text" name="departement_naiss" class="form-control" value="<?= $ve('departement_naiss') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Arrondissement de naissance</label>
              <input type="text" name="arrondissement_naiss" class="form-control" value="<?= $ve('arrondissement_naiss') ?>">
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
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="mt-3 d-flex gap-2">
    <button class="btn btn-primary btn-sm px-4"><i class="bi bi-check-lg me-1"></i>Enregistrer</button>
    <a href="<?= APP_URL ?>/secondaire/pages/eleves/liste.php" class="btn btn-light btn-sm">Annuler</a>
  </div>
</form>

<?php require_once __DIR__ . '/../../../layout/footer.php'; ?>
