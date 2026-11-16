<?php
// parents/accueil.php — enfants du parent connecté (16/11/2026) ; ?id= : fiche
// détaillée (paiements, absences, résultats publiés). Lecture seule.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../noyau/connexion.php';
require_once __DIR__ . '/../noyau/fonctions.php';
require_once __DIR__ . '/../noyau/partage/espace_parents.php';
require_once __DIR__ . '/../secondaire/commun/pages/communication/_sms_lib.php';   // sms_finances_lot()
require_once __DIR__ . '/_layout.php';
$p = ep_exiger();

// Seuls les enfants rattachés à CETTE session, et encore inscrits cette année.
$enfants = [];
foreach ($p['eleves'] as $id) if ($e = ed_eleve((int) $id)) $enfants[(int) $id] = $e;
$fin = $enfants ? sms_finances_lot(array_keys($enfants)) : [];
$id = (int) ($_GET['id'] ?? (count($enfants) === 1 ? array_key_first($enfants) : 0));
$e = $enfants[$id] ?? null;

ep_haut($e ? ed_nom($e) : 'Mes enfants', true);

if (!$enfants): ?>
  <div class="ep-carte text-center"><p class="mb-0">Aucun élève inscrit cette année n'est rattaché à votre accès.</p></div>
<?php ep_bas(); exit; endif;

if (!$e): ?>
  <h1 class="h5 mb-3" style="font-family:Georgia,serif">Mes enfants</h1>
  <?php foreach ($enfants as $k => $x): $f = $fin[$k] ?? ['reste' => 0.0]; ?>
    <a href="?id=<?= $k ?>" class="ep-carte d-flex align-items-center gap-3 text-decoration-none text-reset">
      <i class="bi bi-person-circle fs-2 ep-or"></i>
      <div class="flex-grow-1">
        <div class="fw-semibold"><?= h(ed_nom($x)) ?></div>
        <div class="small text-muted"><?= h($x['classe']) ?> · <?= h(ed_ident($x)) ?></div>
      </div>
      <div class="text-end small">
        <?php if ($f['reste'] > 0.5): ?><div class="text-danger fw-semibold"><?= ep_fcfa($f['reste']) ?></div><div class="text-muted">reste à payer</div>
        <?php else: ?><span class="text-success"><i class="bi bi-check-circle me-1"></i>à jour</span><?php endif; ?>
      </div>
      <i class="bi bi-chevron-right text-muted"></i>
    </a>
  <?php endforeach;
  ep_bas(); exit;
endif;

$f = $fin[$id] ?? ['du' => 0.0, 'paye' => 0.0, 'reste' => 0.0];
$versements = ep_versements($id);
$absences = ep_absences($e);
$resultats = ep_resultats($e);
?>
<?php if (count($enfants) > 1): ?><p class="small mb-2"><a href="accueil.php" class="text-decoration-none"><i class="bi bi-arrow-left me-1"></i>Mes enfants</a></p><?php endif; ?>
<div class="ep-carte">
  <div class="fw-semibold fs-5"><?= h(ed_nom($e)) ?></div>
  <div class="small text-muted"><?= h($e['classe']) ?> · <?= h(ed_ident($e)) ?> · année <?= h(ed_annee()['libelle']) ?></div>
</div>

<div class="ep-carte">
  <h2><i class="bi bi-cash-coin me-1"></i>Scolarité</h2>
  <div class="row text-center g-2 mb-2">
    <div class="col-4"><div class="small text-muted">À payer</div><div class="ep-chiffre"><?= ep_fcfa($f['du']) ?></div></div>
    <div class="col-4"><div class="small text-muted">Payé</div><div class="ep-chiffre text-success"><?= ep_fcfa($f['paye']) ?></div></div>
    <div class="col-4"><div class="small text-muted">Reste</div><div class="ep-chiffre <?= $f['reste'] > 0.5 ? 'text-danger' : 'text-success' ?>"><?= ep_fcfa($f['reste']) ?></div></div>
  </div>
  <?php if ($versements): ?>
  <table class="table table-sm table-ep mb-0">
    <thead><tr><th>Date</th><th class="text-end">Montant</th><th>Mode</th><th>Réf.</th></tr></thead>
    <tbody><?php foreach ($versements as $v): ?>
      <tr><td><?= h($v['date']) ?></td><td class="text-end"><?= ep_fcfa($v['montant']) ?></td><td class="small"><?= h($v['mode']) ?></td><td class="small text-muted"><?= h($v['ref']) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table>
  <?php else: ?><p class="small text-muted mb-0">Aucun versement enregistré cette année.</p><?php endif; ?>
