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

    // Fiche anonyme : ni classe ni noms ; plus grande classe + 10 lignes. Fiche nominative + 5 lignes vides.
    $max = (int) db_val("SELECT MAX(n) FROM (SELECT COUNT(*) n FROM inscrire WHERE val_annee=? GROUP BY IDClasses) t", [$va]);
    $premier = (string) db_val("SELECT e.Nom_elv FROM eleve e JOIN inscrire i ON i.id_eleve=e.id_eleve WHERE i.IDClasses=? AND i.val_annee=? ORDER BY e.Nom_elv LIMIT 1", [$cl, $va]);
    $t = releve_texte(['piste' => 'fr', 'anonyme' => '1', 'seq' => 'annee']);
    ok(str_contains($t, '| ' . ($max + 10) . ' |') && !str_contains($t, '| ' . ($max + 11) . ' |'), 'fiche anonyme : ' . ($max + 10) . ' lignes (plus grande classe ' . $max . ' + 10)');
    ok(!str_contains($t, pdf_u(mb_strtoupper($premier))) && !str_contains($t, pdf_u('Classe : ') . ' | ' . pdf_u('CE')), 'fiche anonyme : aucun nom d\'élève');
    $n_cl = (int) db_val("SELECT COUNT(*) FROM inscrire WHERE IDClasses=? AND val_annee=?", [$cl, $va]);
    $t = releve_texte(['piste' => 'fr', 'classe' => $cl, 'vides' => 5]);
    ok(str_contains($t, '| ' . ($n_cl + 5) . ' |') && !str_contains($t, '| ' . ($n_cl + 6) . ' |') && str_contains($t, pdf_u(mb_strtoupper($premier))), "fiche nominative + 5 lignes vides : " . ($n_cl + 5) . ' lignes');

    // Tableau statistique des effectifs (01/11/2026) : totaux garçons / filles / total
    $eff = db_one("SELECT SUM(e.Sexe_elv LIKE 'F%') f, COUNT(*) t FROM eleve e JOIN inscrire i ON i.id_eleve=e.id_eleve WHERE i.IDClasses=? AND i.val_annee=?", [$cl, $va]);
    $t = releve_texte(['piste' => 'fr', 'classe' => $cl]);
    ok(str_contains($t, 'Redoublants') && str_contains($t, '| Total | ' . ($eff['t'] - $eff['f']) . ' | ' . (int) $eff['f'] . ' | ' . $eff['t'] . ' |'),
       'tableau des effectifs : total G ' . ($eff['t'] - $eff['f']) . ' / F ' . (int) $eff['f'] . ' / ' . $eff['t']);
    // Toutes les classes : une fiche par classe ayant des inscrits
    $nb_cl = (int) db_val("SELECT COUNT(DISTINCT IDClasses) FROM inscrire WHERE val_annee=?", [$va]);
    $t = releve_texte(['piste' => 'fr', 'classe' => 'toutes']);
    egal($nb_cl, substr_count($t, 'RELEV'), "toutes les classes (vierge) : $nb_cl fiches");
    $t = releve_texte(['piste' => 'ar', 'classe' => 'toutes', 'seq' => 'annee']);
    egal($nb_cl, substr_count($t, 'RELEV'), "toutes les classes (arabe, année vierge) : $nb_cl fiches");
    // Barème commun de la classe aussi en « toutes les classes » (02/11/2026)
    $bars = array_filter(array_map(fn($m) => $m['bareme'], matieres_classe_arabe_avec_bareme($cl, $va)));
    $sig = fn($b) => implode('|', [(float) $b['orale'], (float) $b['ecrite'], (float) $b['pratique']]);
    if ($bars && count(array_unique(array_map($sig, $bars))) === 1) {
        $b0 = reset($bars);
        $attendu = '/ ' . rtrim(rtrim(number_format((float) $b0['orale'], 2, ',', ''), '0'), ',');
        $un = releve_texte(['piste' => 'ar', 'classe' => $cl]);
        $tt = releve_texte(['piste' => 'ar', 'classe' => 'toutes']);
        ok(str_contains($un, "| $attendu |") && str_contains($tt, "| $attendu |"), "arabe, toutes les classes : barème commun appliqué ({$attendu}…)");
    }
    // Signatures juste sous le tableau (plus repoussées en bas de page) : fiche de 3 lignes
    $_GET = ['piste' => 'fr', 'classe' => $cl];
    $pdf_brut = (function () { ob_start(); $_SERVER['SCRIPT_NAME'] = '/SIGES/primaire/pdf/releve_notes_evaluation.php'; include __DIR__ . '/../primaire/pdf/releve_notes_evaluation.php'; $_SERVER['SCRIPT_NAME'] = '/SIGES/association/tests.php'; return (string) ob_get_clean(); })();
    preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf_brut, $m);
    $flux = '';   // page des signatures seulement
    foreach ($m[1] as $s) { $u = (string) @gzuncompress($s); if (str_contains($u, "L'enseignant")) $flux = $u; }
    $y_sig = preg_match("/BT ([\\d.]+) ([\\d.]+) Td \\(L'enseignant/", $flux, $ys) ? (float) $ys[2] : null;
    $y_der = null;   // bas du tableau = plus petite ordonnée PDF d'un rectangle de cellule (« re ») de cette page
    $avant = $y_sig !== null ? substr($flux, 0, strpos($flux, "L'enseignant")) : '';
    if (preg_match_all('/([\d.]+) ([\d.]+) ([\d.]+) (-?[\d.]+) re/', $avant, $r)) $y_der = min(array_map(fn($y, $h) => (float) $y + (float) $h, $r[2], $r[4]));
    ok($y_sig !== null && $y_der !== null && $y_der - $y_sig < 25 * 72 / 25.4, 'signatures à environ 2 lignes sous le tableau'
       . ($y_sig !== null && $y_der !== null ? ' (écart ' . round(($y_der - $y_sig) * 25.4 / 72) . ' mm)' : ' — position introuvable'));

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
        ok(!str_contains($t, 'Annuelle') && !str_contains($t, '| Cote') && str_contains($t, 'Moy.') && str_contains($t, pdf_u("Toute l'année")), 'année (fr) : colonnes par trimestre et Moy., ni Annuelle ni Cote');
        ok(str_contains($t, "| $tot | $tot |") && !str_contains($t, "| $tot | A+"), "année (fr) : UA1 $tot → T1 $tot (plus de note annuelle ni de cote)");
        $crit = [];
        foreach (['orale', 'ecrite', 'pratique', 'savoir_etre'] as $k) if ((float) $comp[$k] > 0) $crit[] = rtrim(rtrim(number_format((float) $comp[$k], 2, ',', ''), '0'), ',');
        ok(str_contains($t, '| ' . implode(' | ', $crit) . " | $tot |"), 'année (fr) : détail des critères de l\'UA1 (' . implode(', ', $crit) . ") puis son total");
        $tot_ar = rtrim(rtrim(number_format((float) $mat['bareme']['total_points'], 2, ',', ''), '0'), ',');
        $t = releve_texte(['piste' => 'ar', 'classe' => $cl, 'item' => $mat['id_mat'], 'seq' => 'annee', 'mode' => 'rempli']);
        ok(str_contains($t, "| $tot_ar | $tot_ar |") && !str_contains($t, "| $tot_ar | A+"), "année (arabe) : UA1 $tot_ar → T1 $tot_ar (plus de note annuelle ni de cote)");
        $t = releve_texte(['piste' => 'fr', 'classe' => $cl, 'seq' => 'annee']);
        ok(substr_count($t, 'Trimestre') >= 3 && !str_contains($t, 'Moy.'), 'année vierge : les 3 trimestres en colonnes, sans Moy. (place aux noms)');
        ok(!str_contains($t, 'Annuelle') && !str_contains($t, 'Tot.') && !str_contains($t, '| Cote'), 'année vierge : ni Tot., ni Annuelle, ni Cote (fiche à remplir au stylo)');

        $t = releve_texte(['piste' => 'fr', 'classe' => $cl, 'item' => -1, 'seq' => $seq, 'mode' => 'rempli']);
        egal(count(competences_classe($cl, $va)), substr_count($t, 'RELEV'), 'rempli « toutes » : une page par compétence');
        $t = releve_texte(['piste' => 'fr', 'classe' => 'toutes', 'item' => -1, 'seq' => $seq, 'mode' => 'rempli']);
        $tot = rtrim(rtrim(number_format((float) $comp['total_points'], 2, ',', ''), '0'), ',');
        ok(str_contains($t, "| $tot | A+") && substr_count($t, 'RELEV') >= count(competences_classe($cl, $va)), 'rempli, toutes les classes et compétences : notes saisies présentes');
    });
    return;   // une école suffit
}
passer('aucune école primaire avec classe, compétences et matières arabes configurées');
