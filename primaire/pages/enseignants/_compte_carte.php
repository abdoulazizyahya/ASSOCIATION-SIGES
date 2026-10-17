<?php
// primaire/pages/enseignants/_compte_carte.php — carte « Compte de connexion » de la fiche
// du personnel (incluse par voir.php ; attend $mat et $ens). Actions traitées par compte.php.
// Le rôle du compte est la fonction de la fiche : il change en modifiant la fiche.
if (!in_array(role_connecte(), ['DIRECTEUR', 'FONDATEUR'], true) && !voit_tous_les_comptes()) return;
$cpt = prim_compte_de_fiche((int) $mat);
$fiche_ok = fiche_gerable((int) $mat, (string) ($ens['id_fonction'] ?? ''));
$cpt_ok = $cpt && compte_gerable((string) ($ens['id_fonction'] ?? ''), (int) $cpt['id_user']);
$cpt_soi = $cpt && (int) $cpt['id_user'] === (int) ($_SESSION['user_id'] ?? 0);
$url_cpt = APP_URL . '/primaire/pages/enseignants/compte.php';
?>
<div class="card mb-2" id="compte">
  <div class="card-header fw-semibold"><i class="bi bi-key me-1"></i>Compte de connexion</div>
  <div class="card-body" style="font-size:.83rem">
    <?php if (!$cpt): ?>
      <p class="text-muted mb-2">Aucun compte : cette personne ne peut pas se connecter.</p>
      <?php if ($fiche_ok): ?>
      <form method="post" action="<?= $url_cpt ?>">
        <?= csrf_champ() ?><input type="hidden" name="action" value="creer"><input type="hidden" name="mat" value="<?= (int) $mat ?>">
        <button class="btn btn-primary btn-sm w-100"><i class="bi bi-person-plus me-1"></i>Créer le compte</button>
      </form>
      <div class="form-text" style="font-size:.72rem">Identifiant tiré du nom, mot de passe identique, rôle <?= h(libelle_role((string) ($ens['id_fonction'] ?? ''))) ?>.</div>
      <?php endif; ?>
    <?php else: ?>
      <div class="mb-1"><span class="text-muted">Identifiant :</span> <code class="fw-bold"><?= h($cpt['login_user']) ?></code></div>
      <div class="mb-1"><span class="text-muted">Rôle :</span> <?= h(libelle_role((string) ($ens['id_fonction'] ?? ''))) ?> <span class="text-muted" style="font-size:.72rem">(fonction de la fiche)</span></div>
      <div class="mb-2"><span class="text-muted">État :</span>
        <span class="badge <?= $cpt['actif'] ? 'bg-success' : 'bg-secondary' ?>"><?= $cpt['actif'] ? 'Actif' : 'Désactivé' ?></span>
        <?php if (!empty($cpt['derniere_connexion'])): ?><span class="text-muted" style="font-size:.72rem"> · dernière connexion <?= h(date_fr(substr((string) $cpt['derniere_connexion'], 0, 10))) ?></span><?php endif; ?>
      </div>
      <?php if ($cpt_ok): ?>
        <form method="post" action="<?= $url_cpt ?>" class="mb-1"
              onsubmit="return confirm('Remettre le mot de passe à « <?= h($cpt['login_user']) ?> » (identique à l’identifiant) ?')">
          <?= csrf_champ() ?><input type="hidden" name="action" value="reinitialiser">
          <input type="hidden" name="mat" value="<?= (int) $mat ?>"><input type="hidden" name="id" value="<?= (int) $cpt['id_user'] ?>">
          <button class="btn btn-outline-warning btn-sm w-100"><i class="bi bi-arrow-counterclockwise me-1"></i>Réinitialiser le mot de passe</button>
        </form>
        <button class="btn btn-outline-primary btn-sm w-100" type="button" data-bs-toggle="collapse" data-bs-target="#cptModif">
          <i class="bi bi-pencil me-1"></i>Modifier le compte
        </button>
        <form method="post" action="<?= $url_cpt ?>" class="collapse mt-2" id="cptModif">
          <?= csrf_champ() ?><input type="hidden" name="action" value="modifier">
          <input type="hidden" name="mat" value="<?= (int) $mat ?>"><input type="hidden" name="id" value="<?= (int) $cpt['id_user'] ?>">
          <label class="form-label mb-0" style="font-size:.75rem">Identifiant</label>
          <input type="text" name="login" class="form-control form-control-sm mb-1" value="<?= h($cpt['login_user']) ?>" required pattern="[a-z0-9._@\-]{3,50}">
          <label class="form-label mb-0" style="font-size:.75rem">Nouveau mot de passe <span class="text-muted">(vide = inchangé)</span></label>
          <input type="password" name="mot_de_passe" class="form-control form-control-sm mb-1" autocomplete="new-password" minlength="4">
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" name="actif" value="1" id="cptActif" <?= $cpt['actif'] ? 'checked' : '' ?> <?= $cpt_soi ? 'disabled' : '' ?>>
            <label class="form-check-label" for="cptActif">Compte actif</label>
          </div>
          <button class="btn btn-primary btn-sm w-100"><i class="bi bi-check-lg me-1"></i>Enregistrer</button>
        </form>
      <?php else: ?>
        <p class="text-muted mb-0" style="font-size:.75rem"><?= h(refus_compte_non_gerable((string) ($ens['id_fonction'] ?? ''))) ?></p>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
