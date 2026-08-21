<?php
header('Cache-Control: no-store, no-cache, must-revalidate');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_connexion();

$role = role_connecte();
if (!in_array($role, ['ADMIN','PROVISEUR','CENSEUR'])) {
    flash_set('erreur', 'Accès réservé à l\'administration.');
    rediriger('dashboard.php');
}

$q = trim($_GET['q'] ?? '');
$where = ''; $params = [];
if ($q !== '') {
    $where = "WHERE e.nom_ens LIKE ? OR e.prenom_ens LIKE ? OR e.matricule_ens LIKE ?";
    $params = ["%$q%", "%$q%", "%$q%"];
}

$enseignants = db_all(
    "SELECT e.* FROM enseignant e $where ORDER BY e.nom_ens, e.prenom_ens",
    $params
);

// Régions et départements d'origine présents (pour l'export en lot filtré).
// Défensif : si la colonne n'existe pas encore (migration v6 non appliquée),
// on n'affiche simplement pas le sélecteur.
$deps_present = [];
try {
    $deps_present = db_all(
        "SELECT DISTINCT departement_origine AS d FROM enseignant
         WHERE departement_origine IS NOT NULL AND departement_origine <> ''
         ORDER BY d"
    );
} catch (Throwable $ex) { $deps_present = []; }

$regions_present = [];
try {
    $regions_present = db_all(
        "SELECT DISTINCT region_origine AS r FROM enseignant
         WHERE region_origine IS NOT NULL AND region_origine <> ''
         ORDER BY r"
    );
} catch (Throwable $ex) { $regions_present = []; }

