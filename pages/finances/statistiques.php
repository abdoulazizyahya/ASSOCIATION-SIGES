<?php
// pages/finances/statistiques.php — Finances > Statistiques : vue
// d'ensemble du recouvrement (totaux, par niveau, par type de frais,
// insolvables). Le montant dû par élève = somme de toutes les obligations
// de son niveau (voir pages/finances/obligations.php).
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_role(['DIRECTEUR']);

$annee     = get_annee_active();
$val_annee = $annee['val_annee'] ?? '';

// ── Nombre d'élèves inscrits (actifs) par niveau ────────────────────
$nb_eleves_par_niveau = [];
foreach (db_all(
    "SELECT c.Niveau, COUNT(DISTINCT i.id_eleve) AS nb
     FROM inscrire i
     JOIN classe c ON c.IDClasses = i.IDClasses
     JOIN eleve e  ON e.id_eleve = i.id_eleve AND e.statut='actif'
     WHERE i.val_annee = ?
     GROUP BY c.Niveau",
    [$val_annee]
) as $r) { $nb_eleves_par_niveau[$r['Niveau']] = (int) $r['nb']; }

// ── Dû/payé par niveau ───────────────────────────────────────────────
// Montant dû par élève (après réduction "Cas social" éventuelle,
// migration_v39) — voir finances_du_par_eleve() (fonctions.php). Réutilisé
// ci-dessous pour le "Par niveau" (regroupé), les insolvables (filtre
// solde>0) et la réduction totale par type de frais.
$tous_eleves_du = finances_du_par_eleve($val_annee);
$du_par_niveau = []; // niveau => montant NORMAL (avant réduction), pour "Par type de frais"
foreach (db_all("SELECT niveau_obligation, SUM(montant_obligation) AS total FROM obligation GROUP BY niveau_obligation") as $r) {
    $du_par_niveau[$r['niveau_obligation']] = (float) $r['total'];
}
$paye_par_niveau = [];
foreach (db_all(
    "SELECT c.Niveau, SUM(p.montant_paiement) AS paye FROM paiement_frais p
     JOIN classe c ON c.IDClasses = p.classe WHERE p.val_annee=? GROUP BY c.Niveau",
    [$val_annee]
) as $r) { $paye_par_niveau[$r['Niveau']] = (float) $r['paye']; }

$niveaux = db_all(
    "SELECT DISTINCT n.LibelleNiveau, n.OrdreNiveau FROM niveau n
     JOIN classe c ON c.Niveau=n.LibelleNiveau WHERE n.actif=1 ORDER BY n.OrdreNiveau"
);
$du_reel_par_niveau = []; // niveau => somme des 'du' réels (après réduction) — pour "Par niveau" et sa réutilisation ci-dessous
foreach ($tous_eleves_du as $e) {
    $du_reel_par_niveau[$e['Niveau']] = ($du_reel_par_niveau[$e['Niveau']] ?? 0.0) + $e['du'];
}
$stats_niveau = []; $total_du_general = 0.0; $total_paye_general = 0.0;
foreach ($niveaux as $n) {
    $code   = $n['LibelleNiveau'];
    $nb     = $nb_eleves_par_niveau[$code] ?? 0;
    $du     = $du_reel_par_niveau[$code] ?? 0.0;
    $paye   = $paye_par_niveau[$code] ?? 0.0;
    $stats_niveau[] = ['niveau' => $code, 'nb' => $nb, 'du' => $du, 'paye' => $paye, 'solde' => $du - $paye];
    $total_du_general   += $du;
    $total_paye_general += $paye;
}
$solde_general = $total_du_general - $total_paye_general;
$taux_general  = $total_du_general > 0 ? round($total_paye_general / $total_du_general * 100, 1) : 0;

// ── Répartition par type de frais (nom_obligation) ──────────────────
// Base "nb × montant" (comme avant), puis retrait de la réduction "Cas
// social" de chaque élève concerné, répartie proportionnellement sur
// chaque frais du niveau (même pourcentage sur chacun).
$obligations_toutes = db_all("SELECT * FROM obligation");
$obligations_par_niveau_liste = [];
foreach ($obligations_toutes as $o) $obligations_par_niveau_liste[$o['niveau_obligation']][] = $o;

