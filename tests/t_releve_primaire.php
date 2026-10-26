<?php
// Relevé de notes du primaire (vierge / rempli, pistes française et arabe) :
// colonnes du barème APC, notes saisies, cote. Notes de test insérées puis annulées.
require __DIR__ . '/_amorce.php';
require_once 'primaire/notes_apc.php';
require_once 'primaire/notes_apc_arabe.php';

/** Génère le PDF dans ce processus (transaction visible) et renvoie son texte. */
function releve_texte(array $get): string {
    $_GET = $get; $_REQUEST = $get;
    $_SERVER['SCRIPT_NAME'] = '/SIGES/primaire/pdf/releve_notes_evaluation.php';
    ob_start();
    include __DIR__ . '/../primaire/pdf/releve_notes_evaluation.php';
    $pdf = (string) ob_get_clean();
    $_SERVER['SCRIPT_NAME'] = '/SIGES/association/tests.php';
    if (!str_starts_with($pdf, '%PDF')) return 'PAS UN PDF : ' . substr(strip_tags($pdf), 0, 200);
    preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $m);
    $t = '';
    foreach ($m[1] as $s) { $u = @gzuncompress($s); $t .= $u !== false ? $u : ''; }
    preg_match_all('/\((.*?)\) ?Tj/s', $t, $x);
    return implode(' | ', array_map(fn($s) => stripcslashes($s), $x[1]));
}

