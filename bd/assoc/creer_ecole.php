<?php
// =====================================================================
//  bd/assoc/creer_ecole.php
//  Cree une nouvelle ecole : base MySQL + schema de reference + ligne
//  dans l'annuaire + version de schema calee sur la derniere migration.
//
//  Fine enveloppe CLI autour de creer_etablissement() (connexion_assoc.php),
//  qui porte toute la logique et est aussi appelee par l'interface
//  association (association/etablissement_nouveau.php).
//
//  Usage :
//    php bd/assoc/creer_ecole.php <code> "<nom>" [sous_domaine] [sigle] [ville]
//  Exemple :
//    php bd/assoc/creer_ecole.php EC2 "Ecole Al Nour" ecole2 ALN Ngaoundere
//
//  La base s'appelle promeducam_<slug du nom de l'etablissement>
//  (ex. "GSBI Minhadjoul Mouslim" -> promeducam_minhadjoul_mouslim).
//  Refuse d'ecraser une base existante ou un code deja pris.
// =====================================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion_assoc.php';

if (PHP_SAPI !== 'cli') { header('Content-Type: text/plain; charset=utf-8'); }
$a = $argv ?? [];

$in = [
    'code'         => $a[1] ?? ($_GET['code'] ?? ''),
    'nom'          => $a[2] ?? ($_GET['nom'] ?? ''),
    'sous_domaine' => $a[3] ?? ($_GET['sous_domaine'] ?? ''),
    'sigle'        => $a[4] ?? ($_GET['sigle'] ?? ''),
    'ville'        => $a[5] ?? ($_GET['ville'] ?? ''),
    'nom_en'       => $a[6] ?? ($_GET['nom_en'] ?? ''),
];

if (trim($in['code']) === '' || trim($in['nom']) === '') {
    die("Usage : php bd/assoc/creer_ecole.php <code A-Z0-9> \"<nom>\" [sous_domaine] [sigle] [ville] [nom_en]\n");
}

echo "=== Creation ecole " . strtoupper(trim($in['code'])) . " ===\n";
$r = creer_etablissement($in);
echo ($r['ok'] ? 'OK  ' : 'ERREUR : ') . $r['message'] . "\n";
exit($r['ok'] ? 0 : 1);
