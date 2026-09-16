<?php
// ── PDF : Liste des élèves ────────────────────────────────────
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_connexion();

require_once __DIR__ . '/fpdf.php';
require_once __DIR__ . '/header_pdf.php';

$id_annee  = (int)($_GET['annee']  ?? 0);
$id_classe = (int)($_GET['classe'] ?? 0);
$cols      = explode(',', $_GET['cols'] ?? 'no,nom,date,lieu,sexe,matricule');
$aligns    = explode(',', $_GET['align'] ?? ''); // même ordre/longueur que $cols ; 'L'/'C'/'R' par colonne
$dl        = ($_GET['dl'] ?? '0') === '1';

$where    = ["e.statut='actif'"];
$params   = [];
if ($id_classe) { $where[] = "i.id_classe=?"; $params[] = $id_classe; }
$sql_where = 'WHERE ' . implode(' AND ', $where);

$eleves = db_all(
    "SELECT e.*, c.designation AS classe
     FROM eleve e
     LEFT JOIN inscription i ON i.id_eleve=e.id AND i.id_annee=$id_annee
     LEFT JOIN classe c ON c.id=i.id_classe
     $sql_where ORDER BY e.nom, e.prenom", $params);

$etab   = get_etablissement();
$annee  = db_one("SELECT * FROM annee_scolaire WHERE id=?", [$id_annee]) ?? ['libelle'=>'—'];
$classe = $id_classe ? db_one("SELECT * FROM classe WHERE id=?", [$id_classe]) : null;

// Effectif
$total = count($eleves);
$nb_m  = count(array_filter($eleves, fn($e) => $e['sexe'] === 'M'));
$nb_f  = $total - $nb_m;

// ── Colonnes disponibles ──────────────────────────────────────
$def_cols = [
    'no'        => ['N°/No',                 7,  null],
    'nom'       => ['Nom et Prénoms/Name',   60, null],
    'date'      => ['Date naiss./DOB',       24, 'date_naiss'],
    'lieu'      => ['Lieu naiss./Birthplace',34, 'lieu_naiss'],
    'sexe'      => ['Sexe/Sex',              12, 'sexe'],
    'matricule' => ['NIU',                   25, 'matricule'],
    'classe'    => ['Classe/Class',          26, 'classe'],
    'telephone' => ['Téléphone/Phone',       26, 'telephone'],
];
// $sel[$k] = [libellé, largeur, champ, alignement 'L'/'C'/'R']
$sel = [];
foreach ($cols as $i => $k) {
    if (!isset($def_cols[$k])) continue;
    $al = in_array($aligns[$i] ?? '', ['L', 'C', 'R'], true) ? $aligns[$i] : 'L';
    $sel[$k] = [...$def_cols[$k], $al];
}
if (empty($sel)) { // garde-fou : jamais de tableau vide
    foreach ($def_cols as $k => $c) { $sel[$k] = [...$c, 'L']; }
}

// ── Création du PDF ───────────────────────────────────────────
// Toujours en portrait : les largeurs de colonnes ci-dessous sont des
// proportions "naturelles", mises à l'échelle pour occuper exactement la
// largeur imprimable quel que soit le nombre de colonnes sélectionnées
// (peu de colonnes → colonnes plus larges ; beaucoup → plus étroites),
// donc le tableau ne peut jamais déborder de la page.
$pdf = new FPDF('P', 'mm', 'A4');
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 15);
$pdf->AddPage();
$pw = $pdf->GetPageWidth();
$uw_tbl = $pw - 20;

pdf_filigrane($pdf, $etab, $pw, $pdf->GetPageHeight());

$total_naturel = array_sum(array_map(fn($c) => $c[1], $sel));
$echelle = $total_naturel > 0 ? $uw_tbl / $total_naturel : 1;
foreach ($sel as $k => &$c) { $c[1] = floor($c[1] * $echelle * 10) / 10; }
unset($c);
// La dernière colonne absorbe l'arrondi restant pour un total exact.
$derniere_cle = array_key_last($sel);
$sel[$derniere_cle][1] += $uw_tbl - array_sum(array_map(fn($c) => $c[1], $sel));

pdf_entete($pdf, $etab, $pw);
pdf_bandeau($pdf, 'LISTE PROVISOIRE', 'PROVISIONAL LIST', $pw);

// Méta
$pdf->SetFont('Arial', 'B', 8);
$pdf->Cell(0, 5, pdf_u("Année scolaire/Academic year : " . $annee['libelle'] . "    Classe/Class : " . ($classe['designation'] ?? 'Toutes/All')), 0, 1, 'L');
$pdf->SetFont('Arial', '', 7.5);
$pdf->Cell(0, 4.5, pdf_u("Total : $total élève(s)/students   Garçons/Boys : $nb_m   Filles/Girls : $nb_f"), 0, 1, 'L');

