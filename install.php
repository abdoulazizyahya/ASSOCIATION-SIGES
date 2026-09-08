<?php
// =====================================================================
//  install.php — Assistant de PREMIÈRE INSTALLATION (aucune base encore).
//
//  Demande le nom de l'ASSOCIATION (il n'y a pas de nom figé « promeducam »
//  ni « jaynitaare ») + le 1er établissement + un compte administrateur,
//  puis :
//    - déduit un identifiant de bases  <slug>  du nom d'association,
//    - crée l'annuaire  <slug>_assoc  (bd/assoc/schema_assoc.sql),
//    - crée + garnit la base de l'école n°1  <slug>_<slug école>
//      (schéma de référence + données + classes + année + barème),
//    - crée le compte propriétaire/superadmin de l'association et le
//      compte DIRECTEUR de l'école,
//    - écrit  config.local.php  (DB_NAME_ASSOC / DB_PREFIXE_ECOLE /
//      DB_NAME / ASSOC_NOM / APP_NOM) — jamais versionné,
//    - renvoie vers l'Espace association.
//
//  Idempotence : refuse de tourner si config.local.php pointe déjà vers un
//  annuaire opérationnel, ou si une base cible existe déjà.
// =====================================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/connexion_assoc.php';            // slug_base_ecole(), charger_schema_ecole()
require_once __DIR__ . '/bd/lib/lieux_normalisation.php'; // lieu_normaliser() : accents -> ASCII (table manuelle, fiable Windows)

mysqli_report(MYSQLI_REPORT_OFF);                // on gère les erreurs à la main ici

// ── Déjà installé ? ───────────────────────────────────────────────────
if (is_file(__DIR__ . '/config.local.php')) {
    $c = @mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME_ASSOC);
    if ($c && @mysqli_query($c, "SELECT 1 FROM membre LIMIT 1")) {
        header('Location: ' . APP_URL . '/association/login.php');
        exit;
    }
}

// Identifiant de base à partir d'un nom libre : accents retirés via la
// table manuelle de lieu_normaliser() (iconv//TRANSLIT n'est pas fiable
// sous Windows — voir bd/lib/lieux_normalisation.php), puis [a-z0-9_],
// tronqué à 30. « Réseau Écoles Al-Falah » -> « reseau_ecoles_al_falah ».
function install_slug(string $s): string {
    $t = lieu_normaliser($s);                 // « reseau ecoles al falah »
    $t = trim(preg_replace('/_+/', '_', str_replace(' ', '_', $t)), '_');
    return substr($t, 0, 30);
}

