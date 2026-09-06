<?php
/**
 * Dossier administratif complet d'UN enseignant.
 * GET : id=matricule_ens, dl=1 (téléchargement)
 * Le rendu est délégué à _dossier_lib.php (partagé avec l'export en lot).
 */
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_connexion();

$role = role_connecte();
if (!in_array($role, ['DIRECTEUR', 'FONDATEUR', 'SECRETAIRE'], true)) die('Accès refusé.');

$mat = $_GET['id'] ?? '';
$dl  = ($_GET['dl'] ?? '0') === '1';

$e = db_one("SELECT * FROM enseignant WHERE matricule_ens=?", [$mat]);
if (!$e) die('Enseignant introuvable.');

$etab      = get_etablissement();
$annee_act = get_annee_active();
$val_annee = $annee_act['libelle'] ?? '';

require_once __DIR__ . '/../../pdf/fpdf.php';
require_once __DIR__ . '/../../pdf/header_pdf.php'; // pour pdf_filigrane()
require_once __DIR__ . '/_dossier_lib.php';

// Enveloppé dans un try/catch — voir fonctions.php::pdf_erreur_generation()
try {
$pdf = new FPDF_Dossier('P','mm','A4');
$pdf->etab = $etab;
$pdf->AliasNbPages();
$pdf->SetMargins(DOSSIER_ML, 10, DOSSIER_ML);
$pdf->SetAutoPageBreak(true, 16);

dossier_render_one($pdf, $e, $etab, $val_annee);

$pdf->Output($dl ? 'D' : 'I', 'dossier_'.preg_replace('/\W/', '_', (string)$mat).'.pdf');
} catch (Throwable $e) {
    pdf_erreur_generation($e);
}
