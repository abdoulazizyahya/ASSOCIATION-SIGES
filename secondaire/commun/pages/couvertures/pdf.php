<?php
// secondaire/commun/pages/couvertures/pdf.php — couvertures de carnets de
// notes (10/11/2026). GET : classe (toute la classe), ids=1,2,3 (sélection),
// vierge=1 (une couverture à remplir à la main), apercu=1 (1er élève seulement).
// Couleurs de SIGES (marine #1a2744, or #c8960a) ; A4 ou A5 (tout est mis à
// l'échelle de la largeur de la page).
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
require_once __DIR__ . '/_couvertures_lib.php';
exiger_role(couv_roles());

$R = couv_reglages();
$vierge = ($_GET['vierge'] ?? '') === '1';
$eleves = [];
if (!$vierge) {
    $eleves = ed_eleves((int) ($_GET['classe'] ?? 0));
    $ids = array_filter(array_map('intval', explode(',', (string) ($_GET['ids'] ?? ''))));
    if ($ids) $eleves = array_values(array_filter($eleves, fn($e) => in_array((int) $e['id'], $ids, true)));
    if (($_GET['apercu'] ?? '') === '1') $eleves = array_slice($eleves, 0, 1);
    if (!$eleves) $vierge = true;   // classe vide : une couverture vierge plutôt qu'un PDF vide
}
if ($vierge) $eleves = [null];

require_once __DIR__ . '/../../pdf/fpdf.php';
require_once __DIR__ . '/../../pdf/header_pdf.php';
$etab = etab_pour_pdf(get_etablissement());
$an = ed_annee();
$MAR = [26, 39, 68]; $OR = [200, 150, 10];
$fmt = $R['format'] === 'A5' ? 'A5' : 'A4';
$pdf = new FPDF('P', 'mm', $fmt);
require_once __DIR__ . '/../../../../noyau/partage/apparence_docs.php'; app_appliquer_doc($pdf, 'documents');   // Apparence des documents
$pdf->SetAutoPageBreak(false);
$pw = $fmt === 'A5' ? 148 : 210; $ph = $fmt === 'A5' ? 210 : 297;
$k = $pw / 210;            // échelle : toutes les cotes sont pensées pour l'A4
$m = 10 * $k;              // marge intérieure du cadre
$logo = !empty($etab['logo']) ? __DIR__ . '/../../../../assets/uploads/' . $etab['logo'] : '';

$cadre = function () use ($pdf, $R, $pw, $ph, $k, $MAR, $OR) {
    $pdf->SetLineWidth(0.3);
    switch ($R['modele']) {
        case 'officiel':
            $pdf->SetDrawColor(0, 0, 0); $pdf->SetLineWidth(0.8 * $k); $pdf->Rect(6 * $k, 6 * $k, $pw - 12 * $k, $ph - 12 * $k);
            $pdf->SetLineWidth(0.3); $pdf->Rect(8 * $k, 8 * $k, $pw - 16 * $k, $ph - 16 * $k);
            break;
        case 'prestige':
            $pdf->SetDrawColor(...$OR); $pdf->SetLineWidth(1.2 * $k); $pdf->Rect(6 * $k, 6 * $k, $pw - 12 * $k, $ph - 12 * $k);
            $pdf->SetLineWidth(0.4); $pdf->Rect(9 * $k, 9 * $k, $pw - 18 * $k, $ph - 18 * $k);
            $pdf->SetFillColor(...$OR);
            foreach ([[6, 6], [$pw / $k - 14, 6], [6, $ph / $k - 14], [$pw / $k - 14, $ph / $k - 14]] as [$x, $y]) $pdf->Rect($x * $k, $y * $k, 8 * $k, 8 * $k, 'F');
            break;
        case 'leger':
            $pdf->SetDrawColor(140, 140, 140); $pdf->SetLineWidth(0.3); $pdf->Rect(7 * $k, 7 * $k, $pw - 14 * $k, $ph - 14 * $k);
            break;
        default: // siges : cadre marine + bande latérale or
            $pdf->SetDrawColor(...$MAR); $pdf->SetLineWidth(1 * $k); $pdf->Rect(6 * $k, 6 * $k, $pw - 12 * $k, $ph - 12 * $k);
            $pdf->SetFillColor(...$OR); $pdf->Rect(6 * $k, $ph - 22 * $k, $pw - 12 * $k, 2.2 * $k, 'F');
    }
    $pdf->SetDrawColor(0, 0, 0); $pdf->SetLineWidth(0.2);
};
$titre_couleur = $R['modele'] === 'leger' || $R['modele'] === 'officiel' ? [0, 0, 0] : $MAR;

