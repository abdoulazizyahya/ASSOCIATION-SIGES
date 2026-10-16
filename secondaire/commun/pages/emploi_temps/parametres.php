<?php
// secondaire/commun/pages/emploi_temps/parametres.php — Paramètres de l'emploi du temps :
// grille horaire et jours ouvrés, règles de construction, salles/ateliers,
// copie depuis une autre année, remise à zéro (publication : consulter.php).
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
require_once __DIR__ . '/_edt_lib.php';
exiger_role(EDT_ROLES_GESTION);

$a = edt_annee_id();
$onglet = in_array($_GET['onglet'] ?? '', ['grille', 'regles', 'salles', 'annee'], true) ? $_GET['onglet'] : 'grille';
$ecrire = edt_peut_ecrire();
$retour = fn(string $o) => rediriger("secondaire/commun/pages/emploi_temps/parametres.php?onglet=$o");
$types = edt_types_contraintes();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ecrire) {
    csrf_verifier();
    $act = post('action');

    // ── Grille horaire ────────────────────────────────────────────────
    if ($act === 'grille') {
        $ids = $_POST['cr_id'] ?? [];
        $vus = [];
        foreach ($ids as $i => $cid) {
            $lib = trim($_POST['cr_lib'][$i] ?? ''); $d = $_POST['cr_deb'][$i] ?? ''; $f = $_POST['cr_fin'][$i] ?? '';
            $t = ($_POST['cr_type'][$i] ?? 'cours') === 'pause' ? 'pause' : 'cours';
            if ($lib === '' || !preg_match('/^\d{2}:\d{2}$/', $d) || !preg_match('/^\d{2}:\d{2}$/', $f) || $f <= $d) continue;
            if ((int) $cid) {
                db_exec("UPDATE edt_creneau SET libelle=?, heure_debut=?, heure_fin=?, type=? WHERE id=? AND id_annee=?", [$lib, $d, $f, $t, (int) $cid, $a]);
                $vus[] = (int) $cid;
            } else {
                db_exec("INSERT INTO edt_creneau (id_annee, ordre, libelle, heure_debut, heure_fin, type) VALUES (?,?,?,?,?,?)", [$a, 99, $lib, $d, $f, $t]);
                $vus[] = db_last_id();
            }
        }
        // périodes retirées : leurs séances partent avec elles
        $supp = array_diff(array_map('intval', array_column(db_all("SELECT id FROM edt_creneau WHERE id_annee=?", [$a]), 'id')), $vus);
        foreach ($supp as $sid) { db_exec("DELETE FROM edt_seance WHERE id_annee=? AND id_creneau=?", [$a, $sid]); db_exec("DELETE FROM edt_creneau WHERE id=?", [$sid]); }
        // ordre chronologique
        foreach (db_all("SELECT id FROM edt_creneau WHERE id_annee=? ORDER BY heure_debut", [$a]) as $k => $r) db_exec("UPDATE edt_creneau SET ordre=? WHERE id=?", [$k + 1, $r['id']]);
        $jours = array_values(array_filter(array_map('intval', $_POST['jours'] ?? []), fn($j) => $j >= 1 && $j <= 6));
        edt_param_set($a, 'jours', implode(',', $jours ?: [1, 2, 3, 4, 5]));
        edt_param_set($a, 'samedi_texte', trim(post('samedi_texte')));
        edt_param_set($a, 'pdf_nb', trim($_POST['pdf_nb'] ?? ''));
        edt_param_set($a, 'pdf_ville', trim(post('pdf_ville')));
        flash_set('succes', 'Grille horaire enregistrée' . ($supp ? ' (' . count($supp) . ' période(s) supprimée(s) avec leurs séances)' : '') . '.');
        $retour('grille');
    }
    if ($act === 'grille_defaut') {
        db_exec("DELETE FROM edt_seance WHERE id_annee=?", [$a]);
        db_exec("DELETE FROM edt_creneau WHERE id_annee=?", [$a]);
        foreach (edt_grille_defaut() as $i => [$lib, $d, $f, $t]) db_exec("INSERT INTO edt_creneau (id_annee, ordre, libelle, heure_debut, heure_fin, type) VALUES (?,?,?,?,?,?)", [$a, $i + 1, $lib, $d, $f, $t]);
        flash_set('succes', 'Grille par défaut rétablie (toutes les séances de l\'année ont été effacées).');
        $retour('grille');
    }

    // ── Règles ────────────────────────────────────────────────────────
    if ($act === 'regle_ajouter') {
        $type = post('type');
        if (isset($types[$type])) {
            $ct = post('cible_type');
            if (!in_array($ct, $types[$type]['cibles'], true)) $ct = $types[$type]['cibles'][0];
            $cid = $ct === 'tous' ? null : (trim($_POST['cible_' . $ct] ?? '') ?: null);
            $p = [];
            foreach (array_keys($types[$type]['params']) as $k) $p[$k] = trim($_POST['p_' . $k] ?? '');
            if ($ct !== 'tous' && $cid === null) flash_set('erreur', 'Choisissez la cible de la règle.');
            else {
                db_exec("INSERT INTO edt_contrainte (id_annee,type,cible_type,cible_id,params,`force`,actif,commentaire) VALUES (?,?,?,?,?,?,1,?)",
                        [$a, $type, $ct, $cid, json_encode($p, JSON_UNESCAPED_UNICODE), post('force') === 'souhaitee' ? 'souhaitee' : 'obligatoire', trim(post('commentaire')) ?: null]);
                flash_set('succes', 'Règle ajoutée.');
            }
        }
        $retour('regles');
    }
    if ($act === 'regle_basculer') { db_exec("UPDATE edt_contrainte SET actif=1-actif WHERE id=? AND id_annee=?", [(int) post('id'), $a]); $retour('regles'); }
    if ($act === 'regle_supprimer') { db_exec("DELETE FROM edt_contrainte WHERE id=? AND id_annee=?", [(int) post('id'), $a]); flash_set('succes', 'Règle supprimée.'); $retour('regles'); }
    if ($act === 'regles_recommandees') { $n = edt_regles_recommandees($a); flash_set('succes', "$n règle(s) recommandée(s) ajoutée(s)."); $retour('regles'); }

    // ── Salles ────────────────────────────────────────────────────────
    if ($act === 'salle_enregistrer') {
        $nom = trim(post('nom'));
        $type = in_array(post('type'), ['salle', 'atelier', 'laboratoire', 'salle_info', 'terrain', 'autre'], true) ? post('type') : 'salle';
        $cap = (int) post('capacite') ?: null;
        if ($nom !== '') {
            if ((int) post('id')) db_exec("UPDATE edt_salle SET nom=?, type=?, capacite=?, commentaire=? WHERE id=?", [$nom, $type, $cap, trim(post('commentaire')) ?: null, (int) post('id')]);
            else db_exec("INSERT IGNORE INTO edt_salle (nom, type, capacite, commentaire) VALUES (?,?,?,?)", [$nom, $type, $cap, trim(post('commentaire')) ?: null]);
            flash_set('succes', 'Salle enregistrée.');
        }
        $retour('salles');
    }
    if ($act === 'salle_basculer') { db_exec("UPDATE edt_salle SET actif=1-actif WHERE id=?", [(int) post('id')]); $retour('salles'); }

    // ── Copie / remise à zéro ──────────────────────────
    if ($act === 'copier') {
        $src = (int) post('annee_source');
        if ($src && $src !== $a) {
            $quoi = $_POST['quoi'] ?? [];
            $n = [];
            if (in_array('grille', $quoi, true)) {
                db_exec("DELETE FROM edt_seance WHERE id_annee=?", [$a]); db_exec("DELETE FROM edt_creneau WHERE id_annee=?", [$a]);
                db_exec("INSERT INTO edt_creneau (id_annee,ordre,libelle,heure_debut,heure_fin,type) SELECT ?,ordre,libelle,heure_debut,heure_fin,type FROM edt_creneau WHERE id_annee=?", [$a, $src]);
                db_exec("INSERT INTO edt_parametre (id_annee,cle,valeur) SELECT ?,cle,valeur FROM edt_parametre WHERE id_annee=? AND cle<>'publie' ON DUPLICATE KEY UPDATE valeur=VALUES(valeur)", [$a, $src]);
                $n[] = 'grille';
            }
            if (in_array('regles', $quoi, true)) {
                db_exec("INSERT INTO edt_contrainte (id_annee,type,cible_type,cible_id,params,`force`,actif,commentaire) SELECT ?,type,cible_type,cible_id,params,`force`,actif,commentaire FROM edt_contrainte WHERE id_annee=?", [$a, $src]);
                $n[] = 'règles';
            }
            if (in_array('services', $quoi, true)) {
                db_exec("DELETE FROM edt_service WHERE id_annee=?", [$a]);
                db_exec("INSERT INTO edt_service (id_annee,id_classe,id_mat,matricule_ens,heures,duree_min,duree_max,id_salle,code_commun,commentaire)
                         SELECT ?,id_classe,id_mat,matricule_ens,heures,duree_min,duree_max,id_salle,code_commun,commentaire FROM edt_service WHERE id_annee=?", [$a, $src]);
                db_exec("INSERT INTO edt_enseignant (id_annee,matricule_ens,heures_dues,discipline,grade) SELECT ?,matricule_ens,heures_dues,discipline,grade FROM edt_enseignant WHERE id_annee=?
                         ON DUPLICATE KEY UPDATE heures_dues=VALUES(heures_dues), discipline=VALUES(discipline), grade=VALUES(grade)", [$a, $src]);
                $n[] = 'services';
            }
            if (in_array('seances', $quoi, true)) {
                // les créneaux sont rapprochés par leur rang dans la journée
                $mapCr = [];
                $srcCr = array_values(array_filter(db_all("SELECT id FROM edt_creneau WHERE id_annee=? AND type='cours' ORDER BY ordre", [$src])));
                $dstCr = array_values(array_filter(db_all("SELECT id FROM edt_creneau WHERE id_annee=? AND type='cours' ORDER BY ordre", [$a])));
                foreach ($srcCr as $k => $r) if (isset($dstCr[$k])) $mapCr[(int) $r['id']] = (int) $dstCr[$k]['id'];
                db_exec("DELETE FROM edt_seance WHERE id_annee=?", [$a]);
                foreach (db_all("SELECT * FROM edt_seance WHERE id_annee=?", [$src]) as $s) {
                    if (!isset($mapCr[(int) $s['id_creneau']])) continue;
                    db_exec("INSERT IGNORE INTO edt_seance (id_annee,id_classe,sous_groupe,jour,id_creneau,id_mat,matricule_ens,id_salle,code_commun,verrou,origine) VALUES (?,?,?,?,?,?,?,?,?,?,?)",
                            [$a, $s['id_classe'], $s['sous_groupe'], $s['jour'], $mapCr[(int) $s['id_creneau']], $s['id_mat'], $s['matricule_ens'], $s['id_salle'], $s['code_commun'], $s['verrou'], $s['origine']]);
                }
                $n[] = 'séances';
            }
            flash_set('succes', $n ? 'Copié depuis l\'autre année : ' . implode(', ', $n) . '.' : 'Rien n\'était coché.');
        }
        $retour('annee');
    }
    if ($act === 'vider') {
        $n = post('tout') === '1' ? db_exec("DELETE FROM edt_seance WHERE id_annee=?", [$a]) : db_exec("DELETE FROM edt_seance WHERE id_annee=? AND verrou=0", [$a]);
        flash_set('succes', "$n période(s) de cours effacée(s).");
        $retour('annee');
    }
}

$creneaux = edt_creneaux($a);
$jours = edt_jours_ouvres($a);
$etab = get_etablissement();
$titre_page = 'Emploi du temps — Préparation';
require_once __DIR__ . '/../../../../noyau/layout/header.php';
echo edt_css();
?>
<?= edt_onglets('preparation', $onglet) ?>
<?= flash_html() ?>

<?php if ($onglet === 'grille'): ?>
<form method="post" class="card"><div class="card-body">
  <?= csrf_champ() ?><input type="hidden" name="action" value="grille">
  <div class="row g-4">
    <div class="col-lg-7">
      <h6 class="fw-bold">Périodes de la journée</h6>
      <p class="small text-muted mb-2">Les <b>pauses</b> (récréation, pause de midi) apparaissent comme des colonnes verticales, comme sur les fiches papier. Une séance peut les enjamber.</p>
      <table class="table table-sm align-middle" id="tabCr">
        <thead><tr><th>Libellé</th><th>Début</th><th>Fin</th><th>Type</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($creneaux as $c): ?>
          <tr><td><input type="hidden" name="cr_id[]" value="<?= (int) $c['id'] ?>"><input class="form-control form-control-sm" name="cr_lib[]" value="<?= h($c['libelle']) ?>"></td>
            <td><input type="time" class="form-control form-control-sm" name="cr_deb[]" value="<?= h($c['debut']) ?>"></td>
            <td><input type="time" class="form-control form-control-sm" name="cr_fin[]" value="<?= h($c['fin']) ?>"></td>
            <td><select class="form-select form-select-sm" name="cr_type[]"><option value="cours">Cours</option><option value="pause" <?= $c['type'] === 'pause' ? 'selected' : '' ?>>Pause</option></select></td>
            <td><?php if ($ecrire): ?><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()" title="Retirer"><i class="bi bi-x"></i></button><?php endif; ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php if ($ecrire): ?><button type="button" class="btn btn-sm btn-outline-primary" onclick="ajouterCr()"><i class="bi bi-plus"></i> Ajouter une période</button><?php endif; ?>
    </div>
    <div class="col-lg-5">
      <h6 class="fw-bold">Jours de cours</h6>
      <?php foreach (EDT_JOURS as $j => $lib): ?>
        <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="jours[]" value="<?= $j ?>" id="j<?= $j ?>" <?= in_array($j, $jours, true) ? 'checked' : '' ?>><label class="form-check-label" for="j<?= $j ?>"><?= $lib ?></label></div>
      <?php endforeach; ?>
      <h6 class="fw-bold mt-4">Fiche imprimée (PDF)</h6>
      <label class="form-label small mb-1">Ligne du samedi (laisser vide pour ne pas l'afficher)</label>
      <input class="form-control form-control-sm mb-2" name="samedi_texte" value="<?= h(edt_param($a, 'samedi_texte', 'RATTRAPAGE ET DEVOIRS SURVEILLÉS')) ?>">
      <label class="form-label small mb-1">Lieu de signature (« … , le »)</label>
      <input class="form-control form-control-sm mb-2" name="pdf_ville" value="<?= h(edt_param($a, 'pdf_ville', $etab['ville'] ?? '')) ?>">
      <label class="form-label small mb-1">Notes de bas de page (une par ligne)</label>
      <textarea class="form-control form-control-sm" rows="4" name="pdf_nb"><?= h(edt_param($a, 'pdf_nb', "Cet emploi de temps est essentiellement provisoire.\nTout changement opéré dans cet emploi de temps ne sera valide que s'il est confirmé par le Censeur.\nTous les enseignants qui ont cours à 07h30 min les lundi et vendredi sont tenus d'assister au rassemblement de 07h10 min.")) ?></textarea>
    </div>
  </div>
  <?php if ($ecrire): ?><div class="mt-3 d-flex gap-2"><button class="btn btn-primary"><i class="bi bi-check2"></i> Enregistrer</button></div><?php endif; ?>
</div></form>
<?php if ($ecrire): ?>
<form method="post" class="mt-2" onsubmit="return confirm('Rétablir la grille par défaut efface TOUTES les séances de l\'année. Continuer ?')">
  <?= csrf_champ() ?><input type="hidden" name="action" value="grille_defaut">
  <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-counterclockwise"></i> Rétablir la grille type (9 périodes, récréation 10h00, pause 12h40)</button>
</form>
<?php endif; ?>
<script>
function ajouterCr(){const tb=document.querySelector('#tabCr tbody');const tr=document.createElement('tr');
tr.innerHTML='<td><input type="hidden" name="cr_id[]" value="0"><input class="form-control form-control-sm" name="cr_lib[]" placeholder="10ème H"></td><td><input type="time" class="form-control form-control-sm" name="cr_deb[]"></td><td><input type="time" class="form-control form-control-sm" name="cr_fin[]"></td><td><select class="form-select form-select-sm" name="cr_type[]"><option value="cours">Cours</option><option value="pause">Pause</option></select></td><td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest(\'tr\').remove()"><i class="bi bi-x"></i></button></td>';tb.appendChild(tr);}
</script>

<?php elseif ($onglet === 'regles'):
    $regles = db_all("SELECT * FROM edt_contrainte WHERE id_annee=? ORDER BY actif DESC, type, id", [$a]);
    $mats = db_all("SELECT DISTINCT m.id, TRIM(m.libelle) libelle FROM matiere m JOIN discipline d ON d.id_mat=m.id WHERE m.actif=1 ORDER BY libelle");
    $groupes = db_all("SELECT DISTINCT libelle_groupe_comp l FROM groupe ORDER BY l");
    $ens = edt_enseignants(); $classes = edt_classes(); $salles = edt_salles();
    $niveaux = db_all("SELECT code_niveau, libelle_niv FROM niveau ORDER BY id_cycle, ordre_niveau");
    $nomCible = function (array $r) use ($mats, $ens, $classes, $salles, $niveaux) {
        $id = (string) $r['cible_id'];
        $f = fn($list, $k, $l) => ($x = current(array_filter($list, fn($e) => (string) $e[$k] === $id))) ? $x[$l] : $id;
        return match ($r['cible_type']) {
            'tous' => 'Tout l\'établissement', 'matiere' => $f($mats, 'id', 'libelle'), 'groupe' => mb_strtolower($id), 'enseignant' => $f($ens, 'matricule_ens', 'nom'),
            'classe' => $f($classes, 'id', 'designation'), 'niveau' => $f($niveaux, 'code_niveau', 'libelle_niv'), 'salle' => $f($salles, 'id', 'nom'), default => $id };
    };
?>
<div class="alert alert-info small"><i class="bi bi-lightbulb me-1"></i>
  Les règles <b>obligatoires</b> ne sont jamais enfreintes par la génération automatique et sont signalées en rouge dans Construction → Contrôles.
  Les règles <b>souhaitées</b> sont respectées autant que possible. Les conflits d'emploi du temps (un enseignant, une classe ou une salle à deux endroits à la fois) sont <b>toujours</b> interdits, sans règle à créer.</div>
<div class="row g-3">
  <div class="col-xl-7">
    <div class="card"><div class="card-header d-flex justify-content-between align-items-center"><b>Règles de l'année</b>
      <?php if ($ecrire): ?><form method="post" class="m-0"><?= csrf_champ() ?><input type="hidden" name="action" value="regles_recommandees"><button class="btn btn-sm btn-outline-primary"><i class="bi bi-stars"></i> Charger les règles recommandées</button></form><?php endif; ?></div>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead><tr><th>Règle</th><th>Cible</th><th>Paramètres</th><th>Force</th><th></th></tr></thead><tbody>
        <?php if (!$regles): ?><tr><td colspan="5" class="text-muted text-center py-3">Aucune règle. Commencez par « Charger les règles recommandées ».</td></tr><?php endif; ?>
        <?php foreach ($regles as $r): $p = json_decode((string) $r['params'], true) ?: []; ?>
          <tr class="<?= $r['actif'] ? '' : 'text-muted opacity-50' ?>">
            <td><b><?= h($types[$r['type']]['libelle'] ?? $r['type']) ?></b><?= $r['commentaire'] ? '<div class="small text-muted">' . h($r['commentaire']) . '</div>' : '' ?></td>
            <td class="small"><?= h(edt_libelle_cible_type($r['cible_type'])) ?><?= $r['cible_type'] !== 'tous' ? ' : <b>' . h($nomCible($r)) . '</b>' : '' ?></td>
            <td class="small"><?= h(implode(' · ', array_map(fn($k, $v) => ($types[$r['type']]['params'][$k] ?? $k) . ' ' . $v, array_keys($p), $p))) ?: '—' ?></td>
            <td><span class="badge <?= $r['force'] === 'obligatoire' ? 'bg-danger' : 'bg-warning text-dark' ?>"><?= $r['force'] === 'obligatoire' ? 'Obligatoire' : 'Souhaitée' ?></span></td>
            <td class="text-nowrap"><?php if ($ecrire): ?>
              <form method="post" class="d-inline"><?= csrf_champ() ?><input type="hidden" name="action" value="regle_basculer"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn btn-sm btn-outline-secondary" title="<?= $r['actif'] ? 'Désactiver' : 'Activer' ?>"><i class="bi bi-<?= $r['actif'] ? 'pause' : 'play' ?>"></i></button></form>
              <form method="post" class="d-inline" onsubmit="return confirm('Supprimer cette règle ?')"><?= csrf_champ() ?><input type="hidden" name="action" value="regle_supprimer"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
            <?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table></div></div>
  </div>
  <?php if ($ecrire): ?>
  <div class="col-xl-5">
    <form method="post" class="card"><div class="card-header"><b>Nouvelle règle</b></div><div class="card-body">
      <?= csrf_champ() ?><input type="hidden" name="action" value="regle_ajouter">
      <label class="form-label small mb-1">Type de règle</label>
      <select class="form-select form-select-sm mb-1" name="type" id="rType" onchange="majRegle()">
        <?php foreach ($types as $k => $t): ?><option value="<?= $k ?>" data-cibles="<?= h(implode(',', $t['cibles'])) ?>" data-params="<?= h(implode(',', array_keys($t['params']))) ?>" data-aide="<?= h($t['aide']) ?>"><?= h($t['libelle']) ?></option><?php endforeach; ?>
      </select>
      <div class="small text-muted mb-2" id="rAide"></div>
      <label class="form-label small mb-1">S'applique à</label>
      <select class="form-select form-select-sm mb-2" name="cible_type" id="rCible" onchange="majCible()"></select>
      <select class="form-select form-select-sm mb-2 r-cible" name="cible_matiere" data-c="matiere"><?php foreach ($mats as $m): ?><option value="<?= $m['id'] ?>"><?= h($m['libelle']) ?></option><?php endforeach; ?></select>
      <select class="form-select form-select-sm mb-2 r-cible" name="cible_groupe" data-c="groupe"><?php foreach ($groupes as $g): ?><option value="<?= h($g['l']) ?>"><?= h($g['l']) ?></option><?php endforeach; ?></select>
      <select class="form-select form-select-sm mb-2 r-cible" name="cible_enseignant" data-c="enseignant"><?php foreach ($ens as $e): ?><option value="<?= $e['matricule_ens'] ?>"><?= h($e['nom']) ?></option><?php endforeach; ?></select>
      <select class="form-select form-select-sm mb-2 r-cible" name="cible_classe" data-c="classe"><?php foreach ($classes as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['designation']) ?></option><?php endforeach; ?></select>
      <select class="form-select form-select-sm mb-2 r-cible" name="cible_niveau" data-c="niveau"><?php foreach ($niveaux as $n): ?><option value="<?= h($n['code_niveau']) ?>"><?= h($n['libelle_niv']) ?></option><?php endforeach; ?></select>
      <select class="form-select form-select-sm mb-2 r-cible" name="cible_salle" data-c="salle"><?php foreach ($salles as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['nom']) ?></option><?php endforeach; ?></select>
      <div class="row g-2 mb-2">
        <div class="col-6 r-p" data-p="debut"><label class="form-label small mb-1">Début</label><input type="time" class="form-control form-control-sm" name="p_debut" value="07:30"></div>
        <div class="col-6 r-p" data-p="fin"><label class="form-label small mb-1">Fin</label><input type="time" class="form-control form-control-sm" name="p_fin" value="12:40"></div>
        <div class="col-12 r-p" data-p="cases"><label class="form-label small mb-1">Créneaux (jour + n° de période)</label><input class="form-control form-control-sm" name="p_cases" placeholder="Mer 7-9, Ven 1"></div>
        <div class="col-6 r-p" data-p="max"><label class="form-label small mb-1">Maximum</label><input type="number" min="1" max="20" class="form-control form-control-sm" name="p_max" value="2"></div>
      </div>
      <label class="form-label small mb-1">Force</label>
      <select class="form-select form-select-sm mb-2" name="force"><option value="obligatoire">Obligatoire</option><option value="souhaitee">Souhaitée</option></select>
      <label class="form-label small mb-1">Commentaire (affiché dans la grille pour les créneaux interdits, ex. « A.P.P.S. »)</label>
      <input class="form-control form-control-sm mb-3" name="commentaire">
      <button class="btn btn-primary btn-sm"><i class="bi bi-plus"></i> Ajouter la règle</button>
    </div></form>
  </div>
  <?php endif; ?>
</div>
<script>
const LIB_CIBLE={tous:"Tout l'établissement",matiere:'Une matière',groupe:'Un groupe de matières',enseignant:'Un enseignant',classe:'Une classe',niveau:'Un niveau',salle:'Une salle'};
function majRegle(){const o=document.querySelector('#rType').selectedOptions[0];if(!o)return;const s=document.querySelector('#rCible');s.innerHTML='';
o.dataset.cibles.split(',').forEach(c=>s.insertAdjacentHTML('beforeend','<option value="'+c+'">'+LIB_CIBLE[c]+'</option>'));
const ps=o.dataset.params?o.dataset.params.split(','):[];document.querySelectorAll('.r-p').forEach(d=>d.style.display=ps.includes(d.dataset.p)?'':'none');
document.querySelector('#rAide').textContent=o.dataset.aide;majCible();}
function majCible(){const c=document.querySelector('#rCible').value;document.querySelectorAll('.r-cible').forEach(s=>{s.style.display=s.dataset.c===c?'':'none';s.disabled=s.dataset.c!==c;});}
if(document.querySelector('#rType'))majRegle();
</script>

<?php elseif ($onglet === 'salles'):
    $salles = edt_salles(false);
    $edit = (int) ($_GET['id'] ?? 0); $sal = $edit ? db_one("SELECT * FROM edt_salle WHERE id=?", [$edit]) : null;
    $typesSalle = ['salle' => 'Salle de classe', 'atelier' => 'Atelier', 'laboratoire' => 'Laboratoire', 'salle_info' => 'Salle informatique', 'terrain' => 'Terrain / plateau EPS', 'autre' => 'Autre'];
?>
<div class="row g-3">
  <div class="col-lg-7"><div class="card"><div class="card-header"><b>Salles, ateliers et laboratoires</b></div>
    <div class="table-responsive"><table class="table table-sm mb-0 align-middle"><thead><tr><th>Nom</th><th>Type</th><th>Capacité</th><th>Remarque</th><th></th></tr></thead><tbody>
    <?php if (!$salles): ?><tr><td colspan="5" class="text-muted text-center py-3">Aucune salle. Les salles sont facultatives : renseignez au moins les ateliers et laboratoires partagés pour éviter les doubles réservations.</td></tr><?php endif; ?>
    <?php foreach ($salles as $s): ?><tr class="<?= $s['actif'] ? '' : 'opacity-50' ?>"><td><b><?= h($s['nom']) ?></b></td><td><?= h($typesSalle[$s['type']] ?? $s['type']) ?></td><td><?= $s['capacite'] ?: '—' ?></td><td class="small"><?= h($s['commentaire']) ?></td>
      <td class="text-nowrap"><?php if ($ecrire): ?><a class="btn btn-sm btn-outline-primary" href="?onglet=salles&id=<?= $s['id'] ?>"><i class="bi bi-pencil"></i></a>
      <form method="post" class="d-inline"><?= csrf_champ() ?><input type="hidden" name="action" value="salle_basculer"><input type="hidden" name="id" value="<?= $s['id'] ?>"><button class="btn btn-sm btn-outline-secondary" title="<?= $s['actif'] ? 'Désactiver' : 'Réactiver' ?>"><i class="bi bi-<?= $s['actif'] ? 'eye-slash' : 'eye' ?>"></i></button></form><?php endif; ?></td></tr><?php endforeach; ?>
    </tbody></table></div></div></div>
  <?php if ($ecrire): ?>
  <div class="col-lg-5"><form method="post" class="card"><div class="card-header"><b><?= $sal ? 'Modifier' : 'Nouvelle salle' ?></b></div><div class="card-body">
    <?= csrf_champ() ?><input type="hidden" name="action" value="salle_enregistrer"><input type="hidden" name="id" value="<?= (int) ($sal['id'] ?? 0) ?>">
    <input class="form-control form-control-sm mb-2" name="nom" placeholder="Nom (ex. Atelier Électricité)" value="<?= h($sal['nom'] ?? '') ?>" required>
    <select class="form-select form-select-sm mb-2" name="type"><?php foreach ($typesSalle as $k => $l): ?><option value="<?= $k ?>" <?= ($sal['type'] ?? '') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
    <input type="number" class="form-control form-control-sm mb-2" name="capacite" placeholder="Capacité" value="<?= h((string) ($sal['capacite'] ?? '')) ?>">
    <input class="form-control form-control-sm mb-3" name="commentaire" placeholder="Remarque" value="<?= h($sal['commentaire'] ?? '') ?>">
    <button class="btn btn-primary btn-sm">Enregistrer</button> <?php if ($sal): ?><a href="?onglet=salles" class="btn btn-sm btn-light">Annuler</a><?php endif; ?>
  </div></form></div>
  <?php endif; ?>
</div>

<?php else:
    $annees = db_all("SELECT id, libelle FROM annee_scolaire WHERE id<>? ORDER BY libelle DESC", [$a]);
?>
<div class="row g-3">
  <?php if (!$ecrire): ?><div class="col-12"><div class="alert alert-light border small">Réservé au Censeur, au Proviseur et à l'administrateur.</div></div><?php endif; ?>
  <?php if ($ecrire): ?>
  <div class="col-lg-6"><form method="post" class="card h-100" onsubmit="return confirm('Les éléments cochés de l\'année en cours seront remplacés. Continuer ?')"><div class="card-header"><b>Copier depuis une autre année</b></div><div class="card-body">
    <?= csrf_champ() ?><input type="hidden" name="action" value="copier">
    <select class="form-select form-select-sm mb-2" name="annee_source" required><option value="">— Année source —</option><?php foreach ($annees as $an): ?><option value="<?= $an['id'] ?>"><?= h($an['libelle']) ?></option><?php endforeach; ?></select>
    <?php foreach (['grille' => 'Grille horaire et réglages du PDF', 'regles' => 'Règles de construction (ajoutées)', 'services' => 'Services et heures dues', 'seances' => 'Séances (emploi du temps complet)'] as $k => $l): ?>
      <div class="form-check"><input class="form-check-input" type="checkbox" name="quoi[]" value="<?= $k ?>" id="q<?= $k ?>"><label class="form-check-label" for="q<?= $k ?>"><?= $l ?></label></div>
    <?php endforeach; ?>
    <button class="btn btn-outline-primary btn-sm mt-3"><i class="bi bi-files"></i> Copier</button>
  </div></form></div>
  <div class="col-12"><form method="post" class="card border-danger" onsubmit="return confirm('Effacer les séances ? Cette action est définitive.')"><div class="card-body d-flex flex-wrap align-items-center gap-3">
    <?= csrf_champ() ?><input type="hidden" name="action" value="vider">
    <div><b class="text-danger">Remettre l'emploi du temps à zéro</b><div class="small text-muted">Les services, la grille et les règles sont conservés.</div></div>
    <div class="form-check"><input class="form-check-input" type="checkbox" name="tout" value="1" id="vt"><label class="form-check-label" for="vt">y compris les séances verrouillées</label></div>
    <button class="btn btn-outline-danger btn-sm ms-auto"><i class="bi bi-eraser"></i> Effacer les séances</button>
  </div></form></div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/../../../../noyau/layout/footer.php'; ?>
