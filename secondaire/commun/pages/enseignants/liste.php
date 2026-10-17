<?php
// secondaire/commun/pages/enseignants/liste.php — annuaire du personnel (école
// secondaire). Simplifié par rapport à LAM_ABZ/pages/enseignants/index.php
// pour cette étape (pas encore d'export PDF dossier/attestation/prise de
// service — voir LAM_ABZ pour le modèle complet le moment venu).
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'FONDATEUR', 'CENSEUR']);

$q = trim($_GET['q'] ?? '');
// Comptes de connexion (17/10/2026) : gérés depuis la fiche, plus depuis Paramètres → Utilisateurs.
$gere_comptes = sec_peut_gerer_comptes();
$join_compte = "LEFT JOIN (SELECT matricule_ens, MIN(id) AS id FROM utilisateur
                           WHERE matricule_ens IS NOT NULL AND compte_systeme=0 GROUP BY matricule_ens) uc ON uc.matricule_ens=e.matricule_ens
                LEFT JOIN utilisateur u ON u.id=uc.id";
$where = ''; $params = [];
if ($q !== '') {
    $where  = 'WHERE e.nom_ens LIKE ? OR e.prenom_ens LIKE ? OR e.matricule LIKE ? OR e.id_fonction LIKE ? OR e.tel_ens LIKE ?'
            . ($gere_comptes ? ' OR u.login LIKE ?' : '');
    $params = array_fill(0, $gere_comptes ? 6 : 5, "%$q%");
}

// Tri par colonne (clic sur l'en-tête) et pagination, 17/10/2026. Les cases vides
// restent toujours en dernier, quel que soit le sens du tri.
$tris = [
    'matricule' => ['e.matricule IS NULL', 'e.matricule'],
    'nom'       => [null, 'e.nom_ens', 'e.prenom_ens'],
    'fonction'  => ["NULLIF(e.id_fonction, '') IS NULL", 'e.id_fonction'],
    'tel'       => ["NULLIF(e.tel_ens, '') IS NULL", 'e.tel_ens'],
    'compte'    => ['u.login IS NULL', 'u.login'],
];
if (!$gere_comptes) unset($tris['compte']);
$tri  = isset($tris[$_GET['tri'] ?? '']) ? $_GET['tri'] : 'nom';
$sens = ($_GET['sens'] ?? '') === 'desc' ? 'DESC' : 'ASC';
[$vide, $cols] = [$tris[$tri][0], array_slice($tris[$tri], 1)];
$ordre = ($vide ? $vide . ', ' : '') . implode(', ', array_map(fn($c) => "$c $sens", $cols)) . ', e.nom_ens, e.prenom_ens';

$pp_choix = [25, 50, 100, 0];   // 0 = tout afficher
$pp       = in_array((int) ($_GET['pp'] ?? 25), $pp_choix, true) ? (int) ($_GET['pp'] ?? 25) : 25;
$total    = (int) db_val("SELECT COUNT(*) FROM enseignant e $join_compte $where", $params);
$nb_pages = $pp ? max(1, (int) ceil($total / $pp)) : 1;
$page     = min($nb_pages, max(1, (int) ($_GET['page'] ?? 1)));
$limite   = $pp ? ' LIMIT ' . $pp . ' OFFSET ' . (($page - 1) * $pp) : '';
$enseignants = db_all("SELECT e.*, u.login, u.actif AS compte_actif FROM enseignant e $join_compte $where ORDER BY $ordre$limite", $params);
$sans_compte = $gere_comptes ? (int) db_val("SELECT COUNT(*) FROM enseignant e $join_compte WHERE u.id IS NULL") : 0;

// URL de la liste avec les paramètres courants, modifiés par $chg (null = retiré)
$url_liste = function (array $chg = []) use ($q, $tri, $sens, $pp): string {
    $p = array_merge(['q' => $q, 'tri' => $tri === 'nom' ? null : $tri, 'sens' => $sens === 'DESC' ? 'desc' : null, 'pp' => $pp === 25 ? null : $pp], $chg);
    $p = array_filter($p, fn($v) => $v !== null && $v !== '');
    return APP_URL . '/secondaire/commun/pages/enseignants/liste.php' . ($p ? '?' . http_build_query($p) : '');
};
// En-tête cliquable : 1er clic = croissant, 2e clic = décroissant
$th_tri = function (string $cle, string $libelle) use ($tri, $sens, $url_liste): string {
    $actif = $tri === $cle;
    $icone = $actif ? ($sens === 'ASC' ? 'bi-caret-up-fill' : 'bi-caret-down-fill') : 'bi-chevron-expand opacity-50';
    $url   = $url_liste(['tri' => $cle, 'sens' => $actif && $sens === 'ASC' ? 'desc' : null, 'page' => null]);
    return '<th><a href="' . h($url) . '" class="text-decoration-none text-reset" title="Trier par ' . h(mb_strtolower($libelle)) . '">'
         . h($libelle) . ' <i class="bi ' . $icone . '" style="font-size:.65rem"></i></a></th>';
};

