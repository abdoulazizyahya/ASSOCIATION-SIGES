<?php
// pages/eleves/matricule_config.php — enregistrement du format de matricule
// de l'école (table matricule_config, migration v52). Appelé par le
// formulaire de l'onglet « Import & matricules » (pages/eleves/_eleves_outils.php).
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_role(['DIRECTEUR', 'SECRETAIRE']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') rediriger('pages/eleves/liste.php?statut=outils');
csrf_verifier();

$mode         = post('mode') === 'manuel' ? 'manuel' : 'auto';
$format       = trim(post('format')) ?: '{AA}{NIV}{SEQ}';
$longueur_seq = max(2, min(6, (int) post('longueur_seq')));
$sequence_par = in_array(post('sequence_par'), ['annee_niveau', 'annee', 'globale'], true)
              ? post('sequence_par') : 'annee_niveau';

// Garde-fous sur le format (mode auto uniquement) :
if ($mode === 'auto') {
    if (strpos($format, '{SEQ}') === false) {
        flash_set('erreur', 'Le format doit contenir le jeton {SEQ} (le numéro d\'ordre).');
        rediriger('pages/eleves/liste.php?statut=outils');
    }
    // Caractères autorisés : jetons + lettres/chiffres/ - _ / . espace
    $sans_jetons = str_replace(['{AAAA}', '{AA}', '{NIV}', '{SEQ}'], '', $format);
    if (!preg_match('~^[A-Za-z0-9 ._/\-]*$~', $sans_jetons)) {
        flash_set('erreur', 'Le format contient des caractères non autorisés (utilisez lettres, chiffres, - _ / . espace).');
        rediriger('pages/eleves/liste.php?statut=outils');
    }
}

// Table présente ? (base migrée v52)
try {
    $existe = (bool) db_val("SELECT COUNT(*) FROM information_schema.tables
                             WHERE table_schema = DATABASE() AND table_name = 'matricule_config'");
} catch (\Throwable $e) { $existe = false; }

if (!$existe) {
    flash_set('erreur', "La table de configuration n'existe pas encore sur cette base — lancez la migration v52 (bd/assoc/migrer_toutes_ecoles.php).");
    rediriger('pages/eleves/liste.php?statut=outils');
}

db_exec(
    "INSERT INTO matricule_config (id, mode, format, longueur_seq, sequence_par)
     VALUES (1, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE mode=VALUES(mode), format=VALUES(format),
                             longueur_seq=VALUES(longueur_seq), sequence_par=VALUES(sequence_par)",
    [$mode, $format, $longueur_seq, $sequence_par]
);

flash_set('succes', $mode === 'manuel'
    ? 'Matricule en saisie manuelle — il sera un champ libre (éventuellement vide) dans la fiche élève.'
    : 'Format de matricule enregistré (exemple : ' . matricule_exemple($format, $longueur_seq, get_annee_active()['val_annee'] ?? '') . ').');
rediriger('pages/eleves/liste.php?statut=outils');
