<?php
// pages/enseignants/mon_profil.php — Auto-édition de la fiche personnel par
// le titulaire lui-même (identité, contact, coordonnées bancaires) — PAS le
// volet carrière/administratif (fonction, grade, statut, date de
// recrutement, matricules officiels, arrondissement d'origine officiel),
// qui reste du ressort du Directeur (pages/enseignants/form.php). Ouvert à
// TOUT rôle connecté (demande explicite du 22/08/2026 — menu Ressources
// humaines > Mes informations pour DIRECTEUR/ENSEIGNANT/SECRETAIRE/
// COMPTABLE) : user.matricule_ens est NOT NULL, chaque compte a donc
// toujours une fiche liée, quel que soit son rôle.
// Remplace un ancien mon_profil.php copié tel quel d'un autre projet
// (colonnes region_origine/departement_origine/poste_anterieur/acte de
// recrutement... inexistantes dans ce schéma — jamais fonctionnel, jamais
// lié au menu).
header('Cache-Control: no-store, no-cache, must-revalidate');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_connexion();

$mat = matricule_ens_courant();
if (!$mat) { flash_set('erreur', 'Aucune fiche liée à votre compte. Contactez le Directeur.'); rediriger('dashboard.php'); }

$e = db_one("SELECT * FROM enseignant WHERE matricule_ens=?", [$mat]);
if (!$e) { flash_set('erreur', 'Fiche introuvable.'); rediriger('dashboard.php'); }

$v = fn(string $k) => $e[$k] ?? '';

// Champs éditables par le titulaire lui-même — identité + contact + banque
// uniquement (voir en-tête de fichier pour ce qui reste exclu).
$champs = ['nom_ens','prenom_ens','civilite_ens','sexe_ens','tel_ens','mail_ens','adresse_ens',
    'date_naiss_ens','lieu_ens','num_cni','tribu_ens','ethnie_ens','situation_ens',
    'nb_enfants','nb_pers_charge','mode_paiement','nom_banque','compte_bancaire'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('form') !== 'signature') {
    csrf_verifier();
    $d = $_POST;
    $vals = [];
    foreach ($champs as $f) {
        if (in_array($f, ['nb_enfants','nb_pers_charge'], true)) {
            $vals[$f] = max(0, (int) ($d[$f] ?? 0));
        } else {
            $vals[$f] = trim($d[$f] ?? '') ?: null;
        }
    }
    $set    = implode(', ', array_map(fn($f) => "$f=?", $champs));
    $params = array_merge(array_values($vals), [$mat]);
    db_exec("UPDATE enseignant SET $set WHERE matricule_ens=?", $params);
    flash_set('succes', 'Vos informations ont été mises à jour avec succès.');
    rediriger('pages/enseignants/mon_profil.php');
}

