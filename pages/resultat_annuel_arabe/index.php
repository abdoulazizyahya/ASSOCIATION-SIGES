<?php
// ── Résultat annuel (piste arabe) — miroir de pages/resultat_annuel/index.php ─
// 3 onglets (Par classe / Meilleurs élèves / Liste provisoire), navigation
// AJAX partielle, filtres Admis/Redoublants/Exclus, tri Alpha/Mérite,
// colonnes T1/T2/T3/Abs./Moy. annuelle/Rang/Décision/Classe suivante,
// bandeau d'avertissement pour les décisions non encore actées au Conseil
// de Classe (lien direct). Calcul partagé écran/PDF/Excel dans commun_arabe.php.
//
// Écart assumé (identique à la piste française, même raison) : colonnes
// FIXES pour le PDF/Excel — pas de modale de personnalisation des colonnes.
header('Cache-Control: no-store, no-cache, must-revalidate');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/commun_arabe.php';
exiger_acces_pedagogie();
exiger_annee_active(); // Année scolaire réellement active requise (18/08/2026) — module Pédagogie/Discipline.

$es_partiel = isset($_GET['partiel']);

$role      = role_connecte();
$is_admin  = $role === 'DIRECTEUR'; // Meilleurs élèves réservé DIRECTEUR (comme la piste française)
$annee_act = get_annee_active();
$val_annee = $annee_act['val_annee'] ?? '';

$classes = db_all(
    "SELECT c.IDClasses, c.DesignationClasses, n.OrdreNiveau
     FROM classe c LEFT JOIN niveau n ON n.LibelleNiveau = c.Niveau
     JOIN inscrire i ON i.IDClasses = c.IDClasses AND i.val_annee = ?
     GROUP BY c.IDClasses, c.DesignationClasses, n.OrdreNiveau
     ORDER BY n.OrdreNiveau, c.DesignationClasses",
    [$val_annee]
);

$onglet = in_array($_GET['onglet'] ?? '', ['classe', 'etablissement', 'provisoire'], true) ? $_GET['onglet'] : 'classe';
if ($onglet === 'etablissement' && !$is_admin) $onglet = 'classe';

$id_classe = (int) ($_GET['classe'] ?? 0);
$filtre    = in_array($_GET['filtre'] ?? '', ['admis', 'redoublants', 'exclus', 'tous'], true) ? $_GET['filtre'] : 'tous';
$limite    = max(0, (int) ($_GET['limite'] ?? 10));
$ordre     = ($_GET['ordre'] ?? 'merite') === 'alpha' ? 'alpha' : 'merite';

$lignes_classe_toutes = ($onglet === 'classe' && $id_classe) ? resultat_annuel_lignes_classe_arabe($id_classe, $val_annee, $ordre) : [];
$lignes_classe = resultat_annuel_filtrer_arabe($lignes_classe_toutes, $filtre);
$compte = ['Admis' => 0, 'Redoublement' => 0, 'Exclu' => 0, 'Abandon' => 0, 'Non classé' => 0];
foreach ($lignes_classe_toutes as $l) { $compte[$l['decision']] = ($compte[$l['decision']] ?? 0) + 1; }

$lignes_etab = ($onglet === 'etablissement' && $is_admin) ? resultat_annuel_lignes_etablissement_arabe($val_annee, $limite, $ordre) : [];

$lignes_provisoire = ($onglet === 'provisoire' && $id_classe) ? resultat_annuel_provisoire_classe_arabe($id_classe, $val_annee, $ordre) : [];
$val_annee_suivante = resultat_annuel_libelle_annee_suivante_arabe($val_annee);
$classe_choisie = null;
foreach ($classes as $c) { if ((int) $c['IDClasses'] === $id_classe) { $classe_choisie = $c; break; } }

