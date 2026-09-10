<?php
// association/securite.php — page à onglets.
//   • « Mon compte »  : coordonnées (e-mail + téléphone), questions secrètes,
//     mot de passe, double authentification TOTP.
//   • « Comptes »      : (superadmin) créer un compte membre, réinitialiser
//     un mot de passe, activer/désactiver, retirer la 2FA / les questions.
//     La grille des accès par école reste dans membres/voir.php.
//
// Hiérarchie : un superadmin « simple » ne voit ni ne gère le compte du
// PROPRIÉTAIRE de l'association (membre.proprietaire). Le propriétaire a
// tous les droits et voit tous les comptes.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../bd/lib/totp.php';
exiger_membre_association();

$fa_dispo    = assoc_2fa_disponible();
$coord_dispo = function_exists('assoc_coordonnees_dispo') && assoc_coordonnees_dispo();
$q_dispo     = function_exists('assoc_questions_dispo') && assoc_questions_dispo();
$superadmin  = est_superadmin_association();
$proprio     = est_proprietaire_association();

$moi = (int) (membre_connecte()['id'] ?? (membre_connecte()['login']
        ? assoc_val("SELECT id FROM membre WHERE login=?", [membre_connecte()['login']]) : 0));
$m   = assoc_one("SELECT * FROM membre WHERE id=?", [$moi]);
if (!$m) { asso_haut('Sécurité'); asso_bas(); exit; }

$onglet = ($_GET['onglet'] ?? '') === 'comptes' && $superadmin ? 'comptes' : 'compte';
$msg = ''; $err = '';

