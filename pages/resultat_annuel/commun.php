<?php
// ── Résultat annuel — calcul partagé (port d'ABZ_MBE, adapté au schéma
// jaynitaare) ─────────────────────────────────────────────────────────
// Utilisé par index.php, pdf.php, excel.php, pdf_provisoire.php,
// excel_provisoire.php — jamais de divergence entre l'écran et les exports.
//
// Décision de fin d'année : reprend decision_conseil_annuel (migration_v8)
// si déjà enregistrée ; sinon règle par défaut demandée explicitement par
// l'utilisateur lors du chantier initial (Admis si moyenne annuelle ≥10,
// Redoublement sinon — jamais Exclu/Abandon par défaut, décisions qui
// exigent une saisie explicite). Moyennes T1/T2/T3 lues depuis le cache
// `moyenne_trimestre` (déjà calculé/vérifié), pas recalculées inline.
require_once __DIR__ . '/../../notes_apc.php';

// Moyennes (T1/T2/T3 + annuelle), heures d'absence non justifiées et
// identité de chaque élève actif d'UNE classe, SANS rang ni décision.
// @return array eid => ['eleve'=>ligne eleve, 'id_classe'=>int, 'moy_t'=>[?float,?float,?float], 'moy_annuelle'=>?float, 'abs_nj'=>int]
function resultat_annuel_donnees_classe(int $id_classe, string $val_annee): array {
    $trimestres = db_all("SELECT id_trim FROM trimestre WHERE id_annee=? ORDER BY id_trim", [$val_annee]);
    $trim_ids = array_pad(array_column($trimestres, 'id_trim'), 3, null);

    $eleves = db_all(
        "SELECT e.* FROM eleve e
         JOIN inscrire i ON i.id_eleve=e.id_eleve AND i.IDClasses=? AND i.val_annee=?
         WHERE e.statut='actif' ORDER BY e.Nom_elv, e.Prenom_elv",
        [$id_classe, $val_annee]
    );

    $moys_par_trim = []; // [i] => id_eleve => moyenne
    foreach ($trim_ids as $i => $id_trim) {
        $moys_par_trim[$i] = [];
        if (!$id_trim) continue;
        $rows = db_all("SELECT id_eleve, moy FROM moyenne_trimestre WHERE classe=? AND id_trim=? AND val_annee=?", [$id_classe, $id_trim, $val_annee]);
        foreach ($rows as $r) { if ($r['moy'] !== null && $r['moy'] !== '') $moys_par_trim[$i][(int) $r['id_eleve']] = (float) $r['moy']; }
    }

    $abs_idx = [];
    $rows = db_all("SELECT id_eleve, SUM(nbre_jour_non_jus) AS nj FROM absence WHERE classe=? AND val_annee=? GROUP BY id_eleve", [$id_classe, $val_annee]);
    foreach ($rows as $r) $abs_idx[(int) $r['id_eleve']] = (int) $r['nj'];

    $moy_an_idx = [];
    $rows = db_all("SELECT id_eleve, moy FROM moyenne_annuelle WHERE classe=? AND val_annee=?", [$id_classe, $val_annee]);
    foreach ($rows as $r) $moy_an_idx[(int) $r['id_eleve']] = (float) $r['moy'];

    $donnees = [];
    foreach ($eleves as $el) {
        $eid = (int) $el['id_eleve'];
        $moy_t = [$moys_par_trim[0][$eid] ?? null, $moys_par_trim[1][$eid] ?? null, $moys_par_trim[2][$eid] ?? null];
        $donnees[$eid] = [
            'eleve'        => $el,
            'id_classe'    => $id_classe,
            'moy_t'        => $moy_t,
            'moy_annuelle' => $moy_an_idx[$eid] ?? null,
            'abs_nj'       => $abs_idx[$eid] ?? 0,
        ];
    }
    return $donnees;
}

