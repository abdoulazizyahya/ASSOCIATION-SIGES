<?php
// secondaire/pages/enseignants/voir.php — fiche d'un membre du personnel
// (école secondaire). Carte « Poste & paie » + Contrats/Congés/Avances
// ajoutés le 21/09/2026 (port du module Paie, voir secondaire/pages/paie/
// index.php) — porté de pages/enseignants/voir.php (primaire).
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
require_once __DIR__ . '/../../../paie_fonctions.php';
exiger_connexion();

$mat = (int) ($_GET['id'] ?? 0);
$ens = $mat ? db_one("SELECT * FROM enseignant WHERE matricule_ens=?", [$mat]) : null;
if (!$ens) { flash_set('erreur', 'Membre du personnel introuvable.'); rediriger('secondaire/pages/enseignants/liste.php'); }

$peut_gerer_paie = in_array(role_connecte(), ['ADMIN', 'PROVISEUR', 'FONDATEUR', 'INTENDANT'], true);
$grade = $ens['id_grade'] ? grade_par_code($ens['id_grade']) : null;
$contrat_actif = db_one("SELECT * FROM contrat_enseignant WHERE matricule_ens=? AND actif=1 ORDER BY date_debut DESC LIMIT 1", [$mat]);
$nb_contrats   = (int) db_val("SELECT COUNT(*) FROM contrat_enseignant WHERE matricule_ens=?", [$mat]);
$nb_conges     = (int) db_val("SELECT COUNT(*) FROM conge_enseignant WHERE matricule_ens=?", [$mat]);
$avances_dues  = avances_dues_enseignant($mat);
$total_du      = array_sum(array_column($avances_dues, 'solde'));
$derniers_bulletins = db_all(
    "SELECT b.*, p.libelle AS periode_libelle FROM bulletin_paie b JOIN periode_paie p ON p.id=b.id_periode
     WHERE b.matricule_ens=? ORDER BY p.annee DESC, p.mois DESC LIMIT 6",
    [$mat]
);

$titre_page = 'Fiche personnel';
require_once __DIR__ . '/../../../layout/header.php';
?>

<div class="d-flex align-items-center gap-2 mb-3">
  <a href="<?= APP_URL ?>/secondaire/pages/enseignants/liste.php" class="btn btn-sm btn-light"><i class="bi bi-arrow-left"></i></a>
  <div>
    <h4 class="mb-0" style="font-size:1.05rem;font-weight:700">
      <?= h(($ens['civilite_ens'] ? $ens['civilite_ens'] . ' ' : '') . mb_strtoupper($ens['nom_ens']) . ' ' . ($ens['prenom_ens'] ?? '')) ?>
    </h4>
    <div class="sub"><span class="badge-code"><?= h((string) $ens['matricule_ens']) ?></span></div>
  </div>
  <a href="<?= APP_URL ?>/secondaire/pages/enseignants/form.php?id=<?= (int) $ens['matricule_ens'] ?>" class="btn btn-primary btn-sm ms-auto">
    <i class="bi bi-pencil me-1"></i>Modifier
  </a>
</div>

