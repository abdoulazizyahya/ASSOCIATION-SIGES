<?php
// ── Fonctions communes pour les PDF de la piste arabe (TCPDF) ──────
// Équivalent de pdf/header_pdf.php, mais pour TCPDF plutôt que FPDF —
// nécessaire pour un rendu arabe RTL réel (police aealarabiya, voir
// prompt_continuite_jaynitaare_v2.md, section TCPDF vendorisé). TCPDF est
// nativement UTF-8 : contrairement à pdf_u() (Windows-1252, FPDF), AUCUNE
// conversion d'encodage n'est nécessaire ici — les chaînes UTF-8 de la base
// sont passées telles quelles. Type-hinté TCPDF (pas FPDF) : ces fonctions
// ne sont donc PAS interchangeables avec celles de header_pdf.php.
// Inclure après require_once tcpdf/tcpdf.php.
//
// ⚠️ RÈGLE À RESPECTER PARTOUT DANS CE FICHIER (et tout fichier qui dessine
// en RTL) — 3 pièges TCPDF réels rencontrés et corrigés en session 5, aucun
// documenté de façon évidente, trouvés en inspectant tcpdf.php après un
// rendu visuel cassé (texte superposé/disparu/mal positionné) :
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
    if (!$chemin) return;
    $w_fili = $page_w * 0.70;
    $dim = @getimagesize($chemin);
    $h_fili = ($dim && $dim[0] > 0) ? $w_fili * $dim[1] / $dim[0] : $w_fili;
    $pdf->Image($chemin, ($page_w - $w_fili) / 2, ($page_h - $h_fili) / 2, $w_fili);
}

// Dessine une colonne comme une suite de LIGNES SIMPLES (Cell(), jamais
// MultiCell() avec retour automatique) : chaque ligne a une hauteur fixe
// connue à l'avance et une position Y explicite — élimine toute ambiguïté
// sur l'avancement de curseur entre appels (MultiCell() avec passage direct
// de $x/$y/$ln s'est révélé peu fiable en pratique avec TCPDF — piège
// spécifique, contourné ainsi plutôt que débogué plus avant).
// $lignes : [[texte, police('helvetica'|'amirib'), style, taille, hauteur_ligne], ...].
// $x : bord GAUCHE voulu de la colonne (comme pour la version LTR) — la
// conversion vers le bord droit (nécessaire en interne si $rtl) est faite ici.
function tcpdf_colonne_lignes(TCPDF $pdf, array $lignes, float $x, float $y, float $w, bool $rtl = false): float {
    foreach ($lignes as [$texte, $police, $style, $taille, $lh]) {
        if ($texte === '') { $y += $lh; continue; }
        $pdf->SetRTL($rtl, false);
        $pdf->SetFont($police, $style, $taille);
        // Rétrécit la police si le texte (nom d'établissement souvent long,
        // ex. "GROUPE SCOLAIRE BILINGUE ISLAMIQUE LES POUSSINS DE
        // JAYNITAARÉ") dépasserait la largeur de colonne sur UNE ligne —
        // Cell() ne retourne jamais à la ligne (contrairement à MultiCell()),
        // un texte trop large déborderait des deux côtés du centrage et
        // chevaucherait la colonne voisine (bug réel constaté).
        while ($pdf->GetStringWidth($texte) > $w && $taille > 5) { $taille -= 0.5; $pdf->SetFontSize($taille); }
        $pdf->SetXY($rtl ? $x + $w : $x, $y, true);
        $pdf->Cell($w, $lh, $texte, 0, 0, 'C');
        $pdf->SetRTL(false, false);
        $y += $lh;
    }
    return $y;
}

