<?php
// =====================================================================
//  bd/assoc/recaler_versions.php — recale schema_version_etab sur la
//  VRAIE version de schéma de chaque base école.
// =====================================================================
//  Pourquoi : si schema_version_etab ne reflète plus l'état réel d'une base
//  (ex. toutes les écoles primaires remises à v1 le 25/09/2026 alors que
//  leurs bases étaient bien plus récentes), migrer_toutes_ecoles.php et le
//  bouton « Migrer » du portail rejouent des migrations déjà appliquées et
//  échouent dès la première (« colonne déjà existante »).
//
//  Méthode : chaque bd/migration_vN.sql (bd/secondaire/ pour une école
//  secondaire) est lu pour en extraire des INDICES vérifiables dans la base :
//    - ADD [COLUMN] `c`            -> la colonne existe
//    - ADD [UNIQUE] INDEX|KEY `k`  -> l'index existe
//    - CREATE TABLE `t`            -> la table existe
//    - DROP COLUMN|TABLE|INDEX `x` -> l'objet n'existe plus
//    - MODIFY/CHANGE `c` enum(...) -> la colonne accepte toutes ces valeurs
//  Un objet ajouté puis supprimé par une migration ultérieure (ou l'inverse)
//  n'est pas utilisé comme indice. Une migration sans indice (UPDATE de
//  données seul, etc.) est « indéterminée » et ne bloque pas.
//  Version réelle = la plus haute N telle que toutes les migrations
//  vérifiables <= N sont appliquées.
//  PRIMAIRE : les écoles partent du schéma de référence schema_ref_ecole.sql
//  (état v50, voir migrer_toutes_ecoles.php) — les migrations <= 50 (dont
//  d'anciennes migrations ABZ_MBE sans rapport avec ce schéma) y sont
//  intégrées et ne sont jamais vérifiées : seules v51+ le sont.
//
//  Usage :  php bd/assoc/recaler_versions.php                 (affiche, ne modifie RIEN)
//           php bd/assoc/recaler_versions.php --appliquer     (écrit schema_version_etab)
//           php bd/assoc/recaler_versions.php --ecole=EC1     (une seule école)
//           php bd/assoc/recaler_versions.php --detail        (indices manquants par migration)
//  Lecture seule sur les bases écoles ; seule écriture (avec --appliquer) :
//  la ligne schema_version_etab de l'annuaire.
// =====================================================================
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Script en ligne de commande uniquement.\n"); }

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';

$appliquer = in_array('--appliquer', $argv, true);
$detail    = in_array('--detail', $argv, true);
$filtre    = null;
foreach ($argv as $a) if (str_starts_with($a, '--ecole=')) $filtre = substr($a, 8);

// ── Extraction des indices d'une série de migrations ─────────────────
function rv_migrations(string $type): array {
    $dossier = $type === 'secondaire' ? __DIR__ . '/../secondaire' : __DIR__ . '/..';
    $m = [];
    foreach (glob($dossier . '/migration_v*.sql') ?: [] as $f) {
        if (preg_match('/migration_v(\d+)\.sql$/', $f, $x)) $m[(int) $x[1]] = $f;
    }
    ksort($m);
    return $m;
}

function rv_nettoyer(string $sql): string {
    $sql = preg_replace('~/\*.*?\*/~s', ' ', $sql);
    $sql = preg_replace('~^\s*--.*$~m', ' ', $sql);
    return preg_replace('~\s--\s.*$~m', ' ', $sql);
}

