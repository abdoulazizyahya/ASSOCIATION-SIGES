<?php
// pages/matieres_arabe/liste.php — Administration du référentiel de la
// piste arabe (matière + coefficient, pas de compétences) : Groupes de
// matières → Matières (catalogue) → Matières par niveau → Barème par
// niveau. Tables : groupe_matiere_arabe/matiere_arabe/matiere_niveau_arabe
// (voir notes_apc_arabe.php::matieres_classe_arabe()) — plus d'affectation
// par classe : une classe a exactement les matières de son niveau.
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_acces_pedagogie();
exiger_annee_active(); // Année scolaire réellement active requise (18/08/2026) — module Pédagogie/Discipline.

$role          = role_connecte();
$peut_modifier = ($role === 'DIRECTEUR');

$onglet = $_GET['onglet'] ?? 'groupes';
if (!in_array($onglet, ['groupes', 'matieres', 'niveau', 'bareme'], true)) $onglet = 'groupes';

// Année active — utilisée par les onglets Barème/Matières par niveau.
$annee     = get_annee_active();
$val_annee = $annee['val_annee'] ?? '';

$classes = db_all(
    "SELECT c.IDClasses, c.DesignationClasses, n.OrdreNiveau
     FROM classe c LEFT JOIN niveau n ON n.LibelleNiveau = c.Niveau
     ORDER BY n.OrdreNiveau, c.DesignationClasses"
);
// Tous les niveaux actifs, avec ou sans classe (une classe qui sera créée
// plus tard reprend automatiquement les matières déjà configurées ici).
$niveaux_liste_ar = db_all(
    "SELECT LibelleNiveau, OrdreNiveau FROM niveau WHERE actif = 1 ORDER BY OrdreNiveau"
);

// ══════════════════════════════════════════════════════════════
//  ONGLET 1 — Groupes de matières (catalogue global)
// ══════════════════════════════════════════════════════════════
if ($onglet === 'groupes' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    exiger_role(['DIRECTEUR']);
    csrf_verifier();
    $action = post('action');

    // L'arabe est requis ; si le français est vide, on y recopie l'arabe
    // (nom_groupe_fr reste NOT NULL en base).
    if ($action === 'grp_creer') {
        $fr = post('nom_groupe_fr');
        $ar = post('nom_groupe_ar');
        if ($ar !== '') {
            db_exec("INSERT INTO groupe_matiere_arabe (nom_groupe_fr, nom_groupe_ar) VALUES (?, ?)", [$fr !== '' ? $fr : $ar, $ar]);
            flash_set('succes', 'Groupe de matières ajouté.');
        } else {
            flash_set('erreur', 'Le libellé arabe est requis.');
        }
        rediriger('pages/matieres_arabe/liste.php?onglet=groupes');
    }

    if ($action === 'grp_modifier') {
        $id = (int) post('grp_id');
        $fr = post('nom_groupe_fr');
        $ar = post('nom_groupe_ar');
        if ($id && $ar !== '') {
            db_exec("UPDATE groupe_matiere_arabe SET nom_groupe_fr=?, nom_groupe_ar=? WHERE id_groupe=?", [$fr !== '' ? $fr : $ar, $ar, $id]);
            flash_set('succes', 'Groupe de matières modifié.');
        }
        rediriger('pages/matieres_arabe/liste.php?onglet=groupes');
    }

    if ($action === 'grp_supprimer') {
        $id = (int) post('grp_id');
        $nb = (int) db_val("SELECT COUNT(*) FROM matiere_arabe WHERE id_groupe=?", [$id]);
        if ($nb > 0) {
            flash_set('erreur', "Impossible : ce groupe est utilisé par $nb matière(s) — retirez-les d'abord (onglet Matières).");
        } else {
            db_exec("DELETE FROM groupe_matiere_arabe WHERE id_groupe=?", [$id]);
            flash_set('succes', 'Groupe de matières supprimé.');
        }
        rediriger('pages/matieres_arabe/liste.php?onglet=groupes');
    }
}
$groupes = db_all(
    "SELECT g.*, COUNT(m.id_mat) AS nb_affectations
     FROM groupe_matiere_arabe g
     LEFT JOIN matiere_arabe m ON m.id_groupe = g.id_groupe
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

    // Même règle que les groupes : arabe requis, français recopié si vide.
    if ($action === 'mat_creer') {
        $fr        = post('matiere_fr');
        $ar        = post('matiere_ar');
        $id_groupe = (int) post('id_groupe') ?: null;
        if ($ar !== '') {
            db_exec("INSERT INTO matiere_arabe (matiere_fr, matiere_ar, id_groupe) VALUES (?, ?, ?)", [$fr !== '' ? $fr : $ar, $ar, $id_groupe]);
            flash_set('succes', 'Matière ajoutée.');
        } else {
            flash_set('erreur', 'Le libellé arabe est requis.');
        }
        rediriger('pages/matieres_arabe/liste.php?onglet=matieres');
    }

    if ($action === 'mat_modifier') {
        $id        = (int) post('mat_id');
        $fr        = post('matiere_fr');
        $ar        = post('matiere_ar');
        $id_groupe = (int) post('id_groupe') ?: null;
        if ($id && $ar !== '') {
            db_exec("UPDATE matiere_arabe SET matiere_fr=?, matiere_ar=?, id_groupe=? WHERE id_mat=?", [$fr !== '' ? $fr : $ar, $ar, $id_groupe, $id]);
            flash_set('succes', 'Matière modifiée.');
        }
        rediriger('pages/matieres_arabe/liste.php?onglet=matieres');
    }

    if ($action === 'mat_supprimer') {
        $id        = (int) post('mat_id');
        $nb_niveau = (int) db_val("SELECT COUNT(*) FROM matiere_niveau_arabe WHERE id_mat=?", [$id]);
        $nb_notes  = (int) db_val("SELECT COUNT(*) FROM composer_sequence_arabe WHERE id_mat=?", [$id]);
        if ($nb_niveau > 0) {
            flash_set('erreur', "Impossible : cette matière est affectée à $nb_niveau niveau(x) — retirez-la d'abord (onglet Matières par niveau).");
        } elseif ($nb_notes > 0) {
            flash_set('erreur', "Impossible : $nb_notes note(s) d'élèves existent déjà pour cette matière.");
        } else {
            db_exec("DELETE FROM matiere_arabe WHERE id_mat=?", [$id]);
            flash_set('succes', 'Matière supprimée.');
        }
        rediriger('pages/matieres_arabe/liste.php?onglet=matieres');
    }
}
// Tri du catalogue à la demande (par matière — défaut — ou par groupe),
// via l'en-tête de colonne cliquable ci-dessous — pas de tri fixe imposé.
$tri_mat   = ($_GET['tri_mat'] ?? 'matiere') === 'groupe' ? 'groupe' : 'matiere';
$order_mat = $tri_mat === 'groupe'
    ? 'g.id_groupe IS NULL, g.nom_groupe_ar, g.nom_groupe_fr, m.matiere_ar, m.matiere_fr'
    : 'm.matiere_ar, m.matiere_fr';
