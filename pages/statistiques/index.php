<?php
// ── Statistiques scolaires — piste française (APC) ──────────────────
// Port de pages/statistiques/index.php d'ABZ_MBE à l'identique (mêmes
// onglets, même navigation AJAX partielle, mêmes cartes/tables/CSS, mêmes
// boutons PDF/Excel + case Signature numérique) — seules les DONNÉES
// viennent du schéma jaynitaare (compétences, pas matières ; moyennes
// calculées par notes_apc.php, déjà vérifiées contre les vraies données).
//
// Écarts assumés, imposés par le schéma réel de jaynitaare :
//  • ABZ_MBE a un onglet « Par section » ET un onglet « Par niveau » parce
//    que sa table `classe` porte `libelle_section` ET `code_niveau`.
//    jaynitaare n'a QUE `classe.Niveau` (M/I/II/III, table `niveau` avec
//    OrdreNiveau) — aucune notion de section. L'onglet « Par section » n'a
//    donc pas d'équivalent et n'est pas repris ; « Par niveau » le remplace
//    (même table de bilan M/F/T, même rendu).
//  • « Par matière » devient « Par compétence » (modèle APC réel).
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/../../notes_apc.php';
exiger_acces_pedagogie();
exiger_annee_active(); // Année scolaire réellement active requise (18/08/2026) — module Pédagogie/Discipline.

header('Cache-Control: no-store, no-cache, must-revalidate');

$role      = role_connecte();
$is_admin  = in_array($role, ['DIRECTEUR', 'SECRETAIRE'], true);
$annee_act = get_annee_active();
$val_annee = $annee_act['val_annee'] ?? '';
$seq_act   = get_sequence_active();

$onglet = $_GET['onglet'] ?? 'eleves';
// Onglet « Par compétence » (agrégé école entière) retiré, remplacé par
// « Non évalué » (demande du 18/08/2026) — voir stats_non_evalues_par_competence().
$allowed_onglets = ['eleves', 'competences', 'effectifs', 'niveau', 'non_evalue', 'enseignants'];
if (!in_array($onglet, $allowed_onglets, true)) $onglet = 'eleves';
if ($onglet === 'enseignants' && !$is_admin) $onglet = 'eleves';

// Période : Trimestre (celui de la séquence active, jamais choisissable —
// même principe qu'ABZ_MBE) ou Annuelle.
$vue     = in_array($_GET['vue'] ?? '', ['trim', 'annee'], true) ? $_GET['vue'] : 'trim';
$id_trim = (int) ($seq_act['id_trim'] ?? 0);
$trim_lib = $id_trim ? (string) db_val("SELECT libelle_trim FROM trimestre WHERE id_trim=?", [$id_trim]) : '';
$id_classe = (int) ($_GET['classe'] ?? 0);
$niveau_f  = $_GET['niveau'] ?? '';

// Détail de période — onglets « Par classe/Résultats » et « Par compétence /
// Évaluation » uniquement, en vue trimestrielle (demande du 18/08/2026,
// remplace le badge « Trimestre » figé + les 2 boutons « Évaluation en
// cours »/« Trimestre entier » par UNE SEULE liste déroulante listant les
// séquences du trimestre actif (ex. UA3, UA4 — sequences_trimestre_actif())
// suivies du trimestre lui-même en agrégat (ex. « 2eme Trimestre »).
// $periode_detail : 'trim' (trimestre entier, par défaut) ou l'id_seq choisi.
$seqs_trim_actif_stat = sequences_trimestre_actif();
$periode_detail = $_GET['periode_detail'] ?? 'trim';
if ($periode_detail !== 'trim' && !in_array((int) $periode_detail, array_column($seqs_trim_actif_stat, 'id_seq'), true)) {
    $periode_detail = 'trim';
}
$mode_eval   = $periode_detail === 'trim' ? 'trim' : 'seq';
$id_seq_stat = $mode_eval === 'seq' ? (int) $periode_detail : 0;

$classes = db_all(
    "SELECT c.IDClasses, c.DesignationClasses, c.Niveau, n.OrdreNiveau
     FROM classe c
     LEFT JOIN niveau n ON n.LibelleNiveau = c.Niveau
     JOIN inscrire i ON i.IDClasses = c.IDClasses AND i.val_annee = ?
     GROUP BY c.IDClasses, c.DesignationClasses, c.Niveau, n.OrdreNiveau
     ORDER BY n.OrdreNiveau, c.DesignationClasses",
    [$val_annee]
);
// $classes est déjà trié par OrdreNiveau -> array_unique() sur cette colonne
// préserve l'ordre des niveaux, pas besoin de retrier (demande du 18/08/2026,
// sélecteur Classe/Niveau unifié — voir render_select_classe_niveau() plus bas).
$niveaux_dispo = array_values(array_unique(array_column($classes, 'Niveau')));

$periode_ok = ($vue === 'annee') || ($id_trim > 0);
$fmt = fn(?float $v): string => $v === null ? '—' : rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');

