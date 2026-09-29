<?php
// pages/eleves/fiches_incompletes_export.php — export CSV (ouvrable dans
// Excel) de l'onglet « Fiches incomplètes », mêmes filtres que l'écran
// (_eleves_incompletes.php). Primaire ET secondaire
// (secondaire/pages/eleves/fiches_incompletes_export.php inclut ce fichier).
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/_fiches_lib.php';
exiger_role(photos_roles());

$fp     = fiches_parametres();
$champs = fiches_champs();
$lignes = fiches_filtrer(fiches_eleves($fp['niveau'], $fp['classe']), $fp['champs'], $fp['mode']);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="fiches_incompletes_' . date('Ymd') . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM : accents corrects dans Excel
fputcsv($out, array_merge(['N°', 'Élève', 'Matricule', 'Niveau', 'Classe'], array_values($champs), ['Informations manquantes']), ';');
$no = 1;
foreach ($lignes as $e) {
    $cols = [];
    foreach (array_keys($champs) as $k) $cols[] = in_array($k, $e['manque'], true) ? 'MANQUANT' : 'OK';
    $manq = array_map(fn($k) => $champs[$k], array_values(array_intersect(array_keys($champs), $e['manque'])));
    fputcsv($out, array_merge([$no++, $e['nom'], $e['mat'], $e['niveau'], $e['classe']], $cols, [implode(', ', $manq)]), ';');
}
fclose($out);
