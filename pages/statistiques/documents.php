<?php
// ── Documents de classe — port fidèle d'ABZ_MBE ──────────────────────
// Point d'entrée à 2 onglets (Documents de classe / Tableau d'honneur),
// même structure visuelle (cartes « doc-periode-row », navigation AJAX,
// choix Alphabétique/Mérite) — regroupe les documents déjà construits
// (relevé de notes, fiche statistique, tableau d'honneur) plutôt que de
// les reconstruire.
//
// Écarts assumés (documentés dès la construction initiale, session 5,
// non repris ici faute de temps dans ce chantier de parité) :
//  - Scope TRIMESTRIEL uniquement (pas de bloc « Annuel » comme ABZ_MBE) —
//    pdf/certificat_tableau_honneur.php ne gère que le trimestriel.
//  - Un seul modèle de certificat (pas de sélecteur Modèle 1/2/3).
//  - Pas d'export Excel du relevé de notes (jamais construit pour la piste
//    française, contrairement au PDF).
header('Cache-Control: no-store, no-cache, must-revalidate');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/../../notes_apc.php';
exiger_acces_pedagogie();
exiger_annee_active(); // Année scolaire réellement active requise (18/08/2026) — module Pédagogie/Discipline.

$es_partiel = isset($_GET['partiel']);

$annee      = get_annee_active();
$val_annee  = $annee['val_annee'] ?? '';
$seq_active = get_sequence_active();

$id_classe = (int) ($_GET['classe'] ?? 0);
$id_trim   = (int) ($_GET['trim'] ?? ($seq_active['id_trim'] ?? 0));
$tab       = in_array($_GET['tab'] ?? '', ['documents', 'honneur'], true) ? $_GET['tab'] : 'documents';

$classes = db_all(
    "SELECT c.IDClasses, c.DesignationClasses, n.OrdreNiveau
     FROM classe c LEFT JOIN niveau n ON n.LibelleNiveau = c.Niveau
     JOIN inscrire i ON i.IDClasses = c.IDClasses AND i.val_annee = ?
     GROUP BY c.IDClasses, c.DesignationClasses, n.OrdreNiveau
     ORDER BY n.OrdreNiveau, c.DesignationClasses",
    [$val_annee]
);
$classe_choisie = null;
foreach ($classes as $c) { if ((int) $c['IDClasses'] === $id_classe) { $classe_choisie = $c; break; } }

$qualifies = [];
if ($tab === 'honneur' && $id_classe && $id_trim) {
    $classement = classement_trimestre_classe($id_classe, $id_trim, $val_annee);
    foreach ($classement['lignes'] as $l) {
        if ($l['moy'] === null) continue;
        $jours = jours_absence_non_justifiees_trimestre((int) $l['id_eleve'], $id_classe, $id_trim, $val_annee);
        $m = mention_travail((float) $l['moy'], $jours);
        if (!$m['tableau_honneur']) continue;
        $qualifies[] = ['ligne' => $l, 'mention' => $m, 'nb_classes' => $classement['nb_classes']];
    }
}

