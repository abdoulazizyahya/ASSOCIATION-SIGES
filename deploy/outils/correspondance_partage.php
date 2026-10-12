<?php
// Table de deploy/outils/reorganiser_arborescence.php --table=… :
// fichiers PARTAGÉS par le primaire et le secondaire, sortis de primaire/
// vers noyau/partage/ (12/10/2026). Les pages d'entrée (photos_seance,
// photo_enregistrer, fiches_incompletes_export) gardent leur URL : une page
// relais est recréée à l'ancien emplacement, comme côté secondaire.
return [
    'correspondance' => [
        'primaire/pdf/verif_commun.php'                       => 'noyau/partage/verif/verif_commun.php',
        'primaire/pdf/verif_scolarite_lib.php'                => 'noyau/partage/verif/verif_scolarite_lib.php',
        'primaire/pages/eleves/_photos_lib.php'               => 'noyau/partage/eleves/_photos_lib.php',
        'primaire/pages/eleves/_photos_outils.php'            => 'noyau/partage/eleves/_photos_outils.php',
        'primaire/pages/eleves/_eleves_photos.php'            => 'noyau/partage/eleves/_eleves_photos.php',
        'primaire/pages/eleves/_eleves_incompletes.php'       => 'noyau/partage/eleves/_eleves_incompletes.php',
        'primaire/pages/eleves/photos_seance.php'             => 'noyau/partage/eleves/photos_seance.php',
        'primaire/pages/eleves/photo_enregistrer.php'         => 'noyau/partage/eleves/photo_enregistrer.php',
        'primaire/pages/eleves/fiches_incompletes_export.php' => 'noyau/partage/eleves/fiches_incompletes_export.php',
        'primaire/pages/paie/_mes_bulletins_contenu.php'      => 'noyau/partage/paie/_mes_bulletins_contenu.php',
        'primaire/pages/eleves/_fiches_lib.php'               => 'noyau/partage/eleves/_fiches_lib.php',
    ],
    // URL / chemins à réécrire hors __DIR__ : bibliothèques seulement (les
    // pages d'entrée gardent leur URL grâce aux pages relais).
    'prefixes_url' => [
        'primaire/pdf/verif_commun.php', 'primaire/pdf/verif_scolarite_lib.php',
        'primaire/pages/eleves/_photos_lib.php', 'primaire/pages/eleves/_photos_outils.php',
        'primaire/pages/eleves/_eleves_photos.php', 'primaire/pages/eleves/_eleves_incompletes.php',
        'primaire/pages/paie/_mes_bulletins_contenu.php', 'primaire/pages/eleves/_fiches_lib.php',
    ],
    'fichiers_racine' => [],
];
