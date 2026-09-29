<?php
// ── PDF : Cartes scolaires ────────────────────────────────────
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/verif_carte_lib.php';

$id_format = (int)($_GET['format'] ?? 1);
$id_modele = max(1, min(5, (int)($_GET['modele'] ?? 1)));
$id_annee  = (int)($_GET['annee']  ?? 0);
$id_classe = (int)($_GET['classe'] ?? 0);
$id_eleve  = (int)($_GET['eleve']  ?? 0);
$dl        = ($_GET['dl'] ?? '0') === '1';
$verso     = ($_GET['verso'] ?? '0') === '1';

// Accès public via le QR code de la carte (jeton "vh" = hash de vérification
// déjà calculé pour cette carte précise) : uniquement en mode mono-élève —
// la personne qui scanne n'a pas forcément de compte dans le système (voir
// verif_carte.php). En dehors de ce cas, connexion normale exigée.
$vh_verif = (string)($_GET['vh'] ?? '');
$acces_public = $vh_verif !== '' && $id_eleve > 0 && $id_annee > 0
    && hash_equals(carte_verif_hash($id_eleve, $id_annee), $vh_verif);
if (!$acces_public) {
    exiger_connexion();
    // Enseignant / SG : seulement leurs classes (classe entière ou élève).
    exiger_acces_classe_secondaire($id_classe);
    exiger_acces_eleve_secondaire($id_eleve);
}

require_once __DIR__ . '/fpdf.php';
require_once __DIR__ . '/header_pdf.php'; // pour pdf_u() (encodage UTF-8 -> Windows-1252 attendu par FPDF)
require_once __DIR__ . '/qrcode.php';
// Sens de retournement physique de la pile de feuilles pour l'impression du
// verso : 'long' = retournement bord long (gauche/droite, comme un livre) →
// on inverse les colonnes ; 'short' = bord court (haut/bas, comme un bloc-
// notes) → on inverse les lignes. Doit correspondre à la manière dont
// l'utilisateur retourne physiquement la pile dans le bac de l'imprimante.
$flip      = ($_GET['flip'] ?? 'long') === 'short' ? 'short' : 'long';

$format = db_one("SELECT * FROM format_carte WHERE id=?", [$id_format]);
if (!$format) die('Format introuvable.');

$where = ["e.statut='actif'"];
$params = [];
if ($id_eleve)  { $where[] = "e.id=?";       $params[] = $id_eleve; }
if ($id_classe) { $where[] = "i.id_classe=?"; $params[] = $id_classe; }
$sql_where = 'WHERE ' . implode(' AND ', $where);

