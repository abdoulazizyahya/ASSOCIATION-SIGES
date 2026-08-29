<?php
// ── Résultat annuel — port fidèle d'ABZ_MBE ──────────────────────────
// 3 onglets (Par classe / Meilleurs élèves / Liste provisoire), navigation
// AJAX partielle, filtres Admis/Redoublants/Exclus, tri Alpha/Mérite,
// colonnes T1/T2/T3/Abs./Moy. annuelle/Rang/Décision/Classe suivante,
// bandeau d'avertissement pour les décisions non encore actées au Conseil
// de Classe (lien direct). Calcul partagé écran/PDF/Excel dans commun.php.
//
// Écart assumé (simplification délibérée, pas un oubli) : ABZ_MBE propose
// une modale de personnalisation des colonnes (cocher/réordonner/aligner)
// avant chaque export — jamais construite ici, colonnes FIXES identiques à
// l'écran pour le PDF/Excel (même simplification que Statistiques/Conseil
// de classe dans ce chantier, pour tenir le volume global de ce chantier).
header('Cache-Control: no-store, no-cache, must-revalidate');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/commun.php';
exiger_acces_pedagogie();
exiger_annee_active(); // Année scolaire réellement active requise (18/08/2026) — module Pédagogie/Discipline.

$es_partiel = isset($_GET['partiel']);

$role      = role_connecte();
$is_admin  = $role === 'DIRECTEUR'; // Meilleurs élèves réservé DIRECTEUR (comme session 5)
$annee_act = get_annee_active();
$val_annee = $annee_act['val_annee'] ?? '';

// ══════════════════════════════════════════════════════════════
// POST — Validation/Passage en classe supérieure (onglet=validation,
// DIRECTEUR uniquement, action sensible). Matérialise les décisions
// automatiques (moyenne annuelle) dans `decision_conseil_annuel` — c'est
// cette table, seule source de vérité, que la Liste provisoire lit déjà et
// que appliquer_promotions_annee() (fonctions.php) reprendra automatique-
// ment à la création/activation de l'année scolaire suivante.
// ══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exiger_role(['DIRECTEUR']);
    csrf_verifier();
    $action = post('action');

    if ($action === 'classe_suivante_definir') {
        $id_classe_p = (int) post('id_classe');
        $suivante    = (int) post('classe_suivante') ?: null;
        if ($id_classe_p) {
            db_exec("UPDATE classe SET classe_suivante=? WHERE IDClasses=?", [$suivante, $id_classe_p]);
            flash_set('succes', 'Classe suivante mise à jour.');
        }
        rediriger('pages/resultat_annuel/index.php?onglet=validation');
    }

    if ($action === 'valider_classe') {
        $id_classe_p = (int) post('id_classe');
        if ($id_classe_p) {
            $r = resultat_annuel_valider_classe($id_classe_p, $val_annee);
            $msg = $r['inscrits'] > 0 ? "{$r['inscrits']} décision(s) validée(s) automatiquement." : 'Aucune décision en attente pour cette classe (toutes déjà enregistrées, ou aucune moyenne calculable).';
            if ($r['sans_classe_suivante'] > 0) $msg .= " Attention : {$r['sans_classe_suivante']} élève(s) Admis sans classe suivante configurée — définissez-la ci-dessous puis revalidez.";
            flash_set($r['sans_classe_suivante'] > 0 ? 'alerte' : 'succes', $msg);
        }
        rediriger('pages/resultat_annuel/index.php?onglet=validation');
    }

    if ($action === 'valider_tout') {
        $classes_val = db_all(
            "SELECT DISTINCT c.IDClasses FROM classe c JOIN inscrire i ON i.IDClasses=c.IDClasses AND i.val_annee=?",
            [$val_annee]
        );
        $total = 0; $sans_dest = 0;
        foreach ($classes_val as $c) {
            $r = resultat_annuel_valider_classe((int) $c['IDClasses'], $val_annee);
            $total += $r['inscrits']; $sans_dest += $r['sans_classe_suivante'];
        }
        $msg = "$total décision(s) validée(s) automatiquement sur toutes les classes.";
        if ($sans_dest > 0) $msg .= " $sans_dest élève(s) Admis sans classe suivante configurée.";
        flash_set($sans_dest > 0 ? 'alerte' : 'succes', $msg);
        rediriger('pages/resultat_annuel/index.php?onglet=validation');
    }
}

