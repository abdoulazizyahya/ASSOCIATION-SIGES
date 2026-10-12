<?php
// secondaire/commun/pages/eleves/dossier_supprimer.php — retire un document du
// dossier de l'élève (fichier physique + ligne en base). Porté d'ABZ_MBE ;
// POST + csrf_verifier() (et non un lien GET) : la vérification CSRF standard
// applique aussi la lecture seule des visites association.
require_once __DIR__ . '/../../../../config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'FONDATEUR', 'CENSEUR', 'SG', 'SECRETAIRE']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') rediriger('secondaire/commun/pages/eleves/liste.php');
csrf_verifier();

$doc = db_one("SELECT * FROM dossier_eleve WHERE id=?", [(int) post('id')]);
if ($doc) {
    exiger_acces_eleve_secondaire((int) $doc['id_eleve']);
    $chemin = __DIR__ . '/../../../../assets/uploads/dossiers_eleves/' . $doc['fichier'];
    db_exec("DELETE FROM dossier_eleve WHERE id=?", [(int) $doc['id']]);
    if (is_file($chemin)) @unlink($chemin);
    flash_set('succes', 'Document retiré du dossier.');
    rediriger('secondaire/commun/pages/eleves/voir.php?id=' . (int) $doc['id_eleve']);
}
rediriger('secondaire/commun/pages/eleves/liste.php');
