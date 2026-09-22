<?php
// secondaire/pages/paiements/rapport.php — hub de rapports du module paiements, à
// onglets (même principe que secondaire/pages/parametres/index.php) : Paiements
// (pivot classe × frais, existant), Statut par frais (payé/non payé,
// nouveau), Insolvables (repris de l'ancien insolvables.php, qui redirige
// désormais ici), Statistiques (nouveau). Tous les PDF passent par la
// modale d'aperçu partagée (layout/footer.php::afficherApercu()) — jamais
// de téléchargement direct — avec signature Intendant optionnelle.
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'FONDATEUR', 'CENSEUR', 'INTENDANT']);

$annee_active = get_annee_active();
$id_annee     = (int)($annee_active['id'] ?? 0);
$onglet       = in_array($_GET['onglet'] ?? '', ['paiements', 'statut', 'insolvables', 'stats'], true) ? $_GET['onglet'] : 'paiements';

$classes = $id_annee
    ? db_all("SELECT c.* FROM classe c
              JOIN inscription i ON i.id_classe=c.id AND i.id_annee=?
              WHERE c.archivee=0 GROUP BY c.id ORDER BY c.ordre, c.designation", [$id_annee])
    : [];

// Frais applicables à une classe (même filtre portée/cycle/niveau que
// eleve_obligations_annee(), sans le calcul par élève) — utilisé par les
// onglets Statut et Insolvables pour peupler le select "Frais".
function obligations_de_classe(array $classe, int $id_annee): array {
    $id_cycle = db_val("SELECT id_cycle FROM niveau WHERE code_niveau = ?", [$classe['code_niveau']]);
    return db_all(
        "SELECT * FROM obligation_frais
         WHERE id_annee=? AND actif=1
           AND (portee='etablissement' OR (portee='cycle' AND id_cycle=?) OR (portee='niveau' AND code_niveau=?))
         ORDER BY libelle",
        [$id_annee, $id_cycle, $classe['code_niveau']]
    );
}

// ══════════════════════════════════════════════════ Onglet Paiements ══
if ($onglet === 'paiements') {
    $id_classe  = (int)($_GET['classe'] ?? 0);
    $date_debut = trim($_GET['debut'] ?? '');
    $date_fin   = trim($_GET['fin'] ?? '');

    $where  = ['p.id_annee = ?'];
    $params = [$id_annee];
    if ($id_classe)  { $where[] = 'p.id_classe = ?';      $params[] = $id_classe; }
    if ($date_debut) { $where[] = 'p.date_paiement >= ?'; $params[] = $date_debut; }
    if ($date_fin)   { $where[] = 'p.date_paiement <= ?'; $params[] = $date_fin; }
    $sql_where = implode(' AND ', $where);

    $lignes_brutes = db_all(
        "SELECT c.id AS id_classe, c.designation AS classe, o.libelle AS frais, SUM(p.montant) AS total
         FROM paiement_frais p
         JOIN classe c ON c.id = p.id_classe
         JOIN obligation_frais o ON o.id = p.id_obligation
         WHERE $sql_where
         GROUP BY c.id, o.libelle
         ORDER BY c.ordre, c.designation, o.libelle",
        $params
    );
    $colonnes = []; $pivot = [];
    foreach ($lignes_brutes as $l) {
        if (!in_array($l['frais'], $colonnes, true)) $colonnes[] = $l['frais'];
        $pivot[$l['id_classe']]['classe']               = $l['classe'];
        $pivot[$l['id_classe']]['valeurs'][$l['frais']] = (float)$l['total'];
    }
    sort($colonnes);
    $grand_total = 0;
    foreach ($pivot as &$p) { $p['total'] = array_sum($p['valeurs']); $grand_total += $p['total']; }
    unset($p);
}

// ══════════════════════════════════════════════════ Onglet Statut ═════
if ($onglet === 'statut') {
    $id_classe     = (int)($_GET['classe'] ?? 0);
    $id_obligation = (int)($_GET['obligation'] ?? 0);
    $classe_statut = $id_classe ? db_one("SELECT * FROM classe WHERE id=?", [$id_classe]) : null;
    $obligations_statut = ($classe_statut && $id_annee) ? obligations_de_classe($classe_statut, $id_annee) : [];
    $obligation_statut  = $id_obligation ? db_one("SELECT * FROM obligation_frais WHERE id=?", [$id_obligation]) : null;

    $ont_paye = []; $nont_pas_paye = [];
    if ($classe_statut && $obligation_statut) {
        $eleves_statut = db_all(
            "SELECT e.*,
                    (SELECT SUM(p.montant) FROM paiement_frais p WHERE p.id_eleve=e.id AND p.id_obligation=? AND p.id_annee=?) AS paye,
                    (SELECT MAX(p.date_paiement) FROM paiement_frais p WHERE p.id_eleve=e.id AND p.id_obligation=? AND p.id_annee=?) AS derniere_date,
                    (SELECT op.libelle FROM paiement_frais p JOIN operateur_paiement op ON op.id=p.id_operateur
                     WHERE p.id_eleve=e.id AND p.id_obligation=? AND p.id_annee=? ORDER BY p.id DESC LIMIT 1) AS dernier_operateur
             FROM eleve e
             JOIN inscription i ON i.id_eleve=e.id AND i.id_classe=? AND i.id_annee=?
             WHERE e.statut='actif' ORDER BY e.nom, e.prenom",
            [$id_obligation, $id_annee, $id_obligation, $id_annee, $id_obligation, $id_annee, $id_classe, $id_annee]
        );
        foreach ($eleves_statut as $e) {
            $paye  = (float) ($e['paye'] ?? 0);
            $solde = round((float) $obligation_statut['montant'] - $paye, 2);
            if ($solde <= 0) { $e['paye'] = $paye; $ont_paye[] = $e; }
            else { $e['solde'] = $solde; $nont_pas_paye[] = $e; }
        }
    }
}

// ══════════════════════════════════════════════════ Onglet Insolvables ═
if ($onglet === 'insolvables') {
    $id_classe     = (int)($_GET['classe']     ?? 0);
    $id_obligation = (int)($_GET['obligation'] ?? 0);
    $classe_insolv = $id_classe ? db_one("SELECT * FROM classe WHERE id=?", [$id_classe]) : null;
    $obligations_insolv = ($classe_insolv && $id_annee) ? obligations_de_classe($classe_insolv, $id_annee) : [];

    $insolvables = [];
    if ($classe_insolv && $id_annee) {
        $eleves_insolv = db_all(
            "SELECT e.* FROM eleve e
             JOIN inscription i ON i.id_eleve=e.id AND i.id_classe=? AND i.id_annee=?
             WHERE e.statut='actif' ORDER BY e.nom, e.prenom",
            [$id_classe, $id_annee]
        );
        foreach ($eleves_insolv as $e) {
            if ($id_obligation) {
                $dette = eleve_solde_obligation((int)$e['id'], $id_obligation, $id_annee);
            } else {
                $dette = 0.0;
                foreach (eleve_obligations_annee((int)$e['id'], $classe_insolv['code_niveau'], $id_annee) as $o) { $dette += $o['solde']; }
            }
            if ($dette > 0) { $e['dette'] = round($dette, 2); $insolvables[] = $e; }
        }
    }
}

// ══════════════════════════════════════════════════ Onglet Statistiques ═
if ($onglet === 'stats') {
    $total_general = (float) db_val("SELECT COALESCE(SUM(montant),0) FROM paiement_frais WHERE id_annee=?", [$id_annee]);

    $par_frais = db_all(
        "SELECT o.libelle, SUM(p.montant) AS total, COUNT(*) AS nb
         FROM paiement_frais p JOIN obligation_frais o ON o.id=p.id_obligation
         WHERE p.id_annee=? GROUP BY o.libelle ORDER BY total DESC", [$id_annee]
    );
    $par_operateur = db_all(
        "SELECT op.libelle, SUM(p.montant) AS total, COUNT(*) AS nb
         FROM paiement_frais p JOIN operateur_paiement op ON op.id=p.id_operateur
         WHERE p.id_annee=? GROUP BY op.libelle ORDER BY total DESC", [$id_annee]
    );
    $par_mois = db_all(
        "SELECT DATE_FORMAT(date_paiement, '%Y-%m') AS mois, SUM(montant) AS total, COUNT(*) AS nb
         FROM paiement_frais WHERE id_annee=? GROUP BY mois ORDER BY mois", [$id_annee]
    );

    $classes_effectif = db_all(
        "SELECT c.id, c.designation, c.code_niveau, COUNT(DISTINCT e.id) AS effectif
         FROM classe c
         JOIN inscription i ON i.id_classe=c.id AND i.id_annee=?
         JOIN eleve e ON e.id=i.id_eleve AND e.statut='actif'
         WHERE c.archivee=0 GROUP BY c.id ORDER BY c.ordre, c.designation", [$id_annee]
    );
    $paye_par_classe = [];
    foreach (db_all("SELECT id_classe, SUM(montant) AS total FROM paiement_frais WHERE id_annee=? GROUP BY id_classe", [$id_annee]) as $r) {
        $paye_par_classe[$r['id_classe']] = (float) $r['total'];
    }
    $recouvrement = [];
    foreach ($classes_effectif as $c) {
        $id_cycle = db_val("SELECT id_cycle FROM niveau WHERE code_niveau=?", [$c['code_niveau']]);
        $montant_du_unitaire = (float) db_val(
            "SELECT COALESCE(SUM(montant),0) FROM obligation_frais WHERE id_annee=? AND actif=1
             AND (portee='etablissement' OR (portee='cycle' AND id_cycle=?) OR (portee='niveau' AND code_niveau=?))",
            [$id_annee, $id_cycle, $c['code_niveau']]
        );
        $total_du   = $montant_du_unitaire * $c['effectif'];
        $total_paye = $paye_par_classe[$c['id']] ?? 0.0;
        $recouvrement[] = [
            'classe' => $c['designation'], 'effectif' => $c['effectif'],
            'total_du' => $total_du, 'total_paye' => $total_paye,
            'taux' => $total_du > 0 ? min(100, $total_paye / $total_du * 100) : 0,
        ];
    }
}

$titre_page = 'Rapports des paiements';
require_once __DIR__ . '/../../../layout/header.php';
?>
<div class="page-titre d-flex justify-content-between align-items-center">
  <h4><i class="bi bi-graph-up me-1 text-primary"></i><?= h($titre_page) ?></h4>
</div>

<ul class="nav nav-tabs mb-3" style="border-bottom:2px solid #e5e7eb">
  <?php foreach ([
      'paiements'   => ['bi-cash-coin',          'Paiements'],
      'statut'      => ['bi-check2-square',      'Statut par frais'],
      'insolvables' => ['bi-exclamation-diamond', 'Insolvables'],
      'stats'       => ['bi-bar-chart-line',     'Statistiques'],
  ] as $key => [$ico, $label]): ?>
  <li class="nav-item">
    <a class="nav-link <?= $onglet === $key ? 'active' : '' ?>" href="<?= APP_URL ?>/secondaire/pages/paiements/rapport.php?onglet=<?= $key ?>">
      <i class="bi <?= $ico ?> me-1"></i><?= $label ?>
    </a>
  </li>
  <?php endforeach; ?>
</ul>

<?php if ($onglet === 'paiements'): ?>
<div class="d-flex justify-content-end mb-2">
  <button type="button" class="btn btn-outline-secondary btn-sm" onclick="ouvrirApercuPaiements()">
    <i class="bi bi-file-earmark-pdf me-1"></i>PDF
  </button>
</div>

<div class="card mb-2">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end">
      <input type="hidden" name="onglet" value="paiements">
      <div class="col-md-3">
        <label class="form-label">Classe</label>
        <select name="classe" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="">— Toutes —</option>
          <?php foreach ($classes as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $id_classe === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['designation']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label">Du</label>
        <input type="date" name="debut" class="form-control form-control-sm" value="<?= h($date_debut) ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label">Au</label>
        <input type="date" name="fin" class="form-control form-control-sm" value="<?= h($date_fin) ?>">
      </div>
      <div class="col-md-2">
        <button class="btn btn-primary btn-sm w-100"><i class="bi bi-search me-1"></i>Filtrer</button>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-abz table-hover align-middle mb-0" style="font-size:.8rem">
      <thead>
        <tr>
          <th>Classe</th>
          <?php foreach ($colonnes as $col): ?><th class="text-end"><?= h($col) ?></th><?php endforeach; ?>
          <th class="text-end fw-bold">Total</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($pivot as $p): ?>
        <tr>
          <td class="fw-semibold"><?= h($p['classe']) ?></td>
          <?php foreach ($colonnes as $col): ?>
            <td class="text-end"><?= isset($p['valeurs'][$col]) ? number_format($p['valeurs'][$col], 0, ',', ' ') : '—' ?></td>
          <?php endforeach; ?>
          <td class="text-end fw-bold"><?= number_format($p['total'], 0, ',', ' ') ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$pivot): ?>
          <tr><td colspan="<?= count($colonnes) + 2 ?>" class="text-center text-muted py-4">Aucun paiement pour cette sélection.</td></tr>
        <?php endif; ?>
      </tbody>
      <?php if ($pivot): ?>
      <tfoot>
        <tr class="fw-bold" style="background:#f8faff">
          <td>Total général</td>
          <?php foreach ($colonnes as $col):
            $s = 0; foreach ($pivot as $p) { $s += $p['valeurs'][$col] ?? 0; } ?>
            <td class="text-end"><?= number_format($s, 0, ',', ' ') ?></td>
          <?php endforeach; ?>
          <td class="text-end"><?= number_format($grand_total, 0, ',', ' ') ?></td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>
<script>
function ouvrirApercuPaiements() {
    const url = <?= json_encode(APP_URL . '/secondaire/pages/paiements/pdf_rapport.php?classe=' . $id_classe . '&debut=' . $date_debut . '&fin=' . $date_fin) ?>;
    afficherApercu(url, 'Rapport des paiements', 'rapport_paiement');
}
</script>

<?php elseif ($onglet === 'statut'): ?>
<div class="card mb-2">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end">
      <input type="hidden" name="onglet" value="statut">
      <div class="col-md-4">
        <label class="form-label">Classe</label>
        <select name="classe" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="">— Choisir une classe —</option>
          <?php foreach ($classes as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $id_classe === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['designation']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Frais *</label>
        <select name="obligation" class="form-select form-select-sm" onchange="this.form.submit()" <?= $classe_statut ? '' : 'disabled' ?>>
          <option value="">— Choisir un frais —</option>
          <?php foreach ($obligations_statut as $o): ?>
            <option value="<?= $o['id'] ?>" <?= $id_obligation === (int)$o['id'] ? 'selected' : '' ?>><?= h($o['libelle']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($classe_statut && $obligation_statut): ?>
      <div class="col-md-4">
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="ouvrirApercuStatut()">
          <i class="bi bi-file-earmark-pdf me-1"></i>PDF
        </button>
      </div>
      <?php endif; ?>
    </form>
  </div>
</div>

<?php if ($classe_statut && $obligation_statut): ?>
<div class="row g-2">
  <div class="col-lg-6">
    <div class="card">
      <div class="card-header py-2" style="background:#eafaf0"><span class="fw-semibold text-success" style="font-size:.82rem">Ont payé (<?= count($ont_paye) ?>)</span></div>
      <div class="table-responsive">
        <table class="table table-abz table-hover align-middle mb-0" style="font-size:.8rem">
          <thead><tr><th>Nom et prénom</th><th>Matricule</th><th>Date</th><th class="text-end">Montant</th></tr></thead>
          <tbody>
            <?php foreach ($ont_paye as $e): ?>
            <tr>
              <td><?= h($e['nom'] . ' ' . ($e['prenom'] ?? '')) ?></td>
              <td><?= h($e['matricule']) ?></td>
              <td><?= $e['derniere_date'] ? h(date('d/m/Y', strtotime($e['derniere_date']))) : '' ?></td>
              <td class="text-end fw-semibold text-success"><?= number_format($e['paye'], 0, ',', ' ') ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$ont_paye): ?><tr><td colspan="4" class="text-center text-muted py-3">Aucun élève à jour.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card">
      <div class="card-header py-2" style="background:#fdecec"><span class="fw-semibold text-danger" style="font-size:.82rem">N'ont pas payé (<?= count($nont_pas_paye) ?>)</span></div>
      <div class="table-responsive">
        <table class="table table-abz table-hover align-middle mb-0" style="font-size:.8rem">
          <thead><tr><th>Nom et prénom</th><th>Matricule</th><th class="text-end">Solde dû</th></tr></thead>
          <tbody>
            <?php foreach ($nont_pas_paye as $e): ?>
            <tr>
              <td><?= h($e['nom'] . ' ' . ($e['prenom'] ?? '')) ?></td>
              <td><?= h($e['matricule']) ?></td>
              <td class="text-end fw-bold text-danger"><?= number_format($e['solde'], 0, ',', ' ') ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$nont_pas_paye): ?><tr><td colspan="3" class="text-center text-muted py-3">Tous les élèves sont à jour.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<script>
function ouvrirApercuStatut() {
    const url = <?= json_encode(APP_URL . '/secondaire/pages/paiements/pdf_statut.php?classe=' . $id_classe . '&obligation=' . $id_obligation) ?>;
    afficherApercu(url, 'Statut de paiement', 'statut_paiement');
}
</script>
<?php endif; ?>

<?php elseif ($onglet === 'insolvables'): ?>
<div class="card mb-2">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end">
      <input type="hidden" name="onglet" value="insolvables">
      <div class="col-md-4">
        <label class="form-label">Classe</label>
        <select name="classe" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="">— Choisir une classe —</option>
          <?php foreach ($classes as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $id_classe === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['designation']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Frais (optionnel)</label>
        <select name="obligation" class="form-select form-select-sm" onchange="this.form.submit()" <?= $classe_insolv ? '' : 'disabled' ?>>
          <option value="">— Tous les frais —</option>
          <?php foreach ($obligations_insolv as $o): ?>
            <option value="<?= $o['id'] ?>" <?= $id_obligation === (int)$o['id'] ? 'selected' : '' ?>><?= h($o['libelle']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($classe_insolv): ?>
      <div class="col-md-4 d-flex gap-2">
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="ouvrirApercuInsolvables()">
          <i class="bi bi-file-earmark-pdf me-1"></i>PDF
        </button>
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="exportExcelInsolvables()">
          <i class="bi bi-file-earmark-excel me-1"></i>Excel
        </button>
      </div>
      <?php endif; ?>
    </form>
  </div>
</div>

<?php if ($classe_insolv): ?>
<div class="card">
  <div class="card-header py-2" style="background:#f8faff"><span class="fw-semibold" style="font-size:.82rem"><?= count($insolvables) ?> élève(s) en défaut de paiement</span></div>
  <div class="table-responsive">
    <table class="table table-abz table-hover align-middle mb-0" id="tblInsolvables">
      <thead><tr><th>N°</th><th>Nom et prénom</th><th>Matricule</th><th class="text-end">Solde dû</th></tr></thead>
      <tbody>
        <?php foreach ($insolvables as $i => $e): ?>
        <tr>
          <td><?= $i + 1 ?></td>
          <td class="fw-semibold"><?= h($e['nom'] . ' ' . ($e['prenom'] ?? '')) ?></td>
          <td><?= h($e['matricule']) ?></td>
          <td class="text-end text-danger fw-bold"><?= number_format($e['dette'], 0, ',', ' ') ?> F</td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$insolvables): ?>
          <tr><td colspan="4" class="text-center text-muted py-4">Aucun impayé pour cette sélection — tous les élèves sont à jour.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script>
function ouvrirApercuInsolvables() {
    const url = <?= json_encode(APP_URL . '/secondaire/pages/paiements/pdf_insolvables.php?classe=' . $id_classe . '&obligation=' . $id_obligation) ?>;
    afficherApercu(url, 'Liste des insolvables', 'insolvables_paiement');
}
function exportExcelInsolvables() {
    const rows = [['N°','Nom et prénom','Matricule','Solde dû']];
    document.querySelectorAll('#tblInsolvables tbody tr').forEach(tr => {
        const c = tr.querySelectorAll('td');
        if (c.length < 4) return;
        rows.push([c[0].textContent.trim(), c[1].textContent.trim(), c[2].textContent.trim(), c[3].textContent.trim()]);
    });
    const ws = XLSX.utils.aoa_to_sheet(rows);
    ws['!cols'] = [{wch:4},{wch:28},{wch:14},{wch:14}];
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Impayes');
    XLSX.writeFile(wb, 'impayes_<?= $classe_insolv ? preg_replace('/\s+/', '_', $classe_insolv['designation']) : 'classe' ?>.xlsx');
}
</script>
<?php endif; ?>

<?php elseif ($onglet === 'stats'): ?>
<div class="row g-2 mb-2">
  <div class="col-md-3">
    <div class="card text-center py-3">
      <div class="text-muted" style="font-size:.75rem">TOTAL COLLECTÉ</div>
      <div class="fs-4 fw-bold text-primary"><?= number_format($total_general, 0, ',', ' ') ?> F</div>
    </div>
  </div>
</div>

<div class="row g-2">
  <div class="col-lg-6">
    <div class="card mb-2">
      <div class="card-header py-2" style="background:#f8faff"><span class="fw-semibold" style="font-size:.82rem">Répartition par frais</span></div>
      <div class="table-responsive">
        <table class="table table-abz table-hover align-middle mb-0" style="font-size:.8rem">
          <thead><tr><th>Frais</th><th class="text-end">Versements</th><th class="text-end">Total</th></tr></thead>
          <tbody>
            <?php foreach ($par_frais as $f): ?>
            <tr><td><?= h($f['libelle']) ?></td><td class="text-end"><?= (int)$f['nb'] ?></td><td class="text-end fw-semibold"><?= number_format((float)$f['total'], 0, ',', ' ') ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$par_frais): ?><tr><td colspan="3" class="text-center text-muted py-3">Aucun paiement.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <div class="card-header py-2" style="background:#f8faff"><span class="fw-semibold" style="font-size:.82rem">Répartition par opérateur</span></div>
      <div class="table-responsive">
        <table class="table table-abz table-hover align-middle mb-0" style="font-size:.8rem">
          <thead><tr><th>Opérateur</th><th class="text-end">Versements</th><th class="text-end">Total</th><th class="text-end">%</th></tr></thead>
          <tbody>
            <?php foreach ($par_operateur as $o): $pct = $total_general > 0 ? (float)$o['total'] / $total_general * 100 : 0; ?>
            <tr><td><?= h($o['libelle']) ?></td><td class="text-end"><?= (int)$o['nb'] ?></td><td class="text-end fw-semibold"><?= number_format((float)$o['total'], 0, ',', ' ') ?></td><td class="text-end"><?= number_format($pct, 1) ?>%</td></tr>
            <?php endforeach; ?>
            <?php if (!$par_operateur): ?><tr><td colspan="4" class="text-center text-muted py-3">Aucun paiement.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card mb-2">
      <div class="card-header py-2" style="background:#f8faff"><span class="fw-semibold" style="font-size:.82rem">Évolution mensuelle</span></div>
      <div class="table-responsive">
        <table class="table table-abz table-hover align-middle mb-0" style="font-size:.8rem">
          <thead><tr><th>Mois</th><th class="text-end">Versements</th><th class="text-end">Total</th></tr></thead>
          <tbody>
            <?php foreach ($par_mois as $m): ?>
            <tr><td><?= h(date('m/Y', strtotime($m['mois'] . '-01'))) ?></td><td class="text-end"><?= (int)$m['nb'] ?></td><td class="text-end fw-semibold"><?= number_format((float)$m['total'], 0, ',', ' ') ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$par_mois): ?><tr><td colspan="3" class="text-center text-muted py-3">Aucun paiement.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <div class="card-header py-2" style="background:#f8faff"><span class="fw-semibold" style="font-size:.82rem">Taux de recouvrement par classe</span></div>
      <div class="table-responsive">
        <table class="table table-abz table-hover align-middle mb-0" style="font-size:.8rem">
          <thead><tr><th>Classe</th><th class="text-end">Effectif</th><th class="text-end">Dû (théorique)</th><th class="text-end">Payé</th><th class="text-end">Taux</th></tr></thead>
          <tbody>
            <?php foreach ($recouvrement as $r): ?>
            <tr>
              <td><?= h($r['classe']) ?></td>
              <td class="text-end"><?= (int)$r['effectif'] ?></td>
              <td class="text-end"><?= number_format($r['total_du'], 0, ',', ' ') ?></td>
              <td class="text-end"><?= number_format($r['total_paye'], 0, ',', ' ') ?></td>
              <td class="text-end fw-bold <?= $r['taux'] >= 80 ? 'text-success' : ($r['taux'] >= 50 ? 'text-warning' : 'text-danger') ?>"><?= number_format($r['taux'], 0) ?>%</td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$recouvrement): ?><tr><td colspan="5" class="text-center text-muted py-3">Aucune classe.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<div class="alert alert-light border mt-2" style="font-size:.78rem">
  <i class="bi bi-info-circle me-1"></i>Le montant « dû (théorique) » suppose que chaque élève actif de la classe doit régler l'intégralité des frais qui s'appliquent à son niveau/cycle — il ne tient pas compte d'éventuelles exonérations individuelles.
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../../layout/footer.php'; ?>
