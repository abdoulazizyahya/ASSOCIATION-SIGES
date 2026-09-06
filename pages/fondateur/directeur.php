<?php
// pages/fondateur/directeur.php — SEULE page où le FONDATEUR peut écrire.
//
// Le FONDATEUR consulte toute son école en lecture seule
// (ecole_contexte.php::est_lecture_seule() + fondateur_ecriture_permise()) ;
// ici il gère UNIQUEMENT le compte DIRECTEUR de son école :
//   • enregistrer un nouveau directeur (fiche personnel + compte user) ;
//   • promouvoir un membre du personnel existant au poste de directeur ;
//   • désactiver / réactiver un directeur (le remplacer) ;
//   • rétrograder un directeur en enseignant.
//
// Cette page est dans la liste blanche fondateur_ecriture_permise() : pour
// un FONDATEUR, db_exec()/csrf_verifier() y sont donc autorisés.
header('Cache-Control: no-store, no-cache, must-revalidate');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_role(['FONDATEUR']);

// Un membre association en visite ne doit pas écrire ici (lecture seule).
$peut_ecrire = (function_exists('est_fondateur') && est_fondateur())
    && !(function_exists('est_lecture_seule') && est_lecture_seule());

/** Génère un login unique à partir de l'identité (initiale prénom + nom). */
function fondateur_login_unique(string $nom, string $prenom): string {
    $slug = strtolower(preg_replace('/[^a-z0-9]/i', '', substr($prenom, 0, 1) . ($nom ?: 'directeur')));
    $slug = $slug !== '' ? $slug : 'directeur';
    $login = $slug; $i = 1;
    while (db_val("SELECT COUNT(*) FROM user WHERE login_user=?", [$login])) {
        $login = $slug . (++$i);
    }
    return $login;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    if (!$peut_ecrire) {
        flash_set('erreur', "Action non autorisée.");
        rediriger('pages/fondateur/directeur.php');
    }
    $action = post('action');

    if ($action === 'creer') {
        $nom    = mb_strtoupper(post('nom'));
        $prenom = post('prenom');
        $sexe   = in_array(post('sexe'), ['M', 'F'], true) ? post('sexe') : null;
        $tel    = post('tel') ?: null;
        $email  = post('email') ?: null;
        if ($nom === '') {
            flash_set('erreur', 'Le nom est obligatoire.');
        } else {
            db_exec(
                "INSERT INTO enseignant (nom_ens, prenom_ens, sexe_ens, tel_ens, mail_ens, id_fonction, statut_ens)
                 VALUES (?, ?, ?, ?, ?, 'DIRECTEUR', 'actif')",
                [$nom, $prenom ?: null, $sexe, $tel, $email]
            );
            $mat_ens   = db_last_id();
            $login     = fondateur_login_unique($nom, $prenom);
            $mdp_clair = bin2hex(random_bytes(4));
            db_exec(
                "INSERT INTO user (login_user, pwd_user, matricule_ens) VALUES (?, ?, ?)",
                [$login, password_hash($mdp_clair, PASSWORD_DEFAULT), $mat_ens]
            );
            if (function_exists('journaliser_action')) {
                $ec = function_exists('ecole_courante') ? ecole_courante() : null;
                journaliser_action('directeur_cree', $ec['id'] ?? null, $login);
            }
            flash_set('succes', "Directeur enregistré. Identifiant « $login » — mot de passe temporaire : $mdp_clair (à changer à la 1re connexion).");
        }
        rediriger('pages/fondateur/directeur.php');
    }

    if ($action === 'promouvoir') {
        $mat_ens = (int) post('matricule_ens');
        $ex = $mat_ens ? db_one("SELECT * FROM enseignant WHERE matricule_ens=?", [$mat_ens]) : null;
        if (!$ex) {
            flash_set('erreur', 'Membre du personnel introuvable.');
        } else {
            db_exec("UPDATE enseignant SET id_fonction='DIRECTEUR', statut_ens='actif' WHERE matricule_ens=?", [$mat_ens]);
            // Crée un compte si le membre n'en a pas encore.
            $msg = "« " . trim(mb_strtoupper($ex['nom_ens']) . ' ' . ($ex['prenom_ens'] ?? '')) . " » est désormais directeur.";
            if (!db_val("SELECT COUNT(*) FROM user WHERE matricule_ens=?", [$mat_ens])) {
                $login     = fondateur_login_unique($ex['nom_ens'], $ex['prenom_ens'] ?? '');
                $mdp_clair = bin2hex(random_bytes(4));
                db_exec("INSERT INTO user (login_user, pwd_user, matricule_ens) VALUES (?, ?, ?)",
                        [$login, password_hash($mdp_clair, PASSWORD_DEFAULT), $mat_ens]);
                $msg .= " Identifiant « $login » — mot de passe temporaire : $mdp_clair.";
            }
            flash_set('succes', $msg);
        }
        rediriger('pages/fondateur/directeur.php');
    }

    if (in_array($action, ['desactiver', 'reactiver', 'retrograder'], true)) {
        $mat_ens = (int) post('matricule_ens');
        $ex = $mat_ens ? db_one("SELECT * FROM enseignant WHERE matricule_ens=? AND id_fonction='DIRECTEUR'", [$mat_ens]) : null;
        if (!$ex) {
            flash_set('erreur', 'Directeur introuvable.');
        } elseif ($action === 'desactiver') {
            db_exec("UPDATE enseignant SET statut_ens='inactif' WHERE matricule_ens=?", [$mat_ens]);
            flash_set('succes', 'Compte directeur désactivé.');
        } elseif ($action === 'reactiver') {
            db_exec("UPDATE enseignant SET statut_ens='actif' WHERE matricule_ens=?", [$mat_ens]);
            flash_set('succes', 'Compte directeur réactivé.');
        } else { // retrograder
            db_exec("UPDATE enseignant SET id_fonction='ENSEIGNANT' WHERE matricule_ens=?", [$mat_ens]);
            flash_set('succes', 'Ancien directeur rétrogradé en enseignant.');
        }
        rediriger('pages/fondateur/directeur.php');
    }

    rediriger('pages/fondateur/directeur.php');
}

