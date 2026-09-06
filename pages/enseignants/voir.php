<?php
// pages/enseignants/voir.php — Fiche complète d'un membre du personnel.
// Corrige un bug hérité de la version précédente : `arrondissement_ens`
// était joint directement sur `departement` (mauvaise table — le nom de la
// colonne dit bien "arrondissement") ; traité maintenant exactement comme
// eleve.id_arrondissement (cascade complète région→département→arrondissement,
// voir pages/eleves/voir.php, même requête).
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/../../paie_fonctions.php';
exiger_role(['DIRECTEUR']);

$mat = (int) ($_GET['mat'] ?? 0);
$ens = db_one("SELECT * FROM enseignant WHERE matricule_ens=?", [$mat]);
if (!$ens) { flash_set('erreur', 'Membre du personnel introuvable.'); rediriger('pages/enseignants/liste.php'); }

$lieu = $ens['arrondissement_ens']
    ? db_one(
        "SELECT a.intitule_arrond, d.intitule_depart, r.intitule_region
         FROM arrondissement a
         JOIN departement d ON d.code_depart = a.code_depart
         JOIN region r ON r.id_region = d.code_region
         WHERE a.code_arrond = ?",
        [$ens['arrondissement_ens']]
    )
    : null;

$grade = $ens['id_grade'] ? grade_par_code($ens['id_grade']) : null;
$actif = ($ens['statut_ens'] ?? 'actif') === 'actif';

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
require_once __DIR__ . '/../../layout/header.php';
?>

<div class="page-titre">
  <div>
    <h4><i class="bi bi-person-badge me-1 text-primary"></i><?= h($ens['civilite_ens'] ?: '') ?> <?= h(mb_strtoupper($ens['nom_ens'])) ?> <?= h($ens['prenom_ens'] ?? '') ?></h4>
    <div class="sub">
      <?= h(libelle_role($ens['id_fonction'] ?? '')) ?>
      <span class="badge ms-1" style="background:<?= $actif ? '#dcfce7' : '#fee2e2' ?>;color:<?= $actif ? '#166534' : '#991b1b' ?>;font-size:.68rem"><?= $actif ? 'Actif' : 'Inactif' ?></span>
    </div>
  </div>
  <div class="d-flex gap-2">
    <a href="<?= APP_URL ?>/pages/enseignants/form.php?mat=<?= $mat ?>" class="btn btn-primary btn-sm">
      <i class="bi bi-pencil-square me-1"></i>Modifier
    </a>
    <div class="dropdown">
      <button class="btn btn-outline-primary btn-sm dropdown-toggle" data-bs-toggle="dropdown">
        <i class="bi bi-file-earmark-text me-1"></i>Documents
      </button>
      <ul class="dropdown-menu dropdown-menu-end" style="font-size:.82rem">
        <li><a class="dropdown-item" target="_blank" href="<?= APP_URL ?>/pages/enseignants/pdf_attestation.php?id=<?= $mat ?>">
          <i class="bi bi-file-earmark-check me-2"></i>Attestation de présence effective</a></li>
        <li><a class="dropdown-item" target="_blank" href="<?= APP_URL ?>/pages/enseignants/pdf_prise_service.php?type=prise&id=<?= $mat ?>">
          <i class="bi bi-file-earmark-check me-2"></i>Certificat de prise de service</a></li>
        <li><a class="dropdown-item" target="_blank" href="<?= APP_URL ?>/pages/enseignants/pdf_prise_service.php?type=reprise&id=<?= $mat ?>">
          <i class="bi bi-file-earmark-check me-2"></i>Certificat de reprise de service</a></li>
      </ul>
    </div>
    <a href="<?= APP_URL ?>/pages/enseignants/liste.php" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-arrow-left me-1"></i>Retour
    </a>
  </div>
</div>