$matieres = db_all(
    "SELECT m.*, g.nom_groupe_fr, g.nom_groupe_ar, COUNT(DISTINCT mn.code_niveau) AS nb_niveaux
     FROM matiere_arabe m
     LEFT JOIN groupe_matiere_arabe g ON g.id_groupe = m.id_groupe
     LEFT JOIN matiere_niveau_arabe mn ON mn.id_mat = m.id_mat AND mn.actif = 1
     GROUP BY m.id_mat
     ORDER BY $order_mat"
);

// En-tête de colonne triable (lien AJAX, cohérent avec la nav par onglets
// de cette même page — pas de rechargement complet).
function th_tri_mat_ar(string $val, string $label, string $tri_actuel): string {
    $actif = $tri_actuel === $val;
    $icon  = $actif ? ' <i class="bi bi-caret-down-fill" style="font-size:.6rem"></i>' : '';
    $cls   = $actif ? 'text-primary fw-bold' : 'text-dark';
    return '<a href="' . APP_URL . '/pages/matieres_arabe/liste.php?onglet=matieres&tri_mat=' . $val
         . '" data-ajax-nav class="text-decoration-none ' . $cls . '">' . $label . $icon . '</a>';
}

// ══════════════════════════════════════════════════════════════
//  ONGLET 3 — Matières par niveau
//  Matières (+ ordre) assignées à un niveau — s'appliquent immédiatement à
//  toutes ses classes, matieres_classe_arabe() les résout directement d'ici
//  (pas de table intermédiaire à synchroniser).
// ══════════════════════════════════════════════════════════════
$f_niveau_mn = $_GET['niveau_mn'] ?? ($niveaux_liste_ar[0]['LibelleNiveau'] ?? '');

if ($onglet === 'niveau' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    exiger_role(['DIRECTEUR']);
    csrf_verifier();
    $niveau = post('code_niveau');
    if ($niveau) {
        $assignes = $_POST['assigne'] ?? [];
        $ordres   = $_POST['ordre'] ?? [];
        foreach ($matieres as $m) {
            $idm = (int) $m['id_mat'];
            if (!empty($assignes[$idm])) {
                $ordre = (int) ($ordres[$idm] ?? 1) ?: 1;
                db_exec(
                    "INSERT INTO matiere_niveau_arabe (code_niveau, id_mat, ordre, actif) VALUES (?, ?, ?, 1)
                     ON DUPLICATE KEY UPDATE ordre=VALUES(ordre), actif=1",
                    [$niveau, $idm, $ordre]
                );
            } else {
                db_exec("DELETE FROM matiere_niveau_arabe WHERE code_niveau=? AND id_mat=?", [$niveau, $idm]);
            }
        }
        flash_set('succes', "Matières mises à jour pour le niveau $niveau.");
    }
    rediriger('pages/matieres_arabe/liste.php?onglet=niveau&niveau_mn=' . urlencode($niveau));
}

