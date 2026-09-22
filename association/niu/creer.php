<?php
// association/niu/creer.php — frappe manuelle d'un NIU par un membre
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/../_layout.php';
exiger_superadmin_association();

// Secondaire exclu : NIU manuel ici = format auto-généré PMC+sigle+année
// (assoc_niu_generer_pour() ci-dessous), non pertinent pour le secondaire
// dont le NIU est une simple information de fiche élève (voir
// association/niu/index.php). Demande explicite du 21/09/2026.
$ecoles = assoc_all(
    "SELECT id, code, sigle, nom FROM etablissement
     WHERE actif=1 AND COALESCE(type_enseignement,'primaire')<>'secondaire' ORDER BY nom"
);

$msg = ''; $err = ''; $niu_cree = '';
$val = ['nom' => '', 'prenom' => '', 'date_naissance' => '', 'sexe' => 'Masculin', 'lieu_naissance' => '', 'id_etab' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    foreach ($val as $k => $_) $val[$k] = trim($_POST[$k] ?? $val[$k]);
    $confirme = !empty($_POST['confirme_doublon']);

    $ecole = null;
    foreach ($ecoles as $e) if ((string) $e['id'] === $val['id_etab']) $ecole = $e;

    if ($val['nom'] === '' || $val['prenom'] === '' || !$ecole) {
        $err = 'Nom, prénom et établissement d\'origine sont obligatoires.';
    } else {
        // Déduplication sur identité
        $proches = assoc_all(
            "SELECT niu FROM eleve_niu WHERE LOWER(nom)=LOWER(?) AND LOWER(prenom)=LOWER(?)
             AND (date_naissance = ? OR ? = '')",
            [$val['nom'], $val['prenom'], $val['date_naissance'], $val['date_naissance']]
        );
        if ($proches && !$confirme) {
            $err = 'Un NIU existe déjà pour une identité identique : '
                 . implode(', ', array_column($proches, 'niu'))
                 . '. Cochez « créer quand même » pour forcer.';
        } else {
            // Format unifié (assoc_niu_generer_pour) : PMC + sigle 3 lettres
            // de l'école + année + n° d'ordre.
            $niu = assoc_niu_generer_pour((int) $ecole['id'], [
                'nom'        => $val['nom'],
                'prenom'     => $val['prenom'],
                'date_naiss' => $val['date_naissance'] ?: null,
                'sexe'       => $val['sexe'],
                'lieu_naiss' => $val['lieu_naissance'] ?: null,
            ], 'membre:' . (membre_connecte()['login'] ?? '?'));
            if ($niu) {
                // NIU manuel = élève pas encore inscrit → statut « reserve ».
                assoc_exec("UPDATE eleve_niu SET statut='reserve' WHERE niu=?", [$niu]);
                journaliser_action('niu_creation', (int) $ecole['id'], $niu);
                $niu_cree = $niu;
                $msg = "NIU créé : $niu — à communiquer à l'établissement pour finaliser l'inscription.";
                $val = ['nom' => '', 'prenom' => '', 'date_naissance' => '', 'sexe' => 'Masculin', 'lieu_naissance' => '', 'id_etab' => $val['id_etab']];
            } elseif (!$err) {
                $err = 'Impossible de générer un NIU unique, réessayez.';
            }
        }
    }
}

asso_haut('Nouveau NIU');
?>
<a href="<?= APP_URL ?>/association/niu/index.php" class="small text-decoration-none">← Registre NIU</a>

<?php if ($msg): ?><div class="alert alert-success py-2 small mt-2"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-warning py-2 small mt-2"><?= h($err) ?></div><?php endif; ?>

<div class="asso-card mt-2" style="max-width:520px">
  <form method="post" class="row g-2">
    <input type="hidden" name="csrf" value="<?= h(csrf_generer()) ?>">
    <div class="col-6"><label class="form-label small">Nom *</label>
      <input name="nom" class="form-control form-control-sm" value="<?= h($val['nom']) ?>" required></div>
    <div class="col-6"><label class="form-label small">Prénom *</label>
      <input name="prenom" class="form-control form-control-sm" value="<?= h($val['prenom']) ?>" required></div>
    <div class="col-6"><label class="form-label small">Date de naissance</label>
      <input name="date_naissance" type="date" class="form-control form-control-sm" value="<?= h($val['date_naissance']) ?>"></div>
    <div class="col-6"><label class="form-label small">Sexe</label>
      <select name="sexe" class="form-select form-select-sm">
        <option <?= $val['sexe'] === 'Masculin' ? 'selected' : '' ?>>Masculin</option>
        <option <?= $val['sexe'] === 'Feminin' ? 'selected' : '' ?>>Feminin</option>
      </select></div>
    <div class="col-12"><label class="form-label small">Lieu de naissance</label>
      <input name="lieu_naissance" class="form-control form-control-sm" value="<?= h($val['lieu_naissance']) ?>"></div>
    <div class="col-12"><label class="form-label small">Établissement d'origine *</label>
      <select name="id_etab" class="form-select form-select-sm" required>
        <option value="">— Choisir —</option>
        <?php foreach ($ecoles as $e): ?>
          <option value="<?= (int) $e['id'] ?>" <?= $val['id_etab'] === (string) $e['id'] ? 'selected' : '' ?>>
            <?= h($e['nom']) ?> (<?= h($e['sigle'] ?: $e['code']) ?>)
          </option>
        <?php endforeach; ?>
      </select></div>
    <div class="col-12 form-check ms-2">
      <input type="checkbox" name="confirme_doublon" value="1" class="form-check-input" id="cd">
      <label for="cd" class="form-check-label small">Créer quand même en cas de doublon d'identité</label>
    </div>
    <div class="col-12"><button class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Créer le NIU</button></div>
  </form>
</div>
<?php asso_bas();
