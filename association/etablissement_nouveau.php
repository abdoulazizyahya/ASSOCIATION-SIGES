<?php
// association/etablissement_nouveau.php — un membre crée un nouvel
// établissement : base école dédiée + inscription à l'annuaire.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
require_once __DIR__ . '/_layout.php';
exiger_superadmin_association();

$msg = ''; $err = ''; $ok = false; $comptes = [];
$val = ['code' => '', 'nom' => '', 'nom_en' => '', 'sigle' => '', 'ville' => '', 'sous_domaine' => '', 'type_enseignement' => 'primaire', 'statut' => 'public'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    foreach ($val as $k => $_) $val[$k] = trim($_POST[$k] ?? '');
    $val['code'] = strtoupper($val['code']);
    $val['sous_domaine'] = strtolower($val['sous_domaine']);
    if (!in_array($val['type_enseignement'], ['primaire', 'secondaire'], true)) $val['type_enseignement'] = 'primaire';
    if (!in_array($val['statut'], ['public', 'prive'], true)) $val['statut'] = 'public';

    $r = creer_etablissement($val + ['par' => membre_connecte()['login'] ?? '?']);
    if ($r['ok']) {
        journaliser_action('etablissement_creation', $r['id'], $val['code'] . ' — ' . $val['nom']);
        $ok = true;
        $msg = $r['message'];
        $comptes = $r['comptes'] ?? [];
        $val = ['code' => '', 'nom' => '', 'nom_en' => '', 'sigle' => '', 'ville' => '', 'sous_domaine' => '', 'type_enseignement' => 'primaire', 'statut' => 'public'];
    } else {
        $err = $r['message'];
    }
}

asso_haut('Nouvel établissement');
?>
<a href="<?= APP_URL ?>/association/index.php" class="small text-decoration-none">← Établissements</a>

<?php if ($msg): ?>
  <div class="alert alert-success py-2 small mt-2"><?= h($msg) ?></div>
