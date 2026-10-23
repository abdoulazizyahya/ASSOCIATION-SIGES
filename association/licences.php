<?php
// association/licences.php — Licences de toutes les écoles (23/10/2026).
//   - Tableau : licence en cours de chaque école (début, fin, jours restants,
//     état, dernière modification), lue dans la base de l'école elle-même.
//   - Formulaire : renouvellement direct (sans clé) jusqu'à une date choisie,
//     pour les écoles cochées ou toutes — bd/lib/licence.php,
//     licence_renouveler_ecoles().
// Réservé au propriétaire (seul habilité à renouveler, voir licence.php).
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../noyau/connexion.php';
require_once __DIR__ . '/../noyau/fonctions.php';
require_once __DIR__ . '/_layout.php';
exiger_proprietaire_association();

$ecoles = assoc_all("SELECT id, code, nom, db_name, type_enseignement, actif FROM etablissement
                     ORDER BY actif DESC, type_enseignement='secondaire' DESC, nom");
$par_id = array_column($ecoles, null, 'id');

$debut = date('Y-m-d'); $fin = ''; $lignes = []; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $debut = trim(post('date_debut')) ?: date('Y-m-d');
    $fin   = trim(post('date_fin'));
    $ids   = array_map('intval', (array) ($_POST['ecoles'] ?? []));
    $choix = array_values(array_filter(array_map(fn($i) => $par_id[$i] ?? null, $ids)));
    $d1 = DateTimeImmutable::createFromFormat('!Y-m-d', $debut);
    $d2 = DateTimeImmutable::createFromFormat('!Y-m-d', $fin);
    if (!$d1 || !$d2) {
        $err = 'Dates invalides.';
    } elseif ($d2 <= $d1) {
        $err = 'La date de fin doit être après la date de début.';
    } elseif ($d2 < new DateTimeImmutable('today')) {
        $err = 'La date de fin est déjà passée.';
    } elseif (!$choix) {
        $err = 'Cochez au moins une école.';
    } else {
        $m = membre_connecte();
        $auteur = 'association:' . ($m['login'] ?? 'proprietaire');
        foreach (licence_renouveler_ecoles($choix, $debut, $fin, $auteur) as $code => $echec) {
            $lignes[] = ($echec === null ? '✅ ' : '⚠️ ') . $code . ($echec === null ? " — active jusqu'au " . date('d/m/Y', strtotime($fin)) : ' — ' . $echec);
            if ($echec === null) {
                $id_e = (int) (array_values(array_filter($choix, fn($e) => $e['code'] === $code))[0]['id'] ?? 0);
                journaliser_action('licence_renouvellement_direct', $id_e, "du $debut au $fin (portail association)");
            }
        }
    }
}

// État relu APRÈS un éventuel renouvellement.
$etats = [];
foreach ($ecoles as $e) $etats[$e['id']] = licence_etat_ecole($e);

$motifs = [
    'aucune_licence'     => 'aucune licence',
    'signature_invalide' => 'signature invalide (modifiée à la main ?)',
    'horloge_reculee'    => "horloge du serveur reculée",
    'date_depassee'      => 'date dépassée',
    'anomalie_technique' => 'tables licence absentes (migration non appliquée ?)',
];
$fr = fn(?string $d) => $d ? date('d/m/Y', strtotime($d)) : '—';

asso_haut('Licences des écoles');
?>
<?php if ($err): ?><div class="alert alert-warning py-2 small"><?= h($err) ?></div><?php endif; ?>
<?php if ($lignes): ?>
  <div class="alert alert-success py-2 small mb-2">Renouvellement terminé.</div>
  <pre class="asso-card small mb-3" style="white-space:pre-wrap"><?= h(implode("\n", $lignes)) ?></pre>
<?php endif; ?>

