<?php
// secondaire/commun/pages/eleves/voir.php — fiche d'un élève (école secondaire).
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
exiger_connexion();

$id    = (int) ($_GET['id'] ?? 0);
exiger_acces_eleve_secondaire($id);   // enseignant / SG : seulement leurs classes
$eleve = $id ? db_one("SELECT * FROM eleve WHERE id=?", [$id]) : null;
if (!$eleve) { flash_set('erreur', 'Élève introuvable.'); rediriger('secondaire/commun/pages/eleves/liste.php'); }

$annee    = get_annee_active();
$id_annee = (int) ($annee['id'] ?? 0);
$inscription = $id_annee ? db_one(
    "SELECT i.*, c.designation AS classe FROM inscription i JOIN classe c ON c.id=i.id_classe
     WHERE i.id_eleve=? AND i.id_annee=?", [$id, $id_annee]
) : null;

// Dossier de l'élève (pièces jointes) — porté d'ABZ_MBE, table dossier_eleve
// (migration secondaire v14). Base pas encore migrée : bloc masqué.
$types_dossier = ['acte_naissance', 'carnet_vaccination', 'bulletin', 'document_transfert', 'photo_4x4', 'autre'];
try {
    $documents = db_all("SELECT * FROM dossier_eleve WHERE id_eleve=? ORDER BY type_document, date_ajout DESC", [$id]);
} catch (\Throwable $e) {
    $documents = null;
}
$peut_gerer_dossier = in_array(role_connecte(), ['ADMIN', 'PROVISEUR', 'FONDATEUR', 'CENSEUR', 'SG', 'SECRETAIRE'], true);

$titre_page = 'Fiche élève';
require_once __DIR__ . '/../../../../noyau/layout/header.php';
?>

<div class="d-flex align-items-center gap-2 mb-3">
  <a href="<?= APP_URL ?>/secondaire/commun/pages/eleves/liste.php" class="btn btn-sm btn-light"><i class="bi bi-arrow-left"></i></a>
  <div>
    <h4 class="mb-0" style="font-size:1.05rem;font-weight:700"><?= h(mb_strtoupper($eleve['nom']) . ' ' . ($eleve['prenom'] ?? '')) ?></h4>
    <div class="sub"><span class="badge-code"><?= h($eleve['matricule']) ?></span></div>
  </div>
  <a href="<?= APP_URL ?>/secondaire/commun/pages/eleves/form.php?id=<?= (int) $eleve['id'] ?>" class="btn btn-primary btn-sm ms-auto">
    <i class="bi bi-pencil me-1"></i>Modifier
  </a>
</div>

