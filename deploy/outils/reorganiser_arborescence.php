<?php
// =====================================================================
//  deploy/outils/reorganiser_arborescence.php  (outil de développement, CLI)
//  Réorganisation de l'arborescence de SIGES par type d'école (12/10/2026) :
//    noyau/        cœur commun (connexion, fonctions, contexte école,
//                  layout/, ajax/, compte/)
//    primaire/     pages/, pdf/, notes APC
//    secondaire/   commun/ (partagé), general/ (APC), technique/ (séquences)
//    docs/         documentation
//  Déplace les fichiers (git mv) puis RÉÉCRIT les chemins :
//    R1  __DIR__ . '/…'           recalculé depuis l'ancien et le nouvel emplacement ;
//    R2  URL internes « pages/… », « secondaire/pages/… », « ajax/… »… ;
//    R3  chemins relatifs « ../… » hors __DIR__ (liens entre pages voisines).
//  Usage : php deploy/outils/reorganiser_arborescence.php            (aperçu)
//          php deploy/outils/reorganiser_arborescence.php --go       (applique)
// =====================================================================
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$go = in_array('--go', $argv, true);
$racine = realpath(__DIR__ . '/../..');
chdir($racine);

// Table de correspondance (dossier = clé terminée par « / »). Le préfixe le
// plus long l'emporte (secondaire/pages/notes/ avant secondaire/pages/).
$CORR_DEFAUT = [
    'secondaire/pages/notes/'            => 'secondaire/general/pages/notes/',
    'secondaire/pages/bulletins/'        => 'secondaire/general/pages/bulletins/',
    'secondaire/pages/'                  => 'secondaire/commun/pages/',
    'secondaire/pdf/'                    => 'secondaire/commun/pdf/',
    'secondaire/dashboard_contenu.php'   => 'secondaire/commun/dashboard_contenu.php',
    'secondaire/profil_contenu.php'      => 'secondaire/commun/profil_contenu.php',
    'secondaire/verif_bulletin_contenu.php' => 'secondaire/commun/verif_bulletin_contenu.php',
    'secondaire_en_construction.php'     => 'secondaire/commun/en_construction.php',
    'pages/'                             => 'primaire/pages/',
    'pdf/'                               => 'primaire/pdf/',
    'notes_apc.php'                      => 'primaire/notes_apc.php',
    'notes_apc_arabe.php'                => 'primaire/notes_apc_arabe.php',
    'layout/'                            => 'noyau/layout/',
    'ajax/'                              => 'noyau/ajax/',
    'connexion.php'                      => 'noyau/connexion.php',
    'connexion_assoc.php'                => 'noyau/connexion_assoc.php',
    'ecole_contexte.php'                 => 'noyau/ecole_contexte.php',
    'fonctions.php'                      => 'noyau/fonctions.php',
    'paie_fonctions.php'                 => 'noyau/paie_fonctions.php',
    'accueil.php'                        => 'noyau/accueil.php',
    // compte/profil.php reste à la racine (parcours de compte, clé de privilège 'compte/profil.php').
    'Guide_utilisation_SIGES.docx'       => 'docs/Guide_utilisation_SIGES.docx',
    'SIGES_Presentation_Jaynitaare.pptx' => 'docs/SIGES_Presentation_Jaynitaare.pptx',
    'prompt_continuite_jaynitaare_v2.md' => 'docs/prompt_continuite_jaynitaare_v2.md',
    'avant_bareme_niveau_I.txt'          => 'docs/avant_bareme_niveau_I.txt',
    'save.bat'                           => 'deploy/save.bat',
];

// --table=fichier.php : autre table de correspondance (déplacements ultérieurs) ;
// le fichier retourne ['correspondance' => […], 'prefixes_url' => […], 'fichiers_racine' => […]].
$TABLE = null;
foreach ($argv as $a) if (strncmp($a, '--table=', 8) === 0) $TABLE = require substr($a, 8);
$GLOBALS['CORR'] = $TABLE['correspondance'] ?? $CORR_DEFAUT;

function mapper(string $p): ?string {
    $meilleur = null; $lg = -1;
    foreach ($GLOBALS['CORR'] as $a => $n) {
        $dossier = substr($a, -1) === '/';
        $ok = $dossier ? ($p === rtrim($a, '/') || strncmp($p, $a, strlen($a)) === 0) : $p === $a;
        if ($ok && strlen($a) > $lg) { $meilleur = [$a, $n]; $lg = strlen($a); }
    }
    if (!$meilleur) return null;
    [$a, $n] = $meilleur;
    if ($p === rtrim($a, '/')) return rtrim($n, '/');
    return $n . substr($p, strlen($a));
}
function normaliser(string $p): ?string {      // résout « .. » et « . » ; null si sort de la racine
    $out = [];
    foreach (explode('/', $p) as $s) {
        if ($s === '' || $s === '.') continue;
        if ($s === '..') { if (!$out) return null; array_pop($out); continue; }
        $out[] = $s;
    }
    return implode('/', $out);
}
function relatif(string $de, string $vers): string {   // chemin de dossier $de vers $vers
    $a = $de === '' ? [] : explode('/', $de); $b = $vers === '' ? [] : explode('/', $vers);
    $i = 0; while ($i < count($a) && $i < count($b) && $a[$i] === $b[$i]) $i++;
    return implode('/', array_merge(array_fill(0, count($a) - $i, '..'), array_slice($b, $i)));
}
function dossier(string $f): string { $d = dirname($f); return $d === '.' ? '' : $d; }

