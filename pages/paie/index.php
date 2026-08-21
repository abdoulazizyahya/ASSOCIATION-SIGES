<?php
// pages/paie/index.php — Périodes de paie (liste + création). Le détail
// (aperçu par enseignant, génération, bulletins, validation) est sur
// pages/paie/periode.php?id=... — cette page-ci reste une simple liste
// d'entrée, comme pages/finances/journal.php pour les encaissements.
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/../../paie_fonctions.php';
// DIRECTEUR uniquement — cohérent avec periode.php/avances.php/grille.php
// (données de personnel/paie sensibles). Avant le 16/08/2026, SECRETAIRE
// était aussi autorisé ici mais bloqué sur periode.php (où se fait le vrai
// travail) : une Secrétaire pouvait créer une période puis se faisait
// refuser l'accès en l'ouvrant — incohérence corrigée en retirant l'accès
// partiel plutôt qu'en l'étendant (données sensibles, accès Directeur voulu).
exiger_role(['DIRECTEUR']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $mois  = (int) post('mois');
    $annee = (int) post('annee');
    if ($mois < 1 || $mois > 12 || $annee < 2000) {
        flash_set('erreur', 'Mois et année invalides.');
        rediriger('pages/paie/index.php');
    }
    $existe = db_val("SELECT id FROM periode_paie WHERE mois=? AND annee=?", [$mois, $annee]);
    if ($existe) {
        rediriger('pages/paie/periode.php?id=' . $existe);
    }
    $libelle = libelle_mois($mois) . ' ' . $annee;
    db_exec("INSERT INTO periode_paie (mois, annee, libelle) VALUES (?, ?, ?)", [$mois, $annee, $libelle]);
    $id = (int) db_last_id();
    flash_set('succes', "Période « $libelle » créée.");
    rediriger('pages/paie/periode.php?id=' . $id);
}

$periodes = db_all(
    "SELECT p.*, COUNT(b.id) AS nb_bulletins, COALESCE(SUM(b.net_a_payer),0) AS total_net,
            SUM(CASE WHEN b.statut='Payé' THEN 1 ELSE 0 END) AS nb_payes,
            COALESCE(SUM(CASE WHEN b.statut<>'Payé' THEN b.net_a_payer ELSE 0 END),0) AS reste_a_payer
     FROM periode_paie p LEFT JOIN bulletin_paie b ON b.id_periode=p.id
     GROUP BY p.id ORDER BY p.annee DESC, p.mois DESC"
);

$annee_courante = (int) date('Y');
$mois_courant   = (int) date('n');

$titre_page = 'Paie';
require_once __DIR__ . '/../../layout/header.php';
?>

<div class="page-titre">
  <div>
    <h4><i class="bi bi-cash-stack me-1 text-primary"></i>Paie — Périodes</h4>
    <div class="sub"><?= count($periodes) ?> période(s)</div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header py-2" style="background:#f8faff"><span class="fw-semibold" style="font-size:.82rem">Nouvelle période de paie</span></div>
  <div class="card-body">
    <form method="post" class="row g-2 align-items-end">
      <?= csrf_champ() ?>
      <div class="col-md-4">
        <label class="form-label">Mois</label>
        <select name="mois" class="form-select form-select-sm">
          <?php for ($m = 1; $m <= 12; $m++): ?>
            <option value="<?= $m ?>" <?= $m === $mois_courant ? 'selected' : '' ?>><?= h(libelle_mois($m)) ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Année</label>
        <input type="number" name="annee" class="form-control form-control-sm" value="<?= $annee_courante ?>" min="2020" max="2100">
      </div>
      <div class="col-md-4">
        <button class="btn btn-primary btn-sm w-100"><i class="bi bi-plus-lg me-1"></i>Créer / Ouvrir la période</button>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-abz table-hover align-middle mb-0" style="font-size:.82rem">
      <thead><tr><th>Période</th><th class="text-center">Bulletins</th><th class="text-end">Total net</th><th class="text-end">Reste à payer</th><th>Statut</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
        <?php if (!$periodes): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">
            <i class="bi bi-inbox" style="font-size:2rem;opacity:.3;display:block;margin-bottom:.4rem"></i>Aucune période de paie créée.
          </td></tr>
        <?php else: foreach ($periodes as $p): ?>
          <tr>
            <td class="fw-semibold"><?= h($p['libelle']) ?></td>
            <td class="text-center"><?= (int) $p['nb_bulletins'] ?> (<?= (int) $p['nb_payes'] ?> payé(s))</td>
            <td class="text-end"><?= number_format((float) $p['total_net'], 0, ',', ' ') ?> F</td>
            <td class="text-end fw-semibold" style="color:<?= (float) $p['reste_a_payer'] > 0 ? '#dc2626' : '#16a34a' ?>"><?= number_format((float) $p['reste_a_payer'], 0, ',', ' ') ?> F</td>
            <td>
              <span class="badge" style="background:<?= $p['statut'] === 'Validée' ? '#dcfce7' : '#fef3c7' ?>;color:<?= $p['statut'] === 'Validée' ? '#166534' : '#92400e' ?>;font-size:.7rem">
                <?= h($p['statut']) ?>
              </span>
            </td>
            <td class="text-end">
              <a href="<?= APP_URL ?>/pages/paie/periode.php?id=<?= (int) $p['id'] ?>" class="btn btn-sm" style="background:#eef2ff;color:#1e4fd8;padding:3px 7px">
                <i class="bi bi-eye" style="font-size:.78rem"></i> Ouvrir
              </a>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../../layout/footer.php'; ?>
