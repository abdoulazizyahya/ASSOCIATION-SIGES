<?php
// secondaire/commun/pages/eleves/save_info.php — informations complémentaires
// d'un élève (scolarité antérieure, santé, handicap, réfugié), table
// info_supplementaires (migration secondaire v26). Pendant de
// primaire/pages/eleves/save_info.php, sans le « cas social » (finances primaire).
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'FONDATEUR', 'CENSEUR', 'SG', 'SECRETAIRE']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') rediriger('secondaire/commun/pages/eleves/liste.php');
csrf_verifier();

$id = (int) post('id_eleve');
if (!$id || !db_val("SELECT COUNT(*) FROM eleve WHERE id=?", [$id])) {
    flash_set('erreur', 'Élève introuvable.');
    rediriger('secondaire/commun/pages/eleves/liste.php');
}
exiger_acces_eleve_secondaire($id);
$retour = 'secondaire/commun/pages/eleves/voir.php?id=' . $id;
if (!db_colonne_existe('info_supplementaires', 'id_eleve')) {
    flash_set('erreur', "Mise à jour de la base nécessaire (migration secondaire v26).");
    rediriger($retour);
}

$handicap = in_array(post('handicap'), ['OUI', 'NON'], true) ? post('handicap') : '';
$refugie  = in_array(post('refugie'), ['OUI', 'NON'], true) ? post('refugie') : '';
$v = [
    'dernier_etab'    => post('dernier_etab') ?: null,
    'derniere_classe' => post('derniere_classe') ?: null,
    'date_rec'        => post('date_rec') ?: null,
    'antecedent_med'  => post('antecedent_med') ?: null,
    'frequence'       => post('frequence') ?: null,
    'autres_infos'    => post('autres_infos') ?: null,
    'handicap'        => $handicap,
    'nature_handicap' => $handicap === 'OUI' ? post('nature_handicap') : '',
    'refugie'         => $refugie,
    'type_refugie'    => $refugie === 'OUI' && in_array(post('type_refugie'), ['Interne', 'Externe'], true) ? post('type_refugie') : '',
];
$cols = array_keys($v);
db_exec(
    "INSERT INTO info_supplementaires (id_eleve, " . implode(', ', $cols) . ") VALUES (?" . str_repeat(', ?', count($cols)) . ")
     ON DUPLICATE KEY UPDATE " . implode(', ', array_map(fn($c) => "$c=VALUES($c)", $cols)),
    array_merge([$id], array_values($v))
);

flash_set('succes', 'Informations complémentaires mises à jour.');
rediriger($retour);
