<?php
// parents/index.php — connexion à l'espace parents (16/11/2026).
//  1. choix de l'école (si plusieurs) — ou lien direct ?ec=CODE donné par l'école ;
//  2. téléphone + code SMS, ou matricule + code parent (selon les réglages de l'école).
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../noyau/connexion.php';
require_once __DIR__ . '/../noyau/fonctions.php';
require_once __DIR__ . '/../noyau/partage/espace_parents.php';
require_once __DIR__ . '/_layout.php';
session_init();

// École : choix posé en session (pages suivantes sans ?ec=).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('op') === 'ecole') {
    csrf_verifier();
    $ec = assoc_one("SELECT * FROM etablissement WHERE code=? AND actif=1", [(string) post('ecole')]);
    if ($ec) { basculer_base_ecole($ec); unset($_SESSION['parent'], $_SESSION['ep_tel']); }
    header('Location: ' . APP_URL . '/parents/index.php'); exit;
}
if (ecole_courante() && !empty($_GET['ec']) && (int) ($_SESSION['ecole']['id'] ?? 0) !== (int) ecole_courante()['id']) {
    basculer_base_ecole(ecole_courante());
    unset($_SESSION['parent'], $_SESSION['ep_tel']);
}
if (isset($_GET['changer'])) { unset($_SESSION['ecole'], $_SESSION['parent'], $_SESSION['ep_tel']); header('Location: ' . APP_URL . '/parents/index.php'); exit; }

$ecole = ecole_courante();
if (!$ecole) {
    $ecoles = annuaire_dispo() ? assoc_all("SELECT code, nom FROM etablissement WHERE actif=1 ORDER BY nom") : [];
    ep_haut('Choisir l\'école');
    ?>
    <div class="ep-carte">
      <h2><i class="bi bi-buildings me-1"></i>Choisissez l'école de votre enfant</h2>
      <form method="post"><?= csrf_champ() ?><input type="hidden" name="op" value="ecole">
        <select name="ecole" class="form-select mb-3" required>
          <option value="">— École —</option>
          <?php foreach ($ecoles as $e): ?><option value="<?= h($e['code']) ?>"><?= h($e['nom']) ?></option><?php endforeach; ?>
        </select>
        <button class="btn btn-ep w-100">Continuer</button>
      </form>
    </div>
    <?php ep_bas(); exit;
}

if (!empty($_SESSION['parent']) && (int) ($_SESSION['parent']['ecole'] ?? 0) === (int) $ecole['id']) { header('Location: ' . APP_URL . '/parents/accueil.php'); exit; }

$reg = ep_reglages();
$sms_ok = !empty($reg['sms']) && sms_gw_actif();
$code_ok = !empty($reg['code']);
$mode = (string) ($_GET['mode'] ?? ($sms_ok ? 'sms' : 'code'));
$err = ''; $info = '';

if ($reg['actif'] && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $op = (string) post('op');
    if ($op === 'code' && $code_ok) {
        $r = ep_connexion_code((string) post('matricule'), (string) post('code'));
        if (is_int($r)) { ep_session_ouvrir([$r], 'code'); header('Location: ' . APP_URL . '/parents/accueil.php'); exit; }
        $err = $r; $mode = 'code';
    } elseif ($op === 'sms_envoyer' && $sms_ok) {
        $r = ep_otp_envoyer((string) post('tel'));
        if ($r['ok']) { $_SESSION['ep_tel'] = $r['tel']; $info = $r['message']; } else $err = $r['message'];
        $mode = 'sms';
    } elseif ($op === 'sms_verifier' && $sms_ok && !empty($_SESSION['ep_tel'])) {
        $r = ep_otp_verifier((string) $_SESSION['ep_tel'], (string) post('otp'));
        if (is_array($r)) { $tel = (string) $_SESSION['ep_tel']; unset($_SESSION['ep_tel']); ep_session_ouvrir($r, 'sms', $tel); header('Location: ' . APP_URL . '/parents/accueil.php'); exit; }
        $err = $r; $mode = 'sms';
    } elseif ($op === 'sms_autre') {
        unset($_SESSION['ep_tel']); $mode = 'sms';
    }
}

