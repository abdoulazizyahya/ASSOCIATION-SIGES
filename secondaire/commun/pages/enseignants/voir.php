<?php
// secondaire/commun/pages/enseignants/voir.php — fiche d'un membre du personnel
// (école secondaire). Carte « Poste & paie » + Contrats/Congés/Avances
// ajoutés le 21/09/2026 (port du module Paie, voir secondaire/commun/pages/paie/
// index.php) — porté de primaire/pages/enseignants/voir.php (primaire).
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
require_once __DIR__ . '/../../../../noyau/paie_fonctions.php';
exiger_connexion();

$mat = (int) ($_GET['id'] ?? 0);
$ens = $mat ? db_one("SELECT * FROM enseignant WHERE matricule_ens=?", [$mat]) : null;
if (!$ens) { flash_set('erreur', 'Membre du personnel introuvable.'); rediriger('secondaire/commun/pages/enseignants/liste.php'); }

// École publique : ni salaire, ni contrats, ni congés (personnel de l'État, 17/10/2026) — écoles privées uniquement
$peut_gerer_rh   = in_array(role_connecte(), ['ADMIN', 'PROVISEUR', 'FONDATEUR', 'INTENDANT'], true) && paie_geree_par_ecole();
$peut_gerer_paie = $peut_gerer_rh;
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

// Situation (en service / muté / parti / retraité), migration secondaire v22
$situation_dispo = db_colonne_existe('enseignant', 'statut_ens');
$en_service      = !$situation_dispo || ($ens['statut_ens'] ?? 'actif') === 'actif';
$gere_fiche      = sec_fiche_gerable($ens);
$nb_traces       = $situation_dispo && $en_service && $gere_fiche ? count(sec_traces_enseignant($mat)) : -1;
$nb_affect_cours = $en_service ? 0 : (int) db_val(
    "SELECT COUNT(*) FROM dispenser WHERE matricule_ens=? AND val_annee=?", [$mat, (string) (get_annee_active()['libelle'] ?? '')]);

$titre_page = 'Fiche personnel';
require_once __DIR__ . '/../../../../noyau/layout/header.php';
?>

