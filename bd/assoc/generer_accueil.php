<?php
// ─────────────────────────────────────────────────────────────────────
//  bd/assoc/generer_accueil.php
//  Fabrique une COPIE STATIQUE autonome du portail d'accueil
//  (accueil.php) → promeducamsiges.html à la racine de l'application.
//
//  La page accueil.php est déjà DYNAMIQUE (elle relit l'annuaire à chaque
//  affichage) : ce fichier statique ne sert qu'à être PARTAGÉ hors serveur
//  (e-mail, clé USB…) — logos intégrés en data-URI, liens en absolu.
//
//  - En ligne / CLI :  php bd/assoc/generer_accueil.php
//  - Appelé aussi automatiquement à la création / modification /
//    suppression d'une école (regenerer_portail_accueil(), best-effort).
// ─────────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion_assoc.php';

if (!function_exists('regenerer_portail_accueil')) {
    /**
     * Régénère la copie statique du portail. Ne lève jamais d'exception
     * (les appelants — création d'école… — ne doivent pas échouer si la
     * génération de la vitrine échoue). Retourne le chemin écrit ou null.
     */
    function regenerer_portail_accueil(): ?string
    {
        $racine   = dirname(__DIR__, 2);                 // .../SIGES
        $accueil  = $racine . '/accueil.php';
        $cible    = $racine . '/promeducamsiges.html';
        if (!is_file($accueil)) return null;

        // Base publique pour les liens (le fichier statique peut circuler
        // hors du serveur) : BULLETIN_VERIF_BASE_URL > APP_HOTE > relatif.
        $base = '';
        if (defined('BULLETIN_VERIF_BASE_URL') && BULLETIN_VERIF_BASE_URL) {
            $base = rtrim(BULLETIN_VERIF_BASE_URL, '/');
        } elseif (defined('APP_HOTE') && APP_HOTE) {
            $base = 'https://' . APP_HOTE;
        }

        try {
            // Rendu de accueil.php en mémoire.
            $_SERVER['SCRIPT_NAME']   = ($_SERVER['SCRIPT_NAME']   ?? '') ?: '/accueil.php';
            $_SERVER['DOCUMENT_ROOT'] = ($_SERVER['DOCUMENT_ROOT'] ?? '') ?: dirname($racine);
            ob_start();
            include $accueil;
            $html = (string) ob_get_clean();
        } catch (\Throwable $e) {
            if (ob_get_level() > 0) ob_end_clean();
            return null;
        }
        if ($html === '' || stripos($html, '</html>') === false) return null;

        // 1) Logos <img src=".../assets/uploads/xxx"> → data-URI (redim. 180 px).
        $html = preg_replace_callback(
            '~src="[^"]*?/assets/uploads/([^"]+)"~',
            function ($m) use ($racine) {
                $f = $racine . '/assets/uploads/' . urldecode($m[1]);
                $uri = _accueil_data_uri($f, 180);
                return $uri ? 'src="' . $uri . '"' : $m[0];
            },
            $html
        );

        // 2) Liens de connexion → absolus (le fichier statique peut circuler
        //    hors du serveur). accueil.php rend un seul lien, $LOGIN_URL =
        //    APP_URL.'/index.php?login', à la fois dans un href (échappé
        //    HTML) et dans « var SIGES_URL = <json> » (où json_encode
        //    échappe « / » en « \/ »). On préfixe ce chemin par $base.
        if ($base !== '') {
            $chemin = rtrim(APP_URL, '/') . '/index.php?login';   // ex. "/index.php?login"
            $abs    = $base . '/index.php?login';
            $html   = str_replace(
                [$chemin, str_replace('/', '\\/', $chemin)],      // href + JSON (\/)
                [$abs,    str_replace('/', '\\/', $abs)],
                $html
            );
        }

        // 3) Marqueur « copie statique ».
        $html = str_replace('<body>', "<body>\n<!-- Copie statique générée le " . date('c')
              . " par bd/assoc/generer_accueil.php — la version à jour est accueil.php -->", $html);

        return @file_put_contents($cible, $html) !== false ? $cible : null;
    }

    function _accueil_data_uri(string $path, int $max): ?string
    {
        if (!is_file($path) || !function_exists('imagecreatetruecolor')) return null;
        $info = @getimagesize($path);
        if (!$info) return null;
        [$w, $h] = $info;
        $png = $info[2] === IMAGETYPE_PNG;
        $src = $png ? @imagecreatefrompng($path) : @imagecreatefromjpeg($path);
        if (!$src) return null;
        $s  = min(1, $max / max($w, $h));
        $nw = max(1, (int) round($w * $s));
        $nh = max(1, (int) round($h * $s));
        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 255, 255, 255, 127));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start();
        if ($png) { imagepng($dst, null, 8); $mime = 'image/png'; }
        else      { imagejpeg($dst, null, 82); $mime = 'image/jpeg'; }
        $bin = ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);
        return 'data:' . $mime . ';base64,' . base64_encode($bin);
    }
}

// Exécution directe (CLI ou navigateur) → régénère et affiche le résultat.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    if (PHP_SAPI !== 'cli') header('Content-Type: text/plain; charset=utf-8');
    $out = regenerer_portail_accueil();
    echo $out ? "OK — " . $out . " (" . round(filesize($out) / 1024) . " Ko)\n"
              : "Échec de la génération (voir accueil.php / droits d'écriture).\n";
}
