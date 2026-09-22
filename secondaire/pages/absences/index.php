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

if (!$is_admin && !$is_ens) {
    flash_set('erreur', 'Accès non autorisé.');
    rediriger('dashboard.php');
}

$annee_act = get_annee_active();
$id_annee  = (int)($annee_act['id'] ?? 0);
$val_annee = $annee_act['libelle'] ?? '';

// CSRF (jeton de session autonome — à remplacer par l'équivalent de fonctions.php si existant)
if (empty($_SESSION['csrf_absences'])) {
    $_SESSION['csrf_absences'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_absences'];

// ── Classes accessibles ─────────────────────────────────────────────
if ($is_admin) {
    $classes = $id_annee
        ? db_all("SELECT c.* FROM classe c JOIN inscription i ON i.id_classe=c.id AND i.id_annee=? WHERE c.archivee=0 GROUP BY c.id ORDER BY c.ordre, c.designation", [$id_annee])
        : [];
} else {
    $classes = db_all(
        "SELECT DISTINCT c.* FROM dispenser d
         JOIN classe c ON c.id=d.IDClasses
         WHERE d.matricule_ens=? AND d.val_annee=? AND c.archivee=0
         ORDER BY c.ordre, c.designation",
        [$mat_ens, $val_annee]
    );
}

// ── Paramètres GET ──────────────────────────────────────────────────
$id_classe  = (int)($_GET['classe']  ?? 0);
$id_matiere = (int)($_GET['matiere'] ?? 0);
$id_seq     = (int)($_GET['seq']     ?? 0);

// Sécurité : la classe demandée doit être accessible
$ids_classes_ok = array_column($classes, 'id');
if ($id_classe && !in_array($id_classe, $ids_classes_ok)) $id_classe = 0;

// ── Matières de la classe (restreintes aux matières enseignées si ENSEIGNANT) ──
$matieres = [];
if ($id_classe) {
    if ($is_admin) {
        $matieres = db_all(
            "SELECT d.id_mat, m.libelle FROM discipline d
             JOIN matiere m ON m.id=d.id_mat AND m.actif=1
             WHERE d.IDClasses=? ORDER BY d.ordre, m.libelle",
            [$id_classe]
        );
    } else {
        $matieres = db_all(
            "SELECT DISTINCT d.id_mat, m.libelle FROM dispenser disp
             JOIN discipline d ON d.id_mat=disp.id_mat AND d.IDClasses=disp.IDClasses
             JOIN matiere m ON m.id=d.id_mat AND m.actif=1
             WHERE disp.matricule_ens=? AND disp.val_annee=? AND disp.IDClasses=?
             ORDER BY d.ordre, m.libelle",
            [$mat_ens, $val_annee, $id_classe]
        );
    }
    $ids_mat_ok = array_column($matieres, 'id_mat');
    if ($id_matiere && !in_array($id_matiere, $ids_mat_ok)) $id_matiere = 0;
}

// ── Séquence active (verrouillée, pas de choix possible) ────────────
$seq_active = db_one(
    "SELECT s.*, t.libelle AS trim_lib FROM sequence s
     JOIN trimestre t ON t.id=s.id_trim
     WHERE s.active=1 AND t.id_annee=? LIMIT 1",
    [$id_annee]
);
$id_seq = $seq_active ? (int)$seq_active['id'] : 0;

// ── Élèves + notes + statut de justification ────────────────────────
$eleves = [];
$nb_inscrits = 0;
if ($id_classe && $id_matiere && $id_seq) {
    $eleves_raw = db_all(
        "SELECT e.id, e.nom, e.prenom, e.matricule, s.libelle AS serie
         FROM eleve e
         JOIN inscription i ON i.id_eleve=e.id AND i.id_classe=? AND i.id_annee=?
         LEFT JOIN serie s ON s.id=i.id_serie
         WHERE e.statut='actif' ORDER BY e.nom, e.prenom",
        [$id_classe, $id_annee]
    );
    $nb_inscrits = count($eleves_raw);

    $notes = db_all(
        "SELECT id_eleve, valeur FROM note WHERE id_matiere=? AND id_seq=?",
        [$id_matiere, $id_seq]
    );
    $notes_idx = [];
    foreach ($notes as $n) $notes_idx[(int)$n['id_eleve']] = (float)$n['valeur'];
    $nb_notes = count($notes);
    $seuil_absent = $nb_inscrits > 0 ? (int)ceil($nb_inscrits / 2) : 0;

    $justifs = db_all(
        "SELECT id_eleve, justifie, raison FROM absence_justifiee WHERE id_matiere=? AND id_seq=?",
        [$id_matiere, $id_seq]
    );
    $justifs_idx = [];
    foreach ($justifs as $j) $justifs_idx[(int)$j['id_eleve']] = $j;

    foreach ($eleves_raw as $el) {
        $eid = (int)$el['id'];
        if (isset($notes_idx[$eid])) continue; // uniquement les élèves SANS note
        $el['a_note']    = false;
        $el['note']      = null;
        $el['justifie']  = isset($justifs_idx[$eid]) && (int)$justifs_idx[$eid]['justifie'] === 1;
        $el['raison']    = $justifs_idx[$eid]['raison'] ?? '';
        // "à risque" = pas de note ET l'évaluation a été faite pour au moins la moitié de la classe
        $el['a_risque']  = !$el['a_note'] && $nb_notes >= $seuil_absent && $seuil_absent > 0;
        $eleves[] = $el;
    }
}

$titre_page = 'Notes justifiées';
require_once __DIR__ . '/../../../layout/header.php';
?>
<style>
.badge-periode { background:#dbeafe;color:#1e3a8a;font-size:.78rem;padding:5px 12px;border-radius:20px;font-weight:600; }
.tbl-abs th { background:#1a3c6b;color:#fff;font-size:.78rem;padding:8px 10px; }
.tbl-abs td { font-size:.85rem;padding:7px 10px;vertical-align:middle; }
.tbl-abs tr.row-risque td { background:#fff3f3; }
.tbl-abs tr.row-ok td { background:#f4fff6; }
.raison-input { font-size:.8rem; }
</style>

<div class="page-titre d-flex align-items-center justify-content-between flex-wrap gap-2">
  <h4><i class="bi bi-person-x me-2" style="color:#1a3c6b"></i>Notes justifiées</h4>
</div>

<?= flash_html() ?>

<div class="card mb-3" style="border-color:#c7d8f0">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-3">
        <label class="form-label mb-1" style="font-size:.78rem;font-weight:600;color:#1a3c6b">Classe</label>
        <select name="classe" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="">— Choisir —</option>
          <?php foreach ($classes as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $id_classe==$c['id']?'selected':'' ?>><?= h($c['designation']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label mb-1" style="font-size:.78rem;font-weight:600;color:#1a3c6b">Matière</label>
        <select name="matiere" class="form-select form-select-sm" onchange="this.form.submit()" <?= $id_classe?'':'disabled' ?>>
          <option value="">— Choisir —</option>
          <?php foreach ($matieres as $m): ?>
            <option value="<?= $m['id_mat'] ?>" <?= $id_matiere==$m['id_mat']?'selected':'' ?>><?= h($m['libelle']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label mb-1 d-block" style="font-size:.78rem;font-weight:600;color:#1a3c6b">Séquence</label>
        <?php if ($seq_active): ?>
          <span class="badge" style="background:#dbe4f5;color:#1a3c6b;font-size:.8rem;padding:7px 14px">
            <i class="bi bi-calendar3 me-1"></i><?= h($seq_active['trim_lib'] . ' — ' . $seq_active['libelle']) ?> (en cours)
          </span>
        <?php else: ?>
          <span class="text-danger" style="font-size:.8rem"><i class="bi bi-exclamation-triangle me-1"></i>Aucune séquence active</span>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<?php if (!$id_classe || !$id_matiere || !$id_seq): ?>
  <div class="text-center py-5 text-muted">
    <i class="bi bi-arrow-up-circle" style="font-size:3rem;opacity:.2;display:block;margin-bottom:1rem"></i>
    Sélectionnez une classe, une matière et une séquence.
  </div>

<?php elseif (empty($eleves)): ?>
  <div class="alert alert-info"><i class="bi bi-info-circle me-2"></i>Aucun élève actif dans cette classe.</div>

<?php else: ?>

<form method="post" action="save.php">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
  <input type="hidden" name="id_classe" value="<?= $id_classe ?>">
  <input type="hidden" name="id_matiere" value="<?= $id_matiere ?>">
  <input type="hidden" name="id_seq" value="<?= $id_seq ?>">
  <input type="hidden" name="back" value="?classe=<?= $id_classe ?>&matiere=<?= $id_matiere ?>&seq=<?= $id_seq ?>">

  <div class="card" style="border-color:#c7d8f0">
    <div class="card-header py-2 d-flex align-items-center justify-content-between flex-wrap gap-2" style="background:#f0f4ff">
      <span class="fw-semibold" style="color:#1a3c6b">
        <i class="bi bi-people me-1"></i><?= count($eleves) ?> élève(s)
        <span class="badge-periode ms-2"><?= h($matieres[array_search($id_matiere, array_column($matieres,'id_mat'))]['libelle'] ?? '') ?></span>
      </span>
      <button type="submit" class="btn btn-sm btn-abz-primary" style="background:#1a3c6b;color:#fff;border:none">
        <i class="bi bi-save me-1"></i>Enregistrer
      </button>
    </div>
    <div class="table-responsive">
      <table class="table tbl-abs table-hover mb-0">
        <thead>
          <tr>
            <th>N°</th>
            <th>Nom et Prénom</th>
            <th style="width:90px">Série</th>
            <th style="width:90px">Note</th>
            <th style="width:120px">Statut</th>
            <th style="width:90px" class="text-center">Justifiée</th>
            <th>Raison</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($eleves as $i => $el): ?>
          <?php
            $tr_class = $el['a_note'] ? '' : ($el['a_risque'] ? 'row-risque' : '');
            if ($el['justifie']) $tr_class = 'row-ok';
          ?>
          <tr class="<?= $tr_class ?>">
            <td class="text-muted"><?= $i+1 ?></td>
            <td class="fw-semibold"><?= h(strtoupper($el['nom']).' '.($el['prenom']??'')) ?></td>
            <td style="font-size:.78rem"><?= $el['serie'] ? h($el['serie']) : '<span class="text-muted">—</span>' ?></td>
            <td><?= $el['a_note'] ? h(rtrim(rtrim(number_format($el['note'],2,'.',''),'0'),'.')) : '<span class="text-muted">—</span>' ?></td>
            <td>
              <?php if ($el['a_note']): ?>
                <span class="badge bg-secondary">Note saisie</span>
              <?php elseif ($el['justifie']): ?>
                <span class="badge bg-success">Justifiée</span>
              <?php elseif ($el['a_risque']): ?>
                <span class="badge bg-danger">Comptera 0</span>
              <?php else: ?>
                <span class="badge bg-warning text-dark">En attente</span>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <input type="checkbox" class="form-check-input" name="justifie[<?= $el['id'] ?>]" value="1"
                     <?= $el['justifie']?'checked':'' ?> <?= $el['a_note']?'disabled':'' ?>>
            </td>
            <td>
              <input type="text" class="form-control form-control-sm raison-input"
                     name="raison[<?= $el['id'] ?>]" maxlength="200"
                     value="<?= h($el['raison']) ?>" placeholder="Motif (maladie, deuil...)"
                     <?= $el['a_note']?'disabled':'' ?>>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="mt-2 text-muted" style="font-size:.8rem">
    <i class="bi bi-info-circle me-1"></i>
    « Comptera 0 » signifie qu'à défaut de justification, cette absence sera comptée comme note 0 dans le bulletin.
    Un élève déjà noté ne peut pas être justifié pour cette séquence.
  </div>
</form>

<?php endif; ?>

<?php require_once __DIR__ . '/../../../layout/footer.php'; ?>
