<?php
// pages/finances/recus_lot.php — Impression EN LOT des reçus de paiement,
// demande explicite du 13/09/2026 : depuis l'onglet « Imprimer les reçus »
// (pages/finances/versement.php), un seul PDF concaténant le reçu (voir
// pdf/recu_lib.php, même rendu que pages/finances/recu.php) de CHAQUE élève
// ayant effectué au moins un versement sur une date/période donnée — pour
// une classe précise, un seul élève, ou toutes les classes.
//
// GET :
//   debut, fin = bornes de la période (Y-m-d, incluses) — une date unique
//                si debut === fin.
//   classe     = IDClasses, 0 (ou absent) pour toutes les classes.
//   eleve      = id_eleve, 0 (ou absent) pour tous les élèves concernés.
//   dl         = 1 pour forcer le téléchargement.
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_role(['DIRECTEUR', 'SECRETAIRE', 'COMPTABLE']);

require_once __DIR__ . '/../../pdf/fpdf.php';
require_once __DIR__ . '/../../pdf/header_pdf.php';
require_once __DIR__ . '/../../pdf/verif_recu_lib.php';
require_once __DIR__ . '/../../pdf/recu_lib.php';

$annee     = get_annee_active();
$val_annee = $annee['val_annee'] ?? '';

$debut     = $_GET['debut'] ?? date('Y-m-d');
$fin       = $_GET['fin'] ?? date('Y-m-d');
if ($fin < $debut) { [$debut, $fin] = [$fin, $debut]; } // tolérance si dates inversées
$id_classe = (int) ($_GET['classe'] ?? 0);
$id_eleve  = (int) ($_GET['eleve'] ?? 0);
$dl        = ($_GET['dl'] ?? '0') === '1';

$eleves = finances_eleves_payes_periode($val_annee, $debut, $fin, $id_classe, $id_eleve);
if (!$eleves) die("Aucun élève n'a effectué de paiement sur cette période.");

// Même try/catch que recu.php — voir pdf_erreur_generation() (fonctions.php).
try {
    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetAutoPageBreak(false);
    $imprimes = 0;
    foreach ($eleves as $e) {
        if (finances_dessiner_recu_eleve($pdf, (int) $e['id_eleve'], (int) $e['IDClasses'], $val_annee)) {
            $imprimes++;
        }
    }
    if (!$imprimes) die("Aucun reçu à imprimer pour ces critères.");
    $suffixe = str_replace('/', '-', $debut) . ($fin !== $debut ? '_a_' . str_replace('/', '-', $fin) : '');
    $pdf->Output($dl ? 'D' : 'I', "recus_lot_{$suffixe}_{$imprimes}.pdf");
} catch (Throwable $e) {
    pdf_erreur_generation($e);
}
