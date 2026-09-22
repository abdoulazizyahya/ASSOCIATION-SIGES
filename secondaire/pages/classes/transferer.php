<?php
// secondaire/pages/classes/transferer.php — transfère TOUS les élèves
// inscrits (année scolaire active) d'une classe vers une autre classe de la
// même école, en un clic (ex. fusion de deux classes, correction d'une
// classe créée par erreur). La classe source elle-même n'est pas touchée
// (ni archivée ni supprimée) : seules les inscriptions de ses élèves
// changent de classe. Demande explicite du 17/09/2026.
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'FONDATEUR']);

$id_source = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$source    = $id_source ? db_one("SELECT id, designation FROM classe WHERE id=?", [$id_source]) : null;
if (!$source) { flash_set('erreur', 'Classe introuvable.'); rediriger('secondaire/pages/classes/liste.php'); }

$id_annee = (int) (get_annee_active()['id'] ?? 0);
$nb_insc  = (int) db_val("SELECT COUNT(*) FROM inscription WHERE id_classe=? AND id_annee=?", [$id_source, $id_annee]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $id_cible = (int) post('id_classe_cible');
    $cible    = $id_cible ? db_one("SELECT id, designation FROM classe WHERE id=? AND archivee=0", [$id_cible]) : null;
    if (!$cible || $id_cible === $id_source) {
        flash_set('erreur', 'Classe de destination invalide.');
    } else {
        $n = db_exec("UPDATE inscription SET id_classe=? WHERE id_classe=? AND id_annee=?", [$id_cible, $id_source, $id_annee]);
        flash_set('succes', "$n élève(s) transféré(s) de « {$source['designation']} » vers « {$cible['designation']} ».");
        rediriger('secondaire/pages/classes/liste.php');
    }
}

$classes_cibles = db_all(
    "SELECT id, designation FROM classe WHERE archivee=0 AND id<>? ORDER BY ordre, designation",
    [$id_source]
);

$titre_page = 'Transférer une classe';
require_once __DIR__ . '/../../../layout/header.php';
?>
<a href="<?= APP_URL ?>/secondaire/pages/classes/liste.php" class="small text-decoration-none">← Classes</a>

<?= flash_html() ?>

<div class="page-titre mt-2">
  <h4><i class="bi bi-arrow-left-right me-1 text-primary"></i>Transférer les élèves d'une classe</h4>
  <div class="sub">« <?= h($source['designation']) ?> » — <?= $nb_insc ?> élève(s) inscrit(s) cette année</div>
</div>

<div class="card mt-2" style="max-width:520px">
  <div class="card-body">
    <?php if ($nb_insc === 0): ?>
      <div class="alert alert-light text-muted mb-0">Aucun élève inscrit dans cette classe cette année — rien à transférer.</div>
    <?php else: ?>
      <form method="post" onsubmit="return confirm('Transférer les <?= $nb_insc ?> élève(s) de « <?= h(addslashes($source['designation'])) ?> » vers la classe choisie ?');">
        <?= csrf_champ() ?>
        <input type="hidden" name="id" value="<?= (int) $id_source ?>">
        <div class="mb-3">
          <label class="form-label">Classe de destination</label>
          <select name="id_classe_cible" class="form-select" required>
            <option value="">— Choisir —</option>
            <?php foreach ($classes_cibles as $c): ?>
              <option value="<?= (int) $c['id'] ?>"><?= h($c['designation']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="alert alert-warning py-2 small">
          <i class="bi bi-exclamation-triangle me-1"></i>Les <?= $nb_insc ?> élève(s) actuellement inscrit(s) dans
          « <?= h($source['designation']) ?> » seront déplacés vers la classe choisie. La classe source elle-même n'est ni archivée ni supprimée.
        </div>
        <button class="btn btn-primary btn-sm"><i class="bi bi-arrow-left-right me-1"></i>Transférer</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../../../layout/footer.php'; ?>
