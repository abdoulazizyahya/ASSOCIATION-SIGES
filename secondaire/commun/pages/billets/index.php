<?php
// secondaire/commun/pages/billets/index.php — Discipline → Billets &
// autorisations (10/11/2026), primaire et secondaire. Saisie d'un billet
// (élève ou personnel), impression souche + volet (pdf.php), registre.
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
require_once __DIR__ . '/_billets_lib.php';
require_once __DIR__ . '/../communication/_sms_lib.php';

exiger_role(billets_roles());
billets_assurer_table();
$ici  = 'secondaire/commun/pages/billets/index.php';
$type = array_key_exists($_GET['type'] ?? '', BILLET_TYPES) ? $_GET['type'] : 'sortie';
$T    = BILLET_TYPES[$type];
$an   = ed_annee();
$moi  = trim((string) (($_SESSION['user']['prenom'] ?? '') . ' ' . ($_SESSION['user']['nom'] ?? ''))) ?: (string) ($_SESSION['user']['login'] ?? '');

// ── Enregistrement d'un billet ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $action = post('action');
    if ($action === 'annuler') {
        $b = billet_charger((int) post('id'));
        if ($b && !$b['annule']) {
            db_exec("UPDATE billet SET annule=1 WHERE id=?", [$b['id']]);
            journaliser_action('billet_annule', null, $b['numero'] . ' — ' . $b['nom']);
            flash_set('succes', 'Billet ' . $b['numero'] . ' annulé (il reste dans le registre, barré).');
        }
        rediriger($ici . '?type=' . $type);
    }
    $nom = $classe = $ident = ''; $id_eleve = $id_pers = null; $tels = [];
    if ($T['pour'] === 'eleve') {
        $e = ed_eleve((int) post('id_eleve'));
        if (!$e) { flash_set('erreur', 'Choisissez un élève.'); rediriger($ici . '?type=' . $type); }
        $id_eleve = (int) $e['id']; $nom = ed_nom($e); $classe = (string) $e['classe']; $ident = ed_ident($e);
        $tels = array_column(contacts_parents_eleve($id_eleve), 'tel');
    } else {
        $p = null;
        foreach (ed_personnel() as $x) if ((int) $x['id'] === (int) post('id_personnel')) $p = $x;
        if (!$p) { flash_set('erreur', 'Choisissez un membre du personnel.'); rediriger($ici . '?type=' . $type); }
        $id_pers = (int) $p['id']; $nom = ed_nom($p); $classe = (string) ($p['fonction'] ?? ''); $ident = (string) ($p['matricule'] ?? '');
    }
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', post('date_billet')) ? post('date_billet') : date('Y-m-d');
    $date_fin = preg_match('/^\d{4}-\d{2}-\d{2}$/', post('date_fin')) ? post('date_fin') : null;
    $heure = preg_match('/^\d{2}:\d{2}$/', post('heure')) ? post('heure') : null;
    $heure2 = preg_match('/^\d{2}:\d{2}$/', post('heure2')) ? post('heure2') : null;
    $numero = billet_numero_suivant($type, $an['libelle']);
    db_exec("INSERT INTO billet (type, numero, annee, id_eleve, id_personnel, nom, classe, identifiant, date_billet, date_fin, heure, heure2,
                                 personne, motif, signataire, cree_par, cree_le) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())",
        [$type, $numero, $an['libelle'], $id_eleve, $id_pers, $nom, $classe ?: null, $ident ?: null, $date, $date_fin, $heure, $heure2,
         mb_substr(post('personne'), 0, 150) ?: null, mb_substr(post('motif'), 0, 255) ?: null, mb_substr(post('signataire'), 0, 150) ?: null,
         (string) ($_SESSION['user']['login'] ?? '')]);
    $id = (int) db_last_id();
    journaliser_action('billet_emis', null, $numero . ' — ' . $T['libelle'] . ' — ' . $nom);
    $msg = $T['libelle'] . ' ' . $numero . ' enregistré.';
    // SMS au parent (sortie, infirmerie) : modèle de l'école, journal, file d'attente.
    if (post('sms') === '1' && $tels && in_array($type, ['sortie', 'infirmerie'], true)) {
        $mods = sms_modeles();
        $txt = sms_rendre($mods[$type][sms_langue((int) ($e['id_classe'] ?? 0))], ['eleve' => $nom, 'classe' => $classe,
            'date' => date('d/m/Y', strtotime($date)), 'heure' => $heure ?: '-', 'motif' => post('motif') ?: '-']);
        $r = sms_envoyer($type, $id_eleve, $nom, $tels[0], $txt, 'billet');
        db_exec("UPDATE billet SET sms=? WHERE id=?", [$r['ok'] ? $tels[0] : (!empty($r['attente']) ? 'attente' : 'échec'), $id]);
        $msg .= $r['ok'] ? ' SMS envoyé au parent (' . tel_affiche($tels[0]) . ').' : (!empty($r['attente']) ? ' SMS au parent mis en attente (voir le journal des SMS).' : ' SMS non envoyé : ' . $r['erreur']);
    }
    flash_set('succes', $msg);
    rediriger($ici . '?type=' . $type . '&imprimer=' . $id);
}

