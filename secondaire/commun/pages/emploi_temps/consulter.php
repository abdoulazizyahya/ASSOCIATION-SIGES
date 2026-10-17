<?php
// secondaire/commun/pages/emploi_temps/consulter.php — consultation et impression :
// par classe, par enseignant, par salle, vue générale d'une journée ;
// « Mon emploi du temps » pour l'enseignant connecté (moi=1).
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
require_once __DIR__ . '/_edt_lib.php';
exiger_role(array_merge(EDT_ROLES_GESTION, ['ENSEIGNANT', 'SECRETAIRE', 'INTENDANT']));

$a = edt_annee_id();
$gestion = in_array(role_connecte(), EDT_ROLES_GESTION, true) || (function_exists('est_fondateur') && est_fondateur());
$moi = (int) (matricule_ens_courant() ?? 0);
$vue = in_array($_GET['vue'] ?? '', ['classe', 'enseignant', 'salle', 'generale'], true) ? $_GET['vue'] : ($gestion ? 'classe' : 'enseignant');
if (!$gestion) $vue = 'enseignant';                                   // un enseignant ne voit que le sien
if (isset($_GET['moi'])) $vue = 'enseignant';
$classes = edt_classes();
$ens = edt_enseignants();
$salles = edt_salles();
$id = (int) ($_GET['id'] ?? 0);
if ($vue === 'enseignant' && (!$gestion || isset($_GET['moi']) || !$id)) $id = $id && $gestion && !isset($_GET['moi']) ? $id : $moi;
if ($vue === 'classe' && !$id) $id = (int) ($classes[0]['id'] ?? 0);
if ($vue === 'salle' && !$id) $id = (int) ($salles[0]['id'] ?? 0);
$jourG = (int) ($_GET['jour'] ?? edt_jours_ouvres($a)[0]);
// Publication (les enseignants voient leur emploi du temps une fois publié)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'publier' && edt_peut_ecrire()) {
    csrf_verifier();
    edt_param_set($a, 'publie', post('publie') === '1' ? '1' : '0');
    flash_set('succes', post('publie') === '1' ? 'Emploi du temps publié : chaque enseignant le voit dans « Mon emploi du temps ».' : 'Emploi du temps repassé en préparation : les enseignants ne le voient plus.');
    rediriger('secondaire/commun/pages/emploi_temps/consulter.php?' . http_build_query(array_intersect_key($_GET, ['vue' => 1, 'id' => 1])));
}
$publie = edt_publie($a);
$cache = !$gestion && !$publie;

$mod = new EdtModele($a);
$titre_page = 'Emploi du temps';
require_once __DIR__ . '/../../../../noyau/layout/header.php';
echo edt_css();
$pdf = APP_URL . '/secondaire/commun/pages/emploi_temps/pdf.php';
?>
<div class="page-titre d-flex flex-wrap align-items-center gap-2">
  <h4 class="mb-0"><i class="bi bi-calendar3-week me-1 text-primary"></i><?= isset($_GET['moi']) || !$gestion ? 'Mon emploi du temps' : 'Emploi du temps — Consulter / imprimer' ?> <small class="text-muted fs-6">Année <?= h(get_annee_active()['val_annee']) ?></small></h4>
  <?php if ($gestion): ?>
  <div class="ms-auto d-flex gap-2 align-items-center no-print">
    <span class="badge <?= $publie ? 'bg-success' : 'bg-secondary' ?>"><?= $publie ? 'Publié' : 'En préparation' ?></span>
    <?php if (edt_peut_ecrire()): ?>
    <form method="post" class="m-0"><?= csrf_champ() ?><input type="hidden" name="action" value="publier"><input type="hidden" name="publie" value="<?= $publie ? '0' : '1' ?>">
      <button class="btn btn-sm <?= $publie ? 'btn-outline-secondary' : 'btn-success' ?>" title="<?= $publie ? 'Les enseignants ne le verront plus' : 'Chaque enseignant le verra dans « Mon emploi du temps »' ?>"><i class="bi bi-<?= $publie ? 'eye-slash' : 'send-check' ?>"></i> <?= $publie ? 'Dépublier' : 'Publier' ?></button></form>
    <?php endif; ?>
    <a class="btn btn-sm btn-outline-success" href="import.php?export=1" title="Tout l'emploi du temps, au format du modèle d'import"><i class="bi bi-file-earmark-excel"></i> Exporter Excel</a>
  </div>
  <?php endif; ?>
</div>
<?= flash_html() ?>

<?php if ($cache): ?>
  <div class="alert alert-secondary"><i class="bi bi-hourglass-split me-1"></i>L'emploi du temps de cette année est en cours de préparation par la direction. Il apparaîtra ici dès sa publication.</div>
<?php elseif (!$gestion && !$moi): ?>
  <div class="alert alert-warning">Votre compte n'est relié à aucune fiche enseignant : demandez à l'administration de le rattacher.</div>
<?php else: ?>