$eleves = db_all(
    "SELECT e.*, c.designation AS classe
     FROM eleve e
     LEFT JOIN inscription i ON i.id_eleve=e.id AND i.id_annee=$id_annee
     LEFT JOIN classe c ON c.id=i.id_classe
     $sql_where ORDER BY e.nom, e.prenom", $params);

if (empty($eleves)) die('Aucun élève trouvé.');

$etab  = get_etablissement();
$annee = db_one("SELECT * FROM annee_scolaire WHERE id=?", [$id_annee]) ?? ['libelle' => '—'];

// Tuteur principal de chaque élève (pour le contact affiché au verso) :
// une seule requête groupée plutôt qu'une requête par élève dans la boucle.
$tuteurs_idx = [];
if ($verso) {
    $ids_e = array_column($eleves, 'id');
    $in_ph = implode(',', array_fill(0, count($ids_e), '?'));
    foreach (db_all("SELECT * FROM tuteur WHERE id_eleve IN ($in_ph) ORDER BY id", $ids_e) as $t) {
        if (!isset($tuteurs_idx[$t['id_eleve']])) $tuteurs_idx[$t['id_eleve']] = $t; // le premier = principal
    }
}

// ── Dimensions ────────────────────────────────────────────────
$marge   = 8;
$ecart   = 3;
$page_w  = 210; $page_h = 297;

// Une carte ne doit jamais dépasser la zone imprimable (ex. le format
// prédéfini "A4 pleine page" fait 210x297, soit la page entière) : on la
// borne à l'espace disponible entre les marges pour éviter tout débordement.
$cW      = min((float)$format['largeur_mm'], $page_w - 2*$marge);
$cH      = min((float)$format['hauteur_mm'], $page_h - 2*$marge);

$nb_col  = max(1, (int)(($page_w - 2*$marge + $ecart) / ($cW + $ecart)));
$nb_lig  = max(1, (int)(($page_h - 2*$marge + $ecart) / ($cH + $ecart)));
$per_pag = $nb_col * $nb_lig;

$pdf = new FPDF('P', 'mm', 'A4');
$pdf->SetMargins($marge, $marge, $marge);
$pdf->SetAutoPageBreak(false);

// Chaque page ("lot" de per_pag élèves) est traitée en recto puis, si
// demandé, immédiatement suivie de sa page verso : ainsi, une fois la pile
// imprimée en recto puis retournée et réinsérée pour le verso, la page N
// (recto du lot N) est directement suivie physiquement par la page verso du
// MÊME lot — jamais décalée par rapport aux autres lots.
$fn_recto = 'dessiner_carte_' . $id_modele;

$lots = array_chunk($eleves, $per_pag);
foreach ($lots as $lot) {
    // ── Recto ──────────────────────────────────────────────────
    $pdf->AddPage();
    foreach ($lot as $pos => $el) {
        $col = $pos % $nb_col;
        $lig = (int)($pos / $nb_col);
        $x   = $marge + $col * ($cW + $ecart);
        $y   = $marge + $lig * ($cH + $ecart);
        $fn_recto($pdf, $el, $etab, $annee, $x, $y, $cW, $cH);
    }

    // ── Verso (positions en miroir selon le sens de retournement) ─
    if ($verso) {
        $pdf->AddPage();
        foreach ($lot as $pos => $el) {
            $col = $pos % $nb_col;
            $lig = (int)($pos / $nb_col);
            if ($flip === 'long') { $col = $nb_col - 1 - $col; } // retournement bord long : miroir horizontal
            else                  { $lig = $nb_lig - 1 - $lig; } // retournement bord court : miroir vertical
            $x = $marge + $col * ($cW + $ecart);
            $y = $marge + $lig * ($cH + $ecart);
            dessiner_carte_verso($pdf, $el, $etab, $tuteurs_idx[$el['id']] ?? null, $x, $y, $cW, $cH);
        }
    }
}

$pdf->Output($dl ? 'D' : 'I', 'cartes_scolaires_modele' . $id_modele . '_' . date('Ymd') . '.pdf');

// ════════════════════════════════════════════════
// MODÈLE 1 — reproduction fidèle de others/carte.png (LYCÉE TECHNIQUE DE
// MBÉ) : coin drapeau diagonal, en-tête bilingue 3 colonnes (FR/logo/EN),
// titre en grand bleu, barre noire arrondie décorative, champs bilingues
// sur 2 lignes (libellé FR gras + légende EN italique dessous).
// ════════════════════════════════════════════════
function dessiner_carte_1(FPDF $pdf, array $el, array $etab, array $annee,
                         float $x, float $y, float $cW, float $cH): void
{
    $pdf->SetDrawColor(150, 150, 150);
    $pdf->SetLineWidth(0.25);
    if (method_exists($pdf, 'RoundedRect')) {
        $pdf->RoundedRect($x, $y, $cW, $cH, 1.2, 'D');
    } else {
        $pdf->Rect($x, $y, $cW, $cH, 'D');
    }
    $pdf->SetLineWidth(0.2);

    carte_filigrane($pdf, $etab, $x, $y, $cW, $cH);

    // ── En-tête bilingue (coin drapeau + FR / logo / EN) ──────────────
    $hLettre = $cH * 0.25;
    $flagW = $cW * 0.15;
    $flag_tmp = carte_flag_triangle_tmp(200, (int) round(200 * $hLettre / max(0.01, $flagW)));
    try {
        $pdf->Image($flag_tmp, $x, $y, $flagW, $hLettre, 'PNG');
    } finally {
        if (is_file($flag_tmp)) unlink($flag_tmp);
    }

    $logoW = $cW * 0.15;
    $colW  = ($cW - $flagW - $logoW) / 2;
    $xFr = $x + $flagW; $xLogo = $xFr + $colW; $xEn = $xLogo + $logoW;
    $fsHead = max(2.4, min(3.6, $cW / 28));

    $pdf->SetTextColor(0);
    $pdf->SetFont('Arial', 'B', $fsHead);
    $pdf->SetXY($xFr, $y + 0.3);
    $pdf->MultiCell($colW, 1.9, pdf_u(
        "REPUBLIQUE DU CAMEROUN\nPaix-Travail-Patrie\n" .
        ($etab['region_fr'] ?? "RÉGION DE L'ADAMAOUA") . "\n" .
        ($etab['departement_fr'] ?? 'DEPARTEMENT DE LA VINA') . "\n" .
        ($etab['arrondissement_fr'] ?? 'ARRONDISSEMENT DE MBE')
    ), 0, 'L');
    $pdf->SetXY($xEn, $y + 0.3);
    $pdf->MultiCell($colW, 1.9, pdf_u(
        "REPUBLIC OF CAMEROON\nPeace-Work-Fatherland\n" .
        ($etab['region_en'] ?? 'ADAMAWA REGION') . "\n" .
        ($etab['division_en'] ?? 'VINA DIVISION') . "\n" .
        ($etab['subdivision_en'] ?? 'MBE SUBDIVISION')
    ), 0, 'L');

    $logo_path = !empty($etab['logo']) ? __DIR__ . '/../../assets/uploads/' . $etab['logo'] : '';
    $logoSize = min($logoW, $hLettre) * 0.9;
    if ($logo_path && is_file($logo_path)) {
        $pdf->Image($logo_path, $xLogo + ($logoW - $logoSize) / 2, $y + ($hLettre - $logoSize) / 2, $logoSize);
    } else {
        $pdf->SetFont('Arial', 'B', $fsHead + 1);
        $pdf->SetXY($xLogo, $y + $hLettre / 2 - 2);
        $pdf->Cell($logoW, 4, pdf_u($etab['sigle'] ?? 'MBE'), 0, 0, 'C');
    }

    // ── Titre établissement (grand, bleu, aligné à gauche) ────────────
    $yTitre = $y + $hLettre + 0.5;
    $hTitre = $cH * 0.11;
    $pdf->SetTextColor(30, 79, 216);
    $fsTitre = max(5, min(8, $cW / 10));
    $pdf->SetFont('Arial', 'B', $fsTitre);
    $pdf->SetXY($x + 1.5, $yTitre);
    $pdf->Cell($cW - 3, $hTitre, pdf_u($etab['nom_fr'] ?? APP_NOM), 0, 0, 'L');

    // ── Barre noire arrondie décorative ────────────────────────────────
    $yBar = $yTitre + $hTitre + 0.3;
    $hBar = max(1.2, $cH * 0.025);
    $pdf->SetFillColor(20, 20, 20);
    if (method_exists($pdf, 'RoundedRect')) {
        $pdf->RoundedRect($x + $cW * 0.28, $yBar, $cW * 0.60, $hBar, $hBar / 2, 'F');
    } else {
        $pdf->Rect($x + $cW * 0.28, $yBar, $cW * 0.60, $hBar, 'F');
    }

    // ── Corps : photo + champs bilingues (2 lignes chacun) ────────────
    $pdf->SetTextColor(0);
    $yBody  = $yBar + $hBar + 1;
    $photoW = $cW * 0.26;
    $photoH = min($cH * 0.42, ($y + $cH - 6) - $yBody);
    carte_photo_box($pdf, $el, $x + 1.5, $yBody, $photoW, max(8, $photoH));

    $xi = $x + $photoW + 3;
    $wi = $cW - $photoW - 4.5;
    $fsLbl = max(3.6, min(5.5, $cW / 13));
    $fsSub = max(3, $fsLbl - 1.2);

    $champs = [
        ['Année scolaire: ' . ($annee['libelle'] ?? '—'), 'School Year'],
        ['Nom et Prénom : ' . strtoupper($el['nom']) . ' ' . ($el['prenom'] ?? ''), 'Name and surname'],
        ['Né(e) le: ' . ($el['date_naiss'] ? date('d/m/Y', strtotime($el['date_naiss'])) : '—'), 'Born on'],
        ['à: ' . mb_strimwidth($el['lieu_naiss'] ?? '—', 0, 18, '…'), 'Place'],
        ['Classe: ' . mb_strimwidth($el['classe'] ?? '—', 0, 16, '…'), 'Class'],
    ];
    $yr = $yBody;
    foreach ($champs as [$fr, $en]) {
        $pdf->SetFont('Arial', 'B', $fsLbl);
        $pdf->SetXY($xi, $yr);
        $pdf->Cell($wi, 2.6, pdf_u($fr), 0, 1, 'L');
        $pdf->SetFont('Arial', 'I', $fsSub);
        $pdf->SetX($xi);
        $pdf->Cell($wi, 2.1, pdf_u($en), 0, 1, 'L');
        $yr = $pdf->GetY() + 0.2;
    }

    // ── QR d'authenticité (discret, bas-droite — absent du gabarit
    // d'origine, ajouté car indispensable à l'appli) ──────────────────
    $qrSize = max(6, min(9, $cH * 0.13));
    $qr_tmp = carte_qr_tmp($el, $annee);
    try {
        $pdf->Image($qr_tmp, $x + $cW - $qrSize - 1.5, $y + $cH - $qrSize - 6, $qrSize, $qrSize, 'PNG');
    } finally {
        if (is_file($qr_tmp)) unlink($qr_tmp);
    }

    // ── Pied : signature du chef d'établissement ──────────────────────
    $yFoot = $y + $cH - 5;
    $pdf->SetFont('Arial', 'B', max(3.8, $fsLbl - 0.5));
    $pdf->SetXY($x + 1.5, $yFoot);
    $pdf->Cell($cW - 3, 2.6, pdf_u(strtoupper($etab['chef_etablissement'] ?? 'LE PROVISEUR') . ','), 0, 1, 'L');
    $pdf->SetFont('Arial', 'I', max(3.2, $fsLbl - 1.3));
    $pdf->SetX($x + 1.5);
    $pdf->Cell($cW - 3, 2.2, pdf_u($etab['chef_etablissement_en'] ?? 'The Principal'), 0, 0, 'L');

    // Signature numérique (uniquement si demandée à l'impression — jamais
    // automatique), discrète dans le coin bas-droit du pied de carte.
    if (($_GET['signature'] ?? '0') === '1') {
        $sig_w = min(12, $cW * 0.14);
        $sig_h = 4.5;
        $sx = $x + $cW - $sig_w - 1;
        $sy = $yFoot - 0.3;
        pdf_signature_appliquer($pdf, 'carte_1', 'chef_etablissement', $x, $y, $cW, $cH, [
            'x_pct' => ($sx - $x) / $cW * 100, 'y_pct' => ($sy - $y) / $cH * 100,
            'w_pct' => $sig_w / $cW * 100, 'h_pct' => $sig_h / $cH * 100,
        ]);
    }
}

// ── Aides communes aux modèles 2-5 (modèle 1 reste autonome, historique) ──
// Photo de l'élève (chemin réel ou avatar par défaut selon le sexe — même
// repli que les bulletins/tableau d'honneur), utilisée à la fois pour la
// photo affichée sur la carte et pour celle incrustée dans le QR.
function carte_photo_path(array $el): string {
    $photo_path = !empty($el['photo']) ? __DIR__ . '/../../assets/uploads/eleves/' . $el['photo'] : '';
    if ($photo_path && is_file($photo_path)) return $photo_path;
    $avatar = (strtoupper($el['sexe'] ?? '') === 'F') ? 'fille.png' : 'garcon.png';
    return __DIR__ . '/../../assets/img/avatars/' . $avatar;
}

function carte_photo_box(FPDF $pdf, array $el, float $x, float $y, float $w, float $h): void {
    $pdf->Image(carte_photo_path($el), $x, $y, $w, $h);
}

// Incruste la photo de l'élève au centre du QR (même principe que
// secondaire/pages/bulletins/pdf.php::qr_incruster_photo() / le tableau d'honneur —
// dupliquée ici par convention du projet, pas de dépendance croisée entre
// documents).
function carte_qr_incruster_photo($qr_img, string $photo_path): void {
    if ($photo_path === '' || !is_file($photo_path)) return;
    $photo = @imagecreatefromstring(file_get_contents($photo_path));
    if (!$photo) return;
    $w = imagesx($qr_img); $h = imagesy($qr_img);
    $logo_size = (int) round($w * 0.22);
    $pad = (int) round($w * 0.012);
    $box = $logo_size + $pad * 2;
    $bx = (int) (($w - $box) / 2); $by = (int) (($h - $box) / 2);
    $blanc = imagecolorallocate($qr_img, 255, 255, 255);
    imagefilledrectangle($qr_img, $bx, $by, $bx + $box - 1, $by + $box - 1, $blanc);
    $pw = imagesx($photo); $ph = imagesy($photo);
    $cote = min($pw, $ph);
    $sx = (int) (($pw - $cote) / 2); $sy = (int) (($ph - $cote) / 2);
    imagecopyresampled($qr_img, $photo, $bx + $pad, $by + $pad, $sx, $sy, $logo_size, $logo_size, $cote, $cote);
    imagedestroy($photo);
}

function carte_qr_tmp(array $el, array $annee): string {
    $verif_url = carte_verif_url((int)$el['id'], (int)($annee['id'] ?? 0));
    $qr_tmp = tempnam(sys_get_temp_dir(), 'abzqr_') . '.png';
    // Correction d'erreur élevée ('qr-h') : indispensable dès qu'une photo
    // est incrustée au centre (tolère ~30% de perte de modules).
    $qr_gen = new QRCode($verif_url, ['s' => 'qr-h']);
    $qr_img = $qr_gen->render_image();
    carte_qr_incruster_photo($qr_img, carte_photo_path($el));
    imagepng($qr_img, $qr_tmp);
    imagedestroy($qr_img);
    return $qr_tmp;
}

// Filigrane discret dans le cadre de la carte (contrairement à
// pdf_filigrane(), qui centre sur toute la page, la carte est positionnée
// n'importe où sur la page : on centre donc précisément dans son propre
// cadre x,y,cW,cH à partir du même fichier mis en cache).
function carte_filigrane(FPDF $pdf, array $etab, float $x, float $y, float $cW, float $cH): void {
    $chemin = pdf_filigrane_chemin($etab);
    if (!$chemin) return;
    $w_fili = $cW * 0.5;
    $dim = @getimagesize($chemin);
    $h_fili = ($dim && $dim[0] > 0) ? $w_fili * $dim[1] / $dim[0] : $w_fili;
    // Si le logo est plus haut que large, borne la hauteur à celle de la
    // carte pour ne jamais déborder (carte souvent plus large que haute).
    if ($h_fili > $cH * 0.85) {
        $h_fili = $cH * 0.85;
        $w_fili = ($dim && $dim[1] > 0) ? $h_fili * $dim[0] / $dim[1] : $h_fili;
    }
    $pdf->Image($chemin, $x + ($cW - $w_fili) / 2, $y + ($cH - $h_fili) / 2, $w_fili);
}

// Coin drapeau camerounais diagonal (triangle vert/rouge/jaune + étoile),
// vu sur les 3 modèles de référence (others/carte.png, carte1.png,
// carte.pdf). FPDF n'a pas de primitive polygone remplie : généré comme un
// petit PNG via GD (même principe que qr_incruster_photo()/
// photo_circulaire_th() déjà dans le projet), fond transparent, fichier
// temporaire à supprimer par l'appelant.
function carte_flag_triangle_tmp(int $w_px = 200, int $h_px = 200): string {
    $w_px = max(20, $w_px); $h_px = max(20, $h_px);
    $img = imagecreatetruecolor($w_px, $h_px);
    imagesavealpha($img, true);
    $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
    imagefill($img, 0, 0, $transparent);

    $green  = imagecolorallocate($img, 0, 158, 73);
    $red    = imagecolorallocate($img, 206, 17, 38);
    $yellow = imagecolorallocate($img, 252, 209, 22);

    // Triangle rectangle (angle droit en haut-gauche), 3 bandes diagonales
    // parallèles à l'hypoténuse — dessinées de l'extérieur vers le coin.
    imagefilledpolygon($img, [0, 0, $w_px, 0, 0, $h_px], $yellow);
    imagefilledpolygon($img, [0, 0, (int) round($w_px * 2 / 3), 0, 0, (int) round($h_px * 2 / 3)], $red);
    imagefilledpolygon($img, [0, 0, (int) round($w_px / 3), 0, 0, (int) round($h_px / 3)], $green);

    // Petite étoile jaune dans la bande rouge.
    $cx = $w_px * 0.30; $cy = $h_px * 0.30;
    $r1 = min($w_px, $h_px) * 0.09; $r2 = $r1 * 0.42;
    $pts = [];
    for ($i = 0; $i < 10; $i++) {
        $ang = -M_PI / 2 + $i * M_PI / 5;
        $r = ($i % 2 === 0) ? $r1 : $r2;
        $pts[] = $cx + $r * cos($ang);
        $pts[] = $cy + $r * sin($ang);
    }
    imagefilledpolygon($img, $pts, $yellow);

    $tmp = tempnam(sys_get_temp_dir(), 'abzflag_') . '.png';
    imagepng($img, $tmp);
    imagedestroy($img);
    return $tmp;
}

// Cercle approximé par segments de droite (FPDF standard n'a pas de
// primitive cercle/ellipse native) — utilisé pour le sceau du modèle 3.
function carte_circle_outline(FPDF $pdf, float $cx, float $cy, float $r, int $n = 36): void {
    $prevX = $cx + $r; $prevY = $cy;
    for ($i = 1; $i <= $n; $i++) {
        $ang = 2 * M_PI * $i / $n;
        $px = $cx + $r * cos($ang);
        $py = $cy + $r * sin($ang);
        $pdf->Line($prevX, $prevY, $px, $py);
        $prevX = $px; $prevY = $py;
    }
}

// ════════════════════════════════════════════════
// MODÈLE 2 — reproduction fidèle de others/carte1.png : coin drapeau
// diagonal, en-tête FR (gauche) / EN (droite — jamais d'arabe, remplace la
// colonne arabe de l'original), titre "CARTE D'IDENTITÉ SCOLAIRE" encadré
// bleu, sous-titre rouge italique, photo avec légende signature superposée,
// champs séparés (Nom/Prénom/Né/Sexe/À/Classe/NIU).
// ════════════════════════════════════════════════
function dessiner_carte_2(FPDF $pdf, array $el, array $etab, array $annee,
                           float $x, float $y, float $cW, float $cH): void
{
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(0.25);
    $pdf->Rect($x, $y, $cW, $cH, 'D');

    carte_filigrane($pdf, $etab, $x, $y, $cW, $cH);

    // ── En-tête : coin drapeau + FR gauche / EN droite ────────────────
    $hHead = $cH * 0.20;
    $flagW = $cW * 0.15;
    $flag_tmp = carte_flag_triangle_tmp(200, (int) round(200 * $hHead / max(0.01, $flagW)));
    try {
        $pdf->Image($flag_tmp, $x, $y, $flagW, $hHead, 'PNG');
    } finally {
        if (is_file($flag_tmp)) unlink($flag_tmp);
    }

    $colW = ($cW - $flagW - 2) / 2;
    $xFr = $x + $flagW; $xEn = $xFr + $colW + 2;
    $fsHead = max(2.4, min(3.4, $cW / 30));
    $pdf->SetTextColor(0);
    $pdf->SetFont('Arial', 'B', $fsHead);
    $pdf->SetXY($xFr, $y + 0.3);
    $pdf->MultiCell($colW, 1.9, pdf_u(
        "REPUBLIQUE DU CAMEROUN\nPaix - Travail - Patrie\n" .
        ($etab['region_fr'] ?? "RÉGION DE L'ADAMAOUA") . "\n" .
        ($etab['departement_fr'] ?? 'DEPARTEMENT DE LA VINA') . "\n" .
        ($etab['arrondissement_fr'] ?? 'ARRONDISSEMENT DE MBE')
    ), 0, 'C');
    $pdf->SetXY($xEn, $y + 0.3);
    $pdf->MultiCell($colW, 1.9, pdf_u(
        "REPUBLIC OF CAMEROON\nPeace - Work - Fatherland\n" .
        ($etab['region_en'] ?? 'ADAMAWA REGION') . "\n" .
        ($etab['division_en'] ?? 'VINA DIVISION') . "\n" .
        ($etab['subdivision_en'] ?? 'MBE SUBDIVISION')
    ), 0, 'C');

    // ── Titre encadré bleu ─────────────────────────────────────────────
    $yTitre = $y + $hHead + 1;
    $fsTitre = max(6, min(9, $cW / 9));
    $pdf->SetFont('Arial', 'B', $fsTitre);
    $pdf->SetTextColor(30, 79, 216);
    $titre = "CARTE D'IDENTITÉ SCOLAIRE";
    $tw = min($cW - 4, $pdf->GetStringWidth(pdf_u($titre)) + 6);
    $th = $fsTitre * 0.55;
    $tx = $x + ($cW - $tw) / 2;
    $pdf->SetDrawColor(30, 79, 216);
    $pdf->SetLineWidth(0.5);
    if (method_exists($pdf, 'RoundedRect')) {
        $pdf->RoundedRect($tx, $yTitre, $tw, $th, $th / 2, 'D');
    } else {
        $pdf->Rect($tx, $yTitre, $tw, $th, 'D');
    }
    $pdf->SetLineWidth(0.2);
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetXY($tx, $yTitre);
    $pdf->Cell($tw, $th, pdf_u($titre), 0, 0, 'C');

    // ── Sous-titre rouge italique ───────────────────────────────────────
    $ySub = $yTitre + $th + 0.5;
    $pdf->SetFont('Arial', 'I', max(3.5, $fsTitre - 3));
    $pdf->SetTextColor(190, 20, 20);
    $pdf->SetXY($x + 1, $ySub);
    $pdf->Cell($cW - 2, 2.6, pdf_u('SCHOOL IDENTITY CARD'), 0, 0, 'C');

    // ── Corps : photo (+ signature superposée) à gauche, champs à droite ──
    $pdf->SetTextColor(0);
    $yBody  = $ySub + 3.2;
    $photoW = $cW * 0.27;
    $photoH = min($cH * 0.40, ($y + $cH - 3) - $yBody - 5);
    carte_photo_box($pdf, $el, $x + 1.5, $yBody, $photoW, max(8, $photoH));
    $pdf->SetFont('Arial', 'I', max(2.8, $cW / 30));
    $pdf->SetXY($x + 1.5, $yBody + max(8, $photoH) + 0.3);
    $pdf->MultiCell($photoW, 1.7, pdf_u(strtoupper($etab['chef_etablissement'] ?? 'LE PROVISEUR') . "\nLe Proviseur"), 0, 'C');
    if (($_GET['signature'] ?? '0') === '1') {
        $sig_w = min(14, $photoW);
        $sig_h = 5;
        $sx = $x + 1.5 + ($photoW - $sig_w) / 2;
        $sy = $pdf->GetY() + 0.3;
        pdf_signature_appliquer($pdf, 'carte_2', 'chef_etablissement', $x, $y, $cW, $cH, [
            'x_pct' => ($sx - $x) / $cW * 100, 'y_pct' => ($sy - $y) / $cH * 100,
            'w_pct' => $sig_w / $cW * 100, 'h_pct' => $sig_h / $cH * 100,
        ]);
    }

    $xi = $x + $photoW + 3;
    $wi = $cW - $photoW - 4.5;
    $fs2 = max(3.6, min(5.5, $cW / 13));

    $ligne = function (string $lbl, string $val, float $lblRatio = 0.32) use ($pdf, $xi, $wi, $fs2): void {
        $pdf->SetFont('Arial', 'B', $fs2);
        $pdf->SetTextColor(30, 79, 216);
        $pdf->Cell($wi * $lblRatio, 3, pdf_u($lbl), 0, 0);
        $pdf->SetFont('Arial', 'B', $fs2);
        $pdf->SetTextColor(0);
        $pdf->Cell($wi * (1 - $lblRatio), 3, pdf_u($val), 0, 1);
    };
    $pdf->SetXY($xi, $yBody);
    $ligne('Nom :', strtoupper($el['nom']));
    $pdf->SetX($xi);
    $ligne('Prénom :', $el['prenom'] ?? '—');

    $pdf->SetX($xi);
    $pdf->SetFont('Arial', 'B', $fs2); $pdf->SetTextColor(30, 79, 216);
    $pdf->Cell($wi * 0.32, 3, pdf_u('Né(e) le :'), 0, 0);
    $pdf->SetTextColor(0);
    $pdf->Cell($wi * 0.36, 3, pdf_u($el['date_naiss'] ? date('d/m/Y', strtotime($el['date_naiss'])) : '—'), 0, 0);
    $pdf->SetTextColor(30, 79, 216);
    $pdf->Cell($wi * 0.14, 3, pdf_u('Sexe :'), 0, 0);
    $pdf->SetTextColor(0);
    $pdf->Cell($wi * 0.18, 3, pdf_u($el['sexe'] === 'M' ? 'M' : 'F'), 0, 1);

    $pdf->SetX($xi);
    $ligne('À :', mb_strimwidth($el['lieu_naiss'] ?? '—', 0, 16, '…'));
    $pdf->SetX($xi);
    $ligne('Classe :', mb_strimwidth($el['classe'] ?? '—', 0, 16, '…'));
    $pdf->SetX($xi);
    $ligne('NIU :', id_affichage_eleve($el));

    // QR de vérification (absent de l'original, ajouté pour l'authenticité).
    $qrSize = max(6, min(9, $cH * 0.13));
    $qr_tmp = carte_qr_tmp($el, $annee);
    try {
        $pdf->Image($qr_tmp, $x + $cW - $qrSize - 1.5, $y + $cH - $qrSize - 1.5, $qrSize, $qrSize, 'PNG');
    } finally {
        if (is_file($qr_tmp)) unlink($qr_tmp);
    }
}

// ════════════════════════════════════════════════
// MODÈLE 3 — reproduction fidèle de others/carte.pdf (recto) : nom
// d'établissement sur 2 lignes, bandeau gris "CARTE D'IDENTITÉ SCOLAIRE",
// bandeau vertical bleu pleine hauteur affichant l'année scolaire, champs
// bilingues, Classe/NIU en rouge, QR bas-gauche, sceau + signature bas-droite.
// ════════════════════════════════════════════════
function dessiner_carte_3(FPDF $pdf, array $el, array $etab, array $annee,
                           float $x, float $y, float $cW, float $cH): void
{
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(0.25);
    $pdf->Rect($x, $y, $cW, $cH, 'D');

    $bannerW = $cW * 0.14;
    $mainW   = $cW - $bannerW;

    // Filigrane dans la seule zone blanche (pas sous le bandeau bleu plein).
    carte_filigrane($pdf, $etab, $x, $y, $mainW, $cH);

    // ── Bandeau vertical bleu (année scolaire), pleine hauteur ─────────
    $pdf->SetFillColor(30, 79, 216);
    $pdf->Rect($x + $mainW, $y, $bannerW, $cH, 'F');
    $parts = array_pad(explode('/', (string)($annee['libelle'] ?? '')), 2, '');
    $pdf->SetTextColor(255, 255, 255);
    $fsAnnee = max(6, min(11, $bannerW * 0.9));
    $pdf->SetFont('Arial', 'B', $fsAnnee);
    $pdf->SetXY($x + $mainW, $y + $cH * 0.30);
    $pdf->Cell($bannerW, $cH * 0.16, pdf_u($parts[0]), 0, 0, 'C');
    $pdf->SetXY($x + $mainW, $y + $cH * 0.55);
    $pdf->Cell($bannerW, $cH * 0.16, pdf_u($parts[1]), 0, 0, 'C');

    // ── En-tête : nom établissement sur 2 lignes ───────────────────────
    $pdf->SetTextColor(30, 79, 216);
    $fsNom = max(5, min(8, $mainW / 12));
    $pdf->SetFont('Arial', 'B', $fsNom);
    $pdf->SetXY($x + 1, $y + 0.5);
    $pdf->Cell($mainW - 2, $fsNom * 0.5, pdf_u($etab['nom_fr'] ?? APP_NOM), 0, 1, 'C');
    $pdf->SetTextColor(0);
    $pdf->SetFont('Arial', 'B', max(4.5, $fsNom - 1));
    $pdf->SetX($x + 1);
    $pdf->Cell($mainW - 2, $fsNom * 0.45, pdf_u($etab['sigle'] ?? ($etab['nom_en'] ?? '')), 0, 1, 'C');

    // ── Bandeau gris + fine bande bleue ────────────────────────────────
    $yGris = $pdf->GetY() + 0.5;
    $hGris = $cH * 0.13;
    $pdf->SetFillColor(160, 160, 163);
    $pdf->Rect($x, $yGris, $mainW, $hGris, 'F');
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', max(3.6, $mainW / 24));
    $pdf->SetXY($x + 1, $yGris + $hGris * 0.08);
    $pdf->Cell($mainW - 2, $hGris * 0.42, pdf_u("CARTE D'IDENTITÉ SCOLAIRE"), 0, 1, 'C');
    $pdf->SetFont('Arial', '', max(3.2, $mainW / 28));
    $pdf->SetX($x + 1);
    $pdf->Cell($mainW - 2, $hGris * 0.38, pdf_u('SCHOOL IDENTITY CARD'), 0, 0, 'C');

    $yBleu = $yGris + $hGris + 0.3;
    $hBleu = max(0.8, $cH * 0.015);
    $pdf->SetFillColor(30, 79, 216);
    $pdf->Rect($x, $yBleu, $mainW, $hBleu, 'F');

    // ── Corps : champs bilingues + Classe/NIU en rouge ─────────────────
    $pdf->SetTextColor(0);
    $yBody = $yBleu + $hBleu + 1;
    $fsLbl = max(3.6, min(5.5, $mainW / 13));
    $fsSub = max(3, $fsLbl - 1.2);
    $champs = [
        ['Nom(s) : ' . strtoupper($el['nom']) . ' ' . ($el['prenom'] ?? ''), 'Name(s)'],
        ['Né(e) le : ' . ($el['date_naiss'] ? date('d/m/Y', strtotime($el['date_naiss'])) : '—'), 'Born on'],
        ['À : ' . mb_strimwidth($el['lieu_naiss'] ?? '—', 0, 16, '…'), 'At'],
        ['Sexe : ' . ($el['sexe'] === 'M' ? 'M' : 'F'), 'Sex'],
    ];
    $yr = $yBody;
    foreach ($champs as [$fr, $en]) {
        $pdf->SetFont('Arial', 'B', $fsLbl);
        $pdf->SetXY($x + 1.5, $yr);
        $pdf->Cell($mainW - 3, 2.6, pdf_u($fr), 0, 1, 'L');
        $pdf->SetFont('Arial', 'I', $fsSub);
        $pdf->SetX($x + 1.5);
        $pdf->Cell($mainW - 3, 2.1, pdf_u($en), 0, 1, 'L');
        $yr = $pdf->GetY() + 0.2;
    }

    $pdf->SetTextColor(200, 20, 20);
    $pdf->SetFont('Arial', 'B', max(4, $fsLbl));
    $pdf->SetXY($x + 1.5, $yr + 0.5);
    $pdf->Cell($mainW - 3, 3, pdf_u('Classe : ' . mb_strimwidth($el['classe'] ?? '—', 0, 18, '…')), 0, 1, 'L');
    $pdf->SetX($x + 1.5);
    $pdf->Cell($mainW - 3, 3, pdf_u('NIU : ' . id_affichage_eleve($el)), 0, 1, 'L');
    $pdf->SetTextColor(0);

    // ── QR (bas-gauche, comme dans le gabarit d'origine) ───────────────
    $qrSize = max(6, min(10, $cH * 0.15));
    $qr_tmp = carte_qr_tmp($el, $annee);
    try {
        $pdf->Image($qr_tmp, $x + 1.5, $y + $cH - $qrSize - 1.5, $qrSize, $qrSize, 'PNG');
    } finally {
        if (is_file($qr_tmp)) unlink($qr_tmp);
    }

    // ── Sceau simple + signature (bas-droite — équivalent raisonnable au
    // tampon/signature scannés de l'original, non reproductibles) ──────
    $sealR = min($mainW * 0.10, $cH * 0.13);
    $sealCx = $x + $mainW - $sealR - 3;
    $sealCy = $y + $cH - $sealR - 8; // remonté pour laisser la place à la signature dessous
    $pdf->SetDrawColor(150, 20, 20);
    $pdf->SetLineWidth(0.3);
    carte_circle_outline($pdf, $sealCx, $sealCy, $sealR);
    carte_circle_outline($pdf, $sealCx, $sealCy, $sealR * 0.7);
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(0.2);
    // Signature numérique à l'intérieur du sceau (uniquement si demandée à
    // l'impression — jamais automatique).
    if (($_GET['signature'] ?? '0') === '1') {
        $sig_w = $sealR * 1.2;
        $sx = $sealCx - $sig_w / 2;
        $sy = $sealCy - $sig_w / 2;
        pdf_signature_appliquer($pdf, 'carte_3', 'chef_etablissement', $x, $y, $cW, $cH, [
            'x_pct' => ($sx - $x) / $cW * 100, 'y_pct' => ($sy - $y) / $cH * 100,
            'w_pct' => $sig_w / $cW * 100, 'h_pct' => $sig_w / $cH * 100,
        ]);
    }
    // Nom du signataire sous le sceau (jamais à l'intérieur : une signature
    // longue déborderait du cercle), sur une largeur plus généreuse.
    $sigW = min($mainW - 3, $sealR * 3.6);
    $pdf->SetFont('Arial', 'I', max(2.8, $sealR * 0.32));
    $pdf->SetTextColor(150, 20, 20);
    $pdf->SetXY($sealCx - $sigW / 2, $sealCy + $sealR + 0.8);
    $pdf->MultiCell($sigW, 2, pdf_u(strtoupper($etab['chef_etablissement'] ?? 'LE PROVISEUR')), 0, 'C');
    $pdf->SetTextColor(0);
}

// ════════════════════════════════════════════════
// MODÈLE 4 — "Badge centré" : composition entièrement centrée façon badge
// moderne (photo carrée centrée, nom en dessous, infos centrées), fines
// lignes d'accent turquoise en haut/bas du cadre. Toutes les hauteurs sont
// des fractions de $cH afin de rester proportionnées à n'importe quel
// format, de la carte CB au A4 pleine page.
// ════════════════════════════════════════════════
function dessiner_carte_4(FPDF $pdf, array $el, array $etab, array $annee,
                           float $x, float $y, float $cW, float $cH): void
{
    $accent = [20, 120, 110];
    $pdf->SetDrawColor(200, 200, 205);
    $pdf->SetLineWidth(0.3);
    $pdf->Rect($x, $y, $cW, $cH, 'D');
    $pdf->SetFillColor($accent[0], $accent[1], $accent[2]);
    $pdf->Rect($x, $y, $cW, $cH * 0.02, 'F');
    $pdf->Rect($x, $y + $cH * 0.98, $cW, $cH * 0.02, 'F');

    carte_filigrane($pdf, $etab, $x, $y, $cW, $cH);

    $pad = 2;
    $yCur = $y + $cH * 0.03;

    $fsEtab = max(4, min(5.5, $cW / 16));
    $pdf->SetTextColor(60, 60, 65);
    $pdf->SetFont('Arial', 'B', $fsEtab);
    $pdf->SetXY($x + $pad, $yCur);
    $pdf->Cell($cW - 2 * $pad, $cH * 0.05, pdf_u(mb_strtoupper($etab['sigle'] ?? 'MBE') . ' — ' . ($etab['nom_fr'] ?? '')), 0, 1, 'C');
    $pdf->SetFont('Arial', 'I', max(3.5, $fsEtab - 1));
    $pdf->SetX($x + $pad);
    $pdf->Cell($cW - 2 * $pad, $cH * 0.04, pdf_u("Carte d'identité scolaire"), 0, 0, 'C');
    $yCur += $cH * 0.14;

    // Photo carrée centrée.
    $photoSize = min($cH * 0.32, $cW * 0.30);
    $photoX = $x + ($cW - $photoSize) / 2;
    $pdf->SetDrawColor($accent[0], $accent[1], $accent[2]);
    $pdf->SetLineWidth(0.5);
    $pdf->Rect($photoX - 0.6, $yCur - 0.6, $photoSize + 1.2, $photoSize + 1.2, 'D');
    $pdf->SetLineWidth(0.2);
    $pdf->SetDrawColor(200, 200, 205);
    carte_photo_box($pdf, $el, $photoX, $yCur, $photoSize, $photoSize);
    $yCur += $photoSize + $cH * 0.03;

    // Nom centré.
    $pdf->SetTextColor(0);
    $fsNom = max(4.5, min(6.5, $cW / 11));
    $pdf->SetFont('Arial', 'B', $fsNom);
    $pdf->SetXY($x + $pad, $yCur);
    $pdf->Cell($cW - 2 * $pad, $cH * 0.06, pdf_u(strtoupper($el['nom']) . ' ' . ($el['prenom'] ?? '')), 0, 0, 'C');
    $yCur += $cH * 0.08;

    // Infos centrées (une ligne "Libellé : valeur" par ligne).
    $fs2 = max(3.6, min(5.5, $cW / 13));
    $hLigne = $cH * 0.05;
    $infos4 = [
        'Classe/Class' => mb_strimwidth($el['classe'] ?? '—', 0, 16, '…'),
        'Né(e)/Born'   => $el['date_naiss'] ? date('d/m/Y', strtotime($el['date_naiss'])) : '—',
        'NIU'          => id_affichage_eleve($el),
        'Sexe/Sex'     => $el['sexe'] === 'M' ? 'M / Masc.' : 'F / Fém.',
    ];
    $pdf->SetFont('Arial', '', $fs2);
    foreach ($infos4 as $lbl => $val) {
        $pdf->SetXY($x + $pad, $yCur);
        $pdf->Cell($cW - 2 * $pad, $hLigne, pdf_u($lbl . ' : ' . $val), 0, 1, 'C');
        $yCur += $hLigne;
    }
    $yCur += $cH * 0.02;

    // QR centré + signature, dans la bande basse restante.
    $qrSize = min($cH * 0.16, $cW * 0.14);
    $qr_tmp = carte_qr_tmp($el, $annee);
    try {
        $pdf->Image($qr_tmp, $x + ($cW - $qrSize) / 2, $yCur, $qrSize, $qrSize, 'PNG');
    } finally {
        if (is_file($qr_tmp)) unlink($qr_tmp);
    }
    $yCur += $qrSize + $cH * 0.01;
    $pdf->SetFont('Arial', 'I', max(3.2, $fs2 - 0.5));
    $pdf->SetXY($x + $pad, $yCur);
    $pdf->Cell($cW - 2 * $pad, $cH * 0.04, pdf_u(strtoupper($etab['chef_etablissement'] ?? 'LE PROVISEUR') . ' — The Principal'), 0, 0, 'C');

    // Signature numérique (uniquement si demandée à l'impression — jamais
    // automatique), centrée sous la légende, dans la marge basse restante.
    if (($_GET['signature'] ?? '0') === '1' && $y + $cH - ($yCur + $cH * 0.04) > 6) {
        $sig_w = min(16, $cW * 0.18);
        $sx = $x + ($cW - $sig_w) / 2;
        $sy = $yCur + $cH * 0.04 + 0.5;
        pdf_signature_appliquer($pdf, 'carte_4', 'chef_etablissement', $x, $y, $cW, $cH, [
            'x_pct' => ($sx - $x) / $cW * 100, 'y_pct' => ($sy - $y) / $cH * 100,
            'w_pct' => $sig_w / $cW * 100, 'h_pct' => null,
        ]);
    }
}

// ════════════════════════════════════════════════
// MODÈLE 5 — "Minimaliste" : aucun aplat de couleur (uniquement des filets
// fins et une puce de couleur discrète à côté du nom d'établissement),
// pensé pour l'impression noir & blanc économique.
// ════════════════════════════════════════════════
function dessiner_carte_5(FPDF $pdf, array $el, array $etab, array $annee,
                           float $x, float $y, float $cW, float $cH): void
{
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(0.3);
    $pdf->Rect($x, $y, $cW, $cH, 'D');

    carte_filigrane($pdf, $etab, $x, $y, $cW, $cH);

    $pad = 1.8;
    $hHead = $cH * 0.20;

    // Puce de couleur discrète (seule touche de couleur du modèle).
    $chip = max(2, $cH * 0.03);
    $pdf->SetFillColor(30, 79, 216);
    $pdf->Rect($x + $pad, $y + 1.5, $chip, $chip, 'F');

    $fsH = max(4.5, min(6.5, $cW / 12));
    $pdf->SetTextColor(0);
    $pdf->SetFont('Arial', 'B', $fsH);
    $pdf->SetXY($x + $pad + $chip + 1, $y + 0.8);
    $pdf->Cell($cW - 2 * $pad - $chip - 1, $hHead * 0.5, pdf_u(mb_strtoupper($etab['sigle'] ?? 'MBE') . ' — ' . ($etab['nom_fr'] ?? '')), 0, 1, 'L');
    $pdf->SetFont('Arial', 'I', max(4, $fsH - 1.5));
    $pdf->SetX($x + $pad);
    $pdf->Cell($cW - 2 * $pad, $hHead * 0.4, pdf_u("Carte d'identité scolaire / School identity card"), 0, 0, 'L');

    $pdf->SetDrawColor(120, 120, 120);
    $pdf->SetLineWidth(0.2);
    $pdf->Line($x + $pad, $y + $hHead, $x + $cW - $pad, $y + $hHead);
    $pdf->SetDrawColor(0, 0, 0);

    // Corps : photo + infos (mêmes champs que le modèle 1, sans fond coloré).
    $yBody  = $y + $hHead + 1.5;
    $photoW = $cW * 0.28; $photoH = $cH * 0.56;
    $pdf->SetDrawColor(150, 150, 150);
    $pdf->Rect($x + 1.5, $yBody, $photoW, $photoH, 'D');
    $pdf->SetDrawColor(0, 0, 0);
    carte_photo_box($pdf, $el, $x + 1.5, $yBody, $photoW, $photoH);

    $qrSize = max(6, min(9, $cH * 0.14));
    $xi = $x + $photoW + 3;
    $wi = $cW - $photoW - 4.5 - $qrSize - 1.5;
    $fs2 = max(4.5, min(6.5, $cW / 11));

    $pdf->SetFont('Arial', 'B', $fs2);
    $pdf->SetXY($xi, $yBody);
    $pdf->MultiCell($wi, 3.5, pdf_u(strtoupper($el['nom']) . "\n" . ($el['prenom'] ?? '')), 0, 'L');

    $infos5 = [
        'Cl./Class'  => mb_strimwidth($el['classe'] ?? '—', 0, 16, '…'),
        'Né/Born'    => $el['date_naiss'] ? date('d/m/Y', strtotime($el['date_naiss'])) : '—',
        'À/At'       => mb_strimwidth($el['lieu_naiss'] ?? '—', 0, 13, '…'),
        'NIU'        => id_affichage_eleve($el),
        'Sexe/Sex'   => $el['sexe'] === 'M' ? 'M / Masc.' : 'F / Fém.',
    ];
    foreach ($infos5 as $lbl => $val) {
        $pdf->SetFont('Arial', 'B', max(4, $fs2 - 1.5)); $pdf->SetX($xi);
        $pdf->Cell($wi * 0.36, 3, pdf_u($lbl . ':'), 0, 0);
        $pdf->SetFont('Arial', '', max(4, $fs2 - 1.5));
        $pdf->Cell($wi * 0.64, 3, pdf_u((string)$val), 0, 1);
    }

    $qr_tmp = carte_qr_tmp($el, $annee);
    try {
        $qrX = $x + $cW - $qrSize - 1;
        $qrY = $y + $cH - 5 - $qrSize - 1;
        $pdf->Image($qr_tmp, $qrX, $qrY, $qrSize, $qrSize, 'PNG');
    } finally {
        if (is_file($qr_tmp)) unlink($qr_tmp);
    }

    $yFoot = $y + $cH - 5;
    $pdf->SetDrawColor(120, 120, 120);
    $pdf->SetLineWidth(0.2);
    $pdf->Line($x + 1, $yFoot, $x + $cW - 1, $yFoot);
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetFont('Arial', 'B', 4);
    $pdf->SetXY($x + 1, $yFoot + 0.8);
    $pdf->Cell($cW - 2, 2.4, pdf_u(strtoupper($etab['chef_etablissement'] ?? 'LE PROVISEUR')), 0, 1, 'C');
    $pdf->SetFont('Arial', 'I', 3.5);
    $pdf->SetX($x + 1);
    $pdf->Cell($cW - 2, 2, pdf_u('Le Proviseur / The Principal'), 0, 0, 'C');

    // Signature numérique (sur demande uniquement, jamais automatique) :
    // placée sous la photo, seul espace libre disponible sur ce modèle épuré.
    if (($_GET['signature'] ?? '0') === '1') {
        $sig_y = $yBody + $photoH + 0.4;
        $sig_h = ($y + $cH - 5) - 0.3 - $sig_y;
        if ($sig_h > 2) {
            $sig_w = min($photoW * 0.85, $sig_h * 2.2);
            $sx = $x + 1.5 + ($photoW - $sig_w) / 2;
            pdf_signature_appliquer($pdf, 'carte_5', 'chef_etablissement', $x, $y, $cW, $cH, [
                'x_pct' => ($sx - $x) / $cW * 100, 'y_pct' => ($sig_y - $y) / $cH * 100,
                'w_pct' => $sig_w / $cW * 100, 'h_pct' => $sig_h / $cH * 100,
            ]);
        }
    }
}

// ════════════════════════════════════════════════
function dessiner_carte_verso(FPDF $pdf, array $el, array $etab, ?array $tuteur,
                               float $x, float $y, float $cW, float $cH): void
{
    // Même cadre que le recto (taille identique = alignement garanti au
    // retournement physique de la feuille).
    $pdf->SetDrawColor(30, 79, 216);
    $pdf->SetLineWidth(0.3);
    if (method_exists($pdf, 'RoundedRect')) {
        $pdf->RoundedRect($x, $y, $cW, $cH, 1.2, 'D');
    } else {
        $pdf->Rect($x, $y, $cW, $cH, 'D');
    }
    $pdf->SetLineWidth(0.2);

    // ── Bandeau entête (identique au recto, pour la cohérence visuelle) ──
    $hHead = $cH * 0.16;
    $pdf->SetFillColor(30, 79, 216);
    $pdf->Rect($x, $y, $cW, $hHead, 'F');
    $pdf->SetTextColor(255, 255, 255);
    $fs = max(4, min(6, $cW / 14));
    $pdf->SetFont('Arial', 'B', $fs);
    $pdf->SetXY($x + 1, $y + $hHead / 2 - $fs / 4.5);
    $pdf->Cell($cW - 2, 3, pdf_u('EN CAS DE PERTE, RETOURNER À / IF FOUND, RETURN TO :'), 0, 0, 'C');

    $pdf->SetTextColor(0);
    $yb  = $y + $hHead + 1.5;
    $pad = 1.5;
    $wb  = $cW - 2 * $pad;
    $fsB = max(4, min(6, $cW / 13));

    // ── Coordonnées de l'établissement ────────────────────────────
    $pdf->SetXY($x + $pad, $yb);
    $pdf->SetFont('Arial', 'B', $fsB);
    $pdf->MultiCell($wb, 3, pdf_u(strtoupper($etab['nom_fr'] ?? APP_NOM)), 0, 'C');
    $pdf->SetFont('Arial', '', max(3.8, $fsB - 1));
    $pdf->SetX($x + $pad);
    $coord = trim(($etab['ville'] ?? '') . ($etab['telephone'] ? ' · Tél./Phone ' . $etab['telephone'] : ''));
    $pdf->MultiCell($wb, 3, pdf_u($coord ?: '—'), 0, 'C');

    // ── Contact tuteur / parent + adresse de l'élève ──────────────
    $pdf->Ln(0.5);
    $pdf->SetX($x + $pad);
    $pdf->SetFont('Arial', 'B', max(3.8, $fsB - 1));
    $pdf->Cell($wb, 3, pdf_u('Contact parent/tuteur — Guardian :'), 0, 1, 'L');
    $pdf->SetX($x + $pad);
    $pdf->SetFont('Arial', '', max(3.8, $fsB - 1));
    $contact_tuteur = $tuteur
        ? trim(($tuteur['nom'] ?? '') . ' ' . ($tuteur['prenom'] ?? '')) . ($tuteur['telephone'] ? ' — ' . $tuteur['telephone'] : '')
        : '—';
    $pdf->MultiCell($wb, 3, pdf_u($contact_tuteur), 0, 'L');

    if (!empty($el['adresse'])) {
        $pdf->SetX($x + $pad);
        $pdf->SetFont('Arial', 'B', max(3.8, $fsB - 1));
        $pdf->Cell($wb, 3, pdf_u('Adresse/Address :'), 0, 1, 'L');
        $pdf->SetX($x + $pad);
        $pdf->SetFont('Arial', '', max(3.8, $fsB - 1));
        $pdf->MultiCell($wb, 3, pdf_u(mb_strimwidth($el['adresse'], 0, 60, '…')), 0, 'L');
    }

    // ── Signature (bas de carte) ───────────────────────────────────
    $ySig = $y + $cH - 9;
    $pdf->SetFont('Arial', 'I', max(3.5, $fsB - 1.5));
    $pdf->SetXY($x + $pad, $ySig);
    $pdf->Cell($wb, 3, pdf_u('Signature / Cachet — Stamp'), 0, 1, 'C');
    $pdf->SetFont('Arial', 'B', max(3.8, $fsB - 1));
    $pdf->SetX($x + $pad);
    $pdf->Cell($wb, 4, pdf_u(strtoupper($etab['chef_etablissement'] ?? 'LE PROVISEUR')), 0, 0, 'C');

    // Signature numérique (sur demande uniquement, jamais automatique).
    if (($_GET['signature'] ?? '0') === '1') {
        $sig_w = min($wb * 0.22, 14);
        $sig_h = 8;
        $sx = $x + $cW - $pad - $sig_w;
        $sy = $ySig - 0.5;
        pdf_signature_appliquer($pdf, 'carte_verso', 'chef_etablissement', $x, $y, $cW, $cH, [
            'x_pct' => ($sx - $x) / $cW * 100, 'y_pct' => ($sy - $y) / $cH * 100,
            'w_pct' => $sig_w / $cW * 100, 'h_pct' => $sig_h / $cH * 100,
        ]);
    }
}
