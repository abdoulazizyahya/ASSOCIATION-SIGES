<?php
// pages/matieres_arabe/liste.php — Administration du référentiel de la
// piste arabe (matière + coefficient, PAS de compétences) : Groupes de
// matières → Matières (catalogue global) → Matières par classe (coef/ordre/
// groupe). Miroir de pages/competences/liste.php (même convention UI —
// page à onglets, panneau formulaire à gauche + liste à droite, bascule
// Ajouter/Modifier en JS) mais sur les tables réellement utilisées par la
// piste arabe (groupe_matiere_arabe/matiere_arabe/classe_matiere_arabe —
// voir notes_apc_arabe.php::matieres_classe_arabe()), pas sur le schéma
// matiere/discipline d'ABZ_MBE (pages/matieres/liste.php, incompatible et
// jamais lié au menu, supprimé le 13/08/2026).
//
// Demande explicite du 14/08/2026 : ce menu manquait — Pédagogie/Arabe
// n'avait aucun écran pour gérer groupe_matiere_arabe/matiere_arabe/
// classe_matiere_arabe, contrairement à « Groupes de compétences » côté
// français.
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_connexion();
exiger_annee_active(); // Année scolaire réellement active requise (18/08/2026) — module Pédagogie/Discipline.

$role          = role_connecte();
$peut_modifier = ($role === 'DIRECTEUR');

$onglet = $_GET['onglet'] ?? 'groupes';
if (!in_array($onglet, ['groupes', 'matieres', 'par_classe'], true)) $onglet = 'groupes';

$classes = db_all(
    "SELECT c.IDClasses, c.DesignationClasses, n.OrdreNiveau
     FROM classe c LEFT JOIN niveau n ON n.LibelleNiveau = c.Niveau
     ORDER BY n.OrdreNiveau, c.DesignationClasses"
);

// ══════════════════════════════════════════════════════════════
//  ONGLET 1 — Groupes de matières (catalogue global)
// ══════════════════════════════════════════════════════════════
if ($onglet === 'groupes' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    exiger_role(['DIRECTEUR']);
    csrf_verifier();
    $action = post('action');

    if ($action === 'grp_creer') {
        $fr = post('nom_groupe_fr');
        $ar = post('nom_groupe_ar');
        if ($fr !== '') {
            db_exec("INSERT INTO groupe_matiere_arabe (nom_groupe_fr, nom_groupe_ar) VALUES (?, ?)", [$fr, $ar ?: null]);
            flash_set('succes', 'Groupe de matières ajouté.');
        } else {
            flash_set('erreur', 'Le libellé français est requis.');
        }
        rediriger('pages/matieres_arabe/liste.php?onglet=groupes');
    }

    if ($action === 'grp_modifier') {
        $id = (int) post('grp_id');
        $fr = post('nom_groupe_fr');
        $ar = post('nom_groupe_ar');
        if ($id && $fr !== '') {
            db_exec("UPDATE groupe_matiere_arabe SET nom_groupe_fr=?, nom_groupe_ar=? WHERE id_groupe=?", [$fr, $ar ?: null, $id]);
            flash_set('succes', 'Groupe de matières modifié.');
        }
        rediriger('pages/matieres_arabe/liste.php?onglet=groupes');
    }

    if ($action === 'grp_supprimer') {
        $id = (int) post('grp_id');
        $nb = (int) db_val("SELECT COUNT(*) FROM classe_matiere_arabe WHERE id_groupe=?", [$id]);
        if ($nb > 0) {
            flash_set('erreur', "Impossible : ce groupe est utilisé par $nb affectation(s) matière/classe — retirez-les d'abord (onglet Matières par classe).");
        } else {
            db_exec("DELETE FROM groupe_matiere_arabe WHERE id_groupe=?", [$id]);
            flash_set('succes', 'Groupe de matières supprimé.');
        }
        rediriger('pages/matieres_arabe/liste.php?onglet=groupes');
    }
}
$groupes = db_all(
    "SELECT g.*, COUNT(cma.id_mat) AS nb_affectations
     FROM groupe_matiere_arabe g
     LEFT JOIN classe_matiere_arabe cma ON cma.id_groupe = g.id_groupe
     GROUP BY g.id_groupe
     ORDER BY g.id_groupe"
);