if (!$es_partiel) {
    $titre_page = 'Résultat annuel (arabe)';
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
      <h4><i class="bi bi-mortarboard me-2" style="color:#1a3c6b"></i>Résultat annuel — <span dir="rtl" lang="ar">العربية</span></h4>
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
</ul>

<div class="card border-top-0" style="border-radius:0 6px 6px 6px;border-color:#c7d8f0">
<div class="card-body">

<?php if ($onglet === 'classe'): ?>

<form method="get" class="row g-2 align-items-end mb-3" data-ajax-nav-form action="<?= APP_URL ?>/pages/resultat_annuel_arabe/index.php">
  <input type="hidden" name="onglet" value="classe">
  <input type="hidden" name="ordre" value="<?= h($ordre) ?>">
  <div class="col-md-4">
    <label class="form-label mb-1" style="font-size:.78rem;font-weight:600;color:#1a3c6b">Classe</label>
    <select name="classe" class="form-select form-select-sm" data-ajax-nav-auto>
      <option value="">— Choisir —</option>
      <?php foreach ($classes as $c): ?>
        <option value="<?= $c['IDClasses'] ?>" <?= $id_classe == $c['IDClasses'] ? 'selected' : '' ?>><?= h($c['DesignationClasses']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php if ($id_classe): ?>
  <div class="col-auto ra-filtre">
    <label class="form-label mb-1 d-block" style="font-size:.78rem;font-weight:600;color:#1a3c6b">Filtre</label>
    <div class="d-flex gap-1 flex-wrap">
      <a href="?<?= http_build_query(['onglet' => 'classe', 'classe' => $id_classe, 'filtre' => 'tous', 'ordre' => $ordre]) ?>" data-ajax-nav class="btn btn-sm btn-abz-outline <?= $filtre === 'tous' ? 'active' : '' ?>">Tous (<?= array_sum($compte) ?>)</a>
      <a href="?<?= http_build_query(['onglet' => 'classe', 'classe' => $id_classe, 'filtre' => 'admis', 'ordre' => $ordre]) ?>" data-ajax-nav class="btn btn-sm btn-abz-outline <?= $filtre === 'admis' ? 'active' : '' ?>">Admis (<?= $compte['Admis'] ?>)</a>
      <a href="?<?= http_build_query(['onglet' => 'classe', 'classe' => $id_classe, 'filtre' => 'redoublants', 'ordre' => $ordre]) ?>" data-ajax-nav class="btn btn-sm btn-abz-outline <?= $filtre === 'redoublants' ? 'active' : '' ?>">Redoublants (<?= $compte['Redoublement'] ?>)</a>
    </div>
  </div>
  <div class="col-auto ra-filtre">
    <label class="form-label mb-1 d-block" style="font-size:.78rem;font-weight:600;color:#1a3c6b">Ordre</label>
    <div class="d-flex gap-1">
      <a href="?<?= http_build_query(['onglet' => 'classe', 'classe' => $id_classe, 'filtre' => $filtre, 'ordre' => 'merite']) ?>" data-ajax-nav class="btn btn-sm btn-abz-outline <?= $ordre === 'merite' ? 'active' : '' ?>"><i class="bi bi-trophy me-1"></i>Mérite</a>
      <a href="?<?= http_build_query(['onglet' => 'classe', 'classe' => $id_classe, 'filtre' => $filtre, 'ordre' => 'alpha']) ?>" data-ajax-nav class="btn btn-sm btn-abz-outline <?= $ordre === 'alpha' ? 'active' : '' ?>"><i class="bi bi-sort-alpha-down me-1"></i>Alphabétique</a>
    </div>
  </div>
  <?php endif; ?>
</form>

<?php if (!$id_classe): ?>
  <div class="text-center py-5 text-muted">
    <i class="bi bi-arrow-up-circle" style="font-size:3rem;opacity:.2;display:block;margin-bottom:1rem"></i>
    Sélectionnez une classe pour afficher le résultat annuel.
  </div>
<?php else:
  $nb_auto = count(array_filter($lignes_classe_toutes, fn($l) => $l['decision_auto']));
?>
  <?php if ($nb_auto > 0): ?>
  <div class="alert alert-warning d-flex align-items-center justify-content-between flex-wrap gap-2 py-2 mb-2" style="font-size:.82rem">
    <div><i class="bi bi-exclamation-triangle me-1"></i>
      <?= $nb_auto ?> décision(s) sur <?= count($lignes_classe_toutes) ?> n'<?= $nb_auto > 1 ? 'ont' : 'a' ?> pas encore été enregistrée(s) par le Conseil de Classe — valeur calculée automatiquement ci-dessous (moyenne annuelle ≥10 = Admis, sinon Redoublement).
    </div>
    <a href="<?= APP_URL ?>/pages/conseil_classe_arabe/index.php?type=annee&classe=<?= $id_classe ?>" class="btn btn-sm btn-warning">
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
            onclick="afficherApercu('<?= APP_URL ?>/pdf/resultat_annuel_arabe.php?scope=classe&classe=<?= $id_classe ?>&filtre=<?= $filtre ?>&ordre=<?= $ordre ?>', 'Résultat annuel', 'resultat_annuel_classe_arabe', 'portrait')">
      <i class="bi bi-file-earmark-pdf me-1"></i>Exporter PDF
    </button>
    <a class="btn btn-sm btn-abz-gold" href="<?= APP_URL ?>/pages/resultat_annuel_arabe/excel_arabe.php?scope=classe&classe=<?= $id_classe ?>&filtre=<?= $filtre ?>&ordre=<?= $ordre ?>">
      <i class="bi bi-file-earmark-excel me-1"></i>Exporter Excel
    </a>
  </div>

  <?= ra_table_html_arabe($lignes_classe) ?>

<?php endif; ?>

<?php elseif ($onglet === 'etablissement'): ?>

<form method="get" class="row g-2 align-items-end mb-3" data-ajax-nav-form action="<?= APP_URL ?>/pages/resultat_annuel_arabe/index.php">
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
          onclick="afficherApercu('<?= APP_URL ?>/pdf/resultat_annuel_arabe.php?scope=etablissement&limite=<?= $limite ?>&ordre=<?= $ordre ?>', 'Meilleurs élèves', 'resultat_annuel_etablissement_arabe', 'portrait')">
    <i class="bi bi-file-earmark-pdf me-1"></i>Exporter PDF
  </button>
  <a class="btn btn-sm btn-abz-gold" href="<?= APP_URL ?>/pages/resultat_annuel_arabe/excel_arabe.php?scope=etablissement&limite=<?= $limite ?>&ordre=<?= $ordre ?>">
    <i class="bi bi-file-earmark-excel me-1"></i>Exporter Excel
  </a>
</div>

<?= ra_table_html_arabe($lignes_etab, true) ?>

<?php else: /* onglet provisoire */ ?>

<form method="get" class="row g-2 align-items-end mb-3" data-ajax-nav-form action="<?= APP_URL ?>/pages/resultat_annuel_arabe/index.php">
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
            onclick="afficherApercu('<?= APP_URL ?>/pdf/resultat_annuel_provisoire_arabe.php?classe=<?= $id_classe ?>&ordre=<?= $ordre ?>', 'Liste provisoire', null, 'portrait')">
      <i class="bi bi-file-earmark-pdf me-1"></i>Exporter PDF
    </button>
    <a class="btn btn-sm btn-abz-gold" href="<?= APP_URL ?>/pages/resultat_annuel_arabe/excel_provisoire_arabe.php?classe=<?= $id_classe ?>&ordre=<?= $ordre ?>">
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

<?php endif; ?>

</div>
</div>

</div><!-- /#ra-zone -->

<?php
if ($es_partiel) exit; // rien de plus dans une réponse AJAX partielle.

// Tableau d'aperçu à l'écran — colonnes fixes (voir écart assumé en tête de fichier).
function ra_table_html_arabe(array $lignes, bool $avec_classe = false): string {
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
                  <a href="<?= APP_URL ?>/pages/conseil_classe_arabe/index.php?type=annee&classe=<?= (int) $l['id_classe'] ?>"
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
