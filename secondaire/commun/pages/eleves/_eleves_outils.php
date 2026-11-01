<?php
// Fragment : onglet « Import & matricules » de la liste des élèves du
// secondaire (secondaire/commun/pages/eleves/liste.php), pendant de
// primaire/pages/eleves/_eleves_outils.php. Au secondaire le matricule est
// toujours généré automatiquement (gen_matricule_secondaire()) : l'onglet
// présente l'import Excel et un contrôle des matricules (manquants, doublons).
// $peut_importer est calculé par liste.php.
if (empty($peut_importer)) { echo '<div class="text-muted small py-3">Accès réservé.</div>'; return; }

[$prefixe_mat, $suffixe_mat] = sec_matricule_format();   // migration secondaire v32
$exemple_mat = $prefixe_mat . sec_matricule_annee() . '0001' . $suffixe_mat;
$etab_mat    = get_etablissement();
$format_ok   = db_colonne_existe('etablissement', 'matricule_prefixe');
$peut_format = $format_ok && in_array(role_connecte(), ['ADMIN', 'PROVISEUR', 'FONDATEUR'], true);
$sans_mat = db_all("SELECT id, nom, prenom FROM eleve WHERE statut='actif' AND (matricule IS NULL OR TRIM(matricule)='')
                    ORDER BY nom, prenom LIMIT 200");
$doublons = db_all("SELECT e.id, e.nom, e.prenom, e.matricule, e.statut FROM eleve e
                    JOIN (SELECT matricule FROM eleve WHERE TRIM(COALESCE(matricule,''))<>'' GROUP BY matricule HAVING COUNT(*)>1) d
                      ON d.matricule = e.matricule
                    ORDER BY e.matricule, e.nom LIMIT 200");
$lien_fiche = fn(array $e) => '<a href="' . APP_URL . '/secondaire/commun/pages/eleves/voir.php?id=' . (int) $e['id'] . '" class="text-decoration-none">'
                            . h(trim(mb_strtoupper((string) $e['nom']) . ' ' . ($e['prenom'] ?? ''))) . '</a>';
?>
<div class="row g-3">

  <!-- ── Import Excel ─────────────────────────────────────────── -->
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-body">
        <div class="fw-bold mb-1" style="font-size:.9rem">
          <i class="bi bi-file-earmark-excel me-1 text-success"></i>Importer des élèves depuis Excel
        </div>
        <p class="text-muted mb-2" style="font-size:.8rem">
          Créer plusieurs élèves d'un coup à partir d'un fichier <span class="font-monospace">.xlsx</span>.
          Téléchargez le modèle, remplissez-le, puis ouvrez l'import.
        </p>
        <div class="d-flex gap-2 flex-wrap">
          <a href="<?= APP_URL ?>/secondaire/commun/pages/eleves/import.php" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-upload me-1"></i>Ouvrir l'import
          </a>
          <a href="<?= APP_URL ?>/secondaire/commun/pages/eleves/import_modele.php" class="btn btn-outline-success btn-sm">
            <i class="bi bi-download me-1"></i>Modèle Excel
          </a>
        </div>
      </div>
    </div>
  </div>

  <!-- ── Matricules ───────────────────────────────────────────── -->
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-body">
        <div class="fw-bold mb-1" style="font-size:.9rem">
          <i class="bi bi-hash me-1 text-primary"></i>Matricules des élèves
        </div>
        <p class="text-muted mb-2" style="font-size:.8rem">
          Générés automatiquement à la création de l'élève : préfixe, année, numéro, suffixe
          — exemple : <span class="badge-code font-monospace"><?= h($exemple_mat) ?></span>.
        </p>
        <?php if ($peut_format): ?>
          <form method="post" action="<?= APP_URL ?>/secondaire/commun/pages/eleves/matricule_format.php" class="row g-2 align-items-end mb-3">
            <?= csrf_champ() ?>
            <div class="col-4">
              <label class="form-label mb-0" style="font-size:.75rem">Préfixe</label>
              <input type="text" name="matricule_prefixe" maxlength="10" class="form-control form-control-sm font-monospace text-uppercase"
                     value="<?= h($etab_mat['matricule_prefixe'] ?? '') ?>" placeholder="<?= h(preg_replace('/[^A-Z0-9]/', '', strtoupper($etab_mat['sigle'] ?? ''))) ?>">
            </div>
            <div class="col-3">
              <label class="form-label mb-0" style="font-size:.75rem">Suffixe</label>
              <input type="text" name="matricule_suffixe" maxlength="5" class="form-control form-control-sm font-monospace text-uppercase"
                     value="<?= h($etab_mat['matricule_suffixe'] ?? '') ?>" placeholder="aucun">
            </div>
            <div class="col-5">
              <button class="btn btn-sm btn-abz w-100"><i class="bi bi-check2 me-1"></i>Enregistrer le format</button>
            </div>
            <div class="col-12">
              <div class="form-check" style="font-size:.78rem">
                <input class="form-check-input" type="checkbox" name="renommer" value="1" id="matRenommer">
                <label class="form-check-label" for="matRenommer">Renommer aussi les matricules existants (le numéro est conservé :
                  <span class="font-monospace"><?= h($exemple_mat) ?></span> → nouveau préfixe/suffixe)</label>
              </div>
              <div class="form-text" style="font-size:.72rem">Préfixe vide = sigle de l'école. Lettres et chiffres seulement.</div>
            </div>
          </form>
        <?php endif; ?>
        <?php if (!$sans_mat && !$doublons): ?>
          <div class="alert alert-success py-2 mb-0" style="font-size:.8rem">
            <i class="bi bi-check-circle me-1"></i>Tous les élèves actifs ont un matricule, sans doublon.
          </div>
        <?php else: ?>
          <?php if ($sans_mat): ?>
            <div class="fw-semibold text-danger mb-1" style="font-size:.8rem">
              <i class="bi bi-exclamation-triangle me-1"></i><?= count($sans_mat) ?> élève(s) actif(s) sans matricule
            </div>
            <div class="mb-2" style="font-size:.78rem"><?= implode(' · ', array_map($lien_fiche, $sans_mat)) ?></div>
          <?php endif; ?>
          <?php if ($doublons): ?>
            <div class="fw-semibold text-warning mb-1" style="font-size:.8rem">
              <i class="bi bi-files me-1"></i>Matricules en double
            </div>
            <table class="table table-sm mb-0" style="font-size:.78rem">
              <thead><tr><th>Matricule</th><th>Élève</th><th>Statut</th></tr></thead>
              <tbody>
                <?php foreach ($doublons as $e): ?>
                  <tr>
                    <td class="font-monospace"><?= h($e['matricule']) ?></td>
                    <td><?= $lien_fiche($e) ?></td>
                    <td><?= $e['statut'] === 'actif' ? 'Actif' : 'Désactivé' ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>
