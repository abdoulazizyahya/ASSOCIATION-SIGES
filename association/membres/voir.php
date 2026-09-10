<?php
// association/membres/voir.php — un membre : identité, mot de passe, et
// grille des accès (global + par école : aucun / lecture seule / écriture).
//
// Hiérarchie : le PROPRIÉTAIRE de l'association (membre.proprietaire) est le
// seul à pouvoir accorder / retirer le niveau superadmin (accès global en
// écriture) et à transférer la propriété. Un superadmin « simple » gère les
// membres et les accès par école, mais pas le tier superadmin, et ne peut
// pas modifier le compte du propriétaire.
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/../_layout.php';
exiger_superadmin_association();

$id      = (int) ($_GET['id'] ?? 0);
$moi     = (int) (membre_connecte()['id'] ?? 0);
$detail  = $id ? assoc_membre_detail($id) : null;
if (!$detail) { asso_haut('Membre introuvable'); asso_bas(); exit; }

$je_suis_proprietaire = est_proprietaire_association();
$cible_proprietaire   = !empty($detail['proprietaire']);
// Un superadmin « simple » ne VOIT pas le compte du propriétaire (sauf si
// c'est lui-même — cas impossible ici, mais gardé pour l'anti-verrouillage).
if ($cible_proprietaire && !$je_suis_proprietaire && $id !== $moi) {
    flash_set('erreur', "Le compte du propriétaire de l'association n'est pas accessible.");
    rediriger('association/membres/index.php');
}
// (verrou historique conservé : plus jamais atteint, mais inoffensif)
$lecture_seule = $cible_proprietaire && !$je_suis_proprietaire && $id !== $moi;

$msg = ''; $err = '';

