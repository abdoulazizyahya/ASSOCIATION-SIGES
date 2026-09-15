<?php
// secondaire_en_construction.php — page d'attente autonome pour une école
// de type 'secondaire' (schema_ref_ecole_secondaire.sql, porté de LAM_ABZ).
//
// Pourquoi ce fichier existe : AUCUNE page « primaire » de SIGES n'est
// compatible avec ce schéma — noms de tables/colonnes différents partout
// (ex. annee_scolaire.active au lieu de Etat_annee_scolaire, sequence.
// active au lieu de etat, `utilisateur`/`enseignant` au lieu de `user`/
// `enseignant`...). Même layout/header.php plante dès sa 2e ligne
// (get_annee_active()) avant d'avoir eu la moindre chance d'afficher quoi
// que ce soit. Tant que le module secondaire/ dédié (pages, menu,
// dashboard — voir commit fb21462) n'existe pas, connexion.php redirige
// ICI toute page atteinte à l'intérieur d'une école secondaire, plutôt que
// de laisser un Fatal error s'afficher.
//
// Volontairement autonome : n'inclut PAS layout/header.php (qui plante),
// pas de menu_definition() (100% de ses entrées pointent vers des pages
// primaire), pas de requête sur la base école. Utilise uniquement
// $ETAB_COURANT (ligne de l'annuaire — mêmes colonnes quel que soit le
// type d'école) et la session déjà résolue par connexion.php.

$nom_ecole = $ETAB_COURANT['nom'] ?? 'cette école';
$sigle     = $ETAB_COURANT['sigle'] ?? '';
$visite    = function_exists('est_visite_association') && est_visite_association();
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>En construction — <?= htmlspecialchars($nom_ecole) ?></title>
<style>
  body { font-family:'Segoe UI',system-ui,sans-serif; background:linear-gradient(135deg,#0f1a3a 0%,#1e4fd8 100%); min-height:100vh; display:flex; align-items:center; justify-content:center; margin:0; padding:16px; box-sizing:border-box; }
  .box { background:#fff; border-radius:16px; padding:2.4rem 2rem; width:min(96vw,480px); box-shadow:0 20px 60px rgba(0,0,0,.35); text-align:center; }
  .ico { width:64px;height:64px;border-radius:16px;background:linear-gradient(135deg,#3a6cff,#7a4dff);display:flex;align-items:center;justify-content:center;font-size:1.8rem;color:#fff;margin:0 auto 1rem;box-shadow:0 6px 20px rgba(58,108,255,.4) }
  h1 { font-size:1.15rem;font-weight:700;color:#1e2a3a;margin:0 0 .4rem }
  .etab { font-size:.92rem;color:#6b7280;margin-bottom:1.2rem }
  .etab strong { color:#1e2a3a }
  p.msg { font-size:.85rem;line-height:1.6;color:#4b5563;margin-bottom:1.6rem }
  .badge { display:inline-block;background:#fff8e6;color:#7a5b00;border:1px solid #f0dca0;border-radius:20px;padding:4px 14px;font-size:.72rem;font-weight:600;margin-bottom:1.2rem }
  .liens { display:flex;gap:10px;justify-content:center;flex-wrap:wrap }
  .btn { display:inline-flex;align-items:center;gap:6px;padding:.55rem 1.1rem;border-radius:8px;font-size:.82rem;font-weight:600;text-decoration:none;border:1px solid transparent }
  .btn-primary { background:linear-gradient(135deg,#1e4fd8,#3a6cff);color:#fff }
  .btn-light { background:#f3f4f6;color:#374151;border-color:#e5e7eb }
</style>
</head>
<body>
<div class="box">
  <div class="ico">🏗️</div>
  <div class="badge">Module en construction</div>
  <h1>Établissement secondaire</h1>
  <div class="etab">
    <strong><?= htmlspecialchars($nom_ecole) ?></strong><?= $sigle ? ' (' . htmlspecialchars($sigle) . ')' : '' ?>
  </div>
  <p class="msg">
    La structure de la base est prête (élèves, classes, notes, compétences, finances…),
    mais les écrans dédiés aux établissements secondaires ne sont pas encore construits
    dans SIGES — ils arrivent bientôt. Merci de votre patience.
  </p>
  <div class="liens">
    <?php if ($visite): ?>
      <a href="<?= APP_URL ?>/association/index.php" class="btn btn-primary">← Retour à l'espace association</a>
    <?php else: ?>
      <a href="<?= APP_URL ?>/logout.php" class="btn btn-light">Se déconnecter</a>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
