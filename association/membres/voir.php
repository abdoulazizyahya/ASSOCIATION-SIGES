<?php
// association/membres/voir.php — un membre : identité, mot de passe, et
// grille des accès (global + par école : aucun / lecture seule / écriture).
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/../_layout.php';
exiger_superadmin_association();

$id      = (int) ($_GET['id'] ?? 0);
$moi     = (int) (membre_connecte()['id'] ?? 0);
$detail  = $id ? assoc_membre_detail($id) : null;
if (!$detail) { asso_haut('Membre introuvable'); asso_bas(); exit; }

$msg = ''; $err = '';

// Combien de superadmins au total (pour ne pas supprimer le dernier).
$nb_superadmins = (int) assoc_val(
    "SELECT COUNT(DISTINCT id_membre) FROM membre_acces
     WHERE actif=1 AND id_etablissement IS NULL AND plein_acces=1"
);
$est_superadmin_cible = (bool) assoc_val(
    "SELECT COUNT(*) FROM membre_acces WHERE id_membre=? AND actif=1 AND id_etablissement IS NULL AND plein_acces=1",
    [$id]
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $op = $_POST['op'] ?? '';

    if ($op === 'identite') {
        $in = [
            'nom'    => trim($_POST['nom'] ?? ''),
            'prenom' => trim($_POST['prenom'] ?? ''),
            'email'  => trim($_POST['email'] ?? ''),
            'actif'  => isset($_POST['actif']) ? 1 : 0,
        ];
        if ($id === $moi && !$in['actif']) {
            $err = "Vous ne pouvez pas désactiver votre propre compte.";
        } else {
            $r = assoc_membre_maj($id, $in);
            $msg = $r['ok'] ? $r['message'] : ''; $err = $r['ok'] ? '' : $r['message'];
            if ($r['ok']) journaliser_action('membre_modifie', null, $detail['login']);
        }
    } elseif ($op === 'mdp') {
        $r = assoc_membre_mot_de_passe($id, (string) ($_POST['pwd'] ?? ''));
        $msg = $r['ok'] ? $r['message'] : ''; $err = $r['ok'] ? '' : $r['message'];
        if ($r['ok']) journaliser_action('membre_mdp', null, $detail['login']);
    } elseif ($op === '2fa_off') {
        // Récupération : un superadmin retire la 2FA d'un membre qui a perdu
        // son téléphone. Le membre devra la réactiver lui-même.
        if (function_exists('assoc_membre_2fa_desactiver')) {
            assoc_membre_2fa_desactiver($id);
            journaliser_action('membre_2fa_off', null, $detail['login'] . ' (par superadmin)');
            $msg = "Double authentification retirée pour ce membre.";
        }
    } elseif ($op === 'acces') {
        $portee = $_POST['portee'] ?? '';       // 'global' ou id établissement
        $niveau = $_POST['niveau'] ?? '';       // 'aucun' | 'lecture' | 'ecriture'
        // Garde-fous : ne pas se retirer soi-même le superadmin ni retirer le dernier.
        $retrait_superadmin = ($portee === 'global' && $niveau !== 'ecriture' && $est_superadmin_cible);
        if ($retrait_superadmin && $id === $moi) {
            $err = "Vous ne pouvez pas retirer votre propre accès superadmin.";
        } elseif ($retrait_superadmin && $nb_superadmins <= 1) {
            $err = "Impossible : c'est le dernier compte superadmin de l'association.";
        } else {
            $r = assoc_acces_definir($id, $portee, $niveau);
            $msg = $r['ok'] ? $r['message'] : ''; $err = $r['ok'] ? '' : $r['message'];
            if ($r['ok']) journaliser_action('membre_acces', ($portee === 'global' ? null : (int) $portee), $detail['login'] . " → $niveau");
        }
    }
    $detail = assoc_membre_detail($id);   // recharger
    $est_superadmin_cible = (bool) assoc_val(
        "SELECT COUNT(*) FROM membre_acces WHERE id_membre=? AND actif=1 AND id_etablissement IS NULL AND plein_acces=1", [$id]);
}

// Niveau courant par portée : map [portee => 'aucun'|'lecture'|'ecriture'].
$niveau_courant = ['global' => 'aucun'];
foreach ($detail['acces'] as $a) {
    if (!$a['actif']) continue;
    $cle = $a['id_etablissement'] === null ? 'global' : (string) $a['id_etablissement'];
    $niveau_courant[$cle] = $a['plein_acces'] ? 'ecriture' : 'lecture';
}
$ecoles = assoc_all("SELECT id, code, nom, actif FROM etablissement ORDER BY actif DESC, nom");

