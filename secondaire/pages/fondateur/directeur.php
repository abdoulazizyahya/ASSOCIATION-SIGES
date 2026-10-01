<?php
// secondaire/pages/fondateur/directeur.php — SEULE page où le FONDATEUR
// peut écrire, côté secondaire. Porté depuis pages/fondateur/directeur.php
// (primaire) le 24/09/2026, adapté au schéma secondaire : une SEULE table
// `utilisateur` (id/login/mot_de_passe/role), pas de fiche enseignant
// obligatoire (matricule_ens nullable) — donc pas de INSERT enseignant ici,
// contrairement au primaire. Gère le compte PROVISEUR de l'école (affiché
// « Principal(e) » ou « Proviseur(e) » selon etablissement.statut, voir
// libelle_role()) :
//   • enregistrer un nouveau compte PROVISEUR (identifiant + mot de passe) ;
//   • promouvoir un compte existant (ADMIN/CENSEUR/SG/SECRETAIRE/…) au rôle
//     PROVISEUR ;
//   • désactiver / réactiver un PROVISEUR (le remplacer) ;
//   • rétrograder un PROVISEUR en ENSEIGNANT.
//
// Le FONDATEUR consulte toute son école en lecture seule
// (ecole_contexte.php::est_lecture_seule() + fondateur_ecriture_permise()) ;
// cette page est dans sa liste blanche.
header('Cache-Control: no-store, no-cache, must-revalidate');
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_role(['FONDATEUR']);

// Un membre association en visite ne doit pas écrire ici (lecture seule).
$peut_ecrire = (function_exists('est_fondateur') && est_fondateur())
    && !(function_exists('est_lecture_seule') && est_lecture_seule());

/** Génère un login unique à partir de l'identité (initiale prénom + nom). */
function fondateur_login_unique_secondaire(string $nom, string $prenom): string {
    $slug = strtolower(preg_replace('/[^a-z0-9]/i', '', substr($prenom, 0, 1) . ($nom ?: 'proviseur')));
    $slug = $slug !== '' ? $slug : 'proviseur';
    $login = $slug; $i = 1;
    while (db_val("SELECT COUNT(*) FROM utilisateur WHERE login=?", [$login])) {
        $login = $slug . (++$i);
    }
    return $login;
}

$libelle_poste = libelle_role('PROVISEUR'); // « Principal(e) » ou « Proviseur(e) » selon le statut

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    if (!$peut_ecrire) {
        flash_set('erreur', "Action non autorisée.");
        rediriger('secondaire/pages/fondateur/directeur.php');
    }
    $action = post('action');

    // Un et un seul chef d'établissement (01/10/2026) : nommer quelqu'un
    // rétrograde en enseignant TOUT autre compte PROVISEUR (actif ou non) —
    // le compte est conservé. Retourne les noms rétrogradés.
    $retrograder_autres = function (int $garde): array {
        $noms = array_column(chefs_etablissement($garde), 'nom');
        db_exec("UPDATE utilisateur SET role='ENSEIGNANT' WHERE role='PROVISEUR' AND id<>?", [$garde]);
        return $noms;
    };
    $note_remplaces = fn(array $noms): string => $noms
        ? ' Ancien ' . $libelle_poste . ' rétrogradé en enseignant : ' . implode(', ', array_map('trim', $noms)) . '.' : '';

    if ($action === 'creer') {
        $nom    = mb_strtoupper(post('nom'));
        $prenom = post('prenom');
        $email  = post('email') ?: null;
        if ($nom === '') {
            flash_set('erreur', 'Le nom est obligatoire.');
        } else {
            $login     = fondateur_login_unique_secondaire($nom, $prenom);
            $mdp_clair = bin2hex(random_bytes(4));
            db_exec(
                "INSERT INTO utilisateur (nom, prenom, login, mot_de_passe, role, actif, email)
                 VALUES (?, ?, ?, ?, 'PROVISEUR', 1, ?)",
                [$nom, $prenom ?: null, $login, password_hash($mdp_clair, PASSWORD_DEFAULT), $email]
            );
            $remplaces = $retrograder_autres((int) db_last_id());
            if (function_exists('journaliser_action')) {
                $ec = function_exists('ecole_courante') ? ecole_courante() : null;
                journaliser_action('proviseur_cree', $ec['id'] ?? null, $login);
            }
            flash_set('succes', ucfirst($libelle_poste) . " enregistré. Identifiant « $login » — mot de passe temporaire : $mdp_clair (à changer à la 1re connexion)." . $note_remplaces($remplaces));
        }
        rediriger('secondaire/pages/fondateur/directeur.php');
    }

    if ($action === 'promouvoir') {
        $id = (int) post('id_utilisateur');
        $ex = $id ? db_one("SELECT * FROM utilisateur WHERE id=?", [$id]) : null;
        if (!$ex) {
            flash_set('erreur', 'Compte introuvable.');
        } else {
            db_exec("UPDATE utilisateur SET role='PROVISEUR', actif=1 WHERE id=?", [$id]);
            $remplaces = $retrograder_autres($id);
            flash_set('succes', "« " . trim(mb_strtoupper($ex['nom']) . ' ' . ($ex['prenom'] ?? '')) . " » est désormais " . $libelle_poste . "." . $note_remplaces($remplaces));
        }
        rediriger('secondaire/pages/fondateur/directeur.php');
    }

    // Plusieurs comptes PROVISEUR hérités d'avant la règle : garder celui-ci seul.
    if ($action === 'garder') {
        $id = (int) post('id_utilisateur');
        if ($id && db_val("SELECT COUNT(*) FROM utilisateur WHERE id=? AND role='PROVISEUR'", [$id])) {
            db_exec("UPDATE utilisateur SET actif=1 WHERE id=?", [$id]);
            flash_set('succes', ucfirst($libelle_poste) . ' unique confirmé.' . $note_remplaces($retrograder_autres($id)));
        } else {
            flash_set('erreur', ucfirst($libelle_poste) . ' introuvable.');
        }
        rediriger('secondaire/pages/fondateur/directeur.php');
    }

    if (in_array($action, ['desactiver', 'reactiver', 'retrograder'], true)) {
        $id = (int) post('id_utilisateur');
        $ex = $id ? db_one("SELECT * FROM utilisateur WHERE id=? AND role='PROVISEUR'", [$id]) : null;
        if (!$ex) {
            flash_set('erreur', ucfirst($libelle_poste) . " introuvable.");
        } elseif ($action === 'desactiver') {
            db_exec("UPDATE utilisateur SET actif=0 WHERE id=?", [$id]);
            flash_set('succes', 'Compte désactivé.');
        } elseif ($action === 'reactiver') {
            db_exec("UPDATE utilisateur SET actif=1 WHERE id=?", [$id]);
            flash_set('succes', 'Compte réactivé.');
        } else { // retrograder
            db_exec("UPDATE utilisateur SET role='ENSEIGNANT' WHERE id=?", [$id]);
            flash_set('succes', 'Ancien ' . $libelle_poste . ' rétrogradé en enseignant.');
        }
        rediriger('secondaire/pages/fondateur/directeur.php');
    }

    rediriger('secondaire/pages/fondateur/directeur.php');
}