// Cible propriétaire non gérable par un superadmin simple ?
$cible_verrou = function (int $id) use ($proprio): bool {
    if ($proprio) return false;
    return (bool) assoc_val("SELECT proprietaire FROM membre WHERE id=?", [$id]);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $op = $_POST['op'] ?? '';

    // ───────────── Onglet « Mon compte » ─────────────
    if ($op === 'coordonnees') {
        $r = assoc_membre_coordonnees_maj($moi, $_POST['email'] ?? '', $_POST['tel'] ?? '');
        $msg = $r['ok'] ? $r['message'] : ''; $err = $r['ok'] ? '' : $r['message'];
        if ($r['ok']) journaliser_action('membre_coordonnees', null, $m['login']);

    } elseif ($op === 'questions') {
        $paires = [
            ['question' => $_POST['q1'] ?? '', 'reponse' => $_POST['r1'] ?? ''],
            ['question' => $_POST['q2'] ?? '', 'reponse' => $_POST['r2'] ?? ''],
        ];
        $r = assoc_membre_questions_definir($moi, $paires);
        $msg = $r['ok'] ? $r['message'] : ''; $err = $r['ok'] ? '' : $r['message'];
        if ($r['ok']) journaliser_action('membre_questions', null, $m['login']);

    } elseif ($op === 'mdp') {
        $p1 = (string) ($_POST['pwd'] ?? '');
        $p2 = (string) ($_POST['pwd2'] ?? '');
        if (!password_verify((string) ($_POST['pwd_actuel'] ?? ''), $m['pwd_hash'])) {
            $err = "Mot de passe actuel incorrect.";
        } elseif ($p1 !== $p2) {
            $err = "Les deux nouveaux mots de passe ne correspondent pas.";
        } else {
            $r = assoc_membre_mot_de_passe($moi, $p1);
            $msg = $r['ok'] ? $r['message'] : ''; $err = $r['ok'] ? '' : $r['message'];
            if ($r['ok']) journaliser_action('membre_mdp', null, $m['login']);
        }

    } elseif ($op === 'activer') {
        $secret = preg_replace('/[^A-Z2-7]/', '', strtoupper($_POST['secret'] ?? ''));
        $r = ($secret !== '')
            ? assoc_membre_2fa_activer($moi, $secret, trim($_POST['code'] ?? ''))
            : ['ok' => false, 'message' => 'Secret manquant, recommencez.'];
        if ($r['ok']) { unset($_SESSION['secu_secret'], $_SESSION['forcer_2fa']); $msg = $r['message']; journaliser_action('membre_2fa_on', null, $m['login']); }
        else { $err = $r['message']; }

    } elseif ($op === 'desactiver') {
        if ($m['totp_secret'] && totp_verifier($m['totp_secret'], trim($_POST['code'] ?? ''))) {
            assoc_membre_2fa_desactiver($moi);
            journaliser_action('membre_2fa_off', null, $m['login']);
            $msg = 'Double authentification désactivée.';
        } else {
            $err = 'Code incorrect — la double authentification reste active.';
        }

    // ───────────── Onglet « Comptes » (superadmin) ─────────────
    } elseif (strpos($op, 'c_') === 0 && $superadmin) {
        $onglet = 'comptes';
        $cid = (int) ($_POST['id'] ?? 0);

        if ($op === 'c_creer') {
            $in = [
                'login'  => trim($_POST['login'] ?? ''),
                'nom'    => trim($_POST['nom'] ?? ''),
                'prenom' => trim($_POST['prenom'] ?? ''),
                'email'  => trim($_POST['email'] ?? ''),
                'pwd'    => (string) ($_POST['pwd'] ?? ''),
            ];
            $r = assoc_membre_creer($in);
            $msg = $r['ok'] ? $r['message'] : ''; $err = $r['ok'] ? '' : $r['message'];
            if ($r['ok']) journaliser_action('membre_creation', null, $in['login']);

        } elseif ($cid && $cid === $moi) {
            $err = "Gérez votre propre compte depuis l'onglet « Mon compte ».";
        } elseif ($cid && $cible_verrou($cid)) {
            $err = "Le compte du propriétaire n'est pas accessible.";
        } elseif ($op === 'c_mdp' && $cid) {
            $r = assoc_membre_mot_de_passe($cid, (string) ($_POST['pwd'] ?? ''));
            $msg = $r['ok'] ? $r['message'] : ''; $err = $r['ok'] ? '' : $r['message'];
            if ($r['ok']) journaliser_action('membre_mdp', null, assoc_val("SELECT login FROM membre WHERE id=?", [$cid]));

        } elseif ($op === 'c_actif' && $cid) {
            $etat = (int) !empty($_POST['actif']);
            assoc_exec("UPDATE membre SET actif=? WHERE id=? AND proprietaire=0", [$etat, $cid]);
            $msg = $etat ? "Compte réactivé." : "Compte désactivé.";
            journaliser_action('membre_modifie', null, assoc_val("SELECT login FROM membre WHERE id=?", [$cid]) . ($etat ? ' (réactivé)' : ' (désactivé)'));

        } elseif ($op === 'c_2fa_off' && $cid && function_exists('assoc_membre_2fa_desactiver')) {
            assoc_membre_2fa_desactiver($cid);
            $msg = "Double authentification retirée pour ce membre.";
            journaliser_action('membre_2fa_off', null, assoc_val("SELECT login FROM membre WHERE id=?", [$cid]) . ' (par superadmin)');

        } elseif ($op === 'c_questions_off' && $cid) {
            assoc_membre_questions_supprimer($cid);
            $msg = "Questions secrètes retirées pour ce membre.";
            journaliser_action('membre_questions', null, assoc_val("SELECT login FROM membre WHERE id=?", [$cid]) . ' (retirées par superadmin)');
        }
    }

    $m = assoc_one("SELECT * FROM membre WHERE id=?", [$moi]);
}

$actif      = $fa_dispo && (int) ($m['totp_actif'] ?? 0) === 1;
$mes_q      = $q_dispo ? assoc_membre_questions_get($moi) : [];
$q_ok       = count($mes_q) === 2;
$suggestions = $q_dispo ? assoc_questions_suggerees() : [];

// Secret 2FA proposé (nouveau à chaque affichage, mémorisé en session).
if ($fa_dispo && !$actif) {
    if (empty($_SESSION['secu_secret'])) $_SESSION['secu_secret'] = totp_secret_nouveau();
    $secret = $_SESSION['secu_secret'];
    $issuer = (defined('ASSOC_NOM') && ASSOC_NOM !== '') ? ASSOC_NOM
            : (defined('APP_NOM') ? APP_NOM : 'SIGES');
    $uri    = totp_uri($secret, $m['login'], $issuer);
    $qr_src = APP_URL . '/pdf/qrcode.php?s=6&e=M&d=' . urlencode($uri);
}

// Liste des comptes (onglet « Comptes ») — le propriétaire est masqué pour
// un superadmin simple (sauf lui-même, cas impossible ici).
$membres = [];
if ($onglet === 'comptes' && $superadmin) {
    $membres = assoc_membres_liste();
    if (!$proprio) {
        $membres = array_values(array_filter($membres, fn($x) => empty($x['proprietaire']) || (int) $x['id'] === $moi));
    }
}