<form method="post">
  <?= csrf_champ() ?>
  <div class="asso-card mb-3">
    <h6 class="mb-1"><i class="bi bi-key me-1"></i>Activer / renouveler la licence</h6>
    <p class="small text-muted2 mb-3">
      Renouvellement direct, sans clé : la nouvelle période remplace la licence en cours des écoles cochées
      ci-dessous (historique conservé dans chaque école, blocage anti-saisie levé).
    </p>
    <div class="row g-2 align-items-end">
      <div class="col-sm-4 col-md-3">
        <label class="form-label small mb-1">Début</label>
        <input type="date" name="date_debut" class="form-control form-control-sm" value="<?= h($debut) ?>" required>
      </div>
      <div class="col-sm-4 col-md-3">
        <label class="form-label small mb-1">Fin</label>
        <input type="date" name="date_fin" class="form-control form-control-sm" value="<?= h($fin) ?>" required
               min="<?= date('Y-m-d') ?>">
      </div>
      <div class="col-sm-4 col-md-3">
        <button class="btn btn-primary btn-sm w-100"
                onclick="var n=document.querySelectorAll('.lic-choix:checked').length;
                         if(!n){alert('Cochez au moins une école.');return false;}
                         return confirm('Renouveler la licence de '+n+' école(s) ?');">
          <i class="bi bi-check2-circle me-1"></i>Appliquer aux écoles cochées
        </button>
      </div>
    </div>
  </div>

  <div class="asso-card p-0">
    <div class="table-responsive">
    <table class="table table-sm mb-0 align-middle" style="font-size:.83rem">
      <thead>
        <tr class="text-muted2">
          <th style="width:32px"><input type="checkbox" class="form-check-input" id="lic-tout" title="Toutes les écoles"></th>
          <th>École</th><th>Début</th><th>Fin</th><th class="text-end">Jours restants</th><th>État</th><th>Dernière modification</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($ecoles as $e): $s = $etats[$e['id']]; $lic = $s['licence']; $hist = $s['historique'];
          $badge = ['ok' => ['success', 'Active'], 'alerte' => ['warning', 'Bientôt expirée'], 'expiree' => ['danger', 'Expirée']][$s['etat']]; ?>
          <tr class="<?= $e['actif'] ? '' : 'opacity-50' ?>">
            <td><input type="checkbox" name="ecoles[]" value="<?= (int) $e['id'] ?>" class="form-check-input lic-choix"></td>
            <td>
              <span class="badge badge-soft me-1"><?= h($e['code']) ?></span><?= h($e['nom']) ?>
              <div class="small text-muted2"><?= h($e['type_enseignement']) ?><?= $e['actif'] ? '' : ' · inactive' ?></div>
            </td>
            <td><?= $fr($lic['date_debut'] ?? null) ?></td>
            <td class="fw-semibold"><?= $fr($lic['date_expiration'] ?? null) ?></td>
            <td class="text-end"><?= $s['etat'] === 'expiree' ? '—' : (int) $s['jours_restants'] ?></td>
            <td>
              <span class="badge bg-<?= $badge[0] ?><?= $badge[0] === 'warning' ? ' text-dark' : '' ?>"><?= $badge[1] ?></span>
              <?php if ($s['etat'] === 'expiree' && $s['motif']): ?>
                <div class="small text-muted2"><?= h($motifs[$s['motif']] ?? $s['motif']) ?></div>
              <?php endif; ?>
            </td>
            <td class="small text-muted2">
              <?php if ($hist): ?>
                <?= $fr($hist['date_modification']) ?> · <?= h($hist['methode']) ?>
                <?php if ($hist['modifie_par']): ?><div><?= h($hist['modifie_par']) ?></div><?php endif; ?>
              <?php elseif ($lic): ?>
                <?= $fr($lic['date_derniere_modification'] ?? null) ?><?= !empty($lic['derniere_modification_par']) ? ' · ' . h($lic['derniere_modification_par']) : '' ?>
              <?php else: ?>—<?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$ecoles): ?><tr><td colspan="7" class="text-center text-muted2 py-3">Aucune école.</td></tr><?php endif; ?>
      </tbody>
    </table>
    </div>
  </div>
</form>
<script>
document.getElementById('lic-tout')?.addEventListener('change', function () {
  document.querySelectorAll('.lic-choix').forEach(c => { c.checked = this.checked; });
});
</script>
<?php asso_bas(); ?>