// ── Données de la page ──
$eleves = $T['pour'] === 'eleve' ? ed_eleves() : [];
$personnel = $T['pour'] === 'personnel' ? ed_personnel() : [];
$q = trim((string) ($_GET['q'] ?? ''));
$du = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['du'] ?? '') ? $_GET['du'] : '';
$au = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['au'] ?? '') ? $_GET['au'] : '';
$w = ['type=?', 'annee=?']; $p = [$type, $an['libelle']];
if ($q !== '') { $w[] = '(nom LIKE ? OR numero LIKE ? OR classe LIKE ? OR motif LIKE ?)'; array_push($p, "%$q%", "%$q%", "%$q%", "%$q%"); }
if ($du) { $w[] = 'date_billet >= ?'; $p[] = $du; }
if ($au) { $w[] = 'date_billet <= ?'; $p[] = $au; }
$registre = db_all("SELECT * FROM billet WHERE " . implode(' AND ', $w) . " ORDER BY date_billet DESC, id DESC LIMIT 300", $p);
$compteurs = [];
foreach (db_all("SELECT type, COUNT(*) n FROM billet WHERE annee=? AND annule=0 GROUP BY type", [$an['libelle']]) as $r) $compteurs[$r['type']] = (int) $r['n'];
// Élèves les plus concernés (sorties / retards) : repérage des abus.
$frequents = $T['pour'] === 'eleve' ? db_all("SELECT nom, classe, COUNT(*) n FROM billet WHERE type=? AND annee=? AND annule=0 GROUP BY id_eleve, nom, classe HAVING n >= 3 ORDER BY n DESC LIMIT 8", [$type, $an['libelle']]) : [];
$motifs = [
    'sortie' => ['Malade', 'Convocation des parents', 'Rendez-vous médical', 'Décès / deuil', 'Évènement familial', 'Exclusion temporaire'],
    'entree' => ['Retard', 'Retour de maladie', 'Retour d\'exclusion', 'Transport', 'Pluie'],
    'infirmerie' => ['Maux de tête', 'Fièvre', 'Maux de ventre', 'Blessure', 'Malaise', 'Crise de paludisme'],
    'autorisation' => ['Raison de santé', 'Évènement familial', 'Formation / séminaire', 'Démarche administrative', 'Décès / deuil'],
][$type];
$sms_possible = in_array($type, ['sortie', 'infirmerie'], true);
$sms_coche = $sms_possible && (sms_gw_actif() || !empty(sms_modeles()[$type]['auto']));

$titre_page = 'Billets & autorisations';
require_once __DIR__ . '/../../../../noyau/layout/header.php';
?>
<div class="page-titre d-flex justify-content-between align-items-center flex-wrap gap-2">
  <div>
    <h4><i class="bi bi-ticket-perforated me-1 text-primary"></i>Billets &amp; autorisations</h4>
    <div class="sub">Souche gardée par l'école, volet remis à l'élève ou à l'agent — chaque billet est numéroté et inscrit au registre (année <?= h($an['libelle']) ?>).</div>
  </div>
</div>

<?= flash_html() ?>

