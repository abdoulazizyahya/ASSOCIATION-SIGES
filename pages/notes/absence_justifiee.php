<?php
/**
 * pages/notes/absence_justifiee.php — Notes justifiées (piste française,
 * compétences) — reproduction de pages/absences/index.php d'ABZ_MBE
 * (http://localhost/ABZ_MBE/pages/absences/index.php?classe=2&matiere=12),
 * adaptée au modèle compétence+séquence de jaynitaare (composer_sequence)
 * au lieu de matière+note. Demande explicite du 16/08/2026.
 *
 * Permet de justifier l'absence d'un élève lors de l'évaluation d'une
 * compétence (maladie, deuil...) : sans justification, si au moins la
 * moitié de la classe a déjà composé cette compétence, l'élève reçoit un
 * zéro automatique (migration_v37) ; justifiée, la compétence est ignorée
 * pour lui ce trimestre comme si elle n'était pas encore évaluée — voir
 * notes_apc.php::calculer_moyenne_trimestre_eleve()/absence_justifiee_val().
 * N'affecte jamais l'éligibilité au classement (`classable`).
 *
 * Séquence : n'importe quelle évaluation du TRIMESTRE ACTIF (pas seulement
 * la séquence active elle-même), demande explicite du 17/08/2026 — jamais
 * une séquence d'un autre trimestre. Un élève déjà noté ne peut pas être
 * justifié pour la séquence choisie.
 */
header('Cache-Control: no-store, no-cache, must-revalidate');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/../../notes_apc.php';
exiger_acces_pedagogie();
exiger_annee_active(); // Année scolaire réellement active requise (18/08/2026) — module Pédagogie/Discipline.

$annee_act = get_annee_active();
$val_annee = $annee_act['val_annee'] ?? '';

