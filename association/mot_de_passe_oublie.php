<?php
// association/mot_de_passe_oublie.php — récupération d'un mot de passe
// membre SANS envoi d'e-mail : concordance de l'identifiant + l'e-mail +
// le téléphone enregistrés sur le compte (association/securite.php).
// Rate-limité comme la connexion (login_echec).
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
require_once __DIR__ . '/_layout.php';
ecole_session_demarrer();

if (!annuaire_dispo()) { die('Annuaire association non installé.'); }
if (est_membre_association()) { header('Location: ' . APP_URL . '/association/index.php'); exit; }

$ip     = $_SERVER['REMOTE_ADDR'] ?? null;
$err = ''; $ok = false;
$etape  = 'verif';                 // 'verif' | 'nouveau'
$membre = null;

// Membre vérifié en attente de son nouveau mot de passe (≤ 10 min).
if (!empty($_SESSION['recup_ok']) && ($_SESSION['recup_ok']['t'] ?? 0) > time() - 600) {
    $membre = assoc_one("SELECT * FROM membre WHERE id=? AND actif=1", [(int) $_SESSION['recup_ok']['id']]);
    if ($membre) $etape = 'nouveau';
} else {
    unset($_SESSION['recup_ok']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login  = trim($_POST['login'] ?? ($membre['login'] ?? ''));
    $bloque = assoc_login_bloque($login, $ip);

    if ($bloque > 0) {
        $err = 'Trop de tentatives. Réessayez dans ' . ceil($bloque / 60) . ' min.';
    } elseif (($_POST['op'] ?? '') === 'nouveau' && $membre) {
        // ── Étape 2 : nouveau mot de passe ──
        $p1 = (string) ($_POST['pwd'] ?? '');
        $p2 = (string) ($_POST['pwd2'] ?? '');
        if ($p1 !== $p2) {
            $err = 'Les deux mots de passe ne correspondent pas.';
        } else {
            $r = assoc_recuperation_appliquer((int) $membre['id'], $p1, $membre['login'], $ip);
            if ($r['ok']) {
                unset($_SESSION['recup_ok']);
                journaliser_action('membre_mdp_recup', null, $membre['login']);
                $ok = true;
            } else {
                $err = $r['message'];
            }
        }
    } else {
        // ── Étape 1 : vérification identité ──
        $m = assoc_recuperation_verifier(
            $login, $_POST['email'] ?? '', $_POST['tel'] ?? '', $ip
        );
        if ($m) {
            $_SESSION['recup_ok'] = ['id' => (int) $m['id'], 't' => time()];
            $membre = $m; $etape = 'nouveau';
        } elseif (!assoc_coordonnees_dispo()) {
            $err = "La récupération par e-mail / téléphone n'est pas disponible sur cette installation. "
                 . "Demandez à un autre superadministrateur de réinitialiser votre mot de passe.";
        } else {
            $err = "Identifiant, e-mail ou téléphone incorrect (ils doivent correspondre exactement "
                 . "à ceux enregistrés dans « Sécurité »).";
        }
    }
}

asso_haut('Mot de passe oublié', false);
?>
<div class="asso-card mx-auto" style="max-width:400px">
  <?php if ($ok): ?>
    <div class="alert alert-success py-2 small">
      <i class="bi bi-check-circle me-1"></i>Mot de passe réinitialisé.
    </div>
    <a href="<?= APP_URL ?>/association/login.php" class="btn btn-primary btn-sm w-100 fw-bold">
      <i class="bi bi-box-arrow-in-right me-1"></i>Se connecter
    </a>

  <?php else: ?>
    <?php if ($err): ?><div class="alert alert-danger py-2 small"><?= h($err) ?></div><?php endif; ?>

    <?php if ($etape === 'nouveau'): ?>
      <p class="text-muted2 small mb-3">
        <i class="bi bi-check-circle text-success me-1"></i>Identité vérifiée pour
        <strong><?= h($membre['login']) ?></strong>. Choisissez un nouveau mot de passe.
      </p>
      <form method="post" autocomplete="off">
        <input type="hidden" name="op" value="nouveau">
        <div class="mb-3">
          <label class="form-label small">Nouveau mot de passe</label>
          <input type="password" name="pwd" class="form-control form-control-sm" required minlength="8" autofocus>
          <div class="form-text small text-muted2">8 caractères minimum</div>
        </div>
        <div class="mb-4">
          <label class="form-label small">Confirmer</label>
          <input type="password" name="pwd2" class="form-control form-control-sm" required minlength="8">
        </div>
        <button class="btn btn-primary btn-sm w-100 fw-bold">Enregistrer</button>
      </form>

    <?php else: ?>
      <p class="text-muted2 small mb-3">
        Aucun e-mail n'est envoyé. Saisissez l'identifiant, l'e-mail <strong>et</strong> le téléphone
        <strong>exactement</strong> tels qu'ils sont enregistrés dans votre compte (page « Sécurité »).
      </p>
      <form method="post" autocomplete="off">
        <div class="mb-3">
          <label class="form-label small">Identifiant</label>
          <input type="text" name="login" class="form-control form-control-sm font-monospace" required autofocus
                 value="<?= h($_POST['login'] ?? '') ?>">
        </div>
        <div class="mb-3">
          <label class="form-label small">E-mail enregistré</label>
          <input type="email" name="email" class="form-control form-control-sm" required
                 value="<?= h($_POST['email'] ?? '') ?>">
        </div>
        <div class="mb-4">
          <label class="form-label small">Téléphone enregistré</label>
          <input type="text" name="tel" class="form-control form-control-sm" required
                 value="<?= h($_POST['tel'] ?? '') ?>">
        </div>
        <button class="btn btn-primary btn-sm w-100 fw-bold">
          <i class="bi bi-key me-1"></i>Vérifier mon identité
        </button>
      </form>
    <?php endif; ?>

    <hr class="border-secondary my-3">
    <a href="<?= APP_URL ?>/association/login.php" class="small text-decoration-none">← Retour à la connexion</a>
  <?php endif; ?>
</div>
<?php asso_bas();
