<?php
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
exiger_role(['ADMIN', 'FONDATEUR', 'PROVISEUR']);   // Proviseur : sans voir le Fondateur ni le compte propriétaire (sec_compte_visible())
$GLOBALS['menu_lien_actif'] = 'secondaire/commun/pages/utilisateurs/liste.php';   // Ressources humaines → Comptes utilisateurs (23/10/2026)

// Réinitialisation rapide : mot de passe = identifiant (convention des comptes
// par défaut), à changer par l'utilisateur à sa prochaine connexion — mêmes
// limites que la modification (sec_compte_gerable() : jamais le Fondateur, un
// autre Proviseur, l'ADMIN ni le compte propriétaire), 23/10/2026.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'reinit_mdp') {
    csrf_verifier();
    $cible = db_one("SELECT * FROM utilisateur WHERE id=?", [(int) post('id')]);
    if (!$cible || !sec_compte_gerable($cible)) {
        flash_set('erreur', 'Compte introuvable ou hors de votre périmètre.');
    } else {
        db_exec("UPDATE utilisateur SET mot_de_passe=? WHERE id=?", [password_hash($cible['login'], PASSWORD_DEFAULT), $cible['id']]);
        journaliser_action('mot_de_passe_reinit', null, $cible['login']);
        flash_set('succes', "Mot de passe de « {$cible['login']} » réinitialisé : il est maintenant identique à l'identifiant.");
    }
    rediriger('secondaire/commun/pages/utilisateurs/liste.php');
}

// Nom affiché : celui du compte, sinon celui de la fiche du personnel liée
// (comptes importés d'ABZ_MBE sans nom, 24/10/2026). Ordre alphabétique au départ.
$users = db_all(
    "SELECT u.*, COALESCE(NULLIF(TRIM(u.nom), ''), e.nom_ens, '') AS nom_aff,
            COALESCE(NULLIF(TRIM(u.prenom), ''), e.prenom_ens, '') AS prenom_aff,
            COALESCE(NULLIF(TRIM(u.email), ''), e.mail_ens, '') AS email_aff
     FROM utilisateur u LEFT JOIN enseignant e ON e.matricule_ens = u.matricule_ens"
);
$users = array_values(array_filter($users, 'sec_compte_visible'));
usort($users, fn($a, $b) => strcoll(mb_strtoupper(trim($a['nom_aff'] . ' ' . $a['prenom_aff'])) ?: $a['login'],
                                     mb_strtoupper(trim($b['nom_aff'] . ' ' . $b['prenom_aff'])) ?: $b['login']));

$titre_page = 'Utilisateurs';
require_once __DIR__ . '/../../../../noyau/layout/header.php';
?>
<div class="page-titre d-flex justify-content-between align-items-center">
  <h4><i class="bi bi-people me-1 text-primary"></i>Utilisateurs</h4>
  <a href="<?= APP_URL ?>/secondaire/commun/pages/utilisateurs/form.php" class="btn btn-primary btn-sm">
    <i class="bi bi-plus-lg me-1"></i>Ajouter
  </a>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-abz table-hover mb-0 table-triable">
      <thead>
        <tr>
          <th data-sens="asc">Nom</th><th>Identifiant</th><th>Mot de passe</th><th>Rôle</th><th>Statut</th><th class="text-end" data-sans-tri>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($users)): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">Aucun utilisateur.</td></tr>
        <?php else: foreach ($users as $u): ?>
          <tr>
            <?php $nom_complet = trim(mb_strtoupper($u['nom_aff']) . ' ' . $u['prenom_aff']); ?>
            <td class="fw-semibold" data-tri="<?= h($nom_complet ?: $u['login']) ?>">
              <div><?= $nom_complet !== '' ? h($nom_complet) : '<span class="text-muted fw-normal">—</span>' ?></div>
              <small class="text-muted"><?= h($u['email_aff']) ?></small>
            </td>
            <td><?= h($u['login']) ?></td>
            <td style="font-size:.78rem">
              <?php // Le mot de passe est chiffré (irréversible) : seul le mot de passe par défaut peut être montré.
              if (!sec_compte_gerable($u)): ?>
                <span class="text-muted">—</span>
              <?php elseif (mdp_est_identifiant((string) $u['login'], (string) $u['mot_de_passe'])): ?>
                <span class="badge bg-warning text-dark" title="Mot de passe par défaut, à faire changer">= identifiant</span>
              <?php else: ?>
                <span class="text-muted" title="Choisi par l'utilisateur ; non lisible, mais réinitialisable"><i class="bi bi-lock me-1"></i>personnalisé</span>
              <?php endif; ?>
            </td>
            <td>
              <?php
              // Voir form.php : 'PROF' (LAM_ABZ d'origine) n'existe pas dans
              // l'ENUM utilisateur.role, remplacé par les 7 rôles réels.
              $badges = ['ADMIN'=>'bg-danger','PROVISEUR'=>'bg-primary','FONDATEUR'=>'bg-primary','CENSEUR'=>'bg-info','SG'=>'bg-dark',
                         'SECRETAIRE'=>'bg-warning text-dark','ENSEIGNANT'=>'bg-secondary','INTENDANT'=>'bg-success'];
              $bg = $badges[$u['role']] ?? 'bg-secondary';
              ?>
              <span class="badge <?= $bg ?>"><?= h(libelle_role($u['role'])) ?></span>
            </td>
            <td>
              <?php if ($u['actif']): ?>
                <span class="badge bg-success">Actif</span>
              <?php else: ?>
                <span class="badge bg-secondary">Inactif</span>
              <?php endif; ?>
            </td>
            <td class="text-end">
              <?php if (sec_compte_gerable($u)): ?>
              <a href="<?= APP_URL ?>/secondaire/commun/pages/utilisateurs/form.php?id=<?= $u['id'] ?>" class="btn btn-sm btn-light" style="padding:3px 7px">
                <i class="bi bi-pencil" style="font-size:.78rem"></i>
              </a>
              <form method="post" style="display:inline">
                <?= csrf_champ() ?>
                <input type="hidden" name="action" value="reinit_mdp">
                <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                <button class="btn btn-sm btn-light" style="padding:3px 7px" title="Réinitialiser le mot de passe (= identifiant)"
                        onclick="return confirm('Réinitialiser le mot de passe de « <?= h(addslashes($u['login'])) ?> » ? Il deviendra identique à l\'identifiant.')">
                  <i class="bi bi-arrow-counterclockwise" style="font-size:.78rem"></i>
                </button>
              </form>
              <?php endif; ?>
              <?php if ($u['id'] != ($_SESSION['user_id'] ?? null) && sec_compte_gerable($u)): ?>
              <a href="<?= APP_URL ?>/secondaire/commun/pages/utilisateurs/supprimer.php?id=<?= $u['id'] ?>&csrf=<?= csrf_generer() ?>"
                 class="btn btn-sm btn-light text-danger" style="padding:3px 7px"
                 onclick="return confirm('Supprimer cet utilisateur ?')">
                <i class="bi bi-trash" style="font-size:.78rem"></i>
              </a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/../../../../noyau/layout/footer.php'; ?>
