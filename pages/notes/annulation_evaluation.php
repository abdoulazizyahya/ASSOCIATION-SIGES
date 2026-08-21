<?php
// pages/notes/annulation_evaluation.php — Annulation d'évaluation par élève
// (transfert tardif, maladie longue...) et par classe entière (ex. évaluation
// non organisée pour toute la classe) — remplace l'ancienne « Annulation de
// trimestre » (migration_v37, jamais utilisée en production — 0 ligne
// réelle) par une granularité plus fine : UNE évaluation (séquence, ex. UA3)
// plutôt que tout un trimestre. Demande explicite du 17/08/2026, voir
// notes_apc.php::annuler_evaluation_eleve()/evaluation_annulee_pour_eleve().
// Effet : l'évaluation annulée n'est ni notée-zéro-automatiquement, ni
// affichée sur le bulletin, ni comptée dans le diviseur (moyenne de la
// compétence = moyenne des SEULES séquences restantes du trimestre) pour
// l'élève concerné — comme s'il n'avait pas composé cette séquence précise
// (totalement exclu, contrairement à un simple 0). Réservé au Directeur
// (impact direct sur classement/bulletins officiels), et — pour l'annulation
// individuelle — aux élèves sans note ou ayant composé moins de 50% des
// compétences pour l'évaluation choisie (même filtre que l'ancienne page,
// recalculé par séquence plutôt que par trimestre).
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/../../notes_apc.php';
exiger_role(['DIRECTEUR']);
exiger_annee_active(); // Année scolaire réellement active requise (18/08/2026) — module Pédagogie/Discipline.

$annee     = get_annee_active();
$val_annee = $annee['val_annee'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $action    = post('action');
    $id_classe = (int) post('id_classe');
    $id_seq    = (int) post('id_seq');
    $motif     = trim(post('motif')) ?: null;
    $agent     = utilisateur_connecte()['id'] ?? null;

    if ($action === 'annuler_un' && $id_classe && $id_seq) {
        $id_eleve = (int) post('id_eleve');
        if ($id_eleve) {
            annuler_evaluation_eleve($id_eleve, $id_classe, $id_seq, $val_annee, $motif, $agent);
            flash_set('succes', 'Évaluation annulée pour cet élève.');
        }
        rediriger('pages/notes/annulation_evaluation.php?classe=' . $id_classe . '&seq=' . $id_seq);
    }

    if ($action === 'retablir_un' && $id_classe && $id_seq) {
        $id_eleve = (int) post('id_eleve');
        if ($id_eleve) {
            retablir_evaluation_eleve($id_eleve, $id_classe, $id_seq, $val_annee);
            flash_set('succes', 'Évaluation rétablie pour cet élève.');
        }
        rediriger('pages/notes/annulation_evaluation.php?classe=' . $id_classe . '&seq=' . $id_seq);
    }

    if ($action === 'annuler_classe' && $id_classe && $id_seq) {
        $eleves = db_all(
            "SELECT e.id_eleve FROM eleve e JOIN inscrire i ON i.id_eleve=e.id_eleve
             WHERE i.IDClasses=? AND i.val_annee=? AND e.statut='actif'",
            [$id_classe, $val_annee]
        );
        foreach ($eleves as $e) {
            annuler_evaluation_eleve((int) $e['id_eleve'], $id_classe, $id_seq, $val_annee, $motif, $agent);
        }
        flash_set('succes', count($eleves) . " élève(s) — évaluation annulée pour toute la classe.");
        rediriger('pages/notes/annulation_evaluation.php?classe=' . $id_classe . '&seq=' . $id_seq);
    }

    if ($action === 'retablir_classe' && $id_classe && $id_seq) {
        $eleves = db_all(
            "SELECT e.id_eleve FROM eleve e JOIN inscrire i ON i.id_eleve=e.id_eleve
             WHERE i.IDClasses=? AND i.val_annee=? AND e.statut='actif'",
            [$id_classe, $val_annee]
        );
        foreach ($eleves as $e) {
            retablir_evaluation_eleve((int) $e['id_eleve'], $id_classe, $id_seq, $val_annee);
        }
        flash_set('succes', "Évaluation rétablie pour toute la classe.");
        rediriger('pages/notes/annulation_evaluation.php?classe=' . $id_classe . '&seq=' . $id_seq);
    }
}

