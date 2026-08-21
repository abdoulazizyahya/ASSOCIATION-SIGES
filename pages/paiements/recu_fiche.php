<?php
// pages/paiements/recu_fiche.php — "Reçu + fiche de préinscription" : UNE
// page A4 combinant un reçu de paiement groupé (haut, même contenu que
// recu.php, via pdf/recu_paiement_render.php) et une fiche de préinscription
// (bas, motif INSCRIPTION + éventuel FRAIS OPERATEUR), séparés par une ligne
// en pointillés — visuel repris à l'identique du document MANWI annexé.
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'CENSEUR', 'INTENDANT']);
require_once __DIR__ . '/../../pdf/fpdf.php';
require_once __DIR__ . '/../../pdf/header_pdf.php';
require_once __DIR__ . '/../../pdf/recu_paiement_render.php';

// Dessine le bloc "FICHE DE PREINSCRIPTION" dans le cadre [$xi,$y0,$wi,$h0] —
// extrait en fonction pour être appelé soit à sa vraie position (bas de
// page, sous le reçu), soit seul sur une page à sa taille exacte (mode
// "cadre seul" de la fenêtre de réglage de position, voir plus bas).
function recu_fiche_dessiner_bloc(
    FPDF $pdf, array $etab, array $eleve, array $classe, string $numero_recu,
    float $montant_inscription, bool $avec_frais_operateur, float $montant_frais_op, float $montant_total_fiche,
    bool $avec_sig_intendant, bool $avec_sig_proviseur,
    float $xi, float $y0, float $wi, float $h0,
    ?array $couleurs_fond = null
): void {
    pdf_fond_degrade($pdf, $xi, $y0, $wi, $h0, $couleurs_fond);
    // Filigrane centré dans ce cadre (pas sur la page entière — voir la note
    // équivalente dans pdf/recu_paiement_render.php).
    pdf_filigrane($pdf, $etab, $wi, $h0, $wi * 0.5, $xi + $wi * 0.25, $y0 + ($h0 - $wi * 0.5) / 2);
    $pdf->SetDrawColor(40);
    $pdf->SetLineWidth(0.3);
    $pdf->RoundedRect($xi, $y0, $wi, $h0, 3, 'D');
    $pdf->SetDrawColor(0);
    $pdf->SetLineWidth(0.2);
    pdf_entete($pdf, $etab, $wi + 16, $xi, $y0 + 2);
    $cy = $pdf->GetY() + 2;

    pdf_pilule($pdf, 'FICHE DE PREINSCRIPTION', $xi, $cy, $wi * 0.6, 7);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetXY($xi + $wi * 0.6 + 3, $cy);
    $pdf->Cell(10, 7, pdf_u('N°'), 0, 0, 'L');
    $pdf->Rect($xi + $wi * 0.6 + 14, $cy + 1, $wi * 0.4 - 14, 5);
    $pdf->SetXY($xi + $wi * 0.6 + 14, $cy + 1);
    $pdf->Cell($wi * 0.4 - 14, 5, pdf_u($numero_recu), 0, 0, 'C');
    $cy += 10;

    $ligne_fiche = function (float $yy, string $fr, string $en) use ($pdf, $xi, $wi): void {
        $pdf->SetFont('Arial', '', 8.5);
        $pdf->SetXY($xi, $yy);
        $pdf->Cell($wi, 4, pdf_u($fr), 0, 1);
        $pdf->SetFont('Arial', 'I', 5.5);
        $pdf->SetTextColor(110);
        $pdf->SetX($xi);
        $pdf->Cell($wi, 2.6, pdf_u($en), 0, 1);
        $pdf->SetTextColor(0);
    };
    $sexe_libelle = ($eleve['sexe'] ?? 'M') === 'F' ? 'Féminin' : 'Masculin';
    $date_naiss   = !empty($eleve['date_naiss']) ? date('Y-m-d', strtotime($eleve['date_naiss'])) : '';

    $ligne_fiche($cy, "NOM DE L'ETABLISSEMENT : " . strtoupper($etab['nom_fr'] ?? ''), 'Name of the School');
    $cy += 7.2;
    $ligne_fiche($cy, 'MATRICULE : ' . ($eleve['matricule'] ?? ''), 'Registration No');
    $cy += 7.2;
    $ligne_fiche($cy, 'NOM ET PRENOMS DE L\'ELEVE : ' . strtoupper($eleve['nom']) . ' ' . ($eleve['prenom'] ?? ''), "Student's Names");
    $cy += 7.2;
    $ligne_fiche($cy, 'DATE ET LIEU DE NAISSANCE : ' . $date_naiss . ' A ' . strtoupper((string)($eleve['lieu_naiss'] ?? '')), 'Date and place of birth');
    $cy += 7.2;
    $ligne_fiche($cy, 'SEXE : ' . $sexe_libelle . '          CLASSE : ' . ($classe['designation'] ?? ''), 'Sex');
    $cy += 7.2;

    $pdf->SetFont('Arial', '', 8.5);
    $pdf->SetXY($xi, $cy);
    $pdf->Cell($wi * 0.35, 4, pdf_u('MOTIF DU VERSEMENT : '), 0, 1);
    // Case à cocher dessinée à la main (police Arial standard de FPDF n'a pas de
    // glyphe ☑ correctement rendu en Windows-1252) — un petit carré + "X".
    $case = function (float $x, float $y) use ($pdf): void {
        $pdf->Rect($x, $y, 3.2, 3.2);
        $pdf->SetFont('Arial', 'B', 7);
        $pdf->SetXY($x, $y - 0.3);
        $pdf->Cell(3.2, 3.2, 'X', 0, 0, 'C');
    };
    $case($xi + $wi * 0.35, $cy + 0.2);
    $pdf->SetFont('Arial', '', 8.5);
    $pdf->SetXY($xi + $wi * 0.35 + 5, $cy);
    if ($montant_inscription > 0) {
        $pdf->Cell($wi * 0.6, 4, pdf_u('INSCRIPTION : ' . number_format($montant_inscription, 0, ',', ' ') . ' F cfa'), 0, 1);
    } else {
        $pdf->Cell($wi * 0.6, 4, pdf_u('INSCRIPTION : —'), 0, 1);
    }
    $pdf->SetFont('Arial', 'I', 5.5);
    $pdf->SetTextColor(110);
    $pdf->SetXY($xi, $cy + 4);
    $pdf->Cell($wi * 0.35, 2.6, pdf_u('Reason of payment'), 0, 0);
    $pdf->SetTextColor(0);
    $cy += 8;

    if ($avec_frais_operateur) {
        $case($xi + $wi * 0.35, $cy + 0.2);
        $pdf->SetFont('Arial', '', 8.5);
        $pdf->SetXY($xi + $wi * 0.35 + 5, $cy);
        $pdf->Cell($wi * 0.6, 4, pdf_u('FRAIS OPERATEUR : ' . number_format($montant_frais_op, 0, ',', ' ') . ' F cfa'), 0, 1);
        $cy += 8;
    }
    $cy += 2;

    $somme_lettres = nombre_en_lettres_fcfa($montant_total_fiche);
    $pdf->SetFont('Arial', 'B', 8.5);
    $pdf->SetXY($xi, $cy);
    $pdf->Cell($wi, 4, pdf_u(
        'MONTANT TOTAL A PAYER : ' . number_format($montant_total_fiche, 0, ',', ' ') . ' F cfa ( ' . $somme_lettres . ' Francs CFA)'
    ), 0, 1);
    $pdf->SetFont('Arial', 'I', 5.5);
    $pdf->SetTextColor(110);
    $pdf->SetX($xi);
    $pdf->Cell($wi, 2.6, pdf_u('Total amount to paid'), 0, 1);
    $pdf->SetTextColor(0);
    $cy += 5;

    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetXY($xi, $cy);
    $pdf->Cell($wi, 4, pdf_u(mb_strtoupper(($etab['lieu'] ?: $etab['ville']) ?? 'MBÉ', 'UTF-8') . ', le ' . date('d/m/Y')), 0, 1, 'R');
    $pdf->SetFont('Arial', 'I', 5.5);
    $pdf->SetX($xi);
    $pdf->Cell($wi, 2.6, 'On', 0, 1, 'R');
    $cy += 8;

    $y_sig_fiche = $cy + 4;
    $pdf->SetFont('Arial', 'BI', 8.5);
    $pdf->SetXY($xi, $y_sig_fiche);
    $pdf->Cell($wi * 0.4, 5, pdf_u("L'INTENDANT"), 0, 0, 'C');
    $pdf->Cell($wi * 0.2, 5, '', 0, 0);
    $pdf->Cell($wi * 0.4, 5, pdf_u('LE PROVISEUR'), 0, 1, 'C');

    if ($avec_sig_intendant) {
        pdf_signature_appliquer($pdf, 'recu_fiche_bas', 'intendant', $xi, $y0, $wi, $h0, [
            'x_pct' => 12, 'y_pct' => ($y_sig_fiche + 5 - $y0) / $h0 * 100, 'w_pct' => 20, 'h_pct' => null,
        ]);
    }
    if ($avec_sig_proviseur) {
        pdf_signature_appliquer($pdf, 'recu_fiche_bas', 'chef_etablissement', $xi, $y0, $wi, $h0, [
            'x_pct' => 68, 'y_pct' => ($y_sig_fiche + 5 - $y0) / $h0 * 100, 'w_pct' => 20, 'h_pct' => null,
        ]);
    }

    // Texte standard unique sur tous les PDF du projet (voir pdf_copyright(),
    // pdf/header_pdf.php), coordonnées/téléphone retirés.
    $pdf->SetFont('Arial', '', 5.5);
    $pdf->SetTextColor(90);
    $pdf->TextWithDirection($xi + $wi - 1.5, $y0 + $h0 - 4, pdf_u('Copyright © SIGES-V2 ABZ'), 'U');
    $pdf->SetTextColor(0);
}

