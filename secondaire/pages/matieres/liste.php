<?php
// secondaire/pages/matieres/liste.php — module Matières (école secondaire),
// porté fidèlement depuis LAM_ABZ/pages/matieres/liste.php : catalogue,
// affectation par classe (groupes de compétence Fr/An), compétences par
// trimestre (APC) et affectation enseignants/professeur principal. Logique
// métier et tables identiques entre les deux schémas — seuls les chemins
// require, exiger_role() et flash_html() sont adaptés aux conventions SIGES.
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'FONDATEUR', 'CENSEUR']);

$onglet     = $_GET['onglet'] ?? 'catalogue';
$id_cl_aff  = (int)($_GET['classe_aff'] ?? 0);
$id_cl_ens  = (int)($_GET['classe_ens'] ?? 0);
$niveau_aff = $_GET['niveau_aff'] ?? '';

$classes = db_all(
    "SELECT c.*, n.libelle_niv
     FROM classe c LEFT JOIN niveau n ON n.code_niveau=c.code_niveau
     WHERE c.archivee=0 ORDER BY c.libelle_section, c.ordre, c.designation"
);
$niveaux         = db_all("SELECT * FROM niveau ORDER BY id_cycle, ordre_niveau, libelle_niv");
$sections_dispo  = db_all("SELECT * FROM section_classe ORDER BY libelle_section");
// Groupes de compétence actifs — utilisés à la fois par l'onglet
// « Affectation par classe » et l'onglet « Compétences par trimestre ».
$all_matieres = db_all("SELECT * FROM matiere WHERE actif=1 ORDER BY libelle");

