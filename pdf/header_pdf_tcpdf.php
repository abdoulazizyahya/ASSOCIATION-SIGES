<?php
// ── Fonctions communes pour les PDF de la piste arabe (TCPDF) ──────
// Équivalent de pdf/header_pdf.php, mais pour TCPDF (rendu RTL réel).
// TCPDF est nativement UTF-8 : contrairement à pdf_u() (Windows-1252,
// FPDF), aucune conversion d'encodage n'est nécessaire ici. Type-hinté
// TCPDF (pas FPDF) : ces fonctions ne sont pas interchangeables avec
// celles de header_pdf.php. Inclure après require_once tcpdf/tcpdf.php.
//
// ⚠️ 3 pièges TCPDF RTL réels, non documentés dans TCPDF lui-même :
//  1) setRTL($enable, $resetx=true) : par défaut, CHAQUE bascule RTL↔LTR
//     déclenche un $this->Ln(0) interne qui réinitialise X au bord gauche OU
//     droit de la PAGE ENTIÈRE (this->lMargin / this->w - this->rMargin) —
//     détruit toute position "on continue sur la même ligne" acquise par un
//     Cell(ln=0) précédent. → toujours appeler SetRTL($bool, false).
//  2) setX($x, $rtloff=false) : tant que rtl=true, $x est réinterprété comme
//     une distance depuis le bord DROIT ($this->x = $this->w - $x), sauf
//     $rtloff=true. → toujours SetXY(..., true) / SetX(..., true) pendant RTL.
//  3) getCellCode() : MÊME avec $rtloff=true (donc $this->x = $x littéral),
//     Cell()/MultiCell() ancrent le rectangle par son bord DROIT en RTL
//     ($xk = ($x-$w)*$k), jamais par son bord gauche — il faut donc toujours
//     passer le bord DROIT voulu (x_gauche + largeur), jamais x_gauche.
// En pratique : SetRTL(true, false) → SetXY($x_gauche + $w, $y, true) →
// Cell($w, ...) → SetRTL(false, false).

function tcpdf_filigrane(TCPDF $pdf, array $etab, float $page_w, float $page_h): void {
    $chemin = pdf_filigrane_chemin($etab); // réutilise le cache PNG déjà généré côté FPDF
    if ($chemin) {
        $w_fili = $page_w * 0.70;
        $dim = @getimagesize($chemin);
        $h_fili = ($dim && $dim[0] > 0) ? $w_fili * $dim[1] / $dim[0] : $w_fili;
        $pdf->Image($chemin, ($page_w - $w_fili) / 2, ($page_h - $h_fili) / 2, $w_fili);
    }
    // Ouvert depuis le scan du QR code : même tampon que pdf_filigrane() (FPDF).
    if (pdf_tampon_actif()) tcpdf_tampon_authentique($pdf, $page_w / 2, $page_h * 0.52, $page_w * 0.80);
}

// Tampon oblique « AUTHENTIQUE » (version TCPDF de pdf_tampon_authentique(),
// header_pdf.php — même rendu : vert clair, dessiné avant le texte).
function tcpdf_tampon_authentique(TCPDF $pdf, float $cx, float $cy, float $largeur, string $mention = 'AUTHENTIQUE'): void {
    $rtl = $pdf->getRTL();
    $pdf->SetRTL(false, false);
    $pdf->SetFont('helvetica', 'B', 10);
    $taille = max(8, min(60, 10 * ($largeur * 0.80) / max(1, $pdf->GetStringWidth($mention))));
    $pdf->SetFont('helvetica', 'B', $taille);
    $w_txt = $pdf->GetStringWidth($mention);
    $k = $taille / 54;
    $w = $w_txt + 16 * $k; $h = 34 * $k;
    $couleur = [120, 200, 150];
    $pdf->StartTransform();
    $pdf->Rotate(28, $cx, $cy);
    $pdf->SetDrawColor(...$couleur);
    $pdf->SetTextColor(...$couleur);
    $pdf->SetLineWidth(max(0.3, 1.6 * $k));
    $pdf->RoundedRect($cx - $w / 2, $cy - $h / 2, $w, $h, 4 * $k, '1111', 'D');
    $pdf->SetLineWidth(max(0.15, 0.6 * $k));
    $m = 2 * $k;
    $pdf->RoundedRect($cx - $w / 2 + $m, $cy - $h / 2 + $m, $w - 2 * $m, $h - 2 * $m, 3 * $k, '1111', 'D');
    $pdf->SetXY($cx - $w / 2, $cy - 9 * $k);
    $pdf->Cell($w, 14 * $k, $mention, 0, 0, 'C', false, '', 0, false, 'T', 'M');
    $pdf->SetFont('helvetica', 'B', max(4, 10 * $k));
    $pdf->SetXY($cx - $w / 2, $cy + 7 * $k);
    $pdf->Cell($w, 6 * $k, pdf_tampon_mention(), 0, 0, 'C', false, '', 0, false, 'T', 'M');
    $pdf->StopTransform();
    $pdf->SetDrawColor(0); $pdf->SetTextColor(0); $pdf->SetLineWidth(0.2);
    $pdf->SetRTL($rtl, false);
}