$directeurs = db_all(
    "SELECT id, nom, prenom, email, actif, login
     FROM utilisateur
     WHERE role='PROVISEUR'
     ORDER BY actif DESC, nom"
);

// Comptes actifs promouvables (tout rôle sauf PROVISEUR lui-même).
$promouvables = db_all(
    "SELECT id, nom, prenom, role
     FROM utilisateur
     WHERE actif=1 AND role<>'PROVISEUR'
     ORDER BY nom, prenom"
);

$titre_page = ucfirst($libelle_poste) . ' de l\'établissement';
require_once __DIR__ . '/../../../layout/header.php';
?>

<div class="page-titre">
  <div>
    <h4><i class="bi bi-person-badge me-1 text-primary"></i><?= h(ucfirst($libelle_poste)) ?> de l'établissement</h4>
    <div class="sub">Espace fondateur — enregistrer, remplacer ou désactiver le <?= h($libelle_poste) ?></div>
  </div>
</div>

<?= flash_html() ?>

<?php if (count($directeurs) > 1): ?>
<div class="alert alert-warning py-2" style="font-size:.85rem">
  <i class="bi bi-exclamation-triangle me-1"></i>
  <strong><?= count($directeurs) ?> comptes <?= h($libelle_poste) ?></strong> sont enregistrés, or une école n'en a qu'<strong>un seul</strong>.
  Cliquez sur « Garder seul » devant le bon compte : les autres seront rétrogradés en enseignant (leurs comptes restent).
</div>
<?php endif; ?>

