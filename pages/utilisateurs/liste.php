<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_role(['DIRECTEUR']);

$utilisateurs = db_all(
    "SELECT u.id_user, u.login_user, u.matricule_ens, e.nom_ens, e.prenom_ens, e.id_fonction
     FROM user u JOIN enseignant e ON e.matricule_ens=u.matricule_ens
     ORDER BY e.id_fonction, e.nom_ens"
);

$titre_page = 'Utilisateurs';
require_once __DIR__ . '/../../layout/header.php';
?>

<div class="page-titre d-flex justify-content-between align-items-center">
  <div>
    <h4><i class="bi bi-person-gear me-1 text-primary"></i>Comptes utilisateurs</h4>
    <div class="sub"><?= count($utilisateurs) ?> compte(s)</div>
  </div>
  <a href="<?= APP_URL ?>/pages/utilisateurs/form.php" class="btn btn-primary btn-sm">
    <i class="bi bi-plus-lg me-1"></i>Nouveau compte
  </a>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-abz table-hover align-middle mb-0">
      <thead>
        <tr><th>Identifiant</th><th>Personne</th><th>Rôle</th><th class="text-end">Actions</th></tr>
      </thead>
      <tbody>
        <?php if (empty($utilisateurs)): ?>
          <tr><td colspan="4" class="text-center text-muted py-4">Aucun compte trouvé.</td></tr>
        <?php else: foreach ($utilisateurs as $u): ?>
          <tr>
            <td class="fw-semibold"><?= h($u['login_user']) ?></td>
            <td><?= h(mb_strtoupper($u['nom_ens'])) ?> <?= h($u['prenom_ens'] ?? '') ?></td>
            <td><span class="badge-code"><?= h(libelle_role($u['id_fonction'] ?? '')) ?></span></td>
            <td class="text-end">
              <a href="<?= APP_URL ?>/pages/utilisateurs/form.php?id=<?= (int)$u['id_user'] ?>"
                 class="btn btn-sm btn-light" style="padding:3px 7px" title="Réinitialiser le mot de passe">
                <i class="bi bi-key" style="font-size:.78rem"></i>
              </a>
              <?php if ($u['id_user'] != ($_SESSION['user_id'] ?? 0)): ?>
              <a href="<?= APP_URL ?>/pages/utilisateurs/supprimer.php?id=<?= (int)$u['id_user'] ?>&csrf=<?= csrf_generer() ?>"
                 class="btn btn-sm btn-light text-danger" style="padding:3px 7px"
                 onclick="return confirm('Supprimer ce compte ?')">
                <i class="bi bi-trash" style="font-size:.78rem"></i>
              </a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../../layout/footer.php'; ?>