$dl          = ($_GET['dl'] ?? '0') === '1';
$numero_recu = trim((string)($_GET['numero_recu'] ?? ''));
$id_eleve    = (int)($_GET['eleve'] ?? 0);
$id_annee    = (int)($_GET['annee'] ?? 0);

$versements = ($numero_recu && $id_eleve && $id_annee) ? db_all(
    "SELECT p.*, o.libelle AS obligation_libelle
     FROM paiement_frais p
     JOIN obligation_frais o ON o.id = p.id_obligation
     WHERE p.numero_recu = ? AND p.id_eleve = ? AND p.id_annee = ?
     ORDER BY p.id", [$numero_recu, $id_eleve, $id_annee]
) : [];

if (!$versements) {
    die('<div style="font-family:sans-serif;padding:2rem;color:#b91c1c">Reçu introuvable.</div>');
}

$eleve  = db_one("SELECT * FROM eleve WHERE id = ?", [$id_eleve]);
$classe = db_one("SELECT * FROM classe WHERE id = ?", [(int)$versements[0]['id_classe']]);
$annee  = db_one("SELECT libelle FROM annee_scolaire WHERE id = ?", [$id_annee]);
$etab   = get_etablissement();

// Paiement de l'obligation INSCRIPTION de l'élève pour l'année (peut être
// dans un numero_recu différent de celui affiché en haut — la fiche de
// préinscription est un document distinct, toujours basé sur l'inscription
// réelle de l'élève, pas sur la sélection courante du tableau historique).
$paiement_inscription = db_one(
    "SELECT p.* FROM paiement_frais p
     JOIN obligation_frais o ON o.id = p.id_obligation AND o.code_fixe = 'INSCRIPTION'
     WHERE p.id_eleve = ? AND p.id_annee = ? ORDER BY p.id DESC LIMIT 1",
    [$id_eleve, $id_annee]
);
$montant_inscription   = $paiement_inscription ? (float)$paiement_inscription['montant'] : 0.0;
$avec_frais_operateur  = $paiement_inscription && $paiement_inscription['id_operateur'] !== 'CASH';
$montant_frais_op      = $avec_frais_operateur ? get_reglage_paiement($id_annee)['montant_frais_operateur'] : 0.0;
$montant_total_fiche   = $montant_inscription + $montant_frais_op;

