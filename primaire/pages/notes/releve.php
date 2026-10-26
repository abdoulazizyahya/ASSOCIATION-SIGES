<?php
// primaire/pages/notes/releve.php — Relevé de notes d'une évaluation (26/10/2026),
// vierge ou rempli, piste française (compétence : Orale / Écrite / Pratique /
// Savoir-être) ou arabe (matière : Oral / Écrit / Pratique). Choix : classe,
// compétence ou matière (ou toutes), évaluation, type ; aperçu, impression, PDF.
// Le document : primaire/pdf/releve_notes_evaluation.php.
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../noyau/connexion.php';
require_once __DIR__ . '/../../../noyau/fonctions.php';
require_once __DIR__ . '/../../notes_apc.php';
require_once __DIR__ . '/../../notes_apc_arabe.php';
exiger_acces_pedagogie();
exiger_annee_active();

$val_annee = (string) (get_annee_active()['val_annee'] ?? '');
$piste = ($_GET['piste'] ?? 'fr') === 'ar' ? 'ar' : 'fr';
$classes = filtrer_classes_visibles(db_all(
    "SELECT c.IDClasses, c.DesignationClasses FROM classe c LEFT JOIN niveau n ON n.LibelleNiveau = c.Niveau ORDER BY n.OrdreNiveau, c.DesignationClasses"
), $val_annee, $piste);   // enseignant : seulement ses classes de cette piste
$id_classe = (int) ($_GET['classe'] ?? 0);
if (!in_array($id_classe, array_map('intval', array_column($classes, 'IDClasses')), true)) $id_classe = 0;
$item = (int) ($_GET['item'] ?? 0);
$mode = ($_GET['mode'] ?? '') === 'rempli' ? 'rempli' : 'vierge';
$seqs = db_all("SELECT s.id_seq, s.libelle_seq, t.libelle_trim FROM sequence s JOIN trimestre t ON t.id_trim = s.id_trim WHERE t.id_annee = ? ORDER BY s.id_seq", [$val_annee]);
$id_seq = (int) ($_GET['seq'] ?? (get_sequence_active()['id_seq'] ?? 0));
if (!in_array($id_seq, array_map('intval', array_column($seqs, 'id_seq')), true)) $id_seq = 0;
$annuel = ($_GET['seq'] ?? '') === 'annee';   // toute l'année : les 3 trimestres sur une page

$rel_items = [];
if ($id_classe) {
    if ($piste === 'fr') foreach (competences_classe($id_classe, $val_annee) as $c)
        $rel_items[] = ['id' => (int) $c['id_comp'], 'libelle' => $c['nom_comp'], 'groupe' => $c['libelle_groupe_comp'],
                    'bareme' => 'O ' . (float) $c['orale'] . ' · É ' . (float) $c['ecrite'] . ' · P ' . (float) $c['pratique'] . ' · SE ' . (float) $c['savoir_etre'] . ' = ' . (float) $c['total_points']];
    else foreach (matieres_classe_arabe_avec_bareme($id_classe, $val_annee) as $m)
        $rel_items[] = ['id' => (int) $m['id_mat'], 'libelle' => $m['matiere_fr'], 'groupe' => $m['nom_groupe_fr'],
                    'bareme' => $m['bareme'] ? 'O ' . (float) $m['bareme']['orale'] . ' · É ' . (float) $m['bareme']['ecrite'] . ' · P ' . (float) $m['bareme']['pratique'] . ' = ' . (float) $m['bareme']['total_points'] : 'barème non configuré'];
}
if ($item > 0 && !in_array($item, array_column($rel_items, 'id'), true)) $item = 0;
if ($mode === 'rempli' && ((!$id_seq && !$annuel) || $item === 0)) $mode = 'vierge';   // rempli : une évaluation et une compétence / matière (ou toutes)

$q = http_build_query(['piste' => $piste, 'classe' => $id_classe, 'item' => $item, 'seq' => $annuel ? 'annee' : $id_seq, 'mode' => $mode]);
$url = APP_URL . '/primaire/pdf/releve_notes_evaluation.php?' . $q;
$lib = fn(array $l, $id, $k) => array_values(array_filter($l, fn($x) => (int) $x[$k] === (int) $id))[0] ?? null;
$titre = 'Relevé de notes ' . $mode . ' — ' . ($lib($classes, $id_classe, 'IDClasses')['DesignationClasses'] ?? '')
       . ($item > 0 ? ' — ' . ($lib($rel_items, $item, 'id')['libelle'] ?? '') : ($item === -1 ? ' — toutes' : ''));
$titre_page = 'Relevé de notes';
require_once __DIR__ . '/../../../noyau/layout/header.php';
?>
<div class="page-titre d-flex align-items-center justify-content-between flex-wrap gap-2">
  <h4><i class="bi bi-file-earmark-spreadsheet me-1 text-primary"></i>Relevé de notes</h4>
  <div class="d-flex gap-2">
    <a class="btn btn-sm btn-outline-secondary" href="<?= APP_URL ?>/primaire/pages/notes/index.php"><i class="bi bi-pencil-square me-1"></i>Saisie (français)</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= APP_URL ?>/primaire/pages/notes_arabe/index.php"><i class="bi bi-pencil-square me-1"></i>Saisie (arabe)</a>
  </div>
