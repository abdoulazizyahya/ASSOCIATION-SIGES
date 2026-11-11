<?php
// secondaire/commun/pages/impression/pdf.php — documents du Centre
// d'impression (10/11/2026). GET : doc (voir IMP_DOCS), classe (0 = toutes).
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
require_once __DIR__ . '/_impression_lib.php';
exiger_role(imp_roles());

$doc = (string) ($_GET['doc'] ?? 'liste');
$titres = [];
foreach (IMP_DOCS as $g) $titres += $g;
if (!isset($titres[$doc])) die('Document inconnu.');
$id_classe = (int) ($_GET['classe'] ?? 0);

require_once __DIR__ . '/../../pdf/fpdf.php';
require_once __DIR__ . '/../../pdf/header_pdf.php';
$etab = etab_pour_pdf(get_etablissement());
$an = ed_annee();
$pdf = new FPDF('P', 'mm', 'A4');
require_once __DIR__ . '/../../../../noyau/partage/apparence_docs.php'; app_appliquer_doc($pdf, 'documents');   // Apparence des documents
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(false);
$pdf->AliasNbPages();
$pw = 210; $uw = 190; $bas = 282;
$MAR = [26, 39, 68];

$premiere = true;
$debut_page = function () use ($pdf, $etab, $pw, $titres, $doc, $an, &$premiere) {
    $pdf->AddPage();
    if ($premiere) {
        pdf_entete($pdf, $etab, $pw);
        pdf_bandeau($pdf, mb_strtoupper($titres[$doc][0]), 'Année scolaire ' . $an['libelle'], $pw);
        $premiere = false;
    } else {
        $pdf->SetFont('Arial', 'I', 8); $pdf->SetTextColor(110, 110, 110);
        $pdf->Cell(0, 5, pdf_u(($etab['nom_fr'] ?? '') . ' — ' . $titres[$doc][0] . ' (suite)'), 0, 1, 'L');
        $pdf->SetTextColor(0, 0, 0);
    }
};
$pied = function () use ($pdf) {
    $pdf->SetFont('Arial', 'I', 7); $pdf->SetTextColor(120, 120, 120);
    $pdf->SetXY(10, 287); $pdf->Cell(95, 4, pdf_u('Édité le ' . date('d/m/Y à H:i')), 0, 0, 'L');
    $pdf->Cell(95, 4, pdf_u('Page ' . $pdf->PageNo() . ' / {nb}'), 0, 0, 'R');
    $pdf->SetTextColor(0, 0, 0);
};
/**
 * Tableau avec en-tête répété à chaque page. $cols = [[titre, largeur, align]],
 * $lignes = listes de valeurs ; $titre_groupe : sous-titre (ex. la classe).
 */
$tableau = function (array $cols, array $lignes, string $titre_groupe = '', float $h = 6.2) use ($pdf, $debut_page, $pied, $bas, $MAR) {
    $entete = function () use ($pdf, $cols, $MAR) {
        $pdf->SetFillColor(...$MAR); $pdf->SetTextColor(255, 255, 255); $pdf->SetFont('Arial', 'B', 8);
        foreach ($cols as [$t, $w]) $pdf->Cell($w, 6.5, pdf_u($t), 1, 0, 'C', true);
        $pdf->Ln(); $pdf->SetTextColor(0, 0, 0);
    };
    if ($pdf->GetY() + 22 > $bas) { $pied(); $debut_page(); }
    if ($titre_groupe !== '') {
        $pdf->Ln(1.5); $pdf->SetFont('Arial', 'B', 10); $pdf->SetTextColor(200, 150, 10);
        $pdf->Cell(0, 6, pdf_u($titre_groupe), 0, 1, 'L'); $pdf->SetTextColor(0, 0, 0);
    }
    $entete();
    $fill = false;
    foreach ($lignes as $l) {
        if ($pdf->GetY() + $h > $bas) { $pied(); $debut_page(); $entete(); }
        $pdf->SetFillColor(243, 246, 251);
        foreach ($cols as $i => $col) {
            $w = $col[1]; $al = $col[2] ?? 'L';
            $v = (string) ($l[$i] ?? '');
            $pdf->SetFont('Arial', ($i === 1 && preg_match('/Nom|Élève|Classe|Matière/u', $cols[1][0])) || ($l[0] ?? '') === 'TOTAL ÉCOLE' ? 'B' : '', 8.5);
            for ($t = 8.5; $t > 5.5 && $pdf->GetStringWidth(pdf_u($v)) > $w - 2; $t -= 0.25) $pdf->SetFontSize($t);
            $pdf->Cell($w, $h, pdf_u($v), 1, 0, $al ?? 'L', $fill);
        }
        $pdf->Ln(); $fill = !$fill;
    }
};
$dfr = fn($d) => $d ? date('d/m/Y', strtotime((string) $d)) : '';