$par_type = [];
foreach ($obligations_toutes as $o) {
    $nb = $nb_eleves_par_niveau[$o['niveau_obligation']] ?? 0;
    $par_type[$o['nom_obligation']]['du'] = ($par_type[$o['nom_obligation']]['du'] ?? 0.0) + (float) $o['montant_obligation'] * $nb;
    $par_type[$o['nom_obligation']]['paye'] = $par_type[$o['nom_obligation']]['paye'] ?? 0.0;
}
foreach ($tous_eleves_du as $e) {
    if (!$e['cas_social']) continue;
    foreach ($obligations_par_niveau_liste[$e['Niveau']] ?? [] as $o) {
        $par_type[$o['nom_obligation']]['du'] -= round((float) $o['montant_obligation'] * $e['pourcentage'] / 100, 2);
    }
}
foreach (db_all(
    "SELECT o.nom_obligation, SUM(p.montant_paiement) AS paye FROM paiement_frais p
     JOIN obligation o ON o.id_obligation=p.id_obligation
     WHERE p.val_annee=? GROUP BY o.nom_obligation",
    [$val_annee]
) as $r) {
    $par_type[$r['nom_obligation']]['paye'] = ($par_type[$r['nom_obligation']]['paye'] ?? 0.0) + (float) $r['paye'];
}
$non_ventile_total = (float) db_val(
    "SELECT COALESCE(SUM(p.montant_paiement),0) FROM paiement_frais p
     WHERE p.val_annee=? AND NOT EXISTS (SELECT 1 FROM obligation o WHERE o.id_obligation=p.id_obligation)",
    [$val_annee]
);

// ── Insolvables (solde > 0), toutes classes ─────────────────────────
$paye_par_eleve = [];
foreach (db_all("SELECT id_eleve, SUM(montant_paiement) AS paye FROM paiement_frais WHERE val_annee=? GROUP BY id_eleve", [$val_annee]) as $r) {
    $paye_par_eleve[(int) $r['id_eleve']] = (float) $r['paye'];
}
$insolvables = [];
foreach ($tous_eleves_du as $e) {
    $du    = $e['du'];
    $paye  = $paye_par_eleve[(int) $e['id_eleve']] ?? 0.0;
    $solde = $du - $paye;
    if ($solde > 0) $insolvables[] = $e + ['paye' => $paye, 'solde' => $solde];
}
usort($insolvables, fn($a, $b) => $b['solde'] <=> $a['solde']);

// ── Évolution mensuelle des encaissements (année active) ────────────
// date_paiement stocké en texte ISO 'YYYY-MM-DD' (vérifié sur les données
// réelles) : LEFT(...,7) donne directement 'YYYY-MM', groupable/triable.
$mois_fr = [1=>'Janv', 2=>'Févr', 3=>'Mars', 4=>'Avr', 5=>'Mai', 6=>'Juin', 7=>'Juil', 8=>'Août', 9=>'Sept', 10=>'Oct', 11=>'Nov', 12=>'Déc'];
$evolution_mensuelle = [];
foreach (db_all(
    "SELECT LEFT(date_paiement,7) AS mois, SUM(montant_paiement) AS total FROM paiement_frais
     WHERE val_annee=? GROUP BY LEFT(date_paiement,7) ORDER BY mois",
    [$val_annee]
) as $r) {
    $num = (int) substr($r['mois'], 5, 2);
    $evolution_mensuelle[] = ['label' => ($mois_fr[$num] ?? $r['mois']) . ' ' . substr($r['mois'], 0, 4), 'total' => (float) $r['total']];
}

// ── Comparaison annuelle des encaissements (payé uniquement — `obligation`
//    n'est pas versionnée par année dans ce schéma, seul le payé peut être
//    comparé dans le temps de façon fiable) ──────────────────────────
$comparaison_annuelle = db_all(
    "SELECT val_annee, SUM(montant_paiement) AS total, COUNT(*) AS nb FROM paiement_frais GROUP BY val_annee ORDER BY val_annee"
);