// ══════════════════════════════════════════════════════════════
//  ONGLET 2 — Matières (catalogue global)
// ══════════════════════════════════════════════════════════════
if ($onglet === 'matieres' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    exiger_role(['DIRECTEUR']);
    csrf_verifier();
    $action = post('action');

    if ($action === 'mat_creer') {
        $fr = post('matiere_fr');
        $ar = post('matiere_ar');
        if ($fr !== '') {
            db_exec("INSERT INTO matiere_arabe (matiere_fr, matiere_ar) VALUES (?, ?)", [$fr, $ar ?: null]);
            flash_set('succes', 'Matière ajoutée.');
        } else {
            flash_set('erreur', 'Le libellé français est requis.');
        }
        rediriger('pages/matieres_arabe/liste.php?onglet=matieres');
    }

    if ($action === 'mat_modifier') {
        $id = (int) post('mat_id');
        $fr = post('matiere_fr');
        $ar = post('matiere_ar');
        if ($id && $fr !== '') {
            db_exec("UPDATE matiere_arabe SET matiere_fr=?, matiere_ar=? WHERE id_mat=?", [$fr, $ar ?: null, $id]);
            flash_set('succes', 'Matière modifiée.');
        }
        rediriger('pages/matieres_arabe/liste.php?onglet=matieres');
    }

    if ($action === 'mat_supprimer') {
        $id        = (int) post('mat_id');
        $nb_classe = (int) db_val("SELECT COUNT(*) FROM classe_matiere_arabe WHERE id_mat=?", [$id]);
        $nb_notes  = (int) db_val("SELECT COUNT(*) FROM composer_sequence_arabe WHERE id_mat=?", [$id]);
        if ($nb_classe > 0) {
            flash_set('erreur', "Impossible : cette matière est affectée à $nb_classe classe(s) — retirez-la d'abord (onglet Matières par classe).");
        } elseif ($nb_notes > 0) {
            flash_set('erreur', "Impossible : $nb_notes note(s) d'élèves existent déjà pour cette matière.");
        } else {
            db_exec("DELETE FROM matiere_arabe WHERE id_mat=?", [$id]);
            flash_set('succes', 'Matière supprimée.');
        }
        rediriger('pages/matieres_arabe/liste.php?onglet=matieres');
    }
}
$matieres = db_all(
    "SELECT m.*, COUNT(cma.code_classe) AS nb_classes
     FROM matiere_arabe m
     LEFT JOIN classe_matiere_arabe cma ON cma.id_mat = m.id_mat
     GROUP BY m.id_mat
     ORDER BY m.matiere_fr"
);

// ══════════════════════════════════════════════════════════════
//  ONGLET 3 — Matières par classe (coef/ordre/groupe)
// ══════════════════════════════════════════════════════════════
$f_classe = (int) ($_GET['classe_pc'] ?? 0);