foreach (assoc_all("SELECT code FROM etablissement WHERE actif=1 AND type_enseignement='primaire' ORDER BY id") as $e) {
    $code = $e['code'];
    ecole($code);
    $u = db_one("SELECT u.* FROM user u JOIN enseignant e ON e.matricule_ens=u.matricule_ens WHERE e.id_fonction='DIRECTEUR' LIMIT 1");
    if (!$u) continue;
    $_SESSION['user_id'] = $u['id_user'];
    $_SESSION['user'] = ['id' => $u['id_user'], 'matricule_ens' => $u['matricule_ens'], 'nom' => '', 'prenom' => '', 'role' => 'DIRECTEUR', 'login' => $u['login_user'], 'compte_systeme' => false];
    $va = (string) get_annee_active()['val_annee'];
    // une classe avec élèves, compétences (fr) et matières arabes avec barème
    $cl = null;
    foreach (db_all("SELECT IDClasses FROM inscrire WHERE val_annee=? GROUP BY IDClasses HAVING COUNT(*) >= 3", [$va]) as $r)
        if (competences_classe((int) $r['IDClasses'], $va) && array_filter(matieres_classe_arabe_avec_bareme((int) $r['IDClasses'], $va), fn($m) => $m['bareme'])) { $cl = (int) $r['IDClasses']; break; }
    if (!$cl) continue;
    $seq = (int) db_val("SELECT s.id_seq FROM sequence s JOIN trimestre t ON t.id_trim=s.id_trim WHERE t.id_annee=? ORDER BY s.id_seq LIMIT 1", [$va]);
    $comp = competences_classe($cl, $va)[0];
    $mat = array_values(array_filter(matieres_classe_arabe_avec_bareme($cl, $va), fn($m) => $m['bareme']))[0];
    $els = array_column(db_all("SELECT e.id_eleve FROM eleve e JOIN inscrire i ON i.id_eleve=e.id_eleve WHERE i.IDClasses=? AND i.val_annee=? ORDER BY e.Nom_elv, e.Prenom_elv LIMIT 2", [$cl, $va]), 'id_eleve');
    echo "  ($code, classe $cl, compétence {$comp['id_comp']} barème O{$comp['orale']}/É{$comp['ecrite']}/P{$comp['pratique']}/SE{$comp['savoir_etre']}={$comp['total_points']})\n";

    $t = releve_texte(['piste' => 'fr', 'classe' => $cl]);
    ok(str_contains($t, 'RELEV') && str_contains($t, 'Orale') && str_contains($t, 'Savoir') && str_contains($t, 'Cote'), 'vierge générique (fr) : 4 critères, total, cote');
    $t = releve_texte(['piste' => 'fr', 'classe' => $cl, 'item' => $comp['id_comp'], 'seq' => $seq]);
    ok(str_contains($t, pdf_u($comp['nom_comp'])) || str_contains($t, mb_substr(pdf_u($comp['nom_comp']), 0, 20)), 'vierge d\'une compétence : son libellé en en-tête');
    ok(str_contains($t, '/ ' . rtrim(rtrim(number_format((float) $comp['total_points'], 2, ',', ''), '0'), ',') . ' |'), 'vierge d\'une compétence : barème rappelé (total / ' . (float) $comp['total_points'] . ')');

    test_transaction(function () use ($cl, $seq, $comp, $mat, $els, $va) {
        // élève 1 : notes = barème complet ; élève 2 : moitié (cote NA)
        foreach ($els as $k => $id) {
            $f = $k === 0 ? 1 : 0.5;
            db_exec("REPLACE INTO composer_sequence (id_eleve,id_comp,IDClasses,id_seq,val_annee,note_orale,note_ecrite,note_pratique,note_savoir_etre,note_total_points) VALUES (?,?,?,?,?,?,?,?,?,?)",
                    [$id, $comp['id_comp'], $cl, $seq, $va, $comp['orale'] * $f, $comp['ecrite'] * $f, $comp['pratique'] * $f, $comp['savoir_etre'] * $f, $comp['total_points'] * $f]);
            db_exec("REPLACE INTO composer_sequence_arabe (id_eleve,id_mat,classe,id_seq,note_orale,note_ecrite,note_pratique) VALUES (?,?,?,?,?,?,?)",
                    [$id, $mat['id_mat'], $cl, $seq, $mat['bareme']['orale'] * $f, $mat['bareme']['ecrite'] * $f, $mat['bareme']['pratique'] * $f]);
        }
        $t = releve_texte(['piste' => 'fr', 'classe' => $cl, 'item' => $comp['id_comp'], 'seq' => $seq, 'mode' => 'rempli']);
        $tot = rtrim(rtrim(number_format((float) $comp['total_points'], 2, ',', ''), '0'), ',');
        ok(str_contains($t, "| $tot | A+") && str_contains($t, '| NA'), "rempli (fr) : total $tot et cote A+ pour l'élève au barème plein, NA pour la moitié");
        $t = releve_texte(['piste' => 'ar', 'classe' => $cl, 'item' => $mat['id_mat'], 'seq' => $seq, 'mode' => 'rempli']);
        $tot = rtrim(rtrim(number_format((float) $mat['bareme']['total_points'], 2, ',', ''), '0'), ',');
        ok(str_contains($t, '(ARABE)') && str_contains($t, 'Oral') && !str_contains($t, 'Savoir') && str_contains($t, "| $tot | A+"), "rempli (arabe) : Oral/Écrit/Pratique, total $tot, cote A+");
        // Toute l'année : seule l'UA1 du T1 est notée → note du T1 = note annuelle = cette note
        $tot = rtrim(rtrim(number_format((float) $comp['total_points'], 2, ',', ''), '0'), ',');
        $t = releve_texte(['piste' => 'fr', 'classe' => $cl, 'item' => $comp['id_comp'], 'seq' => 'annee', 'mode' => 'rempli']);
        ok(str_contains($t, 'Annuelle') && str_contains($t, 'Moy.') && str_contains($t, pdf_u("Toute l'année")), 'année (fr) : colonnes par trimestre, Moy., Annuelle');
        ok(str_contains($t, "| $tot | $tot | $tot | A+"), "année (fr) : UA1 $tot → T1 $tot → annuelle $tot, cote A+");
        $crit = [];
        foreach (['orale', 'ecrite', 'pratique', 'savoir_etre'] as $k) if ((float) $comp[$k] > 0) $crit[] = rtrim(rtrim(number_format((float) $comp[$k], 2, ',', ''), '0'), ',');
        ok(str_contains($t, '| ' . implode(' | ', $crit) . " | $tot |"), 'année (fr) : détail des critères de l\'UA1 (' . implode(', ', $crit) . ") puis son total");
        $tot_ar = rtrim(rtrim(number_format((float) $mat['bareme']['total_points'], 2, ',', ''), '0'), ',');
        $t = releve_texte(['piste' => 'ar', 'classe' => $cl, 'item' => $mat['id_mat'], 'seq' => 'annee', 'mode' => 'rempli']);
        ok(str_contains($t, "| $tot_ar | $tot_ar | $tot_ar | A+"), "année (arabe) : UA1 $tot_ar → T1 → annuelle, cote A+");
        $t = releve_texte(['piste' => 'fr', 'classe' => $cl, 'seq' => 'annee']);
        ok(str_contains($t, 'Annuelle') && substr_count($t, 'Trimestre') >= 3, 'année vierge : les 3 trimestres en colonnes');

        $t = releve_texte(['piste' => 'fr', 'classe' => $cl, 'item' => -1, 'seq' => $seq, 'mode' => 'rempli']);
        egal(count(competences_classe($cl, $va)), substr_count($t, 'RELEV'), 'rempli « toutes » : une page par compétence');
    });
    return;   // une école suffit
}
passer('aucune école primaire avec classe, compétences et matières arabes configurées');
