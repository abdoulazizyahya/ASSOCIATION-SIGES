<?php
// pages/enseignants/affectation_save.php — Enregistre l'onglet « Affectation
// des classes » de pages/enseignants/liste.php : DEUX grilles indépendantes,
// piste française (enseignat_classe, affect_fr[]) et piste arabe
// (enseignat_classe_arabe, affect_ar[]). Les enseignant(e)s FR et AR sont
// des personnes distinctes -> on affecte séparément dans chaque piste.
// Remplacement complet des affectations de l'année postée par l'ensemble
// coché (une case décochée supprime l'affectation) — même principe que
// pages/matieres_arabe/liste.php pour matiere_niveau_arabe.
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_role(['DIRECTEUR']);
csrf_verifier();

$val_annee = post('val_annee');
if ($val_annee === '') {
    flash_set('erreur', 'Année scolaire manquante.');
    rediriger('pages/enseignants/liste.php?onglet=affectation');
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

$pistes = [
    'enseignat_classe'       => $parser($_POST['affect_fr'] ?? []),
    'enseignat_classe_arabe' => $parser($_POST['affect_ar'] ?? []),
];

$total = 0;
foreach ($pistes as $table => $lignes) {
    db_exec("DELETE FROM $table WHERE val_annee=?", [$val_annee]);
    foreach ($lignes as [$mat, $idc]) {
        db_exec(
            "INSERT IGNORE INTO $table (matricule_ens, IDClasses, val_annee) VALUES (?,?,?)",
            [$mat, $idc, $val_annee]
        );
        $total++;
    }
}

flash_set('succes', $total . ' affectation(s) enregistrée(s) pour ' . $val_annee . ' (français + arabe).');
rediriger('pages/enseignants/liste.php?onglet=affectation');
