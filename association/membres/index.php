<?php
// association/membres/index.php — liste des membres de l'association +
// création. Édition d'un membre et de ses accès : membres/voir.php.
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/../_layout.php';
exiger_superadmin_association();

$msg = ''; $err = '';
$val = ['login' => '', 'nom' => '', 'prenom' => '', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['op'] ?? '') === 'creer') {
    csrf_verifier();
    foreach ($val as $k => $_) $val[$k] = trim($_POST[$k] ?? '');
    $r = assoc_membre_creer($val + ['pwd' => (string) ($_POST['pwd'] ?? '')]);
    if ($r['ok']) {
        journaliser_action('membre_creation', null, $val['login']);
        $msg = $r['message'];
        $val = ['login' => '', 'nom' => '', 'prenom' => '', 'email' => ''];
    } else {
        $err = $r['message'];
    }
}

$membres = assoc_membres_liste();
$deuxfa  = function_exists('assoc_2fa_disponible') && assoc_2fa_disponible();

asso_haut('Membres de l\'association');
?>
<?php if ($msg): ?><div class="alert alert-success py-2 small"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-warning py-2 small"><?= h($err) ?></div><?php endif; ?>

<div class="asso-card p-0 mb-3">
  <div class="table-responsive">
  <table class="table table-sm mb-0 align-middle" style="font-size:.85rem">
    <thead><tr class="text-muted2">
      <th>Login</th><th>Nom</th><th>Droits</th><th>État</th><?php if ($deuxfa): ?><th>2FA</th><?php endif; ?><th></th>
    </tr></thead>
    <tbody>
      <?php foreach ($membres as $m): ?>
        <tr class="<?= $m['actif'] ? '' : 'opacity-50' ?>">
          <td class="font-monospace"><?= h($m['login']) ?></td>
          <td><?= h(trim(($m['prenom'] ?? '') . ' ' . $m['nom'])) ?>
              <?php if ($m['email']): ?><div class="small text-muted2"><?= h($m['email']) ?></div><?php endif; ?>
          </td>
          <td>
            <?php if (!empty($m['proprietaire'])): ?>
              <span class="badge bg-warning text-dark"><i class="bi bi-key-fill me-1"></i>Propriétaire</span>
            <?php elseif ($m['superadmin']): ?>
              <span class="badge bg-primary">Superadmin</span>
            <?php elseif ($m['global_lecture']): ?>
              <span class="badge badge-soft">Toutes écoles · lecture</span>
            <?php elseif ($m['nb_ecoles']): ?>
              <span class="badge badge-soft"><?= (int) $m['nb_ecoles'] ?> école(s)</span>
            <?php else: ?>
              <span class="text-muted2 small">aucun accès</span>
            <?php endif; ?>
          </td>
          <td><?= $m['actif'] ? '<span class="text-success small">actif</span>' : '<span class="text-warning small">désactivé</span>' ?></td>
          <?php if ($deuxfa): ?>
            <td><?= !empty($m['totp_actif']) ? '<i class="bi bi-shield-check text-success"></i>' : '<span class="text-muted2">—</span>' ?></td>
          <?php endif; ?>
          <td class="text-end">
            <a href="<?= APP_URL ?>/association/membres/voir.php?id=<?= (int) $m['id'] ?>" class="btn btn-outline-light btn-sm">
              <i class="bi bi-pencil me-1"></i>Gérer
            </a>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<div class="asso-card" style="max-width:560px">
  <div class="fw-bold mb-2"><i class="bi bi-person-plus me-1"></i>Nouveau membre</div>
  <form method="post" class="row g-2">
    <input type="hidden" name="csrf" value="<?= h(csrf_generer()) ?>">
    <input type="hidden" name="op" value="creer">
    <div class="col-6">
      <label class="form-label small">Login *</label>
      <input name="login" class="form-control form-control-sm font-monospace" required
             maxlength="50" pattern="[A-Za-z0-9._-]{3,50}" value="<?= h($val['login']) ?>">
      <div class="form-text small text-muted2">3–50 · a-z 0-9 . _ -</div>
    </div>
    <div class="col-6">
      <label class="form-label small">Mot de passe *</label>
      <input name="pwd" type="text" class="form-control form-control-sm" required minlength="8" autocomplete="off">
      <div class="form-text small text-muted2">8 caractères minimum</div>
    </div>
    <div class="col-6">
      <label class="form-label small">Nom *</label>
      <input name="nom" class="form-control form-control-sm" required maxlength="100" value="<?= h($val['nom']) ?>">
    </div>
    <div class="col-6">
      <label class="form-label small">Prénom</label>
      <input name="prenom" class="form-control form-control-sm" maxlength="100" value="<?= h($val['prenom']) ?>">
    </div>
    <div class="col-12">
      <label class="form-label small">Email</label>
      <input name="email" type="email" class="form-control form-control-sm" maxlength="150" value="<?= h($val['email']) ?>">
    </div>
    <div class="col-12 mt-2">
      <button class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Créer le membre</button>
      <span class="small text-muted2 ms-2">
        Le membre est créé sans accès. Réglez ensuite ses droits par école via « Gérer ».
        <?php if (!est_proprietaire_association()): ?>
          Le niveau <strong>superadmin</strong> ne peut être accordé que par le propriétaire de l'association.
        <?php endif; ?>
      </span>
    </div>
  </form>
</div>
<?php asso_bas();
