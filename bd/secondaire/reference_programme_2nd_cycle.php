<?php
// Programme OFFICIEL du 2nd cycle de l'enseignement secondaire général
// (sous-système francophone) : arrêté n°92/22/MINESEC du 17 mars 2022 portant
// définition de la nature, de la durée et des coefficients des matières dans
// les séries et classes du second cycle ESG (officedubac.cm). Remplace les
// valeurs indicatives du 30/10/2026 (demande du 01/11/2026 : être conforme à la
// note ministérielle). Appliqué à la création d'une classe de 2nde, 1ère ou Tle
// (secondaire_auto_matieres_2nd_cycle(), noyau/fonctions.php) — c'est un
// MODÈLE : l'école ajuste ensuite matières, coefficients et groupes dans
// Pédagogie → Matières → Affectation par classe.
//
// [code_niveau][série] => [libellé de matière => [coefficient, ordre, groupe]]
//   groupe : 1 = « 1er GROUPE » (matières de la spécialité), 2 = « 2e GROUPE ».
//   « LVII » = chaque langue vivante II active, réservée aux élèves de sa
//   série (Allemand, Espagnol…) : les classes littéraires ALL/ESP/ITA/CHI/ARA
//   sont toutes de série A4, communes ou non.
// Série D : pas de 2nde D dans l'arrêté (la 2nde scientifique est la 2nde C).

$a4 = fn(int $philo) => [
    'LITTÉRATURE' => [3, 1, 1], 'FRANÇAIS' => [2, 2, 1], 'PHILOSOPHIE' => [$philo, 3, 1], 'ANGLAIS' => [4, 4, 1], 'LVII' => [3, 5, 1],
    'GÉOGRAPHIE' => [2, 6, 2], 'HISTOIRE' => [2, 7, 2], 'ÉDUCATION À LA CITOYENNETÉ ET À LA MORALE' => [2, 8, 2],
    'INFORMATIQUE' => [2, 9, 2], 'ÉDUCATION PHYSIQUE ET SPORTIVE (EPS)' => [2, 10, 2], 'MATHÉMATIQUES' => [2, 11, 2],
    'SCIENCES' => [1, 12, 2], 'LANGUES NATIONALES' => [1, 13, 2], 'CULTURES NATIONALES' => [1, 14, 2],
    'ÉDUCATION ARTISTIQUE ET CULTURELLE' => [1, 15, 2], 'TRAVAIL MANUEL (TM)' => [1, 16, 2],
];

return [
    '2nde' => [
        'A4' => $a4(2),
        'C'  => ['MATHÉMATIQUES' => [5, 1, 1], 'PHYSIQUE' => [3, 2, 1], 'CHIMIE' => [3, 3, 1], 'INFORMATIQUE' => [3, 4, 1], 'SVTEEHB' => [2, 5, 1],
                 'LITTÉRATURE' => [2, 6, 2], 'FRANÇAIS' => [1, 7, 2], 'ANGLAIS' => [3, 8, 2], 'HISTOIRE' => [2, 9, 2], 'GÉOGRAPHIE' => [2, 10, 2],
                 'ÉDUCATION À LA CITOYENNETÉ ET À LA MORALE' => [1, 11, 2], 'ÉDUCATION PHYSIQUE ET SPORTIVE (EPS)' => [2, 12, 2], 'TRAVAIL MANUEL (TM)' => [1, 13, 2]],
    ],
    '1ere' => [
        'A4' => $a4(2),
        'C'  => ['MATHÉMATIQUES' => [6, 1, 1], 'PHYSIQUE' => [4, 2, 1], 'CHIMIE' => [2, 3, 1], 'INFORMATIQUE' => [2, 4, 1],
                 'LITTÉRATURE' => [2, 5, 2], 'FRANÇAIS' => [1, 6, 2], 'ANGLAIS' => [3, 7, 2], 'SVTEEHB' => [2, 8, 2], 'PHILOSOPHIE' => [1, 9, 2],
                 'HISTOIRE' => [2, 10, 2], 'ÉDUCATION À LA CITOYENNETÉ ET À LA MORALE' => [1, 11, 2],
                 'ÉDUCATION PHYSIQUE ET SPORTIVE (EPS)' => [2, 12, 2], 'TRAVAIL MANUEL (TM)' => [1, 13, 2]],
        'D'  => ['SVTEEHB' => [6, 1, 1], 'MATHÉMATIQUES' => [4, 2, 1], 'CHIMIE' => [2, 3, 1], 'INFORMATIQUE' => [2, 4, 1],
                 'LITTÉRATURE' => [2, 5, 2], 'FRANÇAIS' => [1, 6, 2], 'ANGLAIS' => [3, 7, 2], 'PHYSIQUE' => [2, 8, 2], 'PHILOSOPHIE' => [2, 9, 2],
                 'HISTOIRE' => [2, 10, 2], 'ÉDUCATION À LA CITOYENNETÉ ET À LA MORALE' => [1, 11, 2],
                 'ÉDUCATION PHYSIQUE ET SPORTIVE (EPS)' => [2, 12, 2], 'TRAVAIL MANUEL (TM)' => [1, 13, 2]],
    ],
    'Tle' => [
        'A4' => $a4(4),
        'C'  => ['MATHÉMATIQUES' => [7, 1, 1], 'PHYSIQUE' => [4, 2, 1], 'CHIMIE' => [2, 3, 1], 'INFORMATIQUE' => [4, 4, 1],
                 'LITTÉRATURE' => [2, 5, 2], 'FRANÇAIS' => [1, 6, 2], 'ANGLAIS' => [3, 7, 2], 'SVTEEHB' => [2, 8, 2], 'PHILOSOPHIE' => [2, 9, 2],
                 'GÉOGRAPHIE' => [2, 10, 2], 'ÉDUCATION À LA CITOYENNETÉ ET À LA MORALE' => [1, 11, 2],
                 'ÉDUCATION PHYSIQUE ET SPORTIVE (EPS)' => [2, 12, 2], 'TRAVAIL MANUEL (TM)' => [1, 13, 2]],
        'D'  => ['SVTEEHB' => [6, 1, 1], 'MATHÉMATIQUES' => [4, 2, 1], 'CHIMIE' => [2, 3, 1], 'INFORMATIQUE' => [2, 4, 1],
                 'LITTÉRATURE' => [2, 5, 2], 'FRANÇAIS' => [1, 6, 2], 'ANGLAIS' => [3, 7, 2], 'PHYSIQUE' => [3, 8, 2], 'PHILOSOPHIE' => [2, 9, 2],
                 'GÉOGRAPHIE' => [2, 10, 2], 'ÉDUCATION À LA CITOYENNETÉ ET À LA MORALE' => [1, 11, 2],
                 'ÉDUCATION PHYSIQUE ET SPORTIVE (EPS)' => [2, 12, 2], 'TRAVAIL MANUEL (TM)' => [1, 13, 2]],
    ],
];
