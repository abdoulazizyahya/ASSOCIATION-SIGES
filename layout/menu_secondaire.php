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
// ⚠ Groupes/ordre/libellés recopiés À L'IDENTIQUE de LAM_ABZ/layout/
// header.php (demande explicite du 16/09/2026 : « conforme au projet
// d'origine, même menu/sous-menu ») — ne pas réorganiser sans revérifier
// cette source. Seules les URLs sont adaptées (secondaire/pages/... et,
// pour Enseignants, le fichier réellement construit côté SIGES — liste.php,
// pas index.php comme LAM_ABZ, historique de l'étape 7). Toutes les pages
// ne sont pas encore vérifiées en conditions réelles — voir la mémoire
// integration-secondaire pour l'état précis module par module.
return [
    'Principal' => [
        ['Tableau de bord', 'dashboard.php', 'speedometer2', []],
    ],
    'Scolarité' => [
        ['Élèves',  'secondaire/pages/eleves/liste.php',  'people',    ['ADMIN','PROVISEUR','CENSEUR','SG','SECRETAIRE']],
        ['Classes', 'secondaire/pages/classes/liste.php', 'door-open', ['ADMIN','PROVISEUR','CENSEUR']],
    ],
    'Pédagogie' => [
        ['Matières',             'secondaire/pages/matieres/liste.php',         'journal-bookmark',   ['ADMIN','PROVISEUR','CENSEUR']],
        ['Notes',                'secondaire/pages/notes/index.php',            'pencil-square',      ['ADMIN','PROVISEUR','CENSEUR','ENSEIGNANT']],
        ['Absences',             'secondaire/pages/absences/index.php',         'person-x',           ['ADMIN','PROVISEUR','CENSEUR','ENSEIGNANT']],
        ['Bulletins',            'secondaire/pages/bulletins/index.php',        'file-earmark-text',  ['ADMIN','PROVISEUR','CENSEUR','ENSEIGNANT']],
        ['Conseil de Classe',    'secondaire/pages/conseil_classe/index.php',   'mortarboard',         ['ADMIN','PROVISEUR','CENSEUR','SG','ENSEIGNANT']],
        ['Statistiques',         'secondaire/pages/statistiques/index.php',     'bar-chart-line',      ['ADMIN','PROVISEUR','CENSEUR','ENSEIGNANT']],
        ['Documents de classe',  'secondaire/pages/statistiques/documents.php', 'files',                ['ADMIN','PROVISEUR','CENSEUR','ENSEIGNANT']],
        ['Résultat annuel',      'secondaire/pages/statistiques/resultat_annuel.php', 'trophy',         ['ADMIN','PROVISEUR','CENSEUR','ENSEIGNANT']],
    ],
    'Discipline' => [
        ['Discipline', 'secondaire/pages/discipline/index.php', 'shield-exclamation', ['ADMIN','CENSEUR','SG','ENSEIGNANT']],
    ],
    // « PAIEMENT PRIVÉ » : porté du module Finances/Dépenses du PRIMAIRE
    // (pages/finances/*, pages/depenses/*) sous secondaire/pages/
    // paiements_prives/ + secondaire/pages/depenses_privees/, comptabilité
    // 100% indépendante de PAIEMENT PUBLIQUE ci-dessous (tables dédiées —
    // voir fonctions.php, fonctions prive_*). Demande explicite du
    // 17/09/2026. Pas de « Cas sociaux » ici (absent du schéma secondaire,
    // contrairement au primaire) — 6 entrées « Gestion des inscriptions »
    // au lieu de 7 côté primaire. « Frais exigibles »/« Catégories de
    // dépenses » regroupées ICI (pas dans Administration) pour rester
    // cohérent avec PAIEMENT PUBLIQUE ci-dessous, qui fait déjà de même.
    'PAIEMENT PRIVÉ' => [
        ['--', 'Gestion des inscriptions'],
        ['Enregistrer un paiement', 'secondaire/pages/paiements_prives/versement.php',            'cash-stack',          ['ADMIN','PROVISEUR','SECRETAIRE','INTENDANT']],
        ['Frais exigibles',         'secondaire/pages/paiements_prives/obligations.php',           'cash-coin',            ['ADMIN','PROVISEUR','INTENDANT']],
        ['Journal de caisse',       'secondaire/pages/paiements_prives/journal.php',                'journal-text',        ['ADMIN','PROVISEUR','SECRETAIRE','INTENDANT']],
        ['État par classe',         'secondaire/pages/paiements_prives/etat_classe.php',            'list-check',          ['ADMIN','PROVISEUR','SECRETAIRE','INTENDANT']],
        ['Répartition par classe',  'secondaire/pages/paiements_prives/repartition_classes.php',    'pie-chart-fill',      ['ADMIN','PROVISEUR','SECRETAIRE','INTENDANT']],
        ['Impayés',                 'secondaire/pages/paiements_prives/impayes.php',                'exclamation-triangle',['ADMIN','PROVISEUR','SECRETAIRE','INTENDANT']],
        ['Statistiques',            'secondaire/pages/paiements_prives/statistiques.php',           'bar-chart-line',      ['ADMIN','PROVISEUR','INTENDANT']],
        ['--', 'Gestion des dépenses'],
        ['Nouvelle dépense',        'secondaire/pages/depenses_privees/saisie.php',                 'dash-circle',         ['ADMIN','PROVISEUR','SECRETAIRE','INTENDANT']],
        ['Catégories de dépenses',  'secondaire/pages/depenses_privees/categories.php',             'tags',                ['ADMIN','PROVISEUR','INTENDANT']],
        ['Journal des dépenses',    'secondaire/pages/depenses_privees/journal.php',                'journal-minus',       ['ADMIN','PROVISEUR','SECRETAIRE','INTENDANT']],
        ['Répartition par catégorie', 'secondaire/pages/depenses_privees/repartition_categories.php','pie-chart-fill',    ['ADMIN','PROVISEUR','SECRETAIRE','INTENDANT']],
        ['Statistiques',            'secondaire/pages/depenses_privees/statistiques.php',           'pie-chart',           ['ADMIN','PROVISEUR','INTENDANT']],
    ],
    // Ex-« Finances » — renommé « PAIEMENT PUBLIQUE » (même contenu, même
    // demande explicite du 17/09/2026) pour le distinguer du nouveau groupe
    // « PAIEMENT PRIVÉ » ci-dessus.
    'PAIEMENT PUBLIQUE' => [
        ['Enregistrer un paiement', 'secondaire/pages/paiements/index.php',       'cash-stack',          ['ADMIN','PROVISEUR','CENSEUR','INTENDANT']],
        ['Frais exigibles',         'secondaire/pages/paiements/obligations.php', 'cash-coin',            ['ADMIN','PROVISEUR','CENSEUR']],
        ['Opérateurs de paiement',  'secondaire/pages/paiements/operateurs.php',  'credit-card-2-front',  ['ADMIN','PROVISEUR','CENSEUR']],
        ['Impayés',                 'secondaire/pages/paiements/rapport.php?onglet=insolvables', 'exclamation-diamond', ['ADMIN','PROVISEUR','CENSEUR','INTENDANT']],
        ['Rapports',                'secondaire/pages/paiements/rapport.php',     'graph-up',             ['ADMIN','PROVISEUR','CENSEUR','INTENDANT']],
        ['Signatures numériques',   'secondaire/pages/paiements/signatures.php',  'vector-pen',           ['ADMIN','PROVISEUR','CENSEUR','INTENDANT','SG']],
    ],
    'Ressources humaines' => [
        ['Enseignants',           'secondaire/pages/enseignants/liste.php',     'person-badge',       ['ADMIN','PROVISEUR','CENSEUR']],
        ['Mes informations',      'secondaire/pages/enseignants/mon_profil.php','person-vcard',       ['ENSEIGNANT']],
        ['Demandes de documents', 'secondaire/pages/demandes/index.php',        'file-earmark-text',  ['ENSEIGNANT','CENSEUR','PROVISEUR','ADMIN']],
    ],
    'Administration' => [
        ['Paramètres',   'secondaire/pages/parametres/index.php',   'gear',        ['ADMIN','PROVISEUR']],
        ['Utilisateurs', 'secondaire/pages/utilisateurs/liste.php', 'person-gear', ['ADMIN']],
    ],
];
