<?php
// pages/enseignants/statut.php — Active/désactive un membre du personnel
// (pattern pages/eleves/statut.php). Ne bloque PAS la connexion d'un compte
// `user` déjà existant pour ce matricule (login.php ne vérifie pas
// statut_ens) — un membre désactivé disparaît juste des effectifs actifs
// (listes, génération de paie), la révocation d'accès reste un geste
// séparé (pages/utilisateurs/liste.php) si nécessaire.
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_role(['DIRECTEUR']);
csrf_verifier();

$mat = (int) ($_GET['mat'] ?? 0);
$ens = db_one("SELECT matricule_ens, statut_ens, id_fonction FROM enseignant WHERE matricule_ens=?", [$mat]);
if ($ens && (string) $mat === (string) matricule_ens_courant()) {
    flash_set('erreur', 'Vous ne pouvez pas désactiver votre propre fiche.');
} elseif ($ens && !fiche_gerable($mat)) {
    flash_set('erreur', refus_compte_non_gerable((string) $ens['id_fonction']));   // directeur : son personnel seulement
} elseif ($ens) {
    $nouveau = ($ens['statut_ens'] ?? 'actif') === 'actif' ? 'inactif' : 'actif';
    db_exec("UPDATE enseignant SET statut_ens=? WHERE matricule_ens=?", [$nouveau, $mat]);
    $msg = $nouveau === 'actif'
        ? 'Membre du personnel réactivé.'
        : 'Membre du personnel désactivé — exclu des effectifs actifs et de la génération de paie.';
    flash_set('succes', $msg);
} else {
    flash_set('erreur', 'Membre du personnel introuvable.');
}
rediriger('pages/enseignants/liste.php');