<?= flash_html() ?>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="card mb-2">
      <div class="card-body">
        <div class="section-titre"><i class="bi bi-person me-1"></i>Identité</div>
        <div class="row g-2" style="font-size:.85rem">
          <div class="col-md-6"><span class="text-muted">Sexe :</span> <?= h($ens['sexe_ens'] === 'Feminin' ? 'Féminin' : 'Masculin') ?></div>
          <div class="col-md-6"><span class="text-muted">Date de naissance :</span> <?= h(date_fr($ens['date_naiss'])) ?></div>
          <div class="col-md-6"><span class="text-muted">Lieu de naissance :</span> <?= h($ens['lieu_naiss'] ?: '—') ?></div>
          <div class="col-md-6"><span class="text-muted">Situation :</span> <?= h($ens['situation_matrimoniale'] ?: '—') ?></div>
          <div class="col-md-6"><span class="text-muted">Téléphone :</span> <?= h($ens['tel_ens'] ?: '—') ?></div>
          <div class="col-md-6"><span class="text-muted">Email :</span> <?= h($ens['mail_ens'] ?: '—') ?></div>
        </div>
      </div>
    </div>
    <div class="card mb-2">
      <div class="card-body">
        <div class="section-titre"><i class="bi bi-briefcase me-1"></i>Poste</div>
        <div class="row g-2" style="font-size:.85rem">
          <div class="col-md-4"><span class="text-muted">Fonction :</span> <?= h($ens['id_fonction'] ?: '—') ?></div>
          <div class="col-md-4"><span class="text-muted">Grade :</span> <?= h($grade['libelle_grade'] ?? ($ens['id_grade'] ?: '—')) ?></div>
          <div class="col-md-4"><span class="text-muted">Matière enseignée :</span> <?= h($ens['matiere_enseignee'] ?: '—') ?></div>
          <div class="col-md-6"><span class="text-muted">Diplôme :</span> <?= h($ens['diplome'] ?: '—') ?></div>
          <div class="col-md-6"><span class="text-muted">Spécialité :</span> <?= h($ens['specialite'] ?: '—') ?></div>
        </div>
      </div>
    </div>

    <?php if ($peut_gerer_paie): ?>
    <div class="card mb-2">
      <div class="card-body">
        <div class="section-titre"><i class="bi bi-cash-coin me-1"></i>Paie</div>
        <div class="row g-2" style="font-size:.85rem">
          <div class="col-md-6"><span class="text-muted">Salaire de base :</span> <?= $grade ? number_format((float) $grade['salaire_base'], 0, ',', ' ') . ' F' : '—' ?></div>
          <div class="col-md-6"><span class="text-muted">Indice / Position grille :</span> <?= h($ens['indice_grille'] ?: '—') ?></div>
          <div class="col-md-6"><span class="text-muted">Date de recrutement :</span> <?= date_fr($ens['date_recrutement']) ?></div>
          <div class="col-md-6"><span class="text-muted">Ancienneté :</span> <?= h(anciennete_libelle($ens['date_recrutement']) ?: '—') ?></div>
          <div class="col-md-6"><span class="text-muted">Mode de paiement :</span> <?= h($ens['mode_paiement'] ?: '—') ?></div>
          <div class="col-md-6"><span class="text-muted">Banque :</span> <?= h($ens['nom_banque'] ?: '—') ?></div>
          <div class="col-md-6"><span class="text-muted">Compte bancaire / Mobile Money :</span> <?= h($ens['compte_bancaire'] ?: '—') ?></div>
          <div class="col-md-6">
            <span class="text-muted">Avances dues :</span>
            <span class="fw-bold" style="color:<?= $total_du > 0 ? '#dc2626' : '#16a34a' ?>"><?= number_format($total_du, 0, ',', ' ') ?> F</span>
          </div>
        </div>
      </div>
    </div>

    <?php if ($derniers_bulletins): ?>
    <div class="card">
      <div class="card-body">
        <div class="section-titre"><i class="bi bi-receipt me-1"></i>Derniers bulletins de paie</div>
        <div class="table-responsive">
          <table class="table table-abz table-hover mb-0" style="font-size:.8rem">
            <thead><tr><th>Période</th><th class="text-end">Net à payer</th><th>Statut</th><th class="text-center">Action</th></tr></thead>
            <tbody>
              <?php foreach ($derniers_bulletins as $b): ?>
                <tr>
                  <td><?= h($b['periode_libelle']) ?></td>
                  <td class="text-end fw-semibold"><?= number_format((float) $b['net_a_payer'], 0, ',', ' ') ?> F</td>
                  <td>
                    <span class="badge" style="background:<?= $b['statut'] === 'Payé' ? '#dcfce7' : '#fef3c7' ?>;color:<?= $b['statut'] === 'Payé' ? '#166534' : '#92400e' ?>;font-size:.68rem">
                      <?= h($b['statut']) ?>
                    </span>
                  </td>
                  <td class="text-center">
                    <a href="<?= APP_URL ?>/secondaire/pages/paie/bulletin.php?id=<?= (int) $b['id'] ?>" class="btn btn-sm btn-light" style="padding:2px 6px">
                      <i class="bi bi-eye" style="font-size:.75rem"></i>
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
  <div class="col-lg-4">
    <div class="card mb-2">
      <div class="card-body text-center">
        <div class="avatar mx-auto mb-2" style="width:64px;height:64px;font-size:1.4rem">
          <?= h(mb_strtoupper(mb_substr($ens['nom_ens'], 0, 1) . mb_substr($ens['prenom_ens'] ?? '', 0, 1))) ?>
        </div>
        <div class="fw-bold"><?= h(mb_strtoupper($ens['nom_ens'])) ?> <?= h($ens['prenom_ens'] ?? '') ?></div>
        <div class="text-muted" style="font-size:.78rem"><?= h($ens['id_fonction'] ?: '—') ?></div>
      </div>
    </div>

    <?php if ($peut_gerer_paie): ?>
    <div class="card mb-2">
      <div class="card-header fw-semibold"><i class="bi bi-file-earmark-text me-1"></i>Contrat</div>
      <div class="card-body">
        <?php if ($contrat_actif): ?>
          <div class="mb-1"><span class="text-muted small d-block">Type</span><?= h(libelle_type_contrat($contrat_actif['type_contrat'])) ?></div>
          <div class="mb-1"><span class="text-muted small d-block">Début</span><?= date_fr($contrat_actif['date_debut']) ?></div>
          <div class="mb-2"><span class="text-muted small d-block">Fin</span><?= $contrat_actif['date_fin'] ? date_fr($contrat_actif['date_fin']) : 'Indéterminée' ?></div>
        <?php else: ?>
          <p class="text-muted small mb-2">Aucun contrat actif enregistré.</p>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/secondaire/pages/enseignants/contrats.php?mat=<?= $mat ?>" class="btn btn-outline-primary btn-sm w-100">
          <i class="bi bi-file-earmark-text me-1"></i>Gérer les contrats (<?= $nb_contrats ?>)
        </a>
      </div>
    </div>

    <div class="card mb-2">
      <div class="card-header fw-semibold"><i class="bi bi-calendar-x me-1"></i>Congés / Absences</div>
      <div class="card-body">
        <a href="<?= APP_URL ?>/secondaire/pages/enseignants/conges.php?mat=<?= $mat ?>" class="btn btn-outline-primary btn-sm w-100">
          <i class="bi bi-calendar-x me-1"></i>Gérer les congés (<?= $nb_conges ?>)
        </a>
      </div>
    </div>

    <div class="card">
      <div class="card-header fw-semibold"><i class="bi bi-cash me-1"></i>Avances sur salaire</div>
      <div class="card-body">
        <a href="<?= APP_URL ?>/secondaire/pages/paie/avances.php?mat=<?= $mat ?>" class="btn btn-outline-primary btn-sm w-100">
          <i class="bi bi-cash me-1"></i>Gérer les avances
        </a>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../../../layout/footer.php'; ?>
