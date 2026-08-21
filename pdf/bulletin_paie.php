<?php
// pdf/bulletin_paie.php — Bulletin de paie PDF au format CNPS camerounais,
// mise en page reprise du modèle fourni par l'utilisateur
// (BD JAYNITARE/modele/salaire.pdf, style CAMTEL) — migration v35 :
// un seul tableau Code/Rubrique/Nb/Base/Taux %/Gain/Retenue (plutôt que
// deux blocs Gains/Retenues séparés comme avant), en-tête administratif
// complet (matricule CNPS, banque, indice de grille, personnes à charge,
// ancienneté), montant en toutes lettres, bloc "Éléments de présence &
// rubriques indicatives" (cotisations patronales, si saisies).
//
// ⚠️ Les colonnes du modèle sans équivalent dans ce schéma (Direction,
// Mat. CNPS employeur, Ind.fonctionnaire — vides même dans l'exemple
// fourni) sont omises plutôt que remplies de valeurs inventées : les
// montants de cotisations CNPS/IRPP/taxes ne sont JAMAIS calculés
// automatiquement ici (barèmes légaux non fournis/confirmés — risque
// réel de bulletin faux) — seules les lignes explicitement saisies par
// le Directeur (voir pages/paie/periode.php, ligne Gain/Retenue avec
// code/base/taux % optionnels) apparaissent dans le tableau.
//
// Accès : DIRECTEUR (tout bulletin) ou l'enseignant concerné lui-même
// (même règle que pages/paie/bulletin.php).
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
require_once __DIR__ . '/../paie_fonctions.php';
exiger_connexion();

require_once __DIR__ . '/fpdf.php';
require_once __DIR__ . '/header_pdf.php';

$id = (int) ($_GET['id'] ?? 0);
$dl = ($_GET['dl'] ?? '0') === '1';
$bulletin = db_one(
    "SELECT b.*, e.*, p.libelle AS periode_libelle, p.mois, p.annee
     FROM bulletin_paie b JOIN enseignant e ON e.matricule_ens=b.matricule_ens JOIN periode_paie p ON p.id=b.id_periode
     WHERE b.id=?", [$id]
);
if (!$bulletin) die('Bulletin introuvable.');

$role = role_connecte();
$mon_matricule = (int) (utilisateur_connecte()['matricule_ens'] ?? 0);
if ($role !== 'DIRECTEUR' && $mon_matricule !== (int) $bulletin['matricule_ens']) {
    die('Accès refusé.');
}

$lignes = db_all("SELECT * FROM ligne_bulletin_paie WHERE id_bulletin=? ORDER BY ordre_affichage, id", [$id]);
$grade  = $bulletin['code_grade'] ? grade_par_code($bulletin['code_grade']) : null;
$contrat = contrat_actif_enseignant((int) $bulletin['matricule_ens']);

$etab_brut = get_etablissement();
$etab      = etab_pour_pdf($etab_brut);

// Période du bulletin (mois calendaire complet).
$periode_debut = sprintf('%04d-%02d-01', (int) $bulletin['annee'], (int) $bulletin['mois']);
$periode_fin   = date('Y-m-t', strtotime($periode_debut));

// Enveloppé dans un try/catch — voir fonctions.php::pdf_erreur_generation()
// (jamais de fatal error brut ; ce document est public via QR et/ou
// consulté par du personnel qui ne doit pas voir de trace technique).
try {
$pdf = new FPDF('P', 'mm', 'A4');
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 15);
$pdf->AddPage();
$pw = $pdf->GetPageWidth();
$ph = $pdf->GetPageHeight();
$ML = 10; $MR = $pw - 10; $LARGEUR = $MR - $ML;

pdf_filigrane($pdf, $etab, $pw, $ph);

