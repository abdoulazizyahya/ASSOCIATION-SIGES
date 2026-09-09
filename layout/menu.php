<?php
// layout/menu.php — DÉFINITION UNIQUE du menu latéral de l'école.
//
//  Extrait de layout/header.php (rendu) pour être partagé avec
//  fonctions.php : la même structure sert au RENDU du menu ET à
//  l'ENFORCEMENT des « privilèges par utilisateur » (acces_utilisateur —
//  liste de menus/sous-menus RETIRÉS à un compte, gérée depuis
//  pages/utilisateurs/acces.php). Voir fonctions.php::menu_definition().
//
//  Format d'une entrée : [label, url, icône bootstrap, rôles autorisés]
//    - rôles vide []           → visible par tous les rôles
//    - ['--', 'Libellé']       → séparateur (sous-section), pas un lien
//
//  Clé de privilège :
//    - un groupe entier   → 'grp:Nom du groupe'
//    - une entrée précise → son url (ex. 'pages/eleves/liste.php')

return [
    'Principal' => [
        ['Tableau de bord', 'dashboard.php', 'speedometer2', []],
    ],
    'Scolarité' => [
        ['Élèves',  'pages/eleves/liste.php',  'people',    []],
        ['Classes', 'pages/classes/liste.php', 'door-open', ['DIRECTEUR','SECRETAIRE','COMPTABLE']],
    ],

    // Finances : remontée au-dessus de Pédagogie (demande explicite) — le
    // recouvrement des frais est un usage quotidien de la Secrétaire, plus
    // fréquent que la saisie des notes côté navigation. Réorganisée le
    // 15/08/2026 en 2 sous-sections (même convention de séparateur ['--', ..]
    // que Pédagogie/Arabe) : Gestion des inscriptions (tout ce qui existait
    // déjà — versements des élèves) et Gestion des dépenses (nouveau module,
    // migration v33 — décaissements de l'établissement par catégorie,
    // prélevés sur les montants encaissés côté inscriptions, voir
    // fonctions.php::solde_caisse()).
    'Finances' => [
        ['--', 'Gestion des inscriptions'],
        ['Paiements',        'pages/finances/versement.php',    'cash-coin',            ['DIRECTEUR','SECRETAIRE','COMPTABLE']],
        ['Journal de caisse','pages/finances/journal.php',       'journal-text',         ['DIRECTEUR','SECRETAIRE','COMPTABLE']],
        ['État par classe',  'pages/finances/etat_classe.php',   'list-check',           ['DIRECTEUR','SECRETAIRE','COMPTABLE']],
        ['Répartition par classe', 'pages/finances/repartition_classes.php', 'pie-chart-fill', ['DIRECTEUR','SECRETAIRE','COMPTABLE']],
        ['Impayés',          'pages/finances/impayes.php',       'exclamation-triangle', ['DIRECTEUR','SECRETAIRE','COMPTABLE']],
        ['Cas sociaux',      'pages/finances/cas_sociaux.php',   'heart',                ['DIRECTEUR','SECRETAIRE','COMPTABLE']],
        ['Statistiques',     'pages/finances/statistiques.php',  'bar-chart-line',       ['DIRECTEUR','COMPTABLE']],
        ['Obligations',      'pages/finances/obligations.php',   'card-checklist',       ['DIRECTEUR','COMPTABLE']],

        ['--', 'Gestion des dépenses'],
        ['Nouvelle dépense',     'pages/depenses/saisie.php',       'dash-circle',    ['DIRECTEUR','SECRETAIRE','COMPTABLE']],
        ['Journal des dépenses', 'pages/depenses/journal.php',      'journal-minus',  ['DIRECTEUR','SECRETAIRE','COMPTABLE']],
        ['Répartition par catégorie', 'pages/depenses/repartition_categories.php', 'pie-chart-fill', ['DIRECTEUR','SECRETAIRE','COMPTABLE']],
        ['Statistiques',         'pages/depenses/statistiques.php', 'pie-chart',      ['DIRECTEUR','COMPTABLE']],
        ['Catégories',           'pages/depenses/categories.php',   'tags',           ['DIRECTEUR','COMPTABLE']],
    ],
    // Discipline/Pédagogie : rôles listés explicitement (au lieu de [] = tous)
    // depuis le 22/08/2026 — COMPTABLE (Agent financier) n'a normalement rien
    // à y faire, sauf s'il est EN MÊME TEMPS enseignant (voir $roles_effectifs
    // dans header.php, agent_est_aussi_enseignant() dans fonctions.php).
    'Discipline' => [
        ['Absences',   'pages/absences/index.php',    'person-x', ['DIRECTEUR','ENSEIGNANT','SECRETAIRE']],
    ],
    // Pédagogie : les deux pistes de notation coexistent (l'école est
    // bilingue français/arabe) et sont volontairement regroupées séparément —
    // toute la piste française d'abord, puis un séparateur « Arabe », puis la
    // piste arabe avec les MÊMES entrées.
    'Pédagogie' => [
        ['Saisie des notes',       'pages/notes/index.php',       'pencil-square', ['DIRECTEUR','ENSEIGNANT','SECRETAIRE']],
        ['Notes justifiées',       'pages/notes/absence_justifiee.php', 'person-x', ['DIRECTEUR','ENSEIGNANT','SECRETAIRE']],
        ['Annulation d\'évaluation','pages/notes/annulation_evaluation.php', 'calendar-x', ['DIRECTEUR']],
        ['Bulletins',              'pages/bulletins/index.php',   'file-earmark-text', ['DIRECTEUR','ENSEIGNANT','SECRETAIRE']],
        ['Statistiques',           'pages/statistiques/index.php','bar-chart-line', ['DIRECTEUR','ENSEIGNANT','SECRETAIRE']],
        ['Conseil de classe',      'pages/conseil_classe/index.php', 'mortarboard', ['DIRECTEUR','ENSEIGNANT','SECRETAIRE']],
        ['Tableau d\'honneur/ Relevé',    'pages/statistiques/documents.php', 'files', ['DIRECTEUR','ENSEIGNANT','SECRETAIRE']],
        ['Résultat annuel',        'pages/resultat_annuel/index.php', 'trophy', ['DIRECTEUR','ENSEIGNANT','SECRETAIRE']],
        ['Groupes de compétences', 'pages/competences/liste.php', 'diagram-3', ['DIRECTEUR','ENSEIGNANT','SECRETAIRE']],

        ['--', 'Arabe'],
        ['Saisie des notes',    'pages/notes_arabe/index.php',            'pencil-square',     ['DIRECTEUR','ENSEIGNANT','SECRETAIRE']],
        ['Notes justifiées',    'pages/notes_arabe/absence_justifiee.php','person-x',          ['DIRECTEUR','ENSEIGNANT','SECRETAIRE']],
        ['Bulletins',           'pages/bulletins_arabe/index.php',        'file-earmark-text', ['DIRECTEUR','ENSEIGNANT','SECRETAIRE']],
        ['Statistiques',        'pages/statistiques_arabe/index.php',     'bar-chart-line',    ['DIRECTEUR','ENSEIGNANT','SECRETAIRE']],
        ['Documents de classe', 'pages/statistiques_arabe/documents.php', 'files',             ['DIRECTEUR','ENSEIGNANT','SECRETAIRE']],
        ['Conseil de classe',   'pages/conseil_classe_arabe/index.php',   'mortarboard',       ['DIRECTEUR','ENSEIGNANT','SECRETAIRE']],
        ['Résultat annuel',     'pages/resultat_annuel_arabe/index.php',  'trophy',            ['DIRECTEUR','ENSEIGNANT','SECRETAIRE']],
        ['Groupes de matières', 'pages/matieres_arabe/liste.php',         'diagram-3',         ['DIRECTEUR','ENSEIGNANT','SECRETAIRE']],
    ],
    // RH complète (15/08/2026) : fiche + contrats + congés + paie. La gestion
    // complète (fiches, grille, paie, avances) reste DIRECTEUR uniquement.
    // 'Mes informations' / 'Mes bulletins de paie' = libre-service.
    'Ressources humaines' => [
        ['Enseignant(e)s',        'pages/enseignants/liste.php', 'person-badge',   ['DIRECTEUR']],
        ['Grille salariale',      'pages/paie/grille.php',       'table',          ['DIRECTEUR']],
        ['Paie',                  'pages/paie/index.php',        'cash-stack',     ['DIRECTEUR']],
        ['Avances sur salaire',   'pages/paie/avances.php',      'cash',           ['DIRECTEUR']],
        ['Mes informations',      'pages/enseignants/mon_profil.php', 'person-vcard', ['DIRECTEUR','ENSEIGNANT','SECRETAIRE','COMPTABLE']],
        ['Mes bulletins de paie', 'pages/paie/mes_bulletins.php','receipt',        ['ENSEIGNANT','SECRETAIRE','COMPTABLE']],
    ],
    // Paramètres : 'Mon compte' (profil.php) est un libre-service ouvert à
    // TOUS — 'Configurations'/'Utilisateurs' restent DIRECTEUR uniquement.
    'Paramètres' => [
        ['Mon compte',        'profil.php',                    'key', []],
        ['Directeur',         'pages/fondateur/directeur.php', 'person-badge', ['FONDATEUR']],
        ['Configurations',    'pages/parametres/index.php',    'gear',        ['DIRECTEUR']],
        ['Utilisateurs',      'pages/utilisateurs/liste.php',  'person-gear', ['DIRECTEUR']],
    ],
];
