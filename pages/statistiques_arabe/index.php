<?php
// ── Statistiques scolaires — piste arabe ─────────────────────────────
// Miroir de pages/statistiques/index.php (piste française), même structure
// (onglets, navigation AJAX, CSS), mais données du modèle matière+
// coefficient (notes_apc_arabe.php) : « Par compétence » devient « Par
// matière ». Onglet Enseignants identique (donnée commune aux 2 pistes).
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/../../notes_apc.php';
require_once __DIR__ . '/../../notes_apc_arabe.php';
exiger_acces_pedagogie();
exiger_annee_active(); // Année scolaire réellement active requise (18/08/2026) — module Pédagogie/Discipline.

header('Cache-Control: no-store, no-cache, must-revalidate');

$role      = role_connecte();
$is_admin  = in_array($role, ['DIRECTEUR', 'SECRETAIRE'], true);
$annee_act = get_annee_active();
$val_annee = $annee_act['val_annee'] ?? '';
$seq_act   = get_sequence_active();

$onglet = $_GET['onglet'] ?? 'eleves';
$allowed_onglets = ['eleves', 'matieres', 'effectifs', 'niveau', 'matiere', 'non_evalue', 'enseignants'];
if (!in_array($onglet, $allowed_onglets, true)) $onglet = 'eleves';
if ($onglet === 'enseignants' && !$is_admin) $onglet = 'eleves';

$vue     = in_array($_GET['vue'] ?? '', ['trim', 'annee'], true) ? $_GET['vue'] : 'trim';
$id_trim = (int) ($seq_act['id_trim'] ?? 0);
$trim_lib = $id_trim ? (string) db_val("SELECT libelle_trim FROM trimestre WHERE id_trim=?", [$id_trim]) : '';
$id_classe = (int) ($_GET['classe'] ?? 0);

$classes = db_all(
    "SELECT c.IDClasses, c.DesignationClasses, c.Niveau, n.OrdreNiveau
     FROM classe c LEFT JOIN niveau n ON n.LibelleNiveau = c.Niveau
     JOIN inscrire i ON i.IDClasses = c.IDClasses AND i.val_annee = ?
     GROUP BY c.IDClasses, c.DesignationClasses, c.Niveau, n.OrdreNiveau
     ORDER BY n.OrdreNiveau, c.DesignationClasses",
    [$val_annee]
);

$periode_ok = ($vue === 'annee') || ($id_trim > 0);
$fmt = fn(?float $v): string => $v === null ? '—' : rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');

$bilan_cols = ['classes', 'moy_lt10', 'moy_ge10', 'felicit', 'encourag', 'tab', 'avert_trav', 'blame_trav'];
function render_tableau_bilan_genre_arabe(string $titre, array $lignes, array $bilan_cols): void {
    $zero = ['M' => 0, 'F' => 0, 'T' => 0];
    $sous_total = array_fill_keys($bilan_cols, $zero);
    foreach ($lignes as $l) {
        foreach ($bilan_cols as $k) { foreach (['M', 'F', 'T'] as $g) $sous_total[$k][$g] += $l['bilan'][$k][$g]; }
    }
    ?>
    <h6 class="fw-bold mt-3" style="color:#1a3c6b"><i class="bi bi-collection me-1"></i><?= h($titre) ?></h6>
    <div class="table-responsive mb-3">
    <table class="table tbl-stat table-hover mb-0" style="font-size:.72rem">
      <thead>
        <tr>
          <th rowspan="2" style="vertical-align:middle">Classe</th>
          <th colspan="3">Classés</th><th colspan="3">Moy &lt; 10</th><th colspan="3">Moy &ge; 10</th>
          <th colspan="3">Félicit.</th><th colspan="3">Encour.</th><th colspan="3">T.H</th>
          <th colspan="3">Avert. T.</th><th colspan="3">Blâme T.</th>
        </tr>
        <tr><?php for ($i = 0; $i < count($bilan_cols); $i++): ?><th>M</th><th>F</th><th>T</th><?php endfor; ?></tr>
      </thead>
      <tbody>
      <?php foreach ($lignes as $l): $b = $l['bilan']; ?>
        <tr>
          <td class="fw-semibold"><?= h($l['classe']) ?></td>
          <?php foreach ($bilan_cols as $k): ?>
            <td><?= $b[$k]['M'] ?></td><td><?= $b[$k]['F'] ?></td><td class="fw-semibold"><?= $b[$k]['T'] ?></td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
        <tr style="background:#e8f0fe;font-weight:700">
          <td>SOUS-TOTAL</td>
          <?php foreach ($bilan_cols as $k): ?>
            <td><?= $sous_total[$k]['M'] ?></td><td><?= $sous_total[$k]['F'] ?></td><td><?= $sous_total[$k]['T'] ?></td>
          <?php endforeach; ?>
        </tr>
      </tbody>
    </table>
    </div>
    <?php
}

