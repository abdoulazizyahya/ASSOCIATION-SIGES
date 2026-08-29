<?php
// ── Moteur de calcul — module Notes/Bulletins APC (piste française) ─────
// Port fidèle des fonctions de jaynitaare/php/mes_fonctions.php
// (Save_Moyenne_trimestriel_eleve, calcul_moyenne_eleve_par_trimestre,
// Save_Calcule_Moyenne_annuelle, Appreciation_Fr, Appreciation_Moyenne,
// Note_Total_Par_Competence_Trimestrielle), vérifié ligne à ligne contre les
// vraies valeurs déjà stockées dans moyenne_trimestre/moyenne_annuelle
// (8051 notes réelles déjà saisies pour 2025/2026 — voir prompt de
// continuité, section « Notes/Bulletins APC »).
//
// Modèle : chaque compétence active (schéma jaynitaare réel, jamais
// redessiné) a un barème par classe/année dans `discipline` (orale + écrite
// + pratique + savoir_etre = total_points). Un élève compose au plus 2
// séquences par trimestre (`sequence.id_trim`) ; sa note pour une compétence
// dans un trimestre est la moyenne des 1 ou 2 séquences renseignées. La
// moyenne trimestrielle est cette somme de notes-par-compétence rapportée
// au barème total effectivement composé, ramenée sur 20.
//
// Bilinguisme : `competence`/`groupe_competence` existent en double (un jeu
// `langue='Fr'`, un jeu `langue='An'` avec les mêmes `code_comp`) — SEUL le
// jeu Fr porte des données réelles (`discipline`/`composer_sequence`),
// vérifié (aucune ligne discipline pour un id_comp du jeu En). Le jeu En
// sert uniquement à retrouver le libellé anglais via code_comp pour
// l'affichage bilingue des bulletins — jamais pour une saisie ou un calcul.

require_once __DIR__ . '/fonctions.php';

// ── Barème / structure ───────────────────────────────────────────

// Compétences actives pour une classe/année, groupées (jeu Fr — le seul
// utilisé pour la saisie et le calcul), avec le libellé anglais jumeau
// (même code_comp, jeu En) pour l'affichage bilingue.
//
// Mise en cache mémoire (clé $id_classe|$val_annee) — trouvé le 21/08/2026
// en traquant le nombre de requêtes d'un bulletin trimestriel (~1100 pour
// UN élève !) : cette fonction ne dépend QUE de $id_classe/$val_annee, mais
// était rappelée à chaque fois qu'un code appelant en avait besoin — en
// particulier classement_sur_sequences() (via moyenne_eleve_sur_sequences())
// qui la rappelle UNE FOIS PAR ÉLÈVE DE LA CLASSE, et que
// pdf/bulletin_trimestriel.php invoque 2 fois par bulletin (rang UA1/UA2) :
// pour une classe de 40, 2 × 40 = 80 appels, à 1 requête + N sous-requêtes
// nom_comp_en (voir plus bas) chacun → l'essentiel des ~1100 requêtes.
// Même pattern déjà utilisé ailleurs dans ce fichier (sequences_du_trimestre(),
// precharger_notes_sequence_classe()...) — aucun changement de résultat, la
// liste des compétences actives d'une classe/année ne change jamais en
// cours de requête HTTP.
function competences_classe(int $id_classe, string $val_annee): array {
    static $cache = [];
    $cle = $id_classe . '|' . $val_annee;
    if (isset($cache[$cle])) return $cache[$cle];

    // Seules les compétences dont le GROUPE est réellement assigné au niveau
    // de cette classe (onglet « Groupes par niveau ») sont chargées — même
    // résolution Fr↔An par ordre_affichage que fonctions.php::bareme_par_niveau()
    // (un niveau anglophone n'assigne que des groupes langue='An', jamais
    // 'Fr' directement, voir ce commentaire là-bas). Sans ce filtre, une
    // ligne `discipline` restée d'un ancien découpage (groupe depuis retiré
    // de l'assignation du niveau) continuait à apparaître en saisie — bug
    // signalé le 26/08/2026 ("de mauvaises compétences s'affichent").
    $code_niveau     = (string) db_val("SELECT Niveau FROM classe WHERE IDClasses=?", [$id_classe]);
    $ordres_assignes = $code_niveau ? array_map('intval', array_column(
        array_filter(
            db_all(
                "SELECT gcn.id_groupe_comp, gcn.actif, g.ordre_affichage
                 FROM groupe_competence_niveau gcn
                 JOIN groupe_competence g ON g.id_groupe_comp = gcn.id_groupe_comp
                 WHERE gcn.code_niveau=?",
                [$code_niveau]
            ),
            fn($a) => (int) $a['actif'] === 1
        ),
        'ordre_affichage'
    )) : [];
    if (!$ordres_assignes) return $cache[$cle] = [];

    $in_ord = implode(',', array_fill(0, count($ordres_assignes), '?'));
    $lignes = db_all(
        "SELECT m.id_comp, m.code_comp, m.nom_comp, m.id_groupe_comp,
                g.libelle_groupe_comp, g.ordre_affichage,
                d.orale, d.ecrite, d.pratique, d.savoir_etre, d.total_points
         FROM competence m
         JOIN discipline d ON d.id_comp = m.id_comp
         JOIN groupe_competence g ON g.id_groupe_comp = m.id_groupe_comp
         WHERE d.IDClasses = ? AND d.annee_scol = ? AND g.langue = 'Fr' AND g.ordre_affichage IN ($in_ord) AND d.actif = 1
         ORDER BY g.ordre_affichage, m.code_comp",
        array_merge([$id_classe, $val_annee], $ordres_assignes)
    );

    // Libellés EN : une seule requête groupée (IN (...)) au lieu d'une
    // requête par compétence — même optimisation, même occasion (21/08/2026).
    $codes = array_values(array_unique(array_column($lignes, 'code_comp')));
    $noms_en = [];
    if ($codes) {
        $placeholders = implode(',', array_fill(0, count($codes), '?'));
        $rows_en = db_all(
            "SELECT m2.code_comp, m2.nom_comp FROM competence m2
             JOIN groupe_competence g2 ON g2.id_groupe_comp = m2.id_groupe_comp
             WHERE g2.langue = 'An' AND m2.code_comp IN ($placeholders)",
            $codes
        );
        foreach ($rows_en as $r) { $noms_en[$r['code_comp']] = $r['nom_comp']; }
    }
    // Libellés EN des GROUPES (jumeau par ordre_affichage, pas code_comp —
    // voir libelle_groupe_competence_en()) : nécessaire pour que la saisie
    // affiche des libellés anglais pour une classe de section anglophone
    // (bug signalé le 26/08/2026 — la saisie montrait toujours le français,
    // même pour une classe An, alors que « Groupes par niveau » assigne bien
    // les groupes 'An' à ces niveaux-là).
    $ordres = array_values(array_unique(array_column($lignes, 'ordre_affichage')));
    $libelles_groupe_en = [];
    if ($ordres) {
        $placeholders_o = implode(',', array_fill(0, count($ordres), '?'));
        foreach (db_all("SELECT ordre_affichage, libelle_groupe_comp FROM groupe_competence WHERE langue='An' AND ordre_affichage IN ($placeholders_o)", $ordres) as $r) {
            $libelles_groupe_en[(int) $r['ordre_affichage']] = $r['libelle_groupe_comp'];
        }
    }
    foreach ($lignes as &$l) {
        $l['nom_comp_en']           = $noms_en[$l['code_comp']] ?? '';
        $l['libelle_groupe_comp_en'] = $libelles_groupe_en[(int) $l['ordre_affichage']] ?? '';
    }
    unset($l);

    return $cache[$cle] = $lignes;
}

// Libellé anglais d'un groupe de compétences (jumeau par ordre_affichage —
// les 2 jeux de groupes ont le même ordre_affichage 1..6, code_comp n'existe
// qu'au niveau compétence, pas groupe).
function libelle_groupe_competence_en(int $ordre_affichage): string {
    return db_val(
        "SELECT libelle_groupe_comp FROM groupe_competence WHERE langue='An' AND ordre_affichage=? LIMIT 1",
        [$ordre_affichage]
    ) ?? '';
}

// Les 1 ou 2 séquences d'un trimestre, dans l'ordre.
function sequences_du_trimestre(int $id_trim): array {
    static $cache = [];
    if (isset($cache[$id_trim])) return $cache[$id_trim];
    return $cache[$id_trim] = array_column(
        db_all("SELECT id_seq FROM sequence WHERE id_trim=? ORDER BY id_seq", [$id_trim]),
        'id_seq'
    );
}

// ── Préchargement en masse des notes de séquence d'une classe ──────────
// Optimisation pure (aucun changement de résultat) : note_competence_trimestre()
// fait normalement 1-2 requêtes SQL par (élève, compétence, trimestre) —
// correct mais coûteux en boucle sur une classe entière (bulletins en lot,
// bulletin annuel avec sa colonne de rang par compétence — voir le
// commentaire de rang_eleve_competence_annuelle() plus bas pour le détail
// de l'explosion combinatoire que ça évite en pratique). Cette fonction
// charge en UNE requête toutes les lignes composer_sequence d'une
// classe/année, indexées en mémoire ; note_competence_trimestre() les
// consulte en priorité si elles sont disponibles. Sans appel préalable
// (cas d'un bulletin individuel), note_competence_trimestre() continue de
// fonctionner à l'identique (requêtes directes) — cet appel est un pur
// accélérateur, jamais requis pour l'exactitude du résultat.
function &_cache_composer_sequence_classe(): array {
    static $cache = [];
    return $cache;
}
function precharger_notes_sequence_classe(int $id_classe, string $val_annee): void {
    $cache = &_cache_composer_sequence_classe();
    $cle_classe = $id_classe . '|' . $val_annee;
    if (isset($cache[$cle_classe])) return;
    // Colonnes détail (orale/écrite/pratique/savoir_etre) ajoutées le
    // 21/08/2026 — le préchargement ne portait à l'origine que
    // note_total_points (suffisant pour note_competence_trimestre()) ; mais
    // note_detail_competence_sequence() (tableau UA1/UA2 du bulletin
    // trimestriel, 2 appels par compétence) ignorait ce cache et refaisait
    // systématiquement une requête directe — 22-26 requêtes/élève. Même
    // table, même WHERE : ajouter les colonnes ici coûte 0 requête de plus
    // et les rend disponibles aux deux fonctions consommatrices ci-dessous.
    $rows = db_all(
        "SELECT id_eleve, id_comp, id_seq, note_total_points, note_orale, note_ecrite, note_pratique, note_savoir_etre
         FROM composer_sequence WHERE IDClasses=? AND val_annee=?",
        [$id_classe, $val_annee]
    );
    $index = [];
    foreach ($rows as $r) {
        $index[$r['id_eleve'] . '|' . $r['id_comp'] . '|' . $r['id_seq']] = $r;
    }
    $cache[$cle_classe] = $index;
}

// ── Absences justifiées lors d'une évaluation (migration_v38) ──────────
// Reproduction de pages/absences/index.php d'ABZ_MBE, adaptée au modèle
// compétence+séquence de jaynitaare (composer_sequence) — voir
// pages/notes/absence_justifiee.php. Par (élève, compétence, séquence) :
// exempte du zéro automatique (calculer_moyenne_trimestre_eleve() ci-dessous)
// un élève sans note dont l'absence a été justifiée sur AU MOINS une des
// séquences du trimestre pour cette compétence — la compétence est alors
// simplement ignorée pour lui ce trimestre (ni notée, ni zéro), comme si
// elle n'était pas encore évaluée par la classe. N'affecte PAS l'éligibilité
// au classement (`classable`) — portée volontairement identique à la page
// ABZ_MBE reproduite (éviter le zéro, rien de plus).
function &_cache_absence_justifiee_classe(): array {
    static $cache = [];
    return $cache;
}
function precharger_absences_justifiees_classe(int $id_classe, string $val_annee): void {
    $cache = &_cache_absence_justifiee_classe();
    $cle_classe = $id_classe . '|' . $val_annee;
    if (isset($cache[$cle_classe])) return;
    $rows = db_all(
        "SELECT id_eleve, id_comp, id_seq FROM absence_justifiee WHERE IDClasses=? AND val_annee=? AND justifie=1",
        [$id_classe, $val_annee]
    );
    $index = [];
    foreach ($rows as $r) { $index[$r['id_eleve'] . '|' . $r['id_comp'] . '|' . $r['id_seq']] = true; }
    $cache[$cle_classe] = $index;
}
function absence_justifiee_val(int $id_eleve, int $id_comp, int $id_seq, int $id_classe, string $val_annee): bool {
    $preload = _cache_absence_justifiee_classe()[$id_classe . '|' . $val_annee] ?? null;
    if ($preload !== null) return isset($preload[$id_eleve . '|' . $id_comp . '|' . $id_seq]);
    return (bool) db_val(
        "SELECT justifie FROM absence_justifiee WHERE id_eleve=? AND id_comp=? AND id_seq=?",
        [$id_eleve, $id_comp, $id_seq]
    );
}

