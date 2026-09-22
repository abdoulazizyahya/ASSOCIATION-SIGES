<?php
header('Cache-Control: no-store, no-cache, must-revalidate');
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_connexion();

$role     = role_connecte();
$is_admin = in_array($role, ['ADMIN','PROVISEUR', 'FONDATEUR','CENSEUR']) || $role === 'MEMBRE_ASSOCIATION';
$is_ens   = ($role === 'ENSEIGNANT');
$mat_ens  = $is_ens ? get_matricule_ens_connecte() : null;

if (!$is_admin && !$is_ens) { flash_set('erreur','Accès non autorisé.'); rediriger('dashboard.php'); }

$annee_act = get_annee_active();
$id_annee  = (int)($annee_act['id'] ?? 0);
$val_annee = $annee_act['libelle'] ?? '';

$onglet = $_GET['onglet'] ?? 'eleves';
$allowed_onglets = ['eleves','disciplines','effectifs','enseignants','section','niveau','matiere'];
if (!in_array($onglet, $allowed_onglets)) $onglet = 'eleves';

// Séquence active de l'année (et son trimestre) — conservée pour les liens
// legacy (?seq=), mais plus utilisée pour le calcul (voir $id_trim_comp).
$seq_active = db_one("SELECT s.*, t.id AS id_trim, t.libelle AS trim_lib FROM sequence s JOIN trimestre t ON t.id=s.id_trim WHERE s.active=1 AND t.id_annee=? LIMIT 1", [$id_annee]);

$vue = in_array($_GET['vue'] ?? '', ['seq', 'trim', 'annee'], true) ? $_GET['vue'] : 'trim';
// Séquence et trimestre : toujours ceux EN COURS, plus de choix possible
// parmi les séquences/trimestres passés — demande explicite, même principe
// que secondaire/pages/statistiques/documents.php.
$id_seq    = (int)($seq_active['id'] ?? 0);
$id_trim   = (int)($seq_active['id_trim'] ?? 0);
$id_classe = (int)($_GET['classe'] ?? 0);

// Trimestre actif (compétences/APC) — remplace la séquence active comme
// proxy de "la période en cours" pour tout calcul de moyenne : les
// compétences n'ont pas de sous-division par séquence, donc "Séquence" et
// "Trimestre" pointent désormais tous deux vers le même trimestre actif
// (trimestre.active, qui peut différer de la séquence active — constaté
// sur les données réelles). Voir prompt_continuite, mise à jour du
// 07/08/2026, Phase 6.
$trim_comp_actif = get_trimestre_actif();
$id_trim_comp     = (int)($trim_comp_actif['id'] ?? 0);

// Séquences de la période choisie.
$seq_ids = [];
if ($vue === 'trim' && $id_trim) {
    $seq_ids = array_column(db_all("SELECT id FROM sequence WHERE id_trim=?", [$id_trim]), 'id');
} elseif ($vue === 'annee') {
    $seq_ids = array_column(db_all("SELECT s.id FROM sequence s JOIN trimestre t ON t.id=s.id_trim WHERE t.id_annee=?", [$id_annee]), 'id');
} elseif ($id_seq) {
    $seq_ids = [$id_seq];
}

$in_ph = $seq_ids ? implode(',', array_fill(0, count($seq_ids), '?')) : '0';

// Classes selon le rôle
if ($is_admin) {
    $classes = db_all("SELECT c.* FROM classe c JOIN inscription i ON i.id_classe=c.id AND i.id_annee=? WHERE c.archivee=0 GROUP BY c.id ORDER BY c.ordre,c.designation", [$id_annee]);
} elseif ($is_ens && $mat_ens) {
    // PP : classes dont il est principal; sinon ses classes
    $classes = db_all(
        "SELECT DISTINCT c.* FROM classe c
         JOIN dispenser d ON d.IDClasses=c.id AND d.matricule_ens=? AND d.val_annee=?
         WHERE c.archivee=0 ORDER BY c.ordre,c.designation",
        [$mat_ens, $val_annee]
    );
}

