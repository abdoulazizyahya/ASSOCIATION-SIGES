<?php
// pages/competences/liste.php — Administration du référentiel pédagogique
// (Approche Par Compétences) : Groupes de compétences → Groupes par niveau
// → Compétences → Barème par niveau. Branché sur les tables déjà présentes
// dans jaynitaare_v2_bd (groupe_competence, competence, discipline —
// héritées du legacy jaynitaare) + une nouvelle table additive
// (groupe_competence_niveau, bd/migration_v1.sql) pour l'association
// groupe↔niveau, absente du schéma legacy.
//
// Convention UI reprise de ABZ_MBE/LAM_ABZ (pages/matieres/liste.php,
// onglet « Compétences par trimestre ») : page unique à onglets (?onglet=),
// panneau de formulaire à gauche + liste à droite, bascule Ajouter/Modifier
// en JS sans recharger la page (l'enregistrement reste un POST classique).
//
// Rappel du modèle réel (voir prompt_continuite_jaynitaare_v2.md) :
//  - groupe_competence = les 6 domaines de compétences du curriculum
//    camerounais, dupliqués en français (langue='Fr') et en anglais
//    (langue='An') pour le bulletin bilingue.
//  - groupe_competence_niveau = quels groupes sont assignés à quel niveau
//    (onglet « Groupes par niveau », une seule case à cocher par groupe
//    depuis le 21/08/2026 — voir le commentaire détaillé plus bas, section
//    ONGLET 2).
//  - competence = les compétences de base évaluables dans chaque domaine
//    (ex. « Communiquer en Français »). Seules celles du côté langue='Fr'
//    sont réellement notées (confirmé par les données réelles : discipline/
//    composer_sequence ne référencent jamais les id_comp du côté 'An',
//    qui ne servent que de libellé bilingue sur les documents imprimés).
//  - discipline = le barème (points max Oral/Écrit/Pratique/Savoir-être) —
//    stocké par CLASSE dans le schéma legacy, mais **fixé par NIVEAU** en
//    pratique (confirmé par l'utilisateur) : cet écran édite un seul jeu de
//    valeurs par niveau et l'applique à toutes les classes de ce niveau,
//    plutôt que de les gérer classe par classe.
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_acces_pedagogie();
exiger_annee_active(); // Année scolaire réellement active requise (18/08/2026) — module Pédagogie/Discipline.

$role          = role_connecte();
// Directeur ET fondateur (structure pédagogique, pas la saisie de notes —
// voir ecole_contexte.php::ecriture_module_permise()).
$peut_modifier = in_array($role, ['DIRECTEUR', 'FONDATEUR'], true)
    && !(function_exists('est_lecture_seule') && est_lecture_seule());
$annee         = get_annee_active();
$val_annee     = $annee['val_annee'] ?? '';

$onglet = $_GET['onglet'] ?? 'groupes';
if (!in_array($onglet, ['groupes', 'groupes_niveau', 'competences', 'bareme'], true)) $onglet = 'groupes';

// Tous les niveaux actifs, avec ou sans classe (une classe qui sera créée
// plus tard reprend automatiquement les groupes/compétences déjà configurés).
$niveaux_liste = db_all(
    "SELECT LibelleNiveau, OrdreNiveau FROM niveau WHERE actif = 1 ORDER BY OrdreNiveau"
);

// ══════════════════════════════════════════════════════════════
//  ONGLET 1 — Groupes de compétences (catalogue global)
// ══════════════════════════════════════════════════════════════
if ($onglet === 'groupes' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    exiger_role(['DIRECTEUR']);
    csrf_verifier();
    $action = post('action');

    if ($action === 'grp_creer') {
        $lib    = post('libelle_groupe_comp');
        $langue = post('langue');
        $ordre  = (int) post('ordre_affichage');
        if ($lib !== '' && in_array($langue, ['Fr', 'An'], true)) {
            db_exec("INSERT INTO groupe_competence (libelle_groupe_comp, langue, ordre_affichage) VALUES (?, ?, ?)", [$lib, $langue, $ordre]);
            flash_set('succes', 'Groupe de compétences ajouté.');
        } else {
            flash_set('erreur', 'Libellé et langue sont requis.');
        }
        rediriger('pages/competences/liste.php?onglet=groupes');
    }

    if ($action === 'grp_modifier') {
        $id     = (int) post('grp_id');
        $lib    = post('libelle_groupe_comp');
        $langue = post('langue');
        $ordre  = (int) post('ordre_affichage');
        if ($id && $lib !== '' && in_array($langue, ['Fr', 'An'], true)) {
            db_exec("UPDATE groupe_competence SET libelle_groupe_comp=?, langue=?, ordre_affichage=? WHERE id_groupe_comp=?", [$lib, $langue, $ordre, $id]);
            flash_set('succes', 'Groupe de compétences modifié.');
        }
        rediriger('pages/competences/liste.php?onglet=groupes');
    }

    if ($action === 'grp_supprimer') {
        $id = (int) post('grp_id');
        $nb = (int) db_val("SELECT COUNT(*) FROM competence WHERE id_groupe_comp=?", [$id]);
        if ($nb > 0) {
            flash_set('erreur', "Impossible : ce groupe contient encore $nb compétence(s) — supprimez-les d'abord.");
        } else {
            db_exec("DELETE FROM groupe_competence WHERE id_groupe_comp=?", [$id]);
            flash_set('succes', 'Groupe de compétences supprimé.');
        }
        rediriger('pages/competences/liste.php?onglet=groupes');
    }
}
$groupes = db_all(
    "SELECT g.*, COUNT(c.id_comp) AS nb_comp
     FROM groupe_competence g
     LEFT JOIN competence c ON c.id_groupe_comp = g.id_groupe_comp
     GROUP BY g.id_groupe_comp
     ORDER BY g.langue, g.ordre_affichage, g.id_groupe_comp"
);
// Réutilisé par les onglets 2 (association par niveau), 3 (compétences) et
// 4 (barème) — trié par LANGUE d'abord puis ordre_affichage : `langue` ne
// désigne pas une simple étiquette bilingue de bulletin mais la section de
// l'établissement (Anglophone = compétences langue='An', Francophone =
// langue='Fr') — chaque section constitue son propre catalogue ordonné.
$tous_groupes = db_all("SELECT * FROM groupe_competence ORDER BY langue, ordre_affichage, id_groupe_comp");

