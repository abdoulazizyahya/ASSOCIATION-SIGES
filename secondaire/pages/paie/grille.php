<?php
// secondaire/pages/paie/grille.php — Grille salariale, porté de
// pages/paie/grille.php (primaire) à l'identique — voir secondaire/pages/
// paie/index.php pour la note sur la réutilisation de paie_fonctions.php.
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
require_once __DIR__ . '/../../../paie_fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'FONDATEUR', 'INTENDANT']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $action = post('action');

    if ($action === 'ajouter_grade' || $action === 'modifier_grade') {
        $code_grade_orig = post('code_grade_orig');
        $code_grade      = post('code_grade');
        $libelle         = post('libelle_grade');
        $salaire         = (float) str_replace([' ', ','], ['', '.'], post('salaire_base'));
        $ordre           = (int) post('ordre_affichage');

        if ($code_grade === '' || $libelle === '' || $salaire < 0) {
            flash_set('erreur', 'Code, libellé et salaire (≥0) sont requis.');
        } elseif ($action === 'ajouter_grade' && db_val("SELECT COUNT(*) FROM grade_enseignant WHERE code_grade=?", [$code_grade])) {
            flash_set('erreur', 'Ce code de grade existe déjà.');
        } else {
            if ($action === 'ajouter_grade') {
                db_exec("INSERT INTO grade_enseignant (code_grade, libelle_grade, salaire_base, ordre_affichage) VALUES (?, ?, ?, ?)", [$code_grade, $libelle, $salaire, $ordre]);
                flash_set('succes', 'Grade ajouté.');
            } else {
                db_exec("UPDATE grade_enseignant SET libelle_grade=?, salaire_base=?, ordre_affichage=? WHERE code_grade=?", [$libelle, $salaire, $ordre, $code_grade_orig]);
                flash_set('succes', 'Grade modifié.');
            }
        }
    }

    if ($action === 'supprimer_grade') {
        $code = post('code_grade');
        $nb_ens = (int) db_val("SELECT COUNT(*) FROM enseignant WHERE id_grade=?", [$code]);
        if ($nb_ens > 0) {
            flash_set('erreur', "Impossible de supprimer : $nb_ens membre(s) du personnel utilisent encore ce grade.");
        } else {
            db_exec("DELETE FROM grade_enseignant WHERE code_grade=?", [$code]);
            flash_set('succes', 'Grade supprimé.');
        }
    }

    if ($action === 'ajouter_indemnite') {
        $code_grade = post('code_grade');
        $libelle    = post('libelle_indemnite');
        $montant    = (float) str_replace([' ', ','], ['', '.'], post('montant'));
        if ($code_grade === '' || $libelle === '' || $montant < 0) {
            flash_set('erreur', 'Grade, libellé et montant (≥0) sont requis.');
        } else {
            db_exec("INSERT INTO indemnite_grade (code_grade, libelle_indemnite, montant) VALUES (?, ?, ?)", [$code_grade, $libelle, $montant]);
            flash_set('succes', 'Indemnité ajoutée.');
        }
        rediriger('secondaire/pages/paie/grille.php?grade=' . urlencode($code_grade));
    }

    if ($action === 'supprimer_indemnite') {
        $id = (int) post('id');
        $code_grade = post('code_grade');
        db_exec("DELETE FROM indemnite_grade WHERE id=?", [$id]);
        flash_set('succes', 'Indemnité supprimée.');
        rediriger('secondaire/pages/paie/grille.php?grade=' . urlencode($code_grade));
    }

    rediriger('secondaire/pages/paie/grille.php');
}

$grades = grille_salariale();
foreach ($grades as &$g) {
    $g['nb_enseignants'] = (int) db_val("SELECT COUNT(*) FROM enseignant WHERE id_grade=?", [$g['code_grade']]);
    $g['total_indemnites'] = total_indemnites_grade($g['code_grade']);
}
unset($g);

$grade_courant = $_GET['grade'] ?? ($grades[0]['code_grade'] ?? '');
$indemnites = $grade_courant ? indemnites_grade($grade_courant) : [];

