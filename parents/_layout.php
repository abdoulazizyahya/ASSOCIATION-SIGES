<?php
// parents/_layout.php — habillage de l'espace parents (16/11/2026) : page
// simple, lisible au téléphone, aux couleurs de l'application (marine + or).
function ep_haut(string $titre, bool $connecte = false): void {
    $etab = function_exists('get_etablissement') && ecole_courante() ? get_etablissement() : [];
    $nom = (string) ($etab['Nom_Etab_Fr'] ?? (ecole_courante()['nom'] ?? APP_NOM));
    $logo = !empty($etab['logo']) && is_file(__DIR__ . '/../assets/uploads/' . $etab['logo']) ? APP_URL . '/assets/uploads/' . $etab['logo'] : null;
    ?><!doctype html>
<html lang="fr"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= h($titre) ?> — Espace parents</title>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/bootstrap/css/bootstrap.min.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/inter/inter.css">
<style>
  body { background:#f4f2ec; font-family:Inter, system-ui, sans-serif; color:#1e2a3a; }
  .ep-tete { background:#1a2744; color:#fff; padding:.8rem 1rem; display:flex; align-items:center; gap:.7rem; }
  .ep-tete img { width:40px; height:40px; border-radius:9px; object-fit:cover; background:#fff; }
  .ep-tete .nom { font-family:Georgia, serif; font-size:1.02rem; line-height:1.2; }
  .ep-tete .sous { font-size:.75rem; color:#d6b25e; }
  .ep-page { max-width:720px; margin:0 auto; padding:1rem; }
  .ep-carte { background:#fff; border-radius:12px; box-shadow:0 1px 3px rgba(0,0,0,.08); padding:1rem; margin-bottom:1rem; }
  .ep-carte h2 { font-family:Georgia, serif; font-size:1.05rem; color:#1a2744; margin-bottom:.6rem; }
  .ep-chiffre { font-size:1.35rem; font-weight:700; }
  .ep-or { color:#b8862b; }
  .btn-ep { background:#1a2744; color:#fff; } .btn-ep:hover { background:#24365e; color:#fff; }
  .table-ep { font-size:.86rem; } .table-ep th { color:#6b7280; font-weight:500; }
</style>
</head><body>
<div class="ep-tete">
  <?php if ($logo): ?><img src="<?= h($logo) ?>" alt=""><?php endif; ?>
  <div class="flex-grow-1"><div class="nom"><?= h($nom) ?></div><div class="sous">Espace parents</div></div>
  <?php if ($connecte): ?><a href="<?= APP_URL ?>/parents/deconnexion.php" class="btn btn-sm btn-outline-light"><i class="bi bi-box-arrow-right"></i><span class="d-none d-sm-inline ms-1">Quitter</span></a><?php endif; ?>
</div>
<div class="ep-page">
<?php
}

function ep_bas(): void {
    ?></div>
<p class="text-center text-muted small pb-3"><?= function_exists('siges_copyright') ? h(siges_copyright()) : '' ?></p>
</body></html><?php
}

function ep_fcfa(float $v): string { return number_format($v, 0, ',', ' ') . ' F'; }
function ep_note(?float $v): string { return $v === null ? '—' : number_format($v, 2, ',', ''); }
