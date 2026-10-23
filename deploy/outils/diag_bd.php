<?php
// =====================================================================
//  deploy/outils/diag_bd.php  (diagnostic serveur, LECTURE SEULE)
//  Base créée dans le cPanel mais invisible (phpMyAdmin, SIGES) : montre ce
//  que MySQL voit réellement pour un utilisateur — compte reconnu
//  (user@hôte), bases visibles, droits (SHOW GRANTS) et accès à chaque base
//  demandée. Aucune écriture.
//  À déposer à la RACINE du site, ouvrir ?token=<BACKUP_TOKEN>, puis
//  SUPPRIMER après usage. Le mot de passe saisi n'est ni stocké ni affiché.
// =====================================================================
require_once __DIR__ . '/config/config.php';
header('Content-Type: text/html; charset=utf-8');
$token = (string) ($_GET['token'] ?? '');
if (!defined('BACKUP_TOKEN') || BACKUP_TOKEN === '' || !hash_equals(BACKUP_TOKEN, $token)) {
    http_response_code(403);
    die('Accès refusé : jeton invalide (BACKUP_TOKEN de config.local.php).');
}
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$u = trim((string) ($_POST['u'] ?? ''));
$bases = trim((string) ($_POST['bases'] ?? ''));
?>
<!doctype html><meta charset="utf-8"><title>Diagnostic MySQL</title>
<style>body{font-family:sans-serif;max-width:900px;margin:2rem auto;padding:0 1rem}pre{background:#f4f4f4;padding:.6rem;overflow:auto}
.ok{color:#15803d}.ko{color:#b91c1c}input,textarea{width:100%;padding:.4rem;margin:.2rem 0 .8rem}</style>
<h2>Diagnostic MySQL — serveur « <?= $h(DB_HOST) ?> »</h2>
<form method="post">
  Utilisateur MySQL (ex. beeroc11073_sig)<input name="u" value="<?= $h($u) ?>" required>
  Mot de passe<input name="p" type="password" required>
  Bases à tester (une par ligne)<textarea name="bases" rows="3"><?= $h($bases) ?></textarea>
  <button>Diagnostiquer</button>
</form>
<?php
if ($u === '') exit;
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $l = mysqli_connect(DB_HOST, $u, (string) ($_POST['p'] ?? ''));
} catch (Throwable $e) {
    echo '<p class="ko"><b>Connexion refusée</b> [', (int) $e->getCode(), '] ', $h($e->getMessage()),
         '<br>1045 = utilisateur inexistant pour cet hôte ou mot de passe faux (le redéfinir dans cPanel → Bases de données MySQL → Utilisateurs actuels).</p>';
    exit;
}
$q = fn($s) => mysqli_query($l, $s)->fetch_all();
echo '<p class="ok"><b>Connecté.</b> MySQL vous reconnaît comme <b>', $h($q('SELECT CURRENT_USER()')[0][0]),
     '</b> — version ', $h($q('SELECT VERSION()')[0][0]), '</p>';
echo '<h3>Bases visibles</h3><pre>', $h(implode("\n", array_column($q('SHOW DATABASES'), 0))), '</pre>';
echo '<h3>Droits (SHOW GRANTS)</h3><pre>', $h(implode("\n", array_column($q('SHOW GRANTS'), 0))), '</pre>';
foreach (array_filter(array_map('trim', preg_split('/\R/', $bases))) as $b) {
    try {
        mysqli_select_db($l, $b);
        $n = $q("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")[0][0];
        echo '<p class="ok">« ', $h($b), ' » : accessible (', (int) $n, ' table(s)).</p>';
    } catch (Throwable $e) {
        echo '<p class="ko">« ', $h($b), ' » : [', (int) $e->getCode(), '] ', $h($e->getMessage()), '</p>';
    }
}
?>
<p style="color:#b45309">Supprimez ce fichier du serveur après usage.</p>
