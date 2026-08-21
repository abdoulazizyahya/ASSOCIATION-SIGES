<?php
// pdf/recu_paiement_render.php — dessin d'UNE copie du "REÇU DE PAIEMENT DES
// FRAIS" (visuel repris des documents MANWI annexés) dans un cadre donné de
// la page. Fichier séparé (comme pdf/verif_paiement_lib.php) car réutilisé
// par DEUX générateurs PDF différents : pages/paiements/recu.php (3 copies
// empilées en pleine page) et pages/paiements/recu_fiche.php (1 copie en
// demi-page, suivie d'une fiche de préinscription) — pour ne jamais risquer
// de désynchroniser les deux usages.
require_once __DIR__ . '/header_pdf.php';

// $eleve doit contenir : nom, prenom, matricule, date_naiss, lieu_naiss,
// sexe, telephone. $classe : designation. $versements : lignes de
// paiement_frais du groupe (numero_recu), avec obligation_libelle.
// $x0/$y0/$w0/$h0 : cadre COMPLET réservé à cette copie (immuable — sert de
// référence % pour les signatures, même principe que l'ancien recu.php).
// $avec_sig_intendant/$avec_sig_proviseur : la signature n'est JAMAIS
// appliquée automatiquement même si elle est configurée en base — c'est à
// l'appelant de lire le paramètre GET correspondant (&sig_intendant=1 etc.,
// voir layout/footer.php) et de ne passer true que si l'utilisateur l'a
// explicitement demandé à l'impression.
// $echelle : réduit l'en-tête et les interlignes (voir pdf_entete()) —
// nécessaire quand $h0 est petit (3 copies empilées en pleine page A4,
// pages/paiements/recu.php) ; recu_fiche.php (1 copie en demi-page) peut
// utiliser une échelle plus grande, il a plus de place.
function recu_paiement_dessiner_copie(
    FPDF $pdf, array $etab, array $eleve, array $classe, array $versements, string $numero_recu, string $annee_libelle,
    float $x0, float $y0, float $w0, float $h0,
    string $type_document, bool $avec_qr, ?string $qr_tmp,
    bool $avec_sig_intendant = false, bool $avec_sig_proviseur = false, float $echelle = 0.66,
    ?array $couleurs_fond = null
): void {
    $mi = 2; // marge interne au cadre
    $xi = $x0 + $mi;
    $wi = $w0 - 2 * $mi;
    $lh = 5.4 * $echelle + 1.6; // hauteur de ligne "identité" (dégressive avec l'échelle, jamais illisible)

    pdf_fond_degrade($pdf, $x0, $y0, $w0, $h0, $couleurs_fond);
    // Filigrane du logo centré DANS ce cadre précis (pas un seul filigrane
    // pour la page entière) — sur un document à 3 copies empilées, un
    // filigrane unique centré sur la page ne recouvre visiblement que la
    // copie du milieu ; ici chaque copie a le sien.
    pdf_filigrane($pdf, $etab, $w0, $h0, $w0 * 0.55, $x0 + $w0 * 0.225, $y0 + ($h0 - $w0 * 0.55) / 2);
    pdf_rect_arrondi_ou_natif($pdf, $x0, $y0, $w0, $h0, 3);

    // En-tête bilingue standard (même logo/coordonnées que le reste de
    // l'appli) — page_w/marge choisis pour que les 3 colonnes tiennent
    // exactement dans [xi, xi+wi] (voir pdf_entete() dans header_pdf.php).
    pdf_entete($pdf, $etab, $wi + 2 * $xi, $xi, $y0 + 1, $echelle);
    $cy = $pdf->GetY() + 0.5;

    // Bandeau titre + case numéro
    $h_titre = 5 + 2 * $echelle;
    $w_pilule = $wi * 0.62;
    pdf_pilule($pdf, 'REÇU DE PAIEMENT DES FRAIS', $xi, $cy, $w_pilule, $h_titre);
    $x_num = $xi + $w_pilule + 3;
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->SetXY($x_num, $cy);
    $pdf->Cell(10, $h_titre, pdf_u('N° :'), 0, 0, 'L');
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Rect($x_num + 10, $cy + 0.6, $xi + $wi - ($x_num + 10), $h_titre - 1.2);
    $pdf->SetXY($x_num + 10, $cy + 0.6);
    $pdf->Cell($xi + $wi - ($x_num + 10), $h_titre - 1.2, pdf_u($numero_recu), 0, 0, 'C');

    // ── Identité de l'élève (ligne FR + sous-ligne EN italique petite) ──
    $cy += $h_titre + 1.5;
    $sexe_libelle = ($eleve['sexe'] ?? 'M') === 'F' ? 'Féminin' : 'Masculin';
    $date_naiss   = !empty($eleve['date_naiss']) ? date('Y-m-d', strtotime($eleve['date_naiss'])) : '';

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
    $ligne($cy, 'Année scolaire: ' . $annee_libelle, 'School Year');
    $cy += $lh;
    $ligne($cy, 'Nom et Prénoms: ' . strtoupper($eleve['nom']) . ' ' . ($eleve['prenom'] ?? '') . '   —   Matricule : ' . ($eleve['matricule'] ?? ''), 'Surname and given Names — Registration No');
    $cy += $lh;
    $ligne($cy, 'Date et lieu de naissance: ' . $date_naiss . ' A ' . strtoupper((string)($eleve['lieu_naiss'] ?? '')), 'Date and place of birth');
    $cy += $lh;
    $ligne($cy, 'Classe: ' . ($classe['designation'] ?? '') . '   —   Sexe: ' . $sexe_libelle, 'Class — Sex');
    $cy += $lh;
    $ligne($cy, 'Contact téléphonique du Parent/Tuteur: ' . ($eleve['telephone'] ?? ''), "Parent/ Tutor phone number");
    $cy += $lh + 0.5;

    // ── Tableau MOTIF (Frais / Montant) ─────────────────────────────
    $h_ligne = 3.8 + $echelle;
    $w_frais = $wi * 0.72;
    $w_mont  = $wi - $w_frais;
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->SetFillColor(120, 180, 235);
    $pdf->SetXY($xi, $cy);
    $pdf->Cell($w_frais, $h_ligne, pdf_u('FRAIS'), 1, 0, 'C', true);
    $pdf->Cell($w_mont, $h_ligne, pdf_u('MONTANT'), 1, 1, 'C', true);
    $pdf->SetFont('Arial', '', 7.5);
    $total = 0.0;
    foreach ($versements as $v) {
        $cy += $h_ligne;
        $pdf->SetXY($xi, $cy);
        $pdf->Cell($w_frais, $h_ligne, pdf_u($v['obligation_libelle']), 1, 0, 'L');
        $pdf->Cell($w_mont, $h_ligne, number_format((float)$v['montant'], 0, ',', ' '), 1, 1, 'R');
        $total += (float)$v['montant'];
    }
    $cy += $h_ligne;
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->SetFillColor(120, 180, 235);
    $pdf->SetXY($xi, $cy);
    $pdf->Cell($w_frais, $h_ligne + 0.5, pdf_u('TOTAL'), 1, 0, 'L', true);
    $pdf->Cell($w_mont, $h_ligne + 0.5, number_format($total, 0, ',', ' '), 1, 1, 'R', true);
    $cy += $h_ligne + 2;

    // ── Signatures + QR/date + copyright vertical ───────────────────
    $y_signature_ligne = $cy + 1;
    $pdf->SetFont('Arial', 'BI', 7.5);
    $pdf->SetXY($xi, $y_signature_ligne);
    $pdf->Cell($wi * 0.4, 4.5, pdf_u("L'INTENDANT"), 0, 0, 'C');
    $pdf->Cell($wi * 0.2, 4.5, '', 0, 0);
    $pdf->Cell($wi * 0.4, 4.5, pdf_u('LE PROVISEUR'), 0, 1, 'C');

    // Signatures positionnées en % du cadre COMPLET ($x0/$y0/$w0/$h0), pas du
    // curseur courant — mémorisation stable indépendante du nombre de lignes
    // de frais (voir get_signature_position()/pdf_signature_appliquer()). Le
    // dessin lui-même ne se produit QUE si explicitement demandé (voir note
    // ci-dessus) — pdf_signature_appliquer() vérifie déjà signature_configuree(),
    // mais ne sait pas si l'utilisateur l'a demandée : ce contrôle est fait ici.
    if ($avec_sig_intendant) {
        pdf_signature_appliquer($pdf, $type_document, 'intendant', $x0, $y0, $w0, $h0, [
            'x_pct' => (($xi - $x0) + $wi * 0.10) / $w0 * 100, 'y_pct' => ($y_signature_ligne + 4.5 - $y0) / $h0 * 100,
            'w_pct' => $wi * 0.22 / $w0 * 100, 'h_pct' => null,
        ]);
    }
    if ($avec_sig_proviseur) {
        pdf_signature_appliquer($pdf, $type_document, 'chef_etablissement', $x0, $y0, $w0, $h0, [
            'x_pct' => (($xi - $x0) + $wi * 0.68) / $w0 * 100, 'y_pct' => ($y_signature_ligne + 4.5 - $y0) / $h0 * 100,
            'w_pct' => $wi * 0.22 / $w0 * 100, 'h_pct' => null,
        ]);
    }

    if ($avec_qr && $qr_tmp) {
        $qr_taille = 12 + 4 * $echelle;
        $qr_x = $x0 + $w0 / 2 - $qr_taille / 2;
        $qr_y = $y0 + $h0 - $mi - $qr_taille - 3.5;
        $pdf->Image($qr_tmp, $qr_x, $qr_y, $qr_taille, $qr_taille, 'PNG');
        $pdf->SetFont('Arial', '', 6.5);
        $pdf->SetXY($x0 + $w0 / 2 - 25, $qr_y + $qr_taille + 0.3);
        $pdf->Cell(50, 3, pdf_u(mb_strtoupper(($etab['lieu'] ?: $etab['ville']) ?? 'MBÉ', 'UTF-8') . ', le ' . date('d/m/Y')), 0, 1, 'C');
        $pdf->SetFont('Arial', 'I', 5.5);
        $pdf->SetX($x0 + $w0 / 2 - 25);
        $pdf->Cell(50, 2.4, 'On', 0, 0, 'C');
    }

    // Copyright vertical le long du bord droit du cadre — texte standard
    // unique sur tous les PDF du projet (voir pdf_copyright(),
    // pdf/header_pdf.php), coordonnées/téléphone retirés.
    $pdf->SetFont('Arial', '', 5.5);
    $pdf->SetTextColor(90);
    $pdf->TextWithDirection($x0 + $w0 - 1.5, $y0 + $h0 - 4, pdf_u('Copyright © SIGES-V2 ABZ'), 'U');
    $pdf->SetTextColor(0);
}

// RoundedRect() existe déjà nativement dans pdf/fpdf.php (bibliothèque à ne
// jamais modifier) — ce petit indirecteur ne fait qu'appliquer les couleurs
// de trait par défaut utilisées par tous les documents de ce fichier.
function pdf_rect_arrondi_ou_natif(FPDF $pdf, float $x, float $y, float $w, float $h, float $r): void {
    $pdf->SetDrawColor(40);
    $pdf->SetLineWidth(0.3);
    $pdf->RoundedRect($x, $y, $w, $h, $r, 'D');
    $pdf->SetDrawColor(0);
    $pdf->SetLineWidth(0.2);
}
