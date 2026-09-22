<?php
header('Cache-Control: no-store, no-cache, must-revalidate');
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_connexion();

$role      = role_connecte();
$is_admin  = in_array($role, ['ADMIN','PROVISEUR', 'FONDATEUR','CENSEUR']) || $role === 'MEMBRE_ASSOCIATION';
$is_ens    = ($role === 'ENSEIGNANT');
$mat_ens   = $is_ens ? get_matricule_ens_connecte() : null;

$annee_act = get_annee_active();
$id_annee  = (int)($annee_act['id'] ?? 0);
$val_annee = $annee_act['libelle'] ?? '';

if (!$is_admin && !$is_ens) {
    flash_set('erreur', 'Accès non autorisé.');
    rediriger('dashboard.php');
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
        $titre_page = 'Bulletins';
        require_once __DIR__ . '/../../../layout/header.php';
        echo '<div class="alert alert-warning mt-3 mx-3"><i class="bi bi-lock me-2"></i>Accès réservé aux professeurs principaux et à l\'administration.</div>';
        require_once __DIR__ . '/../../../layout/footer.php';
        exit;
    }
}

$classes = $is_admin
    ? ($id_annee ? db_all("SELECT c.* FROM classe c JOIN inscription i ON i.id_classe=c.id AND i.id_annee=? WHERE c.archivee=0 GROUP BY c.id ORDER BY c.ordre, c.designation", [$id_annee]) : [])
    : $classes_pp;

// ── Paramètres GET ─────────────────────────────────────────────────
$id_classe = (int)($_GET['classe'] ?? 0);
$vue       = in_array($_GET['vue'] ?? '', ['trim', 'annee'], true) ? $_GET['vue'] : 'trim';
$id_eleve  = (int)($_GET['eleve']  ?? 0);
$ordre     = in_array($_GET['ordre'] ?? '', ['alpha','merite']) ? $_GET['ordre'] : 'alpha';
$voir_tous = isset($_GET['voir_tous']);
$id_serie  = (int)($_GET['serie'] ?? 0);

// Sécurité PP
if ($is_ens && $id_classe) {
    $ids_pp = array_column($classes_pp, 'id');
    if (!in_array($id_classe, $ids_pp)) $id_classe = 0;
}
if ($is_ens && !$id_classe && count($classes_pp) === 1) {
    $id_classe = (int)$classes_pp[0]['id'];
}

// ── Trimestre actif (chantier APC, voir prompt_continuite, mise à jour du
// 07/08/2026) — plus de vue "Séquence" : les évaluations se font désormais
// par trimestre entier (compétences), la séquence n'a plus de rôle
// fonctionnel dans ce module. Moyenne/rang calculés sur les compétences
// (note.id_competence), voir bull_moys_classe_comp() ci-dessous.
$trim_comp_actif     = get_trimestre_actif();
$id_trim_comp_actif  = (int)($trim_comp_actif['id'] ?? 0);
$id_trim             = $id_trim_comp_actif;

$label_periode = '';
if ($vue === 'annee')          $label_periode = 'Bilan annuel — ' . $val_annee;
elseif ($trim_comp_actif)      $label_periode = $trim_comp_actif['libelle'];

// ── Élèves avec moyenne et rang ─────────────────────────────────────

