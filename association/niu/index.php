<?php
// association/niu/index.php — registre NIU de l'association.
//  Onglet « Élèves du réseau » : liste paginée de TOUS les élèves de
//    toutes les écoles + recherche (nom, NIU, matricule, parent) + filtres
//    (école, avec/sans NIU). Bouton « Générer » par élève sans NIU.
//  Onglet « Générer les NIU » : génération en masse des NIU manquants,
//    par école ou pour tout le réseau. Réglage du format (sigle par école).
// Génération / modification d'un NIU : superadmin de l'association uniquement.
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/../_layout.php';
exiger_membre_association();

$superadmin = est_superadmin_association();
$par        = 'membre:' . (membre_connecte()['login'] ?? '?');
$onglet     = ($_GET['onglet'] ?? 'eleves') === 'generer' ? 'generer' : 'eleves';
$msg = ''; $err = ''; $rapport = null;

// ── Actions (superadmin) ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    if (!$superadmin) {
        $err = "Action réservée au superadministrateur de l'association.";
    } elseif (($_POST['op'] ?? '') === 'generer_un') {
        // NIU pour un élève précis (ligne de la liste)
        @set_time_limit(60);
        $id_etab  = (int) ($_POST['ecole_id'] ?? 0);
        $id_eleve = (int) ($_POST['id_eleve'] ?? 0);
        $e = assoc_one("SELECT * FROM etablissement WHERE id=?", [$id_etab]);
        if ($e && $id_eleve) {
            try {
                $l = mysqli_connect(DB_HOST, DB_USER, DB_PASS, $e['db_name']);
                mysqli_set_charset($l, 'utf8mb4');
                $el = mysqli_fetch_assoc(mysqli_query($l,
                    "SELECT Nom_elv, Prenom_elv, Date_naiss_elv, Sexe_elv, Lieu_naiss_elv, niu
                     FROM eleve WHERE id_eleve=" . $id_eleve));
                if ($el && trim((string) $el['niu']) === '') {
                    $va = mysqli_fetch_row(mysqli_query($l,
                        "SELECT val_annee FROM annee_scolaire WHERE Etat_annee_scolaire=1 LIMIT 1"))[0] ?? null;
                    $niu = assoc_niu_generer_pour($id_etab, [
                        'nom' => $el['Nom_elv'], 'prenom' => $el['Prenom_elv'],
                        'date_naiss' => $el['Date_naiss_elv'] ?: null, 'sexe' => $el['Sexe_elv'] ?: null,
                        'lieu_naiss' => $el['Lieu_naiss_elv'] ?: null,
                    ], $par, $va);
                    if ($niu) {
                        $st = mysqli_prepare($l, "UPDATE eleve SET niu=? WHERE id_eleve=?");
                        mysqli_stmt_bind_param($st, 'si', $niu, $id_eleve);
                        mysqli_stmt_execute($st);
                        journaliser_action('niu_creation', $id_etab, $niu);
                        $msg = "NIU attribué : $niu";
                    } else {
                        $err = "Génération impossible, réessayez.";
                    }
                } else {
                    $err = "Cet élève a déjà un NIU (ou est introuvable).";
                }
                mysqli_close($l);
            } catch (\Throwable $ex) { $err = "Base école injoignable."; }
        }
    } elseif (($_POST['op'] ?? '') === 'generer_masse') {
        @set_time_limit(600);
        $scope   = $_POST['scope'] ?? 'tout';
        $id_etab = $scope === 'tout' ? null : (int) $scope;
        $rapport = assoc_niu_generer_manquants($id_etab, $par);
        $msg = $rapport['total_crees'] . " NIU généré(s).";
        $onglet = 'generer';
    } elseif (($_POST['op'] ?? '') === 'sigle') {
        $id_etab = (int) ($_POST['ecole_id'] ?? 0);
        $s = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $_POST['niu_sigle'] ?? ''));
        $s = substr($s, 0, 3);
        if ($id_etab && strlen($s) === 3) {
            assoc_exec("UPDATE etablissement SET niu_sigle=? WHERE id=?", [$s, $id_etab]);
            journaliser_action('niu_config', $id_etab, "sigle NIU = $s");
            $msg = "Sigle NIU mis à jour ($s).";
        } else {
            $err = "Le sigle NIU doit faire exactement 3 caractères (A–Z, 0–9).";
        }
        $onglet = 'generer';
    }
}