// ── Appréciations ────────────────────────────────────────────────

// Port de Appreciation_Moyenne() — échelle /20 (moyennes trimestrielle/annuelle).
function appreciation_moyenne(?float $moyenne): string {
    if ($moyenne === null) return '';
    if ($moyenne < 10) return 'NA';
    if ($moyenne < 14) return 'ECA';
    if ($moyenne < 18) return 'A';
    return 'A+';
}

// Appréciation en arabe pour la piste matière/coefficient (bulletins
// arabes) — les codes NA/ECA/A/A+ ci-dessus sont propres au système à
// compétences (APC) français et n'ont pas de sens ici. Seuils déduits du
// modèle de référence fourni le 14/08 (bd/../arabe.pdf : 20→ممتاز,
// 16.25 à 18.5→جيد جدا, 15.5→جيد — les seuils exacts entre ces paliers
// n'étaient pas visibles sur l'unique exemple fourni, valeurs rondes
// choisies en cohérence avec tous les points observés ; à ajuster si le
// résultat ne correspond pas exactement à l'usage attendu).
function appreciation_moyenne_arabe(?float $moyenne): string {
    if ($moyenne === null) return '';
    if ($moyenne < 10) return 'ضعيف';
    if ($moyenne < 12) return 'متوسط';
    if ($moyenne < 14) return 'مقبول';
    if ($moyenne < 16) return 'جيد';
    if ($moyenne < 19) return 'جيد جدا';
    return 'ممتاز';
}

function libelle_appreciation(string $code): string {
    return match ($code) {
        'NA'  => 'Non Acquis',
        'ECA' => "En Cours d'Acquisition",
        'A'   => 'Acquis',
        'A+'  => 'Acquis avec Facilité',
        default => '',
    };
}

// Port de Appreciation_Fr($note, $bareme) — échelle proportionnelle au
// barème d'une compétence (20/30/40 en pratique dans jaynitaare, mais
// generalisée proportionnellement pour tout barème réellement rencontré).
function appreciation_fr(?float $note, float $bareme): string {
    if ($note === null || $bareme <= 0) return '';
    $pct = $note / $bareme;
    if ($pct < 0.55) return 'NA';
    if ($pct < 0.75) return 'ECA';
    if ($pct < 0.90) return 'A';
    return 'A+';
}

// Équivalent arabe de appreciation_fr() — mêmes seuils/principe (0.55/0.75/0.90
// du barème). Retourne un code, pas les emoji directement : aucune police
// embarquée dans le projet n'a de glyphes couleur ❌⏳🥇⭐ (TCPDF/amirib —
// vérifié, les cellules restaient vides), affichés via assets/img/pdf/cote_*.png
// (capturés depuis le rendu Chrome, seul rendu couleur disponible ici) —
// voir cote_icone_chemin() dans pdf/bulletin_trimestriel_arabe.php.
function appreciation_fr_arabe(?float $note, float $bareme): string {
    if ($note === null || $bareme <= 0) return '';
    $pct = $note / $bareme;
    if ($pct < 0.55) return 'na';    // ❌ Non acquis
    if ($pct < 0.75) return 'eca';   // ⏳ En cours d’acquisition
    if ($pct < 0.90) return 'a';     // 🥇 Acquis
    return 'aplus';                  // ⭐ Acquis avec facilité
}

// ── Jours d'absence NON JUSTIFIÉS d'un élève ────────────────────────
// Port de Abscence_eleve_par_trimestre() (jaynitaare/php/mes_fonctions.php)
// — seule la colonne nbre_jour_non_jus entre dans les formules de mention
// ci-dessous (ABS_JUS n'y intervient jamais dans l'original). Même table
// que pages/absences/index.php (clé id_eleve, PAS mat_elv — cohérent avec
// le reste du schéma jaynitaare_v2 après migration). Enregistrement en
// JOURS (pas en heures) depuis la migration_v40, demande explicite du
// 17/08/2026 — colonnes `absence.nbre_jour_non_jus`/`nbre_jour_jus`
// (renommées depuis nbre_heure_non_jus/nbre_heure_jus, même sémantique de
// comptage, seule l'unité change), fonctions renommées en conséquence.
// Préchargement en masse (classe + trimestre) des 2 colonnes de `absence`
// — trouvé le 21/08/2026 dans la même traque de requêtes que les
// préchargements ci-dessus : un bulletin en lot interroge cette table UNE
// FOIS PAR ÉLÈVE (justifiées + non justifiées séparément, voir
// pdf/bulletin_trimestriel.php) — chargée en 1 requête par classe/trimestre.
function &_cache_absences_classe_trim(): array {
    static $cache = [];
    return $cache;
}
function precharger_absences_classe_trim(int $id_classe, int $id_trim, string $val_annee): void {
    $cache = &_cache_absences_classe_trim();
    $cle = $id_classe . '|' . $id_trim . '|' . $val_annee;
    if (isset($cache[$cle])) return;
    $rows = db_all(
        "SELECT id_eleve, nbre_jour_non_jus, nbre_jour_jus FROM absence WHERE classe=? AND id_trim=? AND val_annee=?",
        [$id_classe, $id_trim, $val_annee]
    );
    $index = [];
    foreach ($rows as $r) { $index[(int) $r['id_eleve']] = $r; }
    $cache[$cle] = $index;
}

function jours_absence_non_justifiees_trimestre(int $id_eleve, int $id_classe, int $id_trim, string $val_annee): float {
    $preload = _cache_absences_classe_trim()[$id_classe . '|' . $id_trim . '|' . $val_annee] ?? null;
    if ($preload !== null) return (float) ($preload[$id_eleve]['nbre_jour_non_jus'] ?? 0);
    return (float) (db_val(
        "SELECT nbre_jour_non_jus FROM absence WHERE id_eleve=? AND classe=? AND id_trim=? AND val_annee=?",
        [$id_eleve, $id_classe, $id_trim, $val_annee]
    ) ?? 0);
}

// Jours d'absence JUSTIFIÉS d'un élève — même table/préchargement que
// jours_absence_non_justifiees_trimestre() ci-dessus, colonne jumelle
// (nbre_jour_jus). Remplace une requête directe qui existait en ligne dans
// pdf/bulletin_trimestriel.php (jamais nommée en fonction avant le
// 21/08/2026, donc jamais préchargeable).
function jours_absence_justifiees_trimestre(int $id_eleve, int $id_classe, int $id_trim, string $val_annee): float {
    $preload = _cache_absences_classe_trim()[$id_classe . '|' . $id_trim . '|' . $val_annee] ?? null;
    if ($preload !== null) return (float) ($preload[$id_eleve]['nbre_jour_jus'] ?? 0);
    return (float) (db_val(
        "SELECT nbre_jour_jus FROM absence WHERE id_eleve=? AND classe=? AND id_trim=? AND val_annee=?",
        [$id_eleve, $id_classe, $id_trim, $val_annee]
    ) ?? 0);
}

// Port de la somme des 3 Abscence_eleve_par_trimestre() utilisée par
// BULLETIN_ANNUEL_CLASSE.php (abs_annuelle = abs_trim1+abs_trim2+abs_trim3).
function jours_absence_non_justifiees_annuel(int $id_eleve, int $id_classe, string $val_annee): float {
    $total = 0.0;
    foreach (trimestres_de_annee($val_annee) as $id_trim) $total += jours_absence_non_justifiees_trimestre($id_eleve, $id_classe, $id_trim, $val_annee);
    return $total;
}

// ── Préchargement en masse (classe + trimestre) de `exclusion` — même
// principe, remplace une requête directe en ligne dans
// pdf/bulletin_trimestriel.php. ──────────────────────────────────────────
function &_cache_exclusions_classe_trim(): array {
    static $cache = [];
    return $cache;
}
function precharger_exclusions_classe_trim(int $id_classe, int $id_trim, string $val_annee): void {
    $cache = &_cache_exclusions_classe_trim();
    $cle = $id_classe . '|' . $id_trim . '|' . $val_annee;
    if (isset($cache[$cle])) return;
    $rows = db_all(
        "SELECT id_eleve, nbre_jours FROM exclusion WHERE classe=? AND id_trim=? AND val_annee=?",
        [$id_classe, $id_trim, $val_annee]
    );
    $index = [];
    foreach ($rows as $r) { $index[(int) $r['id_eleve']] = $r['nbre_jours']; }
    $cache[$cle] = $index;
}
function exclusion_jours_trimestre(int $id_eleve, int $id_classe, int $id_trim, string $val_annee): ?string {
    $preload = _cache_exclusions_classe_trim()[$id_classe . '|' . $id_trim . '|' . $val_annee] ?? null;
    if ($preload !== null) return $preload[$id_eleve] ?? null;
    return db_val(
        "SELECT nbre_jours FROM exclusion WHERE id_eleve=? AND classe=? AND id_trim=? AND val_annee=?",
        [$id_eleve, $id_classe, $id_trim, $val_annee]
    );
}

// ── Préchargement en masse des fiches élève / statut d'inscription d'une
// classe — remplace 2 requêtes par élève (SELECT * FROM eleve, Statut_elv)
// par 2 requêtes pour toute la classe. ──────────────────────────────────
function &_cache_eleves_classe(): array {
    static $cache = [];
    return $cache;
}
function precharger_eleves_classe(int $id_classe, string $val_annee): void {
    $cache = &_cache_eleves_classe();
    $cle = $id_classe . '|' . $val_annee;
    if (isset($cache[$cle])) return;
    $rows = db_all(
        "SELECT e.* FROM eleve e JOIN inscrire i ON i.id_eleve = e.id_eleve WHERE i.IDClasses=? AND i.val_annee=?",
        [$id_classe, $val_annee]
    );
    $index = [];
    foreach ($rows as $r) { $index[(int) $r['id_eleve']] = $r; }
    $cache[$cle] = $index;
}
function eleve_preload(int $id_eleve, int $id_classe, string $val_annee): ?array {
    $preload = _cache_eleves_classe()[$id_classe . '|' . $val_annee] ?? null;
    if ($preload !== null) return $preload[$id_eleve] ?? null;
    return db_one("SELECT * FROM eleve WHERE id_eleve=?", [$id_eleve]);
}

function &_cache_statuts_classe(): array {
    static $cache = [];
    return $cache;
}
function precharger_statuts_classe(int $id_classe, string $val_annee): void {
    $cache = &_cache_statuts_classe();
    $cle = $id_classe . '|' . $val_annee;
    if (isset($cache[$cle])) return;
    $rows = db_all("SELECT id_eleve, Statut_elv FROM inscrire WHERE IDClasses=? AND val_annee=?", [$id_classe, $val_annee]);
    $index = [];
    foreach ($rows as $r) { $index[(int) $r['id_eleve']] = $r['Statut_elv']; }
    $cache[$cle] = $index;
}
function statut_eleve_preload(int $id_eleve, int $id_classe, string $val_annee): string {
    $preload = _cache_statuts_classe()[$id_classe . '|' . $val_annee] ?? null;
    if ($preload !== null) return $preload[$id_eleve] ?? 'Non';
    return db_val(
        "SELECT Statut_elv FROM inscrire WHERE id_eleve=? AND IDClasses=? AND val_annee=?",
        [$id_eleve, $id_classe, $val_annee]
    ) ?: 'Non';
}