$err = [];
$ok  = false;
$conf_manuel = '';
$val = ['nom_association' => '', 'nom_ecole' => '', 'ville' => '', 'login' => 'admin'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($val as $k => $_) {
        $v = trim((string) ($_POST[$k] ?? $val[$k]));
        // Filet : un client qui n'enverrait pas de l'UTF-8 (la page l'est
        // pourtant) casserait lieu_normaliser()/preg //u plus loin.
        if ($v !== '' && !mb_check_encoding($v, 'UTF-8')) {
            $v = mb_convert_encoding($v, 'UTF-8', 'Windows-1252');
        }
        $val[$k] = $v;
    }
    $val['login'] = strtolower($val['login']);
    $pwd  = (string) ($_POST['pwd'] ?? '');
    $pwd2 = (string) ($_POST['pwd2'] ?? '');

    if ($val['nom_association'] === '') $err[] = "Le nom de l'association est obligatoire.";
    if ($val['nom_ecole'] === '')       $err[] = "Le nom du premier établissement est obligatoire.";
    if (!preg_match('/^[a-z0-9_.\-]{3,30}$/', $val['login'])) $err[] = "Identifiant administrateur : 3 à 30 caractères (lettres, chiffres, . _ -).";
    if (mb_strlen($pwd) < 6) $err[] = "Mot de passe : 6 caractères minimum.";
    if ($pwd !== $pwd2)      $err[] = "Les deux mots de passe ne correspondent pas.";

    $slug = install_slug($val['nom_association']);
    if (mb_strlen($slug) < 3) $err[] = "Impossible de déduire un identifiant de base valide du nom d'association (utilisez au moins 3 lettres/chiffres).";

    $srv = null;
    if (!$err) {
        try { $srv = mysqli_connect(DB_HOST, DB_USER, DB_PASS); mysqli_set_charset($srv, 'utf8mb4'); }
        catch (\Throwable $e) { $err[] = "Connexion MySQL impossible : " . $e->getMessage(); }
    }

    // Nom d'association tronqué (préfixe commun), + slug de l'école (mots
    // génériques « groupe scolaire / GSBI / collège »… retirés par
    // slug_base_ecole) — accents déjà retirés en amont.
    $pref     = substr($slug, 0, 24);
    $slug_ec  = slug_base_ecole(lieu_normaliser($val['nom_ecole']) ?: $val['nom_ecole'], 'EC1') ?: 'ec1';
    $db_assoc = $pref . '_assoc';
    $db_ec1   = $pref . '_' . substr($slug_ec, 0, 34);

    if (!$err && $srv) {
        foreach ([$db_assoc => 'annuaire', $db_ec1 => "école"] as $d => $quoi) {
            $q = mysqli_real_escape_string($srv, $d);
            if ((int) mysqli_fetch_row(mysqli_query($srv,
                "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='$q'"))[0]) {
                $err[] = "La base $quoi « $d » existe déjà — supprimez-la, ou changez le nom.";
            }
        }
    }

    if (!$err && $srv) {
        $hash = password_hash($pwd, PASSWORD_DEFAULT);
        try {
            // 1. Annuaire
            $sql = @file_get_contents(__DIR__ . '/bd/assoc/schema_assoc.sql');
            if ($sql === false) throw new RuntimeException("bd/assoc/schema_assoc.sql introuvable.");
            $sql = str_replace('{{DB_NAME_ASSOC}}', $db_assoc, $sql);
            if (mysqli_multi_query($srv, $sql)) { do { /* drain */ } while (mysqli_next_result($srv)); }
            if (mysqli_errno($srv)) throw new RuntimeException("Création de l'annuaire : " . mysqli_error($srv));
            $assoc = mysqli_connect(DB_HOST, DB_USER, DB_PASS, $db_assoc);
            mysqli_set_charset($assoc, 'utf8mb4');

            // 2. Base de l'école n°1 : schéma + données de référence + classes
            //    + année + barème (charger_schema_ecole -> seed -> provision).
            mysqli_query($srv, "CREATE DATABASE `$db_ec1` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $le = mysqli_connect(DB_HOST, DB_USER, DB_PASS, $db_ec1);
            mysqli_set_charset($le, 'utf8mb4');
            charger_schema_ecole($le, [
                'nom'    => $val['nom_ecole'],
                'nom_en' => null,
                'sigle'  => null,
                'ville'  => $val['ville'] ?: null,
            ]);

            // 3. Compte DIRECTEUR de l'école (login.php joint user -> enseignant)
            mysqli_query($le, "INSERT INTO enseignant (nom_ens, prenom_ens, id_fonction, statut_ens)
                               VALUES ('Directeur', 'Général', 'DIRECTEUR', 'actif')");
            $mat = (int) mysqli_insert_id($le);
            $st = mysqli_prepare($le, "INSERT INTO user (login_user, pwd_user, matricule_ens) VALUES (?, ?, ?)");
            mysqli_stmt_bind_param($st, 'ssi', $val['login'], $hash, $mat);
            mysqli_stmt_execute($st);
            mysqli_stmt_close($st);
            mysqli_close($le);

            // 4. Annuaire : établissement + compte propriétaire/superadmin
            $ville = $val['ville'] ?: null;
            $st = mysqli_prepare($assoc,
                "INSERT INTO etablissement (code, db_name, nom, ville, actif) VALUES ('EC1', ?, ?, ?, 1)");
            mysqli_stmt_bind_param($st, 'sss', $db_ec1, $val['nom_ecole'], $ville);
            mysqli_stmt_execute($st);
            $id_ec1 = (int) mysqli_stmt_insert_id($st);
            mysqli_stmt_close($st);

            $vmax = 0;
            foreach (glob(__DIR__ . '/bd/migration_v*.sql') ?: [] as $f) {
                if (preg_match('/migration_v(\d+)\.sql$/', $f, $m)) $vmax = max($vmax, (int) $m[1]);
            }
            mysqli_query($assoc, "INSERT INTO schema_version_etab (id_etablissement, version) VALUES ($id_ec1, $vmax)");

            $st = mysqli_prepare($assoc,
                "INSERT INTO membre (login, pwd_hash, nom, prenom, actif, proprietaire)
                 VALUES (?, ?, 'Administrateur', ?, 1, 1)");
            mysqli_stmt_bind_param($st, 'sss', $val['login'], $hash, $val['nom_association']);
            mysqli_stmt_execute($st);
            $id_m = (int) mysqli_stmt_insert_id($st);
            mysqli_stmt_close($st);
            mysqli_query($assoc, "INSERT INTO membre_acces (id_membre, id_etablissement, plein_acces, actif)
                                  VALUES ($id_m, NULL, 1, 1)");
            mysqli_close($assoc);

            // 5. config.local.php — la config de CET environnement.
            $conf = "<?php\n"
                . "// Généré par install.php le " . date('Y-m-d H:i') . " — spécifique à ce serveur, NE PAS versionner.\n"
                . "define('DB_NAME_ASSOC',    " . var_export($db_assoc, true) . ");\n"
                . "define('DB_PREFIXE_ECOLE', " . var_export($pref, true) . ");\n"
                . "define('DB_NAME',          " . var_export($db_ec1, true) . ");\n"
                . "define('ASSOC_NOM',        " . var_export($val['nom_association'], true) . ");\n"
                . "define('APP_NOM',          " . var_export($val['nom_association'] . ' · Gestion scolaire', true) . ");\n";
            if (@file_put_contents(__DIR__ . '/config.local.php', $conf) === false) {
                $conf_manuel = $conf;
                throw new RuntimeException("Bases créées, mais impossible d'écrire config.local.php (droits). Créez-le manuellement avec le contenu ci-dessous, puis ouvrez l'Espace association.");
            }

            $ok = true;
        } catch (\Throwable $e) {
            $err[] = $e->getMessage();
            // Nettoyage best-effort (sauf si config.local.php a été écrit).
            if (!$conf_manuel) {
                @mysqli_query($srv, "DROP DATABASE IF EXISTS `$db_ec1`");
                @mysqli_query($srv, "DROP DATABASE IF EXISTS `$db_assoc`");
            }
        }
    }
}

$exemple_slug = install_slug($val['nom_association'] ?: 'Mon Association');
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Installation — SIGES</title>
  <style>
    :root { --marine:#1a2744; --or:#c8960a; --bg:#f5f4f0; --border:#e2ded0; }
    * { box-sizing:border-box; }
    body { margin:0; font:15px/1.55 "Segoe UI",system-ui,sans-serif; color:#1e2a3a;
           background:radial-gradient(900px 400px at 15% -10%, rgba(200,150,10,.10), transparent 60%), var(--bg);
           min-height:100vh; display:flex; align-items:flex-start; justify-content:center; padding:6vh 16px 4rem; }
    .box { width:100%; max-width:560px; background:#fff; border:1px solid var(--border);
           border-radius:12px; box-shadow:0 6px 26px rgba(26,39,68,.12); padding:1.6rem 1.7rem; }
    h1 { font:600 22px Georgia,serif; color:var(--marine); margin:.1rem 0 .2rem; }
    .sub { color:#6b7280; font-size:.9rem; margin-bottom:1.2rem; }
    label { display:block; font-weight:600; font-size:.82rem; margin:.9rem 0 .25rem; color:#374151; }
    input { width:100%; padding:.5rem .65rem; border:1px solid var(--border); border-radius:7px; font-size:.92rem; }
    input:focus { outline:none; border-color:var(--or); box-shadow:0 0 0 3px rgba(200,150,10,.18); }
    .hint { font-size:.74rem; color:#6b7280; margin-top:.2rem; }
    .row { display:flex; gap:.8rem; }
    .row > div { flex:1; }
    button { margin-top:1.4rem; width:100%; padding:.65rem; border:0; border-radius:8px;
             background:var(--marine); color:#fff; font-weight:700; font-size:.95rem; cursor:pointer; }
    button:hover { background:#0f1a30; }
    .alert { border-radius:8px; padding:.6rem .8rem; font-size:.85rem; margin-bottom:1rem; }
    .alert-err { background:#fee2e2; border:1px solid #f0b8b8; color:#8a1414; }
    .alert-ok  { background:#e6f4ee; border:1px solid #b8ddc8; color:#145c3a; }
    .mono { font-family:ui-monospace,"Courier New",monospace; }
    code { background:#f2f0e8; padding:1px 5px; border-radius:4px; }
    pre { background:#f2f0e8; padding:12px; border-radius:6px; overflow:auto; font-size:.8rem; }
    a.btn { display:inline-block; margin-top:1rem; padding:.6rem 1rem; background:var(--or); color:#fff;
            border-radius:8px; text-decoration:none; font-weight:700; }
  </style>
</head>
<body>
<div class="box">
  <h1>Installation de SIGES</h1>
  <div class="sub">Première mise en route — indiquez le nom de votre association et créez votre premier établissement.</div>

  <?php if ($ok): ?>
    <div class="alert alert-ok">
      <strong>Installation terminée.</strong><br>
      Annuaire : <code class="mono"><?= htmlspecialchars($db_assoc) ?></code> —
      École n°1 : <code class="mono"><?= htmlspecialchars($db_ec1) ?></code>.<br>
      Compte administrateur : <strong><?= htmlspecialchars($val['login']) ?></strong>.
    </div>
    <a class="btn" href="<?= htmlspecialchars(APP_URL) ?>/association/login.php">Ouvrir l'Espace association →</a>
    <p class="hint" style="margin-top:1rem">Un compte DIRECTEUR (même identifiant / mot de passe) a aussi été créé pour la connexion à l'école : <code><?= htmlspecialchars(APP_URL) ?>/login.php</code>.</p>

  <?php else: ?>
    <?php foreach ($err as $e): ?>
      <div class="alert alert-err"><?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>
    <?php if ($conf_manuel): ?>
      <pre><?= htmlspecialchars($conf_manuel) ?></pre>
      <a class="btn" href="<?= htmlspecialchars(APP_URL) ?>/association/login.php">Continuer vers l'Espace association →</a>
    <?php else: ?>

    <form method="post" autocomplete="off">
      <label for="nom_association">Nom de l'association <span style="color:#b91c1c">*</span></label>
      <input type="text" id="nom_association" name="nom_association" required maxlength="120"
             value="<?= htmlspecialchars($val['nom_association']) ?>" placeholder="ex. Réseau des Écoles Al-Falah">
      <div class="hint">Identifiant des bases de données : <span class="mono" id="slugview"><?= htmlspecialchars($exemple_slug) ?>_assoc</span>,
        <span class="mono"><?= htmlspecialchars($exemple_slug) ?>_&lt;école&gt;</span>. Ce nom apparaît aussi dans l'interface.</div>

      <div class="row">
        <div>
          <label for="nom_ecole">Premier établissement <span style="color:#b91c1c">*</span></label>
          <input type="text" id="nom_ecole" name="nom_ecole" required maxlength="150"
                 value="<?= htmlspecialchars($val['nom_ecole']) ?>" placeholder="ex. Groupe Scolaire Al-Falah">
        </div>
        <div>
          <label for="ville">Ville</label>
          <input type="text" id="ville" name="ville" maxlength="100" value="<?= htmlspecialchars($val['ville']) ?>" placeholder="ex. Ngaoundéré">
        </div>
      </div>

      <label for="login">Identifiant administrateur <span style="color:#b91c1c">*</span></label>
      <input type="text" id="login" name="login" required maxlength="30" value="<?= htmlspecialchars($val['login']) ?>">
      <div class="hint">Sert à la fois au compte <strong>propriétaire de l'association</strong> et au compte <strong>DIRECTEUR</strong> de l'école.</div>

      <div class="row">
        <div>
          <label for="pwd">Mot de passe <span style="color:#b91c1c">*</span></label>
          <input type="password" id="pwd" name="pwd" required minlength="6">
        </div>
        <div>
          <label for="pwd2">Confirmer <span style="color:#b91c1c">*</span></label>
          <input type="password" id="pwd2" name="pwd2" required minlength="6">
        </div>
      </div>

      <button type="submit">Installer</button>
    </form>

    <script>
      (function () {
        var n = document.getElementById('nom_association'), v = document.getElementById('slugview');
        function slug(s) {
          return s.normalize('NFD').replace(/[̀-ͯ]/g, '')
                  .toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/_+/g, '_')
                  .replace(/^_|_$/g, '').slice(0, 30);
        }
        n.addEventListener('input', function () { v.textContent = (slug(n.value) || 'association') + '_assoc'; });
      })();
    </script>
    <?php endif; ?>
  <?php endif; ?>
</div>
</body>
</html>
