<?php
header('Cache-Control: no-store, no-cache, must-revalidate');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_connexion();

$role = role_connecte();
if (!in_array($role, ['ADMIN','PROVISEUR','CENSEUR'])) { flash_set('erreur','Accès refusé.'); rediriger('dashboard.php'); }

$mat = $_GET['id'] ?? '';
$e   = db_one("SELECT * FROM enseignant WHERE matricule_ens=?", [$mat]);
if (!$e) { flash_set('erreur','Enseignant introuvable.'); rediriger('pages/enseignants/index.php'); }

$annee_act = get_annee_active();
$val_annee = $annee_act['libelle'] ?? '';

// Classes et matières assignées
$affectations = db_all(
    "SELECT DISTINCT c.designation AS classe, m.libelle AS matiere
     FROM dispenser d
     JOIN classe c ON c.id=d.IDClasses
     JOIN matiere m ON m.id=d.id_mat
     WHERE d.matricule_ens=? AND d.val_annee=?
     ORDER BY c.designation, m.libelle",
    [$mat, $val_annee]
);
$classes_pp = db_all(
    "SELECT c.designation FROM enseignat_principal ep JOIN classe c ON c.id=ep.IDClasses WHERE ep.matricule_ens=? AND ep.val_annee=?",
    [$mat, $val_annee]
);

