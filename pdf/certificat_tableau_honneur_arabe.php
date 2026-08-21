<?php
// ── PDF : Certificat de Tableau d'honneur — piste arabe ───────────────
// GET : classe, (trim OU vue=annee), eleve (optionnel), modele (1|2, défaut
// 1), dl (0|1).
//
// Modèle 1 « Classique » : miroir FPDF/Latin de la piste française (existant,
// inchangé) — pas de rendu arabe réel, juste les données de la piste arabe.
// Modèle 2 « Orné » (nouveau) : bilingue FR/AR réel (TCPDF + police RTL
// aealarabiya, mêmes helpers que les bulletins arabes —
// pdf/header_pdf_tcpdf.php), inspiré du modèle fourni TH_ara_ann.pdf pour le
// mode annuel ; le mode TRIMESTRE arabe n'avait PAS de modèle fourni — conçu
// ici en miroir direct de l'annuel (même structure, seul le libellé de
// période change), terminologie « الفصل » réutilisée telle quelle des
// bulletins arabes (pdf/bulletin_trimestriel_arabe.php) plutôt que « الربع »
// du PDF de référence annuel, pour rester cohérent avec le reste du
// vocabulaire arabe déjà établi dans ce projet.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
require_once __DIR__ . '/../notes_apc_arabe.php';
require_once __DIR__ . '/verif_lib.php';
require_once __DIR__ . '/verif_honneur_lib.php';

$acces_public = false;
if (($_GET['vh'] ?? '') !== '' && (int) ($_GET['eleve'] ?? 0) > 0) {
    $vue_pub    = (($_GET['vue'] ?? '') === 'annee') ? 'annee' : 'trim';
    $val_annee_pub = get_annee_active()['val_annee'] ?? '';
    $periode_pub = $vue_pub === 'annee' ? (int) substr($val_annee_pub, 0, 4) : (int) ($_GET['trim'] ?? 0);
    $acces_public = honneur_verif_valider((int) $_GET['eleve'], $vue_pub, $periode_pub, 'ar', (string) $_GET['vh']) !== null;
}
if (!$acces_public) exiger_connexion();

$id_classe       = (int) ($_GET['classe'] ?? 0);
$vue             = (($_GET['vue'] ?? '') === 'annee') ? 'annee' : 'trim';
$id_trim         = (int) ($_GET['trim'] ?? 0);
$id_eleve_filtre = (int) ($_GET['eleve'] ?? 0);
$modele          = in_array((int) ($_GET['modele'] ?? 1), [1, 2], true) ? (int) ($_GET['modele'] ?? 1) : 1;
$dl              = ($_GET['dl'] ?? '0') === '1';
if (!$id_classe) die('Paramètre classe manquant.');
if ($vue === 'trim' && !$id_trim) die('Paramètre trim manquant.');

require_once __DIR__ . '/header_pdf.php';
if ($modele === 2) {
    require_once __DIR__ . '/../pdf/tcpdf/config/tcpdf_config.php';
    require_once __DIR__ . '/../pdf/tcpdf/tcpdf.php';
    require_once __DIR__ . '/header_pdf_tcpdf.php';
} else {
    require_once __DIR__ . '/fpdf.php';
}

$classe = db_one("SELECT * FROM classe WHERE IDClasses=?", [$id_classe]);
if (!$classe) die('Classe introuvable.');
$trimestre = null;
if ($vue === 'trim') {
    $trimestre = db_one("SELECT * FROM trimestre WHERE id_trim=?", [$id_trim]);
    if (!$trimestre) die('Trimestre introuvable.');
}

$annee     = get_annee_active();
$val_annee = $annee['val_annee'] ?? '';
$etab_brut = get_etablissement();
$etab      = etab_pour_pdf($etab_brut);
// En-tête bilingue FR/AR absorbé dans `etablissement` (migration v31,
// ex-table etablissement_arabe) — déjà présent dans $etab_brut.
$etab_ar   = $etab_brut;

