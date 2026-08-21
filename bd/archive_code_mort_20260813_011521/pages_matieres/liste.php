<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_role(['ADMIN','PROVISEUR','CENSEUR']);

$onglet    = $_GET['onglet'] ?? 'catalogue';
$id_cl_aff = (int)($_GET['classe_aff'] ?? 0);
$id_cl_ens = (int)($_GET['classe_ens'] ?? 0);

$classes = db_all(
    "SELECT c.*, n.libelle_niv
     FROM classe c LEFT JOIN niveau n ON n.code_niveau=c.code_niveau
     WHERE c.archivee=0 ORDER BY c.libelle_section, c.ordre, c.designation"
);

$annee     = get_annee_active();
$val_annee = $annee['libelle'] ?: '2025/2026';

// ══════════════════════════════════════════════════════════════
//  ONGLET 1 — Catalogue global des matières
// ══════════════════════════════════════════════════════════════
$pp_cat      = 25;
$page        = max(1, (int)($_GET['page'] ?? 1));
$q           = trim($_GET['q'] ?? '');
$matieres    = [];
$total       = 0;
$total_pages = 1;
$offset      = 0;

if ($onglet === 'catalogue') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_verifier();
        if (post('action') === 'save_mat') {
            $mid    = (int)post('mat_id');
            $lib    = post('libelle');
            $lib_en = post('libelle_en');
            $code   = post('code');
            $ord    = (int)post('ordre') ?: 1;
            if ($lib) {
                if ($mid) {
                    db_exec("UPDATE matiere SET libelle=?,libelle_en=?,code=?,ordre=? WHERE id=?",
                            [$lib,$lib_en?:null,$code?:null,$ord,$mid]);
                    flash_set('succes', 'Matière mise à jour.');
                } else {
                    db_exec("INSERT INTO matiere (libelle,libelle_en,code,ordre) VALUES (?,?,?,?)",
                            [$lib,$lib_en?:null,$code?:null,$ord]);
                    flash_set('succes', 'Matière ajoutée.');
                }
            }
            rediriger('pages/matieres/liste.php?onglet=catalogue' . ($q ? '&q='.urlencode($q) : ''));
        }
    }
    $like        = '%' . $q . '%';
    $total       = (int)db_val(
        "SELECT COUNT(*) FROM matiere WHERE libelle LIKE ? OR libelle_en LIKE ? OR code LIKE ?",
        [$like,$like,$like]);
    $offset      = ($page - 1) * $pp_cat;
    $total_pages = max(1, (int)ceil($total / $pp_cat));
    $matieres    = db_all(
        "SELECT * FROM matiere WHERE libelle LIKE ? OR libelle_en LIKE ? OR code LIKE ?
         ORDER BY ordre, libelle LIMIT $pp_cat OFFSET $offset",
        [$like,$like,$like]);
}

// ══════════════════════════════════════════════════════════════
//  ONGLET 2 — Matières par classe
// ══════════════════════════════════════════════════════════════
$disciplines    = [];
$total_coeff    = 0;
$all_matieres   = [];
$groupes        = [];
$groupes_filtre = [];
$assigned_ids   = [];
$classe_info    = null;

if ($onglet === 'par_classe') {
    $groupes      = db_all("SELECT * FROM groupe ORDER BY id_groupe_comp");
    $all_matieres = db_all("SELECT * FROM matiere ORDER BY libelle");

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_verifier();
        $action = post('action');

        if ($action === 'add_disc' && $id_cl_aff) {
            $selected = $_POST['mats'] ?? [];
            $added = 0;
            foreach ($selected as $id_mat) {
                $id_mat    = (int)$id_mat;
                $id_groupe = (int)($_POST['groupe'][$id_mat] ?? 0);
                $coef      = (int)($_POST['coef'][$id_mat] ?? 1);
                $ordre     = (int)($_POST['ordre'][$id_mat] ?? 1);
                if ($id_mat && $id_groupe) {
                    db_exec("INSERT IGNORE INTO discipline (id_mat,IDClasses,id_groupe,coef,ordre) VALUES (?,?,?,?,?)",
                            [$id_mat, $id_cl_aff, $id_groupe, $coef, (string)$ordre]);
                    $added++;
                }
            }
            flash_set('succes', "$added matière(s) affectée(s).");
            rediriger("pages/matieres/liste.php?onglet=par_classe&classe_aff=$id_cl_aff");
        }

        if ($action === 'del_disc' && $id_cl_aff) {
            $id_mat    = (int)post('id_mat');
            $id_groupe = (int)post('id_groupe');
            db_exec("DELETE FROM discipline WHERE id_mat=? AND IDClasses=? AND id_groupe=?",
                    [$id_mat, $id_cl_aff, $id_groupe]);
            db_exec("DELETE FROM dispenser WHERE id_mat=? AND IDClasses=? AND val_annee=?",
                    [$id_mat, $id_cl_aff, $val_annee]);
            flash_set('succes', 'Matière retirée.');
            rediriger("pages/matieres/liste.php?onglet=par_classe&classe_aff=$id_cl_aff");
        }

        if ($action === 'edit_disc' && $id_cl_aff) {
            $id_mat_old    = (int)post('id_mat_old');
            $id_groupe_old = (int)post('id_groupe_old');
            $id_groupe_new = (int)post('id_groupe_new');
            $coef_new      = (int)post('coef_new') ?: 1;
            $ordre_new     = (int)post('ordre_new') ?: 1;
            if ($id_groupe_new !== $id_groupe_old) {
                db_exec("DELETE FROM discipline WHERE id_mat=? AND IDClasses=? AND id_groupe=?",
                        [$id_mat_old, $id_cl_aff, $id_groupe_old]);
                db_exec("INSERT IGNORE INTO discipline (id_mat,IDClasses,id_groupe,coef,ordre) VALUES (?,?,?,?,?)",
                        [$id_mat_old, $id_cl_aff, $id_groupe_new, $coef_new, (string)$ordre_new]);
            } else {
                db_exec("UPDATE discipline SET coef=?,ordre=? WHERE id_mat=? AND IDClasses=? AND id_groupe=?",
                        [$coef_new, (string)$ordre_new, $id_mat_old, $id_cl_aff, $id_groupe_old]);
            }
            flash_set('succes', 'Discipline mise à jour.');
            rediriger("pages/matieres/liste.php?onglet=par_classe&classe_aff=$id_cl_aff");
        }
    }

    if ($id_cl_aff) {
        $classe_info    = db_one("SELECT * FROM classe WHERE id=?", [$id_cl_aff]);
        $section        = $classe_info['libelle_section'] ?? '';
        $groupes_filtre = $section
            ? db_all("SELECT * FROM groupe WHERE id_section=? ORDER BY id_groupe_comp", [$section])
            : $groupes;

        $disciplines = db_all(
            "SELECT d.id_mat, d.IDClasses, d.id_groupe, d.coef, d.ordre,
                    m.libelle AS mat_libelle,
                    g.libelle_groupe_comp AS groupe_libelle
             FROM discipline d
             JOIN matiere m ON m.id = d.id_mat
             LEFT JOIN groupe g ON g.id_groupe_comp = d.id_groupe
             WHERE d.IDClasses=?
             ORDER BY d.id_groupe, d.ordre, m.libelle",
            [$id_cl_aff]
        );
        $total_coeff  = array_sum(array_column($disciplines, 'coef'));
        $assigned_ids = array_column($disciplines, 'id_mat');
    }
}