</div>
<div class="card mb-3"><div class="card-body">
  <form method="get" class="row g-2 align-items-end">
    <div class="col-md-2"><label class="form-label fw-semibold small">Piste</label>
      <select name="piste" class="form-select form-select-sm" onchange="this.form.classe.value=0;this.form.item.value=0;this.form.submit()">
        <option value="fr" <?= $piste === 'fr' ? 'selected' : '' ?>>Française</option><option value="ar" <?= $piste === 'ar' ? 'selected' : '' ?>>Arabe</option></select></div>
    <div class="col-md-2"><label class="form-label fw-semibold small">Classe</label>
      <select name="classe" class="form-select form-select-sm" onchange="this.form.item.value=0;this.form.submit()"><option value="0">— Choisir —</option>
      <?php foreach ($classes as $c): ?><option value="<?= (int) $c['IDClasses'] ?>" <?= (int) $c['IDClasses'] === $id_classe ? 'selected' : '' ?>><?= h($c['DesignationClasses']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-4"><label class="form-label fw-semibold small"><?= $piste === 'fr' ? 'Compétence' : 'Matière' ?></label>
      <select name="item" class="form-select form-select-sm" onchange="this.form.submit()" <?= $id_classe ? '' : 'disabled' ?>>
        <option value="0">— Aucune (feuille à compléter) —</option>
        <?php if ($rel_items): ?><option value="-1" <?= $item === -1 ? 'selected' : '' ?>>— Toutes (une page chacune) —</option><?php endif; ?>
        <?php $g = null; foreach ($rel_items as $rel_it): if ($rel_it['groupe'] !== $g): if ($g !== null) echo '</optgroup>'; $g = $rel_it['groupe']; ?><optgroup label="<?= h($g) ?>"><?php endif; ?>
          <option value="<?= $rel_it['id'] ?>" <?= $rel_it['id'] === $item ? 'selected' : '' ?>><?= h($rel_it['libelle']) ?> (<?= h($rel_it['bareme']) ?>)</option>
        <?php endforeach; if ($g !== null) echo '</optgroup>'; ?></select></div>
    <div class="col-md-2"><label class="form-label fw-semibold small">Évaluation</label>
      <select name="seq" class="form-select form-select-sm" onchange="this.form.submit()"><option value="0">— À compléter —</option>
      <option value="annee" <?= $annuel ? 'selected' : '' ?>>— Toute l'année (3 trimestres) —</option>
      <?php foreach ($seqs as $s): ?><option value="<?= (int) $s['id_seq'] ?>" <?= (int) $s['id_seq'] === $id_seq ? 'selected' : '' ?>><?= h($s['libelle_seq'] . ' (' . $s['libelle_trim'] . ')') ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label fw-semibold small">Type</label>
      <select name="mode" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="vierge">Vierge</option>
        <option value="rempli" <?= $mode === 'rempli' ? 'selected' : '' ?> <?= ($id_seq || $annuel) && $item !== 0 ? '' : 'disabled' ?>>Rempli (notes saisies)</option></select></div>
  </form>
  <?php if ($id_classe): ?>
  <div class="mt-3 d-flex flex-wrap gap-2 align-items-center">
    <button type="button" class="btn btn-sm btn-primary" onclick="afficherApercu(<?= h(json_encode($url)) ?>, <?= h(json_encode($titre)) ?>, 'releve_notes_evaluation', 'portrait')"><i class="bi bi-eye me-1"></i>Afficher / imprimer</button>
    <a class="btn btn-sm" style="background:#1a2744;color:#fff" href="<?= h($url) ?>&dl=1"><i class="bi bi-file-earmark-pdf me-1"></i>Télécharger en PDF</a>
    <span class="small text-muted"><?= $piste === 'fr' ? 'Colonnes : Orale, Écrite, Pratique, Savoir-être selon le barème de la compétence, puis total et cote.' : 'Colonnes : Oral, Écrit, Pratique selon le barème de la matière, puis total et cote.' ?>
      <?= $annuel ? " Toute l'année : par trimestre, le total de chaque évaluation et la note du trimestre, puis la note annuelle et sa cote (page en paysage)." : '' ?>
      <?= $mode === 'vierge' && !$id_seq && !$annuel ? ' Le rempli demande une évaluation et une compétence / matière.' : '' ?></span>
  </div>
  <?php endif; ?>
</div></div>
<?php if ($id_classe): ?>
<div class="card"><div class="card-header py-2" style="background:#f0f4ff"><span class="fw-semibold"><i class="bi bi-eye me-1"></i>Aperçu — <?= h($titre) ?></span></div>
  <div class="card-body p-0"><iframe src="<?= h($url) ?>" style="width:100%;height:80vh;border:none;display:block" title="Aperçu du relevé de notes"></iframe></div></div>
<?php else: ?>
<div class="alert alert-light border small">Choisissez une classe<?= $classes ? '' : ' (aucune classe ne vous est affectée sur cette piste)' ?>.</div>
<?php endif; ?>
<?php require_once __DIR__ . '/../../../noyau/layout/footer.php'; ?>
