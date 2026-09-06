<?php
// ── PDF : Certificat de Tableau d'honneur — piste française ──────────
// GET : classe, (trim OU vue=annee), eleve (optionnel — sans lui, imprime
// tous les élèves qualifiés de la classe/période, une page chacun),
// modele (1|2, défaut 1), dl (0|1).
//
// Modèle 1 « Classique » : cadre double bleu/or, texte en paragraphe, sans
// QR — port simplifié du modèle ABZ_MBE d'origine (voir historique).
// Modèle 2 « Orné » (nouveau, demande explicite) : bordure décorative à
// motifs + coins ornés, bannière "TABLEAU D'HONNEUR", cases Encouragement/
// Félicitations, encadrés Moyenne/Rang, QR de vérification (photo incrustée,
// même style que les bulletins) — inspiré des modèles fournis
// (TH_fr_trim.pdf/TH_fr_ann.pdf). Seul modèle avec vérification publique par
// QR (voir verif_honneur.php) — le Modèle 1 n'en a pas.
// Qualification et mention via mention_travail() (notes_apc.php).
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
require_once __DIR__ . '/../notes_apc.php';
require_once __DIR__ . '/verif_lib.php';
require_once __DIR__ . '/verif_honneur_lib.php';

// Accès public via le QR (jeton "vh", Modèle 2 uniquement) — même mécanisme
// que pdf/bulletin_annuel.php, voir son commentaire pour le détail.
$acces_public = false;
if (($_GET['vh'] ?? '') !== '' && (int) ($_GET['eleve'] ?? 0) > 0) {
    $vue_pub    = (($_GET['vue'] ?? '') === 'annee') ? 'annee' : 'trim';
    $val_annee_pub = get_annee_active()['val_annee'] ?? '';
    $periode_pub = $vue_pub === 'annee' ? (int) substr($val_annee_pub, 0, 4) : (int) ($_GET['trim'] ?? 0);
    $acces_public = honneur_verif_valider((int) $_GET['eleve'], $vue_pub, $periode_pub, 'fr', (string) $_GET['vh']) !== null;
}
if (!$acces_public) exiger_acces_pedagogie();

require_once __DIR__ . '/fpdf.php';
require_once __DIR__ . '/header_pdf.php';

$id_classe       = (int) ($_GET['classe'] ?? 0);
if (!$acces_public) exiger_acces_classe($id_classe, get_annee_active()['val_annee'] ?? '', 'fr');   // cloisonnement enseignant
$vue             = (($_GET['vue'] ?? '') === 'annee') ? 'annee' : 'trim';
$id_trim         = (int) ($_GET['trim'] ?? 0);
$id_eleve_filtre = (int) ($_GET['eleve'] ?? 0);
$modele          = in_array((int) ($_GET['modele'] ?? 1), [1, 2], true) ? (int) ($_GET['modele'] ?? 1) : 1;
$dl              = ($_GET['dl'] ?? '0') === '1';
if (!$id_classe) die('Paramètre classe manquant.');
if ($vue === 'trim' && !$id_trim) die('Paramètre trim manquant.');

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

