<?php
// =====================================================================
//  bd/secondaire/outils/importer_base_lam.php  (outil d'administration, CLI)
//  Importe une base de la lignée LAM_ABZ / ABZ_MBE (même serveur MySQL)
//  dans une école SECONDAIRE de l'annuaire SIGES déjà créée (association >
//  Nouvel établissement, ou creer_etablissement()) — ex. ABZ_MBE (lycée
//  technique de Mbé) dans une école créée en « Technique ».
//
//  Pour chaque table présente des deux côtés : les données de l'école cible
//  sont REMPLACÉES par celles de la source, colonnes communes seulement
//  (les colonnes propres à SIGES gardent leur valeur par défaut). Exceptions :
//    - licence*            : conservées (période d'essai SIGES de l'école) ;
//    - etablissement       : la ligne cible est mise à jour (identité,
//                            en-têtes), statut/ordre d'enseignement gardés ;
//    - utilisateur         : comptes de la source (SUPERADMIN → ADMIN,
//                            comptes de test « __…__ » ignorés), puis les
//                            comptes par défaut de la cible si leur
//                            identifiant est libre ;
//    - trimestre.active    : déduit de la séquence active.
//  Fichiers (logo, signatures, photos) copiés sous
//  assets/uploads/etab/<code>/ — chemins préfixés en base, aucune collision
//  avec une autre école ; les photos d'élèves passent en base (photo_bin).
//
//  Usage :
//    php bd/secondaire/outils/importer_base_lam.php <base_source> <CODE_ECOLE> <dossier_projet_source> [--go]
//  Sans --go : aperçu (nombre de lignes par table), rien n'est écrit.
//  Exemple :
//    php bd/secondaire/outils/importer_base_lam.php abz_mbe LTM C:/wamp64/www/ABZ_MBE --go
//  Demande du 12/10/2026.
// =====================================================================
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Outil en ligne de commande uniquement.\n"); }
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../noyau/connexion_assoc.php';

$src    = $argv[1] ?? '';
$code   = strtoupper($argv[2] ?? '');
$projet = rtrim(str_replace('\\', '/', $argv[3] ?? ''), '/');
$go     = in_array('--go', $argv, true);
if ($src === '' || $code === '' || $projet === '') {
    exit("Usage : php bd/secondaire/outils/importer_base_lam.php <base_source> <CODE_ECOLE> <dossier_projet_source> [--go]\n");
}
$e = assoc_one("SELECT * FROM etablissement WHERE code=?", [$code]);
if (!$e) exit("École « $code » absente de l'annuaire.\n");
if (($e['type_enseignement'] ?? '') !== 'secondaire') exit("L'école « $code » n'est pas une école secondaire.\n");
$dst = $e['db_name'];