$suivis = array_filter(explode("\n", trim(shell_exec('git ls-files'))));
$existe = array_flip($suivis);
foreach ($suivis as $f) { $d = dossier($f); while ($d !== '') { $existe[$d] = true; $d = dossier($d); } }
$exclus = '~^(vendor/|assets/vendor/|pdf/tcpdf/|pdf/font/|secondaire/pdf/font/|ABZ COMPRESS/|bd/secondaire/outils/porter_technique.php)~';   // porter_technique : chemins ABZ_MBE voulus
$texte  = '~\.(php|js|html|css|htaccess|txt)$|(^|/)\.htaccess$~';

$nouveau = [];
foreach ($suivis as $f) $nouveau[$f] = mapper($f) ?? $f;

// R2 : préfixes d'URL internes (relatifs à la racine de l'application).
$prefixes_url = ['secondaire/pages/', 'secondaire/pdf/', 'secondaire/dashboard_contenu.php', 'secondaire/profil_contenu.php',
                 'secondaire/verif_bulletin_contenu.php', 'pages/', 'pdf/', 'layout/', 'ajax/', 'notes_apc.php', 'notes_apc_arabe.php'];
$fichiers_racine = ['accueil.php', 'secondaire_en_construction.php'];
if ($TABLE) { $prefixes_url = $TABLE['prefixes_url']; $fichiers_racine = $TABLE['fichiers_racine'] ?? []; }

$journal = []; $contenus = [];
foreach ($suivis as $f) {
    if (preg_match($exclus, $f) || !preg_match($texte, $f)) continue;
    $src = file_get_contents($f); $s = $src;
    $ancien_dossier = dossier($f); $nouveau_dossier = dossier($nouveau[$f]);
    $note = function ($regle, $avant, $apres) use (&$journal, $f) {
        if ($avant !== $apres) $journal[$regle][$avant . '  →  ' . $apres][] = $f;
    };

    // R1 — __DIR__ . '/…'
    $s = preg_replace_callback('~__DIR__(\s*\.\s*)([\'"])(/[^\'"$]*)\2~', function ($m) use ($ancien_dossier, $nouveau_dossier, $note) {
        $cible = normaliser($ancien_dossier . $m[3]);
        if ($cible === null) return $m[0];
        $nc = mapper($cible) ?? $cible;
        $r = relatif($nouveau_dossier, $nc);
        $neuf = '/' . $r . (substr($m[3], -1) === '/' && $r !== '' ? '/' : '');
        if ($r === '') $neuf = '';
        $out = '__DIR__' . $m[1] . $m[2] . $neuf . $m[2];
        if ($neuf === '') $out = '__DIR__';
        $note('R1', $m[3], $neuf);
        return $out;
    }, $s);

    // R3 — chemins relatifs « ../x » (liens HTML/JS entre pages), hors __DIR__.
    $s = preg_replace_callback('~(?<=[\'"(=])((?:\.\./)+)([A-Za-z0-9_][A-Za-z0-9_./-]*)~', function ($m) use ($ancien_dossier, $nouveau_dossier, $existe, $note) {
        $chemin = $m[1] . $m[2];
        $cible = normaliser($ancien_dossier . '/' . $chemin);
        if ($cible === null || !isset($existe[rtrim($cible, '/')])) return $m[0];
        $nc = mapper($cible) ?? $cible;
        $r = relatif($nouveau_dossier, $nc) . (substr($chemin, -1) === '/' ? '/' : '');
        $note('R3', $chemin, $r);
        return $r;
    }, $s);

    // R2 — URL relatives à la racine de l'application.
    $alt = implode('|', array_map(function ($p) { return preg_quote($p, '~'); }, $prefixes_url));
    $s = preg_replace_callback('~(^|[\s\'"(=,\[`]|\?>/|[\'"]/|\+ ?[\'"]/)(' . $alt . ')([A-Za-z0-9_./-]*)~m', function ($m) use ($note) {
        $p = $m[2] . $m[3];
        $n = mapper($p);
        if ($n === null) return $m[0];
        $note('R2', $p, $n);
        return $m[1] . $n;
    }, $s);
    // Fichiers déplacés depuis la racine : seulement en position d'URL.
    $alt2 = implode('|', array_map(function ($p) { return preg_quote($p, '~'); }, $fichiers_racine));
    if ($alt2 !== '') $s = preg_replace_callback('~(APP_URL\s*\.\s*[\'"]/|APP_URL\s*\?>/|rediriger\(\s*[\'"])(' . $alt2 . ')~', function ($m) use ($note) {
        $n = mapper($m[2]);
        $note('R2', $m[2], $n);
        return $m[1] . $n;
    }, $s);

    if ($s !== $src) $contenus[$f] = $s;
}

foreach ($journal as $regle => $lignes) {
    echo "\n===== $regle (" . count($lignes) . " remplacements distincts) =====\n";
    ksort($lignes);
    foreach ($lignes as $l => $fs) echo str_pad((string) count($fs), 4, ' ', STR_PAD_LEFT) . "  $l\n";
}
$deplaces = array_filter($nouveau, function ($n, $a) { return $n !== $a; }, ARRAY_FILTER_USE_BOTH);
echo "\nFichiers déplacés : " . count($deplaces) . " ; fichiers réécrits : " . count($contenus) . "\n";
if (!$go) { echo "Aperçu seulement — relancer avec --go.\n"; exit; }

foreach ($deplaces as $a => $n) {
    if (!is_dir(dirname($n))) mkdir(dirname($n), 0775, true);
    exec('git mv ' . escapeshellarg($a) . ' ' . escapeshellarg($n), $o, $rc);
    if ($rc !== 0) exit("ÉCHEC git mv $a\n");
}
foreach ($contenus as $a => $s) file_put_contents($nouveau[$a], $s);
echo "Appliqué.\n";
