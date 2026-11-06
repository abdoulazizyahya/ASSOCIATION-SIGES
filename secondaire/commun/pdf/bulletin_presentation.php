<?php
// =====================================================================
//  secondaire/commun/pdf/bulletin_presentation.php — présentation commune
//  des bulletins du secondaire GÉNÉRAL (06/11/2026, demande de lisibilité) :
//   - police condensée Roboto Condensed (licence Apache 2.0, font/robotoc*)
//     à la place d'Arial : plus de texte par ligne à taille égale ;
//   - en-tête réduit (République, devise, région, département, nom de
//     l'école en gras sur une ligne, B.P. / tél. / e-mail sur une ligne),
//     logo agrandi, immatriculation en dessous ;
//   - intitulé du trimestre en toutes lettres (« PREMIER TRIMESTRE »).
//  Le technique et le primaire n'utilisent pas ce fichier.
// =====================================================================

// À utiliser dans la classe FPDF du bulletin (use BulletinPoliceCondensee;) :
// tout SetFont('Arial', …) dessine en Roboto Condensed, mesures comprises.
trait BulletinPoliceCondensee
{
    private bool $police_condensee_ok = false;

    function SetFont($family, $style = '', $size = 0)
    {
        if (strcasecmp((string) $family, 'Arial') === 0) {
            if (!$this->police_condensee_ok) {
                foreach (['' => 'robotoc', 'B' => 'robotocb', 'I' => 'robotoci', 'BI' => 'robotocbi'] as $st => $f) {
                    $this->AddFont('RobotoC', $st, $f . '.php', __DIR__ . '/font/');
                }
                $this->police_condensee_ok = true;
            }
            $family = 'RobotoC';
        }
        parent::SetFont($family, $style, $size);
    }
}

// « PREMIER TRIMESTRE », « DEUXIÈME TRIMESTRE »… (au lieu de « TRIMESTRE 1 »).
function bulletin_trimestre_titre(array $trim_info): string {
    $o = (int) ($trim_info['ordre'] ?? 0);
    return [1 => 'PREMIER TRIMESTRE', 2 => 'DEUXIÈME TRIMESTRE', 3 => 'TROISIÈME TRIMESTRE'][$o]
        ?? mb_strtoupper((string) ($trim_info['libelle'] ?? ''));
}

// Conversion UTF-8 → cp1252 du bulletin appelant (u() ou uc() selon le fichier).
function bulletin_txt(string $s): string {
    if (function_exists('u')) return u($s);
    if (function_exists('uc')) return uc($s);
    return (string) iconv('UTF-8', 'windows-1252//TRANSLIT', $s);
}

// Plus grande taille (≤ $t_max, ≥ $t_min) à laquelle $txt tient dans $larg.
function bulletin_taille_ligne(FPDF $pdf, string $txt, string $style, float $larg, float $t_max, float $t_min = 5): float {
    for ($t = $t_max; $t > $t_min; $t -= 0.25) {
        $pdf->SetFont('Arial', $style, $t);
        if ($pdf->GetStringWidth($txt) <= $larg) return $t;
    }
    return $t_min;
}

// Bandeau du titre (pilule) : « BULLETIN SCOLAIRE DU PREMIER TRIMESTRE / First
// Term Report Card » sur une seule ligne, réduit ensemble si trop long. $fr et
// $en déjà permutés par bl() pour la section anglophone. Curseur sous le bandeau.
function bulletin_titre_bandeau(FPDF $pdf, float $ml, float $uw, string $fr, string $en): void {
    $y = $pdf->GetY();
    $h = 6;
    pdf_fill($pdf, 'bandeau_titre');
    pdf_draw($pdf, 'bordure_marque');
    $pdf->SetLineWidth(0.3);
    $pdf->RoundedRect($ml, $y, $uw, $h, $h / 2, 'FD');
    $t_fr = 13; $t_en = 10.5;
    do {
        $pdf->SetFont('Arial', 'BI', $t_fr); $w_fr = $pdf->GetStringWidth(bulletin_txt($fr . ' / '));
        $pdf->SetFont('Arial', 'I', $t_en);  $w_en = $pdf->GetStringWidth(bulletin_txt($en));
        if ($w_fr + $w_en <= $uw - 12 || $t_en <= 7) break;
        $t_fr -= 0.5; $t_en -= 0.5;
    } while (true);
    $pdf->SetTextColor(26, 60, 107);
    $pdf->SetXY($ml + ($uw - $w_fr - $w_en) / 2, $y);
    $pdf->SetFont('Arial', 'BI', $t_fr);
    $pdf->Cell($w_fr, $h, bulletin_txt($fr . ' / '), 0, 0, 'L');
    $pdf->SetFont('Arial', 'I', $t_en);
    $pdf->Cell($w_en, $h, bulletin_txt($en), 0, 0, 'L');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetLineWidth(0.2);
    $pdf->SetXY($ml, $y + $h);
}

