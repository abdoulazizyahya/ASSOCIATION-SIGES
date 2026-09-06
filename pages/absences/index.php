<?php
// ── Absences — saisie des jours d'absence par classe/trimestre ──────
// Port du sous-menu Pédagogie ▸ Absences d'ABZ_MBE (même chrome : zone AJAX
// partielle, data-ajax-nav, CSS partagé) mais ABZ_MBE n'a AUCUN équivalent
// réel côté DONNÉES : sa page (pages/absences/index.php) est en fait un
// workflow de « justification de note manquante » (table `note`/
// `absence_justifiee`, propre au modèle simple note/matière d'ABZ_MBE, sans
// notion de jours). jaynitaare a un vrai concept d'assiduité hérité du
// legacy (`jaynitaare/php/form_enrg_abscence.php`) : jours d'absence
// justifiés/non justifiés par élève, par classe, par TRIMESTRE (pas par
// séquence — vérifié sur les lignes réelles déjà saisies, `absence.id_trim`
// correspond à `trimestre.id_trim`, jamais à `sequence.id_seq`). Reconstruit
// ici sur ce vrai modèle plutôt que copié à l'identique du workflow ABZ_MBE
// qui n'a pas de sens pour jaynitaare.
//
// Enregistrement en JOURS (pas en heures) depuis la migration_v40, demande
// explicite du 17/08/2026 — colonnes `absence.nbre_jour_non_jus`/
// `nbre_jour_jus` (renommées depuis nbre_heure_non_jus/nbre_heure_jus, même
// sémantique de comptage, seule l'unité change).
//
// Piste arabe : PAS de variante distincte — l'assiduité est une donnée
// physique de présence en classe, pas académique par piste (`absence` ne
// référence ni matière ni compétence, aucune table `absence_arabe` dans le
// schéma) — un même relevé sert les deux pistes.
header('Cache-Control: no-store, no-cache, must-revalidate');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_acces_pedagogie();
exiger_annee_active(); // Année scolaire réellement active requise (18/08/2026) — module Pédagogie/Discipline.

// Mode "partiel" (AJAX) : réponse limitée au contenu de #abs-zone — voir
// initAjaxZone()/chargerPartiel() dans layout/footer.php. Le formulaire
// d'enregistrement (POST) reste un envoi de page complète classique, comme
// dans ABZ_MBE — seuls les changements de classe/trimestre passent par l'AJAX.
$es_partiel = isset($_GET['partiel']);

$annee     = get_annee_active();
$val_annee = $annee['val_annee'] ?? '';
$seq_active = get_sequence_active();

$id_classe = (int) ($_GET['classe'] ?? 0);
$id_trim   = (int) ($_GET['trim'] ?? ($seq_active['id_trim'] ?? 0));

// DIRECTEUR/SECRETAIRE voient tout ; un ENSEIGNANT ne voit que ses classes
// affectées (voir filtrer_classes_visibles(), demande explicite du 29/08/2026)
// — calculé avant le bloc Enregistrement ci-dessous pour aussi valider
// l'écriture POST (pas seulement le <select> affiché plus bas).
$classes = filtrer_classes_visibles(db_all(
    "SELECT c.IDClasses, c.DesignationClasses, n.OrdreNiveau
     FROM classe c LEFT JOIN niveau n ON n.LibelleNiveau = c.Niveau
     ORDER BY n.OrdreNiveau, c.DesignationClasses"
), $val_annee);
$ids_classes_vis = array_column($classes, 'IDClasses');
if ($id_classe && !in_array($id_classe, $ids_classes_vis, true)) $id_classe = 0;

// ── Enregistrement ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'enregistrer') {
    csrf_verifier();
    $p_classe = (int) post('id_classe');
    $p_trim   = (int) post('id_trim');
    if (!in_array($p_classe, $ids_classes_vis, true)) {
        flash_set('erreur', "Vous n'êtes pas affecté(e) à cette classe.");
        rediriger('pages/absences/index.php');
    }

    $touches = 0;
    foreach ($_POST['jours'] ?? [] as $id_eleve => $vals) {
        $id_eleve = (int) $id_eleve;
        $njus = max(0, (int) ($vals['non_jus'] ?? 0));
        $jus  = max(0, (int) ($vals['jus'] ?? 0));
        db_exec(
            "INSERT INTO absence (id_eleve, id_trim, classe, nbre_jour_non_jus, nbre_jour_jus, val_annee)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE nbre_jour_non_jus=VALUES(nbre_jour_non_jus), nbre_jour_jus=VALUES(nbre_jour_jus)",
            [$id_eleve, $p_trim, $p_classe, $njus, $jus, $val_annee]
        );
        $touches++;
    }
    flash_set('succes', "$touches fiche(s) d'absence enregistrée(s).");
    rediriger("pages/absences/index.php?classe=$p_classe&trim=$p_trim");
}

