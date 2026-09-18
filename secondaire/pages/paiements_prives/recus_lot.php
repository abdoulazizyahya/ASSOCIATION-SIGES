<?php
// secondaire/pages/paiements_prives/recus_lot.php — Impression EN LOT des
// reçus de paiement PRIVÉ (voir secondaire/pdf/prive_recu_lib.php). Porté
// de pages/finances/recus_lot.php (primaire).
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'SECRETAIRE', 'INTENDANT']);

require_once __DIR__ . '/../../pdf/fpdf.php';
require_once __DIR__ . '/../../pdf/header_pdf.php';
require_once __DIR__ . '/../../pdf/prive_recu_lib.php';

$annee     = get_annee_active();
$id_annee  = (int) ($annee['id'] ?? 0);
$val_annee = $annee['val_annee'] ?? ($annee['libelle'] ?? '');

$debut     = $_GET['debut'] ?? date('Y-m-d');
$fin       = $_GET['fin'] ?? date('Y-m-d');
if ($fin < $debut) { [$debut, $fin] = [$fin, $debut]; }
$id_classe = (int) ($_GET['classe'] ?? 0);
$id_eleve  = (int) ($_GET['eleve'] ?? 0);
$dl        = ($_GET['dl'] ?? '0') === '1';

$eleves = prive_finances_eleves_payes_periode($id_annee, $debut, $fin, $id_classe, $id_eleve);
if (!$eleves) die("Aucun élève n'a effectué de paiement sur cette période.");

try {
    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetAutoPageBreak(false);
    $imprimes = 0;
    foreach ($eleves as $e) {
        if (prive_dessiner_recu_eleve($pdf, (int) $e['id_eleve'], (int) $e['id_classe'], $id_annee, $val_annee)) {
            $imprimes++;
        }
    }
    if (!$imprimes) die("Aucun reçu à imprimer pour ces critères.");
    $suffixe = str_replace('/', '-', $debut) . ($fin !== $debut ? '_a_' . str_replace('/', '-', $fin) : '');
    $pdf->Output($dl ? 'D' : 'I', "recus_prive_lot_{$suffixe}_{$imprimes}.pdf");
} catch (Throwable $e) {
    pdf_erreur_generation($e);
}
