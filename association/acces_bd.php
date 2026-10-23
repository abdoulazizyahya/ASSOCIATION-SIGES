<?php
// association/acces_bd.php — Diagnostic des accès MySQL (21/10/2026).
//   Pour un utilisateur MySQL : compte réellement reconnu (user@hôte), droits
//   (SHOW GRANTS) et bases accessibles (SHOW DATABASES), puis, pour chaque
//   école de l'annuaire, test de connexion avec SES identifiants.
//   Sert à vérifier, après avoir créé une base au cPanel, que l'utilisateur
//   y a bien droit AVANT de créer l'établissement.
// Réservé au propriétaire ; le mot de passe saisi n'est jamais réaffiché.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../noyau/connexion.php';
require_once __DIR__ . '/../noyau/fonctions.php';
require_once __DIR__ . '/_layout.php';
exiger_proprietaire_association();

$u = DB_USER; $base_test = ''; $diag = null; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $u         = trim(post('db_user')) ?: DB_USER;
    $p         = (string) ($_POST['db_pass'] ?? '');
    $base_test = trim(post('base_test'));
    // Mot de passe vide pour l'utilisateur global : celui de config.local.php.
    if ($p === '' && $u === DB_USER) $p = DB_PASS;
    try {
        $l = @mysqli_connect(DB_HOST, $u, $p);
        if (!$l) throw new mysqli_sql_exception(mysqli_connect_error(), mysqli_connect_errno());
        $diag = ['compte' => '', 'droits' => [], 'bases' => [], 'test' => null];
        $diag['compte'] = (string) mysqli_fetch_row(mysqli_query($l, "SELECT CURRENT_USER()"))[0];
        $q = mysqli_query($l, "SHOW GRANTS");
        while ($q && ($r = mysqli_fetch_row($q))) {
            // Masque un éventuel hachage de mot de passe (MariaDB / MySQL 5).
            $diag['droits'][] = preg_replace("/(IDENTIFIED BY (PASSWORD )?)'[^']*'/i", "$1'…'", $r[0]);
        }
        $q = mysqli_query($l, "SHOW DATABASES");
        while ($q && ($r = mysqli_fetch_row($q))) {
            if (!in_array($r[0], ['information_schema', 'performance_schema'], true)) $diag['bases'][] = $r[0];
        }
        mysqli_close($l);
        if ($base_test !== '') $diag['test'] = tester_identifiants_bd($base_test, $u, $p);
    } catch (\Throwable $e) {
        $err = (int) $e->getCode() === 1045
            ? "Utilisateur « $u » ou mot de passe incorrect."
            : 'Connexion au serveur MySQL impossible : ' . $e->getMessage();
    }
}

// Écoles de l'annuaire : base, utilisateur utilisé, test avec ses identifiants.
$ecoles = [];
foreach (assoc_all("SELECT code, nom, db_name, actif FROM etablissement ORDER BY type_enseignement='secondaire' DESC, nom") as $e) {
    try {
        [$eu, $ep] = ecole_identifiants_bd($e['db_name']);
        $res = tester_identifiants_bd($e['db_name'], $eu, $ep);
    } catch (\Throwable $ex) {
        $eu = '?'; $res = $ex->getMessage();
    }
    $ecoles[] = $e + ['user' => $eu, 'propre' => $eu !== DB_USER, 'erreur' => $res];
}
$bases_annuaire = array_column($ecoles, 'code', 'db_name');

asso_haut('Accès aux bases MySQL');
?>
<?php if ($err): ?><div class="alert alert-warning py-2 small"><?= h($err) ?></div><?php endif; ?>