$pdo = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS,
               [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
// Nom exact (casse) de la base source tel qu'information_schema le connaît.
$st = $pdo->prepare("SELECT schema_name FROM information_schema.schemata WHERE LOWER(schema_name)=LOWER(?)");
$st->execute([$src]);
$src = (string) ($st->fetchColumn() ?: $src);
$col = function (string $db, string $t) use ($pdo): array {
    $st = $pdo->prepare("SELECT column_name AS c FROM information_schema.columns WHERE table_schema=? AND table_name=? ORDER BY ordinal_position");
    $st->execute([$db, $t]);
    return array_column($st->fetchAll(), 'c');
};
$tables = function (string $db) use ($pdo): array {
    $st = $pdo->prepare("SELECT table_name AS t FROM information_schema.tables WHERE table_schema=? AND table_type='BASE TABLE'");
    $st->execute([$db]);
    return array_column($st->fetchAll(), 't');
};
$q = function (string $id): string { return '`' . str_replace('`', '``', $id) . '`'; };

$t_src = $tables($src); $t_dst = $tables($dst);
if (!$t_src) exit("Base source « $src » vide ou absente.\n");
$communes = array_values(array_intersect($t_src, $t_dst));
sort($communes);
$speciales = ['licence', 'licence_historique', 'licence_securite', 'licence_cles_utilisees', 'etablissement', 'utilisateur'];

echo "=== Import $src → $dst (école $code) " . ($go ? '' : '— APERÇU, rien n\'est écrit') . " ===\n";
foreach ($communes as $t) {
    $n = (int) $pdo->query("SELECT COUNT(*) FROM {$q($src)}.{$q($t)}")->fetchColumn();
    printf("  %-28s %7d%s\n", $t, $n, in_array($t, $speciales, true) ? '  (traitement particulier)' : '');
}
$ignorees = array_diff($t_src, $t_dst);
if ($ignorees) echo "  Tables absentes du schéma SIGES, ignorées : " . implode(', ', $ignorees) . "\n";
if (!$go) exit("\nRelancer avec --go pour importer.\n");

$racine  = realpath(__DIR__ . '/../../..') . '/';
$prefixe = 'etab/' . strtolower(preg_replace('/[^a-z0-9]/i', '', $code)) . '/';
$dir_dst = $racine . 'assets/uploads/' . $prefixe;
if (!is_dir($dir_dst)) mkdir($dir_dst, 0775, true);
// Copie un fichier de la source (chemin relatif à assets/uploads/) dans le
// dossier de l'école ; retourne le chemin à stocker en base, ou null.
$copier = function (?string $rel) use ($projet, $dir_dst, $prefixe): ?string {
    if ($rel === null || $rel === '') return null;
    $from = $projet . '/assets/uploads/' . $rel;
    if (!is_file($from)) return null;
    $nom = basename($rel);
    return copy($from, $dir_dst . $nom) ? $prefixe . $nom : null;
};

$pdo->exec("USE {$q($dst)}");
$pdo->exec("SET FOREIGN_KEY_CHECKS=0");
try {
    // 1. Tables ordinaires : remplacement complet, colonnes communes.
    foreach ($communes as $t) {
        if (in_array($t, $speciales, true)) continue;
        $cols = array_values(array_intersect($col($src, $t), $col($dst, $t)));
        $liste = implode(', ', array_map($q, $cols));
        $pdo->exec("DELETE FROM {$q($t)}");
        $n = $pdo->exec("INSERT INTO {$q($t)} ($liste) SELECT $liste FROM {$q($src)}.{$q($t)}");
        printf("  %-28s %7d ligne(s)\n", $t, $n);
    }

    // 2. Établissement : identité et en-têtes de la source, statut/ordre gardés.
    $cols = array_values(array_diff(array_intersect($col($src, 'etablissement'), $col($dst, 'etablissement')),
                                    ['id', 'statut', 'ordre_enseignement', 'logo', 'signature']));
    $set = implode(', ', array_map(function ($c) use ($q) { return "d.{$q($c)} = s.{$q($c)}"; }, $cols));
    $pdo->exec("UPDATE {$q('etablissement')} d JOIN {$q($src)}.{$q('etablissement')} s ON s.id = d.id SET $set");
    $es = $pdo->query("SELECT logo, signature FROM {$q($src)}.etablissement LIMIT 1")->fetch() ?: [];
    $pdo->prepare("UPDATE etablissement SET logo=?, signature=? WHERE id=1")
        ->execute([$copier($es['logo'] ?? null), $copier($es['signature'] ?? null)]);
    echo "  etablissement                identité importée, fichiers sous assets/uploads/$prefixe\n";

    // 3. Comptes utilisateurs.
    $defauts = $pdo->query("SELECT * FROM utilisateur")->fetchAll();
    $cols_u  = array_values(array_intersect($col($src, 'utilisateur'), $col($dst, 'utilisateur')));
    $liste   = implode(', ', array_map($q, $cols_u));
    $sel     = implode(', ', array_map(function ($c) use ($q) {
        return $c === 'role' ? "IF(role='SUPERADMIN', 'ADMIN', role)" : $q($c);
    }, $cols_u));
    $pdo->exec("DELETE FROM utilisateur");
    $n = $pdo->exec("INSERT INTO utilisateur ($liste) SELECT $sel FROM {$q($src)}.utilisateur WHERE login NOT LIKE '\\_\\_%\\_\\_'");
    $ajoutes = 0;
    foreach ($defauts as $u) {
        $existe = $pdo->prepare("SELECT 1 FROM utilisateur WHERE login=?");
        $existe->execute([$u['login']]);
        if ($existe->fetchColumn()) continue;
        unset($u['id']);
        $c = array_keys($u);
        $pdo->prepare("INSERT INTO utilisateur (" . implode(', ', array_map($q, $c)) . ") VALUES (" . implode(', ', array_fill(0, count($c), '?')) . ")")
            ->execute(array_values($u));
        $ajoutes++;
    }
    echo "  utilisateur                  $n compte(s) importé(s) + $ajoutes compte(s) par défaut conservé(s)\n";

    // 4. Trimestre actif = celui de la séquence active.
    $pdo->exec("UPDATE trimestre SET active = 0");
    $pdo->exec("UPDATE trimestre t JOIN sequence s ON s.id_trim = t.id AND s.active = 1 SET t.active = 1");

    // 5. Fichiers de signature (chemins préfixés par école).
    foreach ($pdo->query("SELECT code, fichier FROM signature_titulaire WHERE fichier IS NOT NULL AND fichier <> ''")->fetchAll() as $s) {
        $pdo->prepare("UPDATE signature_titulaire SET fichier=? WHERE code=?")->execute([$copier($s['fichier']), $s['code']]);
    }
    if (in_array('signature', $col($dst, 'enseignant'), true)) {
        foreach ($pdo->query("SELECT matricule_ens, signature FROM enseignant WHERE signature IS NOT NULL AND signature <> ''")->fetchAll() as $s) {
            $pdo->prepare("UPDATE enseignant SET signature=? WHERE matricule_ens=?")->execute([$copier($s['signature']), $s['matricule_ens']]);
        }
    }

    // 6. Photos des élèves : directement en base (photo_bin), comme le
    //    reste du secondaire SIGES (migration secondaire v9).
    $nb_photos = 0;
    if (in_array('photo_bin', $col($dst, 'eleve'), true)) {
        foreach ($pdo->query("SELECT id, photo FROM eleve WHERE photo IS NOT NULL AND photo <> ''")->fetchAll() as $el) {
            $f = $projet . '/assets/uploads/eleves/' . $el['photo'];
            if (is_file($f)) {
                $pdo->prepare("UPDATE eleve SET photo_bin=? WHERE id=?")->execute([file_get_contents($f), $el['id']]);
                $nb_photos++;
            }
        }
    }
    echo "  photos élèves                $nb_photos photo(s) enregistrée(s) en base\n";
} finally {
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
}
echo "=== Terminé. ===\n";