$ecoles = assoc_all("SELECT id, code, nom, sigle, niu_sigle FROM etablissement WHERE actif=1 ORDER BY nom");

asso_haut('Registre NIU');
$csrf = csrf_generer();
?>
<?php if ($msg): ?><div class="alert alert-success py-2 small"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-warning py-2 small"><?= h($err) ?></div><?php endif; ?>

<ul class="nav nav-tabs mb-3" style="font-size:.88rem">
  <li class="nav-item">
    <a class="nav-link <?= $onglet === 'eleves' ? 'active' : '' ?>" href="<?= APP_URL ?>/association/niu/index.php?onglet=eleves">
      <i class="bi bi-people me-1"></i>Élèves du réseau
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $onglet === 'generer' ? 'active' : '' ?>" href="<?= APP_URL ?>/association/niu/index.php?onglet=generer">
      <i class="bi bi-magic me-1"></i>Générer les NIU
    </a>
  </li>
</ul>

<?php if ($onglet === 'eleves'): ?>
<?php
  // Réseau entier chargé une seule fois : école / NIU / texte se filtrent
  // ensuite côté navigateur, au fur et à mesure, sans rechargement.
  $data     = assoc_eleves_systeme([], 1, 100000);
  $CAP      = 4000;
  $lignes   = array_slice($data['lignes'], 0, $CAP);
  $tronque  = count($data['lignes']) > $CAP;
  $strip = fn($s) => strtr(mb_strtolower((string) $s),
      ['à'=>'a','â'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','î'=>'i','ï'=>'i',
       'ô'=>'o','ö'=>'o','û'=>'u','ù'=>'u','ü'=>'u','ç'=>'c']);
?>

