<?php
// association/acces.php — Module « Privilèges » : régler, par école, ce que
// chaque rôle ou chaque compte peut voir / faire (Masqué · Lecture · Écriture)
// sur chaque menu / sous-menu. Superadmin uniquement.
// Stockage : promeducam_assoc.acces_regle — appliqué côté école par
// ecole_contexte.php::regles_centrales() / niveau_central().
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
require_once __DIR__ . '/_layout.php';
exiger_superadmin_association();

// MEMBRE_ASSOCIATION : rôle synthétique d'un Membre/Superviseur de
// l'association en visite dans cette école (association/entrer_ecole.php).
// Un Administrateur (superadmin) n'est jamais concerné par ce module —
// voir regles_centrales(). Par défaut, Utilisateurs et Paramètres école y
// sont masqués (bd/assoc/maj_assoc.php / assoc_seeder_masque_visite()) ;
// réglable ici comme n'importe quel rôle. Demande explicite du 23/09/2026.
$ROLES = ['DIRECTEUR', 'FONDATEUR', 'COMPTABLE', 'SECRETAIRE', 'ENSEIGNANT', 'MEMBRE_ASSOCIATION'];

$ecoles = assoc_all("SELECT id, code, nom FROM etablissement WHERE actif = 1 ORDER BY nom");

$id_etab = (int) ($_GET['etab'] ?? $_POST['etab'] ?? 0);
$portee  = ($_GET['portee'] ?? $_POST['portee'] ?? 'role') === 'user' ? 'user' : 'role';
$cible   = trim($_GET['cible'] ?? $_POST['cible'] ?? '');

$etab = $id_etab ? assoc_one("SELECT * FROM etablissement WHERE id = ?", [$id_etab]) : null;

// Comptes de l'école choisie (pour la portée « compte »).
$comptes = [];
if ($etab) {
    try {
        $comptes = avec_ecole($id_etab, fn($l) => ecole_all($l,
            "SELECT u.login_user, e.id_fonction, e.nom_ens, e.prenom_ens
             FROM user u JOIN enseignant e ON e.matricule_ens = u.matricule_ens
             ORDER BY e.id_fonction, e.nom_ens"));
    } catch (\Throwable $e) {
        $comptes = [];
    }
}

$menu = menu_definition();

// ── Enregistrement ──────────────────────────────────────────────────
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['op'] ?? '') === 'save') {
    csrf_verifier();
    if (!$etab || $cible === '') {
        $msg = 'Sélectionnez une école et une cible.';
    } else {
        // Clés valides = groupes + entrées réellement présentes dans le menu.
        $valides = [];
        foreach ($menu as $g => $items) {
            $valides['grp:' . $g] = true;
            foreach ($items as $it) {
                if (($it[0] ?? '') === '--') continue;
                $valides[$it[1]] = true;
            }
        }
        $regles = [];
        foreach ((array) ($_POST['regle'] ?? []) as $cle => $niv) {
            $cle = (string) $cle;
            if (isset($valides[$cle]) && in_array($niv, ['masque', 'lecture', 'ecriture'], true)) {
                $regles[$cle] = $niv;
            }
        }
        acces_regle_definir($id_etab, $portee, $cible, $regles);
        journaliser_action('privilege_regle', $id_etab,
            "$portee:$cible — " . count($regles) . ' règle(s)');
        $msg = count($regles)
            ? count($regles) . ' règle(s) enregistrée(s) pour « ' . $cible . ' » (' . $etab['code'] . ').'
            : 'Toutes les règles ont été levées pour « ' . $cible . ' » (retour au défaut du rôle).';
    }
}

// ── Règles actuelles du couple (école, portée, cible) ───────────────
$actuel = [];
if ($etab && $cible !== '') {
    foreach (acces_regle_lister($id_etab) as $r) {
        if ($r['portee'] === $portee && $r['cible'] === $cible) $actuel[$r['cle']] = $r['niveau'];
    }
}

// Défaut « visible » pour un rôle donné (rappel visuel).
$defaut_visible = function (array $roles_entree) use ($portee, $cible, $comptes): bool {
    if (empty($roles_entree)) return true;
    if ($portee === 'role') return in_array($cible, $roles_entree, true);
    // portée compte : on regarde le rôle du compte
    foreach ($comptes as $c) {
        if ($c['login_user'] === $cible) return in_array($c['id_fonction'], $roles_entree, true);
    }
    return false;
};

$qs = fn(array $x) => http_build_query(array_merge(
    ['etab' => $id_etab, 'portee' => $portee, 'cible' => $cible], $x));

asso_haut('Privilèges par école');
?>
<?php if ($msg): ?><div class="alert alert-info py-2 small"><?= h($msg) ?></div><?php endif; ?>

