<?php
/**
 * Certificat TABLEAU D'HONNEUR — modèle 1 "Classique" reproduit fidèlement
 * le PDF fourni par l'utilisateur (th.pdf) ; modèles 2 "Moderne" et 3
 * "Prestige" sont des variantes volontairement très différentes (couleurs,
 * typographie, composition) proposées au choix. QR code avec le même
 * mécanisme visuel que les bulletins (photo de l'élève incrustée au centre),
 * mais signature/vérification propres à ce document
 * (pdf/verif_honneur_lib.php::honneur_verif_hash()/honneur_verif_url()) : un
 * QR de tableau d'honneur ne doit jamais valider un bulletin.
 * GET : classe, trim|seq, annee, modele (1|2|3), eleve (optionnel — sans
 * lui, imprime TOUS les élèves qualifiés de la classe/période, une page
 * chacun), dl (0|1)
 */
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
require_once __DIR__ . '/../../pdf/verif_honneur_lib.php';

function u(string $s): string {
    return mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
}

$id_classe = (int)($_GET['classe'] ?? 0);
$id_trim   = (int)($_GET['trim']   ?? 0);
$id_seq    = (int)($_GET['seq']    ?? 0);
$id_annee  = (int)($_GET['annee']  ?? 0);
$id_eleve_filtre = (int)($_GET['eleve'] ?? 0);
$modele    = in_array((int)($_GET['modele'] ?? 1), [1, 2, 3], true) ? (int)($_GET['modele'] ?? 1) : 1;
$dl        = ($_GET['dl'] ?? '0') === '1';
$vh_verif  = (string)($_GET['vh'] ?? '');
$is_annee_mode = (($_GET['vue'] ?? '') === 'annee');

if (!$id_classe) die('Classe manquante.');
if (!$is_annee_mode && !$id_trim && !$id_seq) die('Periode manquante.');

// Accès public via le QR code du tableau d'honneur (jeton "vh" = hash de
// vérification déjà calculé pour ce certificat précis) : uniquement en mode
// mono-élève (eleve filtré) — jamais pour imprimer toute la classe. Cela
// court-circuite entièrement le contrôle de rôle ci-dessous, la personne qui
// scanne n'ayant pas forcément de compte dans le système (voir
// verif_honneur.php).
$acces_public = false;
if ($vh_verif !== '' && $id_eleve_filtre > 0) {
    if ($is_annee_mode) {
        $vue_verif_pub     = 'annee';
        $periode_verif_pub = $id_annee;
    } else {
        $vue_verif_pub     = ($id_seq > 0 && !$id_trim) ? 'seq' : 'trim';
        $periode_verif_pub = $vue_verif_pub === 'seq' ? $id_seq : $id_trim;
    }
    $eleve_verif = db_one("SELECT niu, matricule FROM eleve WHERE id=?", [$id_eleve_filtre]);
    if ($eleve_verif) {
        $acces_public = hash_equals(
            honneur_verif_hash($id_eleve_filtre, $vue_verif_pub, $periode_verif_pub, id_affichage_eleve($eleve_verif)),
            $vh_verif
        );
    }
}

if ($acces_public) {
    $is_ens = false; $mat_ens = null;
} else {
    exiger_connexion();
    $role     = role_connecte();
    $is_admin = in_array($role, ['ADMIN', 'PROVISEUR', 'FONDATEUR', 'CENSEUR']) || $role === 'MEMBRE_ASSOCIATION';
    $is_ens   = ($role === 'ENSEIGNANT');
    $mat_ens  = $is_ens ? get_matricule_ens_connecte() : null;
    if (!$is_admin && !$is_ens) die('Acces non autorise.');
}

$annee_act = get_annee_active();
if (!$id_annee) $id_annee = (int)($annee_act['id'] ?? 0);
$val_annee = $annee_act['libelle'] ?? '';

if (!$acces_public && $is_ens && $mat_ens) {
    $ok = db_val("SELECT COUNT(*) FROM enseignat_principal WHERE matricule_ens=? AND IDClasses=? AND val_annee=?", [$mat_ens, $id_classe, $val_annee]);
    if (!$ok) die('Acces refuse.');
}

$classe = db_one("SELECT * FROM classe WHERE id=?", [$id_classe]);
if (!$classe) die('Classe introuvable.');

$is_seq_mode = (!$is_annee_mode && $id_seq > 0 && !$id_trim);
if ($is_annee_mode) {
    $periode_libelle = "l'annee scolaire " . $val_annee;
} elseif ($is_seq_mode) {
    $seq_info = db_one("SELECT s.*, t.id AS id_trim, t.libelle AS trimestre FROM sequence s JOIN trimestre t ON t.id=s.id_trim WHERE s.id=?", [$id_seq]);
    if (!$seq_info) die('Sequence introuvable.');
    $id_trim = (int)$seq_info['id_trim'];
    $periode_libelle = $seq_info['libelle'];
} else {
    $trim = db_one("SELECT * FROM trimestre WHERE id=?", [$id_trim]);
    if (!$trim) die('Trimestre introuvable.');
    $periode_libelle = $trim['libelle'];
}
// Pour le QR (vérification, même mécanisme que les bulletins) : "vue" et
// "période" à faire correspondre à ce qu'attend verif_honneur.php.
$vue_verif        = $is_annee_mode ? 'annee' : ($is_seq_mode ? 'seq' : 'trim');
$id_periode_verif = $is_annee_mode ? $id_annee : ($is_seq_mode ? $id_seq : $id_trim);

$etab = get_etablissement();

// ── Élèves + moyennes/rangs de la classe pour cette période (même méthode
// que secondaire/pages/tableau_honneur/index.php et secondaire/pages/statistiques/*) ─────────
$eleves = db_all(
    "SELECT e.id, e.nom, e.prenom, e.sexe, e.matricule, e.niu, e.photo FROM eleve e
     JOIN inscription i ON i.id_eleve=e.id AND i.id_classe=? AND i.id_annee=?
     WHERE e.statut='actif'",
    [$id_classe, $id_annee]
);
$nb_inscrits = count($eleves);

// Règles 1/2/3/4 appliquées via le moteur commun (fonctions.php) — voir sa
// docblock (annuel : Règle 3 pv_moy_annuelle_comp() ; trimestre : classement
// direct). Remplace l'ancien calcul local "moyenne des moyennes existantes".
$moys = calc_moys_classe_periode_comp(
    $id_classe, $id_annee, $is_annee_mode ? 'annee' : 'trimestre', $is_annee_mode ? 0 : $id_trim,
    array_column($eleves, 'id')
);
arsort($moys);
$rangs = []; $rg = 1;
foreach ($moys as $eid => $m) $rangs[$eid] = $rg++;
$nb_classes_ = count($moys);