<div class="card mb-3">
  <div class="card-header py-2 fw-semibold"><i class="bi bi-people me-1"></i><?= h(ucfirst($libelle_poste)) ?>(s) enregistré(s)</div>
  <div class="table-responsive">
    <table class="table table-abz align-middle mb-0" style="font-size:.85rem">
      <thead><tr><th>Nom</th><th>Identifiant</th><th>Contact</th><th>Statut</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
        <?php if (!$directeurs): ?>
          <tr><td colspan="5" class="text-center text-muted py-4">
            <i class="bi bi-inbox d-block mb-1" style="font-size:1.8rem;opacity:.3"></i>
            Aucun <?= h($libelle_poste) ?> enregistré pour le moment.
          </td></tr>
        <?php else: foreach ($directeurs as $d): $actif = (bool) $d['actif']; ?>
          <tr>
            <td class="fw-semibold"><?= h(mb_strtoupper($d['nom'])) ?> <?= h($d['prenom'] ?? '') ?></td>
            <td><span class="badge-code"><?= h($d['login']) ?></span></td>
            <td style="font-size:.78rem"><?= h($d['email'] ?: '') ?></td>
            <td>
              <span class="badge" style="background:<?= $actif ? '#dcfce7' : '#fee2e2' ?>;color:<?= $actif ? '#166534' : '#991b1b' ?>">
                <?= $actif ? 'Actif' : 'Inactif' ?>
              </span>
            </td>
            <td class="text-end">
              <?php if ($peut_ecrire): ?>
              <div class="d-inline-flex gap-1">
                <?php if (count($directeurs) > 1): ?>
                  <form method="post" onsubmit="return confirm('Garder ce compte comme SEUL <?= h($libelle_poste) ?> ? Les autres seront rétrogradés en enseignant.')">
                    <?= csrf_champ() ?>
                    <input type="hidden" name="action" value="garder">
                    <input type="hidden" name="id_utilisateur" value="<?= (int) $d['id'] ?>">
                    <button class="btn btn-sm btn-primary py-0"><i class="bi bi-check2-circle me-1"></i>Garder seul</button>
                  </form>
                <?php endif; ?>
                <?php if ($actif): ?>
                  <form method="post" onsubmit="return confirm('Désactiver ce compte ?')">
                    <?= csrf_champ() ?>
                    <input type="hidden" name="action" value="desactiver">
                    <input type="hidden" name="id_utilisateur" value="<?= (int) $d['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger py-0"><i class="bi bi-x-circle me-1"></i>Désactiver</button>
                  </form>
                <?php else: ?>
                  <form method="post">
                    <?= csrf_champ() ?>
                    <input type="hidden" name="action" value="reactiver">
                    <input type="hidden" name="id_utilisateur" value="<?= (int) $d['id'] ?>">
                    <button class="btn btn-sm btn-outline-success py-0"><i class="bi bi-check-circle me-1"></i>Réactiver</button>
                  </form>
                <?php endif; ?>
                <form method="post" onsubmit="return confirm('Rétrograder ce compte en enseignant ?')">
                  <?= csrf_champ() ?>
                  <input type="hidden" name="action" value="retrograder">
                  <input type="hidden" name="id_utilisateur" value="<?= (int) $d['id'] ?>">
                  <button class="btn btn-sm btn-outline-secondary py-0"><i class="bi bi-arrow-down me-1"></i>Rétrograder</button>
                </form>
              </div>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($peut_ecrire): ?>
<div class="row g-3">
  <div class="col-12 col-lg-6">
    <div class="card h-100">
      <div class="card-header py-2 fw-semibold"><i class="bi bi-person-plus me-1"></i>Enregistrer un nouveau <?= h($libelle_poste) ?></div>
      <div class="card-body">
        <form method="post" class="row g-2">
          <?= csrf_champ() ?>
          <input type="hidden" name="action" value="creer">
          <div class="col-md-6">
            <label class="form-label">Nom <span class="text-danger">*</span></label>
            <input type="text" name="nom" class="form-control form-control-sm" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Prénom(s)</label>
            <input type="text" name="prenom" class="form-control form-control-sm">
          </div>
          <div class="col-12">
            <label class="form-label">E-mail</label>
            <input type="email" name="email" class="form-control form-control-sm">
          </div>
          <div class="col-12">
            <button class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Enregistrer</button>
            <span class="text-muted ms-2" style="font-size:.75rem">Un compte est créé (mot de passe temporaire affiché ensuite). Le <?= h($libelle_poste) ?> actuel est remplacé : il redevient enseignant.</span>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="card h-100">
      <div class="card-header py-2 fw-semibold"><i class="bi bi-arrow-up-circle me-1"></i>Promouvoir un compte existant</div>
      <div class="card-body">
        <?php if (!$promouvables): ?>
          <p class="text-muted mb-0" style="font-size:.85rem">Aucun autre compte actif à promouvoir.</p>
        <?php else: ?>
        <form method="post" class="row g-2" onsubmit="return confirm('Nommer ce compte <?= h($libelle_poste) ?> ? Le compte actuel sera rétrogradé en enseignant.')">
          <?= csrf_champ() ?>
          <input type="hidden" name="action" value="promouvoir">
          <div class="col-12">
            <label class="form-label">Compte</label>
            <select name="id_utilisateur" class="form-select form-select-sm" required>
              <option value="">— Choisir —</option>
              <?php foreach ($promouvables as $p): ?>
                <option value="<?= (int) $p['id'] ?>">
                  <?= h(mb_strtoupper($p['nom'])) ?> <?= h($p['prenom'] ?? '') ?>
                  (<?= h(libelle_role($p['role'])) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <button class="btn btn-outline-primary btn-sm"><i class="bi bi-arrow-up me-1"></i>Nommer <?= h($libelle_poste) ?></button>
          </div>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../../layout/footer.php'; ?>
