<?php
// ── Moteur de calcul — module Notes/Bulletins APC (piste arabe) ─────
// Miroir de notes_apc.php (piste française), mais modèle matière+coefficient
// classique (pas de compétences/barème orale-écrite-pratique-savoir_etre) —
// c'est le modèle réel de la piste arabe dans jaynitaare : `matiere_arabe`
// (16 matières FR+AR) groupées par `groupe_matiere_arabe` (Éducation
// islamique / Langue arabe), pondérées par `classe_matiere_arabe.coef`.
// Formule vérifiée contre les vraies données déjà en cache (session 5,
// avant le chantier Pédagogie) : Σ(note×coef) / Σ(coef) sur les matières
// RÉELLEMENT composées (pas de ligne composer_sequence_arabe = matière
// exclue du calcul, jamais comptée à 0 — vérifié exact sur 13 élèves réels,
// séquence et trimestre confondus). La règle des « 2/3 du coefficient
// composé » du système legacy (Moyenne_seq_eleve_arabe(), classement N.C
// si pas assez de matières composées) n'a JAMAIS déclenché sur les
// véritables données de cette école (tous les élèves qui composent
// composent la totalité) — non reproduite ici, même simplification que la
// piste française (coef>0 suffit à être classable).
require_once __DIR__ . '/fonctions.php';
require_once __DIR__ . '/notes_apc.php'; // sequences_du_trimestre(), appreciation_moyenne(), libelle_appreciation()

// ── Structure : matières d'une classe (groupées, bilingues FR/AR) ──
function matieres_classe_arabe(int $id_classe): array {
    return db_all(
        "SELECT cma.id_mat, cma.coef, cma.ordre, cma.id_groupe,
                m.matiere_fr, m.matiere_ar,
                g.nom_groupe_fr, g.nom_groupe_ar
         FROM classe_matiere_arabe cma
         JOIN matiere_arabe m ON m.id_mat = cma.id_mat
         JOIN groupe_matiere_arabe g ON g.id_groupe = cma.id_groupe
         WHERE cma.code_classe = ?
         ORDER BY cma.ordre",
        [$id_classe]
    );
}

// ── Note brute d'une matière pour une séquence (ou null si non composée) ──
// Consulte le préchargement de classe s'il a été fait (voir
// precharger_notes_sequence_classe_arabe() ci-dessous) — même optimisation
// que note_competence_trimestre() côté français, mêmes gains (bulletins en
// lot : classe entière × ~16 matières × 3 trimestres, avant : 1 requête par
// (élève, matière, trimestre) ⇒ des milliers de requêtes individuelles).
function note_matiere_sequence_arabe(int $id_eleve, int $id_mat, int $id_classe, int $id_seq): ?float {
    $preload = _cache_composer_sequence_classe_arabe()[$id_classe] ?? null;
    if ($preload !== null) {
        $v = $preload[$id_eleve . '|' . $id_mat . '|' . $id_seq] ?? null;
        return $v !== null ? (float) $v : null;
    }
    $note = db_val(
        "SELECT note FROM composer_sequence_arabe WHERE id_eleve=? AND id_mat=? AND classe=? AND id_seq=?",
        [$id_eleve, $id_mat, $id_classe, $id_seq]
    );
    return $note !== null ? (float) $note : null;
}

// ── Préchargement en masse (piste arabe) — miroir de
// precharger_notes_sequence_classe() dans notes_apc.php. Note : contrairement
// à composer_sequence (FR), composer_sequence_arabe n'a pas de colonne
// val_annee (sa clé primaire est id_eleve/id_seq/id_mat/classe — l'année est
// déjà portée par id_seq via sequence.id_trim/trimestre.id_annee) — le
// préchargement filtre donc uniquement par classe, ce qui reste correct :
// note_matiere_trimestre_arabe() ne consulte jamais que des id_seq déjà
// bornés au trimestre/année demandés (sequences_du_trimestre()).
function &_cache_composer_sequence_classe_arabe(): array {
    static $cache = [];
    return $cache;
}
function precharger_notes_sequence_classe_arabe(int $id_classe): void {
    $cache = &_cache_composer_sequence_classe_arabe();
    if (isset($cache[$id_classe])) return;
    $rows = db_all(
        "SELECT id_eleve, id_mat, id_seq, note FROM composer_sequence_arabe WHERE classe=?",
        [$id_classe]
    );
    $index = [];
    foreach ($rows as $r) {
        $index[$r['id_eleve'] . '|' . $r['id_mat'] . '|' . $r['id_seq']] = $r['note'];
    }
    $cache[$id_classe] = $index;
}