$titre_page = 'Enseignants';
require_once __DIR__ . '/../../../../noyau/layout/header.php';
?>

<div class="page-titre d-flex justify-content-between align-items-center">
  <div>
    <h4><i class="bi bi-person-badge me-1 text-primary"></i>Enseignants</h4>
    <div class="sub"><?= $total ?> membre(s) du personnel<?= $q !== '' ? ' trouvé(s)' : '' ?></div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <?php $u_lot = APP_URL . '/secondaire/commun/pages/enseignants/pdf_dossier_lot.php' . ($q !== '' ? '?q=' . urlencode($q) : ''); ?>
    <button type="button" class="btn btn-outline-primary btn-sm"
            onclick="afficherApercu(<?= h(json_encode($u_lot)) ?>, <?= h(json_encode($q !== '' ? 'Dossiers administratifs — résultats de la recherche' : 'Dossiers administratifs — tous')) ?>, 'dossier_enseignant', 'portrait')">
      <i class="bi bi-files me-1"></i><?= $q !== '' ? 'Dossiers (résultats)' : 'Tous les dossiers' ?>
    </button>
    <?php if ($gere_comptes): ?>
    <?php if ($sans_compte): ?>
    <form method="post" action="<?= APP_URL ?>/secondaire/commun/pages/enseignants/compte.php" class="d-inline"
          onsubmit="return confirm('Créer un compte pour les <?= $sans_compte ?> membre(s) sans compte ? (identifiant tiré du nom, mot de passe identique)')">
      <?= csrf_champ() ?><input type="hidden" name="action" value="creer_manquants">
      <button class="btn btn-outline-success btn-sm"><i class="bi bi-person-plus me-1"></i>Créer les comptes manquants (<?= $sans_compte ?>)</button>
    </form>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/secondaire/commun/pages/utilisateurs/liste.php" class="btn btn-outline-secondary btn-sm" title="Tous les comptes, y compris ceux sans fiche du personnel (administrateur, fondateur…)">
      <i class="bi bi-people me-1"></i>Tous les comptes
    </a>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/secondaire/commun/pages/enseignants/form.php" class="btn btn-primary btn-sm">
      <i class="bi bi-plus-lg me-1"></i>Nouveau membre
    </a>
  </div>
</div>

<?= flash_html() ?>