// ══════════════════════════════════════════════════════════════
//  ONGLET 2 — Groupes de compétences par niveau
// ══════════════════════════════════════════════════════════════
// Simplifié le 21/08/2026 (demande explicite de l'utilisateur) : une seule
// case à cocher « Assigné » par groupe (cochée = ce groupe est utilisé pour
// ce niveau, décochée = pas utilisé) — plus de distinction « Associé »/
// « Actif » séparée, jugée sans utilité réelle : un groupe non assigné à un
// niveau et un groupe associé-mais-désactivé produisaient de toute façon le
// même résultat visible partout ailleurs (voir fonctions.php::
// groupe_competence_est_visible(), toujours en place à l'identique — un
// groupe sans compétence active pour ce niveau se masque déjà tout seul,
// automatiquement, sans qu'il faille l'éteindre manuellement ici).
// La liste des groupes proposés se filtre aussi automatiquement selon la
// SECTION du niveau choisi (`niveau.Section`, Fr/An) — un niveau anglophone
// ne propose que les groupes langue='An', jamais les groupes francophones et
// inversement (même logique que le bulletin bilingue, voir $section_en dans
// pdf/bulletin_trimestriel.php).
$f_niveau_gn = $_GET['niveau_gn'] ?? ($niveaux_liste[0]['LibelleNiveau'] ?? '');
$section_niveau_gn = $f_niveau_gn ? (string) (db_val("SELECT Section FROM niveau WHERE LibelleNiveau=?", [$f_niveau_gn]) ?: 'Fr') : 'Fr';

if ($onglet === 'groupes_niveau' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    exiger_role(['DIRECTEUR']);
    csrf_verifier();
    $niveau = post('code_niveau');
    if ($niveau) {
        $section_post = (string) (db_val("SELECT Section FROM niveau WHERE LibelleNiveau=?", [$niveau]) ?: 'Fr');
        $assignes = $_POST['assigne'] ?? [];
        foreach ($tous_groupes as $g) {
            if ($g['langue'] !== $section_post) continue; // hors section de ce niveau, ignoré
            $idg = (int) $g['id_groupe_comp'];
            if (!empty($assignes[$idg])) {
                db_exec(
                    "INSERT INTO groupe_competence_niveau (code_niveau, id_groupe_comp, actif) VALUES (?, ?, 1)
                     ON DUPLICATE KEY UPDATE actif=1",
                    [$niveau, $idg]
                );
            } else {
                db_exec("DELETE FROM groupe_competence_niveau WHERE code_niveau=? AND id_groupe_comp=?", [$niveau, $idg]);
            }
        }
        flash_set('succes', "Groupes de compétences mis à jour pour le niveau $niveau.");
    }
    rediriger('pages/competences/liste.php?onglet=groupes_niveau&niveau_gn=' . urlencode($niveau));
}

$assoc_niveau_map = [];
if ($f_niveau_gn) {
    foreach (db_all("SELECT id_groupe_comp, actif FROM groupe_competence_niveau WHERE code_niveau=?", [$f_niveau_gn]) as $a) {
        $assoc_niveau_map[(int) $a['id_groupe_comp']] = (int) $a['actif'];
    }
}
// Groupes de la section (Fr/An) du niveau sélectionné uniquement.
$groupes_niveau_gn = array_values(array_filter($tous_groupes, fn($g) => $g['langue'] === $section_niveau_gn));
// Pour affichage (« bonne visibilité ») : nombre de compétences actives/total
// par groupe pour ce niveau, côté Fr uniquement (seul côté noté — voir
// onglet Barème). Sert à repérer d'un coup d'œil un groupe déjà « vidé ».
$comp_actives_par_groupe = [];
if ($f_niveau_gn && $val_annee) {
    $classe_ref_gn = (int) db_val("SELECT MIN(IDClasses) FROM classe WHERE Niveau=?", [$f_niveau_gn]);
    if ($classe_ref_gn) {
        foreach (db_all(
            "SELECT c.id_groupe_comp,
                    COUNT(*) AS nb_total,
                    SUM(CASE WHEN d.actif IS NULL OR d.actif=1 THEN 1 ELSE 0 END) AS nb_actives
             FROM competence c
             JOIN groupe_competence g ON g.id_groupe_comp = c.id_groupe_comp
             LEFT JOIN discipline d ON d.id_comp = c.id_comp AND d.IDClasses = ? AND d.annee_scol = ?
             WHERE g.langue = 'Fr'
             GROUP BY c.id_groupe_comp",
            [$classe_ref_gn, $val_annee]
        ) as $r) {
            $comp_actives_par_groupe[(int) $r['id_groupe_comp']] = [(int) $r['nb_actives'], (int) $r['nb_total']];
        }
    }
}

// ══════════════════════════════════════════════════════════════
//  ONGLET 3 — Compétences
// ══════════════════════════════════════════════════════════════
$f_groupe = (int) ($_GET['groupe'] ?? ($tous_groupes[0]['id_groupe_comp'] ?? 0));

if ($onglet === 'competences' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    exiger_role(['DIRECTEUR']);
    csrf_verifier();
    $action    = post('action');
    $id_groupe = (int) post('id_groupe_comp');

    if ($action === 'comp_creer') {
        $code = post('code_comp');
        $nom  = post('nom_comp');
        if ($id_groupe && $code !== '' && $nom !== '') {
            db_exec("INSERT INTO competence (code_comp, nom_comp, id_groupe_comp) VALUES (?, ?, ?)", [$code, $nom, $id_groupe]);
            flash_set('succes', 'Compétence ajoutée.');
        } else {
            flash_set('erreur', 'Groupe, code et libellé sont requis.');
        }
        rediriger("pages/competences/liste.php?onglet=competences&groupe=$id_groupe");
    }

    if ($action === 'comp_modifier') {
        $id   = (int) post('comp_id');
        $code = post('code_comp');
        $nom  = post('nom_comp');
        if ($id && $code !== '' && $nom !== '') {
            db_exec("UPDATE competence SET code_comp=?, nom_comp=? WHERE id_comp=?", [$code, $nom, $id]);
            flash_set('succes', 'Compétence modifiée.');
        }
        rediriger("pages/competences/liste.php?onglet=competences&groupe=$id_groupe");
    }

    if ($action === 'comp_supprimer') {
        $id        = (int) post('comp_id');
        $nb_notes  = (int) db_val("SELECT COUNT(*) FROM composer_sequence WHERE id_comp=?", [$id]);
        $nb_bareme = (int) db_val("SELECT COUNT(*) FROM discipline WHERE id_comp=?", [$id]);
        if ($nb_notes > 0) {
            flash_set('erreur', "Impossible : $nb_notes note(s) d'élèves existent déjà pour cette compétence.");
        } elseif ($nb_bareme > 0) {
            flash_set('erreur', "Impossible : un barème est déjà défini pour cette compétence — retirez-le d'abord (onglet Barème).");
        } else {
            db_exec("DELETE FROM competence WHERE id_comp=?", [$id]);
            flash_set('succes', 'Compétence supprimée.');
        }
        rediriger("pages/competences/liste.php?onglet=competences&groupe=$id_groupe");
    }
}

