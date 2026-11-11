<?php
// secondaire/commun/pages/communication/journal.php — journal des SMS
// (envoyés, échecs, en attente) et relance de la file d'attente (10/11/2026).
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
require_once __DIR__ . '/_sms_lib.php';

exiger_role(ed_secondaire() ? ['ADMIN', 'PROVISEUR', 'FONDATEUR', 'CENSEUR', 'SG', 'SECRETAIRE', 'INTENDANT'] : ['DIRECTEUR', 'SECRETAIRE', 'COMPTABLE', 'FONDATEUR']);
sms_assurer_journal();
$ici = 'secondaire/commun/pages/communication/journal.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    if (post('action') === 'relancer') {
        [$ok, $ko, $reste] = sms_relancer_attente(40);
        journaliser_action('sms_relance', null, "$ok envoyé(s), $ko échec(s), $reste en attente");
        flash_set($ok || !$reste ? 'succes' : 'erreur', sms_gw_actif() ? "$ok SMS envoyé(s), $ko échec(s), $reste encore en attente." : 'Passerelle SMS non configurée : impossible de relancer.');
    } elseif (post('action') === 'renvoyer') {
        db_exec("UPDATE sms_journal SET statut='attente', erreur=NULL WHERE id=? AND statut='echec'", [(int) post('id')]);
        flash_set('succes', 'Message remis en file d\'attente.');
    } elseif (post('action') === 'abandonner') {
        db_exec("UPDATE sms_journal SET statut='abandon' WHERE id=? AND statut IN ('attente','echec')", [(int) post('id')]);
        flash_set('succes', 'Message abandonné.');
    }
    rediriger($ici . '?' . http_build_query(array_intersect_key($_GET, array_flip(['statut', 'type', 'q']))));
}

$statut = in_array($_GET['statut'] ?? '', ['envoye', 'echec', 'attente', 'abandon'], true) ? $_GET['statut'] : '';
$type = array_key_exists($_GET['type'] ?? '', SMS_TYPES) || ($_GET['type'] ?? '') === 'manuel' ? $_GET['type'] : '';
$q = trim((string) ($_GET['q'] ?? ''));
$w = ['1=1']; $p = [];
if ($statut) { $w[] = 'statut=?'; $p[] = $statut; }
if ($type) { $w[] = 'type=?'; $p[] = $type; }
if ($q !== '') { $w[] = '(nom LIKE ? OR tel LIKE ? OR texte LIKE ?)'; array_push($p, "%$q%", "%$q%", "%$q%"); }
$lignes = db_all("SELECT * FROM sms_journal WHERE " . implode(' AND ', $w) . " ORDER BY id DESC LIMIT 300", $p);
$cpt = ['envoye' => 0, 'echec' => 0, 'attente' => 0];
foreach (db_all("SELECT statut, COUNT(*) n FROM sms_journal WHERE cree_le >= DATE_FORMAT(NOW(), '%Y-%m-01') GROUP BY statut") as $r) $cpt[$r['statut']] = (int) $r['n'];
$attente_tot = (int) db_val("SELECT COUNT(*) FROM sms_journal WHERE statut='attente'");
$badge = ['envoye' => ['Envoyé', '#d1fae5', '#065f46'], 'echec' => ['Échec', '#fee2e2', '#991b1b'], 'attente' => ['En attente', '#fef3c7', '#92400e'], 'abandon' => ['Abandonné', '#e5e7eb', '#374151']];

$titre_page = 'Journal des SMS';
require_once __DIR__ . '/../../../../noyau/layout/header.php';
?>
<div class="page-titre"><h4><i class="bi bi-journal-text me-1 text-primary"></i>Envoyer aux parents — journal des SMS</h4></div>
<?= flash_html() ?>
<?php $onglet_comm = 'journal'; require __DIR__ . '/_onglets.php'; ?>

