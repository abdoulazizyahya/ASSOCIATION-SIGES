<?php
// ── PDF : Liste des élèves par classe ──────────────────────────
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
exiger_connexion();

require_once __DIR__ . '/fpdf.php';
require_once __DIR__ . '/header_pdf.php';

$id_classe = (int)($_GET['classe'] ?? 0);
$cols      = explode(',', $_GET['cols'] ?? 'no,nom,date,lieu,sexe,matricule,niu');
$aligns    = explode(',', $_GET['align'] ?? '');
$dl        = ($_GET['dl'] ?? '0') === '1';
$avec_sig  = ($_GET['signature'] ?? '0') === '1';

$annee     = get_annee_active();
$val_annee = $annee['val_annee'] ?? '';

$where  = ["i.val_annee = ?"];
$params = [$val_annee];
if ($id_classe) { $where[] = "i.IDClasses=?"; $params[] = $id_classe; }
$sql_where = 'WHERE ' . implode(' AND ', $where);

$eleves = db_all(
    "SELECT e.*, c.DesignationClasses AS classe, i.Statut_elv
     FROM eleve e
     JOIN inscrire i ON i.id_eleve = e.id_eleve
     LEFT JOIN classe c ON c.IDClasses = i.IDClasses
     $sql_where AND e.statut='actif'
     ORDER BY e.Nom_elv, e.Prenom_elv", $params);

$etab_brut = get_etablissement();
$etab      = etab_pour_pdf($etab_brut);
$classe    = $id_classe ? db_one("SELECT * FROM classe WHERE IDClasses=?", [$id_classe]) : null;

$total = count($eleves);
$nb_m  = count(array_filter($eleves, fn($e) => stripos($e['Sexe_elv'], 'M') === 0));
$nb_f  = $total - $nb_m;

// ── Colonnes disponibles ──────────────────────────────────────
// [libellé FR, libellé EN, largeur, champ] — FR/EN jamais sur la même ligne
// (voir pdf_cell_bilingue() dans header_pdf.php), affichés l'un sous l'autre.
$def_cols = [
    'no'        => ['N°',              'No',         7,  null],
    'nom'       => ['Nom et Prénoms',  'Name',      55, null],
    'date'      => ['Date naiss.',     'DOB',       22, 'Date_naiss_elv'],
    'lieu'      => ['Lieu naiss.',     'Birthplace',30, 'Lieu_naiss_elv'],
    'sexe'      => ['Sexe',            'Sex',       12, 'Sexe_elv'],
    'matricule' => ['Matricule',       'ID No.',    22, 'Mat_elv'],
    'niu'       => ['NIU',             'Nat. ID',   22, 'niu'],
    'classe'    => ['Classe',          'Class',     22, 'classe'],
];
$sel = [];
foreach ($cols as $i => $k) {
    if (!isset($def_cols[$k])) continue;
    $al = in_array($aligns[$i] ?? '', ['L', 'C', 'R'], true) ? $aligns[$i] : 'L';
    $sel[$k] = [...$def_cols[$k], $al];
}
if (empty($sel)) {
    foreach ($def_cols as $k => $c) { $sel[$k] = [...$c, 'L']; }
}

// ── Création du PDF ───────────────────────────────────────────
// Enveloppé dans un try/catch — voir fonctions.php::pdf_erreur_generation()
// (jamais de fatal error brut ; ce document est public via QR et/ou
// consulté par du personnel qui ne doit pas voir de trace technique).
try {
$pdf = new FPDF('P', 'mm', 'A4');
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 15);
$pdf->AddPage();
$pw = $pdf->GetPageWidth();
$uw_tbl = $pw - 20;

pdf_filigrane($pdf, $etab, $pw, $pdf->GetPageHeight());

$total_naturel = array_sum(array_map(fn($c) => $c[2], $sel));
$echelle = $total_naturel > 0 ? $uw_tbl / $total_naturel : 1;
foreach ($sel as $k => &$c) { $c[2] = floor($c[2] * $echelle * 10) / 10; }
unset($c);
$derniere_cle = array_key_last($sel);
$sel[$derniere_cle][2] += $uw_tbl - array_sum(array_map(fn($c) => $c[2], $sel));

pdf_entete($pdf, $etab, $pw);
pdf_bandeau($pdf, 'LISTE DES ÉLÈVES', 'LIST OF STUDENTS', $pw);

$pdf->SetFont('Arial', 'B', 8);
$pdf->Cell(0, 3.8, pdf_u('Année scolaire : ' . $val_annee . '    Classe : ' . ($classe['DesignationClasses'] ?? 'Toutes')), 0, 1, 'L');
$pdf->SetFont('Arial', 'I', 6.5);
$pdf->Cell(0, 3, pdf_u('Academic year / Class'), 0, 1, 'L');
$pdf->SetFont('Arial', 'B', 7.5);
$pdf->Cell(0, 3.8, pdf_u("Total : $total élève(s)   Garçons : $nb_m   Filles : $nb_f"), 0, 1, 'L');
$pdf->SetFont('Arial', 'I', 6.5);
$pdf->Cell(0, 3, pdf_u("Total students : $total   Boys : $nb_m   Girls : $nb_f"), 0, 1, 'L');

// Effectif tableau (Nouveau/Redoublant/Total) — Statut_elv est un champ
// historique Oui/Non (« Redoublant ? »), pas un statut à 4 valeurs.
if ($id_classe) {
    $par_statut = ['Non' => 0, 'Oui' => 0];
    foreach ($eleves as $e) {
        $par_statut[($e['Statut_elv'] ?? 'Non') === 'Oui' ? 'Oui' : 'Non']++;
    }
    $pdf->SetXY($pw - 28, $pdf->GetY() - 9);
    $pdf->SetFont('Arial','B',6.5); $pdf->SetFillColor(214,234,248);
    foreach (array_keys($par_statut) as $s) { $pdf->Cell(9,4,mb_substr(libelle_statut_insc($s),0,3),1,0,'C',true); }
    $pdf->Cell(10,4,'Tot.',1,1,'C',true);
    $pdf->SetX($pw-28); $pdf->SetFont('Arial','',7);
    foreach ($par_statut as $n) { $pdf->Cell(9,4,(string)$n,1,0,'C'); }
    $pdf->Cell(10,4,(string)$total,1,1,'C');
}
$pdf->Ln(1);

// ── Entête du tableau (FR au-dessus, EN en-dessous, interligne resserré) ──
$pdf->SetFillColor(30, 79, 216);
$pdf->SetTextColor(255, 255, 255);
foreach ($sel as $col) {
    pdf_cell_bilingue($pdf, $col[2], 7, $col[0], $col[1], 1, 'C', true, 6.5, 4.8);
}
$pdf->Ln(7);
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
            $val = mb_strtoupper($el['Nom_elv']) . ' ' . ($el['Prenom_elv'] ?? '');
        } elseif ($k === 'date') {
            $val = $el['Date_naiss_elv'] ? date('d/m/Y', strtotime($el['Date_naiss_elv'])) : '—';
        } elseif ($k === 'sexe') {
            $val = stripos($el['Sexe_elv'], 'F') === 0 ? 'F' : 'M';
        } else {
            $val = $el[$col[3]] ?? '—';
        }
        $pdf->Cell($col[2], 5.5, pdf_u((string)($val ?: '—')), 'B', 0, $col[4], $fill);
    }
    $pdf->Ln(); $fill = !$fill; $no++;
}

