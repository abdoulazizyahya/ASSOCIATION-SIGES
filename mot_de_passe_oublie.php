<?php
// mot_de_passe_oublie.php — réinitialisation du mot de passe via les 2
// questions secrètes du compte (voir configurer_securite.php,
// bd/migration_v45.sql). Accessible depuis login.php. Limité à
// MAX_TENTATIVES réponses incorrectes avant de devoir recommencer la
// procédure depuis le début (anti brute-force sur les réponses).
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/connexion.php';
require_once __DIR__ . '/fonctions.php';
session_init();

if (est_connecte()) {
    header('Location: ' . APP_URL . '/dashboard.php'); exit;
}

const MAX_TENTATIVES = 5;

$etape  = $_SESSION['reset_etape'] ?? 'login';
$erreur = '';

// Multi-établissement : choix de l'école à la 1re étape (comme login.php).
// Une fois choisie, basculer_base_ecole() pose $_SESSION['ecole'] et
// connexion.php résout automatiquement la bonne base aux étapes suivantes.
$ecoles = annuaire_dispo()
    ? assoc_all("SELECT id, code, nom FROM etablissement WHERE actif=1 ORDER BY nom")
    : [];
$choix_ecole = count($ecoles) > 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $action = post('action');

    if ($action === 'chercher') {
        $login = post('login');
        if ($choix_ecole) {
            $ec = assoc_one("SELECT * FROM etablissement WHERE code=? AND actif=1", [post('ecole')]);
            if (!$ec) {
                $erreur = 'Veuillez sélectionner votre établissement.';
                $etape  = 'login';
            } else {
                basculer_base_ecole($ec);
            }
        }
        $u = $erreur === '' ? db_one("SELECT id_user FROM user WHERE login_user = ?", [$login]) : null;
        $questions = $u ? db_all(
            "SELECT q.id, q.libelle FROM user_question_secrete uqs
             JOIN question_secrete q ON q.id = uqs.id_question
             WHERE uqs.id_user = ? ORDER BY uqs.id", [$u['id_user']]
        ) : [];

        if ($u && count($questions) >= 2) {
            $_SESSION['reset_uid']   = $u['id_user'];
            $_SESSION['reset_qids']  = [$questions[0]['id'], $questions[1]['id']];
            $_SESSION['reset_tries'] = 0;
            $_SESSION['reset_etape'] = 'questions';
            header('Location: ' . APP_URL . '/mot_de_passe_oublie.php'); exit;
        }
        if ($erreur === '') {
            $erreur = "Compte introuvable ou récupération indisponible pour ce compte. Contactez le Directeur.";
        }
        $etape = 'login';
    }

    if ($action === 'verifier' && $etape === 'questions') {
        $uid  = (int) ($_SESSION['reset_uid'] ?? 0);
        $rep1 = post('reponse_1');
        $rep2 = post('reponse_2');
        $qids = $_SESSION['reset_qids'] ?? [0, 0];

        $hashes = db_all("SELECT id_question, reponse_hash FROM user_question_secrete WHERE id_user = ?", [$uid]);
        $map = [];
        foreach ($hashes as $h) { $map[$h['id_question']] = $h['reponse_hash']; }

        $ok1 = isset($map[$qids[0]]) && password_verify(normaliser_reponse($rep1), $map[$qids[0]]);
        $ok2 = isset($map[$qids[1]]) && password_verify(normaliser_reponse($rep2), $map[$qids[1]]);

        if ($ok1 && $ok2) {
            $_SESSION['reset_ok']    = true;
            $_SESSION['reset_etape'] = 'nouveau';
            header('Location: ' . APP_URL . '/mot_de_passe_oublie.php'); exit;
        }

        $_SESSION['reset_tries'] = ($_SESSION['reset_tries'] ?? 0) + 1;
        if ($_SESSION['reset_tries'] >= MAX_TENTATIVES) {
            unset($_SESSION['reset_uid'], $_SESSION['reset_qids'], $_SESSION['reset_tries'], $_SESSION['reset_etape']);
            $erreur = "Trop de tentatives incorrectes. Recommencez la procédure.";
            $etape  = 'login';
        } else {
            $erreur = "Réponse(s) incorrecte(s).";
            $etape  = 'questions';
        }
    }

    if ($action === 'definir' && $etape === 'nouveau') {
        $uid  = (int) ($_SESSION['reset_uid'] ?? 0);
        $mdp  = post('mot_de_passe');
        $conf = post('confirmer_mdp');

        if (!($_SESSION['reset_ok'] ?? false) || !$uid) {
            header('Location: ' . APP_URL . '/mot_de_passe_oublie.php'); exit;
        }
        if ($mdp === '' || $mdp !== $conf) {
            $erreur = "Les mots de passe ne correspondent pas.";
            $etape  = 'nouveau';
        } else {
            db_exec("UPDATE user SET pwd_user = ? WHERE id_user = ?", [password_hash($mdp, PASSWORD_DEFAULT), $uid]);
            unset($_SESSION['reset_uid'], $_SESSION['reset_qids'], $_SESSION['reset_tries'], $_SESSION['reset_ok'], $_SESSION['reset_etape']);
            flash_set('succes', 'Mot de passe réinitialisé. Vous pouvez vous connecter.');
            header('Location: ' . APP_URL . '/login.php'); exit;
        }
    }
}