// Indices bruts : [ ['type'=>col|idx|tab|enum, 'table'=>…, 'nom'=>…, 'present'=>bool, 'valeurs'=>[]] ]
function rv_indices(string $fichier): array {
    $sql = rv_nettoyer(file_get_contents($fichier));
    $out = [];
    // CREATE TABLE / DROP TABLE (où qu'ils soient, y compris dans une chaîne PREPARE)
    if (preg_match_all('~CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?~i', $sql, $mm)) {
        foreach ($mm[1] as $t) $out[] = ['type' => 'tab', 'table' => $t, 'nom' => $t, 'present' => true];
    }
    if (preg_match_all('~DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?`?(\w+)`?~i', $sql, $mm)) {
        foreach ($mm[1] as $t) $out[] = ['type' => 'tab', 'table' => $t, 'nom' => $t, 'present' => false];
    }
    // ALTER TABLE `t` … : clauses jusqu'à la fin de l'instruction (;) — dans
    // une chaîne PREPARE, la suite (", 'SELECT 1')") ne contient aucune clause.
    if (preg_match_all('~ALTER\s+TABLE\s+`?(\w+)`?(.*?);~is', $sql, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $a) {
            [$t, $corps] = [$a[1], $a[2]];
            if (preg_match_all('~\bADD\s+(?:UNIQUE\s+)?(?:INDEX|KEY)\s+`?(\w+)`?~i', $corps, $x)) {
                foreach ($x[1] as $k) $out[] = ['type' => 'idx', 'table' => $t, 'nom' => $k, 'present' => true];
            }
            if (preg_match_all('~\bADD\s+(?:COLUMN\s+)?`?(\w+)`?~i', $corps, $x)) {
                foreach ($x[1] as $c) {
                    if (preg_match('~^(INDEX|KEY|UNIQUE|PRIMARY|CONSTRAINT|FOREIGN|FULLTEXT|SPATIAL|COLUMN)$~i', $c)) continue;
                    $out[] = ['type' => 'col', 'table' => $t, 'nom' => $c, 'present' => true];
                }
            }
            if (preg_match_all('~\bDROP\s+(?:COLUMN\s+)?`?(\w+)`?~i', $corps, $x)) {
                foreach ($x[1] as $c) {
                    if (preg_match('~^(INDEX|KEY|PRIMARY|FOREIGN|CONSTRAINT|CHECK|COLUMN)$~i', $c)) continue;
                    $out[] = ['type' => 'col', 'table' => $t, 'nom' => $c, 'present' => false];
                }
            }
            if (preg_match_all('~\bDROP\s+(?:INDEX|KEY)\s+`?(\w+)`?~i', $corps, $x)) {
                foreach ($x[1] as $k) $out[] = ['type' => 'idx', 'table' => $t, 'nom' => $k, 'present' => false];
            }
            if (preg_match_all('~\b(?:MODIFY\s+(?:COLUMN\s+)?`?(\w+)`?|CHANGE\s+(?:COLUMN\s+)?`?\w+`?\s+`?(\w+)`?)\s+enum\s*\(([^)]*)\)~i', $corps, $x, PREG_SET_ORDER)) {
                foreach ($x as $e) {
                    preg_match_all("~'([^']*)'~", $e[3], $v);
                    $out[] = ['type' => 'enum', 'table' => $t, 'nom' => $e[1] !== '' ? $e[1] : $e[2], 'present' => true, 'valeurs' => $v[1]];
                }
            }
        }
    }
    return $out;
}

// Retire les indices contredits par une autre migration (objet ajouté puis
// supprimé, ou l'inverse) : ils ne prouvent rien sur l'état actuel.
function rv_indices_fiables(array $par_version): array {
    $sens = [];
    foreach ($par_version as $ind) foreach ($ind as $i) {
        if ($i['type'] === 'enum') continue;
        $sens[$i['type'] . ':' . strtolower($i['table']) . '.' . strtolower($i['nom'])][$i['present'] ? 1 : 0] = true;
    }
    foreach ($par_version as $v => $ind) {
        $par_version[$v] = array_values(array_filter($ind, function ($i) use ($sens) {
            if ($i['type'] === 'enum') return true;
            return count($sens[$i['type'] . ':' . strtolower($i['table']) . '.' . strtolower($i['nom'])]) === 1;
        }));
    }
    // Enum : seule la DERNIÈRE définition d'une colonne fait foi.
    $derniere = [];
    foreach ($par_version as $v => $ind) foreach ($ind as $i) if ($i['type'] === 'enum') $derniere[strtolower($i['table'] . '.' . $i['nom'])] = $v;
    foreach ($par_version as $v => $ind) {
        $par_version[$v] = array_values(array_filter($ind, fn($i) => $i['type'] !== 'enum' || $derniere[strtolower($i['table'] . '.' . $i['nom'])] === $v));
    }
    return $par_version;
}

// ── Vérification d'un indice dans une base ───────────────────────────
function rv_verifier(mysqli $l, array $i): bool {
    $q = fn(string $sql, array $p) => (function () use ($l, $sql, $p) {
        $st = mysqli_prepare($l, $sql);
        mysqli_stmt_bind_param($st, str_repeat('s', count($p)), ...$p);
        mysqli_stmt_execute($st);
        $r = mysqli_stmt_get_result($st)->fetch_row();
        mysqli_stmt_close($st);
        return $r;
    })();
    switch ($i['type']) {
        case 'tab':
            $existe = (int) $q("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?", [$i['table']])[0] > 0;
            break;
        case 'col':
            $existe = (int) $q("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?", [$i['table'], $i['nom']])[0] > 0;
            break;
        case 'idx':
            $existe = (int) $q("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?", [$i['table'], $i['nom']])[0] > 0;
            break;
        case 'enum':
            $r = $q("SELECT column_type FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?", [$i['table'], $i['nom']]);
            if (!$r) return false;
            foreach ($i['valeurs'] as $v) if (stripos($r[0], "'" . $v . "'") === false) return false;
            return true;
        default:
            return true;
    }
    return $existe === $i['present'];
}

