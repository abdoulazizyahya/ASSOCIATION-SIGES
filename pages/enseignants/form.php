<?php
// pages/enseignants/form.php — Création/modification d'un membre du
// personnel (VRAI schéma `enseignant`, remplace l'ancien form.php cassé —
// rôles ADMIN/PROVISEUR/CENSEUR inexistants ici et ~30 colonnes qui
// n'existent pas sur la table réelle, voir prompt de continuité). Pattern
// repris de pages/eleves/form.php (cascade région/département/arrondissement,
// mêmes conventions), adapté au personnel : matricule_ens est un simple
// AUTO_INCREMENT (pas de génération façon Mat_elv), mat_ens est un champ
// libre optionnel (matricule administratif officiel, pas l'identifiant
// interne).
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/../../paie_fonctions.php';
exiger_role(['DIRECTEUR']);

$mat = (int) ($_GET['mat'] ?? 0);
$ens = $mat ? db_one("SELECT * FROM enseignant WHERE matricule_ens=?", [$mat]) : null;
if ($mat && !$ens) { flash_set('erreur', 'Membre du personnel introuvable.'); rediriger('pages/enseignants/liste.php'); }

$grades = grille_salariale();

// Cascade région → département → arrondissement (assets/js/lieu-cascade.js,
// même widget que pages/eleves/form.php — générique) : `arrondissement_ens`
// est traité exactement comme `eleve.id_arrondissement` (FK réelle vers
// arrondissement.code_arrond, ajoutée par la migration v34 — corrige au
// passage l'ancien pages/enseignants/voir.php qui joignait à tort
// arrondissement_ens directement sur `departement`, jamais sur `arrondissement`).
$regions = db_all("SELECT id_region, intitule_region FROM region ORDER BY intitule_region");
$lieu_edit = null;
if ($ens && $ens['arrondissement_ens']) {
    $lieu_edit = db_one(
        "SELECT a.code_arrond, d.code_depart, r.id_region
         FROM arrondissement a
         JOIN departement d ON d.code_depart = a.code_depart
         JOIN region r ON r.id_region = d.code_region
         WHERE a.code_arrond = ?",
        [$ens['arrondissement_ens']]
    );
}

$ve = fn(string $k) => $ens[$k] ?? '';

$es_partiel = isset($_GET['partiel']);
if (!$es_partiel) {
    $titre_page = $ens ? 'Modifier un membre du personnel' : 'Nouveau membre du personnel';
    require_once __DIR__ . '/../../layout/header.php';
} else {
    header('Content-Type: text/html; charset=utf-8');
}
?>

<div id="enseignant-form-zone">

<div class="d-flex align-items-center gap-2 mb-3">
  <a href="<?= APP_URL ?>/pages/enseignants/liste.php" class="btn btn-sm btn-light">
    <i class="bi bi-arrow-left"></i>
  </a>
  <div>
    <h4 class="mb-0" style="font-size:1.05rem;font-weight:700"><?= h($titre_page) ?></h4>
    <div class="sub"><?= $ens ? 'Matricule interne ' . h((string) $ens['matricule_ens']) : 'Le matricule interne sera généré automatiquement' ?></div>
  </div>
</div>