// ── Annulation d'évaluation (migration v40, remplace l'annulation de
//    trimestre de la migration v37 — celle-ci n'a jamais eu de ligne réelle
//    en production) ────────────────────────────────────────────────────
// Exempte un élève d'UNE évaluation précise (une séquence — ex. UA3, pas tout
// le 2e trimestre) : sa note pour cette séquence est totalement ignorée pour
// TOUTES les compétences (voir la fonction lire() de note_competence_
// trimestre() et note_detail_competence_sequence() plus bas — ni prise en
// compte dans le calcul, ni affichée sur le bulletin), sans zéro automatique
// ni pénalité de classement pour l'évaluation manquante (même mécanisme
// d'exemption que absence_justifiee, voir calculer_moyenne_trimestre_eleve()
// ci-dessous — sauf que l'annulation porte sur TOUTE la séquence d'un coup,
// pas une seule compétence). La moyenne trimestrielle de la compétence est
// alors simplement la moyenne des séquences RESTANTES du trimestre (1 sur 2,
// ou aucune) — jamais divisée par le nombre d'évaluations d'origine du
// trimestre. Demande explicite du 17/08/2026 — gérée depuis
// pages/notes/annulation_evaluation.php, réservée aux élèves sans note ou
// ayant composé moins de 50% des compétences pour cette séquence (contrôlé
// côté page, pas ici — cette fonction n'est qu'un moteur d'écriture/lecture).
// Accesseur par référence (même principe que _cache_composer_sequence_classe()
// plus haut) : indispensable pour que annuler_evaluation_eleve()/retablir_
// evaluation_eleve() puissent mettre À JOUR ce cache directement après
// écriture (pas juste le lire) — sans ça, un appel à evaluation_annulee_pour_
// eleve() AVANT l'annulation fige une valeur `false` mémoïsée qui ne serait
// jamais invalidée pour le reste de la requête, même après l'annulation
// réelle en base (même piège que trimestre_annule_pour_eleve() avant elle).
function &_cache_evaluation_annulee(): array {
    static $cache = [];
    return $cache;
}

// Préchargement en masse par ANNÉE (table petite/exceptionnelle, pas besoin
// de la scoper par classe) — trouvé le 21/08/2026 en traquant le nombre de
// requêtes d'un bulletin trimestriel : evaluation_annulee_pour_eleve() sans
// préchargement fait 1 requête par (élève, séquence) JAMAIS ENCORE VU, or
// classement_sur_sequences() (via note_competence_sur_sequences()) l'appelle
// pour CHAQUE élève de la classe — 80 requêtes pour une classe de 40 (2
// séquences par bulletin). Même principe que precharger_notes_sequence_classe()
// ci-dessus : lit toute la table en 1 requête, indexée en mémoire ;
// evaluation_annulee_pour_eleve() la consulte en priorité si disponible. Les
// fonctions d'écriture (annuler_evaluation_eleve()/retablir_evaluation_eleve()
// ci-dessous) mettent aussi CE cache à jour si le préchargement est actif,
// pour rester cohérentes dans une requête qui lirait et écrirait à la fois.
function &_cache_evaluation_annulee_annee(): array {
    static $cache = [];
    return $cache;
}
function precharger_evaluations_annulees_annee(string $val_annee): void {
    $cache = &_cache_evaluation_annulee_annee();
    if (isset($cache[$val_annee])) return;
    $rows = db_all("SELECT id_eleve, id_seq FROM evaluation_annulee WHERE val_annee=?", [$val_annee]);
    $index = [];
    foreach ($rows as $r) { $index[$r['id_eleve'] . '|' . $r['id_seq']] = true; }
    $cache[$val_annee] = $index;
}
function evaluation_annulee_pour_eleve(int $id_eleve, int $id_seq, string $val_annee): bool {
    $preload = _cache_evaluation_annulee_annee()[$val_annee] ?? null;
    if ($preload !== null) {
        return isset($preload[$id_eleve . '|' . $id_seq]);
    }
    $cache = &_cache_evaluation_annulee();
    $cle = $id_eleve . '|' . $id_seq . '|' . $val_annee;
    if (!array_key_exists($cle, $cache)) {
        $cache[$cle] = (bool) db_val(
            "SELECT COUNT(*) FROM evaluation_annulee WHERE id_eleve=? AND id_seq=? AND val_annee=?",
            [$id_eleve, $id_seq, $val_annee]
        );
    }
    return $cache[$cle];
}

// Liste des annulations d'une classe pour une évaluation (page d'administration).
function evaluations_annulees_classe(int $id_classe, int $id_seq, string $val_annee): array {
    return db_all(
        "SELECT ea.*, e.Nom_elv, e.Prenom_elv, e.Mat_elv, u.login_user AS annule_par
         FROM evaluation_annulee ea
         JOIN eleve e ON e.id_eleve = ea.id_eleve
         JOIN inscrire i ON i.id_eleve = ea.id_eleve AND i.val_annee = ea.val_annee
         LEFT JOIN user u ON u.id_user = ea.id_utilisateur
         WHERE i.IDClasses = ? AND ea.id_seq = ? AND ea.val_annee = ?
         ORDER BY e.Nom_elv, e.Prenom_elv",
        [$id_classe, $id_seq, $val_annee]
    );
}

// Annule (ou ré-annule avec un nouveau motif — upsert) une évaluation
// précise d'UN élève, puis recalcule ses moyennes trim/annuelle pour rester
// cohérent immédiatement (pas seulement au prochain enregistrement de note).
function annuler_evaluation_eleve(int $id_eleve, int $id_classe, int $id_seq, string $val_annee, ?string $motif, ?int $id_utilisateur): void {
    db_exec(
        "INSERT INTO evaluation_annulee (id_eleve, id_seq, val_annee, motif, id_utilisateur) VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE motif = VALUES(motif), id_utilisateur = VALUES(id_utilisateur), annule_le = CURRENT_TIMESTAMP",
        [$id_eleve, $id_seq, $val_annee, $motif, $id_utilisateur]
    );
    $cache = &_cache_evaluation_annulee();
    $cache[$id_eleve . '|' . $id_seq . '|' . $val_annee] = true;
    $preload = &_cache_evaluation_annulee_annee();
    if (isset($preload[$val_annee])) { $preload[$val_annee][$id_eleve . '|' . $id_seq] = true; }
    $id_trim = (int) db_val("SELECT id_trim FROM sequence WHERE id_seq=?", [$id_seq]);
    if ($id_trim) calculer_moyenne_trimestre_eleve($id_eleve, $id_trim, $id_classe, $val_annee);
    calculer_moyenne_annuelle_eleve($id_eleve, $id_classe, $val_annee);
}

// Rétablit (annule l'annulation de) une évaluation d'un élève.
function retablir_evaluation_eleve(int $id_eleve, int $id_classe, int $id_seq, string $val_annee): void {
    db_exec("DELETE FROM evaluation_annulee WHERE id_eleve=? AND id_seq=? AND val_annee=?", [$id_eleve, $id_seq, $val_annee]);
    $cache = &_cache_evaluation_annulee();
    $cache[$id_eleve . '|' . $id_seq . '|' . $val_annee] = false;
    $preload = &_cache_evaluation_annulee_annee();
    if (isset($preload[$val_annee])) { unset($preload[$val_annee][$id_eleve . '|' . $id_seq]); }
    $id_trim = (int) db_val("SELECT id_trim FROM sequence WHERE id_seq=?", [$id_seq]);
    if ($id_trim) calculer_moyenne_trimestre_eleve($id_eleve, $id_trim, $id_classe, $val_annee);
    calculer_moyenne_annuelle_eleve($id_eleve, $id_classe, $val_annee);
}

// Séquences sélectionnables pour la saisie/consultation : celles du
// TRIMESTRE ACTIF uniquement (1 ou 2, voir sequences_du_trimestre()), jamais
// celles d'un autre trimestre. Demande explicite du 17/08/2026 — assouplit
// l'ancienne règle (UNIQUEMENT la séquence active, aucun choix) pour
// permettre de revenir corriger une évaluation déjà passée du même trimestre
// (ex. UA3 alors qu'UA4 est devenue active) sans ouvrir la saisie à
// n'importe quelle période de l'année. []  si aucune séquence active.
function sequences_trimestre_actif(): array {
    $seq_active = get_sequence_active();
    if (empty($seq_active['id_trim'])) return [];
    return db_all(
        "SELECT s.id_seq, s.libelle_seq, t.libelle_trim
         FROM sequence s JOIN trimestre t ON t.id_trim = s.id_trim
         WHERE s.id_trim = ? ORDER BY s.id_seq",
        [$seq_active['id_trim']]
    );
}

// ── Taux de participation par compétence (règle "note zéro automatique") ──
// Pour chaque compétence de la classe, proportion des élèves actifs inscrits
// ayant composé (au moins une des 1-2 séquences du trimestre) — un élève
// SANS note pour une compétence dont le taux est ≥ 50% reçoit un zéro dans
// le calcul de sa moyenne trimestrielle (voir calculer_moyenne_trimestre_
// eleve() ci-dessous) ; en dessous de 50%, la compétence n'est simplement
// pas encore évaluée par l'enseignant — comportement inchangé (ignorée).
// Mémoïsé par (classe, trimestre, année) : UN SEUL calcul même appelé pour
// chaque élève de la classe (recalculer_moyennes_trimestre_classe() boucle
// sur tous les élèves), même principe que _cache_composer_sequence_classe().
function taux_participation_competences_trimestre(int $id_classe, int $id_trim, string $val_annee): array {
    static $cache = [];
    $cle = $id_classe . '|' . $id_trim . '|' . $val_annee;
    if (isset($cache[$cle])) return $cache[$cle];

    $effectif = (int) db_val(
        "SELECT COUNT(*) FROM eleve e JOIN inscrire i ON i.id_eleve = e.id_eleve
         WHERE i.IDClasses=? AND i.val_annee=? AND e.statut='actif'",
        [$id_classe, $val_annee]
    );
    $seqs = sequences_du_trimestre($id_trim);
    $taux = [];
    if ($effectif > 0 && $seqs) {
        $in_seq = implode(',', array_fill(0, count($seqs), '?'));
        $rows = db_all(
            "SELECT cs.id_comp, COUNT(DISTINCT cs.id_eleve) AS nb
             FROM composer_sequence cs
             JOIN eleve e ON e.id_eleve = cs.id_eleve
             JOIN inscrire i ON i.id_eleve = cs.id_eleve AND i.IDClasses = cs.IDClasses AND i.val_annee = cs.val_annee
             WHERE cs.IDClasses=? AND cs.val_annee=? AND cs.id_seq IN ($in_seq)
               AND cs.note_total_points IS NOT NULL AND e.statut='actif'
             GROUP BY cs.id_comp",
            array_merge([$id_classe, $val_annee], $seqs)
        );
        foreach ($rows as $r) {
            $taux[(int) $r['id_comp']] = (int) $r['nb'] / $effectif;
        }
    }
    return $cache[$cle] = ['effectif' => $effectif, 'taux' => $taux];
}