$classes = db_all(
    "SELECT c.IDClasses, c.DesignationClasses, n.OrdreNiveau
     FROM classe c LEFT JOIN niveau n ON n.LibelleNiveau = c.Niveau
     JOIN inscrire i ON i.IDClasses = c.IDClasses AND i.val_annee = ?
     GROUP BY c.IDClasses, c.DesignationClasses, n.OrdreNiveau
     ORDER BY n.OrdreNiveau, c.DesignationClasses",
    [$val_annee]
);

$onglet = in_array($_GET['onglet'] ?? '', ['classe', 'etablissement', 'provisoire', 'validation'], true) ? $_GET['onglet'] : 'classe';
if (($onglet === 'etablissement' || $onglet === 'validation') && !$is_admin) $onglet = 'classe';

$id_classe = (int) ($_GET['classe'] ?? 0);
$filtre    = in_array($_GET['filtre'] ?? '', ['admis', 'redoublants', 'exclus', 'tous'], true) ? $_GET['filtre'] : 'tous';
$limite    = max(0, (int) ($_GET['limite'] ?? 10));
$ordre     = ($_GET['ordre'] ?? 'merite') === 'alpha' ? 'alpha' : 'merite';

$lignes_classe_toutes = ($onglet === 'classe' && $id_classe) ? resultat_annuel_lignes_classe($id_classe, $val_annee, $ordre) : [];
$lignes_classe = resultat_annuel_filtrer($lignes_classe_toutes, $filtre);
$compte = ['Admis' => 0, 'Redoublement' => 0, 'Exclu' => 0, 'Abandon' => 0, 'Non classé' => 0];
foreach ($lignes_classe_toutes as $l) { $compte[$l['decision']] = ($compte[$l['decision']] ?? 0) + 1; }

$lignes_etab = ($onglet === 'etablissement' && $is_admin) ? resultat_annuel_lignes_etablissement($val_annee, $limite, $ordre) : [];

$lignes_provisoire = ($onglet === 'provisoire' && $id_classe) ? resultat_annuel_provisoire_classe($id_classe, $val_annee, $ordre) : [];
$val_annee_suivante = resultat_annuel_libelle_annee_suivante($val_annee);
$classe_choisie = null;
foreach ($classes as $c) { if ((int) $c['IDClasses'] === $id_classe) { $classe_choisie = $c; break; } }

// ── Onglet Validation/Passage : effectif + décisions déjà enregistrées +
//    classe suivante configurée, pour CHAQUE classe de l'année active.
$validation_stats = [];
$toutes_classes_pour_select = [];
$nb_deja_inscrits_suivante = 0;
$annee_suivante_existe = false;
if ($onglet === 'validation' && $is_admin) {
    $toutes_classes_pour_select = db_all(
        "SELECT c.IDClasses, c.DesignationClasses FROM classe c
         LEFT JOIN niveau n ON n.LibelleNiveau = c.Niveau ORDER BY n.OrdreNiveau, c.DesignationClasses"
    );
    $map_classes = array_column($toutes_classes_pour_select, 'DesignationClasses', 'IDClasses');
    foreach ($classes as $c) {
        $idc = (int) $c['IDClasses'];
        $effectif = (int) db_val(
            "SELECT COUNT(*) FROM eleve e JOIN inscrire i ON i.id_eleve=e.id_eleve AND i.IDClasses=? AND i.val_annee=? WHERE e.statut='actif'",
            [$idc, $val_annee]
        );
        $nb_decides = (int) db_val("SELECT COUNT(*) FROM decision_conseil_annuel WHERE classe=? AND val_annee=?", [$idc, $val_annee]);
        $suiv_id = db_val("SELECT classe_suivante FROM classe WHERE IDClasses=?", [$idc]);
        $validation_stats[] = [
            'id' => $idc, 'designation' => $c['DesignationClasses'], 'effectif' => $effectif,
            'nb_decides' => $nb_decides,
            'classe_suivante_id' => $suiv_id !== null ? (int) $suiv_id : null,
            'classe_suivante_lib' => $suiv_id !== null ? ($map_classes[(int) $suiv_id] ?? '—') : null,
        ];
    }
    $annee_suivante_existe = (bool) db_val("SELECT COUNT(*) FROM annee_scolaire WHERE val_annee=?", [$val_annee_suivante]);
    $nb_deja_inscrits_suivante = (int) db_val(
        "SELECT COUNT(DISTINCT i1.id_eleve) FROM inscrire i1
         JOIN inscrire i2 ON i2.id_eleve=i1.id_eleve AND i2.val_annee=?
         WHERE i1.val_annee=?",
        [$val_annee_suivante, $val_annee]
    );
}

