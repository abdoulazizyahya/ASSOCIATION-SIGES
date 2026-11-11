<?php
// secondaire/commun/pages/apparence/index.php — Paramètres → Apparence des
// documents (11/11/2026), primaire et secondaire : police, taille du texte,
// couleurs et modèles par défaut des cartes scolaires, des bulletins et des
// autres documents PDF, avec un aperçu PDF réel qui se met à jour en direct.
// Moteur : noyau/partage/apparence_docs.php (crochet siges_* des fpdf.php).
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
require_once __DIR__ . '/../../../../noyau/partage/apparence_docs.php';
require_once __DIR__ . '/../analyse/_analyse_lib.php';

$sec = ed_secondaire();
exiger_role($sec ? ['ADMIN', 'PROVISEUR', 'FONDATEUR'] : ['DIRECTEUR', 'FONDATEUR']);
$docs = app_documents();
$onglet = isset($docs[$_GET['onglet'] ?? '']) ? $_GET['onglet'] : 'cartes';
$technique = $sec && est_technique();

// ── Enregistrement ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $doc = isset($docs[$_POST['doc'] ?? '']) ? $_POST['doc'] : 'cartes';
    $action = $_POST['action'] ?? 'enregistrer';
    if ($action === 'reinitialiser') {
        app_enregistrer($doc, []);
        if ($doc === 'bulletins') {   // rôles en base remis aux couleurs d'origine
            $origine = ed_secondaire()
                ? array_map(fn($d) => [$d['r'], $d['g'], $d['b']], pdf_couleurs_defaut_secondaire())
                : ['groupe_competence' => [45, 231, 218], 'ligne_alternee' => [227, 227, 227], 'ligne_rayee' => [249, 249, 249], 'entete_section' => [65, 165, 165],
                   'cellule_resultat' => [228, 228, 228], 'entete_bleu' => [146, 220, 255], 'colonne_annuelle' => [250, 214, 165]];
            foreach ($origine as $cle => [$cr, $cg, $cb]) db_exec("UPDATE pdf_couleur SET r=?, g=?, b=? WHERE cle=?", [$cr, $cg, $cb, $cle]);
        }
        flash_set('succes', $docs[$doc][0] . ' : apparence remise par défaut.');
    } else {
        $v = [];
        $police = (string) ($_POST['police'] ?? '');
        $v['police'] = in_array($police, $docs[$doc][2], true) ? $police : $docs[$doc][3]['police'];
        if (isset($docs[$doc][3]['echelle'])) $v['echelle'] = max(90, min(115, (int) ($_POST['echelle'] ?? 100)));
        foreach (['couleur', 'accent'] as $k) {
            if (isset($docs[$doc][3][$k])) $v[$k] = app_hex_rgb((string) ($_POST[$k] ?? '')) ? strtolower((string) $_POST[$k]) : $docs[$doc][3][$k];
        }
        if ($doc === 'bulletins') {
            $v['couleurs'] = [];
            foreach (app_bulletin_roles() as $code => $role) {
                $hx = strtolower((string) ($_POST['c_' . $code] ?? ''));
                if (app_hex_rgb($hx) && $hx !== $role[2]) $v['couleurs'][$code] = $hx;
            }
            foreach (array_keys(app_bulletin_roles_base()) as $cle) {
                $rgb = app_hex_rgb((string) ($_POST['pdf_' . $cle] ?? ''));
                if ($rgb) db_exec("UPDATE pdf_couleur SET r=?, g=?, b=? WHERE cle=?", [$rgb[0], $rgb[1], $rgb[2], $cle]);
            }
        }
        if ($doc === 'cartes') {
            $v['modele'] = max(1, min(5, (int) ($_POST['modele'] ?? 1)));
            $v['format'] = max(0, (int) ($_POST['format'] ?? 0));
            $v['verso'] = !empty($_POST['verso']) ? 1 : 0;
            $v['signature'] = !empty($_POST['signature']) ? 1 : 0;
        }
        app_enregistrer($doc, $v);
        // « Même police partout » : la police choisie est reportée sur les autres documents.
        if ($action === 'partout') {
            foreach ($docs as $d => $def) {
                if ($d === $doc || !in_array($v['police'], $def[2], true)) continue;
                $r = app_reglages($d); $r['police'] = $v['police'];
                app_enregistrer($d, $r);
            }
            flash_set('succes', 'Police « ' . APP_POLICES[$v['police']][0] . ' » appliquée à tous les documents.');
        } else {
            flash_set('succes', $docs[$doc][0] . ' : apparence enregistrée.');
        }
    }
    rediriger('secondaire/commun/pages/apparence/index.php?onglet=' . $doc);
}