// En-tête bilingue FR (gauche) / logo (centre) / AR (droite, RTL) — utilise
// $etab_ar (colonnes republique_fr/ar, devise_fr/ar, ministere_fr/ar,
// delegation_reg/dep_fr/ar, arrondissement_fr/ar, ecole_fr/ar de la table
// `etablissement` — absorbée depuis l'ex-table etablissement_arabe en
// migration v31 ; en-tête officiel arabe, vraies données de l'école, pas
// une traduction improvisée) en plus de $etab (français, déjà utilisé
// partout ailleurs) pour le logo/immatriculation.
function tcpdf_entete(TCPDF $pdf, array $etab, ?array $etab_ar, float $page_w, float $marge = 10): void {
    $col = ($page_w - 2 * $marge) / 3;
    $y0 = $marge;
    $taille_logo = 22;

    // ── Gauche (français) ──────────────────────────────
    $y_fin_g = tcpdf_colonne_lignes($pdf, [
        [$etab_ar['republique_fr'] ?? 'REPUBLIQUE DU CAMEROUN', 'helvetica', '', 7, 3.6],
        [$etab_ar['delegation_reg_fr'] ?? '', 'helvetica', '', 6.5, 3.6],
        [$etab_ar['delegation_dep_fr'] ?? '', 'helvetica', '', 6.5, 3.6],
        [$etab_ar['arrondissement_fr'] ?? '', 'helvetica', '', 6.5, 3.6],
        [mb_strtoupper($etab_ar['ecole_fr'] ?? $etab['nom_fr']), 'helvetica', 'B', 7.5, 4],
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
        [$etab_ar['republique_ar'] ?? '', 'amirib', '', 7.5, 4],
        [$etab_ar['delegation_reg_ar'] ?? '', 'amirib', '', 7, 4],
        [$etab_ar['delegation_dep_ar'] ?? '', 'amirib', '', 7, 4],
        [$etab_ar['arrondissement_ar'] ?? '', 'amirib', '', 7, 4],
        [$etab_ar['ecole_ar'] ?? '', 'amirib', 'B', 8.5, 4.5],
        [$etab_ar['devise_ar'] ?? '', 'amirib', '', 7, 4],
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
    $h_fr = $h * 0.5; $h_ar = $h * 0.5;
    $pdf->SetXY($x, $y + ($h_fr - $taille_fr / 2.6) / 2, true);
    $pdf->SetFont('helvetica', 'B', $taille_fr);
    $pdf->Cell($w, $taille_fr / 2.2, $fr, 0, 0, $align);
    $pdf->SetRTL(true, false);
    $pdf->SetXY($x + $w, $y + $h_fr + ($h_ar - $taille_ar / 2.6) / 2, true); // bord DROIT = x + w
    $pdf->SetFont('amirib', '', $taille_ar);
    $pdf->Cell($w, $taille_ar / 2.2, $ar, 0, 0, $align);
    $pdf->SetRTL(false, false);
    $pdf->SetXY($x + $w, $y, true);
}

// Paire de cellules côte à côte : français (gauche, LTR) puis arabe (droite,
// RTL) continuant immédiatement après — pattern répété pour chaque ligne
// « Matière / المادة » (en-tête de tableau ET lignes de données). $x : bord
// GAUCHE de la paire complète ; $w : largeur TOTALE (français + arabe).
// Avance le curseur à ($x+$w, $y) après coup, comme un Cell() normal.
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

// ── Copyright standard, bas de page (équivalent TCPDF de pdf_copyright(),
//    pdf/header_pdf.php) — même texte unique sur tous les PDF du projet,
//    piste arabe comprise. TCPDF gère l'UTF-8 nativement (contrairement à
//    FPDF), aucune conversion d'encodage nécessaire ici.
function tcpdf_copyright(TCPDF $pdf, float $page_w, float $page_h, float $marge_bas = 8): void {
    $pdf->SetFont('helvetica', 'I', 6.5);
    $pdf->SetTextColor(120, 120, 120);
    $pdf->SetXY(0, $page_h - $marge_bas, true);
    $pdf->Cell($page_w, 4, 'Copyright © SIGES-V2 ABZ', 0, 0, 'C');
    $pdf->SetTextColor(0, 0, 0);
}
