<?php
// =====================================================================
//  bd/assoc/maj_assoc.php
//  Applique à une base annuaire DÉJÀ installée les évolutions de schéma
//  postérieures à sa création (colonnes / tables ajoutées à
//  schema_assoc.sql après coup). Idempotent : relançable sans risque.
//
//  À lancer après une mise à jour du code :
//    php bd/assoc/maj_assoc.php
//
//  (schema_assoc.sql via installer.php crée déjà tout pour une NOUVELLE
//  installation ; ce script ne sert qu'aux bases existantes.)
// =====================================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion_assoc.php';

if (PHP_SAPI !== 'cli') header('Content-Type: text/plain; charset=utf-8');
if (!annuaire_dispo()) { die("Annuaire absent — rien à mettre à jour.\n"); }

global $link_assoc;
$db = DB_NAME_ASSOC;

function col_existe(string $table, string $col): bool {
    return (bool) assoc_val(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?",
        [$table, $col]
    );
}
function table_existe(string $table): bool {
    return (bool) assoc_val(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?", [$table]
    );
}

$fait = [];

echo "=== Mise à jour de l'annuaire ($db) ===\n";

// ── 2FA : membre.totp_secret / membre.totp_actif ──────────────────
if (!col_existe('membre', 'totp_secret')) {
    mysqli_query($link_assoc, "ALTER TABLE `membre` ADD COLUMN `totp_secret` varchar(64) DEFAULT NULL AFTER `actif`");
    $fait[] = "membre.totp_secret ajoutée";
}
if (!col_existe('membre', 'totp_actif')) {
    mysqli_query($link_assoc, "ALTER TABLE `membre` ADD COLUMN `totp_actif` tinyint(1) NOT NULL DEFAULT 0 AFTER `totp_secret`");
    $fait[] = "membre.totp_actif ajoutée";
}

// ── Coordonnées : membre.tel (récupération de mot de passe) ──────
if (!col_existe('membre', 'tel')) {
    mysqli_query($link_assoc, "ALTER TABLE `membre` ADD COLUMN `tel` varchar(30) DEFAULT NULL AFTER `email`");
    $fait[] = "membre.tel ajoutée";
}

