<?php
// association/etablissement_desactiver.php — désactivation/réactivation
// rapide d'un établissement depuis sa fiche (association/etablissement.php),
// réservée au PROPRIÉTAIRE et au SUPERADMIN (« admin ») de l'association —
// demande explicite du 21/09/2026 : un fondateur (compte local à l'école)
// n'y a PAS accès, ce niveau reste un privilège système. Désactiver exige de
// reconfirmer son propre mot de passe (opération à fort impact : plus
// personne ne peut se connecter à cette école — voir resoudre_etablissement()
// et login.php, déjà filtrés sur etablissement.actif=1 — et elle disparaît de
// la liste déroulante de connexion). Réactiver reste sans mot de passe,
// comme la case à cocher déjà existante d'etablissement_modifier.php.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
require_once __DIR__ . '/_layout.php';
exiger_membre_association();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$e  = $id ? assoc_one("SELECT * FROM etablissement WHERE id=?", [$id]) : null;
if (!$e) { asso_haut('Établissement introuvable'); asso_bas(); exit; }

if (!est_superadmin_association()) {
    http_response_code(403);
    asso_haut('Accès refusé');
    echo '<div class="alert alert-warning py-2 small">Seuls le <strong>propriétaire</strong> et l\'<strong>administrateur</strong> de l\'association peuvent désactiver ou réactiver un établissement.</div>';
    asso_bas();
    exit;
}

$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $action = post('action');

    if ($action === 'reactiver') {
        assoc_exec("UPDATE etablissement SET actif=1 WHERE id=?", [$id]);
        journaliser_action('etablissement_reactive', $id, $e['nom']);
        if (function_exists('regenerer_portail_accueil_best_effort')) {
            regenerer_portail_accueil_best_effort();
        }
        flash_set('succes', "« {$e['nom']} » a été réactivé.");
        rediriger('association/etablissement.php?id=' . $id);
    }

    if ($action === 'desactiver') {
        $mdp = (string) post('mdp');
        $m   = membre_connecte();
        $membre_bd = assoc_one("SELECT pwd_hash FROM membre WHERE id=?", [$m['id'] ?? 0]);
        if ($mdp === '' || !$membre_bd || !password_verify($mdp, $membre_bd['pwd_hash'])) {
            $err = "Mot de passe incorrect.";
        } else {
            assoc_exec("UPDATE etablissement SET actif=0 WHERE id=?", [$id]);
            journaliser_action('etablissement_desactive', $id, $e['nom']);
            if (function_exists('regenerer_portail_accueil_best_effort')) {
                regenerer_portail_accueil_best_effort();
            }
            flash_set('succes', "« {$e['nom']} » a été désactivé — plus personne ne peut s'y connecter, et il a disparu de la liste des écoles au login.");
            rediriger('association/etablissement.php?id=' . $id);
        }
    }
}

asso_haut(($e['actif'] ? 'Désactiver' : 'Réactiver') . ' — ' . $e['nom']);
?>
<a href="<?= APP_URL ?>/association/etablissement.php?id=<?= (int) $e['id'] ?>" class="small text-decoration-none">← Fiche</a>

<?php if ($err): ?><div class="alert alert-warning py-2 small mt-2"><?= h($err) ?></div><?php endif; ?>

<?php if ($e['actif']): ?>
<div class="asso-card mt-2" style="max-width:520px">
  <div class="d-flex align-items-start gap-2 mb-3">
    <i class="bi bi-exclamation-triangle-fill text-warning" style="font-size:1.4rem"></i>
    <div>
      <div class="fw-bold">Désactiver « <?= h($e['nom']) ?> »</div>
      <div class="small text-muted2">
        Plus personne ne pourra se connecter à cette école, et elle disparaîtra de la liste
        déroulante du formulaire de connexion. Réversible à tout moment (bouton « Réactiver »,
        sans mot de passe).
      </div>
    </div>
  </div>
  <form method="post">
    <?= csrf_champ() ?>
    <input type="hidden" name="action" value="desactiver">
    <div class="mb-3">
      <label class="form-label small fw-bold">Confirmez avec votre mot de passe</label>
      <input type="password" name="mdp" class="form-control form-control-sm" autocomplete="current-password" required autofocus>
    </div>
    <button class="btn btn-danger btn-sm"><i class="bi bi-slash-circle me-1"></i>Désactiver cet établissement</button>
    <a href="<?= APP_URL ?>/association/etablissement.php?id=<?= (int) $e['id'] ?>" class="btn btn-outline-light btn-sm ms-2">Annuler</a>
  </form>
</div>
<?php else: ?>
<div class="asso-card mt-2" style="max-width:520px">
  <div class="d-flex align-items-start gap-2 mb-3">
    <i class="bi bi-info-circle-fill text-primary" style="font-size:1.4rem"></i>
    <div>
      <div class="fw-bold">« <?= h($e['nom']) ?> » est actuellement désactivé</div>
      <div class="small text-muted2">Personne ne peut s'y connecter et il n'apparaît pas dans la liste de connexion.</div>
    </div>
  </div>
  <form method="post">
    <?= csrf_champ() ?>
    <input type="hidden" name="action" value="reactiver">
    <button class="btn btn-primary btn-sm"><i class="bi bi-check-circle me-1"></i>Réactiver cet établissement</button>
    <a href="<?= APP_URL ?>/association/etablissement.php?id=<?= (int) $e['id'] ?>" class="btn btn-outline-light btn-sm ms-2">Annuler</a>
  </form>
</div>
<?php endif; ?>
<?php asso_bas();
