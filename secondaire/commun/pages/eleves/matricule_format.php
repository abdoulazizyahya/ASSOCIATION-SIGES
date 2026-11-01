<?php
// secondaire/commun/pages/eleves/matricule_format.php — préfixe et suffixe du
// matricule interne des élèves (migration secondaire v32, 01/11/2026), depuis
// l'onglet « Import & matricules » (_eleves_outils.php). Option : renommer les
// matricules existants dans le nouveau format (numéro conservé).
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'FONDATEUR']);

$retour = 'secondaire/commun/pages/eleves/liste.php?statut=outils';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') rediriger($retour);
csrf_verifier();
if (!db_colonne_existe('etablissement', 'matricule_prefixe')) {
    flash_set('erreur', 'Mise à jour de la base nécessaire (migration secondaire v32).');
    rediriger($retour);
}

$net = fn($v) => preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string) $v)));
$prefixe = $net(post('matricule_prefixe'));
$suffixe = $net(post('matricule_suffixe'));
if (strlen($prefixe) > 10 || strlen($suffixe) > 5) {
    flash_set('erreur', 'Préfixe : 10 caractères au plus ; suffixe : 5 au plus (lettres et chiffres).');
    rediriger($retour);
}

[$ancien_p, $ancien_s] = sec_matricule_format();
global $link;
mysqli_begin_transaction($link);
try {
    db_exec("UPDATE etablissement SET matricule_prefixe=?, matricule_suffixe=?", [$prefixe ?: null, $suffixe ?: null]);
    [$nouveau_p, $nouveau_s] = sec_matricule_format();
    $n = post('renommer') ? sec_matricule_renommer($ancien_p, $ancien_s, $nouveau_p, $nouveau_s) : 0;
    mysqli_commit($link);
    flash_set('succes', "Format du matricule : {$nouveau_p}" . sec_matricule_annee() . "0001{$nouveau_s}."
        . ($n ? " $n matricule(s) existant(s) renommé(s)." : ''));
} catch (Throwable $e) {
    mysqli_rollback($link);
    flash_set('erreur', 'Rien n\'a été modifié : ' . $e->getMessage());
}
rediriger($retour);
