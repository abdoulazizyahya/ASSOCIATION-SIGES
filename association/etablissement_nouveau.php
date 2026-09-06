<?php
// association/etablissement_nouveau.php — un membre crée un nouvel
// établissement : base école dédiée + inscription à l'annuaire.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
require_once __DIR__ . '/_layout.php';
exiger_superadmin_association();

$msg = ''; $err = ''; $ok = false;
$val = ['code' => '', 'nom' => '', 'nom_en' => '', 'sigle' => '', 'ville' => '', 'sous_domaine' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    foreach ($val as $k => $_) $val[$k] = trim($_POST[$k] ?? '');
    $val['code'] = strtoupper($val['code']);
    $val['sous_domaine'] = strtolower($val['sous_domaine']);

    $r = creer_etablissement($val + ['par' => membre_connecte()['login'] ?? '?']);
    if ($r['ok']) {
        journaliser_action('etablissement_creation', $r['id'], $val['code'] . ' — ' . $val['nom']);
        $ok = true;
        $msg = $r['message'];
        $val = ['code' => '', 'nom' => '', 'nom_en' => '', 'sigle' => '', 'ville' => '', 'sous_domaine' => ''];
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
    Ensuite : créer un compte <strong>DIRECTEUR</strong> via
    <a href="<?= APP_URL ?>/association/personnel/affecter.php">Personnel → Affecter</a>.
  </p>
  <form method="post" class="row g-2">
    <input type="hidden" name="csrf" value="<?= h(csrf_generer()) ?>">

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
<?php asso_bas();
