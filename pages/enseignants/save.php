<?php
// pages/enseignants/save.php — POST handler de pages/enseignants/form.php,
// pattern repris de pages/eleves/save.php (mêmes conventions).
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_role(['DIRECTEUR']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') rediriger('pages/enseignants/liste.php');
csrf_verifier();

$mat_existant   = (int) post('mat');
$civilite       = post('civilite') ?: 'M.';
$nom            = post('nom');
$prenom         = post('prenom');
$sexe           = post('sexe') === 'Feminin' ? 'Feminin' : 'Masculin';
$date_naiss     = post('date_naiss') ?: null;
$lieu_naiss     = post('lieu_naiss') ?: null;
$num_cni        = post('num_cni') ?: null;
$situation      = post('situation') ?: null;
$tel            = post('tel') ?: null;
$mail           = post('mail') ?: null;
$mat_ens        = post('mat_ens') ?: null;
$matricule_cnps = post('matricule_cnps') ?: null;
$nb_enfants     = max(0, (int) post('nb_enfants'));
$nb_pers_charge = max(0, (int) post('nb_pers_charge'));
$adresse        = post('adresse') ?: null;
$fonction       = post('fonction') ?: 'ENSEIGNANT';
$id_grade       = post('id_grade') ?: null;
$indice_grille  = post('indice_grille') ?: null;
$date_recrut    = post('date_recrutement') ?: null;
$mode_paiement  = post('mode_paiement') ?: null;
$nom_banque     = post('nom_banque') ?: null;
$compte_bancaire = post('compte_bancaire') ?: null;
$statut_ens     = post('statut_ens') === 'inactif' ? 'inactif' : 'actif';

// Cascade région/département/arrondissement — même paire de colonnes que
// eleve.id_arrondissement/arrondissement_elv (voir pages/eleves/save.php,
// même logique reprise telle quelle).
$id_arrondissement_brut = post('id_arrondissement');
$lieu_libre             = trim(post('lieu_libre'));
$id_arrondissement      = null;
if (ctype_digit($id_arrondissement_brut) && (int) $id_arrondissement_brut > 0) {
    if (db_val("SELECT COUNT(*) FROM arrondissement WHERE code_arrond=?", [(int) $id_arrondissement_brut])) {
        $id_arrondissement = (int) $id_arrondissement_brut;
    }
}
$lieu_origine_libre = $id_arrondissement ? null : ($lieu_libre ?: null);

if ($nom === '') {
    flash_set('erreur', 'Le nom est obligatoire.');
    rediriger('pages/enseignants/form.php' . ($mat_existant ? '?mat=' . $mat_existant : ''));
}
// Liste AUTORITAIRE côté serveur — même fonctions.php::fonctions_assignables()
// que le <select> de form.php (COMPTABLE était oublié ici avant le
// 15/09/2026 alors que déjà proposé dans le menu déroulant ; FONDATEUR
// jamais assignable par un Directeur, seulement par le superadmin
// association en visite écriture — voir le commentaire de la fonction).
if (!in_array($fonction, fonctions_assignables(), true)) {
    flash_set('erreur', 'Fonction invalide.');
    rediriger('pages/enseignants/form.php' . ($mat_existant ? '?mat=' . $mat_existant : ''));
}
if ($id_grade && !db_val("SELECT COUNT(*) FROM grade_enseignant WHERE code_grade=?", [$id_grade])) {
    $id_grade = null;
}

if ($mat_existant) {
    // ── Modification ──────────────────────────────────────
    $existe = db_one("SELECT matricule_ens FROM enseignant WHERE matricule_ens=?", [$mat_existant]);
    if (!$existe) { flash_set('erreur', 'Membre du personnel introuvable.'); rediriger('pages/enseignants/liste.php'); }
    $mat = $mat_existant;

    db_exec(
        "UPDATE enseignant SET civilite_ens=?, nom_ens=?, prenom_ens=?, sexe_ens=?, date_naiss_ens=?, lieu_ens=?,
                num_cni=?, situation_ens=?, nb_enfants=?, nb_pers_charge=?, tel_ens=?, mail_ens=?, mat_ens=?, matricule_cnps=?, adresse_ens=?,
                id_fonction=?, id_grade=?, indice_grille=?,
                arrondissement_ens=?, lieu_origine_libre=?, statut_ens=?, date_recrutement=?, mode_paiement=?, nom_banque=?, compte_bancaire=?
         WHERE matricule_ens=?",
        [
            $civilite, $nom, $prenom, $sexe, $date_naiss, $lieu_naiss, $num_cni, $situation, $nb_enfants, $nb_pers_charge, $tel, $mail, $mat_ens, $matricule_cnps, $adresse,
            $fonction, $id_grade, $indice_grille, $id_arrondissement, $lieu_origine_libre, $statut_ens, $date_recrut, $mode_paiement, $nom_banque, $compte_bancaire,
            $mat,
        ]
    );
    $msg = 'Membre du personnel modifié.';
} else {
    // ── Création ──────────────────────────────────────────
    db_exec(
        "INSERT INTO enseignant (civilite_ens, nom_ens, prenom_ens, sexe_ens, date_naiss_ens, lieu_ens,
                num_cni, situation_ens, nb_enfants, nb_pers_charge, tel_ens, mail_ens, mat_ens, matricule_cnps, adresse_ens,
                id_fonction, id_grade, indice_grille,
                arrondissement_ens, lieu_origine_libre, statut_ens, date_recrutement, mode_paiement, nom_banque, compte_bancaire)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [
            $civilite, $nom, $prenom, $sexe, $date_naiss, $lieu_naiss, $num_cni, $situation, $nb_enfants, $nb_pers_charge, $tel, $mail, $mat_ens, $matricule_cnps, $adresse,
            $fonction, $id_grade, $indice_grille, $id_arrondissement, $lieu_origine_libre, $statut_ens, $date_recrut, $mode_paiement, $nom_banque, $compte_bancaire,
        ]
    );
    $mat = (int) db_last_id();
    $msg = 'Membre du personnel créé — matricule interne ' . $mat . '.';
}

flash_set('succes', $msg);
rediriger('pages/enseignants/voir.php?mat=' . $mat);
