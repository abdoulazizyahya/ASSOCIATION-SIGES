<?php
// secondaire/commun/pages/enseignants/depart.php — situation d'un membre du
// personnel (migration secondaire v22, 24/10/2026), depuis sa fiche (voir.php) :
//   - depart     : muté / parti / retraité, avec date et motif. La fiche reste
//                  (nom sur les anciens bulletins, paie, contrats) ; il sort des
//                  listes de choix et son compte de connexion est désactivé.
//   - reintegrer : de nouveau en service, compte réactivé.
//   - supprimer  : seulement une fiche sans aucune trace (créée par erreur).
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'FONDATEUR']);
csrf_verifier();

$mat = (int) post('matricule_ens');
$ens = $mat ? db_one("SELECT * FROM enseignant WHERE matricule_ens=?", [$mat]) : null;
$retour = 'secondaire/commun/pages/enseignants/voir.php?id=' . $mat;
if (!$ens) { flash_set('erreur', 'Membre du personnel introuvable.'); rediriger('secondaire/commun/pages/enseignants/liste.php'); }
if (!sec_fiche_gerable($ens)) { flash_set('erreur', "Cette fiche est hors de votre périmètre."); rediriger($retour); }
if (!db_colonne_existe('enseignant', 'statut_ens')) {
    flash_set('erreur', "Mise à jour de la base nécessaire (migration secondaire v22) : demandez au propriétaire de lancer les migrations.");
    rediriger($retour);
}
$nom = trim(mb_strtoupper($ens['nom_ens']) . ' ' . ($ens['prenom_ens'] ?? ''));

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
        $nb_cpt = db_exec("UPDATE utilisateur SET actif=0 WHERE matricule_ens=? AND compte_systeme=0", [$mat]);
        journaliser_action('enseignant_depart', null, "$nom — " . SEC_SITUATIONS[$statut] . " le $date");
        $encore = (int) db_val("SELECT COUNT(*) FROM dispenser WHERE matricule_ens=? AND val_annee=?", [$mat, (string) (get_annee_active()['libelle'] ?? '')]);
        flash_set('succes', "$nom : " . mb_strtolower(SEC_SITUATIONS[$statut]) . ' depuis le ' . date('d/m/Y', strtotime($date)) . '.'
            . ($nb_cpt ? ' Son compte de connexion est désactivé.' : '')
            . ($encore ? " Il est encore affecté à $encore matière(s) cette année : désignez son remplaçant dans Matières → Enseignants, « à partir du » trimestre voulu." : ''));
        rediriger($retour);

    case 'reintegrer':
        db_exec("UPDATE enseignant SET statut_ens='actif', date_depart=NULL, motif_depart=NULL WHERE matricule_ens=?", [$mat]);
        db_exec("UPDATE utilisateur SET actif=1 WHERE matricule_ens=? AND compte_systeme=0", [$mat]);
        journaliser_action('enseignant_reintegre', null, $nom);
        flash_set('succes', "$nom est de nouveau en service (compte de connexion réactivé).");
        rediriger($retour);

    case 'supprimer':
        if ($traces = sec_traces_enseignant($mat)) {
            flash_set('erreur', "Suppression impossible : cette fiche a un historique ("
                . implode(', ', array_map(fn($l, $n) => "$n $l", array_keys($traces), $traces))
                . "). Déclarez plutôt son départ : son nom restera sur les anciens bulletins.");
            rediriger($retour);
        }
        try { db_exec("DELETE FROM edt_enseignant WHERE matricule_ens=?", [$mat]); } catch (\Throwable $e) { /* module EDT absent */ }
        db_exec("DELETE FROM utilisateur WHERE matricule_ens=? AND compte_systeme=0", [$mat]);
        db_exec("DELETE FROM enseignant WHERE matricule_ens=?", [$mat]);
        journaliser_action('enseignant_supprime', null, $nom);
        flash_set('succes', "Fiche de $nom supprimée.");
        rediriger('secondaire/commun/pages/enseignants/liste.php');
}
flash_set('erreur', 'Action inconnue.');
rediriger($retour);
