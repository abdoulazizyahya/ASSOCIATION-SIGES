<?php
// Fragment : onglet « Matricules » de la liste des élèves (primaire) —
// élèves sans matricule et matricules en double. Fonctionnement en deux
// temps (04/10/2026) : « Proposer des matricules » REMPLIT les cases selon le
// format défini (rien n'est enregistré), l'utilisateur ajuste, puis clique
// sur « Enregistrer » ; on revient ici avec le tableau des matricules
// enregistrés. Inclus par _liste_resultats.php (plein écran et AJAX).
// Réservé aux rôles qui configurent les matricules ($peut_importer).
if (empty($peut_importer)) { echo '<div class="text-muted small py-3">Accès réservé.</div>'; return; }
require_once __DIR__ . '/_matricules_lib.php';

$mc        = matricule_config();
$exemple   = matricule_exemple($mc['format'], (int) $mc['longueur_seq']);
$manquants = matricules_manquants();
$doublons  = matricules_doublons();
$props     = $_SESSION['mat_propositions'] ?? [];
$resultats = $_SESSION['mat_resultats'] ?? [];
$erreurs   = $_SESSION['mat_erreurs'] ?? [];
unset($_SESSION['mat_resultats'], $_SESSION['mat_erreurs']);   // affichés une seule fois
$a_corriger = 0;
foreach ($doublons as $g) foreach ($g as $m) if (!$m['garde']) $a_corriger++;
$url_action = APP_URL . '/pages/eleves/matricules_action.php';
$nom = fn(array $e) => trim(mb_strtoupper((string) $e['Nom_elv']) . ' ' . ($e['Prenom_elv'] ?? ''));
$nb_props = count($props);

