<?php
// deploy/outils/construire_zip.php — zip de mise à jour (25/10/2026), installable
// depuis Association → Mise à jour (bd/lib/mise_a_jour.php) ou à la main.
//
//   php deploy/outils/construire_zip.php <commit_de_depart> <nom.zip> [--avec=ancien.zip ...]
//
// Contenu : fichiers modifiés ou ajoutés entre <commit_de_depart> et HEAD (les
// fichiers supprimés sont listés à l'écran : à retirer à la main), plus les
// fichiers d'anciens zips donnés par --avec (zip cumulatif). Le manifeste
// siges_maj.json porte l'empreinte SHA-256 de chaque fichier : la page de
// mise à jour refuse tout fichier altéré ou ajouté après coup.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
chdir(__DIR__ . '/../..');
require_once 'bd/lib/mise_a_jour.php';

[$depart, $nom] = [$argv[1] ?? '', $argv[2] ?? ''];
if ($depart === '' || $nom === '') exit("Usage : php deploy/outils/construire_zip.php <commit_de_depart> <nom.zip> [--avec=ancien.zip]\n");
exec('git diff --name-only --diff-filter=ACMR ' . escapeshellarg($depart) . ' HEAD', $liste, $rc);
if ($rc !== 0) exit("Commit de départ inconnu : $depart\n");
exec('git diff --name-only --diff-filter=D ' . escapeshellarg($depart) . ' HEAD', $supprimes);
foreach (array_slice($argv, 3) as $a) {
    if (!str_starts_with($a, '--avec=')) continue;
    $z = new ZipArchive();
    if ($z->open(substr($a, 7)) !== true) exit("Zip illisible : " . substr($a, 7) . "\n");
    for ($i = 0; $i < $z->numFiles; $i++) $liste[] = $z->getNameIndex($i);
    $z->close();
}
$fichiers = [];
foreach (array_unique($liste) as $p) {
    if ($p === MAJ_MANIFESTE || in_array($p, $supprimes, true)) continue;
    if (maj_chemin_refuse($p) !== null) { echo "  ignoré : $p (" . maj_chemin_refuse($p) . ")\n"; continue; }
    if (!is_file($p)) { echo "  absent : $p\n"; continue; }
    $fichiers[$p] = hash_file('sha256', $p);
}
ksort($fichiers);
$dest = str_contains($nom, '/') ? $nom : 'deploy/archives/' . $nom;
$z = new ZipArchive();
$z->open($dest, ZipArchive::CREATE | ZipArchive::OVERWRITE);
foreach ($fichiers as $p => $_) $z->addFile($p, $p);
exec('git rev-parse --short HEAD', $head);
$z->addFromString(MAJ_MANIFESTE, json_encode(['cree_le' => date('c'), 'depuis' => $depart, 'commit' => $head[0] ?? '', 'fichiers' => $fichiers],
                                             JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
$z->close();
echo "$dest : " . count($fichiers) . " fichiers, " . round(filesize($dest) / 1024) . " Ko\n";
if ($supprimes) echo "Fichiers supprimés depuis $depart (à retirer à la main sur le serveur) :\n  " . implode("\n  ", $supprimes) . "\n";