if (!$es_partiel) {
    $titre_page = 'Documents de classe';
    require_once __DIR__ . '/../../layout/header.php';
    ?>
    <style>
    .doc-card{border:1px solid #c7d8f0;border-radius:10px;background:#fff;}
    .doc-card .card-body{padding:1.25rem;}
    .doc-classe-select{max-width:420px}
    .doc-periode-row{display:flex;align-items:center;flex-wrap:wrap;gap:.75rem;padding:.9rem 1rem;border:1px solid #e3e9f5;border-radius:8px;margin-bottom:.7rem;background:#f8faff;}
    .doc-periode-row .doc-lbl{min-width:180px;font-weight:600;color:#1a3c6b;}
    .doc-periode-row .doc-sub{font-size:.78rem;color:#666;font-weight:400;display:block;}
    .doc-periode-row.disabled{opacity:.55;pointer-events:none;}
    .doc-ordre{display:flex;align-items:center;flex-wrap:wrap;gap:1rem;margin-bottom:1rem;font-size:.85rem}
    .doc-ordre label{cursor:pointer}
    .th-badge-felicit{background:#fde3e3;color:#b91c1c;}
    .th-badge-encour{background:#dbeafe;color:#1e3a8a;}
    .th-badge-simple{background:#e5e7eb;color:#374151;}
    .nav-ong .nav-link{color:#1a3c6b;border-radius:6px 6px 0 0;font-size:.83rem;}
    .nav-ong .nav-link.active{background:#1a3c6b;color:#fff;font-weight:600;}
    </style>

    <div class="page-titre d-flex align-items-center justify-content-between flex-wrap gap-2">
      <h4><i class="bi bi-files me-2" style="color:#1a3c6b"></i>Documents de classe</h4>
      <span class="badge" style="background:#dbeafe;color:#1e3a8a;font-size:.78rem;padding:5px 12px;border-radius:20px"><?= h($val_annee) ?></span>
    </div>

    <?= flash_html() ?>
    <?php
} else {
    header('Content-Type: text/html; charset=utf-8');
}
?>

<div id="doc-zone">

<div class="doc-card mb-3">
  <div class="card-body">
    <label class="form-label fw-semibold" style="color:#1a3c6b">1. Choisir une classe</label>
    <form method="get" class="doc-classe-select" data-ajax-nav-form action="<?= APP_URL ?>/pages/statistiques/documents.php">
      <input type="hidden" name="tab" value="<?= h($tab) ?>">
      <select name="classe" class="form-select" data-ajax-nav-auto>
        <option value="">— Sélectionner une classe —</option>
        <?php foreach ($classes as $c): ?>
          <option value="<?= $c['IDClasses'] ?>" <?= $id_classe == $c['IDClasses'] ? 'selected' : '' ?>><?= h($c['DesignationClasses']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>
</div>

<?php if ($classe_choisie): ?>

<ul class="nav nav-tabs nav-ong mb-0 border-bottom-0">
  <li class="nav-item">
    <a class="nav-link <?= $tab === 'documents' ? 'active' : '' ?>" href="?tab=documents&classe=<?= $id_classe ?>&trim=<?= $id_trim ?>" data-ajax-nav>
      <i class="bi bi-files me-1"></i>Documents de classe
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $tab === 'honneur' ? 'active' : '' ?>" href="?tab=honneur&classe=<?= $id_classe ?>&trim=<?= $id_trim ?>" data-ajax-nav>
      <i class="bi bi-award me-1"></i>Tableau d'honneur
    </a>
  </li>
</ul>

<?php if ($tab === 'documents'): ?>
<div class="doc-card" style="border-top-left-radius:0">
  <div class="card-body">
    <label class="form-label fw-semibold mb-3" style="color:#1a3c6b">
      Période et document — <?= h($classe_choisie['DesignationClasses']) ?>
    </label>

    <div class="doc-ordre">
      <span class="fw-semibold" style="color:#1a3c6b">Ordre (relevé de notes) :</span>
      <label><input type="radio" name="docOrdre" value="alpha" onchange="docSetOrdre('alpha')"> Alphabétique</label>
      <label><input type="radio" name="docOrdre" value="merite" checked onchange="docSetOrdre('merite')"> Mérite</label>
    </div>

    <div class="doc-periode-row <?= $id_trim ? '' : 'disabled' ?>">
      <div class="doc-lbl">
        Trimestriel
        <span class="doc-sub"><?php
          $trim_lib = '';
          foreach (db_all("SELECT id_trim, libelle_trim FROM trimestre WHERE id_annee=?", [$val_annee]) as $t) { if ($t['id_trim'] == $id_trim) { $trim_lib = $t['libelle_trim']; break; } }
          echo $id_trim ? h($trim_lib) . ($seq_active['id_trim'] == $id_trim ? ' (en cours)' : '') : 'Aucun trimestre';
        ?></span>
      </div>
      <button type="button" class="btn btn-sm btn-abz-outline doc-releve-btn"
              onclick="afficherApercu('<?= APP_URL ?>/pdf/releve_notes_classe.php?classe=<?= $id_classe ?>&trim=<?= $id_trim ?>&ordre=' + docOrdre, 'Relevé de notes', 'releve_notes', 'landscape')">
        <i class="bi bi-file-earmark-spreadsheet me-1"></i>Relevé de notes
      </button>
      <button type="button" class="btn btn-sm btn-abz-outline"
              onclick="afficherApercu('<?= APP_URL ?>/pdf/fiche_statistique_classe.php?classe=<?= $id_classe ?>&trim=<?= $id_trim ?>', 'Fiche statistique', 'fiche_statistique', 'portrait')">
        <i class="bi bi-file-earmark-bar-graph me-1"></i>Fiche statistique
      </button>
      <a href="<?= APP_URL ?>/pages/conseil_classe/index.php?type=trimestre&classe=<?= $id_classe ?>&trim=<?= $id_trim ?>" class="btn btn-sm btn-abz-outline">
        <i class="bi bi-mortarboard me-1"></i>Conseil de classe
      </a>
    </div>

    <div class="doc-periode-row">
      <div class="doc-lbl">
        Annuel
        <span class="doc-sub">Année scolaire complète</span>
      </div>
      <button type="button" class="btn btn-sm btn-abz-outline"
              onclick="afficherApercu('<?= APP_URL ?>/pdf/releve_notes_annuel.php?classe=<?= $id_classe ?>', 'Relevé de notes annuel', 'releve_notes_annuel', 'landscape')">
        <i class="bi bi-file-earmark-spreadsheet me-1"></i>Relevé de notes annuel
      </button>
      <a href="<?= APP_URL ?>/pages/bulletins/index.php?classe=<?= $id_classe ?>&vue=annee" class="btn btn-sm btn-abz-outline">
        <i class="bi bi-file-earmark-text me-1"></i>Bulletins annuels
      </a>
      <a href="<?= APP_URL ?>/pages/resultat_annuel/index.php?onglet=classe&classe=<?= $id_classe ?>" class="btn btn-sm btn-abz-outline">
        <i class="bi bi-trophy me-1"></i>Résultat annuel
      </a>
    </div>
  </div>
</div>

<?php else: ?>

<div class="doc-card" style="border-top-left-radius:0">
  <div class="card-body">
    <div class="d-flex align-items-center gap-3 flex-wrap" style="font-size:.85rem">
      <span class="fw-semibold" style="color:#1a3c6b">Période :</span>
      <span class="badge" style="background:#dbeafe;color:#1e3a8a;font-size:.82rem;padding:7px 14px;border-radius:8px">
        <?php
        $trim_lib = '';
        foreach (db_all("SELECT id_trim, libelle_trim FROM trimestre WHERE id_annee=?", [$val_annee]) as $t) { if ($t['id_trim'] == $id_trim) { $trim_lib = $t['libelle_trim']; break; } }
        echo $id_trim ? h($trim_lib) . ($seq_active['id_trim'] == $id_trim ? ' (en cours)' : '') : 'Aucun trimestre';
        ?>
      </span>
    </div>
  </div>
</div>

<?php if ($qualifies): ?>
<div class="doc-card mt-3">
  <div class="card-body">
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
      <h6 class="fw-bold mb-0" style="color:#1a3c6b"><?= count($qualifies) ?> élève(s) au tableau d'honneur</h6>
      <div class="d-flex align-items-center gap-2">
        <label class="form-label mb-0 small fw-semibold" style="color:#1a3c6b" for="thModele">Modèle :</label>
        <select id="thModele" class="form-select form-select-sm" style="width:auto">
          <option value="1">1 — Classique</option>
          <option value="2">2 — Orné (avec QR)</option>
        </select>
        <button type="button" class="btn btn-sm btn-abz-primary"
                onclick="thOuvrirCertificat(<?= $id_classe ?>, <?= $id_trim ?>, 0)">
          <i class="bi bi-printer me-1"></i>Imprimer tous
        </button>
      </div>
    </div>
    <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead><tr><th>Rang</th><th>Nom et prénoms</th><th>Moyenne</th><th>Mention</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($qualifies as $q):
        $l = $q['ligne']; $m = $q['mention'];
        $mention_lbl = $m['felicitations'] ? 'Félicitations' : ($m['encouragement'] ? 'Encouragement' : "Tableau d'honneur");
        $badge_cls = $m['felicitations'] ? 'th-badge-felicit' : ($m['encouragement'] ? 'th-badge-encour' : 'th-badge-simple');
      ?>
        <tr>
          <td><?= h($l['rang']) ?> /<?= $q['nb_classes'] ?></td>
          <td class="fw-semibold"><?= h(mb_strtoupper($l['Nom_elv'])) ?> <?= h($l['Prenom_elv'] ?? '') ?></td>
          <td><?= number_format((float) $l['moy'], 2) ?>/20</td>
          <td><span class="badge <?= $badge_cls ?>"><?= h($mention_lbl) ?></span></td>
          <td class="text-end">
            <button type="button" class="btn btn-sm btn-abz-outline"
                    onclick="thOuvrirCertificat(<?= $id_classe ?>, <?= $id_trim ?>, <?= (int) $l['id_eleve'] ?>)">
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
<div class="alert alert-info mt-3"><i class="bi bi-info-circle me-2"></i>Aucun élève au tableau d'honneur pour cette classe et ce trimestre.</div>
<?php endif; ?>

<?php endif; ?>

<?php else: ?>
  <div class="text-center py-5 text-muted">
    <i class="bi bi-arrow-up-circle" style="font-size:3rem;opacity:.2;display:block;margin-bottom:1rem"></i>
    Sélectionnez une classe.
  </div>
<?php endif; ?>

</div><!-- /#doc-zone -->

<?php
if ($es_partiel) exit; // rien de plus dans une réponse AJAX partielle.
?>

<script>
// Ordre du relevé de notes (Alphabétique/Mérite) — état purement client.
// Défini EN DEHORS de #doc-zone (jamais remplacé par un rechargement AJAX
// partiel) — même convention qu'ABZ_MBE.
let docOrdre = 'merite';
function docSetOrdre(mode) { docOrdre = mode; }
document.getElementById('doc-zone').addEventListener('partielCharge', () => { docOrdre = 'merite'; });

// Tableau d'honneur — ouvre le certificat avec le modèle actuellement
// sélectionné (#thModele, état purement client, comme docOrdre ci-dessus).
// idEleve=0 → tous les élèves qualifiés de la classe/trimestre.
function thOuvrirCertificat(idClasse, idTrim, idEleve) {
  const sel = document.getElementById('thModele');
  const modele = sel ? sel.value : '1';
  let url = '<?= APP_URL ?>/pdf/certificat_tableau_honneur.php?classe=' + idClasse + '&trim=' + idTrim + '&modele=' + modele;
  if (idEleve) url += '&eleve=' + idEleve;
  const titre = idEleve ? "Tableau d'honneur" : "Tableau d'honneur — tous";
  afficherApercu(url, titre, 'tableau_honneur', 'landscape');
}
</script>

<?php
$ajax_zone_id = 'doc-zone'; // voir layout/footer.php — initAjaxZone() y est appelé après sa propre définition
require_once __DIR__ . '/../../layout/footer.php';
