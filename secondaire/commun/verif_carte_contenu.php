<?php
// secondaire/commun/verif_carte_contenu.php — vérification publique d'une
// carte scolaire d'école SECONDAIRE (QR imprimé par secondaire/commun/pdf/
// cartes.php). Inclus par verif/carte.php (racine) une fois l'école résolue
// et reconnue comme secondaire — même principe que verif_bulletin_contenu.php.
// Paramètres : e = id élève, a = id de l'année scolaire, h = signature.
require_once __DIR__ . '/pdf/verif_carte_lib.php';

$id_eleve = (int) ($_GET['e'] ?? 0);
$id_annee = (int) ($_GET['a'] ?? 0);
$h_recu   = (string) ($_GET['h'] ?? '');

try { $eleve = carte_sec_verif_valider($id_eleve, $id_annee, $h_recu); } catch (\Throwable $e) { $eleve = null; }
$authentique = $eleve !== null;
$etab = $authentique ? get_etablissement() : [];

// Carte réelle, ouverte seulement sur clic (jeton vh = hash déjà vérifié :
// accès public à CETTE carte, voir cartes.php).
$pdf_url = $authentique
    ? verif_ajout_ec(APP_URL . '/secondaire/commun/pdf/cartes.php?eleve=' . $id_eleve . '&annee=' . $id_annee . '&vh=' . urlencode($h_recu))
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
      <h4 class="mt-2 mb-1 text-success">Carte authentique</h4>
      <p class="text-muted mb-3">
        <?php if (!empty($etab['nom_fr'])): ?><strong><?= h($etab['nom_fr']) ?></strong><br><?php endif; ?>
        <strong><?= h(mb_strtoupper($eleve['nom']) . ' ' . ($eleve['prenom'] ?? '')) ?></strong><br>
        <?= niu_eleve($eleve) !== '' ? 'NIU ' . h(niu_eleve($eleve)) . '<br>' : '' ?>
        Classe : <?= h($eleve['classe']) ?> — Année scolaire <?= h($eleve['annee']) ?>
      </p>
      <a href="<?= h($pdf_url) ?>" class="btn btn-success btn-sm">
        <i class="bi bi-person-badge me-1"></i>Ouvrir la carte
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
