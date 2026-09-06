<?php
// ── PDF : Résultat annuel (par classe OU établissement) ─────────────
// Remplace pdf/resultat_annuel_classe.php + pdf/palmares_annuel.php
// (session 5, logique statut_promotion() simple) — utilise maintenant
// pages/resultat_annuel/commun.php (decision_conseil_annuel, cohérent avec
// le Conseil de classe et l'écran). GET : scope=classe|etablissement,
// classe (si classe), filtre (si classe), limite (si etablissement), ordre.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
require_once __DIR__ . '/../pages/resultat_annuel/commun.php';
exiger_acces_pedagogie();

require_once __DIR__ . '/fpdf.php';
require_once __DIR__ . '/header_pdf.php';

$scope     = in_array($_GET['scope'] ?? '', ['classe', 'etablissement'], true) ? $_GET['scope'] : 'classe';
$id_classe = (int) ($_GET['classe'] ?? 0);
exiger_acces_classe($id_classe, get_annee_active()['val_annee'] ?? '', 'fr');   // cloisonnement enseignant
$filtre    = in_array($_GET['filtre'] ?? '', ['admis', 'redoublants', 'exclus', 'tous'], true) ? $_GET['filtre'] : 'tous';
$limite    = max(0, (int) ($_GET['limite'] ?? 10));
$ordre     = ($_GET['ordre'] ?? 'merite') === 'alpha' ? 'alpha' : 'merite';
$dl        = ($_GET['dl'] ?? '0') === '1';
$avec_sig  = ($_GET['signature'] ?? '0') === '1';

if ($scope === 'classe' && !$id_classe) die('Classe manquante.');
if ($scope === 'etablissement') {
    exiger_role(['DIRECTEUR']); // même restriction que le palmarès de session 5
}

$annee     = get_annee_active();
$val_annee = $annee['val_annee'] ?? '';
$etab_brut = get_etablissement();
$etab      = etab_pour_pdf($etab_brut);

if ($scope === 'classe') {
    $classe = db_one("SELECT DesignationClasses FROM classe WHERE IDClasses=?", [$id_classe]);
    if (!$classe) die('Classe introuvable.');
    $lignes = resultat_annuel_filtrer(resultat_annuel_lignes_classe($id_classe, $val_annee, $ordre), $filtre);
    $titre_fr = 'RÉSULTAT ANNUEL — ' . mb_strtoupper($classe['DesignationClasses']);
    $sous_titre = ucfirst($filtre === 'tous' ? 'toute la classe' : $filtre);
} else {
    $lignes = resultat_annuel_lignes_etablissement($val_annee, $limite, $ordre);
    $titre_fr = 'PALMARÈS ANNUEL DE L\'ÉTABLISSEMENT';
    $sous_titre = $limite > 0 ? 'Top ' . $limite : 'Tous les classés';
}