$assoc_niveau_mn = [];
if ($f_niveau_mn) {
    foreach (db_all("SELECT id_mat, ordre, actif FROM matiere_niveau_arabe WHERE code_niveau=?", [$f_niveau_mn]) as $a) {
        $assoc_niveau_mn[(int) $a['id_mat']] = $a;
    }
}
// Matières groupées par matiere_arabe.id_groupe (bucket 0 = non classée).
$matieres_par_groupe_mn = [];
foreach ($matieres as $m) {
    $idg = $m['id_groupe'] !== null ? (int) $m['id_groupe'] : 0;
    if (!isset($matieres_par_groupe_mn[$idg])) {
        $libelle_grp = $idg === 0 ? 'غير مصنفة' : ($m['nom_groupe_ar'] ?: ($m['nom_groupe_fr'] ?? ''));
        $matieres_par_groupe_mn[$idg] = ['libelle' => $libelle_grp, 'lignes' => []];
    }
    $matieres_par_groupe_mn[$idg]['lignes'][] = $m;
}

// ══════════════════════════════════════════════════════════════
//  ONGLET 4 — Barème par niveau (Oral/Écrit/Pratique)
//  Fixé par niveau, répliqué sur ses classes à l'enregistrement. Limité
//  aux matières assignées au niveau (onglet précédent).
// ══════════════════════════════════════════════════════════════
$f_niveau_bar = $_GET['niveau'] ?? '';
// Copie du barème d'un autre niveau (aperçu) — n'écrit rien avant
// « Enregistrer le barème ».
$f_copier_de = $_GET['copier_de'] ?? '';

