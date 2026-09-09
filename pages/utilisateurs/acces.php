<?php
// pages/utilisateurs/acces.php — privilèges par utilisateur.
//  Le Directeur (ou un superadmin association entré en écriture) RETIRE à un
//  compte l'accès à des menus / sous-menus. Deny-list : ce qui est coché est
//  REFUSÉ au compte (table acces_utilisateur, migration v53). Le compte voit
//  par défaut tout ce que son rôle autorise ; on lui enlève des entrées.
//  Granularité : groupe de menu ('grp:<Nom>') ou entrée précise (son url).
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_role(['DIRECTEUR']);

$id = (int) ($_GET['id'] ?? 0);
$u  = $id ? db_one(
    "SELECT u.id_user, u.login_user, e.nom_ens, e.prenom_ens, e.id_fonction
     FROM user u JOIN enseignant e ON e.matricule_ens = u.matricule_ens
     WHERE u.id_user = ?", [$id]
) : null;
if (!$u) { flash_set('erreur', 'Compte introuvable.'); rediriger('pages/utilisateurs/liste.php'); }

// Anti-verrouillage : on ne restreint pas son propre compte, ni ces entrées.
$est_moi        = $id === (int) ($_SESSION['user_id'] ?? 0);
$cles_protegees = ['dashboard.php', 'profil.php'];

$menu = menu_definition();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    if ($est_moi) {
        flash_set('erreur', 'Vous ne pouvez pas restreindre votre propre compte.');
        rediriger('pages/utilisateurs/acces.php?id=' . $id);
    }

    // Clés valides = groupes + entrées réellement présentes dans le menu,
    // moins les entrées protégées (jamais restreignables).
    $valides = [];
    foreach ($menu as $groupe => $items) {
        $valides['grp:' . $groupe] = true;
        foreach ($items as $it) {
            if ($it[0] === '--') continue;
            if (in_array($it[1], $cles_protegees, true)) continue;
            $valides[$it[1]] = true;
        }
    }

    $refuses = [];
    foreach ((array) ($_POST['cle'] ?? []) as $cle) {
        $cle = (string) $cle;
        if (isset($valides[$cle])) $refuses[$cle] = true;
    }

    db_exec("DELETE FROM acces_utilisateur WHERE id_user = ?", [$id]);
    foreach (array_keys($refuses) as $cle) {
        db_exec("INSERT INTO acces_utilisateur (id_user, cle) VALUES (?, ?)", [$id, $cle]);
    }

    journaliser_action('acces_utilisateur', null, $u['login_user'] . ' : ' . count($refuses) . ' clé(s) refusée(s)');
    flash_set('succes', $refuses
        ? count($refuses) . ' restriction(s) enregistrée(s) pour « ' . $u['login_user'] . ' ».'
        : 'Toutes les restrictions ont été levées pour « ' . $u['login_user'] . ' ».');
    rediriger('pages/utilisateurs/acces.php?id=' . $id);
}

$refuses = acces_refuses_utilisateur($id); // ['grp:X' => true, 'url' => true, ...]

$titre_page = 'Privilèges — ' . $u['login_user'];
require_once __DIR__ . '/../../layout/header.php';

// Style / couleur par groupe (repris de header.php pour la cohérence visuelle).
$gs = $groupe_style ?? [];
?>

<div class="page-titre d-flex justify-content-between align-items-center">
  <div>
    <h4><i class="bi bi-sliders me-1 text-primary"></i>Privilèges d'accès</h4>
    <div class="sub">
      <?= h(mb_strtoupper($u['nom_ens'])) ?> <?= h($u['prenom_ens'] ?? '') ?>
      · <span class="badge-code"><?= h(libelle_role($u['id_fonction'] ?? '')) ?></span>
      · identifiant <strong><?= h($u['login_user']) ?></strong>
    </div>
  </div>
  <a href="<?= APP_URL ?>/pages/utilisateurs/liste.php" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left me-1"></i>Retour
  </a>
</div>

<?= flash_html() ?>