if ($onglet === 'par_classe' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    exiger_role(['DIRECTEUR']);
    csrf_verifier();
    $action     = post('action');
    $id_classe  = (int) post('id_classe');

    if ($action === 'aff_creer') {
        $id_mat    = (int) post('id_mat');
        $id_groupe = (int) post('id_groupe');
        $coef      = (int) post('coef') ?: 1;
        $ordre     = (int) post('ordre') ?: 1;
        if ($id_classe && $id_mat && $id_groupe) {
            db_exec(
                "INSERT INTO classe_matiere_arabe (id_mat, code_classe, coef, ordre, id_groupe) VALUES (?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE coef=VALUES(coef), ordre=VALUES(ordre), id_groupe=VALUES(id_groupe)",
                [$id_mat, $id_classe, $coef, $ordre, $id_groupe]
            );
            flash_set('succes', 'Matière affectée à la classe.');
        } else {
            flash_set('erreur', 'Matière et groupe sont requis.');
        }
        rediriger("pages/matieres_arabe/liste.php?onglet=par_classe&classe_pc=$id_classe");
    }

    if ($action === 'aff_modifier') {
        $id_mat    = (int) post('id_mat');
        $id_groupe = (int) post('id_groupe');
        $coef      = (int) post('coef') ?: 1;
        $ordre     = (int) post('ordre') ?: 1;
        if ($id_classe && $id_mat && $id_groupe) {
            db_exec(
                "UPDATE classe_matiere_arabe SET coef=?, ordre=?, id_groupe=? WHERE id_mat=? AND code_classe=?",
                [$coef, $ordre, $id_groupe, $id_mat, $id_classe]
            );
            flash_set('succes', 'Affectation modifiée.');
        }
        rediriger("pages/matieres_arabe/liste.php?onglet=par_classe&classe_pc=$id_classe");
    }

    if ($action === 'aff_supprimer') {
        $id_mat   = (int) post('id_mat');
        $nb_notes = (int) db_val(
            "SELECT COUNT(*) FROM composer_sequence_arabe WHERE id_mat=? AND classe=?",
            [$id_mat, $id_classe]
        );
        if ($nb_notes > 0) {
            flash_set('erreur', "Impossible : $nb_notes note(s) d'élèves existent déjà pour cette matière dans cette classe — la matière resterait sans note visible si vous continuez, retirez-les d'abord si besoin.");
        } else {
            db_exec("DELETE FROM classe_matiere_arabe WHERE id_mat=? AND code_classe=?", [$id_mat, $id_classe]);
            flash_set('succes', 'Matière retirée de la classe.');
        }
        rediriger("pages/matieres_arabe/liste.php?onglet=par_classe&classe_pc=$id_classe");
    }
}

$affectations   = [];
$matieres_libre = []; // pas encore affectées à cette classe
if ($f_classe) {
    $affectations = db_all(
        "SELECT cma.id_mat, cma.coef, cma.ordre, cma.id_groupe,
                m.matiere_fr, m.matiere_ar, g.nom_groupe_fr, g.nom_groupe_ar
         FROM classe_matiere_arabe cma
         JOIN matiere_arabe m ON m.id_mat = cma.id_mat
         JOIN groupe_matiere_arabe g ON g.id_groupe = cma.id_groupe
         WHERE cma.code_classe = ?
         ORDER BY cma.ordre, m.matiere_fr",
        [$f_classe]
    );
    $ids_affectes   = array_column($affectations, 'id_mat');
    $matieres_libre = array_filter($matieres, fn($m) => !in_array((int) $m['id_mat'], $ids_affectes, true));
}
$total_coef = array_sum(array_column($affectations, 'coef'));

// Mode « partiel » (AJAX) : réponse limitée au contenu de #matieres-ar-zone,
// sans header/sidebar/footer — même convention que pages/statistiques/index.php.
$es_partiel = isset($_GET['partiel']);
if (!$es_partiel) {
    $titre_page = 'Groupes de matières (arabe)';
    require_once __DIR__ . '/../../layout/header.php';
} else {
    header('Content-Type: text/html; charset=utf-8');
}
?>

<div id="matieres-ar-zone">

<div class="page-titre d-flex justify-content-between align-items-center flex-wrap gap-2">
  <div>
    <h4><i class="bi bi-diagram-3 me-1 text-primary"></i>Pédagogie — Matières (piste arabe)</h4>
    <div class="sub">Groupes de matières, catalogue des matières et affectation par classe (coefficient, ordre)</div>
  </div>
</div>

