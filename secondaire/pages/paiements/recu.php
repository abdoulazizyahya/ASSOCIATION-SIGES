<?php
// secondaire/pages/paiements/recu.php — Reçu de paiement des frais, officiel et
// numéroté (3 exemplaires empilés), regroupant TOUS les frais partageant un
// même numero_recu (une soumission du formulaire "Enregistrer un versement"
// = un seul numéro, voir secondaire/pages/paiements/save.php). Le contenu de chaque
// copie est dessiné par pdf/recu_paiement_render.php, réutilisé aussi par
// recu_fiche.php.
// GET : numero_recu=XXXX/AA&eleve=ID&annee=ID (interface normale) OU
//       paiement=ID (compatibilité ascendante des anciens liens/QR générés
//       avant le passage au regroupement — résolu vers le groupe complet).
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'CENSEUR', 'INTENDANT']);
require_once __DIR__ . '/../../pdf/fpdf.php';
require_once __DIR__ . '/../../pdf/header_pdf.php';
require_once __DIR__ . '/../../pdf/recu_paiement_render.php';

$dl = ($_GET['dl'] ?? '0') === '1';

$numero_recu = trim((string)($_GET['numero_recu'] ?? ''));
$id_eleve    = (int)($_GET['eleve'] ?? 0);
$id_annee    = (int)($_GET['annee'] ?? 0);

if (!$numero_recu && !empty($_GET['paiement'])) {
    $ref = db_one("SELECT numero_recu, id_eleve, id_annee FROM paiement_frais WHERE id = ?", [(int)$_GET['paiement']]);
    if ($ref) {
        $numero_recu = $ref['numero_recu'];
        $id_eleve    = (int)$ref['id_eleve'];
        $id_annee    = (int)$ref['id_annee'];
    }
}

$versements = ($numero_recu && $id_eleve && $id_annee) ? db_all(
    "SELECT p.*, o.libelle AS obligation_libelle
     FROM paiement_frais p
     JOIN obligation_frais o ON o.id = p.id_obligation
     WHERE p.numero_recu = ? AND p.id_eleve = ? AND p.id_annee = ?
     ORDER BY p.id", [$numero_recu, $id_eleve, $id_annee]
) : [];

if (!$versements) {
    die('<div style="font-family:sans-serif;padding:2rem;color:#b91c1c">Reçu introuvable.</div>');
}

$eleve  = db_one("SELECT * FROM eleve WHERE id = ?", [$id_eleve]);
$classe = db_one("SELECT * FROM classe WHERE id = ?", [(int)$versements[0]['id_classe']]);
$annee  = db_one("SELECT libelle FROM annee_scolaire WHERE id = ?", [$id_annee]);
$etab   = get_etablissement();

$id_paiement_repere = (int) min(array_column($versements, 'id'));

require_once __DIR__ . '/../../pdf/verif_paiement_lib.php';
$qr_url = paiement_verif_url($id_paiement_repere, $numero_recu);
require_once __DIR__ . '/../../pdf/qrcode.php';
$qr_tmp = tempnam(sys_get_temp_dir(), 'abzqr_') . '.png';
$qr_gen = new QRCode($qr_url, ['s' => 'qr-m']);
$qr_img = $qr_gen->render_image();
imagepng($qr_img, $qr_tmp);
imagedestroy($qr_img);

$avec_sig_intendant  = ($_GET['sig_intendant'] ?? '0') === '1';
$avec_sig_proviseur  = ($_GET['sig_chef_etablissement'] ?? '0') === '1';

$reglage = get_reglage_paiement($id_annee);
$couleurs_fond = [hex_vers_rgb($reglage['couleur_fond_1']), hex_vers_rgb($reglage['couleur_fond_2']), hex_vers_rgb($reglage['couleur_fond_3'])];

// Mode "cadre seul" : la fenêtre de réglage de position (layout/footer.php)
// a besoin d'un aperçu dont le canvas correspond EXACTEMENT au cadre d'une
// copie (x_pct/y_pct sont mémorisés par rapport à CE cadre, pas à la page
// entière) — sans cela, glisser la signature sur la page complète (3 copies
// visibles) enregistre une position qui ne correspond à rien une fois
// réappliquée à une seule copie. Le paramètre est positionné par
// ouvrirPositionSignature() (déjà présent dans layout/footer.php).
$apercu_cadre_seul = ($_GET['apercu_frame'] ?? '') === 'recu_paiement';

// Dimensions de référence identiques que le mode normal (A4, marge 8mm).
$bandeau_h = (297 - 16) / 3;
$w0_copie  = 210 - 16;

if ($apercu_cadre_seul) {
    // Orientation 'L' (paysage) et non 'P' : le cadre est plus large que
    // haut, et _getpagesize() de pdf/fpdf.php trie toujours le tableau de
    // taille en (plus petit, plus grand) puis l'applique selon l'orientation
    // — avec 'P' ici, largeur/hauteur se retrouvaient interverties.
    $pdf = new FPDF('L', 'mm', [$w0_copie, $bandeau_h]);
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage();
    recu_paiement_dessiner_copie(
        $pdf, $etab, $eleve, $classe, $versements, $numero_recu, $annee['libelle'] ?? '',
        0, 0, $w0_copie, $bandeau_h,
        'recu_paiement', false, null, false, false, 0.66, $couleurs_fond
    );
    $pdf->Output('I', 'apercu.pdf');
    exit;
}

$pdf = new FPDF('P', 'mm', 'A4');
$pdf->SetMargins(8, 8, 8);
$pdf->SetAutoPageBreak(false);
$pdf->AddPage();
$pw = $pdf->GetPageWidth();
$ph = $pdf->GetPageHeight();

pdf_filigrane($pdf, $etab, $pw, $ph, 120);

for ($i = 0; $i < 3; $i++) {
    recu_paiement_dessiner_copie(
        $pdf, $etab, $eleve, $classe, $versements, $numero_recu, $annee['libelle'] ?? '',
        8, 8 + $i * $bandeau_h, $pw - 16, $bandeau_h,
        'recu_paiement', true, $qr_tmp,
        $avec_sig_intendant, $avec_sig_proviseur, 0.66, $couleurs_fond
    );
}

unlink($qr_tmp);
$pdf->Output($dl ? 'D' : 'I', 'recu_' . str_replace('/', '-', $numero_recu) . '.pdf');