<div class="row g-2">
  <div class="col-lg-8">
    <div class="card mb-2">
      <div class="card-header fw-semibold"><i class="bi bi-info-circle me-1"></i>Identité</div>
      <div class="card-body">
        <div class="row">
          <div class="col-md-6 mb-2"><span class="text-muted small d-block">Matricule interne</span><?= h((string) $ens['matricule_ens']) ?></div>
          <div class="col-md-6 mb-2"><span class="text-muted small d-block">Matricule administratif</span><?= h($ens['mat_ens'] ?: '—') ?></div>
          <div class="col-md-6 mb-2"><span class="text-muted small d-block">Matricule CNPS</span><?= h($ens['matricule_cnps'] ?: '—') ?></div>
          <div class="col-md-6 mb-2"><span class="text-muted small d-block">Sexe</span><?= h($ens['sexe_ens'] ?: '—') ?></div>
          <div class="col-md-6 mb-2"><span class="text-muted small d-block">Date de naissance</span><?= date_fr($ens['date_naiss_ens']) ?></div>
          <div class="col-md-6 mb-2"><span class="text-muted small d-block">Lieu de naissance</span><?= h($ens['lieu_ens'] ?: '—') ?></div>
          <div class="col-md-6 mb-2"><span class="text-muted small d-block">CNI</span><?= h($ens['num_cni'] ?: '—') ?></div>
          <div class="col-md-6 mb-2"><span class="text-muted small d-block">Téléphone</span><?= h($ens['tel_ens'] ?: '—') ?></div>
          <div class="col-md-6 mb-2"><span class="text-muted small d-block">Email</span><?= h($ens['mail_ens'] ?: '—') ?></div>
          <div class="col-md-12 mb-2"><span class="text-muted small d-block">Adresse</span><?= h($ens['adresse_ens'] ?: '—') ?></div>
          <div class="col-md-6 mb-2"><span class="text-muted small d-block">Région d'origine</span><?= h($lieu['intitule_region'] ?? '—') ?></div>
          <div class="col-md-6 mb-2"><span class="text-muted small d-block">Arrondissement d'origine</span><?= h($lieu['intitule_arrond'] ?? ($ens['lieu_origine_libre'] ?: '—')) ?></div>
          <div class="col-md-4 mb-2"><span class="text-muted small d-block">Situation matrimoniale</span><?= h($ens['situation_ens'] ?: '—') ?></div>
          <div class="col-md-4 mb-2"><span class="text-muted small d-block">Enfants à charge</span><?= (int) ($ens['nb_enfants'] ?? 0) ?></div>
          <div class="col-md-4 mb-2"><span class="text-muted small d-block">Autres pers. à charge</span><?= (int) ($ens['nb_pers_charge'] ?? 0) ?></div>
        </div>
      </div>
    </div>

    <div class="card mb-2">
      <div class="card-header fw-semibold"><i class="bi bi-cash-coin me-1"></i>Poste &amp; paie</div>
      <div class="card-body">
        <div class="row">
          <div class="col-md-6 mb-2"><span class="text-muted small d-block">Grade</span><?= h($grade['libelle_grade'] ?? 'Non défini') ?></div>
          <div class="col-md-6 mb-2"><span class="text-muted small d-block">Salaire de base</span><?= $grade ? number_format((float) $grade['salaire_base'], 0, ',', ' ') . ' F' : '—' ?></div>
          <div class="col-md-6 mb-2"><span class="text-muted small d-block">Indice / Position grille</span><?= h($ens['indice_grille'] ?: '—') ?></div>
          <div class="col-md-6 mb-2"><span class="text-muted small d-block">Date de recrutement</span><?= date_fr($ens['date_recrutement']) ?></div>
          <div class="col-md-6 mb-2"><span class="text-muted small d-block">Ancienneté</span><?= h(anciennete_libelle($ens['date_recrutement']) ?: '—') ?></div>
          <div class="col-md-6 mb-2"><span class="text-muted small d-block">Mode de paiement</span><?= h($ens['mode_paiement'] ?: '—') ?></div>
          <div class="col-md-6 mb-2"><span class="text-muted small d-block">Banque</span><?= h($ens['nom_banque'] ?: '—') ?></div>
          <div class="col-md-6 mb-2"><span class="text-muted small d-block">Compte bancaire / Mobile Money</span><?= h($ens['compte_bancaire'] ?: '—') ?></div>
          <div class="col-md-6 mb-2">
            <span class="text-muted small d-block">Avances dues</span>
            <span class="fw-bold" style="color:<?= $total_du > 0 ? '#dc2626' : '#16a34a' ?>"><?= number_format($total_du, 0, ',', ' ') ?> F</span>
          </div>
        </div>
      </div>
    </div>

    <?php if ($derniers_bulletins): ?>
    <div class="card">
      <div class="card-header fw-semibold"><i class="bi bi-receipt me-1"></i>Derniers bulletins de paie</div>
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
                  <a href="<?= APP_URL ?>/pages/paie/bulletin.php?id=<?= (int) $b['id'] ?>" class="btn btn-sm btn-light" style="padding:2px 6px">
                    <i class="bi bi-eye" style="font-size:.75rem"></i>
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="col-lg-4">
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
        <a href="<?= APP_URL ?>/pages/enseignants/contrats.php?mat=<?= $mat ?>" class="btn btn-outline-primary btn-sm w-100">
          <i class="bi bi-file-earmark-text me-1"></i>Gérer les contrats (<?= $nb_contrats ?>)
        </a>
      </div>
    </div>

    <div class="card mb-2">
      <div class="card-header fw-semibold"><i class="bi bi-calendar-x me-1"></i>Congés / Absences</div>
      <div class="card-body">
        <a href="<?= APP_URL ?>/pages/enseignants/conges.php?mat=<?= $mat ?>" class="btn btn-outline-primary btn-sm w-100">
          <i class="bi bi-calendar-x me-1"></i>Gérer les congés (<?= $nb_conges ?>)
        </a>
      </div>
    </div>

    <div class="card">
      <div class="card-header fw-semibold"><i class="bi bi-cash me-1"></i>Avances sur salaire</div>
      <div class="card-body">
        <a href="<?= APP_URL ?>/pages/paie/avances.php?mat=<?= $mat ?>" class="btn btn-outline-primary btn-sm w-100">
          <i class="bi bi-cash me-1"></i>Gérer les avances
        </a>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../layout/footer.php'; ?>