$es_partiel = isset($_GET['partiel']);
if (!$es_partiel) {
    $titre_page = 'Grille salariale';
    require_once __DIR__ . '/../../../layout/header.php';
} else {
    header('Content-Type: text/html; charset=utf-8');
}
?>

<div id="grille-paie-zone">

<div class="page-titre">
  <div>
    <h4><i class="bi bi-table me-1 text-primary"></i>Grille salariale</h4>
    <div class="sub"><?= count($grades) ?> grade(s)</div>
  </div>
  <button type="button" class="btn btn-primary btn-sm" onclick="ouvrirGrade(null)">
    <i class="bi bi-plus-lg me-1"></i>Nouveau grade
  </button>
</div>

<div class="card mb-3">
  <div class="table-responsive">
    <table class="table table-abz table-hover align-middle mb-0" style="font-size:.82rem">
      <thead><tr><th>Code</th><th>Grade</th><th class="text-end">Salaire de base</th><th class="text-end">Indemnités</th><th class="text-center">Personnel</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
        <?php if (!$grades): ?>
          <tr><td colspan="6" class="text-center text-muted py-3">Aucun grade configuré.</td></tr>
        <?php else: foreach ($grades as $g): ?>
          <tr <?= $g['code_grade'] === $grade_courant ? 'style="background:#f8faff"' : '' ?>>
            <td><span class="badge-code"><?= h($g['code_grade']) ?></span></td>
            <td class="fw-semibold"><?= h($g['libelle_grade']) ?></td>
            <td class="text-end"><?= number_format((float) $g['salaire_base'], 0, ',', ' ') ?> F</td>
            <td class="text-end"><?= number_format($g['total_indemnites'], 0, ',', ' ') ?> F</td>
            <td class="text-center"><?= $g['nb_enseignants'] ?></td>
            <td class="text-end">
              <a data-ajax-nav href="<?= APP_URL ?>/secondaire/pages/paie/grille.php?grade=<?= urlencode($g['code_grade']) ?>" class="btn btn-sm btn-light" style="padding:2px 6px" title="Indemnités">
                <i class="bi bi-list-ul" style="font-size:.78rem"></i>
              </a>
              <button type="button" class="btn btn-sm btn-light" style="padding:2px 6px" title="Modifier"
                      onclick='ouvrirGrade(<?= json_encode($g) ?>)'>
                <i class="bi bi-pencil-square text-success" style="font-size:.78rem"></i>
              </button>
              <form method="post" class="d-inline" data-ajax-post-form onsubmit="return confirm('Supprimer ce grade ?')">
                <?= csrf_champ() ?>
                <input type="hidden" name="action" value="supprimer_grade">
                <input type="hidden" name="code_grade" value="<?= h($g['code_grade']) ?>">
                <button class="btn btn-sm btn-light" style="padding:2px 6px" title="Supprimer"><i class="bi bi-trash text-danger" style="font-size:.78rem"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($grade_courant): ?>
