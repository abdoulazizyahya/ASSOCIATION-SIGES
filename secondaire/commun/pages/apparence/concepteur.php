<?php
// secondaire/commun/pages/apparence/concepteur.php — Concepteur de modèles
// (11/11/2026), primaire et secondaire : l'école dessine elle-même sa carte
// scolaire, son badge du personnel ou sa couverture de bulletin. Chaque
// élément (texte, logo, photo, QR, rectangle, image) se sélectionne, se
// déplace et se redimensionne à la souris ; le texte se colore mot par mot
// (gras, italique, souligné, couleur) et contient des variables remplies à
// l'impression. Rendu PDF : noyau/partage/gabarits.php.
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
require_once __DIR__ . '/../../../../noyau/partage/gabarits.php';
require_once __DIR__ . '/../../../../noyau/partage/apparence_docs.php';

$sec = ed_secondaire();
exiger_role($sec ? ['ADMIN', 'PROVISEUR', 'FONDATEUR'] : ['DIRECTEUR', 'FONDATEUR']);
$types = gab_types();
$type = isset($types[$_REQUEST['type'] ?? '']) ? $_REQUEST['type'] : 'carte';
$T = $types[$type];

// ── Actions (fetch) ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';
    if ($action === 'brouillon') {   // aperçu : seulement en session, rien n'est enregistré
        if (!hash_equals($_SESSION['csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) { echo json_encode(['erreur' => 'Session expirée, rechargez la page.']); exit; }
        $_SESSION['gab_brouillon'][$type] = gab_nettoyer($type, json_decode((string) ($_POST['gabarit'] ?? ''), true) ?: []);
        echo json_encode(['ok' => 1]); exit;
    }
    csrf_verifier();
    if ($action === 'televerser') { echo json_encode(gab_televerser($_FILES['image'] ?? [])); exit; }
    if ($action === 'reinitialiser') { gab_enregistrer($type, null); echo json_encode(['ok' => 1, 'gabarit' => gab_depart($type)]); exit; }
    if ($action === 'enregistrer') {
        $g = gab_nettoyer($type, json_decode((string) ($_POST['gabarit'] ?? ''), true) ?: []);
        if (!$g['elements']) { echo json_encode(['erreur' => 'Le modèle est vide.']); exit; }
        gab_enregistrer($type, $g);
        $msg = 'Modèle enregistré.';
        if (!empty($_POST['defaut'])) {
            if ($type === 'carte') { $r = app_reglages('cartes'); $r['modele'] = 6; app_enregistrer('cartes', $r); $msg .= ' Il est maintenant proposé par défaut pour les cartes.'; }
            if ($type === 'couverture') { $r = ed_reglages('couvertures', []); $r['modele'] = 'perso'; ed_reglages_enregistrer('couvertures', $r); $msg .= ' Il est maintenant le modèle des couvertures.'; }
        }
        if ($type === 'badge') $msg .= ' Les badges du Centre d\'impression l\'utilisent désormais.';
        echo json_encode(['ok' => 1, 'message' => $msg]); exit;
    }
    echo json_encode(['erreur' => 'Action inconnue.']); exit;
}

// ── Données de l'éditeur ────────────────────────────────────────────
$gab = gab_charger($type);
$etab = etab_pour_pdf(get_etablissement());
$an = ed_annee();
$vars = gab_vars_ecole($etab, $an['libelle']);
$premier = null;
foreach (ed_classes() as $c) { $l = ed_eleves((int) $c['id']); if ($l) { $premier = $l[0] + ['id_classe' => $c['id']]; break; } }
if ($type === 'badge') { $p = ed_personnel()[0] ?? null; $vars += $p ? gab_vars_personnel($p) : []; }
elseif ($premier) $vars += gab_vars_eleve($premier);
foreach (array_keys($T[3]) as $k) $vars[$k] = $vars[$k] ?? '';

$images = [];
foreach (glob(gab_dossier() . '*.{png,jpg}', GLOB_BRACE) ?: [] as $f) $images[basename($f)] = APP_URL . '/assets/uploads/gabarits/' . basename(dirname($f)) . '/' . basename($f);
$logo_url = !empty($etab['logo']) && is_file(__DIR__ . '/../../../../assets/uploads/' . $etab['logo']) ? APP_URL . '/assets/uploads/' . $etab['logo'] : '';
$avatar_url = APP_URL . '/assets/img/avatars/' . (($premier['sexe'] ?? 'M') === 'F' ? 'fille.png' : 'garcon.png');

if ($type === 'carte') {
    $apercu = $premier ? ($sec ? APP_URL . '/secondaire/commun/pdf/cartes.php?annee=' . $an['id'] . '&eleve=' . (int) $premier['id']
                               : APP_URL . '/primaire/pdf/cartes.php?id=' . (int) $premier['id']) . '&modele=6&gab_brouillon=1' : '';
} elseif ($type === 'bulletin') {
    require_once __DIR__ . '/../analyse/_analyse_lib.php';
    $trims_g = array_values(array_filter(an_periodes(), fn($p) => $p['cle'] !== 'annee'));
    $id_trim_g = (int) ($trims_g[0]['id'] ?? 0);
    $apercu = ($premier && $id_trim_g) ? ($sec
        ? APP_URL . '/secondaire/' . (est_technique() ? 'technique' : 'general') . '/pages/bulletins/pdf.php?eleve=' . (int) $premier['id'] . '&annee=' . $an['id'] . '&trim=' . $id_trim_g
        : APP_URL . '/primaire/pdf/bulletin_trimestriel.php?id=' . (int) $premier['id'] . '&trim=' . $id_trim_g) . '&gab_brouillon=1' : '';
    $vars = ['titre' => $sec ? 'BULLETIN SCOLAIRE DU PREMIER TRIMESTRE' : 'BULLETIN DE NOTES', 'titre_en' => $sec ? 'First Term Report Card' : 'REPORT CARD',
        'periode' => $sec ? 'PREMIER TRIMESTRE' : '1er Trimestre', 'periode_en' => '1st Term', 'effectif' => (string) count(ed_eleves((int) ($premier['id_classe'] ?? 0))),
        'redoublant' => 'Non', 'titulaire' => '(titulaire)', 'parents' => '(parents)'] + $vars;
} elseif ($type === 'badge') {
    $apercu = APP_URL . '/secondaire/commun/pages/impression/pdf.php?doc=badges&gab_brouillon=1';
} else {
    $apercu = APP_URL . '/secondaire/commun/pages/couvertures/pdf.php?' . ($premier ? 'classe=' . (int) $premier['id_classe'] . '&apercu=1' : 'vierge=1') . '&gab_brouillon=1';
}
foreach (array_keys($T[3]) as $k) $vars[$k] = $vars[$k] ?? '';
$polices_css = array_map(fn($p) => APP_POLICES[$p][1], array_combine(GAB_POLICES, GAB_POLICES));
$polices_lib = ['helvetica' => 'Arial / Helvetica', 'robotoc' => 'Roboto condensée', 'times' => 'Times', 'courier' => 'Courier'];

$titre_page = 'Concepteur — ' . $T[0];
require_once __DIR__ . '/../../../../noyau/layout/header.php';
?>
<style>
.cz-barre{display:flex;flex-wrap:wrap;gap:6px;align-items:center}
.cz-barre .btn{font-size:.8rem}
.cz-zone{background:#e9edf3;border-radius:12px;padding:24px;overflow:auto;min-height:420px;display:flex;justify-content:center;align-items:flex-start;position:relative}
#cz-toile{position:relative;background:#fff;box-shadow:0 6px 24px rgba(26,39,68,.18);flex-shrink:0;overflow:hidden;user-select:none}
.cz-el{position:absolute;box-sizing:border-box;cursor:move}
.cz-el:hover{outline:1px dashed rgba(200,150,10,.8)}
.cz-el.sel{outline:2px solid #c8960a;z-index:1000}
.cz-el.edition{cursor:text;outline:2px solid #1e7c50;user-select:text}
.cz-el .cz-txt{width:100%;height:100%;display:flex;flex-direction:column;overflow:hidden;padding:0 .4mm;box-sizing:border-box;white-space:pre-wrap;word-break:break-word}
.cz-el .cz-txt>div{outline:none}
.cz-el img{width:100%;height:100%;pointer-events:none;display:block}
.cz-poignee{position:absolute;width:10px;height:10px;background:#fff;border:2px solid #c8960a;border-radius:2px;z-index:1001}
.cz-qr{width:100%;height:100%;background:repeating-conic-gradient(#1a2744 0 25%,#fff 0 50%) 0 0/20% 20%;opacity:.85}
.cz-photo{width:100%;height:100%;background:#e5e7eb center/contain no-repeat}
.cz-guide{position:absolute;background:#e11d48;z-index:999;pointer-events:none}
.cz-panneau{font-size:.82rem}
.cz-panneau label{font-size:.74rem;color:#6b7280;margin-bottom:1px}
.cz-panneau .form-control,.cz-panneau .form-select{font-size:.8rem;padding:.2rem .45rem}
.cz-calques{max-height:230px;overflow:auto}
.cz-calques .item{display:flex;align-items:center;gap:6px;padding:4px 6px;border-radius:6px;cursor:pointer;font-size:.78rem}
.cz-calques .item:hover{background:#f3f6fb}.cz-calques .item.sel{background:#fbf1d6}
.cz-var{font-size:.72rem;padding:2px 6px;border-radius:10px;background:#e8edf7;color:#1a2744;border:0;margin:2px}
.cz-var:hover{background:#1a2744;color:#fff}
#cz-barre-texte{display:none}
#cz-barre-texte.visible{display:flex}
</style>

<div class="page-titre d-flex flex-wrap align-items-center gap-2">
  <div>
    <h4><i class="bi bi-vector-pen me-1 text-primary"></i>Concepteur de modèles</h4>
    <div class="sub">Cliquez un élément pour le sélectionner, faites-le glisser pour le déplacer, tirez le coin pour le redimensionner. Double-cliquez un texte pour l'écrire et colorer les mots.</div>
  </div>
  <a href="<?= APP_URL ?>/secondaire/commun/pages/apparence/index.php?onglet=<?= $type === 'carte' ? 'cartes' : 'documents' ?>" class="btn btn-outline-secondary btn-sm ms-auto"><i class="bi bi-arrow-left me-1"></i>Apparence des documents</a>
</div>

<ul class="nav nav-tabs mb-3" style="border-bottom:2px solid #e5e7eb">
  <?php foreach ($types as $k => $t): ?>
  <li class="nav-item"><a class="nav-link <?= $type === $k ? 'active' : '' ?>" href="?type=<?= $k ?>"><?= h($t[0]) ?><?= gab_existe($k) ? ' <i class="bi bi-check-circle-fill text-success" title="Modèle personnalisé enregistré"></i>' : '' ?></a></li>
  <?php endforeach; ?>
</ul>

<div class="row g-3">
  <div class="col-xl-8">
    <div class="card"><div class="card-body">
      <div class="cz-barre mb-2">
        <span class="text-muted me-1" style="font-size:.76rem">Ajouter :</span>
        <button class="btn btn-outline-primary btn-sm" data-ajout="texte"><i class="bi bi-fonts me-1"></i>Texte</button>
        <button class="btn btn-outline-primary btn-sm" data-ajout="logo"><i class="bi bi-shield me-1"></i>Logo</button>
        <?php if (isset($T[4]['photo'])): ?><button class="btn btn-outline-primary btn-sm" data-ajout="photo"><i class="bi bi-person-square me-1"></i>Photo</button><?php endif; ?>
        <?php if (isset($T[4]['qr'])): ?><button class="btn btn-outline-primary btn-sm" data-ajout="qr"><i class="bi bi-qr-code me-1"></i>QR</button><?php endif; ?>
        <button class="btn btn-outline-primary btn-sm" data-ajout="rect"><i class="bi bi-square me-1"></i>Forme</button>
        <button class="btn btn-outline-primary btn-sm" data-ajout="fichier"><i class="bi bi-image me-1"></i>Image</button>
        <span class="ms-auto"></span>
        <button class="btn btn-light btn-sm" id="cz-annuler" title="Annuler (Ctrl+Z)"><i class="bi bi-arrow-counterclockwise"></i></button>
        <button class="btn btn-light btn-sm" id="cz-retablir" title="Rétablir (Ctrl+Y)"><i class="bi bi-arrow-clockwise"></i></button>
        <button class="btn btn-light btn-sm" id="cz-zoom-moins" title="Zoom arrière"><i class="bi bi-zoom-out"></i></button>
        <button class="btn btn-light btn-sm" id="cz-zoom-plus" title="Zoom avant"><i class="bi bi-zoom-in"></i></button>
        <div class="form-check form-switch ms-1 mb-0"><input class="form-check-input" type="checkbox" id="cz-donnees" checked><label class="form-check-label" for="cz-donnees" style="font-size:.76rem">Données réelles</label></div>
      </div>

      <div class="cz-barre mb-2 p-2 rounded" id="cz-barre-texte" style="background:#f0f7f3;border:1px solid #cfe7da">
        <span style="font-size:.76rem;color:#1e7c50"><i class="bi bi-cursor-text me-1"></i>Sélectionnez des mots puis :</span>
        <button class="btn btn-light btn-sm fw-bold" data-cmd="bold" title="Gras">G</button>
        <button class="btn btn-light btn-sm fst-italic" data-cmd="italic" title="Italique">I</button>
        <button class="btn btn-light btn-sm text-decoration-underline" data-cmd="underline" title="Souligné">S</button>
        <input type="color" id="cz-couleur-mots" class="form-control form-control-color form-control-sm" value="#c0392b" title="Couleur des mots sélectionnés">
        <button class="btn btn-light btn-sm" id="cz-appliquer-couleur" title="Appliquer la couleur aux mots sélectionnés"><i class="bi bi-palette me-1"></i>Colorer</button>
        <select id="cz-var-insert" class="form-select form-select-sm" style="max-width:190px"><option value="">+ Insérer une donnée…</option><?php foreach ($T[3] as $k => $lib): ?><option value="<?= $k ?>"><?= h($lib) ?></option><?php endforeach; ?></select>
        <button class="btn btn-success btn-sm ms-auto" id="cz-fin-edition"><i class="bi bi-check2 me-1"></i>Terminé</button>
      </div>

      <div class="cz-zone" id="cz-zone"><div id="cz-toile"></div></div>

      <div class="d-flex flex-wrap gap-2 mt-3">
        <button class="btn btn-outline-primary btn-sm" id="cz-apercu" <?= $apercu ? '' : 'disabled' ?>><i class="bi bi-file-earmark-pdf me-1"></i>Aperçu PDF réel</button>
        <button class="btn btn-primary btn-sm" id="cz-enregistrer"><i class="bi bi-check2 me-1"></i>Enregistrer</button>
        <?php if (!in_array($type, ['badge', 'bulletin'], true)): ?>
        <button class="btn btn-primary btn-sm" id="cz-enregistrer-defaut"><i class="bi bi-check2-all me-1"></i>Enregistrer et utiliser par défaut</button>
        <?php endif; ?>
        <button class="btn btn-outline-secondary btn-sm ms-auto" id="cz-depart"><i class="bi bi-arrow-repeat me-1"></i>Repartir du modèle de départ</button>
      </div>
      <div class="text-muted mt-2" style="font-size:.75rem">
        <?php if ($type === 'carte'): ?>Utilisation : Élèves → Cartes scolaires → « Modèle 6 — Mon modèle ». Taille de conception : carte bancaire 85,6 × 54 mm (mise à l'échelle des autres formats).
        <?php elseif ($type === 'badge'): ?>Utilisation : Scolarité → Centre d'impression → Personnel → Badges (10 par page A4) — dès que ce modèle est enregistré.
        <?php elseif ($type === 'bulletin'): ?>Utilisation : dès qu'il est enregistré, ce haut remplace l'en-tête, le titre et l'identité de l'élève sur les bulletins trimestriels<?= $sec ? ' (le tableau des notes commence juste en dessous : réglez la hauteur de la zone)' : ' (zone fixe de 200 × 63 mm au-dessus du tableau des compétences)' ?>. Le QR placé ici remplace celui du bas de page. « Repartir du modèle de départ » rend le bulletin d'origine.
        <?php else: ?>Utilisation : Pédagogie → Couvertures de bulletins → modèle « Mon modèle ». Conçu en A4, mis à l'échelle en A5.<?php endif; ?>
        Raccourcis : Suppr = supprimer, flèches = déplacer (Maj = 2 mm), Ctrl+D = dupliquer, Ctrl+Z = annuler.
      </div>
    </div></div>
  </div>

  <div class="col-xl-4">
    <div class="card mb-3"><div class="card-body cz-panneau" id="cz-props"></div></div>
    <div class="card"><div class="card-body cz-panneau">
      <div class="section-titre"><i class="bi bi-layers me-1"></i>Éléments (du fond vers l'avant)</div>
      <div class="cz-calques" id="cz-calques"></div>
    </div></div>
  </div>
</div>
<input type="file" id="cz-fichier" accept="image/png,image/jpeg" class="d-none">

<script>
(function () {
  'use strict';
  var TYPE = <?= json_encode($type) ?>;
  var G = <?= json_encode($gab, JSON_UNESCAPED_UNICODE) ?>;
  var VARS = <?= json_encode($vars, JSON_UNESCAPED_UNICODE) ?>;
  var VARLIB = <?= json_encode($T[3], JSON_UNESCAPED_UNICODE) ?>;
  var SOURCES = <?= json_encode($T[4], JSON_UNESCAPED_UNICODE) ?>;
  var IMGS = <?= json_encode((object) $images) ?>;
  var LOGO = <?= json_encode($logo_url) ?>, AVATAR = <?= json_encode($avatar_url) ?>;
  var POLICES = <?= json_encode($polices_css) ?>, POLICES_LIB = <?= json_encode($polices_lib, JSON_UNESCAPED_UNICODE) ?>;
  var CSRF = <?= json_encode(csrf_generer()) ?>;
  var URL_ICI = <?= json_encode(APP_URL . '/secondaire/commun/pages/apparence/concepteur.php?type=' . $type) ?>;
  var APERCU = <?= json_encode($apercu) ?>;
  var HAUTEUR_LIBRE = <?= json_encode($type === 'bulletin' && $sec) ?>;
  var Z = TYPE === 'couverture' ? 2.3 : 7.5;      // pixels par millimètre
  var sel = null, edition = null, histo = [], futur = [], compteur = 1;
  var toile = document.getElementById('cz-toile');
  var PT = 0.3528;                                 // 1 point = 0,3528 mm

  function h(s) { return String(s).replace(/[&<>"]/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]; }); }
  function idNouveau() { var id; do { id = 'n' + (compteur++); } while (G.elements.some(function (e) { return e.id === id; })); return id; }
  function trouver(id) { return G.elements.find(function (e) { return e.id === id; }); }
  function memo() { histo.push(JSON.stringify(G)); if (histo.length > 60) histo.shift(); futur = []; }
  function remplacer(t) { return document.getElementById('cz-donnees').checked ? t.replace(/\{([a-z_]+)\}/g, function (m, k) { return k in VARS ? (VARS[k] || '…') : m; }) : t; }

  // ── Texte riche : runs <-> HTML ──
  function runsVersHtml(runs, maj, donnees) {
    return runs.map(function (r) {
      var t = donnees ? remplacer(r.t) : r.t; if (maj && donnees) t = t.toUpperCase();
      var st = 'color:' + r.c + ';' + (r.b ? 'font-weight:700;' : 'font-weight:400;') + (r.i ? 'font-style:italic;' : '') + (r.u ? 'text-decoration:underline;' : '');
      return '<span style="' + st + '">' + h(t).replace(/\n/g, '<br>') + '</span>';
    }).join('');
  }
  function hex(c) {
    if (!c) return null; if (c[0] === '#') return c.length === 4 ? '#' + c[1] + c[1] + c[2] + c[2] + c[3] + c[3] : c.toLowerCase();
    var m = c.match(/(\d+),\s*(\d+),\s*(\d+)/); if (!m) return null;
    return '#' + [m[1], m[2], m[3]].map(function (v) { return ('0' + (+v).toString(16)).slice(-2); }).join('');
  }
  function htmlVersRuns(racine, defaut) {
    var runs = [];
    function pousser(t, s) { if (!t) return; var d = runs[runs.length - 1]; if (d && d.c === s.c && d.b === s.b && d.i === s.i && d.u === s.u) d.t += t; else runs.push({t: t, c: s.c, b: s.b, i: s.i, u: s.u}); }
    function marcher(n, s, premier) {
      if (n.nodeType === 3) { pousser(n.nodeValue.replace(/ /g, ' '), s); return; }
      if (n.nodeType !== 1) return;
      var tag = n.tagName;
      if (tag === 'BR') { pousser('\n', s); return; }
      var s2 = {c: s.c, b: s.b, i: s.i, u: s.u}, st = n.style || {};
      if (tag === 'B' || tag === 'STRONG') s2.b = 1;
      if (tag === 'I' || tag === 'EM') s2.i = 1;
      if (tag === 'U') s2.u = 1;
      if (n.getAttribute && n.getAttribute('color')) s2.c = hex(n.getAttribute('color')) || s2.c;
      if (st.color) s2.c = hex(st.color) || s2.c;
      if (st.fontWeight) s2.b = (st.fontWeight === 'bold' || +st.fontWeight >= 600) ? 1 : 0;
      if (st.fontStyle) s2.i = st.fontStyle === 'italic' ? 1 : 0;
      if (st.textDecoration || st.textDecorationLine) s2.u = /underline/.test(st.textDecoration + ' ' + st.textDecorationLine) ? 1 : 0;
      var bloc = (tag === 'DIV' || tag === 'P') && !premier;
      if (bloc && runs.length && !/\n$/.test(runs[runs.length - 1].t)) pousser('\n', s);
      for (var i = 0; i < n.childNodes.length; i++) marcher(n.childNodes[i], s2, false);
    }
    marcher(racine, defaut, true);
    if (runs.length) { var d = runs[runs.length - 1]; d.t = d.t.replace(/\n+$/, ''); if (!d.t) runs.pop(); }
    return runs;
  }

  // ── Dessin de la toile ──
  function dessiner() {
    toile.style.width = (G.largeur * Z) + 'px'; toile.style.height = (G.hauteur * Z) + 'px';
    toile.style.background = (G.fond || '#fff') + (G.image_fond && IMGS[G.image_fond] ? ' url("' + IMGS[G.image_fond] + '") 0 0/100% 100% no-repeat' : '');
    toile.innerHTML = '';
    G.elements.forEach(function (e) { toile.appendChild(dessinerEl(e)); });
    poignees(); calques(); proprietes();
  }
  function dessinerEl(e) {
    var d = document.createElement('div');
    d.className = 'cz-el' + (sel === e.id ? ' sel' : '') + (edition === e.id ? ' edition' : '');
    d.dataset.id = e.id;
    d.style.left = (e.x * Z) + 'px'; d.style.top = (e.y * Z) + 'px'; d.style.width = (e.w * Z) + 'px'; d.style.height = (e.h * Z) + 'px';
    if (e.type === 'rect') {
      d.style.background = e.fond || 'transparent';
      d.style.border = e.bord ? Math.max(1, e.ep * Z) + 'px solid ' + e.bord : (e.fond ? 'none' : '1px dashed #bbb');
    } else if (e.type === 'image') {
      if (e.bord) d.style.border = '1px solid ' + e.bord;
      var u = e.source === 'logo' ? LOGO : (e.source === 'fichier' ? IMGS[e.fichier] : '');
      if (e.source === 'qr') d.innerHTML = '<div class="cz-qr"></div>';
      else if (e.source === 'photo') d.innerHTML = '<div class="cz-photo" style="background-image:url(\'' + AVATAR + '\')"></div>';
      else d.innerHTML = u ? '<img src="' + u + '" style="object-fit:' + (e.ajust === 'etirer' ? 'fill' : 'contain') + '">' : '<div class="cz-photo d-flex align-items-center justify-content-center text-muted" style="font-size:11px">' + (e.source === 'logo' ? 'Logo' : 'Image') + '</div>';
    } else {
      if (e.fond) d.style.background = e.fond;
      if (e.bord) d.style.border = '1px solid ' + e.bord;
      var t = document.createElement('div'); t.className = 'cz-txt';
      t.style.justifyContent = {T: 'flex-start', M: 'center', B: 'flex-end'}[e.valign];
      var c = document.createElement('div');
      c.style.fontFamily = POLICES[e.police]; c.style.fontSize = (e.taille * PT * Z) + 'px';
      c.style.lineHeight = e.interligne; c.style.textAlign = {L: 'left', C: 'center', R: 'right'}[e.align];
      var enEdition = edition === e.id;
      c.innerHTML = runsVersHtml(e.runs, e.maj, !enEdition);
      if (e.maj && !enEdition) c.style.textTransform = 'uppercase';
      if (enEdition) { c.contentEditable = 'true'; c.spellcheck = false; }
      t.appendChild(c); d.appendChild(t);
      if (!enEdition && e.ajuster) setTimeout(function () { ajusterApercu(c, t, e); }, 0);
    }
    return d;
  }
  // Réduit la police à l'écran comme le fera le PDF (option « ajuster »).
  function ajusterApercu(c, t, e) {
    var taille = e.taille, min = e.taille * 0.35;
    while (c.scrollHeight > t.clientHeight + 1 && taille > min) { taille -= 0.25; c.style.fontSize = (taille * PT * Z) + 'px'; }
  }
  function poignees() {
    toile.querySelectorAll('.cz-poignee').forEach(function (p) { p.remove(); });
    var e = sel && trouver(sel); if (!e || edition) return;
    [['se', 1, 1], ['e', 1, .5], ['s', .5, 1], ['nw', 0, 0], ['ne', 1, 0], ['sw', 0, 1]].forEach(function (p) {
      var q = document.createElement('div'); q.className = 'cz-poignee'; q.dataset.sens = p[0];
      q.style.left = (e.x * Z + e.w * Z * p[1] - 5) + 'px'; q.style.top = (e.y * Z + e.h * Z * p[2] - 5) + 'px';
      q.style.cursor = p[0] + '-resize'; toile.appendChild(q);
    });
  }
  function libelle(e) {
    if (e.type === 'texte') { var t = e.runs.map(function (r) { return r.t; }).join('').replace(/\n/g, ' '); return '<i class="bi bi-fonts me-1"></i>' + h(t.slice(0, 32) || '(texte vide)'); }
    if (e.type === 'image') return '<i class="bi bi-image me-1"></i>' + h(SOURCES[e.source] || 'Image');
    return '<i class="bi bi-square me-1"></i>Forme ' + (e.fond ? '<span style="display:inline-block;width:10px;height:10px;background:' + e.fond + ';border-radius:2px"></span>' : '');
  }
  function calques() {
    var z = document.getElementById('cz-calques');
    z.innerHTML = G.elements.map(function (e) { return '<div class="item' + (sel === e.id ? ' sel' : '') + '" data-id="' + e.id + '">' + libelle(e) + '</div>'; }).reverse().join('');
    z.querySelectorAll('.item').forEach(function (i) { i.onclick = function () { choisir(i.dataset.id); }; });
  }

  // ── Panneau des propriétés ──
  function champ(lib, html, col) { return '<div class="' + (col || 'col-6') + '"><label class="form-label d-block">' + lib + '</label>' + html + '</div>'; }
  function num(k, v, pas) { return '<input type="number" class="form-control" data-p="' + k + '" step="' + (pas || 0.5) + '" value="' + (+v).toFixed(1).replace(/\.0$/, '') + '">'; }
  function couleur(k, v, aucun) {
    return '<div class="d-flex align-items-center gap-1"><input type="color" class="form-control form-control-color form-control-sm" data-p="' + k + '" value="' + (v || '#ffffff') + '"' + (aucun && !v ? ' style="opacity:.35"' : '') + '>'
      + (aucun ? '<div class="form-check mb-0"><input class="form-check-input" type="checkbox" data-aucun="' + k + '"' + (!v ? ' checked' : '') + '><label class="form-check-label" style="font-size:.72rem">aucune</label></div>' : '') + '</div>';
  }
  function proprietes() {
    var p = document.getElementById('cz-props'), e = sel && trouver(sel);
    if (!e) {
      p.innerHTML = '<div class="section-titre"><i class="bi bi-file-earmark me-1"></i>Document — ' + h(<?= json_encode($T[0]) ?>) + ' (' + G.largeur + ' × ' + G.hauteur + ' mm)</div>'
        + '<div class="row g-2">' + (TYPE === 'bulletin' && HAUTEUR_LIBRE ? champ('Hauteur de la zone (mm)', num('doc.hauteur', G.hauteur, 1), 'col-12') : '') + champ('Couleur de fond', couleur('doc.fond', G.fond || '#ffffff'))
        + champ('Image de fond', '<div class="d-flex gap-1"><button class="btn btn-outline-secondary btn-sm" id="cz-fond-img"><i class="bi bi-upload me-1"></i>Choisir</button>' + (G.image_fond ? '<button class="btn btn-outline-danger btn-sm" id="cz-fond-suppr" title="Retirer"><i class="bi bi-x"></i></button>' : '') + '</div>') + '</div>'
        + '<div class="text-muted mt-3" style="font-size:.76rem"><i class="bi bi-hand-index me-1"></i>Cliquez un élément du modèle pour le modifier. Les données entre accolades (ex. <code>{nom_complet}</code>) sont remplacées à l\'impression par celles de chaque ' + (TYPE === 'badge' ? 'membre du personnel' : 'élève') + '.</div>';
      lier(p); return;
    }
    var html = '<div class="d-flex align-items-center mb-2"><div class="section-titre mb-0">' + libelle(e) + '</div></div>'
      + '<div class="d-flex flex-wrap gap-1 mb-2">'
      + '<button class="btn btn-light btn-sm" data-act="avant" title="Mettre devant"><i class="bi bi-front"></i></button>'
      + '<button class="btn btn-light btn-sm" data-act="arriere" title="Mettre derrière"><i class="bi bi-back"></i></button>'
      + '<button class="btn btn-light btn-sm" data-act="centreh" title="Centrer horizontalement"><i class="bi bi-distribute-horizontal"></i></button>'
      + '<button class="btn btn-light btn-sm" data-act="centrev" title="Centrer verticalement"><i class="bi bi-distribute-vertical"></i></button>'
      + '<button class="btn btn-light btn-sm" data-act="dupliquer" title="Dupliquer (Ctrl+D)"><i class="bi bi-copy"></i></button>'
      + '<button class="btn btn-outline-danger btn-sm ms-auto" data-act="supprimer" title="Supprimer (Suppr)"><i class="bi bi-trash"></i></button></div>'
      + '<div class="row g-2">' + champ('X (mm)', num('x', e.x), 'col-3') + champ('Y (mm)', num('y', e.y), 'col-3') + champ('Largeur', num('w', e.w), 'col-3') + champ('Hauteur', num('h', e.h), 'col-3') + '</div>';
    if (e.type === 'texte') {
      html += '<div class="d-grid my-2"><button class="btn btn-success btn-sm" data-act="editer"><i class="bi bi-pencil me-1"></i>Écrire / colorer des mots (double-clic)</button></div>'
        + '<div class="row g-2">'
        + champ('Police', '<select class="form-select" data-p="police">' + Object.keys(POLICES_LIB).map(function (k) { return '<option value="' + k + '"' + (e.police === k ? ' selected' : '') + ' style="font-family:' + POLICES[k] + '">' + POLICES_LIB[k] + '</option>'; }).join('') + '</select>', 'col-8')
        + champ('Taille (pt)', num('taille', e.taille, 0.5), 'col-4')
        + champ('Alignement', '<div class="btn-group w-100">' + [['L', 'left'], ['C', 'center'], ['R', 'right']].map(function (a) { return '<button class="btn btn-sm ' + (e.align === a[0] ? 'btn-primary' : 'btn-light') + '" data-set="align" data-v="' + a[0] + '"><i class="bi bi-text-' + a[1] + '"></i></button>'; }).join('') + '</div>')
        + champ('Vertical', '<div class="btn-group w-100">' + [['T', 'Haut'], ['M', 'Milieu'], ['B', 'Bas']].map(function (a) { return '<button class="btn btn-sm ' + (e.valign === a[0] ? 'btn-primary' : 'btn-light') + '" data-set="valign" data-v="' + a[0] + '" style="font-size:.7rem">' + a[1] + '</button>'; }).join('') + '</div>')
        + champ('Interligne', num('interligne', e.interligne, 0.05), 'col-4')
        + champ('Couleur de tout le texte', '<input type="color" class="form-control form-control-color form-control-sm" data-tout="couleur" value="' + ((e.runs[0] || {}).c || '#000000') + '">', 'col-4')
        + champ('Tout en', '<div class="btn-group"><button class="btn btn-light btn-sm fw-bold" data-tout="b">G</button><button class="btn btn-light btn-sm fst-italic" data-tout="i">I</button></div>', 'col-4')
        + champ('Fond', couleur('fond', e.fond, true)) + champ('Bordure', couleur('bord', e.bord, true))
        + '</div><div class="form-check mt-2"><input class="form-check-input" type="checkbox" data-p="ajuster"' + (e.ajuster ? ' checked' : '') + ' id="cz-aj"><label class="form-check-label" for="cz-aj" style="font-size:.78rem">Réduire le texte s\'il dépasse (noms longs)</label></div>'
        + '<div class="form-check"><input class="form-check-input" type="checkbox" data-p="maj"' + (e.maj ? ' checked' : '') + ' id="cz-maj"><label class="form-check-label" for="cz-maj" style="font-size:.78rem">TOUT EN MAJUSCULES</label></div>'
        + '<label class="form-label d-block mt-2">Ajouter une donnée à la fin du texte</label><div>' + Object.keys(VARLIB).map(function (k) { return '<button class="cz-var" data-var="' + k + '" title="' + h(VARLIB[k]) + '">{' + k + '}</button>'; }).join('') + '</div>';
    } else if (e.type === 'image') {
      html += '<div class="row g-2 mt-1">'
        + champ('Contenu', '<select class="form-select" data-p="source">' + Object.keys(SOURCES).map(function (k) { return '<option value="' + k + '"' + (e.source === k ? ' selected' : '') + '>' + h(SOURCES[k]) + '</option>'; }).join('') + '</select>', 'col-7')
        + champ('Ajustement', '<select class="form-select" data-p="ajust"><option value="contenir"' + (e.ajust !== 'etirer' ? ' selected' : '') + '>Proportions gardées</option><option value="etirer"' + (e.ajust === 'etirer' ? ' selected' : '') + '>Remplir le cadre</option></select>', 'col-5')
        + (e.source === 'fichier' ? champ('Image', '<div class="d-flex flex-wrap gap-1">' + Object.keys(IMGS).map(function (f) { return '<img src="' + IMGS[f] + '" data-img="' + f + '" style="width:42px;height:42px;object-fit:contain;border:2px solid ' + (e.fichier === f ? '#c8960a' : '#e5e7eb') + ';border-radius:6px;cursor:pointer;background:#fff">'; }).join('') + '<button class="btn btn-outline-secondary btn-sm" data-act="televerser"><i class="bi bi-upload me-1"></i>Téléverser</button></div>', 'col-12') : '')
        + champ('Bordure', couleur('bord', e.bord, true)) + '</div>';
    } else {
      html += '<div class="row g-2 mt-1">' + champ('Remplissage', couleur('fond', e.fond, true)) + champ('Contour', couleur('bord', e.bord, true)) + champ('Épaisseur du contour (mm)', num('ep', e.ep, 0.1), 'col-6') + '</div>'
        + '<div class="text-muted mt-2" style="font-size:.74rem">Astuce : une forme très fine (hauteur 0,5 mm) fait un trait ou un filet.</div>';
    }
    p.innerHTML = html; lier(p);
  }
  function lier(p) {
    p.querySelectorAll('[data-p]').forEach(function (i) {
      i.addEventListener(i.type === 'number' ? 'change' : (i.type === 'color' ? 'input' : 'change'), function () {
        var k = i.dataset.p;
        if (k.indexOf('doc.') === 0) { memo(); G[k.slice(4)] = i.type === 'number' ? Math.max(30, Math.min(150, parseFloat(i.value) || 58)) : i.value; dessiner(); return; }
        var e = trouver(sel); if (!e) return; memo();
        if (i.type === 'checkbox') e[k] = i.checked ? 1 : 0;
        else if (i.type === 'number') e[k] = parseFloat(i.value) || 0;
        else e[k] = i.value;
        if (k === 'source' && e.source === 'fichier' && !e.fichier) e.fichier = Object.keys(IMGS)[0] || '';
        var aucun = p.querySelector('[data-aucun="' + k + '"]'); if (aucun) aucun.checked = false;
        rafraichir(k === 'source');
      });
    });
    p.querySelectorAll('[data-aucun]').forEach(function (c) { c.onchange = function () { var e = trouver(sel); memo(); e[c.dataset.aucun] = c.checked ? '' : (p.querySelector('[data-p="' + c.dataset.aucun + '"]').value); rafraichir(true); }; });
    p.querySelectorAll('[data-set]').forEach(function (b) { b.onclick = function () { var e = trouver(sel); memo(); e[b.dataset.set] = b.dataset.v; rafraichir(true); }; });
    p.querySelectorAll('[data-tout]').forEach(function (b) {
      var f = function () { var e = trouver(sel); memo(); var k = b.dataset.tout;
        if (k === 'couleur') e.runs.forEach(function (r) { r.c = b.value; });
        else { var tous = e.runs.every(function (r) { return r[k]; }); e.runs.forEach(function (r) { r[k] = tous ? 0 : 1; }); }
        rafraichir(k !== 'couleur'); };
      if (b.type === 'color') b.addEventListener('input', f); else b.onclick = f;
    });
    p.querySelectorAll('[data-var]').forEach(function (b) { b.onclick = function () { var e = trouver(sel); memo(); var d = e.runs[e.runs.length - 1] || {c: '#000000', b: 0, i: 0, u: 0}; e.runs.push({t: (e.runs.length ? ' ' : '') + '{' + b.dataset.var + '}', c: d.c, b: d.b, i: d.i, u: d.u}); rafraichir(true); }; });
    p.querySelectorAll('[data-img]').forEach(function (im) { im.onclick = function () { var e = trouver(sel); memo(); e.fichier = im.dataset.img; rafraichir(true); }; });
    p.querySelectorAll('[data-act]').forEach(function (b) { b.onclick = function () { action(b.dataset.act); }; });
    var fi = p.querySelector('#cz-fond-img'); if (fi) fi.onclick = function () { televerser(function (f) { memo(); G.image_fond = f; dessiner(); }); };
    var fs = p.querySelector('#cz-fond-suppr'); if (fs) fs.onclick = function () { memo(); G.image_fond = ''; dessiner(); };
  }
  // Redessine sans perdre la saisie en cours dans le panneau.
  function rafraichir(panneau) {
    var e = trouver(sel), ancien = toile.querySelector('.cz-el[data-id="' + sel + '"]');
    if (e && ancien) { ancien.replaceWith(dessinerEl(e)); poignees(); calques(); } else dessiner();
    if (panneau) proprietes();
  }

  // ── Actions ──
  function choisir(id) { if (edition && edition !== id) finEdition(); sel = id; dessiner(); }
  function action(a) {
    var e = trouver(sel); if (!e) return;
    var i = G.elements.indexOf(e);
    if (a === 'editer') return debutEdition(e.id);
    if (a === 'televerser') return televerser(function (f) { memo(); e.fichier = f; rafraichir(true); });
    memo();
    if (a === 'supprimer') { G.elements.splice(i, 1); sel = null; }
    if (a === 'dupliquer') { var c = JSON.parse(JSON.stringify(e)); c.id = idNouveau(); c.x += 2; c.y += 2; G.elements.push(c); sel = c.id; }
    if (a === 'avant' && i < G.elements.length - 1) { G.elements.splice(i, 1); G.elements.splice(i + 1, 0, e); }
    if (a === 'arriere' && i > 0) { G.elements.splice(i, 1); G.elements.splice(i - 1, 0, e); }
    if (a === 'centreh') e.x = Math.round((G.largeur - e.w) / 2 * 10) / 10;
    if (a === 'centrev') e.y = Math.round((G.hauteur - e.h) / 2 * 10) / 10;
    dessiner();
  }
  function ajouter(quoi) {
    var W = G.largeur, H = G.hauteur, k = TYPE === 'couverture' ? 2.5 : 1, e;
    if (quoi === 'texte') e = {type: 'texte', w: 40 * k, h: 8 * k, runs: [{t: 'Nouveau texte {nom_complet}', c: '#1a2744', b: 1, i: 0, u: 0}], police: 'helvetica', taille: 8 * (k > 1 ? 2 : 1), align: 'L', valign: 'T', interligne: 1.2, ajuster: 1, maj: 0, fond: '', bord: ''};
    else if (quoi === 'rect') e = {type: 'rect', w: 30 * k, h: 10 * k, fond: '#c8960a', bord: '', ep: 0.3};
    else {
      if (quoi === 'fichier' && !Object.keys(IMGS).length) return televerser(function (f) { ajouterImage('fichier', f); });
      return ajouterImage(quoi, quoi === 'fichier' ? Object.keys(IMGS)[0] : '');
    }
    e.id = idNouveau(); e.x = Math.round((W - e.w) / 2); e.y = Math.round((H - e.h) / 2);
    memo(); G.elements.push(e); sel = e.id; dessiner();
  }
  function ajouterImage(source, fichier) {
    var k = TYPE === 'couverture' ? 3 : 1, c = source === 'photo' ? [18 * k, 23 * k] : [14 * k, 14 * k];
    var e = {id: idNouveau(), type: 'image', w: c[0], h: c[1], source: source, fichier: fichier || '', ajust: 'contenir', bord: ''};
    e.x = Math.round((G.largeur - e.w) / 2); e.y = Math.round((G.hauteur - e.h) / 2);
    memo(); G.elements.push(e); sel = e.id; dessiner();
  }
  function televerser(apres) {
    var inp = document.getElementById('cz-fichier'); inp.value = '';
    inp.onchange = function () {
      if (!inp.files[0]) return;
      var fd = new FormData(); fd.append('action', 'televerser'); fd.append('csrf', CSRF); fd.append('image', inp.files[0]);
      fetch(URL_ICI, {method: 'POST', body: fd, credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (j) {
        if (j.erreur) return message(j.erreur, true);
        IMGS[j.fichier] = j.url; apres(j.fichier);
      }).catch(function () { message('Envoi impossible.', true); });
    };
    inp.click();
  }

  // ── Édition du texte riche ──
  function debutEdition(id) {
    sel = id; edition = id; dessiner();
    var c = toile.querySelector('.cz-el[data-id="' + id + '"] [contenteditable]');
    document.getElementById('cz-barre-texte').classList.add('visible');
    if (c) { c.focus(); var r = document.createRange(); r.selectNodeContents(c); r.collapse(false); var s = getSelection(); s.removeAllRanges(); s.addRange(r); }
    try { document.execCommand('styleWithCSS', false, true); } catch (x) {}
  }
  function finEdition() {
    if (!edition) return;
    var e = trouver(edition), c = toile.querySelector('.cz-el[data-id="' + edition + '"] [contenteditable]');
    if (e && c) { var d = e.runs[0] || {c: '#000000', b: 0, i: 0, u: 0}; var n = htmlVersRuns(c, {c: d.c, b: 0, i: 0, u: 0}); if (JSON.stringify(n) !== JSON.stringify(e.runs)) { memo(); e.runs = n; } }
    edition = null; document.getElementById('cz-barre-texte').classList.remove('visible'); dessiner();
  }
  var selMemo = null;
  document.addEventListener('selectionchange', function () { var s = getSelection(); if (edition && s.rangeCount && toile.contains(s.anchorNode)) selMemo = s.getRangeAt(0).cloneRange(); });
  function restaurerSel() { if (selMemo) { var s = getSelection(); s.removeAllRanges(); s.addRange(selMemo); } }
  document.querySelectorAll('#cz-barre-texte [data-cmd]').forEach(function (b) { b.addEventListener('mousedown', function (ev) { ev.preventDefault(); document.execCommand(b.dataset.cmd); }); });
  document.getElementById('cz-appliquer-couleur').addEventListener('mousedown', function (ev) { ev.preventDefault(); restaurerSel(); document.execCommand('foreColor', false, document.getElementById('cz-couleur-mots').value); });
  document.getElementById('cz-var-insert').addEventListener('change', function () { var v = this.value; this.value = ''; if (!v) return; restaurerSel(); document.execCommand('insertText', false, '{' + v + '}'); });
  document.getElementById('cz-fin-edition').onclick = finEdition;

  // ── Souris : sélection, déplacement, redimensionnement ──
  var glisse = null;
  toile.addEventListener('pointerdown', function (ev) {
    var q = ev.target.closest('.cz-poignee'), el = ev.target.closest('.cz-el');
    if (el && edition === el.dataset.id) return;            // clic dans le texte en cours d'écriture
    if (edition) finEdition();
    if (!q && !el) { sel = null; dessiner(); return; }
    if (el && sel !== el.dataset.id) { sel = el.dataset.id; dessiner(); }
    var e = trouver(sel); if (!e) return;
    glisse = {mode: q ? q.dataset.sens : 'deplacer', x0: ev.clientX, y0: ev.clientY, e0: {x: e.x, y: e.y, w: e.w, h: e.h}, avant: JSON.stringify(G), bouge: false};
    toile.setPointerCapture(ev.pointerId); ev.preventDefault();
  });
  toile.addEventListener('pointermove', function (ev) {
    if (!glisse) return;
    var e = trouver(sel), dx = (ev.clientX - glisse.x0) / Z, dy = (ev.clientY - glisse.y0) / Z, o = glisse.e0;
    if (Math.abs(dx) + Math.abs(dy) > 0.3) glisse.bouge = true;
    var pas = ev.altKey ? 0.1 : 0.5, arr = function (v) { return Math.round(v / pas) * pas; }, m = glisse.mode;
    if (m === 'deplacer') {
      e.x = arr(o.x + dx); e.y = arr(o.y + dy);
      var cx = e.x + e.w / 2, cy = e.y + e.h / 2;                 // aimant au centre du document
      guides(Math.abs(cx - G.largeur / 2) < 0.8 ? (e.x = arr(G.largeur / 2 - e.w / 2), 'v') : '', Math.abs(cy - G.hauteur / 2) < 0.8 ? (e.y = arr(G.hauteur / 2 - e.h / 2), 'h') : '');
    } else {
      if (/e/.test(m)) e.w = Math.max(1, arr(o.w + dx));
      if (/s/.test(m)) e.h = Math.max(0.5, arr(o.h + dy));
      if (/w/.test(m)) { e.w = Math.max(1, arr(o.w - dx)); e.x = arr(o.x + o.w - e.w); }
      if (/n/.test(m)) { e.h = Math.max(0.5, arr(o.h - dy)); e.y = arr(o.y + o.h - e.h); }
    }
    var d = toile.querySelector('.cz-el[data-id="' + sel + '"]');
    d.style.left = (e.x * Z) + 'px'; d.style.top = (e.y * Z) + 'px'; d.style.width = (e.w * Z) + 'px'; d.style.height = (e.h * Z) + 'px';
    poignees();
  });
  toile.addEventListener('pointerup', function () {
    if (!glisse) return;
    if (glisse.bouge) { histo.push(glisse.avant); futur = []; rafraichir(true); }
    glisse = null; guides('', '');
  });
  toile.addEventListener('dblclick', function (ev) { var sous = document.elementFromPoint(ev.clientX, ev.clientY), el = sous && sous.closest('.cz-el'); if (el && trouver(el.dataset.id).type === 'texte' && edition !== el.dataset.id) debutEdition(el.dataset.id); });
  function guides(v, hz) {
    toile.querySelectorAll('.cz-guide').forEach(function (g) { g.remove(); });
    if (v) { var a = document.createElement('div'); a.className = 'cz-guide'; a.style.cssText = 'left:' + (G.largeur * Z / 2) + 'px;top:0;width:1px;height:100%'; toile.appendChild(a); }
    if (hz) { var b = document.createElement('div'); b.className = 'cz-guide'; b.style.cssText = 'top:' + (G.hauteur * Z / 2) + 'px;left:0;height:1px;width:100%'; toile.appendChild(b); }
  }

  // ── Clavier ──
  document.addEventListener('keydown', function (ev) {
    var dansChamp = /INPUT|SELECT|TEXTAREA/.test(document.activeElement.tagName) || document.activeElement.isContentEditable;
    if (ev.key === 'Escape') { if (edition) finEdition(); else { sel = null; dessiner(); } return; }
    if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === 'z' && !dansChamp) { ev.preventDefault(); annuler(); return; }
    if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === 'y' && !dansChamp) { ev.preventDefault(); retablir(); return; }
    if (dansChamp || !sel) return;
    var e = trouver(sel); if (!e) return;
    if (ev.key === 'Delete' || ev.key === 'Backspace') { ev.preventDefault(); action('supprimer'); return; }
    if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === 'd') { ev.preventDefault(); action('dupliquer'); return; }
    var p = ev.shiftKey ? 2 : 0.5, d = {ArrowLeft: [-p, 0], ArrowRight: [p, 0], ArrowUp: [0, -p], ArrowDown: [0, p]}[ev.key];
    if (d) { ev.preventDefault(); memo(); e.x = Math.round((e.x + d[0]) * 10) / 10; e.y = Math.round((e.y + d[1]) * 10) / 10; rafraichir(true); }
  });
  function annuler() { if (!histo.length) return; futur.push(JSON.stringify(G)); G = JSON.parse(histo.pop()); if (sel && !trouver(sel)) sel = null; dessiner(); }
  function retablir() { if (!futur.length) return; histo.push(JSON.stringify(G)); G = JSON.parse(futur.pop()); dessiner(); }
  document.getElementById('cz-annuler').onclick = annuler;
  document.getElementById('cz-retablir').onclick = retablir;
  document.getElementById('cz-zoom-plus').onclick = function () { Z = Math.min(Z * 1.2, 16); dessiner(); };
  document.getElementById('cz-zoom-moins').onclick = function () { Z = Math.max(Z / 1.2, 1); dessiner(); };
  document.getElementById('cz-donnees').onchange = dessiner;
  document.querySelectorAll('[data-ajout]').forEach(function (b) { b.onclick = function () { if (edition) finEdition(); ajouter(b.dataset.ajout); }; });

  // ── Serveur ──
  function message(t, erreur) {
    var z = document.getElementById('cz-message');
    if (!z) { z = document.createElement('div'); z.id = 'cz-message'; z.style.cssText = 'position:fixed;right:20px;bottom:20px;z-index:3000;max-width:380px'; document.body.appendChild(z); }
    z.innerHTML = '<div class="alert ' + (erreur ? 'alert-danger' : 'alert-success') + ' shadow py-2 mb-0" style="font-size:.85rem">' + h(t) + '</div>';
    clearTimeout(z._t); z._t = setTimeout(function () { z.innerHTML = ''; }, 4500);
  }
  function envoyer(action, extra) {
    if (edition) finEdition();
    var fd = new FormData(); fd.append('action', action); fd.append('csrf', CSRF); fd.append('gabarit', JSON.stringify(G));
    Object.keys(extra || {}).forEach(function (k) { fd.append(k, extra[k]); });
    return fetch(URL_ICI, {method: 'POST', body: fd, credentials: 'same-origin'}).then(function (r) { return r.json(); });
  }
  document.getElementById('cz-apercu').onclick = function () {
    envoyer('brouillon').then(function (j) {
      if (j.erreur) return message(j.erreur, true);
      afficherApercu(APERCU + '&_=' + Date.now(), 'Aperçu — ' + <?= json_encode($T[0]) ?>, 'concepteur_' + TYPE, 'portrait');
    }).catch(function () { message('Aperçu impossible.', true); });
  };
  function enregistrer(defaut) {
    envoyer('enregistrer', defaut ? {defaut: 1} : {}).then(function (j) { message(j.erreur || j.message, !!j.erreur); }).catch(function () { message('Enregistrement impossible.', true); });
  }
  document.getElementById('cz-enregistrer').onclick = function () { enregistrer(false); };
  var bd = document.getElementById('cz-enregistrer-defaut'); if (bd) bd.onclick = function () { enregistrer(true); };
  document.getElementById('cz-depart').onclick = function () {
    if (!confirm(TYPE === 'bulletin' ? 'Revenir au haut de bulletin d\'origine ? Le modèle enregistré sera effacé.' : 'Repartir du modèle de départ ? Le modèle enregistré sera effacé.')) return;
    envoyer('reinitialiser').then(function (j) { if (j.gabarit) { memo(); G = j.gabarit; sel = null; dessiner(); message('Modèle de départ rétabli.'); } });
  };
  // Zoom initial : la toile tient dans la zone.
  var zone = document.getElementById('cz-zone');
  Z = Math.max(1.5, Math.min(Z, (zone.clientWidth - 60) / G.largeur));
  dessiner();
})();
</script>

<?php require_once __DIR__ . '/../../../../noyau/layout/footer.php'; ?>