<div class="alert alert-light border d-flex gap-2 align-items-start" style="font-size:.82rem">
  <i class="bi bi-info-circle text-primary mt-1"></i>
  <div>
    Cochez ce que ce compte <strong>ne doit pas voir</strong>. Tout ce qui reste décoché
    demeure visible selon son rôle (<?= h(libelle_role($u['id_fonction'] ?? '')) ?>).
    Cocher un groupe masque tout son contenu. « Tableau de bord » et « Mon compte »
    restent toujours accessibles.
  </div>
</div>

<?php if ($est_moi): ?>
  <div class="alert alert-warning py-2 small"><i class="bi bi-shield-exclamation me-1"></i>
    C'est votre propre compte — les restrictions sont désactivées ici.</div>
<?php endif; ?>

<form method="post">
  <?= csrf_champ() ?>
  <div class="row g-3">
    <?php foreach ($menu as $groupe => $items): ?>
      <?php
        [$ig, $cg] = $gs[$groupe] ?? ['circle-fill', '#94a3b8'];
        $grp_cle   = 'grp:' . $groupe;
        $grp_off   = !empty($refuses[$grp_cle]);
      ?>
      <div class="col-12 col-lg-6">
        <div class="card h-100">
          <div class="card-body">
            <label class="d-flex align-items-center gap-2 mb-2" style="cursor:pointer">
              <input type="checkbox" class="form-check-input mt-0 js-grp" name="cle[]"
                     value="<?= h($grp_cle) ?>" data-groupe="<?= h($groupe) ?>"
                     <?= $grp_off ? 'checked' : '' ?> <?= $est_moi ? 'disabled' : '' ?>>
              <span class="d-inline-flex align-items-center justify-content-center rounded"
                    style="width:26px;height:26px;background:<?= h($cg) ?>1a;color:<?= h($cg) ?>">
                <i class="bi bi-<?= h($ig) ?>"></i>
              </span>
              <span class="fw-bold"><?= h($groupe) ?></span>
              <span class="text-muted small ms-auto">tout le groupe</span>
            </label>

            <div class="ps-4 js-leafs" data-groupe="<?= h($groupe) ?>" <?= $grp_off ? 'style="opacity:.4;pointer-events:none"' : '' ?>>
              <?php foreach ($items as $it): ?>
                <?php if ($it[0] === '--'): ?>
                  <div class="text-muted text-uppercase mt-2 mb-1" style="font-size:.68rem;letter-spacing:.05em"><?= h($it[1]) ?></div>
                <?php else:
                  $prot = in_array($it[1], $cles_protegees, true);
                  $off  = !empty($refuses[$it[1]]); ?>
                  <label class="d-flex align-items-center gap-2 py-1" style="cursor:<?= $prot ? 'default' : 'pointer' ?>">
                    <input type="checkbox" class="form-check-input mt-0" name="cle[]" value="<?= h($it[1]) ?>"
                           <?= $off ? 'checked' : '' ?> <?= ($prot || $est_moi) ? 'disabled' : '' ?>>
                    <i class="bi bi-<?= h($it[2]) ?> text-muted"></i>
                    <span<?= $prot ? ' class="text-muted"' : '' ?>><?= h($it[0]) ?></span>
                    <?php if ($prot): ?><span class="badge bg-light text-muted ms-auto" style="font-size:.62rem">toujours actif</span><?php endif; ?>
                  </label>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="mt-3 d-flex gap-2">
    <button class="btn btn-primary btn-sm" <?= $est_moi ? 'disabled' : '' ?>><i class="bi bi-check-lg me-1"></i>Enregistrer les privilèges</button>
    <a href="<?= APP_URL ?>/pages/utilisateurs/liste.php" class="btn btn-outline-secondary btn-sm">Annuler</a>
  </div>
</form>

<script>
// Cocher « tout le groupe » grise ses entrées (elles seront de toute façon
// masquées) ; l'état est purement visuel — le serveur ne garde que 'grp:…'.
document.querySelectorAll('.js-grp').forEach(function (g) {
  g.addEventListener('change', function () {
    var box = document.querySelector('.js-leafs[data-groupe="' + CSS.escape(g.dataset.groupe) + '"]');
    if (!box) return;
    box.style.opacity = g.checked ? '.4' : '';
    box.style.pointerEvents = g.checked ? 'none' : '';
  });
});
</script>

<?php require_once __DIR__ . '/../../layout/footer.php'; ?>
