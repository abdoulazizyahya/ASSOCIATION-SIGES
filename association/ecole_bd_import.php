<?php
// association/ecole_bd_import.php — Importer un dump SQL dans la base
// d'UNE école : REMPLACE l'intégralité de son contenu. Superadmin
// association uniquement. L'établissement doit être inactif. Un backup
// de sécurité de l'état courant est écrit avant toute destruction
// (bd/sauvegardes/avant_import_<db>_<horo>.sql[.gz]).
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../bd/lib/ecole_maintenance.php';
exiger_superadmin_association();

const IMPORT_TAILLE_MAX = 60 * 1024 * 1024; // 60 Mo

$id = (int) ($_GET['id'] ?? 0);
$e  = $id ? assoc_one("SELECT * FROM etablissement WHERE id=?", [$id]) : null;
if (!$e) { asso_haut('Établissement introuvable'); asso_bas(); exit; }

$err = ''; $ok = false; $res = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $code_saisi = trim($_POST['confirm_code'] ?? '');
    $comprend   = !empty($_POST['comprend']);

    if ((int) $e['actif'] === 1) {
        $err = "Établissement encore actif — désactivez-le d'abord (Modifier → décocher « actif »).";
    } elseif (strcasecmp($code_saisi, $e['code']) !== 0) {
        $err = "Le code saisi ne correspond pas à « " . $e['code'] . " ».";
    } elseif (!$comprend) {
        $err = "Cochez la case confirmant le remplacement complet de la base.";
    } elseif (empty($_FILES['dump']['tmp_name']) || $_FILES['dump']['error'] !== UPLOAD_ERR_OK) {
        $err = "Aucun fichier reçu (ou upload trop volumineux — " . round(IMPORT_TAILLE_MAX / 1048576) . " Mo max).";
    } elseif ($_FILES['dump']['size'] > IMPORT_TAILLE_MAX) {
        $err = "Fichier trop volumineux (" . round(IMPORT_TAILLE_MAX / 1048576) . " Mo max).";
    } elseif (!preg_match('/\.(sql(\.gz)?|zip)$/i', $_FILES['dump']['name'])) {
        $err = "Le fichier doit être un .sql, un .sql.gz ou une archive .zip (export produit par SIGES ou mysqldump).";
    } else {
        $nom_up  = $_FILES['dump']['name'];
        $est_zip = (bool) preg_match('/\.zip$/i', $nom_up);

        if ($est_zip) {
            // Archive complète : SQL + fichiers uploadés (logos, dossiers…).
            $tmp_zip = tempnam(sys_get_temp_dir(), 'siges_impzip_') . '.zip';
            move_uploaded_file($_FILES['dump']['tmp_name'], $tmp_zip);
            $res = ecole_importer_zip($id, $tmp_zip);
            @unlink($tmp_zip);
        } else {
            $est_gz = (bool) preg_match('/\.gz$/i', $nom_up);
            $sql    = $est_gz
                ? ecole_maint_lire_gz($_FILES['dump']['tmp_name'])
                : @file_get_contents($_FILES['dump']['tmp_name']);
            if ($sql === false || $sql === null) {
                $res = ['ok' => false, 'backup' => null, 'message' => $est_gz
                    ? "Impossible de décompresser le .gz (extension zlib absente ?)."
                    : "Fichier illisible."];
            } else {
                $res = ecole_importer_sql($e['db_name'], $sql);
            }
        }

        if ($res['ok']) {
            journaliser_action('ecole_bd_import', $id,
                $e['code'] . ' — ' . $e['db_name'] . ' ← ' . $nom_up
                . ' (' . (int) ($res['tables'] ?? 0) . ' tables'
                . (isset($res['fichiers']) ? ', ' . (int) $res['fichiers'] . ' fichiers' : '') . ')');
            $ok = true;
        } else {
            $err = $res['message'];
            if (!empty($res['backup'])) {
                journaliser_action('ecole_bd_import_echec', $id,
                    $e['code'] . ' — backup ' . basename($res['backup']));
            }
        }
    }
}

