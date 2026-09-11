<?php
// pages/classes/liste.php — Scolarité > Classes, à onglets :
//  - Classes (liste existante, inchangée)
//  - Niveaux (nouveau, demande utilisateur du 10/08/2026) : créer/modifier/
//    supprimer/activer/désactiver un niveau (`niveau`, colonne `actif`
//    ajoutée par bd/migration_v3.sql). Un niveau désactivé n'est plus
//    proposé pour de nouvelles classes (pages/classes/form.php) ni dans les
//    écrans de pédagogie (pages/competences/liste.php, onglets « Groupes
//    par niveau »/« Barème par niveau ») — les classes déjà rattachées ne
//    sont pas affectées.
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_connexion();

$role          = role_connecte();
// DIRECTEUR, ou écriture déléguée : superadmin association entré en écriture,
// ou FONDATEUR (la structure — classes / niveaux — fait partie de ce qu'il
// enregistre ; seuls notes et finances lui restent en lecture seule, voir
// ecole_contexte.php::fondateur_ecriture_permise).
$peut_modifier = (($role === 'DIRECTEUR') || est_ecriture_deleguee())
    && !(function_exists('est_lecture_seule') && est_lecture_seule());
$annee         = get_annee_active();
$val_annee     = $annee['val_annee'] ?? '';

$onglet = $_GET['onglet'] ?? 'classes';
if (!in_array($onglet, ['classes', 'niveaux'], true)) $onglet = 'classes';

// ══════════════════════════════════════════════════════════════
//  ONGLET 2 — Niveaux (CRUD + activation)
// ══════════════════════════════════════════════════════════════
if ($onglet === 'niveaux' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    exiger_role(['DIRECTEUR']);
    csrf_verifier();
    $action = post('action');

    if ($action === 'niv_creer') {
        $lib     = post('libelle_niveau');
        $ordre   = (int) post('ordre_niveau');
        // Section anglophone/francophone (déplacée de classe.Section vers
        // niveau.Section — demande explicite du 20/08/2026, migration_v42 :
        // deux classes d'un même niveau ne peuvent pas avoir des sections
        // différentes dans cet établissement, la section est donc une
        // propriété du niveau, héritée par ses classes).
        $section = post('section_niveau') === 'An' ? 'An' : 'Fr';
        if ($lib !== '') {
            $existe = db_val("SELECT COUNT(*) FROM niveau WHERE LibelleNiveau=?", [$lib]);
            if ($existe) {
                flash_set('erreur', "Le niveau « $lib » existe déjà.");
            } else {
                db_exec("INSERT INTO niveau (LibelleNiveau, OrdreNiveau, actif, Section) VALUES (?, ?, 1, ?)", [$lib, $ordre, $section]);
                flash_set('succes', 'Niveau créé.');
            }
        } else {
            flash_set('erreur', 'Le libellé est requis.');
        }
        rediriger('pages/classes/liste.php?onglet=niveaux');
    }

    if ($action === 'niv_modifier') {
        $ancien  = post('niv_ancien');
        $lib     = post('libelle_niveau');
        $ordre   = (int) post('ordre_niveau');
        $section = post('section_niveau') === 'An' ? 'An' : 'Fr';
        if ($ancien !== '' && $lib !== '') {
            if ($lib !== $ancien && db_val("SELECT COUNT(*) FROM niveau WHERE LibelleNiveau=?", [$lib])) {
                flash_set('erreur', "Le niveau « $lib » existe déjà.");
            } else {
                // LibelleNiveau est la clé référencée par classe.Niveau et
                // groupe_competence_niveau.code_niveau (ON UPDATE CASCADE) :
                // renommer ici propage proprement partout.
                db_exec("UPDATE niveau SET LibelleNiveau=?, OrdreNiveau=?, Section=? WHERE LibelleNiveau=?", [$lib, $ordre, $section, $ancien]);
                flash_set('succes', 'Niveau modifié.');
            }
        }
        rediriger('pages/classes/liste.php?onglet=niveaux');
    }

    if ($action === 'niv_supprimer') {
        $lib = post('niv_lib');
        $nb  = (int) db_val("SELECT COUNT(*) FROM classe WHERE Niveau=?", [$lib]);
        if ($nb > 0) {
            flash_set('erreur', "Impossible : $nb classe(s) sont rattachées à ce niveau — supprimez-les ou changez leur niveau d'abord.");
        } else {
            db_exec("DELETE FROM niveau WHERE LibelleNiveau=?", [$lib]);
            flash_set('succes', 'Niveau supprimé.');
        }
        rediriger('pages/classes/liste.php?onglet=niveaux');
    }

    if ($action === 'niv_activer') {
        db_exec("UPDATE niveau SET actif=1 WHERE LibelleNiveau=?", [post('niv_lib')]);
        flash_set('succes', 'Niveau activé.');
        rediriger('pages/classes/liste.php?onglet=niveaux');
    }
    if ($action === 'niv_desactiver') {
        db_exec("UPDATE niveau SET actif=0 WHERE LibelleNiveau=?", [post('niv_lib')]);
        flash_set('succes', 'Niveau désactivé.');
        rediriger('pages/classes/liste.php?onglet=niveaux');
    }
}

