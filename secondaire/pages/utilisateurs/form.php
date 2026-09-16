<?php
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_role(['ADMIN']);

$id   = (int)($_GET['id'] ?? 0);
$user = $id ? db_one("SELECT * FROM utilisateur WHERE id=?", [$id]) : null;

// POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $nom    = post('nom');
    $prenom = post('prenom');
    $login  = post('login');
    $email  = post('email');
    $role   = post('role');
    $actif  = (int)(bool)post('actif');
    $mdp    = post('mot_de_passe');
    $mdp_hash = $mdp ? password_hash($mdp, PASSWORD_DEFAULT) : null;

    if (!$nom || !$login || !$role) {
        flash_set('erreur', 'Nom, identifiant et rôle sont obligatoires.');
        rediriger('secondaire/pages/utilisateurs/form.php' . ($id ? "?id=$id" : ''));
    }

    if ($id) {
        $sql = "UPDATE utilisateur SET nom=?,prenom=?,login=?,email=?,role=?,actif=? WHERE id=?";
        $p   = [$nom,$prenom,$login,$email,$role,$actif,$id];
        if ($mdp) {
            $sql = "UPDATE utilisateur SET nom=?,prenom=?,login=?,email=?,role=?,actif=?,mot_de_passe=? WHERE id=?";
            $p   = [$nom,$prenom,$login,$email,$role,$actif,$mdp_hash,$id];
        }
        db_exec($sql, $p);
        flash_set('succes', 'Utilisateur mis à jour.');
    } else {
        if (!$mdp) { flash_set('erreur', 'Le mot de passe est obligatoire.'); rediriger('secondaire/pages/utilisateurs/form.php'); }
        db_exec("INSERT INTO utilisateur (nom,prenom,login,email,role,actif,mot_de_passe) VALUES (?,?,?,?,?,?,?)",
                [$nom,$prenom,$login,$email,$role,$actif,$mdp_hash]);
        flash_set('succes', 'Utilisateur créé.');
    }
    rediriger('secondaire/pages/utilisateurs/liste.php');
}

$titre_page = $id ? 'Modifier utilisateur' : 'Ajouter utilisateur';
require_once __DIR__ . '/../../../layout/header.php';
?>
<div class="page-titre d-flex justify-content-between align-items-center">
  <h4><i class="bi bi-person me-1 text-primary"></i><?= h($titre_page) ?></h4>
  <a href="<?= APP_URL ?>/secondaire/pages/utilisateurs/liste.php" class="btn btn-sm btn-light">
    <i class="bi bi-arrow-left me-1"></i>Retour
  </a>
</div>

<div class="card">
  <div class="card-body">
    <form method="post" class="row g-2">
      <?= csrf_champ() ?>
      <div class="col-md-4">
        <label class="form-label">Nom *</label>
        <input type="text" name="nom" class="form-control" required value="<?= h($user['nom'] ?? '') ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Prénom</label>
        <input type="text" name="prenom" class="form-control" value="<?= h($user['prenom'] ?? '') ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Email</label>
        <input type="email" name="email" class="form-control" value="<?= h($user['email'] ?? '') ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Identifiant (login) *</label>
        <input type="text" name="login" class="form-control" required value="<?= h($user['login'] ?? '') ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Rôle *</label>
        <select name="role" class="form-select" required>
          <?php
          // Valeurs alignées sur l'ENUM réel utilisateur.role (schema_ref_ecole_
          // secondaire.sql) — le dropdown LAM_ABZ d'origine listait 'PROF'
          // (inexistant dans l'ENUM, provoquerait une erreur SQL) et omettait
          // SG/ENSEIGNANT/INTENDANT ; corrigé ici (bug source, pas un choix
          // de conception à reproduire).
          foreach (['ADMIN','PROVISEUR','CENSEUR','SG','SECRETAIRE','ENSEIGNANT','INTENDANT'] as $r): ?>
            <option value="<?= $r ?>" <?= ($user['role'] ?? '') === $r ? 'selected' : '' ?>><?= h(libelle_role($r)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4 d-flex align-items-end">
        <div class="form-check mb-2">
          <input class="form-check-input" type="checkbox" name="actif" value="1" id="ck_actif"
            <?= ($user['actif'] ?? 1) ? 'checked' : '' ?>>
          <label class="form-check-label" for="ck_actif">Compte actif</label>
        </div>
      </div>
      <div class="col-md-6">
        <label class="form-label">Mot de passe <?= $id ? '(laisser vide = inchangé)' : '*' ?></label>
        <input type="password" name="mot_de_passe" class="form-control" <?= $id ? '' : 'required' ?>>
      </div>
      <div class="col-12 mt-3">
        <button class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Enregistrer</button>
        <a href="<?= APP_URL ?>/secondaire/pages/utilisateurs/liste.php" class="btn btn-light ms-1">Annuler</a>
      </div>
    </form>
  </div>
</div>
<?php require_once __DIR__ . '/../../../layout/footer.php'; ?>
