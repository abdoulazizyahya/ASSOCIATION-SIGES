<?php
// pages/finances/etat_classe.php — Finances > État par classe : liste des
// élèves d'une classe avec dû/payé/solde (mirroir de check_payment.php du
// vrai jaynitaare legacy). Le montant dû = somme de toutes les obligations
// du niveau de la classe (voir pages/finances/obligations.php) ; le montant
// payé = somme de tous les versements de l'élève, ventilés ou non
// (`paiement_frais`, voir pages/finances/versement.php pour le détail).
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_role(['DIRECTEUR', 'SECRETAIRE']);

$annee     = get_annee_active();
$val_annee = $annee['val_annee'] ?? '';
$id_classe = (int) ($_GET['classe'] ?? 0);

$classes = db_all(
    "SELECT c.IDClasses, c.DesignationClasses, n.OrdreNiveau FROM classe c
     LEFT JOIN niveau n ON n.LibelleNiveau = c.Niveau
     ORDER BY n.OrdreNiveau, c.DesignationClasses"
);

$classe = null; $lignes = []; $total_du_general = 0.0; $total_paye_general = 0.0;
if ($id_classe && $val_annee) {
    $classe = db_one("SELECT * FROM classe WHERE IDClasses=?", [$id_classe]);
    if ($classe) {
        // Montant dû par élève (après réduction "Cas social" éventuelle,
        // migration_v39) — voir finances_du_par_eleve() (fonctions.php).
        $eleves = finances_du_par_eleve($val_annee, $id_classe);
        $payes = [];
        foreach (db_all(
            "SELECT id_eleve, SUM(montant_paiement) AS paye FROM paiement_frais
             WHERE classe=? AND val_annee=? GROUP BY id_eleve",
            [$id_classe, $val_annee]
        ) as $r) {
            $payes[(int) $r['id_eleve']] = (float) $r['paye'];
        }

        foreach ($eleves as $e) {
            $paye  = $payes[(int) $e['id_eleve']] ?? 0.0;
            $solde = $e['du'] - $paye;
            $lignes[] = $e + ['paye' => $paye, 'solde' => $solde];
            $total_paye_general += $paye;
            $total_du_general   += $e['du'];
        }
    }
}
$solde_general = $total_du_general - $total_paye_general;
$taux = $total_du_general > 0 ? round($total_paye_general / $total_du_general * 100, 1) : 0;

$es_partiel = isset($_GET['partiel']);
if (!$es_partiel) {
    $titre_page = 'État par classe';
    require_once __DIR__ . '/../../layout/header.php';
} else {
    header('Content-Type: text/html; charset=utf-8');
}
?>

<div id="etat-classe-zone">

<div class="page-titre d-flex justify-content-between align-items-center flex-wrap gap-2">
  <div>
    <h4><i class="bi bi-list-check me-1 text-primary"></i>Finances — État par classe</h4>
    <div class="sub">Année <?= h($val_annee) ?></div>
  </div>
  <?php if ($classe): ?>
  <div class="d-flex gap-2">
    <button type="button" class="btn btn-outline-danger btn-sm" onclick="ouvrirEtatClassePdf()">
      <i class="bi bi-file-earmark-pdf me-1"></i>PDF
    </button>
    <a class="btn btn-outline-success btn-sm" href="<?= APP_URL ?>/pages/finances/excel_etat_classe.php?classe=<?= $id_classe ?>">
      <i class="bi bi-file-earmark-excel me-1"></i>Excel
    </a>
  </div>
  <?php endif; ?>
</div>

