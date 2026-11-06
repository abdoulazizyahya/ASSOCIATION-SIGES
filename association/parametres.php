<?php
// association/parametres.php — Réglages du site (18/10/2026).
//   • Page d'entrée : à l'ouverture du site (racine), afficher la page d'accueil
//     de l'association (vitrine) ou directement la page de connexion.
// Réglages stockés dans parametre_assoc (assoc_param / assoc_param_set,
// noyau/connexion_assoc.php) ; table créée au premier enregistrement.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../noyau/connexion.php';
require_once __DIR__ . '/../noyau/fonctions.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../noyau/pwa.php';
exiger_superadmin_association();

$msg = ''; $err = '';
$par = (string) (membre_connecte()['login'] ?? '');
// Application Android (02/11/2026) : version distribuée, fichier APK, « Nouveautés ».
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('op') === 'application') {
    csrf_verifier();
    try {
        $version = max(1, (int) post('apk_version'));
        assoc_param_set('apk_version', (string) $version, $par);
        if (!empty($_FILES['apk']['tmp_name']) && $_FILES['apk']['error'] === UPLOAD_ERR_OK) {
            if (strtolower(pathinfo($_FILES['apk']['name'], PATHINFO_EXTENSION)) !== 'apk') throw new RuntimeException('Le fichier doit être une APK (.apk).');
            if (file_get_contents($_FILES['apk']['tmp_name'], false, null, 0, 2) !== 'PK') throw new RuntimeException('Fichier APK invalide.');
            $dossier = __DIR__ . '/../assets/app';
            if (!is_dir($dossier)) mkdir($dossier, 0755, true);
            $nom_f = 'promeducam-v' . $version . '.apk';
            if (!move_uploaded_file($_FILES['apk']['tmp_name'], $dossier . '/' . $nom_f)) throw new RuntimeException('Dépôt du fichier impossible.');
            assoc_param_set('apk_fichier', $nom_f, $par);
        }
        $texte = trim((string) post('nouveautes_texte'));
        if ($texte !== trim((string) assoc_param('nouveautes_texte', ''))) {
            // Nouveau message : affiché une fois sur chaque appareil.
            assoc_param_set('nouveautes_texte', $texte, $par);
            assoc_param_set('nouveautes_version', (string) time(), $par);
            assoc_param_set('nouveautes_date', date('d/m/Y'), $par);
        }
        journaliser_action('reglage_application', null, 'APK v' . $version);
        $msg = 'Application enregistrée (version ' . $version . ').';
    } catch (\Throwable $e) {
        $err = 'Enregistrement impossible : ' . $e->getMessage();
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $page = post('page_entree') === 'connexion' ? 'connexion' : 'accueil';
    try {
        assoc_param_set('page_entree', $page, (string) (membre_connecte()['login'] ?? ''));
        journaliser_action('reglage_page_entree', null, $page);
        $msg = $page === 'connexion'
            ? 'Enregistré : le site s\'ouvre maintenant directement sur la page de connexion.'
            : 'Enregistré : le site s\'ouvre maintenant sur la page d\'accueil de l\'association.';
    } catch (\Throwable $e) {
        $err = 'Enregistrement impossible : ' . $e->getMessage();
    }
}
$page = page_entree_site();

asso_haut('Réglages du site');
?>
<?php if ($msg): ?><div class="alert alert-success py-2 small"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-warning py-2 small"><?= h($err) ?></div><?php endif; ?>

<div class="asso-card mb-3" style="max-width:720px">
  <h6 class="mb-1"><i class="bi bi-door-open me-1"></i>Page d'entrée du site</h6>
  <p class="small text-muted2 mb-3">Ce que voit un visiteur non connecté qui ouvre l'adresse du site
    (<span class="font-monospace"><?= h(APP_URL ?: '/') ?>/</span>). Une personne déjà connectée arrive toujours sur son tableau de bord.</p>
  <form method="post">
    <?= csrf_champ() ?>
    <div class="form-check mb-2">
      <input class="form-check-input" type="radio" name="page_entree" id="pe_accueil" value="accueil" <?= $page === 'accueil' ? 'checked' : '' ?>>
      <label class="form-check-label" for="pe_accueil">
        <strong>Page d'accueil de l'association</strong>
        <span class="d-block small text-muted2">Vitrine publique (présentation, écoles) avec le bouton « Se connecter ».</span>
      </label>
    </div>
    <div class="form-check mb-3">
      <input class="form-check-input" type="radio" name="page_entree" id="pe_connexion" value="connexion" <?= $page === 'connexion' ? 'checked' : '' ?>>
      <label class="form-check-label" for="pe_connexion">
        <strong>Page de connexion directement</strong>
        <span class="d-block small text-muted2">Le site s'ouvre sur le formulaire de connexion. La vitrine reste consultable à l'adresse
          <a href="<?= APP_URL ?>/?accueil" target="_blank" class="font-monospace"><?= h(APP_URL) ?>/?accueil</a>.</span>
      </label>
    </div>
    <button class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Enregistrer</button>
    <a href="<?= APP_URL ?>/" target="_blank" class="btn btn-outline-secondary btn-sm ms-1"><i class="bi bi-box-arrow-up-right me-1"></i>Tester l'ouverture du site</a>
  </form>
</div>
<div class="asso-card mb-3" style="max-width:720px">
  <h6 class="mb-1"><i class="bi bi-pc-display me-1"></i>Installer SIGES sur un autre ordinateur</h6>
  <p class="small text-muted2 mb-2">
    Zip « SIGES vierge » : tout le programme et ses bibliothèques, <strong>sans aucune donnée</strong>
    (ni école, ni élève, ni configuration de ce serveur). Sur l'autre ordinateur (WampServer) : copier le
    dossier <span class="font-monospace">SIGES</span> du zip dans <span class="font-monospace">C:\wamp64\www\</span>,
    puis ouvrir <span class="font-monospace">http://localhost/SIGES/installation/install.php</span>, qui vérifie PHP,
    les extensions et les modules Apache, puis crée l'école. Mode d'emploi détaillé dans le fichier
    <span class="font-monospace">LISEZMOI_INSTALLATION.txt</span> du zip.
  </p>
  <a href="<?= APP_URL ?>/association/telecharger_siges.php" class="btn btn-primary btn-sm">
    <i class="bi bi-download me-1"></i>Télécharger SIGES (installation vierge)
  </a>
  <span class="small text-muted2 ms-2">Préparation de quelques secondes (environ 1 900 fichiers).</span>
</div>

<div class="asso-card mb-3" style="max-width:720px">
  <h6 class="mb-1"><i class="bi bi-phone me-1"></i>Application Android</h6>
  <p class="small text-muted2 mb-3">
    L'application affiche le site en direct : les mises à jour de SIGES sont visibles sans rien réinstaller.
    Une nouvelle APK n'est nécessaire que si l'application elle-même change (icône, nom, adresse).
    Pour en distribuer une : augmentez la version <strong>avant</strong> de la fabriquer (PWABuilder), puis déposez le fichier ici —
    les téléphones qui ont une version plus ancienne verront un bandeau « Nouvelle version disponible ».
  </p>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_champ() ?>
    <input type="hidden" name="op" value="application">
    <div class="row g-2 mb-2">
      <div class="col-sm-4">
        <label class="form-label small">Version de l'APK distribuée</label>
        <input type="number" min="1" name="apk_version" class="form-control form-control-sm" value="<?= pwa_apk_version() ?>">
      </div>
      <div class="col-sm-8">
        <label class="form-label small">Fichier APK <?= pwa_apk_fichier() !== '' ? '(en ligne : <a href="' . h(APP_URL . '/assets/app/' . rawurlencode(pwa_apk_fichier())) . '">' . h(pwa_apk_fichier()) . '</a>)' : '(aucun pour l\'instant)' ?></label>
        <input type="file" name="apk" accept=".apk" class="form-control form-control-sm">
      </div>
    </div>
    <div class="mb-2">
      <label class="form-label small">Message « Nouveautés » <span class="text-muted2">(affiché une fois sur chaque appareil quand il change ; vide = aucun message)</span></label>
      <textarea name="nouveautes_texte" rows="5" class="form-control form-control-sm"
                placeholder="Ex. : Les bulletins de la section anglophone sont disponibles."><?= h((string) assoc_param('nouveautes_texte', '')) ?></textarea>
    </div>
    <div class="small text-muted2 mb-2">Adresse à donner à PWABuilder :
      <span class="font-monospace"><?= h((isset($_SERVER['HTTP_HOST']) ? 'https://' . $_SERVER['HTTP_HOST'] : '') . APP_URL) ?>/compte/login.php?source=application&amp;apk=<?= pwa_apk_version() ?></span></div>
    <button class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Enregistrer</button>
  </form>
</div>
<?php asso_bas(); ?>