$annee     = get_annee_active();
$val_annee = $annee['val_annee'] ?: '—';

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
            $mid     = (int)post('mat_id');
            $lib     = post('libelle');
            $lib_en  = post('libelle_en');
            $code    = post('code');
            $ord     = (int)post('ordre') ?: 1;
            $section = post('libelle_section') ?: null;
            $actif   = post('actif') ? 1 : 0;
            if ($lib) {
                if ($mid) {
                    db_exec("UPDATE matiere SET libelle=?,libelle_en=?,code=?,ordre=?,libelle_section=?,actif=? WHERE id=?",
                            [$lib,$lib_en?:null,$code?:null,$ord,$section,$actif,$mid]);
                    flash_set('succes', 'Matière mise à jour.');
                } else {
                    db_exec("INSERT INTO matiere (libelle,libelle_en,code,ordre,libelle_section,actif) VALUES (?,?,?,?,?,?)",
                            [$lib,$lib_en?:null,$code?:null,$ord,$section,$actif]);
                    flash_set('succes', 'Matière ajoutée.');
                }
            }
            rediriger('secondaire/pages/matieres/liste.php?onglet=catalogue' . ($q ? '&q='.urlencode($q) : ''));
        }
        if (post('action') === 'toggle_actif_mat') {
            $mid = (int)post('mat_id');
            db_exec("UPDATE matiere SET actif = 1 - actif WHERE id=?", [$mid]);
            flash_set('succes', 'Statut mis à jour.');
            rediriger('secondaire/pages/matieres/liste.php?onglet=catalogue' . ($q ? '&q='.urlencode($q) : ''));
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
//  ONGLET 2 — Affectation par classe
// ══════════════════════════════════════════════════════════════
$disciplines    = [];
$total_coeff    = 0;
$groupes        = [];
$groupes_filtre = [];
$assigned_ids   = [];
$classe_info    = null;

if ($onglet === 'par_classe') {
    $groupes = db_all("SELECT * FROM groupe ORDER BY id_groupe_comp");

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_verifier();
        $action = post('action');

        if ($action === 'add_disc' && $id_cl_aff) {
            $selected      = $_POST['mats'] ?? [];
            $id_groupe_lot = (int)post('groupe_batch');
            $added = 0;
            foreach ($selected as $id_mat) {
                $id_mat    = (int)$id_mat;
                $id_groupe = $id_groupe_lot;
                $coef      = (int)($_POST['coef'][$id_mat] ?? 1);
                $ordre     = (int)($_POST['ordre'][$id_mat] ?? 1);
                if ($id_mat && $id_groupe) {
                    db_exec("INSERT IGNORE INTO discipline (id_mat,IDClasses,id_groupe,coef,ordre) VALUES (?,?,?,?,?)",
                            [$id_mat, $id_cl_aff, $id_groupe, $coef, (string)$ordre]);
                    $added++;
                }
            }
            flash_set('succes', "$added matière(s) affectée(s).");
            rediriger("secondaire/pages/matieres/liste.php?onglet=par_classe&niveau_aff=" . urlencode($niveau_aff) . "&classe_aff=$id_cl_aff");
        }

        if ($action === 'del_disc' && $id_cl_aff) {
            $id_mat    = (int)post('id_mat');
            $id_groupe = (int)post('id_groupe');
            db_exec("DELETE FROM discipline WHERE id_mat=? AND IDClasses=? AND id_groupe=?",
                    [$id_mat, $id_cl_aff, $id_groupe]);
            db_exec("DELETE FROM dispenser WHERE id_mat=? AND IDClasses=? AND val_annee=?",
                    [$id_mat, $id_cl_aff, $val_annee]);
            flash_set('succes', 'Matière retirée.');
            rediriger("secondaire/pages/matieres/liste.php?onglet=par_classe&niveau_aff=" . urlencode($niveau_aff) . "&classe_aff=$id_cl_aff");
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
            flash_set('succes', 'Matière mise à jour.');
            rediriger("secondaire/pages/matieres/liste.php?onglet=par_classe&niveau_aff=" . urlencode($niveau_aff) . "&classe_aff=$id_cl_aff");
        }
    }

    if ($id_cl_aff) {
        $classe_info    = db_one("SELECT * FROM classe WHERE id=?", [$id_cl_aff]);
        $section        = $classe_info['libelle_section'] ?? '';
        $groupes_filtre = $section
            ? db_all("SELECT * FROM groupe WHERE id_section=? ORDER BY id_groupe_comp", [$section])
            : $groupes;

        // Classe sans AUCUNE matière affectée : applique automatiquement le
        // programme standard de son niveau, s'il en existe un (onglet
        // « Programme par niveau » ci-dessous) — jamais d'écrasement d'un
        // réglage manuel déjà présent. Demande explicite du 24/09/2026.
        $programme_auto_applique = 0;
        if ($classe_info && !(int) db_val("SELECT COUNT(*) FROM discipline WHERE IDClasses=?", [$id_cl_aff])
            && function_exists('secondaire_appliquer_programme_niveau')) {
            $programme_auto_applique = secondaire_appliquer_programme_niveau(
                $id_cl_aff, $classe_info['code_niveau'] ?? null, $classe_info['libelle_section'] ?? null
            );
        }

        $disciplines = db_all(
            "SELECT d.id_mat, d.IDClasses, d.id_groupe, d.coef, d.ordre,
                    m.libelle AS mat_libelle,
                    g.libelle_groupe_comp AS groupe_libelle
             FROM discipline d
             JOIN matiere m ON m.id = d.id_mat AND m.actif=1
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
//  ONGLET 2bis — Compétences par trimestre (APC)
// ══════════════════════════════════════════════════════════════
$niveaux_comp    = [];
$trimestres_comp = [];
$trimestre_actif = [];
$f_niveau_comp   = '';
$f_trim_comp     = 0;
$competences     = [];

if ($onglet === 'competences_trim') {
    $niveaux_comp     = db_all("SELECT * FROM niveau ORDER BY id_cycle, ordre_niveau, libelle_niv");
    // get_trimestre_actif() DOIT être appelée avant la requête ci-dessous :
    // sur une école neuve, elle amorce les 3 trimestres de l'année active
    // (table `trimestre` encore vide) — sinon le select reste vide au tout
    // premier chargement alors même que trim_comp pointe déjà vers l'ID
    // fraîchement créé (bug constaté à la vérification de cette étape).
    $trimestre_actif  = get_trimestre_actif();
    $trimestres_comp  = db_all(
        "SELECT t.*, a.libelle AS annee_lib FROM trimestre t
         JOIN annee_scolaire a ON a.id=t.id_annee
         WHERE a.active=1 ORDER BY t.ordre"
    );
    $f_niveau_comp    = $_GET['niveau_comp'] ?? '';
    $f_trim_comp      = (int)($_GET['trim_comp'] ?? ($trimestre_actif['id'] ?? 0));
    $redir_comp_base  = "secondaire/pages/matieres/liste.php?onglet=competences_trim";

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_verifier();
        $action = post('action');

        if ($action === 'comp_creer') {
            $id_mat  = (int)post('id_matiere');
            $niveau  = post('code_niveau');
            $id_trim = (int)post('id_trim');
            $lib     = post('libelle');
            $lib_en  = post('libelle_en');
            $ord     = (int)post('ordre') ?: 1;
            if ($id_mat && $niveau && $id_trim && $lib) {
                db_exec(
                    "INSERT INTO competence (id_matiere,code_niveau,id_trim,libelle,libelle_en,ordre)
                     VALUES (?,?,?,?,?,?)",
                    [$id_mat, $niveau, $id_trim, $lib, $lib_en ?: null, $ord]
                );
                flash_set('succes', 'Compétence ajoutée.');
            }
            rediriger("$redir_comp_base&niveau_comp=" . urlencode($niveau) . "&trim_comp=$id_trim");
        }

        if ($action === 'comp_modifier') {
            $id      = (int)post('comp_id');
            $lib     = post('libelle');
            $lib_en  = post('libelle_en');
            $ord     = (int)post('ordre') ?: 1;
            $niveau  = post('code_niveau');
            $id_trim = (int)post('id_trim');
            if ($id && $lib) {
                db_exec("UPDATE competence SET libelle=?,libelle_en=?,ordre=? WHERE id=?",
                        [$lib, $lib_en ?: null, $ord, $id]);
                flash_set('succes', 'Compétence mise à jour.');
            }
            rediriger("$redir_comp_base&niveau_comp=" . urlencode($niveau) . "&trim_comp=$id_trim");
        }

        if ($action === 'comp_supprimer') {
            $id      = (int)post('comp_id');
            $niveau  = post('code_niveau');
            $id_trim = (int)post('id_trim');
            db_exec("DELETE FROM competence WHERE id=?", [$id]);
            flash_set('succes', 'Compétence supprimée.');
            rediriger("$redir_comp_base&niveau_comp=" . urlencode($niveau) . "&trim_comp=$id_trim");
        }
    }

    if ($f_niveau_comp && $f_trim_comp) {
        $competences = db_all(
            "SELECT c.*, m.libelle AS mat_libelle
             FROM competence c
             JOIN matiere m ON m.id = c.id_matiere
             WHERE c.code_niveau=? AND c.id_trim=?
             ORDER BY m.libelle, c.ordre",
            [$f_niveau_comp, $f_trim_comp]
        );
    }
}

// ══════════════════════════════════════════════════════════════
//  ONGLET 2ter — Programme par niveau (matières affectées automatiquement
//  aux classes de ce niveau, à la création ou dès qu'une classe existante
//  n'a encore aucune matière — voir fonctions.php::
//  secondaire_appliquer_programme_niveau(), secondaire/pages/classes/
//  form.php, onglet « Affectation par classe » ci-dessus). Demande
//  explicite du 24/09/2026.
// ══════════════════════════════════════════════════════════════
$f_niveau_prog   = $_GET['niveau_prog'] ?? '';
$programme_lignes = [];
$nb_classes_niveau = 0;

if ($onglet === 'programme') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_verifier();
        $action = post('action');

        if ($action === 'prog_ajouter') {
            $niveau    = post('code_niveau');
            $id_mat    = (int) post('id_matiere');
            $id_groupe = (int) post('id_groupe');
            $coef      = (int) post('coef') ?: 1;
            $ordre     = (int) post('ordre') ?: 1;
            if ($niveau && $id_mat && $id_groupe) {
                db_exec(
                    "INSERT INTO programme_niveau (code_niveau, id_matiere, id_groupe, coef, ordre) VALUES (?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE id_groupe=VALUES(id_groupe), coef=VALUES(coef), ordre=VALUES(ordre)",
                    [$niveau, $id_mat, $id_groupe, $coef, $ordre]
                );
                flash_set('succes', 'Matière ajoutée au programme.');
            }
            rediriger("secondaire/pages/matieres/liste.php?onglet=programme&niveau_prog=" . urlencode($niveau));
        }

        if ($action === 'prog_supprimer') {
            $id_prog = (int) post('id_prog');
            $niveau  = post('code_niveau');
            db_exec("DELETE FROM programme_niveau WHERE id=?", [$id_prog]);
            flash_set('succes', 'Matière retirée du programme.');
            rediriger("secondaire/pages/matieres/liste.php?onglet=programme&niveau_prog=" . urlencode($niveau));
        }

        if ($action === 'prog_appliquer' && $f_niveau_prog) {
            $classes_niveau_prog = db_all("SELECT id, libelle_section FROM classe WHERE code_niveau=? AND archivee=0", [$f_niveau_prog]);
            $total_appliquees = 0;
            foreach ($classes_niveau_prog as $c) {
                $total_appliquees += secondaire_appliquer_programme_niveau((int) $c['id'], $f_niveau_prog, $c['libelle_section'] ?? null);
            }
            flash_set('succes', count($classes_niveau_prog) . " classe(s) du niveau passée(s) en revue — matières manquantes complétées.");
            rediriger("secondaire/pages/matieres/liste.php?onglet=programme&niveau_prog=" . urlencode($f_niveau_prog));
        }
    }

    if ($f_niveau_prog) {
        $programme_lignes = db_all(
            "SELECT pn.id, pn.id_matiere, pn.id_groupe, pn.coef, pn.ordre,
                    m.libelle AS mat_libelle, m.libelle_section,
                    g.libelle_groupe_comp AS groupe_libelle
             FROM programme_niveau pn
             JOIN matiere m ON m.id = pn.id_matiere
             LEFT JOIN groupe g ON g.id_groupe_comp = pn.id_groupe
             WHERE pn.code_niveau = ?
             ORDER BY m.libelle_section, pn.ordre, m.libelle",
            [$f_niveau_prog]
        );
        $nb_classes_niveau = (int) db_val("SELECT COUNT(*) FROM classe WHERE code_niveau=? AND archivee=0", [$f_niveau_prog]);
    }
}