// ── Répartition par mode de paiement (migration v43) ─────────────────
$par_mode = [];
foreach (db_all(
    "SELECT mode_paiement, SUM(montant_paiement) AS total, COUNT(*) AS nb FROM paiement_frais
     WHERE val_annee=? GROUP BY mode_paiement",
    [$val_annee]
) as $r) {
    $code = finances_mode_paiement_normalise($r['mode_paiement']);
    $par_mode[$code] = ['total' => (float) $r['total'], 'nb' => (int) $r['nb']];
}
// Modes sans versement affichés quand même (à 0), dans l'ordre standard.
foreach (finances_modes_paiement() as $code => $m) { $par_mode[$code] ??= ['total' => 0.0, 'nb' => 0]; }

// ── Encaissé par agent (traçabilité, migration v26) ──────────────────
$par_agent = db_all(
    "SELECT p.id_utilisateur, ag.nom_ens, ag.prenom_ens, SUM(p.montant_paiement) AS total, COUNT(*) AS nb
     FROM paiement_frais p
     LEFT JOIN user u ON u.id_user = p.id_utilisateur
     LEFT JOIN enseignant ag ON ag.matricule_ens = u.matricule_ens
     WHERE p.val_annee=? GROUP BY p.id_utilisateur, ag.nom_ens, ag.prenom_ens ORDER BY total DESC",
    [$val_annee]
);

$titre_page = 'Statistiques';
require_once __DIR__ . '/../../layout/header.php';
?>
<!-- Chart.js chargé uniquement sur cette page (pas dans layout/header.php),
     même CDN jsdelivr déjà utilisé pour Bootstrap/icônes. -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>

<div class="page-titre d-flex justify-content-between align-items-center flex-wrap gap-2">
  <div>
    <h4><i class="bi bi-bar-chart-line me-1 text-primary"></i>Finances — Statistiques</h4>
    <div class="sub">Année <?= h($val_annee) ?></div>
  </div>
  <div class="d-flex gap-2">
    <button type="button" class="btn btn-outline-danger btn-sm" onclick="afficherApercu('<?= APP_URL ?>/pdf/finances_statistiques.php', 'Bilan financier', null, 'portrait')">
      <i class="bi bi-file-earmark-pdf me-1"></i>Aperçu PDF
    </button>
    <a class="btn btn-outline-success btn-sm" href="<?= APP_URL ?>/pages/finances/excel_statistiques.php">
      <i class="bi bi-file-earmark-excel me-1"></i>Excel
    </a>
  </div>
</div>

<div class="row g-2 mb-2">
  <div class="col-6 col-md-3">
    <div class="card text-center py-2"><div class="text-muted" style="font-size:.68rem">TOTAL DÛ</div><div class="fw-bold" style="font-size:1.15rem"><?= number_format($total_du_general, 0, ',', ' ') ?> F</div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card text-center py-2"><div class="text-muted" style="font-size:.68rem">TOTAL ENCAISSÉ</div><div class="fw-bold text-success" style="font-size:1.15rem"><?= number_format($total_paye_general, 0, ',', ' ') ?> F</div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card text-center py-2"><div class="text-muted" style="font-size:.68rem">RESTE À RECOUVRER</div><div class="fw-bold" style="font-size:1.15rem;color:<?= $solde_general > 0 ? '#dc2626' : '#16a34a' ?>"><?= number_format($solde_general, 0, ',', ' ') ?> F</div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card text-center py-2"><div class="text-muted" style="font-size:.68rem">TAUX DE RECOUVREMENT</div><div class="fw-bold" style="font-size:1.15rem"><?= h((string) $taux_general) ?> %</div></div>
  </div>
</div>