// ── CALCUL STATS (compétences/APC — voir prompt_continuite du 07/08/2026,
// Phase 6 ; $vue==='annee' moyenne les moyennes trimestrielles existantes,
// même méthode que secondaire/pages/bulletins/pdf_annuel.php) ──────────────────────
function calc_stats_classe(int $id_classe, int $id_annee, string $vue, int $id_trim): array {
    $vide = ['nb'=>0,'moy'=>null,'taux'=>0,'premier'=>null,'dernier'=>null,'admis'=>0,'filles'=>0,'garcons'=>0];
    $eleves = db_all(
        "SELECT e.id, e.sexe FROM eleve e JOIN inscription i ON i.id_eleve=e.id AND i.id_classe=? AND i.id_annee=? WHERE e.statut='actif'",
        [$id_classe, $id_annee]
    );
    if (empty($eleves)) return $vide;

    // Règles 1/2/3/4 appliquées via le moteur commun (fonctions.php).
    $moys = $vue === 'annee' || $id_trim
        ? calc_moys_classe_periode_comp($id_classe, $id_annee, $vue, $id_trim, array_column($eleves, 'id'))
        : [];

    $filles  = count(array_filter($eleves, fn($e) => $e['sexe'] === 'F'));
    $garcons = count($eleves) - $filles;
    $nb = count($eleves);
    $admis = count(array_filter($moys, fn($m)=>$m>=10));
    // Règle 2 : taux de réussite calculé sur les élèves CLASSÉS (count($moys)),
    // pas sur l'effectif total — un élève non classé/annulé en est exclu.
    return [
        'nb'      => $nb,
        'moy'     => !empty($moys)?array_sum($moys)/count($moys):null,
        'taux'    => !empty($moys)?round($admis/count($moys)*100,1):0,
        'premier' => !empty($moys)?max($moys):null,
        'dernier' => !empty($moys)?min($moys):null,
        'admis'   => $admis,
        'filles'  => $filles,
        'garcons' => $garcons,
    ];
}

$fmt = fn(?float $v): string => $v===null?'—':rtrim(rtrim(number_format($v,2,'.',''),'0'),'.');

