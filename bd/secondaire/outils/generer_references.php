<?php
// =====================================================================
//  bd/secondaire/outils/generer_references.php  (outil de développement)
//  Régénère les DONNÉES DE RÉFÉRENCE du secondaire à partir de :
//    - la base LAM_ABZ (matières = groupes de compétences, compétences par
//      niveau et par trimestre), NETTOYÉES : caractères Windows mal
//      convertis (U+0092 -> ', U+0096 -> –, U+009C -> œ…), retours à la
//      ligne, espaces inutiles, apostrophes typographiques, accents
//      manquants (liste vérifiée, compétences françaises seulement),
//      majuscule initiale, doublons ;
//    - la géographie COMPLÈTE du primaire (10 régions, 58 départements,
//      360 arrondissements — base d'une école primaire de l'annuaire).
//  Écrit :
//    bd/assoc/seed_competences_secondaire.sql   (gabarit des compétences)
//    bd/assoc/seed_ref_ecole_secondaire.sql     (géographie + matières)
//    bd/secondaire/reference_coefficients.php   (clés nettoyées)
//    bd/secondaire/migration_v10.sql            (mise à jour des écoles existantes)
//  Usage (CLI, poste de développement) :
//    php bd/secondaire/outils/generer_references.php [base_lam_abz] [base_primaire]
//  Demande du 04/10/2026.
// =====================================================================
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Outil en ligne de commande uniquement.\n"); }
require_once __DIR__ . '/../../../config.php';

