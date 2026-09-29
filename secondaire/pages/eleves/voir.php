<?php
// secondaire/pages/eleves/voir.php — fiche d'un élève (école secondaire).
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_connexion();

$id    = (int) ($_GET['id'] ?? 0);
exiger_acces_eleve_secondaire($id);   // enseignant / SG : seulement leurs classes
$eleve = $id ? db_one("SELECT * FROM eleve WHERE id=?", [$id]) : null;
if (!$eleve) { flash_set('erreur', 'Élève introuvable.'); rediriger('secondaire/pages/eleves/liste.php'); }

$annee    = get_annee_active();
$id_annee = (int) ($annee['id'] ?? 0);
$inscription = $id_annee ? db_one(
    "SELECT i.*, c.designation AS classe FROM inscription i JOIN classe c ON c.id=i.id_classe
     WHERE i.id_eleve=? AND i.id_annee=?", [$id, $id_annee]
) : null;

$titre_page = 'Fiche élève';
require_once __DIR__ . '/../../../layout/header.php';
?>

<div class="d-flex align-items-center gap-2 mb-3">
  <a href="<?= APP_URL ?>/secondaire/pages/eleves/liste.php" class="btn btn-sm btn-light"><i class="bi bi-arrow-left"></i></a>
  <div>
    <h4 class="mb-0" style="font-size:1.05rem;font-weight:700"><?= h(mb_strtoupper($eleve['nom']) . ' ' . ($eleve['prenom'] ?? '')) ?></h4>
    <div class="sub"><span class="badge-code"><?= h($eleve['matricule']) ?></span></div>
  </div>
  <a href="<?= APP_URL ?>/secondaire/pages/eleves/form.php?id=<?= (int) $eleve['id'] ?>" class="btn btn-primary btn-sm ms-auto">
    <i class="bi bi-pencil me-1"></i>Modifier
  </a>
</div>

<?= flash_html() ?>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="card mb-2">
      <div class="card-body">
        <div class="section-titre"><i class="bi bi-person me-1"></i>Informations personnelles</div>
        <div class="row g-2" style="font-size:.85rem">
          <div class="col-md-6"><span class="text-muted">Sexe :</span> <?= $eleve['sexe'] === 'F' ? '<span class="badge-f">F</span>' : '<span class="badge-m">M</span>' ?></div>
          <div class="col-md-6"><span class="text-muted">Date de naissance :</span> <?= h(date_fr($eleve['date_naiss'])) ?></div>
          <div class="col-md-6"><span class="text-muted">Lieu de naissance :</span> <?= h($eleve['lieu_naiss'] ?: '—') ?></div>
          <div class="col-md-6"><span class="text-muted">NIU :</span> <?= h($eleve['niu'] ?: '—') ?></div>
          <div class="col-md-6"><span class="text-muted">Téléphone :</span> <?= h($eleve['telephone'] ?: '—') ?></div>
          <div class="col-md-6"><span class="text-muted">Adresse :</span> <?= h($eleve['adresse'] ?: '—') ?></div>
          <div class="col-md-6"><span class="text-muted">Région/dépt/arrondt de naissance :</span>
            <?= h(implode(' / ', array_filter([$eleve['region_naiss'] ?? null, $eleve['departement_naiss'] ?? null, $eleve['arrondissement_naiss'] ?? null]))) ?: '—' ?>
          </div>
        </div>
      </div>
    </div>
    <div class="card">
      <div class="card-body">
        <div class="section-titre"><i class="bi bi-journal-check me-1"></i>Inscription — <?= h($annee['val_annee'] ?? '—') ?></div>
        <?php if ($inscription): ?>
          <div style="font-size:.85rem">
            <span class="text-muted">Classe :</span> <strong><?= h($inscription['classe']) ?></strong>
            &nbsp;·&nbsp; <span class="text-muted">Statut :</span> <?= h($inscription['statut']) ?>
            &nbsp;·&nbsp; <span class="text-muted">Inscrit le :</span> <?= h(date_fr($inscription['date_inscription'])) ?>
          </div>
        <?php else: ?>
          <p class="text-muted mb-0" style="font-size:.82rem">Non inscrit pour cette année.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card">
      <div class="card-body text-center">
        <div class="avatar mx-auto mb-2" style="width:64px;height:64px;font-size:1.4rem">
          <?= h(mb_strtoupper(mb_substr($eleve['nom'], 0, 1) . mb_substr($eleve['prenom'] ?? '', 0, 1))) ?>
        </div>
        <div class="fw-bold"><?= h(mb_strtoupper($eleve['nom'])) ?> <?= h($eleve['prenom'] ?? '') ?></div>
        <span class="badge <?= $eleve['statut'] === 'actif' ? 'bg-success' : 'bg-secondary' ?>" style="font-size:.7rem">
          <?= $eleve['statut'] === 'actif' ? 'Actif' : 'Désactivé' ?>
        </span>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../../layout/footer.php'; ?>