// Table M/F/T "bilan classe" (Classés/Moy&lt;10/Moy&ge;10/Félicit./Encour./
// T.H/Avert.T/Blâme T) + ligne SOUS-TOTAL — mêmes colonnes que le fichier
// Excel de référence (TEST_PV_CALCUL.xlsx, onglet INDUSTRIELLE), réutilisée
// par les onglets Section et Niveau (regroupement différent, table
// identique) pour ne pas dupliquer ce balisage HTML deux fois.
$bilan_cols = ['classes', 'moy_lt10', 'moy_ge10', 'felicit', 'encourag', 'tab', 'avert_trav', 'blame_trav'];
function render_tableau_bilan_genre(string $titre, array $lignes, array $bilan_cols): void {
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
    <table class="table tbl-stat table-hover mb-0" style="font-size:.72rem">
      <thead>
        <tr>
          <th rowspan="2" style="vertical-align:middle">Classe</th>
          <th colspan="3">Classés</th>
          <th colspan="3">Moy &lt; 10</th>
          <th colspan="3">Moy &ge; 10</th>
          <th colspan="3">Félicit.</th>
          <th colspan="3">Encour.</th>
          <th colspan="3">T.H</th>
          <th colspan="3">Avert. T.</th>
          <th colspan="3">Blâme T.</th>
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

$titre_page = 'Statistiques';
require_once __DIR__ . '/../../../layout/header.php';
?>
<style>
.btn-abz-primary{background:#1a3c6b;color:#fff;border:none;}
.btn-abz-primary:hover{background:#12305a;color:#fff;}
.btn-abz-outline{background:#fff;color:#1a3c6b;border:1.5px solid #1a3c6b;}
.btn-abz-outline:hover{background:#1a3c6b;color:#fff;}
.stat-card{border-left:4px solid #1a3c6b;background:#f8faff;border-radius:6px;padding:10px 14px;}
.stat-num{font-size:1.6rem;font-weight:700;color:#1a3c6b;}
.stat-lbl{font-size:.75rem;color:#666;text-transform:uppercase;}
.tbl-stat th{background:#1a3c6b;color:#fff;font-size:.77rem;padding:7px 10px;}
.tbl-stat td{font-size:.81rem;padding:6px 10px;vertical-align:middle;}
.tbl-stat tr:hover td{background:#f0f4ff;}
.nav-ong .nav-link{color:#1a3c6b;border-radius:6px 6px 0 0;font-size:.83rem;}
.nav-ong .nav-link.active{background:#1a3c6b;color:#fff;font-weight:600;}
</style>

<div class="page-titre d-flex align-items-center justify-content-between flex-wrap gap-2">
  <h4><i class="bi bi-bar-chart-line me-2" style="color:#1a3c6b"></i>Statistiques scolaires</h4>
  <span class="badge" style="background:#dbeafe;color:#1e3a8a;font-size:.78rem;padding:5px 12px;border-radius:20px">
    <?= h($val_annee) ?>
  </span>
</div>

<?= flash_html() ?>

<!-- ── Onglets ── -->
<ul class="nav nav-tabs nav-ong mb-0 border-bottom-0">
  <li class="nav-item">
    <a class="nav-link <?= $onglet==='eleves'?'active':'' ?>"
       href="?onglet=eleves&vue=<?= $vue ?>&seq=<?= $id_seq ?>&trim=<?= $id_trim ?>&classe=<?= $id_classe ?>">
      <i class="bi bi-people me-1"></i>Par classe / Résultats
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $onglet==='disciplines'?'active':'' ?>"
       href="?onglet=disciplines&vue=<?= $vue ?>&seq=<?= $id_seq ?>&trim=<?= $id_trim ?>&classe=<?= $id_classe ?>">
      <i class="bi bi-journal-bookmark me-1"></i>Par matière / Enseignant
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $onglet==='effectifs'?'active':'' ?>"
       href="?onglet=effectifs&vue=<?= $vue ?>&seq=<?= $id_seq ?>&trim=<?= $id_trim ?>&classe=<?= $id_classe ?>">
      <i class="bi bi-diagram-3 me-1"></i>Effectifs / Sections
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $onglet==='section'?'active':'' ?>"
       href="?onglet=section&vue=<?= $vue ?>&seq=<?= $id_seq ?>&trim=<?= $id_trim ?>&classe=<?= $id_classe ?>">
      <i class="bi bi-collection me-1"></i>Par section
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $onglet==='niveau'?'active':'' ?>"
       href="?onglet=niveau&vue=<?= $vue ?>&seq=<?= $id_seq ?>&trim=<?= $id_trim ?>&classe=<?= $id_classe ?>">
      <i class="bi bi-bar-chart-steps me-1"></i>Par niveau
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $onglet==='matiere'?'active':'' ?>"
       href="?onglet=matiere&vue=<?= $vue ?>&seq=<?= $id_seq ?>&trim=<?= $id_trim ?>&classe=<?= $id_classe ?>">
      <i class="bi bi-journal-text me-1"></i>Par matière
    </a>
  </li>
  <?php if ($is_admin): ?>
  <li class="nav-item">
    <a class="nav-link <?= $onglet==='enseignants'?'active':'' ?>"
       href="?onglet=enseignants&vue=<?= $vue ?>&seq=<?= $id_seq ?>&trim=<?= $id_trim ?>&classe=<?= $id_classe ?>">
      <i class="bi bi-person-badge me-1"></i>Enseignants
    </a>
  </li>
  <?php endif; ?>
</ul>

<div class="card border-top-0" style="border-radius:0 6px 6px 6px;border-color:#c7d8f0">
<div class="card-body">

<!-- ── Filtres communs ── -->
<form method="get" class="row g-2 align-items-end mb-3">
  <input type="hidden" name="onglet" value="<?= h($onglet) ?>">
  <div class="col-auto">
    <label class="form-label mb-1" style="font-size:.78rem;font-weight:600;color:#1a3c6b">Période</label>
    <div class="d-flex gap-1">
      <a href="?onglet=<?= $onglet ?>&vue=trim&classe=<?= $id_classe ?>"
         class="btn btn-sm <?= $vue==='trim'?'btn-abz-primary':'btn-abz-outline' ?>">Trimestre</a>
      <a href="?onglet=<?= $onglet ?>&vue=annee&classe=<?= $id_classe ?>"
         class="btn btn-sm <?= $vue==='annee'?'btn-abz-primary':'btn-abz-outline' ?>">Annuelle</a>
    </div>
  </div>
  <?php if ($vue === 'seq'): ?>
    <input type="hidden" name="vue" value="seq">
  <?php elseif ($vue === 'trim'): ?>
  <div class="col-auto">
    <label class="form-label mb-1 d-block" style="font-size:.78rem;font-weight:600;color:#1a3c6b">Trimestre</label>
    <span class="badge" style="background:#dbeafe;color:#1e3a8a;font-size:.82rem;padding:7px 14px;border-radius:8px">
      <?= $id_trim_comp ? h($trim_comp_actif['libelle'] ?? '') . ' (en cours)' : 'Aucun trimestre en cours' ?>
    </span>
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

  <?php if (!in_array($onglet, ['effectifs', 'enseignants', 'section', 'niveau'], true)): ?>
  <div class="col-md-3">
    <label class="form-label mb-1" style="font-size:.78rem;font-weight:600;color:#1a3c6b">Classe (optionnel)</label>
    <select name="classe" class="form-select form-select-sm" onchange="this.form.submit()">
      <option value="">— Toutes —</option>
      <?php foreach ($classes as $c): ?>
        <option value="<?= $c['id'] ?>" <?= $id_classe==$c['id']?'selected':''?>><?= h($c['designation']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php endif; ?>

  <div class="col-auto">
    <button type="button" class="btn btn-sm btn-abz-primary"
            onclick="afficherApercu('pdf_stats.php?onglet=<?= $onglet ?>&vue=<?= $vue ?>&seq=<?= $id_seq ?>&trim=<?= $id_trim ?>&classe=<?= $id_classe ?>', 'Statistiques', 'stat_generale', 'landscape')">
      <i class="bi bi-file-earmark-pdf me-1"></i>PDF
    </button>
  </div>
  <?php if (in_array($onglet, ['section', 'niveau', 'matiere', 'eleves'], true)): ?>
  <div class="col-auto">
    <a id="lienExcelStats" class="btn btn-sm btn-abz-gold" data-base="excel_stats.php?onglet=<?= $onglet ?>&vue=<?= $vue ?>&seq=<?= $id_seq ?>&trim=<?= $id_trim ?>&classe=<?= $id_classe ?>" href="excel_stats.php?onglet=<?= $onglet ?>&vue=<?= $vue ?>&seq=<?= $id_seq ?>&trim=<?= $id_trim ?>&classe=<?= $id_classe ?>">
      <i class="bi bi-file-earmark-excel me-1"></i>Excel
    </a>
  </div>
  <?php if (signature_configuree('chef_etablissement')): ?>
  <div class="col-auto">
    <div class="chk-signature form-check form-check-inline mb-0" style="user-select:none">
      <input class="form-check-input" type="checkbox" id="chkSigExcelStats" onchange="appliquerSigExcelStats()">
      <label class="form-check-label small" for="chkSigExcelStats">Signature numérique</label>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; ?>
  <div class="col-auto">
    <a href="documents.php<?= $id_classe ? '?classe='.$id_classe : '' ?>" class="btn btn-sm btn-abz-outline">
      <i class="bi bi-files me-1"></i>Documents de classe (relevé / fiche stat)
    </a>
  </div>
</form>

<?php if ($vue !== 'annee' && !$id_trim_comp): ?>
<div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i>Aucun trimestre actif.</div>
<?php elseif ($onglet === 'eleves'): ?>
<!-- ══ ONGLET 1 : RÉSULTATS PAR CLASSE ══ -->
<?php
$classes_show = $id_classe ? array_filter($classes, fn($c)=>(int)$c['id']===$id_classe) : $classes;
$global_nb=0; $global_admis=0; $global_moys=[];
?>
<!-- Résumé global -->
<?php
$all_stats = [];
foreach ($classes_show as $c) {
    $st = calc_stats_classe((int)$c['id'], $id_annee, $vue, $id_trim_comp);
    $st['classe'] = $c['designation'];
    $st['id']     = $c['id'];
    // Colonnes d'appréciation (Félicit./Encour./T.H./Avert./Blâme), mêmes
    // règles que secondaire/pages/statistiques/pdf_stat_classe.php, réutilisées via la
    // fonction partagée fonctions.php::calc_bilan_classe_genre() — demande
    // explicite d'enrichissement de cet onglet.
    $bilan = calc_bilan_classe_genre_comp((int)$c['id'], $id_annee, $val_annee, $vue, $id_trim_comp);
    $st['felicit']    = $bilan['felicit']['T'];
    $st['encourag']   = $bilan['encourag']['T'];
    $st['tab']        = $bilan['tab']['T'];
    $st['avert_trav'] = $bilan['avert_trav']['T'];
    $st['blame_trav'] = $bilan['blame_trav']['T'];
    $all_stats[]  = $st;
    $global_nb   += $st['nb'];
    $global_admis+= $st['admis']??0;
    if ($st['moy']!==null) $global_moys[]=$st['moy'];
}
$global_moy   = !empty($global_moys)?array_sum($global_moys)/count($global_moys):null;
$global_taux  = $global_nb>0?round($global_admis/$global_nb*100,1):0;
?>
<div class="row g-2 mb-3">
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-num"><?= $global_nb ?></div>
      <div class="stat-lbl">Élèves évalués</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-num"><?= $fmt($global_moy) ?></div>
      <div class="stat-lbl">Moyenne générale</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-num"><?= $global_admis ?></div>
      <div class="stat-lbl">Admis (≥10)</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="stat-num"><?= $global_taux ?>%</div>
      <div class="stat-lbl">Taux de réussite</div>
    </div>
  </div>
</div>

<div class="table-responsive">
<table class="table tbl-stat table-hover mb-0">
  <thead>
    <tr>
      <th>Classe</th>
      <th>Effectif</th>
      <th>Filles / Garçons</th>
      <th>Moyenne</th>
      <th>Moy. Premier</th>
      <th>Moy. Dernier</th>
      <th>Admis</th>
      <th>Taux réussite</th>
      <th>Félicit.</th>
      <th>Encour.</th>
      <th>T.H</th>
      <th>Avert. T.</th>
      <th>Blâme T.</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($all_stats as $st): ?>
    <tr>
      <td class="fw-semibold"><?= h($st['classe']) ?></td>
      <td><?= $st['nb'] ?></td>
      <td>
        <span class="badge" style="background:#fce7f3;color:#9d174d"><?= $st['filles'] ?> F</span>
        <span class="badge" style="background:#dbeafe;color:#1e3a8a"><?= $st['garcons'] ?> G</span>
      </td>
      <td class="fw-semibold <?= $st['moy']!==null&&$st['moy']>=10?'text-success':'text-danger' ?>">
        <?= $fmt($st['moy']) ?>
      </td>
      <td><?= $fmt($st['premier']??null) ?></td>
      <td><?= $fmt($st['dernier']??null) ?></td>
      <td><?= $st['admis']??0 ?> / <?= $st['nb'] ?></td>
      <td>
        <div class="progress" style="height:14px;min-width:80px">
          <div class="progress-bar <?= $st['taux']>=50?'bg-success':'bg-danger' ?>"
               style="width:<?= $st['taux'] ?>%">
            <?= $st['taux'] ?>%
          </div>
        </div>
      </td>
      <td><?= $st['felicit'] ?></td>
      <td><?= $st['encourag'] ?></td>
      <td><?= $st['tab'] ?></td>
      <td><?= $st['avert_trav'] ?></td>
      <td><?= $st['blame_trav'] ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php elseif ($onglet === 'disciplines'): ?>
<!-- ══ ONGLET 2 : STATS PAR MATIÈRE ══ -->
<?php
$classes_show = $id_classe ? array_filter($classes, fn($c)=>(int)$c['id']===$id_classe) : $classes;
foreach ($classes_show as $c):
    $disc = db_all(
        "SELECT d.id_mat, d.coef, m.libelle AS matiere,
                TRIM(CONCAT(e.nom_ens,' ',COALESCE(e.prenom_ens,''))) AS enseignant
         FROM discipline d JOIN matiere m ON m.id=d.id_mat AND m.actif=1
         LEFT JOIN dispenser disp ON disp.id_mat=d.id_mat AND disp.IDClasses=d.IDClasses AND disp.val_annee=?
         LEFT JOIN enseignant e ON e.matricule_ens=disp.matricule_ens
         WHERE d.IDClasses=? ORDER BY d.ordre, m.libelle",
        [$val_annee, $c['id']]
    );
    if (empty($disc)) continue;

    // Filtre enseignant si pas admin
    if ($is_ens && $mat_ens) {
        $ok = db_val("SELECT COUNT(*) FROM enseignat_principal WHERE matricule_ens=? AND IDClasses=? AND val_annee=?",[$mat_ens,$c['id'],$val_annee]);
        if (!$ok) {
            $disc = array_filter($disc, function($d) use ($mat_ens,$c,$val_annee){
                return db_val("SELECT COUNT(*) FROM dispenser WHERE matricule_ens=? AND IDClasses=? AND id_mat=? AND val_annee=?",[$mat_ens,$c['id'],$d['id_mat'],$val_annee]);
            });
        }
    }
    if (empty($disc)) continue;

    // Compétences (par matière) du trimestre actif — ou des trimestres de
    // l'année, fusionnés, en vue annuelle — remplacent les séquences
    // (chantier APC, voir prompt_continuite du 07/08/2026).
    $enrolled = array_column(db_all("SELECT e.id FROM eleve e JOIN inscription i ON i.id_eleve=e.id AND i.id_classe=? AND i.id_annee=? WHERE e.statut='actif'",[$c['id'],$id_annee]),'id');
    $trims_calc = $vue === 'annee'
        ? array_column(db_all("SELECT id FROM trimestre WHERE id_annee=? ORDER BY ordre", [$id_annee]), 'id')
        : ($id_trim_comp ? [$id_trim_comp] : []);
    $notes_idx_mat = []; // [id_mat][eid][] = valeur (toutes compétences de la période)
    foreach ($trims_calc as $tid) {
        $dcomp = pv_charger_donnees_comp((int)$c['id'], (int)$tid, $id_annee);
        foreach ($dcomp['competences_par_mat'] as $id_mat => $comps) {
            foreach ($comps as $comp) {
                $cid = (int)$comp['id'];
                foreach ($enrolled as $eid) {
                    if (isset($dcomp['notes_idx'][$eid][$cid])) {
                        $notes_idx_mat[$id_mat][$eid][] = $dcomp['notes_idx'][$eid][$cid];
                    }
                }
            }
        }
    }
?>
  <h6 class="fw-bold mt-3" style="color:#1a3c6b"><i class="bi bi-door-open me-1"></i><?= h($c['designation']) ?></h6>
  <div class="table-responsive mb-3">
  <table class="table tbl-stat table-hover mb-0">
    <thead><tr>
      <th>Matière</th><th>Coef.</th><th>Enseignant</th>
      <th>Nb notes</th><th>Moyenne classe</th><th>Min</th><th>Max</th><th>Admis</th><th>Taux</th>
    </tr></thead>
    <tbody>
    <?php foreach ($disc as $d):
        $avgs=[];
        foreach($enrolled as $eid){
            $vals = $notes_idx_mat[$d['id_mat']][$eid] ?? [];
            if(!empty($vals)) $avgs[]=(float)(array_sum($vals)/count($vals));
        }
        $nb_saisies = count($avgs);
        $moy_cl = !empty($avgs)?array_sum($avgs)/count($avgs):null;
        $admis  = count(array_filter($avgs,fn($a)=>$a>=10));
        $taux   = $nb_saisies>0?round($admis/$nb_saisies*100,1):0;
    ?>
    <tr>
      <td class="fw-semibold"><?= h($d['matiere']) ?></td>
      <td><?= $d['coef'] ?></td>
      <td style="font-size:.77rem"><?= h($d['enseignant']??'—') ?></td>
      <td><?= $nb_saisies ?> / <?= count($enrolled) ?></td>
      <td class="fw-bold <?= $moy_cl!==null&&$moy_cl>=10?'text-success':'text-danger' ?>"><?= $fmt($moy_cl) ?></td>
      <td><?= $fmt(!empty($avgs)?min($avgs):null) ?></td>
      <td><?= $fmt(!empty($avgs)?max($avgs):null) ?></td>
      <td><?= $admis ?></td>
      <td>
        <div class="progress" style="height:12px;min-width:60px">
          <div class="progress-bar <?= $taux>=50?'bg-success':'bg-danger' ?>" style="width:<?= $taux ?>%">
            <?= $taux ?>%
          </div>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endforeach; ?>

<?php elseif ($onglet === 'effectifs'): ?>
<!-- ══ ONGLET 3 : EFFECTIFS ══ -->
<?php
$sections_data = [];
foreach ($classes as $c) {
    $section = $c['libelle_section'] ?? 'Non définie';
    $niveau  = $c['code_niveau'] ?? '—';
    $nb_el   = (int)db_val("SELECT COUNT(*) FROM inscription i JOIN eleve e ON e.id=i.id_eleve WHERE i.id_classe=? AND i.id_annee=? AND e.statut='actif'", [$c['id'], $id_annee]);
    $nb_f    = (int)db_val("SELECT COUNT(*) FROM inscription i JOIN eleve e ON e.id=i.id_eleve WHERE i.id_classe=? AND i.id_annee=? AND e.statut='actif' AND e.sexe='F'", [$c['id'], $id_annee]);
    $sections_data[$section][] = ['classe'=>$c['designation'],'niveau'=>$niveau,'nb'=>$nb_el,'filles'=>$nb_f,'garcons'=>$nb_el-$nb_f];
}
$total_eleves = array_sum(array_column(array_merge(...array_values($sections_data)), 'nb'));
$total_filles = array_sum(array_column(array_merge(...array_values($sections_data)), 'filles'));
?>
<div class="row g-2 mb-3">
  <div class="col-4"><div class="stat-card"><div class="stat-num"><?= $total_eleves ?></div><div class="stat-lbl">Total élèves</div></div></div>
  <div class="col-4"><div class="stat-card"><div class="stat-num"><?= $total_filles ?></div><div class="stat-lbl">Filles</div></div></div>
  <div class="col-4"><div class="stat-card"><div class="stat-num"><?= $total_eleves-$total_filles ?></div><div class="stat-lbl">Garçons</div></div></div>
</div>
<?php foreach ($sections_data as $section => $rows): ?>
  <h6 class="fw-bold mt-3" style="color:#1a3c6b"><i class="bi bi-diagram-3 me-1"></i><?= h($section) ?></h6>
  <div class="table-responsive mb-3">
  <table class="table tbl-stat table-hover mb-0">
    <thead><tr><th>Classe</th><th>Niveau</th><th>Effectif</th><th>Filles</th><th>Garçons</th><th>% Filles</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $pct = $r['nb']>0?round($r['filles']/$r['nb']*100,1):0; ?>
      <tr>
        <td class="fw-semibold"><?= h($r['classe']) ?></td>
        <td><?= h($r['niveau']) ?></td>
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
    <?php $st_nb=array_sum(array_column($rows,'nb'));$st_f=array_sum(array_column($rows,'filles')); ?>
    <tr style="background:#e8f0fe;font-weight:700">
      <td colspan="2">Total section</td>
      <td><?= $st_nb ?></td>
      <td><?= $st_f ?></td>
      <td><?= $st_nb-$st_f ?></td>
      <td><?= $st_nb>0?round($st_f/$st_nb*100,1):0 ?>%</td>
    </tr>
    </tbody>
  </table>
  </div>
<?php endforeach; ?>

<?php elseif ($onglet === 'section'): ?>
<!-- ══ ONGLET : PAR SECTION ══ -->
<?php
$par_section = [];
foreach ($classes as $c) {
    $section = $c['libelle_section'] ?? 'Non définie';
    $par_section[$section][] = $c;
}
ksort($par_section);
foreach ($par_section as $section => $classes_section) {
    $lignes = [];
    foreach ($classes_section as $c) {
        $lignes[] = ['classe' => $c['designation'], 'bilan' => calc_bilan_classe_genre_comp((int)$c['id'], $id_annee, $val_annee, $vue, $id_trim_comp)];
    }
    render_tableau_bilan_genre($section, $lignes, $bilan_cols);
}
?>

<?php elseif ($onglet === 'niveau'): ?>
<!-- ══ ONGLET : PAR NIVEAU ══ -->
<?php
$niveaux_ref  = db_all("SELECT code_niveau, libelle_niv FROM niveau ORDER BY ordre_niveau");
$niveaux_map  = array_column($niveaux_ref, 'libelle_niv', 'code_niveau');
$ordre_niv    = array_column($niveaux_ref, 'libelle_niv');
$par_niveau = [];
foreach ($classes as $c) {
    $niv = $niveaux_map[$c['code_niveau']] ?? ($c['code_niveau'] ?: 'Non défini');
    $par_niveau[$niv][] = $c;
}
// Tri selon l'ordre pédagogique (niveau.ordre_niveau), pas alphabétique.
uksort($par_niveau, function ($a, $b) use ($ordre_niv) {
    $ia = array_search($a, $ordre_niv); $ib = array_search($b, $ordre_niv);
    if ($ia === false) $ia = 999;
    if ($ib === false) $ib = 999;
    return $ia <=> $ib;
});
foreach ($par_niveau as $niveau => $classes_niveau) {
    $lignes = [];
    foreach ($classes_niveau as $c) {
        $lignes[] = ['classe' => $c['designation'], 'bilan' => calc_bilan_classe_genre_comp((int)$c['id'], $id_annee, $val_annee, $vue, $id_trim_comp)];
    }
    render_tableau_bilan_genre($niveau, $lignes, $bilan_cols);
}
?>

<?php elseif ($onglet === 'matiere'): ?>
<!-- ══ ONGLET : PAR MATIÈRE (école ou classe filtrée) ══ -->
<?php
// Compétences (chantier APC) : jointure via competence.code_niveau pour ne
// prendre que les compétences du niveau de chaque classe (une classe peut
// être d'un niveau différent d'une autre parmi celles sélectionnées). Voir
// prompt_continuite du 07/08/2026, Phase 6.
$classes_show_mat = $id_classe ? array_filter($classes, fn($c) => (int)$c['id'] === $id_classe) : $classes;
$classe_ids_mat    = array_column($classes_show_mat, 'id');
$mat_stats = [];
$trims_calc_mat = $vue === 'annee'
    ? array_column(db_all("SELECT id FROM trimestre WHERE id_annee=? ORDER BY ordre", [$id_annee]), 'id')
    : ($id_trim_comp ? [$id_trim_comp] : []);
if (!empty($classe_ids_mat) && !empty($trims_calc_mat)) {
    $in_c = implode(',', array_fill(0, count($classe_ids_mat), '?'));
    $in_t = implode(',', array_fill(0, count($trims_calc_mat), '?'));
    $rows = db_all(
        "SELECT n.id_matiere, n.id_eleve, n.valeur, m.libelle AS matiere
         FROM note n
         JOIN competence comp ON comp.id = n.id_competence AND comp.id_trim IN ($in_t)
         JOIN inscription i ON i.id_eleve=n.id_eleve AND i.id_annee=? AND i.id_classe IN ($in_c)
         JOIN classe cl ON cl.id=i.id_classe AND cl.code_niveau=comp.code_niveau
         JOIN matiere m ON m.id=n.id_matiere AND m.actif=1
         JOIN discipline d ON d.id_mat=n.id_matiere AND d.IDClasses=i.id_classe",
        array_merge($trims_calc_mat, [$id_annee], $classe_ids_mat)
    );
    $par_mat = [];
    foreach ($rows as $r) {
        $par_mat[$r['id_matiere']]['libelle'] = $r['matiere'];
        $par_mat[$r['id_matiere']]['vals'][$r['id_eleve']][] = (float)$r['valeur'];
    }
    foreach ($par_mat as $id_mat => $info) {
        $avgs = [];
        foreach ($info['vals'] as $eid => $vs) { $avgs[] = array_sum($vs) / count($vs); }
        $nb = count($avgs);
        $admis = count(array_filter($avgs, fn($a) => $a >= 10));
        $mat_stats[] = [
            'matiere' => $info['libelle'], 'nb' => $nb,
            'moy' => $nb > 0 ? array_sum($avgs) / $nb : null,
            'min' => $nb > 0 ? min($avgs) : null,
            'max' => $nb > 0 ? max($avgs) : null,
            'taux' => $nb > 0 ? round($admis / $nb * 100, 1) : 0,
        ];
    }
    usort($mat_stats, fn($a, $b) => strcmp($a['matiere'], $b['matiere']));
}
?>
<div class="table-responsive">
<table class="table tbl-stat table-hover mb-0">
  <thead><tr><th>Matière</th><th>Nb notes</th><th>Moyenne</th><th>Min</th><th>Max</th><th>Taux réussite</th></tr></thead>
  <tbody>
  <?php foreach ($mat_stats as $ms): ?>
    <tr>
      <td class="fw-semibold"><?= h($ms['matiere']) ?></td>
      <td><?= $ms['nb'] ?></td>
      <td class="fw-bold <?= $ms['moy']!==null && $ms['moy']>=10 ? 'text-success' : 'text-danger' ?>"><?= $fmt($ms['moy']) ?></td>
      <td><?= $fmt($ms['min']) ?></td>
      <td><?= $fmt($ms['max']) ?></td>
      <td>
        <div class="progress" style="height:12px;min-width:60px">
          <div class="progress-bar <?= $ms['taux']>=50?'bg-success':'bg-danger' ?>" style="width:<?= $ms['taux'] ?>%"><?= $ms['taux'] ?>%</div>
        </div>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (empty($mat_stats)): ?>
    <tr><td colspan="6" class="text-center text-muted">Aucune donnée.</td></tr>
  <?php endif; ?>
  </tbody>
</table>
</div>

<?php elseif ($onglet === 'enseignants' && $is_admin): ?>
<!-- ══ ONGLET 4 : ENSEIGNANTS ══ -->
<?php
$ens_list = db_all(
    "SELECT e.matricule_ens, e.nom_ens, e.prenom_ens, e.civilite_ens, e.id_grade,
            COUNT(DISTINCT d.IDClasses) AS nb_classes,
            COUNT(DISTINCT d.id_mat) AS nb_matieres,
            ep.IDClasses AS classe_pp
     FROM enseignant e
     LEFT JOIN dispenser d ON d.matricule_ens=e.matricule_ens AND d.val_annee=?
     LEFT JOIN enseignat_principal ep ON ep.matricule_ens=e.matricule_ens AND ep.val_annee=?
     GROUP BY e.matricule_ens ORDER BY e.nom_ens",
    [$val_annee, $val_annee]
);
?>
<div class="row g-2 mb-3">
  <div class="col-4"><div class="stat-card"><div class="stat-num"><?= count($ens_list) ?></div><div class="stat-lbl">Enseignants</div></div></div>
  <div class="col-4"><div class="stat-card"><div class="stat-num"><?= count(array_filter($ens_list,fn($e)=>$e['classe_pp'])) ?></div><div class="stat-lbl">Profs principaux</div></div></div>
  <div class="col-4"><div class="stat-card"><div class="stat-num"><?= count($classes) ?></div><div class="stat-lbl">Classes actives</div></div></div>
</div>
<div class="table-responsive">
<table class="table tbl-stat table-hover mb-0">
  <thead><tr><th>Enseignant</th><th>Grade</th><th>Classes</th><th>Matières</th><th>Prof. Principal</th></tr></thead>
  <tbody>
  <?php foreach ($ens_list as $e): ?>
  <tr>
    <td class="fw-semibold">
      <a href="<?= APP_URL ?>/secondaire/pages/enseignants/fiche.php?id=<?= urlencode($e['matricule_ens']) ?>"
         class="text-decoration-none" style="color:#1a3c6b">
        <?= h(($e['civilite_ens']??'').' '.strtoupper($e['nom_ens']).' '.($e['prenom_ens']??'')) ?>
      </a>
    </td>
    <td><?= h($e['id_grade']??'—') ?></td>
    <td><?= $e['nb_classes'] ?></td>
    <td><?= $e['nb_matieres'] ?></td>
    <td>
      <?php if ($e['classe_pp']): ?>
        <?php $cl=db_one("SELECT designation FROM classe WHERE id=?",[$e['classe_pp']]); ?>
        <span class="badge" style="background:#fef3c7;color:#92400e"><?= h($cl['designation']??'') ?></span>
      <?php else: echo '—'; endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

</div><!-- card-body -->
</div><!-- card -->

<script>
function appliquerSigExcelStats() {
    const lien = document.getElementById('lienExcelStats');
    if (!lien) return;
    const sig = document.getElementById('chkSigExcelStats')?.checked;
    lien.href = lien.dataset.base + (sig ? '&signature=1' : '');
}
</script>

<?php require_once __DIR__ . '/../../../layout/footer.php'; ?>
