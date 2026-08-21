<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/connexion.php';
require_once __DIR__ . '/fonctions.php';
exiger_connexion();

$user_id  = (int)$_SESSION['user_id'];
$retour   = $_GET['retour'] ?? 'dashboard.php';
$obligatoire = !utilisateur_a_questions($user_id);

$questions_dispo = db_all("SELECT id, libelle FROM question_secrete WHERE actif = 1 ORDER BY libelle");
$actuelles       = db_all("SELECT id_question FROM utilisateur_question_secrete WHERE id_utilisateur = ?", [$user_id]);
$id_q1_actuel    = $actuelles[0]['id_question'] ?? '';
$id_q2_actuel    = $actuelles[1]['id_question'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $id_q1 = (int)post('id_question_1');
    $id_q2 = (int)post('id_question_2');
    $rep1  = post('reponse_1');
    $rep2  = post('reponse_2');

    if (!$id_q1 || !$id_q2 || $rep1 === '' || $rep2 === '') {
        flash_set('erreur', 'Veuillez choisir 2 questions et renseigner les 2 réponses.');
        rediriger('configurer_securite.php' . ($retour !== 'dashboard.php' ? '?retour=' . urlencode($retour) : ''));
    }
    if ($id_q1 === $id_q2) {
        flash_set('erreur', 'Les 2 questions doivent être différentes.');
        rediriger('configurer_securite.php' . ($retour !== 'dashboard.php' ? '?retour=' . urlencode($retour) : ''));
    }

    db_exec("DELETE FROM utilisateur_question_secrete WHERE id_utilisateur = ?", [$user_id]);
    db_exec("INSERT INTO utilisateur_question_secrete (id_utilisateur, id_question, reponse_hash) VALUES (?,?,?)",
            [$user_id, $id_q1, password_hash(normaliser_reponse($rep1), PASSWORD_DEFAULT)]);
    db_exec("INSERT INTO utilisateur_question_secrete (id_utilisateur, id_question, reponse_hash) VALUES (?,?,?)",
            [$user_id, $id_q2, password_hash(normaliser_reponse($rep2), PASSWORD_DEFAULT)]);

    flash_set('succes', 'Vos questions de sécurité ont été enregistrées.');
    rediriger($retour);
}

$titre_page = 'Questions de sécurité';
require_once __DIR__ . '/layout/header.php';
?>
<div class="page-titre d-flex justify-content-between align-items-center">
  <h4><i class="bi bi-shield-lock me-1 text-primary"></i><?= h($titre_page) ?></h4>
</div>

<?php if ($obligatoire): ?>
<div class="alert alert-warning py-2" style="font-size:.85rem">
  <i class="bi bi-exclamation-triangle me-1"></i>
  Configuration obligatoire avant de continuer : ces 2 questions permettront de récupérer votre mot de passe en cas d'oubli, sans passer par un administrateur.
</div>
<?php endif; ?>

<div class="card" style="max-width:640px">
  <div class="card-body">
    <form method="post">
      <?= csrf_champ() ?>
      <div class="mb-3">
        <label class="form-label">Question secrète 1</label>
        <select name="id_question_1" class="form-select" required>
          <option value="">— Choisir —</option>
          <?php foreach ($questions_dispo as $q): ?>
            <option value="<?= $q['id'] ?>" <?= (string)$q['id'] === (string)$id_q1_actuel ? 'selected' : '' ?>><?= h($q['libelle']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="mb-3">
        <label class="form-label">Réponse 1</label>
        <input type="text" name="reponse_1" class="form-control" required autocomplete="off">
      </div>
      <div class="mb-3">
        <label class="form-label">Question secrète 2</label>
        <select name="id_question_2" class="form-select" required>
          <option value="">— Choisir —</option>
          <?php foreach ($questions_dispo as $q): ?>
            <option value="<?= $q['id'] ?>" <?= (string)$q['id'] === (string)$id_q2_actuel ? 'selected' : '' ?>><?= h($q['libelle']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="mb-3">
        <label class="form-label">Réponse 2</label>
        <input type="text" name="reponse_2" class="form-control" required autocomplete="off">
      </div>
      <button class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Enregistrer</button>
      <?php if (!$obligatoire): ?>
        <a href="<?= APP_URL ?>/<?= h($retour) ?>" class="btn btn-light ms-1">Annuler</a>
      <?php endif; ?>
    </form>
  </div>
</div>
<?php require_once __DIR__ . '/layout/footer.php'; ?>
