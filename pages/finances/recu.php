<?php
// pages/finances/recu.php — Reçu de paiement PAR ÉLÈVE (un seul numéro par
// élève, demande explicite du 15/08/2026 : « le reçu doit être par élève
// avec un seul numéro par élève, il faut respecter le modèle... exactement
// »). Remplace l'ancien reçu (qui pouvait aussi être réimprimé pour un seul
// versement via ?pay=, chaque versement ayant son propre numéro).
//
// Reconstruction PIXEL-EXACTE du modèle fourni (recu.pdf) : décompression
// du flux de contenu FPDF de ce PDF (Producer FPDF 1.53, même auteur que ce
// projet), conversion pt→mm de CHAQUE position/couleur/police, texte placé
// via Text() (coordonnées baseline directes, comme le Td/Tj du PDF source —
// pas de Cell() dont le padding interne aurait fallu redeviner). Page A4,
// 3 copies identiques empilées (COUPON PARENT / DIRECTION / ARCHIVE),
// même bloc de 93mm dupliqué 3× (y = 4, 102, 200mm), séparées par une ligne
// de tirets (police 18pt, comme l'original — pas un SetDash, FPDF n'en a
// pas). Couleur unique du document : RGB(106,181,255) — ruban de titre,
// en-tête du tableau, ligne TOTAL (voir FPDF::ChevronRibbon(), pdf/fpdf.php,
// ajoutée pour ce document).
//
// Table des paiements : contrairement à l'ancien reçu (qui distinguait
// chaque frais via nom_obligation), le modèle de référence ne montre que
// Montant/Date par ligne sous un seul intitulé "FRAIS" fusionné — reçu
// récapitulatif de TOUS les versements de l'élève pour l'année, pas un
// détail par type de frais. Nombre de lignes variable (contrairement à
// l'exemple à 3 lignes) : la hauteur de chaque ligne s'adapte pour que le
// tableau garde toujours la même zone verticale réservée (≤3 lignes =
// hauteur identique à l'original, plus de lignes = légèrement compressé).
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/../../pdf/verif_recu_lib.php';

// Accès public via le QR code du reçu (jeton "vh", voir verif_recu.php) —
// même mécanisme que les bulletins (pdf/bulletin_annuel.php).
$acces_public = false;
if (($_GET['vh'] ?? '') !== '' && (int) ($_GET['eleve'] ?? 0) > 0) {
    $val_annee_pub = get_annee_active()['val_annee'] ?? '';
    $acces_public = recu_verif_valider((int) $_GET['eleve'], $val_annee_pub, (string) $_GET['vh']) !== null;
}
if (!$acces_public) exiger_role(['DIRECTEUR', 'SECRETAIRE', 'COMPTABLE']);

require_once __DIR__ . '/../../pdf/fpdf.php';
require_once __DIR__ . '/../../pdf/header_pdf.php';

$id_eleve  = (int) ($_GET['eleve'] ?? 0);
$id_classe = (int) ($_GET['classe'] ?? 0);
$dl        = ($_GET['dl'] ?? '0') === '1';
$eleve     = db_one("SELECT * FROM eleve WHERE id_eleve=?", [$id_eleve]);
$classe    = db_one("SELECT * FROM classe WHERE IDClasses=?", [$id_classe]);
if (!$eleve || !$classe) die('Élève ou classe introuvable.');

$annee     = get_annee_active();
$val_annee = $annee['val_annee'] ?? '';

