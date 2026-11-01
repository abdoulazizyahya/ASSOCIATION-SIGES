<?php
// noyau/partage/eleves/photos_export.php — Télécharge en ZIP les photos des
// élèves d'une classe (?classe=<id>) ou de toutes les classes (?classe=toutes),
// primaire comme secondaire (pages secondaire/commun/pages/eleves/ et
// primaire/pages/eleves/ du même nom : simple inclusion).
// Chaque photo est nommée par le NIU de l'élève, sinon par son matricule
// (photo_nom_fichier()) : le NIU ne change jamais d'une année ni d'une école à
// l'autre, ces fichiers se réimportent donc tels quels l'année suivante
// (onglet « Photos par classe » → Importer des photos, association par NIU).
// « toutes » : un dossier par classe. liste.csv : correspondance fichier ↔ élève.
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/_photos_lib.php';
exiger_role(photos_roles());

$toutes  = ($_GET['classe'] ?? '') === 'toutes';
$classes = $toutes ? photos_classes() : array_filter([photos_classe((int) ($_GET['classe'] ?? 0))]);
if (!$classes) { http_response_code(404); exit('Classe introuvable.'); }
@set_time_limit(300);

$tmp = tempnam(sys_get_temp_dir(), 'phz');
$zip = new ZipArchive();
if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) { http_response_code(500); exit('Archive impossible à créer.'); }

$csv = fopen('php://temp', 'w+');
fwrite($csv, "\xEF\xBB\xBF");
fputcsv($csv, ['Fichier', 'NIU', 'Matricule', 'Nom et prénom(s)', 'Classe'], ';');
$nb = 0;
$nom_dossier = fn(string $s) => trim(preg_replace('/[\\\/:*?"<>|]+/u', '-', $s), ' .-') ?: 'classe';
foreach ($classes as $c) {
    $dossier = $toutes ? $nom_dossier((string) $c['nom']) . '/' : '';
    $pris = [];
    foreach (photos_eleves_classe((int) $c['id']) as $e) {
        if (!$e['photo']) continue;
        $bin = photo_eleve_binaire($e['id']);
        if ($bin === null) continue;
        $t   = @getimagesizefromstring($bin)[2] ?? IMAGETYPE_JPEG;
        $ext = $t === IMAGETYPE_PNG ? 'png' : ($t === IMAGETYPE_WEBP ? 'webp' : 'jpg');
        $nom = $e['fichier'];
        if (isset($pris[strtoupper($nom)])) $nom .= '_' . $e['id'];   // NIU/matricule en double
        $pris[strtoupper($nom)] = true;
        $zip->addFromString($dossier . $nom . '.' . $ext, $bin);
        fputcsv($csv, [$dossier . $nom . '.' . $ext, $e['niu'], $e['mat'], $e['nom'], $c['nom']], ';');
        $nb++;
    }
}
rewind($csv);
$zip->addFromString('liste.csv', stream_get_contents($csv));
fclose($csv);
$zip->close();

if (!$nb) { @unlink($tmp); http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); exit('Aucune photo à exporter.'); }
$nom_zip = 'photos_' . ($toutes ? 'toutes_classes' : preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) reset($classes)['nom'])) . '.zip';
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $nom_zip . '"');
header('Content-Length: ' . filesize($tmp));
readfile($tmp);
@unlink($tmp);
