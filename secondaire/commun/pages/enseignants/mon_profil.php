<?php
/**
 * Auto-édition COMPLÈTE de la fiche enseignant par l'enseignant lui-même.
 * Réservé au rôle ENSEIGNANT — n'édite QUE sa propre fiche (matricule_ens_courant()).
 * Couvre l'intégralité des champs utilisés par les documents administratifs
 * (attestation de présence, certificats de prise/reprise de service) : identité,
 * contact, carrière professionnelle, acte de recrutement / affectation.
 * Le matricule (clé primaire) reste en lecture seule.
 *
 * Porté depuis LAM_ABZ/pages/enseignants/mon_profil.php le 17/09/2026 (audit
 * de la copie en masse) — jusqu'ici manquant, alors que secondaire/commun/pages/
 * demandes/nouvelle.php y redirige un enseignant dont les informations sont
 * incomplètes (page 404 sans ce fichier). Le fetch() vers l'arrondissement
 * a été adapté : l'URL/le format de réponse ({id,nom} et pas des chaînes
 * brutes) visés par le fichier LAM_ABZ d'origine ne correspondent à AUCUN
 * fichier existant côté LAM_ABZ (noyau/ajax/arrondissements_par_departement.php
 * n'y existe pas) — utilise ici noyau/ajax/arrondissements_par_departement.php
 * (déjà présent côté SIGES, désormais type-aware secondaire).
 */
header('Cache-Control: no-store, no-cache, must-revalidate');
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
require_once __DIR__ . '/_listes_carriere.php';
// Ouvert à TOUT le personnel (03/10/2026) : chacun voit et complète SES
// propres informations, même s'il n'enseigne pas (Proviseur/Principal,
// Administrateur, Censeur, Surveillant général, Intendant, Secrétaire).
exiger_role(['ADMIN', 'PROVISEUR', 'FONDATEUR', 'CENSEUR', 'SG', 'INTENDANT', 'SECRETAIRE', 'ENSEIGNANT']);

// Gestion par l'administration (07/11/2026) : le Proviseur/Principal, l'Admin,
// le Fondateur et le compte propriétaire ouvrent la fiche COMPLÈTE de n'importe
// quel membre du personnel (?id=<matricule_ens>) pour la créer / la modifier —
// jusqu'ici chacun ne pouvait renseigner que la sienne.
$peut_gerer_fiches = (function_exists('est_proprietaire_ecole') && est_proprietaire_ecole())
    || in_array(role_connecte(), ['ADMIN', 'PROVISEUR', 'FONDATEUR'], true);
$id_gere = (int) ($_GET['id'] ?? 0);
$mode_gestion = false;
if ($id_gere > 0 && $id_gere !== (int) matricule_ens_courant()) {
    if (!$peut_gerer_fiches) { flash_set('erreur', 'Accès refusé : vous ne pouvez modifier que votre propre fiche.'); rediriger('secondaire/commun/pages/enseignants/mon_profil.php'); }
    $mat = $id_gere;
    $mode_gestion = true;
} else {
    $mat = matricule_ens_courant();
    // Fondateur sans fiche du personnel : il gère celles des autres, il n'en crée pas pour lui.
    if (!$mat && role_connecte() === 'FONDATEUR') rediriger('secondaire/commun/pages/enseignants/liste.php');
}
$url_ici = 'secondaire/commun/pages/enseignants/mon_profil.php' . ($mode_gestion ? '?id=' . (int) $mat : '');

