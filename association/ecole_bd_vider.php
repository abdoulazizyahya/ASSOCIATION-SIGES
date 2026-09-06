<?php
// association/ecole_bd_vider.php — Vider la base d'UNE école : toutes les
// données scolaires sont effacées et la base est réinitialisée au schéma
// de référence (état « école neuve »). Superadmin association uniquement.
// L'établissement doit être inactif. Un backup de sécurité est écrit avant
// (bd/sauvegardes/avant_vidage_<db>_<horo>.sql[.gz]).
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../bd/lib/ecole_maintenance.php';
exiger_superadmin_association();

$id = (int) ($_GET['id'] ?? 0);
$e  = $id ? assoc_one("SELECT * FROM etablissement WHERE id=?", [$id]) : null;
if (!$e) { asso_haut('Établissement introuvable'); asso_bas(); exit; }

$err = ''; $ok = false; $res = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $code_saisi = trim($_POST['confirm_code'] ?? '');
    $comprend   = !empty($_POST['comprend']);

    if ((int) $e['actif'] === 1) {
        $err = "Établissement encore actif — désactivez-le d'abord (Modifier → décocher « actif »).";
    } elseif (strcasecmp($code_saisi, $e['code']) !== 0) {
        $err = "Le code saisi ne correspond pas à « " . $e['code'] . " ».";
    } elseif (!$comprend) {
        $err = "Cochez la case confirmant l'effacement de toutes les données.";
    } else {
        $res = ecole_vider($id);
        if ($res['ok']) {
            journaliser_action('ecole_bd_vidage', $id,
                $e['code'] . ' — ' . $e['db_name'] . ' (' . (int) $res['tables'] . ' tables)');
            $ok = true;
        } else {
            $err = $res['message'];
        }
    }
}

asso_haut('Vider la base — ' . $e['nom']);
?>
<a href="<?= APP_URL ?>/association/etablissement.php?id=<?= (int) $e['id'] ?>" class="small text-decoration-none">← Fiche</a>

<?php if ($ok): ?>
  <div class="alert alert-success py-2 small mt-2"><?= h($res['message']) ?></div>
  <a href="<?= APP_URL ?>/association/etablissement.php?id=<?= (int) $e['id'] ?>" class="btn btn-primary btn-sm mt-1">
    <i class="bi bi-arrow-left me-1"></i>Retour à la fiche
  </a>
  <?php asso_bas(); exit; ?>
<?php endif; ?>

<?php if ($err): ?>
  <div class="alert alert-danger py-2 small mt-2"><?= h($err) ?></div>
  <?php if ($res && !empty($res['backup'])): ?>
    <div class="alert alert-warning py-2 small">
      Un backup de l'état précédent a été écrit :
      <span class="font-monospace"><?= h(basename($res['backup'])) ?></span>
      (dossier <span class="font-monospace">bd/sauvegardes/</span>).
    </div>
  <?php endif; ?>
<?php endif; ?>

<div class="asso-card mt-2" style="max-width:640px;border-color:#7f1d1d">
  <div class="d-flex align-items-center gap-2 mb-2">
    <i class="bi bi-exclamation-octagon-fill text-danger fs-4"></i>
    <strong>Effacement de toutes les données — action irréversible</strong>
  </div>

  <div class="small text-muted2 mb-3">
    Code <span class="font-monospace"><?= h($e['code']) ?></span> ·
    base <span class="font-monospace"><?= h($e['db_name']) ?></span>
    <?php if ((int) $e['actif'] === 1): ?>
      · <span class="badge bg-warning text-dark">ACTIF</span>
    <?php else: ?>
      · <span class="text-warning">inactif</span>
    <?php endif; ?>
  </div>

  <ul class="small text-muted2">
    <li>Élèves, inscriptions, notes, paiements, personnel, années scolaires… <strong>tout est supprimé</strong>.</li>
    <li>La base est recréée à partir du schéma de référence et de l'identité de l'établissement (nom, sigle, ville depuis l'annuaire) — comme une école qui vient d'être créée.</li>
    <li>Un <strong>backup de sécurité</strong> complet est écrit dans <span class="font-monospace">bd/sauvegardes/</span> avant l'effacement.</li>
    <li>L'annuaire association (code, sous-domaine, NIU, personnel, accès membres) n'est pas modifié — les NIU rattachés continueront de pointer vers cette école.</li>
  </ul>

  <?php if ((int) $e['actif'] === 1): ?>
    <div class="alert alert-warning py-2 small">
      Cet établissement est <strong>actif</strong>. Ouvrez d'abord
      <a href="<?= APP_URL ?>/association/etablissement_modifier.php?id=<?= (int) $e['id'] ?>">Modifier</a>,
      décochez « Établissement actif », puis revenez ici.
    </div>
  <?php else: ?>
    <form method="post" class="row g-3">
      <input type="hidden" name="csrf" value="<?= h(csrf_generer()) ?>">

      <div class="col-12">
        <label class="form-label small fw-bold">
          Tapez le code <span class="font-monospace"><?= h($e['code']) ?></span> pour confirmer
        </label>
        <input type="text" name="confirm_code" autocomplete="off" required
               class="form-control form-control-sm font-monospace" style="max-width:220px"
               placeholder="<?= h($e['code']) ?>">
      </div>

      <div class="col-12">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="comprend" id="comprend" required>
          <label class="form-check-label small" for="comprend">
            Je comprends que toutes les données scolaires de la base
            <span class="font-monospace"><?= h($e['db_name']) ?></span> seront définitivement effacées.
          </label>
        </div>
      </div>

      <div class="col-12">
        <button class="btn btn-danger btn-sm"
                onclick="return confirm('Effacer toutes les données de « <?= h(addslashes($e['nom'])) ?> » ?');">
          <i class="bi bi-eraser me-1"></i>Vider la base
        </button>
        <a href="<?= APP_URL ?>/association/etablissement.php?id=<?= (int) $e['id'] ?>" class="btn btn-outline-light btn-sm ms-2">Annuler</a>
      </div>
    </form>
  <?php endif; ?>
</div>
<?php asso_bas();
