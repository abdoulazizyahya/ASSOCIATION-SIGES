<?php
// secondaire/commun/pages/classes/supprimer_definitif.php — suppression RÉELLE
// d'une classe (contrairement à supprimer.php, qui l'archive). Accessible
// depuis l'onglet « Classes archivées » de liste.php. Demande explicite du
// 17/09/2026 (aucune suppression définitive n'existait, seul l'archivage).
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
exiger_role(SEC_ROLES_SUPPRESSION);   // Admin, Proviseur/Principal, fondateur, propriétaire
csrf_verifier();

$id = (int) ($_GET['id'] ?? 0);
// Suppression autorisée UNIQUEMENT si la classe n'a JAMAIS eu d'inscrit
// (toutes années confondues) — sinon on orphelinerait des inscriptions,
// notes, bulletins… rattachés à cette classe (inscription.id_classe est en
// ON DELETE CASCADE : un DELETE ici les effacerait silencieusement). Même
// garde que primaire/pages/classes/supprimer.php (primaire) ; le bouton est déjà
// masqué/désactivé côté liste dans ce cas, ce contrôle serveur reste la
// garde réelle (URL forgée).
$classe      = db_one("SELECT designation FROM classe WHERE id=?", [$id]);
$nb_inscrits = (int) db_val("SELECT COUNT(*) FROM inscription WHERE id_classe=?", [$id]);
if (!$classe) {
    flash_set('erreur', 'Classe introuvable.');
} elseif ($nb_inscrits > 0) {
    flash_set('erreur', "Suppression impossible : $nb_inscrits inscription(s) enregistrée(s) dans cette classe. Retirez d'abord les élèves.");
} else {
    // Réglages pédagogiques de la classe (tables sans clé étrangère) : matières,
    // séries, enseignants, professeur principal, SG, services d'emploi du temps.
    foreach (['discipline' => 'IDClasses', 'discipline_serie' => 'IDClasses', 'dispenser' => 'IDClasses',
              'dispenser_periode' => 'IDClasses', 'enseignat_principal' => 'IDClasses', 'sg' => 'IDClasses',
              'edt_service' => 'id_classe'] as $t => $col) {
        if (db_colonne_existe($t, $col)) db_exec("DELETE FROM `$t` WHERE `$col`=?", [$id]);
    }
    db_exec("DELETE FROM classe WHERE id=?", [$id]);
    flash_set('succes', 'Classe « ' . $classe['designation'] . ' » supprimée définitivement.');
}
rediriger('secondaire/commun/pages/classes/liste.php' . (($_GET['retour'] ?? '') === 'actives' ? '' : '?onglet=archivees'));
