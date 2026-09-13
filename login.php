<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/connexion.php';
require_once __DIR__ . '/fonctions.php';
session_init();

// Si déjà connecté → dashboard
if (est_connecte()) {
    header('Location: ' . APP_URL . '/dashboard.php'); exit;
}

// ?bloque=1 : session coupée immédiatement par exiger_connexion() suite à
// un blocage licence déclenché PENDANT qu'un compte était déjà connecté
// (fonctions.php) — même message que le refus de connexion ci-dessous.
$erreur = (($_GET['bloque'] ?? '') === '1')
    ? "Accès bloqué : trop de tentatives de clé de licence invalides. Contactez le propriétaire de l'association."
    : '';

// Multi-établissement : liste des écoles à proposer si l'annuaire est
// présent ET qu'il y en a plus d'une. Sinon, comportement mono-école
// inchangé (repli EC1 dans connexion.php).
$ecoles = annuaire_dispo()
    ? assoc_all("SELECT id, code, nom FROM etablissement WHERE actif=1 ORDER BY nom")
    : [];
$choix_ecole = count($ecoles) > 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = post('login');
    $mdp   = post('mdp');

    // Bascule sur la base de l'école choisie AVANT la requête d'auth
    // (connexion.php a déjà sélectionné la base par défaut à l'inclusion).
    if ($choix_ecole) {
        $code = post('ecole');
        $ec   = $code !== '' ? assoc_one("SELECT * FROM etablissement WHERE code=? AND actif=1", [$code]) : null;
        if ($ec) {
            basculer_base_ecole($ec);
        } else {
            $erreur = 'Veuillez sélectionner votre établissement.';
        }
    }

    if ($erreur !== '') {
        // établissement manquant — on n'essaie pas d'authentifier
    } elseif ($login === '' || $mdp === '') {
        $erreur = 'Veuillez remplir tous les champs.';
    } else {
        // user.actif (migration_v54) : compte désactivé => connexion refusée,
        // sans révéler si c'est l'identifiant ou le statut qui bloque.
        $a_statut = db_colonne_existe('user', 'actif');
        $col_actif = $a_statut ? ', u.actif' : '';
        $u = db_one(
            "SELECT u.id_user, u.login_user, u.pwd_user, u.matricule_ens $col_actif,
                    e.nom_ens, e.prenom_ens, e.id_fonction
             FROM user u
             JOIN enseignant e ON e.matricule_ens = u.matricule_ens
             WHERE u.login_user = ? LIMIT 1",
            [$login]
        );
        $id_etab_ctx = $ec['id'] ?? (function_exists('ecole_courante') ? (ecole_courante()['id'] ?? null) : null);
        require_once __DIR__ . '/bd/lib/audit.php';
        // Licence (bd/lib/licence.php) : blocage anti-brute-force sur la
        // saisie de clé — refuse la connexion MÊME avec le bon mot de passe,
        // demande explicite du 13/09/2026. Le propriétaire n'a pas de compte
        // local (jamais concerné — il passe par /association/login.php).
        if (function_exists('licence_bloque') && licence_bloque()) {
            audit_log('connexion_echec', ['login' => $login, 'id_etab' => $id_etab_ctx, 'cible' => 'licence bloquée']);
            $erreur = "Accès bloqué : trop de tentatives de clé de licence invalides. Contactez le propriétaire de l'association.";
        } elseif ($u && password_verify($mdp, $u['pwd_user']) && (!$a_statut || (int) $u['actif'] === 1)) {
            if (db_colonne_existe('user', 'derniere_connexion')) {
                // Écriture best-effort : bug réel trouvé en test le
                // 13/09/2026 — sans ce try/catch, une licence expirée
                // (bd/lib/licence.php) bloquait CETTE écriture non
                // essentielle et empêchait TOUTE connexion (y compris pour
                // atteindre la page Licence et corriger la situation).
                try { db_exec("UPDATE user SET derniere_connexion = NOW() WHERE id_user = ?", [$u['id_user']]); }
                catch (\Throwable $e) { /* jamais bloquant — simple horodatage */ }
            }
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
            audit_log('connexion', ['id_etab' => $id_etab_ctx, 'role' => $u['id_fonction']]);
            header('Location: ' . APP_URL . '/dashboard.php'); exit;
        } elseif ($u && password_verify($mdp, $u['pwd_user']) && $a_statut && (int) $u['actif'] !== 1) {
            audit_log('connexion_echec', ['login' => $login, 'id_etab' => $id_etab_ctx, 'cible' => 'compte désactivé']);
            $erreur = "Ce compte a été désactivé. Contactez l'administration de l'établissement.";
        } else {
            audit_log('connexion_echec', ['login' => $login, 'id_etab' => $id_etab_ctx]);
            $erreur = 'Identifiant ou mot de passe incorrect.';
        }
    }
}

