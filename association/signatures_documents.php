<?php
// association/signatures_documents.php — le PROPRIÉTAIRE décide quels
// documents portent la signature numérique du chef d'établissement, pour
// toutes les écoles (24/10/2026). La personne qui imprime ne choisit plus :
// voir SIGNATURE_CATEGORIES / signature_politique() dans noyau/fonctions.php.
// Elle peut seulement ajuster la position de la signature.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../noyau/connexion.php';
require_once __DIR__ . '/../noyau/fonctions.php';
require_once __DIR__ . '/_layout.php';
exiger_proprietaire_association();

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $choix = [];
    foreach (array_keys(SIGNATURE_CATEGORIES) as $cat) $choix[$cat] = isset($_POST['sig'][$cat]) ? 1 : 0;
    $m = membre_connecte();
    assoc_param_set('signature_documents', json_encode($choix), $m['login'] ?? null);
    journaliser_action('signature_documents', null, implode(', ', array_keys(array_filter($choix))) ?: 'aucun document');
    $msg = 'Choix enregistré : il s\'applique immédiatement à toutes les écoles.';
}
$politique = json_decode((string) assoc_param('signature_documents', ''), true);
$enregistre = is_array($politique);
$politique = $enregistre ? $politique : SIGNATURE_DEFAUTS;

asso_haut('Signature sur les documents');
?>
<?php if ($msg): ?><div class="alert alert-success py-2 small"><?= h($msg) ?></div><?php endif; ?>

<div class="asso-card" style="max-width:720px">
  <h6 class="mb-1"><i class="bi bi-vector-pen me-1"></i>Documents signés numériquement</h6>
  <p class="small text-muted2 mb-3">
    Cochez les documents qui portent la <strong>signature numérique du chef d'établissement</strong> (Directeur, Proviseur ou Principal),
    dans toutes les écoles. La personne qui imprime ne peut plus l'ajouter ni la retirer : elle peut seulement
    <strong>ajuster sa position</strong>. Une école sans signature enregistrée imprime sans signature.
    <?php if (!$enregistre): ?><br><em>Aucun choix enregistré pour l'instant : valeurs par défaut affichées.</em><?php endif; ?>
  </p>
  <form method="post">
    <?= csrf_champ() ?>
    <table class="table table-sm align-middle mb-3" style="font-size:.86rem">
      <tbody>
        <?php foreach (SIGNATURE_CATEGORIES as $cat => [$libelle]): ?>
          <tr>
            <td style="width:44px">
              <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" role="switch" name="sig[<?= h($cat) ?>]" id="sig_<?= h($cat) ?>"
                       <?= !empty($politique[$cat]) ? 'checked' : '' ?>>
              </div>
            </td>
            <td><label for="sig_<?= h($cat) ?>" class="mb-0"><?= h($libelle) ?></label></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p class="small text-muted2">Les documents du personnel (attestation de présence, prise de service) ne sont pas concernés :
      ils suivent le circuit de validation réglé dans Paramètres → Documents enseignants.</p>
    <button class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Enregistrer</button>
  </form>
</div>
<?php asso_bas(); ?>
