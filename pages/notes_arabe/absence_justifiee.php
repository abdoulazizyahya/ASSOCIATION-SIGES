<?php
/**
 * pages/notes_arabe/absence_justifiee.php — Notes justifiées (piste arabe,
 * matières) — miroir de pages/notes/absence_justifiee.php (piste française),
 * adapté au modèle matière+coefficient de la piste arabe (composer_sequence_
 * arabe) — reproduction de pages/absences/index.php d'ABZ_MBE. Demande
 * explicite du 16/08/2026.
 *
 * Différence assumée avec la piste française : la piste arabe n'a PAS de
 * zéro automatique (vérifié, voir en-tête notes_apc_arabe.php — une matière
 * non composée est déjà simplement exclue du coefficient, jamais notée 0).
 * Cette page est donc ici un registre administratif (pourquoi la note d'un
 * élève est manquante pour telle matière/séquence), sans effet sur le calcul
 * de la moyenne — à la différence de la piste française où la justification
 * évite un zéro. Aucune recalculation à déclencher après enregistrement.
 *
 * Séquence toujours l'évaluation ACTIVE (même politique que Saisie des
 * notes arabe) — un élève déjà noté ne peut pas être justifié pour cette
 * séquence.
 */
header('Cache-Control: no-store, no-cache, must-revalidate');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/../../notes_apc.php';
require_once __DIR__ . '/../../notes_apc_arabe.php';
exiger_connexion();
exiger_annee_active(); // Année scolaire réellement active requise (18/08/2026) — module Pédagogie/Discipline.

$annee_act = get_annee_active();
$val_annee = $annee_act['val_annee'] ?? '';

