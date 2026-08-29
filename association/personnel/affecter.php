<?php
// association/personnel/affecter.php — affecter un agent à une école
//  Source : soit un agent existant du registre (?m=matricule), soit un
//  agent choisi dans une école source (identité copiée).
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/../_layout.php';
exiger_membre_association();

$ecoles = assoc_all("SELECT id, code, nom FROM etablissement WHERE actif=1 ORDER BY nom");
$mat_pre = (string) ($_GET['m'] ?? '');

$msg = ''; $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $matricule = trim($_POST['matricule'] ?? '');
    $id_source = (int) ($_POST['id_source'] ?? 0);
    $mat_ens_src = trim($_POST['mat_ens_src'] ?? '');
    $id_cible  = (int) ($_POST['id_cible'] ?? 0);
    $fonction  = $_POST['fonction'] ?? 'ENSEIGNANT';

    // Identité : depuis le registre si matricule connu, sinon depuis l'école source
    $ident = null;
    if ($matricule !== '' && ($p = assoc_one("SELECT * FROM personnel WHERE matricule=?", [$matricule]))) {
        $ident = ['nom' => $p['nom'], 'prenom' => $p['prenom'], 'sexe' => $p['sexe'],
                  'date_naiss' => $p['date_naissance'], 'tel' => $p['tel'], 'email' => $p['email']];
    } elseif ($id_source && $mat_ens_src !== '') {
        $row = avec_ecole($id_source, fn($l) => ecole_one($l,
            "SELECT nom_ens, prenom_ens, sexe_ens, date_naiss_ens, tel_ens, mail_ens, mat_ens
             FROM enseignant WHERE matricule_ens=?", [$mat_ens_src]));
        if ($row) {
            $matricule = trim((string) $row['mat_ens']) !== ''
                ? trim($row['mat_ens'])
                : (array_column($ecoles, 'code', 'id')[$id_source] ?? 'X') . '-' . $mat_ens_src;
            $ident = ['nom' => $row['nom_ens'], 'prenom' => $row['prenom_ens'], 'sexe' => $row['sexe_ens'],
                      'date_naiss' => $row['date_naiss_ens'], 'tel' => $row['tel_ens'], 'email' => $row['mail_ens']];
        }
    }

    if (!$ident || !$id_cible) {
        $err = 'Sélectionnez un agent et un établissement cible.';
    } else {
        [$ok, $m] = affecter_agent($matricule, $id_cible, $fonction, $ident);
        if ($ok) {
            journaliser_action('affectation', $id_cible, $matricule . ' → ' . $fonction);
            $msg = $m;
        } else {
            $err = $m;
        }
    }
}

// Données pour les <select> : agents par école source (chargées à la volée)
asso_haut('Affecter un agent');
?>
<a href="<?= APP_URL ?>/association/personnel/liste.php" class="small text-decoration-none">← Personnel</a>
<?php if ($msg): ?><div class="alert alert-success py-2 small mt-2"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-warning py-2 small mt-2"><?= h($err) ?></div><?php endif; ?>

<div class="asso-card mt-2" style="max-width:620px">
  <form method="post" class="row g-3">
    <input type="hidden" name="csrf" value="<?= h(csrf_generer()) ?>">

    <div class="col-12">
      <label class="form-label small fw-bold">Agent à affecter</label>
      <?php if ($mat_pre !== '' && ($pp = assoc_one("SELECT * FROM personnel WHERE matricule=?", [$mat_pre]))): ?>
        <input type="hidden" name="matricule" value="<?= h($mat_pre) ?>">
        <div class="small"><?= h(trim($pp['nom'] . ' ' . $pp['prenom'])) ?>
          <span class="text-muted2 font-monospace">(<?= h($mat_pre) ?>)</span></div>
      <?php else: ?>
        <div class="row g-2">
          <div class="col-6">
            <select name="id_source" class="form-select form-select-sm" id="src" data-filtre>
              <option value="">— École source —</option>
              <?php foreach ($ecoles as $e): ?>
                <option value="<?= (int) $e['id'] ?>"><?= h($e['nom']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6">
            <select name="mat_ens_src" class="form-select form-select-sm" id="agent" data-filtre>
              <option value="">— Agent —</option>
            </select>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <div class="col-6">
      <label class="form-label small">Établissement cible</label>
      <select name="id_cible" class="form-select form-select-sm" required>
        <option value="">— Choisir —</option>
        <?php foreach ($ecoles as $e): ?>
          <option value="<?= (int) $e['id'] ?>"><?= h($e['nom']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6">
      <label class="form-label small">Fonction</label>
      <select name="fonction" class="form-select form-select-sm">
        <?php foreach (['ENSEIGNANT', 'DIRECTEUR', 'SECRETAIRE', 'COMPTABLE'] as $f): ?>
          <option><?= $f ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="col-12">
      <button class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Affecter</button>
      <span class="small text-muted2 ms-2">Un compte est créé dans l'école cible si l'agent n'y en a pas.</span>
    </div>
  </form>
</div>

<script>
// Charge les agents de l'école source sélectionnée (JSON)
document.getElementById('src')?.addEventListener('change', function () {
  var sel = document.getElementById('agent');
  sel.innerHTML = '<option value="">…</option>';
  if (!this.value) { sel.innerHTML = '<option value="">— Agent —</option>'; return; }
  fetch('<?= APP_URL ?>/association/personnel/agents_json.php?id=' + this.value)
    .then(r => r.json())
    .then(list => {
      sel.innerHTML = '<option value="">— Agent —</option>' +
        list.map(a => '<option value="' + a.matricule_ens + '">' + a.nom + ' (' + a.fonction + ')</option>').join('');
    });
});
</script>
<?php asso_bas();
