<?php
// ── Moteur de calcul — module Notes/Bulletins APC (piste arabe) ─────
// Modèle matière (pas de compétences) : `matiere_arabe` groupées par
// `groupe_matiere_arabe`. Moyenne = Σpoints / (Σbarème/20), même formule
// que la piste française (pas de coefficient — chaque matière est pondérée
// par son propre barème discipline_arabe, 20 par défaut) — sur les
// matières réellement composées (pas de ligne = exclue, jamais notée 0).
require_once __DIR__ . '/fonctions.php';
require_once __DIR__ . '/notes_apc.php'; // sequences_du_trimestre(), appreciation_moyenne(), libelle_appreciation()

// ── Structure : matières d'une classe (groupées, bilingues FR/AR) ──
// Résolues via le NIVEAU de la classe (matiere_niveau_arabe), pas d'affectation
// par classe : plus de classe_matiere_arabe (table supprimée) — assigner une
// matière à un niveau (pages/matieres_arabe/liste.php, onglet « Matières par
// niveau ») l'applique automatiquement à toutes ses classes.
function matieres_classe_arabe(int $id_classe): array {
    return db_all(
        "SELECT mn.id_mat, mn.ordre, m.id_groupe,
                m.matiere_fr, m.matiere_ar,
                g.nom_groupe_fr, g.nom_groupe_ar
         FROM classe c
         JOIN matiere_niveau_arabe mn ON mn.code_niveau = c.Niveau AND mn.actif = 1
         JOIN matiere_arabe m ON m.id_mat = mn.id_mat
         JOIN groupe_matiere_arabe g ON g.id_groupe = m.id_groupe
         WHERE c.IDClasses = ?
         ORDER BY mn.ordre",
        [$id_classe]
    );
}

// Même liste, chaque ligne enrichie d'une clé 'bareme' (null, ou le tableau
// orale/ecrite/pratique/total_points/actif de discipline_arabe) — utilisée
// par pages/notes_arabe/index.php pour basculer 1 champ /20 ↔ 3 champs.
function matieres_classe_arabe_avec_bareme(int $id_classe, string $val_annee): array {
    $mats = matieres_classe_arabe($id_classe);
    foreach ($mats as &$m) {
        $m['bareme'] = bareme_matiere_classe_arabe($id_classe, (int) $m['id_mat'], $val_annee);
    }
    unset($m);
    return $mats;
}

// ── Barème (Oral/Écrit/Pratique) d'une classe, en cache. Une matière sans
// ligne discipline_arabe active reste sur l'ancien modèle (note unique /20).
function &_cache_bareme_arabe_classe(): array {
    static $cache = [];
    return $cache;
}
function bareme_matiere_classe_arabe(int $id_classe, int $id_mat, string $val_annee): ?array {
    $cache = &_cache_bareme_arabe_classe();
    $cle_classe = $id_classe . '|' . $val_annee;
    if (!isset($cache[$cle_classe])) {
        $index = [];
        foreach (db_all("SELECT id_mat, orale, ecrite, pratique, total_points, actif FROM discipline_arabe WHERE IDClasses=? AND annee_scol=?", [$id_classe, $val_annee]) as $d) {
            if ((int) $d['actif'] === 1 && (float) $d['total_points'] > 0) {
                $index[(int) $d['id_mat']] = $d;
            }
        }
        $cache[$cle_classe] = $index;
    }
    return $cache[$cle_classe][$id_mat] ?? null;
}

// ── Note d'une matière pour une séquence (null si non composée, ou si
// aucun barème n'est configuré pour cette matière/classe/année). Consulte
// le préchargement de classe s'il existe (perf, évite 1 requête/élève).
// Normalise les 3 sous-notes sur /20 (total_obtenu / total_bareme * 20).
function note_matiere_sequence_arabe(int $id_eleve, int $id_mat, int $id_classe, int $id_seq): ?float {
    $preload = _cache_composer_sequence_classe_arabe()[$id_classe] ?? null;
    if ($preload !== null) {
        $row = $preload[$id_eleve . '|' . $id_mat . '|' . $id_seq] ?? null;
    } else {
        $row = db_one(
            "SELECT note_orale, note_ecrite, note_pratique FROM composer_sequence_arabe WHERE id_eleve=? AND id_mat=? AND classe=? AND id_seq=?",
            [$id_eleve, $id_mat, $id_classe, $id_seq]
        ) ?: null;
    }
    if ($row === null) return null;
    if ($row['note_orale'] === null && $row['note_ecrite'] === null && $row['note_pratique'] === null) return null;

    $val_annee = annee_de_sequence_arabe($id_seq);
    $bareme    = $val_annee !== '' ? bareme_matiere_classe_arabe($id_classe, $id_mat, $val_annee) : null;
    if ($bareme === null) return null;

    $total = (float) ($row['note_orale'] ?? 0) + (float) ($row['note_ecrite'] ?? 0) + (float) ($row['note_pratique'] ?? 0);
    return round($total / (float) $bareme['total_points'] * 20, 2);
}