// Libellé arabe du trimestre — même convention que les bulletins arabes.
$trim_ar_lib = match ($id_trim) { 2 => 'الفصل الثاني', 3 => 'الفصل الثالث', default => 'الفصل الأول' };

if ($vue === 'annee') {
    $classement = classement_annuel_classe_arabe($id_classe, $val_annee);
    $periode_fr = "l'année scolaire " . $val_annee;
    $periode_m2 = "de l'année scolaire " . $val_annee;
    $periode_ar = 'العام الدراسي ' . $val_annee;
} else {
    $classement = classement_trimestre_classe_arabe($id_classe, $id_trim, $val_annee);
    $periode_fr = 'le ' . $trimestre['libelle_trim'] . ' de l\'année scolaire ' . $val_annee;
    $periode_m2 = 'du ' . $trimestre['libelle_trim'] . ' de l\'année scolaire ' . $val_annee;
    $periode_ar = $trim_ar_lib . ' من العام الدراسي ' . $val_annee;
}
$qualifies = [];
foreach ($classement['lignes'] as $l) {
    if ($l['moy'] === null) continue;
    $jours = $vue === 'annee'
        ? jours_absence_non_justifiees_annuel((int) $l['id_eleve'], $id_classe, $val_annee)
        : jours_absence_non_justifiees_trimestre((int) $l['id_eleve'], $id_classe, $id_trim, $val_annee);
    $m = mention_travail((float) $l['moy'], $jours);
    if (!$m['tableau_honneur']) continue;
    if ($id_eleve_filtre && (int) $l['id_eleve'] !== $id_eleve_filtre) continue;
    $qualifies[] = ['ligne' => $l, 'mention' => $m];
}
if ($id_eleve_filtre && empty($qualifies)) die("Cet élève n'est pas au tableau d'honneur pour cette période.");
if (empty($qualifies)) die("Aucun élève au tableau d'honneur pour cette classe et cette période.");

$pw = 297; $ph = 210;

// Coche/case vide — même icônes que les bulletins.
function pdf_case_th_ar($pdf, bool $coche, float $x, float $y, float $taille = 4.5): void {
    $fichier = $coche ? 'case_cochee.jpg' : 'case_a_cocher.jpg';
    $pdf->Image(__DIR__ . '/../assets/img/pdf/' . $fichier, $x, $y, $taille, $taille);
}

