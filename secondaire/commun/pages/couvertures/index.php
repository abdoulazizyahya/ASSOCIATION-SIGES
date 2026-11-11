<?php
// secondaire/commun/pages/couvertures/index.php — Pédagogie → Couvertures de
// bulletins (10/11/2026), primaire et secondaire : réglages de la couverture
// (modèle, format A4 / A5, titre bilingue, contenu) et impression par classe,
// par sélection d'élèves ou vierge. PDF : pdf.php.
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
require_once __DIR__ . '/_couvertures_lib.php';

exiger_role(couv_roles());
$ici = 'secondaire/commun/pages/couvertures/index.php';
$R = couv_reglages();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $nv = [
        'modele'   => array_key_exists(post('modele'), COUV_MODELES) ? post('modele') : 'siges',
        'format'   => post('format') === 'A5' ? 'A5' : 'A4',
        'titre_fr' => mb_substr(trim((string) post('titre_fr')), 0, 60) ?: $R['titre_fr'],
        'titre_en' => mb_substr(trim((string) post('titre_en')), 0, 60),
        'photo' => post('photo') === '1' ? 1 : 0, 'echelle' => post('echelle') === '1' ? 1 : 0,
        'note' => post('note') === '1' ? 1 : 0, 'reglement' => post('reglement') === '1' ? 1 : 0, 'cadre' => post('cadre') === '1' ? 1 : 0,
        'texte_note' => mb_substr(trim((string) post('texte_note')), 0, 600),
        'texte_reglement' => mb_substr(trim((string) post('texte_reglement')), 0, 800),
    ];
    if (ed_reglages_enregistrer('couvertures', $nv)) flash_set('succes', 'Réglages de la couverture enregistrés.');
    else flash_set('erreur', 'Réglages non enregistrés : école non identifiée dans l\'annuaire.');
    rediriger($ici . '?classe=' . (int) post('classe') . (post('apres') === 'apercu' ? '&apercu=1' : ''));
}

$classes = ed_classes();
$id_classe = (int) ($_GET['classe'] ?? ($classes[0]['id'] ?? 0));
$eleves = $id_classe ? ed_eleves($id_classe) : [];
$url_pdf = APP_URL . '/secondaire/commun/pages/couvertures/pdf.php';

