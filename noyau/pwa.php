<?php
// noyau/pwa.php — application installable / APK Android (02/11/2026).
//
// Même principe que l'application de l'hôtel Beero : le site devient une
// « PWA » (manifest + service worker), que PWABuilder (pwabuilder.com)
// transforme en APK. UNE application pour toutes les écoles : elle s'ouvre
// sur la connexion (?source=application) ; après la première connexion
// réussie, elle reste sur cet établissement (compte/login.php, cookies
// siges_app / siges_app_ecole).
//
// Tout passe par index.php?pwa=… (racine du site) : un service worker ne
// peut contrôler que les adresses situées sous son propre dossier.
//   index.php?pwa=manifest   → manifest JSON de l'application
//   index.php?pwa=sw         → service worker (aucun cache)
//   index.php?pwa=icone&t=512 → icône carrée PNG (logo de l'école, sinon sigle)
//
// Après la première connexion réussie, nom et icône sont ceux de l'école de
// l'appareil (cookie siges_app_ecole) : l'application installée depuis Chrome
// prend le logo de l'école. Une APK PWABuilder garde l'icône fixée à sa
// fabrication (limite Android), mais l'intérieur suit l'école.

// École de l'appareil (annuaire : code, nom, sigle, logo) ou null.
function pwa_ecole(string $code = ''): ?array {
    $code = $code !== '' ? $code : (string) ($_COOKIE['siges_app_ecole'] ?? '');
    if ($code === '' || !preg_match('/^[A-Za-z0-9]{2,10}$/', $code)) return null;
    if (!function_exists('annuaire_dispo') || !annuaire_dispo()) return null;
    return assoc_one("SELECT code, nom, sigle, logo FROM etablissement WHERE code=? AND actif=1", [$code]) ?: null;
}

// Nom et sigle de l'application : ceux de l'école de l'appareil, sinon de
// l'association (config.local.php).
function pwa_identite(?array $ecole = null): array {
    if ($ecole) return [$ecole['nom'], ($ecole['sigle'] ?? '') ?: $ecole['code']];
    $nom = (defined('ASSOC_NOM') && ASSOC_NOM !== '') ? ASSOC_NOM : 'SIGES';
    $nom = mb_strtoupper(mb_substr($nom, 0, 1)) . mb_substr($nom, 1);
    if (mb_strlen($nom) <= 12) return [$nom, $nom];   // nom court : affiché tel quel sous l'icône
    preg_match_all('/\b[\p{L}]/u', $nom, $m);
    $sigle = count($m[0]) > 1 ? mb_strtoupper(implode('', array_slice($m[0], 0, 3))) : mb_strtoupper(mb_substr($nom, 0, 5));
    return [$nom, $sigle];
}

// Balises à placer dans <head> (connexion et pages de l'application).
function pwa_balises(): string {
    $ecole = pwa_ecole();
    $q = $ecole ? '&ec=' . rawurlencode($ecole['code']) : '';
    $racine = APP_URL . '/index.php?pwa=';
    return '<link rel="manifest" href="' . h($racine . 'manifest' . $q) . '">' . "\n"
         . '  <meta name="theme-color" content="#1e4fd8">' . "\n"
         . '  <link rel="apple-touch-icon" href="' . h($racine . 'icone&t=192' . $q) . '">' . "\n"
         . '  <script>if (\'serviceWorker\' in navigator) { navigator.serviceWorker.register('
         . json_encode($racine . 'sw') . ', { scope: ' . json_encode(APP_URL . '/') . ' }).catch(function () {}); }</script>';
}