// Liste des élèves qualifiés au tableau d'honneur (Oui à partir du seuil
// de base, distingué Encouragement/Félicitations si applicable) — mêmes
// fonctions partagées que secondaire/pages/conseil_classe/ et secondaire/pages/statistiques/.
$qualifies = [];
foreach ($eleves as $el) {
    $eid = (int)$el['id'];
    if (!isset($moys[$eid])) continue;
    $moy = $moys[$eid];
    $abs_nj = $is_annee_mode
        ? (int) eleve_absence_annuelle($el['matricule'] ?? '', $id_classe, $id_annee)['non_jus']
        : (int) eleve_absence_trimestre($el['matricule'] ?? '', $id_trim, $id_classe, $val_annee)['non_jus'];
    if (pv_tableau_honneur($moy, $abs_nj) !== 'Oui') continue;
    if ($id_eleve_filtre && $eid !== $id_eleve_filtre) continue;
    $qualifies[] = [
        'eleve' => $el, 'moy' => $moy, 'rang' => $rangs[$eid],
        'felicitation'  => pv_felicitation($moy, $abs_nj) === 'Oui',
        'encouragement' => pv_encouragement($moy, $abs_nj) === 'Oui',
    ];
}
if ($id_eleve_filtre && empty($qualifies)) die("Cet eleve n'est pas au tableau d'honneur pour cette periode.");
if (empty($qualifies)) die("Aucun eleve au tableau d'honneur pour cette classe et cette periode.");
usort($qualifies, fn($a, $b) => $b['moy'] <=> $a['moy']);

$titre_periode_full = ($is_annee_mode || $is_seq_mode) ? $periode_libelle : ('le compte du ' . $periode_libelle);

require_once __DIR__ . '/../../pdf/fpdf.php';
require_once __DIR__ . '/../../pdf/header_pdf.php'; // pour pdf_filigrane()
require_once __DIR__ . '/../../pdf/qrcode.php';

class PDF_TH extends FPDF {
    function RoundedRect($x, $y, $w, $h, $r, $style = '') {
        $k = $this->k; $hp = $this->h;
        $op = $style === 'F' ? 'f' : ($style === 'FD' || $style === 'DF' ? 'B' : 'S');
        $myArc = 4 / 3 * (sqrt(2) - 1);
        $this->_out(sprintf('%.2F %.2F m', ($x + $r) * $k, ($hp - $y) * $k));
        $xc = $x + $w - $r; $yc = $y + $r;
        $this->_out(sprintf('%.2F %.2F l', $xc * $k, ($hp - $y) * $k));
        $this->_arcTH($xc + $r * $myArc, $yc - $r, $xc + $r, $yc - $r * $myArc, $xc + $r, $yc);
        $xc = $x + $w - $r; $yc = $y + $h - $r;
        $this->_out(sprintf('%.2F %.2F l', ($x + $w) * $k, ($hp - $yc) * $k));
        $this->_arcTH($xc + $r, $yc + $r * $myArc, $xc + $r * $myArc, $yc + $r, $xc, $yc + $r);
        $xc = $x + $r; $yc = $y + $h - $r;
        $this->_out(sprintf('%.2F %.2F l', $xc * $k, ($hp - ($y + $h)) * $k));
        $this->_arcTH($xc - $r * $myArc, $yc + $r, $xc - $r, $yc + $r * $myArc, $xc - $r, $yc);
        $xc = $x + $r; $yc = $y + $r;
        $this->_out(sprintf('%.2F %.2F l', $x * $k, ($hp - $yc) * $k));
        $this->_arcTH($xc - $r, $yc - $r * $myArc, $xc - $r * $myArc, $yc - $r, $xc, $yc - $r);
        $this->_out($op);
    }
    private function _arcTH($x1, $y1, $x2, $y2, $x3, $y3) {
        $h = $this->h;
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c ', $x1 * $this->k, ($h - $y1) * $this->k, $x2 * $this->k, ($h - $y2) * $this->k, $x3 * $this->k, ($h - $y3) * $this->k));
    }
    // Cercle approché par segments — utilisé pour le sceau du modèle
    // Prestige et le cadre photo du modèle Moderne (FPDF standard n'a pas
    // de primitive Ellipse/Circle).
    function CircleOutline(float $cx, float $cy, float $r, int $n = 72) {
        $prevX = $cx + $r; $prevY = $cy;
        for ($i = 1; $i <= $n; $i++) {
            $a = 2 * M_PI * $i / $n;
            $x = $cx + $r * cos($a); $y = $cy + $r * sin($a);
            $this->Line($prevX, $prevY, $x, $y);
            $prevX = $x; $prevY = $y;
        }
    }
    function CircleFill(float $cx, float $cy, float $r, int $n = 72) {
        $k = $this->k; $hp = $this->h;
        $this->_out(sprintf('%.2F %.2F m', ($cx + $r) * $k, ($hp - $cy) * $k));
        for ($i = 1; $i <= $n; $i++) {
            $a = 2 * M_PI * $i / $n;
            $x = $cx + $r * cos($a); $y = $cy + $r * sin($a);
            $this->_out(sprintf('%.2F %.2F l', $x * $k, ($hp - $y) * $k));
        }
        $this->_out('f');
    }
}

function checkbox_th(FPDF $pdf, float $x, float $y, bool $checked, float $s = 6): void {
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(0.5);
    $pdf->Rect($x, $y, $s, $s);
    if ($checked) {
        $pdf->SetLineWidth(0.6);
        $pdf->Line($x + 0.6, $y + 0.6, $x + $s - 0.6, $y + $s - 0.6);
        $pdf->Line($x + $s - 0.6, $y + 0.6, $x + 0.6, $y + $s - 0.6);
    }
    $pdf->SetLineWidth(0.2);
}

// Badge arrondi plein (modèle 2 "Moderne") — remplace les cases à cocher
// par une étiquette colorée unique correspondant à la mention obtenue,
// plus lisible/actuel qu'une paire de cases.
function badge_mention(FPDF $pdf, float $x, float $y, string $texte, array $couleur): void {
    $pdf->SetFont('Arial', 'B', 12);
    $w = $pdf->GetStringWidth(u($texte)) + 14;
    $h = 10;
    $pdf->SetFillColor($couleur[0], $couleur[1], $couleur[2]);
    $pdf->RoundedRect($x, $y, $w, $h, $h / 2, 'F');
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetXY($x, $y);
    $pdf->Cell($w, $h, u($texte), 0, 0, 'C');
    $pdf->SetTextColor(0, 0, 0);
}

