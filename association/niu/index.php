<?php
// association/niu/index.php — registre NIU : recherche, liste, doublons
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/../_layout.php';
exiger_membre_association();

$q = trim($_GET['q'] ?? '');

$where = '1';
$params = [];
if ($q !== '') {
    $where = "(en.niu LIKE ? OR en.nom LIKE ? OR en.prenom LIKE ?)";
    $params = ["%$q%", "%$q%", "%$q%"];
}
$lignes = assoc_all(
    "SELECT en.*, o.nom AS ecole_origine, c.nom AS ecole_courante
     FROM eleve_niu en
     LEFT JOIN etablissement o ON o.id = en.id_etab_origine
     LEFT JOIN etablissement c ON c.id = en.id_etab_courant
     WHERE $where ORDER BY en.cree_le DESC LIMIT 300",
    $params
);

// Doublons potentiels : même nom+prénom+date, NIU différents
$doublons = assoc_all(
    "SELECT nom, prenom, date_naissance, COUNT(*) n, GROUP_CONCAT(niu SEPARATOR ' , ') nius
     FROM eleve_niu
     WHERE nom IS NOT NULL AND nom <> ''
     GROUP BY LOWER(nom), LOWER(prenom), date_naissance
     HAVING n > 1 ORDER BY n DESC LIMIT 50"
);

asso_haut('Registre NIU');
?>
<div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
  <a href="<?= APP_URL ?>/association/niu/creer.php" class="btn btn-primary btn-sm">
    <i class="bi bi-plus-lg me-1"></i>Nouveau NIU
  </a>
  <form method="get" class="d-flex gap-2 ms-auto" style="max-width:340px">
    <input type="text" name="q" value="<?= h($q) ?>" class="form-control form-control-sm"
           placeholder="NIU, nom, prénom…" data-filtre>
    <button class="btn btn-outline-light btn-sm" data-filtre>OK</button>
  </form>
</div>

<?php if ($doublons): ?>
<div class="asso-card mb-3" style="border-color:#8a6d1f">
  <div class="fw-bold text-warning mb-2"><i class="bi bi-exclamation-triangle me-1"></i>Doublons potentiels (<?= count($doublons) ?>)</div>
  <ul class="small mb-0">
    <?php foreach ($doublons as $d): ?>
      <li><?= h(trim($d['nom'] . ' ' . $d['prenom'])) ?> (<?= h($d['date_naissance'] ?: '?') ?>) → <?= h($d['nius']) ?></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<div class="asso-card p-0">
  <table class="table table-dark table-sm mb-0 align-middle" style="font-size:.83rem">
    <thead><tr>
      <th>NIU</th><th>Nom</th><th>Naissance</th><th>Statut</th><th>Origine</th><th>École actuelle</th>
    </tr></thead>
    <tbody>
      <?php foreach ($lignes as $r): ?>
        <tr>
          <td class="font-monospace"><?= h($r['niu']) ?></td>
          <td><?= h(trim($r['nom'] . ' ' . $r['prenom'])) ?: '<span class="text-muted2">(non renseigné)</span>' ?></td>
          <td><?= h($r['date_naissance'] ?: '—') ?></td>
          <td><span class="badge badge-soft"><?= h($r['statut']) ?></span></td>
          <td><?= h($r['ecole_origine'] ?: '—') ?></td>
          <td><?= h($r['ecole_courante'] ?: '—') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$lignes): ?>
        <tr><td colspan="6" class="text-muted2 text-center py-3">Aucun résultat.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
<p class="small text-muted2 mt-2">300 lignes max affichées. Total registre :
  <?= (int) assoc_val("SELECT COUNT(*) FROM eleve_niu") ?> NIU.</p>
<?php asso_bas();