$r = app_reglages($onglet);

// ── Aperçu : un élève réel de l'école ───────────────────────────────
$an = ed_annee();
$premier = null;
foreach (ed_classes() as $c) { $l = ed_eleves((int) $c['id']); if ($l) { $premier = $l[0]; break; } }
$trims = array_values(array_filter(an_periodes(), fn($p) => $p['cle'] !== 'annee'));
$id_trim = (int) ($trims[0]['id'] ?? 0);
$apercus = [];
if ($onglet === 'cartes' && $premier) {
    $apercus['carte'] = ['Carte de ' . ed_nom($premier), $sec
        ? APP_URL . '/secondaire/commun/pdf/cartes.php?annee=' . $an['id'] . '&eleve=' . (int) $premier['id']
        : APP_URL . '/primaire/pdf/cartes.php?id=' . (int) $premier['id']];
}
if ($onglet === 'bulletins' && $premier && $id_trim) {
    $apercus['bulletin'] = ['Bulletin de ' . ed_nom($premier), $sec
        ? APP_URL . '/secondaire/' . ($technique ? 'technique' : 'general') . '/pages/bulletins/pdf.php?eleve=' . (int) $premier['id'] . '&annee=' . $an['id'] . '&trim=' . $id_trim
        : APP_URL . '/primaire/pdf/bulletin_trimestriel.php?id=' . (int) $premier['id'] . '&trim=' . $id_trim];
}
if ($onglet === 'documents') {
    $apercus['couverture'] = ['Couverture de bulletin (vierge)', APP_URL . '/secondaire/commun/pages/couvertures/pdf.php?vierge=1'];
    $apercus['effectifs'] = ['Effectifs par classe', APP_URL . '/secondaire/commun/pages/impression/pdf.php?doc=effectifs'];
    $apercus['badges'] = ['Badges du personnel', APP_URL . '/secondaire/commun/pages/impression/pdf.php?doc=badges'];
}
if ($sec) {
    $formats = db_all("SELECT id, libelle FROM format_carte ORDER BY id");
} else {
    $formats = [['id' => 1, 'libelle' => 'Carte bancaire (CR80)'], ['id' => 2, 'libelle' => 'ISO carte étudiant'], ['id' => 3, 'libelle' => 'A4 pleine page']];
}
$modeles_carte = [1 => 'Officiel bilingue', 2 => 'Encadré classique', 3 => 'Bandeau année', 4 => 'Badge centré', 5 => 'Minimaliste'];
$palettes = [
    'cartes' => ['#1e4fd8' => 'Bleu (d\'origine)', '#1a2744' => 'Marine SIGES', '#1e7c50' => 'Vert', '#c0392b' => 'Rouge', '#7a1f5c' => 'Bordeaux', '#14786e' => 'Sarcelle'],
    'documents' => ['#1a2744' => 'Marine SIGES', '#1e4fd8' => 'Bleu', '#1e7c50' => 'Vert', '#c0392b' => 'Rouge', '#4b2c83' => 'Violet', '#333333' => 'Anthracite'],
];

