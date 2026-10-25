<?php
// secondaire/technique/pages/notes/index.php — module Notes d'une école
// secondaire TECHNIQUE (etablissement.ordre_enseignement='technique'), porté
// d'ABZ_MBE/pages/notes/index.php : saisie par SÉQUENCE (note.id_seq), pas
// de compétences APC. On y arrive par secondaire/general/pages/notes/index.php
// (redirection selon l'ordre d'enseignement de l'école).
header('Cache-Control: no-store, no-cache, must-revalidate');
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'FONDATEUR', 'CENSEUR', 'ENSEIGNANT', 'SG']);
// SG : seulement s'il a des cours (17/10/2026) — contrôlé avant toute écriture (auto_activer_sequences)
if (role_connecte() === 'SG' && !sec_vue_enseignant()) { flash_set('erreur', 'Les notes sont réservées aux personnes qui ont des cours.'); rediriger('noyau/dashboard.php'); }
if (!est_technique()) rediriger('secondaire/general/pages/notes/index.php');

auto_activer_sequences();

$role        = role_connecte();
$is_admin    = in_array($role, ['ADMIN','PROVISEUR','FONDATEUR','CENSEUR'], true) || $role === 'MEMBRE_ASSOCIATION';
$is_ens      = sec_vue_enseignant();   // ENSEIGNANT, ou SG qui a des cours (17/10/2026)
$onglet      = $_GET['onglet'] ?? 'classe';
$annee_act   = get_annee_active();
$id_annee    = (int)($annee_act['id'] ?? 0);
$val_annee   = $annee_act['libelle'] ?? '';

// ── Séquence active ──────────────────────────────────────────────
$seq_active = db_one(
    "SELECT s.*, t.libelle AS trim_lib, a.libelle AS annee_lib
     FROM sequence s
     JOIN trimestre t ON t.id=s.id_trim
     JOIN annee_scolaire a ON a.id=t.id_annee
     WHERE s.active=1 LIMIT 1"
);

// ── Bloc total si aucune séquence active et non-admin ────────────
$seq_bloquee = empty($seq_active);

// ── Matricule enseignant connecté ─────────────────────────────────
$mat_ens = $is_ens ? get_matricule_ens_connecte() : null;

