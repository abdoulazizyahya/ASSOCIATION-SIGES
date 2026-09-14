<?php
// association/login.php — connexion d'un membre de l'association.
// Limitation de débit (login_echec) + double authentification TOTP
// optionnelle (membre.totp_actif) — voir bd/lib/totp.php.
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

$ip     = $_SERVER['REMOTE_ADDR'] ?? null;
$erreur = '';
$etape  = 'mdp';   // 'mdp' | '2fa'

// Membre en attente de son code 2FA (mot de passe déjà validé, ≤ 5 min).
$pending = null;
if (!empty($_SESSION['membre_2fa_pending'])
    && ($_SESSION['membre_2fa_pending']['t'] ?? 0) > time() - 300) {
    $pending = assoc_one("SELECT * FROM membre WHERE id=? AND actif=1",
        [(int) $_SESSION['membre_2fa_pending']['id']]);
    // 2FA désactivée entre-temps (par le membre lui-même ou un superadmin) :
    // on ne laisse pas l'utilisateur coincé sur l'étape « code », on le
    // connecte directement.
    if ($pending && (!assoc_2fa_disponible() || (int) ($pending['totp_actif'] ?? 0) !== 1 || empty($pending['totp_secret']))) {
        _membre_connecter($pending);   // exit
    }
    if ($pending) $etape = '2fa';
} else {
    unset($_SESSION['membre_2fa_pending']);
}

function _membre_connecter(array $m): void {
    unset($_SESSION['user'], $_SESSION['user_id'], $_SESSION['ecole'], $_SESSION['visite_asso'],
          $_SESSION['membre_2fa_pending'], $_SESSION['forcer_2fa']);
    $_SESSION['membre'] = [
        'id' => (int) $m['id'], 'login' => $m['login'],
        'nom' => $m['nom'], 'prenom' => $m['prenom'],
    ];
    session_regenerate_id(true);
    require_once __DIR__ . '/../bd/lib/audit.php';
    audit_log('connexion');

    // 2FA obligatoire pour les superadmins : si elle n'est pas encore active,
    // on force la configuration (exiger_membre_association() redirige vers
    // securite.php tant que ce drapeau est là). Désactivable via
    // define('ASSOC_2FA_SUPERADMIN_OBLIGATOIRE', false) dans config.local.php.
    $oblig = !defined('ASSOC_2FA_SUPERADMIN_OBLIGATOIRE') || ASSOC_2FA_SUPERADMIN_OBLIGATOIRE;
    if ($oblig && assoc_2fa_disponible() && (int) ($m['totp_actif'] ?? 0) !== 1 && est_superadmin_association()) {
        $_SESSION['forcer_2fa'] = 1;
        header('Location: ' . APP_URL . '/association/securite.php');
        exit;
    }

    header('Location: ' . APP_URL . '/association/index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bloque = assoc_login_bloque(trim($_POST['login'] ?? ($pending['login'] ?? '')), $ip);
    if ($bloque > 0) {
        $erreur = 'Trop de tentatives échouées. Réessayez dans ' . ceil($bloque / 60) . ' min.';
    } elseif (($_POST['op'] ?? '') === '2fa' && $pending) {
        // ── Étape 2 : vérification du code TOTP ──
        require_once __DIR__ . '/../bd/lib/totp.php';
        $code = trim($_POST['code'] ?? '');
        if ($pending['totp_secret'] && totp_verifier($pending['totp_secret'], $code)) {
            assoc_login_echec_reset($pending['login'], $ip);
            _membre_connecter($pending);
        }
        assoc_login_echec_noter($pending['login'], $ip);
        require_once __DIR__ . '/../bd/lib/audit.php';
        audit_log('connexion_echec', ['login' => $pending['login'], 'cible' => 'code 2FA invalide']);
        $erreur = 'Code de vérification incorrect.';
    } else {
        // ── Étape 1 : identifiant + mot de passe ──
        $login = trim($_POST['login'] ?? '');
        $mdp   = (string) ($_POST['mdp'] ?? '');
        $m = $login !== '' ? assoc_one("SELECT * FROM membre WHERE login=? AND actif=1", [$login]) : null;
        if ($m && password_verify($mdp, $m['pwd_hash'])) {
            if (assoc_2fa_disponible() && (int) ($m['totp_actif'] ?? 0) === 1 && $m['totp_secret']) {
                $_SESSION['membre_2fa_pending'] = ['id' => (int) $m['id'], 't' => time()];
                $pending = $m; $etape = '2fa';
            } else {
                assoc_login_echec_reset($login, $ip);
                _membre_connecter($m);
            }
        } else {
            assoc_login_echec_noter($login, $ip);
            require_once __DIR__ . '/../bd/lib/audit.php';
            // Message affiché volontairement générique (anti-énumération) —
            // le journal d'audit, réservé aux superadmins, peut se permettre
            // la raison précise (demande explicite du 15/09/2026).
            audit_log('connexion_echec', ['login' => $login,
                'cible' => $m ? 'mot de passe incorrect' : 'identifiant incorrect']);
            $erreur = 'Identifiant ou mot de passe incorrect.';
        }
    }
}

asso_haut('Espace association', false);
?>
<div class="asso-card mx-auto" style="max-width:380px">
  <?php if ($erreur): ?>
    <div class="alert alert-danger py-2 small"><?= h($erreur) ?></div>
  <?php endif; ?>

  <?php if ($etape === '2fa'): ?>
    <p class="text-muted2 small mb-3">
      <i class="bi bi-shield-lock me-1"></i>Saisissez le code à 6 chiffres de votre application
      d'authentification pour <strong><?= h($pending['login']) ?></strong>.
    </p>
    <form method="post" autocomplete="off">
      <input type="hidden" name="op" value="2fa">
      <div class="mb-4">
        <label class="form-label small">Code de vérification</label>
        <input type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus
               class="form-control form-control-lg text-center font-monospace" style="letter-spacing:.4em">
      </div>
      <button class="btn btn-primary btn-sm w-100 fw-bold">
        <i class="bi bi-check2 me-1"></i>Vérifier
      </button>
    </form>
    <hr class="border-secondary my-3">
    <a href="<?= APP_URL ?>/association/login.php" class="small text-decoration-none">← Recommencer</a>
  <?php else: ?>
    <p class="text-muted2 small mb-3">Connexion réservée aux membres de l'association.</p>
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
    <div class="text-center mt-2">
      <a href="<?= APP_URL ?>/association/mot_de_passe_oublie.php" class="small text-decoration-none">Mot de passe oublié ?</a>
    </div>
    <hr class="border-secondary my-3">
    <a href="<?= APP_URL ?>/login.php" class="small text-decoration-none">← Connexion établissement</a>
  <?php endif; ?>
</div>
<?php asso_bas();