// Année scolaire d'une séquence (via sequence -> trimestre).
function annee_de_sequence_arabe(int $id_seq): string {
    static $cache = [];
    if (!array_key_exists($id_seq, $cache)) {
        $cache[$id_seq] = (string) (db_val(
            "SELECT t.id_annee FROM sequence s JOIN trimestre t ON t.id_trim = s.id_trim WHERE s.id_seq = ?",
            [$id_seq]
        ) ?? '');
    }
    return $cache[$id_seq];
}

// ── Préchargement en masse par classe (perf, bulletins en lot).
function &_cache_composer_sequence_classe_arabe(): array {
    static $cache = [];
    return $cache;
}
function precharger_notes_sequence_classe_arabe(int $id_classe): void {
    $cache = &_cache_composer_sequence_classe_arabe();
    if (isset($cache[$id_classe])) return;
    $rows = db_all(
        "SELECT id_eleve, id_mat, id_seq, note_orale, note_ecrite, note_pratique FROM composer_sequence_arabe WHERE classe=?",
        [$id_classe]
    );
    $index = [];
    foreach ($rows as $r) {
        $index[$r['id_eleve'] . '|' . $r['id_mat'] . '|' . $r['id_seq']] = $r;
    }
    $cache[$id_classe] = $index;
}

// Points bruts d'une matière pour une séquence, sur l'échelle de son propre
// barème (Oral+Écrit+Pratique). Équivalent arabe de note_total_points côté
// français (note_competence_sur_sequences()).
function points_matiere_sequence_arabe(int $id_eleve, int $id_mat, int $id_classe, int $id_seq): ?float {
    $preload = _cache_composer_sequence_classe_arabe()[$id_classe] ?? null;
    if ($preload !== null) {
        $row = $preload[$id_eleve . '|' . $id_mat . '|' . $id_seq] ?? null;
    } else {
        $row = db_one(
            "SELECT note_orale, note_ecrite, note_pratique FROM composer_sequence_arabe WHERE id_eleve=? AND id_mat=? AND classe=? AND id_seq=?",
            [$id_eleve, $id_mat, $id_classe, $id_seq]
        ) ?: null;
    }
    if ($row === null) return null;
    if ($row['note_orale'] === null && $row['note_ecrite'] === null && $row['note_pratique'] === null) return null;
    return (float) ($row['note_orale'] ?? 0) + (float) ($row['note_ecrite'] ?? 0) + (float) ($row['note_pratique'] ?? 0);
}

// ── Moyenne d'une séquence pour un élève : Σpoints / (Σbarème/20), même
// formule que moyenne_eleve_sur_sequences() côté français (pas de
// coefficient — chaque matière est pondérée par son propre barème, 20 par
// défaut). Mise en cache dans moyenne_sequence_arabe (upsert, migration_v7).
function calculer_moyenne_sequence_eleve_arabe(int $id_eleve, int $id_seq, int $id_classe, string $val_annee): ?float {
    $matieres = matieres_classe_arabe($id_classe);
    $total_bareme = 0.0; $total_points = 0.0;
    foreach ($matieres as $mt) {
        $id_mat = (int) $mt['id_mat'];
        $pts = points_matiere_sequence_arabe($id_eleve, $id_mat, $id_classe, $id_seq);
        if ($pts === null) continue;
        $bareme = bareme_matiere_classe_arabe($id_classe, $id_mat, $val_annee);
        $total_bareme += $bareme ? (float) $bareme['total_points'] : 20.0;
        $total_points += $pts;
    }
    $coef = $total_bareme / 20;
    $moyenne = $coef > 0 ? round($total_points / $coef, 2) : null;

    if ($moyenne === null) {
        db_exec("DELETE FROM moyenne_sequence_arabe WHERE id_eleve=? AND id_seq=? AND classe=? AND val_annee=?",
            [$id_eleve, $id_seq, $id_classe, $val_annee]);
    } else {
        db_exec(
            "INSERT INTO moyenne_sequence_arabe (id_eleve, id_seq, classe, moy, val_annee) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE moy = VALUES(moy)",
            [$id_eleve, $id_seq, $id_classe, $moyenne, $val_annee]
        );
    }
    return $moyenne;
}

function recalculer_moyennes_sequence_classe_arabe(int $id_classe, int $id_seq, string $val_annee): int {
    $eleves = db_all(
        "SELECT e.id_eleve FROM eleve e JOIN inscrire i ON i.id_eleve = e.id_eleve
         WHERE i.IDClasses = ? AND i.val_annee = ? AND e.statut = 'actif'",
        [$id_classe, $val_annee]
    );
    foreach ($eleves as $e) { calculer_moyenne_sequence_eleve_arabe((int) $e['id_eleve'], $id_seq, $id_classe, $val_annee); }
    return count($eleves);
}

