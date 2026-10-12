<?php
// noyau/layout/menu.php — DÉFINITION UNIQUE du menu latéral de l'école.
//
//  Extrait de noyau/layout/header.php (rendu) pour être partagé avec
//  fonctions.php : la même structure sert au RENDU du menu ET à
//  l'ENFORCEMENT des « privilèges par utilisateur » (acces_utilisateur —
//  liste de menus/sous-menus RETIRÉS à un compte, gérée depuis
//  primaire/pages/utilisateurs/acces.php). Voir fonctions.php::menu_definition().
//
//  Format d'une entrée : [label, url, icône bootstrap, rôles autorisés]
//    - rôles vide []           → visible par tous les rôles
//    - ['--', 'Libellé']       → séparateur (sous-section), pas un lien
//
//  Clé de privilège :
//    - un groupe entier   → 'grp:Nom du groupe'
//    - une entrée précise → son url (ex. 'primaire/pages/eleves/liste.php')

return [
    'Principal' => [
        ['Tableau de bord', 'dashboard.php', 'speedometer2', []],
    ],
    'Scolarité' => [
        ['Élèves',  'primaire/pages/eleves/liste.php',  'people',    []],
        ['Classes', 'primaire/pages/classes/liste.php', 'door-open', ['DIRECTEUR','SECRETAIRE','COMPTABLE']],
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
        ['Paiements',        'primaire/pages/finances/versement.php',    'cash-coin',            ['COMPTABLE','FONDATEUR']],
        ['Journal de caisse','primaire/pages/finances/journal.php',       'journal-text',         ['SECRETAIRE','COMPTABLE','FONDATEUR']],
        ['État par classe',  'primaire/pages/finances/etat_classe.php',   'list-check',           ['SECRETAIRE','COMPTABLE','FONDATEUR']],
        ['Répartition par classe', 'primaire/pages/finances/repartition_classes.php', 'pie-chart-fill', ['SECRETAIRE','COMPTABLE','FONDATEUR']],
        ['Impayés',          'primaire/pages/finances/impayes.php',       'exclamation-triangle', ['SECRETAIRE','COMPTABLE','FONDATEUR']],
        ['Cas sociaux',      'primaire/pages/finances/cas_sociaux.php',   'heart',                ['SECRETAIRE','COMPTABLE','FONDATEUR']],
        ['Statistiques',     'primaire/pages/finances/statistiques.php',  'bar-chart-line',       ['COMPTABLE','FONDATEUR']],

        ['--', 'Gestion des dépenses'],
        ['Nouvelle dépense',     'primaire/pages/depenses/saisie.php',       'dash-circle',    ['COMPTABLE','FONDATEUR']],
        ['Journal des dépenses', 'primaire/pages/depenses/journal.php',      'journal-minus',  ['SECRETAIRE','COMPTABLE','FONDATEUR']],
        ['Répartition par catégorie', 'primaire/pages/depenses/repartition_categories.php', 'pie-chart-fill', ['SECRETAIRE','COMPTABLE','FONDATEUR']],
        ['Statistiques',         'primaire/pages/depenses/statistiques.php', 'pie-chart',      ['COMPTABLE','FONDATEUR']],
    ],
    // Discipline/Pédagogie : rôles listés explicitement (au lieu de [] = tous)
    // depuis le 22/08/2026 — COMPTABLE (Agent financier) n'a normalement rien
    // à y faire, sauf s'il est EN MÊME TEMPS enseignant (voir $roles_effectifs
    // dans header.php, agent_est_aussi_enseignant() dans fonctions.php).
    'Discipline' => [
        ['Absences',   'primaire/pages/absences/index.php',    'person-x', ['DIRECTEUR','ENSEIGNANT','SECRETAIRE']],
    ],
    // Pédagogie : les deux pistes de notation coexistent (l'école est
    // bilingue français/arabe) et sont volontairement regroupées séparément —
    // toute la piste française d'abord, puis un séparateur « Arabe », puis la
    // piste arabe avec les MÊMES entrées.
    'Pédagogie' => [
        ['Saisie des notes',       'primaire/pages/notes/index.php',       'pencil-square', ['DIRECTEUR','ENSEIGNANT','SECRETAIRE']],
        ['Notes justifiées',       'primaire/pages/notes/absence_justifiee.php', 'person-x', ['DIRECTEUR','ENSEIGNANT','SECRETAIRE']],
        ['Annulation d\'évaluation','primaire/pages/notes/annulation_evaluation.php', 'calendar-x', ['SECRETAIRE']],
        ['Bulletins',              'primaire/pages/bulletins/index.php',   'file-earmark-text', ['DIRECTEUR','ENSEIGNANT','SECRETAIRE']],
        ['Statistiques',           'primaire/pages/statistiques/index.php','bar-chart-line', ['DIRECTEUR','ENSEIGNANT','SECRETAIRE']],
        ['Conseil de classe',      'primaire/pages/conseil_classe/index.php', 'mortarboard', ['DIRECTEUR','ENSEIGNANT','SECRETAIRE']],
        ['Tableau d\'honneur/ Relevé',    'primaire/pages/statistiques/documents.php', 'files', ['DIRECTEUR','ENSEIGNANT','SECRETAIRE']],
        ['Résultat annuel',        'primaire/pages/resultat_annuel/index.php', 'trophy', ['DIRECTEUR','ENSEIGNANT','SECRETAIRE']],
        ['Groupes de compétences', 'primaire/pages/competences/liste.php', 'diagram-3', ['DIRECTEUR','ENSEIGNANT','SECRETAIRE']],

        ['--', 'Arabe'],
        ['Saisie des notes',    'primaire/pages/notes_arabe/index.php',            'pencil-square',     ['DIRECTEUR','ENSEIGNANT','SECRETAIRE']],
        ['Notes justifiées',    'primaire/pages/notes_arabe/absence_justifiee.php','person-x',          ['DIRECTEUR','ENSEIGNANT','SECRETAIRE']],
        ['Bulletins',           'primaire/pages/bulletins_arabe/index.php',        'file-earmark-text', ['DIRECTEUR','ENSEIGNANT','SECRETAIRE']],
        ['Statistiques',        'primaire/pages/statistiques_arabe/index.php',     'bar-chart-line',    ['DIRECTEUR','ENSEIGNANT','SECRETAIRE']],
        ['Documents de classe', 'primaire/pages/statistiques_arabe/documents.php', 'files',             ['DIRECTEUR','ENSEIGNANT','SECRETAIRE']],
        ['Conseil de classe',   'primaire/pages/conseil_classe_arabe/index.php',   'mortarboard',       ['DIRECTEUR','ENSEIGNANT','SECRETAIRE']],
        ['Résultat annuel',     'primaire/pages/resultat_annuel_arabe/index.php',  'trophy',            ['DIRECTEUR','ENSEIGNANT','SECRETAIRE']],
        ['Groupes de matières', 'primaire/pages/matieres_arabe/liste.php',         'diagram-3',         ['DIRECTEUR','ENSEIGNANT','SECRETAIRE']],
    ],
    // RH : la fiche du personnel (Enseignant(e)s) est de la STRUCTURE —
    // DIRECTEUR/FONDATEUR. La grille salariale, la paie et les avances sont
    // de l'argent → agent financier (FONDATEUR en lecture).
    'Ressources humaines' => [
        ['Enseignant(e)s',        'primaire/pages/enseignants/liste.php', 'person-badge',   ['DIRECTEUR','FONDATEUR','SECRETAIRE']],
        ['Grille salariale',      'primaire/pages/paie/grille.php',       'table',          ['COMPTABLE','FONDATEUR']],
        ['Paie',                  'primaire/pages/paie/index.php',        'cash-stack',     ['COMPTABLE','FONDATEUR']],
        ['Avances sur salaire',   'primaire/pages/paie/avances.php',      'cash',           ['COMPTABLE','FONDATEUR']],
        ['Mes informations',      'primaire/pages/enseignants/mon_profil.php', 'person-vcard', ['DIRECTEUR','ENSEIGNANT','SECRETAIRE','COMPTABLE']],
        ['Mes bulletins de paie', 'primaire/pages/paie/mes_bulletins.php','receipt',        ['DIRECTEUR','ENSEIGNANT','SECRETAIRE','COMPTABLE']],
    ],
    // Paramètres : 'Mon compte' (profil.php) est un libre-service ouvert à TOUS.
    'Paramètres' => [
        ['Mon compte',        'profil.php',                    'key', []],
        ['Directeur',         'primaire/pages/fondateur/directeur.php', 'person-badge', ['FONDATEUR']],
        ['Configurations',    'primaire/pages/parametres/index.php',    'gear',        ['DIRECTEUR','FONDATEUR']],
        ['Utilisateurs',      'primaire/pages/utilisateurs/liste.php',  'person-gear', ['DIRECTEUR']],
        ['Journal d\'audit',  'primaire/pages/utilisateurs/journal.php','shield-check',['DIRECTEUR','FONDATEUR']],
        // Licence (bd/lib/licence.php) : ouvert à TOUS ([] = tout rôle voit
        // l'entrée) — la page elle-même ne montre le statut qu'en lecture
        // pour la plupart des comptes ; seul le propriétaire (génération de
        // clé / renouvellement direct) et le Directeur/Fondateur (saisie
        // d'une clé reçue) y ont des actions.
        ['Licence',           'primaire/pages/parametres/licence.php',  'award',       []],
    ],
    // Configuration : le paramétrage que DIRECTEUR / FONDATEUR peuvent
    // enregistrer (structure de facturation) sans avoir accès au menu
    // Finances lui-même (séparation des pouvoirs, 11/09/2026).
    // « Années & séquences » retirée le 15/09/2026 : la page
    // (primaire/pages/parametres/index.php?onglet=annees) est désormais réservée au
    // propriétaire (voir primaire/pages/parametres/index.php) — plus rien
    // d'actionnable ici pour Directeur/Fondateur, l'entrée n'avait donc
    // plus lieu d'être dans ce menu de raccourcis.
    'Configuration' => [
        ['Frais & obligations',    'primaire/pages/finances/obligations.php', 'card-checklist', ['DIRECTEUR','FONDATEUR','SECRETAIRE','COMPTABLE']],
        ['Catégories de dépenses', 'primaire/pages/depenses/categories.php',  'tags',           ['DIRECTEUR','FONDATEUR','SECRETAIRE','COMPTABLE']],
    ],
];
