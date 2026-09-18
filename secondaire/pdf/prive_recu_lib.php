<?php
// secondaire/pdf/prive_recu_lib.php — bibliothèque de rendu du reçu de
// paiement PRIVÉ, par élève (un seul numéro par élève — voir
// finances_numero_recu_eleve(), fonctions.php, réutilisée telle quelle,
// générique). Porté de pdf/recu_lib.php (primaire), même mise en page
// générale (3 copies COUPON PARENT/PROVISEUR/ARCHIVE empilées, cadre
// arrondi, tableau récapitulatif) — SIMPLIFIÉ par rapport à l'original :
// pas de QR de vérification publique (pdf/verif_recu_lib.php, hors
// périmètre), pas de "Cas social" (absent du schéma secondaire), ruban de
// titre en rectangle plein (pas de chevron à pointes arrondies — méthode
// FPDF::ChevronRibbon() absente de secondaire/pdf/fpdf.php, propre à la
// copie racine pdf/fpdf.php, pour ne pas toucher la classe FPDF partagée
// par tous les autres PDF secondaire). Demande explicite du 17/09/2026.
//
// À inclure APRÈS : config.php, connexion.php, fonctions.php,
// secondaire/pdf/fpdf.php, secondaire/pdf/header_pdf.php.
//
// Fournit prive_dessiner_recu_eleve() : ajoute UNE page à $pdf pour un
// élève donné. Renvoie false SANS rien dessiner si l'élève/la classe est
// introuvable ou si l'élève n'a aucun versement PRIVÉ pour l'année.

