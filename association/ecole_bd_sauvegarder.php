<?php
// association/ecole_bd_sauvegarder.php — Sauvegarde « à la demande » de la
// base d'UNE école (SQL + fichiers), écrite dans bd/sauvegardes/manuel_<horo>/.
// Superadmin association uniquement. Non destructif.
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
    $avec_fichiers = ($_POST['contenu'] ?? 'complet') !== 'sql';
    $res = ecole_sauvegarder($id, $avec_fichiers);
    if ($res['ok']) {
        journaliser_action('ecole_bd_sauvegarde', $id,
            $e['code'] . ' — ' . basename((string) $res['fichier'])
            . ' (' . round(($res['octets'] ?: 0) / 1024) . ' Ko)');
        // Purge opportuniste des vieux backups de sécurité.
        ecole_maint_purger_backups();
        $ok = true;
    } else {
        $err = $res['message'];
    }
}

asso_haut('Sauvegarder — ' . $e['nom']);
?>
<a href="<?= APP_URL ?>/association/etablissement.php?id=<?= (int) $e['id'] ?>" class="small text-decoration-none">← Fiche</a>

<?php if ($ok): ?>
  <div class="alert alert-success py-2 small mt-2"><?= h($res['message']) ?></div>
  <div class="small text-muted2 mb-2">
    <?= (int) ($res['octets'] ?? 0) ? number_format($res['octets'] / 1024, 0, ',', ' ') . ' Ko' : '' ?>
    — visible dans le tableau de bord (colonne « Sauvegarde »).
  </div>
  <a href="<?= APP_URL ?>/association/etablissement.php?id=<?= (int) $e['id'] ?>" class="btn btn-primary btn-sm">
    <i class="bi bi-arrow-left me-1"></i>Retour à la fiche
  </a>
  <?php asso_bas(); exit; ?>
<?php endif; ?>

<?php if ($err): ?><div class="alert alert-danger py-2 small mt-2"><?= h($err) ?></div><?php endif; ?>

<div class="asso-card mt-2" style="max-width:560px">
  <div class="d-flex align-items-center gap-2 mb-2">
    <i class="bi bi-shield-check text-info fs-4"></i>
    <strong>Sauvegarder la base maintenant</strong>
  </div>
  <div class="small text-muted2 mb-3">
    Code <span class="font-monospace"><?= h($e['code']) ?></span> ·
    base <span class="font-monospace"><?= h($e['db_name']) ?></span>
  </div>

  <p class="small text-muted2">
    Le fichier est écrit dans <span class="font-monospace">bd/sauvegardes/manuel_&lt;date&gt;/</span>
    et apparaît dans le tableau de bord. Il pourra servir à restaurer l'école
    (<a href="<?= APP_URL ?>/association/ecole_bd_restaurer.php?id=<?= (int) $e['id'] ?>">Restaurer</a>).
    Ne remplace pas la sauvegarde automatique quotidienne — c'est un point de restauration ponctuel.
  </p>

  <form method="post" class="mt-2">
    <input type="hidden" name="csrf" value="<?= h(csrf_generer()) ?>">
    <div class="mb-3">
      <div class="form-check">
        <input class="form-check-input" type="radio" name="contenu" value="complet" id="c_complet" checked>
        <label class="form-check-label small" for="c_complet">
          <strong>Complète (.zip)</strong> — base + fichiers (logos, signatures, pièces de dossier). Recommandé.
        </label>
      </div>
      <div class="form-check">
        <input class="form-check-input" type="radio" name="contenu" value="sql" id="c_sql">
        <label class="form-check-label small" for="c_sql">
          Base seule (.sql.gz) — plus léger, sans les fichiers.
        </label>
      </div>
    </div>
    <button class="btn btn-primary btn-sm"><i class="bi bi-download me-1"></i>Créer la sauvegarde</button>
    <a href="<?= APP_URL ?>/association/etablissement.php?id=<?= (int) $e['id'] ?>" class="btn btn-outline-light btn-sm ms-2">Annuler</a>
  </form>
</div>
<?php asso_bas();
