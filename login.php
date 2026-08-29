<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/connexion.php';
require_once __DIR__ . '/fonctions.php';
session_init();

// Si déjà connecté → dashboard
if (est_connecte()) {
    header('Location: ' . APP_URL . '/dashboard.php'); exit;
}

$erreur = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = post('login');
    $mdp   = post('mdp');

    if ($login === '' || $mdp === '') {
        $erreur = 'Veuillez remplir tous les champs.';
    } else {
        $u = db_one(
            "SELECT u.id_user, u.login_user, u.pwd_user, u.matricule_ens,
                    e.nom_ens, e.prenom_ens, e.id_fonction
             FROM user u
             JOIN enseignant e ON e.matricule_ens = u.matricule_ens
             WHERE u.login_user = ? LIMIT 1",
            [$login]
        );
        if ($u && password_verify($mdp, $u['pwd_user'])) {
            $_SESSION['user_id'] = $u['id_user'];
            $_SESSION['user']    = [
                'id'            => $u['id_user'],
                'matricule_ens' => $u['matricule_ens'],
                'nom'           => $u['nom_ens'],
                'prenom'        => $u['prenom_ens'],
                'role'          => $u['id_fonction'],
                'login'         => $u['login_user'],
            ];
            session_regenerate_id(true);
            header('Location: ' . APP_URL . '/dashboard.php'); exit;
        } else {
            $erreur = 'Identifiant ou mot de passe incorrect.';
        }
    }
}

$etab = get_etablissement();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Connexion — <?= h($etab['Initial_Etab'] ?: 'Jaynitaare') ?></title>
  <?php if (!empty($etab['logo']) && is_file(__DIR__ . '/assets/uploads/' . $etab['logo'])): ?>
    <link rel="icon" href="<?= APP_URL ?>/assets/uploads/<?= h($etab['logo']) ?>">
  <?php endif; ?>
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/bootstrap/css/bootstrap.min.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/inter/inter.css">
  <style>
    body { font-family:'Inter',sans-serif; background:linear-gradient(135deg,#0f1a3a 0%,#1e4fd8 100%); min-height:100vh; display:flex; align-items:center; justify-content:center; margin:0; }
    .login-box { background:#fff; border-radius:16px; padding:2.2rem 2rem; width:min(96vw,380px); box-shadow:0 20px 60px rgba(0,0,0,.35); }
    .login-logo { width:54px;height:54px;border-radius:13px;background:linear-gradient(135deg,#3a6cff,#7a4dff);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:1.2rem;color:#fff;margin:0 auto .8rem;box-shadow:0 6px 20px rgba(58,108,255,.5); overflow:hidden; }
    .login-logo img { width:100%;height:100%;object-fit:cover; }
    .login-etab { text-align:center;font-size:.8rem;color:#6b7280;margin-bottom:1.5rem; }
    .login-etab strong { display:block;font-size:1.05rem;color:#1e2a3a;font-weight:700; }
    .form-label { font-size:.75rem;font-weight:600;color:#374151; }
    .form-control { font-size:.85rem;padding:6px 10px;border-radius:8px; }
    .btn-login { background:linear-gradient(135deg,#1e4fd8,#3a6cff);border:none;border-radius:8px;font-weight:700;font-size:.9rem;padding:.55rem; }
    .btn-login:hover { opacity:.9; }
    .text-hint { font-size:.7rem;color:#9ca3af;text-align:center;margin-top:1rem; }
  </style>
</head>
<body>
<div class="login-box">
  <div class="login-logo">
    <?php if (!empty($etab['logo']) && is_file(__DIR__ . '/assets/uploads/' . $etab['logo'])): ?>
      <img src="<?= APP_URL ?>/assets/uploads/<?= h($etab['logo']) ?>" alt="<?= h($etab['Initial_Etab'] ?: 'Logo') ?>">
    <?php else: ?>
      <?= h($etab['Initial_Etab'] ?: 'JN') ?>
    <?php endif; ?>
  </div>
  <div class="login-etab">
    <strong><?= h($etab['Nom_Etab_Fr'] ?? 'Système de Gestion Scolaire') ?></strong>
    Espace de connexion
  </div>

  <?php if ($erreur): ?>
    <div class="alert alert-danger py-2 mb-3" style="font-size:.8rem">
      <i class="bi bi-exclamation-triangle me-1"></i><?= h($erreur) ?>
    </div>
  <?php endif; ?>

  <form method="post" autocomplete="off">
    <div class="mb-3">
      <label class="form-label">Identifiant</label>
      <div class="input-group">
        <span class="input-group-text" style="background:#f8faff"><i class="bi bi-person" style="color:#6b7280"></i></span>
        <input type="text" name="login" class="form-control" placeholder="Votre identifiant"
               value="<?= h(post('login')) ?>" required autofocus>
      </div>
    </div>
    <div class="mb-4">
      <label class="form-label">Mot de passe</label>
      <div class="input-group">
        <span class="input-group-text" style="background:#f8faff"><i class="bi bi-lock" style="color:#6b7280"></i></span>
        <input type="password" name="mdp" class="form-control" placeholder="••••••••" required>
      </div>
    </div>
    <button class="btn btn-primary btn-login w-100 text-white">
      <i class="bi bi-box-arrow-in-right me-2"></i>Se connecter
    </button>
  </form>
  <div class="text-hint" style="font-size:.75rem;text-align:center;margin-top:1rem">
    <a href="<?= APP_URL ?>/mot_de_passe_oublie.php" style="color:#3a6cff;text-decoration:none">Mot de passe oublié ?</a>
  </div>
</div>
<script src="<?= APP_URL ?>/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>
