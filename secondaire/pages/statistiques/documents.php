<?php
/**
 * Point d'entrée unique pour "Documents de classe" — fusion en un seul
 * menu à 2 onglets (demande explicite) :
 *  - onglet "Documents de classe" : relevé de notes / fiche statistique,
 *    on choisit d'abord la classe puis la période (Séquence/Trimestriel/
 *    Annuel) sans jamais choisir la séquence ou le trimestre exact
 *    (toujours celui ACTIF — sequence.active=1 et son trimestre) ;
 *  - onglet "Tableau d'honneur" : liste + certificats des élèves
 *    méritants, pour la même classe sélectionnée.
 */
header('Cache-Control: no-store, no-cache, must-revalidate');
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_connexion();

$role     = role_connecte();
$is_admin = in_array($role, ['ADMIN', 'PROVISEUR', 'FONDATEUR', 'CENSEUR']) || $role === 'MEMBRE_ASSOCIATION';
$is_ens   = ($role === 'ENSEIGNANT');
$mat_ens  = $is_ens ? get_matricule_ens_connecte() : null;
if (!$is_admin && !$is_ens) { flash_set('erreur', 'Accès non autorisé.'); rediriger('dashboard.php'); }

$annee_act = get_annee_active();
$id_annee  = (int)($annee_act['id'] ?? 0);
$val_annee = $annee_act['libelle'] ?? '';

// Séquence active de l'année (et son trimestre) — seule période "courante"
// proposée, jamais un choix parmi toutes les séquences/trimestres passés.
$seq_active = db_one(
    "SELECT s.*, t.id AS id_trim, t.libelle AS trim_lib
     FROM sequence s JOIN trimestre t ON t.id=s.id_trim
     WHERE s.active=1 AND t.id_annee=? LIMIT 1",
    [$id_annee]
);

if ($is_admin) {
    $classes = db_all("SELECT c.* FROM classe c JOIN inscription i ON i.id_classe=c.id AND i.id_annee=? WHERE c.archivee=0 GROUP BY c.id ORDER BY c.ordre,c.designation", [$id_annee]);
} elseif ($is_ens && $mat_ens) {
    $classes = db_all(
        "SELECT DISTINCT c.* FROM classe c
         JOIN dispenser d ON d.IDClasses=c.id AND d.matricule_ens=? AND d.val_annee=?
         WHERE c.archivee=0 ORDER BY c.ordre,c.designation",
        [$mat_ens, $val_annee]
    );
} else {
    $classes = [];
}

$id_classe    = (int)($_GET['classe'] ?? 0);
$classe_choisie = null;
foreach ($classes as $c) { if ((int)$c['id'] === $id_classe) { $classe_choisie = $c; break; } }

$tab = in_array($_GET['tab'] ?? '', ['documents', 'honneur'], true) ? $_GET['tab'] : 'documents';

// ── Données de l'onglet "Tableau d'honneur" ─────────────────────────────
$th_vue    = in_array($_GET['vue'] ?? '', ['seq', 'trim', 'annee'], true) ? $_GET['vue'] : 'trim';
// Trimestre : toujours celui EN COURS. Les compétences (chantier APC) sont
// scopées par trimestre entier, sans notion de séquence — "Séquence" et
// "Trimestre" pointent donc désormais tous deux vers le même trimestre
// actif (trimestre.active, get_trimestre_actif() — PAS la séquence active,
// qui peut pointer vers un trimestre différent). La vue "annee" couvre
// toute l'année active. Voir prompt_continuite, mise à jour du 07/08/2026.
$trim_comp_actif = get_trimestre_actif();
$th_seq    = (int)($seq_active['id'] ?? 0);
$th_trim   = (int)($trim_comp_actif['id'] ?? 0);
$th_modele = in_array((int)($_GET['modele'] ?? 1), [1, 2, 3], true) ? (int)($_GET['modele'] ?? 1) : 1;