<?php endif; ?>
<?php if ($comptes): ?>
  <div class="alert alert-success py-2 small mt-2">
    <strong>Comptes créés</strong> (mot de passe = identifiant) — à transmettre au personnel puis à changer depuis Sécurité :
    <table class="table table-sm mb-0 mt-2" style="font-size:.82rem">
      <thead><tr><th>Rôle</th><th>Identifiant / mot de passe</th></tr></thead>
      <tbody>
        <?php foreach ($comptes as $c): ?>
        <tr>
          <td><?= h($c['role']) ?></td>
          <td class="font-monospace"><?= h($c['login']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
<?php if ($err): ?>
  <div class="alert alert-warning py-2 small mt-2"><?= h($err) ?></div>
<?php endif; ?>

<div class="asso-card mt-2" style="max-width:560px">
  <?php $pool_mode = defined('ECOLE_POOL_ACTIF') && ECOLE_POOL_ACTIF; ?>
  <p class="text-muted2 small mb-3">
    <?php if ($pool_mode): ?>
      Consomme une base vide du <strong>pool</strong> pré-créé (hébergement mutualisé),
      y installe le schéma de référence à jour, puis inscrit l'école à l'annuaire.
    <?php else: ?>
      Crée une base de données dédiée <span class="font-monospace">promeducam_&lt;nom simplifié&gt;</span>,
      y installe le schéma de référence à jour, puis inscrit l'école à l'annuaire.
    <?php endif; ?>
    Crée aussi automatiquement les 3 comptes par défaut (FONDATEUR, DIRECTEUR, FINANCIER — affichés
    ci-dessus une fois l'établissement créé).
  </p>
  <form method="post" class="row g-2">
    <input type="hidden" name="csrf" value="<?= h(csrf_generer()) ?>">

    <div class="col-12">
      <label class="form-label small d-block">Type d'enseignement *</label>
      <div class="btn-group w-100" role="group">
        <input type="radio" class="btn-check" name="type_enseignement" id="type_primaire" value="primaire"
               <?= $val['type_enseignement'] !== 'secondaire' ? 'checked' : '' ?>>
        <label class="btn btn-outline-primary btn-sm" for="type_primaire"><i class="bi bi-mortarboard me-1"></i>Primaire</label>

        <input type="radio" class="btn-check" name="type_enseignement" id="type_secondaire" value="secondaire"
               <?= $val['type_enseignement'] === 'secondaire' ? 'checked' : '' ?>>
        <label class="btn btn-outline-primary btn-sm" for="type_secondaire"><i class="bi bi-mortarboard-fill me-1"></i>Secondaire</label>
      </div>
      <div class="form-text small text-muted2">Figé définitivement après création.</div>
    </div>

    <div class="col-12" id="bloc_statut" style="<?= $val['type_enseignement'] === 'secondaire' ? '' : 'display:none' ?>">
      <label class="form-label small d-block">Statut *</label>
      <div class="btn-group w-100" role="group">
        <input type="radio" class="btn-check" name="statut" id="statut_public" value="public"
               <?= $val['statut'] !== 'prive' ? 'checked' : '' ?>>
        <label class="btn btn-outline-primary btn-sm" for="statut_public">Public</label>

        <input type="radio" class="btn-check" name="statut" id="statut_prive" value="prive"
               <?= $val['statut'] === 'prive' ? 'checked' : '' ?>>
        <label class="btn btn-outline-primary btn-sm" for="statut_prive">Privé</label>
      </div>
      <div class="form-text small text-muted2">
        Détermine le libellé affiché (Proviseur/Principal) et lequel des deux modules Paiements
        (public ou privé) est visible — jamais les deux à la fois.
      </div>
    </div>

    <div class="col-4">
      <label class="form-label small">Code *</label>
      <input name="code" class="form-control form-control-sm text-uppercase font-monospace"
             value="<?= h($val['code']) ?>" maxlength="10" pattern="[A-Za-z0-9]{2,10}"
             placeholder="EC3" required>
      <div class="form-text small text-muted2">2–10 · A–Z 0–9</div>
    </div>
    <div class="col-8">
      <label class="form-label small">Sigle</label>
      <input name="sigle" class="form-control form-control-sm" value="<?= h($val['sigle']) ?>"
             maxlength="50" placeholder="ex. GSBP">
    </div>

    <div class="col-12">
      <label class="form-label small">Nom de l'établissement (FR) *</label>
      <input name="nom" class="form-control form-control-sm" value="<?= h($val['nom']) ?>"
             maxlength="150" required>
    </div>
    <div class="col-12">
      <label class="form-label small">Nom (EN)</label>
      <input name="nom_en" class="form-control form-control-sm" value="<?= h($val['nom_en']) ?>"
             maxlength="150">
    </div>

    <div class="col-6">
      <label class="form-label small">Ville</label>
      <input name="ville" class="form-control form-control-sm" value="<?= h($val['ville']) ?>"
             maxlength="100">
    </div>
    <?php if (!$pool_mode): ?>
    <div class="col-6">
      <label class="form-label small">Sous-domaine</label>
      <input name="sous_domaine" class="form-control form-control-sm text-lowercase"
             value="<?= h($val['sous_domaine']) ?>" maxlength="63" pattern="[A-Za-z0-9-]{2,63}"
             placeholder="ecole3">
      <div class="form-text small text-muted2">production uniquement · optionnel</div>
    </div>
    <?php endif; ?>

    <div class="col-12 mt-3">
      <button class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Créer l'établissement</button>
    </div>
  </form>
</div>
<script>
  document.querySelectorAll('input[name="type_enseignement"]').forEach(function (r) {
    r.addEventListener('change', function () {
      document.getElementById('bloc_statut').style.display = document.getElementById('type_secondaire').checked ? '' : 'none';
    });
  });
</script>
<?php asso_bas();
