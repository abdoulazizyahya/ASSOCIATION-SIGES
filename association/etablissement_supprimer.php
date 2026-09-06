<?php
// association/etablissement_supprimer.php — suppression DÉFINITIVE d'un
// établissement : ligne d'annuaire + registres liés + BASE MySQL de l'école
// (ou remise au pool). Superadmin uniquement. L'établissement doit être
// inactif. Confirmation par saisie du code + cases à cocher.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
require_once __DIR__ . '/_layout.php';
exiger_superadmin_association();

$id = (int) ($_GET['id'] ?? 0);
$e  = $id ? assoc_one("SELECT * FROM etablissement WHERE id=?", [$id]) : null;
if (!$e) { asso_haut('Établissement introuvable'); asso_bas(); exit; }

$imp = etablissement_impact_suppression($id);
$err = ''; $ok = false; $okmsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $code_saisi     = trim($_POST['confirm_code'] ?? '');
    $comprend_base  = !empty($_POST['comprend_base']);
    $supprimer_niu  = !empty($_POST['supprimer_niu']);

    if ((int) $e['actif'] === 1) {
        $err = "Établissement encore actif — désactivez-le d'abord (Modifier).";
    } elseif (strcasecmp($code_saisi, $e['code']) !== 0) {
        $err = "Le code saisi ne correspond pas à « " . $e['code'] . " ».";
    } elseif (!$comprend_base) {
        $err = "Cochez la case confirmant la suppression de la base de données.";
    } elseif ($imp['niu_courant'] > 0 && !$supprimer_niu) {
        $err = "Cochez la case autorisant la suppression des " . $imp['niu_courant'] . " NIU rattachés.";
    } else {
        $r = supprimer_etablissement($id, ['supprimer_niu' => $supprimer_niu]);
        if ($r['ok']) {
            journaliser_action('etablissement_supprime', null, $e['code'] . ' — ' . $e['nom'] . ' (base ' . $r['db_name'] . ')');
            $ok = true; $okmsg = $r['message'];
        } else {
            $err = $r['message'];
        }
    }
}

asso_haut('Supprimer — ' . $e['nom']);
?>
<a href="<?= APP_URL ?>/association/etablissement.php?id=<?= (int) $e['id'] ?>" class="small text-decoration-none">← Fiche</a>

<?php if ($ok): ?>
  <div class="alert alert-success py-2 small mt-2"><?= h($okmsg) ?></div>
  <a href="<?= APP_URL ?>/association/index.php" class="btn btn-primary btn-sm mt-1">
    <i class="bi bi-arrow-left me-1"></i>Retour aux établissements
  </a>
  <?php asso_bas(); exit; ?>
<?php endif; ?>

<?php if ($err): ?><div class="alert alert-danger py-2 small mt-2"><?= h($err) ?></div><?php endif; ?>

