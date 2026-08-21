<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_connexion();

$annee    = get_annee_active();
$id_annee = (int)$annee['id'];

// ── Paramètres de filtre / tri / pagination ──────────────
$q         = trim($_GET['q'] ?? '');
$id_classe = (int)($_GET['classe'] ?? 0);
$tri       = in_array($_GET['tri'] ?? '', ['nom','matricule','sexe','classe']) ? $_GET['tri'] : 'nom';
$ordre     = ($_GET['ordre'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
$page      = max(1, (int)($_GET['page'] ?? 1));
$pp        = in_array((int)($_GET['pp'] ?? 25), [10,25,50,100]) ? (int)($_GET['pp'] ?? 25) : 25;
$offset    = ($page - 1) * $pp;

// ── Construction de la requête ───────────────────────────
$where   = ["e.statut = 'actif'"];
$params  = [];

if ($q !== '') {
    $where[]  = "(e.matricule LIKE ? OR e.nom LIKE ? OR e.prenom LIKE ?)";
    $like     = "%$q%";
    $params   = array_merge($params, [$like, $like, $like]);
}
if ($id_classe) {
    $where[]  = "i.id_classe = ?";
    $params[] = $id_classe;
}

$sql_where = 'WHERE ' . implode(' AND ', $where);

$order_map = [
    'nom'       => 'e.nom, e.prenom',
    'matricule' => 'e.matricule',
    'sexe'      => 'e.sexe, e.nom',
    'classe'    => 'c.designation, e.nom',
];
$order_sql = ($order_map[$tri] ?? 'e.nom') . ' ' . $ordre;

$total  = (int) db_val(
    "SELECT COUNT(*) FROM eleve e
     LEFT JOIN inscription i ON i.id_eleve=e.id AND i.id_annee=$id_annee
     LEFT JOIN classe c ON c.id=i.id_classe
     $sql_where", $params);

$eleves = db_all(
    "SELECT e.*, c.designation AS classe, i.statut AS statut_insc
     FROM eleve e
     LEFT JOIN inscription i ON i.id_eleve=e.id AND i.id_annee=$id_annee
     LEFT JOIN classe c ON c.id=i.id_classe
     $sql_where
     ORDER BY $order_sql
     LIMIT $pp OFFSET $offset", $params);

$classes = db_all("SELECT c.*, (
    SELECT COUNT(*) FROM inscription i
    JOIN eleve e ON e.id=i.id_eleve
    WHERE i.id_classe=c.id AND i.id_annee=$id_annee AND e.statut='actif'
) AS nb FROM classe c WHERE c.archivee=0 ORDER BY c.ordre, c.designation");

$nb_pages = max(1, (int)ceil($total / $pp));

// URL de base pour pagination / tri
$base = APP_URL . '/pages/eleves/liste.php?' . http_build_query(array_filter([
    'q' => $q, 'classe' => $id_classe ?: null, 'tri' => $tri, 'ordre' => $ordre, 'pp' => $pp
]));

function th_tri(string $col, string $label, string $tri_actuel, string $ordre_actuel, string $base): string {
    $o    = ($tri_actuel === $col && $ordre_actuel === 'ASC') ? 'desc' : 'asc';
    $icon = '';
    if ($tri_actuel === $col) $icon = $ordre_actuel === 'ASC'
        ? '<i class="bi bi-caret-up-fill ms-1" style="font-size:.6rem"></i>'
        : '<i class="bi bi-caret-down-fill ms-1" style="font-size:.6rem"></i>';
    $url = $base . '&tri=' . $col . '&ordre=' . $o;
    return '<a href="' . $url . '" class="text-white text-decoration-none">' . $label . $icon . '</a>';
}

$titre_page = 'Élèves';
require_once __DIR__ . '/../../layout/header.php';
?>

<!-- En-tête page -->
<div class="page-titre">
  <div>
    <h4><i class="bi bi-people me-1 text-primary"></i> Élèves</h4>
    <div class="sub"><?= $total ?> élève(s) actif(s)<?= $id_classe ? ' — classe filtrée' : '' ?></div>
  </div>
  <a href="<?= APP_URL ?>/pages/eleves/form.php" class="btn btn-primary btn-sm">
    <i class="bi bi-plus-lg me-1"></i> Nouvel élève
  </a>
</div>

<!-- Filtres -->
<div class="card mb-2">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-12 col-md-4">
        <label class="form-label">Recherche</label>
        <div class="input-group input-group-sm">
          <span class="input-group-text"><i class="bi bi-search"></i></span>
          <input type="text" name="q" class="form-control" placeholder="Nom, matricule…" value="<?= h($q) ?>">
        </div>
      </div>
      <div class="col-md-3">
        <label class="form-label">Classe</label>
        <select name="classe" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="">— Toutes les classes —</option>
          <?php foreach ($classes as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $id_classe == $c['id'] ? 'selected' : '' ?>>
              <?= h($c['designation']) ?> (<?= (int)$c['nb'] ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label">Par page</label>
        <select name="pp" class="form-select form-select-sm" onchange="this.form.submit()">
          <?php foreach ([10,25,50,100] as $n): ?>
            <option value="<?= $n ?>" <?= $pp==$n?'selected':'' ?>><?= $n ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <input type="hidden" name="tri"   value="<?= h($tri) ?>">
      <input type="hidden" name="ordre" value="<?= strtolower($ordre) ?>">
      <div class="col-auto d-flex gap-1">
        <button class="btn btn-primary btn-sm"><i class="bi bi-search"></i></button>
        <?php if ($q || $id_classe): ?>
          <a href="<?= APP_URL ?>/pages/eleves/liste.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-x-lg"></i>
          </a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<!-- Boutons export -->
<div class="d-flex gap-2 mb-2 flex-wrap">
  <button class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#modalExport">
    <i class="bi bi-file-earmark-pdf me-1"></i> Imprimer PDF
  </button>
  <?php if ($id_classe): ?>
    <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalCartes">
      <i class="bi bi-credit-card me-1"></i> Cartes scolaires
    </button>
  <?php endif; ?>
</div>

<!-- Tableau -->
<div class="card">
  <div class="table-responsive">
    <table class="table table-abz table-hover align-middle mb-0">
      <thead>
        <tr>
          <th style="width:34px">#</th>
          <th><?= th_tri('nom',       'Élève',     $tri, $ordre, $base) ?></th>
          <th><?= th_tri('matricule', 'Matricule', $tri, $ordre, $base) ?></th>
          <th><?= th_tri('sexe',      'Sexe',      $tri, $ordre, $base) ?></th>
          <th><?= th_tri('classe',    'Classe',    $tri, $ordre, $base) ?></th>
          <th>Date naiss.</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($eleves)): ?>
          <tr>
            <td colspan="7" class="text-center text-muted py-4">
              <i class="bi bi-inbox" style="font-size:2rem;opacity:.3;display:block;margin-bottom:.4rem"></i>
              Aucun élève trouvé.
            </td>
          </tr>
        <?php else: $no = $offset + 1; foreach ($eleves as $e): ?>
          <tr>
            <td class="text-muted" style="font-size:.72rem"><?= $no++ ?></td>
            <td>
              <div class="d-flex align-items-center gap-2">
                <?php if (!empty($e['photo'])): ?>
                  <img src="<?= UPLOAD_URL . h($e['photo']) ?>" class="rounded-circle"
                       style="width:32px;height:32px;object-fit:cover;flex-shrink:0">
                <?php else: ?>
                  <div class="avatar">
                    <?= h(mb_strtoupper(mb_substr($e['nom'],0,1) . mb_substr($e['prenom']??'',0,1))) ?>
                  </div>
                <?php endif; ?>
                <a href="<?= APP_URL ?>/pages/eleves/voir.php?id=<?= $e['id'] ?>"
                   class="fw-semibold text-decoration-none" style="font-size:.82rem">
                  <?= h(strtoupper($e['nom']) . ' ' . ($e['prenom'] ?? '')) ?>
                </a>
              </div>
            </td>
            <td><span class="badge-code"><?= h($e['matricule']) ?></span></td>
            <td><?= $e['sexe']==='F' ? '<span class="badge-f">F</span>' : '<span class="badge-m">M</span>' ?></td>
            <td style="font-size:.78rem"><?= h($e['classe'] ?? '—') ?></td>
            <td style="font-size:.78rem;color:#6b7280"><?= date_fr($e['date_naiss']) ?></td>
            <td>
              <div class="d-flex justify-content-end gap-1">
                <a href="<?= APP_URL ?>/pages/eleves/voir.php?id=<?= $e['id'] ?>"
                   class="btn btn-sm" style="background:#eef2ff;color:#1e4fd8;padding:3px 7px">
                  <i class="bi bi-eye" style="font-size:.78rem"></i>
                </a>
                <a href="<?= APP_URL ?>/pages/eleves/form.php?id=<?= $e['id'] ?>"
                   class="btn btn-sm btn-light" style="padding:3px 7px">
                  <i class="bi bi-pencil" style="font-size:.78rem"></i>
                </a>
                <a href="<?= APP_URL ?>/pages/eleves/statut.php?id=<?= $e['id'] ?>&csrf=<?= csrf_generer() ?>"
                   class="btn btn-sm" style="background:#fff3cd;color:#856404;padding:3px 7px"
                   title="Désactiver" onclick="return confirm('Désactiver cet élève ?')">
                  <i class="bi bi-toggle-on" style="font-size:.78rem"></i>
                </a>
                <a href="<?= APP_URL ?>/pages/eleves/supprimer.php?id=<?= $e['id'] ?>&csrf=<?= csrf_generer() ?>"
                   class="btn btn-sm btn-light text-danger" style="padding:3px 7px"
                   onclick="return confirm('Supprimer définitivement <?= h(addslashes($e['nom'])) ?> ?')">
                  <i class="bi bi-trash" style="font-size:.78rem"></i>
                </a>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Pagination -->
<?php if ($nb_pages > 1): ?>
  <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
    <div class="text-muted" style="font-size:.75rem">
      Page <?= $page ?> / <?= $nb_pages ?> &nbsp;·&nbsp; <?= $total ?> élève(s)
    </div>
    <?= pagination_html($page, $nb_pages, $base) ?>
  </div>
<?php endif; ?>

<!-- ═══ Modal export PDF ═══ -->
<div class="modal fade" id="modalExport" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title fw-bold"><i class="bi bi-file-earmark-pdf me-1 text-danger"></i>Impression PDF</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body py-2">
        <p class="text-muted mb-2" style="font-size:.75rem">Colonnes à inclure :</p>
        <?php
        $colonnes = ['no'=>'N°','nom'=>'Nom et Prénoms','date'=>'Date naiss.',
                     'lieu'=>'Lieu naiss.','sexe'=>'Sexe','matricule'=>'Matricule','classe'=>'Classe','telephone'=>'Téléphone'];
        $coches   = ['no','nom','date','lieu','sexe','matricule'];
        foreach ($colonnes as $k => $lbl): ?>
          <div class="form-check mb-1" style="font-size:.78rem">
            <input class="form-check-input" type="checkbox" id="col_<?= $k ?>" value="<?= $k ?>"
                   <?= in_array($k, $coches) ? 'checked' : '' ?>>
            <label class="form-check-label" for="col_<?= $k ?>"><?= $lbl ?></label>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="modal-footer py-2">
        <button class="btn btn-danger btn-sm" onclick="lancerPdf()">
          <i class="bi bi-printer me-1"></i>Générer
        </button>
        <button class="btn btn-light btn-sm" data-bs-dismiss="modal">Annuler</button>
      </div>
    </div>
  </div>
</div>

<!-- ═══ Modal cartes scolaires ═══ -->
<div class="modal fade" id="modalCartes" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title fw-bold"><i class="bi bi-credit-card me-1 text-primary"></i>Cartes scolaires</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body py-2">
        <label class="form-label">Format</label>
        <select id="selFormat" class="form-select form-select-sm">
          <?php foreach (db_all("SELECT * FROM format_carte ORDER BY id") as $f): ?>
            <option value="<?= $f['id'] ?>"><?= h($f['libelle']) ?> — <?= $f['cartes_par_page'] ?> cartes/page</option>
          <?php endforeach; ?>
        </select>
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

<script>
function lancerPdf() {
  const cols = [...document.querySelectorAll('#modalExport input:checked')].map(c=>c.value).join(',');
  if (!cols) { alert('Choisissez au moins une colonne.'); return; }
  window.open('<?= APP_URL ?>/pdf/liste_eleves.php?annee=<?= $id_annee ?>&classe=<?= $id_classe ?>&cols='+cols,'_blank');
  bootstrap.Modal.getInstance(document.getElementById('modalExport')).hide();
}
function lancerCartes() {
  const fmt = document.getElementById('selFormat').value;
  window.open('<?= APP_URL ?>/pdf/cartes.php?annee=<?= $id_annee ?>&classe=<?= $id_classe ?>&format='+fmt,'_blank');
  bootstrap.Modal.getInstance(document.getElementById('modalCartes')).hide();
}
</script>

<?php require_once __DIR__ . '/../../layout/footer.php'; ?>