// ── Qualifiés (trimestre ou année) ──────────────────────────────────
if ($vue === 'annee') {
    $classement = classement_annuel_classe($id_classe, $val_annee);
    $periode_fr = "l'année scolaire " . $val_annee;
    // "pour le compte " + $periode_m2 — élision déjà correcte ("de l'année…").
    $periode_m2 = "de l'année scolaire " . $val_annee;
    $periode_en = 'the ' . $val_annee . ' school year';
} else {
    $classement = classement_trimestre_classe($id_classe, $id_trim, $val_annee);
    $periode_fr = 'le ' . $trimestre['libelle_trim'] . ' de l\'année scolaire ' . $val_annee;
    // "pour le compte " + $periode_m2 — "du" (pas "de le") devant le trimestre.
    $periode_m2 = 'du ' . $trimestre['libelle_trim'] . ' de l\'année scolaire ' . $val_annee;
    $periode_en = 'this term (' . $val_annee . ')';
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

// Enveloppé dans un try/catch : accessible publiquement via le QR du
// certificat — voir fonctions.php::pdf_erreur_generation().
try {
$pdf = new FPDF('L', 'mm', 'A4');
$pdf->SetAutoPageBreak(false);
$pw = 297; $ph = 210;

// Coche/case vide (mêmes icônes que les bulletins — voir assets/img/pdf/,
// même fonction dupliquée par fichier par convention du projet, voir
// pdf/bulletin_trimestriel.php pour le jumeau).
function pdf_case(FPDF $pdf, bool $coche, float $x, float $y, float $taille = 3.0): void {
    $fichier = $coche ? 'case_cochee.jpg' : 'case_a_cocher.jpg';
    $pdf->Image(__DIR__ . '/../assets/img/pdf/' . $fichier, $x, $y, $taille, $taille);
}

foreach ($qualifies as $q) {
    $l = $q['ligne']; $m = $q['mention'];
    $pdf->AddPage();

    if ($modele === 2) {
        // ══════════════════════════════ MODÈLE 2 — ORNÉ ═══════════════════
        // Reconstruction fidèle du modèle fourni (TH_fr_trim.pdf/TH_fr_ann.pdf) :
        // le PDF de référence a été décompressé (flux de contenu PDF brut,
        // opérateurs Tf/Td/Tj/rg/re) pour en extraire les coordonnées, tailles
        // de police, couleurs et images RÉELLES plutôt que de les redeviner sur
        // une capture d'écran — polices/tailles/couleurs/positions ci-dessous
        // sont donc les valeurs exactes du document fourni (converties de pt
        // PDF en mm, 1pt = 25.4/72mm), pas une approximation. Bordure et
        // bannière "TABLEAU D'HONNEUR" : images JPEG extraites telles quelles
        // du PDF fourni (assets/img/pdf/tableau_honneur/), génériques et
        // réutilisables (aucune donnée d'établissement dedans, à la différence
        // du logo/filigrane qui restent dynamiques via pdf_entete()/
        // pdf_filigrane() — la référence utilisait SON logo figé, ici on garde
        // le vrai logo/filigrane configuré de l'établissement, plus correct
        // qu'un doublon codé en dur).
        pdf_filigrane($pdf, $etab, $pw, $ph, 150, 70, 35);
        $pdf->Image(__DIR__ . '/../assets/img/pdf/tableau_honneur/bordure.jpg', 2, 2, 293, 207);
        // Échelle agrandie (logo + police d'en-tête) — demande explicite,
        // 0.8→0.95 — bannière décalée de 2mm plus bas (44→46) pour laisser
        // la place au bloc d'en-tête devenu plus grand, tout le reste du
        // contenu (cases, sous-titre, paragraphe, encadrés, signatures)
        // décalé d'autant pour rester cohérent.
        pdf_entete($pdf, $etab, $pw, 14, 12, 0.95);

        $pdf->SetFont('Arial', 'B', 11);
        $pdf->SetXY(14, 38.5);
        $pdf->Cell(60, 5, pdf_u('Année scolaire: ' . $val_annee), 0, 0);
        $pdf->SetFont('Arial', 'I', 10);
        $pdf->SetXY(14, 43.5);
        $pdf->Cell(60, 4, 'School Year', 0, 1);

        // Bannière-pilule "TABLEAU D'HONNEUR" — pilule vectorielle (couleur
        // exacte 0.416/0.710/1.000 → RGB 106/181/255 du PDF fourni) + image
        // arc-en-ciel exacte par-dessus (527×123pt → 186×43mm à l'échelle,
        // recadrée à 153.7×18mm dans le flux d'origine).
        $pdf->SetFillColor(106, 181, 255);
        $pdf->SetDrawColor(30, 79, 179);
        $pdf->RoundedRect(70, 48, 153.7, 18, 9, 'DF');
        $pdf->Image(__DIR__ . '/../assets/img/pdf/tableau_honneur/titre_arc_en_ciel.jpg', 83, 48.5, 127, 17);
        $pdf->SetDrawColor(0);

        // Photo de l'élève — cadre 40×40mm à x=235 (y décalé avec la bannière).
        $eleve_full = db_one("SELECT * FROM eleve WHERE id_eleve=?", [(int) $l['id_eleve']]);
        $pdf->SetDrawColor(30, 79, 179); $pdf->SetLineWidth(0.5);
        $pdf->Rect(235, 48, 40, 40);
        $photo_tmp = $eleve_full ? photo_eleve_fichier_temp($eleve_full['Photo_elv'] ?? null, (int) $l['id_eleve']) : null;
        if ($photo_tmp) {
            $pdf->Image($photo_tmp, 236, 49, 38, 38);
            @unlink($photo_tmp);
        } else {
            $avatar = (stripos($eleve_full['Sexe_elv'] ?? '', 'F') === 0) ? 'fille.png' : 'garcon.png';
            $ap = __DIR__ . '/../assets/img/avatars/' . $avatar;
            if (is_file($ap)) $pdf->Image($ap, 236, 49, 38, 38);
        }
        $pdf->SetDrawColor(0); $pdf->SetLineWidth(0.2);

        // Cases Encouragement (bleu 0/115/170) / Félicitations (rouge 235/0/0).
        $pdf->SetFont('Arial', 'B', 20);
        pdf_case($pdf, (bool) $m['encouragement'], 30, 67.5, 9);
        $pdf->SetTextColor(0, 115, 170);
        $pdf->Text(40, 74.5, pdf_u('ENCOURAGEMENT'));
        pdf_case($pdf, (bool) $m['felicitations'], 150, 67.5, 9);
        $pdf->SetTextColor(235, 0, 0);
        $pdf->Text(160, 74.5, pdf_u('FELICITATIONS'));
        $pdf->SetTextColor(0);

        // Sous-titre italique gras vert (0/132/0) souligné.
        $pdf->SetFont('Arial', 'BI', 15);
        $pdf->SetTextColor(0, 132, 0);
        $pdf->Text(40, 88.0, pdf_u('POUR SON TRAVAIL TRES SATISFAISANT ET SA CONDUITE EXEMPLAIRE'));
        $pdf->SetFillColor(0, 132, 0);
        $pdf->Rect(40, 88.5, 190.2, 0.26, 'F');
        $pdf->SetTextColor(0);

        // Paragraphe — pleine largeur de ligne (bord à bord, marges 20mm de
        // part et d'autre) et justifié ('J' — demande explicite : le texte
        // doit occuper toute la ligne et ne retourner à la ligne que quand
        // c'est nécessaire, pas de retour prématuré).
        $nom_complet = mb_strtoupper($l['Nom_elv']) . ' ' . ($l['Prenom_elv'] ?? '');
        $marge_para = 20; $largeur_para = $pw - 2 * $marge_para;
        $pdf->SetFont('Arial', '', 15);
        $pdf->SetXY($marge_para, 93.0);
        $pdf->MultiCell($largeur_para, 8.0, pdf_u(
            "L'élève " . $nom_complet . ' de la classe de ' . $classe['DesignationClasses']
            . " est inscrit(e) sur décision du conseil de classe au TABLEAU D'HONNEUR pour le compte "
            . $periode_m2 . '.'
        ), 0, 'J');
        $pdf->SetX($marge_para);
        $pdf->MultiCell($largeur_para, 8.0, pdf_u('Cette reconnaissance prestigieuse de son MERITE est aussi et surtout un appel à l\'EXCELLENCE.'), 0, 'J');

        // Encadrés Moyenne/Rang — même bleu que la bannière (106/181/255).
        $y_res = max($pdf->GetY() + 4, 125.0);
        $lbl_moy = $vue === 'annee' ? 'MOYENNE ANNUELLE' : 'MOYENNE';
        $pdf->SetFont('Arial', 'B', 15);
        $pdf->SetTextColor(0);
        $pdf->Text(24, $y_res, pdf_u($lbl_moy));
        $pdf->Text(120, $y_res, pdf_u('RANG'));
        $pdf->SetFont('Arial', '', 15);
        $pdf->Text(172, $y_res, pdf_u('Fait à ' . (($etab['lieu'] ?: $etab['ville']) ?: '') . ', le ' . date('d-m-Y') . '.'));

        $pdf->SetFillColor(106, 181, 255);
        $pdf->SetFont('Arial', 'B', 20);
        $pdf->SetXY(24, $y_res + 1.9);
        $pdf->Cell(35, 9, number_format((float) $l['moy'], 2) . '/20', 1, 0, 'C', true);
        $pdf->SetXY(119, $y_res + 1.9);
        $pdf->Cell(30, 9, pdf_u($l['rang'] . '/' . $classement['nb_classes']), 1, 0, 'C', true);

        // Signatures — bleu (Enseignant) / rouge (Directeur), mêmes couleurs
        // que les cases à cocher, position exacte.
        $y_sign = $y_res + 17;
        $pdf->SetFont('Arial', 'B', 15);
        $pdf->SetTextColor(0, 115, 170);
        $pdf->Text(47, $y_sign, pdf_u("L'ENSEIGNANT(E),"));
        $pdf->SetTextColor(235, 0, 0);
        $pdf->Text(200, $y_sign, pdf_u(mb_strtoupper($etab['chef_etablissement'] ?: 'LE DIRECTEUR') . ','));
        $pdf->SetTextColor(0);

        if (($_GET['signature'] ?? '0') === '1') {
            pdf_signature_appliquer_jn($pdf, 'certificat_tableau_honneur', 0, 0, $pw, $ph, [
                'x_pct' => 200 / $pw * 100, 'y_pct' => ($y_sign + 2) / $ph * 100,
                'w_pct' => 30 / $pw * 100, 'h_pct' => null,
            ]);
        }

        // QR de vérification (photo incrustée, cache disque) — agrandi par
        // rapport au bloc QR du PDF fourni (19×19mm à l'origine → 27×27mm,
        // demande explicite), toujours centré, bord bas aligné avec l'ancien
        // pour garder un peu d'air avant le copyright. Pas de ligne de devise
        // séparée : dans l'original, le ruban "Travail - Efficacité -
        // Excellence" fait partie de l'image du filigrane elle-même (vérifié
        // dans le flux PDF décompressé — aucun texte Tj séparé), déjà rendu
        // par pdf_filigrane() ci-dessus.
        if ($eleve_full) {
            $taille_qr = 27;
            $id_periode_qr = $vue === 'annee' ? (int) substr($val_annee, 0, 4) : $id_trim;
            $qr_tmp = honneur_qr_fichier_temp($eleve_full, $vue, $id_periode_qr, 'fr');
            if ($qr_tmp) $pdf->Image($qr_tmp, $pw / 2 - $taille_qr / 2, 192 - $taille_qr, $taille_qr, $taille_qr, 'PNG');
        }

        // Copyright standard du système (pdf/header_pdf.php) — texte unique
        // sur tous les PDF du projet, voir pdf_copyright(). Marge basse
        // ajustée pour garder la même position verticale qu'auparavant
        // (196.8 = 210 - 13.2).
        pdf_copyright($pdf, $pw, $ph, 13.2);
        continue;
    }

    // ══════════════════════════════ MODÈLE 1 — CLASSIQUE ═════════════════
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
    $pdf->Cell($pw, 6, 'HONOR ROLL CERTIFICATE', 0, 1, 'C');
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
    $pdf->Cell($uw, 5, pdf_u('Certifies that the above-named student, class ' . $classe['DesignationClasses'] . ', is honored on the school\'s HONOR ROLL for ' . $periode_en . '.'), 0, 1, 'C');
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
        pdf_signature_appliquer_jn($pdf, 'certificat_tableau_honneur', 0, 0, $pw, $ph, [
            'x_pct' => ($x_sign + ($w_sign - 22) / 2) / $pw * 100,
            'y_pct' => ($pdf->GetY() + 1) / $ph * 100,
            'w_pct' => 22 / $pw * 100, 'h_pct' => null,
        ]);
    }

    // Copyright standard du système (pdf/header_pdf.php) — texte unique sur
    // tous les PDF du projet, voir pdf_copyright(). Absent du modèle
    // classique jusqu'ici (seul le modèle "orné" ci-dessus l'avait).
    pdf_copyright($pdf, $pw, $ph);
}

$suffixe_periode = $vue === 'annee' ? '_annee' : '_T' . $id_trim;
$suffixe = $id_eleve_filtre ? '_' . $qualifies[0]['ligne']['id_eleve'] : '_classe';
$pdf->Output($dl ? 'D' : 'I', 'tableau_honneur_' . $classe['DesignationClasses'] . $suffixe_periode . $suffixe . '.pdf');
} catch (Throwable $e) {
    pdf_erreur_generation($e);
}