// Badges du personnel : planche de cartes, pas une liste.
if ($doc === 'badges') {
    $pdf->SetMargins(12, 12, 12);
    $logo = !empty($etab['logo']) ? __DIR__ . '/../../../../assets/uploads/' . $etab['logo'] : '';
    $W = 85.6; $H = 54; $gx = 12; $gy = 10; $ex = 6.8; $ey = 3;
    $i = 0;
    require_once __DIR__ . '/../../../../noyau/partage/gabarits.php';
    $gab_badge = (gab_existe('badge') || !empty($_GET['gab_brouillon'])) ? gab_charger('badge', !empty($_GET['gab_brouillon'])) : null;
    $vars_ecole = gab_vars_ecole($etab, $an['libelle']);
    foreach (ed_personnel() as $p) {
        if ($i % 10 === 0) $pdf->AddPage();
        $col = $i % 2; $lig = intdiv($i % 10, 2);
        $x = $gx + $col * ($W + $ex); $y = $gy + $lig * ($H + $ey);
        if ($gab_badge) {   // modèle dessiné par l'école (concepteur)
            gab_rendre($pdf, $gab_badge, $x, $y, $W, $H, gab_vars_personnel($p) + $vars_ecole, ['logo' => gab_logo($etab)]);
            $pdf->SetDrawColor(200, 200, 200); $pdf->SetLineWidth(0.1); $pdf->Rect($x, $y, $W, $H);
            $i++; continue;
        }
        $pdf->SetDrawColor(190, 190, 190); $pdf->SetLineWidth(0.2); $pdf->Rect($x, $y, $W, $H);
        $pdf->SetFillColor(...$MAR); $pdf->Rect($x, $y, $W, 12, 'F');
        $pdf->SetFillColor(200, 150, 10); $pdf->Rect($x, $y + 12, $W, 0.9, 'F'); $pdf->Rect($x, $y + $H - 1.6, $W, 1.6, 'F');
        if ($logo && is_file($logo) && function_exists('pdf_logo_cadre')) pdf_logo_cadre($pdf, $logo, $x + 1.5, $y + 1, 10, 10);
        $pdf->SetTextColor(255, 255, 255); $pdf->SetFont('Arial', 'B', 7.5);
        $nom_e = mb_strtoupper((string) ($etab['nom_fr'] ?? ''));
        for ($t = 7.5; $t > 5 && $pdf->GetStringWidth(pdf_u($nom_e)) > $W - 15; $t -= 0.25) $pdf->SetFontSize($t);
        $pdf->SetXY($x + 12.5, $y + 2); $pdf->Cell($W - 14, 4, pdf_u($nom_e), 0, 2, 'C');
        $pdf->SetFont('Arial', '', 6.5); $pdf->Cell($W - 14, 4, pdf_u('CARTE PROFESSIONNELLE — ' . $an['libelle']), 0, 0, 'C');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetDrawColor(170, 170, 170); $pdf->Rect($x + 3, $y + 16, 20, 25);
        $pdf->SetFont('Arial', '', 6); $pdf->SetTextColor(150, 150, 150); $pdf->SetXY($x + 3, $y + 27); $pdf->Cell(20, 3, 'PHOTO', 0, 0, 'C'); $pdf->SetTextColor(0, 0, 0);
        $nom = ed_nom($p); $pdf->SetFont('Arial', 'B', 10);
        for ($t = 10; $t > 6 && $pdf->GetStringWidth(pdf_u($nom)) > $W - 29; $t -= 0.25) $pdf->SetFontSize($t);
        $pdf->SetXY($x + 26, $y + 17); $pdf->Cell($W - 28, 5, pdf_u($nom), 0, 2, 'L');
        $pdf->SetFont('Arial', 'B', 8); $pdf->SetTextColor(200, 150, 10); $pdf->Cell($W - 28, 4.5, pdf_u(mb_strtoupper((string) ($p['fonction'] ?: 'Personnel'))), 0, 2, 'L');
        $pdf->SetTextColor(0, 0, 0); $pdf->SetFont('Arial', '', 7.5);
        if (!empty($p['matricule'])) $pdf->Cell($W - 28, 4, pdf_u('Matricule : ' . $p['matricule']), 0, 2, 'L');
        if (!empty($p['tel'])) $pdf->Cell($W - 28, 4, pdf_u('Tél. : ' . $p['tel']), 0, 2, 'L');
        $pdf->SetFont('Arial', 'I', 6.5); $pdf->SetXY($x + 26, $y + $H - 9);
        $pdf->Cell($W - 28, 3.5, pdf_u(ed_titre_chef()), 0, 0, 'R');
        $i++;
    }
    if ($i === 0) { $pdf->AddPage(); $pdf->SetFont('Arial', 'I', 10); $pdf->Cell(0, 10, pdf_u('Aucun membre du personnel.'), 0, 1); }
    $pdf->Output('I', 'badges_personnel_' . date('Ymd') . '.pdf');
    exit;
}
$debut_page();
$classes = ed_classes();
$cibles = $id_classe ? array_values(array_filter($classes, fn($c) => (int) $c['id'] === $id_classe)) : $classes;

