<?php
// association/entrer_ecole.php — un membre « ouvre » une école dans
// l'application scolaire normale.
//
//   Superadmin / membre_acces.plein_acces=1 : entre en LECTURE / ÉCRITURE
//     PAR DÉFAUT (peut tout faire dans l'école — créer, modifier, supprimer
//     — exactement comme un DIRECTEUR local). ?id=N&mode=lecture pour se
//     limiter volontairement à la consultation.
//   Membre en accès simple (lecture) : LECTURE SEULE, toujours.
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

// Un superadmin (ou un membre disposant de plein_acces sur cette école)
// entre EN ÉCRITURE par défaut : il a le privilège de tout faire dans
// toutes les écoles. Il peut se limiter volontairement avec ?mode=lecture.
// Un membre en accès simple reste en LECTURE SEULE, quoi qu'il demande.
$peut_ecrire = est_superadmin_association() || !empty($acces['plein_acces']);
$ecriture    = $peut_ecrire && (($_GET['mode'] ?? '') !== 'lecture');

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
//
// En écriture : rôle « DIRECTEUR » synthétique côté PRIMAIRE — tous les
// boutons/actions des pages école qui testent `role_connecte() === 'DIRECTEUR'`
// en dur (classes, compétences/barème, matières arabes, signatures de
// bulletins, meilleurs élèves…) deviennent disponibles. Côté SECONDAIRE,
// vocabulaire de rôles totalement différent (ADMIN/PROVISEUR/CENSEUR/SG/
// SECRETAIRE/ENSEIGNANT/INTENDANT, cf. schema_ref_ecole_secondaire.sql) —
// « DIRECTEUR » n'y existe pas et est rejeté par les gardes en dur des
// modules secondaire (bulletins, statistiques, discipline, absences,
// conseil_classe : `in_array($role, ['ADMIN','PROVISEUR','CENSEUR'])`),
// d'où un « Accès non autorisé » constaté le 16/09/2026 pour le
// propriétaire de l'association en visite écriture. « ADMIN » est le rôle
// secondaire le plus large (équivalent DIRECTEUR) — utilisé ici à la place.
// En lecture seule : rôle « MEMBRE_ASSOCIATION » (les mêmes boutons restent
// masqués, cohérent avec la consultation), quel que soit le type d'école.
// `est_visite_association()` reste vrai dans tous les cas (bandeau + accès
// à tout le menu via `$menu_voit_tout`).
$secondaire = ($e['type_enseignement'] ?? 'primaire') === 'secondaire';
$_SESSION['user'] = [
    'id'            => null,
    'role'          => $ecriture ? ($secondaire ? 'ADMIN' : 'DIRECTEUR') : 'MEMBRE_ASSOCIATION',
    'nom'           => $m['nom'] ?? 'Association',
    'prenom'        => $m['prenom'] ?? '',
    'login'         => $m['login'] ?? '',
    'assoc_membre'  => (int) $m['id'],
];
unset($_SESSION['user_id']); // pas de compte école : est_connecte() reste faux (branche visite d'exiger_connexion)
basculer_base_ecole($e);
journaliser_action($ecriture ? 'visite_ecole_ecriture' : 'visite_ecole', $id, $e['nom']);

header('Location: ' . APP_URL . '/dashboard.php');
