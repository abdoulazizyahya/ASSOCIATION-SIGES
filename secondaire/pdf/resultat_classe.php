<?php
// ── PDF : Résultat par classe (Admis / Redoublants / Exclus / Toute la
// classe) ── GET : annee, classe, filtre (admis|redoublement|exclu|tous),
// cols, align, dl (0|1), signature (0|1). Voir
// secondaire/pages/statistiques/resultat_annuel.php (onglet "Résultat par classe") et
// fonctions.php::calc_resultat_annuel_comp().
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_connexion();

require_once __DIR__ . '/fpdf.php';
require_once __DIR__ . '/header_pdf.php';

$role     = role_connecte();
$is_admin = in_array($role, ['ADMIN', 'PROVISEUR', 'FONDATEUR', 'CENSEUR']) || $role === 'MEMBRE_ASSOCIATION';
$is_ens   = ($role === 'ENSEIGNANT');
$mat_ens  = $is_ens ? get_matricule_ens_connecte() : null;
if (!$is_admin && !$is_ens) die('Acces non autorise.');

$id_annee  = (int)($_GET['annee']  ?? 0);
$id_classe = (int)($_GET['classe'] ?? 0);
$filtre    = in_array($_GET['filtre'] ?? '', ['admis', 'redoublement', 'exclu', 'tous'], true) ? $_GET['filtre'] : 'tous';
$cols      = explode(',', $_GET['cols'] ?? '');
$aligns    = explode(',', $_GET['align'] ?? '');
$dl        = ($_GET['dl'] ?? '0') === '1';

if (!$id_classe) die('Classe manquante.');

if ($is_ens && $mat_ens) {
    $val_annee_ens = (string) db_val("SELECT libelle FROM annee_scolaire WHERE id=?", [$id_annee]);
    $ok = db_val(
        "SELECT COUNT(*) FROM enseignat_principal WHERE matricule_ens=? AND IDClasses=? AND val_annee=?",
        [$mat_ens, $id_classe, $val_annee_ens]
    );
    if (!$ok) die('Acces refuse (professeur principal uniquement).');
}

$etab   = get_etablissement();
$annee  = db_one("SELECT * FROM annee_scolaire WHERE id=?", [$id_annee]) ?? ['libelle' => '—'];
$classe = db_one("SELECT * FROM classe WHERE id=?", [$id_classe]);
if (!$classe) die('Classe introuvable.');

$filtre_map      = ['admis' => 'Admis', 'redoublement' => 'Redoublement', 'exclu' => 'Exclu'];
$filtre_decision = $filtre_map[$filtre] ?? null;
$filtre_titre    = ['admis' => 'LISTE DES ADMIS', 'redoublement' => 'LISTE DES REDOUBLANTS', 'exclu' => 'LISTE DES EXCLUS', 'tous' => 'RESULTAT DE LA CLASSE'][$filtre];

$rows = calc_resultat_annuel_comp($id_annee, $id_classe);
if ($filtre_decision) {
    $rows = array_values(array_filter($rows, fn($r) => $r['decision'] === $filtre_decision));
}

// ── Colonnes sélectionnées — libellés courts pour l'en-tête PDF uniquement
// (jusqu'à 15 colonnes sur une page paysage : les libellés complets du
// sélecteur/Excel débordent sur la colonne voisine, FPDF::Cell() ne
// tronquant pas le texte qui dépasse).
$short_labels = [
    'no' => 'N°', 'niu' => 'NIU', 'nom' => 'Nom et Prénoms', 'date' => 'Date naiss.',
    'lieu' => 'Lieu naiss.', 'sexe' => 'Sexe', 'classe' => 'Classe',
    't1' => 'T1', 't2' => 'T2', 't3' => 'T3', 'abs' => 'Abs.NJ(h)', 'moy_an' => 'Moy.Ann.',
    'rang' => 'Rang', 'decision' => 'Décision', 'classe_suiv' => 'Cl. suivante', 'obs' => 'Notes',
];
$largeurs = [
    'no' => 8, 'niu' => 24, 'nom' => 40, 'date' => 20, 'lieu' => 24, 'sexe' => 10,
    't1' => 15, 't2' => 15, 't3' => 15, 'abs' => 18, 'moy_an' => 17, 'rang' => 13,
    'decision' => 22, 'classe_suiv' => 22, 'obs' => 28,
];
$def_cols = colonnes_resultat_classe(false);
$sel = [];
foreach ($cols as $i => $k) {
    if (!isset($def_cols[$k])) continue;
    $al = in_array($aligns[$i] ?? '', ['L', 'C', 'R'], true) ? $aligns[$i] : 'L';
    $sel[$k] = [$short_labels[$k] ?? $def_cols[$k], $largeurs[$k] ?? 20, $al];
}
if (empty($sel)) { // garde-fou : jamais de tableau vide
    foreach ($def_cols as $k => $lbl) { $sel[$k] = [$short_labels[$k] ?? $lbl, $largeurs[$k] ?? 20, 'L']; }
}

