<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_role(['DIRECTEUR']);

$onglet = in_array($_GET['onglet'] ?? '', ['liste', 'affectation'], true) ? $_GET['onglet'] : 'liste';

$q      = trim($_GET['q'] ?? '');
$statut = in_array($_GET['statut'] ?? '', ['actif', 'inactif', 'tous'], true) ? $_GET['statut'] : 'actif';

$where  = ['1=1'];
$params = [];
if ($q !== '') {
    $where[] = "(nom_ens LIKE ? OR prenom_ens LIKE ? OR mat_ens LIKE ?)";
    $like = "%$q%";
    array_push($params, $like, $like, $like);
}
if ($statut !== 'tous') {
    $where[] = "COALESCE(statut_ens,'actif') = ?";
    $params[] = $statut;
}

$enseignants = db_all(
    "SELECT * FROM enseignant WHERE " . implode(' AND ', $where) . " ORDER BY id_fonction, nom_ens", $params
);

// ── Onglet 2 : Affectation des classes — DEUX grilles indépendantes :
// piste française (enseignat_classe) et piste arabe (enseignat_classe_arabe).
// Les enseignant(e)s FR et AR sont des personnes distinctes : on affecte
// séparément dans chaque piste. Coché = affecté pour l'année active. Un(e)
// enseignant(e) peut être affecté(e) à plusieurs classes.
// L'enseignant restreint ne voit ensuite QUE ses classes de SA piste
// (fonctions.php::classes_ids_visibles($annee, 'fr'|'ar')).
$val_annee_aff = null;
$enseignants_aff = [];
$classes_aff = [];
$affectes_fr = [];
$affectes_ar = [];
if ($onglet === 'affectation') {
    $val_annee_aff = get_annee_active()['val_annee'] ?? '';
    $enseignants_aff = db_all(
        "SELECT matricule_ens, nom_ens, prenom_ens, id_fonction FROM enseignant
         WHERE COALESCE(statut_ens,'actif')='actif' ORDER BY nom_ens, prenom_ens"
    );
    if ($val_annee_aff !== '') {
        $classes_aff = db_all(
            "SELECT c.IDClasses, c.DesignationClasses, n.OrdreNiveau
             FROM classe c LEFT JOIN niveau n ON n.LibelleNiveau = c.Niveau
             ORDER BY n.OrdreNiveau, c.DesignationClasses"
        );
        foreach (db_all("SELECT matricule_ens, IDClasses FROM enseignat_classe WHERE val_annee=?", [$val_annee_aff]) as $a) {
            $affectes_fr[$a['matricule_ens'] . '_' . $a['IDClasses']] = true;
        }
        foreach (db_all("SELECT matricule_ens, IDClasses FROM enseignat_classe_arabe WHERE val_annee=?", [$val_annee_aff]) as $a) {
            $affectes_ar[$a['matricule_ens'] . '_' . $a['IDClasses']] = true;
        }
    }
}

$es_partiel = isset($_GET['partiel']);
if (!$es_partiel) {
    $titre_page = 'Enseignant(e)s';
    require_once __DIR__ . '/../../layout/header.php';
} else {
    header('Content-Type: text/html; charset=utf-8');
}
?>

<div id="enseignants-zone">

<div class="page-titre">
  <div>
    <h4><i class="bi bi-person-badge me-1 text-primary"></i>Personnel</h4>
    <div class="sub"><?= count($enseignants) ?> membre(s) du personnel</div>
  </div>
  <?php if ($onglet === 'liste'): ?>
  <a href="<?= APP_URL ?>/pages/enseignants/form.php" class="btn btn-primary btn-sm">
    <i class="bi bi-person-plus me-1"></i>Nouveau membre du personnel
  </a>
  <?php endif; ?>
</div>

<ul class="nav nav-tabs mb-2" style="border-bottom:2px solid #e5e7eb">
  <li class="nav-item">
    <a class="nav-link <?= $onglet === 'liste' ? 'active' : '' ?>" data-ajax-nav
       href="<?= APP_URL ?>/pages/enseignants/liste.php?onglet=liste">
      <i class="bi bi-list-ul me-1"></i>Liste
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $onglet === 'affectation' ? 'active' : '' ?>" data-ajax-nav
       href="<?= APP_URL ?>/pages/enseignants/liste.php?onglet=affectation">
      <i class="bi bi-diagram-3 me-1"></i>Affectation des classes
    </a>
  </li>
</ul>

<?php if ($onglet === 'liste'): ?>