<?= flash_html() ?>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="card mb-2">
      <div class="card-body">
        <div class="section-titre"><i class="bi bi-person me-1"></i>Informations personnelles</div>
        <div class="row g-2" style="font-size:.85rem">
          <div class="col-md-6"><span class="text-muted">Sexe :</span> <?= $eleve['sexe'] === 'F' ? '<span class="badge-f">F</span>' : '<span class="badge-m">M</span>' ?></div>
          <div class="col-md-6"><span class="text-muted">Date de naissance :</span> <?= h(date_fr($eleve['date_naiss'])) ?></div>
          <div class="col-md-6"><span class="text-muted">Lieu de naissance :</span> <?= h($eleve['lieu_naiss'] ?: '—') ?></div>
          <div class="col-md-6"><span class="text-muted">NIU :</span> <?= h($eleve['niu'] ?: '—') ?></div>
          <div class="col-md-6"><span class="text-muted">Téléphone :</span> <?= h($eleve['telephone'] ?: '—') ?></div>
          <div class="col-md-6"><span class="text-muted">Adresse :</span> <?= h($eleve['adresse'] ?: '—') ?></div>
          <div class="col-md-6"><span class="text-muted">Région/dépt/arrondt de naissance :</span>
            <?= h(implode(' / ', array_filter([$eleve['region_naiss'] ?? null, $eleve['departement_naiss'] ?? null, $eleve['arrondissement_naiss'] ?? null]))) ?: '—' ?>
          </div>
        </div>
      </div>
    </div>
    <div class="card">
      <div class="card-body">
        <div class="section-titre"><i class="bi bi-journal-check me-1"></i>Inscription — <?= h($annee['val_annee'] ?? '—') ?></div>
        <?php if ($inscription): ?>
          <div style="font-size:.85rem">
            <span class="text-muted">Classe :</span> <strong><?= h($inscription['classe']) ?></strong>
            &nbsp;·&nbsp; <span class="text-muted">Statut :</span> <?= h($inscription['statut']) ?>
            &nbsp;·&nbsp; <span class="text-muted">Inscrit le :</span> <?= h(date_fr($inscription['date_inscription'])) ?>
          </div>
        <?php else: ?>
          <p class="text-muted mb-0" style="font-size:.82rem">Non inscrit pour cette année.</p>
        <?php endif; ?>
      </div>
    </div>
    <?php if ($documents !== null): ?>
    <div class="card mt-2">
      <div class="card-body">
        <div class="d-flex align-items-center justify-content-between">
          <div class="section-titre mb-0"><i class="bi bi-folder2-open me-1"></i>Dossier de l'élève</div>
          <?php if ($peut_gerer_dossier): ?>
          <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalDossier">
            <i class="bi bi-plus-lg me-1"></i>Ajouter un document
          </button>
          <?php endif; ?>
        </div>
        <?php if (!$documents): ?>
          <p class="text-muted mb-0 mt-1" style="font-size:.78rem">Aucune pièce jointe (acte de naissance, carnet de vaccination, bulletin, document de transfert, photo 4×4…).</p>
        <?php else: ?>
          <div class="table-responsive mt-1">
            <table class="table table-sm table-abz mb-0" style="font-size:.8rem">
              <thead><tr><th>Type</th><th>Libellé</th><th>Ajouté le</th><th class="text-end">Actions</th></tr></thead>
              <tbody>
                <?php foreach ($documents as $d): ?>
                  <tr>
                    <td><span class="badge-code"><?= h(libelle_type_dossier($d['type_document'])) ?></span></td>
                    <td><?= h($d['libelle'] ?: '—') ?></td>
                    <td style="color:#6b7280"><?= h(date('d/m/Y', strtotime($d['date_ajout']))) ?></td>
                    <td class="text-end text-nowrap">
                      <a href="<?= APP_URL ?>/secondaire/commun/pages/eleves/dossier_fichier.php?id=<?= (int) $d['id'] ?>" target="_blank"
                         class="btn btn-sm" style="background:#eef2ff;color:#1e4fd8;padding:3px 7px" title="Voir">
                        <i class="bi bi-eye" style="font-size:.78rem"></i>
                      </a>
                      <?php if ($peut_gerer_dossier): ?>
                      <form method="post" action="<?= APP_URL ?>/secondaire/commun/pages/eleves/dossier_supprimer.php" class="d-inline"
                            onsubmit="return confirm('Retirer ce document du dossier ?')">
                        <?= csrf_champ() ?>
                        <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                        <button class="btn btn-sm btn-light text-danger" style="padding:3px 7px" title="Retirer">
                          <i class="bi bi-trash" style="font-size:.78rem"></i>
                        </button>
                      </form>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
  <div class="col-lg-4">
    <div class="card">
      <div class="card-body text-center">
        <div class="avatar mx-auto mb-2" style="width:64px;height:64px;font-size:1.4rem">
          <?= h(mb_strtoupper(mb_substr($eleve['nom'], 0, 1) . mb_substr($eleve['prenom'] ?? '', 0, 1))) ?>
        </div>
        <div class="fw-bold"><?= h(mb_strtoupper($eleve['nom'])) ?> <?= h($eleve['prenom'] ?? '') ?></div>
        <span class="badge <?= $eleve['statut'] === 'actif' ? 'bg-success' : 'bg-secondary' ?>" style="font-size:.7rem">
          <?= $eleve['statut'] === 'actif' ? 'Actif' : 'Désactivé' ?>
        </span>
      </div>
    </div>
  </div>
</div>

<?php if ($documents !== null && $peut_gerer_dossier): ?>
<!-- Ajout d'un document au dossier de l'élève -->
<div class="modal fade" id="modalDossier" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" action="<?= APP_URL ?>/secondaire/commun/pages/eleves/dossier_upload.php" enctype="multipart/form-data">
        <?= csrf_champ() ?>
        <input type="hidden" name="id_eleve" value="<?= (int) $id ?>">
        <div class="modal-header py-2">
          <h6 class="modal-title fw-bold"><i class="bi bi-folder2-open me-1 text-primary"></i>Ajouter un document</h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <label class="form-label">Type de document</label>
          <select name="type_document" class="form-select form-select-sm mb-2" required>
            <?php foreach ($types_dossier as $t): ?>
              <option value="<?= $t ?>"><?= h(libelle_type_dossier($t)) ?></option>
            <?php endforeach; ?>
          </select>
          <label class="form-label">Libellé (optionnel)</label>
          <input type="text" name="libelle" class="form-control form-control-sm mb-2" placeholder="Ex. Bulletin 1er trimestre">
          <label class="form-label">Fichier</label>
          <input type="file" name="fichier" accept=".pdf,.jpg,.jpeg,.png" class="form-control form-control-sm" required>
          <div class="form-text" style="font-size:.68rem">PDF, JPG ou PNG — 750 Ko max.</div>
        </div>
        <div class="modal-footer py-2">
          <button class="btn btn-primary btn-sm"><i class="bi bi-upload me-1"></i>Ajouter</button>
          <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Annuler</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../../../noyau/layout/footer.php'; ?>
