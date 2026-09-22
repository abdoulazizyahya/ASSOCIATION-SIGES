<?php
// secondaire/pages/paie/bulletin.php — Détail d'un bulletin de paie, porté
// de pages/paie/bulletin.php (primaire). Accès : ADMIN/PROVISEUR/FONDATEUR/
// INTENDANT (tous les bulletins) OU l'agent lui-même consultant SON PROPRE
// bulletin (lecture seule, self-service).
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
require_once __DIR__ . '/../../../paie_fonctions.php';
exiger_connexion();

$ROLES_ADMIN = ['ADMIN', 'PROVISEUR', 'FONDATEUR', 'INTENDANT'];

$id = (int) ($_GET['id'] ?? 0);
$bulletin = db_one(
    "SELECT b.*, e.nom_ens, e.prenom_ens, p.libelle AS periode_libelle, p.mois, p.annee
     FROM bulletin_paie b JOIN enseignant e ON e.matricule_ens=b.matricule_ens JOIN periode_paie p ON p.id=b.id_periode
     WHERE b.id=?", [$id]
);
if (!$bulletin) { flash_set('erreur', 'Bulletin introuvable.'); rediriger('dashboard.php'); }

$role = role_connecte();
$mon_matricule = (int) (utilisateur_connecte()['matricule_ens'] ?? 0);
$est_le_sien = $mon_matricule && $mon_matricule === (int) $bulletin['matricule_ens'];
if (!in_array($role, $ROLES_ADMIN, true) && !$est_le_sien) {
    die('<div style="font-family:sans-serif;padding:2rem;color:red">Accès refusé. Vous n\'avez pas les droits nécessaires.</div>');
}

$lignes = db_all("SELECT * FROM ligne_bulletin_paie WHERE id_bulletin=? ORDER BY ordre_affichage, id", [$id]);
$gains    = array_filter($lignes, fn($l) => $l['type_ligne'] === 'Gain');
$retenues = array_filter($lignes, fn($l) => $l['type_ligne'] === 'Retenue');

$titre_page = 'Bulletin de paie';
require_once __DIR__ . '/../../../layout/header.php';
?>

<div class="page-titre">
  <div>
    <h4><i class="bi bi-receipt me-1 text-primary"></i>Bulletin <?= h(numero_bulletin($id)) ?></h4>
    <div class="sub"><?= h($bulletin['periode_libelle']) ?></div>
  </div>
  <div class="d-flex gap-2">
    <button type="button" class="btn btn-outline-danger btn-sm"
            onclick="afficherApercu('<?= APP_URL ?>/secondaire/pdf/bulletin_paie.php?id=<?= $id ?>', 'Bulletin <?= h(numero_bulletin($id)) ?>', null, 'portrait')">
      <i class="bi bi-file-earmark-pdf me-1"></i>PDF
    </button>
    <?php if (in_array($role, $ROLES_ADMIN, true)): ?>
      <a href="<?= APP_URL ?>/secondaire/pages/paie/periode.php?id=<?= (int) $bulletin['id_periode'] ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Retour à la période</a>
    <?php else: ?>
      <a href="<?= APP_URL ?>/secondaire/pages/paie/mes_bulletins.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Mes bulletins</a>
    <?php endif; ?>
  </div>
</div>

<div class="card mb-2">
  <div class="card-body py-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div class="fw-bold" style="font-size:.95rem"><?= h(mb_strtoupper($bulletin['nom_ens'])) ?> <?= h($bulletin['prenom_ens'] ?? '') ?></div>
    <span class="badge" style="background:<?= $bulletin['statut'] === 'Payé' ? '#dcfce7' : '#fef3c7' ?>;color:<?= $bulletin['statut'] === 'Payé' ? '#166534' : '#92400e' ?>;font-size:.72rem"><?= h($bulletin['statut']) ?></span>
  </div>
</div>

<div class="row g-2">
  <div class="col-md-6">
    <div class="card mb-2">
      <div class="card-header py-2" style="background:#f0fdf4"><span class="fw-semibold" style="font-size:.82rem;color:#166534">Gains</span></div>
      <div class="table-responsive">
        <table class="table table-sm mb-0" style="font-size:.82rem">
          <tbody>
            <?php foreach ($gains as $l): ?>
              <tr><td><?= h($l['libelle']) ?></td><td class="text-end"><?= number_format((float) $l['montant'], 0, ',', ' ') ?> F</td></tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot><tr class="fw-bold"><td>BRUT</td><td class="text-end"><?= number_format((float) $bulletin['brut'], 0, ',', ' ') ?> F</td></tr></tfoot>
        </table>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card mb-2">
      <div class="card-header py-2" style="background:#fef2f2"><span class="fw-semibold" style="font-size:.82rem;color:#991b1b">Retenues</span></div>
      <div class="table-responsive">
        <table class="table table-sm mb-0" style="font-size:.82rem">
          <tbody>
            <?php if (!$retenues): ?>
              <tr><td class="text-muted">Aucune retenue.</td><td></td></tr>
            <?php endif; ?>
            <?php foreach ($retenues as $l): ?>
              <tr><td><?= h($l['libelle']) ?></td><td class="text-end"><?= number_format((float) $l['montant'], 0, ',', ' ') ?> F</td></tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot><tr class="fw-bold"><td>TOTAL RETENUES</td><td class="text-end"><?= number_format((float) $bulletin['total_retenues'], 0, ',', ' ') ?> F</td></tr></tfoot>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-body py-3 d-flex justify-content-between align-items-center">
    <span class="fw-bold" style="font-size:1rem">NET À PAYER</span>
    <span class="fw-bold" style="font-size:1.3rem;color:#1e4fd8"><?= number_format((float) $bulletin['net_a_payer'], 0, ',', ' ') ?> F</span>
  </div>
  <?php if ($bulletin['statut'] === 'Payé'): ?>
  <div class="card-body pt-0" style="font-size:.8rem;color:#6b7280">
    Payé le <?= date_fr($bulletin['date_paiement']) ?><?= $bulletin['mode_paiement'] ? ' — ' . h($bulletin['mode_paiement']) : '' ?><?= $bulletin['reference_paiement'] ? ' — Réf. ' . h($bulletin['reference_paiement']) : '' ?>
  </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../../layout/footer.php'; ?>
