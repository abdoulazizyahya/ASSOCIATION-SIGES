<?php
// Relevé de notes du secondaire, général et technique (01/11/2026) : colonne Sexe, fiche
// anonyme (ni classe, ni noms, ni sexe ; plus grande classe + 10 lignes), lignes
// vides en plus, tableau des effectifs.
require __DIR__ . '/_amorce.php';

function releve_sec_texte(array $get, string $f = 'general/pages/notes_matieres'): string {
    $_GET = $get; $_REQUEST = $get;
    $_SERVER['SCRIPT_NAME'] = "/SIGES/secondaire/$f/pdf_releve_vierge.php";
    ob_start();
    include __DIR__ . "/../secondaire/$f/pdf_releve_vierge.php";
    $pdf = (string) ob_get_clean();
    $_SERVER['SCRIPT_NAME'] = '/SIGES/association/tests.php';
    if (!str_starts_with($pdf, '%PDF')) return 'PAS UN PDF : ' . substr(strip_tags($pdf), 0, 200);
    preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $m);
    $t = '';
    foreach ($m[1] as $s) { $u = @gzuncompress($s); $t .= $u !== false ? $u : ''; }
    preg_match_all('/\((.*?)\) ?Tj/s', $t, $x);
    return implode(' | ', array_map(fn($s) => stripcslashes($s), $x[1]));
}

foreach (assoc_all("SELECT code FROM etablissement WHERE actif=1 AND type_enseignement='secondaire' ORDER BY id") as $e) {
    if (!ecole($e['code'])) continue;
    $id_annee = (int) (get_annee_active()['id'] ?? 0);
    $cnt = "SELECT i.id_classe, COUNT(*) n FROM inscription i JOIN eleve e ON e.id=i.id_eleve JOIN classe c ON c.id=i.id_classe
            WHERE i.id_annee=? AND e.statut='actif' AND c.archivee=0 GROUP BY i.id_classe";
    $rows = db_all("$cnt HAVING n >= 3 ORDER BY n DESC", [$id_annee]);
    if (!$rows) continue;
    $cl = (int) $rows[0]['id_classe'];
    $max = (int) db_val("SELECT MAX(n) FROM ($cnt) t", [$id_annee]);
    $n_cl = (int) $rows[0]['n'];
    $premier = (string) db_val("SELECT e.nom FROM eleve e JOIN inscription i ON i.id_eleve=e.id AND i.id_classe=? AND i.id_annee=? WHERE e.statut='actif' ORDER BY e.nom, e.prenom LIMIT 1", [$cl, $id_annee]);
    echo "  ({$e['code']}, classe $cl : $n_cl élèves, plus grande classe $max)\n";

    foreach (['général' => 'general/pages/notes_matieres', 'technique' => 'technique/pages/notes'] as $ord => $f) {
    echo "  — PDF du $ord\n";
    $t = releve_sec_texte(['classe' => $cl], $f);
    ok(str_contains($t, 'SEXE') && str_contains($t, pdf_u(strtoupper($premier))), 'fiche nominative : colonne SEXE et noms des élèves');
    ok(str_contains($t, '| ' . $n_cl . ' |') && !str_contains($t, '| ' . ($n_cl + 1) . ' | |'), "fiche nominative : $n_cl lignes");
    $t = releve_sec_texte(['classe' => $cl, 'vides' => 5], $f);
    ok(str_contains($t, '| ' . ($n_cl + 5) . ' |'), 'fiche nominative + 5 lignes vides : ' . ($n_cl + 5) . ' lignes');
    $t = releve_sec_texte(['anonyme' => '1'], $f);
    ok(str_contains($t, '| ' . ($max + 10) . ' |') && !str_contains($t, '| ' . ($max + 11) . ' |'), 'fiche anonyme : ' . ($max + 10) . " lignes (plus grande classe $max + 10)");
    ok(!str_contains($t, pdf_u(strtoupper($premier)) . ' ') && str_contains($t, 'SEXE') && str_contains($t, 'Redoublants'), 'fiche anonyme : aucun nom, colonne SEXE et tableau des effectifs (vide)');
    }
    return;   // une école suffit
}
passer('aucune école secondaire avec des élèves inscrits');