$eleves_qualifies = [];
if ($tab === 'honneur' && $id_classe && (($th_vue === 'seq' && $th_trim) || ($th_vue === 'trim' && $th_trim) || $th_vue === 'annee')) {
    $eleves = db_all(
        "SELECT e.id, e.nom, e.prenom, e.matricule FROM eleve e
         JOIN inscription i ON i.id_eleve=e.id AND i.id_classe=? AND i.id_annee=?
         WHERE e.statut='actif'",
        [$id_classe, $id_annee]
    );
    $nb_inscrits = count($eleves);

    // Règles 1/2/3/4 appliquées via le moteur commun (fonctions.php).
    $moys = ($th_vue === 'annee' || $nb_inscrits > 0)
        ? calc_moys_classe_periode_comp($id_classe, $id_annee, $th_vue === 'annee' ? 'annee' : 'trimestre', $th_trim, array_column($eleves, 'id'))
        : [];

    if (!empty($moys)) {
        arsort($moys);
        $rangs = []; $rg = 1;
        foreach ($moys as $eid => $m) $rangs[$eid] = $rg++;
        $nb_classes_ = count($moys);

        foreach ($eleves as $el) {
            $eid = (int)$el['id'];
            if (!isset($moys[$eid])) continue;
            $moy = $moys[$eid];
            $abs_nj = $th_vue === 'annee'
                ? (int) eleve_absence_annuelle($el['matricule'] ?? '', $id_classe, $id_annee)['non_jus']
                : (($th_vue === 'trim') ? (int) eleve_absence_trimestre($el['matricule'] ?? '', $th_trim, $id_classe, $val_annee)['non_jus'] : 0);
            if (pv_tableau_honneur($moy, $abs_nj) !== 'Oui') continue;
            $mention = pv_felicitation($moy, $abs_nj) === 'Oui' ? 'Félicitations'
                : (pv_encouragement($moy, $abs_nj) === 'Oui' ? 'Encouragement' : 'Tableau d\'honneur');
            $eleves_qualifies[] = [
                'id' => $eid, 'nom' => $el['nom'], 'prenom' => $el['prenom'] ?? '',
                'moy' => $moy, 'rang' => $rangs[$eid], 'nb_classes' => $nb_classes_, 'mention' => $mention,
            ];
        }
        usort($eleves_qualifies, fn($a, $b) => $b['moy'] <=> $a['moy']);
    }
}
$th_periode_qs = $th_vue === 'seq' ? "seq={$th_seq}" : ($th_vue === 'trim' ? "trim={$th_trim}" : "vue=annee");

