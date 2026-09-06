<?php
// ── PDF : Certificat de scolarité ────────────────────────────
// Simplifié par rapport à ABZ_MBE : pas de QR de vérification publique pour
// l'instant (verif_scolarite_lib.php non porté) — toujours derrière
// l'authentification normale. À ajouter plus tard si besoin.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
exiger_connexion();
interdire_role('COMPTABLE', "Ce document n'est pas accessible au profil Agent financier / Comptable.");

require_once __DIR__ . '/fpdf.php';
require_once __DIR__ . '/header_pdf.php';

function u(string $s): string {
    return mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
}

$id  = (int)($_GET['id'] ?? 0);
exiger_acces_eleve($id, 'union');   // cloisonnement enseignant
$dl  = ($_GET['dl'] ?? '0') === '1';
if (!$id) die('Paramètre id manquant.');

$eleve = db_one("SELECT * FROM eleve WHERE id_eleve=?", [$id]);
if (!$eleve) die('Élève introuvable.');

$etab_brut = get_etablissement();
$etab      = etab_pour_pdf($etab_brut);
$annee     = get_annee_active();
$val_annee = $annee['val_annee'] ?? '';

$insc = db_one(
    "SELECT i.*, c.DesignationClasses AS classe
     FROM inscrire i JOIN classe c ON c.IDClasses=i.IDClasses
     WHERE i.id_eleve=? AND i.val_annee=? LIMIT 1",
    [$id, $val_annee]
);
if (!$insc) die("Aucune inscription active pour cet élève sur l'année en cours.");

$parents = db_all("SELECT nom, prenom, sexe FROM parent WHERE id_eleve=?", [$id]);
$pere = ''; $mere = '';
foreach ($parents as $t) {
    $nom_complet = trim(mb_strtoupper($t['nom'] ?? '') . ' ' . ($t['prenom'] ?? ''));
    if ($t['sexe'] === 'Masculin' && !$pere) $pere = $nom_complet;
    elseif ($t['sexe'] === 'Feminin' && !$mere) $mere = $nom_complet;
}

