<?php
// association/ecole_bd_restaurer.php — Restaurer la base d'UNE école à
// partir d'une sauvegarde déjà présente dans bd/sauvegardes/ (quotidienne,
// manuelle, ou de sécurité avant_*). Superadmin association uniquement.
// L'établissement doit être inactif. Un backup de sécurité de l'état
// courant est écrit avant le remplacement.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../bd/lib/ecole_maintenance.php';
exiger_superadmin_association();

$id = (int) ($_GET['id'] ?? 0);
$e  = $id ? assoc_one("SELECT * FROM etablissement WHERE id=?", [$id]) : null;
if (!$e) { asso_haut('Établissement introuvable'); asso_bas(); exit; }

$sauvegardes = ecole_maint_sauvegardes($e['code'], $e['db_name']);
$err = ''; $ok = false; $res = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $choix      = $_POST['fichier'] ?? '';
    $code_saisi = trim($_POST['confirm_code'] ?? '');
    $comprend   = !empty($_POST['comprend']);

    // Le fichier choisi DOIT figurer dans la liste calculée (jamais un
    // chemin arbitraire venu du POST).
    $cible = null;
    foreach ($sauvegardes as $s) {
        if ($s['nom'] === $choix) { $cible = $s; break; }
    }

    if ((int) $e['actif'] === 1) {
        $err = "Établissement encore actif — désactivez-le d'abord (Modifier → décocher « actif »).";
    } elseif (!$cible) {
        $err = "Sauvegarde introuvable — rechargez la page.";
    } elseif (strcasecmp($code_saisi, $e['code']) !== 0) {
        $err = "Le code saisi ne correspond pas à « " . $e['code'] . " ».";
    } elseif (!$comprend) {
        $err = "Cochez la case confirmant le remplacement du contenu de la base.";
    } elseif (!is_file($cible['chemin'])) {
        $err = "Le fichier de sauvegarde n'existe plus sur le disque.";
    } else {
        $chemin = $cible['chemin'];
        if (preg_match('/\.zip$/i', $chemin)) {
            $res = ecole_importer_zip($id, $chemin);
        } else {
            $sql = preg_match('/\.gz$/i', $chemin)
                ? ecole_maint_lire_gz($chemin)
                : @file_get_contents($chemin);
            $res = ($sql === false || $sql === null)
                ? ['ok' => false, 'backup' => null, 'message' => "Fichier de sauvegarde illisible."]
                : ecole_importer_sql($e['db_name'], $sql);
        }

        if ($res['ok']) {
            journaliser_action('ecole_bd_restauration', $id,
                $e['code'] . ' — ' . $e['db_name'] . ' ← ' . $cible['nom']
                . ' (' . (int) ($res['tables'] ?? 0) . ' tables'
                . (isset($res['fichiers']) ? ', ' . (int) $res['fichiers'] . ' fichiers' : '') . ')');
            $ok = true;
        } else {
            $err = $res['message'];
        }
    }
}

function _restaurer_taille(int $o): string {
    foreach (['o', 'Ko', 'Mo', 'Go'] as $u) { if ($o < 1024) return round($o, 1) . ' ' . $u; $o /= 1024; }
    return round($o, 1) . ' To';
}

asso_haut('Restaurer — ' . $e['nom']);
?>
<a href="<?= APP_URL ?>/association/etablissement.php?id=<?= (int) $e['id'] ?>" class="small text-decoration-none">← Fiche</a>

<?php if ($ok): ?>
  <div class="alert alert-success py-2 small mt-2"><?= h($res['message']) ?></div>
  <a href="<?= APP_URL ?>/association/etablissement.php?id=<?= (int) $e['id'] ?>" class="btn btn-primary btn-sm mt-1">
    <i class="bi bi-arrow-left me-1"></i>Retour à la fiche
  </a>
  <?php asso_bas(); exit; ?>
<?php endif; ?>

<?php if ($err): ?>
  <div class="alert alert-danger py-2 small mt-2"><?= h($err) ?></div>
  <?php if ($res && !empty($res['backup'])): ?>
    <div class="alert alert-warning py-2 small">
      Backup de l'état précédent : <span class="font-monospace"><?= h(basename($res['backup'])) ?></span>
      (<span class="font-monospace">bd/sauvegardes/</span>).
    </div>
  <?php endif; ?>
<?php endif; ?>

