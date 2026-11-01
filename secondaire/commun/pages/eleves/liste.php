<?php
// secondaire/commun/pages/eleves/liste.php — liste des élèves (école secondaire).
// Adapté de LAM_ABZ/pages/eleves/liste.php aux helpers SIGES ; mêmes onglets
// et exports que la liste du primaire (30/10/2026) : PDF (colonnes, ordre,
// alignement, tri), Excel, cartes scolaires, onglet « Import & matricules ».
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
exiger_connexion();

$annee    = get_annee_active();
$id_annee = (int) ($annee['id'] ?? 0);

$q         = trim($_GET['q'] ?? '');
$id_classe = (int) ($_GET['classe'] ?? 0);
$id_serie  = (int) ($_GET['serie'] ?? 0);
require_once __DIR__ . '/../../../../noyau/partage/eleves/_photos_lib.php';
// Onglet « Photos par classe » (commun primaire/secondaire,
// noyau/partage/eleves/_eleves_photos.php) — mêmes rôles que la fiche élève.
$peut_gerer = in_array(role_connecte(), photos_roles(), true)
           && !(function_exists('est_lecture_seule') && est_lecture_seule());
// Import Excel : mêmes rôles que secondaire/commun/pages/eleves/import.php.
$peut_importer = in_array(role_connecte(), ['ADMIN', 'PROVISEUR', 'FONDATEUR', 'SECRETAIRE'], true)
              && !(function_exists('est_lecture_seule') && est_lecture_seule());
