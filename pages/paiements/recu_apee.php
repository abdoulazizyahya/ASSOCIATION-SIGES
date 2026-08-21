<?php
// pages/paiements/recu_apee.php — Reçu A.P.E.E (visuel repris à l'identique
// du document MANWI annexé) : toujours basé sur le paiement APEE réel de
// l'élève pour l'année active (obligation_frais.code_fixe='APEE'), peu
// importe les cases cochées dans le tableau historique de index.php.
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'CENSEUR', 'INTENDANT']);
require_once __DIR__ . '/../../pdf/fpdf.php';
require_once __DIR__ . '/../../pdf/header_pdf.php';

$id_eleve = (int)($_GET['eleve'] ?? 0);
$id_annee = (int)($_GET['annee'] ?? 0);
$dl       = ($_GET['dl'] ?? '0') === '1';

$paiement = ($id_eleve && $id_annee) ? db_one(
    "SELECT p.*, e.nom, e.prenom, e.matricule, e.telephone, c.designation AS classe
     FROM paiement_frais p
     JOIN eleve e ON e.id = p.id_eleve
     JOIN classe c ON c.id = p.id_classe
     JOIN obligation_frais o ON o.id = p.id_obligation AND o.code_fixe = 'APEE'
     WHERE p.id_eleve = ? AND p.id_annee = ?
     ORDER BY p.id DESC LIMIT 1", [$id_eleve, $id_annee]
) : null;

