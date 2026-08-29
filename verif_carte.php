<?php
// verif_carte.php — page publique (pas de exiger_connexion) : quiconque
// scanne le QR d'une carte scolaire arrive ici pour vérifier son
// authenticité. Même pattern que verif_paiement.php/verif_recu.php.
//
// Corrigé le 15/08/2026 : ce fichier utilisait encore les noms de table/
// colonnes d'ABZ_MBE (eleve.id, inscription, classe.designation, id_annee
// entier) — jamais adaptés au schéma réel de jaynitaare_v2_bd
// (eleve.id_eleve, inscrire.val_annee texte, classe.DesignationClasses).
// Pas de QR imprimé sur la carte physique pour l'instant (décision
// explicite de l'utilisateur) — ce fichier reste donc inatteignable en
// pratique, mais est maintenant correct et utilisable si ce lien est
// généré un jour (voir pdf/verif_carte_lib.php::carte_verif_url()).
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/connexion.php';
require_once __DIR__ . '/fonctions.php';
require_once __DIR__ . '/pdf/verif_carte_lib.php';

$id_eleve  = (int) ($_GET['e'] ?? 0);
$val_annee = (string) ($_GET['a'] ?? '');
$h_recu    = (string) ($_GET['h'] ?? '');

$eleve       = carte_verif_valider($id_eleve, $val_annee, $h_recu);
$authentique = $eleve !== null;
$insc        = $authentique
    ? db_one("SELECT c.DesignationClasses AS classe FROM inscrire i JOIN classe c ON c.IDClasses=i.IDClasses WHERE i.id_eleve=? AND i.val_annee=? LIMIT 1", [$id_eleve, $val_annee])
    : null;

// URL de la carte réelle, ouverte uniquement si l'utilisateur clique sur le
// bouton "Ouvrir la carte" (jamais automatiquement). Le jeton "vh" (= le
// hash déjà vérifié ci-dessus) permet à pdf/cartes.php de servir cette
// carte précise, pour ce seul élève, sans exiger de connexion.
$pdf_url = $authentique
    ? APP_URL . '/pdf/cartes.php?eleve=' . $id_eleve . '&annee=' . urlencode($val_annee) . '&vh=' . urlencode($h_recu)
    : '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Vérification de carte scolaire — <?= h(APP_NOM) ?></title>
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
      <h4 class="mt-2 mb-1 text-success">Carte scolaire authentique</h4>
      <p class="text-muted mb-0">
        <strong><?= h(mb_strtoupper($eleve['Nom_elv']) . ' ' . ($eleve['Prenom_elv'] ?? '')) ?></strong><br>
        Matricule : <?= h($eleve['Mat_elv']) ?><br>
        <?php if ($insc): ?>Classe : <?= h($insc['classe']) ?><br><?php endif; ?>
        Année scolaire <?= h($val_annee) ?>
      </p>
      <a href="<?= h($pdf_url) ?>" class="btn btn-success btn-sm mt-2">
        <i class="bi bi-file-earmark-pdf me-1"></i>Ouvrir la carte
      </a>
    <?php else: ?>
      <div class="verif-icon text-danger"><i class="bi bi-x-octagon-fill"></i></div>
      <h4 class="mt-2 mb-1 text-danger">Document non vérifié</h4>
      <p class="text-muted mb-0">
        Cette carte n'a pas pu être authentifiée auprès de <?= h(APP_NOM) ?>.
        Elle peut être falsifiée, périmée ou incomplète.
      </p>
    <?php endif; ?>
  </div>
</body>
</html>