<div class="card mb-2">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end" data-ajax-nav-form>
      <input type="hidden" name="onglet" value="liste">
      <div class="col-md-4">
        <div class="input-group input-group-sm">
          <span class="input-group-text"><i class="bi bi-search"></i></span>
          <input type="text" name="q" class="form-control" placeholder="Nom, matricule…" value="<?= h($q) ?>">
        </div>
      </div>
      <div class="col-md-3">
        <select name="statut" class="form-select form-select-sm" data-ajax-nav-auto>
          <option value="actif" <?= $statut === 'actif' ? 'selected' : '' ?>>Actifs seulement</option>
          <option value="inactif" <?= $statut === 'inactif' ? 'selected' : '' ?>>Inactifs seulement</option>
          <option value="tous" <?= $statut === 'tous' ? 'selected' : '' ?>>Tous</option>
        </select>
      </div>
      <div class="col-auto d-flex gap-1">
        <button class="btn btn-primary btn-sm"><i class="bi bi-search"></i></button>
        <?php if ($q): ?><a data-ajax-nav href="<?= APP_URL ?>/pages/enseignants/liste.php?onglet=liste&statut=<?= $statut ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-lg"></i></a><?php endif; ?>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-abz table-hover align-middle mb-0">
      <thead>
        <tr>
          <th>Nom</th><th>Fonction</th><th>Grade</th><th>Sexe</th><th>Téléphone</th><th>Statut</th><th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($enseignants)): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">
            <i class="bi bi-inbox" style="font-size:2rem;opacity:.3;display:block;margin-bottom:.4rem"></i>Aucun membre du personnel trouvé.
          </td></tr>
        <?php else: foreach ($enseignants as $e):
          $actif = ($e['statut_ens'] ?? 'actif') === 'actif';
          $grade = $e['id_grade'] ? db_val("SELECT libelle_grade FROM grade_enseignant WHERE code_grade=?", [$e['id_grade']]) : null;
        ?>
          <tr>
            <td>
              <div class="d-flex align-items-center gap-2">
                <div class="avatar"><?= h(mb_strtoupper(mb_substr($e['nom_ens'], 0, 1))) ?></div>
                <a href="<?= APP_URL ?>/pages/enseignants/voir.php?mat=<?= (int) $e['matricule_ens'] ?>"
                   class="fw-semibold text-decoration-none" style="font-size:.82rem">
                  <?= h(mb_strtoupper($e['nom_ens'])) ?> <?= h($e['prenom_ens'] ?? '') ?>
                </a>
              </div>
            </td>
            <td><span class="badge-code"><?= h(libelle_role($e['id_fonction'] ?? '')) ?></span></td>
            <td style="font-size:.78rem"><?= h($grade ?: '—') ?></td>
            <td style="font-size:.78rem"><?= h($e['sexe_ens'] ?: '—') ?></td>
            <td style="font-size:.78rem"><?= h($e['tel_ens'] ?: '—') ?></td>
            <td>
              <span class="badge" style="background:<?= $actif ? '#dcfce7' : '#fee2e2' ?>;color:<?= $actif ? '#166534' : '#991b1b' ?>;font-size:.7rem">
                <?= $actif ? 'Actif' : 'Inactif' ?>
              </span>
            </td>
            <td class="text-end">
              <a href="<?= APP_URL ?>/pages/enseignants/voir.php?mat=<?= (int) $e['matricule_ens'] ?>"
                 class="btn btn-sm" style="background:#eef2ff;color:#1e4fd8;padding:3px 7px" title="Voir">
                <i class="bi bi-eye" style="font-size:.78rem"></i>
              </a>
              <?php if (!fiche_gerable((int) $e['matricule_ens'], (string) ($e['id_fonction'] ?? ''))): ?>
                <span class="text-muted ms-1" style="font-size:.75rem" title="<?= h(refus_compte_non_gerable((string) $e['id_fonction'])) ?>"><i class="bi bi-lock"></i></span>
              <?php else: ?>
              <a href="<?= APP_URL ?>/pages/enseignants/form.php?mat=<?= (int) $e['matricule_ens'] ?>"
                 class="btn btn-sm" style="background:#eef7ee;color:#15803d;padding:3px 7px" title="Modifier">
                <i class="bi bi-pencil-square" style="font-size:.78rem"></i>
              </a>
              <?php if ((string) $e['matricule_ens'] !== (string) matricule_ens_courant()): ?>
              <form method="post" action="<?= APP_URL ?>/pages/enseignants/statut.php?mat=<?= (int) $e['matricule_ens'] ?>" class="d-inline"
                    data-ajax-post-form onsubmit="return confirm('<?= $actif ? 'Désactiver' : 'Réactiver' ?> ce membre du personnel ?')">
                <?= csrf_champ() ?>
                <button type="submit" class="btn btn-sm" style="background:<?= $actif ? '#fef2f2' : '#eef7ee' ?>;color:<?= $actif ? '#dc2626' : '#15803d' ?>;padding:3px 7px" title="<?= $actif ? 'Désactiver' : 'Réactiver' ?>">
                  <i class="bi bi-<?= $actif ? 'x-circle' : 'check-circle' ?>" style="font-size:.78rem"></i>
                </button>
              </form>
              <?php endif; ?>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php endif; // onglet liste ?>