// En-tête du bulletin : bloc français à gauche, anglais à droite (inversés
// pour la section anglophone), logo au centre, immatriculation en dessous.
// Laisse le curseur sous l'immatriculation.
function bulletin_entete_reduit(FPDF $pdf, array $etab, float $ml, float $uw, string $logo_path): void {
    $col3 = $uw / 3;
    $y0   = 5.4;   // juste sous le cadre extérieur (4 mm)
    $bloc = function (float $x, bool $en) use ($pdf, $etab, $col3, $y0): float {
        $bp  = trim((string) ($etab['boite_postale'] ?? ''));
        $vil = trim((string) ($etab['ville'] ?? ''));
        $tel = trim((string) ($etab['telephone'] ?? ''));
        $contact = implode('  -  ', array_filter([
            $bp !== '' ? ($en ? 'P.O. Box ' : 'B.P. ') . $bp . ($vil !== '' ? ' ' . $vil : '') : '',
            $tel !== '' ? ($en ? 'Phone: ' : 'Tél. : ') . $tel : '',
            trim((string) ($etab['email'] ?? '')),
        ]));
        $lignes = [
            [$en ? 'REPUBLIC OF CAMEROON' : 'REPUBLIQUE DU CAMEROUN', 'B', 7.5, 3.0],
            [$en ? 'Peace - Work - Fatherland' : 'Paix - Travail - Patrie', 'I', 6.5, 2.7],
            [(string) ($en ? ($etab['region_en'] ?? '') : ($etab['region_fr'] ?? '')), '', 6.3, 2.6],
            [(string) ($en ? ($etab['division_en'] ?? '') : ($etab['departement_fr'] ?? '')), '', 6.3, 2.6],
            [mb_strtoupper((string) ($en ? ($etab['nom_en'] ?? '') : ($etab['nom_fr'] ?? ''))), 'B', 10.5, 4.2],
            [$contact, '', 6.3, 2.8],
        ];
        $y = $y0;
        foreach ($lignes as [$txt, $st, $t_max, $h]) {
            $pdf->SetFont('Arial', $st, bulletin_taille_ligne($pdf, bulletin_txt($txt), $st, $col3 - 1, $t_max));
            $pdf->SetXY($x, $y);
            $pdf->Cell($col3, $h, bulletin_txt($txt), 0, 0, 'C');
            $y += $h;
        }
        return $y;
    };
    $en_gauche = function_exists('bulletin_en') && bulletin_en();   // section anglophone : anglais à gauche
    $y_fin = max($bloc($ml, $en_gauche), $bloc($ml + $col3 * 2, !$en_gauche)) + 0.3;

    // Logo : toute la hauteur de l'en-tête (proportions conservées).
    if ($logo_path !== '' && is_file($logo_path)) {
        pdf_logo_cadre($pdf, $logo_path, $ml + $col3 + ($col3 - 44) / 2, $y0, 44, $y_fin - $y0 - 0.3);
    } elseif (trim((string) ($etab['sigle'] ?? '')) !== '') {
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetXY($ml + $col3 + ($col3 - 28) / 2, $y0 + 2);
        $pdf->Cell(28, $y_fin - $y0 - 4, bulletin_txt($etab['sigle']), 1, 0, 'C');
    }
    $pdf->SetFont('Arial', 'I', 6.5);
    $pdf->SetXY($ml, $y_fin);
    $pdf->Cell($uw, 3.2, bulletin_txt('IMMATRICULATION : ' . ($etab['immatriculation'] ?? '')), 0, 1, 'C');
}