// Réponse à index.php?pwa=… (s'arrête ici).
function pwa_servir(string $quoi): void {
    $ecole = pwa_ecole((string) ($_GET['ec'] ?? ''));
    [$nom, $court] = pwa_identite($ecole);

    if ($quoi === 'sw') {
        // Logiciel de gestion : données toujours à jour, AUCUNE page en cache.
        // Sans Internet, un message clair au lieu de la page d'erreur Android.
        header('Content-Type: application/javascript; charset=UTF-8');
        header('Service-Worker-Allowed: ' . APP_URL . '/');
        header('Cache-Control: no-cache');
        echo <<<'JS'
self.addEventListener('install', function (e) { self.skipWaiting(); });
self.addEventListener('activate', function (e) { e.waitUntil(self.clients.claim()); });
self.addEventListener('fetch', function (e) {
    if (e.request.mode !== 'navigate') return;
    e.respondWith(fetch(e.request).catch(function () {
        return new Response(
            '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8">' +
            '<meta name="viewport" content="width=device-width, initial-scale=1"><title>Hors connexion</title></head>' +
            '<body style="font-family:sans-serif;text-align:center;padding:60px 20px;color:#333">' +
            '<h2 style="color:#1e4fd8">Pas de connexion Internet</h2>' +
            '<p>Vérifiez le Wi-Fi ou les données mobiles, puis réessayez.</p>' +
            '<p><a href="" onclick="location.reload();return false;" style="color:#1e4fd8">Réessayer</a></p>' +
            '</body></html>',
            { headers: { 'Content-Type': 'text/html; charset=UTF-8' } });
    }));
});
JS;
        exit;
    }

    if ($quoi === 'icone') {
        pwa_icone((int) ($_GET['t'] ?? 512), $ecole, $court);
        exit;
    }

    // Manifest : une seule application, ouverte sur la connexion ; nom et
    // icônes de l'école de l'appareil (adresse des icônes propre à l'école).
    $q = $ecole ? '&ec=' . rawurlencode($ecole['code']) : '';
    // Numéro de l'APK dans l'adresse de démarrage : figé dans l'APK à sa fabrication.
    $start = APP_URL . '/compte/login.php?source=application&apk=' . pwa_apk_version();
    header('Content-Type: application/manifest+json; charset=UTF-8');
    header('Cache-Control: no-cache');
    echo json_encode([
        'id'               => APP_URL . '/?app=siges',
        'name'             => $nom,
        'short_name'       => mb_substr($court, 0, 12),
        'description'      => 'Gestion scolaire — ' . $nom . '. Développé par ' . PWA_DEVELOPPEUR . ' (' . implode(' / ', PWA_TELEPHONES) . ', ' . PWA_EMAIL . ').',
        'start_url'        => $start,
        'scope'            => APP_URL . '/',
        'display'          => 'standalone',
        'orientation'      => 'any',
        'background_color' => '#ffffff',
        'theme_color'      => '#1e4fd8',
        'lang'             => 'fr',
        'icons'            => [
            ['src' => APP_URL . '/index.php?pwa=icone&t=192' . $q, 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => APP_URL . '/index.php?pwa=icone&t=512' . $q, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => APP_URL . '/index.php?pwa=icone&t=512&m=1' . $q, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

// Icône carrée PNG : logo centré sur fond blanc si fourni (marge plus large
// pour la version « maskable », que Android découpe en cercle), sinon le
// sigle en blanc sur fond bleu.
function pwa_icone(int $t, ?array $ecole, string $court): void {
    $t = in_array($t, [192, 512], true) ? $t : 512;
    $img = imagecreatetruecolor($t, $t);
    $logo = ($ecole && !empty($ecole['logo']) && strpos($ecole['logo'], '..') === false)
        ? __DIR__ . '/../assets/uploads/' . $ecole['logo'] : '';
    $src = ($logo && is_file($logo)) ? @imagecreatefromstring((string) file_get_contents($logo)) : false;
    if ($src) {
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        $marge = (int) round($t * (isset($_GET['m']) ? 0.18 : 0.06));
        $cote = $t - 2 * $marge;
        $w = imagesx($src); $h = imagesy($src);
        $r = min($cote / $w, $cote / $h);
        $nw = (int) round($w * $r); $nh = (int) round($h * $r);
        imagecopyresampled($img, $src, (int) (($t - $nw) / 2), (int) (($t - $nh) / 2), 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);
    } else {
        imagefill($img, 0, 0, imagecolorallocate($img, 30, 79, 216));
        $texte = mb_strtoupper(mb_substr($court, 0, 4));
        $police = 5;
        $tw = imagefontwidth($police) * strlen($texte); $th = imagefontheight($police);
        // Petite police GD agrandie : dessinée sur une vignette puis étirée.
        $v = imagecreatetruecolor($tw, $th);
        imagefill($v, 0, 0, imagecolorallocate($v, 30, 79, 216));
        imagestring($v, $police, 0, 0, $texte, imagecolorallocate($v, 255, 255, 255));
        $lw = (int) ($t * 0.6); $lh = (int) ($lw * $th / max(1, $tw));
        imagecopyresampled($img, $v, (int) (($t - $lw) / 2), (int) (($t - $lh) / 2), 0, 0, $lw, $lh, $tw, $th);
        imagedestroy($v);
    }
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=86400');
    imagepng($img);
    imagedestroy($img);
}

// ── Développeur (page « À propos » et informations de l'application) ──
const PWA_DEVELOPPEUR = 'Ing ABDOUL-AZIZ Yahya';
const PWA_TELEPHONES  = ['621400800', '699758612', '675744062'];
const PWA_EMAIL       = 'abdoulazizyahya@gmail.com';

// Version de l'APK actuellement distribuée (portail association → Réglages
// → Application Android). L'APK porte SON numéro dans l'adresse de démarrage
// (…&apk=N, figée à sa fabrication) : une APK plus ancienne affiche un
// bandeau « Nouvelle version disponible ».
function pwa_apk_version(): int {
    return max(1, (int) (function_exists('assoc_param') ? assoc_param('apk_version', '1') : 1));
}

// Fichier APK déposé sur le site (assets/app/), ou '' s'il n'y en a pas.
function pwa_apk_fichier(): string {
    $f = function_exists('assoc_param') ? (string) assoc_param('apk_fichier', '') : '';
    return ($f !== '' && strpos($f, '..') === false && is_file(__DIR__ . '/../assets/app/' . $f)) ? $f : '';
}

// Version de l'APK installée sur cet appareil (cookie posé par la connexion), 0 = navigateur.
function pwa_apk_installee(): int {
    return (int) ($_COOKIE['siges_apk'] ?? 0);
}

// Bandeau « Nouvelle version de l'application » (uniquement dans une APK
// plus ancienne que la version distribuée, et si le fichier est en ligne).
function pwa_bandeau_apk(): string {
    $inst = pwa_apk_installee();
    $der  = pwa_apk_version();
    $f    = pwa_apk_fichier();
    if ($inst <= 0 || $inst >= $der || $f === '') return '';
    return '<div style="position:fixed;left:0;right:0;bottom:0;z-index:2000;background:#1e4fd8;color:#fff;'
         . 'padding:.6rem 1rem;font-size:.85rem;display:flex;gap:.8rem;align-items:center;justify-content:center;flex-wrap:wrap">'
         . '<span><i class="bi bi-phone me-1"></i>Nouvelle version de l\'application disponible (v' . $der . ', vous avez la v' . $inst . ').</span>'
         . '<a href="' . h(APP_URL . '/assets/app/' . rawurlencode($f)) . '" download style="background:#fff;color:#1e4fd8;'
         . 'border-radius:6px;padding:.25rem .8rem;font-weight:700;text-decoration:none">Télécharger</a></div>';
}

// Fenêtre « Nouveautés » : message écrit dans le portail association,
// montré UNE fois par appareil après chaque nouveau message.
function pwa_nouveautes(): string {
    if (!function_exists('assoc_param')) return '';
    $texte = trim((string) assoc_param('nouveautes_texte', ''));
    $ver   = (string) assoc_param('nouveautes_version', '');
    if ($texte === '' || $ver === '') return '';
    $date = (string) assoc_param('nouveautes_date', '');
    return '<div id="siges-nouveautes" style="display:none;position:fixed;inset:0;z-index:2100;background:rgba(15,26,58,.55);align-items:center;justify-content:center;padding:1rem">'
         . '<div style="background:#fff;border-radius:14px;max-width:480px;width:100%;padding:1.3rem 1.4rem;box-shadow:0 20px 60px rgba(0,0,0,.3)">'
         . '<h5 style="margin:0 0 .3rem;color:#1e4fd8"><i class="bi bi-stars me-1"></i>Nouveautés</h5>'
         . ($date !== '' ? '<div style="font-size:.75rem;color:#6b7280;margin-bottom:.6rem">Mise à jour du ' . h($date) . '</div>' : '')
         . '<div style="font-size:.88rem;white-space:pre-line;max-height:55vh;overflow:auto">' . h($texte) . '</div>'
         . '<div style="text-align:right;margin-top:1rem"><button type="button" class="btn btn-primary btn-sm" id="siges-nouveautes-ok">J\'ai compris</button></div>'
         . '</div></div>'
         . '<script>(function () { var v = ' . json_encode($ver) . ', k = "siges_nouveautes_vu", d = document.getElementById("siges-nouveautes");'
         . ' try { if (localStorage.getItem(k) === v) return; } catch (e) {}'
         . ' d.style.display = "flex";'
         . ' document.getElementById("siges-nouveautes-ok").onclick = function () { d.style.display = "none"; try { localStorage.setItem(k, v); } catch (e) {} };'
         . ' })();</script>';
}

// Application Android installée (cookie posé à l'ouverture par l'APK,
// compte/login.php?source=application) : seule elle montre « À propos ».
function pwa_mode_app(): bool {
    return ($_COOKIE['siges_app'] ?? '') === '1';
}