<div class="card mb-2">
  <div class="card-body py-2">
    <label class="form-label">Classe</label>
    <select id="selClasse" class="form-select form-select-sm" style="max-width:320px">
      <option value="">— Choisir une classe —</option>
      <?php foreach ($classes as $c): ?>
        <option value="<?= (int) $c['IDClasses'] ?>" <?= $id_classe === (int) $c['IDClasses'] ? 'selected' : '' ?>><?= h($c['DesignationClasses']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
</div>

<?php if ($classe): ?>
<div class="row g-2 mb-2">
  <div class="col-6 col-md-3">
    <div class="card text-center py-2"><div class="text-muted" style="font-size:.68rem">TOTAL DÛ</div><div class="fw-bold" style="font-size:1.1rem"><?= number_format($total_du_general, 0, ',', ' ') ?> F</div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card text-center py-2"><div class="text-muted" style="font-size:.68rem">TOTAL ENCAISSÉ</div><div class="fw-bold text-success" style="font-size:1.1rem"><?= number_format($total_paye_general, 0, ',', ' ') ?> F</div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card text-center py-2"><div class="text-muted" style="font-size:.68rem">RESTE À PAYER</div><div class="fw-bold" style="font-size:1.1rem;color:<?= $solde_general > 0 ? '#dc2626' : '#16a34a' ?>"><?= number_format($solde_general, 0, ',', ' ') ?> F</div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card text-center py-2"><div class="text-muted" style="font-size:.68rem">TAUX DE RECOUVREMENT</div><div class="fw-bold" style="font-size:1.1rem"><?= h((string) $taux) ?> %</div></div>
  </div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-abz table-hover align-middle mb-0" style="font-size:.82rem">
      <thead><tr><th>Matricule</th><th>Nom et prénom</th><th class="text-end">Dû</th><th class="text-end">Payé</th><th class="text-end">Solde</th><th class="text-center">Statut</th><th class="text-center">Actions</th></tr></thead>
      <tbody>
        <?php foreach ($lignes as $l): ?>
          <tr>
            <td><?= h($l['Mat_elv']) ?></td>
            <td>
              <?= h($l['Nom_elv'] . ' ' . ($l['Prenom_elv'] ?? '')) ?>
              <?php if ($l['cas_social']): ?>
                <span class="badge bg-info text-dark ms-1" style="font-size:.62rem" title="Cas social — réduction appliquée au montant dû">
                  Cas social -<?= h(rtrim(rtrim(number_format($l['pourcentage'], 2, '.', ''), '0'), '.')) ?>%
                </span>
              <?php endif; ?>
            </td>
            <td class="text-end"><?= number_format($l['du'], 0, ',', ' ') ?> F</td>
            <td class="text-end"><?= number_format($l['paye'], 0, ',', ' ') ?> F</td>
            <td class="text-end fw-bold" style="color:<?= $l['solde'] > 0 ? '#dc2626' : '#16a34a' ?>"><?= number_format($l['solde'], 0, ',', ' ') ?> F</td>
            <td class="text-center">
              <?php if ($l['solde'] <= 0): ?>
                <span class="badge bg-success">Soldé</span>
              <?php elseif ($l['paye'] > 0): ?>
                <span class="badge" style="background:#fef3c7;color:#92400e">Partiel</span>
              <?php else: ?>
                <span class="badge bg-danger">Impayé</span>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <a href="<?= APP_URL ?>/pages/finances/versement.php?classe=<?= $id_classe ?>&eleve=<?= (int) $l['id_eleve'] ?>" class="btn btn-sm btn-light" style="padding:2px 7px" title="Voir/encaisser">
                <i class="bi bi-cash-coin" style="font-size:.75rem"></i>
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$lignes): ?>
          <tr><td colspan="7" class="text-center text-muted py-3">Aucun élève inscrit dans cette classe.</td></tr>
        <?php endif; ?>
      </tbody>
      <?php if ($lignes): ?>
      <tfoot><tr class="fw-bold">
        <td colspan="2">TOTAL (<?= count($lignes) ?> élève<?= count($lignes) > 1 ? 's' : '' ?>)</td>
        <td class="text-end"><?= number_format($total_du_general, 0, ',', ' ') ?> F</td>
        <td class="text-end"><?= number_format($total_paye_general, 0, ',', ' ') ?> F</td>
        <td class="text-end"><?= number_format($solde_general, 0, ',', ' ') ?> F</td>
        <td colspan="2"></td>
      </tr></tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>
<?php endif; ?>

<script>
document.getElementById('selClasse').addEventListener('change', function() {
    var url = '<?= APP_URL ?>/pages/finances/etat_classe.php' + (this.value ? '?classe=' + encodeURIComponent(this.value) : '');
    chargerPartiel(url, 'etat-classe-zone');
});
// Passe par la modale d'aperçu partagée (afficherApercu(), layout/footer.php)
// comme tous les autres PDF du projet — corrige une incohérence où ce
// bouton ouvrait pdf/finances_etat_classe.php directement dans un nouvel
// onglet (target="_blank").
function ouvrirEtatClassePdf() {
    afficherApercu('<?= APP_URL ?>/pdf/finances_etat_classe.php?classe=<?= $id_classe ?>', 'État des paiements', null, 'portrait');
}
</script>

</div><!-- /#etat-classe-zone -->
<?php if ($es_partiel) exit; // rien de plus dans une réponse AJAX partielle. ?>

<?php
$ajax_zone_id = 'etat-classe-zone'; // voir layout/footer.php — initAjaxZone() y est appelé
require_once __DIR__ . '/../../layout/footer.php';
