<?php
/**
 * Demandes de documents administratifs (Attestation / Prise / Reprise de service).
 * Contenu adapté selon le rôle :
 *  - ENSEIGNANT  : ses propres demandes + bouton "Nouvelle demande"
 *  - CENSEUR     : file d'attente à valider (en_attente_censeur) + historique de ses décisions
 *  - PROVISEUR   : file d'attente à valider (en_attente_proviseur) + historique
 *  - ADMIN       : vue globale, peut agir en lieu et place du Censeur ou du Proviseur
 */
header('Cache-Control: no-store, no-cache, must-revalidate');
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_connexion();

$role = role_connecte();
$uid  = (int)($_SESSION['user_id'] ?? 0);

// Marquer les notifications comme lues à l'ouverture de la page si on vient d'un lien de notif.
if (isset($_GET['lu']) && $_GET['lu'] === '1') {
    notifications_tout_marquer_lu($uid);
}

if ($role === 'ENSEIGNANT') {
    $mat = matricule_ens_courant();
    if (!$mat) { flash_set('erreur', 'Aucune fiche enseignant liée à votre compte.'); rediriger('dashboard.php'); }
    $demandes = db_all(
        "SELECT * FROM demande_document WHERE matricule_ens=? ORDER BY date_demande DESC", [$mat]
    );
} elseif (in_array($role, ['CENSEUR','PROVISEUR','ADMIN'])) {
    // File d'attente pertinente pour ce rôle
    $statut_attendu = $role === 'PROVISEUR' ? 'en_attente_proviseur' : 'en_attente_censeur';
    if ($role === 'ADMIN') {
        $file_attente = db_all(
            "SELECT d.*, e.nom_ens, e.prenom_ens, e.civilite_ens
             FROM demande_document d JOIN enseignant e ON e.matricule_ens=d.matricule_ens
             WHERE d.statut IN ('en_attente_censeur','en_attente_proviseur')
             ORDER BY d.date_demande ASC"
        );
    } else {
        $file_attente = db_all(
            "SELECT d.*, e.nom_ens, e.prenom_ens, e.civilite_ens
             FROM demande_document d JOIN enseignant e ON e.matricule_ens=d.matricule_ens
             WHERE d.statut=? ORDER BY d.date_demande ASC", [$statut_attendu]
        );
    }
    $historique = db_all(
        "SELECT d.*, e.nom_ens, e.prenom_ens, e.civilite_ens
         FROM demande_document d JOIN enseignant e ON e.matricule_ens=d.matricule_ens
         WHERE d.statut NOT IN ('en_attente_censeur','en_attente_proviseur')
         ORDER BY GREATEST(COALESCE(d.date_censeur,'1970-01-01'),COALESCE(d.date_proviseur,'1970-01-01')) DESC
         LIMIT 50"
    );
} else {
    flash_set('erreur', 'Accès réservé.'); rediriger('dashboard.php');
}

