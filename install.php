<?php
// =====================================================================
//  install.php — Assistant de PREMIÈRE INSTALLATION (aucune base encore).
//
//  Deux modes :
//   - « école » (par défaut) : UNE SEULE école, primaire OU secondaire,
//     SANS annuaire association — juste la base de l'école. C'est le mode
//     normal pour un établissement isolé qui ne gère pas de réseau
//     d'écoles : pas de base « _assoc » créée, aucun besoin de passer par
//     /association/ ensuite (voir ecole_contexte.php::type_enseignement_courant()
//     + config.php::ECOLE_TYPE_SOLO — l'app tourne en mono-école dès que
//     l'annuaire est absent, connexion.php).
//   - « réseau » (association) : demande le nom de l'ASSOCIATION + le 1er
//     établissement + un compte administrateur, puis :
//       - déduit un identifiant de bases  <slug>  du nom d'association,
//       - crée l'annuaire  <slug>_assoc  (bd/assoc/schema_assoc.sql),
//       - crée + garnit la base de l'école n°1  <slug>_<slug école>
//         (schéma de référence + données + classes + année + barème),
//       - crée le compte propriétaire/superadmin de l'association.
//
//  Dans les deux modes :
//    - crée + garnit la base de l'école (schéma de référence primaire OU
//      secondaire selon le choix, + données + classes + année + barème),
//    - crée le compte administrateur de l'école (DIRECTEUR en primaire,
//      ADMIN en secondaire — même identifiant/mot de passe saisis ici),
//    - écrit  config.local.php  (jamais versionné),
//    - renvoie vers la connexion.
//
//  Idempotence : refuse de tourner si config.local.php pointe déjà vers un
//  annuaire ou une base école opérationnelle.
// =====================================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/connexion_assoc.php';            // slug_base_ecole(), charger_schema_ecole(), annuaire_dispo()
require_once __DIR__ . '/bd/lib/lieux_normalisation.php'; // lieu_normaliser() : accents -> ASCII (table manuelle, fiable Windows)

mysqli_report(MYSQLI_REPORT_OFF);                // on gère les erreurs à la main ici

