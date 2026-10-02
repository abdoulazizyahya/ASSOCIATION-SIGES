<?php
// pages/paie/_mes_bulletins_contenu.php — « Mes bulletins de paie » en
// libre-service, PARTAGÉ primaire / secondaire (mêmes tables bulletin_paie,
// periode_paie, avance_salaire — paie_fonctions.php). Tout membre du
// personnel connecté (compte lié à une fiche, matricule_ens) y consulte
// l'HISTORIQUE de ses salaires (période, brut, retenues, net, statut, date
// et mode de paiement), ses avances, et affiche / télécharge chacun de ses
// bulletins PDF quand il le souhaite (demande du 01/10/2026). Lecture
// seule : uniquement SES données (matricule de la session, jamais de GET).
//
// Variables attendues de l'appelant (pages/paie/mes_bulletins.php ou
// secondaire/pages/paie/mes_bulletins.php) :
//   $url_vue  : page HTML d'un bulletin (…/paie/bulletin.php)
//   $url_pdf  : PDF d'un bulletin (…/pdf/bulletin_paie.php)
//   $url_page : cette page (pour le filtre et l'export)

$mon_matricule = (int) (utilisateur_connecte()['matricule_ens'] ?? 0);

$tous = $mon_matricule ? db_all(
    "SELECT b.*, p.libelle AS periode_libelle, p.mois, p.annee
     FROM bulletin_paie b JOIN periode_paie p ON p.id = b.id_periode
     WHERE b.matricule_ens = ? ORDER BY p.annee DESC, p.mois DESC", [$mon_matricule]
) : [];
$avances = [];
if ($mon_matricule) {
    try {
        $avances = db_all("SELECT date_avance, montant, motif FROM avance_salaire WHERE matricule_ens = ? ORDER BY date_avance DESC", [$mon_matricule]);
    } catch (\Throwable $e) { $avances = []; }   // table absente (base non migrée)
}

// Filtre par année (toutes par défaut).
$annees = array_values(array_unique(array_map(fn($b) => (int) $b['annee'], $tous)));
$annee  = (int) ($_GET['annee'] ?? 0);
if ($annee && !in_array($annee, $annees, true)) $annee = 0;
$bulletins = $annee ? array_values(array_filter($tous, fn($b) => (int) $b['annee'] === $annee)) : $tous;

$retenues = fn(array $b): float => (float) $b['total_retenues'] + (float) $b['montant_avance_deduite'] + (float) $b['montant_absence_deduite'];
$fcfa     = fn($n): string => number_format((float) $n, 0, ',', ' ') . ' F';
$date_fr  = fn(?string $d): string => $d ? date('d/m/Y', strtotime($d)) : '—';

// ── Export de l'historique (CSV, ouvrable dans Excel) ─────────────────
if (($_GET['export'] ?? '') === 'csv') {
    $nom = 'historique_salaire' . ($annee ? '_' . $annee : '') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nom . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // BOM : accents corrects dans Excel
    fputcsv($out, ['Période', 'Brut', 'Retenues', 'Net à payer', 'Statut', 'Date de paiement', 'Mode', 'Référence'], ';');
    foreach ($bulletins as $b) {
        fputcsv($out, [$b['periode_libelle'], (float) $b['brut'], $retenues($b), (float) $b['net_a_payer'], $b['statut'],
                       $date_fr($b['date_paiement']), $b['mode_paiement'] ?? '', $b['reference_paiement'] ?? ''], ';');
    }
    if ($avances) {
        fputcsv($out, [], ';');
        fputcsv($out, ['Avances sur salaire'], ';');
        fputcsv($out, ['Date', 'Montant', 'Motif'], ';');
        foreach ($avances as $a) fputcsv($out, [$date_fr($a['date_avance']), (float) $a['montant'], $a['motif'] ?? ''], ';');
    }
    fclose($out);
    exit;
}

$payes        = array_filter($bulletins, fn($b) => $b['statut'] === 'Payé');
$total_percu  = array_sum(array_column($payes, 'net_a_payer'));
$en_attente   = array_sum(array_column(array_filter($bulletins, fn($b) => $b['statut'] !== 'Payé'), 'net_a_payer'));
$dernier      = null;
foreach ($tous as $b) {
    if ($b['statut'] === 'Payé' && (!$dernier || (string) $b['date_paiement'] > (string) $dernier['date_paiement'])) $dernier = $b;
}

$titre_page = 'Mes bulletins de paie';
require_once __DIR__ . '/../../layout/header.php';
?>

<div class="page-titre d-flex justify-content-between align-items-center flex-wrap gap-2">
  <div>
    <h4><i class="bi bi-receipt me-1 text-primary"></i>Mes bulletins de paie</h4>
    <div class="sub">Historique de vos salaires — affichez ou téléchargez vos bulletins à tout moment</div>
  </div>
  <?php if ($tous): ?>
  <form method="get" class="d-flex gap-2 align-items-center">
    <select name="annee" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
      <option value="0">Toutes les années</option>
      <?php foreach ($annees as $a): ?>
        <option value="<?= $a ?>" <?= $annee === $a ? 'selected' : '' ?>><?= $a ?></option>
      <?php endforeach; ?>
    </select>
    <a href="<?= h($url_page . '?' . http_build_query(['annee' => $annee, 'export' => 'csv'])) ?>" class="btn btn-sm btn-outline-success text-nowrap">
      <i class="bi bi-file-earmark-spreadsheet me-1"></i>Télécharger l'historique
    </a>
  </form>
  <?php endif; ?>
</div>

<?php if (!$mon_matricule): ?>
  <div class="alert alert-warning py-2" style="font-size:.85rem">
    <i class="bi bi-info-circle me-1"></i>Votre compte n'est relié à aucune fiche du personnel : aucun bulletin de paie à afficher.
    Demandez à l'administration de relier votre compte à votre fiche.
  </div>