// ── Note d'une matière pour un trimestre (ligne de bulletin : séq.1/séq.2/moyenne) ──
function note_matiere_trimestre_arabe(int $id_eleve, int $id_mat, int $id_classe, int $id_trim, string $val_annee): array {
    $seqs = sequences_du_trimestre($id_trim);
    $n1 = isset($seqs[0]) ? note_matiere_sequence_arabe($id_eleve, $id_mat, $id_classe, $seqs[0]) : null;
    $n2 = isset($seqs[1]) ? note_matiere_sequence_arabe($id_eleve, $id_mat, $id_classe, $seqs[1]) : null;
    if ($n1 === null && $n2 === null) $moyenne = null;
    elseif ($n1 !== null && $n2 !== null) $moyenne = round(($n1 + $n2) / 2, 2);
    else $moyenne = $n1 ?? $n2;
    return ['note1' => $n1, 'note2' => $n2, 'moyenne' => $moyenne];
}

// Points bruts d'une matière pour un trimestre (moyenne des 1-2 séquences,
// sur l'échelle du barème) — équivalent arabe de note_competence_trimestre().
function points_matiere_trimestre_arabe(int $id_eleve, int $id_mat, int $id_classe, int $id_trim, string $val_annee): ?float {
    $seqs = sequences_du_trimestre($id_trim);
    $n1 = isset($seqs[0]) ? points_matiere_sequence_arabe($id_eleve, $id_mat, $id_classe, $seqs[0]) : null;
    $n2 = isset($seqs[1]) ? points_matiere_sequence_arabe($id_eleve, $id_mat, $id_classe, $seqs[1]) : null;
    if ($n1 === null && $n2 === null) return null;
    if ($n1 !== null && $n2 !== null) return round(($n1 + $n2) / 2, 2);
    return $n1 ?? $n2;
}

// ── Moyenne trimestrielle : Σpoints / (Σbarème/20) sur les matières
// composées au moins une fois dans le trimestre — même formule que
// calculer_moyenne_trimestre_eleve() côté français (sans le zéro
// automatique : piste arabe, matière non composée = exclue, jamais notée 0).
function calculer_moyenne_trimestre_eleve_arabe(int $id_eleve, int $id_trim, int $id_classe, string $val_annee): ?float {
    $matieres = matieres_classe_arabe($id_classe);
    $total_bareme = 0.0; $total_points = 0.0;
    foreach ($matieres as $mt) {
        $id_mat = (int) $mt['id_mat'];
        $pts = points_matiere_trimestre_arabe($id_eleve, $id_mat, $id_classe, $id_trim, $val_annee);
        if ($pts === null) continue;
        $bareme = bareme_matiere_classe_arabe($id_classe, $id_mat, $val_annee);
        $total_bareme += $bareme ? (float) $bareme['total_points'] : 20.0;
        $total_points += $pts;
    }
    $coef = $total_bareme / 20;
    $moyenne = $coef > 0 ? round($total_points / $coef, 2) : null;

    if ($moyenne === null) {
        db_exec("DELETE FROM moyenne_trimestre_arabe WHERE id_eleve=? AND id_trim=? AND classe=? AND val_annee=?",
            [$id_eleve, $id_trim, $id_classe, $val_annee]);
    } else {
        db_exec(
            "INSERT INTO moyenne_trimestre_arabe (id_eleve, id_trim, classe, moy, val_annee) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE moy = VALUES(moy)",
            [$id_eleve, $id_trim, $id_classe, $moyenne, $val_annee]
        );
    }
    return $moyenne;
}

function recalculer_moyennes_trimestre_classe_arabe(int $id_classe, int $id_trim, string $val_annee): int {
    // Rafraîchit le cache séquence (utilisé par le rang par UA) puis le cache trimestre.
    foreach (sequences_du_trimestre($id_trim) as $id_seq) {
        recalculer_moyennes_sequence_classe_arabe($id_classe, $id_seq, $val_annee);
    }
    $eleves = db_all(
        "SELECT e.id_eleve FROM eleve e JOIN inscrire i ON i.id_eleve = e.id_eleve
         WHERE i.IDClasses = ? AND i.val_annee = ? AND e.statut = 'actif'",
        [$id_classe, $val_annee]
    );
    foreach ($eleves as $e) { calculer_moyenne_trimestre_eleve_arabe((int) $e['id_eleve'], $id_trim, $id_classe, $val_annee); }
    return count($eleves);
}