// ── Déjà installé ? ───────────────────────────────────────────────────
if (is_file(__DIR__ . '/config.local.php')) {
    // Mode réseau déjà installé : annuaire joignable.
    $c = @mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME_ASSOC);
    if ($c && @mysqli_query($c, "SELECT 1 FROM membre LIMIT 1")) {
        header('Location: ' . APP_URL . '/association/login.php');
        exit;
    }
    // Mode école unique déjà installé : pas d'annuaire, mais la base école existe.
    $c2 = @mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($c2 && @mysqli_query($c2, "SELECT 1 FROM etablissement LIMIT 1")) {
        header('Location: ' . APP_URL . '/login.php');
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
$comptes = [];
$conf_manuel = '';
$val = [
    'mode'              => 'ecole',
    'type_enseignement' => 'primaire',
    'statut'            => 'public',
    'nom_association'   => '',
    'nom_ecole'         => '',
    'ville'             => '',
    'login'             => 'admin',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['nom_association', 'nom_ecole', 'ville', 'login'] as $k) {
        $v = trim((string) ($_POST[$k] ?? $val[$k]));
        // Filet : un client qui n'enverrait pas de l'UTF-8 (la page l'est
        // pourtant) casserait lieu_normaliser()/preg //u plus loin.
        if ($v !== '' && !mb_check_encoding($v, 'UTF-8')) {
            $v = mb_convert_encoding($v, 'UTF-8', 'Windows-1252');
        }
        $val[$k] = $v;
    }
    $val['mode']              = in_array($_POST['mode'] ?? '', ['ecole', 'reseau'], true) ? $_POST['mode'] : 'ecole';
    $val['type_enseignement'] = in_array($_POST['type_enseignement'] ?? '', ['primaire', 'secondaire'], true)
                               ? $_POST['type_enseignement'] : 'primaire';
    $val['statut'] = in_array($_POST['statut'] ?? '', ['public', 'prive'], true) ? $_POST['statut'] : 'public';
    $val['login'] = strtolower($val['login']);
    $reseau = $val['mode'] === 'reseau';

    if ($reseau && $val['nom_association'] === '') $err[] = "Le nom de l'association est obligatoire.";
    if ($val['nom_ecole'] === '') $err[] = $reseau ? "Le nom du premier établissement est obligatoire." : "Le nom de l'école est obligatoire.";
    if (!preg_match('/^[a-z0-9_.\-]{3,30}$/', $val['login'])) $err[] = "Identifiant administrateur : 3 à 30 caractères (lettres, chiffres, . _ -).";

    $slug = install_slug($reseau ? $val['nom_association'] : $val['nom_ecole']);
    if (mb_strlen($slug) < 3) $err[] = $reseau
        ? "Impossible de déduire un identifiant de base valide du nom d'association (utilisez au moins 3 lettres/chiffres)."
        : "Impossible de déduire un identifiant de base valide du nom de l'école (utilisez au moins 3 lettres/chiffres).";

    $srv = null;
    if (!$err) {
        try { $srv = mysqli_connect(DB_HOST, DB_USER, DB_PASS); mysqli_set_charset($srv, 'utf8mb4'); }
        catch (\Throwable $e) { $err[] = "Connexion MySQL impossible : " . $e->getMessage(); }
    }

    // Nom d'association tronqué (préfixe commun), + slug de l'école (mots
    // génériques « groupe scolaire / GSBI / collège »… retirés par
    // slug_base_ecole) — accents déjà retirés en amont. En mode « école
    // unique », pas de préfixe d'association : le slug de l'école EST le
    // nom de la base.
    $db_assoc = null;
    if ($reseau) {
        $pref     = substr($slug, 0, 24);
        $slug_ec  = slug_base_ecole(lieu_normaliser($val['nom_ecole']) ?: $val['nom_ecole'], 'EC1') ?: 'ec1';
        $db_assoc = $pref . '_assoc';
        $db_ec1   = $pref . '_' . substr($slug_ec, 0, 34);
    } else {
        $db_ec1 = $slug;
    }

    if (!$err && $srv) {
        $cibles = $reseau ? [$db_assoc => 'annuaire', $db_ec1 => 'école'] : [$db_ec1 => 'école'];
        foreach ($cibles as $d => $quoi) {
            $q = mysqli_real_escape_string($srv, $d);
            if ((int) mysqli_fetch_row(mysqli_query($srv,
                "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='$q'"))[0]) {
                $err[] = "La base $quoi « $d » existe déjà — supprimez-la, ou changez le nom.";
            }
        }
    }

    if (!$err && $srv) {
        // Mot de passe = identifiant (demande explicite du 22/09/2026,
        // cohérent avec creer_comptes_defaut_ecole()) — à changer depuis
        // Sécurité dès la première connexion.
        $hash = password_hash($val['login'], PASSWORD_DEFAULT);
        $type = $val['type_enseignement'];
        try {
            if ($reseau) {
                // 1. Annuaire
                $sql = @file_get_contents(__DIR__ . '/bd/assoc/schema_assoc.sql');
                if ($sql === false) throw new RuntimeException("bd/assoc/schema_assoc.sql introuvable.");
                $sql = str_replace('{{DB_NAME_ASSOC}}', $db_assoc, $sql);
                if (mysqli_multi_query($srv, $sql)) { do { /* drain */ } while (mysqli_next_result($srv)); }
                if (mysqli_errno($srv)) throw new RuntimeException("Création de l'annuaire : " . mysqli_error($srv));
            }

            // 2. Base de l'école : schéma (primaire ou secondaire) + données de
            //    référence + classes + année + barème (charger_schema_ecole).
            mysqli_query($srv, "CREATE DATABASE `$db_ec1` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $le = mysqli_connect(DB_HOST, DB_USER, DB_PASS, $db_ec1);
            mysqli_set_charset($le, 'utf8mb4');
            charger_schema_ecole($le, [
                'nom'    => $val['nom_ecole'],
                'nom_en' => null,
                'sigle'  => null,
                'ville'  => $val['ville'] ?: null,
                'statut' => $val['statut'],
            ], $type);

            // 3. Comptes par défaut de l'école : FONDATEUR, DIRECTEUR (identifiant
            //    saisi dans ce formulaire, mot de passe = identifiant) et FINANCIER —
            //    mêmes 3 comptes que crée creer_etablissement() (association > Nouvel
            //    établissement), voir connexion_assoc.php::creer_comptes_defaut_ecole().
            $comptes = creer_comptes_defaut_ecole($le, $type, $val['login']);
            mysqli_close($le);

            if ($reseau) {
                $assoc = mysqli_connect(DB_HOST, DB_USER, DB_PASS, $db_assoc);
                mysqli_set_charset($assoc, 'utf8mb4');

                // 4. Annuaire : établissement + compte propriétaire/superadmin
                $ville = $val['ville'] ?: null;
                $st = mysqli_prepare($assoc,
                    "INSERT INTO etablissement (code, db_name, nom, ville, actif, type_enseignement) VALUES ('EC1', ?, ?, ?, 1, ?)");
                mysqli_stmt_bind_param($st, 'ssss', $db_ec1, $val['nom_ecole'], $ville, $type);
                mysqli_stmt_execute($st);
                $id_ec1 = (int) mysqli_stmt_insert_id($st);
                mysqli_stmt_close($st);

                $vmax = 0;
                $motif = $type === 'secondaire' ? '/bd/secondaire/migration_v*.sql' : '/bd/migration_v*.sql';
                foreach (glob(__DIR__ . $motif) ?: [] as $f) {
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
            }

            // 5. config.local.php — la config de CET environnement.
            if ($reseau) {
                $conf = "<?php\n"
                    . "// Généré par install.php le " . date('Y-m-d H:i') . " — installation RÉSEAU (association), spécifique à ce serveur, NE PAS versionner.\n"
                    . "define('DB_NAME_ASSOC',    " . var_export($db_assoc, true) . ");\n"
                    . "define('DB_PREFIXE_ECOLE', " . var_export(substr($slug, 0, 24), true) . ");\n"
                    . "define('DB_NAME',          " . var_export($db_ec1, true) . ");\n"
                    . "define('ASSOC_NOM',        " . var_export($val['nom_association'], true) . ");\n"
                    . "define('APP_NOM',          " . var_export($val['nom_association'] . ' · Gestion scolaire', true) . ");\n"
                    . "\n"
                    . "// Double authentification (TOTP) NON imposée aux superadministrateurs :\n"
                    . "// chacun peut l'activer depuis « Sécurité ». Mettre true pour la rendre obligatoire.\n"
                    . "define('ASSOC_2FA_SUPERADMIN_OBLIGATOIRE', false);\n";
            } else {
                $conf = "<?php\n"
                    . "// Généré par install.php le " . date('Y-m-d H:i') . " — installation ÉCOLE UNIQUE (sans annuaire association), spécifique à ce serveur, NE PAS versionner.\n"
                    . "define('DB_NAME',          " . var_export($db_ec1, true) . ");\n"
                    . "define('APP_NOM',          " . var_export($val['nom_ecole'] . ' · Gestion scolaire', true) . ");\n"
                    . "define('ASSOC_NOM',        " . var_export($val['nom_ecole'], true) . ");\n"
                    . "define('ECOLE_TYPE_SOLO',  " . var_export($type, true) . ");\n";
            }
            if (@file_put_contents(__DIR__ . '/config.local.php', $conf) === false) {
                $conf_manuel = $conf;
                throw new RuntimeException("Base créée, mais impossible d'écrire config.local.php (droits). Créez-le manuellement avec le contenu ci-dessous, puis connectez-vous.");
            }

            $ok = true;
        } catch (\Throwable $e) {
            $err[] = $e->getMessage();
            // Nettoyage best-effort (sauf si config.local.php a été écrit).
            if (!$conf_manuel) {
                @mysqli_query($srv, "DROP DATABASE IF EXISTS `$db_ec1`");
                if ($db_assoc) @mysqli_query($srv, "DROP DATABASE IF EXISTS `$db_assoc`");
            }
        }
    }
}

$exemple_slug = install_slug(($val['mode'] === 'reseau' ? $val['nom_association'] : $val['nom_ecole']) ?: 'Mon École');
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
    .choix { display:flex; gap:.6rem; margin-top:.3rem; }
    .choix label.opt { flex:1; display:flex; flex-direction:column; gap:.15rem; margin:0; padding:.55rem .7rem;
            border:1px solid var(--border); border-radius:8px; cursor:pointer; font-weight:600; font-size:.85rem; color:#374151; }
    .choix label.opt span.d { font-weight:400; font-size:.74rem; color:#6b7280; }
    .choix input[type=radio] { width:auto; margin-right:.4rem; }
    .choix input[type=radio]:checked ~ * { color:var(--marine); }
    .choix label.opt:has(input:checked) { border-color:var(--or); background:#fdf8ee; }
  </style>
</head>
<body>
<div class="box">
  <h1>Installation de SIGES</h1>
  <div class="sub">Première mise en route de l'application.</div>

  <?php if ($ok): ?>
    <div class="alert alert-ok">
      <strong>Installation terminée.</strong><br>
      <?php if ($val['mode'] === 'reseau'): ?>
        Annuaire : <code class="mono"><?= htmlspecialchars($db_assoc) ?></code> —
        École n°1 : <code class="mono"><?= htmlspecialchars($db_ec1) ?></code>.
      <?php else: ?>
        Base de l'école : <code class="mono"><?= htmlspecialchars($db_ec1) ?></code>
        (<?= $val['type_enseignement'] === 'secondaire' ? 'secondaire' : 'primaire' ?>).
      <?php endif; ?>
    </div>
    <?php if ($comptes): ?>
    <div class="alert alert-ok">
      <strong>Comptes créés</strong> (mot de passe = identifiant) — à transmettre au personnel puis à changer depuis Sécurité :
      <table class="mono" style="width:100%;margin-top:.5rem;font-size:.82rem;border-collapse:collapse">
        <thead><tr style="text-align:left"><th>Rôle</th><th>Identifiant / mot de passe</th></tr></thead>
        <tbody>
          <?php foreach ($comptes as $c): ?>
          <tr>
            <td><?= htmlspecialchars($c['role']) ?></td>
            <td><?= htmlspecialchars($c['login']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
    <?php if ($val['mode'] === 'reseau'): ?>
      <a class="btn" href="<?= htmlspecialchars(APP_URL) ?>/association/login.php">Ouvrir l'Espace association →</a>
      <p class="hint" style="margin-top:1rem">Le compte DIRECTEUR ci-dessus sert aussi à la connexion à l'école : <code><?= htmlspecialchars(APP_URL) ?>/login.php</code>.</p>
    <?php else: ?>
      <a class="btn" href="<?= htmlspecialchars(APP_URL) ?>/login.php">Se connecter →</a>
    <?php endif; ?>

  <?php else: ?>
    <?php foreach ($err as $e): ?>
      <div class="alert alert-err"><?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>
    <?php if ($conf_manuel): ?>
      <pre><?= htmlspecialchars($conf_manuel) ?></pre>
      <a class="btn" href="<?= htmlspecialchars(APP_URL) ?>/<?= $val['mode'] === 'reseau' ? 'association/login.php' : 'login.php' ?>">Continuer →</a>
    <?php else: ?>

    <form method="post" autocomplete="off">
      <label>Combien d'établissements allez-vous gérer ?</label>
      <div class="choix">
        <label class="opt">
          <input type="radio" name="mode" value="ecole" <?= $val['mode'] !== 'reseau' ? 'checked' : '' ?>>
          Une seule école
          <span class="d">Pas d'espace association, connexion directe.</span>
        </label>
        <label class="opt">
          <input type="radio" name="mode" value="reseau" <?= $val['mode'] === 'reseau' ? 'checked' : '' ?>>
          Un réseau d'écoles
          <span class="d">Annuaire association, ajout d'écoles ensuite.</span>
        </label>
      </div>

      <label>Type d'école</label>
      <div class="choix">
        <label class="opt">
          <input type="radio" name="type_enseignement" id="type_ens_primaire" value="primaire" <?= $val['type_enseignement'] !== 'secondaire' ? 'checked' : '' ?>>
          Primaire
        </label>
        <label class="opt">
          <input type="radio" name="type_enseignement" id="type_ens_secondaire" value="secondaire" <?= $val['type_enseignement'] === 'secondaire' ? 'checked' : '' ?>>
          Secondaire
        </label>
      </div>

      <div id="bloc_statut" style="<?= $val['type_enseignement'] === 'secondaire' ? '' : 'display:none' ?>">
        <label>Statut de l'école</label>
        <div class="choix">
          <label class="opt">
            <input type="radio" name="statut" value="public" <?= $val['statut'] !== 'prive' ? 'checked' : '' ?>>
            Public
          </label>
          <label class="opt">
            <input type="radio" name="statut" value="prive" <?= $val['statut'] === 'prive' ? 'checked' : '' ?>>
            Privé
          </label>
        </div>
        <div class="hint">Détermine le libellé affiché (Proviseur/Principal) et lequel des deux modules Paiements (public ou privé) est visible.</div>
      </div>

      <div id="bloc_association" style="display:none">
        <label for="nom_association">Nom de l'association <span style="color:#b91c1c">*</span></label>
        <input type="text" id="nom_association" name="nom_association" maxlength="120"
               value="<?= htmlspecialchars($val['nom_association']) ?>" placeholder="ex. Réseau des Écoles Al-Falah">
      </div>
      <div class="hint">Identifiant de base : <span class="mono" id="slugview"><?= htmlspecialchars($exemple_slug) ?></span>. Ce nom apparaît aussi dans l'interface.</div>

      <div class="row">
        <div>
          <label id="label_nom_ecole" for="nom_ecole">Nom de l'école <span style="color:#b91c1c">*</span></label>
          <input type="text" id="nom_ecole" name="nom_ecole" required maxlength="150"
                 value="<?= htmlspecialchars($val['nom_ecole']) ?>" placeholder="ex. Groupe Scolaire Al-Falah">
        </div>
        <div>
          <label for="ville">Ville</label>
          <input type="text" id="ville" name="ville" maxlength="100" value="<?= htmlspecialchars($val['ville']) ?>" placeholder="ex. Ngaoundéré">
        </div>
      </div>

      <label for="login">Identifiant du compte Directeur <span style="color:#b91c1c">*</span></label>
      <input type="text" id="login" name="login" required maxlength="30" value="<?= htmlspecialchars($val['login']) ?>">
      <div class="hint" id="hint_login">Sert aussi de compte DIRECTEUR de l'école. Mot de passe = cet identifiant (à changer depuis Sécurité). Deux autres comptes (FONDATEUR, FINANCIER) sont créés automatiquement, mêmes conditions.</div>

      <button type="submit">Installer</button>
    </form>

    <script>
      (function () {
        var nAssoc = document.getElementById('nom_association'), nEcole = document.getElementById('nom_ecole');
        var v = document.getElementById('slugview'), bloc = document.getElementById('bloc_association');
        var labelEcole = document.getElementById('label_nom_ecole'), hintLogin = document.getElementById('hint_login');
        var blocStatut = document.getElementById('bloc_statut'), typeSecondaire = document.getElementById('type_ens_secondaire');
        function slug(s) {
          return s.normalize('NFD').replace(/[̀-ͯ]/g, '')
                  .toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/_+/g, '_')
                  .replace(/^_|_$/g, '').slice(0, 30);
        }
        function modeVal() {
          var r = document.querySelector('input[name="mode"]:checked');
          return r ? r.value : 'ecole';
        }
        function refresh() {
          var reseau = modeVal() === 'reseau';
          bloc.style.display = reseau ? '' : 'none';
          nAssoc.required = reseau;
          labelEcole.textContent = (reseau ? 'Premier établissement' : "Nom de l'école") + ' *';
          hintLogin.textContent = reseau
            ? "Sert à la fois au compte propriétaire de l'association et au compte DIRECTEUR de l'école. Mot de passe = cet identifiant (à changer depuis Sécurité)."
            : "Sert aussi de compte DIRECTEUR de l'école. Mot de passe = cet identifiant (à changer depuis Sécurité). Deux autres comptes (FONDATEUR, FINANCIER) sont créés automatiquement, mêmes conditions.";
          v.textContent = reseau
            ? (slug(nAssoc.value) || 'association') + '_assoc, ' + (slug(nAssoc.value) || 'association') + '_<école>'
            : (slug(nEcole.value) || 'ecole');
          blocStatut.style.display = typeSecondaire.checked ? '' : 'none';
        }
        document.querySelectorAll('input[name="mode"]').forEach(function (r) { r.addEventListener('change', refresh); });
        document.querySelectorAll('input[name="type_enseignement"]').forEach(function (r) { r.addEventListener('change', refresh); });
        nAssoc.addEventListener('input', refresh);
        nEcole.addEventListener('input', refresh);
        refresh();
      })();
    </script>
    <?php endif; ?>
  <?php endif; ?>
</div>
</body>
</html>