switch ($doc) {
    case 'liste':
    case 'emargement':
        foreach ($cibles as $c) {
            $el = ed_eleves((int) $c['id']); $n = 0;
            $cols = $doc === 'liste'
                ? [['N°', 9, 'C'], ['Nom et prénom(s)', 66], ['Sexe', 11, 'C'], ['Né(e) le', 22, 'C'], ['À', 40], ['Matricule / NIU', 42, 'C']]
                : [['N°', 9, 'C'], ['Nom et prénom(s)', 81], ['Signature', 50], ['Observation', 50]];
            $lignes = array_map(function ($e) use (&$n, $doc, $dfr) {
                $n++;
                return $doc === 'liste' ? [$n, ed_nom($e), $e['sexe'], $dfr($e['date_naiss']), (string) $e['lieu_naiss'], ed_ident($e)] : [$n, ed_nom($e), '', ''];
            }, $el);
            $tableau($cols, $lignes, $c['libelle'] . ' — ' . count($el) . ' élève(s) (G ' . (int) $c['g'] . ' / F ' . (int) $c['f'] . ')', $doc === 'emargement' ? 8 : 6.2);
            if ($id_classe === 0 && $c !== end($cibles)) { $pied(); $debut_page(); }
        }
        break;

    case 'alpha':
        $el = ed_eleves();
        usort($el, fn($a, $b) => strcoll(ed_nom($a), ed_nom($b)));
        $n = 0;
        $tableau([['N°', 11, 'C'], ['Nom et prénom(s)', 80], ['Sexe', 12, 'C'], ['Classe', 40], ['Matricule / NIU', 47, 'C']],
            array_map(function ($e) use (&$n) { return [++$n, ed_nom($e), $e['sexe'], $e['classe'], ed_ident($e)]; }, $el), count($el) . ' élève(s)');
        break;

    case 'effectifs':
        $tg = $tf = 0; $lignes = [];
        foreach ($classes as $c) { $tg += (int) $c['g']; $tf += (int) $c['f']; $lignes[] = [$c['libelle'], (int) $c['g'], (int) $c['f'], (int) $c['nb'], $c['nb'] ? round(100 * $c['f'] / $c['nb']) . ' %' : '']; }
        $lignes[] = ['TOTAL ÉCOLE', $tg, $tf, $tg + $tf, ($tg + $tf) ? round(100 * $tf / ($tg + $tf)) . ' %' : ''];
        $tableau([['Classe', 70], ['Garçons', 30, 'C'], ['Filles', 30, 'C'], ['Total', 30, 'C'], ['% filles', 30, 'C']], $lignes);
        break;

    case 'annuaire':
    case 'sans_tel':
        foreach ($cibles as $c) {
            $el = ed_eleves((int) $c['id']);
            $ct = contacts_parents_lot(array_column($el, 'id'));
            $n = 0; $lignes = [];
            foreach ($el as $e) {
                $nums = $ct[(int) $e['id']] ?? [];
                if ($doc === 'sans_tel') { if (!$nums) $lignes[] = [++$n, ed_nom($e), ed_ident($e), '']; continue; }
                $lignes[] = [++$n, ed_nom($e), $nums ? implode('  /  ', array_map(fn($x) => tel_affiche($x['tel']), array_slice($nums, 0, 2))) : '—',
                             $nums ? $nums[0]['qui'] . ($nums[0]['nom'] !== '' ? ' — ' . $nums[0]['nom'] : '') : 'à compléter'];
            }
            if (!$lignes) continue;
            $cols = $doc === 'annuaire'
                ? [['N°', 9, 'C'], ['Élève', 62], ['Téléphone(s)', 55, 'C'], ['Parent', 64]]
                : [['N°', 9, 'C'], ['Élève', 80], ['Matricule / NIU', 45, 'C'], ['Téléphone à saisir', 56]];
            $tableau($cols, $lignes, $c['libelle'] . ($doc === 'sans_tel' ? ' — ' . count($lignes) . ' élève(s) sans numéro' : ''), $doc === 'sans_tel' ? 7 : 6.2);
        }
        break;

    case 'personnel':
        $n = 0;
        $tableau([['N°', 9, 'C'], ['Nom et prénom(s)', 70], ['Fonction', 45], ['Matricule', 32, 'C'], ['Téléphone', 34, 'C']],
            array_map(function ($p) use (&$n) { return [++$n, ed_nom($p), (string) $p['fonction'], (string) $p['matricule'], (string) $p['tel']]; }, ed_personnel()));
        break;

    case 'titulaires':
    case 'classes':
        $tit = imp_titulaires(); $n = 0; $lignes = [];
        foreach ($classes as $c) $lignes[] = $doc === 'classes'
            ? [++$n, $c['libelle'], (int) $c['g'], (int) $c['f'], (int) $c['nb'], $tit[(int) $c['id']] ?? '—']
            : [++$n, $c['libelle'], $tit[(int) $c['id']] ?? 'non désigné', ''];
        $tableau($doc === 'classes'
            ? [['N°', 9, 'C'], ['Classe', 45], ['G', 14, 'C'], ['F', 14, 'C'], ['Total', 16, 'C'], [ed_secondaire() ? 'Professeur principal' : 'Titulaire', 92]]
            : [['N°', 9, 'C'], ['Classe', 50], [ed_secondaire() ? 'Professeur principal (téléphone)' : 'Titulaire (téléphone)', 91], ['Signature', 40]], $lignes, '', $doc === 'titulaires' ? 8 : 6.2);
        break;

    case 'matieres':
        $mc = imp_matieres_classes();
        foreach ($cibles as $c) {
            $l = $mc[(int) $c['id']] ?? [];
            if (!$l) continue;
            $n = 0;
            $tableau([['N°', 9, 'C'], ['Matière', 85], ['Coef.', 16, 'C'], ['Enseignant(s)', 80]],
                array_map(function ($r) use (&$n) { return [++$n, $r['matiere'], (string) $r['coef'], (string) ($r['enseignant'] ?: '—')]; }, $l), $c['libelle']);
        }
        break;
}
$pied();
$pdf->Output('I', 'impression_' . $doc . '_' . date('Ymd') . '.pdf');
