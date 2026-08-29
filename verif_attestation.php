<?php
// verif_attestation.php — page publique (pas de exiger_connexion) : quiconque
// scanne le QR imprimé sur une attestation de présence ou un certificat de
// prise/reprise de service arrive ici pour vérifier son authenticité.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/connexion.php';
require_once __DIR__ . '/fonctions.php';
require_once __DIR__ . '/pdf/verif_attestation_lib.php';

$type_recu = (string)($_GET['t'] ?? '');
$type      = in_array($type_recu, ['attestation', 'prise', 'reprise'], true) ? $type_recu : '';
$mat       = (string)($_GET['m'] ?? '');
$h_recu    = (string)($_GET['h'] ?? '');

$libelles = [
    'attestation' => "Attestation de présence effective",
    'prise'       => "Certificat de prise de service",
    'reprise'     => "Certificat de reprise de service",
];

$e = ($type && $mat) ? db_one("SELECT * FROM enseignant WHERE matricule_ens=?", [$mat]) : null;

$authentique = false;
if ($e && $type && $h_recu !== '') {
    $authentique = attestation_verif_hash_ok($type, $mat, $h_recu);
}

// URL du document réel, ouverte uniquement si l'utilisateur clique sur le
// bouton "Ouvrir" (jamais automatiquement). Le jeton "vh" (= le hash déjà
// vérifié ci-dessus) permet à pdf_attestation.php / pdf_prise_service.php de
// servir ce document précis sans exiger de connexion.
$pdf_urls = [
    'attestation' => '/pages/enseignants/pdf_attestation.php?id=' . urlencode($mat) . '&vh=' . urlencode($h_recu),
    'prise'       => '/pages/enseignants/pdf_prise_service.php?type=prise&id=' . urlencode($mat) . '&vh=' . urlencode($h_recu),
    'reprise'     => '/pages/enseignants/pdf_prise_service.php?type=reprise&id=' . urlencode($mat) . '&vh=' . urlencode($h_recu),
];
$pdf_url = $authentique ? APP_URL . $pdf_urls[$type] : '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Vérification de document — <?= h(APP_NOM) ?></title>
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/bootstrap/css/bootstrap.min.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
  <style>
    body { background:#0f1a3a; min-height:100vh; display:flex; align-items:center; justify-content:center; font-family:system-ui,sans-serif; }
    .verif-card { background:#fff; border-radius:16px; padding:2.2rem 1.8rem; max-width:420px; width:92%; text-align:center; box-shadow:0 10px 40px rgba(0,0,0,.35); }
    .verif-icon { font-size:3.2rem; }
  </style>
</head>
<body>
  <div class="verif-card">
    <?php if ($authentique): ?>
      <div class="verif-icon text-success"><i class="bi bi-patch-check-fill"></i></div>
      <h4 class="mt-2 mb-1 text-success"><?= h($libelles[$type]) ?> authentique</h4>
      <p class="text-muted mb-0">
        <strong><?= h(strtoupper(trim(($e['civilite_ens'] ?? '') . ' ' . $e['nom_ens'] . ' ' . ($e['prenom_ens'] ?? '')))) ?></strong><br>
        Matricule : <?= h($e['matricule_ens']) ?><br>
        <?php if (!empty($e['id_grade'])): ?>Grade : <?= h($e['id_grade']) ?><?php endif; ?>
      </p>
      <a href="<?= h($pdf_url) ?>" class="btn btn-success btn-sm mt-2">
        <i class="bi bi-file-earmark-pdf me-1"></i>Ouvrir le document
      </a>
    <?php else: ?>
      <div class="verif-icon text-danger"><i class="bi bi-x-octagon-fill"></i></div>
      <h4 class="mt-2 mb-1 text-danger">Document non vérifié</h4>
      <p class="text-muted mb-0">
        Ce document n'a pas pu être authentifié auprès de <?= h(APP_NOM) ?>.
        Il peut être falsifié, périmé ou incomplet.
      </p>
    <?php endif; ?>
  </div>
</body>
</html>