// Case de saisie d'un élève : pré-remplie par la proposition (fond jaune pâle).
$case = function (int $id, string $placeholder) use ($props, $erreurs): string {
    $v = $props[$id] ?? '';
    return '<input type="text" name="mat[' . $id . ']" form="formMatEnr" value="' . h($v) . '" maxlength="30"'
         . ' class="form-control form-control-sm font-monospace' . ($v !== '' ? ' mat-propose' : '') . (isset($erreurs[$id]) ? ' is-invalid' : '') . '"'
         . ' placeholder="' . h($placeholder) . '" style="max-width:190px">'
         . (isset($erreurs[$id]) ? '<div class="invalid-feedback d-block" style="font-size:.72rem">' . h($erreurs[$id]) . '</div>' : '');
};
?>
<style>
  .mat-propose { background:#fff8db; border-color:#e0b84f; }
</style>

<div class="alert alert-light border py-2 mb-3" style="font-size:.8rem">
  <i class="bi bi-hash me-1 text-primary"></i>
  Format défini : <span class="badge-code font-monospace"><?= h($mc['format']) ?></span>
  — exemple <span class="badge-code font-monospace"><?= h($exemple) ?></span>
  <?php if ($mc['mode'] === 'manuel'): ?>
    <span class="text-muted">(mode « saisie manuelle » : les propositions appliquent quand même ce format)</span>
  <?php endif; ?>
  <a href="#" onclick="changerStatutListe('outils');return false" class="ms-2">Modifier le format</a>
</div>

<?php if ($resultats): ?>
<div class="card border-success mb-3">
  <div class="card-header py-2 fw-semibold text-success" style="font-size:.88rem">
    <i class="bi bi-check-circle me-1"></i>Matricules enregistrés (<?= count($resultats) ?>)
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0" style="font-size:.82rem">
      <thead><tr><th>Élève</th><th>Ancien</th><th>Nouveau matricule</th></tr></thead>
      <tbody>
      <?php foreach ($resultats as $r): ?>
        <tr>
          <td><a href="<?= APP_URL ?>/pages/eleves/voir.php?id=<?= (int) $r['id'] ?>"><?= h($r['nom']) ?></a></td>
          <td class="font-monospace text-muted"><?= h($r['ancien'] !== '' ? $r['ancien'] : '—') ?></td>
          <td class="font-monospace fw-bold text-success"><?= h($r['nouveau']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Formulaire unique d'enregistrement : toutes les cases (mat[ID]) y sont
     rattachées par l'attribut form="formMatEnr". -->
<form method="post" action="<?= h($url_action) ?>" id="formMatEnr">
  <?= csrf_champ() ?><input type="hidden" name="action" value="enregistrer">
</form>

<?php if ($manquants || $a_corriger): ?>
<div class="d-flex flex-wrap gap-2 align-items-center mb-3">
  <button type="submit" form="formMatEnr" class="btn btn-success btn-sm"
          onclick="return confirm('Enregistrer les matricules saisis dans les cases ?')">
    <i class="bi bi-save me-1"></i>Enregistrer les matricules
  </button>
  <?php if ($nb_props): ?>
  <form method="post" action="<?= h($url_action) ?>" class="d-inline">
    <?= csrf_champ() ?><input type="hidden" name="action" value="annuler">
    <button class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-lg me-1"></i>Effacer les propositions</button>
  </form>
  <span class="text-muted" style="font-size:.78rem"><span class="badge" style="background:#fff8db;color:#7a5b00;border:1px solid #e0b84f">jaune</span> = proposition non encore enregistrée</span>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="row g-3">
  <!-- ── Élèves sans matricule ───────────────────────────────── -->
  <div class="col-12">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2 py-2">
        <span class="fw-semibold" style="font-size:.88rem">
          <i class="bi bi-person-exclamation me-1 text-warning"></i>Élèves sans matricule
          <span class="badge <?= $manquants ? 'bg-warning text-dark' : 'bg-success' ?> ms-1"><?= count($manquants) ?></span>
        </span>
        <?php if ($manquants): ?>
        <form method="post" action="<?= h($url_action) ?>" class="d-inline">
          <?= csrf_champ() ?><input type="hidden" name="action" value="proposer"><input type="hidden" name="cible" value="manquants">
          <button class="btn btn-primary btn-sm"><i class="bi bi-magic me-1"></i>Proposer des matricules</button>
        </form>
        <?php endif; ?>
      </div>
      <?php if (!$manquants): ?>
        <div class="card-body text-success" style="font-size:.85rem"><i class="bi bi-check-circle me-1"></i>Tous les élèves actifs ont un matricule.</div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0" style="font-size:.82rem">
          <thead><tr><th>Élève</th><th>Classe</th><th style="width:240px">Matricule (proposé ou saisi)</th></tr></thead>
          <tbody>
          <?php foreach ($manquants as $e): ?>
            <tr>
              <td><a href="<?= APP_URL ?>/pages/eleves/voir.php?id=<?= (int) $e['id_eleve'] ?>"><?= h($nom($e)) ?></a></td>
              <td><?= h($e['classe'] ?? '— non inscrit cette année —') ?></td>
              <td><?= $case((int) $e['id_eleve'], 'ex. ' . $exemple) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── Matricules en double ───────────────────────────────── -->
  <div class="col-12">
    <div class="card <?= $doublons ? 'border-danger' : '' ?>">
      <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2 py-2">
        <span class="fw-semibold" style="font-size:.88rem">
          <i class="bi bi-files me-1 text-danger"></i>Matricules en double
          <span class="badge <?= $doublons ? 'bg-danger' : 'bg-success' ?> ms-1"><?= count($doublons) ?></span>
        </span>
        <?php if ($a_corriger): ?>
        <form method="post" action="<?= h($url_action) ?>" class="d-inline">
          <?= csrf_champ() ?><input type="hidden" name="action" value="proposer"><input type="hidden" name="cible" value="doublons">
          <button class="btn btn-outline-danger btn-sm"><i class="bi bi-magic me-1"></i>Proposer une correction (<?= $a_corriger ?>)</button>
        </form>
        <?php endif; ?>
      </div>
      <?php if (!$doublons): ?>
        <div class="card-body text-success" style="font-size:.85rem"><i class="bi bi-check-circle me-1"></i>Aucun matricule en double : chaque matricule est unique.</div>
      <?php else: ?>
      <div class="card-body py-2 text-danger" style="font-size:.8rem">
        <i class="bi bi-exclamation-triangle me-1"></i>
        Ces matricules sont portés par plusieurs élèves (comparaison sans tenir compte des espaces ni des majuscules).
        Proposition : l'élève enregistré <strong>en premier</strong> garde son matricule, les autres en reçoivent un nouveau.
      </div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0" style="font-size:.82rem">
          <thead><tr><th>Matricule actuel</th><th>Élève</th><th>Classe</th><th style="width:240px">Nouveau matricule</th></tr></thead>
          <tbody>
          <?php foreach ($doublons as $groupe): foreach ($groupe as $i => $m): ?>
            <tr class="<?= $i === 0 ? 'border-top border-2' : '' ?>">
              <td class="font-monospace fw-semibold"><?= h($m['Mat_elv']) ?></td>
              <td>
                <a href="<?= APP_URL ?>/pages/eleves/voir.php?id=<?= (int) $m['id_eleve'] ?>"><?= h($nom($m)) ?></a>
                <?php if ($m['statut'] !== 'actif'): ?><span class="badge bg-secondary ms-1">désactivé</span><?php endif; ?>
              </td>
              <td><?= h($m['classe'] ?? '—') ?></td>
              <td>
                <?php if ($m['garde']): ?>
                  <span class="badge bg-success"><i class="bi bi-lock me-1"></i>garde son matricule</span>
                <?php else: ?>
                  <?= $case((int) $m['id_eleve'], 'nouveau matricule') ?>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<p class="text-muted mt-2 mb-0" style="font-size:.74rem">
  <i class="bi bi-info-circle me-1"></i>Changer un matricule ne touche à aucune donnée de l'élève (notes, paiements,
  absences sont reliés à sa fiche, pas à son matricule). Seuls les documents déjà imprimés avec un QR code
  (bulletins, cartes) portent l'ancien matricule et sont à réimprimer.
</p>
