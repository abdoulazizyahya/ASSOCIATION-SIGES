<?php
// secondaire/commun/pages/emploi_temps/index.php — ancien tableau de bord du module, fusionné
// le 16/10/2026 dans Construction → Contrôles (conflits.php). Conservé pour les anciens liens.
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
require_once __DIR__ . '/_edt_lib.php';
exiger_role(array_merge(EDT_ROLES_GESTION, ['ENSEIGNANT']));
rediriger(role_connecte() === 'ENSEIGNANT'
    ? 'secondaire/commun/pages/emploi_temps/consulter.php?moi=1'
    : 'secondaire/commun/pages/emploi_temps/conflits.php');
