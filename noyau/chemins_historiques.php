<?php
// noyau/chemins_historiques.php — anciens emplacements des pages (avant la
// réorganisation de l'arborescence du 12/10/2026) → emplacement actuel.
//
// Des chemins de pages sont ENREGISTRÉS EN BASE et survivent au
// déplacement des fichiers : clés de privilèges (acces_regle du portail,
// acces_utilisateur des écoles : « pages/parametres/index.php »…) et liens
// de notification du secondaire. chemin_actuel() les convertit à la
// lecture, sans dépendre d'une migration de données ;
// bd/assoc/migrer_chemins_arborescence.php réécrit ensuite les valeurs
// stockées une fois pour toutes.
// Même table que deploy/outils/reorganiser_arborescence.php (pages seulement).

if (!function_exists('chemin_actuel')) {
    function chemin_actuel(string $p): string {
        static $table = [
            'secondaire/pages/notes/'     => 'secondaire/general/pages/notes/',
            'secondaire/pages/bulletins/' => 'secondaire/general/pages/bulletins/',
            'secondaire/pages/'           => 'secondaire/commun/pages/',
            'pages/'                      => 'primaire/pages/',
            'notes_apc.php'               => 'primaire/notes_apc.php',
            'notes_apc_arabe.php'         => 'primaire/notes_apc_arabe.php',
        ];
        foreach ($table as $ancien => $neuf) {      // ordre : du plus précis au plus général
            if (strncmp($p, $ancien, strlen($ancien)) === 0) return $neuf . substr($p, strlen($ancien));
        }
        return $p;
    }
}
