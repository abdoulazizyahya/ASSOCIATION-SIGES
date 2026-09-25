<?php
// secondaire/pages/classes/form.php — création/modification d'une classe
// (école secondaire). Porté de LAM_ABZ, URLs rebranchées sous secondaire/.
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'FONDATEUR', 'CENSEUR']);

$id     = (int) ($_GET['id'] ?? 0);
$classe = $id ? db_one("SELECT * FROM classe WHERE id=?", [$id]) : null;

$niveaux  = db_all("SELECT * FROM niveau ORDER BY ordre_niveau");
$sections = db_all("SELECT * FROM section_classe ORDER BY libelle_section");
$filieres = db_all("SELECT * FROM filiere ORDER BY libelle");
$series   = db_all("SELECT * FROM serie ORDER BY id");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $designation     = post('designation');
    $code_niveau     = post('code_niveau');
    $libelle_section = post('libelle_section');
    $id_filiere      = post('id_filiere');
    $id_serie        = (int) post('id_serie') ?: null;
    $effectif_max    = (int) post('effectif_max') ?: 40;
    $ordre           = (int) post('ordre') ?: 1;

    if (!$designation) { flash_set('erreur', 'La désignation est obligatoire.'); rediriger('secondaire/pages/classes/form.php' . ($id ? "?id=$id" : '')); }

    if ($id) {
        db_exec("UPDATE classe SET designation=?, code_niveau=?, libelle_section=?, id_filiere=?, id_serie=?, effectif_max=?, ordre=? WHERE id=?",
                [$designation, $code_niveau ?: null, $libelle_section ?: null, $id_filiere ?: null, $id_serie, $effectif_max, $ordre, $id]);
        flash_set('succes', 'Classe mise à jour.');
    } else {
        db_exec("INSERT INTO classe (designation, code_niveau, libelle_section, id_filiere, id_serie, effectif_max, ordre, archivee) VALUES (?, ?, ?, ?, ?, ?, ?, 0)",
                [$designation, $code_niveau ?: null, $libelle_section ?: null, $id_filiere ?: null, $id_serie, $effectif_max, $ordre]);
        $nouvelle_id = db_last_id();
        // Matières affectées automatiquement : d'abord depuis une classe
        // sœur du même niveau/section déjà affectée, sinon depuis les
        // compétences déjà configurées pour ce niveau (onglet « Compétences
        // par trimestre »). Demande explicite du 25/09/2026.
        $nb_mat = function_exists('secondaire_auto_matieres_classe')
            ? secondaire_auto_matieres_classe((int) $nouvelle_id, $code_niveau ?: null, $libelle_section ?: null)
            : 0;
        flash_set('succes', 'Classe créée.' . ($nb_mat ? " $nb_mat matière(s) affectée(s) automatiquement." : ''));
    }
    rediriger('secondaire/pages/classes/liste.php');
}

$titre_page = $id ? 'Modifier la classe' : 'Nouvelle classe';
require_once __DIR__ . '/../../../layout/header.php';
?>
<div class="page-titre d-flex justify-content-between align-items-center">
  <h4><i class="bi bi-door-open me-1 text-primary"></i><?= h($titre_page) ?></h4>
  <a href="<?= APP_URL ?>/secondaire/pages/classes/liste.php" class="btn btn-sm btn-light"><i class="bi bi-arrow-left me-1"></i>Retour</a>
</div>

<?= flash_html() ?>

<div class="card">
  <div class="card-body">
    <form method="post" class="row g-2">
      <?= csrf_champ() ?>

      <div class="col-md-6">
        <label class="form-label">Désignation *</label>
        <input type="text" name="designation" class="form-control" required
               value="<?= h($classe['designation'] ?? '') ?>" placeholder="ex: 6ème A">
      </div>
      <div class="col-md-3">
        <label class="form-label">Effectif max</label>
        <input type="number" name="effectif_max" class="form-control" min="1" value="<?= (int) ($classe['effectif_max'] ?? 40) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Ordre affichage</label>
        <input type="number" name="ordre" class="form-control" min="1" value="<?= (int) ($classe['ordre'] ?? 1) ?>">
      </div>

      <div class="col-12"><hr class="my-1"><div class="section-titre mt-1">Classification pédagogique</div></div>
      <?php if (!$niveaux && !$sections && !$filieres && !$series): ?>
        <div class="col-12">
          <div class="alert alert-light border py-2" style="font-size:.78rem">
            <i class="bi bi-info-circle me-1"></i>Aucune donnée de référence (niveau/section/filière/série) saisie pour
            l'instant dans cette école — la désignation seule suffit pour créer la classe.
          </div>
        </div>
      <?php endif; ?>

      <div class="col-md-3">
        <label class="form-label">Niveau</label>
        <select name="code_niveau" class="form-select">
          <option value="">— Choisir —</option>
          <?php foreach ($niveaux as $n): ?>
            <option value="<?= h($n['code_niveau']) ?>" <?= ($classe['code_niveau'] ?? '') === $n['code_niveau'] ? 'selected' : '' ?>>
              <?= h($n['libelle_niv'] . ' (' . $n['code_niveau'] . ')') ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Section</label>
        <select name="libelle_section" class="form-select">
          <option value="">— Choisir —</option>
          <?php foreach ($sections as $s): ?>
            <option value="<?= h($s['libelle_section']) ?>" <?= ($classe['libelle_section'] ?? '') === $s['libelle_section'] ? 'selected' : '' ?>>
              <?= h($s['libelle_section']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Filière</label>
        <select name="id_filiere" class="form-select">
          <option value="">— Choisir —</option>
          <?php foreach ($filieres as $f): ?>
            <option value="<?= h($f['id']) ?>" <?= ($classe['id_filiere'] ?? '') === $f['id'] ? 'selected' : '' ?>><?= h($f['libelle']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Série</label>
        <select name="id_serie" class="form-select">
          <option value="">— Choisir —</option>
          <?php foreach ($series as $s): ?>
            <option value="<?= (int) $s['id'] ?>" <?= (int) ($classe['id_serie'] ?? 0) === (int) $s['id'] ? 'selected' : '' ?>><?= h($s['libelle']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 mt-3">
        <button class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Enregistrer</button>
        <a href="<?= APP_URL ?>/secondaire/pages/classes/liste.php" class="btn btn-light btn-sm ms-1">Annuler</a>
      </div>
    </form>
  </div>
</div>
<?php require_once __DIR__ . '/../../../layout/footer.php'; ?>
