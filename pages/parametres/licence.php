<?php
// pages/parametres/licence.php — Onglet Licence (Paramètres), demande
// explicite du 13/09/2026 (précisée le 13/09/2026) : voir bd/lib/licence.php
// pour toute la logique (chiffrement, signature, anti-rejeu, blocage).
// Cette page ne fait que l'UI + le routage des 4 actions, selon le rôle du
// compte connecté :
//   - TOUT compte (y compris propriétaire en visite) : bloc état, lecture.
//   - Propriétaire de TOUT LE SYSTÈME (est_proprietaire_association()) SEUL :
//     renouvellement direct par dates, génération de clé, déblocage — ni le
//     Directeur, ni le Fondateur, ni même un superadmin association qui
//     n'est PAS le propriétaire n'ont ces actions.
//   - Directeur OU Fondateur de l'école (jamais le propriétaire, qui n'a
//     pas de compte local) : reçoivent la clé du propriétaire et la
//     SAISISSENT ici — 5 échecs consécutifs bloquent tous les comptes de
//     cette école, seul le propriétaire débloque (bouton ci-dessous).
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_connexion();

$est_proprietaire   = function_exists('est_proprietaire_association') && est_proprietaire_association();
$peut_appliquer_cle = !$est_proprietaire && in_array(role_connecte(), ['DIRECTEUR', 'FONDATEUR'], true);

$auteur = $est_proprietaire
    ? 'propriétaire:' . (membre_connecte()['login'] ?? '?')
    : role_connecte() . ':' . (utilisateur_connecte()['login'] ?? '?');