// Compte sans fiche du personnel (fréquent au secondaire : utilisateur.
// matricule_ens est facultatif) : la personne crée elle-même sa fiche,
// pré-remplie depuis son compte, puis la complète ci-dessous.
if (!$mat) {
    $compte = db_one("SELECT id, nom, prenom, email, role FROM utilisateur WHERE id=?", [(int) ($_SESSION['user_id'] ?? 0)]);
    if (!$compte) { flash_set('erreur', 'Compte introuvable.'); rediriger('noyau/dashboard.php'); }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('form') === 'creer_fiche') {
        csrf_verifier();
        db_exec("INSERT INTO enseignant (nom_ens, prenom_ens, mail_ens, id_fonction) VALUES (?, ?, ?, ?)",
                [mb_strtoupper(trim((string) $compte['nom']) ?: 'À COMPLÉTER'), $compte['prenom'] ?: null, $compte['email'] ?: null,
                 str_replace('(e)', '', libelle_role((string) $compte['role']))]);
        $mat = (int) db_last_id();
        db_exec("UPDATE utilisateur SET matricule_ens=? WHERE id=?", [$mat, (int) $compte['id']]);
        $_SESSION['user']['matricule_ens'] = $mat;
        journaliser_action('fiche_personnel_creee', null, 'compte #' . $compte['id'] . ' → fiche ' . $mat);
        flash_set('succes', 'Votre fiche du personnel a été créée. Complétez maintenant vos informations.');
        rediriger('secondaire/commun/pages/enseignants/mon_profil.php');
    }
    $titre_page = 'Mes informations';
    require_once __DIR__ . '/../../../../noyau/layout/header.php';
    ?>
    <div class="page-titre"><h4><i class="bi bi-person-vcard me-1 text-primary"></i>Mes informations</h4></div>
    <div class="card" style="max-width:620px">
      <div class="card-body">
        <p class="mb-2">Votre compte n'est pas encore relié à une <strong>fiche du personnel</strong>.
           Créez-la pour pouvoir consulter et compléter vos informations (identité, contact, carrière),
           utilisées par les documents administratifs et la paie.</p>
        <table class="table table-sm mb-3" style="font-size:.85rem">
          <tr><th style="width:35%">Nom</th><td><?= h(mb_strtoupper((string) $compte['nom'])) ?> <?= h($compte['prenom'] ?? '') ?></td></tr>
          <tr><th>Rôle</th><td><?= h(libelle_role((string) $compte['role'])) ?></td></tr>
          <tr><th>E-mail</th><td><?= h($compte['email'] ?: '—') ?></td></tr>
        </table>
        <form method="post">
          <?= csrf_champ() ?>
          <input type="hidden" name="form" value="creer_fiche">
          <button class="btn btn-primary btn-sm"><i class="bi bi-person-plus me-1"></i>Créer ma fiche du personnel</button>
        </form>
      </div>
    </div>
    <?php
    require_once __DIR__ . '/../../../../noyau/layout/footer.php';
    exit;
}

$e = db_one("SELECT * FROM enseignant WHERE matricule_ens=?", [$mat]);
if (!$e) { flash_set('erreur', 'Fiche enseignant introuvable.'); rediriger('noyau/dashboard.php'); }

$v = fn(string $k) => $e[$k] ?? '';
$regions = db_all("SELECT id, nom FROM region ORDER BY nom");
try { $grades = db_all("SELECT code_grade, libelle_grade FROM grade_enseignant ORDER BY ordre_affichage, libelle_grade"); } catch (\Throwable $ex) { $grades = []; }