// ══════════════════════════════════════════════════════════════
//  ONGLET 3 — Affectation des enseignants
// ══════════════════════════════════════════════════════════════
$disciplines_ens  = [];
$nb_affectes      = 0;
$total_coeff_ens  = 0;
$pp               = null;
$enseignants      = [];

if ($onglet === 'enseignants') {
    $enseignants = db_all("SELECT * FROM enseignant ORDER BY nom_ens, prenom_ens");

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_verifier();
        $action = post('action');

        if ($action === 'save_ens' && $id_cl_ens) {
            $id_mat    = (int)post('id_mat');
            $matricule = (int)post('matricule_ens');
            db_exec("DELETE FROM dispenser WHERE IDClasses=? AND id_mat=? AND val_annee=?",
                    [$id_cl_ens, $id_mat, $val_annee]);
            if ($matricule) {
                db_exec("INSERT INTO dispenser (matricule_ens,IDClasses,id_mat,val_annee) VALUES (?,?,?,?)",
                        [$matricule, $id_cl_ens, $id_mat, $val_annee]);
            }
            header('Content-Type: application/json');
            echo json_encode(['ok' => true]);
            exit;
        }

        if ($action === 'set_pp' && $id_cl_ens) {
            $matricule = (int)post('matricule_ens');
            db_exec("DELETE FROM enseignat_principal WHERE IDClasses=? AND val_annee=?",
                    [$id_cl_ens, $val_annee]);
            if ($matricule) {
                db_exec("INSERT INTO enseignat_principal (matricule_ens,IDClasses,val_annee) VALUES (?,?,?)",
                        [$matricule, $id_cl_ens, $val_annee]);
            }
            flash_set('succes', 'Professeur principal mis à jour.');
            rediriger("pages/matieres/liste.php?onglet=enseignants&classe_ens=$id_cl_ens");
        }
    }

    if ($id_cl_ens) {
        $disciplines_ens = db_all(
            "SELECT d.id_mat, d.id_groupe, d.coef, d.ordre,
                    m.libelle AS mat_libelle,
                    g.libelle_groupe_comp AS groupe_libelle,
                    MAX(disp.matricule_ens) AS matricule_ens_aff,
                    MAX(TRIM(CONCAT(e.nom_ens, COALESCE(CONCAT(' ', e.prenom_ens),''))))
                        AS enseignant_nom
             FROM discipline d
             JOIN matiere m ON m.id = d.id_mat
             LEFT JOIN groupe g ON g.id_groupe_comp = d.id_groupe
             LEFT JOIN dispenser disp ON disp.id_mat=d.id_mat AND disp.IDClasses=d.IDClasses AND disp.val_annee=?
             LEFT JOIN enseignant e ON e.matricule_ens = disp.matricule_ens
             WHERE d.IDClasses=?
             GROUP BY d.id_mat, d.IDClasses, d.id_groupe, d.coef, d.ordre, m.libelle, g.libelle_groupe_comp
             ORDER BY d.id_groupe, d.ordre, m.libelle",
            [$val_annee, $id_cl_ens]
        );
        $total_coeff_ens = array_sum(array_column($disciplines_ens, 'coef'));
        $nb_affectes     = count(array_filter($disciplines_ens, fn($d) => $d['matricule_ens_aff']));
        $pp = db_one(
            "SELECT e.matricule_ens, e.nom_ens, e.prenom_ens, e.id_fonction
             FROM enseignat_principal ep
             JOIN enseignant e ON e.matricule_ens = ep.matricule_ens
             WHERE ep.IDClasses=? AND ep.val_annee=?",
            [$id_cl_ens, $val_annee]
        );
    }
}

$titre_page = 'Matières';
require_once __DIR__ . '/../../layout/header.php';

// Couleurs par groupe
$groupe_colors = [
    'ENSEIGNEMENT GÉNÉRAL'       => ['bg'=>'#dbeafe','txt'=>'#1d4ed8','brd'=>'#93c5fd'],
    'ENSEIGNEMENT PROFESSIONNEL' => ['bg'=>'#dcfce7','txt'=>'#15803d','brd'=>'#86efac'],
    'AUTRES ENSEIGNEMENTS'       => ['bg'=>'#fef9c3','txt'=>'#a16207','brd'=>'#fde047'],
];
function grp_style(string $lib, string $part): string {
    global $groupe_colors;
    foreach ($groupe_colors as $k => $v) {
        if (stripos($lib, $k) !== false) return $v[$part];
    }
    return $part === 'bg' ? '#f3f4f6' : ($part === 'txt' ? '#374151' : '#d1d5db');
}
?>

<div class="page-titre">
  <h4><i class="bi bi-journal-bookmark me-1 text-primary"></i>Matières &amp; Disciplines</h4>
</div>

