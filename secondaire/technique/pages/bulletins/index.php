<?php
header('Cache-Control: no-store, no-cache, must-revalidate');
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
exiger_connexion();

// Mode "partiel" (AJAX) : réponse limitée au contenu de #bull-zone, sans
// noyau/layout/header.php ni noyau/layout/footer.php — voir initAjaxZone()/chargerPartiel()
// dans noyau/layout/footer.php. Choix de classe/période/ordre, retour à la liste,
// aperçu d'un élève ou de toute la classe passent par ce mécanisme — jamais
// les liens "Télécharger" (déclenchent un téléchargement de fichier, pas un
// changement d'affichage de cette page).
$es_partiel = isset($_GET['partiel']);

$role      = role_connecte();
$is_admin  = in_array($role, ['SUPERADMIN', 'ADMIN','PROVISEUR','CENSEUR']);
$is_ens    = ($role === 'ENSEIGNANT');
$mat_ens   = $is_ens ? get_matricule_ens_connecte() : null;

$annee_act = get_annee_active();
$id_annee  = (int)($annee_act['id'] ?? 0);
$val_annee = $annee_act['libelle'] ?? '';

if (!$is_admin && !$is_ens) {
    flash_set('erreur', 'Accès non autorisé.');
    rediriger('noyau/dashboard.php');
}

// Classes PP si enseignant
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
        $message = '<div class="alert alert-warning mt-3 mx-3"><i class="bi bi-lock me-2"></i>Accès réservé aux professeurs principaux et à l\'administration.</div>';
        if ($es_partiel) { echo $message; exit; }
        $titre_page = 'Bulletins';
        require_once __DIR__ . '/../../../../noyau/layout/header.php';
        echo $message;
        require_once __DIR__ . '/../../../../noyau/layout/footer.php';
        exit;
    }
}

$classes = $is_admin
    ? ($id_annee ? db_all("SELECT c.* FROM classe c JOIN inscription i ON i.id_classe=c.id AND i.id_annee=? WHERE c.archivee=0 GROUP BY c.id ORDER BY c.ordre, c.designation", [$id_annee]) : [])
    : $classes_pp;

// ── Paramètres GET ─────────────────────────────────────────────────
$id_classe = (int)($_GET['classe'] ?? 0);
// Vue "Séquence" masquée de l'interface (demande explicite) — seules
// Trimestre et Annuel restent choisissables ; 'seq' n'est plus une valeur
// atteignable via l'UI et retombe sur 'trim' si elle traîne dans une URL.
$vue       = in_array($_GET['vue'] ?? '', ['trim', 'annee'], true) ? $_GET['vue'] : 'trim';
$id_eleve  = (int)($_GET['eleve']  ?? 0);
$ordre     = in_array($_GET['ordre'] ?? '', ['alpha','merite']) ? $_GET['ordre'] : 'alpha';
$voir_tous = isset($_GET['voir_tous']);

// Sécurité PP
if ($is_ens && $id_classe) {
    $ids_pp = array_column($classes_pp, 'id');
    if (!in_array($id_classe, $ids_pp)) $id_classe = 0;
}
if ($is_ens && !$id_classe && count($classes_pp) === 1) {
    $id_classe = (int)$classes_pp[0]['id'];
}

// ── Séquence / Trimestre ACTIF(S) — pas de choix manuel ───────────
$seq_active  = db_one("SELECT s.*, t.id AS id_trim, t.libelle AS trim_lib FROM sequence s JOIN trimestre t ON t.id=s.id_trim WHERE s.active=1 AND t.id_annee=? LIMIT 1", [$id_annee]);
$trim_actif  = $seq_active ? db_one("SELECT * FROM trimestre WHERE id=?", [(int)$seq_active['id_trim']]) : null;

$id_seq  = $seq_active  ? (int)$seq_active['id']      : 0;
$id_trim = $trim_actif  ? (int)$trim_actif['id']       : 0;

