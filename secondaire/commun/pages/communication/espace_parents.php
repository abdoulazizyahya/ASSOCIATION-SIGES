<?php
// secondaire/commun/pages/communication/espace_parents.php — réglages de
// l'espace parents de l'école (16/11/2026), primaire et secondaire :
// ouverture, modes de connexion, trimestres publiés, codes parents imprimés.
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
require_once __DIR__ . '/_sms_lib.php';
require_once __DIR__ . '/../../../../noyau/partage/espace_parents.php';
require_once __DIR__ . '/../analyse/_analyse_lib.php';

exiger_role(ed_secondaire() ? ['ADMIN', 'PROVISEUR', 'FONDATEUR', 'CENSEUR', 'SG', 'SECRETAIRE', 'INTENDANT'] : ['DIRECTEUR', 'SECRETAIRE', 'COMPTABLE', 'FONDATEUR']);
$ici = 'secondaire/commun/pages/communication/espace_parents.php';
$reg = ep_reglages();
$periodes = array_values(array_filter(an_periodes(), fn($p) => $p['cle'] !== 'annee'));
$id_classe = (int) ($_GET['classe'] ?? $_POST['classe'] ?? 0);
$fiches = null;   // codes à imprimer (affichés une seule fois)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    if (post('op') === 'reglages') {
        $ok = ed_reglages_enregistrer('espace_parents', [
            'actif' => post('actif') ? 1 : 0, 'sms' => post('sms') ? 1 : 0, 'code' => post('code') ? 1 : 0,
            'trims' => array_values(array_intersect(array_map('intval', (array) ($_POST['trims'] ?? [])), array_column($periodes, 'id'))),
            'annee' => post('annee') ? 1 : 0,
        ]);
        journaliser_action('espace_parents_reglages', null, post('actif') ? 'ouvert' : 'fermé');
        $ok ? flash_set('succes', 'Réglages de l\'espace parents enregistrés.') : flash_set('erreur', 'École non identifiée dans l\'annuaire : réglages non enregistrés.');
        rediriger($ici . ($id_classe ? '?classe=' . $id_classe : ''));
    }
    if (in_array(post('op'), ['codes_tous', 'codes_manquants', 'code_un'], true) && $id_classe) {
        $ids = null;
        if (post('op') === 'code_un') $ids = [(int) post('eleve')];
        if (post('op') === 'codes_manquants') {
            $deja = ep_codes_existants($id_classe);
            $ids = array_values(array_filter(array_map('intval', array_column(ed_eleves($id_classe), 'id')), fn($i) => !isset($deja[$i])));
        }
        $fiches = ep_codes_generer($id_classe, $ids);
        journaliser_action('espace_parents_codes', null, count($fiches) . ' code(s), classe ' . $id_classe);
    }
}

$etab = etab_pour_pdf(get_etablissement());
$nom_ecole = trim((string) ($etab['nom_fr'] ?? APP_NOM));
$lien = APP_URL . '/parents/index.php?ec=' . urlencode((string) (ecole_courante()['code'] ?? ''));
if (!preg_match('~^https?://~', $lien)) $lien = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $lien;