// Fonction : moyenne pondérée de chaque élève d'une classe, pour UN
// trimestre donné, à partir des compétences de ce trimestre (même
// algorithme que moy_eleve_bull()/mat_avg_comp() dans pdf.php).
function bull_moys_classe_comp(int $id_classe, int $id_trim_x, int $id_annee, array $eleve_ids): array {
    if (!$id_trim_x || empty($eleve_ids)) return [];
    $disciplines = db_all(
        "SELECT d.id_mat, d.coef FROM discipline d
         JOIN matiere m ON m.id=d.id_mat AND m.actif=1
         WHERE d.IDClasses=?", [$id_classe]
    );
    if (empty($disciplines)) return [];
    $code_niveau = db_val("SELECT code_niveau FROM classe WHERE id=?", [$id_classe]);

    $competences_par_mat = [];
    foreach ($disciplines as $d) {
        $competences_par_mat[$d['id_mat']] = db_all(
            "SELECT id FROM competence WHERE id_matiere=? AND code_niveau=? AND id_trim=? ORDER BY ordre",
            [$d['id_mat'], $code_niveau, $id_trim_x]
        );
    }
    $all_comp_ids = [];
    foreach ($competences_par_mat as $comps) { foreach ($comps as $c) { $all_comp_ids[] = (int)$c['id']; } }
    if (empty($all_comp_ids)) return [];

    $in_c = implode(',', array_fill(0, count($all_comp_ids), '?'));
    $all_notes_raw = db_all(
        "SELECT n.id_eleve, n.id_competence, n.valeur
         FROM note n
         JOIN inscription i ON i.id_eleve=n.id_eleve AND i.id_annee=? AND i.id_classe=?
         JOIN eleve el ON el.id=n.id_eleve AND el.statut='actif'
         WHERE n.id_competence IN ($in_c)",
        array_merge([$id_annee, $id_classe], $all_comp_ids)
    );
    $notes_idx = [];
    foreach ($all_notes_raw as $row) { $notes_idx[(int)$row['id_eleve']][(int)$row['id_competence']] = (float)$row['valeur']; }
    $notes_count = [];
    foreach ($all_notes_raw as $row) {
        $c = (int)$row['id_competence'];
        $notes_count[$c] = ($notes_count[$c] ?? 0) + 1;
    }
    $nb_ins = count($eleve_ids);

    $mat_avg = function(int $eid, array $comps) use ($notes_idx, $notes_count, $nb_ins): ?float {
        $tot = 0; $cnt = 0;
        foreach ($comps as $c) {
            $cid = (int)$c['id'];
            $v = $notes_idx[$eid][$cid] ?? null;
            if ($v === null) {
                $n = $notes_count[$cid] ?? 0;
                if ($nb_ins > 0 && $n >= ceil($nb_ins / 2)) $v = 0.0; // absent majoritaire = 0
            }
            if ($v !== null) { $tot += $v; $cnt++; }
        }
        return $cnt > 0 ? $tot / $cnt : null;
    };

    $mats_avec_notes = [];
    foreach ($disciplines as $d) {
        foreach ($competences_par_mat[$d['id_mat']] ?? [] as $c) {
            if (($notes_count[(int)$c['id']] ?? 0) > 0) { $mats_avec_notes[] = $d['id_mat']; break; }
        }
    }
    $nb_mats_avec_notes = count($mats_avec_notes);

    $out = [];
    foreach ($eleve_ids as $eid) {
        $tot = 0; $coef = 0; $nb_data = 0;
        foreach ($disciplines as $d) {
            if (!in_array($d['id_mat'], $mats_avec_notes)) continue;
            $avg = $mat_avg($eid, $competences_par_mat[$d['id_mat']] ?? []);
            if ($avg !== null) { $tot += $avg * $d['coef']; $coef += $d['coef']; $nb_data++; }
        }
        $moy = $coef > 0 ? $tot / $coef : null;
        $classe_ok = $nb_mats_avec_notes > 0 && $nb_data >= ceil($nb_mats_avec_notes / 2);
        $out[$eid] = ($classe_ok && $moy !== null) ? $moy : null;
    }
    return $out;
}

// Séries (LV2) réellement présentes dans la classe choisie — permet de
// filtrer une classe mixte (ex. 4ème Allemand/Arabe/Espagnol) aussi bien à
// l'écran que dans les PDF de classe (pdf_classe.php/pdf_annuel_classe.php).
$series_dispo = [];
if ($id_classe) {
    $series_dispo = db_all(
        "SELECT DISTINCT s.id, s.libelle FROM inscription i
         JOIN serie s ON s.id=i.id_serie
         WHERE i.id_classe=? AND i.id_annee=? ORDER BY s.libelle",
        [$id_classe, $id_annee]
    );
    if ($id_serie && !in_array($id_serie, array_column($series_dispo, 'id'))) $id_serie = 0;
}

