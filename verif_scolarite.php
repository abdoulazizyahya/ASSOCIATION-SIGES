<?php
// verif_scolarite.php — page publique (pas de exiger_connexion) : quiconque
// scanne le QR imprimé sur un certificat de scolarité arrive ici pour
// vérifier son authenticité.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/connexion.php';
require_once __DIR__ . '/fonctions.php';
require_once __DIR__ . '/pdf/verif_scolarite_lib.php';
verif_exiger_ecole_publique();  // multi-école : URL sans &ec= -> page neutre

$id_eleve = (int)($_GET['e'] ?? 0);
$h_recu   = (string)($_GET['h'] ?? '');

// Schéma jaynitaare_v2 : eleve.id_eleve, table `inscrire` (val_annee texte,
// pas d'id numérique), classe.DesignationClasses — voir verif_carte.php,
// corrigé de la même façon le 15/08/2026.
$eleve = $id_eleve ? db_one("SELECT * FROM eleve WHERE id_eleve=?", [$id_eleve]) : null;

$authentique = false;
if ($eleve && $h_recu !== '') {
    $authentique = scolarite_verif_hash_ok($id_eleve, id_affichage_eleve($eleve), $h_recu);
}

$classe = $authentique ? db_one(
    "SELECT c.DesignationClasses AS designation, i.val_annee AS annee
     FROM inscrire i JOIN classe c ON c.IDClasses = i.IDClasses
     WHERE i.id_eleve=? ORDER BY i.val_annee DESC LIMIT 1", [$id_eleve]
) : null;

// URL du certificat réel, ouverte uniquement si l'utilisateur clique sur le
// bouton "Ouvrir le certificat" (jamais automatiquement). Le jeton "vh" (=
// le hash déjà vérifié ci-dessus) permet à pdf/certificat_scolarite.php de
// servir ce certificat précis sans exiger de connexion.
// verif_ajout_ec() : en multi-établissement, propage &ec=CODE — sinon
// pdf/certificat_scolarite.php (accès public par « vh ») reste pointé sur
// l'annuaire.
$pdf_url = $authentique
    ? verif_ajout_ec(APP_URL . '/pdf/certificat_scolarite.php?id=' . $id_eleve . '&vh=' . urlencode($h_recu))
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
        <strong><?= h(mb_strtoupper($eleve['Nom_elv']) . ' ' . ($eleve['Prenom_elv'] ?? '')) ?></strong><br>
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
