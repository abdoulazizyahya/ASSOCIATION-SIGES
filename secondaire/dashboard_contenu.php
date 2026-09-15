<?php
// secondaire/dashboard_contenu.php — contenu du tableau de bord pour une
// école secondaire (schema_ref_ecole_secondaire.sql, porté de LAM_ABZ).
// Inclus par dashboard.php (racine) quand type_enseignement_courant() ===
// 'secondaire', APRÈS exiger_connexion() — connexion.php/fonctions.php
// déjà chargés par l'appelant. Adapté de LAM_ABZ/dashboard.php (mêmes
// statistiques) aux helpers SIGES (db_all/db_val, h(), APP_URL) et au
// layout/header.php commun (déjà rendu type-aware : get_etablissement(),
// get_annee_active(), menu_definition()…).

$annee    = get_annee_active();
$id_annee = (int) ($annee['id'] ?? 0);

$nb_eleves   = (int) db_val("SELECT COUNT(*) FROM eleve WHERE statut='actif'");
$nb_garcons  = (int) db_val("SELECT COUNT(*) FROM eleve WHERE statut='actif' AND sexe='M'");
$nb_filles   = (int) db_val("SELECT COUNT(*) FROM eleve WHERE statut='actif' AND sexe='F'");
$nb_classes  = (int) db_val("SELECT COUNT(*) FROM classe WHERE archivee=0");
$nb_inscrits = $id_annee ? (int) db_val("SELECT COUNT(*) FROM inscription WHERE id_annee=?", [$id_annee]) : 0;

$repartition = $id_annee ? db_all(
    "SELECT c.designation, COUNT(i.id) AS nb,
            SUM(e.sexe='M') AS m, SUM(e.sexe='F') AS f
     FROM classe c
     LEFT JOIN inscription i ON i.id_classe=c.id AND i.id_annee=?
     LEFT JOIN eleve e ON e.id=i.id_eleve AND e.statut='actif'
     WHERE c.archivee=0
     GROUP BY c.id ORDER BY nb DESC LIMIT 10",
    [$id_annee]
) : [];

$titre_page = 'Tableau de bord';
require_once __DIR__ . '/../layout/header.php';
?>

<div class="page-titre">
  <div>
    <h4><i class="bi bi-speedometer2 me-1 text-primary"></i>Tableau de bord</h4>
    <div class="sub">Année scolaire <?= h($annee['val_annee'] ?? '—') ?> — établissement secondaire</div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:#eef2ff;color:#1e4fd8"><i class="bi bi-people-fill"></i></div>
      <div><div class="stat-val"><?= $nb_eleves ?></div><div class="stat-lbl">Élèves actifs</div></div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:#dde9ff;color:#1a3fb0"><i class="bi bi-gender-male"></i></div>
      <div><div class="stat-val"><?= $nb_garcons ?></div><div class="stat-lbl">Garçons</div></div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:#ffe0e6;color:#c0144e"><i class="bi bi-gender-female"></i></div>
      <div><div class="stat-val"><?= $nb_filles ?></div><div class="stat-lbl">Filles</div></div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:#d1fae5;color:#065f46"><i class="bi bi-door-open-fill"></i></div>
      <div><div class="stat-val"><?= $nb_classes ?></div><div class="stat-lbl">Classes actives</div></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-body">
    <div class="section-titre mb-3"><i class="bi bi-bar-chart me-1"></i>Répartition par classe — <?= h($annee['val_annee'] ?? '—') ?> (<?= $nb_inscrits ?> inscrit(s))</div>
    <?php if (empty($repartition)): ?>
      <p class="text-muted mb-0" style="font-size:.8rem">Aucune inscription pour cette année.</p>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm table-abz mb-0">
          <thead>
            <tr>
              <th>Classe</th>
              <th class="text-center">Garçons</th>
              <th class="text-center">Filles</th>
              <th class="text-center">Total</th>
              <th style="min-width:120px">Jauge</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $max = max(array_column($repartition, 'nb') ?: [1]);
            foreach ($repartition as $r):
                $pct = $max > 0 ? round($r['nb'] / $max * 100) : 0;
            ?>
              <tr>
                <td class="fw-semibold"><?= h($r['designation']) ?></td>
                <td class="text-center"><span class="badge-m"><?= (int) $r['m'] ?></span></td>
                <td class="text-center"><span class="badge-f"><?= (int) $r['f'] ?></span></td>
                <td class="text-center fw-bold"><?= (int) $r['nb'] ?></td>
                <td>
                  <div style="background:#e5eaf5;border-radius:6px;height:8px;overflow:hidden">
                    <div style="width:<?= $pct ?>%;height:100%;background:linear-gradient(90deg,#1e4fd8,#3a6cff);border-radius:6px"></div>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
