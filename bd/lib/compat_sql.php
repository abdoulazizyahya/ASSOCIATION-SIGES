<?php
// bd/lib/compat_sql.php — compatibilité du SQL avec MariaDB / MySQL 5.7 (06/11/2026).
//
// Le schéma de référence et les migrations (écrits sous MySQL 8) utilisent la
// collation utf8mb4_0900_ai_ci, inconnue de MariaDB (jusqu'à 11.4) et de
// MySQL 5.7 — « Unknown collation: 'utf8mb4_0900_ai_ci' » à l'installation
// (WampServer 3.2.x). Sur un tel serveur, le SQL lu dans un fichier .sql passe
// par sql_compat_serveur() avant exécution : la collation est remplacée par
// utf8mb4_unicode_ci (la collation de toutes les autres tables). Sur MySQL 8
// (Camoo, ce poste) le SQL reste inchangé.
// Chargé par config/config.php.

/** Le serveur de $l connaît-il utf8mb4_0900_ai_ci ? (une requête par serveur) */
function sql_serveur_a_0900(mysqli $l): bool {
    static $cache = [];
    $cle = mysqli_get_host_info($l) . '|' . mysqli_get_server_info($l);
    if (!isset($cache[$cle])) {
        try {
            $r = mysqli_query($l, "SHOW COLLATION LIKE 'utf8mb4\\_0900\\_ai\\_ci'");
            $cache[$cle] = $r instanceof mysqli_result && mysqli_num_rows($r) > 0;
            if ($r instanceof mysqli_result) mysqli_free_result($r);
        } catch (\Throwable $e) {
            $cache[$cle] = true;   // doute : on ne touche pas au SQL
        }
    }
    return $cache[$cle];
}

/**
 * Le serveur accepte-t-il une expression comme valeur par défaut
 * (« DEFAULT (curdate()) ») ? MySQL ≥ 8.0.13 et MariaDB ≥ 10.2 oui ;
 * MySQL 5.7 (WampServer 3.2.x) non : « #1064 Erreur de syntaxe près de '(curdate())' ».
 */
function sql_serveur_defaut_expression(mysqli $l): bool {
    $v = mysqli_get_server_info($l);
    if (stripos($v, 'mariadb') !== false) return version_compare(preg_replace('/^(?:5\.5\.5-)?([\d.]+).*$/', '$1', $v), '10.2', '>=');
    return version_compare(preg_replace('/^([\d.]+).*$/', '$1', $v), '8.0.13', '>=');
}

/** SQL adapté au serveur de $l (MySQL 8 → MariaDB / MySQL 5.7). */
function sql_compat_serveur(mysqli $l, string $sql): string {
    if (stripos($sql, 'utf8mb4_0900_ai_ci') !== false && !sql_serveur_a_0900($l)) {
        $sql = str_ireplace('utf8mb4_0900_ai_ci', 'utf8mb4_unicode_ci', $sql);
    }
    // Date d'inscription par défaut : sans effet pour SIGES, qui fournit
    // toujours la date (CURDATE()) à l'enregistrement d'une inscription.
    if (stripos($sql, 'DEFAULT (curdate())') !== false && !sql_serveur_defaut_expression($l)) {
        $sql = str_ireplace('DEFAULT (curdate())', 'DEFAULT NULL', $sql);
    }
    return $sql;
}
