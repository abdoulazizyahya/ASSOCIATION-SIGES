<?php
// secondaire/commun/pages/emploi_temps/services.php — Services (charges horaires) :
// classe × matière × enseignant × heures/semaine, heures dues des enseignants,
// cours communs (une séance partagée par plusieurs classes).
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
require_once __DIR__ . '/_edt_lib.php';
exiger_role(EDT_ROLES_GESTION);

$a = edt_annee_id();
$onglet = in_array($_GET['onglet'] ?? '', ['classe', 'enseignants'], true) ? $_GET['onglet'] : 'classe';
$ecrire = edt_peut_ecrire();
$classes = edt_classes();
$id_classe = (int) ($_GET['classe'] ?? ($classes[0]['id'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ecrire) {
    csrf_verifier();
    $act = post('action');
    if ($act === 'services' && $id_classe) {
        $n = 0;
        foreach ($_POST['s_mat'] ?? [] as $i => $mat) {
            $sid = (int) ($_POST['s_id'][$i] ?? 0);
            $mat = (int) $mat;
            $h = (float) str_replace(',', '.', $_POST['s_h'][$i] ?? '0');
            $ens = (int) ($_POST['s_ens'][$i] ?? 0) ?: null;
            $dmin = max(1, (int) ($_POST['s_dmin'][$i] ?? 1)); $dmax = max($dmin, (int) ($_POST['s_dmax'][$i] ?? 2));
            $salle = (int) ($_POST['s_salle'][$i] ?? 0) ?: null;
            $cc = trim($_POST['s_commun'][$i] ?? '') ?: null;
            $com = trim($_POST['s_com'][$i] ?? '') ?: null;
            $del = !empty($_POST['s_del'][$i]);
            if ($sid && ($del || ($h <= 0 && !$ens))) { db_exec("DELETE FROM edt_service WHERE id=? AND id_annee=?", [$sid, $a]); $n++; continue; }
            if (!$mat || ($h <= 0 && !$ens)) continue;
            if ($sid) db_exec("UPDATE edt_service SET id_mat=?, matricule_ens=?, heures=?, duree_min=?, duree_max=?, id_salle=?, code_commun=?, commentaire=? WHERE id=? AND id_annee=?",
                              [$mat, $ens, $h, $dmin, $dmax, $salle, $cc, $com, $sid, $a]);
            // Code commun ajouté / retiré après coup : les séances déjà placées le suivent (25/10/2026)
            if ($sid) db_exec("UPDATE edt_seance SET code_commun=? WHERE id_service=? AND id_annee=?", [$cc, $sid, $a]);
            else db_exec("INSERT INTO edt_service (id_annee,id_classe,id_mat,matricule_ens,heures,duree_min,duree_max,id_salle,code_commun,commentaire) VALUES (?,?,?,?,?,?,?,?,?,?)",
                         [$a, $id_classe, $mat, $ens, $h, $dmin, $dmax, $salle, $cc, $com]);
            $n++;
        }
        if (post('sync') === '1') edt_synchroniser_dispenser($a);
        flash_set('succes', "Services enregistrés ($n ligne(s)).");
        rediriger("secondaire/commun/pages/emploi_temps/services.php?onglet=classe&classe=$id_classe");
    }
    if ($act === 'reprendre' && $id_classe) {
        $annee = get_annee_active()['val_annee'];
        $n = 0;
        // primaire : titulaire de la classe (« Affectation des classes ») pour chaque cours de sa piste
        $reprise = edt_primaire()
            ? db_all("SELECT ec.matricule_ens, d.id_mat FROM " . edt_t('discipline') . " d JOIN enseignat_classe ec ON ec.IDClasses=d.IDClasses AND ec.val_annee=?
                      WHERE d.IDClasses=? AND d.id_mat < " . EDT_PRIM_ARABE . "
                      UNION
                      SELECT ea.matricule_ens, d.id_mat FROM " . edt_t('discipline') . " d JOIN enseignat_classe_arabe ea ON ea.IDClasses=d.IDClasses AND ea.val_annee=?
                      WHERE d.IDClasses=? AND d.id_mat >= " . EDT_PRIM_ARABE, [$annee, $id_classe, $annee, $id_classe])
            : db_all("SELECT matricule_ens, id_mat FROM dispenser WHERE IDClasses=? AND val_annee=?", [$id_classe, $annee]);
        foreach ($reprise as $d) {
            if (db_val("SELECT COUNT(*) FROM edt_service WHERE id_annee=? AND id_classe=? AND id_mat=?", [$a, $id_classe, $d['id_mat']])) continue;
            db_exec("INSERT INTO edt_service (id_annee,id_classe,id_mat,matricule_ens,heures) VALUES (?,?,?,?,0)", [$a, $id_classe, $d['id_mat'], $d['matricule_ens']]);
            $n++;
        }
        flash_set('succes', "$n enseignant(s) repris " . edt_lib_affectations() . " — complétez les heures.");
        rediriger("secondaire/commun/pages/emploi_temps/services.php?onglet=classe&classe=$id_classe");
    }
    if ($act === 'enseignants') {
        foreach ($_POST['e_mat'] ?? [] as $i => $m) {
            $hd = trim($_POST['e_hd'][$i] ?? '');
            db_exec("INSERT INTO edt_enseignant (id_annee,matricule_ens,heures_dues,discipline,grade) VALUES (?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE heures_dues=VALUES(heures_dues), discipline=VALUES(discipline), grade=VALUES(grade)",
                    [$a, (int) $m, $hd === '' ? null : (float) str_replace(',', '.', $hd), trim($_POST['e_disc'][$i] ?? '') ?: null, trim($_POST['e_grade'][$i] ?? '') ?: null]);
        }
        flash_set('succes', 'Heures dues enregistrées.');
        rediriger('secondaire/commun/pages/emploi_temps/services.php?onglet=enseignants' . (isset($_GET['tous']) ? '&tous=1' : ''));
    }
    if ($act === 'sync') { $n = edt_synchroniser_dispenser($a); flash_set('succes', "Affectations mises à jour (" . edt_lib_affectations() . ", $n)."); rediriger("secondaire/commun/pages/emploi_temps/services.php?onglet=$onglet&classe=$id_classe"); }
}

$ens = edt_enseignants();
$salles = edt_salles();
$titre_page = 'Emploi du temps — Préparation';
require_once __DIR__ . '/../../../../noyau/layout/header.php';
echo edt_css();
?>
<?= edt_onglets('preparation', $onglet === 'enseignants' ? 'heures' : 'services') ?>
<?= flash_html() ?>

<?php if ($onglet === 'classe'):
    $prog = edt_programme_classe($id_classe);
    $svc = edt_services($a, $id_classe);
    $parMat = [];
    foreach ($svc as $s) $parMat[(int) $s['id_mat']][] = $s;
    $lignes = [];
    foreach ($prog as $p) {
        foreach ($parMat[(int) $p['id_mat']] ?? [null] as $s) $lignes[] = ['p' => $p, 's' => $s];
        unset($parMat[(int) $p['id_mat']]);
    }
    foreach ($parMat as $mid => $ss) foreach ($ss as $s) $lignes[] = ['p' => ['id_mat' => $mid, 'libelle' => $s['matiere'] . ' (hors programme)', 'coef' => '', 'groupe' => ''], 's' => $s];
    $total = array_sum(array_map(fn($s) => (float) $s['heures'], $svc));
    $places = array_sum(array_map(fn($s) => (int) $s['places'], $svc));
?>
<form method="get" class="card mb-3"><div class="card-body py-2 d-flex flex-wrap gap-2 align-items-center">
  <input type="hidden" name="onglet" value="classe"><label class="fw-bold">Classe</label>
  <select class="form-select form-select-sm w-auto" name="classe" onchange="this.form.submit()"><?php foreach ($classes as $c): ?><option value="<?= $c['id'] ?>" <?= (int) $c['id'] === $id_classe ? 'selected' : '' ?>><?= h($c['designation']) ?></option><?php endforeach; ?></select>
  <span class="ms-3 small">Volume prévu : <b><?= rtrim(rtrim(number_format($total, 1, ',', ''), '0'), ',') ?> h</b> · placé dans la grille : <b><?= $places ?> h</b></span>
  <?php if ($ecrire): ?>
  <button form="fReprendre" class="btn btn-sm btn-outline-secondary ms-auto" title="Créer les services à partir des enseignants déjà affectés (<?= h(edt_lib_affectations()) ?>)"><i class="bi bi-arrow-down-circle"></i> Reprendre les affectations</button>
  <?php endif; ?>
</div></form>
<?php if ($ecrire): ?><form id="fReprendre" method="post"><?= csrf_champ() ?><input type="hidden" name="action" value="reprendre"></form><?php endif; ?>
<form method="post" class="card">
  <?= csrf_champ() ?><input type="hidden" name="action" value="services">
  <div class="table-responsive"><table class="table table-sm align-middle mb-0" id="tabSvc">
    <thead class="table-light"><tr><th>Matière</th><th style="width:70px">Coef</th><th>Enseignant</th><th style="width:80px">H / sem.</th><th style="width:75px" title="Durée minimale d'une séance (heures)">Durée min</th><th style="width:75px" title="Durée maximale d'une séance (heures consécutives)">Durée max</th><th>Salle imposée</th><th style="width:110px" title="Même code sur plusieurs classes = une seule séance commune">Code commun</th><th>Placé</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($lignes as $i => $l): $s = $l['s']; $p = $l['p']; $placeH = $s ? (int) $s['places'] : 0; $prevu = $s ? (float) $s['heures'] : 0; ?>
      <tr>
        <td><input type="hidden" name="s_id[]" value="<?= (int) ($s['id'] ?? 0) ?>"><input type="hidden" name="s_mat[]" value="<?= (int) $p['id_mat'] ?>">
          <b><?= h($p['libelle']) ?></b><div class="small text-muted"><?= h(mb_strtolower((string) $p['groupe'])) ?></div></td>
        <td><?= h((string) $p['coef']) ?></td>
        <td><select class="form-select form-select-sm" name="s_ens[]"><option value="">— à attribuer —</option><?php foreach ($ens as $e): ?><option value="<?= $e['matricule_ens'] ?>" <?= (int) ($s['matricule_ens'] ?? 0) === (int) $e['matricule_ens'] ? 'selected' : '' ?>><?= h($e['nom']) ?></option><?php endforeach; ?></select></td>
        <td><input class="form-control form-control-sm" name="s_h[]" value="<?= $s ? rtrim(rtrim((string) $s['heures'], '0'), '.') : '' ?>" inputmode="decimal"></td>
        <td><input type="number" min="1" max="9" class="form-control form-control-sm" name="s_dmin[]" value="<?= (int) ($s['duree_min'] ?? 1) ?>"></td>
        <td><input type="number" min="1" max="9" class="form-control form-control-sm" name="s_dmax[]" value="<?= (int) ($s['duree_max'] ?? 2) ?>"></td>
        <td><select class="form-select form-select-sm" name="s_salle[]"><option value="">—</option><?php foreach ($salles as $sa): ?><option value="<?= $sa['id'] ?>" <?= (int) ($s['id_salle'] ?? 0) === (int) $sa['id'] ? 'selected' : '' ?>><?= h($sa['nom']) ?></option><?php endforeach; ?></select></td>
        <td><input class="form-control form-control-sm" name="s_commun[]" value="<?= h($s['code_commun'] ?? '') ?>"></td>
        <td class="text-nowrap"><?php if ($s): ?><span class="badge <?= $placeH === (int) round($prevu) ? 'bg-success' : ($placeH > 0 ? 'bg-warning text-dark' : 'bg-light text-dark border') ?>"><?= $placeH ?> / <?= rtrim(rtrim((string) $prevu, '0'), '.') ?> h</span><?php endif; ?></td>
        <td class="text-nowrap"><input type="hidden" name="s_com[]" value="<?= h($s['commentaire'] ?? '') ?>">
          <?php if ($ecrire): ?>
          <button type="button" class="btn btn-sm btn-outline-secondary" title="Ajouter un second enseignant / groupe pour cette matière" onclick="dupliquer(this)"><i class="bi bi-plus"></i></button>
          <?php if ($s): ?><label class="btn btn-sm btn-outline-danger mb-0" title="Supprimer ce service"><input type="checkbox" class="d-none" name="s_del[<?= $i ?>]" value="1" onchange="this.closest('tr').classList.toggle('opacity-25',this.checked)"><i class="bi bi-trash"></i></label><?php endif; ?>
          <?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($ecrire): ?>
  <div class="card-footer d-flex flex-wrap gap-3 align-items-center">
    <button class="btn btn-primary btn-sm"><i class="bi bi-check2"></i> Enregistrer les services</button>
    <div class="form-check"><input class="form-check-input" type="checkbox" name="sync" value="1" id="sync" checked><label class="form-check-label small" for="sync">Mettre aussi à jour les enseignants affectés (<?= h(edt_lib_affectations()) ?> : notes, bulletins)</label></div>
    <span class="small text-muted ms-auto">Une ligne sans heures ni enseignant est ignorée. « Code commun » : même code sur plusieurs classes = une seule séance commune.</span>
  </div>
  <?php endif; ?>
</form>
<script>
function dupliquer(btn){const tr=btn.closest('tr');const c=tr.cloneNode(true);c.querySelector('[name="s_id[]"]').value='0';
c.querySelectorAll('input[name="s_h[]"],input[name="s_commun[]"]').forEach(i=>i.value='');c.querySelectorAll('select').forEach(s=>s.selectedIndex=0);
c.querySelectorAll('td:nth-child(9)').forEach(td=>td.innerHTML='');c.querySelectorAll('label.btn-outline-danger').forEach(l=>l.remove());tr.after(c);
// réindexe les cases « supprimer » (elles portent l'index de ligne)
document.querySelectorAll('#tabSvc tbody tr').forEach((r,i)=>{const d=r.querySelector('input[name^="s_del"]');if(d)d.name='s_del['+i+']';});}
</script>

<?php elseif ($onglet === 'enseignants'):
    $charges = edt_charges_enseignants($a);
    $dues = [];
    foreach (db_all("SELECT * FROM edt_enseignant WHERE id_annee=?", [$a]) as $d) $dues[(int) $d['matricule_ens']] = $d;
    $tous = isset($_GET['tous']);
    $liste = array_values(array_filter($ens, fn($e) => $tous || isset($charges[(int) $e['matricule_ens']]) || isset($dues[(int) $e['matricule_ens']])));
?>
<div class="d-flex mb-2 gap-2 align-items-center">
  <span class="small text-muted">Heures attribuées = total des services (un cours commun compte une seule fois). Heures placées = périodes réellement inscrites dans la grille.</span>
  <a class="btn btn-sm btn-outline-secondary ms-auto" href="?onglet=enseignants<?= $tous ? '' : '&tous=1' ?>"><?= $tous ? 'Seulement les enseignants ayant un service' : 'Afficher tous les enseignants' ?></a>
</div>
<form method="post" class="card"><?= csrf_champ() ?><input type="hidden" name="action" value="enseignants">
  <div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead class="table-light"><tr><th>Enseignant</th><th>Discipline</th><th style="width:110px">Grade</th><th style="width:90px">Heures dues</th><th>Attribuées</th><th>Placées</th><th>Écart / dues</th><th></th></tr></thead><tbody>
    <?php foreach ($liste as $e): $m = (int) $e['matricule_ens']; $d = $dues[$m] ?? []; $att = (float) ($charges[$m]['attribuees'] ?? 0); $pl = (int) ($charges[$m]['placees'] ?? 0);
        $hd = isset($d['heures_dues']) && $d['heures_dues'] !== null ? (float) $d['heures_dues'] : null; $ec = $hd !== null ? $att - $hd : null; ?>
      <tr><td><input type="hidden" name="e_mat[]" value="<?= $m ?>"><b><?= h($e['nom']) ?></b><div class="small text-muted"><?= h($e['tel_ens']) ?></div></td>
        <td><input class="form-control form-control-sm" name="e_disc[]" value="<?= h($d['discipline'] ?? $e['matiere_enseignee'] ?? '') ?>"></td>
        <td><input class="form-control form-control-sm" name="e_grade[]" value="<?= h($d['grade'] ?? '') ?>" placeholder="<?= h($e['id_grade']) ?>"></td>
        <td><input class="form-control form-control-sm" name="e_hd[]" value="<?= $hd !== null ? rtrim(rtrim((string) $hd, '0'), '.') : '' ?>"></td>
        <td><b><?= rtrim(rtrim((string) $att, '0'), '.') ?: 0 ?></b> h</td>
        <td><span class="badge <?= $pl >= $att && $att > 0 ? 'bg-success' : ($pl ? 'bg-warning text-dark' : 'bg-light text-dark border') ?>"><?= $pl ?> h</span></td>
        <td><?php if ($ec !== null): ?><span class="badge <?= $ec > 0 ? 'bg-info text-dark' : ($ec < 0 ? 'bg-danger' : 'bg-success') ?>"><?= $ec > 0 ? '+' : '' ?><?= rtrim(rtrim((string) $ec, '0'), '.') ?: '0' ?> h</span><div class="small text-muted"><?= $ec > 0 ? 'heures supplémentaires' : ($ec < 0 ? 'sous-service' : 'conforme') ?></div><?php endif; ?></td>
        <td><a class="btn btn-sm btn-outline-primary" href="consulter.php?vue=enseignant&id=<?= $m ?>" title="Voir son emploi du temps"><i class="bi bi-calendar3-week"></i></a></td></tr>
    <?php endforeach; ?>
    <?php if (!$liste): ?><tr><td colspan="8" class="text-center text-muted py-3">Aucun service saisi pour l'instant.</td></tr><?php endif; ?>
    </tbody></table></div>
  <?php if ($ecrire && $liste): ?><div class="card-footer"><button class="btn btn-primary btn-sm"><i class="bi bi-check2"></i> Enregistrer</button></div><?php endif; ?>
</form>

<?php endif; ?>
<?php require_once __DIR__ . '/../../../../noyau/layout/footer.php'; ?>
