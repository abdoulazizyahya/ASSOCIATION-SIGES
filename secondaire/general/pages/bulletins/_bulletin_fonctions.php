<?php
// Fonctions du bulletin trimestriel APC (secondaire général), sorties de pdf.php
// pour que le rendu d'un élève (_bulletin_corps.php) puisse être répété pour
// toute une classe sans les redéclarer (30/10/2026).

function note_eff_comp(int $eid, int $id_comp, array $ni, array $nc, int $nb_ins): ?float {
    if (isset($ni[$eid][$id_comp])) return $ni[$eid][$id_comp];
    $cnt = $nc[$id_comp] ?? 0;
    if ($nb_ins > 0 && $cnt >= ceil($nb_ins / 2)) return 0.0; // absent = 0
    return null;
}

function mat_avg_comp(int $eid, array $comps, array $ni, array $nc, int $nb_ins): ?float {
    $tot = 0; $cnt = 0;
    foreach ($comps as $c) {
        // Classe mixte : matière d'une autre série jamais comptée ; seuil du
        // zéro automatique sur l'effectif du groupe qui la suit.
        $m = (int)($c['id_matiere'] ?? 0);
        if ($m && sec_hors_serie($eid, $m)) continue;
        $v = note_eff_comp($eid, (int)$c['id'], $ni, $nc, $m ? sec_effectif_groupe($eid, $m, $nb_ins) : $nb_ins);
        if ($v !== null) { $tot += $v; $cnt++; }
    }
    return $cnt > 0 ? $tot / $cnt : null;
}

function fmt_note_b(?float $v): string {
    if ($v === null) return '';
    $s = rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    // Zéro de tête sur la partie entière (demande explicite) : "4" -> "04",
    // "8.75" -> "08.75" — appliqué à toutes les notes/NxC/moyennes du bulletin.
    [$int, $dec] = array_pad(explode('.', $s, 2), 2, null);
    $int = str_pad($int, 2, '0', STR_PAD_LEFT);
    return $dec !== null ? "$int.$dec" : $int;
}

/**
 * Appréciation APC d'une MOYENNE (10/11/2026) : cote d'appreciation()
 * (mêmes seuils que les lignes de matières) + formule APC « Compétences …
 * acquises » (la moyenne générale est la moyenne des compétences évaluées) —
 * ex. ['cote' => 'B+', 'fr' => 'Compétences bien acquises', 'en' => …, 'abr' => 'CBA'].
 */
function appreciation_moyenne_apc(float $moy): ?array {
    $cote = appreciation($moy)['COTE'] ?? '';
    $lib = [
        'D'  => ['Compétences non acquises',         'Competences not acquired',         'CNA',  'CNA'],
        'C'  => ['Compétences moyennement acquises', 'Competences averagely acquired',   'CMA',  'CAA'],
        'C+' => ['Compétences acquises',             'Competences acquired',             'CA',   'CA'],
        'B'  => ['Compétences bien acquises',        'Competences well acquired',        'CBA',  'CWA'],
        'B+' => ['Compétences bien acquises',        'Competences well acquired',        'CBA',  'CWA'],
        'A'  => ['Compétences très bien acquises',   'Competences very well acquired',   'CTBA', 'CVWA'],
        'A+' => ['Compétences très bien acquises',   'Competences very well acquired',   'CTBA', 'CVWA'],
    ][$cote] ?? null;
    return $lib ? ['cote' => $cote, 'fr' => $lib[0], 'en' => $lib[1], 'abr_fr' => $lib[2], 'abr_en' => $lib[3]] : null;
}

function mention_gen_b(float $a): string {
    if (bulletin_en()) {
        if ($a >= 16) return 'Excellent'; if ($a >= 14) return 'Very good';
        if ($a >= 12) return 'Good'; if ($a >= 10) return 'Fairly good';
        if ($a >= 8)  return 'Average'; return 'Insufficient';
    }
    if ($a >= 16) return 'Excellent'; if ($a >= 14) return 'Tres bien';
    if ($a >= 12) return 'Bien'; if ($a >= 10) return 'Assez bien';
    if ($a >= 8)  return 'Passable'; return 'Insuffisant';
}