function rv_libelle(array $i): string {
    $o = match ($i['type']) { 'tab' => "table {$i['table']}", 'col' => "colonne {$i['table']}.{$i['nom']}",
                              'idx' => "index {$i['table']}.{$i['nom']}", 'enum' => "valeurs de {$i['table']}.{$i['nom']}" };
    return $i['type'] === 'enum' ? $o : ($i['present'] ? $o : "$o supprimé(e)");
}

// ── Traitement ───────────────────────────────────────────────────────
echo "=== Recalage des versions de schéma ===\n", $appliquer ? "MODE APPLICATION\n\n" : "MODE LECTURE (rien n'est modifié — ajoutez --appliquer)\n\n";

// Version de départ (schéma de référence) par type d'école.
const RV_BASE = ['primaire' => 50, 'secondaire' => 0];

$series = [];
foreach (['primaire', 'secondaire'] as $type) {
    $brut = [];
    foreach (rv_migrations($type) as $v => $f) if ($v > RV_BASE[$type]) $brut[$v] = rv_indices($f);
    $series[$type] = rv_indices_fiables($brut);
}

$sql = "SELECT e.id, e.code, e.nom, e.db_name, e.type_enseignement, v.version
        FROM etablissement e LEFT JOIN schema_version_etab v ON v.id_etablissement=e.id
        WHERE e.actif=1" . ($filtre ? " AND e.code=?" : '') . " ORDER BY e.id";
foreach (assoc_all($sql, $filtre ? [$filtre] : []) as $e) {
    $type   = ($e['type_enseignement'] ?? 'primaire') === 'secondaire' ? 'secondaire' : 'primaire';
    $serie  = $series[$type];
    $actuel = $e['version'] === null ? null : (int) $e['version'];
    echo str_pad($e['code'], 8), " ", $e['nom'], " ($type)\n";

    $l = @mysqli_connect(DB_HOST, DB_USER, DB_PASS, $e['db_name']);
    if (!$l) { echo "   base « {$e['db_name']} » injoignable — ignorée\n\n"; continue; }
    mysqli_set_charset($l, 'utf8mb4');

    $etat = [];   // v => 'ok' | 'non' | 'partiel' | '?'
    $manque = [];
    foreach ($serie as $v => $ind) {
        if (!$ind) { $etat[$v] = '?'; continue; }
        $ok = 0; $m = [];
        foreach ($ind as $i) { if (rv_verifier($l, $i)) $ok++; else $m[] = rv_libelle($i); }
        $etat[$v]   = $ok === count($ind) ? 'ok' : ($ok === 0 ? 'non' : 'partiel');
        $manque[$v] = $m;
    }
    mysqli_close($l);

    // Version réelle : on avance tant que les migrations vérifiables sont appliquées.
    $reelle = RV_BASE[$type];
    foreach ($etat as $v => $s) { if ($s === 'non' || $s === 'partiel') break; $reelle = $v; }
    $trous = array_keys(array_filter($etat, fn($s, $v) => $v > $reelle && $s === 'ok', ARRAY_FILTER_USE_BOTH));
    $pb    = array_keys(array_filter($etat, fn($s) => $s === 'partiel'));

    echo "   enregistrée : v", $actuel ?? '—', "   réelle détectée : v$reelle",
         ($reelle === array_key_last($etat) || !$etat) ? " (à jour)" : " → à appliquer : v" . implode(', v', array_filter(array_keys($etat), fn($v) => $v > $reelle)), "\n";
    if ($pb)    echo "   ⚠ migration(s) PARTIELLEMENT présente(s) : v", implode(', v', $pb), " — à examiner avant de migrer\n";
    if ($trous) echo "   ℹ déjà présentes plus loin (seront rejouées sans effet si idempotentes) : v", implode(', v', $trous), "\n";
    if ($detail) foreach ($manque as $v => $m) if ($m && $v > $reelle) echo "      v$v manque : ", implode(' ; ', array_slice($m, 0, 4)), count($m) > 4 ? ' …' : '', "\n";

    if ($appliquer && $actuel !== $reelle) {
        if ($pb) { echo "   ✗ NON recalée (migration partielle à examiner d'abord)\n\n"; continue; }
        assoc_exec("INSERT INTO schema_version_etab (id_etablissement, version) VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE version=VALUES(version)", [$e['id'], $reelle]);
        echo "   ✔ schema_version_etab : v", $actuel ?? '—', " → v$reelle\n";
    }
    echo "\n";
}
echo "=== Terminé. ===\n";
