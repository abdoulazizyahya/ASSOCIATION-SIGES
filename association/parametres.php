<?php
// association/parametres.php — Réglages du site (18/10/2026).
//   • Page d'entrée : à l'ouverture du site (racine), afficher la page d'accueil
//     de l'association (vitrine) ou directement la page de connexion.
// Réglages stockés dans parametre_assoc (assoc_param / assoc_param_set,
// noyau/connexion_assoc.php) ; table créée au premier enregistrement.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../noyau/connexion.php';
require_once __DIR__ . '/../noyau/fonctions.php';
require_once __DIR__ . '/_layout.php';
exiger_superadmin_association();

$msg = ''; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $page = post('page_entree') === 'connexion' ? 'connexion' : 'accueil';
    try {
        assoc_param_set('page_entree', $page, (string) (membre_connecte()['login'] ?? ''));
        journaliser_action('reglage_page_entree', null, $page);
        $msg = $page === 'connexion'
            ? 'Enregistré : le site s\'ouvre maintenant directement sur la page de connexion.'
            : 'Enregistré : le site s\'ouvre maintenant sur la page d\'accueil de l\'association.';
    } catch (\Throwable $e) {
        $err = 'Enregistrement impossible : ' . $e->getMessage();
    }
}
$page = page_entree_site();

asso_haut('Réglages du site');
?>
<?php if ($msg): ?><div class="alert alert-success py-2 small"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-warning py-2 small"><?= h($err) ?></div><?php endif; ?>

<div class="asso-card mb-3" style="max-width:720px">
  <h6 class="mb-1"><i class="bi bi-door-open me-1"></i>Page d'entrée du site</h6>
  <p class="small text-muted2 mb-3">Ce que voit un visiteur non connecté qui ouvre l'adresse du site
    (<span class="font-monospace"><?= h(APP_URL ?: '/') ?>/</span>). Une personne déjà connectée arrive toujours sur son tableau de bord.</p>
  <form method="post">
    <?= csrf_champ() ?>
    <div class="form-check mb-2">
      <input class="form-check-input" type="radio" name="page_entree" id="pe_accueil" value="accueil" <?= $page === 'accueil' ? 'checked' : '' ?>>
      <label class="form-check-label" for="pe_accueil">
        <strong>Page d'accueil de l'association</strong>
        <span class="d-block small text-muted2">Vitrine publique (présentation, écoles) avec le bouton « Se connecter ».</span>
      </label>
    </div>
    <div class="form-check mb-3">
      <input class="form-check-input" type="radio" name="page_entree" id="pe_connexion" value="connexion" <?= $page === 'connexion' ? 'checked' : '' ?>>
      <label class="form-check-label" for="pe_connexion">
        <strong>Page de connexion directement</strong>
        <span class="d-block small text-muted2">Le site s'ouvre sur le formulaire de connexion. La vitrine reste consultable à l'adresse
          <a href="<?= APP_URL ?>/?accueil" target="_blank" class="font-monospace"><?= h(APP_URL) ?>/?accueil</a>.</span>
      </label>
    </div>
    <button class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Enregistrer</button>
    <a href="<?= APP_URL ?>/" target="_blank" class="btn btn-outline-secondary btn-sm ms-1"><i class="bi bi-box-arrow-up-right me-1"></i>Tester l'ouverture du site</a>
  </form>
</div>
<?php asso_bas(); ?>