// Contexte neutre (annuaire présent, plusieurs écoles, aucune choisie) :
// get_etablissement() renvoie [] -> habillage GÉNÉRIQUE (aucun logo ni nom
// d'école). L'identité d'un établissement n'apparaît qu'après sélection dans
// la liste (fetch ajax/ecole_identite.php) ou après un POST avec école
// valide (basculer_base_ecole() déjà appelé plus haut).
$etab = get_etablissement();
$neutre = function_exists('est_contexte_neutre') && est_contexte_neutre() && empty($etab);

// Habillage générique (aucune école choisie) : nom + sigle de l'association
// configurée (config.local.php) plutôt qu'un « JN » / « Jaynitaare » figé.
$marque_defaut = (defined('ASSOC_NOM') && ASSOC_NOM !== '') ? ASSOC_NOM : 'Système de Gestion Scolaire';
if (preg_match_all('/\b[\p{L}]/u', $marque_defaut, $mm) && count($mm[0]) > 1) {
    $sigle_defaut = mb_strtoupper(implode('', array_slice($mm[0], 0, 3)), 'UTF-8');
} else {
    $sigle_defaut = mb_strtoupper(mb_substr(preg_replace('/[^\p{L}]/u', '', $marque_defaut) ?: 'SG', 0, 2), 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Connexion — <?= h(($etab['Initial_Etab'] ?? '') ?: (defined('ASSOC_NOM') && ASSOC_NOM !== '' ? ASSOC_NOM : 'SIGES')) ?></title>
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
  <a href="<?= APP_URL ?>/" class="d-inline-flex align-items-center gap-1 mb-2"
     style="font-size:.75rem;color:#6b7280;text-decoration:none">
    <i class="bi bi-arrow-left"></i>Retour à l'accueil
  </a>
  <div class="login-logo" id="loginLogo">
    <?php if (!empty($etab['logo']) && is_file(__DIR__ . '/assets/uploads/' . $etab['logo'])): ?>
      <img src="<?= APP_URL ?>/assets/uploads/<?= h($etab['logo']) ?>" alt="<?= h(($etab['Initial_Etab'] ?? '') ?: 'Logo') ?>">
    <?php else: ?>
      <?= h(($etab['Initial_Etab'] ?? '') ?: $sigle_defaut) ?>
    <?php endif; ?>
  </div>
  <div class="login-etab">
    <strong id="loginEtabNom"><?= h($etab['Nom_Etab_Fr'] ?? $marque_defaut) ?></strong>
    Espace de connexion
  </div>

  <?php if ($erreur): ?>
    <div class="alert alert-danger py-2 mb-3" style="font-size:.8rem">
      <i class="bi bi-exclamation-triangle me-1"></i><?= h($erreur) ?>
    </div>
  <?php endif; ?>

  <form method="post" autocomplete="off">
    <?php if ($choix_ecole): ?>
    <div class="mb-3">
      <label class="form-label">Établissement</label>
      <div class="input-group">
        <span class="input-group-text" style="background:#f8faff"><i class="bi bi-building" style="color:#6b7280"></i></span>
        <select name="ecole" class="form-select" required>
          <option value="">— Choisir —</option>
          <?php foreach ($ecoles as $ec): ?>
            <option value="<?= h($ec['code']) ?>" <?= post('ecole') === $ec['code'] ? 'selected' : '' ?>>
              <?= h($ec['nom']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <?php endif; ?>
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
  <?php if (annuaire_dispo()): ?>
  <div style="border-top:1px solid #eef0f4;margin-top:1rem;padding-top:.9rem;text-align:center">
    <a href="<?= APP_URL ?>/association/login.php" style="font-size:.78rem;color:#6b7280;text-decoration:none">
      <i class="bi bi-buildings me-1"></i>Espace association
    </a>
  </div>
  <?php endif; ?>
</div>
<script src="<?= APP_URL ?>/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<?php if ($choix_ecole): ?>
<script>
// Multi-établissement : afficher le logo + le nom de l'école dès qu'elle est
// choisie dans la liste (accueil neutre tant qu'aucune n'est sélectionnée).
(function () {
  var sel  = document.querySelector('select[name="ecole"]');
  var logo = document.getElementById('loginLogo');
  var nom  = document.getElementById('loginEtabNom');
  if (!sel) return;
  var nomDefaut   = nom.textContent;
  var sigleDefaut = <?= json_encode($sigle_defaut) ?>;
  function appliquer(code) {
    if (!code) { logo.textContent = sigleDefaut; nom.textContent = nomDefaut; return; }
    fetch('<?= APP_URL ?>/ajax/ecole_identite.php?code=' + encodeURIComponent(code))
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d) { logo.textContent = sigleDefaut; nom.textContent = nomDefaut; return; }
        nom.textContent = d.nom || nomDefaut;
        if (d.logo) {
          logo.innerHTML = '<img src="' + d.logo + '" alt="' + (d.sigle || 'Logo') + '">';
        } else {
          logo.textContent = d.sigle || sigleDefaut;
        }
      })
      .catch(function () {});
  }
  sel.addEventListener('change', function () { appliquer(this.value); });
  if (sel.value) appliquer(sel.value);   // rechargement après erreur d'auth
})();
</script>
<?php endif; ?>
</body>
</html>
