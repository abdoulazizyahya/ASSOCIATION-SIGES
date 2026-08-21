<?php
/**
 * Attestation de Présence Effective au Poste
 * GET : id=matricule_ens, dl=1 pour téléchargement
 */
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/../../pdf/verif_attestation_lib.php';

// Accès public via le QR code de l'attestation (jeton "vh" = hash de
// vérification déjà calculé pour ce document précis) : la personne qui
// scanne n'a pas forcément de compte dans le système (voir
// verif_attestation.php). En dehors de ce cas, connexion normale exigée.
$vh_verif     = (string)($_GET['vh'] ?? '');
$mat_public   = (string)($_GET['id'] ?? '');
$acces_public = $vh_verif !== '' && $mat_public !== '' && hash_equals(attestation_verif_hash('attestation', $mat_public), $vh_verif);

if ($acces_public) {
    $mat = $mat_public;
} else {
    exiger_connexion();
    $role = role_connecte();
    if (in_array($role, ['ADMIN','PROVISEUR','CENSEUR'])) {
        $mat = $_GET['id'] ?? '';
    } elseif ($role === 'ENSEIGNANT') {
        $mat = matricule_ens_courant() ?? '';
        if (!$mat || !demande_validee_existe($mat, 'attestation')) die('Accès refusé : aucune demande validée pour ce document.');
    } else {
        die('Accès refusé.');
    }
}
$dl  = ($_GET['dl'] ?? '0') === '1';

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

class FPDF_Att extends FPDF {
    function Header() {}
    function Footer() {
        $this->SetY(-10);
        $this->SetFont('Arial','I',7);
        $this->Cell(0,5,'(*) Rayer la mention inutile / Delete if necessary',0,0,'L');
    }
}

