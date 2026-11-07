<?php
// bd/lib/garde_version_php.php — version de PHP trop ancienne (06/11/2026).
//
// Chargé en TOUT PREMIER par les points d'entrée (index.php,
// installation/install.php) : sous PHP < 7.4, le reste du code provoque une
// « Parse error » incompréhensible ; on affiche à la place la marche à suivre.
// Ce fichier doit rester lisible par un vieux PHP (5.x) : aucune syntaxe récente.
if (PHP_VERSION_ID < 70400) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>SIGES - PHP trop ancien</title></head>'
       . '<body style="font-family:Segoe UI,Arial,sans-serif;max-width:640px;margin:3rem auto;padding:0 1rem;color:#1a2b45">'
       . '<h2>Version de PHP trop ancienne</h2>'
       . '<p>SIGES demande <strong>PHP 7.4 ou plus récent</strong> (8.x conseillé). Ce serveur utilise <strong>PHP '
       . htmlspecialchars(PHP_VERSION) . '</strong>.</p>'
       . '<p><strong>WampServer</strong> : clic gauche sur l\'icône Wamp &gt; <em>PHP</em> &gt; <em>Version</em> &gt; '
       . 'choisir <strong>7.4.x</strong> ou <strong>8.x</strong>. Wamp redémarre tout seul ; rechargez ensuite cette page.</p>'
       . '<p>Pensez à réactiver dans cette version les extensions mysqli, gd, zip, mbstring, openssl et fileinfo '
       . '(<em>PHP</em> &gt; <em>Extensions PHP</em>) et à régler <code>memory_limit = 512M</code> dans son php.ini.</p>'
       . '</body></html>';
    exit;
}