ep_haut('Connexion');
if (!$reg['actif'] || (!$sms_ok && !$code_ok)): ?>
  <div class="ep-carte text-center">
    <i class="bi bi-hourglass-split fs-2 ep-or"></i>
    <p class="mt-2 mb-1">L'espace parents n'est pas encore ouvert dans cette école.</p>
    <p class="small text-muted mb-0">Renseignez-vous auprès de l'administration.</p>
  </div>
<?php else: ?>
  <div class="ep-carte">
    <h2><i class="bi bi-person-lock me-1"></i>Suivez la scolarité de votre enfant</h2>
    <p class="small text-muted">Paiements, absences et résultats publiés par l'école.</p>
    <?php if ($sms_ok && $code_ok): ?>
    <ul class="nav nav-pills nav-fill mb-3 small">
      <li class="nav-item"><a class="nav-link <?= $mode === 'sms' ? 'active' : '' ?>" href="?mode=sms"><i class="bi bi-phone me-1"></i>Par SMS</a></li>
      <li class="nav-item"><a class="nav-link <?= $mode === 'code' ? 'active' : '' ?>" href="?mode=code"><i class="bi bi-key me-1"></i>Code parent</a></li>
    </ul>
    <?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger py-2 small"><?= h($err) ?></div><?php endif; ?>
    <?php if ($info): ?><div class="alert alert-success py-2 small"><?= h($info) ?></div><?php endif; ?>

    <?php if ($mode === 'sms' && $sms_ok): ?>
      <?php if (empty($_SESSION['ep_tel'])): ?>
      <form method="post"><?= csrf_champ() ?><input type="hidden" name="op" value="sms_envoyer">
        <label class="form-label small">Numéro de téléphone donné à l'école</label>
        <input type="tel" name="tel" class="form-control mb-3" inputmode="tel" placeholder="6 96 51 43 46" autocomplete="tel" required>
        <button class="btn btn-ep w-100"><i class="bi bi-send me-1"></i>Recevoir un code par SMS</button>
      </form>
      <?php else: ?>
      <form method="post"><?= csrf_champ() ?><input type="hidden" name="op" value="sms_verifier">
        <label class="form-label small">Code reçu au <?= h(tel_affiche((string) $_SESSION['ep_tel'])) ?></label>
        <input type="text" name="otp" class="form-control mb-3 text-center fs-4" inputmode="numeric" maxlength="6" autocomplete="one-time-code" placeholder="••••••" required autofocus>
        <button class="btn btn-ep w-100">Se connecter</button>
      </form>
      <form method="post" class="text-center mt-2"><?= csrf_champ() ?><input type="hidden" name="op" value="sms_autre">
        <button class="btn btn-link btn-sm">Changer de numéro / renvoyer un code</button></form>
      <?php endif; ?>
    <?php elseif ($code_ok): ?>
      <form method="post"><?= csrf_champ() ?><input type="hidden" name="op" value="code">
        <label class="form-label small">Matricule (ou NIU) de l'élève</label>
        <input type="text" name="matricule" class="form-control mb-2" autocapitalize="characters" required value="<?= h((string) post('matricule')) ?>">
        <label class="form-label small">Code parent (remis par l'école)</label>
        <input type="text" name="code" class="form-control mb-3 text-uppercase" placeholder="ABCD-EFGH" autocomplete="off" autocapitalize="characters" required>
        <button class="btn btn-ep w-100">Se connecter</button>
      </form>
    <?php endif; ?>
  </div>
<?php endif; ?>
<?php if (annuaire_dispo() && (int) assoc_val("SELECT COUNT(*) FROM etablissement WHERE actif=1") > 1): ?>
  <p class="text-center small"><a href="?changer=1">Changer d'école</a></p>
<?php endif;
ep_bas();