// ══════════════════════════════════════════════════════════════
//  ONGLET 3 — Affectation des enseignants
// ══════════════════════════════════════════════════════════════
$disciplines_ens  = [];
$nb_affectes      = 0;
$total_coeff_ens  = 0;
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
            rediriger("secondaire/pages/matieres/liste.php?onglet=enseignants&classe_ens=$id_cl_ens");
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
             JOIN matiere m ON m.id = d.id_mat AND m.actif=1
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
require_once __DIR__ . '/../../../layout/header.php';

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
  <h4><i class="bi bi-journal-bookmark me-1 text-primary"></i>Matières</h4>
</div>

<?= flash_html() ?>

<!-- Onglets -->
<ul class="nav nav-tabs mb-3" style="border-bottom:2px solid #e5e7eb">
  <li class="nav-item">
    <a class="nav-link <?= $onglet==='catalogue'?'active':'' ?>"
       href="<?= APP_URL ?>/secondaire/pages/matieres/liste.php?onglet=catalogue">
      <i class="bi bi-journal-bookmark me-1"></i>Catalogue
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $onglet==='par_classe'?'active':'' ?>"
       href="<?= APP_URL ?>/secondaire/pages/matieres/liste.php?onglet=par_classe<?= $id_cl_aff?"&classe_aff=$id_cl_aff":'' ?>">
      <i class="bi bi-diagram-3 me-1"></i>Affectation par classe
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $onglet==='programme'?'active':'' ?>"
       href="<?= APP_URL ?>/secondaire/pages/matieres/liste.php?onglet=programme<?= $f_niveau_prog?"&niveau_prog=".urlencode($f_niveau_prog):'' ?>">
      <i class="bi bi-diagram-2 me-1"></i>Programme par niveau
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $onglet==='competences_trim'?'active':'' ?>"
       href="<?= APP_URL ?>/secondaire/pages/matieres/liste.php?onglet=competences_trim<?= $f_niveau_comp?"&niveau_comp=".urlencode($f_niveau_comp):'' ?><?= $f_trim_comp?"&trim_comp=$f_trim_comp":'' ?>">
      <i class="bi bi-list-stars me-1"></i>Compétences par trimestre
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $onglet==='enseignants'?'active':'' ?>"
       href="<?= APP_URL ?>/secondaire/pages/matieres/liste.php?onglet=enseignants<?= $id_cl_ens?"&classe_ens=$id_cl_ens":'' ?>">
      <i class="bi bi-person-badge me-1"></i>Affectation enseignants
    </a>
  </li>
</ul>

<script>
// Recharge en AJAX le contenu d'un conteneur (identifié par containerId) au
// changement d'un <select> de filtre, au lieu de soumettre le formulaire et
// recharger toute la page. Le fragment ciblé est extrait de la même page
// re-render côté serveur avec les nouveaux paramètres GET (pas d'endpoint
// séparé à maintenir) ; repli sur une navigation normale en cas d'échec.
async function ajaxSelectReload(selectEl, containerId) {
    var form      = selectEl.form;
    var params    = new URLSearchParams(new FormData(form));
    var url       = window.location.pathname + '?' + params.toString();
    var container = document.getElementById(containerId);
    if (!container) { form.submit(); return; }
    var prevOpacity = container.style.opacity;
    container.style.opacity = '.5';
    try {
        var res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        var html  = await res.text();
        var doc   = new DOMParser().parseFromString(html, 'text/html');
        var fresh = doc.getElementById(containerId);
        if (!fresh) { window.location.href = url; return; }
        container.innerHTML = fresh.innerHTML;
        history.replaceState(null, '', url);
    } catch (e) {
        window.location.href = url;
    } finally {
        container.style.opacity = prevOpacity;
    }
}
</script>

<?php if ($onglet === 'catalogue'): ?>
<!-- ══════════════════════════════════════════════════
     ONGLET 1 — Catalogue global
