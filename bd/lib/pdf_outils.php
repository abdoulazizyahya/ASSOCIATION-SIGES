<?php
// bd/lib/pdf_outils.php — petits outils de mise en page communs aux PDF
// (FPDF comme TCPDF : seules les méthodes Image()/SetFont()/Cell() communes
// aux deux sont utilisées). Chargé par fonctions.php et par les en-têtes
// partagés (primaire/pdf/header_pdf.php, secondaire/commun/pdf/header_pdf.php,
// primaire/pdf/header_pdf_tcpdf.php).

// Logo (ou toute image) ajusté DANS un cadre $w × $h : proportions
// conservées, centré dans le cadre, sans jamais en sortir — un logo haut ne
// descend plus sur l'immatriculation, un logo large ne déborde plus sur les
// colonnes de texte, et l'image n'est jamais déformée.
if (!function_exists('pdf_logo_cadre')) {
    function pdf_logo_cadre($pdf, string $chemin, float $x, float $y, float $w, float $h): void {
        if ($w <= 0 || $h <= 0 || !is_file($chemin)) return;
        $dim   = @getimagesize($chemin);
        $ratio = ($dim && $dim[0] > 0 && $dim[1] > 0) ? $dim[1] / $dim[0] : 1.0;   // hauteur / largeur
        $lw = $w; $lh = $w * $ratio;
        if ($lh > $h) { $lh = $h; $lw = $h / $ratio; }
        $pdf->Image($chemin, $x + ($w - $lw) / 2, $y + ($h - $lh) / 2, $lw, $lh);
    }
}

// Lignes de texte (UTF-8) centrées dans une colonne de largeur $w, chacune
// sur UNE seule ligne : la police est réduite au besoin (jusqu'à
// $taille_min) pour que la ligne tienne dans la colonne — un nom
// d'établissement long (FR/EN) ne déborde jamais de sa colonne ni du cadre
// de la page. FPDF uniquement (texte converti en Windows-1252). Retourne le
// Y de fin.
if (!function_exists('pdf_lignes_ajustees')) {
    function pdf_lignes_ajustees($pdf, array $lignes, float $x, float $y, float $w, float $lh,
                                 float $taille, string $style = '', float $taille_min = 3.5): float {
        foreach ($lignes as $ligne) {
            $t = mb_convert_encoding((string) $ligne, 'Windows-1252', 'UTF-8');
            $tt = $taille;
            $pdf->SetFont('Arial', $style, $tt);
            while ($tt > $taille_min && $pdf->GetStringWidth($t) > $w - 1) {
                $tt -= 0.25;
                $pdf->SetFont('Arial', $style, $tt);
            }
            $pdf->SetXY($x, $y);
            $pdf->Cell($w, $lh, $t, 0, 0, 'C');
            $y += $lh;
        }
        $pdf->SetXY($x, $y);
        return $y;
    }
}
