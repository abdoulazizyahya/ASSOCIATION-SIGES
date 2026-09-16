<?php
// secondaire/pages/paiements/versement.php — modifier/supprimer un versement individuel
// depuis le tableau historique (secondaire/pages/paiements/index.php). Champs
// modifiables volontairement restreints à opérateur/référence/date : jamais
// le montant ni l'obligation, qui casseraient eleve_solde_obligation() et le
// regroupement par numero_recu (voir save.php).
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'CENSEUR', 'INTENDANT']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rediriger('secondaire/pages/paiements/index.php');
}
csrf_verifier();

$action    = post('action');
$id        = (int)post('id');
$id_classe = (int)post('id_classe');
$id_eleve  = (int)post('id_eleve');
$retour    = "secondaire/pages/paiements/index.php?classe=$id_classe&eleve=$id_eleve";

$paiement = db_one(
    "SELECT p.*, o.mode_paiement AS obligation_mode_paiement FROM paiement_frais p
     JOIN obligation_frais o ON o.id = p.id_obligation WHERE p.id = ?", [$id]
);
if (!$paiement) {
    flash_set('erreur', 'Versement introuvable.');
    rediriger($retour);
}

if ($action === 'modifier') {
    $date_paiement = post('date_paiement');
    $ref_paiement  = trim((string)post('ref_paiement')) ?: null;
    if (!$date_paiement) {
        flash_set('erreur', 'La date du versement est obligatoire.');
        rediriger($retour);
    }
    if ($paiement['obligation_mode_paiement'] === 'cash') {
        $id_operateur = 'CASH';
    } else {
        $id_operateur = trim((string)post('id_operateur'));
        $operateur_valide = $id_operateur !== 'CASH'
            && db_val("SELECT COUNT(*) FROM operateur_paiement WHERE id=?", [$id_operateur]);
        if (!$operateur_valide) {
            flash_set('erreur', 'Canal de paiement invalide.');
            rediriger($retour);
        }
    }
    db_exec(
        "UPDATE paiement_frais SET id_operateur=?, ref_paiement=?, date_paiement=? WHERE id=?",
        [$id_operateur, $ref_paiement, $date_paiement, $id]
    );
    flash_set('succes', 'Versement mis à jour.');
}

if ($action === 'supprimer') {
    db_exec("DELETE FROM paiement_frais WHERE id = ?", [$id]);
    flash_set('succes', 'Versement supprimé.');
}

rediriger($retour);
