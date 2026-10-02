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
//
// Onglets « Comptes »/« Rôles et privilèges » essayés le 15/09/2026 puis
// abandonnés le jour même (demande explicite : pas assez de contenu propre
// à l'onglet privilèges pour justifier la séparation — les actions étaient
// de toute façon liées au même compte, sur la même ligne) : retour à une
// page unique, toutes les actions par compte sur la même ligne.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'changer_role') {
    csrf_verifier();
    $id_user   = (int) post('id_user');
    $nouveau   = post('role');
    // FONDATEUR est attribué uniquement par le superadmin association
    // (association/personnel/affecter.php) — jamais depuis cet écran école,
    // sauf le superadmin lui-même en visite écriture (fonctions_assignables()).
    $roles_ok  = fonctions_assignables();
    $fct_cible = (string) db_val("SELECT e.id_fonction FROM user u JOIN enseignant e ON e.matricule_ens=u.matricule_ens WHERE u.id_user=?", [$id_user]);
    if ($id_user && !compte_gerable($fct_cible, $id_user)) {
        flash_set('erreur', refus_compte_non_gerable($fct_cible));
    } elseif ($id_user === (int) ($_SESSION['user_id'] ?? 0) && role_connecte() === 'DIRECTEUR') {
        flash_set('erreur', 'Vous ne pouvez pas changer votre propre rôle.');
    } elseif ($id_user && in_array($nouveau, $roles_ok, true)) {
        $mat = db_val("SELECT matricule_ens FROM user WHERE id_user=?", [$id_user]);
        if ($mat && $nouveau === 'DIRECTEUR' && ($refus = refus_second_chef((int) $mat)) !== '') {
            flash_set('erreur', $refus);   // un seul directeur par école
        } elseif ($mat) {
            $login_cible = db_val("SELECT login_user FROM user WHERE id_user=?", [$id_user]);
            db_exec("UPDATE enseignant SET id_fonction=? WHERE matricule_ens=?", [$nouveau, $mat]);
            journaliser_action('role_modifie', null, ($login_cible ? $login_cible . ' → ' : '') . libelle_role($nouveau));
            flash_set('succes', 'Rôle mis à jour : ' . libelle_role($nouveau) . '.');
        }
    } else {
        flash_set('erreur', 'Rôle invalide.');
    }
    rediriger('pages/utilisateurs/liste.php');
}

// Activer/désactiver un compte (colonne user.actif, migration_v54) — un
// compte désactivé ne peut plus se connecter (voir login.php). Ouvert à
// DIRECTEUR et FONDATEUR (même accès que le reste de cette page), sans
// restriction de rôle : le fondateur peut désactiver n'importe quel compte,
// y compris Comptable (demande explicite du 21/09/2026). Autodésactivation
// bloquée pour ne pas se retrouver enfermé dehors.
$a_statut = db_colonne_existe('user', 'actif');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'toggle_actif') {
    csrf_verifier();
    $id_user = (int) post('id_user');
    if (!$a_statut) {
        flash_set('erreur', "Cette fonctionnalité nécessite la mise à jour de la base (colonne user.actif).");
    } elseif ($id_user === (int) ($_SESSION['user_id'] ?? 0)) {
        flash_set('erreur', 'Vous ne pouvez pas désactiver votre propre compte.');
    } elseif ($id_user && !compte_gerable($fct = (string) db_val("SELECT e.id_fonction FROM user u JOIN enseignant e ON e.matricule_ens=u.matricule_ens WHERE u.id_user=?", [$id_user]), $id_user)) {
        flash_set('erreur', refus_compte_non_gerable($fct));
    } elseif ($id_user) {
        $actuel = (int) db_val("SELECT actif FROM user WHERE id_user=?", [$id_user]);
        $nouveau = $actuel ? 0 : 1;
        db_exec("UPDATE user SET actif=? WHERE id_user=?", [$nouveau, $id_user]);
        $login_cible = db_val("SELECT login_user FROM user WHERE id_user=?", [$id_user]);
        journaliser_action($nouveau ? 'compte_active' : 'compte_desactive', null, $login_cible);
        flash_set('succes', $nouveau ? 'Compte activé.' : 'Compte désactivé.');
    }
    rediriger('pages/utilisateurs/liste.php');
}

$roles_disponibles = fonctions_assignables();

