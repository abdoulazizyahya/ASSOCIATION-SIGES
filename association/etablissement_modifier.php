<?php
// association/etablissement_modifier.php — édition des champs d'annuaire
// d'un établissement (superadmin uniquement). Ne touche PAS `code` ni
// `db_name` (immuables — liés à la base MySQL de l'école) ni la clé de
// signature (générée par bd/assoc/generer_cles_verif_ecoles.php).
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../noyau/connexion.php';
require_once __DIR__ . '/../noyau/fonctions.php';
require_once __DIR__ . '/_layout.php';
exiger_superadmin_association();

$id = (int) ($_GET['id'] ?? 0);
$e  = $id ? assoc_one("SELECT * FROM etablissement WHERE id=?", [$id]) : null;
if (!$e) { asso_haut('Établissement introuvable'); asso_bas(); exit; }

$msg = ''; $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $nom     = trim($_POST['nom'] ?? '');
    $sigle   = trim($_POST['sigle'] ?? '') ?: null;
    $ville   = trim($_POST['ville'] ?? '') ?: null;
    $sous    = strtolower(trim($_POST['sous_domaine'] ?? '')) ?: null;
    $couleur = trim($_POST['couleur'] ?? '') ?: null;
    $vbu     = trim($_POST['verif_base_url'] ?? '') ?: null;
    $actif   = isset($_POST['actif']) ? 1 : 0;
    $niu_sig = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $_POST['niu_sigle'] ?? '')) ?: null;
    if ($niu_sig !== null) $niu_sig = substr($niu_sig, 0, 3);
    // Utilisateur MySQL de la base (créé au cPanel) : vide = identifiants
    // globaux ; mot de passe vide = inchangé. Testé avant enregistrement.
    $db_user  = trim($_POST['db_user'] ?? '');
    $db_pass  = (string) ($_POST['db_pass'] ?? '');
    $maj_bd   = $db_user !== (string) ($e['db_user'] ?? '') || $db_pass !== '';
    if ($maj_bd && $db_user !== '' && $db_pass === '' && (string) ($e['db_user'] ?? '') === '') {
        $err_bd = "Saisissez le mot de passe MySQL de l'utilisateur « $db_user ».";
    } elseif ($maj_bd && $db_user !== '' && !preg_match('/^[A-Za-z0-9_.@-]{1,80}$/', $db_user)) {
        $err_bd = "Utilisateur MySQL invalide.";
    } elseif ($maj_bd) {
        try {
            $p_test = $db_user === '' ? DB_PASS
                    : ($db_pass !== '' ? $db_pass : ecole_identifiants_bd($e['db_name'])[1]);
            $err_bd = tester_identifiants_bd($e['db_name'], $db_user ?: DB_USER, $p_test);
        } catch (\Throwable $ex) {
            $err_bd = "Ancien mot de passe illisible : saisissez le mot de passe MySQL.";
        }
    } else {
        $err_bd = null;
    }

    if ($err_bd !== null) {
        $err = $err_bd;
    } elseif ($nom === '') {
        $err = "Le nom est obligatoire.";
    } elseif ($sous !== null && !preg_match('/^[a-z0-9-]{2,63}$/', $sous)) {
        $err = "Sous-domaine invalide : lettres minuscules, chiffres et tirets (2 à 63).";
    } elseif ($couleur !== null && !preg_match('/^#[0-9a-fA-F]{6}$/', $couleur)) {
        $err = "Couleur invalide : format #RRGGBB attendu.";
    } elseif ($niu_sig !== null && strlen($niu_sig) !== 3) {
        $err = "Le sigle NIU doit faire exactement 3 caractères (A–Z, 0–9).";
    } elseif ($vbu !== null && !preg_match('~^https?://[^\s]+$~i', $vbu)) {
        $err = "URL de vérification invalide (doit commencer par http:// ou https://).";
    } elseif ($sous !== null && assoc_val("SELECT COUNT(*) FROM etablissement WHERE sous_domaine=? AND id<>?", [$sous, $id])) {
        $err = "Ce sous-domaine est déjà utilisé par un autre établissement.";
    } else {
        $cols = "nom=?, sigle=?, ville=?, sous_domaine=?, couleur=?, verif_base_url=?, actif=?";
        $vals = [$nom, $sigle, $ville, $sous, $couleur, $vbu, $actif];
        if (function_exists('assoc_niu_config_dispo') && assoc_niu_config_dispo()) {
            $cols .= ", niu_sigle=?";
            $vals[] = $niu_sig;
        }
        if ($maj_bd) {
            annuaire_assurer_identifiants_bd();
            $cols .= ", db_user=?, db_pass_chiffre=?";
            $vals[] = $db_user ?: null;
            $vals[] = $db_user === '' ? null
                    : ($db_pass !== '' ? ecole_bd_chiffrer($db_pass) : $e['db_pass_chiffre']);
        }
        $vals[] = $id;
        assoc_exec("UPDATE etablissement SET $cols WHERE id=?", $vals);
        journaliser_action('etablissement_modifie', $id, $nom);
        if (function_exists('regenerer_portail_accueil_best_effort')) {
            regenerer_portail_accueil_best_effort();   // rafraîchit promeducamsiges.html
        }
        $e = assoc_one("SELECT * FROM etablissement WHERE id=?", [$id]);
        $msg = "Établissement mis à jour.";
    }
}

