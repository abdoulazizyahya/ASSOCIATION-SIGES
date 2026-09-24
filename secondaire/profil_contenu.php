<?php
// secondaire/profil_contenu.php — « Mon compte » pour une école secondaire.
// Porté depuis profil.php (primaire) le 24/09/2026 : même page libre-service
// (identifiant, mot de passe, appareils connus), adaptée au schéma
// secondaire — une SEULE table `utilisateur` (id/login/mot_de_passe/role),
// pas de jointure enseignant obligatoire (matricule_ens nullable). Les
// questions de sécurité (configurer_securite.php) ne sont pas encore
// portées côté secondaire (déjà exempté par exiger_connexion()) : ce bloc
// est donc omis ici, pas juste caché.
// Inclus par profil.php (racine) via require + exit — jamais appelé seul.

$user_id = (int) $_SESSION['user_id'];
$compte  = db_one("SELECT * FROM utilisateur WHERE id = ?", [$user_id]);
if (!$compte) { flash_set('erreur', 'Compte introuvable.'); rediriger('dashboard.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $login = post('login');
    $mdp   = post('mot_de_passe');
    $conf  = post('confirmer_mdp');

    if (!$login) { flash_set('erreur', 'Le login est obligatoire.'); rediriger('profil.php'); }

    $existe = db_val("SELECT id FROM utilisateur WHERE login=? AND id!=?", [$login, $user_id]);
    if ($existe) { flash_set('erreur', 'Ce login est déjà utilisé.'); rediriger('profil.php'); }

    if ($mdp !== '') {
        if ($mdp !== $conf) { flash_set('erreur', 'Les mots de passe ne correspondent pas.'); rediriger('profil.php'); }
        db_exec("UPDATE utilisateur SET login=?, mot_de_passe=? WHERE id=?",
                [$login, password_hash($mdp, PASSWORD_DEFAULT), $user_id]);
        if (function_exists('journaliser_action')) journaliser_action('mot_de_passe_change');
    } else {
        db_exec("UPDATE utilisateur SET login=? WHERE id=?", [$login, $user_id]);
    }
    $_SESSION['user']['login'] = $login;
    flash_set('succes', 'Profil mis à jour.');
    rediriger('profil.php');
}

// ── Mes appareils (renommage) — générique, acteur_type='user' partagé ────
require_once __DIR__ . '/../bd/lib/audit.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'appareil_renommer') {
    csrf_verifier();
    $ok = appareil_connu_renommer((int) post('id_appareil'), 'user', $user_id, (string) post('nom_appareil'));
    flash_set($ok ? 'succes' : 'erreur', $ok ? 'Appareil renommé.' : 'Appareil introuvable.');
    rediriger('profil.php');
}
$mes_appareils     = appareil_connu_lister('user', $user_id);
$mon_appareil_actu = appareil_device_id();

$titre_page = 'Mon compte';
require_once __DIR__ . '/../layout/header.php';

$initiales = mb_strtoupper(mb_substr($compte['nom'] ?? '?', 0, 1)) . mb_strtoupper(mb_substr($compte['prenom'] ?? '', 0, 1));
$role_colors = [
    'ADMIN'      => ['bg' => '#fee2e2', 'txt' => '#991b1b'],
    'PROVISEUR'  => ['bg' => '#fee2e2', 'txt' => '#991b1b'],
    'FONDATEUR'  => ['bg' => '#fef9c3', 'txt' => '#854d0e'],
    'CENSEUR'    => ['bg' => '#e0e7ff', 'txt' => '#3730a3'],
    'SG'         => ['bg' => '#f3f4f6', 'txt' => '#374151'],
    'ENSEIGNANT' => ['bg' => '#fdf4ff', 'txt' => '#6b21a8'],
    'SECRETAIRE' => ['bg' => '#f0fdf4', 'txt' => '#166534'],
    'INTENDANT'  => ['bg' => '#dbeafe', 'txt' => '#1e40af'],
];
$rc = $role_colors[$compte['role'] ?? ''] ?? ['bg' => '#f3f4f6', 'txt' => '#374151'];
?>

<div class="page-titre d-flex align-items-center justify-content-between">
  <h4><i class="bi bi-person-circle me-1 text-primary"></i>Mon compte</h4>
</div>

