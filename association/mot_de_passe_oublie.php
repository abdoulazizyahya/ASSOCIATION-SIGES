<?php
// association/mot_de_passe_oublie.php — récupération d'un mot de passe
// membre SANS envoi d'e-mail. Deux voies au choix (association/securite.php) :
//   • identifiant + e-mail + téléphone (concordance exacte) ;
//   • identifiant + réponses aux 2 questions secrètes.
// Rate-limité comme la connexion (login_echec).
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
require_once __DIR__ . '/_layout.php';
ecole_session_demarrer();

if (!annuaire_dispo()) { die('Annuaire association non installé.'); }
if (est_membre_association()) { header('Location: ' . APP_URL . '/association/index.php'); exit; }

$ip      = $_SERVER['REMOTE_ADDR'] ?? null;
$err = ''; $ok = false;
$etape   = 'verif';                 // 'verif' | 'nouveau'
$membre  = null;
$q_dispo = function_exists('assoc_questions_dispo') && assoc_questions_dispo();
$methode = ($_POST['methode'] ?? '') === 'questions' && $q_dispo ? 'questions' : 'coord';
$q_labels = [];                     // libellés des 2 questions (méthode questions, sous-étape 2)

// Membre vérifié en attente de son nouveau mot de passe (≤ 10 min).
if (!empty($_SESSION['recup_ok']) && ($_SESSION['recup_ok']['t'] ?? 0) > time() - 600) {
    $membre = assoc_one("SELECT * FROM membre WHERE id=? AND actif=1", [(int) $_SESSION['recup_ok']['id']]);
    if ($membre) $etape = 'nouveau';
} else {
    unset($_SESSION['recup_ok']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $op     = $_POST['op'] ?? '';
    $login  = trim($_POST['login'] ?? ($membre['login'] ?? ''));
    $bloque = assoc_login_bloque($login, $ip);

    if ($bloque > 0) {
        $err = 'Trop de tentatives. Réessayez dans ' . ceil($bloque / 60) . ' min.';

    } elseif ($op === 'nouveau' && $membre) {
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

    } elseif ($op === 'q_lookup') {
        // ── Méthode questions, sous-étape 1 : identifiant → afficher ses questions ──
        $labels = $login !== '' ? assoc_membre_questions_get(
            (int) assoc_val("SELECT id FROM membre WHERE login=? AND actif=1", [$login])
        ) : [];
        if (count($labels) === 2) {
            $q_labels = $labels;
        } else {
            assoc_login_echec_noter($login, $ip);
            $err = "Aucune question secrète n'est configurée pour cet identifiant. "
                 . "Essayez la récupération par e-mail + téléphone.";
        }

    } elseif ($op === 'q_verif') {
        // ── Méthode questions, sous-étape 2 : vérifier les réponses ──
        $m = assoc_recuperation_verifier_questions($login, $_POST['r1'] ?? '', $_POST['r2'] ?? '', $ip);
        if ($m) {
            $_SESSION['recup_ok'] = ['id' => (int) $m['id'], 't' => time()];
            $membre = $m; $etape = 'nouveau';
        } else {
            $err = "Réponses incorrectes.";
            // ré-afficher les questions pour un nouvel essai
            $labels = assoc_membre_questions_get(
                (int) assoc_val("SELECT id FROM membre WHERE login=? AND actif=1", [$login])
            );
            if (count($labels) === 2) $q_labels = $labels;
        }

    } else {
        // ── Méthode e-mail + téléphone ──
        $m = assoc_recuperation_verifier($login, $_POST['email'] ?? '', $_POST['tel'] ?? '', $ip);
        if ($m) {
            $_SESSION['recup_ok'] = ['id' => (int) $m['id'], 't' => time()];
            $membre = $m; $etape = 'nouveau';
        } elseif (!assoc_coordonnees_dispo()) {
            $err = "La récupération par e-mail / téléphone n'est pas disponible sur cette installation."
                 . ($q_dispo ? " Utilisez vos questions secrètes." : " Demandez à un superadministrateur.");
        } else {
            $err = "Identifiant, e-mail ou téléphone incorrect (ils doivent correspondre exactement "
                 . "à ceux enregistrés dans « Sécurité »).";
        }
    }
}

asso_haut('Mot de passe oublié', false);
$login_val = h($_POST['login'] ?? '');
?>
<div class="asso-card mx-auto" style="max-width:420px">
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

    <?php elseif ($q_labels): ?>
      <!-- Méthode questions — sous-étape 2 : répondre -->
      <p class="text-muted2 small mb-3">Répondez à vos 2 questions secrètes pour <strong><?= $login_val ?></strong>.</p>
      <form method="post" autocomplete="off">
        <input type="hidden" name="methode" value="questions">
        <input type="hidden" name="op" value="q_verif">
        <input type="hidden" name="login" value="<?= $login_val ?>">
        <div class="mb-3">
          <label class="form-label small"><?= h($q_labels[1]) ?></label>
          <input type="text" name="r1" class="form-control form-control-sm" required autofocus>
        </div>
        <div class="mb-4">
          <label class="form-label small"><?= h($q_labels[2]) ?></label>
          <input type="text" name="r2" class="form-control form-control-sm" required>
        </div>
        <button class="btn btn-primary btn-sm w-100 fw-bold"><i class="bi bi-key me-1"></i>Vérifier</button>
      </form>
      <div class="small mt-2"><a href="<?= APP_URL ?>/association/mot_de_passe_oublie.php" class="text-decoration-none">← Changer de méthode</a></div>

    <?php else: ?>
      <?php if ($q_dispo): ?>
      <ul class="nav nav-pills nav-fill mb-3" style="font-size:.82rem">
        <li class="nav-item"><a class="nav-link <?= $methode === 'coord' ? 'active' : '' ?>" href="#coord" onclick="pmSwitch('coord');return false;">E-mail + téléphone</a></li>
        <li class="nav-item"><a class="nav-link <?= $methode === 'questions' ? 'active' : '' ?>" href="#questions" onclick="pmSwitch('questions');return false;">Questions secrètes</a></li>
      </ul>
      <?php endif; ?>

      <div id="pm-coord" <?= $methode === 'coord' ? '' : 'hidden' ?>>
        <p class="text-muted2 small mb-3">
          Aucun e-mail n'est envoyé. Saisissez l'identifiant, l'e-mail <strong>et</strong> le téléphone
          <strong>exactement</strong> tels qu'ils sont enregistrés (page « Sécurité »).
        </p>
        <form method="post" autocomplete="off">
          <input type="hidden" name="methode" value="coord">
          <div class="mb-3">
            <label class="form-label small">Identifiant</label>
            <input type="text" name="login" class="form-control form-control-sm font-monospace" required value="<?= $login_val ?>">
          </div>
          <div class="mb-3">
            <label class="form-label small">E-mail enregistré</label>
            <input type="email" name="email" class="form-control form-control-sm" required value="<?= h($_POST['email'] ?? '') ?>">
          </div>
          <div class="mb-4">
            <label class="form-label small">Téléphone enregistré</label>
            <input type="text" name="tel" class="form-control form-control-sm" required value="<?= h($_POST['tel'] ?? '') ?>">
          </div>
          <button class="btn btn-primary btn-sm w-100 fw-bold"><i class="bi bi-key me-1"></i>Vérifier mon identité</button>
        </form>
      </div>

      <?php if ($q_dispo): ?>
      <div id="pm-questions" <?= $methode === 'questions' ? '' : 'hidden' ?>>
        <p class="text-muted2 small mb-3">Saisissez votre identifiant : vos 2 questions secrètes s'afficheront.</p>
        <form method="post" autocomplete="off">
          <input type="hidden" name="methode" value="questions">
          <input type="hidden" name="op" value="q_lookup">
          <div class="mb-4">
            <label class="form-label small">Identifiant</label>
            <input type="text" name="login" class="form-control form-control-sm font-monospace" required value="<?= $login_val ?>">
          </div>
          <button class="btn btn-primary btn-sm w-100 fw-bold"><i class="bi bi-patch-question me-1"></i>Continuer</button>
        </form>
      </div>
      <script>
        function pmSwitch(t){
          document.getElementById('pm-coord').hidden = (t!=='coord');
          document.getElementById('pm-questions').hidden = (t!=='questions');
          document.querySelectorAll('.nav-pills .nav-link').forEach(function(a){ a.classList.toggle('active', a.getAttribute('href')==='#'+t); });
        }
      </script>
      <?php endif; ?>
    <?php endif; ?>

    <hr class="border-secondary my-3">
    <a href="<?= APP_URL ?>/association/login.php" class="small text-decoration-none">← Retour à la connexion</a>
  <?php endif; ?>
</div>
<?php asso_bas();