// ── Bandeau titre (logo + école à gauche, "BULLETIN DE PAIE" à droite) ──
$y0 = 10;
if (!empty($etab['logo'])) {
    $logo_path = __DIR__ . '/../assets/uploads/' . $etab['logo'];
    if (is_file($logo_path)) $pdf->Image($logo_path, $ML, $y0, 16, 16);
}
$pdf->SetXY($ML + 19, $y0);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(90, 5, pdf_u(mb_strtoupper($etab['nom_fr'] ?? APP_NOM)), 0, 2, 'L');
$pdf->SetX($ML + 19);
$pdf->SetFont('Arial', '', 7.5);
$pdf->Cell(90, 4, pdf_u('B.P. ' . ($etab['boite_postale'] ?? '') . '  Tél.: ' . ($etab['telephone'] ?? '')), 0, 1, 'L');
$pdf->SetXY($ML, $y0);
$pdf->SetFont('Arial', 'B', 17);
$pdf->SetTextColor(30, 79, 216);
$pdf->Cell($LARGEUR, 16, pdf_u('BULLETIN DE PAIE'), 0, 1, 'R');
$pdf->SetTextColor(0);
$pdf->SetY($y0 + 18);
$pdf->SetDrawColor(30, 79, 216);
$pdf->SetLineWidth(0.5);
$pdf->Line($ML, $pdf->GetY(), $MR, $pdf->GetY());
$pdf->SetLineWidth(0.2);
$pdf->Ln(2);

// ── En-tête administratif (grille 2 colonnes, style CNPS) ────────────
// Valeur de repli "—" pour toute donnée non renseignée (jamais une valeur
// inventée) — voir le disclaimer en tête de fichier.
$v = fn($x) => ($x !== null && $x !== '') ? (string) $x : '—';
$nom_complet = mb_strtoupper($bulletin['nom_ens']) . ' ' . ($bulletin['prenom_ens'] ?? '');

$champ = function (float $x, float $y, float $w_lib, float $w_val, string $label, string $valeur, bool $gras = false) use ($pdf): void {
    $pdf->SetXY($x, $y);
    $pdf->SetFont('Arial', '', 7.5);
    $pdf->SetTextColor(90, 90, 90);
    $pdf->Cell($w_lib, 5, pdf_u($label), 0, 0, 'L');
    $pdf->SetTextColor(0);
    $pdf->SetFont('Arial', $gras ? 'B' : '', 8);
    $pdf->Cell($w_val, 5, pdf_u($valeur), 0, 0, 'L');
};

$y_grille = $pdf->GetY();
$demi = $LARGEUR / 2;
$w_lib = 38; $w_val = $demi - $w_lib - 3;

$col_g = [
    ['Établissement',    $v($etab['nom_fr'] ?? null)],
    ['Matricule CNPS',   $v($bulletin['matricule_cnps'] ?? null)],
    ['État civil',       $v($bulletin['situation_ens'] ?? null)],
    ['Enf. / Pers. charge', 'EN ' . str_pad((string) (int) ($bulletin['nb_enfants'] ?? 0), 2, '0', STR_PAD_LEFT) . '  PE ' . str_pad((string) (int) ($bulletin['nb_pers_charge'] ?? 0), 2, '0', STR_PAD_LEFT)],
    ['Mode de paiement', $v($bulletin['mode_paiement'] ?? null)],
    ['Banque',           $v($bulletin['nom_banque'] ?? null)],
    ['N° de compte',     $v($bulletin['compte_bancaire'] ?? null)],
];
$col_d = [
    ['Matricule',   (string) $bulletin['matricule_ens'] . '  ' . $nom_complet, true],
    ['Type paie',   'Paie mensuelle'],
    ['Sit. adm.',   $contrat ? libelle_type_contrat($contrat['type_contrat']) : '—'],
    ['Emploi',      libelle_role($bulletin['id_fonction'] ?? '')],
    ['Sal. base',   number_format((float) $bulletin['salaire_base'], 0, ',', ' ') . '   Jours base : 30'],
    ['Pos. grille', $v($bulletin['indice_grille'] ?? null)],
    ['Période',     'Du ' . date_fr($periode_debut) . ' au ' . date_fr($periode_fin)],
    ['Ancienneté',  $v(anciennete_libelle($bulletin['date_recrutement'] ?? null) ?: null)],
];

$y = $y_grille;
foreach ($col_g as [$label, $valeur]) { $champ($ML, $y, $w_lib, $w_val, $label, $valeur); $y += 5.2; }
$y_fin_g = $y;

$y = $y_grille;
$xd = $ML + $demi + 3;
foreach ($col_d as $c) { $champ($xd, $y, $w_lib, $w_val, $c[0], $c[1], $c[2] ?? false); $y += 5.2; }
$y_fin_d = $y;

$pdf->SetY(max($y_fin_g, $y_fin_d) + 2);
$pdf->SetDrawColor(180, 180, 180);
$pdf->Line($ML, $pdf->GetY(), $MR, $pdf->GetY());
$pdf->Ln(3);

