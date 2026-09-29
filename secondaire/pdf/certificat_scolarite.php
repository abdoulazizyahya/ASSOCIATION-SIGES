<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
// Bibliothèque PARTAGÉE avec le primaire (pas de duplicata) : mêmes
// fonctions hash/URL utilisées par pdf/certificat_scolarite.php (primaire)
// et verif_scolarite.php — schéma-agnostique (id_eleve + matricule en
// chaînes, jamais de requête SQL dedans). Corrige au passage l'absence de
// &ec= dans l'URL du QR (verif_ajout_ec(), manquant dans l'ancien duplicata
// secondaire/pdf/verif_scolarite_lib.php, supprimé) — cassait la
// vérification en multi-établissement. Demande explicite du 26/09/2026.
require_once __DIR__ . '/../../pdf/verif_scolarite_lib.php';

function u(string $s): string {
    return mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
}

$id_eleve = (int)($_GET['id'] ?? 0);
$dl       = ($_GET['dl'] ?? '0') === '1';
if (!$id_eleve) die('Parametre id manquant.');

$eleve = db_one("SELECT * FROM eleve WHERE id=?", [$id_eleve]);
if (!$eleve) die('Eleve introuvable.');

// Accès public via le QR code du certificat (jeton "vh" = hash de
// vérification déjà calculé pour ce certificat précis) : la personne qui
// scanne n'a pas forcément de compte dans le système et ne doit pas avoir à
// s'y connecter pour consulter le document qu'elle vient de vérifier (voir
// verif_scolarite.php). En dehors de ce cas, connexion normale exigée.
$vh_verif = (string)($_GET['vh'] ?? '');
$acces_public = $vh_verif !== '' && hash_equals(scolarite_verif_hash($id_eleve, id_affichage_eleve($eleve)), $vh_verif);
if (!$acces_public) {
    exiger_connexion();
    exiger_acces_eleve_secondaire($id_eleve);   // enseignant / SG : seulement leurs classes
}
require_once __DIR__ . '/fpdf.php';
require_once __DIR__ . '/header_pdf.php'; // pour pdf_filigrane()
require_once __DIR__ . '/qrcode.php';

$etab      = get_etablissement();
$annee_act = get_annee_active();
$val_annee = $annee_act['libelle'] ?? '';

$insc = db_one(
    "SELECT i.*, c.designation AS classe
     FROM inscription i JOIN classe c ON c.id=i.id_classe
     WHERE i.id_eleve=? AND i.id_annee=? LIMIT 1",
    [$id_eleve, (int)($annee_act['id'] ?? 0)]
);
if (!$insc) die("Aucune inscription active pour cet eleve sur l'annee en cours.");

// Père / mère (via la table tuteur, "lien" en texte libre) — vide si non
// renseigné, comme sur le modèle de référence (others/scolarite.pdf).
$tuteurs = db_all("SELECT nom, prenom, lien FROM tuteur WHERE id_eleve=?", [$id_eleve]);
$pere = ''; $mere = '';
foreach ($tuteurs as $t) {
    $lien = mb_strtolower($t['lien'] ?? '');
    $nom_tuteur = trim(strtoupper($t['nom'] ?? '') . ' ' . ($t['prenom'] ?? ''));
    $est_pere = str_contains($lien, 'pere') || str_contains($lien, 'père');
    $est_mere = str_contains($lien, 'mere') || str_contains($lien, 'mère');
    if (!$pere && $est_pere) $pere = $nom_tuteur;
    elseif (!$mere && $est_mere) $mere = $nom_tuteur;
}

$pdf = new FPDF('P', 'mm', 'A4');
$pdf->SetMargins(15, 15, 15);
$pdf->SetAutoPageBreak(true, 15);
$pdf->AddPage();
$pw = $pdf->GetPageWidth();
$ph = $pdf->GetPageHeight();
$ml = 15; $mr = 15;
$uw = $pw - $ml - $mr;

pdf_filigrane($pdf, $etab, $pw, $ph);
// Ouvert depuis le scan du QR code UNIQUEMENT : tampon « AUTHENTIQUE » en fond,
// posé par pdf_filigrane() (voir pdf_tampon_actif(), header_pdf.php). Jamais
// sur un certificat imprimé depuis l'application.

