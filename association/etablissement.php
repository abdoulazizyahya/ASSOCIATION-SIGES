<?php
// association/etablissement.php — fiche synthèse d'un établissement
// (lecture seule, agrégée depuis la base de l'école).
// Réservée à l'Administrateur (superadmin) — un Membre/Superviseur ne voit
// plus cette synthèse (finances agrégées, effectifs…), il peut seulement
// « Ouvrir » l'école selon son attribution. Demande explicite du 23/09/2026.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
require_once __DIR__ . '/_layout.php';
exiger_superadmin_association();

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
// Schéma DISTINCT selon type_enseignement (val_annee/Sexe_elv/inscrire côté
// primaire vs libelle/sexe/inscription côté secondaire, schema_ref_ecole
// vs schema_ref_ecole_secondaire.sql) — piloté ici, jamais mélangé, sinon
// « Champ … inconnu » sur toute école secondaire (bug réel constaté, la
// requête primaire tournait inconditionnellement, cf. $stats_err ci-dessous).
$stats = null; $stats_err = '';
if ($acces) {
    $secondaire = ($e['type_enseignement'] ?? 'primaire') === 'secondaire';
    try {
        $stats = avec_ecole($id, function (mysqli $l) use ($id, $secondaire) {
            // Garde le logo de l'annuaire à jour (il se règle DANS l'école).
            assoc_sync_logo_ecole($l, $id);

            if ($secondaire) {
                $active = ecole_one($l, "SELECT id, libelle FROM annee_scolaire WHERE active=1 LIMIT 1");
                $annee  = $active
                      ?: ecole_one($l, "SELECT id, libelle FROM annee_scolaire ORDER BY libelle DESC LIMIT 1");
                $id_annee = $annee['id'] ?? null;
                $va = $annee['libelle'] ?? null;

                $eff = $id_annee
                    ? ecole_one($l,
                        "SELECT
                            COUNT(*) AS total,
                            SUM(el.sexe = 'M') AS g,
                            SUM(el.sexe = 'F') AS f
                         FROM inscription i
                         JOIN eleve el ON el.id = i.id_eleve
                         WHERE i.id_annee = ? AND el.statut = 'actif'", [$id_annee])
                    : ['total' => 0, 'g' => 0, 'f' => 0];

                return [
                    'annee'        => $va,
                    'annee_active' => !empty($active),
                    'eleves'      => (int) ($eff['total'] ?? 0),
                    'garcons'     => (int) ($eff['g'] ?? 0),
                    'filles'      => (int) ($eff['f'] ?? 0),
                    'classes'     => count(ecole_all($l, "SELECT id FROM classe WHERE archivee=0")),
                    'enseignants' => count(ecole_all($l, "SELECT matricule_ens FROM enseignant WHERE id_fonction='ENSEIGNANT'")),
                ];
            }

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

// État de la base MySQL de l'école (pour proposer sa création si absente).
require_once __DIR__ . '/../bd/lib/ecole_maintenance.php';
$base_etat = ecole_base_etat($e['db_name']);
$base_absente = !$base_etat['existe'] || $base_etat['tables'] === 0;

// Affectations centrales (personnel de l'association affecté à cette école)
$affectations = assoc_all(
    "SELECT a.fonction, a.actif, p.nom, p.prenom, p.matricule
     FROM personnel_affectation a
     JOIN personnel p ON p.matricule = a.matricule
     WHERE a.id_etablissement = ?
     ORDER BY a.actif DESC, a.fonction", [$id]
);

// Logo (potentiellement rafraîchi par assoc_sync_logo_ecole ci-dessus).
$logo_url = assoc_ecole_logo_url(assoc_val("SELECT logo FROM etablissement WHERE id=?", [$id]) ?? ($e['logo'] ?? null));

asso_haut('Fiche — ' . $e['nom']);
?>
<a href="<?= APP_URL ?>/association/index.php" class="small text-decoration-none">← Établissements</a>
<a href="<?= APP_URL ?>/association/etablissement_demarrage.php?id=<?= (int) $e['id'] ?>"
   class="btn btn-outline-light btn-sm ms-2"><i class="bi bi-list-check me-1"></i>Démarrage</a>
<?php if (est_superadmin_association()): ?>
  <a href="<?= APP_URL ?>/association/etablissement_modifier.php?id=<?= (int) $e['id'] ?>"
     class="btn btn-outline-light btn-sm ms-2"><i class="bi bi-pencil me-1"></i>Modifier</a>
  <a href="<?= APP_URL ?>/association/etablissement_desactiver.php?id=<?= (int) $e['id'] ?>"
     class="btn btn-outline-<?= $e['actif'] ? 'warning' : 'success' ?> btn-sm ms-2">
    <i class="bi bi-<?= $e['actif'] ? 'slash-circle' : 'check-circle' ?> me-1"></i><?= $e['actif'] ? 'Désactiver' : 'Réactiver' ?>
  </a>
  <?php if (!$e['actif'] && est_proprietaire_association()): ?>
    <a href="<?= APP_URL ?>/association/etablissement_supprimer.php?id=<?= (int) $e['id'] ?>"
       class="btn btn-outline-danger btn-sm ms-2"><i class="bi bi-trash3 me-1"></i>Supprimer</a>
  <?php endif; ?>
<?php endif; ?>

<div class="d-flex align-items-start gap-3 mt-2 mb-3">
  <?php if ($logo_url): ?>
  <div class="flex-shrink-0 d-flex align-items-center justify-content-center"
       style="width:64px;height:64px;border-radius:12px;overflow:hidden;background:#fff;border:1px solid var(--border)">
    <img src="<?= h($logo_url) ?>" alt="Logo <?= h($e['code']) ?>" style="max-width:100%;max-height:100%;object-fit:contain">
  </div>
  <?php endif; ?>
  <div class="d-flex flex-wrap align-items-center gap-2">
  <span class="badge badge-soft"><?= h($e['code']) ?></span>
  <?php if (!$e['actif']): ?><span class="badge bg-warning text-dark">Inactive</span><?php endif; ?>
  <?php if ($e['sigle']): ?><span class="text-muted2 small"><?= h($e['sigle']) ?></span><?php endif; ?>
  <?php if ($e['ville']): ?><span class="text-muted2 small"><i class="bi bi-geo-alt me-1"></i><?= h($e['ville']) ?></span><?php endif; ?>
  <span class="text-muted2 small"><i class="bi bi-database me-1"></i><?= h($e['db_name']) ?></span>
  <?php if ($e['sous_domaine']): ?><span class="text-muted2 small"><i class="bi bi-globe me-1"></i><?= h($e['sous_domaine']) ?></span><?php endif; ?>
  </div>
</div>

<?php if (!$acces): ?>
  <div class="alert alert-warning py-2 small">
    Vous n'avez pas d'accès à cet établissement — synthèse masquée.
    Un administrateur peut vous l'accorder (table <span class="font-monospace">membre_acces</span>).
  </div>
<?php else: ?>

  <?php
    // Écriture dans l'école : superadmin association, ou membre disposant
    // de membre_acces.plein_acces=1 sur cette école ($acces calculé plus haut).
    $peut_ecrire_ecole = est_superadmin_association() || !empty($acces['plein_acces']);
  ?>
  <?php if ($e['actif']): ?>
  <div class="d-flex flex-wrap gap-2 mb-3">
    <a href="<?= APP_URL ?>/association/entrer_ecole.php?id=<?= (int) $e['id'] ?>" class="btn btn-primary btn-sm">
      <i class="bi bi-box-arrow-in-right me-1"></i>Ouvrir en lecture seule
    </a>
    <?php if ($peut_ecrire_ecole): ?>
    <a href="<?= APP_URL ?>/association/entrer_ecole.php?id=<?= (int) $e['id'] ?>&mode=ecriture"
       class="btn btn-warning btn-sm"
       onclick="return confirm('Ouvrir « <?= h(addslashes($e['nom'])) ?> » en LECTURE / ÉCRITURE ?\n\nToute modification sera enregistrée dans la base de cette école.');">
      <i class="bi bi-pencil-square me-1"></i>Ouvrir en écriture
    </a>
    <?php endif; ?>
    <?php if (($e['type_enseignement'] ?? 'primaire') !== 'secondaire'): ?>
    <a href="<?= APP_URL ?>/association/niu/index.php?etab=<?= (int) $e['id'] ?>" class="btn btn-outline-light btn-sm">
      <i class="bi bi-person-vcard me-1"></i>NIU de l'école
    </a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($base_absente && est_superadmin_association()): ?>
    <div class="alert alert-warning d-flex flex-wrap align-items-center gap-2 py-2 small">
      <span>
        <i class="bi bi-database-exclamation me-1"></i>
        La base <span class="font-monospace"><?= h($e['db_name']) ?></span>
        <?= $base_etat['existe'] ? 'existe mais est vide' : "n'existe pas encore sur le serveur" ?> —
        l'école n'est pas utilisable.
      </span>
      <?php if (est_proprietaire_association()): ?>
        <a href="<?= APP_URL ?>/association/ecole_bd_creer.php?id=<?= (int) $e['id'] ?>" class="btn btn-primary btn-sm">
          <i class="bi bi-database-add me-1"></i>Créer la base
        </a>
      <?php else: ?>
        <span class="text-muted2">Le propriétaire de l'association doit la créer.</span>
      <?php endif; ?>
    </div>
  <?php elseif ($stats_err): ?>
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

<?php if (est_superadmin_association()): ?>
<div class="asso-card p-0 mb-3" style="border-color:var(--border)">
  <div class="px-3 py-2 small text-muted2 border-bottom d-flex align-items-center gap-2" style="border-color:var(--border)">
    <i class="bi bi-database-gear"></i>Base de données
    <span class="font-monospace text-muted2"><?= h($e['db_name']) ?></span>
  </div>
  <?php $eid = (int) $e['id']; $csrf = h(csrf_generer());
        // Propriétaire de l'association : seul habilité aux opérations
        // lourdes (créer / vider / importer / restaurer / supprimer).
        // Un administrateur « simple » (superadmin non propriétaire) ne
        // peut que sauvegarder / exporter.
        $prop = est_proprietaire_association(); ?>
  <div class="p-3">
    <?php if ($base_absente): ?>
      <?php if ($prop): ?>
      <div class="d-flex flex-wrap gap-2">
        <a href="<?= APP_URL ?>/association/ecole_bd_creer.php?id=<?= $eid ?>" class="btn btn-primary btn-sm">
          <i class="bi bi-database-add me-1"></i><?= $base_etat['existe'] ? 'Initialiser la base' : 'Créer la base' ?>
        </a>
        <?php if ($base_etat['existe']): ?>
          <a href="<?= APP_URL ?>/association/ecole_bd_import.php?id=<?= $eid ?>" class="btn btn-outline-warning btn-sm">
            <i class="bi bi-database-down me-1"></i>Importer un dump
          </a>
        <?php endif; ?>
      </div>
      <?php else: ?>
        <div class="small text-muted2"><i class="bi bi-info-circle me-1"></i>La base de cette école n'existe pas — seul le propriétaire de l'association peut la créer.</div>
      <?php endif; ?>
    <?php else: ?>
      <div class="mb-2">
        <div class="small text-muted2 mb-1"><i class="bi bi-shield-check me-1"></i>Sauvegarde</div>
        <div class="d-flex flex-wrap gap-2">
          <a href="<?= APP_URL ?>/association/ecole_bd_sauvegarder.php?id=<?= $eid ?>" class="btn btn-primary btn-sm">
            <i class="bi bi-clock me-1"></i>Sauvegarder maintenant
          </a>
          <a href="<?= APP_URL ?>/association/ecole_bd_export.php?id=<?= $eid ?>&format=zip&csrf=<?= $csrf ?>" class="btn btn-outline-light btn-sm">
            <i class="bi bi-file-earmark-zip me-1"></i>Exporter complet (.zip)
          </a>
          <a href="<?= APP_URL ?>/association/ecole_bd_export.php?id=<?= $eid ?>&gzip=1&csrf=<?= $csrf ?>" class="btn btn-outline-light btn-sm">
            <i class="bi bi-download me-1"></i>Base seule (.sql.gz)
          </a>
        </div>
      </div>
      <?php if ($prop): ?>
      <div>
        <div class="small text-muted2 mb-1"><i class="bi bi-arrow-counterclockwise me-1"></i>Restauration &amp; réinitialisation <span class="badge bg-warning text-dark">propriétaire</span></div>
        <div class="d-flex flex-wrap gap-2">
          <a href="<?= APP_URL ?>/association/ecole_bd_restaurer.php?id=<?= $eid ?>" class="btn btn-outline-warning btn-sm">
            <i class="bi bi-clock-history me-1"></i>Restaurer une sauvegarde
          </a>
          <a href="<?= APP_URL ?>/association/ecole_bd_import.php?id=<?= $eid ?>" class="btn btn-outline-warning btn-sm">
            <i class="bi bi-database-down me-1"></i>Importer un fichier
          </a>
          <a href="<?= APP_URL ?>/association/ecole_bd_vider.php?id=<?= $eid ?>" class="btn btn-outline-danger btn-sm">
            <i class="bi bi-eraser me-1"></i>Vider la base
          </a>
          <?php if (!$e['actif']): ?>
            <a href="<?= APP_URL ?>/association/etablissement_supprimer.php?id=<?= $eid ?>" class="btn btn-outline-danger btn-sm">
              <i class="bi bi-trash3 me-1"></i>Supprimer l'établissement
            </a>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    <?php endif; ?>
    <?php if ($prop && $e['actif'] && !$base_absente): ?>
      <div class="small text-muted2 mt-2">
        <i class="bi bi-info-circle me-1"></i>Restaurer, Importer et Vider exigent un établissement <strong>inactif</strong>
        (Modifier → décocher « actif »).
      </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="asso-card p-0">
  <div class="px-3 py-2 small text-muted2 border-bottom" style="border-color:var(--border)">
    Personnel affecté par l'association
  </div>
  <table class="table table-sm mb-0 align-middle" style="font-size:.83rem">
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
