<?php
// ── PDF : Liste des élèves ────────────────────────────────────
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../noyau/connexion.php';
require_once __DIR__ . '/../../../noyau/fonctions.php';
exiger_connexion();

require_once __DIR__ . '/fpdf.php';
require_once __DIR__ . '/header_pdf.php';

$id_annee  = (int)($_GET['annee']  ?? 0) ?: (int) (get_annee_active()['id'] ?? 0);
$id_classe = (int)($_GET['classe'] ?? 0);
$id_serie  = (int)($_GET['serie']  ?? 0);
exiger_acces_classe_secondaire($id_classe);   // enseignant / SG : seulement leurs classes
$cols      = explode(',', $_GET['cols'] ?? 'no,nom,date,lieu,sexe,matricule');
$aligns    = explode(',', $_GET['align'] ?? ''); // même ordre/longueur que $cols ; 'L'/'C'/'R' par colonne
$dl        = ($_GET['dl'] ?? '0') === '1';

// Ordre de tri choisi à l'impression (liste blanche — jamais de SQL venu de l'URL).
$o    = ($_GET['ordre'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
$TRIS = [
    'nom'       => "e.nom $o, e.prenom $o",
    'matricule' => "e.matricule $o, e.nom, e.prenom",
    'classe'    => "c.ordre $o, c.designation $o, e.nom, e.prenom",
    'sexe'      => "e.sexe $o, e.nom, e.prenom",
    'date'      => "e.date_naiss $o, e.nom, e.prenom",
    'lieu'      => "e.lieu_naiss $o, e.nom, e.prenom",
    'niu'       => "e.niu $o, e.nom, e.prenom",
    'statut'    => "i.statut $o, e.nom, e.prenom",
];
$order_by = $TRIS[$_GET['tri'] ?? 'nom'] ?? $TRIS['nom'];

$where    = ["e.statut='actif'", 'i.id IS NOT NULL'];
$params   = [$id_annee];
if ($id_classe) { $where[] = "i.id_classe=?"; $params[] = $id_classe; }
if ($id_serie)  { $where[] = "i.id_serie=?";  $params[] = $id_serie; }
// Toutes les classes : un enseignant / SG ne voit que les siennes (comme la liste).
$ids_classes_vis = sec_classes_ids_visibles();
if (!$id_classe && $ids_classes_vis !== null) {
    $where[] = $ids_classes_vis ? 'i.id_classe IN (' . implode(',', array_map('intval', $ids_classes_vis)) . ')' : '1=0';
}
$sql_where = 'WHERE ' . implode(' AND ', $where);

$eleves = db_all(
    "SELECT e.*, c.designation AS classe, s.libelle AS serie, i.statut AS redoublant
     FROM eleve e
     LEFT JOIN inscription i ON i.id_eleve=e.id AND i.id_annee=?
     LEFT JOIN classe c ON c.id=i.id_classe
     LEFT JOIN serie s ON s.id=i.id_serie
     $sql_where ORDER BY $order_by", $params);

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
    'matricule' => ['Matricule/ID No.',      25, 'matricule'],
    'niu'       => ['NIU',                   25, 'niu'],
    'classe'    => ['Classe/Class',          26, 'classe'],
    'serie'     => ['Série-LV2/Series',      22, 'serie'],
    'statut'    => ['Statut/Status',         18, 'redoublant'],
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

// Effectif tableau (NV/RED/TOTAL) — inscription.statut = « Oui » pour un
// redoublant (« Oui » ou « Redoublant », voir est_redoublant()), calculé sur les élèves listés.
if ($id_classe) {
    $eff = ['NV' => ['M' => 0, 'F' => 0], 'RED' => ['M' => 0, 'F' => 0]];
    foreach ($eleves as $el) $eff[est_redoublant($el['redoublant']) ? 'RED' : 'NV'][$el['sexe'] === 'F' ? 'F' : 'M']++;
    $pdf->SetXY($pw - 60, $pdf->GetY() - 9);
    $pdf->SetFont('Arial','B',7); $pdf->SetFillColor(214,234,248);
    $pdf->Cell(12,4,'',0,0,'C'); $pdf->Cell(12,4,'M',1,0,'C',true); $pdf->Cell(12,4,'F',1,0,'C',true); $pdf->Cell(12,4,'T',1,1,'C',true);
    $pdf->SetFont('Arial','',7);
    foreach ($eff as $lib => $n) {
        $pdf->SetX($pw-60);
        $pdf->Cell(12,4,$lib,1,0,'C'); $pdf->Cell(12,4,(string)$n['M'],1,0,'C'); $pdf->Cell(12,4,(string)$n['F'],1,0,'C'); $pdf->Cell(12,4,(string)($n['M']+$n['F']),1,1,'C');
    }
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
        } elseif ($k === 'niu') {
            $val = niu_eleve($el) ?: '—';
        } elseif ($k === 'statut') {
            $val = libelle_statut_insc($el['redoublant']);
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
pdf_fait_a_bilingue($pdf, $x_sign, $w_sign, lieu_edition_documents($etab), 'R', 9);
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