// ── En-tête bilingue façon MINESEC (Ministère + Délégations) ────────
// Extrait juste le nom du lieu à partir des libellés complets stockés en
// base ("RÉGION DE L'ADAMAOUA" → "ADAMAOUA", "ADAMAWA REGION" → "ADAMAWA"),
// pour composer "DELEGATION REGIONALE DU {nom}" / "REGIONAL DELEGATION OF
// {nom}" comme sur le modèle de référence.
$region_fr_nom = trim((string) preg_replace('/^(RÉGION|REGION)\s+DE\s+(L\')?/iu', '', $etab['region_fr'] ?? "RÉGION DE L'ADAMAOUA"));
$dept_fr_nom   = trim((string) preg_replace('/^(DÉPARTEMENT|DEPARTEMENT)\s+DE\s+(LA\s+|DE\s+)?/iu', '', $etab['departement_fr'] ?? 'DÉPARTEMENT DE LA VINA'));
$region_en_nom = trim((string) preg_replace('/\s+REGION$/iu', '', $etab['region_en'] ?? 'ADAMAWA REGION'));
$division_en_nom = trim((string) preg_replace('/\s+DIVISION$/iu', '', $etab['division_en'] ?? 'VINA DIVISION'));

$col3 = $uw / 3;
$y0   = 15;

$pdf->SetFont('Arial', 'B', 7);
$pdf->SetXY($ml, $y0);
$pdf->MultiCell($col3, 3.1, u(
    "REPUBLIQUE DU CAMEROUN\nMINISTERE DES ENSEIGNEMENTS SECONDAIRES\n" .
    "DELEGATION REGIONALE DU " . mb_strtoupper($region_fr_nom) . "\n" .
    "DELEGATION DEPARTEMENTALE DE " . mb_strtoupper($dept_fr_nom)
), 0, 'C');
$y_fr_fin = $pdf->GetY();

$logo_path = !empty($etab['logo']) ? __DIR__ . '/../../assets/uploads/' . $etab['logo'] : '';
$logo_x = $ml + $col3 + ($col3 - 20) / 2;
if ($logo_path && is_file($logo_path)) {
    $pdf->Image($logo_path, $logo_x, $y0, 20);
} else {
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetXY($logo_x, $y0 + 2);
    $pdf->Cell(20, 16, u($etab['sigle'] ?? 'LTM'), 1, 0, 'C');
}

$pdf->SetXY($ml + 2 * $col3, $y0);
$pdf->SetFont('Arial', 'B', 7);
$pdf->MultiCell($col3, 3.1, u(
    "REPUBLIC OF CAMEROON\nMINISTRY OF SECONDARY EDUCATION\n" .
    "REGIONAL DELEGATION OF " . mb_strtoupper($region_en_nom) . "\n" .
    "DIVISIONAL DELEGATION OF " . mb_strtoupper($division_en_nom)
), 0, 'C');
$y_en_fin = $pdf->GetY();

$y_nom = max($y_fr_fin, $y_en_fin) + 1;
// Nom de l'établissement : 2 lignes max, police réduite si besoin — un nom
// très long débordait sur le logo et la colonne anglaise (pdf_texte_ajuste()).
pdf_texte_ajuste($pdf, $ml, $y_nom, $col3, mb_strtoupper($etab['nom_fr'] ?? APP_NOM), 9, 6, 2);
$pdf->SetFont('Arial', '', 6.5);
$pdf->SetX($ml);
$pdf->MultiCell($col3, 3, u(
    "B.P. " . ($etab['boite_postale'] ?? '32') . " " . ($etab['ville'] ?? 'Mbe') . "  Tel: " . ($etab['telephone'] ?? '') . "\n" .
    ($etab['email'] ?? '')
), 0, 'C');
$y_left_fin = $pdf->GetY();

pdf_texte_ajuste($pdf, $ml + 2 * $col3, $y_nom, $col3, mb_strtoupper($etab['nom_en'] ?? 'GTHS OF MBE'), 9, 6, 2);
$pdf->SetFont('Arial', '', 6.5);
$pdf->SetX($ml + 2 * $col3);
$pdf->MultiCell($col3, 3, u(
    "P.O. BOX. " . ($etab['boite_postale'] ?? '32') . " " . ($etab['ville'] ?? 'Mbe') . "  Phone: " . ($etab['telephone'] ?? '') . "\n" .
    ($etab['email'] ?? '')
), 0, 'C');
$y_right_fin = $pdf->GetY();

$pdf->SetY(max($y_left_fin, $y_right_fin, $y0 + 20) + 3);