$titre_page = 'Enseignants';
require_once __DIR__ . '/../../layout/header.php';
?>
<style>
.btn-abz-primary{background:#1a3c6b;color:#fff;border:none;}
.btn-abz-primary:hover{background:#12305a;color:#fff;}
.btn-abz-outline{background:#fff;color:#1a3c6b;border:1.5px solid #1a3c6b;}
.btn-abz-outline:hover{background:#1a3c6b;color:#fff;}
.btn-abz-danger{background:#b91c1c;color:#fff;border:none;}
.btn-abz-danger:hover{background:#991b1b;color:#fff;}
.tbl-ens th{background:#1a3c6b;color:#fff;font-size:.78rem;padding:8px 10px;}
.tbl-ens td{font-size:.81rem;padding:7px 10px;vertical-align:middle;}
.tbl-ens tr:hover td{background:#f0f4ff;}
</style>

<div class="page-titre d-flex align-items-center justify-content-between flex-wrap gap-2">
  <h4><i class="bi bi-person-badge me-2" style="color:#1a3c6b"></i>Gestion des Enseignants</h4>
  <a href="form.php" class="btn btn-abz-primary btn-sm">
    <i class="bi bi-plus-lg me-1"></i>Nouvel enseignant
  </a>
</div>

<?= flash_html() ?>

<div class="card mb-3" style="border-color:#c7d8f0">
  <div class="card-body py-2">
    <form method="get" class="d-flex gap-2">
      <input type="search" name="q" value="<?= h($q) ?>" placeholder="Rechercher par nom, prénom, matricule…"
             class="form-control form-control-sm" style="max-width:320px">
      <button class="btn btn-sm btn-abz-outline"><i class="bi bi-search me-1"></i>Rechercher</button>
      <?php if ($q): ?><a href="index.php" class="btn btn-sm btn-secondary">Effacer</a><?php endif; ?>
    </form>
  </div>
</div>

<!-- Export en lot des dossiers administratifs -->
<div class="card mb-3" style="border-color:#c7d8f0">
  <div class="card-body py-2 d-flex align-items-center flex-wrap gap-2">
    <span class="fw-semibold me-1" style="color:#1a3c6b;font-size:.83rem">
      <i class="bi bi-folder2-open me-1"></i>Export en lot des dossiers
    </span>

    <button type="button" class="btn btn-sm btn-abz-primary"
            onclick="afficherApercu('pdf_dossier_lot.php', 'Dossiers administratifs — Tous', 'dossier_enseignant', 'portrait')">
      <i class="bi bi-files me-1"></i>Tous les dossiers
    </button>

    <?php if ($q !== ''): ?>
      <button type="button" class="btn btn-sm btn-abz-outline"
              onclick="afficherApercu('pdf_dossier_lot.php?q=<?= urlencode($q) ?>', 'Dossiers administratifs — Résultats de recherche', 'dossier_enseignant', 'portrait')">
        <i class="bi bi-search me-1"></i>Résultats de la recherche
      </button>
    <?php endif; ?>

    <?php if (!empty($regions_present)): ?>
      <form onsubmit="event.preventDefault(); afficherApercu('pdf_dossier_lot.php?region='+encodeURIComponent(this.region.value), 'Dossiers administratifs — '+this.region.value, 'dossier_enseignant', 'portrait')"
            class="d-flex align-items-center gap-2<?= empty($deps_present) ? ' ms-auto' : '' ?>">
        <label class="text-muted" style="font-size:.8rem">Par région :</label>
        <select name="region" class="form-select form-select-sm" style="max-width:220px" required>
          <option value="">— Choisir —</option>
          <?php foreach ($regions_present as $r): ?>
            <option value="<?= h($r['r']) ?>"><?= h($r['r']) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-abz-outline"><i class="bi bi-eye me-1"></i>Aperçu</button>
      </form>
    <?php endif; ?>

    <?php if (!empty($deps_present)): ?>
      <form onsubmit="event.preventDefault(); afficherApercu('pdf_dossier_lot.php?dep='+encodeURIComponent(this.dep.value), 'Dossiers administratifs — '+this.dep.value, 'dossier_enseignant', 'portrait')"
            class="d-flex align-items-center gap-2 ms-auto">
        <label class="text-muted" style="font-size:.8rem">Par département :</label>
        <select name="dep" class="form-select form-select-sm" style="max-width:220px" required>
          <option value="">— Choisir —</option>
          <?php foreach ($deps_present as $d): ?>
            <option value="<?= h($d['d']) ?>"><?= h($d['d']) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-abz-outline"><i class="bi bi-eye me-1"></i>Aperçu</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="card" style="border-color:#c7d8f0">
  <div class="card-header py-2" style="background:#f0f4ff">
    <span class="fw-semibold" style="color:#1a3c6b;font-size:.88rem">
      <i class="bi bi-person-badge me-1"></i><?= count($enseignants) ?> enseignant(s)
    </span>
  </div>
  <div class="table-responsive">
    <table class="table tbl-ens table-hover mb-0">
      <thead>
        <tr>
          <th>N°</th>
          <th>Matricule</th>
          <th>Nom et Prénom</th>
          <th>Grade / Fonction</th>
          <th>Téléphone</th>
          <th>Email</th>
          <th style="text-align:center;width:200px">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($enseignants)): ?>
        <tr><td colspan="7" class="text-center text-muted py-4">Aucun enseignant enregistré.</td></tr>
        <?php endif; ?>
        <?php foreach ($enseignants as $i => $e): ?>
        <tr>
          <td class="text-muted"><?= $i+1 ?></td>
          <td><code><?= h($e['matricule_ens']) ?></code></td>
          <td class="fw-semibold">
            <?= h(($e['civilite_ens']?$e['civilite_ens'].' ':'').strtoupper($e['nom_ens']).' '.($e['prenom_ens']??'')) ?>
          </td>
          <td>
            <span style="font-size:.77rem"><?= h($e['id_grade']??'—') ?></span>
            <?php if (!empty($e['id_fonction'])): ?>
              <br><small class="text-muted"><?= h($e['id_fonction']) ?></small>
            <?php endif; ?>
          </td>
          <td><?= h($e['tel_ens'] ?? '—') ?></td>
          <td><?= h($e['mail_ens'] ?? '—') ?></td>
          <td class="text-center">
            <a href="form.php?id=<?= $e['matricule_ens'] ?>" class="btn btn-sm btn-abz-outline"
               style="font-size:.72rem;padding:3px 8px" title="Modifier">
              <i class="bi bi-pencil"></i>
            </a>
            <a href="fiche.php?id=<?= urlencode($e['matricule_ens']) ?>" class="btn btn-sm btn-abz-primary"
               style="font-size:.72rem;padding:3px 8px" title="Fiche">
              <i class="bi bi-eye"></i>
            </a>
            <a href="apercu.php?id=<?= urlencode($e['matricule_ens']) ?>&type=attestation"
               class="btn btn-sm btn-secondary"
               style="font-size:.72rem;padding:3px 8px" title="Attestation de présence">
              <i class="bi bi-file-earmark-text"></i>
            </a>
            <a href="apercu.php?id=<?= urlencode($e['matricule_ens']) ?>&type=prise_service"
               class="btn btn-sm btn-secondary"
               style="font-size:.72rem;padding:3px 8px" title="Certificat prise de service">
              <i class="bi bi-file-earmark-check"></i>
            </a>
            <a href="apercu.php?id=<?= urlencode($e['matricule_ens']) ?>&type=dossier"
               class="btn btn-sm btn-abz-primary"
               style="font-size:.72rem;padding:3px 8px" title="Dossier administratif complet">
              <i class="bi bi-folder2-open"></i>
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/../../layout/footer.php'; ?>
