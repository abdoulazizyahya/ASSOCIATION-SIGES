<?php
/**
 * FICHE DE PRESENCE — grille hebdomadaire vierge (une ligne par élève, une
 * case par heure de cours LUNDI..VENDREDI) à imprimer et remplir à la main
 * en salle de classe. Modèle fourni par l'utilisateur (PDF joint, paysage
 * A4) : semaine "SEMAINE DU .......... AU .........." laissée en blanc
 * (aucune saisie en base — c'est une fiche VIERGE), MERCREDI limité à 5
 * créneaux (T1-T5) contre 9 pour les autres jours (demi-journée).
 * GET : classe (0 = toutes les classes accessibles), dl (0|1),
 *       vh+va = accès public via le QR de vérification (voir plus bas).
 */
require_once __DIR__ . '/../../../../config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
require_once __DIR__ . '/../../pdf/verif_fiche_presence_lib.php';

// Accès public via le QR imprimé sur la fiche (jeton "vh" = hash de
// vérification déjà calculé pour CETTE classe et CETTE année précises, voir
// secondaire/commun/verif/document_classe.php) : la personne qui scanne (parent, surveillant...)
// n'a pas forcément de compte dans le système. En dehors de ce cas, connexion
// + contrôle de rôle normal exigés (même règle que secondaire/commun/pages/discipline/index.php).
$vh_verif      = (string)($_GET['vh'] ?? '');
$va_verif      = (int)($_GET['va'] ?? 0);
$id_classe_pub = (int)($_GET['classe'] ?? 0);
$acces_public  = $vh_verif !== '' && $id_classe_pub > 0 && $va_verif > 0
    && hash_equals(fiche_presence_verif_hash($id_classe_pub, $va_verif), $vh_verif);

if ($acces_public) {
    $id_annee  = $va_verif;
    $val_annee = (string) db_val("SELECT libelle FROM annee_scolaire WHERE id=?", [$id_annee]);
    $classe_unique = db_one("SELECT * FROM classe WHERE id=?", [$id_classe_pub]);
    if (!$classe_unique) die('Classe introuvable.');
    $classes_a_generer = [$classe_unique];
    $id_classe_demande = $id_classe_pub;
} else {
    exiger_connexion();
    $role      = role_connecte();
    $annee_act = get_annee_active();
    $id_annee  = (int)($annee_act['id'] ?? 0);
    $val_annee = $annee_act['libelle'] ?? '';

    // ── Accès : même règle que secondaire/commun/pages/discipline/index.php ───────────────
    $full_access = (in_array($role, ['ADMIN', 'CENSEUR', 'PROVISEUR', 'FONDATEUR'], true) || $role === 'MEMBRE_ASSOCIATION');
    $classes_access = [];
    if ($full_access) {
        $classes_access = db_all("SELECT * FROM classe WHERE archivee=0 ORDER BY ordre, designation");
    } elseif ($role === 'SG') {
        $mat_ens = get_matricule_ens_connecte();
        $classes_access = db_all(
            "SELECT c.* FROM sg JOIN classe c ON c.id=sg.IDClasses
             WHERE sg.matricule_ens=? AND sg.val_annee=? AND c.archivee=0",
            [$mat_ens, $val_annee]
        );
    } elseif ($role === 'ENSEIGNANT') {
        $mat_ens = get_matricule_ens_connecte();
        $classes_access = db_all(
            "SELECT c.* FROM enseignat_principal ep JOIN classe c ON c.id=ep.IDClasses
             WHERE ep.matricule_ens=? AND ep.val_annee=? AND c.archivee=0",
            [$mat_ens, $val_annee]
        );
    }
    $ids_ok = array_column($classes_access, 'id');
    if (empty($ids_ok)) die('Acces non autorise.');

    $id_classe_demande = (int)($_GET['classe'] ?? 0);
    if ($id_classe_demande > 0) {
        $classes_a_generer = array_values(array_filter($classes_access, fn($c) => (int)$c['id'] === $id_classe_demande));
        if (empty($classes_a_generer)) die('Acces refuse a cette classe.');
    } else {
        $classes_a_generer = $classes_access; // toutes les classes accessibles
    }
}

