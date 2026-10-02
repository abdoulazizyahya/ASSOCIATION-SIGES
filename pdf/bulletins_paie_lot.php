<?php
// pdf/bulletins_paie_lot.php — plusieurs bulletins de paie dans UN seul PDF
// (un bulletin par page), pour les bulletins cochés sur pages/paie/
// periode.php (02/10/2026). GET : ids=1,2,3 (ids de bulletin_paie),
// dl=1 pour télécharger au lieu d'afficher. Même rendu que le bulletin
// seul (pdf/bulletin_paie_lib.php).
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
require_once __DIR__ . '/../paie_fonctions.php';
exiger_role(['DIRECTEUR', 'FONDATEUR', 'COMPTABLE']);   // mêmes rôles que pages/paie/periode.php

require_once __DIR__ . '/fpdf.php';
require_once __DIR__ . '/header_pdf.php';
require_once __DIR__ . '/bulletin_paie_lib.php';

$dl  = ($_GET['dl'] ?? '0') === '1';
$ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) ($_GET['ids'] ?? ''))))));
if (!$ids) die('Aucun bulletin sélectionné.');
if (count($ids) > 500) die('Trop de bulletins demandés en une fois (500 au maximum).');

$bulletins = [];
foreach ($ids as $id) {
    $b = bulletin_paie_charger($id);
    if ($b) $bulletins[$id] = $b;
}
if (!$bulletins) die('Bulletins introuvables.');

$etab = etab_pour_pdf(get_etablissement());

try {
    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetAutoPageBreak(true, 15);
    foreach ($bulletins as $id => $b) {
        bulletin_paie_page($pdf, $b, $id, $etab);
    }
    $premier = reset($bulletins);
    $nom = 'bulletins_paie_' . preg_replace('/\W+/u', '_', (string) ($premier['periode_libelle'] ?? 'lot')) . '_' . count($bulletins);
    $pdf->Output($dl ? 'D' : 'I', $nom . '.pdf');
} catch (Throwable $e) {
    pdf_erreur_generation($e);
}
