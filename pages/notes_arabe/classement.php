<?php
// ── Classement d'une classe pour un trimestre — piste arabe ─────────
// Miroir de pages/notes/classement.php (piste française).
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/../../notes_apc.php';
require_once __DIR__ . '/../../notes_apc_arabe.php';
exiger_connexion();

$annee     = get_annee_active();
$val_annee = $annee['val_annee'] ?? '';
$seq_actuelle = get_sequence_active();
$trim_actuel  = (int) ($seq_actuelle['id_trim'] ?? 0);

$id_classe = (int) ($_GET['classe'] ?? 0);
$id_trim   = (int) ($_GET['trim'] ?? $trim_actuel);

if (($_GET['recalculer'] ?? '') === '1' && $id_classe && $id_trim) {
    csrf_verifier();
    $n = recalculer_moyennes_trimestre_classe_arabe($id_classe, $id_trim, $val_annee);
    foreach (db_all("SELECT DISTINCT id_eleve FROM inscrire WHERE IDClasses=? AND val_annee=?", [$id_classe, $val_annee]) as $e) {
        calculer_moyenne_annuelle_eleve_arabe((int) $e['id_eleve'], $id_classe, $val_annee);
    }
    flash_set('succes', "$n élève(s) recalculé(s).");
    rediriger("pages/notes_arabe/classement.php?classe=$id_classe&trim=$id_trim");
}

$classes = db_all(
    "SELECT c.IDClasses, c.DesignationClasses, n.OrdreNiveau
     FROM classe c LEFT JOIN niveau n ON n.LibelleNiveau = c.Niveau
     ORDER BY n.OrdreNiveau, c.DesignationClasses"
);
$trimestres = db_all("SELECT id_trim, libelle_trim FROM trimestre WHERE id_annee=? ORDER BY id_trim", [$val_annee]);

$classement = ($id_classe && $id_trim) ? classement_trimestre_classe_arabe($id_classe, $id_trim, $val_annee) : null;

$es_partiel = isset($_GET['partiel']);
if (!$es_partiel) {
    $titre_page = 'Classement (arabe)';
    require_once __DIR__ . '/../../layout/header.php';
} else {
    header('Content-Type: text/html; charset=utf-8');
}
?>

<div id="classement-ar-zone">

<div class="page-titre d-flex align-items-center justify-content-between flex-wrap gap-2">
  <div>
    <h4><i class="bi bi-trophy me-1 text-primary"></i>Classement — <span dir="rtl" lang="ar">العربية</span></h4>
    <div class="sub">Année <?= h($val_annee) ?> — piste arabe (matière + coefficient)</div>
  </div>
  <a href="<?= APP_URL ?>/pages/notes_arabe/index.php?onglet=classe<?= $id_classe ? "&classe_c=$id_classe" : '' ?>" class="btn btn-outline-primary btn-sm">
    <i class="bi bi-pencil-square me-1"></i>Saisie des notes
  </a>
</div>