// Trimestres de l'année (bilan annuel) — voir secondaire/technique/pages/bulletins/pdf_annuel.php
// pour le détail (même logique) : toujours 3 emplacements, complétés à vide
// si un trimestre n'existe pas encore (bulletin annuel consultable sans note).
$seq_ids_par_trim = [];
if ($vue === 'annee') {
    $trimestres_annee = db_all("SELECT * FROM trimestre WHERE id_annee=? ORDER BY ordre", [$id_annee]);
    $trimestres_annee = array_pad(array_slice($trimestres_annee, 0, 3), 3, null);
    foreach ($trimestres_annee as $i => $t) {
        $seq_ids_par_trim[$i] = $t
            ? array_column(db_all("SELECT id FROM sequence WHERE id_trim=? ORDER BY ordre", [(int)$t['id']]), 'id')
            : [];
    }
}

// Séquences prises en compte selon la vue choisie (séquence active seule,
// tout le trimestre, ou toute l'année pour le bilan annuel)
if ($vue === 'annee') {
    $seq_ids = array_merge($seq_ids_par_trim[0] ?? [], $seq_ids_par_trim[1] ?? [], $seq_ids_par_trim[2] ?? []);
} elseif ($vue === 'trim' && $id_trim) {
    $seqs_du_trim = db_all("SELECT * FROM sequence WHERE id_trim=? ORDER BY ordre", [$id_trim]);
    $seq_ids = array_column($seqs_du_trim, 'id');
} elseif ($id_seq) {
    $seq_ids = [$id_seq];
} else {
    $seq_ids = [];
}

$label_periode = '';
if ($vue === 'annee')                $label_periode = 'Bilan annuel — ' . $val_annee;
elseif ($vue === 'trim' && $trim_actif) $label_periode = $trim_actif['libelle'];
elseif ($seq_active)                 $label_periode = ($seq_active['trim_lib']??'').' — '.($seq_active['libelle']??'');

