<?php
// noyau/layout/menu_secondaire.php — menu latéral pour une école de type
// 'secondaire' (schema_ref_ecole_secondaire.sql, porté de LAM_ABZ).
// Chargé par fonctions.php::menu_definition() à la place de noyau/layout/menu.php
// quand type_enseignement_courant() === 'secondaire'.
//
// Même format que noyau/layout/menu.php : [label, url, icône, rôles autorisés].
//   - rôles vide []           → visible par tous les rôles
//   - ['--', 'Libellé']       → séparateur, pas un lien
//
// Rôles (utilisateur.role, ENUM figé au schéma) : ADMIN, PROVISEUR,
// CENSEUR, SG (Surveillant Général), SECRETAIRE, ENSEIGNANT, INTENDANT.
//
// ⚠ Groupes/ordre/libellés recopiés À L'IDENTIQUE de LAM_ABZ/layout/
// header.php (demande explicite du 16/09/2026 : « conforme au projet
// d'origine, même menu/sous-menu ») — ne pas réorganiser sans revérifier
// cette source. Seules les URLs sont adaptées (secondaire/commun/pages/... et,
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
        ['Tableau de bord', 'noyau/dashboard.php', 'speedometer2', []],
    ],
    'Scolarité' => [
        ['Élèves',  'secondaire/commun/pages/eleves/liste.php',  'people',    ['ADMIN','PROVISEUR', 'FONDATEUR','CENSEUR','SG','SECRETAIRE']],
        ['Classes', 'secondaire/commun/pages/classes/liste.php', 'door-open', ['ADMIN','PROVISEUR', 'FONDATEUR','CENSEUR']],
    ],
    'Pédagogie' => [
        ['Matières',             'secondaire/commun/pages/matieres/liste.php',         'journal-bookmark',   ['ADMIN','PROVISEUR', 'FONDATEUR','CENSEUR']],
        ['Notes',                'secondaire/general/pages/notes/index.php',            'pencil-square',      ['ADMIN','PROVISEUR', 'FONDATEUR','CENSEUR','ENSEIGNANT']],
        ['Absences',             'secondaire/commun/pages/absences/index.php',         'person-x',           ['ADMIN','PROVISEUR', 'FONDATEUR','CENSEUR','ENSEIGNANT']],
        ['Bulletins',            'secondaire/general/pages/bulletins/index.php',        'file-earmark-text',  ['ADMIN','PROVISEUR', 'FONDATEUR','CENSEUR','ENSEIGNANT']],
        ['Conseil de Classe',    'secondaire/commun/pages/conseil_classe/index.php',   'mortarboard',         ['ADMIN','PROVISEUR', 'FONDATEUR','CENSEUR','SG','ENSEIGNANT']],
        ['Statistiques',         'secondaire/commun/pages/statistiques/index.php',     'bar-chart-line',      ['ADMIN','PROVISEUR', 'FONDATEUR','CENSEUR','ENSEIGNANT']],
        ['Documents de classe',  'secondaire/commun/pages/statistiques/documents.php', 'files',                ['ADMIN','PROVISEUR', 'FONDATEUR','CENSEUR','ENSEIGNANT']],
        ['Résultat annuel',      'secondaire/commun/pages/statistiques/resultat_annuel.php', 'trophy',         ['ADMIN','PROVISEUR', 'FONDATEUR','CENSEUR','ENSEIGNANT']],
    ],
    'Discipline' => [
        ['Discipline', 'secondaire/commun/pages/discipline/index.php', 'shield-exclamation', ['ADMIN','CENSEUR','PROVISEUR','FONDATEUR','SG','ENSEIGNANT']],
    ],
    // Module Emploi du temps (migration secondaire v17, 15/10/2026) : construit
    // par le Censeur (écriture ADMIN/PROVISEUR/CENSEUR, voir _edt_lib.php),
    // consulté par l'équipe de direction ; l'enseignant voit le sien une fois publié.
    'EMPLOI DU TEMPS' => [
        // 3 étapes, chacune avec ses onglets dans la page (pas de doublon menu / onglets, 16/10/2026)
        ['Préparation',          'secondaire/commun/pages/emploi_temps/parametres.php', 'sliders',        ['ADMIN','PROVISEUR','FONDATEUR','CENSEUR']],
        ['Construction',         'secondaire/commun/pages/emploi_temps/saisie.php',     'magic',          ['ADMIN','PROVISEUR','FONDATEUR','CENSEUR','SG']],
        ['Consulter / imprimer', 'secondaire/commun/pages/emploi_temps/consulter.php',  'calendar3-week', ['ADMIN','PROVISEUR','FONDATEUR','CENSEUR','SG','SECRETAIRE','INTENDANT']],
        ['Mon emploi du temps',  'secondaire/commun/pages/emploi_temps/consulter.php?moi=1', 'calendar-week', ['ENSEIGNANT']],
    ],
    // « PAIEMENT PRIVÉ » : porté du module Finances/Dépenses du PRIMAIRE
    // (primaire/pages/finances/*, primaire/pages/depenses/*) sous secondaire/commun/pages/
    // paiements_prives/ + secondaire/commun/pages/depenses_privees/, comptabilité
    // 100% indépendante de PAIEMENT PUBLIQUE ci-dessous (tables dédiées —
    // voir fonctions.php, fonctions prive_*). Demande explicite du
    // 17/09/2026. Pas de « Cas sociaux » ici (absent du schéma secondaire,
    // contrairement au primaire) — 6 entrées « Gestion des inscriptions »
    // au lieu de 7 côté primaire. « Frais exigibles »/« Catégories de
    // dépenses » regroupées ICI (pas dans Administration) pour rester
    // cohérent avec PAIEMENT PUBLIQUE ci-dessous, qui fait déjà de même.
    'PAIEMENT PRIVÉ' => [
        ['--', 'Gestion des inscriptions'],
        ['Enregistrer un paiement', 'secondaire/commun/pages/paiements_prives/versement.php',            'cash-stack',          ['ADMIN','PROVISEUR','FONDATEUR','SECRETAIRE','INTENDANT']],
        ['Frais exigibles',         'secondaire/commun/pages/paiements_prives/obligations.php',           'cash-coin',            ['ADMIN','PROVISEUR','FONDATEUR','INTENDANT']],
        ['Journal de caisse',       'secondaire/commun/pages/paiements_prives/journal.php',                'journal-text',        ['ADMIN','PROVISEUR','FONDATEUR','SECRETAIRE','INTENDANT']],
        ['État par classe',         'secondaire/commun/pages/paiements_prives/etat_classe.php',            'list-check',          ['ADMIN','PROVISEUR','FONDATEUR','SECRETAIRE','INTENDANT']],
        ['Répartition par classe',  'secondaire/commun/pages/paiements_prives/repartition_classes.php',    'pie-chart-fill',      ['ADMIN','PROVISEUR','FONDATEUR','SECRETAIRE','INTENDANT']],
        ['Impayés',                 'secondaire/commun/pages/paiements_prives/impayes.php',                'exclamation-triangle',['ADMIN','PROVISEUR','FONDATEUR','SECRETAIRE','INTENDANT']],
        ['Statistiques',            'secondaire/commun/pages/paiements_prives/statistiques.php',           'bar-chart-line',      ['ADMIN','PROVISEUR','FONDATEUR','INTENDANT']],
        ['--', 'Gestion des dépenses'],
        ['Nouvelle dépense',        'secondaire/commun/pages/depenses_privees/saisie.php',                 'dash-circle',         ['ADMIN','PROVISEUR','FONDATEUR','SECRETAIRE','INTENDANT']],
        ['Catégories de dépenses',  'secondaire/commun/pages/depenses_privees/categories.php',             'tags',                ['ADMIN','PROVISEUR','FONDATEUR','INTENDANT']],
        ['Journal des dépenses',    'secondaire/commun/pages/depenses_privees/journal.php',                'journal-minus',       ['ADMIN','PROVISEUR','FONDATEUR','SECRETAIRE','INTENDANT']],
        ['Répartition par catégorie', 'secondaire/commun/pages/depenses_privees/repartition_categories.php','pie-chart-fill',    ['ADMIN','PROVISEUR','FONDATEUR','SECRETAIRE','INTENDANT']],
        ['Statistiques',            'secondaire/commun/pages/depenses_privees/statistiques.php',           'pie-chart',           ['ADMIN','PROVISEUR','FONDATEUR','INTENDANT']],
    ],
    // Ex-« Finances » — renommé « PAIEMENT PUBLIQUE » (même contenu, même
    // demande explicite du 17/09/2026) pour le distinguer du nouveau groupe
    // « PAIEMENT PRIVÉ » ci-dessus.
    'PAIEMENT PUBLIQUE' => [
        ['Enregistrer un paiement', 'secondaire/commun/pages/paiements/index.php',       'cash-stack',          ['ADMIN','PROVISEUR', 'FONDATEUR','CENSEUR','INTENDANT']],
        ['Frais exigibles',         'secondaire/commun/pages/paiements/obligations.php', 'cash-coin',            ['ADMIN','PROVISEUR', 'FONDATEUR','CENSEUR']],
        ['Opérateurs de paiement',  'secondaire/commun/pages/paiements/operateurs.php',  'credit-card-2-front',  ['ADMIN','PROVISEUR', 'FONDATEUR','CENSEUR']],
        ['Impayés',                 'secondaire/commun/pages/paiements/rapport.php?onglet=insolvables', 'exclamation-diamond', ['ADMIN','PROVISEUR', 'FONDATEUR','CENSEUR','INTENDANT']],
        ['Rapports',                'secondaire/commun/pages/paiements/rapport.php',     'graph-up',             ['ADMIN','PROVISEUR', 'FONDATEUR','CENSEUR','INTENDANT']],
        ['Statistiques avancées',   'secondaire/commun/pages/paiements/statistiques.php', 'bar-chart-line',     ['ADMIN','PROVISEUR', 'FONDATEUR','CENSEUR','INTENDANT']],   // porté d'ABZ_MBE
        ['Signatures numériques',   'secondaire/commun/pages/paiements/signatures.php',  'vector-pen',           ['ADMIN','PROVISEUR', 'FONDATEUR','CENSEUR','INTENDANT','SG']],
    ],
    // Paie : porté du module Paie du PRIMAIRE (primaire/pages/paie/*,
    // paie_fonctions.php, réutilisé TEL QUEL — voir secondaire/commun/pages/paie/
    // index.php) sous secondaire/commun/pages/paie/. Accès élargi par rapport au
    // primaire (Comptable+Fondateur seulement) : ADMIN/PROVISEUR/FONDATEUR/
    // INTENDANT, même liste que PAIEMENT PRIVÉ/PUBLIQUE ci-dessus — demande
    // explicite du 21/09/2026. « Mes bulletins de paie » reste ouvert au
    // personnel payé qui n'a pas déjà cet accès complet.
    'Ressources humaines' => [
        ['Enseignants',           'secondaire/commun/pages/enseignants/liste.php',     'person-badge',       ['ADMIN','PROVISEUR', 'FONDATEUR','CENSEUR']],
        // Remis au menu le 23/10/2026 : identifiants / mots de passe de tous les comptes (le Proviseur ne gère pas Fondateur, Admin ni autre Proviseur)
        ['Comptes utilisateurs',  'secondaire/commun/pages/utilisateurs/liste.php',    'people',             ['ADMIN','PROVISEUR', 'FONDATEUR']],
        ['Grille salariale',      'secondaire/commun/pages/paie/grille.php',           'table',              ['ADMIN','PROVISEUR', 'FONDATEUR','INTENDANT']],
        ['Paie',                  'secondaire/commun/pages/paie/index.php',            'cash-stack',         ['ADMIN','PROVISEUR', 'FONDATEUR','INTENDANT']],
        ['Avances sur salaire',   'secondaire/commun/pages/paie/avances.php',          'cash',               ['ADMIN','PROVISEUR', 'FONDATEUR','INTENDANT']],
        ['Mes informations',      'secondaire/commun/pages/enseignants/mon_profil.php','person-vcard',       ['ADMIN','PROVISEUR','CENSEUR','SG','INTENDANT','SECRETAIRE','ENSEIGNANT']],
        ['Mes bulletins de paie', 'secondaire/commun/pages/paie/mes_bulletins.php',    'receipt',            ['ADMIN','PROVISEUR','CENSEUR','SG','INTENDANT','SECRETAIRE','ENSEIGNANT']],
        ['Demandes de documents', 'secondaire/commun/pages/demandes/index.php',        'file-earmark-text',  ['ENSEIGNANT','CENSEUR','PROVISEUR', 'FONDATEUR','ADMIN']],
        ['Validité des documents', 'secondaire/commun/pages/enseignants/validite_documents.php', 'file-earmark-lock', ['ADMIN','PROVISEUR', 'FONDATEUR']],   // porté d'ABZ_MBE
    ],
    // Paramètres : alignée sur noyau/layout/menu.php (primaire) — mêmes 6 entrées,
    // même ordre, mêmes fonctionnalités (Mon compte, Directeur/Proviseur,
    // Configurations, Utilisateurs, Journal d'audit, Licence). Portée le
    // 24/09/2026 (jusqu'ici groupe « Administration » à seulement 2 entrées,
    // sans Mon compte/Directeur/Journal/Licence — demande explicite).
    'Paramètres' => [
        ['Mon compte',        'compte/profil.php',                              'key',          []],
        // Secondaire : pas de « Directeur » (17/10/2026) — Proviseur (public) ou Principal (privé)
        [$statut_ecole === 'prive' ? 'Principal' : 'Proviseur', 'secondaire/commun/pages/fondateur/directeur.php', 'person-badge', ['FONDATEUR']],
        ['Configurations',    'secondaire/commun/pages/parametres/index.php',   'gear',        ['ADMIN','PROVISEUR', 'FONDATEUR']],
        // « Utilisateurs » retiré (17/10/2026) : comptes gérés dans Ressources humaines → Enseignants
        // (fiche de chaque membre, bouton « Tous les comptes » de la liste).
        ['Journal d\'audit',  'secondaire/commun/pages/utilisateurs/journal.php','shield-check',['ADMIN','PROVISEUR', 'FONDATEUR']],
        ['Licence',           'secondaire/commun/pages/parametres/licence.php', 'award',       []],
    ],
];