function resultat_annuel_decision(?array $decision_row, ?float $moy_annuelle): array {
    if ($decision_row) {
        return [
            'decision' => $decision_row['decision'], 'notes' => $decision_row['observation'] ?? '', 'auto' => false,
            'next_classe_id' => !empty($decision_row['next_classe']) ? (int) $decision_row['next_classe'] : null,
        ];
    }
    if ($moy_annuelle === null) {
        return ['decision' => 'Non classé', 'notes' => '', 'auto' => true, 'next_classe_id' => null];
    }
    return ['decision' => $moy_annuelle >= 10 ? 'Admis' : 'Redoublement', 'notes' => '', 'auto' => true, 'next_classe_id' => null];
}

function resultat_annuel_classes_map(): array {
    static $map = null;
    if ($map === null) $map = array_column(db_all("SELECT IDClasses, DesignationClasses FROM classe"), 'DesignationClasses', 'IDClasses');
    return $map;
}

function resultat_annuel_classe_suivante(string $decision, ?int $next_classe_id, int $id_classe_actuelle): string {
    $map = resultat_annuel_classes_map();
    if ($decision === 'Redoublement') return $map[$id_classe_actuelle] ?? '—';
    if ($decision === 'Admis') return $next_classe_id !== null ? ($map[$next_classe_id] ?? '—') : '(à définir)';
    return '—';
}

// Rang calculé localement parmi les élèves classables de CETTE classe.
// $ordre contrôle uniquement l'ordre d'AFFICHAGE ; le rang reste toujours
// calculé au mérite.
function resultat_annuel_lignes_classe(int $id_classe, string $val_annee, string $ordre = 'merite'): array {
    $donnees = resultat_annuel_donnees_classe($id_classe, $val_annee);

    $moys = [];
    foreach ($donnees as $eid => $d) if ($d['moy_annuelle'] !== null) $moys[$eid] = $d['moy_annuelle'];
    arsort($moys);
    $rangs = []; $rg = 1;
    foreach ($moys as $eid => $m) $rangs[$eid] = $rg++;
    $nb_classes_ = count($moys);

    $decisions = db_all("SELECT * FROM decision_conseil_annuel WHERE classe=? AND val_annee=?", [$id_classe, $val_annee]);
    $decisions_idx = [];
    foreach ($decisions as $d) $decisions_idx[(int) $d['id_eleve']] = $d;

    $lignes = [];
    foreach ($donnees as $eid => $d) {
        $dec = resultat_annuel_decision($decisions_idx[$eid] ?? null, $d['moy_annuelle']);
        $lignes[] = array_merge($d, [
            'rang' => $rangs[$eid] ?? null, 'nb_classes' => $nb_classes_,
            'decision' => $dec['decision'], 'notes' => $dec['notes'], 'decision_auto' => $dec['auto'],
            'classe_suivante' => resultat_annuel_classe_suivante($dec['decision'], $dec['next_classe_id'], $id_classe),
        ]);
    }
    usort($lignes, $ordre === 'alpha' ? 'resultat_annuel_cmp_alpha' : 'resultat_annuel_cmp');
    return $lignes;
}

