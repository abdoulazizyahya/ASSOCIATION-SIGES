<?php
// pages/depenses/categories.php — Dépenses > Catégories : catalogue des
// catégories de dépenses (`categorie_depense`, migration v33). Miroir de
// pages/finances/obligations.php (même convention CRUD) côté décaissements.
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_role(['DIRECTEUR', 'COMPTABLE']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $action = post('action');

    if ($action === 'creer') {
        $lib  = post('libelle');
        $desc = post('description');
        if ($lib !== '') {
            db_exec("INSERT INTO categorie_depense (libelle, description) VALUES (?, ?)", [$lib, $desc ?: null]);
            flash_set('succes', 'Catégorie ajoutée.');
        } else {
            flash_set('erreur', 'Le libellé est requis.');
        }
        rediriger('pages/depenses/categories.php');
    }

    if ($action === 'modifier') {
        $id   = (int) post('id_categorie');
        $lib  = post('libelle');
        $desc = post('description');
        if ($id && $lib !== '') {
            db_exec("UPDATE categorie_depense SET libelle=?, description=? WHERE id_categorie=?", [$lib, $desc ?: null, $id]);
            flash_set('succes', 'Catégorie modifiée.');
        }
        rediriger('pages/depenses/categories.php');
    }

    if ($action === 'supprimer') {
        $id = (int) post('id_categorie');
        $nb = (int) db_val("SELECT COUNT(*) FROM depense WHERE id_categorie=?", [$id]);
        if ($nb > 0) {
            flash_set('erreur', "Impossible : $nb dépense(s) sont déjà rattachées à cette catégorie.");
        } else {
            db_exec("DELETE FROM categorie_depense WHERE id_categorie=?", [$id]);
            flash_set('succes', 'Catégorie supprimée.');
        }
        rediriger('pages/depenses/categories.php');
    }
}

$categories = db_all(
    "SELECT cd.*, COUNT(d.id_depense) AS nb_depenses, COALESCE(SUM(d.montant),0) AS total_depense
     FROM categorie_depense cd
     LEFT JOIN depense d ON d.id_categorie = cd.id_categorie
     GROUP BY cd.id_categorie
     ORDER BY cd.libelle"
);

$es_partiel = isset($_GET['partiel']);
if (!$es_partiel) {
    $titre_page = 'Catégories de dépenses';
    require_once __DIR__ . '/../../layout/header.php';
} else {
    header('Content-Type: text/html; charset=utf-8');
}
?>

<div id="cat-depenses-zone">

<div class="page-titre d-flex justify-content-between align-items-center flex-wrap gap-2">
  <div>
    <h4><i class="bi bi-tags me-1 text-primary"></i>Dépenses — Catégories</h4>
    <div class="sub">Classement des dépenses de l'établissement.</div>
  </div>
  <div class="d-flex gap-2">
    <button type="button" class="btn btn-outline-danger btn-sm" onclick="afficherApercu('<?= APP_URL ?>/pdf/depenses_categories.php', 'Catégories de dépenses', null, 'portrait')">
      <i class="bi bi-file-earmark-pdf me-1"></i>Aperçu PDF
    </button>
    <a class="btn btn-outline-success btn-sm" href="<?= APP_URL ?>/pages/depenses/excel_categories.php">
      <i class="bi bi-file-earmark-excel me-1"></i>Excel
    </a>
  </div>
</div>