// Rendu d'une ligne de sélection d'accès.
function ligne_acces(string $portee, string $libelle, string $courant, string $csrf): void {
    $opts = [
        'aucun'    => 'Aucun',
        'lecture'  => 'Lecture seule',
        'ecriture' => 'Écriture',
    ];
    echo '<form method="post" class="d-flex align-items-center gap-2 py-1">';
    echo '<input type="hidden" name="csrf" value="' . h($csrf) . '">';
    echo '<input type="hidden" name="op" value="acces">';
    echo '<input type="hidden" name="portee" value="' . h($portee) . '">';
    echo '<div class="flex-grow-1 small">' . h($libelle) . '</div>';
    echo '<select name="niveau" class="form-select form-select-sm" style="width:150px" onchange="this.form.submit()">';
    foreach ($opts as $k => $lab) {
        echo '<option value="' . $k . '"' . ($courant === $k ? ' selected' : '') . '>' . h($lab) . '</option>';
    }
    echo '</select>';
    echo '<noscript><button class="btn btn-outline-light btn-sm">OK</button></noscript>';
    echo '</form>';
}

asso_haut('Membre — ' . $detail['login']);
$csrf = csrf_generer();
?>
<a href="<?= APP_URL ?>/association/membres/index.php" class="small text-decoration-none">← Membres</a>
<?php if ($msg): ?><div class="alert alert-success py-2 small mt-2"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-warning py-2 small mt-2"><?= h($err) ?></div><?php endif; ?>

<div class="row g-3 mt-1">
  <div class="col-12 col-lg-5">
    <div class="asso-card">
      <div class="fw-bold mb-2">Identité</div>
      <form method="post" class="row g-2">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="op" value="identite">
        <div class="col-12">
          <label class="form-label small">Login</label>
          <input class="form-control form-control-sm font-monospace" value="<?= h($detail['login']) ?>" disabled>
        </div>
        <div class="col-6">
          <label class="form-label small">Nom *</label>
          <input name="nom" class="form-control form-control-sm" required value="<?= h($detail['nom']) ?>">
        </div>
        <div class="col-6">
          <label class="form-label small">Prénom</label>
          <input name="prenom" class="form-control form-control-sm" value="<?= h($detail['prenom'] ?? '') ?>">
        </div>
        <div class="col-12">
          <label class="form-label small">Email</label>
          <input name="email" type="email" class="form-control form-control-sm" value="<?= h($detail['email'] ?? '') ?>">
        </div>
        <div class="col-12">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="actif" id="actif" <?= $detail['actif'] ? 'checked' : '' ?>
                   <?= $id === $moi ? 'disabled' : '' ?>>
            <label class="form-check-label small" for="actif">Compte actif
              <?php if ($id === $moi): ?><span class="text-muted2">(votre compte)</span><?php endif; ?>
            </label>
          </div>
        </div>
        <div class="col-12"><button class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Enregistrer</button></div>
      </form>

      <?php if (function_exists('assoc_2fa_disponible') && assoc_2fa_disponible()): ?>
      <hr class="border-secondary my-3">
      <div class="d-flex align-items-center justify-content-between">
        <div class="small">
          <i class="bi bi-shield-<?= $detail['totp_actif'] ? 'check text-success' : 'x text-muted2' ?> me-1"></i>
          Double authentification : <strong><?= $detail['totp_actif'] ? 'active' : 'inactive' ?></strong>
        </div>
        <?php if ($detail['totp_actif']): ?>
        <form method="post" onsubmit="return confirm('Retirer la double authentification de ce membre ? (récupération de compte)');">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="op" value="2fa_off">
          <button class="btn btn-outline-warning btn-sm">Retirer</button>
        </form>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <hr class="border-secondary my-3">
      <div class="fw-bold mb-2">Réinitialiser le mot de passe</div>
      <form method="post" class="d-flex gap-2" onsubmit="return confirm('Redéfinir le mot de passe de ce membre ?');">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="op" value="mdp">
        <input name="pwd" type="text" class="form-control form-control-sm" placeholder="Nouveau mot de passe" required minlength="8" autocomplete="off">
        <button class="btn btn-outline-warning btn-sm text-nowrap">Redéfinir</button>
      </form>
    </div>
  </div>

  <div class="col-12 col-lg-7">
    <div class="asso-card">
      <div class="fw-bold mb-1">Accès</div>
      <div class="small text-muted2 mb-2">
        « Écriture » sur <strong>toutes les écoles</strong> = superadmin de l'association
        (crée/supprime des écoles, gère les membres, frappe les NIU).
        « Lecture seule » = visite sans modification.
      </div>

      <div class="border rounded p-2 mb-2" style="border-color:var(--border)">
        <?php ligne_acces('global', 'Toutes les écoles (accès global)', $niveau_courant['global'], $csrf); ?>
      </div>

      <div class="border rounded p-2" style="border-color:var(--border)">
        <div class="small text-muted2 mb-1">Par école</div>
        <?php foreach ($ecoles as $e):
          $cle = (string) $e['id'];
          $lib = $e['code'] . ' — ' . $e['nom'] . ($e['actif'] ? '' : ' (inactive)');
          ligne_acces($cle, $lib, $niveau_courant[$cle] ?? 'aucun', $csrf);
        endforeach; ?>
      </div>
      <div class="small text-muted2 mt-2">
        L'accès global prime : s'il est défini, les réglages par école sont ignorés à la connexion.
      </div>
    </div>
  </div>
</div>
<?php asso_bas();