<div class="asso-card mt-2" style="max-width:640px">
  <div class="d-flex align-items-center gap-2 mb-2">
    <i class="bi bi-exclamation-octagon-fill text-danger fs-4"></i>
    <strong>Suppression définitive — action irréversible</strong>
  </div>

  <div class="alert alert-secondary py-2 small">
    <i class="bi bi-shield-check me-1"></i>
    Une <strong>sauvegarde complète</strong> (base + fichiers : logos, signatures, pièces de dossier)
    est écrite dans <span class="font-monospace">bd/sauvegardes/avant_suppression_…</span>
    <em>avant</em> la suppression. Elle permet de restaurer l'école plus tard si besoin
    (<span class="font-monospace">bd/assoc/restaurer_ecole.php</span>).
  </div>

  <div class="small text-muted2 mb-3">
    Code <span class="font-monospace"><?= h($e['code']) ?></span> ·
    base <span class="font-monospace"><?= h($e['db_name']) ?></span>
    <?php if (!$e['actif']): ?>
      · <span class="text-warning">inactif</span>
    <?php else: ?>
      · <span class="badge bg-warning text-dark">ACTIF</span>
    <?php endif; ?>
  </div>

  <div class="asso-card p-0 mb-3">
    <div class="px-3 py-2 small text-muted2 border-bottom" style="border-color:var(--border)">Ce qui sera supprimé</div>
    <table class="table table-sm mb-0 align-middle" style="font-size:.83rem">
      <tbody>
        <tr>
          <td>Base de données <span class="font-monospace"><?= h($imp['db_name']) ?></span></td>
          <td class="text-end">
            <?php if ($imp['pool']): ?>
              <span class="text-info">vidée et remise au pool</span>
            <?php elseif ($imp['db_existe']): ?>
              <span class="text-danger">SUPPRIMÉE</span> · <?= (int) $imp['db_tables'] ?> tables · <?= h((string) $imp['db_mo']) ?> Mo
            <?php else: ?>
              <span class="text-muted2">introuvable sur le serveur (rien à supprimer)</span>
            <?php endif; ?>
          </td>
        </tr>
        <tr>
          <td>NIU — élèves actuellement dans cette école</td>
          <td class="text-end <?= $imp['niu_courant'] ? 'text-danger' : 'text-muted2' ?>"><?= (int) $imp['niu_courant'] ?> supprimé(s)</td>
        </tr>
        <tr>
          <td>NIU — élèves originaires d'ici, aujourd'hui ailleurs</td>
          <td class="text-end text-muted2"><?= (int) $imp['niu_origine'] ?> conservé(s) (lien d'origine retiré)</td>
        </tr>
        <tr>
          <td>Affectations de personnel de l'association</td>
          <td class="text-end text-muted2"><?= (int) $imp['affectations'] ?> supprimée(s) · le personnel central est conservé</td>
        </tr>
        <tr>
          <td>Accès de membres à cette école</td>
          <td class="text-end text-muted2"><?= (int) $imp['acces_membres'] ?> retiré(s)</td>
        </tr>
      </tbody>
    </table>
  </div>

  <?php if ($e['actif']): ?>
    <div class="alert alert-warning py-2 small">
      Cet établissement est <strong>actif</strong>. Ouvrez d'abord
      <a href="<?= APP_URL ?>/association/etablissement_modifier.php?id=<?= (int) $e['id'] ?>">Modifier</a>
      et décochez « Établissement actif », puis revenez ici.
    </div>
  <?php elseif ($imp['db_protegee']): ?>
    <div class="alert alert-warning py-2 small">
      La base <span class="font-monospace"><?= h($imp['db_name']) ?></span> est protégée
      (base principale ou annuaire) — suppression impossible depuis cet écran.
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
          <input class="form-check-input" type="checkbox" name="comprend_base" id="comprend_base" required>
          <label class="form-check-label small" for="comprend_base">
            Je comprends que la base <span class="font-monospace"><?= h($e['db_name']) ?></span>
            et toutes ses données scolaires seront
            <?= $imp['pool'] ? 'effacées (base remise au pool)' : 'définitivement supprimées' ?>.
          </label>
        </div>
      </div>

      <?php if ($imp['niu_courant'] > 0): ?>
      <div class="col-12">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="supprimer_niu" id="supprimer_niu" required>
          <label class="form-check-label small text-danger" for="supprimer_niu">
            Supprimer aussi les <?= (int) $imp['niu_courant'] ?> NIU (identités élèves) rattachés à cette école — irréversible.
          </label>
        </div>
      </div>
      <?php endif; ?>

      <div class="col-12">
        <button class="btn btn-danger btn-sm" onclick="return confirm('Supprimer définitivement « <?= h(addslashes($e['nom'])) ?> » et sa base de données ?');">
          <i class="bi bi-trash3 me-1"></i>Supprimer définitivement
        </button>
        <a href="<?= APP_URL ?>/association/etablissement.php?id=<?= (int) $e['id'] ?>" class="btn btn-outline-light btn-sm ms-2">Annuler</a>
      </div>
    </form>
  <?php endif; ?>
</div>
<?php asso_bas();
