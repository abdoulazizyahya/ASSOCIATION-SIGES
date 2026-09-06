<?php
/**
 * pages/notes/index.php — Saisie des notes (piste française APC)
 * Port fidèle d'ABZ_MBE (même structure/CSS/4 onglets : Saisie par classe,
 * Saisie par élève, Copie de notes, Compétences non saisies) — seules les
 * DONNÉES viennent du schéma jaynitaare : chaque « note » est en réalité 4
 * sous-notes (Orale/Écrite/Pratique/Savoir-être) sommées en un total, sur un
 * barème par compétence (`discipline`, via competences_classe() de
 * notes_apc.php) au lieu d'une simple note/20 par matière — chaque endroit
 * où ABZ_MBE affiche UNE note/UN champ affiche donc ici 4 champs + un total,
 * la « cote » (NA/ECA/A/A+) remplaçant l'échelle à 7 crans A+.../D d'ABZ_MBE
 * (appreciation_fr(), déjà utilisée par les bulletins).
 *
 * Écart assumé, même politique que Statistiques/Conseil de classe/Résultat
 * annuel : `dispenser` (affectation enseignant → classe/compétence) existe
 * dans le schéma mais n'est pas peuplée par l'établissement — pas de
 * restriction par enseignant (les 3 rôles DIRECTEUR/SECRETAIRE/ENSEIGNANT
 * voient tout), contrairement à ABZ_MBE qui restreint par ADMIN/ENSEIGNANT
 * via `dispenser`/`enseignat_principal`. La colonne « Enseignant responsable »
 * de l'onglet 4 reste affichée (même forme) mais vide (donnée non disponible
 * ici, pas une régression).
 *
 * Noms d'élèves : uniquement en français ici (eleve.Nom_arabe_elv non
 * affiché, sur demande explicite du 14/08 — le nom arabe n'a de sens que
 * sur la piste arabe, voir pages/notes_arabe/index.php qui le garde).
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
exiger_acces_pedagogie();
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

// ── Classes disponibles ────────────────────────────────────────
// LEFT JOIN (pas INNER) — une classe sans élève inscrit reste visible dans
// les selects ci-dessous (grisée, non sélectionnable) plutôt que
// silencieusement absente (confusion signalée le 21/08/2026).
// DIRECTEUR/SECRETAIRE voient tout ; un ENSEIGNANT ne voit que ses classes
// affectées (pages/enseignants/liste.php, onglet Affectation des classes —
// demande explicite du 29/08/2026, voir filtrer_classes_visibles()).
$classes = filtrer_classes_visibles(db_all(
    "SELECT c.IDClasses, c.DesignationClasses, n.OrdreNiveau, COUNT(i.id_eleve) AS nb_eleves
     FROM classe c LEFT JOIN niveau n ON n.LibelleNiveau = c.Niveau
     LEFT JOIN inscrire i ON i.IDClasses = c.IDClasses AND i.val_annee = ?
     GROUP BY c.IDClasses, c.DesignationClasses, n.OrdreNiveau
     ORDER BY n.OrdreNiveau, c.DesignationClasses",
    [$val_annee]
), $val_annee, 'fr');

// Toutes les séquences de l'année (choix libre pour les 3 rôles — jaynitaare
// n'a pas de distinction ADMIN vs ENSEIGNANT pour ça, voir Classement).
$seqs = db_all(
    "SELECT s.id_seq, s.libelle_seq, t.libelle_trim
     FROM sequence s JOIN trimestre t ON t.id_trim = s.id_trim
     WHERE t.id_annee = ? ORDER BY s.id_seq", [$val_annee]
);
$seqs_copie = $seqs;

// Cote → couleur (échelle NA/ECA/A/A+ de jaynitaare, voir appreciation_fr()
// dans notes_apc.php — 4 crans au lieu des 7 A+/A/B+/B/C+/C/D d'ABZ_MBE).
function jn_cote_color(string $cote): string {
    return match ($cote) { 'A+' => '#15803d', 'A' => '#1d4ed8', 'ECA' => '#d97706', 'NA' => '#dc2626', default => '#6b7280' };
}

// Section anglophone d'une classe (même convention que pdf/bulletin_trimestriel.php
// — section rattachée au NIVEAU, pas à la classe) : détermine si la saisie
// doit afficher les libellés anglais des compétences/groupes plutôt que les
// français — la saisie/le calcul restent TOUJOURS sur le jeu langue='Fr'
// (voir competences_classe()), seul l'affichage change. Bug signalé le
// 26/08/2026 : la liste déroulante « Compétence » montrait toujours le
// français, même pour une classe de section anglophone.
function jn_section_en(int $id_classe): bool {
    static $cache = [];
    if (!array_key_exists($id_classe, $cache)) {
        $cache[$id_classe] = db_val(
            "SELECT n.Section FROM classe c JOIN niveau n ON n.LibelleNiveau = c.Niveau WHERE c.IDClasses=?",
            [$id_classe]
        ) === 'An';
    }
    return $cache[$id_classe];
}
// Libellé à afficher pour une ligne de competences_classe() (nom_comp_en si
// section anglophone et libellé EN disponible, sinon repli sur le français).
function jn_libelle_comp(array $c, bool $section_en): string {
    return ($section_en && !empty($c['nom_comp_en'])) ? $c['nom_comp_en'] : $c['nom_comp'];
}
function jn_libelle_groupe_comp(array $c, bool $section_en): string {
    return ($section_en && !empty($c['libelle_groupe_comp_en'])) ? $c['libelle_groupe_comp_en'] : $c['libelle_groupe_comp'];
}

// ══════════════════════════════════════════════════════════════
//  ONGLET 1 — Saisie par classe (une compétence, toute la classe)
// ══════════════════════════════════════════════════════════════
$id_cl_c   = (int) ($_GET['classe_c'] ?? 0);
exiger_acces_classe($id_cl_c, $val_annee, 'fr');   // enseignant restreint : refuse une classe hors périmètre
$id_comp_c = (int) ($_GET['comp_c'] ?? 0);
// Demande explicite du 17/08/2026 : la saisie peut porter sur n'importe
// quelle évaluation (séquence) du TRIMESTRE ACTIF — pas seulement LA
// séquence active elle-même (permet de revenir corriger UA3 par exemple
// alors qu'UA4, même trimestre, est devenue active) — jamais une séquence
// d'un autre trimestre. Repli sur la séquence active si absente/invalide.
$seqs_trim_actif_c = sequences_trimestre_actif();
$id_seq_c = (int) ($_GET['seq_c'] ?? ($seq_active['id_seq'] ?? 0));
if (!in_array($id_seq_c, array_column($seqs_trim_actif_c, 'id_seq'), true)) {
    $id_seq_c = (int) ($seq_active['id_seq'] ?? 0);
}
$comps_c   = [];
$eleves_c  = [];

if ($onglet === 'classe') {
    if ($id_cl_c) {
        $comps_c = competences_classe($id_cl_c, $val_annee);
    }
    if ($id_cl_c && $id_comp_c && $id_seq_c) {
        $eleves_c = db_all(
            "SELECT e.id_eleve, e.Nom_elv, e.Prenom_elv, e.Mat_elv,
                    cs.note_orale, cs.note_ecrite, cs.note_pratique, cs.note_savoir_etre, cs.note_total_points
             FROM eleve e
             JOIN inscrire i ON i.id_eleve = e.id_eleve
             LEFT JOIN composer_sequence cs ON cs.id_eleve = e.id_eleve AND cs.id_comp = ? AND cs.IDClasses = ? AND cs.id_seq = ? AND cs.val_annee = ?
             WHERE i.IDClasses = ? AND i.val_annee = ? AND e.statut = 'actif'
             ORDER BY e.Nom_elv, e.Prenom_elv",
            [$id_comp_c, $id_cl_c, $id_seq_c, $val_annee, $id_cl_c, $val_annee]
        );
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'save_classe') {
        csrf_verifier();
        $p_classe = (int) post('id_cl');
        exiger_acces_classe($p_classe, $val_annee, 'fr');
        $p_seq    = (int) post('id_seq');
        $p_comp   = (int) post('id_comp');

        // Défense en profondeur : la séquence postée doit appartenir au
        // trimestre actif (même contrôle que la page — voir sequences_trimestre_actif()).
        if (!in_array($p_seq, array_column(sequences_trimestre_actif(), 'id_seq'), true)) {
            flash_set('erreur', 'Évaluation invalide ou hors du trimestre actif.');
            rediriger("pages/notes/index.php?onglet=classe&classe_c=$p_classe&comp_c=$p_comp");
        }

        $bareme = db_one(
            "SELECT orale, ecrite, pratique, savoir_etre FROM discipline WHERE IDClasses=? AND id_comp=? AND annee_scol=?",
            [$p_classe, $p_comp, $val_annee]
        );
        $id_trim_p = (int) db_val("SELECT id_trim FROM sequence WHERE id_seq=?", [$p_seq]);
        if (!$bareme || !$id_trim_p) {
            flash_set('erreur', 'Barème ou séquence introuvable.');
            rediriger("pages/notes/index.php?onglet=classe&classe_c=$p_classe&comp_c=$p_comp");
        }

        $clamp = fn($val, $max) => $val === '' ? null : max(0.0, min((float) $max, (float) str_replace(',', '.', $val)));
        $touches = 0;
        foreach ($_POST['notes'] ?? [] as $id_eleve => $vals) {
            $id_eleve = (int) $id_eleve;
            $o  = $clamp($vals['orale'] ?? '', $bareme['orale']);
            $e  = $clamp($vals['ecrite'] ?? '', $bareme['ecrite']);
            $p  = $clamp($vals['pratique'] ?? '', $bareme['pratique']);
            $se = $clamp($vals['savoir_etre'] ?? '', $bareme['savoir_etre']);
            if ($o === null && $e === null && $p === null && $se === null) {
                db_exec("DELETE FROM composer_sequence WHERE id_eleve=? AND id_comp=? AND IDClasses=? AND id_seq=? AND val_annee=?",
                    [$id_eleve, $p_comp, $p_classe, $p_seq, $val_annee]);
                continue;
            }
            $o ??= 0; $e ??= 0; $p ??= 0; $se ??= 0;
            $total = $o + $e + $p + $se;
            db_exec(
                "INSERT INTO composer_sequence (id_eleve, id_comp, IDClasses, id_seq, val_annee, note_orale, note_ecrite, note_pratique, note_savoir_etre, note_total_points)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE note_orale=VALUES(note_orale), note_ecrite=VALUES(note_ecrite),
                     note_pratique=VALUES(note_pratique), note_savoir_etre=VALUES(note_savoir_etre), note_total_points=VALUES(note_total_points)",
                [$id_eleve, $p_comp, $p_classe, $p_seq, $val_annee, $o, $e, $p, $se, $total]
            );
            $touches++;
        }
        recalculer_moyennes_trimestre_classe($p_classe, $id_trim_p, $val_annee);
        foreach (db_all("SELECT DISTINCT id_eleve FROM inscrire WHERE IDClasses=? AND val_annee=?", [$p_classe, $val_annee]) as $e) {
            calculer_moyenne_annuelle_eleve((int) $e['id_eleve'], $p_classe, $val_annee);
        }
        flash_set('succes', "$touches note(s) enregistrée(s).");
        rediriger("pages/notes/index.php?onglet=classe&classe_c=$p_classe&comp_c=$p_comp");
    }
}

// ══════════════════════════════════════════════════════════════
//  ONGLET 2 — Saisie par élève (toutes les compétences, un élève)
// ══════════════════════════════════════════════════════════════
$id_cl_e  = (int) ($_GET['classe_e'] ?? 0);
exiger_acces_classe($id_cl_e, $val_annee, 'fr');
$id_eleve = (int) ($_GET['eleve_e'] ?? 0);
exiger_acces_eleve($id_eleve, 'fr');
// Demande explicite du 17/08/2026 : même règle que l'onglet 1 — n'importe
// quelle évaluation du trimestre actif, pas seulement la séquence active.
$seqs_trim_actif_e = sequences_trimestre_actif();
$id_seq_e = (int) ($_GET['seq_e'] ?? ($seq_active['id_seq'] ?? 0));
if (!in_array($id_seq_e, array_column($seqs_trim_actif_e, 'id_seq'), true)) {
    $id_seq_e = (int) ($seq_active['id_seq'] ?? 0);
}
$eleves_e = [];
$comps_e  = [];

if ($onglet === 'eleve') {
    if ($id_cl_e) {
        $eleves_e = db_all(
            "SELECT e.id_eleve, e.Nom_elv, e.Prenom_elv, e.Mat_elv FROM eleve e
             JOIN inscrire i ON i.id_eleve = e.id_eleve AND i.IDClasses = ? AND i.val_annee = ?
             WHERE e.statut = 'actif' ORDER BY e.Nom_elv, e.Prenom_elv",
            [$id_cl_e, $val_annee]
        );
    }
    if ($id_cl_e && $id_eleve && $id_seq_e) {
        $comps_brutes = competences_classe($id_cl_e, $val_annee);
        $notes_idx = db_all(
            "SELECT id_comp, note_orale, note_ecrite, note_pratique, note_savoir_etre, note_total_points
             FROM composer_sequence WHERE id_eleve=? AND IDClasses=? AND id_seq=? AND val_annee=?",
            [$id_eleve, $id_cl_e, $id_seq_e, $val_annee]
        );
        $notes_par_comp = [];
        foreach ($notes_idx as $n) { $notes_par_comp[(int) $n['id_comp']] = $n; }
        foreach ($comps_brutes as $c) {
            $n = $notes_par_comp[(int) $c['id_comp']] ?? null;
            $c['note_orale']       = $n['note_orale'] ?? null;
            $c['note_ecrite']      = $n['note_ecrite'] ?? null;
            $c['note_pratique']    = $n['note_pratique'] ?? null;
            $c['note_savoir_etre'] = $n['note_savoir_etre'] ?? null;
            $c['note_total']       = $n['note_total_points'] ?? null;
            $comps_e[] = $c;
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'save_eleve') {
        csrf_verifier();
        $p_seq    = (int) post('id_seq');
        $p_classe = (int) post('id_cl');
        exiger_acces_classe($p_classe, $val_annee, 'fr');
        $p_eleve  = (int) post('id_eleve');

        // Défense en profondeur : même contrôle que l'onglet 1.
        if (!in_array($p_seq, array_column(sequences_trimestre_actif(), 'id_seq'), true)) {
            flash_set('erreur', 'Évaluation invalide ou hors du trimestre actif.');
            rediriger("pages/notes/index.php?onglet=eleve&classe_e=$p_classe&eleve_e=$p_eleve");
        }

        $id_trim_p = (int) db_val("SELECT id_trim FROM sequence WHERE id_seq=?", [$p_seq]);

        $clamp = fn($val, $max) => $val === '' ? null : max(0.0, min((float) $max, (float) str_replace(',', '.', $val)));
        $touches = 0;
        foreach ($_POST['notes'] ?? [] as $id_comp => $vals) {
            $id_comp = (int) $id_comp;
            $bareme = db_one(
                "SELECT orale, ecrite, pratique, savoir_etre FROM discipline WHERE IDClasses=? AND id_comp=? AND annee_scol=?",
                [$p_classe, $id_comp, $val_annee]
            );
            if (!$bareme) continue;
            $o  = $clamp($vals['orale'] ?? '', $bareme['orale']);
            $e  = $clamp($vals['ecrite'] ?? '', $bareme['ecrite']);
            $p  = $clamp($vals['pratique'] ?? '', $bareme['pratique']);
            $se = $clamp($vals['savoir_etre'] ?? '', $bareme['savoir_etre']);
            if ($o === null && $e === null && $p === null && $se === null) {
                db_exec("DELETE FROM composer_sequence WHERE id_eleve=? AND id_comp=? AND IDClasses=? AND id_seq=? AND val_annee=?",
                    [$p_eleve, $id_comp, $p_classe, $p_seq, $val_annee]);
                continue;
            }
            $o ??= 0; $e ??= 0; $p ??= 0; $se ??= 0;
            $total = $o + $e + $p + $se;
            db_exec(
                "INSERT INTO composer_sequence (id_eleve, id_comp, IDClasses, id_seq, val_annee, note_orale, note_ecrite, note_pratique, note_savoir_etre, note_total_points)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE note_orale=VALUES(note_orale), note_ecrite=VALUES(note_ecrite),
                     note_pratique=VALUES(note_pratique), note_savoir_etre=VALUES(note_savoir_etre), note_total_points=VALUES(note_total_points)",
                [$p_eleve, $id_comp, $p_classe, $p_seq, $val_annee, $o, $e, $p, $se, $total]
            );
            $touches++;
        }
        if ($id_trim_p) {
            recalculer_moyennes_trimestre_classe($p_classe, $id_trim_p, $val_annee);
            calculer_moyenne_annuelle_eleve($p_eleve, $p_classe, $val_annee);
        }
        flash_set('succes', "Notes de l'élève enregistrées ($touches compétence(s)).");
        rediriger("pages/notes/index.php?onglet=eleve&classe_e=$p_classe&eleve_e=$p_eleve");
    }
}

// ══════════════════════════════════════════════════════════════
//  ONGLET 3 — Copie de notes (d'une séquence vers une autre)
// ══════════════════════════════════════════════════════════════
$id_cl_cop   = (int) ($_GET['classe_cop'] ?? 0);
exiger_acces_classe($id_cl_cop, $val_annee, 'fr');
$id_seq_src  = (int) ($_GET['seq_src'] ?? 0);
$id_seq_dst  = (int) ($_GET['seq_dst'] ?? 0);
$id_comp_cop = (int) ($_GET['comp_cop'] ?? 0);
$ajust       = (float) str_replace(',', '.', $_GET['ajust'] ?? '0');
$preview     = [];
$comps_cop   = [];
$bareme_cop  = null;

if ($onglet === 'copie') {
    if ($id_cl_cop) {
        $comps_cop = competences_classe($id_cl_cop, $val_annee);
    }
    if ($id_cl_cop && $id_comp_cop) {
        foreach ($comps_cop as $c) { if ((int) $c['id_comp'] === $id_comp_cop) { $bareme_cop = $c; break; } }
    }
    if ($id_cl_cop && $id_seq_src && $id_comp_cop && $bareme_cop) {
        $preview = db_all(
            "SELECT e.id_eleve, e.Nom_elv, e.Prenom_elv, e.Mat_elv,
                    cs.note_total_points AS note_src,
                    LEAST(?, GREATEST(0, IF(cs.note_total_points IS NOT NULL, cs.note_total_points + ?, NULL))) AS note_dst
             FROM eleve e
             JOIN inscrire i ON i.id_eleve = e.id_eleve AND i.IDClasses = ? AND i.val_annee = ?
             LEFT JOIN composer_sequence cs ON cs.id_eleve = e.id_eleve AND cs.id_comp = ? AND cs.IDClasses = ? AND cs.id_seq = ? AND cs.val_annee = ?
             WHERE e.statut = 'actif'
             ORDER BY e.Nom_elv, e.Prenom_elv",
            [(float) $bareme_cop['total_points'], $ajust, $id_cl_cop, $val_annee, $id_comp_cop, $id_cl_cop, $id_seq_src, $val_annee]
        );
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'exec_copie') {
        csrf_verifier();
        $p_classe = (int) post('id_cl');
        exiger_acces_classe($p_classe, $val_annee, 'fr');
        $p_src    = (int) post('id_seq_src');
        $p_dst    = (int) post('id_seq_dst');
        $p_comp   = (int) post('id_comp');
        $p_ajust  = (float) str_replace(',', '.', post('ajust') ?? '0');
        $p_bareme = db_one(
            "SELECT orale, ecrite, pratique, savoir_etre, total_points FROM discipline WHERE IDClasses=? AND id_comp=? AND annee_scol=?",
            [$p_classe, $p_comp, $val_annee]
        );
        $id_trim_dst = (int) db_val("SELECT id_trim FROM sequence WHERE id_seq=?", [$p_dst]);

        $rows = db_all(
            "SELECT cs.id_eleve, cs.note_orale, cs.note_ecrite, cs.note_pratique, cs.note_savoir_etre, cs.note_total_points
             FROM eleve e
             JOIN inscrire i ON i.id_eleve = e.id_eleve AND i.IDClasses = ? AND i.val_annee = ?
             JOIN composer_sequence cs ON cs.id_eleve = e.id_eleve AND cs.id_comp = ? AND cs.IDClasses = ? AND cs.id_seq = ? AND cs.val_annee = ?
             WHERE e.statut = 'actif'",
            [$p_classe, $val_annee, $p_comp, $p_classe, $p_src, $val_annee]
        );
        $nb = 0;
        foreach ($rows as $r) {
            $ancien_total = (float) $r['note_total_points'];
            if ($ancien_total <= 0 && $p_ajust <= 0) continue;
            $nouveau_total = min((float) $p_bareme['total_points'], max(0.0, $ancien_total + $p_ajust));
            // Sous-notes redistribuées proportionnellement (même ratio
            // O/É/P/SE que la séquence source) — préserve la somme = total,
            // invariant utilisé partout ailleurs dans le moteur de calcul.
            $ratio = $ancien_total > 0 ? $nouveau_total / $ancien_total : 0.0;
            $o  = round((float) $r['note_orale'] * $ratio, 2);
            $e  = round((float) $r['note_ecrite'] * $ratio, 2);
            $p  = round((float) $r['note_pratique'] * $ratio, 2);
            $se = round((float) $nouveau_total - $o - $e - $p, 2); // absorbe l'arrondi
            db_exec(
                "INSERT INTO composer_sequence (id_eleve, id_comp, IDClasses, id_seq, val_annee, note_orale, note_ecrite, note_pratique, note_savoir_etre, note_total_points)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE note_orale=VALUES(note_orale), note_ecrite=VALUES(note_ecrite),
                     note_pratique=VALUES(note_pratique), note_savoir_etre=VALUES(note_savoir_etre), note_total_points=VALUES(note_total_points)",
                [$r['id_eleve'], $p_comp, $p_classe, $p_dst, $val_annee, $o, $e, $p, $se, $nouveau_total]
            );
            $nb++;
        }
        if ($id_trim_dst) {
            recalculer_moyennes_trimestre_classe($p_classe, $id_trim_dst, $val_annee);
            foreach (db_all("SELECT DISTINCT id_eleve FROM inscrire WHERE IDClasses=? AND val_annee=?", [$p_classe, $val_annee]) as $e) {
                calculer_moyenne_annuelle_eleve((int) $e['id_eleve'], $p_classe, $val_annee);
            }
        }
        flash_set('succes', "$nb note(s) copiée(s).");
        rediriger("pages/notes/index.php?onglet=copie&classe_cop=$p_classe&seq_src=$p_src&seq_dst=$p_dst&comp_cop=$p_comp");
    }
}

// ══════════════════════════════════════════════════════════════
//  ONGLET 4 — Compétences non saisies (évaluation choisie du trimestre actif)
// ══════════════════════════════════════════════════════════════
$id_cl_ns   = (int) ($_GET['classe_ns'] ?? 0);
exiger_acces_classe($id_cl_ns, $val_annee, 'fr');
$non_saisis = [];
// Demande explicite du 17/08/2026 : même règle que les onglets 1 et 2.
$seqs_trim_actif_ns = sequences_trimestre_actif();
$id_seq_ns = (int) ($_GET['seq_ns'] ?? ($seq_active['id_seq'] ?? 0));
if (!in_array($id_seq_ns, array_column($seqs_trim_actif_ns, 'id_seq'), true)) {
    $id_seq_ns = (int) ($seq_active['id_seq'] ?? 0);
}

if ($onglet === 'non_saisis' && $seq_active) {
    $where_cl_a = $id_cl_ns ? "AND d.IDClasses = " . (int) $id_cl_ns : '';
    // n.Section + m_en.nom_comp (jumeau par code_comp) : ce tableau croise
    // plusieurs classes à la fois, potentiellement Fr et An mélangées — même
    // bascule de libellé que jn_libelle_comp()/jn_section_en() plus haut,
    // mais ici par ligne (chaque classe peut avoir sa propre section).
    $non_saisis = db_all(
        "SELECT d.IDClasses AS id_classe, c.DesignationClasses AS classe, n.Section AS section,
                m.nom_comp AS competence, m_en.nom_comp AS competence_en, m.code_comp,
                (SELECT COUNT(*) FROM inscrire ii WHERE ii.IDClasses=d.IDClasses AND ii.val_annee=? AND EXISTS(SELECT 1 FROM eleve ee WHERE ee.id_eleve=ii.id_eleve AND ee.statut='actif')) AS nb_eleves,
                (SELECT COUNT(*) FROM composer_sequence nn
                 WHERE nn.id_comp=d.id_comp AND nn.IDClasses=d.IDClasses AND nn.id_seq=? AND nn.val_annee=?) AS nb_saisis
         FROM discipline d
         JOIN classe c ON c.IDClasses=d.IDClasses
         LEFT JOIN niveau n ON n.LibelleNiveau=c.Niveau
         JOIN competence m ON m.id_comp=d.id_comp
         JOIN groupe_competence g ON g.id_groupe_comp=m.id_groupe_comp AND g.langue='Fr'
         LEFT JOIN groupe_competence g_en ON g_en.ordre_affichage=g.ordre_affichage AND g_en.langue='An'
         LEFT JOIN competence m_en ON m_en.id_groupe_comp=g_en.id_groupe_comp AND m_en.code_comp=m.code_comp
         WHERE d.annee_scol=? AND d.actif=1 $where_cl_a
         HAVING nb_eleves > 0 AND nb_saisis = 0
         ORDER BY c.DesignationClasses, g.ordre_affichage, m.code_comp",
        [$val_annee, $id_seq_ns, $val_annee, $val_annee]
    );
}

// Mode "partiel" (AJAX) : réponse limitée au contenu de #notes-zone, sans
// layout/header.php ni layout/footer.php — voir initAjaxZone()/chargerPartiel()
// dans layout/footer.php. Changement d'onglet et sélecteurs classe/compétence/
// élève/séquence passent par ce mécanisme — jamais les formulaires POST
// (enregistrement des notes, copie), qui continuent de recharger normalement
// après leur redirection.
$es_partiel = isset($_GET['partiel']);

$titre_page = 'Notes';
if (!$es_partiel) {
    require_once __DIR__ . '/../../layout/header.php';
} else {
    header('Content-Type: text/html; charset=utf-8');
}
?>

<div id="notes-zone">

<div class="page-titre d-flex align-items-center justify-content-between flex-wrap gap-2">
  <h4><i class="bi bi-pencil-square me-1 text-primary"></i>Gestion des notes</h4>
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
</div>

<?= flash_html() ?>

<!-- Onglets -->
<ul class="nav nav-tabs mb-3" style="border-bottom:2px solid #e5e7eb">
  <li class="nav-item">
    <a class="nav-link <?= $onglet === 'classe' ? 'active' : '' ?>" data-ajax-nav
       href="<?= APP_URL ?>/pages/notes/index.php?onglet=classe<?= $id_cl_c ? "&classe_c=$id_cl_c" : '' ?>">
      <i class="bi bi-people me-1"></i>Saisie par classe
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $onglet === 'eleve' ? 'active' : '' ?>" data-ajax-nav
       href="<?= APP_URL ?>/pages/notes/index.php?onglet=eleve<?= $id_cl_e ? "&classe_e=$id_cl_e" : '' ?><?= $id_eleve ? "&eleve_e=$id_eleve" : '' ?>">
      <i class="bi bi-person me-1"></i>Saisie par élève
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $onglet === 'copie' ? 'active' : '' ?>" data-ajax-nav
       href="<?= APP_URL ?>/pages/notes/index.php?onglet=copie<?= $id_cl_cop ? "&classe_cop=$id_cl_cop" : '' ?>">
      <i class="bi bi-copy me-1"></i>Copie de notes
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $onglet === 'non_saisis' ? 'active' : '' ?>" data-ajax-nav
       href="<?= APP_URL ?>/pages/notes/index.php?onglet=non_saisis">
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
      <div class="col-md-3">
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
      <?php if ($id_cl_c && $comps_c): ?>
      <div class="col-md-5">
        <label class="form-label fw-semibold">Compétence</label>
        <select name="comp_c" class="form-select" data-ajax-nav-auto>
          <option value="">— Choisir —</option>
          <?php $section_c = jn_section_en($id_cl_c); $groupe_courant = null; foreach ($comps_c as $c):
            if ($c['id_groupe_comp'] !== $groupe_courant) {
                if ($groupe_courant !== null) echo '</optgroup>';
                $groupe_courant = $c['id_groupe_comp'];
                echo '<optgroup label="' . h(mb_strtoupper(mb_substr(jn_libelle_groupe_comp($c, $section_c), 0, 45))) . '">';
            } ?>
            <option value="<?= $c['id_comp'] ?>" <?= $id_comp_c == $c['id_comp'] ? 'selected' : '' ?>>
              <?= h($c['code_comp'] . ' — ' . jn_libelle_comp($c, $section_c)) ?> (/<?= (int) $c['total_points'] ?>)
            </option>
          <?php endforeach; if ($groupe_courant !== null) echo '</optgroup>'; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Évaluation</label>
        <select name="seq_c" class="form-select" data-ajax-nav-auto>
          <?php foreach ($seqs_trim_actif_c as $s): ?>
            <option value="<?= $s['id_seq'] ?>" <?= $id_seq_c == $s['id_seq'] ? 'selected' : '' ?>>
              <?= h($s['libelle_trim'] . ' — ' . $s['libelle_seq']) ?><?= ($seq_active['id_seq'] ?? 0) == $s['id_seq'] ? ' ★' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
    </form>
  </div>
</div>

<?php if ($id_cl_c && $id_comp_c && !empty($eleves_c)):
  $comp_info = null;
  foreach ($comps_c as $c) { if ($c['id_comp'] == $id_comp_c) { $comp_info = $c; break; } }
  $saisi = count(array_filter($eleves_c, fn($e) => $e['note_total_points'] !== null));
  // Barème unique pour toute la table (une seule compétence ici, contrairement
  // à la vue élève où le barème varie par ligne) : une colonne à barème 0 est
  // donc inutile pour TOUS les élèves de cette table -> masquée entièrement,
  // pas juste désactivée (ex. compétences dont le barème n'utilise pas la
  // Pratique, voir `discipline` : c'est fréquent, pas une exception isolée).
  $champs_labels_c = ['orale' => 'Orale', 'ecrite' => 'Écrite', 'pratique' => 'Pratique', 'savoir_etre' => 'Savoir-être'];
  $champs_actifs_c = array_filter($champs_labels_c, fn($champ) => (float) $comp_info[$champ] > 0, ARRAY_FILTER_USE_KEY);
  $seq_lbl_c = ''; foreach ($seqs_trim_actif_c as $s) { if ($s['id_seq'] == $id_seq_c) { $seq_lbl_c = $s['libelle_trim'] . ' — ' . $s['libelle_seq']; break; } }
  $section_c_hdr = jn_section_en($id_cl_c);
?>
<div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
  <span class="fw-bold" style="font-size:.88rem"><?= h($comp_info['code_comp'] . ' — ' . jn_libelle_comp($comp_info, $section_c_hdr)) ?></span>
  <?php $autre_langue_c = $section_c_hdr ? $comp_info['nom_comp'] : $comp_info['nom_comp_en']; if ($autre_langue_c !== ''): ?>
  <span class="badge-code"><?= h($autre_langue_c) ?></span>
  <?php endif; ?>
  <span style="background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:10px;font-size:.72rem;font-weight:600">
    Barème : O/<?= (int) $comp_info['orale'] ?> · É/<?= (int) $comp_info['ecrite'] ?> · P/<?= (int) $comp_info['pratique'] ?> · SE/<?= (int) $comp_info['savoir_etre'] ?> = <?= (int) $comp_info['total_points'] ?>
  </span>
  <?php if ($seq_lbl_c): ?>
  <span style="background:#ede9fe;color:#5b21b6;padding:2px 8px;border-radius:10px;font-size:.72rem;font-weight:600">
    <i class="bi bi-calendar3 me-1"></i><?= h($seq_lbl_c) ?>
  </span>
  <?php endif; ?>
  <span style="background:#f3f4f6;color:#6b7280;padding:2px 8px;border-radius:10px;font-size:.72rem">
    <?= $saisi ?>/<?= count($eleves_c) ?> saisi(s)
  </span>
</div>

<div class="card">
  <div class="card-body p-0">
    <form method="post" data-ajax-post-form>
      <?= csrf_champ() ?>
      <input type="hidden" name="action" value="save_classe">
      <input type="hidden" name="id_cl" value="<?= $id_cl_c ?>">
      <input type="hidden" name="id_seq" value="<?= $id_seq_c ?>">
      <input type="hidden" name="id_comp" value="<?= $id_comp_c ?>">
      <div class="table-responsive">
        <table class="table table-abz table-hover mb-0" id="tbl-notes">
          <thead>
            <tr>
              <th style="width:34px">N°</th>
              <th>Nom et Prénom</th>
              <?php foreach ($champs_actifs_c as $champ => $label): ?>
                <th style="width:90px" class="text-center"><?= $label ?> /<?= (int) $comp_info[$champ] ?></th>
              <?php endforeach; ?>
              <th style="width:80px" class="text-center">Total /<?= (int) $comp_info['total_points'] ?></th>
              <th style="width:52px" class="text-center">Cote</th>
              <th style="width:50px" class="text-center">
                <button type="button" class="btn btn-link btn-sm p-0" onclick="viderToutClasse()" title="Vider tout">
                  <i class="bi bi-eraser" style="font-size:.8rem"></i>
                </button>
              </th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($eleves_c as $i => $el):
              $cote = $el['note_total_points'] !== null ? appreciation_fr((float) $el['note_total_points'], (float) $comp_info['total_points']) : '';
            ?>
              <tr>
                <td class="text-muted" style="font-size:.72rem"><?= $i + 1 ?></td>
                <td class="fw-semibold" style="font-size:.82rem"><?= h(mb_strtoupper($el['Nom_elv'])) . ' ' . h($el['Prenom_elv'] ?? '') ?></td>
                <?php foreach ($champs_actifs_c as $champ => $label): ?>
                  <td>
                    <input type="number" class="form-control form-control-sm note-inp-c text-center"
                           name="notes[<?= $el['id_eleve'] ?>][<?= $champ ?>]"
                           min="0" max="<?= (float) $comp_info[$champ] ?>" step="0.25"
                           value="<?= $el['note_' . $champ] !== null ? (float) $el['note_' . $champ] : '' ?>"
                           data-eleve="<?= $el['id_eleve'] ?>" placeholder="—">
                  </td>
                <?php endforeach; ?>
                <td class="text-center fw-bold total-cell" id="total-<?= $el['id_eleve'] ?>" style="color:#1e4fd8">
                  <?= $el['note_total_points'] !== null ? (float) $el['note_total_points'] : '—' ?>
                </td>
                <td class="text-center cote-cl" id="cote-<?= $el['id_eleve'] ?>" style="font-size:.78rem;font-weight:700;color:<?= jn_cote_color($cote) ?>">
                  <?= h($cote) ?: '—' ?>
                </td>
                <td></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="card-body py-2 border-top d-flex align-items-center gap-2">
        <button class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Enregistrer</button>
        <span class="ms-auto text-muted" style="font-size:.75rem">Entrée / Tab pour naviguer — laisser les 4 champs vides retire la note</span>
      </div>
    </form>
  </div>
</div>

<script>
(function() {
  var BAREME = { orale: <?= (float) $comp_info['orale'] ?>, ecrite: <?= (float) $comp_info['ecrite'] ?>, pratique: <?= (float) $comp_info['pratique'] ?>, savoir_etre: <?= (float) $comp_info['savoir_etre'] ?>, total: <?= (float) $comp_info['total_points'] ?> };
  function cote(v, bareme) {
    if (bareme <= 0) return ['—', '#6b7280'];
    var pct = v / bareme;
    if (pct < 0.55) return ['NA', '#dc2626'];
    if (pct < 0.75) return ['ECA', '#d97706'];
    if (pct < 0.90) return ['A', '#1d4ed8'];
    return ['A+', '#15803d'];
  }
  // Empêche de dépasser le barème (max de chaque champ) dès la saisie — pas
  // seulement au moment d'enregistrer (le serveur clampe aussi, en dernier
  // recours, mais l'utilisateur doit voir tout de suite que la note qu'il
  // tape est ramenée au maximum autorisé, pas après coup).
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
      var row = this.closest('tr');
      var vals = Array.from(row.querySelectorAll('.note-inp-c')).map(function(i) { return i.value === '' ? null : parseFloat(i.value); });
      var totalCell = document.getElementById('total-' + idEleve);
      var coteCell  = document.getElementById('cote-' + idEleve);
      if (vals.every(function(v) { return v === null; })) { totalCell.textContent = '—'; coteCell.textContent = '—'; coteCell.style.color = '#6b7280'; return; }
      var total = vals.reduce(function(s, v) { return s + (v || 0); }, 0);
      total = Math.round(total * 100) / 100;
      totalCell.textContent = total;
      var c = cote(total, BAREME.total);
      coteCell.textContent = c[0]; coteCell.style.color = c[1];
    });
  });
  var inps = Array.from(document.querySelectorAll('.note-inp-c'));
  inps.forEach(function(inp, idx) {
    inp.addEventListener('keydown', function(e) {
      if (e.key === 'Enter') { e.preventDefault(); if (inps[idx + 1]) inps[idx + 1].focus(); }
    });
  });
  window.viderToutClasse = function() {
    inps.forEach(function(i) { i.value = ''; i.dispatchEvent(new Event('input')); });
  };
})();
</script>

<?php elseif ($id_cl_c && $id_comp_c): ?>
  <div class="alert alert-info py-2"><i class="bi bi-info-circle me-1"></i>Aucun élève actif dans cette classe.</div>
<?php elseif ($id_cl_c && empty($comps_c)): ?>
  <div class="alert alert-warning py-2"><i class="bi bi-exclamation me-1"></i>Aucune compétence active configurée pour cette classe.</div>
<?php elseif ($id_cl_c): ?>
  <div class="alert alert-light text-muted py-3 text-center">Sélectionnez une compétence.</div>
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
              <?= h(mb_strtoupper($el['Nom_elv']) . ' ' . ($el['Prenom_elv'] ?? '')) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">Évaluation</label>
        <select name="seq_e" class="form-select" data-ajax-nav-auto>
          <?php foreach ($seqs_trim_actif_e as $s): ?>
            <option value="<?= $s['id_seq'] ?>" <?= $id_seq_e == $s['id_seq'] ? 'selected' : '' ?>>
              <?= h($s['libelle_trim'] . ' — ' . $s['libelle_seq']) ?><?= ($seq_active['id_seq'] ?? 0) == $s['id_seq'] ? ' ★' : '' ?>
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
<?php elseif ($id_cl_e && $id_eleve && $id_seq_e && !empty($comps_e)):
  $eleve_info = db_one("SELECT * FROM eleve WHERE id_eleve=?", [$id_eleve]);
  $saisi_e    = count(array_filter($comps_e, fn($c) => $c['note_total'] !== null));
  $seq_lbl    = '';
  foreach ($seqs as $s) { if ($s['id_seq'] == $id_seq_e) { $seq_lbl = h($s['libelle_trim'] . ' — ' . $s['libelle_seq']); break; } }
?>

<div class="card mb-3" style="border-left:4px solid #7c3aed;background:#faf5ff">
  <div class="card-body py-2 d-flex align-items-center gap-3">
    <div style="width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,#7c3aed,#a78bfa);
                display:flex;align-items:center;justify-content:center;flex-shrink:0">
      <i class="bi bi-person-fill" style="color:#fff;font-size:1.1rem"></i>
    </div>
    <div>
      <div class="fw-bold" style="font-size:.9rem">
        <?= h(mb_strtoupper($eleve_info['Nom_elv']) . ' ' . ($eleve_info['Prenom_elv'] ?? '')) ?>
      </div>
      <div style="font-size:.72rem;color:#6b7280">
        Matricule <?= h($eleve_info['Mat_elv']) ?> &nbsp;|&nbsp;
        <?= $seq_lbl ?> &nbsp;|&nbsp;
        <?= $saisi_e ?>/<?= count($comps_e) ?> compétence(s)
      </div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-body p-0">
    <form method="post" data-ajax-post-form>
      <?= csrf_champ() ?>
      <input type="hidden" name="action" value="save_eleve">
      <input type="hidden" name="id_seq" value="<?= $id_seq_e ?>">
      <input type="hidden" name="id_cl" value="<?= $id_cl_e ?>">
      <input type="hidden" name="id_eleve" value="<?= $id_eleve ?>">
      <div class="table-responsive">
        <table class="table table-abz table-hover mb-0">
          <thead>
            <tr>
              <th>Compétence</th>
              <th style="width:100px;text-align:center">Orale</th>
              <th style="width:100px;text-align:center">Écrite</th>
              <th style="width:100px;text-align:center">Pratique</th>
              <th style="width:110px;text-align:center">Savoir-être</th>
              <th style="width:80px;text-align:center">Total</th>
              <th style="width:70px;text-align:center">Cote</th>
            </tr>
          </thead>
          <tbody>
            <?php $section_e = jn_section_en($id_cl_e); foreach ($comps_e as $c):
              $cote = $c['note_total'] !== null ? appreciation_fr((float) $c['note_total'], (float) $c['total_points']) : '';
            ?>
            <tr>
              <td class="fw-semibold" style="font-size:.82rem"><?= h($c['code_comp'] . ' — ' . jn_libelle_comp($c, $section_e)) ?></td>
              <?php foreach (['orale', 'ecrite', 'pratique', 'savoir_etre'] as $champ):
                $max_champ = rtrim(rtrim(number_format((float) $c[$champ], 2, '.', ''), '0'), '.');
              ?>
                <?php if ((float) $c[$champ] <= 0): ?>
                  <!-- Barème 0 POUR CETTE compétence précise (le barème varie
                       par ligne ici, contrairement à la vue classe — masquer
                       toute la colonne serait faux pour les autres lignes qui,
                       elles, utilisent bien ce champ) : case grisée, non
                       saisissable, plutôt qu'un champ à "/0" trompeur. -->
                  <td class="text-center text-muted" style="font-size:.78rem;background:#f9fafb">—</td>
                <?php else: ?>
                  <td class="py-2">
                    <div class="d-flex align-items-center justify-content-center gap-1">
                      <input type="number" name="notes[<?= $c['id_comp'] ?>][<?= $champ ?>]"
                             class="form-control eleve-note text-center fw-semibold"
                             min="0" max="<?= (float) $c[$champ] ?>" step="0.25"
                             value="<?= $c['note_' . $champ] !== null ? (float) $c['note_' . $champ] : '' ?>"
                             placeholder="—" data-comp="<?= $c['id_comp'] ?>"
                             style="max-width:78px;font-size:.95rem;padding:.4rem .5rem">
                      <span class="text-muted" style="font-size:.72rem;white-space:nowrap">/<?= $max_champ ?></span>
                    </div>
                  </td>
                <?php endif; ?>
              <?php endforeach; ?>
              <td class="text-center total-cell-e" id="total-e-<?= $c['id_comp'] ?>" style="font-size:.82rem;font-weight:700;color:#1e4fd8">
                <?= $c['note_total'] !== null ? (float) $c['note_total'] : '—' ?>
              </td>
              <td class="text-center cote-cell-e" id="cote-e-<?= $c['id_comp'] ?>" style="font-size:.82rem;font-weight:700;color:<?= jn_cote_color($cote) ?>">
                <?= h($cote) ?: '—' ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
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
  var BAREMES = {};
  <?php foreach ($comps_e as $c): ?>
  BAREMES[<?= (int) $c['id_comp'] ?>] = { orale: <?= (float) $c['orale'] ?>, ecrite: <?= (float) $c['ecrite'] ?>, pratique: <?= (float) $c['pratique'] ?>, savoir_etre: <?= (float) $c['savoir_etre'] ?>, total: <?= (float) $c['total_points'] ?> };
  <?php endforeach; ?>
  function cote(v, bareme) {
    if (bareme <= 0) return ['—', '#6b7280'];
    var pct = v / bareme;
    var code = pct < 0.55 ? 'NA' : (pct < 0.75 ? 'ECA' : (pct < 0.90 ? 'A' : 'A+'));
    var couleur = { NA: '#dc2626', ECA: '#d97706', A: '#1d4ed8', 'A+': '#15803d' }[code];
    return [code, couleur];
  }
  var inps = Array.from(document.querySelectorAll('.eleve-note'));
  function recalcComp(idComp) {
    var b = BAREMES[idComp];
    var champs = document.querySelectorAll('.eleve-note[data-comp="' + idComp + '"]');
    var vals = Array.from(champs).map(function(i) { return i.value === '' ? null : parseFloat(i.value); });
    var totalCell = document.getElementById('total-e-' + idComp);
    var coteCell  = document.getElementById('cote-e-' + idComp);
    if (vals.every(function(v) { return v === null; })) {
      totalCell.textContent = '—'; coteCell.textContent = '—'; coteCell.style.color = '#6b7280';
      return;
    }
    var total = Math.round(vals.reduce(function(s, v) { return s + (v || 0); }, 0) * 100) / 100;
    totalCell.textContent = total;
    var c = cote(total, b.total);
    coteCell.textContent = c[0]; coteCell.style.color = c[1];
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
    inp.addEventListener('input', function() { clamperBareme(this); recalcComp(this.dataset.comp); });
    inp.addEventListener('keydown', function(e) {
      if (e.key === 'Enter') { e.preventDefault(); if (inps[idx + 1]) inps[idx + 1].focus(); }
    });
  });
})();
</script>

<?php elseif ($id_cl_e && $id_eleve && empty($comps_e)): ?>
  <div class="alert alert-warning py-2">Aucune compétence active configurée pour cette classe.</div>
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
        <form method="get" id="form-copie" class="row g-2" data-ajax-nav-form>
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
          <?php if ($id_cl_cop && $comps_cop): ?>
          <div class="col-md-6">
            <label class="form-label">Compétence</label>
            <select name="comp_cop" class="form-select form-select-sm" data-ajax-nav-auto>
              <option value="">— Choisir —</option>
              <?php $section_cop = jn_section_en($id_cl_cop); foreach ($comps_cop as $c): ?>
                <option value="<?= $c['id_comp'] ?>" <?= $id_comp_cop == $c['id_comp'] ? 'selected' : '' ?>><?= h($c['code_comp'] . ' — ' . jn_libelle_comp($c, $section_cop)) ?></option>
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
            <label class="form-label">Ajust. <i class="bi bi-info-circle text-muted" title="Points à ajouter sur le total (redistribués proportionnellement)"></i></label>
            <input type="number" name="ajust" class="form-control form-control-sm" step="0.25"
                   min="-<?= $bareme_cop ? (int) $bareme_cop['total_points'] : 20 ?>" max="<?= $bareme_cop ? (int) $bareme_cop['total_points'] : 20 ?>"
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
          <li>Choisissez source, destination et compétence</li>
          <li>Ajoutez un ajustement (±) sur le total</li>
          <li>Prévisualisez puis confirmez</li>
          <li><strong>Maximum : le barème de la compétence — jamais dépassé</strong></li>
        </ul>
      </div>
    </div>
  </div>
</div>

<?php if ($id_cl_cop && $id_seq_src && $id_seq_dst && $id_comp_cop && !empty($preview)):
  $nb_src  = count(array_filter($preview, fn($r) => $r['note_src'] !== null));
  $src_inf = null; $dst_inf = null;
  foreach ($seqs_copie as $s) {
      if ($s['id_seq'] == $id_seq_src) $src_inf = $s;
      if ($s['id_seq'] == $id_seq_dst) $dst_inf = $s;
  }
?>
<div class="card mb-3" style="border:1px solid #d1fae5;background:#f0fdf4">
  <div class="card-body py-2 d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div style="font-size:.82rem;color:#065f46">
      <strong><?= h($bareme_cop['code_comp'] . ' — ' . jn_libelle_comp($bareme_cop, jn_section_en($id_cl_cop))) ?></strong> —
      <span class="badge" style="background:#fde68a;color:#92400e"><?= $src_inf ? h($src_inf['libelle_trim'] . ' — ' . $src_inf['libelle_seq']) : '' ?></span>
      <i class="bi bi-arrow-right mx-1"></i>
      <span class="badge" style="background:#a7f3d0;color:#065f46"><?= $dst_inf ? h($dst_inf['libelle_trim'] . ' — ' . $dst_inf['libelle_seq']) : '' ?></span>
      <?php if ($ajust != 0): ?>
        <span class="badge ms-1" style="background:<?= $ajust > 0 ? '#dbeafe' : '#fee2e2' ?>;color:<?= $ajust > 0 ? '#1e40af' : '#991b1b' ?>">
          <?= $ajust > 0 ? '+' : '' ?><?= $ajust ?> pts
        </span>
      <?php endif; ?>
    </div>
    <form method="post" data-ajax-post-form>
      <?= csrf_champ() ?>
      <input type="hidden" name="action" value="exec_copie">
      <input type="hidden" name="id_cl" value="<?= $id_cl_cop ?>">
      <input type="hidden" name="id_seq_src" value="<?= $id_seq_src ?>">
      <input type="hidden" name="id_seq_dst" value="<?= $id_seq_dst ?>">
      <input type="hidden" name="id_comp" value="<?= $id_comp_cop ?>">
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
          <th style="width:36px">N°</th><th>Élève</th>
          <th style="text-align:center">Total source</th>
          <?php if ($ajust != 0): ?><th style="text-align:center">Ajust.</th><?php endif; ?>
          <th style="text-align:center">Total destination</th><th style="text-align:center">Info</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($preview as $i => $r):
          $capped = ($r['note_src'] !== null) && (round((float) $r['note_src'] + $ajust, 4) > (float) $bareme_cop['total_points'] + 0.001);
          $no_note = ($r['note_src'] === null);
        ?>
        <tr <?= $no_note ? 'style="opacity:.5"' : '' ?>>
          <td class="text-muted"><?= $i + 1 ?></td>
          <td class="fw-semibold"><?= h(mb_strtoupper($r['Nom_elv'])) . ' ' . h($r['Prenom_elv'] ?? '') ?></td>
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
     ONGLET 4 — Compétences non saisies
══════════════════════════════════════════════════ -->
<?php if (!$seq_active): ?>
  <div class="alert alert-warning d-flex align-items-center gap-2">
    <i class="bi bi-exclamation-triangle-fill"></i>
    Aucune évaluation active — impossible d'afficher les compétences non saisies.
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
      <label class="form-label mb-0" style="font-size:.8rem;white-space:nowrap">Évaluation</label>
      <select name="seq_ns" class="form-select form-select-sm" style="min-width:160px" data-ajax-nav-auto>
        <?php foreach ($seqs_trim_actif_ns as $s): ?>
          <option value="<?= $s['id_seq'] ?>" <?= $id_seq_ns == $s['id_seq'] ? 'selected' : '' ?>>
            <?= h($s['libelle_trim'] . ' — ' . $s['libelle_seq']) ?><?= ($seq_active['id_seq'] ?? 0) == $s['id_seq'] ? ' ★' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
      <?php if ($id_cl_ns): ?>
        <a href="<?= APP_URL ?>/pages/notes/index.php?onglet=non_saisis" data-ajax-nav class="btn btn-outline-secondary btn-sm">
          <i class="bi bi-x"></i>
        </a>
      <?php endif; ?>
    </form>
  </div>
  <div class="col-auto ms-auto d-flex align-items-center gap-2">
    <span class="badge" style="background:#fee2e2;color:#991b1b;font-size:.78rem;padding:5px 10px;border-radius:8px">
      <i class="bi bi-exclamation-triangle-fill me-1"></i>
      <?php $seq_lbl_ns = ''; foreach ($seqs_trim_actif_ns as $s) { if ($s['id_seq'] == $id_seq_ns) { $seq_lbl_ns = $s['libelle_seq']; break; } } ?>
      <?= count($non_saisis) ?> compétence(s) sans aucune note — <?= h($seq_lbl_ns) ?>
    </span>
  </div>
</div>

<?php if (empty($non_saisis)): ?>
  <div class="alert alert-success d-flex align-items-center gap-2">
    <i class="bi bi-check-circle-fill fs-4"></i>
    <div>
      <div class="fw-bold">Toutes les notes sont saisies !</div>
      <div style="font-size:.82rem">Aucune compétence sans note pour <?= $id_cl_ns ? 'cette classe' : 'toutes les classes' ?>.</div>
    </div>
  </div>
<?php else: ?>

<div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
  <div class="d-flex align-items-center gap-2" style="font-size:.82rem">
    <label class="mb-0 text-muted">Afficher</label>
    <select id="ns-per-page" class="form-select form-select-sm" style="width:75px">
      <option value="10">10</option>
      <option value="25" selected>25</option>
      <option value="50">50</option>
      <option value="100">100</option>
      <option value="0">Tout</option>
    </select>
    <span class="text-muted">lignes par page</span>
  </div>
  <div id="ns-info" style="font-size:.8rem;color:#6b7280"></div>
</div>

<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-abz table-hover mb-0" style="font-size:.82rem" id="ns-table">
        <thead>
          <tr>
            <th style="width:34px">N°</th>
            <?php if (!$id_cl_ns): ?><th>Classe</th><?php endif; ?>
            <th>Compétence</th>
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
            <td class="fw-semibold"><?= h($r['code_comp'] . ' — ' . (($r['section'] === 'An' && !empty($r['competence_en'])) ? $r['competence_en'] : $r['competence'])) ?></td>
            <td>
              <span class="text-muted fst-italic" style="font-size:.75rem">Non assigné</span>
            </td>
            <td class="text-center">
              <span style="font-size:.75rem;color:#dc2626;font-weight:700">
                <?= $r['nb_eleves'] ?> élève<?= $r['nb_eleves'] > 1 ? 's' : '' ?>
              </span>
            </td>
            <td class="text-center">
              <a href="<?= APP_URL ?>/pages/notes/index.php?onglet=classe&classe_c=<?= $r['id_classe'] ?>&seq_c=<?= $id_seq_ns ?>" data-ajax-nav
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
  <div id="ns-pag-info" style="font-size:.8rem;color:#6b7280"></div>
  <nav><ul class="pagination pagination-sm mb-0" id="ns-pag"></ul></nav>
</div>

<script>
(function() {
  var rows = Array.from(document.querySelectorAll('#ns-table tbody tr'));
  var perSel = document.getElementById('ns-per-page');
  var info = document.getElementById('ns-pag-info');
  var pag = document.getElementById('ns-pag');
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
    info.textContent = total > 0 ? 'Affichage ' + start + ' à ' + end + ' sur ' + total + ' compétence(s)' : '';

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

</div><!-- /#notes-zone -->

<?php
if ($es_partiel) exit; // rien de plus dans une réponse AJAX partielle.
$ajax_zone_id = 'notes-zone'; // voir layout/footer.php — initAjaxZone() y est appelé après sa propre définition
require_once __DIR__ . '/../../layout/footer.php';