// ── Classes disponibles ───────────────────────────────────────────
if ($is_ens && $mat_ens) {
    // Uniquement les classes où l'enseignant dispense au moins une matière
    $classes = db_all(
        "SELECT DISTINCT c.* FROM classe c
         JOIN dispenser d ON d.IDClasses=c.id AND d.matricule_ens=? AND d.val_annee=?
         WHERE c.archivee=0 ORDER BY c.ordre, c.designation",
        [$mat_ens, $val_annee]
    );
} else {
    $classes = $id_annee
        ? db_all("SELECT c.* FROM classe c
                  JOIN inscription i ON i.id_classe=c.id AND i.id_annee=?
                  WHERE c.archivee=0 GROUP BY c.id ORDER BY c.ordre, c.designation", [$id_annee])
        : [];
}

// ── Listes séquences pour admin ───────────────────────────────────
$seqs = $is_admin
    ? db_all("SELECT s.*, t.libelle AS trim_lib
              FROM sequence s JOIN trimestre t ON t.id=s.id_trim
              WHERE t.id_annee=? ORDER BY t.ordre, s.ordre", [$id_annee])
    : ($seq_active ? [$seq_active] : []);
// Toutes sequences pour onglet copie (admin + enseignant)
$seqs_copie = $id_annee ? db_all("SELECT s.*, t.libelle AS trim_lib FROM sequence s JOIN trimestre t ON t.id=s.id_trim WHERE t.id_annee=? ORDER BY t.ordre, s.ordre", [$id_annee]) : [];

// ══════════════════════════════════════════════════════════════
//  ONGLET 1 — Saisie par classe
// ══════════════════════════════════════════════════════════════
$id_cl_c  = (int)($_GET['classe_c'] ?? 0);
$id_mat_c = (int)($_GET['mat_c']    ?? 0);
$id_seq_c = $is_admin ? (int)($_GET['seq_c'] ?? ($seq_active['id'] ?? 0)) : (int)($seq_active['id'] ?? 0);
$eleves_c = [];
$mats_c   = [];

if ($onglet === 'classe') {
    if ($id_cl_c && $id_seq_c) {
        // Matieres : filtrées pour enseignant
        if ($is_ens && $mat_ens) {
            $mats_c = db_all(
                "SELECT m.id, m.libelle, m.code, d.coef
                 FROM discipline d
                 JOIN matiere m ON m.id=d.id_mat
                 JOIN dispenser disp ON disp.id_mat=d.id_mat AND disp.IDClasses=d.IDClasses
                                    AND disp.matricule_ens=? AND disp.val_annee=?
                 WHERE d.IDClasses=?
                 ORDER BY d.ordre, m.libelle",
                [$mat_ens, $val_annee, $id_cl_c]
            );
        } else {
            $mats_c = db_all(
                "SELECT m.id, m.libelle, m.code, d.coef
                 FROM discipline d JOIN matiere m ON m.id=d.id_mat
                 WHERE d.IDClasses=? ORDER BY d.ordre, m.libelle",
                [$id_cl_c]
            );
        }
    }
    if ($id_cl_c && $id_mat_c && $id_seq_c) {
        $eleves_c = db_all(
            "SELECT e.id, e.nom, e.prenom, e.matricule, e.niu,
                    n.valeur AS note, n.id AS id_note
             FROM eleve e
             JOIN inscription i ON i.id_eleve=e.id AND i.id_annee=? AND i.id_classe=?
             LEFT JOIN note n ON n.id_eleve=e.id AND n.id_matiere=? AND n.id_seq=?
             WHERE e.statut='actif'
             ORDER BY e.nom, e.prenom",
            [$id_annee, $id_cl_c, $id_mat_c, $id_seq_c]
        );
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'save_classe') {
        if ($seq_bloquee && !$is_admin) {
            flash_set('erreur', 'Aucune évaluation active. Saisie impossible.');
            rediriger("secondaire/technique/pages/notes/index.php?onglet=classe");
        }
        csrf_verifier();
        $id_seq_p  = (int)post('id_seq');
        $id_mat_p  = (int)post('id_mat');
        $id_cl_p   = (int)post('id_cl');
        // Sécurité (même contrôle que le secondaire général) : un enseignant
        // n'écrit que dans SES classe/matière (dispenser) et seulement sur
        // la séquence active — l'interface ne propose rien d'autre, mais
        // rien ne l'empêchait côté serveur.
        if ($is_ens && (!$mat_ens || $id_seq_p !== (int)($seq_active['id'] ?? 0) || !db_val(
                "SELECT 1 FROM dispenser WHERE matricule_ens=? AND val_annee=? AND IDClasses=? AND id_mat=?",
                [$mat_ens, $val_annee, $id_cl_p, $id_mat_p]))) {
            flash_set('erreur', 'Accès non autorisé à cette classe/matière/évaluation.');
            rediriger('secondaire/technique/pages/notes/index.php?onglet=classe');
        }
        foreach ($_POST['notes'] ?? [] as $id_eleve => $val) {
            $id_eleve = (int)$id_eleve;
            $val = trim($val);
            if ($val === '') {
                db_exec("DELETE FROM note WHERE id_eleve=? AND id_matiere=? AND id_seq=?",
                        [$id_eleve, $id_mat_p, $id_seq_p]);
            } else {
                $val = min(20, max(0, (float)str_replace(',', '.', $val)));
                $exist = db_val("SELECT id FROM note WHERE id_eleve=? AND id_matiere=? AND id_seq=?",
                                [$id_eleve, $id_mat_p, $id_seq_p]);
                if ($exist) {
                    db_exec("UPDATE note SET valeur=? WHERE id=?", [$val, $exist]);
                } else {
                    db_exec("INSERT INTO note (id_eleve,id_matiere,id_seq,valeur) VALUES (?,?,?,?)",
                            [$id_eleve, $id_mat_p, $id_seq_p, $val]);
                }
            }
        }
        flash_set('succes', 'Notes enregistrées.');
        rediriger("secondaire/technique/pages/notes/index.php?onglet=classe&classe_c=$id_cl_p&mat_c=$id_mat_p&seq_c=$id_seq_c");
    }
}

// ══════════════════════════════════════════════════════════════
//  ONGLET 2 — Saisie par élève
// ══════════════════════════════════════════════════════════════
$id_cl_e  = (int)($_GET['classe_e']  ?? 0);
$id_eleve = (int)($_GET['eleve_e']   ?? 0);
$id_seq_e = $is_admin ? (int)($_GET['seq_e'] ?? ($seq_active['id'] ?? 0)) : (int)($seq_active['id'] ?? 0);
$eleves_e = [];
$mats_e   = [];

if ($onglet === 'eleve') {
    if ($id_cl_e) {
        $eleves_e = db_all(
            "SELECT e.* FROM eleve e
             JOIN inscription i ON i.id_eleve=e.id AND i.id_annee=? AND i.id_classe=?
             WHERE e.statut='actif' ORDER BY e.nom, e.prenom",
            [$id_annee, $id_cl_e]
        );
    }
    if ($id_cl_e && $id_eleve && $id_seq_e) {
        if ($is_ens && $mat_ens) {
            $mats_e = db_all(
                "SELECT m.id, m.libelle, m.code, d.coef,
                        n.valeur AS note, n.id AS id_note
                 FROM discipline d
                 JOIN matiere m ON m.id=d.id_mat
                 JOIN dispenser disp ON disp.id_mat=d.id_mat AND disp.IDClasses=d.IDClasses
                                    AND disp.matricule_ens=? AND disp.val_annee=?
                 LEFT JOIN note n ON n.id_matiere=m.id AND n.id_eleve=? AND n.id_seq=?
                 WHERE d.IDClasses=?
                 ORDER BY d.ordre, m.libelle",
                [$mat_ens, $val_annee, $id_eleve, $id_seq_e, $id_cl_e]
            );
        } else {
            $mats_e = db_all(
                "SELECT m.id, m.libelle, m.code, d.coef,
                        n.valeur AS note, n.id AS id_note
                 FROM discipline d
                 JOIN matiere m ON m.id=d.id_mat
                 LEFT JOIN note n ON n.id_matiere=m.id AND n.id_eleve=? AND n.id_seq=?
                 WHERE d.IDClasses=?
                 ORDER BY d.ordre, m.libelle",
                [$id_eleve, $id_seq_e, $id_cl_e]
            );
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'save_eleve') {
        if ($seq_bloquee && !$is_admin) {
            flash_set('erreur', 'Aucune évaluation active. Saisie impossible.');
            rediriger("secondaire/technique/pages/notes/index.php?onglet=eleve");
        }
        csrf_verifier();
        $id_seq_p      = (int)post('id_seq');
        $id_cl_p       = (int)post('id_cl');
        $id_eleve_post = (int)post('id_eleve');
        if ($is_ens && (!$mat_ens || $id_seq_p !== (int)($seq_active['id'] ?? 0))) {
            flash_set('erreur', 'Saisie possible uniquement sur l\'évaluation active.');
            rediriger('secondaire/technique/pages/notes/index.php?onglet=eleve');
        }
        foreach ($_POST['notes'] ?? [] as $id_mat => $val) {
            $id_mat = (int)$id_mat;
            // Matière non affectée à l'enseignant connecté : ignorée (le
            // formulaire, construit depuis dispenser, ne la propose jamais).
            if ($is_ens && !db_val(
                    "SELECT 1 FROM dispenser WHERE matricule_ens=? AND val_annee=? AND IDClasses=? AND id_mat=?",
                    [$mat_ens, $val_annee, $id_cl_p, $id_mat])) continue;
            $val    = trim($val);
            if ($val === '') {
                db_exec("DELETE FROM note WHERE id_eleve=? AND id_matiere=? AND id_seq=?",
                        [$id_eleve_post, $id_mat, $id_seq_p]);
            } else {
                $val = min(20, max(0, (float)str_replace(',', '.', $val)));
                $exist = db_val("SELECT id FROM note WHERE id_eleve=? AND id_matiere=? AND id_seq=?",
                                [$id_eleve_post, $id_mat, $id_seq_p]);
                if ($exist) {
                    db_exec("UPDATE note SET valeur=? WHERE id=?", [$val, $exist]);
                } else {
                    db_exec("INSERT INTO note (id_eleve,id_matiere,id_seq,valeur) VALUES (?,?,?,?)",
                            [$id_eleve_post, $id_mat, $id_seq_p, $val]);
                }
            }
        }
        flash_set('succes', 'Notes de l\'élève enregistrées.');
        rediriger("secondaire/technique/pages/notes/index.php?onglet=eleve&classe_e=$id_cl_p&eleve_e=$id_eleve_post&seq_e=$id_seq_e");
    }
}

// ══════════════════════════════════════════════════════════════
//  ONGLET 3 — Copie de notes (ADMIN seulement)
// ══════════════════════════════════════════════════════════════
$id_cl_cop  = (int)($_GET['classe_cop'] ?? 0);
$id_seq_src = (int)($_GET['seq_src']    ?? 0);
$id_seq_dst = (int)($_GET['seq_dst']    ?? 0);
$id_mat_cop = (int)($_GET['mat_cop']    ?? 0);
$ajust      = (float)str_replace(',', '.', $_GET['ajust'] ?? '0');
$preview    = [];
$mats_cop   = [];

if ($onglet === 'copie' && ($is_admin || $is_ens)) {
    if ($id_cl_cop) {
        if ($is_ens && $mat_ens) {
            // Enseignant : uniquement ses matières dans cette classe
            $mats_cop = db_all(
                "SELECT m.id, m.libelle FROM discipline d
                 JOIN matiere m ON m.id=d.id_mat
                 JOIN dispenser disp ON disp.id_mat=d.id_mat AND disp.IDClasses=d.IDClasses
                                    AND disp.matricule_ens=? AND disp.val_annee=?
                 WHERE d.IDClasses=? ORDER BY d.ordre, m.libelle",
                [$mat_ens, $val_annee, $id_cl_cop]
            );
        } else {
            $mats_cop = db_all(
                "SELECT m.id, m.libelle FROM discipline d JOIN matiere m ON m.id=d.id_mat
                 WHERE d.IDClasses=? ORDER BY d.ordre, m.libelle", [$id_cl_cop]
            );
        }
    }
    if ($id_cl_cop && $id_seq_src && $id_mat_cop) {
        $preview = db_all(
            "SELECT e.id, e.nom, e.prenom, e.matricule,
                    n.valeur AS note_src,
                    LEAST(20, GREATEST(0, IF(n.valeur IS NOT NULL, n.valeur + ?, NULL))) AS note_dst
             FROM eleve e
             JOIN inscription i ON i.id_eleve=e.id AND i.id_annee=? AND i.id_classe=?
             LEFT JOIN note n ON n.id_eleve=e.id AND n.id_matiere=? AND n.id_seq=?
             WHERE e.statut='actif'
             ORDER BY e.nom, e.prenom",
            [$ajust, $id_annee, $id_cl_cop, $id_mat_cop, $id_seq_src]
        );
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'exec_copie') {
        csrf_verifier();
        $id_cl_p  = (int)post('id_cl');
        $id_src_p = (int)post('id_seq_src');
        $id_dst_p = (int)post('id_seq_dst');
        $id_mat_p = (int)post('id_mat');
        $ajust_p  = (float)str_replace(',', '.', post('ajust') ?? '0');
        // Sécurité : enseignant ne peut copier que ses propres matières
        if ($is_ens && $mat_ens) {
            $autorise = db_val(
                "SELECT COUNT(*) FROM dispenser
                 WHERE id_mat=? AND IDClasses=? AND matricule_ens=? AND val_annee=?",
                [$id_mat_p, $id_cl_p, $mat_ens, $val_annee]
            );
            if (!$autorise) {
                flash_set('erreur', 'Accès refusé à cette matière.');
                rediriger("secondaire/technique/pages/notes/index.php?onglet=copie");
            }
        }
        $rows = db_all(
            "SELECT e.id AS id_eleve, n.valeur
             FROM eleve e
             JOIN inscription i ON i.id_eleve=e.id AND i.id_annee=? AND i.id_classe=?
             LEFT JOIN note n ON n.id_eleve=e.id AND n.id_matiere=? AND n.id_seq=?
             WHERE e.statut='actif'", [$id_annee, $id_cl_p, $id_mat_p, $id_src_p]
        );
        $nb = 0;
        foreach ($rows as $r) {
            if ($r['valeur'] === null) continue;
            $new_val = min(20, max(0, (float)$r['valeur'] + $ajust_p));
            $exist = db_val("SELECT id FROM note WHERE id_eleve=? AND id_matiere=? AND id_seq=?",
                            [$r['id_eleve'], $id_mat_p, $id_dst_p]);
            if ($exist) db_exec("UPDATE note SET valeur=? WHERE id=?", [$new_val, $exist]);
            else db_exec("INSERT INTO note (id_eleve,id_matiere,id_seq,valeur) VALUES (?,?,?,?)",
                         [$r['id_eleve'], $id_mat_p, $id_dst_p, $new_val]);
            $nb++;
        }
        flash_set('succes', "$nb note(s) copiée(s).");
        rediriger("secondaire/technique/pages/notes/index.php?onglet=copie&classe_cop=$id_cl_p&seq_src=$id_src_p&seq_dst=$id_dst_p&mat_cop=$id_mat_p");
    }
}

// ══════════════════════════════════════════════════════════════
//  ONGLET 4 — Matières non saisies (séquence active)
// ══════════════════════════════════════════════════════════════
$id_cl_ns  = (int)($_GET['classe_ns'] ?? 0);
$non_saisis = [];

// Classes où l'enseignant est principal (toutes les matières visibles)
$classes_principal = [];
if ($is_ens && $mat_ens) {
    $classes_principal = array_column(
        db_all("SELECT IDClasses FROM enseignat_principal WHERE matricule_ens=? AND val_annee=?",
               [$mat_ens, $val_annee]),
        'IDClasses'
    );
}

if ($onglet === 'non_saisis' && $seq_active) {
    $id_seq_ns  = (int)$seq_active['id'];
    $where_cl_a = $id_cl_ns ? "AND d.IDClasses = " . (int)$id_cl_ns : '';

    try {
    if ($is_admin) {
        $non_saisis = db_all(
            "SELECT d.IDClasses AS id_classe, c.designation AS classe,
                    m.libelle AS matiere, d.coef,
                    CONCAT(e.nom_ens,' ',COALESCE(e.prenom_ens,'')) AS enseignant,
                    (SELECT COUNT(*) FROM inscription ii WHERE ii.id_classe=d.IDClasses AND ii.id_annee=?) AS nb_eleves,
                    (SELECT COUNT(*) FROM note nn
                     JOIN inscription ii2 ON ii2.id_eleve=nn.id_eleve AND ii2.id_classe=d.IDClasses AND ii2.id_annee=?
                     WHERE nn.id_matiere=d.id_mat AND nn.id_seq=?) AS nb_saisis
             FROM discipline d
             JOIN classe c ON c.id=d.IDClasses AND c.archivee=0
             JOIN matiere m ON m.id=d.id_mat
             LEFT JOIN dispenser disp ON disp.id_mat=d.id_mat AND disp.IDClasses=d.IDClasses AND disp.val_annee=?
             LEFT JOIN enseignant e ON e.matricule_ens=disp.matricule_ens
             WHERE 1=1 $where_cl_a
             HAVING nb_eleves > 0 AND nb_saisis = 0
             ORDER BY c.ordre, c.designation, d.ordre, m.libelle",
            [$id_annee, $id_annee, $id_seq_ns, $val_annee]
        );
    } elseif ($is_ens && $mat_ens) {
        $is_pp_cl = $id_cl_ns && in_array($id_cl_ns, $classes_principal);
        if ($is_pp_cl) {
            // Prof principal : toutes les matières de sa classe
            $non_saisis = db_all(
                "SELECT d.IDClasses AS id_classe, c.designation AS classe,
                        m.libelle AS matiere, d.coef,
                        CONCAT(e.nom_ens,' ',COALESCE(e.prenom_ens,'')) AS enseignant,
                        (SELECT COUNT(*) FROM inscription ii WHERE ii.id_classe=d.IDClasses AND ii.id_annee=?) AS nb_eleves,
                        (SELECT COUNT(*) FROM note nn
                         JOIN inscription ii2 ON ii2.id_eleve=nn.id_eleve AND ii2.id_classe=d.IDClasses AND ii2.id_annee=?
                         WHERE nn.id_matiere=d.id_mat AND nn.id_seq=?) AS nb_saisis
                 FROM discipline d
                 JOIN classe c ON c.id=d.IDClasses AND c.archivee=0
                 JOIN matiere m ON m.id=d.id_mat
                 LEFT JOIN dispenser disp ON disp.id_mat=d.id_mat AND disp.IDClasses=d.IDClasses AND disp.val_annee=?
                 LEFT JOIN enseignant e ON e.matricule_ens=disp.matricule_ens
                 WHERE d.IDClasses=?
                 HAVING nb_eleves > 0 AND nb_saisis = 0
                 ORDER BY d.ordre, m.libelle",
                [$id_annee, $id_annee, $id_seq_ns, $val_annee, $id_cl_ns]
            );
        } else {
            // Enseignant normal : uniquement ses matières
            $non_saisis = db_all(
                "SELECT d.IDClasses AS id_classe, c.designation AS classe,
                        m.libelle AS matiere, d.coef,
                        CONCAT(e.nom_ens,' ',COALESCE(e.prenom_ens,'')) AS enseignant,
                        (SELECT COUNT(*) FROM inscription ii WHERE ii.id_classe=d.IDClasses AND ii.id_annee=?) AS nb_eleves,
                        (SELECT COUNT(*) FROM note nn
                         JOIN inscription ii2 ON ii2.id_eleve=nn.id_eleve AND ii2.id_classe=d.IDClasses AND ii2.id_annee=?
                         WHERE nn.id_matiere=d.id_mat AND nn.id_seq=?) AS nb_saisis
                 FROM discipline d
                 JOIN classe c ON c.id=d.IDClasses AND c.archivee=0
                 JOIN matiere m ON m.id=d.id_mat
                 JOIN dispenser disp ON disp.id_mat=d.id_mat AND disp.IDClasses=d.IDClasses
                                    AND disp.matricule_ens=? AND disp.val_annee=?
                 LEFT JOIN enseignant e ON e.matricule_ens=disp.matricule_ens
                 WHERE 1=1 $where_cl_a
                 HAVING nb_eleves > 0 AND nb_saisis = 0
                 ORDER BY c.ordre, c.designation, d.ordre, m.libelle",
                [$id_annee, $id_annee, $id_seq_ns, $mat_ens, $val_annee]
            );
        }
    }
    } catch (\Throwable $e) {
        error_log('[non_saisis] '.$e->getMessage());
        die('<pre style="color:red;padding:1rem">Erreur non_saisis : '.htmlspecialchars($e->getMessage()).'</pre>');
    }
}

// ══════════════════════════════════════════════════════════════
//  ONGLET 5 — Relevé de notes (24/10/2026) : VIERGE (formulaire à remplir à la
//  main) ou REMPLI (notes saisies d'une matière, une colonne par évaluation).
// ══════════════════════════════════════════════════════════════
$id_cl_rv    = (int)($_GET['classe_rv'] ?? 0);
$mode_rv     = ($_GET['mode_rv'] ?? '') === 'rempli' ? 'rempli' : 'vierge';
$id_mat_rv   = (int)($_GET['mat_rv'] ?? 0);
$matieres_rv = [];
if ($onglet === 'releve_vierge' && $id_cl_rv) {
    // Matières de la classe ; l'enseignant ne voit que celles qu'il enseigne.
    $matieres_rv = $is_admin
        ? db_all("SELECT m.id, m.libelle FROM discipline d JOIN matiere m ON m.id=d.id_mat WHERE d.IDClasses=? ORDER BY d.ordre, m.libelle", [$id_cl_rv])
        : db_all("SELECT m.id, m.libelle FROM discipline d JOIN matiere m ON m.id=d.id_mat
                  JOIN dispenser p ON p.IDClasses=d.IDClasses AND p.id_mat=d.id_mat AND p.val_annee=? AND p.matricule_ens=?
                  WHERE d.IDClasses=? ORDER BY d.ordre, m.libelle", [$val_annee, (int) $mat_ens, $id_cl_rv]);
    if ($id_mat_rv && !in_array($id_mat_rv, array_map('intval', array_column($matieres_rv, 'id')), true)) $id_mat_rv = 0;
}
if ($mode_rv === 'rempli' && !$id_mat_rv) $mode_rv = 'vierge';   // rempli : une matière précise

// Mode "partiel" (AJAX) : réponse limitée au contenu de #notes-zone, sans
// noyau/layout/header.php ni noyau/layout/footer.php — voir initAjaxZone()/chargerPartiel()
// dans noyau/layout/footer.php. Changement d'onglet et sélecteurs classe/matière/
// élève/évaluation passent par ce mécanisme — jamais les formulaires POST
// (enregistrement des notes, copie), qui continuent de recharger normalement
// après leur redirection.
$es_partiel = isset($_GET['partiel']);

$titre_page = 'Notes';
if (!$es_partiel) {
    require_once __DIR__ . '/../../../../noyau/layout/header.php';
} else {
    header('Content-Type: text/html; charset=utf-8');
}
?>

<div id="notes-zone">

<div class="page-titre d-flex align-items-center justify-content-between">
  <h4><i class="bi bi-pencil-square me-1 text-primary"></i>Gestion des notes</h4>
  <?php if ($seq_active): ?>
    <span class="badge" style="background:#dbeafe;color:#1e40af;font-size:.75rem;padding:5px 10px;border-radius:8px">
      <i class="bi bi-lightning-charge-fill me-1 text-warning"></i>
      <?= h($seq_active['trim_lib'].' — '.$seq_active['libelle']) ?>
      &nbsp;|&nbsp; <?= h($seq_active['annee_lib'] ?? '') ?>
    </span>
  <?php else: ?>
    <span class="badge bg-danger" style="font-size:.75rem;padding:5px 10px">
      <i class="bi bi-lock-fill me-1"></i>Aucune évaluation active
    </span>
  <?php endif; ?>
</div>

<?php if ($seq_bloquee && !$is_admin): ?>
<!-- ── Blocage séquence ────────────────────────────────────────── -->
<div class="alert alert-danger d-flex align-items-center gap-3 py-3" style="border-left:4px solid #dc2626">
  <i class="bi bi-lock-fill fs-3 text-danger"></i>
  <div>
    <div class="fw-bold">Saisie des notes désactivée</div>
    <div style="font-size:.82rem">Aucune évaluation n'est active en ce moment. Contactez l'administration pour activer une évaluation.</div>
  </div>
</div>
<?php else: ?>

<!-- Onglets v2 -->
<ul class="nav nav-tabs mb-3" style="border-bottom:2px solid #e5e7eb">
  <li class="nav-item">
    <a class="nav-link <?= $onglet==='classe'?'active':'' ?>" data-ajax-nav
       href="<?= APP_URL ?>/secondaire/technique/pages/notes/index.php?onglet=classe<?= $id_cl_c?"&classe_c=$id_cl_c":'' ?>">
      <i class="bi bi-people me-1"></i>Saisie par classe
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $onglet==='eleve'?'active':'' ?>" data-ajax-nav
       href="<?= APP_URL ?>/secondaire/technique/pages/notes/index.php?onglet=eleve<?= $id_cl_e?"&classe_e=$id_cl_e":'' ?><?= $id_eleve?"&eleve_e=$id_eleve":'' ?>">
      <i class="bi bi-person me-1"></i>Saisie par élève
    </a>
  </li>
  <?php if ($is_admin || $is_ens): ?>
  <li class="nav-item">
    <a class="nav-link <?= $onglet==='copie'?'active':'' ?>" data-ajax-nav
       href="<?= APP_URL ?>/secondaire/technique/pages/notes/index.php?onglet=copie<?= $id_cl_cop?"&classe_cop=$id_cl_cop":'' ?>">
      <i class="bi bi-copy me-1"></i>Copie de notes
    </a>
  </li>
  <?php endif; ?>
  <li class="nav-item">
    <a class="nav-link <?= $onglet==='non_saisis'?'active':'' ?>" data-ajax-nav
       href="<?= APP_URL ?>/secondaire/technique/pages/notes/index.php?onglet=non_saisis">
      <i class="bi bi-exclamation-triangle me-1"></i>Non saisies
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $onglet==='releve_vierge'?'active':'' ?>" data-ajax-nav
       href="<?= APP_URL ?>/secondaire/technique/pages/notes/index.php?onglet=releve_vierge<?= $id_cl_rv?"&classe_rv=$id_cl_rv":'' ?>">
      <i class="bi bi-file-earmark-spreadsheet me-1"></i>Relevé de notes
    </a>
  </li>
</ul>


<?php if ($onglet === 'classe'): ?>
<!-- ══════════════════════════════════════════════════
     ONGLET 1 — Saisie par classe
══════════════════════════════════════════════════ -->

<!-- Bandeau éval -->
<?php if ($seq_active): ?>
<div class="card mb-3" style="border-left:4px solid #2563eb;background:#eff6ff">
  <div class="card-body py-2 d-flex align-items-center gap-3">
    <div>
      <span style="font-size:.7rem;color:#6b7280;font-weight:600;text-transform:uppercase">Évaluation</span><br>
      <span class="fw-bold" style="color:#1e40af"><?= h($seq_active['trim_lib'].' — '.$seq_active['libelle']) ?></span>
    </div>
    <?php if ($is_admin && count($seqs) > 1): ?>
    <div class="ms-auto">
      <form method="get" class="d-flex align-items-center gap-2" data-ajax-nav-form>
        <input type="hidden" name="onglet"    value="classe">
        <input type="hidden" name="classe_c"  value="<?= $id_cl_c ?>">
        <input type="hidden" name="mat_c"     value="<?= $id_mat_c ?>">
        <label class="text-muted" style="font-size:.72rem;white-space:nowrap">Changer :</label>
        <select name="seq_c" class="form-select form-select-sm" style="width:auto" data-ajax-nav-auto>
          <?php foreach ($seqs as $s): ?>
            <option value="<?= $s['id'] ?>" <?= $id_seq_c==$s['id']?'selected':''?>>
              <?= h($s['trim_lib'].' — '.$s['libelle']) ?><?= $s['active']?' ★':'' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="card mb-3">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end" data-ajax-nav-form>
      <input type="hidden" name="onglet" value="classe">
      <?php if ($is_admin): ?><input type="hidden" name="seq_c" value="<?= $id_seq_c ?>"><?php endif; ?>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Classe</label>
        <select name="classe_c" class="form-select" data-ajax-nav-auto>
          <option value="">— Choisir —</option>
          <?php foreach ($classes as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $id_cl_c==$c['id']?'selected':''?>>
              <?= h($c['designation']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($id_cl_c && $mats_c): ?>
      <div class="col-md-5">
        <label class="form-label fw-semibold">Matière</label>
        <select name="mat_c" class="form-select" data-ajax-nav-auto>
          <option value="">— Choisir —</option>
          <?php foreach ($mats_c as $m): ?>
            <option value="<?= $m['id'] ?>" <?= $id_mat_c==$m['id']?'selected':''?>>
              <?= h($m['libelle']) ?> (coef <?= $m['coef'] ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
    </form>
  </div>
</div>

<?php if ($id_cl_c && $id_mat_c && !empty($eleves_c)):
  $mat_info = null;
  foreach ($mats_c as $m) { if ($m['id'] == $id_mat_c) { $mat_info = $m; break; } }
  $saisi = count(array_filter($eleves_c, fn($e) => $e['note'] !== null));
?>
<div class="d-flex align-items-center gap-2 mb-2">
  <span class="fw-bold" style="font-size:.88rem"><?= h($mat_info['libelle'] ?? '') ?></span>
  <span style="background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:10px;font-size:.72rem;font-weight:600">Coef <?= $mat_info['coef'] ?? 1 ?></span>
  <span style="background:#f3f4f6;color:#6b7280;padding:2px 8px;border-radius:10px;font-size:.72rem">
    <?= $saisi ?>/<?= count($eleves_c) ?> note(s)
  </span>
</div>

<div class="card">
  <div class="card-body p-0">
    <form method="post">
      <?= csrf_champ() ?>
      <input type="hidden" name="action" value="save_classe">
      <input type="hidden" name="id_seq" value="<?= $id_seq_c ?>">
      <input type="hidden" name="id_mat" value="<?= $id_mat_c ?>">
      <input type="hidden" name="id_cl"  value="<?= $id_cl_c ?>">
      <table class="table table-abz table-hover mb-0" id="tbl-notes">
        <thead>
          <tr>
            <th style="width:40px">N°</th>
            <th>Nom et Prénom</th>
            <th style="width:100px">NIU</th>
            <th style="width:120px" class="text-center">Note /20</th>
            <th style="width:60px" class="text-center">Cote</th>
            <th style="width:50px" class="text-center">
              <button type="button" class="btn btn-link btn-sm p-0" onclick="viderTout()" title="Vider tout">
                <i class="bi bi-eraser" style="font-size:.8rem"></i>
              </button>
            </th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($eleves_c as $i => $el):
            $appr = appreciation($el['note']);
          ?>
          <tr>
            <td class="text-muted"><?= $i+1 ?></td>
            <td class="fw-semibold"><?= h(strtoupper($el['nom']).' '.($el['prenom']??'')) ?></td>
            <td class="text-muted" style="font-size:.78rem"><?= h(niu_eleve($el) ?: '—') ?></td>
            <td>
              <input type="number" name="notes[<?= $el['id'] ?>]"
                     class="form-control form-control-sm note-inp text-center"
                     min="0" max="20" step="0.25"
                     value="<?= $el['note'] !== null ? $el['note'] : '' ?>"
                     placeholder="—">
            </td>
            <td class="text-center cote-cl" style="font-size:.78rem;font-weight:700;color:<?= appr_color($appr['COTE']) ?>">
              <?= h($appr['COTE']) ?: '—' ?>
            </td>
            <td class="text-center">
              <?php if ($el['note'] !== null): ?>
                <i class="bi bi-check-circle-fill text-success" style="font-size:.8rem"></i>
              <?php else: ?>
                <i class="bi bi-dash-circle text-muted" style="font-size:.8rem"></i>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <div class="card-body py-2 border-top d-flex align-items-center gap-2">
        <button class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Enregistrer</button>
        <input type="number" id="valeur-quick" class="form-control form-control-sm" style="width:80px"
               min="0" max="20" step="0.25" placeholder="Valeur">
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="remplirTout()">
          <i class="bi bi-arrow-down me-1"></i>Appliquer à tous
        </button>
        <span class="ms-auto text-muted" style="font-size:.75rem">Entrée / Tab pour naviguer</span>
      </div>
    </form>
  </div>
</div>

<?php elseif ($id_cl_c && $id_mat_c): ?>
  <div class="alert alert-info py-2"><i class="bi bi-info-circle me-1"></i>Aucun élève actif dans cette classe.</div>
<?php elseif ($id_cl_c && empty($mats_c)): ?>
  <div class="alert alert-warning py-2"><i class="bi bi-exclamation me-1"></i>Aucune matière affectée pour vous dans cette classe.</div>
<?php elseif ($id_cl_c): ?>
  <div class="alert alert-light text-muted py-3 text-center">Sélectionnez une matière.</div>
<?php else: ?>
  <div class="alert alert-light text-muted py-4 text-center">
    <i class="bi bi-arrow-up" style="font-size:2rem;display:block;opacity:.2;margin-bottom:.4rem"></i>
    Sélectionnez une classe pour commencer.
  </div>
<?php endif; ?>

<script>
function viderTout() {
    document.querySelectorAll('.note-inp').forEach(function(i){ i.value=''; });
    document.querySelectorAll('.cote-cl').forEach(function(c){ c.textContent='—'; c.style.color='#374151'; });
}
function remplirTout() {
    var val = document.getElementById('valeur-quick').value;
    if (val === '') return;
    val = Math.min(20, Math.max(0, parseFloat(val)));
    document.querySelectorAll('.note-inp').forEach(function(i){ i.value = val; majCote(i); });
}
var COTE_MAP = [
    [18, 'A+','#15803d'],[16,'A','#15803d'],[15,'B+','#1d4ed8'],[14,'B','#1d4ed8'],
    [12,'C+','#ca8a04'],[10,'C','#d97706'],[0,'D','#dc2626']
];
function getCote(v) {
    for (var i=0;i<COTE_MAP.length;i++) { if (v>=COTE_MAP[i][0]) return COTE_MAP[i]; }
    return [0,'D','#dc2626'];
}
function majCote(inp) {
    var v = parseFloat(inp.value);
    var coteCell = inp.closest('tr').querySelector('.cote-cl');
    if (!coteCell) return;
    if (isNaN(v) || inp.value==='') { coteCell.textContent='—'; coteCell.style.color='#374151'; return; }
    var c = getCote(v);
    coteCell.textContent = c[1]; coteCell.style.color = c[2];
}
document.addEventListener('DOMContentLoaded', function(){
    var inps = Array.from(document.querySelectorAll('.note-inp'));
    inps.forEach(function(inp, idx){
        inp.addEventListener('input', function(){ majCote(this); });
        inp.addEventListener('keydown', function(e){
            if (e.key==='Enter') { e.preventDefault(); if (inps[idx+1]) inps[idx+1].focus(); }
        });
        inp.addEventListener('change', function(){
            var v = parseFloat(this.value);
            if (!isNaN(v)) this.value = Math.min(20, Math.max(0, Math.round(v*4)/4));
            majCote(this);
        });
    });
});
</script>


<?php elseif ($onglet === 'eleve'): ?>
<!-- ══════════════════════════════════════════════════
     ONGLET 2 — Saisie par élève
══════════════════════════════════════════════════ -->

<div class="card mb-3">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end" data-ajax-nav-form>
      <input type="hidden" name="onglet" value="eleve">
      <?php if (!$is_admin): ?><input type="hidden" name="seq_e" value="<?= $id_seq_e ?>"><?php endif; ?>
      <div class="col-md-3">
        <label class="form-label fw-semibold">Classe</label>
        <select name="classe_e" class="form-select" data-ajax-nav-auto>
          <option value="">— Choisir —</option>
          <?php foreach ($classes as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $id_cl_e==$c['id']?'selected':''?>>
              <?= h($c['designation']) ?>
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
            <option value="<?= $el['id'] ?>" <?= $id_eleve==$el['id']?'selected':''?>>
              <?= h(strtoupper($el['nom']).' '.($el['prenom']??'')) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <?php if ($id_eleve && $is_admin && count($seqs) > 1): ?>
      <div class="col-md-3">
        <label class="form-label fw-semibold">Évaluation</label>
        <select name="seq_e" class="form-select" data-ajax-nav-auto>
          <?php foreach ($seqs as $s): ?>
            <option value="<?= $s['id'] ?>" <?= $id_seq_e==$s['id']?'selected':''?>>
              <?= h($s['trim_lib'].' — '.$s['libelle']) ?><?= $s['active']?' ★':'' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php elseif ($id_eleve && !$is_admin && $seq_active): ?>
        <input type="hidden" name="seq_e" value="<?= $seq_active['id'] ?>">
      <?php endif; ?>
      <?php if ($id_cl_e): ?><input type="hidden" name="classe_e" value="<?= $id_cl_e ?>"><?php endif; ?>
    </form>
  </div>
</div>

<?php if ($id_cl_e && $id_eleve && $id_seq_e && !empty($mats_e)):
  $eleve_info = db_one("SELECT * FROM eleve WHERE id=?", [$id_eleve]);
  $saisi_e    = count(array_filter($mats_e, fn($m) => $m['note'] !== null));
  $seq_lbl    = $seq_active ? h($seq_active['trim_lib'].' — '.$seq_active['libelle']) : '';
  if ($is_admin) {
      foreach ($seqs as $s) { if ($s['id'] == $id_seq_e) { $seq_lbl = h($s['trim_lib'].' — '.$s['libelle']); break; } }
  }
?>

<div class="card mb-3" style="border-left:4px solid #7c3aed;background:#faf5ff">
  <div class="card-body py-2 d-flex align-items-center gap-3">
    <div style="width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,#7c3aed,#a78bfa);
                display:flex;align-items:center;justify-content:center;flex-shrink:0">
      <i class="bi bi-person-fill" style="color:#fff;font-size:1.1rem"></i>
    </div>
    <div>
      <div class="fw-bold" style="font-size:.9rem">
        <?= h(strtoupper($eleve_info['nom']).' '.($eleve_info['prenom']??'')) ?>
      </div>
      <div style="font-size:.72rem;color:#6b7280">
        NIU <?= h(niu_eleve($eleve_info) ?: '—') ?> &nbsp;|&nbsp;
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
      <input type="hidden" name="action"   value="save_eleve">
      <input type="hidden" name="id_seq"   value="<?= $id_seq_e ?>">
      <input type="hidden" name="id_cl"    value="<?= $id_cl_e ?>">
      <input type="hidden" name="id_eleve" value="<?= $id_eleve ?>">
      <table class="table table-abz table-hover mb-0">
        <thead>
          <tr>
            <th>Matière</th>
            <th style="width:55px;text-align:center">Coef</th>
            <th style="width:110px;text-align:center">Note /20</th>
            <th style="width:70px;text-align:center">Points</th>
            <th style="width:52px;text-align:center">Cote</th>
            <th>Appréciation</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($mats_e as $m):
            $pts  = ($m['note'] !== null) ? round((float)$m['note'] * $m['coef'], 2) : null;
            $appr = appreciation($m['note']);
          ?>
          <tr>
            <td class="fw-semibold"><?= h($m['libelle']) ?></td>
            <td class="text-center">
              <span style="background:#dbeafe;color:#1e40af;font-size:.7rem;padding:2px 7px;border-radius:8px;font-weight:700">
                <?= $m['coef'] ?>
              </span>
            </td>
            <td>
              <input type="number" name="notes[<?= $m['id'] ?>]"
                     class="form-control form-control-sm eleve-note text-center"
                     min="0" max="20" step="0.25"
                     value="<?= $m['note'] !== null ? $m['note'] : '' ?>"
                     placeholder="—"
                     data-coef="<?= $m['coef'] ?>">
            </td>
            <td class="text-center pts-cell" style="font-size:.82rem;color:#374151"><?= $pts ?? '—' ?></td>
            <td class="text-center cote-cell" style="font-size:.82rem;font-weight:700;color:<?= appr_color($appr['COTE']) ?>">
              <?= h($appr['COTE']) ?: '—' ?>
            </td>
            <td class="app-cell" style="font-size:.73rem"><?= appr_badge($appr) ?: '—' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr style="background:#eef2ff">
            <td colspan="2" class="text-end fw-bold" style="font-size:.82rem;color:#374151">Moyenne</td>
            <td class="text-center fw-bold" id="moy-cell" style="color:#1e40af">—</td>
            <td class="text-center fw-bold" id="pts-cell" style="color:#1e40af;font-size:.82rem">—</td>
            <td class="text-center fw-bold" id="cote-moy-cell">—</td>
            <td id="appr-moy-cell" style="font-size:.73rem">—</td>
          </tr>
        </tfoot>
      </table>
      <div class="card-body py-2 border-top">
        <button class="btn btn-primary btn-sm">
          <i class="bi bi-save me-1"></i>Enregistrer les notes
        </button>
      </div>
    </form>
  </div>
</div>

<script>
(function(){
    var COTE_COLORS = {'A+':'#15803d','A':'#15803d','B+':'#1d4ed8','B':'#1d4ed8','C+':'#ca8a04','C':'#d97706','D':'#dc2626'};
    function getAppr(v) {
        if (isNaN(v) || v < 0) return {cote:'',fr:'',abr:''};
        if (v < 10)  return {cote:'D',  fr:'Compétences non acquises',  abr:'CNA'};
        if (v < 12)  return {cote:'C',  fr:'Compétences moy. acquises', abr:'CMA'};
        if (v < 14)  return {cote:'C+', fr:'Compétences acquises',      abr:'CA'};
        if (v < 15)  return {cote:'B',  fr:'Compétences bien acquises', abr:'CBA'};
        if (v < 16)  return {cote:'B+', fr:'Compétences bien acquises', abr:'CBA'};
        if (v < 18)  return {cote:'A',  fr:'Compétences TB acquises',   abr:'CTBA'};
        return              {cote:'A+', fr:'Compétences TB acquises',   abr:'CTBA'};
    }
    function coteBadge(a) {
        if (!a.cote) return '—';
        return '<span style="color:'+(COTE_COLORS[a.cote]||'#374151')+';font-weight:700">'+a.cote+'</span>';
    }
    function apprBadge(a) {
        if (!a.fr) return '—';
        var col = COTE_COLORS[a.cote] || '#374151';
        return '<span style="color:'+col+'">'+a.fr+'</span>'
             + ' <span style="color:#9ca3af;font-size:.7rem">('+a.abr+')</span>';
    }

    var inps = Array.from(document.querySelectorAll('.eleve-note'));
    function recalc() {
        var tc=0, tp=0, has=false;
        inps.forEach(function(inp){
            var v = parseFloat(inp.value);
            var c = parseFloat(inp.dataset.coef);
            var row      = inp.closest('tr');
            var ptsCell  = row.querySelector('.pts-cell');
            var coteCell = row.querySelector('.cote-cell');
            var appCell  = row.querySelector('.app-cell');
            if (!isNaN(v) && inp.value !== '') {
                var pts = Math.round(v*c*100)/100;
                tc += c; tp += pts; has = true;
                var a = getAppr(v);
                if (ptsCell)  ptsCell.textContent  = pts;
                if (coteCell) { coteCell.innerHTML = coteBadge(a); coteCell.style.color = COTE_COLORS[a.cote]||'#374151'; }
                if (appCell)  appCell.innerHTML    = apprBadge(a);
            } else {
                if (ptsCell)  ptsCell.textContent = '—';
                if (coteCell) { coteCell.innerHTML = '—'; coteCell.style.color='#374151'; }
                if (appCell)  appCell.innerHTML   = '—';
            }
        });
        var moy = (has && tc>0) ? Math.round(tp/tc*100)/100 : null;
        document.getElementById('moy-cell').textContent = moy !== null ? moy.toFixed(2)+'/20' : '—';
        document.getElementById('pts-cell').textContent = has ? Math.round(tp*100)/100 : '—';
        if (moy !== null) {
            var am = getAppr(moy);
            document.getElementById('cote-moy-cell').innerHTML = coteBadge(am);
            document.getElementById('appr-moy-cell').innerHTML = apprBadge(am);
        } else {
            document.getElementById('cote-moy-cell').textContent = '—';
            document.getElementById('appr-moy-cell').textContent = '—';
        }
    }
    inps.forEach(function(inp, idx){
        inp.addEventListener('input', function(){
            var v = parseFloat(this.value);
            if (!isNaN(v)) this.value = Math.min(20, Math.max(0, this.value));
            recalc();
        });
        inp.addEventListener('change', function(){
            var v = parseFloat(this.value);
            if (!isNaN(v)) this.value = Math.min(20, Math.max(0, Math.round(v*4)/4));
            recalc();
        });
        inp.addEventListener('keydown', function(e){
            if (e.key==='Enter') { e.preventDefault(); if (inps[idx+1]) inps[idx+1].focus(); }
        });
    });
    recalc();
})();
</script>

<?php elseif ($id_cl_e && $id_eleve && empty($mats_e)): ?>
  <div class="alert alert-warning py-2">Aucune matière affectée pour vous dans cette classe.</div>
<?php elseif ($id_cl_e && !$id_eleve): ?>
  <div class="alert alert-light text-muted py-3 text-center">Sélectionnez un élève.</div>
<?php else: ?>
  <div class="alert alert-light text-muted py-4 text-center">
    <i class="bi bi-arrow-up" style="font-size:2rem;display:block;opacity:.2;margin-bottom:.4rem"></i>
    Sélectionnez une classe puis un élève.
  </div>
<?php endif; ?>


<?php elseif ($onglet === 'copie' && ($is_admin || $is_ens)): ?>
<!-- ══════════════════════════════════════════════════
     ONGLET 3 — Copie de notes (ADMIN)
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
              <?php foreach ($classes as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $id_cl_cop==$c['id']?'selected':''?>><?= h($c['designation']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php if ($id_cl_cop && $mats_cop): ?>
          <div class="col-md-6">
            <label class="form-label">Matière</label>
            <select name="mat_cop" class="form-select form-select-sm" data-ajax-nav-auto>
              <option value="">— Choisir —</option>
              <?php foreach ($mats_cop as $m): ?>
                <option value="<?= $m['id'] ?>" <?= $id_mat_cop==$m['id']?'selected':''?>><?= h($m['libelle']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-5">
            <label class="form-label">Évaluation source</label>
            <select name="seq_src" class="form-select form-select-sm" data-ajax-nav-auto>
              <option value="">— Source —</option>
              <?php foreach ($seqs_copie as $s): ?>
                <option value="<?= $s['id'] ?>" <?= $id_seq_src==$s['id']?'selected':''?>><?= h($s['trim_lib'].' — '.$s['libelle']) ?><?= $s['active']?' ★':'' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-5">
            <label class="form-label">Évaluation destination</label>
            <select name="seq_dst" class="form-select form-select-sm" data-ajax-nav-auto>
              <option value="">— Destination —</option>
              <?php foreach ($seqs_copie as $s): ?>
                <option value="<?= $s['id'] ?>" <?= $id_seq_dst==$s['id']?'selected':''?>><?= h($s['trim_lib'].' — '.$s['libelle']) ?><?= $s['active']?' ★':'' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label">Ajust. <i class="bi bi-info-circle text-muted" title="Points à ajouter (max final = 20)"></i></label>
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
          <li>Ajoutez un ajustement (±)</li>
          <li>Prévisualisez puis confirmez</li>
          <li><strong>Maximum : 20 — jamais dépassé</strong></li>
        </ul>
      </div>
    </div>
  </div>
</div>

<?php if ($id_cl_cop && $id_seq_src && $id_seq_dst && $id_mat_cop && !empty($preview)):
  $nb_src  = count(array_filter($preview, fn($r) => $r['note_src'] !== null));
  $src_inf = null; $dst_inf = null;
  foreach ($seqs_copie as $s) {
      if ($s['id'] == $id_seq_src) $src_inf = $s;
      if ($s['id'] == $id_seq_dst) $dst_inf = $s;
  }
  $mat_cop_lib = '';
  foreach ($mats_cop as $m) { if ($m['id'] == $id_mat_cop) { $mat_cop_lib = $m['libelle']; break; } }
?>
<div class="card mb-3" style="border:1px solid #d1fae5;background:#f0fdf4">
  <div class="card-body py-2 d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div style="font-size:.82rem;color:#065f46">
      <strong><?= h($mat_cop_lib) ?></strong> —
      <span class="badge" style="background:#fde68a;color:#92400e"><?= $src_inf ? h($src_inf['libelle']) : '' ?></span>
      <i class="bi bi-arrow-right mx-1"></i>
      <span class="badge" style="background:#a7f3d0;color:#065f46"><?= $dst_inf ? h($dst_inf['libelle']) : '' ?></span>
      <?php if ($ajust!=0): ?>
        <span class="badge ms-1" style="background:<?= $ajust>0?'#dbeafe':'#fee2e2' ?>;color:<?= $ajust>0?'#1e40af':'#991b1b' ?>">
          <?= $ajust>0?'+':'' ?><?= $ajust ?> pts
        </span>
      <?php endif; ?>
    </div>
    <form method="post">
      <?= csrf_champ() ?>
      <input type="hidden" name="action"     value="exec_copie">
      <input type="hidden" name="id_cl"      value="<?= $id_cl_cop ?>">
      <input type="hidden" name="id_seq_src" value="<?= $id_seq_src ?>">
      <input type="hidden" name="id_seq_dst" value="<?= $id_seq_dst ?>">
      <input type="hidden" name="id_mat"     value="<?= $id_mat_cop ?>">
      <input type="hidden" name="ajust"      value="<?= $ajust ?>">
      <button class="btn btn-success btn-sm"
              onclick="return confirm('Copier <?= $nb_src ?> note(s) ?')">
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
          <th style="text-align:center">Note source</th>
          <?php if ($ajust!=0): ?><th style="text-align:center">Ajust.</th><?php endif; ?>
          <th style="text-align:center">Note abz</th><th style="text-align:center">Info</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($preview as $i => $r):
          $capped = ($r['note_src']!==null) && (round((float)$r['note_src']+$ajust,4)>20.001);
          $no_note = ($r['note_src']===null);
        ?>
        <tr <?= $no_note?'style="opacity:.5"':'' ?>>
          <td class="text-muted"><?= $i+1 ?></td>
          <td class="fw-semibold"><?= h(strtoupper($r['nom']).' '.($r['prenom']??'')) ?></td>
          <td class="text-center"><span style="background:#f3f4f6;padding:2px 8px;border-radius:8px;font-weight:600"><?= $r['note_src']!==null ? rtrim(rtrim(number_format((float)$r['note_src'],2,'.',''),'0'),'.') : '—' ?></span></td>
          <?php if ($ajust!=0): ?><td class="text-center" style="color:<?= $ajust>0?'#15803d':'#dc2626' ?>"><?= $r['note_src']!==null?($ajust>0?'+':'').$ajust:'—' ?></td><?php endif; ?>
          <td class="text-center">
            <?php if ($r['note_dst']!==null): ?>
              <span style="background:<?= $capped?'#fef3c7':'#d1fae5' ?>;color:<?= $capped?'#92400e':'#065f46' ?>;padding:2px 8px;border-radius:8px;font-weight:700"><?= rtrim(rtrim(number_format((float)$r['note_dst'],2,'.',''),'0'),'.') ?></span>
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

<!-- Barre filtres + compteur -->
<div class="row g-2 mb-3 align-items-center">
  <div class="col-auto">
    <form method="get" class="d-flex align-items-center gap-2" data-ajax-nav-form>
      <input type="hidden" name="onglet" value="non_saisis">
      <label class="form-label mb-0" style="font-size:.8rem;white-space:nowrap">Classe</label>
      <select name="classe_ns" class="form-select form-select-sm" style="min-width:160px" data-ajax-nav-auto>
        <option value="">Toutes les classes</option>
        <?php foreach ($classes as $c): ?>
          <option value="<?= $c['id'] ?>" <?= $id_cl_ns==$c['id']?'selected':''?>><?= h($c['designation']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($id_cl_ns): ?>
        <a href="<?= APP_URL ?>/secondaire/technique/pages/notes/index.php?onglet=non_saisis" data-ajax-nav class="btn btn-outline-secondary btn-sm">
          <i class="bi bi-x"></i>
        </a>
      <?php endif; ?>
    </form>
  </div>
  <div class="col-auto ms-auto d-flex align-items-center gap-2">
    <span class="badge" style="background:#fee2e2;color:#991b1b;font-size:.78rem;padding:5px 10px;border-radius:8px">
      <i class="bi bi-exclamation-triangle-fill me-1"></i>
      <?= count($non_saisis) ?> matière(s) sans aucune note — <?= h($seq_active['libelle']) ?>
    </span>
  </div>
</div>

<?php if ($is_ens && !empty($classes_principal)): ?>
  <?php
  $noms_pp = [];
  foreach ($classes as $c) {
      if (in_array($c['id'], $classes_principal)) $noms_pp[] = $c['designation'];
  }
  ?>
  <div class="alert alert-info py-2 d-flex align-items-center gap-2 mb-3" style="font-size:.8rem">
    <i class="bi bi-star-fill text-warning"></i>
    Vous êtes professeur principal de : <strong><?= h(implode(', ', $noms_pp)) ?></strong> —
    toutes les matières de <?= $id_cl_ns && in_array($id_cl_ns, $classes_principal) ? 'cette classe sont' : 'ces classes sont' ?> visibles.
  </div>
<?php endif; ?>

<?php if (empty($non_saisis)): ?>
  <div class="alert alert-success d-flex align-items-center gap-2">
    <i class="bi bi-check-circle-fill fs-4"></i>
    <div>
      <div class="fw-bold">Toutes les notes sont saisies !</div>
      <div style="font-size:.82rem">Aucune matière sans note pour <?= $id_cl_ns ? 'cette classe' : 'toutes les classes' ?>.</div>
    </div>
  </div>
<?php else: ?>

<!-- Contrôles pagination (lignes/page + info) -->
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
            <th>Matière</th>
            <th style="width:55px;text-align:center">Coef</th>
            <th>Enseignant responsable</th>
            <th style="width:100px;text-align:center">Élèves</th>
            <th style="width:90px;text-align:center">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($non_saisis as $i => $r): ?>
          <tr>
            <td class="text-muted"><?= $i+1 ?></td>
            <?php if (!$id_cl_ns): ?>
            <td>
              <span style="background:#eff6ff;color:#1e40af;padding:2px 8px;border-radius:6px;font-size:.75rem;font-weight:600">
                <?= h($r['classe']) ?>
              </span>
            </td>
            <?php endif; ?>
            <td class="fw-semibold"><?= h($r['matiere']) ?></td>
            <td class="text-center">
              <span style="background:#f3f4f6;padding:2px 8px;border-radius:6px;font-weight:700">
                <?= h($r['coef']) ?>
              </span>
            </td>
            <td>
              <?php if ($r['enseignant'] && trim($r['enseignant'])): ?>
                <span style="display:flex;align-items:center;gap:5px">
                  <i class="bi bi-person-badge" style="color:#6b7280;font-size:.8rem"></i>
                  <?= h(trim($r['enseignant'])) ?>
                </span>
              <?php else: ?>
                <span class="text-muted fst-italic" style="font-size:.75rem">Non assigné</span>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <span style="font-size:.75rem;color:#dc2626;font-weight:700">
                <?= $r['nb_eleves'] ?> élève<?= $r['nb_eleves'] > 1 ? 's' : '' ?>
              </span>
            </td>
            <td class="text-center">
              <a href="<?= APP_URL ?>/secondaire/technique/pages/notes/index.php?onglet=classe&classe_c=<?= $r['id_classe'] ?>&seq_c=<?= $seq_active['id'] ?>" data-ajax-nav
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

<!-- Pagination -->
<div class="d-flex align-items-center justify-content-between mt-2 flex-wrap gap-2">
  <div id="ns-pag-info" style="font-size:.8rem;color:#6b7280"></div>
  <nav><ul class="pagination pagination-sm mb-0" id="ns-pag"></ul></nav>
</div>

<script>
(function(){
  var rows   = Array.from(document.querySelectorAll('#ns-table tbody tr'));
  var perSel = document.getElementById('ns-per-page');
  var info   = document.getElementById('ns-pag-info');
  var pag    = document.getElementById('ns-pag');
  var cur    = 1;

  function render() {
    var per   = parseInt(perSel.value) || 0;
    var total = rows.length;
    var pages = per > 0 ? Math.ceil(total / per) : 1;
    if (cur > pages) cur = pages;
    if (cur < 1)     cur = 1;

    rows.forEach(function(r, i) {
      if (per === 0) {
        r.style.display = '';
      } else {
        var start = (cur - 1) * per;
        r.style.display = (i >= start && i < start + per) ? '' : 'none';
      }
    });

    var start = per > 0 ? (cur - 1) * per + 1 : 1;
    var end   = per > 0 ? Math.min(cur * per, total) : total;
    info.textContent = total > 0
      ? 'Affichage ' + start + ' à ' + end + ' sur ' + total + ' matière(s)'
      : '';

    // Boutons pagination
    pag.innerHTML = '';
    if (pages <= 1) return;

    function btn(label, page, disabled, active) {
      var li = document.createElement('li');
      li.className = 'page-item' + (disabled?' disabled':'') + (active?' active':'');
      var a = document.createElement('a');
      a.className = 'page-link';
      a.href = '#';
      a.innerHTML = label;
      a.addEventListener('click', function(e){ e.preventDefault(); if(!disabled){cur=page;render();} });
      li.appendChild(a);
      pag.appendChild(li);
    }

    btn('&laquo;', cur - 1, cur === 1, false);
    var from = Math.max(1, cur - 2), to = Math.min(pages, cur + 2);
    if (from > 1)     { btn('1', 1, false, false); if(from>2) btn('…', cur, true, false); }
    for (var p = from; p <= to; p++) btn(p, p, false, p === cur);
    if (to < pages)   { if(to<pages-1) btn('…', cur, true, false); btn(pages, pages, false, false); }
    btn('&raquo;', cur + 1, cur === pages, false);
  }

  perSel.addEventListener('change', function(){ cur = 1; render(); });
  render();
})();
</script>

<?php endif; ?>
<?php endif; // seq_active ?>


<?php elseif ($onglet === 'releve_vierge'): ?>
<!-- ══════════════════════════════════════════════════
     ONGLET 5 — Relevé de notes : vierge ou rempli (24/10/2026)
══════════════════════════════════════════════════ -->
<?php
  $q_rv   = 'classe=' . $id_cl_rv . '&mode=' . $mode_rv . ($id_mat_rv ? '&mat=' . $id_mat_rv : '');
  $url_rv = APP_URL . '/secondaire/technique/pages/notes/pdf_releve_vierge.php?' . $q_rv;
  $classe_rv_lbl = 'Toutes les classes';
  if ($id_cl_rv) {
      $classe_rv_lbl = '';
      foreach ($classes as $c) { if ($c['id'] == $id_cl_rv) { $classe_rv_lbl = $c['designation']; break; } }
  }
  $mat_rv_lbl = '';
  foreach ($matieres_rv as $m) { if ((int) $m['id'] === $id_mat_rv) { $mat_rv_lbl = $m['libelle']; break; } }
  $titre_rv = 'Relevé de notes ' . $mode_rv . ' — ' . $classe_rv_lbl . ($mat_rv_lbl ? ' — ' . $mat_rv_lbl : '');
?>
<div class="card mb-3">
  <div class="card-body">
    <div class="section-titre mb-2"><i class="bi bi-file-earmark-spreadsheet me-1 text-primary"></i>Relevé de notes</div>
    <form method="get" class="row g-2 align-items-end" data-ajax-nav-form>
      <input type="hidden" name="onglet" value="releve_vierge">
      <div class="col-md-4">
        <label class="form-label fw-semibold">Classe</label>
        <select name="classe_rv" class="form-select" data-ajax-nav-auto>
          <option value="0" <?= $id_cl_rv==0?'selected':''?>>— Toutes les classes —</option>
          <?php foreach ($classes as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $id_cl_rv==$c['id']?'selected':''?>><?= h($c['designation']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Matière</label>
        <select name="mat_rv" class="form-select" data-ajax-nav-auto <?= $id_cl_rv ? '' : 'disabled' ?>>
          <option value="0"><?= $id_cl_rv ? '— Aucune (à remplir à la main) —' : '— Choisir d\'abord une classe —' ?></option>
          <?php foreach ($matieres_rv as $m): ?>
            <option value="<?= (int) $m['id'] ?>" <?= (int) $m['id'] === $id_mat_rv ? 'selected' : '' ?>><?= h($m['libelle']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Type de relevé</label>
        <select name="mode_rv" class="form-select" data-ajax-nav-auto>
          <option value="vierge" <?= $mode_rv === 'vierge' ? 'selected' : '' ?>>Vierge (à remplir à la main)</option>
          <option value="rempli" <?= $mode_rv === 'rempli' ? 'selected' : '' ?> <?= $id_mat_rv ? '' : 'disabled' ?>>Rempli (notes saisies)<?= $id_mat_rv ? '' : ' — choisir une matière' ?></option>
        </select>
      </div>
    </form>
    <div class="mt-3 d-flex flex-wrap gap-2">
      <button type="button" class="btn btn-sm btn-primary"
              onclick="afficherApercu(<?= h(json_encode($url_rv)) ?>, <?= h(json_encode($titre_rv)) ?>, 'releve_notes_matiere', 'portrait')">
        <i class="bi bi-eye me-1"></i>Afficher / imprimer
      </button>
      <a class="btn btn-sm" style="background:#1a2744;color:#fff" target="_blank" href="<?= h($url_rv) ?>&dl=1">
        <i class="bi bi-file-earmark-pdf me-1"></i>Télécharger en PDF
      </a>
      <?php if ($mode_rv === 'vierge'): ?>
      <a class="btn btn-sm btn-outline-success"
         href="<?= APP_URL ?>/secondaire/technique/pages/notes/excel_releve_vierge.php?classe=<?= $id_cl_rv ?>">
        <i class="bi bi-file-earmark-excel me-1"></i>Télécharger en Excel (vierge)
      </a>
      <?php endif; ?>
    </div>
    <?php if ($mode_rv === 'rempli'): ?>
      <div class="text-muted mt-2" style="font-size:.78rem"><i class="bi bi-info-circle me-1"></i>Une colonne par évaluation (séquence) de l'année, avec les notes saisies en <?= h($mat_rv_lbl) ?> ; case vide = note non saisie.</div>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card-header py-2" style="background:#f0f4ff">
    <span class="fw-semibold" style="color:#1a2744"><i class="bi bi-eye me-1"></i>Aperçu — <?= h($titre_rv) ?></span>
  </div>
  <div class="card-body p-0">
    <iframe src="<?= h($url_rv) ?>"
            style="width:100%;height:80vh;border:none;display:block" title="Aperçu du relevé de notes"></iframe>
  </div>
</div>
<?php endif; // fin onglet non_saisis / releve_vierge (fin chaine if/elseif onglets) ?>

<?php endif; // fin bloc seq_bloquee ?>

</div><!-- /#notes-zone -->

<?php
if ($es_partiel) exit; // rien de plus dans une réponse AJAX partielle.
$ajax_zone_id = 'notes-zone'; // voir noyau/layout/footer.php — initAjaxZone() y est appelé après sa propre définition
require_once __DIR__ . '/../../../../noyau/layout/footer.php'; ?>