$periode_choisie = ($vue === 'annee') ? ($id_annee > 0) : ($id_trim_comp_actif > 0);
$eleves = [];
if ($id_classe && $periode_choisie) {
    // Charger TOUS les élèves inscrits (LV2/série non filtrée ici) : le rang
    // et la moyenne doivent toujours être calculés sur la classe entière,
    // même quand ?serie= ne filtre que l'AFFICHAGE d'un sous-groupe (classe
    // mixte) — sinon un élève filtré verrait un rang "sur son sous-groupe"
    // au lieu de son vrai rang de classe (incohérent avec pdf_classe.php).
    $eleves_raw = db_all(
        "SELECT e.id, e.nom, e.prenom, e.matricule, e.niu, e.sexe, e.date_naiss, e.lieu_naiss,
                i.id_serie, s.libelle AS serie
         FROM eleve e
         JOIN inscription i ON i.id_eleve=e.id AND i.id_classe=? AND i.id_annee=?
         LEFT JOIN serie s ON s.id=i.id_serie
         WHERE e.statut='actif' ORDER BY e.nom, e.prenom",
        [$id_classe, $id_annee]
    );

    if (!empty($eleves_raw)) {
        $eleve_ids = array_column($eleves_raw, 'id');

        if ($vue === 'annee') {
            // Moyenne annuelle = moyenne des moyennes trimestrielles existantes
            // (méthode retenue, demande explicite, identique à pdf_annuel.php).
            $trimestres_annee = db_all("SELECT id FROM trimestre WHERE id_annee=? ORDER BY ordre", [$id_annee]);
            $moys_par_trim = [];
            foreach ($trimestres_annee as $t) {
                $moys_par_trim[] = bull_moys_classe_comp($id_classe, (int)$t['id'], $id_annee, $eleve_ids);
            }
            $moys = [];
            foreach ($eleve_ids as $eid) {
                $vals = [];
                foreach ($moys_par_trim as $mt) { if (($mt[$eid] ?? null) !== null) $vals[] = $mt[$eid]; }
                $moys[$eid] = !empty($vals) ? array_sum($vals) / count($vals) : null;
            }
        } else {
            $moys = bull_moys_classe_comp($id_classe, $id_trim_comp_actif, $id_annee, $eleve_ids);
        }

        $moys_sorted = array_filter($moys, fn($m) => $m !== null);
        arsort($moys_sorted);
        $rangs = [];
        $r = 1;
        foreach ($moys_sorted as $eid => $m) { $rangs[$eid] = $r++; }

        foreach ($eleves_raw as &$el) {
            $el['moy']  = $moys[$el['id']] ?? null;
            $el['rang'] = $rangs[$el['id']] ?? null;
        }
        unset($el);

        if ($ordre === 'merite') {
            usort($eleves_raw, fn($a,$b) => ($b['moy'] ?? -1) <=> ($a['moy'] ?? -1));
        }
    }
    // Filtre d'AFFICHAGE par série, appliqué après le calcul des rangs
    // ci-dessus (voir commentaire plus haut) — n'affecte que la liste écran,
    // pas le classement.
    $eleves = $id_serie
        ? array_values(array_filter($eleves_raw, fn($el) => (int)($el['id_serie'] ?? 0) === $id_serie))
        : $eleves_raw;
}

$fmt = fn(?float $v): string => $v === null ? '—' : rtrim(rtrim(number_format($v,2,'.',''),'0'),'.');
$etab_sig_bull = signature_configuree('chef_etablissement');
$peut_configurer_sig_bull = signature_role_autorisee('chef_etablissement', role_connecte());

function pdf_url_bull(int $id_eleve, string $vue, int $id_trim, int $id_annee, bool $dl=false): string {
    if ($vue === 'annee') {
        $base = APP_URL.'/secondaire/pages/bulletins/pdf_annuel.php?eleve='.$id_eleve.'&annee='.$id_annee;
    } else {
        $base = APP_URL.'/secondaire/pages/bulletins/pdf.php?eleve='.$id_eleve.'&annee='.$id_annee.'&trim='.$id_trim;
    }
    if ($dl) $base .= '&dl=1';
    return $base;
}
function url_classe_bull(int $id_classe, string $vue, int $id_trim, int $id_annee, string $ordre, bool $dl=false, int $id_serie=0): string {
    if ($vue === 'annee') {
        $base = APP_URL.'/secondaire/pages/bulletins/pdf_annuel_classe.php?classe='.$id_classe.'&annee='.$id_annee.'&ordre='.$ordre;
    } else {
        $base = APP_URL.'/secondaire/pages/bulletins/pdf_classe.php?classe='.$id_classe.'&annee='.$id_annee.'&ordre='.$ordre.'&trim='.$id_trim;
    }
    if ($id_serie) $base .= '&serie='.$id_serie;
    if ($dl) $base .= '&dl=1';
    return $base;
}

$titre_page = 'Bulletins';
require_once __DIR__ . '/../../../layout/header.php';
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

<div class="page-titre d-flex align-items-center justify-content-between flex-wrap gap-2">
  <h4><i class="bi bi-file-earmark-text me-2" style="color:#1a3c6b"></i>Bulletins de notes</h4>
  <div class="d-flex align-items-center gap-2 flex-wrap">
    <?php if ($vue === 'annee'): ?>
      <span class="badge-periode"><i class="bi bi-calendar3 me-1"></i><?= h($label_periode) ?></span>
    <?php elseif ($trim_comp_actif): ?>
      <span class="badge-periode"><i class="bi bi-calendar3 me-1"></i>
        <?= h($trim_comp_actif['libelle'] ?? '') ?>
      </span>
    <?php else: ?>
      <span class="badge bg-warning text-dark">Aucun trimestre actif</span>
    <?php endif; ?>
  </div>
