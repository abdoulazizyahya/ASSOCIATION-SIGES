<?php
// pdf/recu_lib.php — bibliothèque partagée de rendu du reçu de paiement PAR
// ÉLÈVE (récapitulatif annuel, un seul numéro par élève — voir
// finances_numero_recu_eleve(), fonctions.php). Extraite de
// pages/finances/recu.php (impression unitaire, demande du 15/08/2026) pour
// être réutilisée par pages/finances/recus_lot.php (impression groupée,
// demande du 13/09/2026) SANS dupliquer la reconstruction pixel-exacte du
// modèle de référence (voir l'en-tête de recu.php pour son origine).
//
// À inclure APRÈS : config.php, connexion.php, fonctions.php, pdf/fpdf.php,
// pdf/header_pdf.php, pdf/verif_recu_lib.php.
//
// Fournit finances_dessiner_recu_eleve() : ajoute UNE page à $pdf (3 copies
// empilées COUPON PARENT/DIRECTION/ARCHIVE) pour un élève donné. Renvoie
// false SANS rien dessiner si l'élève/la classe est introuvable ou si
// l'élève n'a aucun versement pour l'année — l'appelant (recu.php pour un
// seul élève, recus_lot.php pour plusieurs) décide alors du message.

if (!function_exists('finances_dessiner_recu_eleve')) {
function finances_dessiner_recu_eleve(FPDF $pdf, int $id_eleve, int $id_classe, string $val_annee): bool {
    $eleve  = db_one("SELECT * FROM eleve WHERE id_eleve=?", [$id_eleve]);
    $classe = db_one("SELECT * FROM classe WHERE IDClasses=?", [$id_classe]);
    if (!$eleve || !$classe) return false;

    // Cas social (migration_v39) : réduction appliquée au montant total dû —
    // voir eleve_pourcentage_reduction() (fonctions.php), même règle que
    // pages/finances/versement.php.
    $pourcentage_reduction = eleve_pourcentage_reduction($id_eleve);
    $total_du_normal = (float) db_val("SELECT COALESCE(SUM(montant_obligation),0) FROM obligation WHERE niveau_obligation=?", [$classe['Niveau']]);
    $total_du = $pourcentage_reduction > 0 ? round($total_du_normal * (1 - $pourcentage_reduction / 100), 2) : $total_du_normal;
    $versements = db_all(
        "SELECT montant_paiement, date_paiement FROM paiement_frais WHERE id_eleve=? AND val_annee=? ORDER BY date_paiement, id_pay",
        [$id_eleve, $val_annee]
    );
    if (!$versements) return false;
    $total_paye = array_sum(array_column($versements, 'montant_paiement'));
    $reste      = max(0.0, $total_du - $total_paye);

    $numero_recu = finances_numero_recu_eleve($id_eleve, $val_annee);
    $qr_chemin   = recu_qr_fichier_temp($id_eleve, $val_annee, $numero_recu);

    $etab_brut = get_etablissement();
    $sexe_lettre = stripos($eleve['Sexe_elv'] ?? '', 'F') === 0 ? 'F' : 'M';
    $naiss = trim(date_fr($eleve['Date_naiss_elv'] ?: null) . ($eleve['Date_naiss_elv'] ? '' : '//') . '  à  ' . ($eleve['Lieu_naiss_elv'] ?? ''));

    $pdf->AddPage();
    $BLEU = [106, 181, 255];

    // Dessine UNE copie complète (identique aux 2 autres, juste décalée de
    // $offY) — voir l'en-tête de recu.php pour le détail de l'extraction.
    $dessiner_copie = function (float $offY, string $etiquette) use (
        $pdf, $etab_brut, $eleve, $classe, $versements, $numero_recu, $val_annee,
        $total_du, $total_paye, $reste, $qr_chemin, $sexe_lettre, $naiss, $BLEU,
        $pourcentage_reduction
    ): void {
        $u = fn(string $s) => pdf_u($s);

        // Écrit $texte à ($x,$y) en réduisant la police (jusqu'à 5.5pt) si
        // besoin pour qu'il tienne dans $largeurMax — sans quoi un nom
        // d'établissement long déborde sur la colonne anglaise ou hors de
        // la page (retour utilisateur du 12/09/2026). Taille identique à
        // l'original tant que le texte tient déjà.
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
        $etab_pdf = etab_pour_pdf($etab_brut);
        pdf_filigrane($pdf, $etab_pdf, 210, 297, 66, 72, 29 + $offY);
        $logo_path = !empty($etab_brut['logo']) ? __DIR__ . '/../assets/uploads/' . $etab_brut['logo'] : '';
        if ($logo_path && is_file($logo_path)) {
            $pdf->Image($logo_path, 95, 4.3 + $offY, 20, 18);
        }

        // En-tête bilingue (3 lignes FR gauche / EN droite).
        $pdf->SetTextColor(0);
        $texteAjuste($etab_brut['pays_etab_fr'] ?? 'REPUBLIQUE DU CAMEROUN', 23.35, 7.55 + $offY, 124, '', 8);
        $texteAjuste('REPUBLIC OF CAMEROON', 149.39, 7.55 + $offY, 52, '', 8);
        $texteAjuste($etab_brut['region_etab_fr'] ?: "REGION DE L'ADAMAOUA", 26.06, 10.95 + $offY, 121, '', 8);
        $texteAjuste($etab_brut['region_etab_en'] ?: 'ADAMAWA REGION', 154.25, 10.95 + $offY, 47, '', 8);
        $texteAjuste($etab_brut['Nom_Etab_Fr'] ?: 'GSBI LES POUSSINS DE JAYNITAARE', 14.21, 14.45 + $offY, 124, 'B', 9);
        $texteAjuste($etab_brut['Nom_Etab_An'] ?: 'BISG THE CHICKS OF JAYNITAARE', 140.25, 14.45 + $offY, 65, 'B', 9);

        // Ruban de titre + case numéro
        pdf_ruban_chevron($pdf, 64, 145, 23.3 + $offY, 29.3 + $offY, 6, $BLEU);
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
        $pdf->Text(12, 33.5 + $offY, $u('Classe : ' . $classe['DesignationClasses']));
        $pdf->Text(95, 33.5 + $offY, $u('Matricule : ' . $eleve['Mat_elv']));
        $pdf->Text(12, 40.5 + $offY, $u('Nom et Prénoms : ' . mb_strtoupper($eleve['Nom_elv']) . ' ' . ($eleve['Prenom_elv'] ?? '')));
        $pdf->Text(12, 47.5 + $offY, $u('Date et lieu de naissance : ' . $naiss));
        $pdf->Text(160, 47.5 + $offY, $u('Sexe : ' . $sexe_lettre));
        $pdf->SetFont('Arial', 'I', 8.2);
        $pdf->Text(12, 35.8 + $offY, $u('Class'));
        $pdf->Text(95, 35.8 + $offY, $u('Register N°'));
        $pdf->Text(12, 42.8 + $offY, $u('Surname and given Names'));
        $pdf->Text(12, 49.8 + $offY, $u('Date and place of birth'));
        $pdf->Text(160, 49.8 + $offY, $u('Sex'));

        // ── Tableau récapitulatif des paiements ─────────────────────
        $pdf->SetFont('Arial', 'BI', 9.2);
        $pdf->Text(12, 55.5 + $offY, $u('TABLEAU RECAPITULATIF DES PAIEMENTS'));

        $tableTop = 56.5 + $offY;
        $headerH  = 4.0;
        $n        = count($versements);
        // ≤3 lignes : hauteur/police identiques au modèle (3.5mm/ligne, 9pt).
        // Au-delà, compression progressive — voir recu.php pour le détail.
        $rowH   = $n > 3 ? min(3.5, 13.5 / $n) : 3.5;
        $rowFont = $rowH >= 3.2 ? 9 : max(4.5, $rowH * 2.6);
        $totalH = 3.5;
        $x0 = 12; $wFrais = 25; $wMontant = 22; $wDate = 22;
        $x1 = $x0 + $wFrais; $x2 = $x1 + $wMontant; $x3 = $x2 + $wDate;

        $pdf->SetDrawColor(0);
        $pdf->SetLineWidth(0.3);
        $pdf->SetFillColor($BLEU[0], $BLEU[1], $BLEU[2]);
        $pdf->Rect($x0, $tableTop, $wFrais, $headerH + $n * $rowH, 'B'); // FRAIS (fusionnée sur toute la hauteur)
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

        // ── Résumé (Total dû / Payé / Reste) — position FIXE, voir recu.php.
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
        if ($pourcentage_reduction > 0) {
            $pdf->SetFont('Arial', 'BI', 6.5);
            $pourcentage_txt = rtrim(rtrim(number_format($pourcentage_reduction, 2, '.', ''), '0'), '.');
            $pdf->Text(85, $yReste + 5.3, $u("Cas social — réduction de {$pourcentage_txt}% appliquée"));
        }

        // ── Signature ────────────────────────────────────────────────
        $pdf->SetFont('Arial', '', 8);
        $pdf->Text(150, 56.5 + $offY, $u(($etab_pdf['lieu'] ?: $etab_pdf['ville']) . ', le ' . date('d/m/Y')));
        $pdf->SetFont('Arial', 'I', 7);
        $pdf->Text(164.88, 59 + $offY, $u('On'));
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Text(162, 63.5 + $offY, $u('LE DIRECTEUR'));
        $pdf->SetFont('Arial', 'I', 9);
        $pdf->Text(162, 66 + $offY, $u('THE DIRECTOR'));
        $pdf->SetFillColor(0, 0, 0);
        $pdf->Rect(162, 66.32 + $offY, 23.81, 0.16, 'F');

        // ── QR de vérification ───────────────────────────────────────
        if ($qr_chemin) {
            $pdf->Image($qr_chemin, 98, 82 + $offY, 14, 14, 'PNG');
        }

        // ── Étiquette latérale (verticale, bord gauche) + copyright
        //    (verticale, bord droit) ─────────────────────────────────
        $pdf->SetFont('Arial', 'BI', 7);
        $pdf->TextWithDirection(6.8, 56.5 + $offY, $u($etiquette), 'U');
        $pdf->SetFont('Arial', 'BI', 4.5);
        $pdf->TextWithDirection(206.49, 93.49 + $offY, $u(
            'Copyright © SIGES-V2 ABZ'
        ), 'U');

        // ── Séparateur pointillé sous la copie ───────────────────────
        $pdf->SetFont('Arial', '', 18);
        $pdf->Text(0, 101 + $offY, str_repeat('-', 101));
        $pdf->SetTextColor(0);
    };

    $dessiner_copie(0, 'COUPON PARENT');
    $dessiner_copie(98, 'COUPON DIRECTION');
    $dessiner_copie(196, 'COUPON ARCHIVE');

    return true;
}
}
