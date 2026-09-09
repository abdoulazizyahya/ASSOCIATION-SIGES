<?php
// association/securite.php — le membre connecté gère son compte :
//   • ses coordonnées (e-mail + téléphone) — servent à récupérer un mot de
//     passe oublié (association/mot_de_passe_oublie.php) ;
//   • sa double authentification TOTP (facultative — activation volontaire).
// La désactivation de la 2FA par un tiers (perte du téléphone) se fait par
// un superadmin depuis membres/voir.php.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../bd/lib/totp.php';
exiger_membre_association();

$fa_dispo   = assoc_2fa_disponible();
$coord_dispo = function_exists('assoc_coordonnees_dispo') && assoc_coordonnees_dispo();

$moi = (int) (membre_connecte()['login'] ? assoc_val("SELECT id FROM membre WHERE login=?", [membre_connecte()['login']]) : 0);
$m   = assoc_one("SELECT * FROM membre WHERE id=?", [$moi]);
if (!$m) { asso_haut('Sécurité'); asso_bas(); exit; }

$msg = ''; $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $op = $_POST['op'] ?? '';

    if ($op === 'coordonnees') {
        $r = assoc_membre_coordonnees_maj($moi, $_POST['email'] ?? '', $_POST['tel'] ?? '');
        $msg = $r['ok'] ? $r['message'] : ''; $err = $r['ok'] ? '' : $r['message'];
        if ($r['ok']) journaliser_action('membre_coordonnees', null, $m['login']);
        $m = assoc_one("SELECT * FROM membre WHERE id=?", [$moi]);

    } elseif ($op === 'activer') {
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

$actif = $fa_dispo && (int) ($m['totp_actif'] ?? 0) === 1;

// Secret proposé (nouveau à chaque affichage du formulaire d'activation,
// mémorisé en session tant qu'il n'est pas confirmé).
if ($fa_dispo && !$actif) {
    if (empty($_SESSION['secu_secret'])) $_SESSION['secu_secret'] = totp_secret_nouveau();
    $secret = $_SESSION['secu_secret'];
    $issuer = (defined('ASSOC_NOM') && ASSOC_NOM !== '') ? ASSOC_NOM
            : (defined('APP_NOM') ? APP_NOM : 'SIGES');
    $uri    = totp_uri($secret, $m['login'], $issuer);
    // QR : générateur PNG maison (pdf/qrcode.php), déjà utilisé par les
    // bulletins — pas de dépendance externe.
    $qr_src = APP_URL . '/pdf/qrcode.php?s=6&e=M&d=' . urlencode($uri);
}

asso_haut('Sécurité — mon compte');
$csrf = csrf_generer();
?>
<?php if ($msg): ?><div class="alert alert-success py-2 small" style="max-width:560px"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-warning py-2 small" style="max-width:560px"><?= h($err) ?></div><?php endif; ?>

<!-- ── Coordonnées ─────────────────────────────────────────────── -->
<div class="asso-card mb-3" style="max-width:560px">
  <div class="fw-bold mb-1"><i class="bi bi-person-vcard me-1"></i>Mes coordonnées</div>
  <div class="small text-muted2 mb-3">
    Servent à récupérer votre mot de passe si vous l'oubliez
    (<a href="<?= APP_URL ?>/association/mot_de_passe_oublie.php">page « mot de passe oublié »</a>) :
    il faudra fournir <strong>exactement</strong> l'e-mail <em>et</em> le téléphone enregistrés ici.
    Gardez-les à jour.
  </div>
  <form method="post" class="row g-2">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="op" value="coordonnees">
    <div class="col-12">
      <label class="form-label small">Adresse e-mail</label>
      <input type="email" name="email" class="form-control form-control-sm" value="<?= h($m['email'] ?? '') ?>"
             placeholder="vous@exemple.cm">
    </div>
    <div class="col-12">
      <label class="form-label small">Téléphone</label>
      <input type="text" name="tel" class="form-control form-control-sm" value="<?= h($m['tel'] ?? '') ?>"
             placeholder="+237 6XX XX XX XX" <?= $coord_dispo ? '' : 'disabled' ?>>
      <?php if (!$coord_dispo): ?>
        <div class="form-text small text-warning">Annuaire non à jour : lancez <span class="font-monospace">php bd/assoc/maj_assoc.php</span> pour activer le champ téléphone.</div>
      <?php endif; ?>
    </div>
    <div class="col-12 mt-1"><button class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Enregistrer</button></div>
  </form>
</div>

<?php if ($fa_dispo): ?>
<!-- ── Double authentification (facultative) ───────────────────── -->
<div class="asso-card" style="max-width:560px">
  <?php if (!empty($_SESSION['forcer_2fa'])): ?>
    <div class="alert alert-warning py-2 small">
      <i class="bi bi-shield-exclamation me-1"></i>
      La double authentification est <strong>obligatoire</strong> pour les superadministrateurs.
      Configurez-la ci-dessous pour accéder au portail.
    </div>
  <?php endif; ?>

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
    <div class="fw-bold mb-3"><i class="bi bi-shield-lock me-1"></i>Activer la double authentification <span class="text-muted2 small">(facultatif)</span></div>

    <!-- Étape 1 -->
    <div class="mb-3">
      <div class="fw-bold small mb-1"><span class="badge bg-secondary me-1">1</span>Installez une application d'authentification</div>
      <div class="small text-muted2">Google&nbsp;Authenticator, Microsoft&nbsp;Authenticator, Authy, FreeOTP… sur votre téléphone.</div>
    </div>

    <!-- Étape 2 : QR + clé -->
    <div class="mb-3">
      <div class="fw-bold small mb-2"><span class="badge bg-secondary me-1">2</span>Ajoutez le compte dans l'application</div>
      <div class="d-flex flex-wrap gap-3 align-items-start">
        <div style="flex:0 0 auto">
          <img src="<?= h($qr_src) ?>" alt="QR code de configuration" width="180" height="180"
               style="border:1px solid var(--border);border-radius:8px;background:#fff;padding:6px;display:block">
          <div class="text-center small text-muted2 mt-1">Scannez ce QR&nbsp;code</div>
        </div>
        <div style="flex:1 1 220px;min-width:220px">
          <div class="small text-muted2">…ou saisissez la clé à la main :</div>
          <div class="font-monospace fs-6 mb-2" style="letter-spacing:.12em;word-break:break-all"><?= h(chunk_split($secret, 4, ' ')) ?></div>
          <div class="small text-muted2">Compte : <span class="font-monospace"><?= h($m['login']) ?></span> · Émetteur : <span class="font-monospace"><?= h($issuer) ?></span> · SHA1 · 6&nbsp;chiffres · 30&nbsp;s</div>
          <details class="mt-2">
            <summary class="small text-muted2" style="cursor:pointer">Lien <span class="font-monospace">otpauth://</span></summary>
            <div class="font-monospace small text-break mt-1"><?= h($uri) ?></div>
          </details>
        </div>
      </div>
    </div>

    <!-- Étape 3 : confirmation -->
    <div class="mb-1">
      <label class="fw-bold small mb-1" for="secu_code"><span class="badge bg-secondary me-1">3</span>Entrez le code affiché par l'application</label>
      <form method="post" class="d-flex gap-2 align-items-center mt-1">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="op" value="activer">
        <input type="hidden" name="secret" value="<?= h($secret) ?>">
        <input id="secu_code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus
               autocomplete="one-time-code"
               class="form-control font-monospace" style="max-width:160px;letter-spacing:.35em;font-size:1.1rem"
               placeholder="000000">
        <button class="btn btn-primary"><i class="bi bi-shield-check me-1"></i>Activer</button>
      </form>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php asso_bas();
