<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_role(['DIRECTEUR','SECRETAIRE']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') rediriger('pages/eleves/liste.php');

// Jeton dédié (comme ABZ_MBE) — pas le csrf_verifier() générique, les
// formulaires des modales Parents/Tuteur de voir.php envoient $_SESSION['csrf_parents'].
session_init();
$csrf_ok = isset($_POST['csrf'], $_SESSION['csrf_parents']) && hash_equals($_SESSION['csrf_parents'], $_POST['csrf']);
if (!$csrf_ok) {
    flash_set('erreur', 'Jeton de sécurité invalide, veuillez réessayer.');
    rediriger('pages/eleves/liste.php');
}

$id = (int) post('id_eleve');
if (!db_val("SELECT COUNT(*) FROM eleve WHERE id_eleve=?", [$id])) {
    flash_set('erreur', 'Élève introuvable.');
    rediriger('pages/eleves/liste.php');
}

$action = post('action');

/**
 * Crée ou met à jour le parent d'un sexe donné (Masculin=père, Feminin=mère)
 * pour cet élève — jaynitaare n'a qu'une seule table `parent` (pas de
 * id_pere/id_mere sur eleve comme ABZ_MBE), un rôle = un sexe.
 */
function upsert_parent(int $id_eleve, string $sexe, string $nom, string $prenom, string $profession, string $adresse): void {
    $nom = trim($nom); $prenom = trim($prenom); $profession = trim($profession); $adresse = trim($adresse);
    $existant = db_one("SELECT id FROM parent WHERE id_eleve=? AND sexe=? LIMIT 1", [$id_eleve, $sexe]);

    if ($nom === '' && $prenom === '' && $profession === '' && $adresse === '') {
        if ($existant) db_exec("DELETE FROM parent WHERE id=?", [$existant['id']]);
        return;
    }
    if ($existant) {
        db_exec("UPDATE parent SET nom=?, prenom=?, profession=?, adresse=? WHERE id=?",
            [$nom, $prenom, $profession, $adresse, $existant['id']]);
    } else {
        db_exec("INSERT INTO parent (nom, prenom, profession, adresse, sexe, id_eleve) VALUES (?, ?, ?, ?, ?, ?)",
            [$nom, $prenom, $profession, $adresse, $sexe, $id_eleve]);
    }
}

if ($action === 'tuteur_ajouter') {
    $nom = post('tuteur_nom');
    if ($nom !== '') {
        db_exec("INSERT INTO parent (nom, prenom, profession, adresse, sexe, id_eleve) VALUES (?, ?, ?, ?, 'titeur', ?)",
            [$nom, post('tuteur_prenom'), post('tuteur_profession'), post('tuteur_adresse'), $id]);
        flash_set('succes', 'Tuteur ajouté.');
    }
} elseif ($action === 'tuteur_supprimer') {
    db_exec("DELETE FROM parent WHERE id=? AND id_eleve=? AND sexe='titeur'", [(int) post('id'), $id]);
    flash_set('succes', 'Tuteur retiré.');
} else {
    upsert_parent($id, 'Masculin', post('pere_nom'), post('pere_prenom'), post('pere_profession'), post('pere_adresse'));
    upsert_parent($id, 'Feminin',  post('mere_nom'),  post('mere_prenom'),  post('mere_profession'),  post('mere_adresse'));
    flash_set('succes', 'Informations des parents mises à jour.');
}

rediriger('pages/eleves/voir.php?id=' . $id);