<?php if ($gestion): ?>
<ul class="nav nav-tabs mb-3 no-print">
  <?php foreach (['classe' => ['Classe', 'door-open'], 'enseignant' => ['Enseignant', 'person-badge'], 'salle' => ['Salle', 'building'], 'generale' => ['Journée (tout le lycée)', 'grid-3x3']] as $k => [$l, $i]): ?>
  <li class="nav-item"><a class="nav-link <?= $vue === $k ? 'active' : '' ?>" href="?vue=<?= $k ?>"><i class="bi bi-<?= $i ?> me-1"></i><?= $l ?></a></li>
  <?php endforeach; ?>
</ul>
<?php endif; ?>

<?php if ($vue !== 'generale'): ?>
<div class="card mb-3 no-print"><div class="card-body py-2 d-flex flex-wrap gap-2 align-items-center">
  <?php if ($gestion): ?>
  <form method="get" class="d-flex gap-2 align-items-center m-0"><input type="hidden" name="vue" value="<?= $vue ?>">
    <select class="form-select form-select-sm w-auto" name="id" onchange="this.form.submit()">
      <?php if ($vue === 'classe') foreach ($classes as $c): ?><option value="<?= $c['id'] ?>" <?= (int) $c['id'] === $id ? 'selected' : '' ?>><?= h($c['designation']) ?></option><?php endforeach; ?>
      <?php if ($vue === 'enseignant'): ?><option value="0">— Choisir un enseignant —</option><?php foreach ($ens as $e): ?><option value="<?= $e['matricule_ens'] ?>" <?= (int) $e['matricule_ens'] === $id ? 'selected' : '' ?>><?= h($e['nom']) ?></option><?php endforeach; endif; ?>
      <?php if ($vue === 'salle') foreach ($salles as $s): ?><option value="<?= $s['id'] ?>" <?= (int) $s['id'] === $id ? 'selected' : '' ?>><?= h($s['nom']) ?></option><?php endforeach; ?>
    </select>
  </form>
  <?php endif; ?>
  <?php if ($id && $vue !== 'salle'): ?>
  <a class="btn btn-sm btn-primary" target="_blank" href="<?= $pdf ?>?vue=<?= $vue ?>&id=<?= $id ?>"><i class="bi bi-file-earmark-pdf"></i> Imprimer (PDF)</a>
  <?php endif; ?>
  <?php if ($gestion && $vue !== 'salle'): ?>
  <a class="btn btn-sm btn-outline-primary" target="_blank" href="<?= $pdf ?>?vue=<?= $vue ?>&tous=1"><i class="bi bi-files"></i> <?= $vue === 'classe' ? 'Toutes les classes' : 'Tous les enseignants' ?> (PDF)</a>
  <?php if ($vue === 'classe' && edt_peut_ecrire()): ?><a class="btn btn-sm btn-outline-secondary" href="saisie.php?classe=<?= $id ?>"><i class="bi bi-pencil-square"></i> Modifier</a><?php endif; ?>
  <?php endif; ?>
</div></div>

<?php if ($vue === 'salle' && !$salles): ?>
  <div class="alert alert-light border">Aucune salle définie (Préparation → Salles).</div>
<?php elseif ($id): ?>
  <?php
  // En-tête d'information (comme la fiche papier)
  if ($vue === 'enseignant') {
      $e = current(array_filter($ens, fn($x) => (int) $x['matricule_ens'] === $id)) ?: ['nom' => '?', 'tel_ens' => ''];
      $d = db_one("SELECT * FROM edt_enseignant WHERE id_annee=? AND matricule_ens=?", [$a, $id]) ?? [];
      $faites = (int) db_val("SELECT COUNT(DISTINCT jour, id_creneau) FROM edt_seance WHERE id_annee=? AND matricule_ens=?", [$a, $id]);
      $dues = isset($d['heures_dues']) && $d['heures_dues'] !== null ? (float) $d['heures_dues'] : null;
      echo '<div class="row g-2 mb-3">';
      foreach ([['Enseignant', $e['nom']], ['Discipline', $d['discipline'] ?? '—'], ['Heures dues', $dues !== null ? rtrim(rtrim((string) $dues, '0'), '.') . ' h' : '—'],
                ['Heures faites', $faites . ' h'], ['Heures supplémentaires', $dues !== null ? max(0, $faites - $dues) . ' h' : '—'], ['Téléphone', $e['tel_ens'] ?: '—']] as [$l, $v])
          echo '<div class="col-6 col-md-2"><div class="card edt-kpi"><div class="card-body py-2"><div class="small text-muted">' . h($l) . '</div><div class="fw-bold">' . h((string) $v) . '</div></div></div></div>';
      echo '</div>';
  } elseif ($vue === 'classe') {
      $pp = db_val("SELECT TRIM(CONCAT(e.nom_ens,' ',IFNULL(e.prenom_ens,''))) FROM " . edt_t('enseignat_principal') . " p JOIN enseignant e ON e.matricule_ens=p.matricule_ens WHERE p.IDClasses=? AND p.val_annee=?", [$id, get_annee_active()['val_annee']]);
      $vol = (int) db_val("SELECT COUNT(*) FROM edt_seance WHERE id_annee=? AND id_classe=? AND sous_groupe IN ('', 'A')", [$a, $id]);
      $prevu = (float) db_val("SELECT COALESCE(SUM(heures),0) FROM edt_service WHERE id_annee=? AND id_classe=?", [$a, $id]);
      echo '<div class="small mb-2">Professeur principal : <b>' . h($pp ?: '—') . '</b> · Volume placé : <b>' . $vol . ' h</b> / ' . rtrim(rtrim((string) $prevu, '0'), '.') . ' h prévues</div>';
  }
  echo edt_grille_html($a, $vue, $id, ['modele' => $mod]);
  ?>
