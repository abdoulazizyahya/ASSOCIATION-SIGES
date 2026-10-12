<?php
// =====================================================================
//  deploy/outils/porter_abz.php  (outil de développement, CLI)
//  Porte une page d'ABZ_MBE (projet frère, lycée technique) vers
//  l'arborescence de SIGES : chaque chemin d'ABZ (relatif à sa racine) est
//  converti vers son emplacement SIGES puis réécrit relativement au
//  fichier de destination.
//    config/config.php                          → config/config.php
//    connexion|fonctions|paie_fonctions  → noyau/
//    layout/                             → noyau/layout/
//    pdf/                                → secondaire/commun/pdf/
//    pages/{notes,bulletins}/            → secondaire/technique/pages/… (option --technique)
//                                          ou secondaire/general/pages/…
//    pages/X/                            → secondaire/commun/pages/X/
//    assets/, vendor/, bd/, verif_*.php  → inchangés
//  Ajustements SIGES : rôles (FONDATEUR, MEMBRE_ASSOCIATION, plus de
//  SUPERADMIN), vrai NIU affiché, photos des élèves en base, BOM retiré.
//  Usage : php deploy/outils/porter_abz.php <fichier_abz> <destination_siges> [--technique]
//  Le fichier produit est ensuite RELU et adapté à la main.
// =====================================================================
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$src = $argv[1] ?? ''; $dst = $argv[2] ?? '';
$technique = in_array('--technique', $argv, true);
if (!is_file($src) || $dst === '') exit("Usage : php porter_abz.php <fichier_abz> <destination> [--technique]\n");
$abz = realpath(dirname($src)); $abz_racine = $abz;
while ($abz_racine && !is_file($abz_racine . '/config.php')) $abz_racine = dirname($abz_racine);
$rel_src = ltrim(str_replace('\\', '/', substr(realpath($src), strlen($abz_racine))), '/');
$dst = str_replace('\\', '/', $dst);

function vers_siges(string $p, bool $technique): string {
    if (preg_match('~^(connexion|fonctions|paie_fonctions|ecole_contexte)\.php$~', $p)) return 'noyau/' . $p;
    if (strncmp($p, 'layout/', 7) === 0 || $p === 'layout') return 'noyau/' . $p;
    if (strncmp($p, 'ajax/', 5) === 0) return 'noyau/' . $p;
    if (strncmp($p, 'pdf/', 4) === 0 || $p === 'pdf') return 'secondaire/commun/' . $p;
    if (preg_match('~^pages/(notes|bulletins)(/|$)~', $p)) return 'secondaire/' . ($technique ? 'technique' : 'general') . '/' . $p;
    if (strncmp($p, 'pages/', 6) === 0) return 'secondaire/commun/' . $p;
    return $p;
}
function normaliser(string $p): ?string {
    $o = [];
    foreach (explode('/', $p) as $s) {
        if ($s === '' || $s === '.') continue;
        if ($s === '..') { if (!$o) return null; array_pop($o); continue; }
        $o[] = $s;
    }
    return implode('/', $o);
}
function relatif(string $de, string $vers): string {
    $a = $de === '' ? [] : explode('/', $de); $b = $vers === '' ? [] : explode('/', $vers);
    $i = 0; while ($i < count($a) && $i < count($b) && $a[$i] === $b[$i]) $i++;
    return implode('/', array_merge(array_fill(0, count($a) - $i, '..'), array_slice($b, $i)));
}
$d_src = dirname($rel_src) === '.' ? '' : dirname($rel_src);
$d_dst = dirname($dst) === '.' ? '' : dirname($dst);

$s = str_replace("\r\n", "\n", file_get_contents($src));
if (strncmp($s, "\xEF\xBB\xBF", 3) === 0) $s = substr($s, 3);

// __DIR__ . '/…'
$s = preg_replace_callback('~__DIR__(\s*\.\s*)([\'"])(/[^\'"$]*)\2~', function ($m) use ($d_src, $d_dst, $technique) {
    $cible = normaliser($d_src . $m[3]);
    if ($cible === null) return $m[0];
    $r = relatif($d_dst, vers_siges($cible, $technique));
    return '__DIR__' . $m[1] . $m[2] . '/' . $r . (substr($m[3], -1) === '/' ? '/' : '') . $m[2];
}, $s);
// URL relatives à la racine (« pages/… », « pdf/… », après APP_URL).
$s = preg_replace_callback('~(^|[\s\'"(=,\[`]|\?>/|[\'"]/)(pages/[a-z_]+/[A-Za-z0-9_]+\.php|pdf/[A-Za-z0-9_]+\.php|ajax/[A-Za-z0-9_]+\.php)~m',
    function ($m) use ($technique) { return $m[1] . vers_siges($m[2], $technique); }, $s);

// Rôles et conventions SIGES.
$s = str_replace(["'SUPERADMIN', ", "'SUPERADMIN',", ", 'SUPERADMIN'"], '', $s);
$s = str_replace("['ADMIN','PROVISEUR','CENSEUR']", "['ADMIN','PROVISEUR','FONDATEUR','CENSEUR']", $s);
$s = str_replace("['ADMIN', 'PROVISEUR', 'CENSEUR']", "['ADMIN', 'PROVISEUR', 'FONDATEUR', 'CENSEUR']", $s);
$s = str_replace("!empty(\$eleve['photo']) ? __DIR__ . '/" . relatif($d_dst, 'assets/uploads/eleves') . "/' . \$eleve['photo'] : ''",
                 "!empty(\$eleve['photo']) ? photo_sec_fichier_tmp((int) \$eleve['id']) : ''", $s);

if (!is_dir(dirname($dst))) mkdir(dirname($dst), 0775, true);
file_put_contents($dst, $s);
echo "$rel_src → $dst\n";
