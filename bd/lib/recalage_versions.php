<?php
// =====================================================================
//  bd/lib/recalage_versions.php — détection de la VRAIE version de schéma
//  de chaque base école et recalage de schema_version_etab.
// =====================================================================
//  Utilisé par bd/assoc/recaler_versions.php (ligne de commande) et par
//  association/versions.php (portail, propriétaire seul) — voir le
//  commentaire de bd/assoc/recaler_versions.php pour la méthode.
//  À inclure après config.php / connexion.php (fonctions assoc_*()).
// =====================================================================

// Version de départ (schéma de référence) par type d'école — PRIMAIRE :
// schema_ref_ecole.sql est à l'état v50, les migrations <= 50 n'y sont
// jamais vérifiées (voir assoc_version_depart(), connexion_assoc.php).
const RV_BASE = ['primaire' => 50, 'secondaire' => 0];

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


// Indices vérifiables par type d'école (calculés une fois par requête).
function rv_series(): array {
    static $series = null;
    if ($series !== null) return $series;
    foreach (['primaire', 'secondaire'] as $type) {
        $brut = [];
        foreach (rv_migrations($type) as $v => $f) if ($v > RV_BASE[$type]) $brut[$v] = rv_indices($f);
        $series[$type] = rv_indices_fiables($brut);
    }
    return $series;
}

// Analyse une école (ligne etablissement + version enregistrée). Retour :
//   joignable, actuel (?int), reelle (int), derniere (int),
//   partielles/trous/a_appliquer (versions), manque [v => [libellés]].
function rv_analyser_ecole(array $e): array {
    $type   = ($e['type_enseignement'] ?? 'primaire') === 'secondaire' ? 'secondaire' : 'primaire';
    $serie  = rv_series()[$type];
    $r = ['id' => (int) $e['id'], 'code' => $e['code'], 'nom' => $e['nom'], 'type' => $type,
          'actuel' => $e['version'] === null ? null : (int) $e['version'], 'joignable' => false,
          'reelle' => RV_BASE[$type], 'derniere' => $serie ? (int) array_key_last($serie) : RV_BASE[$type],
          'partielles' => [], 'trous' => [], 'a_appliquer' => [], 'manque' => []];

    $l = @mysqli_connect(DB_HOST, DB_USER, DB_PASS, $e['db_name']);
    if (!$l) return $r;
    mysqli_set_charset($l, 'utf8mb4');
    $r['joignable'] = true;

    $etat = [];   // v => 'ok' | 'non' | 'partiel' | '?'
    foreach ($serie as $v => $ind) {
        if (!$ind) { $etat[$v] = '?'; continue; }
        $ok = 0; $m = [];
        foreach ($ind as $i) { if (rv_verifier($l, $i)) $ok++; else $m[] = rv_libelle($i); }
        $etat[$v] = $ok === count($ind) ? 'ok' : ($ok === 0 ? 'non' : 'partiel');
        if ($m) $r['manque'][$v] = $m;
    }
    mysqli_close($l);

    // Version réelle : on avance tant que les migrations vérifiables sont appliquées.
    foreach ($etat as $v => $s) { if ($s === 'non' || $s === 'partiel') break; $r['reelle'] = $v; }
    $r['partielles']  = array_keys(array_filter($etat, fn($s) => $s === 'partiel'));
    $r['trous']       = array_keys(array_filter($etat, fn($s, $v) => $v > $r['reelle'] && $s === 'ok', ARRAY_FILTER_USE_BOTH));
    $r['a_appliquer'] = array_values(array_filter(array_keys($etat), fn($v) => $v > $r['reelle']));
    return $r;
}

// Toutes les écoles actives (ou une seule par son code).
function rv_analyser(?string $code = null): array {
    $sql = "SELECT e.id, e.code, e.nom, e.db_name, e.type_enseignement, v.version
            FROM etablissement e LEFT JOIN schema_version_etab v ON v.id_etablissement=e.id
            WHERE e.actif=1" . ($code ? " AND e.code=?" : '') . " ORDER BY e.id";
    return array_map('rv_analyser_ecole', assoc_all($sql, $code ? [$code] : []));
}

// Recalage d'une école analysée : écrit la version réelle si elle diffère.
// Refusé (false) si la base est injoignable ou si une migration n'est que
// PARTIELLEMENT présente (à examiner à la main avant tout recalage).
function rv_peut_recaler(array $r): bool {
    return $r['joignable'] && !$r['partielles'] && $r['actuel'] !== $r['reelle'];
}
function rv_recaler(array $r): bool {
    if (!rv_peut_recaler($r)) return false;
    assoc_exec("INSERT INTO schema_version_etab (id_etablissement, version) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE version=VALUES(version)", [$r['id'], $r['reelle']]);
    return true;
}