<div class="asso-card mb-3">
  <div class="row g-2 align-items-end">
    <div class="col-12 col-md-5">
      <label class="form-label small">Recherche</label>
      <input type="text" id="niu-q" class="form-control form-control-sm" autocomplete="off"
             placeholder="nom, NIU, matricule interne, parent…">
    </div>
    <div class="col-6 col-md-5">
      <label class="form-label small">École</label>
      <select id="niu-etab" class="form-select form-select-sm">
        <option value="">— toutes —</option>
        <?php foreach ($ecoles as $e): ?>
          <option value="<?= (int) $e['id'] ?>" <?= (int) ($_GET['etab'] ?? 0) === (int) $e['id'] ? 'selected' : '' ?>><?= h($e['code'] . ' — ' . $e['nom']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small">NIU</label>
      <select id="niu-filtre" class="form-select form-select-sm">
        <option value="">— tous —</option>
        <option value="avec">avec NIU</option>
        <option value="sans">sans NIU</option>
      </select>
    </div>
  </div>
  <div class="small text-muted2 mt-2">
    <span id="niu-compte"><?= count($lignes) ?></span> affiché(s) · <?= (int) $data['sans_niu'] ?> sans NIU
    <?php if ($tronque): ?> · <span class="text-warning">liste limitée à <?= $CAP ?> — affinez la recherche</span><?php endif; ?>
  </div>
</div>

<?php if ($superadmin): ?>
<form method="post" id="niu-gen-form" class="d-none">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
  <input type="hidden" name="op" value="generer_un">
  <input type="hidden" name="ecole_id" id="gen-ecole">
  <input type="hidden" name="id_eleve" id="gen-eleve">
</form>
<?php endif; ?>

<div class="asso-card p-0">
  <div class="table-responsive">
  <table class="table table-sm mb-0 align-middle" style="font-size:.82rem">
    <thead><tr class="text-muted2">
      <th>École</th><th>Élève</th><th>Naissance</th><th>Matricule</th><th>NIU</th><th></th>
    </tr></thead>
    <tbody id="niu-tbody">
      <?php foreach ($lignes as $r): ?>
        <tr data-ecole="<?= (int) $r['ecole_id'] ?>"
            data-niu="<?= $r['niu'] !== '' ? 'avec' : 'sans' ?>"
            data-txt="<?= h($strip($r['nom'] . ' ' . $r['niu'] . ' ' . $r['mat'] . ' ' . ($r['parents'] ?? ''))) ?>">
          <td><span class="badge badge-soft"><?= h($r['ecole_code']) ?></span></td>
          <td><?= h($r['nom']) ?>
            <?php if ($r['parents']): ?><div class="small text-muted2"><?= h($r['parents']) ?></div><?php endif; ?>
          </td>
          <td class="text-muted2"><?= h($r['naiss'] ?: '—') ?></td>
          <td class="font-monospace small"><?= h($r['mat'] ?: '—') ?></td>
          <td class="font-monospace">
            <?php if ($r['niu'] !== ''): ?>
              <a href="<?= APP_URL ?>/association/niu/voir.php?niu=<?= urlencode($r['niu']) ?>" class="text-decoration-none"><?= h($r['niu']) ?></a>
            <?php else: ?>
              <span class="text-warning small">aucun</span>
            <?php endif; ?>
          </td>
          <td class="text-end">
            <?php if ($r['niu'] === '' && $superadmin): ?>
              <button type="button" class="btn btn-outline-primary btn-sm py-0"
                      onclick="niuGenerer(<?= (int) $r['ecole_id'] ?>,<?= (int) $r['id_eleve'] ?>)">
                <i class="bi bi-magic"></i> Générer
              </button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <div id="niu-vide" class="text-center text-muted2 py-3 d-none">Aucun élève pour ces critères.</div>
</div>

<script>
(function () {
  var q = document.getElementById('niu-q'),
      selEtab = document.getElementById('niu-etab'),
      selNiu = document.getElementById('niu-filtre'),
      rows = Array.prototype.slice.call(document.querySelectorAll('#niu-tbody tr')),
      compte = document.getElementById('niu-compte'),
      vide = document.getElementById('niu-vide'),
      t;
  function norm(s){ return s.toLowerCase()
      .replace(/[àâä]/g,'a').replace(/[éèêë]/g,'e').replace(/[îï]/g,'i')
      .replace(/[ôö]/g,'o').replace(/[ûùü]/g,'u').replace(/ç/g,'c'); }
  function filtrer(){
    var texte = norm(q.value.trim()), ec = selEtab.value, nf = selNiu.value, n = 0;
    rows.forEach(function (tr) {
      var ok = (!texte || tr.dataset.txt.indexOf(texte) !== -1)
            && (!ec || tr.dataset.ecole === ec)
            && (!nf || tr.dataset.niu === nf);
      tr.hidden = !ok;
      if (ok) n++;
    });
    compte.textContent = n;
    vide.classList.toggle('d-none', n !== 0);
  }
  q.addEventListener('input', function () { clearTimeout(t); t = setTimeout(filtrer, 120); });
  selEtab.addEventListener('change', filtrer);
  selNiu.addEventListener('change', filtrer);
  filtrer();
})();
function niuGenerer(idEcole, idEleve) {
  var f = document.getElementById('niu-gen-form');
  if (!f) return;
  document.getElementById('gen-ecole').value = idEcole;
  document.getElementById('gen-eleve').value = idEleve;
  f.submit();
}
</script>

<?php else: /* ── Onglet Générer ─────────────────────────────────── */ ?>

<?php
  // Comptage des NIU manquants par école (léger : COUNT par base).
  $etat = [];
  $total_manquants = 0;
  foreach ($ecoles as $e) {
    $r = assoc_one("SELECT db_name FROM etablissement WHERE id=?", [$e['id']]);
    $n = null;
    try {
      $l = mysqli_connect(DB_HOST, DB_USER, DB_PASS, $r['db_name']);
      mysqli_set_charset($l, 'utf8mb4');
      $n = (int) mysqli_fetch_row(mysqli_query($l,
        "SELECT SUM(niu IS NULL OR niu='') FROM eleve WHERE statut='actif'"))[0];
      mysqli_close($l);
      $total_manquants += $n;
    } catch (\Throwable $ex) { $n = null; }
    $etat[$e['id']] = $n;
  }
  // Sigles NIU en double (ambiguïté) ?
  $sigles = [];
  foreach ($ecoles as $e) { $sigles[assoc_niu_sigle($e)][] = $e['code']; }
  $doublons_sigle = array_filter($sigles, fn($v) => count($v) > 1);
?>

<?php if (!$superadmin): ?>
  <div class="asso-card text-muted2">La génération des NIU est réservée au superadministrateur de l'association.</div>
<?php else: ?>

<?php if ($rapport): ?>
<div class="asso-card mb-3">
  <div class="fw-bold mb-2">Résultat</div>
  <table class="table table-sm mb-0"><tbody>
    <?php foreach ($rapport['ecoles'] as $lg): ?>
      <tr>
        <td><span class="badge badge-soft"><?= h($lg['code']) ?></span> <?= h($lg['nom']) ?></td>
        <td class="text-end small">
          <?php if ($lg['erreur']): ?><span class="text-warning"><?= h($lg['erreur']) ?></span>
          <?php else: ?><strong class="text-success"><?= (int) $lg['crees'] ?></strong> créés · <?= (int) $lg['deja'] ?> déjà · <?= (int) $lg['total'] ?> élèves<?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody></table>
</div>
<?php endif; ?>

<?php if ($doublons_sigle): ?>
<div class="alert alert-warning py-2 small">
  <i class="bi bi-exclamation-triangle me-1"></i>
  Des écoles partagent le même sigle NIU (<?= h(implode(' ; ', array_map(fn($c) => implode('/', $c), $doublons_sigle))) ?>) —
  leurs NIU ne permettront pas de distinguer l'école. Corrigez les sigles ci-dessous.
</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-12 col-lg-7">
    <div class="asso-card">
      <div class="fw-bold mb-2"><i class="bi bi-magic me-1"></i>Générer les NIU manquants</div>
      <div class="small text-muted2 mb-3">
        Attribue un NIU à chaque élève actif qui n'en a pas (format
        <span class="font-monospace"><?= h((defined('NIU_PREFIXE') ? NIU_PREFIXE : 'PMC')) ?>+sigle+année+n°</span>).
        Total à générer : <strong><?= (int) $total_manquants ?></strong>.
      </div>
      <form method="post" class="d-flex flex-wrap gap-2 align-items-center"
            onsubmit="return confirm('Générer les NIU manquants ? Opération non réversible.');">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="op" value="generer_masse">
        <select name="scope" class="form-select form-select-sm" style="max-width:280px">
          <option value="tout">Toutes les écoles (<?= (int) $total_manquants ?>)</option>
          <?php foreach ($ecoles as $e): if (!$etat[$e['id']]) continue; ?>
            <option value="<?= (int) $e['id'] ?>"><?= h($e['code'] . ' — ' . $e['nom']) ?> (<?= (int) $etat[$e['id']] ?>)</option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-primary btn-sm"><i class="bi bi-lightning-charge me-1"></i>Générer</button>
        <a href="<?= APP_URL ?>/association/niu/creer.php" class="btn btn-outline-light btn-sm">
          <i class="bi bi-plus-lg me-1"></i>NIU manuel (élève hors système)
        </a>
      </form>
    </div>
  </div>

  <div class="col-12 col-lg-5">
    <div class="asso-card">
      <div class="fw-bold mb-2"><i class="bi bi-sliders me-1"></i>Sigle NIU par école (3 caractères)</div>
      <table class="table table-sm mb-0 align-middle" style="font-size:.85rem">
        <tbody>
          <?php foreach ($ecoles as $e): ?>
          <tr>
            <td><span class="badge badge-soft"><?= h($e['code']) ?></span>
              <div class="small text-muted2"><?= h(mb_strimwidth($e['nom'], 0, 34, '…')) ?></div></td>
            <td style="width:150px">
              <form method="post" class="d-flex gap-1">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="op" value="sigle">
                <input type="hidden" name="ecole_id" value="<?= (int) $e['id'] ?>">
                <input name="niu_sigle" maxlength="3" required
                       class="form-control form-control-sm font-monospace text-uppercase" style="width:70px"
                       value="<?= h(assoc_niu_sigle($e)) ?>">
                <button class="btn btn-outline-primary btn-sm py-0">OK</button>
              </form>
            </td>
            <td class="text-end small text-muted2"><?= (int) ($etat[$e['id']] ?? 0) ?> sans NIU</td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <div class="small text-muted2 mt-2">Ne renommez pas un sigle après avoir généré des NIU : les anciens NIU garderaient l'ancien sigle.</div>
    </div>
  </div>
</div>

<?php endif; /* superadmin */ ?>
<?php endif; /* onglet */ ?>

<?php asso_bas();