<!-- Onglets -->
<ul class="nav nav-tabs mb-3" style="border-bottom:2px solid #e5e7eb">
  <li class="nav-item">
    <a class="nav-link <?= $onglet==='catalogue'?'active':'' ?>"
       href="<?= APP_URL ?>/pages/matieres/liste.php?onglet=catalogue">
      <i class="bi bi-journal-bookmark me-1"></i>Catalogue
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $onglet==='par_classe'?'active':'' ?>"
       href="<?= APP_URL ?>/pages/matieres/liste.php?onglet=par_classe<?= $id_cl_aff?"&classe_aff=$id_cl_aff":'' ?>">
      <i class="bi bi-diagram-3 me-1"></i>Matières par classe
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $onglet==='enseignants'?'active':'' ?>"
       href="<?= APP_URL ?>/pages/matieres/liste.php?onglet=enseignants<?= $id_cl_ens?"&classe_ens=$id_cl_ens":'' ?>">
      <i class="bi bi-person-badge me-1"></i>Affectation enseignants
    </a>
  </li>
</ul>

<?php if ($onglet === 'catalogue'): ?>
<!-- ══════════════════════════════════════════════════
     ONGLET 1 — Catalogue global
══════════════════════════════════════════════════ -->
<div class="card mb-2">
  <div class="card-body py-2">
    <div class="section-titre mb-2"><i class="bi bi-plus me-1"></i>Ajouter / modifier</div>
    <form method="post" class="row g-2 align-items-end" id="form-mat">
      <?= csrf_champ() ?>
      <input type="hidden" name="action" value="save_mat">
      <input type="hidden" name="mat_id" id="f_id" value="0">
      <div class="col-md-3">
        <label class="form-label">Libellé (FR) *</label>
        <input type="text" name="libelle" id="f_lib" class="form-control" required placeholder="ex: Mathématiques">
      </div>
      <div class="col-md-3">
        <label class="form-label">Libellé (EN)</label>
        <input type="text" name="libelle_en" id="f_lib_en" class="form-control" placeholder="ex: Mathematics">
      </div>
      <div class="col-md-2">
        <label class="form-label">Code</label>
        <input type="text" name="code" id="f_code" class="form-control" placeholder="MATH">
      </div>
      <div class="col-md-1">
        <label class="form-label">Ordre</label>
        <input type="number" name="ordre" id="f_ordre" class="form-control" value="1" min="1">
      </div>
      <div class="col-auto d-flex gap-1 align-self-end">
        <button class="btn btn-primary btn-sm">
          <i class="bi bi-check-lg me-1"></i><span id="btn-label">Ajouter</span>
        </button>
        <button type="button" class="btn btn-light btn-sm d-none" id="btn-annuler" onclick="reinitForm()">
          <i class="bi bi-x-lg"></i>
        </button>
      </div>
    </form>
  </div>
</div>

<div class="card mb-2">
  <div class="card-body py-2">
    <form method="get" class="d-flex gap-2 align-items-center">
      <input type="hidden" name="onglet" value="catalogue">
      <input type="text" name="q" class="form-control form-control-sm" style="max-width:300px"
             placeholder="Rechercher..." value="<?= h($q) ?>">
      <button class="btn btn-outline-secondary btn-sm"><i class="bi bi-search"></i></button>
      <?php if ($q): ?>
        <a href="?onglet=catalogue" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-lg"></i></a>
      <?php endif; ?>
      <span class="text-muted ms-auto" style="font-size:.78rem;white-space:nowrap">
        <?= $total ?> matière(s)
      </span>
    </form>
  </div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-abz table-hover mb-0">
      <thead>
        <tr>
          <th style="width:44px">N°</th>
          <th style="width:70px">Code</th>
          <th>Libellé (FR)</th>
          <th>Libellé (EN)</th>
          <th style="width:60px" class="text-center">Ordre</th>
          <th class="text-end" style="width:90px">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($matieres)): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">Aucune matière trouvée.</td></tr>
        <?php else: $num = $offset + 1; foreach ($matieres as $m): ?>
          <tr>
            <td class="text-muted"><?= $num++ ?></td>
            <td><?= $m['code'] ? '<span class="badge-code">'.h($m['code']).'</span>' : '—' ?></td>
            <td class="fw-semibold"><?= h($m['libelle']) ?></td>
            <td style="color:#6b7280;font-size:.8rem"><?= h($m['libelle_en'] ?? '') ?: '—' ?></td>
            <td class="text-center text-muted"><?= $m['ordre'] ?></td>
            <td class="text-end">
              <button class="btn btn-sm btn-light" style="padding:3px 7px" title="Modifier"
                      onclick="editerMat(<?= $m['id'] ?>,<?= h(json_encode($m['libelle'])) ?>,<?= h(json_encode($m['libelle_en']??'')) ?>,<?= h(json_encode($m['code']??'')) ?>,<?= (int)$m['ordre'] ?>)">
                <i class="bi bi-pencil" style="font-size:.78rem"></i>
              </button>
              <a href="<?= APP_URL ?>/pages/matieres/supprimer.php?id=<?= $m['id'] ?>&csrf=<?= csrf_generer() ?>"
                 class="btn btn-sm btn-light text-danger" style="padding:3px 7px"
                 onclick="return confirm('Supprimer « <?= h(addslashes($m['libelle'])) ?> » ?')">
                <i class="bi bi-trash" style="font-size:.78rem"></i>
              </a>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php if ($total_pages > 1): ?>
  <div class="mt-2"><?= pagination_html($page, $total_pages, APP_URL.'/pages/matieres/liste.php?onglet=catalogue'.($q?'&q='.urlencode($q):'')) ?></div>
<?php endif; ?>

<script>
function editerMat(id, lib, libEn, code, ordre) {
    document.getElementById('f_id').value     = id;
    document.getElementById('f_lib').value    = lib;
    document.getElementById('f_lib_en').value = libEn;
    document.getElementById('f_code').value   = code;
    document.getElementById('f_ordre').value  = ordre;
    document.getElementById('btn-label').textContent = 'Enregistrer';
    document.getElementById('btn-annuler').classList.remove('d-none');
    document.getElementById('f_lib').focus();
    window.scrollTo({top: 0, behavior: 'smooth'});
}
function reinitForm() {
    document.getElementById('form-mat').reset();
    document.getElementById('f_id').value = '0';
    document.getElementById('btn-label').textContent = 'Ajouter';
    document.getElementById('btn-annuler').classList.add('d-none');
}
</script>

<?php elseif ($onglet === 'par_classe'): ?>
<!-- ══════════════════════════════════════════════════
     ONGLET 2 — Matières par classe
══════════════════════════════════════════════════ -->

