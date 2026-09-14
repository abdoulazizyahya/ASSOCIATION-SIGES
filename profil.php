<?php
// profil.php — Compte utilisateur en libre-service (login + mot de passe +
// état des questions de sécurité), accessible à TOUS les rôles connectés
// (demande explicite du 22/08/2026 : menu Paramètres visible à tous, en
// libre-service pour tout rôle hors Directeur). La gestion complète des
// comptes (création, rôle, suppression) reste un module séparé réservé au
// Directeur — voir pages/utilisateurs/liste.php, ne PAS dupliquer ici.
// Remplace un ancien profil.php copié tel quel d'un autre projet (table
// `utilisateur`/colonnes `id`/`role`/`login`/`mot_de_passe` inexistantes
// dans ce schéma — jamais fonctionnel, jamais lié au menu).
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/connexion.php';
require_once __DIR__ . '/fonctions.php';
exiger_connexion();

$user_id = (int) $_SESSION['user_id'];
// $compte (pas $user) : layout/header.php écrase $user avec la session
// ($_SESSION['user'] : clés nom/prenom/role, PAS nom_ens/id_fonction…).
$compte  = db_one(
    "SELECT u.id_user, u.login_user, u.matricule_ens, e.nom_ens, e.prenom_ens, e.id_fonction
     FROM user u JOIN enseignant e ON e.matricule_ens = u.matricule_ens
     WHERE u.id_user = ?", [$user_id]
);
if (!$compte) { flash_set('erreur', 'Compte introuvable.'); rediriger('dashboard.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $login = post('login');
    $mdp   = post('mot_de_passe');
    $conf  = post('confirmer_mdp');

    if (!$login) { flash_set('erreur', 'Le login est obligatoire.'); rediriger('profil.php'); }

    $existe = db_val("SELECT id_user FROM user WHERE login_user=? AND id_user!=?", [$login, $user_id]);
    if ($existe) { flash_set('erreur', 'Ce login est déjà utilisé.'); rediriger('profil.php'); }

    if ($mdp !== '') {
        if ($mdp !== $conf) { flash_set('erreur', 'Les mots de passe ne correspondent pas.'); rediriger('profil.php'); }
        db_exec("UPDATE user SET login_user=?, pwd_user=? WHERE id_user=?",
                [$login, password_hash($mdp, PASSWORD_DEFAULT), $user_id]);
        if (function_exists('journaliser_action')) journaliser_action('mot_de_passe_change');
    } else {
        db_exec("UPDATE user SET login_user=? WHERE id_user=?", [$login, $user_id]);
    }
    $_SESSION['user']['login'] = $login;
    flash_set('succes', 'Profil mis à jour.');
    rediriger('profil.php');
}

// ── Mes appareils (renommage) ─────────────────────────────────────────
//  bd/lib/audit.php n'est chargé qu'à la demande ailleurs (ecole_contexte.
//  php::journaliser_action()) — on le charge ici explicitement pour
//  appareil_device_id() (function_exists() serait sinon faux tant
//  qu'aucune action n'a encore été journalisée dans CETTE requête).
require_once __DIR__ . '/bd/lib/audit.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'appareil_renommer') {
    csrf_verifier();
    $ok = appareil_connu_renommer((int) post('id_appareil'), 'user', $user_id, (string) post('nom_appareil'));
    flash_set($ok ? 'succes' : 'erreur', $ok ? 'Appareil renommé.' : 'Appareil introuvable.');
    rediriger('profil.php');
}
$mes_appareils     = appareil_connu_lister('user', $user_id);
$mon_appareil_actu = appareil_device_id();

$titre_page = 'Mon compte';
require_once __DIR__ . '/layout/header.php';