<ul class="nav nav-tabs mb-3" style="border-bottom:2px solid #e5e7eb">
  <li class="nav-item">
    <a class="nav-link <?= $onglet === 'groupes' ? 'active' : '' ?>" data-ajax-nav
       href="<?= APP_URL ?>/pages/matieres_arabe/liste.php?onglet=groupes">
      <i class="bi bi-collection me-1"></i>Groupes de matières
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $onglet === 'matieres' ? 'active' : '' ?>" data-ajax-nav
       href="<?= APP_URL ?>/pages/matieres_arabe/liste.php?onglet=matieres">
      <i class="bi bi-journal-bookmark me-1"></i>Matières
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $onglet === 'par_classe' ? 'active' : '' ?>" data-ajax-nav
       href="<?= APP_URL ?>/pages/matieres_arabe/liste.php?onglet=par_classe<?= $f_classe ? "&classe_pc=$f_classe" : '' ?>">
      <i class="bi bi-diagram-2 me-1"></i>Matières par classe
    </a>
  </li>
</ul>

<?php if ($onglet === 'groupes'): ?>
<!-- ══════════════════════════════════════════════
     ONGLET 1 — Groupes de matières
══════════════════════════════════════════════ -->
<div class="row g-2" style="align-items:flex-start">

  <?php if ($peut_modifier): ?>
  <div class="col-md-4">
    <div class="card h-100" style="border:1px solid #c7d2fe">
      <div class="card-header py-2 px-3" style="background:linear-gradient(135deg,#eef2ff,#e0e7ff);border-bottom:1px solid #c7d2fe">
        <span class="fw-bold" style="font-size:.8rem;color:#312e81">
          <i class="bi bi-plus-circle-fill me-1"></i><span id="grp-form-title">Ajouter un groupe</span>
        </span>
      </div>
      <div class="card-body p-3">
        <form method="post" id="form-grp" data-ajax-post-form>
          <?= csrf_champ() ?>
          <input type="hidden" name="action" id="grp_action" value="grp_creer">
          <input type="hidden" name="grp_id" id="grp_id" value="0">
          <div class="mb-2">
            <label class="form-label" style="font-size:.75rem">Libellé (français)</label>
            <input type="text" name="nom_groupe_fr" id="grp_fr" class="form-control form-control-sm" required>
          </div>
          <div class="mb-3">
            <label class="form-label" style="font-size:.75rem">Libellé (arabe)</label>
            <input type="text" name="nom_groupe_ar" id="grp_ar" class="form-control form-control-sm" dir="rtl">
          </div>
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-sm btn-primary" id="grp-btn-submit">Ajouter</button>
            <button type="button" class="btn btn-sm btn-light d-none" id="grp-btn-annuler" onclick="reinitGrpForm()">Annuler</button>
          </div>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="col-md-<?= $peut_modifier ? 8 : 12 ?>">
    <div class="card h-100" style="border:1px solid #e5e7eb">
      <div class="card-header py-2 px-3" style="background:#f8faff;border-bottom:1px solid #e5e7eb">
        <span class="fw-bold" style="font-size:.8rem;color:#374151">Groupes de matières — <?= count($groupes) ?></span>
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0" style="font-size:.78rem">
          <thead style="background:#f8faff">
            <tr>
              <th>Libellé (FR)</th>
              <th>Libellé (AR)</th>
              <th style="width:110px" class="text-center">Affectations</th>
              <?php if ($peut_modifier): ?><th style="width:80px" class="text-end">Actions</th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php if (!$groupes): ?>
              <tr><td colspan="4" class="text-center text-muted py-3">Aucun groupe de matières.</td></tr>
            <?php else: foreach ($groupes as $g): ?>
              <tr>
                <td class="fw-semibold"><?= h($g['nom_groupe_fr']) ?></td>
                <td dir="rtl" lang="ar"><?= h($g['nom_groupe_ar'] ?: '—') ?></td>
                <td class="text-center"><?= (int) $g['nb_affectations'] ?></td>
                <?php if ($peut_modifier): ?>
                <td class="text-end">
                  <button type="button" class="btn btn-sm btn-light" style="padding:3px 7px"
                          onclick="editGrp(<?= (int) $g['id_groupe'] ?>,<?= h(json_encode($g['nom_groupe_fr'])) ?>,<?= h(json_encode($g['nom_groupe_ar'])) ?>)">
                    <i class="bi bi-pencil" style="font-size:.72rem"></i>
                  </button>
                  <form method="post" class="d-inline" data-ajax-post-form onsubmit="return confirm('Supprimer ce groupe de matières ?')">
                    <?= csrf_champ() ?>
                    <input type="hidden" name="action" value="grp_supprimer">
                    <input type="hidden" name="grp_id" value="<?= (int) $g['id_groupe'] ?>">
                    <button type="submit" class="btn btn-sm btn-light text-danger" style="padding:3px 7px" <?= $g['nb_affectations'] > 0 ? 'disabled title="Utilisé par des affectations matière/classe"' : '' ?>>
                      <i class="bi bi-trash" style="font-size:.72rem"></i>
                    </button>
                  </form>
                </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