// ── Fiches de codes : page imprimable, codes visibles cette seule fois ──
if ($fiches !== null) { ?>
<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Codes parents</title>
<style>
  body { font-family: Arial, sans-serif; margin: 0; }
  .barre { padding: 10px; background: #fff8e6; border-bottom: 1px solid #f0dca0; font-size: 14px; }
  .grille { display: grid; grid-template-columns: 1fr 1fr; gap: 0; }
  .fiche { border: 1px dashed #999; padding: 12px 14px; font-size: 13px; page-break-inside: avoid; min-height: 125px; }
  .fiche .ec { font-weight: bold; font-size: 14px; } .fiche .code { font: bold 20px monospace; letter-spacing: 2px; margin: 6px 0; }
  .fiche .petit { font-size: 11px; color: #444; }
  @media print { .barre { display: none; } }
</style></head><body>
<div class="barre"><b><?= count($fiches) ?> code(s) créé(s).</b> Imprimez-les maintenant : ils ne seront plus jamais affichés
  (en cas de perte, créez un nouveau code pour l'élève). <button onclick="print()">Imprimer</button>
  <a href="<?= APP_URL . '/' . $ici . '?classe=' . $id_classe ?>">Retour</a></div>
<div class="grille">
<?php foreach ($fiches as $x): $e = $x['eleve']; ?>
  <div class="fiche">
    <div class="ec"><?= h($nom_ecole) ?> — Espace parents</div>
    <div><?= h(ed_nom($e)) ?> · <?= h($e['classe']) ?></div>
    <div>Matricule : <b><?= h((string) $e['matricule']) ?></b></div>
    <div class="code"><?= h($x['code']) ?></div>
    <div class="petit">Connexion : <?= h($lien) ?><br>Code personnel et confidentiel : ne le communiquez à personne.</div>
  </div>
<?php endforeach; ?>
</div></body></html>
<?php exit; }

$classes = ed_classes();
$codes = $id_classe ? ep_codes_existants($id_classe) : [];
$eleves = $id_classe ? ed_eleves($id_classe) : [];
$titre_page = 'Espace parents';
require_once __DIR__ . '/../../../../noyau/layout/header.php';
?>
<div class="page-titre">
  <h4><i class="bi bi-house-heart me-1 text-primary"></i>Envoyer aux parents — espace parents</h4>
  <div class="sub">Les parents consultent en ligne le reste à payer et les versements, les absences et les résultats des trimestres que vous publiez. Ils ne peuvent rien modifier.</div>
</div>
<?= flash_html() ?>
<?php $onglet_comm = 'espace_parents'; require __DIR__ . '/_onglets.php'; ?>

<div class="row g-3">
  <div class="col-lg-6">
    <form method="post" class="card p-3 h-100">
      <?= csrf_champ() ?><input type="hidden" name="op" value="reglages"><input type="hidden" name="classe" value="<?= $id_classe ?>">
      <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="actif" id="ep_actif" value="1" <?= $reg['actif'] ? 'checked' : '' ?>>
        <label class="form-check-label fw-semibold" for="ep_actif">Espace parents ouvert</label></div>
      <div class="small fw-semibold mt-2">Connexion des parents</div>
      <div class="form-check"><input class="form-check-input" type="checkbox" name="sms" id="ep_sms" value="1" <?= $reg['sms'] ? 'checked' : '' ?>>
        <label class="form-check-label small" for="ep_sms">Téléphone + code reçu par SMS
          <?php if (!sms_gw_actif()): ?><span class="text-warning">(passerelle SMS non configurée : indisponible)</span><?php endif; ?></label></div>
      <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="code" id="ep_code" value="1" <?= $reg['code'] ? 'checked' : '' ?>>
        <label class="form-check-label small" for="ep_code">Matricule + code parent imprimé par l'école</label></div>
      <div class="small fw-semibold mt-2">Résultats visibles par les parents</div>
      <?php foreach ($periodes as $p): ?>
        <div class="form-check"><input class="form-check-input" type="checkbox" name="trims[]" id="ep_t<?= $p['id'] ?>" value="<?= $p['id'] ?>" <?= in_array($p['id'], array_map('intval', (array) $reg['trims']), true) ? 'checked' : '' ?>>
          <label class="form-check-label small" for="ep_t<?= $p['id'] ?>"><?= h($p['libelle']) ?></label></div>
      <?php endforeach; ?>
      <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="annee" id="ep_an" value="1" <?= $reg['annee'] ? 'checked' : '' ?>>
        <label class="form-check-label small" for="ep_an">Moyenne annuelle</label></div>
      <div class="small text-muted mb-2">Cochez un trimestre seulement quand ses notes sont définitives (après le conseil de classe).</div>
      <button class="btn btn-primary btn-sm align-self-start"><i class="bi bi-save me-1"></i>Enregistrer</button>
    </form>
  </div>
  <div class="col-lg-6">
    <div class="card p-3 h-100">
      <div class="fw-semibold mb-1"><i class="bi bi-link-45deg me-1"></i>Lien à donner aux parents</div>
      <div class="input-group input-group-sm mb-2">
        <input type="text" class="form-control" id="ep_lien" value="<?= h($lien) ?>" readonly>
        <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard && navigator.clipboard.writeText(document.getElementById('ep_lien').value); this.textContent='Copié'">Copier</button>
      </div>
      <div class="small text-muted">À mettre dans un communiqué, un SMS (onglet Envoyer → message libre) ou sur les fiches de codes.
        Avec le code SMS, le parent doit utiliser un numéro enregistré dans la fiche de l'élève (onglet Contacts des parents).</div>
    </div>
  </div>
</div>

<div class="card p-3 mt-3">
  <div class="fw-semibold mb-2"><i class="bi bi-key me-1"></i>Codes parents</div>
  <form method="get" class="d-flex gap-2 align-items-center mb-2">
    <select name="classe" class="form-select form-select-sm" style="max-width:280px" onchange="this.form.submit()">
      <option value="0">— Choisir une classe —</option>
      <?php foreach ($classes as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $id_classe ? 'selected' : '' ?>><?= h($c['libelle']) ?> (<?= (int) $c['nb'] ?>)</option><?php endforeach; ?>
    </select>
  </form>
  <?php if ($id_classe): ?>
    <div class="small mb-2"><?= count($codes) ?> élève(s) sur <?= count($eleves) ?> ont déjà un code. Un code n'est affiché qu'à sa création, sur la fiche à imprimer.</div>
    <form method="post" class="d-flex flex-wrap gap-2 mb-2" target="_blank">
      <?= csrf_champ() ?><input type="hidden" name="classe" value="<?= $id_classe ?>">
      <?php if (count($codes) < count($eleves)): ?>
      <button class="btn btn-primary btn-sm" name="op" value="codes_manquants"><i class="bi bi-printer me-1"></i>Créer et imprimer les codes manquants (<?= count($eleves) - count($codes) ?>)</button>
      <?php endif; ?>
      <button class="btn btn-outline-danger btn-sm" name="op" value="codes_tous" onclick="return confirm('Remplacer les codes de TOUTE la classe ? Les anciens codes ne fonctionneront plus.')"><i class="bi bi-arrow-repeat me-1"></i>Recréer tous les codes de la classe</button>
    </form>
    <table class="table table-sm table-abz mb-0" style="font-size:.84rem">
      <thead><tr><th>Élève</th><th>Matricule</th><th>Code</th><th></th></tr></thead>
      <tbody><?php foreach ($eleves as $e): $k = (int) $e['id']; ?>
        <tr><td><?= h(ed_nom($e)) ?></td><td><?= h((string) $e['matricule']) ?></td>
            <td class="small"><?= isset($codes[$k]) ? '<span class="text-success">créé le ' . h(date('d/m/Y', strtotime($codes[$k]))) . '</span>' : '<span class="text-muted">aucun</span>' ?></td>
            <td class="text-end"><form method="post" target="_blank" class="d-inline"><?= csrf_champ() ?><input type="hidden" name="classe" value="<?= $id_classe ?>"><input type="hidden" name="eleve" value="<?= $k ?>">
              <button class="btn btn-link btn-sm p-0" name="op" value="code_un" title="Nouveau code pour cet élève (code perdu)"><?= isset($codes[$k]) ? 'nouveau code' : 'créer' ?></button></form></td></tr>
      <?php endforeach; ?></tbody>
    </table>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../../../../noyau/layout/footer.php'; ?>
