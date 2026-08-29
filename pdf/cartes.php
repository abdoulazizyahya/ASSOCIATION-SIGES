<?php
// ── PDF : Cartes scolaires (5 modèles, recto/verso) ──────────────
// Port complet des 5 modèles d'ABZ_MBE, adapté au schéma jaynitaare.
// Formats de carte codés en dur ici plutôt qu'une table `format_carte`
// (absente du schéma jaynitaare, pas nécessaire pour 3 presets).
//
// Pas de QR imprimé sur la carte (décision explicite de l'utilisateur,
// 15/08/2026 — la carte physique reste telle quelle) MAIS l'accès public via
// jeton "vh" est quand même accepté ci-dessous, pour que verif_carte.php
// (qui existait déjà, construit sur ce mécanisme) ne pointe plus vers une
// impasse (redirection login) si jamais un lien de ce type est utilisé —
// cohérence interne, même si rien ne génère ce lien pour l'instant.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
require_once __DIR__ . '/verif_carte_lib.php';

$acces_public = false;
$val_annee_pub = '';
if (($_GET['vh'] ?? '') !== '' && (int) ($_GET['eleve'] ?? 0) > 0) {
    $val_annee_pub = (string) ($_GET['annee'] ?? '');
    $acces_public = carte_verif_valider((int) $_GET['eleve'], $val_annee_pub, (string) $_GET['vh']) !== null;
}
if (!$acces_public) {
    exiger_connexion();
    interdire_role('COMPTABLE', "Ce document n'est pas accessible au profil Agent financier / Comptable.");
}

require_once __DIR__ . '/fpdf.php';
require_once __DIR__ . '/header_pdf.php';

$id_modele = max(1, min(5, (int)($_GET['modele'] ?? 1)));
$id_format = (int)($_GET['format'] ?? 1);
// En accès public, la carte est TOUJOURS restreinte au seul élève vérifié —
// jamais à une classe entière (id_classe ignoré ci-dessous dans ce cas).
$id_classe = $acces_public ? 0 : (int)($_GET['classe'] ?? 0);
$id_seul   = $acces_public ? (int) $_GET['eleve'] : (int)($_GET['id'] ?? 0);
$dl        = ($_GET['dl'] ?? '0') === '1';
$verso     = ($_GET['verso'] ?? '0') === '1';
$flip      = ($_GET['flip'] ?? 'long') === 'short' ? 'short' : 'long';

// Presets de format (largeur, hauteur en mm) — codés en dur, pas de table
// format_carte dans le schéma jaynitaare (3 presets suffisent).
$formats = [
    1 => ['libelle' => 'Carte bancaire (CR80)', 'largeur_mm' => 85.6, 'hauteur_mm' => 54],
    2 => ['libelle' => 'ISO carte étudiant',     'largeur_mm' => 105,  'hauteur_mm' => 74],
    3 => ['libelle' => 'A4 pleine page',         'largeur_mm' => 210,  'hauteur_mm' => 297],
];
$format = $formats[$id_format] ?? $formats[1];

// En accès public, l'année est celle du jeton vérifié (peut différer de
// l'année active si la carte date d'une année scolaire précédente) — sinon
// l'année active courante, comme avant.
$annee     = get_annee_active();
$val_annee = $acces_public ? $val_annee_pub : ($annee['val_annee'] ?? '');

$where  = ["i.val_annee = ?", "e.statut='actif'"];
$params = [$val_annee];
if ($id_seul)    { $where[] = "e.id_eleve=?";   $params[] = $id_seul; }
if ($id_classe)  { $where[] = "i.IDClasses=?"; $params[] = $id_classe; }
$sql_where = 'WHERE ' . implode(' AND ', $where);

