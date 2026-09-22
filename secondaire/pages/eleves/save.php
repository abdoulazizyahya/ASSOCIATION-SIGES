<?php
// secondaire/pages/eleves/save.php — POST handler de secondaire/pages/eleves/form.php.
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'FONDATEUR', 'CENSEUR', 'SG', 'SECRETAIRE']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') rediriger('secondaire/pages/eleves/liste.php');
csrf_verifier();

$id        = (int) post('id');
$id_annee  = (int) post('id_annee');
$nom       = post('nom');
$prenom    = post('prenom') ?: null;
$sexe      = post('sexe') === 'F' ? 'F' : 'M';
$date_naiss = post('date_naiss') ?: null;
$lieu_naiss = post('lieu_naiss') ?: null;
$region_naiss = post('region_naiss') ?: null;
$departement_naiss = post('departement_naiss') ?: null;
$arrondissement_naiss = post('arrondissement_naiss') ?: null;
$niu       = post('niu') ?: null;
$telephone = post('telephone') ?: null;
$adresse   = post('adresse') ?: null;
$id_classe = (int) post('id_classe');
$statut_insc = post('statut_insc') ?: 'Nouveau';
$id_serie  = (int) post('id_serie') ?: null;

if ($nom === '') {
    flash_set('erreur', 'Le nom est obligatoire.');
    rediriger('secondaire/pages/eleves/form.php' . ($id ? "?id=$id" : ''));
}

if ($id) {
    $existe = db_one("SELECT id FROM eleve WHERE id=?", [$id]);
    if (!$existe) { flash_set('erreur', 'Élève introuvable.'); rediriger('secondaire/pages/eleves/liste.php'); }
    db_exec(
        "UPDATE eleve SET nom=?, prenom=?, sexe=?, date_naiss=?, lieu_naiss=?, region_naiss=?, departement_naiss=?,
                arrondissement_naiss=?, niu=?, telephone=?, adresse=? WHERE id=?",
        [$nom, $prenom, $sexe, $date_naiss, $lieu_naiss, $region_naiss, $departement_naiss,
         $arrondissement_naiss, $niu, $telephone, $adresse, $id]
    );
    $msg = 'Élève modifié.';
} else {
    $matricule = gen_matricule_secondaire();
    db_exec(
        "INSERT INTO eleve (matricule, nom, prenom, sexe, date_naiss, lieu_naiss, region_naiss, departement_naiss,
                arrondissement_naiss, niu, telephone, adresse, statut)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'actif')",
        [$matricule, $nom, $prenom, $sexe, $date_naiss, $lieu_naiss, $region_naiss, $departement_naiss,
         $arrondissement_naiss, $niu, $telephone, $adresse]
    );
    $id  = (int) db_last_id();
    $msg = 'Élève créé — matricule ' . $matricule . '.';
}

if ($id_classe && $id_annee) {
    $insc = db_one("SELECT id FROM inscription WHERE id_eleve=? AND id_annee=?", [$id, $id_annee]);
    if ($insc) {
        db_exec("UPDATE inscription SET id_classe=?, statut=?, id_serie=? WHERE id=?", [$id_classe, $statut_insc, $id_serie, $insc['id']]);
    } else {
        db_exec("INSERT INTO inscription (id_eleve, id_classe, id_annee, statut, id_serie, date_inscription) VALUES (?, ?, ?, ?, ?, CURDATE())",
                [$id, $id_classe, $id_annee, $statut_insc, $id_serie]);
    }
} elseif ($id_annee) {
    db_exec("DELETE FROM inscription WHERE id_eleve=? AND id_annee=?", [$id, $id_annee]);
}

flash_set('succes', $msg);
rediriger('secondaire/pages/eleves/voir.php?id=' . $id);
