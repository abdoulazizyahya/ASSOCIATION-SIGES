<?php
// verif_scolarite.php — page publique (pas de exiger_connexion) : quiconque
// scanne le QR imprimé sur un certificat de scolarité arrive ici pour
// vérifier son authenticité.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/connexion.php';
require_once __DIR__ . '/fonctions.php';
require_once __DIR__ . '/pdf/verif_scolarite_lib.php';

$id_eleve = (int)($_GET['e'] ?? 0);
$h_recu   = (string)($_GET['h'] ?? '');

$eleve = $id_eleve ? db_one("SELECT * FROM eleve WHERE id=?", [$id_eleve]) : null;

$authentique = false;
if ($eleve && $h_recu !== '') {
    $authentique = scolarite_verif_hash_ok($id_eleve, id_affichage_eleve($eleve), $h_recu);
}

$classe = $authentique ? db_one(
    "SELECT c.designation, a.libelle AS annee FROM inscription i
     JOIN classe c ON c.id=i.id_classe
     JOIN annee_scolaire a ON a.id=i.id_annee
     WHERE i.id_eleve=? ORDER BY i.id DESC LIMIT 1", [$id_eleve]
) : null;

// URL du certificat réel, ouverte uniquement si l'utilisateur clique sur le
// bouton "Ouvrir le certificat" (jamais automatiquement). Le jeton "vh" (=
// le hash déjà vérifié ci-dessus) permet à pdf/certificat_scolarite.php de
// servir ce certificat précis sans exiger de connexion.
$pdf_url = $authentique
    ? APP_URL . '/pdf/certificat_scolarite.php?id=' . $id_eleve . '&vh=' . urlencode($h_recu)
    : '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Vérification de certificat de scolarité — <?= h(APP_NOM) ?></title>
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
      <h4 class="mt-2 mb-1 text-success">Certificat de scolarité authentique</h4>
      <p class="text-muted mb-0">
        <strong><?= h(strtoupper($eleve['nom']) . ' ' . ($eleve['prenom'] ?? '')) ?></strong><br>
        NIU : <?= h(id_affichage_eleve($eleve)) ?><br>
        <?php if ($classe): ?>
          Classe : <?= h($classe['designation']) ?><br>
          Année scolaire : <?= h($classe['annee']) ?>
        <?php endif; ?>
      </p>
      <a href="<?= h($pdf_url) ?>" class="btn btn-success btn-sm mt-2">
        <i class="bi bi-file-earmark-pdf me-1"></i>Ouvrir le certificat
      </a>
    <?php else: ?>
      <div class="verif-icon text-danger"><i class="bi bi-x-octagon-fill"></i></div>
      <h4 class="mt-2 mb-1 text-danger">Document non vérifié</h4>
      <p class="text-muted mb-0">
        Ce certificat n'a pas pu être authentifié auprès de <?= h(APP_NOM) ?>.
        Il peut être falsifié, périmé ou incomplet.
      </p>
    <?php endif; ?>
  </div>
</body>
</html>
