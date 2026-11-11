<?php
// secondaire/commun/pages/analyse/index.php — Pédagogie → Analyse des
// performances (10/11/2026), primaire et secondaire : indicateurs,
// répartition des moyennes, résultats par matière, évolution sur l'année,
// comparaison des classes du niveau, 10 premiers / 10 à accompagner.
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
require_once __DIR__ . '/_analyse_lib.php';
exiger_role(an_roles());

$classes = ed_classes();
$periodes = an_periodes();
$id_classe = (int) ($_GET['classe'] ?? ($classes[0]['id'] ?? 0));
$cle_p = (string) ($_GET['periode'] ?? ($periodes[0]['cle'] ?? 'annee'));
$periode = $periodes[0] ?? ['cle' => 'annee', 'id' => 0, 'libelle' => 'Année entière'];
foreach ($periodes as $p) if ($p['cle'] === $cle_p) $periode = $p;
$vue = in_array($_GET['vue'] ?? '', ['classe', 'matieres', 'evolution', 'comparer', 'eleves'], true) ? $_GET['vue'] : 'classe';
$classe_lib = '';
foreach ($classes as $c) if ((int) $c['id'] === $id_classe) $classe_lib = $c['libelle'];

$moys = $id_classe ? an_moyennes($id_classe, $periode) : [];
$ind = an_indicateurs(array_values($moys));
$noms = [];
foreach ($id_classe ? ed_eleves($id_classe) : [] as $e) $noms[(int) $e['id']] = ed_nom($e);

$matieres = $vue === 'matieres' || $vue === 'classe' ? an_matieres($id_classe, $periode) : [];
$evolution = [];
if ($vue === 'evolution') foreach ($periodes as $p) { if ($p['cle'] === 'annee') continue; $i = an_indicateurs(array_values(an_moyennes($id_classe, $p))); if ($i['n']) $evolution[] = ['libelle' => $p['libelle'], 'moy' => $i['moy'], 'taux' => $i['taux']]; }
$comparaison = [];
if ($vue === 'comparer') foreach (an_classes_niveau($id_classe) as $c) { $i = an_indicateurs(array_values(an_moyennes((int) $c['id'], $periode))); $comparaison[] = ['classe' => $c['libelle'], 'id' => (int) $c['id']] + $i; }
arsort($moys);