<form method="get" class="asso-card mb-3">
  <div class="row g-2 align-items-end">
    <div class="col-12 col-md-4">
      <label class="form-label small">École</label>
      <select name="etab" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="">— choisir —</option>
        <?php foreach ($ecoles as $e): ?>
          <option value="<?= (int) $e['id'] ?>" <?= $id_etab === (int) $e['id'] ? 'selected' : '' ?>>
            <?= h($e['code'] . ' — ' . $e['nom']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-3">
      <label class="form-label small">Portée</label>
      <select name="portee" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="role" <?= $portee === 'role' ? 'selected' : '' ?>>Par rôle</option>
        <option value="user" <?= $portee === 'user' ? 'selected' : '' ?>>Par compte</option>
      </select>
    </div>
    <div class="col-6 col-md-4">
      <label class="form-label small"><?= $portee === 'user' ? 'Compte' : 'Rôle' ?></label>
      <select name="cible" class="form-select form-select-sm" onchange="this.form.submit()" <?= $etab ? '' : 'disabled' ?>>
        <option value="">— choisir —</option>
        <?php if ($portee === 'role'): ?>
          <?php foreach ($ROLES as $r): ?>
            <option value="<?= h($r) ?>" <?= $cible === $r ? 'selected' : '' ?>><?= h(libelle_role($r)) ?></option>
          <?php endforeach; ?>
        <?php else: ?>
          <?php foreach ($comptes as $c): ?>
            <option value="<?= h($c['login_user']) ?>" <?= $cible === $c['login_user'] ? 'selected' : '' ?>>
              <?= h($c['login_user'] . ' — ' . mb_strtoupper($c['nom_ens']) . ' ' . ($c['prenom_ens'] ?? '') . ' (' . libelle_role($c['id_fonction']) . ')') ?>
            </option>
          <?php endforeach; ?>
        <?php endif; ?>
      </select>
    </div>
  </div>
</form>

<?php if ($etab && $cible !== ''): ?>
<form method="post" class="asso-card">
  <input type="hidden" name="csrf" value="<?= h(csrf_generer()) ?>">
  <input type="hidden" name="op"   value="save">
  <input type="hidden" name="etab"   value="<?= $id_etab ?>">
  <input type="hidden" name="portee" value="<?= h($portee) ?>">
  <input type="hidden" name="cible"  value="<?= h($cible) ?>">

  <p class="small text-muted2 mb-3">
    École <strong><?= h($etab['code']) ?></strong> — <?= $portee === 'user' ? 'compte' : 'rôle' ?>
    <strong><?= h($portee === 'role' ? libelle_role($cible) : $cible) ?></strong>.
    « Défaut » = comportement normal du rôle. Les 3 autres niveaux le remplacent
    (y compris pour <em>accorder</em> un accès au-delà du rôle).
  </p>

  <div class="table-responsive">
  <table class="table table-sm align-middle mb-0" style="font-size:.83rem">
    <thead><tr class="text-muted2"><th>Menu / entrée</th><th style="width:130px">Défaut</th><th style="width:190px">Règle</th></tr></thead>
    <tbody>
      <?php foreach ($menu as $groupe => $items): ?>
        <?php $cg = 'grp:' . $groupe; $ng = $actuel[$cg] ?? ''; ?>
        <tr class="table-light">
          <td class="fw-bold"><i class="bi bi-collection me-1"></i><?= h($groupe) ?> <span class="text-muted2 fw-normal">(tout le groupe)</span></td>
          <td class="text-muted2">—</td>
          <td>
            <select name="regle[<?= h($cg) ?>]" class="form-select form-select-sm">
              <option value="">Défaut</option>
              <option value="ecriture" <?= $ng === 'ecriture' ? 'selected' : '' ?>>Écriture</option>
              <option value="lecture"  <?= $ng === 'lecture'  ? 'selected' : '' ?>>Lecture seule</option>
              <option value="masque"   <?= $ng === 'masque'   ? 'selected' : '' ?>>Masqué</option>
            </select>
          </td>
        </tr>
        <?php foreach ($items as $it): ?>
          <?php if (($it[0] ?? '') === '--') continue; ?>
          <?php $cle = $it[1]; $nv = $actuel[$cle] ?? ''; $vis = $defaut_visible($it[3] ?? []); ?>
          <tr>
            <td class="ps-4"><i class="bi bi-<?= h($it[2]) ?> me-1 text-muted2"></i><?= h($it[0]) ?>
              <span class="text-muted2 d-block" style="font-size:.72rem"><?= h($cle) ?></span></td>
            <td><span class="badge <?= $vis ? 'badge-soft' : '' ?>" style="<?= $vis ? '' : 'opacity:.5' ?>"><?= $vis ? 'Visible' : 'Masqué' ?></span></td>
            <td>
              <select name="regle[<?= h($cle) ?>]" class="form-select form-select-sm">
                <option value="">Défaut</option>
                <option value="ecriture" <?= $nv === 'ecriture' ? 'selected' : '' ?>>Écriture</option>
                <option value="lecture"  <?= $nv === 'lecture'  ? 'selected' : '' ?>>Lecture seule</option>
                <option value="masque"   <?= $nv === 'masque'   ? 'selected' : '' ?>>Masqué</option>
              </select>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>

  <div class="mt-3 d-flex gap-2">
    <button class="btn btn-primary btn-sm"><i class="bi bi-check2 me-1"></i>Enregistrer</button>
    <a href="<?= APP_URL ?>/association/acces.php?<?= h($qs([])) ?>" class="btn btn-outline-light btn-sm">Annuler</a>
  </div>
</form>
<?php elseif ($etab): ?>
  <div class="asso-card small text-muted2">Choisissez un rôle ou un compte à configurer.</div>
<?php else: ?>
  <div class="asso-card small text-muted2">Choisissez une école pour commencer.</div>
<?php endif; ?>
<?php asso_bas();
