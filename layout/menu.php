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
    // Séparation des pouvoirs (11/09/2026) : le DIRECTEUR n'est plus dans ces
    // entrées (défaut). L'enregistrement de l'argent (Paiements, Nouvelle
    // dépense) est réservé à l'agent financier ; le FONDATEUR y accède en
    // lecture (bypass exiger_role). Une règle « Privilèges » centrale
    // (association/acces.php) peut rouvrir une entrée à un directeur précis.
    'Finances' => [
        ['--', 'Gestion des inscriptions'],
        ['Paiements',        'pages/finances/versement.php',    'cash-coin',            ['COMPTABLE','FONDATEUR']],
        ['Journal de caisse','pages/finances/journal.php',       'journal-text',         ['SECRETAIRE','COMPTABLE','FONDATEUR']],
        ['État par classe',  'pages/finances/etat_classe.php',   'list-check',           ['SECRETAIRE','COMPTABLE','FONDATEUR']],
        ['Répartition par classe', 'pages/finances/repartition_classes.php', 'pie-chart-fill', ['SECRETAIRE','COMPTABLE','FONDATEUR']],
        ['Impayés',          'pages/finances/impayes.php',       'exclamation-triangle', ['SECRETAIRE','COMPTABLE','FONDATEUR']],
        ['Cas sociaux',      'pages/finances/cas_sociaux.php',   'heart',                ['SECRETAIRE','COMPTABLE','FONDATEUR']],
        ['Statistiques',     'pages/finances/statistiques.php',  'bar-chart-line',       ['COMPTABLE','FONDATEUR']],

        ['--', 'Gestion des dépenses'],
        ['Nouvelle dépense',     'pages/depenses/saisie.php',       'dash-circle',    ['COMPTABLE','FONDATEUR']],
        ['Journal des dépenses', 'pages/depenses/journal.php',      'journal-minus',  ['SECRETAIRE','COMPTABLE','FONDATEUR']],
        ['Répartition par catégorie', 'pages/depenses/repartition_categories.php', 'pie-chart-fill', ['SECRETAIRE','COMPTABLE','FONDATEUR']],
        ['Statistiques',         'pages/depenses/statistiques.php', 'pie-chart',      ['COMPTABLE','FONDATEUR']],
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
        ['Annulation d\'évaluation','pages/notes/annulation_evaluation.php', 'calendar-x', ['SECRETAIRE']],
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
    // RH : la fiche du personnel (Enseignant(e)s) est de la STRUCTURE —
    // DIRECTEUR/FONDATEUR. La grille salariale, la paie et les avances sont
    // de l'argent → agent financier (FONDATEUR en lecture).
    'Ressources humaines' => [
        ['Enseignant(e)s',        'pages/enseignants/liste.php', 'person-badge',   ['DIRECTEUR','FONDATEUR','SECRETAIRE']],
        ['Grille salariale',      'pages/paie/grille.php',       'table',          ['COMPTABLE','FONDATEUR']],
        ['Paie',                  'pages/paie/index.php',        'cash-stack',     ['COMPTABLE','FONDATEUR']],
        ['Avances sur salaire',   'pages/paie/avances.php',      'cash',           ['COMPTABLE','FONDATEUR']],
        ['Mes informations',      'pages/enseignants/mon_profil.php', 'person-vcard', ['DIRECTEUR','ENSEIGNANT','SECRETAIRE','COMPTABLE']],
        ['Mes bulletins de paie', 'pages/paie/mes_bulletins.php','receipt',        ['ENSEIGNANT','SECRETAIRE','COMPTABLE']],
    ],
    // Paramètres : 'Mon compte' (profil.php) est un libre-service ouvert à TOUS.
    'Paramètres' => [
        ['Mon compte',        'profil.php',                    'key', []],
        ['Directeur',         'pages/fondateur/directeur.php', 'person-badge', ['FONDATEUR']],
        ['Configurations',    'pages/parametres/index.php',    'gear',        ['DIRECTEUR','FONDATEUR']],
        ['Utilisateurs',      'pages/utilisateurs/liste.php',  'person-gear', ['DIRECTEUR']],
        ['Journal d\'audit',  'pages/utilisateurs/journal.php','shield-check',['DIRECTEUR','FONDATEUR']],
    ],
    // Configuration : le paramétrage que DIRECTEUR / FONDATEUR peuvent
    // enregistrer (structure de facturation) sans avoir accès au menu
    // Finances lui-même (séparation des pouvoirs, 11/09/2026).
    'Configuration' => [
        ['Frais & obligations',    'pages/finances/obligations.php', 'card-checklist', ['DIRECTEUR','FONDATEUR','SECRETAIRE','COMPTABLE']],
        ['Catégories de dépenses', 'pages/depenses/categories.php',  'tags',           ['DIRECTEUR','FONDATEUR','SECRETAIRE','COMPTABLE']],
        ['Années & séquences',     'pages/parametres/index.php?onglet=annees', 'calendar-range', ['DIRECTEUR','FONDATEUR']],
    ],
];