<!-- Sélecteur de classe -->
<div class="card mb-3" style="border:none;box-shadow:none;background:transparent">
  <div class="card-body py-0 px-0">
    <form method="get" class="d-flex align-items-center gap-3 flex-wrap">
      <input type="hidden" name="onglet" value="par_classe">
      <div style="min-width:240px;max-width:360px;flex:1">
        <select name="classe_aff" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="">— Sélectionner une classe —</option>
          <?php foreach ($classes as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $id_cl_aff==$c['id']?'selected':''?>>
              <?= h($c['designation']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($id_cl_aff && $classe_info): ?>
        <div class="d-flex gap-2 align-items-center">
          <span style="background:#eef2ff;color:#3730a3;padding:3px 10px;border-radius:8px;font-size:.72rem;font-weight:600">
            <i class="bi bi-building me-1"></i>Section <?= h($classe_info['libelle_section'] ?? '—') ?>
          </span>
          <span style="background:#f0fdf4;color:#166534;padding:3px 10px;border-radius:8px;font-size:.72rem;font-weight:600">
            <i class="bi bi-layers me-1"></i><?= count($disciplines) ?> discipline(s) · Σ coef <?= $total_coeff ?>
          </span>
        </div>
      <?php endif; ?>
    </form>
  </div>
</div>

<?php if (!$id_cl_aff): ?>
  <div class="text-center text-muted py-5">
    <i class="bi bi-cursor" style="font-size:2.5rem;display:block;opacity:.15;margin-bottom:.5rem"></i>
    Sélectionnez une classe pour gérer ses disciplines.
  </div>

<?php else:
  $nom_classe = h($classe_info['designation'] ?? '');
  $section    = $classe_info['libelle_section'] ?? '';

  // Grouper les disciplines par groupe pour la vue structurée
  $disc_par_groupe = [];
  foreach ($disciplines as $d) {
      $disc_par_groupe[$d['groupe_libelle'] ?? 'Autres'][] = $d;
  }
?>

<div class="row g-2" style="align-items:flex-start">

  <!-- ══ Panneau gauche : Ajouter ══ -->
  <div class="col-xl-4 col-lg-5">
    <div class="card h-100" style="border:1px solid #c7d2fe;overflow:hidden">
      <div class="card-header py-2 px-3 d-flex align-items-center gap-2"
           style="background:linear-gradient(135deg,#eef2ff,#e0e7ff);border-bottom:1px solid #c7d2fe">
        <i class="bi bi-plus-circle-fill" style="color:#4338ca;font-size:.95rem"></i>
        <span class="fw-bold" style="font-size:.8rem;color:#312e81">Ajouter des matières</span>
        <span class="ms-auto badge" style="background:#c7d2fe;color:#3730a3;font-size:.65rem">
          <?= count($all_matieres) ?> disponibles
        </span>
      </div>
      <div class="card-body p-0">
        <form method="post" id="form-disc">
          <?= csrf_champ() ?>
          <input type="hidden" name="action" value="add_disc">

          <!-- Filtre rapide -->
          <div class="px-2 pt-2 pb-1" style="background:#f8faff;border-bottom:1px solid #e5e7eb">
            <div class="d-flex align-items-center gap-1" style="background:#fff;border:1px solid #dde3f0;border-radius:7px;padding:3px 8px">
              <i class="bi bi-search" style="color:#9ca3af;font-size:.72rem"></i>
              <input type="text" id="filtre-mat" class="form-control form-control-sm border-0 p-0 shadow-none"
                     style="font-size:.78rem" placeholder="Filtrer les matières...">
            </div>
          </div>

          <!-- En-têtes colonnes -->
          <div style="display:grid;grid-template-columns:20px 1fr 36px 36px 1fr;gap:3px;
                      padding:4px 8px;font-size:.62rem;font-weight:700;color:#6b7280;
                      background:#f8faff;border-bottom:1px solid #e9ecef;text-transform:uppercase;letter-spacing:.04em">
            <div></div>
            <div>Matière</div>
            <div class="text-center">Cf</div>
            <div class="text-center">Ord</div>
            <div class="text-center">Groupe</div>
          </div>

          <div style="max-height:420px;overflow-y:auto">
            <?php foreach ($all_matieres as $m):
              $deja = in_array($m['id'], $assigned_ids);
            ?>
            <div class="mat-row" data-lib="<?= strtolower(h($m['libelle'])) ?>"
                 style="display:grid;grid-template-columns:20px 1fr 36px 36px 1fr;gap:3px;
                        align-items:center;padding:4px 8px;border-bottom:1px solid #f3f4f6;
                        background:<?= $deja?'#f8fffe':'#fff' ?>">
              <div style="text-align:center">
                <?php if ($deja): ?>
                  <i class="bi bi-check-circle-fill" style="color:#10b981;font-size:.78rem"></i>
                <?php else: ?>
                  <input type="checkbox" name="mats[]" value="<?= $m['id'] ?>"
                         class="form-check-input mat-cb" style="width:13px;height:13px;margin:0;cursor:pointer">
                <?php endif; ?>
              </div>
              <div style="font-size:.75rem;font-weight:<?= $deja?'400':'500' ?>;
                          color:<?= $deja?'#9ca3af':'#111827' ?>;
                          white-space:nowrap;overflow:hidden;text-overflow:ellipsis"
                   title="<?= h($m['libelle']) ?>">
                <?= h($m['libelle']) ?>
              </div>
              <?php if (!$deja): ?>
              <div>
                <select name="coef[<?= $m['id'] ?>]" class="form-select form-select-sm px-1 text-center"
                        style="font-size:.68rem;padding:1px 2px">
                  <?php for ($c=1;$c<=10;$c++): ?><option value="<?= $c ?>"><?= $c ?></option><?php endfor; ?>
                </select>
              </div>
              <div>
                <select name="ordre[<?= $m['id'] ?>]" class="form-select form-select-sm px-1 text-center"
                        style="font-size:.68rem;padding:1px 2px">
                  <?php for ($o=1;$o<=30;$o++): ?><option value="<?= $o ?>"><?= $o ?></option><?php endfor; ?>
                </select>
              </div>
              <div>
                <select name="groupe[<?= $m['id'] ?>]" class="form-select form-select-sm px-1"
                        style="font-size:.65rem;padding:1px 2px">
                  <option value="">Groupe</option>
                  <?php foreach ($groupes_filtre as $g): ?>
                    <option value="<?= $g['id_groupe_comp'] ?>">
                      <?= h($g['libelle_groupe_comp']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <?php else: ?>
              <div colspan="3" style="color:#10b981;font-size:.65rem;text-align:center;grid-column:3/6">
                <i class="bi bi-check2-all"></i> affectée
              </div>
              <?php endif; ?>
            </div>
            <?php endforeach; ?>
          </div>

          <div class="d-flex align-items-center gap-2 px-2 py-2 border-top" style="background:#f8faff">
            <button type="submit" class="btn btn-primary btn-sm px-3">
              <i class="bi bi-save me-1"></i>Affecter
            </button>
            <label style="display:flex;align-items:center;gap:4px;font-size:.72rem;cursor:pointer;margin:0;color:#374151">
              <input type="checkbox" id="chk-all" class="form-check-input" style="width:13px;height:13px;margin:0">
              Tout
            </label>
            <span id="sel-count" class="text-muted ms-auto" style="font-size:.7rem">0 sél.</span>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- ══ Panneau droit : Disciplines ══ -->
  <div class="col-xl-8 col-lg-7">
    <div class="card h-100" style="border:1px solid #c7d2fe;overflow:hidden">
      <div class="card-header py-2 px-3 d-flex align-items-center gap-2"
           style="background:linear-gradient(135deg,#eef2ff,#e0e7ff);border-bottom:1px solid #c7d2fe">
        <i class="bi bi-journal-check" style="color:#4338ca;font-size:.95rem"></i>
        <span class="fw-bold" style="font-size:.8rem;color:#312e81">
          Disciplines &mdash; <?= $nom_classe ?>
        </span>
        <span class="ms-auto badge" style="background:#c7d2fe;color:#3730a3;font-size:.65rem">
          <?= count($disciplines) ?> mat. &nbsp;|&nbsp; Σ coef <strong><?= $total_coeff ?></strong>
        </span>
      </div>
      <div class="card-body p-0">
        <?php if (empty($disciplines)): ?>
          <div class="text-center text-muted py-5" style="font-size:.82rem">
            <i class="bi bi-inbox" style="font-size:2.2rem;display:block;opacity:.15;margin-bottom:.4rem"></i>
            Aucune discipline affectée à cette classe.
          </div>
        <?php else: ?>
        <div style="max-height:520px;overflow-y:auto">
          <?php foreach ($disc_par_groupe as $grp_lib => $disc_grp):
            $bg  = grp_style($grp_lib, 'bg');
            $txt = grp_style($grp_lib, 'txt');
            $brd = grp_style($grp_lib, 'brd');
            $sc  = array_sum(array_column($disc_grp, 'coef'));
          ?>
          <!-- En-tête groupe -->
          <div style="display:flex;align-items:center;gap:8px;padding:5px 12px;
                      background:<?= $bg ?>;border-bottom:1px solid <?= $brd ?>;
                      position:sticky;top:0;z-index:2">
            <span style="width:7px;height:7px;border-radius:50%;background:<?= $txt ?>;flex-shrink:0"></span>
            <span style="font-size:.68rem;font-weight:700;color:<?= $txt ?>;text-transform:uppercase;letter-spacing:.06em;flex:1">
              <?= h($grp_lib) ?>
            </span>
            <span style="font-size:.65rem;color:<?= $txt ?>;opacity:.8">
              <?= count($disc_grp) ?> mat. · coef <?= $sc ?>
            </span>
          </div>
          <!-- Lignes disciplines -->
          <table style="width:100%;border-collapse:collapse">
            <tbody>
              <?php foreach ($disc_grp as $i => $d): ?>
              <tr class="disc-row" style="border-bottom:1px solid #f3f4f6"
                  onmouseover="this.style.background='#f8faff'" onmouseout="this.style.background=''">
                <td style="width:28px;padding:5px 4px 5px 12px;color:#d1d5db;font-size:.7rem;text-align:right;white-space:nowrap">
                  <?= $i+1 ?>
                </td>
                <td style="padding:5px 6px;font-size:.8rem;font-weight:600;color:#111827;max-width:0;width:99%">
                  <div style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="<?= h($d['mat_libelle']) ?>">
                    <?= h($d['mat_libelle']) ?>
                  </div>
                </td>
                <td style="width:40px;padding:5px 4px;text-align:center">
                  <span style="display:inline-block;min-width:24px;padding:1px 5px;border-radius:6px;
                               background:#dbeafe;color:#1e40af;font-size:.72rem;font-weight:700">
                    <?= $d['coef'] ?>
                  </span>
                </td>
                <td style="width:34px;padding:5px 4px;text-align:center;color:#9ca3af;font-size:.7rem">
                  <?= (int)$d['ordre'] ?>
                </td>
                <td style="width:38px;padding:4px;text-align:center">
                  <div class="dropdown">
                    <button class="btn btn-sm btn-light" style="padding:2px 6px;border:1px solid #e5e7eb;line-height:1"
                            data-bs-toggle="dropdown" title="Actions">
                      <i class="bi bi-three-dots-vertical" style="font-size:.72rem"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="font-size:.78rem;min-width:130px">
                      <li>
                        <a class="dropdown-item d-flex align-items-center gap-2 py-1" href="#"
                           onclick="ouvrirEditDisc(<?= $d['id_mat'] ?>,<?= $d['id_groupe'] ?>,<?= $d['coef'] ?>,<?= (int)$d['ordre'] ?>,<?= h(json_encode($d['mat_libelle'])) ?>); return false">
                          <i class="bi bi-pencil" style="color:#2563eb;width:14px"></i>Modifier
                        </a>
                      </li>
                      <li><hr class="dropdown-divider my-1"></li>
                      <li>
                        <a class="dropdown-item d-flex align-items-center gap-2 py-1 text-danger" href="#"
                           onclick="supprimerDisc(<?= $d['id_mat'] ?>,<?= $d['id_groupe'] ?>,<?= h(json_encode($d['mat_libelle'])) ?>); return false">
                          <i class="bi bi-trash" style="width:14px"></i>Retirer
                        </a>
                      </li>
                    </ul>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php endforeach; ?>

          <!-- Pied total -->
          <div style="display:flex;align-items:center;justify-content:flex-end;gap:8px;
                      padding:6px 12px;background:#eef2ff;border-top:2px solid #c7d2fe;
                      position:sticky;bottom:0">
            <span style="font-size:.72rem;color:#374151;font-weight:600;text-transform:uppercase;letter-spacing:.04em">
              Total coefficients
            </span>
            <span style="background:#3730a3;color:#fff;padding:2px 12px;border-radius:10px;font-size:.88rem;font-weight:700">
              <?= $total_coeff ?>
            </span>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div><!-- /row -->

<!-- Formulaire caché supprimer (via JS) -->
<form method="post" id="form-del" style="display:none">
  <?= csrf_champ() ?>
  <input type="hidden" name="action" value="del_disc">
  <input type="hidden" name="id_mat"    id="del_id_mat">
  <input type="hidden" name="id_groupe" id="del_id_groupe">
</form>

<!-- Modal Modifier discipline -->
<div class="modal fade" id="modalEditDisc" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content" style="border-radius:14px;overflow:hidden">
      <div class="modal-header py-2" style="background:linear-gradient(135deg,#eef2ff,#e0e7ff);border-bottom:1px solid #c7d2fe">
        <h6 class="modal-title fw-bold d-flex align-items-center gap-2" style="font-size:.85rem;color:#312e81">
          <i class="bi bi-pencil-square text-primary"></i>Modifier la discipline
        </h6>
        <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal"></button>
      </div>
      <form method="post">
        <?= csrf_champ() ?>
        <input type="hidden" name="action" value="edit_disc">
        <input type="hidden" name="id_mat_old"    id="edit_id_mat">
        <input type="hidden" name="id_groupe_old" id="edit_id_groupe_old">
        <div class="modal-body py-3">
          <div class="mb-3 p-2 rounded" style="background:#f8faff;border:1px solid #e0e7ff">
            <span style="font-size:.72rem;color:#6b7280;display:block">Matière</span>
            <span class="fw-bold" id="edit_mat_nom" style="font-size:.88rem;color:#1e40af"></span>
          </div>
          <div class="row g-2">
            <div class="col-6">
              <label class="form-label" style="font-size:.78rem">Coefficient</label>
              <select name="coef_new" id="edit_coef" class="form-select form-select-sm">
                <?php for ($c=1;$c<=10;$c++): ?>
                  <option value="<?= $c ?>"><?= $c ?></option>
                <?php endfor; ?>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label" style="font-size:.78rem">Ordre</label>
              <select name="ordre_new" id="edit_ordre" class="form-select form-select-sm">
                <?php for ($o=1;$o<=30;$o++): ?>
                  <option value="<?= $o ?>"><?= $o ?></option>
                <?php endfor; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label" style="font-size:.78rem">Groupe</label>
              <select name="id_groupe_new" id="edit_groupe" class="form-select form-select-sm">
                <?php foreach ($groupes_filtre ?: $groupes as $g): ?>
                  <option value="<?= $g['id_groupe_comp'] ?>">
                    <?= h($g['libelle_groupe_comp']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer py-2" style="border-top:1px solid #e5e7eb">
          <button class="btn btn-primary btn-sm px-4">
            <i class="bi bi-check-lg me-1"></i>Enregistrer
          </button>
          <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Annuler</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// Filtre liste matières
document.getElementById('filtre-mat').addEventListener('input', function() {
    var q = this.value.toLowerCase();
    document.querySelectorAll('.mat-row').forEach(function(row) {
        row.style.display = (row.dataset.lib || '').includes(q) ? '' : 'none';
    });
});
// Sélectionner tout
document.getElementById('chk-all').addEventListener('change', function() {
    document.querySelectorAll('.mat-cb').forEach(function(cb) { cb.checked = this.checked; }, this);
    majCount();
});
function majCount() {
    var n = document.querySelectorAll('.mat-cb:checked').length;
    document.getElementById('sel-count').textContent = n + ' sélectionnée(s)';
}
document.querySelectorAll('.mat-cb').forEach(function(cb) { cb.addEventListener('change', majCount); });

// Supprimer discipline
function supprimerDisc(idMat, idGroupe, nom) {
    if (!confirm('Retirer « ' + nom + ' » de cette classe ?')) return;
    document.getElementById('del_id_mat').value    = idMat;
    document.getElementById('del_id_groupe').value = idGroupe;
    document.getElementById('form-del').submit();
}
// Ouvrir modal édition discipline
function ouvrirEditDisc(idMat, idGroupe, coef, ordre, nom) {
    document.getElementById('edit_id_mat').value       = idMat;
    document.getElementById('edit_id_groupe_old').value = idGroupe;
    document.getElementById('edit_coef').value         = coef;
    document.getElementById('edit_ordre').value        = ordre;
    document.getElementById('edit_groupe').value       = idGroupe;
    document.getElementById('edit_mat_nom').textContent = nom;
    new bootstrap.Modal(document.getElementById('modalEditDisc')).show();
}
</script>

<?php endif; // id_cl_aff ?>

<?php elseif ($onglet === 'enseignants'): ?>
<!-- ══════════════════════════════════════════════════
     ONGLET 3 — Affectation des enseignants
══════════════════════════════════════════════════ -->

<!-- Sélecteur de classe -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end">
      <input type="hidden" name="onglet" value="enseignants">
      <div class="col-md-5">
        <label class="form-label fw-semibold">Classe :</label>
        <select name="classe_ens" class="form-select" onchange="this.form.submit()">
          <option value="">— Choisir une classe —</option>
          <?php foreach ($classes as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $id_cl_ens==$c['id']?'selected':''?>>
              <?= h($c['designation']) ?>
              <?= $c['libelle_niv'] ? ' ('.$c['libelle_niv'].')' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </form>
  </div>
</div>

<?php if (!$id_cl_ens): ?>
  <div class="alert alert-light text-center text-muted py-5">
    <i class="bi bi-arrow-up" style="font-size:2rem;display:block;opacity:.2;margin-bottom:.5rem"></i>
    Sélectionnez une classe pour gérer les affectations.
  </div>

<?php else:
  $nom_cl_ens  = h(db_val("SELECT designation FROM classe WHERE id=?", [$id_cl_ens]));
  $nb_total    = count($disciplines_ens);
  $pct         = $nb_total > 0 ? round($nb_affectes * 100 / $nb_total) : 0;

  // Grouper par groupe
  $par_groupe = [];
  foreach ($disciplines_ens as $d) {
      $grp = $d['groupe_libelle'] ?? 'Autres';
      $par_groupe[$grp][] = $d;
  }
?>

<!-- ── Barre de synthèse ──────────────────────────────────── -->
<div class="row g-2 mb-3">

  <!-- Prof principal -->
  <div class="col-md-5">
    <div class="card" style="border-left:4px solid #1e4fd8">
      <div class="card-body py-2 px-3">
        <div class="d-flex align-items-center gap-3">
          <div style="width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,#1e4fd8,#7a4dff);
                      display:flex;align-items:center;justify-content:center;flex-shrink:0">
            <i class="bi bi-person-badge-fill" style="color:#fff;font-size:1.1rem"></i>
          </div>
          <div style="flex:1;min-width:0">
            <div style="font-size:.7rem;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.05em">
              Professeur Principal
            </div>
            <div class="fw-bold" style="font-size:.88rem;color:#111827;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
              <?php if ($pp): ?>
                <?= h(trim($pp['nom_ens'].' '.($pp['prenom_ens']??''))) ?>
              <?php else: ?>
                <span class="text-muted" style="font-weight:400">Non défini</span>
              <?php endif; ?>
            </div>
          </div>
          <button class="btn btn-outline-primary btn-sm" style="flex-shrink:0;font-size:.75rem"
                  data-bs-toggle="modal" data-bs-target="#modalPP">
            <i class="bi bi-pencil"></i>
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Taux d'affectation -->
  <div class="col-md-4">
    <div class="card" style="border-left:4px solid <?= $pct>=80?'#15803d':($pct>=50?'#d97706':'#dc2626') ?>">
      <div class="card-body py-2 px-3">
        <div style="font-size:.7rem;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px">
          Taux d'affectation
        </div>
        <div class="d-flex align-items-center gap-2">
          <div style="flex:1">
            <div style="height:8px;background:#e5e7eb;border-radius:4px;overflow:hidden">
              <div style="height:100%;width:<?= $pct ?>%;border-radius:4px;
                          background:<?= $pct>=80?'#22c55e':($pct>=50?'#f59e0b':'#ef4444') ?>;
                          transition:width .5s"></div>
            </div>
          </div>
          <div class="fw-bold" style="font-size:.88rem;color:#111827;white-space:nowrap">
            <?= $nb_affectes ?>/<?= $nb_total ?>
          </div>
        </div>
        <div style="font-size:.7rem;color:#6b7280;margin-top:2px"><?= $pct ?>% affectées</div>
      </div>
    </div>
  </div>

  <!-- Total coefficients -->
  <div class="col-md-3">
    <div class="card" style="border-left:4px solid #7c3aed">
      <div class="card-body py-2 px-3">
        <div style="font-size:.7rem;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.05em">
          Total coefficients
        </div>
        <div class="fw-bold" style="font-size:1.5rem;color:#7c3aed;line-height:1.2">
          <?= $total_coeff_ens ?>
        </div>
        <div style="font-size:.7rem;color:#6b7280"><?= $nb_total ?> discipline(s)</div>
      </div>
    </div>
  </div>

</div>

<!-- Barre de recherche enseignant (réutilisée dans chaque select) -->
<div class="card mb-3">
  <div class="card-body py-2 d-flex align-items-center gap-2">
    <i class="bi bi-search text-muted" style="font-size:.85rem"></i>
    <input type="text" id="filtre-ens" class="form-control form-control-sm" style="max-width:300px"
           placeholder="Filtrer les enseignants dans les listes...">
    <span class="text-muted ms-auto" style="font-size:.75rem">
      Les modifications sont sauvegardées automatiquement
      <i class="bi bi-lightning-charge-fill text-warning ms-1"></i>
    </span>
  </div>
</div>

<!-- ── Disciplines groupées par groupe ────────────────────── -->
<?php if (empty($par_groupe)): ?>
  <div class="alert alert-info py-2 d-flex align-items-center gap-2">
    <i class="bi bi-info-circle-fill"></i>
    Aucune discipline affectée à cette classe.
    <a href="?onglet=par_classe&classe_aff=<?= $id_cl_ens ?>" class="alert-link ms-1">
      Affecter des matières d'abord →
    </a>
  </div>
<?php else:
  $num_global = 1;
  foreach ($par_groupe as $grp_lib => $grp_discs):
    $nb_aff_grp = count(array_filter($grp_discs, fn($d) => $d['matricule_ens_aff']));
    $coef_grp   = array_sum(array_column($grp_discs, 'coef'));
    $bg   = grp_style($grp_lib, 'bg');
    $txt  = grp_style($grp_lib, 'txt');
    $brd  = grp_style($grp_lib, 'brd');
?>
<div class="card mb-3" style="border:1px solid <?= $brd ?>;border-top:3px solid <?= $txt ?>">
  <!-- En-tête groupe -->
  <div class="card-header py-2 px-3 d-flex align-items-center justify-content-between"
       style="background:<?= $bg ?>;border-bottom:1px solid <?= $brd ?>">
    <span class="fw-bold" style="font-size:.88rem;color:<?= $txt ?>">
      <?= h($grp_lib) ?>
    </span>
    <div class="d-flex gap-2 align-items-center">
      <span style="font-size:.72rem;color:<?= $txt ?>;background:rgba(255,255,255,.6);
                   padding:2px 8px;border-radius:10px;border:1px solid <?= $brd ?>">
        <?= count($grp_discs) ?> matière(s) · Coef <?= $coef_grp ?>
      </span>
      <span style="font-size:.72rem;padding:2px 8px;border-radius:10px;
                   background:<?= $nb_aff_grp===count($grp_discs)?'#dcfce7':'#fef3c7' ?>;
                   color:<?= $nb_aff_grp===count($grp_discs)?'#15803d':'#92400e' ?>;
                   border:1px solid <?= $nb_aff_grp===count($grp_discs)?'#86efac':'#fde68a' ?>">
        <?= $nb_aff_grp ?>/<?= count($grp_discs) ?> affectées
      </span>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0" style="font-size:.8rem">
      <thead style="background:#fafafa">
        <tr>
          <th style="padding:5px 10px;width:36px;font-size:.7rem;color:#9ca3af">N°</th>
          <th style="padding:5px 10px;font-size:.7rem;color:#9ca3af">Matière</th>
          <th style="padding:5px 10px;width:48px;text-align:center;font-size:.7rem;color:#9ca3af">Coef</th>
          <th style="padding:5px 10px;font-size:.7rem;color:#9ca3af">Enseignant assigné</th>
          <th style="padding:5px 10px;width:32px;text-align:center;font-size:.7rem;color:#9ca3af">
            <i class="bi bi-check2" title="Statut"></i>
          </th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($grp_discs as $d): $assigned = (bool)$d['matricule_ens_aff']; ?>
        <tr class="<?= $assigned ? '' : 'table-warning bg-opacity-25' ?>">
          <td style="padding:6px 10px;color:#9ca3af"><?= $num_global++ ?></td>
          <td style="padding:6px 10px;font-weight:600;color:#111827"><?= h($d['mat_libelle']) ?></td>
          <td style="padding:6px 10px;text-align:center">
            <span style="background:<?= $txt ?>;color:#fff;font-size:.7rem;padding:2px 7px;
                         border-radius:10px;font-weight:700"><?= $d['coef'] ?></span>
          </td>
          <td style="padding:4px 10px">
            <select class="form-select form-select-sm ens-select"
                    style="max-width:320px;font-size:.78rem;border-color:<?= $assigned?'#86efac':'#fde68a' ?>"
                    data-id-mat="<?= $d['id_mat'] ?>">
              <option value="">— Non affecté —</option>
              <?php foreach ($enseignants as $e): ?>
                <option value="<?= $e['matricule_ens'] ?>"
                        data-nom="<?= strtolower(h($e['nom_ens'].' '.($e['prenom_ens']??''))) ?>"
                        <?= ($d['matricule_ens_aff']==$e['matricule_ens'])?'selected':'' ?>>
                  <?= h(trim($e['nom_ens'].' '.($e['prenom_ens']??''))) ?>
                  <?php if ($e['id_fonction'] && $e['id_fonction']!='ENSEIGNANT'): ?>
                    (<?= h($e['id_fonction']) ?>)
                  <?php endif; ?>
                </option>
              <?php endforeach; ?>
            </select>
          </td>
          <td style="padding:6px 10px;text-align:center">
            <?php if ($assigned): ?>
              <i class="bi bi-check-circle-fill text-success" style="font-size:.9rem"
                 title="<?= h($d['enseignant_nom']) ?>"></i>
            <?php else: ?>
              <i class="bi bi-circle text-warning" style="font-size:.9rem" title="Non affecté"></i>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endforeach; endif; ?>

<input type="hidden" id="csrf_token" value="<?= csrf_generer() ?>">

<!-- Modal Professeur Principal -->
<div class="modal fade" id="modalPP" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title fw-bold">
          <i class="bi bi-person-badge-fill me-1 text-primary"></i>Professeur Principal — <?= $nom_cl_ens ?>
        </h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post">
        <?= csrf_champ() ?>
        <input type="hidden" name="action" value="set_pp">
        <div class="modal-body">
          <?php if ($pp): ?>
            <div class="alert alert-info py-2 mb-3" style="font-size:.82rem">
              <i class="bi bi-person-check me-1"></i>
              Actuellement : <strong><?= h(trim($pp['nom_ens'].' '.($pp['prenom_ens']??''))) ?></strong>
            </div>
          <?php endif; ?>
          <label class="form-label fw-semibold">Choisir l'enseignant</label>
          <input type="text" id="pp-search" class="form-control form-control-sm mb-2"
                 placeholder="Rechercher un enseignant...">
          <select name="matricule_ens" id="pp-select" class="form-select" size="8"
                  style="font-size:.82rem">
            <option value="">— Aucun —</option>
            <?php foreach ($enseignants as $e):
              $nom_e = trim($e['nom_ens'].' '.($e['prenom_ens']??''));
            ?>
              <option value="<?= $e['matricule_ens'] ?>"
                      data-nom="<?= strtolower(h($nom_e)) ?>"
                      <?= ($pp && $pp['matricule_ens']==$e['matricule_ens'])?'selected':'' ?>>
                <?= h($nom_e) ?>
                <?php if ($e['id_fonction'] && $e['id_fonction']!='ENSEIGNANT'): ?>
                  (<?= h($e['id_fonction']) ?>)
                <?php endif; ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="modal-footer py-2">
          <button class="btn btn-primary btn-sm">
            <i class="bi bi-check-lg me-1"></i>Enregistrer
          </button>
          <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Annuler</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// AJAX save enseignant
document.querySelectorAll('.ens-select').forEach(function(sel) {
    sel.addEventListener('change', function() {
        var fd = new FormData();
        fd.append('action', 'save_ens');
        fd.append('id_mat', this.dataset.idMat);
        fd.append('matricule_ens', this.value);
        fd.append('csrf', document.getElementById('csrf_token').value);
        var row = this.closest('tr');
        var icon = row.querySelector('td:last-child i');
        fetch('', {method:'POST', body: fd})
            .then(function(r){ return r.json(); })
            .then(function(data){
                if (data.ok) {
                    // Mise à jour icône statut
                    var assigned = sel.value !== '';
                    if (icon) {
                        icon.className = assigned
                            ? 'bi bi-check-circle-fill text-success'
                            : 'bi bi-circle text-warning';
                        icon.style.fontSize = '.9rem';
                        icon.title = assigned ? sel.options[sel.selectedIndex].text : 'Non affecté';
                    }
                    sel.style.borderColor = assigned ? '#86efac' : '#fde68a';
                    row.classList.toggle('table-warning', !assigned);
                    // Flash vert
                    row.style.transition = 'background .25s';
                    row.style.background = '#d1fae5';
                    setTimeout(function(){ row.style.background = ''; }, 1200);
                }
            });
    });
});

// Filtre enseignants dans les selects
document.getElementById('filtre-ens').addEventListener('input', function(){
    var q = this.value.toLowerCase();
    document.querySelectorAll('.ens-select option').forEach(function(opt){
        if (!opt.value) return;
        opt.hidden = !(opt.dataset.nom || '').includes(q);
    });
});

// Recherche dans le modal PP
document.getElementById('pp-search').addEventListener('input', function(){
    var q = this.value.toLowerCase();
    document.querySelectorAll('#pp-select option').forEach(function(opt){
        if (!opt.value) return;
        opt.hidden = !(opt.dataset.nom || '').includes(q);
    });
});
</script>

<?php endif; // id_cl_ens ?>

<?php endif; // onglets ?>

<?php require_once __DIR__ . '/../../layout/footer.php'; ?>
