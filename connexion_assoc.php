<?php
// ── Connexion à la base centrale « promeducam_assoc » (annuaire) ─────
//  Fournit $link_assoc (mysqli) + les helpers assoc_all / assoc_one /
//  assoc_val / assoc_exec / assoc_last_id — mêmes signatures que les
//  db_* de connexion.php, mais sur la connexion annuaire.
//
//  IMPORTANT : l'annuaire est OPTIONNEL. Tant que bd/assoc/installer.php
//  n'a pas tourné (base absente), $link_assoc vaut null et l'application
//  retombe sur l'installation mono-école historique (DB_NAME). Aucune
//  erreur fatale ici : le multi-établissement se greffe sans casser
//  l'existant.
//
//  À inclure APRÈS config.php (constante DB_NAME_ASSOC).

require_once __DIR__ . '/config.php';

if (!defined('DB_NAME_ASSOC')) {
    define('DB_NAME_ASSOC', 'promeducam_assoc');
}

// ── Nom de la base d'une école à partir de son nom ──────────────────
//  Convention : « promeducam_<slug du nom de l'établissement> ».
//  Ex. « GSBI MINHADJOUL MOUSLIM » → promeducam_minhadjoul_mouslim.
//  Les mots génériques en tête (GSBI, ÉCOLE, GROUPE SCOLAIRE, COLLÈGE…)
//  sont retirés ; accents translittérés ; tout le reste en [a-z0-9_] ;
//  tronqué à 40 caractères. Repli sur le code si le slug est vide.
if (!function_exists('slug_base_ecole')) {
    function slug_base_ecole(string $nom, string $code = ''): string {
        $s = @iconv('UTF-8', 'ASCII//TRANSLIT', $nom);
        if ($s === false || $s === null) $s = $nom;
        $s = strtolower($s);
        $s = preg_replace('/[^a-z0-9]+/', '_', $s);
        $s = trim((string) $s, '_');
        $mots = $s === '' ? [] : explode('_', $s);
        $generiques = ['gsbi','gsb','gs','cs','ecole','groupe','scolaire','complexe',
                       'institut','college','lycee','bilingue','prive','privee','public',
                       'la','le','les','l','de','des','du','d'];
        while ($mots && in_array($mots[0], $generiques, true)) array_shift($mots);
        $s = trim(implode('_', $mots), '_');
        $s = trim(substr($s, 0, 40), '_');
        if ($s === '') {
            $s = strtolower(preg_replace('/[^A-Za-z0-9]+/', '', $code) ?? '');
        }
        return $s !== '' ? $s : 'ecole';
    }
}

/** Préfixe commun des bases école (« promeducam » → promeducam_<slug>). */
if (!defined('DB_PREFIXE_ECOLE')) {
    define('DB_PREFIXE_ECOLE', 'promeducam');
}

/** @var mysqli|null $link_assoc  Connexion annuaire, ou null si absente. */
$link_assoc = null;
try {
    // mysqli_report est déjà passé en mode exception par connexion.php si
    // celui-ci a été inclus avant ; on le force ici au cas où ce fichier
    // serait inclus seul (runners bd/assoc/*).
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $link_assoc = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME_ASSOC);
    mysqli_set_charset($link_assoc, 'utf8mb4');
} catch (mysqli_sql_exception $e) {
    // Base annuaire absente / inaccessible — mode mono-école, pas d'erreur.
    $link_assoc = null;
}

/** L'annuaire association est-il disponible sur cette installation ? */
function annuaire_dispo(): bool {
    global $link_assoc;
    return $link_assoc instanceof mysqli;
}

// ── Helper interne : prépare, lie en « s », exécute (cf. connexion.php) ──
function _assoc_stmt(string $sql, array $params) {
    global $link_assoc;
    $stmt = mysqli_prepare($link_assoc, $sql);
    if ($params) {
        mysqli_stmt_bind_param($stmt, str_repeat('s', count($params)), ...$params);
    }
    mysqli_stmt_execute($stmt);
    return $stmt;
}

function assoc_all(string $sql, array $params = []): array {
    $stmt = _assoc_stmt($sql, $params);
    $res  = mysqli_stmt_get_result($stmt);
    $rows = $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];
    mysqli_stmt_close($stmt);
    return $rows;
}

