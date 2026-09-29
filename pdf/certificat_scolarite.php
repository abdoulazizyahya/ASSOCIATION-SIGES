<?php
// ── PDF : Certificat de scolarité ────────────────────────────
// QR de vérification publique (même fonctionnement que les bulletins) —
// voir pdf/verif_scolarite_lib.php / verif_scolarite.php. Demande explicite
// du 26/09/2026.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
require_once __DIR__ . '/verif_scolarite_lib.php';

function u(string $s): string {
    return mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
}

$id  = (int)($_GET['id'] ?? 0);
$dl  = ($_GET['dl'] ?? '0') === '1';
if (!$id) die('Paramètre id manquant.');

$eleve = db_one("SELECT * FROM eleve WHERE id_eleve=?", [$id]);
if (!$eleve) die('Élève introuvable.');

// Accès public via le QR (jeton "vh" = hash de vérification déjà calculé
// pour ce certificat précis) : la personne qui scanne n'a pas forcément de
// compte et ne doit pas avoir à se connecter — voir verif_scolarite.php.
// En dehors de ce cas, connexion + cloisonnement enseignant normaux.
$vh_verif = (string) ($_GET['vh'] ?? '');
$acces_public = $vh_verif !== '' && scolarite_verif_hash_ok($id, id_affichage_eleve($eleve), $vh_verif);
if (!$acces_public) {
    exiger_connexion();
    exiger_acces_eleve($id, 'union');   // cloisonnement enseignant
    interdire_role('COMPTABLE', "Ce document n'est pas accessible au profil Agent financier / Comptable.");
}

require_once __DIR__ . '/fpdf.php';
require_once __DIR__ . '/header_pdf.php';
require_once __DIR__ . '/verif_lib.php';   // qr_incruster_photo(), bulletin_photo_pour_qr()
require_once __DIR__ . '/qrcode.php';

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
// Ouvert depuis le scan du QR code UNIQUEMENT : tampon « AUTHENTIQUE » en fond,
// posé par pdf_filigrane() (voir pdf_tampon_actif(), header_pdf.php). Jamais
// sur un certificat imprimé depuis l'application.
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
$pdf->SetFont('Arial', '', 11);
$pdf->SetX($ml);
// Interlignes bilingues : chaque ligne française est SUIVIE DE PRÈS de sa
// traduction anglaise (FR 4,6 mm puis EN 3,3 mm, police +1 pt), et un espace plus net
// (3,2 mm) sépare chaque paire FR/EN de la suivante — lisibilité demandée
// le 29/09/2026 (avant : FR→EN et EN→FR suivant avaient le même écart).
$pdf->Cell($uw * 0.35, 4.6, u('Je soussigné(e),'), 0, 1, 'L');
$pdf->SetX($ml);
$pdf->SetFont('Arial', 'I', 9.5);
$pdf->Cell($uw, 3.3, u('I, the undersigned'), 0, 1, 'L');

$pdf->Ln(3.2);
$pdf->SetFont('Arial', 'B', 11.5);
$pdf->SetX($ml);
$pdf->Cell($uw, 4.6, pdf_police_ajustee($pdf, u(mb_strtoupper($etab['chef_etablissement'] ?: 'DIRECTEUR') . ' DE ' . mb_strtoupper($etab['nom_fr'] ?: APP_NOM)), 'B', 11.5, $uw), 0, 1, 'C');
$pdf->SetFont('Arial', 'I', 9.5);
$pdf->SetX($ml);
$pdf->Cell($uw, 3.3, pdf_police_ajustee($pdf, u('DIRECTOR OF ' . mb_strtoupper($etab['nom_en'] ?: APP_NOM)), 'I', 9.5, $uw), 0, 1, 'C');

$pdf->Ln(3);
$champ = function (string $fr, string $val, string $en) use ($pdf, $ml, $uw): void {
    // La valeur suit l'étiquette à ~3 mm (et non plus une colonne fixe, qui
    // laissait un grand vide après « Et de », « Fils/Fille de »…).
    $pdf->SetX($ml);
    $t = pdf_police_ajustee($pdf, u($fr), '', 11, $uw * 0.50);
    $w = $pdf->GetStringWidth($t) + 3;
    $pdf->Cell($w, 4.6, $t, 0, 0, 'L');
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell($uw - $w, 4.6, u($val), 0, 1, 'L');
    $pdf->SetFont('Arial', 'I', 8.5);
    $pdf->SetX($ml);
    $pdf->Cell($uw, 3.3, u($en), 0, 1, 'L');
    $pdf->Ln(3.2);   // espace entre deux paires FR/EN
};

$champ("Certifie que l'élève", $nom_complet, 'Certifies that the student');
$champ('Fils/Fille de', $pere ?: '.....................', 'Son(daughter) of');
$champ('Et de', $mere ?: '.....................', 'And of');

