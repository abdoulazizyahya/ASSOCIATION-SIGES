<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_role(['DIRECTEUR']);

$id = (int)($_GET['id'] ?? 0);
$u  = $id ? db_one(
    "SELECT u.*, e.nom_ens, e.prenom_ens, e.id_fonction FROM user u
     JOIN enseignant e ON e.matricule_ens=u.matricule_ens WHERE u.id_user=?", [$id]
) : null;
if ($id && !$u) { flash_set('erreur', 'Compte introuvable.'); rediriger('pages/utilisateurs/liste.php'); }

$erreur = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $login = post('login');
    $mdp   = post('mdp');
    $matricule_ens = (int) post('matricule_ens');

    if ($login === '' || $mdp === '' || (!$id && !$matricule_ens)) {
        $erreur = 'Veuillez remplir tous les champs.';
    } elseif (strlen($mdp) < 4) {
        $erreur = 'Le mot de passe doit contenir au moins 4 caractères.';
    } else {
        $hash = password_hash($mdp, PASSWORD_DEFAULT);
        if ($id) {
            db_exec("UPDATE user SET login_user=?, pwd_user=? WHERE id_user=?", [$login, $hash, $id]);
            journaliser_action('mot_de_passe_reinit', null, $login);
            flash_set('succes', 'Compte mis à jour.');
        } else {
            $existe = db_val("SELECT COUNT(*) FROM user WHERE login_user=?", [$login]);
            if ($existe) {
                $erreur = 'Cet identifiant est déjà utilisé.';
            } else {
                db_exec("INSERT INTO user (login_user, pwd_user, matricule_ens) VALUES (?, ?, ?)", [$login, $hash, $matricule_ens]);
                journaliser_action('utilisateur_cree', null, $login);
                flash_set('succes', 'Compte créé.');
            }
        }
        if (!$erreur) rediriger('pages/utilisateurs/liste.php');
    }
}

// Personnel sans compte encore (pour la création)
$sans_compte = db_all(
    "SELECT e.matricule_ens, e.nom_ens, e.prenom_ens, e.id_fonction FROM enseignant e
     WHERE e.matricule_ens NOT IN (SELECT matricule_ens FROM user)
     ORDER BY e.nom_ens"
);

$es_partiel = isset($_GET['partiel']);
if (!$es_partiel) {
    $titre_page = $id ? 'Réinitialiser un compte' : 'Nouveau compte';
    require_once __DIR__ . '/../../layout/header.php';
} else {
    header('Content-Type: text/html; charset=utf-8');
}
?>

<div id="utilisateur-form-zone">

<div class="page-titre">
  <h4><i class="bi bi-person-gear me-1 text-primary"></i><?= $id ? 'Réinitialiser le mot de passe' : 'Nouveau compte' ?></h4>
</div>

<div class="card" style="max-width:520px">
  <div class="card-body">
    <?php if ($erreur): ?><div class="alert alert-danger py-2"><?= h($erreur) ?></div><?php endif; ?>
    <form method="post" data-ajax-post-form>
      <?= csrf_champ() ?>
      <?php if ($id): ?>
        <div class="mb-3">
          <label class="form-label">Personne</label>
          <input type="text" class="form-control" disabled value="<?= h(mb_strtoupper($u['nom_ens'])) . ' ' . h($u['prenom_ens'] ?? '') . ' — ' . h(libelle_role($u['id_fonction'])) ?>">
        </div>
      <?php else: ?>
        <div class="mb-3">
          <label class="form-label">Membre du personnel</label>
          <select name="matricule_ens" class="form-select" required>
            <option value="">— Choisir —</option>
            <?php foreach ($sans_compte as $e): ?>
              <option value="<?= (int)$e['matricule_ens'] ?>">
                <?= h(mb_strtoupper($e['nom_ens'])) ?> <?= h($e['prenom_ens'] ?? '') ?> — <?= h(libelle_role($e['id_fonction'] ?? '')) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php if (empty($sans_compte)): ?>
            <div class="form-text text-warning">Tout le personnel dispose déjà d'un compte.</div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
      <div class="mb-3">
        <label class="form-label">Identifiant</label>
        <input type="text" name="login" class="form-control" required value="<?= h($u['login_user'] ?? post('login')) ?>">
      </div>
      <div class="mb-3">
        <label class="form-label">Mot de passe <?= $id ? '(nouveau)' : '' ?></label>
        <input type="text" name="mdp" class="form-control" required placeholder="Au moins 4 caractères">
      </div>
      <div class="d-flex gap-2">
        <button class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Enregistrer</button>
        <a href="<?= APP_URL ?>/pages/utilisateurs/liste.php" class="btn btn-outline-secondary btn-sm">Annuler</a>
      </div>
    </form>
  </div>
</div>

</div><!-- /#utilisateur-form-zone -->
<?php if ($es_partiel) exit; // rien de plus dans une réponse AJAX partielle. ?>

<?php
$ajax_zone_id = 'utilisateur-form-zone'; // voir layout/footer.php — initAjaxZone() y est appelé
require_once __DIR__ . '/../../layout/footer.php';