$base_lam  = $argv[1] ?? 'lam_abz';
$base_prim = $argv[2] ?? 'promeducam_poussins_de_jaynitaare';
$racine    = realpath(__DIR__ . '/../../..') . '/';
$pdo = fn(string $db) => new PDO('mysql:host=' . DB_HOST . ";dbname=$db;charset=utf8mb4", DB_USER, DB_PASS,
                                 [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$lam = $pdo($base_lam); $prim = $pdo($base_prim);
$q = fn($v) => $v === null ? 'NULL' : "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], (string) $v) . "'";

// ── Nettoyage d'un texte ─────────────────────────────────────────────
function nettoyer(string $s, bool $majuscule = true): string {
    $s = strtr($s, [
        "\u{0092}" => "'", "\u{0091}" => "'", "\u{2019}" => "'", "\u{2018}" => "'", "\u{02BC}" => "'",
        "\u{0093}" => '"', "\u{0094}" => '"', "\u{201C}" => '"', "\u{201D}" => '"',
        "\u{0096}" => '–', "\u{0097}" => '—', "\u{009C}" => 'œ', "\u{008C}" => 'Œ', "\u{0085}" => '…',
        "\u{00A0}" => ' ', "\r" => ' ', "\n" => ' ', "\t" => ' ',
    ]);
    $s = preg_replace('/[\x{0080}-\x{009F}]/u', '', $s);          // autres caractères de contrôle C1
    $s = preg_replace('/\s+/u', ' ', $s);                          // espaces multiples
    $s = preg_replace('/\s+([,.)])/u', '$1', $s);                  // pas d'espace avant , . )
    $s = preg_replace('/\(\s+/u', '(', $s);
    $s = preg_replace("/\s+'/u", "'", $s);                         // jamais d'espace AVANT l'apostrophe
    // Élision française : pas d'espace APRÈS (« l' école » -> « l'école ») —
    // jamais ailleurs (possessif anglais « consumers' protection » conservé).
    $s = preg_replace("/\b(l|d|n|s|j|m|t|c|qu|jusqu|lorsqu|puisqu|quoiqu)'\s+/iu", "$1'", $s);
    $s = preg_replace('/,(?=\S)/u', ', ', $s);                     // espace après la virgule
    $s = trim($s);
    if ($majuscule && $s !== '') $s = mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    return $s;
}

// Accents manquants — liste VÉRIFIÉE (mot sans accent -> forme correcte),
// appliquée seulement aux compétences rédigées en français.
const ACCENTS = [
    'elements' => 'éléments', 'element' => 'élément', 'roles' => 'rôles', 'role' => 'rôle',
    'integration' => 'intégration', 'education' => 'éducation', 'hygiene' => 'hygiène',
    'representations' => 'représentations', 'representation' => 'représentation',
    'maitriser' => 'maîtriser', 'prevalence' => 'prévalence', 'ecrire' => 'écrire',
    'equations' => 'équations', 'equation' => 'équation', 'strategies' => 'stratégies', 'strategie' => 'stratégie',
    'cooperation' => 'coopération', 'defense' => 'défense', 'procedures' => 'procédures', 'procedure' => 'procédure',
    'ere' => 'ère', 'medias' => 'médias', 'media' => 'média', 'negative' => 'négative', 'imperative' => 'impérative',
    'present' => 'présent',
];
function est_francais(string $s): bool {
    $mots = preg_split('/[^\p{L}]+/u', mb_strtolower($s), -1, PREG_SPLIT_NO_EMPTY);
    $fr = count(array_intersect($mots, ['le', 'la', 'les', 'des', 'du', 'de', 'et', 'un', 'une', 'en', 'pour', 'sur', 'dans', 'aux', 'au', 'à', 'son', 'ses', 'leur']));
    $en = count(array_intersect($mots, ['the', 'and', 'of', 'to', 'in', 'for', 'with', 'on', 'about', 'their', 'using']));
    return $fr >= 2 && $fr > $en;
}
function accentuer(string $s): string {
    return preg_replace_callback('/\b(\p{L}+)\b/u', function ($m) {
        $w = $m[1]; $bas = mb_strtolower($w);
        if (!isset(ACCENTS[$bas])) return $w;
        $c = ACCENTS[$bas];
        if ($w === mb_strtoupper($w) && mb_strlen($w) > 1) return mb_strtoupper($c);
        if (mb_substr($w, 0, 1) === mb_strtoupper(mb_substr($w, 0, 1))) return mb_strtoupper(mb_substr($c, 0, 1)) . mb_substr($c, 1);
        return $c;
    }, $s);
}

// ── 1. Matières (groupes de compétences) ─────────────────────────────
$matieres = $lam->query("SELECT * FROM matiere ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$mat_propre = [];   // id -> libellé nettoyé
$maj_matieres = []; // ancien -> nouveau (si différent)
foreach ($matieres as $m) {
    $propre = nettoyer((string) $m['libelle'], false);
    $mat_propre[$m['id']] = $propre;
    if ($propre !== $m['libelle']) $maj_matieres[$m['libelle']] = $propre;
}

// ── 2. Compétences ───────────────────────────────────────────────────
$comps = $lam->query("SELECT c.*, t.ordre AS ordre_trim FROM competence c JOIN trimestre t ON t.id = c.id_trim
                      ORDER BY c.id_matiere, c.code_niveau, t.ordre, c.ordre, c.id")->fetchAll(PDO::FETCH_ASSOC);
$gabarit = []; $vu = []; $maj_textes = []; $nb_doublons = 0; $stats = ['modifiees' => 0, 'accents' => 0];
foreach ($comps as $c) {
    $mat = $mat_propre[$c['id_matiere']] ?? null;
    if ($mat === null) continue;
    $propre = nettoyer((string) $c['libelle']);
    if (est_francais($propre)) { $a = accentuer($propre); if ($a !== $propre) $stats['accents']++; $propre = $a; }
    $en = $c['libelle_en'] !== null && trim((string) $c['libelle_en']) !== '' ? nettoyer((string) $c['libelle_en']) : null;
    if ($propre !== $c['libelle']) { $stats['modifiees']++; $maj_textes[$mat][$c['libelle']] = $propre; }
    $cle = $mat . '|' . $c['code_niveau'] . '|' . $c['ordre_trim'] . '|' . mb_strtolower($propre);
    if (isset($vu[$cle])) { $nb_doublons++; continue; }
    $vu[$cle] = true;
    $grp = $mat . '|' . $c['code_niveau'] . '|' . $c['ordre_trim'];
    $gabarit[$grp][] = [$mat, $c['code_niveau'], (int) $c['ordre_trim'], $propre, $en];
}
$lignes = [];
foreach ($gabarit as $items) foreach ($items as $i => [$mat, $niv, $tri, $lib, $en]) {
    $lignes[] = 'ROW(' . $q($mat) . ',' . $q($niv) . ',' . $tri . ',' . $q($lib) . ',' . $q($en) . ',' . ($i + 1) . ')';
}
$seed_comp = "-- bd/assoc/seed_competences_secondaire.sql\n"
    . "-- Gabarit des COMPÉTENCES (groupes = matières) par niveau et par trimestre\n"
    . "-- d'une école secondaire — repris de LAM_ABZ et NETTOYÉ le 04/10/2026\n"
    . "-- (bd/secondaire/outils/generer_references.php : caractères mal convertis,\n"
    . "-- espaces, apostrophes, accents, doublons). Indexé par libellé de matière +\n"
    . "-- code niveau + ORDRE du trimestre (1..3), donc indépendant de toute année :\n"
    . "-- appliquer_competences_ref_secondaire() (fonctions.php) le rejoue sur les\n"
    . "-- trimestres de chaque année créée, en remplaçant :ID_ANNEE:. Idempotent.\n"
    . "INSERT INTO `competence` (`id_matiere`, `code_niveau`, `id_trim`, `libelle`, `libelle_en`, `ordre`)\n"
    . "SELECT m.`id`, v.`code_niveau`, t.`id`, v.`libelle`, v.`libelle_en`, v.`ordre`\n"
    . "FROM (VALUES\n" . implode(",\n", $lignes) . "\n"
    . ") AS v(`matiere`, `code_niveau`, `ordre_trim`, `libelle`, `libelle_en`, `ordre`)\n"
    . "JOIN `matiere` m ON m.`libelle` = v.`matiere`\n"
    . "JOIN `niveau`  n ON n.`code_niveau` = v.`code_niveau`\n"
    . "JOIN `trimestre` t ON t.`id_annee` = :ID_ANNEE: AND t.`ordre` = v.`ordre_trim`\n"
    . "WHERE NOT EXISTS (\n  SELECT 1 FROM `competence` c\n"
    . "  WHERE c.`id_matiere` = m.`id` AND c.`code_niveau` = v.`code_niveau`\n"
    . "    AND c.`id_trim` = t.`id` AND c.`libelle` = v.`libelle`\n);\n";
file_put_contents($racine . 'bd/assoc/seed_competences_secondaire.sql', $seed_comp);

// ── 3. Géographie complète du primaire ───────────────────────────────
$regions = $prim->query("SELECT id_region AS id, intitule_region AS nom FROM region ORDER BY id_region")->fetchAll(PDO::FETCH_ASSOC);
$deps    = $prim->query("SELECT code_depart AS id, code_region AS id_region, intitule_depart AS nom FROM departement ORDER BY code_depart")->fetchAll(PDO::FETCH_ASSOC);
$arrs    = $prim->query("SELECT code_arrond AS id, code_depart AS id_departement, intitule_arrond AS nom FROM arrondissement ORDER BY code_arrond")->fetchAll(PDO::FETCH_ASSOC);
$geo_ins = [];
foreach ($regions as $r) $geo_ins[] = "INSERT IGNORE INTO `region` (`id`, `nom`, `nom_en`) VALUES ({$r['id']}," . $q(nettoyer($r['nom'], false)) . ",NULL);";
foreach ($deps as $d)    $geo_ins[] = "INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES ({$d['id']},{$d['id_region']}," . $q(nettoyer($d['nom'], false)) . ");";
foreach ($arrs as $a)    $geo_ins[] = "INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES ({$a['id']},{$a['id_departement']}," . $q(nettoyer($a['nom'], false)) . ");";

// Seed de référence : remplace les lignes géographiques (LAM_ABZ, incomplètes
// et mal encodées) et nettoie les libellés de matières.
$f_ref = $racine . 'bd/assoc/seed_ref_ecole_secondaire.sql';
$ref = str_replace("\r\n", "\n", file_get_contents($f_ref));
$sortie = []; $geo_place = false;
foreach (explode("\n", $ref) as $l) {
    // Commentaire posé par une génération précédente : réécrit ci-dessous.
    if (str_starts_with($l, '-- Géographie : reprise COMPLÈTE') || str_starts_with($l, '-- 360 arrondissements) le ')) continue;
    if (preg_match('/^INSERT IGNORE INTO `(region|departement|arrondissement)`/', $l)) {
        if (!$geo_place) { $sortie[] = '-- Géographie : reprise COMPLÈTE du primaire (10 régions, 58 départements,'; $sortie[] = '-- 360 arrondissements) le 04/10/2026 — bd/secondaire/outils/generer_references.php.'; array_push($sortie, ...$geo_ins); $geo_place = true; }
        continue;
    }
    if (str_starts_with($l, 'INSERT IGNORE INTO `matiere`')) foreach ($maj_matieres as $ancien => $nouveau) $l = str_replace($q($ancien), $q($nouveau), $l);
    $sortie[] = $l;
}
file_put_contents($f_ref, implode("\n", $sortie));

// ── 4. Coefficients de référence : clés nettoyées ────────────────────
$f_coef = $racine . 'bd/secondaire/reference_coefficients.php';
$coefs = require $f_coef;
$txt = "<?php\n// Coefficients et ordre de référence par « niveau|matière », extraits de\n// LAM_ABZ (discipline) — libellés de matières nettoyés le 04/10/2026\n// (bd/secondaire/outils/generer_references.php). [coef, ordre].\nreturn [\n";
foreach ($coefs as $k => [$coef, $ordre]) {
    [$niv, $mat] = explode('|', $k, 2);
    $txt .= '    ' . var_export($niv . '|' . nettoyer($mat, false), true) . " => [$coef, $ordre],\n";
}
file_put_contents($f_coef, $txt . "];\n");

// ── 5. Migration v10 : met à jour les écoles secondaires EXISTANTES ──
$m = "-- =====================================================================\n"
   . "--  SIGES — Migration SECONDAIRE v10 : références nettoyées (04/10/2026)\n"
   . "-- =====================================================================\n"
   . "--  1. Géographie : reprise COMPLÈTE du primaire (10 régions, 58 départements,\n"
   . "--     360 arrondissements) au format du secondaire (id, nom) — remplace les\n"
   . "--     données LAM_ABZ incomplètes et mal encodées. Les élèves et le personnel\n"
   . "--     n'y sont liés que par leurs NOMS (champs texte) : rien n'est cassé.\n"
   . "--  2. Matières (groupes de compétences) : libellés nettoyés.\n"
   . "--  3. Compétences : textes nettoyés SUR PLACE (mêmes identifiants : les\n"
   . "--     notes déjà saisies restent rattachées), puis suppression des doublons\n"
   . "--     qui n'ont AUCUNE note ni absence justifiée.\n"
   . "--  Généré par bd/secondaire/outils/generer_references.php. Rejouable.\n"
   . "-- =====================================================================\n\n"
   . "SET FOREIGN_KEY_CHECKS = 0;\n"
   . "DROP TABLE IF EXISTS `arrondissement`;\nDROP TABLE IF EXISTS `departement`;\nDROP TABLE IF EXISTS `region`;\n"
   . "CREATE TABLE `region` (\n  `id` int unsigned NOT NULL AUTO_INCREMENT,\n  `nom` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,\n  `nom_en` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,\n  PRIMARY KEY (`id`),\n  UNIQUE KEY `nom` (`nom`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n"
   . "CREATE TABLE `departement` (\n  `id` int unsigned NOT NULL AUTO_INCREMENT,\n  `id_region` int unsigned NOT NULL,\n  `nom` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,\n  PRIMARY KEY (`id`),\n  KEY `id_region` (`id_region`),\n  CONSTRAINT `fk_departement_region` FOREIGN KEY (`id_region`) REFERENCES `region` (`id`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n"
   . "CREATE TABLE `arrondissement` (\n  `id` int unsigned NOT NULL AUTO_INCREMENT,\n  `id_departement` int unsigned NOT NULL,\n  `nom` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,\n  PRIMARY KEY (`id`),\n  KEY `id_departement` (`id_departement`),\n  CONSTRAINT `fk_arrondissement_departement` FOREIGN KEY (`id_departement`) REFERENCES `departement` (`id`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n"
   . implode("\n", $geo_ins) . "\nSET FOREIGN_KEY_CHECKS = 1;\n\n-- Matières\n";
foreach ($maj_matieres as $ancien => $nouveau) $m .= "UPDATE `matiere` SET `libelle` = " . $q($nouveau) . " WHERE `libelle` COLLATE utf8mb4_bin = " . $q($ancien) . ";\n";
$m .= "\n-- Compétences : textes nettoyés (matière + ancien texte exact)\n";
foreach ($maj_textes as $mat => $paires) foreach ($paires as $ancien => $nouveau) {
    $m .= "UPDATE `competence` c JOIN `matiere` mt ON mt.`id` = c.`id_matiere` SET c.`libelle` = " . $q($nouveau)
        . " WHERE mt.`libelle` = " . $q($mat) . " AND c.`libelle` COLLATE utf8mb4_bin = " . $q($ancien) . ";\n";
}
$m .= "\n-- Doublons devenus identiques : on garde le plus ancien, seulement s'ils n'ont aucune note\n"
   . "DELETE c FROM `competence` c\n"
   . "JOIN `competence` k ON k.`id_matiere` = c.`id_matiere` AND k.`code_niveau` = c.`code_niveau`\n"
   . "                    AND k.`id_trim` = c.`id_trim` AND k.`libelle` = c.`libelle` AND k.`id` < c.`id`\n"
   . "WHERE NOT EXISTS (SELECT 1 FROM `note` n WHERE n.`id_competence` = c.`id`)\n"
   . "  AND NOT EXISTS (SELECT 1 FROM `absence_justifiee` a WHERE a.`id_competence` = c.`id`);\n";
file_put_contents($racine . 'bd/secondaire/migration_v10.sql', $m);

// ── 6. Migration v11 : compétences manquantes de l'année ACTIVE ──────
// Le gabarit n'est appliqué qu'à la CRÉATION des trimestres d'une année :
// une année créée avant lui (cas de Camoo) n'a aucune compétence, donc des
// bulletins vides. On complète, pour l'année active, chaque groupe
// (matière + niveau + trimestre) qui n'a AUCUNE compétence — un groupe déjà
// rempli (même partiellement, ou modifié par l'école) n'est pas touché.
$m11 = "-- =====================================================================\n"
     . "--  SIGES — Migration SECONDAIRE v11 : compétences de l'année active\n"
     . "-- =====================================================================\n"
     . "--  Le gabarit (bd/assoc/seed_competences_secondaire.sql) n'est appliqué\n"
     . "--  qu'à la création des trimestres : une année créée avant lui n'a aucune\n"
     . "--  compétence et ses bulletins sortent vides. Complète, pour l'année ACTIVE,\n"
     . "--  chaque groupe matière + niveau + trimestre qui n'a AUCUNE compétence.\n"
     . "--  Les groupes déjà remplis ne sont pas touchés. Rejouable.\n"
     . "--  Généré par bd/secondaire/outils/generer_references.php.\n"
     . "-- =====================================================================\n\n"
     . str_replace(
         ["-- bd/assoc/seed_competences_secondaire.sql\n", "t.`id_annee` = :ID_ANNEE:", "    AND c.`id_trim` = t.`id` AND c.`libelle` = v.`libelle`\n"],
         ["", "t.`id_annee` = (SELECT a.`id` FROM `annee_scolaire` a WHERE a.`active` = 1 ORDER BY a.`id` DESC LIMIT 1)", "    AND c.`id_trim` = t.`id`\n"],
         preg_replace('/\A(?:--[^\n]*\n)+/', '', $seed_comp));
file_put_contents($racine . 'bd/secondaire/migration_v11.sql', $m11);

// ── 7. Migration v12 : école créée AVANT l'ajout des matières au seed ──
// (24/09/2026) : aucune matière, donc ni compétence (v11 sans effet) ni
// affectation par classe. Complète, sans rien écraser :
//   a) sections, niveaux, groupes, séries, matières de référence (une
//      matière déjà présente sous le même libellé ou le même id est gardée) ;
//   b) trimestres + séquences de l'année active s'ils manquent ;
//   c) compétences du gabarit (groupes matière/niveau/trimestre vides) ;
//   d) matières de chaque classe qui n'en a AUCUNE, dérivées des compétences
//      de son niveau (même règle que secondaire_deriver_matieres_competences()),
//      coefficient/ordre de reference_coefficients.php, sinon 1.
$ref_actuel = str_replace("\r\n", "\n", file_get_contents($f_ref));
$ref_sql = [];
foreach (preg_split('/;\n/', $ref_actuel) as $st) {
    $st = trim(preg_replace('/^--[^\n]*\n/m', '', $st . "\n"));
    if (preg_match('/^INSERT IGNORE INTO `(section_classe|niveau|groupe|serie)`/', $st)) { $ref_sql[] = $st . ';'; continue; }
    if (preg_match("/^INSERT IGNORE INTO `matiere` (\(.*?\)) VALUES \((\d+),(NULL|'(?:[^'\\\\]|\\\\.)*'),('(?:[^'\\\\]|\\\\.)*'),(.*)\)$/s", $st, $mm)) {
        $ref_sql[] = "INSERT INTO `matiere` $mm[1] SELECT $mm[2],$mm[3],$mm[4],$mm[5] FROM DUAL"
                   . " WHERE NOT EXISTS (SELECT 1 FROM `matiere` WHERE `id` = $mm[2] OR `libelle` = $mm[4]);";
    }
}
$coef_rows = [];
foreach ($coefs as $k => [$coef, $ordre]) {
    [$niv, $mat] = explode('|', $k, 2);
    $coef_rows[] = 'ROW(' . $q($niv) . ',' . $q(nettoyer($mat, false)) . ',' . (int) $coef . ',' . $q((string) $ordre) . ')';
}
$annee_active = "(SELECT a.`id` FROM `annee_scolaire` a WHERE a.`active` = 1 ORDER BY a.`id` DESC LIMIT 1)";
$m12 = "-- =====================================================================\n"
     . "--  SIGES — Migration SECONDAIRE v12 : matières, compétences, affectations\n"
     . "-- =====================================================================\n"
     . "--  Pour une école secondaire créée AVANT l'ajout des matières au seed de\n"
     . "--  référence (24/09/2026) : sans matière, ni compétence ni affectation par\n"
     . "--  classe (menu Pédagogie > Matières vide, bulletins sans compétences).\n"
     . "--  Complète sans rien écraser ni supprimer :\n"
     . "--    1. sections, niveaux, groupes, séries, matières de référence ;\n"
     . "--    2. trimestres et séquences de l'année active s'ils manquent ;\n"
     . "--    3. compétences du gabarit (groupes matière/niveau/trimestre vides) ;\n"
     . "--    4. matières des classes qui n'en ont AUCUNE (d'après les compétences\n"
     . "--       de leur niveau), avec coefficient et ordre de référence.\n"
     . "--  Rejouable. Généré par bd/secondaire/outils/generer_references.php.\n"
     . "-- =====================================================================\n\n"
     . "-- 1. Références\n" . implode("\n", $ref_sql) . "\n\n"
     . "-- 2. Trimestres et séquences de l'année active\n"
     . "INSERT INTO `trimestre` (`libelle`, `ordre`, `id_annee`, `active`)\n"
     . "SELECT v.`libelle`, v.`ordre`, a.`id`, 0\n"
     . "FROM (VALUES ROW('Trimestre 1',1), ROW('Trimestre 2',2), ROW('Trimestre 3',3)) AS v(`libelle`, `ordre`)\n"
     . "JOIN `annee_scolaire` a ON a.`id` = $annee_active\n"
     . "WHERE NOT EXISTS (SELECT 1 FROM `trimestre` t WHERE t.`id_annee` = a.`id`);\n"
     . "INSERT INTO `sequence` (`libelle`, `ordre`, `active`, `id_trim`)\n"
     . "SELECT v.`libelle`, v.`ordre`, 0, t.`id`\n"
     . "FROM (VALUES ROW('Séquence 1',1), ROW('Séquence 2',2)) AS v(`libelle`, `ordre`)\n"
     . "JOIN `trimestre` t ON t.`id_annee` = $annee_active\n"
     . "WHERE NOT EXISTS (SELECT 1 FROM `sequence` s JOIN `trimestre` t2 ON t2.`id` = s.`id_trim` WHERE t2.`id_annee` = t.`id_annee`);\n\n"
     . "-- 3. Compétences de l'année active\n"
     . preg_replace('/\A(?:--[^\n]*\n)+\n?/', '', $m11) . "\n\n"
     . "-- 4. Matières des classes qui n'en ont aucune\n"
     . "INSERT IGNORE INTO `discipline` (`id_mat`, `IDClasses`, `id_groupe`, `coef`, `ordre`)\n"
     . "SELECT DISTINCT m.`id`, cl.`id`, g.`id_groupe_comp`, COALESCE(r.`coef`, 1), COALESCE(r.`ordre`, '1')\n"
     . "FROM `classe` cl\n"
     . "JOIN `groupe` g ON g.`id_groupe_comp` = (SELECT g2.`id_groupe_comp` FROM `groupe` g2 WHERE g2.`id_section` = cl.`libelle_section` COLLATE utf8mb4_unicode_ci ORDER BY g2.`id_groupe_comp` LIMIT 1)\n"
     . "JOIN `competence` c ON c.`code_niveau` = cl.`code_niveau`\n"
     . "JOIN `matiere` m ON m.`id` = c.`id_matiere` AND m.`libelle_section` = cl.`libelle_section`\n"
     . "LEFT JOIN (VALUES\n" . implode(",\n", $coef_rows) . "\n) AS r(`niveau`, `matiere`, `coef`, `ordre`) ON r.`niveau` = cl.`code_niveau` AND r.`matiere` = m.`libelle`\n"
     . "WHERE cl.`archivee` = 0\n"
     . "  AND NOT EXISTS (SELECT 1 FROM `discipline` d WHERE d.`IDClasses` = cl.`id`);\n";
file_put_contents($racine . 'bd/secondaire/migration_v12.sql', $m12);

printf("Matières nettoyées : %d\nCompétences : %d lues, %d textes corrigés (dont %d accents), %d doublons retirés, %d dans le gabarit\n"
     . "Géographie : %d régions, %d départements, %d arrondissements\nFichiers écrits : seed_competences_secondaire.sql, seed_ref_ecole_secondaire.sql, reference_coefficients.php, migration_v10.sql\n",
    count($maj_matieres), count($comps), $stats['modifiees'], $stats['accents'], $nb_doublons, count($lignes),
    count($regions), count($deps), count($arrs));