asso_haut('Importer une base — ' . $e['nom']);
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
      Un backup de l'état précédent a été écrit :
      <span class="font-monospace"><?= h(basename($res['backup'])) ?></span>
      (dossier <span class="font-monospace">bd/sauvegardes/</span>).
    </div>
  <?php endif; ?>
<?php endif; ?>

<div class="asso-card mt-2" style="max-width:660px">
  <div class="d-flex align-items-center gap-2 mb-2">
    <i class="bi bi-exclamation-octagon-fill text-danger fs-4"></i>
    <strong>Remplacement complet de la base — action destructrice</strong>
  </div>

  <div class="small text-muted2 mb-3">
    Code <span class="font-monospace"><?= h($e['code']) ?></span> ·
    base <span class="font-monospace"><?= h($e['db_name']) ?></span>
    <?php if ((int) $e['actif'] === 1): ?>
      · <span class="badge bg-warning text-dark">ACTIF</span>
    <?php else: ?>
      · <span class="text-warning">inactif</span>
    <?php endif; ?>
  </div>

  <ul class="small text-muted2">
    <li>Toutes les tables actuelles de la base sont <strong>supprimées</strong>, puis remplacées par le contenu du fichier.</li>
    <li>Un <strong>backup de sécurité</strong> de l'état courant est écrit dans <span class="font-monospace">bd/sauvegardes/</span> avant destruction.</li>
    <li>Fichier accepté : export d'une base école SIGES — <span class="font-monospace">.sql</span>, <span class="font-monospace">.sql.gz</span>, ou archive <span class="font-monospace">.zip</span> (base + fichiers : logos, signatures, pièces de dossier).</li>
    <li>L'annuaire association (code, sous-domaine, NIU, personnel) n'est pas modifié.</li>
  </ul>

  <?php if ((int) $e['actif'] === 1): ?>
    <div class="alert alert-warning py-2 small">
      Cet établissement est <strong>actif</strong>. Ouvrez d'abord
      <a href="<?= APP_URL ?>/association/etablissement_modifier.php?id=<?= (int) $e['id'] ?>">Modifier</a>,
      décochez « Établissement actif », puis revenez ici.
    </div>
  <?php else: ?>
    <form method="post" enctype="multipart/form-data" class="row g-3">
      <input type="hidden" name="csrf" value="<?= h(csrf_generer()) ?>">

      <div class="col-12">
        <label class="form-label small fw-bold">Fichier de dump (.sql, .sql.gz ou .zip)</label>
        <input type="file" name="dump" accept=".sql,.gz,.zip,application/sql,application/gzip,application/zip" required
               class="form-control form-control-sm">
      </div>

      <div class="col-12">
        <label class="form-label small fw-bold">
          Tapez le code <span class="font-monospace"><?= h($e['code']) ?></span> pour confirmer
        </label>
        <input type="text" name="confirm_code" autocomplete="off" required
               class="form-control form-control-sm font-monospace" style="max-width:220px"
               placeholder="<?= h($e['code']) ?>">
      </div>

      <div class="col-12">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="comprend" id="comprend" required>
          <label class="form-check-label small" for="comprend">
            Je comprends que le contenu actuel de la base
            <span class="font-monospace"><?= h($e['db_name']) ?></span> sera entièrement remplacé.
          </label>
        </div>
      </div>

      <div class="col-12">
        <button class="btn btn-danger btn-sm"
                onclick="return confirm('Remplacer tout le contenu de la base « <?= h(addslashes($e['db_name'])) ?> » ?');">
          <i class="bi bi-database-down me-1"></i>Importer et remplacer
        </button>
        <a href="<?= APP_URL ?>/association/etablissement.php?id=<?= (int) $e['id'] ?>" class="btn btn-outline-light btn-sm ms-2">Annuler</a>
      </div>
    </form>
  <?php endif; ?>
</div>
<?php asso_bas();
