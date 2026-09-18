<?php
// secondaire/pages/paiements_prives/recu.php — Reçu de paiement PRIVÉ par
// élève (un seul numéro par élève). Porté de pages/finances/recu.php
// (primaire) — SIMPLIFIÉ : pas d'accès public par QR (voir
// secondaire/pdf/prive_recu_lib.php), toujours réservé au personnel.
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'SECRETAIRE', 'INTENDANT']);

require_once __DIR__ . '/../../pdf/fpdf.php';
require_once __DIR__ . '/../../pdf/header_pdf.php';
require_once __DIR__ . '/../../pdf/prive_recu_lib.php';

$id_eleve  = (int) ($_GET['eleve'] ?? 0);
$id_classe = (int) ($_GET['classe'] ?? 0);
$dl        = ($_GET['dl'] ?? '0') === '1';
$eleve     = db_one("SELECT * FROM eleve WHERE id=?", [$id_eleve]);
$classe    = db_one("SELECT * FROM classe WHERE id=?", [$id_classe]);
if (!$eleve || !$classe) die('Élève ou classe introuvable.');

$annee     = get_annee_active();
$id_annee  = (int) ($annee['id'] ?? 0);
$val_annee = $annee['val_annee'] ?? ($annee['libelle'] ?? '');

try {
    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetAutoPageBreak(false);
    if (!prive_dessiner_recu_eleve($pdf, $id_eleve, $id_classe, $id_annee, $val_annee)) {
        die('Aucun versement enregistré pour cet élève.');
    }
    $numero_recu = finances_numero_recu_eleve($id_eleve, $val_annee);
    $nom_fichier = 'recu_prive_' . str_replace('/', '-', $numero_recu) . '_' . $eleve['matricule'];
    $pdf->Output($dl ? 'D' : 'I', $nom_fichier . '.pdf');
} catch (Throwable $e) {
    pdf_erreur_generation($e);
}