<?php endif; ?>

<?php else: /* vue générale d'une journée */
    $cours = edt_creneaux_cours($a);
    $parCase = [];
    foreach ($mod->seances as $s) if ($s['jour'] === $jourG) $parCase[$s['id_classe']][$s['id_creneau']][] = $s;
    $ensJour = [];
    foreach ($mod->seances as $s) if ($s['jour'] === $jourG && $s['matricule_ens']) $ensJour[$s['matricule_ens']][$s['id_creneau']][] = $s;
    $mode = ($_GET['mode'] ?? 'classes') === 'enseignants' ? 'enseignants' : 'classes';
?>
<form method="get" class="card mb-3 no-print"><div class="card-body py-2 d-flex flex-wrap gap-2 align-items-center">
  <input type="hidden" name="vue" value="generale">
  <select class="form-select form-select-sm w-auto" name="jour" onchange="this.form.submit()"><?php foreach (edt_jours_ouvres($a) as $j): ?><option value="<?= $j ?>" <?= $j === $jourG ? 'selected' : '' ?>><?= EDT_JOURS[$j] ?></option><?php endforeach; ?></select>
  <div class="btn-group btn-group-sm"><a class="btn btn-<?= $mode === 'classes' ? 'primary' : 'outline-primary' ?>" href="?vue=generale&jour=<?= $jourG ?>&mode=classes">Classes</a><a class="btn btn-<?= $mode === 'enseignants' ? 'primary' : 'outline-primary' ?>" href="?vue=generale&jour=<?= $jourG ?>&mode=enseignants">Enseignants</a></div>
  <span class="small text-muted ms-2"><?= $mode === 'enseignants' ? 'Une case rouge = enseignant attendu dans deux classes en même temps.' : 'Qui est où, période par période.' ?></span>
</div></form>
<div class="table-responsive"><table class="table table-bordered table-sm edt-grille" style="font-size:.72rem">
  <thead><tr><th style="width:150px"><?= $mode === 'classes' ? 'Classe' : 'Enseignant' ?></th><?php foreach ($cours as $c): ?><th><?= h($c['libelle']) ?><div class="small text-muted"><?= h($c['debut']) ?></div></th><?php endforeach; ?></tr></thead><tbody>
  <?php if ($mode === 'classes'): foreach ($classes as $c): ?>
    <tr><th class="text-start"><?= h($c['designation']) ?></th>
    <?php foreach ($cours as $cr => $_): $ss = $parCase[(int) $c['id']][$cr] ?? []; ?>
      <td style="height:auto;<?= $ss ? 'background:' . edt_couleur($ss[0]['id_mat']) : '' ?>"><?php foreach ($ss as $s): ?><div><b><?= h(mb_strimwidth($mod->mat[$s['id_mat']] ?? '', 0, 18, '…')) ?></b><br><span class="text-muted"><?= h(edt_nom_court($mod->ens[$s['matricule_ens']] ?? '')) ?></span></div><?php endforeach; ?></td>
    <?php endforeach; ?></tr>
  <?php endforeach; else:
      $liste = array_filter($ens, fn($e) => isset($ensJour[(int) $e['matricule_ens']]));
      foreach ($liste as $e): $m = (int) $e['matricule_ens']; ?>
    <tr><th class="text-start"><?= h($e['nom']) ?></th>
    <?php foreach ($cours as $cr => $_): $ss = $ensJour[$m][$cr] ?? [];
        $codes = array_unique(array_map(fn($s) => $s['code_commun'] ?: 'x' . $s['id'], $ss)); $conf = count($codes) > 1; ?>
      <td style="height:auto" class="<?= $conf ? 'edt-conflit' : '' ?>"><?php foreach ($ss as $s): ?><div><?= h($mod->classes[$s['id_classe']]['designation'] ?? '') ?></div><?php endforeach; ?></td>
    <?php endforeach; ?></tr>
  <?php endforeach; if (!$liste): ?><tr><td colspan="<?= count($cours) + 1 ?>" class="text-muted text-center">Aucun cours ce jour-là.</td></tr><?php endif; endif; ?>
  </tbody></table></div>
<?php endif; ?>
<?php endif; ?>
<?php require_once __DIR__ . '/../../../../noyau/layout/footer.php'; ?>
