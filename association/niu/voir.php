<?php
// association/niu/voir.php — fiche d'un NIU : identité, école courante,
// historique des mouvements + actions (transfert inter-écoles, sortie du
// réseau, réintégration, fusion de doublon). Superadmin uniquement pour
// les actions ; lecture pour tout membre.
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/../_layout.php';
exiger_membre_association();

$niu = trim($_GET['niu'] ?? '');
$n   = $niu !== '' ? assoc_niu_detail($niu) : null;
if (!$n) { asso_haut('NIU introuvable'); echo '<div class="asso-card text-muted2">Ce NIU n\'existe pas dans le registre.</div>'; asso_bas(); exit; }

$superadmin = est_superadmin_association();
$par = 'membre:' . (membre_connecte()['login'] ?? '?');
$msg = ''; $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    if (!$superadmin) {
        $err = "Action réservée au superadministrateur.";
    } else {
        $op    = $_POST['op'] ?? '';
        $motif = trim($_POST['motif'] ?? '') ?: null;
        if ($op === 'transfert') {
            $r = assoc_niu_transferer($niu, (int) ($_POST['id_etab'] ?? 0), $motif, $par, 'transfert');
        } elseif ($op === 'reintegration') {
            $r = assoc_niu_transferer($niu, (int) ($_POST['id_etab'] ?? 0), $motif, $par, 'reintegration');
        } elseif ($op === 'sortie') {
            $r = assoc_niu_sortie($niu, $motif, $par);
        } elseif ($op === 'fusion') {
            $r = assoc_niu_fusionner($niu, trim($_POST['absorbe'] ?? ''), $par);
        } else {
            $r = ['ok' => false, 'message' => "Action inconnue."];
        }
        if ($r['ok']) {
            journaliser_action('niu_' . $op, (int) ($_POST['id_etab'] ?? 0) ?: null, $niu . ($motif ? " — $motif" : ''));
            $msg = $r['message'];
        } else {
            $err = $r['message'];
        }
        $n = assoc_niu_detail($niu);   // recharger
    }
}

$ecoles = assoc_all("SELECT id, code, nom, actif FROM etablissement ORDER BY actif DESC, nom");

$TYPE_LABEL = [
    'creation' => 'Création', 'inscription' => 'Inscription', 'transfert' => 'Transfert',
    'sortie' => 'Sortie du réseau', 'reintegration' => 'Réintégration',
];

asso_haut('NIU ' . $n['niu']);
$csrf = csrf_generer();
?>
<a href="<?= APP_URL ?>/association/niu/index.php" class="small text-decoration-none">← Registre NIU</a>
<?php if ($msg): ?><div class="alert alert-success py-2 small mt-2"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-warning py-2 small mt-2"><?= h($err) ?></div><?php endif; ?>