// Dessine un titre où chaque caractère alterne de couleur (fidélité au
// modèle fourni : "TABLEAU D'HONNEUR" est imprimé en plusieurs couleurs,
// lettre par lettre) — modèle 1 uniquement.
function titre_multicolore(FPDF $pdf, float $cx, float $y, string $texte, float $taille): void {
    $palette = [[128, 0, 160], [0, 70, 200], [200, 0, 130], [230, 120, 0], [200, 0, 0], [0, 140, 60], [0, 130, 160]];
    $pdf->SetFont('Arial', 'BI', $taille);
    $total_w = $pdf->GetStringWidth($texte);
    $x = $cx - $total_w / 2;
    foreach (str_split($texte) as $i => $ch) {
        [$r, $g, $b] = $palette[$i % count($palette)];
        $pdf->SetTextColor($r, $g, $b);
        $pdf->SetXY($x, $y);
        $pdf->Cell($pdf->GetStringWidth($ch) + 0.3, $taille * 0.5, $ch, 0, 0, 'L');
        $x += $pdf->GetStringWidth($ch);
    }
    $pdf->SetTextColor(0, 0, 0);
}

// Photo de l'élève (chemin réel ou avatar par défaut selon le sexe — même
// repli que les bulletins).
function photo_eleve_th(array $el): string {
    $photo_path = !empty($el['photo']) ? __DIR__ . '/../../../assets/uploads/eleves/' . $el['photo'] : '';
    if ($photo_path && is_file($photo_path)) return $photo_path;
    $avatar = (strtoupper($el['sexe'] ?? '') === 'F') ? 'fille.png' : 'garcon.png';
    return __DIR__ . '/../../../assets/img/avatars/' . $avatar;
}

// Version recadrée en cercle (canal alpha) d'une photo — FPDF ne supporte
// pas le "clipping", il faut donc pré-découper l'image elle-même (masque
// circulaire pixel par pixel sur un PNG transparent) pour un rendu
// réellement circulaire (modèle 2 "Moderne"), au lieu d'un carré qui
// déborderait du cadre rond. Fichier temporaire à supprimer par l'appelant.
function photo_circulaire_th(string $photo_path, int $taille_px = 240): string {
    $src = @imagecreatefromstring(file_get_contents($photo_path));
    if (!$src) return $photo_path;
    $sw = imagesx($src); $sh = imagesy($src);
    $cote = min($sw, $sh);
    $sx = (int)(($sw - $cote) / 2); $sy = (int)(($sh - $cote) / 2);

    $dst = imagecreatetruecolor($taille_px, $taille_px);
    imagesavealpha($dst, true);
    $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    imagefill($dst, 0, 0, $transparent);
    imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $taille_px, $taille_px, $cote, $cote);

    $r = $taille_px / 2;
    for ($y = 0; $y < $taille_px; $y++) {
        for ($x = 0; $x < $taille_px; $x++) {
            if ((($x - $r) ** 2 + ($y - $r) ** 2) > $r * $r) imagesetpixel($dst, $x, $y, $transparent);
        }
    }
    imagedestroy($src);
    $tmp = tempnam(sys_get_temp_dir(), 'abzcirc_') . '.png';
    imagepng($dst, $tmp);
    imagedestroy($dst);
    return $tmp;
}

// QR code de vérification — même mécanisme visuel que les bulletins
// (correction d'erreur élevée 'qr-h', tolère la photo incrustée), mais
// signature/vérification propres au tableau d'honneur
// (pdf/verif_honneur_lib.php) : scanner ce QR confirme l'authenticité de
// CE certificat, sans jamais rediriger vers le bulletin de l'élève.
function dessiner_qr_th(FPDF $pdf, array $el, string $vue_verif, int $id_periode_verif, float $x, float $y, float $size): void {
    $verif_url = honneur_verif_url((int)$el['id'], $vue_verif, $id_periode_verif, id_affichage_eleve($el));
    $qr_tmp = tempnam(sys_get_temp_dir(), 'abzqrth_') . '.png';
    try {
        $qr_gen = new QRCode($verif_url, ['s' => 'qr-h']);
        $qr_img = $qr_gen->render_image();
        qr_incruster_photo_th($qr_img, photo_eleve_th($el));
        imagepng($qr_img, $qr_tmp);
        imagedestroy($qr_img);
        $pdf->Image($qr_tmp, $x, $y, $size, $size, 'PNG');
    } finally {
        if (is_file($qr_tmp)) unlink($qr_tmp);
    }
}
// Incruste la photo au centre du QR (voir secondaire/pages/bulletins/pdf.php pour le
// jumeau exact, qr_incruster_photo() — dupliquée ici par convention du projet).
function qr_incruster_photo_th($qr_img, string $photo_path): void {
    if ($photo_path === '' || !is_file($photo_path)) return;
    $photo = @imagecreatefromstring(file_get_contents($photo_path));
    if (!$photo) return;
    $w = imagesx($qr_img); $h = imagesy($qr_img);
    $logo_size = (int)round($w * 0.22);
    $pad = (int)round($w * 0.012);
    $box = $logo_size + $pad * 2;
    $bx = (int)(($w - $box) / 2); $by = (int)(($h - $box) / 2);
    $blanc = imagecolorallocate($qr_img, 255, 255, 255);
    imagefilledrectangle($qr_img, $bx, $by, $bx + $box - 1, $by + $box - 1, $blanc);
    $pw = imagesx($photo); $ph = imagesy($photo);
    $cote = min($pw, $ph);
    $sx = (int)(($pw - $cote) / 2); $sy = (int)(($ph - $cote) / 2);
    imagecopyresampled($qr_img, $photo, $bx + $pad, $by + $pad, $sx, $sy, $logo_size, $logo_size, $cote, $cote);
    imagedestroy($photo);
}

$pdf = new PDF_TH('L', 'mm', 'A4');
$pdf->SetMargins(8, 8, 8);
$pdf->SetAutoPageBreak(false);