asso_haut('Sécurité');
$csrf = csrf_generer();
$url_ong = fn(string $o) => APP_URL . '/association/securite.php?onglet=' . $o;
?>
<?php if ($msg): ?><div class="alert alert-success py-2 small" style="max-width:640px"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-warning py-2 small" style="max-width:640px"><?= h($err) ?></div><?php endif; ?>

<?php if ($superadmin): ?>
<ul class="nav nav-tabs mb-3" style="border-bottom:2px solid var(--border)">
  <li class="nav-item"><a class="nav-link <?= $onglet === 'compte' ? 'active' : '' ?>" href="<?= $url_ong('compte') ?>"><i class="bi bi-person-gear me-1"></i>Mon compte</a></li>
  <li class="nav-item"><a class="nav-link <?= $onglet === 'comptes' ? 'active' : '' ?>" href="<?= $url_ong('comptes') ?>"><i class="bi bi-people me-1"></i>Comptes</a></li>
</ul>
<?php endif; ?>

<?php if ($onglet === 'compte'): ?>
<!-- ═══════════════════ ONGLET « MON COMPTE » ═══════════════════ -->

<!-- ── Coordonnées ─────────────────────────────────────────────── -->
<div class="asso-card mb-3" style="max-width:560px">
  <div class="fw-bold mb-1"><i class="bi bi-person-vcard me-1"></i>Mes coordonnées</div>
  <div class="small text-muted2 mb-3">
    Servent à récupérer votre mot de passe si vous l'oubliez
    (<a href="<?= APP_URL ?>/association/mot_de_passe_oublie.php">page « mot de passe oublié »</a>) :
    il faudra fournir <strong>exactement</strong> l'e-mail <em>et</em> le téléphone enregistrés ici.
  </div>
  <form method="post" class="row g-2">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="op" value="coordonnees">
    <div class="col-12">
      <label class="form-label small">Adresse e-mail</label>
      <input type="email" name="email" class="form-control form-control-sm" value="<?= h($m['email'] ?? '') ?>" placeholder="vous@exemple.cm">
    </div>
    <div class="col-12">
      <label class="form-label small">Téléphone</label>
      <input type="text" name="tel" class="form-control form-control-sm" value="<?= h($m['tel'] ?? '') ?>"
             placeholder="+237 6XX XX XX XX" <?= $coord_dispo ? '' : 'disabled' ?>>
      <?php if (!$coord_dispo): ?>
        <div class="form-text small text-warning">Annuaire non à jour : lancez <span class="font-monospace">php bd/assoc/maj_assoc.php</span>.</div>
      <?php endif; ?>
    </div>
    <div class="col-12 mt-1"><button class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Enregistrer</button></div>
  </form>
</div>

<!-- ── Questions secrètes ──────────────────────────────────────── -->
<?php if ($q_dispo): ?>
<div class="asso-card mb-3" style="max-width:560px">
  <div class="d-flex align-items-center gap-2 mb-1">
    <i class="bi bi-patch-question<?= $q_ok ? '-fill text-success' : '' ?>"></i>
    <span class="fw-bold">Mes questions secrètes</span>
    <span class="badge <?= $q_ok ? 'bg-success' : 'bg-secondary' ?> ms-auto"><?= $q_ok ? 'configurées' : 'non configurées' ?></span>
  </div>
  <div class="small text-muted2 mb-3">
    Voie de récupération <strong>complémentaire</strong> : à la page « mot de passe oublié » vous
    pourrez, au choix, fournir e-mail + téléphone <em>ou</em> répondre à ces 2 questions.
    La casse et les espaces ne comptent pas dans les réponses.
  </div>
  <form method="post" class="row g-2">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="op" value="questions">
    <datalist id="q_suggest"><?php foreach ($suggestions as $s): ?><option value="<?= h($s) ?>"><?php endforeach; ?></datalist>
    <?php for ($i = 1; $i <= 2; $i++): ?>
      <div class="col-12">
        <label class="form-label small">Question <?= $i ?></label>
        <input list="q_suggest" name="q<?= $i ?>" class="form-control form-control-sm" maxlength="160" required
               value="<?= h($mes_q[$i] ?? '') ?>" placeholder="Choisir ou saisir une question…">
      </div>
      <div class="col-12">
        <label class="form-label small">Réponse <?= $i ?></label>
        <input type="text" name="r<?= $i ?>" class="form-control form-control-sm" required autocomplete="off"
               placeholder="<?= $q_ok ? 'Ressaisir la réponse pour enregistrer' : 'Votre réponse' ?>">
      </div>
    <?php endfor; ?>
    <div class="col-12 mt-1"><button class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Enregistrer les questions</button></div>
  </form>