// Agrège toutes les classes de l'année, rang calculé GLOBALEMENT, tronqué
// aux $limite meilleures moyennes (0 = toutes). Le "Top N" est toujours
// déterminé au mérite AVANT tronquage.
function resultat_annuel_lignes_etablissement(string $val_annee, int $limite, string $ordre = 'merite'): array {
    $classes = db_all(
        "SELECT c.IDClasses, c.DesignationClasses FROM classe c
         JOIN inscrire i ON i.IDClasses=c.IDClasses AND i.val_annee=?
         GROUP BY c.IDClasses, c.DesignationClasses",
        [$val_annee]
    );

    $toutes = [];
    $decisions_idx = []; // [id_classe][id_eleve] => ligne
    foreach ($classes as $c) {
        $id_classe = (int) $c['IDClasses'];
        $donnees = resultat_annuel_donnees_classe($id_classe, $val_annee);
        foreach ($donnees as $eid => $d) {
            $d['classe'] = $c['DesignationClasses'];
            $toutes[$eid . '_' . $id_classe] = $d;
        }
        $decisions = db_all("SELECT * FROM decision_conseil_annuel WHERE classe=? AND val_annee=?", [$id_classe, $val_annee]);
        foreach ($decisions as $dec) $decisions_idx[$id_classe][(int) $dec['id_eleve']] = $dec;
    }

    $moys = [];
    foreach ($toutes as $k => $d) if ($d['moy_annuelle'] !== null) $moys[$k] = $d['moy_annuelle'];
    arsort($moys);
    $rangs = []; $rg = 1;
    foreach ($moys as $k => $m) $rangs[$k] = $rg++;
    $nb_classes_ = count($moys);

    $lignes = [];
    foreach ($toutes as $k => $d) {
        $eid = (int) $d['eleve']['id_eleve'];
        $dec_row = $decisions_idx[$d['id_classe']][$eid] ?? null;
        $dec = resultat_annuel_decision($dec_row, $d['moy_annuelle']);
        $lignes[] = array_merge($d, [
            'rang' => $rangs[$k] ?? null, 'nb_classes' => $nb_classes_,
            'decision' => $dec['decision'], 'notes' => $dec['notes'], 'decision_auto' => $dec['auto'],
            'classe_suivante' => resultat_annuel_classe_suivante($dec['decision'], $dec['next_classe_id'], $d['id_classe']),
        ]);
    }
    usort($lignes, 'resultat_annuel_cmp');

    if ($limite > 0) {
        $lignes = array_values(array_filter($lignes, fn($l) => $l['moy_annuelle'] !== null));
        $lignes = array_slice($lignes, 0, $limite);
    }
    if ($ordre === 'alpha') usort($lignes, 'resultat_annuel_cmp_alpha');
    return $lignes;
}

function resultat_annuel_cmp(array $a, array $b): int {
    if ($a['moy_annuelle'] === null && $b['moy_annuelle'] === null) {
        return strcmp($a['eleve']['Nom_elv'] . $a['eleve']['Prenom_elv'], $b['eleve']['Nom_elv'] . $b['eleve']['Prenom_elv']);
    }
    if ($a['moy_annuelle'] === null) return 1;
    if ($b['moy_annuelle'] === null) return -1;
    return $b['moy_annuelle'] <=> $a['moy_annuelle'];
}
function resultat_annuel_cmp_alpha(array $a, array $b): int {
    return strcmp($a['eleve']['Nom_elv'] . ' ' . ($a['eleve']['Prenom_elv'] ?? ''), $b['eleve']['Nom_elv'] . ' ' . ($b['eleve']['Prenom_elv'] ?? ''));
}

function resultat_annuel_filtrer(array $lignes, string $filtre): array {
    $map = ['admis' => 'Admis', 'redoublants' => 'Redoublement', 'exclus' => 'Exclu'];
    if (!isset($map[$filtre])) return $lignes;
    return array_values(array_filter($lignes, fn($l) => $l['decision'] === $map[$filtre]));
}

function resultat_annuel_libelle_annee_suivante(string $val_annee): string {
    if (preg_match('/^(\d{4})\/(\d{4})$/', $val_annee, $m)) {
        return ((int) $m[1] + 1) . '/' . ((int) $m[2] + 1);
    }
    return $val_annee . ' (suivante)';
}