// Modèle dessiné par l'école (concepteur) : rendu libre, page entière.
if ($R['modele'] === 'perso' || !empty($_GET['gab_brouillon'])) {
    require_once __DIR__ . '/../../../../noyau/partage/gabarits.php';
    $gab = gab_charger('couverture', !empty($_GET['gab_brouillon']));
    $vars_ecole = gab_vars_ecole($etab, $an['libelle']);
    foreach ($eleves as $e) {
        $pdf->AddPage();
        $tmp = [];
        gab_rendre($pdf, $gab, 0, 0, $pw, $ph, ($e ? gab_vars_eleve($e) : array_fill_keys(['nom_complet', 'nom', 'prenom', 'classe', 'identifiant', 'matricule', 'niu', 'sexe', 'date_naiss', 'lieu_naiss'], '')) + $vars_ecole, [
            'logo' => gab_logo($etab),
            'photo' => function () use ($e, &$tmp) { $p = $e ? ed_photo_fichier((int) $e['id']) : null; if ($p) $tmp[] = $p; return (string) $p; },
        ]);
        foreach ($tmp as $t) @unlink($t);
    }
    $pdf->Output('I', 'couvertures_' . date('Ymd') . '.pdf');
    exit;
}

foreach ($eleves as $e) {
    $pdf->AddPage();
    $cadre();
    // En-tête officiel de l'école (déjà bilingue, avec le logo au centre)
    pdf_entete($pdf, $etab, $pw, 12 * $k, 12 * $k, 0.85 * $k);
    $y = max($pdf->GetY(), 42 * $k) + 6 * $k;

    // Bandeau du titre
    if ($R['modele'] === 'siges') {
        $pdf->SetFillColor(...$MAR); $pdf->Rect($m + 4 * $k, $y, $pw - 2 * $m - 8 * $k, ($R['titre_en'] !== '' ? 24 : 16) * $k, 'F');
        $pdf->SetTextColor(255, 255, 255);
    } else {
        $pdf->SetTextColor(...$titre_couleur);
    }
    // Titre réduit si besoin pour tenir dans la largeur (titre long, A5).
    $t_titre = 24 * $k; $pdf->SetFont('Arial', 'B', $t_titre);
    while ($t_titre > 11 * $k && $pdf->GetStringWidth(pdf_u(mb_strtoupper($R['titre_fr']))) > $pw - 2 * $m - 14 * $k) { $t_titre -= 0.5; $pdf->SetFont('Arial', 'B', $t_titre); }
    $pdf->SetXY($m, $y + 2.5 * $k); $pdf->Cell($pw - 2 * $m, 11 * $k, pdf_u(mb_strtoupper($R['titre_fr'])), 0, 2, 'C');
    if ($R['titre_en'] !== '') { $pdf->SetFont('Arial', 'I', 13 * $k); $pdf->Cell($pw - 2 * $m, 8 * $k, pdf_u($R['titre_en']), 0, 2, 'C'); }
    $y += ($R['titre_en'] !== '' ? 24 : 16) * $k + 3 * $k;
    $pdf->SetTextColor(...($R['modele'] === 'leger' ? [60, 60, 60] : $OR));
    $pdf->SetFont('Arial', 'B', 13 * $k);
    $pdf->SetXY($m, $y); $pdf->Cell($pw - 2 * $m, 7 * $k, pdf_u('Année scolaire ' . $an['libelle'] . ' / School year'), 0, 0, 'C');
    $pdf->SetTextColor(0, 0, 0);
    $y += 13 * $k;

    // (Le logo est déjà au centre de l'en-tête officiel : pas de second logo.)
    $y += 6 * $k;

    // Centrage vertical : hauteur estimée du contenu restant (identité + blocs
    // choisis), la place libre est répartie au-dessus et au-dessous.
    $h_prev = 51 * $k;
    if (!empty($R['echelle'])) $h_prev += (10 + 4.6 * count(couv_echelle())) * $k;
    $pdf->SetFont('Arial', '', 8.5 * $k);
    foreach ([['note', 'texte_note'], ['reglement', 'texte_reglement']] as [$c1, $c2]) {
        if (!empty($R[$c1]) && trim((string) $R[$c2]) !== '') $h_prev += (11 + 4.2 * max(1, (int) ceil($pdf->GetStringWidth(pdf_u($R[$c2])) / (($pw - 2 * $m - 20 * $k) * 0.97)))) * $k;
    }
    if (!empty($R['cadre'])) $h_prev += 33 * $k;
    $libre = ($ph - 26 * $k) - $y - $h_prev;
    if ($libre > 0) $y += $libre * 0.4;
    // Identité de l'élève
    $bx = $m + 8 * $k; $bw = $pw - 2 * $m - 16 * $k; $bh = 46 * $k;
    $pdf->SetDrawColor(...($R['modele'] === 'prestige' ? $OR : $MAR)); $pdf->SetLineWidth(0.5 * $k);
    $pdf->Rect($bx, $y, $bw, $bh); $pdf->SetLineWidth(0.2); $pdf->SetDrawColor(0, 0, 0);
    $xp = $bx + 4 * $k; $tw = $bw - 8 * $k;
    if (!empty($R['photo'])) {
        $fw = 30 * $k; $fh = 38 * $k;
        $ph_f = $e ? ed_photo_fichier((int) $e['id']) : null;
        if ($ph_f) $pdf->Image($ph_f, $bx + 4 * $k, $y + 4 * $k, $fw, $fh);
        else { $pdf->SetDrawColor(170, 170, 170); $pdf->Rect($bx + 4 * $k, $y + 4 * $k, $fw, $fh); $pdf->SetFont('Arial', '', 7 * $k); $pdf->SetTextColor(150, 150, 150);
               $pdf->SetXY($bx + 4 * $k, $y + 4 * $k + $fh / 2 - 2); $pdf->Cell($fw, 4, 'PHOTO', 0, 0, 'C'); $pdf->SetTextColor(0, 0, 0); $pdf->SetDrawColor(0, 0, 0); }
        $xp = $bx + 4 * $k + $fw + 5 * $k; $tw = $bw - ($xp - $bx) - 4 * $k;
    }
    $champs = [
        ['Nom et prénom(s)', $e ? ed_nom($e) : ''],
        ['Classe', $e ? (string) $e['classe'] : ''],
        [ed_secondaire() ? 'Matricule / NIU' : 'Matricule', $e ? ed_ident($e) : ''],
        ['Né(e) le', $e && $e['date_naiss'] ? date('d/m/Y', strtotime($e['date_naiss'])) . ($e['lieu_naiss'] ? ' à ' . $e['lieu_naiss'] : '') : ''],
    ];
    $yy = $y + 5 * $k;
    foreach ($champs as [$lib, $val]) {
        $pdf->SetXY($xp, $yy);
        $pdf->SetFont('Arial', '', 9 * $k); $pdf->SetTextColor(90, 90, 90);
        $pdf->Cell($tw, 4 * $k, pdf_u($lib), 0, 2, 'L');
        $pdf->SetTextColor(0, 0, 0);
        if ($val === '') { pdf_ligne_pointillee($pdf, $xp, $yy + 9 * $k, $xp + $tw, $yy + 9 * $k); }
        else { $pdf->SetFont('Arial', 'B', 11.5 * $k); $pdf->Cell($tw, 5.5 * $k, pdf_u(mb_substr($val, 0, 60)), 0, 0, 'L'); }
        $yy += 10 * $k;
    }
    $y += $bh + 5 * $k;

    // Blocs optionnels, dans la place restante
    $bloc = function (string $titre, callable $corps, float $h) use ($pdf, &$y, $bx, $bw, $k, $MAR, $OR, $R) {
        $pdf->SetFillColor(...($R['modele'] === 'leger' ? [235, 235, 235] : ($R['modele'] === 'prestige' ? $OR : $MAR)));
        $pdf->Rect($bx, $y, $bw, 5.5 * $k, 'F');
        $pdf->SetTextColor(...($R['modele'] === 'leger' ? [0, 0, 0] : [255, 255, 255])); $pdf->SetFont('Arial', 'B', 8.5 * $k);
        $pdf->SetXY($bx + 2 * $k, $y); $pdf->Cell($bw - 4 * $k, 5.5 * $k, pdf_u($titre), 0, 0, 'L'); $pdf->SetTextColor(0, 0, 0);
        $pdf->Rect($bx, $y, $bw, $h);
        $pdf->SetXY($bx + 2 * $k, $y + 6.5 * $k);
        $corps($bx + 2 * $k, $bw - 4 * $k);
        $y += $h + 3 * $k;
    };
    $limite = $ph - 26 * $k;
    if (!empty($R['echelle'])) {
        $ech = couv_echelle(); $h = (7 + 4.6 * count($ech)) * $k;
        if ($y + $h < $limite) $bloc('Échelle d\'appréciation / Grading scale', function ($x, $w) use ($pdf, $ech, $k) {
            foreach ($ech as [$c, $n, $l]) {
                $pdf->SetX($x); $pdf->SetFont('Arial', 'B', 8.5 * $k); $pdf->Cell(18 * $k, 4.4 * $k, pdf_u($c), 0, 0);
                $pdf->SetFont('Arial', '', 8.5 * $k); $pdf->Cell(20 * $k, 4.4 * $k, pdf_u($n), 0, 0); $pdf->Cell($w - 38 * $k, 4.4 * $k, pdf_u($l), 0, 1);
            }
        }, $h);
    }
    foreach ([['note', 'Note aux parents', 'texte_note'], ['reglement', 'Extrait du règlement intérieur', 'texte_reglement']] as [$cle, $tit, $txt]) {
        if (empty($R[$cle]) || trim((string) $R[$txt]) === '') continue;
        $pdf->SetFont('Arial', '', 8.5 * $k);
        $lignes = max(1, (int) ceil($pdf->GetStringWidth(pdf_u($R[$txt])) / (($pw - 2 * $m - 20 * $k) * 0.97)));
        $h = (8 + 4.2 * $lignes) * $k;
        if ($y + $h < $limite) $bloc($tit, function ($x, $w) use ($pdf, $R, $txt, $k) {
            $pdf->SetX($x); $pdf->SetFont('Arial', '', 8.5 * $k); $pdf->MultiCell($w, 4.2 * $k, pdf_u($R[$txt]), 0, 'J');
        }, $h);
    }
    if (!empty($R['cadre'])) {
        $h = min(30 * $k, $limite - $y);
        if ($h > 14 * $k) $bloc('Observations', function () {}, $h);
    }
    // Pied : lieu
    $pdf->SetFont('Arial', 'I', 8 * $k); $pdf->SetTextColor(90, 90, 90);
    $pdf->SetXY($m, $ph - 18 * $k); $pdf->Cell($pw - 2 * $m, 4 * $k, pdf_u(lieu_edition_documents() . ' — ' . $an['libelle']), 0, 0, 'C');
    $pdf->SetTextColor(0, 0, 0);
}

$pdf->Output('I', 'couvertures_' . date('Ymd') . '.pdf');