</div>
<?php endif; ?>

<!-- ── Mot de passe ────────────────────────────────────────────── -->
<div class="asso-card mb-3" style="max-width:560px">
  <div class="fw-bold mb-2"><i class="bi bi-key me-1"></i>Changer mon mot de passe</div>
  <form method="post" class="row g-2" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="op" value="mdp">
    <div class="col-12">
      <label class="form-label small">Mot de passe actuel</label>
      <input type="password" name="pwd_actuel" class="form-control form-control-sm" required autocomplete="current-password">
    </div>
    <div class="col-sm-6">
      <label class="form-label small">Nouveau mot de passe</label>
      <input type="password" name="pwd" class="form-control form-control-sm" required minlength="8" autocomplete="new-password">
      <div class="form-text small text-muted2">8 caractères minimum</div>
    </div>
    <div class="col-sm-6">
      <label class="form-label small">Confirmer</label>
      <input type="password" name="pwd2" class="form-control form-control-sm" required minlength="8" autocomplete="new-password">
    </div>
    <div class="col-12 mt-1"><button class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Changer</button></div>
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

<?php elseif ($onglet === 'comptes' && $superadmin): ?>
<!-- ═══════════════════ ONGLET « COMPTES » ═══════════════════ -->
<div class="small text-muted2 mb-3" style="max-width:720px">
  Créez ici les comptes de l'association et réinitialisez leurs identifiants.
  La <strong>grille des accès par école</strong> (lecture / écriture) se règle via
  <a href="<?= APP_URL ?>/association/membres/index.php">Membres</a> → « Gérer ».
</div>