// Table M/F/T « bilan classe » + ligne SOUS-TOTAL, réutilisée par l'onglet
// Par niveau. Demande du 18/08/2026 : Félicit./Encour./T.H/Avert.T/Blâme T
// retirés, remplacés par 7 tranches de moyenne plus fines (Classés conservé
// en tête). $cols_lbl : [clé bilan => libellé colonne] dans l'ordre voulu.
$bilan_cols_niveau = [
    'classes'   => 'Classés',
    'tr_0_7'    => 'Moy < 7',
    'tr_7_10'   => '7 ≤ Moy < 10',
    'tr_10_12'  => '10 ≤ Moy < 12',
    'tr_12_14'  => '12 ≤ Moy < 14',
    'tr_14_16'  => '14 ≤ Moy < 16',
    'tr_16_18'  => '16 ≤ Moy < 18',
    'tr_18_20'  => '18 ≤ Moy',
];
function render_tableau_bilan_genre(string $titre, array $lignes, array $cols_lbl): void {
    $bilan_cols = array_keys($cols_lbl);
    $zero = ['M' => 0, 'F' => 0, 'T' => 0];
    $sous_total = array_fill_keys($bilan_cols, $zero);
    foreach ($lignes as $l) {
        foreach ($bilan_cols as $k) {
            foreach (['M', 'F', 'T'] as $g) $sous_total[$k][$g] += $l['bilan'][$k][$g];
        }
    }
    ?>
    <h6 class="fw-bold mt-3" style="color:#1a3c6b"><i class="bi bi-collection me-1"></i><?= h($titre) ?></h6>
    <div class="table-responsive mb-3">
    <table class="table tbl-stat tbl-genre-bordered table-hover mb-0" style="font-size:.72rem">
      <thead>
        <tr>
          <th rowspan="2" style="vertical-align:middle">Classe</th>
          <?php foreach ($cols_lbl as $lbl): ?><th colspan="3"><?= h($lbl) ?></th><?php endforeach; ?>
        </tr>
        <tr>
          <?php for ($i = 0; $i < count($bilan_cols); $i++): ?><th>M</th><th>F</th><th>T</th><?php endfor; ?>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($lignes as $l): $b = $l['bilan']; ?>
        <tr>
          <td class="fw-semibold"><?= h($l['classe']) ?></td>
          <?php foreach ($bilan_cols as $k): ?>
            <td><?= $b[$k]['M'] ?></td><td><?= $b[$k]['F'] ?></td><td class="fw-semibold"><?= $b[$k]['T'] ?></td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
        <tr style="background:#e8f0fe;font-weight:700">
          <td>SOUS-TOTAL</td>
          <?php foreach ($bilan_cols as $k): ?>
            <td><?= $sous_total[$k]['M'] ?></td><td><?= $sous_total[$k]['F'] ?></td><td><?= $sous_total[$k]['T'] ?></td>
          <?php endforeach; ?>
        </tr>
      </tbody>
    </table>
    </div>
    <?php
}

// ── Sélecteur unifié Classe/Niveau (remplace les 2 selects séparés Classe +
//    Niveau, demande du 18/08/2026, restructuré en 2 groupes « Par Classe »/
//    « Par niveau » suite à un ajustement le même jour) ────────────────────
// Même principe de regroupement par <optgroup> que le select Compétence de
// la Saisie de notes (pages/notes/index.php?onglet=classe) : un groupe
// « Par Classe » (Toutes les classes + chaque classe) et un groupe
// « Par niveau » (Tous les niveaux + chaque niveau) — les 2 options
// catch-all (« Toutes les classes »/« Tous les niveaux ») mènent au même état
// non filtré (value=""), simplement accessibles depuis les 2 entrées
// naturelles selon qu'on raisonne par classe ou par niveau. Un seul <select>
// visible pilote 2 champs cachés (classe/niveau) via
// appliquerSelectClasseNiveau() (JS, voir le <script> en bas de page) — la
// navigation AJAX (data-ajax-nav-auto/data-ajax-nav-form) sérialise le
// formulaire par NOM de champ, et ce contrôle représente 2 paramètres
// différents (classe OU niveau, jamais les deux) à la fois. Appliqué à tous
// les onglets qui filtrent par classe (Par classe/Résultats, Par compétence/
// Évaluation, Par compétence) — jamais Effectifs/Niveau/Enseignants, qui
// n'ont pas ce filtre.
function render_select_classe_niveau(array $classes, array $niveaux, int $id_classe_sel, string $niveau_sel): void {
    $rien_filtre = !$id_classe_sel && $niveau_sel === '';
    ?>
    <select class="form-select form-select-sm" onchange="appliquerSelectClasseNiveau(this)">
      <optgroup label="Par Classe">
        <option value="" <?= $rien_filtre ? 'selected' : '' ?>>Toutes les classes</option>
        <?php foreach ($classes as $c): ?>
          <option value="classe:<?= $c['IDClasses'] ?>" <?= $id_classe_sel == $c['IDClasses'] ? 'selected' : '' ?>><?= h($c['DesignationClasses']) ?></option>
        <?php endforeach; ?>
      </optgroup>
      <optgroup label="Par niveau">
        <option value="">Tous les niveaux</option>
        <?php foreach ($niveaux as $niv): ?>
          <option value="niveau:<?= h($niv) ?>" <?= (!$id_classe_sel && $niveau_sel === $niv) ? 'selected' : '' ?>><?= h($niv) ?></option>
        <?php endforeach; ?>
      </optgroup>
    </select>
    <input type="hidden" name="classe" value="<?= $id_classe_sel ?>">
    <input type="hidden" name="niveau" value="<?= h($niveau_sel) ?>">
    <?php
}

// Mode « partiel » (AJAX) : réponse limitée au contenu de #stat-zone, sans
// header/footer — voir initAjaxZone()/chargerPartiel() dans layout/footer.php.
$es_partiel = isset($_GET['partiel']);

