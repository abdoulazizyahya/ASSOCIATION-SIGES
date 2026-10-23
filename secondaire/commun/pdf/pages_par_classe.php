<?php
// secondaire/commun/pdf/pages_par_classe.php — documents imprimés classe par
// classe (relevé vierge, fiche de présence), 22/10/2026 :
//  • numérotation des pages PAR CLASSE : « Page 1 / 2 » … « 2 / 2 », puis
//    repart à 1 pour la classe suivante, même dans le PDF de toutes les
//    classes. Chaque classe a son alias de total ({gN}), remplacé avant la sortie ;
//  • pied de page réduit (un filet + une ligne de 6,5 pt, pas de QR code) ;
//  • petit tableau d'effectifs H/F/T (nouveaux, redoublants) et Ⓡ après le
//    nom des redoublants.
require_once __DIR__ . '/fpdf.php';
require_once __DIR__ . '/header_pdf.php';

class FPDF_PagesParClasse extends FPDF {
    private array $groupes = [];   // alias => [première page, dernière page]
    private string $alias = '';

    // À appeler juste AVANT le AddPage() de chaque nouvelle classe.
    public function nouvelleClasse(): void {
        $this->alias = '{g' . (count($this->groupes) + 1) . '}';
        $this->groupes[$this->alias] = [$this->page + 1, $this->page + 1];
    }
    public function pageDansClasse(): int {
        $this->groupes[$this->alias][1] = $this->page;
        return $this->page - $this->groupes[$this->alias][0] + 1;
    }
    public function aliasClasse(): string { return $this->alias; }
    public function policeCourante(): array { return [$this->FontFamily, $this->FontStyle, $this->FontSizePt]; }
    public function Output($dest = '', $name = '', $isUTF8 = false) {
        foreach ($this->groupes as $alias => [$premiere, $derniere]) {
            for ($p = $premiere; $p <= $derniere; $p++) {
                $this->pages[$p] = str_replace($alias, (string) ($derniere - $premiere + 1), $this->pages[$p]);
            }
        }
        return parent::Output($dest, $name, $isUTF8);
    }
}

// ── Effectifs de la classe (22/10/2026) ────────────────────────────
// $eleves : lignes avec `sexe` et `statut_insc` (inscription.statut).
// Retour : ['nouveau'|'redoublant'|'total' => ['M'=>n, 'F'=>n, 'T'=>n]].
function pdf_effectifs_classe(array $eleves): array {
    $s = [];
    foreach (['nouveau', 'redoublant', 'total'] as $k) $s[$k] = ['M' => 0, 'F' => 0, 'T' => 0];
    foreach ($eleves as $el) {
        $sx = strtoupper(substr((string) ($el['sexe'] ?? ''), 0, 1)) === 'F' ? 'F' : 'M';
        $k  = pdf_est_redoublant($el) ? 'redoublant' : 'nouveau';
        foreach ([$k, 'total'] as $kk) { $s[$kk][$sx]++; $s[$kk]['T']++; }
    }
    return $s;
}

function pdf_est_redoublant(array $el): bool {
    return strtolower((string) ($el['statut_insc'] ?? '')) === 'redoublant';
}

// Petit tableau Nouveaux / Redoublants / Total × H, F, T (36 × 11,6 mm).
function pdf_tableau_effectifs(FPDF $pdf, array $s, float $x, float $y): void {
    $w_lib = 15; $w = 7; $h = 2.9;
    $pdf->SetDrawColor(120, 120, 120);
    $pdf->SetLineWidth(0.1);
    $pdf->SetFont('Arial', 'B', 5.5);
    $pdf->SetFillColor(230, 240, 250);
    $pdf->SetXY($x, $y);
    $pdf->Cell($w_lib, $h, '', 1, 0, 'C', true);
    foreach (['H', 'F', 'T'] as $c) $pdf->Cell($w, $h, $c, 1, 0, 'C', true);
    foreach (['nouveau' => 'Nouveaux', 'redoublant' => 'Redoublants', 'total' => 'Total'] as $k => $lib) {
        $y += $h;
        $pdf->SetXY($x, $y);
        $pdf->SetFont('Arial', 'B', 5.5);
        $pdf->Cell($w_lib, $h, pdf_u($lib), 1, 0, 'L', true);
        $pdf->SetFont('Arial', $k === 'total' ? 'B' : '', 5.5);
        foreach (['M', 'F', 'T'] as $c) $pdf->Cell($w, $h, (string) $s[$k][$c], 1, 0, 'C');
    }
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(0.2);
}

// Cellule « nom de l'élève » (bordure comprise), suivie de Ⓡ pour un
// redoublant : « R » dans un cercle dessiné, la police Arial du PDF n'ayant
// pas le caractère Ⓡ. La police courante doit être celle des lignes.
function pdf_cellule_nom(FPDF_PagesParClasse $pdf, float $x, float $y, float $w, float $h, string $nom, bool $redoublant): void {
    $pdf->SetXY($x, $y);
    $pdf->Cell($w, $h, $nom, 1, 0, 'L');
    if (!$redoublant) return;
    $d  = 3.2;
    $cx = min($x + 1 + $pdf->GetStringWidth($nom) + 0.8, $x + $w - $d - 0.5);
    $cy = $y + ($h - $d) / 2;
    [$famille, $style, $taille] = $pdf->policeCourante();
    $pdf->SetLineWidth(0.15);
    $pdf->RoundedRect($cx, $cy, $d, $d, $d / 2, 'D');
    $pdf->SetFont('Arial', 'B', 5.5);
    $pdf->SetXY($cx, $cy);
    $pdf->Cell($d, $d, 'R', 0, 0, 'C');
    $pdf->SetLineWidth(0.2);
    $pdf->SetFont($famille, $style, $taille);
}

// Pied de page réduit (8 mm). Laisser au moins 10 mm sous le contenu.
function pdf_pied_par_classe(FPDF_PagesParClasse $pdf, string $val_annee, float $pw, float $ph, float $ml): void {
    $y = $ph - 8;
    $pdf->SetDrawColor(190, 190, 190);
    $pdf->Line($ml, $y, $pw - $ml, $y);
    $pdf->SetFont('Arial', '', 6.5);
    $pdf->SetTextColor(110, 110, 110);
    $pdf->SetXY($ml, $y + 0.5);
    $pdf->Cell(($pw - 2 * $ml) / 2, 3.5, pdf_u('Copyright © SIGES ABZ - ' . $val_annee), 0, 0, 'L');
    $pdf->Cell(($pw - 2 * $ml) / 2, 3.5, pdf_u('Page ' . $pdf->pageDansClasse() . ' / ' . $pdf->aliasClasse()), 0, 0, 'R');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetDrawColor(0, 0, 0);
}
