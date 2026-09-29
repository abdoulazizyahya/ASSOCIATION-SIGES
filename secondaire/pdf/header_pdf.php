<?php
// ── Fonctions communes pour les PDF ─────────────────────────
// Inclure après require_once fpdf.php

// Encodage commun pour tous les PDF du projet : FPDF (polices standard
// Arial/Helvetica) n'affiche correctement que du Windows-1252/ISO-8859-1,
// jamais de l'UTF-8 brut (chaque caractère accentué UTF-8 tient sur 2+
// octets et serait sinon rendu comme 2 caractères parasites). La BD stocke
// bien de l'UTF-8 correct (vérifié) — la conversion doit donc TOUJOURS se
// faire une seule fois, juste avant l'appel Cell()/MultiCell()/Write().
// Fonction canonique à utiliser dans tous les fichiers PDF du projet (au
// lieu de réimplémenter localement u()/pvpdf_u()/ud()/uc()...).
function pdf_u(?string $s): string {
    return mb_convert_encoding($s ?? '', 'Windows-1252', 'UTF-8');
}

// Filigrane (logo de l'établissement très éclairci, en fond de page) —
// généré une fois puis mis en cache sur disque (régénéré seulement si le
// logo source est modifié après coup). Centralisé ici (au lieu d'être
// dupliqué par fichier comme les fonctions QR/hash) car ce n'est pas du
// code de vérification/sécurité : une seule copie de l'algorithme suffit.
// $largeur_mm : largeur du filigrane ; 0 = 70% de $page_w (documents pleine
// page) — les appelants au format réduit (ex. cartes scolaires) passent une
// largeur adaptée à leur propre cadre et des coordonnées $x/$y explicites.
function pdf_filigrane_chemin(array $etab): ?string {
    $logo_src_path = !empty($etab['logo']) ? __DIR__ . '/../../assets/uploads/' . $etab['logo'] : '';
    if (!$logo_src_path || !is_file($logo_src_path)) return null;
    $dest_path = __DIR__ . '/../../assets/uploads/filigrane_logo.png';
    if (is_file($dest_path) && filemtime($dest_path) >= filemtime($logo_src_path)) return $dest_path;
    $src = @imagecreatefromstring(file_get_contents($logo_src_path));
    if (!$src) return null;
    $w = imagesx($src); $h = imagesy($src);
    $dst = imagecreatetruecolor($w, $h);
    $blanc = 0.90; // fraction d'éclaircissement vers le blanc
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $rgb = imagecolorat($src, $x, $y);
            $r = (int) round((($rgb >> 16) & 0xFF) * (1 - $blanc) + 255 * $blanc);
            $g = (int) round((($rgb >> 8)  & 0xFF) * (1 - $blanc) + 255 * $blanc);
            $b = (int) round(($rgb & 0xFF)          * (1 - $blanc) + 255 * $blanc);
            imagesetpixel($dst, $x, $y, imagecolorallocate($dst, $r, $g, $b));
        }
    }
    imagepng($dst, $dest_path);
    imagedestroy($src); imagedestroy($dst);
    return $dest_path;
}

function pdf_filigrane(FPDF $pdf, array $etab, float $page_w, float $page_h, float $largeur_mm = 0, ?float $x = null, ?float $y = null): void {
    $chemin = pdf_filigrane_chemin($etab);
    if (!$chemin) return;
    $w_fili = $largeur_mm > 0 ? $largeur_mm : $page_w * 0.70;
    $dim = @getimagesize($chemin);
    $h_fili = ($dim && $dim[0] > 0) ? $w_fili * $dim[1] / $dim[0] : $w_fili;
    $px = $x ?? (($page_w - $w_fili) / 2);
    $py = $y ?? (($page_h - $h_fili) / 2);
    $pdf->Image($chemin, $px, $py, $w_fili);
}

// Signature numérique — plusieurs signataires possibles (chef
// d'établissement, intendant, président APEE — voir table
// signature_titulaire), chacun sélectionné une fois par la personne
// habilitée (secondaire/pages/parametres/index.php ou secondaire/pages/paiements/signatures.php)
// — jamais appliquée automatiquement : chaque appelant ne dessine l'image
// que si l'utilisateur l'a explicitement demandé à l'impression (paramètre
// GET "signature", lu par l'appelant). Contrairement au filigrane, l'image
// n'est pas éclaircie : c'est une vraie signature, elle doit rester nette.
//
// La position/taille (en % du cadre — la page entière la plupart du temps,
// ou le cadre d'une seule carte pour pdf/cartes.php) est mémorisée par
// type de document dans signature_position, réglable via une fenêtre
// modale de glisser-déposer (layout/footer.php). $defaut fournit la valeur
// initiale tant que personne n'a encore reconfiguré ce document précis —
// aucun document ne casse avant configuration.
function pdf_signature_appliquer(FPDF $pdf, string $type_document, string $code,
                                  float $frameX, float $frameY, float $frameW, float $frameH,
                                  array $defaut): void {
    if (!signature_configuree($code)) return;
    $chemin = signature_chemin($code);
    $pos = get_signature_position($type_document, $code, $defaut);
    $x = $frameX + $frameW * $pos['x_pct'] / 100;
    $y = $frameY + $frameH * $pos['y_pct'] / 100;
    $w = $frameW * $pos['w_pct'] / 100;
    $h = $pos['h_pct'] !== null ? $frameH * $pos['h_pct'] / 100 : 0;
    $pdf->Image($chemin, $x, $y, $w, $h);
}

