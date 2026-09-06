<?php
// association/securite.php — le membre connecté gère sa double
// authentification (TOTP). Personnel : chacun ne configure que son
// propre compte. La désactivation par un tiers (perte du téléphone)
// se fait par un superadmin depuis membres/voir.php.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../bd/lib/totp.php';
exiger_membre_association();

if (!assoc_2fa_disponible()) {
    asso_haut('Sécurité');
    echo '<div class="asso-card text-muted2">Annuaire non à jour : lancez <span class="font-monospace">php bd/assoc/maj_assoc.php</span> pour activer la double authentification.</div>';
    asso_bas(); exit;
}

$moi = (int) (membre_connecte()['login'] ? assoc_val("SELECT id FROM membre WHERE login=?", [membre_connecte()['login']]) : 0);
$m   = assoc_one("SELECT * FROM membre WHERE id=?", [$moi]);
if (!$m) { asso_haut('Sécurité'); asso_bas(); exit; }

$msg = ''; $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $op = $_POST['op'] ?? '';

    if ($op === 'activer') {
        $secret = preg_replace('/[^A-Z2-7]/', '', strtoupper($_POST['secret'] ?? ''));
        $r = ($secret !== '')
            ? assoc_membre_2fa_activer($moi, $secret, trim($_POST['code'] ?? ''))
            : ['ok' => false, 'message' => 'Secret manquant, recommencez.'];
        if ($r['ok']) {
            unset($_SESSION['secu_secret'], $_SESSION['forcer_2fa']);
            $msg = $r['message'];
        } else {
            $err = $r['message'];
        }
        $m = assoc_one("SELECT * FROM membre WHERE id=?", [$moi]);

    } elseif ($op === 'desactiver') {
        if ($m['totp_secret'] && totp_verifier($m['totp_secret'], trim($_POST['code'] ?? ''))) {
            assoc_membre_2fa_desactiver($moi);
            journaliser_action('membre_2fa_off', null, $m['login']);
            $msg = 'Double authentification désactivée.';
            $m = assoc_one("SELECT * FROM membre WHERE id=?", [$moi]);
        } else {
            $err = 'Code incorrect — la double authentification reste active.';
        }
    }
    if ($op === 'activer' && $msg) journaliser_action('membre_2fa_on', null, $m['login']);
}

$actif = (int) $m['totp_actif'] === 1;

// Secret proposé (nouveau à chaque affichage du formulaire d'activation,
// mémorisé en session tant qu'il n'est pas confirmé).
if (!$actif) {
    if (empty($_SESSION['secu_secret'])) $_SESSION['secu_secret'] = totp_secret_nouveau();
    $secret = $_SESSION['secu_secret'];
    $issuer = 'SIGES Association';
    $uri    = totp_uri($secret, $m['login'], $issuer);
}

asso_haut('Sécurité — double authentification');
$csrf = csrf_generer();
?>
<div class="asso-card" style="max-width:560px">
  <?php if (!empty($_SESSION['forcer_2fa'])): ?>
    <div class="alert alert-warning py-2 small">
      <i class="bi bi-shield-exclamation me-1"></i>
      La double authentification est <strong>obligatoire</strong> pour les superadministrateurs.
      Configurez-la ci-dessous pour accéder au portail.
    </div>
  <?php endif; ?>
  <?php if ($msg): ?><div class="alert alert-success py-2 small"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-warning py-2 small"><?= h($err) ?></div><?php endif; ?>

  <?php if ($actif): ?>
    <div class="d-flex align-items-center gap-2 mb-3">
      <i class="bi bi-shield-check text-success fs-4"></i>
      <div>
        <div class="fw-bold">Double authentification active</div>
        <div class="small text-muted2">Un code est demandé à chaque connexion de « <?= h($m['login']) ?> ».</div>
      </div>
    </div>
    <form method="post" class="d-flex gap-2 align-items-center"
          onsubmit="return confirm('Désactiver la double authentification ?');">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="op" value="desactiver">
      <input name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required
             class="form-control form-control-sm font-monospace" style="max-width:130px" placeholder="Code actuel">
      <button class="btn btn-outline-danger btn-sm">Désactiver</button>
    </form>

  <?php else: ?>
    <div class="fw-bold mb-2"><i class="bi bi-shield-lock me-1"></i>Activer la double authentification</div>
    <ol class="small text-muted2">
      <li>Installez une application d'authentification (Google Authenticator, Authy, FreeOTP…).</li>
      <li>Ajoutez un compte en scannant un QR code <em>ou</em> en saisissant la clé ci-dessous.</li>
      <li>Entrez le code à 6 chiffres affiché par l'application pour confirmer.</li>
    </ol>

    <div class="mb-2">
      <div class="small text-muted2">Clé de configuration (saisie manuelle)</div>
      <div class="font-monospace fs-6" style="letter-spacing:.15em;word-break:break-all"><?= h(chunk_split($secret, 4, ' ')) ?></div>
    </div>
    <div class="mb-3">
      <div class="small text-muted2">Ou lien <span class="font-monospace">otpauth://</span> (copier dans l'app si elle l'accepte)</div>
      <div class="font-monospace small text-break"><?= h($uri) ?></div>
    </div>

    <form method="post" class="d-flex gap-2 align-items-center">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="op" value="activer">
      <input type="hidden" name="secret" value="<?= h($secret) ?>">
      <input name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus
             class="form-control form-control-sm font-monospace" style="max-width:130px" placeholder="Code à 6 chiffres">
      <button class="btn btn-primary btn-sm">Activer</button>
    </form>
  <?php endif; ?>
</div>
<?php asso_bas();
