<?php
// association/personnel/compte_secondaire.php — créer un compte de connexion
// dans une école SECONDAIRE (schema_ref_ecole_secondaire.sql, porté de
// LAM_ABZ). Pendant du couple affecter.php/agents_json.php (primaire), mais
// en plus simple : `utilisateur` est self-contained (nom/prenom/login/mot_
// de_passe/role directement dessus), pas de registre `personnel`/`enseignant`
// réseau à affecter comme en primaire — donc un simple formulaire de
// création, pas une « affectation » d'un agent déjà connu ailleurs.
//
// Raison d'être : creer_etablissement() ne crée AUCUN compte pour une
// école secondaire neuve (charger_schema_ecole() s'arrête avant, voir
// connexion_assoc.php) — sans cette page, une école secondaire n'a
// littéralement aucun moyen d'être utilisée (bug réel constaté le
// 15/09/2026 : 0 ligne dans `utilisateur` pour la 1ʳᵉ école secondaire créée).
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/../_layout.php';
exiger_superadmin_association();

$ecoles = assoc_all("SELECT id, code, nom FROM etablissement WHERE actif=1 AND type_enseignement='secondaire' ORDER BY nom");
$ROLES  = ['ADMIN', 'PROVISEUR', 'CENSEUR', 'SG', 'SECRETAIRE', 'ENSEIGNANT', 'INTENDANT'];

$id_cible = (int) ($_GET['etab'] ?? $_POST['id_cible'] ?? 0);
$msg = ''; $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $nom    = trim($_POST['nom'] ?? '');
    $prenom = trim($_POST['prenom'] ?? '');
    $login  = trim($_POST['login'] ?? '');
    $pwd    = (string) ($_POST['pwd'] ?? '');
    $role   = in_array($_POST['role'] ?? '', $ROLES, true) ? $_POST['role'] : 'SECRETAIRE';

    if (!$id_cible) {
        $err = 'Choisissez un établissement.';
    } elseif ($nom === '' || $login === '') {
        $err = 'Nom et identifiant sont obligatoires.';
    } elseif (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $login)) {
        $err = 'Identifiant : 3–50 caractères (lettres, chiffres, . _ -).';
    } elseif (strlen($pwd) < 4) {
        $err = 'Mot de passe : 4 caractères minimum.';
    } else {
        try {
            $existe = avec_ecole($id_cible, fn($l) => ecole_one($l, "SELECT id FROM utilisateur WHERE login=?", [$login]));
            if ($existe) {
                $err = "L'identifiant « $login » est déjà pris dans cette école.";
            } else {
                avec_ecole($id_cible, fn($l) => ecole_exec(
                    $l,
                    "INSERT INTO utilisateur (nom, prenom, login, mot_de_passe, role, actif) VALUES (?, ?, ?, ?, ?, 1)",
                    [$nom, $prenom ?: null, $login, password_hash($pwd, PASSWORD_DEFAULT), $role]
                ));
                journaliser_action('compte_ecole_creer_secondaire', $id_cible, $login . ' (' . $role . ')');
                $msg = "Compte « $login » créé (" . libelle_role($role) . ").";
            }
        } catch (\Throwable $ex) {
            $err = 'Erreur : ' . $ex->getMessage();
        }
    }
}

asso_haut('Créer un compte — école secondaire');
?>
<a href="<?= APP_URL ?>/association/personnel/liste.php" class="small text-decoration-none">← Personnel</a>
<?php if ($msg): ?><div class="alert alert-success py-2 small mt-2"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-warning py-2 small mt-2"><?= h($err) ?></div><?php endif; ?>

<?php if (!$ecoles): ?>
  <div class="alert alert-light border small mt-2">Aucune école secondaire dans l'annuaire pour l'instant.</div>
<?php else: ?>
<div class="asso-card mt-2" style="max-width:520px">
  <form method="post" class="row g-3">
    <input type="hidden" name="csrf" value="<?= h(csrf_generer()) ?>">

    <div class="col-12">
      <label class="form-label small fw-bold">Établissement</label>
      <select name="id_cible" class="form-select form-select-sm" required>
        <option value="">— Choisir —</option>
        <?php foreach ($ecoles as $e): ?>
          <option value="<?= (int) $e['id'] ?>" <?= $id_cible === (int) $e['id'] ? 'selected' : '' ?>><?= h($e['nom']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6">
      <label class="form-label small">Nom</label>
      <input type="text" name="nom" class="form-control form-control-sm" required>
    </div>
    <div class="col-6">
      <label class="form-label small">Prénom</label>
      <input type="text" name="prenom" class="form-control form-control-sm">
    </div>
    <div class="col-6">
      <label class="form-label small">Identifiant</label>
      <input type="text" name="login" class="form-control form-control-sm" required>
    </div>
    <div class="col-6">
      <label class="form-label small">Mot de passe</label>
      <input type="text" name="pwd" class="form-control form-control-sm" required>
    </div>
    <div class="col-12">
      <label class="form-label small">Rôle</label>
      <select name="role" class="form-select form-select-sm">
        <?php foreach ($ROLES as $r): ?>
          <option value="<?= $r ?>"><?= h(libelle_role($r)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="col-12">
      <button class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Créer le compte</button>
    </div>
  </form>
</div>
<?php endif; ?>
<?php asso_bas();
