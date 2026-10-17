<?php
// primaire/pages/enseignants/save.php — POST handler de primaire/pages/enseignants/form.php,
// pattern repris de primaire/pages/eleves/save.php (mêmes conventions).
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../noyau/connexion.php';
require_once __DIR__ . '/../../../noyau/fonctions.php';
exiger_role(['DIRECTEUR']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') rediriger('primaire/pages/enseignants/liste.php');
csrf_verifier();

$mat_existant   = (int) post('mat');
// Un directeur ne modifie pas la fiche d'un autre directeur ni du fondateur
// (fonctions.php::fiche_gerable()) ; sur sa propre fiche, il garde sa fonction.
if ($mat_existant && !fiche_gerable($mat_existant)) {
    flash_set('erreur', refus_compte_non_gerable((string) db_val("SELECT id_fonction FROM enseignant WHERE matricule_ens=?", [$mat_existant])));
    rediriger('primaire/pages/enseignants/liste.php');
}
if ($mat_existant && role_connecte() === 'DIRECTEUR' && (string) $mat_existant === (string) matricule_ens_courant()
    && post('fonction') !== 'DIRECTEUR') {
    flash_set('erreur', 'Vous ne pouvez pas changer votre propre fonction de directeur.');
    rediriger('primaire/pages/enseignants/form.php?mat=' . $mat_existant);
}
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
// Matricule (alphanumérique, ex. JP23444, 402683, 561345-M) : majuscules, unique quand renseigné (17/10/2026)
$mat_ens        = mb_strtoupper(preg_replace('/\s+/', ' ', trim(post('mat_ens')))) ?: null;
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
// École publique : ces champs ne sont pas affichés (17/10/2026) — on garde les valeurs en base.
if (!paie_geree_par_ecole() && $mat_existant) {
    $av = db_one("SELECT mode_paiement, nom_banque, compte_bancaire FROM enseignant WHERE matricule_ens=?", [$mat_existant]) ?? [];
    [$mode_paiement, $nom_banque, $compte_bancaire] = [$av['mode_paiement'] ?? null, $av['nom_banque'] ?? null, $av['compte_bancaire'] ?? null];
}
$statut_ens     = post('statut_ens') === 'inactif' ? 'inactif' : 'actif';

// Cascade région/département/arrondissement — même paire de colonnes que
// eleve.id_arrondissement/arrondissement_elv (voir primaire/pages/eleves/save.php,
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
    rediriger('primaire/pages/enseignants/form.php' . ($mat_existant ? '?mat=' . $mat_existant : ''));
}
// Liste AUTORITAIRE côté serveur — même fonctions.php::fonctions_assignables()
// que le <select> de form.php (COMPTABLE était oublié ici avant le
// 15/09/2026 alors que déjà proposé dans le menu déroulant ; FONDATEUR
// jamais assignable par un Directeur, seulement par le superadmin
// association en visite écriture — voir le commentaire de la fonction).
if (!in_array($fonction, fonctions_assignables(), true)) {
    flash_set('erreur', 'Fonction invalide.');
    rediriger('primaire/pages/enseignants/form.php' . ($mat_existant ? '?mat=' . $mat_existant : ''));
}
// Un seul directeur par école (fonctions.php::refus_second_chef()).
if ($fonction === 'DIRECTEUR' && ($refus = refus_second_chef($mat_existant ? (int) $mat_existant : null)) !== '') {
    flash_set('erreur', $refus);
    rediriger('primaire/pages/enseignants/form.php' . ($mat_existant ? '?mat=' . $mat_existant : ''));
}
if ($mat_ens !== null && ($autre = db_one("SELECT CONCAT_WS(' ', UPPER(nom_ens), prenom_ens) AS n FROM enseignant WHERE mat_ens=? AND matricule_ens<>?", [$mat_ens, (int) $mat_existant]))) {
    flash_set('erreur', 'Le matricule « ' . $mat_ens . ' » est déjà attribué à ' . trim($autre['n']) . '.');
    rediriger('primaire/pages/enseignants/form.php' . ($mat_existant ? '?mat=' . $mat_existant : ''));
}
if ($id_grade && !db_val("SELECT COUNT(*) FROM grade_enseignant WHERE code_grade=?", [$id_grade])) {
    $id_grade = null;
}

if ($mat_existant) {
    // ── Modification ──────────────────────────────────────
    $existe = db_one("SELECT matricule_ens FROM enseignant WHERE matricule_ens=?", [$mat_existant]);
    if (!$existe) { flash_set('erreur', 'Membre du personnel introuvable.'); rediriger('primaire/pages/enseignants/liste.php'); }
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
    $msg = 'Membre du personnel créé.';
    // Compte de connexion créé d'office (17/10/2026) : identifiant tiré du nom, mot de passe identique.
    if ($compte = prim_creer_compte_fiche($mat)) {
        $msg .= ' Compte de connexion créé : identifiant « ' . $compte['login_user'] . ' », mot de passe identique (à changer à la première connexion).';
    }
}

flash_set('succes', $msg);
rediriger('primaire/pages/enseignants/voir.php?mat=' . $mat);