// ── Statistiques « Évaluation en cours » (une seule séquence) — calcul EN
//    LIGNE, jamais persisté ────────────────────────────────────────────
// Demande explicite du 18/08/2026 : le module Statistiques (onglet « Par
// classe / Résultats ») doit pouvoir afficher soit le trimestre entier
// (comportement historique, moyenne_trimestre cache) soit UNE SEULE
// évaluation (séquence) du trimestre en cours. Plutôt que de dupliquer le
// cache `moyenne_trimestre` (réservé au calcul RÉEL du trimestre, utilisé
// par les bulletins/classement officiel), ces 4 fonctions généralisent les
// équivalents *_trimestre() à un ENSEMBLE de séquences explicite et
// recalculent à la volée à chaque affichage — jamais d'écriture en base,
// jamais utilisées par un bulletin. Passer sequences_du_trimestre($id_trim)
// reproduit exactement le calcul du trimestre entier ; passer [$id_seq]
// restreint à cette seule évaluation.
function taux_participation_competences_sequences(int $id_classe, array $seqs, string $val_annee): array {
    static $cache = [];
    $cle = $id_classe . '|' . implode(',', $seqs) . '|' . $val_annee;
    if (isset($cache[$cle])) return $cache[$cle];

    $effectif = (int) db_val(
        "SELECT COUNT(*) FROM eleve e JOIN inscrire i ON i.id_eleve = e.id_eleve
         WHERE i.IDClasses=? AND i.val_annee=? AND e.statut='actif'",
        [$id_classe, $val_annee]
    );
    $taux = [];
    if ($effectif > 0 && $seqs) {
        $in_seq = implode(',', array_fill(0, count($seqs), '?'));
        $rows = db_all(
            "SELECT cs.id_comp, COUNT(DISTINCT cs.id_eleve) AS nb
             FROM composer_sequence cs
             JOIN eleve e ON e.id_eleve = cs.id_eleve
             JOIN inscrire i ON i.id_eleve = cs.id_eleve AND i.IDClasses = cs.IDClasses AND i.val_annee = cs.val_annee
             WHERE cs.IDClasses=? AND cs.val_annee=? AND cs.id_seq IN ($in_seq)
               AND cs.note_total_points IS NOT NULL AND e.statut='actif'
             GROUP BY cs.id_comp",
            array_merge([$id_classe, $val_annee], $seqs)
        );
        foreach ($rows as $r) { $taux[(int) $r['id_comp']] = (int) $r['nb'] / $effectif; }
    }
    return $cache[$cle] = ['effectif' => $effectif, 'taux' => $taux];
}

function note_competence_sur_sequences(int $id_eleve, int $id_comp, int $id_classe, array $seqs, string $val_annee): ?float {
    $preload = _cache_composer_sequence_classe()[$id_classe . '|' . $val_annee] ?? null;
    $valeurs = [];
    foreach ($seqs as $id_seq) {
        $id_seq = (int) $id_seq;
        if (evaluation_annulee_pour_eleve($id_eleve, $id_seq, $val_annee)) continue;
        if ($preload !== null) {
            // Le cache indexe désormais la ligne complète (voir
            // precharger_notes_sequence_classe(), 21/08/2026) — extraire le
            // seul champ utile ici.
            $v = $preload[$id_eleve . '|' . $id_comp . '|' . $id_seq]['note_total_points'] ?? null;
        } else {
            $v = db_val(
                "SELECT note_total_points FROM composer_sequence WHERE id_eleve=? AND id_comp=? AND IDClasses=? AND id_seq=? AND val_annee=?",
                [$id_eleve, $id_comp, $id_classe, $id_seq, $val_annee]
            );
        }
        if ($v !== null) $valeurs[] = (float) $v;
    }
    return $valeurs ? round(array_sum($valeurs) / count($valeurs), 2) : null;
}

function moyenne_eleve_sur_sequences(int $id_eleve, int $id_classe, array $seqs, string $val_annee): array {
    $competences = competences_classe($id_classe, $val_annee);
    $participation = taux_participation_competences_sequences($id_classe, $seqs, $val_annee)['taux'];

    $total_bareme = 0.0; $total_points = 0.0; $nb_composees = 0;
    foreach ($competences as $c) {
        $id_comp = (int) $c['id_comp'];
        $note = note_competence_sur_sequences($id_eleve, $id_comp, $id_classe, $seqs, $val_annee);
        if ($note !== null) {
            $total_bareme += (float) $c['total_points'];
            $total_points += $note;
            $nb_composees++;
            continue;
        }
        if (($participation[$id_comp] ?? 0.0) >= 0.5) {
            $exemptee = false;
            foreach ($seqs as $id_seq_verif) {
                if (absence_justifiee_val($id_eleve, $id_comp, (int) $id_seq_verif, $id_classe, $val_annee)
                    || evaluation_annulee_pour_eleve($id_eleve, (int) $id_seq_verif, $val_annee)) { $exemptee = true; break; }
            }
            if (!$exemptee) $total_bareme += (float) $c['total_points'];
        }
    }

    $coef = $total_bareme / 20;
    $moyenne = $coef > 0 ? round($total_points / $coef, 2) : null;
    $nb_total_comp = count($competences);
    $classable = $nb_total_comp > 0 && ($nb_composees / $nb_total_comp) >= 0.5;

    return ['moyenne' => $moyenne, 'classable' => $classable];
}

function classement_sur_sequences(int $id_classe, array $seqs, string $val_annee): array {
    // Effectif de la classe mémoïsé (indépendant de $seqs) — trouvé le
    // 21/08/2026 : cette fonction est appelée une fois par séquence (UA1
    // puis UA2) pour CHAQUE bulletin imprimé, donc 2× par élève d'une
    // classe en lot — sans cache, le même effectif était rerequêté à
    // l'identique 80 fois pour une classe de 40.
    static $cache = [];
    $cle = $id_classe . '|' . $val_annee;
    if (isset($cache[$cle])) {
        $eleves = $cache[$cle];
    } else {
        $eleves = $cache[$cle] = db_all(
            "SELECT e.id_eleve, e.Nom_elv, e.Prenom_elv FROM eleve e
             JOIN inscrire i ON i.id_eleve = e.id_eleve
             WHERE i.IDClasses = ? AND i.val_annee = ? AND e.statut = 'actif'
             ORDER BY e.Nom_elv, e.Prenom_elv",
            [$id_classe, $val_annee]
        );
    }
    $lignes = [];
    foreach ($eleves as $e) {
        $r = moyenne_eleve_sur_sequences((int) $e['id_eleve'], $id_classe, $seqs, $val_annee);
        $lignes[] = [
            'id_eleve' => $e['id_eleve'], 'Nom_elv' => $e['Nom_elv'], 'Prenom_elv' => $e['Prenom_elv'],
            'moy' => $r['moyenne'], 'classable' => $r['classable'],
        ];
    }
    usort($lignes, function ($a, $b) {
        if ($a['moy'] === null && $b['moy'] === null) return 0;
        if ($a['moy'] === null) return 1;
        if ($b['moy'] === null) return -1;
        return $b['moy'] <=> $a['moy'];
    });

    $n = count($lignes);
    $nb_admis = 0; $somme = 0.0; $nb_classes_val = 0;
    $rang_du_groupe = 0; $moy_precedente = null;
    $moy_premier = null; $moy_dernier = null;
    foreach ($lignes as &$l) {
        $moy = $l['moy'] !== null ? (float) $l['moy'] : null;
        if ($moy === null || !$l['classable']) { $l['rang'] = ''; $l['classement'] = 'N.C'; continue; }
        $l['classement'] = 'C';
        $somme += $moy; $nb_classes_val++;
        if ($moy >= 10) $nb_admis++;
        if ($moy_premier === null) $moy_premier = $moy;
        $moy_dernier = $moy;
        if ($moy_precedente === null || abs($moy - $moy_precedente) > 0.001) $rang_du_groupe = $nb_classes_val;
        $l['rang'] = $rang_du_groupe . ($rang_du_groupe < $nb_classes_val ? 'ex' : 'e');
        $moy_precedente = $moy;
    }
    unset($l);

    return [
        'lignes' => $lignes, 'effectif' => $n, 'nb_classes' => $nb_classes_val, 'nb_admis' => $nb_admis,
        'taux_reussite' => $nb_classes_val > 0 ? round($nb_admis / $nb_classes_val * 100, 2) : null,
        'moy_classe' => $nb_classes_val > 0 ? round($somme / $nb_classes_val, 2) : null,
        'moy_premier' => $moy_premier, 'moy_dernier' => $moy_dernier,
    ];
}

// ── Mentions de travail/conduite (bulletins, certificats, statistiques) ──
// Port EXACT de Tableau_honneur()/Encouragement()/Felicitation()/
// Avertissement_travail()/Blame_travail()/Avertissement_Conduite_eleve()/
// Blame_Conduite_eleve() (jaynitaare/php/mes_fonctions.php) — remplace
// l'ancien port de Sanction_Notes() (barème différent, n'a jamais été le
// bon modèle : Sanction_Notes() n'est utilisée par aucun bulletin/certificat
// legacy, seul Tableau_honneur() et sa famille le sont). "Travail" (sur la
// moyenne seule) et "Conduite" (sur les jours d'absence non justifiés
// seuls) sont deux axes indépendants dans l'original — Tableau d'honneur/
// Encouragement/Félicitations combinent les deux (moyenne ET absences).
// $jours_absence_non_justifiees : 0 par défaut pour les appelants qui ne
// suivent pas encore l'assiduité (mêmes seuils, juste jamais "Refusé").
function mention_travail(?float $moyenne, float $jours_absence_non_justifiees = 0.0): array {
    if ($moyenne === null) {
        return [
            'blame' => false, 'avertissement' => false, 'tableau_honneur' => false,
            'encouragement' => false, 'felicitations' => false, 'refuse_tableau_honneur' => false,
            'avertissement_conduite' => false, 'blame_conduite' => false, 'libelle' => '',
        ];
    }
    $jrs = $jours_absence_non_justifiees;

    $tableau_honneur        = $moyenne >= 10 && $jrs < 15;
    $refuse_tableau_honneur = $moyenne >= 14 && $jrs >= 15;
    $encouragement          = $tableau_honneur && $moyenne > 16;
    $felicitations          = $tableau_honneur && $moyenne >= 16;
    $avertissement          = $moyenne >= 5 && $moyenne <= 7.30;
    $blame                  = $moyenne < 5;
    $avertissement_conduite = $jrs >= 5 && $jrs < 10;
    $blame_conduite         = $jrs >= 10;

    $libelle = match (true) {
        $felicitations          => "Tableau d'honneur, Félicitations",
        $encouragement          => "Tableau d'honneur, Encouragement",
        $tableau_honneur        => "Tableau d'honneur",
        $refuse_tableau_honneur => "Tableau d'honneur refusé (absences)",
        $avertissement          => 'Avertissement travail',
        $blame                  => 'Blâme travail',
        default                 => '',
    };
    return compact(
        'blame', 'avertissement', 'tableau_honneur', 'encouragement', 'felicitations',
        'refuse_tableau_honneur', 'avertissement_conduite', 'blame_conduite', 'libelle'
    );
}

// Appréciation qualitative de la classe entière (moyenne de classe), portée
// à l'identique de la logique par tranche déjà en usage (ABZ_MBE, même
// principe) — distincte de mention_travail() qui s'applique à un élève.
function appreciation_classe(?float $moyenne_classe): string {
    if ($moyenne_classe === null) return '';
    if ($moyenne_classe >= 16) return 'Excellent';
    if ($moyenne_classe >= 14) return 'Très Bien';
    if ($moyenne_classe >= 12) return 'Bien';
    if ($moyenne_classe >= 10) return 'Assez Bien';
    if ($moyenne_classe >= 8)  return 'Moyenne';
    return 'Faible';
}