<div class="row g-3 mb-3">
  <?php foreach ([['envoye', 'SMS envoyés ce mois', 'check-circle'], ['echec', 'Échecs ce mois', 'x-circle'], ['attente', 'En attente (total)', 'hourglass-split']] as [$k, $lib, $ic]): ?>
  <div class="col-md-4">
    <div class="card"><div class="card-body d-flex align-items-center gap-3">
      <div style="width:42px;height:42px;border-radius:12px;background:<?= $badge[$k][1] ?>;display:flex;align-items:center;justify-content:center"><i class="bi bi-<?= $ic ?>" style="color:<?= $badge[$k][2] ?>;font-size:1.2rem"></i></div>
      <div><div style="font-size:1.4rem;font-weight:700"><?= $k === 'attente' ? $attente_tot : $cpt[$k] ?></div><div class="text-muted" style="font-size:.8rem"><?= $lib ?></div></div>
    </div></div>
  </div>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card-body">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
      <form method="get" class="d-flex flex-wrap gap-1">
        <select name="statut" class="form-select form-select-sm" style="width:140px"><option value="">Tous les statuts</option><?php foreach ($badge as $k => [$l]): ?><option value="<?= $k ?>" <?= $statut === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
        <select name="type" class="form-select form-select-sm" style="width:200px"><option value="">Tous les types</option><option value="manuel" <?= $type === 'manuel' ? 'selected' : '' ?>>Envoi depuis « Envoyer »</option><?php foreach (SMS_TYPES as $k => [$l]): ?><option value="<?= $k ?>" <?= $type === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select>
        <input type="search" name="q" class="form-control form-control-sm" style="width:170px" placeholder="Nom, numéro, texte…" value="<?= h($q) ?>">
        <button class="btn btn-outline-primary btn-sm"><i class="bi bi-search"></i></button>
      </form>
      <?php if ($attente_tot): ?>
      <form method="post"><?= csrf_champ() ?><input type="hidden" name="action" value="relancer">
        <button class="btn btn-success btn-sm"><i class="bi bi-arrow-repeat me-1"></i>Envoyer les <?= $attente_tot ?> message(s) en attente</button></form>
      <?php endif; ?>
    </div>
    <div class="table-responsive">
      <table class="table table-abz table-sm align-middle mb-0" style="font-size:.8rem">
        <thead><tr><th>Date</th><th>Type</th><th>Destinataire</th><th>Message</th><th>Statut</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($lignes as $l): $b = $badge[$l['statut']] ?? $badge['attente']; ?>
          <tr>
            <td class="text-nowrap"><?= h(date('d/m/Y H:i', strtotime($l['cree_le']))) ?></td>
            <td><?= h(SMS_TYPES[$l['type']][0] ?? ($l['type'] === 'manuel' ? 'Envoyer' : $l['type'])) ?><div class="text-muted" style="font-size:.7rem"><?= $l['mode'] === 'auto' ? 'automatique' : h($l['mode']) ?></div></td>
            <td><?= h((string) $l['nom']) ?><div class="text-muted"><?= h(tel_affiche((string) $l['tel'])) ?></div></td>
            <td style="max-width:380px"><?= h($l['texte']) ?></td>
            <td><span class="badge" style="background:<?= $b[1] ?>;color:<?= $b[2] ?>" title="<?= h((string) $l['erreur']) ?>"><?= $b[0] ?></span><?php if ($l['essais'] > 1): ?><div class="text-muted" style="font-size:.68rem"><?= (int) $l['essais'] ?> essais</div><?php endif; ?></td>
            <td class="text-end text-nowrap">
              <?php if ($l['statut'] === 'echec'): ?><form method="post" class="d-inline"><?= csrf_champ() ?><input type="hidden" name="action" value="renvoyer"><input type="hidden" name="id" value="<?= (int) $l['id'] ?>"><button class="btn btn-sm btn-light" style="padding:2px 6px" title="Remettre en file d'attente"><i class="bi bi-arrow-repeat"></i></button></form><?php endif; ?>
              <?php if (in_array($l['statut'], ['attente', 'echec'], true)): ?><form method="post" class="d-inline"><?= csrf_champ() ?><input type="hidden" name="action" value="abandonner"><input type="hidden" name="id" value="<?= (int) $l['id'] ?>"><button class="btn btn-sm btn-light" style="padding:2px 6px" title="Abandonner"><i class="bi bi-x"></i></button></form><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$lignes): ?><tr><td colspan="6" class="text-center text-muted py-3">Aucun SMS dans le journal pour ces critères.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../../../../noyau/layout/footer.php'; ?>