// Enveloppé dans un try/catch — voir fonctions.php::pdf_erreur_generation()
// (jamais de fatal error brut ; ce document est public via QR et/ou
// consulté par du personnel qui ne doit pas voir de trace technique).
try {
$pdf = new FPDF('L', 'mm', 'A4');
$pdf->SetMargins(8, 8, 8);
$pdf->SetAutoPageBreak(true, 15);
$pdf->AddPage();
$pw = $pdf->GetPageWidth(); $ph = $pdf->GetPageHeight(); $uw = $pw - 16;

pdf_filigrane($pdf, $etab, $pw, $ph);
pdf_entete($pdf, $etab, $pw, 8, null, 0.85);
pdf_bandeau($pdf, $titre_fr, 'ANNUAL RESULT', $pw, 8);

$pdf->SetFont('Arial', 'B', 8.5);
$pdf->SetX(8);
$pdf->Cell($uw, 4.5, pdf_u($sous_titre . '   —   Année scolaire : ' . $val_annee), 0, 1, 'C');
$pdf->Ln(2);

$avec_classe = $scope === 'etablissement';
$cols = [['N°', 8], ['Matricule', 20], ['Nom et prénoms', $avec_classe ? 44 : 54]];
if ($avec_classe) $cols[] = ['Classe', 24];
$cols = array_merge($cols, [
    ['Sexe', 10], ['Moy.T1', 14], ['Moy.T2', 14], ['Moy.T3', 14], ['Abs.', 12],
    ['Moy. an.', 16], ['Rang', 16], ['Décision', 24],
]);
$fixed = array_sum(array_column($cols, 1));
$last = count($cols) - 1;
$cols[$last][1] += max(0, $uw - $fixed); // dernière colonne absorbe le reste

$pdf->SetFont('Arial', 'B', 7);
$pdf->SetFillColor(30, 79, 216); $pdf->SetTextColor(255);
$pdf->SetX(8);
foreach ($cols as $c) $pdf->Cell($c[1], 7, pdf_u($c[0]), 1, 0, 'C', true);
$pdf->Ln();
$pdf->SetTextColor(0);
$pdf->SetFont('Arial', '', 7);

$no = 1;
foreach ($lignes as $l) {
    if ($pdf->GetY() > $ph - 15) { $pdf->AddPage(); pdf_filigrane($pdf, $etab, $pw, $ph); }
    $e = $l['eleve'];
    $fill = ($no % 2 === 0);
    $pdf->SetFillColor(234, 244, 251);
    $pdf->SetX(8);
    $vals = [(string) $no, $e['Mat_elv'] ?? '', mb_strtoupper($e['Nom_elv']) . ' ' . ($e['Prenom_elv'] ?? '')];
    if ($avec_classe) $vals[] = $l['classe'] ?? '—';
    $vals = array_merge($vals, [
        stripos($e['Sexe_elv'] ?? '', 'F') === 0 ? 'F' : 'M',
        $l['moy_t'][0] !== null ? number_format($l['moy_t'][0], 2) : '—',
        $l['moy_t'][1] !== null ? number_format($l['moy_t'][1], 2) : '—',
        $l['moy_t'][2] !== null ? number_format($l['moy_t'][2], 2) : '—',
        (string) $l['abs_nj'],
        $l['moy_annuelle'] !== null ? number_format($l['moy_annuelle'], 2) : '—',
        $l['rang'] !== null ? $l['rang'] . 'e/' . $l['nb_classes'] : '—',
        $l['decision'],
    ]);
    foreach ($cols as $i => $c) { $pdf->Cell($c[1], 6, pdf_u((string) $vals[$i]), 1, 0, 'C', $fill); }
    $pdf->Ln();
    $no++;
}
if (empty($lignes)) {
    $pdf->SetX(8);
    $pdf->Cell($uw, 8, pdf_u('Aucun élève.'), 1, 1, 'C');
}

$pdf->Ln(6);
if ($pdf->GetY() > $ph - 20) { $pdf->AddPage(); pdf_filigrane($pdf, $etab, $pw, $ph); }
$w_sign = $uw * 0.3; $x_sign = $pw - 8 - $w_sign;
$pdf->SetFont('Arial', '', 8);
$pdf->SetXY($x_sign, $pdf->GetY());
$pdf->Cell($w_sign, 5, pdf_u('Fait à ' . (($etab['lieu'] ?: $etab['ville']) ?: '') . ', le ' . date('d/m/Y')), 0, 1, 'R');
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetX($x_sign);
$pdf->Cell($w_sign, 5, pdf_u(mb_strtoupper($etab['chef_etablissement'] ?: 'LE DIRECTEUR') . ','), 0, 1, 'R');

if ($avec_sig) {
    pdf_signature_appliquer_jn($pdf, 'resultat_annuel_' . $scope, 0, 0, $pw, $ph, [
        'x_pct' => ($x_sign + ($w_sign - 22) / 2) / $pw * 100,
        'y_pct' => ($pdf->GetY() + 1) / $ph * 100,
        'w_pct' => 22 / $pw * 100, 'h_pct' => null,
    ]);
}

// Copyright standard du système (pdf/header_pdf.php) — texte unique sur
// tous les PDF du projet, voir pdf_copyright().
pdf_copyright($pdf, $pw, $ph);

$pdf->Output($dl ? 'D' : 'I', 'resultat_annuel_' . $scope . '.pdf');
} catch (Throwable $e) {
    pdf_erreur_generation($e);
}