// Cas social (migration_v39) : réduction appliquée au montant total dû —
// voir eleve_pourcentage_reduction() (fonctions.php), même règle que
// pages/finances/versement.php.
$pourcentage_reduction = eleve_pourcentage_reduction($id_eleve);
$total_du_normal = (float) db_val("SELECT COALESCE(SUM(montant_obligation),0) FROM obligation WHERE niveau_obligation=?", [$classe['Niveau']]);
$total_du = $pourcentage_reduction > 0 ? round($total_du_normal * (1 - $pourcentage_reduction / 100), 2) : $total_du_normal;
$versements = db_all(
    "SELECT id_pay, id_versement, montant_paiement, date_paiement FROM paiement_frais WHERE id_eleve=? AND val_annee=? ORDER BY date_paiement, id_pay",
    [$id_eleve, $val_annee]
);
if (!$versements) die('Aucun versement enregistré pour cet élève.');
$total_paye = array_sum(array_column($versements, 'montant_paiement'));
$reste      = max(0.0, $total_du - $total_paye);

$numero_recu = finances_numero_recu_eleve($id_eleve, $val_annee);
$qr_chemin   = recu_qr_fichier_temp($id_eleve, $val_annee, $numero_recu);

$etab_brut = get_etablissement();
$sexe_lettre = stripos($eleve['Sexe_elv'] ?? '', 'F') === 0 ? 'F' : 'M';
$naiss = trim(date_fr($eleve['Date_naiss_elv'] ?: null) . ($eleve['Date_naiss_elv'] ? '' : '//') . '  à  ' . ($eleve['Lieu_naiss_elv'] ?? ''));

