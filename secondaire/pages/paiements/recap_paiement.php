<?php
// secondaire/pages/paiements/recap_paiement.php — Récapitulatif de paiement (visuel
// repris à l'identique du document MANWI annexé) : 3 copies empilées
// séparées par des lignes en pointillés, regroupant tous les frais d'un même
// numero_recu (voir secondaire/pages/paiements/save.php). Remplace l'ancien mode
// `recu.php?mode=recap` (retiré).
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'CENSEUR', 'INTENDANT']);
require_once __DIR__ . '/../../pdf/fpdf.php';
require_once __DIR__ . '/../../pdf/header_pdf.php';

$dl          = ($_GET['dl'] ?? '0') === '1';
$numero_recu = trim((string)($_GET['numero_recu'] ?? ''));
$id_eleve    = (int)($_GET['eleve'] ?? 0);
$id_annee    = (int)($_GET['annee'] ?? 0);

$versements = ($numero_recu && $id_eleve && $id_annee) ? db_all(
    "SELECT p.*, o.libelle AS obligation_libelle, op.libelle AS operateur_libelle
     FROM paiement_frais p
     JOIN obligation_frais o ON o.id = p.id_obligation
     JOIN operateur_paiement op ON op.id = p.id_operateur
     WHERE p.numero_recu = ? AND p.id_eleve = ? AND p.id_annee = ?
     ORDER BY p.id", [$numero_recu, $id_eleve, $id_annee]
) : [];

if (!$versements) {
    die('<div style="font-family:sans-serif;padding:2rem;color:#b91c1c">Récapitulatif introuvable.</div>');
}

$eleve  = db_one("SELECT * FROM eleve WHERE id = ?", [$id_eleve]);
$classe = db_one("SELECT * FROM classe WHERE id = ?", [(int)$versements[0]['id_classe']]);
$annee  = db_one("SELECT libelle FROM annee_scolaire WHERE id = ?", [$id_annee]);
$etab   = get_etablissement();

$id_paiement_repere = (int) min(array_column($versements, 'id'));
require_once __DIR__ . '/../../pdf/verif_paiement_lib.php';
$qr_url = paiement_verif_url($id_paiement_repere, $numero_recu);
require_once __DIR__ . '/../../pdf/qrcode.php';
$qr_tmp = tempnam(sys_get_temp_dir(), 'abzqr_') . '.png';
$qr_gen = new QRCode($qr_url, ['s' => 'qr-m']);
$qr_img = $qr_gen->render_image();
imagepng($qr_img, $qr_tmp);
imagedestroy($qr_img);

$avec_sig_intendant = ($_GET['sig_intendant'] ?? '0') === '1';
$avec_sig_proviseur = ($_GET['sig_chef_etablissement'] ?? '0') === '1';

$reglage = get_reglage_paiement($id_annee);
$couleurs_fond = [hex_vers_rgb($reglage['couleur_fond_1']), hex_vers_rgb($reglage['couleur_fond_2']), hex_vers_rgb($reglage['couleur_fond_3'])];

// Mode "cadre seul" pour la fenêtre de réglage de position — voir la note
// équivalente dans secondaire/pages/paiements/recu.php (même principe : le canvas de
// l'éditeur doit correspondre EXACTEMENT au cadre d'UNE copie, pas à la
// page entière, sinon la position % enregistrée ne correspond à rien une
// fois réappliquée à une seule copie).
$apercu_cadre_seul = ($_GET['apercu_frame'] ?? '') === 'recap_paiement';
$bandeau_h_ref = (297 - 16) / 3;
$w0_ref        = 210 - 16;
$h0_ref        = $bandeau_h_ref - 2;

$echelle      = 0.66;
$sexe_libelle = ($eleve['sexe'] ?? 'M') === 'F' ? 'Féminin' : 'Masculin';
$date_naiss   = !empty($eleve['date_naiss']) ? date('Y-m-d', strtotime($eleve['date_naiss'])) : '';