<div class="asso-card mb-3" style="max-width:820px">
  <h6 class="mb-1"><i class="bi bi-person-lock me-1"></i>Que voit cet utilisateur MySQL ?</h6>
  <p class="small text-muted2 mb-3">Après avoir créé une base et l'avoir rattachée à un utilisateur dans le cPanel, vérifiez ici qu'il y a
    bien accès avant de créer l'établissement. Serveur : <span class="font-monospace"><?= h(DB_HOST) ?></span>.</p>
  <form method="post" class="row g-2 align-items-end" autocomplete="off">
    <?= csrf_champ() ?>
    <div class="col-md-4">
      <label class="form-label small mb-1">Utilisateur MySQL</label>
      <input name="db_user" class="form-control form-control-sm font-monospace" value="<?= h($u) ?>" maxlength="80">
    </div>
    <div class="col-md-3">
      <label class="form-label small mb-1">Mot de passe</label>
      <input type="password" name="db_pass" class="form-control form-control-sm" autocomplete="new-password"
             placeholder="<?= h(DB_USER) ?> : laisser vide">
    </div>
    <div class="col-md-3">
      <label class="form-label small mb-1">Base à tester <span class="text-muted2">(facultatif)</span></label>
      <input name="base_test" class="form-control form-control-sm font-monospace" value="<?= h($base_test) ?>" maxlength="64">
    </div>
    <div class="col-md-2"><button class="btn btn-primary btn-sm w-100"><i class="bi bi-search me-1"></i>Vérifier</button></div>
  </form>

  <?php if ($diag): ?>
  <hr>
  <?php if ($diag['test'] !== null || $base_test !== ''): ?>
    <div class="alert py-2 small <?= $diag['test'] === null ? 'alert-success' : 'alert-danger' ?>">
      <?= $diag['test'] === null
          ? '<i class="bi bi-check-circle me-1"></i>Connexion à la base « ' . h($base_test) . ' » réussie : vous pouvez créer l\'établissement avec cette base.'
          : '<i class="bi bi-x-circle me-1"></i>' . h($diag['test']) ?>
    </div>
  <?php endif; ?>
  <div class="small mb-2">Compte reconnu par MySQL : <span class="font-monospace fw-bold"><?= h($diag['compte']) ?></span></div>
  <div class="small mb-1 fw-bold">Bases accessibles (<?= count($diag['bases']) ?>)</div>
  <table class="table table-sm small mb-3">
    <?php foreach ($diag['bases'] as $b): ?>
      <tr>
        <td class="font-monospace"><?= h($b) ?></td>
        <td class="text-end"><?= isset($bases_annuaire[$b])
            ? '<span class="badge badge-soft">' . h($bases_annuaire[$b]) . '</span>'
            : '<span class="text-muted2">non utilisée par une école</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$diag['bases']): ?><tr><td class="text-muted2">Aucune base.</td></tr><?php endif; ?>
  </table>
  <div class="small mb-1 fw-bold">Droits (SHOW GRANTS)</div>
  <pre class="small p-2 mb-0" style="background:#f6f7fb;border-radius:6px;white-space:pre-wrap"><?= h(implode("\n", $diag['droits'])) ?></pre>
  <div class="small text-muted2 mt-1">Le « \_ » dans un nom de base de ces lignes est normal (le cPanel échappe le caractère « _ »).</div>
  <?php endif; ?>
</div>

<div class="asso-card p-0" style="max-width:820px">
  <div class="px-3 py-2 small text-muted2 border-bottom" style="border-color:var(--border)">
    <i class="bi bi-buildings me-1"></i>Écoles de l'annuaire : connexion avec leurs propres identifiants
  </div>
  <table class="table table-sm mb-0 align-middle" style="font-size:.83rem">
    <thead><tr><th>École</th><th>Base</th><th>Utilisateur</th><th>Accès</th></tr></thead>
    <tbody>
      <?php foreach ($ecoles as $e): ?>
        <tr class="<?= $e['actif'] ? '' : 'opacity-50' ?>">
          <td><span class="badge badge-soft me-1"><?= h($e['code']) ?></span><?= h($e['nom']) ?></td>
          <td class="font-monospace"><?= h($e['db_name']) ?></td>
          <td class="font-monospace"><?= h($e['user']) ?><?= $e['propre'] ? '' : ' <span class="text-muted2">(global)</span>' ?></td>
          <td><?= $e['erreur'] === null
              ? '<span class="text-success"><i class="bi bi-check-circle"></i> OK</span>'
              : '<span class="text-danger" title="' . h($e['erreur']) . '"><i class="bi bi-x-circle"></i> ' . h(mb_strimwidth($e['erreur'], 0, 70, '…')) . '</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$ecoles): ?><tr><td colspan="4" class="text-center text-muted2 py-3">Aucune école.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php asso_bas(); ?>
