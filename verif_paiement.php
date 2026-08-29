<?php
// verif_paiement.php — page publique (pas de exiger_connexion) : quiconque
// scanne le QR imprimé sur un reçu de paiement arrive ici pour vérifier son
// authenticité. Ne montre qu'un résumé non sensible (pas l'historique complet
// de l'élève) : cette page est accessible à quiconque scanne le reçu papier.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/connexion.php';
require_once __DIR__ . '/fonctions.php';
require_once __DIR__ . '/pdf/verif_paiement_lib.php';

$id_paiement = (int)($_GET['p'] ?? 0);
$h_recu      = (string)($_GET['h'] ?? '');

$paiement = $id_paiement ? db_one(
    "SELECT p.*, e.nom, e.prenom, e.matricule FROM paiement_frais p
     JOIN eleve e ON e.id = p.id_eleve WHERE p.id = ?", [$id_paiement]
) : null;

$authentique = false;
if ($paiement && $h_recu !== '') {
    $h_attendu   = paiement_verif_hash((int)$paiement['id'], $paiement['numero_recu']);
    $authentique = hash_equals($h_attendu, $h_recu);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Vérification de reçu — <?= h(APP_NOM) ?></title>
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
      <h4 class="mt-2 mb-1 text-success">Reçu authentique</h4>
      <p class="text-muted mb-1">
        N° <strong><?= h($paiement['numero_recu']) ?></strong><br>
        <?= h(strtoupper($paiement['nom']) . ' ' . ($paiement['prenom'] ?? '')) ?> — Matricule <?= h($paiement['matricule']) ?><br>
        Montant : <?= number_format((float)$paiement['montant'], 0, ',', ' ') ?> FCFA<br>
        Date : <?= h(date('d/m/Y', strtotime($paiement['date_paiement']))) ?>
      </p>
    <?php else: ?>
      <div class="verif-icon text-danger"><i class="bi bi-x-octagon-fill"></i></div>
      <h4 class="mt-2 mb-1 text-danger">Document non vérifié</h4>
      <p class="text-muted mb-0">
        Ce reçu n'a pas pu être authentifié auprès de <?= h(APP_NOM) ?>.
        Il peut être falsifié, périmé ou incomplet.
      </p>
    <?php endif; ?>
  </div>
</body>
</html>
