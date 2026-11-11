<?php
// secondaire/commun/pages/analyse/_analyse_lib.php — Analyse des
// performances (10/11/2026), primaire et secondaire. AUCUNE règle de calcul
// nouvelle : les moyennes viennent des moteurs des bulletins et des
// statistiques (calc_moys_classe_periode_comp / pv_* au secondaire,
// calculer_moyenne_trimestre_eleve / calculer_moyenne_annuelle_eleve au
// primaire) — l'analyse est donc toujours d'accord avec les bulletins.
require_once __DIR__ . '/../../../../noyau/partage/ecole_donnees.php';
if (!ed_secondaire()) require_once __DIR__ . '/../../../../primaire/notes_apc.php';

function an_roles(): array {
    return ed_secondaire() ? ['ADMIN', 'PROVISEUR', 'FONDATEUR', 'CENSEUR', 'SG'] : ['DIRECTEUR', 'FONDATEUR', 'SECRETAIRE'];
}

/** Périodes : trimestres de l'année active + année entière. [['cle' => 't12' | 'annee', 'id' => int, 'libelle']]. */
function an_periodes(): array {
    $an = ed_annee(); $out = [];
    $rows = ed_secondaire()
        ? db_all("SELECT id, libelle FROM trimestre WHERE id_annee=? ORDER BY ordre", [$an['cle']])
        : db_all("SELECT id_trim AS id, libelle_trim AS libelle FROM trimestre WHERE id_annee=? ORDER BY id_trim", [$an['cle']]);
    foreach ($rows as $r) $out[] = ['cle' => 't' . $r['id'], 'id' => (int) $r['id'], 'libelle' => (string) $r['libelle']];
    $out[] = ['cle' => 'annee', 'id' => 0, 'libelle' => 'Année entière'];
    return $out;
}

/** Moyennes générales des élèves d'une classe pour une période : [id_eleve => moyenne]. */
function an_moyennes(int $id_classe, array $periode): array {
    $ids = array_map('intval', array_column(ed_eleves($id_classe), 'id'));
    if (!$ids) return [];
    $an = ed_annee();
    try {
        if (ed_secondaire()) {
            $m = calc_moys_classe_periode_comp($id_classe, (int) $an['cle'], $periode['cle'] === 'annee' ? 'annee' : 'trimestre', (int) $periode['id'], $ids);
            return array_map('floatval', array_filter($m, fn($v) => $v !== null));
        }
        $out = [];
        foreach ($ids as $eid) {
            $v = $periode['cle'] === 'annee'
                ? (calculer_moyenne_annuelle_eleve($eid, $id_classe, (string) $an['cle'])['moyenne'] ?? null)
                : calculer_moyenne_trimestre_eleve($eid, (int) $periode['id'], $id_classe, (string) $an['cle']);
            if ($v !== null) $out[$eid] = (float) $v;
        }
        return $out;
    } catch (\Throwable $e) { return []; }
}

/** Indicateurs d'une série de moyennes. */
function an_indicateurs(array $moys): array {
    $n = count($moys);
    if (!$n) return ['n' => 0, 'moy' => null, 'taux' => null, 'max' => null, 'min' => null, 'ecart' => null, 'admis' => 0];
    $moy = array_sum($moys) / $n;
    $var = array_sum(array_map(fn($v) => ($v - $moy) ** 2, $moys)) / $n;
    $admis = count(array_filter($moys, fn($v) => $v >= 10));
    return ['n' => $n, 'moy' => $moy, 'taux' => 100 * $admis / $n, 'max' => max($moys), 'min' => min($moys), 'ecart' => sqrt($var), 'admis' => $admis];
}

/** Répartition par tranches de notes. */
function an_tranches(array $moys): array {
    $t = ['0–5' => [0, 5], '5–8' => [5, 8], '8–10' => [8, 10], '10–12' => [10, 12], '12–14' => [12, 14], '14–16' => [14, 16], '16–20' => [16, 20.01]];
    $out = [];
    foreach ($t as $lib => [$a, $b]) $out[$lib] = count(array_filter($moys, fn($v) => $v >= $a && $v < $b));
    return $out;
}

/**
 * Résultats par matière (secondaire, période = un trimestre) :
 * [['matiere', 'enseignant', 'notes', 'moyenne', 'min', 'max', 'admis', 'taux', 'ecart']], triés par taux croissant.
 */
function an_matieres(int $id_classe, array $periode): array {
    if (!ed_secondaire() || $periode['cle'] === 'annee' || est_technique()) return [];
    $an = ed_annee();
    try {
        $d = pv_charger_donnees_comp($id_classe, (int) $periode['id'], (int) $an['cle']);
        $libs = [];
        foreach (db_all("SELECT id, libelle FROM matiere") as $m) $libs[(int) $m['id']] = $m['libelle'];
        $ens = [];
        foreach (db_all("SELECT ds.id_mat, GROUP_CONCAT(UPPER(e.nom_ens) SEPARATOR ', ') n FROM dispenser ds JOIN enseignant e ON e.matricule_ens=ds.matricule_ens WHERE ds.IDClasses=? AND ds.val_annee=? GROUP BY ds.id_mat", [$id_classe, $an['libelle']]) as $r) $ens[(int) $r['id_mat']] = $r['n'];
        $ids = array_map('intval', array_column(ed_eleves($id_classe), 'id'));
        $out = [];
        foreach ($d['disciplines'] as $disc) {
            $id_mat = (int) $disc['id_mat'];
            $vals = [];
            foreach ($ids as $eid) {
                $v = pv_moy_matiere_comp($eid, $d['competences_par_mat'][$id_mat] ?? [], $d['notes_idx'], $d['notes_count'], $d['nb_inscrits']);
                if ($v !== null) $vals[] = (float) $v;
            }
            if (!$vals) continue;
            $i = an_indicateurs($vals);
            $out[] = ['matiere' => $libs[$id_mat] ?? '?', 'enseignant' => $ens[$id_mat] ?? '—', 'notes' => $i['n'], 'moyenne' => $i['moy'],
                      'min' => $i['min'], 'max' => $i['max'], 'admis' => $i['admis'], 'taux' => $i['taux'], 'ecart' => $i['ecart']];
        }
        usort($out, fn($a, $b) => $a['taux'] <=> $b['taux'] ?: $a['moyenne'] <=> $b['moyenne']);
        return $out;
    } catch (\Throwable $e) { return []; }
}

/** Classes du même niveau que $id_classe (pour la comparaison). */
function an_classes_niveau(int $id_classe): array {
    $classes = ed_classes();
    try {
        $niv = ed_secondaire()
            ? db_val("SELECT code_niveau FROM classe WHERE id=?", [$id_classe])
            : db_val("SELECT Niveau FROM classe WHERE IDClasses=?", [$id_classe]);
        $memes = ed_secondaire()
            ? array_map('intval', array_column(db_all("SELECT id FROM classe WHERE code_niveau=?", [$niv]), 'id'))
            : array_map('intval', array_column(db_all("SELECT IDClasses AS id FROM classe WHERE Niveau=?", [$niv]), 'id'));
        return array_values(array_filter($classes, fn($c) => in_array((int) $c['id'], $memes, true)));
    } catch (\Throwable $e) { return $classes; }
}

function an_fmt(?float $v, int $dec = 2): string {
    return $v === null ? '—' : number_format($v, $dec, ',', ' ');
}
