<?php
header('Cache-Control: no-store, no-cache, must-revalidate');
require_once __DIR__ . '/../../../../config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
exiger_connexion();

$role = role_connecte();
$user = utilisateur_connecte();

// ── Accès : ADMIN/CENSEUR/PROVISEUR/FONDATEUR (toutes classes), SG (sa
//    classe d'affectation), ENSEIGNANT principal (sa classe). Aucun autre
//    rôle. PROVISEUR/FONDATEUR ajoutés le 18/09/2026 (oubli constaté : le
//    chef d'établissement ne voyait pas la discipline de sa propre école). ──
$full_access = in_array($role, ['ADMIN', 'CENSEUR', 'PROVISEUR', 'FONDATEUR']) || $role === 'MEMBRE_ASSOCIATION';
$annee_act   = get_annee_active();
$id_annee    = (int)($annee_act['id'] ?? 0);
$val_annee   = $annee_act['libelle'] ?? '';

$classes_access = [];
if ($full_access) {
    $classes_access = db_all("SELECT * FROM classe WHERE archivee=0 ORDER BY ordre, designation");
} elseif ($role === 'SG') {
    $mat_ens = get_matricule_ens_connecte();
    $classes_access = db_all(
        "SELECT c.* FROM sg
         JOIN classe c ON c.id = sg.IDClasses
         WHERE sg.matricule_ens=? AND sg.val_annee=? AND c.archivee=0
         ORDER BY c.ordre, c.designation",
        [$mat_ens, $val_annee]
    );
} elseif ($role === 'ENSEIGNANT') {
    $mat_ens = get_matricule_ens_connecte();
    $classes_access = db_all(
        "SELECT c.* FROM enseignat_principal ep
         JOIN classe c ON c.id = ep.IDClasses
         WHERE ep.matricule_ens=? AND ep.val_annee=? AND c.archivee=0",
        [$mat_ens, $val_annee]
    );
}

if (empty($classes_access)) {
    flash_set('erreur', 'Accès non autorisé au module Discipline.');
    rediriger('dashboard.php');
}
$ids_classes_ok = array_column($classes_access, 'id');

// ── Trimestre actif (dérivé de la séquence active) ─────────────────
$trim_actif = db_one(
    "SELECT t.* FROM trimestre t JOIN sequence s ON s.id_trim=t.id WHERE s.active=1 LIMIT 1"
);
$id_trim = (int)($trim_actif['id'] ?? 0);

$onglet = in_array($_GET['onglet'] ?? '', ['absences', 'exclusions', 'retards', 'fiche_presence'], true)
    ? $_GET['onglet'] : 'absences';

$id_classe = (int)($_GET['classe'] ?? ($classes_access[0]['id'] ?? 0));
// "Toutes les classes" (classe=0) n'est une valeur valide que pour l'onglet
// Fiche de présence — les autres onglets (saisie) exigent une classe précise.
if ($id_classe === 0 && $onglet === 'fiche_presence') {
    // valeur acceptée telle quelle
} elseif (!in_array($id_classe, $ids_classes_ok, true)) {
    $id_classe = $classes_access[0]['id'] ?? 0;
}

