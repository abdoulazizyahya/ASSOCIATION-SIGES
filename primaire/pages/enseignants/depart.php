<?php
// primaire/pages/enseignants/depart.php — départ d'un membre du personnel
// (migration v65, 24/10/2026), depuis sa fiche (voir.php). Même principe que
// secondaire/commun/pages/enseignants/depart.php :
//   - depart     : muté / parti / retraité (statut_ens), date et motif. Fiche
//                  conservée (nom sur les anciens bulletins, paie, contrats) ;
//                  sort des listes et des effectifs, compte désactivé.
//   - reintegrer : de nouveau en service, compte réactivé.
//   - supprimer  : seulement une fiche sans aucune trace (créée par erreur).
// L'activation / désactivation simple (statut.php) reste disponible.
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../noyau/connexion.php';
require_once __DIR__ . '/../../../noyau/fonctions.php';
exiger_role(['DIRECTEUR']);
csrf_verifier();

$mat = (int) post('matricule_ens');
$ens = $mat ? db_one("SELECT * FROM enseignant WHERE matricule_ens=?", [$mat]) : null;
$retour = 'primaire/pages/enseignants/voir.php?mat=' . $mat;
if (!$ens) { flash_set('erreur', 'Membre du personnel introuvable.'); rediriger('primaire/pages/enseignants/liste.php'); }
if ((string) $mat === (string) matricule_ens_courant()) { flash_set('erreur', 'Vous ne pouvez pas déclarer votre propre départ.'); rediriger($retour); }
if (!fiche_gerable($mat, (string) $ens['id_fonction'])) { flash_set('erreur', refus_compte_non_gerable((string) $ens['id_fonction'])); rediriger($retour); }
if (!db_colonne_existe('enseignant', 'date_depart')) {
    flash_set('erreur', "Mise à jour de la base nécessaire (migration v65) : demandez au propriétaire de lancer les migrations.");
    rediriger($retour);
}
$nom = trim(mb_strtoupper($ens['nom_ens']) . ' ' . ($ens['prenom_ens'] ?? ''));
$a_actif = db_colonne_existe('user', 'actif');

switch (post('action')) {
    case 'depart':
        $statut = post('statut_ens');
        $date   = post('date_depart');
        if (!in_array($statut, ['mute', 'parti', 'retraite'], true) || !DateTimeImmutable::createFromFormat('!Y-m-d', $date)) {
            flash_set('erreur', 'Indiquez le type de départ et sa date.');
            rediriger($retour);
        }
        db_exec("UPDATE enseignant SET statut_ens=?, date_depart=?, motif_depart=? WHERE matricule_ens=?",
                [$statut, $date, post('motif_depart') ?: null, $mat]);
        $nb_cpt = $a_actif ? db_exec("UPDATE user SET actif=0 WHERE matricule_ens=?", [$mat]) : 0;
        journaliser_action('enseignant_depart', null, "$nom — " . PRIM_SITUATIONS[$statut] . " le $date");
        $va = get_annee_active()['val_annee'] ?? '';
        $encore = (int) db_val("SELECT (SELECT COUNT(*) FROM enseignat_classe WHERE matricule_ens=? AND val_annee=?)
                                     + (SELECT COUNT(*) FROM enseignat_classe_arabe WHERE matricule_ens=? AND val_annee=?)", [$mat, $va, $mat, $va]);
        flash_set('succes', "$nom : " . mb_strtolower(PRIM_SITUATIONS[$statut]) . ' depuis le ' . date('d/m/Y', strtotime($date)) . '.'
            . ($nb_cpt ? ' Son compte de connexion est désactivé.' : '')
            . ($encore ? " Il est encore titulaire de $encore classe(s) cette année : désignez son remplaçant dans Enseignant(e)s → Affectation des classes, « à partir du » trimestre voulu." : ''));
        rediriger($retour);

    case 'reintegrer':
        db_exec("UPDATE enseignant SET statut_ens='actif', date_depart=NULL, motif_depart=NULL WHERE matricule_ens=?", [$mat]);
        if ($a_actif) db_exec("UPDATE user SET actif=1 WHERE matricule_ens=?", [$mat]);
        journaliser_action('enseignant_reintegre', null, $nom);
        flash_set('succes', "$nom est de nouveau en service" . ($a_actif ? ' (compte de connexion réactivé)' : '') . '.');
        rediriger($retour);

    case 'supprimer':
        if ($traces = prim_traces_enseignant($mat)) {
            flash_set('erreur', "Suppression impossible : cette fiche a un historique ("
                . implode(', ', array_map(fn($l, $n) => "$n $l", array_keys($traces), $traces))
                . "). Déclarez plutôt son départ : son nom restera sur les anciens bulletins.");
            rediriger($retour);
        }
        try { db_exec("DELETE FROM edt_enseignant WHERE matricule_ens=?", [$mat]); } catch (\Throwable $e) { /* module EDT absent */ }
        db_exec("DELETE FROM user WHERE matricule_ens=?", [$mat]);
        db_exec("DELETE FROM enseignant WHERE matricule_ens=?", [$mat]);
        journaliser_action('enseignant_supprime', null, $nom);
        flash_set('succes', "Fiche de $nom supprimée.");
        rediriger('primaire/pages/enseignants/liste.php');
}
flash_set('erreur', 'Action inconnue.');
rediriger($retour);
