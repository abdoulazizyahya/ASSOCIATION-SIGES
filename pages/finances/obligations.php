<?php
// pages/finances/obligations.php — Finances > Obligations : catalogue des
// frais exigibles par niveau (`obligation` — table réelle héritée du
// legacy jaynitaare, PAS le modèle ABZ_MBE `obligation_frais` qui traîne
// mort dans pages/paiements/*). Un niveau peut avoir PLUSIEURS obligations
// distinctes (ex. niveau I : INSCRIPTION 5000, SCOLARITÉ 50000, APEE 1500 —
// confirmé sur les vraies données) : le montant dû par un élève est la
// somme de toutes les obligations de son niveau (voir pages/finances/
// versement.php et statistiques.php).
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_role(['DIRECTEUR']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $action = post('action');

    if ($action === 'creer') {
        $niveau  = post('niveau_obligation');
        $nom     = post('nom_obligation');
        $montant = (float) str_replace([' ', ','], ['', '.'], post('montant_obligation'));
        if ($niveau !== '' && $nom !== '' && $montant > 0) {
            db_exec("INSERT INTO obligation (nom_obligation, montant_obligation, niveau_obligation) VALUES (?, ?, ?)", [$nom, $montant, $niveau]);
            flash_set('succes', 'Obligation ajoutée.');
        } else {
            flash_set('erreur', 'Niveau, libellé et montant (positif) sont requis.');
        }
        rediriger('pages/finances/obligations.php');
    }

    if ($action === 'modifier') {
        $id      = (int) post('id_obligation');
        $niveau  = post('niveau_obligation');
        $nom     = post('nom_obligation');
        $montant = (float) str_replace([' ', ','], ['', '.'], post('montant_obligation'));
        if ($id && $niveau !== '' && $nom !== '' && $montant > 0) {
            db_exec("UPDATE obligation SET nom_obligation=?, montant_obligation=?, niveau_obligation=? WHERE id_obligation=?", [$nom, $montant, $niveau, $id]);
            flash_set('succes', 'Obligation modifiée.');
        }
        rediriger('pages/finances/obligations.php');
    }

    if ($action === 'supprimer') {
        $id = (int) post('id_obligation');
        $nb = (int) db_val("SELECT COUNT(*) FROM paiement_frais WHERE id_obligation=?", [$id]);
        if ($nb > 0) {
            flash_set('erreur', "Impossible : $nb versement(s) sont déjà rattachés à cette obligation.");
        } else {
            db_exec("DELETE FROM obligation WHERE id_obligation=?", [$id]);
            flash_set('succes', 'Obligation supprimée.');
        }
        rediriger('pages/finances/obligations.php');
    }
}

$niveaux = db_all("SELECT LibelleNiveau, OrdreNiveau FROM niveau WHERE actif=1 ORDER BY OrdreNiveau");

$obligations = db_all(
    "SELECT o.*, n.OrdreNiveau FROM obligation o
     LEFT JOIN niveau n ON n.LibelleNiveau = o.niveau_obligation
     ORDER BY n.OrdreNiveau, o.nom_obligation"
);
$par_niveau = [];
foreach ($obligations as $o) {
    $par_niveau[$o['niveau_obligation']]['lignes'][] = $o;
    $par_niveau[$o['niveau_obligation']]['total'] = ($par_niveau[$o['niveau_obligation']]['total'] ?? 0) + (float) $o['montant_obligation'];
    $par_niveau[$o['niveau_obligation']]['ordre'] = $o['OrdreNiveau'];
}
uasort($par_niveau, fn($a, $b) => ($a['ordre'] ?? 999) <=> ($b['ordre'] ?? 999));

$es_partiel = isset($_GET['partiel']);
if (!$es_partiel) {
    $titre_page = 'Obligations';
    require_once __DIR__ . '/../../layout/header.php';
} else {
    header('Content-Type: text/html; charset=utf-8');
}
?>

<div id="obligations-zone">

<div class="page-titre d-flex justify-content-between align-items-center flex-wrap gap-2">
  <div>
    <h4><i class="bi bi-card-checklist me-1 text-primary"></i>Finances — Obligations (frais par niveau)</h4>
    <div class="sub">Le montant dû par un élève = somme de toutes les obligations de son niveau.</div>
  </div>
  <?php if ($obligations): ?>
  <a class="btn btn-outline-success btn-sm" href="<?= APP_URL ?>/pages/finances/excel_obligations.php">
    <i class="bi bi-file-earmark-excel me-1"></i>Excel
  </a>
  <?php endif; ?>
</div>

