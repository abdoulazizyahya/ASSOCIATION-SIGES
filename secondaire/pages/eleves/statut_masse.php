<?php
// secondaire/pages/eleves/statut_masse.php — bascule actif/désactivé pour
// plusieurs élèves d'un coup (même sémantique que statut.php, en masse).
// Demande explicite du 17/09/2026.
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'FONDATEUR', 'CENSEUR', 'SG', 'SECRETAIRE']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') rediriger('secondaire/pages/eleves/liste.php');
csrf_verifier();

$ids   = array_filter(array_map('intval', $_POST['ids'] ?? []));
$vers  = post('vers') === 'actif' ? 'actif' : 'desactive';
$retour = 'secondaire/pages/eleves/liste.php?' . http_build_query(array_filter([
    'q' => post('q') ?: null, 'classe' => (int) post('classe') ?: null,
    'statut' => post('statut') === 'desactive' ? 'desactive' : null,
]));

if (empty($ids)) {
    flash_set('erreur', 'Aucun élève sélectionné.');
    rediriger($retour);
}

$in = implode(',', array_fill(0, count($ids), '?'));
$n  = db_exec("UPDATE eleve SET statut=? WHERE id IN ($in)", array_merge([$vers], $ids));

flash_set('succes', $n . ' élève(s) ' . ($vers === 'actif' ? 'réactivé(s).' : 'désactivé(s).'));
rediriger($retour);
