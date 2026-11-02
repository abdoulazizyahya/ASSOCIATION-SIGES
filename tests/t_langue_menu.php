<?php
// Langue de l'interface (02/11/2026) : menu traduit FR/EN via tm(), cookie siges_langue.
require __DIR__ . '/_amorce.php';
require_once 'noyau/langue.php';

unset($_COOKIE['siges_langue']);
egal('fr', langue_interface(), 'sans cookie : français');
egal('Élèves', tm('Élèves'), 'français : libellé inchangé');

$_COOKIE['siges_langue'] = 'xx';
egal('fr', langue_interface(), 'langue inconnue : français');

$_COOKIE['siges_langue'] = 'en';
egal('Dashboard', tm('Tableau de bord'), 'anglais : traduit');
egal('Texte inconnu', tm('Texte inconnu'), 'sans traduction : reste en français');

// Chaque libellé des deux menus (groupes, séparateurs, entrées) a sa traduction.
$dico = require 'noyau/lang/menu_en.php';
$manquants = [];
foreach (['menu.php', 'menu_secondaire.php'] as $f) {
    $src = preg_replace('~^\s*//.*$~m', '', file_get_contents("noyau/layout/$f"));   // sans commentaires
    // Libellés littéraux : clés de groupe « 'X' => [ » et 1er élément d'une entrée « ['X', ».
    preg_match_all("/^\s*'((?:[^'\\\\]|\\\\.)+)'\s*=>\s*\[/m", $src, $g);
    preg_match_all("/\[\s*'((?:[^'\\\\]|\\\\.)+)'\s*,\s*'((?:[^'\\\\]|\\\\.)+)'/", $src, $e, PREG_SET_ORDER);
    $libs = $g[1];
    foreach ($e as $m) $libs[] = $m[1] === '--' ? $m[2] : $m[1];
    foreach ($libs as $l) {
        $l = stripslashes($l);
        if (preg_match('/^[A-Z_]+$/', $l)) continue;   // codes de rôles ['DIRECTEUR', …]
        if (!isset($dico[$l])) $manquants[$l] = $f;
    }
}
ok(!$manquants, 'toutes les entrées du menu sont traduites' . ($manquants ? ' — manquent : ' . implode(' | ', array_keys($manquants)) : ''));
unset($_COOKIE['siges_langue']);