// CSRF
if (empty($_SESSION['csrf_discipline'])) {
    $_SESSION['csrf_discipline'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_discipline'];

// ── Élèves de la classe ─────────────────────────────────────────────
$eleves = [];
if ($id_classe && $id_trim) {
    $eleves = db_all(
        "SELECT e.id, e.matricule, e.nom, e.prenom
         FROM eleve e
         JOIN inscription i ON i.id_eleve=e.id AND i.id_classe=? AND i.id_annee=?
         WHERE e.statut='actif' ORDER BY e.nom, e.prenom",
        [$id_classe, $id_annee]
    );
}

$data_idx = [];
if (!empty($eleves) && $onglet === 'absences') {
    $rows = db_all(
        "SELECT mat_elv, nbre_heure_jus, nbre_heure_non_jus FROM absence
         WHERE id_trim=? AND IDClasses=? AND val_annee=?",
        [$id_trim, $id_classe, $val_annee]
    );
    foreach ($rows as $r) $data_idx[$r['mat_elv']] = $r;
} elseif (!empty($eleves) && $onglet === 'exclusions') {
    $rows = db_all(
        "SELECT mat_elv, SUM(nbre_jours) AS nbre_jours FROM exclusion
         WHERE id_trim=? AND classe=? AND val_annee=? GROUP BY mat_elv",
        [$id_trim, $id_classe, $val_annee]
    );
    foreach ($rows as $r) $data_idx[$r['mat_elv']] = $r;
} elseif (!empty($eleves) && $onglet === 'retards') {
    $rows = db_all(
        "SELECT mat_elv, nbre_retards FROM retard
         WHERE id_trim=? AND IDClasses=? AND val_annee=?",
        [$id_trim, $id_classe, $val_annee]
    );
    foreach ($rows as $r) $data_idx[$r['mat_elv']] = $r;
}

$titre_page = 'Discipline';
require_once __DIR__ . '/../../../../noyau/layout/header.php';
?>
<style>
.tbl-disc th { background:#1a3c6b; color:#fff; font-size:.78rem; padding:8px 10px; }
.tbl-disc td { font-size:.85rem; padding:6px 10px; vertical-align:middle; }
.tbl-disc input[type=number] { width:80px; }
</style>

<div class="page-titre d-flex align-items-center justify-content-between flex-wrap gap-2">
  <h4><i class="bi bi-shield-exclamation me-2" style="color:#1a3c6b"></i>Discipline</h4>
</div>

<?= flash_html() ?>

<?php if (!$id_trim): ?>
  <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i>Aucun trimestre actif — impossible de saisir.</div>
<?php else: ?>

<ul class="nav nav-tabs mb-3">
  <?php foreach (['absences' => "Heures d'absence", 'exclusions' => 'Exclusions', 'retards' => 'Retards', 'fiche_presence' => 'Fiche de présence'] as $key => $label): ?>
    <li class="nav-item">
      <a class="nav-link <?= $onglet === $key ? 'active' : '' ?>"
         href="?onglet=<?= $key ?>&classe=<?= $id_classe ?>"><?= h($label) ?></a>
    </li>
  <?php endforeach; ?>
</ul>

<div class="card mb-3" style="border-color:#c7d8f0">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end">
      <input type="hidden" name="onglet" value="<?= h($onglet) ?>">
      <?php $nb_options_classe = count($classes_access) + ($onglet === 'fiche_presence' ? 1 : 0); ?>
      <div class="col-md-4">
        <label class="form-label mb-1" style="font-size:.78rem;font-weight:600;color:#1a3c6b">Classe</label>
        <select name="classe" class="form-select form-select-sm" onchange="this.form.submit()" <?= $nb_options_classe <= 1 ? 'disabled' : '' ?>>
          <?php if ($onglet === 'fiche_presence'): ?>
            <option value="0" <?= $id_classe === 0 ? 'selected' : '' ?>>— Toutes les classes —</option>
          <?php endif; ?>
          <?php foreach ($classes_access as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $id_classe == $c['id'] ? 'selected' : '' ?>><?= h($c['designation']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if ($nb_options_classe <= 1): ?>
          <input type="hidden" name="classe" value="<?= $id_classe ?>">
        <?php endif; ?>
      </div>
      <?php if ($onglet !== 'fiche_presence'): // trimestre inutile sur une fiche vierge, sans saisie liée ?>
      <div class="col-md-4">
        <span class="badge" style="background:#dbe4f5;color:#1a3c6b;font-size:.78rem;padding:6px 12px">
          <i class="bi bi-calendar3 me-1"></i><?= h($trim_actif['libelle'] ?? '') ?> (en cours)
        </span>
      </div>
      <?php endif; ?>
    </form>
  </div>
</div>

<?php if ($onglet === 'fiche_presence'):
  $classe_lbl = 'Toutes les classes';
  if ($id_classe) {
      $classe_lbl = '';
      foreach ($classes_access as $c) { if ($c['id'] == $id_classe) { $classe_lbl = $c['designation']; break; } }
  }
?>
  <div class="card" style="border-color:#c7d8f0">
    <div class="card-header py-2 d-flex align-items-center justify-content-between flex-wrap gap-2" style="background:#f0f4ff">
      <span class="fw-semibold" style="color:#1a3c6b">
        <i class="bi bi-clipboard2-check me-1"></i>Fiche de présence — <?= h($classe_lbl) ?>
      </span>
      <div class="d-flex gap-2 flex-wrap">
        <a class="btn btn-sm" style="background:#1a3c6b;color:#fff" target="_blank"
           href="<?= APP_URL ?>/secondaire/commun/pages/discipline/pdf_fiche_presence.php?classe=<?= $id_classe ?>&dl=1">
          <i class="bi bi-file-earmark-pdf me-1"></i>Télécharger en PDF
        </a>
        <a class="btn btn-sm btn-outline-success"
           href="<?= APP_URL ?>/secondaire/commun/pages/discipline/excel_fiche_presence.php?classe=<?= $id_classe ?>">
          <i class="bi bi-file-earmark-excel me-1"></i>Télécharger en Excel
        </a>
      </div>
    </div>
    <div class="card-body p-0">
      <iframe src="<?= APP_URL ?>/secondaire/commun/pages/discipline/pdf_fiche_presence.php?classe=<?= $id_classe ?>"
              style="width:100%;height:80vh;border:none;display:block" title="Aperçu fiche de présence"></iframe>
    </div>
  </div>
<?php elseif (empty($eleves)): ?>
  <div class="alert alert-info">Aucun élève actif dans cette classe.</div>
<?php else: ?>

<form method="post" action="save.php">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
  <input type="hidden" name="type" value="<?= h($onglet) ?>">
  <input type="hidden" name="id_classe" value="<?= $id_classe ?>">
  <input type="hidden" name="id_trim" value="<?= $id_trim ?>">
  <input type="hidden" name="back" value="?onglet=<?= $onglet ?>&classe=<?= $id_classe ?>">

  <div class="card" style="border-color:#c7d8f0">
    <div class="card-header py-2 d-flex align-items-center justify-content-between" style="background:#f0f4ff">
      <span class="fw-semibold" style="color:#1a3c6b"><i class="bi bi-people me-1"></i><?= count($eleves) ?> élève(s)</span>
      <button type="submit" class="btn btn-sm" style="background:#1a3c6b;color:#fff;border:none">
        <i class="bi bi-save me-1"></i>Enregistrer
      </button>
    </div>
    <div class="table-responsive">
      <table class="table tbl-disc table-hover mb-0">
        <thead>
          <tr>
            <th>N°</th>
            <th>Nom et Prénom</th>
            <?php if ($onglet === 'absences'): ?>
              <th>Heures justifiées</th>
              <th>Heures non justifiées</th>
            <?php elseif ($onglet === 'exclusions'): ?>
              <th>Jours d'exclusion</th>
            <?php else: ?>
              <th>Nombre de retards</th>
            <?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($eleves as $i => $el): ?>
          <?php $d = $data_idx[$el['matricule']] ?? []; ?>
          <tr>
            <td class="text-muted"><?= $i + 1 ?></td>
            <td class="fw-semibold"><?= h(strtoupper($el['nom']) . ' ' . ($el['prenom'] ?? '')) ?></td>
            <?php if ($onglet === 'absences'): ?>
              <td><input type="number" min="0" class="form-control form-control-sm" name="jus[<?= h($el['matricule']) ?>]" value="<?= (int)($d['nbre_heure_jus'] ?? 0) ?>"></td>
              <td><input type="number" min="0" class="form-control form-control-sm" name="nj[<?= h($el['matricule']) ?>]" value="<?= (int)($d['nbre_heure_non_jus'] ?? 0) ?>"></td>
            <?php elseif ($onglet === 'exclusions'): ?>
              <td><input type="number" min="0" class="form-control form-control-sm" name="jours[<?= h($el['matricule']) ?>]" value="<?= (int)($d['nbre_jours'] ?? 0) ?>"></td>
            <?php else: ?>
              <td><input type="number" min="0" class="form-control form-control-sm" name="retards[<?= h($el['matricule']) ?>]" value="<?= (int)($d['nbre_retards'] ?? 0) ?>"></td>
            <?php endif; ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</form>

<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../../../../noyau/layout/footer.php'; ?>
