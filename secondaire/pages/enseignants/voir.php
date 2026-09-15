<?php
// secondaire/pages/enseignants/voir.php — fiche d'un membre du personnel
// (école secondaire).
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_connexion();

$mat = (int) ($_GET['id'] ?? 0);
$ens = $mat ? db_one("SELECT * FROM enseignant WHERE matricule_ens=?", [$mat]) : null;
if (!$ens) { flash_set('erreur', 'Membre du personnel introuvable.'); rediriger('secondaire/pages/enseignants/liste.php'); }

$titre_page = 'Fiche personnel';
require_once __DIR__ . '/../../../layout/header.php';
?>

<div class="d-flex align-items-center gap-2 mb-3">
  <a href="<?= APP_URL ?>/secondaire/pages/enseignants/liste.php" class="btn btn-sm btn-light"><i class="bi bi-arrow-left"></i></a>
  <div>
    <h4 class="mb-0" style="font-size:1.05rem;font-weight:700">
      <?= h(($ens['civilite_ens'] ? $ens['civilite_ens'] . ' ' : '') . mb_strtoupper($ens['nom_ens']) . ' ' . ($ens['prenom_ens'] ?? '')) ?>
    </h4>
    <div class="sub"><span class="badge-code"><?= h((string) $ens['matricule_ens']) ?></span></div>
  </div>
  <a href="<?= APP_URL ?>/secondaire/pages/enseignants/form.php?id=<?= (int) $ens['matricule_ens'] ?>" class="btn btn-primary btn-sm ms-auto">
    <i class="bi bi-pencil me-1"></i>Modifier
  </a>
</div>

<?= flash_html() ?>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="card mb-2">
      <div class="card-body">
        <div class="section-titre"><i class="bi bi-person me-1"></i>Identité</div>
        <div class="row g-2" style="font-size:.85rem">
          <div class="col-md-6"><span class="text-muted">Sexe :</span> <?= h($ens['sexe_ens'] === 'Feminin' ? 'Féminin' : 'Masculin') ?></div>
          <div class="col-md-6"><span class="text-muted">Date de naissance :</span> <?= h(date_fr($ens['date_naiss'])) ?></div>
          <div class="col-md-6"><span class="text-muted">Lieu de naissance :</span> <?= h($ens['lieu_naiss'] ?: '—') ?></div>
          <div class="col-md-6"><span class="text-muted">Situation :</span> <?= h($ens['situation_matrimoniale'] ?: '—') ?></div>
          <div class="col-md-6"><span class="text-muted">Téléphone :</span> <?= h($ens['tel_ens'] ?: '—') ?></div>
          <div class="col-md-6"><span class="text-muted">Email :</span> <?= h($ens['mail_ens'] ?: '—') ?></div>
        </div>
      </div>
    </div>
    <div class="card">
      <div class="card-body">
        <div class="section-titre"><i class="bi bi-briefcase me-1"></i>Poste</div>
        <div class="row g-2" style="font-size:.85rem">
          <div class="col-md-4"><span class="text-muted">Fonction :</span> <?= h($ens['id_fonction'] ?: '—') ?></div>
          <div class="col-md-4"><span class="text-muted">Grade :</span> <?= h($ens['id_grade'] ?: '—') ?></div>
          <div class="col-md-4"><span class="text-muted">Matière enseignée :</span> <?= h($ens['matiere_enseignee'] ?: '—') ?></div>
          <div class="col-md-6"><span class="text-muted">Diplôme :</span> <?= h($ens['diplome'] ?: '—') ?></div>
          <div class="col-md-6"><span class="text-muted">Spécialité :</span> <?= h($ens['specialite'] ?: '—') ?></div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card">
      <div class="card-body text-center">
        <div class="avatar mx-auto mb-2" style="width:64px;height:64px;font-size:1.4rem">
          <?= h(mb_strtoupper(mb_substr($ens['nom_ens'], 0, 1) . mb_substr($ens['prenom_ens'] ?? '', 0, 1))) ?>
        </div>
        <div class="fw-bold"><?= h(mb_strtoupper($ens['nom_ens'])) ?> <?= h($ens['prenom_ens'] ?? '') ?></div>
        <div class="text-muted" style="font-size:.78rem"><?= h($ens['id_fonction'] ?: '—') ?></div>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../../layout/footer.php'; ?>