// ── Génération ─────────────────────────────────────────────────────
// Enveloppée dans un try/catch : accessible publiquement (scan du QR, voir
// $acces_public plus haut) — un incident technique (ex. fichier image mis
// en cache corrompu/incomplet, déjà rencontré : FPDF "Unexpected end of
// stream") ne doit JAMAIS renvoyer un fatal error brut à un visiteur
// anonyme. Message d'accueil convivial à la place, avec un rappel réseau
// utile (cause la plus fréquente d'échec de scan — voir hote_verif_reseau(),
// fonctions.php) ; le détail technique n'est montré qu'au personnel connecté.
try {
$pdf = new FPDF('P', 'mm', 'A4');
$pdf->SetAutoPageBreak(false);
$pdf->AddPage();
$BLEU = [106, 181, 255];

// Dessine UNE copie complète (identique aux 2 autres, juste décalée de
// $offY) — voir l'en-tête du fichier pour le détail de l'extraction.
$dessiner_copie = function (float $offY, string $etiquette) use (
    $pdf, $etab_brut, $eleve, $classe, $versements, $numero_recu, $val_annee,
    $total_du, $total_paye, $reste, $qr_chemin, $sexe_lettre, $naiss, $BLEU,
    $pourcentage_reduction
): void {
    $u = fn(string $s) => pdf_u($s);

    // Écrit $texte à ($x,$y) en réduisant la police (jusqu'à 5.5pt) si besoin
    // pour qu'il tienne dans $largeurMax — sans quoi un nom d'établissement
    // long (ex. « GROUPE SCOLAIRE BILINGUE ISLAMIQUE D'EXCELLENCE DE GADA
    // MABANGA ») déborde sur la colonne anglaise ou hors de la page (retour
    // utilisateur du 12/09/2026). Taille identique à l'original tant que le
    // texte tient déjà — aucun changement visuel pour les noms courts.
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

    // Filigrane (logo très éclairci en fond, réutilise le cache existant —
    // même fichier que partout ailleurs dans le projet) + logo net en en-tête.
    $etab_pdf = etab_pour_pdf($etab_brut);
    pdf_filigrane($pdf, $etab_pdf, 210, 297, 66, 72, 29 + $offY);
    $logo_path = !empty($etab_brut['logo']) ? __DIR__ . '/../../assets/uploads/' . $etab_brut['logo'] : '';
    if ($logo_path && is_file($logo_path)) {
        $pdf->Image($logo_path, 95, 4.3 + $offY, 20, 18);
    }

    // En-tête bilingue (3 lignes FR gauche / EN droite) — police posée par
    // $texteAjuste() pour chaque ligne (rétrécit si le texte est trop long).
    $pdf->SetTextColor(0);
    // pays_etab_fr (pas republique_fr, colonne supprimée de `etablissement`
    // — voir pdf/bulletin_trimestriel_arabe.php pour le même remplacement)
    // contient déjà le texte complet "RÉPUBLIQUE DU CAMEROUN".
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
    // Au-delà, la zone de données peut s'étendre jusqu'à 13.5mm avant de
    // devoir compresser — plafonné pour garder au moins 2mm de marge avant
    // le QR (82mm) MÊME en tenant compte de la sous-ligne EN du résumé
    // (RESTE À PAYER + 2.3mm, voir plus bas) qui descend avec le tableau.
    // La police ne rétrécit QUE si la hauteur de ligne devient trop petite
    // pour 9pt (sinon le texte se chevauche, vu en test réel à 6 versements).
    $rowH   = $n > 3 ? min(3.5, 13.5 / $n) : 3.5;
    $rowFont = $rowH >= 3.2 ? 9 : max(4.5, $rowH * 2.6);
    $totalH = 3.5;
    // Colonne N° ajoutée (demande explicite du 12/09/2026 : un même
    // versement réparti sur plusieurs frais doit afficher le même numéro
    // de reçu sur CHAQUE ligne concernée — voir finances_id_versement()).
    // Largeur totale inchangée (69mm, x0=12 → x4=81) pour ne pas empiéter
    // sur le bloc résumé qui démarre à x=85.
    $x0 = 12; $wNo = 19; $wFrais = 13; $wMontant = 18; $wDate = 19;
    $x1 = $x0 + $wNo; $x2 = $x1 + $wFrais; $x3 = $x2 + $wMontant; $x4 = $x3 + $wDate;
    $noFont = 8;

    $pdf->SetDrawColor(0);
    $pdf->SetLineWidth(0.3);
    $pdf->SetFillColor($BLEU[0], $BLEU[1], $BLEU[2]);
    $pdf->Rect($x0, $tableTop, $wNo, $headerH, 'B');
    $pdf->Rect($x1, $tableTop, $wFrais, $headerH + $n * $rowH, 'B'); // FRAIS (fusionnée sur toute la hauteur)
    $pdf->Rect($x2, $tableTop, $wMontant, $headerH, 'B');
    $pdf->Rect($x3, $tableTop, $wDate, $headerH, 'B');
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Text($x0 + ($wNo - $pdf->GetStringWidth('N°')) / 2, $tableTop + $headerH - 1.05, $u('N°'));
    $pdf->Text($x1 + ($wFrais - $pdf->GetStringWidth('FRAIS')) / 2, $tableTop + $headerH + $n * $rowH / 2 + 0.6, $u('FRAIS'));
    $pdf->Text($x2 + ($wMontant - $pdf->GetStringWidth('MONTANT')) / 2, $tableTop + $headerH - 1.05, $u('MONTANT'));
    $pdf->Text($x3 + ($wDate - $pdf->GetStringWidth('DATE')) / 2, $tableTop + $headerH - 1.05, $u('DATE'));

    $pdf->SetFont('Arial', '', $rowFont);
    $yBaselineOffset = min(0.5, $rowH * 0.16);
    $y = $tableTop + $headerH;
    foreach ($versements as $v) {
        $y += $rowH;
        $numero_txt = finances_numero_recu(finances_id_versement($v));
        $pdf->SetFont('Arial', '', $noFont);
        $pdf->Text($x0 + ($wNo - $pdf->GetStringWidth($numero_txt)) / 2, $y - $yBaselineOffset, $numero_txt);
        $pdf->SetFont('Arial', '', $rowFont);
        $montant_txt = number_format((float) $v['montant_paiement'], 0, ',', ' ');
        $pdf->Text($x2 + ($wMontant - $pdf->GetStringWidth($montant_txt)) / 2, $y - $yBaselineOffset, $montant_txt);
        $date_txt = $u(date_fr($v['date_paiement']));
        $pdf->Text($x3 + ($wDate - $pdf->GetStringWidth($date_txt)) / 2, $y - $yBaselineOffset, $date_txt);
        // Ligne de séparation par ligne : sous N° (x0-x1) et sous
        // MONTANT+DATE (x2-x4) — PAS sous FRAIS (x1-x2), colonne fusionnée
        // sur toute la hauteur, sans séparateur interne.
        $pdf->Line($x0, $y, $x1, $y);
        $pdf->Line($x2, $y, $x4, $y);
    }
    $pdf->Line($x0, $tableTop + $headerH, $x0, $y);
    $pdf->Line($x3, $tableTop + $headerH, $x3, $y);
    $pdf->Line($x4, $tableTop + $headerH, $x4, $y);

    $pdf->SetFillColor($BLEU[0], $BLEU[1], $BLEU[2]);
    $pdf->Rect($x0, $y, $wNo, $totalH, 'B');
    $pdf->Rect($x1, $y, $wFrais, $totalH, 'B');
    $pdf->Rect($x2, $y, $wMontant, $totalH, 'B');
    $pdf->Rect($x3, $y, $wDate, $totalH, 'B');
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Text($x1 + ($wFrais - $pdf->GetStringWidth('TOTAL')) / 2, $y + $totalH - 0.6, $u('TOTAL'));
    $total_txt = number_format($total_paye, 0, ',', ' ');
    $pdf->Text($x2 + ($wMontant - $pdf->GetStringWidth($total_txt)) / 2, $y + $totalH - 0.6, $total_txt);

    // ── Résumé (Total dû / Payé / Reste) — position FIXE (mêmes 3 lignes
    //    espacées de 7mm que le modèle de référence, 60.5/67.5/74.5),
    //    INDÉPENDANTE du nombre de lignes du tableau. Un essai précédent
    //    calait $yPaye/$yReste sur $dataBottom (milieu/bas du tableau) —
    //    avec peu de versements (tableau court), les 3 lignes se
    //    retrouvaient trop rapprochées et la sous-ligne anglaise de l'une
    //    se confondait avec le début de la suivante (retour utilisateur).
    //    Le tableau (à gauche, x≤81) et le résumé (à droite, x≥85) ne se
    //    chevauchent jamais horizontalement — aucune raison de les lier.
    $yTot = $tableTop + $headerH;
    $yPaye = $yTot + 7.0;
    $yReste = $yTot + 14.0;
    $pdf->SetFont('Arial', 'B', 9.5);
    $pdf->Text(85, $yTot, $u('MONTANT TOTAL  : ' . number_format($total_du, 0, ',', ' ') . '  FCFA'));
    $pdf->Text(85, $yPaye, $u('MONTANT PAYÉ    : ' . number_format($total_paye, 0, ',', ' ') . '  FCFA'));
    $pdf->Text(85, $yReste, $u('RESTE À PAYER    : ' . number_format($reste, 0, ',', ' ') . '  FCFA'));
    // Sous-ligne EN toujours +2.3mm sous sa ligne FR (fixe, indépendant de la
    // hauteur des lignes du tableau — même écart que le modèle de référence
    // entre "MONTANT TOTAL"(60.5) et "Total amount"(62.8)).
    $pdf->SetFont('Arial', 'I', 8.2);
    $pdf->Text(85, $yTot + 2.3, $u('Total amount'));
    $pdf->Text(85, $yPaye + 2.3, $u('Amount already pay'));
    $pdf->Text(85, $yReste + 2.3, $u('Balance'));
    // Cas social (migration_v39) : mention discrète pour ne pas laisser le
    // parent penser à une erreur devant un total inférieur aux frais publiés.
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

$nom_fichier = 'recu_' . str_replace('/', '-', $numero_recu) . '_' . $eleve['Mat_elv'];
$pdf->Output($dl ? 'D' : 'I', $nom_fichier . '.pdf');
} catch (Throwable $e) {
    pdf_erreur_generation($e);
}
