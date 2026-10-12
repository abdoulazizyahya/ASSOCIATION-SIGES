<?php
/**
 * Certificat de Prise / Reprise de Service
 * GET : id=matricule_ens, type=prise|reprise, dl=1
 *
 * Mise en page reproduite à l'identique du modèle officiel fourni par
 * l'utilisateur (PS.pdf — Certificat de Prise/Reprise de Service, LYCÉE
 * TECHNIQUE DE MBÉ) : polices, tailles et disposition (ligne d'intitulé en
 * français suivie d'une ligne de traduction anglaise en italique, plus
 * petite) mesurées directement dans le flux du PDF modèle. Les polices non
 * disponibles dans FPDF (Comic Sans MS, Verdana, Maiandra GD — aucune n'est
 * une police coeur du moteur PDF, seules Helvetica/Arial, Times et Courier
 * le sont) sont remplacées par l'équivalent coeur le plus proche
 * (Arial/Times selon le cas), à la MÊME taille mesurée dans le modèle.
 */
require_once __DIR__ . '/../../../../config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
require_once __DIR__ . '/../../pdf/verif_attestation_lib.php';

$type = ($_GET['type'] ?? 'prise') === 'reprise' ? 'reprise' : 'prise';

// Accès public via le QR code du certificat (jeton "vh" = hash de
// vérification déjà calculé pour ce document précis, discriminé par $type)
// : la personne qui scanne n'a pas forcément de compte dans le système
// (voir verif_attestation.php). En dehors de ce cas, connexion normale exigée.
$vh_verif     = (string)($_GET['vh'] ?? '');
$mat_public   = (string)($_GET['id'] ?? '');
$acces_public = $vh_verif !== '' && $mat_public !== '' && hash_equals(attestation_verif_hash($type, $mat_public), $vh_verif);

// Validité configurable (bd/migration_v29.sql, pages/enseignants/
// validite_documents.php) : une fois expirée (durée réglée par
// l'administrateur, décomptée depuis la signature du Proviseur),
// l'enseignant peut encore VISUALISER son document mais plus
// l'imprimer/l'exporter/l'enregistrer — $doc_expire ne s'applique jamais au
// personnel administratif (ADMIN/PROVISEUR/CENSEUR génèrent librement, hors
// circuit de demande).
$doc_expire = false;

if ($acces_public) {
    $mat = $mat_public;
} else {
    exiger_connexion();
    $role = role_connecte();
    if ((in_array($role, ['ADMIN','PROVISEUR','FONDATEUR','CENSEUR'], true) || $role === 'MEMBRE_ASSOCIATION')) {
        $mat = $_GET['id'] ?? '';
    } elseif ($role === 'ENSEIGNANT') {
        $mat = matricule_ens_courant() ?? '';
        if (!$mat || !demande_validee_existe($mat, $type)) die('Accès refusé : aucune demande validée pour ce document.');
        $validite   = demande_validite_info($mat, $type);
        $doc_expire = $validite['expire'] ?? false;
    } else {
        die('Accès refusé.');
    }
}

$dl = !$doc_expire && (($_GET['dl'] ?? '0') === '1');

$e = db_one("SELECT * FROM enseignant WHERE matricule_ens=?", [$mat]);
if (!$e) die('Enseignant introuvable.');

$etab = get_etablissement();
if (!function_exists('ud')) {
    function ud(string $s): string {
        // Windows-1252 (pas ISO-8859-1 strict) : FPDF avec polices standard attend cet
        // encodage, et Windows-1252 couvre en plus des caractères comme le tiret demi-cadratin
        // "–" utilisé plus bas, absent d'ISO-8859-1.
        return mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
    }
}

require_once __DIR__ . '/../../pdf/fpdf.php';
require_once __DIR__ . '/../../pdf/header_pdf.php'; // pour pdf_filigrane()
require_once __DIR__ . '/../../pdf/qrcode.php';