$directeurs = db_all(
    "SELECT e.matricule_ens, e.nom_ens, e.prenom_ens, e.sexe_ens, e.tel_ens, e.mail_ens,
            COALESCE(e.statut_ens,'actif') AS statut_ens, u.login_user
     FROM enseignant e
     LEFT JOIN user u ON u.matricule_ens = e.matricule_ens
     WHERE e.id_fonction='DIRECTEUR'
     ORDER BY (COALESCE(e.statut_ens,'actif')='actif') DESC, e.nom_ens"
);

// Personnel actif non-directeur, promouvable.
$promouvables = db_all(
    "SELECT matricule_ens, nom_ens, prenom_ens, id_fonction
     FROM enseignant
     WHERE COALESCE(statut_ens,'actif')='actif' AND COALESCE(id_fonction,'')<>'DIRECTEUR'
     ORDER BY nom_ens, prenom_ens"
);

$titre_page = 'Directeur de l\'établissement';
require_once __DIR__ . '/../../layout/header.php';
?>

<div class="page-titre">
  <div>
    <h4><i class="bi bi-person-badge me-1 text-primary"></i>Directeur de l'établissement</h4>
    <div class="sub">Espace fondateur — enregistrer, remplacer ou désactiver le directeur</div>
  </div>
</div>

<?= flash_html() ?>