$es_partiel = isset($_GET['partiel']);
if (!$es_partiel) {
    $titre_page = 'Statistiques (arabe)';
    require_once __DIR__ . '/../../layout/header.php';
    ?>
    <style>
    .stat-card{border-left:4px solid #1a3c6b;background:#f8faff;border-radius:6px;padding:10px 14px;}
    .stat-num{font-size:1.6rem;font-weight:700;color:#1a3c6b;}
    .stat-lbl{font-size:.75rem;color:#666;text-transform:uppercase;}
    .tbl-stat th{background:#1a3c6b;color:#fff;font-size:.77rem;padding:7px 10px;}
    .tbl-stat td{font-size:.81rem;padding:6px 10px;vertical-align:middle;}
    .tbl-stat tr:hover td{background:#f0f4ff;}
    .nav-ong .nav-link{color:#1a3c6b;border-radius:6px 6px 0 0;font-size:.83rem;}
    .nav-ong .nav-link.active{background:#1a3c6b;color:#fff;font-weight:600;}
    </style>

    <div class="page-titre d-flex align-items-center justify-content-between flex-wrap gap-2">
      <h4><i class="bi bi-bar-chart-line me-2" style="color:#1a3c6b"></i>Statistiques scolaires — <span dir="rtl" lang="ar">العربية</span></h4>
      <span class="badge" style="background:#dbeafe;color:#1e3a8a;font-size:.78rem;padding:5px 12px;border-radius:20px"><?= h($val_annee) ?></span>
    </div>
    <?= flash_html() ?>
    <?php
} else {
    header('Content-Type: text/html; charset=utf-8');
}

$q = fn(string $o) => "?onglet=$o&vue=$vue&classe=$id_classe";
?>

<div id="stat-zone">

<ul class="nav nav-tabs nav-ong mb-0 border-bottom-0">
  <li class="nav-item"><a class="nav-link <?= $onglet === 'eleves' ? 'active' : '' ?>" data-ajax-nav href="<?= h($q('eleves')) ?>"><i class="bi bi-people me-1"></i>Par classe / Résultats</a></li>
  <li class="nav-item"><a class="nav-link <?= $onglet === 'matieres' ? 'active' : '' ?>" data-ajax-nav href="<?= h($q('matieres')) ?>"><i class="bi bi-journal-bookmark me-1"></i>Par matière (classe)</a></li>
  <li class="nav-item"><a class="nav-link <?= $onglet === 'effectifs' ? 'active' : '' ?>" data-ajax-nav href="<?= h($q('effectifs')) ?>"><i class="bi bi-diagram-3 me-1"></i>Effectifs / Niveaux</a></li>
  <li class="nav-item"><a class="nav-link <?= $onglet === 'niveau' ? 'active' : '' ?>" data-ajax-nav href="<?= h($q('niveau')) ?>"><i class="bi bi-bar-chart-steps me-1"></i>Par niveau</a></li>
  <li class="nav-item"><a class="nav-link <?= $onglet === 'matiere' ? 'active' : '' ?>" data-ajax-nav href="<?= h($q('matiere')) ?>"><i class="bi bi-journal-text me-1"></i>Par matière</a></li>
  <li class="nav-item"><a class="nav-link <?= $onglet === 'non_evalue' ? 'active' : '' ?>" data-ajax-nav href="<?= h($q('non_evalue')) ?>"><i class="bi bi-exclamation-triangle me-1"></i>Non évalués</a></li>
  <?php if ($is_admin): ?><li class="nav-item"><a class="nav-link <?= $onglet === 'enseignants' ? 'active' : '' ?>" data-ajax-nav href="<?= h($q('enseignants')) ?>"><i class="bi bi-person-badge me-1"></i>Enseignants</a></li><?php endif; ?>
</ul>

<div class="card border-top-0" style="border-radius:0 6px 6px 6px;border-color:#c7d8f0">
<div class="card-body">

<form method="get" class="row g-2 align-items-end mb-3" data-ajax-nav-form action="<?= APP_URL ?>/pages/statistiques_arabe/index.php">
  <input type="hidden" name="onglet" value="<?= h($onglet) ?>">
  <div class="col-auto">
    <label class="form-label mb-1" style="font-size:.78rem;font-weight:600;color:#1a3c6b">Période</label>
    <div class="d-flex gap-1">
      <a href="?onglet=<?= $onglet ?>&vue=trim&classe=<?= $id_classe ?>" data-ajax-nav class="btn btn-sm <?= $vue === 'trim' ? 'btn-abz-primary' : 'btn-abz-outline' ?>">Trimestre</a>
      <a href="?onglet=<?= $onglet ?>&vue=annee&classe=<?= $id_classe ?>" data-ajax-nav class="btn btn-sm <?= $vue === 'annee' ? 'btn-abz-primary' : 'btn-abz-outline' ?>">Annuelle</a>
    </div>
  </div>
  <?php if ($vue === 'trim'): ?>
  <div class="col-auto">
    <label class="form-label mb-1 d-block" style="font-size:.78rem;font-weight:600;color:#1a3c6b">Trimestre</label>
    <span class="badge" style="background:#dbeafe;color:#1e3a8a;font-size:.82rem;padding:7px 14px;border-radius:8px"><?= $id_trim ? h($trim_lib) . ' (en cours)' : 'Aucun trimestre en cours' ?></span>
    <input type="hidden" name="vue" value="trim">
  </div>
  <?php else: ?>
  <div class="col-auto">
    <label class="form-label mb-1 d-block" style="font-size:.78rem;font-weight:600;color:#1a3c6b">Année</label>
    <span class="badge" style="background:#dbeafe;color:#1e3a8a;font-size:.82rem;padding:7px 14px;border-radius:8px"><?= h($val_annee) ?></span>
    <input type="hidden" name="vue" value="annee">
  </div>
  <?php endif; ?>
  <?php if (!in_array($onglet, ['effectifs', 'enseignants', 'niveau'], true)): ?>
  <div class="col-md-3">
    <label class="form-label mb-1" style="font-size:.78rem;font-weight:600;color:#1a3c6b">Classe (optionnel)</label>
    <select name="classe" class="form-select form-select-sm" data-ajax-nav-auto>
      <option value="">— Toutes —</option>
      <?php foreach ($classes as $c): ?><option value="<?= $c['IDClasses'] ?>" <?= $id_classe == $c['IDClasses'] ? 'selected' : '' ?>><?= h($c['DesignationClasses']) ?></option><?php endforeach; ?>
    </select>
  </div>
  <?php endif; ?>

  <div class="col-auto">
    <button type="button" class="btn btn-sm btn-abz-primary"
            onclick="afficherApercu('<?= APP_URL ?>/pdf/statistiques_arabe.php?onglet=<?= $onglet ?>&vue=<?= $vue ?>&trim=<?= $id_trim ?>&classe=<?= $id_classe ?>', 'Statistiques (arabe)', 'stat_generale_arabe', 'landscape')">
      <i class="bi bi-file-earmark-pdf me-1"></i>PDF
    </button>
  </div>
  <?php if (in_array($onglet, ['niveau', 'matiere', 'eleves'], true)): ?>
  <div class="col-auto">
    <a id="lienExcelStatsArabe" class="btn btn-sm btn-abz-gold"
       data-base="<?= APP_URL ?>/pages/statistiques_arabe/excel_stats_arabe.php?onglet=<?= $onglet ?>&vue=<?= $vue ?>&trim=<?= $id_trim ?>&classe=<?= $id_classe ?>"
       href="<?= APP_URL ?>/pages/statistiques_arabe/excel_stats_arabe.php?onglet=<?= $onglet ?>&vue=<?= $vue ?>&trim=<?= $id_trim ?>&classe=<?= $id_classe ?>">
      <i class="bi bi-file-earmark-excel me-1"></i>Excel
    </a>
  </div>
  <?php if (signature_etablissement_chemin()): ?>
  <div class="col-auto">
    <div class="chk-signature form-check form-check-inline mb-0" style="user-select:none">
      <input class="form-check-input" type="checkbox" id="chkSigExcelStatsArabe" onchange="appliquerSigExcelStatsArabe()">
      <label class="form-check-label small" for="chkSigExcelStatsArabe">Signature numérique</label>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; ?>
  <div class="col-auto">
    <a href="<?= APP_URL ?>/pages/statistiques_arabe/documents.php<?= $id_classe ? '?classe=' . $id_classe : '' ?>" class="btn btn-sm btn-abz-outline">
      <i class="bi bi-files me-1"></i>Documents de classe
    </a>
  </div>
</form>

<?php if (!$periode_ok): ?>
<div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i>Aucun trimestre en cours.</div>

<?php elseif ($onglet === 'eleves'): ?>
<?php
$classes_show = $id_classe ? array_filter($classes, fn($c) => (int) $c['IDClasses'] === $id_classe) : $classes;
$all_stats = []; $global_nb = 0; $global_admis = 0; $global_moys = [];
foreach ($classes_show as $c) {
    $st = stats_classe_arabe((int) $c['IDClasses'], $val_annee, $vue, $id_trim);
    $st['classe'] = $c['DesignationClasses'];
    $b = bilan_classe_genre_arabe((int) $c['IDClasses'], $val_annee, $vue, $id_trim);
    $st['felicit'] = $b['felicit']['T']; $st['encourag'] = $b['encourag']['T']; $st['tab'] = $b['tab']['T'];
    $st['avert_trav'] = $b['avert_trav']['T']; $st['blame_trav'] = $b['blame_trav']['T'];
    $all_stats[] = $st; $global_nb += $st['nb_classes']; $global_admis += $st['admis'];
    if ($st['moy'] !== null) $global_moys[] = $st['moy'];
}
$global_moy = $global_moys ? array_sum($global_moys) / count($global_moys) : null;
$global_taux = $global_nb > 0 ? round($global_admis / $global_nb * 100, 1) : 0;
?>
<div class="row g-2 mb-3">
  <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-num"><?= $global_nb ?></div><div class="stat-lbl">Élèves évalués</div></div></div>
  <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-num"><?= $fmt($global_moy) ?></div><div class="stat-lbl">Moyenne générale</div></div></div>
  <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-num"><?= $global_admis ?></div><div class="stat-lbl">Admis (≥10)</div></div></div>
  <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-num"><?= $global_taux ?>%</div><div class="stat-lbl">Taux de réussite</div></div></div>
</div>
<div class="table-responsive">
<table class="table tbl-stat table-hover mb-0">
  <thead><tr><th>Classe</th><th>Effectif</th><th>Filles / Garçons</th><th>Moyenne</th><th>Moy. Premier</th><th>Moy. Dernier</th><th>Admis</th><th>Taux réussite</th><th>Félicit.</th><th>Encour.</th><th>T.H</th><th>Avert. T.</th><th>Blâme T.</th></tr></thead>
  <tbody>
    <?php foreach ($all_stats as $st): ?>
    <tr>
      <td class="fw-semibold"><?= h($st['classe']) ?></td>
      <td><?= $st['nb'] ?></td>
      <td><span class="badge" style="background:#fce7f3;color:#9d174d"><?= $st['filles'] ?> F</span> <span class="badge" style="background:#dbeafe;color:#1e3a8a"><?= $st['garcons'] ?> G</span></td>
      <td class="fw-semibold <?= $st['moy'] !== null && $st['moy'] >= 10 ? 'text-success' : 'text-danger' ?>"><?= $fmt($st['moy']) ?></td>
      <td><?= $fmt($st['premier']) ?></td><td><?= $fmt($st['dernier']) ?></td>
      <td><?= $st['admis'] ?> / <?= $st['nb_classes'] ?></td>
      <td><div class="progress" style="height:14px;min-width:80px"><div class="progress-bar <?= $st['taux'] >= 50 ? 'bg-success' : 'bg-danger' ?>" style="width:<?= $st['taux'] ?>%"><?= $st['taux'] ?>%</div></div></td>
      <td><?= $st['felicit'] ?></td><td><?= $st['encourag'] ?></td><td><?= $st['tab'] ?></td><td><?= $st['avert_trav'] ?></td><td><?= $st['blame_trav'] ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php elseif ($onglet === 'matieres'): ?>
<?php
$classes_show = $id_classe ? array_filter($classes, fn($c) => (int) $c['IDClasses'] === $id_classe) : $classes;
foreach ($classes_show as $c):
    $stats_c = stats_par_matiere_arabe([(int) $c['IDClasses']], $val_annee, $vue, $id_trim);
    if (empty($stats_c)) continue;
    $baremes = [];
    foreach (matieres_classe_arabe_avec_bareme((int) $c['IDClasses'], $val_annee) as $m) {
        $baremes[$m['matiere_fr']] = $m['bareme'] !== null ? (int) $m['bareme']['total_points'] : 20;
    }
?>
  <h6 class="fw-bold mt-3" style="color:#1a3c6b"><i class="bi bi-door-open me-1"></i><?= h($c['DesignationClasses']) ?></h6>
  <div class="table-responsive mb-3">
  <table class="table tbl-stat table-hover mb-0">
    <thead><tr><th>Matière</th><th>Barème</th><th>Nb évalués</th><th>Moyenne classe</th><th>Min</th><th>Max</th><th>Admis</th><th>Taux</th></tr></thead>
    <tbody>
    <?php foreach ($stats_c as $d): ?>
    <tr>
      <td class="fw-semibold"><?= h($d['matiere']) ?></td>
      <td><?= $baremes[$d['matiere']] ?? '—' ?></td>
      <td><?= $d['nb'] ?></td>
      <td class="fw-bold <?= $d['moy'] !== null && $d['moy'] >= 10 ? 'text-success' : 'text-danger' ?>"><?= $fmt($d['moy']) ?></td>
      <td><?= $fmt($d['min']) ?></td><td><?= $fmt($d['max']) ?></td><td><?= $d['admis'] ?></td>
      <td><div class="progress" style="height:12px;min-width:60px"><div class="progress-bar <?= $d['taux'] >= 50 ? 'bg-success' : 'bg-danger' ?>" style="width:<?= $d['taux'] ?>%"><?= $d['taux'] ?>%</div></div></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endforeach; ?>

<?php elseif ($onglet === 'effectifs'): ?>
<?php
$niveaux_map = array_column(db_all("SELECT LibelleNiveau, OrdreNiveau FROM niveau ORDER BY OrdreNiveau"), 'OrdreNiveau', 'LibelleNiveau');
$par_niveau = [];
foreach ($classes as $c) {
    $nb = (int) db_val("SELECT COUNT(*) FROM inscrire i JOIN eleve e ON e.id_eleve=i.id_eleve WHERE i.IDClasses=? AND i.val_annee=? AND e.statut='actif'", [$c['IDClasses'], $val_annee]);
    $nf = (int) db_val("SELECT COUNT(*) FROM inscrire i JOIN eleve e ON e.id_eleve=i.id_eleve WHERE i.IDClasses=? AND i.val_annee=? AND e.statut='actif' AND e.Sexe_elv LIKE 'F%'", [$c['IDClasses'], $val_annee]);
    $par_niveau[$c['Niveau'] ?: 'Non défini'][] = ['classe' => $c['DesignationClasses'], 'nb' => $nb, 'filles' => $nf, 'garcons' => $nb - $nf];
}
uksort($par_niveau, fn($a, $b) => ($niveaux_map[$a] ?? 999) <=> ($niveaux_map[$b] ?? 999));
$toutes = array_merge(...array_values($par_niveau));
$total_eleves = array_sum(array_column($toutes, 'nb')); $total_filles = array_sum(array_column($toutes, 'filles'));
?>
<div class="row g-2 mb-3">
  <div class="col-4"><div class="stat-card"><div class="stat-num"><?= $total_eleves ?></div><div class="stat-lbl">Total élèves</div></div></div>
  <div class="col-4"><div class="stat-card"><div class="stat-num"><?= $total_filles ?></div><div class="stat-lbl">Filles</div></div></div>
  <div class="col-4"><div class="stat-card"><div class="stat-num"><?= $total_eleves - $total_filles ?></div><div class="stat-lbl">Garçons</div></div></div>
</div>
<?php foreach ($par_niveau as $niveau => $rows): ?>
  <h6 class="fw-bold mt-3" style="color:#1a3c6b"><i class="bi bi-diagram-3 me-1"></i>Niveau <?= h($niveau) ?></h6>
  <div class="table-responsive mb-3">
  <table class="table tbl-stat table-hover mb-0">
    <thead><tr><th>Classe</th><th>Effectif</th><th>Filles</th><th>Garçons</th><th>% Filles</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $pct = $r['nb'] > 0 ? round($r['filles'] / $r['nb'] * 100, 1) : 0; ?>
      <tr><td class="fw-semibold"><?= h($r['classe']) ?></td><td><strong><?= $r['nb'] ?></strong></td>
      <td><span class="badge" style="background:#fce7f3;color:#9d174d"><?= $r['filles'] ?></span></td>
      <td><span class="badge" style="background:#dbeafe;color:#1e3a8a"><?= $r['garcons'] ?></span></td>
      <td><div class="progress" style="height:12px;min-width:60px"><div class="progress-bar" style="width:<?= $pct ?>%;background:#9d174d"><?= $pct ?>%</div></div></td></tr>
    <?php endforeach; ?>
    <?php $st_nb = array_sum(array_column($rows, 'nb')); $st_f = array_sum(array_column($rows, 'filles')); ?>
    <tr style="background:#e8f0fe;font-weight:700"><td>Total niveau</td><td><?= $st_nb ?></td><td><?= $st_f ?></td><td><?= $st_nb - $st_f ?></td><td><?= $st_nb > 0 ? round($st_f / $st_nb * 100, 1) : 0 ?>%</td></tr>
    </tbody>
  </table>
  </div>
<?php endforeach; ?>

<?php elseif ($onglet === 'niveau'): ?>
<?php
$niveaux_map = array_column(db_all("SELECT LibelleNiveau, OrdreNiveau FROM niveau ORDER BY OrdreNiveau"), 'OrdreNiveau', 'LibelleNiveau');
$par_niveau = [];
foreach ($classes as $c) { $par_niveau[$c['Niveau'] ?: 'Non défini'][] = $c; }
uksort($par_niveau, fn($a, $b) => ($niveaux_map[$a] ?? 999) <=> ($niveaux_map[$b] ?? 999));
foreach ($par_niveau as $niveau => $classes_niveau) {
    $lignes = [];
    foreach ($classes_niveau as $c) { $lignes[] = ['classe' => $c['DesignationClasses'], 'bilan' => bilan_classe_genre_arabe((int) $c['IDClasses'], $val_annee, $vue, $id_trim)]; }
    render_tableau_bilan_genre_arabe('Niveau ' . $niveau, $lignes, $bilan_cols);
}
?>

<?php elseif ($onglet === 'matiere'): ?>
<?php
$classes_show = $id_classe ? array_filter($classes, fn($c) => (int) $c['IDClasses'] === $id_classe) : $classes;
$mat_stats = stats_par_matiere_arabe(array_map('intval', array_column($classes_show, 'IDClasses')), $val_annee, $vue, $id_trim);
?>
<div class="table-responsive">
<table class="table tbl-stat table-hover mb-0">
  <thead><tr><th>Matière</th><th>Nb évalués</th><th>Moyenne</th><th>Min</th><th>Max</th><th>Taux réussite</th></tr></thead>
  <tbody>
  <?php foreach ($mat_stats as $ms): ?>
    <tr>
      <td class="fw-semibold"><?= h($ms['matiere']) ?></td><td><?= $ms['nb'] ?></td>
      <td class="fw-bold <?= $ms['moy'] !== null && $ms['moy'] >= 10 ? 'text-success' : 'text-danger' ?>"><?= $fmt($ms['moy']) ?></td>
      <td><?= $fmt($ms['min']) ?></td><td><?= $fmt($ms['max']) ?></td>
      <td><div class="progress" style="height:12px;min-width:60px"><div class="progress-bar <?= $ms['taux'] >= 50 ? 'bg-success' : 'bg-danger' ?>" style="width:<?= $ms['taux'] ?>%"><?= $ms['taux'] ?>%</div></div></td>
    </tr>
  <?php endforeach; ?>
  <?php if (empty($mat_stats)): ?><tr><td colspan="6" class="text-center text-muted">Aucune donnée.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>

<?php elseif ($onglet === 'non_evalue'): ?>
<!-- Onglet Non évalués : écart entre inscrits et élèves évalués, liste
     nominative par classe avec la raison. -->
<?php
$classes_show_ne = $id_classe ? array_filter($classes, fn($c) => (int) $c['IDClasses'] === $id_classe) : $classes;

$lignes_ne = []; $total_inscrits = 0;
foreach ($classes_show_ne as $c) {
    $nb_inscrits = (int) db_val(
        "SELECT COUNT(*) FROM inscrire i JOIN eleve e ON e.id_eleve=i.id_eleve WHERE i.IDClasses=? AND i.val_annee=? AND e.statut='actif'",
        [(int) $c['IDClasses'], $val_annee]
    );
    $total_inscrits += $nb_inscrits;
    $ne = eleves_non_evalues_classe_arabe((int) $c['IDClasses'], $val_annee, $vue, $id_trim);
    foreach ($ne as $e) { $lignes_ne[] = $e + ['classe' => $c['DesignationClasses']]; }
}
$total_gap     = count($lignes_ne);
$total_evalues = $total_inscrits - $total_gap;
?>
<div class="row g-2 mb-3 cartes-genre">
  <div class="col-md-4">
    <div class="stat-card"><div class="stat-lbl mb-1">Effectif inscrit</div><div class="stat-num"><?= $total_inscrits ?></div></div>
  </div>
  <div class="col-md-4">
    <div class="stat-card"><div class="stat-lbl mb-1">Élèves évalués</div><div class="stat-num text-success"><?= $total_evalues ?></div></div>
  </div>
  <div class="col-md-4">
    <div class="stat-card"><div class="stat-lbl mb-1">Non évalué (écart)</div><div class="stat-num text-danger"><?= $total_gap ?></div></div>
  </div>
</div>

<div class="table-responsive">
<table class="table tbl-stat table-hover mb-0" style="font-size:.8rem">
  <thead><tr><th>Classe</th><th>Matricule</th><th>Nom et prénom</th><th>Sexe</th><th>Raison</th></tr></thead>
  <tbody>
    <?php foreach ($lignes_ne as $e): ?>
    <tr>
      <td class="fw-semibold"><?= h($e['classe']) ?></td>
      <td><?= h($e['Mat_elv']) ?></td>
      <td><?= h($e['Nom_elv'] . ' ' . ($e['Prenom_elv'] ?? '')) ?></td>
      <td><?= stripos($e['Sexe_elv'] ?? '', 'F') === 0 ? 'F' : 'M' ?></td>
      <td><span class="badge bg-danger"><?= h($e['raison']) ?></span></td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$lignes_ne): ?>
      <tr><td colspan="5" class="text-center text-muted py-3">Aucun écart pour cette sélection — tous les élèves inscrits sont évalués.</td></tr>
    <?php endif; ?>
  </tbody>
  <?php if ($lignes_ne): ?>
  <tfoot><tr style="background:#e8f0fe;font-weight:700"><td colspan="4">TOTAL</td><td><?= count($lignes_ne) ?> élève<?= count($lignes_ne) > 1 ? 's' : '' ?></td></tr></tfoot>
  <?php endif; ?>
</table>
</div>

<?php elseif ($onglet === 'enseignants' && $is_admin): ?>
<?php
$ens_list = db_all(
    "SELECT e.matricule_ens, e.nom_ens, e.prenom_ens, e.civilite_ens, e.id_fonction, COUNT(DISTINCT d.IDClasses) AS nb_classes
     FROM enseignant e LEFT JOIN dispenser d ON d.matricule_ens = e.matricule_ens AND d.val_annee = ?
     GROUP BY e.matricule_ens, e.nom_ens, e.prenom_ens, e.civilite_ens, e.id_fonction ORDER BY e.nom_ens",
    [$val_annee]
);
?>
<div class="row g-2 mb-3">
  <div class="col-4"><div class="stat-card"><div class="stat-num"><?= count($ens_list) ?></div><div class="stat-lbl">Enseignants</div></div></div>
  <div class="col-4"><div class="stat-card"><div class="stat-num"><?= count($classes) ?></div><div class="stat-lbl">Classes actives</div></div></div>
  <div class="col-4"><div class="stat-card"><div class="stat-num"><?= (int) db_val("SELECT COUNT(*) FROM inscrire i JOIN eleve e ON e.id_eleve=i.id_eleve WHERE i.val_annee=? AND e.statut='actif'", [$val_annee]) ?></div><div class="stat-lbl">Élèves inscrits</div></div></div>
</div>
<div class="table-responsive">
<table class="table tbl-stat table-hover mb-0">
  <thead><tr><th>Enseignant</th><th>Fonction</th><th>Classes</th></tr></thead>
  <tbody>
  <?php foreach ($ens_list as $e): ?>
  <tr>
    <td class="fw-semibold"><a href="<?= APP_URL ?>/pages/enseignants/voir.php?mat=<?= urlencode($e['matricule_ens']) ?>" class="text-decoration-none" style="color:#1a3c6b"><?= h(trim(($e['civilite_ens'] ?? '') . ' ' . mb_strtoupper($e['nom_ens']) . ' ' . ($e['prenom_ens'] ?? ''))) ?></a></td>
    <td><?= h(libelle_role($e['id_fonction'] ?? '') ?: '—') ?></td><td><?= $e['nb_classes'] ?></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

</div></div>
</div><!-- /#stat-zone -->

<?php if ($es_partiel) exit; ?>

<script>
function appliquerSigExcelStatsArabe() {
    const lien = document.getElementById('lienExcelStatsArabe');
    if (!lien) return;
    const sig = document.getElementById('chkSigExcelStatsArabe')?.checked;
    lien.href = lien.dataset.base + (sig ? '&signature=1' : '');
}
</script>

<?php
$ajax_zone_id = 'stat-zone';
require_once __DIR__ . '/../../layout/footer.php';
