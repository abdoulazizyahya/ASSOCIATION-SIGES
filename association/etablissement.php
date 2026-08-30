<?php
// association/etablissement.php — fiche synthèse d'un établissement
// (lecture seule, agrégée depuis la base de l'école).
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
require_once __DIR__ . '/_layout.php';
exiger_membre_association();

$id = (int) ($_GET['id'] ?? 0);
$e  = $id ? assoc_one("SELECT * FROM etablissement WHERE id=?", [$id]) : null;
if (!$e) {
    asso_haut('Établissement introuvable');
    echo '<div class="asso-card text-muted2">Cet établissement n\'existe pas dans l\'annuaire.</div>';
    asso_bas();
    exit;
}

// Droits de visite (comme entrer_ecole.php) — pour n'exposer la synthèse
// qu'aux membres autorisés sur cette école.
$m = membre_connecte();
$acces = assoc_one(
    "SELECT id FROM membre_acces
     WHERE id_membre=? AND actif=1 AND (id_etablissement IS NULL OR id_etablissement=?)
     LIMIT 1",
    [$m['id'], $id]
);

// Effectifs agrégés depuis la base école (best-effort : une école toute
// neuve ou une base injoignable ne doit pas casser la page).
$stats = null; $stats_err = '';
if ($acces) {
    try {
        $stats = avec_ecole($id, function (mysqli $l) {
            $active = ecole_one($l, "SELECT val_annee FROM annee_scolaire WHERE Etat_annee_scolaire=1 LIMIT 1");
            $annee  = $active
                  ?: ecole_one($l, "SELECT val_annee FROM annee_scolaire ORDER BY val_annee DESC LIMIT 1");
            $va = $annee['val_annee'] ?? null;

            $eff = $va
                ? ecole_one($l,
                    "SELECT
                        COUNT(*) AS total,
                        SUM(LOWER(el.Sexe_elv) LIKE 'm%') AS g,
                        SUM(LOWER(el.Sexe_elv) LIKE 'f%') AS f
                     FROM inscrire i
                     JOIN eleve el ON el.id_eleve = i.id_eleve
                     WHERE i.val_annee = ? AND el.statut = 'actif'", [$va])
                : ['total' => 0, 'g' => 0, 'f' => 0];

            return [
                'annee'        => $va,
                'annee_active' => !empty($active),
                'eleves'     => (int) ($eff['total'] ?? 0),
                'garcons'    => (int) ($eff['g'] ?? 0),
                'filles'     => (int) ($eff['f'] ?? 0),
                'classes'    => count(ecole_all($l, "SELECT IDClasses FROM classe")),
                'enseignants'=> count(ecole_all($l, "SELECT matricule_ens FROM enseignant WHERE statut_ens='actif' OR statut_ens IS NULL OR statut_ens=''")),
            ];
        });
    } catch (\Throwable $ex) {
        $stats_err = $ex->getMessage();
    }
}

// Affectations centrales (personnel de l'association affecté à cette école)
$affectations = assoc_all(
    "SELECT a.fonction, a.actif, p.nom, p.prenom, p.matricule
     FROM personnel_affectation a
     JOIN personnel p ON p.matricule = a.matricule
     WHERE a.id_etablissement = ?
     ORDER BY a.actif DESC, a.fonction", [$id]
);

asso_haut('Fiche — ' . $e['nom']);
?>
<a href="<?= APP_URL ?>/association/index.php" class="small text-decoration-none">← Établissements</a>

<div class="d-flex flex-wrap align-items-center gap-2 mt-2 mb-3">
  <span class="badge badge-soft"><?= h($e['code']) ?></span>
  <?php if (!$e['actif']): ?><span class="badge bg-warning text-dark">Inactive</span><?php endif; ?>
  <?php if ($e['sigle']): ?><span class="text-muted2 small"><?= h($e['sigle']) ?></span><?php endif; ?>
  <?php if ($e['ville']): ?><span class="text-muted2 small"><i class="bi bi-geo-alt me-1"></i><?= h($e['ville']) ?></span><?php endif; ?>
  <span class="text-muted2 small"><i class="bi bi-database me-1"></i><?= h($e['db_name']) ?></span>
  <?php if ($e['sous_domaine']): ?><span class="text-muted2 small"><i class="bi bi-globe me-1"></i><?= h($e['sous_domaine']) ?></span><?php endif; ?>
</div>

<?php if (!$acces): ?>
  <div class="alert alert-warning py-2 small">
    Vous n'avez pas d'accès à cet établissement — synthèse masquée.
    Un administrateur peut vous l'accorder (table <span class="font-monospace">membre_acces</span>).
  </div>
<?php else: ?>

  <?php if ($e['actif']): ?>
  <div class="d-flex flex-wrap gap-2 mb-3">
    <a href="<?= APP_URL ?>/association/entrer_ecole.php?id=<?= (int) $e['id'] ?>" class="btn btn-primary btn-sm">
      <i class="bi bi-box-arrow-in-right me-1"></i>Ouvrir en lecture seule
    </a>
    <a href="<?= APP_URL ?>/association/niu/index.php?etab=<?= (int) $e['id'] ?>" class="btn btn-outline-light btn-sm">
      <i class="bi bi-person-vcard me-1"></i>NIU de l'école
    </a>
  </div>
  <?php endif; ?>

  <?php if ($stats_err): ?>
    <div class="alert alert-warning py-2 small">Base école injoignable : <?= h($stats_err) ?></div>
  <?php elseif ($stats): ?>
    <div class="row g-3 mb-3">
      <div class="col-6 col-md-3"><div class="asso-card text-center">
        <div class="h4 fw-bold mb-0"><?= (int) $stats['eleves'] ?></div>
        <div class="small text-muted2">Élèves<?= $stats['annee'] ? ' · ' . h($stats['annee']) : '' ?></div>
      </div></div>
      <div class="col-6 col-md-3"><div class="asso-card text-center">
        <div class="h4 fw-bold mb-0"><?= (int) $stats['garcons'] ?> / <?= (int) $stats['filles'] ?></div>
        <div class="small text-muted2">Garçons / Filles</div>
      </div></div>
      <div class="col-6 col-md-3"><div class="asso-card text-center">
        <div class="h4 fw-bold mb-0"><?= (int) $stats['classes'] ?></div>
        <div class="small text-muted2">Classes</div>
      </div></div>
      <div class="col-6 col-md-3"><div class="asso-card text-center">
        <div class="h4 fw-bold mb-0"><?= (int) $stats['enseignants'] ?></div>
        <div class="small text-muted2">Enseignant(e)s</div>
      </div></div>
    </div>
    <?php if (!$stats['annee_active']): ?>
      <div class="alert alert-secondary py-2 small">
        Aucune année scolaire active dans cette base — l'école n'est pas encore opérationnelle.
      </div>
    <?php endif; ?>
  <?php endif; ?>

<?php endif; ?>

<div class="asso-card p-0">
  <div class="px-3 py-2 small text-muted2 border-bottom" style="border-color:#23304d!important">
    Personnel affecté par l'association
  </div>
  <table class="table table-dark table-sm mb-0 align-middle" style="font-size:.83rem">
    <tbody>
      <?php foreach ($affectations as $a): ?>
        <tr>
          <td class="font-monospace"><?= h($a['matricule']) ?></td>
          <td><?= h(trim($a['nom'] . ' ' . $a['prenom'])) ?></td>
          <td><span class="badge badge-soft"><?= h($a['fonction']) ?></span></td>
          <td class="text-end small text-muted2"><?= $a['actif'] ? '' : 'clôturée' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$affectations): ?>
        <tr><td class="text-center text-muted2 py-3">Aucune affectation centrale.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
<?php asso_bas();
