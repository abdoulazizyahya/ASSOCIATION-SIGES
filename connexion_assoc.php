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
//  $type : 'primaire' (schema_ref_ecole.sql, comportement historique
//  inchangé) ou 'secondaire' (schema_ref_ecole_secondaire.sql, porté de
//  LAM_ABZ — voir plan « Intégration du secondaire »). Doit correspondre à
//  etablissement.type_enseignement (annuaire) pour cette école.
//  Retourne le nombre de tables créées. Lève une RuntimeException sur échec.
function charger_schema_ecole(mysqli $l, array $seed, string $type = 'primaire'): int {
    $fichier = $type === 'secondaire'
        ? __DIR__ . '/bd/assoc/schema_ref_ecole_secondaire.sql'
        : __DIR__ . '/bd/assoc/schema_ref_ecole.sql';
    $schema = @file_get_contents($fichier);
    if ($schema === false || trim($schema) === '') {
        throw new RuntimeException('Schéma de référence introuvable (' . basename($fichier) . ').');
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

    // Licence (bd/lib/licence.php, migration v56/v57) : période d'ESSAI de
    // 60 jours par défaut — sans ça, une école toute neuve serait
    // IMMÉDIATEMENT bloquée en écriture (licence_etat() fail-closed :
    // aucune ligne = 'expiree'), avant même que le propriétaire ait pu
    // générer une clé. Commun aux deux schémas (table `licence` identique).
    // Le propriétaire renouvelle/génère une clé ensuite normalement.
    if (function_exists('licence_signature')) {
        $lic_debut = date('Y-m-d');
        $lic_fin   = date('Y-m-d', strtotime('+60 days'));
        $lic_sig   = licence_signature($lic_fin, null, 'active');
        $stl = mysqli_prepare($l,
            "INSERT INTO licence (cle_licence, date_debut, date_expiration, statut, derniere_modification_par, date_derniere_modification, signature)
             VALUES (NULL, ?, ?, 'active', 'systeme:creation_ecole', NOW(), ?)");
        mysqli_stmt_bind_param($stl, 'sss', $lic_debut, $lic_fin, $lic_sig);
        mysqli_stmt_execute($stl);
        mysqli_stmt_close($stl);
    }

    if ($type === 'secondaire') {
        // Amorce la ligne `etablissement` locale au format LAM_ABZ (colonnes
        // nom_fr/nom_en/sigle/ville, pas IDEtablissement/Nom_Etab_Fr comme
        // en primaire). Pas de provisionnement classes/trimestres/barème
        // pour l'instant (schéma pédagogique secondaire pas encore branché
        // à aucune page — voir étapes suivantes du plan).
        $st = mysqli_prepare($l,
            "INSERT INTO etablissement (id, nom_fr, nom_en, sigle, ville)
             VALUES (1, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE nom_fr=VALUES(nom_fr), nom_en=VALUES(nom_en),
                                     sigle=VALUES(sigle), ville=VALUES(ville)");
        mysqli_stmt_bind_param($st, 'ssss', $seed['nom'], $seed['nom_en'], $seed['sigle'], $seed['ville']);
        mysqli_stmt_execute($st);
        mysqli_stmt_close($st);
        return $nbTables;
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

    regenerer_portail_accueil_best_effort();   // rafraîchit promeducamsiges.html

    return [
        'ok'      => true,
        'message' => "Établissement « $nom » créé (base $db, schéma v$vmax). "
                   . "Créez maintenant un compte DIRECTEUR via Personnel → Affecter.",
        'id'      => $id,
        'db_name' => $db,
    ];
}

// Régénère la copie statique du portail d'accueil (promeducamsiges.html)
// après création / modification / suppression d'une école. Best-effort :
// n'interrompt jamais l'opération appelante. accueil.php reste la version
// dynamique de référence — ceci n'est qu'un cache pour partage hors serveur.
function regenerer_portail_accueil_best_effort(): void {
    try {
        $gen = __DIR__ . '/bd/assoc/generer_accueil.php';
        if (is_file($gen)) {
            require_once $gen;
            if (function_exists('regenerer_portail_accueil')) {
                regenerer_portail_accueil();
            }
        }
    } catch (\Throwable $e) { /* silencieux — la vitrine n'est pas critique */ }
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
    // Défense en profondeur : hors CLI, seul le propriétaire peut supprimer
    // (la page etablissement_supprimer.php pose déjà exiger_proprietaire_association()).
    if (PHP_SAPI !== 'cli' && function_exists('est_proprietaire_association') && !est_proprietaire_association()) {
        return ['ok' => false, 'db_name' => null,
                'message' => "Suppression réservée au propriétaire de l'association."];
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
        assoc_exec("UPDATE journal_audit SET id_etablissement=NULL WHERE id_etablissement=?", [$id]);
        if (assoc_val("SELECT COUNT(*) FROM information_schema.tables
                       WHERE table_schema=DATABASE() AND table_name='journal_action'")) {
            assoc_exec("UPDATE journal_action SET id_etablissement=NULL WHERE id_etablissement=?", [$id]);
        }

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

    regenerer_portail_accueil_best_effort();   // rafraîchit promeducamsiges.html

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

// =====================================================================
//  JOURNAL D'AUDIT UNIFIÉ  (journal_audit — voir bd/lib/audit.php)
//  Couvre membres de l'association ET comptes d'école. Connexions,
//  déconnexions, échecs, actions sensibles + appareil + localisation.
// =====================================================================

/** Valeurs distinctes présentes dans le journal, pour peupler les filtres. */
function audit_journal_filtres(): array {
    $col = fn(string $c) => array_values(array_filter(array_column(
        assoc_all("SELECT DISTINCT `$c` AS v FROM journal_audit WHERE `$c` IS NOT NULL AND `$c` <> '' ORDER BY `$c`"),
        'v'
    )));
    return [
        'evenement' => $col('evenement'),
        'action'    => $col('action'),
        'appareil'  => $col('ua_appareil'),
        'role'      => $col('role'),
        'pays'      => $col('geo_pays'),
    ];
}

// Lignes du journal filtrées + total (pagination).
//  $f : id_etab?, acteur?(texte), type?(membre|user|inconnu), role?, evenement?,
//       action?, appareil?, pays?, depuis?(date), jusqua?(date)
//  $id_etab_impose : si non-null, restreint EN DUR à cette école (vue directeur).
function audit_journal(array $f, int $page = 1, int $par_page = 50, ?int $id_etab_impose = null): array {
    $w = []; $p = [];
    if ($id_etab_impose !== null) { $w[] = "j.id_etablissement = ?"; $p[] = $id_etab_impose; }
    elseif (!empty($f['id_etab'])) { $w[] = "j.id_etablissement = ?"; $p[] = (int) $f['id_etab']; }

    if (!empty($f['acteur'])) {
        $w[] = "(j.acteur_login LIKE ? OR j.acteur_nom LIKE ?)";
        $p[] = '%' . $f['acteur'] . '%'; $p[] = '%' . $f['acteur'] . '%';
    }
    if (!empty($f['type']))      { $w[] = "j.acteur_type = ?"; $p[] = $f['type']; }
    if (!empty($f['role']))      { $w[] = "j.role = ?";        $p[] = $f['role']; }
    if (!empty($f['evenement'])) { $w[] = "j.evenement = ?";   $p[] = $f['evenement']; }
    if (!empty($f['action']))    { $w[] = "j.action = ?";      $p[] = $f['action']; }
    if (!empty($f['appareil']))  { $w[] = "j.ua_appareil = ?"; $p[] = $f['appareil']; }
    if (!empty($f['pays']))      { $w[] = "j.geo_pays = ?";    $p[] = $f['pays']; }
    if (!empty($f['depuis']))    { $w[] = "j.date >= ?";       $p[] = $f['depuis'] . ' 00:00:00'; }
    if (!empty($f['jusqua']))    { $w[] = "j.date <= ?";       $p[] = $f['jusqua'] . ' 23:59:59'; }
    $sql_w = $w ? ('WHERE ' . implode(' AND ', $w)) : '';

    $total = (int) assoc_val("SELECT COUNT(*) FROM journal_audit j $sql_w", $p);
    $page  = max(1, $page);
    $off   = ($page - 1) * $par_page;

    $lignes = assoc_all(
        "SELECT j.*, e.code AS etab_code, e.nom AS etab_nom
         FROM journal_audit j
         LEFT JOIN etablissement e ON e.id = j.id_etablissement
         $sql_w
         ORDER BY j.date DESC, j.id DESC
         LIMIT $par_page OFFSET $off",
        $p
    );
    return ['lignes' => $lignes, 'total' => $total, 'page' => $page, 'par_page' => $par_page,
            'pages' => max(1, (int) ceil($total / $par_page))];
}

/** Purge les entrées plus vieilles que $mois mois. */
function audit_journal_purger(int $mois = 12): int {
    return assoc_exec("DELETE FROM journal_audit WHERE date < (NOW() - INTERVAL ? MONTH)", [$mois]);
}

// =====================================================================
//  MODULE « PRIVILÈGES » — règles d'accès par école (acces_regle)
//  Réglé depuis association/acces.php, appliqué côté école par
//  ecole_contexte.php::regles_centrales() / niveau_central().
//   portee : 'role' (nom de fonction) | 'user' (login du compte)
//   niveau : 'masque' | 'lecture' | 'ecriture'  (absence = défaut du rôle)
//   cle    : 'grp:<Nom de groupe>' | url d'entrée de menu
// =====================================================================

/** Toutes les règles d'une école (pour l'écran de configuration). */
function acces_regle_lister(int $id_etab): array {
    if (!annuaire_dispo()) return [];
    try {
        return assoc_all(
            "SELECT portee, cible, cle, niveau FROM acces_regle
             WHERE id_etablissement = ? ORDER BY portee, cible, cle",
            [$id_etab]
        );
    } catch (\Throwable $e) {
        return [];   // table pas encore créée
    }
}

/**
 * Remplace EN BLOC les règles d'un couple (école, portée, cible).
 * $regles : ['grp:X' => 'masque'|'lecture'|'ecriture', 'pages/…' => …].
 * Une valeur vide / 'defaut' supprime la ligne.
 */
function acces_regle_definir(int $id_etab, string $portee, string $cible, array $regles): void {
    if (!annuaire_dispo()) return;
    $portee = $portee === 'user' ? 'user' : 'role';
    $cible  = trim($cible);
    if ($cible === '') return;

    assoc_exec(
        "DELETE FROM acces_regle WHERE id_etablissement=? AND portee=? AND cible=?",
        [$id_etab, $portee, $cible]
    );
    foreach ($regles as $cle => $niveau) {
        $cle = trim((string) $cle);
        if ($cle === '' || !in_array($niveau, ['masque', 'lecture', 'ecriture'], true)) continue;
        assoc_exec(
            "INSERT INTO acces_regle (id_etablissement, portee, cible, cle, niveau)
             VALUES (?, ?, ?, ?, ?)",
            [$id_etab, $portee, $cible, $cle, $niveau]
        );
    }
}

/**
 * Règles effectives pour un utilisateur d'une école : fusion des règles de
 * son rôle et des règles nominatives (login), la portée 'user' l'emportant,
 * puis en cas d'égalité de portée : masque > lecture > ecriture.
 * Retour : ['grp:X' => niveau, 'pages/…' => niveau].
 */
function acces_regle_pour(int $id_etab, string $role, ?string $login): array {
    if (!annuaire_dispo() || $id_etab <= 0) return [];
    static $cache = [];
    $k = $id_etab . '|' . $role . '|' . ($login ?? '');
    if (isset($cache[$k])) return $cache[$k];

    $rang = ['ecriture' => 1, 'lecture' => 2, 'masque' => 3];
    $par_role = [];
    $par_user = [];
    try {
        $rows = assoc_all(
            "SELECT portee, cle, niveau FROM acces_regle
             WHERE id_etablissement = ?
               AND ( (portee='role' AND cible=?) OR (portee='user' AND cible=?) )",
            [$id_etab, $role, (string) $login]
        );
    } catch (\Throwable $e) {
        return $cache[$k] = [];
    }
    foreach ($rows as $r) {
        if ($r['portee'] === 'user') {
            if (!isset($par_user[$r['cle']]) || $rang[$r['niveau']] > $rang[$par_user[$r['cle']]]) {
                $par_user[$r['cle']] = $r['niveau'];
            }
        } else {
            if (!isset($par_role[$r['cle']]) || $rang[$r['niveau']] > $rang[$par_role[$r['cle']]]) {
                $par_role[$r['cle']] = $r['niveau'];
            }
        }
    }
    // user écrase role clé par clé.
    return $cache[$k] = array_merge($par_role, $par_user);
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

// Écoles ACTIVES dont le schéma a du retard — résumé léger pour le bandeau
// d'avertissement du propriétaire (layout/header.php, association/_layout.php),
// demande explicite du 13/09/2026 : AUCUNE application automatique, juste un
// rappel visible avec lien direct vers /association/migrations.php (où le
// clic « Migrer » reste requis, avec sa sauvegarde de sécurité).
function assoc_migrations_en_retard(): array {
    if (!annuaire_dispo()) return [];
    return array_values(array_filter(assoc_migrations_etat()['ecoles'], fn($e) => $e['actif'] && !$e['a_jour']));
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
            // Licence (migration v56) : même bootstrap d'essai 60 jours que
            // bd/assoc/migrer_toutes_ecoles.php — voir ce fichier pour le détail.
            if ($v === 56 && function_exists('licence_signature')) {
                $lic_debut = date('Y-m-d');
                $lic_fin   = date('Y-m-d', strtotime('+60 days'));
                $lic_sig   = licence_signature($lic_fin, null, 'active');
                $stl = mysqli_prepare($l,
                    "INSERT INTO licence (cle_licence, date_debut, date_expiration, statut, derniere_modification_par, date_derniere_modification, signature)
                     VALUES (NULL, ?, ?, 'active', 'systeme:migration_v56', NOW(), ?)");
                mysqli_stmt_bind_param($stl, 'sss', $lic_debut, $lic_fin, $lic_sig);
                mysqli_stmt_execute($stl);
                mysqli_stmt_close($stl);
            }
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

// =====================================================================
//  QUESTIONS SECRÈTES DES MEMBRES
//  Voie de récupération COMPLÉMENTAIRE à e-mail + téléphone. Table
//  membre_question_secrete (bd/assoc/maj_assoc.php), numero 1|2.
//  UI : association/securite.php (config) + association/mot_de_passe_oublie.php.
// =====================================================================

/** Normalise une réponse : minuscules + espaces réduits (comparaison tolérante). */
function assoc_reponse_normaliser(string $r): string {
    return preg_replace('/\s+/u', ' ', mb_strtolower(trim($r), 'UTF-8'));
}

/** La table des questions secrètes existe-t-elle (maj_assoc.php passé) ? */
function assoc_questions_dispo(): bool {
    static $ok = null;
    if ($ok === null) {
        try {
            $ok = (bool) assoc_val(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = 'membre_question_secrete'");
        } catch (\Throwable $e) { $ok = false; }
    }
    return $ok;
}

/** Libellés de questions proposés (le membre peut aussi saisir la sienne). */
function assoc_questions_suggerees(): array {
    return [
        "Quel est le nom de jeune fille de votre mère ?",
        "Quel est le nom de votre école primaire ?",
        "Quel est le nom de votre premier animal de compagnie ?",
        "Dans quelle ville êtes-vous né(e) ?",
        "Quel est le prénom de votre meilleur(e) ami(e) d'enfance ?",
        "Quel est votre plat préféré ?",
        "Quel était le modèle de votre premier véhicule ?",
    ];
}

/** Nombre de questions secrètes configurées pour un membre (0, 1 ou 2). */
function assoc_membre_questions_nb(int $id_membre): int {
    if (!$id_membre || !assoc_questions_dispo()) return 0;
    return (int) assoc_val("SELECT COUNT(*) FROM membre_question_secrete WHERE id_membre=?", [$id_membre]);
}

/** Libellés des questions d'un membre : [1 => '…', 2 => '…']. */
function assoc_membre_questions_get(int $id_membre): array {
    if (!$id_membre || !assoc_questions_dispo()) return [];
    $out = [];
    foreach (assoc_all("SELECT numero, question FROM membre_question_secrete WHERE id_membre=? ORDER BY numero", [$id_membre]) as $r) {
        $out[(int) $r['numero']] = $r['question'];
    }
    return $out;
}

/**
 * Enregistre (remplace) les 2 questions secrètes d'un membre.
 * $paires : [['question'=>'…','reponse'=>'…'], ['question'=>…,'reponse'=>…]]
 */
function assoc_membre_questions_definir(int $id_membre, array $paires): array {
    if (!assoc_questions_dispo()) {
        return ['ok' => false, 'message' => "Fonction indisponible : lancez bd/assoc/maj_assoc.php."];
    }
    if (count($paires) !== 2) return ['ok' => false, 'message' => "Il faut exactement 2 questions."];
    $q = [];
    foreach ($paires as $p) {
        $qi = trim((string) ($p['question'] ?? ''));
        $ri = trim((string) ($p['reponse'] ?? ''));
        if ($qi === '' || $ri === '') return ['ok' => false, 'message' => "Chaque question doit avoir un libellé et une réponse."];
        $q[] = [mb_substr($qi, 0, 160), $ri];
    }
    if (assoc_reponse_normaliser($q[0][0]) === assoc_reponse_normaliser($q[1][0])) {
        return ['ok' => false, 'message' => "Les 2 questions doivent être différentes."];
    }
    assoc_exec("DELETE FROM membre_question_secrete WHERE id_membre=?", [$id_membre]);
    $n = 1;
    foreach ($q as [$qi, $ri]) {
        assoc_exec(
            "INSERT INTO membre_question_secrete (id_membre, numero, question, reponse_hash) VALUES (?,?,?,?)",
            [$id_membre, $n++, $qi, password_hash(assoc_reponse_normaliser($ri), PASSWORD_DEFAULT)]
        );
    }
    return ['ok' => true, 'message' => "Questions secrètes enregistrées."];
}

/** Supprime les questions secrètes d'un membre. */
function assoc_membre_questions_supprimer(int $id_membre): void {
    if (assoc_questions_dispo()) assoc_exec("DELETE FROM membre_question_secrete WHERE id_membre=?", [$id_membre]);
}

/**
 * Récupération par questions secrètes : $login + les 2 réponses doivent
 * correspondre. Rate-limité (login_echec). Retour : ligne `membre` ou null.
 */
function assoc_recuperation_verifier_questions(string $login, string $rep1, string $rep2, ?string $ip): ?array {
    if (!assoc_questions_dispo()) return null;
    if (assoc_login_bloque($login, $ip) > 0) return null;
    $m = assoc_one("SELECT * FROM membre WHERE login=? AND actif=1", [trim($login)]);
    if (!$m) { assoc_login_echec_noter($login, $ip); return null; }
    $rows = assoc_all("SELECT numero, reponse_hash FROM membre_question_secrete WHERE id_membre=? ORDER BY numero", [(int) $m['id']]);
    if (count($rows) < 2) { assoc_login_echec_noter($login, $ip); return null; }
    $h = [];
    foreach ($rows as $r) $h[(int) $r['numero']] = $r['reponse_hash'];
    $ok = password_verify(assoc_reponse_normaliser($rep1), $h[1] ?? '')
       && password_verify(assoc_reponse_normaliser($rep2), $h[2] ?? '');
    if (!$ok) { assoc_login_echec_noter($login, $ip); return null; }
    return $m;
}

// =====================================================================
//  LOGOS D'ÉCOLES (portail association)
// =====================================================================

/**
 * URL du logo d'une école à partir de sa ligne annuaire (`etablissement.logo`,
 * chemin relatif à assets/uploads/), ou null si absent / fichier introuvable.
 */
function assoc_ecole_logo_url(?string $logo): ?string {
    $logo = trim((string) $logo);
    if ($logo === '' || strpos($logo, '..') !== false) return null;
    if (!is_file(__DIR__ . '/assets/uploads/' . $logo)) return null;
    return APP_URL . '/assets/uploads/' . $logo;
}

/**
 * Rafraîchit `etablissement.logo` (annuaire) depuis la base d'une école —
 * best-effort, sans lever d'exception. À appeler quand on a déjà une
 * connexion ouverte sur la base école ($l), pour garder l'annuaire à jour
 * (le logo se règle DANS l'école, Configurations).
 */
function assoc_sync_logo_ecole(mysqli $l, int $id_etab): void {
    try {
        $r = mysqli_query($l, "SELECT logo FROM etablissement WHERE COALESCE(logo,'')<>'' LIMIT 1");
        $logo = $r ? (mysqli_fetch_row($r)[0] ?? null) : null;
        if ($logo !== null && $logo !== '') {
            assoc_exec("UPDATE etablissement SET logo=? WHERE id=? AND COALESCE(logo,'')<>?", [$logo, $id_etab, $logo]);
        }
    } catch (\Throwable $e) { /* ignore */ }
}

// =====================================================================
//  REGISTRE NIU — génération + vue « élèves du réseau »
//  (association/niu/index.php). Format d'un NIU :
//    <PREFIXE><SIGLE3><AA><NNNN>
//     PREFIXE = constante NIU_PREFIXE (défaut « PMC »)
//     SIGLE3  = etablissement.niu_sigle (3 lettres, réglable par école)
//     AA      = 2 derniers chiffres de l'année scolaire de l'école
//     NNNN    = numéro d'ordre 4 chiffres, séquence par (SIGLE3, AA)
// =====================================================================

/** Le code 3 lettres d'une école dans le NIU (niu_sigle, ou défaut sigle/code). */
function assoc_niu_sigle(array $e): string {
    $s = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($e['niu_sigle'] ?? '')));
    if ($s === '') {
        $s = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($e['sigle'] ?: ($e['code'] ?? ''))));
    }
    return substr(str_pad($s, 3, 'X'), 0, 3);
}

/** La colonne etablissement.niu_sigle existe-t-elle (maj_assoc.php passé) ? */
function assoc_niu_config_dispo(): bool {
    static $ok = null;
    if ($ok === null) {
        try {
            $ok = (bool) assoc_val(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'etablissement' AND column_name = 'niu_sigle'");
        } catch (\Throwable $e) { $ok = false; }
    }
    return $ok;
}

/** Base d'un NIU pour une école + une année scolaire (« PMCJAB26 »). */
function assoc_niu_base(array $e, ?string $val_annee): string {
    $prefixe = (defined('NIU_PREFIXE') && NIU_PREFIXE !== '') ? NIU_PREFIXE : 'PMC';
    $an      = substr((string) (explode('/', (string) $val_annee)[0] ?: date('Y')), -2);
    return strtoupper($prefixe) . assoc_niu_sigle($e) . $an;
}

/**
 * Génère (et réserve dans eleve_niu) un NIU pour une identité rattachée à
 * l'école $id_etab. $ident : ['nom','prenom','date_naiss','sexe','lieu_naiss'].
 * Retourne le NIU, ou null si échec.
 */
function assoc_niu_generer_pour(int $id_etab, array $ident, string $par, ?string $val_annee = null): ?string {
    $e = assoc_one("SELECT * FROM etablissement WHERE id=?", [$id_etab]);
    if (!$e) return null;
    $base  = assoc_niu_base($e, $val_annee);
    $regex = '^' . preg_quote($base, '/') . '[0-9]{4}$';

    for ($essai = 0; $essai < 8; $essai++) {
        $max = (int) assoc_val(
            "SELECT MAX(CAST(SUBSTRING(niu, ?) AS UNSIGNED)) FROM eleve_niu WHERE niu REGEXP ?",
            [strlen($base) + 1, $regex]
        );
        $n   = $essai < 4 ? $max + 1 : random_int(1, 9999);
        $niu = $base . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
        try {
            assoc_exec(
                "INSERT INTO eleve_niu (niu, nom, prenom, date_naissance, sexe, lieu_naissance,
                        id_etab_origine, id_etab_courant, statut, cree_par)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'actif', ?)",
                [$niu, $ident['nom'] ?? null, $ident['prenom'] ?? null, $ident['date_naiss'] ?? null,
                 $ident['sexe'] ?? null, $ident['lieu_naiss'] ?? null, $id_etab, $id_etab, $par]
            );
            assoc_exec("INSERT INTO eleve_niu_mouvement (niu, id_etab_cible, type, par) VALUES (?, ?, 'creation', ?)",
                       [$niu, $id_etab, $par]);
            return $niu;
        } catch (\Throwable $ex) { /* collision PK : on retente */ }
    }
    return null;
}

/**
 * Génère les NIU MANQUANTS : pour chaque élève actif sans NIU d'une école
 * (ou de toutes si $id_etab === null), crée un NIU et l'écrit dans eleve.niu
 * + eleve_niu. Retour :
 *   ['ecoles' => [['code','nom','crees','deja','total','erreur'], …],
 *    'total_crees' => int]
 */
function assoc_niu_generer_manquants(?int $id_etab, string $par): array {
    $ecoles = $id_etab
        ? assoc_all("SELECT * FROM etablissement WHERE id=? AND actif=1", [$id_etab])
        : assoc_all("SELECT * FROM etablissement WHERE actif=1 ORDER BY nom");
    $out = ['ecoles' => [], 'total_crees' => 0];

    foreach ($ecoles as $e) {
        $ligne = ['code' => $e['code'], 'nom' => $e['nom'], 'crees' => 0, 'deja' => 0, 'total' => 0, 'erreur' => null];
        try {
            $l = mysqli_connect(DB_HOST, DB_USER, DB_PASS, $e['db_name']);
            mysqli_set_charset($l, 'utf8mb4');
        } catch (\Throwable $ex) {
            $ligne['erreur'] = 'base injoignable';
            $out['ecoles'][] = $ligne;
            continue;
        }

        $va = null;
        $r = mysqli_query($l, "SELECT val_annee FROM annee_scolaire WHERE Etat_annee_scolaire=1 LIMIT 1");
        if ($r && ($row = mysqli_fetch_row($r))) $va = $row[0];

        $res = mysqli_query($l,
            "SELECT id_eleve, Nom_elv, Prenom_elv, Date_naiss_elv, Sexe_elv, Lieu_naiss_elv, niu
             FROM eleve WHERE statut='actif'");
        $eleves = $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];
        $ligne['total'] = count($eleves);

        $st = mysqli_prepare($l, "UPDATE eleve SET niu=? WHERE id_eleve=?");
        foreach ($eleves as $el) {
            if (trim((string) $el['niu']) !== '') { $ligne['deja']++; continue; }
            $niu = assoc_niu_generer_pour((int) $e['id'], [
                'nom'        => $el['Nom_elv'],
                'prenom'     => $el['Prenom_elv'],
                'date_naiss' => $el['Date_naiss_elv'] ?: null,
                'sexe'       => $el['Sexe_elv'] ?: null,
                'lieu_naiss' => $el['Lieu_naiss_elv'] ?: null,
            ], $par, $va);
            if ($niu) {
                mysqli_stmt_bind_param($st, 'si', $niu, $el['id_eleve']);
                mysqli_stmt_execute($st);
                $ligne['crees']++;
            }
        }
        mysqli_stmt_close($st);
        mysqli_close($l);

        $out['total_crees'] += $ligne['crees'];
        $out['ecoles'][] = $ligne;
        if ($ligne['crees'] > 0) {
            journaliser_action('niu_generation_masse', (int) $e['id'], $ligne['crees'] . ' NIU');
        }
    }
    return $out;
}

/**
 * Liste agrégée des élèves de TOUTES les écoles (ou d'une seule), paginée,
 * avec filtres. $f : ['etab'=>?int, 'q'=>?string, 'niu'=>'avec'|'sans'|null].
 * Coûteux (une requête par école, filtrage/tri/pagination en PHP) — OK pour
 * un réseau de quelques milliers d'élèves.
 */
function assoc_eleves_systeme(array $f, int $page = 1, int $par_page = 40): array {
    $ecoles = !empty($f['etab'])
        ? assoc_all("SELECT * FROM etablissement WHERE id=?", [(int) $f['etab']])
        : assoc_all("SELECT * FROM etablissement WHERE actif=1 ORDER BY nom");

    $q  = trim((string) ($f['q'] ?? ''));
    $ql = mb_strtolower($q);
    $tous = [];
    $sans_niu = 0;

    foreach ($ecoles as $e) {
        try {
            $l = mysqli_connect(DB_HOST, DB_USER, DB_PASS, $e['db_name']);
            mysqli_set_charset($l, 'utf8mb4');
        } catch (\Throwable $ex) { continue; }

        $res = mysqli_query($l,
            "SELECT e.id_eleve, e.Mat_elv, e.Nom_elv, e.Prenom_elv, e.Date_naiss_elv,
                    e.Sexe_elv, e.niu,
                    (SELECT GROUP_CONCAT(CONCAT(p.nom, ' ', p.prenom) SEPARATOR ', ')
                     FROM parent p WHERE p.id_eleve = e.id_eleve) AS parents
             FROM eleve e WHERE e.statut = 'actif'");
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $niu = trim((string) $r['niu']);
            if ($niu === '') $sans_niu++;

            if (($f['niu'] ?? null) === 'avec' && $niu === '') continue;
            if (($f['niu'] ?? null) === 'sans' && $niu !== '') continue;

            if ($q !== '') {
                $hay = mb_strtolower(
                    $r['Nom_elv'] . ' ' . $r['Prenom_elv'] . ' ' . $niu . ' '
                    . $r['Mat_elv'] . ' ' . ($r['parents'] ?? ''));
                if (mb_strpos($hay, $ql) === false) continue;
            }

            $tous[] = [
                'ecole_code' => $e['code'], 'ecole_nom' => $e['nom'], 'ecole_id' => (int) $e['id'],
                'id_eleve'   => (int) $r['id_eleve'],
                'mat'        => $r['Mat_elv'],
                'nom'        => trim($r['Nom_elv'] . ' ' . $r['Prenom_elv']),
                'naiss'      => $r['Date_naiss_elv'],
                'sexe'       => $r['Sexe_elv'],
                'niu'        => $niu,
                'parents'    => $r['parents'],
            ];
        }
        mysqli_close($l);
    }

    usort($tous, fn($a, $b) => [$a['ecole_nom'], $a['nom']] <=> [$b['ecole_nom'], $b['nom']]);

    $total = count($tous);
    $pages = max(1, (int) ceil($total / $par_page));
    $page  = max(1, min($page, $pages));

    return [
        'lignes'   => array_slice($tous, ($page - 1) * $par_page, $par_page),
        'total'    => $total,
        'page'     => $page,
        'pages'    => $pages,
        'par_page' => $par_page,
        'sans_niu' => $sans_niu,
    ];
}

// =====================================================================
//  CONSOLE DES COMPTES UTILISATEURS DE TOUTES LES ÉCOLES
//  (association/personnel/liste.php, onglet « Comptes »)
//  Le superadmin voit/gère les comptes `user` de chaque base école :
//  réinitialiser le mot de passe, activer/désactiver (user.actif, v54),
//  changer le rôle, supprimer l'accès, créer un compte.
// =====================================================================

/** Libellé lisible d'un rôle école (id_fonction). */
function assoc_role_libelle(?string $r): string {
    static $map = [
        'DIRECTEUR' => 'Directeur', 'ENSEIGNANT' => 'Enseignant(e)',
        'SECRETAIRE' => 'Secrétaire', 'COMPTABLE' => 'Comptable',
        'FONDATEUR' => 'Fondateur',
    ];
    $r = (string) $r;
    return $map[$r] ?? ($r !== '' ? ucfirst(mb_strtolower($r)) : '—');
}

/** Rôles attribuables depuis la console (jamais FONDATEUR : réservé à Personnel/Affecter). */
function assoc_roles_console(): array {
    return ['DIRECTEUR' => 'Directeur', 'ENSEIGNANT' => 'Enseignant(e)',
            'SECRETAIRE' => 'Secrétaire', 'COMPTABLE' => 'Comptable'];
}

/** Une colonne existe-t-elle dans une base école déjà connectée ($l) ? */
function ecole_colonne_existe(mysqli $l, string $table, string $colonne): bool {
    $r = ecole_one($l,
        "SELECT COUNT(*) c FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?",
        [$table, $colonne]);
    return (int) ($r['c'] ?? 0) > 0;
}

/**
 * Agrège les comptes `user` de toutes les écoles (ou d'une seule si $f['etab']).
 * $f : etab (id), q (recherche), role (id_fonction), statut ('actif'|'inactif'|'sans_connexion').
 * Retour : ['lignes'=>[...], 'total','page','pages','par_page','stats'=>[...]].
 */
function assoc_comptes_systeme(array $f, int $page = 1, int $par_page = 40): array {
    $ecoles = !empty($f['etab'])
        ? assoc_all("SELECT * FROM etablissement WHERE id=?", [(int) $f['etab']])
        : assoc_all("SELECT * FROM etablissement WHERE actif=1 ORDER BY nom");

    $q  = trim((string) ($f['q'] ?? ''));
    $ql = mb_strtolower($q);
    $role_f   = (string) ($f['role'] ?? '');
    $statut_f = (string) ($f['statut'] ?? '');

    $tous = [];
    $stats = ['total' => 0, 'actifs' => 0, 'inactifs' => 0, 'dormants' => 0];

    foreach ($ecoles as $e) {
        try {
            $l = mysqli_connect(DB_HOST, DB_USER, DB_PASS, $e['db_name']);
            mysqli_set_charset($l, 'utf8mb4');
        } catch (\Throwable $ex) { continue; }

        $a_statut  = ecole_colonne_existe($l, 'user', 'actif');
        $a_dc      = ecole_colonne_existe($l, 'user', 'derniere_connexion');
        $sel_actif = $a_statut ? 'u.actif' : '1 AS actif';
        $sel_dc    = $a_dc ? 'u.derniere_connexion' : 'NULL AS derniere_connexion';

        $res = mysqli_query($l,
            "SELECT u.id_user, u.login_user, u.matricule_ens, $sel_actif, $sel_dc,
                    en.nom_ens, en.prenom_ens, en.id_fonction, en.statut_ens
             FROM user u
             JOIN enseignant en ON en.matricule_ens = u.matricule_ens
             ORDER BY en.id_fonction, en.nom_ens");

        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $actif = (int) $r['actif'] === 1;
            $dc    = $r['derniere_connexion'];
            $dormant = $actif && ($dc === null || strtotime($dc) < time() - 90 * 86400);

            $stats['total']++;
            if ($actif) $stats['actifs']++; else $stats['inactifs']++;
            if ($dormant) $stats['dormants']++;

            if ($role_f !== '' && (string) $r['id_fonction'] !== $role_f) continue;
            if ($statut_f === 'actif'   && !$actif) continue;
            if ($statut_f === 'inactif' && $actif) continue;
            if ($statut_f === 'dormant' && !$dormant) continue;

            $nom = trim(($r['nom_ens'] ?? '') . ' ' . ($r['prenom_ens'] ?? ''));
            if ($q !== '' && mb_strpos(mb_strtolower($nom . ' ' . $r['login_user']), $ql) === false) continue;

            $tous[] = [
                'ecole_id'   => (int) $e['id'], 'ecole_code' => $e['code'], 'ecole_nom' => $e['nom'],
                'id_user'    => (int) $r['id_user'],
                'login'      => $r['login_user'],
                'matricule_ens' => (int) $r['matricule_ens'],
                'nom'        => $nom ?: '—',
                'role'       => (string) $r['id_fonction'],
                'role_lib'   => assoc_role_libelle($r['id_fonction']),
                'actif'      => $actif,
                'derniere_connexion' => $dc,
                'dormant'    => $dormant,
                'statut_ens' => $r['statut_ens'] ?? 'actif',
            ];
        }
        mysqli_close($l);
    }

    usort($tous, fn($a, $b) => [$a['ecole_nom'], $a['nom']] <=> [$b['ecole_nom'], $b['nom']]);

    // Doublons d'identifiant entre écoles (info) : chaque base école est
    // indépendante, un même login peut donc exister dans plusieurs écoles.
    // On calcule sur TOUT le réseau (indépendamment du filtre école), pour
    // que le repère reste juste même en filtrant sur une seule école.
    $doublons = [];
    if (empty($f['etab'])) {
        $par_login = [];
        foreach ($tous as $c) $par_login[mb_strtolower($c['login'])][] = $c['ecole_code'];
        foreach ($par_login as $lg => $codes) {
            if (count($codes) > 1) $doublons[$lg] = array_values(array_unique($codes));
        }
    } else {
        // filtré sur une école : refaire un balayage léger des logins réseau
        foreach (assoc_all("SELECT db_name, code FROM etablissement WHERE actif=1") as $e) {
            try {
                $l = mysqli_connect(DB_HOST, DB_USER, DB_PASS, $e['db_name']);
                $rr = mysqli_query($l, "SELECT login_user FROM user");
                while ($rr && ($x = mysqli_fetch_row($rr))) {
                    $doublons[mb_strtolower($x[0])][] = $e['code'];
                }
                mysqli_close($l);
            } catch (\Throwable $ex) { /* école injoignable : ignorée */ }
        }
        foreach ($doublons as $lg => $codes) {
            $u = array_values(array_unique($codes));
            if (count($u) > 1) $doublons[$lg] = $u; else unset($doublons[$lg]);
        }
    }
    foreach ($tous as &$c) {
        $c['doublon'] = $doublons[mb_strtolower($c['login'])] ?? [];
    }
    unset($c);
    $stats['doublons'] = count($doublons);

    $total    = count($tous);
    $par_page = max(1, $par_page);
    $pages    = max(1, (int) ceil($total / $par_page));
    $page     = max(1, min($page, $pages));

    return [
        'lignes'   => array_slice($tous, ($page - 1) * $par_page, $par_page),
        'toutes'   => $tous,
        'total'    => $total,
        'page'     => $page,
        'pages'    => $pages,
        'par_page' => $par_page,
        'stats'    => $stats,
        'doublons' => $doublons,
    ];
}

/** Personnel d'une école SANS compte de connexion (pour la création). */
function assoc_ecole_personnel_sans_compte(int $id_etab): array {
    try {
        return avec_ecole($id_etab, function (mysqli $l) {
            return ecole_all($l,
                "SELECT en.matricule_ens, en.nom_ens, en.prenom_ens, en.id_fonction
                 FROM enseignant en
                 WHERE en.matricule_ens NOT IN (SELECT matricule_ens FROM user)
                   AND COALESCE(en.statut_ens,'actif') = 'actif'
                 ORDER BY en.nom_ens, en.prenom_ens");
        });
    } catch (\Throwable $e) { return []; }
}

/** Nombre de comptes DIRECTEUR actifs d'une école (garde-fou « dernier directeur »). */
function assoc_ecole_nb_directeurs_actifs(mysqli $l): int {
    $a_statut = ecole_colonne_existe($l, 'user', 'actif');
    $cond = $a_statut ? 'AND u.actif = 1' : '';
    $r = ecole_one($l,
        "SELECT COUNT(*) c FROM user u JOIN enseignant en ON en.matricule_ens = u.matricule_ens
         WHERE en.id_fonction = 'DIRECTEUR' $cond");
    return (int) ($r['c'] ?? 0);
}

/**
 * Action sur un compte école depuis la console association.
 * $op : 'reset_mdp' (p.pwd) | 'desactiver' | 'activer' | 'role' (p.role) | 'supprimer'.
 * Garde-fou : on ne rend pas une école ingérable (dernier DIRECTEUR actif).
 * Retour : ['ok'=>bool, 'message'=>string].
 */
function assoc_compte_ecole_action(int $id_etab, int $id_user, string $op, array $p = []): array {
    if (!est_superadmin_association()) return ['ok' => false, 'message' => "Réservé au superadmin."];
    $e = assoc_one("SELECT id, code, nom FROM etablissement WHERE id=?", [$id_etab]);
    if (!$e) return ['ok' => false, 'message' => "École introuvable."];

    try {
        return avec_ecole($id_etab, function (mysqli $l) use ($op, $id_user, $p, $e) {
            $u = ecole_one($l,
                "SELECT u.id_user, u.login_user, u.matricule_ens, en.id_fonction,
                        en.nom_ens, en.prenom_ens
                 FROM user u JOIN enseignant en ON en.matricule_ens = u.matricule_ens
                 WHERE u.id_user = ?", [$id_user]);
            if (!$u) return ['ok' => false, 'message' => "Compte introuvable dans « {$e['nom']} »."];

            $a_statut       = ecole_colonne_existe($l, 'user', 'actif');
            $est_directeur  = (string) $u['id_fonction'] === 'DIRECTEUR';
            $dernier_dir    = $est_directeur && assoc_ecole_nb_directeurs_actifs($l) <= 1;
            $qui            = trim($u['nom_ens'] . ' ' . ($u['prenom_ens'] ?? '')) . " (« {$u['login_user']} »)";

            if ($op === 'reset_mdp') {
                $pwd = (string) ($p['pwd'] ?? '');
                if (strlen($pwd) < 4) return ['ok' => false, 'message' => "Mot de passe : 4 caractères minimum."];
                ecole_exec($l, "UPDATE user SET pwd_user = ? WHERE id_user = ?",
                    [password_hash($pwd, PASSWORD_DEFAULT), $id_user]);
                return ['ok' => true, 'message' => "Mot de passe réinitialisé pour $qui."];
            }

            if ($op === 'desactiver') {
                if (!$a_statut) return ['ok' => false, 'message' => "Base « {$e['nom']} » pas à jour (migration v54)."];
                if ($dernier_dir) return ['ok' => false, 'message' => "Impossible : c'est le dernier Directeur actif de « {$e['nom']} »."];
                ecole_exec($l, "UPDATE user SET actif = 0 WHERE id_user = ?", [$id_user]);
                return ['ok' => true, 'message' => "Compte de $qui désactivé."];
            }

            if ($op === 'activer') {
                if (!$a_statut) return ['ok' => false, 'message' => "Base « {$e['nom']} » pas à jour (migration v54)."];
                ecole_exec($l, "UPDATE user SET actif = 1 WHERE id_user = ?", [$id_user]);
                return ['ok' => true, 'message' => "Compte de $qui réactivé."];
            }

            if ($op === 'role') {
                $role = strtoupper((string) ($p['role'] ?? ''));
                if (!isset(assoc_roles_console()[$role])) return ['ok' => false, 'message' => "Rôle invalide."];
                if ($est_directeur && $role !== 'DIRECTEUR' && $dernier_dir) {
                    return ['ok' => false, 'message' => "Impossible : c'est le dernier Directeur actif de « {$e['nom']} »."];
                }
                ecole_exec($l, "UPDATE enseignant SET id_fonction = ? WHERE matricule_ens = ?",
                    [$role, $u['matricule_ens']]);
                return ['ok' => true, 'message' => "Rôle de $qui : " . assoc_role_libelle($role) . "."];
            }

            if ($op === 'supprimer') {
                if ($dernier_dir) return ['ok' => false, 'message' => "Impossible : c'est le dernier Directeur actif de « {$e['nom']} »."];
                ecole_exec($l, "DELETE FROM user WHERE id_user = ?", [$id_user]);
                return ['ok' => true, 'message' => "Compte de connexion de $qui supprimé (la fiche personnel est conservée)."];
            }

            return ['ok' => false, 'message' => "Action inconnue."];
        });
    } catch (\Throwable $ex) {
        return ['ok' => false, 'message' => "Erreur : " . $ex->getMessage()];
    }
}

/** Crée un compte de connexion dans une école pour un membre du personnel. */
function assoc_compte_ecole_creer(int $id_etab, string $login, string $pwd, int $matricule_ens): array {
    if (!est_superadmin_association()) return ['ok' => false, 'message' => "Réservé au superadmin."];
    $login = trim($login);
    if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $login)) {
        return ['ok' => false, 'message' => "Identifiant : 3–50 caractères (lettres, chiffres, . _ -)."];
    }
    if (strlen($pwd) < 4) return ['ok' => false, 'message' => "Mot de passe : 4 caractères minimum."];
    if (!$matricule_ens)  return ['ok' => false, 'message' => "Choisissez un membre du personnel."];

    try {
        return avec_ecole($id_etab, function (mysqli $l) use ($login, $pwd, $matricule_ens) {
            $ens = ecole_one($l, "SELECT nom_ens, prenom_ens FROM enseignant WHERE matricule_ens = ?", [$matricule_ens]);
            if (!$ens) return ['ok' => false, 'message' => "Membre du personnel introuvable."];
            if (ecole_one($l, "SELECT id_user FROM user WHERE matricule_ens = ?", [$matricule_ens])) {
                return ['ok' => false, 'message' => "Cette personne a déjà un compte."];
            }
            if (ecole_one($l, "SELECT id_user FROM user WHERE login_user = ?", [$login])) {
                return ['ok' => false, 'message' => "L'identifiant « $login » est déjà pris dans cette école."];
            }
            ecole_exec($l, "INSERT INTO user (login_user, pwd_user, matricule_ens) VALUES (?, ?, ?)",
                [$login, password_hash($pwd, PASSWORD_DEFAULT), $matricule_ens]);
            $qui = trim($ens['nom_ens'] . ' ' . ($ens['prenom_ens'] ?? ''));
            return ['ok' => true, 'message' => "Compte « $login » créé pour $qui."];
        });
    } catch (\Throwable $ex) {
        return ['ok' => false, 'message' => "Erreur : " . $ex->getMessage()];
    }
}
