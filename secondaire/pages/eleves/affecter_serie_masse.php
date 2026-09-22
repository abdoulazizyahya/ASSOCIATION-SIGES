<?php
// secondaire/pages/eleves/affecter_serie_masse.php — affecte en masse la
// série/LV2 (inscription.id_serie, année active) de plusieurs élèves — pour
// répartir une classe mixte (ex. 4ème : 10 en Arabe, 5 en Espagnol, 33 en
// Allemand) sans passer par la fiche élève un par un. Même modèle que
// transferer_masse.php. Valeur vide = retire la série (repasse à NULL).
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'FONDATEUR', 'CENSEUR', 'SG', 'SECRETAIRE']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') rediriger('secondaire/pages/eleves/liste.php');
csrf_verifier();

$ids        = array_filter(array_map('intval', $_POST['ids'] ?? []));
$id_serie   = (int) post('id_serie_cible') ?: null;
$retour = 'secondaire/pages/eleves/liste.php?' . http_build_query(array_filter([
    'q' => post('q') ?: null, 'classe' => (int) post('classe') ?: null,
    'statut' => post('statut') === 'desactive' ? 'desactive' : null,
]));

if (empty($ids)) {
    flash_set('erreur', 'Aucun élève sélectionné.');
    rediriger($retour);
}

$serie_lib = 'Aucune';
if ($id_serie) {
    $serie = db_one("SELECT libelle FROM serie WHERE id=?", [$id_serie]);
    if (!$serie) { flash_set('erreur', 'Série invalide.'); rediriger($retour); }
    $serie_lib = $serie['libelle'];
}

$id_annee = (int) (get_annee_active()['id'] ?? 0);
$in       = implode(',', array_fill(0, count($ids), '?'));
$n = db_exec(
    "UPDATE inscription SET id_serie=? WHERE id_annee=? AND id_eleve IN ($in)",
    array_merge([$id_serie, $id_annee], $ids)
);
$ignores = count($ids) - $n;

$msg = "$n élève(s) affecté(s) à la série « $serie_lib ».";
if ($ignores > 0) $msg .= " $ignores ignoré(s) (pas d'inscription cette année).";
flash_set('succes', $msg);
rediriger($retour);
