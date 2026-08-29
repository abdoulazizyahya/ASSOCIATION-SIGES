<?php
// association/personnel/fiche.php — identité d'un agent + historique d'affectations
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/../_layout.php';
exiger_membre_association();

$mat = (string) ($_GET['m'] ?? '');
$p   = $mat !== '' ? assoc_one("SELECT * FROM personnel WHERE matricule=?", [$mat]) : null;
if (!$p) { asso_haut('Agent'); echo '<div class="asso-card">Agent introuvable.</div>'; asso_bas(); exit; }

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cloturer') {
    csrf_verifier();
    cloturer_affectation((int) $_POST['id_affectation']);
    journaliser_action('affectation_cloturee', null, $mat);
    $msg = 'Affectation clôturée.';
}

$affs = assoc_all(
    "SELECT a.*, e.nom AS ecole, e.code
     FROM personnel_affectation a JOIN etablissement e ON e.id = a.id_etablissement
     WHERE a.matricule=? ORDER BY a.actif DESC, a.date_debut DESC", [$mat]
);

asso_haut('Agent — ' . trim($p['nom'] . ' ' . $p['prenom']));
?>
<a href="<?= APP_URL ?>/association/personnel/liste.php" class="small text-decoration-none">← Personnel</a>
<?php if ($msg): ?><div class="alert alert-success py-2 small mt-2"><?= h($msg) ?></div><?php endif; ?>

<div class="asso-card mt-2 mb-3">
  <div class="row small">
    <div class="col-6 col-md-3"><span class="text-muted2">Matricule</span><br><span class="font-monospace"><?= h($p['matricule']) ?></span></div>
    <div class="col-6 col-md-3"><span class="text-muted2">Sexe</span><br><?= h($p['sexe'] ?: '—') ?></div>
    <div class="col-6 col-md-3"><span class="text-muted2">Naissance</span><br><?= h($p['date_naissance'] ?: '—') ?></div>
    <div class="col-6 col-md-3"><span class="text-muted2">Statut</span><br><?= h($p['statut']) ?></div>
    <div class="col-6 col-md-3 mt-2"><span class="text-muted2">Tél.</span><br><?= h($p['tel'] ?: '—') ?></div>
    <div class="col-6 col-md-3 mt-2"><span class="text-muted2">Email</span><br><?= h($p['email'] ?: '—') ?></div>
  </div>
</div>

<h2 class="h6 fw-bold">Affectations</h2>
<div class="asso-card p-0">
  <table class="table table-dark table-sm mb-0 align-middle" style="font-size:.83rem">
    <thead><tr><th>École</th><th>Fonction</th><th>Depuis</th><th>Jusqu'au</th><th>État</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($affs as $a): ?>
        <tr>
          <td><?= h($a['ecole']) ?> <span class="text-muted2">(<?= h($a['code']) ?>)</span></td>
          <td><?= h($a['fonction']) ?></td>
          <td><?= h($a['date_debut'] ?: '—') ?></td>
          <td><?= h($a['date_fin'] ?: '—') ?></td>
          <td><?= $a['actif'] ? '<span class="text-success">active</span>' : '<span class="text-muted2">clôturée</span>' ?></td>
          <td class="text-end">
            <?php if ($a['actif']): ?>
            <form method="post" onsubmit="return confirm('Clôturer cette affectation ? Le compte sera désactivé dans l\'école.');" class="d-inline">
              <input type="hidden" name="csrf" value="<?= h(csrf_generer()) ?>">
              <input type="hidden" name="action" value="cloturer">
              <input type="hidden" name="id_affectation" value="<?= (int) $a['id'] ?>">
              <button class="btn btn-outline-danger btn-sm py-0">Clôturer</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<p class="mt-3">
  <a class="btn btn-primary btn-sm" href="<?= APP_URL ?>/association/personnel/affecter.php?m=<?= urlencode($mat) ?>">
    <i class="bi bi-plus-lg me-1"></i>Nouvelle affectation
  </a>
</p>
<?php asso_bas();