$niveaux = db_all(
    "SELECT n.*, COUNT(c.IDClasses) AS nb_classes
     FROM niveau n
     LEFT JOIN classe c ON c.Niveau = n.LibelleNiveau
     GROUP BY n.LibelleNiveau
     ORDER BY n.OrdreNiveau"
);

// ══════════════════════════════════════════════════════════════
//  ONGLET 1 — Classes (inchangé)
// ══════════════════════════════════════════════════════════════
$classes = db_all(
    "SELECT c.IDClasses, c.DesignationClasses, c.Niveau, n.Section, n.OrdreNiveau,
            COUNT(DISTINCT i.id_eleve) AS nb_eleves,
            SUM(e.Sexe_elv LIKE 'M%') AS nb_m,
            SUM(e.Sexe_elv LIKE 'F%') AS nb_f,
            (SELECT COUNT(*) FROM inscrire ix WHERE ix.IDClasses = c.IDClasses) AS nb_inscrits_total
     FROM classe c
     LEFT JOIN niveau n ON n.LibelleNiveau = c.Niveau
     LEFT JOIN inscrire i ON i.IDClasses = c.IDClasses AND i.val_annee = ?
     LEFT JOIN eleve e ON e.id_eleve = i.id_eleve
     GROUP BY c.IDClasses
     ORDER BY n.OrdreNiveau, c.DesignationClasses",
    [$val_annee]
);

$es_partiel = isset($_GET['partiel']);
if (!$es_partiel) {
    $titre_page = 'Classes';
    require_once __DIR__ . '/../../layout/header.php';
} else {
    header('Content-Type: text/html; charset=utf-8');
}
?>

<div id="classes-zone">

<div class="page-titre d-flex justify-content-between align-items-center flex-wrap gap-2">
  <div>
    <h4><i class="bi bi-door-open me-1 text-primary"></i>Classes</h4>
    <div class="sub"><?= count($classes) ?> classe(s) — année <?= h($val_annee) ?></div>
  </div>
  <?php if ($peut_modifier && $onglet === 'classes'): ?>
  <a href="<?= APP_URL ?>/pages/classes/form.php" class="btn btn-primary btn-sm">
    <i class="bi bi-plus-lg me-1"></i>Nouvelle classe
  </a>
  <?php endif; ?>
</div>

<ul class="nav nav-tabs mb-3" style="border-bottom:2px solid #e5e7eb">
  <li class="nav-item">
    <a class="nav-link <?= $onglet === 'classes' ? 'active' : '' ?>" data-ajax-nav href="<?= APP_URL ?>/pages/classes/liste.php?onglet=classes">
      <i class="bi bi-door-open me-1"></i>Classes
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $onglet === 'niveaux' ? 'active' : '' ?>" data-ajax-nav href="<?= APP_URL ?>/pages/classes/liste.php?onglet=niveaux">
      <i class="bi bi-bar-chart-steps me-1"></i>Niveaux
    </a>
  </li>
</ul>

<?php if ($onglet === 'classes'): ?>
<!-- ══════════════════════════════════════════════
     ONGLET 1 — Classes
