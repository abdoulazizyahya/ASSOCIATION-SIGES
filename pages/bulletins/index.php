<?php
// ── Bulletins — port fidèle d'ABZ_MBE ────────────────────────────────
// Même structure qu'ABZ_MBE (aperçu inline en iframe, pas la modale
// partagée — permet de naviguer d'élève en élève sans fermer/rouvrir),
// navigation AJAX partielle, tri Alpha/Mérite, "Visualiser tous les
// bulletins", colonnes NIU/date-lieu de naissance/sexe. Seules les DONNÉES
// et le calcul viennent de notes_apc.php (compétences, pas matières —
// classement_trimestre_classe()/classement_annuel_classe(), déjà vérifiées)
// et les PDF de pdf/bulletin_trimestriel.php/bulletin_annuel.php (déjà
// construits et testés, session 5, acceptent ?id= et ?classe= en lot).
header('Cache-Control: no-store, no-cache, must-revalidate');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/../../notes_apc.php';
exiger_acces_pedagogie();
exiger_annee_active(); // Année scolaire réellement active requise (18/08/2026) — module Pédagogie/Discipline.

// Mode "partiel" (AJAX) : réponse limitée au contenu de #bull-zone — voir
// initAjaxZone()/chargerPartiel() dans layout/footer.php.
$es_partiel = isset($_GET['partiel']);

$annee_act = get_annee_active();
$val_annee = $annee_act['val_annee'] ?? '';
$seq_active = get_sequence_active();
$trim_actif = (int) ($seq_active['id_trim'] ?? 0);

// LEFT JOIN (pas INNER) — une classe nouvellement créée sans élève inscrit
// doit rester VISIBLE dans le select (grisée, non sélectionnable) plutôt que
// silencieusement absente, source de confusion signalée le 21/08/2026
// (« la classe/le niveau que je viens de créer n'apparaît nulle part »).
// DIRECTEUR/SECRETAIRE voient tout ; un ENSEIGNANT ne voit que ses classes
// affectées (voir filtrer_classes_visibles(), demande explicite du 29/08/2026).
$classes = filtrer_classes_visibles(db_all(
    "SELECT c.IDClasses, c.DesignationClasses, n.OrdreNiveau, COUNT(i.id_eleve) AS nb_eleves
     FROM classe c LEFT JOIN niveau n ON n.LibelleNiveau = c.Niveau
     LEFT JOIN inscrire i ON i.IDClasses = c.IDClasses AND i.val_annee = ?
     GROUP BY c.IDClasses, c.DesignationClasses, n.OrdreNiveau
     ORDER BY n.OrdreNiveau, c.DesignationClasses",
    [$val_annee]
), $val_annee, 'fr');

// ── Paramètres GET ─────────────────────────────────────────────────
$id_classe = (int) ($_GET['classe'] ?? 0);
if ($id_classe && !in_array($id_classe, array_column($classes, 'IDClasses'), true)) $id_classe = 0;
$vue       = in_array($_GET['vue'] ?? '', ['trim', 'annee'], true) ? $_GET['vue'] : 'trim';
$id_eleve  = (int) ($_GET['eleve'] ?? 0);
exiger_acces_eleve($id_eleve, 'fr');   // enseignant restreint : élève hors de ses classes -> refus
$ordre     = in_array($_GET['ordre'] ?? '', ['alpha', 'merite'], true) ? $_GET['ordre'] : 'alpha';
$voir_tous = isset($_GET['voir_tous']);
// Trimestre choisissable (contrairement à ABZ_MBE qui impose la séquence
// active) — la piste française jaynitaare a déjà une page Classement qui
// permet ce choix, cohérence conservée ici.
$id_trim   = (int) ($_GET['trim'] ?? $trim_actif);

$trimestres = db_all("SELECT id_trim, libelle_trim FROM trimestre WHERE id_annee=? ORDER BY id_trim", [$val_annee]);
$trim_lib = '';
foreach ($trimestres as $t) if ($t['id_trim'] == $id_trim) { $trim_lib = $t['libelle_trim']; break; }

