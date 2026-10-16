<?php
// secondaire/commun/pages/emploi_temps/generer.php — génération automatique sous contraintes.
// Les séances verrouillées ne bougent jamais ; les autres classes déjà
// construites contraignent les enseignants et les salles. L'état précédent
// des classes visées est sauvegardé : « Annuler la dernière génération ».
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
require_once __DIR__ . '/_edt_lib.php';
exiger_role(EDT_ROLES_GESTION);

$a = edt_annee_id();
$ecrire = edt_peut_ecrire();
$classes = edt_classes();
$rapport = $_SESSION['edt_rapport_generation'] ?? null;
unset($_SESSION['edt_rapport_generation']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ecrire) {
    csrf_verifier();
    if (post('action') === 'generer') {
        @set_time_limit(300);
        $cibles = post('portee') === 'toutes' ? array_column($classes, 'id') : array_map('intval', $_POST['classes'] ?? []);
        if (!$cibles) { flash_set('erreur', 'Choisissez au moins une classe.'); rediriger('secondaire/commun/pages/emploi_temps/generer.php'); }
        // sauvegarde des séances des classes visées
        $sauve = db_all("SELECT id_classe,sous_groupe,jour,id_creneau,id_service,id_mat,matricule_ens,id_salle,code_commun,verrou,origine FROM edt_seance WHERE id_annee=? AND id_classe IN (" . implode(',', array_map('intval', $cibles)) . ")", [$a]);
        edt_param_set($a, 'sauvegarde_generation', json_encode(['classes' => array_map('intval', $cibles), 'seances' => $sauve, 'le' => date('d/m/Y H:i')]));
        $res = edt_generer($a, $cibles, ['effacer' => post('effacer') === '1', 'essais' => (int) post('essais') ?: 8, 'duree' => (int) post('duree') ?: 40]);
        edt_enregistrer_generation($a, $res);
        $mod = new EdtModele($a, false);
        $_SESSION['edt_rapport_generation'] = [
            'classes' => count($cibles), 'places' => $res['places'], 'h_non' => $res['h_non_placees'], 'essais' => $res['essais_faits'] ?? 0,
            'non_places' => array_map(fn($d) => [
                'classes' => implode(', ', array_map(fn($c) => $mod->classes[$c]['designation'] ?? $c, $d['classes'])),
                'matiere' => $mod->mat[$d['id_mat']] ?? '?', 'ens' => $d['ens'] ? ($mod->ens[$d['ens']] ?? '?') : '— sans enseignant —', 'duree' => $d['duree'], 'raison' => $d['raison']], $res['non_places']),
        ];
        rediriger('secondaire/commun/pages/emploi_temps/generer.php');
    }
    if (post('action') === 'annuler') {
        $s = json_decode((string) edt_param($a, 'sauvegarde_generation', ''), true);
        if ($s && !empty($s['classes'])) {
            db_exec("DELETE FROM edt_seance WHERE id_annee=? AND id_classe IN (" . implode(',', array_map('intval', $s['classes'])) . ")", [$a]);
            foreach ($s['seances'] as $q) db_exec("INSERT IGNORE INTO edt_seance (id_annee,id_classe,sous_groupe,jour,id_creneau,id_service,id_mat,matricule_ens,id_salle,code_commun,verrou,origine) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
                [$a, $q['id_classe'], $q['sous_groupe'], $q['jour'], $q['id_creneau'], $q['id_service'], $q['id_mat'], $q['matricule_ens'], $q['id_salle'], $q['code_commun'], $q['verrou'], $q['origine']]);
            edt_param_set($a, 'sauvegarde_generation', null);
            flash_set('succes', 'Emploi du temps rétabli tel qu\'il était avant la génération du ' . $s['le'] . '.');
        }
        rediriger('secondaire/commun/pages/emploi_temps/generer.php');
    }
}

$sauvegarde = json_decode((string) edt_param($a, 'sauvegarde_generation', ''), true);
$nbServices = (int) db_val("SELECT COUNT(*) FROM edt_service WHERE id_annee=? AND heures>0", [$a]);
$sansEns = (int) db_val("SELECT COUNT(*) FROM edt_service WHERE id_annee=? AND heures>0 AND matricule_ens IS NULL", [$a]);
$nbRegles = (int) db_val("SELECT COUNT(*) FROM edt_contrainte WHERE id_annee=? AND actif=1", [$a]);
$titre_page = 'Emploi du temps — Construction';
require_once __DIR__ . '/../../../../noyau/layout/header.php';
echo edt_css();
?>
<?= edt_onglets('construction', 'generer') ?>
<?= flash_html() ?>