// Tous les champs éditables par l'enseignant lui-même (identité + carrière + affectation).
// Seul le matricule (clé primaire) reste hors de cette liste : il ne peut pas être modifié.
// Grade et fonction : JAMAIS modifiables par la personne elle-même (03/10/2026 —
// un enseignant pouvait se déclarer « Principal ») ; affichés en lecture seule,
// gérés par l'administration (Ressources humaines > Enseignants).
$champs = ['nom_ens','prenom_ens','civilite_ens','sexe_ens','tel_ens','mail_ens',
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
        rediriger($url_ici);
    }
    $ext_ok = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
    $ext    = strtolower(pathinfo($_FILES['signature']['name'], PATHINFO_EXTENSION));
    $fi     = finfo_open(FILEINFO_MIME_TYPE);
    $mime   = finfo_file($fi, $_FILES['signature']['tmp_name']);
    finfo_close($fi);
    if (!isset($ext_ok[$ext]) || $ext_ok[$ext] !== $mime) {
        flash_set('erreur', 'Fichier invalide — JPG ou PNG uniquement.');
        rediriger($url_ici);
    }
    // Fond blanc nettoyé/rendu transparent (PNG) : le texte du document
    // (bulletin, PV, fiche statistique de sa classe) reste visible même si
    // la signature est positionnée par-dessus.
    // Préfixe par école (assets/uploads/etab/<code>/) : deux écoles
    // secondaires peuvent avoir le même matricule enseignant.
    $fichier_sig = upload_prefixe_etab() . 'signature_pp_' . $mat . '.png';
    if (signature_traiter_transparence($_FILES['signature']['tmp_name'],
            upload_dir_etab(__DIR__ . '/../../../../assets/uploads') . 'signature_pp_' . $mat . '.png')) {
        db_exec("UPDATE enseignant SET signature=? WHERE matricule_ens=?", [$fichier_sig, $mat]);
        flash_set('succes', 'Signature mise à jour.');
    } else {
        flash_set('erreur', "Échec du traitement de l'image.");
    }
    rediriger($url_ici);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $d = $_POST;
    if (($d['arrondissement_origine'] ?? '') === '__autre__') {
        $d['arrondissement_origine'] = trim($d['arrondissement_origine_autre'] ?? '');
    }
    $vals = [];
    foreach ($champs as $f) {
        $vals[$f] = is_array($d[$f] ?? null) ? null : (trim((string) ($d[$f] ?? '')) ?: null);
    }
    foreach ($champs_date as $df) {
        if (empty($vals[$df])) $vals[$df] = null;
    }
    // Champs à liste de choix (+ « Autre… ») et matières multiples (07/11/2026).
    foreach (['qualite', 'diplome', 'specialite', 'poste_anterieur'] as $f) $vals[$f] = carriere_post($f);
    $vals['matiere_enseignee'] = carriere_post_matieres('matiere_enseignee');

    $set = implode(', ', array_map(fn($f) => "$f=?", $champs));
    $params = array_merge(array_values($vals), [$mat]);
    db_exec("UPDATE enseignant SET $set WHERE matricule_ens=?", $params);

    // Grade : modifiable seulement par l'administration (fiche gérée), jamais
    // par la personne elle-même. « Autre… » ajoute le grade à la grille
    // (salaire 0, à compléter dans Ressources humaines > Grille salariale).
    if ($mode_gestion && array_key_exists('grade', $_POST)) {
        $g = (string) $_POST['grade'];
        if ($g === '__autre__') {
            $lib = carriere_post('grade');
            $g = '';
            if ($lib !== null) {
                $exist = db_one("SELECT code_grade FROM grade_enseignant WHERE LOWER(code_grade)=LOWER(?) OR LOWER(libelle_grade)=LOWER(?)", [$lib, $lib]);
                if ($exist) {
                    $g = $exist['code_grade'];
                } else {
                    $g = carriere_code_grade($lib);
                    for ($i = 2, $base = mb_substr($g, 0, 17); db_one("SELECT 1 FROM grade_enseignant WHERE code_grade=?", [$g]); $i++) $g = $base . $i;
                    db_exec("INSERT INTO grade_enseignant (code_grade, libelle_grade, salaire_base, ordre_affichage) VALUES (?, ?, 0, 99)", [$g, $lib]);
                    journaliser_action('grade_ajoute', null, $g . ' — ' . $lib);
                }
            }
        } elseif ($g !== '' && !db_one("SELECT 1 FROM grade_enseignant WHERE code_grade=?", [$g])) {
            $g = '';
        }
        db_exec("UPDATE enseignant SET id_grade=? WHERE matricule_ens=?", [$g !== '' ? $g : null, $mat]);
    }
    if ($mode_gestion) {
        journaliser_action('fiche_personnel_modifiee', null, 'fiche ' . $mat . ' (' . trim(($vals['nom_ens'] ?? '') . ' ' . ($vals['prenom_ens'] ?? '')) . ')');
        flash_set('succes', 'La fiche a été mise à jour avec succès.');
    } else {
        flash_set('succes', 'Vos informations ont été mises à jour avec succès.');
    }
    rediriger($url_ici);
}

$manquants = identite_ens_manquants($e);