══════════════════════════════════════════════ -->
<div class="card">
  <div class="table-responsive">
    <table class="table table-abz table-hover align-middle mb-0">
      <thead>
        <tr>
          <th>Désignation</th>
          <th>Niveau</th>
          <th>Section</th>
          <th class="text-center">G</th>
          <th class="text-center">F</th>
          <th class="text-center fw-bold">Total</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($classes as $c): ?>
        <tr>
          <td class="fw-semibold"><?= h($c['DesignationClasses']) ?></td>
          <td style="font-size:.78rem"><?= h($c['Niveau']) ?></td>
          <td>
            <?php if (($c['Section'] ?? 'Fr') === 'An'): ?>
              <span class="badge" style="background:#dbeafe;color:#1e40af;font-size:.7rem">Anglophone</span>
            <?php else: ?>
              <span class="badge" style="background:#f3f4f6;color:#4b5563;font-size:.7rem">Francophone</span>
            <?php endif; ?>
          </td>
          <td class="text-center"><span class="badge-m"><?= (int)$c['nb_m'] ?></span></td>
          <td class="text-center"><span class="badge-f"><?= (int)$c['nb_f'] ?></span></td>
          <td class="text-center fw-bold"><?= (int)$c['nb_eleves'] ?></td>
          <td class="text-end">
            <a href="<?= APP_URL ?>/pages/eleves/liste.php?classe=<?= (int)$c['IDClasses'] ?>"
               class="btn btn-sm" style="background:#eef2ff;color:#1e4fd8;padding:3px 7px" title="Élèves">
              <i class="bi bi-people" style="font-size:.78rem"></i>
            </a>
            <?php if ($peut_modifier): ?>
            <a href="<?= APP_URL ?>/pages/classes/form.php?id=<?= (int)$c['IDClasses'] ?>"
               class="btn btn-sm btn-light" style="padding:3px 7px" title="Modifier">
              <i class="bi bi-pencil" style="font-size:.78rem"></i>
            </a>
            <?php if ((int) $c['nb_inscrits_total'] > 0): ?>
            <span class="btn btn-sm btn-light text-muted disabled" style="padding:3px 7px;opacity:.4"
                  title="Suppression impossible : <?= (int) $c['nb_inscrits_total'] ?> inscription(s) enregistrée(s) dans cette classe (retirez d'abord les élèves)">
              <i class="bi bi-trash" style="font-size:.78rem"></i>
            </span>
            <?php else: ?>
            <a href="<?= APP_URL ?>/pages/classes/supprimer.php?id=<?= (int)$c['IDClasses'] ?>&csrf=<?= csrf_generer() ?>"
               class="btn btn-sm btn-light text-danger" style="padding:3px 7px" title="Supprimer"
               onclick="return confirm('Supprimer cette classe ? (aucun élève n\'y est inscrit)')">
              <i class="bi bi-trash" style="font-size:.78rem"></i>
            </a>
            <?php endif; ?>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if (empty($classes)): ?>
  <div class="alert alert-light text-muted text-center py-4">
    <i class="bi bi-inbox" style="font-size:2rem;display:block;opacity:.3;margin-bottom:.5rem"></i>
    Aucune classe trouvée.
  </div>
<?php endif; ?>

<?php elseif ($onglet === 'niveaux'): ?>
<!-- ══════════════════════════════════════════════
     ONGLET 2 — Niveaux
══════════════════════════════════════════════ -->
<div class="alert alert-light border py-2 mb-3" style="font-size:.8rem">
  <i class="bi bi-info-circle me-1"></i>
  Un niveau désactivé n'est plus proposé pour de nouvelles classes, ni dans les écrans de pédagogie
  (Groupes par niveau, Barème par niveau) — les classes déjà rattachées ne sont pas affectées.
</div>

