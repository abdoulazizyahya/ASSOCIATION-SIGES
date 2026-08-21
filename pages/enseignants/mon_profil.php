<?php
/**
 * Auto-édition COMPLÈTE de la fiche enseignant par l'enseignant lui-même.
 * Réservé au rôle ENSEIGNANT — n'édite QUE sa propre fiche (matricule_ens_courant()).
 * Couvre l'intégralité des champs utilisés par les documents administratifs
 * (attestation de présence, certificats de prise/reprise de service) : identité,
 * contact, carrière professionnelle, acte de recrutement / affectation.
 * Le matricule (clé primaire) reste en lecture seule.
 */
header('Cache-Control: no-store, no-cache, must-revalidate');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_connexion();

$role = role_connecte();
if ($role !== 'ENSEIGNANT') { flash_set('erreur', 'Cette page est réservée aux enseignants.'); rediriger('dashboard.php'); }

$mat = matricule_ens_courant();
if (!$mat) { flash_set('erreur', 'Aucune fiche enseignant n\'est liée à votre compte. Contactez l\'administration.'); rediriger('dashboard.php'); }

$e = db_one("SELECT * FROM enseignant WHERE matricule_ens=?", [$mat]);
if (!$e) { flash_set('erreur', 'Fiche enseignant introuvable.'); rediriger('dashboard.php'); }

$v = fn(string $k) => $e[$k] ?? '';
$regions = db_all("SELECT id, nom FROM region ORDER BY nom");

// Tous les champs éditables par l'enseignant lui-même (identité + carrière + affectation).
// Seul le matricule (clé primaire) reste hors de cette liste : il ne peut pas être modifié.
$champs = ['nom_ens','prenom_ens','civilite_ens','sexe_ens','tel_ens','mail_ens',
    'id_grade','id_fonction',
    'date_naiss','lieu_naiss','region_origine','departement_origine','arrondissement_origine',
    'tribu','ethnie','situation_matrimoniale',
    'poste_anterieur','lieu_anterieur',
    'num_acte_recrutement','date_acte_recrutement','date_entree_fp',
    'type_affectation','num_note_affectation','date_note_affectation',
    'date_prise_service','qualite','diplome','specialite',
    'date_1ere_admin','date_1ere_etab','matiere_enseignee'];

