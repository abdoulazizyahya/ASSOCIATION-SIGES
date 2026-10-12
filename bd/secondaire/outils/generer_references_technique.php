<?php
// =====================================================================
//  bd/secondaire/outils/generer_references_technique.php  (outil de développement)
//  Régénère les DONNÉES DE RÉFÉRENCE du secondaire TECHNIQUE à partir de
//  la base ABZ_MBE (lycée technique — projet C:\wamp64\www\ABZ_MBE) :
//    - sections (COMMERCIALE / INDUSTRIELLE), niveaux (1A-4A, 2nde-Tle),
//      filières, séries, groupes (Enseignement professionnel / général /
//      autres, un jeu par section), catalogue des matières ;
//    - le PROGRAMME de référence : pour chaque (niveau, filière), les
//      matières enseignées avec coefficient, groupe et ordre — union des
//      classes réelles d'ABZ_MBE de ce niveau et de cette filière.
//  Écrit :
//    bd/assoc/seed_ref_ecole_technique.sql          (références)
//    bd/secondaire/reference_programme_technique.php (programme)
//  Usage (CLI, poste de développement) :
//    php bd/secondaire/outils/generer_references_technique.php [base_abz_mbe]
//  Demande du 12/10/2026.
// =====================================================================
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Outil en ligne de commande uniquement.\n"); }
require_once __DIR__ . '/../../../config/config.php';