// ── Classement d'une classe pour un trimestre (piste arabe) ─────────
// Algorithme « 1224 » comme classement_trimestre_classe() (piste française).
// Piège déjà rencontré : moyenne_trimestre_arabe.moy doit être decimal(4,2),
// pas VARCHAR (tri lexicographique sinon) — corrigé par migration v29.
function classement_trimestre_classe_arabe(int $id_classe, int $id_trim, string $val_annee): array {
    $lignes = db_all(
        "SELECT mt.id_eleve, mt.moy, e.Nom_elv, e.Prenom_elv
         FROM moyenne_trimestre_arabe mt
         JOIN eleve e ON e.id_eleve = mt.id_eleve
         JOIN inscrire i ON i.id_eleve = mt.id_eleve AND i.IDClasses = mt.classe AND i.val_annee = mt.val_annee
         WHERE mt.classe = ? AND mt.id_trim = ? AND mt.val_annee = ?
         ORDER BY mt.moy DESC",
        [$id_classe, $id_trim, $val_annee]
    );

    $n = count($lignes); $nb_admis = 0; $somme = 0.0; $nb_classes_val = 0;
    $rang_du_groupe = 0; $moy_precedente = null;
    foreach ($lignes as $i => &$l) {
        $moy = $l['moy'] !== null ? (float) $l['moy'] : null;
        if ($moy === null) { $l['rang'] = ''; $l['classement'] = 'N.C'; continue; }
        $l['classement'] = 'C';
        $somme += $moy; $nb_classes_val++;
        if ($moy >= 10) $nb_admis++;
        if ($moy_precedente === null || abs($moy - $moy_precedente) > 0.001) { $rang_du_groupe = $nb_classes_val; }
        $l['rang'] = $rang_du_groupe . ($rang_du_groupe < $nb_classes_val ? 'ex' : 'e');
        $moy_precedente = $moy;
    }
    unset($l);

    return [
        'lignes' => $lignes, 'effectif' => $n, 'nb_classes' => $nb_classes_val, 'nb_admis' => $nb_admis,
        'taux_reussite' => $nb_classes_val > 0 ? round($nb_admis / $nb_classes_val * 100, 2) : null,
        'moy_classe' => $nb_classes_val > 0 ? round($somme / $nb_classes_val, 2) : null,
        'moy_premier' => $nb_classes_val > 0 ? (float) $lignes[0]['moy'] : null,
        'moy_dernier' => $nb_classes_val > 0 ? (float) $lignes[$nb_classes_val - 1]['moy'] : null,
    ];
}

function rang_eleve_trimestre_arabe(int $id_eleve, int $id_classe, int $id_trim, string $val_annee): array {
    $classement = classement_trimestre_classe_arabe($id_classe, $id_trim, $val_annee);
    foreach ($classement['lignes'] as $l) {
        if ((int) $l['id_eleve'] === $id_eleve) {
            return [
                'moyenne' => $l['moy'] !== null ? (float) $l['moy'] : null, 'rang' => $l['rang'],
                'effectif' => $classement['effectif'], 'nb_classes' => $classement['nb_classes'],
                'moy_classe' => $classement['moy_classe'],
                'moy_premier' => $classement['moy_premier'], 'moy_dernier' => $classement['moy_dernier'],
                'taux_reussite' => $classement['taux_reussite'],
            ];
        }
    }
    return [
        'moyenne' => null, 'rang' => '', 'effectif' => $classement['effectif'],
        'nb_classes' => $classement['nb_classes'],
        'moy_classe' => $classement['moy_classe'], 'moy_premier' => $classement['moy_premier'],
        'moy_dernier' => $classement['moy_dernier'], 'taux_reussite' => $classement['taux_reussite'],
    ];
}

// ── Classement sur une ou plusieurs séquences (rang par UA) ─────────
// Miroir de moyenne_eleve_sur_sequences()/classement_sur_sequences() côté
// français : Σpoints / (Σbarème/20), moyenne d'une matière sur les
// séquences données puis pondération par barème sur toutes les matières.
function moyenne_eleve_sur_sequences_arabe(int $id_eleve, int $id_classe, array $seqs, string $val_annee): array {
    $matieres = matieres_classe_arabe($id_classe);
    $total_bareme = 0.0; $total_points = 0.0; $nb_composees = 0;
    foreach ($matieres as $mt) {
        $id_mat = (int) $mt['id_mat'];
        $pts = [];
        foreach ($seqs as $id_seq) {
            $p = points_matiere_sequence_arabe($id_eleve, $id_mat, $id_classe, (int) $id_seq);
            if ($p !== null) $pts[] = $p;
        }
        if (!$pts) continue;
        $bareme = bareme_matiere_classe_arabe($id_classe, $id_mat, $val_annee);
        $total_bareme += $bareme ? (float) $bareme['total_points'] : 20.0;
        $total_points += array_sum($pts) / count($pts);
        $nb_composees++;
    }
    $coef = $total_bareme / 20;
    $moyenne = $coef > 0 ? round($total_points / $coef, 2) : null;
    $classable = count($matieres) > 0 && ($nb_composees / count($matieres)) >= 0.5;
    return ['moyenne' => $moyenne, 'classable' => $classable];
}

function classement_sur_sequences_arabe(int $id_classe, array $seqs, string $val_annee): array {
    static $cache = [];
    $cle = $id_classe . '|' . $val_annee . '|' . implode(',', $seqs);
    if (isset($cache[$cle])) return $cache[$cle];

    $eleves = db_all(
        "SELECT e.id_eleve FROM eleve e JOIN inscrire i ON i.id_eleve = e.id_eleve
         WHERE i.IDClasses = ? AND i.val_annee = ? AND e.statut = 'actif'",
        [$id_classe, $val_annee]
    );
    $lignes = [];
    foreach ($eleves as $e) {
        $r = moyenne_eleve_sur_sequences_arabe((int) $e['id_eleve'], $id_classe, $seqs, $val_annee);
        $lignes[] = ['id_eleve' => $e['id_eleve'], 'moy' => $r['moyenne'], 'classable' => $r['classable']];
    }
    usort($lignes, function ($a, $b) {
        if ($a['moy'] === null && $b['moy'] === null) return 0;
        if ($a['moy'] === null) return 1;
        if ($b['moy'] === null) return -1;
        return $b['moy'] <=> $a['moy'];
    });

    $nb_classes_val = 0; $rang_du_groupe = 0; $moy_precedente = null;
    foreach ($lignes as &$l) {
        if ($l['moy'] === null || !$l['classable']) { $l['rang'] = ''; continue; }
        $nb_classes_val++;
        if ($moy_precedente === null || abs($l['moy'] - $moy_precedente) > 0.001) { $rang_du_groupe = $nb_classes_val; }
        $l['rang'] = $rang_du_groupe . ($rang_du_groupe < $nb_classes_val ? 'ex' : 'e');
        $moy_precedente = $l['moy'];
    }
    unset($l);

    return $cache[$cle] = ['lignes' => $lignes, 'effectif' => count($lignes), 'nb_classes' => $nb_classes_val];
}