class FPDF_PS extends FPDF {
    function Header() {}
    function Footer() {
        // Deux lignes distinctes (français en gras, anglais en dessous en
        // maigre) — comme le modèle, plutôt qu'une seule ligne "FR / EN".
        $this->SetY(-14);
        $this->SetFont('Times', 'B', 11);
        $this->Cell(0, 5, ud('(*) Rayer la mention inutile'), 0, 1, 'L');
        $this->SetX(15);
        $this->SetFont('Times', '', 11);
        $this->Cell(0, 5, 'Delete if necessary', 0, 0, 'L');
    }
}

$pdf = new FPDF_PS('P','mm','A4');
$pdf->SetMargins(15,10,15);
$pdf->SetAutoPageBreak(true,18);
$pdf->AddPage();

$pw = $pdf->GetPageWidth();
$ml = 15; $mr = 15; $uw = $pw-$ml-$mr;
$col3 = $uw/3;

pdf_filigrane($pdf, $etab, $pw, $pdf->GetPageHeight());

// ── EN-TÊTE — Arial 8 (police du modèle : Maiandra GD 8, non disponible
// dans FPDF ; ni le nom de l'établissement ni "MATRICULE" n'y sont en gras,
// contrairement à d'autres documents du projet : mesuré ainsi dans le
// modèle, reproduit à l'identique) ─────────────────────────────────────
$y0 = 10;
$pdf->SetXY($ml,$y0); $pdf->SetFont('Arial','',8);
$pdf->MultiCell($col3,2.7,ud(
    "REPUBLIQUE DU CAMEROUN\nPaix – Travail – Patrie\n**********\n".
    ($etab['region_fr']??"REGION DE L'ADAMAOUA")."\n**********\n".
    ($etab['departement_fr']??'DEPARTEMENT DE LA VINA')."\n**********\n".
    ($etab['arrondissement_fr']??'ARRONDISSEMENT DE MBE')."\n**********\n".
    strtoupper($etab['nom_fr']??'LYCEE TECHNIQUE DE MBE')."\n".
    "BP ".($etab['boite_postale']??'32')."\n".
    "E-mail : ".($etab['email']??'lyceetechniquembe@yahoo.fr')
), 0,'C');
$ycol = $pdf->GetY();

$logo_x = $ml+$col3+($col3-22)/2;
$logo_path = !empty($etab['logo']) ? __DIR__.'/../../../../assets/uploads/'.$etab['logo'] : '';
if ($logo_path && is_file($logo_path)) {
    $pdf->Image($logo_path,$logo_x,$y0,22);
} else {
    $pdf->SetFont('Arial','B',10); $pdf->SetXY($logo_x,$y0+4);
    $pdf->Cell(22,12,ud($etab['sigle']??'LTM'),1,0,'C');
}

$xr = $ml+$col3*2; $pdf->SetXY($xr,$y0); $pdf->SetFont('Arial','',8);
$pdf->MultiCell($col3,2.7,ud(
    "REPUBLIC OF CAMEROON\nPeace – Work – Fatherland\n**********\n".
    ($etab['region_en']??'ADAMAWA REGION')."\n**********\n".
    ($etab['division_en']??'VINA DIVISION')."\n**********\n".
    ($etab['subdivision_en']??'MBE SUBDIVISION')."\n**********\n".
    strtoupper($etab['nom_en']??'GTHS OF MBE')."\n".
    "P.O. BOX ".($etab['boite_postale']??'32')."\n".
    "E-mail : ".($etab['email']??'lyceetechniquembe@yahoo.fr')
), 0,'C');
$ycol2 = $pdf->GetY();
$pdf->SetY(max($ycol,$ycol2)+2);

$pdf->SetX($ml); $pdf->SetFont('Arial','',8);
$pdf->Cell($uw,5,ud('MATRICULE: '.($etab['immatriculation']??'2JH1TEFD110316102')),0,1,'C');

// ── TITRE — Comic Sans MS 12 / Courier New 12 dans le modèle (Comic Sans
// n'étant pas une police coeur PDF, remplacée par Arial à LA MÊME taille
// mesurée — 12, pas plus grand malgré l'aspect "titre" du modèle) ───────
$pdf->Ln(4);
$pdf->SetX($ml);$pdf->SetFont('Arial','',12);
$pdf->Cell($uw,7,ud('CERTIFICAT DE PRISE / REPRISE DE SERVICE (*)'),0,1,'C');
$pdf->SetX($ml);$pdf->SetFont('Courier','',12);
$pdf->Cell($uw,6,'CERTIFICATE OF ASSUMPTION/RESUMPTION OF DUTY',0,1,'C');
$pdf->SetX($ml);$pdf->SetFont('Courier','B',9);
$pdf->Cell($uw,5,'*********************************',0,1,'C');

