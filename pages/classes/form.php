<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_role(['DIRECTEUR']);

$id = (int)($_GET['id'] ?? 0);
$classe = $id ? db_one("SELECT * FROM classe WHERE IDClasses=?", [$id]) : null;
if ($id && !$classe) { flash_set('erreur', 'Classe introuvable.'); rediriger('pages/classes/liste.php'); }

$erreur = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $designation = post('designation');
    $niveau      = post('niveau');
    if ($designation === '' || $niveau === '') {
        $erreur = 'Veuillez remplir tous les champs.';
    } else {
        if ($id) {
            db_exec("UPDATE classe SET DesignationClasses=?, Niveau=? WHERE IDClasses=?", [$designation, $niveau, $id]);
            flash_set('succes', 'Classe modifiée.');
        } else {
            db_exec("INSERT INTO classe (DesignationClasses, Niveau) VALUES (?, ?)", [$designation, $niveau]);
            flash_set('succes', 'Classe créée.');
        }
        rediriger('pages/classes/liste.php');
    }
}

// Niveaux actifs uniquement — plus le niveau déjà affecté à cette classe le
// cas échéant (même s'il a été désactivé depuis, pour ne pas le faire
// disparaître silencieusement du formulaire d'une classe existante).
$niveaux = db_all(
    "SELECT * FROM niveau WHERE actif=1 OR LibelleNiveau=? ORDER BY OrdreNiveau",
    [$classe['Niveau'] ?? '']
);

$titre_page = $id ? 'Modifier la classe' : 'Nouvelle classe';
require_once __DIR__ . '/../../layout/header.php';
?>

<div class="page-titre">
  <h4><i class="bi bi-door-open me-1 text-primary"></i><?= $id ? 'Modifier la classe' : 'Nouvelle classe' ?></h4>
</div>

<div class="card" style="max-width:520px">
  <div class="card-body">
    <?php if ($erreur): ?><div class="alert alert-danger py-2"><?= h($erreur) ?></div><?php endif; ?>
    <form method="post">
      <?= csrf_champ() ?>
      <div class="mb-3">
        <label class="form-label">Désignation</label>
        <input type="text" name="designation" class="form-control" required
               value="<?= h($classe['DesignationClasses'] ?? post('designation')) ?>">
      </div>
      <div class="mb-3">
        <label class="form-label">Niveau</label>
        <select name="niveau" id="sel-niveau" class="form-select" required onchange="majSectionHeritee()">
          <option value="">— Choisir —</option>
          <?php foreach ($niveaux as $n): $sel = ($classe['Niveau'] ?? '') === $n['LibelleNiveau']; ?>
            <option value="<?= h($n['LibelleNiveau']) ?>" data-section="<?= h($n['Section'] ?? 'Fr') ?>" <?= $sel ? 'selected' : '' ?>>
              <?= h($n['LibelleNiveau']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <!-- La section (francophone/anglophone) n'est plus choisie par classe
             (demande explicite du 20/08/2026, migration_v42) : elle est
             héritée du niveau choisi ci-dessus — réglable dans Scolarité >
             Classes > onglet Niveaux. -->
        <div class="form-text" id="zone-section-heritee"></div>
      </div>
      <div class="d-flex gap-2">
        <button class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Enregistrer</button>
        <a href="<?= APP_URL ?>/pages/classes/liste.php" class="btn btn-outline-secondary btn-sm">Annuler</a>
      </div>
    </form>
  </div>
</div>

<script>
function majSectionHeritee() {
    const sel = document.getElementById('sel-niveau');
    const zone = document.getElementById('zone-section-heritee');
    const opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value) { zone.innerHTML = ''; return; }
    const section = opt.dataset.section === 'An' ? 'Anglophone' : 'Francophone';
    zone.innerHTML = 'Section : <strong>' + section + '</strong> (héritée du niveau, langue du bulletin).';
}
majSectionHeritee();
</script>

<?php require_once __DIR__ . '/../../layout/footer.php'; ?>