$titre_page = 'Fiche enseignant';
require_once __DIR__ . '/../../layout/header.php';
?>
<style>
.btn-abz-primary{background:#1a3c6b;color:#fff;border:none;}
.btn-abz-primary:hover{background:#12305a;color:#fff;}
.btn-abz-outline{background:#fff;color:#1a3c6b;border:1.5px solid #1a3c6b;}
.btn-abz-outline:hover{background:#1a3c6b;color:#fff;}
.fiche-section{border-left:4px solid #1a3c6b;padding:6px 12px;margin-bottom:12px;background:#f8faff;}
.fiche-label{font-size:.74rem;color:#666;text-transform:uppercase;font-weight:600;}
.fiche-val{font-size:.92rem;color:#1a1a2e;font-weight:500;}
</style>

<div class="page-titre d-flex align-items-center justify-content-between flex-wrap gap-2">
  <h4><i class="bi bi-person-badge me-2" style="color:#1a3c6b"></i>
    <?= h(($e['civilite_ens']??'').' '.strtoupper($e['nom_ens']).' '.($e['prenom_ens']??'')) ?>
  </h4>
  <div class="d-flex gap-2 flex-wrap">
    <a href="form.php?id=<?= urlencode($mat) ?>" class="btn btn-sm btn-abz-outline"><i class="bi bi-pencil me-1"></i>Modifier</a>
    <a href="apercu.php?id=<?= urlencode($mat) ?>&type=attestation" class="btn btn-sm btn-abz-primary">
      <i class="bi bi-file-earmark-text me-1"></i>Attestation présence
    </a>
    <a href="apercu.php?id=<?= urlencode($mat) ?>&type=prise_service" class="btn btn-sm btn-abz-primary">
      <i class="bi bi-file-earmark-check me-1"></i>Certificat service
    </a>
    <a href="apercu.php?id=<?= urlencode($mat) ?>&type=dossier" class="btn btn-sm btn-abz-primary">
      <i class="bi bi-folder2-open me-1"></i>Dossier complet
    </a>
    <a href="index.php" class="btn btn-sm btn-abz-outline"><i class="bi bi-arrow-left me-1"></i>Retour</a>
  </div>
</div>

<?= flash_html() ?>

<div class="row g-3">
  <!-- Identité -->
  <div class="col-md-6">
    <div class="card h-100" style="border-color:#c7d8f0">
      <div class="card-header py-2" style="background:#1a3c6b;color:#fff;font-size:.83rem;font-weight:600">
        <i class="bi bi-person-vcard me-1"></i>Identité
      </div>
      <div class="card-body">
        <?php $info = [
          ['Matricule', $e['matricule_ens']],
          ['Nom et Prénom', ($e['civilite_ens']??'').' '.strtoupper($e['nom_ens']).' '.($e['prenom_ens']??'')],
          ['Sexe', $e['sexe_ens']??'—'],
          ['Date de naissance', $e['date_naiss']?date('d/m/Y',strtotime($e['date_naiss'])):'—'],
          ['Lieu de naissance', $e['lieu_naiss']??'—'],
          ['Région d\'origine', $e['region_origine']??'—'],
          ['Département', $e['departement_origine']??'—'],
          ['Arrondissement', $e['arrondissement_origine']??'—'],
          ['Tribu / Ethnie', trim(($e['tribu']??'').' / '.($e['ethnie']??''), ' /')],
          ['Situation matrimoniale', $e['situation_matrimoniale']??'—'],
        ]; foreach ($info as [$label,$val]): ?>
        <div class="mb-2">
          <div class="fiche-label"><?= h($label) ?></div>
          <div class="fiche-val"><?= h($val ?: '—') ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Carrière -->
  <div class="col-md-6">
    <div class="card h-100" style="border-color:#c7d8f0">
      <div class="card-header py-2" style="background:#1a3c6b;color:#fff;font-size:.83rem;font-weight:600">
        <i class="bi bi-briefcase me-1"></i>Carrière
      </div>
      <div class="card-body">
        <?php $info2 = [
          ['Grade', $e['id_grade']??'—'],
          ['Fonction / Qualité', ($e['id_fonction']??'').($e['qualite']?' — '.$e['qualite']:'')],
          ['Diplôme', $e['diplome']??'—'],
          ['Spécialité', $e['specialite']??'—'],
          ['Matière enseignée', $e['matiere_enseignee']??'—'],
          ['Poste antérieur', ($e['poste_anterieur']??'—').($e['lieu_anterieur']?' ('.$e['lieu_anterieur'].')':'')],
          ['Date entrée FP', $e['date_entree_fp']?date('d/m/Y',strtotime($e['date_entree_fp'])):'—'],
          ['1ère prise de service (Admin)', $e['date_1ere_admin']?date('d/m/Y',strtotime($e['date_1ere_admin'])):'—'],
          ['1ère prise de service (Étab.)', $e['date_1ere_etab']?date('d/m/Y',strtotime($e['date_1ere_etab'])):'—'],
          ['Prise de service actuelle', $e['date_prise_service']?date('d/m/Y',strtotime($e['date_prise_service'])):'—'],
          ['Téléphone', $e['tel_ens']??'—'],
          ['Email', $e['mail_ens']??'—'],
        ]; foreach ($info2 as [$label,$val]): ?>
        <div class="mb-2">
          <div class="fiche-label"><?= h($label) ?></div>
          <div class="fiche-val"><?= h($val ?: '—') ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Affectations -->
  <div class="col-12">
    <div class="card" style="border-color:#c7d8f0">
      <div class="card-header py-2" style="background:#1a3c6b;color:#fff;font-size:.83rem;font-weight:600">
        <i class="bi bi-journal-bookmark me-1"></i>Affectations – Année <?= h($val_annee) ?>
        <?php if (!empty($classes_pp)): ?>
          <span class="badge ms-2" style="background:#f59e0b;color:#1a1a2e">
            Prof. Principal : <?= h(implode(', ', array_column($classes_pp,'designation'))) ?>
          </span>
        <?php endif; ?>
      </div>
      <div class="card-body p-0">
        <?php if (empty($affectations)): ?>
          <p class="text-muted p-3 mb-0">Aucune affectation enregistrée pour cette année.</p>
        <?php else: ?>
          <table class="table table-sm mb-0" style="font-size:.82rem">
            <thead style="background:#f0f4ff">
              <tr><th>Classe</th><th>Matière dispensée</th></tr>
            </thead>
            <tbody>
              <?php foreach ($affectations as $a): ?>
              <tr>
                <td class="fw-semibold"><?= h($a['classe']) ?></td>
                <td><?= h($a['matiere']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../layout/footer.php'; ?>