<?php if ($onglet === 'affectation'): ?>

<?php if ($val_annee_aff === ''): ?>
  <div class="alert alert-warning d-flex align-items-center gap-2 py-2">
    <i class="bi bi-exclamation-triangle"></i>
    <span>Aucune année scolaire active — activez une année dans Paramètres pour affecter des classes.</span>
  </div>
<?php else: ?>
  <p class="text-muted" style="font-size:.8rem">
    Classe(s) où chaque enseignant(e) enseigne — année <?= h($val_annee_aff) ?>. Deux grilles distinctes :
    <strong>piste française</strong> (notes/bulletins compétences) et <strong>piste arabe</strong>. Les
    enseignant(e)s des deux pistes sont des personnes différentes : cochez chacun(e) dans SA piste.
    Un(e) enseignant(e) non affecté(e) ne verra pas la classe dans la piste correspondante (saisie des notes,
    bulletins, statistiques, conseil de classe, élèves…). Directeur/Secrétaire/Fondateur voient toujours tout.
  </p>
  <?php if (empty($classes_aff)): ?>
    <div class="alert alert-info py-2"><i class="bi bi-info-circle me-1"></i>Aucune classe créée pour le moment.</div>
  <?php elseif (empty($enseignants_aff)): ?>
    <div class="alert alert-info py-2"><i class="bi bi-info-circle me-1"></i>Aucun membre du personnel actif.</div>
  <?php else: ?>
  <form method="post" action="<?= APP_URL ?>/pages/enseignants/affectation_save.php" data-ajax-post-form>
    <?= csrf_champ() ?>
    <input type="hidden" name="val_annee" value="<?= h($val_annee_aff) ?>">
    <?php
    // Rend une grille enseignant(e)s × classes pour une piste donnée.
    $grille_affectation = function (string $champ, array $coches, string $titre, string $icone) use ($classes_aff, $enseignants_aff) { ?>
      <div class="card mb-3">
        <div class="card-header py-2 fw-semibold"><i class="bi bi-<?= $icone ?> me-1"></i><?= h($titre) ?></div>
        <div class="table-responsive">
          <table class="table table-abz table-hover align-middle mb-0" style="font-size:.8rem">
            <thead>
              <tr>
                <th style="min-width:180px">Enseignant(e)</th>
                <?php foreach ($classes_aff as $c): ?>
                  <th class="text-center" style="white-space:nowrap"><?= h($c['DesignationClasses']) ?></th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($enseignants_aff as $e): $mat = (int) $e['matricule_ens']; ?>
                <tr>
                  <td>
                    <?= h(mb_strtoupper($e['nom_ens'])) ?> <?= h($e['prenom_ens'] ?? '') ?>
                    <div class="text-muted" style="font-size:.72rem"><?= h(libelle_role($e['id_fonction'] ?? '')) ?></div>
                  </td>
                  <?php foreach ($classes_aff as $c): $idc = (int) $c['IDClasses']; ?>
                    <td class="text-center">
                      <input type="checkbox" class="form-check-input" name="<?= $champ ?>[]" value="<?= $mat ?>_<?= $idc ?>"
                             <?= isset($coches[$mat . '_' . $idc]) ? 'checked' : '' ?>>
                    </td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php };
    $grille_affectation('affect_fr', $affectes_fr, 'Piste française (compétences)', 'translate');
    $grille_affectation('affect_ar', $affectes_ar, 'Piste arabe', 'translate');
    ?>
    <div class="mt-2">
      <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Enregistrer l'affectation</button>
    </div>
  </form>
  <?php endif; ?>
<?php endif; ?>

<?php endif; // onglet affectation ?>

</div><!-- /#enseignants-zone -->
<?php if ($es_partiel) exit; // rien de plus dans une réponse AJAX partielle. ?>

<?php
$ajax_zone_id = 'enseignants-zone'; // voir layout/footer.php — initAjaxZone() y est appelé
require_once __DIR__ . '/../../layout/footer.php';
