<?php
// association/entrer_ecole.php — un membre « ouvre » une école dans
// l'application scolaire normale.
//   ?id=N              → LECTURE SEULE (comportement par défaut, tous les
//                        membres autorisés).
//   ?id=N&mode=ecriture → LECTURE / ÉCRITURE : réservé au superadministrateur
//                        de l'association (ou à un membre disposant de
//                        membre_acces.plein_acces=1 sur cette école). Toute
//                        écriture est alors permise dans la base de l'école
//                        choisie, exactement comme un DIRECTEUR local.
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

// Mode demandé : écriture seulement si explicitement réclamée ET autorisée
// (superadmin association, ou membre_acces.plein_acces=1 sur cette école).
// Par défaut — et pour tout membre « admin » standard — LECTURE SEULE.
$mode_demande = ($_GET['mode'] ?? 'lecture') === 'ecriture';
$peut_ecrire  = est_superadmin_association() || !empty($acces['plein_acces']);
$ecriture     = $mode_demande && $peut_ecrire;

// Bascule de contexte : session « visite association », rôle synthétique,
// base école sélectionnée.
$_SESSION['visite_asso'] = true;
if ($ecriture) {
    $_SESSION['visite_asso_ecriture'] = true;
} else {
    unset($_SESSION['visite_asso_ecriture']);
}
// Rôle synthétique. `id` volontairement NULL : le membre association n'a
// pas de compte dans la base de l'école — les écritures faites en son nom
// (paiement_frais.id_utilisateur, depense.id_utilisateur, absence_justifiee
// .id_utilisateur…) sont donc enregistrées SANS utilisateur local (colonnes
// nullables). La traçabilité réelle est assurée au central par
// journaliser_action() ci-dessous (qui membre, quelle école, quand) et par
// le bandeau permanent « Visite association — LECTURE / ÉCRITURE ».
$_SESSION['user'] = [
    'id'            => null,
    'role'          => 'MEMBRE_ASSOCIATION',
    'nom'           => $m['nom'] ?? 'Association',
    'prenom'        => $m['prenom'] ?? '',
    'login'         => $m['login'] ?? '',
    'assoc_membre'  => (int) $m['id'],
];
unset($_SESSION['user_id']); // pas de compte école : est_connecte() reste faux (branche visite d'exiger_connexion)
basculer_base_ecole($e);
journaliser_action($ecriture ? 'visite_ecole_ecriture' : 'visite_ecole', $id, $e['nom']);

header('Location: ' . APP_URL . '/dashboard.php');