<div class="row g-3 mt-1">
  <div class="col-12 col-lg-5">
    <div class="asso-card">
      <div class="d-flex align-items-center gap-2 mb-2">
        <span class="font-monospace fs-5"><?= h($n['niu']) ?></span>
        <span class="badge badge-soft"><?= h($n['statut']) ?></span>
      </div>
      <table class="table table-dark table-sm mb-0" style="font-size:.85rem">
        <tbody>
          <tr><td class="text-muted2">Nom</td><td><?= h(trim($n['nom'] . ' ' . $n['prenom'])) ?: '—' ?></td></tr>
          <tr><td class="text-muted2">Naissance</td><td><?= h($n['date_naissance'] ?: '—') ?> <?= h($n['lieu_naissance'] ? '· ' . $n['lieu_naissance'] : '') ?></td></tr>
          <tr><td class="text-muted2">Sexe</td><td><?= h($n['sexe'] ?: '—') ?></td></tr>
          <tr><td class="text-muted2">Père / Mère</td><td><?= h(trim(($n['nom_pere'] ?? '') . ' / ' . ($n['nom_mere'] ?? ''), ' /')) ?: '—' ?></td></tr>
          <tr><td class="text-muted2">École d'origine</td><td><?= h($n['origine_nom'] ? $n['origine_code'] . ' — ' . $n['origine_nom'] : '—') ?></td></tr>
          <tr><td class="text-muted2">École actuelle</td><td><?= h($n['courant_nom'] ? $n['courant_code'] . ' — ' . $n['courant_nom'] : '—') ?></td></tr>
          <tr><td class="text-muted2">Créé</td><td><?= h($n['cree_le']) ?> <?= h($n['cree_par'] ? '· ' . $n['cree_par'] : '') ?></td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <div class="col-12 col-lg-7">
    <?php if ($superadmin): ?>
    <div class="asso-card mb-3">
      <div class="fw-bold mb-2">Actions</div>

      <?php if ($n['statut'] !== 'sorti'): ?>
      <form method="post" class="row g-2 align-items-end mb-3">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="op" value="transfert">
        <div class="col-12 small text-muted2">Transférer vers une autre école (change l'école actuelle)</div>
        <div class="col-5">
          <select name="id_etab" class="form-select form-select-sm" required>
            <option value="">— école cible —</option>
            <?php foreach ($ecoles as $e): if ((int) $e['id'] === (int) $n['id_etab_courant']) continue; ?>
              <option value="<?= (int) $e['id'] ?>"><?= h($e['code'] . ' — ' . $e['nom']) ?><?= $e['actif'] ? '' : ' (inactive)' ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-5">
          <input name="motif" class="form-control form-control-sm" placeholder="Motif (facultatif)" maxlength="255">
        </div>
        <div class="col-2"><button class="btn btn-primary btn-sm w-100">Transférer</button></div>
      </form>

      <form method="post" class="d-flex gap-2 align-items-center" onsubmit="return confirm('Marquer cet élève comme sorti du réseau ?');">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="op" value="sortie">
        <input name="motif" class="form-control form-control-sm" placeholder="Motif de sortie (facultatif)" maxlength="255">
        <button class="btn btn-outline-warning btn-sm text-nowrap">Sortie du réseau</button>
      </form>
      <?php else: ?>
      <form method="post" class="row g-2 align-items-end">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="op" value="reintegration">
        <div class="col-12 small text-muted2">Élève « sorti » — le réintégrer dans une école</div>
        <div class="col-6">
          <select name="id_etab" class="form-select form-select-sm" required>
            <option value="">— école —</option>
            <?php foreach ($ecoles as $e): ?>
              <option value="<?= (int) $e['id'] ?>"><?= h($e['code'] . ' — ' . $e['nom']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-4"><input name="motif" class="form-control form-control-sm" placeholder="Motif" maxlength="255"></div>
        <div class="col-2"><button class="btn btn-primary btn-sm w-100">Réintégrer</button></div>
      </form>
      <?php endif; ?>

      <hr class="border-secondary my-2">
      <form method="post" class="d-flex gap-2 align-items-center"
            onsubmit="return confirm('Fusionner : ce NIU absorbe l\'autre, qui sera supprimé. Continuer ?');">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="op" value="fusion">
        <input name="absorbe" class="form-control form-control-sm font-monospace" placeholder="NIU doublon à fusionner ici" maxlength="30" required>
        <button class="btn btn-outline-danger btn-sm text-nowrap">Fusionner</button>
      </form>
    </div>
    <?php endif; ?>

    <div class="asso-card p-0">
      <div class="px-3 py-2 small text-muted2 border-bottom" style="border-color:#23304d!important">Historique</div>
      <table class="table table-dark table-sm mb-0 align-middle" style="font-size:.82rem">
        <tbody>
          <?php foreach ($n['mouvements'] as $mv): ?>
            <tr>
              <td class="text-nowrap text-muted2"><?= h(date('d/m/Y H:i', strtotime($mv['date']))) ?></td>
              <td><span class="badge badge-soft"><?= h($TYPE_LABEL[$mv['type']] ?? $mv['type']) ?></span></td>
              <td class="small">
                <?= $mv['source_code'] ? h($mv['source_code']) . ' → ' : '' ?><?= h($mv['cible_code'] ?? '') ?>
                <?php if ($mv['motif']): ?><div class="text-muted2"><?= h($mv['motif']) ?></div><?php endif; ?>
              </td>
              <td class="text-end small text-muted2"><?= h($mv['par'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$n['mouvements']): ?>
            <tr><td class="text-center text-muted2 py-3">Aucun mouvement.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php asso_bas();