// Signature d'un enseignant précis (Professeur Principal d'une classe) —
// chemin direct plutôt qu'un code du catalogue signature_titulaire, car il
// y a un enseignant par signature, pas un rôle fixe unique. Position fixe
// (pas de fenêtre de glisser-déposer pour ce cas — un enseignant par classe
// parmi des dizaines, une configuration interactive par enseignant serait
// disproportionnée pour cette première version).
function pdf_signature_image_directe(FPDF $pdf, ?string $chemin, float $x, float $y, float $w, float $h = 0): void {
    if (!$chemin) return;
    $pdf->Image($chemin, $x, $y, $w, $h);
}

// $y : position verticale de départ, seulement si différente de $marge —
// nécessaire pour dessiner plusieurs en-têtes empilés sur UNE MÊME page (ex.
// reçus multi-copies de secondaire/pages/paiements/) sans qu'ils se superposent tous à
// $marge ; tous les appelants à un seul en-tête par page (cas normal) ne
// passent pas ce paramètre et gardent le comportement d'origine.
// $echelle : réduit proportionnellement tailles de police/interlignes/logo
// (défaut 1.0 = comportement d'origine inchangé) — nécessaire pour les reçus
// à 3 copies empilées par page (secondaire/pages/paiements/), où l'en-tête pleine taille
// ne laisserait pas assez de place pour le reste du contenu.
function pdf_entete(FPDF $pdf, array $etab, float $page_w, float $marge = 10, ?float $y = null, float $echelle = 1.0): void
{
    $col = ($page_w - 2 * $marge) / 3;
    $y0  = $y ?? $marge;
    $lh1 = 3.8 * $echelle; $lh2 = 4.5 * $echelle; $lh3 = 3.5 * $echelle;
    $taille_logo = 22 * $echelle;

    // ── Gauche (français) ────────────────────────────────
    $pdf->SetXY($marge, $y0);
    $pdf->SetFont('Arial', '', 7 * $echelle);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->MultiCell($col, $lh1, pdf_u(
        "REPUBLIQUE DU CAMEROUN\n" .
        ($etab['region_fr']         ?? "RÉGION DE L'ADAMAOUA") . "\n" .
        ($etab['departement_fr']    ?? 'DÉPARTEMENT DE LA VINA') . "\n" .
        ($etab['arrondissement_fr'] ?? 'ARRONDISSEMENT DE MBÉ')
    ), 0, 'C');
    $pdf->SetFont('Arial', 'B', 8.5 * $echelle);
    $pdf->SetX($marge);
    $pdf->MultiCell($col, $lh2, pdf_u(strtoupper($etab['nom_fr'] ?? 'LYCÉE TECHNIQUE DE MBÉ')), 0, 'C');
    $pdf->SetFont('Arial', '', 6.5 * $echelle);
    $pdf->SetX($marge);
    $pdf->MultiCell($col, $lh3, pdf_u(
        'B.P. ' . ($etab['boite_postale'] ?? '') . '  Tél.: ' . ($etab['telephone'] ?? '') . "\n" .
        ($etab['email'] ?? '')
    ), 0, 'C');

    // ── Centre (logo) ─────────────────────────────────────
    $logo_path = !empty($etab['logo'])
        ? __DIR__ . '/../../assets/uploads/' . $etab['logo'] : '';
    $logo_x = $marge + $col + ($col - $taille_logo) / 2;
    if ($logo_path && is_file($logo_path)) {
        $pdf->Image($logo_path, $logo_x, $y0, $taille_logo);
    } else {
        $pdf->SetFont('Arial', 'B', 9 * $echelle);
        $pdf->SetXY($logo_x, $y0 + 3);
        $pdf->Cell($taille_logo, 8 * $echelle, pdf_u($etab['sigle'] ?? 'MBE'), 1, 0, 'C');
    }

    // ── Droite (anglais) ──────────────────────────────────
    $xr = $marge + $col * 2;
    $pdf->SetXY($xr, $y0);
    $pdf->SetFont('Arial', '', 7 * $echelle);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->MultiCell($col, $lh1, pdf_u(
        "REPUBLIC OF CAMEROON\n" .
        ($etab['region_en']      ?? 'ADAMAWA REGION') . "\n" .
        ($etab['division_en']    ?? 'VINA DIVISION') . "\n" .
        ($etab['subdivision_en'] ?? 'MBE SUBDIVISION')
    ), 0, 'C');
    $pdf->SetFont('Arial', 'B', 8.5 * $echelle);
    $pdf->SetX($xr);
    $pdf->MultiCell($col, $lh2, pdf_u(strtoupper($etab['nom_en'] ?? 'GTHS OF MBE')), 0, 'C');
    $pdf->SetFont('Arial', '', 6.5 * $echelle);
    $pdf->SetX($xr);
    $pdf->MultiCell($col, $lh3, pdf_u(
        'P.O.BOX. ' . ($etab['boite_postale'] ?? '') . '  Phone: ' . ($etab['telephone'] ?? '') . "\n" .
        ($etab['email'] ?? '')
    ), 0, 'C');

    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(2 * $echelle);
}