<div class="row g-2" style="align-items:flex-start">

  <?php if ($peut_modifier): ?>
  <div class="col-md-4">
    <div class="card h-100" style="border:1px solid #c7d2fe">
      <div class="card-header py-2 px-3" style="background:linear-gradient(135deg,#eef2ff,#e0e7ff);border-bottom:1px solid #c7d2fe">
        <span class="fw-bold" style="font-size:.8rem;color:#312e81">
          <i class="bi bi-plus-circle-fill me-1"></i><span id="niv-form-title">Ajouter un niveau</span>
        </span>
      </div>
      <div class="card-body p-3">
        <form method="post" id="form-niv" data-ajax-post-form>
          <?= csrf_champ() ?>
          <input type="hidden" name="action" id="niv_action" value="niv_creer">
          <input type="hidden" name="niv_ancien" id="niv_ancien" value="">
          <div class="mb-2">
            <label class="form-label" style="font-size:.75rem">Libellé</label>
            <input type="text" name="libelle_niveau" id="niv_libelle" class="form-control form-control-sm" maxlength="10" placeholder="ex. IV" required>
          </div>
          <div class="mb-3">
            <label class="form-label" style="font-size:.75rem">Ordre</label>
            <input type="number" name="ordre_niveau" id="niv_ordre" class="form-control form-control-sm" min="0" value="<?= count($niveaux) + 1 ?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label" style="font-size:.75rem">Section <span class="text-muted" style="font-weight:normal">(langue du bulletin)</span></label>
            <select name="section_niveau" id="niv_section" class="form-select form-select-sm">
              <option value="Fr" selected>Francophone</option>
              <option value="An">Anglophone</option>
            </select>
          </div>
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-sm btn-primary" id="niv-btn-submit">Ajouter</button>
            <button type="button" class="btn btn-sm btn-light d-none" id="niv-btn-annuler" onclick="reinitNivForm()">Annuler</button>
          </div>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="col-md-<?= $peut_modifier ? 8 : 12 ?>">
    <div class="card h-100" style="border:1px solid #e5e7eb">
      <div class="card-header py-2 px-3" style="background:#f8faff;border-bottom:1px solid #e5e7eb">
        <span class="fw-bold" style="font-size:.8rem;color:#374151">Niveaux — <?= count($niveaux) ?></span>
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0" style="font-size:.78rem">
          <thead style="background:#f8faff">
            <tr>
              <th style="width:60px" class="text-center">Ordre</th>
              <th style="width:100px">Libellé</th>
              <th style="width:100px" class="text-center">Section</th>
              <th style="width:90px" class="text-center">Statut</th>
              <?php if ($peut_modifier): ?><th style="width:130px" class="text-end">Actions</th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php if (!$niveaux): ?>
              <tr><td colspan="5" class="text-center text-muted py-3">Aucun niveau.</td></tr>
            <?php else: foreach ($niveaux as $n): ?>
              <tr>
                <td class="text-center text-muted fw-semibold"><?= (int) $n['OrdreNiveau'] ?></td>
                <td class="fw-semibold"><?= h($n['LibelleNiveau']) ?></td>
                <td class="text-center">
                  <?php if (($n['Section'] ?? 'Fr') === 'An'): ?>
                    <span class="badge" style="background:#dbeafe;color:#1e40af;font-size:.68rem">Anglophone</span>
                  <?php else: ?>
                    <span class="badge" style="background:#f3f4f6;color:#4b5563;font-size:.68rem">Francophone</span>
                  <?php endif; ?>
                </td>
                <td class="text-center">
                  <?php if ($n['actif']): ?>
                    <span class="badge bg-success">Actif</span>
                  <?php else: ?>
                    <span class="badge bg-secondary">Inactif</span>
                  <?php endif; ?>
                </td>
                <?php if ($peut_modifier): ?>
                <td class="text-end" style="white-space:nowrap">
                  <button type="button" class="btn btn-sm btn-light" style="padding:3px 7px" title="Modifier"
                          onclick="editNiv(<?= h(json_encode($n['LibelleNiveau'])) ?>,<?= (int) $n['OrdreNiveau'] ?>,<?= h(json_encode($n['Section'] ?? 'Fr')) ?>)">
                    <i class="bi bi-pencil" style="font-size:.72rem"></i>
                  </button>
                  <form method="post" class="d-inline" data-ajax-post-form>
                    <?= csrf_champ() ?>
                    <input type="hidden" name="niv_lib" value="<?= h($n['LibelleNiveau']) ?>">
                    <?php if ($n['actif']): ?>
                      <input type="hidden" name="action" value="niv_desactiver">
                      <button type="submit" class="btn btn-sm btn-light text-warning" style="padding:3px 7px" title="Désactiver">
                        <i class="bi bi-toggle-on" style="font-size:.72rem"></i>
                      </button>
                    <?php else: ?>
                      <input type="hidden" name="action" value="niv_activer">
                      <button type="submit" class="btn btn-sm btn-light text-success" style="padding:3px 7px" title="Activer">
                        <i class="bi bi-toggle-off" style="font-size:.72rem"></i>
                      </button>
                    <?php endif; ?>
                  </form>
                  <form method="post" class="d-inline" data-ajax-post-form onsubmit="return confirm('Supprimer ce niveau ?')">
                    <?= csrf_champ() ?>
                    <input type="hidden" name="action" value="niv_supprimer">
                    <input type="hidden" name="niv_lib" value="<?= h($n['LibelleNiveau']) ?>">
                    <button type="submit" class="btn btn-sm btn-light text-danger" style="padding:3px 7px"
                            <?= (int) $n['nb_classes'] > 0 ? 'disabled title="Des classes y sont rattachées"' : '' ?>>
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
function editNiv(lib, ordre, section) {
    document.getElementById('niv_action').value  = 'niv_modifier';
    document.getElementById('niv_ancien').value  = lib;
    document.getElementById('niv_libelle').value = lib;
    document.getElementById('niv_ordre').value   = ordre;
    document.getElementById('niv_section').value = section || 'Fr';
    document.getElementById('niv-form-title').textContent = 'Modifier le niveau';
    document.getElementById('niv-btn-submit').textContent = 'Enregistrer';
    document.getElementById('niv-btn-annuler').classList.remove('d-none');
    document.getElementById('niv_libelle').focus();
}
function reinitNivForm() {
    document.getElementById('form-niv').reset();
    document.getElementById('niv_action').value = 'niv_creer';
    document.getElementById('niv_ancien').value = '';
    document.getElementById('niv_section').value = 'Fr';
    document.getElementById('niv-form-title').textContent = 'Ajouter un niveau';
    document.getElementById('niv-btn-submit').textContent = 'Ajouter';
    document.getElementById('niv-btn-annuler').classList.add('d-none');
}
</script>

<?php endif; // onglets ?>

</div><!-- /#classes-zone -->
<?php if ($es_partiel) exit; // rien de plus dans une réponse AJAX partielle. ?>

<?php
$ajax_zone_id = 'classes-zone'; // voir layout/footer.php — initAjaxZone() y est appelé
require_once __DIR__ . '/../../layout/footer.php';
