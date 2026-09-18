<?php
// secondaire/pages/paiements_prives/obligations.php — PAIEMENT PRIVÉ >
// Obligations (frais par niveau) : porté de pages/finances/obligations.php
// (primaire), adapté au schéma secondaire (table obligation_privee,
// code_niveau au lieu de niveau_obligation texte libre).
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'INTENDANT']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $action = post('action');

    if ($action === 'creer') {
        $code_niveau = post('code_niveau');
        $nom         = post('nom_obligation');
        $montant     = (float) str_replace([' ', ','], ['', '.'], post('montant_obligation'));
        if ($code_niveau !== '' && $nom !== '' && $montant > 0) {
            db_exec("INSERT INTO obligation_privee (nom_obligation, montant_obligation, code_niveau) VALUES (?, ?, ?)", [$nom, $montant, $code_niveau]);
            flash_set('succes', 'Obligation ajoutée.');
        } else {
            flash_set('erreur', 'Niveau, libellé et montant (positif) sont requis.');
        }
        rediriger('secondaire/pages/paiements_prives/obligations.php');
    }

    if ($action === 'modifier') {
        $id          = (int) post('id_obligation');
        $code_niveau = post('code_niveau');
        $nom         = post('nom_obligation');
        $montant     = (float) str_replace([' ', ','], ['', '.'], post('montant_obligation'));
        if ($id && $code_niveau !== '' && $nom !== '' && $montant > 0) {
            db_exec("UPDATE obligation_privee SET nom_obligation=?, montant_obligation=?, code_niveau=? WHERE id=?", [$nom, $montant, $code_niveau, $id]);
            flash_set('succes', 'Obligation modifiée.');
        }
        rediriger('secondaire/pages/paiements_prives/obligations.php');
    }

    if ($action === 'supprimer') {
        $id = (int) post('id_obligation');
        $nb = (int) db_val("SELECT COUNT(*) FROM paiement_prive WHERE id_obligation=?", [$id]);
        if ($nb > 0) {
            flash_set('erreur', "Impossible : $nb versement(s) sont déjà rattachés à cette obligation.");
        } else {
            db_exec("DELETE FROM obligation_privee WHERE id=?", [$id]);
            flash_set('succes', 'Obligation supprimée.');
        }
        rediriger('secondaire/pages/paiements_prives/obligations.php');
    }
}

$niveaux = db_all("SELECT code_niveau, libelle_niv, ordre_niveau FROM niveau ORDER BY ordre_niveau");

$obligations = db_all(
    "SELECT o.*, n.ordre_niveau, n.libelle_niv FROM obligation_privee o
     LEFT JOIN niveau n ON n.code_niveau = o.code_niveau
     ORDER BY n.ordre_niveau, o.nom_obligation"
);
$par_niveau = [];
foreach ($obligations as $o) {
    $par_niveau[$o['code_niveau']]['lignes'][] = $o;
    $par_niveau[$o['code_niveau']]['total'] = ($par_niveau[$o['code_niveau']]['total'] ?? 0) + (float) $o['montant_obligation'];
    $par_niveau[$o['code_niveau']]['ordre'] = $o['ordre_niveau'];
    $par_niveau[$o['code_niveau']]['libelle'] = $o['libelle_niv'] ?: $o['code_niveau'];
}
uasort($par_niveau, fn($a, $b) => ($a['ordre'] ?? 999) <=> ($b['ordre'] ?? 999));

$es_partiel = isset($_GET['partiel']);
if (!$es_partiel) {
    $titre_page = 'Obligations (privé)';
    require_once __DIR__ . '/../../../layout/header.php';
} else {
    header('Content-Type: text/html; charset=utf-8');
}
?>

<div id="obligations-prive-zone">

<div class="page-titre d-flex justify-content-between align-items-center flex-wrap gap-2">
  <div>
    <h4><i class="bi bi-card-checklist me-1 text-primary"></i>Paiement privé — Obligations (frais par niveau)</h4>
    <div class="sub">Le montant dû par un élève = somme de toutes les obligations de son niveau.</div>
  </div>
  <?php if ($obligations): ?>
  <a class="btn btn-outline-success btn-sm" href="<?= APP_URL ?>/secondaire/pages/paiements_prives/excel_obligations.php">
    <i class="bi bi-file-earmark-excel me-1"></i>Excel
  </a>
  <?php endif; ?>
</div>