foreach ($qualifies as $q) {
    $el = $q['eleve'];
    $pdf->AddPage();
    $pw = $pdf->GetPageWidth(); $ph = $pdf->GetPageHeight();
    $ml = 10; $uw = $pw - 2 * $ml;
    $mention_lib = $q['felicitation'] ? 'Félicitations' : ($q['encouragement'] ? 'Encouragement' : "Tableau d'honneur");

    

    $logo_src_path = !empty($etab['logo']) ? __DIR__ . '/../../../assets/uploads/' . $etab['logo'] : '';

    // ══════════════════════════════════════════════════════════════════
    // MODÈLE 1 — "Classique" : reproduction fidèle du modèle fourni.
    // ══════════════════════════════════════════════════════════════════
    if ($modele === 1) {
        $pdf->SetDrawColor(160, 200, 235); $pdf->SetLineWidth(1.4);
        $pdf->RoundedRect(4, 4, $pw - 8, $ph - 8, 3, 'D');
        $pdf->SetLineWidth(0.2); $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetFillColor(160, 200, 235);
        foreach ([[8, 8], [$pw - 16, 8], [8, $ph - 16], [$pw - 16, $ph - 16]] as [$cx, $cy]) {
           // $pdf->Rect($cx, $cy, 8, 8, 'F');
            $pdf->SetFillColor(255, 255, 255);
            $pdf->Rect($cx + 2, $cy + 2, 4, 4, 'F');
            $pdf->SetFillColor(160, 200, 235);
        }

		
		
		$filigrane_path = __DIR__ . '/../../../assets/uploads/bordure1.jpg';
		$pdf->Image($filigrane_path,2,2,293,207);
		
		
		// ── Filigrane logo en fond (fonction centralisée) ─────────────────
		pdf_filigrane($pdf, $etab, $pw, $ph, 180);
		
		
		
		
		
        $col3 = $uw / 3;
        $y0 = 12;
        $pdf->SetXY($ml, $y0);
        $pdf->SetFont('Arial', '', 9);
		
		$pdf->MultiCell($col3, 3.5, u(
		"REPUBLIQUE DU CAMEROUN\nPaix - Travail - Patrie\n***************\n" .
		($etab['region_fr'] ?? "VOTRE REGION ICI") . "\n" .
		($etab['departement_fr'] ?? 'DEPARTEMENT ') . "\n" .
		($etab['arrondissement_fr'] ?? 'ARRONDISSEMENT') . "\n" .
		strtoupper($etab['nom_fr'] ?? 'NOM ECOLE ') . "\n" .
		"B.P. " . ($etab['boite_postale'] ?? 'XX') . " ville Tel.: " . ($etab['telephone'] ?? '') . "\n" .
		($etab['email'] ?? 'adresse email')
	), 0, 'C');

		
        /*$pdf->MultiCell($col3, 3.6, u("REPUBLIQUE DU CAMEROUN\nREGION DE L'ADAMAOUA\nDEPARTEMENT DE LA VINA\nARRONDISSEMENT DE MBE"), 0, 'L');
        $pdf->SetFont('Arial', 'B', 9.5);
        $pdf->SetX($ml);
        $pdf->Cell($col3, 5, u(strtoupper($etab['nom_fr'] ?? 'LYCEE TECHNIQUE DE MBE')), 0, 1, 'L');
        $pdf->SetFont('Arial', '', 7);
        $pdf->SetX($ml);
        $pdf->Cell($col3, 3.5, u('B.P. ' . ($etab['boite_postale'] ?? '') . '  Tel.: ' . ($etab['telephone'] ?? '')), 0, 1, 'L');
        $pdf->SetX($ml);
        $pdf->Cell($col3, 3.5, u($etab['email'] ?? ''), 0, 1, 'L');
        $pdf->Ln(1);*/
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetX($ml+20);
        $pdf->Cell($col3 * 0.6, 5, u('Année scolaire: ' . $val_annee), 0, 1, 'L');
        $pdf->SetFont('Arial', 'I', 9);
        $pdf->SetX($ml+20);
        $pdf->Cell($col3 * 0.6, 1, u('School Year'), 0, 1, 'L');

        $logo_w = 32; $logo_h = $logo_w;
        if ($logo_src_path && is_file($logo_src_path)) {
            $dim = @getimagesize($logo_src_path);
            if ($dim && $dim[0] > 0) $logo_h = $logo_w * $dim[1] / $dim[0];
            $pdf->Image($logo_src_path, $ml + $col3 + ($col3 - $logo_w) / 2, $y0, $logo_w);
        }

        $xr = $ml + $col3 * 2;
        $pdf->SetXY($xr, $y0);
        $pdf->SetFont('Arial', '', 9);
        $pdf->MultiCell($col3, 3.5, u(
			"REPUBLIC OF CAMEROON\nPeace - Work - Fatherland\n***************\n" .
			($etab['region_en'] ?? 'ADAMAWA REGION') . "\n" .
			($etab['division_en'] ?? 'VINA DIVISION') . "\n" .
			($etab['subdivision_en'] ?? 'MBE SUBDIVISION') . "\n" .
			strtoupper($etab['nom_en'] ?? 'GTHS OF MBE') . "\n" .
			"P.O. BOX. " . ($etab['boite_postale'] ?? '32') . " Mbe  Phone: " . ($etab['telephone'] ?? '') . "\n" .
			($etab['email'] ?? 'lyceetechniquembe@yahoo.fr')
		),
		0, 'C');

		$pdf->SetY(max($pdf->GetY(), 33)-2.5);
		$pdf->SetFont('Arial', 'I', 9);
		$pdf->SetX($ml);
		$pdf->Cell($uw, 4, u('IMMATRICULATION : ' . ($etab['immatriculation'] ?? '2JH1TEFD110316102')), 0, 1, 'C');


        $photo_w = 38; $photo_h = 38;
        $photo_x = $pw - $ml - $photo_w-15; $photo_y = $y0 + 33;
        $photo_path = photo_eleve_th($el);
        $pdf->SetDrawColor(26, 60, 107); $pdf->SetLineWidth(0.5);
        $pdf->Rect($photo_x, $photo_y, $photo_w, $photo_h);
        $pdf->SetLineWidth(0.2); $pdf->SetDrawColor(0, 0, 0);
        if (is_file($photo_path)) $pdf->Image($photo_path, $photo_x + 0.6, $photo_y + 0.6, $photo_w - 1.2, $photo_h - 1.2);

        $y_titre = $y0 + 34;
        $w_bandeau = $uw - $photo_w - 8;
        $h_bandeau = 14;
        $pdf->SetFillColor(210, 232, 250);
        $pdf->SetDrawColor(90, 150, 210); $pdf->SetLineWidth(0.4);
        $pdf->RoundedRect($ml+72, $y_titre, $w_bandeau-100, $h_bandeau, $h_bandeau-1 , 'FD');
        $pdf->SetLineWidth(0.2); $pdf->SetDrawColor(0, 0, 0);
        titre_multicolore($pdf, ($ml + $w_bandeau / 2)+20, $y_titre -1.8, u('TABLEAU D\'HONNEUR'), 28);
		 $pdf->SetFont('Arial', 'BI', 17);
		$pdf->Text(($ml + $w_bandeau / 2)-0,$y_titre+13,"HONOR ROLL");

        $y_case = $y_titre + $h_bandeau + 4;
        checkbox_th($pdf, $ml+30, $y_case, $q['encouragement']);
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->SetTextColor(20, 70, 190);
        $pdf->SetXY($ml + 38, $y_case - 1);
        $pdf->Cell(70, 8, u('ENCOURAGEMENT'), 0, 0, 'L');
        $x_felicit = $ml + 90;
        checkbox_th($pdf, $x_felicit+60, $y_case, $q['felicitation']);
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->SetTextColor(200, 0, 0);
        $pdf->SetXY($x_felicit + 68, $y_case - 1);
        $pdf->Cell(70, 8, u('FELICITATIONS'), 0, 0, 'L');
        $pdf->SetTextColor(0, 0, 0);

        $y_sub = $y_case + 12;
        $pdf->SetFont('Arial', 'BIU', 14);
        $pdf->SetTextColor(0, 120, 40);
        $pdf->SetXY($ml+40, $y_sub);
        $pdf->Cell($uw, 6, u('POUR SON TRAVAIL TRES SATISFAISANT ET SA CONDUITE EXEMPLAIRE'), 0, 1, 'L');
		 $pdf->SetTextColor(0, 0, 0);
		$pdf->SetXY($ml+80, $y_sub+4.5);
		$pdf->SetFont('Arial', 'I', 12);
		//$pdf->Cell($uw, 6, u('For his/her outstanding work and exemplary conduct.'), 0, 1, 'L');
       

        $y_par = $y_sub + 12;
		$ml=$ml+11;
        $pdf->SetFont('Arial', '', 14);
        $pdf->SetXY($ml, $y_par);
		
		$pdf->SetFont('arial','',15);
		$pdf->MultiCell(268,8,u("                      ".strtoupper($el['nom']) . ' ' . ($el['prenom'] ?? '')."                           ".$classe['designation'])." ",'','L',0);
		
		$pdf->SetXY($ml, $y_par);
		$pdf->MultiCell(268,8, u("         L'élève  ".strtoupper($el['nom']) . ' ' . ($el['prenom'] ?? '')."  de la classe de ".$classe['designation']."  est inscrit(e) sur décision du conseil de classe au TABLEAU D'HONNEUR " . ($is_annee_mode ? 'pour ' : 'pour le compte du ') . $titre_periode_full . ($is_annee_mode ? '' : (" de l'année scolaire " . $val_annee)) . ".\n         Cette reconnaissance prestigieuse de son MERITE est aussi et surtout un appel à l'EXCELLENCE."),'','L',0);
		
		
		
		
        /*$pdf->Write(6, u("   L'élève  "));
        $pdf->SetFont('Arial', 'B', 14);
        $pdf->Write(6, u(strtoupper($el['nom']) . ' ' . ($el['prenom'] ?? '')));
        $pdf->SetFont('Arial', '', 14);
        $pdf->Write(6, u(' de la classe de '));
        $pdf->SetFont('Arial', 'B', 14);
        $pdf->Write(6, u($classe['designation']));
        $pdf->SetFont('Arial', '', 14);
        $pdf->Write(6, u(" est inscrit(e) sur decision du conseil"));
        $pdf->SetXY($ml, $y_par + 6);
        $pdf->Cell($uw, 6, u("de classe au TABLEAU D'HONNEUR pour " . $titre_periode_full . " de l'annee scolaire " . $val_annee . "."), 0, 1, 'L');
        $pdf->SetX($ml);
        $pdf->Cell($uw, 6, u("Cette reconnaissance prestigieuse de son MERITE est aussi et surtout un appel a l'EXCELLENCE."), 0, 1, 'L');*/

        $y_box = $y_par + 32;
        $w_box =38; $h_box = 9;
        $pdf->SetFont('Arial', 'B', 18);
        $pdf->SetXY($ml+18, $y_box+5);
        $pdf->Cell($w_box, 5, u('MOYENNE'), 0, 1, 'L');
        $pdf->SetFillColor(120, 190, 235);
        $pdf->SetXY($ml+16, $y_box + 10);
        $pdf->SetFont('Arial', 'B', 18);
        $pdf->Cell($w_box, $h_box, u(number_format($q['moy'], 2) . '/20'), 1, 0, 'C', true);

        $x_rang = $ml + 100;
        $pdf->SetFont('Arial', 'B', 18);
        $pdf->SetXY($x_rang+7, $y_box+5);
        $pdf->Cell($w_box, 5, u('RANG'), 0, 1, 'L');
        $pdf->SetXY($x_rang, $y_box + 10);
       // $pdf->SetFont('Arial', 'B', 13);
        $pdf->Cell($w_box, $h_box, u($q['rang'] . 'e /' . $nb_classes_), 1, 0, 'C', true);

        $pdf->SetFont('Arial', 'I', 12);
        $pdf->SetXY($ml + 200, $y_box + 13);
        $pdf->Cell($uw - 130, 6, u( $etab['ville'].   ', le ' . date('d-m-Y') . '.'), 0, 1, 'L');

        $y_sig = $y_box + $h_box + 14;
        $pdf->SetFont('Arial', 'BU', 14);
        $w3 = ($uw / 3)-15;
        $pdf->SetTextColor(0, 120, 40);
        $pdf->SetXY($ml, $y_sig);
        $pdf->Cell($w3, 6, u('LE RECIPIENDAIRE,'), 0, 0, 'L');
        $pdf->SetTextColor(20, 70, 190);
        $pdf->Cell($w3, 6, u('LE PRINCIPAL,'), 0, 0, 'C');
        $pdf->SetTextColor(200, 0, 0);
        $pdf->Cell($w3, 6, u(strtoupper($etab['chef_etablissement'] ?? 'LE PROVISEUR') . ','), 0, 1, 'R');
        $pdf->SetTextColor(0, 0, 0);

        // Signature numérique (sur demande uniquement, jamais automatique).
        if (($_GET['signature'] ?? '0') === '1') {
            $sig_w = min(24, $w3 * 0.5);
            $sx = $ml + $w3 * 2 + ($w3 - $sig_w) / 2;
            $sy = $y_sig + 6.5;
            pdf_signature_appliquer($pdf, 'tableau_honneur_1', 'chef_etablissement', 0, 0, $pw, $ph, [
                'x_pct' => $sx / $pw * 100, 'y_pct' => $sy / $ph * 100, 'w_pct' => $sig_w / $pw * 100, 'h_pct' => null,
            ]);
        }

        $qr_size = 26;
        dessiner_qr_th($pdf, $el, $vue_verif, $id_periode_verif, $pw / 2 - $qr_size / 2, $ph - $qr_size - 12, $qr_size);
        $pdf->SetFont('Arial', '', 6.5);
        $pdf->SetXY($ml-10, $ph - 13);
        $pdf->Cell($uw, 4, u('Copyright © SIGES ABZ , E-mail: abdoulazizyahya@gmail.com'), 0, 1, 'C');

		
		
		
    // ══════════════════════════════════════════════════════════════════
    // MODÈLE 2 — "Moderne" : bandeau plein, badge coloré unique, cartes
    // statistiques arrondies, composition très différente du modèle 1.
    // ══════════════════════════════════════════════════════════════════
    } elseif ($modele === 2) {
        $teal = [13, 148, 136]; $gold = [202, 138, 4];
        $pdf->SetDrawColor($teal[0], $teal[1], $teal[2]); $pdf->SetLineWidth(1);
        $pdf->Rect(6, 6, $pw - 12, $ph - 12);
        $pdf->SetDrawColor($gold[0], $gold[1], $gold[2]); $pdf->SetLineWidth(0.3);
        $pdf->Rect(8.5, 8.5, $pw - 17, $ph - 17);
        $pdf->SetLineWidth(0.2); $pdf->SetDrawColor(0, 0, 0);

        pdf_filigrane($pdf, $etab, $pw, $ph, 180);

        // Bandeau plein en haut (au lieu du bandeau pilule du modèle 1).
        $pdf->SetFillColor($teal[0], $teal[1], $teal[2]);
        $pdf->Rect(6, 6, $pw - 12, 24, 'F');
        $logo_w = 20; $logo_h = $logo_w;
        if ($logo_src_path && is_file($logo_src_path)) {
            $dim = @getimagesize($logo_src_path);
            if ($dim && $dim[0] > 0) $logo_h = $logo_w * $dim[1] / $dim[0];
            $pdf->SetFillColor(255, 255, 255);
            $pdf->CircleFill($ml + 12, 18, 12);
            $pdf->Image($logo_src_path, $ml + 12 - $logo_w / 2, 18 - $logo_h / 2, $logo_w);
        }
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY($ml + 28, 10);
        $pdf->SetFont('Arial', '', 7.5);
        $pdf->Cell($uw - 56, 3.5, u(strtoupper($etab['nom_fr'] ?? 'LYCEE TECHNIQUE DE MBE') . ' — ' . strtoupper($etab['nom_en'] ?? 'GTHS OF MBE')), 0, 1, 'C');
        $pdf->SetX($ml + 28);
        $pdf->SetFont('Arial', 'B', 20);
        $pdf->Cell($uw - 56, 12, u('TABLEAU D\'HONNEUR'), 0, 1, 'C');
        $pdf->SetX($ml + 28);
        $pdf->SetFont('Arial', 'I', 8);
        $pdf->Cell($uw - 56, 4, u('Honor Roll — Annee scolaire ' . $val_annee), 0, 1, 'C');
        $pdf->SetTextColor(0, 0, 0);

        // Photo circulaire (cadre) à droite du bandeau — vrai recadrage
        // circulaire (canal alpha), pas un carré posé sur un disque blanc.
        $photo_d = 22;
        $photo_cx = $pw - $ml - $photo_d / 2 - 2; $photo_cy = 18;
        $pdf->SetFillColor(255, 255, 255);
        $pdf->CircleFill($photo_cx, $photo_cy, $photo_d / 2 + 1.2);
        $photo_path = photo_eleve_th($el);
        $photo_circ_tmp = is_file($photo_path) ? photo_circulaire_th($photo_path) : '';
        if ($photo_circ_tmp && is_file($photo_circ_tmp)) {
            $pdf->Image($photo_circ_tmp, $photo_cx - $photo_d / 2, $photo_cy - $photo_d / 2, $photo_d, $photo_d, 'PNG');
            unlink($photo_circ_tmp);
        }
        $pdf->SetDrawColor($gold[0], $gold[1], $gold[2]); $pdf->SetLineWidth(0.6);
        $pdf->CircleOutline($photo_cx, $photo_cy, $photo_d / 2 + 1.2);
        $pdf->SetLineWidth(0.2); $pdf->SetDrawColor(0, 0, 0);

        // Badge de mention unique (remplace les 2 cases à cocher).
        $y_badge = 34;
        $couleur_badge = $q['felicitation'] ? [190, 30, 30] : ($q['encouragement'] ? $teal : [100, 100, 110]);
        badge_mention($pdf, $ml, $y_badge, strtoupper($mention_lib), $couleur_badge);

        // Sous-titre.
        $pdf->SetFont('Arial', 'I', 10.5);
        $pdf->SetTextColor(80, 80, 80);
        $pdf->SetXY($ml, $y_badge + 13);
        $pdf->Cell($uw, 5, u('Pour son travail tres satisfaisant et sa conduite exemplaire'), 0, 1, 'L');
        $pdf->SetTextColor(0, 0, 0);

        // Paragraphe.
        $y_par = $y_badge + 22;
        $pdf->SetFont('Arial', '', 11);
        $pdf->SetXY($ml, $y_par);
        $pdf->Write(6, u("L'eleve "));
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Write(6, u(strtoupper($el['nom']) . ' ' . ($el['prenom'] ?? '')));
        $pdf->SetFont('Arial', '', 11);
        $pdf->Write(6, u(' de la classe de '));
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Write(6, u($classe['designation']));
        $pdf->SetFont('Arial', '', 11);
        $pdf->Write(6, u(" figure au tableau d'honneur"));
        $pdf->SetXY($ml, $y_par + 6);
        $pdf->Cell($uw, 6, u("pour " . $titre_periode_full . ($is_annee_mode ? '' : (" de l'annee scolaire " . $val_annee)) . ", en reconnaissance de son merite."), 0, 1, 'L');

        // Cartes statistiques arrondies (Moyenne / Rang) — remplacent les
        // cases bleues carrées du modèle 1.
        $y_card = $y_par + 16;
        $w_card = 55; $h_card = 22;
        $pdf->SetFillColor(240, 253, 250);
        $pdf->SetDrawColor($teal[0], $teal[1], $teal[2]); $pdf->SetLineWidth(0.5);
        $pdf->RoundedRect($ml, $y_card, $w_card, $h_card, 3, 'FD');
        $pdf->SetXY($ml, $y_card + 2);
        $pdf->SetFont('Arial', '', 8);
        $pdf->SetTextColor(80, 80, 80);
        $pdf->Cell($w_card, 4, u('MOYENNE'), 0, 1, 'C');
        $pdf->SetTextColor($teal[0], $teal[1], $teal[2]);
        $pdf->SetFont('Arial', 'B', 15);
        $pdf->SetX($ml);
        $pdf->Cell($w_card, 12, u(number_format($q['moy'], 2) . '/20'), 0, 1, 'C');

        $x_card2 = $ml + $w_card + 8;
        $pdf->SetFillColor(255, 251, 235);
        $pdf->SetDrawColor($gold[0], $gold[1], $gold[2]);
        $pdf->RoundedRect($x_card2, $y_card, $w_card, $h_card, 3, 'FD');
        $pdf->SetXY($x_card2, $y_card + 2);
        $pdf->SetFont('Arial', '', 8);
        $pdf->SetTextColor(80, 80, 80);
        $pdf->Cell($w_card, 4, u('RANG'), 0, 1, 'C');
        $pdf->SetTextColor($gold[0], $gold[1], $gold[2]);
        $pdf->SetFont('Arial', 'B', 15);
        $pdf->SetX($x_card2);
        $pdf->Cell($w_card, 12, u($q['rang'] . 'e / ' . $nb_classes_), 0, 1, 'C');
        $pdf->SetDrawColor(0, 0, 0); $pdf->SetTextColor(0, 0, 0);

        $pdf->SetFont('Arial', 'I', 9.5);
        $pdf->SetXY($x_card2 + $w_card + 10, $y_card + 9);
        $pdf->Cell($uw - ($x_card2 + $w_card + 10 - $ml), 5, u('Mbe, le ' . date('d/m/Y')), 0, 1, 'L');

        // Signatures — traits colorés fins au lieu de texte souligné.
        $y_sig = $y_card + $h_card + 16;
        $w3 = $uw / 3;
        foreach ([['LE RECIPIENDAIRE', $teal], ['LE PRINCIPAL', $gold], ['LE PROVISEUR', [190, 30, 30]]] as $i => [$lbl, $col]) {
            $x0 = $ml + $i * $w3;
            $pdf->SetDrawColor($col[0], $col[1], $col[2]); $pdf->SetLineWidth(0.7);
            $pdf->Line($x0 + 8, $y_sig, $x0 + $w3 - 8, $y_sig);
            $pdf->SetLineWidth(0.2); $pdf->SetDrawColor(0, 0, 0);
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->SetTextColor($col[0], $col[1], $col[2]);
            $pdf->SetXY($x0, $y_sig + 1.5);
            // $lbl reste l'identifiant de comparaison ci-dessous ('LE
            // PROVISEUR' déclenche la signature) ; seul le texte AFFICHÉ
            // devient le nom réel du chef d'établissement.
            $texte_signataire = $lbl === 'LE PROVISEUR'
                ? strtoupper($etab['chef_etablissement'] ?? 'LE PROVISEUR') : $lbl;
            $pdf->Cell($w3, 5, u($texte_signataire), 0, 0, 'C');
            if ($lbl === 'LE PROVISEUR' && ($_GET['signature'] ?? '0') === '1') {
                $sig_w = min(20, $w3 * 0.5);
                $sx = $x0 + ($w3 - $sig_w) / 2;
                $sy = $y_sig + 7;
                pdf_signature_appliquer($pdf, 'tableau_honneur_2', 'chef_etablissement', 0, 0, $pw, $ph, [
                    'x_pct' => $sx / $pw * 100, 'y_pct' => $sy / $ph * 100, 'w_pct' => $sig_w / $pw * 100, 'h_pct' => null,
                ]);
            }
        }
        $pdf->SetTextColor(0, 0, 0);

        $qr_size = 18;
        dessiner_qr_th($pdf, $el, $vue_verif, $id_periode_verif, $pw / 2 - $qr_size / 2, $ph - $qr_size - 12, $qr_size);
        $pdf->SetFont('Arial', '', 6.5);
        $pdf->SetXY($ml, $ph - 10);
        $pdf->Cell($uw, 4, u('Copyright © SIGES ABZ , E-mail: abdoulazizyahya@gmail.com'), 0, 1, 'C');

    // ══════════════════════════════════════════════════════════════════
    // MODÈLE 3 — "Prestige" : police Times, bordure double filet or/marine,
    // sceau circulaire, composition centrée façon diplôme d'honneur.
    // ══════════════════════════════════════════════════════════════════
    } else {
        $or = [180, 150, 60]; $marine = [26, 60, 107];
        $pdf->SetDrawColor($or[0], $or[1], $or[2]); $pdf->SetLineWidth(1.3);
        $pdf->Rect(6, 6, $pw - 12, $ph - 12);
        $pdf->SetDrawColor($marine[0], $marine[1], $marine[2]); $pdf->SetLineWidth(0.4);
        $pdf->Rect(9, 9, $pw - 18, $ph - 18);
        // Fleurons d'angle (quarts de cercle dorés).
        $pdf->SetDrawColor($or[0], $or[1], $or[2]); $pdf->SetLineWidth(0.5);
        foreach ([[13, 13], [$pw - 13, 13], [13, $ph - 13], [$pw - 13, $ph - 13]] as [$cx, $cy]) {
            $pdf->CircleOutline($cx, $cy, 4, 36);
        }
        $pdf->SetLineWidth(0.2); $pdf->SetDrawColor(0, 0, 0);

        pdf_filigrane($pdf, $etab, $pw, $ph, 180);

        $pdf->SetY(16);
        $pdf->SetFont('Times', '', 9);
        $pdf->Cell($uw, 4.5, u("REPUBLIQUE DU CAMEROUN — Paix - Travail - Patrie"), 0, 1, 'C');
        $pdf->SetFont('Times', 'B', 12);
        $pdf->Cell($uw, 6, u(strtoupper($etab['nom_fr'] ?? 'LYCEE TECHNIQUE DE MBE')), 0, 1, 'C');
        $pdf->SetFont('Times', 'I', 8.5);
        $pdf->Cell($uw, 4.5, u('Annee scolaire ' . $val_annee), 0, 1, 'C');

        $logo_w = 20; $logo_h = $logo_w;
        if ($logo_src_path && is_file($logo_src_path)) {
            $dim = @getimagesize($logo_src_path);
            if ($dim && $dim[0] > 0) $logo_h = $logo_w * $dim[1] / $dim[0];
            $pdf->Image($logo_src_path, $pw / 2 - $logo_w / 2, $pdf->GetY() + 2, $logo_w);
        }
        $pdf->Ln($logo_h + 6);

        $pdf->SetFont('Times', 'B', 28);
        $pdf->SetTextColor($marine[0], $marine[1], $marine[2]);
        $pdf->Cell($uw, 13, u('TABLEAU D\'HONNEUR'), 0, 1, 'C');
        $pdf->SetTextColor(0, 0, 0);
        // Filet doré orné d'un losange central sous le titre.
        $y_filet = $pdf->GetY() + 1;
        $pdf->SetDrawColor($or[0], $or[1], $or[2]); $pdf->SetLineWidth(0.5);
        $pdf->Line($pw / 2 - 45, $y_filet, $pw / 2 - 3, $y_filet);
        $pdf->Line($pw / 2 + 3, $y_filet, $pw / 2 + 45, $y_filet);
        $pdf->SetFillColor($or[0], $or[1], $or[2]);
        $pdf->SetXY($pw / 2 - 2, $y_filet - 2);
        $pdf->Cell(4, 4, '', 0, 0, 'C', false);
        $pdf->CircleOutline($pw / 2, $y_filet, 2, 24);
        $pdf->SetDrawColor(0, 0, 0); $pdf->SetLineWidth(0.2);
        $pdf->Ln(8);

        $pdf->SetFont('Times', 'I', 11);
        $pdf->Cell($uw, 6, u('Ce certificat est decerne a'), 0, 1, 'C');
        $pdf->SetFont('Times', 'BU', 18);
        $pdf->Cell($uw, 10, u(strtoupper($el['nom']) . ' ' . ($el['prenom'] ?? '')), 0, 1, 'C');
        $pdf->SetFont('Times', '', 11.5);
        $pdf->Cell($uw, 6, u('eleve de la classe de ' . $classe['designation']), 0, 1, 'C');
        $pdf->SetFont('Times', 'B', 13);
        $pdf->SetTextColor($or[0], $or[1], $or[2]);
        $pdf->Cell($uw, 8, u(strtoupper($mention_lib)), 0, 1, 'C');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Times', '', 11);
        $pdf->MultiCell($uw, 5.5, u(
            "pour son merite scolaire " . ($is_annee_mode ? ('de ' . $periode_libelle) : ('du ' . $periode_libelle . " de l'annee " . $val_annee)) . ",\n" .
            "avec une moyenne de " . number_format($q['moy'], 2) . "/20 et un rang de " . $q['rang'] . "e sur " . $nb_classes_ . "."
        ), 0, 'C');

        // Sceau circulaire (médaillon) au-dessus des signatures.
        $y_seau = $ph - 62;
        $cx_seau = $pw / 2;
        $pdf->SetDrawColor($or[0], $or[1], $or[2]); $pdf->SetLineWidth(0.6);
        $pdf->CircleOutline($cx_seau, $y_seau, 9);
        $pdf->CircleOutline($cx_seau, $y_seau, 7);
        for ($a = 0; $a < 360; $a += 30) {
            $rad = deg2rad($a);
            $pdf->Line($cx_seau + 7 * cos($rad), $y_seau + 7 * sin($rad), $cx_seau + 9 * cos($rad), $y_seau + 9 * sin($rad));
        }
        $pdf->SetFont('Times', 'B', 7);
        $pdf->SetTextColor($marine[0], $marine[1], $marine[2]);
        $pdf->SetXY($cx_seau - 9, $y_seau - 3);
        $pdf->Cell(18, 6, u('MERITE'), 0, 0, 'C');
        $pdf->SetTextColor(0, 0, 0); $pdf->SetDrawColor(0, 0, 0); $pdf->SetLineWidth(0.2);

        $y_sig = $ph - 38;
        $pdf->SetY($y_sig - 6);
        $pdf->SetFont('Times', 'I', 9.5);
        $pdf->Cell($uw, 5, u('Fait a Mbe, le ' . date('d/m/Y')), 0, 1, 'R');
        $w3 = $uw / 3;
        $pdf->SetFont('Times', 'BU', 9.5);
        $pdf->SetXY($ml, $y_sig);
        $pdf->Cell($w3, 5, u('LE RECIPIENDAIRE'), 0, 0, 'C');
        $pdf->Cell($w3, 5, u('LE PRINCIPAL'), 0, 0, 'C');
        $pdf->Cell($w3, 5, u(strtoupper($etab['chef_etablissement'] ?? 'LE PROVISEUR')), 0, 1, 'C');

        // Signature numérique (sur demande uniquement, jamais automatique).
        if (($_GET['signature'] ?? '0') === '1') {
            $sig_w = min(20, $w3 * 0.5);
            $sx = $ml + $w3 * 2 + ($w3 - $sig_w) / 2;
            $sy = $y_sig + 5.5;
            pdf_signature_appliquer($pdf, 'tableau_honneur_3', 'chef_etablissement', 0, 0, $pw, $ph, [
                'x_pct' => $sx / $pw * 100, 'y_pct' => $sy / $ph * 100, 'w_pct' => $sig_w / $pw * 100, 'h_pct' => null,
            ]);
        }

        $qr_size = 16;
        dessiner_qr_th($pdf, $el, $vue_verif, $id_periode_verif, $pw - $ml - $qr_size, $ph - $qr_size - 12, $qr_size);
        $pdf->SetFont('Times', '', 6.5);
        $pdf->SetXY($ml, $ph - 10);
        $pdf->Cell($uw, 4, u('Copyright © SIGES ABZ , E-mail: abdoulazizyahya@gmail.com'), 0, 1, 'C');
		
		
		
    }
}

$mode = $dl ? 'D' : 'I';
$suffix = preg_replace('/\W+/', '_', $classe['designation'] ?? 'classe');
$pdf->Output($mode, 'tableau_honneur_' . $suffix . '.pdf');