// Enveloppé dans un try/catch : accessible publiquement via le QR de
// l'attestation — voir fonctions.php::pdf_erreur_generation().
try {
$pdf = new FPDF_Att('P','mm','A4');
$pdf->SetMargins(15,10,15);
$pdf->SetAutoPageBreak(true,15);
$pdf->AddPage();

$pw = $pdf->GetPageWidth();
$ml = 15; $mr = 15; $uw = $pw-$ml-$mr;
$col3 = $uw/3;

pdf_filigrane($pdf, $etab, $pw, $pdf->GetPageHeight());

// ── EN-TÊTE BILINGUE ───────────────────────────────────────────────
$y0 = 10;
$pdf->SetXY($ml,$y0); $pdf->SetFont('Arial','',7.5);
$pdf->MultiCell($col3,4, ud(
    "REPUBLIQUE DU CAMEROUN\nPaix - Travail - Patrie\n**********\n".
    ($etab['region_fr']??"REGION DE L'ADAMAOUA")."\n**********\n".
    ($etab['departement_fr']??'DEPARTEMENT DE LA VINA')."\n**********\n".
    ($etab['arrondissement_fr']??'ARRONDISSEMENT DE MBE')."\n**********\n".
    strtoupper($etab['nom_fr']??'LYCEE TECHNIQUE DE MBE')."\n".
    "BP ".($etab['boite_postale']??'32')." e-mail : ".($etab['email']??'lyceetechniquembe@yahoo.fr')
), 0,'C');
$ycol = $pdf->GetY();

// Logo centre
$logo_x = $ml+$col3+($col3-22)/2;
$logo_path = !empty($etab['logo']) ? __DIR__.'/../../assets/uploads/'.$etab['logo'] : '';
if ($logo_path && is_file($logo_path)) {
    $pdf->Image($logo_path,$logo_x,$y0,22);
} else {
    $pdf->SetFont('Arial','B',10); $pdf->SetXY($logo_x,$y0+4);
    $pdf->Cell(22,12,ud($etab['sigle']??'LTM'),1,0,'C');
}

// Colonne droite anglais
$xr = $ml+$col3*2; $pdf->SetXY($xr,$y0); $pdf->SetFont('Arial','',7.5);
$pdf->MultiCell($col3,4,ud(
    "REPUBLIC OF CAMEROON\nPeace – Work – Fatherland\n**********\n".
    ($etab['region_en']??'ADAMAWA REGION')."\n**********\n".
    ($etab['division_en']??'VINA DIVISION')."\n**********\n".
    ($etab['subdivision_en']??'MBE SUBDIVISION')."\n**********\n".
    strtoupper($etab['nom_en']??'GTHS OF MBE')."\n".
    "P.O. BOX ".($etab['boite_postale']??'32')."  e-mail : ".($etab['email']??'lyceetechniquembe@yahoo.fr')
), 0,'C');
$ycol2 = $pdf->GetY();
$pdf->SetY(max($ycol,$ycol2)+2);

// Matricule établissement
$pdf->SetX($ml); $pdf->SetFont('Arial','B',8);
$pdf->Cell($uw,5,ud('MATRICULE : '.($etab['immatriculation']??'2JH1TEFD110316102')),0,1,'C');

// ── N° document ───────────────────────────────────────────────────
$pdf->Ln(2);$pdf->SetX($ml);$pdf->SetFont('Arial','',8);
$pdf->Cell($uw,5,ud('N°_____________/APEP/H.52.03/LT-MBE'),0,1,'R');

// ── TITRE ─────────────────────────────────────────────────────────
$pdf->Ln(3);
$pdf->SetX($ml);$pdf->SetFont('Arial','B',13);
$pdf->Cell($uw,8,'ATTESTATION DE PRESENCE EFFECTIVE AU POSTE',0,1,'C');
$pdf->SetX($ml);$pdf->SetFont('Arial','I',9);
$pdf->Cell($uw,6,'Effective Service Attendance Certificate',0,1,'C');
$pdf->SetX($ml);$pdf->SetFont('Arial','B',9);
$pdf->Cell($uw,5,'*********************************',0,1,'C');

// ── TEXTE ─────────────────────────────────────────────────────────
$pdf->Ln(4);$pdf->SetFont('Arial','',9.5);
$pdf->SetX($ml);
$pdf->MultiCell($uw,6,ud("Le Proviseur du ".strtoupper($etab['nom_fr']??'LYCEE TECHNIQUE DE MBE').", soussigne\nThe principal of ".strtoupper($etab['nom_en']??'Government Technical High School MBE').", the undersigned"),0,'L');

$pdf->Ln(3);$pdf->SetX($ml);
$pdf->MultiCell($uw,6,ud("Atteste que M./Mme/Mlle : ".str_pad(strtoupper(($e['civilite_ens']??'').' '.($e['nom_ens']??'').' '.($e['prenom_ens']??'')),60,'_')."\nTestifies that Mr./Mme/Miss"),0,'L');

$fmt_date = fn(?string $d): string => $d ? date('d/m/Y',strtotime($d)) : '____/____/________';
$blank = fn(string $val='', int $len=40): string => $val ? str_pad($val,$len) : str_repeat('_',$len);

$pdf->Ln(2);$pdf->SetX($ml);
$pdf->Cell($uw/2,6,ud("Grade : ".$blank($e['id_grade']??'',30)),0,0,'L');
$pdf->Cell($uw/2,6,ud("Matricule/ID No. : ".$blank($e['matricule_ens'],24)),0,1,'L');

$pdf->SetX($ml);
$pdf->Cell($uw/2,6,ud("Ne(e)/Born le/on : ").$blank($fmt_date($e['date_naiss']??null),20),0,0,'L');
$pdf->Cell($uw/2,6,ud("A/At : ".$blank($e['lieu_naiss']??'',33)),0,1,'L');

$pdf->SetX($ml);
$pdf->Cell($uw/3,6,ud("Region : ".$blank($e['region_origine']??'',15)),0,0,'L');
$pdf->Cell($uw/3,6,ud("Departement : ".$blank($e['departement_origine']??'',15)),0,0,'L');
$pdf->Cell($uw/3,6,ud("Arrondt./Subdiv. : ".$blank($e['arrondissement_origine']??'',9)),0,1,'L');

$pdf->SetX($ml);
$pdf->Cell($uw/2,6,ud("Tribu/Tribe : ".$blank($e['tribu']??'',26)),0,0,'L');
$pdf->Cell($uw/2,6,ud("Ethnie/Ethnic gp. : ".$blank($e['ethnie']??'',22)),0,1,'L');

$pdf->SetX($ml);
$pdf->Cell($uw/2,6,ud("Sit. matrimoniale/Marital status : ".$blank($e['situation_matrimoniale']??'',10)),0,0,'L');
$pdf->Cell($uw/2,6,ud("Poste anterieur/Previous post : ".$blank($e['poste_anterieur']??'',18)." lieu/place : ".$blank($e['lieu_anterieur']??'',10)),0,1,'L');

$pdf->SetX($ml);
$pdf->Cell($uw/2,6,ud("N Acte recrutement/Recruitment act No. : ".$blank($e['num_acte_recrutement']??'',10)),0,0,'L');
$pdf->Cell($uw/2,6,ud("du/dated : ".$blank($fmt_date($e['date_acte_recrutement']??null),25)),0,1,'L');

$pdf->SetX($ml);
$pdf->Cell($uw,6,ud("Date d'entree dans la Fonction Publique/Date of entry into Civil Service : ").$blank($fmt_date($e['date_entree_fp']??null),25),0,1,'L');

$aff_type = match($e['type_affectation']??'') {
    'Arrete' => 'Arrete', 'Note de service' => 'Note de service', 'Decision' => 'Decision', default => 'Arrete, Note de service, Decision'
};
$pdf->SetX($ml);
$pdf->MultiCell($uw,6,ud("Affecte(e)/Nomme(e)/Mute(e) par : $aff_type (*)\nPosted, Appointed, Transferred by service note, decision, order"),0,'L');

$pdf->SetX($ml);
$pdf->Cell($uw/2,6,ud("N°/No. : ".$blank($e['num_note_affectation']??'',28)),0,0,'L');
$pdf->Cell($uw/2,6,ud("du/dated : ".$blank($fmt_date($e['date_note_affectation']??null),25)),0,1,'L');

$pdf->SetX($ml);
$pdf->Cell($uw,6,ud("Est effectivement en poste depuis le/Effectively in post since : ").$blank($fmt_date($e['date_prise_service']??null),25).ud(" (date de reprise/resumption date)"),0,1,'L');

$pdf->SetX($ml);
$pdf->Cell($uw,6,ud("En qualite de/In the capacity of : ".$blank($e['qualite']??'',40)),0,1,'L');

$pdf->SetX($ml);
$pdf->Cell($uw/2,6,ud("Diplome/Degree : ".$blank($e['diplome']??'',20)),0,0,'L');
$pdf->Cell($uw/2,6,ud("Specialite/Field : ".$blank($e['specialite']??'',20)),0,1,'L');

$pdf->SetX($ml);
$pdf->Cell($uw,6,ud("1ere prise de service (Admin.)/1st entry into service (Civil Service) : ").$blank($fmt_date($e['date_1ere_admin']??null),20),0,1,'L');

$pdf->SetX($ml);
$pdf->Cell($uw,6,ud("1ere prise de service (Etablt.)/1st entry into service (School) : ").$blank($fmt_date($e['date_1ere_etab']??null),25),0,1,'L');

$pdf->SetX($ml);
$pdf->Cell($uw,6,ud("Matiere effectivement enseignee/Subject actually taught : ".$blank($e['matiere_enseignee']??'',35)),0,1,'L');

$pdf->Ln(4);$pdf->SetFont('Arial','',9);$pdf->SetX($ml);
$pdf->MultiCell($uw,5.5,ud("En foi de quoi la presente attestation de presence effective au poste lui est delivree pour servir et valoir ce que de droit.\nWitness whereof this effective service attendance certificate has been issued to serve where and when necessary."),0,'L');

$pdf->Ln(6);
$y_sig_block = $pdf->GetY();
$pdf->SetX($ml+$uw*0.6);$pdf->SetFont('Arial','',9);
$pdf->Cell($uw*0.35,5,ud("Mbe, le/on ".date('d/m/Y').'.'),0,1,'R');
$pdf->SetX($ml+$uw*0.6);$pdf->SetFont('Arial','B',9);
$pdf->Cell($uw*0.35,5,'Le Proviseur / The Principal',0,1,'C');
// Signature numérique (uniquement si demandée à l'impression — jamais
// automatique — et si l'admin en a configuré une dans les paramètres) :
// placée dans l'espace blanc réservé à la signature manuscrite.
if (($_GET['signature'] ?? '0') === '1') {
    $sig_w = 24;
    $ph = $pdf->GetPageHeight();
    $sx = $ml+$uw*0.6+($uw*0.35-$sig_w)/2;
    $sy = $pdf->GetY()+1;
    pdf_signature_appliquer($pdf, 'attestation_enseignant', 'chef_etablissement', 0, 0, $pw, $ph, [
        'x_pct' => $sx / $pw * 100, 'y_pct' => $sy / $ph * 100, 'w_pct' => $sig_w / $pw * 100, 'h_pct' => null,
    ]);
}
$pdf->Ln(18);
$pdf->SetX($ml+$uw*0.6);$pdf->Cell($uw*0.35,0,'','T',1,'C');

// ── QR de vérification d'authenticité (propre à ce document : un QR
// d'attestation ne valide jamais un certificat de prise/reprise de service),
// aligné à gauche à la hauteur du bloc signature. Pas de photo incrustée :
// la table enseignant n'a pas de champ photo.
$qr_size = 18;
$qr_x = $ml;
$qr_y = $y_sig_block;
$verif_url = attestation_verif_url('attestation', $mat);
$qr_tmp = tempnam(sys_get_temp_dir(), 'abzqrat_') . '.png';
$qr_gen = new QRCode($verif_url, ['s' => 'qr-h']);
$qr_img = $qr_gen->render_image();
imagepng($qr_img, $qr_tmp);
imagedestroy($qr_img);
$pdf->Image($qr_tmp, $qr_x, $qr_y, $qr_size, $qr_size, 'PNG');
unlink($qr_tmp);
$pdf->SetFont('Arial', 'I', 6);
$pdf->SetXY($qr_x, $qr_y + $qr_size + 0.5);
$pdf->Cell($qr_size, 3, 'Scanner pour verifier', 0, 0, 'C');

$pdf->Output($dl?'D':'I', 'attestation_'.preg_replace('/\W/','_',$mat).'.pdf');
} catch (Throwable $e) {
    pdf_erreur_generation($e);
}
