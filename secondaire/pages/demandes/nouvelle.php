<?php
/**
 * Nouvelle demande de document administratif (ENSEIGNANT uniquement).
 * Bloque la soumission tant que les informations identitaires ne sont pas complètes.
 */
header('Cache-Control: no-store, no-cache, must-revalidate');
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_connexion();

$role = role_connecte();
if ($role !== 'ENSEIGNANT') { flash_set('erreur', 'Cette page est réservée aux enseignants.'); rediriger('dashboard.php'); }

$mat = matricule_ens_courant();
if (!$mat) { flash_set('erreur', 'Aucune fiche enseignant liée à votre compte.'); rediriger('dashboard.php'); }

$e = db_one("SELECT * FROM enseignant WHERE matricule_ens=?", [$mat]);
if (!$e) { flash_set('erreur', 'Fiche enseignant introuvable.'); rediriger('dashboard.php'); }

$manquants = identite_ens_manquants($e);
if (!empty($manquants)) {
    flash_set('erreur', 'Veuillez compléter vos informations identitaires avant de soumettre une demande (champs manquants : '.implode(', ', $manquants).').');
    rediriger('secondaire/pages/enseignants/mon_profil.php');
}

// Types déjà en cours de traitement (pour éviter les doublons de demande active)
$en_cours = db_all(
    "SELECT type_document FROM demande_document
     WHERE matricule_ens=? AND statut IN ('en_attente_censeur','en_attente_proviseur')",
    [$mat]
);
$types_en_cours = array_column($en_cours, 'type_document');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $type  = $_POST['type_document'] ?? '';
    $motif = trim($_POST['motif'] ?? '');

    if (!in_array($type, ['attestation','prise','reprise'], true)) {
        flash_set('erreur', 'Type de document invalide.'); rediriger('secondaire/pages/demandes/nouvelle.php');
    }
    if (in_array($type, $types_en_cours, true)) {
        flash_set('erreur', 'Une demande de ce type est déjà en cours de traitement.'); rediriger('secondaire/pages/demandes/nouvelle.php');
    }

    $annee_act = get_annee_active();
    db_exec(
        "INSERT INTO demande_document (matricule_ens, type_document, motif, val_annee, statut)
         VALUES (?,?,?,?, 'en_attente_censeur')",
        [$mat, $type, $motif ?: null, $annee_act['libelle'] ?? null]
    );

    $nom_ens = trim(($e['civilite_ens']??'').' '.strtoupper($e['nom_ens']).' '.($e['prenom_ens']??''));
    notifier_role('CENSEUR',
        "Nouvelle demande de ".libelle_type_demande($type)." de la part de $nom_ens.",
        'secondaire/pages/demandes/index.php');

    flash_set('succes', 'Votre demande a été soumise et transmise au Censeur pour vérification.');
    rediriger('secondaire/pages/demandes/index.php');
}

$titre_page = 'Nouvelle demande de document';
require_once __DIR__ . '/../../../layout/header.php';
?>
<style>
.btn-abz-primary{background:#1a3c6b;color:#fff;border:none;}
.btn-abz-outline{background:#fff;color:#1a3c6b;border:1.5px solid #1a3c6b;}
.btn-abz-outline:hover{background:#1a3c6b;color:#fff;}
.type-card{border:1.5px solid #c7d8f0;border-radius:8px;padding:14px;cursor:pointer;transition:.15s}
.type-card:hover{background:#f0f4ff}
.type-card input:checked ~ .type-inner, .type-card.checked{border-color:#1a3c6b;background:#f0f4ff}
</style>

<div class="page-titre d-flex align-items-center justify-content-between flex-wrap gap-2">
  <h4><i class="bi bi-file-earmark-plus me-2" style="color:#1a3c6b"></i>Nouvelle demande de document</h4>
  <a href="index.php" class="btn btn-sm btn-abz-outline"><i class="bi bi-arrow-left me-1"></i>Mes demandes</a>
</div>

<?= flash_html() ?>

<div class="card" style="border-color:#c7d8f0;max-width:640px">
  <div class="card-body">
    <form method="post">
      <?= csrf_champ() ?>
      <label class="form-label fw-semibold mb-2">Type de document souhaité</label>
      <div class="d-flex flex-column gap-2 mb-3">
        <?php
        $options = [
            'attestation' => ['Attestation de présence effective', 'bi-file-earmark-text'],
            'prise'       => ['Certificat de prise de service',    'bi-file-earmark-check'],
            'reprise'     => ['Certificat de reprise de service',  'bi-file-earmark-arrow-up'],
        ];
        foreach ($options as $val => [$label, $icon]):
            $bloque = in_array($val, $types_en_cours, true);
        ?>
        <label class="type-card d-flex align-items-center gap-2 <?= $bloque ? 'opacity-50' : '' ?>">
          <input type="radio" name="type_document" value="<?= $val ?>" required <?= $bloque ? 'disabled' : '' ?>>
          <i class="bi <?= $icon ?>" style="color:#1a3c6b"></i>
          <span><?= h($label) ?></span>
          <?php if ($bloque): ?><span class="badge bg-warning ms-auto">Demande déjà en cours</span><?php endif; ?>
        </label>
        <?php endforeach; ?>
      </div>

      <div class="mb-3">
        <label class="form-label">Motif / précision (facultatif)</label>
        <textarea name="motif" class="form-control form-control-sm" rows="2" placeholder="ex : dossier de bourse, démarche administrative…"></textarea>
      </div>

      <button type="submit" class="btn btn-abz-primary"><i class="bi bi-send me-1"></i>Soumettre la demande</button>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/../../../layout/footer.php'; ?>