<?php else: ?>

<div class="row g-2 mb-3">
  <div class="col-6 col-md-3"><div class="card"><div class="card-body py-2">
    <div class="text-muted" style="font-size:.68rem">TOTAL PERÇU<?= $annee ? ' ' . $annee : '' ?></div>
    <div class="fw-bold text-success" style="font-size:1.1rem"><?= $fcfa($total_percu) ?></div>
  </div></div></div>
  <div class="col-6 col-md-3"><div class="card"><div class="card-body py-2">
    <div class="text-muted" style="font-size:.68rem">EN ATTENTE DE PAIEMENT</div>
    <div class="fw-bold" style="font-size:1.1rem;color:#92400e"><?= $fcfa($en_attente) ?></div>
  </div></div></div>
  <div class="col-6 col-md-3"><div class="card"><div class="card-body py-2">
    <div class="text-muted" style="font-size:.68rem">DERNIER PAIEMENT</div>
    <div class="fw-bold" style="font-size:1.1rem"><?= $dernier ? $fcfa($dernier['net_a_payer']) : '—' ?></div>
    <?php if ($dernier): ?><div class="text-muted" style="font-size:.7rem"><?= h($dernier['periode_libelle']) ?> · <?= $date_fr($dernier['date_paiement']) ?></div><?php endif; ?>
  </div></div></div>
  <div class="col-6 col-md-3"><div class="card"><div class="card-body py-2">
    <div class="text-muted" style="font-size:.68rem">BULLETINS</div>
    <div class="fw-bold" style="font-size:1.1rem"><?= count($bulletins) ?> <span class="text-muted fw-normal" style="font-size:.75rem">(<?= count($payes) ?> payé<?= count($payes) > 1 ? 's' : '' ?>)</span></div>
  </div></div></div>
</div>

<div class="card mb-3">
  <div class="card-header py-2 fw-semibold"><i class="bi bi-clock-history me-1"></i>Historique des paiements de salaire</div>
  <div class="table-responsive">
    <table class="table table-abz table-hover align-middle mb-0" style="font-size:.85rem">
      <thead><tr>
        <th>Période</th><th class="text-end">Brut</th><th class="text-end">Retenues</th><th class="text-end">Net à payer</th>
        <th>Statut</th><th>Payé le</th><th>Mode / référence</th><th class="text-end">Bulletin</th>
      </tr></thead>
      <tbody>
        <?php if (!$bulletins): ?>
          <tr><td colspan="8" class="text-center text-muted py-4">
            <i class="bi bi-inbox" style="font-size:2rem;opacity:.3;display:block;margin-bottom:.4rem"></i>Aucun bulletin de paie pour l'instant.
          </td></tr>
        <?php else: foreach ($bulletins as $b): $paye = $b['statut'] === 'Payé'; ?>
          <tr>
            <td class="fw-semibold"><?= h($b['periode_libelle']) ?></td>
            <td class="text-end"><?= $fcfa($b['brut']) ?></td>
            <td class="text-end text-danger"><?= $retenues($b) > 0 ? '− ' . $fcfa($retenues($b)) : '—' ?></td>
            <td class="text-end fw-semibold"><?= $fcfa($b['net_a_payer']) ?></td>
            <td>
              <span class="badge" style="background:<?= $paye ? '#dcfce7' : '#fef3c7' ?>;color:<?= $paye ? '#166534' : '#92400e' ?>;font-size:.7rem">
                <?= h($paye ? 'Payé' : 'En attente') ?>
              </span>
            </td>
            <td><?= $paye ? $date_fr($b['date_paiement']) : '—' ?></td>
            <td style="font-size:.78rem"><?= h(trim(($b['mode_paiement'] ?? '') . (($b['reference_paiement'] ?? '') !== '' ? ' · ' . $b['reference_paiement'] : ''))) ?: '—' ?></td>
            <td class="text-end text-nowrap">
              <a href="<?= h($url_vue) ?>?id=<?= (int) $b['id'] ?>" class="btn btn-sm" style="background:#eef2ff;color:#1e4fd8;padding:3px 7px" title="Voir le détail">
                <i class="bi bi-eye" style="font-size:.78rem"></i>
              </a>
              <button type="button" class="btn btn-sm btn-light" style="padding:3px 7px" title="Afficher le PDF"
                      onclick="afficherApercu('<?= h($url_pdf) ?>?id=<?= (int) $b['id'] ?>', 'Bulletin <?= h(numero_bulletin((int) $b['id'])) ?>', null, 'portrait')">
                <i class="bi bi-file-earmark-pdf text-danger" style="font-size:.78rem"></i>
              </button>
              <a href="<?= h($url_pdf) ?>?id=<?= (int) $b['id'] ?>&dl=1" class="btn btn-sm btn-light" style="padding:3px 7px" title="Télécharger le PDF">
                <i class="bi bi-download" style="font-size:.78rem"></i>
              </a>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($avances): ?>
<div class="card">
  <div class="card-header py-2 fw-semibold"><i class="bi bi-cash me-1"></i>Mes avances sur salaire</div>
  <div class="table-responsive">
    <table class="table table-abz align-middle mb-0" style="font-size:.85rem">
      <thead><tr><th>Date</th><th class="text-end">Montant</th><th>Motif</th></tr></thead>
      <tbody>
        <?php foreach ($avances as $a): ?>
          <tr>
            <td><?= $date_fr($a['date_avance']) ?></td>
            <td class="text-end"><?= $fcfa($a['montant']) ?></td>
            <td><?= h($a['motif'] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../../layout/footer.php'; ?>