══════════════════════════════════════════════════ -->
<div class="card mb-2">
  <div class="card-body py-2">
    <div class="section-titre mb-2"><i class="bi bi-plus me-1"></i>Ajouter / modifier une matière</div>
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
      <div class="col-md-2">
        <label class="form-label">Section</label>
        <select name="libelle_section" id="f_section" class="form-select">
          <option value="">—</option>
          <?php foreach ($sections_dispo as $s): ?>
            <option value="<?= h($s['libelle_section']) ?>"><?= h($s['libelle_section']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-1">
        <label class="form-label">Ordre</label>
        <input type="number" name="ordre" id="f_ordre" class="form-control" value="1" min="1">
      </div>
      <div class="col-md-1 form-check ms-1 mb-1">
        <input type="checkbox" name="actif" id="f_actif" class="form-check-input" value="1" checked>
        <label class="form-check-label" for="f_actif" style="font-size:.78rem">Actif</label>
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
          <th style="width:70px" class="text-center">Section</th>
          <th style="width:60px" class="text-center">Ordre</th>
          <th style="width:80px" class="text-center">Statut</th>
          <th class="text-end" style="width:110px">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($matieres)): ?>
          <tr><td colspan="8" class="text-center text-muted py-4">Aucune matière trouvée.</td></tr>
        <?php else: $num = $offset + 1; foreach ($matieres as $m): $inactif = !$m['actif']; ?>
          <tr style="<?= $inactif ? 'opacity:.55' : '' ?>">
            <td class="text-muted"><?= $num++ ?></td>
            <td><?= $m['code'] ? '<span class="badge-code">'.h($m['code']).'</span>' : '—' ?></td>
            <td class="fw-semibold"><?= h($m['libelle']) ?></td>
            <td style="color:#6b7280;font-size:.8rem"><?= h($m['libelle_en'] ?? '') ?: '—' ?></td>
            <td class="text-center">
              <?php if ($m['libelle_section']): ?>
                <span class="badge" style="background:<?= $m['libelle_section']==='An'?'#ede9fe':'#dbeafe' ?>;color:<?= $m['libelle_section']==='An'?'#5b21b6':'#1e40af' ?>;font-size:.68rem">
                  <?= h($m['libelle_section']) ?>
                </span>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td class="text-center text-muted"><?= $m['ordre'] ?></td>
            <td class="text-center">
              <form method="post" style="display:inline">
                <?= csrf_champ() ?>
                <input type="hidden" name="action" value="toggle_actif_mat">
                <input type="hidden" name="mat_id" value="<?= $m['id'] ?>">
                <button type="submit" class="btn btn-sm" style="padding:1px 8px;font-size:.68rem;border:none;background:<?= $inactif?'#fee2e2':'#dcfce7' ?>;color:<?= $inactif?'#991b1b':'#15803d' ?>"
                        title="<?= $inactif ? 'Réactiver' : 'Désactiver' ?>">
                  <?= $inactif ? 'Inactif' : 'Actif' ?>
                </button>
              </form>
            </td>
            <td class="text-end">
              <button class="btn btn-sm btn-light" style="padding:3px 7px" title="Modifier"
                      onclick="editerMat(<?= $m['id'] ?>,<?= h(json_encode($m['libelle'])) ?>,<?= h(json_encode($m['libelle_en']??'')) ?>,<?= h(json_encode($m['code']??'')) ?>,<?= (int)$m['ordre'] ?>,<?= h(json_encode($m['libelle_section']??'')) ?>,<?= (int)$m['actif'] ?>)">
                <i class="bi bi-pencil" style="font-size:.78rem"></i>
              </button>
              <a href="<?= APP_URL ?>/secondaire/pages/matieres/supprimer.php?id=<?= $m['id'] ?>&csrf=<?= csrf_generer() ?>"
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
  <div class="mt-2"><?= pagination_html($page, $total_pages, APP_URL.'/secondaire/pages/matieres/liste.php?onglet=catalogue'.($q?'&q='.urlencode($q):'')) ?></div>
<?php endif; ?>

<script>
function editerMat(id, lib, libEn, code, ordre, section, actif) {
    document.getElementById('f_id').value      = id;
    document.getElementById('f_lib').value     = lib;
    document.getElementById('f_lib_en').value  = libEn;
    document.getElementById('f_code').value    = code;
    document.getElementById('f_ordre').value   = ordre;
    document.getElementById('f_section').value = section;
    document.getElementById('f_actif').checked = !!actif;
    document.getElementById('btn-label').textContent = 'Enregistrer';
    document.getElementById('btn-annuler').classList.remove('d-none');
    document.getElementById('f_lib').focus();
    window.scrollTo({top: 0, behavior: 'smooth'});
}
function reinitForm() {
    document.getElementById('form-mat').reset();
    document.getElementById('f_id').value = '0';
    document.getElementById('f_actif').checked = true;
    document.getElementById('btn-label').textContent = 'Ajouter';
    document.getElementById('btn-annuler').classList.add('d-none');
}
</script>

<?php elseif ($onglet === 'par_classe'): ?>
<!-- ══════════════════════════════════════════════════
     ONGLET 2 — Affectation par classe
══════════════════════════════════════════════════ -->
<div id="par-classe-dynamic">
<?php
  // Sélection en cascade niveau → classe : le niveau détermine la section
  // (Fr/An) qui sert ensuite à filtrer les groupes de compétence proposables
  // ci-dessous.
  $classes_niveau = $niveau_aff
      ? array_values(array_filter($classes, fn($c) => $c['code_niveau'] === $niveau_aff))
      : $classes;
