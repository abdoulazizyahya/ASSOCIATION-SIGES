<?php
// secondaire/pages/enseignants/save.php — POST handler de
// secondaire/pages/enseignants/form.php.
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'FONDATEUR', 'CENSEUR']);

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

// Champs Paie : mêmes rôles que le module Paie (secondaire/pages/paie/
// index.php) — un Censeur (autorisé sur ce formulaire par ailleurs) ne
// peut pas modifier le grade salarial ni les coordonnées de paiement,
// même en forgeant la requête (le formulaire les masque déjà).
$peut_gerer_paie = in_array(role_connecte(), ['ADMIN', 'PROVISEUR', 'FONDATEUR', 'INTENDANT'], true);
if (!$peut_gerer_paie) $grade = null;
$matricule_cnps   = $peut_gerer_paie ? (post('matricule_cnps') ?: null) : null;
$indice_grille    = $peut_gerer_paie ? (post('indice_grille') ?: null) : null;
$nb_enfants       = $peut_gerer_paie ? (int) post('nb_enfants') : 0;
$nb_pers_charge   = $peut_gerer_paie ? (int) post('nb_pers_charge') : 0;
$date_recrutement = $peut_gerer_paie ? (post('date_recrutement') ?: null) : null;
$mode_paiement    = $peut_gerer_paie ? (post('mode_paiement') ?: null) : null;
$nom_banque       = $peut_gerer_paie ? (post('nom_banque') ?: null) : null;
$compte_bancaire  = $peut_gerer_paie ? (post('compte_bancaire') ?: null) : null;

if ($nom === '') {
    flash_set('erreur', 'Le nom est obligatoire.');
    rediriger('secondaire/pages/enseignants/form.php' . ($mat ? "?id=$mat" : ''));
}

// Un seul chef d'établissement : la fonction « Principal / Proviseur /
// Directeur… » ne peut être portée que par une fiche du personnel.
if (fonction_est_chef($fonction)) {
    foreach (db_all("SELECT matricule_ens, CONCAT_WS(' ', UPPER(nom_ens), prenom_ens) AS n, id_fonction FROM enseignant WHERE matricule_ens<>?", [$mat]) as $autre) {
        if (fonction_est_chef($autre['id_fonction'])) {
            flash_set('erreur', "Une école ne peut avoir qu'un seul chef d'établissement : « " . trim($autre['n']) . ' » a déjà la fonction « ' . $autre['id_fonction'] . ' ». Modifiez d\'abord sa fiche.');
            rediriger('secondaire/pages/enseignants/form.php' . ($mat ? "?id=$mat" : ''));
        }
    }
}

if ($mat) {
    $existe = db_one("SELECT matricule_ens FROM enseignant WHERE matricule_ens=?", [$mat]);
    if (!$existe) { flash_set('erreur', 'Membre du personnel introuvable.'); rediriger('secondaire/pages/enseignants/liste.php'); }
    // Les champs Paie ne sont mis à jour QUE si l'utilisateur y a accès —
    // sinon on préserve les valeurs déjà en base (pas d'écrasement à NULL
    // par un Censeur qui ne voit pas ces champs dans son formulaire).
    if ($peut_gerer_paie) {
        db_exec(
            "UPDATE enseignant SET civilite_ens=?, nom_ens=?, prenom_ens=?, sexe_ens=?, date_naiss=?, lieu_naiss=?,
                    tel_ens=?, mail_ens=?, situation_matrimoniale=?, id_fonction=?, id_grade=?, matiere_enseignee=?,
                    diplome=?, specialite=?, matricule_cnps=?, indice_grille=?, nb_enfants=?, nb_pers_charge=?,
                    date_recrutement=?, mode_paiement=?, nom_banque=?, compte_bancaire=?
             WHERE matricule_ens=?",
            [$civilite, $nom, $prenom, $sexe, $date_naiss, $lieu_naiss, $tel, $mail, $situation,
             $fonction, $grade, $matiere_enseignee, $diplome, $specialite, $matricule_cnps, $indice_grille,
             $nb_enfants, $nb_pers_charge, $date_recrutement, $mode_paiement, $nom_banque, $compte_bancaire, $mat]
        );
    } else {
        db_exec(
            "UPDATE enseignant SET civilite_ens=?, nom_ens=?, prenom_ens=?, sexe_ens=?, date_naiss=?, lieu_naiss=?,
                    tel_ens=?, mail_ens=?, situation_matrimoniale=?, id_fonction=?, matiere_enseignee=?,
                    diplome=?, specialite=?
             WHERE matricule_ens=?",
            [$civilite, $nom, $prenom, $sexe, $date_naiss, $lieu_naiss, $tel, $mail, $situation,
             $fonction, $matiere_enseignee, $diplome, $specialite, $mat]
        );
    }
    $msg = 'Membre du personnel modifié.';
} else {
    db_exec(
        "INSERT INTO enseignant (civilite_ens, nom_ens, prenom_ens, sexe_ens, date_naiss, lieu_naiss, tel_ens, mail_ens,
                situation_matrimoniale, id_fonction, id_grade, matiere_enseignee, diplome, specialite, matricule_cnps,
                indice_grille, nb_enfants, nb_pers_charge, date_recrutement, mode_paiement, nom_banque, compte_bancaire)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [$civilite, $nom, $prenom, $sexe, $date_naiss, $lieu_naiss, $tel, $mail, $situation,
         $fonction, $grade, $matiere_enseignee, $diplome, $specialite, $matricule_cnps, $indice_grille,
         $nb_enfants, $nb_pers_charge, $date_recrutement, $mode_paiement, $nom_banque, $compte_bancaire]
    );
    $mat = (int) db_last_id();
    $msg = 'Membre du personnel créé.';
}

flash_set('succes', $msg);
rediriger('secondaire/pages/enseignants/voir.php?id=' . $mat);