$initiales = mb_strtoupper(mb_substr($compte['nom_ens'] ?? '?', 0, 1)) . mb_strtoupper(mb_substr($compte['prenom_ens'] ?? '', 0, 1));
$role_colors = [
    'DIRECTEUR'  => ['bg' => '#fee2e2', 'txt' => '#991b1b'],
    'FONDATEUR'  => ['bg' => '#fef9c3', 'txt' => '#854d0e'],
    'ENSEIGNANT' => ['bg' => '#fdf4ff', 'txt' => '#6b21a8'],
    'SECRETAIRE' => ['bg' => '#f0fdf4', 'txt' => '#166534'],
    'COMPTABLE'  => ['bg' => '#dbeafe', 'txt' => '#1e40af'],
];
$rc = $role_colors[$compte['id_fonction'] ?? ''] ?? ['bg' => '#f3f4f6', 'txt' => '#374151'];
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
          <?= h($compte['nom_ens']) ?> <?= h($compte['prenom_ens'] ?? '') ?>
        </div>
        <div class="text-muted mb-2" style="font-size:.78rem">
          <i class="bi bi-at"></i><?= h($compte['login_user']) ?>
        </div>
        <span style="background:<?= $rc['bg'] ?>;color:<?= $rc['txt'] ?>;padding:2px 9px;border-radius:10px;font-size:.68rem;font-weight:700">
          <?= h(libelle_role($compte['id_fonction'] ?? '')) ?>
        </span>

        <!-- Questions de sécurité -->
        <div class="mt-3 pt-2 border-top">
          <div style="font-size:.65rem;text-transform:uppercase;letter-spacing:.06em;color:#9ca3af;margin-bottom:4px">
            <i class="bi bi-shield-lock me-1"></i>Sécurité
          </div>
          <?php if (utilisateur_a_questions($user_id)): ?>
            <span class="badge bg-success" style="font-size:.68rem">Questions configurées</span>
          <?php else: ?>
            <span class="badge bg-warning text-dark" style="font-size:.68rem">Non configurées</span>
          <?php endif; ?>
          <a href="<?= APP_URL ?>/configurer_securite.php?retour=profil.php" class="btn btn-light btn-sm d-block mt-2" style="font-size:.72rem">
            <i class="bi bi-pencil me-1"></i>Modifier mes questions
          </a>
        </div>

        <div class="mt-2 pt-2 border-top" style="font-size:.75rem;color:#6b7280">
          <i class="bi bi-person-badge me-1"></i>Fiche liée : matricule <?= h((string) $compte['matricule_ens']) ?>
          <a href="<?= APP_URL ?>/pages/enseignants/mon_profil.php" class="d-block mt-1" style="font-size:.72rem">
            <i class="bi bi-pencil-square me-1"></i>Modifier mes informations
          </a>
        </div>
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
              <input type="text" name="login" class="form-control" required value="<?= h($compte['login_user']) ?>">
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
          <a href="<?= APP_URL ?>/pages/utilisateurs/journal.php">journal d'audit</a> (ex. « PC du bureau »,
          « Mon téléphone ») — à la place du type générique.
        </p>
        <?php if (!$mes_appareils): ?>
          <div class="text-muted" style="font-size:.78rem">Aucun appareil reconnu pour l'instant.</div>
        <?php else: foreach ($mes_appareils as $ap):
          $ico    = ['ordinateur' => 'bi-laptop', 'tablette' => 'bi-tablet', 'mobile' => 'bi-phone'][$ap['ua_appareil'] ?? ''] ?? 'bi-question-circle';
          $type   = ['ordinateur' => 'Ordinateur', 'tablette' => 'Tablette', 'mobile' => 'Téléphone'][$ap['ua_appareil'] ?? ''] ?? '—';
          $modele = trim((string) ($ap['ua_modele'] ?? ''));
          // Placeholder : le modèle détecté (ex. « TECNO L34 ») quand il
          // existe — sinon le type générique. Toujours suggéré, jamais
          // enregistré tant que le compte ne valide pas lui-même.
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

<?php require_once __DIR__ . '/layout/footer.php'; ?>
