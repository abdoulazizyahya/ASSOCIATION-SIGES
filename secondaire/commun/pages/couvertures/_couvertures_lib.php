<?php
// secondaire/commun/pages/couvertures/_couvertures_lib.php — couvertures de
// carnets de notes (10/11/2026), primaire et secondaire, aux couleurs de SIGES.
require_once __DIR__ . '/../../../../noyau/partage/ecole_donnees.php';

const COUV_MODELES = [
    'officiel' => ['Officiel',  'Cadre double sobre, conforme aux carnets officiels'],
    'siges'    => ['Moderne',   'Bandeau marine et filet or, aux couleurs de SIGES'],
    'prestige' => ['Prestige',  'Double filet or, coins ornés, présentation soignée'],
    'leger'    => ['Économique','Trait fin, sans aplat de couleur — économise l\'encre'],
    'perso'    => ['Mon modèle', 'Dessiné dans Paramètres → Apparence des documents → Concepteur'],
];

function couv_roles(): array {
    return ed_secondaire() ? ['ADMIN', 'PROVISEUR', 'FONDATEUR', 'CENSEUR', 'SECRETAIRE'] : ['DIRECTEUR', 'SECRETAIRE', 'FONDATEUR'];
}

function couv_reglages(): array {
    $sec = ed_secondaire();
    return ed_reglages('couvertures', [
        'modele' => 'siges', 'format' => 'A4',
        'titre_fr' => $sec ? 'CARNET DE NOTES' : 'CARNET DE NOTES ET D\'ÉVALUATION',
        'titre_en' => 'REPORT BOOKLET',
        'photo' => 1, 'echelle' => 1, 'note' => 1, 'reglement' => 0, 'cadre' => 0,
        'texte_note' => "Chers parents, ce carnet est le lien entre l'école et la famille. Consultez-le régulièrement, signez chaque bulletin et n'hésitez pas à rencontrer les enseignants pour suivre la progression de votre enfant.",
        'texte_reglement' => "La ponctualité et l'assiduité sont obligatoires. La tenue réglementaire est exigée. Toute absence doit être justifiée par écrit. Le respect des personnes et des biens de l'établissement est de rigueur.",
    ]);
}

/** Échelle d'appréciation imprimée : celle des bulletins de l'école. */
function couv_echelle(): array {
    if (ed_secondaire()) return [
        ['A+ / A', '16 à 20', 'Compétences très bien acquises'], ['B+ / B', '14 à 16', 'Compétences bien acquises'],
        ['C+', '12 à 14', 'Compétences acquises'], ['C', '10 à 12', 'Compétences moyennement acquises'], ['D', '0 à 10', 'Compétences non acquises'],
    ];
    return [['A+', '18 à 20', 'Expert'], ['A', '14 à 18', 'Compétences acquises'], ['ECA', '10 à 14', 'En cours d\'acquisition'], ['NA', '0 à 10', 'Non acquis']];
}
