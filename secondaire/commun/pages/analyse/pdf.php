<?php
// secondaire/commun/pages/analyse/pdf.php — export PDF de l'analyse des
// performances d'une classe pour une période (10/11/2026).
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
require_once __DIR__ . '/_analyse_lib.php';
exiger_role(an_roles());

$classes = ed_classes(); $periodes = an_periodes();
$id_classe = (int) ($_GET['classe'] ?? 0);
$periode = $periodes[0] ?? ['cle' => 'annee', 'id' => 0, 'libelle' => 'Année entière'];
foreach ($periodes as $p) if ($p['cle'] === ($_GET['periode'] ?? '')) $periode = $p;
$classe_lib = '';
foreach ($classes as $c) if ((int) $c['id'] === $id_classe) $classe_lib = $c['libelle'];
$moys = an_moyennes($id_classe, $periode); arsort($moys);
$ind = an_indicateurs(array_values($moys));
$noms = []; foreach (ed_eleves($id_classe) as $e) $noms[(int) $e['id']] = ed_nom($e);
$matieres = an_matieres($id_classe, $periode);

require_once __DIR__ . '/../../pdf/fpdf.php';
require_once __DIR__ . '/../../pdf/header_pdf.php';
$etab = etab_pour_pdf(get_etablissement());
$pdf = new FPDF('P', 'mm', 'A4'); require_once __DIR__ . '/../../../../noyau/partage/apparence_docs.php'; app_appliquer_doc($pdf, 'documents');   // Apparence des documents
$pdf->SetMargins(10, 10, 10); $pdf->SetAutoPageBreak(true, 14); $pdf->AddPage();
pdf_entete($pdf, $etab, 210);
pdf_bandeau($pdf, 'ANALYSE DES PERFORMANCES', $classe_lib . ' — ' . $periode['libelle'], 210);
$MAR = [26, 39, 68]; $OR = [200, 150, 10];
$titre = function (string $t) use ($pdf, $OR) { $pdf->Ln(2); $pdf->SetFont('Arial', 'B', 10.5); $pdf->SetTextColor(...$OR); $pdf->Cell(0, 6, pdf_u($t), 0, 1); $pdf->SetTextColor(0, 0, 0); };
$table = function (array $cols, array $lignes) use ($pdf, $MAR) {
    $pdf->SetFillColor(...$MAR); $pdf->SetTextColor(255, 255, 255); $pdf->SetFont('Arial', 'B', 8);
    foreach ($cols as [$t, $w]) $pdf->Cell($w, 6, pdf_u($t), 1, 0, 'C', true);
    $pdf->Ln(); $pdf->SetTextColor(0, 0, 0); $f = false;
    foreach ($lignes as $l) { $pdf->SetFillColor(243, 246, 251); $pdf->SetFont('Arial', '', 8.5);
        foreach ($cols as $i => $c) $pdf->Cell($c[1], 5.8, pdf_u((string) ($l[$i] ?? '')), 1, 0, $c[2] ?? 'C', $f);
        $pdf->Ln(); $f = !$f; }
};

$titre('Indicateurs de la classe');
if (!$ind['n']) { $pdf->SetFont('Arial', 'I', 9); $pdf->Cell(0, 6, pdf_u('Aucune moyenne pour cette période.'), 0, 1); }
else {
    $table([['Élèves classés', 32], ['Moyenne', 30], ['Taux de réussite', 34], ['Meilleure', 30], ['Plus faible', 32], ['Écart-type', 32]],
        [[$ind['n'], an_fmt($ind['moy']), an_fmt($ind['taux'], 0) . ' %', an_fmt($ind['max']), an_fmt($ind['min']), an_fmt($ind['ecart'])]]);
    $titre('Répartition des moyennes');
    $tr = an_tranches(array_values($moys));
    $table(array_map(fn($k) => [$k, 190 / count($tr)], array_keys($tr)), [array_values($tr)]);
    if ($matieres) {
        $titre('Résultats par matière (triés par taux de réussite croissant)');
        $table([['Matière', 48, 'L'], ['Enseignant(s)', 40, 'L'], ['Notés', 14], ['Moyenne', 18], ['Min', 14], ['Max', 14], ['Admis', 14], ['Taux', 14], ['Écart-type', 14]],
            array_map(fn($m) => [$m['matiere'], $m['enseignant'], $m['notes'], an_fmt($m['moyenne']), an_fmt($m['min']), an_fmt($m['max']), $m['admis'], an_fmt($m['taux'], 0) . ' %', an_fmt($m['ecart'])], $matieres));
    }
    $liste = []; $r = 0; foreach ($moys as $eid => $m) $liste[] = [++$r . 'e', $noms[$eid] ?? '?', an_fmt($m)];
    $titre('Les 10 premiers');
    $table([['Rang', 20], ['Élève', 130, 'L'], ['Moyenne', 40]], array_slice($liste, 0, 10));
    $titre('Les 10 élèves à accompagner en priorité');
    $table([['Rang', 20], ['Élève', 130, 'L'], ['Moyenne', 40]], array_slice(array_reverse($liste), 0, 10));
}
$pdf->Ln(4); $pdf->SetFont('Arial', 'I', 7.5); $pdf->SetTextColor(110, 110, 110);
$pdf->MultiCell(0, 4, pdf_u('Moyennes calculées par le même moteur que les bulletins. Écart-type : dispersion des moyennes autour de la moyenne de la classe. Édité le ' . date('d/m/Y à H:i') . '.'), 0, 'L');
$pdf->Output('I', 'analyse_' . date('Ymd') . '.pdf');
