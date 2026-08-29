<?php
// association/entrer_ecole.php — un membre « ouvre » une école pour la
// consulter en LECTURE SEULE dans l'application scolaire normale.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
exiger_membre_association();

$id = (int) ($_GET['id'] ?? 0);
$e  = $id ? assoc_one("SELECT * FROM etablissement WHERE id=? AND actif=1", [$id]) : null;
if (!$e) {
    http_response_code(404);
    die('Établissement introuvable ou inactif.');
}

// Droits du membre : membre_acces (id_etablissement NULL = toutes les écoles).
$m = membre_connecte();
$acces = assoc_one(
    "SELECT * FROM membre_acces
     WHERE id_membre=? AND actif=1 AND (id_etablissement IS NULL OR id_etablissement=?)
     ORDER BY id_etablissement IS NULL LIMIT 1",
    [$m['id'], $id]
);
if (!$acces) {
    http_response_code(403);
    die('Accès non autorisé à cet établissement.');
}

// Bascule de contexte : session « visite association », rôle synthétique,
// base école sélectionnée. Écriture éventuellement autorisée si
// membre_acces.plein_acces = 1 (par défaut : lecture seule).
$_SESSION['visite_asso'] = true;
if (!empty($acces['plein_acces'])) {
    $_SESSION['visite_asso_ecriture'] = true;
} else {
    unset($_SESSION['visite_asso_ecriture']);
}
$_SESSION['user'] = [
    'role'   => 'MEMBRE_ASSOCIATION',
    'nom'    => $m['nom'] ?? 'Association',
    'prenom' => $m['prenom'] ?? '',
    'login'  => $m['login'] ?? '',
];
basculer_base_ecole($e);
journaliser_action('visite_ecole', $id, $e['nom']);

header('Location: ' . APP_URL . '/dashboard.php');
