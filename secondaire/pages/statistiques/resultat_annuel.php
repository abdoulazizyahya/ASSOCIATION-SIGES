<?php
/**
 * Module "Résultat annuel" — 3 onglets :
 *  - "classe"     : Résultat par classe (Admis/Redoublants/Exclus/Toute la
 *                   classe, tri alpha/mérite d'AFFICHAGE seulement — le
 *                   rang reste toujours calculé au mérite).
 *  - "meilleurs"  : palmarès de l'établissement entier, top N au choix,
 *                   réservé à l'administration.
 *  - "provisoire" : effectif prévisionnel d'une classe pour l'année
 *                   suivante (redoublants + admis d'ailleurs), reposant
 *                   exclusivement sur des décisions déjà enregistrées.
 * Calcul partagé : calc_resultat_annuel_comp() / calc_liste_provisoire_comp()
 * (fonctions.php) — jamais 3 implémentations qui pourraient diverger entre
 * l'écran, le PDF et l'Excel.
 *
 * Navigation : tout changement de classe/filtre/tri/onglet/N est un
 * rechargement AJAX du seul conteneur #fragResultat (voir le script en bas
 * de fichier) — la page elle-même (topbar/menu/sidebar) n'est jamais
 * retouchée. Les fonctions JS qui pilotent cela vivent HORS de
 * #fragResultat (persistantes, ne sont jamais réinjectées) et lisent l'état
 * courant depuis l'URL (history.replaceState tenu à jour à chaque
 * navigation) plutôt que depuis des valeurs PHP figées au premier rendu —
 * un <script> inséré via innerHTML ne s'exécute jamais, seules les
 * fonctions déjà déclarées avant le premier rendu restent utilisables.
 */
header('Cache-Control: no-store, no-cache, must-revalidate');
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_connexion();

$role     = role_connecte();
$is_admin = in_array($role, ['ADMIN', 'PROVISEUR', 'CENSEUR']);
$is_ens   = ($role === 'ENSEIGNANT');
$mat_ens  = $is_ens ? get_matricule_ens_connecte() : null;
if (!$is_admin && !$is_ens) { flash_set('erreur', 'Accès non autorisé.'); rediriger('dashboard.php'); }

$annee_act = get_annee_active();
$id_annee  = (int)($annee_act['id'] ?? 0);
$val_annee = $annee_act['libelle'] ?? '';
$annee_suivante_lib = libelle_annee_suivante($val_annee);

// Classes PP si enseignant — onglets "classe" et "provisoire" leur sont
// réservés UNIQUEMENT pour les classes dont ils sont professeur principal
// (pas "toute classe où ils enseignent une matière", volontairement plus
// strict que secondaire/pages/statistiques/documents.php).
$classes_pp = [];
if ($is_ens && $mat_ens) {
    $classes_pp = db_all(
        "SELECT c.* FROM enseignat_principal ep
         JOIN classe c ON c.id=ep.IDClasses
         WHERE ep.matricule_ens=? AND ep.val_annee=? AND c.archivee=0
         ORDER BY c.ordre, c.designation",
        [$mat_ens, $val_annee]
    );
    if (empty($classes_pp)) {
        $titre_page = 'Résultat annuel';
        require_once __DIR__ . '/../../../layout/header.php';
        echo '<div class="alert alert-warning mt-3 mx-3"><i class="bi bi-lock me-2"></i>Accès réservé aux professeurs principaux et à l\'administration.</div>';
        require_once __DIR__ . '/../../../layout/footer.php';
        exit;
    }
}
$classes = $is_admin
    ? db_all("SELECT c.* FROM classe c JOIN inscription i ON i.id_classe=c.id AND i.id_annee=? WHERE c.archivee=0 GROUP BY c.id ORDER BY c.ordre,c.designation", [$id_annee])
    : $classes_pp;

$onglet = in_array($_GET['onglet'] ?? '', ['classe', 'meilleurs', 'provisoire'], true) ? $_GET['onglet'] : 'classe';
if ($onglet === 'meilleurs' && !$is_admin) $onglet = 'classe'; // établissement entier = administration uniquement

$id_classe = (int)($_GET['classe'] ?? 0);
if ($is_ens && $id_classe && !in_array($id_classe, array_column($classes_pp, 'id'))) $id_classe = 0;

$tri = in_array($_GET['tri'] ?? '', ['alpha', 'merite'], true) ? $_GET['tri'] : 'merite';