$eleves = db_all(
    "SELECT e.*, c.DesignationClasses AS classe
     FROM eleve e
     JOIN inscrire i ON i.id_eleve=e.id_eleve
     LEFT JOIN classe c ON c.IDClasses=i.IDClasses
     $sql_where ORDER BY e.Nom_elv, e.Prenom_elv", $params);

if (empty($eleves)) die('Aucun élève trouvé.');

$etab_brut = get_etablissement();
$etab      = etab_pour_pdf($etab_brut);

// Parent principal de chaque élève (pour le contact affiché au verso) —
// une seule requête groupée plutôt qu'une requête par élève dans la boucle.
$parents_idx = [];
if ($verso) {
    $ids_eleves = array_column($eleves, 'id_eleve');
    $in_ph = implode(',', array_fill(0, count($ids_eleves), '?'));
    foreach (db_all("SELECT * FROM parent WHERE id_eleve IN ($in_ph) ORDER BY id", $ids_eleves) as $p) {
        if (!isset($parents_idx[$p['id_eleve']])) $parents_idx[$p['id_eleve']] = $p; // le premier trouvé = principal
    }
}

// ── Dimensions ────────────────────────────────────────────────
$marge  = 8; $ecart = 3;
$page_w = 210; $page_h = 297;
$cW = min($format['largeur_mm'], $page_w - 2 * $marge);
$cH = min($format['hauteur_mm'], $page_h - 2 * $marge);

$nb_col  = max(1, (int)(($page_w - 2 * $marge + $ecart) / ($cW + $ecart)));
$nb_lig  = max(1, (int)(($page_h - 2 * $marge + $ecart) / ($cH + $ecart)));
$per_pag = $nb_col * $nb_lig;

// Enveloppé dans un try/catch — voir fonctions.php::pdf_erreur_generation()
// (jamais de fatal error brut ; ce document est public via QR et/ou
// consulté par du personnel qui ne doit pas voir de trace technique).
try {
$pdf = new FPDF('P', 'mm', 'A4');
$pdf->SetMargins($marge, $marge, $marge);
$pdf->SetAutoPageBreak(false);

$fn_recto = 'dessiner_carte_' . $id_modele;
// Le modèle 5 a son propre verso (dessiner_carte_5_verso(), design du
// modèle physique cs1.jpeg) — les modèles 1-4 continuent de partager
// dessiner_carte_verso() (voir commentaire au-dessus de cette fonction).
$fn_verso = $id_modele === 5 ? 'dessiner_carte_5_verso' : 'dessiner_carte_verso';

$lots = array_chunk($eleves, $per_pag);
foreach ($lots as $lot) {
    $pdf->AddPage();
    foreach ($lot as $pos => $el) {
        $col = $pos % $nb_col; $lig = (int)($pos / $nb_col);
        $x = $marge + $col * ($cW + $ecart); $y = $marge + $lig * ($cH + $ecart);
        $fn_recto($pdf, $el, $etab, $val_annee, $x, $y, $cW, $cH);
    }
    // Copyright standard du système sur la planche d'impression (pas sur
    // chaque carte individuelle — voir pdf_copyright(), pdf/header_pdf.php).
    pdf_copyright($pdf, $page_w, $page_h, 4);
    if ($verso) {
        $pdf->AddPage();
        foreach ($lot as $pos => $el) {
            $col = $pos % $nb_col; $lig = (int)($pos / $nb_col);
            if ($flip === 'long') { $col = $nb_col - 1 - $col; } else { $lig = $nb_lig - 1 - $lig; }
            $x = $marge + $col * ($cW + $ecart); $y = $marge + $lig * ($cH + $ecart);
            $fn_verso($pdf, $el, $etab, $parents_idx[$el['id_eleve']] ?? null, $x, $y, $cW, $cH);
        }
        pdf_copyright($pdf, $page_w, $page_h, 4);
    }
}

$pdf->Output($dl ? 'D' : 'I', 'cartes_scolaires_modele' . $id_modele . '_' . date('Ymd') . '.pdf');
} catch (Throwable $e) {
    pdf_erreur_generation($e);
}

// ── Aides communes ───────────────────────────────────────────
// Identifiant imprimé sur la carte (NIU si connu, sinon matricule).
function carte_identifiant(array $el): string {
    return $el['niu'] ?: $el['Mat_elv'];
}

// Photo de l'élève : BLOB écrit en fichier temporaire (repli avatar générique
// selon le sexe si absente/invalide — voir blob_est_image()). Chemin à
// nettoyer par l'appelant après le rendu de la carte (immédiatement après
// l'appel Image(), les octets sont déjà lus dans le flux PDF en mémoire).
function carte_photo_path_tmp(array $el): array {
    $tmp = photo_eleve_fichier_temp($el['Photo_elv'] ?? null, (int)$el['id_eleve']);
    if ($tmp) return [$tmp, true];
    $avatar = stripos($el['Sexe_elv'] ?? '', 'F') === 0 ? 'fille.png' : 'garcon.png';
    return [__DIR__ . '/../assets/img/avatars/' . $avatar, false];
}
function carte_photo_box(FPDF $pdf, array $el, float $x, float $y, float $w, float $h): void {
    [$chemin, $est_temp] = carte_photo_path_tmp($el);
    $pdf->Image($chemin, $x, $y, $w, $h);
    if ($est_temp && is_file($chemin)) @unlink($chemin);
}

// Filigrane discret dans le cadre de la carte (pdf_filigrane() centre sur
// toute la page, la carte est positionnée n'importe où sur la page : on
// centre donc précisément dans son propre cadre x,y,cW,cH).
function carte_filigrane(FPDF $pdf, array $etab, float $x, float $y, float $cW, float $cH): void {
    $chemin = pdf_filigrane_chemin($etab);
    if (!$chemin) return;
    $w_fili = $cW * 0.5;
    $dim = @getimagesize($chemin);
    $h_fili = ($dim && $dim[0] > 0) ? $w_fili * $dim[1] / $dim[0] : $w_fili;
    if ($h_fili > $cH * 0.85) {
        $h_fili = $cH * 0.85;
        $w_fili = ($dim && $dim[1] > 0) ? $h_fili * $dim[0] / $dim[1] : $h_fili;
    }
    $pdf->Image($chemin, $x + ($cW - $w_fili) / 2, $y + ($cH - $h_fili) / 2, $w_fili);
}

// Coin drapeau camerounais diagonal (triangle vert/rouge/jaune + étoile).
// FPDF n'a pas de primitive polygone remplie : généré comme un petit PNG via
// GD, fond transparent, fichier temporaire à supprimer par l'appelant.
function carte_flag_triangle_tmp(int $w_px = 200, int $h_px = 200): string {
    $w_px = max(20, $w_px); $h_px = max(20, $h_px);
    // Cache disque déterministe (nom dérivé des paramètres, jamais des
    // données élève) — trouvé le 21/08/2026 en auditant cartes.php : cette
    // vignette est purement décorative (mêmes couleurs/dimensions pour
    // TOUTE la classe), mais tempnam() lui donnait un nom aléatoire à
    // CHAQUE élève, regénérée par GD puis supprimée aussitôt après usage —
    // aucune réutilisation possible, ni au sein du lot, ni entre requêtes.
    // Même principe que le cache filigrane/QR (pdf/header_pdf.php,
    // pdf/verif_lib.php) et que le correctif Image()/Output() de fpdf.php.
    $tmp = sys_get_temp_dir() . '/jnflag_' . md5($w_px . '|' . $h_px) . '.png';
    if (is_file($tmp)) return $tmp;
    $img = imagecreatetruecolor($w_px, $h_px);
    imagesavealpha($img, true);
    $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
    imagefill($img, 0, 0, $transparent);
    $green  = imagecolorallocate($img, 0, 158, 73);
    $red    = imagecolorallocate($img, 206, 17, 38);
    $yellow = imagecolorallocate($img, 252, 209, 22);
    imagefilledpolygon($img, [0, 0, $w_px, 0, 0, $h_px], $yellow);
    imagefilledpolygon($img, [0, 0, (int) round($w_px * 2 / 3), 0, 0, (int) round($h_px * 2 / 3)], $red);
    imagefilledpolygon($img, [0, 0, (int) round($w_px / 3), 0, 0, (int) round($h_px / 3)], $green);
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
    imagepng($img, $tmp);
    imagedestroy($img);
    return $tmp;
}

// Étoile pleine (transparent autour) — même technique GD que
// carte_flag_triangle_tmp() ci-dessus (FPDF n'a pas de polygone rempli
// natif). Utilisée par le modèle 5 verso (emblème central sur le bandeau
// rouge, voir dessiner_carte_5_verso()).
function carte_etoile_tmp(int $w_px = 120, array $rgb = [252, 209, 22]): string {
    $w_px = max(20, $w_px);
    // Cache déterministe — voir carte_flag_triangle_tmp() ci-dessus, même
    // principe et même trouvaille (21/08/2026).
    $tmp = sys_get_temp_dir() . '/jnstar_' . md5($w_px . '|' . implode(',', $rgb)) . '.png';
    if (is_file($tmp)) return $tmp;
    $img  = imagecreatetruecolor($w_px, $w_px);
    imagesavealpha($img, true);
    $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
    imagefill($img, 0, 0, $transparent);
    $color = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
    $cx = $w_px / 2; $cy = $w_px / 2;
    $r1 = $w_px * 0.46; $r2 = $r1 * 0.42;
    $pts = [];
    for ($i = 0; $i < 10; $i++) {
        $ang = -M_PI / 2 + $i * M_PI / 5;
        $r = ($i % 2 === 0) ? $r1 : $r2;
        $pts[] = $cx + $r * cos($ang);
        $pts[] = $cy + $r * sin($ang);
    }
    imagefilledpolygon($img, $pts, $color);
    imagepng($img, $tmp);
    imagedestroy($img);
    return $tmp;
}

// Badge d'armoiries simplifié (écusson tricolore + étoile) — approximation
// stylisée des armoiries du Cameroun pour l'emblème droit du modèle 5 verso :
// le blason détaillé (faisceau, balance, palmes) n'est pas reproduit à
// l'échelle d'une carte scolaire, seuls la forme d'écusson et les couleurs
// nationales sont respectées. Même technique GD que carte_flag_triangle_tmp().
function carte_armoiries_tmp(int $w_px = 140, int $h_px = 160): string {
    $w_px = max(20, $w_px); $h_px = max(20, $h_px);
    // Cache déterministe — voir carte_flag_triangle_tmp() ci-dessus, même
    // principe et même trouvaille (21/08/2026).
    $tmp = sys_get_temp_dir() . '/jnarmo_' . md5($w_px . '|' . $h_px) . '.png';
    if (is_file($tmp)) return $tmp;
    $img  = imagecreatetruecolor($w_px, $h_px);
    imagesavealpha($img, true);
    $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
    imagefill($img, 0, 0, $transparent);
    $gold  = imagecolorallocate($img, 252, 209, 22);
    $green = imagecolorallocate($img, 0, 158, 73);
    $red   = imagecolorallocate($img, 206, 17, 38);
    $dark  = imagecolorallocate($img, 40, 30, 10);

    // Écusson (rectangle + pointe basse), rempli or, contour foncé.
    $ymid = (int) round($h_px * 0.62);
    $shield = [0, 0, $w_px - 1, 0, $w_px - 1, $ymid, (int) round($w_px / 2), $h_px - 1, 0, $ymid];
    imagefilledpolygon($img, $shield, $gold);
    imagepolygon($img, $shield, $dark);

    // Bandeau tricolore horizontal au centre de l'écusson.
    $by0 = (int) round($h_px * 0.34); $by1 = (int) round($h_px * 0.5);
    $bw  = (int) round($w_px * 0.2);
    imagefilledrectangle($img, 0, $by0, $bw, $by1, $green);
    imagefilledrectangle($img, $bw, $by0, $w_px - $bw, $by1, $red);
    imagefilledrectangle($img, $w_px - $bw, $by0, $w_px, $by1, $gold);
    imagerectangle($img, 0, $by0, $w_px - 1, $by1, $dark);

    // Petite étoile au sommet de l'écusson.
    $cx = $w_px / 2; $cy = $h_px * 0.20;
    $r1 = $w_px * 0.11; $r2 = $r1 * 0.42;
    $pts = [];
    for ($i = 0; $i < 10; $i++) {
        $ang = -M_PI / 2 + $i * M_PI / 5;
        $r = ($i % 2 === 0) ? $r1 : $r2;
        $pts[] = $cx + $r * cos($ang);
        $pts[] = $cy + $r * sin($ang);
    }
    imagefilledpolygon($img, $pts, $dark);

    imagepng($img, $tmp);
    imagedestroy($img);
    return $tmp;
}

// Bandeau "vague" décoratif (pied de carte) — silhouette approximée par un
// polygone suivant une sinusoïde, seule façon d'obtenir un bord courbe
// rempli avec FPDF (voir carte_flag_triangle_tmp() ci-dessus). Utilisé par
// le modèle 5 (recto ET verso, couleur différente).
function carte_vague_tmp(int $w_px, int $h_px, array $rgb): string {
    $w_px = max(20, $w_px); $h_px = max(10, $h_px);
    // Cache déterministe — voir carte_flag_triangle_tmp() ci-dessus, même
    // principe et même trouvaille (21/08/2026).
    $tmp = sys_get_temp_dir() . '/jnvague_' . md5($w_px . '|' . $h_px . '|' . implode(',', $rgb)) . '.png';
    if (is_file($tmp)) return $tmp;
    $img  = imagecreatetruecolor($w_px, $h_px);
    imagesavealpha($img, true);
    $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
    imagefill($img, 0, 0, $transparent);
    $color = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
    $pts = [];
    $n = 24;
    for ($i = 0; $i <= $n; $i++) {
        $px = $w_px * $i / $n;
        $py = $h_px * 0.35 + sin($i / $n * M_PI * 2 + M_PI / 2) * $h_px * 0.22;
        $pts[] = $px; $pts[] = $py;
    }
    $pts[] = (float) $w_px; $pts[] = (float) $h_px;
    $pts[] = 0.0; $pts[] = (float) $h_px;
    imagefilledpolygon($img, $pts, $color);
    imagepng($img, $tmp);
    imagedestroy($img);
    return $tmp;
}

// Cercle approximé par segments de droite (FPDF n'a pas de primitive
// cercle/ellipse native) — utilisé pour le sceau du modèle 3.
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

// Applique la signature numérique (sur demande uniquement, jamais
// automatique) à la position enregistrée par l'utilisateur pour ce modèle
// de carte précis (fenêtre de positionnement, layout/footer.php) — $defX/Y/W/H
// (coordonnées absolues déjà calculées par chaque modèle) servent de valeur
// par défaut tant qu'aucune position n'a encore été enregistrée pour
// $type_document. $frameX/Y/W/H : cadre de LA carte (pas la page — plusieurs
// cartes peuvent partager une même page), les % sont relatifs à ce cadre.
function carte_signature(FPDF $pdf, string $type_document, float $frameX, float $frameY, float $frameW, float $frameH,
                          float $defX, float $defY, float $defW, ?float $defH = null): void {
    if (($_GET['signature'] ?? '0') !== '1') return;
    pdf_signature_appliquer_jn($pdf, $type_document, $frameX, $frameY, $frameW, $frameH, [
        'x_pct' => ($defX - $frameX) / $frameW * 100,
        'y_pct' => ($defY - $frameY) / $frameH * 100,
        'w_pct' => $defW / $frameW * 100,
        'h_pct' => $defH !== null ? $defH / $frameH * 100 : null,
    ]);
}

// ════════════════════════════════════════════════
// MODÈLE 1 — en-tête bilingue 3 colonnes (FR/logo/EN), coin drapeau
// diagonal, titre établissement en grand bleu, barre noire arrondie
// décorative, champs bilingues sur 2 lignes (libellé FR gras + légende EN
// italique dessous).
// ════════════════════════════════════════════════
function dessiner_carte_1(FPDF $pdf, array $el, array $etab, string $val_annee,
                         float $x, float $y, float $cW, float $cH): void
{
    $pdf->SetDrawColor(150, 150, 150);
    $pdf->SetLineWidth(0.25);
    if (method_exists($pdf, 'RoundedRect')) { $pdf->RoundedRect($x, $y, $cW, $cH, 1.2, 'D'); }
    else { $pdf->Rect($x, $y, $cW, $cH, 'D'); }
    $pdf->SetLineWidth(0.2);

    carte_filigrane($pdf, $etab, $x, $y, $cW, $cH);

    $hLettre = $cH * 0.25;
    $flagW = $cW * 0.15;
    $flag_tmp = carte_flag_triangle_tmp(200, (int) round(200 * $hLettre / max(0.01, $flagW)));
    $pdf->Image($flag_tmp, $x, $y, $flagW, $hLettre, 'PNG');

    $logoW = $cW * 0.15;
    $colW  = ($cW - $flagW - $logoW) / 2;
    $xFr = $x + $flagW; $xLogo = $xFr + $colW; $xEn = $xLogo + $logoW;
    $fsHead = max(2.4, min(3.6, $cW / 28));

    $pdf->SetTextColor(0);
    $pdf->SetFont('Arial', 'B', $fsHead);
    $pdf->SetXY($xFr, $y + 0.3);
    $pdf->MultiCell($colW, 1.9, pdf_u(
        "REPUBLIQUE DU CAMEROUN\nPaix-Travail-Patrie\n" .
        ($etab['region_fr'] ?: "RÉGION DE L'ADAMAOUA") . "\n" .
        ($etab['departement_fr'] ?: 'DEPARTEMENT DE LA VINA') . "\n" .
        ($etab['arrondissement_fr'] ?: 'ARRONDISSEMENT')
    ), 0, 'L');
    $pdf->SetXY($xEn, $y + 0.3);
    $pdf->MultiCell($colW, 1.9, pdf_u(
        "REPUBLIC OF CAMEROON\nPeace-Work-Fatherland\n" .
        ($etab['region_en'] ?: 'ADAMAWA REGION') . "\n" .
        ($etab['division_en'] ?: 'VINA DIVISION') . "\n" .
        ($etab['subdivision_en'] ?: 'SUBDIVISION')
    ), 0, 'L');

    $logo_path = !empty($etab['logo']) ? __DIR__ . '/../assets/uploads/' . $etab['logo'] : '';
    $logoSize = min($logoW, $hLettre) * 0.9;
    if ($logo_path && is_file($logo_path)) {
        $pdf->Image($logo_path, $xLogo + ($logoW - $logoSize) / 2, $y + ($hLettre - $logoSize) / 2, $logoSize);
    } else {
        $pdf->SetFont('Arial', 'B', $fsHead + 1);
        $pdf->SetXY($xLogo, $y + $hLettre / 2 - 2);
        $pdf->Cell($logoW, 4, pdf_u($etab['sigle'] ?: 'JN'), 0, 0, 'C');
    }

    $yTitre = $y + $hLettre + 0.5;
    $hTitre = $cH * 0.11;
    $pdf->SetTextColor(30, 79, 216);
    $fsTitre = max(5, min(8, $cW / 10));
    $pdf->SetFont('Arial', 'B', $fsTitre);
    $pdf->SetXY($x + 1.5, $yTitre);
    $pdf->Cell($cW - 3, $hTitre, pdf_u($etab['nom_fr'] ?: APP_NOM), 0, 0, 'L');

    $yBar = $yTitre + $hTitre + 0.3;
    $hBar = max(1.2, $cH * 0.025);
    $pdf->SetFillColor(20, 20, 20);
    if (method_exists($pdf, 'RoundedRect')) { $pdf->RoundedRect($x + $cW * 0.28, $yBar, $cW * 0.60, $hBar, $hBar / 2, 'F'); }
    else { $pdf->Rect($x + $cW * 0.28, $yBar, $cW * 0.60, $hBar, 'F'); }

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
        ['Année scolaire: ' . $val_annee, 'School Year'],
        ['Nom et Prénom : ' . mb_strtoupper($el['Nom_elv']) . ' ' . ($el['Prenom_elv'] ?? ''), 'Name and surname'],
        ['Né(e) le: ' . ($el['Date_naiss_elv'] ? date('d/m/Y', strtotime($el['Date_naiss_elv'])) : '—'), 'Born on'],
        ['à: ' . mb_strimwidth($el['Lieu_naiss_elv'] ?: '—', 0, 18, '…'), 'Place'],
        ['Classe: ' . mb_strimwidth($el['classe'] ?: '—', 0, 16, '…'), 'Class'],
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

    $yFoot = $y + $cH - 5;
    $pdf->SetFont('Arial', 'B', max(3.8, $fsLbl - 0.5));
    $pdf->SetXY($x + 1.5, $yFoot);
    $pdf->Cell($cW - 3, 2.6, pdf_u(mb_strtoupper($etab['chef_etablissement'] ?: 'LE DIRECTEUR') . ','), 0, 1, 'L');
    $pdf->SetFont('Arial', 'I', max(3.2, $fsLbl - 1.3));
    $pdf->SetX($x + 1.5);
    $pdf->Cell($cW - 3, 2.2, pdf_u('The Director'), 0, 0, 'L');

    carte_signature($pdf, 'carte_1', $x, $y, $cW, $cH, $x + $cW - min(12, $cW * 0.14) - 1, $yFoot - 0.3, min(12, $cW * 0.14), 4.5);
}

// ════════════════════════════════════════════════
// MODÈLE 2 — coin drapeau diagonal, en-tête FR (gauche) / EN (droite),
// titre "CARTE D'IDENTITÉ SCOLAIRE" encadré bleu, sous-titre rouge italique,
// photo avec légende signature superposée, champs séparés.
// ════════════════════════════════════════════════
function dessiner_carte_2(FPDF $pdf, array $el, array $etab, string $val_annee,
                           float $x, float $y, float $cW, float $cH): void
{
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(0.25);
    $pdf->Rect($x, $y, $cW, $cH, 'D');

    carte_filigrane($pdf, $etab, $x, $y, $cW, $cH);

    $hHead = $cH * 0.20;
    $flagW = $cW * 0.15;
    $flag_tmp = carte_flag_triangle_tmp(200, (int) round(200 * $hHead / max(0.01, $flagW)));
    $pdf->Image($flag_tmp, $x, $y, $flagW, $hHead, 'PNG');

    $colW = ($cW - $flagW - 2) / 2;
    $xFr = $x + $flagW; $xEn = $xFr + $colW + 2;
    $fsHead = max(2.4, min(3.4, $cW / 30));
    $pdf->SetTextColor(0);
    $pdf->SetFont('Arial', 'B', $fsHead);
    $pdf->SetXY($xFr, $y + 0.3);
    $pdf->MultiCell($colW, 1.9, pdf_u(
        "REPUBLIQUE DU CAMEROUN\nPaix - Travail - Patrie\n" .
        ($etab['region_fr'] ?: "RÉGION DE L'ADAMAOUA") . "\n" .
        ($etab['departement_fr'] ?: 'DEPARTEMENT') . "\n" .
        ($etab['arrondissement_fr'] ?: 'ARRONDISSEMENT')
    ), 0, 'C');
    $pdf->SetXY($xEn, $y + 0.3);
    $pdf->MultiCell($colW, 1.9, pdf_u(
        "REPUBLIC OF CAMEROON\nPeace - Work - Fatherland\n" .
        ($etab['region_en'] ?: 'ADAMAWA REGION') . "\n" .
        ($etab['division_en'] ?: 'DIVISION') . "\n" .
        ($etab['subdivision_en'] ?: 'SUBDIVISION')
    ), 0, 'C');

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
    if (method_exists($pdf, 'RoundedRect')) { $pdf->RoundedRect($tx, $yTitre, $tw, $th, $th / 2, 'D'); }
    else { $pdf->Rect($tx, $yTitre, $tw, $th, 'D'); }
    $pdf->SetLineWidth(0.2);
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetXY($tx, $yTitre);
    $pdf->Cell($tw, $th, pdf_u($titre), 0, 0, 'C');

    $ySub = $yTitre + $th + 0.5;
    $pdf->SetFont('Arial', 'I', max(3.5, $fsTitre - 3));
    $pdf->SetTextColor(190, 20, 20);
    $pdf->SetXY($x + 1, $ySub);
    $pdf->Cell($cW - 2, 2.6, pdf_u('SCHOOL IDENTITY CARD'), 0, 0, 'C');

    $pdf->SetTextColor(0);
    $yBody  = $ySub + 3.2;
    $photoW = $cW * 0.27;
    $photoH = min($cH * 0.40, ($y + $cH - 3) - $yBody - 5);
    carte_photo_box($pdf, $el, $x + 1.5, $yBody, $photoW, max(8, $photoH));
    $pdf->SetFont('Arial', 'I', max(2.8, $cW / 30));
    $pdf->SetXY($x + 1.5, $yBody + max(8, $photoH) + 0.3);
    $pdf->MultiCell($photoW, 1.7, pdf_u(mb_strtoupper($etab['chef_etablissement'] ?: 'LE DIRECTEUR') . "\nLe Directeur"), 0, 'C');
    carte_signature($pdf, 'carte_2', $x, $y, $cW, $cH, $x + 1.5 + ($photoW - min(14, $photoW)) / 2, $pdf->GetY() + 0.3, min(14, $photoW), 5);

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
    $ligne('Nom :', mb_strtoupper($el['Nom_elv']));
    $pdf->SetX($xi);
    $ligne('Prénom :', $el['Prenom_elv'] ?: '—');

    $pdf->SetX($xi);
    $pdf->SetFont('Arial', 'B', $fs2); $pdf->SetTextColor(30, 79, 216);
    $pdf->Cell($wi * 0.32, 3, pdf_u('Né(e) le :'), 0, 0);
    $pdf->SetTextColor(0);
    $pdf->Cell($wi * 0.36, 3, pdf_u($el['Date_naiss_elv'] ? date('d/m/Y', strtotime($el['Date_naiss_elv'])) : '—'), 0, 0);
    $pdf->SetTextColor(30, 79, 216);
    $pdf->Cell($wi * 0.14, 3, pdf_u('Sexe :'), 0, 0);
    $pdf->SetTextColor(0);
    $pdf->Cell($wi * 0.18, 3, pdf_u(stripos($el['Sexe_elv'], 'F') === 0 ? 'F' : 'M'), 0, 1);

    $pdf->SetX($xi);
    $ligne('À :', mb_strimwidth($el['Lieu_naiss_elv'] ?: '—', 0, 16, '…'));
    $pdf->SetX($xi);
    $ligne('Classe :', mb_strimwidth($el['classe'] ?: '—', 0, 16, '…'));
    $pdf->SetX($xi);
    $ligne('NIU :', carte_identifiant($el));
}

// ════════════════════════════════════════════════
// MODÈLE 3 — nom d'établissement sur 2 lignes, bandeau gris "CARTE
// D'IDENTITÉ SCOLAIRE", bandeau vertical bleu pleine hauteur affichant
// l'année scolaire, champs bilingues, Classe/NIU en rouge, sceau + signature.
// ════════════════════════════════════════════════
function dessiner_carte_3(FPDF $pdf, array $el, array $etab, string $val_annee,
                           float $x, float $y, float $cW, float $cH): void
{
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(0.25);
    $pdf->Rect($x, $y, $cW, $cH, 'D');

    $bannerW = $cW * 0.14;
    $mainW   = $cW - $bannerW;

    carte_filigrane($pdf, $etab, $x, $y, $mainW, $cH);

    $pdf->SetFillColor(30, 79, 216);
    $pdf->Rect($x + $mainW, $y, $bannerW, $cH, 'F');
    $parts = array_pad(explode('/', $val_annee), 2, '');
    $pdf->SetTextColor(255, 255, 255);
    $fsAnnee = max(6, min(11, $bannerW * 0.9));
    $pdf->SetFont('Arial', 'B', $fsAnnee);
    $pdf->SetXY($x + $mainW, $y + $cH * 0.30);
    $pdf->Cell($bannerW, $cH * 0.16, pdf_u($parts[0]), 0, 0, 'C');
    $pdf->SetXY($x + $mainW, $y + $cH * 0.55);
    $pdf->Cell($bannerW, $cH * 0.16, pdf_u($parts[1]), 0, 0, 'C');

    $pdf->SetTextColor(30, 79, 216);
    $fsNom = max(5, min(8, $mainW / 12));
    $pdf->SetFont('Arial', 'B', $fsNom);
    $pdf->SetXY($x + 1, $y + 0.5);
    $pdf->Cell($mainW - 2, $fsNom * 0.5, pdf_u($etab['nom_fr'] ?: APP_NOM), 0, 1, 'C');
    $pdf->SetTextColor(0);
    $pdf->SetFont('Arial', 'B', max(4.5, $fsNom - 1));
    $pdf->SetX($x + 1);
    $pdf->Cell($mainW - 2, $fsNom * 0.45, pdf_u($etab['sigle'] ?: ($etab['nom_en'] ?: '')), 0, 1, 'C');

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

    $pdf->SetTextColor(0);
    $yBody = $yBleu + $hBleu + 1;
    $fsLbl = max(3.6, min(5.5, $mainW / 13));
    $fsSub = max(3, $fsLbl - 1.2);
    $champs = [
        ['Nom(s) : ' . mb_strtoupper($el['Nom_elv']) . ' ' . ($el['Prenom_elv'] ?? ''), 'Name(s)'],
        ['Né(e) le : ' . ($el['Date_naiss_elv'] ? date('d/m/Y', strtotime($el['Date_naiss_elv'])) : '—'), 'Born on'],
        ['À : ' . mb_strimwidth($el['Lieu_naiss_elv'] ?: '—', 0, 16, '…'), 'At'],
        ['Sexe : ' . (stripos($el['Sexe_elv'], 'F') === 0 ? 'F' : 'M'), 'Sex'],
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
    $pdf->Cell($mainW - 3, 3, pdf_u('Classe : ' . mb_strimwidth($el['classe'] ?: '—', 0, 18, '…')), 0, 1, 'L');
    $pdf->SetX($x + 1.5);
    $pdf->Cell($mainW - 3, 3, pdf_u('NIU : ' . carte_identifiant($el)), 0, 1, 'L');
    $pdf->SetTextColor(0);

    $sealR = min($mainW * 0.10, $cH * 0.13);
    $sealCx = $x + $mainW - $sealR - 3;
    $sealCy = $y + $cH - $sealR - 8;
    $pdf->SetDrawColor(150, 20, 20);
    $pdf->SetLineWidth(0.3);
    carte_circle_outline($pdf, $sealCx, $sealCy, $sealR);
    carte_circle_outline($pdf, $sealCx, $sealCy, $sealR * 0.7);
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(0.2);
    carte_signature($pdf, 'carte_3', $x, $y, $cW, $cH, $sealCx - $sealR * 0.6, $sealCy - $sealR * 0.6, $sealR * 1.2, $sealR * 1.2);
    $sigW = min($mainW - 3, $sealR * 3.6);
    $pdf->SetFont('Arial', 'I', max(2.8, $sealR * 0.32));
    $pdf->SetTextColor(150, 20, 20);
    $pdf->SetXY($sealCx - $sigW / 2, $sealCy + $sealR + 0.8);
    $pdf->MultiCell($sigW, 2, pdf_u(mb_strtoupper($etab['chef_etablissement'] ?: 'LE DIRECTEUR')), 0, 'C');
    $pdf->SetTextColor(0);
}

// ════════════════════════════════════════════════
// MODÈLE 4 — "Badge centré" : composition entièrement centrée (photo carrée
// centrée, nom en dessous, infos centrées), fines lignes d'accent turquoise
// en haut/bas du cadre.
// ════════════════════════════════════════════════
function dessiner_carte_4(FPDF $pdf, array $el, array $etab, string $val_annee,
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
    $pdf->Cell($cW - 2 * $pad, $cH * 0.05, pdf_u(mb_strtoupper($etab['sigle'] ?: 'JN') . ' — ' . ($etab['nom_fr'] ?: '')), 0, 1, 'C');
    $pdf->SetFont('Arial', 'I', max(3.5, $fsEtab - 1));
    $pdf->SetX($x + $pad);
    $pdf->Cell($cW - 2 * $pad, $cH * 0.04, pdf_u("Carte d'identité scolaire"), 0, 0, 'C');
    $yCur += $cH * 0.14;

    $photoSize = min($cH * 0.32, $cW * 0.30);
    $photoX = $x + ($cW - $photoSize) / 2;
    $pdf->SetDrawColor($accent[0], $accent[1], $accent[2]);
    $pdf->SetLineWidth(0.5);
    $pdf->Rect($photoX - 0.6, $yCur - 0.6, $photoSize + 1.2, $photoSize + 1.2, 'D');
    $pdf->SetLineWidth(0.2);
    $pdf->SetDrawColor(200, 200, 205);
    carte_photo_box($pdf, $el, $photoX, $yCur, $photoSize, $photoSize);
    $yCur += $photoSize + $cH * 0.03;

    $pdf->SetTextColor(0);
    $fsNom = max(4.5, min(6.5, $cW / 11));
    $pdf->SetFont('Arial', 'B', $fsNom);
    $pdf->SetXY($x + $pad, $yCur);
    $pdf->Cell($cW - 2 * $pad, $cH * 0.06, pdf_u(mb_strtoupper($el['Nom_elv']) . ' ' . ($el['Prenom_elv'] ?? '')), 0, 0, 'C');
    $yCur += $cH * 0.08;

    $fs2 = max(3.6, min(5.5, $cW / 13));
    $hLigne = $cH * 0.038;
    // [libellé FR complet, valeur, libellé EN seul (caption, pas de valeur
    // répétée)] — FR/EN jamais sur la même ligne, interligne resserré.
    $infos4 = [
        ['Classe', mb_strimwidth($el['classe'] ?: '—', 0, 16, '…'), 'Class'],
        ['Né(e) le', $el['Date_naiss_elv'] ? date('d/m/Y', strtotime($el['Date_naiss_elv'])) : '—', 'Born on'],
        ['NIU', carte_identifiant($el), null],
        ['Sexe', stripos($el['Sexe_elv'], 'F') === 0 ? 'Féminin' : 'Masculin', 'Sex'],
    ];
    foreach ($infos4 as [$lbl, $val, $en]) {
        $pdf->SetFont('Arial', '', $fs2);
        $pdf->SetXY($x + $pad, $yCur);
        $pdf->Cell($cW - 2 * $pad, $hLigne, pdf_u($lbl . ' : ' . $val), 0, 1, 'C');
        $yCur += $hLigne;
        if ($en !== null) {
            $pdf->SetFont('Arial', 'I', max(2.8, $fs2 - 1.3));
            $pdf->SetX($x + $pad);
            $pdf->Cell($cW - 2 * $pad, $hLigne * 0.75, pdf_u($en), 0, 1, 'C');
            $yCur += $hLigne * 0.75;
        }
    }
    $yCur += $cH * 0.02;

    $pdf->SetFont('Arial', 'I', max(3.2, $fs2 - 0.5));
    $pdf->SetXY($x + $pad, $yCur);
    $pdf->Cell($cW - 2 * $pad, $cH * 0.03, pdf_u(mb_strtoupper($etab['chef_etablissement'] ?: 'LE DIRECTEUR')), 0, 1, 'C');
    $pdf->SetFont('Arial', 'I', max(2.8, $fs2 - 1.3));
    $pdf->SetX($x + $pad);
    $pdf->Cell($cW - 2 * $pad, $cH * 0.025, 'The Director', 0, 0, 'C');
    $yCur = $pdf->GetY();

    if ($y + $cH - ($yCur + $cH * 0.04) > 6) {
        carte_signature($pdf, 'carte_4', $x, $y, $cW, $cH, $x + ($cW - min(16, $cW * 0.18)) / 2, $yCur + $cH * 0.04 + 0.5, min(16, $cW * 0.18));
    }
}

// ════════════════════════════════════════════════
// MODÈLE 5 — reconstruction du modèle physique fourni par l'établissement
// (BD JAYNITARE/modele/cs2.jpeg pour ce recto, cs1.jpeg pour le verso dédié
// dessiner_carte_5_verso() ci-dessous, remplace le 15/08/2026 l'ancien
// modèle « minimaliste ») : en-tête bilingue République/Devise/Région/
// Département/Arrondissement de part et d'autre du logo, bandeau vert
// « CARTE D'IDENTITE SCOLAIRE » + année scolaire, photo + état civil,
// vague verte décorative en pied — même style, couleurs et forme que le
// modèle de référence, données prises dans notre BD (etab_pour_pdf()).
// ════════════════════════════════════════════════
function dessiner_carte_5(FPDF $pdf, array $el, array $etab, string $val_annee,
                           float $x, float $y, float $cW, float $cH): void
{
    $pdf->SetDrawColor(120, 120, 120);
    $pdf->SetLineWidth(0.25);
    if (method_exists($pdf, 'RoundedRect')) { $pdf->RoundedRect($x, $y, $cW, $cH, 1.2, 'D'); }
    else { $pdf->Rect($x, $y, $cW, $cH, 'D'); }
    $pdf->SetLineWidth(0.2);

    carte_filigrane($pdf, $etab, $x, $y, $cW, $cH);

    // ── En-tête bilingue (République/Devise/Région/Département/
    //    Arrondissement de part et d'autre du logo), même contenu que
    //    pdf_entete()/modèle 1 compressé sur 5 lignes minuscules.
    $hHead = $cH * 0.27;
    $logoW = $cW * 0.16;
    $colW  = ($cW - $logoW - 3) / 2;
    $xFr = $x + 1; $xLogo = $xFr + $colW + 1; $xEn = $xLogo + $logoW + 1;
    $fsHead = max(2.2, min(3.2, $cW / 32));

    $pdf->SetTextColor(0);
    $pdf->SetFont('Arial', '', $fsHead);
    $pdf->SetXY($xFr, $y + 0.6);
    $pdf->MultiCell($colW, $fsHead * 0.62, pdf_u(
        "REPUBLIQUE DU CAMEROUN\nPaix - Travail - Patrie\n" .
        ($etab['region_fr'] ?: "RÉGION DE L'ADAMAOUA") . "\n" .
        ($etab['departement_fr'] ?: 'DEPARTEMENT DE LA VINA') . "\n" .
        ($etab['arrondissement_fr'] ?: 'ARRONDISSEMENT')
    ), 0, 'C');
    $pdf->SetXY($xEn, $y + 0.6);
    $pdf->MultiCell($colW, $fsHead * 0.62, pdf_u(
        "REPUBLIC OF CAMEROON\nPeace - Work - Fatherland\n" .
        ($etab['region_en'] ?: 'ADAMAWA REGION') . "\n" .
        ($etab['division_en'] ?: 'VINA DIVISION') . "\n" .
        ($etab['subdivision_en'] ?: 'SUBDIVISION')
    ), 0, 'C');

    $logo_path = !empty($etab['logo']) ? __DIR__ . '/../assets/uploads/' . $etab['logo'] : '';
    $logoSize = min($logoW, $hHead * 0.75);
    if ($logo_path && is_file($logo_path)) {
        $pdf->Image($logo_path, $xLogo + ($logoW - $logoSize) / 2, $y + 1, $logoSize);
    } else {
        $pdf->SetFont('Arial', 'B', $fsHead + 1);
        $pdf->SetXY($xLogo, $y + $hHead / 2 - 2);
        $pdf->Cell($logoW, 4, pdf_u($etab['sigle'] ?: 'JN'), 0, 0, 'C');
    }

    // ── Bandeau vert « CARTE D'IDENTITE SCOLAIRE » ────────────────
    $yTitre = $y + $hHead + 0.5;
    $hTitre = $cH * 0.21;
    $pdf->SetFillColor(0, 122, 61);
    if (method_exists($pdf, 'RoundedRect')) { $pdf->RoundedRect($x + 0.8, $yTitre, $cW - 1.6, $hTitre, 0.8, 'F'); }
    else { $pdf->Rect($x + 0.8, $yTitre, $cW - 1.6, $hTitre, 'F'); }

    $pdf->SetTextColor(255, 255, 255);
    $fsNom = max(4, min(6.5, $cW / 13));
    $pdf->SetFont('Arial', 'B', $fsNom);
    $pdf->SetXY($x + 2, $yTitre + $hTitre * 0.08);
    $pdf->Cell($cW - 4, $hTitre * 0.36, pdf_u(mb_strimwidth(mb_strtoupper($etab['nom_fr'] ?: APP_NOM), 0, 34, '…')), 0, 1, 'C');
    $pdf->SetTextColor(252, 209, 22);
    $pdf->SetFont('Arial', 'B', max(3.2, $fsNom - 1.5));
    $pdf->SetX($x + 2);
    $pdf->Cell($cW - 4, $hTitre * 0.3, pdf_u("CARTE D'IDENTITE SCOLAIRE"), 0, 1, 'C');
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'I', max(2.8, $fsNom - 2.5));
    $pdf->SetX($x + 2);
    $pdf->Cell($cW - 4, $hTitre * 0.26, pdf_u('Année Scolaire ' . $val_annee), 0, 0, 'C');
    $pdf->SetTextColor(0);

    // ── Corps : photo + état civil ─────────────────────────────
    $yBody  = $yTitre + $hTitre + 1.3;
    $photoW = $cW * 0.27; $photoH = $cH * 0.36;
    $pdf->SetDrawColor(150, 150, 150);
    $pdf->Rect($x + 1.8, $yBody, $photoW, $photoH, 'D');
    $pdf->SetDrawColor(0, 0, 0);
    carte_photo_box($pdf, $el, $x + 1.8, $yBody, $photoW, $photoH);

    $xi = $x + $photoW + 4; $wi = $cW - $photoW - 6.5;
    $fs2 = max(3.6, min(5, $cW / 17));
    $champs5 = [
        ['Nom', mb_strtoupper($el['Nom_elv']), 'Name'],
        ['Prénom', $el['Prenom_elv'] ?? '—', 'Surname'],
        ['Né(e) le', $el['Date_naiss_elv'] ? date('d/m/Y', strtotime($el['Date_naiss_elv'])) : '—', 'Born on'],
        ['À', mb_strimwidth($el['Lieu_naiss_elv'] ?: '—', 0, 18, '…'), 'At'],
        ['Classe', mb_strimwidth($el['classe'] ?: '—', 0, 16, '…'), 'Class'],
    ];
    $yi = $yBody;
    foreach ($champs5 as [$lbl, $val, $en]) {
        $pdf->SetFont('Arial', 'B', $fs2); $pdf->SetTextColor(0);
        $pdf->SetXY($xi, $yi);
        $pdf->Cell($wi * 0.34, 2.4, pdf_u($lbl . ' :'), 0, 0);
        $pdf->SetTextColor(30, 79, 216);
        $pdf->Cell($wi * 0.66, 2.4, pdf_u((string) $val), 0, 1);
        $pdf->SetFont('Arial', 'I', max(2.6, $fs2 - 1.2));
        $pdf->SetTextColor(180, 30, 40);
        $pdf->SetX($xi + $wi * 0.34);
        $pdf->Cell($wi * 0.66, 1.8, pdf_u($en), 0, 1);
        $yi = $pdf->GetY() + 0.2;
    }
    $pdf->SetTextColor(0);

    // ── Vague verte décorative + pied (Directeur/Director) ────────
    $hVague = max(3.5, $cH * 0.09);
    $yVague = $y + $cH - $hVague;
    $vague_tmp = carte_vague_tmp((int) round($cW * 4), (int) round($hVague * 4), [0, 122, 61]);
    $pdf->Image($vague_tmp, $x, $yVague, $cW, $hVague, 'PNG');

    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', max(3.2, $fs2 - 0.5));
    $pdf->SetXY($x + 1, $y + $cH - $hVague * 0.62);
    $pdf->Cell($cW - 2, 2.2, pdf_u(mb_strtoupper($etab['chef_etablissement'] ?: 'LE DIRECTEUR')), 0, 1, 'C');
    $pdf->SetFont('Arial', 'I', max(2.6, $fs2 - 1.6));
    $pdf->SetX($x + 1);
    $pdf->Cell($cW - 2, 1.8, 'The Director', 0, 0, 'C');
    $pdf->SetTextColor(0);

    $sig_y = $yBody + $photoH + 0.5;
    $sig_h = $yVague - 0.4 - $sig_y;
    if ($sig_h > 2) {
        $sig_w = min($photoW * 0.85, $sig_h * 2.2);
        carte_signature($pdf, 'carte_5', $x, $y, $cW, $cH, $x + 1.8 + ($photoW - $sig_w) / 2, $sig_y, $sig_w, $sig_h);
    }
}

// ════════════════════════════════════════════════
// VERSO commun aux 5 modèles.
// ════════════════════════════════════════════════
function dessiner_carte_verso(FPDF $pdf, array $el, array $etab, ?array $parent,
                               float $x, float $y, float $cW, float $cH): void
{
    $pdf->SetDrawColor(30, 79, 216);
    $pdf->SetLineWidth(0.3);
    if (method_exists($pdf, 'RoundedRect')) { $pdf->RoundedRect($x, $y, $cW, $cH, 1.2, 'D'); }
    else { $pdf->Rect($x, $y, $cW, $cH, 'D'); }
    $pdf->SetLineWidth(0.2);

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

    $pdf->SetXY($x + $pad, $yb);
    $pdf->SetFont('Arial', 'B', $fsB);
    $pdf->MultiCell($wb, 3, pdf_u(mb_strtoupper($etab['nom_fr'] ?: APP_NOM)), 0, 'C');
    $pdf->SetFont('Arial', '', max(3.8, $fsB - 1));
    $pdf->SetX($x + $pad);
    $coord = trim(($etab['ville'] ?: '') . ($etab['telephone'] ? ' · Tél./Phone ' . $etab['telephone'] : ''));
    $pdf->MultiCell($wb, 3, pdf_u($coord ?: '—'), 0, 'C');

    $pdf->Ln(0.5);
    $pdf->SetX($x + $pad);
    $pdf->SetFont('Arial', 'B', max(3.8, $fsB - 1));
    $pdf->Cell($wb, 3, pdf_u('Contact parent/tuteur — Guardian :'), 0, 1, 'L');
    $pdf->SetX($x + $pad);
    $pdf->SetFont('Arial', '', max(3.8, $fsB - 1));
    $contact = $parent
        ? trim(($parent['nom'] ?? '') . ' ' . ($parent['prenom'] ?? '')) . ($parent['adresse'] ? ' — ' . $parent['adresse'] : '')
        : '—';
    $pdf->MultiCell($wb, 3, pdf_u($contact), 0, 'L');

    if (!empty($el['Adresse_elv'])) {
        $pdf->SetX($x + $pad);
        $pdf->SetFont('Arial', 'B', max(3.8, $fsB - 1));
        $pdf->Cell($wb, 3, pdf_u('Adresse/Address :'), 0, 1, 'L');
        $pdf->SetX($x + $pad);
        $pdf->SetFont('Arial', '', max(3.8, $fsB - 1));
        $pdf->MultiCell($wb, 3, pdf_u(mb_strimwidth($el['Adresse_elv'], 0, 60, '…')), 0, 'L');
    }

    $ySig = $y + $cH - 9;
    $pdf->SetFont('Arial', 'I', max(3.5, $fsB - 1.5));
    $pdf->SetXY($x + $pad, $ySig);
    $pdf->Cell($wb, 3, pdf_u('Signature / Cachet — Stamp'), 0, 1, 'C');
    $pdf->SetFont('Arial', 'B', max(3.8, $fsB - 1));
    $pdf->SetX($x + $pad);
    $pdf->Cell($wb, 4, pdf_u(mb_strtoupper($etab['chef_etablissement'] ?: 'LE DIRECTEUR')), 0, 0, 'C');

    carte_signature($pdf, 'carte_verso', $x, $y, $cW, $cH, $x + $cW - $pad - min($wb * 0.22, 14), $ySig - 0.5, min($wb * 0.22, 14), 8);
}

// ════════════════════════════════════════════════
// VERSO du modèle 5 — reconstruction du modèle physique fourni par
// l'établissement (BD JAYNITARE/modele/cs1.jpeg) : bandes tricolores
// vert/rouge/jaune avec logo + étoile + armoiries simplifiée, ruban de
// devise, coordonnées, vague turquoise avec lieu/date/signature/cachet et
// validité de l'année scolaire — même style, couleurs et forme que le
// modèle de référence, données prises dans notre BD. Dédié au modèle 5 :
// les modèles 1-4 continuent d'utiliser dessiner_carte_verso() ci-dessus
// (appel conditionnel selon $id_modele dans le script principal).
// ════════════════════════════════════════════════
function dessiner_carte_5_verso(FPDF $pdf, array $el, array $etab, ?array $parent,
                                 float $x, float $y, float $cW, float $cH): void
{
    $pdf->SetDrawColor(120, 120, 120);
    $pdf->SetLineWidth(0.25);
    if (method_exists($pdf, 'RoundedRect')) { $pdf->RoundedRect($x, $y, $cW, $cH, 1.2, 'D'); }
    else { $pdf->Rect($x, $y, $cW, $cH, 'D'); }
    $pdf->SetLineWidth(0.2);

    // ── Bandes tricolores (vert/rouge/jaune) ──────────────────────
    $hBande = $cH * 0.52;
    $wVert  = $cW * 0.16; $wJaune = $cW * 0.34; $wRouge = $cW - $wVert - $wJaune;
    $pdf->SetFillColor(0, 158, 73);
    $pdf->Rect($x, $y, $wVert, $hBande, 'F');
    $pdf->SetFillColor(206, 17, 38);
    $pdf->Rect($x + $wVert, $y, $wRouge, $hBande, 'F');
    $pdf->SetFillColor(252, 209, 22);
    $pdf->Rect($x + $wVert + $wRouge, $y, $wJaune, $hBande, 'F');

    // Nom de l'établissement en bandeau, superposé aux 3 bandes.
    $fsNom = max(3.6, min(5.5, $cW / 16));
    $pdf->SetTextColor(0);
    $pdf->SetFont('Arial', 'B', $fsNom);
    $pdf->SetXY($x + 1.5, $y + 0.8);
    $pdf->MultiCell($cW - 3, $fsNom * 0.62, pdf_u(mb_strtoupper($etab['nom_en'] ?: $etab['nom_fr'] ?: APP_NOM)), 0, 'C');
    $yApresNom = $pdf->GetY() + 0.5;

    // Logo (bande verte), étoile (bande rouge), armoiries (bande jaune).
    $hEmblemes = max(4, $hBande - ($yApresNom - $y) - 1);
    $cyEmb = $yApresNom + $hEmblemes / 2;
    $logo_path = !empty($etab['logo']) ? __DIR__ . '/../assets/uploads/' . $etab['logo'] : '';
    $logoD = min($wVert * 0.8, $hEmblemes * 0.85);
    if ($logo_path && is_file($logo_path)) {
        $pdf->Image($logo_path, $x + ($wVert - $logoD) / 2, $cyEmb - $logoD / 2, $logoD);
    }
    $starD = min($wRouge * 0.32, $hEmblemes * 0.6);
    $star_tmp = carte_etoile_tmp((int) round($starD * 6));
    $pdf->Image($star_tmp, $x + $wVert + ($wRouge - $starD) / 2, $cyEmb - $starD / 2, $starD, $starD, 'PNG');
    $armoW = min($wJaune * 0.5, $hEmblemes * 0.68);
    $armoH = $armoW * 1.1;
    $armo_tmp = carte_armoiries_tmp((int) round($armoW * 6), (int) round($armoH * 6));
    $pdf->Image($armo_tmp, $x + $wVert + $wRouge + ($wJaune - $armoW) / 2, $cyEmb - $armoH / 2, $armoW, $armoH, 'PNG');

    // ── Ruban de devise (chevron vert, texte bilingue) ─────────────
    $yRuban = $y + $hBande + 1.4;
    $hRuban = max(3, $cH * 0.09);
    pdf_ruban_chevron($pdf, $x + $cW * 0.08, $x + $cW * 0.92, $yRuban, $yRuban + $hRuban, $cW * 0.025, [0, 122, 61]);
    $pdf->SetTextColor(255, 255, 255);
    $fsDev = max(2.8, min(4, $cW / 24));
    $pdf->SetFont('Arial', 'B', $fsDev);
    $pdf->SetXY($x + $cW * 0.1, $yRuban + $hRuban * 0.08);
    $pdf->Cell($cW * 0.8, $hRuban * 0.5, pdf_u('TRAVAIL - EFFICACITE - EXCELLENCE'), 0, 1, 'C');
    $pdf->SetFont('Arial', 'I', max(2.4, $fsDev - 1));
    $pdf->SetX($x + $cW * 0.1);
    $pdf->Cell($cW * 0.8, $hRuban * 0.36, pdf_u('WORK - EFFICIENCY - EXCELLENCE'), 0, 0, 'C');
    $pdf->SetTextColor(0);

    // ── Coordonnées (B.P./Tél.) ─────────────────────────────────────
    $yCoord = $yRuban + $hRuban + 1.6;
    $fsCoord = max(3, min(4.2, $cW / 22));
    $pdf->SetFont('Arial', 'B', $fsCoord);
    $pdf->SetTextColor(0, 122, 61);
    $pdf->SetXY($x + 2, $yCoord);
    $pdf->Cell($cW - 4, 2.6, pdf_u('B.P./P.O.BOX : ' . ($etab['boite_postale'] ?: '—')), 0, 1, 'L');
    $pdf->SetTextColor(206, 17, 38);
    $pdf->SetX($x + 2);
    $pdf->Cell($cW - 4, 2.6, pdf_u('Tél./Phone : ' . ($etab['telephone'] ?: '—')), 0, 0, 'L');
    $pdf->SetTextColor(0);

    // ── Vague turquoise : lieu/date, signature, cachet, validité ───
    $hVague = max(14, $cH * 0.30);
    $yVague = $y + $cH - $hVague;
    $vague_tmp = carte_vague_tmp((int) round($cW * 4), (int) round($hVague * 4), [13, 148, 136]);
    $pdf->Image($vague_tmp, $x, $yVague, $cW, $hVague, 'PNG');

    $lieu = $etab['lieu'] ?: $etab['ville'];
    $fsSig = max(3, min(4.2, $cW / 22));
    $pdf->SetTextColor(0);
    $pdf->SetFont('Arial', '', $fsSig);
    $pdf->SetXY($x + $cW * 0.42, $yVague + 1);
    $pdf->Cell($cW * 0.56, 2.6, pdf_u('Fait à ' . $lieu . ', le ' . date('d/m/Y')), 0, 1, 'R');
    $pdf->SetFont('Arial', 'B', $fsSig);
    $pdf->SetX($x + $cW * 0.42);
    $pdf->Cell($cW * 0.56, 3, pdf_u(mb_strtoupper($etab['chef_etablissement'] ?: 'LE DIRECTEUR') . ' :'), 0, 0, 'R');
    $pdf->SetFont('Arial', 'I', max(2.4, $fsSig - 1));
    $pdf->SetXY($x + $cW * 0.42, $yVague + $hVague - 3.4);
    $pdf->Cell($cW * 0.56, 2.4, 'The Principal', 0, 0, 'R');

    $sig_w = min($cW * 0.22, $hVague * 0.55);
    carte_signature($pdf, 'carte_5_verso', $x, $y, $cW, $cH, $x + $cW - 2 - $sig_w, $yVague + 3.5, $sig_w, $hVague * 0.5);

    // Cachet décoratif (double cercle rouge, sans texte à cette échelle).
    $cachetR = min($cW, $hVague) * 0.11;
    $cachetCx = $x + $cW * 0.30; $cachetCy = $yVague + $hVague * 0.55;
    $pdf->SetDrawColor(206, 17, 38);
    carte_circle_outline($pdf, $cachetCx, $cachetCy, $cachetR);
    carte_circle_outline($pdf, $cachetCx, $cachetCy, $cachetR * 0.68);
    $pdf->SetDrawColor(0);

    // Validité (bas gauche) — année scolaire active, même donnée que le
    // recto (dessiner_carte_5() reçoit $val_annee en paramètre ; le verso
    // partagé n'en reçoit pas, on la relit ici pour rester autonome).
    $val_annee_verso = get_annee_active()['val_annee'] ?? '';
    $pdf->SetFont('Arial', 'B', max(2.6, $fsSig - 1));
    $pdf->SetTextColor(0, 122, 61);
    $pdf->SetXY($x + 1.5, $y + $cH - 5.2);
    $pdf->Cell($cW * 0.4, 2.2, pdf_u('Validité'), 0, 1, 'L');
    $pdf->SetFont('Arial', 'I', max(2.4, $fsSig - 1.4));
    $pdf->SetX($x + 1.5);
    $pdf->Cell($cW * 0.4, 2, pdf_u($val_annee_verso), 0, 0, 'L');
    $pdf->SetTextColor(0);
}
