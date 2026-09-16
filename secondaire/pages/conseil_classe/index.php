<?php
/**
 * secondaire/pages/conseil_classe/index.php — Conseil de Classe (PV trimestriel + annuel)
 * Adapté du modèle fourni par l'utilisateur, sur le schéma réel d'ABZ_MBE.
 * - Type "trimestre" : mentions/appréciations (Tableau d'honneur, Encouragements,
 *   Félicitations, Avertissement/Blâme travail), calculées sur les séquences du
 *   trimestre choisi, avec les heures d'absence de ce trimestre (table `absence`).
 * - Type "annee" : décision de passage (Admis/Redoublement/Exclu/Abandon) + classe
 *   suivante, calculée sur toutes les séquences de l'année, heures d'absence cumulées.
 * Réutilise pv_moy_generale()/pv_moy_matiere() de fonctions.php (même pondération
 * que les bulletins) et la table `absence` déjà alimentée par le module Discipline.
 */
header('Cache-Control: no-store, no-cache, must-revalidate');
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_connexion();

$role = role_connecte();

// ── Accès : ADMIN/CENSEUR/PROVISEUR (toutes classes), SG (sa classe),
//    ENSEIGNANT principal (sa classe) — même politique que le module Discipline. ──
$full_access = in_array($role, ['ADMIN', 'CENSEUR', 'PROVISEUR']);
$annee_act   = get_annee_active();
$id_annee    = (int)($annee_act['id'] ?? 0);
$val_annee   = $annee_act['libelle'] ?? '';

$classes_access = [];
if ($full_access) {
    $classes_access = db_all(
        "SELECT c.*, n.libelle_niv AS niveau_lib, s.libelle AS serie_lib
         FROM classe c LEFT JOIN niveau n ON n.code_niveau=c.code_niveau LEFT JOIN serie s ON s.id=c.id_serie
         WHERE c.archivee=0 ORDER BY c.ordre, c.designation"
    );
} elseif ($role === 'SG') {
    $mat_ens = get_matricule_ens_connecte();
    $classes_access = db_all(
        "SELECT c.*, n.libelle_niv AS niveau_lib, s.libelle AS serie_lib FROM sg
         JOIN classe c ON c.id = sg.IDClasses
         LEFT JOIN niveau n ON n.code_niveau=c.code_niveau LEFT JOIN serie s ON s.id=c.id_serie
         WHERE sg.matricule_ens=? AND sg.val_annee=? AND c.archivee=0
         ORDER BY c.ordre, c.designation",
        [$mat_ens, $val_annee]
    );
} elseif ($role === 'ENSEIGNANT') {
    $mat_ens = get_matricule_ens_connecte();
    $classes_access = db_all(
        "SELECT c.*, n.libelle_niv AS niveau_lib, s.libelle AS serie_lib FROM enseignat_principal ep
         JOIN classe c ON c.id = ep.IDClasses
         LEFT JOIN niveau n ON n.code_niveau=c.code_niveau LEFT JOIN serie s ON s.id=c.id_serie
         WHERE ep.matricule_ens=? AND ep.val_annee=? AND c.archivee=0",
        [$mat_ens, $val_annee]
    );
}
if (empty($classes_access)) {
    flash_set('erreur', 'Accès non autorisé au Conseil de Classe.');
    rediriger('dashboard.php');
}
$ids_classes_ok = array_column($classes_access, 'id');
$toutes_classes = db_all("SELECT * FROM classe WHERE archivee=0 ORDER BY ordre, designation");

$trimestres = db_all("SELECT * FROM trimestre WHERE id_annee=? ORDER BY ordre", [$id_annee]);

// ── Paramètres ────────────────────────────────────────────────────
$type      = in_array($_POST['pv_type'] ?? $_GET['type'] ?? '', ['trimestre','annee'], true)
           ? ($_POST['pv_type'] ?? $_GET['type']) : 'trimestre';
$id_classe = (int)($_POST['pv_classe'] ?? $_GET['classe'] ?? 0);
$id_trim   = (int)($_POST['pv_trim']   ?? $_GET['trim']   ?? ($trimestres[0]['id'] ?? 0));
$next_glob = (int)($_POST['pv_next_global'] ?? 0);

if ($id_classe && !in_array($id_classe, $ids_classes_ok, true)) {
    flash_set('erreur', "Vous n'avez pas accès à cette classe.");
    $id_classe = 0;
}
$id_trim_save = $type === 'annee' ? 0 : $id_trim; // sentinelle 0 = décision annuelle

$eleves = []; $classe_info = null; $errors = []; $success_msg = '';

// ═══════════════════════════════════════════════════════════════════
// SAUVEGARDE DES DÉCISIONS
// ═══════════════════════════════════════════════════════════════════
if (isset($_POST['pv_decisions']) && $id_classe) {
    csrf_verifier();
    $saved = 0; $skipped = 0;
    foreach ($_POST['pv_decisions'] as $id_eleve_raw => $data) {
        $id_eleve = (int)$id_eleve_raw;
        $decision = trim($data['decision'] ?? '');
        $obs      = trim($data['observation'] ?? '');
        if ($decision === '') { $skipped++; continue; }

        $next_indiv = (int)($data['next_classe'] ?? 0);
        $next_val   = null;
        if ($type === 'annee' && $decision === 'Admis') {
            $next_val = $next_indiv > 0 ? $next_indiv : ($next_glob > 0 ? $next_glob : $id_classe);
        }

        db_exec(
            "INSERT INTO decision_conseil (id_eleve,id_classe,id_annee,type,id_trim,decision,next_classe,observation)
             VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE decision=VALUES(decision), next_classe=VALUES(next_classe), observation=VALUES(observation)",
            [$id_eleve, $id_classe, $id_annee, $type, $id_trim_save, $decision, $next_val, $obs ?: null]
        );
        $saved++;
    }
    $success_msg = "$saved décision(s) enregistrée(s).";
    if ($skipped > 0) $success_msg .= " $skipped ligne(s) sans décision ignorée(s).";
}

