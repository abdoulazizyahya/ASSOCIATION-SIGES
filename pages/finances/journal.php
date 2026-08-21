<?php
// pages/finances/journal.php — Finances > Journal de caisse : registre
// chronologique de tous les versements sur une période (jour par jour, avec
// sous-total quotidien) — outil de rapprochement de caisse quotidien pour
// la Secrétaire, complémentaire de la fiche par élève (versement.php).
// date_paiement est stocké en texte ISO 'YYYY-MM-DD' (vérifié sur les
// données réelles) : comparable directement en chaîne pour un intervalle.
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_role(['DIRECTEUR', 'SECRETAIRE']);

$annee     = get_annee_active();
$val_annee = $annee['val_annee'] ?? '';

$date_debut    = $_GET['debut'] ?? date('Y-m-01');
$date_fin      = $_GET['fin'] ?? date('Y-m-d');
$id_classe     = (int) ($_GET['classe'] ?? 0);
$mode_paiement = $_GET['mode'] ?? '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_debut)) $date_debut = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_fin))   $date_fin   = date('Y-m-d');
if ($mode_paiement && !isset(finances_modes_paiement()[$mode_paiement])) $mode_paiement = '';

$classes = db_all(
    "SELECT c.IDClasses, c.DesignationClasses, n.OrdreNiveau FROM classe c
     LEFT JOIN niveau n ON n.LibelleNiveau = c.Niveau
     ORDER BY n.OrdreNiveau, c.DesignationClasses"
);

$params = [$val_annee, $date_debut, $date_fin];
$sql = "SELECT p.id_pay, p.date_paiement, p.montant_paiement, p.ref_paiement, p.mode_paiement,
               e.Mat_elv, e.Nom_elv, e.Prenom_elv, c.DesignationClasses,
               o.nom_obligation, ag.nom_ens, ag.prenom_ens
        FROM paiement_frais p
        JOIN eleve e ON e.id_eleve = p.id_eleve
        JOIN classe c ON c.IDClasses = p.classe
        LEFT JOIN obligation o ON o.id_obligation = p.id_obligation
        LEFT JOIN user u ON u.id_user = p.id_utilisateur
        LEFT JOIN enseignant ag ON ag.matricule_ens = u.matricule_ens
        WHERE p.val_annee = ? AND p.date_paiement BETWEEN ? AND ?";
if ($id_classe) { $sql .= " AND p.classe = ?"; $params[] = $id_classe; }
if ($mode_paiement) { $sql .= " AND p.mode_paiement = ?"; $params[] = $mode_paiement; }
$sql .= " ORDER BY p.date_paiement, p.id_pay";
$lignes = db_all($sql, $params);

$jours = [];
$total_general = 0.0;
foreach ($lignes as $l) {
    $jours[$l['date_paiement']]['lignes'][] = $l;
    $jours[$l['date_paiement']]['total'] = ($jours[$l['date_paiement']]['total'] ?? 0.0) + (float) $l['montant_paiement'];
    $total_general += (float) $l['montant_paiement'];
}

$es_partiel = isset($_GET['partiel']);
if (!$es_partiel) {
    $titre_page = 'Journal de caisse';
    require_once __DIR__ . '/../../layout/header.php';
} else {
    header('Content-Type: text/html; charset=utf-8');
}
?>

<div id="journal-caisse-zone">

<div class="page-titre d-flex justify-content-between align-items-center flex-wrap gap-2">
  <div>
    <h4><i class="bi bi-journal-text me-1 text-primary"></i>Finances — Journal de caisse</h4>
    <div class="sub">Année <?= h($val_annee) ?> — <?= count($lignes) ?> versement<?= count($lignes) > 1 ? 's' : '' ?></div>
  </div>
  <div class="d-flex gap-2">
    <button type="button" class="btn btn-outline-danger btn-sm" onclick="ouvrirJournalPdf()">
      <i class="bi bi-file-earmark-pdf me-1"></i>Aperçu PDF
    </button>
    <a class="btn btn-outline-success btn-sm" id="lienExcelJournal" href="#">
      <i class="bi bi-file-earmark-excel me-1"></i>Excel
    </a>
  </div>
</div>