$label_periode = $vue === 'annee' ? ('Bilan annuel — ' . $val_annee) : ($trim_lib ?: '—');
$periode_choisie = ($vue === 'annee') || $id_trim > 0;

// ── Élèves classés (moyenne + rang), déjà vérifié ────────────────────
$eleves = [];
if ($id_classe && $periode_choisie) {
    $eleves_raw = db_all(
        "SELECT e.id_eleve AS id, e.Nom_elv AS nom, e.Prenom_elv AS prenom, e.Mat_elv AS matricule,
                e.niu, e.Sexe_elv AS sexe, e.Date_naiss_elv AS date_naiss, e.Lieu_naiss_elv AS lieu_naiss
         FROM eleve e JOIN inscrire i ON i.id_eleve=e.id_eleve AND i.IDClasses=? AND i.val_annee=?
         WHERE e.statut='actif' ORDER BY e.Nom_elv, e.Prenom_elv",
        [$id_classe, $val_annee]
    );
    $classement = $vue === 'annee'
        ? classement_annuel_classe($id_classe, $val_annee)
        : ($id_trim ? classement_trimestre_classe($id_classe, $id_trim, $val_annee) : null);
    $moy_idx = []; $rang_idx = [];
    if ($classement) {
        foreach ($classement['lignes'] as $l) {
            $moy_idx[(int) $l['id_eleve']]  = $l['moy'] !== null ? (float) $l['moy'] : null;
            $rang_idx[(int) $l['id_eleve']] = $l['rang'] ?: null;
        }
    }
    foreach ($eleves_raw as &$el) {
        $el['moy']  = $moy_idx[(int) $el['id']] ?? null;
        $el['rang'] = $rang_idx[(int) $el['id']] ?? null;
    }
    unset($el);

    if ($ordre === 'merite') {
        usort($eleves_raw, fn($a, $b) => ($b['moy'] ?? -1) <=> ($a['moy'] ?? -1));
    }
    $eleves = $eleves_raw;
}

$fmt = fn(?float $v): string => $v === null ? '—' : rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
$chemin_sig = signature_etablissement_chemin();
$peut_configurer_sig = role_connecte() === 'DIRECTEUR';

// $id_classe : nécessaire pour choisir le bon fichier PDF — pdf/
// bulletin_{trimestriel,annuel}_anglais.php (demande du 26/08/2026) pour
// une classe de niveau anglophone (section_classe(), fonctions.php), sinon
// les fichiers français inchangés.
function pdf_url_bull(int $id_eleve, int $id_classe, string $vue, int $id_trim, bool $dl = false): string {
    $anglais = section_classe($id_classe) === 'An';
    $base = $vue === 'annee'
        ? APP_URL . '/pdf/bulletin_annuel' . ($anglais ? '_anglais' : '') . '.php?id=' . $id_eleve
        : APP_URL . '/pdf/bulletin_trimestriel' . ($anglais ? '_anglais' : '') . '.php?id=' . $id_eleve . '&trim=' . $id_trim;
    if ($dl) $base .= '&dl=1';
    return $base;
}
function url_classe_bull(int $id_classe, string $vue, int $id_trim, string $ordre, bool $dl = false): string {
    $anglais = section_classe($id_classe) === 'An';
    $base = $vue === 'annee'
        ? APP_URL . '/pdf/bulletin_annuel' . ($anglais ? '_anglais' : '') . '.php?classe=' . $id_classe
        : APP_URL . '/pdf/bulletin_trimestriel' . ($anglais ? '_anglais' : '') . '.php?classe=' . $id_classe . '&trim=' . $id_trim;
    $base .= '&ordre=' . $ordre;
    if ($dl) $base .= '&dl=1';
    return $base;
}