$etab = get_etablissement();
$dl   = ($_GET['dl'] ?? '0') === '1';

require_once __DIR__ . '/../../pdf/fpdf.php';
require_once __DIR__ . '/../../pdf/header_pdf.php';
require_once __DIR__ . '/../../pdf/qrcode.php';

// ── Découpage des colonnes (identique pour chaque page) ─────────────────
$jours = ['LUNDI' => 9, 'MARDI' => 9, 'MERCREDI' => 5, 'JEUDI' => 9, 'VENDREDI' => 9];
$nb_sub = array_sum($jours); // 41

function dessiner_entete_fiche(FPDF $pdf, array $etab, array $classe, string $val_annee, float $ml, float $uw, array $jours, float $w_no, float $w_nom, float $w_total, float $w_sub, float $h_hdr1, float $h_hdr2): void {
    pdf_entete($pdf, $etab, $pdf->GetPageWidth(), $ml);

    $pdf->SetFont('Arial', 'B', 15);
    $pdf->Cell($uw, 6.5, pdf_u('FICHE DE PRESENCE'), 0, 1, 'C');
    $pdf->SetFont('Arial', 'I', 9);
    $pdf->Cell($uw, 5, pdf_u('ATTENDANCE RECORD'), 0, 1, 'C');
    $pdf->Ln(1);

    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell($uw / 2, 5, pdf_u('Annee scolaire: ' . $val_annee), 0, 0, 'L');
    $pdf->Cell($uw / 2, 5, pdf_u('Classe : ' . ($classe['designation'] ?? '')), 0, 1, 'R');
    $pdf->Ln(1);

    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell($uw, 6, pdf_u('SEMAINE DU ' . str_repeat('.', 30) . ' AU ' . str_repeat('.', 30)), 0, 1, 'C');
    $pdf->Ln(0.5);

    // ── En-tête du tableau (2 lignes) ────────────────────────────────────
    $y_hdr = $pdf->GetY();
    $h_hdr = $h_hdr1 + $h_hdr2;
    $pdf->SetFillColor(179, 224, 255);
    $pdf->SetTextColor(15, 45, 90);
    $pdf->SetDrawColor(0, 0, 0);

    $x = $ml;
    foreach ([['N°', $w_no], ['NOMS ET PRENOMS', $w_nom]] as [$lbl, $w]) {
        $pdf->SetXY($x, $y_hdr);
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell($w, $h_hdr, pdf_u($lbl), 1, 0, 'C', true);
        $x += $w;
    }
    foreach ($jours as $jour => $nb) {
        $w_jour = $w_sub * $nb;
        $pdf->SetXY($x, $y_hdr);
        $pdf->SetFont('Arial', 'B', 7.5);
        $pdf->Cell($w_jour, $h_hdr1, pdf_u($jour), 1, 0, 'C', true);
        for ($t = 1; $t <= $nb; $t++) {
            $pdf->SetXY($x + ($t - 1) * $w_sub, $y_hdr + $h_hdr1);
            $pdf->SetFont('Arial', 'B', 6);
            $pdf->Cell($w_sub, $h_hdr2, 'T' . $t, 1, 0, 'C', true);
        }
        $x += $w_jour;
    }
    $pdf->SetXY($x, $y_hdr);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell($w_total, $h_hdr, pdf_u('TOTAL'), 1, 0, 'C', true);

    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetXY($ml, $y_hdr + $h_hdr);
}

