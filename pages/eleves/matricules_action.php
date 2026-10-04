<?php
// pages/eleves/matricules_action.php — actions de l'onglet « Matricules »
// (pages/eleves/_eleves_matricules.php), en deux temps (04/10/2026) :
//   1. « proposer » : calcule des matricules selon le format défini et les
//      AFFICHE dans les cases (rien n'est enregistré) ;
//   2. « enregistrer » : l'utilisateur a pu ajuster les cases ; chaque
//      matricule est vérifié (unique en base ET dans le lot) puis enregistré.
// On revient toujours sur l'onglet Matricules, qui affiche le résultat.
// Mêmes rôles que la configuration des matricules (matricule_config.php).
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/_matricules_lib.php';
exiger_role(['DIRECTEUR', 'SECRETAIRE']);
$retour = 'pages/eleves/liste.php?statut=matricules';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') rediriger($retour);
csrf_verifier();

$action = post('action');

if ($action === 'proposer') {
    // Élèves visés : les sans-matricule, ou les doublons « à corriger ».
    $ids = [];
    if (post('cible') === 'doublons') {
        foreach (matricules_doublons() as $g) foreach ($g as $m) if (!$m['garde']) $ids[] = (int) $m['id_eleve'];
    } else {
        $ids = array_map(fn($e) => (int) $e['id_eleve'], matricules_manquants());
    }
    $props = matricules_proposer($ids);
    $_SESSION['mat_propositions'] ??= [];
    foreach ($props as $id => $m) $_SESSION['mat_propositions'][$id] = $m;
    flash_set('info', count($props) . ' matricule(s) proposé(s) selon le format défini. Vérifiez-les, ajustez si besoin, puis cliquez sur « Enregistrer ». Rien n\'est encore enregistré.');
    rediriger($retour);
}

if ($action === 'annuler') {
    unset($_SESSION['mat_propositions']);
    flash_set('info', 'Propositions effacées — aucun matricule n\'a été modifié.');
    rediriger($retour);
}

if ($action === 'enregistrer') {
    $saisis = [];
    foreach ((array) ($_POST['mat'] ?? []) as $id => $m) {
        $m = trim((string) $m);
        if ((int) $id > 0 && $m !== '') $saisis[(int) $id] = $m;
    }
    if (!$saisis) {
        flash_set('erreur', 'Aucun matricule à enregistrer : remplissez au moins une case (ou cliquez sur « Proposer des matricules »).');
        rediriger($retour);
    }
    // Doublons À L'INTÉRIEUR du lot (deux cases identiques) : refusés tous les deux.
    $compte = array_count_values(array_map('matricule_cle', $saisis));
    $faits = []; $erreurs = [];
    foreach ($saisis as $id => $m) {
        $nom = trim((string) db_val("SELECT CONCAT_WS(' ', UPPER(Nom_elv), Prenom_elv) FROM eleve WHERE id_eleve=?", [$id]));
        if ($compte[matricule_cle($m)] > 1) { $erreurs[$id] = "$nom : « $m » est saisi pour plusieurs élèves"; continue; }
        try {
            $ancien = (string) db_val("SELECT Mat_elv FROM eleve WHERE id_eleve=?", [$id]);
            $faits[] = ['id' => $id, 'nom' => $nom, 'ancien' => $ancien, 'nouveau' => matricule_attribuer($id, $m)];
            unset($_SESSION['mat_propositions'][$id]);
        } catch (\Throwable $e) {
            $erreurs[$id] = "$nom : " . $e->getMessage();
        }
    }
    // Résultat affiché sur l'onglet (tableau « Matricules enregistrés »).
    $_SESSION['mat_resultats'] = $faits;
    $_SESSION['mat_erreurs']   = $erreurs;
    if ($faits && !$erreurs)      flash_set('succes', count($faits) . ' matricule(s) enregistré(s) avec succès.');
    elseif ($faits)               flash_set('alerte', count($faits) . ' matricule(s) enregistré(s), ' . count($erreurs) . ' refusé(s) — voir le détail ci-dessous.');
    else                          flash_set('erreur', 'Aucun matricule enregistré — voir le détail ci-dessous.');
    rediriger($retour);
}

rediriger($retour);