if ($apercu_cadre_seul) {
    // Orientation 'L' : voir la note équivalente dans recu.php (le cadre est
    // plus large que haut — 'P' inverserait largeur/hauteur).
    $pdf = new FPDF('L', 'mm', [$w0_ref, $h0_ref]);
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage();
    $frames = [[0, 0]];
    $bandeau_h = $bandeau_h_ref;
} else {
    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetMargins(8, 8, 8);
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage();
    $pw = $pdf->GetPageWidth();
    $ph = $pdf->GetPageHeight();
    pdf_filigrane($pdf, $etab, $pw, $ph, 120);
    $bandeau_h = ($ph - 16) / 3;
    $frames = [[8, 8], [8, 8 + $bandeau_h], [8, 8 + 2 * $bandeau_h]];
}

foreach ($frames as $i => [$x0, $y0]) {
    $w0 = $w0_ref; $h0 = $h0_ref;
    $mi = 2; $xi = $x0 + $mi; $wi = $w0 - 2 * $mi;

    pdf_fond_degrade($pdf, $x0, $y0, $w0, $h0, $couleurs_fond);
    $pdf->SetDrawColor(40);
    $pdf->SetLineWidth(0.3);
    $pdf->RoundedRect($x0, $y0, $w0, $h0, 3, 'D');
    $pdf->SetDrawColor(0);
    $pdf->SetLineWidth(0.2);
    if (!$apercu_cadre_seul && $i < count($frames) - 1) {
        pdf_ligne_pointillee($pdf, $x0, $y0 + $h0 + 1, $x0 + $w0, $y0 + $h0 + 1, 1.5, 1);
    }

    pdf_entete($pdf, $etab, $wi + 2 * $xi, $xi, $y0 + 1, $echelle);
    $cy = $pdf->GetY() + 1;

    // Bandeau titre + case numéro
    $h_titre = 6;
    $w_pilule = $wi * 0.55;
    pdf_pilule($pdf, 'RECAPITULATIF DE PAIEMENT', $xi, $cy, $w_pilule, $h_titre);
    $x_num = $xi + $w_pilule + 3;
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->SetXY($x_num, $cy);
    $pdf->Cell(9, $h_titre, pdf_u('N° :'), 0, 0, 'L');
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Rect($x_num + 9, $cy + 0.8, $xi + $wi - ($x_num + 9), $h_titre - 1.6);
    $pdf->SetXY($x_num + 9, $cy + 0.8);
    $pdf->Cell($xi + $wi - ($x_num + 9), $h_titre - 1.6, pdf_u($numero_recu), 0, 0, 'C');
    $pdf->SetFont('Arial', '', 6);
    $pdf->SetXY($xi, $cy - 3.2);
    $pdf->Cell($w_pilule, 3, pdf_u('Année scolaire: ' . ($annee['libelle'] ?? '')), 0, 0, 'L');
    $cy += $h_titre + 2;

    // Identité
    $ligne = function (float $yy, string $fr, string $en) use ($pdf, $xi, $wi): void {
        $pdf->SetFont('Arial', '', 8);
        $pdf->SetXY($xi, $yy);
        $pdf->Cell($wi, 3.4, pdf_u($fr), 0, 1);
        $pdf->SetFont('Arial', 'I', 5.2);
        $pdf->SetTextColor(110);
        $pdf->SetX($xi);
        $pdf->Cell($wi, 2.2, pdf_u($en), 0, 1);
        $pdf->SetTextColor(0);
    };
    $ligne($cy, 'Nom et Prénoms : ' . strtoupper($eleve['nom']) . ' ' . ($eleve['prenom'] ?? ''), 'Surname and given Names');
    $cy += 6.6;
    $ligne($cy,
        'Date et lieu de naissance : ' . $date_naiss . ' A ' . strtoupper((string)($eleve['lieu_naiss'] ?? '')) . '          Sexe : ' . $sexe_libelle,
        'Date and place of birth — Sex'
    );
    $cy += 6.6;
    $ligne($cy, 'Classe : ' . ($classe['designation'] ?? ''), 'Class');
    $cy += 7;

    // Tableau MOTIF (Frais / Montant / Operateur / Reference)
    $w_col = [$wi * 0.38, $wi * 0.22, $wi * 0.20, $wi * 0.20];
    $entetes = ['Frais', 'Montant', 'Operateur', 'Reference'];
    $h_ligne = 4;
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->SetFillColor(120, 180, 235);
    $pdf->SetXY($xi, $cy);
    foreach ($entetes as $k => $lib) {
        $pdf->Cell($w_col[$k], $h_ligne, pdf_u($lib), 1, 0, 'C', true);
    }
    $pdf->Ln();
    $pdf->SetFont('Arial', '', 7);
    $total = 0.0;
    foreach ($versements as $v) {
        $cy += $h_ligne;
        $pdf->SetXY($xi, $cy);
        $pdf->Cell($w_col[0], $h_ligne, pdf_u($v['obligation_libelle']), 1, 0, 'L');
        $pdf->Cell($w_col[1], $h_ligne, number_format((float)$v['montant'], 0, ',', ' '), 1, 0, 'R');
        $pdf->Cell($w_col[2], $h_ligne, pdf_u($v['operateur_libelle']), 1, 0, 'C');
        $pdf->Cell($w_col[3], $h_ligne, pdf_u($v['ref_paiement'] ?? ''), 1, 1, 'C');
        $total += (float)$v['montant'];
    }
    $cy += $h_ligne;
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->SetFillColor(120, 180, 235);
    $pdf->SetXY($xi, $cy);
    $pdf->Cell($w_col[0], $h_ligne + 0.5, pdf_u('TOTAL'), 1, 0, 'L', true);
    $pdf->Cell($w_col[1], $h_ligne + 0.5, number_format($total, 0, ',', ' '), 1, 0, 'R', true);
    $pdf->Cell($w_col[2] + $w_col[3], $h_ligne + 0.5, '', 1, 1, 'C', true);

    // Signatures + QR/date + copyright vertical
    $y_sig_ligne = $y0 + $h0 - 18;
    $pdf->SetFont('Arial', 'BI', 8);
    $pdf->SetXY($xi, $y_sig_ligne);
    $pdf->Cell($wi * 0.35, 5, pdf_u("L'INTENDANT"), 0, 0, 'C');
    $pdf->Cell($wi * 0.30, 5, '', 0, 0);
    $pdf->Cell($wi * 0.35, 5, pdf_u('LE PROVISEUR'), 0, 1, 'C');

    if ($avec_sig_intendant) {
        pdf_signature_appliquer($pdf, 'recap_paiement', 'intendant', $x0, $y0, $w0, $h0, [
            'x_pct' => (($xi - $x0) + $wi * 0.08) / $w0 * 100, 'y_pct' => ($y_sig_ligne + 5 - $y0) / $h0 * 100,
            'w_pct' => $wi * 0.20 / $w0 * 100, 'h_pct' => null,
        ]);
    }
    if ($avec_sig_proviseur) {
        pdf_signature_appliquer($pdf, 'recap_paiement', 'chef_etablissement', $x0, $y0, $w0, $h0, [
            'x_pct' => (($xi - $x0) + $wi * 0.72) / $w0 * 100, 'y_pct' => ($y_sig_ligne + 5 - $y0) / $h0 * 100,
            'w_pct' => $wi * 0.20 / $w0 * 100, 'h_pct' => null,
        ]);
    }

    $qr_taille = 13;
    $qr_x = $x0 + $w0 / 2 - $qr_taille / 2;
    $qr_y = $y0 + $h0 - $mi - $qr_taille - 3.5;
    $pdf->Image($qr_tmp, $qr_x, $qr_y, $qr_taille, $qr_taille, 'PNG');
    $pdf->SetFont('Arial', '', 6.5);
    $pdf->SetXY($x0 + $w0 / 2 - 25, $qr_y + $qr_taille + 0.3);
    $pdf->Cell(50, 3, pdf_u(mb_strtoupper($etab['ville'] ?? 'MBÉ', 'UTF-8') . ', le ' . date('d/m/Y')), 0, 1, 'C');
    $pdf->SetFont('Arial', 'I', 5.5);
    $pdf->SetX($x0 + $w0 / 2 - 25);
    $pdf->Cell(50, 2.4, 'On', 0, 0, 'C');

    $pdf->SetFont('Arial', '', 5.5);
    $pdf->SetTextColor(90);
    $pdf->TextWithDirection($x0 + $w0 - 1.5, $y0 + $h0 - 4, pdf_u(
        'Copyright © SIGES ABZ Tel: (+237) 661 00 06 57 / 699 75 86 12 / 675 74 40 62, E-mail: abdoulazizyahya@gmail.com'
    ), 'U');
    $pdf->SetTextColor(0);
}

unlink($qr_tmp);
$pdf->Output($dl ? 'D' : 'I', 'recapitulatif_' . str_replace('/', '-', $numero_recu) . '.pdf');
