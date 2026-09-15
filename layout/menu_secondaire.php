<?php
// layout/menu_secondaire.php — menu latéral pour une école de type
// 'secondaire' (schema_ref_ecole_secondaire.sql, porté de LAM_ABZ).
// Chargé par fonctions.php::menu_definition() à la place de layout/menu.php
// quand type_enseignement_courant() === 'secondaire'.
//
// Même format que layout/menu.php : [label, url, icône, rôles autorisés].
//   - rôles vide []           → visible par tous les rôles
//   - ['--', 'Libellé']       → séparateur, pas un lien
//
// Rôles (utilisateur.role, ENUM figé au schéma) : ADMIN, PROVISEUR,
// CENSEUR, SG (Surveillant Général), SECRETAIRE, ENSEIGNANT, INTENDANT.
//
// ⚠ Volontairement réduit pour l'instant (« étape 6 » du plan
// d'intégration du secondaire, voir commit fb21462) : seules les entrées
// dont la page existe réellement sous secondaire/pages/ sont listées.
// Ajouter une entrée ici SEULEMENT une fois sa page construite et testée
// — sinon on retombe dans le piège qui a motivé secondaire_en_construction.php
// (un lien qui mène nulle part).
return [
    'Principal' => [
        ['Tableau de bord', 'dashboard.php', 'speedometer2', []],
    ],
    'Scolarité' => [
        ['Élèves',  'secondaire/pages/eleves/liste.php',  'people',    ['ADMIN','PROVISEUR','CENSEUR','SG','SECRETAIRE']],
        ['Classes', 'secondaire/pages/classes/liste.php', 'door-open', ['ADMIN','PROVISEUR','CENSEUR']],
    ],
    'Ressources humaines' => [
        ['Enseignants', 'secondaire/pages/enseignants/liste.php', 'person-badge', ['ADMIN','PROVISEUR','CENSEUR']],
    ],
];