<div class="card mb-2">
  <div class="card-body py-2">
    <form method="get" class="d-flex gap-2 flex-wrap align-items-center">
      <input type="search" name="q" value="<?= h($q) ?>" placeholder="Nom, matricule, fonction, téléphone<?= $gere_comptes ? ', identifiant' : '' ?>…" class="form-control form-control-sm" style="max-width:340px">
      <?php if ($tri !== 'nom'): ?><input type="hidden" name="tri" value="<?= h($tri) ?>"><?php endif; ?>
      <?php if ($sens === 'DESC'): ?><input type="hidden" name="sens" value="desc"><?php endif; ?>
      <button class="btn btn-outline-primary btn-sm"><i class="bi bi-search"></i></button>
      <?php if ($q): ?><a href="<?= h($url_liste(['q' => null, 'page' => null])) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-lg"></i></a><?php endif; ?>
      <div class="ms-auto d-flex align-items-center gap-1" style="font-size:.78rem">
        <span class="text-muted">Afficher</span>
        <select name="pp" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
          <?php foreach ($pp_choix as $n): ?>
            <option value="<?= $n ?>" <?= $n === $pp ? 'selected' : '' ?>><?= $n ?: 'Tout' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-abz table-hover align-middle mb-0">
      <thead>
        <tr>
          <?= $th_tri('matricule', 'Matricule') ?>
          <?= $th_tri('nom', 'Nom et prénom') ?>
          <?= $th_tri('fonction', 'Fonction / Grade') ?>
          <?= $th_tri('tel', 'Téléphone') ?>
          <?php if ($gere_comptes): ?><?= $th_tri('compte', 'Compte') ?><?php endif; ?>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($enseignants)): ?>
          <tr><td colspan="<?= $gere_comptes ? 6 : 5 ?>" class="text-center text-muted py-4">
            <i class="bi bi-inbox" style="font-size:2rem;opacity:.3;display:block;margin-bottom:.4rem"></i>
            Aucun membre du personnel.
          </td></tr>
        <?php else: foreach ($enseignants as $e): ?>
          <tr>
            <td><?php if ($e['matricule']): ?><span class="badge-code"><?= h($e['matricule']) ?></span><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
            <td>
              <a href="<?= APP_URL ?>/secondaire/commun/pages/enseignants/voir.php?id=<?= (int) $e['matricule_ens'] ?>"
                 class="fw-semibold text-decoration-none" style="font-size:.82rem">
                <?= h(($e['civilite_ens'] ? $e['civilite_ens'] . ' ' : '') . mb_strtoupper($e['nom_ens']) . ' ' . ($e['prenom_ens'] ?? '')) ?>
              </a>
            </td>
            <td style="font-size:.78rem">
              <?= h($e['id_fonction'] ?: '—') ?>
              <?php if ($e['id_grade']): ?><br><span class="text-muted"><?= h($e['id_grade']) ?></span><?php endif; ?>
            </td>
            <td style="font-size:.78rem"><?= h($e['tel_ens'] ?: '—') ?></td>
            <?php if ($gere_comptes): ?>
            <td style="font-size:.78rem">
              <a href="<?= APP_URL ?>/secondaire/commun/pages/enseignants/voir.php?id=<?= (int) $e['matricule_ens'] ?>#compte" class="text-decoration-none">
              <?php if ($e['login'] !== null): ?><code><?= h($e['login']) ?></code><?php if (!$e['compte_actif']): ?> <span class="badge bg-secondary">désactivé</span><?php endif; ?>
              <?php else: ?><span class="badge bg-warning text-dark">sans compte</span><?php endif; ?>
              </a>
            </td>
            <?php endif; ?>
            <td class="text-end">
              <a href="<?= APP_URL ?>/secondaire/commun/pages/enseignants/voir.php?id=<?= (int) $e['matricule_ens'] ?>"
                 class="btn btn-sm" style="background:#eef2ff;color:#1e4fd8;padding:3px 7px">
                <i class="bi bi-eye" style="font-size:.78rem"></i>
              </a>
              <a href="<?= APP_URL ?>/secondaire/commun/pages/enseignants/form.php?id=<?= (int) $e['matricule_ens'] ?>"
                 class="btn btn-sm btn-light" style="padding:3px 7px">
                <i class="bi bi-pencil" style="font-size:.78rem"></i>
              </a>
              <!-- Documents administratifs (portés d'ABZ_MBE) : aperçu avant impression -->
              <div class="dropdown d-inline">
                <button class="btn btn-sm btn-light dropdown-toggle" style="padding:3px 7px" data-bs-toggle="dropdown" title="Documents">
                  <i class="bi bi-file-earmark-text" style="font-size:.78rem"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end" style="font-size:.8rem">
                  <?php foreach (['attestation' => 'Attestation de présence', 'prise_service' => 'Prise de service',
                                  'reprise_service' => 'Reprise de service', 'dossier' => 'Dossier administratif'] as $t => $lib): ?>
                    <li><a class="dropdown-item" href="<?= APP_URL ?>/secondaire/commun/pages/enseignants/apercu.php?id=<?= urlencode((string) $e['matricule_ens']) ?>&type=<?= $t ?>"><?= h($lib) ?></a></li>
                  <?php endforeach; ?>
                </ul>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($total): ?>
  <div class="card-footer bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div class="text-muted" style="font-size:.75rem">
      <?= $pp ? (($page - 1) * $pp + 1) . '–' . min($total, $page * $pp) . ' sur ' . $total : $total . ' au total' ?>
    </div>
    <?= pagination_html($page, $nb_pages, $url_liste()) ?>
  </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../../../noyau/layout/footer.php'; ?>
