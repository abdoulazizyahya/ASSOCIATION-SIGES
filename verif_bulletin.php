<?php
// verif_bulletin.php — page publique (pas de exiger_connexion) : quiconque
// scanne le QR imprimé sur un bulletin arrive ici pour vérifier son
// authenticité.
//
// 🐛 CORRIGÉ (repris tel quel d'ABZ_MBE, jamais adapté au schéma
// jaynitaare_v2 jusqu'ici — la vérification échouait donc TOUJOURS) :
// `eleve.id` → `eleve.id_eleve`, `$eleve['nom']/['prenom']` →
// `Nom_elv`/`Prenom_elv`, et l'URL du bulletin pointait vers
// `pages/bulletins/pdf*.php` (chemins ABZ_MBE) au lieu de
// `pdf/bulletin_annuel.php`/`pdf/bulletin_trimestriel.php` (les vrais
// fichiers ici, FR et AR). Ajout du paramètre `t` (piste fr/ar, absent du
// modèle ABZ_MBE à une seule piste — voir pdf/verif_lib.php).
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/connexion.php';
require_once __DIR__ . '/fonctions.php';
require_once __DIR__ . '/pdf/verif_lib.php';

$id_eleve   = (int) ($_GET['e'] ?? 0);
$vue_recue  = (string) ($_GET['v'] ?? '');
$vue        = in_array($vue_recue, ['trim', 'annee'], true) ? $vue_recue : 'annee';
$id_periode = (int) ($_GET['p'] ?? 0);
$piste      = ($_GET['t'] ?? 'fr') === 'ar' ? 'ar' : 'fr';
$h_recu     = (string) ($_GET['h'] ?? '');

$eleve       = bulletin_verif_valider($id_eleve, $vue, $id_periode, $piste, $h_recu);
$authentique = $eleve !== null;

// URL du bulletin réel, ouverte uniquement si l'utilisateur clique sur le
// bouton "Ouvrir le bulletin" (jamais automatiquement). Le jeton "vh" (= le
// hash déjà vérifié ci-dessus) permet aux 4 générateurs de bulletin
// (pdf/bulletin_{annuel,trimestriel}{,_arabe}.php) de servir CE bulletin
// précis sans exiger de connexion — voir leur en-tête, même mécanisme.
$pdf_url = '';
if ($authentique) {
    $fichier = 'bulletin_' . ($vue === 'annee' ? 'annuel' : 'trimestriel') . ($piste === 'ar' ? '_arabe' : '');
    $pdf_url = APP_URL . '/pdf/' . $fichier . '.php?id=' . $id_eleve
             . ($vue === 'trim' ? '&trim=' . $id_periode : '')
             . '&vh=' . urlencode($h_recu);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Vérification de bulletin — <?= h(APP_NOM) ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
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
        Bulletin de <strong><?= h(mb_strtoupper($eleve['Nom_elv']) . ' ' . ($eleve['Prenom_elv'] ?? '')) ?></strong><br>
        Matricule : <?= h(id_affichage_eleve($eleve)) ?>
      </p>
      <a href="<?= h($pdf_url) ?>" class="btn btn-success btn-sm">
        <i class="bi bi-file-earmark-pdf me-1"></i>Ouvrir le bulletin
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