</div>

<div class="ep-carte">
  <h2><i class="bi bi-calendar-x me-1"></i>Absences</h2>
  <?php if ($absences): ?>
  <table class="table table-sm table-ep mb-0">
    <thead><tr><th>Période</th><th class="text-end">Non justifiées</th><?php if (ed_secondaire()): ?><th class="text-end">Justifiées</th><?php endif; ?></tr></thead>
    <tbody><?php foreach ($absences as $a): ?>
      <tr><td><?= h($a['libelle']) ?></td>
          <td class="text-end <?= $a['non_jus'] > 0 ? 'text-danger fw-semibold' : '' ?>"><?= h(rtrim(rtrim(number_format((float) $a['non_jus'], 1, ',', ''), '0'), ',')) ?> <?= $a['unite'] === 'h' ? 'h' : 'j' ?></td>
          <?php if (ed_secondaire()): ?><td class="text-end"><?= h(rtrim(rtrim(number_format((float) $a['jus'], 1, ',', ''), '0'), ',')) ?> h</td><?php endif; ?></tr>
    <?php endforeach; ?></tbody>
  </table>
  <?php else: ?><p class="small text-muted mb-0">Aucune période définie pour l'instant.</p><?php endif; ?>
</div>

<div class="ep-carte">
  <h2><i class="bi bi-mortarboard me-1"></i>Résultats</h2>
  <?php if ($resultats): ?>
  <table class="table table-sm table-ep mb-0">
    <thead><tr><th>Période</th><th class="text-end">Moyenne</th><th class="text-end">Rang</th><th class="text-end">Moy. classe</th></tr></thead>
    <tbody><?php foreach ($resultats as $r): ?>
      <tr><td><?= h($r['libelle']) ?></td>
          <td class="text-end fw-semibold <?= $r['moyenne'] !== null && $r['moyenne'] < 10 ? 'text-danger' : '' ?>"><?= ep_note($r['moyenne']) ?><?= $r['moyenne'] !== null ? '/20' : '' ?></td>
          <td class="text-end"><?= $r['rang'] ? (int) $r['rang'] . '/' . (int) $r['effectif'] : '—' ?></td>
          <td class="text-end text-muted"><?= ep_note($r['moy_classe']) ?></td></tr>
      <?php if ($r['mention'] !== ''): ?>
      <tr><td colspan="4" class="border-0 pt-0"><span class="badge rounded-pill" style="background:#fbf3df;color:#8a6414;border:1px solid #e8cf8f;font-weight:600">
        <i class="bi bi-trophy-fill me-1"></i><?= h($r['mention']) ?></span></td></tr>
      <?php endif; ?>
      <?php if ($r['detail']): ?>
      <tr><td colspan="4" class="border-0 pt-0">
        <details class="small"><summary class="text-primary" style="cursor:pointer">Notes par compétence (<?= count($r['detail']) ?>)</summary>
          <table class="table table-sm table-ep mb-0 mt-1"><tbody>
            <?php foreach ($r['detail'] as $d): ?>
              <tr><td><?= h($d['libelle']) ?></td><td class="text-end fw-semibold <?= $d['note'] < 10 ? 'text-danger' : '' ?>"><?= ep_note($d['note']) ?>/20</td></tr>
            <?php endforeach; ?>
          </tbody></table>
        </details>
      </td></tr>
      <?php endif; ?>
    <?php endforeach; ?></tbody>
  </table>
  <?php else: ?><p class="small text-muted mb-0">L'école n'a pas encore publié de résultats.</p><?php endif; ?>
</div>
<?php ep_bas();
