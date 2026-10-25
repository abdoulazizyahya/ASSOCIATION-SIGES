<?php
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../noyau/connexion.php';
require_once __DIR__ . '/../../../noyau/fonctions.php';
exiger_role(['DIRECTEUR']);

$onglet = in_array($_GET['onglet'] ?? '', ['liste', 'affectation'], true) ? $_GET['onglet'] : 'liste';

$q      = trim($_GET['q'] ?? '');
$statut = in_array($_GET['statut'] ?? '', ['actif', 'inactif', 'tous'], true) ? $_GET['statut'] : 'actif';

$where  = ['1=1'];
if (!voit_tous_les_comptes()) $where[] = sql_hors_compte_systeme('');   // fiche du compte propriétaire
$params = [];
if ($q !== '') {
    // nom, matricule, téléphone, identifiant du compte (u = table user, jointe plus bas)
    $where[] = "(nom_ens LIKE ? OR prenom_ens LIKE ? OR mat_ens LIKE ? OR tel_ens LIKE ? OR u.login_user LIKE ?)";
    array_push($params, ...array_fill(0, 5, "%$q%"));
}
if ($statut === 'actif') {
    $where[] = "COALESCE(statut_ens,'actif') = 'actif'";
} elseif ($statut === 'inactif') {
    // Inactifs ET anciens (mutés, partis, retraités — migration v65)
    $where[] = "COALESCE(statut_ens,'actif') NOT IN ('actif', 'systeme')";
}

// Tri par colonne et pagination (17/10/2026, comme au secondaire) ; compte de connexion
// (table user) joint pour la colonne « Compte » et la recherche par identifiant.
$join_compte = "LEFT JOIN (SELECT matricule_ens, MIN(id_user) AS id_user FROM user WHERE matricule_ens IS NOT NULL GROUP BY matricule_ens) uc
                  ON uc.matricule_ens = enseignant.matricule_ens
                LEFT JOIN user u ON u.id_user = uc.id_user";
$tris = [
    'matricule' => ["NULLIF(mat_ens, '') IS NULL", 'mat_ens'],
    'nom'       => [null, 'nom_ens', 'prenom_ens'],
    'fonction'  => [null, 'id_fonction'],
    'tel'       => ["NULLIF(tel_ens, '') IS NULL", 'tel_ens'],
    'compte'    => ['u.login_user IS NULL', 'u.login_user'],
    'statut'    => [null, "COALESCE(statut_ens, 'actif')"],
];
$tri  = isset($tris[$_GET['tri'] ?? '']) ? $_GET['tri'] : 'fonction';
$sens = ($_GET['sens'] ?? '') === 'desc' ? 'DESC' : 'ASC';
[$vide, $cols] = [$tris[$tri][0], array_slice($tris[$tri], 1)];
$ordre = ($vide ? $vide . ', ' : '') . implode(', ', array_map(fn($c) => "$c $sens", $cols)) . ', nom_ens, prenom_ens';
$pp_choix = [25, 50, 100, 0];   // 0 = tout afficher
$pp       = in_array((int) ($_GET['pp'] ?? 25), $pp_choix, true) ? (int) ($_GET['pp'] ?? 25) : 25;
$sql_where = implode(' AND ', $where);
$total    = (int) db_val("SELECT COUNT(*) FROM enseignant $join_compte WHERE $sql_where", $params);
$nb_pages = $pp ? max(1, (int) ceil($total / $pp)) : 1;
$page     = min($nb_pages, max(1, (int) ($_GET['page'] ?? 1)));
$enseignants = db_all(
    "SELECT enseignant.*, u.id_user, u.login_user, u.actif AS compte_actif FROM enseignant $join_compte
     WHERE $sql_where ORDER BY $ordre" . ($pp ? ' LIMIT ' . $pp . ' OFFSET ' . (($page - 1) * $pp) : ''), $params
);
$gere_comptes = in_array(role_connecte(), ['DIRECTEUR', 'FONDATEUR'], true) || voit_tous_les_comptes();
$sans_compte  = $gere_comptes ? (int) db_val("SELECT COUNT(*) FROM enseignant e WHERE COALESCE(e.statut_ens,'actif')='actif'
                                              AND NOT EXISTS (SELECT 1 FROM user x WHERE x.matricule_ens=e.matricule_ens)") : 0;
$url_liste = function (array $chg = []) use ($q, $statut, $tri, $sens, $pp): string {
    $p = array_merge(['onglet' => 'liste', 'q' => $q, 'statut' => $statut === 'actif' ? null : $statut, 'tri' => $tri === 'fonction' ? null : $tri,
                      'sens' => $sens === 'DESC' ? 'desc' : null, 'pp' => $pp === 25 ? null : $pp], $chg);
    return APP_URL . '/primaire/pages/enseignants/liste.php?' . http_build_query(array_filter($p, fn($v) => $v !== null && $v !== ''));
};
$th_tri = function (string $cle, string $libelle) use ($tri, $sens, $url_liste): string {
    $actif = $tri === $cle;
    $icone = $actif ? ($sens === 'ASC' ? 'bi-caret-up-fill' : 'bi-caret-down-fill') : 'bi-chevron-expand opacity-50';
    $url   = $url_liste(['tri' => $cle, 'sens' => $actif && $sens === 'ASC' ? 'desc' : null, 'page' => null]);
    return '<th><a data-ajax-nav href="' . h($url) . '" class="text-decoration-none text-reset" title="Trier par ' . h(mb_strtolower($libelle)) . '">'
         . h($libelle) . ' <i class="bi ' . $icone . '" style="font-size:.65rem"></i></a></th>';
};

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
    // Membres actifs + ceux qui ne le sont plus mais restent affectés cette année
    // (signalés, à décocher pour désigner le remplaçant) — sinon l'enregistrement
    // les retirait sans qu'on les voie (24/10/2026).
    $enseignants_aff = db_all(
        "SELECT matricule_ens, nom_ens, prenom_ens, id_fonction, COALESCE(statut_ens,'actif') AS statut_ens FROM enseignant e
         WHERE COALESCE(statut_ens,'actif')='actif'
            OR (COALESCE(statut_ens,'actif')<>'systeme' AND (
                   EXISTS (SELECT 1 FROM enseignat_classe c WHERE c.matricule_ens=e.matricule_ens AND c.val_annee=?)
                OR EXISTS (SELECT 1 FROM enseignat_classe_arabe c WHERE c.matricule_ens=e.matricule_ens AND c.val_annee=?)))
         ORDER BY nom_ens, prenom_ens", [$val_annee_aff, $val_annee_aff]
    );
    $trims_aff  = $val_annee_aff !== '' ? db_all("SELECT id_trim, libelle_trim FROM trimestre WHERE id_annee=? ORDER BY id_trim", [$val_annee_aff]) : [];
    $ordre_aff  = prim_ordre_trimestre_courant();
    $histo_aff  = $val_annee_aff !== '' ? prim_historique_affectations($val_annee_aff) : [];
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
    require_once __DIR__ . '/../../../noyau/layout/header.php';
} else {
    header('Content-Type: text/html; charset=utf-8');
}
?>

