<?php
// association/personnel/liste.php — personnel de l'association + affectations
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/../_layout.php';
exiger_membre_association();

$q = trim($_GET['q'] ?? '');
$where = '1'; $params = [];
if ($q !== '') { $where = "(p.matricule LIKE ? OR p.nom LIKE ? OR p.prenom LIKE ?)"; $params = ["%$q%", "%$q%", "%$q%"]; }

$gens = assoc_all(
    "SELECT p.*,
            GROUP_CONCAT(CONCAT(e.code, ':', a.fonction, IF(a.actif, '', ' (clôturée)')) SEPARATOR ' · ') AS affectations
     FROM personnel p
     LEFT JOIN personnel_affectation a ON a.matricule = p.matricule
     LEFT JOIN etablissement e ON e.id = a.id_etablissement
     WHERE $where
     GROUP BY p.matricule ORDER BY p.nom, p.prenom", $params
);

asso_haut('Personnel de l\'association');
?>
<form method="get" class="mb-3" style="max-width:340px">
  <input type="text" name="q" value="<?= h($q) ?>" class="form-control form-control-sm"
         placeholder="matricule, nom…" data-filtre>
</form>

<div class="asso-card p-0">
  <table class="table table-dark table-sm mb-0 align-middle" style="font-size:.83rem">
    <thead><tr><th>Matricule</th><th>Nom</th><th>Statut</th><th>Affectations</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($gens as $p): ?>
        <tr>
          <td class="font-monospace"><?= h($p['matricule']) ?></td>
          <td><?= h(trim($p['nom'] . ' ' . $p['prenom'])) ?></td>
          <td><span class="badge badge-soft"><?= h($p['statut']) ?></span></td>
          <td class="small text-muted2"><?= h($p['affectations'] ?: '—') ?></td>
          <td class="text-end">
            <a class="btn btn-outline-light btn-sm py-0" href="<?= APP_URL ?>/association/personnel/fiche.php?m=<?= urlencode($p['matricule']) ?>">
              Fiche
            </a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$gens): ?><tr><td colspan="5" class="text-center text-muted2 py-3">Aucun agent.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<p class="small text-muted2 mt-2">
  <a href="<?= APP_URL ?>/association/personnel/affecter.php" class="btn btn-primary btn-sm">
    <i class="bi bi-arrow-left-right me-1"></i>Affecter un agent à une école
  </a>
</p>
<?php asso_bas();