// ── Moyenne annuelle (piste arabe) ──────────────────────────────────
function calculer_moyenne_annuelle_eleve_arabe(int $id_eleve, int $id_classe, string $val_annee): array {
    $valeurs = [];
    foreach (trimestres_de_annee($val_annee) as $id_trim) {
        $moy = db_val("SELECT moy FROM moyenne_trimestre_arabe WHERE id_eleve=? AND classe=? AND id_trim=? AND val_annee=?", [$id_eleve, $id_classe, $id_trim, $val_annee]);
        if ($moy !== null && $moy !== '') $valeurs[] = (float) $moy;
    }
    $nb = count($valeurs);
    $moyenne = $nb > 0 ? round(array_sum($valeurs) / $nb, 2) : null;

    if ($moyenne === null) {
        db_exec("DELETE FROM moyenne_annuelle_arabe WHERE id_eleve=? AND classe=? AND val_annee=?", [$id_eleve, $id_classe, $val_annee]);
    } else {
        db_exec(
            "INSERT INTO moyenne_annuelle_arabe (id_eleve, classe, moy, Nb_trim, val_annee) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE moy = VALUES(moy), Nb_trim = VALUES(Nb_trim)",
            [$id_eleve, $id_classe, $moyenne, $nb, $val_annee]
        );
    }
    return ['moyenne' => $moyenne, 'nb_trimestres' => $nb];
}

// ── Note d'une matière pour l'année — moyenne des moyennes trimestrielles
// (1 à 3 valeurs). Mémoïsée par requête.
function note_matiere_annuelle_arabe(int $id_eleve, int $id_mat, int $id_classe, string $val_annee): array {
    static $cache = [];
    $cle = $id_eleve . '|' . $id_mat . '|' . $id_classe . '|' . $val_annee;
    if (isset($cache[$cle])) return $cache[$cle];

    // trimestres_de_annee() : fonction du moteur français (notes_apc.php,
    // déjà chargé — voir l'en-tête de ce fichier), réutilisée telle quelle —
    // la liste des trimestres d'une année scolaire est commune aux 2 pistes.
    $valeurs = [];
    foreach (trimestres_de_annee($val_annee) as $id_trim) {
        $note = note_matiere_trimestre_arabe($id_eleve, $id_mat, $id_classe, $id_trim, $val_annee);
        if ($note['moyenne'] !== null) $valeurs[$id_trim] = $note['moyenne'];
    }
    $moyenne = $valeurs ? round(array_sum($valeurs) / count($valeurs), 2) : null;
    return $cache[$cle] = ['par_trim' => $valeurs, 'moyenne' => $moyenne];
}

// ── Classement annuel d'une classe (piste arabe) — même algorithme que
// classement_trimestre_classe_arabe(). moyenne_annuelle_arabe.moy est FLOAT.
function classement_annuel_classe_arabe(int $id_classe, string $val_annee): array {
    $lignes = db_all(
        "SELECT ma.id_eleve, ma.moy, ma.Nb_trim, e.Nom_elv, e.Prenom_elv
         FROM moyenne_annuelle_arabe ma
         JOIN eleve e ON e.id_eleve = ma.id_eleve
         JOIN inscrire i ON i.id_eleve = ma.id_eleve AND i.IDClasses = ma.classe AND i.val_annee = ma.val_annee
         WHERE ma.classe = ? AND ma.val_annee = ?
         ORDER BY (ma.moy IS NULL) ASC, ma.moy DESC",
        [$id_classe, $val_annee]
    );

    $n = count($lignes); $nb_admis = 0; $somme = 0.0; $nb_classes_val = 0;
    $rang_du_groupe = 0; $moy_precedente = null;
    foreach ($lignes as $i => &$l) {
        $moy = $l['moy'] !== null ? (float) $l['moy'] : null;
        if ($moy === null) { $l['rang'] = ''; $l['classement'] = 'N.C'; continue; }
        $l['classement'] = 'C';
        $somme += $moy; $nb_classes_val++;
        if ($moy >= 10) $nb_admis++;
        if ($moy_precedente === null || abs($moy - $moy_precedente) > 0.001) { $rang_du_groupe = $nb_classes_val; }
        $l['rang'] = $rang_du_groupe . ($rang_du_groupe < $nb_classes_val ? 'ex' : 'e');
        $moy_precedente = $moy;
    }
    unset($l);

    return [
        'lignes' => $lignes, 'effectif' => $n, 'nb_classes' => $nb_classes_val, 'nb_admis' => $nb_admis,
        'taux_reussite' => $nb_classes_val > 0 ? round($nb_admis / $nb_classes_val * 100, 2) : null,
        'moy_classe' => $nb_classes_val > 0 ? round($somme / $nb_classes_val, 2) : null,
        'moy_premier' => $nb_classes_val > 0 ? (float) $lignes[0]['moy'] : null,
        'moy_dernier' => $nb_classes_val > 0 ? (float) $lignes[$nb_classes_val - 1]['moy'] : null,
    ];
}