$classes = db_all(
    "SELECT c.IDClasses, c.DesignationClasses, n.OrdreNiveau
     FROM classe c LEFT JOIN niveau n ON n.LibelleNiveau = c.Niveau
     ORDER BY n.OrdreNiveau, c.DesignationClasses"
);
$trimestres = db_all("SELECT id_trim, libelle_trim FROM trimestre WHERE id_annee=? ORDER BY id_trim", [$val_annee]);

$id_classe_sel = (int) ($_GET['classe'] ?? 0);
$id_trim_sel   = (int) ($_GET['trim'] ?? 0);
$id_seq_sel    = (int) ($_GET['seq'] ?? 0);

// Séquences du trimestre choisi (1 ou 2, ex. UA3/UA4) — si le trimestre
// posté ne correspond à aucune séquence choisie, on prend la 1ère par défaut.
$seqs_du_trim = $id_trim_sel ? sequences_du_trimestre($id_trim_sel) : [];
$seqs_du_trim_info = $id_trim_sel
    ? db_all("SELECT id_seq, libelle_seq FROM sequence WHERE id_trim=? ORDER BY id_seq", [$id_trim_sel])
    : [];
if ($id_seq_sel && !in_array($id_seq_sel, $seqs_du_trim, true)) $id_seq_sel = 0;
if (!$id_seq_sel && $seqs_du_trim) $id_seq_sel = (int) $seqs_du_trim[0];

$eleves = [];
$annulations_idx = [];
if ($id_classe_sel && $id_seq_sel) {
    // Liste volontairement restreinte aux élèves qui n'ont composé AUCUNE
    // compétence pour cette évaluation OU qui en ont composé moins de 50% —
    // demande explicite du 17/08/2026. Un élève ayant normalement composé
    // cette évaluation n'a pas besoin d'être annulé.
    $total_comp = count(competences_classe($id_classe_sel, $val_annee));
    $seuil = $total_comp * 0.5;
    $eleves = db_all(
        "SELECT e.id_eleve, e.Nom_elv, e.Prenom_elv, e.Mat_elv,
                (SELECT COUNT(DISTINCT cs.id_comp) FROM composer_sequence cs
                 WHERE cs.id_eleve = e.id_eleve AND cs.IDClasses = ? AND cs.id_seq = ? AND cs.val_annee = ?) AS nb_composees
         FROM eleve e
         JOIN inscrire i ON i.id_eleve = e.id_eleve
         WHERE i.IDClasses = ? AND i.val_annee = ? AND e.statut = 'actif'
         HAVING nb_composees = 0 OR nb_composees < ?
         ORDER BY e.Nom_elv, e.Prenom_elv",
        [$id_classe_sel, $id_seq_sel, $val_annee, $id_classe_sel, $val_annee, $seuil]
    );
    $annulations = evaluations_annulees_classe($id_classe_sel, $id_seq_sel, $val_annee);
    foreach ($annulations as $a) { $annulations_idx[(int) $a['id_eleve']] = $a; }
}

$es_partiel = isset($_GET['partiel']);
if (!$es_partiel) {
    $titre_page = 'Annulation d\'évaluation';
    require_once __DIR__ . '/../../layout/header.php';
} else {
    header('Content-Type: text/html; charset=utf-8');
}
?>

<div id="annulation-eval-zone">

<div class="page-titre">
  <div>
    <h4><i class="bi bi-calendar-x me-1 text-primary"></i>Annulation d'évaluation</h4>
    <div class="sub">Année <?= h($val_annee) ?> — exempte un élève (ou toute une classe) d'une évaluation pour le calcul des moyennes/classement</div>
  </div>
  <a href="<?= APP_URL ?>/pages/notes/index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Saisie des notes</a>
</div>

<div class="alert alert-light border py-2 mb-3" style="font-size:.8rem">
  <i class="bi bi-info-circle me-1"></i>
  Une évaluation annulée pour un élève est totalement exclue de son calcul (aucun zéro automatique, pas de « non classé » pénalisant, jamais
  affichée sur le bulletin) — la moyenne de chaque compétence se rabat alors sur la <strong>seule séquence restante</strong> du trimestre
  (jamais divisée par le nombre d'évaluations d'origine). À réserver aux cas réels (transfert tardif, maladie longue, évaluation non
  organisée pour toute la classe...). La liste ci-dessous n'affiche que les élèves <strong>sans aucune note</strong> ou ayant composé
  <strong>moins de 50%</strong> des compétences pour l'évaluation choisie.
