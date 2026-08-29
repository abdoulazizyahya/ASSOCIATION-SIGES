<?php
// ── Mise en page minimale de l'interface association ────────────────
//  Volontairement distincte du shell « école » (layout/header.php) :
//  contexte différent, pas d'établissement unique, pas de menu scolaire.

function asso_haut(string $titre, bool $avec_nav = true): void {
    $m = membre_connecte();
    ?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($titre) ?> — Association</title>
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/bootstrap/css/bootstrap.min.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/inter/inter.css">
  <style>
    body { font-family:'Inter',system-ui,sans-serif; background:#0b1220; color:#e5e9f0; margin:0; }
    .asso-topbar { background:#11192b; border-bottom:1px solid #23304d; padding:.7rem 1.2rem;
                   display:flex; align-items:center; gap:1rem; position:sticky; top:0; z-index:10; }
    .asso-brand { font-weight:800; letter-spacing:.5px; color:#fff; display:flex; align-items:center; gap:.5rem; }
    .asso-brand .dot { width:10px; height:10px; border-radius:3px; background:linear-gradient(135deg,#3a6cff,#7a4dff); }
    .asso-wrap { max-width:1100px; margin:0 auto; padding:1.6rem 1.2rem 3rem; }
    .asso-card { background:#141d33; border:1px solid #23304d; border-radius:14px; padding:1.2rem; }
    a { color:#8ab4ff; }
    .text-muted2 { color:#8b97ad !important; }
    .ecole-card { background:#141d33; border:1px solid #23304d; border-radius:14px; padding:1.1rem 1.2rem;
                  transition:border-color .15s; }
    .ecole-card:hover { border-color:#3a6cff; }
    .badge-soft { background:#1e2a44; color:#a9b7d0; font-weight:600; }
  </style>
</head>
<body>
<?php if ($avec_nav): ?>
<div class="asso-topbar">
  <span class="asso-brand"><span class="dot"></span> ASSOCIATION</span>
  <a href="<?= APP_URL ?>/association/index.php" class="text-decoration-none text-white-50 small">
    <i class="bi bi-grid me-1"></i>Établissements
  </a>
  <div class="ms-auto d-flex align-items-center gap-3 small">
    <span class="text-muted2"><i class="bi bi-person-circle me-1"></i><?= h(trim(($m['prenom'] ?? '') . ' ' . ($m['nom'] ?? ''))) ?: 'Membre' ?></span>
    <a href="<?= APP_URL ?>/association/logout.php" class="text-danger text-decoration-none">
      <i class="bi bi-box-arrow-right me-1"></i>Déconnexion
    </a>
  </div>
</div>
<?php endif; ?>
<div class="asso-wrap">
  <h1 class="h4 fw-bold mb-3"><?= h($titre) ?></h1>
<?php
}

function asso_bas(): void {
    ?>
</div>
<script src="<?= APP_URL ?>/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
}