<div class="asso-card p-0 mb-3">
  <div class="table-responsive">
  <table class="table table-sm mb-0 align-middle" style="font-size:.85rem">
    <thead><tr class="text-muted2">
      <th>Login</th><th>Nom</th><th>Droits</th><th>État</th>
      <?php if ($fa_dispo): ?><th class="text-center">2FA</th><?php endif; ?>
      <?php if ($q_dispo): ?><th class="text-center">Questions</th><?php endif; ?>
      <th class="text-end">Actions</th>
    </tr></thead>
    <tbody>
      <?php foreach ($membres as $mm):
        $mid = (int) $mm['id']; $is_moi = $mid === $moi; ?>
        <tr class="<?= $mm['actif'] ? '' : 'opacity-50' ?>">
          <td class="font-monospace"><?= h($mm['login']) ?><?php if ($is_moi): ?> <span class="badge badge-soft">vous</span><?php endif; ?></td>
          <td><?= h(trim(($mm['prenom'] ?? '') . ' ' . $mm['nom'])) ?>
            <?php if ($mm['email']): ?><div class="small text-muted2"><?= h($mm['email']) ?></div><?php endif; ?>
          </td>
          <td>
            <?php if (!empty($mm['proprietaire'])): ?><span class="badge bg-warning text-dark"><i class="bi bi-key-fill me-1"></i>Propriétaire</span>
            <?php elseif ($mm['superadmin']): ?><span class="badge bg-primary">Superadmin</span>
            <?php elseif ($mm['global_lecture']): ?><span class="badge badge-soft">Toutes écoles · lecture</span>
            <?php elseif ($mm['nb_ecoles']): ?><span class="badge badge-soft"><?= (int) $mm['nb_ecoles'] ?> école(s)</span>
            <?php else: ?><span class="text-muted2 small">aucun accès</span><?php endif; ?>
          </td>
          <td><?= $mm['actif'] ? '<span class="text-success small">actif</span>' : '<span class="text-warning small">désactivé</span>' ?></td>
          <?php if ($fa_dispo): ?><td class="text-center"><?= !empty($mm['totp_actif']) ? '<i class="bi bi-shield-check text-success"></i>' : '<span class="text-muted2">—</span>' ?></td><?php endif; ?>
          <?php if ($q_dispo): ?><td class="text-center"><?= assoc_membre_questions_nb($mid) === 2 ? '<i class="bi bi-patch-check text-success"></i>' : '<span class="text-muted2">—</span>' ?></td><?php endif; ?>
          <td class="text-end" style="white-space:nowrap">
            <?php if ($is_moi): ?>
              <a href="<?= $url_ong('compte') ?>" class="btn btn-outline-light btn-sm">Mon compte</a>
            <?php else: ?>
              <div class="dropdown d-inline-block">
                <button class="btn btn-outline-light btn-sm dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></button>
                <ul class="dropdown-menu dropdown-menu-end" style="font-size:.85rem">
                  <li>
                    <form method="post" class="px-2 py-1" onsubmit="return confirm('Réinitialiser le mot de passe de « <?= h($mm['login']) ?> » ?');">
                      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                      <input type="hidden" name="op" value="c_mdp">
                      <input type="hidden" name="id" value="<?= $mid ?>">
                      <div class="input-group input-group-sm">
                        <input name="pwd" type="text" class="form-control" placeholder="Nouveau mot de passe" required minlength="8" autocomplete="off" style="min-width:150px">
                        <button class="btn btn-outline-warning">OK</button>
                      </div>
                    </form>
                  </li>
                  <li><hr class="dropdown-divider my-1"></li>
                  <li>
                    <form method="post">
                      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                      <input type="hidden" name="op" value="c_actif">
                      <input type="hidden" name="id" value="<?= $mid ?>">
                      <?php if ($mm['actif']): ?>
                        <button class="dropdown-item text-warning" <?= !empty($mm['proprietaire']) ? 'disabled' : '' ?>>Désactiver le compte</button>
                      <?php else: ?>
                        <input type="hidden" name="actif" value="1">
                        <button class="dropdown-item text-success">Réactiver le compte</button>
                      <?php endif; ?>
                    </form>
                  </li>
                  <?php if ($fa_dispo && !empty($mm['totp_actif'])): ?>
                  <li><form method="post" onsubmit="return confirm('Retirer la 2FA de ce membre ?');">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="op" value="c_2fa_off"><input type="hidden" name="id" value="<?= $mid ?>">
                    <button class="dropdown-item">Retirer la double authentification</button>
                  </form></li>
                  <?php endif; ?>
                  <?php if ($q_dispo && assoc_membre_questions_nb($mid) > 0): ?>
                  <li><form method="post" onsubmit="return confirm('Retirer les questions secrètes de ce membre ?');">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="op" value="c_questions_off"><input type="hidden" name="id" value="<?= $mid ?>">
                    <button class="dropdown-item">Retirer les questions secrètes</button>
                  </form></li>
                  <?php endif; ?>
                  <li><hr class="dropdown-divider my-1"></li>
                  <li><a class="dropdown-item" href="<?= APP_URL ?>/association/membres/voir.php?id=<?= $mid ?>"><i class="bi bi-diagram-3 me-1"></i>Gérer les accès par école</a></li>
                </ul>
              </div>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<div class="asso-card" style="max-width:560px">
  <div class="fw-bold mb-2"><i class="bi bi-person-plus me-1"></i>Nouveau compte</div>
  <form method="post" class="row g-2">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="op" value="c_creer">
    <div class="col-6">
      <label class="form-label small">Login *</label>
      <input name="login" class="form-control form-control-sm font-monospace" required maxlength="50" pattern="[A-Za-z0-9._-]{3,50}">
      <div class="form-text small text-muted2">3–50 · a-z 0-9 . _ -</div>
    </div>
    <div class="col-6">
      <label class="form-label small">Mot de passe *</label>
      <input name="pwd" type="text" class="form-control form-control-sm" required minlength="8" autocomplete="off">
      <div class="form-text small text-muted2">8 caractères minimum</div>
    </div>
    <div class="col-6">
      <label class="form-label small">Nom *</label>
      <input name="nom" class="form-control form-control-sm" required maxlength="100">
    </div>
    <div class="col-6">
      <label class="form-label small">Prénom</label>
      <input name="prenom" class="form-control form-control-sm" maxlength="100">
    </div>
    <div class="col-12">
      <label class="form-label small">Email</label>
      <input name="email" type="email" class="form-control form-control-sm" maxlength="150">
    </div>
    <div class="col-12 mt-2">
      <button class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Créer le compte</button>
      <span class="small text-muted2 ms-2">
        Créé sans accès. Réglez ensuite ses droits par école via « Gérer les accès ».
        <?php if (!$proprio): ?>Le niveau <strong>superadmin</strong> ne peut être accordé que par le propriétaire.<?php endif; ?>
      </span>
    </div>
  </form>
</div>

<?php endif; // onglet ?>
<?php asso_bas();
