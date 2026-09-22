<?php
// secondaire/pages/eleves/supprimer_masse.php — suppression DÉFINITIVE de
// plusieurs élèves d'un coup, SANS garde sur l'historique d'inscription
// (retiré à la demande explicite du 17/09/2026 : le garde-fou initial
// bloquait systématiquement tout élève réellement inscrit, ce qui empêchait
// dans les faits toute suppression réelle). Un DELETE sur `eleve` entraîne
// donc désormais, en cascade (ON DELETE CASCADE du schéma), l'effacement
// définitif et irréversible de TOUTES ses inscriptions, notes, absences,
// paiements et décisions de conseil. Action assumée comme irréversible —
// la désactivation (statut_masse.php) reste l'option réversible.
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'FONDATEUR']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') rediriger('secondaire/pages/eleves/liste.php');
csrf_verifier();

$ids    = array_filter(array_map('intval', $_POST['ids'] ?? []));
$retour = 'secondaire/pages/eleves/liste.php?' . http_build_query(array_filter([
    'q' => post('q') ?: null, 'classe' => (int) post('classe') ?: null,
    'statut' => post('statut') === 'desactive' ? 'desactive' : null,
]));

if (empty($ids)) {
    flash_set('erreur', 'Aucun élève sélectionné.');
    rediriger($retour);
}

$in = implode(',', array_fill(0, count($ids), '?'));
$supprimes = db_exec("DELETE FROM eleve WHERE id IN ($in)", $ids);

flash_set('succes', "$supprimes élève(s) supprimé(s) définitivement (notes, absences, paiements et inscriptions liés effacés également).");
rediriger($retour);