function editGrp(id, fr, ar) {
    document.getElementById('grp_action').value = 'grp_modifier';
    document.getElementById('grp_id').value     = id;
    document.getElementById('grp_fr').value     = fr;
    document.getElementById('grp_ar').value     = ar || '';
    document.getElementById('grp-form-title').textContent = 'Modifier le groupe';
    document.getElementById('grp-btn-submit').textContent = 'Enregistrer';
    document.getElementById('grp-btn-annuler').classList.remove('d-none');
    document.getElementById('grp_fr').focus();
}
function reinitGrpForm() {
    document.getElementById('form-grp').reset();
    document.getElementById('grp_action').value = 'grp_creer';
    document.getElementById('grp_id').value     = '0';
    document.getElementById('grp-form-title').textContent = 'Ajouter un groupe';
    document.getElementById('grp-btn-submit').textContent = 'Ajouter';
    document.getElementById('grp-btn-annuler').classList.add('d-none');
}
</script>

<?php elseif ($onglet === 'matieres'): ?>
<!-- ══════════════════════════════════════════════
     ONGLET 2 — Matières (catalogue global)
══════════════════════════════════════════════ -->
<div class="row g-2" style="align-items:flex-start">

  <?php if ($peut_modifier): ?>
  <div class="col-md-4">
    <div class="card h-100" style="border:1px solid #c7d2fe">
      <div class="card-header py-2 px-3" style="background:linear-gradient(135deg,#eef2ff,#e0e7ff);border-bottom:1px solid #c7d2fe">
        <span class="fw-bold" style="font-size:.8rem;color:#312e81">
          <i class="bi bi-plus-circle-fill me-1"></i><span id="mat-form-title">Ajouter une matière</span>
        </span>
      </div>
      <div class="card-body p-3">
        <form method="post" id="form-mat" data-ajax-post-form>
          <?= csrf_champ() ?>
          <input type="hidden" name="action" id="mat_action" value="mat_creer">
          <input type="hidden" name="mat_id" id="mat_id" value="0">
          <div class="mb-2">
            <label class="form-label" style="font-size:.75rem">Libellé (français)</label>
            <input type="text" name="matiere_fr" id="mat_fr" class="form-control form-control-sm" placeholder="ex. Lecture du Coran" required>
          </div>
          <div class="mb-3">
            <label class="form-label" style="font-size:.75rem">Libellé (arabe)</label>
            <input type="text" name="matiere_ar" id="mat_ar" class="form-control form-control-sm" dir="rtl" placeholder="القرآن">
          </div>
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-sm btn-primary" id="mat-btn-submit">Ajouter</button>
            <button type="button" class="btn btn-sm btn-light d-none" id="mat-btn-annuler" onclick="reinitMatForm()">Annuler</button>
          </div>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="col-md-<?= $peut_modifier ? 8 : 12 ?>">
    <div class="card h-100" style="border:1px solid #e5e7eb">
      <div class="card-header py-2 px-3" style="background:#f8faff;border-bottom:1px solid #e5e7eb">
        <span class="fw-bold" style="font-size:.8rem;color:#374151">Matières — <?= count($matieres) ?></span>
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0" style="font-size:.78rem">
          <thead style="background:#f8faff">
            <tr>
              <th>Libellé (FR)</th>
              <th>Libellé (AR)</th>
              <th style="width:80px" class="text-center">Classes</th>
              <?php if ($peut_modifier): ?><th style="width:80px" class="text-end">Actions</th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php if (!$matieres): ?>
              <tr><td colspan="4" class="text-center text-muted py-3">Aucune matière.</td></tr>
            <?php else: foreach ($matieres as $m): ?>
              <tr>
                <td class="fw-semibold"><?= h($m['matiere_fr']) ?></td>
                <td dir="rtl" lang="ar"><?= h($m['matiere_ar'] ?: '—') ?></td>
                <td class="text-center"><?= (int) $m['nb_classes'] ?></td>
                <?php if ($peut_modifier): ?>
                <td class="text-end">
                  <button type="button" class="btn btn-sm btn-light" style="padding:3px 7px"
                          onclick="editMat(<?= (int) $m['id_mat'] ?>,<?= h(json_encode($m['matiere_fr'])) ?>,<?= h(json_encode($m['matiere_ar'])) ?>)">
                    <i class="bi bi-pencil" style="font-size:.72rem"></i>
                  </button>
                  <form method="post" class="d-inline" data-ajax-post-form onsubmit="return confirm('Supprimer cette matière ?')">
                    <?= csrf_champ() ?>
                    <input type="hidden" name="action" value="mat_supprimer">
                    <input type="hidden" name="mat_id" value="<?= (int) $m['id_mat'] ?>">
                    <button type="submit" class="btn btn-sm btn-light text-danger" style="padding:3px 7px" <?= $m['nb_classes'] > 0 ? 'disabled title="Affectée à des classes"' : '' ?>>
                      <i class="bi bi-trash" style="font-size:.72rem"></i>
                    </button>
                  </form>
                </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