if ($onglet === 'bareme' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    exiger_role(['DIRECTEUR']);
    csrf_verifier();
    $niveau = post('code_niveau');
    if ($niveau && $val_annee) {
        $classes_niveau_bar = db_all("SELECT IDClasses FROM classe WHERE Niveau=?", [$niveau]);
        $lignes = $_POST['bareme'] ?? [];
        foreach ($lignes as $id_mat => $vals) {
            $id_mat   = (int) $id_mat;
            $orale    = (float) ($vals['orale'] ?? 0);
            $ecrite   = (float) ($vals['ecrite'] ?? 0);
            $pratique = (float) ($vals['pratique'] ?? 0);
            $total    = $orale + $ecrite + $pratique;
            $actif    = !empty($vals['actif']) ? 1 : 0;
            foreach ($classes_niveau_bar as $cl) {
                db_exec(
                    "INSERT INTO discipline_arabe (IDClasses, id_mat, annee_scol, orale, ecrite, pratique, total_points, actif)
                     VALUES (?,?,?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE orale=VALUES(orale), ecrite=VALUES(ecrite), pratique=VALUES(pratique),
                                             total_points=VALUES(total_points), actif=VALUES(actif)",
                    [(int) $cl['IDClasses'], $id_mat, $val_annee, $orale, $ecrite, $pratique, $total, $actif]
                );
            }
        }
        flash_set('succes', "Barème enregistré pour le niveau $niveau (" . count($classes_niveau_bar) . ' classe(s) mise(s) à jour).');
    }
    rediriger('pages/matieres_arabe/liste.php?onglet=bareme&niveau=' . urlencode($niveau));
}

$classes_du_niveau_bar    = [];
$bareme_par_groupe_mat    = [];
$bareme_divergent_mat     = false;
$niveau_a_matieres_assignees = false;

$mode_copie_mat          = false;
$niveau_copie_source_mat = '';
if ($f_niveau_bar && $val_annee) {
    // En mode copie, les valeurs viennent de la classe de référence du
    // niveau source ; les matières affichées restent celles du niveau cible.
    $id_classe_valeurs_mat = null;
    if ($f_copier_de && $f_copier_de !== $f_niveau_bar) {
        $classes_source_mat = db_all("SELECT IDClasses FROM classe WHERE Niveau=? ORDER BY IDClasses", [$f_copier_de]);
        if ($classes_source_mat) {
            $id_classe_valeurs_mat   = (int) $classes_source_mat[0]['IDClasses'];
            $mode_copie_mat          = true;
            $niveau_copie_source_mat = $f_copier_de;
        }
    }

    $bareme_niveau_mat           = bareme_matiere_par_niveau($f_niveau_bar, $val_annee, $id_classe_valeurs_mat);
    $classes_du_niveau_bar       = $bareme_niveau_mat['classes'];
    $bareme_par_groupe_mat       = $bareme_niveau_mat['groupes'];
    $bareme_divergent_mat        = $bareme_niveau_mat['divergent'];
    $niveau_a_matieres_assignees = $bareme_niveau_mat['assigne'];
}

// Total général des points du niveau — somme du « Total » de chaque
// matière active (recalculé en JS à chaque modification, voir plus bas).
$total_general_ar = 0.0;
foreach ($bareme_par_groupe_mat as $grp) {
    foreach ($grp['lignes'] as $b) {
        $active = $b['actif'] === null || (int) $b['actif'] === 1;
        if ($active) $total_general_ar += (float) ($b['total_points'] ?? 0);
    }
}

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
    <a class="nav-link <?= $onglet === 'niveau' ? 'active' : '' ?>" data-ajax-nav
       href="<?= APP_URL ?>/pages/matieres_arabe/liste.php?onglet=niveau<?= $f_niveau_mn ? '&niveau_mn=' . urlencode($f_niveau_mn) : '' ?>">
      <i class="bi bi-layers me-1"></i>Matières par niveau
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $onglet === 'bareme' ? 'active' : '' ?>" data-ajax-nav
       href="<?= APP_URL ?>/pages/matieres_arabe/liste.php?onglet=bareme<?= $f_niveau_bar ? '&niveau=' . urlencode($f_niveau_bar) : '' ?>">
      <i class="bi bi-rulers me-1"></i>Barème par niveau
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
            <label class="form-label" style="font-size:.75rem">الاسم (عربي)</label>
            <input type="text" name="nom_groupe_ar" id="grp_ar" class="form-control form-control-sm" dir="rtl" required>
          </div>
          <div class="mb-3">
            <label class="form-label" style="font-size:.75rem">Libellé (français) <span class="text-muted">— optionnel</span></label>
            <input type="text" name="nom_groupe_fr" id="grp_fr" class="form-control form-control-sm">
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
              <th>المجموعة</th>
              <th style="width:110px" class="text-center">Matières</th>
              <?php if ($peut_modifier): ?><th style="width:80px" class="text-end">Actions</th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php if (!$groupes): ?>
              <tr><td colspan="3" class="text-center text-muted py-3">Aucun groupe de matières.</td></tr>
            <?php else: foreach ($groupes as $g): ?>
              <tr>
                <td class="fw-bold" dir="rtl" lang="ar" style="font-size:1.05em">
                  <?= h($g['nom_groupe_ar'] ?: $g['nom_groupe_fr']) ?>
                  <?php if ($g['nom_groupe_fr'] && $g['nom_groupe_fr'] !== $g['nom_groupe_ar']): ?>
                    <span class="text-muted fw-normal" dir="ltr" style="font-size:.75em;display:block"><?= h($g['nom_groupe_fr']) ?></span>
                  <?php endif; ?>
                </td>
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
                    <button type="submit" class="btn btn-sm btn-light text-danger" style="padding:3px 7px" <?= $g['nb_affectations'] > 0 ? 'disabled title="Utilisé par des matières"' : '' ?>>
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
    document.getElementById('grp_ar').focus();
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
            <label class="form-label" style="font-size:.75rem">الاسم (عربي)</label>
            <input type="text" name="matiere_ar" id="mat_ar" class="form-control form-control-sm" dir="rtl" placeholder="القرآن" required>
          </div>
          <div class="mb-2">
            <label class="form-label" style="font-size:.75rem">Libellé (français) <span class="text-muted">— optionnel</span></label>
            <input type="text" name="matiere_fr" id="mat_fr" class="form-control form-control-sm" placeholder="ex. Lecture du Coran">
          </div>
          <div class="mb-3">
            <label class="form-label" style="font-size:.75rem">Groupe de matières</label>
            <select name="id_groupe" id="mat_groupe" class="form-select form-select-sm" dir="rtl">
              <option value="">— غير مصنفة —</option>
              <?php foreach ($groupes as $g): ?>
                <option value="<?= (int) $g['id_groupe'] ?>"><?= h($g['nom_groupe_ar'] ?: $g['nom_groupe_fr']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text" style="font-size:.68rem">Utilisé pour regrouper l'affichage dans « Matières par niveau » et « Barème par niveau ».</div>
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
              <th><?= th_tri_mat_ar('matiere', 'المادة', $tri_mat) ?></th>
              <th><?= th_tri_mat_ar('groupe', 'Groupe', $tri_mat) ?></th>
              <th style="width:80px" class="text-center">Niveaux</th>
              <?php if ($peut_modifier): ?><th style="width:80px" class="text-end">Actions</th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php if (!$matieres): ?>
              <tr><td colspan="4" class="text-center text-muted py-3">Aucune matière.</td></tr>
            <?php else: foreach ($matieres as $m): ?>
              <tr>
                <td class="fw-bold" dir="rtl" lang="ar" style="font-size:1.05em">
                  <?= h($m['matiere_ar'] ?: $m['matiere_fr']) ?>
                  <?php if ($m['matiere_fr'] && $m['matiere_fr'] !== $m['matiere_ar']): ?>
                    <span class="text-muted fw-normal" dir="ltr" style="font-size:.75em;display:block"><?= h($m['matiere_fr']) ?></span>
                  <?php endif; ?>
                </td>
                <td dir="rtl" lang="ar"><?= $m['nom_groupe_ar'] || $m['nom_groupe_fr'] ? h($m['nom_groupe_ar'] ?: $m['nom_groupe_fr']) : '<span class="text-muted" dir="ltr">Non classée</span>' ?></td>
                <td class="text-center"><?= (int) $m['nb_niveaux'] ?></td>
                <?php if ($peut_modifier): ?>
                <td class="text-end">
                  <button type="button" class="btn btn-sm btn-light" style="padding:3px 7px"
                          onclick="editMat(<?= (int) $m['id_mat'] ?>,<?= h(json_encode($m['matiere_fr'])) ?>,<?= h(json_encode($m['matiere_ar'])) ?>,<?= (int) ($m['id_groupe'] ?? 0) ?>)">
                    <i class="bi bi-pencil" style="font-size:.72rem"></i>
                  </button>
                  <form method="post" class="d-inline" data-ajax-post-form onsubmit="return confirm('Supprimer cette matière ?')">
                    <?= csrf_champ() ?>
                    <input type="hidden" name="action" value="mat_supprimer">
                    <input type="hidden" name="mat_id" value="<?= (int) $m['id_mat'] ?>">
                    <button type="submit" class="btn btn-sm btn-light text-danger" style="padding:3px 7px" <?= $m['nb_niveaux'] > 0 ? 'disabled title="Affectée à un niveau"' : '' ?>>
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
function editMat(id, fr, ar, idGroupe) {
    document.getElementById('mat_action').value = 'mat_modifier';
    document.getElementById('mat_id').value     = id;
    document.getElementById('mat_fr').value     = fr;
    document.getElementById('mat_ar').value     = ar || '';
    document.getElementById('mat_groupe').value = idGroupe || '';
    document.getElementById('mat-form-title').textContent = 'Modifier la matière';
    document.getElementById('mat-btn-submit').textContent = 'Enregistrer';
    document.getElementById('mat-btn-annuler').classList.remove('d-none');
    document.getElementById('mat_ar').focus();
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

<?php elseif ($onglet === 'niveau'): ?>
<!-- ══════════════════════════════════════════════
     ONGLET 3 — Matières par niveau (migration_v46)
══════════════════════════════════════════════ -->
<form method="get" class="d-flex align-items-center gap-3 flex-wrap mb-3" data-ajax-nav-form>
  <input type="hidden" name="onglet" value="niveau">
  <div style="min-width:220px">
    <select name="niveau_mn" class="form-select form-select-sm" data-ajax-nav-auto>
      <?php foreach ($niveaux_liste_ar as $n): ?>
        <option value="<?= h($n['LibelleNiveau']) ?>" <?= $f_niveau_mn === $n['LibelleNiveau'] ? 'selected' : '' ?>>
          Niveau <?= h($n['LibelleNiveau']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
</form>

<?php if (!$niveaux_liste_ar): ?>
  <div class="alert alert-light text-muted text-center py-4">Aucun niveau avec une classe rattachée.</div>
<?php elseif (!$f_niveau_mn): ?>
  <div class="text-center text-muted py-5">
    <i class="bi bi-cursor" style="font-size:2.5rem;display:block;opacity:.15;margin-bottom:.5rem"></i>
    Sélectionnez un niveau.
  </div>
<?php else: ?>

<form method="post" data-ajax-post-form>
  <?= csrf_champ() ?>
  <input type="hidden" name="code_niveau" value="<?= h($f_niveau_mn) ?>">
  <div class="card" style="border:1px solid #e5e7eb">
    <div class="card-header py-2 px-3 d-flex justify-content-between align-items-center flex-wrap gap-2" style="background:#f8faff;border-bottom:1px solid #e5e7eb">
      <span class="fw-bold" style="font-size:.8rem;color:#374151">Matières — Niveau <?= h($f_niveau_mn) ?></span>
      <?php if ($peut_modifier): ?>
      <div class="d-flex gap-2">
        <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-mn-tout-cocher"><i class="bi bi-check-all me-1"></i>Tout cocher</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-mn-tout-decocher"><i class="bi bi-x-square me-1"></i>Tout décocher</button>
        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check-lg me-1"></i>Enregistrer</button>
      </div>
      <?php endif; ?>
    </div>

    <?php if (isset($matieres_par_groupe_mn[0])): ?>
      <div class="alert alert-warning py-2 mx-3 mt-3 mb-0" style="font-size:.78rem">
        <i class="bi bi-exclamation-triangle me-1"></i>
        Des matières n'ont pas encore de groupe — allez d'abord dans l'onglet
        <a href="<?= APP_URL ?>/pages/matieres_arabe/liste.php?onglet=matieres">« Matières »</a> pour leur en assigner un.
      </div>
    <?php endif; ?>

    <?php if (!$matieres): ?>
      <div class="text-center text-muted py-3">Aucune matière au catalogue (onglet « Matières »).</div>
    <?php else: foreach ($matieres_par_groupe_mn as $idg => $grp): ?>
    <div class="px-3 pt-3">
      <div class="fw-bold mb-1" dir="rtl" lang="ar" style="font-size:.85rem;color:<?= $idg === 0 ? '#92400e' : '#374151' ?>"><?= h($grp['libelle']) ?></div>
      <div class="table-responsive mb-2">
        <table class="table table-sm table-hover mb-0" style="font-size:.78rem">
          <thead style="background:#fbfbfd">
            <tr>
              <th>المادة</th>
              <th style="width:90px" class="text-center">Ordre</th>
              <th style="width:90px" class="text-center">Assigné</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($grp['lignes'] as $i_mn => $m): $idm = (int) $m['id_mat']; $assoc = $assoc_niveau_mn[$idm] ?? null; $assigne = $assoc !== null && (int) $assoc['actif'] === 1; $ordre_val = $assoc['ordre'] ?? ($i_mn + 1); ?>
              <tr>
                <td class="fw-bold" dir="rtl" lang="ar">
                  <?= h($m['matiere_ar'] ?: $m['matiere_fr']) ?>
                  <?php if ($m['matiere_fr'] && $m['matiere_fr'] !== $m['matiere_ar']): ?>
                    <span class="text-muted fw-normal" dir="ltr" style="font-size:.8em;display:block"><?= h($m['matiere_fr']) ?></span>
                  <?php endif; ?>
                </td>
                <td class="text-center">
                  <?php if ($peut_modifier): ?>
                    <input type="number" class="form-control form-control-sm text-center" style="max-width:70px;margin:0 auto"
                           name="ordre[<?= $idm ?>]" min="1" value="<?= (int) $ordre_val ?>">
                  <?php else: ?>
                    <?= (int) $ordre_val ?>
                  <?php endif; ?>
                </td>
                <td class="text-center">
                  <?php if ($peut_modifier): ?>
                    <input type="checkbox" class="form-check-input chk-mn-assigne" name="assigne[<?= $idm ?>]" value="1" <?= $assigne ? 'checked' : '' ?>>
                  <?php else: ?>
                    <?= $assigne ? '<i class="bi bi-check-lg text-success"></i>' : '<span class="text-muted">—</span>' ?>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endforeach; endif; ?>
  </div>
</form>

<?php if ($peut_modifier): ?>
<script>
document.getElementById('btn-mn-tout-cocher')?.addEventListener('click', () => {
  document.querySelectorAll('.chk-mn-assigne').forEach(chk => chk.checked = true);
});
document.getElementById('btn-mn-tout-decocher')?.addEventListener('click', () => {
  document.querySelectorAll('.chk-mn-assigne').forEach(chk => chk.checked = false);
});
</script>
<?php endif; ?>

<?php endif; // niveaux_liste_ar / f_niveau_mn ?>

<?php elseif ($onglet === 'bareme'): ?>
<!-- ══════════════════════════════════════════════
     ONGLET 4 — Barème par niveau (Oral/Écrit/Pratique, migration_v46)
══════════════════════════════════════════════ -->
<form method="get" class="d-flex align-items-center gap-3 flex-wrap mb-3" data-ajax-nav-form>
  <input type="hidden" name="onglet" value="bareme">
  <div style="min-width:220px">
    <select name="niveau" class="form-select form-select-sm" data-ajax-nav-auto>
      <option value="">— Choisir un niveau —</option>
      <?php foreach ($niveaux_liste_ar as $n): ?>
        <option value="<?= h($n['LibelleNiveau']) ?>" <?= $f_niveau_bar === $n['LibelleNiveau'] ? 'selected' : '' ?>>
          Niveau <?= h($n['LibelleNiveau']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <?php if ($f_niveau_bar && $peut_modifier && count($niveaux_liste_ar) > 1): ?>
  <div class="d-flex align-items-center gap-2" style="font-size:.78rem;color:#6b7280">
    <i class="bi bi-arrow-repeat"></i>Copier le barème d'un autre niveau :
    <select name="copier_de" class="form-select form-select-sm" style="width:auto">
      <option value="">— aucun —</option>
      <?php foreach ($niveaux_liste_ar as $n): if ($n['LibelleNiveau'] === $f_niveau_bar) continue; ?>
        <option value="<?= h($n['LibelleNiveau']) ?>" <?= $f_copier_de === $n['LibelleNiveau'] ? 'selected' : '' ?>>
          Niveau <?= h($n['LibelleNiveau']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-clipboard-check me-1"></i>Copier ici</button>
  </div>
  <?php endif; ?>
</form>

<div class="alert alert-light text-muted py-2 mb-3" style="font-size:.8rem">
  <i class="bi bi-info-circle me-1"></i>
  Le barème est <strong>fixé par niveau</strong> : l'enregistrement applique les mêmes valeurs à toutes les classes de ce niveau.
  Seules les matières <strong>assignées à ce niveau</strong> (onglet « Matières par niveau ») sont listées ici — pas de Savoir-être/Savoir-faire sur la piste arabe.
</div>

<?php if (!$f_niveau_bar): ?>
  <div class="text-center text-muted py-5">
    <i class="bi bi-cursor" style="font-size:2.5rem;display:block;opacity:.15;margin-bottom:.5rem"></i>
    Sélectionnez un niveau pour gérer son barème.
  </div>
<?php else: ?>

<?php if (!$niveau_a_matieres_assignees): ?>
  <div class="alert alert-warning py-3 mb-3" style="font-size:.85rem">
    <i class="bi bi-exclamation-triangle me-1"></i>
    <strong>Aucune matière n'est assignée au niveau <?= h($f_niveau_bar) ?></strong> —
    il n'y a donc rien à configurer ici. Rendez-vous d'abord dans l'onglet
    <strong>« Matières par niveau »</strong> pour assigner les matières de ce niveau, puis revenez
    configurer son barème.
    <div class="mt-2">
      <a href="<?= APP_URL ?>/pages/matieres_arabe/liste.php?onglet=niveau&niveau_mn=<?= urlencode($f_niveau_bar) ?>"
         class="btn btn-sm btn-primary"><i class="bi bi-diagram-3 me-1"></i>Assigner des matières à ce niveau</a>
    </div>
  </div>
<?php else: ?>

<?php if ($mode_copie_mat): ?>
  <div class="alert alert-info py-2 mb-3 d-flex justify-content-between align-items-center flex-wrap gap-2" style="font-size:.8rem">
    <span>
      <i class="bi bi-clipboard-check me-1"></i>
      Aperçu : valeurs <strong>copiées depuis le niveau <?= h($niveau_copie_source_mat) ?></strong> —
      <strong>rien n'est encore enregistré</strong>. Ajustez si besoin puis cliquez « Enregistrer le barème »
      pour les appliquer au niveau <?= h($f_niveau_bar) ?>.
    </span>
    <a href="<?= APP_URL ?>/pages/matieres_arabe/liste.php?onglet=bareme&niveau=<?= urlencode($f_niveau_bar) ?>"
       class="btn btn-sm btn-outline-secondary">Annuler la copie</a>
  </div>
<?php endif; ?>

<?php if ($bareme_divergent_mat && !$mode_copie_mat): ?>
  <div class="alert alert-warning py-2 mb-3" style="font-size:.8rem">
    <i class="bi bi-exclamation-triangle me-1"></i>
    Les classes de ce niveau ont actuellement des barèmes <strong>différents</strong>.
    Les valeurs ci-dessous proviennent de <?= h($classes_du_niveau_bar[0]['DesignationClasses'] ?? '') ?> —
    enregistrer les <strong>harmonisera</strong> sur toutes les classes du niveau.
  </div>
<?php endif; ?>

<form method="post" id="form-bareme-ar" data-ajax-post-form>
  <?= csrf_champ() ?>
  <input type="hidden" name="code_niveau" value="<?= h($f_niveau_bar) ?>">

  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <span class="fw-bold" style="font-size:.85rem;color:#374151">
        Barème — Niveau <?= h($f_niveau_bar) ?>
        (<?= h(implode(', ', array_column($classes_du_niveau_bar, 'DesignationClasses'))) ?>) — <?= h($val_annee) ?>
      </span>
      <span id="total-general-ar" class="badge" style="background:#dcfce7;color:#166534;font-size:.75rem;padding:5px 10px;border-radius:8px" dir="rtl" lang="ar">
        <?= h(rtrim(rtrim(number_format($total_general_ar, 2, '.', ''), '0'), '.')) ?> نقطة (points)
      </span>
    </div>
    <?php if ($peut_modifier): ?>
    <div class="d-flex gap-2">
      <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-bareme-ar-tout-cocher"><i class="bi bi-check-all me-1"></i>Tout cocher</button>
      <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-bareme-ar-tout-decocher"><i class="bi bi-x-square me-1"></i>Tout décocher</button>
      <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check-lg me-1"></i>Enregistrer le barème</button>
    </div>
    <?php endif; ?>
  </div>

  <?php foreach ($bareme_par_groupe_mat as $idg => $grp): ?>
  <div class="card mb-3 bareme-ar-groupe <?= $grp['visible'] ? '' : 'bareme-ar-groupe-masque' ?>" style="border:1px solid #e5e7eb">
    <div class="card-header py-2 px-3 d-flex justify-content-between align-items-center flex-wrap gap-2"
         style="background:<?= $grp['visible'] ? '#f8faff' : '#f9fafb' ?>;border-bottom:1px solid #e5e7eb">
      <span class="fw-bold" dir="rtl" lang="ar" style="font-size:.85rem;color:#374151"><?= h($grp['libelle']) ?></span>
      <span>
        <?php if (!$grp['a_une_active']): ?>
          <span class="badge" style="background:#fef3c7;color:#92400e;font-size:.68rem">
            <i class="bi bi-eye-slash me-1"></i>Masqué — toutes les matières sont désactivées
          </span>
        <?php else: ?>
          <span class="badge bg-success-subtle text-success-emphasis" style="font-size:.68rem">
            <i class="bi bi-eye me-1"></i>Visible
          </span>
        <?php endif; ?>
      </span>
    </div>
    <div class="table-responsive">
      <table class="table table-sm table-hover mb-0" style="font-size:.78rem">
        <thead style="background:#fbfbfd">
          <tr>
            <th style="width:60px" class="text-center">Active</th>
            <th>المادة</th>
            <th style="width:85px" class="text-center">شفهي (Oral)</th>
            <th style="width:95px" class="text-center">تحريري (Écrit)</th>
            <th style="width:95px" class="text-center">عملي (Pratique)</th>
            <th style="width:95px" class="text-center fw-bold">المجموع (Total)</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($grp['lignes'] as $b): $id = (int) $b['id_mat']; $mat_active = $b['actif'] === null || (int) $b['actif'] === 1; ?>
            <tr class="bareme-ar-ligne <?= $mat_active ? '' : 'bareme-ar-ligne-inactive' ?>" data-mat="<?= $id ?>">
              <td class="text-center">
                <?php if ($peut_modifier): ?>
                  <input type="checkbox" class="form-check-input bareme-ar-actif" name="bareme[<?= $id ?>][actif]" value="1"
                         data-mat="<?= $id ?>" <?= $mat_active ? 'checked' : '' ?>>
                <?php else: ?>
                  <?= $mat_active ? '<i class="bi bi-check-lg text-success"></i>' : '<i class="bi bi-x-lg text-muted"></i>' ?>
                <?php endif; ?>
              </td>
              <td class="fw-bold" dir="rtl" lang="ar">
                <?= h($b['matiere_ar'] ?: $b['matiere_fr']) ?>
                <?php if ($b['matiere_fr'] && $b['matiere_fr'] !== $b['matiere_ar']): ?>
                  <span class="text-muted fw-normal" dir="ltr" style="font-size:.8em;display:block"><?= h($b['matiere_fr']) ?></span>
                <?php endif; ?>
              </td>
              <?php foreach (['orale', 'ecrite', 'pratique'] as $champ): ?>
                <td class="text-center">
                  <?php if ($peut_modifier): ?>
                    <input type="number" step="0.5" min="0" class="form-control form-control-sm text-center bareme-ar-input"
                           name="bareme[<?= $id ?>][<?= $champ ?>]" data-mat="<?= $id ?>"
                           value="<?= $b[$champ] !== null ? h((string) (float) $b[$champ]) : 0 ?>">
                  <?php else: ?>
                    <?= $b[$champ] !== null ? h((string) (float) $b[$champ]) : '—' ?>
                  <?php endif; ?>
                </td>
              <?php endforeach; ?>
              <td class="text-center fw-bold" id="total-ar-<?= $id ?>"><?= $b['total_points'] !== null ? h((string) (float) $b['total_points']) : '0' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endforeach; ?>
</form>

<style>
.bareme-ar-ligne-inactive { opacity: .45; }
.bareme-ar-groupe-masque .card-header { opacity: .75; }
</style>

<?php if ($peut_modifier): ?>
<script>
// Total général du niveau — somme du « Total » des lignes actives,
// recalculée à chaque frappe/coche (valeur initiale déjà fournie par PHP).
function recalculerTotalGeneralAr() {
  let total = 0;
  document.querySelectorAll('tr.bareme-ar-ligne').forEach(tr => {
    if (tr.classList.contains('bareme-ar-ligne-inactive')) return;
    tr.querySelectorAll('.bareme-ar-input').forEach(i => total += parseFloat(i.value) || 0);
  });
  const el = document.getElementById('total-general-ar');
  if (el) el.textContent = (Math.round(total * 100) / 100) + ' نقطة (points)';
}
document.querySelectorAll('.bareme-ar-input').forEach(inp => {
  inp.addEventListener('input', () => {
    const id = inp.dataset.mat;
    let total = 0;
    document.querySelectorAll(`.bareme-ar-input[data-mat="${id}"]`).forEach(i => total += parseFloat(i.value) || 0);
    document.getElementById('total-ar-' + id).textContent = total;
    recalculerTotalGeneralAr();
  });
});
document.querySelectorAll('.bareme-ar-actif').forEach(chk => {
  chk.addEventListener('change', () => {
    const tr = document.querySelector(`tr.bareme-ar-ligne[data-mat="${chk.dataset.mat}"]`);
    if (tr) tr.classList.toggle('bareme-ar-ligne-inactive', !chk.checked);
    recalculerTotalGeneralAr();
  });
});
function bareme_ar_toutBasculer(coche) {
  document.querySelectorAll('.bareme-ar-actif').forEach(chk => {
    chk.checked = coche;
    chk.dispatchEvent(new Event('change'));
  });
}
document.getElementById('btn-bareme-ar-tout-cocher')?.addEventListener('click', () => bareme_ar_toutBasculer(true));
document.getElementById('btn-bareme-ar-tout-decocher')?.addEventListener('click', () => bareme_ar_toutBasculer(false));
</script>
<?php endif; ?>

<?php endif; // niveau_a_matieres_assignees ?>
<?php endif; // f_niveau_bar ?>

<?php endif; // onglets ?>

</div><!-- /#matieres-ar-zone -->
<?php if ($es_partiel) exit; // rien de plus dans une réponse AJAX partielle. ?>

<?php
$ajax_zone_id = 'matieres-ar-zone'; // voir layout/footer.php — initAjaxZone() y est appelé
require_once __DIR__ . '/../../layout/footer.php';
