<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_connexion();

$role = role_connecte();
$user = utilisateur_connecte();

$full_access = in_array($role, ['ADMIN', 'CENSEUR']);
$annee_act   = get_annee_active();
$id_annee    = (int)($annee_act['id'] ?? 0);
$val_annee   = $annee_act['libelle'] ?? '';

$classes_access = [];
if ($full_access) {
    $classes_access = db_all("SELECT id FROM classe WHERE archivee=0");
} elseif ($role === 'SG') {
    $mat_ens = get_matricule_ens_connecte();
    $classes_access = db_all(
        "SELECT IDClasses AS id FROM sg WHERE matricule_ens=? AND val_annee=?",
        [$mat_ens, $val_annee]
    );
} elseif ($role === 'ENSEIGNANT') {
    $mat_ens = get_matricule_ens_connecte();
    $classes_access = db_all(
        "SELECT IDClasses AS id FROM enseignat_principal WHERE matricule_ens=? AND val_annee=?",
        [$mat_ens, $val_annee]
    );
}
$ids_classes_ok = array_column($classes_access, 'id');

if (empty($ids_classes_ok)) {
    flash_set('erreur', 'Accès non autorisé.');
    rediriger('dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rediriger('pages/discipline/index.php');
}

$csrf_ok = isset($_POST['csrf'], $_SESSION['csrf_discipline']) && hash_equals($_SESSION['csrf_discipline'], $_POST['csrf']);
if (!$csrf_ok) {
    flash_set('erreur', 'Jeton de sécurité invalide, veuillez réessayer.');
    rediriger('pages/discipline/index.php');
}

$type      = in_array($_POST['type'] ?? '', ['absences', 'exclusions', 'retards'], true) ? $_POST['type'] : '';
$id_classe = (int)($_POST['id_classe'] ?? 0);
$id_trim   = (int)($_POST['id_trim']   ?? 0);
$back      = $_POST['back'] ?? 'index.php';

if (!$type || !$id_classe || !$id_trim) {
    flash_set('erreur', 'Paramètres manquants.');
    rediriger('pages/discipline/index.php');
}
if (!in_array($id_classe, $ids_classes_ok, true)) {
    flash_set('erreur', 'Accès non autorisé à cette classe.');
    rediriger('pages/discipline/index.php');
}

// Matricules réellement inscrits dans cette classe (sécurité : ignorer tout ajout arbitraire)
$matricules_ok = array_column(
    db_all(
        "SELECT e.matricule FROM eleve e
         JOIN inscription i ON i.id_eleve=e.id AND i.id_classe=? AND i.id_annee=?
         WHERE e.statut='actif'",
        [$id_classe, $id_annee]
    ),
    'matricule'
);

$n = 0;

if ($type === 'absences') {
    $jus = $_POST['jus'] ?? [];
    $nj  = $_POST['nj']  ?? [];
    foreach ($matricules_ok as $mat) {
        $hj  = max(0, (int)($jus[$mat] ?? 0));
        $hnj = max(0, (int)($nj[$mat]  ?? 0));
        db_exec(
            "INSERT INTO absence (mat_elv, id_trim, IDClasses, nbre_heure_non_jus, nbre_heure_jus, val_annee)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE nbre_heure_non_jus=VALUES(nbre_heure_non_jus), nbre_heure_jus=VALUES(nbre_heure_jus)",
            [$mat, $id_trim, $id_classe, $hnj, $hj, $val_annee]
        );
        $n++;
    }
} elseif ($type === 'retards') {
    $ret = $_POST['retards'] ?? [];
    foreach ($matricules_ok as $mat) {
        $r = max(0, (int)($ret[$mat] ?? 0));
        db_exec(
            "INSERT INTO retard (mat_elv, id_trim, IDClasses, nbre_retards, val_annee)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE nbre_retards=VALUES(nbre_retards)",
            [$mat, $id_trim, $id_classe, $r, $val_annee]
        );
        $n++;
    }
} elseif ($type === 'exclusions') {
    // La table `exclusion` n'a pas de contrainte UNIQUE (log d'incidents possible).
    // Ici on traite la saisie comme UN total agrégé par élève/trimestre/classe :
    // on remplace les éventuelles lignes existantes par une seule ligne à jour.
    $jours = $_POST['jours'] ?? [];
    foreach ($matricules_ok as $mat) {
        $j = max(0, (int)($jours[$mat] ?? 0));
        db_exec(
            "DELETE FROM exclusion WHERE mat_elv=? AND id_trim=? AND classe=? AND val_annee=?",
            [$mat, $id_trim, $id_classe, $val_annee]
        );
        if ($j > 0) {
            db_exec(
                "INSERT INTO exclusion (mat_elv, id_trim, classe, nbre_jours, val_annee) VALUES (?, ?, ?, ?, ?)",
                [$mat, $id_trim, $id_classe, $j, $val_annee]
            );
        }
        $n++;
    }
}

flash_set('succes', $n . ' fiche(s) mise(s) à jour.');
header('Location: ' . APP_URL . '/pages/discipline/' . $back);
exit;