function rang_eleve_b(float $avg, array $avgs_sorted): int {
    $r = 1; foreach ($avgs_sorted as $a) { if ($a > $avg) $r++; else break; } return $r;
}

function moy_eleve_bull(int $eid, array $discs, array $competences_par_mat, array $ni, array $nc, int $nb_ins, array $mats_avec_notes, int $nb_mats_an): array {
    $tot = 0; $coef = 0; $nb_data = 0;
    foreach ($discs as $d) {
        if (!in_array($d['id_mat'], $mats_avec_notes)) continue;
        if (sec_hors_serie($eid, (int)$d['id_mat'])) { $nb_mats_an--; continue; } // classe mixte
        $avg = mat_avg_comp($eid, $competences_par_mat[$d['id_mat']] ?? [], $ni, $nc, $nb_ins);
        if ($avg !== null) { $tot += $avg * $d['coef']; $coef += $d['coef']; $nb_data++; }
    }
    $moy = $coef > 0 ? $tot / $coef : null;
    $classe_ok = $nb_mats_an > 0 && $nb_data >= ceil($nb_mats_an / 2);
    return [$moy, $classe_ok, $coef];
}

function checkbox_ltm(FPDF $pdf, float $x, float $y, bool $checked, float $s = 3): void {
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->Rect($x, $y, $s, $s);
    if ($checked) {
        $pdf->SetLineWidth(0.35);
        $pdf->Line($x + 0.4, $y + 0.4, $x + $s - 0.4, $y + $s - 0.4);
        $pdf->Line($x + $s - 0.4, $y + 0.4, $x + 0.4, $y + $s - 0.4);
        $pdf->SetLineWidth(0.2);
    }
}

function cell_rang(FPDF $pdf, float $w, float $h, ?int $rang, string $suffixe, int $border, string $align, float $taille, bool $bold = true): void {
    $x = $pdf->GetX(); $y = $pdf->GetY();
    $style = $bold ? 'B' : '';
    if ($border) { $pdf->Rect($x, $y, $w, $h); }
    if ($rang === null) {
        $pdf->SetFont('Arial', $style, $taille);
        $pdf->SetXY($x, $y);
        $pdf->Cell($w, $h, '-', 0, 0, $align);
        $pdf->SetXY($x + $w, $y);
        return;
    }

    $part1 = (string)$rang;
    $ord = rang_suffixe($rang);   // « e », ou « st/nd/rd/th » (section anglophone)
    $taille_e = round($taille * 0.62, 2);
    $pdf->SetFont('Arial', $style, $taille);
    $w1 = $pdf->GetStringWidth($part1);
    $w2 = $pdf->GetStringWidth(u($suffixe));
    $pdf->SetFont('Arial', $style, $taille_e);
    $we = $pdf->GetStringWidth($ord);
    $total = $w1 + $we + $w2;
    if ($align === 'C')     { $x0 = $x + ($w - $total) / 2; }
    elseif ($align === 'R') { $x0 = $x + $w - $total - 1.2; }
    else                    { $x0 = $x + 1.2; }

    $pdf->SetFont('Arial', $style, $taille);
    $pdf->SetXY($x0, $y);
    $pdf->Cell($w1, $h, $part1, 0, 0, 'L');

    $raise = $taille * 0.12; // décalage vertical approximatif (mm)
    $pdf->SetXY($x0 + $w1, $y - $raise);
    $pdf->SetFont('Arial', $style, $taille_e);
    $pdf->Cell($we, $h, $ord, 0, 0, 'L');

    $pdf->SetXY($x0 + $w1 + $we, $y);
    $pdf->SetFont('Arial', $style, $taille);
    $pdf->Cell($w2, $h, u($suffixe), 0, 0, 'L');

    $pdf->SetXY($x + $w, $y);
}