// Récupère les libellés des 2 questions pour l'étape "questions" (dans
// l'ordre exact de $_SESSION['reset_qids'], pour que reponse_1/reponse_2
// corresponde bien à qids[0]/qids[1] au moment de la vérification).
$questions_a_afficher = [];
if ($etape === 'questions' && !empty($_SESSION['reset_qids'])) {
    foreach ($_SESSION['reset_qids'] as $qid) {
        $questions_a_afficher[] = ['id' => $qid, 'libelle' => db_val("SELECT libelle FROM question_secrete WHERE id = ?", [$qid])];
    }
}

$etab = get_etablissement();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Mot de passe oublié — <?= h(($etab['Initial_Etab'] ?? '') ?: (defined('ASSOC_NOM') && ASSOC_NOM !== '' ? ASSOC_NOM : 'SIGES')) ?></title>
  <?php if (!empty($etab['logo']) && is_file(__DIR__ . '/assets/uploads/' . $etab['logo'])): ?>
    <link rel="icon" href="<?= APP_URL ?>/assets/uploads/<?= h($etab['logo']) ?>">
  <?php endif; ?>
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/bootstrap/css/bootstrap.min.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/inter/inter.css">
  <style>
    body { font-family:'Inter',sans-serif; background:linear-gradient(135deg,#0f1a3a 0%,#1e4fd8 100%); min-height:100vh; display:flex; align-items:center; justify-content:center; margin:0; }
    .login-box { background:#fff; border-radius:16px; padding:2.2rem 2rem; width:min(96vw,420px); box-shadow:0 20px 60px rgba(0,0,0,.35); }
    .login-etab { text-align:center;font-size:.8rem;color:#6b7280;margin-bottom:1.5rem; }
    .login-etab strong { display:block;font-size:1.05rem;color:#1e2a3a;font-weight:700; }
    .form-label { font-size:.75rem;font-weight:600;color:#374151; }
    .form-control, .form-select { font-size:.85rem;padding:6px 10px;border-radius:8px; }
    .btn-login { background:linear-gradient(135deg,#1e4fd8,#3a6cff);border:none;border-radius:8px;font-weight:700;font-size:.9rem;padding:.55rem; }
    .btn-login:hover { opacity:.9; }
    .text-hint { font-size:.7rem;color:#9ca3af;text-align:center;margin-top:1rem; }
  </style>
</head>
<body>
<div class="login-box">
  <div class="login-etab">
    <strong>Mot de passe oublié</strong>
    <?= h($etab['Nom_Etab_Fr'] ?? 'Système de Gestion Scolaire') ?>
  </div>

  <?php if ($erreur): ?>
    <div class="alert alert-danger py-2 mb-3" style="font-size:.8rem">
      <i class="bi bi-exclamation-triangle me-1"></i><?= h($erreur) ?>
    </div>
  <?php endif; ?>

  <?php if ($etape === 'login'): ?>
    <form method="post" autocomplete="off">
      <?= csrf_champ() ?>
      <input type="hidden" name="action" value="chercher">
      <?php if ($choix_ecole): ?>
      <div class="mb-3">
        <label class="form-label">Établissement</label>
        <select name="ecole" class="form-select" required>
          <option value="">— Choisir —</option>
          <?php foreach ($ecoles as $ec): ?>
            <option value="<?= h($ec['code']) ?>" <?= post('ecole') === $ec['code'] ? 'selected' : '' ?>>
              <?= h($ec['nom']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="mb-3">
        <label class="form-label">Identifiant</label>
        <input type="text" name="login" class="form-control" required autofocus placeholder="Votre identifiant">
      </div>
      <button class="btn btn-primary btn-login w-100 text-white">Continuer</button>
    </form>

  <?php elseif ($etape === 'questions'): ?>
    <form method="post" autocomplete="off">
      <?= csrf_champ() ?>
      <input type="hidden" name="action" value="verifier">
      <?php foreach ($questions_a_afficher as $i => $q): ?>
        <div class="mb-3">
          <label class="form-label"><?= h($q['libelle']) ?></label>
          <input type="text" name="reponse_<?= $i + 1 ?>" class="form-control" required autocomplete="off">
        </div>
      <?php endforeach; ?>
      <button class="btn btn-primary btn-login w-100 text-white">Valider</button>
    </form>

  <?php elseif ($etape === 'nouveau'): ?>
    <form method="post" autocomplete="off">
      <?= csrf_champ() ?>
      <input type="hidden" name="action" value="definir">
      <div class="mb-3">
        <label class="form-label">Nouveau mot de passe</label>
        <input type="password" name="mot_de_passe" class="form-control" required autofocus>
      </div>
      <div class="mb-3">
        <label class="form-label">Confirmer le mot de passe</label>
        <input type="password" name="confirmer_mdp" class="form-control" required>
      </div>
      <button class="btn btn-primary btn-login w-100 text-white">Réinitialiser</button>
    </form>
  <?php endif; ?>

  <div class="text-hint">
    <a href="<?= APP_URL ?>/login.php" style="color:#3a6cff;text-decoration:none">← Retour à la connexion</a>
  </div>
</div>
</body>
</html>
