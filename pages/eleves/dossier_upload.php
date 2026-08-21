<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_role(['DIRECTEUR','SECRETAIRE']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') rediriger('pages/eleves/liste.php');
csrf_verifier();

$id = (int) post('id_eleve');
if (!db_val("SELECT COUNT(*) FROM eleve WHERE id_eleve=?", [$id])) {
    flash_set('erreur', 'Élève introuvable.');
    rediriger('pages/eleves/liste.php');
}

$types_valides = ['acte_naissance','carnet_vaccination','bulletin','document_transfert','photo_4x4','autre'];
$type = post('type_document');
if (!in_array($type, $types_valides, true)) {
    flash_set('erreur', 'Type de document invalide.');
    rediriger('pages/eleves/voir.php?id=' . $id);
}

$fichier = sauver_document_dossier('fichier');
if (!$fichier) {
    flash_set('erreur', "Fichier invalide ou trop volumineux (PDF/JPG/PNG uniquement, 750 Ko max).");
    rediriger('pages/eleves/voir.php?id=' . $id);
}

db_exec(
    "INSERT INTO dossier_eleve (id_eleve, type_document, libelle, fichier, ajoute_par) VALUES (?, ?, ?, ?, ?)",
    [$id, $type, post('libelle') ?: null, $fichier, $_SESSION['user_id'] ?? null]
);

flash_set('succes', 'Document ajouté au dossier de l\'élève.');
rediriger('pages/eleves/voir.php?id=' . $id);