<div class="d-flex align-items-center gap-2 mb-3">
  <a href="<?= APP_URL ?>/secondaire/commun/pages/enseignants/liste.php" class="btn btn-sm btn-light"><i class="bi bi-arrow-left"></i></a>
  <div>
    <h4 class="mb-0" style="font-size:1.05rem;font-weight:700">
      <?= h(($ens['civilite_ens'] ? $ens['civilite_ens'] . ' ' : '') . mb_strtoupper($ens['nom_ens']) . ' ' . ($ens['prenom_ens'] ?? '')) ?>
    </h4>
    <?php if ($ens['matricule']): ?><div class="sub"><span class="badge-code">Matricule <?= h($ens['matricule']) ?></span></div><?php endif; ?>
    <?php if (!$en_service): ?><span class="badge bg-danger"><?= h(SEC_SITUATIONS[$ens['statut_ens']] ?? 'Parti') ?></span><?php endif; ?>
  </div>
  <a href="<?= APP_URL ?>/secondaire/commun/pages/enseignants/form.php?id=<?= (int) $ens['matricule_ens'] ?>" class="btn btn-primary btn-sm ms-auto">
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

    <?php
    // Emploi du temps de l'année (module secondaire/commun/pages/emploi_temps, migration v17) —
    // ignoré sans bruit sur une base pas encore migrée.
    try {
        require_once __DIR__ . '/../emploi_temps/_edt_lib.php';
        echo edt_fiche_enseignant_html($mat);
    } catch (\Throwable $e) { /* tables edt_* absentes */ }
    ?>

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
                    <a href="<?= APP_URL ?>/secondaire/commun/pages/paie/bulletin.php?id=<?= (int) $b['id'] ?>" class="btn btn-sm btn-light" style="padding:2px 6px">
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
    <?php // Situation (v22, 24/10/2026) : départ au lieu d'une suppression, voir depart.php
    if ($situation_dispo && ($en_service ? $gere_fiche : true)): ?>
    <div class="card mb-2" style="<?= $en_service ? '' : 'border:1px solid #fca5a5;background:#fef2f2' ?>">
      <div class="card-header fw-semibold"><i class="bi bi-person-walking me-1"></i>Situation</div>
      <div class="card-body" style="font-size:.85rem">
        <?php if ($en_service): ?>
          <div class="mb-2"><span class="badge bg-success">En service</span></div>
          <form method="post" action="<?= APP_URL ?>/secondaire/commun/pages/enseignants/depart.php" class="row g-2">
            <?= csrf_champ() ?>
            <input type="hidden" name="action" value="depart">
            <input type="hidden" name="matricule_ens" value="<?= $mat ?>">
            <div class="col-6">
              <select name="statut_ens" class="form-select form-select-sm" required>
                <option value="mute">Muté</option><option value="parti">Parti</option><option value="retraite">Retraité</option>
              </select>
            </div>
            <div class="col-6"><input type="date" name="date_depart" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required></div>
            <div class="col-12"><input type="text" name="motif_depart" class="form-control form-control-sm" maxlength="255" placeholder="Motif / nouvelle affectation (facultatif)"></div>
            <div class="col-12">
              <button class="btn btn-outline-danger btn-sm w-100"
                      onclick="return confirm('Déclarer ce départ ? La fiche est conservée (nom sur les anciens bulletins), elle sort des listes et le compte de connexion est désactivé.')">
                <i class="bi bi-box-arrow-right me-1"></i>Déclarer le départ
              </button>
            </div>
          </form>
          <?php if ($nb_traces === 0): ?>
          <form method="post" action="<?= APP_URL ?>/secondaire/commun/pages/enseignants/depart.php" class="mt-2">
            <?= csrf_champ() ?>
            <input type="hidden" name="action" value="supprimer">
            <input type="hidden" name="matricule_ens" value="<?= $mat ?>">
            <button class="btn btn-link btn-sm text-danger p-0" onclick="return confirm('Supprimer définitivement cette fiche (aucun historique) et son compte ?')">
              <i class="bi bi-trash me-1"></i>Supprimer la fiche (créée par erreur)
            </button>
          </form>
          <?php endif; ?>
        <?php else: ?>
          <div class="mb-1"><span class="badge bg-danger"><?= h(SEC_SITUATIONS[$ens['statut_ens']] ?? 'Parti') ?></span>
            depuis le <?= h(date_fr($ens['date_depart'])) ?></div>
          <?php if ($ens['motif_depart']): ?><div class="text-muted mb-2"><?= h($ens['motif_depart']) ?></div><?php endif; ?>
          <?php if ($nb_affect_cours): ?>
            <div class="alert alert-warning py-1 px-2 small mb-2">Encore affecté à <?= $nb_affect_cours ?> matière(s) cette année :
              <a href="<?= APP_URL ?>/secondaire/commun/pages/matieres/liste.php?onglet=enseignants">désigner le remplaçant</a>.</div>
          <?php endif; ?>
          <?php if ($gere_fiche): ?>
          <form method="post" action="<?= APP_URL ?>/secondaire/commun/pages/enseignants/depart.php">
            <?= csrf_champ() ?>
            <input type="hidden" name="action" value="reintegrer">
            <input type="hidden" name="matricule_ens" value="<?= $mat ?>">
            <button class="btn btn-outline-success btn-sm w-100" onclick="return confirm('Remettre en service ?')">
              <i class="bi bi-arrow-counterclockwise me-1"></i>Réintégrer
            </button>
          </form>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
    <div class="card mb-2">
      <div class="card-body text-center">
        <div class="avatar mx-auto mb-2" style="width:64px;height:64px;font-size:1.4rem">
          <?= h(mb_strtoupper(mb_substr($ens['nom_ens'], 0, 1) . mb_substr($ens['prenom_ens'] ?? '', 0, 1))) ?>
        </div>
        <div class="fw-bold"><?= h(mb_strtoupper($ens['nom_ens'])) ?> <?= h($ens['prenom_ens'] ?? '') ?></div>
        <div class="text-muted" style="font-size:.78rem"><?= h($ens['id_fonction'] ?: '—') ?></div>
      </div>
    </div>

    <?php
    // Compte de connexion (17/10/2026) : géré ici plutôt que dans Paramètres → Utilisateurs.
    if (sec_peut_gerer_comptes()):
        $cpt = sec_compte_de_fiche($mat);
        $cpt_gerable = $cpt && sec_compte_gerable($cpt);
        $cpt_soi = $cpt && (int) $cpt['id'] === (int) ($_SESSION['user_id'] ?? 0);
        $role_suggere = sec_role_depuis_fonction($ens['id_fonction']);
    ?>
    <div class="card mb-2" id="compte">
      <div class="card-header fw-semibold"><i class="bi bi-key me-1"></i>Compte de connexion</div>
      <div class="card-body" style="font-size:.83rem">
        <?php if (!$cpt): ?>
          <p class="text-muted mb-2">Aucun compte : cette personne ne peut pas se connecter.</p>
          <form method="post" action="<?= APP_URL ?>/secondaire/commun/pages/enseignants/compte.php">
            <?= csrf_champ() ?><input type="hidden" name="action" value="creer"><input type="hidden" name="mat" value="<?= $mat ?>">
            <button class="btn btn-primary btn-sm w-100"><i class="bi bi-person-plus me-1"></i>Créer le compte</button>
          </form>
          <div class="form-text" style="font-size:.72rem">Identifiant tiré du nom, mot de passe identique, rôle <?= h(libelle_role($role_suggere)) ?>.</div>
        <?php else: ?>
          <div class="mb-1"><span class="text-muted">Identifiant :</span> <code class="fw-bold"><?= h($cpt['login']) ?></code></div>
          <div class="mb-1"><span class="text-muted">Rôle :</span> <?= h(libelle_role($cpt['role'])) ?></div>
          <div class="mb-2"><span class="text-muted">État :</span>
            <span class="badge <?= $cpt['actif'] ? 'bg-success' : 'bg-secondary' ?>"><?= $cpt['actif'] ? 'Actif' : 'Désactivé' ?></span></div>
          <?php if ($role_suggere !== $cpt['role'] && $role_suggere !== 'ENSEIGNANT' && $cpt_gerable && !$cpt_soi): ?>
            <div class="alert alert-warning py-1 px-2 mb-2" style="font-size:.75rem">
              Fonction « <?= h($ens['id_fonction']) ?> » : le rôle attendu est <b><?= h(libelle_role($role_suggere)) ?></b>.
            </div>
          <?php endif; ?>
          <?php if ($cpt_gerable): ?>
            <form method="post" action="<?= APP_URL ?>/secondaire/commun/pages/enseignants/compte.php" class="mb-1"
                  onsubmit="return confirm('Remettre le mot de passe à « <?= h($cpt['login']) ?> » (identique à l’identifiant) ?')">
              <?= csrf_champ() ?><input type="hidden" name="action" value="reinitialiser">
              <input type="hidden" name="mat" value="<?= $mat ?>"><input type="hidden" name="id" value="<?= (int) $cpt['id'] ?>">
              <button class="btn btn-outline-warning btn-sm w-100"><i class="bi bi-arrow-counterclockwise me-1"></i>Réinitialiser le mot de passe</button>
            </form>
            <button class="btn btn-outline-primary btn-sm w-100" type="button" data-bs-toggle="collapse" data-bs-target="#cptModif">
              <i class="bi bi-pencil me-1"></i>Modifier le compte
            </button>
            <form method="post" action="<?= APP_URL ?>/secondaire/commun/pages/enseignants/compte.php" class="collapse mt-2" id="cptModif">
              <?= csrf_champ() ?><input type="hidden" name="action" value="modifier">
              <input type="hidden" name="mat" value="<?= $mat ?>"><input type="hidden" name="id" value="<?= (int) $cpt['id'] ?>">
              <label class="form-label mb-0" style="font-size:.75rem">Identifiant</label>
              <input type="text" name="login" class="form-control form-control-sm mb-1" value="<?= h($cpt['login']) ?>" required pattern="[a-z0-9._\-]{3,80}">
              <label class="form-label mb-0" style="font-size:.75rem">Rôle</label>
              <select name="role" class="form-select form-select-sm mb-1" <?= $cpt_soi ? 'disabled' : '' ?>>
                <?php foreach (array_unique(array_merge([$cpt['role']], sec_roles_attribuables())) as $r): ?>
                  <option value="<?= h($r) ?>" <?= $r === $cpt['role'] ? 'selected' : '' ?>><?= h(libelle_role($r)) ?><?= $r === $role_suggere && $r !== $cpt['role'] ? ' (selon la fonction)' : '' ?></option>
                <?php endforeach; ?>
              </select>
              <label class="form-label mb-0" style="font-size:.75rem">Nouveau mot de passe <span class="text-muted">(vide = inchangé)</span></label>
              <input type="password" name="mot_de_passe" class="form-control form-control-sm mb-1" autocomplete="new-password" minlength="4">
              <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" name="actif" value="1" id="cptActif" <?= $cpt['actif'] ? 'checked' : '' ?> <?= $cpt_soi ? 'disabled' : '' ?>>
                <label class="form-check-label" for="cptActif">Compte actif</label>
              </div>
              <button class="btn btn-primary btn-sm w-100"><i class="bi bi-check-lg me-1"></i>Enregistrer</button>
            </form>
          <?php else: ?>
            <p class="text-muted mb-0" style="font-size:.75rem">Compte de rang égal ou supérieur au vôtre : géré par l'administrateur.</p>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($peut_gerer_rh): ?>
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
        <a href="<?= APP_URL ?>/secondaire/commun/pages/enseignants/contrats.php?mat=<?= $mat ?>" class="btn btn-outline-primary btn-sm w-100">
          <i class="bi bi-file-earmark-text me-1"></i>Gérer les contrats (<?= $nb_contrats ?>)
        </a>
      </div>
    </div>

    <div class="card mb-2">
      <div class="card-header fw-semibold"><i class="bi bi-calendar-x me-1"></i>Congés / Absences</div>
      <div class="card-body">
        <a href="<?= APP_URL ?>/secondaire/commun/pages/enseignants/conges.php?mat=<?= $mat ?>" class="btn btn-outline-primary btn-sm w-100">
          <i class="bi bi-calendar-x me-1"></i>Gérer les congés (<?= $nb_conges ?>)
        </a>
      </div>
    </div>

    <?php if ($peut_gerer_paie): ?>
    <div class="card">
      <div class="card-header fw-semibold"><i class="bi bi-cash me-1"></i>Avances sur salaire</div>
      <div class="card-body">
        <a href="<?= APP_URL ?>/secondaire/commun/pages/paie/avances.php?mat=<?= $mat ?>" class="btn btn-outline-primary btn-sm w-100">
          <i class="bi bi-cash me-1"></i>Gérer les avances
        </a>
      </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../../../../noyau/layout/footer.php'; ?>