$titre_page = 'Documents de classe';
require_once __DIR__ . '/../../../layout/header.php';
?>
<style>
.doc-card{border:1px solid #c7d8f0;border-radius:10px;background:#fff;}
.doc-card .card-body{padding:1.25rem;}
.doc-classe-select{max-width:420px}
.doc-periode-row{display:flex;align-items:center;gap:.75rem;padding:.9rem 1rem;border:1px solid #e3e9f5;border-radius:8px;margin-bottom:.7rem;background:#f8faff;}
.doc-periode-row .doc-lbl{min-width:210px;font-weight:600;color:#1a3c6b;}
.doc-periode-row .doc-sub{font-size:.78rem;color:#666;font-weight:400;display:block;}
.doc-periode-row.disabled{opacity:.55;pointer-events:none;}
.doc-ordre{display:flex;align-items:center;gap:1rem;margin-bottom:1rem;font-size:.85rem}
.doc-ordre label{cursor:pointer}
.th-badge-felicit{background:#fde3e3;color:#b91c1c;}
.th-badge-encour{background:#dbeafe;color:#1e3a8a;}
.th-badge-simple{background:#e5e7eb;color:#374151;}
.nav-ong .nav-link{color:#1a3c6b;border-radius:6px 6px 0 0;font-size:.83rem;}
.nav-ong .nav-link.active{background:#1a3c6b;color:#fff;font-weight:600;}
</style>

<div class="page-titre d-flex align-items-center justify-content-between flex-wrap gap-2">
  <h4><i class="bi bi-files me-2" style="color:#1a3c6b"></i>Documents de classe</h4>
  <span class="badge" style="background:#dbeafe;color:#1e3a8a;font-size:.78rem;padding:5px 12px;border-radius:20px">
    <?= h($val_annee) ?>
  </span>
</div>

<?= flash_html() ?>

<div class="doc-card mb-3">
  <div class="card-body">
    <label class="form-label fw-semibold" style="color:#1a3c6b">1. Choisir une classe</label>
    <form method="get" class="doc-classe-select">
      <input type="hidden" name="tab" value="<?= h($tab) ?>">
      <select name="classe" class="form-select" onchange="this.form.submit()">
        <option value="">— Sélectionner une classe —</option>
        <?php foreach ($classes as $c): ?>
          <option value="<?= $c['id'] ?>" <?= $id_classe == $c['id'] ? 'selected' : '' ?>><?= h($c['designation']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>
</div>

<?php if ($classe_choisie): ?>

<ul class="nav nav-tabs nav-ong mb-0 border-bottom-0">
  <li class="nav-item">
    <a class="nav-link <?= $tab==='documents'?'active':'' ?>" href="?tab=documents&classe=<?= $id_classe ?>">
      <i class="bi bi-files me-1"></i>Documents de classe
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $tab==='honneur'?'active':'' ?>" href="?tab=honneur&classe=<?= $id_classe ?>">
      <i class="bi bi-award me-1"></i>Tableau d'honneur
    </a>
  </li>
</ul>

<?php if ($tab === 'documents'): ?>
<div class="doc-card" style="border-top-left-radius:0">
  <div class="card-body">
    <label class="form-label fw-semibold mb-3" style="color:#1a3c6b">
      Période et document — <?= h($classe_choisie['designation']) ?>
    </label>

    <div class="doc-ordre">
      <span class="fw-semibold" style="color:#1a3c6b">Ordre (relevé de notes) :</span>
      <label><input type="radio" name="docOrdre" value="alpha" checked onchange="docSetOrdre('alpha')"> Alphabétique</label>
      <label><input type="radio" name="docOrdre" value="merite" onchange="docSetOrdre('merite')"> Mérite</label>
      <?php if (signature_configuree('chef_etablissement')): ?>
      <div class="chk-signature form-check form-check-inline mb-0 ms-2" style="user-select:none">
        <input class="form-check-input" type="checkbox" id="chkSigDocExcel" onchange="docSetSignature()">
        <label class="form-check-label small" for="chkSigDocExcel">Signature numérique (Excel)</label>
      </div>
      <?php endif; ?>
    </div>

    <div class="doc-periode-row <?= $trim_comp_actif ? '' : 'disabled' ?>">
      <div class="doc-lbl">
        Trimestriel
        <span class="doc-sub"><?= $trim_comp_actif ? h($trim_comp_actif['libelle'] ?? '') : 'Aucun trimestre en cours' ?></span>
      </div>
      <button type="button" class="btn btn-sm btn-abz-outline"
              onclick="afficherApercu('../conseil_classe/pdf_releve.php?classe=<?= $id_classe ?>&trim=<?= $th_trim ?>&annee=<?= $id_annee ?>&ordre=' + docOrdre, 'Relevé de notes', 'releve_notes', 'landscape')">
        <i class="bi bi-file-earmark-spreadsheet me-1"></i>Relevé de notes
      </button>
      <a class="btn btn-sm btn-abz-gold doc-excel-link" data-href="../conseil_classe/excel_releve.php?classe=<?= $id_classe ?>&trim=<?= $th_trim ?>&annee=<?= $id_annee ?>" href="../conseil_classe/excel_releve.php?classe=<?= $id_classe ?>&trim=<?= $th_trim ?>&annee=<?= $id_annee ?>&ordre=alpha">
        <i class="bi bi-file-earmark-excel me-1"></i>Excel
      </a>
      <button type="button" class="btn btn-sm btn-abz-outline"
              onclick="afficherApercu('pdf_stat_classe.php?classe=<?= $id_classe ?>&trim=<?= $th_trim ?>&annee=<?= $id_annee ?>', 'Fiche statistique', 'stat_classe', 'portrait')">
        <i class="bi bi-file-earmark-bar-graph me-1"></i>Fiche statistique
      </button>
    </div>

    <div class="doc-periode-row">
      <div class="doc-lbl">
        Annuel
        <span class="doc-sub">Année scolaire complète</span>
      </div>
      <button type="button" class="btn btn-sm btn-abz-outline"
              onclick="afficherApercu('../conseil_classe/pdf_releve_annuel.php?classe=<?= $id_classe ?>&annee=<?= $id_annee ?>&ordre=' + docOrdre, 'Relevé de notes annuel', 'releve_notes_annuel', 'landscape')">
        <i class="bi bi-file-earmark-spreadsheet me-1"></i>Relevé de notes
      </button>
      <a class="btn btn-sm btn-abz-gold doc-excel-link" data-href="../conseil_classe/excel_releve_annuel.php?classe=<?= $id_classe ?>&annee=<?= $id_annee ?>" href="../conseil_classe/excel_releve_annuel.php?classe=<?= $id_classe ?>&annee=<?= $id_annee ?>&ordre=alpha">
        <i class="bi bi-file-earmark-excel me-1"></i>Excel
      </a>
      <button type="button" class="btn btn-sm btn-abz-outline"
              onclick="afficherApercu('pdf_stat_classe_annuel.php?classe=<?= $id_classe ?>&annee=<?= $id_annee ?>', 'Fiche statistique annuelle', 'stat_classe_annuel', 'portrait')">
        <i class="bi bi-file-earmark-bar-graph me-1"></i>Fiche statistique
      </button>
    </div>
  </div>
</div>
<script>
let docOrdre = 'alpha';
function docRebuildExcelLinks() {
  const sig = document.getElementById('chkSigDocExcel')?.checked;
  document.querySelectorAll('.doc-excel-link').forEach(function (a) {
    a.href = a.dataset.href + '&ordre=' + docOrdre + (sig ? '&signature=1' : '');
  });
}
function docSetOrdre(mode) {
  docOrdre = mode;
  docRebuildExcelLinks();
}
function docSetSignature() {
  docRebuildExcelLinks();
}
</script>

<?php else: ?>

<div class="doc-card" style="border-top-left-radius:0">
  <div class="card-body">
    <form method="get" class="row g-2 align-items-end">
      <input type="hidden" name="tab" value="honneur">
      <input type="hidden" name="classe" value="<?= $id_classe ?>">
      <div class="col-auto">
        <label class="form-label mb-1" style="font-size:.78rem;font-weight:600;color:#1a3c6b">Période</label>
        <div class="d-flex gap-1">
          <a href="?tab=honneur&classe=<?= $id_classe ?>&vue=trim&modele=<?= $th_modele ?>"
             class="btn btn-sm <?= $th_vue==='trim'?'btn-abz-primary':'btn-abz-outline' ?>">Trimestre</a>
          <a href="?tab=honneur&classe=<?= $id_classe ?>&vue=annee&modele=<?= $th_modele ?>"
             class="btn btn-sm <?= $th_vue==='annee'?'btn-abz-primary':'btn-abz-outline' ?>">Annuel</a>
        </div>
      </div>
      <?php if ($th_vue === 'seq'): ?>
        <input type="hidden" name="vue" value="seq">
      <?php elseif ($th_vue === 'annee'): ?>
      <div class="col-auto">
        <label class="form-label mb-1 d-block" style="font-size:.78rem;font-weight:600;color:#1a3c6b">Année scolaire</label>
        <span class="badge" style="background:#dbeafe;color:#1e3a8a;font-size:.82rem;padding:7px 14px;border-radius:8px">
          <?= h($val_annee ?: '—') ?>
        </span>
        <input type="hidden" name="vue" value="annee">
      </div>
      <?php else: ?>
      <div class="col-auto">
        <label class="form-label mb-1 d-block" style="font-size:.78rem;font-weight:600;color:#1a3c6b">Trimestre</label>
        <span class="badge" style="background:#dbeafe;color:#1e3a8a;font-size:.82rem;padding:7px 14px;border-radius:8px">
          <?= $th_trim ? h($trim_comp_actif['libelle'] ?? '') . ' (en cours)' : 'Aucun trimestre en cours' ?>
        </span>
        <input type="hidden" name="vue" value="trim">
      </div>
      <?php endif; ?>
      <div class="col-md-3">
        <label class="form-label mb-1" style="font-size:.78rem;font-weight:600;color:#1a3c6b">Modèle du certificat</label>
        <select name="modele" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="1" <?= $th_modele===1?'selected':'' ?>>Modèle 1 — Classique</option>
          <option value="2" <?= $th_modele===2?'selected':'' ?>>Modèle 2 — Sobre</option>
          <option value="3" <?= $th_modele===3?'selected':'' ?>>Modèle 3 — Diplôme</option>
        </select>
      </div>
    </form>
  </div>
</div>

<?php if ($eleves_qualifies): ?>
<div class="doc-card mt-3">
  <div class="card-body">
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
      <h6 class="fw-bold mb-0" style="color:#1a3c6b"><?= count($eleves_qualifies) ?> élève(s) au tableau d'honneur</h6>
      <button type="button" class="btn btn-sm btn-abz-primary"
              onclick="afficherApercu('../tableau_honneur/pdf_certificat.php?classe=<?= $id_classe ?>&<?= $th_periode_qs ?>&annee=<?= $id_annee ?>&modele=<?= $th_modele ?>', 'Tableau d\'honneur — tous', 'tableau_honneur_<?= $th_modele ?>', 'landscape')">
        <i class="bi bi-printer me-1"></i>Imprimer tous
      </button>
    </div>
    <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>Rang</th><th>Nom et prénoms</th><th>Moyenne</th><th>Mention</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($eleves_qualifies as $eq): ?>
        <tr>
          <td><?= $eq['rang'] ?>e /<?= $eq['nb_classes'] ?></td>
          <td class="fw-semibold"><?= h(strtoupper($eq['nom']) . ' ' . $eq['prenom']) ?></td>
          <td><?= number_format($eq['moy'], 2) ?>/20</td>
          <td>
            <span class="badge <?= $eq['mention']==='Félicitations'?'th-badge-felicit':($eq['mention']==='Encouragement'?'th-badge-encour':'th-badge-simple') ?>">
              <?= h($eq['mention']) ?>
            </span>
          </td>
          <td class="text-end">
            <button type="button" class="btn btn-sm btn-abz-outline"
                    onclick="afficherApercu('../tableau_honneur/pdf_certificat.php?classe=<?= $id_classe ?>&<?= $th_periode_qs ?>&annee=<?= $id_annee ?>&modele=<?= $th_modele ?>&eleve=<?= $eq['id'] ?>', 'Tableau d\'honneur', 'tableau_honneur_<?= $th_modele ?>', 'landscape')">
              <i class="bi bi-eye me-1"></i>Voir / Imprimer
            </button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>
<?php else: ?>
<div class="alert alert-info mt-3"><i class="bi bi-info-circle me-2"></i>Aucun élève au tableau d'honneur pour cette classe et cette période.</div>
<?php endif; ?>

<?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../../../layout/footer.php'; ?>