function rang_eleve_annuel_arabe(int $id_eleve, int $id_classe, string $val_annee): array {
    $classement = classement_annuel_classe_arabe($id_classe, $val_annee);
    foreach ($classement['lignes'] as $l) {
        if ((int) $l['id_eleve'] === $id_eleve) {
            return [
                'moyenne' => $l['moy'] !== null ? (float) $l['moy'] : null, 'rang' => $l['rang'],
                'nb_trim' => (int) $l['Nb_trim'], 'effectif' => $classement['effectif'],
                'moy_classe' => $classement['moy_classe'], 'moy_premier' => $classement['moy_premier'],
                'moy_dernier' => $classement['moy_dernier'], 'taux_reussite' => $classement['taux_reussite'],
            ];
        }
    }
    return [
        'moyenne' => null, 'rang' => '', 'nb_trim' => 0, 'effectif' => $classement['effectif'],
        'moy_classe' => $classement['moy_classe'], 'moy_premier' => $classement['moy_premier'],
        'moy_dernier' => $classement['moy_dernier'], 'taux_reussite' => $classement['taux_reussite'],
    ];
}

// ── Élèves inscrits mais non évalués (piste arabe) — pour l'onglet
// « Non évalués » de pages/statistiques_arabe/index.php. Un seul motif
// possible : aucune moyenne calculée pour cette vue.
function eleves_non_evalues_classe_arabe(int $id_classe, string $val_annee, string $vue = 'trim', int $id_trim = 0): array {
    $inscrits = db_all(
        "SELECT e.id_eleve, e.Mat_elv, e.Nom_elv, e.Prenom_elv, e.Sexe_elv
         FROM eleve e JOIN inscrire i ON i.id_eleve = e.id_eleve
         WHERE i.IDClasses = ? AND i.val_annee = ? AND e.statut = 'actif'
         ORDER BY e.Nom_elv, e.Prenom_elv",
        [$id_classe, $val_annee]
    );
    if (empty($inscrits)) return [];

    $classement = $vue === 'annee'
        ? classement_annuel_classe_arabe($id_classe, $val_annee)
        : classement_trimestre_classe_arabe($id_classe, $id_trim, $val_annee);
    $idx = [];
    foreach ($classement['lignes'] as $l) { $idx[(int) $l['id_eleve']] = $l; }

    $resultat = [];
    foreach ($inscrits as $e) {
        $l = $idx[(int) $e['id_eleve']] ?? null;
        if ($l === null || $l['moy'] === null) {
            $resultat[] = $e + ['raison' => 'Aucune moyenne calculée', 'moy' => $l['moy'] ?? null];
        }
    }
    return $resultat;
}

// ── Bilan M/F/T d'une classe (piste arabe) — miroir de bilan_classe_genre()
// côté français, réutilise mention_travail() (générique sur un float, pas
// spécifique à une piste). ─────────────────────────────────────────
function bilan_classe_genre_arabe(int $id_classe, string $val_annee, string $vue = 'trim', int $id_trim = 0): array {
    $classement = $vue === 'annee'
        ? classement_annuel_classe_arabe($id_classe, $val_annee)
        : classement_trimestre_classe_arabe($id_classe, $id_trim, $val_annee);

    $sexes = db_all(
        "SELECT e.id_eleve, e.Sexe_elv FROM eleve e
         JOIN inscrire i ON i.id_eleve = e.id_eleve
         WHERE i.IDClasses = ? AND i.val_annee = ? AND e.statut = 'actif'",
        [$id_classe, $val_annee]
    );
    $sexe_idx = [];
    foreach ($sexes as $s) { $sexe_idx[(int) $s['id_eleve']] = stripos($s['Sexe_elv'], 'F') === 0 ? 'F' : 'M'; }

    $zero = ['M' => 0, 'F' => 0, 'T' => 0];
    $b = array_fill_keys(['classes', 'moy_lt10', 'moy_ge10', 'felicit', 'encourag', 'tab', 'avert_trav', 'blame_trav'], $zero);
    $incr = function (string $col, string $sx) use (&$b): void { $b[$col][$sx]++; $b[$col]['T']++; };

    foreach ($classement['lignes'] as $l) {
        if ($l['moy'] === null) continue;
        $moy = (float) $l['moy'];
        $sx  = $sexe_idx[(int) $l['id_eleve']] ?? 'M';
        $incr('classes', $sx);
        $incr($moy >= 10 ? 'moy_ge10' : 'moy_lt10', $sx);
        $jours = $vue === 'annee'
            ? jours_absence_non_justifiees_annuel((int) $l['id_eleve'], $id_classe, $val_annee)
            : jours_absence_non_justifiees_trimestre((int) $l['id_eleve'], $id_classe, $id_trim, $val_annee);
        $m = mention_travail($moy, $jours);
        if ($m['felicitations'])   $incr('felicit', $sx);
        if ($m['encouragement'])   $incr('encourag', $sx);
        if ($m['tableau_honneur']) $incr('tab', $sx);
        if ($m['avertissement'])   $incr('avert_trav', $sx);
        if ($m['blame'])           $incr('blame_trav', $sx);
    }
    return $b;
}

