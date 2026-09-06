<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_connexion();

$id    = (int)($_GET['id'] ?? 0);
exiger_acces_eleve($id, 'union');   // enseignant restreint
$eleve = db_one("SELECT * FROM eleve WHERE id=?", [$id]);
if (!$eleve) { flash_set('erreur','Élève introuvable.'); rediriger('pages/eleves/liste.php'); }

$annee    = get_annee_active();
$id_annee = (int)$annee['id'];

$inscriptions = db_all(
    "SELECT i.*, c.designation AS classe, a.libelle AS annee
     FROM inscription i
     JOIN classe c ON c.id=i.id_classe
     JOIN annee_scolaire a ON a.id=i.id_annee
     WHERE i.id_eleve=? ORDER BY a.libelle DESC", [$id]);

$tuteurs  = db_all("SELECT * FROM tuteur WHERE id_eleve=?", [$id]);
$formats  = db_all("SELECT * FROM format_carte ORDER BY id");

// Classe actuelle
$classe_actuelle = !empty($inscriptions) ? $inscriptions[0]['classe'] : null;

$titre_page = 'Fiche élève';
require_once __DIR__ . '/../../layout/header.php';
?>

<!-- En-tête -->
<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <a href="<?= APP_URL ?>/pages/eleves/liste.php" class="btn btn-sm btn-light">
    <i class="bi bi-arrow-left me-1"></i>Retour
  </a>
  <div class="flex-grow-1">
    <h4 class="mb-0" style="font-size:1.05rem;font-weight:700">
      <?= h(strtoupper($eleve['nom']) . ' ' . ($eleve['prenom'] ?? '')) ?>
    </h4>
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <span style="font-size:.75rem;color:#6b7280"><?= h($eleve['matricule']) ?></span>
      <?php if ($eleve['statut'] === 'actif'): ?>
        <span style="background:#d1fae5;color:#065f46;font-size:.68rem;padding:1px 8px;border-radius:10px;font-weight:600">
          <i class="bi bi-circle-fill me-1" style="font-size:.4rem"></i>Actif
        </span>
      <?php else: ?>
        <span style="background:#fee2e2;color:#991b1b;font-size:.68rem;padding:1px 8px;border-radius:10px;font-weight:600">
          <i class="bi bi-circle-fill me-1" style="font-size:.4rem"></i>Désactivé
        </span>
      <?php endif; ?>
    </div>
  </div>
  <!-- Actions -->
  <div class="d-flex gap-1 flex-wrap">
    <a href="<?= APP_URL ?>/pages/eleves/form.php?id=<?= $id ?>" class="btn btn-primary btn-sm">
      <i class="bi bi-pencil me-1"></i>Modifier
    </a>
    <a href="<?= APP_URL ?>/pdf/fiche_eleve.php?id=<?= $id ?>" target="_blank"
       class="btn btn-outline-danger btn-sm">
      <i class="bi bi-file-earmark-pdf me-1"></i>Fiche PDF
    </a>
    <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalCarte">
      <i class="bi bi-credit-card me-1"></i>Carte scolaire
    </button>
    <?php if ($eleve['statut'] === 'actif'): ?>
      <a href="<?= APP_URL ?>/pages/eleves/statut.php?id=<?= $id ?>&csrf=<?= csrf_generer() ?>"
         class="btn btn-outline-warning btn-sm"
         onclick="return confirm('Désactiver cet élève ?')">
        <i class="bi bi-toggle-on me-1"></i>Désactiver
      </a>
    <?php else: ?>
      <a href="<?= APP_URL ?>/pages/eleves/statut.php?id=<?= $id ?>&csrf=<?= csrf_generer() ?>"
         class="btn btn-outline-success btn-sm">
        <i class="bi bi-toggle-off me-1"></i>Réactiver
      </a>
    <?php endif; ?>
  </div>
</div>

<div class="row g-3">

  <!-- Photo + identité -->
  <div class="col-lg-3">
    <div class="card text-center h-100">
      <div class="card-body">
        <?php if (!empty($eleve['photo'])): ?>
          <img src="<?= UPLOAD_URL . h($eleve['photo']) ?>"
               style="width:100px;height:120px;object-fit:cover;border-radius:8px;border:2px solid #d1daf0;margin-bottom:.6rem">
        <?php else: ?>
          <div style="width:100px;height:120px;border-radius:8px;background:#eef2ff;color:#1e4fd8;
                      display:flex;align-items:center;justify-content:center;font-size:2.2rem;
                      font-weight:700;margin:0 auto .6rem">
            <?= h(mb_strtoupper(mb_substr($eleve['nom'],0,1) . mb_substr($eleve['prenom']??'',0,1))) ?>
          </div>
        <?php endif; ?>
        <div class="fw-bold" style="font-size:.88rem">
          <?= h(strtoupper($eleve['nom']) . ' ' . ($eleve['prenom'] ?? '')) ?>
        </div>
        <div style="font-size:.72rem;color:#6b7280"><?= h($eleve['matricule']) ?></div>
        <div class="mt-1">
          <?= $eleve['sexe']==='F'
              ? '<span class="badge-f">Féminin</span>'
              : '<span class="badge-m">Masculin</span>' ?>
        </div>
        <?php if ($classe_actuelle): ?>
          <div class="mt-2" style="background:#f0f5ff;border-radius:7px;padding:4px 8px;font-size:.74rem">
            <i class="bi bi-door-open me-1 text-primary"></i><?= h($classe_actuelle) ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- État civil -->
  <div class="col-lg-5">
    <div class="card mb-2">
      <div class="card-body">
        <div class="section-titre"><i class="bi bi-person me-1"></i>État civil</div>
        <?php
        $champs = [
          'Date de naissance' => date_fr($eleve['date_naiss']),
          'Lieu de naissance' => $eleve['lieu_naiss'] ?: '—',
          'NIU'               => $eleve['niu'] ?: '—',
          'Téléphone'         => $eleve['telephone'] ?: '—',
          'Adresse'           => $eleve['adresse'] ?: '—',
        ];
        foreach ($champs as $lbl => $val): ?>
          <div class="d-flex py-1" style="border-bottom:1px solid #f3f4f6;font-size:.8rem">
            <span style="min-width:140px;color:#6b7280;font-size:.74rem"><?= $lbl ?></span>
            <strong><?= h($val) ?></strong>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Tuteurs -->
    <?php if ($tuteurs): ?>
    <div class="card">
      <div class="card-body">
        <div class="section-titre"><i class="bi bi-people me-1"></i>Parents / Tuteurs</div>
        <?php foreach ($tuteurs as $t): ?>
          <div class="d-flex align-items-center gap-2 py-1" style="border-bottom:1px solid #f3f4f6;font-size:.8rem">
            <span class="badge-code"><?= h($t['lien'] ?? 'Tuteur') ?></span>
            <div>
              <div class="fw-semibold"><?= h($t['nom'] . ' ' . ($t['prenom']??'')) ?></div>
              <?php if ($t['telephone']): ?>
                <div style="color:#6b7280;font-size:.72rem"><i class="bi bi-telephone me-1"></i><?= h($t['telephone']) ?></div>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Historique scolaire -->
  <div class="col-lg-4">
    <div class="card">
      <div class="card-body">
        <div class="section-titre"><i class="bi bi-journal-text me-1"></i>Historique scolaire</div>
        <?php if (empty($inscriptions)): ?>
          <p class="text-muted mb-0" style="font-size:.78rem">Aucune inscription.</p>
        <?php else: ?>
          <table class="table table-sm mb-0" style="font-size:.78rem">
            <thead style="background:#f8faff">
              <tr>
                <th style="padding:4px 6px">Année</th>
                <th style="padding:4px 6px">Classe</th>
                <th style="padding:4px 6px">Statut</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($inscriptions as $i): ?>
                <tr>
                  <td style="padding:4px 6px"><?= h($i['annee']) ?></td>
                  <td style="padding:4px 6px"><?= h($i['classe']) ?></td>
                  <td style="padding:4px 6px">
                    <span class="badge-code"><?= h($i['statut']) ?></span>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>

<!-- Modal carte scolaire -->
<div class="modal fade" id="modalCarte" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title fw-bold">
          <i class="bi bi-credit-card me-1 text-primary"></i>Carte scolaire
        </h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body py-2">
        <label class="form-label">Format</label>
        <select id="selFmt" class="form-select form-select-sm">
          <?php foreach ($formats as $f): ?>
            <option value="<?= $f['id'] ?>">
              <?= h($f['libelle']) ?> (<?= $f['cartes_par_page'] ?> /page)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="modal-footer py-2">
        <button class="btn btn-primary btn-sm" onclick="imprimerCarte()">
          <i class="bi bi-printer me-1"></i>Générer PDF
        </button>
        <button class="btn btn-light btn-sm" data-bs-dismiss="modal">Annuler</button>
      </div>
    </div>
  </div>
</div>
<script>
function imprimerCarte() {
  const fmt = document.getElementById('selFmt').value;
  window.open('<?= APP_URL ?>/pdf/cartes.php?eleve=<?= $id ?>&annee=<?= $id_annee ?>&format='+fmt,'_blank');
  bootstrap.Modal.getInstance(document.getElementById('modalCarte')).hide();
}
</script>
<?php require_once __DIR__ . '/../../layout/footer.php'; ?>
