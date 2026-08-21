<?php
// pdf/bareme_niveau.php — Export PDF du barème par niveau (pages/competences/
// liste.php, onglet Barème), groupé par groupe de compétences assigné — même
// logique que l'écran (fonctions.php::bareme_par_niveau()), pas de duplication.
// GET : niveau=X (un seul niveau) — OU sans "niveau" (tous les niveaux
// utilisés, un par page). Demande explicite du 21/08/2026.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
exiger_connexion(); // même politique d'accès que l'écran (lecture) — pages/competences/liste.php

require_once __DIR__ . '/fpdf.php';
require_once __DIR__ . '/header_pdf.php';

$dl          = ($_GET['dl'] ?? '0') === '1';
$f_niveau    = trim((string) ($_GET['niveau'] ?? ''));
$annee       = get_annee_active();
$val_annee   = $annee['val_annee'] ?? '';

$niveaux_liste = db_all(
    "SELECT DISTINCT n.LibelleNiveau, n.OrdreNiveau FROM niveau n
     JOIN classe c ON c.Niveau = n.LibelleNiveau
     WHERE n.actif = 1
     ORDER BY n.OrdreNiveau"
);
if ($f_niveau) {
    $niveaux_liste = array_values(array_filter($niveaux_liste, fn($n) => $n['LibelleNiveau'] === $f_niveau));
    if (!$niveaux_liste) die('Niveau introuvable.');
}
if (!$niveaux_liste) die('Aucun niveau à exporter.');

$etab_brut = get_etablissement();
$etab      = etab_pour_pdf($etab_brut);

// Dessine le barème d'UN niveau (déjà positionné en haut de page) — table
// par groupe de compétences, colonnes Code/Compétence/Oral/Écrit/Pratique/
// Savoir-être/Total, comme le formulaire d'écran.
function dessiner_bareme_niveau_pdf(FPDF $pdf, array $etab, string $code_niveau, string $val_annee, float $pw, float $ph): void {
    $donnees = bareme_par_niveau($code_niveau, $val_annee);
    $noms_classes = implode(', ', array_column($donnees['classes'], 'DesignationClasses'));

    pdf_filigrane($pdf, $etab, $pw, $ph);
    pdf_entete($pdf, $etab, $pw, 12);
    pdf_bandeau($pdf, 'BAREME DE NOTATION', 'GRADING SCALE', $pw, 12);

    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 6, pdf_u('Niveau ' . $code_niveau . ($noms_classes ? ' (' . $noms_classes . ')' : '')), 0, 1, 'L');
    $pdf->SetFont('Arial', '', 8.5);
    $pdf->Cell(0, 5, pdf_u('Année scolaire : ' . $val_annee), 0, 1, 'L');
    $pdf->Ln(2);

    if (!$donnees['assigne']) {
        $pdf->SetFont('Arial', 'I', 9);
        $pdf->Cell(0, 8, pdf_u("Aucun groupe de compétences n'est assigné à ce niveau — barème non configuré."), 0, 1, 'L');
        return;
    }
    if (!$donnees['groupes']) {
        $pdf->SetFont('Arial', 'I', 9);
        $pdf->Cell(0, 8, pdf_u('Aucune compétence en français définie.'), 0, 1, 'L');
        return;
    }

    $w = [22, 84, 16, 16, 18, 22, 18]; // Code, Compétence, Oral, Écrit, Pratique, Savoir-être, Total
    foreach ($donnees['groupes'] as $grp) {
        if ($pdf->GetY() > $ph - 30) { $pdf->AddPage(); pdf_filigrane($pdf, $etab, $pw, $ph); }

        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetFillColor(214, 234, 248);
        $libelle_grp = $grp['libelle'] . (!$grp['visible'] ? ' — masqué (toutes compétences désactivées)' : '');
        $pdf->Cell(array_sum($w), 6, pdf_u($libelle_grp), 1, 1, 'L', true);

        $pdf->SetFont('Arial', 'B', 7.5);
        $pdf->SetFillColor(30, 79, 216); $pdf->SetTextColor(255, 255, 255);
        $entetes = ['Code', 'Compétence', 'Oral', 'Écrit', 'Pratique', 'Savoir-être', 'Total'];
        foreach ($entetes as $i => $lbl) { $pdf->Cell($w[$i], 5.5, pdf_u($lbl), 1, 0, 'C', true); }
        $pdf->Ln();
        $pdf->SetTextColor(0); $pdf->SetFont('Arial', '', 7.5);

        foreach ($grp['lignes'] as $ligne) {
            if ($pdf->GetY() > $ph - 20) {
                $pdf->AddPage(); pdf_filigrane($pdf, $etab, $pw, $ph);
                $pdf->SetFont('Arial', 'B', 7.5);
                $pdf->SetFillColor(30, 79, 216); $pdf->SetTextColor(255, 255, 255);
                foreach ($entetes as $i => $lbl) { $pdf->Cell($w[$i], 5.5, pdf_u($lbl), 1, 0, 'C', true); }
                $pdf->Ln();
                $pdf->SetTextColor(0); $pdf->SetFont('Arial', '', 7.5);
            }
            $active = $ligne['actif'] === null || (int) $ligne['actif'] === 1;
            if (!$active) $pdf->SetTextColor(150);
            $pdf->Cell($w[0], 5, pdf_u($ligne['code_comp']), 1, 0, 'C');
            $pdf->Cell($w[1], 5, pdf_u($ligne['nom_comp']), 1, 0, 'L');
            foreach (['orale', 'ecrite', 'pratique', 'savoir_etre'] as $i => $champ) {
                $v = $ligne[$champ] !== null ? (float) $ligne[$champ] : 0;
                $pdf->Cell($w[$i + 2], 5, number_format($v, 1, ',', ''), 1, 0, 'C');
            }
            $total = $ligne['total_points'] !== null ? (float) $ligne['total_points'] : 0;
            $pdf->SetFont('Arial', 'B', 7.5);
            $pdf->Cell($w[6], 5, number_format($total, 1, ',', ''), 1, 1, 'C');
            $pdf->SetFont('Arial', '', 7.5);
            if (!$active) $pdf->SetTextColor(0);
        }
        $pdf->Ln(3);
    }
}

// Enveloppé dans un try/catch — voir fonctions.php::pdf_erreur_generation().
try {
$pdf = new FPDF('P', 'mm', 'A4');
$pdf->SetMargins(12, 10, 12);
$pdf->SetAutoPageBreak(true, 15);
$pw = $pdf->GetPageWidth();
$ph = $pdf->GetPageHeight();

foreach ($niveaux_liste as $i => $n) {
    $pdf->AddPage();
    dessiner_bareme_niveau_pdf($pdf, $etab, $n['LibelleNiveau'], $val_annee, $pw, $ph);
    // Copyright standard du système (pdf/header_pdf.php) — une fois par
    // niveau, sur la DERNIÈRE page réellement utilisée par ce niveau (un
    // niveau avec beaucoup de compétences peut avoir déclenché plusieurs
    // AddPage() dans dessiner_bareme_niveau_pdf() ci-dessus).
    pdf_copyright($pdf, $pw, $ph);
}

$nom_fichier = $f_niveau ? ('bareme_niveau_' . preg_replace('/[^A-Za-z0-9]/', '_', $f_niveau) . '.pdf') : ('bareme_tous_niveaux_' . date('Ymd') . '.pdf');
$pdf->Output($dl ? 'D' : 'I', $nom_fichier);
} catch (Throwable $e) {
    pdf_erreur_generation($e);
}
