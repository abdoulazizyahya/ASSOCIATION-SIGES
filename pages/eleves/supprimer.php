<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_role(['DIRECTEUR']);
csrf_verifier();

$id = (int)($_GET['id'] ?? 0);
$eleve = db_one("SELECT id_eleve, Nom_elv FROM eleve WHERE id_eleve=?", [$id]);
if (!$eleve) { flash_set('erreur', 'Élève introuvable.'); rediriger('pages/eleves/liste.php'); }

// Suppression définitive et irréversible. Toutes les tables dépendantes
// (parent, inscrire, info_supplementaires, exclusion, dossier_eleve, absence,
// composer_sequence(_arabe), moyenne_annuelle, moyenne_sequence_arabe,
// moyenne_trimestre(_arabe), paiement_frais, note_trimestrielle) référencent
// désormais eleve(id_eleve) via une vraie contrainte FK ON DELETE CASCADE —
// une seule suppression sur eleve suffit, MySQL nettoie tout le reste.
db_exec("DELETE FROM eleve WHERE id_eleve=?", [$id]);
journaliser_action('eleve_suppr', null, (string) $eleve['Nom_elv'] . ' (#' . $id . ')');

flash_set('succes', 'Élève « ' . $eleve['Nom_elv'] . ' » supprimé définitivement.');
rediriger('pages/eleves/liste.php');
