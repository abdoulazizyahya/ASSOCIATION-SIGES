<?php
// Fragment : onglet « Fiches incomplètes » de la liste des élèves — primaire
// (pages/eleves/_liste_resultats.php) ET secondaire
// (secondaire/pages/eleves/liste.php). Élèves actifs de l'année à qui il
// manque des informations (matricule, NIU, photo, date/lieu de naissance,
// nom arabe au primaire), pour toutes les classes, un niveau ou une classe.
// Filtres = simple formulaire GET (rechargement de la page liste, pas de
// <script> ici : ce fragment peut être injecté en AJAX au primaire).
// Variable attendue : $peut_gerer.
require_once __DIR__ . '/_fiches_lib.php';
if (empty($peut_gerer)) { echo '<div class="text-muted small py-3">Accès réservé.</div>'; return; }

$fp       = fiches_parametres();
$champs_l = fiches_champs();
$niveaux  = fiches_niveaux();
$classes_f = fiches_classes($fp['niveau']);
// Classe choisie hors du niveau choisi : on l'ignore.
if ($fp['classe'] && !in_array($fp['classe'], array_map(fn($c) => (int) $c['id'], $classes_f), true)) $fp['classe'] = 0;

$tous      = fiches_eleves($fp['niveau'], $fp['classe']);
$resultats = fiches_filtrer($tous, $fp['champs'], $fp['mode']);
$par_champ = array_fill_keys(array_keys($champs_l), 0);
foreach ($tous as $e) foreach ($e['manque'] as $k) $par_champ[$k]++;

$base  = photos_base_url();
$qs    = fn(array $extra = []) => http_build_query(array_merge(
    ['statut' => 'incompletes', 'f' => 1, 'niveau' => $fp['niveau'], 'classe' => $fp['classe'] ?: null,
     'champs' => $fp['champs'], 'mode' => $fp['mode']], $extra));
$portee = $fp['classe'] ? 'la classe ' . (array_column($classes_f, 'nom', 'id')[$fp['classe']] ?? '')
        : ($fp['niveau'] !== '' ? 'le niveau ' . (array_column($niveaux, 'nom', 'id')[$fp['niveau']] ?? $fp['niveau']) : 'toutes les classes');
?>
<form method="get" action="<?= h($base) ?>/liste.php" class="card mb-2">
  <input type="hidden" name="statut" value="incompletes">
  <input type="hidden" name="f" value="1">
  <div class="card-body py-2">
    <div class="row g-2 align-items-end">
      <div class="col-6 col-md-3">
        <label class="form-label mb-0">Niveau</label>
        <select name="niveau" class="form-select form-select-sm" onchange="this.form.classe.value='';this.form.submit()">
          <option value="">Tous les niveaux</option>
          <?php foreach ($niveaux as $n): ?>
            <option value="<?= h($n['id']) ?>" <?= $fp['niveau'] === (string) $n['id'] ? 'selected' : '' ?>><?= h($n['nom']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label mb-0">Classe</label>
        <select name="classe" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="">Toutes les classes<?= $fp['niveau'] !== '' ? ' du niveau' : '' ?></option>
          <?php foreach ($classes_f as $c): ?>
            <option value="<?= (int) $c['id'] ?>" <?= $fp['classe'] === (int) $c['id'] ? 'selected' : '' ?>><?= h($c['nom']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-6">
        <label class="form-label mb-0">Élèves à qui il manque</label>
        <select name="mode" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="un" <?= $fp['mode'] === 'un' ? 'selected' : '' ?>>au moins une des informations cochées</option>
          <option value="tous" <?= $fp['mode'] === 'tous' ? 'selected' : '' ?>>toutes les informations cochées</option>
        </select>
      </div>
    </div>
    <div class="d-flex flex-wrap align-items-center gap-3 mt-2">
      <?php foreach ($champs_l as $k => $lbl): ?>
        <div class="form-check mb-0">
          <input class="form-check-input" type="checkbox" name="champs[]" value="<?= $k ?>" id="fc_<?= $k ?>"
                 <?= in_array($k, $fp['champs'], true) ? 'checked' : '' ?> onchange="this.form.submit()">
          <label class="form-check-label" for="fc_<?= $k ?>" style="font-size:.82rem"><?= h($lbl) ?></label>
        </div>
      <?php endforeach; ?>
      <div class="ms-auto d-flex gap-1">
        <a href="<?= h($base) ?>/fiches_incompletes_export.php?<?= h($qs()) ?>" class="btn btn-outline-success btn-sm">
          <i class="bi bi-file-earmark-excel me-1"></i>Excel
        </a>
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()">
          <i class="bi bi-printer me-1"></i>Imprimer
        </button>
      </div>
    </div>
  </div>
</form>

<!-- Récapitulatif : nombre d'élèves à qui il manque chaque information
     (clic = n'afficher que ce manque). -->
<div class="d-flex flex-wrap gap-2 mb-2">
  <?php foreach ($champs_l as $k => $lbl): $n = $par_champ[$k]; ?>
    <a href="<?= h($base) ?>/liste.php?<?= h($qs(['champs' => [$k], 'mode' => 'un'])) ?>"
       class="text-decoration-none border rounded px-2 py-1 bg-white" style="font-size:.78rem" title="Afficher seulement : <?= h($lbl) ?> manquant(e)">
      <span class="fw-bold <?= $n ? 'text-danger' : 'text-success' ?>"><?= $n ?></span>
      <span class="text-muted">sans <?= h(mb_strtolower($lbl)) ?></span>
    </a>
  <?php endforeach; ?>
</div>

<div class="text-muted mb-1" style="font-size:.78rem">
  <?= count($resultats) ?> élève(s) sur <?= count($tous) ?> — <?= h($portee) ?>
  <?= !$fp['champs'] ? ' — <span class="text-danger">cochez au moins une information</span>' : '' ?>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-abz table-hover align-middle mb-0">
      <thead>
        <tr>
          <th style="width:34px">#</th>
          <th>Élève</th>
          <th>Matricule</th>
          <th>Classe</th>
          <th>Informations manquantes</th>
          <th class="text-end">Compléter</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$resultats): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">
            <i class="bi bi-check2-circle text-success" style="font-size:2rem;display:block;margin-bottom:.4rem"></i>
            <?= $fp['champs'] ? 'Aucune fiche incomplète pour ces critères.' : 'Aucun critère coché.' ?>
          </td></tr>
        <?php else: $no = 1; foreach ($resultats as $e): ?>
          <tr>
            <td class="text-muted" style="font-size:.72rem"><?= $no++ ?></td>
            <td class="fw-semibold" style="font-size:.82rem"><?= h($e['nom']) ?></td>
            <td><?= $e['mat'] !== '' ? '<span class="badge-code">' . h($e['mat']) . '</span>' : '<span class="text-muted">—</span>' ?></td>
            <td style="font-size:.78rem"><?= h($e['classe']) ?></td>
            <td>
              <?php foreach ($e['manque'] as $k): if (!isset($champs_l[$k])) continue; ?>
                <span class="badge <?= in_array($k, $fp['champs'], true) ? 'bg-danger' : 'bg-light text-muted border' ?> me-1" style="font-size:.66rem"><?= h($champs_l[$k]) ?></span>
              <?php endforeach; ?>
            </td>
            <td class="text-end">
              <a href="<?= h($base) ?>/form.php?id=<?= $e['id'] ?>" class="btn btn-sm btn-light" style="padding:3px 7px" title="Modifier la fiche">
                <i class="bi bi-pencil" style="font-size:.78rem"></i>
              </a>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