// Enveloppé dans un try/catch : accessible publiquement via le QR du
// certificat — voir fonctions.php::pdf_erreur_generation() (couvre les 2
// moteurs, FPDF modèle 1 et TCPDF modèle 2 — K_TCPDF_THROW_EXCEPTION_ERROR
// mis à true dans pdf/tcpdf/config/tcpdf_config.php pour que TCPDF lève
// bien une Exception plutôt que d'appeler die() directement).
try {
if ($modele === 2) {
    // ══════════════════════════ MODÈLE 2 — ORNÉ, BILINGUE ══════════════════
    $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(0, 0, 0);
    $pdf->SetAutoPageBreak(false, 0);

    foreach ($qualifies as $q) {
        $l = $q['ligne']; $m = $q['mention'];
        $pdf->AddPage();
        tcpdf_filigrane($pdf, $etab, $pw, $ph);

        // Bordure — reconstruction VECTORIELLE fidèle du PDF fourni
        // (TH_ara_ann.pdf, flux de contenu PDF décompressé : ce fichier
        // dessine sa bordure en traits pointillés superposés, contrairement
        // à TH_fr_trim.pdf qui utilise une image — chaque piste a donc sa
        // propre reconstruction, la plus fidèle à SA source). 3 couches
        // concentriques de rectangles arrondis, coordonnées/couleurs/
        // épaisseurs/pointillés extraites du flux (unités converties en mm) :
        // extérieur turquoise pointillé épais, milieu noir fin continu,
        // intérieur turquoise pointillé plus fin.
        $pdf->SetDrawColor(0, 152, 217);
        $pdf->SetLineStyle(['width' => 1.10, 'dash' => '1.06,1.06']);
        $pdf->RoundedRect(7, 7, 283, 196, 5.3, '1111', 'D');
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetLineStyle(['width' => 0.30, 'dash' => 0]);
        $pdf->RoundedRect(8, 8, 281, 194, 5.0, '1111', 'D');
        $pdf->SetDrawColor(0, 152, 217);
        $pdf->SetLineStyle(['width' => 0.70, 'dash' => '0.57,0.57']);
        $pdf->RoundedRect(9, 9, 279, 192, 4.7, '1111', 'D');
        $pdf->SetDrawColor(0); $pdf->SetLineStyle(['width' => 0.2, 'dash' => 0]);

        // En-tête bilingue (République/Ministère/Délégations/École) —
        // fonctions déjà éprouvées par les bulletins arabes.
        tcpdf_entete($pdf, $etab, $etab_ar, $pw, 12);

        $pdf->SetFont('helvetica', 'B', 8.5);
        $pdf->SetXY(14, 44, true);
        $pdf->Cell(60, 5, pdf_u_th('Année scolaire: ' . $val_annee), 0, 0, 'L');
        $pdf->SetRTL(true, false);
        $pdf->SetFont('amirib', '', 9);
        // Largeur/position resserrées pour ne pas chevaucher le cadre photo
        // (bord gauche à $pw-14-26) juste à droite.
        $pdf->SetXY($pw - 14 - 26 - 4, 44, true);
        $pdf->Cell(30, 5, 'العام الدراسي: ' . $val_annee, 0, 0, 'R');
        $pdf->SetRTL(false, false);

        // Bannière "TABLEAU D'HONNEUR / لوحة الشرف" — image RÉELLE unique
        // extraite du PDF fourni (fond turquoise + calligraphie arabe
        // dégradée + "TABLEU D'HONNEUR" en rouge déjà inclus dans l'image —
        // orthographe telle quelle dans l'asset d'origine, reproduite à
        // l'identique), remplace l'ancienne reconstruction à 2 pilules.
        $y_ban = 44;
        $h_ban = 36;
        $pdf->Image(__DIR__ . '/../assets/img/pdf/tableau_honneur/banniere_arabe.png', $pw / 2 - 60, $y_ban, 120, $h_ban);

        // Photo élève, cadre en haut à droite.
        $eleve_full = db_one("SELECT * FROM eleve WHERE id_eleve=?", [(int) $l['id_eleve']]);
        $pdf->SetLineStyle(['width' => 0.5, 'color' => [30, 79, 179]]);
        $pdf->Rect($pw - 14 - 26, $y_ban - 2, 26, 30, 'D');
        $photo_tmp = $eleve_full ? photo_eleve_fichier_temp($eleve_full['Photo_elv'] ?? null, (int) $l['id_eleve']) : null;
        if ($photo_tmp) {
            $pdf->Image($photo_tmp, $pw - 14 - 25, $y_ban - 1, 24, 28);
            @unlink($photo_tmp);
        } else {
            $avatar = (stripos($eleve_full['Sexe_elv'] ?? '', 'F') === 0) ? 'fille.png' : 'garcon.png';
            $ap = __DIR__ . '/../assets/img/avatars/' . $avatar;
            if (is_file($ap)) $pdf->Image($ap, $pw - 14 - 25, $y_ban - 1, 24, 28);
        }

        // Cases Encouragement/تشجيع — Félicitations/تهانينا.
        $y_case = $y_ban + $h_ban + 4;
        pdf_case_th_ar($pdf, (bool) $m['encouragement'], 14, $y_case, 4.5);
        $pdf->SetFont('helvetica', 'B', 10.5); $pdf->SetTextColor(30, 79, 179);
        $pdf->SetXY(20, $y_case, true); $pdf->Cell(30, 5, 'ENCOURAGEMENT', 0, 0, 'L');
        $pdf->SetRTL(true, false); $pdf->SetFont('amirib', '', 9);
        $pdf->SetXY(80, $y_case, true); $pdf->Cell(30, 5, 'تشجيع', 0, 0, 'R');
        $pdf->SetRTL(false, false); $pdf->SetTextColor(0);

        pdf_case_th_ar($pdf, (bool) $m['felicitations'], 105, $y_case, 4.5);
        $pdf->SetFont('helvetica', 'B', 10.5); $pdf->SetTextColor(200, 20, 20);
        $pdf->SetXY(111, $y_case, true); $pdf->Cell(30, 5, 'FELICITATIONS', 0, 0, 'L');
        $pdf->SetRTL(true, false); $pdf->SetFont('amirib', '', 9);
        $pdf->SetXY(171, $y_case, true); $pdf->Cell(30, 5, 'تهانينا', 0, 0, 'R');
        $pdf->SetRTL(false, false); $pdf->SetTextColor(0);

        $pdf->SetFont('helvetica', 'BI', 9.5); $pdf->SetTextColor(20, 130, 60);
        $pdf->SetXY(14, $y_case + 8, true);
        $pdf->Cell($pw - 28, 5, 'POUR SON TRAVAIL TRES SATISFAISANT ET SA CONDUITE EXEMPLAIRE', 0, 1, 'C');
        $pdf->SetTextColor(0);

        $nom_complet = mb_strtoupper($l['Nom_elv']) . ' ' . ($l['Prenom_elv'] ?? '');
        $y_txt = $y_case + 15;
        $w_col = ($pw - 28) / 2;

        // Paragraphe FR (gauche, LTR) — justifié pleine largeur de colonne
        // (demande explicite côté FR, appliquée ici aussi par cohérence).
        $pdf->SetFont('helvetica', '', 9.5);
        $pdf->SetXY(14, $y_txt, true);
        $pdf->MultiCell($w_col - 4, 5, pdf_u_th(
            "L'élève " . $nom_complet . ' de la classe de ' . $classe['DesignationClasses']
            . " est inscrit(e) sur décision du conseil de classe au TABLEAU D'HONNEUR pour le compte "
            . $periode_m2 . '.'
        ), 0, 'J');
        $pdf->SetX(14, true);
        $pdf->MultiCell($w_col - 4, 5, pdf_u_th('Cette reconnaissance prestigieuse de son MERITE est aussi et surtout un appel à l\'EXCELLENCE.'), 0, 'J');

        // Paragraphe AR (droite, RTL) — miroir du texte FR, justifié aussi.
        $pdf->SetRTL(true, false);
        $pdf->SetFont('amirib', '', 10);
        $pdf->SetXY($pw - 14, $y_txt, true);
        $pdf->MultiCell($w_col - 4, 5.5, 'يتم تسجيل الطالب (ة) ' . $nom_complet . ' من فصل ' . $classe['DesignationClasses']
            . ' بقرار من مجلس الفصل في قائمة الشرف نيابة عن ' . $periode_ar . '.', 0, 'J');
        $pdf->SetX($pw - 14, true);
        $pdf->MultiCell($w_col - 4, 5.5, 'إن هذا الاعتراف المرموق باستحقاق الفرد هو أيضًا وقبل كل شيء دعوة للتميز.', 0, 'J');
        $pdf->SetRTL(false, false);

        $y_res = max($pdf->GetY() + 6, $y_txt + 25);
        $lbl_moy_fr = $vue === 'annee' ? 'MOYENNE ANNUELLE' : 'MOYENNE';
        $lbl_moy_ar = $vue === 'annee' ? 'المعدل السنوي' : 'المعدل';

        $pdf->SetFont('helvetica', 'B', 9.5); $pdf->SetTextColor(20, 40, 120);
        $pdf->SetXY(14, $y_res, true); $pdf->Cell(40, 5, $lbl_moy_fr, 0, 0, 'L');
        $pdf->SetTextColor(0);
        $pdf->SetFillColor(120, 190, 250);
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->SetXY(14, $y_res + 5, true);
        $pdf->Cell(35, 8, number_format((float) $l['moy'], 2) . '/20', 1, 0, 'C', true);

        $pdf->SetFont('helvetica', 'B', 9.5); $pdf->SetTextColor(20, 40, 120);
        $pdf->SetXY(60, $y_res, true); $pdf->Cell(30, 5, 'RANG', 0, 0, 'L');
        $pdf->SetTextColor(0);
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->SetXY(60, $y_res + 5, true);
        $pdf->Cell(30, 8, $l['rang'] . '/' . $classement['nb_classes'], 1, 0, 'C', true);

        // Étiquettes arabes des mêmes encadrés, alignées à droite en miroir.
        $pdf->SetRTL(true, false);
        $pdf->SetFont('amirib', '', 9); $pdf->SetTextColor(20, 40, 120);
        $pdf->SetXY($pw - 14, $y_res, true); $pdf->Cell(40, 5, $lbl_moy_ar, 0, 0, 'R');
        $pdf->SetXY($pw - 54, $y_res, true); $pdf->Cell(30, 5, 'الترتيب', 0, 0, 'R');
        $pdf->SetRTL(false, false);
        $pdf->SetTextColor(0);
        $pdf->SetFillColor(120, 190, 250);
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->SetXY($pw - 14 - 35, $y_res + 5, true);
        $pdf->Cell(35, 8, number_format((float) $l['moy'], 2) . '/20', 1, 0, 'C', true);
        $pdf->SetXY($pw - 54 - 30, $y_res + 5, true);
        $pdf->Cell(30, 8, $l['rang'] . '/' . $classement['nb_classes'], 1, 0, 'C', true);

        // Lieu/date + signatures, centrés en bas du bloc résultat.
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetXY($pw / 2 - 40, $y_res + 16, true);
        $pdf->Cell(80, 5, pdf_u_th('Fait à ' . (($etab['lieu'] ?: $etab['ville']) ?: '') . ', le ' . date('d-m-Y') . '.'), 0, 1, 'C');
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetXY($pw / 2 - 55, $y_res + 22, true);
        $pdf->Cell(50, 5, "L'ENSEIGNANT(E),", 0, 0, 'L');
        $pdf->SetXY($pw / 2 + 5, $y_res + 22, true);
        $pdf->Cell(50, 5, pdf_u_th(mb_strtoupper($etab['chef_etablissement'] ?: 'LE DIRECTEUR') . ','), 0, 0, 'R');

        if (($_GET['signature'] ?? '0') === '1') {
            pdf_signature_appliquer_jn($pdf, 'certificat_tableau_honneur_arabe', 0, 0, $pw, $ph, [
                'x_pct' => ($pw / 2 + 5) / $pw * 100, 'y_pct' => ($y_res + 24) / $ph * 100,
                'w_pct' => 30 / $pw * 100, 'h_pct' => null,
            ]);
        }

        // QR agrandi (14→27mm, demande explicite, même taille que la piste
        // FR) — devise remontée d'autant pour ne pas être recouverte.
        $taille_qr = 27; $y_qr = $ph - 8 - 4 - $taille_qr; $y_devise = $y_qr - 6; $y_copyright = $ph - 8;
        $pdf->SetFont('helvetica', '', 8); $pdf->SetTextColor(200);
        $pdf->SetXY(0, $y_devise, true);
        $pdf->Cell($pw, 5, pdf_u_th($etab['devise'] ?? 'Travail - Efficacité - Excellence'), 0, 1, 'C');
        $pdf->SetTextColor(0);

        if ($eleve_full) {
            $id_periode_qr = $vue === 'annee' ? (int) substr($val_annee, 0, 4) : $id_trim;
            $qr_tmp = honneur_qr_fichier_temp($eleve_full, $vue, $id_periode_qr, 'ar');
            if ($qr_tmp) $pdf->Image($qr_tmp, $pw / 2 - $taille_qr / 2, $y_qr, $taille_qr, $taille_qr, 'PNG');
        }

        // Copyright standard du système (pdf/header_pdf_tcpdf.php) — texte
        // unique sur tous les PDF du projet, voir tcpdf_copyright(). Marge
        // basse ajustée pour garder la même position verticale qu'auparavant.
        tcpdf_copyright($pdf, $pw, $ph, $ph - $y_copyright);
    }
} else {
    // ══════════════════════════ MODÈLE 1 — CLASSIQUE (existant, FPDF) ══════
    $pdf = new FPDF('L', 'mm', 'A4');
    $pdf->SetAutoPageBreak(false);

    foreach ($qualifies as $q) {
        $l = $q['ligne']; $m = $q['mention'];
        $pdf->AddPage();
        pdf_filigrane($pdf, $etab, $pw, $ph, 160);

        $pdf->SetDrawColor(30, 79, 216); $pdf->SetLineWidth(1.2);
        $pdf->Rect(8, 8, $pw - 16, $ph - 16);
        $pdf->SetDrawColor(214, 175, 55); $pdf->SetLineWidth(0.4);
        $pdf->Rect(11, 11, $pw - 22, $ph - 22);
        $pdf->SetLineWidth(0.2); $pdf->SetDrawColor(0);

        pdf_entete($pdf, $etab, $pw, 16, 15, 0.85);
        $pdf->Ln(2);

        $y_titre = $pdf->GetY() + 2;
        $pdf->SetXY(0, $y_titre);
        $pdf->SetFont('Arial', 'B', 26);
        $pdf->SetTextColor(20, 40, 120);
        $pdf->Cell($pw, 12, pdf_u('CERTIFICAT DE TABLEAU D\'HONNEUR'), 0, 1, 'C');
        $pdf->SetFont('Arial', 'I', 11);
        $pdf->SetTextColor(120);
        $pdf->SetX(0);
        $pdf->Cell($pw, 6, 'HONOR ROLL CERTIFICATE - ARABIC TRACK', 0, 1, 'C');
        $pdf->SetTextColor(0);
        $pdf->Ln(6);

        $pdf->SetDrawColor(214, 175, 55); $pdf->SetLineWidth(0.6);
        $pdf->Line($pw / 2 - 30, $pdf->GetY(), $pw / 2 + 30, $pdf->GetY());
        $pdf->SetLineWidth(0.2); $pdf->SetDrawColor(0);
        $pdf->Ln(10);

        $uw = $pw - 60;
        $ml = 30;
        $nom_complet = mb_strtoupper($l['Nom_elv']) . ' ' . ($l['Prenom_elv'] ?? '');
        $pdf->SetFont('Arial', '', 11);
        $pdf->SetX($ml);
        $pdf->Cell($uw, 6, pdf_u("L'établissement " . ($etab['nom_fr'] ?: APP_NOM) . ' certifie que'), 0, 1, 'C');

        $pdf->SetFont('Arial', 'B', 16);
        $pdf->SetX($ml);
        $pdf->Cell($uw, 10, pdf_u($nom_complet), 0, 1, 'C');

        $pdf->SetFont('Arial', '', 11);
        $pdf->SetX($ml);
        $pdf->Cell($uw, 6, pdf_u('de la classe de ' . $classe['DesignationClasses'] . ", s'est distingué(e) par son mérite et"), 0, 1, 'C');
        $pdf->SetX($ml);
        $pdf->Cell($uw, 6, pdf_u('est inscrit(e) au TABLEAU D\'HONNEUR pour ' . $periode_fr . '.'), 0, 1, 'C');
        $pdf->SetFont('Arial', 'I', 8.5);
        $pdf->SetTextColor(120);
        $pdf->SetX($ml);
        $pdf->Cell($uw, 5, pdf_u('Certifies that the above-named student, class ' . $classe['DesignationClasses'] . ', is honored on the school\'s HONOR ROLL.'), 0, 1, 'C');
        $pdf->SetTextColor(0);
        $pdf->Ln(4);

        $mention_lbl = $m['felicitations'] ? 'FÉLICITATIONS' : ($m['encouragement'] ? 'ENCOURAGEMENT' : "TABLEAU D'HONNEUR");
        $pdf->SetFont('Arial', 'B', 13);
        $pdf->SetTextColor(214, 175, 55);
        $pdf->SetX($ml);
        $pdf->Cell($uw, 8, pdf_u('Mention : ' . $mention_lbl), 0, 1, 'C');
        $pdf->SetTextColor(0);

        $pdf->SetFont('Arial', '', 10);
        $pdf->SetX($ml);
        $pdf->Cell($uw, 6, pdf_u('Moyenne : ' . number_format((float) $l['moy'], 2) . '/20   —   Rang : ' . $l['rang'] . ' / ' . $classement['nb_classes']), 0, 1, 'C');
        $pdf->Ln(8);

        $w_sign = 70; $x_sign = $pw / 2 - $w_sign / 2;
        $pdf->SetFont('Arial', '', 9);
        $pdf->SetXY($x_sign, $pdf->GetY());
        $pdf->Cell($w_sign, 5, pdf_u('Fait à ' . (($etab['lieu'] ?: $etab['ville']) ?: '') . ', le ' . date('d/m/Y')), 0, 1, 'C');
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetX($x_sign);
        $pdf->Cell($w_sign, 5, pdf_u(mb_strtoupper($etab['chef_etablissement'] ?: 'LE DIRECTEUR') . ','), 0, 1, 'C');

        if (($_GET['signature'] ?? '0') === '1') {
            pdf_signature_appliquer_jn($pdf, 'certificat_tableau_honneur_arabe', 0, 0, $pw, $ph, [
                'x_pct' => ($x_sign + ($w_sign - 22) / 2) / $pw * 100,
                'y_pct' => ($pdf->GetY() + 1) / $ph * 100,
                'w_pct' => 22 / $pw * 100, 'h_pct' => null,
            ]);
        }

        // Copyright standard du système (pdf/header_pdf.php) — texte unique
        // sur tous les PDF du projet, voir pdf_copyright(). Absent du modèle
        // classique jusqu'ici (seul le modèle "orné" ci-dessus l'avait).
        pdf_copyright($pdf, $pw, $ph);
    }
}

// pdf_u() (FPDF, Windows-1252) n'est pas adaptée au flux TCPDF (UTF-8) — le
// Modèle 2 a besoin d'un encodage différent pour ses seuls textes LATINS
// (les textes arabes passent nus, TCPDF gère l'UTF-8 nativement).
function pdf_u_th(string $s): string {
    return $s;
}

$suffixe_periode = $vue === 'annee' ? '_annee' : '_T' . $id_trim;
$suffixe = $id_eleve_filtre ? '_' . $qualifies[0]['ligne']['id_eleve'] : '_classe';
$nom_fichier = 'tableau_honneur_arabe_' . $classe['DesignationClasses'] . $suffixe_periode . $suffixe . '.pdf';
// ⚠️ Ordre des paramètres INVERSÉ entre FPDF (dest, nom) et TCPDF
// (nom, dest) — voir pdf/bulletin_annuel_arabe.php pour le même piège déjà
// contourné côté bulletins.
if ($modele === 2) {
    $pdf->Output($nom_fichier, $dl ? 'D' : 'I');
} else {
    $pdf->Output($dl ? 'D' : 'I', $nom_fichier);
}
} catch (Throwable $e) {
    pdf_erreur_generation($e);
}