$pdf = new FPDF('L', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->SetMargins(8, 8, 8);
$pdf->SetAutoPageBreak(false);
$pw = $pdf->GetPageWidth();
$ph = $pdf->GetPageHeight();
$ml = 8;
$uw = $pw - 2 * $ml;

$w_no = 8; $w_nom = 45; $w_total = 12;
$w_sub = ($uw - $w_no - $w_nom - $w_total) / $nb_sub;
$h_hdr1 = 6; $h_hdr2 = 6;
$h_row  = 6;

foreach ($classes_a_generer as $classe) {
    $eleves = db_all(
        "SELECT e.id, e.nom, e.prenom FROM eleve e
         JOIN inscription i ON i.id_eleve=e.id AND i.id_classe=? AND i.id_annee=?
         WHERE e.statut='actif' ORDER BY e.nom, e.prenom",
        [$classe['id'], $id_annee]
    );

    $pdf->AddPage();
    pdf_filigrane($pdf, $etab, $pw, $ph);
    dessiner_entete_fiche($pdf, $etab, $classe, $val_annee, $ml, $uw, $jours, $w_no, $w_nom, $w_total, $w_sub, $h_hdr1, $h_hdr2);

    $n = 1;
    foreach ($eleves as $el) {
        // Marge basse agrandie (26mm au lieu de 16) : la dernière page de
        // chaque classe se termine par un pied de page avec QR de
        // vérification (16mm) au lieu du simple filet copyright — voir
        // pdf_pied_copyright_qr() plus bas.
        if ($pdf->GetY() + $h_row > $ph - 26) {
            pdf_pied_copyright($pdf, $val_annee, $pw, $ph, $ml);
            $pdf->AddPage();
            pdf_filigrane($pdf, $etab, $pw, $ph);
            dessiner_entete_fiche($pdf, $etab, $classe, $val_annee, $ml, $uw, $jours, $w_no, $w_nom, $w_total, $w_sub, $h_hdr1, $h_hdr2);
        }
        $x = $ml; $y = $pdf->GetY();
        $pdf->SetFont('Arial', '', 7.5);
        $pdf->SetXY($x, $y);
        $pdf->Cell($w_no, $h_row, (string)$n, 1, 0, 'C');
        $x += $w_no;
        $pdf->SetXY($x, $y);
        $pdf->Cell($w_nom, $h_row, pdf_u(strtoupper($el['nom']) . ' ' . ($el['prenom'] ?? '')), 1, 0, 'L');
        $x += $w_nom;
        for ($i = 0; $i < $nb_sub; $i++) {
            $pdf->SetXY($x, $y);
            $pdf->Cell($w_sub, $h_row, '', 1, 0, 'C');
            $x += $w_sub;
        }
        $pdf->SetXY($x, $y);
        $pdf->Cell($w_total, $h_row, '', 1, 1, 'C');
        $n++;
    }
    if (empty($eleves)) {
        $pdf->SetX($ml);
        $pdf->Cell($uw, 8, pdf_u('Aucun eleve inscrit.'), 1, 1, 'C');
    }

    // ── QR de vérification d'authenticité (même principe que les bulletins/
    // attestations) : un tampon PNG temporaire, incrusté puis supprimé. ───
    $qr_tmp = tempnam(sys_get_temp_dir(), 'abzqrfp_') . '.png';
    try {
        $verif_url = fiche_presence_verif_url((int)$classe['id'], $id_annee);
        $qr_gen = new QRCode($verif_url, ['s' => 'qr-h']); // qr-h = correction d'erreur elevee (impression papier)
        $qr_img = $qr_gen->render_image();
        imagepng($qr_img, $qr_tmp);
        imagedestroy($qr_img);
        pdf_pied_copyright_qr($pdf, $val_annee, $pw, $ph, $ml, $qr_tmp, 16);
    } finally {
        if (is_file($qr_tmp)) unlink($qr_tmp);
    }
}

$mode   = $dl ? 'D' : 'I';
$suffix = $id_classe_demande ? preg_replace('/\W+/', '_', $classes_a_generer[0]['designation'] ?? 'classe') : 'toutes_classes';
$pdf->Output($mode, 'fiche_presence_' . $suffix . '.pdf');