$avec_sig_intendant = ($_GET['sig_intendant'] ?? '0') === '1';
$avec_sig_proviseur = ($_GET['sig_chef_etablissement'] ?? '0') === '1';

$reglage = get_reglage_paiement($id_annee);
$couleurs_fond = [hex_vers_rgb($reglage['couleur_fond_1']), hex_vers_rgb($reglage['couleur_fond_2']), hex_vers_rgb($reglage['couleur_fond_3'])];

// Mode "cadre seul" pour la fenêtre de réglage de position — voir la note
// équivalente dans pages/paiements/recu.php. Ce document a DEUX cadres
// distincts (reçu en haut, fiche en bas), chacun avec sa propre position
// mémorisée par signataire (recu_fiche_haut / recu_fiche_bas) : le paramètre
// précise lequel des deux prévisualiser.
$apercu_frame = $_GET['apercu_frame'] ?? '';

// Dimensions de référence identiques au mode normal (A4, marges 8mm).
$pw_ref       = 210; $ph_ref = 297;
$h_recu_ref   = ($ph_ref - 24) * 0.52;
$y0_ref       = 8 + $h_recu_ref + 4 + 4;
$h0_fiche_ref = $ph_ref - $y0_ref - 6;

// Enveloppé dans un try/catch — voir fonctions.php::pdf_erreur_generation().
try {
if ($apercu_frame === 'recu_fiche_haut') {
    // Orientation 'L' : voir la note équivalente dans recu.php (le cadre est
    // plus large que haut — 'P' inverserait largeur/hauteur).
    $pdf = new FPDF('L', 'mm', [$pw_ref - 16, $h_recu_ref]);
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage();
    recu_paiement_dessiner_copie(
        $pdf, $etab, $eleve, $classe, $versements, $numero_recu, $annee['libelle'] ?? '',
        0, 0, $pw_ref - 16, $h_recu_ref,
        'recu_fiche_haut', false, null, false, false, 0.85, $couleurs_fond
    );
    $pdf->Output('I', 'apercu.pdf');
    exit;
}

if ($apercu_frame === 'recu_fiche_bas') {
    // Orientation 'L' : voir la note équivalente dans recu.php (le cadre est
    // plus large que haut — 'P' inverserait largeur/hauteur).
    $pdf = new FPDF('L', 'mm', [$pw_ref - 16, $h0_fiche_ref]);
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage();
    recu_fiche_dessiner_bloc(
        $pdf, $etab, $eleve, $classe, $numero_recu,
        $montant_inscription, $avec_frais_operateur, $montant_frais_op, $montant_total_fiche,
        false, false,
        0, 0, $pw_ref - 16, $h0_fiche_ref,
        $couleurs_fond
    );
    $pdf->Output('I', 'apercu.pdf');
    exit;
}

$id_paiement_repere = (int) min(array_column($versements, 'id'));
require_once __DIR__ . '/../../pdf/verif_paiement_lib.php';
$qr_url = paiement_verif_url($id_paiement_repere, $numero_recu);
require_once __DIR__ . '/../../pdf/qrcode.php';
$qr_tmp = tempnam(sys_get_temp_dir(), 'abzqr_') . '.png';
$qr_gen = new QRCode($qr_url, ['s' => 'qr-m']);
$qr_img = $qr_gen->render_image();
imagepng($qr_img, $qr_tmp);
imagedestroy($qr_img);

$pdf = new FPDF('P', 'mm', 'A4');
$pdf->SetMargins(8, 8, 8);
$pdf->SetAutoPageBreak(false);
$pdf->AddPage();
$pw = $pdf->GetPageWidth();
$ph = $pdf->GetPageHeight();

// Filigrane dessiné par bloc (reçu en haut via recu_paiement_dessiner_copie(),
// fiche en bas via recu_fiche_dessiner_bloc() ci-dessus) — pas un filigrane
// unique pour la page entière, qui ne recouvrirait pas les deux zones de
// façon équilibrée.
// ── Haut de page : reçu de paiement groupé (via le rendu partagé) ────────
$h_recu = ($ph - 24) * 0.52;
recu_paiement_dessiner_copie(
    $pdf, $etab, $eleve, $classe, $versements, $numero_recu, $annee['libelle'] ?? '',
    8, 8, $pw - 16, $h_recu,
    'recu_fiche_haut', true, $qr_tmp,
    $avec_sig_intendant, $avec_sig_proviseur, 0.85, $couleurs_fond
);

// ── Séparateur pointillé ──────────────────────────────────────────────
$y_sep = 8 + $h_recu + 4;
pdf_ligne_pointillee($pdf, 8, $y_sep, $pw - 8, $y_sep, 2, 1.5);

// ── Bas de page : fiche de préinscription ────────────────────────────
$y0 = $y_sep + 4;
$h0_fiche = $ph - $y0 - 6;
recu_fiche_dessiner_bloc(
    $pdf, $etab, $eleve, $classe, $numero_recu,
    $montant_inscription, $avec_frais_operateur, $montant_frais_op, $montant_total_fiche,
    $avec_sig_intendant, $avec_sig_proviseur,
    8, $y0, $pw - 16, $h0_fiche,
    $couleurs_fond
);

unlink($qr_tmp);
$pdf->Output($dl ? 'D' : 'I', 'recu_fiche_' . str_replace('/', '-', $numero_recu) . '.pdf');
} catch (Throwable $e) {
    pdf_erreur_generation($e);
}
