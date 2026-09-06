<?php
// association/niu/creer.php — frappe manuelle d'un NIU par un membre
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/../_layout.php';
exiger_superadmin_association();

$ecoles = assoc_all("SELECT id, code, sigle, nom FROM etablissement WHERE actif=1 ORDER BY nom");

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
            // Préfixe : PMC + sigle école + code année scolaire courante
            $sigle   = strtoupper(trim($ecole['sigle'] ?: $ecole['code']));
            $annee   = (int) date('Y') - ((int) date('n') < 8 ? 1 : 0);
            $code_an = substr((string) $annee, 2, 2);
            $base    = 'PMC' . $sigle . $code_an;
            for ($i = 0; $i < 6; $i++) {
                $max = (int) assoc_val(
                    "SELECT MAX(CAST(SUBSTRING(niu, ?) AS UNSIGNED)) FROM eleve_niu WHERE niu REGEXP ?",
                    [strlen($base) + 1, '^' . preg_quote($base) . '[0-9]{4}$']
                );
                $niu = $base . str_pad((string) ($i < 3 ? $max + 1 : random_int(1, 9999)), 4, '0', STR_PAD_LEFT);
                try {
                    assoc_exec(
                        "INSERT INTO eleve_niu (niu, nom, prenom, date_naissance, sexe, lieu_naissance,
                                id_etab_origine, id_etab_courant, statut, cree_par)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'reserve', ?)",
                        [$niu, $val['nom'], $val['prenom'], $val['date_naissance'] ?: null, $val['sexe'],
                         $val['lieu_naissance'] ?: null, $ecole['id'], $ecole['id'],
                         'membre:' . (membre_connecte()['login'] ?? '?')]
                    );
                    assoc_exec("INSERT INTO eleve_niu_mouvement (niu, id_etab_cible, type, par) VALUES (?, ?, 'creation', ?)",
                        [$niu, $ecole['id'], 'membre:' . (membre_connecte()['login'] ?? '?')]);
                    journaliser_action('niu_creation', (int) $ecole['id'], $niu);
                    $niu_cree = $niu;
                    $msg = "NIU créé : $niu — à communiquer à l'établissement pour finaliser l'inscription.";
                    $val = ['nom' => '', 'prenom' => '', 'date_naissance' => '', 'sexe' => 'Masculin', 'lieu_naissance' => '', 'id_etab' => $val['id_etab']];
                    break;
                } catch (\Throwable $e) { /* collision PK → on retente */ }
            }
            if (!$niu_cree && !$err) $err = 'Impossible de générer un NIU unique, réessayez.';
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
