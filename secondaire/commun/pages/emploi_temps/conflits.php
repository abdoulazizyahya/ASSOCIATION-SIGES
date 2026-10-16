<?php
// secondaire/commun/pages/emploi_temps/conflits.php — contrôle complet de l'emploi du temps :
// conflits (enseignant / classe / salle à deux endroits), règles obligatoires
// et souhaitées non respectées, écarts entre services et séances placées,
// services sans enseignant, heures dues.
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
require_once __DIR__ . '/_edt_lib.php';
exiger_role(EDT_ROLES_GESTION);

$a = edt_annee_id();
$mod = new EdtModele($a);
$ctl = edt_controle($a, $mod);

// Écarts service / séances
$ecarts = [];
foreach (edt_services($a) as $s) {
    $prevu = (int) round((float) $s['heures']); $pl = (int) $s['places'];
    if ($prevu !== $pl) $ecarts[] = $s + ['prevu' => $prevu, 'pl' => $pl];
}
// Séances sans service correspondant
$horsService = db_all("SELECT q.id_classe, q.id_mat, q.matricule_ens, COUNT(*) n FROM edt_seance q
                       WHERE q.id_annee=? AND NOT EXISTS (SELECT 1 FROM edt_service s WHERE s.id_annee=q.id_annee AND s.id_classe=q.id_classe AND s.id_mat=q.id_mat AND s.matricule_ens<=>q.matricule_ens)
                       GROUP BY q.id_classe, q.id_mat, q.matricule_ens", [$a]);
$sansEns = db_all("SELECT s.*, c.designation classe, TRIM(m.libelle) matiere FROM edt_service s JOIN classe c ON c.id=s.id_classe JOIN matiere m ON m.id=s.id_mat
                   WHERE s.id_annee=? AND s.matricule_ens IS NULL AND s.heures>0 ORDER BY c.ordre", [$a]);
$seancesSansEns = (int) db_val("SELECT COUNT(*) FROM edt_seance WHERE id_annee=? AND matricule_ens IS NULL", [$a]);
// Matières du programme sans service
$progSansService = db_all("SELECT c.designation classe, c.id id_classe, TRIM(m.libelle) matiere FROM discipline d JOIN classe c ON c.id=d.IDClasses AND c.archivee=0 JOIN matiere m ON m.id=d.id_mat
                           WHERE NOT EXISTS (SELECT 1 FROM edt_service s WHERE s.id_annee=? AND s.id_classe=d.IDClasses AND s.id_mat=d.id_mat) ORDER BY c.ordre, m.libelle", [$a]);
// Heures dues
$charges = edt_charges_enseignants($a);
$dues = db_all("SELECT d.*, TRIM(CONCAT(e.nom_ens,' ',IFNULL(e.prenom_ens,''))) nom FROM edt_enseignant d JOIN enseignant e ON e.matricule_ens=d.matricule_ens WHERE d.id_annee=? AND d.heures_dues IS NOT NULL ORDER BY e.nom_ens", [$a]);
// Avancement par classe et étapes (ancien tableau de bord, fusionné ici le 16/10/2026)
$classes = edt_classes();
$prevuC = []; $placeC = []; $confC = [];
foreach (db_all("SELECT id_classe, SUM(heures) h FROM edt_service WHERE id_annee=? GROUP BY id_classe", [$a]) as $r) $prevuC[(int) $r['id_classe']] = (float) $r['h'];
foreach (db_all("SELECT id_classe, COUNT(*) n FROM edt_seance WHERE id_annee=? AND sous_groupe IN ('','A') GROUP BY id_classe", [$a]) as $r) $placeC[(int) $r['id_classe']] = (int) $r['n'];
foreach ($ctl['conflit'] as $p) $confC[(int) $p['bloc']['id_classe']] = ($confC[(int) $p['bloc']['id_classe']] ?? 0) + 1;
$totP = array_sum($prevuC); $totS = array_sum($placeC);
$etapes = [
    ['Grille horaire', (bool) db_val("SELECT COUNT(*) FROM edt_creneau WHERE id_annee=?", [$a]), 'parametres.php?onglet=grille'],
    ['Règles', (bool) db_val("SELECT COUNT(*) FROM edt_contrainte WHERE id_annee=?", [$a]), 'parametres.php?onglet=regles'],
    ['Services', $totP > 0, 'services.php'],
    ['Cours placés', $totS > 0, 'generer.php'],
    ['Aucun conflit', $totS > 0 && !$ctl['conflit'], '#conflits'],
    ['Publié', edt_publie($a), 'consulter.php'],
];

$titre_page = 'Emploi du temps — Construction';
require_once __DIR__ . '/../../../../noyau/layout/header.php';
echo edt_css();
$lien = fn(array $b) => 'saisie.php?classe=' . (int) $b['id_classe'];
$section = function (string $titre, string $couleur, string $ico, int $n, string $aide = '') {
    echo '<div class="card mb-3"><div class="card-header d-flex align-items-center gap-2" style="border-left:5px solid ' . $couleur . '"><i class="bi bi-' . $ico . '"></i><b>' . h($titre) . '</b><span class="badge ms-auto" style="background:' . $couleur . '">' . $n . '</span></div>'
        . ($aide ? '<div class="px-3 pt-2 small text-muted">' . $aide . '</div>' : '');
};
?>
<?= edt_onglets('construction', 'controles') ?>
<?= flash_html() ?>
<div class="row g-2 mb-3">
<?php foreach ([['Conflits', count($ctl['conflit']), '#dc2626'], ['Règles obligatoires', count($ctl['obligatoire']), '#ea580c'], ['Règles souhaitées', count($ctl['souhaitee']), '#ca8a04'],
                ['Écarts de service', count($ecarts), '#2563eb'], ['Services sans enseignant', count($sansEns), '#7c3aed'], ['Matières sans service', count($progSansService), '#64748b']] as [$l, $n, $c]): ?>
  <div class="col-6 col-md-4 col-xl-2"><div class="card h-100" style="border-left:4px solid <?= $c ?>"><div class="card-body py-2"><div class="small text-muted"><?= $l ?></div><div class="fs-4 fw-bold" style="color:<?= $n ? $c : '#16a34a' ?>"><?= $n ?></div></div></div></div>
<?php endforeach; ?>
</div>

<div class="d-flex flex-wrap gap-2 mb-3 small align-items-center">
  <b class="me-1">Étapes :</b>
  <?php foreach ($etapes as $k => [$l, $ok, $url]): ?>
    <a href="<?= $url ?>" class="badge rounded-pill text-decoration-none <?= $ok ? 'bg-success' : 'bg-light text-dark border' ?>" style="font-size:.8rem"><?= $ok ? '✓' : $k + 1 ?> <?= h($l) ?></a>
  <?php endforeach; ?>
  <span class="ms-auto text-muted">Heures placées : <b><?= $totS ?></b> / <?= rtrim(rtrim(number_format($totP, 1, ',', ''), '0'), ',') ?: 0 ?> prévues<?= $totP ? ' (' . round($totS * 100 / $totP) . ' %)' : '' ?></span>
</div>

<details class="card mb-3" <?= $totS && $totS < $totP ? 'open' : '' ?>><summary class="card-header" style="cursor:pointer"><b><i class="bi bi-bar-chart-steps me-1"></i>Avancement par classe</b></summary>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead class="table-light"><tr><th>Classe</th><th>Prévu</th><th>Placé</th><th style="width:30%">Avancement</th><th>Conflits</th><th></th></tr></thead><tbody>
  <?php foreach ($classes as $c): $id = (int) $c['id']; $p = $prevuC[$id] ?? 0; $s = $placeC[$id] ?? 0; $pct = $p ? min(100, round($s * 100 / $p)) : 0; ?>
    <tr><td><b><?= h($c['designation']) ?></b></td><td><?= $p ? rtrim(rtrim((string) $p, '0'), '.') . ' h' : '<span class="text-muted">—</span>' ?></td><td><?= $s ?> h</td>
      <td><div class="progress" style="height:8px"><div class="progress-bar <?= $pct >= 100 ? 'bg-success' : ($pct >= 50 ? 'bg-info' : 'bg-warning') ?>" style="width:<?= $pct ?>%"></div></div></td>
      <td><?= !empty($confC[$id]) ? '<span class="badge bg-danger">' . $confC[$id] . '</span>' : '<span class="text-success">0</span>' ?></td>
      <td class="text-nowrap"><a class="btn btn-sm btn-outline-secondary" href="saisie.php?classe=<?= $id ?>" title="Modifier"><i class="bi bi-pencil"></i></a> <a class="btn btn-sm btn-outline-secondary" href="consulter.php?vue=classe&id=<?= $id ?>" title="Consulter"><i class="bi bi-eye"></i></a></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
</details>
<span id="conflits"></span>

<?php
$section('Conflits : enseignant, classe ou salle à deux endroits en même temps', '#dc2626', 'x-octagon', count($ctl['conflit']), 'À corriger en priorité. Un cours commun (même code sur plusieurs classes) n\'est pas un conflit.');
echo '<div class="table-responsive"><table class="table table-sm mb-0"><tbody>';
foreach ($ctl['conflit'] as $p) echo '<tr><td><span class="badge bg-danger">' . h(ucfirst($p['type'])) . '</span></td><td>' . h($p['msg']) . '</td><td class="small text-muted">' . h(edt_bloc_libelle($mod, $p['bloc'])) . '</td><td><a class="btn btn-sm btn-outline-primary" href="' . $lien($p['bloc']) . '">Corriger</a></td></tr>';
if (!$ctl['conflit']) echo '<tr><td class="text-success p-3"><i class="bi bi-check-circle"></i> Aucun conflit : aucun enseignant n\'est programmé dans deux classes à la même heure.</td></tr>';
echo '</tbody></table></div></div>';

foreach (['obligatoire' => ['Règles obligatoires non respectées', '#ea580c', 'exclamation-triangle'], 'souhaitee' => ['Règles souhaitées non respectées', '#ca8a04', 'info-circle']] as $niv => [$t, $c, $i]) {
    $section($t, $c, $i, count($ctl[$niv]));
    echo '<div class="table-responsive"><table class="table table-sm mb-0"><tbody>';
    foreach (array_slice($ctl[$niv], 0, 300) as $p) echo '<tr><td>' . h($p['msg']) . '</td><td class="small text-muted">' . h(edt_bloc_libelle($mod, $p['bloc'])) . '</td><td><a class="btn btn-sm btn-outline-primary" href="' . $lien($p['bloc']) . '">Voir</a></td></tr>';
    if (!$ctl[$niv]) echo '<tr><td class="text-success p-3"><i class="bi bi-check-circle"></i> Rien à signaler.</td></tr>';
    if (count($ctl[$niv]) > 300) echo '<tr><td class="text-muted">… ' . (count($ctl[$niv]) - 300) . ' autres.</td></tr>';
    echo '</tbody></table></div></div>';
}

$section('Écarts entre services et séances placées', '#2563eb', 'arrow-left-right', count($ecarts), 'Heures prévues dans « Services » qui ne sont pas (ou trop) inscrites dans la grille.');
echo '<div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Classe</th><th>Matière</th><th>Enseignant</th><th>Prévu</th><th>Placé</th><th></th></tr></thead><tbody>';
foreach ($ecarts as $s) echo '<tr><td>' . h($s['classe']) . '</td><td>' . h($s['matiere']) . '</td><td>' . h($s['enseignant'] ?: '—') . '</td><td>' . $s['prevu'] . ' h</td><td><span class="badge ' . ($s['pl'] < $s['prevu'] ? 'bg-warning text-dark' : 'bg-danger') . '">' . $s['pl'] . ' h</span></td><td><a class="btn btn-sm btn-outline-primary" href="saisie.php?classe=' . (int) $s['id_classe'] . '">Placer</a></td></tr>';
if (!$ecarts) echo '<tr><td colspan="6" class="text-success p-3"><i class="bi bi-check-circle"></i> Toutes les heures prévues sont placées.</td></tr>';
echo '</tbody></table></div>';
if ($horsService) {
    echo '<div class="px-3 py-2 small border-top"><b>Séances sans service correspondant</b> (saisies à la main ou importées) : ';
    echo h(implode(' ; ', array_map(fn($r) => ($mod->classes[$r['id_classe']]['designation'] ?? '?') . ' – ' . ($mod->mat[$r['id_mat']] ?? '?') . ' – ' . ($r['matricule_ens'] ? edt_nom_court($mod->ens[$r['matricule_ens']] ?? '?') : 'sans enseignant') . ' (' . $r['n'] . ' h)', array_slice($horsService, 0, 60))));
    echo count($horsService) > 60 ? ' …' : '';
    echo '</div>';
}
echo '</div>';

$section('Services sans enseignant', '#7c3aed', 'person-x', count($sansEns), $seancesSansEns ? "$seancesSansEns période(s) placée(s) n'ont pas d'enseignant." : '');
echo '<div class="table-responsive"><table class="table table-sm mb-0"><tbody>';
foreach ($sansEns as $s) echo '<tr><td>' . h($s['classe']) . '</td><td>' . h($s['matiere']) . '</td><td>' . rtrim(rtrim((string) $s['heures'], '0'), '.') . ' h</td><td><a class="btn btn-sm btn-outline-primary" href="services.php?classe=' . (int) $s['id_classe'] . '">Attribuer</a></td></tr>';
if (!$sansEns) echo '<tr><td class="text-success p-3"><i class="bi bi-check-circle"></i> Tous les services ont un enseignant.</td></tr>';
echo '</tbody></table></div></div>';

$section('Matières du programme sans service', '#64748b', 'journal-x', count($progSansService), 'Matières prévues au programme SIGES de la classe (module Matières) pour lesquelles aucun service n\'est saisi.');
$parClasse = [];
foreach ($progSansService as $r) $parClasse[$r['classe']][] = $r['matiere'];
echo '<div class="table-responsive"><table class="table table-sm mb-0"><tbody>';
foreach ($parClasse as $cl => $ms) echo '<tr><td style="width:160px"><b>' . h($cl) . '</b></td><td class="small">' . h(implode(', ', $ms)) . '</td></tr>';
if (!$parClasse) echo '<tr><td class="text-success p-3"><i class="bi bi-check-circle"></i> Toutes les matières ont un service.</td></tr>';
echo '</tbody></table></div></div>';

$section('Heures dues des enseignants', '#0f766e', 'hourglass-split', count($dues), 'Heures attribuées (services) comparées aux heures dues renseignées dans Préparation → Heures dues.');
echo '<div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Enseignant</th><th>Dues</th><th>Attribuées</th><th>Placées</th><th>Situation</th></tr></thead><tbody>';
foreach ($dues as $d) {
    $m = (int) $d['matricule_ens']; $att = (float) ($charges[$m]['attribuees'] ?? 0); $pl = (int) ($charges[$m]['placees'] ?? 0); $hd = (float) $d['heures_dues']; $ec = $att - $hd;
    echo '<tr><td>' . h($d['nom']) . '</td><td>' . rtrim(rtrim((string) $hd, '0'), '.') . ' h</td><td>' . rtrim(rtrim((string) $att, '0'), '.') . ' h</td><td>' . $pl . ' h</td><td>'
        . ($ec > 0 ? '<span class="badge bg-info text-dark">+' . $ec . ' h supplémentaires</span>' : ($ec < 0 ? '<span class="badge bg-danger">sous-service ' . $ec . ' h</span>' : '<span class="badge bg-success">conforme</span>')) . '</td></tr>';
}
if (!$dues) echo '<tr><td colspan="5" class="text-muted p-3">Aucune heure due renseignée.</td></tr>';
echo '</tbody></table></div></div>';
?>
<?php require_once __DIR__ . '/../../../../noyau/layout/footer.php'; ?>