<div class="row g-2" style="align-items:flex-start">

  <div class="col-md-4">
    <div class="card h-100" style="border:1px solid #c7d2fe">
      <div class="card-header py-2 px-3" style="background:linear-gradient(135deg,#eef2ff,#e0e7ff);border-bottom:1px solid #c7d2fe">
        <span class="fw-bold" style="font-size:.8rem;color:#312e81">
          <i class="bi bi-plus-circle-fill me-1"></i><span id="cat-form-title">Ajouter une catégorie</span>
        </span>
      </div>
      <div class="card-body p-3">
        <form method="post" id="form-cat" data-ajax-post-form>
          <?= csrf_champ() ?>
          <input type="hidden" name="action" id="cat_action" value="creer">
          <input type="hidden" name="id_categorie" id="cat_id" value="0">
          <div class="mb-2">
            <label class="form-label" style="font-size:.75rem">Libellé</label>
            <input type="text" name="libelle" id="cat_libelle" class="form-control form-control-sm" maxlength="150" placeholder="ex. Salaires, Fournitures..." required>
          </div>
          <div class="mb-3">
            <label class="form-label" style="font-size:.75rem">Description (optionnel)</label>
            <textarea name="description" id="cat_description" class="form-control form-control-sm" rows="2" maxlength="255"></textarea>
          </div>
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-sm btn-primary" id="cat-btn-submit">Ajouter</button>
            <button type="button" class="btn btn-sm btn-light d-none" id="cat-btn-annuler" onclick="reinitCatForm()">Annuler</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-md-8">
    <div class="card h-100" style="border:1px solid #e5e7eb">
      <div class="card-header py-2 px-3" style="background:#f8faff;border-bottom:1px solid #e5e7eb">
        <span class="fw-bold" style="font-size:.8rem;color:#374151">Catégories — <?= count($categories) ?></span>
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0" style="font-size:.78rem">
          <thead style="background:#fbfbfd">
            <tr>
              <th>Libellé</th>
              <th>Description</th>
              <th class="text-end" style="width:70px">Nb</th>
              <th class="text-end" style="width:120px">Total dépensé</th>
              <th class="text-end" style="width:90px">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$categories): ?>
              <tr><td colspan="5" class="text-center text-muted py-3">Aucune catégorie de dépense.</td></tr>
            <?php else: foreach ($categories as $c): ?>
              <tr>
                <td class="fw-semibold"><?= h($c['libelle']) ?></td>
                <td class="text-muted"><?= h($c['description'] ?: '—') ?></td>
                <td class="text-end"><?= (int) $c['nb_depenses'] ?></td>
                <td class="text-end fw-semibold"><?= number_format((float) $c['total_depense'], 0, ',', ' ') ?> F</td>
                <td class="text-end">
                  <button type="button" class="btn btn-sm btn-light" style="padding:3px 7px" title="Modifier"
                          onclick="editCat(<?= (int) $c['id_categorie'] ?>,<?= h(json_encode($c['libelle'])) ?>,<?= h(json_encode($c['description'])) ?>)">
                    <i class="bi bi-pencil" style="font-size:.72rem"></i>
                  </button>
                  <form method="post" class="d-inline" data-ajax-post-form onsubmit="return confirm('Supprimer cette catégorie ?')">
                    <?= csrf_champ() ?>
                    <input type="hidden" name="action" value="supprimer">
                    <input type="hidden" name="id_categorie" value="<?= (int) $c['id_categorie'] ?>">
                    <button type="submit" class="btn btn-sm btn-light text-danger" style="padding:3px 7px" <?= $c['nb_depenses'] > 0 ? 'disabled title="Des dépenses y sont rattachées"' : '' ?>>
                      <i class="bi bi-trash" style="font-size:.72rem"></i>
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
function editCat(id, lib, desc) {
    document.getElementById('cat_action').value      = 'modifier';
    document.getElementById('cat_id').value          = id;
    document.getElementById('cat_libelle').value     = lib;
    document.getElementById('cat_description').value = desc || '';
    document.getElementById('cat-form-title').textContent = 'Modifier la catégorie';
    document.getElementById('cat-btn-submit').textContent = 'Enregistrer';
    document.getElementById('cat-btn-annuler').classList.remove('d-none');
    document.getElementById('cat_libelle').focus();
}
function reinitCatForm() {
    document.getElementById('form-cat').reset();
    document.getElementById('cat_action').value = 'creer';
    document.getElementById('cat_id').value     = '0';
    document.getElementById('cat-form-title').textContent = 'Ajouter une catégorie';
    document.getElementById('cat-btn-submit').textContent = 'Ajouter';
    document.getElementById('cat-btn-annuler').classList.add('d-none');
}
</script>

</div><!-- /#cat-depenses-zone -->
<?php if ($es_partiel) exit; // rien de plus dans une réponse AJAX partielle. ?>

<?php
$ajax_zone_id = 'cat-depenses-zone'; // voir layout/footer.php — initAjaxZone() y est appelé
require_once __DIR__ . '/../../layout/footer.php';
