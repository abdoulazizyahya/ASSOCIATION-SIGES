<?php
// secondaire/pages/depenses_privees/categories.php — PAIEMENT PRIVÉ >
// Catégories de dépenses : porté de pages/depenses/categories.php
// (primaire), adapté au schéma secondaire (table categorie_depense_privee).
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'INTENDANT']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $action = post('action');

    if ($action === 'creer') {
        $lib  = post('libelle');
        $desc = post('description');
        if ($lib !== '') {
            db_exec("INSERT INTO categorie_depense_privee (libelle, description) VALUES (?, ?)", [$lib, $desc ?: null]);
            flash_set('succes', 'Catégorie ajoutée.');
        } else {
            flash_set('erreur', 'Le libellé est requis.');
        }
        rediriger('secondaire/pages/depenses_privees/categories.php');
    }

    if ($action === 'modifier') {
        $id   = (int) post('id_categorie');
        $lib  = post('libelle');
        $desc = post('description');
        if ($id && $lib !== '') {
            db_exec("UPDATE categorie_depense_privee SET libelle=?, description=? WHERE id=?", [$lib, $desc ?: null, $id]);
            flash_set('succes', 'Catégorie modifiée.');
        }
        rediriger('secondaire/pages/depenses_privees/categories.php');
    }

    if ($action === 'supprimer') {
        $id = (int) post('id_categorie');
        $nb = (int) db_val("SELECT COUNT(*) FROM depense_privee WHERE id_categorie=?", [$id]);
        if ($nb > 0) {
            flash_set('erreur', "Impossible : $nb dépense(s) sont déjà rattachées à cette catégorie.");
        } else {
            db_exec("DELETE FROM categorie_depense_privee WHERE id=?", [$id]);
            flash_set('succes', 'Catégorie supprimée.');
        }
        rediriger('secondaire/pages/depenses_privees/categories.php');
    }
}

$categories = db_all(
    "SELECT cd.*, COUNT(d.id) AS nb_depenses, COALESCE(SUM(d.montant),0) AS total_depense
     FROM categorie_depense_privee cd
     LEFT JOIN depense_privee d ON d.id_categorie = cd.id
     GROUP BY cd.id
     ORDER BY cd.libelle"
);

$es_partiel = isset($_GET['partiel']);
if (!$es_partiel) {
    $titre_page = 'Catégories de dépenses (privé)';
    require_once __DIR__ . '/../../../layout/header.php';
} else {
    header('Content-Type: text/html; charset=utf-8');
}
?>

<div id="cat-depenses-privees-zone">

<div class="page-titre d-flex justify-content-between align-items-center flex-wrap gap-2">
  <div>
    <h4><i class="bi bi-tags me-1 text-primary"></i>Paiement privé — Catégories de dépenses</h4>
    <div class="sub">Classement des dépenses de l'établissement.</div>
  </div>
  <div class="d-flex gap-2">
    <button type="button" class="btn btn-outline-danger btn-sm" onclick="afficherApercu('<?= APP_URL ?>/secondaire/pdf/prive_depenses_categories.php', 'Catégories de dépenses', null, 'portrait')">
      <i class="bi bi-file-earmark-pdf me-1"></i>Aperçu PDF
    </button>
    <a class="btn btn-outline-success btn-sm" href="<?= APP_URL ?>/secondaire/pages/depenses_privees/excel_categories.php">
      <i class="bi bi-file-earmark-excel me-1"></i>Excel
    </a>
  </div>
</div>

<div class="row g-2" style="align-items:flex-start">

  <div class="col-md-4">
    <div class="card h-100" style="border:1px solid #c7d2fe">
      <div class="card-header py-2 px-3" style="background:linear-gradient(135deg,#eef2ff,#e0e7ff);border-bottom:1px solid #c7d2fe">
        <span class="fw-bold" style="font-size:.8rem;color:#312e81">
          <i class="bi bi-plus-circle-fill me-1"></i><span id="catp-form-title">Ajouter une catégorie</span>
        </span>
      </div>
      <div class="card-body p-3">
        <form method="post" id="form-catp" data-ajax-post-form>
          <?= csrf_champ() ?>
          <input type="hidden" name="action" id="catp_action" value="creer">
          <input type="hidden" name="id_categorie" id="catp_id" value="0">
          <div class="mb-2">
            <label class="form-label" style="font-size:.75rem">Libellé</label>
            <input type="text" name="libelle" id="catp_libelle" class="form-control form-control-sm" maxlength="150" placeholder="ex. Salaires, Fournitures..." required>
          </div>
          <div class="mb-3">
            <label class="form-label" style="font-size:.75rem">Description (optionnel)</label>
            <textarea name="description" id="catp_description" class="form-control form-control-sm" rows="2" maxlength="255"></textarea>
          </div>
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-sm btn-primary" id="catp-btn-submit">Ajouter</button>
            <button type="button" class="btn btn-sm btn-light d-none" id="catp-btn-annuler" onclick="reinitCatpForm()">Annuler</button>
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
              <th>Libellé</th><th>Description</th><th class="text-end" style="width:70px">Nb</th>
              <th class="text-end" style="width:120px">Total dépensé</th><th class="text-end" style="width:90px">Actions</th>
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
                          onclick="editCatp(<?= (int) $c['id'] ?>,<?= h(json_encode($c['libelle'])) ?>,<?= h(json_encode($c['description'])) ?>)">
                    <i class="bi bi-pencil" style="font-size:.72rem"></i>
                  </button>
                  <form method="post" class="d-inline" data-ajax-post-form onsubmit="return confirm('Supprimer cette catégorie ?')">
                    <?= csrf_champ() ?>
                    <input type="hidden" name="action" value="supprimer">
                    <input type="hidden" name="id_categorie" value="<?= (int) $c['id'] ?>">
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
function editCatp(id, lib, desc) {
    document.getElementById('catp_action').value      = 'modifier';
    document.getElementById('catp_id').value          = id;
    document.getElementById('catp_libelle').value     = lib;
    document.getElementById('catp_description').value = desc || '';
    document.getElementById('catp-form-title').textContent = 'Modifier la catégorie';
    document.getElementById('catp-btn-submit').textContent = 'Enregistrer';
    document.getElementById('catp-btn-annuler').classList.remove('d-none');
    document.getElementById('catp_libelle').focus();
}
function reinitCatpForm() {
    document.getElementById('form-catp').reset();
    document.getElementById('catp_action').value = 'creer';
    document.getElementById('catp_id').value     = '0';
    document.getElementById('catp-form-title').textContent = 'Ajouter une catégorie';
    document.getElementById('catp-btn-submit').textContent = 'Ajouter';
    document.getElementById('catp-btn-annuler').classList.add('d-none');
}
</script>

</div><!-- /#cat-depenses-privees-zone -->
<?php if ($es_partiel) exit; ?>

<?php
$ajax_zone_id = 'cat-depenses-privees-zone';
require_once __DIR__ . '/../../../layout/footer.php';
