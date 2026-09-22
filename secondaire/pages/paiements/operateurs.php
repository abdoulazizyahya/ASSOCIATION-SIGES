<?php
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_role(['ADMIN','PROVISEUR', 'FONDATEUR','CENSEUR']);

$logo_dir = __DIR__ . '/../../../assets/uploads/operateurs/';
if (!is_dir($logo_dir)) @mkdir($logo_dir, 0755, true);

function sauver_logo_operateur(): ?string {
    global $logo_dir;
    if (empty($_FILES['logo']['tmp_name']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) return null;
    $ext_ok = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    $ext    = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
    $fi     = finfo_open(FILEINFO_MIME_TYPE);
    $mime   = finfo_file($fi, $_FILES['logo']['tmp_name']);
    finfo_close($fi);
    if (!isset($ext_ok[$ext]) || $ext_ok[$ext] !== $mime || $_FILES['logo']['size'] > 1024 * 1024) return null;
    $nom = 'op_' . bin2hex(random_bytes(8)) . '.' . $ext;
    move_uploaded_file($_FILES['logo']['tmp_name'], $logo_dir . $nom);
    return $nom;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $action = post('action');

    if ($action === 'creer') {
        $id      = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '', post('id')));
        $libelle = post('libelle');
        if (!$id || !$libelle) {
            flash_set('erreur', 'Identifiant et libellé sont obligatoires.');
            rediriger('secondaire/pages/paiements/operateurs.php');
        }
        if (db_val("SELECT COUNT(*) FROM operateur_paiement WHERE id=?", [$id])) {
            flash_set('erreur', "L'identifiant « $id » est déjà utilisé.");
            rediriger('secondaire/pages/paiements/operateurs.php');
        }
        $logo = sauver_logo_operateur();
        db_exec("INSERT INTO operateur_paiement (id, libelle, logo) VALUES (?,?,?)", [$id, $libelle, $logo]);
        flash_set('succes', 'Opérateur ajouté.');
        rediriger('secondaire/pages/paiements/operateurs.php');
    }

    if ($action === 'modifier') {
        $id      = post('id');
        $libelle = post('libelle');
        if (!$id || !$libelle) {
            flash_set('erreur', 'Libellé obligatoire.');
            rediriger('secondaire/pages/paiements/operateurs.php');
        }
        $ancien = db_one("SELECT logo FROM operateur_paiement WHERE id=?", [$id]);
        $logo   = sauver_logo_operateur();
        if ($logo) {
            db_exec("UPDATE operateur_paiement SET libelle=?, logo=? WHERE id=?", [$libelle, $logo, $id]);
            if (!empty($ancien['logo']) && is_file($logo_dir . $ancien['logo'])) @unlink($logo_dir . $ancien['logo']);
        } else {
            db_exec("UPDATE operateur_paiement SET libelle=? WHERE id=?", [$libelle, $id]);
        }
        flash_set('succes', 'Opérateur mis à jour.');
        rediriger('secondaire/pages/paiements/operateurs.php');
    }

    if ($action === 'supprimer') {
        $id = post('id');
        $nb_utilise = (int) db_val("SELECT COUNT(*) FROM paiement_frais WHERE id_operateur=?", [$id]);
        if ($nb_utilise > 0) {
            flash_set('erreur', "Impossible de supprimer « $id » : utilisé dans $nb_utilise paiement(s) déjà enregistré(s).");
        } else {
            $op = db_one("SELECT logo FROM operateur_paiement WHERE id=?", [$id]);
            db_exec("DELETE FROM operateur_paiement WHERE id=?", [$id]);
            if ($op && !empty($op['logo']) && is_file($logo_dir . $op['logo'])) @unlink($logo_dir . $op['logo']);
            flash_set('succes', 'Opérateur supprimé.');
        }
        rediriger('secondaire/pages/paiements/operateurs.php');
    }
}

$operateurs = db_all("SELECT * FROM operateur_paiement ORDER BY libelle");

$titre_page = 'Opérateurs de paiement';
require_once __DIR__ . '/../../../layout/header.php';
?>
<div class="page-titre d-flex justify-content-between align-items-center">
  <div>
    <h4><i class="bi bi-credit-card-2-front me-1 text-primary"></i><?= h($titre_page) ?></h4>
    <div class="sub"><?= count($operateurs) ?> opérateur(s) configuré(s)</div>
  </div>
  <button class="btn btn-primary btn-sm" onclick="ouvrirCreerOp()">
    <i class="bi bi-plus-lg me-1"></i>Nouvel opérateur
  </button>
</div>