$titre_page = 'Couvertures de bulletins';
require_once __DIR__ . '/../../../../noyau/layout/header.php';
?>
<style>
.couv-modele { border:1.5px solid #e5e7eb; border-radius:10px; padding:.55rem .75rem; cursor:pointer; height:100%; }
.couv-modele:has(input:checked) { border-color:#1a3c6b; background:#f3f6fb; box-shadow:0 0 0 2px rgba(26,60,107,.12); }
.couv-modele input { margin-right:.35rem; }
</style>
<div class="page-titre">
  <h4><i class="bi bi-journal-bookmark me-1 text-primary"></i>Couvertures de bulletins</h4>
  <div class="sub">Couverture du carnet de notes : une par élève (nom, classe, photo, matricule) ou vierge, en A4 ou A5.</div>
</div>

<?= flash_html() ?>

<div class="row g-3">
  <div class="col-lg-7">
    <form method="post" class="card">
      <div class="card-body">
        <?= csrf_champ() ?>
        <input type="hidden" name="classe" value="<?= $id_classe ?>">
        <div class="section-titre"><i class="bi bi-palette me-1"></i>Modèle</div>
        <div class="row g-2 mb-3">
          <?php foreach (COUV_MODELES as $k => [$lib, $desc]): ?>
          <div class="col-sm-6">
            <label class="couv-modele d-block">
              <input type="radio" name="modele" value="<?= $k ?>" <?= $R['modele'] === $k ? 'checked' : '' ?>><strong><?= h($lib) ?></strong>
              <div class="text-muted" style="font-size:.76rem;margin-left:1.3rem"><?= h($desc) ?></div>
            </label>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="row g-2 mb-3">
          <div class="col-md-4">
            <label class="form-label">Format</label>
            <select name="format" class="form-select form-select-sm">
              <option value="A4" <?= $R['format'] === 'A4' ? 'selected' : '' ?>>A4 — 210 × 297 mm</option>
              <option value="A5" <?= $R['format'] === 'A5' ? 'selected' : '' ?>>A5 — 148 × 210 mm (carnet)</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Titre — français</label>
            <input type="text" name="titre_fr" class="form-control form-control-sm" maxlength="60" value="<?= h($R['titre_fr']) ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">Titre — anglais <span class="text-muted">(vide = aucun)</span></label>
            <input type="text" name="titre_en" class="form-control form-control-sm" maxlength="60" value="<?= h($R['titre_en']) ?>">
          </div>
        </div>
        <div class="section-titre"><i class="bi bi-list-check me-1"></i>Contenu</div>
        <div class="row g-1 mb-2" style="font-size:.86rem">
          <?php foreach (['photo' => 'Photo de l\'élève', 'echelle' => 'Échelle d\'appréciation des bulletins', 'note' => 'Note aux parents', 'reglement' => 'Extrait du règlement intérieur', 'cadre' => 'Cadre « Observations » à remplir à la main'] as $k => $lib): ?>
          <div class="col-sm-6"><div class="form-check">
            <input class="form-check-input" type="checkbox" name="<?= $k ?>" value="1" id="c-<?= $k ?>" <?= !empty($R[$k]) ? 'checked' : '' ?>>
            <label class="form-check-label" for="c-<?= $k ?>"><?= h($lib) ?></label>
          </div></div>
          <?php endforeach; ?>
        </div>
        <div class="mb-2">
          <label class="form-label">Texte de la note aux parents</label>
          <textarea name="texte_note" rows="2" class="form-control form-control-sm" maxlength="600"><?= h($R['texte_note']) ?></textarea>
        </div>
        <div class="mb-3">
          <label class="form-label">Extrait du règlement intérieur</label>
          <textarea name="texte_reglement" rows="3" class="form-control form-control-sm" maxlength="800"><?= h($R['texte_reglement']) ?></textarea>
        </div>
        <div class="d-flex gap-2">
          <button class="btn btn-primary btn-sm" name="apres" value="apercu"><i class="bi bi-eye me-1"></i>Enregistrer et voir l'aperçu</button>
          <button class="btn btn-outline-primary btn-sm" name="apres" value=""><i class="bi bi-save me-1"></i>Enregistrer</button>
        </div>
      </div>
    </form>
  </div>

  <div class="col-lg-5">
    <div class="card">
      <div class="card-body">
        <div class="section-titre"><i class="bi bi-printer me-1"></i>Imprimer</div>
        <form method="get" class="mb-2">
          <label class="form-label">Classe</label>
          <select name="classe" class="form-select form-select-sm" onchange="this.form.submit()">
            <?php foreach ($classes as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $id_classe ? 'selected' : '' ?>><?= h($c['libelle']) ?> (<?= (int) $c['nb'] ?>)</option><?php endforeach; ?>
          </select>
        </form>
        <div class="d-flex flex-wrap gap-2 mb-2">
          <button type="button" class="btn btn-primary btn-sm" onclick="imprimer('classe')" <?= $eleves ? '' : 'disabled' ?>><i class="bi bi-people me-1"></i>Toute la classe (<?= count($eleves) ?>)</button>
          <button type="button" class="btn btn-outline-primary btn-sm" onclick="imprimer('selection')"><i class="bi bi-check2-square me-1"></i>Sélection <span id="nb-sel">(0)</span></button>
          <button type="button" class="btn btn-outline-secondary btn-sm" onclick="imprimer('vierge')"><i class="bi bi-file-earmark me-1"></i>Couverture vierge</button>
        </div>
        <div class="border rounded p-2" style="max-height:340px;overflow:auto;font-size:.82rem">
          <div class="form-check mb-1"><input class="form-check-input" type="checkbox" id="tout"><label class="form-check-label fw-semibold" for="tout">Tout cocher</label></div>
          <?php foreach ($eleves as $e): ?>
          <div class="form-check"><input class="form-check-input sel-el" type="checkbox" value="<?= (int) $e['id'] ?>" id="e<?= (int) $e['id'] ?>"><label class="form-check-label" for="e<?= (int) $e['id'] ?>"><?= h(ed_nom($e)) ?></label></div>
          <?php endforeach; ?>
          <?php if (!$eleves): ?><div class="text-muted">Aucun élève dans cette classe.</div><?php endif; ?>
        </div>
        <div class="text-muted mt-2" style="font-size:.76rem">L'aperçu s'ouvre avant l'impression ; les informations de l'école et de l'élève se remplissent automatiquement.</div>
      </div>
    </div>
  </div>
</div>

<script>
var URL_PDF = <?= json_encode($url_pdf) ?>, CLASSE = <?= (int) $id_classe ?>;
function coches() { return Array.prototype.map.call(document.querySelectorAll('.sel-el:checked'), function (c) { return c.value; }); }
document.addEventListener('change', function (e) {
  if (e.target.id === 'tout') document.querySelectorAll('.sel-el').forEach(function (c) { c.checked = e.target.checked; });
  document.getElementById('nb-sel').textContent = '(' + coches().length + ')';
});
function imprimer(mode) {
  var url = URL_PDF + '?classe=' + CLASSE;
  if (mode === 'vierge') url = URL_PDF + '?vierge=1';
  if (mode === 'selection') { var ids = coches(); if (!ids.length) { alert('Cochez au moins un élève.'); return; } url += '&ids=' + ids.join(','); }
  afficherApercu(url, 'Couvertures de bulletins', 'couvertures', 'portrait');
}
<?php if (!empty($_GET['apercu'])): ?>
document.addEventListener('DOMContentLoaded', function () { afficherApercu(URL_PDF + '?classe=' + CLASSE + '&apercu=1', 'Aperçu de la couverture', 'couvertures', 'portrait'); });
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../../../../noyau/layout/footer.php'; ?>