<div class="card">
  <div class="card-header py-2 d-flex justify-content-between align-items-center" style="background:#f8faff">
    <span class="fw-semibold" style="font-size:.82rem">
      <i class="bi bi-plus-circle me-1"></i>Indemnités — <?= h($grades[array_search($grade_courant, array_column($grades, 'code_grade'))]['libelle_grade'] ?? $grade_courant) ?>
    </span>
  </div>
  <div class="card-body">
    <form method="post" class="row g-2 align-items-end mb-3" data-ajax-post-form>
      <?= csrf_champ() ?>
      <input type="hidden" name="action" value="ajouter_indemnite">
      <input type="hidden" name="code_grade" value="<?= h($grade_courant) ?>">
      <div class="col-md-5">
        <label class="form-label">Libellé (ex. Logement, Transport…)</label>
        <input type="text" name="libelle_indemnite" class="form-control form-control-sm" required>
      </div>
      <div class="col-md-3">
        <label class="form-label">Montant (FCFA)</label>
        <input type="number" name="montant" class="form-control form-control-sm" min="0" step="1" required>
      </div>
      <div class="col-md-4">
        <button class="btn btn-primary btn-sm w-100"><i class="bi bi-check-lg me-1"></i>Ajouter</button>
      </div>
    </form>

    <table class="table table-sm mb-0" style="font-size:.8rem">
      <thead><tr><th>Indemnité</th><th class="text-end">Montant</th><th class="text-end">Action</th></tr></thead>
      <tbody>
        <?php if (!$indemnites): ?>
          <tr><td colspan="3" class="text-center text-muted py-2">Aucune indemnité pour ce grade.</td></tr>
        <?php else: foreach ($indemnites as $i): ?>
          <tr>
            <td><?= h($i['libelle_indemnite']) ?></td>
            <td class="text-end"><?= number_format((float) $i['montant'], 0, ',', ' ') ?> F</td>
            <td class="text-end">
              <form method="post" class="d-inline" data-ajax-post-form onsubmit="return confirm('Supprimer cette indemnité ?')">
                <?= csrf_champ() ?>
                <input type="hidden" name="action" value="supprimer_indemnite">
                <input type="hidden" name="id" value="<?= (int) $i['id'] ?>">
                <input type="hidden" name="code_grade" value="<?= h($grade_courant) ?>">
                <button class="btn btn-sm btn-light" style="padding:2px 6px" title="Supprimer"><i class="bi bi-trash text-danger" style="font-size:.75rem"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Modale Nouveau/Modifier grade -->
<div class="modal fade" id="modalGrade" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header py-2" style="background:#f8faff">
        <h6 class="modal-title fw-bold" id="modalGradeTitre"><i class="bi bi-table me-1 text-primary"></i>Grade</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" data-ajax-post-form>
        <?= csrf_champ() ?>
        <input type="hidden" name="action" id="mg-action" value="ajouter_grade">
        <input type="hidden" name="code_grade_orig" id="mg-code-orig">
        <div class="modal-body row g-2">
          <div class="col-6">
            <label class="form-label">Code (court, sans espace)</label>
            <input type="text" name="code_grade" id="mg-code" class="form-control" required maxlength="20" style="text-transform:uppercase">
          </div>
          <div class="col-6">
            <label class="form-label">Ordre d'affichage</label>
            <input type="number" name="ordre_affichage" id="mg-ordre" class="form-control" value="0">
          </div>
          <div class="col-12">
            <label class="form-label">Libellé</label>
            <input type="text" name="libelle_grade" id="mg-libelle" class="form-control" required>
          </div>
          <div class="col-12">
            <label class="form-label">Salaire de base (FCFA)</label>
            <input type="number" name="salaire_base" id="mg-salaire" class="form-control" min="0" step="1" required>
          </div>
        </div>
        <div class="modal-footer py-2">
          <button class="btn btn-primary btn-sm">Enregistrer</button>
          <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Annuler</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function ouvrirGrade(g) {
    document.getElementById('mg-action').value = g ? 'modifier_grade' : 'ajouter_grade';
    document.getElementById('mg-code-orig').value = g ? g.code_grade : '';
    const codeInp = document.getElementById('mg-code');
    codeInp.value = g ? g.code_grade : '';
    codeInp.readOnly = !!g;
    document.getElementById('mg-libelle').value = g ? g.libelle_grade : '';
    document.getElementById('mg-salaire').value = g ? g.salaire_base : '';
    document.getElementById('mg-ordre').value = g ? g.ordre_affichage : 0;
    document.getElementById('modalGradeTitre').innerHTML = '<i class="bi bi-table me-1 text-primary"></i>' + (g ? 'Modifier le grade' : 'Nouveau grade');
    new bootstrap.Modal(document.getElementById('modalGrade')).show();
}
</script>

</div><!-- /#grille-paie-zone -->
<?php if ($es_partiel) exit; // rien de plus dans une réponse AJAX partielle. ?>

<?php
$ajax_zone_id = 'grille-paie-zone'; // voir layout/footer.php — initAjaxZone() y est appelé
require_once __DIR__ . '/../../../layout/footer.php';
