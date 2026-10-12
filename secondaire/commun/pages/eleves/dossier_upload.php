<?php
// secondaire/commun/pages/eleves/dossier_upload.php — Ajout d'un document au dossier de
// l'élève (secondaire/commun/pages/eleves/voir.php, carte "Dossier de l'élève").
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'FONDATEUR', 'CENSEUR', 'SG', 'SECRETAIRE']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') rediriger('secondaire/commun/pages/eleves/liste.php');
csrf_verifier();

$id = (int) post('id_eleve');
if (!db_val("SELECT COUNT(*) FROM eleve WHERE id=?", [$id])) {
    flash_set('erreur', 'Élève introuvable.');
    rediriger('secondaire/commun/pages/eleves/liste.php');
}
exiger_acces_eleve_secondaire($id);

$types_valides = ['acte_naissance','carnet_vaccination','bulletin','document_transfert','photo_4x4','autre'];
$type = post('type_document');
if (!in_array($type, $types_valides, true)) {
    flash_set('erreur', 'Type de document invalide.');
    rediriger('secondaire/commun/pages/eleves/voir.php?id=' . $id);
}

$fichier = sauver_document_dossier('fichier');
if (!$fichier) {
    flash_set('erreur', 'Fichier invalide ou trop volumineux (PDF/JPG/PNG uniquement, 750 Ko max).');
    rediriger('secondaire/commun/pages/eleves/voir.php?id=' . $id);
}

db_exec(
    "INSERT INTO dossier_eleve (id_eleve, type_document, libelle, fichier, ajoute_par) VALUES (?, ?, ?, ?, ?)",
    [$id, $type, post('libelle') ?: null, $fichier, $_SESSION['user_id'] ?? null]
);

flash_set('succes', "Document ajouté au dossier de l'élève.");
rediriger('secondaire/commun/pages/eleves/voir.php?id=' . $id);
