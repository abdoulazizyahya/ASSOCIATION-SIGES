<?php
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_role(['ADMIN']);

$users = db_all("SELECT * FROM utilisateur ORDER BY nom, prenom");

$titre_page = 'Utilisateurs';
require_once __DIR__ . '/../../../layout/header.php';
?>
<div class="page-titre d-flex justify-content-between align-items-center">
  <h4><i class="bi bi-people me-1 text-primary"></i>Utilisateurs</h4>
  <a href="<?= APP_URL ?>/secondaire/pages/utilisateurs/form.php" class="btn btn-primary btn-sm">
    <i class="bi bi-plus-lg me-1"></i>Ajouter
  </a>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-abz table-hover mb-0">
      <thead>
        <tr>
          <th>Nom</th><th>Identifiant</th><th>Rôle</th><th>Statut</th><th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($users)): ?>
          <tr><td colspan="5" class="text-center text-muted py-4">Aucun utilisateur.</td></tr>
        <?php else: foreach ($users as $u): ?>
          <tr>
            <td class="fw-semibold">
              <div><?= h($u['nom']) ?> <?= h($u['prenom'] ?? '') ?></div>
              <small class="text-muted"><?= h($u['email'] ?? '') ?></small>
            </td>
            <td><?= h($u['login']) ?></td>
            <td>
              <?php
              $badges = ['ADMIN'=>'bg-danger','PROVISEUR'=>'bg-primary','CENSEUR'=>'bg-info','PROF'=>'bg-secondary','SECRETAIRE'=>'bg-warning text-dark'];
              $bg = $badges[$u['role']] ?? 'bg-secondary';
              ?>
              <span class="badge <?= $bg ?>"><?= h($u['role']) ?></span>
            </td>
            <td>
              <?php if ($u['actif']): ?>
                <span class="badge bg-success">Actif</span>
              <?php else: ?>
                <span class="badge bg-secondary">Inactif</span>
              <?php endif; ?>
            </td>
            <td class="text-end">
              <a href="<?= APP_URL ?>/secondaire/pages/utilisateurs/form.php?id=<?= $u['id'] ?>" class="btn btn-sm btn-light" style="padding:3px 7px">
                <i class="bi bi-pencil" style="font-size:.78rem"></i>
              </a>
              <?php if ($u['id'] != $_SESSION['user_id']): ?>
              <a href="<?= APP_URL ?>/secondaire/pages/utilisateurs/supprimer.php?id=<?= $u['id'] ?>&csrf=<?= csrf_generer() ?>"
                 class="btn btn-sm btn-light text-danger" style="padding:3px 7px"
                 onclick="return confirm('Supprimer cet utilisateur ?')">
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
<?php require_once __DIR__ . '/../../../layout/footer.php'; ?>