$champs_date = ['date_naiss','date_acte_recrutement','date_entree_fp','date_note_affectation',
    'date_prise_service','date_1ere_admin','date_1ere_etab'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('form') === 'signature') {
    csrf_verifier();
    if (empty($_FILES['signature']['tmp_name']) || $_FILES['signature']['error'] !== UPLOAD_ERR_OK) {
        flash_set('erreur', 'Aucun fichier reçu.');
        rediriger('pages/enseignants/mon_profil.php');
    }
    $ext_ok = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
    $ext    = strtolower(pathinfo($_FILES['signature']['name'], PATHINFO_EXTENSION));
    $fi     = finfo_open(FILEINFO_MIME_TYPE);
    $mime   = finfo_file($fi, $_FILES['signature']['tmp_name']);
    finfo_close($fi);
    if (!isset($ext_ok[$ext]) || $ext_ok[$ext] !== $mime) {
        flash_set('erreur', 'Fichier invalide — JPG ou PNG uniquement.');
        rediriger('pages/enseignants/mon_profil.php');
    }
    // Fond blanc nettoyé/rendu transparent (PNG) : le texte du document
    // (bulletin, PV, fiche statistique de sa classe) reste visible même si
    // la signature est positionnée par-dessus.
    $fichier_sig = 'signature_pp_' . $mat . '.png';
    if (signature_traiter_transparence($_FILES['signature']['tmp_name'], __DIR__ . '/../../assets/uploads/' . $fichier_sig)) {
        db_exec("UPDATE enseignant SET signature=? WHERE matricule_ens=?", [$fichier_sig, $mat]);
        flash_set('succes', 'Signature mise à jour.');
    } else {
        flash_set('erreur', "Échec du traitement de l'image.");
    }
    rediriger('pages/enseignants/mon_profil.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $d = $_POST;
    if (($d['arrondissement_origine'] ?? '') === '__autre__') {
        $d['arrondissement_origine'] = trim($d['arrondissement_origine_autre'] ?? '');
    }
    $vals = [];
    foreach ($champs as $f) {
        $vals[$f] = trim($d[$f] ?? '') ?: null;
    }
    foreach ($champs_date as $df) {
        if (empty($vals[$df])) $vals[$df] = null;
    }

    $set = implode(', ', array_map(fn($f) => "$f=?", $champs));
    $params = array_merge(array_values($vals), [$mat]);
    db_exec("UPDATE enseignant SET $set WHERE matricule_ens=?", $params);
    flash_set('succes', 'Vos informations ont été mises à jour avec succès.');
    rediriger('pages/enseignants/mon_profil.php');
}

$manquants = identite_ens_manquants($e);

$titre_page = 'Mes informations';
require_once __DIR__ . '/../../layout/header.php';
?>
<style>
.btn-abz-primary{background:#1a3c6b;color:#fff;border:none;}
.btn-abz-primary:hover{background:#12305a;color:#fff;}
.btn-abz-outline{background:#fff;color:#1a3c6b;border:1.5px solid #1a3c6b;}
.btn-abz-outline:hover{background:#1a3c6b;color:#fff;}
.section-title{background:#1a3c6b;color:#fff;padding:6px 14px;border-radius:5px;font-size:.82rem;font-weight:600;margin-bottom:10px;margin-top:16px;}
</style>

<div class="page-titre d-flex align-items-center justify-content-between flex-wrap gap-2">
  <h4><i class="bi bi-person-vcard me-2" style="color:#1a3c6b"></i>Mes informations</h4>
  <a href="<?= APP_URL ?>/pages/demandes/index.php" class="btn btn-sm btn-abz-outline">
    <i class="bi bi-file-earmark-text me-1"></i>Mes demandes de documents
  </a>
</div>

<?= flash_html() ?>

<div class="alert alert-info d-flex gap-2" style="font-size:.86rem">
  <i class="bi bi-info-circle mt-1"></i>
  <div>
    Ces informations servent à générer vos documents officiels (attestation de présence,
    certificats de prise/reprise de service). Merci de toutes les renseigner avec exactitude —
    elles seront vérifiées par le Censeur puis le Proviseur lors du traitement de vos demandes.
    Seul le matricule ne peut pas être modifié.
  </div>
</div>

<?php if (!empty($manquants)): ?>
<div class="alert alert-warning" style="font-size:.86rem">
  <i class="bi bi-exclamation-triangle me-1"></i>
  Informations manquantes pour pouvoir soumettre une demande : <strong><?= h(implode(', ', $manquants)) ?></strong>.
</div>
<?php endif; ?>

<form method="post" class="row g-3">
  <?= csrf_champ() ?>

  <!-- ── Identité ── -->
  <div class="col-12"><div class="section-title"><i class="bi bi-person-vcard me-1"></i>Identité</div></div>

  <div class="col-md-3">
    <label class="form-label">Matricule</label>
    <input type="text" value="<?= h($mat) ?>" class="form-control form-control-sm" readonly>
  </div>
  <div class="col-md-1">
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
  <div class="col-md-3">
    <label class="form-label">Prénom(s)</label>
    <input type="text" name="prenom_ens" value="<?= h($v('prenom_ens')) ?>" class="form-control form-control-sm">
  </div>
  <div class="col-md-2">
    <label class="form-label">Sexe</label>
    <select name="sexe_ens" class="form-select form-select-sm">
      <option value="">—</option>
      <option value="M" <?= $v('sexe_ens')==='M'?'selected':'' ?>>Masculin</option>
      <option value="F" <?= $v('sexe_ens')==='F'?'selected':'' ?>>Féminin</option>
    </select>
  </div>
  <div class="col-md-3">
    <label class="form-label">Date de naissance</label>
    <input type="date" name="date_naiss" value="<?= h($v('date_naiss')) ?>" class="form-control form-control-sm">
  </div>
  <div class="col-md-3">
    <label class="form-label">Lieu de naissance</label>
    <input type="text" name="lieu_naiss" value="<?= h($v('lieu_naiss')) ?>" class="form-control form-control-sm">
  </div>
  <div class="col-md-3">
    <label class="form-label">Région d'origine</label>
    <select name="region_origine" id="selRegionOrigine" class="form-select form-select-sm">
      <option value="">— Choisir —</option>
      <?php foreach ($regions as $r): ?>
        <option value="<?= h($r['nom']) ?>" <?= $v('region_origine')===$r['nom']?'selected':'' ?>><?= h($r['nom']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-3">
    <label class="form-label">Département d'origine</label>
    <select name="departement_origine" id="selDeptOrigine" class="form-select form-select-sm">
      <option value="">— Choisir une région d'abord —</option>
    </select>
  </div>
  <div class="col-md-3">
    <label class="form-label">Arrondissement d'origine</label>
    <select name="arrondissement_origine" id="selArrondissement" class="form-select form-select-sm">
      <option value="">— Choisir un département d'abord —</option>
    </select>
    <input type="text" name="arrondissement_origine_autre" id="inpArrondissementAutre"
           class="form-control form-control-sm mt-1" style="display:none" placeholder="Préciser l'arrondissement">
  </div>
  <div class="col-md-3">
    <label class="form-label">Tribu</label>
    <input type="text" name="tribu" value="<?= h($v('tribu')) ?>" class="form-control form-control-sm">
  </div>
  <div class="col-md-3">
    <label class="form-label">Ethnie</label>
    <input type="text" name="ethnie" value="<?= h($v('ethnie')) ?>" class="form-control form-control-sm">
  </div>
  <div class="col-md-3">
    <label class="form-label">Situation matrimoniale</label>
    <select name="situation_matrimoniale" class="form-select form-select-sm">
      <option value="">—</option>
      <?php foreach (['Celibataire'=>'Célibataire','Marie'=>'Marié(e)','Divorce'=>'Divorcé(e)','Veuf'=>'Veuf/Veuve'] as $val=>$lab): ?>
        <option value="<?= $val ?>" <?= $v('situation_matrimoniale')===$val?'selected':'' ?>><?= $lab ?></option>
      <?php endforeach; ?>
    </select>
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

  <!-- ── Carrière ── -->
  <div class="col-12"><div class="section-title"><i class="bi bi-briefcase me-1"></i>Carrière professionnelle</div></div>
  <div class="col-md-4">
    <label class="form-label">Grade</label>
    <input type="text" name="id_grade" value="<?= h($v('id_grade')) ?>" class="form-control form-control-sm"
           placeholder="ex: PLEG, IPES…">
  </div>
  <div class="col-md-4">
    <label class="form-label">Fonction</label>
    <input type="text" name="id_fonction" value="<?= h($v('id_fonction')) ?>" class="form-control form-control-sm"
           placeholder="ex: Enseignant, PP…">
  </div>
  <div class="col-md-4">
    <label class="form-label">Qualité / En qualité de</label>
    <input type="text" name="qualite" value="<?= h($v('qualite')) ?>" class="form-control form-control-sm">
  </div>
  <div class="col-md-4">
    <label class="form-label">Diplôme</label>
    <input type="text" name="diplome" value="<?= h($v('diplome')) ?>" class="form-control form-control-sm">
  </div>
  <div class="col-md-4">
    <label class="form-label">Spécialité</label>
    <input type="text" name="specialite" value="<?= h($v('specialite')) ?>" class="form-control form-control-sm">
  </div>
  <div class="col-md-4">
    <label class="form-label">Matière effectivement enseignée</label>
    <input type="text" name="matiere_enseignee" value="<?= h($v('matiere_enseignee')) ?>" class="form-control form-control-sm">
  </div>
  <div class="col-md-3">
    <label class="form-label">Poste antérieur</label>
    <input type="text" name="poste_anterieur" value="<?= h($v('poste_anterieur')) ?>" class="form-control form-control-sm">
  </div>
  <div class="col-md-3">
    <label class="form-label">Lieu poste antérieur</label>
    <input type="text" name="lieu_anterieur" value="<?= h($v('lieu_anterieur')) ?>" class="form-control form-control-sm">
  </div>
  <div class="col-md-3">
    <label class="form-label">Date d'entrée dans la FP</label>
    <input type="date" name="date_entree_fp" value="<?= h($v('date_entree_fp')) ?>" class="form-control form-control-sm">
  </div>
  <div class="col-md-3">
    <label class="form-label">1ère prise de service (Admin)</label>
    <input type="date" name="date_1ere_admin" value="<?= h($v('date_1ere_admin')) ?>" class="form-control form-control-sm">
  </div>
  <div class="col-md-3">
    <label class="form-label">1ère prise de service (Établissement)</label>
    <input type="date" name="date_1ere_etab" value="<?= h($v('date_1ere_etab')) ?>" class="form-control form-control-sm">
  </div>
  <div class="col-md-3">
    <label class="form-label">Date de prise de service actuelle</label>
    <input type="date" name="date_prise_service" value="<?= h($v('date_prise_service')) ?>" class="form-control form-control-sm">
  </div>

  <!-- ── Acte de recrutement ── -->
  <div class="col-12"><div class="section-title"><i class="bi bi-file-text me-1"></i>Acte de recrutement / Affectation</div></div>
  <div class="col-md-4">
    <label class="form-label">N° Acte de recrutement</label>
    <input type="text" name="num_acte_recrutement" value="<?= h($v('num_acte_recrutement')) ?>" class="form-control form-control-sm">
  </div>
  <div class="col-md-3">
    <label class="form-label">Date acte de recrutement</label>
    <input type="date" name="date_acte_recrutement" value="<?= h($v('date_acte_recrutement')) ?>" class="form-control form-control-sm">
  </div>
  <div class="col-md-5"></div>
  <div class="col-md-3">
    <label class="form-label">Type d'affectation</label>
    <select name="type_affectation" class="form-select form-select-sm">
      <option value="">—</option>
      <?php foreach (['Arrete'=>'Arrêté','Note de service'=>'Note de service','Decision'=>'Décision'] as $val=>$lab): ?>
        <option value="<?= $val ?>" <?= $v('type_affectation')===$val?'selected':'' ?>><?= $lab ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-4">
    <label class="form-label">N° Note / Arrêté d'affectation</label>
    <input type="text" name="num_note_affectation" value="<?= h($v('num_note_affectation')) ?>" class="form-control form-control-sm">
  </div>
  <div class="col-md-3">
    <label class="form-label">Date de la note / arrêté</label>
    <input type="date" name="date_note_affectation" value="<?= h($v('date_note_affectation')) ?>" class="form-control form-control-sm">
  </div>

  <!-- ── Boutons ── -->
  <div class="col-12 mt-2 d-flex gap-2">
    <button type="submit" class="btn btn-abz-primary"><i class="bi bi-check-lg me-1"></i>Enregistrer</button>
  </div>
</form>

<!-- ── Signature numérique ── -->
<div class="section-title"><i class="bi bi-vector-pen me-1"></i>Signature numérique</div>
<div class="alert alert-info" style="font-size:.85rem">
  <i class="bi bi-info-circle me-1"></i>Utilisée si vous êtes Professeur Principal d'une classe — jamais appliquée
  automatiquement, chaque impression propose une case à cocher. Le fond est nettoyé (rendu transparent) automatiquement.
</div>
<?php $sig_pp = signature_chemin_enseignant($mat); ?>
<?php if ($sig_pp): ?>
  <div class="mb-2">
    <img src="<?= APP_URL ?>/assets/uploads/<?= h(basename($sig_pp)) ?>"
         style="height:56px;border-radius:6px;border:1px solid #e5e7eb;background:repeating-conic-gradient(#e5e7eb 0% 25%, #fff 0% 50%) 50% / 12px 12px">
  </div>
<?php else: ?>
  <div class="text-muted mb-2" style="font-size:.8rem">Aucune signature configurée.</div>
<?php endif; ?>
<form method="post" enctype="multipart/form-data" class="d-flex gap-2 align-items-end flex-wrap">
  <?= csrf_champ() ?>
  <input type="hidden" name="form" value="signature">
  <div>
    <input type="file" name="signature" class="form-control form-control-sm" accept="image/jpeg,image/png" required>
  </div>
  <button class="btn btn-sm btn-abz-primary"><i class="bi bi-upload me-1"></i>Enregistrer</button>
</form>

<script>
(function() {
  var selRegion = document.getElementById('selRegionOrigine');
  var selDept   = document.getElementById('selDeptOrigine');
  var selArr    = document.getElementById('selArrondissement');
  var inpAutre  = document.getElementById('inpArrondissementAutre');
  var valeurDeptActuelle = <?= json_encode($v('departement_origine')) ?>;
  var valeurArrActuelle  = <?= json_encode($v('arrondissement_origine')) ?>;

  function chargerDepartements(nomRegion, preselection) {
    if (!nomRegion) {
      selDept.innerHTML = '<option value="">— Choisir une région d\'abord —</option>';
      chargerArrondissements('', null);
      return;
    }
    selDept.innerHTML = '<option value="">Chargement…</option>';
    fetch('<?= APP_URL ?>/ajax/departements_par_region.php?nom_region=' + encodeURIComponent(nomRegion))
      .then(function(r) { return r.json(); })
      .then(function(deps) {
        var html = '<option value="">— Choisir —</option>';
        deps.forEach(function(d) {
          var sel = (preselection && d.nom === preselection) ? ' selected' : '';
          html += '<option value="' + d.nom + '"' + sel + '>' + d.nom + '</option>';
        });
        selDept.innerHTML = html;
        if (preselection) chargerArrondissements(preselection, valeurArrActuelle);
      })
      .catch(function() {
        selDept.innerHTML = '<option value="">Erreur de chargement</option>';
      });
  }

  function chargerArrondissements(nomDept, preselection) {
    if (!nomDept) {
      selArr.innerHTML = '<option value="">— Choisir un département d\'abord —</option>';
      inpAutre.style.display = 'none';
      return;
    }
    selArr.innerHTML = '<option value="">Chargement…</option>';
    fetch('<?= APP_URL ?>/ajax/arrondissements_par_departement.php?departement=' + encodeURIComponent(nomDept))
      .then(function(r) { return r.json(); })
      .then(function(arrs) {
        var html = '<option value="">— Choisir —</option>';
        var trouve = false;
        arrs.forEach(function(a) {
          var sel = (preselection && a === preselection) ? ' selected' : '';
          if (sel) trouve = true;
          html += '<option value="' + a + '"' + sel + '>' + a + '</option>';
        });
        html += '<option value="__autre__">Autre (non listé, préciser)…</option>';
        selArr.innerHTML = html;
        if (preselection && !trouve) {
          selArr.value = '__autre__';
          inpAutre.style.display = 'block';
          inpAutre.value = preselection;
        } else {
          inpAutre.style.display = 'none';
        }
      })
      .catch(function() {
        selArr.innerHTML = '<option value="">Erreur de chargement</option>';
      });
  }

  selArr.addEventListener('change', function() {
    inpAutre.style.display = (this.value === '__autre__') ? 'block' : 'none';
    if (this.value === '__autre__') inpAutre.focus();
  });

  selRegion.addEventListener('change', function() {
    chargerDepartements(this.value, null);
  });
  selDept.addEventListener('change', function() {
    chargerArrondissements(this.value, null);
  });

  // Pré-chargement en mode édition
  if (selRegion.value) {
    chargerDepartements(selRegion.value, valeurDeptActuelle);
  }
})();
</script>

<?php require_once __DIR__ . '/../../layout/footer.php'; ?>