// ── Année scolaire (haut gauche) ────────────────────────────────────
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetX($ml);
$pdf->Cell($uw / 2, 4, u('Annee scolaire: ' . $val_annee), 0, 1, 'L');
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
$pdf->Cell($titre_w, 7, u('CERTIFICAT DE SCOLARITE'), 0, 1, 'C');
$pdf->SetFont('Arial', '', 9);
$pdf->SetX($titre_x);
$pdf->Cell($titre_w, 5, 'SCHOOL CERTIFICATE', 0, 0, 'C');
$pdf->SetTextColor(0);
$pdf->Ln(14);

// ── Numéro de document (à compléter à la main, même convention que
// pdf_attestation.php/pdf_prise_service.php) ─────────────────────────
$pdf->Ln(3);
$pdf->SetFont('Arial', '', 9);
$pdf->SetX($ml);
$pdf->Cell($uw, 5, u('N° _____________/CS/' . ($etab['sigle'] ?? 'LTM') . '/' . date('y')), 0, 1, 'R');

// ── Corps (paragraphe légal bilingue complet) ────────────────────────
$sexe   = ($eleve['sexe'] ?? 'M') === 'F' ? ['Née', 'inscrite'] : ['Né', 'inscrit'];
$dnaiss = $eleve['date_naiss'] ? date('d/m/Y', strtotime($eleve['date_naiss'])) : '.....................';
$nom_complet = strtoupper($eleve['nom']) . ' ' . ($eleve['prenom'] ?? '');

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
$pdf->Cell($uw, 4.6, pdf_police_ajustee($pdf, u('PROVISEUR DU ' . strtoupper($etab['nom_fr'] ?? APP_NOM)), 'B', 11.5, $uw), 0, 1, 'C');
$pdf->SetFont('Arial', 'I', 9.5);
$pdf->SetX($ml);
$pdf->Cell($uw, 3.3, pdf_police_ajustee($pdf, u('PRINCIPAL OF THE ' . strtoupper($etab['nom_en'] ?? 'GTHS OF MBE')), 'I', 9.5, $uw), 0, 1, 'C');

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

$champ('Certifie que l\'élève', $nom_complet, 'Certifies that the student');
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
$pdf->Cell($ml + $uw - $pdf->GetX(), 4.6, u($eleve['lieu_naiss'] ?? '.....................'), 0, 1, 'L');
$pdf->SetFont('Arial', 'I', 8.5);
$pdf->SetX($ml);
$pdf->Cell($x_a - $ml, 3.3, 'Born on', 0, 0, 'L');
$pdf->SetX($x_a);
$pdf->Cell(10, 3.3, 'at', 0, 1, 'L');

$pdf->Ln(3.2);
$pdf->SetFont('Arial', '', 11);
$pdf->SetX($ml);
$pdf->MultiCell($uw, 4.7, u(
    'Est effectivement ' . $sexe[1] . '(e) dans mon établissement en classe de : ' . strtoupper($insc['classe'])
), 0, 'L');
$pdf->SetFont('Arial', 'I', 8.5);
$pdf->SetX($ml);
$pdf->Cell($uw, 3.3, 'Is effectively part of my school in class', 0, 1, 'L');

$pdf->Ln(3.2);
$champ('Pour le compte de l\'année scolaire :', $val_annee, 'On behalf of the school year');
$champ('Sous le matricule national :', id_affichage_eleve($eleve), 'Under the national matricule');

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
$pdf->Cell($uw * 0.45, 4.6, u(($etab['ville'] ?? 'Mbe') . ', le ' . date('d/m/Y') . '.'), 0, 1, 'C');
$pdf->SetFont('Arial', 'I', 9);
$pdf->SetX($ml + $uw * 0.55);
$pdf->Cell($uw * 0.45, 3.3, 'On', 0, 1, 'C');
$pdf->Ln(3.2);
$pdf->SetX($ml + $uw * 0.55);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell($uw * 0.45, 4.6, u(strtoupper($etab['chef_etablissement'] ?? 'LE PROVISEUR') . ','), 0, 1, 'C');
$pdf->SetFont('Arial', 'I', 9);
$pdf->SetX($ml + $uw * 0.55);
$pdf->Cell($uw * 0.45, 3.3, ($etab['chef_etablissement_en'] ?? 'The Principal'), 0, 0, 'C');

