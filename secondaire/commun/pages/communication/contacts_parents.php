<?php
// secondaire/commun/pages/communication/contacts_parents.php — contacts
// téléphoniques des parents, par classe (10/11/2026), commun au primaire et
// au secondaire. Préparation des SMS / WhatsApp : montre, pour chaque élève,
// les numéros trouvés (fiche, père, mère, tuteur, numéros saisis dans une
// adresse — contacts_parents_eleve()) et ceux qui n'en ont aucun, pour
// compléter la saisie. Lien WhatsApp direct sur chaque numéro.
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';

$secondaire = type_enseignement_courant() === 'secondaire';
exiger_role($secondaire ? ['ADMIN', 'PROVISEUR', 'FONDATEUR', 'CENSEUR', 'SG', 'SECRETAIRE', 'INTENDANT'] : ['DIRECTEUR', 'SECRETAIRE', 'COMPTABLE', 'FONDATEUR']);

$annee = get_annee_active();
$id_classe = (int) ($_GET['classe'] ?? 0);
$filtre = in_array($_GET['filtre'] ?? '', ['tous', 'sans', 'avec'], true) ? $_GET['filtre'] : 'tous';

// Classes de l'année active + élèves inscrits (schémas différents selon le type).
if ($secondaire) {
    $id_annee = (int) ($annee['id'] ?? 0);
    $classes = db_all("SELECT c.id, c.designation AS libelle, COUNT(i.id_eleve) AS nb FROM classe c
                       JOIN inscription i ON i.id_classe=c.id AND i.id_annee=?
                       JOIN eleve e ON e.id=i.id_eleve AND e.statut='actif'
                       GROUP BY c.id, c.designation ORDER BY c.libelle_section, c.ordre, c.designation", [$id_annee]);
    $eleves_de = fn(int $cl) => db_all("SELECT e.id, e.nom, e.prenom, e.matricule, e.niu FROM eleve e
                       JOIN inscription i ON i.id_eleve=e.id AND i.id_classe=? AND i.id_annee=? WHERE e.statut='actif' ORDER BY e.nom, e.prenom", [$cl, $id_annee]);
    $fiche_url = fn(int $id) => APP_URL . '/secondaire/commun/pages/eleves/voir.php?id=' . $id;
} else {
    $val_annee = (string) ($annee['val_annee'] ?? '');
    $classes = db_all("SELECT c.IDClasses AS id, c.DesignationClasses AS libelle, COUNT(i.id_eleve) AS nb FROM classe c
                       JOIN inscrire i ON i.IDClasses=c.IDClasses AND i.val_annee=?
                       JOIN eleve e ON e.id_eleve=i.id_eleve AND e.statut='actif'
                       GROUP BY c.IDClasses, c.DesignationClasses ORDER BY c.DesignationClasses", [$val_annee]);
    $eleves_de = fn(int $cl) => array_map(fn($r) => ['id' => $r['id_eleve'], 'nom' => $r['Nom_elv'], 'prenom' => $r['Prenom_elv'], 'matricule' => $r['Mat_elv'], 'niu' => $r['niu']],
        db_all("SELECT e.id_eleve, e.Nom_elv, e.Prenom_elv, e.Mat_elv, e.niu FROM eleve e
                JOIN inscrire i ON i.id_eleve=e.id_eleve AND i.IDClasses=? AND i.val_annee=? WHERE e.statut='actif' ORDER BY e.Nom_elv, e.Prenom_elv", [$cl, $val_annee]));
    $fiche_url = fn(int $id) => APP_URL . '/primaire/pages/eleves/voir.php?id=' . $id;
}

// Synthèse par classe (nombre d'élèves avec au moins un numéro).
$synthese = [];
$total = ['eleves' => 0, 'avec' => 0];
foreach ($classes as $c) {
    $ids_c = array_column($eleves_de((int) $c['id']), 'id');
    $avec = count(array_filter(contacts_parents_lot($ids_c)));
    $synthese[(int) $c['id']] = $avec;
    $total['eleves'] += (int) $c['nb']; $total['avec'] += $avec;
}

$lignes = [];
if ($id_classe) {
    $el_classe = $eleves_de($id_classe);
    $ct_classe = contacts_parents_lot(array_column($el_classe, 'id'));
    foreach ($el_classe as $e) {
        $ct = $ct_classe[(int) $e['id']] ?? [];
        if ($filtre === 'sans' && $ct) continue;
        if ($filtre === 'avec' && !$ct) continue;
        $lignes[] = $e + ['contacts' => $ct];
    }
}
$classe_lib = '';
foreach ($classes as $c) if ((int) $c['id'] === $id_classe) $classe_lib = $c['libelle'];

$titre_page = 'Contacts des parents';
require_once __DIR__ . '/../../../../noyau/layout/header.php';
$pct = fn(int $a, int $n) => $n > 0 ? round(100 * $a / $n) : 0;
?>
<div class="page-titre">
  <h4><i class="bi bi-telephone me-1 text-primary"></i>Contacts des parents</h4>
  <div class="sub">Numéros trouvés sur les fiches (élève, père, mère, tuteur, numéros saisis dans une adresse) — base des SMS / WhatsApp.
    <?= (int) $total['avec'] ?> élève(s) joignable(s) sur <?= (int) $total['eleves'] ?> (<?= $pct($total['avec'], $total['eleves']) ?> %).</div>
</div>

<?= flash_html() ?>
<?php $onglet_comm = 'contacts'; require_once __DIR__ . '/_passerelle_lib.php'; require __DIR__ . '/_onglets.php'; ?>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card">
      <div class="card-body p-2">
        <table class="table table-sm table-hover mb-0" style="font-size:.82rem">
          <thead><tr><th>Classe</th><th class="text-center">Élèves</th><th class="text-center">Joignables</th></tr></thead>
          <tbody>
          <?php foreach ($classes as $c): $a = $synthese[(int) $c['id']] ?? 0; $p = $pct($a, (int) $c['nb']); ?>
            <tr class="<?= (int) $c['id'] === $id_classe ? 'table-primary' : '' ?>">
              <td><a href="?classe=<?= (int) $c['id'] ?>&filtre=<?= h($filtre) ?>" data-ajax-nav><?= h($c['libelle']) ?></a></td>
              <td class="text-center"><?= (int) $c['nb'] ?></td>
              <td class="text-center"><span class="badge" style="background:<?= $p >= 80 ? '#d1fae5' : ($p >= 40 ? '#fef3c7' : '#fee2e2') ?>;color:#111"><?= $a ?> · <?= $p ?> %</span></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$classes): ?><tr><td colspan="3" class="text-muted text-center">Aucune classe avec des élèves cette année.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <?php if (!$id_classe): ?>
      <div class="alert alert-info" style="font-size:.86rem"><i class="bi bi-arrow-left me-1"></i>Choisissez une classe pour voir les contacts de ses élèves.</div>
    <?php else: ?>
    <div class="card">
      <div class="card-body">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
          <div class="fw-semibold"><?= h($classe_lib) ?></div>
          <div class="btn-group btn-group-sm">
            <?php foreach (['tous' => 'Tous', 'sans' => 'Sans numéro', 'avec' => 'Avec numéro'] as $k => $lib): ?>
              <a href="?classe=<?= $id_classe ?>&filtre=<?= $k ?>" data-ajax-nav class="btn <?= $filtre === $k ? 'btn-primary' : 'btn-outline-primary' ?>"><?= $lib ?></a>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="table-responsive">
          <table class="table table-sm table-hover align-middle mb-0" style="font-size:.82rem">
            <thead><tr><th>#</th><th>Élève</th><th>Numéro(s) du parent</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($lignes as $n => $e): ?>
              <tr>
                <td class="text-muted"><?= $n + 1 ?></td>
                <td><div class="fw-semibold"><?= h(mb_strtoupper((string) $e['nom']) . ' ' . ($e['prenom'] ?? '')) ?></div>
                    <small class="text-muted"><?= h(niu_eleve($e) !== '' ? 'NIU ' . niu_eleve($e) : (string) ($e['matricule'] ?? '')) ?></small></td>
                <td>
                  <?php if (!$e['contacts']): ?>
                    <span class="badge" style="background:#fee2e2;color:#991b1b">Aucun numéro</span>
                  <?php else: foreach ($e['contacts'] as $ct): ?>
                    <div class="d-flex align-items-center gap-2">
                      <a href="tel:+237<?= h($ct['tel']) ?>" class="fw-semibold text-decoration-none"><?= h(tel_affiche($ct['tel'])) ?></a>
                      <span class="text-muted" style="font-size:.74rem"><?= h($ct['qui'] . ($ct['nom'] !== '' ? ' — ' . $ct['nom'] : '')) ?></span>
                      <a href="https://wa.me/237<?= h($ct['tel']) ?>" target="_blank" rel="noopener" title="Ouvrir WhatsApp" style="color:#16a34a"><i class="bi bi-whatsapp"></i></a>
                    </div>
                  <?php endforeach; endif; ?>
                </td>
                <td class="text-end"><a href="<?= h($fiche_url((int) $e['id'])) ?>" class="btn btn-sm btn-light" title="Fiche de l'élève (saisir les numéros)"><i class="bi bi-pencil"></i></a></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$lignes): ?><tr><td colspan="4" class="text-center text-muted py-3">Aucun élève pour ce filtre.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../../../../noyau/layout/footer.php'; ?>