$nb_superadmins = (int) assoc_val(
    "SELECT COUNT(DISTINCT id_membre) FROM membre_acces
     WHERE actif=1 AND id_etablissement IS NULL AND plein_acces=1"
);
$est_superadmin_cible = (bool) $detail['superadmin_effectif'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $op = $_POST['op'] ?? '';

    if ($lecture_seule) {
        $err = "Le compte du propriétaire ne peut être modifié que par lui-même.";
    } elseif ($op === 'identite') {
        $in = [
            'nom'    => trim($_POST['nom'] ?? ''),
            'prenom' => trim($_POST['prenom'] ?? ''),
            'email'  => trim($_POST['email'] ?? ''),
            'actif'  => isset($_POST['actif']) ? 1 : 0,
        ];
        if ($id === $moi && !$in['actif']) {
            $err = "Vous ne pouvez pas désactiver votre propre compte.";
        } elseif ($cible_proprietaire && !$in['actif']) {
            $err = "Le compte propriétaire ne peut pas être désactivé.";
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
        if (function_exists('assoc_membre_2fa_desactiver')) {
            assoc_membre_2fa_desactiver($id);
            journaliser_action('membre_2fa_off', null, $detail['login'] . ' (par superadmin)');
            $msg = "Double authentification retirée pour ce membre.";
        }
    } elseif ($op === 'acces') {
        $portee = $_POST['portee'] ?? '';
        $niveau = $_POST['niveau'] ?? '';
        $retrait_superadmin = ($portee === 'global' && $niveau !== 'ecriture' && $est_superadmin_cible);
        if ($retrait_superadmin && $id === $moi) {
            $err = "Vous ne pouvez pas retirer votre propre accès superadmin.";
        } elseif ($retrait_superadmin && $cible_proprietaire) {
            $err = "Le propriétaire reste toujours superadmin.";
        } elseif ($retrait_superadmin && $nb_superadmins <= 1) {
            $err = "Impossible : c'est le dernier compte superadmin de l'association.";
        } else {
            $r = assoc_acces_definir($id, $portee, $niveau, $je_suis_proprietaire);
            $msg = $r['ok'] ? $r['message'] : ''; $err = $r['ok'] ? '' : $r['message'];
            if ($r['ok']) journaliser_action('membre_acces', ($portee === 'global' ? null : (int) $portee), $detail['login'] . " → $niveau");
        }
    } elseif ($op === 'transfert_propriete' && $je_suis_proprietaire && assoc_proprietaire_dispo()) {
        // Le propriétaire transmet son rôle à un autre superadmin.
        if ($id === $moi) {
            $err = "Vous êtes déjà propriétaire.";
        } elseif (strcasecmp(trim($_POST['confirm_login'] ?? ''), $detail['login']) !== 0) {
            $err = "Le login saisi ne correspond pas.";
        } else {
            // La cible devient superadmin si elle ne l'est pas déjà.
            assoc_acces_definir($id, 'global', 'ecriture', true);
            assoc_exec("UPDATE membre SET proprietaire = (id = ?) WHERE proprietaire=1 OR id=?", [$id, $id]);
            journaliser_action('membre_proprietaire', null, "propriété transférée à " . $detail['login']);
            $msg = "« {$detail['login']} » est désormais le propriétaire de l'association. Vous restez superadmin.";
        }
    }
    $detail = assoc_membre_detail($id);
    $cible_proprietaire   = !empty($detail['proprietaire']);
    $lecture_seule        = $cible_proprietaire && !$je_suis_proprietaire;
    $est_superadmin_cible = (bool) $detail['superadmin_effectif'];
}

$niveau_courant = ['global' => 'aucun'];
foreach ($detail['acces'] as $a) {
    if (!$a['actif']) continue;
    $cle = $a['id_etablissement'] === null ? 'global' : (string) $a['id_etablissement'];
    $niveau_courant[$cle] = $a['plein_acces'] ? 'ecriture' : 'lecture';
}
if ($cible_proprietaire) $niveau_courant['global'] = 'ecriture';
$ecoles = assoc_all("SELECT id, code, nom, actif FROM etablissement ORDER BY actif DESC, nom");

// Rendu d'une ligne de sélection d'accès.
//  $verrou : 'off' (éditable) | 'ecriture' (option Écriture désactivée)
//          | 'tout' (select entier désactivé).
function ligne_acces(string $portee, string $libelle, string $courant, string $csrf, string $verrou = 'off'): void {
    $opts = ['aucun' => 'Aucun', 'lecture' => 'Lecture seule', 'ecriture' => 'Écriture'];
    echo '<form method="post" class="d-flex align-items-center gap-2 py-1">';
    echo '<input type="hidden" name="csrf" value="' . h($csrf) . '">';
    echo '<input type="hidden" name="op" value="acces">';
    echo '<input type="hidden" name="portee" value="' . h($portee) . '">';
    echo '<div class="flex-grow-1 small">' . h($libelle) . '</div>';
    echo '<select name="niveau" class="form-select form-select-sm" style="width:150px"'
       . ($verrou === 'tout' ? ' disabled' : ' onchange="this.form.submit()"') . '>';
    foreach ($opts as $k => $lab) {
        $dis = ($verrou === 'ecriture' && $k === 'ecriture') ? ' disabled' : '';
        echo '<option value="' . $k . '"' . ($courant === $k ? ' selected' : '') . $dis . '>' . h($lab) . '</option>';
    }
    echo '</select>';
    echo '<noscript><button class="btn btn-outline-primary btn-sm">OK</button></noscript>';
    echo '</form>';
}

asso_haut('Membre — ' . $detail['login']);
$csrf = csrf_generer();
?>
<a href="<?= APP_URL ?>/association/membres/index.php" class="small text-decoration-none">← Membres</a>
<?php if ($msg): ?><div class="alert alert-success py-2 small mt-2"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-warning py-2 small mt-2"><?= h($err) ?></div><?php endif; ?>

<div class="d-flex flex-wrap align-items-center gap-2 mt-2 mb-1">
  <?php if ($cible_proprietaire): ?><span class="badge bg-warning text-dark"><i class="bi bi-key-fill me-1"></i>Propriétaire</span>
  <?php elseif ($est_superadmin_cible): ?><span class="badge bg-primary">Superadmin</span><?php endif; ?>
  <?php if ($lecture_seule): ?><span class="small text-muted2">consultation — modifiable seulement par le propriétaire</span><?php endif; ?>
</div>

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
          <input name="nom" class="form-control form-control-sm" required value="<?= h($detail['nom']) ?>" <?= $lecture_seule ? 'disabled' : '' ?>>
        </div>
        <div class="col-6">
          <label class="form-label small">Prénom</label>
          <input name="prenom" class="form-control form-control-sm" value="<?= h($detail['prenom'] ?? '') ?>" <?= $lecture_seule ? 'disabled' : '' ?>>
        </div>
        <div class="col-12">
          <label class="form-label small">Email</label>
          <input name="email" type="email" class="form-control form-control-sm" value="<?= h($detail['email'] ?? '') ?>" <?= $lecture_seule ? 'disabled' : '' ?>>
        </div>
        <div class="col-12">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="actif" id="actif" <?= $detail['actif'] ? 'checked' : '' ?>
                   <?= ($id === $moi || $cible_proprietaire || $lecture_seule) ? 'disabled' : '' ?>>
            <label class="form-check-label small" for="actif">Compte actif
              <?php if ($id === $moi): ?><span class="text-muted2">(votre compte)</span>
              <?php elseif ($cible_proprietaire): ?><span class="text-muted2">(compte propriétaire)</span><?php endif; ?>
            </label>
          </div>
        </div>
        <?php if (!$lecture_seule): ?>
        <div class="col-12"><button class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Enregistrer</button></div>
        <?php endif; ?>
      </form>

      <?php if (!$lecture_seule && function_exists('assoc_2fa_disponible') && assoc_2fa_disponible()): ?>
      <hr class="my-3">
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

      <?php if (!$lecture_seule): ?>
      <hr class="my-3">
      <div class="fw-bold mb-2">Réinitialiser le mot de passe</div>
      <form method="post" class="d-flex gap-2" onsubmit="return confirm('Redéfinir le mot de passe de ce membre ?');">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="op" value="mdp">
        <input name="pwd" type="text" class="form-control form-control-sm" placeholder="Nouveau mot de passe" required minlength="8" autocomplete="off">
        <button class="btn btn-outline-warning btn-sm text-nowrap">Redéfinir</button>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-12 col-lg-7">
    <div class="asso-card">
      <div class="fw-bold mb-1">Accès</div>
      <div class="small text-muted2 mb-2">
        « Écriture » sur <strong>toutes les écoles</strong> = <strong>superadmin</strong>
        (crée/supprime des écoles, gère les membres, frappe les NIU) —
        <?php if ($je_suis_proprietaire): ?>vous seul (propriétaire) pouvez l'accorder ou le retirer.
        <?php else: ?>réservé au propriétaire de l'association.<?php endif; ?>
        « Lecture seule » = visite sans modification.
      </div>

      <?php
        // Verrou de la ligne globale pour un non-propriétaire :
        //  - cible déjà superadmin → select entier verrouillé (pas de rétrogradation) ;
        //  - sinon → option « Écriture » désactivée (pas de promotion superadmin).
        $verrou_global = 'off';
        if (!$je_suis_proprietaire) $verrou_global = $est_superadmin_cible ? 'tout' : 'ecriture';
        if ($lecture_seule)         $verrou_global = 'tout';
      ?>
      <div class="border rounded p-2 mb-2">
        <?php ligne_acces('global', 'Toutes les écoles (accès global)', $niveau_courant['global'], $csrf, $verrou_global); ?>
      </div>

      <div class="border rounded p-2">
        <div class="small text-muted2 mb-1">Par école</div>
        <?php foreach ($ecoles as $e):
          $cle = (string) $e['id'];
          $lib = $e['code'] . ' — ' . $e['nom'] . ($e['actif'] ? '' : ' (inactive)');
          ligne_acces($cle, $lib, $niveau_courant[$cle] ?? 'aucun', $csrf, $lecture_seule ? 'tout' : 'off');
        endforeach; ?>
      </div>
      <div class="small text-muted2 mt-2">
        L'accès global prime : s'il est défini, les réglages par école sont ignorés à la connexion.
      </div>
    </div>

    <?php if ($je_suis_proprietaire && !$cible_proprietaire && $est_superadmin_cible && assoc_proprietaire_dispo()): ?>
    <div class="asso-card mt-3" style="border-color:#c8960a">
      <div class="fw-bold mb-1"><i class="bi bi-key me-1"></i>Transférer la propriété</div>
      <div class="small text-muted2 mb-2">
        « <?= h($detail['login']) ?> » deviendra le propriétaire de l'association (seul habilité à gérer
        les superadmins). Vous conservez votre accès superadmin. Action réversible par le nouveau propriétaire.
      </div>
      <form method="post" class="d-flex gap-2 align-items-center"
            onsubmit="return confirm('Transférer la propriété de l\'association à « <?= h(addslashes($detail['login'])) ?> » ?');">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="op" value="transfert_propriete">
        <input name="confirm_login" class="form-control form-control-sm font-monospace" style="max-width:200px"
               placeholder="taper « <?= h($detail['login']) ?> »" autocomplete="off" required>
        <button class="btn btn-outline-warning btn-sm text-nowrap">Transférer</button>
      </form>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php asso_bas();