$seq_active_brut = get_sequence_active();
$seq_active = null;
if (!empty($seq_active_brut['id_seq'])) {
    $seq_active = db_one(
        "SELECT s.id_seq, s.libelle_seq, t.id_trim, t.libelle_trim
         FROM sequence s JOIN trimestre t ON t.id_trim = s.id_trim
         WHERE s.id_seq = ?", [$seq_active_brut['id_seq']]
    );
}
$seqs_trim_actif = sequences_trimestre_actif();
$id_seq = (int) ($_GET['seq'] ?? ($seq_active['id_seq'] ?? 0));
if (!in_array($id_seq, array_column($seqs_trim_actif, 'id_seq'), true)) {
    $id_seq = (int) ($seq_active['id_seq'] ?? 0);
}
$id_trim = (int) ($seq_active['id_trim'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $id_classe_p = (int) post('id_classe');
    $id_comp_p   = (int) post('id_comp');
    $id_seq_p    = (int) post('id_seq');
    $agent       = utilisateur_connecte()['id'] ?? null;

    // Défense en profondeur : la séquence postée doit appartenir au
    // trimestre actif (même contrôle que sequences_trimestre_actif()).
    if ($id_classe_p && $id_comp_p && $id_seq_p && in_array($id_seq_p, array_column($seqs_trim_actif, 'id_seq'), true)) {
        $eleves_ids = array_column(
            db_all(
                "SELECT e.id_eleve FROM eleve e JOIN inscrire i ON i.id_eleve=e.id_eleve
                 WHERE i.IDClasses=? AND i.val_annee=? AND e.statut='actif'",
                [$id_classe_p, $val_annee]
            ),
            'id_eleve'
        );
        // Élèves ayant déjà une note : jamais justifiables (sécurité, même
        // règle que la liste affichée).
        $notes_eleves = array_column(
            db_all("SELECT id_eleve FROM composer_sequence WHERE id_comp=? AND IDClasses=? AND id_seq=? AND val_annee=?",
                [$id_comp_p, $id_classe_p, $id_seq_p, $val_annee]),
            'id_eleve'
        );

        // post() ne gère que les champs scalaires (trim((string)$_POST[$key]))
        // — justifie[]/raison[] sont des tableaux indexés par id_eleve, à lire
        // directement sur $_POST (post('raison') caste un tableau en la chaîne
        // "Array", un bug réel trouvé ici même en testant : $raison_post[$eid]
        // devenait alors un accès à un caractère de "Array" plutôt qu'au motif
        // saisi, et isset($justifie_post[$eid]) répondait vrai au hasard selon
        // la valeur de $eid au lieu de refléter la case cochée).
        $justifie_post = is_array($_POST['justifie'] ?? null) ? $_POST['justifie'] : [];
        $raison_post   = is_array($_POST['raison'] ?? null) ? $_POST['raison'] : [];
        $n_saved = 0;
        $eleves_touches = [];
        foreach ($eleves_ids as $eid) {
            $eid = (int) $eid;
            if (in_array($eid, $notes_eleves, true)) continue;

            $justifie = isset($justifie_post[$eid]) ? 1 : 0;
            $raison   = trim((string) ($raison_post[$eid] ?? ''));
            $raison   = $raison !== '' ? $raison : null;

            $deja = db_val("SELECT 1 FROM absence_justifiee WHERE id_eleve=? AND id_comp=? AND id_seq=?", [$eid, $id_comp_p, $id_seq_p]);
            if (!$justifie && $raison === null && !$deja) continue; // rien à faire, rien n'existait

            db_exec(
                "INSERT INTO absence_justifiee (id_eleve, id_comp, id_seq, IDClasses, val_annee, justifie, raison, id_utilisateur)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE justifie=VALUES(justifie), raison=VALUES(raison), id_utilisateur=VALUES(id_utilisateur)",
                [$eid, $id_comp_p, $id_seq_p, $id_classe_p, $val_annee, $justifie, $raison, $agent]
            );
            $n_saved++;
            $eleves_touches[] = $eid;
        }

        // Recalcul immédiat (comme annuler_evaluation_eleve()) — sans attendre
        // la prochaine saisie de note pour que le zéro auto disparaisse déjà.
        // Aucun préchargement n'a pu être fait plus tôt dans cette requête
        // (absence_justifiee_val() retombe alors sur une requête directe,
        // donc toujours à jour) — pas besoin d'invalider de cache ici.
        foreach (array_unique($eleves_touches) as $eid) {
            calculer_moyenne_trimestre_eleve($eid, $id_trim, $id_classe_p, $val_annee);
            calculer_moyenne_annuelle_eleve($eid, $id_classe_p, $val_annee);
        }
        flash_set('succes', "$n_saved absence(s) mise(s) à jour.");
    } else {
        flash_set('erreur', 'Évaluation invalide ou hors du trimestre actif, veuillez réessayer.');
    }
    rediriger('pages/notes/absence_justifiee.php?classe=' . $id_classe_p . '&comp=' . $id_comp_p . '&seq=' . $id_seq_p);
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
$id_comp_sel   = (int) ($_GET['comp'] ?? 0);

$competences = $id_classe_sel ? competences_classe($id_classe_sel, $val_annee) : [];
$ids_comp_ok = array_column($competences, 'id_comp');
if ($id_comp_sel && !in_array($id_comp_sel, $ids_comp_ok, true)) $id_comp_sel = 0;

$eleves = [];
$a_risque_comp = false;
if ($id_classe_sel && $id_comp_sel && $id_seq) {
    $eleves = db_all(
        "SELECT e.id_eleve, e.Nom_elv, e.Prenom_elv, e.Mat_elv
         FROM eleve e
         JOIN inscrire i ON i.id_eleve=e.id_eleve
         LEFT JOIN composer_sequence cs ON cs.id_eleve=e.id_eleve AND cs.id_comp=? AND cs.IDClasses=? AND cs.id_seq=? AND cs.val_annee=?
         WHERE i.IDClasses=? AND i.val_annee=? AND e.statut='actif' AND cs.id_eleve IS NULL
         ORDER BY e.Nom_elv, e.Prenom_elv",
        [$id_comp_sel, $id_classe_sel, $id_seq, $val_annee, $id_classe_sel, $val_annee]
    );
    $participation = taux_participation_competences_trimestre($id_classe_sel, $id_trim, $val_annee)['taux'];
    $a_risque_comp = ($participation[$id_comp_sel] ?? 0.0) >= 0.5;

    $justifs = db_all("SELECT id_eleve, justifie, raison FROM absence_justifiee WHERE id_comp=? AND id_seq=?", [$id_comp_sel, $id_seq]);
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

<div id="notes-justifiees-zone">

<div class="page-titre d-flex align-items-center justify-content-between flex-wrap gap-2">
  <div>
    <h4><i class="bi bi-person-x me-1 text-primary"></i>Notes justifiées</h4>
    <div class="sub">Année <?= h($val_annee) ?> — justifie l'absence d'un élève lors de l'évaluation d'une compétence</div>
  </div>
  <a href="<?= APP_URL ?>/pages/notes/index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Saisie des notes</a>
</div>

<?= flash_html() ?>

<div class="alert alert-light border py-2 mb-3" style="font-size:.8rem">
  <i class="bi bi-info-circle me-1"></i>
  « Comptera 0 » signifie qu'à défaut de justification, cette absence sera comptée comme un zéro dans la moyenne trimestrielle
  (au moins la moitié de la classe a déjà composé cette compétence). Un élève déjà noté ne peut pas être justifié pour cette séquence.
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
        <label class="form-label">Compétence</label>
        <select name="comp" class="form-select" data-ajax-nav-auto <?= $id_classe_sel ? '' : 'disabled' ?>>
          <option value="">— Choisir —</option>
          <?php $section_sel = $id_classe_sel ? section_classe($id_classe_sel) : 'Fr'; foreach ($competences as $c): ?>
            <option value="<?= $c['id_comp'] ?>" <?= $id_comp_sel == $c['id_comp'] ? 'selected' : '' ?>><?= h(libelle_comp_affiche($c, $section_sel)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Évaluation</label>
        <?php if ($seqs_trim_actif): ?>
          <select name="seq" class="form-select" data-ajax-nav-auto>
            <?php foreach ($seqs_trim_actif as $s): ?>
              <option value="<?= $s['id_seq'] ?>" <?= $id_seq == $s['id_seq'] ? 'selected' : '' ?>>
                <?= h($s['libelle_trim'] . ' — ' . $s['libelle_seq']) ?><?= ($seq_active['id_seq'] ?? 0) == $s['id_seq'] ? ' ★' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        <?php else: ?>
          <span class="text-danger" style="font-size:.8rem"><i class="bi bi-exclamation-triangle me-1"></i>Aucune séquence active</span>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<?php if (!$id_classe_sel || !$id_comp_sel || !$id_seq): ?>
  <div class="text-center py-5 text-muted">
    <i class="bi bi-arrow-up-circle" style="font-size:3rem;opacity:.2;display:block;margin-bottom:1rem"></i>
    Sélectionnez une classe et une compétence.
  </div>
<?php elseif (empty($eleves)): ?>
  <div class="alert alert-light text-muted py-4 text-center">Tous les élèves de cette classe ont déjà une note pour cette compétence/séquence.</div>
<?php else: ?>

<form method="post" data-ajax-post-form>
  <?= csrf_champ() ?>
  <input type="hidden" name="id_classe" value="<?= $id_classe_sel ?>">
  <input type="hidden" name="id_comp" value="<?= $id_comp_sel ?>">
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
            <th style="width:120px">Statut</th>
            <th style="width:90px" class="text-center">Justifiée</th>
            <th>Raison</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($eleves as $i => $e): ?>
          <tr style="<?= $e['justifie'] ? 'background:#f4fff6' : ($a_risque_comp ? 'background:#fff3f3' : '') ?>">
            <td class="text-muted"><?= $i + 1 ?></td>
            <td class="fw-semibold"><?= h(mb_strtoupper($e['Nom_elv'])) ?> <?= h($e['Prenom_elv'] ?? '') ?></td>
            <td>
              <?php if ($e['justifie']): ?>
                <span class="badge" style="background:#dcfce7;color:#166534">Justifiée</span>
              <?php elseif ($a_risque_comp): ?>
                <span class="badge" style="background:#fee2e2;color:#991b1b">Comptera 0</span>
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

</div><!-- /#notes-justifiees-zone -->
<?php if ($es_partiel) exit; // rien de plus dans une réponse AJAX partielle. ?>

<?php
$ajax_zone_id = 'notes-justifiees-zone'; // voir layout/footer.php — initAjaxZone() y est appelé
require_once __DIR__ . '/../../layout/footer.php';
