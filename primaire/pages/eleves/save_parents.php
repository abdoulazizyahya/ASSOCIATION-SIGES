<?php
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../noyau/connexion.php';
require_once __DIR__ . '/../../../noyau/fonctions.php';
exiger_role(['DIRECTEUR','SECRETAIRE']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') rediriger('primaire/pages/eleves/liste.php');

// Jeton dédié (comme ABZ_MBE) — pas le csrf_verifier() générique, les
// formulaires des modales Parents/Tuteur de voir.php envoient $_SESSION['csrf_parents'].
session_init();
$csrf_ok = isset($_POST['csrf'], $_SESSION['csrf_parents']) && hash_equals($_SESSION['csrf_parents'], $_POST['csrf']);
if (!$csrf_ok) {
    flash_set('erreur', 'Jeton de sécurité invalide, veuillez réessayer.');
    rediriger('primaire/pages/eleves/liste.php');
}

$id = (int) post('id_eleve');
if (!db_val("SELECT COUNT(*) FROM eleve WHERE id_eleve=?", [$id])) {
    flash_set('erreur', 'Élève introuvable.');
    rediriger('primaire/pages/eleves/liste.php');
}

$action = post('action');

/**
 * Crée ou met à jour le parent d'un sexe donné (Masculin=père, Feminin=mère)
 * pour cet élève — jaynitaare n'a qu'une seule table `parent` (pas de
 * id_pere/id_mere sur eleve comme ABZ_MBE), un rôle = un sexe.
 */
function upsert_parent(int $id_eleve, string $sexe, string $nom, string $prenom, string $profession, string $adresse, string $telephone = ''): void {
    $nom = trim($nom); $prenom = trim($prenom); $profession = trim($profession); $adresse = trim($adresse); $telephone = trim($telephone);
    $existant = db_one("SELECT id FROM parent WHERE id_eleve=? AND sexe=? LIMIT 1", [$id_eleve, $sexe]);

    if ($nom === '' && $prenom === '' && $profession === '' && $adresse === '' && $telephone === '') {
        if ($existant) db_exec("DELETE FROM parent WHERE id=?", [$existant['id']]);
        return;
    }
    if ($existant) {
        db_exec("UPDATE parent SET nom=?, prenom=?, profession=?, adresse=? WHERE id=?",
            [$nom, $prenom, $profession, $adresse, $existant['id']]);
        $id_p = (int) $existant['id'];
    } else {
        db_exec("INSERT INTO parent (nom, prenom, profession, adresse, sexe, id_eleve) VALUES (?, ?, ?, ?, ?, ?)",
            [$nom, $prenom, $profession, $adresse, $sexe, $id_eleve]);
        $id_p = (int) db_last_id();
    }
    parent_enregistrer_tel($id_p, $telephone);
}

/** Téléphone du parent / tuteur (migration v71) : normalisé s'il est reconnu, sinon tel que saisi. */
function parent_enregistrer_tel(int $id_parent, string $telephone): void {
    if (!db_colonne_existe('parent', 'telephone')) return;
    $telephone = trim($telephone);
    db_exec("UPDATE parent SET telephone=? WHERE id=?", [$telephone === '' ? null : (tel_normaliser_cm($telephone) ?? mb_substr($telephone, 0, 30)), $id_parent]);
}

if ($action === 'tuteur_ajouter') {
    $nom = post('tuteur_nom');
    if ($nom !== '') {
        db_exec("INSERT INTO parent (nom, prenom, profession, adresse, sexe, id_eleve) VALUES (?, ?, ?, ?, 'titeur', ?)",
            [$nom, post('tuteur_prenom'), post('tuteur_profession'), post('tuteur_adresse'), $id]);
        parent_enregistrer_tel((int) db_last_id(), post('tuteur_telephone'));
        flash_set('succes', 'Tuteur ajouté.');
    }
} elseif ($action === 'tuteur_supprimer') {
    db_exec("DELETE FROM parent WHERE id=? AND id_eleve=? AND sexe='titeur'", [(int) post('id'), $id]);
    flash_set('succes', 'Tuteur retiré.');
} else {
    upsert_parent($id, 'Masculin', post('pere_nom'), post('pere_prenom'), post('pere_profession'), post('pere_adresse'), post('pere_telephone'));
    upsert_parent($id, 'Feminin',  post('mere_nom'),  post('mere_prenom'),  post('mere_profession'),  post('mere_adresse'),  post('mere_telephone'));
    flash_set('succes', 'Informations des parents mises à jour.');
}

rediriger('primaire/pages/eleves/voir.php?id=' . $id);