// ── Fiche statistique d'une classe pour un trimestre ────────────────
// Regroupe classement_trimestre_classe() + répartition par tranche/mention,
// séparé par genre (M/F) et total (T) — réutilisé par la page web et le PDF.
function statistiques_classe_trimestre(int $id_classe, int $id_trim, string $val_annee): array {
    $classement = classement_trimestre_classe($id_classe, $id_trim, $val_annee);

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
        if ($l['moy'] === null || !$l['classable']) continue; // "Non classé" (v37) : jamais compté dans les stats
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

// ── Note d'une compétence pour un trimestre (ligne de bulletin) ────
// Port de Note_Total_Par_Competence_Trimestrielle() : NOTE1 (séq. 1),
// NOTE2 (séq. 2), MOYENNE (moyenne des séquences renseignées).
// ── Bilan M/F/T d'une classe (port de calc_bilan_classe_genre() d'ABZ_MBE) ──
// Mêmes 8 colonnes que la table « bilan par genre » d'ABZ_MBE (onglets Par
// section / Par niveau des Statistiques) : Classés, Moy<10, Moy>=10,
// Félicitations, Encouragements, Tableau d'honneur, Avertissement travail,
// Blâme travail — chacune ventilée M / F / Total.
// $vue : 'trim' (avec $id_trim) ou 'annee' — mêmes colonnes dans les 2 cas,
// seule la source du classement change (moyenne_trimestre vs moyenne_annuelle).
// $seqs_override (demande du 18/08/2026, uniquement pertinent avec $vue='trim') :
// si fourni, le classement est calculé EN LIGNE sur cet ensemble explicite de
// séquences (classement_sur_sequences()) au lieu du cache moyenne_trimestre —
// permet la vue « Évaluation en cours » (une seule séquence) du module
// Statistiques sans toucher au calcul réel du trimestre.
function bilan_classe_genre(int $id_classe, string $val_annee, string $vue = 'trim', int $id_trim = 0, ?array $seqs_override = null): array {
    $classement = $vue === 'annee'
        ? classement_annuel_classe($id_classe, $val_annee)
        : ($seqs_override !== null
            ? classement_sur_sequences($id_classe, $seqs_override, $val_annee)
            : classement_trimestre_classe($id_classe, $id_trim, $val_annee));

    $sexes = db_all(
        "SELECT e.id_eleve, e.Sexe_elv FROM eleve e
         JOIN inscrire i ON i.id_eleve = e.id_eleve
         WHERE i.IDClasses = ? AND i.val_annee = ? AND e.statut = 'actif'",
        [$id_classe, $val_annee]
    );
    $sexe_idx = [];
    foreach ($sexes as $s) { $sexe_idx[(int) $s['id_eleve']] = stripos($s['Sexe_elv'], 'F') === 0 ? 'F' : 'M'; }

    $zero = ['M' => 0, 'F' => 0, 'T' => 0];
    $b = array_fill_keys(
        [
            'classes', 'moy_lt10', 'moy_ge10', 'felicit', 'encourag', 'tab', 'avert_trav', 'blame_trav',
            // Tranches de moyenne (onglet « Par niveau » — demande du 18/08/2026,
            // remplace le duo Moy<10/Moy>=10 par 7 tranches plus fines).
            'tr_0_7', 'tr_7_10', 'tr_10_12', 'tr_12_14', 'tr_14_16', 'tr_16_18', 'tr_18_20',
        ],
        $zero
    );
    $incr = function (string $col, string $sx) use (&$b): void { $b[$col][$sx]++; $b[$col]['T']++; };
    // Moyenne générale par genre (onglet « Par classe / Résultats » —
    // demande du 17/08/2026) : somme des moyennes classées, divisée par
    // 'classes' (même dénominateur que le taux de réussite) une fois la
    // boucle terminée.
    $moy_somme = ['M' => 0.0, 'F' => 0.0, 'T' => 0.0];

    foreach ($classement['lignes'] as $l) {
        // N.C : jamais compté — `classable` (v37) n'existe que côté trimestre
        // (classement_trimestre_classe()) : classement_annuel_classe() n'a pas
        // cette notion, d'où la vérification conditionnelle à $vue.
        if ($l['moy'] === null || ($vue !== 'annee' && !$l['classable'])) continue;
        $moy = (float) $l['moy'];
        $sx  = $sexe_idx[(int) $l['id_eleve']] ?? 'M';
        $incr('classes', $sx);
        $moy_somme[$sx] += $moy; $moy_somme['T'] += $moy;
        $incr($moy >= 10 ? 'moy_ge10' : 'moy_lt10', $sx);
        $tranche = match (true) {
            $moy < 7    => 'tr_0_7',
            $moy < 10   => 'tr_7_10',
            $moy < 12   => 'tr_10_12',
            $moy < 14   => 'tr_12_14',
            $moy < 16   => 'tr_14_16',
            $moy < 18   => 'tr_16_18',
            default     => 'tr_18_20',
        };
        $incr($tranche, $sx);
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
    $b['moy_gen'] = [];
    foreach (['M', 'F', 'T'] as $g) {
        $b['moy_gen'][$g] = $b['classes'][$g] > 0 ? round($moy_somme[$g] / $b['classes'][$g], 2) : null;
    }
    return $b;
}

// ── Statistiques agrégées d'une classe (onglet « Par classe / Résultats ») ──
// Effectif, F/G, moyenne de classe, 1er/dernier, admis, taux — pour la vue
// trimestrielle OU annuelle. Réutilise les classements déjà vérifiés plutôt
// que de recalculer les moyennes inline (contrairement à ABZ_MBE, qui refait
// tout le calcul dans chaque fichier).
// $seqs_override : voir bilan_classe_genre() ci-dessus, même principe.
function stats_classe(int $id_classe, string $val_annee, string $vue = 'trim', int $id_trim = 0, ?array $seqs_override = null): array {
    $classement = $vue === 'annee'
        ? classement_annuel_classe($id_classe, $val_annee)
        : ($seqs_override !== null
            ? classement_sur_sequences($id_classe, $seqs_override, $val_annee)
            : classement_trimestre_classe($id_classe, $id_trim, $val_annee));

    $eleves = db_all(
        "SELECT e.id_eleve, e.Sexe_elv FROM eleve e
         JOIN inscrire i ON i.id_eleve = e.id_eleve
         WHERE i.IDClasses = ? AND i.val_annee = ? AND e.statut = 'actif'",
        [$id_classe, $val_annee]
    );
    $filles = 0; $garcons = 0;
    foreach ($eleves as $e) { stripos($e['Sexe_elv'], 'F') === 0 ? $filles++ : $garcons++; }

    return [
        'nb'      => count($eleves),
        'nb_classes' => $classement['nb_classes'],
        'moy'     => $classement['moy_classe'],
        'premier' => $classement['moy_premier'],
        'dernier' => $classement['moy_dernier'],
        'admis'   => $classement['nb_admis'],
        'taux'    => $classement['taux_reussite'] ?? 0,
        'filles'  => $filles,
        'garcons' => $garcons,
    ];
}

// ── Statistiques par compétence sur un périmètre de classes ─────────
// Équivalent de l'onglet « Par matière » d'ABZ_MBE, mais sur les compétences
// (modèle réel de jaynitaare). Moyenne par élève et par compétence ramenée
// sur 20 via le barème (discipline.total_points), puis agrégée.
// $seqs_override (demande du 18/08/2026) : voir stats_classe()/bilan_classe_
// genre() — même principe, restreint à un ensemble explicite de séquences
// (ex. une seule évaluation) au lieu des 1-2 séquences du trimestre entier.
function stats_par_competence(array $id_classes, string $val_annee, string $vue = 'trim', int $id_trim = 0, ?array $seqs_override = null): array {
    if (empty($id_classes)) return [];
    $seqs = $seqs_override ?? ($vue === 'annee'
        ? array_column(db_all("SELECT s.id_seq FROM sequence s JOIN trimestre t ON t.id_trim = s.id_trim WHERE t.id_annee = ?", [$val_annee]), 'id_seq')
        : sequences_du_trimestre($id_trim));
    if (empty($seqs)) return [];

    $in_c = implode(',', array_fill(0, count($id_classes), '?'));
    $in_s = implode(',', array_fill(0, count($seqs), '?'));
    $rows = db_all(
        "SELECT cs.id_comp, cs.id_eleve, cs.note_total_points, d.total_points, c.nom_comp
         FROM composer_sequence cs
         JOIN discipline d ON d.id_comp = cs.id_comp AND d.IDClasses = cs.IDClasses AND d.annee_scol = cs.val_annee
         JOIN competence c ON c.id_comp = cs.id_comp
         JOIN inscrire i ON i.id_eleve = cs.id_eleve AND i.IDClasses = cs.IDClasses AND i.val_annee = cs.val_annee
         WHERE cs.IDClasses IN ($in_c) AND cs.id_seq IN ($in_s) AND cs.val_annee = ?
               AND d.total_points > 0 AND COALESCE(d.actif, 1) = 1",
        array_merge($id_classes, $seqs, [$val_annee])
    );

    // Moyenne par (compétence, élève) sur les séquences, ramenée sur 20.
    $par_comp = [];
    foreach ($rows as $r) {
        $sur20 = (float) $r['note_total_points'] / (float) $r['total_points'] * 20;
        $par_comp[$r['id_comp']]['nom'] = $r['nom_comp'];
        $par_comp[$r['id_comp']]['vals'][$r['id_eleve']][] = $sur20;
    }
    $stats = [];
    foreach ($par_comp as $info) {
        $moys = [];
        foreach ($info['vals'] as $vs) { $moys[] = array_sum($vs) / count($vs); }
        $nb = count($moys);
        $admis = count(array_filter($moys, fn($m) => $m >= 10));
        $stats[] = [
            'competence' => $info['nom'],
            'nb'   => $nb,
            'moy'  => $nb > 0 ? array_sum($moys) / $nb : null,
            'min'  => $nb > 0 ? min($moys) : null,
            'max'  => $nb > 0 ? max($moys) : null,
            'admis' => $admis,
            'taux' => $nb > 0 ? round($admis / $nb * 100, 1) : 0,
        ];
    }
    usort($stats, fn($a, $b) => strcmp($a['competence'], $b['competence']));
    return $stats;
}

// ── Statistiques par compétence, ventilées par genre (F/M/T) ────────
// Onglet « Par compétence / Évaluation » (renommé depuis « Par compétence /
// Enseignant », demande du 18/08/2026) : Nb évalués/Échoués/Admis/Taux par
// genre, code de la compétence en tête. Contrairement à stats_par_competence()
// (utilisée par l'onglet « Par compétence » agrégé école entière, non
// touché par cette demande), $seqs est une liste EXPLICITE d'id_seq — permet
// au tableau de choisir « Évaluation en cours » (une seule séquence) ou
// « Trimestre » (les 2 séquences du trimestre, comme avant) sans dépendre
// de $vue/$id_trim.
function stats_par_competence_genre(array $id_classes, string $val_annee, array $seqs): array {
    if (empty($id_classes) || empty($seqs)) return [];
    $in_c = implode(',', array_fill(0, count($id_classes), '?'));
    $in_s = implode(',', array_fill(0, count($seqs), '?'));
    $rows = db_all(
        "SELECT cs.id_comp, cs.id_eleve, cs.note_total_points, d.total_points, c.nom_comp, c.code_comp, e.Sexe_elv
         FROM composer_sequence cs
         JOIN discipline d ON d.id_comp = cs.id_comp AND d.IDClasses = cs.IDClasses AND d.annee_scol = cs.val_annee
         JOIN competence c ON c.id_comp = cs.id_comp
         JOIN inscrire i ON i.id_eleve = cs.id_eleve AND i.IDClasses = cs.IDClasses AND i.val_annee = cs.val_annee
         JOIN eleve e ON e.id_eleve = cs.id_eleve
         WHERE cs.IDClasses IN ($in_c) AND cs.id_seq IN ($in_s) AND cs.val_annee = ?
               AND d.total_points > 0 AND COALESCE(d.actif, 1) = 1",
        array_merge($id_classes, $seqs, [$val_annee])
    );

    $par_comp = [];
    foreach ($rows as $r) {
        $sur20 = (float) $r['note_total_points'] / (float) $r['total_points'] * 20;
        $sx = stripos($r['Sexe_elv'], 'F') === 0 ? 'F' : 'M';
        $par_comp[$r['id_comp']]['nom']  ??= $r['nom_comp'];
        $par_comp[$r['id_comp']]['code'] ??= $r['code_comp'];
        // total_points supposé identique pour une même compétence sur les
        // classes regroupées (barème global de l'établissement) — on garde
        // simplement la première valeur rencontrée.
        $par_comp[$r['id_comp']]['bareme'] ??= (int) $r['total_points'];
        $par_comp[$r['id_comp']]['vals'][$r['id_eleve']]['sur20'][] = $sur20;
        $par_comp[$r['id_comp']]['vals'][$r['id_eleve']]['sexe'] = $sx;
    }

    $zero = ['M' => 0, 'F' => 0, 'T' => 0];
    $stats = [];
    foreach ($par_comp as $info) {
        $nb = $zero; $echoues = $zero; $admis = $zero;
        $moy_somme = ['M' => 0.0, 'F' => 0.0, 'T' => 0.0];
        foreach ($info['vals'] as $v) {
            $moy = array_sum($v['sur20']) / count($v['sur20']);
            $sx  = $v['sexe'];
            $nb[$sx]++; $nb['T']++;
            $moy_somme[$sx] += $moy; $moy_somme['T'] += $moy;
            if ($moy >= 10) { $admis[$sx]++; $admis['T']++; } else { $echoues[$sx]++; $echoues['T']++; }
        }
        $moy_gen = []; $taux = [];
        foreach (['M', 'F', 'T'] as $g) {
            $moy_gen[$g] = $nb[$g] > 0 ? round($moy_somme[$g] / $nb[$g], 2) : null;
            $taux[$g]    = $nb[$g] > 0 ? round($admis[$g] / $nb[$g] * 100, 1) : 0;
        }
        $stats[] = [
            'competence' => $info['nom'], 'code' => $info['code'], 'bareme' => $info['bareme'],
            'nb' => $nb, 'echoues' => $echoues, 'admis' => $admis, 'moy' => $moy_gen, 'taux' => $taux,
        ];
    }
    usort($stats, fn($a, $b) => strcmp($a['competence'], $b['competence']));
    return $stats;
}

// ── Statistiques des élèves NON évalués par compétence, ventilées par
// genre (F/M/T) ────────────────────────────────────────────────────
// Onglet « Non évalué » (remplace l'onglet « Par compétence » agrégé école
// entière, demande du 18/08/2026) : pour chaque compétence du périmètre,
// combien d'élèves inscrits/actifs n'ont AUCUNE note sur les séquences
// choisies ($seqs, même convention que stats_par_competence_genre() —
// « Évaluation en cours » ou « Trimestre »). Un élève compte comme
// « évalué » dès qu'il a au moins une ligne composer_sequence pour cette
// compétence sur le périmètre de séquences ; sinon il est « non évalué ».
function stats_non_evalues_par_competence(array $id_classes, string $val_annee, array $seqs): array {
    if (empty($id_classes)) return [];
    $in_c = implode(',', array_fill(0, count($id_classes), '?'));

    $eleves = db_all(
        "SELECT e.id_eleve, e.Sexe_elv FROM eleve e
         JOIN inscrire i ON i.id_eleve = e.id_eleve AND i.IDClasses IN ($in_c) AND i.val_annee = ?
         WHERE e.statut = 'actif'",
        array_merge($id_classes, [$val_annee])
    );
    if (empty($eleves)) return [];
    $sexe_idx = [];
    foreach ($eleves as $e) { $sexe_idx[(int) $e['id_eleve']] = stripos($e['Sexe_elv'], 'F') === 0 ? 'F' : 'M'; }
    $zero = ['M' => 0, 'F' => 0, 'T' => 0];
    $total = $zero;
    foreach ($sexe_idx as $sx) { $total[$sx]++; $total['T']++; }

    $competences = db_all(
        "SELECT DISTINCT d.id_comp, c.nom_comp, c.code_comp, d.total_points
         FROM discipline d JOIN competence c ON c.id_comp = d.id_comp
         WHERE d.IDClasses IN ($in_c) AND d.annee_scol = ? AND d.total_points > 0 AND COALESCE(d.actif, 1) = 1",
        array_merge($id_classes, [$val_annee])
    );
    if (empty($competences) || empty($seqs)) return [];

    $in_s = implode(',', array_fill(0, count($seqs), '?'));
    $rows = db_all(
        "SELECT DISTINCT cs.id_comp, cs.id_eleve FROM composer_sequence cs
         WHERE cs.IDClasses IN ($in_c) AND cs.id_seq IN ($in_s) AND cs.val_annee = ?",
        array_merge($id_classes, $seqs, [$val_annee])
    );
    $evalues = [];
    foreach ($rows as $r) { $evalues[$r['id_comp']][(int) $r['id_eleve']] = true; }

    $stats = [];
    foreach ($competences as $cp) {
        $comp_evalues = $evalues[$cp['id_comp']] ?? [];
        $non_eval = $zero;
        foreach ($sexe_idx as $id_eleve => $sx) {
            if (!isset($comp_evalues[$id_eleve])) { $non_eval[$sx]++; $non_eval['T']++; }
        }
        $taux = [];
        foreach (['M', 'F', 'T'] as $g) $taux[$g] = $total[$g] > 0 ? round($non_eval[$g] / $total[$g] * 100, 1) : 0;
        $stats[] = [
            'competence' => $cp['nom_comp'], 'code' => $cp['code_comp'], 'bareme' => (int) $cp['total_points'],
            'total' => $total, 'non_evalue' => $non_eval, 'taux' => $taux,
        ];
    }
    usort($stats, fn($a, $b) => strcmp($a['competence'], $b['competence']));
    return $stats;
}

// ── Élèves NON pris en compte dans « Élèves évalués » (onglet Par classe/
// Résultats) ──────────────────────────────────────────────────────
// Demande du 18/08/2026, suite : explique l'écart entre l'effectif inscrit
// (Scolarité > Élèves, ex. 179) et « Élèves évalués » (bilan_classe_genre()
// 'classes', ex. 119) — liste, PAR CLASSE, les élèves inscrits/actifs qui
// n'entrent PAS dans ce compte, avec la raison :
//   - « Aucune moyenne calculée » : pas de ligne moyenne_trimestre/
//     moyenne_annuelle pour cet élève (aucune compétence composée, ou
//     élève tout juste transféré/inscrit après le dernier recalcul) ;
//   - « Non classé (participation < 50%) » : moyenne_trimestre.classable=0
//     (migration v37) — trimestre seulement, la vue annuelle n'a pas cette
//     notion (voir classement_annuel_classe()).
// $vue/$id_trim : mêmes conventions que bilan_classe_genre()/stats_classe().
function eleves_non_evalues_classe(int $id_classe, string $val_annee, string $vue = 'trim', int $id_trim = 0): array {
    $inscrits = db_all(
        "SELECT e.id_eleve, e.Mat_elv, e.Nom_elv, e.Prenom_elv, e.Sexe_elv
         FROM eleve e JOIN inscrire i ON i.id_eleve = e.id_eleve
         WHERE i.IDClasses = ? AND i.val_annee = ? AND e.statut = 'actif'
         ORDER BY e.Nom_elv, e.Prenom_elv",
        [$id_classe, $val_annee]
    );
    if (empty($inscrits)) return [];

    $classement = $vue === 'annee'
        ? classement_annuel_classe($id_classe, $val_annee)
        : classement_trimestre_classe($id_classe, $id_trim, $val_annee);
    $idx = [];
    foreach ($classement['lignes'] as $l) { $idx[(int) $l['id_eleve']] = $l; }

    $resultat = [];
    foreach ($inscrits as $e) {
        $l = $idx[(int) $e['id_eleve']] ?? null;
        if ($l === null || $l['moy'] === null) {
            $raison = 'Aucune moyenne calculée';
        } elseif ($vue !== 'annee' && !$l['classable']) {
            $raison = 'Non classé (participation < 50%)';
        } else {
            continue; // évalué/classé — ne fait pas partie de l'écart
        }
        $resultat[] = $e + ['raison' => $raison, 'moy' => $l['moy'] ?? null];
    }
    return $resultat;
}

function note_competence_trimestre(int $id_eleve, int $id_comp, int $id_classe, int $id_trim, string $val_annee): array {
    $seqs = sequences_du_trimestre($id_trim);
    $preload = _cache_composer_sequence_classe()[$id_classe . '|' . $val_annee] ?? null;
    $lire = function (?int $id_seq) use ($id_eleve, $id_comp, $id_classe, $val_annee, $preload): ?float {
        if ($id_seq === null) return null;
        // Évaluation annulée pour cet élève (migration v40) : totalement
        // ignorée, comme s'il n'avait rien composé pour cette séquence — la
        // moyenne de la compétence se rabat alors sur l'AUTRE séquence
        // composée du trimestre (s'il y en a une), jamais divisée par le
        // nombre d'évaluations d'origine.
        if (evaluation_annulee_pour_eleve($id_eleve, $id_seq, $val_annee)) return null;
        if ($preload !== null) {
            // Le cache indexe désormais la ligne complète (voir
            // precharger_notes_sequence_classe(), 21/08/2026) — extraire le
            // seul champ utile ici.
            $v = $preload[$id_eleve . '|' . $id_comp . '|' . $id_seq]['note_total_points'] ?? null;
        } else {
            $v = db_val(
                "SELECT note_total_points FROM composer_sequence WHERE id_eleve=? AND id_comp=? AND IDClasses=? AND id_seq=? AND val_annee=?",
                [$id_eleve, $id_comp, $id_classe, $id_seq, $val_annee]
            );
        }
        return $v !== null ? (float) $v : null;
    };
    $n1 = $lire($seqs[0] ?? null);
    $n2 = $lire($seqs[1] ?? null);

    if ($n1 === null && $n2 === null) {
        $moyenne = null;
    } elseif ($n1 !== null && $n2 !== null) {
        $moyenne = round(($n1 + $n2) / 2, 2);
    } else {
        $moyenne = $n1 ?? $n2;
    }

    return ['note1' => $n1, 'note2' => $n2, 'moyenne' => $moyenne];
}

// ── Détail Orale/Écrite/Pratique/Savoir d'une compétence pour UNE séquence
// (UA) ────────────────────────────────────────────────────────────────
// Port du SELECT direct dans BULLETIN_TRIMESTRIEL.php (jaynitaare legacy) —
// nécessaire pour le tableau UA1/UA2 du bulletin trimestriel (colonnes
// Orale/Écrite/Pratique/Savoir + total), contrairement à
// note_competence_trimestre() qui n'expose que le total déjà agrégé
// (note_total_points). Retourne null si l'élève n'a pas composé cette
// séquence pour cette compétence (aucune ligne composer_sequence). Retourne
// aussi null si l'évaluation est annulée pour cet élève (migration v40) —
// « ni affichée sur le bulletin », demande explicite du 17/08/2026.
function note_detail_competence_sequence(int $id_eleve, int $id_comp, int $id_classe, int $id_seq, string $val_annee): ?array {
    if (evaluation_annulee_pour_eleve($id_eleve, $id_seq, $val_annee)) return null;
    // Consulte le préchargement de classe (precharger_notes_sequence_classe(),
    // qui indexe désormais la ligne complète — 21/08/2026) avant de retomber
    // sur une requête directe — élimine 2 requêtes/compétence sur le
    // bulletin trimestriel (~22-26/élève) quand la classe a été préchargée.
    $preload = _cache_composer_sequence_classe()[$id_classe . '|' . $val_annee] ?? null;
    if ($preload !== null) {
        $ligne = $preload[$id_eleve . '|' . $id_comp . '|' . $id_seq] ?? null;
    } else {
        $ligne = db_one(
            "SELECT note_orale, note_ecrite, note_pratique, note_savoir_etre, note_total_points
             FROM composer_sequence WHERE id_eleve=? AND id_comp=? AND IDClasses=? AND id_seq=? AND val_annee=?",
            [$id_eleve, $id_comp, $id_classe, $id_seq, $val_annee]
        );
    }
    if (!$ligne) return null;
    return [
        'orale'        => $ligne['note_orale'] !== null ? (float) $ligne['note_orale'] : null,
        'ecrite'       => $ligne['note_ecrite'] !== null ? (float) $ligne['note_ecrite'] : null,
        'pratique'     => $ligne['note_pratique'] !== null ? (float) $ligne['note_pratique'] : null,
        'savoir_etre'  => $ligne['note_savoir_etre'] !== null ? (float) $ligne['note_savoir_etre'] : null,
        'total_points' => $ligne['note_total_points'] !== null ? (float) $ligne['note_total_points'] : null,
    ];
}

// ── Moyenne trimestrielle d'un élève ────────────────────────────
// Port de Save_Moyenne_trimestriel_eleve() : pour chaque compétence active,
// moyenne des 1 ou 2 séquences composées, pondérée par son barème
// (total_points), le tout ramené sur 20. Résultat mis en cache dans
// `moyenne_trimestre` (upsert), comme le fait l'original.
// Depuis la migration v37 (demande explicite du 16/08/2026) :
//  - Compétence non composée par l'élève : ZÉRO automatique (bareme compté,
//    0 point) si au moins 50% de la classe a déjà composé cette compétence
//    (taux_participation_competences_trimestre()) — sinon compétence pas
//    encore évaluée par l'enseignant, ignorée comme avant (ni bareme ni
//    points, ne pénalise pas un élève en avance sur une classe pas encore
//    notée).
//  - Éligibilité au classement (`classable`, persistée) : l'élève doit avoir
//    personnellement composé (vraie note, pas un zéro automatique) au moins
//    50% des compétences de la classe — sinon "non classé", voir
//    classement_trimestre_classe() qui exclut alors ce rang/cette ligne des
//    statistiques et de la moyenne de classe même si `moy` existe ci-dessous.
// Depuis la migration v40 (demande explicite du 17/08/2026, remplace
// l'annulation de trimestre de la v37) : une évaluation (séquence) annulée
// pour l'élève exempte, elle aussi, du zéro automatique — voir la boucle
// $exemptee ci-dessous — sans qu'il soit nécessaire d'annuler tout le
// trimestre pour ça.
function calculer_moyenne_trimestre_eleve(int $id_eleve, int $id_trim, int $id_classe, string $val_annee): ?float {
    $competences = competences_classe($id_classe, $val_annee);
    $participation = taux_participation_competences_trimestre($id_classe, $id_trim, $val_annee)['taux'];

    $total_bareme = 0.0;
    $total_points = 0.0;
    $nb_composees = 0;
    foreach ($competences as $c) {
        $id_comp = (int) $c['id_comp'];
        $note = note_competence_trimestre($id_eleve, $id_comp, $id_classe, $id_trim, $val_annee);
        if ($note['moyenne'] !== null) {
            $total_bareme += (float) $c['total_points'];
            $total_points += $note['moyenne'];
            $nb_composees++;
            continue;
        }
        if (($participation[$id_comp] ?? 0.0) >= 0.5) {
            // Zéro automatique — sauf absence justifiée (migration_v38, une
            // seule compétence) OU évaluation annulée (migration_v40, toute la
            // séquence) sur au moins une des séquences du trimestre pour cette
            // compétence : la compétence est alors ignorée pour lui, ni bareme
            // ni points, comme si elle n'était pas encore évaluée.
            $exemptee = false;
            foreach (sequences_du_trimestre($id_trim) as $id_seq_verif) {
                if (absence_justifiee_val($id_eleve, $id_comp, $id_seq_verif, $id_classe, $val_annee)
                    || evaluation_annulee_pour_eleve($id_eleve, $id_seq_verif, $val_annee)) { $exemptee = true; break; }
            }
            if (!$exemptee) {
                $total_bareme += (float) $c['total_points'];
            }
        }
        // Sinon : compétence pas encore évaluée par la classe, ignorée (comportement d'origine).
    }

    $coef = $total_bareme / 20;
    $moyenne = $coef > 0 ? round($total_points / $coef, 2) : null;

    $nb_total_comp = count($competences);
    $classable = $nb_total_comp > 0 && ($nb_composees / $nb_total_comp) >= 0.5;

    // `moy` est NOT NULL (decimal(4,2)) — « pas de moyenne » se traduit par
    // l'absence de ligne, jamais par une valeur NULL/vide écrite en base.
    if ($moyenne === null) {
        db_exec("DELETE FROM moyenne_trimestre WHERE id_eleve=? AND id_trim=? AND classe=? AND val_annee=?",
            [$id_eleve, $id_trim, $id_classe, $val_annee]);
    } else {
        db_exec(
            "INSERT INTO moyenne_trimestre (id_eleve, id_trim, classe, moy, classable, val_annee) VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE moy = VALUES(moy), classable = VALUES(classable)",
            [$id_eleve, $id_trim, $id_classe, $moyenne, $classable ? 1 : 0, $val_annee]
        );
    }

    return $moyenne;
}

// Recalcule toute une classe pour un trimestre (tous les élèves actifs
// inscrits sur l'année active). Renvoie le nombre d'élèves traités.
function recalculer_moyennes_trimestre_classe(int $id_classe, int $id_trim, string $val_annee): int {
    precharger_absences_justifiees_classe($id_classe, $val_annee); // évite N requêtes absence_justifiee (1 par élève × compétence en zéro auto)
    $eleves = db_all(
        "SELECT e.id_eleve FROM eleve e
         JOIN inscrire i ON i.id_eleve = e.id_eleve
         WHERE i.IDClasses = ? AND i.val_annee = ? AND e.statut = 'actif'",
        [$id_classe, $val_annee]
    );
    foreach ($eleves as $e) {
        calculer_moyenne_trimestre_eleve((int) $e['id_eleve'], $id_trim, $id_classe, $val_annee);
    }
    return count($eleves);
}

// ── Classement d'une classe pour un trimestre ───────────────────
// Port de calcul_moyenne_eleve_par_trimestre() : lit le cache
// `moyenne_trimestre`, trie DESC, range avec gestion des ex æquo (on
// remonte jusqu'à 2 positions), taux de réussite (moyenne >= 10).
function classement_trimestre_classe(int $id_classe, int $id_trim, string $val_annee): array {
    // Mémoïsé par (classe, trimestre, année) — trouvé le 21/08/2026 :
    // rang_eleve_trimestre() (bulletin trimestriel, tableau de classement...)
    // rappelle cette fonction une fois PAR ÉLÈVE affiché, et elle recalcule
    // à chaque fois le classement de TOUTE la classe à partir de la même
    // lecture `moyenne_trimestre` — 40 requêtes identiques pour une classe
    // de 40 imprimée en lot. Sûr à mémoïser pour la durée d'une requête
    // HTTP : moyenne_trimestre n'est jamais recalculée par les pages qui
    // consomment cette fonction (bulletins, classement, conseil de classe,
    // relevés — voir liste des appelants) — seul annuler_evaluation_eleve()/
    // retablir_evaluation_eleve() la modifient, depuis une AUTRE page/requête.
    static $cache = [];
    $cle = $id_classe . '|' . $id_trim . '|' . $val_annee;
    if (isset($cache[$cle])) return $cache[$cle];

    // JOIN sur `inscrire` (pas seulement `moyenne_trimestre`) : un élève qui
    // change de classe en cours d'année garde sa moyenne déjà calculée dans
    // moyenne_trimestre.classe (l'ancienne classe, jamais réécrite après
    // coup) — sans ce filtre il apparaîtrait encore dans le classement de la
    // classe qu'il a quittée. Bug réel trouvé en session 5 (id_eleve=123,
    // moyenne_trimestre.classe=4 mais inscrire.IDClasses=5 pour 2025/2026)
    // en construisant le module Statistiques — corrigé ici à la source,
    // profite à toutes les fonctions qui en dépendent (page Classement,
    // classement du bulletin trimestriel, fiche statistique).
    //
    // Bug de tri lexicographique trouvé en session 5 (moyenne_trimestre.moy
    // était VARCHAR : "9.97" > "19.45" comme chaîne) — corrigé à la source
    // par la migration v29 (colonne passée en decimal(4,2)). ORDER BY
    // numérique nu, plus besoin de cast.
    $lignes = db_all(
        "SELECT mt.id_eleve, mt.moy, mt.classable, e.Nom_elv, e.Prenom_elv
         FROM moyenne_trimestre mt
         JOIN eleve e ON e.id_eleve = mt.id_eleve
         JOIN inscrire i ON i.id_eleve = mt.id_eleve AND i.IDClasses = mt.classe AND i.val_annee = mt.val_annee
         WHERE mt.classe = ? AND mt.id_trim = ? AND mt.val_annee = ?
         ORDER BY mt.moy DESC",
        [$id_classe, $id_trim, $val_annee]
    );

    $n = count($lignes);
    $nb_admis = 0;
    $somme = 0.0;
    $nb_classes_val = 0;
    // Classement standard (« 1224 ») : les ex æquo partagent le même rang
    // (celui du premier du groupe), le rang suivant reprend au nombre total
    // de classés déjà vus (jamais 1,2,2,3 — toujours 1,2,2,4). Plus robuste
    // que l'algorithme d'origine (comparaison manuelle à 2-3 positions en
    // arrière, qui ne gère correctement que les groupes de 2 ou 3 ex æquo).
    //
    // Depuis la migration v37 : un élève avec `classable=0` (moins de 50%
    // des compétences personnellement composées, voir calculer_moyenne_
    // trimestre_eleve()) est traité comme "Non classé" ici — sa moyenne
    // reste visible dans `moy` (ex. sur une liste de classe) mais n'entre ni
    // dans le rang, ni dans moy_classe/taux_reussite/1er/dernier ci-dessous,
    // même quand `moy` n'est pas null (zéros automatiques compris).
    $rang_du_groupe = 0;
    $moy_precedente = null;
    $moy_premier = null; $moy_dernier = null; // du sous-ensemble classable uniquement (voir plus bas)
    foreach ($lignes as $i => &$l) {
        $moy = $l['moy'] !== null ? (float) $l['moy'] : null;
        if ($moy === null || !$l['classable']) {
            $l['rang'] = ''; $l['classement'] = 'N.C';
            continue;
        }
        $l['classement'] = 'C';
        $somme += $moy; $nb_classes_val++;
        if ($moy >= 10) $nb_admis++;
        // $lignes est trié DESC sur moy (toute la table, non-classables compris)
        // -> le premier/dernier élève CLASSABLE rencontré en itérant dans cet
        // ordre est bien le 1er/dernier du classement (indexer $lignes[0]/
        // $lignes[$nb_classes_val-1] directement serait faux si un élève
        // non classable se glisse en tête ou avant la fin du tableau brut).
        if ($moy_premier === null) $moy_premier = $moy;
        $moy_dernier = $moy;

        if ($moy_precedente === null || abs($moy - $moy_precedente) > 0.001) {
            $rang_du_groupe = $nb_classes_val;
        }
        $l['rang'] = $rang_du_groupe . ($rang_du_groupe < $nb_classes_val ? 'ex' : 'e');
        $moy_precedente = $moy;
    }
    unset($l);

    return $cache[$cle] = [
        'lignes'        => $lignes,
        'effectif'      => $n,
        'nb_classes'    => $nb_classes_val,
        'nb_admis'      => $nb_admis,
        'taux_reussite' => $nb_classes_val > 0 ? round($nb_admis / $nb_classes_val * 100, 2) : null,
        'moy_classe'    => $nb_classes_val > 0 ? round($somme / $nb_classes_val, 2) : null,
        'moy_premier'   => $moy_premier,
        'moy_dernier'   => $moy_dernier,
    ];
}

// Rang + moyenne d'UN élève dans sa classe pour un trimestre (pour le bulletin).
function rang_eleve_trimestre(int $id_eleve, int $id_classe, int $id_trim, string $val_annee): array {
    $classement = classement_trimestre_classe($id_classe, $id_trim, $val_annee);
    foreach ($classement['lignes'] as $l) {
        if ((int) $l['id_eleve'] === $id_eleve) {
            return [
                'moyenne'       => $l['moy'] !== null ? (float) $l['moy'] : null,
                'rang'          => $l['rang'],
                'classable'     => (bool) $l['classable'],
                'effectif'      => $classement['effectif'],
                'nb_classes'    => $classement['nb_classes'],
                'moy_classe'    => $classement['moy_classe'],
                'moy_premier'   => $classement['moy_premier'],
                'moy_dernier'   => $classement['moy_dernier'],
                'taux_reussite' => $classement['taux_reussite'],
            ];
        }
    }
    return [
        'moyenne' => null, 'rang' => '', 'classable' => false,
        'effectif' => $classement['effectif'], 'nb_classes' => $classement['nb_classes'],
        'moy_classe' => $classement['moy_classe'], 'moy_premier' => $classement['moy_premier'],
        'moy_dernier' => $classement['moy_dernier'], 'taux_reussite' => $classement['taux_reussite'],
    ];
}

// ── Moyenne annuelle ─────────────────────────────────────────────
// Port de Save_Calcule_Moyenne_annuelle() : moyenne simple des trimestres
// dont la moyenne existe déjà en cache (1, 2 ou 3 sur 3), mise en cache
// dans `moyenne_annuelle` (upsert) avec le nombre de trimestres utilisés.
// Depuis la migration v37 (demande explicite du 16/08/2026) : le diviseur
// est TOUJOURS le nombre de trimestres de l'année (3), PAS le nombre de
// trimestres ayant réellement une moyenne — un trimestre sans moyenne (élève
// non classé, ou aucune compétence composée) compte pour 0 dans la somme
// mais reste dans le diviseur. La migration v37 exemptait aussi un trimestre
// ENTIER via trimestre_annule_pour_eleve() ; ce mécanisme n'existe plus
// depuis la migration v40 (remplacé par l'annulation à la granularité de
// l'évaluation, voir evaluation_annulee_pour_eleve() — une évaluation
// annulée influence déjà la moyenne trimestrielle en amont, pas besoin
// d'exempter tout le trimestre ici) : le diviseur est donc simplement
// toujours count(trimestres_de_annee($val_annee)).
function calculer_moyenne_annuelle_eleve(int $id_eleve, int $id_classe, string $val_annee): array {
    $somme = 0.0;
    $nb = 0;
    foreach (trimestres_de_annee($val_annee) as $id_trim) {
        $moy = db_val(
            "SELECT moy FROM moyenne_trimestre WHERE id_eleve=? AND classe=? AND id_trim=? AND val_annee=?",
            [$id_eleve, $id_classe, $id_trim, $val_annee]
        );
        $somme += ($moy !== null && $moy !== '') ? (float) $moy : 0.0;
        $nb++;
    }
    $moyenne = $nb > 0 ? round($somme / $nb, 2) : null;

    // `moy` est NOT NULL (float) — « pas de moyenne » se traduit par
    // l'absence de ligne, jamais par une valeur NULL écrite en base.
    if ($moyenne === null) {
        db_exec("DELETE FROM moyenne_annuelle WHERE id_eleve=? AND classe=? AND val_annee=?",
            [$id_eleve, $id_classe, $val_annee]);
    } else {
        db_exec(
            "INSERT INTO moyenne_annuelle (id_eleve, classe, moy, Nb_trim, val_annee) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE moy = VALUES(moy), Nb_trim = VALUES(Nb_trim)",
            [$id_eleve, $id_classe, $moyenne, $nb, $val_annee]
        );
    }

    return ['moyenne' => $moyenne, 'nb_trimestres' => $nb];
}

// ── Note d'une compétence pour l'année (bulletin annuel) ────────────
// Moyenne des moyennes trimestrielles de cette compétence (1 à 3 valeurs
// disponibles) — même principe que calculer_moyenne_annuelle_eleve() un
// niveau plus bas (par compétence plutôt que la moyenne générale).
//
// Mémoïsée par requête (static $cache) : fonction pure vis-à-vis de la BD
// (aucune écriture), donc sûre à mettre en cache le temps d'une génération
// PDF. Sans ça, le bulletin annuel EN LOT (une classe entière) rappelle
// cette fonction ~(effectif × compétences) fois pour le tableau de chaque
// élève, PLUS une fois par élève de la classe à CHAQUE appel de
// rang_eleve_competence_annuelle() ci-dessous (qui reclasse toute la classe
// pour chaque élève × compétence) — explosion combinatoire O(effectif² ×
// compétences) constatée en conditions réelles (classe de 49, 13
// compétences → PDF de classe entière hors service, minutes au lieu de
// secondes). Le cache ramène le coût réel à O(effectif × compétences),
// chaque triplet (élève, compétence, année) n'étant calculé qu'une fois
// quel que soit le nombre de fois où on le redemande.
function note_competence_annuelle(int $id_eleve, int $id_comp, int $id_classe, string $val_annee): array {
    static $cache = [];
    $cle = $id_eleve . '|' . $id_comp . '|' . $id_classe . '|' . $val_annee;
    if (isset($cache[$cle])) return $cache[$cle];

    $valeurs = [];
    foreach (trimestres_de_annee($val_annee) as $id_trim) {
        $note = note_competence_trimestre($id_eleve, $id_comp, $id_classe, $id_trim, $val_annee);
        if ($note['moyenne'] !== null) $valeurs[$id_trim] = $note['moyenne'];
    }
    $moyenne = $valeurs ? round(array_sum($valeurs) / count($valeurs), 2) : null;
    return $cache[$cle] = ['par_trimestre' => $valeurs, 'moyenne' => $moyenne];
}

// Liste des id_trim d'une année scolaire — mémoïsée (même principe qu'à
// chaque autre fonction de cette section : cette liste est identique pour
// TOUS les élèves/compétences d'une même année, ça ne vaut jamais le coup
// de la requêter deux fois dans la même requête HTTP). Remplace l'appel
// direct à trimestre autrefois dupliqué dans note_competence_annuelle() et
// calculer_moyenne_annuelle_eleve() — même requête, un seul point d'entrée.
function trimestres_de_annee(string $val_annee): array {
    static $cache = [];
    if (isset($cache[$val_annee])) return $cache[$val_annee];
    return $cache[$val_annee] = array_column(
        db_all("SELECT id_trim FROM trimestre WHERE id_annee=? ORDER BY id_trim", [$val_annee]),
        'id_trim'
    );
}

// ── Rang d'un élève sur UNE compétence pour l'année (colonne RANG du
// bulletin annuel) ────────────────────────────────────────────────────
// Port de Rang_elev_par_competance_annuel() (jaynitaare legacy) : classement
// « 1224 » (mêmes règles ex æquo que classement_trimestre_classe()) des
// moyennes annuelles de cette seule compétence parmi les élèves actifs de
// la classe. '' si l'élève n'a pas de moyenne pour cette compétence.
//
// Mémoïsée PAR GROUPE (id_comp/id_classe/val_annee), pas par élève : le
// classement de toute la classe sur cette compétence est calculé UNE SEULE
// fois (premier appel, quel que soit l'élève demandé), les appels suivants
// pour les autres élèves de la même classe/compétence relisent simplement
// le tableau déjà classé — voir le commentaire de note_competence_annuelle()
// ci-dessus pour l'explosion combinatoire que ça évite.
function rang_eleve_competence_annuelle(int $id_eleve, int $id_comp, int $id_classe, string $val_annee): string {
    static $cache = [];
    $cle_groupe = $id_comp . '|' . $id_classe . '|' . $val_annee;
    if (!isset($cache[$cle_groupe])) {
        $eleves = db_all(
            "SELECT e.id_eleve FROM eleve e JOIN inscrire i ON i.id_eleve = e.id_eleve
             WHERE i.IDClasses = ? AND i.val_annee = ? AND e.statut = 'actif'",
            [$id_classe, $val_annee]
        );
        $moyennes = [];
        foreach ($eleves as $e) {
            $note = note_competence_annuelle((int) $e['id_eleve'], $id_comp, $id_classe, $val_annee);
            if ($note['moyenne'] !== null) $moyennes[(int) $e['id_eleve']] = $note['moyenne'];
        }
        arsort($moyennes);
        $rangs = []; $rang_du_groupe = 0; $moy_precedente = null; $i = 0;
        foreach ($moyennes as $id_courant => $moy) {
            $i++;
            if ($moy_precedente === null || abs($moy - $moy_precedente) > 0.001) $rang_du_groupe = $i;
            $rangs[$id_courant] = $rang_du_groupe . ($rang_du_groupe < $i ? 'ex' : 'e');
            $moy_precedente = $moy;
        }
        $cache[$cle_groupe] = $rangs;
    }
    return $cache[$cle_groupe][$id_eleve] ?? '';
}

// ── Classement annuel d'une classe ──────────────────────────────────
// Même logique que classement_trimestre_classe() (cache `moyenne_annuelle`,
// JOIN sur `inscrire` pour ignorer un élève qui a changé de classe en cours
// d'année, classement « 1224 » avec ex æquo).
function classement_annuel_classe(int $id_classe, string $val_annee): array {
    // Mémoïsé par (classe, année) — même correctif que
    // classement_trimestre_classe() (21/08/2026) : rang_eleve_annuel()
    // rappelle cette fonction une fois par élève affiché dans le bulletin
    // annuel, sûr à mémoïser pour la durée d'une requête HTTP (voir le
    // commentaire détaillé sur classement_trimestre_classe()).
    static $cache = [];
    $cle = $id_classe . '|' . $val_annee;
    if (isset($cache[$cle])) return $cache[$cle];

    $lignes = db_all(
        "SELECT ma.id_eleve, ma.moy, ma.Nb_trim, e.Nom_elv, e.Prenom_elv
         FROM moyenne_annuelle ma
         JOIN eleve e ON e.id_eleve = ma.id_eleve
         JOIN inscrire i ON i.id_eleve = ma.id_eleve AND i.IDClasses = ma.classe AND i.val_annee = ma.val_annee
         WHERE ma.classe = ? AND ma.val_annee = ?
         ORDER BY (ma.moy IS NULL) ASC, ma.moy DESC",
        [$id_classe, $val_annee]
    );

    $n = count($lignes);
    $nb_admis = 0; $somme = 0.0; $nb_classes_val = 0;
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

    return $cache[$cle] = [
        'lignes'        => $lignes,
        'effectif'      => $n,
        'nb_classes'    => $nb_classes_val,
        'nb_admis'      => $nb_admis,
        'taux_reussite' => $nb_classes_val > 0 ? round($nb_admis / $nb_classes_val * 100, 2) : null,
        'moy_classe'    => $nb_classes_val > 0 ? round($somme / $nb_classes_val, 2) : null,
        'moy_premier'   => $nb_classes_val > 0 ? (float) $lignes[0]['moy'] : null,
        'moy_dernier'   => $nb_classes_val > 0 ? (float) $lignes[$nb_classes_val - 1]['moy'] : null,
    ];
}

// Rang + moyenne d'UN élève dans sa classe pour l'année (pour le bulletin annuel).
function rang_eleve_annuel(int $id_eleve, int $id_classe, string $val_annee): array {
    $classement = classement_annuel_classe($id_classe, $val_annee);
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

// ── Résultat annuel : statut de promotion + palmarès établissement ──
// jaynitaare n'a pas de table de décision annuelle (decision_conseil,
// migration_v6, est SEULEMENT trimestrielle) — le statut Admis/À redoubler
// est donc calculé automatiquement à partir de la moyenne annuelle (≥10),
// pas d'un enregistrement de décision individuelle. Limitation assumée,
// documentée dans le prompt de continuité — à remplacer par une vraie
// décision annuelle si l'établissement en exprime le besoin.
function statut_promotion(?float $moyenne_annuelle): string {
    if ($moyenne_annuelle === null) return 'Non classé';
    return $moyenne_annuelle >= 10 ? 'Admis' : 'À redoubler';
}

// Résultat annuel d'une classe : classement + statut de promotion par élève.
function resultat_annuel_classe(int $id_classe, string $val_annee): array {
    $classement = classement_annuel_classe($id_classe, $val_annee);
    $admis = 0; $a_redoubler = 0;
    foreach ($classement['lignes'] as &$l) {
        $l['statut'] = statut_promotion($l['moy'] !== null ? (float) $l['moy'] : null);
        if ($l['statut'] === 'Admis') $admis++;
        elseif ($l['statut'] === 'À redoubler') $a_redoubler++;
    }
    unset($l);
    $classement['admis'] = $admis;
    $classement['a_redoubler'] = $a_redoubler;
    return $classement;
}

// Palmarès établissement : les N meilleures moyennes annuelles toutes
// classes confondues (même correctif d'inscription que classement_annuel_classe :
// un élève ne compte que dans SA classe actuelle).
function palmares_annuel_etablissement(string $val_annee, int $limite = 10): array {
    return db_all(
        "SELECT ma.id_eleve, ma.moy, ma.classe, c.DesignationClasses, e.Nom_elv, e.Prenom_elv
         FROM moyenne_annuelle ma
         JOIN eleve e ON e.id_eleve = ma.id_eleve
         JOIN classe c ON c.IDClasses = ma.classe
         JOIN inscrire i ON i.id_eleve = ma.id_eleve AND i.IDClasses = ma.classe AND i.val_annee = ma.val_annee
         WHERE ma.val_annee = ?
         ORDER BY ma.moy DESC
         LIMIT ?",
        [$val_annee, $limite]
    );
}
