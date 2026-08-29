<?php
// association/login.php — connexion d'un membre de l'association
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
require_once __DIR__ . '/_layout.php';
ecole_session_demarrer();

if (!annuaire_dispo()) {
    die('Annuaire association non installé (php bd/assoc/installer.php).');
}
if (est_membre_association()) {
    header('Location: ' . APP_URL . '/association/index.php'); exit;
}

$erreur = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $mdp   = (string) ($_POST['mdp'] ?? '');
    $m = $login !== '' ? assoc_one("SELECT * FROM membre WHERE login=? AND actif=1", [$login]) : null;
    if ($m && password_verify($mdp, $m['pwd_hash'])) {
        // Session « membre » — distincte de la session « user » (école).
        unset($_SESSION['user'], $_SESSION['user_id'], $_SESSION['ecole'], $_SESSION['visite_asso']);
        $_SESSION['membre'] = [
            'id' => (int) $m['id'], 'login' => $m['login'],
            'nom' => $m['nom'], 'prenom' => $m['prenom'],
        ];
        session_regenerate_id(true);
        journaliser_action('connexion_membre');
        header('Location: ' . APP_URL . '/association/index.php'); exit;
    }
    $erreur = 'Identifiant ou mot de passe incorrect.';
}

asso_haut('Espace association', false);
?>
<div class="asso-card mx-auto" style="max-width:380px">
  <p class="text-muted2 small mb-3">Connexion réservée aux membres de l'association.</p>
  <?php if ($erreur): ?>
    <div class="alert alert-danger py-2 small"><?= h($erreur) ?></div>
  <?php endif; ?>
  <form method="post" autocomplete="off">
    <div class="mb-3">
      <label class="form-label small">Identifiant</label>
      <input type="text" name="login" class="form-control form-control-sm" required autofocus>
    </div>
    <div class="mb-4">
      <label class="form-label small">Mot de passe</label>
      <input type="password" name="mdp" class="form-control form-control-sm" required>
    </div>
    <button class="btn btn-primary btn-sm w-100 fw-bold">
      <i class="bi bi-box-arrow-in-right me-1"></i>Se connecter
    </button>
  </form>
  <hr class="border-secondary my-3">
  <a href="<?= APP_URL ?>/login.php" class="small text-decoration-none">← Connexion établissement</a>
</div>
<?php asso_bas();
