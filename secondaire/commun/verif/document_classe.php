<?php
// secondaire/commun/verif/document_classe.php — page PUBLIQUE (sans
// connexion) : vérification du QR imprimé sur un document de classe du
// secondaire. Porté d'ABZ_MBE (verif_fiche_presence.php +
// verif_releve_vierge.php, réunies ici).
//   t=fiche   fiche de présence vierge   (secondaire/commun/pages/discipline/pdf_fiche_presence.php)
//   t=releve  relevé de notes vierge     (secondaire/technique/pages/notes/pdf_releve_vierge.php)
// Paramètres : c = id classe, a = id année, h = signature, ec = code école.
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../noyau/connexion.php';
require_once __DIR__ . '/../../../noyau/fonctions.php';
require_once __DIR__ . '/../pdf/verif_fiche_presence_lib.php';
require_once __DIR__ . '/../pdf/verif_releve_vierge_lib.php';
verif_exiger_ecole_publique();  // multi-école : URL sans &ec= -> page neutre

$type      = ($_GET['t'] ?? '') === 'releve' ? 'releve' : 'fiche';
$id_classe = (int) ($_GET['c'] ?? 0);
$id_annee  = (int) ($_GET['a'] ?? 0);
$h_recu    = (string) ($_GET['h'] ?? '');

$docs = [
    'fiche'  => ['titre' => 'Fiche de présence',   'hash' => 'fiche_presence_verif_hash',
                 'pdf'   => '/secondaire/commun/pages/discipline/pdf_fiche_presence.php'],
    'releve' => ['titre' => 'Relevé de notes vierge', 'hash' => 'releve_vierge_verif_hash',
                 'pdf'   => '/secondaire/technique/pages/notes/pdf_releve_vierge.php'],
];
$doc = $docs[$type];

$classe = null; $annee = null;
try {
    $classe = $id_classe ? db_one("SELECT * FROM classe WHERE id=?", [$id_classe]) : null;
    $annee  = $id_annee ? db_one("SELECT * FROM annee_scolaire WHERE id=?", [$id_annee]) : null;
} catch (\Throwable $e) { /* école non résolue */ }
$authentique = $classe && $annee && $h_recu !== '' && hash_equals(($doc['hash'])($id_classe, $id_annee), $h_recu);

// Document réel, ouvert seulement sur clic (jeton vh = hash déjà vérifié).
$pdf_url = $authentique
    ? verif_ajout_ec(APP_URL . $doc['pdf'] . '?classe=' . $id_classe . '&va=' . $id_annee . '&vh=' . urlencode($h_recu))
    : '';
$etab = $authentique ? get_etablissement() : [];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Vérification — <?= h($doc['titre']) ?> — <?= h(APP_NOM) ?></title>
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
        <?php if (!empty($etab['nom_fr'])): ?><strong><?= h($etab['nom_fr']) ?></strong><br><?php endif; ?>
        <?= h($doc['titre']) ?> — classe <strong><?= h($classe['designation']) ?></strong><br>
        Année scolaire <?= h($annee['libelle']) ?>
      </p>
      <a href="<?= h($pdf_url) ?>" class="btn btn-success btn-sm">
        <i class="bi bi-file-earmark-pdf me-1"></i>Ouvrir le document
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
