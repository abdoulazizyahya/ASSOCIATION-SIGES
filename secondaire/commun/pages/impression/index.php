<?php
// secondaire/commun/pages/impression/index.php — Scolarité → Centre
// d'impression (10/11/2026), primaire et secondaire : toutes les listes PDF de
// l'école au même endroit (élèves, personnel, établissement) + raccourcis vers
// les documents déjà présents ailleurs dans SIGES.
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
require_once __DIR__ . '/_impression_lib.php';
exiger_role(imp_roles());

$onglet = array_key_exists($_GET['onglet'] ?? '', IMP_DOCS) ? $_GET['onglet'] : 'eleves';
$classes = ed_classes();
$sec = ed_secondaire();
$avec_classe = ['liste', 'annuaire', 'sans_tel', 'emargement'];
$raccourcis = $sec ? [
    ['Documents de classe (relevés, fiches statistiques, tableau d\'honneur)', 'secondaire/commun/pages/statistiques/documents.php', 'file-earmark-text'],
    ['Élèves (cartes scolaires, certificats, fiches)', 'secondaire/commun/pages/eleves/liste.php', 'person-vcard'],
    ['Couvertures de bulletins', 'secondaire/commun/pages/couvertures/index.php', 'journal-bookmark'],
    ['Billets & autorisations', 'secondaire/commun/pages/billets/index.php', 'ticket-perforated'],
] : [
    ['Élèves (cartes scolaires, certificats, fiches)', 'primaire/pages/eleves/liste.php', 'person-vcard'],
    ['Impayés', 'primaire/pages/finances/impayes.php', 'exclamation-triangle'],
    ['Couvertures de bulletins', 'secondaire/commun/pages/couvertures/index.php', 'journal-bookmark'],
    ['Billets & autorisations', 'secondaire/commun/pages/billets/index.php', 'ticket-perforated'],
];

$titre_page = 'Centre d\'impression';
require_once __DIR__ . '/../../../../noyau/layout/header.php';
?>
<div class="page-titre">
  <h4><i class="bi bi-printer me-1 text-primary"></i>Centre d'impression</h4>
  <div class="sub">Les listes de l'école au même endroit, en PDF prêts à imprimer — année <?= h(ed_annee()['libelle']) ?>.</div>
</div>

<ul class="nav nav-tabs mb-3" style="border-bottom:2px solid #e5e7eb">
  <?php foreach (['eleves' => ['Élèves', 'people'], 'personnel' => ['Personnel', 'person-badge'], 'etablissement' => ['Établissement', 'building']] as $k => [$lib, $ic]): ?>
  <li class="nav-item"><a class="nav-link <?= $onglet === $k ? 'active' : '' ?>" href="?onglet=<?= $k ?>"><i class="bi bi-<?= $ic ?> me-1"></i><?= $lib ?></a></li>
  <?php endforeach; ?>
</ul>

<div class="row g-3">
  <?php foreach (IMP_DOCS[$onglet] as $doc => [$lib, $ic, $desc]): if ($doc === 'matieres' && !$sec) continue; ?>
  <div class="col-md-6 col-xl-4">
    <div class="card h-100">
      <div class="card-body d-flex flex-column">
        <div class="d-flex align-items-start gap-2 mb-2">
          <div style="width:38px;height:38px;border-radius:10px;background:#f3f6fb;display:flex;align-items:center;justify-content:center;flex-shrink:0">
            <i class="bi bi-<?= $ic ?>" style="color:#1a2744;font-size:1.1rem"></i>
          </div>
          <div>
            <div class="fw-semibold" style="font-size:.9rem"><?= h($lib) ?></div>
            <div class="text-muted" style="font-size:.76rem"><?= h($desc) ?></div>
          </div>
        </div>
        <div class="mt-auto d-flex gap-2">
          <?php if (in_array($doc, $avec_classe, true)): ?>
          <select class="form-select form-select-sm imp-classe" data-doc="<?= $doc ?>">
            <option value="0">Toutes les classes</option>
            <?php foreach ($classes as $c): ?><option value="<?= (int) $c['id'] ?>"><?= h($c['libelle']) ?> (<?= (int) $c['nb'] ?>)</option><?php endforeach; ?>
          </select>
          <?php endif; ?>
          <button type="button" class="btn btn-primary btn-sm text-nowrap" onclick="imprimerDoc('<?= $doc ?>', <?= h(json_encode($lib)) ?>)"><i class="bi bi-file-earmark-pdf me-1"></i>Aperçu</button>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php if ($onglet === 'etablissement' && $sec):
  require_once __DIR__ . '/../analyse/_analyse_lib.php';
  $trims = array_values(array_filter(an_periodes(), fn($p) => $p['cle'] !== 'annee')); ?>
<div class="card mt-3">
  <div class="card-body">
    <div class="d-flex align-items-start gap-2 mb-2">
      <div style="width:38px;height:38px;border-radius:10px;background:#f3f6fb;display:flex;align-items:center;justify-content:center"><i class="bi bi-archive" style="color:#1a2744;font-size:1.1rem"></i></div>
      <div><div class="fw-semibold" style="font-size:.9rem">Registre de conservation des notes</div>
        <div class="text-muted" style="font-size:.76rem">Moyenne de chaque élève dans chaque matière, moyenne générale et rang, pour une classe et un trimestre — à signer et à archiver.</div></div>
    </div>
    <div class="d-flex flex-wrap gap-2">
      <select id="reg-classe" class="form-select form-select-sm" style="max-width:220px"><?php foreach ($classes as $c): ?><option value="<?= (int) $c['id'] ?>"><?= h($c['libelle']) ?></option><?php endforeach; ?></select>
      <select id="reg-periode" class="form-select form-select-sm" style="max-width:180px"><?php foreach ($trims as $t): ?><option value="<?= $t['cle'] ?>"><?= h($t['libelle']) ?></option><?php endforeach; ?></select>
      <button type="button" class="btn btn-primary btn-sm" onclick="afficherApercu(<?= h(json_encode(APP_URL . '/secondaire/commun/pages/impression/registre.php')) ?> + '?classe=' + document.getElementById('reg-classe').value + '&periode=' + document.getElementById('reg-periode').value, 'Registre de conservation des notes', 'registre_notes', 'landscape')"><i class="bi bi-file-earmark-pdf me-1"></i>Aperçu</button>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="card mt-3">
  <div class="card-body">
    <div class="section-titre"><i class="bi bi-link-45deg me-1"></i>Autres documents de SIGES</div>
    <div class="d-flex flex-wrap gap-2">
      <?php foreach ($raccourcis as [$lib, $url, $ic]): ?>
        <a href="<?= APP_URL . '/' . $url ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-<?= $ic ?> me-1"></i><?= h($lib) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<script>
function imprimerDoc(doc, titre) {
  var sel = document.querySelector('.imp-classe[data-doc="' + doc + '"]');
  var url = <?= json_encode(APP_URL . '/secondaire/commun/pages/impression/pdf.php') ?> + '?doc=' + doc + (sel ? '&classe=' + sel.value : '');
  afficherApercu(url, titre, 'impression_' + doc, ['effectifs', 'annuaire', 'matieres'].indexOf(doc) >= 0 ? 'portrait' : 'portrait');
}
</script>

<?php require_once __DIR__ . '/../../../../noyau/layout/footer.php'; ?>