$titre_page = 'Apparence des documents';
require_once __DIR__ . '/../../../../noyau/layout/header.php';
?>
<style>
.app-police{border:1.5px solid #e5e7eb;border-radius:10px;padding:8px 12px;cursor:pointer;display:flex;align-items:center;gap:10px;margin-bottom:6px;transition:border-color .15s,background .15s}
.app-police:hover{border-color:#c8960a}
.app-police.actif{border-color:#1a2744;background:#f3f6fb}
.app-police .ex{font-size:1.05rem;color:#1a2744;min-width:120px}
.app-police .lib{font-size:.78rem;color:#6b7280}
.app-pastille{width:28px;height:28px;border-radius:50%;border:2px solid #fff;box-shadow:0 0 0 1.5px #d1d5db;cursor:pointer;display:inline-block}
.app-pastille.actif{box-shadow:0 0 0 2.5px #1a2744}
#app-apercu{width:100%;height:640px;border:0;border-radius:10px;background:#525659}
</style>

<div class="page-titre">
  <h4><i class="bi bi-palette me-1 text-primary"></i>Apparence des documents</h4>
  <div class="sub">Police, taille du texte, couleurs et modèles par défaut des documents imprimés de l'école — l'aperçu à droite est le vrai PDF, mis à jour en direct.</div>
</div>

<ul class="nav nav-tabs mb-3" style="border-bottom:2px solid #e5e7eb">
  <?php foreach ($docs as $k => $d): ?>
  <li class="nav-item"><a class="nav-link <?= $onglet === $k ? 'active' : '' ?>" href="?onglet=<?= $k ?>"><i class="bi bi-<?= $d[1] ?> me-1"></i><?= h($d[0]) ?></a></li>
  <?php endforeach; ?>
</ul>

<div class="card mb-3" style="border-left:4px solid #c8960a">
  <div class="card-body d-flex flex-wrap align-items-center gap-3 py-2">
    <div style="width:42px;height:42px;border-radius:10px;background:#fbf1d6;display:flex;align-items:center;justify-content:center"><i class="bi bi-vector-pen" style="color:#8a6400;font-size:1.3rem"></i></div>
    <div class="flex-grow-1">
      <div class="fw-semibold" style="font-size:.92rem">Concepteur de modèles — personnalisation complète</div>
      <div class="text-muted" style="font-size:.78rem">Dessinez votre propre document : déplacez et redimensionnez le logo, la photo, le QR et chaque texte, colorez les mots un par un, ajoutez formes et images.</div>
    </div>
    <a href="<?= APP_URL ?>/secondaire/commun/pages/apparence/concepteur.php?type=carte" class="btn btn-primary btn-sm"><i class="bi bi-credit-card-2-front me-1"></i>Carte scolaire</a>
    <a href="<?= APP_URL ?>/secondaire/commun/pages/apparence/concepteur.php?type=badge" class="btn btn-outline-primary btn-sm"><i class="bi bi-person-badge me-1"></i>Badge</a>
    <a href="<?= APP_URL ?>/secondaire/commun/pages/apparence/concepteur.php?type=couverture" class="btn btn-outline-primary btn-sm"><i class="bi bi-journal-bookmark me-1"></i>Couverture</a>
    <?php if (true): ?><a href="<?= APP_URL ?>/secondaire/commun/pages/apparence/concepteur.php?type=bulletin" class="btn btn-outline-primary btn-sm"><i class="bi bi-journal-text me-1"></i>Haut du bulletin</a><?php endif; ?>
  </div>
</div>
<div class="row g-3">
  <div class="col-lg-5">
    <form method="post" id="app-form" class="card">
      <div class="card-body">
        <?= csrf_champ() ?>
        <input type="hidden" name="doc" value="<?= $onglet ?>">

        <div class="section-titre"><i class="bi bi-fonts me-1"></i>Police</div>
        <?php foreach ($docs[$onglet][2] as $p): ?>
        <label class="app-police <?= $r['police'] === $p ? 'actif' : '' ?>">
          <input type="radio" name="police" value="<?= $p ?>" class="d-none" <?= $r['police'] === $p ? 'checked' : '' ?>>
          <span class="ex" style="font-family:<?= h(APP_POLICES[$p][1]) ?>">Bulletin 2026</span>
          <span class="lib"><?= h(APP_POLICES[$p][0]) ?></span>
        </label>
        <?php endforeach; ?>
        <?php if ($onglet === 'bulletins'): ?>
          <div class="text-muted mb-2" style="font-size:.75rem">
            <?php if (false): ?>
            <?php else: ?><i class="bi bi-info-circle me-1"></i>Les bulletins sont serrés : Roboto condensée laisse le plus de place au texte. Toutes les couleurs du bulletin se règlent ci-dessous.<?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if ($onglet === 'bulletins'):
          $roles_base = app_bulletin_roles_base(); $roles_app = app_bulletin_roles(); ?>
        <div class="section-titre mt-3"><i class="bi bi-palette me-1"></i>Couleurs des cases, des textes et des bordures</div>
        <div class="text-muted mb-2" style="font-size:.75rem">Chaque couleur s'applique partout où elle apparaît sur le bulletin — l'aperçu à droite change en direct.</div>
        <div class="row g-1">
          <?php foreach ($roles_base as $cle => [$lib, $hx]): ?>
          <div class="col-12 d-flex align-items-center gap-2 py-1 border-bottom">
            <input type="color" name="pdf_<?= h($cle) ?>" value="<?= h($hx) ?>" class="form-control form-control-color form-control-sm flex-shrink-0">
            <span style="font-size:.8rem"><i class="bi bi-square-fill me-1" style="color:#c8960a;font-size:.6rem"></i><?= h($lib) ?></span>
          </div>
          <?php endforeach; ?>
          <?php foreach ($roles_app as $code => [$lib, $src, $def]): ?>
          <div class="col-12 d-flex align-items-center gap-2 py-1 border-bottom">
            <input type="color" name="c_<?= $code ?>" value="<?= h($r['couleurs'][$code] ?? $def) ?>" class="form-control form-control-color form-control-sm flex-shrink-0">
            <span style="font-size:.8rem"><i class="bi bi-<?= $src[0][0] === 'T' ? 'fonts' : ($src[0][0] === 'D' ? 'border-outer' : 'square-fill') ?> me-1 text-muted" style="font-size:.7rem"></i><?= h($lib) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="alert alert-light border mt-3 mb-0 py-2" style="font-size:.8rem">
          <i class="bi bi-vector-pen me-1" style="color:#c8960a"></i>Pour <strong>déplacer</strong> la photo, le nom, le QR, le logo… ou colorer un mot précis du haut du bulletin :
          <a href="<?= APP_URL ?>/secondaire/commun/pages/apparence/concepteur.php?type=bulletin" class="fw-semibold">ouvrir le concepteur du haut du bulletin</a>.
        </div>
        <?php endif; ?>

        <?php if (isset($r['echelle'])): ?>
        <div class="section-titre mt-3"><i class="bi bi-type me-1"></i>Taille du texte — <span id="app-echelle-val"><?= (int) $r['echelle'] ?></span> %</div>
        <input type="range" class="form-range" name="echelle" min="90" max="115" step="5" value="<?= (int) $r['echelle'] ?>" oninput="document.getElementById('app-echelle-val').textContent=this.value">
        <div class="d-flex justify-content-between text-muted" style="font-size:.72rem;margin-top:-4px"><span>Plus petit</span><span>Normal</span><span>Plus grand</span></div>
        <?php endif; ?>

        <?php foreach (['couleur' => 'Couleur principale', 'accent' => 'Couleur d\'accent (filets, fonction)'] as $k => $lib): if (!isset($r[$k])) continue; ?>
        <div class="section-titre mt-3"><i class="bi bi-droplet me-1"></i><?= $lib ?></div>
        <div class="d-flex align-items-center flex-wrap gap-2">
          <?php if ($k === 'couleur'): foreach ($palettes[$onglet] ?? [] as $hex => $nom): ?>
            <span class="app-pastille <?= strtolower($r[$k]) === $hex ? 'actif' : '' ?>" style="background:<?= $hex ?>" title="<?= h($nom) ?>" data-cible="<?= $k ?>" data-hex="<?= $hex ?>"></span>
          <?php endforeach; endif; ?>
          <input type="color" name="<?= $k ?>" id="app-<?= $k ?>" value="<?= h($r[$k]) ?>" class="form-control form-control-color form-control-sm" title="Autre couleur">
          <code id="app-<?= $k ?>-code" style="font-size:.78rem"><?= h($r[$k]) ?></code>
        </div>
        <?php endforeach; ?>

        <?php if ($onglet === 'cartes'): ?>
        <div class="section-titre mt-3"><i class="bi bi-layout-text-window me-1"></i>Choix par défaut à l'impression</div>
        <div class="row g-2">
          <div class="col-sm-6"><label class="form-label">Modèle</label>
            <select name="modele" class="form-select form-select-sm"><?php foreach ($modeles_carte as $n => $lib): ?><option value="<?= $n ?>" <?= (int) $r['modele'] === $n ? 'selected' : '' ?>>Modèle <?= $n ?> — <?= h($lib) ?></option><?php endforeach; ?></select></div>
          <div class="col-sm-6"><label class="form-label">Format</label>
            <select name="format" class="form-select form-select-sm"><?php foreach ($formats as $i => $f): ?><option value="<?= (int) $f['id'] ?>" <?= ((int) $r['format'] === (int) $f['id'] || (!$r['format'] && $i === 0)) ? 'selected' : '' ?>><?= h(str_replace('?', '×', $f['libelle'])) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="verso" id="app-verso" value="1" <?= $r['verso'] ? 'checked' : '' ?>><label class="form-check-label" for="app-verso" style="font-size:.84rem">Imprimer le verso par défaut</label></div>
        <?php if ($sec): ?><div class="form-check"><input class="form-check-input" type="checkbox" name="signature" id="app-sig" value="1" <?= $r['signature'] ? 'checked' : '' ?>><label class="form-check-label" for="app-sig" style="font-size:.84rem">Signature du chef d'établissement</label></div><?php endif; ?>
        <div class="text-muted mt-1" style="font-size:.75rem">Ces choix sont présélectionnés dans Élèves → Cartes scolaires (on peut toujours les changer avant d'imprimer). Le nombre de cartes par page découle du format.</div>
        <?php endif; ?>

        <div class="d-flex flex-wrap gap-2 mt-4">
          <button type="submit" name="action" value="enregistrer" class="btn btn-primary btn-sm"><i class="bi bi-check2 me-1"></i>Enregistrer</button>
          <button type="submit" name="action" value="partout" class="btn btn-outline-primary btn-sm" title="Reporter cette police sur les cartes, les bulletins et les autres documents"><i class="bi bi-files me-1"></i>Enregistrer et appliquer la police partout</button>
          <button type="submit" name="action" value="reinitialiser" class="btn btn-outline-secondary btn-sm ms-auto"><i class="bi bi-arrow-counterclockwise me-1"></i>Par défaut</button>
        </div>
      </div>
    </form>
  </div>

  <div class="col-lg-7">
    <div class="card">
      <div class="card-body">
        <div class="d-flex align-items-center gap-2 mb-2">
          <div class="section-titre mb-0"><i class="bi bi-eye me-1"></i>Aperçu réel</div>
          <?php if (count($apercus) > 1): ?>
          <select id="app-doc-apercu" class="form-select form-select-sm ms-auto" style="max-width:240px">
            <?php foreach ($apercus as $k => [$lib, $u]): ?><option value="<?= h($u) ?>"><?= h($lib) ?></option><?php endforeach; ?>
          </select>
          <?php elseif ($apercus): ?><span class="text-muted ms-auto" style="font-size:.78rem"><?= h(reset($apercus)[0]) ?></span><?php endif; ?>
        </div>
        <?php if ($apercus): ?>
          <iframe id="app-apercu" title="Aperçu"></iframe>
          <div class="text-muted mt-1" style="font-size:.74rem">L'aperçu suit vos réglages sans les enregistrer.</div>
        <?php else: ?>
          <div class="alert alert-light border mb-0" style="font-size:.85rem"><i class="bi bi-info-circle me-1"></i>Aperçu indisponible <?= $technique ? 'pour le bulletin du technique' : '(aucun élève inscrit ou aucun trimestre cette année)' ?>.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var form = document.getElementById('app-form');
  var frame = document.getElementById('app-apercu');
  var sel = document.getElementById('app-doc-apercu');
  var base = <?= json_encode($apercus ? reset($apercus)[1] : '') ?>;
  var doc = <?= json_encode($onglet) ?>;
  var minuterie = null;
  function val(n) { var e = form.querySelector('[name="' + n + '"]:checked') || form.querySelector('[name="' + n + '"]'); return e ? e.value : ''; }
  function url() {
    var u = (sel ? sel.value : base);
    var p = ['app_apercu=1', 'app_police=' + encodeURIComponent(val('police'))];
    ['echelle', 'couleur', 'accent'].forEach(function (k) { if (form.querySelector('[name="' + k + '"]')) p.push('app_' + k + '=' + encodeURIComponent(val(k))); });
    if (doc === 'bulletins') form.querySelectorAll('input[type=color][name^="c_"], input[type=color][name^="pdf_"]').forEach(function (i) {
      p.push((i.name.indexOf('c_') === 0 ? 'app_' : 'app_') + i.name + '=' + encodeURIComponent(i.value));
    });
    if (doc === 'cartes') {
      p.push('modele=' + val('modele'), 'format=' + val('format'));
      var vs = form.querySelector('[name="verso"]'); if (vs && vs.checked) p.push('verso=1');
      var sg = form.querySelector('[name="signature"]'); if (sg) p.push('signature=' + (sg.checked ? 1 : 0));
    }
    return u + (u.indexOf('?') >= 0 ? '&' : '?') + p.join('&') + '#toolbar=0&view=FitH';
  }
  function rafraichir() { if (!frame) return; clearTimeout(minuterie); minuterie = setTimeout(function () { frame.src = url(); }, 350); }
  form.addEventListener('change', function (e) {
    if (e.target.name === 'police') form.querySelectorAll('.app-police').forEach(function (l) { l.classList.toggle('actif', l.contains(e.target)); });
    if (e.target.type === 'color') { document.getElementById(e.target.id + '-code').textContent = e.target.value; majPastilles(e.target.name, e.target.value); }
    rafraichir();
  });
  form.querySelectorAll('.app-pastille').forEach(function (p) {
    p.addEventListener('click', function () {
      var c = document.getElementById('app-' + p.dataset.cible); c.value = p.dataset.hex;
      document.getElementById(c.id + '-code').textContent = p.dataset.hex; majPastilles(p.dataset.cible, p.dataset.hex); rafraichir();
    });
  });
  function majPastilles(cible, hex) { form.querySelectorAll('.app-pastille[data-cible="' + cible + '"]').forEach(function (x) { x.classList.toggle('actif', x.dataset.hex === hex.toLowerCase()); }); }
  if (sel) sel.addEventListener('change', rafraichir);
  if (frame) frame.src = url();
})();
</script>

<?php require_once __DIR__ . '/../../../../noyau/layout/footer.php'; ?>