// ── Lieu, date et fonction du signataire (bas de page, à droite) ──────
$pdf->Ln(10);
if ($pdf->GetY() > $pdf->GetPageHeight() - 30) $pdf->AddPage();
$w_sign = $uw_tbl * 0.4;
$x_sign = $pw - 25 - $w_sign;
$pdf->SetFont('Arial', '', 9);
$pdf->SetX($x_sign);
$pdf->Cell($w_sign, 4.2, pdf_u('Fait à ' . (($etab['lieu'] ?: $etab['ville']) ?: '') . ', le ' . date('d/m/Y')), 0, 1, 'R');
$pdf->SetFont('Arial', 'I', 6.5);
$pdf->SetX($x_sign);
$pdf->Cell($w_sign, 3, 'Done at, on', 0, 1, 'R');
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetX($x_sign);
$pdf->Cell($w_sign, 4.2, pdf_u(mb_strtoupper($etab['chef_etablissement'] ?: 'LE DIRECTEUR')), 0, 1, 'R');
$pdf->SetFont('Arial', 'I', 6.5);
$pdf->SetX($x_sign);
$pdf->Cell($w_sign, 3, 'The Director', 0, 1, 'R');

// Signature numérique — uniquement si la case a été cochée à l'impression
// (jamais automatique), à la position enregistrée par l'utilisateur (ou une
// position par défaut raisonnable tant qu'elle n'a jamais été configurée).
if ($avec_sig) {
    pdf_signature_appliquer_jn($pdf, 'liste_eleves', 0, 0, $pw, $pdf->GetPageHeight(), [
        'x_pct' => ($x_sign + ($w_sign - 24) / 2) / $pw * 100,
        'y_pct' => ($pdf->GetY() + 1) / $pdf->GetPageHeight() * 100,
        'w_pct' => 24 / $pw * 100, 'h_pct' => null,
    ]);
}

pdf_copyright($pdf, $pw, $pdf->GetPageHeight());
$pdf->Output($dl ? 'D' : 'I', 'liste_eleves_' . date('Ymd') . '.pdf');
} catch (Throwable $e) {
    pdf_erreur_generation($e);
}