// ── Moyenne d'une séquence pour un élève (Σnote×coef/Σcoef) ─────────
// Mise en cache dans moyenne_sequence_arabe (upsert, migration_v7).
function calculer_moyenne_sequence_eleve_arabe(int $id_eleve, int $id_seq, int $id_classe, string $val_annee): ?float {
    $matieres = matieres_classe_arabe($id_classe);
    $coef = 0.0; $note_coef = 0.0;
    foreach ($matieres as $mt) {
        $note = note_matiere_sequence_arabe($id_eleve, (int) $mt['id_mat'], $id_classe, $id_seq);
        if ($note === null) continue;
        $coef += (float) $mt['coef'];
        $note_coef += $note * (float) $mt['coef'];
    }
    $moyenne = $coef > 0 ? round($note_coef / $coef, 2) : null;

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

// ── Moyenne trimestrielle : moyenne des (1 à 2) moyennes de séquence ────
// Lit le cache moyenne_sequence_arabe (rafraîchi par recalculer_moyennes_
// sequence_classe_arabe()) plutôt que de resommer les notes brutes — même
// principe que Moyenne_trimestrielle_Arabe() du legacy.
function calculer_moyenne_trimestre_eleve_arabe(int $id_eleve, int $id_trim, int $id_classe, string $val_annee): ?float {
    $seqs = sequences_du_trimestre($id_trim);
    $valeurs = [];
    foreach ($seqs as $id_seq) {
        $moy = db_val(
            "SELECT moy FROM moyenne_sequence_arabe WHERE id_eleve=? AND id_seq=? AND classe=? AND val_annee=?",
            [$id_eleve, $id_seq, $id_classe, $val_annee]
        );
        if ($moy !== null && $moy !== '') $valeurs[] = (float) $moy;
    }
    $moyenne = $valeurs ? round(array_sum($valeurs) / count($valeurs), 2) : null;

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
    // Rafraîchit d'abord le cache séquence (les 2 séquences du trimestre),
    // puis le cache trimestre qui en dépend.
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
// Même algorithme « 1224 » que classement_trimestre_classe() (piste
// française), JOIN sur inscrire dès le départ (correctif déjà connu).
//
// Bug de tri lexicographique détecté ICI en premier (classe 5/trim 1 :
// moy_classe=14.83 mais le "1er" affiché avait moy=9.97 — impossible),
// moyenne_trimestre_arabe.moy était VARCHAR, confirmé identique côté
// français. Corrigé à la source par la migration v29 (decimal(4,2)).
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
        $l['rang'] = $rang_du_groupe . 'e' . ($rang_du_groupe < $nb_classes_val ? ' ex' : '');
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
                'effectif' => $classement['effectif'], 'moy_classe' => $classement['moy_classe'],
                'moy_premier' => $classement['moy_premier'], 'moy_dernier' => $classement['moy_dernier'],
                'taux_reussite' => $classement['taux_reussite'],
            ];
        }
    }
    return [
        'moyenne' => null, 'rang' => '', 'effectif' => $classement['effectif'],
        'moy_classe' => $classement['moy_classe'], 'moy_premier' => $classement['moy_premier'],
        'moy_dernier' => $classement['moy_dernier'], 'taux_reussite' => $classement['taux_reussite'],
    ];
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

// ── Note d'une matière pour l'année (bulletin annuel arabe) ─────────
// Moyenne des moyennes trimestrielles de cette matière (1 à 3 valeurs) —
// même principe que note_competence_annuelle() côté français.
// Mémoïsée par requête (même principe et mêmes gains que
// note_competence_annuelle() côté français — voir son commentaire dans
// notes_apc.php pour le détail de l'explosion combinatoire évitée).
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

// ── Classement annuel d'une classe (piste arabe) ─────────────────────
// Même algorithme « 1224 » et même correctif de tri numérique que
// classement_trimestre_classe_arabe() — moyenne_annuelle_arabe.moy est déjà
// FLOAT (contrairement à moyenne_trimestre_arabe), pas besoin du "+0".
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
        $l['rang'] = $rang_du_groupe . 'e' . ($rang_du_groupe < $nb_classes_val ? ' ex' : '');
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
        "SELECT cs.id_mat, cs.id_eleve, cs.note, m.matiere_fr
         FROM composer_sequence_arabe cs
         JOIN matiere_arabe m ON m.id_mat = cs.id_mat
         JOIN inscrire i ON i.id_eleve = cs.id_eleve AND i.IDClasses = cs.classe AND i.val_annee = ?
         WHERE cs.classe IN ($in_c) AND cs.id_seq IN ($in_s)",
        array_merge([$val_annee], $id_classes, $seqs)
    );

    $par_mat = [];
    foreach ($rows as $r) {
        $par_mat[$r['id_mat']]['nom'] = $r['matiere_fr'];
        $par_mat[$r['id_mat']]['vals'][$r['id_eleve']][] = (float) $r['note'];
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
