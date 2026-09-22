<?php
// secondaire/pages/eleves/transferer_masse.php — déplace l'inscription de
// plusieurs élèves (année scolaire active) vers une autre classe de la même
// école. Un élève sans inscription cette année (rien à déplacer) est
// ignoré. Demande explicite du 17/09/2026.
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'FONDATEUR', 'CENSEUR', 'SG', 'SECRETAIRE']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') rediriger('secondaire/pages/eleves/liste.php');
csrf_verifier();

$ids           = array_filter(array_map('intval', $_POST['ids'] ?? []));
$id_classe_cible = (int) post('id_classe_cible');
$retour = 'secondaire/pages/eleves/liste.php?' . http_build_query(array_filter([
    'q' => post('q') ?: null, 'classe' => (int) post('classe') ?: null,
    'statut' => post('statut') === 'desactive' ? 'desactive' : null,
]));

if (empty($ids)) {
    flash_set('erreur', 'Aucun élève sélectionné.');
    rediriger($retour);
}

$classe_cible = $id_classe_cible ? db_one("SELECT id, designation FROM classe WHERE id=? AND archivee=0", [$id_classe_cible]) : null;
if (!$classe_cible) {
    flash_set('erreur', 'Classe de destination invalide.');
    rediriger($retour);
}

$id_annee = (int) (get_annee_active()['id'] ?? 0);
$in       = implode(',', array_fill(0, count($ids), '?'));
$transferes = db_exec(
    "UPDATE inscription SET id_classe=? WHERE id_annee=? AND id_eleve IN ($in)",
    array_merge([$id_classe_cible, $id_annee], $ids)
);
$ignores = count($ids) - $transferes;

$msg = "$transferes élève(s) transféré(s) vers « " . $classe_cible['designation'] . " ».";
if ($ignores > 0) $msg .= " $ignores ignoré(s) (pas d'inscription cette année).";
flash_set('succes', $msg);
rediriger($retour);