// ── NIU : etablissement.niu_sigle (code 3 lettres dans le NIU) ───
if (!col_existe('etablissement', 'niu_sigle')) {
    mysqli_query($link_assoc, "ALTER TABLE `etablissement` ADD COLUMN `niu_sigle` varchar(3) DEFAULT NULL AFTER `sigle`");
    // Défaut : 3 premières lettres alphanumériques du sigle (ou du code).
    mysqli_query($link_assoc,
        "UPDATE etablissement
         SET niu_sigle = UPPER(LEFT(REGEXP_REPLACE(COALESCE(NULLIF(sigle,''), code), '[^A-Za-z0-9]', ''), 3))
         WHERE niu_sigle IS NULL OR niu_sigle = ''");
    $fait[] = "etablissement.niu_sigle ajoutée + initialisée";
}

// ── Hiérarchie : membre.proprietaire ─────────────────────────────
//  Le « propriétaire » (compte fondateur) est le seul habilité à accorder
//  ou retirer le niveau superadmin à un autre membre. Sur une base
//  existante, on désigne le plus ancien superadmin (ou, à défaut, le plus
//  ancien membre actif).
if (!col_existe('membre', 'proprietaire')) {
    mysqli_query($link_assoc, "ALTER TABLE `membre` ADD COLUMN `proprietaire` tinyint(1) NOT NULL DEFAULT 0 AFTER `actif`");
    $fait[] = "membre.proprietaire ajoutée";
}
if (!(int) assoc_val("SELECT COUNT(*) FROM membre WHERE proprietaire=1")) {
    $cible = (int) (assoc_val(
        "SELECT m.id FROM membre m
         JOIN membre_acces a ON a.id_membre = m.id
         WHERE a.actif=1 AND a.id_etablissement IS NULL AND a.plein_acces=1 AND m.actif=1
         ORDER BY m.id LIMIT 1"
    ) ?? assoc_val("SELECT id FROM membre WHERE actif=1 ORDER BY id LIMIT 1") ?? 0);
    if ($cible) {
        assoc_exec("UPDATE membre SET proprietaire=1 WHERE id=?", [$cible]);
        $log = assoc_val("SELECT login FROM membre WHERE id=?", [$cible]);
        $fait[] = "propriétaire désigné : « $log » (#$cible)";
    }
}

// ── Questions secrètes des membres (récupération de mot de passe) ──
if (!table_existe('membre_question_secrete')) {
    mysqli_query($link_assoc,
        "CREATE TABLE `membre_question_secrete` (
           `id_membre`    int          NOT NULL,
           `numero`       tinyint(1)   NOT NULL,
           `question`     varchar(160) NOT NULL,
           `reponse_hash` varchar(255) NOT NULL,
           `maj_le`       datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
           PRIMARY KEY (`id_membre`,`numero`),
           CONSTRAINT `fk_mqs_membre` FOREIGN KEY (`id_membre`) REFERENCES `membre` (`id`) ON DELETE CASCADE
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $fait[] = "table membre_question_secrete créée";
}

// ── Limitation des connexions : table login_echec ─────────────────
if (!table_existe('login_echec')) {
    mysqli_query($link_assoc,
        "CREATE TABLE `login_echec` (
           `id`    bigint      NOT NULL AUTO_INCREMENT,
           `login` varchar(50) DEFAULT NULL,
           `ip`    varchar(45) DEFAULT NULL,
           `date`  datetime    NOT NULL DEFAULT CURRENT_TIMESTAMP,
           PRIMARY KEY (`id`),
           KEY `k_login` (`login`,`date`),
           KEY `k_ip` (`ip`,`date`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $fait[] = "table login_echec créée";
}

// ── Journal d'audit unifié + cache géo IP ────────────────────────────
if (!table_existe('journal_audit')) {
    mysqli_query($link_assoc,
        "CREATE TABLE `journal_audit` (
           `id`               bigint       NOT NULL AUTO_INCREMENT,
           `date`             datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
           `evenement`        varchar(20)  NOT NULL,
           `action`           varchar(60)  DEFAULT NULL,
           `cible`            varchar(255) DEFAULT NULL,
           `acteur_type`      enum('membre','user','inconnu') NOT NULL DEFAULT 'inconnu',
           `acteur_id`        int          DEFAULT NULL,
           `acteur_login`     varchar(60)  DEFAULT NULL,
           `acteur_nom`       varchar(120) DEFAULT NULL,
           `role`             varchar(30)  DEFAULT NULL,
           `id_etablissement` int          DEFAULT NULL,
           `ip`               varchar(45)  DEFAULT NULL,
           `ua_navigateur`    varchar(60)  DEFAULT NULL,
           `ua_os`            varchar(60)  DEFAULT NULL,
           `ua_appareil`      varchar(12)  DEFAULT NULL,
           `ua_brut`          varchar(400) DEFAULT NULL,
           `geo_pays`         varchar(60)  DEFAULT NULL,
           `geo_region`       varchar(80)  DEFAULT NULL,
           `geo_ville`        varchar(80)  DEFAULT NULL,
           `geo_operateur`    varchar(120) DEFAULT NULL,
           PRIMARY KEY (`id`),
           KEY `k_date`   (`date`),
           KEY `k_etab`   (`id_etablissement`,`date`),
           KEY `k_acteur` (`acteur_type`,`acteur_login`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $fait[] = "table journal_audit créée";

    // Reprise unique de l'ancien journal_action (traçabilité conservée).
    if (table_existe('journal_action')
        && (int) assoc_val("SELECT COUNT(*) FROM journal_action")
        && !(int) assoc_val("SELECT COUNT(*) FROM journal_audit")) {
        mysqli_query($link_assoc,
            "INSERT INTO journal_audit
               (date, evenement, action, cible, acteur_type, acteur_id, id_etablissement, ip)
             SELECT j.date,
                    CASE j.action WHEN 'connexion_membre' THEN 'connexion'
                                  WHEN 'deconnexion_membre' THEN 'deconnexion'
                                  ELSE 'action' END,
                    CASE WHEN j.action IN ('connexion_membre','deconnexion_membre') THEN NULL ELSE j.action END,
                    j.cible, 'membre', j.id_membre, j.id_etablissement, j.ip
             FROM journal_action j");
        $n = mysqli_affected_rows($link_assoc);
        $fait[] = "journal_action repris ($n ligne(s)) dans journal_audit";
    }
}
if (!table_existe('geo_ip_cache')) {
    mysqli_query($link_assoc,
        "CREATE TABLE `geo_ip_cache` (
           `ip`        varchar(45)  NOT NULL,
           `pays`      varchar(60)  DEFAULT NULL,
           `region`    varchar(80)  DEFAULT NULL,
           `ville`     varchar(80)  DEFAULT NULL,
           `operateur` varchar(120) DEFAULT NULL,
           `ok`        tinyint(1)   NOT NULL DEFAULT 0,
           `maj_le`    datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
           PRIMARY KEY (`ip`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $fait[] = "table geo_ip_cache créée";
}

// ── Module « Privilèges » : règles d'accès par école ─────────────────
if (!table_existe('acces_regle')) {
    mysqli_query($link_assoc,
        "CREATE TABLE `acces_regle` (
           `id`               bigint      NOT NULL AUTO_INCREMENT,
           `id_etablissement` int         NOT NULL,
           `portee`           enum('role','user') NOT NULL,
           `cible`            varchar(60)  NOT NULL,
           `cle`              varchar(120) NOT NULL,
           `niveau`           enum('masque','lecture','ecriture') NOT NULL,
           `maj_le`           datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
           PRIMARY KEY (`id`),
           UNIQUE KEY `u_regle` (`id_etablissement`,`portee`,`cible`,`cle`),
           KEY `k_etab` (`id_etablissement`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $fait[] = "table acces_regle créée";
}

if ($fait) {
    foreach ($fait as $f) echo "  OK  $f\n";
} else {
    echo "  — déjà à jour, rien à faire.\n";
}
echo "=== Terminé. ===\n";