// ── Création du PDF (paysage — nombreuses colonnes) ──────────────────
$pdf = new FPDF('L', 'mm', 'A4');
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 15);
$pdf->AddPage();
$pw = $pdf->GetPageWidth();
$uw_tbl = $pw - 20;

pdf_filigrane($pdf, $etab, $pw, $pdf->GetPageHeight());
pdf_entete($pdf, $etab, $pw);
pdf_bandeau($pdf, $filtre_titre, $filtre_titre, $pw);

$total = count($rows);
$nb_m  = count(array_filter($rows, fn($r) => $r['sexe'] === 'M'));
$nb_f  = $total - $nb_m;

$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(0, 5, pdf_u("Année scolaire : " . $annee['libelle'] . "    Classe : " . $classe['designation']), 0, 1, 'L');
$pdf->SetFont('Arial', '', 8);
$pdf->Cell(0, 4.5, pdf_u("Total : $total élève(s)   Garçons : $nb_m   Filles : $nb_f"), 0, 1, 'L');
$pdf->Ln(1);

$total_naturel = array_sum(array_map(fn($c) => $c[1], $sel));
$echelle = $total_naturel > 0 ? $uw_tbl / $total_naturel : 1;
foreach ($sel as $k => &$c) { $c[1] = floor($c[1] * $echelle * 10) / 10; }
unset($c);
$derniere_cle = array_key_last($sel);
$sel[$derniere_cle][1] += $uw_tbl - array_sum(array_map(fn($c) => $c[1], $sel));

$pdf->SetFillColor(30, 79, 216);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('Arial', 'B', 7.5);
foreach ($sel as $col) { $pdf->Cell($col[1], 6, pdf_u($col[0]), 1, 0, 'C', true); }
$pdf->Ln();
$pdf->SetTextColor(0);

$fill = false; $no = 1;
foreach ($rows as $row) {
    $pdf->SetFillColor(234, 244, 251);
    $pdf->SetFont('Arial', '', 7);
    foreach ($sel as $k => $col) {
        $val = valeur_colonne_resultat($k, $row, $no);
        $pdf->Cell($col[1], 5.5, pdf_u($val), 'B', 0, $col[2], $fill);
    }
    $pdf->Ln(); $fill = !$fill; $no++;
}

// ── Lieu, date et fonction du signataire (bas de page, à droite) ──────
$pdf->Ln(10);
if ($pdf->GetY() > $pdf->GetPageHeight() - 30) $pdf->AddPage();
$w_sign = $uw_tbl * 0.35;
$x_sign = $pw - 25 - $w_sign;
$pdf->SetFont('Arial', '', 9);
$pdf->SetX($x_sign);
$pdf->Cell($w_sign, 5, pdf_u('Fait à ' . ($etab['ville'] ?? '') . ', le ' . date('d/m/Y')), 0, 1, 'R');
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetX($x_sign);
$pdf->Cell($w_sign, 5, pdf_u(strtoupper($etab['chef_etablissement'] ?? 'LE PROVISEUR')), 0, 1, 'R');

if (($_GET['signature'] ?? '0') === '1') {
    $sig_w = 24;
    $ph = $pdf->GetPageHeight();
    $sx = $x_sign + ($w_sign - $sig_w) / 2;
    $sy = $pdf->GetY() + 1;
    pdf_signature_appliquer($pdf, 'resultat_classe', 'chef_etablissement', 0, 0, $pw, $ph, [
        'x_pct' => $sx / $pw * 100, 'y_pct' => $sy / $ph * 100, 'w_pct' => $sig_w / $pw * 100, 'h_pct' => null,
    ]);
}

$pdf->Output($dl ? 'D' : 'I', 'resultat_' . $filtre . '_' . preg_replace('/\W+/', '_', $classe['designation']) . '_' . date('Ymd') . '.pdf');