$pdf->Ln(1);$pdf->SetX($ml);$pdf->SetFont('Courier','',10);
$pdf->Cell($uw,5,ud('N°_________________/CP - RS/H.52.03/LT-MBE'),0,1,'C');

// ── "Le Proviseur ..., soussigné" — Verdana Bold 11 / Courier New 9 dans
// le modèle (Verdana absente des polices coeur, remplacée par Arial Bold à
// la même taille) — centré (contrairement aux autres documents du projet,
// alignés à gauche : mesuré ainsi dans ce modèle précis). ───────────────
$pdf->Ln(3);$pdf->SetX($ml);$pdf->SetFont('Arial','B',11);
$pdf->Cell($uw,6,ud('Le Proviseur du '.strtoupper($etab['nom_fr']??'LYCEE TECHNIQUE DE MBE').', soussigné'),0,1,'C');
$pdf->SetX($ml);$pdf->SetFont('Courier','',9);
$pdf->Cell($uw,5,ud('The principal of '.strtoupper($etab['nom_en']??'Government Technical High School MBE').', the undersigned'),0,1,'C');

// ── CORPS — Times 11 pour l'intitulé français (police du modèle :
// TimesNewRomanPSMT 11.04) ; chaque champ occupe DEUX lignes comme dans le
// modèle : l'intitulé français (rempli), puis sa traduction anglaise juste
// en dessous, en italique — le modèle alternait Courier/Times à des tailles
// différentes selon le champ (artefact du document Word d'origine),
// uniformisé ici sur UNE seule police/taille (Courier italique 8, demande
// explicite) pour toutes les traductions anglaises, avec un interligne
// resserré entre les deux lignes (hauteurs de cellule réduites ci-dessous —
// la taille de police, elle, reste celle mesurée dans le modèle). ────────
$fmt_date = fn(?string $d): string => $d ? date('d/m/Y',strtotime($d)) : '____/____/________';
$blank = fn(string $val='', int $len=40): string => $val ? str_pad($val,$len) : str_repeat('_',$len);

// Ligne française (Times 11, sur toute la largeur ou en colonnes) puis,
// juste en dessous, la ligne de traduction anglaise (Courier 8 italique,
// uniforme) — colonnes alignées par les mêmes largeurs sur les 2 lignes.
// Hauteurs resserrées (h/h_en) : interligne réduit entre les deux lignes,
// sans toucher aux tailles de police (11 / 8).
function ligne_champ(FPDF $pdf, float $ml, array $cols_fr, array $cols_en, string $font_en, float $size_en, float $h = 4.2, float $h_en = 2.6): void {
    $pdf->SetFont('Times', '', 11);
    $pdf->SetX($ml);
    foreach ($cols_fr as $i => [$txt, $w]) {
        $dernier = $i === array_key_last($cols_fr);
        $pdf->Cell($w, $h, ud($txt), 0, $dernier ? 1 : 0, 'L');
    }
    if ($cols_en) {
        $pdf->SetFont($font_en, 'I', $size_en);
        $pdf->SetX($ml);
        foreach ($cols_en as $i => [$txt, $w]) {
            $dernier = $i === array_key_last($cols_en);
            $pdf->Cell($w, $h_en, ud($txt), 0, $dernier ? 1 : 0, 'L');
        }
    }
}

$c2 = $uw / 2;
$c3a = $uw * 0.34; $c3b = $uw * 0.30; $c3c = $uw - $c3a - $c3b;

$pdf->Ln(2);
ligne_champ($pdf, $ml, [['Atteste que', $uw]], [], 'Courier', 8);

ligne_champ($pdf, $ml,
    [["M./Mme/Mlle:".$blank(strtoupper(($e['civilite_ens']??'').' '.($e['nom_ens']??'').' '.($e['prenom_ens']??'')),75), $uw]],
    [['Testifies that Mr./Mme/Miss', $uw]], 'Courier', 8);