$competences = $f_groupe
    ? db_all("SELECT * FROM competence WHERE id_groupe_comp=? ORDER BY code_comp", [$f_groupe])
    : [];

// ══════════════════════════════════════════════════════════════
//  ONGLET 4 — Barème par NIVEAU (discipline : points Oral/Écrit/
//  Pratique/Savoir-être). `discipline` reste stockée par classe dans le
//  schéma (héritage legacy), mais cet écran édite une seule fois par
//  niveau et réplique automatiquement sur toutes les classes du niveau —
//  fixé ainsi sur demande explicite de l'utilisateur. Limité aux
//  compétences du côté langue='Fr' (seul côté réellement noté).
// ══════════════════════════════════════════════════════════════
$f_niveau_bareme = $_GET['niveau'] ?? '';
// Copie du barème d'un autre niveau (aperçu avant enregistrement) — demande
// utilisateur du 10/08/2026 : pratique pour démarrer un niveau qui n'a pas
// encore de barème en reprenant celui d'un niveau proche, puis en l'ajustant
// avant de cliquer Enregistrer. N'écrit rien tant que ce n'est pas fait.
$f_copier_de = $_GET['copier_de'] ?? '';

if ($onglet === 'bareme' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    exiger_role(['DIRECTEUR']);
    csrf_verifier();
    $niveau = post('code_niveau');
    if ($niveau && $val_annee) {
        $classes_niveau = db_all("SELECT IDClasses FROM classe WHERE Niveau=?", [$niveau]);
        $lignes         = $_POST['bareme'] ?? [];
        foreach ($lignes as $id_comp => $vals) {
            $id_comp     = (int) $id_comp;
            $orale       = (float) ($vals['orale'] ?? 0);
            $ecrite      = (float) ($vals['ecrite'] ?? 0);
            $pratique    = (float) ($vals['pratique'] ?? 0);
            $savoir_etre = (float) ($vals['savoir_etre'] ?? 0);
            $total       = $orale + $ecrite + $pratique + $savoir_etre;
            $actif       = !empty($vals['actif']) ? 1 : 0;
            foreach ($classes_niveau as $cl) {
                db_exec(
                    "INSERT INTO discipline (IDClasses, id_comp, annee_scol, orale, ecrite, pratique, savoir_etre, total_points, actif)
                     VALUES (?,?,?,?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE orale=VALUES(orale), ecrite=VALUES(ecrite), pratique=VALUES(pratique),
                                             savoir_etre=VALUES(savoir_etre), total_points=VALUES(total_points), actif=VALUES(actif)",
                    [(int) $cl['IDClasses'], $id_comp, $val_annee, $orale, $ecrite, $pratique, $savoir_etre, $total, $actif]
                );
            }
        }
        flash_set('succes', "Barème enregistré pour le niveau $niveau (" . count($classes_niveau) . ' classe(s) mise(s) à jour).');
    }
    rediriger('pages/competences/liste.php?onglet=bareme&niveau=' . urlencode($niveau));
}

// Groupes assignés à ce niveau (onglet « Groupes par niveau ») — depuis le
// 21/08/2026 (demande explicite) le barème n'affiche PLUS que ces groupes-là
// (fini le "tout visible par défaut si non configuré") : si aucun groupe
// n'est assigné, l'onglet Barème n'affiche rien et invite à assigner
// d'abord (voir le rendu plus bas et fonctions.php::bareme_par_niveau()).
$classes_du_niveau       = [];
$bareme_par_groupe       = [];
$bareme_divergent        = false;
$niveau_a_groupes_assignes = false;

$mode_copie          = false;
$niveau_copie_source = '';
if ($f_niveau_bareme && $val_annee) {
    // Si une copie depuis un autre niveau est demandée, les valeurs
    // affichées (pas encore enregistrées) viennent de la classe de référence
    // de CE niveau source à la place — le formulaire reste un aperçu
    // modifiable, rien n'est écrit avant un clic sur « Enregistrer ». Les
    // groupes affichés restent ceux assignés au niveau CIBLE (voir
    // bareme_par_niveau()) : seules les valeurs viennent d'ailleurs.
    $id_classe_valeurs = null;
    if ($f_copier_de && $f_copier_de !== $f_niveau_bareme) {
        $classes_source = db_all("SELECT IDClasses FROM classe WHERE Niveau=? ORDER BY IDClasses", [$f_copier_de]);
        if ($classes_source) {
            $id_classe_valeurs   = (int) $classes_source[0]['IDClasses'];
            $mode_copie          = true;
            $niveau_copie_source = $f_copier_de;
        }
    }

    $bareme_niveau             = bareme_par_niveau($f_niveau_bareme, $val_annee, $id_classe_valeurs);
    $classes_du_niveau         = $bareme_niveau['classes'];
    $bareme_par_groupe         = $bareme_niveau['groupes'];
    $bareme_divergent          = $bareme_niveau['divergent'];
    $niveau_a_groupes_assignes = $bareme_niveau['assigne'];
}

// Total général des points du niveau — somme du « Total » de chaque
// compétence active (recalculé en JS à chaque modification, voir plus bas) —
// même affichage que pages/matieres_arabe/liste.php (onglet Barème, côté arabe).
$total_general = 0.0;
foreach ($bareme_par_groupe as $grp) {
    foreach ($grp['lignes'] as $b) {
        $active = $b['actif'] === null || (int) $b['actif'] === 1;
        if ($active) $total_general += (float) ($b['total_points'] ?? 0);
    }
}

$es_partiel = isset($_GET['partiel']);
if (!$es_partiel) {
    $titre_page = 'Groupes de compétences';
    require_once __DIR__ . '/../../layout/header.php';
} else {
    header('Content-Type: text/html; charset=utf-8');
}
?>

<div id="competences-zone">

<div class="page-titre d-flex justify-content-between align-items-center flex-wrap gap-2">
  <div>
    <h4><i class="bi bi-diagram-3 me-1 text-primary"></i>Pédagogie — Compétences (APC)</h4>
    <div class="sub">Référentiel des groupes de compétences, compétences et barèmes — année <?= h($val_annee) ?></div>
  </div>
</div>

<ul class="nav nav-tabs mb-3" style="border-bottom:2px solid #e5e7eb">
  <li class="nav-item">
    <a class="nav-link <?= $onglet === 'groupes' ? 'active' : '' ?>" data-ajax-nav
       href="<?= APP_URL ?>/pages/competences/liste.php?onglet=groupes">
      <i class="bi bi-collection me-1"></i>Groupes de compétences
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $onglet === 'groupes_niveau' ? 'active' : '' ?>" data-ajax-nav
       href="<?= APP_URL ?>/pages/competences/liste.php?onglet=groupes_niveau<?= $f_niveau_gn ? '&niveau_gn=' . urlencode($f_niveau_gn) : '' ?>">
      <i class="bi bi-layers me-1"></i>Groupes par niveau
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $onglet === 'competences' ? 'active' : '' ?>" data-ajax-nav
       href="<?= APP_URL ?>/pages/competences/liste.php?onglet=competences<?= $f_groupe ? "&groupe=$f_groupe" : '' ?>">
      <i class="bi bi-list-check me-1"></i>Compétences
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $onglet === 'bareme' ? 'active' : '' ?>" data-ajax-nav
       href="<?= APP_URL ?>/pages/competences/liste.php?onglet=bareme<?= $f_niveau_bareme ? '&niveau=' . urlencode($f_niveau_bareme) : '' ?>">
      <i class="bi bi-rulers me-1"></i>Barème par niveau
    </a>
  </li>
</ul>

<?php if ($onglet === 'groupes'): ?>
<!-- ══════════════════════════════════════════════
     ONGLET 1 — Groupes de compétences
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
            <label class="form-label" style="font-size:.75rem">Libellé</label>
            <textarea name="libelle_groupe_comp" id="grp_libelle" class="form-control form-control-sm" rows="3" required></textarea>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-7">
              <label class="form-label" style="font-size:.75rem">Langue</label>
              <select name="langue" id="grp_langue" class="form-select form-select-sm" required>
                <option value="Fr">Français</option>
                <option value="An">Anglais</option>
              </select>
            </div>
            <div class="col-5">
              <label class="form-label" style="font-size:.75rem">Ordre</label>
              <input type="number" name="ordre_affichage" id="grp_ordre" class="form-control form-control-sm" min="0" value="0" required>
            </div>
          </div>
          <div class="form-text mb-2" style="font-size:.68rem">
            L'ordre détermine la place du groupe partout où il est listé (cet écran, puis à terme saisie/bulletins/stats).
            Pensez à donner le <strong>même ordre</strong> à un groupe Fr et à son équivalent An.
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
        <span class="fw-bold" style="font-size:.8rem;color:#374151">Groupes de compétences — <?= count($groupes) ?></span>
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0" style="font-size:.78rem">
          <thead style="background:#f8faff">
            <tr>
              <th style="width:55px" class="text-center">Ordre</th>
              <th style="width:60px">Langue</th>
              <th>Libellé</th>
              <th style="width:110px" class="text-center">Compétences</th>
              <?php if ($peut_modifier): ?><th style="width:80px" class="text-end">Actions</th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php if (!$groupes): ?>
              <tr><td colspan="5" class="text-center text-muted py-3">Aucun groupe de compétences.</td></tr>
            <?php else: foreach ($groupes as $g): ?>
              <tr>
                <td class="text-center text-muted fw-semibold"><?= (int) $g['ordre_affichage'] ?></td>
                <td><span class="badge <?= $g['langue'] === 'Fr' ? 'bg-primary' : 'bg-secondary' ?>"><?= h($g['langue']) ?></span></td>
                <td style="white-space:normal"><?= h($g['libelle_groupe_comp']) ?></td>
                <td class="text-center">
                  <a href="<?= APP_URL ?>/pages/competences/liste.php?onglet=competences&groupe=<?= (int) $g['id_groupe_comp'] ?>">
                    <?= (int) $g['nb_comp'] ?>
                  </a>
                </td>
                <?php if ($peut_modifier): ?>
                <td class="text-end">
                  <button type="button" class="btn btn-sm btn-light" style="padding:3px 7px"
                          onclick="editGrp(<?= (int) $g['id_groupe_comp'] ?>,<?= h(json_encode($g['libelle_groupe_comp'])) ?>,<?= h(json_encode($g['langue'])) ?>,<?= (int) $g['ordre_affichage'] ?>)">
                    <i class="bi bi-pencil" style="font-size:.72rem"></i>
                  </button>
                  <form method="post" class="d-inline" data-ajax-post-form onsubmit="return confirm('Supprimer ce groupe de compétences ?')">
                    <?= csrf_champ() ?>
                    <input type="hidden" name="action" value="grp_supprimer">
                    <input type="hidden" name="grp_id" value="<?= (int) $g['id_groupe_comp'] ?>">
                    <button type="submit" class="btn btn-sm btn-light text-danger" style="padding:3px 7px" <?= $g['nb_comp'] > 0 ? 'disabled title="Contient des compétences"' : '' ?>>
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
function editGrp(id, lib, langue, ordre) {
    document.getElementById('grp_action').value  = 'grp_modifier';
    document.getElementById('grp_id').value      = id;
    document.getElementById('grp_libelle').value = lib;
    document.getElementById('grp_langue').value  = langue;
    document.getElementById('grp_ordre').value   = ordre;
    document.getElementById('grp-form-title').textContent = 'Modifier le groupe';
    document.getElementById('grp-btn-submit').textContent = 'Enregistrer';
    document.getElementById('grp-btn-annuler').classList.remove('d-none');
    document.getElementById('grp_libelle').focus();
}
function reinitGrpForm() {
    document.getElementById('form-grp').reset();
    document.getElementById('grp_action').value = 'grp_creer';
    document.getElementById('grp_id').value     = '0';
    document.getElementById('grp_ordre').value  = '0';
    document.getElementById('grp-form-title').textContent = 'Ajouter un groupe';
    document.getElementById('grp-btn-submit').textContent = 'Ajouter';
    document.getElementById('grp-btn-annuler').classList.add('d-none');
}
</script>

<?php elseif ($onglet === 'groupes_niveau'): ?>
<!-- ══════════════════════════════════════════════
     ONGLET 2 — Groupes de compétences par niveau
══════════════════════════════════════════════ -->
<form method="get" class="d-flex align-items-center gap-3 flex-wrap mb-3" data-ajax-nav-form>
  <input type="hidden" name="onglet" value="groupes_niveau">
  <div style="min-width:220px">
    <select name="niveau_gn" class="form-select form-select-sm" data-ajax-nav-auto>
      <?php foreach ($niveaux_liste as $n): ?>
        <option value="<?= h($n['LibelleNiveau']) ?>" <?= $f_niveau_gn === $n['LibelleNiveau'] ? 'selected' : '' ?>>
          Niveau <?= h($n['LibelleNiveau']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
</form>

<?php if (!$niveaux_liste): ?>
  <div class="alert alert-light text-muted text-center py-4">Aucun niveau avec une classe rattachée.</div>
<?php elseif (!$f_niveau_gn): ?>
  <div class="text-center text-muted py-5">
    <i class="bi bi-cursor" style="font-size:2.5rem;display:block;opacity:.15;margin-bottom:.5rem"></i>
    Sélectionnez un niveau.
  </div>
<?php else: ?>

<form method="post" data-ajax-post-form>
  <?= csrf_champ() ?>
  <input type="hidden" name="code_niveau" value="<?= h($f_niveau_gn) ?>">
  <div class="card" style="border:1px solid #e5e7eb">
    <div class="card-header py-2 px-3 d-flex justify-content-between align-items-center flex-wrap gap-2" style="background:#f8faff;border-bottom:1px solid #e5e7eb">
      <span class="fw-bold" style="font-size:.8rem;color:#374151">
        Groupes de compétences — Niveau <?= h($f_niveau_gn) ?>
        <span class="badge <?= $section_niveau_gn === 'Fr' ? 'bg-primary' : 'bg-secondary' ?> ms-1">
          Section <?= $section_niveau_gn === 'Fr' ? 'Francophone' : 'Anglophone' ?>
        </span>
      </span>
      <?php if ($peut_modifier): ?>
      <div class="d-flex gap-2">
        <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-gn-tout-cocher"><i class="bi bi-check-all me-1"></i>Tout cocher</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-gn-tout-decocher"><i class="bi bi-x-square me-1"></i>Tout décocher</button>
        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check-lg me-1"></i>Enregistrer</button>
      </div>
      <?php endif; ?>
    </div>
    <div class="table-responsive">
      <table class="table table-sm table-hover mb-0" style="font-size:.78rem">
        <thead style="background:#f8faff">
          <tr>
            <th style="width:55px" class="text-center">Ordre</th>
            <th>Libellé</th>
            <th style="width:110px" class="text-center">Compétences</th>
            <th style="width:90px" class="text-center">Assigné</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$groupes_niveau_gn): ?>
            <tr><td colspan="4" class="text-center text-muted py-3">Aucun groupe de compétences dans cette section.</td></tr>
          <?php endif; ?>
          <?php foreach ($groupes_niveau_gn as $g): $idg = (int) $g['id_groupe_comp']; $assigne = (bool) ($assoc_niveau_map[$idg] ?? 0); [$nb_act, $nb_tot] = $comp_actives_par_groupe[$idg] ?? [0, 0]; ?>
            <tr>
              <td class="text-center text-muted"><?= (int) $g['ordre_affichage'] ?></td>
              <td style="white-space:normal"><?= h($g['libelle_groupe_comp']) ?></td>
              <td class="text-center" style="font-size:.72rem">
                <?php if ($g['langue'] !== 'Fr'): ?>
                  <span class="text-muted">—</span>
                <?php elseif ($nb_tot === 0): ?>
                  <span class="text-muted">aucune</span>
                <?php elseif ($nb_act === 0): ?>
                  <span class="badge" style="background:#fef3c7;color:#92400e">0/<?= $nb_tot ?> active</span>
                <?php else: ?>
                  <span class="badge bg-light text-dark border"><?= $nb_act ?>/<?= $nb_tot ?> active(s)</span>
                <?php endif; ?>
              </td>
              <td class="text-center">
                <?php if ($peut_modifier): ?>
                  <input type="checkbox" class="form-check-input chk-gn-assigne" name="assigne[<?= $idg ?>]" value="1" <?= $assigne ? 'checked' : '' ?>>
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
</form>

<?php if ($peut_modifier): ?>
<script>
// Tout cocher / Tout décocher — assigne ou retire tous les groupes de la
// section d'un coup (demande du 21/08/2026) plutôt que de cliquer chaque
// case une à une.
document.getElementById('btn-gn-tout-cocher')?.addEventListener('click', () => {
  document.querySelectorAll('.chk-gn-assigne').forEach(chk => chk.checked = true);
});
document.getElementById('btn-gn-tout-decocher')?.addEventListener('click', () => {
  document.querySelectorAll('.chk-gn-assigne').forEach(chk => chk.checked = false);
});
</script>
<?php endif; ?>

<?php endif; // niveaux_liste / f_niveau_gn ?>

<?php elseif ($onglet === 'competences'): ?>
<!-- ══════════════════════════════════════════════
     ONGLET 3 — Compétences
══════════════════════════════════════════════ -->
<form method="get" class="d-flex align-items-center gap-3 flex-wrap mb-3" data-ajax-nav-form>
  <input type="hidden" name="onglet" value="competences">
  <div style="min-width:320px">
    <select name="groupe" class="form-select form-select-sm" data-ajax-nav-auto>
      <?php foreach ($tous_groupes as $g): ?>
        <option value="<?= (int) $g['id_groupe_comp'] ?>" <?= $f_groupe === (int) $g['id_groupe_comp'] ? 'selected' : '' ?>>
          [<?= h($g['langue']) ?>] <?= h(mb_strimwidth($g['libelle_groupe_comp'], 0, 70, '…')) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
</form>

<?php if (!$tous_groupes): ?>
  <div class="alert alert-light text-muted text-center py-4">Créez d'abord un groupe de compétences (onglet précédent).</div>
<?php else: ?>

<div class="row g-2" style="align-items:flex-start">

  <?php if ($peut_modifier): ?>
  <div class="col-md-4">
    <div class="card h-100" style="border:1px solid #c7d2fe">
      <div class="card-header py-2 px-3" style="background:linear-gradient(135deg,#eef2ff,#e0e7ff);border-bottom:1px solid #c7d2fe">
        <span class="fw-bold" style="font-size:.8rem;color:#312e81">
          <i class="bi bi-plus-circle-fill me-1"></i><span id="comp-form-title">Ajouter une compétence</span>
        </span>
      </div>
      <div class="card-body p-3">
        <form method="post" id="form-comp" data-ajax-post-form>
          <?= csrf_champ() ?>
          <input type="hidden" name="action" id="comp_action" value="comp_creer">
          <input type="hidden" name="comp_id" id="comp_id" value="0">
          <div class="mb-2">
            <label class="form-label" style="font-size:.75rem">Groupe de compétences</label>
            <select name="id_groupe_comp" id="comp_id_groupe" class="form-select form-select-sm" required>
              <?php foreach ($tous_groupes as $g): ?>
                <option value="<?= (int) $g['id_groupe_comp'] ?>" <?= $f_groupe === (int) $g['id_groupe_comp'] ? 'selected' : '' ?>>
                  [<?= h($g['langue']) ?>] <?= h(mb_strimwidth($g['libelle_groupe_comp'], 0, 45, '…')) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-2">
            <label class="form-label" style="font-size:.75rem">Code</label>
            <input type="text" name="code_comp" id="comp_code" class="form-control form-control-sm" maxlength="10" placeholder="ex. 1A" required>
          </div>
          <div class="mb-3">
            <label class="form-label" style="font-size:.75rem">Libellé</label>
            <textarea name="nom_comp" id="comp_nom" class="form-control form-control-sm" rows="2" required></textarea>
          </div>
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-sm btn-primary" id="comp-btn-submit">Ajouter</button>
            <button type="button" class="btn btn-sm btn-light d-none" id="comp-btn-annuler" onclick="reinitCompForm()">Annuler</button>
          </div>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="col-md-<?= $peut_modifier ? 8 : 12 ?>">
    <div class="card h-100" style="border:1px solid #e5e7eb">
      <div class="card-header py-2 px-3" style="background:#f8faff;border-bottom:1px solid #e5e7eb">
        <span class="fw-bold" style="font-size:.8rem;color:#374151">Compétences — <?= count($competences) ?></span>
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0" style="font-size:.78rem">
          <thead style="background:#f8faff">
            <tr>
              <th style="width:70px">Code</th>
              <th>Libellé</th>
              <?php if ($peut_modifier): ?><th style="width:80px" class="text-end">Actions</th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php if (!$competences): ?>
              <tr><td colspan="3" class="text-center text-muted py-3">Aucune compétence pour ce groupe.</td></tr>
            <?php else: foreach ($competences as $c): ?>
              <tr>
                <td><span class="badge-code"><?= h($c['code_comp']) ?></span></td>
                <td style="white-space:normal"><?= h($c['nom_comp']) ?></td>
                <?php if ($peut_modifier): ?>
                <td class="text-end">
                  <button type="button" class="btn btn-sm btn-light" style="padding:3px 7px"
                          onclick="editComp(<?= (int) $c['id_comp'] ?>,<?= h(json_encode($c['code_comp'])) ?>,<?= h(json_encode($c['nom_comp'])) ?>)">
                    <i class="bi bi-pencil" style="font-size:.72rem"></i>
                  </button>
                  <form method="post" class="d-inline" data-ajax-post-form onsubmit="return confirm('Supprimer cette compétence ?')">
                    <?= csrf_champ() ?>
                    <input type="hidden" name="action" value="comp_supprimer">
                    <input type="hidden" name="comp_id" value="<?= (int) $c['id_comp'] ?>">
                    <input type="hidden" name="id_groupe_comp" value="<?= $f_groupe ?>">
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
function editComp(id, code, nom) {
    document.getElementById('comp_action').value = 'comp_modifier';
    document.getElementById('comp_id').value     = id;
    document.getElementById('comp_code').value   = code;
    document.getElementById('comp_nom').value    = nom;
    document.getElementById('comp_id_groupe').disabled = true;
    document.getElementById('comp-form-title').textContent = 'Modifier la compétence';
    document.getElementById('comp-btn-submit').textContent = 'Enregistrer';
    document.getElementById('comp-btn-annuler').classList.remove('d-none');
    document.getElementById('comp_code').focus();
}
function reinitCompForm() {
    document.getElementById('form-comp').reset();
    document.getElementById('comp_action').value = 'comp_creer';
    document.getElementById('comp_id').value     = '0';
    document.getElementById('comp_id_groupe').disabled = false;
    document.getElementById('comp-form-title').textContent = 'Ajouter une compétence';
    document.getElementById('comp-btn-submit').textContent = 'Ajouter';
    document.getElementById('comp-btn-annuler').classList.add('d-none');
}
</script>

<?php endif; // tous_groupes ?>

<?php elseif ($onglet === 'bareme'): ?>
<!-- ══════════════════════════════════════════════
     ONGLET 4 — Barème par niveau, regroupé par groupe de compétences
══════════════════════════════════════════════ -->
<form method="get" class="d-flex align-items-center gap-3 flex-wrap mb-3" data-ajax-nav-form>
  <input type="hidden" name="onglet" value="bareme">
  <div style="min-width:220px">
    <select name="niveau" class="form-select form-select-sm" data-ajax-nav-auto>
      <option value="">— Choisir un niveau —</option>
      <?php foreach ($niveaux_liste as $n): ?>
        <option value="<?= h($n['LibelleNiveau']) ?>" <?= $f_niveau_bareme === $n['LibelleNiveau'] ? 'selected' : '' ?>>
          Niveau <?= h($n['LibelleNiveau']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <?php if ($f_niveau_bareme && $peut_modifier && count($niveaux_liste) > 1): ?>
  <div class="d-flex align-items-center gap-2" style="font-size:.78rem;color:#6b7280">
    <i class="bi bi-arrow-repeat"></i>Copier le barème d'un autre niveau :
    <select name="copier_de" class="form-select form-select-sm" style="width:auto">
      <option value="">— aucun —</option>
      <?php foreach ($niveaux_liste as $n): if ($n['LibelleNiveau'] === $f_niveau_bareme) continue; ?>
        <option value="<?= h($n['LibelleNiveau']) ?>" <?= $f_copier_de === $n['LibelleNiveau'] ? 'selected' : '' ?>>
          Niveau <?= h($n['LibelleNiveau']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-clipboard-check me-1"></i>Copier ici</button>
  </div>
  <?php endif; ?>
</form>

<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <span class="text-muted" style="font-size:.78rem"><i class="bi bi-download me-1"></i>Exporter :</span>
  <?php if ($f_niveau_bareme && $niveau_a_groupes_assignes): ?>
    <button type="button" class="btn btn-outline-danger btn-sm"
            onclick="afficherApercu('<?= APP_URL ?>/pdf/bareme_niveau.php?niveau=<?= urlencode($f_niveau_bareme) ?>', 'Barème — Niveau <?= h($f_niveau_bareme) ?>', null, 'portrait')">
      <i class="bi bi-file-earmark-pdf me-1"></i>Ce niveau — PDF
    </button>
    <a class="btn btn-outline-success btn-sm" href="<?= APP_URL ?>/pages/competences/excel_bareme.php?niveau=<?= urlencode($f_niveau_bareme) ?>">
      <i class="bi bi-file-earmark-excel me-1"></i>Ce niveau — Excel
    </a>
    <span class="border-start mx-1" style="height:20px"></span>
  <?php endif; ?>
  <button type="button" class="btn btn-outline-danger btn-sm"
          onclick="afficherApercu('<?= APP_URL ?>/pdf/bareme_niveau.php', 'Barème — Tous les niveaux', null, 'portrait')">
    <i class="bi bi-file-earmark-pdf me-1"></i>Tous les niveaux — PDF
  </button>
  <a class="btn btn-outline-success btn-sm" href="<?= APP_URL ?>/pages/competences/excel_bareme.php">
    <i class="bi bi-file-earmark-excel me-1"></i>Tous les niveaux — Excel
  </a>
</div>

<div class="alert alert-light text-muted py-2 mb-3" style="font-size:.8rem">
  <i class="bi bi-info-circle me-1"></i>
  Le barème est <strong>fixé par niveau</strong> : l'enregistrement applique les mêmes valeurs à toutes les classes de ce niveau.
  Seules les compétences en <strong>français</strong> sont listées ici (côté qui porte réellement la notation).
  Décocher <strong>Active</strong> sur une compétence la retire de la saisie, des bulletins et des statistiques —
  et si <strong>toutes</strong> les compétences d'un groupe sont désactivées, le groupe entier se masque automatiquement.
</div>

<?php if (!$f_niveau_bareme): ?>
  <div class="text-center text-muted py-5">
    <i class="bi bi-cursor" style="font-size:2.5rem;display:block;opacity:.15;margin-bottom:.5rem"></i>
    Sélectionnez un niveau pour gérer son barème.
  </div>
<?php else: ?>

<?php if ($mode_copie): ?>
  <div class="alert alert-info py-2 mb-3 d-flex justify-content-between align-items-center flex-wrap gap-2" style="font-size:.8rem">
    <span>
      <i class="bi bi-clipboard-check me-1"></i>
      Aperçu : valeurs <strong>copiées depuis le niveau <?= h($niveau_copie_source) ?></strong> —
      <strong>rien n'est encore enregistré</strong>. Ajustez si besoin puis cliquez « Enregistrer le barème »
      pour les appliquer au niveau <?= h($f_niveau_bareme) ?>.
    </span>
    <a href="<?= APP_URL ?>/pages/competences/liste.php?onglet=bareme&niveau=<?= urlencode($f_niveau_bareme) ?>"
       class="btn btn-sm btn-outline-secondary">Annuler la copie</a>
  </div>
<?php endif; ?>

<?php if (!$niveau_a_groupes_assignes): ?>
  <div class="alert alert-warning py-3 mb-3" style="font-size:.85rem">
    <i class="bi bi-exclamation-triangle me-1"></i>
    <strong>Aucun groupe de compétences n'est assigné au niveau <?= h($f_niveau_bareme) ?></strong> —
    il n'y a donc rien à configurer ici. Rendez-vous d'abord dans l'onglet
    <strong>« Groupes par niveau »</strong> pour assigner les groupes de ce niveau, puis revenez
    configurer son barème.
    <div class="mt-2">
      <a href="<?= APP_URL ?>/pages/competences/liste.php?onglet=groupes_niveau&niveau_gn=<?= urlencode($f_niveau_bareme) ?>"
         class="btn btn-sm btn-primary"><i class="bi bi-diagram-3 me-1"></i>Assigner des groupes à ce niveau</a>
    </div>
  </div>
<?php else: ?>

<?php if ($bareme_divergent && !$mode_copie): ?>
  <div class="alert alert-warning py-2 mb-3" style="font-size:.8rem">
    <i class="bi bi-exclamation-triangle me-1"></i>
    Les classes de ce niveau ont actuellement des barèmes <strong>différents</strong> (hérités des données importées).
    Les valeurs ci-dessous proviennent de <?= h($classes_du_niveau[0]['DesignationClasses'] ?? '') ?> —
    enregistrer les <strong>harmonisera</strong> sur toutes les classes du niveau.
  </div>
<?php endif; ?>

<form method="post" id="form-bareme" data-ajax-post-form>
  <?= csrf_champ() ?>
  <input type="hidden" name="code_niveau" value="<?= h($f_niveau_bareme) ?>">

  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <span class="fw-bold" style="font-size:.85rem;color:#374151">
        Barème — Niveau <?= h($f_niveau_bareme) ?>
        (<?= h(implode(', ', array_column($classes_du_niveau, 'DesignationClasses'))) ?>) — <?= h($val_annee) ?>
      </span>
      <span id="total-general" class="badge" style="background:#dcfce7;color:#166534;font-size:.75rem;padding:5px 10px;border-radius:8px">
        <?= h(rtrim(rtrim(number_format($total_general, 2, '.', ''), '0'), '.')) ?> points
      </span>
    </div>
    <?php if ($peut_modifier): ?>
    <div class="d-flex gap-2">
      <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-bareme-tout-cocher"><i class="bi bi-check-all me-1"></i>Tout cocher</button>
      <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-bareme-tout-decocher"><i class="bi bi-x-square me-1"></i>Tout décocher</button>
      <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check-lg me-1"></i>Enregistrer le barème</button>
    </div>
    <?php endif; ?>
  </div>

  <?php if (!$bareme_par_groupe): ?>
    <div class="alert alert-light text-muted text-center py-4">Aucune compétence en français définie (onglet Compétences).</div>
  <?php else: foreach ($bareme_par_groupe as $idg => $grp): ?>
  <div class="card mb-3 bareme-groupe <?= $grp['visible'] ? '' : 'bareme-groupe-masque' ?>" style="border:1px solid #e5e7eb">
    <div class="card-header py-2 px-3 d-flex justify-content-between align-items-center flex-wrap gap-2"
         style="background:<?= $grp['visible'] ? '#f8faff' : '#f9fafb' ?>;border-bottom:1px solid #e5e7eb">
      <span style="font-size:.8rem">
        <span class="badge bg-light text-dark border me-2" style="font-size:.68rem">Ordre <?= (int) $grp['ordre'] ?></span>
        <span class="fw-bold" style="color:#374151"><?= h($grp['libelle']) ?></span>
      </span>
      <span>
        <?php if (!$grp['a_une_active']): ?>
          <span class="badge" style="background:#fef3c7;color:#92400e;font-size:.68rem">
            <i class="bi bi-eye-slash me-1"></i>Masqué — toutes les compétences sont désactivées
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
            <th style="width:70px">Code</th>
            <th>Compétence</th>
            <th style="width:75px" class="text-center">Oral</th>
            <th style="width:75px" class="text-center">Écrit</th>
            <th style="width:75px" class="text-center">Pratique</th>
            <th style="width:90px" class="text-center">Savoir-être</th>
            <th style="width:80px" class="text-center fw-bold">Total</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($grp['lignes'] as $b): $id = (int) $b['id_comp']; $comp_active = $b['actif'] === null || (int) $b['actif'] === 1; ?>
            <tr class="bareme-ligne <?= $comp_active ? '' : 'bareme-ligne-inactive' ?>" data-comp="<?= $id ?>">
              <td class="text-center">
                <?php if ($peut_modifier): ?>
                  <input type="checkbox" class="form-check-input bareme-actif" name="bareme[<?= $id ?>][actif]" value="1"
                         data-comp="<?= $id ?>" <?= $comp_active ? 'checked' : '' ?>>
                <?php else: ?>
                  <?= $comp_active ? '<i class="bi bi-check-lg text-success"></i>' : '<i class="bi bi-x-lg text-muted"></i>' ?>
                <?php endif; ?>
              </td>
              <td><span class="badge-code"><?= h($b['code_comp']) ?></span></td>
              <td style="white-space:normal"><?= h($b['nom_comp_affiche'] ?? $b['nom_comp']) ?></td>
              <?php foreach (['orale', 'ecrite', 'pratique', 'savoir_etre'] as $champ): ?>
                <td class="text-center">
                  <?php if ($peut_modifier): ?>
                    <input type="number" step="0.5" min="0" class="form-control form-control-sm text-center bareme-input"
                           name="bareme[<?= $id ?>][<?= $champ ?>]" data-comp="<?= $id ?>"
                           value="<?= $b[$champ] !== null ? h((string) (float) $b[$champ]) : 0 ?>">
                  <?php else: ?>
                    <?= $b[$champ] !== null ? h((string) (float) $b[$champ]) : '—' ?>
                  <?php endif; ?>
                </td>
              <?php endforeach; ?>
              <td class="text-center fw-bold" id="total-<?= $id ?>"><?= $b['total_points'] !== null ? h((string) (float) $b['total_points']) : '0' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endforeach; endif; ?>
</form>

<style>
.bareme-ligne-inactive { opacity: .45; }
.bareme-groupe-masque .card-header { opacity: .75; }
</style>

<?php if ($peut_modifier): ?>
<script>
// Total général du niveau — somme du « Total » des lignes actives,
// recalculée à chaque frappe/coche (valeur initiale déjà fournie par PHP).
function recalculerTotalGeneral() {
  let total = 0;
  document.querySelectorAll('tr.bareme-ligne').forEach(tr => {
    if (tr.classList.contains('bareme-ligne-inactive')) return;
    tr.querySelectorAll('.bareme-input').forEach(i => total += parseFloat(i.value) || 0);
  });
  const el = document.getElementById('total-general');
  if (el) el.textContent = (Math.round(total * 100) / 100) + ' points';
}
// Recalcule le total affiché (Oral+Écrit+Pratique+Savoir-être) en direct,
// juste pour le confort visuel — le vrai total est recalculé côté serveur
// à l'enregistrement (jamais fait confiance à une valeur postée par le client).
document.querySelectorAll('.bareme-input').forEach(inp => {
  inp.addEventListener('input', () => {
    const id = inp.dataset.comp;
    let total = 0;
    document.querySelectorAll(`.bareme-input[data-comp="${id}"]`).forEach(i => total += parseFloat(i.value) || 0);
    document.getElementById('total-' + id).textContent = total;
    recalculerTotalGeneral();
  });
});
// Grise/dégrise la ligne en direct quand on (dés)active une compétence —
// confort visuel seul, la vraie règle est recalculée côté serveur au rechargement.
document.querySelectorAll('.bareme-actif').forEach(chk => {
  chk.addEventListener('change', () => {
    const tr = document.querySelector(`tr.bareme-ligne[data-comp="${chk.dataset.comp}"]`);
    if (tr) tr.classList.toggle('bareme-ligne-inactive', !chk.checked);
    recalculerTotalGeneral();
  });
});
// Tout cocher / Tout décocher — coche ou décoche toutes les compétences
// d'un coup (demande du 21/08/2026) au lieu de cliquer chaque ligne une à
// une ; déclenche l'event 'change' pour que le grisage ci-dessus suive.
function bareme_toutBasculer(coche) {
  document.querySelectorAll('.bareme-actif').forEach(chk => {
    chk.checked = coche;
    chk.dispatchEvent(new Event('change'));
  });
}
document.getElementById('btn-bareme-tout-cocher')?.addEventListener('click', () => bareme_toutBasculer(true));
document.getElementById('btn-bareme-tout-decocher')?.addEventListener('click', () => bareme_toutBasculer(false));
</script>
<?php endif; ?>

<?php endif; // niveau_a_groupes_assignes (gate) ?>
<?php endif; // f_niveau_bareme ?>
<?php endif; // onglets ?>

</div><!-- /#competences-zone -->
<?php if ($es_partiel) exit; // rien de plus dans une réponse AJAX partielle. ?>

<?php
$ajax_zone_id = 'competences-zone'; // voir layout/footer.php — initAjaxZone() y est appelé
require_once __DIR__ . '/../../layout/footer.php';