<ul class="nav nav-tabs mb-3" style="border-bottom:2px solid #e5e7eb">
  <?php foreach (BILLET_TYPES as $k => $t): ?>
  <li class="nav-item">
    <a class="nav-link <?= $type === $k ? 'active' : '' ?>" href="?type=<?= $k ?>">
      <i class="bi bi-<?= $t['icone'] ?> me-1"></i><?= h($t['libelle']) ?>
      <?php if (!empty($compteurs[$k])): ?><span class="badge bg-light text-dark ms-1"><?= $compteurs[$k] ?></span><?php endif; ?>
    </a>
  </li>
  <?php endforeach; ?>
</ul>

<div class="row g-3">
  <div class="col-lg-5">
    <div class="card">
      <div class="card-body">
        <div class="section-titre"><i class="bi bi-plus-circle me-1"></i>Nouveau — <?= h($T['libelle']) ?></div>
        <form method="post" autocomplete="off">
          <?= csrf_champ() ?>
          <?php if ($T['pour'] === 'eleve'): ?>
          <div class="mb-2">
            <label class="form-label">Élève <span class="text-danger">*</span></label>
            <select name="id_eleve" class="form-select form-select-sm" required id="sel-eleve">
              <option value="">— Choisir —</option>
              <?php $cl = null; foreach ($eleves as $e): if ($e['classe'] !== $cl): if ($cl !== null) echo '</optgroup>'; $cl = $e['classe']; ?><optgroup label="<?= h($cl) ?>"><?php endif; ?>
                <option value="<?= (int) $e['id'] ?>"><?= h(ed_nom($e)) ?></option>
              <?php endforeach; if ($cl !== null) echo '</optgroup>'; ?>
            </select>
          </div>
          <?php else: ?>
          <div class="mb-2">
            <label class="form-label">Membre du personnel <span class="text-danger">*</span></label>
            <select name="id_personnel" class="form-select form-select-sm" required>
              <option value="">— Choisir —</option>
              <?php foreach ($personnel as $x): ?><option value="<?= (int) $x['id'] ?>"><?= h(ed_nom($x) . ($x['fonction'] ? ' — ' . $x['fonction'] : '')) ?></option><?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
          <div class="row g-2 mb-2">
            <div class="col-6">
              <label class="form-label"><?= $type === 'autorisation' ? 'Du' : 'Date' ?></label>
              <input type="date" name="date_billet" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required>
            </div>
            <?php if ($type === 'autorisation'): ?>
            <div class="col-6">
              <label class="form-label">Au</label>
              <input type="date" name="date_fin" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
            </div>
            <?php endif; ?>
            <div class="col-<?= $type === 'autorisation' ? '6' : '3' ?>">
              <label class="form-label"><?= h($T['heure']) ?></label>
              <input type="time" name="heure" class="form-control form-control-sm" value="<?= $type === 'autorisation' ? '' : date('H:i') ?>">
            </div>
            <?php if ($T['heure2'] !== ''): ?>
            <div class="col-<?= $type === 'autorisation' ? '6' : '3' ?>">
              <label class="form-label"><?= h($T['heure2']) ?></label>
              <input type="time" name="heure2" class="form-control form-control-sm">
            </div>
            <?php endif; ?>
          </div>
          <?php if ($T['personne'] !== ''): ?>
          <div class="mb-2">
            <label class="form-label"><?= h($T['personne']) ?></label>
            <input type="text" name="personne" class="form-control form-control-sm" maxlength="150">
          </div>
          <?php endif; ?>
          <div class="mb-2">
            <label class="form-label">Motif</label>
            <input type="text" name="motif" class="form-control form-control-sm" maxlength="255" list="motifs-<?= $type ?>">
            <datalist id="motifs-<?= $type ?>"><?php foreach ($motifs as $m): ?><option value="<?= h($m) ?>"><?php endforeach; ?></datalist>
          </div>
          <div class="mb-2">
            <label class="form-label">Signataire</label>
            <input type="text" name="signataire" class="form-control form-control-sm" maxlength="150" value="<?= h($moi) ?>">
          </div>
          <?php if ($sms_possible): ?>
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" name="sms" value="1" id="b-sms" <?= $sms_coche ? 'checked' : '' ?>>
            <label class="form-check-label" for="b-sms">Prévenir le parent par SMS<?= sms_gw_actif() ? '' : ' (mis en attente : passerelle SMS non configurée)' ?></label>
          </div>
          <?php endif; ?>
          <button class="btn btn-primary btn-sm"><i class="bi bi-printer me-1"></i>Enregistrer et imprimer</button>
        </form>
      </div>
    </div>
    <?php if ($frequents): ?>
    <div class="card mt-3">
      <div class="card-body">
        <div class="section-titre"><i class="bi bi-exclamation-triangle me-1 text-warning"></i>Élèves les plus concernés cette année</div>
        <table class="table table-sm mb-0" style="font-size:.82rem">
          <?php foreach ($frequents as $f): ?><tr><td><?= h($f['nom']) ?> <span class="text-muted">— <?= h($f['classe']) ?></span></td><td class="text-end"><span class="badge bg-warning text-dark"><?= (int) $f['n'] ?></span></td></tr><?php endforeach; ?>
        </table>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="col-lg-7">
    <div class="card">
      <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
          <div class="section-titre mb-0"><i class="bi bi-journal-text me-1"></i>Registre — <?= h($T['libelle']) ?> (<?= count($registre) ?>)</div>
          <form method="get" class="d-flex flex-wrap gap-1">
            <input type="hidden" name="type" value="<?= $type ?>">
            <input type="search" name="q" class="form-control form-control-sm" style="width:150px" placeholder="Nom, n°, motif…" value="<?= h($q) ?>">
            <input type="date" name="du" class="form-control form-control-sm" style="width:135px" value="<?= h($du) ?>" title="Du">
            <input type="date" name="au" class="form-control form-control-sm" style="width:135px" value="<?= h($au) ?>" title="Au">
            <button class="btn btn-outline-primary btn-sm"><i class="bi bi-search"></i></button>
          </form>
        </div>
        <div class="table-responsive">
          <table class="table table-abz table-hover table-sm align-middle mb-0" style="font-size:.82rem">
            <thead><tr><th>N°</th><th>Date</th><th><?= $T['pour'] === 'eleve' ? 'Élève' : 'Agent' ?></th><th>Motif</th><th class="text-end"></th></tr></thead>
            <tbody>
            <?php foreach ($registre as $b): ?>
              <tr style="<?= $b['annule'] ? 'text-decoration:line-through;opacity:.55' : '' ?>">
                <td class="text-nowrap fw-semibold"><?= h($b['numero']) ?></td>
                <td class="text-nowrap"><?= h(date('d/m/Y', strtotime($b['date_billet']))) ?><?= $b['heure'] ? ' <span class="text-muted">' . h($b['heure']) . '</span>' : '' ?></td>
                <td><?= h($b['nom']) ?><?php if ($b['classe']): ?> <span class="text-muted">— <?= h($b['classe']) ?></span><?php endif; ?>
                  <?php if ($b['sms'] && $b['sms'] !== 'échec'): ?><i class="bi bi-chat-dots text-success ms-1" title="SMS envoyé au <?= h($b['sms']) ?>"></i><?php endif; ?></td>
                <td><?= h((string) $b['motif']) ?></td>
                <td class="text-end text-nowrap">
                  <button type="button" class="btn btn-sm btn-light" style="padding:2px 7px" title="Imprimer (souche + volet)"
                          onclick="afficherApercu('<?= APP_URL ?>/secondaire/commun/pages/billets/pdf.php?id=<?= (int) $b['id'] ?>', <?= h(json_encode($b['numero'])) ?>, 'billet', 'portrait')"><i class="bi bi-printer"></i></button>
                  <?php if (!$b['annule']): ?>
                  <form method="post" style="display:inline" onsubmit="return confirm('Annuler le billet <?= h($b['numero']) ?> ? Il restera dans le registre, barré.')">
                    <?= csrf_champ() ?><input type="hidden" name="action" value="annuler"><input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                    <button class="btn btn-sm btn-light" style="padding:2px 7px" title="Annuler"><i class="bi bi-x-circle text-danger"></i></button>
                  </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$registre): ?><tr><td colspan="5" class="text-center text-muted py-3">Aucun billet pour ces critères.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<?php if (!empty($_GET['imprimer'])): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  if (typeof afficherApercu === 'function') afficherApercu('<?= APP_URL ?>/secondaire/commun/pages/billets/pdf.php?id=<?= (int) $_GET['imprimer'] ?>', 'Billet', 'billet', 'portrait');
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../../../noyau/layout/footer.php'; ?>