// Affichage À USAGE UNIQUE de la clé générée (voir cahier des charges :
// « ne doit plus réapparaître après avoir quitté/rechargé la page ») —
// stockée dans un slot de session dédié, lu puis immédiatement effacé
// (PRG : la génération redirige, cette lecture n'arrive qu'une fois).
$cle_generee = null;
if (!empty($_SESSION['licence_cle_generee'])) {
    $cle_generee = $_SESSION['licence_cle_generee'];
    unset($_SESSION['licence_cle_generee']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $action = post('action');

    if ($action === 'renouveler_direct' && $est_proprietaire) {
        $debut = post('date_debut');
        $fin   = post('date_fin');
        if (!$debut || !$fin || $fin <= $debut) {
            flash_set('erreur', 'Dates invalides : la date de fin doit être postérieure à la date de début.');
        } else {
            licence_renouveler_direct($debut, $fin, $auteur);
            journaliser_action('licence_renouvellement_direct', null, "jusqu'au $fin");
            flash_set('succes', 'Licence renouvelée directement jusqu\'au ' . date_fr($fin) . '.');
        }
        rediriger('pages/parametres/licence.php');
    }

    if ($action === 'generer_cle' && $est_proprietaire) {
        $debut = post('date_debut');
        $fin   = post('date_fin');
        if (!$debut || !$fin || $fin <= $debut) {
            flash_set('erreur', 'Dates invalides : la date de fin doit être postérieure à la date de début.');
        } else {
            try {
                $_SESSION['licence_cle_generee'] = licence_generer_cle($debut, $fin);
                journaliser_action('licence_cle_generee', null, "période $debut → $fin");
                flash_set('succes', 'Clé générée ci-dessous — copiez-la maintenant : elle ne réapparaîtra plus après avoir quitté cette page.');
            } catch (\Throwable $e) {
                flash_set('erreur', 'Impossible de générer la clé : ' . $e->getMessage());
            }
        }
        rediriger('pages/parametres/licence.php');
    }

    if ($action === 'appliquer_cle' && $peut_appliquer_cle) {
        $cle = post('cle');
        if (trim($cle) === '') {
            flash_set('erreur', 'Veuillez saisir une clé.');
        } else {
            $res = licence_appliquer_cle($cle, $auteur);
            journaliser_action('licence_cle_appliquee', null, $res['ok'] ? 'succès' : ('échec : ' . $res['message']));
            flash_set($res['ok'] ? 'succes' : 'erreur', $res['message']);
        }
        rediriger('pages/parametres/licence.php');
    }

    if ($action === 'debloquer' && $est_proprietaire) {
        licence_debloquer();
        journaliser_action('licence_debloquee', null, null);
        flash_set('succes', 'Blocage levé.');
        rediriger('pages/parametres/licence.php');
    }
}

$etat       = licence_etat();
$bloque     = function_exists('licence_bloque') && licence_bloque();
$historique = ($est_proprietaire || $peut_appliquer_cle)
    ? db_all("SELECT * FROM licence_historique ORDER BY id DESC LIMIT 50")
    : [];

$titre_page = 'Licence';
require_once __DIR__ . '/../../layout/header.php';
?>

<div class="page-titre">
  <h4><i class="bi bi-award me-1 text-primary"></i>Licence</h4>
  <div class="sub">État d'activation de l'application pour cet établissement</div>
</div>

<?= flash_html() ?>

<!-- ── Bloc état — visible par TOUS les rôles ─────────────────────── -->
<div class="card mb-3">
  <div class="card-body">
    <div class="d-flex flex-wrap align-items-center gap-3">
      <?php
        $badge = ['ok' => ['success', 'Active'], 'alerte' => ['warning', 'Bientôt expirée'], 'expiree' => ['danger', 'Expirée']][$etat['etat']];
      ?>
      <span class="badge bg-<?= $badge[0] ?> fs-6 px-3 py-2"><?= h($badge[1]) ?></span>
      <?php if (!empty($etat['licence'])): ?>
        <div>
          <div class="fw-semibold" style="font-size:.85rem">
            Valide du <?= h(date_fr($etat['licence']['date_debut'])) ?> au <?= h(date_fr($etat['licence']['date_expiration'])) ?>
          </div>
          <?php if ($etat['jours_restants'] !== null && $etat['etat'] !== 'expiree'): ?>
            <div class="text-muted" style="font-size:.78rem"><?= (int) $etat['jours_restants'] ?> jour(s) restant(s)</div>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <div class="text-muted" style="font-size:.85rem">Aucune licence enregistrée pour cet établissement.</div>
      <?php endif; ?>
    </div>
    <?php if ($etat['motif'] === 'signature_invalide'): ?>
      <div class="alert alert-danger mt-3 mb-0 py-2" style="font-size:.82rem">
        <i class="bi bi-shield-exclamation me-1"></i>
        <strong>Anomalie détectée</strong> — les données de licence enregistrées ont été modifiées en dehors de l'application
        (signature invalide). La licence est considérée comme expirée par précaution.
      </div>
    <?php elseif ($etat['motif'] === 'anomalie_technique'): ?>
      <div class="alert alert-danger mt-3 mb-0 py-2" style="font-size:.82rem">
        <i class="bi bi-exclamation-triangle me-1"></i>
        Une anomalie technique empêche de vérifier la licence — elle est considérée comme expirée par précaution.
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($est_proprietaire): ?>
<!-- ── Bloc PROPRIÉTAIRE uniquement ─────────────────────────────── -->
<div class="card mb-3">
  <div class="card-header py-2" style="background:#f8faff"><span class="fw-semibold" style="font-size:.82rem"><i class="bi bi-shield-lock me-1"></i>Actions propriétaire</span></div>
  <div class="card-body">

    <?php if ($cle_generee): ?>
    <div class="alert alert-primary" style="font-size:.85rem">
      <div class="fw-bold mb-2"><i class="bi bi-key me-1"></i>Clé générée — à transmettre au directeur ou fondateur de l'établissement :</div>
      <div class="d-flex align-items-center gap-2">
        <code id="licence-cle-generee" class="p-2 bg-white border rounded flex-grow-1" style="font-size:1rem;letter-spacing:.5px;word-break:break-all">
          <?= h($cle_generee) ?>
        </code>
        <button type="button" class="btn btn-sm btn-outline-primary" onclick="navigator.clipboard.writeText(document.getElementById('licence-cle-generee').textContent.trim()); this.innerHTML='<i class=\'bi bi-check-lg\'></i> Copié';">
          <i class="bi bi-clipboard me-1"></i>Copier
        </button>
      </div>
      <div class="text-muted mt-2" style="font-size:.75rem">Cette clé ne sera plus affichée après avoir quitté ou rechargé cette page — notez-la maintenant.</div>
    </div>
    <?php endif; ?>

    <?php if ($bloque): ?>
    <div class="alert alert-warning d-flex align-items-center gap-2" style="font-size:.85rem">
      <i class="bi bi-lock-fill"></i>
      <span class="flex-grow-1">Accès bloqué pour tous les comptes de cet établissement (trop de tentatives de clé invalides).</span>
      <form method="post" class="d-inline">
        <?= csrf_champ() ?>
        <input type="hidden" name="action" value="debloquer">
        <button class="btn btn-warning btn-sm"><i class="bi bi-unlock me-1"></i>Débloquer</button>
      </form>
    </div>
    <?php endif; ?>

    <div class="row g-3">
      <div class="col-md-6">
        <div class="border rounded p-3 h-100">
          <div class="fw-semibold mb-2" style="font-size:.85rem"><i class="bi bi-calendar-check me-1"></i>Renouvellement direct (sans clé)</div>
          <form method="post" class="row g-2 align-items-end">
            <?= csrf_champ() ?>
            <input type="hidden" name="action" value="renouveler_direct">
            <div class="col-6">
              <label class="form-label" style="font-size:.78rem">Début</label>
              <input type="date" name="date_debut" class="form-control form-control-sm" required value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-6">
              <label class="form-label" style="font-size:.78rem">Fin</label>
              <input type="date" name="date_fin" class="form-control form-control-sm" required>
            </div>
            <div class="col-12">
              <button class="btn btn-primary btn-sm w-100"><i class="bi bi-check-lg me-1"></i>Renouveler directement</button>
            </div>
          </form>
        </div>
      </div>
      <div class="col-md-6">
        <div class="border rounded p-3 h-100">
          <div class="fw-semibold mb-2" style="font-size:.85rem"><i class="bi bi-key-fill me-1"></i>Générer une clé pour l'établissement</div>
          <form method="post" class="row g-2 align-items-end">
            <?= csrf_champ() ?>
            <input type="hidden" name="action" value="generer_cle">
            <div class="col-6">
              <label class="form-label" style="font-size:.78rem">Début</label>
              <input type="date" name="date_debut" class="form-control form-control-sm" required value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-6">
              <label class="form-label" style="font-size:.78rem">Fin</label>
              <input type="date" name="date_fin" class="form-control form-control-sm" required>
            </div>
            <div class="col-12">
              <button class="btn btn-outline-primary btn-sm w-100"><i class="bi bi-key me-1"></i>Générer la clé</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($peut_appliquer_cle): ?>
<!-- ── Bloc FONDATEUR uniquement ────────────────────────────────── -->
<div class="card mb-3">
  <div class="card-header py-2" style="background:#f8faff"><span class="fw-semibold" style="font-size:.82rem"><i class="bi bi-key me-1"></i>Activer une clé de licence</span></div>
  <div class="card-body">
    <form method="post" class="row g-2 align-items-end">
      <?= csrf_champ() ?>
      <input type="hidden" name="action" value="appliquer_cle">
      <div class="col-md-9">
        <label class="form-label">Clé reçue du propriétaire</label>
        <input type="text" name="cle" class="form-control" placeholder="XXXX-XXXX-XXXX-…" autocomplete="off" required>
      </div>
      <div class="col-md-3">
        <button class="btn btn-primary w-100"><i class="bi bi-check-lg me-1"></i>Activer</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($historique): ?>
<!-- ── Historique — propriétaire + directeur/fondateur ────────────────────── -->
<div class="card">
  <div class="card-header py-2" style="background:#f8faff"><span class="fw-semibold" style="font-size:.82rem"><i class="bi bi-clock-history me-1"></i>Historique des renouvellements</span></div>
  <div class="table-responsive">
    <table class="table table-abz table-hover align-middle mb-0" style="font-size:.8rem">
      <thead><tr><th>Date</th><th>Méthode</th><th>Début (avant → après)</th><th>Fin (avant → après)</th><th>Auteur</th></tr></thead>
      <tbody>
        <?php foreach ($historique as $h_row): ?>
        <tr>
          <td><?= h(date('d/m/Y H:i', strtotime($h_row['date_modification']))) ?></td>
          <td><?= $h_row['methode'] === 'direct' ? '<span class="badge bg-secondary">Direct</span>' : '<span class="badge bg-info text-dark">Clé</span>' ?></td>
          <td><?= h(date_fr($h_row['date_debut_avant'])) ?> → <?= h(date_fr($h_row['date_debut_apres'])) ?></td>
          <td><?= h(date_fr($h_row['date_fin_avant'])) ?> → <?= h(date_fr($h_row['date_fin_apres'])) ?></td>
          <td><?= h($h_row['modifie_par'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../layout/footer.php'; ?>