<form method="post" action="<?= APP_URL ?>/pages/enseignants/save.php" data-ajax-post-form>
  <?= csrf_champ() ?>
  <input type="hidden" name="mat" value="<?= $ens ? (int) $ens['matricule_ens'] : '' ?>">

  <div class="row g-2">
    <div class="col-lg-8">
      <div class="card mb-2">
        <div class="card-body">
          <div class="section-titre"><i class="bi bi-person me-1"></i>Identité</div>
          <div class="row g-compact">
            <div class="col-md-3">
              <label class="form-label">Civilité</label>
              <select name="civilite" class="form-select">
                <?php foreach (['M.', 'Mme', 'Mlle'] as $c): ?>
                  <option value="<?= $c ?>" <?= $ve('civilite_ens') === $c ? 'selected' : '' ?>><?= $c ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-5">
              <label class="form-label">Nom <span class="text-danger">*</span></label>
              <input type="text" name="nom" class="form-control" required value="<?= h($ve('nom_ens')) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Prénom(s)</label>
              <input type="text" name="prenom" class="form-control" value="<?= h($ve('prenom_ens')) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Sexe</label>
              <select name="sexe" class="form-select">
                <option value="Masculin" <?= $ve('sexe_ens') !== 'Feminin' ? 'selected' : '' ?>>Masculin</option>
                <option value="Feminin" <?= $ve('sexe_ens') === 'Feminin' ? 'selected' : '' ?>>Féminin</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Date de naissance</label>
              <input type="date" name="date_naiss" class="form-control" value="<?= h($ve('date_naiss_ens')) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Lieu de naissance</label>
              <input type="text" name="lieu_naiss" class="form-control" value="<?= h($ve('lieu_ens')) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">CNI</label>
              <input type="text" name="num_cni" class="form-control" value="<?= h($ve('num_cni')) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Situation matrimoniale</label>
              <input type="text" name="situation" class="form-control" value="<?= h($ve('situation_ens')) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Enfants à charge</label>
              <input type="number" name="nb_enfants" class="form-control" min="0" value="<?= h($ve('nb_enfants') ?: '0') ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Autres pers. à charge</label>
              <input type="number" name="nb_pers_charge" class="form-control" min="0" value="<?= h($ve('nb_pers_charge') ?: '0') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Région d'origine</label>
              <select id="sel_region" class="form-select">
                <option value="">— Choisir —</option>
                <?php foreach ($regions as $r): ?>
                  <option value="<?= (int) $r['id_region'] ?>"><?= h($r['intitule_region']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Département d'origine</label>
              <select id="sel_departement" class="form-select" disabled>
                <option value="">— Choisir une région d'abord —</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Arrondissement d'origine</label>
              <select id="sel_arrondissement" name="id_arrondissement" class="form-select" disabled>
                <option value="">— Choisir un département d'abord —</option>
              </select>
            </div>
            <div class="col-md-12">
              <input type="text" id="inp_lieu_libre" name="lieu_libre" class="form-control mt-1"
                     style="display:none" placeholder="Préciser l'arrondissement (non répertorié dans la liste officielle)">
            </div>
          </div>
        </div>
      </div>

      <div class="card mb-2">
        <div class="card-body">
          <div class="section-titre"><i class="bi bi-telephone me-1"></i>Contact</div>
          <div class="row g-compact">
            <div class="col-md-4">
              <label class="form-label">Téléphone</label>
              <input type="text" name="tel" class="form-control" value="<?= h($ve('tel_ens')) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Email</label>
              <input type="email" name="mail" class="form-control" value="<?= h($ve('mail_ens')) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Matricule administratif</label>
              <input type="text" name="mat_ens" class="form-control" value="<?= h($ve('mat_ens')) ?>" placeholder="Référence officielle (optionnel)">
            </div>
            <div class="col-md-6">
              <label class="form-label">Matricule CNPS</label>
              <input type="text" name="matricule_cnps" class="form-control" value="<?= h($ve('matricule_cnps')) ?>" placeholder="Optionnel — affiché sur le bulletin de paie">
            </div>
            <div class="col-md-12">
              <label class="form-label">Adresse</label>
              <input type="text" name="adresse" class="form-control" value="<?= h($ve('adresse_ens')) ?>">
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-body">
          <div class="section-titre"><i class="bi bi-cash-coin me-1"></i>Poste &amp; paie</div>
          <div class="row g-compact">
            <div class="col-md-4">
              <label class="form-label">Fonction</label>
              <select name="fonction" class="form-select">
                <?php foreach (db_all("SELECT id_fonction FROM fonction ORDER BY id_fonction") as $f): ?>
                  <option value="<?= h($f['id_fonction']) ?>" <?= $ve('id_fonction') === $f['id_fonction'] ? 'selected' : '' ?>>
                    <?= h(libelle_role($f['id_fonction'])) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Grade <span class="badge-code">Grille salariale</span></label>
              <select name="id_grade" class="form-select">
                <option value="">— Non défini —</option>
                <?php foreach ($grades as $g): ?>
                  <option value="<?= h($g['code_grade']) ?>" <?= $ve('id_grade') === $g['code_grade'] ? 'selected' : '' ?>>
                    <?= h($g['libelle_grade']) ?> (<?= number_format((float) $g['salaire_base'], 0, ',', ' ') ?> F)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Indice / Position grille</label>
              <input type="text" name="indice_grille" class="form-control" value="<?= h($ve('indice_grille')) ?>" placeholder="ex. 9/ A /227204">
            </div>
            <div class="col-md-4">
              <label class="form-label">Date de recrutement</label>
              <input type="date" name="date_recrutement" class="form-control" value="<?= h($ve('date_recrutement')) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Mode de paiement</label>
              <select name="mode_paiement" class="form-select">
                <option value="">— Non précisé —</option>
                <?php foreach (['Espèces', 'Virement bancaire', 'Mobile Money'] as $m): ?>
                  <option value="<?= $m ?>" <?= $ve('mode_paiement') === $m ? 'selected' : '' ?>><?= $m ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Banque</label>
              <input type="text" name="nom_banque" class="form-control" value="<?= h($ve('nom_banque')) ?>" placeholder="ex. CREDIT LYONNAIS-YAOUNDE">
            </div>
            <div class="col-md-8">
              <label class="form-label">Compte bancaire / Mobile Money</label>
              <input type="text" name="compte_bancaire" class="form-control" value="<?= h($ve('compte_bancaire')) ?>">
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card">
        <div class="card-body">
          <div class="section-titre"><i class="bi bi-info-circle me-1"></i>Statut</div>
          <div class="row g-compact">
            <div class="col-12">
              <label class="form-label">Statut d'activité</label>
              <select name="statut_ens" class="form-select">
                <option value="actif" <?= ($ve('statut_ens') ?: 'actif') === 'actif' ? 'selected' : '' ?>>Actif</option>
                <option value="inactif" <?= $ve('statut_ens') === 'inactif' ? 'selected' : '' ?>>Inactif</option>
              </select>
            </div>
          </div>
          <?php if ($ens): ?>
          <hr>
          <div class="d-grid gap-1">
            <a href="<?= APP_URL ?>/pages/enseignants/contrats.php?mat=<?= (int) $ens['matricule_ens'] ?>" class="btn btn-outline-secondary btn-sm">
              <i class="bi bi-file-earmark-text me-1"></i>Contrats
            </a>
            <a href="<?= APP_URL ?>/pages/enseignants/conges.php?mat=<?= (int) $ens['matricule_ens'] ?>" class="btn btn-outline-secondary btn-sm">
              <i class="bi bi-calendar-x me-1"></i>Congés / Absences
            </a>
            <a href="<?= APP_URL ?>/pages/paie/avances.php?mat=<?= (int) $ens['matricule_ens'] ?>" class="btn btn-outline-secondary btn-sm">
              <i class="bi bi-cash me-1"></i>Avances sur salaire
            </a>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <div class="mt-3 d-flex gap-2">
    <button class="btn btn-primary btn-sm px-4"><i class="bi bi-check-lg me-1"></i>Enregistrer</button>
    <a href="<?= APP_URL ?>/pages/enseignants/liste.php" class="btn btn-light btn-sm">Annuler</a>
  </div>
</form>

<script src="<?= APP_URL ?>/assets/js/lieu-cascade.js"></script>
<script>
initLieuCascade({
    selRegion:         document.getElementById('sel_region'),
    selDepartement:    document.getElementById('sel_departement'),
    selArrondissement: document.getElementById('sel_arrondissement'),
    inputLibre:        document.getElementById('inp_lieu_libre'),
    appUrl:            '<?= APP_URL ?>',
    valeurs: {
        id_region:         <?= json_encode($lieu_edit['id_region'] ?? null) ?>,
        id_departement:    <?= json_encode($lieu_edit['code_depart'] ?? null) ?>,
        id_arrondissement: <?= json_encode($ens['arrondissement_ens'] ?? null) ?>,
        texte_libre:       <?= json_encode($ve('lieu_origine_libre')) ?>
    }
});
</script>

</div><!-- /#enseignant-form-zone -->
<?php if ($es_partiel) exit; // rien de plus dans une réponse AJAX partielle. ?>

<?php
$ajax_zone_id = 'enseignant-form-zone'; // voir layout/footer.php — initAjaxZone() y est appelé
require_once __DIR__ . '/../../layout/footer.php';