<div class="row g-2">
  <?php foreach ($operateurs as $op): ?>
  <div class="col-6 col-md-4 col-lg-3">
    <div class="card h-100 text-center">
      <div class="card-body py-3">
        <?php if (!empty($op['logo'])): ?>
          <img src="<?= APP_URL ?>/assets/uploads/operateurs/<?= h($op['logo']) ?>" alt="<?= h($op['libelle']) ?>"
               style="width:56px;height:56px;object-fit:contain;margin-bottom:.5rem">
        <?php else: ?>
          <div style="width:56px;height:56px;border-radius:12px;background:#eef2ff;display:flex;align-items:center;justify-content:center;margin:0 auto .5rem">
            <i class="bi bi-credit-card-2-front" style="font-size:1.5rem;color:#7c8bbd"></i>
          </div>
        <?php endif; ?>
        <div class="fw-semibold" style="font-size:.85rem"><?= h($op['libelle']) ?></div>
        <div class="text-muted" style="font-size:.7rem"><?= h($op['id']) ?></div>
        <div class="mt-2 d-flex justify-content-center gap-1">
          <button class="btn btn-sm btn-light" style="padding:2px 7px" title="Modifier"
                  onclick='ouvrirModifierOp(<?= json_encode($op) ?>)'>
            <i class="bi bi-pencil" style="font-size:.72rem"></i>
          </button>
          <button class="btn btn-sm btn-light text-danger" style="padding:2px 7px" title="Supprimer"
                  onclick="supprimerOp('<?= h(addslashes($op['id'])) ?>','<?= h(addslashes($op['libelle'])) ?>')">
            <i class="bi bi-trash" style="font-size:.72rem"></i>
          </button>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if (!$operateurs): ?>
    <div class="col-12 text-center text-muted py-4">Aucun opérateur configuré.</div>
  <?php endif; ?>
</div>

<!-- Modal Créer/Modifier -->
<div class="modal fade" id="modalOp" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header py-2" style="background:#f8faff">
        <h6 class="modal-title fw-bold" id="modalOpTitre"><i class="bi bi-credit-card-2-front me-1 text-primary"></i>Nouvel opérateur</h6>
        <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" enctype="multipart/form-data">
        <?= csrf_champ() ?>
        <input type="hidden" name="action" id="op-action" value="creer">
        <div class="modal-body row g-2">
          <div class="col-md-6">
            <label class="form-label">Identifiant *<span class="text-muted" style="font-weight:400"> (court, ex. OM)</span></label>
            <input type="text" name="id" id="op-id" class="form-control" required maxlength="20">
          </div>
          <div class="col-md-6">
            <label class="form-label">Libellé *</label>
            <input type="text" name="libelle" id="op-libelle" class="form-control" required placeholder="Ex. Orange Money">
          </div>
          <div class="col-12">
            <label class="form-label">Logo <span class="text-muted" style="font-weight:400">(JPG/PNG/WebP, 1 Mo max — laisser vide pour conserver l'actuel en modification)</span></label>
            <input type="file" name="logo" id="op-logo" class="form-control" accept="image/png,image/jpeg,image/webp">
          </div>
        </div>
        <div class="modal-footer py-2">
          <button class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Enregistrer</button>
          <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Annuler</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Form caché suppression -->
<form method="post" id="form-del-op" style="display:none">
  <?= csrf_champ() ?>
  <input type="hidden" name="action" value="supprimer">
  <input type="hidden" name="id" id="del-op-id">
</form>

<script>
function ouvrirCreerOp() {
    document.getElementById('modalOpTitre').innerHTML = '<i class="bi bi-credit-card-2-front me-1 text-primary"></i>Nouvel opérateur';
    document.getElementById('op-action').value = 'creer';
    document.getElementById('op-id').value = '';
    document.getElementById('op-id').readOnly = false;
    document.getElementById('op-libelle').value = '';
    document.getElementById('op-logo').value = '';
    new bootstrap.Modal(document.getElementById('modalOp')).show();
}
function ouvrirModifierOp(op) {
    document.getElementById('modalOpTitre').innerHTML = '<i class="bi bi-pencil me-1 text-primary"></i>Modifier l\'opérateur';
    document.getElementById('op-action').value = 'modifier';
    document.getElementById('op-id').value = op.id;
    document.getElementById('op-id').readOnly = true;
    document.getElementById('op-libelle').value = op.libelle;
    document.getElementById('op-logo').value = '';
    new bootstrap.Modal(document.getElementById('modalOp')).show();
}
function supprimerOp(id, libelle) {
    if (!confirm('Supprimer l\'opérateur « ' + libelle + ' » ?')) return;
    document.getElementById('del-op-id').value = id;
    document.getElementById('form-del-op').submit();
}
</script>

<?php require_once __DIR__ . '/../../../layout/footer.php'; ?>