// $titre_fr/$titre_en doivent être passés en UTF-8 BRUT (non pré-converti)
// par l'appelant — la conversion est faite une seule fois ici, en interne.
// Ligne en pointillés — segments courts répétés le long du vecteur (x1,y1)
// → (x2,y2), fonctionne aussi bien à l'horizontale (séparateur reçu/fiche)
// qu'à la verticale. Pas d'équivalent natif dans pdf/fpdf.php (contrairement
// à RoundedRect()/TextWithDirection() déjà présents, réutilisés tels quels
// pour les coins arrondis et le texte vertical — voir pdf_pilule() ci-dessous).
function pdf_ligne_pointillee(FPDF $pdf, float $x1, float $y1, float $x2, float $y2, float $tiret = 1.2, float $espace = 1): void {
    $longueur = sqrt(($x2 - $x1) ** 2 + ($y2 - $y1) ** 2);
    if ($longueur < 0.01) return;
    $pas = $tiret + $espace;
    $nb  = (int) ceil($longueur / $pas);
    $dx  = ($x2 - $x1) / $longueur;
    $dy  = ($y2 - $y1) / $longueur;
    for ($i = 0; $i < $nb; $i++) {
        $d0 = $i * $pas;
        $d1 = min($d0 + $tiret, $longueur);
        $pdf->Line($x1 + $dx * $d0, $y1 + $dy * $d0, $x1 + $dx * $d1, $y1 + $dy * $d1);
    }
}

// Fond dégradé diagonal (jaune pâle → rose pâle → vert pâle par défaut,
// réglable par l'utilisateur — voir get_reglage_paiement() et le formulaire
// de secondaire/pages/paiements/obligations.php) — visuel des documents MANWI repris à
// l'identique (chaque copie du reçu a son propre arrière-plan coloré,
// derrière le texte). pdf/fpdf.php n'a pas de gradient ni de chemin de
// découpe (clip) natif : une version précédente inclinait un grand
// rectangle via Rotate() pour l'effet diagonal, mais sa forme pivotée
// déborde largement du cadre (la diagonale d'un rectangle incliné est bien
// plus grande que le cadre lui-même) et repeint par-dessus le contenu déjà
// dessiné juste au-dessus/en-dessous (ex. chevauchement entre le reçu et la
// fiche de secondaire/pages/paiements/recu_fiche.php). Remplacé par une grille de
// petits rectangles strictement bornés à [x,x+w]×[y,y+h], dont la couleur
// suit un dégradé calculé sur la diagonale (i+j) — aucun débordement
// possible, quelle que soit la taille du cadre. À appeler AVANT le contenu
// (derrière tout le reste).
// $couleurs : 3 teintes [R,G,B] (voir hex_vers_rgb() dans fonctions.php) —
// par défaut les mêmes pastels que la version initiale si non fournies.
function pdf_fond_degrade(FPDF $pdf, float $x, float $y, float $w, float $h, ?array $couleurs = null): void {
    $couleurs ??= [[255, 246, 200], [255, 205, 210], [205, 232, 205]];
    $cols = 26; $rows = 18;
    $cw = $w / $cols; $ch = $h / $rows;
    for ($j = 0; $j < $rows; $j++) {
        for ($i = 0; $i < $cols; $i++) {
            $t = (($i + 0.5) / $cols + ($j + 0.5) / $rows) / 2;
            [$r, $g, $b] = pdf_interpoler_couleur($couleurs, $t);
            $pdf->SetFillColor($r, $g, $b);
            // Léger surplus (+0.3mm) pour ne pas laisser de liseré blanc
            // entre cellules, sans jamais dépasser le cadre lui-même.
            $pdf->Rect($x + $i * $cw, $y + $j * $ch, min($cw + 0.3, $w - $i * $cw), min($ch + 0.3, $h - $j * $ch), 'F');
        }
    }
}

