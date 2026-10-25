<?php
// primaire/pages/enseignants/affectation_save.php — Enregistre l'onglet « Affectation
// des classes » de primaire/pages/enseignants/liste.php : DEUX grilles indépendantes,
// piste française (enseignat_classe, affect_fr[]) et piste arabe
// (enseignat_classe_arabe, affect_ar[]). Les enseignant(e)s FR et AR sont
// des personnes distinctes -> on affecte séparément dans chaque piste.
// Remplacement complet des affectations de l'année postée par l'ensemble
// coché (une case décochée supprime l'affectation) — même principe que
// primaire/pages/matieres_arabe/liste.php pour matiere_niveau_arabe.
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../noyau/connexion.php';
require_once __DIR__ . '/../../../noyau/fonctions.php';
exiger_role(['DIRECTEUR']);
csrf_verifier();

$val_annee = post('val_annee');
if ($val_annee === '') {
    flash_set('erreur', 'Année scolaire manquante.');
    rediriger('primaire/pages/enseignants/liste.php?onglet=affectation');
}

// "matricule_ens_IDClasses" par case cochée — filtré/validé avant écriture.
$parser = function (array $brut): array {
    $out = [];
    foreach ($brut as $valeur) {
        if (preg_match('/^(\d+)_(\d+)$/', (string) $valeur, $m)) {
            $out[] = [(int) $m[1], (int) $m[2]];
        }
    }
    return $out;
};

$coches = [
    'fr' => $parser($_POST['affect_fr'] ?? []),
    'ar' => $parser($_POST['affect_ar'] ?? []),
];

// Titulaires par trimestre (migration v65, 24/10/2026) : seule une classe dont les
// titulaires changent reçoit une nouvelle période, « à partir du » trimestre choisi
// (1er trimestre = correction) ; les bulletins des trimestres passés gardent
// l'ancien titulaire. Voir prim_enregistrer_affectations() (fonctions.php).
$trim_debut = (int) post('trim_debut') ?: prim_ordre_trimestre_courant();
$total = prim_enregistrer_affectations($val_annee, $coches, $trim_debut);

flash_set('succes', $total . ' affectation(s) enregistrée(s) pour ' . $val_annee . ' (français + arabe).');
rediriger('primaire/pages/enseignants/liste.php?onglet=affectation');