// ── Statistiques agrégées d'une classe (piste arabe) ─────────────────
function stats_classe_arabe(int $id_classe, string $val_annee, string $vue = 'trim', int $id_trim = 0): array {
    $classement = $vue === 'annee'
        ? classement_annuel_classe_arabe($id_classe, $val_annee)
        : classement_trimestre_classe_arabe($id_classe, $id_trim, $val_annee);

    $eleves = db_all(
        "SELECT e.id_eleve, e.Sexe_elv FROM eleve e
         JOIN inscrire i ON i.id_eleve = e.id_eleve
         WHERE i.IDClasses = ? AND i.val_annee = ? AND e.statut = 'actif'",
        [$id_classe, $val_annee]
    );
    $filles = 0; $garcons = 0;
    foreach ($eleves as $e) { stripos($e['Sexe_elv'], 'F') === 0 ? $filles++ : $garcons++; }

    return [
        'nb'      => count($eleves), 'nb_classes' => $classement['nb_classes'],
        'moy'     => $classement['moy_classe'], 'premier' => $classement['moy_premier'],
        'dernier' => $classement['moy_dernier'], 'admis' => $classement['nb_admis'],
        'taux'    => $classement['taux_reussite'] ?? 0, 'filles' => $filles, 'garcons' => $garcons,
    ];
}

// ── Statistiques par matière sur un périmètre de classes (piste arabe) ──
function stats_par_matiere_arabe(array $id_classes, string $val_annee, string $vue = 'trim', int $id_trim = 0): array {
    if (empty($id_classes)) return [];
    $seqs = $vue === 'annee'
        ? array_column(db_all("SELECT s.id_seq FROM sequence s JOIN trimestre t ON t.id_trim = s.id_trim WHERE t.id_annee = ?", [$val_annee]), 'id_seq')
        : sequences_du_trimestre($id_trim);
    if (empty($seqs)) return [];

    $in_c = implode(',', array_fill(0, count($id_classes), '?'));
    $in_s = implode(',', array_fill(0, count($seqs), '?'));
    $rows = db_all(
        "SELECT cs.id_mat, cs.id_eleve, cs.classe, cs.id_seq, m.matiere_fr
         FROM composer_sequence_arabe cs
         JOIN matiere_arabe m ON m.id_mat = cs.id_mat
         JOIN inscrire i ON i.id_eleve = cs.id_eleve AND i.IDClasses = cs.classe AND i.val_annee = ?
         WHERE cs.classe IN ($in_c) AND cs.id_seq IN ($in_s)",
        array_merge([$val_annee], $id_classes, $seqs)
    );

    $par_mat = [];
    foreach ($rows as $r) {
        $note = note_matiere_sequence_arabe((int) $r['id_eleve'], (int) $r['id_mat'], (int) $r['classe'], (int) $r['id_seq']);
        if ($note === null) continue;
        $par_mat[$r['id_mat']]['nom'] = $r['matiere_fr'];
        $par_mat[$r['id_mat']]['vals'][$r['id_eleve']][] = $note;
    }
    $stats = [];
    foreach ($par_mat as $info) {
        $moys = [];
        foreach ($info['vals'] as $vs) { $moys[] = array_sum($vs) / count($vs); }
        $nb = count($moys);
        $admis = count(array_filter($moys, fn($m) => $m >= 10));
        $stats[] = [
            'matiere' => $info['nom'], 'nb' => $nb,
            'moy' => $nb > 0 ? array_sum($moys) / $nb : null,
            'min' => $nb > 0 ? min($moys) : null, 'max' => $nb > 0 ? max($moys) : null,
            'admis' => $admis, 'taux' => $nb > 0 ? round($admis / $nb * 100, 1) : 0,
        ];
    }
    usort($stats, fn($a, $b) => strcmp($a['matiere'], $b['matiere']));
    return $stats;
}

