<?php
// installation/install.php — point d'entrée de l'assistant de première
// installation. Vérifie d'abord la version de PHP (message clair au lieu d'une
// « Parse error » sous PHP < 7.4), puis charge l'assistant (_assistant.php).
require __DIR__ . '/../bd/lib/garde_version_php.php';
require __DIR__ . '/_assistant.php';