// ── Tableau unifié des rubriques (Code/Rubrique/Nb/Base/Taux %/Gain/Retenue) ──
$w = ['code' => 12, 'rubrique' => 62, 'nb' => 12, 'base' => 26, 'taux' => 16, 'gain' => 29, 'retenue' => 29];
// 12+62+12+26+16+29+29 = 186 = $LARGEUR (A4 portrait, marges 10mm)

$entete_tableau = function () use ($pdf, $w, $ML): void {
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->SetFillColor(30, 79, 216);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetX($ML);
    $pdf->Cell($w['code'], 6, pdf_u('Code'), 1, 0, 'C', true);
    $pdf->Cell($w['rubrique'], 6, pdf_u('Rubrique'), 1, 0, 'L', true);
    $pdf->Cell($w['nb'], 6, pdf_u('Nb'), 1, 0, 'C', true);
    $pdf->Cell($w['base'], 6, pdf_u('Base'), 1, 0, 'R', true);
    $pdf->Cell($w['taux'], 6, pdf_u('Taux %'), 1, 0, 'C', true);
    $pdf->Cell($w['gain'], 6, pdf_u('Gain'), 1, 0, 'R', true);
    $pdf->Cell($w['retenue'], 6, pdf_u('Retenue'), 1, 1, 'R', true);
    $pdf->SetTextColor(0);
};
$entete_tableau();

$num = fn($v) => $v === null ? '' : number_format((float) $v, 0, ',', ' ');
$numt = fn($v) => $v === null ? '' : rtrim(rtrim(number_format((float) $v, 2, ',', ' '), '0'), ',');

$patronales = [];
foreach ($lignes as $l) {
    if ($l['type_ligne'] === 'Patronal') { $patronales[] = $l; continue; }

    if ($pdf->GetY() > $ph - 55) { $pdf->AddPage(); pdf_filigrane($pdf, $etab, $pw, $ph); $entete_tableau(); }

    $sous_total = $l['type_ligne'] === 'Sous-total';
    $pdf->SetFont('Arial', $sous_total ? 'B' : '', 8);
    if ($sous_total) $pdf->SetFillColor(230, 230, 230);
    $remplir = $sous_total;

    $pdf->SetX($ML);
    $pdf->Cell($w['code'], 5.5, pdf_u($l['code_rubrique'] ?? ''), 1, 0, 'C', $remplir);
    $pdf->Cell($w['rubrique'], 5.5, pdf_u($l['libelle']), 1, 0, 'L', $remplir);
    $pdf->Cell($w['nb'], 5.5, $l['nb'] !== null ? $numt($l['nb']) : '', 1, 0, 'C', $remplir);
    $pdf->Cell($w['base'], 5.5, $l['base'] !== null ? $num($l['base']) : '', 1, 0, 'R', $remplir);
    $pdf->Cell($w['taux'], 5.5, $l['taux_pct'] !== null ? $numt($l['taux_pct']) : '', 1, 0, 'C', $remplir);
    if ($sous_total) {
        // Ligne repère (BRUT/SALAIRE NET) : le montant est dans "Base" (déjà
        // affiché ci-dessus), les colonnes Gain/Retenue restent vides — même
        // convention visuelle que le modèle fourni pour ces lignes.
        $pdf->Cell($w['gain'], 5.5, '', 1, 0, 'R', $remplir);
        $pdf->Cell($w['retenue'], 5.5, '', 1, 1, 'R', $remplir);
    } else {
        $pdf->Cell($w['gain'], 5.5, $l['type_ligne'] === 'Gain' ? $num($l['montant']) : '', 1, 0, 'R');
        $pdf->Cell($w['retenue'], 5.5, $l['type_ligne'] === 'Retenue' ? $num($l['montant']) : '', 1, 1, 'R');
    }
}

// ── Totaux / Net à payer / Montant en lettres ─────────────────────────
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetFillColor(240, 240, 240);
$pdf->SetX($ML);
$pdf->Cell($w['code'] + $w['rubrique'] + $w['nb'] + $w['base'] + $w['taux'], 6, pdf_u('Totaux des gains et des retenues'), 1, 0, 'R', true);
$pdf->Cell($w['gain'], 6, $num($bulletin['brut']), 1, 0, 'R', true);
$pdf->Cell($w['retenue'], 6, $num($bulletin['total_retenues']), 1, 1, 'R', true);

$pdf->SetFont('Arial', 'B', 11);
$pdf->SetFillColor(30, 79, 216);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetX($ML);
$pdf->Cell($LARGEUR * 0.7, 9, pdf_u('NET À PAYER'), 1, 0, 'L', true);
$pdf->Cell($LARGEUR * 0.3, 9, $num($bulletin['net_a_payer']) . ' FCFA', 1, 1, 'R', true);
$pdf->SetTextColor(0);

