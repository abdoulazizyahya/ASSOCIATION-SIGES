<?php
// secondaire/commun/pages/billets/_billets_lib.php — Billets & autorisations
// (10/11/2026), commun au primaire et au secondaire : billet de sortie,
// billet d'entrée (retard), billet d'infirmerie, autorisation d'absence d'un
// membre du personnel. Chaque billet est numéroté par type et par année
// (BS-2026-0001…), enregistré dans un registre, et imprimé en deux parties :
// la SOUCHE (gardée par l'école) et le VOLET (remis à l'intéressé).
require_once __DIR__ . '/../../../../noyau/partage/ecole_donnees.php';

const BILLET_TYPES = [
    'sortie'       => ['libelle' => 'Billet de sortie',       'prefixe' => 'BS', 'icone' => 'box-arrow-right', 'pour' => 'eleve',
                       'heure' => 'Heure de sortie',  'heure2' => 'Retour prévu à', 'personne' => 'Accompagné(e) de', 'couleur' => '#1a3c6b'],
    'entree'       => ['libelle' => 'Billet d\'entrée',       'prefixe' => 'BE', 'icone' => 'box-arrow-in-right', 'pour' => 'eleve',
                       'heure' => 'Heure d\'arrivée', 'heure2' => '',               'personne' => '',                 'couleur' => '#0f766e'],
    'infirmerie'   => ['libelle' => 'Billet d\'infirmerie',   'prefixe' => 'BI', 'icone' => 'heart-pulse', 'pour' => 'eleve',
                       'heure' => 'Heure de passage', 'heure2' => 'Retour en classe à', 'personne' => 'Reçu(e) par', 'couleur' => '#b91c1c'],
    'autorisation' => ['libelle' => 'Autorisation d\'absence', 'prefixe' => 'AA', 'icone' => 'person-badge', 'pour' => 'personnel',
                       'heure' => 'De (heure)',       'heure2' => 'À (heure)',       'personne' => 'Remplacé(e) par',  'couleur' => '#7c3aed'],
];

/** Rôles autorisés à délivrer des billets. */
function billets_roles(): array {
    return ed_secondaire() ? ['ADMIN', 'PROVISEUR', 'FONDATEUR', 'CENSEUR', 'SG', 'SECRETAIRE'] : ['DIRECTEUR', 'SECRETAIRE', 'FONDATEUR'];
}

/** Crée la table du registre si elle n'existe pas encore (aucune migration à lancer). */
function billets_assurer_table(): void {
    static $fait = false;
    if ($fait) return;
    try { db_exec("CREATE TABLE IF NOT EXISTS `billet` (
        `id` int unsigned NOT NULL AUTO_INCREMENT,
        `type` varchar(20) NOT NULL,
        `numero` varchar(30) NOT NULL,
        `annee` varchar(20) NOT NULL,
        `id_eleve` int unsigned DEFAULT NULL,
        `id_personnel` int unsigned DEFAULT NULL,
        `nom` varchar(150) NOT NULL,
        `classe` varchar(100) DEFAULT NULL,
        `identifiant` varchar(60) DEFAULT NULL,
        `date_billet` date NOT NULL,
        `date_fin` date DEFAULT NULL,
        `heure` varchar(5) DEFAULT NULL,
        `heure2` varchar(5) DEFAULT NULL,
        `personne` varchar(150) DEFAULT NULL,
        `motif` varchar(255) DEFAULT NULL,
        `signataire` varchar(150) DEFAULT NULL,
        `sms` varchar(40) DEFAULT NULL,
        `cree_par` varchar(80) DEFAULT NULL,
        `cree_le` datetime NOT NULL,
        `annule` tinyint(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_billet_numero` (`numero`),
        KEY `k_billet_type_annee` (`type`, `annee`),
        KEY `k_billet_eleve` (`id_eleve`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); } catch (\Throwable $e) { /* lecture seule : la table sera créée par un compte en écriture */ }
    $fait = true;
}

/** Numéro suivant : PRÉFIXE-AAAA-NNNN (AAAA = 1re année de l'année scolaire). */
function billet_numero_suivant(string $type, string $annee): string {
    $pref = BILLET_TYPES[$type]['prefixe'] . '-' . (preg_match('/(\d{4})/', $annee, $m) ? $m[1] : date('Y')) . '-';
    $max = (int) db_val("SELECT MAX(CAST(SUBSTRING(numero, ?) AS UNSIGNED)) FROM billet WHERE numero LIKE ?", [strlen($pref) + 1, $pref . '%']);
    return $pref . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
}

function billet_charger(int $id): ?array {
    billets_assurer_table();
    return db_one("SELECT * FROM billet WHERE id=?", [$id]);
}