$pdf->SetFont('Arial', '', 11);
$pdf->SetX($ml);
// Chaque partie suit la précédente à ~3 mm ; « Born on » / « at » restent
// alignés sous « Né(e) le » / « à ».
$t = u($sexe[0] . '(e) le');
$pdf->Cell($pdf->GetStringWidth($t) + 3, 4.6, $t, 0, 0, 'L');
$pdf->SetFont('Arial', 'B', 11);
$t = u($dnaiss);
$pdf->Cell($pdf->GetStringWidth($t) + 4, 4.6, $t, 0, 0, 'L');
$pdf->SetFont('Arial', '', 11);
$x_a = $pdf->GetX();
$t = u('à');
$pdf->Cell($pdf->GetStringWidth($t) + 3, 4.6, $t, 0, 0, 'L');
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell($ml + $uw - $pdf->GetX(), 4.6, u($eleve['Lieu_naiss_elv'] ?: '.....................'), 0, 1, 'L');
$pdf->SetFont('Arial', 'I', 8.5);
$pdf->SetX($ml);
$pdf->Cell($x_a - $ml, 3.3, 'Born on', 0, 0, 'L');
$pdf->SetX($x_a);
$pdf->Cell(10, 3.3, 'at', 0, 1, 'L');

$pdf->Ln(3.2);
$pdf->SetFont('Arial', '', 11);
$pdf->SetX($ml);
$pdf->MultiCell($uw, 4.7, u(
    'Est effectivement ' . $sexe[1] . '(e) dans mon établissement en classe de : ' . mb_strtoupper($insc['classe'])
), 0, 'L');
$pdf->SetFont('Arial', 'I', 8.5);
$pdf->SetX($ml);
$pdf->Cell($uw, 3.3, 'Is effectively part of my school in class', 0, 1, 'L');

$pdf->Ln(3.2);
$champ("Pour le compte de l'année scolaire :", $val_annee, 'On behalf of the school year');
$champ('Sous le matricule :', $eleve['Mat_elv'], 'Under the matricule');

$pdf->Ln(2);   // + 2,2 mm déjà ajoutés après la dernière paire
$pdf->SetFont('Arial', '', 10.5);
$pdf->SetX($ml);
$pdf->MultiCell($uw, 4.7, u(
    "En foi de quoi le présent Certificat est établi et délivré pour servir et valoir ce que de droit./."
), 0, 'L');
$pdf->SetFont('Arial', 'I', 9);
$pdf->SetX($ml);
$pdf->MultiCell($uw, 3.5, u(
    'In testimony whereof, the present attestation is issued for the purpose it deserves'
), 0, 'L');

$pdf->Ln(8);
$pdf->SetFont('Arial', '', 11);
$pdf->SetX($ml + $uw * 0.55);
$pdf->Cell($uw * 0.45, 4.6, u((($etab['lieu'] ?: $etab['ville']) ?: 'Ngaoundéré') . ', le ' . date('d/m/Y') . '.'), 0, 1, 'C');
$pdf->SetFont('Arial', 'I', 9);
$pdf->SetX($ml + $uw * 0.55);
$pdf->Cell($uw * 0.45, 3.3, 'On', 0, 1, 'C');
$pdf->Ln(3.2);
$pdf->SetX($ml + $uw * 0.55);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell($uw * 0.45, 4.6, u(mb_strtoupper($etab['chef_etablissement'] ?: 'LE DIRECTEUR') . ','), 0, 1, 'C');
$pdf->SetFont('Arial', 'I', 9);
$pdf->SetX($ml + $uw * 0.55);
$pdf->Cell($uw * 0.45, 3.3, 'The Director', 0, 0, 'C');

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

// ── QR de vérification (même style que les bulletins) ──────────────
// Désactive le saut de page auto pour cette zone positionnée à la main tout
// près du bas de page (voir même technique, secondaire/pdf/certificat_scolarite.php).
$pdf->SetAutoPageBreak(false);
$qr_size = 20;
$qr_x = $ml;
$qr_y = $ph - 15 - $qr_size;
$verif_url = scolarite_verif_url($id, id_affichage_eleve($eleve));
[$photo_path, $photo_est_temp] = bulletin_photo_pour_qr($eleve);
$qr_tmp = tempnam(sys_get_temp_dir(), 'sigesqrcs_') . '.png';
try {
    $qr_gen = new QRCode($verif_url, ['s' => 'qr-h']);
    $qr_img = $qr_gen->render_image();
    qr_incruster_photo($qr_img, $photo_path);
    imagepng($qr_img, $qr_tmp);
    imagedestroy($qr_img);
    $pdf->Image($qr_tmp, $qr_x, $qr_y, $qr_size, $qr_size, 'PNG');
} finally {
    if (is_file($qr_tmp)) unlink($qr_tmp);
    if ($photo_est_temp && is_file($photo_path)) unlink($photo_path);
}
$pdf->SetFont('Arial', 'I', 6.5);
$pdf->SetXY($qr_x, $qr_y + $qr_size + 1);
$pdf->Cell($qr_size, 3, u('Scanner pour vérifier'), 0, 0, 'C');

// ── Copyright standard du système (pdf/header_pdf.php) — texte unique sur
//    tous les PDF du projet, voir pdf_copyright().
pdf_copyright($pdf, $pw, $ph);

$pdf->Output($dl ? 'D' : 'I', 'certificat_scolarite_' . $eleve['Mat_elv'] . '.pdf');
} catch (Throwable $e) {
    pdf_erreur_generation($e);
}