<div class="row g-2" style="align-items:flex-start">

  <div class="col-md-4">
    <div class="card h-100" style="border:1px solid #c7d2fe">
      <div class="card-header py-2 px-3" style="background:linear-gradient(135deg,#eef2ff,#e0e7ff);border-bottom:1px solid #c7d2fe">
        <span class="fw-bold" style="font-size:.8rem;color:#312e81">
          <i class="bi bi-plus-circle-fill me-1"></i><span id="obp-form-title">Ajouter une obligation</span>
        </span>
      </div>
      <div class="card-body p-3">
        <form method="post" id="form-obp" data-ajax-post-form>
          <?= csrf_champ() ?>
          <input type="hidden" name="action" id="obp_action" value="creer">
          <input type="hidden" name="id_obligation" id="obp_id" value="0">
          <div class="mb-2">
            <label class="form-label" style="font-size:.75rem">Niveau</label>
            <select name="code_niveau" id="obp_niveau" class="form-select form-select-sm" required>
              <option value="">— Choisir —</option>
              <?php foreach ($niveaux as $n): ?>
                <option value="<?= h($n['code_niveau']) ?>"><?= h($n['libelle_niv'] ?: $n['code_niveau']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-2">
            <label class="form-label" style="font-size:.75rem">Libellé</label>
            <input type="text" name="nom_obligation" id="obp_nom" class="form-control form-control-sm" maxlength="200" placeholder="ex. SCOLARITÉ, INSCRIPTION, APEE" required>
          </div>
          <div class="mb-3">
            <label class="form-label" style="font-size:.75rem">Montant (FCFA)</label>
            <input type="number" name="montant_obligation" id="obp_montant" class="form-control form-control-sm" min="1" step="1" required>
          </div>
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-sm btn-primary" id="obp-btn-submit">Ajouter</button>
            <button type="button" class="btn btn-sm btn-light d-none" id="obp-btn-annuler" onclick="reinitObpForm()">Annuler</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-md-8">
    <?php if (!$par_niveau): ?>
      <div class="alert alert-light text-muted text-center py-4">Aucune obligation configurée pour l'instant.</div>
    <?php else: foreach ($par_niveau as $code_niveau => $grp): ?>
    <div class="card mb-2" style="border:1px solid #e5e7eb">
      <div class="card-header py-2 px-3 d-flex justify-content-between align-items-center" style="background:#f8faff;border-bottom:1px solid #e5e7eb">
        <span class="fw-bold" style="font-size:.82rem;color:#374151">Niveau <?= h($grp['libelle']) ?></span>
        <span class="badge bg-light text-dark border">Total : <?= number_format($grp['total'], 0, ',', ' ') ?> FCFA</span>
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0" style="font-size:.78rem">
          <thead style="background:#fbfbfd">
            <tr><th>Libellé</th><th class="text-end" style="width:130px">Montant</th><th class="text-end" style="width:90px">Actions</th></tr>
          </thead>
          <tbody>
            <?php foreach ($grp['lignes'] as $o): ?>
              <tr>
                <td><?= h($o['nom_obligation']) ?></td>
                <td class="text-end fw-semibold"><?= number_format((float) $o['montant_obligation'], 0, ',', ' ') ?> F</td>
                <td class="text-end">
                  <button type="button" class="btn btn-sm btn-light" style="padding:3px 7px" title="Modifier"
                          onclick="editObp(<?= (int) $o['id'] ?>,<?= h(json_encode($o['code_niveau'])) ?>,<?= h(json_encode($o['nom_obligation'])) ?>,<?= (float) $o['montant_obligation'] ?>)">
                    <i class="bi bi-pencil" style="font-size:.72rem"></i>
                  </button>
                  <form method="post" class="d-inline" data-ajax-post-form onsubmit="return confirm('Supprimer cette obligation ?')">
                    <?= csrf_champ() ?>
                    <input type="hidden" name="action" value="supprimer">
                    <input type="hidden" name="id_obligation" value="<?= (int) $o['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-light text-danger" style="padding:3px 7px">
                      <i class="bi bi-trash" style="font-size:.72rem"></i>
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<script>
function editObp(id, niveau, nom, montant) {
    document.getElementById('obp_action').value  = 'modifier';
    document.getElementById('obp_id').value      = id;
    document.getElementById('obp_niveau').value  = niveau;
    document.getElementById('obp_nom').value     = nom;
    document.getElementById('obp_montant').value = montant;
    document.getElementById('obp-form-title').textContent = 'Modifier l\'obligation';
    document.getElementById('obp-btn-submit').textContent = 'Enregistrer';
    document.getElementById('obp-btn-annuler').classList.remove('d-none');
    document.getElementById('obp_nom').focus();
}
function reinitObpForm() {
    document.getElementById('form-obp').reset();
    document.getElementById('obp_action').value = 'creer';
    document.getElementById('obp_id').value     = '0';
    document.getElementById('obp-form-title').textContent = 'Ajouter une obligation';
    document.getElementById('obp-btn-submit').textContent = 'Ajouter';
    document.getElementById('obp-btn-annuler').classList.add('d-none');
}
</script>

</div><!-- /#obligations-prive-zone -->
<?php if ($es_partiel) exit; ?>

<?php
$ajax_zone_id = 'obligations-prive-zone';
require_once __DIR__ . '/../../../layout/footer.php';