<div class="card mb-2">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end" id="form-filtre" data-ajax-nav-form>
      <div class="col-6 col-md-3">
        <label class="form-label">Du</label>
        <input type="date" name="debut" class="form-control form-control-sm" value="<?= h($date_debut) ?>">
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label">Au</label>
        <input type="date" name="fin" class="form-control form-control-sm" value="<?= h($date_fin) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Classe</label>
        <select name="classe" class="form-select form-select-sm">
          <option value="">— Toutes les classes —</option>
          <?php foreach ($classes as $c): ?>
            <option value="<?= (int) $c['IDClasses'] ?>" <?= $id_classe === (int) $c['IDClasses'] ? 'selected' : '' ?>><?= h($c['DesignationClasses']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Mode de paiement</label>
        <select name="mode" class="form-select form-select-sm">
          <option value="">— Tous les modes —</option>
          <?php foreach (finances_modes_paiement() as $code => $m): ?>
            <option value="<?= h($code) ?>" <?= $mode_paiement === $code ? 'selected' : '' ?>><?= h($m['libelle']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <button class="btn btn-primary btn-sm w-100"><i class="bi bi-funnel me-1"></i>Filtrer</button>
      </div>
    </form>
  </div>
</div>

<div class="card text-center py-2 mb-2" style="max-width:280px">
  <div class="text-muted" style="font-size:.68rem">TOTAL ENCAISSÉ SUR LA PÉRIODE</div>
  <div class="fw-bold text-success" style="font-size:1.2rem"><?= number_format($total_general, 0, ',', ' ') ?> F</div>
</div>

<?php if (!$jours): ?>
  <div class="alert alert-light text-muted text-center py-4">Aucun versement sur cette période<?= $id_classe ? ' pour cette classe' : '' ?>.</div>
<?php else: foreach ($jours as $jour => $grp): ?>
  <div class="card mb-2">
    <div class="card-header py-2 d-flex justify-content-between align-items-center" style="background:#f8faff">
      <span class="fw-bold" style="font-size:.82rem"><i class="bi bi-calendar3 me-1 text-primary"></i><?= h(date_fr($jour)) ?></span>
      <span class="badge bg-light text-dark border">Sous-total : <?= number_format($grp['total'], 0, ',', ' ') ?> F (<?= count($grp['lignes']) ?>)</span>
    </div>
    <div class="table-responsive">
      <table class="table table-sm table-hover mb-0" style="font-size:.78rem">
        <thead style="background:#fbfbfd"><tr><th>N° reçu</th><th>Élève</th><th>Classe</th><th>Frais</th><th class="text-end">Montant</th><th>Mode</th><th>Encaissé par</th></tr></thead>
        <tbody>
          <?php foreach ($grp['lignes'] as $l): ?>
            <tr>
              <td class="text-muted"><?= h(finances_numero_recu((int) $l['id_pay'])) ?></td>
              <td><?= h($l['Nom_elv'] . ' ' . ($l['Prenom_elv'] ?? '')) . ' (' . h($l['Mat_elv']) . ')' ?></td>
              <td><?= h($l['DesignationClasses']) ?></td>
              <td><?= $l['nom_obligation'] ? h($l['nom_obligation']) : '<span class="text-muted fst-italic">Non ventilé</span>' ?></td>
              <td class="text-end fw-semibold"><?= number_format((float) $l['montant_paiement'], 0, ',', ' ') ?> F</td>
              <td><?= finances_mode_paiement_badge($l['mode_paiement'] ?? null) ?></td>
              <td><?= $l['nom_ens'] ? h(trim($l['prenom_ens'] . ' ' . $l['nom_ens'])) : '<span class="text-muted">—</span>' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endforeach; endif; ?>

<script>
function urlAvecFiltres(base) {
    const p = new URLSearchParams({ debut: '<?= h($date_debut) ?>', fin: '<?= h($date_fin) ?>', classe: '<?= $id_classe ?>', mode: '<?= h($mode_paiement) ?>' });
    return base + '?' + p.toString();
}
function ouvrirJournalPdf() {
    afficherApercu(urlAvecFiltres('<?= APP_URL ?>/pdf/finances_journal.php'), 'Journal de caisse', null, 'portrait');
}
document.getElementById('lienExcelJournal').href = urlAvecFiltres('<?= APP_URL ?>/pages/finances/excel_journal.php');
</script>

</div><!-- /#journal-caisse-zone -->
<?php if ($es_partiel) exit; // rien de plus dans une réponse AJAX partielle. ?>

<?php
$ajax_zone_id = 'journal-caisse-zone'; // voir layout/footer.php — initAjaxZone() y est appelé
require_once __DIR__ . '/../../layout/footer.php';