// ── Données pour l'affichage ────────────────────────────────────
// $classes déjà chargée (filtrée) plus haut, avant le bloc Enregistrement.
$trimestres = db_all("SELECT id_trim, libelle_trim FROM trimestre WHERE id_annee=? ORDER BY id_trim", [$val_annee]);

$eleves = [];
if ($id_classe && $id_trim) {
    $eleves = db_all(
        "SELECT e.id_eleve, e.Nom_elv, e.Prenom_elv,
                a.nbre_jour_non_jus, a.nbre_jour_jus
         FROM eleve e
         JOIN inscrire i ON i.id_eleve = e.id_eleve
         LEFT JOIN absence a ON a.id_eleve = e.id_eleve AND a.id_trim = ? AND a.classe = ? AND a.val_annee = ?
         WHERE i.IDClasses = ? AND i.val_annee = ? AND e.statut = 'actif'
         ORDER BY e.Nom_elv, e.Prenom_elv",
        [$id_trim, $id_classe, $val_annee, $id_classe, $val_annee]
    );
}

// Port de Avertissement_Conduite_eleve()/Blame_Conduite_eleve()
// (jaynitaare/php/mes_fonctions.php) : seuils sur les jours NON justifiés.
$conduite = function (int $jours_non_jus): array {
    if ($jours_non_jus >= 10) return ['Blâme', '#991b1b', '#fee2e2'];
    if ($jours_non_jus >= 5)  return ['Avertissement', '#92400e', '#fef3c7'];
    return ['—', '#6b7280', '#f3f4f6'];
};

$trim_lib = '';
foreach ($trimestres as $t) if ($t['id_trim'] == $id_trim) { $trim_lib = $t['libelle_trim']; break; }
$label_periode = $trim_lib ?: '—';

