<?php
// noyau/langue.php — langue de l'INTERFACE choisie par l'utilisateur (02/11/2026).
//
//  1re étape : seul le MENU (barre latérale, en-tête) est traduit ; les pages
//  restent en français. Le choix est gardé dans un cookie « siges_langue »
//  (par navigateur, toutes écoles), modifié par compte/langue.php.
//
//  Les libellés du menu restent en français dans menu.php / menu_secondaire.php :
//  ils servent aussi de CLÉS de privilèges (« grp:Scolarité », acces_utilisateur)
//  — on ne traduit qu'à l'AFFICHAGE via tm().
//
//  Ne pas confondre avec la langue des DOCUMENTS d'élèves (bulletins…), fixée
//  par l'école / la classe : bulletin_fixer_langue(), bt(), bl().

const LANGUES_INTERFACE = ['fr' => 'Français', 'en' => 'English'];

/** Langue d'interface courante : 'fr' (défaut) ou 'en'. */
function langue_interface(): string {
    $l = $_COOKIE['siges_langue'] ?? 'fr';
    return isset(LANGUES_INTERFACE[$l]) ? $l : 'fr';
}

/**
 * Sélecteur visible « FR | EN » (barre du haut, page de connexion) : liens vers
 * compte/langue.php qui reviennent sur la page courante.
 */
function selecteur_langue_html(string $style = ''): string {
    $retour = urlencode((string) ($_SERVER['REQUEST_URI'] ?? ''));
    $html = '<div class="btn-group btn-group-sm selecteur-langue" role="group" aria-label="Langue / Language"'
          . ($style !== '' ? ' style="' . htmlspecialchars($style, ENT_QUOTES) . '"' : '') . '>';
    foreach (LANGUES_INTERFACE as $code => $nom) {
        $actif = langue_interface() === $code;
        $html .= '<a href="' . APP_URL . '/compte/langue.php?l=' . $code . '&amp;retour=' . $retour . '"'
               . ' class="btn py-0 px-2 ' . ($actif ? 'btn-primary' : 'btn-outline-secondary') . '"'
               . ' style="font-size:.72rem;font-weight:600" title="' . htmlspecialchars($nom, ENT_QUOTES) . '"'
               . ($actif ? ' aria-current="true"' : '') . '>' . strtoupper($code) . '</a>';
    }
    return $html . '</div>';
}

/** Traduction d'un libellé de menu ; le texte français est renvoyé s'il n'a pas de traduction. */
function tm(string $fr): string {
    static $dico = null;
    if (langue_interface() === 'fr') return $fr;
    if ($dico === null) $dico = require __DIR__ . '/lang/menu_en.php';
    return $dico[$fr] ?? $dico[mb_strtolower($fr, 'UTF-8')] ?? $fr;
}