<div class="card mb-3">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end" data-ajax-nav-form>
      <div class="col-md-4">
        <label class="form-label">Classe</label>
        <select name="classe" class="form-select" data-ajax-nav-auto>
          <option value="">— Choisir —</option>
          <?php foreach ($classes as $c): ?>
            <option value="<?= $c['IDClasses'] ?>" <?= $id_classe == $c['IDClasses'] ? 'selected' : '' ?>>
              <?= h($c['DesignationClasses']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Trimestre</label>
        <select name="trim" class="form-select" data-ajax-nav-auto>
          <?php foreach ($trimestres as $t): ?>
            <option value="<?= $t['id_trim'] ?>" <?= $id_trim == $t['id_trim'] ? 'selected' : '' ?>>
              <?= h($t['libelle_trim']) ?><?= $trim_actuel == $t['id_trim'] ? ' ★' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($id_classe && $id_trim): ?>
      <div class="col-md-4">
        <a data-ajax-nav href="<?= APP_URL ?>/pages/notes_arabe/classement.php?classe=<?= $id_classe ?>&trim=<?= $id_trim ?>&recalculer=1&csrf=<?= csrf_generer() ?>"
           class="btn btn-outline-secondary btn-sm">
          <i class="bi bi-arrow-clockwise me-1"></i>Recalculer les moyennes
        </a>
      </div>
      <?php endif; ?>
    </form>
  </div>
</div>

<?php if ($classement): ?>
  <?php if (empty($classement['lignes'])): ?>
    <div class="alert alert-info py-2">Aucune moyenne calculée pour cette classe/trimestre — saisissez des notes puis recalculez.</div>
  <?php else: ?>

  <div class="row g-2 mb-3">
    <div class="col-6 col-md-2">
      <div class="stat-card"><div class="stat-val"><?= $classement['effectif'] ?></div><div class="stat-lbl">Effectif</div></div>
    </div>
    <div class="col-6 col-md-2">
      <div class="stat-card"><div class="stat-val"><?= $classement['moy_classe'] ?? '—' ?></div><div class="stat-lbl">Moy. classe</div></div>
    </div>
    <div class="col-6 col-md-2">
      <div class="stat-card"><div class="stat-val"><?= $classement['nb_admis'] ?></div><div class="stat-lbl">Admis (≥10)</div></div>
    </div>
    <div class="col-6 col-md-2">
      <div class="stat-card"><div class="stat-val"><?= $classement['taux_reussite'] !== null ? $classement['taux_reussite'] . '%' : '—' ?></div><div class="stat-lbl">Taux réussite</div></div>
    </div>
    <div class="col-6 col-md-2">
      <div class="stat-card"><div class="stat-val"><?= $classement['moy_premier'] ?? '—' ?></div><div class="stat-lbl">1er</div></div>
    </div>
    <div class="col-6 col-md-2">
      <div class="stat-card"><div class="stat-val"><?= $classement['moy_dernier'] ?? '—' ?></div><div class="stat-lbl">Dernier</div></div>
    </div>
  </div>

  <div class="card">
    <div class="table-responsive">
      <table class="table table-abz table-hover mb-0">
        <thead>
          <tr>
            <th style="width:70px">Rang</th>
            <th>Élève</th>
            <th class="text-center" style="width:100px">Moyenne /20</th>
            <th class="text-center" style="width:100px">Appréciation</th>
            <th class="text-center" style="width:90px">Statut</th>
            <th class="text-center" style="width:60px">Bulletin</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($classement['lignes'] as $l):
            $moy = $l['moy'] !== null ? (float) $l['moy'] : null;
            $appr = appreciation_moyenne($moy);
          ?>
            <tr>
              <td class="fw-bold" style="font-size:.82rem"><?= h($l['rang']) ?></td>
              <td style="font-size:.82rem"><?= h(mb_strtoupper($l['Nom_elv'])) ?> <?= h($l['Prenom_elv'] ?? '') ?></td>
              <td class="text-center fw-bold" style="color:<?= $moy !== null && $moy >= 10 ? '#065f46' : '#991b1b' ?>">
                <?= $moy !== null ? number_format($moy, 2) : '—' ?>
              </td>
              <td class="text-center">
                <?php if ($appr): ?><span class="badge-code"><?= h($appr) ?> — <?= h(libelle_appreciation($appr)) ?></span><?php endif; ?>
              </td>
              <td class="text-center">
                <?php if ($l['classement'] === 'C'): ?>
                  <span style="background:#d1fae5;color:#065f46;font-size:.7rem;padding:2px 8px;border-radius:10px">Classé</span>
                <?php else: ?>
                  <span style="background:#f3f4f6;color:#6b7280;font-size:.7rem;padding:2px 8px;border-radius:10px">N.C</span>
                <?php endif; ?>
              </td>
              <td class="text-center">
                <button type="button" class="btn btn-sm" style="background:#eef2ff;color:#1e4fd8;padding:3px 7px"
                        title="Bulletin PDF"
                        onclick="afficherApercu('<?= APP_URL ?>/pdf/bulletin_trimestriel_arabe.php?id=<?= (int) $l['id_eleve'] ?>&trim=<?= $id_trim ?>', 'Bulletin trimestriel', 'bulletin_trimestriel_arabe', 'portrait')">
                  <i class="bi bi-file-earmark-pdf" style="font-size:.78rem"></i>
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
<?php else: ?>
  <div class="alert alert-light text-muted py-4 text-center">
    <i class="bi bi-arrow-up" style="font-size:2rem;display:block;opacity:.2;margin-bottom:.4rem"></i>
    Sélectionnez une classe et un trimestre.
  </div>
<?php endif; ?>

</div><!-- /#classement-ar-zone -->
<?php if ($es_partiel) exit; // rien de plus dans une réponse AJAX partielle. ?>

<?php
$ajax_zone_id = 'classement-ar-zone'; // voir layout/footer.php — initAjaxZone() y est appelé
require_once __DIR__ . '/../../layout/footer.php';