// ── Élèves avec moyenne et rang ─────────────────────────────────────
$periode_choisie = ($vue === 'annee') ? ($id_annee > 0) : ($id_trim || $id_seq);
$eleves = [];
if ($id_classe && $periode_choisie) {
    // Charger tous les élèves inscrits
    $eleves_raw = db_all(
        "SELECT e.id, e.nom, e.prenom, e.matricule, e.niu, e.sexe, e.date_naiss, e.lieu_naiss
         FROM eleve e
         JOIN inscription i ON i.id_eleve=e.id AND i.id_classe=? AND i.id_annee=?
         WHERE e.statut='actif' ORDER BY e.nom, e.prenom",
        [$id_classe, $id_annee]
    );

    if (!empty($eleves_raw) && !empty($seq_ids)) {
        $in_ph = implode(',', array_fill(0, count($seq_ids), '?'));
        // Notes de tous les élèves
        $params_n = array_merge([$id_annee, $id_classe], $seq_ids);
        $all_notes = db_all(
            "SELECT n.id_eleve, n.id_matiere, n.id_seq, n.valeur
             FROM note n
             JOIN inscription i ON i.id_eleve=n.id_eleve AND i.id_annee=? AND i.id_classe=?
             WHERE n.id_seq IN ($in_ph)",
            $params_n
        );
        $notes_idx = [];
        foreach ($all_notes as $n) {
            $notes_idx[$n['id_eleve']][$n['id_matiere']][$n['id_seq']] = (float)$n['valeur'];
        }
        // Comptage des notes par (matière, séquence) — pour la règle "absent = 0"
        $notes_count = [];
        foreach ($all_notes as $n) {
            $m = (int)$n['id_matiere']; $s = (int)$n['id_seq'];
            $notes_count[$m][$s] = ($notes_count[$m][$s] ?? 0) + 1;
        }
        $nb_inscrits = count($eleves_raw);

        // Absences justifiées (mêmes séquences)
        $abs_just = [];
        $all_abs = db_all(
            "SELECT id_eleve, id_matiere, id_seq FROM absence_justifiee WHERE id_seq IN ($in_ph) AND justifie=1",
            $seq_ids
        );
        foreach ($all_abs as $row) {
            $abs_just[(int)$row['id_eleve']][(int)$row['id_matiere']][(int)$row['id_seq']] = 1;
        }

        $disciplines = db_all("SELECT id_mat, coef FROM discipline WHERE IDClasses=?", [$id_classe]);

        // Identique à note_eff_bull()/note_eff_c() utilisées dans pdf.php et pdf_classe.php :
        // note existante > absence justifiée (exclue) > absent majoritaire (0) > pas encore noté (null)
        $note_eff = function(int $eid, int $id_mat, int $sid) use ($notes_idx, $abs_just, $notes_count, $nb_inscrits): array {
            if (isset($notes_idx[$eid][$id_mat][$sid])) return [$notes_idx[$eid][$id_mat][$sid], false];
            if (isset($abs_just[$eid][$id_mat][$sid])) return [null, true];
            $cnt = $notes_count[$id_mat][$sid] ?? 0;
            if ($nb_inscrits > 0 && $cnt >= ceil($nb_inscrits / 2)) return [0.0, false];
            return [null, false];
        };

        // Moyenne pondérée d'un élève sur un jeu de séquences donné (utilisée
        // telle quelle en mode séquence/trimestre, et 3 fois — une par
        // trimestre — en mode annuel ci-dessous).
        $calc_moy_periode = function(int $eid, array $ids) use ($note_eff, $disciplines): ?float {
            $tp = 0; $tc = 0;
            foreach ($disciplines as $d) {
                $vals = [];
                foreach ($ids as $sid) {
                    [$v, $ex] = $note_eff($eid, (int)$d['id_mat'], $sid);
                    if ($ex) continue;
                    if ($v !== null) $vals[] = $v;
                }
                if (!empty($vals)) { $a = array_sum($vals)/count($vals); $tp += $a*$d['coef']; $tc += $d['coef']; }
            }
            return $tc > 0 ? $tp/$tc : null;
        };

        // Moyenne annuelle = moyenne des moyennes trimestrielles existantes
        // (méthode retenue, demande explicite, identique à pdf_annuel.php) —
        // sinon (séquence/trimestre) moyenne pondérée classique.
        $calc_moy = function(int $eid) use ($calc_moy_periode, $seq_ids, $seq_ids_par_trim, $vue): ?float {
            if ($vue === 'annee') {
                $vals = [];
                foreach ($seq_ids_par_trim as $ids_t) {
                    if (empty($ids_t)) continue;
                    $m = $calc_moy_periode($eid, $ids_t);
                    if ($m !== null) $vals[] = $m;
                }
                return !empty($vals) ? array_sum($vals) / count($vals) : null;
            }
            return $calc_moy_periode($eid, $seq_ids);
        };

        $moys = [];
        foreach ($eleves_raw as $el) $moys[$el['id']] = $calc_moy($el['id']);

        $moys_sorted = array_filter($moys, fn($m) => $m !== null);
        arsort($moys_sorted);
        $rangs = [];
        $r = 1;
        foreach ($moys_sorted as $eid => $m) { $rangs[$eid] = $r++; }

        foreach ($eleves_raw as &$el) {
            $el['moy']  = $moys[$el['id']];
            $el['rang'] = $rangs[$el['id']] ?? null;
        }
        unset($el);

        if ($ordre === 'merite') {
            usort($eleves_raw, fn($a,$b) => ($b['moy'] ?? -1) <=> ($a['moy'] ?? -1));
        }
        $eleves = $eleves_raw;
    } else {
        $eleves = $eleves_raw;
    }
}

$fmt = fn(?float $v): string => $v === null ? '—' : rtrim(rtrim(number_format($v,2,'.',''),'0'),'.');
$etab_sig_bull = signature_configuree('chef_etablissement');
$peut_configurer_sig_bull = signature_role_autorisee('chef_etablissement', role_connecte());

function pdf_url_bull(int $id_eleve, string $vue, int $id_trim, int $id_seq, int $id_annee, bool $dl=false): string {
    if ($vue === 'annee') {
        $base = APP_URL.'/secondaire/technique/pages/bulletins/pdf_annuel.php?eleve='.$id_eleve.'&annee='.$id_annee;
    } else {
        $base = APP_URL.'/secondaire/technique/pages/bulletins/pdf.php?eleve='.$id_eleve.'&annee='.$id_annee;
        $base .= $vue === 'trim' ? '&trim='.$id_trim : '&seq='.$id_seq;
    }
    if ($dl) $base .= '&dl=1';
    return $base;
}
function url_classe_bull(int $id_classe, string $vue, int $id_trim, int $id_seq, int $id_annee, string $ordre, bool $dl=false): string {
    if ($vue === 'annee') {
        $base = APP_URL.'/secondaire/technique/pages/bulletins/pdf_annuel_classe.php?classe='.$id_classe.'&annee='.$id_annee.'&ordre='.$ordre;
    } else {
        $base = APP_URL.'/secondaire/technique/pages/bulletins/pdf_classe.php?classe='.$id_classe.'&annee='.$id_annee.'&ordre='.$ordre;
        $base .= $vue === 'trim' ? '&trim='.$id_trim : '&seq='.$id_seq;
    }
    if ($dl) $base .= '&dl=1';
    return $base;
}