$base   = $argv[1] ?? 'abz_mbe';
$racine = realpath(__DIR__ . '/../../..') . '/';
$pdo = new PDO('mysql:host=' . DB_HOST . ";dbname=$base;charset=utf8mb4", DB_USER, DB_PASS,
               [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$q = function ($v) { return $v === null ? 'NULL' : "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], (string) $v) . "'"; };
$propre = function (?string $s) { return $s === null ? null : trim(preg_replace('/\s+/u', ' ', $s)); };

$sections = $pdo->query("SELECT libelle_section FROM section_classe ORDER BY libelle_section")->fetchAll();
$niveaux  = $pdo->query("SELECT * FROM niveau ORDER BY ordre_niveau")->fetchAll();
$filieres = $pdo->query("SELECT * FROM filiere ORDER BY id")->fetchAll();
$series   = $pdo->query("SELECT * FROM serie ORDER BY id")->fetchAll();
$groupes  = $pdo->query("SELECT * FROM groupe ORDER BY id_groupe_comp")->fetchAll();
$matieres = $pdo->query("SELECT * FROM matiere ORDER BY id")->fetchAll();

// Section d'une matière = section des classes qui l'enseignent, si elle est
// unique (sinon NULL : matière commune aux deux sections).
$sect_mat = [];
foreach ($pdo->query("SELECT DISTINCT d.id_mat, c.libelle_section FROM discipline d JOIN classe c ON c.id=d.IDClasses
                      WHERE c.libelle_section IS NOT NULL") as $r) {
    $sect_mat[(int) $r['id_mat']][$r['libelle_section']] = true;
}

// ── Programme de référence par (niveau, filière) ─────────────────────
$prog = [];
$rows = $pdo->query(
    "SELECT c.code_niveau, c.id_filiere, d.id_mat, d.coef, d.id_groupe, d.ordre
     FROM discipline d JOIN classe c ON c.id=d.IDClasses
     WHERE c.code_niveau IS NOT NULL AND c.id_filiere IS NOT NULL AND c.archivee=0
     ORDER BY c.code_niveau, c.id_filiere, d.id_groupe, d.ordre+0, d.id_mat")->fetchAll();
foreach ($rows as $r) {
    $k = (int) $r['id_mat'];
    $prog[$r['code_niveau']][$r['id_filiere']][$k] ??= [
        'id_mat' => $k, 'coef' => (int) $r['coef'], 'id_groupe' => (int) $r['id_groupe'], 'ordre' => (int) $r['ordre'],
    ];
}
foreach ($prog as $niv => $parf) foreach ($parf as $fil => $mats) $prog[$niv][$fil] = array_values($mats);

// ── seed_ref_ecole_technique.sql ─────────────────────────────────────
$s  = "-- bd/assoc/seed_ref_ecole_technique.sql\n";
$s .= "-- Références pédagogiques d'une école secondaire TECHNIQUE neuve (sections,\n";
$s .= "-- niveaux, filières, séries, groupes, matières) — reprises de la base ABZ_MBE.\n";
$s .= "-- Chargé par connexion_assoc.php::charger_seed_ref_ecole_secondaire(\$l, 'technique')\n";
$s .= "-- À LA PLACE des références du général. INSERT IGNORE : rejouable.\n";
$s .= "-- GÉNÉRÉ par bd/secondaire/outils/generer_references_technique.php — ne pas éditer.\n\n";

$s .= "INSERT IGNORE INTO `section_classe` (`libelle_section`) VALUES\n"
    . implode(",\n", array_map(function ($r) use ($q) { return '(' . $q($r['libelle_section']) . ')'; }, $sections)) . ";\n\n";
$s .= "INSERT IGNORE INTO `niveau` (`code_niveau`, `libelle_niv`, `id_cycle`, `ordre_niveau`) VALUES\n"
    . implode(",\n", array_map(function ($r) use ($q, $propre) {
        return '(' . $q($r['code_niveau']) . ', ' . $q($propre($r['libelle_niv'])) . ', ' . $q($r['id_cycle']) . ', ' . $q($r['ordre_niveau']) . ')';
    }, $niveaux)) . ";\n\n";
$s .= "INSERT IGNORE INTO `filiere` (`id`, `libelle`) VALUES\n"
    . implode(",\n", array_map(function ($r) use ($q, $propre) { return '(' . $q($r['id']) . ', ' . $q($propre($r['libelle'])) . ')'; }, $filieres)) . ";\n\n";
$s .= "INSERT IGNORE INTO `serie` (`id`, `libelle`, `id_filiere`) VALUES\n"
    . implode(",\n", array_map(function ($r) use ($q, $propre) {
        return '(' . (int) $r['id'] . ', ' . $q($propre($r['libelle'])) . ', ' . $q($r['id_filiere']) . ')';
    }, $series)) . ";\n\n";
$s .= "INSERT IGNORE INTO `groupe` (`id_groupe_comp`, `libelle_groupe_comp`, `id_section`) VALUES\n"
    . implode(",\n", array_map(function ($r) use ($q, $propre) {
        return '(' . (int) $r['id_groupe_comp'] . ', ' . $q($propre($r['libelle_groupe_comp'])) . ', ' . $q($r['id_section']) . ')';
    }, $groupes)) . ";\n\n";
$s .= "INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES\n"
    . implode(",\n", array_map(function ($r) use ($q, $propre, $sect_mat) {
        $sec = isset($sect_mat[(int) $r['id']]) && count($sect_mat[(int) $r['id']]) === 1 ? array_key_first($sect_mat[(int) $r['id']]) : null;
        return '(' . (int) $r['id'] . ', ' . $q($propre($r['code'])) . ', ' . $q($propre($r['libelle'])) . ', '
             . $q($propre($r['libelle_en'])) . ', ' . $q($sec) . ', ' . (int) ($r['ordre'] ?? 1) . ', 1)';
    }, $matieres)) . ";\n";
file_put_contents($racine . 'bd/assoc/seed_ref_ecole_technique.sql', $s);

// ── reference_programme_technique.php ─────────────────────────────────
$p  = "<?php\n";
$p .= "// bd/secondaire/reference_programme_technique.php — programme de référence du\n";
$p .= "// secondaire TECHNIQUE : [code_niveau][id_filiere] => liste de\n";
$p .= "// ['id_mat','coef','id_groupe','ordre'] (ids de bd/assoc/seed_ref_ecole_technique.sql).\n";
$p .= "// Sert à pré-remplir les matières d'une classe technique neuve\n";
$p .= "// (secondaire_deriver_matieres_technique(), fonctions.php).\n";
$p .= "// GÉNÉRÉ par bd/secondaire/outils/generer_references_technique.php — ne pas éditer.\n";
$p .= 'return ' . var_export($prog, true) . ";\n";
file_put_contents($racine . 'bd/secondaire/reference_programme_technique.php', $p);

$nb = 0; foreach ($prog as $parf) $nb += count($parf);
printf("%d sections, %d niveaux, %d filières, %d séries, %d groupes, %d matières, %d programmes (niveau × filière).\n",
    count($sections), count($niveaux), count($filieres), count($series), count($groupes), count($matieres), $nb);