ligne_champ($pdf, $ml,
    [["Grade".$blank($e['id_grade']??'',22)." Matricule".$blank((string)$e['matricule_ens'],24), $uw]],
    [['Grade', $c2], ['registration N°', $c2]], 'Courier', 8);

ligne_champ($pdf, $ml,
    [["Né le ".$blank($fmt_date($e['date_naiss']??null),35)." A".$blank($e['lieu_naiss']??'',33), $uw]],
    [['Borne on', $c2], ['at', $c2]], 'Courier', 8);

ligne_champ($pdf, $ml,
    [['Région '.$blank($e['region_origine']??'',15), $c3a], ['Département '.$blank($e['departement_origine']??'',12), $c3b], ["Arrondissement d'origine ".$blank($e['arrondissement_origine']??'',10), $c3c]],
    [['Region', $c3a], ['Division', $c3b], ['Sub-division of origine', $c3c]], 'Courier', 8);

ligne_champ($pdf, $ml,
    [["Tribu ".$blank($e['tribu']??'',24)." Ethnie ".$blank($e['ethnie']??'',20), $uw]],
    [['Tribe', $c2], ['ethnie groupe', $c2]], 'Courier', 8);

ligne_champ($pdf, $ml,
    [['Situation matrimoniale '.$blank($e['situation_matrimoniale']??'',10), $c3a], ['Poste antérieur '.$blank($e['poste_anterieur']??'',13), $c3b], ['lieu '.$blank($e['lieu_anterieur']??'',8), $c3c]],
    [['Family status', $c3a], ['former post', $c3b], ['place', $c3c]], 'Courier', 8);

ligne_champ($pdf, $ml,
    [["Numero de l'acte de recrutement ".$blank($e['num_acte_recrutement']??'',28)." du ".$blank($fmt_date($e['date_acte_recrutement']??null),12), $uw]],
    [['Recrutement certificate number', $c2], ['of', $c2]], 'Courier', 8);

ligne_champ($pdf, $ml,
    [["Date d'entrée dans la Fonction Publique : ".$blank($fmt_date($e['date_entree_fp']??null),25), $uw]],
    [['Date of entering into the public service', $uw]], 'Courier', 8);

ligne_champ($pdf, $ml,
    [['Affecté (e) / Nommé(e)/Muté(e) par Arrêté, Note de service, Décision (*)', $uw]],
    [['Posted, Appointed, Transferred by service note, decision, order', $uw]], 'Courier', 8);

ligne_champ($pdf, $ml,
    [["N° ".$blank($e['num_note_affectation']??'',30)." du ".$blank($fmt_date($e['date_note_affectation']??null),15), $uw]],
    [['N°', $c2], ['of', $c2]], 'Courier', 8);

ligne_champ($pdf, $ml,
    [['A effectivement pris/repris (*) le '.$blank($fmt_date($e['date_prise_service']??null),35), $uw]],
    [['Has effectively taken service on the', $uw]], 'Courier', 8);

ligne_champ($pdf, $ml,
    [['En qualité de : '.$blank($e['qualite']??'',45), $uw]],
    [['As', $uw]], 'Courier', 8);

ligne_champ($pdf, $ml,
    [['Diplôme '.$blank($e['diplome']??'',12).' Spécialité : '.$blank($e['specialite']??'',22), $uw]],
    [['Diploma', $c2], ['speciality', $c2]], 'Courier', 8);

ligne_champ($pdf, $ml,
    [["Date de la 1ère prise de service dans l'administration ".$blank($fmt_date($e['date_1ere_admin']??null),20), $uw]],
    [['First date of assumption of duty in the public service', $uw]], 'Courier', 8);

ligne_champ($pdf, $ml,
    [["Date de la première prise de service dans l'Etablissement ".$blank($fmt_date($e['date_1ere_etab']??null),20), $uw]],
    [['First date of assumption of duty in the structure', $uw]], 'Courier', 8);

ligne_champ($pdf, $ml,
    [['Matière effectivement enseignée '.$blank($e['matiere_enseignee']??'',35), $uw]],
    [['Subjet really taught', $uw]], 'Courier', 8);