<div class="row g-2" style="align-items:flex-start">

  <div class="col-md-4">
    <div class="card h-100" style="border:1px solid #c7d2fe">
      <div class="card-header py-2 px-3" style="background:linear-gradient(135deg,#eef2ff,#e0e7ff);border-bottom:1px solid #c7d2fe">
        <span class="fw-bold" style="font-size:.8rem;color:#312e81">
          <i class="bi bi-plus-circle-fill me-1"></i><span id="ob-form-title">Ajouter une obligation</span>
        </span>
      </div>
      <div class="card-body p-3">
        <form method="post" id="form-ob" data-ajax-post-form>
          <?= csrf_champ() ?>
          <input type="hidden" name="action" id="ob_action" value="creer">
          <input type="hidden" name="id_obligation" id="ob_id" value="0">
          <div class="mb-2">
            <label class="form-label" style="font-size:.75rem">Niveau</label>
            <select name="niveau_obligation" id="ob_niveau" class="form-select form-select-sm" required>
              <option value="">— Choisir —</option>
              <?php foreach ($niveaux as $n): ?>
                <option value="<?= h($n['LibelleNiveau']) ?>">Niveau <?= h($n['LibelleNiveau']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-2">
            <label class="form-label" style="font-size:.75rem">Libellé</label>
            <input type="text" name="nom_obligation" id="ob_nom" class="form-control form-control-sm" maxlength="200" placeholder="ex. SCOLARITÉ, INSCRIPTION, APEE" required>
          </div>
          <div class="mb-3">
            <label class="form-label" style="font-size:.75rem">Montant (FCFA)</label>
            <input type="number" name="montant_obligation" id="ob_montant" class="form-control form-control-sm" min="1" step="1" required>
          </div>
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-sm btn-primary" id="ob-btn-submit">Ajouter</button>
            <button type="button" class="btn btn-sm btn-light d-none" id="ob-btn-annuler" onclick="reinitObForm()">Annuler</button>
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
        <span class="fw-bold" style="font-size:.82rem;color:#374151">Niveau <?= h($code_niveau) ?></span>
        <span class="badge bg-light text-dark border">Total : <?= number_format($grp['total'], 0, ',', ' ') ?> FCFA</span>
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0" style="font-size:.78rem">
          <thead style="background:#fbfbfd">
            <tr>
              <th>Libellé</th>
              <th class="text-end" style="width:130px">Montant</th>
              <th class="text-end" style="width:90px">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($grp['lignes'] as $o): ?>
              <tr>
                <td><?= h($o['nom_obligation']) ?></td>
                <td class="text-end fw-semibold"><?= number_format((float) $o['montant_obligation'], 0, ',', ' ') ?> F</td>
                <td class="text-end">
                  <button type="button" class="btn btn-sm btn-light" style="padding:3px 7px" title="Modifier"
                          onclick="editOb(<?= (int) $o['id_obligation'] ?>,<?= h(json_encode($o['niveau_obligation'])) ?>,<?= h(json_encode($o['nom_obligation'])) ?>,<?= (float) $o['montant_obligation'] ?>)">
                    <i class="bi bi-pencil" style="font-size:.72rem"></i>
                  </button>
                  <form method="post" class="d-inline" data-ajax-post-form onsubmit="return confirm('Supprimer cette obligation ?')">
                    <?= csrf_champ() ?>
                    <input type="hidden" name="action" value="supprimer">
                    <input type="hidden" name="id_obligation" value="<?= (int) $o['id_obligation'] ?>">
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
function editOb(id, niveau, nom, montant) {
    document.getElementById('ob_action').value  = 'modifier';
    document.getElementById('ob_id').value      = id;
    document.getElementById('ob_niveau').value  = niveau;
    document.getElementById('ob_nom').value     = nom;
    document.getElementById('ob_montant').value = montant;
    document.getElementById('ob-form-title').textContent = 'Modifier l\'obligation';
    document.getElementById('ob-btn-submit').textContent = 'Enregistrer';
    document.getElementById('ob-btn-annuler').classList.remove('d-none');
    document.getElementById('ob_nom').focus();
}
function reinitObForm() {
    document.getElementById('form-ob').reset();
    document.getElementById('ob_action').value = 'creer';
    document.getElementById('ob_id').value     = '0';
    document.getElementById('ob-form-title').textContent = 'Ajouter une obligation';
    document.getElementById('ob-btn-submit').textContent = 'Ajouter';
    document.getElementById('ob-btn-annuler').classList.add('d-none');
}
</script>

</div><!-- /#obligations-zone -->
<?php if ($es_partiel) exit; // rien de plus dans une réponse AJAX partielle. ?>

<?php
$ajax_zone_id = 'obligations-zone'; // voir layout/footer.php — initAjaxZone() y est appelé
require_once __DIR__ . '/../../layout/footer.php';