<?php if ($rapport): ?>
<div class="card mb-3 border-<?= $rapport['h_non'] ? 'warning' : 'success' ?>"><div class="card-body">
  <h6 class="fw-bold"><i class="bi bi-clipboard-check me-1"></i>Résultat de la génération</h6>
  <p class="mb-2"><?= (int) $rapport['classes'] ?> classe(s) traitée(s) · <b><?= (int) $rapport['places'] ?></b> période(s) placée(s) · meilleur résultat sur <?= (int) $rapport['essais'] ?> essai(s).
    <?= $rapport['h_non'] ? '<span class="text-danger fw-bold">' . (int) $rapport['h_non'] . ' h n\'ont pas pu être placées.</span>' : '<span class="text-success fw-bold">Tout a été placé.</span>' ?></p>
  <?php if ($rapport['non_places']): ?>
  <div class="table-responsive"><table class="table table-sm mb-2"><thead><tr><th>Classe(s)</th><th>Matière</th><th>Enseignant</th><th>Séance</th><th>Raison principale</th></tr></thead><tbody>
  <?php foreach ($rapport['non_places'] as $n): ?><tr><td><?= h($n['classes']) ?></td><td><?= h($n['matiere']) ?></td><td><?= h($n['ens']) ?></td><td><?= (int) $n['duree'] ?> h</td><td class="small"><?= h($n['raison']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <p class="small text-muted mb-0">Pistes : assouplir une règle (Préparation → Règles, passer en « souhaitée »), réduire la durée maximale d'une séance (Préparation → Services), répartir la charge d'un enseignant trop chargé, ou placer ces séances à la main (onglet Saisie manuelle) en voyant les cases libres.</p>
  <?php endif; ?>
  <div class="mt-2"><a class="btn btn-sm btn-primary" href="conflits.php"><i class="bi bi-exclamation-octagon"></i> Voir les contrôles</a> <a class="btn btn-sm btn-outline-primary" href="consulter.php"><i class="bi bi-calendar3-week"></i> Consulter</a></div>
</div></div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-7">
    <form method="post" class="card" onsubmit="document.getElementById('btnGen').disabled=true;document.getElementById('btnGen').innerHTML='<span class=&quot;spinner-border spinner-border-sm&quot;></span> Génération en cours…'">
      <div class="card-header"><b>Lancer une génération</b></div><div class="card-body">
      <?= csrf_champ() ?><input type="hidden" name="action" value="generer">
      <div class="form-check"><input class="form-check-input" type="radio" name="portee" value="toutes" id="pt" checked onchange="document.getElementById('choixCl').style.display='none'"><label class="form-check-label" for="pt">Toutes les classes</label></div>
      <div class="form-check mb-2"><input class="form-check-input" type="radio" name="portee" value="choix" id="pc" onchange="document.getElementById('choixCl').style.display=''"><label class="form-check-label" for="pc">Seulement certaines classes</label></div>
      <div id="choixCl" style="display:none;max-height:220px;overflow:auto" class="border rounded p-2 mb-2 small">
        <?php foreach ($classes as $c): ?><div class="form-check form-check-inline" style="min-width:150px"><input class="form-check-input" type="checkbox" name="classes[]" value="<?= $c['id'] ?>" id="c<?= $c['id'] ?>"><label class="form-check-label" for="c<?= $c['id'] ?>"><?= h($c['designation']) ?></label></div><?php endforeach; ?>
      </div>
      <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="effacer" value="1" id="ef" checked><label class="form-check-label" for="ef">Repartir de zéro pour ces classes (les séances <b>verrouillées</b> sont toujours conservées)</label></div>
      <div class="row g-2 mb-3">
        <div class="col-6"><label class="form-label small mb-1">Nombre d'essais (le meilleur est retenu)</label><input type="number" min="1" max="40" class="form-control form-control-sm" name="essais" value="8"></div>
        <div class="col-6"><label class="form-label small mb-1">Durée maximale (secondes)</label><input type="number" min="5" max="240" class="form-control form-control-sm" name="duree" value="40"></div>
      </div>
      <?php if ($ecrire): ?><button id="btnGen" class="btn btn-primary"><i class="bi bi-magic"></i> Générer</button><?php else: ?><div class="text-muted small">Réservé au Censeur, au Proviseur et à l'administrateur.</div><?php endif; ?>
    </div></form>
    <?php if ($sauvegarde && $ecrire): ?>
    <form method="post" class="mt-2" onsubmit="return confirm('Revenir à l\'état d\'avant la dernière génération ?')"><?= csrf_champ() ?><input type="hidden" name="action" value="annuler">
      <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-counterclockwise"></i> Annuler la dernière génération (<?= h($sauvegarde['le']) ?>, <?= count($sauvegarde['classes']) ?> classe(s))</button></form>
    <?php endif; ?>
  </div>
  <div class="col-lg-5">
    <div class="card"><div class="card-header"><b>Avant de générer</b></div><div class="card-body small">
      <ol class="mb-2 ps-3">
        <li class="mb-1"><b>Grille horaire</b> (Préparation) : périodes, récréation, pause, jours de cours.</li>
        <li class="mb-1"><b>Services</b> : <?= $nbServices ?> service(s) avec des heures<?= $sansEns ? ", dont <span class='text-danger'>$sansEns sans enseignant</span> (placés sans contrôle d'enseignant)" : '' ?>.</li>
        <li class="mb-1"><b>Règles</b> : <?= $nbRegles ?> règle(s) active(s)<?= $nbRegles ? '' : ' — pensez à « Charger les règles recommandées »' ?>.</li>
        <li class="mb-1"><b>Verrouillez</b> (onglet Saisie manuelle) les séances imposées : EPS sur le terrain, cours d'un vacataire, etc.</li>
      </ol>
      <p class="mb-1 fw-bold">Comment le générateur décide</p>
      <ul class="ps-3 mb-0">
        <li>il place d'abord les séances les plus difficiles (longs blocs d'atelier, cours communs, enseignants très chargés) ;</li>
        <li>il ne viole jamais un conflit (enseignant, classe, salle) ni une règle obligatoire ;</li>
        <li>il évite les trous dans la journée des élèves et des enseignants, étale chaque matière sur la semaine, garde l'enseignement général le matin et respecte au mieux les règles souhaitées ;</li>
        <li>il recommence plusieurs fois avec un ordre différent et garde le meilleur résultat.</li>
      </ul>
    </div></div>
  </div>
</div>
<?php require_once __DIR__ . '/../../../../noyau/layout/footer.php'; ?>