<div class="asso-card mt-2" style="max-width:760px">
  <div class="d-flex align-items-center gap-2 mb-2">
    <i class="bi bi-clock-history text-danger fs-4"></i>
    <strong>Restaurer une sauvegarde — remplace le contenu actuel</strong>
  </div>

  <div class="small text-muted2 mb-3">
    Code <span class="font-monospace"><?= h($e['code']) ?></span> ·
    base <span class="font-monospace"><?= h($e['db_name']) ?></span>
    · <?= (int) $e['actif'] === 1 ? '<span class="badge bg-warning text-dark">ACTIF</span>' : '<span class="text-warning">inactif</span>' ?>
  </div>

  <?php if ((int) $e['actif'] === 1): ?>
    <div class="alert alert-warning py-2 small">
      Cet établissement est <strong>actif</strong>. Ouvrez d'abord
      <a href="<?= APP_URL ?>/association/etablissement_modifier.php?id=<?= (int) $e['id'] ?>">Modifier</a>,
      décochez « Établissement actif », puis revenez ici.
    </div>
  <?php elseif (!$sauvegardes): ?>
    <div class="alert alert-secondary py-2 small">
      Aucune sauvegarde trouvée pour cette école dans <span class="font-monospace">bd/sauvegardes/</span>.
      Créez-en une depuis la fiche (« Sauvegarder maintenant »), ou importez un fichier via
      <a href="<?= APP_URL ?>/association/ecole_bd_import.php?id=<?= (int) $e['id'] ?>">Importer un dump</a>.
    </div>
  <?php else: ?>
    <ul class="small text-muted2">
      <li>Le contenu actuel de la base est remplacé par celui de la sauvegarde choisie.</li>
      <li>Un <strong>backup de sécurité</strong> de l'état courant est écrit avant.</li>
      <li>Une archive <span class="font-monospace">.zip</span> restaure aussi les fichiers (logos, signatures, pièces de dossier).</li>
    </ul>

    <form method="post" class="row g-3 mt-1">
      <input type="hidden" name="csrf" value="<?= h(csrf_generer()) ?>">

      <div class="col-12">
        <div style="overflow-x:auto">
          <table class="table table-sm align-middle mb-0" style="font-size:.8rem">
            <thead><tr><th></th><th>Sauvegarde</th><th>Type</th><th>Date</th><th class="text-end">Taille</th></tr></thead>
            <tbody>
              <?php foreach ($sauvegardes as $i => $s): ?>
                <tr>
                  <td><input class="form-check-input" type="radio" name="fichier"
                             value="<?= h($s['nom']) ?>" id="sv<?= $i ?>" <?= $i === 0 ? 'checked' : '' ?> required></td>
                  <td><label for="sv<?= $i ?>" class="font-monospace" style="cursor:pointer"><?= h($s['nom']) ?></label>
                      <?= preg_match('/\.zip$/i', $s['chemin']) ? ' <span class="badge badge-soft">+ fichiers</span>' : '' ?></td>
                  <td><?= h($s['type']) ?></td>
                  <td class="text-muted2"><?= $s['date'] ? date('d/m/Y H:i', $s['date']) : '—' ?></td>
                  <td class="text-end text-muted2"><?= _restaurer_taille((int) $s['octets']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="col-12 col-sm-6">
        <label class="form-label small fw-bold">
          Tapez le code <span class="font-monospace"><?= h($e['code']) ?></span>
        </label>
        <input type="text" name="confirm_code" autocomplete="off" required
               class="form-control form-control-sm font-monospace" placeholder="<?= h($e['code']) ?>">
      </div>

      <div class="col-12">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="comprend" id="comprend" required>
          <label class="form-check-label small" for="comprend">
            Je comprends que le contenu actuel de <span class="font-monospace"><?= h($e['db_name']) ?></span>
            sera remplacé par la sauvegarde sélectionnée.
          </label>
        </div>
      </div>

      <div class="col-12">
        <button class="btn btn-danger btn-sm"
                onclick="return confirm('Restaurer « <?= h(addslashes($e['nom'])) ?> » à partir de la sauvegarde choisie ?');">
          <i class="bi bi-clock-history me-1"></i>Restaurer
        </button>
        <a href="<?= APP_URL ?>/association/etablissement.php?id=<?= (int) $e['id'] ?>" class="btn btn-outline-light btn-sm ms-2">Annuler</a>
      </div>
    </form>
  <?php endif; ?>
</div>
<?php asso_bas();
