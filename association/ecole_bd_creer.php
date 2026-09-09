<?php
// association/ecole_bd_creer.php — Créer / initialiser la base MySQL d'une
// école déjà inscrite à l'annuaire mais dont la base est absente (jamais
// provisionnée, supprimée à la main…). Superadmin association uniquement.
// Non destructif : refuse d'agir si la base existe déjà avec des tables.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../bd/lib/ecole_maintenance.php';
exiger_proprietaire_association();  // opérations lourdes : propriétaire uniquement (admin simple = sauvegarde seule)

$id = (int) ($_GET['id'] ?? 0);
$e  = $id ? assoc_one("SELECT * FROM etablissement WHERE id=?", [$id]) : null;
if (!$e) { asso_haut('Établissement introuvable'); asso_bas(); exit; }

$etat = ecole_base_etat($e['db_name']);
$err = ''; $ok = false; $res = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $res = ecole_creer_base($id);
    if ($res['ok']) {
        journaliser_action('ecole_bd_creation', $id,
            $e['code'] . ' — ' . $e['db_name'] . ' (' . (int) $res['tables'] . ' tables)');
        $ok = true;
    } else {
        $err = $res['message'];
    }
}

asso_haut('Créer la base — ' . $e['nom']);
?>
<a href="<?= APP_URL ?>/association/etablissement.php?id=<?= (int) $e['id'] ?>" class="small text-decoration-none">← Fiche</a>

<?php if ($ok): ?>
  <div class="alert alert-success py-2 small mt-2"><?= h($res['message']) ?></div>
  <a href="<?= APP_URL ?>/association/etablissement.php?id=<?= (int) $e['id'] ?>" class="btn btn-primary btn-sm mt-1">
    <i class="bi bi-arrow-left me-1"></i>Retour à la fiche
  </a>
  <?php asso_bas(); exit; ?>
<?php endif; ?>

<?php if ($err): ?><div class="alert alert-danger py-2 small mt-2"><?= h($err) ?></div><?php endif; ?>

<div class="asso-card mt-2" style="max-width:640px">
  <div class="d-flex align-items-center gap-2 mb-2">
    <i class="bi bi-database-add text-info fs-4"></i>
    <strong>Initialiser la base de données</strong>
  </div>

  <div class="small text-muted2 mb-3">
    Code <span class="font-monospace"><?= h($e['code']) ?></span> ·
    base <span class="font-monospace"><?= h($e['db_name']) ?></span>
  </div>

  <div class="asso-card p-0 mb-3">
    <table class="table table-sm mb-0 align-middle" style="font-size:.83rem">
      <tbody>
        <tr>
          <td>Base <span class="font-monospace"><?= h($e['db_name']) ?></span> sur le serveur</td>
          <td class="text-end">
            <?php if (!$etat['existe']): ?>
              <span class="text-warning">absente — sera créée</span>
            <?php elseif ($etat['tables'] === 0): ?>
              <span class="text-warning">présente mais vide — sera peuplée</span>
            <?php else: ?>
              <span class="text-success"><?= (int) $etat['tables'] ?> tables · <?= h((string) $etat['mo']) ?> Mo</span>
            <?php endif; ?>
          </td>
        </tr>
      </tbody>
    </table>
  </div>

  <?php if ($etat['existe'] && $etat['tables'] > 0): ?>
    <div class="alert alert-secondary py-2 small">
      La base est déjà initialisée. Pour la réinitialiser, utilisez
      <a href="<?= APP_URL ?>/association/ecole_bd_vider.php?id=<?= (int) $e['id'] ?>">Vider</a> ou
      <a href="<?= APP_URL ?>/association/ecole_bd_import.php?id=<?= (int) $e['id'] ?>">Importer un dump</a>.
    </div>
  <?php else: ?>
    <ul class="small text-muted2">
      <li>Le schéma de référence (<span class="font-monospace">bd/assoc/schema_ref_ecole.sql</span>) est chargé.</li>
      <li>La fiche établissement locale est amorcée depuis l'annuaire (nom, sigle, ville).</li>
      <li>Aucune donnée scolaire : créez ensuite un compte DIRECTEUR via Personnel → Affecter, puis l'année scolaire.</li>
    </ul>
    <form method="post" class="mt-2">
      <input type="hidden" name="csrf" value="<?= h(csrf_generer()) ?>">
      <button class="btn btn-primary btn-sm"><i class="bi bi-database-add me-1"></i>Créer et initialiser la base</button>
      <a href="<?= APP_URL ?>/association/etablissement.php?id=<?= (int) $e['id'] ?>" class="btn btn-outline-light btn-sm ms-2">Annuler</a>
    </form>
  <?php endif; ?>
</div>
<?php asso_bas();
