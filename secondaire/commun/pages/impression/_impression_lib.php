<?php
// secondaire/commun/pages/impression/_impression_lib.php — Centre
// d'impression (10/11/2026), primaire et secondaire : listes PDF regroupées.
require_once __DIR__ . '/../../../../noyau/partage/ecole_donnees.php';

function imp_roles(): array {
    return ed_secondaire() ? ['ADMIN', 'PROVISEUR', 'FONDATEUR', 'CENSEUR', 'SG', 'SECRETAIRE', 'INTENDANT'] : ['DIRECTEUR', 'SECRETAIRE', 'COMPTABLE', 'FONDATEUR'];
}

/** Titulaire / professeur principal par classe : [id_classe => 'NOM Prénom']. */
function imp_titulaires(): array {
    $an = ed_annee(); $out = [];
    try {
        $rows = ed_secondaire()
            ? db_all("SELECT ep.IDClasses AS c, e.nom_ens AS nom, e.prenom_ens AS prenom, e.tel_ens AS tel FROM enseignat_principal ep JOIN enseignant e ON e.matricule_ens=ep.matricule_ens WHERE ep.val_annee=?", [$an['libelle']])
            : db_all("SELECT ec.IDClasses AS c, e.nom_ens AS nom, e.prenom_ens AS prenom, e.tel_ens AS tel FROM enseignat_classe ec JOIN enseignant e ON e.matricule_ens=ec.matricule_ens WHERE ec.val_annee=?", [$an['libelle']]);
        foreach ($rows as $r) $out[(int) $r['c']][] = ed_nom($r) . ($r['tel'] ? ' (' . $r['tel'] . ')' : '');
    } catch (\Throwable $e) {}
    return array_map(fn($l) => implode(', ', $l), $out);
}

/** Matières par classe avec l'enseignant (secondaire) : [id_classe => [['matiere','coef','enseignant']]]. */
function imp_matieres_classes(): array {
    if (!ed_secondaire()) return [];
    $an = ed_annee(); $out = [];
    try {
        $rows = db_all("SELECT d.IDClasses AS c, m.libelle AS matiere, d.coef,
                               (SELECT GROUP_CONCAT(CONCAT(UPPER(e.nom_ens), ' ', COALESCE(e.prenom_ens, '')) SEPARATOR ', ')
                                  FROM dispenser ds JOIN enseignant e ON e.matricule_ens=ds.matricule_ens
                                 WHERE ds.IDClasses=d.IDClasses AND ds.id_mat=d.id_mat AND ds.val_annee=?) AS enseignant
                        FROM discipline d JOIN matiere m ON m.id=d.id_mat
                        ORDER BY d.IDClasses, d.id_groupe, d.ordre" . sec_ordre_num() . ", m.libelle", [$an['libelle']]);
        foreach ($rows as $r) $out[(int) $r['c']][] = $r;
    } catch (\Throwable $e) {}
    return $out;
}

const IMP_DOCS = [
    'eleves' => [
        'liste'      => ['Liste de classe', 'list-ol', 'N°, nom, sexe, date et lieu de naissance, matricule / NIU'],
        'alpha'      => ['Liste alphabétique de l\'école', 'sort-alpha-down', 'Tous les élèves inscrits, avec leur classe'],
        'effectifs'  => ['Effectifs par classe et par genre', 'bar-chart', 'Garçons, filles et total, par classe et pour l\'école'],
        'annuaire'   => ['Annuaire téléphonique des parents', 'telephone', 'Numéros trouvés pour chaque élève (fiche, père, mère, tuteur)'],
        'sans_tel'   => ['Élèves sans numéro de parent', 'telephone-x', 'Liste à compléter, par classe'],
        'emargement' => ['Liste d\'émargement', 'pen', 'Nom des élèves et colonne de signature (réunions, distributions…)'],
    ],
    'personnel' => [
        'personnel'  => ['Liste du personnel', 'people', 'Nom, fonction, matricule, téléphone'],
        'titulaires' => ['Professeurs principaux / titulaires', 'star', 'Responsable de chaque classe'],
        'matieres'   => ['Matières par classe et enseignants', 'journal-bookmark', 'Coefficients et enseignants de chaque matière (secondaire)'],
    ],
    'etablissement' => [
        'classes'    => ['Liste des classes ouvertes', 'door-open', 'Classes, effectifs et titulaires'],
    ],
];