// Signature numérique (uniquement si demandée à l'impression — jamais
// automatique — et si l'admin en a configuré une dans les paramètres).
if (($_GET['signature'] ?? '0') === '1') {
    $sig_w = 26;
    $sx = $ml + $uw * 0.55 + ($uw * 0.45 - $sig_w) / 2;
    $sy = $pdf->GetY() + 5;
    pdf_signature_appliquer($pdf, 'certificat_scolarite', 'chef_etablissement', 0, 0, $pw, $ph, [
        'x_pct' => $sx / $pw * 100, 'y_pct' => $sy / $ph * 100, 'w_pct' => $sig_w / $pw * 100, 'h_pct' => null,
    ]);
}

// ── QR de vérification d'authenticité (propre à ce certificat, jamais à un
// autre document) — photo de l'élève incrustée au centre si disponible,
// même principe visuel que secondaire/pages/bulletins/pdf.php et le tableau d'honneur ;
// fonctions dupliquées ici par convention du projet (pas de dépendance
// croisée entre les différents types de documents).
function photo_eleve_cs(array $eleve): string {
    $photo_path = !empty($eleve['photo']) ? __DIR__ . '/../../assets/uploads/eleves/' . $eleve['photo'] : '';
    if ($photo_path && is_file($photo_path)) return $photo_path;
    $avatar = (strtoupper($eleve['sexe'] ?? '') === 'F') ? 'fille.png' : 'garcon.png';
    return __DIR__ . '/../../assets/img/avatars/' . $avatar;
}
function qr_incruster_photo_cs($qr_img, string $photo_path): void {
    if ($photo_path === '' || !is_file($photo_path)) return;
    $photo = @imagecreatefromstring(file_get_contents($photo_path));
    if (!$photo) return;
    $w = imagesx($qr_img); $h = imagesy($qr_img);
    $logo_size = (int)round($w * 0.22);
    $pad = (int)round($w * 0.012);
    $box = $logo_size + $pad * 2;
    $bx = (int)(($w - $box) / 2); $by = (int)(($h - $box) / 2);
    $blanc = imagecolorallocate($qr_img, 255, 255, 255);
    imagefilledrectangle($qr_img, $bx, $by, $bx + $box - 1, $by + $box - 1, $blanc);
    $pw2 = imagesx($photo); $ph2 = imagesy($photo);
    $cote = min($pw2, $ph2);
    $sx = (int)(($pw2 - $cote) / 2); $sy = (int)(($ph2 - $cote) / 2);
    imagecopyresampled($qr_img, $photo, $bx + $pad, $by + $pad, $sx, $sy, $logo_size, $logo_size, $cote, $cote);
    imagedestroy($photo);
}

// Désactive le saut de page automatique pour cette zone : les éléments sont
// positionnés à la main tout près du bas de page ($ph), et le déclencheur
// de saut de page automatique (marge de 15mm) les ferait sinon basculer sur
// une page suivante presque vide.
$pdf->SetAutoPageBreak(false);

$qr_size = 20;
$qr_x = $ml;
$qr_y = $ph - 15 - $qr_size;
$verif_url = scolarite_verif_url((int)$eleve['id'], id_affichage_eleve($eleve));
$qr_tmp = tempnam(sys_get_temp_dir(), 'abzqrcs_') . '.png';
try {
    $qr_gen = new QRCode($verif_url, ['s' => 'qr-h']);
    $qr_img = $qr_gen->render_image();
    qr_incruster_photo_cs($qr_img, photo_eleve_cs($eleve));
    imagepng($qr_img, $qr_tmp);
    imagedestroy($qr_img);
    $pdf->Image($qr_tmp, $qr_x, $qr_y, $qr_size, $qr_size, 'PNG');
} finally {
    if (is_file($qr_tmp)) unlink($qr_tmp);
}
$pdf->SetFont('Arial', 'I', 6.5);
$pdf->SetXY($qr_x, $qr_y + $qr_size + 1);
$pdf->Cell($qr_size, 3, u('Scanner pour vérifier'), 0, 0, 'C');

// ── Copyright (même texte que le tableau d'honneur) ─────────────────
$pdf->SetFont('Arial', 'I', 6.5);
$pdf->SetTextColor(120);
$pdf->SetXY($ml, $ph - 12);
$pdf->Cell($uw, 4, u('Copyright © SIGES ABZ , E-mail: abdoulazizyahya@gmail.com'), 0, 0, 'C');
$pdf->SetTextColor(0);

$pdf->Output($dl ? 'D' : 'I', 'certificat_scolarite_' . ($eleve['matricule'] ?? $id_eleve) . '.pdf');
