<?php
// secondaire/verif_bulletin_contenu.php — vérification publique d'un
// bulletin d'école SECONDAIRE (QR imprimé par secondaire/pages/bulletins/
// pdf.php). Inclus par verif_bulletin.php (racine) une fois l'école
// résolue (&ec=CODE) et reconnue comme secondaire — même principe que
// secondaire/dashboard_contenu.php. Avant le 03/10/2026, ces QR arrivaient
// sur la page du primaire (tables différentes) sans code d'école :
// « Établissement non précisé ».
// Paramètres : e = id élève, v = 'trim' | 'seq', p = id du trimestre ou de
// la séquence, h = signature (secondaire/pdf/verif_lib.php).
require_once __DIR__ . '/pdf/verif_lib.php';

$id_eleve   = (int) ($_GET['e'] ?? 0);
$vue        = ($_GET['v'] ?? '') === 'seq' ? 'seq' : 'trim';
$id_periode = (int) ($_GET['p'] ?? 0);
$h_recu     = (string) ($_GET['h'] ?? '');

$eleve = $id_eleve ? db_one("SELECT * FROM eleve WHERE id=?", [$id_eleve]) : null;
$authentique = $eleve && $id_periode > 0 && $h_recu !== ''
    && hash_equals(bulletin_verif_hash($id_eleve, $vue, $id_periode, id_affichage_eleve($eleve)), $h_recu);

// Période et année du bulletin (le générateur a besoin de l'année).
$libelle_periode = '';
$pdf_url = '';
if ($authentique) {
    if ($vue === 'seq') {
        $per = db_one("SELECT s.libelle, s.id_trim, t.id_annee FROM sequence s JOIN trimestre t ON t.id = s.id_trim WHERE s.id=?", [$id_periode]);
        $q = ['eleve' => $id_eleve, 'trim' => (int) ($per['id_trim'] ?? 0), 'seq' => $id_periode];
    } else {
        $per = db_one("SELECT libelle, id_annee FROM trimestre WHERE id=?", [$id_periode]);
        $q = ['eleve' => $id_eleve, 'trim' => $id_periode];
    }
    $libelle_periode = (string) ($per['libelle'] ?? '');
    $q['annee'] = (int) ($per['id_annee'] ?? 0);
    $q['vh']    = $h_recu;
    // Le générateur reconnaît le jeton « vh » et sert CE bulletin sans
    // connexion (secondaire/pages/bulletins/pdf.php, en-tête).
    $pdf_url = verif_ajout_ec(APP_URL . '/secondaire/pages/bulletins/pdf.php?' . http_build_query($q));
}
$etab = get_etablissement();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Vérification de bulletin — <?= h($etab['nom_fr'] ?? APP_NOM) ?></title>
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
        Bulletin de <strong><?= h(mb_strtoupper((string) $eleve['nom']) . ' ' . ($eleve['prenom'] ?? '')) ?></strong><br>
        Matricule : <?= h(id_affichage_eleve($eleve)) ?>
        <?php if ($libelle_periode !== ''): ?><br>Période : <?= h($libelle_periode) ?><?php endif; ?>
        <br><small><?= h($etab['nom_fr'] ?? '') ?></small>
      </p>
      <a href="<?= h($pdf_url) ?>" class="btn btn-success btn-sm">
        <i class="bi bi-file-earmark-pdf me-1"></i>Ouvrir le bulletin
      </a>
    <?php else: ?>
      <div class="verif-icon text-danger"><i class="bi bi-x-octagon-fill"></i></div>
      <h4 class="mt-2 mb-1 text-danger">Document non vérifié</h4>
      <p class="text-muted mb-0">
        Ce document n'a pas pu être authentifié auprès de <?= h($etab['nom_fr'] ?? APP_NOM) ?>.
        Il peut être falsifié, périmé ou incomplet.
      </p>
    <?php endif; ?>
  </div>
</body>
</html>