function cell2l_taille_ajustee(FPDF $pdf, string $texte, string $style, float $largeur_max, float $taille_max, float $taille_min): float {
    $taille = $taille_max;
    $pdf->SetFont('Arial', $style, $taille);
    while ($taille > $taille_min && $pdf->GetStringWidth(u($texte)) > $largeur_max) {
        $taille -= 0.25;
        $pdf->SetFont('Arial', $style, $taille);
    }
    return $taille;
}

function cell2l(FPDF $pdf, float $w, float $h, string $fr, string $en, string $align = 'L', bool $fill = false, float $taille_fr = 6, float $taille_en = 4.3, ?array $fill_color = null, bool $tight = false): void {
    [$fr, $en] = bl($fr, $en);   // section anglophone : l'anglais à la place du français
    $x = $pdf->GetX(); $y = $pdf->GetY();
    if ($fill) { $fill_color = $fill_color ?? couleur_pdf('bandeau_section'); $pdf->SetFillColor($fill_color[0], $fill_color[1], $fill_color[2]); $pdf->Rect($x, $y, $w, $h, 'F'); }
    $pdf->Rect($x, $y, $w, $h);
    // Marge de sécurité (offset 0.8+0.4 + cMargin interne de Cell()) — même
    // principe que fpdf_texte_ajuste() ailleurs dans ce fichier.
    $dispo = $w - 2.4;
    if ($en === '') {
        $taille_fr = cell2l_taille_ajustee($pdf, $fr, 'B', $dispo, $taille_fr, 5.5);
        $pdf->SetFont('Arial', 'B', $taille_fr);
        $pdf->SetXY($x + 0.8, $y);
        $pdf->Cell($w - 1.2, $h, u($fr), 0, 0, $align);
    } else {
        $taille_fr = cell2l_taille_ajustee($pdf, $fr, 'B', $dispo, $taille_fr, 5.5);
        $taille_en = cell2l_taille_ajustee($pdf, $en, 'I', $dispo, $taille_en, 4.5);
        // Interligne FR/EN resserré (demande explicite) : quasiment plus de
        // marge morte entre les 2 lignes, pour laisser le plus de place
        // possible au texte lui-même. $tight (demande explicite, case
        // d'identification élève) va plus loin : répartition stricte 50/50
        // sans aucun décalage haut, pour 2 lignes de MÊME taille de police.
        if ($tight) {
            [$y_fr, $h_fr, $y_en, $h_en] = [$y, $h * 0.5, $y + $h * 0.5, $h * 0.5];
        } else {
            [$y_fr, $h_fr, $y_en, $h_en] = [$y + $h * 0.02, $h * 0.52, $y + $h * 0.54, $h * 0.44];
            // Jamais plus grand que la demi-ligne (1 pt = 0.3528 mm) : sinon le
            // français et l'anglais se chevauchent (30/10/2026).
            $taille_fr = min($taille_fr, max(5.5, $h_fr / 0.3528 * 1.15));
            $taille_en = min($taille_en, max(4.5, $h_en / 0.3528 * 1.15));
        }
        $pdf->SetFont('Arial', 'B', $taille_fr);
        $pdf->SetXY($x + 0.8, $y_fr);
        $pdf->Cell($w - 1.2, $h_fr, u($fr), 0, 0, $align);
        $pdf->SetFont('Arial', 'I', $taille_en);
        $pdf->SetXY($x + 0.8, $y_en);
        $pdf->Cell($w - 1.2, $h_en, u($en), 0, 0, $align);
    }
    $pdf->SetXY($x + $w, $y);
}

function comp_wrap_lignes(FPDF $pdf, string $texte, float $largeur, float $taille): int {
    $pdf->SetFont('Arial', '', $taille);
    $mots = preg_split('/\s+/', trim($texte));
    $lignes = 1; $cur = '';
    foreach ($mots as $mot) {
        $essai = $cur === '' ? $mot : $cur . ' ' . $mot;
        if ($pdf->GetStringWidth(u($essai)) > $largeur - 2.4 && $cur !== '') {
            $lignes++; $cur = $mot;
        } else {
            $cur = $essai;
        }
    }
    return max(1, $lignes);
}

