<?php
// verif_recu.php — page publique (pas de exiger_connexion) : quiconque
// scanne le QR imprimé sur un reçu de paiement arrive ici pour vérifier son
// authenticité. Même pattern que verif_bulletin.php.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/connexion.php';
require_once __DIR__ . '/fonctions.php';
require_once __DIR__ . '/pdf/verif_recu_lib.php';
verif_exiger_ecole_publique();  // multi-école : URL sans &ec= -> page neutre

$id_eleve  = (int) ($_GET['e'] ?? 0);
$val_annee = (string) ($_GET['a'] ?? '');
$h_recu    = (string) ($_GET['h'] ?? '');

$eleve       = recu_verif_valider($id_eleve, $val_annee, $h_recu);
$authentique = $eleve !== null;

// URL du reçu réel, ouverte uniquement si l'utilisateur clique sur le bouton
// "Ouvrir le reçu" (jamais automatiquement). Le jeton "vh" (= le hash déjà
// vérifié ci-dessus) permet à pages/finances/recu.php de servir CE reçu
// précis sans exiger de connexion — même mécanisme que les bulletins.
$pdf_url = '';
if ($authentique) {
    $id_classe = (int) db_val("SELECT IDClasses FROM inscrire WHERE id_eleve=? AND val_annee=? LIMIT 1", [$id_eleve, $val_annee]);
    // verif_ajout_ec() : en multi-établissement, propage &ec=CODE — sans lui
    // pages/finances/recu.php (accès public) ne sait pas dans quelle base
    // chercher et connexion.php reste pointé sur l'annuaire (erreur SQL
    // « table annee_scolaire n'existe pas »).
    $pdf_url = verif_ajout_ec(APP_URL . '/pages/finances/recu.php?eleve=' . $id_eleve . '&classe=' . $id_classe . '&vh=' . urlencode($h_recu));
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
      <h4 class="mt-2 mb-1 text-success">Document authentique</h4>
      <p class="text-muted mb-3">
        Reçu de paiement de <strong><?= h(mb_strtoupper($eleve['Nom_elv']) . ' ' . ($eleve['Prenom_elv'] ?? '')) ?></strong><br>
        Matricule : <?= h($eleve['Mat_elv']) ?> — Année scolaire <?= h($val_annee) ?>
      </p>
      <a href="<?= h($pdf_url) ?>" class="btn btn-success btn-sm">
        <i class="bi bi-file-earmark-pdf me-1"></i>Ouvrir le reçu
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