// Dessine une colonne comme une suite de lignes simples (Cell(), jamais
// MultiCell() — position Y explicite par ligne, MultiCell() s'est révélé
// peu fiable en RTL avec TCPDF).
// $lignes : [[texte, police('helvetica'|'amirib'), style, taille, hauteur_ligne], ...].
// $x : bord GAUCHE voulu de la colonne — la conversion vers le bord droit
// (nécessaire en interne si $rtl) est faite ici.
function tcpdf_colonne_lignes(TCPDF $pdf, array $lignes, float $x, float $y, float $w, bool $rtl = false): float {
    foreach ($lignes as [$texte, $police, $style, $taille, $lh]) {
        if ($texte === '') { $y += $lh; continue; }
        $pdf->SetRTL($rtl, false);
        $pdf->SetFont($police, $style, $taille);
        // Rétrécit la police si le texte dépasse la largeur de colonne
        // (Cell() ne retourne jamais à la ligne, contrairement à MultiCell()).
        while ($pdf->GetStringWidth($texte) > $w && $taille > 5) { $taille -= 0.5; $pdf->SetFontSize($taille); }
        $pdf->SetXY($rtl ? $x + $w : $x, $y, true);
        $pdf->Cell($w, $lh, $texte, 0, 0, 'C');
        $pdf->SetRTL(false, false);
        $y += $lh;
    }
    return $y;
}

// En-tête bilingue FR (gauche) / logo (centre) / AR (droite, RTL) — $etab_ar
// (colonnes pays_etab_fr/ar, region_etab_fr/region_ar, departement_fr/ar,
// arrondissement_fr/ar, ecole_ar de `etablissement`) en plus de $etab
// (français) pour le logo/immatriculation.
function tcpdf_entete(TCPDF $pdf, array $etab, ?array $etab_ar, float $page_w, float $marge = 10): void {
    $col = ($page_w - 2 * $marge) / 3;
    $y0 = $marge;
    $taille_logo = 22;

    // ── Gauche (français) ──────────────────────────────
    $y_fin_g = tcpdf_colonne_lignes($pdf, [
        [$etab_ar['pays_etab_fr'] ?? 'REPUBLIQUE DU CAMEROUN', 'helvetica', '', 7, 3.6],
        [$etab_ar['region_etab_fr'] ?? '', 'helvetica', '', 6.5, 3.6],
        [$etab_ar['departement_fr'] ?? '', 'helvetica', '', 6.5, 3.6],
        [$etab_ar['arrondissement_fr'] ?? '', 'helvetica', '', 6.5, 3.6],
        [mb_strtoupper($etab_ar['Nom_Etab_Fr'] ?? $etab['nom_fr']), 'helvetica', 'B', 7.5, 4],
        ['B.P. ' . ($etab['boite_postale'] ?? '') . '  Tél.: ' . ($etab['telephone'] ?? ''), 'helvetica', '', 6.5, 3.6],
    ], $marge, $y0, $col);

    // ── Centre (logo) ──────────────────────────────────
    $logo_path = !empty($etab['logo']) ? __DIR__ . '/../assets/uploads/' . $etab['logo'] : '';
    $logo_x = $marge + $col + ($col - $taille_logo) / 2;
    if ($logo_path && is_file($logo_path)) {
        $pdf->Image($logo_path, $logo_x, $y0, $taille_logo);
    } else {
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetXY($logo_x, $y0 + 3);
        $pdf->Cell($taille_logo, 8, $etab['sigle'] ?? '', 1, 0, 'C');
    }
    if (!empty($etab['immatriculation'])) {
        $pdf->SetFont('helvetica', 'I', 5.5);
        $pdf->SetXY($marge + $col, $y0 + $taille_logo + 0.5);
        $pdf->Cell($col, 3, 'IMMATRICULATION : ' . $etab['immatriculation'], 0, 0, 'C');
    }

    // ── Droite (arabe, RTL) ─────────────────────────────
    $xr = $marge + $col * 2;
    $y_fin_d = tcpdf_colonne_lignes($pdf, [
        [$etab_ar['pays_etab_ar'] ?? '', 'amirib', '', 7.5, 4],
        [$etab_ar['region_ar'] ?? '', 'amirib', '', 7, 4],
        [$etab_ar['departement_ar'] ?? '', 'amirib', '', 7, 4],
        [$etab_ar['arrondissement_ar'] ?? '', 'amirib', '', 7, 4],
        [$etab_ar['ecole_ar'] ?? '', 'amirib', 'B', 8.5, 4.5],
    ], $xr, $y0, $col, true);

    $pdf->SetY(max($y_fin_g, $y_fin_d, $y0 + $taille_logo + 4) + 2);
}

