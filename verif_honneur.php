<?php
// verif_honneur.php — page publique (pas de exiger_connexion) : quiconque
// scanne le QR imprimé sur un certificat de tableau d'honneur (Modèle 2 —
// « Orné », seul modèle qui porte un QR) arrive ici pour vérifier son
// authenticité. Miroir de verif_bulletin.php, clé/hash séparés (voir
// pdf/verif_honneur_lib.php) — un QR de certificat ne valide jamais un
// bulletin, et inversement.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/connexion.php';
require_once __DIR__ . '/fonctions.php';
require_once __DIR__ . '/pdf/verif_lib.php';
require_once __DIR__ . '/pdf/verif_honneur_lib.php';

$id_eleve   = (int) ($_GET['e'] ?? 0);
$vue_recue  = (string) ($_GET['v'] ?? '');
$vue        = in_array($vue_recue, ['trim', 'annee'], true) ? $vue_recue : 'annee';
$id_periode = (int) ($_GET['p'] ?? 0);
$piste      = ($_GET['t'] ?? 'fr') === 'ar' ? 'ar' : 'fr';
$h_recu     = (string) ($_GET['h'] ?? '');

$eleve       = honneur_verif_valider($id_eleve, $vue, $id_periode, $piste, $h_recu);
$authentique = $eleve !== null;

// Retrouve la classe actuelle de l'élève pour reconstruire l'URL du
// certificat (le QR n'encode que élève/période/piste, pas la classe —
// comme pour les bulletins, voir verif_bulletin.php).
$pdf_url = '';
if ($authentique) {
    $annee_act = get_annee_active();
    $val_annee = $annee_act['val_annee'] ?? '';
    $insc = db_one(
        "SELECT IDClasses FROM inscrire WHERE id_eleve=? AND val_annee=? LIMIT 1",
        [$id_eleve, $val_annee]
    );
    if ($insc) {
        $fichier = 'certificat_tableau_honneur' . ($piste === 'ar' ? '_arabe' : '');
        $pdf_url = APP_URL . '/pdf/' . $fichier . '.php?classe=' . (int) $insc['IDClasses']
                 . '&eleve=' . $id_eleve . '&modele=2'
                 . ($vue === 'trim' ? '&trim=' . $id_periode : '&vue=annee')
                 . '&vh=' . urlencode($h_recu);
    } else {
        $authentique = false; // élève plus inscrit cette année — rien à ouvrir
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Vérification de certificat — <?= h(APP_NOM) ?></title>
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
      <h4 class="mt-2 mb-1 text-success">Certificat authentique</h4>
      <p class="text-muted mb-3">
        Tableau d'honneur de <strong><?= h(mb_strtoupper($eleve['Nom_elv']) . ' ' . ($eleve['Prenom_elv'] ?? '')) ?></strong><br>
        Matricule : <?= h(id_affichage_eleve($eleve)) ?>
      </p>
      <a href="<?= h($pdf_url) ?>" class="btn btn-success btn-sm">
        <i class="bi bi-file-earmark-pdf me-1"></i>Ouvrir le certificat
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