function editMat(id, fr, ar) {
    document.getElementById('mat_action').value = 'mat_modifier';
    document.getElementById('mat_id').value     = id;
    document.getElementById('mat_fr').value     = fr;
    document.getElementById('mat_ar').value     = ar || '';
    document.getElementById('mat-form-title').textContent = 'Modifier la matière';
    document.getElementById('mat-btn-submit').textContent = 'Enregistrer';
    document.getElementById('mat-btn-annuler').classList.remove('d-none');
    document.getElementById('mat_fr').focus();
}
function reinitMatForm() {
    document.getElementById('form-mat').reset();
    document.getElementById('mat_action').value = 'mat_creer';
    document.getElementById('mat_id').value     = '0';
    document.getElementById('mat-form-title').textContent = 'Ajouter une matière';
    document.getElementById('mat-btn-submit').textContent = 'Ajouter';
    document.getElementById('mat-btn-annuler').classList.add('d-none');
}
</script>

<?php elseif ($onglet === 'par_classe'): ?>
<!-- ══════════════════════════════════════════════
     ONGLET 3 — Matières par classe (coef/ordre/groupe)
══════════════════════════════════════════════ -->
<form method="get" class="d-flex align-items-center gap-3 flex-wrap mb-3" data-ajax-nav-form>
  <input type="hidden" name="onglet" value="par_classe">
  <div style="min-width:260px">
    <select name="classe_pc" class="form-select form-select-sm" data-ajax-nav-auto>
      <option value="">— Choisir une classe —</option>
      <?php foreach ($classes as $c): ?>
        <option value="<?= $c['IDClasses'] ?>" <?= $f_classe === (int) $c['IDClasses'] ? 'selected' : '' ?>>
          <?= h($c['DesignationClasses']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php if ($f_classe): ?>
    <span class="badge bg-light text-dark border" style="font-size:.75rem">
      <?= count($affectations) ?> matière(s) · Σ coef <?= $total_coef ?>
    </span>
  <?php endif; ?>
</form>

<?php if (!$f_classe): ?>
  <div class="text-center text-muted py-5">
    <i class="bi bi-cursor" style="font-size:2.5rem;display:block;opacity:.15;margin-bottom:.5rem"></i>
    Sélectionnez une classe pour gérer ses matières.
  </div>
<?php else: ?>

<div class="row g-2" style="align-items:flex-start">

  <?php if ($peut_modifier): ?>
  <div class="col-md-4">
    <div class="card h-100" style="border:1px solid #c7d2fe">
      <div class="card-header py-2 px-3" style="background:linear-gradient(135deg,#eef2ff,#e0e7ff);border-bottom:1px solid #c7d2fe">
        <span class="fw-bold" style="font-size:.8rem;color:#312e81">
          <i class="bi bi-plus-circle-fill me-1"></i><span id="aff-form-title">Affecter une matière</span>
        </span>
      </div>
      <div class="card-body p-3">
        <?php if (!$matieres_libre && !$affectations): ?>
          <p class="text-muted mb-0" style="font-size:.78rem">Créez d'abord des matières (onglet Matières).</p>
        <?php else: ?>
        <form method="post" id="form-aff" data-ajax-post-form>
          <?= csrf_champ() ?>
          <input type="hidden" name="action" id="aff_action" value="aff_creer">
          <input type="hidden" name="id_classe" value="<?= $f_classe ?>">
          <div class="mb-2">
            <label class="form-label" style="font-size:.75rem">Matière</label>
            <select name="id_mat" id="aff_id_mat" class="form-select form-select-sm" required>
              <option value="">— Choisir —</option>
              <?php foreach ($matieres_libre as $m): ?>
                <option value="<?= (int) $m['id_mat'] ?>"><?= h($m['matiere_fr']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-2">
            <label class="form-label" style="font-size:.75rem">Groupe</label>
            <select name="id_groupe" id="aff_id_groupe" class="form-select form-select-sm" required>
              <option value="">— Choisir —</option>
              <?php foreach ($groupes as $g): ?>
                <option value="<?= (int) $g['id_groupe'] ?>"><?= h($g['nom_groupe_fr']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label" style="font-size:.75rem">Coefficient</label>
              <input type="number" name="coef" id="aff_coef" class="form-control form-control-sm" min="1" value="1" required>
            </div>
            <div class="col-6">
              <label class="form-label" style="font-size:.75rem">Ordre</label>
              <input type="number" name="ordre" id="aff_ordre" class="form-control form-control-sm" min="1" value="<?= count($affectations) + 1 ?>" required>
            </div>
          </div>
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-sm btn-primary" id="aff-btn-submit">Affecter</button>
            <button type="button" class="btn btn-sm btn-light d-none" id="aff-btn-annuler" onclick="reinitAffForm()">Annuler</button>
          </div>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="col-md-<?= $peut_modifier ? 8 : 12 ?>">
    <div class="card h-100" style="border:1px solid #e5e7eb">
      <div class="card-header py-2 px-3" style="background:#f8faff;border-bottom:1px solid #e5e7eb">
        <span class="fw-bold" style="font-size:.8rem;color:#374151">
          Matières — <?= h($classes[array_search($f_classe, array_column($classes, 'IDClasses'))]['DesignationClasses'] ?? '') ?>
        </span>
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0" style="font-size:.78rem">
          <thead style="background:#f8faff">
            <tr>
              <th style="width:45px" class="text-center">Ordre</th>
              <th>Matière</th>
              <th>Groupe</th>
              <th style="width:60px" class="text-center">Coef</th>
              <?php if ($peut_modifier): ?><th style="width:80px" class="text-end">Actions</th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php if (!$affectations): ?>
              <tr><td colspan="5" class="text-center text-muted py-3">Aucune matière affectée à cette classe.</td></tr>
            <?php else: foreach ($affectations as $a): ?>
              <tr>
                <td class="text-center text-muted"><?= (int) $a['ordre'] ?></td>
                <td class="fw-semibold"><?= h($a['matiere_fr']) ?> <span class="text-muted" dir="rtl" lang="ar" style="font-size:.85em"><?= h($a['matiere_ar'] ?: '') ?></span></td>
                <td><?= h($a['nom_groupe_fr']) ?></td>
                <td class="text-center"><span class="badge-code"><?= (int) $a['coef'] ?></span></td>
                <?php if ($peut_modifier): ?>
                <td class="text-end">
                  <button type="button" class="btn btn-sm btn-light" style="padding:3px 7px"
                          onclick="editAff(<?= (int) $a['id_mat'] ?>,<?= (int) $a['id_groupe'] ?>,<?= (int) $a['coef'] ?>,<?= (int) $a['ordre'] ?>,<?= h(json_encode($a['matiere_fr'])) ?>)">
                    <i class="bi bi-pencil" style="font-size:.72rem"></i>
                  </button>
                  <form method="post" class="d-inline" data-ajax-post-form onsubmit="return confirm('Retirer « <?= h(addslashes($a['matiere_fr'])) ?> » de cette classe ?')">
                    <?= csrf_champ() ?>
                    <input type="hidden" name="action" value="aff_supprimer">
                    <input type="hidden" name="id_classe" value="<?= $f_classe ?>">
                    <input type="hidden" name="id_mat" value="<?= (int) $a['id_mat'] ?>">
                    <button type="submit" class="btn btn-sm btn-light text-danger" style="padding:3px 7px">
                      <i class="bi bi-trash" style="font-size:.72rem"></i>
                    </button>
                  </form>
                </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
function editAff(idMat, idGroupe, coef, ordre, nom) {
    document.getElementById('aff_action').value = 'aff_modifier';
    // La matière déjà affectée n'apparaît pas dans le <select> (limité aux
    // libres) — on l'y ajoute temporairement pour pouvoir la présélectionner.
    var sel = document.getElementById('aff_id_mat');
    var dejaPresent = Array.from(sel.options).some(o => o.value == idMat);
    if (!dejaPresent) {
        var opt = document.createElement('option');
        opt.value = idMat; opt.textContent = nom;
        sel.appendChild(opt);
    }
    sel.value = idMat;
    // pointer-events (pas "disabled") : un select disabled ne serait pas
    // soumis avec le formulaire, le serveur ne saurait plus quelle ligne
    // mettre à jour (WHERE id_mat=? AND code_classe=?).
    sel.style.pointerEvents = 'none';
    sel.style.background = '#f3f4f6';
    document.getElementById('aff_id_groupe').value = idGroupe;
    document.getElementById('aff_coef').value  = coef;
    document.getElementById('aff_ordre').value = ordre;
    document.getElementById('aff-form-title').textContent = 'Modifier l\'affectation';
    document.getElementById('aff-btn-submit').textContent = 'Enregistrer';
    document.getElementById('aff-btn-annuler').classList.remove('d-none');
}
function reinitAffForm() {
    document.getElementById('form-aff').reset();
    document.getElementById('aff_action').value = 'aff_creer';
    var sel = document.getElementById('aff_id_mat');
    sel.style.pointerEvents = '';
    sel.style.background = '';
    document.getElementById('aff-form-title').textContent = 'Affecter une matière';
    document.getElementById('aff-btn-submit').textContent = 'Affecter';
    document.getElementById('aff-btn-annuler').classList.add('d-none');
}
</script>

<?php endif; // f_classe ?>
<?php endif; // onglets ?>

</div><!-- /#matieres-ar-zone -->
<?php if ($es_partiel) exit; // rien de plus dans une réponse AJAX partielle. ?>

<?php
$ajax_zone_id = 'matieres-ar-zone'; // voir layout/footer.php — initAjaxZone() y est appelé
require_once __DIR__ . '/../../layout/footer.php';