<div id="enseignants-zone">

<div class="page-titre">
  <div>
    <h4><i class="bi bi-person-badge me-1 text-primary"></i>Personnel</h4>
    <div class="sub"><?= $total ?> membre(s) du personnel</div>
  </div>
  <?php if ($onglet === 'liste'): ?>
  <div class="d-flex gap-2 flex-wrap">
    <?php if ($gere_comptes): ?>
    <?php if ($sans_compte): ?>
    <form method="post" action="<?= APP_URL ?>/primaire/pages/enseignants/compte.php" class="d-inline"
          onsubmit="return confirm('Créer un compte pour les <?= $sans_compte ?> membre(s) actif(s) sans compte ? (identifiant tiré du nom, mot de passe identique)')">
      <?= csrf_champ() ?><input type="hidden" name="action" value="creer_manquants">
      <button class="btn btn-outline-success btn-sm"><i class="bi bi-person-plus me-1"></i>Créer les comptes manquants (<?= $sans_compte ?>)</button>
    </form>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/primaire/pages/utilisateurs/liste.php" class="btn btn-outline-secondary btn-sm" title="Tous les comptes et les privilèges par utilisateur">
      <i class="bi bi-people me-1"></i>Tous les comptes
    </a>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/primaire/pages/enseignants/form.php" class="btn btn-primary btn-sm">
      <i class="bi bi-person-plus me-1"></i>Nouveau membre du personnel
    </a>
  </div>
  <?php endif; ?>
</div>