?>
<!-- Sélecteur niveau → classe -->
<div class="card mb-3" style="border:none;box-shadow:none;background:transparent">
  <div class="card-body py-0 px-0">
    <form method="get" class="d-flex align-items-center gap-3 flex-wrap">
      <input type="hidden" name="onglet" value="par_classe">
      <div style="min-width:220px">
        <select name="niveau_aff" id="sel-niveau-aff" class="form-select form-select-sm"
                onchange="document.getElementById('sel-classe-aff').value='';ajaxSelectReload(this,'par-classe-dynamic')">
          <option value="">— Sélectionner un niveau —</option>
          <?php foreach ($niveaux as $n): ?>
            <option value="<?= h($n['code_niveau']) ?>" <?= $niveau_aff === $n['code_niveau'] ? 'selected' : '' ?>>
              <?= h($n['libelle_niv']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="min-width:240px;max-width:360px;flex:1">
        <select name="classe_aff" id="sel-classe-aff" class="form-select form-select-sm"
                onchange="ajaxSelectReload(this,'par-classe-dynamic')">
          <option value="">— Sélectionner une classe —</option>
          <?php foreach ($classes_niveau as $c): ?>
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
            <i class="bi bi-layers me-1"></i><?= count($disciplines) ?> matière(s) · Σ coef <?= $total_coeff ?>
          </span>
        </div>
      <?php endif; ?>
    </form>
  </div>
</div>

<?php if ($programme_auto_applique): ?>
  <div class="alert alert-success py-2 mb-3" style="font-size:.82rem">
    <i class="bi bi-magic me-1"></i>
    <?= (int) $programme_auto_applique ?> matière(s) affectée(s) automatiquement d'après le programme standard du niveau
    <strong><?= h($classe_info['code_niveau'] ?? '') ?></strong>.
  </div>
<?php endif; ?>

<?php if (!$id_cl_aff): ?>
  <div class="text-center text-muted py-5">
    <i class="bi bi-cursor" style="font-size:2.5rem;display:block;opacity:.15;margin-bottom:.5rem"></i>
    Sélectionnez une classe pour gérer ses matières.
  </div>

<?php else:
  $nom_classe = h($classe_info['designation'] ?? '');
  $section    = $classe_info['libelle_section'] ?? '';
?>

<div class="row g-2" style="align-items:flex-start">

  <!-- ══ Panneau gauche : Ajouter ══ -->
  <?php
    // Seules les matières de la section (Fr/An) du niveau de la classe
    // choisie sont proposables ici — une matière Fr ne doit pas apparaître
    // pour une classe anglophone et inversement.
    $all_matieres_section = array_values(array_filter(
        $all_matieres, fn($m) => ($m['libelle_section'] ?? '') === $section
    ));
  ?>
  <div class="col-xl-5 col-lg-6">
    <div class="card h-100" style="border:1px solid #c7d2fe;overflow:hidden">
      <div class="card-header py-2 px-3 d-flex align-items-center gap-2"
           style="background:linear-gradient(135deg,#eef2ff,#e0e7ff);border-bottom:1px solid #c7d2fe">
        <i class="bi bi-plus-circle-fill" style="color:#4338ca;font-size:.95rem"></i>
        <span class="fw-bold" style="font-size:.8rem;color:#312e81">Ajouter des matières</span>
        <span class="ms-auto badge" style="background:#c7d2fe;color:#3730a3;font-size:.65rem">
          <?= count($all_matieres_section) ?> disponibles
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
          <div style="display:grid;grid-template-columns:20px 1fr 56px 56px;gap:3px;
                      padding:4px 8px;font-size:.62rem;font-weight:700;color:#6b7280;
                      background:#f8faff;border-bottom:1px solid #e9ecef;text-transform:uppercase;letter-spacing:.04em">
            <div></div>
            <div>Matière</div>
            <div class="text-center">Coef</div>
            <div class="text-center">Ordre</div>
          </div>

          <div style="max-height:420px;overflow-y:auto">
            <?php foreach ($all_matieres_section as $m):
              $deja = in_array($m['id'], $assigned_ids);
            ?>
            <div class="mat-row" data-lib="<?= strtolower(h($m['libelle'])) ?>"
                 style="display:grid;grid-template-columns:20px 1fr 56px 56px;gap:3px;
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
                <select name="coef[<?= $m['id'] ?>]" class="form-select form-select-sm text-center"
                        style="font-size:.78rem;padding:3px 4px">
                  <?php for ($c=1;$c<=10;$c++): ?><option value="<?= $c ?>"><?= $c ?></option><?php endfor; ?>
                </select>
              </div>
              <div>
                <select name="ordre[<?= $m['id'] ?>]" class="form-select form-select-sm text-center"
                        style="font-size:.78rem;padding:3px 4px">
                  <?php for ($o=1;$o<=30;$o++): ?><option value="<?= $o ?>"><?= $o ?></option><?php endfor; ?>
                </select>
              </div>
              <?php else: ?>
              <div style="color:#10b981;font-size:.65rem;text-align:center;grid-column:3/5">
                <i class="bi bi-check2-all"></i> affectée
              </div>
              <?php endif; ?>
            </div>
            <?php endforeach; ?>
          </div>

          <div class="d-flex align-items-center gap-2 px-2 py-2 border-top flex-wrap" style="background:#f8faff">
            <div style="min-width:150px">
              <select name="groupe_batch" class="form-select form-select-sm" style="font-size:.7rem">
                <?php foreach ($groupes_filtre as $g): ?>
                  <option value="<?= $g['id_groupe_comp'] ?>">
                    <?= h($g['libelle_groupe_comp']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
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

  <!-- ══ Panneau droit : Matières affectées ══ -->
  <div class="col-xl-7 col-lg-6">
    <div class="card h-100" style="border:1px solid #c7d2fe;overflow:hidden">
      <div class="card-header py-2 px-3 d-flex align-items-center gap-2"
           style="background:linear-gradient(135deg,#eef2ff,#e0e7ff);border-bottom:1px solid #c7d2fe">
        <i class="bi bi-journal-check" style="color:#4338ca;font-size:.95rem"></i>
        <span class="fw-bold" style="font-size:.8rem;color:#312e81">
          Matières &mdash; <?= $nom_classe ?>
        </span>
        <span class="ms-auto badge" style="background:#c7d2fe;color:#3730a3;font-size:.65rem">
          <?= count($disciplines) ?> mat. &nbsp;|&nbsp; Σ coef <strong><?= $total_coeff ?></strong>
        </span>
      </div>
      <div class="card-body p-0">
        <?php if (empty($disciplines)): ?>
          <div class="text-center text-muted py-5" style="font-size:.82rem">
            <i class="bi bi-inbox" style="font-size:2.2rem;display:block;opacity:.15;margin-bottom:.4rem"></i>
            Aucune matière affectée à cette classe.
          </div>
        <?php else: ?>
        <div style="max-height:520px;overflow-y:auto">
          <table style="width:100%;border-collapse:collapse;border:1px solid #e5e7eb">
            <thead>
              <tr style="position:sticky;top:0;z-index:2;background:#f8faff;border-bottom:1px solid #e9ecef">
                <th style="width:24px;padding:5px 4px 5px 12px;border-right:1px solid #e5e7eb"></th>
                <th style="padding:5px 6px;width:184px;text-align:left;font-size:.62rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;border-right:1px solid #e5e7eb">
                  Groupe de compétence
                </th>
                <th style="width:10px;padding:5px 2px;text-align:center;font-size:.62rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;border-right:1px solid #e5e7eb">
                  Coef
                </th>
                <th style="width:6px;padding:5px 2px;text-align:center;font-size:.62rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;border-right:1px solid #e5e7eb">
                  Ordre
                </th>
                <th style="width:25px;padding:5px 0;text-align:center;font-size:.62rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.04em">
                  Actions
                </th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($disciplines as $i => $d): ?>
              <tr class="disc-row" style="border-bottom:1px solid #e5e7eb"
                  onmouseover="this.style.background='#f8faff'" onmouseout="this.style.background=''">
                <td style="width:24px;padding:5px 4px 5px 12px;color:#d1d5db;font-size:.7rem;text-align:right;white-space:nowrap;border-right:1px solid #e5e7eb">
                  <?= $i+1 ?>
                </td>
                <td style="padding:5px 6px;font-size:.8rem;font-weight:600;color:#111827;max-width:184px;width:184px;border-right:1px solid #e5e7eb">
                  <div style="white-space:normal;word-break:break-word" title="<?= h($d['mat_libelle']) ?>">
                    <?= h($d['mat_libelle']) ?>
                  </div>
                </td>
                <td style="width:10px;padding:5px 2px;text-align:center;border-right:1px solid #e5e7eb">
                  <span style="display:inline-block;min-width:10px;padding:1px 2px;border-radius:6px;
                               background:#dbeafe;color:#1e40af;font-size:.66rem;font-weight:700">
                    <?= $d['coef'] ?>
                  </span>
                </td>
                <td style="width:6px;padding:5px 2px;text-align:center;color:#9ca3af;font-size:.64rem;border-right:1px solid #e5e7eb">
                  <?= (int)$d['ordre'] ?>
                </td>
                <td style="width:25px;padding:4px 0;text-align:center;white-space:nowrap">
                  <button type="button" class="btn btn-sm btn-light" style="padding:3px 5px;margin-right:20px"
                          title="Modifier"
                          onclick="ouvrirEditDisc(<?= $d['id_mat'] ?>,<?= $d['id_groupe'] ?>,<?= $d['coef'] ?>,<?= (int)$d['ordre'] ?>,<?= h(json_encode($d['mat_libelle'])) ?>)">
                    <i class="bi bi-pencil" style="font-size:.7rem;color:#2563eb"></i>
                  </button>
                  <button type="button" class="btn btn-sm btn-light text-danger" style="padding:3px 5px"
                          title="Retirer"
                          onclick="supprimerDisc(<?= $d['id_mat'] ?>,<?= $d['id_groupe'] ?>,<?= h(json_encode($d['mat_libelle'])) ?>)">
                    <i class="bi bi-trash" style="font-size:.7rem"></i>
                  </button>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>

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

<!-- Modal Modifier -->
<div class="modal fade" id="modalEditDisc" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content" style="border-radius:14px;overflow:hidden">
      <div class="modal-header py-2" style="background:linear-gradient(135deg,#eef2ff,#e0e7ff);border-bottom:1px solid #c7d2fe">
        <h6 class="modal-title fw-bold d-flex align-items-center gap-2" style="font-size:.85rem;color:#312e81">
          <i class="bi bi-pencil-square text-primary"></i>Modifier la matière
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
// Délégation sur document (et non un binding direct par élément) : le
// contenu de #par-classe-dynamic est reconstruit à chaque changement de
// niveau/classe via ajaxSelectReload() (innerHTML), ce qui ne réexécute pas
// les <script> et casserait des listeners attachés directement aux
// éléments d'origine — la délégation survit à ces remplacements.
document.addEventListener('input', function(e) {
    if (e.target.id !== 'filtre-mat') return;
    var q = e.target.value.toLowerCase();
    document.querySelectorAll('.mat-row').forEach(function(row) {
        row.style.display = (row.dataset.lib || '').includes(q) ? '' : 'none';
    });
});
document.addEventListener('change', function(e) {
    if (e.target.id === 'chk-all') {
        document.querySelectorAll('.mat-cb').forEach(function(cb) { cb.checked = e.target.checked; });
        majCount();
    } else if (e.target.classList.contains('mat-cb')) {
        majCount();
    }
});
function majCount() {
    var n  = document.querySelectorAll('.mat-cb:checked').length;
    var el = document.getElementById('sel-count');
    if (el) el.textContent = n + ' sélectionnée(s)';
}

// Supprimer une affectation
function supprimerDisc(idMat, idGroupe, nom) {
    if (!confirm('Retirer « ' + nom + ' » de cette classe ?')) return;
    document.getElementById('del_id_mat').value    = idMat;
    document.getElementById('del_id_groupe').value = idGroupe;
    document.getElementById('form-del').submit();
}
// Ouvrir modal édition
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
</div><!-- /#par-classe-dynamic -->

<?php elseif ($onglet === 'programme'): ?>
<!-- ══════════════════════════════════════════════════
     ONGLET 2ter — Programme par niveau
══════════════════════════════════════════════════ -->
<div class="alert alert-info py-2 mb-3" style="font-size:.82rem">
  <i class="bi bi-info-circle me-1"></i>
  Les matières listées ici pour un niveau sont affectées <strong>automatiquement</strong> à toute nouvelle
  classe de ce niveau, et à toute classe existante qui n'a encore aucune matière (dès que vous la
  consultez dans l'onglet « Affectation par classe »).
</div>

<form method="get" class="d-flex align-items-center gap-3 flex-wrap mb-3">
  <input type="hidden" name="onglet" value="programme">
  <div style="min-width:220px">
    <select name="niveau_prog" class="form-select form-select-sm" onchange="this.form.submit()">
      <option value="">— Sélectionner un niveau —</option>
      <?php foreach ($niveaux as $n): ?>
        <option value="<?= h($n['code_niveau']) ?>" <?= $f_niveau_prog === $n['code_niveau'] ? 'selected' : '' ?>>
          <?= h($n['libelle_niv']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php if ($f_niveau_prog): ?>
    <span style="background:#f0fdf4;color:#166534;padding:3px 10px;border-radius:8px;font-size:.72rem;font-weight:600">
      <i class="bi bi-door-open me-1"></i><?= $nb_classes_niveau ?> classe(s) à ce niveau
    </span>
  <?php endif; ?>
</form>

<?php if (!$f_niveau_prog): ?>
  <div class="text-center text-muted py-5">
    <i class="bi bi-cursor" style="font-size:2.5rem;display:block;opacity:.15;margin-bottom:.5rem"></i>
    Sélectionnez un niveau pour voir ou modifier son programme.
  </div>
<?php else: ?>

  <div class="card mb-3">
    <div class="table-responsive">
      <table class="table table-abz table-hover align-middle mb-0" style="font-size:.85rem">
        <thead><tr><th>Section</th><th>Matière</th><th>Groupe de compétence</th><th>Coef.</th><th>Ordre</th><th class="text-end">Actions</th></tr></thead>
        <tbody>
          <?php if (!$programme_lignes): ?>
            <tr><td colspan="6" class="text-center text-muted py-4">
              Aucune matière dans le programme de ce niveau pour l'instant.
            </td></tr>
          <?php else: foreach ($programme_lignes as $pl): ?>
            <tr>
              <td><span class="badge-code"><?= h($pl['libelle_section'] ?? '—') ?></span></td>
              <td class="fw-semibold"><?= h($pl['mat_libelle']) ?></td>
              <td><?= h($pl['groupe_libelle'] ?? '—') ?></td>
              <td><?= (int) $pl['coef'] ?></td>
              <td><?= (int) $pl['ordre'] ?></td>
              <td class="text-end">
                <form method="post" class="d-inline" onsubmit="return confirm('Retirer cette matière du programme du niveau ?')">
                  <?= csrf_champ() ?>
                  <input type="hidden" name="action" value="prog_supprimer">
                  <input type="hidden" name="id_prog" value="<?= (int) $pl['id'] ?>">
                  <input type="hidden" name="code_niveau" value="<?= h($f_niveau_prog) ?>">
                  <button class="btn btn-sm btn-light text-danger" style="padding:3px 7px"><i class="bi bi-trash" style="font-size:.78rem"></i></button>
                </form>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-12 col-lg-6">
      <div class="card h-100">
        <div class="card-header py-2 fw-semibold"><i class="bi bi-plus-lg me-1"></i>Ajouter une matière au programme</div>
        <div class="card-body">
          <form method="post" class="row g-2">
            <?= csrf_champ() ?>
            <input type="hidden" name="action" value="prog_ajouter">
            <input type="hidden" name="code_niveau" value="<?= h($f_niveau_prog) ?>">
            <div class="col-12">
              <label class="form-label">Matière</label>
              <select name="id_matiere" class="form-select form-select-sm" required>
                <option value="">— Choisir —</option>
                <?php foreach ($all_matieres as $m): ?>
                  <option value="<?= (int) $m['id'] ?>"><?= h($m['libelle']) ?> (<?= h($m['libelle_section'] ?? '—') ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Groupe de compétence</label>
              <select name="id_groupe" class="form-select form-select-sm" required>
                <option value="">— Choisir —</option>
                <?php foreach ($groupes as $g): ?>
                  <option value="<?= (int) $g['id_groupe_comp'] ?>"><?= h($g['libelle_groupe_comp']) ?> (<?= h($g['id_section']) ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label">Coefficient</label>
              <input type="number" name="coef" class="form-control form-control-sm" value="1" min="1" required>
            </div>
            <div class="col-6">
              <label class="form-label">Ordre</label>
              <input type="number" name="ordre" class="form-control form-control-sm" value="1" min="1" required>
            </div>
            <div class="col-12 mt-2">
              <button class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Ajouter</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <div class="col-12 col-lg-6">
      <div class="card h-100">
        <div class="card-header py-2 fw-semibold"><i class="bi bi-magic me-1"></i>Appliquer aux classes existantes</div>
        <div class="card-body">
          <p class="text-muted" style="font-size:.85rem">
            Complète les classes de ce niveau qui n'ont pas encore toutes les matières du programme —
            n'écrase jamais une affectation déjà présente.
          </p>
          <form method="post" onsubmit="return confirm('Compléter les matières manquantes sur les <?= $nb_classes_niveau ?> classe(s) de ce niveau ?')">
            <?= csrf_champ() ?>
            <input type="hidden" name="action" value="prog_appliquer">
            <input type="hidden" name="code_niveau" value="<?= h($f_niveau_prog) ?>">
            <button class="btn btn-outline-primary btn-sm" <?= $nb_classes_niveau ? '' : 'disabled' ?>>
              <i class="bi bi-magic me-1"></i>Appliquer maintenant (<?= $nb_classes_niveau ?> classe(s))
            </button>
          </form>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php elseif ($onglet === 'competences_trim'): ?>
<!-- ══════════════════════════════════════════════════
     ONGLET 2bis — Compétences par trimestre (APC)
══════════════════════════════════════════════════ -->
<div id="competences-trim-dynamic">

<!-- Filtre niveau + trimestre -->
<form method="get" class="d-flex align-items-center gap-3 flex-wrap mb-3">
  <input type="hidden" name="onglet" value="competences_trim">
  <div style="min-width:220px">
    <select name="niveau_comp" class="form-select form-select-sm" onchange="ajaxSelectReload(this,'competences-trim-dynamic')">
      <option value="">— Niveau —</option>
      <?php foreach ($niveaux_comp as $n): ?>
        <option value="<?= h($n['code_niveau']) ?>" <?= $f_niveau_comp === $n['code_niveau'] ? 'selected' : '' ?>>
          <?= h($n['libelle_niv']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div style="min-width:220px">
    <select name="trim_comp" class="form-select form-select-sm" onchange="ajaxSelectReload(this,'competences-trim-dynamic')">
      <?php foreach ($trimestres_comp as $t): ?>
        <option value="<?= $t['id'] ?>" <?= $f_trim_comp == $t['id'] ? 'selected' : '' ?>>
          <?= h($t['libelle'] . ' — ' . $t['annee_lib']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
</form>

<?php if (!$f_niveau_comp || !$f_trim_comp): ?>
  <div class="text-center text-muted py-5">
    <i class="bi bi-cursor" style="font-size:2.5rem;display:block;opacity:.15;margin-bottom:.5rem"></i>
    Sélectionnez un niveau et un trimestre pour gérer les compétences.
  </div>
<?php else: ?>

<div class="row g-2" style="align-items:flex-start">

  <!-- Panneau gauche : créer / modifier -->
  <div class="col-md-4">
    <div class="card h-100" style="border:1px solid #c7d2fe">
      <div class="card-header py-2 px-3" style="background:linear-gradient(135deg,#eef2ff,#e0e7ff);border-bottom:1px solid #c7d2fe">
        <span class="fw-bold" style="font-size:.8rem;color:#312e81" id="comp-btn-label-wrap">
          <i class="bi bi-plus-circle-fill me-1"></i><span id="comp-form-title">Ajouter une compétence</span>
        </span>
      </div>
      <div class="card-body p-3">
        <form method="post" id="form-comp">
          <?= csrf_champ() ?>
          <input type="hidden" name="action" id="comp_action" value="comp_creer">
          <input type="hidden" name="comp_id" id="comp_id" value="0">
          <input type="hidden" name="code_niveau" value="<?= h($f_niveau_comp) ?>">
          <input type="hidden" name="id_trim" value="<?= $f_trim_comp ?>">

          <div class="mb-2">
            <label class="form-label" style="font-size:.75rem">Matière</label>
            <select name="id_matiere" id="comp_id_matiere" class="form-select form-select-sm" required>
              <option value="">—</option>
              <?php foreach ($all_matieres as $m): ?>
                <option value="<?= $m['id'] ?>"><?= h($m['libelle']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-2">
            <label class="form-label" style="font-size:.75rem">Libellé (FR)</label>
            <input type="text" name="libelle" id="comp_libelle" class="form-control form-control-sm" required>
          </div>
          <div class="mb-2">
            <label class="form-label" style="font-size:.75rem">Libellé (EN)</label>
            <input type="text" name="libelle_en" id="comp_libelle_en" class="form-control form-control-sm">
          </div>
          <div class="mb-3">
            <label class="form-label" style="font-size:.75rem">Ordre</label>
            <input type="number" name="ordre" id="comp_ordre" class="form-control form-control-sm" value="1" min="1" style="max-width:100px">
          </div>
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-sm btn-primary" id="comp-btn-submit">Ajouter</button>
            <button type="button" class="btn btn-sm btn-light d-none" id="comp-btn-annuler" onclick="reinitCompForm()">Annuler</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Panneau droit : liste -->
  <div class="col-md-8">
    <div class="card h-100" style="border:1px solid #e5e7eb">
      <div class="card-header py-2 px-3 d-flex align-items-center" style="background:#f8faff;border-bottom:1px solid #e5e7eb">
        <span class="fw-bold" style="font-size:.8rem;color:#374151">
          Compétences &mdash; <?= count($competences) ?>
        </span>
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0" style="font-size:.78rem">
          <thead style="background:#f8faff">
            <tr>
              <th style="width:110px;white-space:normal">Matière</th>
              <th>Compétence</th>
              <th style="width:60px" class="text-center">Ordre</th>
              <th style="width:80px" class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$competences): ?>
              <tr><td colspan="4" class="text-center text-muted py-3">Aucune compétence pour ce niveau/trimestre.</td></tr>
            <?php else: foreach ($competences as $c): ?>
              <tr>
                <td style="white-space:normal"><?= h($c['mat_libelle']) ?></td>
                <td style="white-space:normal">
                  <?= h($c['libelle']) ?>
                  <?php if ($c['libelle_en']): ?><br><span class="text-muted" style="font-size:.7rem"><?= h($c['libelle_en']) ?></span><?php endif; ?>
                </td>
                <td class="text-center"><?= (int)$c['ordre'] ?></td>
                <td class="text-end">
                  <button type="button" class="btn btn-sm btn-light" style="padding:3px 7px"
                          onclick="editComp(<?= $c['id'] ?>,<?= h(json_encode($c['libelle'])) ?>,<?= h(json_encode($c['libelle_en']??'')) ?>,<?= (int)$c['ordre'] ?>,<?= h(json_encode($c['mat_libelle'])) ?>)">
                    <i class="bi bi-pencil" style="font-size:.72rem"></i>
                  </button>
                  <form method="post" class="d-inline" onsubmit="return confirm('Supprimer cette compétence ?\nToutes les notes déjà saisies pour elle seront perdues.')">
                    <?= csrf_champ() ?>
                    <input type="hidden" name="action" value="comp_supprimer">
                    <input type="hidden" name="comp_id" value="<?= $c['id'] ?>">
                    <input type="hidden" name="code_niveau" value="<?= h($f_niveau_comp) ?>">
                    <input type="hidden" name="id_trim" value="<?= $f_trim_comp ?>">
                    <button type="submit" class="btn btn-sm btn-light text-danger" style="padding:3px 7px">
                      <i class="bi bi-trash" style="font-size:.72rem"></i>
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
function editComp(id, lib, libEn, ordre, matLibelle) {
    document.getElementById('comp_action').value    = 'comp_modifier';
    document.getElementById('comp_id').value        = id;
    document.getElementById('comp_libelle').value   = lib;
    document.getElementById('comp_libelle_en').value = libEn;
    document.getElementById('comp_ordre').value      = ordre;
    document.getElementById('comp_id_matiere').disabled = true;
    document.getElementById('comp-form-title').textContent = 'Modifier — ' + matLibelle;
    document.getElementById('comp-btn-submit').textContent = 'Enregistrer';
    document.getElementById('comp-btn-annuler').classList.remove('d-none');
    document.getElementById('comp_libelle').focus();
    window.scrollTo({top: document.getElementById('form-comp').getBoundingClientRect().top + window.scrollY - 80, behavior: 'smooth'});
}
function reinitCompForm() {
    document.getElementById('form-comp').reset();
    document.getElementById('comp_action').value = 'comp_creer';
    document.getElementById('comp_id').value     = '0';
    document.getElementById('comp_id_matiere').disabled = false;
    document.getElementById('comp-form-title').textContent = 'Ajouter une compétence';
    document.getElementById('comp-btn-submit').textContent = 'Ajouter';
    document.getElementById('comp-btn-annuler').classList.add('d-none');
}
</script>

<?php endif; // niveau + trim choisis ?>
</div><!-- /#competences-trim-dynamic -->

<?php elseif ($onglet === 'enseignants'): ?>
<div id="enseignants-dynamic">
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
        <select name="classe_ens" class="form-select" onchange="ajaxSelectReload(this,'enseignants-dynamic')">
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

<!-- ── Matières groupées par groupe ────────────────────── -->
<?php if (empty($par_groupe)): ?>
  <div class="alert alert-info py-2 d-flex align-items-center gap-2">
    <i class="bi bi-info-circle-fill"></i>
    Aucune matière affectée à cette classe.
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
// Délégation sur document : #enseignants-dynamic est reconstruit à chaque
// changement de classe via ajaxSelectReload() (innerHTML, les <script> ne
// se ré-exécutent pas) — la délégation survit à ces remplacements, contrairement
// à des listeners attachés directement aux éléments d'origine.

// AJAX save enseignant
document.addEventListener('change', function(e) {
    var sel = e.target;
    if (!sel.classList || !sel.classList.contains('ens-select')) return;
    var fd = new FormData();
    fd.append('action', 'save_ens');
    fd.append('id_mat', sel.dataset.idMat);
    fd.append('matricule_ens', sel.value);
    fd.append('csrf', document.getElementById('csrf_token').value);
    var row  = sel.closest('tr');
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

// Filtre enseignants dans les selects
document.addEventListener('input', function(e) {
    if (e.target.id !== 'filtre-ens') return;
    var q = e.target.value.toLowerCase();
    document.querySelectorAll('.ens-select option').forEach(function(opt){
        if (!opt.value) return;
        opt.hidden = !(opt.dataset.nom || '').includes(q);
    });
});

// Recherche dans le modal PP
document.addEventListener('input', function(e) {
    if (e.target.id !== 'pp-search') return;
    var q = e.target.value.toLowerCase();
    document.querySelectorAll('#pp-select option').forEach(function(opt){
        if (!opt.value) return;
        opt.hidden = !(opt.dataset.nom || '').includes(q);
    });
});
</script>

<?php endif; // id_cl_ens ?>
</div><!-- /#enseignants-dynamic -->

<?php endif; // onglets ?>

<?php require_once __DIR__ . '/../../../layout/footer.php'; ?>