if (!$es_partiel) {
    $titre_page = 'Résultat annuel';
    require_once __DIR__ . '/../../layout/header.php';
    ?>
    <style>
    .ra-tabs .nav-link{color:#1a3c6b;border-radius:6px 6px 0 0;font-size:.85rem}
    .ra-tabs .nav-link.active{background:#1a3c6b;color:#fff;font-weight:600}
    .ra-filtre .btn.active{background:#1a3c6b;color:#fff;border-color:#1a3c6b}
    .ra-compte{display:flex;gap:.6rem;flex-wrap:wrap;margin-bottom:.8rem}
    .ra-compte .badge{font-size:.78rem;padding:6px 12px;border-radius:20px}
    </style>

    <div class="page-titre d-flex align-items-center justify-content-between flex-wrap gap-2">
      <h4><i class="bi bi-mortarboard me-2" style="color:#1a3c6b"></i>Résultat annuel</h4>
      <span class="badge" style="background:#dbeafe;color:#1e3a8a;font-size:.78rem;padding:5px 12px;border-radius:20px"><?= h($val_annee) ?></span>
    </div>

    <?= flash_html() ?>
    <?php
} else {
    header('Content-Type: text/html; charset=utf-8');
}
?>

<div id="ra-zone">

<ul class="nav nav-tabs ra-tabs mb-0 border-bottom-0">
  <li class="nav-item">
    <a class="nav-link <?= $onglet === 'classe' ? 'active' : '' ?>" href="?onglet=classe" data-ajax-nav>
      <i class="bi bi-people me-1"></i>Résultat par classe
    </a>
  </li>
  <?php if ($is_admin): ?>
  <li class="nav-item">
    <a class="nav-link <?= $onglet === 'etablissement' ? 'active' : '' ?>" href="?onglet=etablissement" data-ajax-nav>
      <i class="bi bi-trophy me-1"></i>Meilleurs élèves
    </a>
  </li>
  <?php endif; ?>
  <li class="nav-item">
    <a class="nav-link <?= $onglet === 'provisoire' ? 'active' : '' ?>" href="?onglet=provisoire" data-ajax-nav>
      <i class="bi bi-arrow-right-circle me-1"></i>Liste provisoire
    </a>
  </li>
  <?php if ($is_admin): ?>
  <li class="nav-item">
    <a class="nav-link <?= $onglet === 'validation' ? 'active' : '' ?>" href="?onglet=validation" data-ajax-nav>
      <i class="bi bi-check2-circle me-1"></i>Passage en classe supérieure
    </a>
  </li>
  <?php endif; ?>
</ul>

<div class="card" style="border-top-left-radius:0;border-color:#c7d8f0">
<div class="card-body">

<?php if ($onglet === 'classe'): ?>

<form method="get" class="row g-2 align-items-end mb-3" data-ajax-nav-form action="<?= APP_URL ?>/pages/resultat_annuel/index.php">
  <input type="hidden" name="onglet" value="classe">
  <div class="col-md-4">
    <label class="form-label mb-1" style="font-size:.78rem;font-weight:600;color:#1a3c6b">Classe</label>
    <select name="classe" class="form-select form-select-sm" data-ajax-nav-auto>
      <option value="">— Choisir une classe —</option>
      <?php foreach ($classes as $c): ?>
        <option value="<?= $c['IDClasses'] ?>" <?= $id_classe == $c['IDClasses'] ? 'selected' : '' ?>><?= h($c['DesignationClasses']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php if ($id_classe): ?>
  <div class="col-auto ra-filtre">
    <label class="form-label mb-1 d-block" style="font-size:.78rem;font-weight:600;color:#1a3c6b">Liste</label>
    <div class="d-flex gap-1">
      <?php foreach (['tous' => 'Toute la classe', 'admis' => 'Admis', 'redoublants' => 'Redoublants', 'exclus' => 'Exclus'] as $f => $lbl): ?>
        <a href="?<?= http_build_query(['onglet' => 'classe', 'classe' => $id_classe, 'filtre' => $f, 'ordre' => $ordre]) ?>" data-ajax-nav
           class="btn btn-sm btn-abz-outline <?= $filtre === $f ? 'active' : '' ?>"><?= $lbl ?></a>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="col-auto ra-filtre">
    <label class="form-label mb-1 d-block" style="font-size:.78rem;font-weight:600;color:#1a3c6b">Ordre</label>
    <div class="d-flex gap-1">
      <a href="?<?= http_build_query(['onglet' => 'classe', 'classe' => $id_classe, 'filtre' => $filtre, 'ordre' => 'merite']) ?>" data-ajax-nav
         class="btn btn-sm btn-abz-outline <?= $ordre === 'merite' ? 'active' : '' ?>"><i class="bi bi-trophy me-1"></i>Mérite</a>
      <a href="?<?= http_build_query(['onglet' => 'classe', 'classe' => $id_classe, 'filtre' => $filtre, 'ordre' => 'alpha']) ?>" data-ajax-nav
         class="btn btn-sm btn-abz-outline <?= $ordre === 'alpha' ? 'active' : '' ?>"><i class="bi bi-sort-alpha-down me-1"></i>Alphabétique</a>
    </div>
  </div>
  <?php endif; ?>
</form>

<?php if (!$id_classe): ?>
  <div class="text-center py-5 text-muted">
    <i class="bi bi-arrow-up-circle" style="font-size:3rem;opacity:.2;display:block;margin-bottom:1rem"></i>
    Sélectionnez une classe pour afficher le résultat annuel.
  </div>
<?php else: ?>

  <?php $nb_auto = count(array_filter($lignes_classe_toutes, fn($l) => $l['decision_auto'])); ?>
  <?php if ($nb_auto > 0): ?>
  <div class="alert alert-warning d-flex align-items-center justify-content-between flex-wrap gap-2 py-2 mb-2" style="font-size:.82rem">
    <div><i class="bi bi-exclamation-triangle me-1"></i>
      <?= $nb_auto ?> décision(s) sur <?= count($lignes_classe_toutes) ?> n'<?= $nb_auto > 1 ? 'ont' : 'a' ?> pas encore été enregistrée(s) par le Conseil de Classe — valeur calculée automatiquement ci-dessous (moyenne annuelle ≥10 = Admis, sinon Redoublement).
    </div>
    <a href="<?= APP_URL ?>/pages/conseil_classe/index.php?type=annee&classe=<?= $id_classe ?>" class="btn btn-sm btn-warning">
      <i class="bi bi-arrow-right-circle me-1"></i>Aller enregistrer au Conseil de Classe
    </a>
  </div>
  <?php endif; ?>

  <div class="ra-compte">
    <span class="badge" style="background:#d1fae5;color:#065f46">✅ Admis : <?= $compte['Admis'] ?></span>
    <span class="badge" style="background:#dbeafe;color:#1e40af">🔄 Redoublants : <?= $compte['Redoublement'] ?></span>
    <span class="badge" style="background:#fee2e2;color:#991b1b">❌ Exclus : <?= $compte['Exclu'] ?></span>
    <?php if ($compte['Abandon'] > 0): ?><span class="badge" style="background:#fef3c7;color:#92400e">🚪 Abandon : <?= $compte['Abandon'] ?></span><?php endif; ?>
    <?php if ($compte['Non classé'] > 0): ?><span class="badge" style="background:#f3f4f6;color:#6b7280">— Non classé : <?= $compte['Non classé'] ?></span><?php endif; ?>
  </div>

  <div class="d-flex gap-2 mb-2">
    <button type="button" class="btn btn-sm btn-abz-primary"
            onclick="afficherApercu('<?= APP_URL ?>/pdf/resultat_annuel.php?scope=classe&classe=<?= $id_classe ?>&filtre=<?= $filtre ?>&ordre=<?= $ordre ?>', 'Résultat annuel', 'resultat_annuel_classe', 'portrait')">
      <i class="bi bi-file-earmark-pdf me-1"></i>Exporter PDF
    </button>
    <a class="btn btn-sm btn-abz-gold" href="<?= APP_URL ?>/pages/resultat_annuel/excel.php?scope=classe&classe=<?= $id_classe ?>&filtre=<?= $filtre ?>&ordre=<?= $ordre ?>">
      <i class="bi bi-file-earmark-excel me-1"></i>Exporter Excel
    </a>
  </div>

  <?= ra_table_html($lignes_classe) ?>

<?php endif; ?>

<?php elseif ($onglet === 'etablissement'): ?>

<form method="get" class="row g-2 align-items-end mb-3" data-ajax-nav-form action="<?= APP_URL ?>/pages/resultat_annuel/index.php">
  <input type="hidden" name="onglet" value="etablissement">
  <input type="hidden" name="ordre" value="<?= h($ordre) ?>">
  <div class="col-auto">
    <label class="form-label mb-1" style="font-size:.78rem;font-weight:600;color:#1a3c6b">Nombre d'élèves</label>
    <div class="d-flex gap-1 align-items-center">
      <?php foreach ([5, 10, 30, 50] as $n): ?>
        <a href="?<?= http_build_query(['onglet' => 'etablissement', 'limite' => $n, 'ordre' => $ordre]) ?>" data-ajax-nav
           class="btn btn-sm btn-abz-outline <?= $limite === $n ? 'active' : '' ?>"><?= $n ?></a>
      <?php endforeach; ?>
      <input type="number" name="limite" min="1" class="form-control form-control-sm" style="width:90px" value="<?= $limite ?>" placeholder="Autre">
      <button class="btn btn-sm btn-abz-outline"><i class="bi bi-check-lg"></i></button>
    </div>
  </div>
  <div class="col-auto ra-filtre">
    <label class="form-label mb-1 d-block" style="font-size:.78rem;font-weight:600;color:#1a3c6b">Ordre</label>
    <div class="d-flex gap-1">
      <a href="?<?= http_build_query(['onglet' => 'etablissement', 'limite' => $limite, 'ordre' => 'merite']) ?>" data-ajax-nav
         class="btn btn-sm btn-abz-outline <?= $ordre === 'merite' ? 'active' : '' ?>"><i class="bi bi-trophy me-1"></i>Mérite</a>
      <a href="?<?= http_build_query(['onglet' => 'etablissement', 'limite' => $limite, 'ordre' => 'alpha']) ?>" data-ajax-nav
         class="btn btn-sm btn-abz-outline <?= $ordre === 'alpha' ? 'active' : '' ?>"><i class="bi bi-sort-alpha-down me-1"></i>Alphabétique</a>
    </div>
  </div>
</form>

<div class="d-flex gap-2 mb-2">
  <button type="button" class="btn btn-sm btn-abz-primary"
          onclick="afficherApercu('<?= APP_URL ?>/pdf/resultat_annuel.php?scope=etablissement&limite=<?= $limite ?>&ordre=<?= $ordre ?>', 'Meilleurs élèves', 'resultat_annuel_etablissement', 'portrait')">
    <i class="bi bi-file-earmark-pdf me-1"></i>Exporter PDF
  </button>
  <a class="btn btn-sm btn-abz-gold" href="<?= APP_URL ?>/pages/resultat_annuel/excel.php?scope=etablissement&limite=<?= $limite ?>&ordre=<?= $ordre ?>">
    <i class="bi bi-file-earmark-excel me-1"></i>Exporter Excel
  </a>
</div>

<?= ra_table_html($lignes_etab, true) ?>

<?php elseif ($onglet === 'provisoire'): ?>

<form method="get" class="row g-2 align-items-end mb-3" data-ajax-nav-form action="<?= APP_URL ?>/pages/resultat_annuel/index.php">
  <input type="hidden" name="onglet" value="provisoire">
  <div class="col-md-4">
    <label class="form-label mb-1" style="font-size:.78rem;font-weight:600;color:#1a3c6b">Classe (l'an prochain)</label>
    <select name="classe" class="form-select form-select-sm" data-ajax-nav-auto>
      <option value="">— Choisir une classe —</option>
      <?php foreach ($classes as $c): ?>
        <option value="<?= $c['IDClasses'] ?>" <?= $id_classe == $c['IDClasses'] ? 'selected' : '' ?>><?= h($c['DesignationClasses']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php if ($id_classe): ?>
  <div class="col-auto ra-filtre">
    <label class="form-label mb-1 d-block" style="font-size:.78rem;font-weight:600;color:#1a3c6b">Ordre</label>
    <div class="d-flex gap-1">
      <a href="?<?= http_build_query(['onglet' => 'provisoire', 'classe' => $id_classe, 'ordre' => 'alpha']) ?>" data-ajax-nav
         class="btn btn-sm btn-abz-outline <?= $ordre === 'alpha' ? 'active' : '' ?>"><i class="bi bi-sort-alpha-down me-1"></i>Alphabétique</a>
      <a href="?<?= http_build_query(['onglet' => 'provisoire', 'classe' => $id_classe, 'ordre' => 'merite']) ?>" data-ajax-nav
         class="btn btn-sm btn-abz-outline <?= $ordre === 'merite' ? 'active' : '' ?>"><i class="bi bi-trophy me-1"></i>Mérite</a>
    </div>
  </div>
  <?php endif; ?>
</form>

<?php if (!$id_classe): ?>
  <div class="text-center py-5 text-muted">
    <i class="bi bi-arrow-up-circle" style="font-size:3rem;opacity:.2;display:block;margin-bottom:1rem"></i>
    Sélectionnez la classe (telle qu'elle existera l'an prochain) pour afficher son effectif prévisionnel.
  </div>
<?php else: ?>

  <div class="alert alert-info d-flex align-items-center gap-2 py-2 mb-2" style="font-size:.8rem">
    <i class="bi bi-info-circle"></i>
    Effectif prévisionnel <strong><?= h($val_annee_suivante) ?></strong> pour <strong><?= h($classe_choisie['DesignationClasses'] ?? '') ?></strong> :
    redoublants de cette classe + élèves admis d'autres classes dont la classe suivante a été enregistrée ici. Seules les décisions déjà enregistrées au Conseil de Classe sont prises en compte —
    un élève admis « (à définir) » n'apparaît dans aucune liste provisoire.
  </div>

  <div class="ra-compte">
    <span class="badge" style="background:#dbeafe;color:#1e40af">🔄 RED (redoublants) : <?= count(array_filter($lignes_provisoire, fn($l) => $l['statut_code'] === 'RED')) ?></span>
    <span class="badge" style="background:#d1fae5;color:#065f46">🆕 NV (admis d'autres classes) : <?= count(array_filter($lignes_provisoire, fn($l) => $l['statut_code'] === 'NV')) ?></span>
    <span class="badge" style="background:#f3f4f6;color:#374151">Total : <?= count($lignes_provisoire) ?></span>
  </div>

  <div class="d-flex gap-2 mb-2">
    <button type="button" class="btn btn-sm btn-abz-primary"
            onclick="afficherApercu('<?= APP_URL ?>/pdf/resultat_annuel_provisoire.php?classe=<?= $id_classe ?>&ordre=<?= $ordre ?>', 'Liste provisoire', null, 'portrait')">
      <i class="bi bi-file-earmark-pdf me-1"></i>Exporter PDF
    </button>
    <a class="btn btn-sm btn-abz-gold" href="<?= APP_URL ?>/pages/resultat_annuel/excel_provisoire.php?classe=<?= $id_classe ?>&ordre=<?= $ordre ?>">
      <i class="bi bi-file-earmark-excel me-1"></i>Exporter Excel
    </a>
  </div>

  <div class="table-responsive">
    <table class="table table-abz table-hover align-middle mb-0" style="font-size:.79rem">
      <thead>
        <tr>
          <th style="width:34px">N°</th>
          <th>Matricule</th>
          <th>Nom et prénoms</th>
          <th class="text-center">Date naiss.</th>
          <th>Lieu naiss.</th>
          <th class="text-center">Sexe</th>
          <th class="text-center">Statut</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($lignes_provisoire)): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">Aucun élève — aucune décision du Conseil de Classe enregistrée pour cette classe.</td></tr>
        <?php else: $no = 1; foreach ($lignes_provisoire as $l): $e = $l['eleve']; ?>
          <tr>
            <td class="text-muted"><?= $no++ ?></td>
            <td><span class="badge-code"><?= h($e['Mat_elv'] ?? '') ?></span></td>
            <td class="fw-semibold"><?= h(mb_strtoupper($e['Nom_elv']) . ' ' . ($e['Prenom_elv'] ?? '')) ?></td>
            <td class="text-center"><?= $e['Date_naiss_elv'] ? h(date('d/m/Y', strtotime($e['Date_naiss_elv']))) : '—' ?></td>
            <td><?= h($e['Lieu_naiss_elv'] ?: '—') ?></td>
            <td class="text-center"><?= stripos($e['Sexe_elv'] ?? '', 'F') === 0 ? '<span class="badge-f">F</span>' : '<span class="badge-m">M</span>' ?></td>
            <td class="text-center">
              <span class="badge" style="<?= $l['statut_code'] === 'RED' ? 'background:#dbeafe;color:#1e40af' : 'background:#d1fae5;color:#065f46' ?>"><?= $l['statut_code'] ?></span>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

<?php endif; ?>

<?php elseif ($onglet === 'validation' && $is_admin): ?>

<div class="alert alert-info d-flex align-items-start gap-2 py-2 mb-3" style="font-size:.8rem">
  <i class="bi bi-info-circle mt-1"></i>
  <div>
    Pour chaque classe, la validation inscrit une décision <strong>Admis</strong> (moyenne annuelle ≥10 → classe
    suivante configurée ci-dessous) ou <strong>Redoublement</strong> (moyenne &lt;10 → reste dans la même classe)
    pour tout élève qui n'a <u>pas déjà</u> de décision enregistrée par le Conseil de Classe — le Conseil de Classe
    reste toujours prioritaire, rien n'est jamais écrasé. Une fois validées, ces décisions sont consultables à tout
    moment dans <strong>Liste provisoire</strong>, et seront reprises <strong>automatiquement</strong> — élèves
    admis inscrits dans leur classe suivante, redoublants réinscrits dans la même classe avec le statut
    « Redoublant » — dès la création ou l'activation de l'année <strong><?= h($val_annee_suivante) ?></strong>.
    <?php if ($annee_suivante_existe): ?>
      <div class="mt-1"><i class="bi bi-check-circle-fill text-success me-1"></i>L'année <?= h($val_annee_suivante) ?> existe déjà — <?= $nb_deja_inscrits_suivante ?> élève(s) de <?= h($val_annee) ?> y sont déjà inscrit(s) (automatiquement ou manuellement).</div>
    <?php else: ?>
      <div class="mt-1 text-muted"><i class="bi bi-hourglass-split me-1"></i>L'année <?= h($val_annee_suivante) ?> n'existe pas encore — le passage sera appliqué dès sa création (Paramètres → Années scolaires).</div>
    <?php endif; ?>
  </div>
</div>

<form method="post" class="mb-3" onsubmit="return confirm('Valider automatiquement les décisions manquantes pour TOUTES les classes ?')">
  <?= csrf_champ() ?>
  <input type="hidden" name="action" value="valider_tout">
  <button type="submit" class="btn btn-sm btn-abz-primary"><i class="bi bi-check2-all me-1"></i>Valider toutes les classes</button>
</form>

<div class="table-responsive">
  <table class="table table-abz table-hover align-middle mb-0" style="font-size:.8rem">
    <thead>
      <tr>
        <th>Classe</th>
        <th class="text-center">Effectif</th>
        <th class="text-center">Décisions enregistrées</th>
        <th style="min-width:220px">Classe suivante (pour la règle automatique)</th>
        <th class="text-center" style="width:110px">Action</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$validation_stats): ?>
        <tr><td colspan="5" class="text-center text-muted py-4">Aucune classe avec élève inscrit cette année.</td></tr>
      <?php else: foreach ($validation_stats as $vs): ?>
        <tr>
          <td class="fw-semibold"><?= h($vs['designation']) ?></td>
          <td class="text-center"><?= $vs['effectif'] ?></td>
          <td class="text-center">
            <?php if ($vs['nb_decides'] >= $vs['effectif'] && $vs['effectif'] > 0): ?>
              <span class="badge" style="background:#d1fae5;color:#065f46"><?= $vs['nb_decides'] ?>/<?= $vs['effectif'] ?> ✅</span>
            <?php else: ?>
              <span class="badge" style="background:#fef3c7;color:#92400e"><?= $vs['nb_decides'] ?>/<?= $vs['effectif'] ?></span>
            <?php endif; ?>
          </td>
          <td>
            <form method="post" class="d-flex gap-1">
              <?= csrf_champ() ?>
              <input type="hidden" name="action" value="classe_suivante_definir">
              <input type="hidden" name="id_classe" value="<?= $vs['id'] ?>">
              <select name="classe_suivante" class="form-select form-select-sm" onchange="this.form.requestSubmit()">
                <option value="">— (à définir) —</option>
                <?php foreach ($toutes_classes_pour_select as $tc): ?>
                  <option value="<?= $tc['IDClasses'] ?>" <?= $vs['classe_suivante_id'] === (int) $tc['IDClasses'] ? 'selected' : '' ?>><?= h($tc['DesignationClasses']) ?></option>
                <?php endforeach; ?>
              </select>
            </form>
          </td>
          <td class="text-center">
            <form method="post" onsubmit="return confirm('Valider automatiquement les décisions manquantes pour <?= h($vs['designation']) ?> ?')">
              <?= csrf_champ() ?>
              <input type="hidden" name="action" value="valider_classe">
              <input type="hidden" name="id_classe" value="<?= $vs['id'] ?>">
              <button type="submit" class="btn btn-sm btn-abz-outline"><i class="bi bi-check2 me-1"></i>Valider</button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<?php endif; ?>

</div>
</div>

</div><!-- /#ra-zone -->

<?php
if ($es_partiel) exit; // rien de plus dans une réponse AJAX partielle.

// Tableau d'aperçu à l'écran — colonnes fixes (voir écart assumé en tête de fichier).
function ra_table_html(array $lignes, bool $avec_classe = false): string {
    ob_start();
    ?>
    <div class="table-responsive">
      <table class="table table-abz table-hover align-middle mb-0" style="font-size:.79rem">
        <thead>
          <tr>
            <th style="width:34px">N°</th>
            <th>Matricule</th>
            <th>Nom et prénoms</th>
            <?php if ($avec_classe): ?><th>Classe</th><?php endif; ?>
            <th class="text-center">Sexe</th>
            <th class="text-center">Moy. T1</th>
            <th class="text-center">Moy. T2</th>
            <th class="text-center">Moy. T3</th>
            <th class="text-center">Heures d'abs.</th>
            <th class="text-center">Moy. annuelle</th>
            <th class="text-center">Rang</th>
            <th>Décision</th>
            <th>Classe suivante</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($lignes)): ?>
            <tr><td colspan="<?= $avec_classe ? 12 : 11 ?>" class="text-center text-muted py-4">Aucun élève.</td></tr>
          <?php else: $no = 1; foreach ($lignes as $l): $e = $l['eleve']; ?>
            <tr>
              <td class="text-muted"><?= $no++ ?></td>
              <td><span class="badge-code"><?= h($e['Mat_elv'] ?? '') ?></span></td>
              <td class="fw-semibold"><?= h(mb_strtoupper($e['Nom_elv']) . ' ' . ($e['Prenom_elv'] ?? '')) ?></td>
              <?php if ($avec_classe): ?><td><?= h($l['classe'] ?? '—') ?></td><?php endif; ?>
              <td class="text-center"><?= stripos($e['Sexe_elv'] ?? '', 'F') === 0 ? '<span class="badge-f">F</span>' : '<span class="badge-m">M</span>' ?></td>
              <td class="text-center"><?= $l['moy_t'][0] !== null ? number_format($l['moy_t'][0], 2) : '—' ?></td>
              <td class="text-center"><?= $l['moy_t'][1] !== null ? number_format($l['moy_t'][1], 2) : '—' ?></td>
              <td class="text-center"><?= $l['moy_t'][2] !== null ? number_format($l['moy_t'][2], 2) : '—' ?></td>
              <td class="text-center"><?= $l['abs_nj'] ?></td>
              <td class="text-center fw-bold"><?= $l['moy_annuelle'] !== null ? number_format($l['moy_annuelle'], 2) : '—' ?></td>
              <td class="text-center"><?= $l['rang'] !== null ? $l['rang'] . 'e/' . $l['nb_classes'] : '—' ?></td>
              <td>
                <?php
                $couleur = ['Admis' => 'background:#d1fae5;color:#065f46', 'Redoublement' => 'background:#dbeafe;color:#1e40af',
                            'Exclu' => 'background:#fee2e2;color:#991b1b', 'Abandon' => 'background:#fef3c7;color:#92400e',
                            'Non classé' => 'background:#f3f4f6;color:#6b7280'][$l['decision']] ?? '';
                ?>
                <span class="badge" style="<?= $couleur ?>;font-size:.75rem"><?= h($l['decision']) ?></span>
                <?php if ($l['decision_auto']): ?>
                  <a href="<?= APP_URL ?>/pages/conseil_classe/index.php?type=annee&classe=<?= (int) $l['id_classe'] ?>"
                     class="text-warning" title="Non enregistrée au Conseil de Classe — valeur calculée automatiquement. Cliquer pour aller l'enregistrer.">
                    <i class="bi bi-exclamation-triangle-fill" style="font-size:.72rem"></i>
                  </a>
                <?php else: ?>
                  <i class="bi bi-check-circle-fill text-success" style="font-size:.72rem" title="Décision enregistrée par le Conseil de Classe"></i>
                <?php endif; ?>
              </td>
              <td style="font-size:.78rem"><?= h($l['classe_suivante'] ?? '—') ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <?php
    return ob_get_clean();
}
?>

<?php
$ajax_zone_id = 'ra-zone'; // voir layout/footer.php — initAjaxZone() y est appelé après sa propre définition
require_once __DIR__ . '/../../layout/footer.php';