// ── Validation / Passage en classe supérieure (onglet=validation) ──────
// Matérialise, pour les élèves d'UNE classe qui n'ont pas encore de
// décision explicite du Conseil de Classe (decision_conseil_annuel), la
// règle par défaut déjà utilisée à l'écran (resultat_annuel_decision() —
// moyenne annuelle >= 10 = Admis, sinon Redoublement) en une vraie ligne
// decision_conseil_annuel. Ne touche JAMAIS un élève qui a déjà une
// décision (Conseil de Classe reste toujours prioritaire) — cette fonction
// ne fait que compléter les manquantes, jamais écraser. next_classe =
// classe.classe_suivante configurée pour les Admis (null si pas configurée
// — le "(à définir)" habituel apparaît alors dans la Liste provisoire).
// @return array ['inscrits'=>int admis+redoublement matérialisés, 'sans_classe_suivante'=>int admis sans destination configurée]
function resultat_annuel_valider_classe(int $id_classe, string $val_annee): array {
    $classe_suiv = (int) (db_val("SELECT classe_suivante FROM classe WHERE IDClasses=?", [$id_classe]) ?? 0) ?: null;

    $deja = db_all("SELECT id_eleve FROM decision_conseil_annuel WHERE classe=? AND val_annee=?", [$id_classe, $val_annee]);
    $deja_idx = array_flip(array_column($deja, 'id_eleve'));

    $donnees = resultat_annuel_donnees_classe($id_classe, $val_annee);

    $nb_materialises = 0; $nb_sans_destination = 0;
    foreach ($donnees as $eid => $d) {
        if (isset($deja_idx[$eid])) continue; // décision déjà enregistrée — jamais écrasée
        if ($d['moy_annuelle'] === null) continue; // pas de moyenne calculable, aucune décision automatique possible

        $decision = $d['moy_annuelle'] >= 10 ? 'Admis' : 'Redoublement';
        $next_classe = null;
        if ($decision === 'Admis') {
            $next_classe = $classe_suiv;
            if ($next_classe === null) $nb_sans_destination++;
        }
        db_exec(
            "INSERT INTO decision_conseil_annuel (id_eleve, classe, val_annee, decision, next_classe, observation)
             VALUES (?, ?, ?, ?, ?, 'Validation automatique — moyenne annuelle')",
            [$eid, $id_classe, $val_annee, $decision, $next_classe]
        );
        $nb_materialises++;
    }
    return ['inscrits' => $nb_materialises, 'sans_classe_suivante' => $nb_sans_destination];
}

// Effectif PRÉVISIONNEL d'une classe pour l'année suivante : redoublants DE
// cette classe (statut RED) + élèves Admis de n'importe quelle classe dont
// la décision (déjà enregistrée) précise cette classe comme classe
// suivante (statut NV). Ne repose QUE sur des décisions déjà enregistrées.
function resultat_annuel_provisoire_classe(int $id_classe_cible, string $val_annee, string $ordre = 'alpha'): array {
    $redoublants = db_all(
        "SELECT e.* FROM decision_conseil_annuel dc
         JOIN eleve e ON e.id_eleve = dc.id_eleve AND e.statut='actif'
         WHERE dc.val_annee=? AND dc.decision='Redoublement' AND dc.classe=?",
        [$val_annee, $id_classe_cible]
    );
    $entrants = db_all(
        "SELECT e.*, dc.classe AS provenance_id FROM decision_conseil_annuel dc
         JOIN eleve e ON e.id_eleve = dc.id_eleve AND e.statut='actif'
         WHERE dc.val_annee=? AND dc.decision='Admis' AND dc.next_classe=?",
        [$val_annee, $id_classe_cible]
    );

    $classes_origine = array_map(fn($e) => (int) $e['provenance_id'], $entrants);
    if ($redoublants) $classes_origine[] = $id_classe_cible;
    $moys_par_classe = [];
    foreach (array_unique($classes_origine) as $idc) {
        $donnees = resultat_annuel_donnees_classe($idc, $val_annee);
        foreach ($donnees as $eid => $d) $moys_par_classe[$eid] = $d['moy_annuelle'];
    }

    $lignes = [];
    foreach ($redoublants as $e) {
        $eid = (int) $e['id_eleve'];
        $lignes[] = ['eleve' => $e, 'statut' => 'Redoublant', 'statut_code' => 'RED', 'moy_annuelle' => $moys_par_classe[$eid] ?? null];
    }
    foreach ($entrants as $e) {
        $eid = (int) $e['id_eleve'];
        $lignes[] = ['eleve' => $e, 'statut' => 'Admis', 'statut_code' => 'NV', 'moy_annuelle' => $moys_par_classe[$eid] ?? null];
    }
    usort($lignes, $ordre === 'merite' ? 'resultat_annuel_cmp' : 'resultat_annuel_cmp_alpha');
    return $lignes;
}