$col_actif = $a_statut ? ', u.actif' : '';
$utilisateurs = db_all(
    "SELECT u.id_user, u.login_user, u.matricule_ens, e.nom_ens, e.prenom_ens, e.id_fonction $col_actif
     FROM user u JOIN enseignant e ON e.matricule_ens=u.matricule_ens
     ORDER BY e.id_fonction, e.nom_ens"
);
// Les comptes qu'il ne peut pas gérer (autre directeur, fondateur) ne sont
// même pas affichés au directeur (demande du 01/10/2026).
$utilisateurs = array_values(array_filter($utilisateurs,
    fn($u) => compte_gerable((string) $u['id_fonction'], (int) $u['id_user'])));

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
        <tr>
          <th>Identifiant</th><th>Personne</th><th>Rôle</th>
          <?php if ($a_statut): ?><th class="text-center">Statut</th><?php endif; ?>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($utilisateurs)): ?>
          <tr><td colspan="<?= $a_statut ? 5 : 4 ?>" class="text-center text-muted py-4">Aucun compte trouvé.</td></tr>
        <?php else: foreach ($utilisateurs as $u): ?>
          <tr>
            <td class="fw-semibold"><?= h($u['login_user']) ?></td>
            <td><?= h(mb_strtoupper($u['nom_ens'])) ?> <?= h($u['prenom_ens'] ?? '') ?></td>
            <td><span class="badge-code"><?= h(libelle_role($u['id_fonction'] ?? '')) ?></span></td>
            <?php if ($a_statut): ?>
            <td class="text-center">
              <?php if ($u['actif']): ?>
                <span class="badge" style="background:#d1fae5;color:#065f46;font-size:.7rem">Actif</span>
              <?php else: ?>
                <span class="badge" style="background:#f3f4f6;color:#6b7280;font-size:.7rem">Inactif</span>
              <?php endif; ?>
            </td>
            <?php endif; ?>
            <?php
              $moi     = (int) $u['id_user'] === (int) ($_SESSION['user_id'] ?? 0);
              $gerable = compte_gerable((string) $u['id_fonction'], (int) $u['id_user']);
            ?>
            <td class="text-end">
              <?php if (!$gerable): ?>
                <span class="text-muted" style="font-size:.75rem" title="<?= h(refus_compte_non_gerable((string) $u['id_fonction'])) ?>">
                  <i class="bi bi-lock me-1"></i>Géré par le fondateur / l'association
                </span>
              <?php elseif ($moi && role_connecte() === 'DIRECTEUR'): ?>
              <a href="<?= APP_URL ?>/pages/utilisateurs/form.php?id=<?= (int)$u['id_user'] ?>"
                 class="btn btn-sm btn-light" style="padding:3px 7px" title="Changer mon identifiant / mot de passe">
                <i class="bi bi-key" style="font-size:.78rem"></i>
              </a>
              <?php else: ?>
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
              <?php if ($a_statut && $u['id_user'] != ($_SESSION['user_id'] ?? 0)): ?>
              <form method="post" style="display:inline">
                <?= csrf_champ() ?>
                <input type="hidden" name="action" value="toggle_actif">
                <input type="hidden" name="id_user" value="<?= (int) $u['id_user'] ?>">
                <button type="submit" class="btn btn-sm btn-light <?= $u['actif'] ? 'text-warning' : 'text-success' ?>"
                        style="padding:3px 7px" title="<?= $u['actif'] ? 'Désactiver le compte' : 'Activer le compte' ?>"
                        onclick="return confirm('<?= $u['actif'] ? 'Désactiver' : 'Activer' ?> ce compte ?')">
                  <i class="bi bi-<?= $u['actif'] ? 'toggle-on' : 'toggle-off' ?>" style="font-size:.78rem"></i>
                </button>
              </form>
              <?php endif; ?>
              <?php if ($u['id_user'] != ($_SESSION['user_id'] ?? 0)): ?>
              <a href="<?= APP_URL ?>/pages/utilisateurs/supprimer.php?id=<?= (int)$u['id_user'] ?>&csrf=<?= csrf_generer() ?>"
                 class="btn btn-sm btn-light text-danger" style="padding:3px 7px"
                 onclick="return confirm('Supprimer ce compte ?')">
                <i class="bi bi-trash" style="font-size:.78rem"></i>
              </a>
              <?php endif; ?>
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