// Proviseur / censeur / SG qui dispense aussi un cours (17/10/2026) : « Mon emploi du
// temps » pour tous ; pour le SG, les pages pédagogiques en vue enseignant (ses classes).
// Jamais pour un menu construit hors de l'école (portail Privilèges, $GLOBALS['statut_ecole_menu']).
if (!isset($GLOBALS['statut_ecole_menu']) && function_exists('sec_enseigne_connecte')
    && in_array(role_connecte(), ['PROVISEUR', 'CENSEUR', 'SG'], true)) {
    try { $enseigne = sec_enseigne_connecte(); } catch (\Throwable $e) { $enseigne = false; }
    if ($enseigne) {
        foreach ($menu as $g => $items) foreach ($items as $k => $it) {
            if (($it[1] ?? '') === 'secondaire/commun/pages/emploi_temps/consulter.php?moi=1') $menu[$g][$k][3][] = role_connecte();
            if (role_connecte() === 'SG' && $g === 'Pédagogie' && in_array('ENSEIGNANT', $it[3] ?? [], true) && !in_array('SG', $it[3], true)) $menu[$g][$k][3][] = 'SG';
        }
    }
}

if ($statut_ecole === 'prive') {
    unset($menu['PAIEMENT PUBLIQUE']);
} else {
    unset($menu['PAIEMENT PRIVÉ']);
    // École publique : le personnel est payé par l'État — aucune entrée salaire
    // (grille, paie, avances, bulletins de paie) dans Ressources humaines (17/10/2026).
    $menu['Ressources humaines'] = array_values(array_filter($menu['Ressources humaines'],
        fn($e) => !str_contains($e[1] ?? '', 'secondaire/commun/pages/paie/')));
}
return $menu;