if (!$paiement) {
    die('<div style="font-family:sans-serif;padding:2rem;color:#b91c1c">
         Aucun versement APEE enregistré pour cet élève cette année — impossible de générer le reçu APEE.</div>');
}

$etab         = get_etablissement();
$annee        = db_one("SELECT libelle FROM annee_scolaire WHERE id = ?", [$id_annee]);
$annee_libelle = $annee['libelle'] ?? '';

$avec_sig_president = ($_GET['sig_president_apee'] ?? '0') === '1';
$avec_sig_tresorier = ($_GET['sig_tresorier_apee'] ?? '0') === '1';

$reglage = get_reglage_paiement($id_annee);
$couleurs_fond = [hex_vers_rgb($reglage['couleur_fond_1']), hex_vers_rgb($reglage['couleur_fond_2']), hex_vers_rgb($reglage['couleur_fond_3'])];

// Mode "cadre seul" pour la fenêtre de réglage de position — voir la note
// équivalente dans pages/paiements/recu.php.
$apercu_cadre_seul = ($_GET['apercu_frame'] ?? '') === 'recu_apee';
$bandeau_h_ref = (297 - 16) / 3;
$w0_ref        = 210 - 16;
$echelle       = 0.66;
$ml            = 8;

// Enveloppé dans un try/catch — voir fonctions.php::pdf_erreur_generation().
try {
if ($apercu_cadre_seul) {
    // Orientation 'L' : voir la note équivalente dans recu.php (le cadre est
    // plus large que haut — 'P' inverserait largeur/hauteur).
    $pdf = new FPDF('L', 'mm', [$w0_ref, $bandeau_h_ref]);
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage();
    $frames = [[0, 0]];
} else {
    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetMargins(8, 8, 8);
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage();
    $pw = $pdf->GetPageWidth();
    $ph = $pdf->GetPageHeight();
    $bandeau_h_ref = ($ph - 16) / 3;
    $frames = [[$ml, 8], [$ml, 8 + $bandeau_h_ref], [$ml, 8 + 2 * $bandeau_h_ref]];
}

foreach ($frames as [$x0, $y0]) {
    $w0 = $w0_ref; $h0 = $bandeau_h_ref;
    $mi = 2; $xi = $x0 + $mi; $wi = $w0 - 2 * $mi;

    pdf_fond_degrade($pdf, $x0, $y0, $w0, $h0, $couleurs_fond);
    // Filigrane centré dans CE cadre (pas sur la page entière — voir la note
    // équivalente dans pdf/recu_paiement_render.php).
    pdf_filigrane($pdf, $etab, $w0, $h0, $w0 * 0.55, $x0 + $w0 * 0.225, $y0 + ($h0 - $w0 * 0.55) / 2);
    $pdf->SetDrawColor(40);
    $pdf->SetLineWidth(0.3);
    $pdf->RoundedRect($x0, $y0, $w0, $h0, 3, 'D');
    $pdf->SetDrawColor(0);
    $pdf->SetLineWidth(0.2);

    pdf_entete($pdf, $etab, $wi + 2 * $xi, $xi, $y0 + 1, $echelle);
    $cy = $pdf->GetY() + 1;

    // Titre chevron "REÇU A.P.E.E" (gauche) + case "B.P.F. CFA" (droite)
    pdf_titre_chevron($pdf, 'REÇU A.P.E.E', $xi, $cy, $wi * 0.42, 7);
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->SetXY($xi + $wi * 0.55, $cy);
    $pdf->Cell($wi * 0.14, 4, pdf_u('B.P.F. CFA'), 0, 0, 'L');
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Rect($xi + $wi * 0.70, $cy, $wi * 0.30, 4);
    $pdf->SetXY($xi + $wi * 0.70, $cy);
    $pdf->Cell($wi * 0.30, 4, number_format((float)$paiement['montant'], 0, ',', ' '), 0, 0, 'C');
    $pdf->SetFont('Arial', '', 7);
    $pdf->SetXY($xi + $wi * 0.55, $cy + 4.3);
    $pdf->Cell($wi * 0.45, 3.2, pdf_u('Année scolaire: ' . $annee_libelle), 0, 1, 'L');
    $pdf->SetFont('Arial', 'I', 5.2);
    $pdf->SetX($xi + $wi * 0.55);
    $pdf->Cell($wi * 0.45, 2.2, 'School Year', 0, 1, 'L');
    $cy += 10;

    pdf_pilule($pdf, "ASSOCIATION DES PARENTS D'ELEVES ET ENSEIGNANTS", $xi, $cy, $wi, 5.5);
    $cy += 7.5;

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
    $nom_complet = strtoupper($paiement['nom']) . ' ' . ($paiement['prenom'] ?? '');
    $ligne($cy, "Nom et Prénoms l'éleve : " . $nom_complet, "Student's Names");
    $cy += 6.6;
    $somme_lettres = nombre_en_lettres_fcfa((float)$paiement['montant']);
    $ligne($cy,
        'Classe : ' . $paiement['classe'] . '          La somme de : ' . number_format((float)$paiement['montant'], 0, ',', ' ') . ' Fcfa ( ' . $somme_lettres . ' Francs CFA )',
        'Class'
    );
    $cy += 6.6;
    $ligne($cy, 'Nom et Prénoms du Parent : ', "Parent's Name");
    $cy += 6.6;
    $pdf->SetFont('Arial', '', 8);
    $pdf->SetXY($xi, $cy);
    $pdf->Cell($wi * 0.62, 3.4, pdf_u('Adresse du Parent ou Contact Téléphonique : '), 0, 0);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell($wi * 0.38, 3.4, pdf_u(mb_strtoupper(($etab['lieu'] ?: $etab['ville']) ?? 'MBÉ', 'UTF-8') . ', le ' . date('d/m/Y')), 0, 1, 'R');
    $pdf->SetFont('Arial', 'I', 5.2);
    $pdf->SetTextColor(110);
    $pdf->SetX($xi);
    $pdf->Cell($wi * 0.62, 2.2, "Parent's address or phone number", 0, 0);
    $pdf->SetFont('Arial', 'I', 5.2);
    $pdf->Cell($wi * 0.38, 2.2, 'On', 0, 1, 'R');
    $pdf->SetTextColor(0);

    // Signatures — jamais appliquées automatiquement, seulement si demandé
    // explicitement à l'impression (voir layout/footer.php).
    $y_sig_ligne = $y0 + $h0 - 20;
    $pdf->SetFont('Arial', 'BI', 8);
    $pdf->SetXY($xi, $y_sig_ligne);
    $pdf->Cell($wi * 0.45, 5, pdf_u('LE PRESIDENT'), 0, 0, 'C');
    $pdf->Cell($wi * 0.1, 5, '', 0, 0);
    $pdf->Cell($wi * 0.45, 5, pdf_u("LE TRESORIER DE L'APEE"), 0, 1, 'C');

    if ($avec_sig_president) {
        pdf_signature_appliquer($pdf, 'recu_apee', 'president_apee', $x0, $y0, $w0, $h0, [
            'x_pct' => (($xi - $x0) + $wi * 0.12) / $w0 * 100, 'y_pct' => ($y_sig_ligne + 5 - $y0) / $h0 * 100,
            'w_pct' => $wi * 0.22 / $w0 * 100, 'h_pct' => null,
        ]);
    }
    if ($avec_sig_tresorier) {
        pdf_signature_appliquer($pdf, 'recu_apee', 'tresorier_apee', $x0, $y0, $w0, $h0, [
            'x_pct' => (($xi - $x0) + $wi * 0.60) / $w0 * 100, 'y_pct' => ($y_sig_ligne + 5 - $y0) / $h0 * 100,
            'w_pct' => $wi * 0.22 / $w0 * 100, 'h_pct' => null,
        ]);
    }
}

$pdf->Output($dl ? 'D' : 'I', 'recu_apee_' . $paiement['matricule'] . '.pdf');
} catch (Throwable $e) {
    pdf_erreur_generation($e);
}