$pdf->Ln(2);$pdf->SetFont('Times','',11);$pdf->SetX($ml);
$pdf->MultiCell($uw,5,ud("En foi de quoi le présent certificat de prise/reprise de service lui est délivrée pour servir et valoir ce que de droit."),0,'L');
$pdf->SetFont('Times','I',7);$pdf->SetX($ml);
$pdf->MultiCell($uw,3.4,'Witness where of this certificate of assumption/resumption has been issued to serve where and when necessary.',0,'L');

// ── SIGNATURE — "Le Proviseur" en Times Bold 18 (le plus grand texte du
// document, mesuré ainsi dans le modèle), "The principal" en Times 14 ──
// Réservation explicite de la place nécessaire (signature + QR, ~24mm) AVANT
// de dessiner quoi que ce soit : évite qu'un saut de page automatique se
// déclenche AU MILIEU du bloc (ce qui décalerait $y_sig_block, capturé avant
// le dessin, par rapport à la page réellement utilisée par le QR plus bas).
$pdf->Ln(4);
if ($pdf->GetY() + 24 > $pdf->GetPageHeight() - 18) {
    $pdf->AddPage();
    pdf_filigrane($pdf, $etab, $pw, $pdf->GetPageHeight());
    $pdf->SetY(10);
}
$y_sig_block = $pdf->GetY();
$pdf->SetX($ml+$uw*0.55);$pdf->SetFont('Times','',12);
$pdf->Cell($uw*0.45,6,ud('MBE, Le………………….'),0,1,'R');
$pdf->SetX($ml+$uw*0.55);$pdf->SetFont('Times','B',18);
$pdf->Cell($uw*0.45,9,'Le Proviseur',0,1,'C');
$pdf->SetX($ml+$uw*0.55);$pdf->SetFont('Times','',14);
$pdf->Cell($uw*0.45,7,'The principal',0,1,'C');
// Signature numérique (uniquement si demandée à l'impression — jamais
// automatique — et si l'admin en a configuré une dans les paramètres) :
// placée dans l'espace blanc réservé à la signature manuscrite.
if (($_GET['signature'] ?? '0') === '1') {
    $sig_w = 26;
    $ph = $pdf->GetPageHeight();
    $sx = $ml+$uw*0.55+($uw*0.45-$sig_w)/2;
    $sy = $pdf->GetY()+1;
    pdf_signature_appliquer($pdf, 'prise_reprise_service', 'chef_etablissement', 0, 0, $pw, $ph, [
        'x_pct' => $sx / $pw * 100, 'y_pct' => $sy / $ph * 100, 'w_pct' => $sig_w / $pw * 100, 'h_pct' => null,
    ]);
}

// ── QR de vérification d'authenticité (propre à ce document : $type
// discrimine prise/reprise, un QR de prise de service ne valide jamais un
// certificat de reprise, et inversement). Pas de photo incrustée : la
// table enseignant n'a pas de champ photo.
$qr_size = 18;
$qr_x = $ml;
$qr_y = $y_sig_block;
$verif_url = attestation_verif_url($type, $mat);
$qr_tmp = tempnam(sys_get_temp_dir(), 'abzqrps_') . '.png';
$qr_gen = new QRCode($verif_url, ['s' => 'qr-h']);
$qr_img = $qr_gen->render_image();
imagepng($qr_img, $qr_tmp);
imagedestroy($qr_img);
$pdf->Image($qr_tmp, $qr_x, $qr_y, $qr_size, $qr_size, 'PNG');
unlink($qr_tmp);
$pdf->SetFont('Arial', 'I', 6);
$pdf->SetXY($qr_x, $qr_y + $qr_size + 0.5);
$pdf->Cell($qr_size, 3, 'Scanner pour verifier', 0, 0, 'C');

// Document expiré (validité configurée par l'administrateur — voir plus
// haut) : encore consultable, mais visuellement marqué non valable, en plus
// du blocage déjà appliqué sur $dl.
if ($doc_expire) {
    pdf_filigrane_expire($pdf, $pw, $pdf->GetPageHeight());
}

$pdf->Output($dl?'D':'I', 'certificat_service_'.preg_replace('/\W/','_',$mat).'.pdf');
