<?php
/**
 * pages/notes_arabe/index.php — Saisie des notes (piste arabe)
 * Miroir strict de pages/notes/index.php (piste française) — même nombre
 * d'onglets (4), même forme/gabarit visuel, même navigation AJAX partielle —
 * seules les DONNÉES changent : ici le modèle réel de la piste arabe
 * (matière + coefficient, UNE seule note /20 par matière/séquence, voir
 * notes_apc_arabe.php) au lieu du modèle à compétences/sous-notes O/É/P/SE
 * de la piste française. Là où pages/notes/index.php a 4 champs + un total
 * par élève, cette page a UN seul champ Note/20 par élève — la piste arabe
 * n'a jamais eu de sous-notes dans le schéma jaynitaare réel.
 *
 * Écart assumé, même politique que la piste française (voir en-tête de
 * pages/notes/index.php) : `dispenser` non peuplée → pas de restriction par
 * enseignant, les 3 rôles voient toutes les classes.
 *
 * Noms d'élèves : colonne « Nom arabe » séparée (eleve.Nom_arabe_elv, v27,
 * pas toujours renseigné ~224/271 élèves) dans les tableaux (grille onglet 1,
 * aperçu onglet 3) — même convention sur les 2 pages de saisie. Le sélecteur
 * <select> élève (onglet 2) et l'en-tête « fiche élève » gardent le format
 * compact « NOM (اسم) » via nom_eleve_aff() (fonctions.php), une colonne
 * n'ayant pas de sens hors tableau.
 *
 * Séquence de saisie : toujours l'évaluation ACTIVE (get_sequence_active()),
 * jamais choisie par l'utilisateur — seule « Copie de notes » (onglet 3)
 * garde des séquences source/destination explicites, par nature.
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
$onglet    = in_array($_GET['onglet'] ?? '', ['classe', 'eleve', 'copie', 'non_saisis'], true) ? $_GET['onglet'] : 'classe';

// ── Séquence active ──────────────────────────────────────────────
$seq_active_brut = get_sequence_active();
$seq_active = null;
if (!empty($seq_active_brut['id_seq'])) {
    $seq_active = db_one(
        "SELECT s.id_seq, s.libelle_seq, t.libelle_trim, t.id_annee
         FROM sequence s JOIN trimestre t ON t.id_trim = s.id_trim
         WHERE s.id_seq = ?", [$seq_active_brut['id_seq']]
    );
}

// ── Classes disponibles (les 3 rôles voient tout, voir en-tête) ──
// LEFT JOIN (pas INNER) — une classe sans élève inscrit reste visible dans
// les selects ci-dessous (grisée, non sélectionnable) plutôt que
// silencieusement absente (confusion signalée le 21/08/2026).
$classes = db_all(
    "SELECT c.IDClasses, c.DesignationClasses, n.OrdreNiveau, COUNT(i.id_eleve) AS nb_eleves
     FROM classe c LEFT JOIN niveau n ON n.LibelleNiveau = c.Niveau
     LEFT JOIN inscrire i ON i.IDClasses = c.IDClasses AND i.val_annee = ?
     GROUP BY c.IDClasses, c.DesignationClasses, n.OrdreNiveau
     ORDER BY n.OrdreNiveau, c.DesignationClasses",
    [$val_annee]
);

$seqs = db_all(
    "SELECT s.id_seq, s.libelle_seq, t.libelle_trim
     FROM sequence s JOIN trimestre t ON t.id_trim = s.id_trim
     WHERE t.id_annee = ? ORDER BY s.id_seq", [$val_annee]
);
$seqs_copie = $seqs;

// Mention arabe (ضعيف/متوسط/مقبول/جيد/جيد جدا/ممتاز, appreciation_moyenne_arabe()
// dans notes_apc.php) → couleur, même principe que jn_cote_color() côté FR.
function jn_mention_ar_color(string $m): string {
    return match ($m) {
        'ممتاز'    => '#15803d',
        'جيد جدا'  => '#0891b2',
        'جيد'      => '#1d4ed8',
        'مقبول'    => '#ca8a04',
        'متوسط'    => '#d97706',
        'ضعيف'     => '#dc2626',
        default    => '#6b7280',
    };
}

// Cote /20 — échelle A+/A/B+/B/C+/C/D d'ABZ_MBE reprise à l'identique
// (COTE_MAP, pages/notes/index.php d'ABZ_MBE), en complément de la mention
// arabe : une lettre courte à la ABZ_MBE plutôt que le mot arabe complet,
// utile pour un survol rapide de la grille.
function cote_abz20(?float $note): array {
    if ($note === null) return ['—', '#6b7280'];
    if ($note >= 18) return ['A+', '#15803d'];
    if ($note >= 16) return ['A', '#15803d'];
    if ($note >= 15) return ['B+', '#1d4ed8'];
    if ($note >= 14) return ['B', '#1d4ed8'];
    if ($note >= 12) return ['C+', '#ca8a04'];
    if ($note >= 10) return ['C', '#d97706'];
    return ['D', '#dc2626'];
}

// ══════════════════════════════════════════════════════════════
//  ONGLET 1 — Saisie par classe (une matière, toute la classe)
// ══════════════════════════════════════════════════════════════
$id_cl_c  = (int) ($_GET['classe_c'] ?? 0);
$id_mat_c = (int) ($_GET['mat_c'] ?? 0);
// La saisie de notes porte toujours sur l'évaluation active — pas de
// sélecteur pour en choisir une autre (même règle que pages/notes/index.php).
$id_seq_c = (int) ($seq_active['id_seq'] ?? 0);
$mats_c   = [];
$eleves_c = [];

if ($onglet === 'classe') {
    if ($id_cl_c) {
        $mats_c = matieres_classe_arabe($id_cl_c);
    }
    if ($id_cl_c && $id_mat_c && $id_seq_c) {
        $eleves_c = db_all(
            "SELECT e.id_eleve, e.Nom_elv, e.Prenom_elv, e.Nom_arabe_elv, e.Mat_elv, cs.note
             FROM eleve e
             JOIN inscrire i ON i.id_eleve = e.id_eleve
             LEFT JOIN composer_sequence_arabe cs ON cs.id_eleve = e.id_eleve AND cs.id_mat = ? AND cs.classe = ? AND cs.id_seq = ?
             WHERE i.IDClasses = ? AND i.val_annee = ? AND e.statut = 'actif'
             ORDER BY e.Nom_elv, e.Prenom_elv",
            [$id_mat_c, $id_cl_c, $id_seq_c, $id_cl_c, $val_annee]
        );
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'save_classe') {
        csrf_verifier();
        $p_classe = (int) post('id_cl');
        $p_seq    = (int) post('id_seq');
        $p_mat    = (int) post('id_mat');

        $coef = db_val("SELECT coef FROM classe_matiere_arabe WHERE code_classe=? AND id_mat=?", [$p_classe, $p_mat]);
        $id_trim_p = (int) db_val("SELECT id_trim FROM sequence WHERE id_seq=?", [$p_seq]);
        if ($coef === null || !$id_trim_p) {
            flash_set('erreur', 'Matière ou séquence introuvable.');
            rediriger("pages/notes_arabe/index.php?onglet=classe&classe_c=$p_classe&mat_c=$p_mat");
        }

        $touches = 0;
        foreach ($_POST['notes'] ?? [] as $id_eleve => $val) {
            $id_eleve = (int) $id_eleve;
            $note = $val === '' ? null : max(0.0, min(20.0, (float) str_replace(',', '.', $val)));
            if ($note === null) {
                db_exec("DELETE FROM composer_sequence_arabe WHERE id_eleve=? AND id_mat=? AND classe=? AND id_seq=?",
                    [$id_eleve, $p_mat, $p_classe, $p_seq]);
                continue;
            }
            db_exec(
                "INSERT INTO composer_sequence_arabe (id_eleve, id_mat, classe, id_seq, note)
                 VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE note=VALUES(note)",
                [$id_eleve, $p_mat, $p_classe, $p_seq, $note]
            );
            $touches++;
        }
        recalculer_moyennes_trimestre_classe_arabe($p_classe, $id_trim_p, $val_annee);
        foreach (db_all("SELECT DISTINCT id_eleve FROM inscrire WHERE IDClasses=? AND val_annee=?", [$p_classe, $val_annee]) as $e) {
            calculer_moyenne_annuelle_eleve_arabe((int) $e['id_eleve'], $p_classe, $val_annee);
        }
        flash_set('succes', "$touches note(s) enregistrée(s).");
        rediriger("pages/notes_arabe/index.php?onglet=classe&classe_c=$p_classe&mat_c=$p_mat");
    }
}

// ══════════════════════════════════════════════════════════════
//  ONGLET 2 — Saisie par élève (toutes les matières, un élève)
// ══════════════════════════════════════════════════════════════
$id_cl_e  = (int) ($_GET['classe_e'] ?? 0);
$id_eleve = (int) ($_GET['eleve_e'] ?? 0);
$id_seq_e = (int) ($seq_active['id_seq'] ?? 0); // idem — toujours l'évaluation active
$eleves_e = [];
$mats_e   = [];

if ($onglet === 'eleve') {
    if ($id_cl_e) {
        $eleves_e = db_all(
            "SELECT e.id_eleve, e.Nom_elv, e.Prenom_elv, e.Nom_arabe_elv, e.Mat_elv FROM eleve e
             JOIN inscrire i ON i.id_eleve = e.id_eleve AND i.IDClasses = ? AND i.val_annee = ?
             WHERE e.statut = 'actif' ORDER BY e.Nom_elv, e.Prenom_elv",
            [$id_cl_e, $val_annee]
        );
    }
    if ($id_cl_e && $id_eleve && $id_seq_e) {
        $mats_brutes = matieres_classe_arabe($id_cl_e);
        $notes_idx = [];
        foreach (db_all(
            "SELECT id_mat, note FROM composer_sequence_arabe WHERE id_eleve=? AND classe=? AND id_seq=?",
            [$id_eleve, $id_cl_e, $id_seq_e]
        ) as $n) { $notes_idx[(int) $n['id_mat']] = $n['note']; }
        foreach ($mats_brutes as $m) {
            $m['note'] = $notes_idx[(int) $m['id_mat']] ?? null;
            $mats_e[] = $m;
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'save_eleve') {
        csrf_verifier();
        $p_seq    = (int) post('id_seq');
        $p_classe = (int) post('id_cl');
        $p_eleve  = (int) post('id_eleve');
        $id_trim_p = (int) db_val("SELECT id_trim FROM sequence WHERE id_seq=?", [$p_seq]);

        $touches = 0;
        foreach ($_POST['notes'] ?? [] as $id_mat => $val) {
            $id_mat = (int) $id_mat;
            $note = $val === '' ? null : max(0.0, min(20.0, (float) str_replace(',', '.', $val)));
            if ($note === null) {
                db_exec("DELETE FROM composer_sequence_arabe WHERE id_eleve=? AND id_mat=? AND classe=? AND id_seq=?",
                    [$p_eleve, $id_mat, $p_classe, $p_seq]);
                continue;
            }
            db_exec(
                "INSERT INTO composer_sequence_arabe (id_eleve, id_mat, classe, id_seq, note)
                 VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE note=VALUES(note)",
                [$p_eleve, $id_mat, $p_classe, $p_seq, $note]
            );
            $touches++;
        }
        if ($id_trim_p) {
            recalculer_moyennes_trimestre_classe_arabe($p_classe, $id_trim_p, $val_annee);
            calculer_moyenne_annuelle_eleve_arabe($p_eleve, $p_classe, $val_annee);
        }
        flash_set('succes', "Notes de l'élève enregistrées ($touches matière(s)).");
        rediriger("pages/notes_arabe/index.php?onglet=eleve&classe_e=$p_classe&eleve_e=$p_eleve");
    }
}

// ══════════════════════════════════════════════════════════════
//  ONGLET 3 — Copie de notes (d'une séquence vers une autre)
// ══════════════════════════════════════════════════════════════
$id_cl_cop  = (int) ($_GET['classe_cop'] ?? 0);
$id_seq_src = (int) ($_GET['seq_src'] ?? 0);
$id_seq_dst = (int) ($_GET['seq_dst'] ?? 0);
$id_mat_cop = (int) ($_GET['mat_cop'] ?? 0);
$ajust      = (float) str_replace(',', '.', $_GET['ajust'] ?? '0');
$preview    = [];
$mats_cop   = [];

if ($onglet === 'copie') {
    if ($id_cl_cop) {
        $mats_cop = matieres_classe_arabe($id_cl_cop);
    }
    if ($id_cl_cop && $id_seq_src && $id_mat_cop) {
        $preview = db_all(
            "SELECT e.id_eleve, e.Nom_elv, e.Prenom_elv, e.Nom_arabe_elv, e.Mat_elv,
                    cs.note AS note_src,
                    LEAST(20, GREATEST(0, IF(cs.note IS NOT NULL, cs.note + ?, NULL))) AS note_dst
             FROM eleve e
             JOIN inscrire i ON i.id_eleve = e.id_eleve AND i.IDClasses = ? AND i.val_annee = ?
             LEFT JOIN composer_sequence_arabe cs ON cs.id_eleve = e.id_eleve AND cs.id_mat = ? AND cs.classe = ? AND cs.id_seq = ?
             WHERE e.statut = 'actif'
             ORDER BY e.Nom_elv, e.Prenom_elv",
            [$ajust, $id_cl_cop, $val_annee, $id_mat_cop, $id_cl_cop, $id_seq_src]
        );
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'exec_copie') {
        csrf_verifier();
        $p_classe = (int) post('id_cl');
        $p_src    = (int) post('id_seq_src');
        $p_dst    = (int) post('id_seq_dst');
        $p_mat    = (int) post('id_mat');
        $p_ajust  = (float) str_replace(',', '.', post('ajust') ?? '0');
        $id_trim_dst = (int) db_val("SELECT id_trim FROM sequence WHERE id_seq=?", [$p_dst]);

        $rows = db_all(
            "SELECT cs.id_eleve, cs.note
             FROM eleve e
             JOIN inscrire i ON i.id_eleve = e.id_eleve AND i.IDClasses = ? AND i.val_annee = ?
             JOIN composer_sequence_arabe cs ON cs.id_eleve = e.id_eleve AND cs.id_mat = ? AND cs.classe = ? AND cs.id_seq = ?
             WHERE e.statut = 'actif'",
            [$p_classe, $val_annee, $p_mat, $p_classe, $p_src]
        );
        $nb = 0;
        foreach ($rows as $r) {
            $ancienne = (float) $r['note'];
            $nouvelle = min(20.0, max(0.0, round($ancienne + $p_ajust, 2)));
            db_exec(
                "INSERT INTO composer_sequence_arabe (id_eleve, id_mat, classe, id_seq, note)
                 VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE note=VALUES(note)",
                [$r['id_eleve'], $p_mat, $p_classe, $p_dst, $nouvelle]
            );
            $nb++;
        }
        if ($id_trim_dst) {
            recalculer_moyennes_trimestre_classe_arabe($p_classe, $id_trim_dst, $val_annee);
            foreach (db_all("SELECT DISTINCT id_eleve FROM inscrire WHERE IDClasses=? AND val_annee=?", [$p_classe, $val_annee]) as $e) {
                calculer_moyenne_annuelle_eleve_arabe((int) $e['id_eleve'], $p_classe, $val_annee);
            }
        }
        flash_set('succes', "$nb note(s) copiée(s).");
        rediriger("pages/notes_arabe/index.php?onglet=copie&classe_cop=$p_classe&seq_src=$p_src&seq_dst=$p_dst&mat_cop=$p_mat");
    }
}

// ══════════════════════════════════════════════════════════════
//  ONGLET 4 — Matières non saisies (séquence active)
// ══════════════════════════════════════════════════════════════
$id_cl_ns   = (int) ($_GET['classe_ns'] ?? 0);
$non_saisis = [];

if ($onglet === 'non_saisis' && $seq_active) {
    $id_seq_ns  = (int) $seq_active['id_seq'];
    $where_cl_a = $id_cl_ns ? "AND cma.code_classe = " . (int) $id_cl_ns : '';
    $non_saisis = db_all(
        "SELECT cma.code_classe AS id_classe, c.DesignationClasses AS classe,
                m.matiere_fr, m.matiere_ar,
                (SELECT COUNT(*) FROM inscrire ii WHERE ii.IDClasses=cma.code_classe AND ii.val_annee=? AND EXISTS(SELECT 1 FROM eleve ee WHERE ee.id_eleve=ii.id_eleve AND ee.statut='actif')) AS nb_eleves,
                (SELECT COUNT(*) FROM composer_sequence_arabe nn WHERE nn.id_mat=cma.id_mat AND nn.classe=cma.code_classe AND nn.id_seq=?) AS nb_saisis
         FROM classe_matiere_arabe cma
         JOIN classe c ON c.IDClasses=cma.code_classe
         JOIN matiere_arabe m ON m.id_mat=cma.id_mat
         WHERE 1=1 $where_cl_a
         HAVING nb_eleves > 0 AND nb_saisis = 0
         ORDER BY c.DesignationClasses, cma.ordre",
        [$val_annee, $id_seq_ns]
    );
}

// Mode "partiel" (AJAX), même convention que pages/notes/index.php.
$es_partiel = isset($_GET['partiel']);

$titre_page = 'Notes (arabe)';
if (!$es_partiel) {
    require_once __DIR__ . '/../../layout/header.php';
} else {
    header('Content-Type: text/html; charset=utf-8');
}
?>

<div id="notesar-zone">

<div class="page-titre d-flex align-items-center justify-content-between flex-wrap gap-2">
  <h4><i class="bi bi-pencil-square me-1 text-primary"></i>Gestion des notes — <span dir="rtl" lang="ar">العربية</span></h4>
  <div class="d-flex align-items-center gap-2 flex-wrap">
    <?php if ($seq_active): ?>
      <span class="badge" style="background:#dbeafe;color:#1e40af;font-size:.75rem;padding:5px 10px;border-radius:8px">
        <i class="bi bi-lightning-charge-fill me-1 text-warning"></i>
        <?= h($seq_active['libelle_trim'] . ' — ' . $seq_active['libelle_seq']) ?>
        &nbsp;|&nbsp; <?= h($val_annee) ?>
      </span>
    <?php else: ?>
      <span class="badge bg-danger" style="font-size:.75rem;padding:5px 10px">
        <i class="bi bi-lock-fill me-1"></i>Aucune évaluation active
      </span>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/pages/notes_arabe/classement.php<?= $id_cl_c ? "?classe=$id_cl_c" : '' ?>" class="btn btn-outline-primary btn-sm">
      <i class="bi bi-trophy me-1"></i>Classement
    </a>
  </div>
</div>

<?= flash_html() ?>

<!-- Onglets -->
<ul class="nav nav-tabs mb-3" style="border-bottom:2px solid #e5e7eb">
  <li class="nav-item">
    <a class="nav-link <?= $onglet === 'classe' ? 'active' : '' ?>" data-ajax-nav
       href="<?= APP_URL ?>/pages/notes_arabe/index.php?onglet=classe<?= $id_cl_c ? "&classe_c=$id_cl_c" : '' ?>">
      <i class="bi bi-people me-1"></i>Saisie par classe
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $onglet === 'eleve' ? 'active' : '' ?>" data-ajax-nav
       href="<?= APP_URL ?>/pages/notes_arabe/index.php?onglet=eleve<?= $id_cl_e ? "&classe_e=$id_cl_e" : '' ?><?= $id_eleve ? "&eleve_e=$id_eleve" : '' ?>">
      <i class="bi bi-person me-1"></i>Saisie par élève
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $onglet === 'copie' ? 'active' : '' ?>" data-ajax-nav
       href="<?= APP_URL ?>/pages/notes_arabe/index.php?onglet=copie<?= $id_cl_cop ? "&classe_cop=$id_cl_cop" : '' ?>">
      <i class="bi bi-copy me-1"></i>Copie de notes
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $onglet === 'non_saisis' ? 'active' : '' ?>" data-ajax-nav
       href="<?= APP_URL ?>/pages/notes_arabe/index.php?onglet=non_saisis">
      <i class="bi bi-exclamation-triangle me-1"></i>Non saisies
    </a>
  </li>
</ul>


<?php if ($onglet === 'classe'): ?>
<!-- ══════════════════════════════════════════════════
     ONGLET 1 — Saisie par classe
══════════════════════════════════════════════════ -->

<?php if (!$seq_active): ?>
<div class="alert alert-warning d-flex align-items-center gap-2">
  <i class="bi bi-exclamation-triangle-fill"></i>
  Aucune évaluation active — la saisie des notes est désactivée tant qu'une séquence n'a pas été activée.
</div>
<?php endif; ?>

<div class="card mb-3">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end" data-ajax-nav-form>
      <input type="hidden" name="onglet" value="classe">
      <div class="col-md-4">
        <label class="form-label fw-semibold">Classe</label>
        <select name="classe_c" class="form-select" data-ajax-nav-auto>
          <option value="">— Choisir —</option>
          <?php foreach ($classes as $c): $vide = (int) $c['nb_eleves'] === 0; ?>
            <option value="<?= $c['IDClasses'] ?>" <?= $id_cl_c == $c['IDClasses'] ? 'selected' : '' ?> <?= $vide ? 'disabled' : '' ?>>
              <?= h($c['DesignationClasses']) ?><?= $vide ? ' (aucun élève inscrit)' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($id_cl_c && $mats_c): ?>
      <div class="col-md-6">
        <label class="form-label fw-semibold">Matière</label>
        <select name="mat_c" class="form-select" data-ajax-nav-auto>
          <option value="">— Choisir —</option>
          <?php $groupe_courant = null; foreach ($mats_c as $m):
            if ($m['id_groupe'] !== $groupe_courant) {
                if ($groupe_courant !== null) echo '</optgroup>';
                $groupe_courant = $m['id_groupe'];
                echo '<optgroup label="' . h(mb_strtoupper($m['nom_groupe_fr'])) . '">';
            } ?>
            <option value="<?= $m['id_mat'] ?>" <?= $id_mat_c == $m['id_mat'] ? 'selected' : '' ?>>
              <?= h($m['matiere_fr']) ?> / <?= h($m['matiere_ar']) ?>
            </option>
          <?php endforeach; if ($groupe_courant !== null) echo '</optgroup>'; ?>
        </select>
      </div>
      <?php endif; ?>
    </form>
  </div>
</div>

<?php if ($id_cl_c && $id_mat_c && !empty($eleves_c)):
  $mat_info = null;
  foreach ($mats_c as $m) { if ($m['id_mat'] == $id_mat_c) { $mat_info = $m; break; } }
  $saisi = count(array_filter($eleves_c, fn($e) => $e['note'] !== null));
?>
<div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
  <span class="fw-bold" style="font-size:.88rem"><?= h($mat_info['matiere_fr']) ?></span>
  <span class="badge-code" dir="rtl" lang="ar"><?= h($mat_info['matiere_ar']) ?></span>
  <span style="background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:10px;font-size:.72rem;font-weight:600">
    Coefficient <?= (int) $mat_info['coef'] ?> · Note /20
  </span>
  <span style="background:#f3f4f6;color:#6b7280;padding:2px 8px;border-radius:10px;font-size:.72rem">
    <?= $saisi ?>/<?= count($eleves_c) ?> saisi(s)
  </span>
</div>

<div class="card">
  <div class="card-body p-0">
    <form method="post">
      <?= csrf_champ() ?>
      <input type="hidden" name="action" value="save_classe">
      <input type="hidden" name="id_cl" value="<?= $id_cl_c ?>">
      <input type="hidden" name="id_seq" value="<?= $id_seq_c ?>">
      <input type="hidden" name="id_mat" value="<?= $id_mat_c ?>">
      <div class="table-responsive">
        <table class="table table-abz table-hover mb-0" id="tbl-notes">
          <thead>
            <tr>
              <th style="width:34px">N°</th>
              <th style="width:160px">Nom et Prénom</th>
              <th style="width:150px">Nom arabe</th>
              <th style="width:120px" class="text-center">Note /20</th>
              <th style="width:55px" class="text-center">Cote</th>
              <th style="width:70px" class="text-center">Mention</th>
              <th style="width:50px" class="text-center">
                <button type="button" class="btn btn-link btn-sm p-0" onclick="viderToutClasseAr()" title="Vider tout">
                  <i class="bi bi-eraser" style="font-size:.8rem"></i>
                </button>
              </th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($eleves_c as $i => $el):
              $mention = $el['note'] !== null ? appreciation_moyenne_arabe((float) $el['note']) : '';
              $cote_abz = cote_abz20($el['note'] !== null ? (float) $el['note'] : null);
            ?>
              <tr>
                <td class="text-muted" style="font-size:.72rem"><?= $i + 1 ?></td>
                <td class="fw-semibold" style="font-size:.82rem"><?= h(mb_strtoupper($el['Nom_elv'])) . ' ' . h($el['Prenom_elv'] ?? '') ?></td>
                <td class="text-muted" dir="rtl" lang="ar" style="font-size:.82rem"><?= h($el['Nom_arabe_elv'] ?? '') ?: '—' ?></td>
                <td>
                  <input type="number" class="form-control form-control-sm note-inp-c text-center"
                         name="notes[<?= $el['id_eleve'] ?>]"
                         min="0" max="20" step="0.25"
                         value="<?= $el['note'] !== null ? (float) $el['note'] : '' ?>"
                         data-eleve="<?= $el['id_eleve'] ?>" placeholder="—">
                </td>
                <td class="text-center cote-cl" id="cote-<?= $el['id_eleve'] ?>"
                    style="font-size:.8rem;font-weight:700;color:<?= $cote_abz[1] ?>">
                  <?= h($cote_abz[0]) ?>
                </td>
                <td class="text-center mention-cl" id="mention-<?= $el['id_eleve'] ?>" dir="rtl" lang="ar"
                    style="font-size:.78rem;font-weight:700;color:<?= jn_mention_ar_color($mention) ?>">
                  <?= h($mention) ?: '—' ?>
                </td>
                <td></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="card-body py-2 border-top d-flex align-items-center gap-2">
        <button class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Enregistrer</button>
        <span class="ms-auto text-muted" style="font-size:.75rem">Entrée / Tab pour naviguer — laisser le champ vide retire la note</span>
      </div>
    </form>
  </div>
</div>

<script>
(function() {
  var LIBS = { 'ضعيف': [0, 10], 'متوسط': [10, 12], 'مقبول': [12, 14], 'جيد': [14, 16], 'جيد جدا': [16, 19], 'ممتاز': [19, 20.01] };
  var COLORS = { 'ضعيف': '#dc2626', 'متوسط': '#d97706', 'مقبول': '#ca8a04', 'جيد': '#1d4ed8', 'جيد جدا': '#0891b2', 'ممتاز': '#15803d' };
  function mention(v) {
    for (var k in LIBS) { if (v >= LIBS[k][0] && v < LIBS[k][1]) return k; }
    return '—';
  }
  // Cote /20 — même échelle ABZ_MBE (A+/A/B+/B/C+/C/D) que cote_abz20() côté PHP.
  var COTE_MAP = [
    [18, 'A+', '#15803d'], [16, 'A', '#15803d'], [15, 'B+', '#1d4ed8'], [14, 'B', '#1d4ed8'],
    [12, 'C+', '#ca8a04'], [10, 'C', '#d97706'], [0, 'D', '#dc2626']
  ];
  function coteAbz(v) {
    for (var i = 0; i < COTE_MAP.length; i++) { if (v >= COTE_MAP[i][0]) return COTE_MAP[i]; }
    return COTE_MAP[COTE_MAP.length - 1];
  }
  // Empêche de dépasser le barème (20/20, ou le max défini sur le champ)
  // dès la saisie — le serveur clampe aussi en dernier recours, mais la
  // valeur affichée doit refléter tout de suite le maximum autorisé.
  function clamperBareme(inp) {
    if (inp.value === '') return;
    var v = parseFloat(inp.value.replace(',', '.'));
    if (isNaN(v)) return;
    // Ne réécrit le champ QUE si la valeur dépasse réellement le barème —
    // reformater à chaque frappe (ex. arrondi systématique) écrasait une
    // virgule décimale en cours de saisie ("3," redevenait "3" avant même
    // d'avoir pu taper "45"), rendant impossible la saisie d'un nombre
    // comme 3,45. Le filtrage des caractères autorisés (chiffres + une
    // seule virgule/point) est fait globalement par restreindreSaisieNumerique()
    // (layout/footer.php) ; ce garde-fou-ci ne gère que le dépassement du max/min.
    var max = parseFloat(inp.max), min = parseFloat(inp.min || '0');
    var clamped = v;
    if (clamped > max) clamped = max; else if (clamped < min) clamped = min;
    if (clamped !== v) inp.value = clamped;
  }
  document.querySelectorAll('.note-inp-c').forEach(function(inp) {
    inp.addEventListener('input', function() {
      clamperBareme(this);
      var idEleve = this.dataset.eleve;
      var mentionCell = document.getElementById('mention-' + idEleve);
      var coteCell = document.getElementById('cote-' + idEleve);
      if (this.value === '') {
        mentionCell.textContent = '—'; mentionCell.style.color = '#6b7280';
        coteCell.textContent = '—'; coteCell.style.color = '#6b7280';
        return;
      }
      var v = parseFloat(this.value);
      var m = mention(v);
      mentionCell.textContent = m; mentionCell.style.color = COLORS[m] || '#6b7280';
      var c = coteAbz(v);
      coteCell.textContent = c[1]; coteCell.style.color = c[2];
    });
  });
  var inps = Array.from(document.querySelectorAll('.note-inp-c'));
  inps.forEach(function(inp, idx) {
    inp.addEventListener('keydown', function(e) {
      if (e.key === 'Enter') { e.preventDefault(); if (inps[idx + 1]) inps[idx + 1].focus(); }
    });
  });
  window.viderToutClasseAr = function() {
    inps.forEach(function(i) { i.value = ''; i.dispatchEvent(new Event('input')); });
  };
})();
</script>

<?php elseif ($id_cl_c && $id_mat_c): ?>
  <div class="alert alert-info py-2"><i class="bi bi-info-circle me-1"></i>Aucun élève actif dans cette classe.</div>
<?php elseif ($id_cl_c && empty($mats_c)): ?>
  <div class="alert alert-warning py-2"><i class="bi bi-exclamation me-1"></i>Aucune matière arabe configurée pour cette classe.</div>
<?php elseif ($id_cl_c): ?>
  <div class="alert alert-light text-muted py-3 text-center">Sélectionnez une matière.</div>
<?php else: ?>
  <div class="alert alert-light text-muted py-4 text-center">
    <i class="bi bi-arrow-up" style="font-size:2rem;display:block;opacity:.2;margin-bottom:.4rem"></i>
    Sélectionnez une classe pour commencer.
  </div>
<?php endif; ?>


<?php elseif ($onglet === 'eleve'): ?>
<!-- ══════════════════════════════════════════════════
     ONGLET 2 — Saisie par élève
══════════════════════════════════════════════════ -->

<div class="card mb-3">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end" data-ajax-nav-form>
      <input type="hidden" name="onglet" value="eleve">
      <div class="col-md-3">
        <label class="form-label fw-semibold">Classe</label>
        <select name="classe_e" class="form-select" data-ajax-nav-auto>
          <option value="">— Choisir —</option>
          <?php foreach ($classes as $c): $vide = (int) $c['nb_eleves'] === 0; ?>
            <option value="<?= $c['IDClasses'] ?>" <?= $id_cl_e == $c['IDClasses'] ? 'selected' : '' ?> <?= $vide ? 'disabled' : '' ?>>
              <?= h($c['DesignationClasses']) ?><?= $vide ? ' (aucun élève inscrit)' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($id_cl_e && $eleves_e): ?>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Élève</label>
        <select name="eleve_e" class="form-select" data-ajax-nav-auto>
          <option value="">— Choisir —</option>
          <?php foreach ($eleves_e as $el): ?>
            <option value="<?= $el['id_eleve'] ?>" <?= $id_eleve == $el['id_eleve'] ? 'selected' : '' ?>>
              <?= nom_eleve_aff($el['Nom_elv'], $el['Prenom_elv'] ?? '', $el['Nom_arabe_elv'] ?? '', false) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <?php if ($id_cl_e): ?><input type="hidden" name="classe_e" value="<?= $id_cl_e ?>"><?php endif; ?>
    </form>
  </div>
</div>

<?php if (!$seq_active): ?>
  <div class="alert alert-warning d-flex align-items-center gap-2">
    <i class="bi bi-exclamation-triangle-fill"></i>
    Aucune évaluation active — la saisie des notes est désactivée tant qu'une séquence n'a pas été activée.
  </div>
<?php elseif ($id_cl_e && $id_eleve && $id_seq_e && !empty($mats_e)):
  $eleve_info = db_one("SELECT * FROM eleve WHERE id_eleve=?", [$id_eleve]);
  $saisi_e    = count(array_filter($mats_e, fn($m) => $m['note'] !== null));
  $seq_lbl    = '';
  foreach ($seqs as $s) { if ($s['id_seq'] == $id_seq_e) { $seq_lbl = h($s['libelle_trim'] . ' — ' . $s['libelle_seq']); break; } }
  $coef_saisi = 0.0; $note_coef = 0.0;
  foreach ($mats_e as $m) { if ($m['note'] !== null) { $coef_saisi += (float) $m['coef']; $note_coef += (float) $m['note'] * (float) $m['coef']; } }
  $moy_e = $coef_saisi > 0 ? round($note_coef / $coef_saisi, 2) : null;
?>

<div class="card mb-3" style="border-left:4px solid #7c3aed;background:#faf5ff">
  <div class="card-body py-2 d-flex align-items-center gap-3">
    <div style="width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,#7c3aed,#a78bfa);
                display:flex;align-items:center;justify-content:center;flex-shrink:0">
      <i class="bi bi-person-fill" style="color:#fff;font-size:1.1rem"></i>
    </div>
    <div>
      <div class="fw-bold" style="font-size:.9rem">
        <?= nom_eleve_aff($eleve_info['Nom_elv'], $eleve_info['Prenom_elv'] ?? '', $eleve_info['Nom_arabe_elv'] ?? '') ?>
      </div>
      <div style="font-size:.72rem;color:#6b7280">
        Matricule <?= h($eleve_info['Mat_elv']) ?> &nbsp;|&nbsp;
        <?= $seq_lbl ?> &nbsp;|&nbsp;
        <?= $saisi_e ?>/<?= count($mats_e) ?> matière(s)
      </div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-body p-0">
    <form method="post">
      <?= csrf_champ() ?>
      <input type="hidden" name="action" value="save_eleve">
      <input type="hidden" name="id_seq" value="<?= $id_seq_e ?>">
      <input type="hidden" name="id_cl" value="<?= $id_cl_e ?>">
      <input type="hidden" name="id_eleve" value="<?= $id_eleve ?>">
      <div class="table-responsive">
        <table class="table table-abz table-hover mb-0">
          <thead>
            <tr>
              <th>Matière</th>
              <th style="width:70px;text-align:center">Coef</th>
              <th style="width:100px;text-align:center">Note /20</th>
              <th style="width:90px;text-align:center">Points</th>
              <th style="width:80px;text-align:center">Mention</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($mats_e as $m):
              $mention = $m['note'] !== null ? appreciation_moyenne_arabe((float) $m['note']) : '';
              $points  = $m['note'] !== null ? round((float) $m['note'] * (float) $m['coef'], 2) : null;
            ?>
            <tr>
              <td class="fw-semibold" style="font-size:.82rem">
                <?= h($m['matiere_fr']) ?>
                <span class="text-muted" dir="rtl" lang="ar" style="font-size:.85em"> (<?= h($m['matiere_ar']) ?>)</span>
              </td>
              <td class="text-center"><?= (int) $m['coef'] ?></td>
              <td>
                <input type="number" name="notes[<?= $m['id_mat'] ?>]"
                       class="form-control form-control-sm eleve-note-ar text-center"
                       min="0" max="20" step="0.25"
                       value="<?= $m['note'] !== null ? (float) $m['note'] : '' ?>"
                       placeholder="—" data-mat="<?= $m['id_mat'] ?>" data-coef="<?= (int) $m['coef'] ?>">
              </td>
              <td class="text-center points-cell-e" id="points-e-<?= $m['id_mat'] ?>" style="font-size:.82rem;font-weight:700;color:#1e4fd8">
                <?= $points !== null ? $points : '—' ?>
              </td>
              <td class="text-center mention-cell-e" id="mention-e-<?= $m['id_mat'] ?>" dir="rtl" lang="ar"
                  style="font-size:.8rem;font-weight:700;color:<?= jn_mention_ar_color($mention) ?>">
                <?= h($mention) ?: '—' ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr style="background:#f8faff">
              <td class="fw-bold text-end" colspan="3">Moyenne pondérée</td>
              <td class="text-center fw-bold" id="moy-eleve-ar" style="color:#1e4fd8"><?= $moy_e !== null ? $moy_e : '—' ?></td>
              <td></td>
            </tr>
          </tfoot>
        </table>
      </div>
      <div class="card-body py-2 border-top">
        <button class="btn btn-primary btn-sm">
          <i class="bi bi-save me-1"></i>Enregistrer les notes
        </button>
      </div>
    </form>
  </div>
</div>

<script>
(function() {
  var LIBS = { 'ضعيف': [0, 10], 'متوسط': [10, 12], 'مقبول': [12, 14], 'جيد': [14, 16], 'جيد جدا': [16, 19], 'ممتاز': [19, 20.01] };
  var COLORS = { 'ضعيف': '#dc2626', 'متوسط': '#d97706', 'مقبول': '#ca8a04', 'جيد': '#1d4ed8', 'جيد جدا': '#0891b2', 'ممتاز': '#15803d' };
  function mention(v) {
    for (var k in LIBS) { if (v >= LIBS[k][0] && v < LIBS[k][1]) return k; }
    return '—';
  }
  var inps = Array.from(document.querySelectorAll('.eleve-note-ar'));
  function recalcMoyenne() {
    var coefTotal = 0, noteCoef = 0;
    inps.forEach(function(i) {
      if (i.value !== '') { var c = parseFloat(i.dataset.coef); coefTotal += c; noteCoef += parseFloat(i.value) * c; }
    });
    document.getElementById('moy-eleve-ar').textContent = coefTotal > 0 ? Math.round((noteCoef / coefTotal) * 100) / 100 : '—';
  }
  function clamperBareme(inp) {
    if (inp.value === '') return;
    var v = parseFloat(inp.value.replace(',', '.'));
    if (isNaN(v)) return;
    // Ne réécrit le champ QUE si la valeur dépasse réellement le barème —
    // reformater à chaque frappe (ex. arrondi systématique) écrasait une
    // virgule décimale en cours de saisie ("3," redevenait "3" avant même
    // d'avoir pu taper "45"), rendant impossible la saisie d'un nombre
    // comme 3,45. Le filtrage des caractères autorisés (chiffres + une
    // seule virgule/point) est fait globalement par restreindreSaisieNumerique()
    // (layout/footer.php) ; ce garde-fou-ci ne gère que le dépassement du max/min.
    var max = parseFloat(inp.max), min = parseFloat(inp.min || '0');
    var clamped = v;
    if (clamped > max) clamped = max; else if (clamped < min) clamped = min;
    if (clamped !== v) inp.value = clamped;
  }
  inps.forEach(function(inp, idx) {
    inp.addEventListener('input', function() {
      clamperBareme(this);
      var idMat = this.dataset.mat;
      var pointsCell = document.getElementById('points-e-' + idMat);
      var mentionCell = document.getElementById('mention-e-' + idMat);
      if (this.value === '') { pointsCell.textContent = '—'; mentionCell.textContent = '—'; mentionCell.style.color = '#6b7280'; recalcMoyenne(); return; }
      var v = parseFloat(this.value);
      pointsCell.textContent = Math.round(v * parseFloat(this.dataset.coef) * 100) / 100;
      var m = mention(v);
      mentionCell.textContent = m; mentionCell.style.color = COLORS[m] || '#6b7280';
      recalcMoyenne();
    });
    inp.addEventListener('keydown', function(e) {
      if (e.key === 'Enter') { e.preventDefault(); if (inps[idx + 1]) inps[idx + 1].focus(); }
    });
  });
})();
</script>

<?php elseif ($id_cl_e && $id_eleve && empty($mats_e)): ?>
  <div class="alert alert-warning py-2">Aucune matière arabe configurée pour cette classe.</div>
<?php elseif ($id_cl_e && !$id_eleve): ?>
  <div class="alert alert-light text-muted py-3 text-center">Sélectionnez un élève.</div>
<?php else: ?>
  <div class="alert alert-light text-muted py-4 text-center">
    <i class="bi bi-arrow-up" style="font-size:2rem;display:block;opacity:.2;margin-bottom:.4rem"></i>
    Sélectionnez une classe puis un élève.
  </div>
<?php endif; ?>


<?php elseif ($onglet === 'copie'): ?>
<!-- ══════════════════════════════════════════════════
     ONGLET 3 — Copie de notes
══════════════════════════════════════════════════ -->
<div class="row g-3 mb-3">
  <div class="col-md-8">
    <div class="card">
      <div class="card-header py-2" style="background:#f8faff">
        <span class="fw-semibold" style="font-size:.82rem">
          <i class="bi bi-sliders me-1 text-primary"></i>Paramètres de la copie
        </span>
      </div>
      <div class="card-body py-2">
        <form method="get" id="form-copie-ar" class="row g-2" data-ajax-nav-form>
          <input type="hidden" name="onglet" value="copie">
          <div class="col-md-6">
            <label class="form-label">Classe</label>
            <select name="classe_cop" class="form-select form-select-sm" data-ajax-nav-auto>
              <option value="">— Choisir —</option>
              <?php foreach ($classes as $c): $vide = (int) $c['nb_eleves'] === 0; ?>
                <option value="<?= $c['IDClasses'] ?>" <?= $id_cl_cop == $c['IDClasses'] ? 'selected' : '' ?> <?= $vide ? 'disabled' : '' ?>><?= h($c['DesignationClasses']) ?><?= $vide ? ' (aucun élève inscrit)' : '' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php if ($id_cl_cop && $mats_cop): ?>
          <div class="col-md-6">
            <label class="form-label">Matière</label>
            <select name="mat_cop" class="form-select form-select-sm" data-ajax-nav-auto>
              <option value="">— Choisir —</option>
              <?php foreach ($mats_cop as $m): ?>
                <option value="<?= $m['id_mat'] ?>" <?= $id_mat_cop == $m['id_mat'] ? 'selected' : '' ?>><?= h($m['matiere_fr']) ?> / <?= h($m['matiere_ar']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-5">
            <label class="form-label">Évaluation source</label>
            <select name="seq_src" class="form-select form-select-sm" data-ajax-nav-auto>
              <option value="">— Source —</option>
              <?php foreach ($seqs_copie as $s): ?>
                <option value="<?= $s['id_seq'] ?>" <?= $id_seq_src == $s['id_seq'] ? 'selected' : '' ?>><?= h($s['libelle_trim'] . ' — ' . $s['libelle_seq']) ?><?= $seq_active && $seq_active['id_seq'] == $s['id_seq'] ? ' ★' : '' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-5">
            <label class="form-label">Évaluation destination</label>
            <select name="seq_dst" class="form-select form-select-sm" data-ajax-nav-auto>
              <option value="">— Destination —</option>
              <?php foreach ($seqs_copie as $s): ?>
                <option value="<?= $s['id_seq'] ?>" <?= $id_seq_dst == $s['id_seq'] ? 'selected' : '' ?>><?= h($s['libelle_trim'] . ' — ' . $s['libelle_seq']) ?><?= $seq_active && $seq_active['id_seq'] == $s['id_seq'] ? ' ★' : '' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label">Ajust. <i class="bi bi-info-circle text-muted" title="Points à ajouter sur la note /20"></i></label>
            <input type="number" name="ajust" class="form-control form-control-sm" step="0.25" min="-20" max="20"
                   value="<?= $ajust ?>" data-ajax-nav-auto placeholder="0">
          </div>
          <?php endif; ?>
        </form>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card h-100" style="border-left:4px solid #f59e0b;background:#fffbeb">
      <div class="card-body py-3">
        <div class="fw-bold mb-1" style="color:#92400e;font-size:.82rem"><i class="bi bi-lightbulb-fill me-1 text-warning"></i>Comment ça marche</div>
        <ul style="font-size:.78rem;color:#78350f;padding-left:1.1rem;margin:0">
          <li>Choisissez source, destination et matière</li>
          <li>Ajoutez un ajustement (±) sur la note</li>
          <li>Prévisualisez puis confirmez</li>
          <li><strong>Maximum : 20/20 — jamais dépassé</strong></li>
        </ul>
      </div>
    </div>
  </div>
</div>

<?php if ($id_cl_cop && $id_seq_src && $id_seq_dst && $id_mat_cop && !empty($preview)):
  $nb_src  = count(array_filter($preview, fn($r) => $r['note_src'] !== null));
  $mat_cop_info = null; foreach ($mats_cop as $m) { if ($m['id_mat'] == $id_mat_cop) { $mat_cop_info = $m; break; } }
  $src_inf = null; $dst_inf = null;
  foreach ($seqs_copie as $s) {
      if ($s['id_seq'] == $id_seq_src) $src_inf = $s;
      if ($s['id_seq'] == $id_seq_dst) $dst_inf = $s;
  }
?>
<div class="card mb-3" style="border:1px solid #d1fae5;background:#f0fdf4">
  <div class="card-body py-2 d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div style="font-size:.82rem;color:#065f46">
      <strong><?= h($mat_cop_info['matiere_fr'] ?? '') ?></strong> —
      <span class="badge" style="background:#fde68a;color:#92400e"><?= $src_inf ? h($src_inf['libelle_trim'] . ' — ' . $src_inf['libelle_seq']) : '' ?></span>
      <i class="bi bi-arrow-right mx-1"></i>
      <span class="badge" style="background:#a7f3d0;color:#065f46"><?= $dst_inf ? h($dst_inf['libelle_trim'] . ' — ' . $dst_inf['libelle_seq']) : '' ?></span>
      <?php if ($ajust != 0): ?>
        <span class="badge ms-1" style="background:<?= $ajust > 0 ? '#dbeafe' : '#fee2e2' ?>;color:<?= $ajust > 0 ? '#1e40af' : '#991b1b' ?>">
          <?= $ajust > 0 ? '+' : '' ?><?= $ajust ?> pts
        </span>
      <?php endif; ?>
    </div>
    <form method="post">
      <?= csrf_champ() ?>
      <input type="hidden" name="action" value="exec_copie">
      <input type="hidden" name="id_cl" value="<?= $id_cl_cop ?>">
      <input type="hidden" name="id_seq_src" value="<?= $id_seq_src ?>">
      <input type="hidden" name="id_seq_dst" value="<?= $id_seq_dst ?>">
      <input type="hidden" name="id_mat" value="<?= $id_mat_cop ?>">
      <input type="hidden" name="ajust" value="<?= $ajust ?>">
      <button class="btn btn-success btn-sm" onclick="return confirm('Copier <?= $nb_src ?> note(s) ?')">
        <i class="bi bi-check-circle me-1"></i>Confirmer (<?= $nb_src ?> élève(s))
      </button>
    </form>
  </div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-abz table-hover mb-0" style="font-size:.82rem">
      <thead>
        <tr>
          <th style="width:36px">N°</th><th>Élève</th><th style="width:150px">Nom arabe</th>
          <th style="text-align:center">Note source</th>
          <?php if ($ajust != 0): ?><th style="text-align:center">Ajust.</th><?php endif; ?>
          <th style="text-align:center">Note destination</th><th style="text-align:center">Info</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($preview as $i => $r):
          $capped = ($r['note_src'] !== null) && (round((float) $r['note_src'] + $ajust, 4) > 20.001);
          $no_note = ($r['note_src'] === null);
        ?>
        <tr <?= $no_note ? 'style="opacity:.5"' : '' ?>>
          <td class="text-muted"><?= $i + 1 ?></td>
          <td class="fw-semibold"><?= h(mb_strtoupper($r['Nom_elv'])) . ' ' . h($r['Prenom_elv'] ?? '') ?></td>
          <td class="text-muted" dir="rtl" lang="ar"><?= h($r['Nom_arabe_elv'] ?? '') ?: '—' ?></td>
          <td class="text-center"><span style="background:#f3f4f6;padding:2px 8px;border-radius:8px;font-weight:600"><?= $r['note_src'] !== null ? rtrim(rtrim(number_format((float) $r['note_src'], 2, '.', ''), '0'), '.') : '—' ?></span></td>
          <?php if ($ajust != 0): ?><td class="text-center" style="color:<?= $ajust > 0 ? '#15803d' : '#dc2626' ?>"><?= $r['note_src'] !== null ? ($ajust > 0 ? '+' : '') . $ajust : '—' ?></td><?php endif; ?>
          <td class="text-center">
            <?php if ($r['note_dst'] !== null): ?>
              <span style="background:<?= $capped ? '#fef3c7' : '#d1fae5' ?>;color:<?= $capped ? '#92400e' : '#065f46' ?>;padding:2px 8px;border-radius:8px;font-weight:700"><?= rtrim(rtrim(number_format((float) $r['note_dst'], 2, '.', ''), '0'), '.') ?></span>
            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
          </td>
          <td class="text-center" style="font-size:.72rem">
            <?php if ($no_note): ?>
              <span class="badge bg-secondary" style="font-size:.65rem">Sans note</span>
            <?php elseif ($capped): ?>
              <span class="badge" style="background:#fef3c7;color:#92400e;font-size:.65rem"><i class="bi bi-lock-fill"></i> Plafonné</span>
            <?php else: ?>
              <i class="bi bi-check text-success"></i>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php elseif ($id_cl_cop): ?>
  <div class="alert alert-light text-muted py-3 text-center">Complétez les paramètres pour prévisualiser.</div>
<?php else: ?>
  <div class="alert alert-light text-muted py-4 text-center">
    <i class="bi bi-arrow-up" style="font-size:2rem;display:block;opacity:.2;margin-bottom:.4rem"></i>
    Sélectionnez une classe pour commencer.
  </div>
<?php endif; ?>


<?php elseif ($onglet === 'non_saisis'): ?>
<!-- ══════════════════════════════════════════════════
     ONGLET 4 — Matières non saisies
══════════════════════════════════════════════════ -->
<?php if (!$seq_active): ?>
  <div class="alert alert-warning d-flex align-items-center gap-2">
    <i class="bi bi-exclamation-triangle-fill"></i>
    Aucune évaluation active — impossible d'afficher les matières non saisies.
  </div>
<?php else: ?>

<div class="row g-2 mb-3 align-items-center">
  <div class="col-auto">
    <form method="get" class="d-flex align-items-center gap-2" data-ajax-nav-form>
      <input type="hidden" name="onglet" value="non_saisis">
      <label class="form-label mb-0" style="font-size:.8rem;white-space:nowrap">Classe</label>
      <select name="classe_ns" class="form-select form-select-sm" style="min-width:160px" data-ajax-nav-auto>
        <option value="">Toutes les classes</option>
        <?php foreach ($classes as $c): $vide = (int) $c['nb_eleves'] === 0; ?>
          <option value="<?= $c['IDClasses'] ?>" <?= $id_cl_ns == $c['IDClasses'] ? 'selected' : '' ?> <?= $vide ? 'disabled' : '' ?>><?= h($c['DesignationClasses']) ?><?= $vide ? ' (aucun élève inscrit)' : '' ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($id_cl_ns): ?>
        <a href="<?= APP_URL ?>/pages/notes_arabe/index.php?onglet=non_saisis" data-ajax-nav class="btn btn-outline-secondary btn-sm">
          <i class="bi bi-x"></i>
        </a>
      <?php endif; ?>
    </form>
  </div>
  <div class="col-auto ms-auto d-flex align-items-center gap-2">
    <span class="badge" style="background:#fee2e2;color:#991b1b;font-size:.78rem;padding:5px 10px;border-radius:8px">
      <i class="bi bi-exclamation-triangle-fill me-1"></i>
      <?= count($non_saisis) ?> matière(s) sans aucune note — <?= h($seq_active['libelle_seq']) ?>
    </span>
  </div>
</div>

<?php if (empty($non_saisis)): ?>
  <div class="alert alert-success d-flex align-items-center gap-2">
    <i class="bi bi-check-circle-fill fs-4"></i>
    <div>
      <div class="fw-bold">Toutes les notes sont saisies !</div>
      <div style="font-size:.82rem">Aucune matière sans note pour <?= $id_cl_ns ? 'cette classe' : 'toutes les classes' ?>.</div>
    </div>
  </div>
<?php else: ?>

<div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
  <div class="d-flex align-items-center gap-2" style="font-size:.82rem">
    <label class="mb-0 text-muted">Afficher</label>
    <select id="ns-per-page-ar" class="form-select form-select-sm" style="width:75px">
      <option value="10">10</option>
      <option value="25" selected>25</option>
      <option value="50">50</option>
      <option value="100">100</option>
      <option value="0">Tout</option>
    </select>
    <span class="text-muted">lignes par page</span>
  </div>
  <div id="ns-info-ar" style="font-size:.8rem;color:#6b7280"></div>
</div>

<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-abz table-hover mb-0" style="font-size:.82rem" id="ns-table-ar">
        <thead>
          <tr>
            <th style="width:34px">N°</th>
            <?php if (!$id_cl_ns): ?><th>Classe</th><?php endif; ?>
            <th>Matière</th>
            <th>Enseignant responsable</th>
            <th style="width:100px;text-align:center">Élèves</th>
            <th style="width:90px;text-align:center">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($non_saisis as $i => $r): ?>
          <tr>
            <td class="text-muted"><?= $i + 1 ?></td>
            <?php if (!$id_cl_ns): ?>
            <td>
              <span style="background:#eff6ff;color:#1e40af;padding:2px 8px;border-radius:6px;font-size:.75rem;font-weight:600">
                <?= h($r['classe']) ?>
              </span>
            </td>
            <?php endif; ?>
            <td class="fw-semibold">
              <?= h($r['matiere_fr']) ?>
              <span class="text-muted" dir="rtl" lang="ar" style="font-size:.85em"> (<?= h($r['matiere_ar']) ?>)</span>
            </td>
            <td>
              <span class="text-muted fst-italic" style="font-size:.75rem">Non assigné</span>
            </td>
            <td class="text-center">
              <span style="font-size:.75rem;color:#dc2626;font-weight:700">
                <?= $r['nb_eleves'] ?> élève<?= $r['nb_eleves'] > 1 ? 's' : '' ?>
              </span>
            </td>
            <td class="text-center">
              <a href="<?= APP_URL ?>/pages/notes_arabe/index.php?onglet=classe&classe_c=<?= $r['id_classe'] ?>" data-ajax-nav
                 class="btn btn-sm btn-primary" style="font-size:.72rem;padding:2px 8px"
                 title="Saisir les notes">
                <i class="bi bi-pencil-square me-1"></i>Saisir
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="d-flex align-items-center justify-content-between mt-2 flex-wrap gap-2">
  <div id="ns-pag-info-ar" style="font-size:.8rem;color:#6b7280"></div>
  <nav><ul class="pagination pagination-sm mb-0" id="ns-pag-ar"></ul></nav>
</div>

<script>
(function() {
  var rows = Array.from(document.querySelectorAll('#ns-table-ar tbody tr'));
  var perSel = document.getElementById('ns-per-page-ar');
  var info = document.getElementById('ns-pag-info-ar');
  var pag = document.getElementById('ns-pag-ar');
  var cur = 1;

  function render() {
    var per = parseInt(perSel.value) || 0;
    var total = rows.length;
    var pages = per > 0 ? Math.ceil(total / per) : 1;
    if (cur > pages) cur = pages;
    if (cur < 1) cur = 1;

    rows.forEach(function(r, i) {
      if (per === 0) { r.style.display = ''; }
      else {
        var start = (cur - 1) * per;
        r.style.display = (i >= start && i < start + per) ? '' : 'none';
      }
    });

    var start = per > 0 ? (cur - 1) * per + 1 : 1;
    var end = per > 0 ? Math.min(cur * per, total) : total;
    info.textContent = total > 0 ? 'Affichage ' + start + ' à ' + end + ' sur ' + total + ' matière(s)' : '';

    pag.innerHTML = '';
    if (pages <= 1) return;

    function btn(label, page, disabled, active) {
      var li = document.createElement('li');
      li.className = 'page-item' + (disabled ? ' disabled' : '') + (active ? ' active' : '');
      var a = document.createElement('a');
      a.className = 'page-link'; a.href = '#'; a.innerHTML = label;
      a.addEventListener('click', function(e) { e.preventDefault(); if (!disabled) { cur = page; render(); } });
      li.appendChild(a); pag.appendChild(li);
    }

    btn('&laquo;', cur - 1, cur === 1, false);
    var from = Math.max(1, cur - 2), to = Math.min(pages, cur + 2);
    if (from > 1) { btn('1', 1, false, false); if (from > 2) btn('…', cur, true, false); }
    for (var p = from; p <= to; p++) btn(p, p, false, p === cur);
    if (to < pages) { if (to < pages - 1) btn('…', cur, true, false); btn(pages, pages, false, false); }
    btn('&raquo;', cur + 1, cur === pages, false);
  }

  perSel.addEventListener('change', function() { cur = 1; render(); });
  render();
})();
</script>

<?php endif; ?>
<?php endif; // seq_active ?>

<?php endif; // fin chaine if/elseif onglets ?>

</div><!-- /#notesar-zone -->

<?php
if ($es_partiel) exit; // rien de plus dans une réponse AJAX partielle.
$ajax_zone_id = 'notesar-zone'; // voir layout/footer.php — initAjaxZone() y est appelé après sa propre définition
require_once __DIR__ . '/../../layout/footer.php';