// Bandeau titre bilingue FR (au-dessus) / AR RTL (en-dessous) — jamais sur
// la même ligne, même convention que pdf_bandeau() côté FPDF/français.
function tcpdf_bandeau(TCPDF $pdf, string $titre_fr, string $titre_ar, float $page_w, float $marge = 10): void {
    $w = $page_w - 2 * $marge;
    $pdf->SetFont('helvetica', 'B', 13);
    $pdf->SetFillColor(214, 234, 248);
    $pdf->SetDrawColor(30, 79, 216);
    $pdf->SetTextColor(20, 40, 120);
    $pdf->SetXY($marge, $pdf->GetY());
    $pdf->Cell($w, 8, $titre_fr, 1, 1, 'C', true);
    $pdf->SetRTL(true, false);
    $pdf->SetFont('amirib', '', 10);
    $pdf->SetFillColor(235, 244, 255);
    $pdf->SetXY($marge + $w, $pdf->GetY(), true); // bord DROIT voulu = marge + largeur
    $pdf->Cell($w, 6, $titre_ar, 1, 1, 'C', true);
    $pdf->SetRTL(false, false);
    $pdf->SetTextColor(0);
    $pdf->SetDrawColor(0);
    $pdf->Ln(1);
}

// Cellule bilingue FR (gras, au-dessus) / AR (RTL, en-dessous) — dessine son
// propre cadre puis avance X de $w, comme pdf_cell_bilingue() côté français.
function tcpdf_cell_bilingue(TCPDF $pdf, float $w, float $h, string $fr, string $ar,
                              int $border = 0, string $align = 'C', bool $fill = false,
                              float $taille_fr = 7.5, float $taille_ar = 7): void {
    $x = $pdf->GetX(); $y = $pdf->GetY();
    if ($fill || $border) {
        $style = $fill && $border ? 'DF' : ($fill ? 'F' : 'D');
        $pdf->Rect($x, $y, $w, $h, $style);
    }
    // Bloc FR+AR compact (interligne réduit à $gap), centré dans $h.
    $lh_fr = $taille_fr / 2.2; $lh_ar = $taille_ar / 2.2;
    $gap = 0.1;
    $bloc_h = $lh_fr + $gap + $lh_ar;
    $y_fr = $y + ($h - $bloc_h) / 2;
    $y_ar = $y_fr + $lh_fr + $gap;
    $pdf->SetXY($x, $y_fr, true);
    $pdf->SetFont('helvetica', 'B', $taille_fr);
    $pdf->Cell($w, $lh_fr, $fr, 0, 0, $align);
    $pdf->SetRTL(true, false);
    $pdf->SetXY($x + $w, $y_ar, true); // bord DROIT = x + w
    $pdf->SetFont('amirib', '', $taille_ar);
    $pdf->Cell($w, $lh_ar, $ar, 0, 0, $align);
    $pdf->SetRTL(false, false);
    $pdf->SetXY($x + $w, $y, true);
}

// Paire de cellules côte à côte : français (gauche, LTR) puis arabe (droite,
// RTL). $x : bord GAUCHE de la paire complète ; $w : largeur TOTALE.
function tcpdf_cellule_fr_ar(TCPDF $pdf, float $x, float $y, float $w, float $h,
                              string $fr, string $ar, float $ratio_fr = 0.55,
                              int $border = 1, bool $fill = false,
                              string $style_fr = 'B', float $taille_fr = 7, float $taille_ar = 7): void {
    $w_fr = $w * $ratio_fr; $w_ar = $w - $w_fr;
    $pdf->SetFont('helvetica', $style_fr, $taille_fr);
    $pdf->SetXY($x, $y, true);
    $pdf->Cell($w_fr, $h, $fr, $border, 0, 'L', $fill);
    $pdf->SetRTL(true, false);
    $pdf->SetFont('amirib', '', $taille_ar);
    $pdf->SetXY($x + $w, $y, true); // bord DROIT voulu = x + w (largeur totale de la paire)
    $pdf->Cell($w_ar, $h, $ar, $border, 0, 'R', $fill);
    $pdf->SetRTL(false, false);
    $pdf->SetXY($x + $w, $y, true);
}

// ── Copyright standard, bas de page (équivalent TCPDF de pdf_copyright(), pdf/header_pdf.php) ──
function tcpdf_copyright(TCPDF $pdf, float $page_w, float $page_h, float $marge_bas = 8): void {
    $pdf->SetFont('helvetica', 'I', 6.5);
    $pdf->SetTextColor(120, 120, 120);
    $pdf->SetXY(0, $page_h - $marge_bas, true);
    $pdf->Cell($page_w, 4, 'Copyright © SIGES-V2 ABZ', 0, 0, 'C');
    $pdf->SetTextColor(0, 0, 0);
}