asso_haut('Modifier — ' . $e['nom']);
?>
<a href="<?= APP_URL ?>/association/etablissement.php?id=<?= (int) $e['id'] ?>" class="small text-decoration-none">← Fiche</a>
<?php if (!$e['actif']): ?>
  <a href="<?= APP_URL ?>/association/etablissement_supprimer.php?id=<?= (int) $e['id'] ?>"
     class="btn btn-outline-danger btn-sm ms-2"><i class="bi bi-trash3 me-1"></i>Supprimer cet établissement</a>
<?php endif; ?>
<?php if ($msg): ?><div class="alert alert-success py-2 small mt-2"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-warning py-2 small mt-2"><?= h($err) ?></div><?php endif; ?>

<div class="asso-card mt-2" style="max-width:620px">
  <form method="post" class="row g-3">
    <input type="hidden" name="csrf" value="<?= h(csrf_generer()) ?>">

    <div class="col-12 small text-muted2">
      Code <span class="font-monospace"><?= h($e['code']) ?></span> ·
      base <span class="font-monospace"><?= h($e['db_name']) ?></span>
      <em>(non modifiables)</em>
    </div>

    <div class="col-12">
      <label class="form-label small fw-bold">Nom de l'établissement *</label>
      <input type="text" name="nom" class="form-control form-control-sm" required value="<?= h($e['nom']) ?>">
    </div>
    <div class="col-6">
      <label class="form-label small">Sigle</label>
      <input type="text" name="sigle" class="form-control form-control-sm" value="<?= h($e['sigle'] ?? '') ?>">
    </div>
    <div class="col-6">
      <label class="form-label small">Ville</label>
      <input type="text" name="ville" class="form-control form-control-sm" value="<?= h($e['ville'] ?? '') ?>">
    </div>
    <?php if (function_exists('assoc_niu_config_dispo') && assoc_niu_config_dispo()): ?>
    <div class="col-6">
      <label class="form-label small">Sigle NIU <span class="text-muted2">(3 caractères)</span></label>
      <input type="text" name="niu_sigle" maxlength="3" class="form-control form-control-sm font-monospace text-uppercase"
             value="<?= h(function_exists('assoc_niu_sigle') ? assoc_niu_sigle($e) : ($e['niu_sigle'] ?? '')) ?>">
      <div class="form-text small text-muted2">Code de l'école dans le NIU (<span class="font-monospace"><?= h((defined('NIU_PREFIXE') ? NIU_PREFIXE : 'PMC')) ?>+sigle+année+n°</span>). Ne pas changer après génération.</div>
    </div>
    <?php endif; ?>
    <div class="col-6">
      <label class="form-label small">Sous-domaine <span class="text-muted2">(production : ecole.assoc.cm)</span></label>
      <input type="text" name="sous_domaine" class="form-control form-control-sm" value="<?= h($e['sous_domaine'] ?? '') ?>"
             placeholder="ecole1">
    </div>
    <div class="col-6">
      <label class="form-label small">Couleur d'accent</label>
      <input type="text" name="couleur" class="form-control form-control-sm" value="<?= h($e['couleur'] ?? '') ?>"
             placeholder="#1e4fd8">
    </div>
    <div class="col-12">
      <label class="form-label small">URL publique de vérification des QR
        <span class="text-muted2">(laisser vide en LAN — détection automatique)</span></label>
      <input type="text" name="verif_base_url" class="form-control form-control-sm" value="<?= h($e['verif_base_url'] ?? '') ?>"
             placeholder="https://promeducam.beero.cm">
    </div>
    <div class="col-6">
      <label class="form-label small">Utilisateur MySQL de la base</label>
      <input type="text" name="db_user" class="form-control form-control-sm font-monospace" maxlength="80"
             value="<?= h($e['db_user'] ?? '') ?>" placeholder="<?= h(DB_USER) ?> (global)" autocomplete="off">
      <div class="form-text small text-muted2">Celui créé dans le cPanel · vide = utilisateur global</div>
    </div>
    <div class="col-6">
      <label class="form-label small">Mot de passe MySQL</label>
      <input type="password" name="db_pass" class="form-control form-control-sm" autocomplete="new-password"
             placeholder="<?= ($e['db_user'] ?? '') !== '' ? 'inchangé' : '' ?>">
      <div class="form-text small text-muted2">Laisser vide pour ne pas le changer · testé avant enregistrement</div>
    </div>
    <div class="col-12">
      <div class="form-check">
        <input class="form-check-input" type="checkbox" name="actif" id="actif" <?= $e['actif'] ? 'checked' : '' ?>>
        <label class="form-check-label small" for="actif">Établissement actif
          <span class="text-muted2">(décoché : n'apparaît plus dans le choix d'école ni la connexion)</span></label>
      </div>
    </div>

    <div class="col-12">
      <button class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Enregistrer</button>
      <span class="small text-muted2 ms-2">
        Logo et signature se règlent DANS l'école (Configurations). Clé de signature :
        <span class="font-monospace">bd/assoc/generer_cles_verif_ecoles.php</span>.
      </span>
    </div>
  </form>
</div>
<?php asso_bas();