</div>

<?= flash_html() ?>

<!-- ── Filtres ── -->
<div class="card mb-3" style="border-color:#c7d8f0">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end">

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
        <select name="classe" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="">— Choisir —</option>
          <?php foreach ($classes as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $id_classe==$c['id']?'selected':''?>><?= h($c['designation']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php else: ?>
        <input type="hidden" name="classe" value="<?= $id_classe ?>">
      <?php endif; ?>

      <!-- Série / LV2 (classe mixte) -->
      <?php if ($id_classe && $series_dispo): ?>
      <div class="col-md-2">
        <label class="form-label mb-1" style="font-size:.78rem;font-weight:600;color:#1a3c6b">Série / LV2</label>
        <select name="serie" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="">— Toutes —</option>
          <?php foreach ($series_dispo as $s): ?>
            <option value="<?= $s['id'] ?>" <?= $id_serie==$s['id']?'selected':''?>><?= h($s['libelle']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <!-- Ordre -->
      <?php if ($id_classe): ?>
      <div class="col-auto">
        <label class="form-label mb-1" style="font-size:.78rem;font-weight:600;color:#1a3c6b">Ordre</label>
        <div class="d-flex gap-1">
          <a href="?vue=<?= $vue ?>&classe=<?= $id_classe ?>&ordre=alpha<?= $id_serie?'&serie='.$id_serie:'' ?><?= $id_eleve?'&eleve='.$id_eleve:'' ?>"
             class="ordre-btn <?= $ordre==='alpha'?'active':'' ?>">
            <i class="bi bi-sort-alpha-down me-1"></i>Alphabétique
          </a>
          <a href="?vue=<?= $vue ?>&classe=<?= $id_classe ?>&ordre=merite<?= $id_serie?'&serie='.$id_serie:'' ?><?= $id_eleve?'&eleve='.$id_eleve:'' ?>"
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
<?php $url_cl = url_classe_bull($id_classe, $vue, $id_trim, $id_annee, $ordre, false, $id_serie); ?>
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
      <a href="?vue=<?= $vue ?>&classe=<?= $id_classe ?>&ordre=<?= $ordre ?><?= $id_serie?"&serie=$id_serie":"" ?>"
         class="btn btn-sm btn-abz-outline"><i class="bi bi-list me-1"></i>Retour liste</a>
      <a id="lienDlCl" href="<?= h(url_classe_bull($id_classe,$vue,$id_trim,$id_annee,$ordre,true,$id_serie)) ?>"
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
  const baseDl = <?= json_encode(url_classe_bull($id_classe,$vue,$id_trim,$id_annee,$ordre,true,$id_serie)) ?>;
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
$url_pdf   = pdf_url_bull($id_eleve, $vue, $id_trim, $id_annee);
$url_dl    = pdf_url_bull($id_eleve, $vue, $id_trim, $id_annee, true);
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
      <a href="<?= h($back_url) ?>" class="btn btn-sm btn-abz-outline"><i class="bi bi-arrow-left me-1"></i>Retour</a>
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
      <a href="?vue=<?= $vue ?>&classe=<?= $id_classe ?>&ordre=<?= $ordre ?><?= $id_serie?"&serie=$id_serie":"" ?>&voir_tous=1"
         class="btn btn-sm btn-abz-outline">
        <i class="bi bi-eye me-1"></i>Visualiser tous les bulletins
      </a>
      <a href="<?= h(url_classe_bull($id_classe,$vue,$id_trim,$id_annee,$ordre,true,$id_serie)) ?>"
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
        $url_d  = pdf_url_bull($el['id'], $vue, $id_trim, $id_annee, true);
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
            <a href="<?= h($url_ap) ?>" class="text-decoration-none fw-semibold" style="color:#1a3c6b">
              <?= h(strtoupper($el['nom']).' '.($el['prenom']??'')) ?>
            </a>
          </td>
          <td style="font-size:.78rem"><?= h($naiss) ?></td>
          <td><?= h($el['sexe'] ?? '—') ?></td>
          <td class="<?= $cls_moy ?>"><?= $fmt($moy) ?></td>
          <td class="text-center fw-semibold"><?= $rang !== null ? $rang.'e' : '—' ?></td>
          <td class="text-center">
            <a href="<?= h($url_ap) ?>" class="btn btn-sm btn-abz-primary"
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

<?php require_once __DIR__ . '/../../../layout/footer.php'; ?>