if (!$es_partiel) {
    $titre_page = 'Absences';
    require_once __DIR__ . '/../../layout/header.php';
    ?>
    <style>
    .badge-periode { background:#dbeafe;color:#1e3a8a;font-size:.78rem;padding:5px 12px;border-radius:20px;font-weight:600; }
    .tbl-abs th     { background:#1a3c6b;color:#fff;font-size:.78rem;padding:8px 10px; }
    .tbl-abs td     { font-size:.81rem;padding:7px 10px;vertical-align:middle; }
    .tbl-abs tr:hover td { background:#f0f4ff; }
    </style>
    <?= flash_html() ?>
    <?php
} else {
    header('Content-Type: text/html; charset=utf-8');
}
?>

<div id="abs-zone">

<div class="page-titre d-flex align-items-center justify-content-between flex-wrap gap-2">
  <h4><i class="bi bi-person-x me-2" style="color:#1a3c6b"></i>Absences</h4>
  <div class="d-flex align-items-center gap-2 flex-wrap">
    <span class="badge-periode"><i class="bi bi-calendar3 me-1"></i><?= h($label_periode) ?></span>
  </div>
</div>

<!-- ── Filtres ── -->
<div class="card mb-3" style="border-color:#c7d8f0">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end" data-ajax-nav-form action="<?= APP_URL ?>/pages/absences/index.php">
      <div class="col-md-4">
        <label class="form-label mb-1" style="font-size:.78rem;font-weight:600;color:#1a3c6b">Classe</label>
        <select name="classe" class="form-select form-select-sm" data-ajax-nav-auto>
          <option value="">— Choisir —</option>
          <?php foreach ($classes as $c): ?>
            <option value="<?= $c['IDClasses'] ?>" <?= $id_classe == $c['IDClasses'] ? 'selected' : '' ?>>
              <?= h($c['DesignationClasses']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label mb-1" style="font-size:.78rem;font-weight:600;color:#1a3c6b">Trimestre</label>
        <select name="trim" class="form-select form-select-sm" data-ajax-nav-auto>
          <?php foreach ($trimestres as $t): ?>
            <option value="<?= $t['id_trim'] ?>" <?= $id_trim == $t['id_trim'] ? 'selected' : '' ?>>
              <?= h($t['libelle_trim']) ?><?= ($seq_active['id_trim'] ?? 0) == $t['id_trim'] ? ' ★' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </form>
  </div>
</div>

<?php if (!$id_classe): ?>
  <div class="text-center py-5 text-muted">
    <i class="bi bi-arrow-up-circle" style="font-size:3rem;opacity:.2;display:block;margin-bottom:1rem"></i>
    Sélectionnez une classe pour afficher les absences.
  </div>

<?php elseif (!$id_trim): ?>
  <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i>Aucun trimestre sélectionné.</div>

<?php elseif (empty($eleves)): ?>
  <div class="alert alert-info"><i class="bi bi-info-circle me-2"></i>Aucun élève actif dans cette classe.</div>

<?php else: ?>

<form method="post" action="<?= APP_URL ?>/pages/absences/index.php">
  <?= csrf_champ() ?>
  <input type="hidden" name="action" value="enregistrer">
  <input type="hidden" name="id_classe" value="<?= $id_classe ?>">
  <input type="hidden" name="id_trim" value="<?= $id_trim ?>">

  <div class="card" style="border-color:#c7d8f0">
    <div class="card-header py-2 d-flex align-items-center justify-content-between flex-wrap gap-2" style="background:#f0f4ff">
      <span class="fw-semibold" style="color:#1a3c6b">
        <i class="bi bi-people me-1"></i><?= count($eleves) ?> élève(s) —
        <span class="fw-normal text-muted"><?= h($label_periode) ?></span>
      </span>
      <button type="submit" class="btn btn-sm btn-abz-primary"><i class="bi bi-save me-1"></i>Enregistrer</button>
    </div>
    <div class="table-responsive">
      <table class="table tbl-abs table-hover mb-0">
        <thead>
          <tr>
            <th>N°</th>
            <th>Élève</th>
            <th class="text-center" style="width:130px">Jours non justifiés</th>
            <th class="text-center" style="width:110px">Jours justifiés</th>
            <th class="text-center" style="width:130px">Conduite</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($eleves as $i => $el):
            $njus = (int) ($el['nbre_jour_non_jus'] ?? 0);
            [$lbl, $col, $bg] = $conduite($njus);
          ?>
            <tr>
              <td class="text-muted"><?= $i + 1 ?></td>
              <td class="fw-semibold"><?= h(mb_strtoupper($el['Nom_elv'])) ?> <?= h($el['Prenom_elv'] ?? '') ?></td>
              <td>
                <input type="number" class="form-control form-control-sm jour-inp text-center"
                       name="jours[<?= $el['id_eleve'] ?>][non_jus]" min="0" step="1"
                       value="<?= $njus ?: '' ?>" data-eleve="<?= $el['id_eleve'] ?>" placeholder="0">
              </td>
              <td>
                <input type="number" class="form-control form-control-sm text-center"
                       name="jours[<?= $el['id_eleve'] ?>][jus]" min="0" step="1"
                       value="<?= (int) ($el['nbre_jour_jus'] ?? 0) ?: '' ?>" placeholder="0">
              </td>
              <td class="text-center conduite-cell" id="conduite-<?= $el['id_eleve'] ?>">
                <span style="background:<?= $bg ?>;color:<?= $col ?>;font-size:.72rem;padding:2px 9px;border-radius:10px;font-weight:600"><?= h($lbl) ?></span>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="card-body py-2 border-top d-flex align-items-center gap-2">
      <button type="submit" class="btn btn-sm btn-abz-primary"><i class="bi bi-save me-1"></i>Enregistrer</button>
      <span class="ms-auto text-muted" style="font-size:.75rem">Avertissement à partir de 5j non justifiés, Blâme à partir de 10j</span>
    </div>
  </div>
</form>

<script>
document.querySelectorAll('.jour-inp').forEach(function (inp) {
  inp.addEventListener('input', function () {
    var v = parseInt(this.value, 10) || 0;
    var cell = document.getElementById('conduite-' + this.dataset.eleve);
    var lbl, col, bg;
    if (v >= 10)      { lbl = 'Blâme';         col = '#991b1b'; bg = '#fee2e2'; }
    else if (v >= 5)  { lbl = 'Avertissement'; col = '#92400e'; bg = '#fef3c7'; }
    else              { lbl = '—';             col = '#6b7280'; bg = '#f3f4f6'; }
    cell.innerHTML = '<span style="background:' + bg + ';color:' + col + ';font-size:.72rem;padding:2px 9px;border-radius:10px;font-weight:600">' + lbl + '</span>';
  });
});
</script>

<?php endif; ?>

</div><!-- /#abs-zone -->

<?php
if ($es_partiel) exit; // rien de plus dans une réponse AJAX partielle.
$ajax_zone_id = 'abs-zone'; // voir layout/footer.php — initAjaxZone() y est appelé après sa propre définition
require_once __DIR__ . '/../../layout/footer.php';