// Effectif tableau (NV/RED/TOTAL)
if ($id_classe) {
    $nv = (int)db_val("SELECT COUNT(*) FROM inscription WHERE id_classe=? AND id_annee=? AND statut='Nouveau'",[$id_classe,$id_annee]);
    $rd = (int)db_val("SELECT COUNT(*) FROM inscription WHERE id_classe=? AND id_annee=? AND statut='Redoublant'",[$id_classe,$id_annee]);
    $pdf->SetXY($pw - 60, $pdf->GetY() - 9);
    $pdf->SetFont('Arial','B',7); $pdf->SetFillColor(214,234,248);
    $pdf->Cell(12,4,'',0,0,'C'); $pdf->Cell(12,4,'M',1,0,'C',true); $pdf->Cell(12,4,'F',1,0,'C',true); $pdf->Cell(12,4,'T',1,1,'C',true);
    $pdf->SetX($pw-60); $pdf->SetFont('Arial','',7);
    $pdf->Cell(12,4,'NV',1,0,'C'); $pdf->Cell(12,4,(string)(int)db_val("SELECT SUM(e.sexe='M') FROM inscription i JOIN eleve e ON e.id=i.id_eleve WHERE i.id_classe=$id_classe AND i.id_annee=$id_annee AND i.statut='Nouveau'"),1,0,'C'); $pdf->Cell(12,4,(string)(int)db_val("SELECT SUM(e.sexe='F') FROM inscription i JOIN eleve e ON e.id=i.id_eleve WHERE i.id_classe=$id_classe AND i.id_annee=$id_annee AND i.statut='Nouveau'"),1,0,'C'); $pdf->Cell(12,4,(string)$nv,1,1,'C');
    $pdf->SetX($pw-60);
    $pdf->Cell(12,4,'RED',1,0,'C'); $pdf->Cell(12,4,(string)(int)db_val("SELECT SUM(e.sexe='M') FROM inscription i JOIN eleve e ON e.id=i.id_eleve WHERE i.id_classe=$id_classe AND i.id_annee=$id_annee AND i.statut='Redoublant'"),1,0,'C'); $pdf->Cell(12,4,(string)(int)db_val("SELECT SUM(e.sexe='F') FROM inscription i JOIN eleve e ON e.id=i.id_eleve WHERE i.id_classe=$id_classe AND i.id_annee=$id_annee AND i.statut='Redoublant'"),1,0,'C'); $pdf->Cell(12,4,(string)$rd,1,1,'C');
    $pdf->SetX($pw-60);
    $pdf->SetFont('Arial','B',7);
    $pdf->Cell(12,4,'TOTAL',1,0,'C'); $pdf->Cell(12,4,(string)$nb_m,1,0,'C'); $pdf->Cell(12,4,(string)$nb_f,1,0,'C'); $pdf->Cell(12,4,(string)$total,1,1,'C');
}
$pdf->Ln(1);

// ── Entête du tableau ─────────────────────────────────────────
$pdf->SetFillColor(30, 79, 216);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('Arial', 'B', 7.5);
foreach ($sel as $col) {
    $pdf->Cell($col[1], 6, pdf_u($col[0]), 1, 0, 'C', true);
}
$pdf->Ln();
$pdf->SetTextColor(0);

// ── Lignes ────────────────────────────────────────────────────
$fill = false; $no = 1;
foreach ($eleves as $el) {
    $pdf->SetFillColor(234, 244, 251);
    $pdf->SetFont('Arial', '', 7);
    foreach ($sel as $k => $col) {
        if ($k === 'no') {
            $val = (string)$no;
        } elseif ($k === 'nom') {
            $val = strtoupper($el['nom']) . ' ' . ($el['prenom'] ?? '');
        } elseif ($k === 'date') {
            $val = $el['date_naiss'] ? date('d/m/Y', strtotime($el['date_naiss'])) : '—';
        } elseif ($k === 'matricule') {
            $val = id_affichage_eleve($el);
        } else {
            $val = $el[$col[2]] ?? '—';
        }
        $pdf->Cell($col[1], 5.5, pdf_u((string)$val), 'B', 0, $col[3], $fill);
    }
    $pdf->Ln(); $fill = !$fill; $no++;
}

// ── Lieu, date et fonction du signataire (bas de page, à droite) ──────
$pdf->Ln(10);
if ($pdf->GetY() > $pdf->GetPageHeight() - 30) $pdf->AddPage();
$w_sign = $uw_tbl * 0.4;
$x_sign = $pw - 25 - $w_sign; // décalé du bord droit (pas collé à la marge)
$pdf->SetFont('Arial', '', 9);
$pdf->SetX($x_sign);
$pdf->Cell($w_sign, 5, pdf_u('Fait à/Done at ' . ($etab['ville'] ?? '') . ', le/on ' . date('d/m/Y')), 0, 1, 'R');
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetX($x_sign);
$pdf->Cell($w_sign, 5, pdf_u(strtoupper($etab['chef_etablissement'] ?? 'LE PROVISEUR')), 0, 1, 'R');

// Signature numérique (uniquement si demandée à l'impression — jamais
// automatique — et si l'admin en a configuré une dans les paramètres).
if (($_GET['signature'] ?? '0') === '1') {
    $sig_w = 24;
    $ph = $pdf->GetPageHeight();
    $sx = $x_sign + ($w_sign - $sig_w) / 2;
    $sy = $pdf->GetY() + 1;
    pdf_signature_appliquer($pdf, 'liste_eleves', 'chef_etablissement', 0, 0, $pw, $ph, [
        'x_pct' => $sx / $pw * 100, 'y_pct' => $sy / $ph * 100, 'w_pct' => $sig_w / $pw * 100, 'h_pct' => null,
    ]);
}

$pdf->Output($dl ? 'D' : 'I', 'liste_eleves_' . date('Ymd') . '.pdf');
