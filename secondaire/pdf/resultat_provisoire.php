<?php
// ── PDF : Liste provisoire (effectif prévisionnel de l'année suivante) ──
// GET : annee, classe (classe CIBLE), cols, align, dl (0|1). Voir
// secondaire/pages/statistiques/resultat_annuel.php (onglet "Liste provisoire") et
// fonctions.php::calc_liste_provisoire_comp().
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_connexion();

require_once __DIR__ . '/fpdf.php';
require_once __DIR__ . '/header_pdf.php';

$role     = role_connecte();
$is_admin = in_array($role, ['ADMIN', 'PROVISEUR', 'CENSEUR']);
$is_ens   = ($role === 'ENSEIGNANT');
$mat_ens  = $is_ens ? get_matricule_ens_connecte() : null;
if (!$is_admin && !$is_ens) die('Acces non autorise.');

$id_annee        = (int)($_GET['annee']  ?? 0);
$id_classe_cible = (int)($_GET['classe'] ?? 0);
$cols            = explode(',', $_GET['cols'] ?? '');
$aligns          = explode(',', $_GET['align'] ?? '');
$dl              = ($_GET['dl'] ?? '0') === '1';

if (!$id_classe_cible) die('Classe manquante.');

if ($is_ens && $mat_ens) {
    $val_annee_ens = (string) db_val("SELECT libelle FROM annee_scolaire WHERE id=?", [$id_annee]);
    $ok = db_val(
        "SELECT COUNT(*) FROM enseignat_principal WHERE matricule_ens=? AND IDClasses=? AND val_annee=?",
        [$mat_ens, $id_classe_cible, $val_annee_ens]
    );
    if (!$ok) die('Acces refuse (professeur principal uniquement).');
}

$etab         = get_etablissement();
$annee        = db_one("SELECT * FROM annee_scolaire WHERE id=?", [$id_annee]) ?? ['libelle' => '—'];
$classe_cible = db_one("SELECT * FROM classe WHERE id=?", [$id_classe_cible]);
if (!$classe_cible) die('Classe introuvable.');
$annee_suivante_lib = libelle_annee_suivante((string)$annee['libelle']);

$rows = calc_liste_provisoire_comp($id_annee, $id_classe_cible);

$short_labels = ['no' => 'N°', 'niu' => 'NIU', 'nom' => 'Nom et Prénoms', 'date' => 'Date naiss.', 'lieu' => 'Lieu naiss.', 'sexe' => 'Sexe', 'statut' => 'Statut'];
$largeurs     = ['no' => 10, 'niu' => 28, 'nom' => 55, 'date' => 24, 'lieu' => 32, 'sexe' => 14, 'statut' => 18];
$def_cols = colonnes_liste_provisoire();
$sel = [];
foreach ($cols as $i => $k) {
    if (!isset($def_cols[$k])) continue;
    $al = in_array($aligns[$i] ?? '', ['L', 'C', 'R'], true) ? $aligns[$i] : 'L';
    $sel[$k] = [$short_labels[$k] ?? $def_cols[$k], $largeurs[$k] ?? 20, $al];
}
if (empty($sel)) {
    foreach ($def_cols as $k => $lbl) { $sel[$k] = [$short_labels[$k] ?? $lbl, $largeurs[$k] ?? 20, 'L']; }
}

$pdf = new FPDF('P', 'mm', 'A4');
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 15);
$pdf->AddPage();
$pw = $pdf->GetPageWidth();
$uw_tbl = $pw - 20;

pdf_filigrane($pdf, $etab, $pw, $pdf->GetPageHeight());
pdf_entete($pdf, $etab, $pw);
pdf_bandeau($pdf, 'LISTE PROVISOIRE', 'PROVISIONAL LIST', $pw);

$nb_red = count(array_filter($rows, fn($r) => $r['statut'] === 'RED'));
$nb_nv  = count(array_filter($rows, fn($r) => $r['statut'] === 'NV'));

$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(0, 5, pdf_u('Classe : ' . $classe_cible['designation'] . ' — Année ' . $annee_suivante_lib), 0, 1, 'L');
$pdf->SetFont('Arial', '', 8);
$pdf->Cell(0, 4.5, pdf_u("Redoublants (RED) : $nb_red    Nouveaux (NV) : $nb_nv    Total : " . count($rows)), 0, 1, 'L');
$pdf->Ln(1);

$total_naturel = array_sum(array_map(fn($c) => $c[1], $sel));
$echelle = $total_naturel > 0 ? $uw_tbl / $total_naturel : 1;
foreach ($sel as $k => &$c) { $c[1] = floor($c[1] * $echelle * 10) / 10; }
unset($c);
$derniere_cle = array_key_last($sel);
$sel[$derniere_cle][1] += $uw_tbl - array_sum(array_map(fn($c) => $c[1], $sel));

$pdf->SetFillColor(30, 79, 216);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('Arial', 'B', 8);
foreach ($sel as $col) { $pdf->Cell($col[1], 6, pdf_u($col[0]), 1, 0, 'C', true); }
$pdf->Ln();
$pdf->SetTextColor(0);

$fill = false; $no = 1;
foreach ($rows as $row) {
    $pdf->SetFillColor(234, 244, 251);
    $pdf->SetFont('Arial', '', 8);
    foreach ($sel as $k => $col) {
        $val = valeur_colonne_provisoire($k, $row, $no);
        $pdf->Cell($col[1], 5.5, pdf_u($val), 'B', 0, $col[2], $fill);
    }
    $pdf->Ln(); $fill = !$fill; $no++;
}

// Avertissement explicite en pied de document (demande explicite) : cette
// liste ne reprend que des décisions déjà enregistrées, un Admis encore
// auto-calculé n'y figure jamais tant que sa destination n'est pas connue.
$pdf->Ln(6);
if ($pdf->GetY() > $pdf->GetPageHeight() - 25) $pdf->AddPage();
$pdf->SetFont('Arial', 'I', 7.5);
$pdf->MultiCell($uw_tbl, 4, pdf_u("Cette liste ne reprend que les décisions déjà enregistrées au Conseil de Classe. Un élève admis mais pas encore examiné (destination inconnue) n'y figure pas tant que sa décision n'est pas enregistrée."), 0, 'L');

$pdf->Ln(6);
if ($pdf->GetY() > $pdf->GetPageHeight() - 30) $pdf->AddPage();
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(0, 5, pdf_u('Fait à ' . ($etab['ville'] ?? '') . ', le ' . date('d/m/Y')), 0, 1, 'R');
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(0, 5, pdf_u(strtoupper($etab['chef_etablissement'] ?? 'LE PROVISEUR')), 0, 1, 'R');

$pdf->Output($dl ? 'D' : 'I', 'liste_provisoire_' . preg_replace('/\W+/', '_', $classe_cible['designation']) . '_' . date('Ymd') . '.pdf');