function assoc_one(string $sql, array $params = []) {
    $stmt = _assoc_stmt($sql, $params);
    $res  = mysqli_stmt_get_result($stmt);
    $row  = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

function assoc_val(string $sql, array $params = []) {
    $stmt = _assoc_stmt($sql, $params);
    $res  = mysqli_stmt_get_result($stmt);
    $val  = null;
    if ($res && ($r = mysqli_fetch_row($res))) $val = $r[0];
    mysqli_stmt_close($stmt);
    return $val;
}

function assoc_exec(string $sql, array $params = []): int {
    $stmt = _assoc_stmt($sql, $params);
    $n    = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    return (int) $n;
}

function assoc_last_id(): int {
    global $link_assoc;
    return (int) mysqli_insert_id($link_assoc);
}

// ── Exécution d'un traitement DANS la base d'une autre école ─────────
//  Ouvre une connexion dédiée vers la base de l'école $id_etab, passe le
//  mysqli à $fn, referme, retourne la valeur de $fn. Utilisé par
//  l'interface association pour écrire dans une base école (affectation
//  d'un enseignant, création d'un élève à partir d'un NIU, agrégations).
function avec_ecole(int $id_etab, callable $fn) {
    $e = assoc_one("SELECT db_name FROM etablissement WHERE id=?", [$id_etab]);
    if (!$e) {
        throw new RuntimeException("École #$id_etab introuvable dans l'annuaire.");
    }
    $l = mysqli_connect(DB_HOST, DB_USER, DB_PASS, $e['db_name']);
    mysqli_set_charset($l, 'utf8mb4');
    try {
        return $fn($l);
    } finally {
        mysqli_close($l);
    }
}

// ── Helpers requête sur une connexion école arbitraire ($l = mysqli) ──
//  Utilisés dans les callbacks avec_ecole(). Paramètres liés en « s ».
function ecole_all(mysqli $l, string $sql, array $params = []): array {
    $st = mysqli_prepare($l, $sql);
    if ($params) mysqli_stmt_bind_param($st, str_repeat('s', count($params)), ...$params);
    mysqli_stmt_execute($st);
    $r = mysqli_stmt_get_result($st);
    $rows = $r ? mysqli_fetch_all($r, MYSQLI_ASSOC) : [];
    mysqli_stmt_close($st);
    return $rows;
}
function ecole_one(mysqli $l, string $sql, array $params = []): ?array {
    return ecole_all($l, $sql, $params)[0] ?? null;
}
function ecole_exec(mysqli $l, string $sql, array $params = []): int {
    $st = mysqli_prepare($l, $sql);
    if ($params) mysqli_stmt_bind_param($st, str_repeat('s', count($params)), ...$params);
    mysqli_stmt_execute($st);
    $n = mysqli_stmt_affected_rows($st);
    mysqli_stmt_close($st);
    return (int) $n;
}

// ── Chargement du schéma de référence dans une base école ───────────
//  $l : connexion mysqli AVEC la base école déjà sélectionnée (vide).
//  $seed : ['nom','nom_en','sigle','ville'] pour amorcer la ligne
//  `etablissement` locale (IDEtablissement=1) — sans elle : dashboard,
//  en-têtes PDF et page Configurations en erreur « colonne indéfinie ».
//  Retourne le nombre de tables créées. Lève une RuntimeException sur échec.
function charger_schema_ecole(mysqli $l, array $seed): int {
    $schema = @file_get_contents(__DIR__ . '/bd/assoc/schema_ref_ecole.sql');
    if ($schema === false || trim($schema) === '') {
        throw new RuntimeException('Schéma de référence introuvable (bd/assoc/schema_ref_ecole.sql).');
    }
    if (mysqli_multi_query($l, $schema)) {
        do { /* consommer tous les jeux de résultats */ } while (mysqli_next_result($l));
    }
    if (mysqli_errno($l)) {
        throw new RuntimeException('Chargement du schéma : ' . mysqli_error($l));
    }
    $nbTables = (int) mysqli_fetch_row(mysqli_query($l,
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()"))[0];
    if ($nbTables < 10) {
        throw new RuntimeException("Schéma incomplet ($nbTables tables).");
    }

    // Données de référence communes (niveaux, compétences Fr/An, disciplines
    // arabes, géographie, grades, barème APC par niveau…) — voir
    // bd/assoc/seed_ref_ecole.sql. Absence tolérée (rétro-compat).
    charger_seed_ref_ecole($l);

    $st = mysqli_prepare($l,
        "INSERT INTO etablissement (IDEtablissement, Nom_Etab_Fr, Nom_Etab_An, Initial_Etab, ville_etab)
         VALUES (1, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE Nom_Etab_Fr=VALUES(Nom_Etab_Fr), Nom_Etab_An=VALUES(Nom_Etab_An),
                                 Initial_Etab=VALUES(Initial_Etab), ville_etab=VALUES(ville_etab)");
    mysqli_stmt_bind_param($st, 'ssss', $seed['nom'], $seed['nom_en'], $seed['sigle'], $seed['ville']);
    mysqli_stmt_execute($st);
    mysqli_stmt_close($st);

    // Provisionnement « école neuve » : classes standard + année scolaire
    // active + barème de travail dérivé du gabarit. Best-effort — un échec
    // ici ne doit pas empêcher la création de l'école (rattrapable via
    // bd/assoc/reseeder_ecole.php --classes).
    try {
        provisionner_ecole_neuve($l);
    } catch (\Throwable $ex) {
        error_log('provisionner_ecole_neuve: ' . $ex->getMessage());
    }

    return $nbTables;
}

// Charge les données de référence (bd/assoc/seed_ref_ecole.sql) dans la base
// école $l (déjà sélectionnée). Idempotent (DELETE + INSERT dans le fichier).
// Retourne le nombre de lignes de référence chargées, 0 si le fichier est
// absent (ancienne installation) ou en cas d'erreur non bloquante.
function charger_seed_ref_ecole(mysqli $l): int {
    $f = __DIR__ . '/bd/assoc/seed_ref_ecole.sql';
    $sql = @file_get_contents($f);
    if ($sql === false || trim($sql) === '') return 0;
    if (mysqli_multi_query($l, $sql)) {
        do { /* consommer */ } while (mysqli_next_result($l));
    }
    if (mysqli_errno($l)) {
        // Non bloquant : l'école reste utilisable, la référence sera
        // rechargeable via bd/assoc/reseeder_ecole.php.
        error_log('charger_seed_ref_ecole: ' . mysqli_error($l));
        return 0;
    }
    $n = 0;
    foreach (['niveau', 'competence', 'groupe_competence_niveau', 'arrondissement', 'bareme_reference'] as $t) {
        $r = mysqli_query($l, "SELECT COUNT(*) FROM `$t`");
        if ($r) $n += (int) mysqli_fetch_row($r)[0];
    }
    return $n;
}

// ── Provisionnement d'une école neuve ──────────────────────────────
//  Amène une base fraîchement créée / vidée à l'état « prête à l'emploi » :
//   1. les 8 classes standard (progression M → CM1 + CLASS 1 anglophone),
//      chaînées par `classe_suivante` (passage automatique en classe sup.) ;
//   2. l'année scolaire courante, ACTIVE, avec 3 trimestres et 6 évaluations
//      (UA1-UA6) ;
//   3. le barème de travail (`discipline`) de cette année, dérivé du gabarit
//      `bareme_reference` (une ligne par classe × compétence du niveau).
//  Chaque étape est idempotente (ne fait rien si déjà présente). $l : mysqli
//  avec la base école sélectionnée. Retour : compteurs.
function provisionner_ecole_neuve(mysqli $l, ?string $val_annee = null): array {
    if ($val_annee === null) {
        // Année de la rentrée en cours (la nouvelle année commence en août).
        $y = (int) date('Y') - ((int) date('n') < 8 ? 1 : 0);
        $val_annee = $y . '/' . ($y + 1);
    }
    $va  = mysqli_real_escape_string($l, $val_annee);
    $out = ['classes' => 0, 'annee' => $val_annee, 'sequences' => 0, 'bareme' => 0];

    // 1. Classes standard (uniquement si la base n'en a aucune).
    if ((int) mysqli_fetch_row(mysqli_query($l, "SELECT COUNT(*) FROM classe"))[0] === 0) {
        $standard = [
            ['1ère Année', 'M'],   ['2ème Année', 'M'],
            ['SIL', 'I'],          ['CP', 'I'],
            ['CE1', 'II'],         ['CE2', 'II'],
            ['CM1', 'III'],        ['CLASS 1', 'LEVEL 1'],
        ];
        $st = mysqli_prepare($l, "INSERT INTO classe (DesignationClasses, Niveau) VALUES (?, ?)");
        $ids = [];
        foreach ($standard as [$des, $niv]) {
            mysqli_stmt_bind_param($st, 'ss', $des, $niv);
            mysqli_stmt_execute($st);
            $ids[] = mysqli_insert_id($l);
        }
        mysqli_stmt_close($st);
        // Progression 1ère → 2ème → SIL → CP → CE1 → CE2 → CM1 (indices 0..6).
        // CM1 (6) et CLASS 1 (7) : pas de suivante.
        for ($i = 0; $i < 6; $i++) {
            mysqli_query($l, "UPDATE classe SET classe_suivante = {$ids[$i + 1]} WHERE IDClasses = {$ids[$i]}");
        }
        $out['classes'] = count($ids);
    }

    // 2. Année scolaire active + trimestres + évaluations (si cette année absente).
    if ((int) mysqli_fetch_row(mysqli_query($l,
            "SELECT COUNT(*) FROM annee_scolaire WHERE val_annee = '$va'"))[0] === 0) {
        mysqli_query($l, "UPDATE annee_scolaire SET Etat_annee_scolaire = 0");
        mysqli_query($l, "INSERT INTO annee_scolaire (val_annee, Etat_annee_scolaire) VALUES ('$va', 1)");
        $trims = [];
        foreach (['1er Trimestre', '2eme Trimestre', '3eme Trimestre'] as $lt) {
            mysqli_query($l, "INSERT INTO trimestre (libelle_trim, id_annee) VALUES ('$lt', '$va')");
            $trims[] = mysqli_insert_id($l);
        }
        foreach ([[0,'UA1'],[0,'UA2'],[1,'UA3'],[1,'UA4'],[2,'UA5'],[2,'UA6']] as [$ti, $ls]) {
            mysqli_query($l, "INSERT INTO sequence (libelle_seq, etat, id_trim) VALUES ('$ls', 0, {$trims[$ti]})");
        }
        $out['sequences'] = 6;
    }

    // 3. Barème de travail dérivé du gabarit bareme_reference (lignes manquantes).
    $chk = mysqli_query($l, "SELECT COUNT(*) FROM information_schema.tables
                             WHERE table_schema = DATABASE() AND table_name = 'bareme_reference'");
    if ($chk && (int) mysqli_fetch_row($chk)[0] > 0) {
        mysqli_query($l,
            "INSERT INTO discipline (IDClasses, id_comp, annee_scol, orale, ecrite, pratique, savoir_etre, total_points, actif)
             SELECT c.IDClasses, b.id_comp, '$va', b.orale, b.ecrite, b.pratique, b.savoir_etre, b.total_points, b.actif
             FROM bareme_reference b
             JOIN classe c ON c.Niveau = b.code_niveau
             WHERE NOT EXISTS (
                 SELECT 1 FROM discipline d
                 WHERE d.IDClasses = c.IDClasses AND d.id_comp = b.id_comp AND d.annee_scol = '$va'
             )");
        $out['bareme'] = mysqli_affected_rows($l);
    }

    return $out;
}

// Best-effort : vide toutes les tables d'une base (restaure une base du
// pool à l'état « vide » après un chargement de schéma échoué).
function _vider_base(string $db): void {
    try {
        $l = mysqli_connect(DB_HOST, DB_USER, DB_PASS, $db);
        mysqli_set_charset($l, 'utf8mb4');
        mysqli_query($l, "SET FOREIGN_KEY_CHECKS=0");
        $res = mysqli_query($l, "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()");
        while ($r = mysqli_fetch_row($res)) {
            mysqli_query($l, "DROP TABLE IF EXISTS `" . str_replace('`', '', $r[0]) . "`");
        }
        mysqli_query($l, "SET FOREIGN_KEY_CHECKS=1");
        mysqli_close($l);
    } catch (\Throwable $e) { /* ignore */ }
}

// Remet une base du pool à l'état « libre » (réservation annulée).
function _liberer_pool(string $db): void {
    try {
        assoc_exec("UPDATE bd_pool SET etat='libre', id_etablissement=NULL, consomme_le=NULL WHERE db_name=?", [$db]);
    } catch (\Throwable $e) { /* ignore */ }
}

// ── Création d'un nouvel établissement ──────────────────────────────
//  Point unique : provisionne la base école, y charge le schéma de
//  référence (bd/assoc/schema_ref_ecole.sql), amorce la ligne
//  `etablissement` locale, inscrit l'école dans l'annuaire et cale sa
//  version de schéma sur la dernière migration connue. Utilisé par
//  l'interface association (association/etablissement_nouveau.php) ET par
//  la CLI (bd/assoc/creer_ecole.php).
//
//  Deux modes de provisionnement de la base :
//   - ECOLE_POOL_ACTIF (mutualisé cPanel, pas de droit CREATE DATABASE) :
//     consomme une base VIDE pré-créée et enregistrée dans `bd_pool`.
//   - sinon (LAN / serveur dédié) : CREATE DATABASE `promeducam_<slug du nom>`.
//
//  $in : ['code','nom','nom_en'?,'sigle'?,'ville'?,'sous_domaine'?,'par'?]
//  Retour : ['ok'=>bool, 'message'=>string, 'id'=>?int, 'db_name'=>?string]
function creer_etablissement(array $in): array {
    $code = strtoupper(trim($in['code'] ?? ''));
    $nom  = trim($in['nom'] ?? '');
    $nomEn = trim($in['nom_en'] ?? '') ?: null;
    $sigle = trim($in['sigle'] ?? '') ?: null;
    $ville = trim($in['ville'] ?? '') ?: null;
    $sous  = trim($in['sous_domaine'] ?? '') ?: null;

    if (!preg_match('/^[A-Z0-9]{2,10}$/', $code)) {
        return ['ok' => false, 'message' => 'Code invalide : 2 à 10 caractères A–Z ou 0–9.', 'id' => null, 'db_name' => null];
    }
    if ($nom === '') {
        return ['ok' => false, 'message' => 'Le nom de l\'établissement est obligatoire.', 'id' => null, 'db_name' => null];
    }
    if ($sous !== null && !preg_match('/^[a-z0-9-]{2,63}$/', $sous)) {
        return ['ok' => false, 'message' => 'Sous-domaine invalide : lettres minuscules, chiffres et tirets.', 'id' => null, 'db_name' => null];
    }
    if (!annuaire_dispo()) {
        return ['ok' => false, 'message' => 'Annuaire association absent (bd/assoc/installer.php).', 'id' => null, 'db_name' => null];
    }

    $pool_mode = defined('ECOLE_POOL_ACTIF') && ECOLE_POOL_ACTIF;

    // Détermination de la base cible
    // Doublons annuaire (code / sous-domaine) — avant toute réservation
    if (assoc_val("SELECT COUNT(*) FROM etablissement WHERE code=?", [$code])) {
        return ['ok' => false, 'message' => "Le code « $code » est déjà utilisé.", 'id' => null, 'db_name' => null];
    }
    if ($sous !== null && assoc_val("SELECT COUNT(*) FROM etablissement WHERE sous_domaine=?", [$sous])) {
        return ['ok' => false, 'message' => "Le sous-domaine « $sous » est déjà utilisé.", 'id' => null, 'db_name' => null];
    }

    // Détermination + réservation de la base cible
    if ($pool_mode) {
        $db = assoc_val("SELECT db_name FROM bd_pool WHERE etat='libre' ORDER BY db_name LIMIT 1");
        if (!$db) {
            return ['ok' => false, 'message' => 'Pool de bases épuisé : créez de nouvelles bases vides dans le cPanel puis enregistrez-les via bd/assoc/pool_enregistrer.php.', 'id' => null, 'db_name' => null];
        }
        // Réservation atomique (évite qu'une création concurrente prenne la même base).
        if (assoc_exec("UPDATE bd_pool SET etat='consomme', consomme_le=NOW() WHERE db_name=? AND etat='libre'", [$db]) !== 1) {
            return ['ok' => false, 'message' => 'La base du pool vient d\'être prise — réessayez.', 'id' => null, 'db_name' => null];
        }
    } else {
        // Convention : promeducam_<slug du nom>. Si ce nom est déjà pris
        // (annuaire ou serveur), on suffixe avec le code pour lever l'ambiguïté.
        $base = DB_PREFIXE_ECOLE . '_' . slug_base_ecole($nom, $code);
        $db   = $base;
        $srvChk = @mysqli_connect(DB_HOST, DB_USER, DB_PASS);
        $prise = function (string $n) use ($srvChk): bool {
            if (assoc_val("SELECT COUNT(*) FROM etablissement WHERE db_name=?", [$n])) return true;
            if ($srvChk) {
                $q = mysqli_query($srvChk,
                    "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='"
                    . mysqli_real_escape_string($srvChk, $n) . "'");
                if ($q && (int) mysqli_fetch_row($q)[0] > 0) return true;
            }
            return false;
        };
        if ($prise($db)) {
            $db = $base . '_' . strtolower(preg_replace('/[^A-Za-z0-9]+/', '', $code));
        }
        if ($srvChk) mysqli_close($srvChk);
    }

    if (assoc_val("SELECT COUNT(*) FROM etablissement WHERE db_name=?", [$db])) {
        if ($pool_mode) _liberer_pool($db);
        return ['ok' => false, 'message' => "La base « $db » est déjà référencée.", 'id' => null, 'db_name' => null];
    }

    $seed = ['nom' => $nom, 'nom_en' => $nomEn, 'sigle' => $sigle, 'ville' => $ville];

    try {
        if ($pool_mode) {
            // Base du pool : elle existe déjà (créée au cPanel) et DOIT être vide.
            $l = mysqli_connect(DB_HOST, DB_USER, DB_PASS, $db);
            mysqli_set_charset($l, 'utf8mb4');
            $nb = (int) mysqli_fetch_row(mysqli_query($l,
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()"))[0];
            if ($nb > 0) {
                mysqli_close($l);
                _liberer_pool($db);
                return ['ok' => false, 'message' => "La base du pool « $db » n'est pas vide — abandon. Nettoyez-la ou retirez-la du pool.", 'id' => null, 'db_name' => null];
            }
            charger_schema_ecole($l, $seed);
            mysqli_close($l);
        } else {
            // Serveur dédié / LAN : on crée la base.
            $srv = mysqli_connect(DB_HOST, DB_USER, DB_PASS);
            mysqli_set_charset($srv, 'utf8mb4');

            $existe = (int) mysqli_fetch_row(mysqli_query($srv,
                "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='"
                . mysqli_real_escape_string($srv, $db) . "'"))[0];
            if ($existe) {
                return ['ok' => false, 'message' => "La base « $db » existe déjà sur le serveur — abandon (aucun écrasement).", 'id' => null, 'db_name' => null];
            }

            mysqli_query($srv, "CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            mysqli_select_db($srv, $db);
            charger_schema_ecole($srv, $seed);
            mysqli_close($srv);
        }
    } catch (\Throwable $e) {
        // Nettoyage best-effort.
        if ($pool_mode) {
            _vider_base($db);      // restaure la base du pool à l'état « vide »
            _liberer_pool($db);    // et la remet « libre »
        } else {
            try {
                $c = mysqli_connect(DB_HOST, DB_USER, DB_PASS);
                mysqli_query($c, "DROP DATABASE IF EXISTS `$db`");
                mysqli_close($c);
            } catch (\Throwable $e2) { /* ignore */ }
        }
        return ['ok' => false, 'message' => 'Erreur lors de la création de la base : ' . $e->getMessage(), 'id' => null, 'db_name' => null];
    }

    // Version de schéma = dernière migration connue
    $vmax = 0;
    foreach (glob(__DIR__ . '/bd/migration_v*.sql') as $f) {
        if (preg_match('/migration_v(\d+)\.sql$/', $f, $m)) $vmax = max($vmax, (int) $m[1]);
    }

    // Ligne annuaire
    assoc_exec(
        "INSERT INTO etablissement (code, sous_domaine, db_name, nom, sigle, ville, actif)
         VALUES (?, ?, ?, ?, ?, ?, 1)",
        [$code, $sous, $db, $nom, $sigle, $ville]
    );
    $id = (int) assoc_val("SELECT id FROM etablissement WHERE code=?", [$code]);

    if ($pool_mode) {
        // La base est déjà réservée (etat='consomme') : on relie juste l'école.
        assoc_exec("UPDATE bd_pool SET id_etablissement=? WHERE db_name=?", [$id, $db]);
    }

    assoc_exec(
        "INSERT INTO schema_version_etab (id_etablissement, version) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE version=GREATEST(version, VALUES(version))",
        [$id, $vmax]
    );

    return [
        'ok'      => true,
        'message' => "Établissement « $nom » créé (base $db, schéma v$vmax). "
                   . "Créez maintenant un compte DIRECTEUR via Personnel → Affecter.",
        'id'      => $id,
        'db_name' => $db,
    ];
}

// ── Bases MySQL qu'on ne doit JAMAIS supprimer ─────────────────────
function _bases_protegees(): array {
    $p = ['information_schema', 'mysql', 'performance_schema', 'sys', 'phpmyadmin'];
    if (defined('DB_NAME'))       $p[] = DB_NAME;
    if (defined('DB_NAME_ASSOC')) $p[] = DB_NAME_ASSOC;
    return array_map('strtolower', $p);
}

// ── Impact d'une suppression d'établissement (pour l'écran de confirmation) ──
//  Retourne l'état de la base école + les enregistrements annuaire qui la
//  référencent. Aucune écriture. Toutes les valeurs sont « best-effort ».
function etablissement_impact_suppression(int $id): array {
    $e = assoc_one("SELECT * FROM etablissement WHERE id=?", [$id]);
    if (!$e) return ['ok' => false, 'message' => "Établissement introuvable."];

    $db = $e['db_name'];
    $imp = [
        'ok'                => true,
        'etab'              => $e,
        'db_name'           => $db,
        'db_protegee'       => in_array(strtolower($db), _bases_protegees(), true),
        'db_existe'         => false,
        'db_tables'         => 0,
        'db_mo'             => 0.0,
        'pool'              => false,
        'niu_courant'       => (int) assoc_val("SELECT COUNT(*) FROM eleve_niu WHERE id_etab_courant=?", [$id]),
        'niu_origine'       => (int) assoc_val("SELECT COUNT(*) FROM eleve_niu WHERE id_etab_origine=? AND (id_etab_courant IS NULL OR id_etab_courant<>?)", [$id, $id]),
        'affectations'      => (int) assoc_val("SELECT COUNT(*) FROM personnel_affectation WHERE id_etablissement=?", [$id]),
        'acces_membres'     => (int) assoc_val("SELECT COUNT(*) FROM membre_acces WHERE id_etablissement=?", [$id]),
    ];

    try {
        if (assoc_val("SELECT COUNT(*) FROM bd_pool WHERE db_name=?", [$db])) $imp['pool'] = true;
    } catch (\Throwable $ex) { /* table bd_pool absente en LAN : ignorer */ }

    try {
        $srv = mysqli_connect(DB_HOST, DB_USER, DB_PASS);
        mysqli_set_charset($srv, 'utf8mb4');
        $q = mysqli_query($srv,
            "SELECT COUNT(*) t, COALESCE(ROUND(SUM(data_length+index_length)/1024/1024,1),0) mo
             FROM information_schema.tables
             WHERE table_schema='" . mysqli_real_escape_string($srv, $db) . "'");
        if ($q && ($r = mysqli_fetch_assoc($q))) {
            $imp['db_tables'] = (int) $r['t'];
            $imp['db_mo']     = (float) $r['mo'];
            $imp['db_existe'] = $imp['db_tables'] > 0
                || (bool) mysqli_fetch_row(mysqli_query($srv,
                    "SELECT COUNT(*) FROM information_schema.schemata
                     WHERE schema_name='" . mysqli_real_escape_string($srv, $db) . "'"))[0];
        }
        mysqli_close($srv);
    } catch (\Throwable $ex) { /* serveur injoignable : on garde les valeurs par défaut */ }

    return $imp;
}

// ── Suppression définitive d'un établissement ───────────────────────
//  1. l'établissement DOIT être inactif (garde-fou : le désactiver d'abord) ;
//  2. si des NIU ont cette école pour « école courante », il faut cocher
//     explicitement $opts['supprimer_niu'] (identités élèves détruites) ;
//  3. détache les NIU d'origine des élèves partis ailleurs (ils gardent leur
//     NIU), supprime les NIU rattachés ici, purge les affectations de
//     personnel, neutralise le lien du journal ;
//  4. supprime la ligne annuaire (CASCADE : membre_acces, schema_version_etab) ;
//  5. supprime la BASE MySQL de l'école — ou, si elle vient d'un pool, la
//     vide et la remet « libre ».
//
//  $opts : ['supprimer_niu' => bool]
//  Retour : ['ok' => bool, 'message' => string, 'db_name' => ?string]
function supprimer_etablissement(int $id, array $opts = []): array {
    if (!annuaire_dispo()) {
        return ['ok' => false, 'message' => "Annuaire association absent.", 'db_name' => null];
    }
    $e = assoc_one("SELECT * FROM etablissement WHERE id=?", [$id]);
    if (!$e) {
        return ['ok' => false, 'message' => "Établissement introuvable.", 'db_name' => null];
    }
    if ((int) $e['actif'] === 1) {
        return ['ok' => false, 'message' => "Désactivez d'abord l'établissement (Modifier → décocher « actif ») avant de le supprimer.", 'db_name' => null];
    }

    $db = $e['db_name'];
    if (in_array(strtolower($db), _bases_protegees(), true)) {
        return ['ok' => false, 'message' => "La base « $db » est protégée (base principale ou annuaire) — suppression refusée.", 'db_name' => null];
    }

    $niu_courant = (int) assoc_val("SELECT COUNT(*) FROM eleve_niu WHERE id_etab_courant=?", [$id]);
    if ($niu_courant > 0 && empty($opts['supprimer_niu'])) {
        return ['ok' => false, 'db_name' => null, 'message' =>
            "$niu_courant NIU (identités élèves) ont cette école pour école courante. "
            . "Cochez « supprimer aussi les NIU » pour confirmer la destruction de ces identités."];
    }

    // ── Sauvegarde de sécurité COMPLÈTE avant toute destruction ──────
    //  Une suppression d'école est irréversible : on archive d'abord la
    //  base (SQL) ET les fichiers uploadés (logos/signatures/dossiers)
    //  dans bd/sauvegardes/avant_suppression_<db>_<horo>.zip. Si la base
    //  n'existe déjà plus (fantôme d'annuaire), rien à sauvegarder.
    require_once __DIR__ . '/bd/lib/ecole_maintenance.php';
    $backup_securite = null;
    if (ecole_base_etat($db)['existe']) {
        try {
            $dir = __DIR__ . '/bd/sauvegardes';
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            $cand = $dir . '/avant_suppression_' . preg_replace('/[^A-Za-z0-9_]/', '', $db)
                  . '_' . date('Ymd_His') . '.zip';
            $rb = ecole_export_zip($id, $cand);
            $backup_securite = ($rb['ok'] && is_file($cand))
                ? $cand
                : ecole_maint_backup_securite($db, 'avant_suppression');
        } catch (\Throwable $ex) {
            return ['ok' => false, 'db_name' => null,
                    'message' => "Sauvegarde de sécurité impossible avant suppression ({$ex->getMessage()}) — suppression annulée."];
        }
    }

    $pool = false;
    try { $pool = (bool) assoc_val("SELECT COUNT(*) FROM bd_pool WHERE db_name=?", [$db]); }
    catch (\Throwable $ex) { /* pas de pool en LAN */ }

    try {
        // 1. NIU : les élèves désormais dans une autre école gardent leur NIU
        //    (on détache juste l'origine) ; ceux rattachés ici sont supprimés.
        assoc_exec("UPDATE eleve_niu SET id_etab_origine=NULL
                    WHERE id_etab_origine=? AND (id_etab_courant IS NULL OR id_etab_courant<>?)", [$id, $id]);
        assoc_exec("DELETE FROM eleve_niu WHERE id_etab_courant=? OR id_etab_origine=?", [$id, $id]);

        // 2. Affectations de personnel (le personnel central lui-même est conservé).
        assoc_exec("DELETE FROM personnel_affectation WHERE id_etablissement=?", [$id]);

        // 3. Journal : conservé pour la traçabilité, lien neutralisé.
        assoc_exec("UPDATE journal_action SET id_etablissement=NULL WHERE id_etablissement=?", [$id]);

        // 4. Ligne annuaire — CASCADE sur membre_acces et schema_version_etab,
        //    SET NULL sur bd_pool.
        assoc_exec("DELETE FROM etablissement WHERE id=?", [$id]);

        // 5. Base MySQL de l'école.
        if ($pool) {
            _vider_base($db);
            _liberer_pool($db);
        } else {
            $srv = mysqli_connect(DB_HOST, DB_USER, DB_PASS);
            mysqli_set_charset($srv, 'utf8mb4');
            mysqli_query($srv, "DROP DATABASE IF EXISTS `" . str_replace('`', '', $db) . "`");
            mysqli_close($srv);
        }

        // 6. Dossiers de fichiers uploadés de l'école (déjà inclus dans la
        //    sauvegarde de sécurité $backup_securite).
        $slug = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $e['code']));
        if ($slug !== '' && function_exists('ecole_maint_rmdir_recursif')) {
            $up = __DIR__ . '/assets/uploads';
            ecole_maint_rmdir_recursif("$up/etab/$slug");
            ecole_maint_rmdir_recursif("$up/dossiers_eleves/etab/$slug");
        }
    } catch (\Throwable $ex) {
        return ['ok' => false, 'db_name' => $db,
                'message' => "Erreur pendant la suppression : " . $ex->getMessage()
                           . " (l'annuaire a pu être partiellement modifié)."];
    }

    return [
        'ok'      => true,
        'db_name' => $db,
        'backup'  => $backup_securite,
        'message' => "Établissement « {$e['nom']} » supprimé. "
                   . ($pool ? "Base « $db » vidée et remise au pool." : "Base « $db » supprimée.")
                   . ($backup_securite ? " Sauvegarde de sécurité : " . basename($backup_securite) . "." : ""),
    ];
}

// =====================================================================
//  GESTION DES MEMBRES ET DES ACCÈS (interface association)
//  Utilisé par association/membres/*.php. Toutes ces fonctions
//  supposent annuaire_dispo() ; l'appelant garde l'accès superadmin.
// =====================================================================

/** Liste des membres + résumé de leurs droits. */
function assoc_membres_liste(): array {
    $cols = ['id', 'login', 'nom', 'prenom', 'email', 'actif', 'cree_le'];
    $prop = assoc_proprietaire_dispo();
    if (assoc_2fa_disponible()) $cols[] = 'totp_actif';
    if ($prop)                  $cols[] = 'proprietaire';
    $ordre = ($prop ? 'proprietaire DESC, ' : '') . 'actif DESC, login';
    $membres = assoc_all("SELECT " . implode(', ', $cols) . " FROM membre ORDER BY $ordre");
    foreach ($membres as &$m) {
        $m['proprietaire'] = !empty($m['proprietaire']);
        $m['superadmin'] = $m['proprietaire'] || (bool) assoc_val(
            "SELECT COUNT(*) FROM membre_acces WHERE id_membre=? AND actif=1 AND id_etablissement IS NULL AND plein_acces=1",
            [$m['id']]
        );
        $m['nb_ecoles'] = (int) assoc_val(
            "SELECT COUNT(*) FROM membre_acces WHERE id_membre=? AND actif=1 AND id_etablissement IS NOT NULL",
            [$m['id']]
        );
        $m['global_lecture'] = (bool) assoc_val(
            "SELECT COUNT(*) FROM membre_acces WHERE id_membre=? AND actif=1 AND id_etablissement IS NULL AND plein_acces=0",
            [$m['id']]
        );
    }
    unset($m);
    return $membres;
}

/** Un membre + ses lignes d'accès (avec le nom de l'école). */
function assoc_membre_detail(int $id): ?array {
    $m = assoc_one("SELECT * FROM membre WHERE id=?", [$id]);
    if (!$m) return null;
    $m['proprietaire'] = !empty($m['proprietaire']);
    $m['superadmin_effectif'] = $m['proprietaire'] || (bool) assoc_val(
        "SELECT COUNT(*) FROM membre_acces WHERE id_membre=? AND actif=1 AND id_etablissement IS NULL AND plein_acces=1",
        [$id]
    );
    $m['acces'] = assoc_all(
        "SELECT a.id, a.id_etablissement, a.plein_acces, a.actif, e.code, e.nom
         FROM membre_acces a
         LEFT JOIN etablissement e ON e.id = a.id_etablissement
         WHERE a.id_membre=?
         ORDER BY (a.id_etablissement IS NOT NULL), e.nom",
        [$id]
    );
    return $m;
}

/** Crée un membre. $in : login, nom, prenom?, email?, pwd. */
function assoc_membre_creer(array $in): array {
    $login = strtolower(trim($in['login'] ?? ''));
    $nom   = trim($in['nom'] ?? '');
    $pwd   = (string) ($in['pwd'] ?? '');
    if (!preg_match('/^[a-z0-9._-]{3,50}$/', $login)) {
        return ['ok' => false, 'message' => "Login invalide : 3 à 50 caractères (a-z, 0-9, . _ -)."];
    }
    if ($nom === '')            return ['ok' => false, 'message' => "Le nom est obligatoire."];
    if (strlen($pwd) < 8)       return ['ok' => false, 'message' => "Mot de passe : 8 caractères minimum."];
    if (assoc_val("SELECT COUNT(*) FROM membre WHERE login=?", [$login])) {
        return ['ok' => false, 'message' => "Le login « $login » est déjà pris."];
    }
    assoc_exec(
        "INSERT INTO membre (login, pwd_hash, nom, prenom, email, actif)
         VALUES (?, ?, ?, ?, ?, 1)",
        [$login, password_hash($pwd, PASSWORD_DEFAULT), $nom,
         trim($in['prenom'] ?? '') ?: null, trim($in['email'] ?? '') ?: null]
    );
    return ['ok' => true, 'id' => assoc_last_id(), 'message' => "Membre « $login » créé."];
}

/** Met à jour l'identité d'un membre (pas le mot de passe). */
function assoc_membre_maj(int $id, array $in): array {
    $m = assoc_one("SELECT * FROM membre WHERE id=?", [$id]);
    if (!$m) return ['ok' => false, 'message' => "Membre introuvable."];
    $nom = trim($in['nom'] ?? '');
    if ($nom === '') return ['ok' => false, 'message' => "Le nom est obligatoire."];
    assoc_exec(
        "UPDATE membre SET nom=?, prenom=?, email=?, actif=? WHERE id=?",
        [$nom, trim($in['prenom'] ?? '') ?: null, trim($in['email'] ?? '') ?: null,
         !empty($in['actif']) ? 1 : 0, $id]
    );
    return ['ok' => true, 'message' => "Membre mis à jour."];
}

/** Redéfinit le mot de passe d'un membre. */
function assoc_membre_mot_de_passe(int $id, string $pwd): array {
    if (!assoc_val("SELECT COUNT(*) FROM membre WHERE id=?", [$id])) {
        return ['ok' => false, 'message' => "Membre introuvable."];
    }
    if (strlen($pwd) < 8) return ['ok' => false, 'message' => "Mot de passe : 8 caractères minimum."];
    assoc_exec("UPDATE membre SET pwd_hash=? WHERE id=?", [password_hash($pwd, PASSWORD_DEFAULT), $id]);
    return ['ok' => true, 'message' => "Mot de passe réinitialisé."];
}

// Définit (upsert) une ligne d'accès.
//  $portee : 'global' (toutes les écoles) ou un id d'établissement.
//  $niveau : 'aucun' (retire l'accès), 'lecture' (visite lecture seule) ou
//            'ecriture' (plein_acces=1). 'global'+'ecriture' = superadmin.
//  $par_proprietaire : true si l'opération est faite par le propriétaire de
//            l'association. Requis pour toucher le TIER SUPERADMIN (accorder
//            OU retirer l'accès global en écriture) — un superadmin « simple »
//            ne peut gérer que les accès par école et l'accès global lecture.
function assoc_acces_definir(int $id_membre, string $portee, string $niveau, bool $par_proprietaire = false): array {
    if (!assoc_val("SELECT COUNT(*) FROM membre WHERE id=?", [$id_membre])) {
        return ['ok' => false, 'message' => "Membre introuvable."];
    }
    $id_etab = ($portee === 'global') ? null : (int) $portee;
    if ($id_etab !== null && !assoc_val("SELECT COUNT(*) FROM etablissement WHERE id=?", [$id_etab])) {
        return ['ok' => false, 'message' => "Établissement introuvable."];
    }
    if (!in_array($niveau, ['aucun', 'lecture', 'ecriture'], true)) {
        return ['ok' => false, 'message' => "Niveau d'accès invalide."];
    }

    // Garde du tier superadmin.
    if ($id_etab === null && !$par_proprietaire) {
        $deja_superadmin = (bool) assoc_val(
            "SELECT COUNT(*) FROM membre_acces
             WHERE id_membre=? AND actif=1 AND id_etablissement IS NULL AND plein_acces=1", [$id_membre]);
        if ($niveau === 'ecriture' || $deja_superadmin) {
            return ['ok' => false, 'message' =>
                "Seul le propriétaire de l'association peut accorder ou retirer le niveau superadmin."];
        }
    }

    $where = $id_etab === null ? "id_etablissement IS NULL" : "id_etablissement=" . (int) $id_etab;
    $ligne = assoc_one("SELECT id FROM membre_acces WHERE id_membre=? AND $where", [$id_membre]);

    if ($niveau === 'aucun') {
        if ($ligne) assoc_exec("DELETE FROM membre_acces WHERE id=?", [$ligne['id']]);
        return ['ok' => true, 'message' => "Accès retiré."];
    }
    $plein = $niveau === 'ecriture' ? 1 : 0;
    if ($ligne) {
        assoc_exec("UPDATE membre_acces SET plein_acces=?, actif=1 WHERE id=?", [$plein, $ligne['id']]);
    } else {
        assoc_exec(
            "INSERT INTO membre_acces (id_membre, id_etablissement, plein_acces, actif) VALUES (?, ?, ?, 1)",
            [$id_membre, $id_etab, $plein]
        );
    }
    return ['ok' => true, 'message' => "Accès enregistré."];
}

// =====================================================================
//  JOURNAL D'AUDIT (association/journal.php)
// =====================================================================

/** Valeurs d'« action » réellement présentes dans le journal (pour le filtre). */
function assoc_journal_actions(): array {
    return array_column(
        assoc_all("SELECT DISTINCT action FROM journal_action ORDER BY action"), 'action'
    );
}

// Lignes du journal filtrées + total (pour la pagination).
//  $f : ['membre'=>?int, 'etab'=>?int, 'action'=>?string, 'depuis'=>?date, 'jusqua'=>?date]
function assoc_journal(array $f, int $page = 1, int $par_page = 50): array {
    $w = []; $p = [];
    if (!empty($f['membre'])) { $w[] = "j.id_membre=?";        $p[] = (int) $f['membre']; }
    if (!empty($f['etab']))   { $w[] = "j.id_etablissement=?"; $p[] = (int) $f['etab']; }
    if (!empty($f['action'])) { $w[] = "j.action=?";           $p[] = $f['action']; }
    if (!empty($f['depuis'])) { $w[] = "j.date >= ?";          $p[] = $f['depuis'] . ' 00:00:00'; }
    if (!empty($f['jusqua'])) { $w[] = "j.date <= ?";          $p[] = $f['jusqua'] . ' 23:59:59'; }
    $sql_w = $w ? ('WHERE ' . implode(' AND ', $w)) : '';

    $total = (int) assoc_val("SELECT COUNT(*) FROM journal_action j $sql_w", $p);
    $page  = max(1, $page);
    $off   = ($page - 1) * $par_page;

    $lignes = assoc_all(
        "SELECT j.id, j.date, j.action, j.cible, j.ip,
                m.login AS membre_login, m.nom AS membre_nom, m.prenom AS membre_prenom,
                e.code AS etab_code, e.nom AS etab_nom
         FROM journal_action j
         LEFT JOIN membre m       ON m.id = j.id_membre
         LEFT JOIN etablissement e ON e.id = j.id_etablissement
         $sql_w
         ORDER BY j.date DESC, j.id DESC
         LIMIT $par_page OFFSET $off",
        $p
    );
    return ['lignes' => $lignes, 'total' => $total, 'page' => $page, 'par_page' => $par_page,
            'pages' => max(1, (int) ceil($total / $par_page))];
}

/** Purge les entrées de journal plus vieilles que $mois mois. */
function assoc_journal_purger(int $mois = 12): int {
    return assoc_exec("DELETE FROM journal_action WHERE date < (NOW() - INTERVAL ? MONTH)", [$mois]);
}

// =====================================================================
//  COCKPIT ASSOCIATION (association/dashboard.php)
//  Santé + effectifs consolidés de toutes les écoles. Best-effort :
//  une base injoignable n'interrompt pas la collecte.
// =====================================================================

/** Dernière version de migration présente sur le disque (bd/migration_v*.sql). */
function assoc_migration_max(): int {
    $v = 0;
    foreach (glob(__DIR__ . '/bd/migration_v*.sql') ?: [] as $f) {
        if (preg_match('/migration_v(\d+)\.sql$/', $f, $m)) $v = max($v, (int) $m[1]);
    }
    return $v;
}

/** Date (timestamp) de la sauvegarde la plus récente trouvée pour une base. */
function assoc_derniere_sauvegarde(string $db_name): ?int {
    $racine = __DIR__ . '/bd/sauvegardes';
    if (!is_dir($racine)) return null;
    $plus_recent = null;
    foreach (glob($racine . '/*/*' . $db_name . '*') ?: [] as $f) {
        $t = @filemtime($f);
        if ($t && ($plus_recent === null || $t > $plus_recent)) $plus_recent = $t;
    }
    return $plus_recent;
}

function assoc_cockpit(): array {
    $vmax  = assoc_migration_max();
    $ecoles = assoc_all("SELECT * FROM etablissement ORDER BY actif DESC, nom");

    $srv = null;
    try { $srv = mysqli_connect(DB_HOST, DB_USER, DB_PASS); mysqli_set_charset($srv, 'utf8mb4'); }
    catch (\Throwable $e) { $srv = null; }

    $tot = ['ecoles' => 0, 'ecoles_actives' => 0, 'eleves' => 0, 'g' => 0, 'f' => 0,
            'classes' => 0, 'enseignants' => 0, 'alertes' => 0];
    $lignes = [];

    foreach ($ecoles as $e) {
        $tot['ecoles']++;
        if ($e['actif']) $tot['ecoles_actives']++;

        $row = [
            'etab' => $e, 'joignable' => false, 'version' => null, 'version_ok' => false,
            'annee' => null, 'annee_active' => false, 'eleves' => 0, 'g' => 0, 'f' => 0,
            'classes' => 0, 'enseignants' => 0, 'directeur' => false,
            'db_mo' => 0.0, 'sauvegarde' => assoc_derniere_sauvegarde($e['db_name']),
            'alertes' => [],
        ];

        $row['version'] = (int) (assoc_val(
            "SELECT version FROM schema_version_etab WHERE id_etablissement=?", [$e['id']]) ?? 0);
        $row['version_ok'] = $row['version'] >= $vmax;

        $row['directeur'] = (bool) assoc_val(
            "SELECT COUNT(*) FROM personnel_affectation
             WHERE id_etablissement=? AND actif=1 AND fonction IN ('DIRECTEUR','FONDATEUR')", [$e['id']]);

        if ($srv) {
            try {
                $q = mysqli_query($srv, "SELECT COALESCE(ROUND(SUM(data_length+index_length)/1024/1024,1),0) AS mo
                                          FROM information_schema.tables
                                          WHERE table_schema='" . mysqli_real_escape_string($srv, $e['db_name']) . "'");
                if ($q && ($r = mysqli_fetch_assoc($q))) $row['db_mo'] = (float) $r['mo'];
            } catch (\Throwable $ex) { /* ignore */ }
        }

        try {
            $d = avec_ecole((int) $e['id'], function (mysqli $l) {
                $active = ecole_one($l, "SELECT val_annee FROM annee_scolaire WHERE Etat_annee_scolaire=1 LIMIT 1");
                $annee  = $active ?: ecole_one($l, "SELECT val_annee FROM annee_scolaire ORDER BY val_annee DESC LIMIT 1");
                $va = $annee['val_annee'] ?? null;
                $eff = $va ? ecole_one($l,
                    "SELECT COUNT(*) AS total,
                            SUM(LOWER(el.Sexe_elv) LIKE 'm%') AS g,
                            SUM(LOWER(el.Sexe_elv) LIKE 'f%') AS f
                     FROM inscrire i JOIN eleve el ON el.id_eleve=i.id_eleve
                     WHERE i.val_annee=? AND el.statut='actif'", [$va]) : null;
                return [
                    'annee' => $va, 'annee_active' => !empty($active),
                    'eleves' => (int) ($eff['total'] ?? 0),
                    'g' => (int) ($eff['g'] ?? 0), 'f' => (int) ($eff['f'] ?? 0),
                    'classes' => count(ecole_all($l, "SELECT IDClasses FROM classe")),
                    'enseignants' => count(ecole_all($l,
                        "SELECT matricule_ens FROM enseignant WHERE statut_ens='actif' OR statut_ens IS NULL OR statut_ens=''")),
                ];
            });
            $row = array_merge($row, $d, ['joignable' => true]);
            $tot['eleves'] += $d['eleves']; $tot['g'] += $d['g']; $tot['f'] += $d['f'];
            $tot['classes'] += $d['classes']; $tot['enseignants'] += $d['enseignants'];
        } catch (\Throwable $ex) {
            $row['joignable'] = false;
        }

        // ── Alertes ──
        if ($e['actif'] && !$row['joignable'])       $row['alertes'][] = "base injoignable";
        if ($e['actif'] && $row['joignable'] && !$row['annee_active']) $row['alertes'][] = "aucune année active";
        if ($e['actif'] && !$row['version_ok'])       $row['alertes'][] = "schéma v{$row['version']} < v$vmax";
        if ($e['actif'] && !$row['directeur'])        $row['alertes'][] = "aucun directeur affecté";
        if ($e['actif'] && $row['sauvegarde'] === null) $row['alertes'][] = "jamais sauvegardée";
        elseif ($e['actif'] && $row['sauvegarde'] < time() - 172800) $row['alertes'][] = "sauvegarde > 48 h";

        $tot['alertes'] += count($row['alertes']);
        $lignes[] = $row;
    }
    if ($srv) mysqli_close($srv);

    return ['total' => $tot, 'ecoles' => $lignes, 'migration_max' => $vmax];
}

// =====================================================================
//  REGISTRE NIU — parcours de vie de l'élève au niveau association
//  (association/niu/voir.php). Le NIU est stable ; seule l'école
//  courante et le statut évoluent, chaque changement laissant un
//  mouvement dans eleve_niu_mouvement.
// =====================================================================

/** Un NIU : identité + écoles + historique des mouvements. */
function assoc_niu_detail(string $niu): ?array {
    $n = assoc_one(
        "SELECT en.*, o.code AS origine_code, o.nom AS origine_nom,
                c.code AS courant_code, c.nom AS courant_nom
         FROM eleve_niu en
         LEFT JOIN etablissement o ON o.id = en.id_etab_origine
         LEFT JOIN etablissement c ON c.id = en.id_etab_courant
         WHERE en.niu=?", [$niu]
    );
    if (!$n) return null;
    $n['mouvements'] = assoc_all(
        "SELECT mv.*, s.code AS source_code, s.nom AS source_nom,
                t.code AS cible_code, t.nom AS cible_nom
         FROM eleve_niu_mouvement mv
         LEFT JOIN etablissement s ON s.id = mv.id_etab_source
         LEFT JOIN etablissement t ON t.id = mv.id_etab_cible
         WHERE mv.niu=? ORDER BY mv.date DESC, mv.id DESC", [$niu]
    );
    return $n;
}

// Change l'école courante d'un élève (transfert d'un établissement à un autre).
//  $type : 'transfert' (élève actif qui change d'école) ou 'reintegration'
//          (élève 'sorti' qui revient). Met à jour id_etab_courant + statut='actif'
//          et enregistre le mouvement (source = ancienne école courante).
function assoc_niu_transferer(string $niu, int $id_etab_cible, ?string $motif, string $par, string $type = 'transfert'): array {
    $n = assoc_one("SELECT id_etab_courant, statut FROM eleve_niu WHERE niu=?", [$niu]);
    if (!$n) return ['ok' => false, 'message' => "NIU introuvable."];
    if (!assoc_val("SELECT COUNT(*) FROM etablissement WHERE id=?", [$id_etab_cible])) {
        return ['ok' => false, 'message' => "Établissement cible introuvable."];
    }
    if ((int) $n['id_etab_courant'] === $id_etab_cible && $n['statut'] === 'actif') {
        return ['ok' => false, 'message' => "L'élève est déjà rattaché à cet établissement."];
    }
    if (!in_array($type, ['transfert', 'reintegration'], true)) $type = 'transfert';
    $source = $n['id_etab_courant'] ? (int) $n['id_etab_courant'] : null;

    assoc_exec("UPDATE eleve_niu SET id_etab_courant=?, statut='actif' WHERE niu=?", [$id_etab_cible, $niu]);
    assoc_exec(
        "INSERT INTO eleve_niu_mouvement (niu, id_etab_source, id_etab_cible, type, motif, par)
         VALUES (?, ?, ?, ?, ?, ?)",
        [$niu, $source, $id_etab_cible, $type, $motif ?: null, $par]
    );
    return ['ok' => true, 'message' => "Élève rattaché au nouvel établissement (" . $type . ")."];
}

/** Marque un élève « sorti » du réseau (déscolarisé / parti). */
function assoc_niu_sortie(string $niu, ?string $motif, string $par): array {
    $n = assoc_one("SELECT id_etab_courant, statut FROM eleve_niu WHERE niu=?", [$niu]);
    if (!$n) return ['ok' => false, 'message' => "NIU introuvable."];
    if ($n['statut'] === 'sorti') return ['ok' => false, 'message' => "L'élève est déjà marqué « sorti »."];
    assoc_exec("UPDATE eleve_niu SET statut='sorti' WHERE niu=?", [$niu]);
    assoc_exec(
        "INSERT INTO eleve_niu_mouvement (niu, id_etab_source, type, motif, par)
         VALUES (?, ?, 'sortie', ?, ?)",
        [$niu, $n['id_etab_courant'] ? (int) $n['id_etab_courant'] : null, $motif ?: null, $par]
    );
    return ['ok' => true, 'message' => "Élève marqué « sorti » du réseau."];
}

/** Fusionne deux NIU en doublon : $garde absorbe $absorbe (mouvements repointés, $absorbe supprimé). */
function assoc_niu_fusionner(string $garde, string $absorbe, string $par): array {
    if ($garde === $absorbe) return ['ok' => false, 'message' => "Sélectionnez deux NIU différents."];
    $a = assoc_one("SELECT * FROM eleve_niu WHERE niu=?", [$garde]);
    $b = assoc_one("SELECT * FROM eleve_niu WHERE niu=?", [$absorbe]);
    if (!$a || !$b) return ['ok' => false, 'message' => "NIU introuvable."];
    try {
        assoc_exec("UPDATE eleve_niu_mouvement SET niu=? WHERE niu=?", [$garde, $absorbe]);
        assoc_exec(
            "INSERT INTO eleve_niu_mouvement (niu, type, motif, par)
             VALUES (?, 'transfert', ?, ?)",
            [$garde, "fusion depuis $absorbe", $par]
        );
        assoc_exec("DELETE FROM eleve_niu WHERE niu=?", [$absorbe]);
    } catch (\Throwable $ex) {
        return ['ok' => false, 'message' => "Échec de la fusion : " . $ex->getMessage()];
    }
    return ['ok' => true, 'message' => "NIU $absorbe fusionné dans $garde."];
}

// =====================================================================
//  DÉMARRAGE D'UNE ÉCOLE — checklist « opérationnelle »
//  (association/etablissement_demarrage.php + fiche + cockpit)
// =====================================================================

/**
 * Liste d'items [fait, label, aide, lien, lien_txt] indiquant si une école
 * est prête à fonctionner. Best-effort : une base injoignable → items école
 * marqués « inconnu » (fait=null).
 */
function etablissement_checklist(int $id): array {
    $e = assoc_one("SELECT * FROM etablissement WHERE id=?", [$id]);
    if (!$e) return [];

    $vmax  = assoc_migration_max();
    $ver   = (int) (assoc_val("SELECT version FROM schema_version_etab WHERE id_etablissement=?", [$id]) ?? 0);

    require_once __DIR__ . '/bd/lib/ecole_maintenance.php';
    $etat  = ecole_base_etat($e['db_name']);

    $fondateur = (bool) assoc_val(
        "SELECT COUNT(*) FROM personnel_affectation WHERE id_etablissement=? AND actif=1 AND fonction='FONDATEUR'", [$id]);
    $directeur = (bool) assoc_val(
        "SELECT COUNT(*) FROM personnel_affectation WHERE id_etablissement=? AND actif=1 AND fonction='DIRECTEUR'", [$id]);

    // Données lues DANS la base école (si joignable).
    $annee_active = null; $a_classes = null; $a_logo = null;
    if ($etat['existe'] && $etat['tables'] > 5) {
        try {
            $d = avec_ecole($id, function (mysqli $l) {
                return [
                    'annee'   => (bool) ecole_one($l, "SELECT 1 FROM annee_scolaire WHERE Etat_annee_scolaire=1 LIMIT 1"),
                    'classes' => count(ecole_all($l, "SELECT IDClasses FROM classe")),
                    'logo'    => (bool) ecole_one($l, "SELECT 1 FROM etablissement WHERE COALESCE(logo,'')<>'' LIMIT 1"),
                ];
            });
            $annee_active = $d['annee']; $a_classes = $d['classes'] > 0; $a_logo = $d['logo'];
        } catch (\Throwable $ex) { /* injoignable : items restent null */ }
    }

    $A = APP_URL;
    return [
        [
            'fait' => $etat['existe'] && $etat['tables'] > 10,
            'label' => 'Base de données créée',
            'aide' => $etat['existe'] ? "{$etat['tables']} tables" : "base absente sur le serveur",
            'lien' => $etat['existe'] ? null : "$A/association/etablissement.php?id=$id",
            'lien_txt' => 'Créer la base',
        ],
        [
            'fait' => $ver >= $vmax,
            'label' => 'Schéma à jour',
            'aide' => "v$ver" . ($ver >= $vmax ? '' : " → v$vmax"),
            'lien' => $ver >= $vmax ? null : "$A/association/migrations.php",
            'lien_txt' => 'Migrations',
        ],
        [
            'fait' => $fondateur,
            'label' => 'Fondateur affecté',
            'aide' => $fondateur ? '' : 'aucun',
            'lien' => $fondateur ? null : "$A/association/personnel/affecter.php",
            'lien_txt' => 'Affecter',
        ],
        [
            'fait' => $directeur,
            'label' => 'Directeur affecté',
            'aide' => $directeur ? '' : 'aucun',
            'lien' => $directeur ? null : "$A/association/personnel/affecter.php",
            'lien_txt' => 'Affecter',
        ],
        [
            'fait' => $annee_active,
            'label' => 'Année scolaire ouverte',
            'aide' => $annee_active === null ? 'base injoignable' : ($annee_active ? '' : 'aucune année active'),
            'lien' => null, 'lien_txt' => '',
        ],
        [
            'fait' => $a_classes,
            'label' => 'Classes créées',
            'aide' => $a_classes === null ? 'base injoignable' : ($a_classes ? '' : 'aucune classe'),
            'lien' => null, 'lien_txt' => '',
        ],
        [
            'fait' => $a_logo,
            'label' => 'Logo de l\'établissement',
            'aide' => $a_logo === null ? 'base injoignable' : ($a_logo ? '' : 'se règle dans l\'école (Configurations)'),
            'lien' => null, 'lien_txt' => '',
        ],
    ];
}

// =====================================================================
//  MIGRATIONS DE SCHÉMA DEPUIS L'INTERFACE (association/migrations.php)
//  Même logique que bd/assoc/migrer_toutes_ecoles.php, mais par école et
//  avec une sauvegarde de sécurité AVANT d'appliquer.
// =====================================================================

/** Migrations disponibles sur le disque : [version => chemin], triées. */
function assoc_migrations_disponibles(): array {
    $d = [];
    foreach (glob(__DIR__ . '/bd/migration_v*.sql') ?: [] as $f) {
        if (preg_match('/migration_v(\d+)\.sql$/', $f, $m)) $d[(int) $m[1]] = $f;
    }
    ksort($d);
    return $d;
}

/** État des migrations par école : version courante + versions en retard. */
function assoc_migrations_etat(): array {
    $dispo = assoc_migrations_disponibles();
    $vmax  = $dispo ? max(array_keys($dispo)) : 0;
    $out = [];
    foreach (assoc_all("SELECT id, code, nom, db_name, actif FROM etablissement ORDER BY actif DESC, nom") as $e) {
        $ver = (int) (assoc_val("SELECT version FROM schema_version_etab WHERE id_etablissement=?", [$e['id']]) ?? 0);
        $retard = array_values(array_filter(array_keys($dispo), fn($v) => $v > $ver));
        $out[] = $e + ['version' => $ver, 'retard' => $retard, 'a_jour' => !$retard];
    }
    return ['ecoles' => $out, 'vmax' => $vmax, 'nb_migrations' => count($dispo)];
}

/**
 * Applique les migrations manquantes à UNE école. Sauvegarde de sécurité
 * d'abord (sauf $backup=false). S'arrête à la première migration en échec.
 * Retour : ['ok'=>bool, 'message'=>string, 'appliquees'=>int[], 'backup'=>?string].
 */
function assoc_migrer_ecole(int $id, bool $backup = true): array {
    $e = assoc_one("SELECT * FROM etablissement WHERE id=?", [$id]);
    if (!$e) return ['ok' => false, 'message' => "École introuvable.", 'appliquees' => [], 'backup' => null];

    require_once __DIR__ . '/bd/lib/ecole_maintenance.php';
    $etat = ecole_base_etat($e['db_name']);
    if (!$etat['existe']) {
        return ['ok' => false, 'message' => "La base « {$e['db_name']} » n'existe pas.", 'appliquees' => [], 'backup' => null];
    }

    $dispo = assoc_migrations_disponibles();
    $ver   = (int) (assoc_val("SELECT version FROM schema_version_etab WHERE id_etablissement=?", [$id]) ?? 0);
    $a_faire = array_values(array_filter(array_keys($dispo), fn($v) => $v > $ver));
    if (!$a_faire) return ['ok' => true, 'message' => "Déjà à jour (v$ver).", 'appliquees' => [], 'backup' => null];

    $chemin_backup = null;
    if ($backup) {
        try { $chemin_backup = ecole_maint_backup_securite($e['db_name'], 'avant_migration'); }
        catch (\Throwable $ex) {
            return ['ok' => false, 'message' => "Sauvegarde de sécurité impossible — migration annulée : " . $ex->getMessage(),
                    'appliquees' => [], 'backup' => null];
        }
    }

    $l = mysqli_connect(DB_HOST, DB_USER, DB_PASS, $e['db_name']);
    mysqli_set_charset($l, 'utf8mb4');
    $ok = []; $echec = null;
    foreach ($a_faire as $v) {
        $sql = file_get_contents($dispo[$v]);
        try {
            if (mysqli_multi_query($l, $sql)) {
                do { /* consommer */ } while (mysqli_next_result($l));
            }
            if (mysqli_errno($l)) throw new RuntimeException(mysqli_error($l));
            assoc_exec(
                "INSERT INTO schema_version_etab (id_etablissement, version) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE version=GREATEST(version, VALUES(version))",
                [$id, $v]
            );
            $ok[] = $v;
        } catch (\Throwable $ex) {
            $echec = "v$v : " . $ex->getMessage();
            break;
        }
    }
    mysqli_close($l);

    if ($echec) {
        return ['ok' => false, 'appliquees' => $ok, 'backup' => $chemin_backup,
                'message' => "Migrations appliquées : " . ($ok ? implode(', ', array_map(fn($v) => "v$v", $ok)) : 'aucune')
                           . ". ARRÊT sur $echec. "
                           . ($chemin_backup ? "Sauvegarde : " . basename($chemin_backup) : '')];
    }
    return ['ok' => true, 'appliquees' => $ok, 'backup' => $chemin_backup,
            'message' => "École à jour : " . implode(', ', array_map(fn($v) => "v$v", $ok)) . " appliquée(s)."];
}

// =====================================================================
//  SÉCURITÉ DE LA CONNEXION (association/login.php, association/securite.php)
//  - limitation de débit : 8 échecs sur un même login OU une même IP en
//    15 min → connexion bloquée jusqu'à 15 min après le dernier échec ;
//  - double authentification TOTP (bd/lib/totp.php).
//  Sans table login_echec (annuaire non mis à jour) : dégradation
//  silencieuse, aucun blocage.
// =====================================================================

const ASSOC_LOGIN_FENETRE_MIN = 15;
const ASSOC_LOGIN_MAX_ECHECS  = 8;

/** Secondes de blocage restantes pour ce couple (login, ip), 0 si libre. */
function assoc_login_bloque(?string $login, ?string $ip): int {
    try {
        // Tout est calculé côté MySQL (NOW()) pour éviter tout écart de
        // fuseau horaire entre PHP et la base.
        $r = assoc_one(
            "SELECT COUNT(*) AS n,
                    TIMESTAMPDIFF(SECOND, NOW(), MAX(date) + INTERVAL ? MINUTE) AS restant
             FROM login_echec
             WHERE date > (NOW() - INTERVAL ? MINUTE) AND (login = ? OR ip = ?)",
            [ASSOC_LOGIN_FENETRE_MIN, ASSOC_LOGIN_FENETRE_MIN, (string) $login, (string) $ip]
        );
    } catch (\Throwable $e) {
        return 0;   // table absente : pas de limitation
    }
    if (!$r || (int) $r['n'] < ASSOC_LOGIN_MAX_ECHECS) return 0;
    return max(0, (int) $r['restant']);
}

function assoc_login_echec_noter(?string $login, ?string $ip): void {
    try {
        assoc_exec("INSERT INTO login_echec (login, ip) VALUES (?, ?)", [(string) $login, (string) $ip]);
        // purge opportuniste des vieilles lignes
        if (random_int(1, 20) === 1) {
            assoc_exec("DELETE FROM login_echec WHERE date < (NOW() - INTERVAL 1 DAY)");
        }
    } catch (\Throwable $e) { /* table absente : ignore */ }
}

function assoc_login_echec_reset(?string $login, ?string $ip): void {
    try {
        assoc_exec("DELETE FROM login_echec WHERE login = ? OR ip = ?", [(string) $login, (string) $ip]);
    } catch (\Throwable $e) { /* ignore */ }
}

/** Active la 2FA pour un membre après vérification d'un code. */
function assoc_membre_2fa_activer(int $id, string $secret, string $code): array {
    require_once __DIR__ . '/bd/lib/totp.php';
    if (!totp_verifier($secret, $code)) {
        return ['ok' => false, 'message' => "Code incorrect — vérifiez l'heure de votre téléphone et réessayez."];
    }
    assoc_exec("UPDATE membre SET totp_secret=?, totp_actif=1 WHERE id=?", [$secret, $id]);
    return ['ok' => true, 'message' => "Double authentification activée."];
}

/** Désactive la 2FA d'un membre (efface le secret). */
function assoc_membre_2fa_desactiver(int $id): void {
    assoc_exec("UPDATE membre SET totp_secret=NULL, totp_actif=0 WHERE id=?", [$id]);
}

/** La colonne totp_actif existe-t-elle (annuaire à jour) ? */
function assoc_2fa_disponible(): bool {
    static $ok = null;
    if ($ok === null) {
        try {
            $ok = (bool) assoc_val(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'membre' AND column_name = 'totp_actif'"
            );
        } catch (\Throwable $e) { $ok = false; }
    }
    return $ok;
}

/** La colonne membre.proprietaire existe-t-elle (bd/assoc/maj_assoc.php passé) ? */
function assoc_proprietaire_dispo(): bool {
    static $ok = null;
    if ($ok === null) {
        try {
            $ok = (bool) assoc_val(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'membre' AND column_name = 'proprietaire'"
            );
        } catch (\Throwable $e) { $ok = false; }
    }
    return $ok;
}

// =====================================================================
//  COORDONNÉES MEMBRE + RÉCUPÉRATION DE MOT DE PASSE
//  (association/securite.php + association/mot_de_passe_oublie.php)
//
//  Sans SMTP : la récupération se fait par CONCORDANCE de l'e-mail ET du
//  téléphone enregistrés sur le compte (+ limitation de débit login_echec).
//  Léger mais suffisant pour une petite association ; le jour où un vrai
//  serveur d'e-mail existe, on ajoutera un code à usage unique par-dessus.
// =====================================================================

/** La colonne membre.tel existe-t-elle (bd/assoc/maj_assoc.php passé) ? */
function assoc_coordonnees_dispo(): bool {
    static $ok = null;
    if ($ok === null) {
        try {
            $ok = (bool) assoc_val(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'membre' AND column_name = 'tel'"
            );
        } catch (\Throwable $e) { $ok = false; }
    }
    return $ok;
}

/**
 * Normalise un numéro de téléphone pour comparaison : on ne garde que les
 * 9 derniers chiffres (numéro national significatif au Cameroun), ce qui
 * rend équivalents « +237 691 22 33 44 », « 00237691223344 »,
 * « 0691223344 » et « 691223344 ».
 */
function assoc_tel_normaliser(?string $tel): string {
    $d = preg_replace('/\D+/', '', (string) $tel);
    return strlen($d) > 9 ? substr($d, -9) : $d;
}

/** Enregistre e-mail + téléphone d'un membre. */
function assoc_membre_coordonnees_maj(int $id, string $email, string $tel): array {
    $email = trim($email);
    $tel   = trim($tel);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => "Adresse e-mail invalide."];
    }
    if ($tel !== '' && !preg_match('/^[0-9 .+()-]{6,30}$/', $tel)) {
        return ['ok' => false, 'message' => "Numéro de téléphone invalide."];
    }
    $cols = assoc_coordonnees_dispo() ? "email=?, tel=?" : "email=?";
    $params = assoc_coordonnees_dispo() ? [$email ?: null, $tel ?: null, $id] : [$email ?: null, $id];
    assoc_exec("UPDATE membre SET $cols WHERE id=?", $params);
    return ['ok' => true, 'message' => "Coordonnées enregistrées."];
}

/**
 * Vérifie une demande de récupération : login + e-mail + téléphone doivent
 * TOUS correspondre à ceux enregistrés sur un compte actif. Rate-limité.
 * Retour : la ligne `membre` si tout concorde, null sinon.
 */
function assoc_recuperation_verifier(string $login, string $email, string $tel, ?string $ip): ?array {
    if (assoc_login_bloque($login, $ip) > 0) return null;
    if (!assoc_coordonnees_dispo()) return null;   // pas de téléphone en base : mécanisme indisponible

    $m = assoc_one("SELECT * FROM membre WHERE login=? AND actif=1", [trim($login)]);
    $ok = $m
        && trim((string) $m['email']) !== ''
        && trim((string) $m['tel'])   !== ''
        && strcasecmp(trim($m['email']), trim($email)) === 0
        && assoc_tel_normaliser($m['tel']) !== ''
        && assoc_tel_normaliser($m['tel']) === assoc_tel_normaliser($tel);

    if (!$ok) {
        assoc_login_echec_noter($login, $ip);
        return null;
    }
    return $m;
}

/** Définit un nouveau mot de passe pour un membre (après récupération vérifiée). */
function assoc_recuperation_appliquer(int $id, string $pwd, ?string $login, ?string $ip): array {
    if (strlen($pwd) < 8) return ['ok' => false, 'message' => "Mot de passe : 8 caractères minimum."];
    assoc_exec("UPDATE membre SET pwd_hash=? WHERE id=?", [password_hash($pwd, PASSWORD_DEFAULT), $id]);
    assoc_login_echec_reset($login, $ip);
    return ['ok' => true, 'message' => "Mot de passe réinitialisé — vous pouvez vous connecter."];
}
