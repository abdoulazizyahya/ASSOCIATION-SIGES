<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
exiger_role(['ADMIN']);

$sql_file = 'C:/Users/abdou.DESKTOP-M3PGF3H/Downloads/lycee_technique_mbe(3).sql';
$messages = [];
$done     = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'import') {
    csrf_verifier();

    if (!file_exists($sql_file)) {
        $messages[] = ['err', "Fichier SQL introuvable : $sql_file"];
    } else {
        $content = file_get_contents($sql_file);

        // Importer discipline
        if (preg_match('/INSERT INTO `discipline` \(`id_mat`.*?\) VALUES\s*([\s\S]+?);(?:\s*\n|$)/m', $content, $m)) {
            try {
                global $pdo;
                $pdo->exec("TRUNCATE TABLE discipline");
                $pdo->exec("INSERT INTO `discipline` (`id_mat`, `IDClasses`, `id_groupe`, `coef`, `ordre`) VALUES " . $m[1]);
                $cnt = (int)$pdo->query("SELECT COUNT(*) FROM discipline")->fetchColumn();
                $messages[] = ['ok', "discipline importée : $cnt lignes"];
            } catch (Exception $e) {
                $messages[] = ['err', "discipline : " . $e->getMessage()];
            }
        } else {
            $messages[] = ['warn', "INSERT discipline non trouvé dans le fichier SQL"];
        }

        // Importer dispenser
        if (preg_match('/INSERT INTO `dispenser` \(`matricule_ens`.*?\) VALUES\s*([\s\S]+?);(?:\s*\n|$)/m', $content, $m)) {
            try {
                global $pdo;
                $pdo->exec("TRUNCATE TABLE dispenser");
                $pdo->exec("INSERT INTO `dispenser` (`matricule_ens`, `IDClasses`, `id_mat`, `val_annee`) VALUES " . $m[1]);
                $cnt = (int)$pdo->query("SELECT COUNT(*) FROM dispenser")->fetchColumn();
                $messages[] = ['ok', "dispenser importée : $cnt lignes"];
            } catch (Exception $e) {
                $messages[] = ['err', "dispenser : " . $e->getMessage()];
            }
        } else {
            $messages[] = ['warn', "INSERT dispenser non trouvé dans le fichier SQL"];
        }

        $done = true;
    }
}

// Stats actuelles
$nb_disc = (int)db_val("SELECT COUNT(*) FROM discipline") ?: 0;
$nb_disp = (int)db_val("SELECT COUNT(*) FROM dispenser") ?: 0;

$titre_page = 'Import Discipline & Dispenser';
require_once __DIR__ . '/../layout/header.php';
?>

<div class="page-titre">
  <h4><i class="bi bi-database-up me-1 text-primary"></i>Import Discipline &amp; Dispenser</h4>
  <a href="<?= APP_URL ?>/pages/matieres/liste.php?onglet=affectations" class="btn btn-sm btn-light">
    <i class="bi bi-arrow-left me-1"></i>Retour
  </a>
</div>

<?php foreach ($messages as [$type, $msg]): ?>
  <div class="alert alert-<?= $type==='ok'?'success':($type==='err'?'danger':'warning') ?> py-2">
    <?= h($msg) ?>
  </div>
<?php endforeach; ?>

<div class="card mb-3">
  <div class="card-body">
    <div class="mb-3">
      <div class="section-titre">Statistiques actuelles</div>
      <div class="row g-2 mt-1">
        <div class="col-auto">
          <span class="badge bg-primary fs-6"><?= $nb_disc ?></span>
          <span class="ms-1" style="font-size:.85rem">discipline(s) affectée(s)</span>
        </div>
        <div class="col-auto">
          <span class="badge bg-success fs-6"><?= $nb_disp ?></span>
          <span class="ms-1" style="font-size:.85rem">dispenser(s) (enseignant→matière)</span>
        </div>
      </div>
    </div>

    <div class="section-titre mb-2">Fichier source</div>
    <code style="font-size:.8rem"><?= h($sql_file) ?></code>
    <div class="text-muted mt-1" style="font-size:.75rem">
      <?= file_exists($sql_file) ? '<span class="text-success"><i class="bi bi-check-circle me-1"></i>Fichier trouvé</span>' : '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>Fichier introuvable</span>' ?>
    </div>

    <hr class="my-3">
    <div class="alert alert-warning py-2" style="font-size:.82rem">
      <i class="bi bi-exclamation-triangle me-1"></i>
      <strong>Attention :</strong> Cette opération vide et réimporte les tables
      <code>discipline</code> et <code>dispenser</code> depuis le fichier SQL original.
      Les affectations manuelles seront écrasées.
    </div>

    <form method="post">
      <?= csrf_champ() ?>
      <input type="hidden" name="action" value="import">
      <button type="submit" class="btn btn-primary btn-sm"
              onclick="return confirm('Confirmer la réimportation des données discipline et dispenser ?')">
        <i class="bi bi-database-up me-1"></i>Lancer l'import
      </button>
    </form>
  </div>
</div>

<?php if ($done && !array_filter($messages, fn($m)=>$m[0]==='err')): ?>
<div class="alert alert-success">
  <i class="bi bi-check-circle me-1"></i>Import terminé avec succès !
  <a href="<?= APP_URL ?>/pages/matieres/liste.php?onglet=affectations" class="alert-link ms-2">
    Aller aux affectations →
  </a>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
