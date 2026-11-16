<?php
// deploy/outils/construire_zip.php — zip de mise à jour (25/10/2026), installable
// depuis Association → Mise à jour (bd/lib/mise_a_jour.php) ou à la main.
//
//   php deploy/outils/construire_zip.php <commit_de_depart> <nom.zip> [--avec=ancien.zip ...]
//   php deploy/outils/construire_zip.php --fichiers <nom.zip> <chemin1> [<chemin2> ...]
//
// --fichiers : zip de quelques fichiers seulement, pris sur le disque (commités ou non).
// Contenu : fichiers modifiés ou ajoutés entre <commit_de_depart> et HEAD (les
// fichiers supprimés sont listés à l'écran : à retirer à la main), plus les
// fichiers d'anciens zips donnés par --avec (zip cumulatif). Le manifeste
// siges_maj.json porte l'empreinte SHA-256 de chaque fichier : la page de
// mise à jour refuse tout fichier altéré ou ajouté après coup.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
chdir(__DIR__ . '/../..');
require_once 'bd/lib/compat_php74.php';   // str_contains()… sous PHP 7.4
require_once 'bd/lib/mise_a_jour.php';

[$depart, $nom] = [$argv[1] ?? '', $argv[2] ?? ''];
if ($depart === '' || $nom === '') exit("Usage : php deploy/outils/construire_zip.php <commit_de_depart> <nom.zip> [--avec=ancien.zip]\n"
    . "        php deploy/outils/construire_zip.php --fichiers <nom.zip> <chemin1> [<chemin2> ...]\n");
$liste = []; $supprimes = [];
if ($depart === '--fichiers') {
    // Petite mise à jour : seulement les fichiers nommés, tels qu'ils sont sur le disque (16/11/2026).
    // Chemins absolus acceptés (fichiers glissés sur deploy/MAJ.bat) ; un dossier = tous ses fichiers.
    $base = str_replace('\\', '/', getcwd()) . '/';
    foreach (array_slice($argv, 3) as $a) {
        if (str_starts_with($a, '--')) continue;
        $a = rtrim(str_replace('\\', '/', trim($a, '"')), '/');
        if (stripos($a, $base) === 0) $a = substr($a, strlen($base));
        elseif (preg_match('~^([A-Za-z]:)?/~', $a)) { echo "  hors de SIGES : $a\n"; continue; }
        if (is_dir($a)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($a, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) if ($f->isFile()) $liste[] = str_replace('\\', '/', $f->getPathname());
        } else $liste[] = $a;
    }
    if (!$liste) exit("Indiquer au moins un fichier après le nom du zip.\n");
    if ($nom === 'auto') $nom = 'maj_' . date('Y-m-d_H\hi') . '.zip';
    $depart = 'fichiers choisis';
} else {
    exec('git diff --name-only --diff-filter=ACMR ' . escapeshellarg($depart) . ' HEAD', $liste, $rc);
    if ($rc !== 0) exit("Commit de départ inconnu : $depart\n");
    exec('git diff --name-only --diff-filter=D ' . escapeshellarg($depart) . ' HEAD', $supprimes);
}
// --travail : ajoute aussi les modifications NON COMMITÉES (fichiers modifiés
// et nouveaux fichiers non ignorés) — le zip reflète alors le disque, pas HEAD.
$travail = in_array('--travail', $argv, true);
if ($travail) {
    exec('git diff --name-only --diff-filter=ACMR HEAD', $liste);
    exec('git ls-files --others --exclude-standard', $liste);
    exec('git diff --name-only --diff-filter=D HEAD', $supprimes);
}
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
// Fichiers supprimés du code : la page de mise à jour les retire du serveur
// (après sauvegarde) — seulement ceux d'un dossier autorisé.
$a_retirer = array_values(array_filter(array_unique($supprimes), fn($p) => maj_chemin_refuse($p) === null && !isset($fichiers[$p])));
$manifeste = json_encode(['cree_le' => date('c'), 'depuis' => $depart, 'commit' => ($head[0] ?? '') . ($travail ? '+travail' : ''),
                          'fichiers' => $fichiers, 'supprimes' => $a_retirer],
                         JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
$z->addFromString(MAJ_MANIFESTE, $manifeste);
// Signature : sans elle, la page Association → Mise à jour refuse le zip.
$cle = 'deploy/cles/maj_privee.pem';
if (is_file($cle)) $z->addFromString(MAJ_SIGNATURE, maj_signer($manifeste, (string) file_get_contents($cle)));
$z->close();
echo "$dest : " . count($fichiers) . " fichiers, " . round(filesize($dest) / 1024) . " Ko"
   . (is_file($cle) ? ", signé\n" : "\n  NON SIGNÉ ($cle absente : php deploy/outils/generer_cle_maj.php) — installable seulement par FTP\n");
if ($a_retirer) echo "Fichiers supprimés depuis $depart (retirés par la page de mise à jour) :\n  " . implode("\n  ", $a_retirer) . "\n";