if (!$es_partiel) {
    $titre_page = 'Bulletins';
    require_once __DIR__ . '/../../../../noyau/layout/header.php';
    ?>
    <style>
    .btn-abz-primary   { background:#1a3c6b;color:#fff;border:none; }
    .btn-abz-primary:hover { background:#12305a;color:#fff; }
    .btn-abz-outline   { background:#fff;color:#1a3c6b;border:1.5px solid #1a3c6b; }
    .btn-abz-outline:hover { background:#1a3c6b;color:#fff; }
    .btn-abz-success   { background:#15803d;color:#fff;border:none; }
    .btn-abz-success:hover { background:#116635;color:#fff; }
    .badge-periode     { background:#dbeafe;color:#1e3a8a;font-size:.78rem;padding:5px 12px;border-radius:20px;font-weight:600; }
    .tbl-bull th       { background:#1a3c6b;color:#fff;font-size:.78rem;padding:8px 10px; }
    .tbl-bull td       { font-size:.81rem;padding:7px 10px;vertical-align:middle; }
    .tbl-bull tr:hover td { background:#f0f4ff; }
    .ordre-btn         { padding:6px 16px;font-size:.82rem;border-radius:6px;cursor:pointer;border:1.5px solid #1a3c6b;transition:all .15s; }
    .ordre-btn.active  { background:#1a3c6b;color:#fff; }
    .ordre-btn:not(.active){ background:#fff;color:#1a3c6b; }
    </style>
    <?= flash_html() ?>
    <?php
} else {
    header('Content-Type: text/html; charset=utf-8');
}
?>

<div id="bull-zone">

<div class="page-titre d-flex align-items-center justify-content-between flex-wrap gap-2">
  <h4><i class="bi bi-file-earmark-text me-2" style="color:#1a3c6b"></i>Bulletins de notes</h4>
  <div class="d-flex align-items-center gap-2 flex-wrap">
    <?php if ($vue === 'annee'): ?>
      <span class="badge-periode"><i class="bi bi-calendar3 me-1"></i><?= h($label_periode) ?></span>
    <?php elseif ($seq_active): ?>
      <span class="badge-periode"><i class="bi bi-calendar3 me-1"></i>
        <?= $vue==='trim' ? h($trim_actif['libelle']??'') : h(($seq_active['trim_lib']??'').' — '.($seq_active['libelle']??'')) ?>
      </span>
    <?php else: ?>
      <span class="badge bg-warning text-dark">Aucune séquence active</span>
    <?php endif; ?>
  </div>
</div>

<!-- ── Filtres ── -->
<div class="card mb-3" style="border-color:#c7d8f0">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end" data-ajax-nav-form>

      <!-- Vue -->
      <input type="hidden" name="ordre" value="<?= h($ordre) ?>">
      <?php if ($id_eleve): ?><input type="hidden" name="eleve" value="<?= $id_eleve ?>"><?php endif; ?>
      <div class="col-auto">
        <label class="form-label mb-1" style="font-size:.78rem;font-weight:600;color:#1a3c6b">Période</label>
        <div class="d-flex gap-1">
          <button type="submit" name="vue" value="trim"
                  class="ordre-btn <?= $vue==='trim'?'active':'' ?>">
            <i class="bi bi-calendar3 me-1"></i>Trimestre
          </button>
          <button type="submit" name="vue" value="annee"
                  class="ordre-btn <?= $vue==='annee'?'active':'' ?>">
            <i class="bi bi-calendar-range me-1"></i>Annuel
          </button>
        </div>
      </div>

      <!-- Classe -->
      <?php if ($is_admin || count($classes) > 1): ?>
      <div class="col-md-3">
        <label class="form-label mb-1" style="font-size:.78rem;font-weight:600;color:#1a3c6b">Classe</label>
        <select name="classe" class="form-select form-select-sm" data-ajax-nav-auto>
          <option value="">— Choisir —</option>
          <?php foreach ($classes as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $id_classe==$c['id']?'selected':''?>><?= h($c['designation']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php else: ?>
        <input type="hidden" name="classe" value="<?= $id_classe ?>">
      <?php endif; ?>

      <!-- Ordre -->
      <?php if ($id_classe): ?>
      <div class="col-auto">
        <label class="form-label mb-1" style="font-size:.78rem;font-weight:600;color:#1a3c6b">Ordre</label>
        <div class="d-flex gap-1">
          <a href="?vue=<?= $vue ?>&classe=<?= $id_classe ?>&ordre=alpha<?= $id_eleve?'&eleve='.$id_eleve:'' ?>" data-ajax-nav
             class="ordre-btn <?= $ordre==='alpha'?'active':'' ?>">
            <i class="bi bi-sort-alpha-down me-1"></i>Alphabétique
          </a>
          <a href="?vue=<?= $vue ?>&classe=<?= $id_classe ?>&ordre=merite<?= $id_eleve?'&eleve='.$id_eleve:'' ?>" data-ajax-nav
             class="ordre-btn <?= $ordre==='merite'?'active':'' ?>">
            <i class="bi bi-trophy me-1"></i>Mérite
          </a>
        </div>
      </div>
      <?php endif; ?>

    </form>
  </div>
</div>

<?php $periode_ok = $periode_choisie; ?>

<?php if (!$id_classe): ?>
  <div class="text-center py-5 text-muted">
    <i class="bi bi-arrow-up-circle" style="font-size:3rem;opacity:.2;display:block;margin-bottom:1rem"></i>
    Sélectionnez une classe pour afficher les bulletins.
  </div>

<?php elseif (!$periode_ok): ?>
  <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i>Aucune séquence active — configurez une séquence dans les paramètres.</div>

<?php elseif ($voir_tous): ?>
<!-- ══ VISUALISATION TOUTE LA CLASSE ══ -->
<?php $url_cl = url_classe_bull($id_classe, $vue, $id_trim, $id_seq, $id_annee, $ordre); ?>
<div class="card" style="border-color:#c7d8f0">
  <div class="card-header py-2 d-flex align-items-center justify-content-between flex-wrap gap-2" style="background:#f0f4ff">
    <span class="fw-semibold" style="color:#1a3c6b">
      <i class="bi bi-people me-1"></i>Bulletins — <?= h($label_periode) ?>
    </span>
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <?php if ($etab_sig_bull): ?>
      <div class="chk-signature form-check form-check-inline mb-0" style="user-select:none">
        <input class="form-check-input" type="checkbox" id="chkSigCl" onchange="appliquerSigCl()">
        <label class="form-check-label small" for="chkSigCl">Signature numérique</label>
      </div>
      <?php if ($peut_configurer_sig_bull): ?>
      <button type="button" class="btn btn-sm btn-abz-outline" title="Configurer la position de la signature"
              onclick="ouvrirPositionSignatureLocale(<?= json_encode($url_cl) ?>, <?= json_encode($vue === 'annee' ? 'bulletin_annuel_classe' : 'bulletin_trimestriel_classe') ?>, 'portrait', appliquerSigCl)">
        <i class="bi bi-gear"></i>
      </button>
      <?php endif; ?>
      <?php endif; ?>
      <a href="?vue=<?= $vue ?>&classe=<?= $id_classe ?>&ordre=<?= $ordre ?>" data-ajax-nav
         class="btn btn-sm btn-abz-outline"><i class="bi bi-list me-1"></i>Retour liste</a>
      <a id="lienDlCl" href="<?= h(url_classe_bull($id_classe,$vue,$id_trim,$id_seq,$id_annee,$ordre,true)) ?>"
         class="btn btn-sm btn-abz-primary"><i class="bi bi-download me-1"></i>Télécharger PDF</a>
      <button onclick="document.getElementById('iframe-cl').contentWindow.print()"
              class="btn btn-sm btn-abz-outline"><i class="bi bi-printer me-1"></i>Imprimer</button>
    </div>
  </div>
  <div class="card-body p-0">
    <iframe id="iframe-cl" src="<?= h($url_cl) ?>"
            style="width:100%;height:88vh;border:none;display:block" title="Bulletins classe"></iframe>
  </div>
</div>
<script>
function appliquerSigCl() {
  const base = <?= json_encode($url_cl) ?>;
  const baseDl = <?= json_encode(url_classe_bull($id_classe,$vue,$id_trim,$id_seq,$id_annee,$ordre,true)) ?>;
  const sig = document.getElementById('chkSigCl').checked;
  const sep = base.includes('?') ? '&' : '?';
  const sepDl = baseDl.includes('?') ? '&' : '?';
  document.getElementById('iframe-cl').src = base + (sig ? sep + 'signature=1' : '');
  document.getElementById('lienDlCl').href = baseDl + (sig ? sepDl + 'signature=1' : '');
}
</script>

<?php elseif ($id_eleve): ?>
<!-- ══ APERÇU BULLETIN ÉLÈVE ══ -->
<?php
$eleve_sel = db_one("SELECT * FROM eleve WHERE id=?", [$id_eleve]);
$url_pdf   = pdf_url_bull($id_eleve, $vue, $id_trim, $id_seq, $id_annee);
$url_dl    = pdf_url_bull($id_eleve, $vue, $id_trim, $id_seq, $id_annee, true);
$back_url  = '?vue='.$vue.'&classe='.$id_classe.'&ordre='.$ordre;
?>
<div class="card" style="border-color:#c7d8f0">
  <div class="card-header py-2 d-flex align-items-center justify-content-between flex-wrap gap-2" style="background:#f0f4ff">
    <span class="fw-semibold" style="color:#1a3c6b">
      <i class="bi bi-person me-1"></i>
      <?= h(strtoupper($eleve_sel['nom']??'').' '.($eleve_sel['prenom']??'')) ?>
      <span class="text-muted fw-normal" style="font-size:.8rem">— <?= h($label_periode) ?></span>
    </span>
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <?php if ($etab_sig_bull): ?>
      <div class="chk-signature form-check form-check-inline mb-0" style="user-select:none">
        <input class="form-check-input" type="checkbox" id="chkSigEl" onchange="appliquerSigEl()">
        <label class="form-check-label small" for="chkSigEl">Signature numérique</label>
      </div>
      <?php if ($peut_configurer_sig_bull): ?>
      <button type="button" class="btn btn-sm btn-abz-outline" title="Configurer la position de la signature"
              onclick="ouvrirPositionSignatureLocale(<?= json_encode($url_pdf) ?>, <?= json_encode($vue === 'annee' ? 'bulletin_annuel' : 'bulletin_trimestriel') ?>, 'portrait', appliquerSigEl)">
        <i class="bi bi-gear"></i>
      </button>
      <?php endif; ?>
      <?php endif; ?>
      <a href="<?= h($back_url) ?>" data-ajax-nav class="btn btn-sm btn-abz-outline"><i class="bi bi-arrow-left me-1"></i>Retour</a>
      <a id="lienDlEl" href="<?= h($url_dl) ?>" class="btn btn-sm btn-abz-primary"><i class="bi bi-download me-1"></i>Télécharger</a>
      <button onclick="document.getElementById('iframe-bull').contentWindow.print()"
              class="btn btn-sm btn-abz-outline"><i class="bi bi-printer me-1"></i>Imprimer</button>
    </div>
  </div>
  <div class="card-body p-0">
    <iframe id="iframe-bull" src="<?= h($url_pdf) ?>"
            style="width:100%;height:88vh;border:none;display:block" title="Aperçu bulletin"></iframe>
  </div>
</div>
<script>
function appliquerSigEl() {
  const base = <?= json_encode($url_pdf) ?>;
  const baseDl = <?= json_encode($url_dl) ?>;
  const sig = document.getElementById('chkSigEl').checked;
  const sep = base.includes('?') ? '&' : '?';
  const sepDl = baseDl.includes('?') ? '&' : '?';
  document.getElementById('iframe-bull').src = base + (sig ? sep + 'signature=1' : '');
  document.getElementById('lienDlEl').href = baseDl + (sig ? sepDl + 'signature=1' : '');
}
</script>

<?php else: ?>
<!-- ══ TABLEAU CLASSE ══ -->
<?php if (empty($eleves)): ?>
  <div class="alert alert-info"><i class="bi bi-info-circle me-2"></i>Aucun élève actif dans cette classe.</div>
<?php else: ?>

<div class="card" style="border-color:#c7d8f0">
  <div class="card-header py-2 d-flex align-items-center justify-content-between flex-wrap gap-2" style="background:#f0f4ff">
    <span class="fw-semibold" style="color:#1a3c6b;font-size:.88rem">
      <i class="bi bi-people me-1"></i><?= count($eleves) ?> élève(s) —
      <span class="fw-normal text-muted"><?= h($label_periode) ?></span>
    </span>
    <div class="d-flex gap-2 flex-wrap">
      <a href="?vue=<?= $vue ?>&classe=<?= $id_classe ?>&ordre=<?= $ordre ?>&voir_tous=1" data-ajax-nav
         class="btn btn-sm btn-abz-outline">
        <i class="bi bi-eye me-1"></i>Visualiser tous les bulletins
      </a>
      <a href="<?= h(url_classe_bull($id_classe,$vue,$id_trim,$id_seq,$id_annee,$ordre,true)) ?>"
         class="btn btn-sm btn-abz-primary">
        <i class="bi bi-download me-1"></i>Télécharger tous
      </a>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table tbl-bull table-hover mb-0">
      <thead>
        <tr>
          <th>N°</th>
          <th>NIU</th>
          <th>Nom et Prénom</th>
          <th>Date et Lieu de Naissance</th>
          <th style="width:50px">Sexe</th>
          <th style="width:70px">Moyenne</th>
          <th style="width:55px">Rang</th>
          <th style="width:140px;text-align:center">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($eleves as $i => $el): ?>
        <?php
        $url_ap = '?vue='.$vue.'&classe='.$id_classe.'&ordre='.$ordre.'&eleve='.$el['id'];
        $url_d  = pdf_url_bull($el['id'], $vue, $id_trim, $id_seq, $id_annee, true);
        $moy    = $el['moy'] ?? null;
        $rang   = $el['rang'] ?? null;
        $dn     = $el['date_naiss'] ? date('d/m/Y', strtotime($el['date_naiss'])) : '—';
        $naiss  = trim($dn.' '.($el['lieu_naiss']??''));
        $cls_moy= $moy !== null ? ($moy >= 10 ? 'text-success fw-bold' : 'text-danger fw-bold') : 'text-muted';
        ?>
        <tr>
          <td class="text-muted"><?= $i+1 ?></td>
          <td style="font-size:.75rem"><?= h($el['niu'] ?? '—') ?></td>
          <td>
            <a href="<?= h($url_ap) ?>" data-ajax-nav class="text-decoration-none fw-semibold" style="color:#1a3c6b">
              <?= h(strtoupper($el['nom']).' '.($el['prenom']??'')) ?>
            </a>
          </td>
          <td style="font-size:.78rem"><?= h($naiss) ?></td>
          <td><?= h($el['sexe'] ?? '—') ?></td>
          <td class="<?= $cls_moy ?>"><?= $fmt($moy) ?></td>
          <td class="text-center fw-semibold"><?= $rang !== null ? $rang.'e' : '—' ?></td>
          <td class="text-center">
            <a href="<?= h($url_ap) ?>" data-ajax-nav class="btn btn-sm btn-abz-primary"
               style="font-size:.72rem;padding:3px 9px" title="Aperçu">
              <i class="bi bi-eye me-1"></i>Aperçu
            </a>
            <a href="<?= h($url_d) ?>" class="btn btn-sm btn-abz-outline"
               style="font-size:.72rem;padding:3px 8px" title="Télécharger">
              <i class="bi bi-download"></i>
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

</div><!-- /#bull-zone -->

<?php
if ($es_partiel) exit; // rien de plus dans une réponse AJAX partielle.
$ajax_zone_id = 'bull-zone'; // voir noyau/layout/footer.php — initAjaxZone() y est appelé après sa propre définition
require_once __DIR__ . '/../../../../noyau/layout/footer.php'; ?>