if (!function_exists('prive_dessiner_recu_eleve')) {
function prive_dessiner_recu_eleve(FPDF $pdf, int $id_eleve, int $id_classe, int $id_annee, string $val_annee): bool {
    $eleve  = db_one("SELECT * FROM eleve WHERE id=?", [$id_eleve]);
    $classe = db_one("SELECT * FROM classe WHERE id=?", [$id_classe]);
    if (!$eleve || !$classe) return false;

    $total_du = (float) db_val("SELECT COALESCE(SUM(montant_obligation),0) FROM obligation_privee WHERE code_niveau=?", [$classe['code_niveau']]);
    $versements = db_all(
        "SELECT montant_paiement, date_paiement FROM paiement_prive WHERE id_eleve=? AND id_annee=? ORDER BY date_paiement, id",
        [$id_eleve, $id_annee]
    );
    if (!$versements) return false;
    $total_paye = array_sum(array_column($versements, 'montant_paiement'));
    $reste      = max(0.0, $total_du - $total_paye);

    $numero_recu = finances_numero_recu_eleve($id_eleve, $val_annee);

    $etab_brut = get_etablissement();

    $pdf->AddPage();
    $BLEU = [106, 181, 255];

    $dessiner_copie = function (float $offY, string $etiquette) use (
        $pdf, $etab_brut, $eleve, $classe, $versements, $numero_recu, $val_annee,
        $total_du, $total_paye, $reste, $BLEU
    ): void {
        $u = fn(string $s) => pdf_u($s);

        $texteAjuste = function (string $texte, float $x, float $y, float $largeurMax, string $style, float $taille) use ($pdf, $u): void {
            $txt = $u($texte);
            $pdf->SetFont('Arial', $style, $taille);
            while ($taille > 5.5 && $pdf->GetStringWidth($txt) > $largeurMax) {
                $taille -= 0.25;
                $pdf->SetFont('Arial', $style, $taille);
            }
            $pdf->Text($x, $y, $txt);
        };

        // Cadre (bordure arrondie fine)
        $pdf->SetDrawColor(0);
        $pdf->SetLineWidth(0.27);
        $pdf->RoundedRect(7.5, 4 + $offY, 197, 93, 3.13, 'D');

        // Filigrane (logo très éclairci en fond) + logo net en en-tête.
        pdf_filigrane($pdf, $etab_brut, 210, 297, 66, 72, 29 + $offY);
        $logo_path = !empty($etab_brut['logo']) ? __DIR__ . '/../../assets/uploads/' . $etab_brut['logo'] : '';
        if ($logo_path && is_file($logo_path)) {
            $pdf->Image($logo_path, 95, 4.3 + $offY, 20, 18);
        }

        // En-tête bilingue (3 lignes FR gauche / EN droite).
        $pdf->SetTextColor(0);
        $texteAjuste('REPUBLIQUE DU CAMEROUN', 23.35, 7.55 + $offY, 124, '', 8);
        $texteAjuste('REPUBLIC OF CAMEROON', 149.39, 7.55 + $offY, 52, '', 8);
        $texteAjuste($etab_brut['region_fr'] ?: "REGION DE L'ADAMAOUA", 26.06, 10.95 + $offY, 121, '', 8);
        $texteAjuste($etab_brut['region_en'] ?: 'ADAMAWA REGION', 154.25, 10.95 + $offY, 47, '', 8);
        $texteAjuste($etab_brut['nom_fr'] ?: 'ÉTABLISSEMENT', 14.21, 14.45 + $offY, 124, 'B', 9);
        $texteAjuste($etab_brut['nom_en'] ?: 'SCHOOL', 140.25, 14.45 + $offY, 65, 'B', 9);

        // Ruban de titre (rectangle plein — pas de chevron, voir en-tête du
        // fichier) + case numéro.
        $pdf->SetFillColor($BLEU[0], $BLEU[1], $BLEU[2]);
        $pdf->Rect(64, 23.3 + $offY, 81, 6, 'F');
        $pdf->SetTextColor(0);
        $pdf->SetFont('Arial', 'B', 13);
        $pdf->Text(62, 28 + $offY, $u('REÇU DE PAIEMENT'));
        $pdf->SetFont('Arial', 'I', 11);
        $pdf->Text(108.5, 27.8 + $offY, $u('/ PAYMENT RECEIPT'));
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Text(165, 28 + $offY, $u('N° :'));
        $pdf->Rect(175, 24 + $offY, 20, 5, 'S');
        $pdf->Text(177.35, 27.77 + $offY, $u($numero_recu));

        // Identité de l'élève
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Text(15, 26.5 + $offY, $u('Année scolaire: ' . $val_annee));
        $pdf->SetFont('Arial', 'I', 8);
        $pdf->Text(15, 29 + $offY, $u('School Year'));

        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Text(12, 33.5 + $offY, $u('Classe : ' . $classe['designation']));
        $pdf->Text(95, 33.5 + $offY, $u('Matricule : ' . $eleve['matricule']));
        $pdf->Text(12, 40.5 + $offY, $u('Nom et Prénoms : ' . mb_strtoupper($eleve['nom']) . ' ' . ($eleve['prenom'] ?? '')));
        $pdf->SetFont('Arial', 'I', 8.2);
        $pdf->Text(12, 35.8 + $offY, $u('Class'));
        $pdf->Text(95, 35.8 + $offY, $u('Register N°'));
        $pdf->Text(12, 42.8 + $offY, $u('Surname and given Names'));

        // ── Tableau récapitulatif des paiements ─────────────────────
        $pdf->SetFont('Arial', 'BI', 9.2);
        $pdf->Text(12, 55.5 + $offY, $u('TABLEAU RECAPITULATIF DES PAIEMENTS'));

        $tableTop = 56.5 + $offY;
        $headerH  = 4.0;
        $n        = count($versements);
        $rowH   = $n > 3 ? min(3.5, 13.5 / $n) : 3.5;
        $rowFont = $rowH >= 3.2 ? 9 : max(4.5, $rowH * 2.6);
        $totalH = 3.5;
        $x0 = 12; $wFrais = 25; $wMontant = 22; $wDate = 22;
        $x1 = $x0 + $wFrais; $x2 = $x1 + $wMontant; $x3 = $x2 + $wDate;

        $pdf->SetDrawColor(0);
        $pdf->SetLineWidth(0.3);
        $pdf->SetFillColor($BLEU[0], $BLEU[1], $BLEU[2]);
        $pdf->Rect($x0, $tableTop, $wFrais, $headerH + $n * $rowH, 'B');
        $pdf->Rect($x1, $tableTop, $wMontant, $headerH, 'B');
        $pdf->Rect($x2, $tableTop, $wDate, $headerH, 'B');
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Text($x0 + 7.74, $tableTop + $headerH + $n * $rowH / 2 + 0.6, $u('FRAIS'));
        $pdf->Text($x1 + 3.06, $tableTop + $headerH - 1.05, $u('MONTANT'));
        $pdf->Text($x2 + 3.94, $tableTop + $headerH - 1.05, $u('DATE'));

        $pdf->SetFont('Arial', '', $rowFont);
        $yBaselineOffset = min(0.5, $rowH * 0.16);
        $y = $tableTop + $headerH;
        foreach ($versements as $v) {
            $y += $rowH;
            $montant_txt = number_format((float) $v['montant_paiement'], 0, ',', ' ');
            $pdf->Text($x1 + ($wMontant - $pdf->GetStringWidth($montant_txt)) / 2, $y - $yBaselineOffset, $montant_txt);
            $pdf->Text($x2 + 3, $y - $yBaselineOffset, $u(date_fr($v['date_paiement'])));
            $pdf->Line($x1, $y, $x3, $y);
        }
        $pdf->Line($x1, $tableTop + $headerH, $x1, $y);
        $pdf->Line($x2, $tableTop + $headerH, $x2, $y);
        $pdf->Line($x3, $tableTop + $headerH, $x3, $y);

        $pdf->SetFillColor($BLEU[0], $BLEU[1], $BLEU[2]);
        $pdf->Rect($x0, $y, $wFrais, $totalH, 'B');
        $pdf->Rect($x1, $y, $wMontant, $totalH, 'B');
        $pdf->Rect($x2, $y, $wDate, $totalH, 'B');
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Text($x0 + 7.21, $y + $totalH - 0.6, $u('TOTAL'));
        $total_txt = number_format($total_paye, 0, ',', ' ');
        $pdf->Text($x1 + ($wMontant - $pdf->GetStringWidth($total_txt)) / 2, $y + $totalH - 0.6, $total_txt);

        // ── Résumé (Total dû / Payé / Reste) ────────────────────────
        $yTot = $tableTop + $headerH;
        $yPaye = $yTot + 7.0;
        $yReste = $yTot + 14.0;
        $pdf->SetFont('Arial', 'B', 9.5);
        $pdf->Text(85, $yTot, $u('MONTANT TOTAL  : ' . number_format($total_du, 0, ',', ' ') . '  FCFA'));
        $pdf->Text(85, $yPaye, $u('MONTANT PAYÉ    : ' . number_format($total_paye, 0, ',', ' ') . '  FCFA'));
        $pdf->Text(85, $yReste, $u('RESTE À PAYER    : ' . number_format($reste, 0, ',', ' ') . '  FCFA'));
        $pdf->SetFont('Arial', 'I', 8.2);
        $pdf->Text(85, $yTot + 2.3, $u('Total amount'));
        $pdf->Text(85, $yPaye + 2.3, $u('Amount already pay'));
        $pdf->Text(85, $yReste + 2.3, $u('Balance'));

        // ── Signature ────────────────────────────────────────────────
        $pdf->SetFont('Arial', '', 8);
        $pdf->Text(150, 56.5 + $offY, $u(($etab_brut['ville'] ?: '') . ', le ' . date('d/m/Y')));
        $pdf->SetFont('Arial', 'I', 7);
        $pdf->Text(164.88, 59 + $offY, $u('On'));
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Text(162, 63.5 + $offY, $u(strtoupper($etab_brut['chef_etablissement'] ?? 'LE PROVISEUR')));
        $pdf->SetFont('Arial', 'I', 9);
        $pdf->Text(162, 66 + $offY, $u(strtoupper($etab_brut['chef_etablissement_en'] ?? 'The Principal')));
        $pdf->SetFillColor(0, 0, 0);
        $pdf->Rect(162, 66.32 + $offY, 23.81, 0.16, 'F');

        // ── Étiquette latérale (verticale, bord gauche) + copyright
        //    (verticale, bord droit) ─────────────────────────────────
        $pdf->SetFont('Arial', 'BI', 7);
        $pdf->TextWithDirection(6.8, 56.5 + $offY, $u($etiquette), 'U');
        $pdf->SetFont('Arial', 'BI', 4.5);
        $pdf->TextWithDirection(206.49, 93.49 + $offY, $u('Copyright © SIGES-V2 ABZ'), 'U');

        // ── Séparateur pointillé sous la copie ───────────────────────
        $pdf->SetFont('Arial', '', 18);
        $pdf->Text(0, 101 + $offY, str_repeat('-', 101));
        $pdf->SetTextColor(0);
    };

    $dessiner_copie(0, 'COUPON PARENT');
    $dessiner_copie(98, 'COUPON PROVISEUR');
    $dessiner_copie(196, 'COUPON ARCHIVE');

    return true;
}
}