$seq_active_brut = get_sequence_active();
$seq_active = null;
if (!empty($seq_active_brut['id_seq'])) {
    $seq_active = db_one(
        "SELECT s.id_seq, s.libelle_seq, t.libelle_trim
         FROM sequence s JOIN trimestre t ON t.id_trim = s.id_trim
         WHERE s.id_seq = ?", [$seq_active_brut['id_seq']]
    );
}
$id_seq = (int) ($seq_active['id_seq'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $id_classe_p = (int) post('id_classe');
    $id_mat_p    = (int) post('id_mat');
    $id_seq_p    = (int) post('id_seq');
    $agent       = utilisateur_connecte()['id'] ?? null;

    if ($id_classe_p && $id_mat_p && $id_seq_p && $id_seq_p === $id_seq) {
        $eleves_ids = array_column(
            db_all(
                "SELECT e.id_eleve FROM eleve e JOIN inscrire i ON i.id_eleve=e.id_eleve
                 WHERE i.IDClasses=? AND i.val_annee=? AND e.statut='actif'",
                [$id_classe_p, $val_annee]
            ),
            'id_eleve'
        );
        $notes_eleves = array_column(
            db_all("SELECT id_eleve FROM composer_sequence_arabe WHERE id_mat=? AND classe=? AND id_seq=?",
                [$id_mat_p, $id_classe_p, $id_seq_p]),
            'id_eleve'
        );

        // post() ne gère que les champs scalaires — justifie[]/raison[] sont
        // des tableaux indexés par id_eleve, à lire directement sur $_POST
        // (voir le commentaire équivalent dans pages/notes/absence_justifiee.php,
        // bug réel trouvé en testant cette page-ci).
        $justifie_post = is_array($_POST['justifie'] ?? null) ? $_POST['justifie'] : [];
        $raison_post   = is_array($_POST['raison'] ?? null) ? $_POST['raison'] : [];
        $n_saved = 0;
        foreach ($eleves_ids as $eid) {
            $eid = (int) $eid;
            if (in_array($eid, $notes_eleves, true)) continue;

            $justifie = isset($justifie_post[$eid]) ? 1 : 0;
            $raison   = trim((string) ($raison_post[$eid] ?? ''));
            $raison   = $raison !== '' ? $raison : null;

            $deja = db_val("SELECT 1 FROM absence_justifiee_arabe WHERE id_eleve=? AND id_mat=? AND id_seq=?", [$eid, $id_mat_p, $id_seq_p]);
            if (!$justifie && $raison === null && !$deja) continue;

            db_exec(
                "INSERT INTO absence_justifiee_arabe (id_eleve, id_mat, id_seq, classe, justifie, raison, id_utilisateur)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE justifie=VALUES(justifie), raison=VALUES(raison), id_utilisateur=VALUES(id_utilisateur)",
                [$eid, $id_mat_p, $id_seq_p, $id_classe_p, $justifie, $raison, $agent]
            );
            $n_saved++;
        }
        flash_set('succes', "$n_saved absence(s) mise(s) à jour.");
    } else {
        flash_set('erreur', 'La séquence active a changé, veuillez réessayer.');
    }
    rediriger('pages/notes_arabe/absence_justifiee.php?classe=' . $id_classe_p . '&mat=' . $id_mat_p);
}

// LEFT JOIN (pas INNER) — une classe sans élève inscrit reste visible dans
// le select (grisée, non sélectionnable) plutôt que silencieusement absente
// (confusion signalée le 21/08/2026).
$classes = db_all(
    "SELECT c.IDClasses, c.DesignationClasses, n.OrdreNiveau, COUNT(i.id_eleve) AS nb_eleves
     FROM classe c LEFT JOIN niveau n ON n.LibelleNiveau = c.Niveau
     LEFT JOIN inscrire i ON i.IDClasses = c.IDClasses AND i.val_annee = ?
     GROUP BY c.IDClasses, c.DesignationClasses, n.OrdreNiveau
     ORDER BY n.OrdreNiveau, c.DesignationClasses",
    [$val_annee]
);

$id_classe_sel = (int) ($_GET['classe'] ?? 0);
$id_mat_sel    = (int) ($_GET['mat'] ?? 0);

$matieres = $id_classe_sel ? matieres_classe_arabe($id_classe_sel) : [];
$ids_mat_ok = array_column($matieres, 'id_mat');
if ($id_mat_sel && !in_array($id_mat_sel, $ids_mat_ok, true)) $id_mat_sel = 0;

$eleves = [];
if ($id_classe_sel && $id_mat_sel && $id_seq) {
    $eleves = db_all(
        "SELECT e.id_eleve, e.Nom_elv, e.Prenom_elv, e.Nom_arabe_elv, e.Mat_elv
         FROM eleve e
         JOIN inscrire i ON i.id_eleve=e.id_eleve
         LEFT JOIN composer_sequence_arabe cs ON cs.id_eleve=e.id_eleve AND cs.id_mat=? AND cs.classe=? AND cs.id_seq=?
         WHERE i.IDClasses=? AND i.val_annee=? AND e.statut='actif' AND cs.id_eleve IS NULL
         ORDER BY e.Nom_elv, e.Prenom_elv",
        [$id_mat_sel, $id_classe_sel, $id_seq, $id_classe_sel, $val_annee]
    );
    $justifs = db_all("SELECT id_eleve, justifie, raison FROM absence_justifiee_arabe WHERE id_mat=? AND id_seq=?", [$id_mat_sel, $id_seq]);
    $justifs_idx = [];
    foreach ($justifs as $j) $justifs_idx[(int) $j['id_eleve']] = $j;
    foreach ($eleves as &$e) {
        $j = $justifs_idx[(int) $e['id_eleve']] ?? null;
        $e['justifie'] = $j && (int) $j['justifie'] === 1;
        $e['raison']   = $j['raison'] ?? '';
    }
    unset($e);
}

$es_partiel = isset($_GET['partiel']);
if (!$es_partiel) {
    $titre_page = 'Notes justifiées';
    require_once __DIR__ . '/../../layout/header.php';
} else {
    header('Content-Type: text/html; charset=utf-8');
}
?>

<div id="notes-justifiees-ar-zone">

<div class="page-titre d-flex align-items-center justify-content-between flex-wrap gap-2">
  <div>
    <h4><i class="bi bi-person-x me-1 text-primary"></i>Notes justifiées <span class="text-muted" style="font-size:.7em">(عربي)</span></h4>
    <div class="sub">Année <?= h($val_annee) ?> — justifie l'absence d'un élève lors de l'évaluation d'une matière</div>
  </div>
  <a href="<?= APP_URL ?>/pages/notes_arabe/index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Saisie des notes</a>
</div>

<?= flash_html() ?>

<div class="alert alert-light border py-2 mb-3" style="font-size:.8rem">
  <i class="bi bi-info-circle me-1"></i>
  Registre administratif : sur la piste arabe, une matière sans note est déjà exclue du calcul de la moyenne (jamais comptée zéro) —
  cette justification garde simplement la raison de l'absence pour le dossier de l'élève.
  Un élève déjà noté ne peut pas être justifié pour cette séquence.
</div>

<div class="card mb-3">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end" data-ajax-nav-form>
      <div class="col-md-4">
        <label class="form-label">Classe</label>
        <select name="classe" class="form-select" data-ajax-nav-auto>
          <option value="">— Choisir —</option>
          <?php foreach ($classes as $c): $vide = (int) $c['nb_eleves'] === 0; ?>
            <option value="<?= $c['IDClasses'] ?>" <?= $id_classe_sel == $c['IDClasses'] ? 'selected' : '' ?> <?= $vide ? 'disabled' : '' ?>><?= h($c['DesignationClasses']) ?><?= $vide ? ' (aucun élève inscrit)' : '' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-5">
        <label class="form-label">Matière</label>
        <select name="mat" class="form-select" data-ajax-nav-auto <?= $id_classe_sel ? '' : 'disabled' ?>>
          <option value="">— Choisir —</option>
          <?php foreach ($matieres as $m): ?>
            <option value="<?= $m['id_mat'] ?>" <?= $id_mat_sel == $m['id_mat'] ? 'selected' : '' ?>><?= h($m['matiere_fr']) ?><?= $m['matiere_ar'] ? ' — ' . h($m['matiere_ar']) : '' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label mb-1 d-block">Séquence</label>
        <?php if ($seq_active): ?>
          <span class="badge" style="background:#dbe4f5;color:#1a3c6b;font-size:.8rem;padding:7px 14px">
            <i class="bi bi-calendar3 me-1"></i><?= h($seq_active['libelle_trim'] . ' — ' . $seq_active['libelle_seq']) ?> (en cours)
          </span>
        <?php else: ?>
          <span class="text-danger" style="font-size:.8rem"><i class="bi bi-exclamation-triangle me-1"></i>Aucune séquence active</span>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<?php if (!$id_classe_sel || !$id_mat_sel || !$id_seq): ?>
  <div class="text-center py-5 text-muted">
    <i class="bi bi-arrow-up-circle" style="font-size:3rem;opacity:.2;display:block;margin-bottom:1rem"></i>
    Sélectionnez une classe et une matière.
  </div>
<?php elseif (empty($eleves)): ?>
  <div class="alert alert-light text-muted py-4 text-center">Tous les élèves de cette classe ont déjà une note pour cette matière/séquence.</div>
<?php else: ?>

<form method="post" data-ajax-post-form>
  <?= csrf_champ() ?>
  <input type="hidden" name="id_classe" value="<?= $id_classe_sel ?>">
  <input type="hidden" name="id_mat" value="<?= $id_mat_sel ?>">
  <input type="hidden" name="id_seq" value="<?= $id_seq ?>">

  <div class="card">
    <div class="card-header py-2 d-flex align-items-center justify-content-between flex-wrap gap-2" style="background:#f8faff">
      <span class="fw-semibold" style="font-size:.82rem"><i class="bi bi-people me-1"></i><?= count($eleves) ?> élève(s) sans note</span>
      <button type="submit" class="btn btn-sm btn-abz-primary"><i class="bi bi-save me-1"></i>Enregistrer</button>
    </div>
    <div class="table-responsive">
      <table class="table table-abz table-hover align-middle mb-0" style="font-size:.82rem">
        <thead>
          <tr>
            <th style="width:30px">N°</th>
            <th>Nom et Prénom</th>
            <th>الاسم بالعربية</th>
            <th style="width:120px">Statut</th>
            <th style="width:90px" class="text-center">Justifiée</th>
            <th>Raison</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($eleves as $i => $e): ?>
          <tr style="<?= $e['justifie'] ? 'background:#f4fff6' : '' ?>">
            <td class="text-muted"><?= $i + 1 ?></td>
            <td class="fw-semibold"><?= h(mb_strtoupper($e['Nom_elv'])) ?> <?= h($e['Prenom_elv'] ?? '') ?></td>
            <td dir="rtl" style="font-family:var(--font-arabe,inherit)"><?= h($e['Nom_arabe_elv'] ?: '—') ?></td>
            <td>
              <?php if ($e['justifie']): ?>
                <span class="badge" style="background:#dcfce7;color:#166534">Justifiée</span>
              <?php else: ?>
                <span class="badge" style="background:#fef3c7;color:#92400e">En attente</span>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <input type="checkbox" class="form-check-input" name="justifie[<?= $e['id_eleve'] ?>]" value="1" <?= $e['justifie'] ? 'checked' : '' ?>>
            </td>
            <td>
              <input type="text" class="form-control form-control-sm" name="raison[<?= $e['id_eleve'] ?>]" maxlength="200"
                     value="<?= h($e['raison']) ?>" placeholder="Motif (maladie, deuil...)">
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</form>

<?php endif; ?>

</div><!-- /#notes-justifiees-ar-zone -->
<?php if ($es_partiel) exit; // rien de plus dans une réponse AJAX partielle. ?>

<?php
$ajax_zone_id = 'notes-justifiees-ar-zone'; // voir layout/footer.php — initAjaxZone() y est appelé
require_once __DIR__ . '/../../layout/footer.php';