$titre_page = 'Mes informations';
require_once __DIR__ . '/../../layout/header.php';
?>
<style>
.btn-abz-primary{background:#1a3c6b;color:#fff;border:none;}
.btn-abz-primary:hover{background:#12305a;color:#fff;}
.section-title{background:#1a3c6b;color:#fff;padding:6px 14px;border-radius:5px;font-size:.82rem;font-weight:600;margin-bottom:10px;margin-top:16px;}
</style>

<div class="page-titre d-flex align-items-center justify-content-between flex-wrap gap-2">
  <h4><i class="bi bi-person-vcard me-2" style="color:#1a3c6b"></i>Mes informations</h4>
  <a href="<?= APP_URL ?>/profil.php" class="btn btn-sm btn-light"><i class="bi bi-arrow-left me-1"></i>Mon compte</a>
</div>

<?= flash_html() ?>

<div class="alert alert-info d-flex gap-2" style="font-size:.86rem">
  <i class="bi bi-info-circle mt-1"></i>
  <div>
    Identité, contact et coordonnées bancaires uniquement — matricule, fonction, grade et statut
    restent gérés par le Directeur (Ressources humaines).
  </div>
</div>

<form method="post" class="row g-3">
  <?= csrf_champ() ?>

  <!-- ── Identité ── -->
  <div class="col-12"><div class="section-title"><i class="bi bi-person-vcard me-1"></i>Identité</div></div>

  <div class="col-md-3">
    <label class="form-label">Matricule</label>
    <input type="text" value="<?= h($v('mat_ens') ?: (string) $mat) ?>" class="form-control form-control-sm" readonly>
  </div>
  <div class="col-md-2">
    <label class="form-label">Civilité</label>
    <select name="civilite_ens" class="form-select form-select-sm">
      <option value="">—</option>
      <?php foreach (['M.','Mme','Mlle'] as $cv): ?>
        <option <?= $v('civilite_ens')===$cv?'selected':'' ?>><?= $cv ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-3">
    <label class="form-label">Nom</label>
    <input type="text" name="nom_ens" value="<?= h($v('nom_ens')) ?>" class="form-control form-control-sm">
  </div>
  <div class="col-md-4">
    <label class="form-label">Prénom(s)</label>
    <input type="text" name="prenom_ens" value="<?= h($v('prenom_ens')) ?>" class="form-control form-control-sm">
  </div>
  <div class="col-md-2">
    <label class="form-label">Sexe</label>
    <select name="sexe_ens" class="form-select form-select-sm">
      <option value="">—</option>
      <option value="Masculin" <?= $v('sexe_ens')==='Masculin'?'selected':'' ?>>Masculin</option>
      <option value="Feminin" <?= $v('sexe_ens')==='Feminin'?'selected':'' ?>>Féminin</option>
    </select>
  </div>
  <div class="col-md-3">
    <label class="form-label">Date de naissance</label>
    <input type="date" name="date_naiss_ens" value="<?= h($v('date_naiss_ens')) ?>" class="form-control form-control-sm">
  </div>
  <div class="col-md-3">
    <label class="form-label">Lieu de naissance</label>
    <input type="text" name="lieu_ens" value="<?= h($v('lieu_ens')) ?>" class="form-control form-control-sm">
  </div>
  <div class="col-md-4">
    <label class="form-label">N° CNI</label>
    <input type="text" name="num_cni" value="<?= h($v('num_cni')) ?>" class="form-control form-control-sm">
  </div>
  <div class="col-md-3">
    <label class="form-label">Tribu</label>
    <input type="text" name="tribu_ens" value="<?= h($v('tribu_ens')) ?>" class="form-control form-control-sm">
  </div>
  <div class="col-md-3">
    <label class="form-label">Ethnie</label>
    <input type="text" name="ethnie_ens" value="<?= h($v('ethnie_ens')) ?>" class="form-control form-control-sm">
  </div>
  <div class="col-md-3">
    <label class="form-label">Situation matrimoniale</label>
    <select name="situation_ens" class="form-select form-select-sm">
      <option value="">—</option>
      <?php foreach (['Celibataire'=>'Célibataire','Marie'=>'Marié(e)','Divorce'=>'Divorcé(e)','Veuf'=>'Veuf/Veuve'] as $val=>$lab): ?>
        <option value="<?= $val ?>" <?= $v('situation_ens')===$val?'selected':'' ?>><?= $lab ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-3">
    <label class="form-label">Enfants à charge</label>
    <input type="number" min="0" name="nb_enfants" value="<?= h($v('nb_enfants') ?: '0') ?>" class="form-control form-control-sm">
  </div>
  <div class="col-md-3">
    <label class="form-label">Autres personnes à charge</label>
    <input type="number" min="0" name="nb_pers_charge" value="<?= h($v('nb_pers_charge') ?: '0') ?>" class="form-control form-control-sm">
  </div>

  <!-- ── Contact ── -->
  <div class="col-12"><div class="section-title"><i class="bi bi-telephone me-1"></i>Contact</div></div>
  <div class="col-md-3">
    <label class="form-label">Téléphone</label>
    <input type="tel" name="tel_ens" value="<?= h($v('tel_ens')) ?>" class="form-control form-control-sm">
  </div>
  <div class="col-md-4">
    <label class="form-label">Email</label>
    <input type="email" name="mail_ens" value="<?= h($v('mail_ens')) ?>" class="form-control form-control-sm">
  </div>
  <div class="col-md-5">
    <label class="form-label">Adresse</label>
    <input type="text" name="adresse_ens" value="<?= h($v('adresse_ens')) ?>" class="form-control form-control-sm">
  </div>

  <!-- ── Coordonnées bancaires ── -->
  <div class="col-12"><div class="section-title"><i class="bi bi-bank me-1"></i>Coordonnées bancaires (paie)</div></div>
  <div class="col-md-4">
    <label class="form-label">Mode de paiement</label>
    <select name="mode_paiement" class="form-select form-select-sm">
      <option value="">—</option>
      <?php foreach (['Especes'=>'Espèces','Virement'=>'Virement bancaire','MobileMoney'=>'Mobile Money'] as $val=>$lab): ?>
        <option value="<?= $val ?>" <?= $v('mode_paiement')===$val?'selected':'' ?>><?= $lab ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-4">
    <label class="form-label">Banque</label>
    <input type="text" name="nom_banque" value="<?= h($v('nom_banque')) ?>" class="form-control form-control-sm">
  </div>
  <div class="col-md-4">
    <label class="form-label">N° de compte</label>
    <input type="text" name="compte_bancaire" value="<?= h($v('compte_bancaire')) ?>" class="form-control form-control-sm">
  </div>

  <!-- ── Boutons ── -->
  <div class="col-12 mt-2 d-flex gap-2">
    <button type="submit" class="btn btn-abz-primary"><i class="bi bi-check-lg me-1"></i>Enregistrer</button>
  </div>
</form>

<?php require_once __DIR__ . '/../../layout/footer.php'; ?>