function pdf_interpoler_couleur(array $couleurs, float $t): array {
    $n = count($couleurs) - 1;
    $seg = (int) min(floor($t * $n), $n - 1);
    $local = ($t * $n) - $seg;
    [$r1, $g1, $b1] = $couleurs[$seg];
    [$r2, $g2, $b2] = $couleurs[$seg + 1];
    return [
        (int) round($r1 + ($r2 - $r1) * $local),
        (int) round($g1 + ($g2 - $g1) * $local),
        (int) round($b1 + ($b2 - $b1) * $local),
    ];
}

// Bandeau "pilule" (coins totalement arrondis, $r = $h/2) — style des
// documents MANWI repris à l'identique (ex. "RECAPITULATIF DE PAIEMENT",
// "ASSOCIATION DES PARENTS D'ELEVES ET ENSEIGNANTS"), distinct du bandeau
// rectangulaire pdf_bandeau() déjà utilisé ailleurs dans le projet (bulletins
// etc.) — les deux styles coexistent, chacun sur ses propres documents.
function pdf_pilule(FPDF $pdf, string $texte, float $x, float $y, float $w, float $h, array $rgb = [65, 145, 225]): void {
    $pdf->SetFillColor($rgb[0], $rgb[1], $rgb[2]);
    $pdf->RoundedRect($x, $y, $w, $h, $h / 2, 'F');
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->SetXY($x, $y);
    $pdf->Cell($w, $h, pdf_u($texte), 0, 0, 'C');
    $pdf->SetTextColor(0, 0, 0);
}

// Titre encadré de chevrons "< texte >" (visuel du reçu APEE, ex. "REÇU
// A.P.E.E") — deux triangles pleins de part et d'autre du texte centré.
function pdf_titre_chevron(FPDF $pdf, string $texte, float $x, float $y, float $w, float $h): void {
    $pointe = $h * 0.4;
    $pdf->SetDrawColor(0);
    $pdf->SetLineWidth(0.4);
    // Chevron gauche : pointe vers l'intérieur (">")
    $pdf->Line($x, $y, $x + $pointe, $y + $h / 2);
    $pdf->Line($x + $pointe, $y + $h / 2, $x, $y + $h);
    // Chevron droit : pointe vers l'intérieur ("<")
    $pdf->Line($x + $w, $y, $x + $w - $pointe, $y + $h / 2);
    $pdf->Line($x + $w - $pointe, $y + $h / 2, $x + $w, $y + $h);
    $pdf->SetFont('Arial', 'B', 15);
    $pdf->SetXY($x, $y);
    $pdf->Cell($w, $h, pdf_u($texte), 0, 0, 'C');
}

function pdf_bandeau(FPDF $pdf, string $titre_fr, string $titre_en, float $page_w, float $marge = 10): void
{
    $w = $page_w - 2 * $marge;
    $pdf->SetFont('Arial', 'B', 13);
    $pdf->SetFillColor(214, 234, 248);
    $pdf->SetDrawColor(30, 79, 216);
    $pdf->SetTextColor(20, 40, 120);
    $pdf->SetX($marge);
    $pdf->Cell($w, 8, pdf_u($titre_fr), 1, 1, 'C', true);
    $pdf->SetFont('Arial', 'I', 8.5);
    $pdf->SetFillColor(235, 244, 255);
    $pdf->SetX($marge);
    $pdf->Cell($w, 5, pdf_u($titre_en), 1, 1, 'C', true);
    $pdf->SetTextColor(0);
    $pdf->SetDrawColor(0);
    $pdf->Ln(1);
}

// Copyright standard, bas de page — porté de pdf/header_pdf.php (primaire),
// absent de ce fichier jusqu'ici (jamais utilisé par les PDF secondaire
// existants). Ajouté pour secondaire/pdf/prive_*.php (module PAIEMENT
// PRIVÉ, demande explicite du 17/09/2026), qui réutilise cette convention
// plutôt que de réinventer une mention de bas de page.
function pdf_copyright(FPDF $pdf, float $page_w, float $page_h, float $marge_bas = 8): void {
    $pdf->SetFont('Arial', 'I', 6.5);
    $pdf->SetTextColor(120, 120, 120);
    $texte = pdf_u('Copyright © SIGES-V2 ABZ');
    $h = 4;
    $taille_police_mm = 6.5 / (72 / 25.4);
    $x = ($page_w - $pdf->GetStringWidth($texte)) / 2;
    $y = ($page_h - $marge_bas) + .5 * $h + .3 * $taille_police_mm;
    $pdf->Text($x, $y, $texte);
    $pdf->SetTextColor(0, 0, 0);
}
