<?php
// secondaire/pages/paiements/signatures.php — configuration des signatures numériques
// des signataires autres que le chef d'établissement (voir
// secondaire/pages/parametres/index.php pour celle-ci) : Intendant, Président de
// l'APEE, Censeur, Surveillant Général — sur le même principe : une image
// choisie une fois ici par la personne habilitée, jamais appliquée
// automatiquement — chaque impression choisit ensuite si elle s'applique à
// ce document précis. Chaque carte n'est visible/modifiable que par le rôle
// correspondant (ADMIN autorisé partout en secours).
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'CENSEUR', 'INTENDANT', 'SG']);

$codes_geres = ['censeur', 'surveillant_general', 'intendant', 'president_apee', 'tresorier_apee'];
$role = role_connecte();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $code = post('code');
    if (!in_array($code, $codes_geres, true) || !signature_role_autorisee($code, $role)) {
        flash_set('erreur', 'Accès refusé pour ce signataire.');
        rediriger('secondaire/pages/paiements/signatures.php');
    }
    if (empty($_FILES['fichier']['tmp_name']) || $_FILES['fichier']['error'] !== UPLOAD_ERR_OK) {
        flash_set('erreur', 'Aucun fichier reçu.');
        rediriger('secondaire/pages/paiements/signatures.php');
    }
    $ext_ok = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
    $ext    = strtolower(pathinfo($_FILES['fichier']['name'], PATHINFO_EXTENSION));
    $fi     = finfo_open(FILEINFO_MIME_TYPE);
    $mime   = finfo_file($fi, $_FILES['fichier']['tmp_name']);
    finfo_close($fi);
    if (!isset($ext_ok[$ext]) || $ext_ok[$ext] !== $mime) {
        flash_set('erreur', 'Fichier invalide — JPG ou PNG uniquement.');
        rediriger('secondaire/pages/paiements/signatures.php');
    }
    // Fond blanc nettoyé/rendu transparent (PNG) : le texte du document
    // reste visible même si la signature est déplacée par-dessus.
    $fichier = 'signature_' . $code . '.png';
    if (signature_traiter_transparence($_FILES['fichier']['tmp_name'], __DIR__ . '/../../../assets/uploads/' . $fichier)) {
        db_exec("UPDATE signature_titulaire SET fichier=? WHERE code=?", [$fichier, $code]);
        flash_set('succes', 'Signature mise à jour.');
    } else {
        flash_set('erreur', "Échec du traitement de l'image.");
    }
    rediriger('secondaire/pages/paiements/signatures.php');
}

$titulaires = get_signature_titulaires();
$codes_visibles = array_filter($codes_geres, fn($c) => signature_role_autorisee($c, $role));

$titre_page = 'Signatures numériques';
require_once __DIR__ . '/../../../layout/header.php';
?>
<div class="page-titre d-flex justify-content-between align-items-center">
  <h4><i class="bi bi-vector-pen me-1 text-primary"></i><?= h($titre_page) ?></h4>
</div>

<div class="alert alert-info" style="font-size:.85rem">
  <i class="bi bi-info-circle me-1"></i>Ces signatures ne sont jamais appliquées automatiquement : chaque impression
  propose une case à cocher pour les ajouter ou non au document. Le fond de l'image est automatiquement nettoyé
  (rendu transparent) pour que le texte du document reste visible en dessous.
</div>

<?php if (empty($codes_visibles)): ?>
  <div class="alert alert-warning" style="font-size:.85rem">Aucune signature à configurer pour votre rôle.</div>
<?php endif; ?>

<div class="row g-3">
  <?php foreach ($codes_visibles as $code): $t = $titulaires[$code] ?? null; ?>
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header py-2" style="background:#f8faff">
        <span class="fw-semibold" style="font-size:.85rem"><?= h($t['libelle'] ?? $code) ?></span>
      </div>
      <div class="card-body">
        <?php if (!empty($t['fichier'])): ?>
          <div class="mb-2">
            <img src="<?= APP_URL ?>/assets/uploads/<?= h($t['fichier']) ?>"
                 style="height:56px;border-radius:6px;border:1px solid #e5e7eb;background:repeating-conic-gradient(#e5e7eb 0% 25%, #fff 0% 50%) 50% / 12px 12px">
          </div>
        <?php else: ?>
          <div class="text-muted mb-2" style="font-size:.8rem">Aucune signature configurée.</div>
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data" class="d-flex gap-2 align-items-end flex-wrap">
          <?= csrf_champ() ?>
          <input type="hidden" name="code" value="<?= h($code) ?>">
          <div class="flex-grow-1">
            <input type="file" name="fichier" class="form-control form-control-sm" accept="image/jpeg,image/png" required>
          </div>
          <button class="btn btn-sm btn-abz-primary"><i class="bi bi-upload me-1"></i>Enregistrer</button>
        </form>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/../../../layout/footer.php'; ?>