// Désactiver / réactiver / supprimer : Admin, Proviseur/Principal, fondateur, propriétaire.
$peut_supprimer = sec_peut_supprimer() && !(function_exists('est_lecture_seule') && est_lecture_seule());
// « incompletes » = onglet Fiches incomplètes (noyau/partage/eleves/_eleves_incompletes.php).
// « outils » = onglet Import & matricules (_eleves_outils.php).
$statut    = in_array($_GET['statut'] ?? '', ['desactive', 'photos', 'incompletes', 'outils'], true) ? $_GET['statut'] : 'actif';
if (in_array($statut, ['photos', 'incompletes'], true) && !$peut_gerer) $statut = 'actif';
if ($statut === 'outils' && !$peut_importer) $statut = 'actif';
$vue_outils = $statut === 'outils';
$vue_incompletes = $statut === 'incompletes';
$vue_photos = $statut === 'photos' || $vue_incompletes || $vue_outils;   // pas de liste paginée
$tri       = in_array($_GET['tri'] ?? '', ['nom', 'niu', 'mat', 'sexe', 'classe', 'date'], true) ? $_GET['tri'] : 'nom';
$ordre     = ($_GET['ordre'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
$order_sql = [
    'nom'    => "e.nom $ordre, e.prenom $ordre",
    'niu'    => "e.niu IS NULL, e.niu $ordre, e.nom, e.prenom",   // NIU affiché à la place du matricule interne (31/10/2026)
    'mat'    => "e.matricule $ordre",
    'sexe'   => "e.sexe $ordre, e.nom, e.prenom",
    'classe' => "c.ordre $ordre, c.designation $ordre, e.nom, e.prenom",
    'date'   => "e.date_naiss $ordre, e.nom, e.prenom",
][$tri];
$page      = max(1, (int) ($_GET['page'] ?? 1));
$pp        = (int) ($_GET['pp'] ?? 25);
if (!in_array($pp, [10, 25, 50, 100], true)) $pp = 25;
$offset    = ($page - 1) * $pp;

$where  = ['e.statut = ?'];
$params = [$vue_photos ? 'actif' : $statut];
if ($q !== '') {
    $where[]  = '(e.matricule LIKE ? OR e.niu LIKE ? OR e.nom LIKE ? OR e.prenom LIKE ?)';
    $like     = "%$q%";
    $params   = array_merge($params, [$like, $like, $like, $like]);
}
// Enseignant / SG : seulement les élèves de leurs classes (comme au primaire).
$ids_classes_vis = sec_classes_ids_visibles();
if ($ids_classes_vis !== null) {
    if (!$ids_classes_vis) {
        $where[] = '1=0';
    } else {
        $where[] = 'i.id_classe IN (' . implode(',', array_fill(0, count($ids_classes_vis), '?')) . ')';
        $params  = array_merge($params, $ids_classes_vis);
    }
    if ($id_classe && !in_array($id_classe, $ids_classes_vis, true)) $id_classe = 0;
}
if ($id_classe) { $where[] = 'i.id_classe = ?'; $params[] = $id_classe; }
if ($id_serie)  { $where[] = 'i.id_serie = ?';  $params[] = $id_serie; }
$sql_where = 'WHERE ' . implode(' AND ', $where);

$total = (int) db_val(
    "SELECT COUNT(*) FROM eleve e
     LEFT JOIN inscription i ON i.id_eleve=e.id AND i.id_annee=?
     LEFT JOIN classe c ON c.id=i.id_classe
     $sql_where",
    array_merge([$id_annee], $params)
);
$eleves = db_all(
    "SELECT e.*, c.designation AS classe, s.libelle AS serie
     FROM eleve e
     LEFT JOIN inscription i ON i.id_eleve=e.id AND i.id_annee=?
     LEFT JOIN classe c ON c.id=i.id_classe
     LEFT JOIN serie s ON s.id=i.id_serie
     $sql_where
     ORDER BY $order_sql
     LIMIT $pp OFFSET $offset",
    array_merge([$id_annee], $params)
);
$classes = db_all(
    "SELECT c.id, c.designation, (
        SELECT COUNT(*) FROM inscription i JOIN eleve e ON e.id=i.id_eleve
        WHERE i.id_classe=c.id AND i.id_annee=? AND e.statut='actif'
     ) AS nb
     FROM classe c WHERE c.archivee=0 ORDER BY c.ordre, c.designation",
    [$id_annee]
);
if ($ids_classes_vis !== null) {
    $classes = array_values(array_filter($classes, fn($c) => in_array((int) $c['id'], $ids_classes_vis, true)));
}
$series = db_all("SELECT * FROM serie ORDER BY libelle");
$nb_pages = max(1, (int) ceil($total / $pp));
$base = APP_URL . '/secondaire/commun/pages/eleves/liste.php?' . http_build_query(array_filter([
    'q' => $q, 'classe' => $id_classe ?: null, 'serie' => $id_serie ?: null,
    'statut' => $statut !== 'actif' ? $statut : null,
    'tri' => $tri !== 'nom' ? $tri : null, 'ordre' => $ordre === 'DESC' ? 'desc' : null, 'pp' => $pp !== 25 ? $pp : null,
]));
// En-tête de colonne cliquable : trie (et inverse l'ordre au 2e clic).
$lien_tri = function (string $col, string $lib) use ($q, $id_classe, $id_serie, $statut, $tri, $ordre, $pp): string {
    $nv_ordre = ($tri === $col && $ordre === 'ASC') ? 'desc' : 'asc';
    $url = '?' . http_build_query(array_filter([
        'q' => $q, 'classe' => $id_classe ?: null, 'serie' => $id_serie ?: null,
        'statut' => $statut !== 'actif' ? $statut : null, 'pp' => $pp !== 25 ? $pp : null,
        'tri' => $col, 'ordre' => $nv_ordre,
    ]));
    $ic = $tri === $col ? ($ordre === 'ASC' ? ' <i class="bi bi-caret-up-fill"></i>' : ' <i class="bi bi-caret-down-fill"></i>') : '';
    return '<a href="' . h($url) . '" class="text-reset text-decoration-none">' . h($lib) . $ic . '</a>';
};
$classe_nom_choisie = '';
foreach ($classes as $c) if ((int) $c['id'] === $id_classe) $classe_nom_choisie = $c['designation'];
$formats_carte = db_all("SELECT id, libelle FROM format_carte ORDER BY id");

$titre_page = 'Élèves';
require_once __DIR__ . '/../../../../noyau/layout/header.php';
?>

<div class="page-titre d-flex justify-content-between align-items-center">
  <div>
    <h4><i class="bi bi-people me-1 text-primary"></i>Élèves</h4>
    <div class="sub"><?= $total ?> élève(s) <?= $statut === 'actif' ? 'actif(s)' : 'désactivé(s)' ?></div>
  </div>
  <div class="d-flex gap-2">
    <?php /* « Importer » : déplacé dans l'onglet « Import & matricules » (comme au primaire). */ ?>
    <a href="<?= APP_URL ?>/secondaire/commun/pages/eleves/form.php" class="btn btn-primary btn-sm">
      <i class="bi bi-plus-lg me-1"></i>Nouvel élève
    </a>
  </div>
</div>

<?= flash_html() ?>

<ul class="nav nav-tabs mb-2">
  <?php $qs_tab = array_filter(['q' => $q, 'classe' => $id_classe ?: null, 'serie' => $id_serie ?: null, 'pp' => $pp !== 25 ? $pp : null]); ?>
  <li class="nav-item">
    <a class="nav-link <?= $statut === 'actif' ? 'active' : '' ?>" href="?<?= http_build_query($qs_tab) ?>">
      <i class="bi bi-check-circle me-1"></i>Actifs
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $statut === 'desactive' ? 'active' : '' ?>"
       href="?<?= http_build_query(array_merge($qs_tab, ['statut' => 'desactive'])) ?>">
      <i class="bi bi-slash-circle me-1"></i>Désactivés
    </a>
  </li>
  <?php if ($peut_importer): ?>
  <li class="nav-item">
    <a class="nav-link <?= $vue_outils ? 'active' : '' ?>"
       href="?<?= http_build_query(array_merge($qs_tab, ['statut' => 'outils'])) ?>">
      <i class="bi bi-upc-scan me-1"></i>Import &amp; matricules
    </a>
  </li>
  <?php endif; ?>
  <?php if ($peut_gerer): ?>
  <li class="nav-item">
    <a class="nav-link <?= $statut === 'photos' ? 'active' : '' ?>"
       href="?<?= http_build_query(array_merge($qs_tab, ['statut' => 'photos'])) ?>">
      <i class="bi bi-camera me-1"></i>Photos par classe
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $vue_incompletes ? 'active' : '' ?>"
       href="?<?= http_build_query(array_merge($qs_tab, ['statut' => 'incompletes'])) ?>">
      <i class="bi bi-clipboard-x me-1"></i>Fiches incomplètes
    </a>
  </li>
  <?php endif; ?>
</ul>

<div class="card mb-2">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-12 col-md-3">
        <label class="form-label">Recherche</label>
        <div class="input-group input-group-sm">
          <span class="input-group-text"><i class="bi bi-search"></i></span>
          <input type="text" name="q" class="form-control" placeholder="Nom, NIU, matricule…" value="<?= h($q) ?>">
        </div>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Classe</label>
        <select name="classe" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="">— Toutes les classes —</option>
          <?php foreach ($classes as $c): ?>
            <option value="<?= (int) $c['id'] ?>" <?= $id_classe === (int) $c['id'] ? 'selected' : '' ?>>
              <?= h($c['designation']) ?> (<?= (int) $c['nb'] ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Série / LV2</label>
        <select name="serie" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="">— Toutes —</option>
          <?php foreach ($series as $s): ?>
            <option value="<?= (int) $s['id'] ?>" <?= $id_serie === (int) $s['id'] ? 'selected' : '' ?>>
              <?= h($s['libelle']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-4 col-md-1">
        <label class="form-label">/page</label>
        <select name="pp" class="form-select form-select-sm" onchange="this.form.submit()">
          <?php foreach ([10, 25, 50, 100] as $n): ?>
            <option value="<?= $n ?>" <?= $pp === $n ? 'selected' : '' ?>><?= $n ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($statut !== 'actif'): ?><input type="hidden" name="statut" value="<?= h($statut) ?>"><?php endif; ?>
      <?php if ($tri !== 'nom' || $ordre === 'DESC'): ?>
        <input type="hidden" name="tri" value="<?= h($tri) ?>"><input type="hidden" name="ordre" value="<?= $ordre === 'DESC' ? 'desc' : 'asc' ?>">
      <?php endif; ?>
      <div class="col-auto d-flex gap-1">
        <button class="btn btn-primary btn-sm"><i class="bi bi-search"></i></button>
        <?php if ($q || $id_classe || $id_serie): ?>
          <a href="<?= APP_URL ?>/secondaire/commun/pages/eleves/liste.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-lg"></i></a>
        <?php endif; ?>
      </div>
      <?php $titre_export = $id_classe ? 'Liste des élèves de ' . $classe_nom_choisie : 'Liste des élèves de toutes les classes'; ?>
      <div class="col d-flex gap-1 justify-content-md-end flex-wrap">
        <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#modalExport"
                title="<?= h($titre_export) ?>">
          <i class="bi bi-file-earmark-pdf me-1"></i>PDF
        </button>
        <a href="<?= APP_URL ?>/secondaire/commun/pages/eleves/excel.php?<?= http_build_query(array_filter(['classe' => $id_classe ?: null, 'serie' => $id_serie ?: null])) ?>"
           class="btn btn-outline-success btn-sm" title="<?= h($titre_export) ?>">
          <i class="bi bi-file-earmark-excel me-1"></i>Excel
        </a>
        <?php if ($id_classe): ?>
        <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalCartes"
                title="<?= h('Cartes scolaires de ' . $classe_nom_choisie) ?>">
          <i class="bi bi-credit-card me-1"></i>Cartes
        </button>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<?php if ($vue_outils): ?>
  <?php require __DIR__ . '/_eleves_outils.php'; ?>
<?php elseif ($vue_incompletes): ?>
  <?php require __DIR__ . '/../../../../noyau/partage/eleves/_eleves_incompletes.php'; ?>
<?php elseif ($vue_photos): ?>
  <?php require __DIR__ . '/../../../../noyau/partage/eleves/_eleves_photos.php'; ?>
<?php else: ?>

<!-- Barre d'actions groupées (démasquée par JS dès qu'une case est cochée) -->
<div class="card mb-2 d-none" id="barreMasse">
  <div class="card-body py-2 d-flex flex-wrap align-items-center gap-2">
    <span class="fw-semibold" style="font-size:.82rem"><span id="nbSelection">0</span> sélectionné(s)</span>
    <?php if (!$peut_supprimer): ?>
    <?php elseif ($statut === 'actif'): ?>
      <button type="button" class="btn btn-sm" style="background:#fff3cd;color:#856404" onclick="masseDesactiver()">
        <i class="bi bi-toggle-on me-1"></i>Désactiver
      </button>
    <?php else: ?>
      <button type="button" class="btn btn-sm" style="background:#d1fae5;color:#065f46" onclick="masseReactiver()">
        <i class="bi bi-toggle-off me-1"></i>Réactiver
      </button>
    <?php endif; ?>
    <div class="d-flex align-items-center gap-1">
      <select id="selClasseCible" class="form-select form-select-sm" style="width:auto;max-width:220px">
        <option value="">— Transférer vers —</option>
        <?php foreach ($classes as $c): ?>
          <option value="<?= (int) $c['id'] ?>"><?= h($c['designation']) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="button" class="btn btn-sm btn-outline-primary" onclick="masseTransferer()">
        <i class="bi bi-arrow-left-right me-1"></i>Transférer
      </button>
    </div>
    <div class="d-flex align-items-center gap-1">
      <select id="selSerieCible" class="form-select form-select-sm" style="width:auto;max-width:200px">
        <option value="">— Aucune série —</option>
        <?php foreach ($series as $s): ?>
          <option value="<?= (int) $s['id'] ?>"><?= h($s['libelle']) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="button" class="btn btn-sm btn-outline-primary" onclick="masseAffecterSerie()">
        <i class="bi bi-translate me-1"></i>Affecter série/LV2
      </button>
    </div>
    <?php if ($peut_supprimer): ?>
    <button type="button" class="btn btn-sm btn-outline-danger ms-auto" onclick="masseSupprimer()">
      <i class="bi bi-trash me-1"></i>Supprimer définitivement
    </button>
    <?php endif; ?>
  </div>
</div>
<form id="formMasseAction" method="post">
  <?= csrf_champ() ?>
  <input type="hidden" name="q" value="<?= h($q) ?>">
  <input type="hidden" name="classe" value="<?= (int) $id_classe ?>">
  <input type="hidden" name="statut" value="<?= h($statut) ?>">
</form>

<div class="card">
  <div class="table-responsive">
    <table class="table table-abz table-hover align-middle mb-0">
      <thead>
        <tr>
          <th style="width:28px"><input type="checkbox" onchange="toggleTous(this)"></th>
          <th style="width:34px">#</th>
          <th><?= $lien_tri('nom', 'Élève') ?></th>
          <th><?= $lien_tri('niu', 'NIU') ?></th>
          <th><?= $lien_tri('sexe', 'Sexe') ?></th>
          <th><?= $lien_tri('classe', 'Classe') ?></th>
          <th>Série/LV2</th>
          <th><?= $lien_tri('date', 'Date naiss.') ?></th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($eleves)): ?>
          <tr><td colspan="9" class="text-center text-muted py-4">
            <i class="bi bi-inbox" style="font-size:2rem;opacity:.3;display:block;margin-bottom:.4rem"></i>
            Aucun élève trouvé.
          </td></tr>
        <?php else: $no = $offset + 1; foreach ($eleves as $e): ?>
          <tr>
            <td><input type="checkbox" class="chk-eleve" value="<?= (int) $e['id'] ?>" onchange="majBarreMasse()"></td>
            <td class="text-muted" style="font-size:.72rem"><?= $no++ ?></td>
            <td>
              <a href="<?= APP_URL ?>/secondaire/commun/pages/eleves/voir.php?id=<?= (int) $e['id'] ?>"
                 class="fw-semibold text-decoration-none" style="font-size:.82rem">
                <?= h(mb_strtoupper($e['nom']) . ' ' . ($e['prenom'] ?? '')) ?>
              </a>
            </td>
            <td><?= niu_eleve($e) !== '' ? '<span class="badge-code">' . h(niu_eleve($e)) . '</span>' : '<span class="text-muted">—</span>' ?></td>
            <td><?= $e['sexe'] === 'F' ? '<span class="badge-f">F</span>' : '<span class="badge-m">M</span>' ?></td>
            <td style="font-size:.78rem"><?= h($e['classe'] ?? '—') ?></td>
            <td style="font-size:.78rem"><?= $e['serie'] ? h($e['serie']) : '<span class="text-muted">—</span>' ?></td>
            <td style="font-size:.78rem;color:#6b7280"><?= h(date_fr($e['date_naiss'])) ?></td>
            <td>
              <div class="d-flex justify-content-end gap-1">
                <a href="<?= APP_URL ?>/secondaire/commun/pages/eleves/voir.php?id=<?= (int) $e['id'] ?>"
                   class="btn btn-sm" style="background:#eef2ff;color:#1e4fd8;padding:3px 7px">
                  <i class="bi bi-eye" style="font-size:.78rem"></i>
                </a>
                <a href="<?= APP_URL ?>/secondaire/commun/pages/eleves/form.php?id=<?= (int) $e['id'] ?>"
                   class="btn btn-sm btn-light" style="padding:3px 7px">
                  <i class="bi bi-pencil" style="font-size:.78rem"></i>
                </a>
                <?php if (!$peut_supprimer): ?>
                <?php elseif ($statut === 'actif'): ?>
                  <a href="<?= APP_URL ?>/secondaire/commun/pages/eleves/statut.php?id=<?= (int) $e['id'] ?>&csrf=<?= csrf_generer() ?>"
                     class="btn btn-sm" style="background:#fff3cd;color:#856404;padding:3px 7px"
                     title="Désactiver" onclick="return confirm('Désactiver cet élève ?')">
                    <i class="bi bi-toggle-on" style="font-size:.78rem"></i>
                  </a>
                <?php else: ?>
                  <a href="<?= APP_URL ?>/secondaire/commun/pages/eleves/statut.php?id=<?= (int) $e['id'] ?>&csrf=<?= csrf_generer() ?>"
                     class="btn btn-sm" style="background:#d1fae5;color:#065f46;padding:3px 7px"
                     title="Réactiver" onclick="return confirm('Réactiver cet élève ?')">
                    <i class="bi bi-toggle-off" style="font-size:.78rem"></i>
                  </a>
                <?php endif; ?>
                <?php if ($peut_supprimer): ?>
                  <button type="button" class="btn btn-sm btn-light text-danger" style="padding:3px 7px" title="Supprimer définitivement"
                          onclick="supprimerEleve(<?= (int) $e['id'] ?>, <?= h(json_encode(trim(mb_strtoupper((string) $e['nom']) . ' ' . ($e['prenom'] ?? '')))) ?>)">
                    <i class="bi bi-trash" style="font-size:.78rem"></i>
                  </button>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($nb_pages > 1): ?>
  <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
    <div class="text-muted" style="font-size:.75rem">Page <?= $page ?> / <?= $nb_pages ?> · <?= $total ?> élève(s)</div>
    <?= pagination_html($page, $nb_pages, $base) ?>
  </div>
<?php endif; ?>

<?php endif; // fin liste vs. onglet « Photos par classe » ?>

<?php if ($peut_gerer) require __DIR__ . '/../../../../noyau/partage/eleves/_photos_outils.php'; ?>

<!-- ═══ Modal export PDF — colonnes, ordre d'affichage, alignement et tri
     (comme au primaire) → secondaire/commun/pdf/liste_eleves.php ═══ -->
<div class="modal fade" id="modalExport" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title fw-bold"><i class="bi bi-file-earmark-pdf me-1 text-danger"></i>Impression PDF — <?= h($id_classe ? $classe_nom_choisie : 'toutes les classes') ?></h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body py-2">
        <p class="text-muted mb-2" style="font-size:.75rem">
          Colonnes à inclure (cocher), ordre d'affichage (flèches) et alignement du contenu :
        </p>
        <?php
        $colonnes_export = ['no' => 'N°', 'nom' => 'Nom et Prénoms', 'date' => 'Date naiss.', 'lieu' => 'Lieu naiss.',
                            'sexe' => 'Sexe', 'matricule' => 'Matricule', 'niu' => 'NIU', 'classe' => 'Classe',
                            'serie' => 'Série/LV2', 'statut' => 'Nouveau / Redoublant', 'telephone' => 'Téléphone'];
        $coches_export   = ['no', 'nom', 'date', 'lieu', 'sexe', 'niu'];
        if (!$id_classe) $coches_export[] = 'classe';
        ?>
        <ul class="list-group" id="listeColonnesExport">
          <?php foreach ($colonnes_export as $k => $lbl): ?>
            <li class="list-group-item d-flex align-items-center gap-2 py-1 px-2" data-col="<?= $k ?>">
              <input class="form-check-input mt-0 flex-shrink-0" type="checkbox" id="col_<?= $k ?>" value="<?= $k ?>"
                     <?= in_array($k, $coches_export, true) ? 'checked' : '' ?>>
              <label class="form-check-label flex-grow-1" for="col_<?= $k ?>" style="font-size:.78rem"><?= $lbl ?></label>
              <select class="form-select form-select-sm flex-shrink-0" style="width:auto;font-size:.72rem" data-align title="Alignement">
                <option value="L" selected>⟸ Gauche</option>
                <option value="C">↔ Centre</option>
                <option value="R">Droite ⟹</option>
              </select>
              <button type="button" class="btn btn-sm btn-light py-0 px-1" style="line-height:1"
                      onclick="deplacerColonneExport(this,-1)" title="Monter"><i class="bi bi-arrow-up"></i></button>
              <button type="button" class="btn btn-sm btn-light py-0 px-1" style="line-height:1"
                      onclick="deplacerColonneExport(this,1)" title="Descendre"><i class="bi bi-arrow-down"></i></button>
            </li>
          <?php endforeach; ?>
        </ul>
        <div class="row g-2 mt-2 align-items-end">
          <div class="col-7">
            <label class="form-label mb-1" style="font-size:.75rem"><i class="bi bi-sort-alpha-down me-1"></i>Trier la liste par</label>
            <select id="selTriPdf" class="form-select form-select-sm" style="font-size:.78rem">
              <option value="nom" selected>Nom et prénoms</option>
              <option value="matricule">Matricule</option>
              <option value="classe">Classe (puis nom)</option>
              <option value="sexe">Sexe (puis nom)</option>
              <option value="date">Date de naissance</option>
              <option value="lieu">Lieu de naissance</option>
              <option value="niu">NIU</option>
              <option value="statut">Nouveau / Redoublant (puis nom)</option>
            </select>
          </div>
          <div class="col-5">
            <label class="form-label mb-1" style="font-size:.75rem">Ordre</label>
            <select id="selOrdrePdf" class="form-select form-select-sm" style="font-size:.78rem">
              <option value="asc" selected>Croissant (A → Z)</option>
              <option value="desc">Décroissant (Z → A)</option>
            </select>
          </div>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button class="btn btn-danger btn-sm" onclick="lancerPdfListe()">
          <i class="bi bi-printer me-1"></i>Générer
        </button>
        <button class="btn btn-light btn-sm" data-bs-dismiss="modal">Annuler</button>
      </div>
    </div>
  </div>
</div>

<?php if ($id_classe): ?>
<div class="modal fade" id="modalCartes" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title fw-bold"><i class="bi bi-credit-card me-1 text-primary"></i>Cartes scolaires — <?= h($classe_nom_choisie) ?></h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body py-2">
        <label class="form-label">Modèle</label>
        <select id="selModele" class="form-select form-select-sm mb-2">
          <option value="1">Modèle 1 — Officiel bilingue</option>
          <option value="2">Modèle 2 — Encadré classique</option>
          <option value="3">Modèle 3 — Bandeau année</option>
          <option value="4">Modèle 4 — Badge centré</option>
          <option value="5">Modèle 5 — Minimaliste</option>
        </select>
        <label class="form-label">Format</label>
        <select id="selFormat" class="form-select form-select-sm mb-2">
          <?php foreach ($formats_carte as $f): ?>
            <option value="<?= (int) $f['id'] ?>"><?= h(str_replace('?', '×', $f['libelle'])) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-check mb-1">
          <input class="form-check-input" type="checkbox" id="chkVerso" onchange="document.getElementById('optFlip').classList.toggle('d-none', !this.checked)">
          <label class="form-check-label" for="chkVerso" style="font-size:.82rem">Imprimer le verso (recto-verso)</label>
        </div>
        <div id="optFlip" class="d-none">
          <label class="form-label" style="font-size:.78rem">Sens de retournement de la feuille</label>
          <select id="selFlip" class="form-select form-select-sm">
            <option value="long">Bord long (comme un livre)</option>
            <option value="short">Bord court (comme un bloc-notes)</option>
          </select>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button class="btn btn-primary btn-sm" onclick="lancerCartes()">
          <i class="bi bi-printer me-1"></i>Générer
        </button>
        <button class="btn btn-light btn-sm" data-bs-dismiss="modal">Annuler</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
// Export PDF / cartes — aperçu via afficherApercu() (noyau/layout/footer.php).
function deplacerColonneExport(btn, sens) {
  var li = btn.closest('li');
  if (sens === -1 && li.previousElementSibling) {
    li.parentElement.insertBefore(li, li.previousElementSibling);
  } else if (sens === 1 && li.nextElementSibling) {
    li.parentElement.insertBefore(li.nextElementSibling, li);
  }
}
function lancerPdfListe() {
  var lignes = Array.prototype.filter.call(document.querySelectorAll('#listeColonnesExport li'),
    function (li) { return li.querySelector('input').checked; });
  if (!lignes.length) { alert('Choisissez au moins une colonne.'); return; }
  var cols  = lignes.map(function (li) { return li.dataset.col; }).join(',');
  var align = lignes.map(function (li) { return li.querySelector('[data-align]').value; }).join(',');
  afficherApercuApresFermeture('modalExport',
    '<?= APP_URL ?>/secondaire/commun/pdf/liste_eleves.php?annee=<?= $id_annee ?>&classe=<?= $id_classe ?>&serie=<?= $id_serie ?>'
      + '&cols=' + cols + '&align=' + align
      + '&tri=' + document.getElementById('selTriPdf').value + '&ordre=' + document.getElementById('selOrdrePdf').value,
    'Liste des élèves', 'liste_eleves', 'portrait');
}
function lancerCartes() {
  var modele = document.getElementById('selModele').value;
  var verso  = document.getElementById('chkVerso').checked ? '1' : '0';
  afficherApercuApresFermeture('modalCartes',
    '<?= APP_URL ?>/secondaire/commun/pdf/cartes.php?annee=<?= $id_annee ?>&classe=<?= $id_classe ?>&modele=' + modele
      + '&format=' + document.getElementById('selFormat').value + '&verso=' + verso + '&flip=' + document.getElementById('selFlip').value,
    'Cartes scolaires', verso === '1' ? 'carte_verso' : ('carte_' + modele), 'card');
}
</script>

<script>
// Actions groupées (sélection multiple) — secondaire/commun/pages/eleves/liste.php.
function getCheckedIds() {
  return Array.prototype.slice.call(document.querySelectorAll('.chk-eleve:checked')).map(function (c) { return c.value; });
}
function majBarreMasse() {
  var n = getCheckedIds().length;
  document.getElementById('nbSelection').textContent = n;
  document.getElementById('barreMasse').classList.toggle('d-none', n === 0);
}
function toggleTous(cb) {
  document.querySelectorAll('.chk-eleve').forEach(function (el) { el.checked = cb.checked; });
  majBarreMasse();
}
function masseSubmit(actionUrl, extra) {
  var ids = getCheckedIds();
  if (!ids.length) { alert('Sélectionnez au moins un élève.'); return; }
  var f = document.getElementById('formMasseAction');
  f.action = actionUrl;
  f.querySelectorAll('input[name="ids[]"]').forEach(function (el) { el.remove(); });
  ids.forEach(function (id) {
    var inp = document.createElement('input');
    inp.type = 'hidden'; inp.name = 'ids[]'; inp.value = id;
    f.appendChild(inp);
  });
  Object.keys(extra || {}).forEach(function (k) {
    var el = f.querySelector('[name="' + k + '"]');
    if (!el) { el = document.createElement('input'); el.type = 'hidden'; el.name = k; f.appendChild(el); }
    el.value = extra[k];
  });
  f.submit();
}
function masseDesactiver() {
  if (confirm('Désactiver ' + getCheckedIds().length + ' élève(s) sélectionné(s) ?')) {
    masseSubmit('<?= APP_URL ?>/secondaire/commun/pages/eleves/statut_masse.php', { vers: 'desactive' });
  }
}
function masseReactiver() {
  if (confirm('Réactiver ' + getCheckedIds().length + ' élève(s) sélectionné(s) ?')) {
    masseSubmit('<?= APP_URL ?>/secondaire/commun/pages/eleves/statut_masse.php', { vers: 'actif' });
  }
}
function masseSupprimer() {
  if (confirm('Supprimer DÉFINITIVEMENT ' + getCheckedIds().length + ' élève(s) ? Action IRRÉVERSIBLE : leurs notes, absences, paiements et inscriptions seront aussi effacés définitivement. Préférez « Désactiver » si vous n\'êtes pas certain.')) {
    masseSubmit('<?= APP_URL ?>/secondaire/commun/pages/eleves/supprimer_masse.php', {});
  }
}
function supprimerEleve(id, nom) {
  if (!confirm('Supprimer DÉFINITIVEMENT ' + nom + ' ? Action IRRÉVERSIBLE : ses notes, absences, paiements et inscriptions seront aussi effacés. Préférez « Désactiver » si vous n\'êtes pas certain.')) return;
  var f = document.getElementById('formMasseAction');
  f.action = '<?= APP_URL ?>/secondaire/commun/pages/eleves/supprimer_masse.php';
  f.querySelectorAll('input[name="ids[]"]').forEach(function (el) { el.remove(); });
  var inp = document.createElement('input');
  inp.type = 'hidden'; inp.name = 'ids[]'; inp.value = id;
  f.appendChild(inp);
  f.submit();
}
function masseTransferer() {
  var sel = document.getElementById('selClasseCible');
  if (!sel.value) { alert('Choisissez une classe de destination.'); return; }
  if (confirm('Transférer ' + getCheckedIds().length + ' élève(s) vers ' + sel.options[sel.selectedIndex].text.trim() + ' ?')) {
    masseSubmit('<?= APP_URL ?>/secondaire/commun/pages/eleves/transferer_masse.php', { id_classe_cible: sel.value });
  }
}
function masseAffecterSerie() {
  var sel = document.getElementById('selSerieCible');
  var libelle = sel.value ? sel.options[sel.selectedIndex].text.trim() : 'Aucune série';
  if (confirm('Affecter la série « ' + libelle + ' » à ' + getCheckedIds().length + ' élève(s) ?')) {
    masseSubmit('<?= APP_URL ?>/secondaire/commun/pages/eleves/affecter_serie_masse.php', { id_serie_cible: sel.value });
  }
}
</script>

<?php require_once __DIR__ . '/../../../../noyau/layout/footer.php'; ?>