$filtre_map      = ['admis' => 'Admis', 'redoublement' => 'Redoublement', 'exclu' => 'Exclu'];
$filtre          = in_array($_GET['filtre'] ?? '', ['admis', 'redoublement', 'exclu', 'tous'], true) ? $_GET['filtre'] : 'tous';
$filtre_decision = $filtre_map[$filtre] ?? null;

$n_choix = [5, 10, 30, 50];
$n       = max(1, (int)($_GET['n'] ?? 10));

// ── Données de l'onglet actif seulement (jamais les 3 à la fois) ────────
$rows_classe          = [];
$rows_classe_filtrees = [];
$compte_decisions     = [];
$nb_non_enregistrees  = 0;
if ($onglet === 'classe' && $id_classe) {
    $rows_classe = calc_resultat_annuel_comp($id_annee, $id_classe);
    foreach ($rows_classe as $r) {
        $compte_decisions[$r['decision']] = ($compte_decisions[$r['decision']] ?? 0) + 1;
        if ($r['decision_source'] === 'auto' && $r['decision'] !== 'Non classé') $nb_non_enregistrees++;
    }
    $rows_classe_filtrees = $filtre_decision
        ? array_values(array_filter($rows_classe, fn($r) => $r['decision'] === $filtre_decision))
        : $rows_classe;
    $rows_classe_filtrees = trier_resultat_affichage($rows_classe_filtrees, $tri);
}

$rows_meilleurs = [];
$nb_eleves_etab = 0;
if ($onglet === 'meilleurs') {
    $etab_full      = calc_resultat_annuel_comp($id_annee, 0);
    $nb_eleves_etab = count(array_filter($etab_full, fn($r) => $r['moy_annuelle'] !== null));
    $rows_meilleurs = trier_resultat_affichage(array_slice($etab_full, 0, $n), $tri);
}

$id_classe_cible = (int)($_GET['classe'] ?? 0);
if ($is_ens && $id_classe_cible && !in_array($id_classe_cible, array_column($classes_pp, 'id'))) $id_classe_cible = 0;
$rows_provisoire  = [];
$classe_cible     = null;
if ($onglet === 'provisoire' && $id_classe_cible) {
    foreach ($classes as $c) { if ((int)$c['id'] === $id_classe_cible) { $classe_cible = $c; break; } }
    $rows_provisoire = calc_liste_provisoire_comp($id_annee, $id_classe_cible);
    if ($tri === 'merite') {
        usort($rows_provisoire, function ($a, $b) {
            if ($a['moy_annuelle_origine'] === null && $b['moy_annuelle_origine'] === null) return 0;
            if ($a['moy_annuelle_origine'] === null) return 1;
            if ($b['moy_annuelle_origine'] === null) return -1;
            return $b['moy_annuelle_origine'] <=> $a['moy_annuelle_origine'];
        });
    }
}
$nb_red = count(array_filter($rows_provisoire, fn($r) => $r['statut'] === 'RED'));
$nb_nv  = count(array_filter($rows_provisoire, fn($r) => $r['statut'] === 'NV'));

