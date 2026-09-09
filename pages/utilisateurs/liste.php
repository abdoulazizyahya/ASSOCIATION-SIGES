<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_role(['DIRECTEUR']);

// Changement de rôle (privilège système) — demande explicite du 22/08/2026 :
// avant ce chantier, le SEUL moyen de changer le "rôle" d'un compte était de
// modifier le champ "Fonction" de sa fiche personnel (Ressources humaines >
// Enseignant(e)s), très peu découvrable depuis cet écran "Utilisateurs" où
// un administrateur va naturellement chercher à gérer les privilèges. Le
// rôle reste porté par enseignant.id_fonction (un compte = une fiche liée,
// voir user.matricule_ens NOT NULL) : ce formulaire modifie donc CETTE
// colonne pour la fiche liée au compte, pas une colonne séparée sur `user`.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'changer_role') {
    csrf_verifier();
    $id_user   = (int) post('id_user');
    $nouveau   = post('role');
    // FONDATEUR est attribué uniquement par le superadmin association
    // (association/personnel/affecter.php) — jamais depuis cet écran école.
    $roles_ok  = array_values(array_diff(
        array_column(db_all("SELECT id_fonction FROM fonction"), 'id_fonction'),
        ['FONDATEUR']
    ));
    if ($id_user && in_array($nouveau, $roles_ok, true)) {
        $mat = db_val("SELECT matricule_ens FROM user WHERE id_user=?", [$id_user]);
        if ($mat) {
            db_exec("UPDATE enseignant SET id_fonction=? WHERE matricule_ens=?", [$nouveau, $mat]);
            flash_set('succes', 'Rôle mis à jour : ' . libelle_role($nouveau) . '.');
        }
    } else {
        flash_set('erreur', 'Rôle invalide.');
    }
    rediriger('pages/utilisateurs/liste.php');
}

$roles_disponibles = array_values(array_diff(
    array_column(db_all("SELECT id_fonction FROM fonction ORDER BY id_fonction"), 'id_fonction'),
    ['FONDATEUR']
));

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
    <div class="sub"><?= count($utilisateurs) ?> compte(s) — identifiant, mot de passe, rôle et privilèges d'accès (menus)</div>
  </div>
  <a href="<?= APP_URL ?>/pages/utilisateurs/form.php" class="btn btn-primary btn-sm">
    <i class="bi bi-plus-lg me-1"></i>Nouveau compte
  </a>
</div>

<?= flash_html() ?>

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
              <button type="button" class="btn btn-sm btn-light" style="padding:3px 7px" title="Modifier le rôle (privilèges)"
                      onclick='ouvrirRole(<?= (int) $u['id_user'] ?>, <?= json_encode($u['id_fonction']) ?>, <?= json_encode(mb_strtoupper($u['nom_ens']) . ' ' . ($u['prenom_ens'] ?? '')) ?>)'>
                <i class="bi bi-shield-lock" style="font-size:.78rem"></i>
              </button>
              <a href="<?= APP_URL ?>/pages/utilisateurs/acces.php?id=<?= (int)$u['id_user'] ?>"
                 class="btn btn-sm btn-light" style="padding:3px 7px" title="Privilèges — menus et sous-menus visibles">
                <i class="bi bi-sliders" style="font-size:.78rem"></i>
              </a>
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

<!-- Modale Modifier le rôle -->
<div class="modal fade" id="modalRole" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content" style="border-radius:12px;overflow:hidden">
      <div class="modal-header py-2" style="background:#f8faff">
        <h6 class="modal-title fw-bold" style="font-size:.85rem">
          <i class="bi bi-shield-lock me-1 text-primary"></i>Modifier le rôle
        </h6>
        <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal"></button>
      </div>
      <form method="post">
        <?= csrf_champ() ?>
        <input type="hidden" name="action" value="changer_role">
        <input type="hidden" name="id_user" id="role-uid">
        <div class="modal-body">
          <div class="mb-2" style="font-size:.72rem;color:#6b7280">
            Compte : <strong id="role-nom-affiche"></strong>
          </div>
          <label class="form-label">Rôle (privilèges dans le système)</label>
          <select name="role" id="role-select" class="form-select" required>
            <?php foreach ($roles_disponibles as $r): ?>
              <option value="<?= h($r) ?>"><?= h(libelle_role($r)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="modal-footer py-2">
          <button class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Enregistrer</button>
          <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Annuler</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function ouvrirRole(idUser, roleActuel, nomAffiche) {
    document.getElementById('role-uid').value = idUser;
    document.getElementById('role-select').value = roleActuel;
    document.getElementById('role-nom-affiche').textContent = nomAffiche;
    new bootstrap.Modal(document.getElementById('modalRole')).show();
}
</script>

<?php require_once __DIR__ . '/../../layout/footer.php'; ?>
