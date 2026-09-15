<?php
// secondaire/pages/enseignants/save.php — POST handler de
// secondaire/pages/enseignants/form.php.
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'CENSEUR']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') rediriger('secondaire/pages/enseignants/liste.php');
csrf_verifier();

$mat        = (int) post('mat');
$civilite   = post('civilite') ?: 'M.';
$nom        = post('nom');
$prenom     = post('prenom') ?: null;
$sexe       = post('sexe') === 'Feminin' ? 'Feminin' : 'Masculin';
$date_naiss = post('date_naiss') ?: null;
$lieu_naiss = post('lieu_naiss') ?: null;
$tel        = post('tel') ?: null;
$mail       = post('mail') ?: null;
$situation  = in_array(post('situation'), ['Celibataire', 'Marie', 'Divorce', 'Veuf'], true) ? post('situation') : null;
$fonction   = post('fonction') ?: null;
$grade      = post('grade') ?: null;
$matiere_enseignee = post('matiere_enseignee') ?: null;
$diplome    = post('diplome') ?: null;
$specialite = post('specialite') ?: null;

if ($nom === '') {
    flash_set('erreur', 'Le nom est obligatoire.');
    rediriger('secondaire/pages/enseignants/form.php' . ($mat ? "?id=$mat" : ''));
}

if ($mat) {
    $existe = db_one("SELECT matricule_ens FROM enseignant WHERE matricule_ens=?", [$mat]);
    if (!$existe) { flash_set('erreur', 'Membre du personnel introuvable.'); rediriger('secondaire/pages/enseignants/liste.php'); }
    db_exec(
        "UPDATE enseignant SET civilite_ens=?, nom_ens=?, prenom_ens=?, sexe_ens=?, date_naiss=?, lieu_naiss=?,
                tel_ens=?, mail_ens=?, situation_matrimoniale=?, id_fonction=?, id_grade=?, matiere_enseignee=?,
                diplome=?, specialite=?
         WHERE matricule_ens=?",
        [$civilite, $nom, $prenom, $sexe, $date_naiss, $lieu_naiss, $tel, $mail, $situation,
         $fonction, $grade, $matiere_enseignee, $diplome, $specialite, $mat]
    );
    $msg = 'Membre du personnel modifié.';
} else {
    db_exec(
        "INSERT INTO enseignant (civilite_ens, nom_ens, prenom_ens, sexe_ens, date_naiss, lieu_naiss, tel_ens, mail_ens,
                situation_matrimoniale, id_fonction, id_grade, matiere_enseignee, diplome, specialite)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [$civilite, $nom, $prenom, $sexe, $date_naiss, $lieu_naiss, $tel, $mail, $situation,
         $fonction, $grade, $matiere_enseignee, $diplome, $specialite]
    );
    $mat = (int) db_last_id();
    $msg = 'Membre du personnel créé.';
}

flash_set('succes', $msg);
rediriger('secondaire/pages/enseignants/voir.php?id=' . $mat);