$titre_page = 'Résultat annuel';
require_once __DIR__ . '/../../../layout/header.php';
?>
<style>
.doc-card{border:1px solid #c7d8f0;border-radius:10px;background:#fff;}
.doc-card .card-body{padding:1.25rem;}
.doc-classe-select{max-width:420px}
.nav-ong .nav-link{color:#1a3c6b;border-radius:6px 6px 0 0;font-size:.83rem;text-decoration:none;}
.nav-ong .nav-link.active{background:#1a3c6b;color:#fff;font-weight:600;}
.ordre-btn{padding:6px 16px;font-size:.82rem;border-radius:6px;cursor:pointer;border:1.5px solid #1a3c6b;transition:all .15s;text-decoration:none;display:inline-block;}
.ordre-btn.active{background:#1a3c6b;color:#fff;}
.ordre-btn:not(.active){background:#fff;color:#1a3c6b;}
.tbl-resultat{font-size:.8rem;}
.tbl-resultat th{background:#1a3c6b;color:#fff;font-size:.75rem;padding:7px 8px;white-space:nowrap;}
.tbl-resultat td{padding:6px 8px;vertical-align:middle;white-space:nowrap;}
.tbl-resultat tr:nth-child(even) td{background:#f4f8ff;}
.tbl-wrap{overflow-x:auto;border:1px solid #dbe4f3;border-radius:8px;}
.badge-compte{font-size:.78rem;padding:6px 12px;border-radius:20px;font-weight:600;}
.sig-warn{cursor:pointer;text-decoration:none;}
</style>

<div class="page-titre d-flex align-items-center justify-content-between flex-wrap gap-2">
  <h4><i class="bi bi-mortarboard me-2" style="color:#1a3c6b"></i>Résultat annuel</h4>
  <span class="badge" style="background:#dbeafe;color:#1e3a8a;font-size:.78rem;padding:5px 12px;border-radius:20px">
    <?= h($val_annee) ?>
  </span>
</div>

<?= flash_html() ?>

<ul class="nav nav-tabs nav-ong mb-0 border-bottom-0">
  <li class="nav-item">
    <a class="nav-link ax-link <?= $onglet==='classe'?'active':'' ?>" href="?onglet=classe">Résultat par classe</a>
  </li>
  <?php if ($is_admin): ?>
  <li class="nav-item">
    <a class="nav-link ax-link <?= $onglet==='meilleurs'?'active':'' ?>" href="?onglet=meilleurs">Meilleurs élèves</a>
  </li>
  <?php endif; ?>
  <li class="nav-item">
    <a class="nav-link ax-link <?= $onglet==='provisoire'?'active':'' ?>" href="?onglet=provisoire">Liste provisoire</a>
  </li>
</ul>

<div id="fragResultat">
<?php if ($onglet === 'classe'): ?>

  <div class="doc-card" style="border-top-left-radius:0">
    <div class="card-body">
      <label class="form-label fw-semibold" style="color:#1a3c6b">1. Choisir une classe</label>
      <form method="get" class="doc-classe-select ax-form">
        <input type="hidden" name="onglet" value="classe">
        <select name="classe" class="form-select ax-select" onchange="this.form.submit()">
          <option value="">— Sélectionner une classe —</option>
          <?php foreach ($classes as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $id_classe == $c['id'] ? 'selected' : '' ?>><?= h($c['designation']) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>
  </div>

  <?php if ($id_classe): ?>
  <div class="doc-card mt-3">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
        <div class="d-flex gap-1 flex-wrap">
          <a href="?onglet=classe&classe=<?= $id_classe ?>&filtre=tous&tri=<?= $tri ?>" class="ax-link ordre-btn <?= $filtre==='tous'?'active':'' ?>">Toute la classe (<?= count($rows_classe) ?>)</a>
          <a href="?onglet=classe&classe=<?= $id_classe ?>&filtre=admis&tri=<?= $tri ?>" class="ax-link ordre-btn <?= $filtre==='admis'?'active':'' ?>">Admis (<?= $compte_decisions['Admis'] ?? 0 ?>)</a>
          <a href="?onglet=classe&classe=<?= $id_classe ?>&filtre=redoublement&tri=<?= $tri ?>" class="ax-link ordre-btn <?= $filtre==='redoublement'?'active':'' ?>">Redoublants (<?= $compte_decisions['Redoublement'] ?? 0 ?>)</a>
          <a href="?onglet=classe&classe=<?= $id_classe ?>&filtre=exclu&tri=<?= $tri ?>" class="ax-link ordre-btn <?= $filtre==='exclu'?'active':'' ?>">Exclus (<?= $compte_decisions['Exclu'] ?? 0 ?>)</a>
        </div>
        <div class="d-flex gap-1">
          <a href="?onglet=classe&classe=<?= $id_classe ?>&filtre=<?= $filtre ?>&tri=alpha" class="ax-link ordre-btn <?= $tri==='alpha'?'active':'' ?>"><i class="bi bi-sort-alpha-down me-1"></i>Alphabétique</a>
          <a href="?onglet=classe&classe=<?= $id_classe ?>&filtre=<?= $filtre ?>&tri=merite" class="ax-link ordre-btn <?= $tri==='merite'?'active':'' ?>"><i class="bi bi-trophy me-1"></i>Mérite</a>
        </div>
      </div>
      <div class="d-flex gap-2 flex-wrap mb-2">
        <span class="badge-compte" style="background:#dcfce7;color:#166534">Admis : <?= $compte_decisions['Admis'] ?? 0 ?></span>
        <span class="badge-compte" style="background:#fef3c7;color:#92400e">Redoublants : <?= $compte_decisions['Redoublement'] ?? 0 ?></span>
        <span class="badge-compte" style="background:#fee2e2;color:#991b1b">Exclus : <?= $compte_decisions['Exclu'] ?? 0 ?></span>
        <?php if (!empty($compte_decisions['Abandon'])): ?>
        <span class="badge-compte" style="background:#f3e8ff;color:#6b21a8">Abandon : <?= $compte_decisions['Abandon'] ?></span>
        <?php endif; ?>
        <?php if (!empty($compte_decisions['Non classé'])): ?>
        <span class="badge-compte" style="background:#e5e7eb;color:#374151">Non classé : <?= $compte_decisions['Non classé'] ?></span>
        <?php endif; ?>
      </div>
      <?php if ($nb_non_enregistrees > 0): ?>
      <div class="alert alert-warning py-2 px-3 mb-3" style="font-size:.82rem">
        <i class="bi bi-exclamation-triangle me-1"></i>
        <strong><?= $nb_non_enregistrees ?></strong> décision(s) non enregistrée(s) au Conseil de Classe (valeur par défaut affichée) —
        <a href="<?= APP_URL ?>/secondaire/pages/conseil_classe/index.php?type=annee&classe=<?= $id_classe ?>" target="_blank">régulariser au Conseil de Classe</a>.
      </div>
      <?php endif; ?>

      <?php if (empty($rows_classe_filtrees)): ?>
        <p class="text-muted mb-0" style="font-size:.85rem">Aucun élève dans cette liste.</p>
      <?php else: ?>
      <div class="d-flex justify-content-end mb-2">
        <button class="btn btn-abz-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalExportClasse">
          <i class="bi bi-printer me-1"></i>Imprimer / Exporter
        </button>
      </div>
      <div class="tbl-wrap">
        <table class="table table-sm tbl-resultat mb-0">
          <thead><tr>
            <?php foreach (colonnes_resultat_classe(false) as $lbl): ?><th><?= h($lbl) ?></th><?php endforeach; ?>
          </tr></thead>
          <tbody>
            <?php $no = 1; foreach ($rows_classe_filtrees as $r): ?>
            <tr>
              <?php foreach (array_keys(colonnes_resultat_classe(false)) as $k): ?>
                <?php if ($k === 'decision'): ?>
                  <td>
                    <?php if ($r['decision_source'] === 'enregistree'): ?>
                      <span title="Décision enregistrée au Conseil de Classe">✅</span>
                    <?php else: ?>
                      <a class="sig-warn" href="<?= APP_URL ?>/secondaire/pages/conseil_classe/index.php?type=annee&classe=<?= $r['id_classe'] ?>" target="_blank" title="Non enregistrée — cliquer pour régulariser au Conseil de Classe">⚠️</a>
                    <?php endif; ?>
                    <?= h(valeur_colonne_resultat('decision', $r, $no)) ?>
                  </td>
                <?php else: ?>
                  <td><?= h(valeur_colonne_resultat($k, $r, $no)) ?></td>
                <?php endif; ?>
              <?php endforeach; ?>
            </tr>
            <?php $no++; endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

<?php elseif ($onglet === 'meilleurs'): ?>

  <div class="doc-card" style="border-top-left-radius:0">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
          <label class="form-label fw-semibold mb-2 d-block" style="color:#1a3c6b">Nombre d'élèves à afficher</label>
          <div class="d-flex gap-1 flex-wrap align-items-center">
            <?php foreach ($n_choix as $nc): ?>
              <a href="?onglet=meilleurs&n=<?= $nc ?>&tri=<?= $tri ?>" class="ax-link ordre-btn <?= $n===$nc?'active':'' ?>"><?= $nc ?></a>
            <?php endforeach; ?>
            <form method="get" class="d-flex gap-1 align-items-center ms-2 ax-form">
              <input type="hidden" name="onglet" value="meilleurs">
              <input type="hidden" name="tri" value="<?= $tri ?>">
              <input type="number" name="n" min="1" max="<?= max(1,$nb_eleves_etab) ?>" value="<?= h((string)$n) ?>" class="form-control form-control-sm" style="width:90px">
              <button class="btn btn-abz-outline btn-sm" type="submit">Autre</button>
            </form>
          </div>
        </div>
        <div class="d-flex gap-1">
          <a href="?onglet=meilleurs&n=<?= $n ?>&tri=alpha" class="ax-link ordre-btn <?= $tri==='alpha'?'active':'' ?>"><i class="bi bi-sort-alpha-down me-1"></i>Alphabétique</a>
          <a href="?onglet=meilleurs&n=<?= $n ?>&tri=merite" class="ax-link ordre-btn <?= $tri==='merite'?'active':'' ?>"><i class="bi bi-trophy me-1"></i>Mérite</a>
        </div>
      </div>
      <p class="text-muted mt-2 mb-0" style="font-size:.75rem">
        Le Top <?= $n ?> est toujours sélectionné au mérite (<?= $nb_eleves_etab ?> élève(s) classable(s) au total établissement) ;
        Alphabétique/Mérite ne change que l'ordre d'affichage de ce sous-ensemble déjà retenu, jamais sa composition ni le rang.
      </p>
    </div>
  </div>

  <div class="doc-card mt-3">
    <div class="card-body">
      <?php if (empty($rows_meilleurs)): ?>
        <p class="text-muted mb-0" style="font-size:.85rem">Aucun élève classable pour l'instant.</p>
      <?php else: ?>
      <div class="d-flex justify-content-end mb-2">
        <button class="btn btn-abz-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalExportMeilleurs">
          <i class="bi bi-printer me-1"></i>Imprimer / Exporter
        </button>
      </div>
      <div class="tbl-wrap">
        <table class="table table-sm tbl-resultat mb-0">
          <thead><tr>
            <?php foreach (colonnes_resultat_classe(true) as $lbl): ?><th><?= h($lbl) ?></th><?php endforeach; ?>
          </tr></thead>
          <tbody>
            <?php $no = 1; foreach ($rows_meilleurs as $r): ?>
            <tr>
              <?php foreach (array_keys(colonnes_resultat_classe(true)) as $k): ?>
                <td><?= h(valeur_colonne_resultat($k, $r, $no)) ?></td>
              <?php endforeach; ?>
            </tr>
            <?php $no++; endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

<?php else: /* ── Onglet "provisoire" ── */ ?>

  <div class="doc-card" style="border-top-left-radius:0">
    <div class="card-body">
      <label class="form-label fw-semibold" style="color:#1a3c6b">1. Choisir la classe (telle qu'elle existera l'an prochain)</label>
      <form method="get" class="doc-classe-select ax-form">
        <input type="hidden" name="onglet" value="provisoire">
        <select name="classe" class="form-select ax-select" onchange="this.form.submit()">
          <option value="">— Sélectionner une classe —</option>
          <?php foreach ($classes as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $id_classe_cible == $c['id'] ? 'selected' : '' ?>><?= h($c['designation']) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>
  </div>

  <?php if ($id_classe_cible && $classe_cible): ?>
  <div class="doc-card mt-3">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
        <div>
          <strong><?= h($classe_cible['designation']) ?> — <?= h($annee_suivante_lib) ?></strong>
          &nbsp;·&nbsp; Redoublants (RED) : <?= $nb_red ?> &nbsp;·&nbsp; Nouveaux (NV) : <?= $nb_nv ?> &nbsp;·&nbsp; Total : <?= count($rows_provisoire) ?>
        </div>
        <div class="d-flex gap-1">
          <a href="?onglet=provisoire&classe=<?= $id_classe_cible ?>&tri=alpha" class="ax-link ordre-btn <?= $tri==='alpha'?'active':'' ?>"><i class="bi bi-sort-alpha-down me-1"></i>Alphabétique</a>
          <a href="?onglet=provisoire&classe=<?= $id_classe_cible ?>&tri=merite" class="ax-link ordre-btn <?= $tri==='merite'?'active':'' ?>"><i class="bi bi-trophy me-1"></i>Mérite</a>
        </div>
      </div>
      <div class="alert alert-info py-2 px-3 mb-3" style="font-size:.82rem">
        <i class="bi bi-info-circle me-1"></i>
        Cette liste ne reprend que les décisions déjà enregistrées au Conseil de Classe : un élève admis mais pas encore
        examiné (destination inconnue) n'y figure pas tant que sa décision n'est pas enregistrée.
      </div>

      <?php if (empty($rows_provisoire)): ?>
        <p class="text-muted mb-0" style="font-size:.85rem">Aucune décision enregistrée concernant cette classe pour l'instant.</p>
      <?php else: ?>
      <div class="d-flex justify-content-end mb-2">
        <button class="btn btn-abz-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalExportProvisoire">
          <i class="bi bi-printer me-1"></i>Imprimer / Exporter
        </button>
      </div>
      <div class="tbl-wrap">
        <table class="table table-sm tbl-resultat mb-0">
          <thead><tr>
            <?php foreach (colonnes_liste_provisoire() as $lbl): ?><th><?= h($lbl) ?></th><?php endforeach; ?>
          </tr></thead>
          <tbody>
            <?php $no = 1; foreach ($rows_provisoire as $r): ?>
            <tr>
              <?php foreach (array_keys(colonnes_liste_provisoire()) as $k): ?>
                <td><?= h(valeur_colonne_provisoire($k, $r, $no)) ?></td>
              <?php endforeach; ?>
            </tr>
            <?php $no++; endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

<?php endif; ?>

<!-- ═══ Modales d'export — une par onglet, sélection de colonnes propre à
     chacune (cocher/ordre/alignement), même principe que secondaire/pages/eleves/liste.php ═══ -->
<?php if ($onglet === 'classe' && $id_classe && !empty($rows_classe_filtrees)): ?>
<div class="modal fade" id="modalExportClasse" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title fw-bold"><i class="bi bi-file-earmark-pdf me-1 text-danger"></i>Impression / Export</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body py-2">
        <p class="text-muted mb-2" style="font-size:.75rem">Colonnes à inclure (cocher), ordre (flèches) et alignement :</p>
        <ul class="list-group" id="listeColonnesExportClasse">
          <?php foreach (colonnes_resultat_classe(false) as $k => $lbl):
                $align_def = in_array($k, ['no','sexe','t1','t2','t3','abs','moy_an','rang'], true) ? 'C' : 'L'; ?>
            <li class="list-group-item d-flex align-items-center gap-2 py-1 px-2" data-col="<?= $k ?>">
              <input class="form-check-input mt-0 flex-shrink-0" type="checkbox" id="colCl_<?= $k ?>" value="<?= $k ?>" checked>
              <label class="form-check-label flex-grow-1" for="colCl_<?= $k ?>" style="font-size:.78rem"><?= h($lbl) ?></label>
              <select class="form-select form-select-sm flex-shrink-0" style="width:auto;font-size:.72rem" data-align title="Alignement">
                <option value="L" <?= $align_def==='L'?'selected':'' ?>>⟸ Gauche</option>
                <option value="C" <?= $align_def==='C'?'selected':'' ?>>↔ Centre</option>
                <option value="R" <?= $align_def==='R'?'selected':'' ?>>Droite ⟹</option>
              </select>
              <button type="button" class="btn btn-sm btn-light py-0 px-1" onclick="deplacerColonneExport(this,-1)" title="Monter"><i class="bi bi-arrow-up"></i></button>
              <button type="button" class="btn btn-sm btn-light py-0 px-1" onclick="deplacerColonneExport(this,1)" title="Descendre"><i class="bi bi-arrow-down"></i></button>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div class="modal-footer py-2">
        <button class="btn btn-danger btn-sm" onclick="lancerPdfClasse()"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</button>
        <button class="btn btn-abz-gold btn-sm" onclick="lancerExcelClasse()"><i class="bi bi-file-earmark-excel me-1"></i>Excel</button>
        <button class="btn btn-light btn-sm" data-bs-dismiss="modal">Annuler</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($onglet === 'meilleurs' && !empty($rows_meilleurs)): ?>
<div class="modal fade" id="modalExportMeilleurs" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title fw-bold"><i class="bi bi-file-earmark-pdf me-1 text-danger"></i>Impression / Export</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body py-2">
        <p class="text-muted mb-2" style="font-size:.75rem">Colonnes à inclure (cocher), ordre (flèches) et alignement :</p>
        <ul class="list-group" id="listeColonnesExportMeilleurs">
          <?php foreach (colonnes_resultat_classe(true) as $k => $lbl):
                $align_def = in_array($k, ['no','sexe','t1','t2','t3','abs','moy_an','rang'], true) ? 'C' : 'L'; ?>
            <li class="list-group-item d-flex align-items-center gap-2 py-1 px-2" data-col="<?= $k ?>">
              <input class="form-check-input mt-0 flex-shrink-0" type="checkbox" id="colMe_<?= $k ?>" value="<?= $k ?>" checked>
              <label class="form-check-label flex-grow-1" for="colMe_<?= $k ?>" style="font-size:.78rem"><?= h($lbl) ?></label>
              <select class="form-select form-select-sm flex-shrink-0" style="width:auto;font-size:.72rem" data-align title="Alignement">
                <option value="L" <?= $align_def==='L'?'selected':'' ?>>⟸ Gauche</option>
                <option value="C" <?= $align_def==='C'?'selected':'' ?>>↔ Centre</option>
                <option value="R" <?= $align_def==='R'?'selected':'' ?>>Droite ⟹</option>
              </select>
              <button type="button" class="btn btn-sm btn-light py-0 px-1" onclick="deplacerColonneExport(this,-1)" title="Monter"><i class="bi bi-arrow-up"></i></button>
              <button type="button" class="btn btn-sm btn-light py-0 px-1" onclick="deplacerColonneExport(this,1)" title="Descendre"><i class="bi bi-arrow-down"></i></button>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div class="modal-footer py-2">
        <button class="btn btn-danger btn-sm" onclick="lancerPdfMeilleurs()"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</button>
        <button class="btn btn-abz-gold btn-sm" onclick="lancerExcelMeilleurs()"><i class="bi bi-file-earmark-excel me-1"></i>Excel</button>
        <button class="btn btn-light btn-sm" data-bs-dismiss="modal">Annuler</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($onglet === 'provisoire' && $id_classe_cible && !empty($rows_provisoire)): ?>
<div class="modal fade" id="modalExportProvisoire" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title fw-bold"><i class="bi bi-file-earmark-pdf me-1 text-danger"></i>Impression / Export</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body py-2">
        <p class="text-muted mb-2" style="font-size:.75rem">Colonnes à inclure (cocher), ordre (flèches) et alignement :</p>
        <ul class="list-group" id="listeColonnesExportProvisoire">
          <?php foreach (colonnes_liste_provisoire() as $k => $lbl):
                $align_def = in_array($k, ['no','sexe','statut'], true) ? 'C' : 'L'; ?>
            <li class="list-group-item d-flex align-items-center gap-2 py-1 px-2" data-col="<?= $k ?>">
              <input class="form-check-input mt-0 flex-shrink-0" type="checkbox" id="colPr_<?= $k ?>" value="<?= $k ?>" checked>
              <label class="form-check-label flex-grow-1" for="colPr_<?= $k ?>" style="font-size:.78rem"><?= h($lbl) ?></label>
              <select class="form-select form-select-sm flex-shrink-0" style="width:auto;font-size:.72rem" data-align title="Alignement">
                <option value="L" <?= $align_def==='L'?'selected':'' ?>>⟸ Gauche</option>
                <option value="C" <?= $align_def==='C'?'selected':'' ?>>↔ Centre</option>
                <option value="R" <?= $align_def==='R'?'selected':'' ?>>Droite ⟹</option>
              </select>
              <button type="button" class="btn btn-sm btn-light py-0 px-1" onclick="deplacerColonneExport(this,-1)" title="Monter"><i class="bi bi-arrow-up"></i></button>
              <button type="button" class="btn btn-sm btn-light py-0 px-1" onclick="deplacerColonneExport(this,1)" title="Descendre"><i class="bi bi-arrow-down"></i></button>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div class="modal-footer py-2">
        <button class="btn btn-danger btn-sm" onclick="lancerPdfProvisoire()"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</button>
        <button class="btn btn-abz-gold btn-sm" onclick="lancerExcelProvisoire()"><i class="bi bi-file-earmark-excel me-1"></i>Excel</button>
        <button class="btn btn-light btn-sm" data-bs-dismiss="modal">Annuler</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

</div><!-- /#fragResultat -->

<script>
// ═══ Tout ce qui suit vit HORS de #fragResultat et n'est jamais réinjecté
// via innerHTML : les fonctions restent donc utilisables après chaque
// rechargement AJAX du fragment, contrairement à un <script> qui serait
// À L'INTÉRIEUR du fragment (jamais exécuté par le navigateur une fois
// inséré via innerHTML). Elles lisent l'état courant (classe/filtre/tri/n)
// depuis l'URL du navigateur (tenue à jour par history.replaceState à
// chaque navigation AJAX), jamais depuis une valeur PHP figée au premier
// rendu de la page.
const APP_URL_RA  = '<?= APP_URL ?>';
const ID_ANNEE_RA = <?= $id_annee ?>;

function parametresActuels() {
    return new URLSearchParams(window.location.search);
}

async function resultatAjaxNavigate(url) {
    const container = document.getElementById('fragResultat');
    if (!container) { window.location.href = url; return; }
    const prevOpacity = container.style.opacity;
    container.style.opacity = '.5';
    try {
        const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const html  = await res.text();
        const doc   = new DOMParser().parseFromString(html, 'text/html');
        const fresh = doc.getElementById('fragResultat');
        if (!fresh) { window.location.href = url; return; }
        container.innerHTML = fresh.innerHTML;
        // Les onglets (hors du conteneur) doivent aussi refléter le nouvel
        // onglet actif — recopiés depuis la réponse fraîche.
        const freshTabs = doc.querySelector('.nav-ong');
        const liveTabs  = document.querySelector('.nav-ong');
        if (freshTabs && liveTabs) liveTabs.innerHTML = freshTabs.innerHTML;
        history.replaceState(null, '', url);
    } catch (e) {
        window.location.href = url;
    } finally {
        container.style.opacity = prevOpacity;
    }
}

document.addEventListener('click', function (e) {
    const a = e.target.closest('a.ax-link');
    if (!a) return;
    e.preventDefault();
    resultatAjaxNavigate(a.getAttribute('href'));
});
document.addEventListener('submit', function (e) {
    const form = e.target.closest('form.ax-form');
    if (!form) return;
    e.preventDefault();
    const params = new URLSearchParams(new FormData(form));
    resultatAjaxNavigate(window.location.pathname + '?' + params.toString());
});
document.addEventListener('change', function (e) {
    const sel = e.target.closest('select.ax-select');
    if (!sel || !sel.form) return;
    e.preventDefault();
    const params = new URLSearchParams(new FormData(sel.form));
    resultatAjaxNavigate(window.location.pathname + '?' + params.toString());
});

// Déplace la <li> du bouton cliqué d'un cran (ordre = ordre des colonnes du
// document généré) — même principe que secondaire/pages/eleves/liste.php.
function deplacerColonneExport(btn, sens) {
    const li = btn.closest('li');
    if (sens === -1 && li.previousElementSibling) {
        li.parentElement.insertBefore(li, li.previousElementSibling);
    } else if (sens === 1 && li.nextElementSibling) {
        li.parentElement.insertBefore(li.nextElementSibling, li);
    }
}
function colsAlignDe(ulId) {
    const lignes = [...document.querySelectorAll('#' + ulId + ' li')].filter(li => li.querySelector('input').checked);
    if (!lignes.length) { alert('Choisissez au moins une colonne.'); return null; }
    return {
        cols:  lignes.map(li => li.dataset.col).join(','),
        align: lignes.map(li => li.querySelector('[data-align]').value).join(','),
    };
}

function lancerPdfClasse() {
    const ca = colsAlignDe('listeColonnesExportClasse'); if (!ca) return;
    const p = parametresActuels();
    const url = APP_URL_RA + '/pdf/resultat_classe.php?annee=' + ID_ANNEE_RA
        + '&classe=' + (p.get('classe') || '0') + '&filtre=' + (p.get('filtre') || 'tous')
        + '&cols=' + ca.cols + '&align=' + ca.align;
    afficherApercuApresFermeture('modalExportClasse', url, 'Résultat par classe', 'resultat_classe', 'landscape');
}
function lancerExcelClasse() {
    const ca = colsAlignDe('listeColonnesExportClasse'); if (!ca) return;
    const p = parametresActuels();
    window.location.href = APP_URL_RA + '/secondaire/pages/statistiques/excel_resultat_classe.php?annee=' + ID_ANNEE_RA
        + '&classe=' + (p.get('classe') || '0') + '&filtre=' + (p.get('filtre') || 'tous')
        + '&cols=' + ca.cols + '&align=' + ca.align;
}
function lancerPdfMeilleurs() {
    const ca = colsAlignDe('listeColonnesExportMeilleurs'); if (!ca) return;
    const p = parametresActuels();
    const url = APP_URL_RA + '/pdf/resultat_meilleurs.php?annee=' + ID_ANNEE_RA
        + '&n=' + (p.get('n') || '10') + '&cols=' + ca.cols + '&align=' + ca.align;
    afficherApercuApresFermeture('modalExportMeilleurs', url, 'Meilleurs élèves', 'resultat_meilleurs', 'landscape');
}
function lancerExcelMeilleurs() {
    const ca = colsAlignDe('listeColonnesExportMeilleurs'); if (!ca) return;
    const p = parametresActuels();
    window.location.href = APP_URL_RA + '/secondaire/pages/statistiques/excel_resultat_meilleurs.php?annee=' + ID_ANNEE_RA
        + '&n=' + (p.get('n') || '10') + '&cols=' + ca.cols + '&align=' + ca.align;
}
function lancerPdfProvisoire() {
    const ca = colsAlignDe('listeColonnesExportProvisoire'); if (!ca) return;
    const p = parametresActuels();
    const url = APP_URL_RA + '/pdf/resultat_provisoire.php?annee=' + ID_ANNEE_RA
        + '&classe=' + (p.get('classe') || '0') + '&cols=' + ca.cols + '&align=' + ca.align;
    afficherApercuApresFermeture('modalExportProvisoire', url, 'Liste provisoire', 'resultat_provisoire', 'landscape');
}
function lancerExcelProvisoire() {
    const ca = colsAlignDe('listeColonnesExportProvisoire'); if (!ca) return;
    const p = parametresActuels();
    window.location.href = APP_URL_RA + '/secondaire/pages/statistiques/excel_resultat_provisoire.php?annee=' + ID_ANNEE_RA
        + '&classe=' + (p.get('classe') || '0') + '&cols=' + ca.cols + '&align=' + ca.align;
}
</script>

<?php require_once __DIR__ . '/../../../layout/footer.php'; ?>