// ── Fiche statistique d'une classe pour un trimestre (piste arabe) ──
// Miroir exact de statistiques_classe_trimestre() (notes_apc.php) — mêmes
// tranches/mentions/appréciation (mention_travail()/appreciation_classe()
// sont génériques sur un float, réutilisées telles quelles), seule la
// source du classement change (classement_trimestre_classe_arabe()).
function statistiques_classe_trimestre_arabe(int $id_classe, int $id_trim, string $val_annee): array {
    $classement = classement_trimestre_classe_arabe($id_classe, $id_trim, $val_annee);

    $eleves = db_all(
        "SELECT e.id_eleve, e.Sexe_elv, i.Statut_elv
         FROM eleve e JOIN inscrire i ON i.id_eleve = e.id_eleve
         WHERE i.IDClasses = ? AND i.val_annee = ? AND e.statut = 'actif'",
        [$id_classe, $val_annee]
    );
    $sexe_idx = [];
    foreach ($eleves as $e) { $sexe_idx[(int) $e['id_eleve']] = stripos($e['Sexe_elv'], 'F') === 0 ? 'F' : 'M'; }

    $nv  = ['M' => 0, 'F' => 0]; $red = ['M' => 0, 'F' => 0];
    foreach ($eleves as $e) {
        $sx = $sexe_idx[(int) $e['id_eleve']];
        (($e['Statut_elv'] ?? 'Non') === 'Oui') ? $red[$sx]++ : $nv[$sx]++;
    }
    $nv['T'] = $nv['M'] + $nv['F']; $red['T'] = $red['M'] + $red['F'];
    $effectif_total = ['M' => $nv['M'] + $red['M'], 'F' => $nv['F'] + $red['F']];
    $effectif_total['T'] = $effectif_total['M'] + $effectif_total['F'];

    $tranche_vide = ['ge16' => 0, 'ge14' => 0, 'ge12' => 0, 'ge10' => 0, 'ge08' => 0, 'lt08' => 0];
    $tranches = ['M' => $tranche_vide, 'F' => $tranche_vide, 'T' => $tranche_vide];
    $mention_vide = ['blame' => 0, 'avertissement' => 0, 'tableau_honneur' => 0, 'encouragement' => 0, 'felicitations' => 0];
    $mentions = ['M' => $mention_vide, 'F' => $mention_vide, 'T' => $mention_vide];
    $admis = ['M' => 0, 'F' => 0, 'T' => 0];
    $eff_classe = ['M' => 0, 'F' => 0, 'T' => 0];

    foreach ($classement['lignes'] as $l) {
        if ($l['moy'] === null) continue;
        $moy = (float) $l['moy'];
        $sx  = $sexe_idx[(int) $l['id_eleve']] ?? 'M';
        $eff_classe[$sx]++; $eff_classe['T']++;

        if ($moy >= 16) { $tranches[$sx]['ge16']++; $tranches['T']['ge16']++; }
        elseif ($moy >= 14) { $tranches[$sx]['ge14']++; $tranches['T']['ge14']++; }
        elseif ($moy >= 12) { $tranches[$sx]['ge12']++; $tranches['T']['ge12']++; }
        elseif ($moy >= 10) { $tranches[$sx]['ge10']++; $tranches['T']['ge10']++; }
        elseif ($moy >= 8)  { $tranches[$sx]['ge08']++; $tranches['T']['ge08']++; }
        else                { $tranches[$sx]['lt08']++; $tranches['T']['lt08']++; }

        if ($moy >= 10) { $admis[$sx]++; $admis['T']++; }

        $jours = jours_absence_non_justifiees_trimestre((int) $l['id_eleve'], $id_classe, $id_trim, $val_annee);
        $m = mention_travail($moy, $jours);
        foreach (['blame', 'avertissement', 'tableau_honneur', 'encouragement', 'felicitations'] as $k) {
            if ($m[$k]) { $mentions[$sx][$k]++; $mentions['T'][$k]++; }
        }
    }

    $taux_reussite = ['M' => null, 'F' => null, 'T' => null];
    $taux_echec    = ['M' => null, 'F' => null, 'T' => null];
    foreach (['M', 'F', 'T'] as $g) {
        $taux_reussite[$g] = $eff_classe[$g] > 0 ? round($admis[$g] / $eff_classe[$g] * 100, 2) : null;
        $taux_echec[$g]    = $eff_classe[$g] > 0 ? round(($eff_classe[$g] - $admis[$g]) / $eff_classe[$g] * 100, 2) : null;
    }

    $nom_premier = ''; $nom_dernier = '';
    if (!empty($classement['lignes'])) {
        $classes_only = array_filter($classement['lignes'], fn($l) => $l['moy'] !== null);
        if ($classes_only) {
            $premier = reset($classes_only);
            $dernier = end($classes_only);
            $nom_premier = mb_strtoupper($premier['Nom_elv']) . ' ' . ($premier['Prenom_elv'] ?? '');
            $nom_dernier = mb_strtoupper($dernier['Nom_elv']) . ' ' . ($dernier['Prenom_elv'] ?? '');
        }
    }

    return [
        'effectif_total' => $effectif_total, 'nv' => $nv, 'red' => $red,
        'eff_classe' => $eff_classe, 'tranches' => $tranches, 'mentions' => $mentions,
        'admis' => $admis, 'taux_reussite' => $taux_reussite, 'taux_echec' => $taux_echec,
        'moy_classe' => $classement['moy_classe'], 'moy_premier' => $classement['moy_premier'],
        'moy_dernier' => $classement['moy_dernier'], 'nom_premier' => $nom_premier, 'nom_dernier' => $nom_dernier,
        'appreciation_classe' => appreciation_classe($classement['moy_classe']),
    ];
}
