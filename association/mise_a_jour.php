<?php
// association/mise_a_jour.php — Mise à jour du code SIGES depuis le portail,
// sans FTP (16/11/2026, demande du propriétaire). Propriétaire seulement.
//  1. envoi du zip construit par deploy/outils/construire_zip.php ;
//  2. contrôle (chemins, empreintes, SIGNATURE du propriétaire) + aperçu ;
//  3. installation après saisie du mot de passe : sauvegarde des fichiers
//     remplacés, écriture, retour arrière automatique en cas d'échec ;
//  4. historique avec bouton « Annuler » (remet les fichiers d'avant).
// Les migrations des écoles restent un clic sur la page Migrations.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../noyau/connexion.php';
require_once __DIR__ . '/../noyau/fonctions.php';
require_once __DIR__ . '/../bd/lib/mise_a_jour.php';
require_once __DIR__ . '/_layout.php';
exiger_proprietaire_association();

$attente_dir = maj_dossier() . '/maj_attente';
$jeton = (string) ($_SESSION['maj_attente'] ?? '');
$attente = preg_match('/^[0-9a-f]{32}$/', $jeton) && is_file("$attente_dir/$jeton.zip") ? "$attente_dir/$jeton.zip" : null;
$err = '';

function _maj_mdp_ok(string $mdp): bool {
    $m = membre_connecte();
    $row = assoc_one("SELECT pwd_hash FROM membre WHERE id=?", [$m['id'] ?? 0]);
    return $mdp !== '' && $row && password_verify($mdp, $row['pwd_hash']);
}
function _maj_vider_attente(string $dir): void {
    foreach (glob("$dir/*.zip") ?: [] as $f) @unlink($f);
    unset($_SESSION['maj_attente']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $op = (string) ($_POST['op'] ?? '');

    if ($op === 'envoyer') {
        $f = $_FILES['zip'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $err = ($f['error'] ?? 0) === UPLOAD_ERR_INI_SIZE || ($f['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE
                ? 'Zip trop gros pour le serveur (limite : ' . ini_get('upload_max_filesize') . ').'
                : 'Aucun fichier reçu.';
        } elseif (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'zip') {
            $err = 'Le fichier doit être un .zip.';
        } else {
            _maj_vider_attente($attente_dir);
            if (!is_dir($attente_dir)) @mkdir($attente_dir, 0700, true);
            $jeton = bin2hex(random_bytes(16));
            if (!move_uploaded_file($f['tmp_name'], "$attente_dir/$jeton.zip")) {
                $err = "Impossible d'enregistrer le zip dans bd/sauvegardes/ (droits d'écriture ?).";
            } else {
                $_SESSION['maj_attente'] = $jeton;
                $_SESSION['maj_attente_nom'] = mb_substr(basename($f['name']), 0, 120);
                rediriger('association/mise_a_jour.php');
            }
        }
    } elseif ($op === 'abandonner') {
        _maj_vider_attente($attente_dir);
        rediriger('association/mise_a_jour.php');
    } elseif ($op === 'installer' && $attente) {
        if (!_maj_mdp_ok((string) ($_POST['mdp'] ?? ''))) {
            $err = 'Mot de passe incorrect.';
        } else {
            $m = membre_connecte();
            $r = maj_installer($attente, trim(($m['prenom'] ?? '') . ' ' . ($m['nom'] ?? '')));
            journaliser_action($r['ok'] ? 'maj_installee' : 'maj_echec', null, ($_SESSION['maj_attente_nom'] ?? '') . ' — ' . $r['message']);
            if ($r['ok']) {
                _maj_vider_attente($attente_dir);
                flash_set('succes', 'Mise à jour installée : ' . $r['message'] . ' Pensez à passer par Migrations si de nouvelles migrations sont arrivées.');
                rediriger('association/mise_a_jour.php');
            }
            $err = $r['message'];
        }
    } elseif ($op === 'annuler') {
        if (!_maj_mdp_ok((string) ($_POST['mdp'] ?? ''))) {
            $err = 'Mot de passe incorrect.';
        } else {
            $nom = (string) ($_POST['sauvegarde'] ?? '');
            // Seule la dernière mise à jour encore active : on revient en arrière dans l'ordre.
            $actives = array_values(array_filter(maj_historique(), fn($h) => !$h['annulee']));
            $r = ($actives[0]['nom'] ?? null) === $nom ? maj_annuler($nom)
               : ['ok' => false, 'message' => "Annulez d'abord les mises à jour plus récentes."];
            journaliser_action($r['ok'] ? 'maj_annulee' : 'maj_annulation_echec', null, $nom . ' — ' . $r['message']);
            if ($r['ok']) { flash_set('succes', $r['message']); rediriger('association/mise_a_jour.php'); }
            $err = $r['message'];
        }
    }
}

$an = $attente ? maj_analyser($attente) : null;
$cle_presente = is_file(maj_cle_publique_chemin());
$historique = maj_historique();
$compte = $an ? array_count_values($an['fichiers']) : [];
$csrf = csrf_generer();

asso_haut('Mise à jour de SIGES');
?>
<p class="small text-muted2">
  Installe une nouvelle version du code <strong>sans passer par FTP</strong>. Seuls les zips construits sur le poste du propriétaire
  (<code>deploy/outils/construire_zip.php</code>) et <strong>signés avec sa clé</strong> sont acceptés. Les fichiers remplacés sont
  sauvegardés avant l'installation : chaque mise à jour peut être annulée. Les données des écoles ne sont pas touchées ;
  les migrations de bases restent à lancer depuis <a href="<?= APP_URL ?>/association/migrations.php">Migrations</a>.
</p>

<?php if (!$cle_presente): ?>
  <div class="alert alert-warning small"><i class="bi bi-key me-1"></i>
    Clé publique absente du serveur (<code>bd/lib/cle_maj_publique.pem</code>) : aucun zip ne peut être installé.
    Déposez-la <strong>une seule fois</strong> par FTP ; ensuite toutes les mises à jour passent par cette page.</div>
<?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger py-2 small"><?= h($err) ?></div><?php endif; ?>

<?php if (!$an): ?>
<div class="asso-card mb-3">
  <h6 class="mb-2"><i class="bi bi-cloud-arrow-up me-1"></i>1. Envoyer le zip de mise à jour</h6>
  <form method="post" enctype="multipart/form-data" class="d-flex flex-wrap gap-2 align-items-center">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="op" value="envoyer">
    <input type="file" name="zip" accept=".zip" class="form-control form-control-sm" style="max-width:420px" required>
    <button class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Contrôler</button>
  </form>
  <div class="small text-muted2 mt-2">Taille maximale acceptée par le serveur : <?= h(ini_get('upload_max_filesize')) ?>
    (envoi total : <?= h(ini_get('post_max_size')) ?>). Rien n'est installé à cette étape.</div>
</div>
<?php else: ?>
<div class="asso-card mb-3">
  <h6 class="mb-2"><i class="bi bi-clipboard-check me-1"></i>2. Contrôle de « <?= h($_SESSION['maj_attente_nom'] ?? 'zip') ?> »</h6>
  <ul class="small mb-2">
    <li>Version : commit <strong><?= h($an['manifeste']['commit'] ?? '?') ?></strong>
        (depuis <?= h($an['manifeste']['depuis'] ?? '?') ?>, construit le <?= h($an['manifeste']['cree_le'] ?? '?') ?>)</li>
    <li>Signature du propriétaire :
      <?= $an['signe'] ? '<span class="text-success fw-semibold"><i class="bi bi-patch-check me-1"></i>valide</span>'
                       : '<span class="text-danger fw-semibold"><i class="bi bi-x-octagon me-1"></i>absente ou invalide</span>' ?></li>
    <li>Empreintes des fichiers : <?= $an['verifie'] && $an['ok'] ? '<span class="text-success">conformes au manifeste</span>' : '<span class="text-danger">non conformes</span>' ?></li>
    <li><?= (int) ($compte['nouveau'] ?? 0) ?> nouveau(x), <?= (int) ($compte['modifie'] ?? 0) ?> modifié(s),
        <?= (int) ($compte['identique'] ?? 0) ?> déjà à jour<?= $an['supprimes'] ? ', ' . count($an['supprimes']) . ' à retirer' : '' ?>.</li>
  </ul>
  <?php if ($an['erreurs']): ?>
    <div class="alert alert-danger small py-2 mb-2"><strong>Zip refusé :</strong>
      <ul class="mb-0"><?php foreach (array_slice($an['erreurs'], 0, 20) as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>
  <?php $changes = array_filter($an['fichiers'], fn($e) => $e !== 'identique'); if ($changes || $an['supprimes']): ?>
  <details class="small mb-2"><summary>Voir les fichiers concernés (<?= count($changes) + count($an['supprimes']) ?>)</summary>
    <ul class="mb-0" style="max-height:300px;overflow:auto">
      <?php foreach ($changes as $p => $e): ?><li><span class="badge <?= $e === 'nouveau' ? 'bg-success' : 'bg-warning text-dark' ?> me-1"><?= h($e) ?></span><code><?= h($p) ?></code></li><?php endforeach; ?>
      <?php foreach ($an['supprimes'] as $p): ?><li><span class="badge bg-danger me-1">retiré</span><code><?= h($p) ?></code></li><?php endforeach; ?>
    </ul>
  </details>
  <?php endif; ?>
  <div class="d-flex flex-wrap gap-2 align-items-center">
    <?php if ($an['ok'] && $an['signe'] && ($changes || $an['supprimes'])): ?>
    <form method="post" class="d-flex flex-wrap gap-2 align-items-center" onsubmit="return confirm('Installer cette mise à jour sur le serveur ?');">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="op" value="installer">
      <input type="password" name="mdp" class="form-control form-control-sm" style="max-width:220px" placeholder="Votre mot de passe" autocomplete="current-password" required>
      <button class="btn btn-success btn-sm"><i class="bi bi-download me-1"></i>3. Installer</button>
    </form>
    <?php elseif ($an['ok'] && !$changes && !$an['supprimes']): ?>
      <span class="small text-success"><i class="bi bi-check-circle me-1"></i>Le serveur est déjà à jour pour ce zip.</span>
    <?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="op" value="abandonner">
      <button class="btn btn-outline-secondary btn-sm">Abandonner</button>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="asso-card p-0 mb-3">
  <div class="p-3 pb-0"><h6 class="mb-2"><i class="bi bi-clock-history me-1"></i>Mises à jour installées</h6></div>
  <?php if (!$historique): ?>
    <p class="small text-muted2 px-3">Aucune mise à jour installée depuis cette page.</p>
  <?php else: ?>
  <div class="table-responsive">
  <table class="table table-sm mb-0 align-middle" style="font-size:.85rem">
    <thead><tr class="text-muted2"><th>Date</th><th>Version</th><th>Par</th><th>Fichiers</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($historique as $i => $hst): $inf = $hst['info']; ?>
      <tr class="<?= $hst['annulee'] ? 'text-muted2' : '' ?>">
        <td><?= h(date('d/m/Y H:i', strtotime($inf['date'] ?? '') ?: $hst['mtime'])) ?></td>
        <td><?= h($inf['commit'] ?? '') ?></td>
        <td><?= h($inf['auteur'] ?? '') ?></td>
        <td class="small"><?= count($inf['nouveaux'] ?? []) ?> nouveau(x), <?= count($inf['remplaces'] ?? []) ?> remplacé(s)<?= !empty($inf['supprimes']) ? ', ' . count($inf['supprimes']) . ' retiré(s)' : '' ?></td>
        <td class="text-end">
          <?php if ($hst['annulee']): ?><span class="badge badge-soft">annulée</span>
          <?php elseif (!array_filter(array_slice($historique, 0, $i), fn($x) => !$x['annulee'])): ?>
          <form method="post" class="d-inline-flex gap-1" onsubmit="return confirm('Annuler cette mise à jour et remettre les fichiers d\'avant ?');">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="op" value="annuler">
            <input type="hidden" name="sauvegarde" value="<?= h($hst['nom']) ?>">
            <input type="password" name="mdp" class="form-control form-control-sm" style="width:150px" placeholder="Mot de passe" autocomplete="current-password" required>
            <button class="btn btn-outline-danger btn-sm"><i class="bi bi-arrow-counterclockwise me-1"></i>Annuler</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="small text-muted2 px-3 py-2 mb-0">Seule la dernière mise à jour encore active peut être annulée (on revient en arrière dans l'ordre).</p>
  <?php endif; ?>
</div>
<?php asso_bas();