<div class="row g-2">
  <div class="col-lg-6">
    <div class="card mb-2">
      <div class="card-header py-2" style="background:#f8faff"><span class="fw-semibold" style="font-size:.82rem">Par niveau</span></div>
      <div class="table-responsive">
        <table class="table table-sm mb-0" style="font-size:.78rem">
          <thead style="background:#fbfbfd"><tr><th>Niveau</th><th class="text-center">Élèves</th><th class="text-end">Dû</th><th class="text-end">Payé</th><th style="width:110px">Recouvrement</th></tr></thead>
          <tbody>
            <?php foreach ($stats_niveau as $s): $t = $s['du'] > 0 ? round($s['paye'] / $s['du'] * 100) : 0; ?>
              <tr>
                <td class="fw-semibold">Niveau <?= h($s['niveau']) ?></td>
                <td class="text-center"><?= $s['nb'] ?></td>
                <td class="text-end"><?= number_format($s['du'], 0, ',', ' ') ?> F</td>
                <td class="text-end"><?= number_format($s['paye'], 0, ',', ' ') ?> F</td>
                <td>
                  <div class="progress" style="height:14px">
                    <div class="progress-bar <?= $t >= 100 ? 'bg-success' : ($t >= 50 ? 'bg-info' : 'bg-warning') ?>" style="width:<?= min(100, $t) ?>%">
                      <span style="font-size:.65rem"><?= $t ?>%</span>
                    </div>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$stats_niveau): ?><tr><td colspan="5" class="text-center text-muted py-3">Aucune donnée.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card mb-2">
      <div class="card-header py-2" style="background:#f8faff"><span class="fw-semibold" style="font-size:.82rem">Par type de frais</span></div>
      <div class="table-responsive">
        <table class="table table-sm mb-0" style="font-size:.78rem">
          <thead style="background:#fbfbfd"><tr><th>Frais</th><th class="text-end">Dû</th><th class="text-end">Payé (ventilé)</th></tr></thead>
          <tbody>
            <?php foreach ($par_type as $nom => $t): ?>
              <tr>
                <td><?= h($nom) ?></td>
                <td class="text-end"><?= number_format($t['du'], 0, ',', ' ') ?> F</td>
                <td class="text-end"><?= number_format($t['paye'], 0, ',', ' ') ?> F</td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$par_type): ?><tr><td colspan="3" class="text-center text-muted py-3">Aucune obligation configurée.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php if ($non_ventile_total > 0): ?>
        <div class="card-body py-2" style="background:#fffbeb;font-size:.76rem">
          <i class="bi bi-info-circle me-1"></i>
          <strong><?= number_format($non_ventile_total, 0, ',', ' ') ?> F</strong> versés sans type de frais précisé (historique) —
          non ventilés dans le tableau ci-dessus, mais bien comptés dans le total encaissé général.
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="row g-2">
  <div class="col-lg-5">
    <div class="card mb-2">
      <div class="card-header py-2" style="background:#f8faff"><span class="fw-semibold" style="font-size:.82rem">Répartition par mode de paiement — <?= h($val_annee) ?></span></div>
      <div class="table-responsive">
        <table class="table table-sm mb-0" style="font-size:.78rem">
          <thead style="background:#fbfbfd"><tr><th>Mode</th><th class="text-center">Versements</th><th class="text-end">Total encaissé</th><th style="width:90px">Part</th></tr></thead>
          <tbody>
            <?php foreach ($par_mode as $code => $pm): $part = $total_paye_general > 0 ? round($pm['total'] / $total_paye_general * 100) : 0; ?>
              <tr>
                <td><?= finances_mode_paiement_badge($code) ?></td>
                <td class="text-center"><?= $pm['nb'] ?></td>
                <td class="text-end"><?= number_format($pm['total'], 0, ',', ' ') ?> F</td>
                <td><?= $part ?> %</td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="row g-2">
  <div class="col-lg-7">
    <div class="card mb-2">
      <div class="card-header py-2" style="background:#f8faff"><span class="fw-semibold" style="font-size:.82rem">Évolution mensuelle des encaissements — <?= h($val_annee) ?></span></div>
      <div class="card-body">
        <?php if ($evolution_mensuelle): ?>
          <canvas id="chartEvolution" height="90"></canvas>
        <?php else: ?>
          <div class="text-center text-muted py-3">Aucun versement enregistré cette année.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card mb-2">
      <div class="card-header py-2" style="background:#f8faff"><span class="fw-semibold" style="font-size:.82rem">Comparaison annuelle (encaissements)</span></div>
      <div class="table-responsive">
        <table class="table table-sm mb-0" style="font-size:.78rem">
          <thead style="background:#fbfbfd"><tr><th>Année scolaire</th><th class="text-center">Versements</th><th class="text-end">Total encaissé</th></tr></thead>
          <tbody>
            <?php foreach ($comparaison_annuelle as $c): ?>
              <tr class="<?= $c['val_annee'] === $val_annee ? 'fw-bold' : '' ?>">
                <td><?= h($c['val_annee']) ?><?= $c['val_annee'] === $val_annee ? ' <span class="badge bg-primary" style="font-size:.6rem">active</span>' : '' ?></td>
                <td class="text-center"><?= (int) $c['nb'] ?></td>
                <td class="text-end"><?= number_format((float) $c['total'], 0, ',', ' ') ?> F</td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$comparaison_annuelle): ?><tr><td colspan="3" class="text-center text-muted py-3">Aucune donnée.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="card mb-2">
  <div class="card-header py-2" style="background:#f8faff"><span class="fw-semibold" style="font-size:.82rem">Encaissé par agent — <?= h($val_annee) ?> <span class="text-muted fw-normal">(traçabilité)</span></span></div>
  <div class="table-responsive">
    <table class="table table-sm mb-0" style="font-size:.78rem">
      <thead style="background:#fbfbfd"><tr><th>Agent</th><th class="text-center">Versements</th><th class="text-end">Total encaissé</th></tr></thead>
      <tbody>
        <?php foreach ($par_agent as $a): ?>
          <tr>
            <td><?= $a['id_utilisateur'] ? h(trim($a['prenom_ens'] . ' ' . $a['nom_ens'])) : '<span class="text-muted fst-italic">Non tracé (avant migration / historique)</span>' ?></td>
            <td class="text-center"><?= (int) $a['nb'] ?></td>
            <td class="text-end"><?= number_format((float) $a['total'], 0, ',', ' ') ?> F</td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$par_agent): ?><tr><td colspan="3" class="text-center text-muted py-3">Aucune donnée.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-header py-2 d-flex justify-content-between align-items-center" style="background:#f8faff">
    <span class="fw-semibold" style="font-size:.82rem">Élèves en impayé — <?= count($insolvables) ?></span>
  </div>
  <div class="table-responsive" style="max-height:420px;overflow-y:auto">
    <table class="table table-abz table-hover align-middle mb-0" style="font-size:.8rem">
      <thead style="position:sticky;top:0;background:#fff"><tr><th>Matricule</th><th>Nom et prénom</th><th>Classe</th><th class="text-end">Payé</th><th class="text-end">Solde dû</th><th class="text-center">Action</th></tr></thead>
      <tbody>
        <?php foreach ($insolvables as $i): ?>
          <tr>
            <td><?= h($i['Mat_elv']) ?></td>
            <td>
              <?= h($i['Nom_elv'] . ' ' . ($i['Prenom_elv'] ?? '')) ?>
              <?php if ($i['cas_social']): ?>
                <span class="badge bg-info text-dark ms-1" style="font-size:.6rem">Cas social -<?= h(rtrim(rtrim(number_format($i['pourcentage'], 2, '.', ''), '0'), '.')) ?>%</span>
              <?php endif; ?>
            </td>
            <td><?= h($i['DesignationClasses']) ?></td>
            <td class="text-end"><?= number_format($i['paye'], 0, ',', ' ') ?> F</td>
            <td class="text-end fw-bold text-danger"><?= number_format($i['solde'], 0, ',', ' ') ?> F</td>
            <td class="text-center">
              <a href="<?= APP_URL ?>/pages/finances/versement.php?classe=<?= (int) $i['IDClasses'] ?>&eleve=<?= (int) $i['id_eleve'] ?>" class="btn btn-sm btn-light" style="padding:2px 7px">
                <i class="bi bi-cash-coin" style="font-size:.75rem"></i>
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$insolvables): ?><tr><td colspan="6" class="text-center text-muted py-3">Aucun élève en impayé — tous les frais sont soldés.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($evolution_mensuelle): ?>
<script>
new Chart(document.getElementById('chartEvolution'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($evolution_mensuelle, 'label')) ?>,
        datasets: [{
            label: 'Encaissements (F)',
            data: <?= json_encode(array_map(fn($m) => (int) round($m['total']), $evolution_mensuelle)) ?>,
            backgroundColor: '#1e4fd8',
            borderRadius: 3,
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, ticks: { callback: v => v.toLocaleString('fr-FR') + ' F' } } }
    }
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../layout/footer.php'; ?>