if (!$es_partiel) {
    $titre_page = 'Bulletins';
    require_once __DIR__ . '/../../layout/header.php';
    ?>
    <style>
    .badge-periode     { background:#dbeafe;color:#1e3a8a;font-size:.78rem;padding:5px 12px;border-radius:20px;font-weight:600; }
    .tbl-bull th       { background:#1a3c6b;color:#fff;font-size:.78rem;padding:8px 10px; }
    .tbl-bull td       { font-size:.81rem;padding:7px 10px;vertical-align:middle; }
    .tbl-bull tr:hover td { background:#f0f4ff; }
    .ordre-btn         { padding:6px 16px;font-size:.82rem;border-radius:6px;cursor:pointer;border:1.5px solid #1a3c6b;transition:all .15s;text-decoration:none;display:inline-block; }
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
    <span class="badge-periode"><i class="bi bi-calendar3 me-1"></i><?= h($label_periode) ?></span>
  </div>
</div>

<!-- ── Filtres ── -->
<div class="card mb-3" style="border-color:#c7d8f0">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end" data-ajax-nav-form action="<?= APP_URL ?>/pages/bulletins/index.php">
      <input type="hidden" name="ordre" value="<?= h($ordre) ?>">
      <?php if ($id_eleve): ?><input type="hidden" name="eleve" value="<?= $id_eleve ?>"><?php endif; ?>

      <div class="col-md-3">
        <label class="form-label mb-1" style="font-size:.78rem;font-weight:600;color:#1a3c6b">Classe</label>
        <select name="classe" class="form-select form-select-sm" data-ajax-nav-auto>
          <option value="">— Choisir —</option>
          <?php foreach ($classes as $c): $vide = (int) $c['nb_eleves'] === 0; ?>
            <option value="<?= $c['IDClasses'] ?>" <?= $id_classe == $c['IDClasses'] ? 'selected' : '' ?> <?= $vide ? 'disabled' : '' ?>>
              <?= h($c['DesignationClasses']) ?><?= $vide ? ' (aucun élève inscrit)' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-auto">
        <label class="form-label mb-1" style="font-size:.78rem;font-weight:600;color:#1a3c6b">Période</label>
        <div class="d-flex gap-1">
          <button type="submit" name="vue" value="trim" class="ordre-btn <?= $vue === 'trim' ? 'active' : '' ?>">
            <i class="bi bi-calendar3 me-1"></i>Trimestre
          </button>
          <button type="submit" name="vue" value="annee" class="ordre-btn <?= $vue === 'annee' ? 'active' : '' ?>">
            <i class="bi bi-calendar-range me-1"></i>Annuel
          </button>
        </div>
      </div>

      <?php if ($vue === 'trim'): ?>
      <div class="col-md-2">
        <label class="form-label mb-1" style="font-size:.78rem;font-weight:600;color:#1a3c6b">
          Trimestre <i class="bi bi-lock-fill" id="cadenasTrim" style="font-size:.7rem;color:#9ca3af"></i>
        </label>
        <?php
        // Verrouillé par défaut sur le trimestre en cours : un select
        // "disabled" ne soumettrait pas sa valeur, on bloque donc
        // l'interaction avec pointer-events (le select reste un champ de
        // formulaire normal, sa valeur est toujours envoyée) — double-clic
        // pour déverrouiller et permettre de choisir un autre trimestre.
        ?>
        <select name="trim" id="selTrim" class="form-select form-select-sm" data-ajax-nav-auto
                style="pointer-events:none;background:#f3f4f6;cursor:default"
                tabindex="-1"
                title="Verrouillé sur le trimestre en cours — double-cliquez pour choisir un autre trimestre"
                ondblclick="this.style.pointerEvents='';this.style.background='';this.style.cursor='';this.removeAttribute('tabindex');this.removeAttribute('title');document.getElementById('cadenasTrim').className='bi bi-unlock-fill';">
          <?php foreach ($trimestres as $t): ?>
            <option value="<?= $t['id_trim'] ?>" <?= $id_trim == $t['id_trim'] ? 'selected' : '' ?>>
              <?= h($t['libelle_trim']) ?><?= $trim_actif == $t['id_trim'] ? ' ★' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php else: ?>
        <input type="hidden" name="trim" value="<?= $id_trim ?>">
      <?php endif; ?>

      <?php if ($id_classe): ?>
      <div class="col-auto">
        <label class="form-label mb-1" style="font-size:.78rem;font-weight:600;color:#1a3c6b">Ordre</label>
        <div class="d-flex gap-1">
          <a href="?vue=<?= $vue ?>&classe=<?= $id_classe ?>&trim=<?= $id_trim ?>&ordre=alpha<?= $id_eleve ? '&eleve=' . $id_eleve : '' ?>" data-ajax-nav
             class="ordre-btn <?= $ordre === 'alpha' ? 'active' : '' ?>">
            <i class="bi bi-sort-alpha-down me-1"></i>Alphabétique
          </a>
          <a href="?vue=<?= $vue ?>&classe=<?= $id_classe ?>&trim=<?= $id_trim ?>&ordre=merite<?= $id_eleve ? '&eleve=' . $id_eleve : '' ?>" data-ajax-nav
             class="ordre-btn <?= $ordre === 'merite' ? 'active' : '' ?>">
            <i class="bi bi-trophy me-1"></i>Mérite
          </a>
        </div>
      </div>
      <?php endif; ?>
    </form>
  </div>
</div>

<?php if (!$id_classe): ?>
  <div class="text-center py-5 text-muted">
    <i class="bi bi-arrow-up-circle" style="font-size:3rem;opacity:.2;display:block;margin-bottom:1rem"></i>
    Sélectionnez une classe pour afficher les bulletins.
  </div>

<?php elseif (!$periode_choisie): ?>
  <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i>Aucun trimestre sélectionné.</div>

<?php elseif ($voir_tous): ?>
<!-- ══ VISUALISATION TOUTE LA CLASSE ══ -->
<?php $url_cl = url_classe_bull($id_classe, $vue, $id_trim, $ordre); ?>
<div class="card" style="border-color:#c7d8f0">
  <div class="card-header py-2 d-flex align-items-center justify-content-between flex-wrap gap-2" style="background:#f0f4ff">
    <span class="fw-semibold" style="color:#1a3c6b">
      <i class="bi bi-people me-1"></i>Bulletins — <?= h($label_periode) ?>
    </span>
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <?php if ($chemin_sig): ?>
      <div class="chk-signature form-check form-check-inline mb-0" style="user-select:none">
        <input class="form-check-input" type="checkbox" id="chkSigCl" onchange="appliquerSigCl()">
        <label class="form-check-label small" for="chkSigCl">Signature numérique</label>
      </div>
      <?php if ($peut_configurer_sig): ?>
      <button type="button" class="btn btn-sm btn-abz-outline" title="Configurer la position de la signature"
              onclick="ouvrirPositionSignatureLocale(<?= json_encode($url_cl) ?>, <?= json_encode($vue === 'annee' ? 'bulletin_annuel' : 'bulletin_trimestriel') ?>, 'portrait', appliquerSigCl)">
        <i class="bi bi-gear"></i>
      </button>
      <?php endif; ?>
      <?php endif; ?>
      <a href="?vue=<?= $vue ?>&classe=<?= $id_classe ?>&trim=<?= $id_trim ?>&ordre=<?= $ordre ?>" data-ajax-nav
         class="btn btn-sm btn-abz-outline"><i class="bi bi-list me-1"></i>Retour liste</a>
      <a id="lienDlCl" href="<?= h(url_classe_bull($id_classe, $vue, $id_trim, $ordre, true)) ?>"
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
  const baseDl = <?= json_encode(url_classe_bull($id_classe, $vue, $id_trim, $ordre, true)) ?>;
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
$eleve_sel = db_one("SELECT Nom_elv, Prenom_elv FROM eleve WHERE id_eleve=?", [$id_eleve]);
$url_pdf   = pdf_url_bull($id_eleve, $id_classe, $vue, $id_trim);
$url_dl    = pdf_url_bull($id_eleve, $id_classe, $vue, $id_trim, true);
$back_url  = '?vue=' . $vue . '&classe=' . $id_classe . '&trim=' . $id_trim . '&ordre=' . $ordre;
?>
<div class="card" style="border-color:#c7d8f0">
  <div class="card-header py-2 d-flex align-items-center justify-content-between flex-wrap gap-2" style="background:#f0f4ff">
    <span class="fw-semibold" style="color:#1a3c6b">
      <i class="bi bi-person me-1"></i>
      <?= h(mb_strtoupper($eleve_sel['Nom_elv'] ?? '') . ' ' . ($eleve_sel['Prenom_elv'] ?? '')) ?>
      <span class="text-muted fw-normal" style="font-size:.8rem">— <?= h($label_periode) ?></span>
    </span>
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <?php if ($chemin_sig): ?>
      <div class="chk-signature form-check form-check-inline mb-0" style="user-select:none">
        <input class="form-check-input" type="checkbox" id="chkSigEl" onchange="appliquerSigEl()">
        <label class="form-check-label small" for="chkSigEl">Signature numérique</label>
      </div>
      <?php if ($peut_configurer_sig): ?>
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
      <a href="?vue=<?= $vue ?>&classe=<?= $id_classe ?>&trim=<?= $id_trim ?>&ordre=<?= $ordre ?>&voir_tous=1" data-ajax-nav
         class="btn btn-sm btn-abz-outline">
        <i class="bi bi-eye me-1"></i>Visualiser tous les bulletins
      </a>
      <a href="<?= h(url_classe_bull($id_classe, $vue, $id_trim, $ordre, true)) ?>" class="btn btn-sm btn-abz-primary">
        <i class="bi bi-download me-1"></i>Télécharger tous
      </a>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table tbl-bull table-hover mb-0">
      <thead>
        <tr>
          <th>N°</th>
          <th>Matricule</th>
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
        $url_ap = '?vue=' . $vue . '&classe=' . $id_classe . '&trim=' . $id_trim . '&ordre=' . $ordre . '&eleve=' . $el['id'];
        $url_d  = pdf_url_bull((int) $el['id'], $id_classe, $vue, $id_trim, true);
        $moy    = $el['moy'] ?? null;
        $rang   = $el['rang'] ?? null;
        $dn     = $el['date_naiss'] ? date('d/m/Y', strtotime($el['date_naiss'])) : '—';
        $naiss  = trim($dn . ' ' . ($el['lieu_naiss'] ?? ''));
        $cls_moy = $moy !== null ? ($moy >= 10 ? 'text-success fw-bold' : 'text-danger fw-bold') : 'text-muted';
        ?>
        <tr>
          <td class="text-muted"><?= $i + 1 ?></td>
          <td style="font-size:.75rem"><?= h($el['matricule'] ?? '—') ?></td>
          <td>
            <a href="<?= h($url_ap) ?>" data-ajax-nav class="text-decoration-none fw-semibold" style="color:#1a3c6b">
              <?= h(mb_strtoupper($el['nom']) . ' ' . ($el['prenom'] ?? '')) ?>
            </a>
          </td>
          <td style="font-size:.78rem"><?= h($naiss) ?></td>
          <td><?= h($el['sexe'] ?? '—') ?></td>
          <td class="<?= $cls_moy ?>"><?= $fmt($moy) ?></td>
          <td class="text-center fw-semibold"><?= $rang !== null ? h((string) $rang) : '—' ?></td>
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
$ajax_zone_id = 'bull-zone'; // voir layout/footer.php — initAjaxZone() y est appelé après sa propre définition
require_once __DIR__ . '/../../layout/footer.php';