<div class="card mb-3">
  <div class="card-header py-2 fw-semibold"><i class="bi bi-people me-1"></i>Directeur(s) enregistré(s)</div>
  <div class="table-responsive">
    <table class="table table-abz align-middle mb-0" style="font-size:.85rem">
      <thead><tr><th>Nom</th><th>Identifiant</th><th>Contact</th><th>Statut</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
        <?php if (!$directeurs): ?>
          <tr><td colspan="5" class="text-center text-muted py-4">
            <i class="bi bi-inbox d-block mb-1" style="font-size:1.8rem;opacity:.3"></i>
            Aucun directeur enregistré pour le moment.
          </td></tr>
        <?php else: foreach ($directeurs as $d): $actif = $d['statut_ens'] === 'actif'; ?>
          <tr>
            <td class="fw-semibold"><?= h(mb_strtoupper($d['nom_ens'])) ?> <?= h($d['prenom_ens'] ?? '') ?></td>
            <td><span class="badge-code"><?= h($d['login_user'] ?: '— aucun compte —') ?></span></td>
            <td style="font-size:.78rem"><?= h($d['tel_ens'] ?: '') ?><?= $d['mail_ens'] ? '<br>' . h($d['mail_ens']) : '' ?></td>
            <td>
              <span class="badge" style="background:<?= $actif ? '#dcfce7' : '#fee2e2' ?>;color:<?= $actif ? '#166534' : '#991b1b' ?>">
                <?= $actif ? 'Actif' : 'Inactif' ?>
              </span>
            </td>
            <td class="text-end">
              <?php if ($peut_ecrire): ?>
              <div class="d-inline-flex gap-1">
                <?php if ($actif): ?>
                  <form method="post" onsubmit="return confirm('Désactiver ce directeur ?')">
                    <?= csrf_champ() ?>
                    <input type="hidden" name="action" value="desactiver">
                    <input type="hidden" name="matricule_ens" value="<?= (int) $d['matricule_ens'] ?>">
                    <button class="btn btn-sm btn-outline-danger py-0"><i class="bi bi-x-circle me-1"></i>Désactiver</button>
                  </form>
                <?php else: ?>
                  <form method="post">
                    <?= csrf_champ() ?>
                    <input type="hidden" name="action" value="reactiver">
                    <input type="hidden" name="matricule_ens" value="<?= (int) $d['matricule_ens'] ?>">
                    <button class="btn btn-sm btn-outline-success py-0"><i class="bi bi-check-circle me-1"></i>Réactiver</button>
                  </form>
                <?php endif; ?>
                <form method="post" onsubmit="return confirm('Rétrograder cet ancien directeur en enseignant ?')">
                  <?= csrf_champ() ?>
                  <input type="hidden" name="action" value="retrograder">
                  <input type="hidden" name="matricule_ens" value="<?= (int) $d['matricule_ens'] ?>">
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
      <div class="card-header py-2 fw-semibold"><i class="bi bi-person-plus me-1"></i>Enregistrer un nouveau directeur</div>
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
          <div class="col-md-4">
            <label class="form-label">Sexe</label>
            <select name="sexe" class="form-select form-select-sm">
              <option value="">—</option><option value="M">Masculin</option><option value="F">Féminin</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Téléphone</label>
            <input type="text" name="tel" class="form-control form-control-sm">
          </div>
          <div class="col-md-4">
            <label class="form-label">E-mail</label>
            <input type="email" name="email" class="form-control form-control-sm">
          </div>
          <div class="col-12">
            <button class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Enregistrer</button>
            <span class="text-muted ms-2" style="font-size:.75rem">Un compte est créé, avec un mot de passe temporaire affiché ensuite.</span>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="card h-100">
      <div class="card-header py-2 fw-semibold"><i class="bi bi-arrow-up-circle me-1"></i>Promouvoir un membre du personnel</div>
      <div class="card-body">
        <?php if (!$promouvables): ?>
          <p class="text-muted mb-0" style="font-size:.85rem">Aucun membre du personnel actif à promouvoir.</p>
        <?php else: ?>
        <form method="post" class="row g-2" onsubmit="return confirm('Nommer cette personne directeur ?')">
          <?= csrf_champ() ?>
          <input type="hidden" name="action" value="promouvoir">
          <div class="col-12">
            <label class="form-label">Membre du personnel</label>
            <select name="matricule_ens" class="form-select form-select-sm" required>
              <option value="">— Choisir —</option>
              <?php foreach ($promouvables as $p): ?>
                <option value="<?= (int) $p['matricule_ens'] ?>">
                  <?= h(mb_strtoupper($p['nom_ens'])) ?> <?= h($p['prenom_ens'] ?? '') ?>
                  <?= $p['id_fonction'] ? '(' . h(libelle_role($p['id_fonction'])) . ')' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <button class="btn btn-outline-primary btn-sm"><i class="bi bi-arrow-up me-1"></i>Nommer directeur</button>
          </div>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../layout/footer.php'; ?>