<ul class="nav nav-tabs mb-2" style="border-bottom:2px solid #e5e7eb">
  <li class="nav-item">
    <a class="nav-link <?= $onglet === 'liste' ? 'active' : '' ?>" data-ajax-nav
       href="<?= APP_URL ?>/primaire/pages/enseignants/liste.php?onglet=liste">
      <i class="bi bi-list-ul me-1"></i>Liste
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $onglet === 'affectation' ? 'active' : '' ?>" data-ajax-nav
       href="<?= APP_URL ?>/primaire/pages/enseignants/liste.php?onglet=affectation">
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
          <input type="text" name="q" class="form-control" placeholder="Nom, matricule, téléphone, identifiant…" value="<?= h($q) ?>">
        </div>
      </div>
      <div class="col-md-3">
        <select name="statut" class="form-select form-select-sm" data-ajax-nav-auto>
          <option value="actif" <?= $statut === 'actif' ? 'selected' : '' ?>>Actifs seulement</option>
          <option value="inactif" <?= $statut === 'inactif' ? 'selected' : '' ?>>Inactifs et anciens (mutés, partis…)</option>
          <option value="tous" <?= $statut === 'tous' ? 'selected' : '' ?>>Tous</option>
        </select>
      </div>
      <div class="col-auto">
        <select name="pp" class="form-select form-select-sm" data-ajax-nav-auto title="Lignes par page">
          <?php foreach ($pp_choix as $n): ?><option value="<?= $n ?>" <?= $n === $pp ? 'selected' : '' ?>><?= $n ? $n . ' par page' : 'Tout' ?></option><?php endforeach; ?>
        </select>
      </div>
      <?php if ($tri !== 'fonction'): ?><input type="hidden" name="tri" value="<?= h($tri) ?>"><?php endif; ?>
      <?php if ($sens === 'DESC'): ?><input type="hidden" name="sens" value="desc"><?php endif; ?>
      <div class="col-auto d-flex gap-1">
        <button class="btn btn-primary btn-sm"><i class="bi bi-search"></i></button>
        <?php if ($q): ?><a data-ajax-nav href="<?= APP_URL ?>/primaire/pages/enseignants/liste.php?onglet=liste&statut=<?= $statut ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-lg"></i></a><?php endif; ?>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-abz table-hover align-middle mb-0">
      <thead>
        <tr>
          <?= $th_tri('matricule', 'Matricule') ?><?= $th_tri('nom', 'Nom') ?><?= $th_tri('fonction', 'Fonction') ?><th>Grade</th>
          <?= $th_tri('tel', 'Téléphone') ?><?php if ($gere_comptes): ?><?= $th_tri('compte', 'Compte') ?><?php endif; ?><?= $th_tri('statut', 'Statut') ?><th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($enseignants)): ?>
          <tr><td colspan="<?= $gere_comptes ? 8 : 7 ?>" class="text-center text-muted py-4">
            <i class="bi bi-inbox" style="font-size:2rem;opacity:.3;display:block;margin-bottom:.4rem"></i>Aucun membre du personnel trouvé.
          </td></tr>
        <?php else: foreach ($enseignants as $e):
          $actif = ($e['statut_ens'] ?? 'actif') === 'actif';
          $grade = $e['id_grade'] ? db_val("SELECT libelle_grade FROM grade_enseignant WHERE code_grade=?", [$e['id_grade']]) : null;
        ?>
          <tr>
            <td><?php if ($e['mat_ens']): ?><span class="badge-code"><?= h($e['mat_ens']) ?></span><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
            <td>
              <div class="d-flex align-items-center gap-2">
                <div class="avatar"><?= h(mb_strtoupper(mb_substr($e['nom_ens'], 0, 1))) ?></div>
                <a href="<?= APP_URL ?>/primaire/pages/enseignants/voir.php?mat=<?= (int) $e['matricule_ens'] ?>"
                   class="fw-semibold text-decoration-none" style="font-size:.82rem">
                  <?= h(mb_strtoupper($e['nom_ens'])) ?> <?= h($e['prenom_ens'] ?? '') ?>
                </a>
              </div>
            </td>
            <td><span class="badge-code"><?= h(libelle_role($e['id_fonction'] ?? '')) ?></span></td>
            <td style="font-size:.78rem"><?= h($grade ?: '—') ?></td>
            <td style="font-size:.78rem"><?= h($e['tel_ens'] ?: '—') ?></td>
            <?php if ($gere_comptes): ?>
            <td style="font-size:.78rem">
              <a href="<?= APP_URL ?>/primaire/pages/enseignants/voir.php?mat=<?= (int) $e['matricule_ens'] ?>#compte" class="text-decoration-none">
              <?php if ($e['login_user'] !== null): ?><code><?= h($e['login_user']) ?></code><?php if (!$e['compte_actif']): ?> <span class="badge bg-secondary">désactivé</span><?php endif; ?>
              <?php else: ?><span class="badge bg-warning text-dark">sans compte</span><?php endif; ?>
              </a>
            </td>
            <?php endif; ?>
            <td>
              <span class="badge" style="background:<?= $actif ? '#dcfce7' : '#fee2e2' ?>;color:<?= $actif ? '#166534' : '#991b1b' ?>;font-size:.7rem">
                <?= h(PRIM_SITUATIONS[$e['statut_ens'] ?? 'actif'] ?? 'Inactif') ?>
              </span>
              <?php if (!$actif && !empty($e['date_depart'])): ?><div class="text-muted" style="font-size:.68rem">le <?= h(date_fr($e['date_depart'])) ?></div><?php endif; ?>
            </td>
            <td class="text-end">
              <a href="<?= APP_URL ?>/primaire/pages/enseignants/voir.php?mat=<?= (int) $e['matricule_ens'] ?>"
                 class="btn btn-sm" style="background:#eef2ff;color:#1e4fd8;padding:3px 7px" title="Voir">
                <i class="bi bi-eye" style="font-size:.78rem"></i>
              </a>
              <?php if (!fiche_gerable((int) $e['matricule_ens'], (string) ($e['id_fonction'] ?? ''))): ?>
                <span class="text-muted ms-1" style="font-size:.75rem" title="<?= h(refus_compte_non_gerable((string) $e['id_fonction'])) ?>"><i class="bi bi-lock"></i></span>
              <?php else: ?>
              <a href="<?= APP_URL ?>/primaire/pages/enseignants/form.php?mat=<?= (int) $e['matricule_ens'] ?>"
                 class="btn btn-sm" style="background:#eef7ee;color:#15803d;padding:3px 7px" title="Modifier">
                <i class="bi bi-pencil-square" style="font-size:.78rem"></i>
              </a>
              <?php if ((string) $e['matricule_ens'] !== (string) matricule_ens_courant()): ?>
              <form method="post" action="<?= APP_URL ?>/primaire/pages/enseignants/statut.php?mat=<?= (int) $e['matricule_ens'] ?>" class="d-inline"
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
  <?php if ($total): ?>
  <div class="card-footer bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div class="text-muted" style="font-size:.75rem">
      <?= $pp ? (($page - 1) * $pp + 1) . '–' . min($total, $page * $pp) . ' sur ' . $total : $total . ' au total' ?>
    </div>
    <?= str_replace('class="page-link"', 'class="page-link" data-ajax-nav', pagination_html($page, $nb_pages, $url_liste())) ?>
  </div>
  <?php endif; ?>
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
  <form method="post" action="<?= APP_URL ?>/primaire/pages/enseignants/affectation_save.php" data-ajax-post-form>
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
                    <?php if ($e['statut_ens'] !== 'actif'): ?>
                      <span class="badge bg-danger" style="font-size:.6rem"><?= h(PRIM_SITUATIONS[$e['statut_ens']] ?? 'Inactif') ?> — à remplacer</span>
                    <?php endif; ?>
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
    $noms_classes = array_column($classes_aff, 'DesignationClasses', 'IDClasses');
    ?>
    <?php if ($histo_aff): ?>
    <div class="card mb-2"><div class="card-body py-2" style="font-size:.76rem">
      <div class="fw-semibold mb-1"><i class="bi bi-clock-history me-1"></i>Changements de titulaire en cours d'année</div>
      <?php foreach ($histo_aff as $piste => $classes_h): foreach ($classes_h as $idc => $periodes): ?>
        <div><span class="badge-code"><?= $piste === 'ar' ? 'Arabe' : 'Français' ?></span> <?= h($noms_classes[$idc] ?? "Classe $idc") ?> :
          <?= implode(' · ', array_map(fn($t, $noms) => 'T' . $t . ' : ' . h(implode(', ', $noms)), array_keys($periodes), $periodes)) ?></div>
      <?php endforeach; endforeach; ?>
    </div></div>
    <?php endif; ?>
    <div class="mt-2 d-flex flex-wrap align-items-center gap-2">
      <?php if (prim_periodes_dispo() && $trims_aff): ?>
      <label for="trim-debut-aff" style="font-size:.8rem"
             title="Remplacement en cours d'année : les bulletins des trimestres précédents gardent l'ancien titulaire. 1er trimestre = correction d'une erreur d'affectation.">
        <i class="bi bi-arrow-left-right me-1 text-primary"></i>Changements valables à partir du
      </label>
      <select name="trim_debut" id="trim-debut-aff" class="form-select form-select-sm" style="width:auto">
        <?php foreach ($trims_aff as $i => $t): ?>
          <option value="<?= $i + 1 ?>" <?= $i + 1 === $ordre_aff ? 'selected' : '' ?>><?= h($t['libelle_trim']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>
      <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Enregistrer l'affectation</button>
    </div>
  </form>
  <?php endif; ?>
<?php endif; ?>

<?php endif; // onglet affectation ?>

</div><!-- /#enseignants-zone -->
<?php if ($es_partiel) exit; // rien de plus dans une réponse AJAX partielle. ?>

<?php
$ajax_zone_id = 'enseignants-zone'; // voir noyau/layout/footer.php — initAjaxZone() y est appelé
require_once __DIR__ . '/../../../noyau/layout/footer.php';