if (!$es_partiel) {
    $titre_page = 'Statistiques';
    require_once __DIR__ . '/../../layout/header.php';
    ?>
    <style>
    .stat-card{border-left:4px solid #1a3c6b;background:#f8faff;border-radius:6px;padding:10px 14px;}
    .stat-num{font-size:1.6rem;font-weight:700;color:#1a3c6b;}
    .stat-lbl{font-size:.75rem;color:#666;text-transform:uppercase;}
    .tbl-stat th{background:#1a3c6b;color:#fff;font-size:.77rem;padding:7px 10px;}
    .tbl-stat td{font-size:.81rem;padding:6px 10px;vertical-align:middle;}
    .tbl-stat tr:hover td{background:#f0f4ff;}
    .nav-ong .nav-link{color:#1a3c6b;border-radius:6px 6px 0 0;font-size:.83rem;}
    .nav-ong .nav-link.active{background:#1a3c6b;color:#fff;font-weight:600;}
    /* Entêtes bien délimitées (bordures blanches sur fond bleu) — appliqué
       aux tableaux ventilés par genre (Par classe/Résultats, Par niveau,
       Par compétence/Évaluation), demande du 17-18/08/2026. */
    .tbl-genre-bordered{border-collapse:collapse!important}
    .tbl-genre-bordered th{border:1.5px solid #ffffff!important;text-align:center!important}
    .tbl-genre-bordered td{border:1px solid #dbe4f3!important}
    .cartes-genre{display:flex}
    .cartes-genre .stat-card{flex:1;display:flex;flex-direction:column;justify-content:center;min-height:78px}
    </style>

    <div class="page-titre d-flex align-items-center justify-content-between flex-wrap gap-2">
      <h4><i class="bi bi-bar-chart-line me-2" style="color:#1a3c6b"></i>Statistiques scolaires</h4>
      <span class="badge" style="background:#dbeafe;color:#1e3a8a;font-size:.78rem;padding:5px 12px;border-radius:20px">
        <?= h($val_annee) ?>
      </span>
    </div>

    <?= flash_html() ?>
    <?php
} else {
    header('Content-Type: text/html; charset=utf-8');
}

$q = fn(string $o) => "?onglet=$o&vue=$vue&classe=$id_classe&niveau=" . rawurlencode($niveau_f) . "&periode_detail=" . rawurlencode($periode_detail);
?>

<div id="stat-zone">

<!-- ── Onglets ── -->
<ul class="nav nav-tabs nav-ong mb-0 border-bottom-0">
  <li class="nav-item">
    <a class="nav-link <?= $onglet === 'eleves' ? 'active' : '' ?>" data-ajax-nav href="<?= h($q('eleves')) ?>">
      <i class="bi bi-people me-1"></i>Par classe / Résultats
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $onglet === 'competences' ? 'active' : '' ?>" data-ajax-nav href="<?= h($q('competences')) ?>">
      <i class="bi bi-journal-bookmark me-1"></i>Par compétence / Évaluation
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $onglet === 'effectifs' ? 'active' : '' ?>" data-ajax-nav href="<?= h($q('effectifs')) ?>">
      <i class="bi bi-diagram-3 me-1"></i>Effectifs / Niveaux
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $onglet === 'niveau' ? 'active' : '' ?>" data-ajax-nav href="<?= h($q('niveau')) ?>">
      <i class="bi bi-bar-chart-steps me-1"></i>Par niveau
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $onglet === 'non_evalue' ? 'active' : '' ?>" data-ajax-nav href="<?= h($q('non_evalue')) ?>">
      <i class="bi bi-journal-x me-1"></i>Non évalué
    </a>
  </li>
  <?php if ($is_admin): ?>
  <li class="nav-item">
    <a class="nav-link <?= $onglet === 'enseignants' ? 'active' : '' ?>" data-ajax-nav href="<?= h($q('enseignants')) ?>">
      <i class="bi bi-person-badge me-1"></i>Enseignants
    </a>
  </li>
  <?php endif; ?>
</ul>

<div class="card border-top-0" style="border-radius:0 6px 6px 6px;border-color:#c7d8f0">
<div class="card-body">

<!-- ── Filtres communs ── -->
<form method="get" class="row g-2 align-items-end mb-3" data-ajax-nav-form>
  <input type="hidden" name="onglet" value="<?= h($onglet) ?>">
  <div class="col-auto">
    <label class="form-label mb-1" style="font-size:.78rem;font-weight:600;color:#1a3c6b">Période</label>
    <div class="d-flex gap-1">
      <a href="?onglet=<?= $onglet ?>&vue=trim&classe=<?= $id_classe ?>&niveau=<?= h($niveau_f) ?>&periode_detail=<?= h($periode_detail) ?>" data-ajax-nav
         class="btn btn-sm <?= $vue === 'trim' ? 'btn-abz-primary' : 'btn-abz-outline' ?>">Trimestre</a>
      <a href="?onglet=<?= $onglet ?>&vue=annee&classe=<?= $id_classe ?>&niveau=<?= h($niveau_f) ?>&periode_detail=<?= h($periode_detail) ?>" data-ajax-nav
         class="btn btn-sm <?= $vue === 'annee' ? 'btn-abz-primary' : 'btn-abz-outline' ?>">Annuelle</a>
    </div>
  </div>
  <?php if ($vue === 'trim'): ?>
  <div class="col-auto">
    <label class="form-label mb-1 d-block" style="font-size:.78rem;font-weight:600;color:#1a3c6b">Trimestre<?= in_array($onglet, ['eleves', 'competences', 'niveau'], true) ? ' / Évaluation' : '' ?></label>
    <?php if (in_array($onglet, ['eleves', 'competences', 'niveau'], true) && $id_trim): ?>
      <select name="periode_detail" class="form-select form-select-sm" data-ajax-nav-auto>
        <?php foreach ($seqs_trim_actif_stat as $s): ?>
          <option value="<?= $s['id_seq'] ?>" <?= $mode_eval === 'seq' && $id_seq_stat == $s['id_seq'] ? 'selected' : '' ?>>
            <?= h($s['libelle_seq']) ?><?= ($seq_act['id_seq'] ?? 0) == $s['id_seq'] ? ' ★' : '' ?>
          </option>
        <?php endforeach; ?>
        <option value="trim" <?= $mode_eval === 'trim' ? 'selected' : '' ?>><?= h($trim_lib) ?></option>
      </select>
    <?php else: ?>
      <span class="badge" style="background:#dbeafe;color:#1e3a8a;font-size:.82rem;padding:7px 14px;border-radius:8px">
        <?= $id_trim ? h($trim_lib) . ' (en cours)' : 'Aucun trimestre en cours' ?>
      </span>
    <?php endif; ?>
    <input type="hidden" name="vue" value="trim">
  </div>
  <?php else: ?>
  <div class="col-auto">
    <label class="form-label mb-1 d-block" style="font-size:.78rem;font-weight:600;color:#1a3c6b">Année</label>
    <span class="badge" style="background:#dbeafe;color:#1e3a8a;font-size:.82rem;padding:7px 14px;border-radius:8px">
      <?= h($val_annee) ?>
    </span>
    <input type="hidden" name="vue" value="annee">
  </div>
  <?php endif; ?>

  <?php if (!in_array($onglet, ['effectifs', 'enseignants', 'niveau'], true)): ?>
  <div class="col-md-3">
    <label class="form-label mb-1" style="font-size:.78rem;font-weight:600;color:#1a3c6b">Classe (optionnel)</label>
    <?php render_select_classe_niveau($classes, $niveaux_dispo, $id_classe, $niveau_f); ?>
  </div>
  <?php endif; ?>

  <div class="col-auto">
    <button type="button" class="btn btn-sm btn-abz-primary"
            onclick="afficherApercu('<?= APP_URL ?>/pdf/statistiques.php?onglet=<?= $onglet ?>&vue=<?= $vue ?>&trim=<?= $id_trim ?>&classe=<?= $id_classe ?>&niveau=<?= h($niveau_f) ?>&mode=<?= $mode_eval ?>&seq_stat=<?= $id_seq_stat ?>', 'Statistiques', 'stat_generale', 'landscape')">
      <i class="bi bi-file-earmark-pdf me-1"></i>PDF
    </button>
  </div>
  <?php if (in_array($onglet, ['niveau', 'non_evalue', 'competences', 'eleves'], true)): ?>
  <div class="col-auto">
    <a id="lienExcelStats" class="btn btn-sm btn-abz-gold"
       data-base="<?= APP_URL ?>/pages/statistiques/excel_stats.php?onglet=<?= $onglet ?>&vue=<?= $vue ?>&trim=<?= $id_trim ?>&classe=<?= $id_classe ?>&niveau=<?= h($niveau_f) ?>&mode=<?= $mode_eval ?>&seq_stat=<?= $id_seq_stat ?>"
       href="<?= APP_URL ?>/pages/statistiques/excel_stats.php?onglet=<?= $onglet ?>&vue=<?= $vue ?>&trim=<?= $id_trim ?>&classe=<?= $id_classe ?>&niveau=<?= h($niveau_f) ?>&mode=<?= $mode_eval ?>&seq_stat=<?= $id_seq_stat ?>">
      <i class="bi bi-file-earmark-excel me-1"></i>Excel
    </a>
  </div>
  <?php if (signature_etablissement_chemin()): ?>
  <div class="col-auto">
    <div class="chk-signature form-check form-check-inline mb-0" style="user-select:none">
      <input class="form-check-input" type="checkbox" id="chkSigExcelStats" onchange="appliquerSigExcelStats()">
      <label class="form-check-label small" for="chkSigExcelStats">Signature numérique</label>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; ?>
  <div class="col-auto">
    <a href="<?= APP_URL ?>/pages/statistiques/documents.php<?= $id_classe ? '?classe=' . $id_classe : '' ?>" class="btn btn-sm btn-abz-outline">
      <i class="bi bi-files me-1"></i>Documents de classe (relevé / fiche stat)
    </a>
  </div>
</form>

<?php if (!$periode_ok): ?>
<div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i>Aucun trimestre en cours (aucune séquence active).</div>

<?php elseif ($onglet === 'eleves'): ?>
<!-- ══ ONGLET 1 : RÉSULTATS PAR CLASSE ══ -->
<!-- Demande du 17/08/2026 : Félicitations/Encouragements/Avert./Blâme
     retirés (seul T.H reste) ; Recalés/Admis/T.H/Taux de réussite ventilés
     par genre (F/G/T) — Moy Gen reste une seule valeur (pas de ventilation
     par genre, précisé dans un message de suivi). Cartes de résumé (Élèves
     évalués, Admis, Taux de réussite) de même hauteur/largeur. Ligne TOTAL
     en pied de tableau (somme pour les effectifs, moyenne pondérée pour
     Moy Gen/Taux). -->
<?php
$classes_show = $classes;
if ($id_classe)           $classes_show = array_filter($classes_show, fn($c) => (int) $c['IDClasses'] === $id_classe);
elseif ($niveau_f !== '') $classes_show = array_filter($classes_show, fn($c) => $c['Niveau'] === $niveau_f);

// Détail « Évaluation en cours » (demande du 18/08/2026, même bascule que
// l'onglet « Par compétence / Évaluation ») : $seqs_override restreint le
// classement/les moyennes à la seule séquence active plutôt qu'au trimestre
// entier — voir classement_sur_sequences() (notes_apc.php), calcul EN LIGNE,
// jamais persisté ni utilisé par les bulletins. null = comportement
// historique inchangé (cache moyenne_trimestre).
$seqs_override = ($vue === 'trim' && $mode_eval === 'seq' && $id_seq_stat) ? [$id_seq_stat] : null;

$all_stats = [];
$global = ['classes' => ['M' => 0, 'F' => 0, 'T' => 0], 'moy_ge10' => ['M' => 0, 'F' => 0, 'T' => 0], 'tab' => ['M' => 0, 'F' => 0, 'T' => 0]];
$global_nb = 0; $global_filles = 0; $global_garcons = 0; $global_moy_somme = 0.0;
foreach ($classes_show as $c) {
    $st = stats_classe((int) $c['IDClasses'], $val_annee, $vue, $id_trim, $seqs_override);
    $st['classe'] = $c['DesignationClasses'];
    $b = bilan_classe_genre((int) $c['IDClasses'], $val_annee, $vue, $id_trim, $seqs_override);
    $st['bilan'] = $b;
    $st['taux_genre'] = [];
    foreach (['M', 'F', 'T'] as $g) {
        $st['taux_genre'][$g] = $b['classes'][$g] > 0 ? round($b['moy_ge10'][$g] / $b['classes'][$g] * 100, 1) : 0;
    }
    $all_stats[] = $st;
    foreach (['M', 'F', 'T'] as $g) {
        $global['classes'][$g]  += $b['classes'][$g];
        $global['moy_ge10'][$g] += $b['moy_ge10'][$g];
        $global['tab'][$g]      += $b['tab'][$g];
    }
    $global_nb      += $st['nb'];
    $global_filles   += $st['filles'];
    $global_garcons  += $st['garcons'];
    if ($b['moy_gen']['T'] !== null) $global_moy_somme += $b['moy_gen']['T'] * $b['classes']['T'];
}
$global_taux_genre = [];
foreach (['M', 'F', 'T'] as $g) {
    $global_taux_genre[$g] = $global['classes'][$g] > 0 ? round($global['moy_ge10'][$g] / $global['classes'][$g] * 100, 1) : 0;
}
$global_moy_gen = $global['classes']['T'] > 0 ? round($global_moy_somme / $global['classes']['T'], 2) : null;
?>
<div class="row g-2 mb-3 cartes-genre">
  <div class="col-md-4">
    <div class="stat-card">
      <div class="stat-lbl mb-1">Élèves évalués</div>
      <div class="d-flex gap-3">
        <div><span class="stat-num" style="font-size:1.15rem"><?= $global['classes']['F'] ?></span> <span class="stat-lbl">F</span></div>
        <div><span class="stat-num" style="font-size:1.15rem"><?= $global['classes']['M'] ?></span> <span class="stat-lbl">M</span></div>
        <div><span class="stat-num" style="font-size:1.15rem"><?= $global['classes']['T'] ?></span> <span class="stat-lbl">T</span></div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="stat-card">
      <div class="stat-lbl mb-1">Admis (≥10)</div>
      <div class="d-flex gap-3">
        <div><span class="stat-num" style="font-size:1.15rem"><?= $global['moy_ge10']['F'] ?></span> <span class="stat-lbl">F</span></div>
        <div><span class="stat-num" style="font-size:1.15rem"><?= $global['moy_ge10']['M'] ?></span> <span class="stat-lbl">M</span></div>
        <div><span class="stat-num" style="font-size:1.15rem"><?= $global['moy_ge10']['T'] ?></span> <span class="stat-lbl">T</span></div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="stat-card">
      <div class="stat-lbl mb-1">Taux de réussite</div>
      <div class="d-flex gap-3">
        <div><span class="stat-num" style="font-size:1.15rem"><?= $global_taux_genre['F'] ?>%</span> <span class="stat-lbl">F</span></div>
        <div><span class="stat-num" style="font-size:1.15rem"><?= $global_taux_genre['M'] ?>%</span> <span class="stat-lbl">M</span></div>
        <div><span class="stat-num" style="font-size:1.15rem"><?= $global_taux_genre['T'] ?>%</span> <span class="stat-lbl">T</span></div>
      </div>
    </div>
  </div>
</div>

<div class="table-responsive">
<table class="table tbl-stat tbl-genre-bordered table-hover mb-0" style="font-size:.72rem">
  <thead>
    <tr>
      <th rowspan="2" style="vertical-align:middle;min-width:110px">Classe</th>
      <th rowspan="2" style="vertical-align:middle">Effectif</th>
      <th rowspan="2" style="vertical-align:middle">Filles / Garçons</th>
      <th rowspan="2" style="vertical-align:middle;width:56px">Moy. 1er</th>
      <th rowspan="2" style="vertical-align:middle;width:56px">Moy. dern.</th>
      <th rowspan="2" style="vertical-align:middle">Moy Gen</th>
      <th colspan="3">Recalés</th>
      <th colspan="3">Admis</th>
      <th colspan="3">T.H</th>
      <th colspan="3">Taux réussite</th>
    </tr>
    <tr>
      <?php for ($i = 0; $i < 4; $i++): ?><th>F</th><th>M</th><th>T</th><?php endfor; ?>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($all_stats as $st): $b = $st['bilan']; $mg = $b['moy_gen']['T']; ?>
    <tr>
      <td class="fw-semibold"><?= h($st['classe']) ?></td>
      <td><?= $st['nb'] ?></td>
      <td>
        <span class="badge" style="background:#fce7f3;color:#9d174d"><?= $st['filles'] ?> F</span>
        <span class="badge" style="background:#dbeafe;color:#1e3a8a"><?= $st['garcons'] ?> M</span>
      </td>
      <td><?= $fmt($st['premier']) ?></td>
      <td><?= $fmt($st['dernier']) ?></td>
      <td class="fw-semibold <?= $mg !== null ? ($mg >= 10 ? 'text-success' : 'text-danger') : '' ?>"><?= $fmt($mg) ?></td>
      <?php foreach (['F', 'M', 'T'] as $g): ?><td><?= $b['moy_lt10'][$g] ?></td><?php endforeach; ?>
      <?php foreach (['F', 'M', 'T'] as $g): ?><td><?= $b['moy_ge10'][$g] ?></td><?php endforeach; ?>
      <?php foreach (['F', 'M', 'T'] as $g): ?><td><?= $b['tab'][$g] ?></td><?php endforeach; ?>
      <?php foreach (['F', 'M', 'T'] as $g): ?><td><?= $st['taux_genre'][$g] ?>%</td><?php endforeach; ?>
    </tr>
    <?php endforeach; ?>
  </tbody>
  <?php if ($all_stats): ?>
  <tfoot>
    <tr style="background:#e8f0fe;font-weight:700">
      <td>TOTAL</td>
      <td><?= $global_nb ?></td>
      <td>
        <span class="badge" style="background:#fce7f3;color:#9d174d"><?= $global_filles ?> F</span>
        <span class="badge" style="background:#dbeafe;color:#1e3a8a"><?= $global_garcons ?> M</span>
      </td>
      <td>—</td>
      <td>—</td>
      <td><?= $fmt($global_moy_gen) ?></td>
      <?php foreach (['F', 'M', 'T'] as $g): ?><td><?= $global['classes'][$g] - $global['moy_ge10'][$g] ?></td><?php endforeach; ?>
      <?php foreach (['F', 'M', 'T'] as $g): ?><td><?= $global['moy_ge10'][$g] ?></td><?php endforeach; ?>
      <?php foreach (['F', 'M', 'T'] as $g): ?><td><?= $global['tab'][$g] ?></td><?php endforeach; ?>
      <?php foreach (['F', 'M', 'T'] as $g): ?><td><?= $global_taux_genre[$g] ?>%</td><?php endforeach; ?>
    </tr>
  </tfoot>
  <?php endif; ?>
</table>
</div>

<?php elseif ($onglet === 'competences'): ?>
<!-- ══ ONGLET 2 : PAR COMPÉTENCE / ÉVALUATION (par classe) ══ -->
<!-- Demande du 18/08/2026 : code en 1ère colonne, Nb évalués/Échoués/Admis/
     Taux ventilés par genre (F/M/T), filtre Niveau en plus de Classe,
     bascule Évaluation en cours (séquence active seule) / Trimestre entier. -->
<?php
$classes_show = $classes;
if ($id_classe)          $classes_show = array_filter($classes_show, fn($c) => (int) $c['IDClasses'] === $id_classe);
elseif ($niveau_f !== '') $classes_show = array_filter($classes_show, fn($c) => $c['Niveau'] === $niveau_f);

if ($vue === 'annee') {
    $seqs_choisis = array_column(db_all("SELECT s.id_seq FROM sequence s JOIN trimestre t ON t.id_trim = s.id_trim WHERE t.id_annee = ?", [$val_annee]), 'id_seq');
} elseif ($mode_eval === 'seq') {
    $seqs_choisis = $id_seq_stat ? [$id_seq_stat] : [];
} else {
    $seqs_choisis = sequences_du_trimestre($id_trim);
}

$une_classe_affichee = false;
foreach ($classes_show as $c):
    $stats_c = stats_par_competence_genre([(int) $c['IDClasses']], $val_annee, $seqs_choisis);
    if (empty($stats_c)) continue;
    $une_classe_affichee = true;
?>
  <h6 class="fw-bold mt-3" style="color:#1a3c6b"><i class="bi bi-door-open me-1"></i><?= h($c['DesignationClasses']) ?></h6>
  <div class="table-responsive mb-3">
  <table class="table tbl-stat tbl-genre-bordered table-hover mb-0" style="font-size:.72rem">
    <thead>
      <tr>
        <th rowspan="2" style="vertical-align:middle">Code</th>
        <th rowspan="2" style="vertical-align:middle">Compétence</th>
        <th rowspan="2" style="vertical-align:middle">Barème</th>
        <th rowspan="2" style="vertical-align:middle">Moyenne</th>
        <th colspan="3">Nb évalués</th>
        <th colspan="3">Échoués</th>
        <th colspan="3">Admis</th>
        <th colspan="3">Taux réussite</th>
      </tr>
      <tr>
        <?php for ($i = 0; $i < 4; $i++): ?><th>F</th><th>M</th><th>T</th><?php endfor; ?>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($stats_c as $d): $moy = $d['moy']['T']; ?>
    <tr>
      <td><span class="badge-code"><?= h($d['code'] ?: '—') ?></span></td>
      <td class="fw-semibold"><?= h($d['competence']) ?></td>
      <td>/<?= (int) $d['bareme'] ?></td>
      <td class="fw-bold <?= $moy !== null && $moy >= 10 ? 'text-success' : 'text-danger' ?>"><?= $fmt($moy) ?></td>
      <?php foreach (['F', 'M', 'T'] as $g): ?><td><?= $d['nb'][$g] ?></td><?php endforeach; ?>
      <?php foreach (['F', 'M', 'T'] as $g): ?><td><?= $d['echoues'][$g] ?></td><?php endforeach; ?>
      <?php foreach (['F', 'M', 'T'] as $g): ?><td><?= $d['admis'][$g] ?></td><?php endforeach; ?>
      <?php foreach (['F', 'M', 'T'] as $g): ?><td><?= $d['taux'][$g] ?>%</td><?php endforeach; ?>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endforeach; ?>
<?php if (!$une_classe_affichee): ?>
  <div class="alert alert-light border text-center text-muted py-3"><i class="bi bi-info-circle me-1"></i>Aucune donnée pour cette sélection.</div>
<?php endif; ?>

<?php elseif ($onglet === 'effectifs'): ?>
<!-- ══ ONGLET 3 : EFFECTIFS PAR NIVEAU ══ -->
<?php
$niveaux_map = array_column(db_all("SELECT LibelleNiveau, OrdreNiveau FROM niveau ORDER BY OrdreNiveau"), 'OrdreNiveau', 'LibelleNiveau');
$par_niveau = [];
foreach ($classes as $c) {
    $nb = (int) db_val("SELECT COUNT(*) FROM inscrire i JOIN eleve e ON e.id_eleve=i.id_eleve WHERE i.IDClasses=? AND i.val_annee=? AND e.statut='actif'", [$c['IDClasses'], $val_annee]);
    $nf = (int) db_val("SELECT COUNT(*) FROM inscrire i JOIN eleve e ON e.id_eleve=i.id_eleve WHERE i.IDClasses=? AND i.val_annee=? AND e.statut='actif' AND e.Sexe_elv LIKE 'F%'", [$c['IDClasses'], $val_annee]);
    $par_niveau[$c['Niveau'] ?: 'Non défini'][] = ['classe' => $c['DesignationClasses'], 'nb' => $nb, 'filles' => $nf, 'garcons' => $nb - $nf];
}
uksort($par_niveau, fn($a, $b) => ($niveaux_map[$a] ?? 999) <=> ($niveaux_map[$b] ?? 999));
$toutes = array_merge(...array_values($par_niveau));
$total_eleves = array_sum(array_column($toutes, 'nb'));
$total_filles = array_sum(array_column($toutes, 'filles'));
?>
<div class="row g-2 mb-3">
  <div class="col-4"><div class="stat-card"><div class="stat-num"><?= $total_eleves ?></div><div class="stat-lbl">Total élèves</div></div></div>
  <div class="col-4"><div class="stat-card"><div class="stat-num"><?= $total_filles ?></div><div class="stat-lbl">Filles</div></div></div>
  <div class="col-4"><div class="stat-card"><div class="stat-num"><?= $total_eleves - $total_filles ?></div><div class="stat-lbl">Garçons</div></div></div>
</div>
<?php foreach ($par_niveau as $niveau => $rows): ?>
  <h6 class="fw-bold mt-3" style="color:#1a3c6b"><i class="bi bi-diagram-3 me-1"></i>Niveau <?= h($niveau) ?></h6>
  <div class="table-responsive mb-3">
  <table class="table tbl-stat table-hover mb-0">
    <thead><tr><th>Classe</th><th>Effectif</th><th>Filles</th><th>Garçons</th><th>% Filles</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $pct = $r['nb'] > 0 ? round($r['filles'] / $r['nb'] * 100, 1) : 0; ?>
      <tr>
        <td class="fw-semibold"><?= h($r['classe']) ?></td>
        <td><strong><?= $r['nb'] ?></strong></td>
        <td><span class="badge" style="background:#fce7f3;color:#9d174d"><?= $r['filles'] ?></span></td>
        <td><span class="badge" style="background:#dbeafe;color:#1e3a8a"><?= $r['garcons'] ?></span></td>
        <td>
          <div class="progress" style="height:12px;min-width:60px">
            <div class="progress-bar" style="width:<?= $pct ?>%;background:#9d174d"><?= $pct ?>%</div>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php $st_nb = array_sum(array_column($rows, 'nb')); $st_f = array_sum(array_column($rows, 'filles')); ?>
    <tr style="background:#e8f0fe;font-weight:700">
      <td>Total niveau</td>
      <td><?= $st_nb ?></td><td><?= $st_f ?></td><td><?= $st_nb - $st_f ?></td>
      <td><?= $st_nb > 0 ? round($st_f / $st_nb * 100, 1) : 0 ?>%</td>
    </tr>
    </tbody>
  </table>
  </div>
<?php endforeach; ?>

<?php elseif ($onglet === 'niveau'): ?>
<!-- ══ ONGLET : PAR NIVEAU (bilan M/F/T) ══ -->
<?php
// $seqs_override (demande du 18/08/2026) : même bascule « Évaluation en
// cours » que les autres onglets — voir bilan_classe_genre().
$seqs_override = ($vue === 'trim' && $mode_eval === 'seq' && $id_seq_stat) ? [$id_seq_stat] : null;
$niveaux_map = array_column(db_all("SELECT LibelleNiveau, OrdreNiveau FROM niveau ORDER BY OrdreNiveau"), 'OrdreNiveau', 'LibelleNiveau');
$par_niveau = [];
foreach ($classes as $c) { $par_niveau[$c['Niveau'] ?: 'Non défini'][] = $c; }
uksort($par_niveau, fn($a, $b) => ($niveaux_map[$a] ?? 999) <=> ($niveaux_map[$b] ?? 999));
foreach ($par_niveau as $niveau => $classes_niveau) {
    $lignes = [];
    foreach ($classes_niveau as $c) {
        $lignes[] = ['classe' => $c['DesignationClasses'], 'bilan' => bilan_classe_genre((int) $c['IDClasses'], $val_annee, $vue, $id_trim, $seqs_override)];
    }
    render_tableau_bilan_genre('Niveau ' . $niveau, $lignes, $bilan_cols_niveau);
}
?>

<?php elseif ($onglet === 'non_evalue'): ?>
<!-- ══ ONGLET : NON ÉVALUÉ ══ -->
<!-- Demande du 18/08/2026, suite : explique l'écart entre l'effectif inscrit
     (Scolarité > Élèves) et « Élèves évalués » (onglet Par classe/Résultats)
     — liste NOMINATIVE, par classe, des élèves qui n'entrent pas dans ce
     compte, avec la raison (aucune moyenne calculée / non classé). Même
     filtre Classe/Niveau que les autres onglets ; pas de bascule Évaluation
     en cours/Trimestre (notion propre au trimestre entier, pas à une
     séquence isolée) — voir eleves_non_evalues_classe(). -->
<?php
$classes_show = $classes;
if ($id_classe)          $classes_show = array_filter($classes_show, fn($c) => (int) $c['IDClasses'] === $id_classe);
elseif ($niveau_f !== '') $classes_show = array_filter($classes_show, fn($c) => $c['Niveau'] === $niveau_f);

$lignes_ne = []; $total_inscrits = 0; $total_gap = 0;
foreach ($classes_show as $c) {
    $nb_inscrits = (int) db_val(
        "SELECT COUNT(*) FROM inscrire i JOIN eleve e ON e.id_eleve=i.id_eleve WHERE i.IDClasses=? AND i.val_annee=? AND e.statut='actif'",
        [(int) $c['IDClasses'], $val_annee]
    );
    $total_inscrits += $nb_inscrits;
    $ne = eleves_non_evalues_classe((int) $c['IDClasses'], $val_annee, $vue, $id_trim);
    foreach ($ne as $e) { $lignes_ne[] = $e + ['classe' => $c['DesignationClasses']]; }
    $total_gap += count($ne);
}
$total_evalues = $total_inscrits - $total_gap;
?>
<div class="row g-2 mb-3 cartes-genre">
  <div class="col-md-4">
    <div class="stat-card"><div class="stat-lbl mb-1">Effectif inscrit</div><div class="stat-num"><?= $total_inscrits ?></div></div>
  </div>
  <div class="col-md-4">
    <div class="stat-card"><div class="stat-lbl mb-1">Élèves évalués</div><div class="stat-num text-success"><?= $total_evalues ?></div></div>
  </div>
  <div class="col-md-4">
    <div class="stat-card"><div class="stat-lbl mb-1">Non évalué (écart)</div><div class="stat-num text-danger"><?= $total_gap ?></div></div>
  </div>
</div>

<div class="table-responsive">
<table class="table tbl-stat tbl-genre-bordered table-hover mb-0" style="font-size:.8rem">
  <thead><tr><th>Classe</th><th>Matricule</th><th>Nom et prénom</th><th>Sexe</th><th>Raison</th></tr></thead>
  <tbody>
    <?php foreach ($lignes_ne as $e): ?>
    <tr>
      <td class="fw-semibold"><?= h($e['classe']) ?></td>
      <td><?= h($e['Mat_elv']) ?></td>
      <td><?= h($e['Nom_elv'] . ' ' . ($e['Prenom_elv'] ?? '')) ?></td>
      <td><?= stripos($e['Sexe_elv'] ?? '', 'F') === 0 ? 'F' : 'M' ?></td>
      <td><span class="badge bg-danger"><?= h($e['raison']) ?></span></td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$lignes_ne): ?>
      <tr><td colspan="5" class="text-center text-muted py-3">Aucun écart pour cette sélection — tous les élèves inscrits sont évalués.</td></tr>
    <?php endif; ?>
  </tbody>
  <?php if ($lignes_ne): ?>
  <tfoot><tr style="background:#e8f0fe;font-weight:700"><td colspan="4">TOTAL</td><td><?= count($lignes_ne) ?> élève<?= count($lignes_ne) > 1 ? 's' : '' ?></td></tr></tfoot>
  <?php endif; ?>
</table>
</div>

<?php elseif ($onglet === 'enseignants' && $is_admin): ?>
<!-- ══ ONGLET : ENSEIGNANTS ══ -->
<?php
// `fonction` ne contient que id_fonction (DIRECTEUR/ENSEIGNANT/SECRETAIRE,
// libellé = la clé elle-même) — pas de table `grade` dans ce schéma
// (contrairement à ABZ_MBE) : la colonne « Grade » d'ABZ_MBE est donc
// remplacée par « Fonction ». `dispenser` (affectation enseignant →
// classe/compétence) existe mais n'est pas encore peuplée par l'école : la
// colonne Classes affiche 0 pour tout le monde tant que c'est le cas.
$ens_list = db_all(
    "SELECT e.matricule_ens, e.nom_ens, e.prenom_ens, e.civilite_ens, e.id_fonction,
            COUNT(DISTINCT d.IDClasses) AS nb_classes
     FROM enseignant e
     LEFT JOIN dispenser d ON d.matricule_ens = e.matricule_ens AND d.val_annee = ?
     GROUP BY e.matricule_ens, e.nom_ens, e.prenom_ens, e.civilite_ens, e.id_fonction
     ORDER BY e.nom_ens",
    [$val_annee]
);
?>
<div class="row g-2 mb-3">
  <div class="col-4"><div class="stat-card"><div class="stat-num"><?= count($ens_list) ?></div><div class="stat-lbl">Enseignants</div></div></div>
  <div class="col-4"><div class="stat-card"><div class="stat-num"><?= count($classes) ?></div><div class="stat-lbl">Classes actives</div></div></div>
  <div class="col-4"><div class="stat-card"><div class="stat-num"><?= (int) db_val("SELECT COUNT(*) FROM inscrire i JOIN eleve e ON e.id_eleve=i.id_eleve WHERE i.val_annee=? AND e.statut='actif'", [$val_annee]) ?></div><div class="stat-lbl">Élèves inscrits</div></div></div>
</div>
<div class="table-responsive">
<table class="table tbl-stat table-hover mb-0">
  <thead><tr><th>Enseignant</th><th>Fonction</th><th>Classes</th></tr></thead>
  <tbody>
  <?php foreach ($ens_list as $e): ?>
  <tr>
    <td class="fw-semibold">
      <a href="<?= APP_URL ?>/pages/enseignants/voir.php?mat=<?= urlencode($e['matricule_ens']) ?>"
         class="text-decoration-none" style="color:#1a3c6b">
        <?= h(trim(($e['civilite_ens'] ?? '') . ' ' . mb_strtoupper($e['nom_ens']) . ' ' . ($e['prenom_ens'] ?? ''))) ?>
      </a>
    </td>
    <td><?= h(libelle_role($e['id_fonction'] ?? '') ?: '—') ?></td>
    <td><?= $e['nb_classes'] ?></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

</div><!-- card-body -->
</div><!-- card -->

</div><!-- /#stat-zone -->

<?php
if ($es_partiel) exit; // rien de plus dans une réponse AJAX partielle.
?>

<script>
// Défini EN DEHORS de #stat-zone (jamais remplacé par un rechargement AJAX
// partiel) — même convention qu'ABZ_MBE.
function appliquerSigExcelStats() {
    const lien = document.getElementById('lienExcelStats');
    if (!lien) return;
    const sig = document.getElementById('chkSigExcelStats')?.checked;
    lien.href = lien.dataset.base + (sig ? '&signature=1' : '');
}

// Pilote le sélecteur unifié Classe/Niveau (render_select_classe_niveau(),
// PHP) — répercute la sélection sur les 2 champs cachés name="classe"/
// name="niveau" puis déclenche la soumission AJAX du formulaire (comme
// data-ajax-nav-auto, qu'on ne peut pas utiliser ici : un seul contrôle
// visible représente 2 paramètres différents à la fois).
function appliquerSelectClasseNiveau(sel) {
    const form = sel.closest('form');
    if (!form) return;
    const champClasse = form.querySelector('input[name="classe"]');
    const champNiveau = form.querySelector('input[name="niveau"]');
    const v = sel.value;
    if (v.indexOf('niveau:') === 0)      { champClasse.value = ''; champNiveau.value = v.slice(7); }
    else if (v.indexOf('classe:') === 0) { champClasse.value = v.slice(7); champNiveau.value = ''; }
    else                                 { champClasse.value = ''; champNiveau.value = ''; }
    form.requestSubmit();
}
</script>

<?php
$ajax_zone_id = 'stat-zone'; // voir layout/footer.php — initAjaxZone() y est appelé
require_once __DIR__ . '/../../layout/footer.php';