// ═══════════════════════════════════════════════════════════════════
// MÉMORISATION DES SEUILS (Décisions automatiques) POUR CETTE CLASSE
// ═══════════════════════════════════════════════════════════════════
if (isset($_POST['pv_save_seuils']) && $id_classe) {
    csrf_verifier();
    $num = fn($k) => ($_POST[$k] ?? '') !== '' ? (float)$_POST[$k] : null;
    $int = fn($k) => ($_POST[$k] ?? '') !== '' ? (int)$_POST[$k]   : null;
    db_exec(
        "INSERT INTO critere_conseil
            (id_classe, id_annee, moyenne_admission, moyenne_exclusion, heures_absence_max, jours_exclusion_max,
             seuil_tableau_honneur, seuil_encouragement, seuil_felicitation)
         VALUES (?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
            moyenne_admission=VALUES(moyenne_admission), moyenne_exclusion=VALUES(moyenne_exclusion),
            heures_absence_max=VALUES(heures_absence_max), jours_exclusion_max=VALUES(jours_exclusion_max),
            seuil_tableau_honneur=VALUES(seuil_tableau_honneur), seuil_encouragement=VALUES(seuil_encouragement),
            seuil_felicitation=VALUES(seuil_felicitation)",
        [
            $id_classe, $id_annee,
            $num('seuil_moy_adm'), $num('seuil_moy_excl'), $int('seuil_abs_excl'), $int('seuil_excl_jours'),
            $num('seuil_th'), $num('seuil_enc'), $num('seuil_fel'),
        ]
    );
    $success_msg = 'Seuils mémorisés pour cette classe.';
}

// ═══════════════════════════════════════════════════════════════════
// CHARGEMENT DES DONNÉES DE LA CLASSE
// ═══════════════════════════════════════════════════════════════════
if ($id_classe) {
    $classe_info = db_one(
        "SELECT c.*, n.libelle_niv AS niveau_lib, s.libelle AS serie_lib FROM classe c
         LEFT JOIN niveau n ON n.code_niveau=c.code_niveau LEFT JOIN serie s ON s.id=c.id_serie WHERE c.id=?",
        [$id_classe]
    );

    // Seuils mémorisés pour cette classe/année (préchargés dans le panneau "Décisions automatiques")
    $criteres = db_one("SELECT * FROM critere_conseil WHERE id_classe=? AND id_annee=?", [$id_classe, $id_annee]) ?: [];

    // Trimestre(s) pris en compte selon le type de conseil — les compétences
    // sont scopées par trimestre entier (chantier APC), pas de notion de
    // séquence en dessous. "annee" moyenne les moyennes des trimestres
    // existants (même méthode que secondaire/pages/bulletins/index.php et pdf_annuel.php).
    // Voir prompt_continuite, mise à jour du 07/08/2026 (Phase 6).
    $trims_pour_calc = $type === 'trimestre' ? [$id_trim] : array_column($trimestres, 'id');

    // Disciplines (matière + coefficient) de la classe
    $disciplines = db_all(
        "SELECT d.id_mat, d.coef, m.libelle AS matiere FROM discipline d
         JOIN matiere m ON m.id=d.id_mat AND m.actif=1 WHERE d.IDClasses=? ORDER BY d.ordre, m.libelle",
        [$id_classe]
    );

    // Élèves inscrits (actifs) dans la classe
    $eleves_raw = db_all(
        "SELECT e.id, e.matricule, e.niu, e.nom, e.prenom, e.sexe, e.date_naiss, e.lieu_naiss, i.statut
         FROM eleve e JOIN inscription i ON i.id_eleve=e.id AND i.id_classe=? AND i.id_annee=?
         WHERE e.statut='actif' ORDER BY e.nom, e.prenom",
        [$id_classe, $id_annee]
    );
    $enrolled_ids = array_column($eleves_raw, 'id');
    $nb_inscrits  = count($enrolled_ids);

    // Moyenne par élève, pour chaque trimestre concerné (1 pour "trimestre",
    // jusqu'à 3 pour "annee"), via les compétences (pv_*_comp de fonctions.php,
    // même algorithme que bulletins/pdf.php).
    $moys_par_trim = [];
    foreach ($trims_pour_calc as $tid) {
        if (!$tid || $nb_inscrits === 0) continue;
        $dcomp = pv_charger_donnees_comp($id_classe, (int)$tid, $id_annee);
        $mt = [];
        foreach ($enrolled_ids as $eid) {
            [$m, $classable] = pv_moy_generale_comp(
                $eid, $dcomp['disciplines'], $dcomp['competences_par_mat'], $dcomp['notes_idx'], $dcomp['notes_count'],
                $dcomp['nb_inscrits'], $dcomp['mats_avec_notes'], $dcomp['nb_mats_avec_notes']
            );
            $mt[$eid] = ($classable && $m !== null) ? $m : null;
        }
        $moys_par_trim[] = $mt;
    }
    $calc_moy_eid = function(int $eid) use ($moys_par_trim): ?float {
        $vals = [];
        foreach ($moys_par_trim as $mt) { if (($mt[$eid] ?? null) !== null) $vals[] = $mt[$eid]; }
        return !empty($vals) ? array_sum($vals) / count($vals) : null;
    };

    // Heures d'absence (table `absence`, alimentée par le module Discipline)
    $abs_heures = []; // [matricule] = ['jus'=>x,'nj'=>y]
    try {
        if ($type === 'trimestre') {
            $rows = db_all("SELECT mat_elv, nbre_heure_jus, nbre_heure_non_jus FROM absence WHERE id_trim=? AND IDClasses=? AND val_annee=?", [$id_trim, $id_classe, $val_annee]);
            foreach ($rows as $r) $abs_heures[$r['mat_elv']] = ['jus'=>(int)$r['nbre_heure_jus'], 'nj'=>(int)$r['nbre_heure_non_jus']];
        } else {
            $rows = db_all("SELECT mat_elv, SUM(nbre_heure_jus) AS jus, SUM(nbre_heure_non_jus) AS nj FROM absence WHERE IDClasses=? AND val_annee=? GROUP BY mat_elv", [$id_classe, $val_annee]);
            foreach ($rows as $r) $abs_heures[$r['mat_elv']] = ['jus'=>(int)$r['jus'], 'nj'=>(int)$r['nj']];
        }
    } catch (Throwable $ex) { $abs_heures = []; }

    // Jours d'exclusion disciplinaire (table `exclusion`, alimentée par le module Discipline)
    $excl_jours = []; // [matricule] = nbre_jours (trimestre ou cumul année)
    try {
        if ($type === 'trimestre') {
            $rows = db_all("SELECT mat_elv, SUM(nbre_jours) AS j FROM exclusion WHERE id_trim=? AND classe=? AND val_annee=? GROUP BY mat_elv", [$id_trim, $id_classe, $val_annee]);
        } else {
            $rows = db_all("SELECT mat_elv, SUM(nbre_jours) AS j FROM exclusion WHERE classe=? AND val_annee=? GROUP BY mat_elv", [$id_classe, $val_annee]);
        }
        foreach ($rows as $r) $excl_jours[$r['mat_elv']] = (int)$r['j'];
    } catch (Throwable $ex) { $excl_jours = []; }

    // Décisions déjà enregistrées pour cette période
    $decisions_idx = [];
    $rows = db_all("SELECT * FROM decision_conseil WHERE id_classe=? AND id_annee=? AND type=? AND id_trim=?", [$id_classe, $id_annee, $type, $id_trim_save]);
    foreach ($rows as $r) $decisions_idx[$r['id_eleve']] = $r;

    // Moyennes + classement
    $all_moys = [];
    foreach ($enrolled_ids as $eid) {
        $m = $calc_moy_eid($eid);
        if ($m !== null) $all_moys[$eid] = $m;
    }
    arsort($all_moys);
    $rangs = []; $rg = 1;
    foreach ($all_moys as $eid => $m) { $rangs[$eid] = $rg++; }

    foreach ($eleves_raw as &$e) {
        $eid = $e['id'];
        $moy = $calc_moy_eid($eid);
        $e['moy']    = $moy;
        $e['rang']   = $rangs[$eid] ?? '—';
        $e['abs_jus'] = $abs_heures[$e['matricule']]['jus'] ?? 0;
        $e['abs_nj']  = $abs_heures[$e['matricule']]['nj']  ?? 0;
        $e['excl_j']  = $excl_jours[$e['matricule']] ?? 0;
        $e['deja']    = $decisions_idx[$eid] ?? null;
    }
    unset($e);
    $eleves = $eleves_raw;
}

function pv_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function pv_fmt_moy(?float $v): string {
    if ($v === null) return '<span class="pv-na">—</span>';
    $c = $v >= 10 ? 'good' : ($v >= 8 ? 'mid' : 'bad');
    return '<span class="pv-moy ' . $c . '">' . number_format($v, 2) . '</span>';
}
function pv_opt_classes(array $classes, int $sel, string $def = '— Choisir —'): string {
    $h = '<option value="">' . pv_h($def) . '</option>';
    foreach ($classes as $c) {
        $s = $c['id'] == $sel ? ' selected' : '';
        $h .= '<option value="' . $c['id'] . '"' . $s . '>' . pv_h($c['designation']) . '</option>';
    }
    return $h;
}
$options_decision = $type === 'annee'
    ? ['Admis' => '✅ Admis', 'Redoublement' => '🔄 Redoublement', 'Exclu' => '❌ Exclu', 'Abandon' => '🚪 Abandon']
    : ['Félicitations' => '🏆 Félicitations', 'Encouragements' => '⭐ Encouragements', "Tableau d'honneur" => "📋 Tableau d'honneur",
       'Avertissement (travail)' => '⚠️ Avertissement', 'Blâme (travail)' => '🚫 Blâme', 'RAS' => 'RAS'];

$titre_page = 'Conseil de Classe';
require_once __DIR__ . '/../../../layout/header.php';
?>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<style>
.pv-wrap{font-family:var(--font-body);color:var(--text)}
.pv-topbar{background:var(--primary);color:#fff;border-radius:var(--radius) var(--radius) 0 0;
  display:flex;align-items:center;gap:12px;padding:10px 18px;border-bottom:3px solid var(--gold);margin-bottom:12px}
.pv-topbar-icon{width:34px;height:34px;background:var(--gold);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;color:var(--primary);flex-shrink:0}
.pv-topbar h2{font-family:var(--font-heading);font-size:1rem;font-weight:700;margin:0}
.pv-topbar p{font-size:.68rem;color:var(--gold-light);margin:1px 0 0}
.pv-topbar-badge{margin-left:auto;background:var(--gold);color:var(--primary);font-weight:700;font-size:.72rem;padding:3px 11px;border-radius:12px;white-space:nowrap}

.pv-type-tabs{display:flex;gap:6px;margin-bottom:11px}
.pv-type-tab{padding:7px 18px;border-radius:var(--radius-sm);border:1.5px solid var(--border);
  font-size:.8rem;font-weight:600;cursor:pointer;background:var(--surface);color:var(--text);transition:all .15s}
.pv-type-tab.on{background:var(--primary);color:#fff;border-color:var(--primary)}

.pv-sel{background:var(--surface);border:1px solid var(--border);border-left:4px solid var(--gold);
  border-radius:var(--radius);padding:11px 14px;margin-bottom:11px;display:flex;flex-wrap:wrap;gap:11px;align-items:flex-end}
.pv-sg{display:flex;flex-direction:column;gap:3px}
.pv-sg label{font-size:.66rem;font-weight:700;color:var(--primary);text-transform:uppercase;letter-spacing:.06em}
.pv-hint{font-weight:400;color:var(--muted);text-transform:none;letter-spacing:0}

.pv-rules{background:var(--surface);border:1px solid var(--purple);border-left:4px solid var(--purple);
  border-radius:var(--radius);padding:0;margin-bottom:11px;overflow:hidden}
.pv-rules summary{font-size:.73rem;font-weight:700;color:var(--purple);text-transform:uppercase;letter-spacing:.06em;
  cursor:pointer;list-style:none;display:flex;align-items:center;gap:7px;padding:9px 13px;background:var(--purple-bg)}
.pv-rules summary::-webkit-details-marker{display:none}
.pv-rules-body{padding:12px 14px}
.pv-rf{display:flex;flex-direction:column;gap:3px;min-width:160px}
.pv-rf label{font-size:.65rem;font-weight:700;color:var(--text);text-transform:uppercase;letter-spacing:.05em}
.pv-rf .sub{font-size:.6rem;color:var(--muted);font-weight:400;text-transform:none}
.pv-rf input{border:1.5px solid var(--border);border-radius:var(--radius-sm);padding:5px 9px;font-size:.85rem;
  font-family:var(--font-mono);width:120px;font-weight:700}
.pv-rule-tag{font-size:.55rem;font-weight:700;padding:1px 5px;border-radius:8px;display:inline-block;margin-left:3px}
.pv-rule-tag.a-ok{background:var(--ok-bg);color:var(--ok)} .pv-rule-tag.a-warn{background:var(--warn-bg);color:var(--warn)} .pv-rule-tag.a-bad{background:var(--danger-bg);color:var(--danger)}

.pv-banner{background:linear-gradient(135deg,var(--primary),var(--primary-2));color:#fff;border-radius:var(--radius);
  padding:9px 16px;margin-bottom:10px;display:flex;flex-wrap:wrap;gap:16px;align-items:center;box-shadow:var(--card-shadow-lg)}
.pv-ib{display:flex;flex-direction:column;gap:1px}
.pv-ib-l{font-size:.61rem;color:var(--gold-light);text-transform:uppercase;letter-spacing:.08em}
.pv-ib-v{font-size:.86rem;font-weight:700}
.pv-stats{margin-left:auto;display:flex;gap:8px;flex-wrap:wrap}
.pv-pill{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);border-radius:15px;padding:3px 10px;font-size:.7rem}
.pv-pill strong{color:var(--gold-light)}

.pv-twrap{background:var(--surface);border-radius:var(--radius);overflow-x:auto;border:1px solid var(--border)}
.pv-wrap table{width:100%;border-collapse:collapse;font-size:.72rem}
.pv-wrap thead th{background:var(--primary);color:#fff;padding:6px 6px;text-align:center;font-size:.66rem;font-weight:700;white-space:nowrap}
.pv-wrap tbody tr:nth-child(even){background:var(--hover-bg)}
.pv-wrap tbody tr:hover{background:#eeedf6}
.pv-wrap td{padding:4px 6px;text-align:center;vertical-align:middle;border-bottom:1px solid var(--border)}
.pv-tdid{min-width:150px;text-align:left!important}
.pv-nom{font-weight:700;color:var(--primary);font-size:.74rem}
.pv-moy{font-family:var(--font-mono);font-weight:700}
.pv-moy.good{color:var(--ok)} .pv-moy.mid{color:var(--warn)} .pv-moy.bad{color:var(--danger)}
.pv-na{color:var(--muted-2)}
.pv-dsel{border:1.5px solid var(--border);border-radius:4px;padding:3px 4px;font-size:.68rem;width:100%;min-width:140px}
.pv-dsel.ok{border-color:var(--ok);background:var(--ok-bg);color:var(--ok)}
.pv-dsel.warn{border-color:var(--warn);background:var(--warn-bg);color:var(--warn)}
.pv-dsel.bad{border-color:var(--danger);background:var(--danger-bg);color:var(--danger)}
.pv-nxi{border:1.5px solid var(--border);border-radius:4px;padding:3px 4px;font-size:.66rem;width:100%}
.pv-obs{border:1.5px solid var(--border);border-radius:4px;padding:3px 5px;font-size:.66rem;width:120px}
.pv-tfooter{display:flex;justify-content:space-between;align-items:center;padding:8px 12px;background:var(--hover-bg);
  border-top:1px solid var(--border);border-radius:0 0 var(--radius) var(--radius);flex-wrap:wrap;gap:7px}
.pv-empty{text-align:center;padding:44px 20px;color:var(--muted)}
.pv-phead{display:none}
@media print{
  .pv-sel,.pv-rules,.pv-type-tabs,.pv-tfooter,.pv-nop{display:none!important}
  .pv-phead{display:block;text-align:center;margin-bottom:10px}
  .pv-wrap table{font-size:6.5pt}
}
</style>

<div class="pv-wrap" id="pvWrap">
  <div class="pv-topbar">
    <div class="pv-topbar-icon"><i class="bi bi-mortarboard-fill"></i></div>
    <div>
      <h2>Conseil de Classe</h2>
      <p>Délibérations et décisions<?= $val_annee ? ' · ' . pv_h($val_annee) : '' ?></p>
    </div>
    <?php if ($classe_info): ?><div class="pv-topbar-badge"><?= pv_h($classe_info['designation']) ?></div><?php endif; ?>
  </div>

  <?= flash_html() ?>
  <?php if ($success_msg): ?><div class="alert alert-success py-2" style="font-size:.82rem">✅ <?= pv_h($success_msg) ?></div><?php endif; ?>

  <div class="pv-type-tabs">
    <a href="?type=trimestre<?= $id_classe ? '&classe='.$id_classe : '' ?>" class="pv-type-tab<?= $type==='trimestre'?' on':'' ?>" style="text-decoration:none">📘 Conseil trimestriel</a>
    <a href="?type=annee<?= $id_classe ? '&classe='.$id_classe : '' ?>" class="pv-type-tab<?= $type==='annee'?' on':'' ?>" style="text-decoration:none">🎓 Conseil de fin d'année</a>
  </div>

  <form method="get" class="pv-sel" id="pvFrmSel">
    <input type="hidden" name="type" value="<?= $type ?>">
    <div class="pv-sg">
      <label>Classe</label>
      <select name="classe" class="form-select form-select-sm" onchange="this.form.submit()" style="min-width:200px">
        <option value="">— Sélectionner —</option>
        <?php foreach ($classes_access as $cl): ?>
          <option value="<?= $cl['id'] ?>" <?= $cl['id']==$id_classe?'selected':'' ?>>
            <?= pv_h($cl['designation']) ?><?= $cl['niveau_lib']?' ('.pv_h($cl['niveau_lib']).')':'' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if ($type === 'trimestre'): ?>
    <div class="pv-sg">
      <label>Trimestre</label>
      <select name="trim" class="form-select form-select-sm" onchange="this.form.submit()">
        <?php foreach ($trimestres as $t): ?>
          <option value="<?= $t['id'] ?>" <?= $t['id']==$id_trim?'selected':'' ?>><?= pv_h($t['libelle']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php else: ?>
    <div class="pv-sg">
      <label>Destination collective <span class="pv-hint">(admis sans choix individuel)</span></label>
      <select id="pvNxtGlob" class="form-select form-select-sm" style="min-width:220px">
        <?= pv_opt_classes($toutes_classes, $next_glob, '— Aucune (individuel) —') ?>
      </select>
    </div>
    <?php endif; ?>
    <?php if ($id_classe && $eleves): ?>
    <div class="pv-sg">
      <label>Ordre</label>
      <div class="d-flex gap-1">
        <button type="button" class="btn btn-sm btn-abz-primary" id="pvBtnAlpha" onclick="pvSetTri('alpha')">🔤 Alpha</button>
        <button type="button" class="btn btn-sm btn-abz-outline" id="pvBtnMerite" onclick="pvSetTri('merite')">🏅 Mérite</button>
      </div>
    </div>
    <div class="pv-sg">
      <label>&nbsp;</label>
      <div class="d-flex gap-1">
        <button type="button" class="btn btn-sm btn-abz-outline pv-nop" onclick="window.print()"><i class="bi bi-printer"></i> Imprimer</button>
        <button type="button" class="btn btn-sm btn-abz-gold pv-nop" onclick="pvExcel()"><i class="bi bi-file-earmark-excel"></i> Excel</button>
        <button type="button" class="btn btn-sm btn-abz-primary pv-nop"
                onclick="afficherApercu('pdf.php?classe=<?= $id_classe ?>&type=<?= $type ?>&trim=<?= $id_trim ?>&ordre=' + pvTriMode, 'Procès-verbal du Conseil de Classe', 'pv_conseil', 'landscape')">
          <i class="bi bi-file-earmark-pdf"></i> PV (PDF)
        </button>
        <?php if ($type === 'annee'): ?>
        <button type="button" class="btn btn-sm btn-abz-outline pv-nop"
                onclick="afficherApercu('pdf_releve_annuel.php?classe=<?= $id_classe ?>&annee=<?= $id_annee ?>&ordre=' + pvTriMode, 'Relevé de notes annuel', 'releve_notes_annuel', 'landscape')">
          <i class="bi bi-file-earmark-bar-graph"></i> Relevé de notes (PDF)
        </button>
        <?php else: ?>
        <button type="button" class="btn btn-sm btn-abz-outline pv-nop"
                onclick="afficherApercu('pdf_releve.php?classe=<?= $id_classe ?>&trim=<?= $id_trim ?>&annee=<?= $id_annee ?>&ordre=' + pvTriMode, 'Relevé de notes', 'releve_notes', 'landscape')">
          <i class="bi bi-file-earmark-bar-graph"></i> Relevé de notes (PDF)
        </button>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </form>

  <?php if ($id_classe && $eleves): ?>
  <details class="pv-rules" id="pvRulesPanel">
    <summary>⚡ Décisions automatiques — saisir les critères puis Appliquer</summary>
    <div class="pv-rules-body">
      <div class="d-flex flex-wrap gap-4 align-items-end mb-2">
        <?php if ($type === 'annee'): ?>
        <div class="pv-rf">
          <label>Moyenne d'admission<span class="sub">≥ → Admis, sinon Redoublement</span></label>
          <input type="number" id="pvRuleMoyAdm" min="0" max="20" step="0.01" placeholder="ex: 10.00" value="<?= h($criteres['moyenne_admission'] ?? '') ?>">
        </div>
        <div class="pv-rf">
          <label>Moyenne d'exclusion<span class="sub">≤ → Exclu (optionnel)</span></label>
          <input type="number" id="pvRuleMoyExcl" min="0" max="20" step="0.01" placeholder="optionnel" value="<?= h($criteres['moyenne_exclusion'] ?? '') ?>">
        </div>
        <div class="pv-rf">
          <label>Heures d'absence non just. max<span class="sub">≥ → Exclu (optionnel)</span></label>
          <input type="number" id="pvRuleAbsExcl" min="0" step="1" placeholder="optionnel" value="<?= h($criteres['heures_absence_max'] ?? '') ?>">
        </div>
        <div class="pv-rf">
          <label>Jours d'exclusion max<span class="sub">≥ → Exclu (optionnel, prioritaire)</span></label>
          <input type="number" id="pvRuleExclJours" min="0" step="1" placeholder="optionnel" value="<?= h($criteres['jours_exclusion_max'] ?? '') ?>">
        </div>
        <?php else: ?>
        <div class="pv-rf">
          <label>Seuil Tableau d'honneur<span class="sub">moy ≥ et abs.nj ≤ 8h</span></label>
          <input type="number" id="pvRuleTH" min="0" max="20" step="0.01" value="<?= h($criteres['seuil_tableau_honneur'] ?? '12') ?>" placeholder="12">
        </div>
        <div class="pv-rf">
          <label>Seuil Encouragements<span class="sub">moy ≥</span></label>
          <input type="number" id="pvRuleEnc" min="0" max="20" step="0.01" value="<?= h($criteres['seuil_encouragement'] ?? '14') ?>" placeholder="14">
        </div>
        <div class="pv-rf">
          <label>Seuil Félicitations<span class="sub">moy ≥</span></label>
          <input type="number" id="pvRuleFel" min="0" max="20" step="0.01" value="<?= h($criteres['seuil_felicitation'] ?? '15') ?>" placeholder="15">
        </div>
        <?php endif; ?>
        <button type="button" class="btn btn-sm btn-abz-primary" onclick="pvApplyRules()">⚡ Appliquer</button>
        <button type="button" class="btn btn-sm btn-abz-outline" onclick="pvClearRules()">✖ Effacer auto</button>
        <button type="button" class="btn btn-sm btn-abz-outline" onclick="pvMemoriserSeuils()">💾 Mémoriser ces seuils pour cette classe</button>
        <span class="text-muted" id="pvRulesInfo" style="font-size:.68rem;font-style:italic">N'écrase pas les décisions déjà saisies manuellement.</span>
      </div>
    </div>
  </details>
  <form method="post" id="pvFrmSeuils" style="display:none">
    <?= csrf_champ() ?>
    <input type="hidden" name="pv_type" value="<?= $type ?>">
    <input type="hidden" name="pv_classe" value="<?= $id_classe ?>">
    <input type="hidden" name="pv_trim" value="<?= $id_trim ?>">
    <input type="hidden" name="pv_save_seuils" value="1">
    <input type="hidden" name="seuil_moy_adm"    id="fsMoyAdm">
    <input type="hidden" name="seuil_moy_excl"   id="fsMoyExcl">
    <input type="hidden" name="seuil_abs_excl"   id="fsAbsExcl">
    <input type="hidden" name="seuil_excl_jours" id="fsExclJours">
    <input type="hidden" name="seuil_th"  id="fsTH">
    <input type="hidden" name="seuil_enc" id="fsEnc">
    <input type="hidden" name="seuil_fel" id="fsFel">
  </form>
  <?php endif; ?>

  <?php if ($classe_info): ?>
    <?php
      $total = count($eleves);
      $moys_ok = array_filter(array_column($eleves,'moy'), fn($m)=>$m!==null);
      $moy_classe = count($moys_ok) ? array_sum($moys_ok)/count($moys_ok) : 0;
    ?>
    <div class="pv-phead" id="pvPhead"></div>
    <div class="pv-banner">
      <div class="pv-ib"><span class="pv-ib-l">Classe</span><span class="pv-ib-v"><?= pv_h($classe_info['designation']) ?></span></div>
      <div class="pv-ib"><span class="pv-ib-l">Niveau</span><span class="pv-ib-v"><?= pv_h($classe_info['niveau_lib'] ?? '—') ?></span></div>
      <div class="pv-ib"><span class="pv-ib-l">Période</span><span class="pv-ib-v"><?= $type==='annee' ? "Année complète" : pv_h($trimestres[array_search($id_trim, array_column($trimestres,'id'))]['libelle'] ?? '') ?></span></div>
      <div class="pv-stats">
        <div class="pv-pill">👥 Effectif <strong><?= $total ?></strong></div>
        <div class="pv-pill">📊 Moy. classe <strong><?= number_format($moy_classe,2) ?></strong></div>
      </div>
    </div>

    <form method="post" id="pvFrmDec">
      <?= csrf_champ() ?>
      <input type="hidden" name="pv_type" value="<?= $type ?>">
      <input type="hidden" name="pv_classe" value="<?= $id_classe ?>">
      <input type="hidden" name="pv_trim" value="<?= $id_trim ?>">
      <input type="hidden" name="pv_next_global" id="pvNextGlobalHidden" value="<?= $next_glob ?>">

      <div class="pv-twrap">
        <table id="pvTable">
          <thead><tr>
            <th style="width:24px">N°</th><th class="pv-tdid">Élève</th>
            <th>Moy.</th><th>Rang</th><th>Abs. Jus.</th><th>Abs. N.J.</th><th>Excl.</th>
            <th style="min-width:150px">Décision</th>
            <?php if ($type==='annee'): ?><th style="min-width:150px">Destination</th><?php endif; ?>
            <th style="min-width:110px">Observation</th>
          </tr></thead>
          <tbody id="pvTbody">
          <?php if (empty($eleves)): ?>
            <tr><td colspan="10"><div class="pv-empty">📭 Aucun élève inscrit.</div></td></tr>
          <?php else: foreach ($eleves as $n => $e):
            $deja = $e['deja']; $decVal = $deja['decision'] ?? '';
            $nextSaved = (int)($deja['next_classe'] ?? 0);
            $isAdm = $type==='annee' && $decVal==='Admis';
          ?>
          <tr data-nom="<?= pv_h(strtolower($e['nom'].' '.$e['prenom'])) ?>" data-moy="<?= (float)($e['moy']??0) ?>"
              data-absnj="<?= (int)$e['abs_nj'] ?>" data-excl="<?= (int)$e['excl_j'] ?>" data-eid="<?= $e['id'] ?>">
            <td><?= $n+1 ?></td>
            <td class="pv-tdid">
              <div class="pv-nom"><?= pv_h($e['nom'].' '.$e['prenom']) ?></div>
              <div style="font-size:.6rem;color:var(--muted)"><?= pv_h(id_affichage_eleve($e)) ?></div>
            </td>
            <td><?= pv_fmt_moy($e['moy']) ?></td>
            <td><span class="pv-moy"><?= $e['rang'] ?></span></td>
            <td><?= (int)$e['abs_jus'] ?>h</td>
            <td style="<?= $e['abs_nj']>10?'color:var(--danger);font-weight:700':'' ?>"><?= (int)$e['abs_nj'] ?>h</td>
            <td style="<?= $e['excl_j']>0?'color:var(--danger);font-weight:700':'' ?>"><?= (int)$e['excl_j'] ?>j</td>
            <td>
              <select name="pv_decisions[<?= $e['id'] ?>][decision]" class="pv-dsel" id="pvDsel-<?= $e['id'] ?>"
                      data-eid="<?= $e['id'] ?>" onchange="pvOnDec(this)">
                <option value="">— Choisir —</option>
                <?php foreach ($options_decision as $v=>$l): ?>
                  <option value="<?= pv_h($v) ?>" <?= $decVal===$v?'selected':'' ?>><?= $l ?></option>
                <?php endforeach; ?>
              </select>
              <span class="pv-rule-tag" id="pvTag-<?= $e['id'] ?>" style="display:none"></span>
            </td>
            <?php if ($type==='annee'): ?>
            <td>
              <select name="pv_decisions[<?= $e['id'] ?>][next_classe]" class="pv-nxi" id="pvNxi-<?= $e['id'] ?>" style="<?= !$isAdm?'display:none':'' ?>">
                <option value="">↑ Collective</option>
                <?= pv_opt_classes($toutes_classes, $nextSaved) ?>
              </select>
            </td>
            <?php endif; ?>
            <td><input type="text" name="pv_decisions[<?= $e['id'] ?>][observation]" class="pv-obs" value="<?= pv_h($deja['observation'] ?? '') ?>"></td>
          </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
        <div class="pv-tfooter">
          <span style="font-size:.68rem;color:var(--muted)">⚡ auto = calculée par règle · Sans décision = non enregistré</span>
          <button type="submit" class="btn btn-abz-primary btn-sm pv-nop"><i class="bi bi-save me-1"></i>Enregistrer les décisions</button>
        </div>
      </div>
    </form>
  <?php else: ?>
    <div class="pv-empty"><i class="bi bi-mortarboard" style="font-size:2.2rem"></i><p>Sélectionnez une classe pour afficher le conseil.</p></div>
  <?php endif; ?>
</div>

<script>
const PV = {
  type: <?= json_encode($type) ?>,
  classe: <?= json_encode($classe_info['designation'] ?? '') ?>,
  annee: <?= json_encode($val_annee) ?>,
  etab: <?= json_encode(get_etablissement()['nom_fr'] ?? '') ?>,
  periode: <?= json_encode($type === 'annee' ? "Conseil de fin d'année" : (($trimestres[array_search($id_trim, array_column($trimestres,'id'))]['libelle'] ?? '')) ) ?>,
  classes: <?= json_encode(array_map(fn($c)=>['id'=>(int)$c['id'],'name'=>$c['designation']], $toutes_classes)) ?>,
  nextGlobal: <?= (int)$next_glob ?>,
};

let pvTriMode = 'alpha';
function pvSetTri(mode) {
  pvTriMode = mode;
  document.getElementById('pvBtnAlpha')?.classList.toggle('btn-abz-primary', mode==='alpha');
  document.getElementById('pvBtnAlpha')?.classList.toggle('btn-abz-outline', mode!=='alpha');
  document.getElementById('pvBtnMerite')?.classList.toggle('btn-abz-primary', mode==='merite');
  document.getElementById('pvBtnMerite')?.classList.toggle('btn-abz-outline', mode!=='merite');
  const tbody = document.getElementById('pvTbody'); if (!tbody) return;
  const rows = Array.from(tbody.querySelectorAll('tr[data-eid]'));
  rows.sort((a,b) => mode==='merite'
    ? parseFloat(b.dataset.moy||0) - parseFloat(a.dataset.moy||0)
    : a.dataset.nom.localeCompare(b.dataset.nom,'fr'));
  rows.forEach((tr,i) => { tbody.appendChild(tr); tr.cells[0].textContent = i+1; });
}

const pvAutoSet = new Set();
function pvOnDec(sel) {
  const eid = sel.dataset.eid;
  sel.classList.remove('ok','warn','bad');
  const v = sel.value;
  if (['Admis','Félicitations','Encouragements',"Tableau d'honneur"].includes(v)) sel.classList.add('ok');
  else if (['Redoublement','Avertissement (travail)'].includes(v)) sel.classList.add('warn');
  else if (['Exclu','Abandon','Blâme (travail)'].includes(v)) sel.classList.add('bad');
  const nxi = document.getElementById('pvNxi-'+eid);
  if (nxi) nxi.style.display = (PV.type==='annee' && v==='Admis') ? '' : 'none';
  if (!sel._auto) pvAutoSet.delete(eid);
}

function pvApplyRules() {
  const tbody = document.getElementById('pvTbody'); if (!tbody) return;
  let nb = 0;
  if (PV.type === 'annee') {
    const moyAdm = parseFloat(document.getElementById('pvRuleMoyAdm')?.value);
    const moyExclEl = document.getElementById('pvRuleMoyExcl'), absExclEl = document.getElementById('pvRuleAbsExcl');
    const exclJoursEl = document.getElementById('pvRuleExclJours');
    const moyExcl = moyExclEl.value !== '' ? parseFloat(moyExclEl.value) : null;
    const absExcl = absExclEl.value !== '' ? parseFloat(absExclEl.value) : null;
    const exclJours = exclJoursEl.value !== '' ? parseFloat(exclJoursEl.value) : null;
    if (isNaN(moyAdm)) { document.getElementById('pvRulesInfo').textContent = '⚠️ Saisissez la moyenne d\'admission.'; return; }
    tbody.querySelectorAll('tr[data-eid]').forEach(tr => {
      const eid = tr.dataset.eid, moy = parseFloat(tr.dataset.moy||0), absnj = parseInt(tr.dataset.absnj||0,10), excl = parseInt(tr.dataset.excl||0,10);
      const sel = document.getElementById('pvDsel-'+eid); if (!sel) return;
      if (sel.value !== '' && !pvAutoSet.has(eid)) return;
      let dec;
      if (exclJours !== null && excl >= exclJours) dec = 'Exclu';
      else if (absExcl !== null && absnj >= absExcl) dec = 'Exclu';
      else if (moyExcl !== null && moy <= moyExcl) dec = 'Exclu';
      else if (moy >= moyAdm) dec = 'Admis';
      else dec = 'Redoublement';
      sel.value = dec; sel._auto = true; pvOnDec(sel); pvAutoSet.add(eid);
      const tag = document.getElementById('pvTag-'+eid);
      if (tag) { tag.style.display='inline-block'; tag.textContent='⚡ auto'; tag.className='pv-rule-tag ' + (dec==='Admis'?'a-ok':dec==='Redoublement'?'a-warn':'a-bad'); }
      nb++;
    });
  } else {
    const th = parseFloat(document.getElementById('pvRuleTH')?.value || 12);
    const enc = parseFloat(document.getElementById('pvRuleEnc')?.value || 14);
    const fel = parseFloat(document.getElementById('pvRuleFel')?.value || 15);
    tbody.querySelectorAll('tr[data-eid]').forEach(tr => {
      const eid = tr.dataset.eid, moy = parseFloat(tr.dataset.moy||0), absnj = parseInt(tr.dataset.absnj||0,10), excl = parseInt(tr.dataset.excl||0,10);
      const sel = document.getElementById('pvDsel-'+eid); if (!sel) return;
      if (sel.value !== '' && !pvAutoSet.has(eid)) return;
      let dec = 'RAS';
      // Toute exclusion disciplinaire dans la période disqualifie des mentions positives.
      if (excl > 0) {
        dec = moy < 5 ? 'Blâme (travail)' : 'RAS';
      } else if (moy >= fel && absnj <= 8) dec = 'Félicitations';
      else if (moy >= enc && absnj <= 8) dec = 'Encouragements';
      else if (moy >= th && absnj <= 8) dec = "Tableau d'honneur";
      else if (moy < 5) dec = 'Blâme (travail)';
      else if (moy <= 7.3) dec = 'Avertissement (travail)';
      sel.value = dec; sel._auto = true; pvOnDec(sel); pvAutoSet.add(eid);
      const tag = document.getElementById('pvTag-'+eid);
      if (tag) { tag.style.display='inline-block'; tag.textContent = excl>0 ? '⚡ excl.' : '⚡ auto'; tag.className='pv-rule-tag ' + (dec==='RAS'?'a-warn':dec.includes('lâme')||dec.includes('Avert')?'a-bad':'a-ok'); }
      nb++;
    });
  }
  document.getElementById('pvRulesInfo').textContent = `✅ ${nb} décision(s) appliquée(s) automatiquement.`;
}
function pvClearRules() {
  document.getElementById('pvTbody')?.querySelectorAll('tr[data-eid]').forEach(tr => {
    const eid = tr.dataset.eid;
    if (!pvAutoSet.has(eid)) return;
    const sel = document.getElementById('pvDsel-'+eid); if (!sel) return;
    sel.value = ''; sel._auto = false; pvOnDec(sel); pvAutoSet.delete(eid);
    const tag = document.getElementById('pvTag-'+eid); if (tag) tag.style.display = 'none';
  });
}

document.getElementById('pvNxtGlob')?.addEventListener('change', function() {
  document.getElementById('pvNextGlobalHidden').value = this.value;
});

function pvMemoriserSeuils() {
  const cp = (fromId, toId) => {
    const el = document.getElementById(toId);
    if (el) el.value = document.getElementById(fromId)?.value || '';
  };
  cp('pvRuleMoyAdm', 'fsMoyAdm'); cp('pvRuleMoyExcl', 'fsMoyExcl');
  cp('pvRuleAbsExcl', 'fsAbsExcl'); cp('pvRuleExclJours', 'fsExclJours');
  cp('pvRuleTH', 'fsTH'); cp('pvRuleEnc', 'fsEnc'); cp('pvRuleFel', 'fsFel');
  document.getElementById('pvFrmSeuils').submit();
}

function pvExcel() {
  const rows = [[PV.etab || ''], [PV.classe + ' — ' + (PV.type==='annee'?'Conseil de fin d\'année':'Conseil trimestriel')],
    ['Année : ' + PV.annee + '   |   Période : ' + PV.periode], []];
  const headers = ['N°','NIU','Nom et Prénom','Moyenne','Rang','Abs.Jus.','Abs.N.J.','Excl.(j)','Décision','Observation'];
  rows.push(headers);
  document.querySelectorAll('#pvTbody tr[data-eid]').forEach((tr,i) => {
    const cells = tr.querySelectorAll('td');
    const dsel = tr.querySelector('.pv-dsel'), obs = tr.querySelector('.pv-obs');
    rows.push([i+1, cells[1].querySelector('div:last-child').textContent, cells[1].querySelector('.pv-nom').textContent,
      cells[2].textContent.trim(), cells[3].textContent.trim(), cells[4].textContent.trim(), cells[5].textContent.trim(),
      cells[6].textContent.trim(), dsel ? dsel.value : '', obs ? obs.value : '']);
  });
  const ws = XLSX.utils.aoa_to_sheet(rows);
  ws['!cols'] = [{wch:4},{wch:12},{wch:26},{wch:9},{wch:6},{wch:8},{wch:8},{wch:8},{wch:22},{wch:26}];
  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, 'Conseil');
  XLSX.writeFile(wb, `conseil_${(PV.classe||'classe').replace(/\s+/g,'_')}_${PV.type}.xlsx`);
}

document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.pv-dsel').forEach(s => pvOnDec(s));
});
</script>

<?php require_once __DIR__ . '/../../../layout/footer.php'; ?>
