<?php
// pages/finances/recu.php — Reçu de paiement PAR ÉLÈVE (un seul numéro par
// élève, demande explicite du 15/08/2026 : « le reçu doit être par élève
// avec un seul numéro par élève, il faut respecter le modèle... exactement
// »). Remplace l'ancien reçu (qui pouvait aussi être réimprimé pour un seul
// versement via ?pay=, chaque versement ayant son propre numéro).
//
// Reconstruction PIXEL-EXACTE du modèle fourni (recu.pdf) : décompression
// du flux de contenu FPDF de ce PDF (Producer FPDF 1.53, même auteur que ce
// projet), conversion pt→mm de CHAQUE position/couleur/police, texte placé
// via Text() (coordonnées baseline directes, comme le Td/Tj du PDF source —
// pas de Cell() dont le padding interne aurait fallu redeviner). Page A4,
// 3 copies identiques empilées (COUPON PARENT / DIRECTION / ARCHIVE),
// même bloc de 93mm dupliqué 3× (y = 4, 102, 200mm), séparées par une ligne
// de tirets (police 18pt, comme l'original — pas un SetDash, FPDF n'en a
// pas). Couleur unique du document : RGB(106,181,255) — ruban de titre,
// en-tête du tableau, ligne TOTAL (voir FPDF::ChevronRibbon(), pdf/fpdf.php,
// ajoutée pour ce document).
//
// Table des paiements : contrairement à l'ancien reçu (qui distinguait
// chaque frais via nom_obligation), le modèle de référence ne montre que
// Montant/Date par ligne sous un seul intitulé "FRAIS" fusionné — reçu
// récapitulatif de TOUS les versements de l'élève pour l'année, pas un
// détail par type de frais. Nombre de lignes variable (contrairement à
// l'exemple à 3 lignes) : la hauteur de chaque ligne s'adapte pour que le
// tableau garde toujours la même zone verticale réservée (≤3 lignes =
// hauteur identique à l'original, plus de lignes = légèrement compressé).
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/../../pdf/verif_recu_lib.php';

// Accès public via le QR code du reçu (jeton "vh", voir verif_recu.php) —
// même mécanisme que les bulletins (pdf/bulletin_annuel.php).
$acces_public = false;
if (($_GET['vh'] ?? '') !== '' && (int) ($_GET['eleve'] ?? 0) > 0) {
    $val_annee_pub = get_annee_active()['val_annee'] ?? '';
    $acces_public = recu_verif_valider((int) $_GET['eleve'], $val_annee_pub, (string) $_GET['vh']) !== null;
}
if (!$acces_public) exiger_role(['DIRECTEUR', 'SECRETAIRE', 'COMPTABLE']);

require_once __DIR__ . '/../../pdf/fpdf.php';
require_once __DIR__ . '/../../pdf/header_pdf.php';
require_once __DIR__ . '/../../pdf/recu_lib.php';

$id_eleve  = (int) ($_GET['eleve'] ?? 0);
$id_classe = (int) ($_GET['classe'] ?? 0);
$dl        = ($_GET['dl'] ?? '0') === '1';
$eleve     = db_one("SELECT * FROM eleve WHERE id_eleve=?", [$id_eleve]);
$classe    = db_one("SELECT * FROM classe WHERE IDClasses=?", [$id_classe]);
if (!$eleve || !$classe) die('Élève ou classe introuvable.');

$annee     = get_annee_active();
$val_annee = $annee['val_annee'] ?? '';

// ── Génération ─────────────────────────────────────────────────────
// Le rendu (3 copies pixel-exactes) est délégué à finances_dessiner_recu_eleve()
// (pdf/recu_lib.php, partagée avec l'impression en lot — pages/finances/recus_lot.php).
// Enveloppée dans un try/catch : accessible publiquement (scan du QR, voir
// $acces_public plus haut) — un incident technique (ex. fichier image mis
// en cache corrompu/incomplet, déjà rencontré : FPDF "Unexpected end of
// stream") ne doit JAMAIS renvoyer un fatal error brut à un visiteur
// anonyme. Message d'accueil convivial à la place, avec un rappel réseau
// utile (cause la plus fréquente d'échec de scan — voir hote_verif_reseau(),
// fonctions.php) ; le détail technique n'est montré qu'au personnel connecté.
try {
    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetAutoPageBreak(false);
    if (!finances_dessiner_recu_eleve($pdf, $id_eleve, $id_classe, $val_annee)) {
        die('Aucun versement enregistré pour cet élève.');
    }
    $numero_recu = finances_numero_recu_eleve($id_eleve, $val_annee);
    $nom_fichier = 'recu_' . str_replace('/', '-', $numero_recu) . '_' . $eleve['Mat_elv'];
    $pdf->Output($dl ? 'D' : 'I', $nom_fichier . '.pdf');
} catch (Throwable $e) {
    pdf_erreur_generation($e);
}
