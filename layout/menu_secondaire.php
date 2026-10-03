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
// PAIEMENT PRIVÉ vs PAIEMENT PUBLIQUE : jamais les deux à la fois — le
// statut de l'établissement (etablissement.statut, migration v4 secondaire)
// tranche. Demande explicite du 22/09/2026 (jusqu'ici les deux groupes
// étaient toujours visibles ensemble, quel que soit le statut réel).
// $GLOBALS['statut_ecole_menu'] : statut imposé par l'appelant quand le menu
// d'une AUTRE école est construit hors de celle-ci (association/acces.php,
// portail Privilèges — aucune école « ouverte », get_etablissement() vide :
// une école privée y montrait PAIEMENT PUBLIQUE, 03/10/2026).
$statut_ecole = $GLOBALS['statut_ecole_menu']
    ?? (function_exists('get_etablissement') ? get_etablissement()['statut'] ?? 'public' : 'public');

$menu = [
    'Principal' => [
        ['Tableau de bord', 'dashboard.php', 'speedometer2', []],
    ],
    'Scolarité' => [
        ['Élèves',  'secondaire/pages/eleves/liste.php',  'people',    ['ADMIN','PROVISEUR', 'FONDATEUR','CENSEUR','SG','SECRETAIRE']],
        ['Classes', 'secondaire/pages/classes/liste.php', 'door-open', ['ADMIN','PROVISEUR', 'FONDATEUR','CENSEUR']],
    ],
    'Pédagogie' => [
        ['Matières',             'secondaire/pages/matieres/liste.php',         'journal-bookmark',   ['ADMIN','PROVISEUR', 'FONDATEUR','CENSEUR']],
        ['Notes',                'secondaire/pages/notes/index.php',            'pencil-square',      ['ADMIN','PROVISEUR', 'FONDATEUR','CENSEUR','ENSEIGNANT']],
        ['Absences',             'secondaire/pages/absences/index.php',         'person-x',           ['ADMIN','PROVISEUR', 'FONDATEUR','CENSEUR','ENSEIGNANT']],
        ['Bulletins',            'secondaire/pages/bulletins/index.php',        'file-earmark-text',  ['ADMIN','PROVISEUR', 'FONDATEUR','CENSEUR','ENSEIGNANT']],
        ['Conseil de Classe',    'secondaire/pages/conseil_classe/index.php',   'mortarboard',         ['ADMIN','PROVISEUR', 'FONDATEUR','CENSEUR','SG','ENSEIGNANT']],
        ['Statistiques',         'secondaire/pages/statistiques/index.php',     'bar-chart-line',      ['ADMIN','PROVISEUR', 'FONDATEUR','CENSEUR','ENSEIGNANT']],
        ['Documents de classe',  'secondaire/pages/statistiques/documents.php', 'files',                ['ADMIN','PROVISEUR', 'FONDATEUR','CENSEUR','ENSEIGNANT']],
        ['Résultat annuel',      'secondaire/pages/statistiques/resultat_annuel.php', 'trophy',         ['ADMIN','PROVISEUR', 'FONDATEUR','CENSEUR','ENSEIGNANT']],
    ],
    'Discipline' => [
        ['Discipline', 'secondaire/pages/discipline/index.php', 'shield-exclamation', ['ADMIN','CENSEUR','PROVISEUR','FONDATEUR','SG','ENSEIGNANT']],
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
        ['Enregistrer un paiement', 'secondaire/pages/paiements_prives/versement.php',            'cash-stack',          ['ADMIN','PROVISEUR','FONDATEUR','SECRETAIRE','INTENDANT']],
        ['Frais exigibles',         'secondaire/pages/paiements_prives/obligations.php',           'cash-coin',            ['ADMIN','PROVISEUR','FONDATEUR','INTENDANT']],
        ['Journal de caisse',       'secondaire/pages/paiements_prives/journal.php',                'journal-text',        ['ADMIN','PROVISEUR','FONDATEUR','SECRETAIRE','INTENDANT']],
        ['État par classe',         'secondaire/pages/paiements_prives/etat_classe.php',            'list-check',          ['ADMIN','PROVISEUR','FONDATEUR','SECRETAIRE','INTENDANT']],
        ['Répartition par classe',  'secondaire/pages/paiements_prives/repartition_classes.php',    'pie-chart-fill',      ['ADMIN','PROVISEUR','FONDATEUR','SECRETAIRE','INTENDANT']],
        ['Impayés',                 'secondaire/pages/paiements_prives/impayes.php',                'exclamation-triangle',['ADMIN','PROVISEUR','FONDATEUR','SECRETAIRE','INTENDANT']],
        ['Statistiques',            'secondaire/pages/paiements_prives/statistiques.php',           'bar-chart-line',      ['ADMIN','PROVISEUR','FONDATEUR','INTENDANT']],
        ['--', 'Gestion des dépenses'],
        ['Nouvelle dépense',        'secondaire/pages/depenses_privees/saisie.php',                 'dash-circle',         ['ADMIN','PROVISEUR','FONDATEUR','SECRETAIRE','INTENDANT']],
        ['Catégories de dépenses',  'secondaire/pages/depenses_privees/categories.php',             'tags',                ['ADMIN','PROVISEUR','FONDATEUR','INTENDANT']],
        ['Journal des dépenses',    'secondaire/pages/depenses_privees/journal.php',                'journal-minus',       ['ADMIN','PROVISEUR','FONDATEUR','SECRETAIRE','INTENDANT']],
        ['Répartition par catégorie', 'secondaire/pages/depenses_privees/repartition_categories.php','pie-chart-fill',    ['ADMIN','PROVISEUR','FONDATEUR','SECRETAIRE','INTENDANT']],
        ['Statistiques',            'secondaire/pages/depenses_privees/statistiques.php',           'pie-chart',           ['ADMIN','PROVISEUR','FONDATEUR','INTENDANT']],
    ],
    // Ex-« Finances » — renommé « PAIEMENT PUBLIQUE » (même contenu, même
    // demande explicite du 17/09/2026) pour le distinguer du nouveau groupe
    // « PAIEMENT PRIVÉ » ci-dessus.
    'PAIEMENT PUBLIQUE' => [
        ['Enregistrer un paiement', 'secondaire/pages/paiements/index.php',       'cash-stack',          ['ADMIN','PROVISEUR', 'FONDATEUR','CENSEUR','INTENDANT']],
        ['Frais exigibles',         'secondaire/pages/paiements/obligations.php', 'cash-coin',            ['ADMIN','PROVISEUR', 'FONDATEUR','CENSEUR']],
        ['Opérateurs de paiement',  'secondaire/pages/paiements/operateurs.php',  'credit-card-2-front',  ['ADMIN','PROVISEUR', 'FONDATEUR','CENSEUR']],
        ['Impayés',                 'secondaire/pages/paiements/rapport.php?onglet=insolvables', 'exclamation-diamond', ['ADMIN','PROVISEUR', 'FONDATEUR','CENSEUR','INTENDANT']],
        ['Rapports',                'secondaire/pages/paiements/rapport.php',     'graph-up',             ['ADMIN','PROVISEUR', 'FONDATEUR','CENSEUR','INTENDANT']],
        ['Signatures numériques',   'secondaire/pages/paiements/signatures.php',  'vector-pen',           ['ADMIN','PROVISEUR', 'FONDATEUR','CENSEUR','INTENDANT','SG']],
    ],
    // Paie : porté du module Paie du PRIMAIRE (pages/paie/*,
    // paie_fonctions.php, réutilisé TEL QUEL — voir secondaire/pages/paie/
    // index.php) sous secondaire/pages/paie/. Accès élargi par rapport au
    // primaire (Comptable+Fondateur seulement) : ADMIN/PROVISEUR/FONDATEUR/
    // INTENDANT, même liste que PAIEMENT PRIVÉ/PUBLIQUE ci-dessus — demande
    // explicite du 21/09/2026. « Mes bulletins de paie » reste ouvert au
    // personnel payé qui n'a pas déjà cet accès complet.
    'Ressources humaines' => [
        ['Enseignants',           'secondaire/pages/enseignants/liste.php',     'person-badge',       ['ADMIN','PROVISEUR', 'FONDATEUR','CENSEUR']],
        ['Grille salariale',      'secondaire/pages/paie/grille.php',           'table',              ['ADMIN','PROVISEUR', 'FONDATEUR','INTENDANT']],
        ['Paie',                  'secondaire/pages/paie/index.php',            'cash-stack',         ['ADMIN','PROVISEUR', 'FONDATEUR','INTENDANT']],
        ['Avances sur salaire',   'secondaire/pages/paie/avances.php',          'cash',               ['ADMIN','PROVISEUR', 'FONDATEUR','INTENDANT']],
        ['Mes informations',      'secondaire/pages/enseignants/mon_profil.php','person-vcard',       ['ADMIN','PROVISEUR','CENSEUR','SG','INTENDANT','SECRETAIRE','ENSEIGNANT']],
        ['Mes bulletins de paie', 'secondaire/pages/paie/mes_bulletins.php',    'receipt',            ['ADMIN','PROVISEUR','CENSEUR','SG','INTENDANT','SECRETAIRE','ENSEIGNANT']],
        ['Demandes de documents', 'secondaire/pages/demandes/index.php',        'file-earmark-text',  ['ENSEIGNANT','CENSEUR','PROVISEUR', 'FONDATEUR','ADMIN']],
    ],
    // Paramètres : alignée sur layout/menu.php (primaire) — mêmes 6 entrées,
    // même ordre, mêmes fonctionnalités (Mon compte, Directeur/Proviseur,
    // Configurations, Utilisateurs, Journal d'audit, Licence). Portée le
    // 24/09/2026 (jusqu'ici groupe « Administration » à seulement 2 entrées,
    // sans Mon compte/Directeur/Journal/Licence — demande explicite).
    'Paramètres' => [
        ['Mon compte',        'profil.php',                              'key',          []],
        ['Directeur',         'secondaire/pages/fondateur/directeur.php', 'person-badge', ['FONDATEUR']],
        ['Configurations',    'secondaire/pages/parametres/index.php',   'gear',        ['ADMIN','PROVISEUR', 'FONDATEUR']],
        ['Utilisateurs',      'secondaire/pages/utilisateurs/liste.php', 'person-gear', ['ADMIN', 'FONDATEUR']],
        ['Journal d\'audit',  'secondaire/pages/utilisateurs/journal.php','shield-check',['ADMIN','PROVISEUR', 'FONDATEUR']],
        ['Licence',           'secondaire/pages/parametres/licence.php', 'award',       []],
    ],
];

if ($statut_ecole === 'prive') {
    unset($menu['PAIEMENT PUBLIQUE']);
} else {
    unset($menu['PAIEMENT PRIVÉ']);
}
return $menu;