$titre_page = 'Demandes de documents';
require_once __DIR__ . '/../../../layout/header.php';
?>
<style>
.btn-abz-primary{background:#1a3c6b;color:#fff;border:none;}
.btn-abz-primary:hover{background:#12305a;color:#fff;}
.btn-abz-outline{background:#fff;color:#1a3c6b;border:1.5px solid #1a3c6b;}
.btn-abz-outline:hover{background:#1a3c6b;color:#fff;}
.tbl-dem th{background:#1a3c6b;color:#fff;font-size:.78rem;padding:8px 10px;}
.tbl-dem td{font-size:.82rem;padding:8px 10px;vertical-align:middle;}
.step{display:flex;align-items:center;gap:4px;font-size:.72rem;color:#9ca3af}
.step .on{color:#1a3c6b;font-weight:700}
.step .ko{color:#b91c1c;font-weight:700}
</style>

<div class="page-titre d-flex align-items-center justify-content-between flex-wrap gap-2">
  <h4><i class="bi bi-file-earmark-text me-2" style="color:#1a3c6b"></i>Demandes de documents</h4>
  <?php if ($role === 'ENSEIGNANT'): ?>
    <a href="nouvelle.php" class="btn btn-sm btn-abz-primary"><i class="bi bi-plus-lg me-1"></i>Nouvelle demande</a>
  <?php endif; ?>
</div>

<?= flash_html() ?>

<?php if ($role === 'ENSEIGNANT'): ?>

  <?php if (empty($demandes)): ?>
    <div class="card"><div class="card-body text-center text-muted py-4">
      Vous n'avez encore soumis aucune demande. <a href="nouvelle.php">Faire une demande</a>.
    </div></div>
  <?php else: ?>
    <div class="row g-3">
      <?php foreach ($demandes as $d):
        [$lib_statut, $classe, $icone] = libelle_statut_demande($d['statut']);
        $rejetee = str_starts_with($d['statut'], 'rejetee');
        $validee = $d['statut'] === 'validee';
      ?>
      <div class="col-md-6 col-lg-4">
        <div class="card h-100" style="border-color:#c7d8f0">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-start mb-2">
              <span class="fw-semibold"><?= h(libelle_type_demande($d['type_document'])) ?></span>
              <span class="badge bg-<?= $classe ?>"><i class="bi bi-<?= $icone ?> me-1"></i><?= h($lib_statut) ?></span>
            </div>
            <div class="text-muted mb-2" style="font-size:.78rem">
              Soumise le <?= date('d/m/Y à H:i', strtotime($d['date_demande'])) ?>
              <?php if ($d['motif']): ?><br>Motif : <?= h($d['motif']) ?><?php endif; ?>
            </div>

            <div class="step mb-2">
              <span class="<?= in_array($d['statut'],['en_attente_proviseur','validee']) ? 'on' : ($d['statut']==='rejetee_censeur'?'ko':'') ?>">Censeur</span>
              <i class="bi bi-arrow-right"></i>
              <span class="<?= $d['statut']==='validee' ? 'on' : ($d['statut']==='rejetee_proviseur'?'ko':'') ?>">Proviseur</span>
              <i class="bi bi-arrow-right"></i>
              <span class="<?= $validee ? 'on' : '' ?>">Disponible</span>
            </div>

            <?php if ($rejetee): ?>
              <div class="alert alert-danger py-1 px-2 mb-2" style="font-size:.78rem">
                <?= h($d['statut']==='rejetee_censeur' ? ($d['commentaire_censeur'] ?: 'Aucun motif précisé.') : ($d['commentaire_proviseur'] ?: 'Aucun motif précisé.')) ?>
              </div>
            <?php endif; ?>

            <?php if ($validee): ?>
              <a href="<?= APP_URL ?>/secondaire/pages/enseignants/apercu.php?id=<?= urlencode($mat) ?>&type=<?= h($d['type_document']==='attestation'?'attestation':($d['type_document']==='prise'?'prise_service':'reprise_service')) ?>"
                 class="btn btn-sm btn-abz-primary w-100" target="_blank">
                <i class="bi bi-eye me-1"></i>Voir / Imprimer
              </a>
            <?php else: ?>
              <div class="text-center text-muted" style="font-size:.78rem"><i class="bi bi-hourglass-split me-1"></i>Traitement en cours…</div>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

<?php else: /* CENSEUR / PROVISEUR / ADMIN */ ?>

  <div class="card mb-3" style="border-color:#c7d8f0">
    <div class="card-header py-2" style="background:#f0f4ff">
      <span class="fw-semibold" style="color:#1a3c6b;font-size:.88rem">
        <i class="bi bi-hourglass-split me-1"></i>À traiter (<?= count($file_attente) ?>)
      </span>
    </div>
    <div class="table-responsive">
      <table class="table tbl-dem table-hover mb-0">
        <thead><tr>
          <th>Enseignant</th><th>Document</th><th>Soumise le</th><th>Étape</th><th style="width:280px">Décision</th>
        </tr></thead>
        <tbody>
          <?php if (empty($file_attente)): ?>
          <tr><td colspan="5" class="text-center text-muted py-4">Aucune demande en attente.</td></tr>
          <?php endif; ?>
          <?php foreach ($file_attente as $d):
            [$lib_statut, $classe, $icone] = libelle_statut_demande($d['statut']);
            $peut_agir = $role === 'ADMIN'
                || ($role === 'CENSEUR'   && $d['statut'] === 'en_attente_censeur')
                || ($role === 'PROVISEUR' && $d['statut'] === 'en_attente_proviseur');
          ?>
          <tr>
            <td class="fw-semibold"><?= h(($d['civilite_ens']?$d['civilite_ens'].' ':'').strtoupper($d['nom_ens']).' '.($d['prenom_ens']??'')) ?></td>
            <td><?= h(libelle_type_demande($d['type_document'])) ?>
              <?php if ($d['motif']): ?><br><small class="text-muted"><?= h($d['motif']) ?></small><?php endif; ?>
            </td>
            <td><?= date('d/m/Y H:i', strtotime($d['date_demande'])) ?></td>
            <td><span class="badge bg-<?= $classe ?>"><i class="bi bi-<?= $icone ?> me-1"></i><?= h($lib_statut) ?></span></td>
            <td>
              <?php if ($peut_agir): ?>
              <form method="post" action="traiter.php" class="d-flex gap-1">
                <?= csrf_champ() ?>
                <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                <input type="text" name="commentaire" class="form-control form-control-sm" placeholder="Commentaire (obligatoire si rejet)">
                <button type="submit" name="action" value="valider" class="btn btn-sm btn-success" title="Valider"><i class="bi bi-check-lg"></i></button>
                <button type="submit" name="action" value="rejeter" class="btn btn-sm btn-danger" title="Rejeter"
                        onclick="return document.querySelector('input[name=commentaire]').value.trim() !== '' || confirm('Rejeter sans motif précisé ?');"><i class="bi bi-x-lg"></i></button>
              </form>
              <?php else: ?>
                <span class="text-muted" style="font-size:.76rem">En attente d'un autre valideur</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card" style="border-color:#c7d8f0">
    <div class="card-header py-2" style="background:#f0f4ff">
      <span class="fw-semibold" style="color:#1a3c6b;font-size:.88rem"><i class="bi bi-clock-history me-1"></i>Historique récent</span>
    </div>
    <div class="table-responsive">
      <table class="table tbl-dem table-hover mb-0">
        <thead><tr><th>Enseignant</th><th>Document</th><th>Statut final</th><th>Censeur</th><th>Proviseur</th></tr></thead>
        <tbody>
          <?php if (empty($historique)): ?>
          <tr><td colspan="5" class="text-center text-muted py-4">Aucun historique pour le moment.</td></tr>
          <?php endif; ?>
          <?php foreach ($historique as $d): [$lib_statut, $classe, $icone] = libelle_statut_demande($d['statut']); ?>
          <tr>
            <td class="fw-semibold"><?= h(($d['civilite_ens']?$d['civilite_ens'].' ':'').strtoupper($d['nom_ens']).' '.($d['prenom_ens']??'')) ?></td>
            <td><?= h(libelle_type_demande($d['type_document'])) ?></td>
            <td><span class="badge bg-<?= $classe ?>"><i class="bi bi-<?= $icone ?> me-1"></i><?= h($lib_statut) ?></span></td>
            <td style="font-size:.78rem"><?= $d['date_censeur'] ? h(date('d/m/Y', strtotime($d['date_censeur']))).($d['commentaire_censeur']?' — '.h($d['commentaire_censeur']):'') : '—' ?></td>
            <td style="font-size:.78rem"><?= $d['date_proviseur'] ? h(date('d/m/Y', strtotime($d['date_proviseur']))).($d['commentaire_proviseur']?' — '.h($d['commentaire_proviseur']):'') : '—' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php endif; ?>

<?php require_once __DIR__ . '/../../../layout/footer.php'; ?>
