<?php
// compte/a_propos.php — À propos de l'application (02/11/2026) : versions,
// développeur et contacts (accessible avant la connexion,
// uniquement dans l'application Android installée).
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../noyau/fonctions.php';
require_once __DIR__ . '/../noyau/connexion_assoc.php';
require_once __DIR__ . '/../noyau/pwa.php';
session_init();
// Réservée à l'application installée (APK) : depuis un navigateur, retour à la connexion.
if (!pwa_mode_app()) { header('Location: ' . APP_URL . '/compte/login.php'); exit; }

[$nom_app] = pwa_identite(pwa_ecole());
$apk_inst  = pwa_apk_installee();
$apk_der   = pwa_apk_version();
$apk_f     = pwa_apk_fichier();
$maj       = trim((string) assoc_param('nouveautes_date', ''));
$retour    = est_connecte() ? APP_URL . '/noyau/dashboard.php' : APP_URL . '/compte/login.php';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>À propos — <?= h($nom_app) ?></title>
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/bootstrap/css/bootstrap.min.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/inter/inter.css">
  <?= pwa_balises() ?>
  <style>
    body { font-family:'Inter',sans-serif; background:linear-gradient(135deg,#0f1a3a 0%,#1e4fd8 100%); min-height:100vh; display:flex; align-items:center; justify-content:center; margin:0; padding:1rem; }
    .boite { background:#fff; border-radius:16px; padding:1.8rem 1.6rem; width:min(96vw,420px); box-shadow:0 20px 60px rgba(0,0,0,.35); }
    .ligne { display:flex; justify-content:space-between; gap:1rem; font-size:.85rem; padding:.45rem 0; border-bottom:1px solid #eef0f4; }
    .ligne span:first-child { color:#6b7280; }
    .ligne span:last-child { font-weight:600; color:#1e2a3a; text-align:right; }
    .titre { font-size:.72rem; text-transform:uppercase; letter-spacing:.06em; color:#9ca3af; font-weight:700; margin:1.1rem 0 .3rem; }
    a { color:#1e4fd8; text-decoration:none; }
  </style>
</head>
<body>
<div class="boite">
  <div class="text-center mb-2">
    <img src="<?= h(APP_URL . '/index.php?pwa=icone&t=192' . (pwa_ecole() ? '&ec=' . rawurlencode(pwa_ecole()['code']) : '')) ?>" alt="" style="width:64px;height:64px;border-radius:14px">
    <h5 class="mt-2 mb-0 fw-bold"><?= h($nom_app) ?></h5>
    <div class="small text-muted">Système intégré de gestion scolaire (SIGES)</div>
  </div>

  <div class="titre">Versions</div>
  <div class="ligne"><span>Système SIGES</span><span>v2.0<?= $maj !== '' ? ' — mise à jour du ' . h($maj) : '' ?></span></div>
  <div class="ligne"><span>Application Android</span>
    <span><?= $apk_inst > 0 ? 'v' . $apk_inst . ($apk_inst < $apk_der ? ' (v' . $apk_der . ' disponible)' : ' (à jour)') : 'navigateur — dernière APK : v' . $apk_der ?></span></div>
  <?php if ($apk_f !== ''): ?>
  <div class="ligne"><span>Télécharger l'APK</span><span><a href="<?= h(APP_URL . '/assets/app/' . rawurlencode($apk_f)) ?>" download><i class="bi bi-download me-1"></i>v<?= $apk_der ?></a></span></div>
  <?php endif; ?>

  <div class="titre">Développeur</div>
  <div class="ligne"><span>Développé par</span><span><?= h(PWA_DEVELOPPEUR) ?></span></div>
  <div class="ligne"><span>Téléphone</span><span>
    <?php foreach (PWA_TELEPHONES as $i => $tel): ?><?= $i ? ' / ' : '' ?><a href="tel:+237<?= h($tel) ?>"><?= h($tel) ?></a><?php endforeach; ?>
  </span></div>
  <div class="ligne"><span>E-mail</span><span><a href="mailto:<?= h(PWA_EMAIL) ?>"><?= h(PWA_EMAIL) ?></a></span></div>

  <div class="text-center mt-3">
    <a href="<?= h($retour) ?>" class="btn btn-primary btn-sm"><i class="bi bi-arrow-left me-1"></i>Retour</a>
  </div>
  <div class="text-center mt-3" style="font-size:.7rem;color:#9ca3af">© <?= date('Y') ?> <?= h(PWA_DEVELOPPEUR) ?></div>
</div>
</body>
</html>
