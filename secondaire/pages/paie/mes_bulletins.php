<?php
// secondaire/pages/paie/mes_bulletins.php — Consultation self-service de
// SES propres bulletins de paie, lecture seule, porté de pages/paie/
// mes_bulletins.php (primaire). Rôles "personnel payé" qui n'ont pas déjà
// l'accès complet au module Paie (ADMIN/PROVISEUR/FONDATEUR/INTENDANT, voir
// secondaire/pages/paie/index.php).
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
require_once __DIR__ . '/../../../paie_fonctions.php';
exiger_role(['CENSEUR', 'SG', 'SECRETAIRE', 'ENSEIGNANT']);

$mon_matricule = (int) (utilisateur_connecte()['matricule_ens'] ?? 0);
$bulletins = $mon_matricule ? db_all(
    "SELECT b.*, p.libelle AS periode_libelle FROM bulletin_paie b JOIN periode_paie p ON p.id=b.id_periode
     WHERE b.matricule_ens=? ORDER BY p.annee DESC, p.mois DESC", [$mon_matricule]
) : [];

$titre_page = 'Mes bulletins de paie';
require_once __DIR__ . '/../../../layout/header.php';
?>

<div class="page-titre">
  <div>
    <h4><i class="bi bi-receipt me-1 text-primary"></i>Mes bulletins de paie</h4>
    <div class="sub"><?= count($bulletins) ?> bulletin(s)</div>
  </div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-abz table-hover align-middle mb-0">
      <thead><tr><th>Période</th><th class="text-end">Net à payer</th><th>Statut</th><th class="text-end">Action</th></tr></thead>
      <tbody>
        <?php if (!$bulletins): ?>
          <tr><td colspan="4" class="text-center text-muted py-4">
            <i class="bi bi-inbox" style="font-size:2rem;opacity:.3;display:block;margin-bottom:.4rem"></i>Aucun bulletin de paie pour l'instant.
          </td></tr>
        <?php else: foreach ($bulletins as $b): ?>
          <tr>
            <td class="fw-semibold"><?= h($b['periode_libelle']) ?></td>
            <td class="text-end"><?= number_format((float) $b['net_a_payer'], 0, ',', ' ') ?> F</td>
            <td>
              <span class="badge" style="background:<?= $b['statut'] === 'Payé' ? '#dcfce7' : '#fef3c7' ?>;color:<?= $b['statut'] === 'Payé' ? '#166534' : '#92400e' ?>;font-size:.7rem">
                <?= h($b['statut']) ?>
              </span>
            </td>
            <td class="text-end">
              <a href="<?= APP_URL ?>/secondaire/pages/paie/bulletin.php?id=<?= (int) $b['id'] ?>" class="btn btn-sm" style="background:#eef2ff;color:#1e4fd8;padding:3px 7px">
                <i class="bi bi-eye" style="font-size:.78rem"></i>
              </a>
              <button type="button" class="btn btn-sm btn-light" style="padding:3px 7px" title="PDF"
                      onclick="afficherApercu('<?= APP_URL ?>/secondaire/pdf/bulletin_paie.php?id=<?= (int) $b['id'] ?>', 'Bulletin <?= h(numero_bulletin((int) $b['id'])) ?>', null, 'portrait')">
                <i class="bi bi-file-earmark-pdf text-danger" style="font-size:.78rem"></i>
              </button>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../../../layout/footer.php'; ?>