</div>

<div class="card mb-3">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end" data-ajax-nav-form>
      <div class="col-md-4">
        <label class="form-label">Classe</label>
        <select name="classe" class="form-select" data-ajax-nav-auto>
          <option value="">— Choisir —</option>
          <?php foreach ($classes as $c): ?>
            <option value="<?= $c['IDClasses'] ?>" <?= $id_classe_sel == $c['IDClasses'] ? 'selected' : '' ?>>
              <?= h($c['DesignationClasses']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Trimestre</label>
        <select name="trim" class="form-select" data-ajax-nav-auto>
          <option value="">— Choisir —</option>
          <?php foreach ($trimestres as $t): ?>
            <option value="<?= $t['id_trim'] ?>" <?= $id_trim_sel == $t['id_trim'] ? 'selected' : '' ?>>
              <?= h($t['libelle_trim']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($id_trim_sel && $seqs_du_trim_info): ?>
      <div class="col-md-4">
        <label class="form-label">Évaluation</label>
        <select name="seq" class="form-select" data-ajax-nav-auto>
          <?php foreach ($seqs_du_trim_info as $s): ?>
            <option value="<?= $s['id_seq'] ?>" <?= $id_seq_sel == $s['id_seq'] ? 'selected' : '' ?>>
              <?= h($s['libelle_seq']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <?php if ($id_classe_sel): ?><input type="hidden" name="classe" value="<?= $id_classe_sel ?>"><?php endif; ?>
    </form>
  </div>
</div>

<?php if ($id_classe_sel && $id_seq_sel && $eleves): ?>

<div class="card mb-3">
  <div class="card-header py-2 d-flex justify-content-between align-items-center" style="background:#f8faff">
    <span class="fw-semibold" style="font-size:.82rem"><i class="bi bi-people me-1"></i>Action groupée — toute la classe</span>
    <div class="d-flex gap-2">
      <form method="post" data-ajax-post-form onsubmit="return confirm('Annuler cette évaluation pour TOUS les élèves de cette classe ?')" class="d-flex gap-1">
        <?= csrf_champ() ?>
        <input type="hidden" name="action" value="annuler_classe">
        <input type="hidden" name="id_classe" value="<?= $id_classe_sel ?>">
        <input type="hidden" name="id_seq" value="<?= $id_seq_sel ?>">
        <input type="text" name="motif" class="form-control form-control-sm" placeholder="Motif (optionnel)" style="width:200px">
        <button class="btn btn-outline-danger btn-sm"><i class="bi bi-x-circle me-1"></i>Annuler pour la classe</button>
      </form>
      <form method="post" data-ajax-post-form onsubmit="return confirm('Rétablir cette évaluation pour TOUS les élèves de cette classe ?')">
        <?= csrf_champ() ?>
        <input type="hidden" name="action" value="retablir_classe">
        <input type="hidden" name="id_classe" value="<?= $id_classe_sel ?>">
        <input type="hidden" name="id_seq" value="<?= $id_seq_sel ?>">
        <button class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-counterclockwise me-1"></i>Tout rétablir</button>
      </form>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header py-2" style="background:#f8faff">
    <span class="fw-semibold" style="font-size:.82rem"><i class="bi bi-list me-1"></i>Élèves sans note / moins de 50% des compétences composées (<?= count($eleves) ?>)</span>
  </div>
  <div class="table-responsive">
    <table class="table table-abz table-hover align-middle mb-0" style="font-size:.82rem">
      <thead><tr><th>Élève</th><th>Matricule</th><th>Compétences composées</th><th>Statut</th><th>Motif / annulé par</th><th class="text-end">Action</th></tr></thead>
      <tbody>
        <?php foreach ($eleves as $e): $eid = (int) $e['id_eleve']; $a = $annulations_idx[$eid] ?? null; ?>
        <tr>
          <td class="fw-semibold"><?= h(mb_strtoupper($e['Nom_elv'])) ?> <?= h($e['Prenom_elv'] ?? '') ?></td>
          <td><?= h($e['Mat_elv']) ?></td>
          <td><?= (int) $e['nb_composees'] ?> / <?= $total_comp ?></td>
          <td>
            <?php if ($a): ?>
              <span class="badge" style="background:#fee2e2;color:#991b1b;font-size:.7rem">Annulée</span>
            <?php else: ?>
              <span class="badge" style="background:#dcfce7;color:#166534;font-size:.7rem">Normal</span>
            <?php endif; ?>
          </td>
          <td style="font-size:.75rem;color:#6b7280">
            <?php if ($a): ?>
              <?= h($a['motif'] ?: '—') ?><?= $a['annule_par'] ? ' — ' . h($a['annule_par']) : '' ?>
              <div><?= h(date_fr($a['annule_le'])) ?></div>
            <?php endif; ?>
          </td>
          <td class="text-end">
            <?php if ($a): ?>
              <form method="post" style="display:inline" data-ajax-post-form onsubmit="return confirm('Rétablir cette évaluation pour cet élève ?')">
                <?= csrf_champ() ?>
                <input type="hidden" name="action" value="retablir_un">
                <input type="hidden" name="id_classe" value="<?= $id_classe_sel ?>">
                <input type="hidden" name="id_seq" value="<?= $id_seq_sel ?>">
                <input type="hidden" name="id_eleve" value="<?= $eid ?>">
                <button class="btn btn-sm btn-light text-success" style="padding:2px 8px"><i class="bi bi-arrow-counterclockwise me-1"></i>Rétablir</button>
              </form>
            <?php else: ?>
              <button type="button" class="btn btn-sm btn-light text-danger" style="padding:2px 8px" onclick="ouvrirAnnulation(<?= $eid ?>, <?= h(json_encode(mb_strtoupper($e['Nom_elv']) . ' ' . ($e['Prenom_elv'] ?? ''))) ?>)">
                <i class="bi bi-x-circle me-1"></i>Annuler
              </button>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modale annulation individuelle (motif) -->
<div class="modal fade" id="modalAnnuler" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title fw-bold" style="font-size:.88rem"><i class="bi bi-x-circle me-1 text-danger"></i>Annuler l'évaluation</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" data-ajax-post-form>
        <?= csrf_champ() ?>
        <input type="hidden" name="action" value="annuler_un">
        <input type="hidden" name="id_classe" value="<?= $id_classe_sel ?>">
        <input type="hidden" name="id_seq" value="<?= $id_seq_sel ?>">
        <input type="hidden" name="id_eleve" id="ann-id-eleve">
        <div class="modal-body">
          <p class="mb-2" style="font-size:.85rem">Élève : <strong id="ann-nom-eleve"></strong></p>
          <label class="form-label">Motif (optionnel)</label>
          <input type="text" name="motif" class="form-control" placeholder="ex. Transfert tardif, maladie longue...">
        </div>
        <div class="modal-footer py-2">
          <button class="btn btn-danger btn-sm">Confirmer l'annulation</button>
          <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Annuler</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
function ouvrirAnnulation(idEleve, nom) {
    document.getElementById('ann-id-eleve').value = idEleve;
    document.getElementById('ann-nom-eleve').textContent = nom;
    new bootstrap.Modal(document.getElementById('modalAnnuler')).show();
}
</script>

<?php elseif ($id_classe_sel && $id_seq_sel): ?>
  <div class="alert alert-light text-muted py-4 text-center">Aucun élève sans note ou en dessous de 50% des compétences composées dans cette classe pour cette évaluation.</div>
<?php elseif ($id_classe_sel && $id_trim_sel && !$seqs_du_trim_info): ?>
  <div class="alert alert-warning">Aucune évaluation configurée pour ce trimestre.</div>
<?php else: ?>
  <div class="alert alert-light text-muted py-4 text-center">
    <i class="bi bi-arrow-up" style="font-size:2rem;display:block;opacity:.2;margin-bottom:.4rem"></i>
    Sélectionnez une classe, un trimestre puis une évaluation.
  </div>
<?php endif; ?>

</div><!-- /#annulation-eval-zone -->
<?php if ($es_partiel) exit; // rien de plus dans une réponse AJAX partielle. ?>

<?php
$ajax_zone_id = 'annulation-eval-zone'; // voir layout/footer.php — initAjaxZone() y est appelé
require_once __DIR__ . '/../../layout/footer.php';