<div class="row g-3 align-items-start">

  <!-- Carte identité -->
  <div class="col-lg-4 col-md-5">
    <div class="card text-center" style="overflow:hidden">
      <div style="height:75px;background:linear-gradient(135deg,var(--primary,#1e4fd8),#7c3aed)"></div>
      <div class="card-body pt-0 pb-3">
        <div style="position:relative;width:90px;margin:-45px auto 12px">
          <div style="width:90px;height:90px;border-radius:50%;
                      background:linear-gradient(135deg,var(--primary,#1e4fd8),#7c3aed);
                      display:flex;align-items:center;justify-content:center;
                      font-size:1.8rem;font-weight:800;color:#fff;
                      box-shadow:0 4px 16px rgba(0,0,0,.2);border:3px solid #fff;margin:0 auto">
            <?= h($initiales) ?>
          </div>
        </div>
        <div class="fw-bold" style="font-size:1rem;color:#1e2a3a">
          <?= h($compte['nom']) ?> <?= h($compte['prenom'] ?? '') ?>
        </div>
        <div class="text-muted mb-2" style="font-size:.78rem">
          <i class="bi bi-at"></i><?= h($compte['login']) ?>
        </div>
        <span style="background:<?= $rc['bg'] ?>;color:<?= $rc['txt'] ?>;padding:2px 9px;border-radius:10px;font-size:.68rem;font-weight:700">
          <?= h(libelle_role($compte['role'] ?? '')) ?>
        </span>

        <?php if (!empty($compte['matricule_ens'])): ?>
        <div class="mt-2 pt-2 border-top" style="font-size:.75rem;color:#6b7280">
          <i class="bi bi-person-badge me-1"></i>Fiche liée : matricule <?= h((string) $compte['matricule_ens']) ?>
          <a href="<?= APP_URL ?>/secondaire/pages/enseignants/mon_profil.php" class="d-block mt-1" style="font-size:.72rem">
            <i class="bi bi-pencil-square me-1"></i>Modifier mes informations
          </a>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Formulaire modification -->
  <div class="col-lg-8 col-md-7">
    <div class="card">
      <div class="card-header py-2 d-flex align-items-center gap-2" style="background:#f8faff">
        <i class="bi bi-pencil-square text-primary"></i>
        <span class="fw-semibold" style="font-size:.82rem">Identifiant &amp; mot de passe</span>
      </div>
      <div class="card-body">
        <form method="post" class="row g-3">
          <?= csrf_champ() ?>

          <div class="col-md-6">
            <label class="form-label">Nom d'utilisateur (login) <span class="text-danger">*</span></label>
            <div class="input-group input-group-sm">
              <span class="input-group-text" style="background:#f3f4f6"><i class="bi bi-at"></i></span>
              <input type="text" name="login" class="form-control" required value="<?= h($compte['login']) ?>">
            </div>
          </div>

          <div class="col-12 mt-1">
            <div class="section-titre mb-1 d-flex align-items-center gap-2"
                 style="font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;color:#9ca3af">
              Mot de passe
              <span style="font-weight:400;text-transform:none;letter-spacing:0;color:#d1d5db">— Laisser vide pour ne pas changer</span>
            </div>
          </div>
          <div class="col-md-5">
            <label class="form-label">Nouveau mot de passe</label>
            <input type="password" name="mot_de_passe" class="form-control form-control-sm" autocomplete="new-password" placeholder="••••••">
          </div>
          <div class="col-md-5">
            <label class="form-label">Confirmer</label>
            <input type="password" name="confirmer_mdp" class="form-control form-control-sm" autocomplete="new-password" placeholder="••••••">
          </div>

          <div class="col-12 mt-2 d-flex align-items-center gap-2">
            <button class="btn btn-primary btn-sm px-4">
              <i class="bi bi-check-lg me-1"></i>Enregistrer
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Mes appareils -->
    <div class="card mt-3">
      <div class="card-header py-2 d-flex align-items-center gap-2" style="background:#f8faff">
        <i class="bi bi-laptop text-primary"></i>
        <span class="fw-semibold" style="font-size:.82rem">Mes appareils</span>
      </div>
      <div class="card-body">
        <p class="text-muted" style="font-size:.75rem;margin-top:-4px">
          Donne un nom à un appareil pour le reconnaître facilement dans le
          <a href="<?= APP_URL ?>/secondaire/pages/utilisateurs/journal.php">journal d'audit</a> (ex. « PC du bureau »,
          « Mon téléphone ») — à la place du type générique.
        </p>
        <?php if (!$mes_appareils): ?>
          <div class="text-muted" style="font-size:.78rem">Aucun appareil reconnu pour l'instant.</div>
        <?php else: foreach ($mes_appareils as $ap):
          $ico    = ['ordinateur' => 'bi-laptop', 'tablette' => 'bi-tablet', 'mobile' => 'bi-phone'][$ap['ua_appareil'] ?? ''] ?? 'bi-question-circle';
          $type   = ['ordinateur' => 'Ordinateur', 'tablette' => 'Tablette', 'mobile' => 'Téléphone'][$ap['ua_appareil'] ?? ''] ?? '—';
          $modele = trim((string) ($ap['ua_modele'] ?? ''));
          $suggestion = $modele !== '' ? $modele : $type;
          $detail = trim(implode(' · ', array_filter([$modele !== '' ? $type : null, $ap['ua_os'] ?? null])));
          $ici = $ap['device_id'] === $mon_appareil_actu;
        ?>
        <form method="post" class="d-flex align-items-center gap-2 py-2 border-bottom flex-wrap">
          <?= csrf_champ() ?>
          <input type="hidden" name="action" value="appareil_renommer">
          <input type="hidden" name="id_appareil" value="<?= (int) $ap['id'] ?>">
          <i class="bi <?= $ico ?>" style="font-size:1.1rem;color:#9ca3af"></i>
          <div style="min-width:160px">
            <input type="text" name="nom_appareil" class="form-control form-control-sm" placeholder="<?= h($suggestion) ?>"
                   value="<?= h($ap['nom'] ?? '') ?>" maxlength="60">
          </div>
          <span class="text-muted" style="font-size:.72rem"><?= h($detail !== '' ? $detail : $type) ?></span>
          <?php if ($ici): ?><span class="badge bg-success" style="font-size:.65rem">Cet appareil</span><?php endif; ?>
          <span class="text-muted ms-auto" style="font-size:.7rem">
            Vu le <?= h(date('d/m/Y', strtotime((string) $ap['derniere_connexion']))) ?>
          </span>
          <button class="btn btn-light btn-sm" style="font-size:.72rem" title="Enregistrer le nom">
            <i class="bi bi-check-lg"></i>
          </button>
        </form>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../layout/footer.php'; ?>