$qs = fn(array $x) => '?' . http_build_query(array_merge(['classe' => $id_classe, 'periode' => $periode['cle'], 'vue' => $vue], $x));
$titre_page = 'Analyse des performances';
require_once __DIR__ . '/../../../../noyau/layout/header.php';
?>
<style>
.an-kpi { border-radius:14px; padding:.9rem 1rem; background:#fff; border:1px solid #e5e7eb; height:100%; }
.an-kpi .v { font-size:1.6rem; font-weight:700; color:#1a2744; line-height:1.1; }
.an-kpi .l { font-size:.78rem; color:#6b7280; }
</style>
<div class="page-titre d-flex flex-wrap justify-content-between align-items-center gap-2">
  <div>
    <h4><i class="bi bi-graph-up-arrow me-1 text-primary"></i>Analyse des performances</h4>
    <div class="sub">Moyennes calculées comme sur les bulletins — <?= h($classe_lib) ?>, <?= h($periode['libelle']) ?>.</div>
  </div>
  <button type="button" class="btn btn-outline-danger btn-sm" onclick="afficherApercu(<?= h(json_encode(APP_URL . '/secondaire/commun/pages/analyse/pdf.php' . $qs([]))) ?>, 'Analyse des performances', 'analyse', 'portrait')"><i class="bi bi-file-earmark-pdf me-1"></i>Exporter l'analyse (PDF)</button>
</div>

<form method="get" class="card mb-3"><div class="card-body py-2 d-flex flex-wrap gap-2 align-items-end">
  <input type="hidden" name="vue" value="<?= $vue ?>">
  <div><label class="form-label mb-0">Classe</label>
    <select name="classe" class="form-select form-select-sm" onchange="this.form.submit()"><?php foreach ($classes as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $id_classe ? 'selected' : '' ?>><?= h($c['libelle']) ?></option><?php endforeach; ?></select></div>
  <div><label class="form-label mb-0">Période</label>
    <select name="periode" class="form-select form-select-sm" onchange="this.form.submit()"><?php foreach ($periodes as $p): ?><option value="<?= $p['cle'] ?>" <?= $p['cle'] === $periode['cle'] ? 'selected' : '' ?>><?= h($p['libelle']) ?></option><?php endforeach; ?></select></div>
</div></form>

<ul class="nav nav-tabs mb-3" style="border-bottom:2px solid #e5e7eb">
  <?php foreach (['classe' => ['Vue de la classe', 'easel'], 'matieres' => ['Par matière', 'journal-bookmark'], 'evolution' => ['Évolution', 'graph-up'], 'comparer' => ['Comparer les classes', 'bar-chart'], 'eleves' => ['Par élève', 'person-lines-fill']] as $k => [$l, $ic]): ?>
  <li class="nav-item"><a class="nav-link <?= $vue === $k ? 'active' : '' ?>" href="<?= h($qs(['vue' => $k])) ?>"><i class="bi bi-<?= $ic ?> me-1"></i><?= $l ?></a></li>
  <?php endforeach; ?>
</ul>

<?php if (!$ind['n'] && $vue !== 'comparer' && $vue !== 'evolution'): ?>
  <div class="alert alert-info"><i class="bi bi-info-circle me-1"></i>Aucune moyenne pour cette classe et cette période (notes pas encore saisies).</div>
<?php else: ?>

<?php if (in_array($vue, ['classe', 'matieres', 'eleves'], true)): ?>
<div class="row g-3 mb-3">
  <?php foreach ([['Moyenne de la classe', an_fmt($ind['moy']), 'bullseye'], ['Taux de réussite', an_fmt($ind['taux'], 0) . ' %', 'check2-circle'], ['Meilleure moyenne', an_fmt($ind['max']), 'trophy'], ['Plus faible moyenne', an_fmt($ind['min']), 'arrow-down-circle'], ['Écart-type', an_fmt($ind['ecart']), 'distribute-vertical']] as [$l, $v, $ic]): ?>
  <div class="col-6 col-md"><div class="an-kpi"><div class="d-flex justify-content-between"><div class="v"><?= $v ?></div><i class="bi bi-<?= $ic ?>" style="color:#c8960a;font-size:1.2rem"></i></div><div class="l"><?= $l ?></div></div></div>
  <?php endforeach; ?>
</div>
<div class="text-muted mb-3" style="font-size:.78rem"><?= (int) $ind['n'] ?> élève(s) classé(s), <?= (int) $ind['admis'] ?> admis (moyenne ≥ 10). L'écart-type mesure la dispersion : plus il est grand, plus la classe est hétérogène.</div>
<?php endif; ?>

<?php if ($vue === 'classe'): ?>
<div class="row g-3">
  <div class="col-lg-6"><div class="card h-100"><div class="card-body">
    <div class="section-titre">Répartition des moyennes</div><canvas id="ch-tranches" height="220"></canvas>
  </div></div></div>
  <div class="col-lg-6"><div class="card h-100"><div class="card-body">
    <div class="section-titre">Moyenne par matière <span class="text-muted fw-normal" style="font-size:.75rem">(trait rouge : 10/20)</span></div>
    <?php if ($matieres): ?><canvas id="ch-matieres" height="220"></canvas><?php else: ?><div class="text-muted" style="font-size:.84rem">Disponible pour un trimestre au secondaire général.</div><?php endif; ?>
  </div></div></div>
</div>
<?php elseif ($vue === 'matieres'): ?>
<div class="card"><div class="card-body">
  <div class="section-titre">Résultats détaillés par matière <span class="text-muted fw-normal" style="font-size:.75rem">— triés par taux de réussite croissant : les matières en difficulté d'abord</span></div>
  <?php if (!$matieres): ?><div class="text-muted">Disponible pour un trimestre, au secondaire (pas pour l'année entière ni au primaire).</div><?php else: ?>
  <div class="table-responsive"><table class="table table-abz table-sm align-middle mb-0" style="font-size:.84rem">
    <thead><tr><th>Matière</th><th>Enseignant(s)</th><th class="text-center">Notés</th><th class="text-center">Moyenne</th><th class="text-center">Min</th><th class="text-center">Max</th><th class="text-center">Admis</th><th class="text-center">Taux</th><th class="text-center">Écart-type</th></tr></thead>
    <tbody><?php foreach ($matieres as $m): $t = (float) $m['taux']; ?>
      <tr><td class="fw-semibold"><?= h($m['matiere']) ?></td><td><?= h($m['enseignant']) ?></td><td class="text-center"><?= (int) $m['notes'] ?></td>
        <td class="text-center fw-semibold" style="color:<?= $m['moyenne'] < 10 ? '#c0392b' : '#1e7c50' ?>"><?= an_fmt($m['moyenne']) ?></td><td class="text-center"><?= an_fmt($m['min']) ?></td><td class="text-center"><?= an_fmt($m['max']) ?></td>
        <td class="text-center"><?= (int) $m['admis'] ?>/<?= (int) $m['notes'] ?></td>
        <td class="text-center"><span class="badge" style="background:<?= $t >= 75 ? '#d1fae5' : ($t >= 50 ? '#fef3c7' : '#fee2e2') ?>;color:#111"><?= an_fmt($t, 0) ?> %</span></td><td class="text-center"><?= an_fmt($m['ecart']) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div><?php endif; ?>
</div></div>
<?php elseif ($vue === 'evolution'): ?>
<div class="card"><div class="card-body">
  <div class="section-titre">Évolution de la classe sur l'année</div>
  <?php if (!$evolution): ?><div class="text-muted">Aucune moyenne trimestrielle disponible.</div><?php else: ?>
  <canvas id="ch-evolution" height="110"></canvas>
  <table class="table table-sm mt-3 mb-0" style="font-size:.84rem;max-width:520px"><thead><tr><th>Période</th><th class="text-center">Moyenne</th><th class="text-center">Taux de réussite</th></tr></thead>
    <tbody><?php foreach ($evolution as $e): ?><tr><td><?= h($e['libelle']) ?></td><td class="text-center"><?= an_fmt($e['moy']) ?></td><td class="text-center"><?= an_fmt($e['taux'], 0) ?> %</td></tr><?php endforeach; ?></tbody></table>
  <?php endif; ?>
</div></div>
<?php elseif ($vue === 'comparer'): ?>
<div class="card"><div class="card-body">
  <div class="section-titre">Comparaison des classes du même niveau — <?= h($periode['libelle']) ?></div>
  <canvas id="ch-comparer" height="110"></canvas>
  <table class="table table-abz table-sm mt-3 mb-0" style="font-size:.84rem"><thead><tr><th>Classe</th><th class="text-center">Élèves classés</th><th class="text-center">Moyenne</th><th class="text-center">Taux de réussite</th><th class="text-center">Meilleure</th><th class="text-center">Écart-type</th></tr></thead>
    <tbody><?php foreach ($comparaison as $c): ?><tr class="<?= $c['id'] === $id_classe ? 'table-warning' : '' ?>"><td class="fw-semibold"><?= h($c['classe']) ?></td><td class="text-center"><?= (int) $c['n'] ?></td><td class="text-center"><?= an_fmt($c['moy']) ?></td><td class="text-center"><?= $c['n'] ? an_fmt($c['taux'], 0) . ' %' : '—' ?></td><td class="text-center"><?= an_fmt($c['max']) ?></td><td class="text-center"><?= an_fmt($c['ecart']) ?></td></tr><?php endforeach; ?></tbody></table>
</div></div>
<?php elseif ($vue === 'eleves'): ?>
<?php $liste = []; $r = 0; foreach ($moys as $eid => $m) $liste[] = ['rang' => ++$r, 'nom' => $noms[$eid] ?? '?', 'moy' => $m]; ?>
<div class="row g-3">
  <?php foreach ([['Les 10 premiers', 'trophy', array_slice($liste, 0, 10), '#1e7c50'], ['Les 10 à accompagner en priorité', 'life-preserver', array_slice(array_reverse($liste), 0, 10), '#c0392b']] as [$tit, $ic, $l, $coul]): ?>
  <div class="col-lg-6"><div class="card h-100"><div class="card-body">
    <div class="section-titre"><i class="bi bi-<?= $ic ?> me-1" style="color:<?= $coul ?>"></i><?= $tit ?></div>
    <table class="table table-sm mb-0" style="font-size:.85rem"><?php foreach ($l as $x): ?><tr><td class="text-muted" style="width:48px"><?= $x['rang'] ?>e</td><td><?= h($x['nom']) ?></td><td class="text-end fw-semibold" style="color:<?= $x['moy'] < 10 ? '#c0392b' : '#1e7c50' ?>"><?= an_fmt($x['moy']) ?></td></tr><?php endforeach; ?></table>
  </div></div></div>
  <?php endforeach; ?>
</div>
<div class="mt-2"><a href="<?= APP_URL ?>/secondaire/commun/pages/communication/envoyer.php?type=moyenne&classe=<?= $id_classe ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-send me-1"></i>Informer les parents de la moyenne (SMS / WhatsApp)</a></div>
<?php endif; ?>
<?php endif; ?>

<script src="<?= APP_URL ?>/assets/vendor/chart/chart.umd.min.js"></script>
<script>
(function () {
  if (typeof Chart === 'undefined') return;
  var MAR = '#1a2744', OR = '#c8960a', VERT = '#1e7c50', ROUGE = '#c0392b';
  Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
  function el(id) { return document.getElementById(id); }
  <?php if ($vue === 'classe'): $tr = an_tranches(array_values($moys)); ?>
  if (el('ch-tranches')) new Chart(el('ch-tranches'), { type: 'bar',
    data: { labels: <?= json_encode(array_keys($tr)) ?>, datasets: [{ label: 'Élèves', data: <?= json_encode(array_values($tr)) ?>,
      backgroundColor: <?= json_encode(array_map(fn($k) => in_array($k, ['0–5', '5–8', '8–10'], true) ? '#c0392b' : '#1a2744', array_keys($tr))) ?>, borderRadius: 6 }] },
    options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } } });
  <?php if ($matieres): ?>
  if (el('ch-matieres')) new Chart(el('ch-matieres'), { type: 'bar',
    data: { labels: <?= json_encode(array_column($matieres, 'matiere')) ?>, datasets: [
      { type: 'bar', label: 'Moyenne', data: <?= json_encode(array_map(fn($m) => round($m['moyenne'], 2), $matieres)) ?>, backgroundColor: <?= json_encode(array_map(fn($m) => $m['moyenne'] < 10 ? '#c0392b' : '#1a2744', $matieres)) ?>, borderRadius: 5 },
      { type: 'line', label: '10/20', data: <?= json_encode(array_fill(0, count($matieres), 10)) ?>, borderColor: ROUGE, borderWidth: 1.5, pointRadius: 0, borderDash: [5, 4] }] },
    options: { indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { min: 0, max: 20 } } } });
  <?php endif; ?>
  <?php elseif ($vue === 'evolution' && $evolution): ?>
  new Chart(el('ch-evolution'), { type: 'line',
    data: { labels: <?= json_encode(array_column($evolution, 'libelle')) ?>, datasets: [
      { label: 'Moyenne de la classe (/20)', data: <?= json_encode(array_map(fn($e) => round($e['moy'], 2), $evolution)) ?>, borderColor: MAR, backgroundColor: MAR, tension: .3, yAxisID: 'y' },
      { label: 'Taux de réussite (%)', data: <?= json_encode(array_map(fn($e) => round($e['taux'], 1), $evolution)) ?>, borderColor: OR, backgroundColor: OR, tension: .3, yAxisID: 'y2' }] },
    options: { scales: { y: { min: 0, max: 20, title: { display: true, text: '/20' } }, y2: { min: 0, max: 100, position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: '%' } } } } });
  <?php elseif ($vue === 'comparer' && $comparaison): ?>
  new Chart(el('ch-comparer'), { type: 'bar',
    data: { labels: <?= json_encode(array_column($comparaison, 'classe')) ?>, datasets: [{ label: 'Moyenne', data: <?= json_encode(array_map(fn($c) => $c['moy'] !== null ? round($c['moy'], 2) : null, $comparaison)) ?>,
      backgroundColor: <?= json_encode(array_map(fn($c) => $c['id'] === $id_classe ? '#c8960a' : '#1a2744', $comparaison)) ?>, borderRadius: 6 }] },
    options: { plugins: { legend: { display: false } }, scales: { y: { min: 0, max: 20 } } } });
  <?php endif; ?>
})();
</script>
<?php require_once __DIR__ . '/../../../../noyau/layout/footer.php'; ?>
