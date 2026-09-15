<?php
// secondaire/pages/enseignants/form.php — création/modification d'un
// membre du personnel (école secondaire). Champs essentiels seulement pour
// cette étape (carrière/administratif détaillé — arrêté de recrutement,
// affectation, etc. — laissé pour une itération future si besoin, LAM_ABZ
// a le modèle complet dans pages/enseignants/form.php).
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'CENSEUR']);

$mat = (int) ($_GET['id'] ?? 0);
$ens = $mat ? db_one("SELECT * FROM enseignant WHERE matricule_ens=?", [$mat]) : null;
if ($mat && !$ens) { flash_set('erreur', 'Membre du personnel introuvable.'); rediriger('secondaire/pages/enseignants/liste.php'); }
$ve = fn(string $k) => h($ens[$k] ?? '');

$titre_page = $ens ? 'Modifier un membre du personnel' : 'Nouveau membre du personnel';
require_once __DIR__ . '/../../../layout/header.php';
?>

<div class="d-flex align-items-center gap-2 mb-3">
  <a href="<?= APP_URL ?>/secondaire/pages/enseignants/liste.php" class="btn btn-sm btn-light"><i class="bi bi-arrow-left"></i></a>
  <h4 class="mb-0" style="font-size:1.05rem;font-weight:700"><?= h($titre_page) ?></h4>
</div>

<?= flash_html() ?>

<form method="post" action="<?= APP_URL ?>/secondaire/pages/enseignants/save.php">
  <?= csrf_champ() ?>
  <input type="hidden" name="mat" value="<?= (int) ($ens['matricule_ens'] ?? 0) ?>">

  <div class="card mb-2">
    <div class="card-body">
      <div class="section-titre"><i class="bi bi-person me-1"></i>Identité</div>
      <div class="row g-compact">
        <div class="col-md-2">
          <label class="form-label">Civilité</label>
          <select name="civilite" class="form-select">
            <?php foreach (['M.', 'Mme', 'Mlle'] as $c): ?>
              <option <?= ($ens['civilite_ens'] ?? 'M.') === $c ? 'selected' : '' ?>><?= $c ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-5">
          <label class="form-label">Nom <span class="text-danger">*</span></label>
          <input type="text" name="nom" class="form-control" required value="<?= $ve('nom_ens') ?>">
        </div>
        <div class="col-md-5">
          <label class="form-label">Prénom(s)</label>
          <input type="text" name="prenom" class="form-control" value="<?= $ve('prenom_ens') ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Sexe</label>
          <select name="sexe" class="form-select">
            <option value="Masculin" <?= ($ens['sexe_ens'] ?? 'Masculin') === 'Masculin' ? 'selected' : '' ?>>Masculin</option>
            <option value="Feminin" <?= ($ens['sexe_ens'] ?? '') === 'Feminin' ? 'selected' : '' ?>>Féminin</option>
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
          <label class="form-label">Téléphone</label>
          <input type="text" name="tel" class="form-control" value="<?= $ve('tel_ens') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Email</label>
          <input type="email" name="mail" class="form-control" value="<?= $ve('mail_ens') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Situation matrimoniale</label>
          <select name="situation" class="form-select">
            <option value="">—</option>
            <?php foreach (['Celibataire' => 'Célibataire', 'Marie' => 'Marié(e)', 'Divorce' => 'Divorcé(e)', 'Veuf' => 'Veuf/Veuve'] as $v => $lbl): ?>
              <option value="<?= $v ?>" <?= ($ens['situation_matrimoniale'] ?? '') === $v ? 'selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-body">
      <div class="section-titre"><i class="bi bi-briefcase me-1"></i>Poste</div>
      <div class="row g-compact">
        <div class="col-md-4">
          <label class="form-label">Fonction</label>
          <input type="text" name="fonction" class="form-control" value="<?= $ve('id_fonction') ?>" placeholder="ex: Enseignant, Surveillant...">
        </div>
        <div class="col-md-4">
          <label class="form-label">Grade</label>
          <input type="text" name="grade" class="form-control" value="<?= $ve('id_grade') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Matière enseignée</label>
          <input type="text" name="matiere_enseignee" class="form-control" value="<?= $ve('matiere_enseignee') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Diplôme</label>
          <input type="text" name="diplome" class="form-control" value="<?= $ve('diplome') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Spécialité</label>
          <input type="text" name="specialite" class="form-control" value="<?= $ve('specialite') ?>">
        </div>
      </div>
    </div>
  </div>

  <div class="mt-3 d-flex gap-2">
    <button class="btn btn-primary btn-sm px-4"><i class="bi bi-check-lg me-1"></i>Enregistrer</button>
    <a href="<?= APP_URL ?>/secondaire/pages/enseignants/liste.php" class="btn btn-light btn-sm">Annuler</a>
  </div>
</form>

<?php require_once __DIR__ . '/../../../layout/footer.php'; ?>