// Enveloppé dans un try/catch — voir fonctions.php::pdf_erreur_generation()
// (jamais de fatal error brut ; ce document est public via QR et/ou
// consulté par du personnel qui ne doit pas voir de trace technique).
try {
$pdf = new FPDF('P', 'mm', 'A4');
$pdf->SetMargins(15, 15, 15);
$pdf->SetAutoPageBreak(true, 15);
$pdf->AddPage();
$pw = $pdf->GetPageWidth();
$ph = $pdf->GetPageHeight();
$ml = 15; $uw = $pw - 30;

pdf_filigrane($pdf, $etab, $pw, $ph);
pdf_entete($pdf, $etab, $pw, 15);
$pdf->Ln(2);

// ── Année scolaire (haut gauche) ────────────────────────────────────
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetX($ml);
$pdf->Cell($uw / 2, 4, u('Année scolaire : ' . $val_annee), 0, 1, 'L');
$pdf->SetFont('Arial', 'I', 8);
$pdf->SetX($ml);
$pdf->Cell($uw / 2, 4, 'School Year', 0, 1, 'L');
$pdf->Ln(2);

// ── Titre en bandeau pilule bleu clair ───────────────────────────────
$titre_w = min($uw, 110);
$titre_x = $ml + ($uw - $titre_w) / 2;
$titre_y = $pdf->GetY();
$pdf->SetFillColor(214, 234, 248);
$pdf->SetDrawColor(30, 79, 216);
$pdf->SetLineWidth(0.5);
if (method_exists($pdf, 'RoundedRect')) {
    $pdf->RoundedRect($titre_x, $titre_y, $titre_w, 14, 4, 'DF');
} else {
    $pdf->Rect($titre_x, $titre_y, $titre_w, 14, 'DF');
}
$pdf->SetLineWidth(0.2); $pdf->SetDrawColor(0);
$pdf->SetTextColor(20, 40, 120);
$pdf->SetFont('Arial', 'B', 14);
$pdf->SetXY($titre_x, $titre_y + 1.5);
$pdf->Cell($titre_w, 7, u('CERTIFICAT DE SCOLARITÉ'), 0, 1, 'C');
$pdf->SetFont('Arial', '', 9);
$pdf->SetX($titre_x);
$pdf->Cell($titre_w, 5, 'SCHOOL CERTIFICATE', 0, 0, 'C');
$pdf->SetTextColor(0);
$pdf->Ln(14);

$pdf->Ln(3);
$pdf->SetFont('Arial', '', 9);
$pdf->SetX($ml);
$pdf->Cell($uw, 5, u('N° _____________/CS/' . ($etab['sigle'] ?: 'JN') . '/' . date('y')), 0, 1, 'R');

// ── Corps (paragraphe légal bilingue) ────────────────────────
$sexe   = stripos($eleve['Sexe_elv'], 'F') === 0 ? ['Née', 'inscrite'] : ['Né', 'inscrit'];
$dnaiss = $eleve['Date_naiss_elv'] ? date('d/m/Y', strtotime($eleve['Date_naiss_elv'])) : '.....................';
$nom_complet = mb_strtoupper($eleve['Nom_elv']) . ' ' . ($eleve['Prenom_elv'] ?? '');

$pdf->Ln(4);
$pdf->SetFont('Arial', '', 10);
$pdf->SetX($ml);
$pdf->Cell($uw * 0.35, 6, u('Je soussigné(e),'), 0, 1, 'L');
$pdf->SetX($ml);
$pdf->SetFont('Arial', 'I', 8.5);
$pdf->Cell($uw, 4, u('I, the undersigned'), 0, 1, 'L');

$pdf->Ln(1);
$pdf->SetFont('Arial', 'B', 10.5);
$pdf->SetX($ml);
$pdf->Cell($uw, 5, u(mb_strtoupper($etab['chef_etablissement'] ?: 'DIRECTEUR') . ' DE ' . mb_strtoupper($etab['nom_fr'] ?: APP_NOM)), 0, 1, 'C');
$pdf->SetFont('Arial', 'I', 8.5);
$pdf->SetX($ml);
$pdf->Cell($uw, 4, u('DIRECTOR OF ' . mb_strtoupper($etab['nom_en'] ?: APP_NOM)), 0, 1, 'C');

$pdf->Ln(3);
$champ = function (string $fr, string $val, string $en) use ($pdf, $ml, $uw): void {
    $pdf->SetFont('Arial', '', 10);
    $pdf->SetX($ml);
    $pdf->Cell($uw * 0.32, 5.5, u($fr), 0, 0, 'L');
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell($uw * 0.68, 5.5, u($val), 0, 1, 'L');
    $pdf->SetFont('Arial', 'I', 7.5);
    $pdf->SetX($ml);
    $pdf->Cell($uw * 0.32, 3.5, u($en), 0, 1, 'L');
};

$champ("Certifie que l'élève", $nom_complet, 'Certifies that the student');
$champ('Fils/Fille de', $pere ?: '.....................', 'Son(daughter) of');
$champ('Et de', $mere ?: '.....................', 'And of');

$pdf->SetFont('Arial', '', 10);
$pdf->SetX($ml);
$pdf->Cell($uw * 0.16, 5.5, u($sexe[0] . '(e) le'), 0, 0, 'L');
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell($uw * 0.24, 5.5, u($dnaiss), 0, 0, 'L');
$pdf->SetFont('Arial', '', 10);
$pdf->Cell($uw * 0.10, 5.5, u('à'), 0, 0, 'L');
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell($uw * 0.50, 5.5, u($eleve['Lieu_naiss_elv'] ?: '.....................'), 0, 1, 'L');
$pdf->SetFont('Arial', 'I', 7.5);
$pdf->SetX($ml);
$pdf->Cell($uw * 0.16, 3.5, 'Born on', 0, 0, 'L');
$pdf->SetX($ml + $uw * 0.40);
$pdf->Cell($uw * 0.10, 3.5, 'at', 0, 1, 'L');

$pdf->Ln(2);
$pdf->SetFont('Arial', '', 10);
$pdf->SetX($ml);
$pdf->MultiCell($uw, 5.5, u(
    'Est effectivement ' . $sexe[1] . '(e) dans mon établissement en classe de : ' . mb_strtoupper($insc['classe'])
), 0, 'L');
$pdf->SetFont('Arial', 'I', 7.5);
$pdf->SetX($ml);
$pdf->Cell($uw, 3.5, 'Is effectively part of my school in class', 0, 1, 'L');

$pdf->Ln(2);
$champ("Pour le compte de l'année scolaire :", $val_annee, 'On behalf of the school year');
$champ('Sous le matricule :', $eleve['Mat_elv'], 'Under the matricule');

$pdf->Ln(4);
$pdf->SetFont('Arial', '', 9.5);
$pdf->SetX($ml);
$pdf->MultiCell($uw, 5.5, u(
    "En foi de quoi le présent Certificat est établi et délivré pour servir et valoir ce que de droit./."
), 0, 'L');
$pdf->SetFont('Arial', 'I', 8);
$pdf->SetX($ml);
$pdf->MultiCell($uw, 4, u(
    'In testimony whereof, the present attestation is issued for the purpose it deserves'
), 0, 'L');

$pdf->Ln(8);
$pdf->SetFont('Arial', '', 10);
$pdf->SetX($ml + $uw * 0.55);
$pdf->Cell($uw * 0.45, 6, u((($etab['lieu'] ?: $etab['ville']) ?: 'Ngaoundéré') . ', le ' . date('d/m/Y') . '.'), 0, 1, 'C');
$pdf->SetFont('Arial', 'I', 8);
$pdf->SetX($ml + $uw * 0.55);
$pdf->Cell($uw * 0.45, 4, 'On', 0, 1, 'C');
$pdf->Ln(2);
$pdf->SetX($ml + $uw * 0.55);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell($uw * 0.45, 6, u(mb_strtoupper($etab['chef_etablissement'] ?: 'LE DIRECTEUR') . ','), 0, 1, 'C');
$pdf->SetFont('Arial', 'I', 8);
$pdf->SetX($ml + $uw * 0.55);
$pdf->Cell($uw * 0.45, 4, 'The Director', 0, 0, 'C');

// Signature numérique (sur demande uniquement, jamais automatique), à la
// position enregistrée par l'utilisateur.
if (($_GET['signature'] ?? '0') === '1') {
    $sig_w = 26;
    $sx = $ml + $uw * 0.55 + ($uw * 0.45 - $sig_w) / 2;
    pdf_signature_appliquer_jn($pdf, 'certificat_scolarite', 0, 0, $pw, $ph, [
        'x_pct' => $sx / $pw * 100, 'y_pct' => ($pdf->GetY() + 5) / $ph * 100,
        'w_pct' => $sig_w / $pw * 100, 'h_pct' => null,
    ]);
}

// ── Copyright standard du système (pdf/header_pdf.php) — texte unique sur
//    tous les PDF du projet, voir pdf_copyright().
pdf_copyright($pdf, $pw, $ph);

$pdf->Output($dl ? 'D' : 'I', 'certificat_scolarite_' . $eleve['Mat_elv'] . '.pdf');
} catch (Throwable $e) {
    pdf_erreur_generation($e);
}