$nom_complet = trim(mb_strtoupper((string) ($e['nom_ens'] ?? '')) . ' ' . ($e['prenom_ens'] ?? ''));
$titre_page = $mode_gestion ? 'Fiche du personnel — ' . $nom_complet : 'Mes informations';
require_once __DIR__ . '/../../../../noyau/layout/header.php';
?>
<style>
.btn-abz-primary{background:#1a3c6b;color:#fff;border:none;}
.btn-abz-primary:hover{background:#12305a;color:#fff;}
.btn-abz-outline{background:#fff;color:#1a3c6b;border:1.5px solid #1a3c6b;}
.btn-abz-outline:hover{background:#1a3c6b;color:#fff;}
.section-title{background:#1a3c6b;color:#fff;padding:6px 14px;border-radius:5px;font-size:.82rem;font-weight:600;margin-bottom:10px;margin-top:16px;}
</style>

<div class="page-titre d-flex align-items-center justify-content-between flex-wrap gap-2">
<?php if ($mode_gestion): ?>
  <h4><i class="bi bi-person-vcard me-2" style="color:#1a3c6b"></i>Fiche complète — <?= h($nom_complet) ?></h4>
  <div class="d-flex gap-2 flex-wrap">
    <a href="<?= APP_URL ?>/secondaire/commun/pages/enseignants/form.php?id=<?= (int) $mat ?>" class="btn btn-sm btn-abz-outline">
      <i class="bi bi-pencil me-1"></i>Grade, fonction, paie
    </a>
    <a href="<?= APP_URL ?>/secondaire/commun/pages/enseignants/voir.php?id=<?= (int) $mat ?>" class="btn btn-sm btn-abz-outline">
      <i class="bi bi-arrow-left me-1"></i>Retour à la fiche
    </a>
  </div>
<?php else: ?>
  <h4><i class="bi bi-person-vcard me-2" style="color:#1a3c6b"></i>Mes informations</h4>
  <a href="<?= APP_URL ?>/secondaire/commun/pages/demandes/index.php" class="btn btn-sm btn-abz-outline">
    <i class="bi bi-file-earmark-text me-1"></i>Mes demandes de documents
  </a>
<?php endif; ?>
</div>

<?= flash_html() ?>

<div class="alert alert-info d-flex gap-2" style="font-size:.86rem">
  <i class="bi bi-info-circle mt-1"></i>
  <div>
    <?php if ($mode_gestion): ?>
    Vous modifiez la fiche de <strong><?= h($nom_complet) ?></strong>. Ces informations servent à générer
    ses documents officiels (attestation de présence, certificats de prise/reprise de service).
    Le grade, la fonction et la paie se modifient avec le bouton « Grade, fonction, paie ».
    <?php else: ?>
    Ces informations servent à générer vos documents officiels (attestation de présence,
    certificats de prise/reprise de service). Merci de toutes les renseigner avec exactitude —
    elles seront vérifiées par le Censeur puis le Proviseur lors du traitement de vos demandes.
    Seul le matricule ne peut pas être modifié.
    <?php endif; ?>
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
    <input type="text" value="<?= h($v('matricule') ?: '—') ?>" class="form-control form-control-sm" readonly title="Saisi par l'administration sur la fiche du personnel">
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
    <?php if ($mode_gestion): ?>
      <select name="grade" class="form-select form-select-sm js-carriere-select">
        <option value="">— Choisir —</option>
        <?php foreach ($grades as $g): ?>
          <option value="<?= h($g['code_grade']) ?>" <?= $v('id_grade') === $g['code_grade'] ? 'selected' : '' ?>><?= h($g['code_grade'] . ' — ' . $g['libelle_grade']) ?></option>
        <?php endforeach; ?>
        <option value="__autre__">➕ Autre (ajouter à la grille)…</option>
      </select>
      <input type="text" name="grade_autre" maxlength="120" class="form-control form-control-sm mt-1 js-carriere-autre" style="display:none" placeholder="Intitulé du nouveau grade">
    <?php else: ?>
      <?php $lib_g = ''; foreach ($grades as $g) if ($g['code_grade'] === $v('id_grade')) $lib_g = $g['code_grade'] . ' — ' . $g['libelle_grade']; ?>
      <input type="text" value="<?= h($lib_g ?: ($v('id_grade') ?: 'Non renseigné')) ?>" class="form-control form-control-sm" disabled
             title="Le grade est renseigné par l'administration (Proviseur / Principal)">
      <div class="form-text" style="font-size:.72rem">Renseigné par l'administration.</div>
    <?php endif; ?>
  </div>
  <div class="col-md-4">
    <label class="form-label">Fonction</label>
    <input type="text" value="<?= h($v('id_fonction')) ?>" class="form-control form-control-sm" disabled
           title="Géré par l'administration">
  </div>
  <div class="col-md-4">
    <label class="form-label">Qualité / En qualité de</label>
    <?= carriere_select('qualite', $v('qualite'), carriere_options('qualite')) ?>
  </div>
  <div class="col-md-4">
    <label class="form-label">Diplôme</label>
    <?= carriere_select('diplome', $v('diplome'), carriere_options('diplome')) ?>
  </div>
  <div class="col-md-4">
    <label class="form-label">Spécialité</label>
    <?= carriere_select('specialite', $v('specialite'), carriere_options('specialite')) ?>
  </div>
  <div class="col-md-4">
    <label class="form-label">Poste antérieur</label>
    <?= carriere_select('poste_anterieur', $v('poste_anterieur'), carriere_options('poste_anterieur')) ?>
  </div>
  <div class="col-12">
    <label class="form-label">Matière(s) effectivement enseignée(s) <span class="text-muted" style="font-size:.75rem">— plusieurs possibles</span></label>
    <?= carriere_matieres('matiere_enseignee', $v('matiere_enseignee'), carriere_options_matieres()) ?>
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
    fetch('<?= APP_URL ?>/noyau/ajax/departements_par_region.php?nom_region=' + encodeURIComponent(nomRegion))
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
    // noyau/ajax/arrondissements_par_departement.php (SIGES, type-aware secondaire)
    // renvoie [{id,nom}, ...] — pas des chaînes brutes.
    fetch('<?= APP_URL ?>/noyau/ajax/arrondissements_par_departement.php?departement=' + encodeURIComponent(nomDept))
      .then(function(r) { return r.json(); })
      .then(function(arrs) {
        var html = '<option value="">— Choisir —</option>';
        var trouve = false;
        arrs.forEach(function(a) {
          var sel = (preselection && a.nom === preselection) ? ' selected' : '';
          if (sel) trouve = true;
          html += '<option value="' + a.nom + '"' + sel + '>' + a.nom + '</option>';
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

<?= carriere_script() ?>

<?php require_once __DIR__ . '/../../../../noyau/layout/footer.php'; ?>