$pdf->SetFont('Arial', 'BI', 8.5);
$pdf->SetFillColor(250, 250, 240);
$pdf->SetX($ML);
$pdf->Cell($LARGEUR, 7, pdf_u(montant_en_lettres((float) $bulletin['net_a_payer'])), 1, 1, 'C', true);
$pdf->Ln(3);

// ── Éléments de présence & rubriques indicatives (cotisations patronales) ──
// Purement informatif — n'affecte jamais le net à payer. Uniquement affiché
// si le Directeur en a explicitement saisi (voir disclaimer en tête de fichier).
if ($patronales) {
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetFillColor(245, 245, 245);
    $pdf->SetX($ML);
    $pdf->Cell($LARGEUR, 6, pdf_u('Éléments de présence & rubriques indicatives (charges patronales)'), 1, 1, 'C', true);
    foreach ($patronales as $l) {
        if ($pdf->GetY() > $ph - 40) { $pdf->AddPage(); pdf_filigrane($pdf, $etab, $pw, $ph); }
        $pdf->SetFont('Arial', '', 8);
        $pdf->SetX($ML);
        $pdf->Cell($w['code'], 5.5, pdf_u($l['code_rubrique'] ?? ''), 1, 0, 'C');
        $pdf->Cell($w['rubrique'], 5.5, pdf_u($l['libelle']), 1, 0, 'L');
        $pdf->Cell($w['nb'], 5.5, $l['nb'] !== null ? $numt($l['nb']) : '', 1, 0, 'C');
        $pdf->Cell($w['base'], 5.5, $l['base'] !== null ? $num($l['base']) : '', 1, 0, 'R');
        $pdf->Cell($w['taux'], 5.5, $l['taux_pct'] !== null ? $numt($l['taux_pct']) : '', 1, 0, 'C');
        $pdf->Cell($w['gain'] + $w['retenue'], 5.5, $num($l['montant']), 1, 1, 'R');
    }
    $pdf->Ln(3);
}

// ── Statut de paiement ─────────────────────────────────────────────
$pdf->SetFont('Arial', '', 8.5);
$pdf->SetX($ML);
if ($bulletin['statut'] === 'Payé') {
    $pdf->Cell(0, 5, pdf_u('Payé le ' . date_fr($bulletin['date_paiement']) . ($bulletin['mode_paiement'] ? ' — ' . $bulletin['mode_paiement'] : '') . ($bulletin['reference_paiement'] ? ' — Réf. ' . $bulletin['reference_paiement'] : '')), 0, 1, 'L');
} else {
    $pdf->Cell(0, 5, pdf_u('Statut : ' . $bulletin['statut']), 0, 1, 'L');
}
$pdf->SetFont('Arial', '', 7);
$pdf->SetTextColor(140);
$pdf->SetX($ML);
$pdf->Cell(0, 4, pdf_u('N° ' . numero_bulletin($id) . ' — Édité le ' . date('d/m/Y H:i')), 0, 1, 'L');
$pdf->SetTextColor(0);

// ── Lieu, date et signature (bas de page) ─────────────────────────
$pdf->Ln(8);
if ($pdf->GetY() > $ph - 30) { $pdf->AddPage(); pdf_filigrane($pdf, $etab, $pw, $ph); }
$w_sign = $LARGEUR * 0.4;
$x_sign = $MR - $w_sign;
$pdf->SetFont('Arial', '', 9);
$pdf->SetX($x_sign);
$pdf->Cell($w_sign, 5, pdf_u('Fait à ' . (($etab['lieu'] ?: $etab['ville']) ?: '') . ', le ' . date('d/m/Y')), 0, 1, 'R');
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetX($x_sign);
$pdf->Cell($w_sign, 5, pdf_u(mb_strtoupper($etab['chef_etablissement'] ?: 'LE DIRECTEUR') . ','), 0, 1, 'R');

// Copyright standard du système (pdf/header_pdf.php) — texte unique sur tous
// les PDF du projet, voir pdf_copyright().
pdf_copyright($pdf, $pw, $ph);

$nom_fichier = numero_bulletin($id);
$pdf->Output($dl ? 'D' : 'I', $nom_fichier . '.pdf');
} catch (Throwable $e) {
    pdf_erreur_generation($e);
}
