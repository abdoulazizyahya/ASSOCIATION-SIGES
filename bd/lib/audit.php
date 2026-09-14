<?php
// bd/lib/audit.php — Journal d'audit unifié (connexions, déconnexions,
// échecs, actions sensibles) pour TOUS les comptes : membres de
// l'association ET comptes d'école (directeur, fondateur, enseignant,
// comptable). Tout est écrit dans  promeducam_assoc.journal_audit.
//
//   audit_ua()                 → navigateur / OS / type d'appareil (User-Agent)
//   audit_geo($ip)             → pays / région / ville / opérateur (ip-api.com + cache)
//   audit_log($evenement, $ctx)→ écrit une ligne de journal
//
// Ne lève JAMAIS d'exception : une panne du journal ne doit pas casser une
// page ni bloquer une connexion.

if (!function_exists('assoc_exec')) {
    require_once __DIR__ . '/../../connexion_assoc.php';
}

// ─────────────────────────────────────────────────────────────────────
//  User-Agent → navigateur, OS, type d'appareil
// ─────────────────────────────────────────────────────────────────────
function audit_ua(): array
{
    $ua   = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $brut = mb_substr($ua, 0, 400) ?: null;
    if ($ua === '') {
        return ['navigateur' => null, 'os' => null, 'appareil' => null, 'brut' => null];
    }

    // Robots / outils
    if (preg_match('~bot\b|crawler|spider|crawl|facebookexternalhit|bingpreview|slurp|'
        . 'curl/|wget/|python-requests|go-http-client|headless|monitoring|uptime~i', $ua)) {
        return ['navigateur' => 'Robot / outil', 'os' => null, 'appareil' => 'bot', 'brut' => $brut];
    }

    // ── OS ──
    $os = null;
    if (preg_match('~Windows NT 10\.0~', $ua))                    $os = 'Windows 10/11';
    elseif (preg_match('~Windows NT 6\.3~', $ua))                 $os = 'Windows 8.1';
    elseif (preg_match('~Windows NT 6\.1~', $ua))                 $os = 'Windows 7';
    elseif (preg_match('~Windows Phone~', $ua))                   $os = 'Windows Phone';
    elseif (preg_match('~Windows~', $ua))                         $os = 'Windows';
    elseif (preg_match('~iPad;.*OS (\d+)[_.](\d+)~', $ua, $m))    $os = 'iPadOS ' . $m[1] . '.' . $m[2];
    elseif (preg_match('~iPhone OS (\d+)[_.](\d+)~', $ua, $m))    $os = 'iOS ' . $m[1] . '.' . $m[2];
    elseif (preg_match('~Android (\d+(?:\.\d+)?)~', $ua, $m))     $os = 'Android ' . $m[1];
    elseif (preg_match('~Android~', $ua))                         $os = 'Android';
    elseif (preg_match('~Mac OS X (\d+)[_.](\d+)~', $ua, $m))     $os = 'macOS ' . $m[1] . '.' . $m[2];
    elseif (preg_match('~CrOS~', $ua))                            $os = 'ChromeOS';
    elseif (preg_match('~Linux~', $ua))                           $os = 'Linux';

    // ── Navigateur (ordre important) ──
    $nav = null;
    if (preg_match('~Edg(?:iOS|A)?/(\d+)~', $ua, $m))             $nav = 'Edge ' . $m[1];
    elseif (preg_match('~OPR/(\d+)~', $ua, $m))                   $nav = 'Opera ' . $m[1];
    elseif (preg_match('~SamsungBrowser/(\d+)~', $ua, $m))        $nav = 'Samsung Internet ' . $m[1];
    elseif (preg_match('~(?:Firefox|FxiOS)/(\d+)~', $ua, $m))     $nav = 'Firefox ' . $m[1];
    elseif (preg_match('~CriOS/(\d+)~', $ua, $m))                 $nav = 'Chrome ' . $m[1];
    elseif (preg_match('~Chrome/(\d+)~', $ua, $m))                $nav = 'Chrome ' . $m[1];
    elseif (preg_match('~Version/(\d+)[.\d]*\s+.*Safari~', $ua, $m)) $nav = 'Safari ' . $m[1];
    elseif (preg_match('~Safari~', $ua))                          $nav = 'Safari';
    elseif (preg_match('~MSIE (\d+)~', $ua, $m))                  $nav = 'Internet Explorer ' . $m[1];
    elseif (preg_match('~Trident~', $ua))                         $nav = 'Internet Explorer';

    // ── Type d'appareil ──
    if (preg_match('~iPad|Tablet|PlayBook|Silk~i', $ua)
        || (preg_match('~Android~', $ua) && !preg_match('~Mobile~', $ua))) {
        $appareil = 'tablette';
    } elseif (preg_match('~Mobi|iPhone|iPod|Windows Phone|BlackBerry|Opera Mini~i', $ua)) {
        $appareil = 'mobile';
    } else {
        $appareil = 'ordinateur';
    }

    return ['navigateur' => $nav, 'os' => $os, 'appareil' => $appareil, 'brut' => $brut];
}

// ─────────────────────────────────────────────────────────────────────
//  IP → localisation approximative (ip-api.com), avec cache geo_ip_cache
// ─────────────────────────────────────────────────────────────────────
function audit_geo(?string $ip): array
{
    $vide = ['pays' => null, 'region' => null, 'ville' => null, 'operateur' => null];
    $ip   = trim((string) $ip);

    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) return $vide;
    // IP privée / réservée (LAN, localhost…) : aucune géolocalisation possible.
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return $vide;
    }
    if (!function_exists('annuaire_dispo') || !annuaire_dispo()) return $vide;

    // Cache (30 jours)
    $c = null;
    try {
        $c = assoc_one("SELECT pays, region, ville, operateur, maj_le FROM geo_ip_cache WHERE ip = ?", [$ip]);
    } catch (\Throwable $e) {
        return $vide;   // table pas encore créée
    }
    if ($c && strtotime((string) $c['maj_le']) > time() - 30 * 86400) {
        return ['pays' => $c['pays'], 'region' => $c['region'], 'ville' => $c['ville'], 'operateur' => $c['operateur']];
    }

    if (!defined('AUDIT_GEOIP') || !AUDIT_GEOIP) {
        return $c ? ['pays' => $c['pays'], 'region' => $c['region'], 'ville' => $c['ville'], 'operateur' => $c['operateur']] : $vide;
    }

    // Appel ip-api.com (HTTP, gratuit, sans clé, ~45 req/min)
    $url = 'http://ip-api.com/json/' . rawurlencode($ip) . '?fields=status,country,regionName,city,isp&lang=fr';
    $raw = _audit_http_get($url);
    $res = $vide;
    $ok  = 0;
    if ($raw !== null) {
        $j = json_decode($raw, true);
        if (is_array($j) && ($j['status'] ?? '') === 'success') {
            $res = [
                'pays'      => $j['country']    ?: null,
                'region'    => $j['regionName'] ?: null,
                'ville'     => $j['city']       ?: null,
                'operateur' => $j['isp']        ?: null,
            ];
            $ok = 1;
        }
    }

    // Écrit le cache dans tous les cas (échec compris : maj_le repoussé →
    // on ne re-frappe pas l'API à chaque connexion pour une IP muette).
    try {
        assoc_exec(
            "INSERT INTO geo_ip_cache (ip, pays, region, ville, operateur, ok, maj_le)
             VALUES (?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE pays = VALUES(pays), region = VALUES(region),
                 ville = VALUES(ville), operateur = VALUES(operateur), ok = VALUES(ok), maj_le = NOW()",
            [$ip, $res['pays'], $res['region'], $res['ville'], $res['operateur'], $ok]
        );
    } catch (\Throwable $e) {
    }

    return $res;
}

/** GET HTTP court (cURL si dispo, sinon file_get_contents). Retourne null en cas d'échec. */
function _audit_http_get(string $url): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_USERAGENT      => 'SIGES-audit',
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $out  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($out !== false && $code >= 200 && $code < 300) ? (string) $out : null;
    }
    if (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => [
            'timeout'       => 3,
            'ignore_errors' => true,
            'header'        => "User-Agent: SIGES-audit\r\n",
        ]]);
        $out = @file_get_contents($url, false, $ctx);
        return $out !== false ? (string) $out : null;
    }
    return null;
}

// ─────────────────────────────────────────────────────────────────────
//  Identification d'appareil (cookie durable, 2 ans) — PAS le nom système
//  de la machine (jamais transmis par un navigateur à un site, quelle que
//  soit la techno : vie privée). Sert uniquement à RECONNAÎTRE le même
//  navigateur d'une connexion à l'autre, pour que le compte puisse lui
//  donner un nom depuis « Mon compte » (profil.php > Mes appareils) — voir
//  appareil_connu (connexion_assoc.php).
// ─────────────────────────────────────────────────────────────────────
function appareil_device_id(): string
{
    $id = $_COOKIE['siges_appareil'] ?? '';
    if (preg_match('/^[a-f0-9]{32}$/', $id)) return $id;

    $id = bin2hex(random_bytes(16));
    if (!headers_sent()) {
        @setcookie('siges_appareil', $id, [
            'expires'  => time() + 2 * 365 * 86400,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE['siges_appareil'] = $id; // dispo immédiatement pour cette requête
    }
    return $id;
}

// ─────────────────────────────────────────────────────────────────────
//  Écriture d'une ligne de journal
//    $evenement : 'connexion' | 'connexion_echec' | 'deconnexion' | 'action'
//    $ctx : ['action'=>slug, 'cible'=>texte, 'id_etab'=>int, 'role'=>str, 'login'=>str]
// ─────────────────────────────────────────────────────────────────────
function audit_log(string $evenement, array $ctx = []): void
{
    if (!function_exists('annuaire_dispo') || !annuaire_dispo()) return;
    if (function_exists('ecole_session_demarrer')) ecole_session_demarrer();

    // ── Acteur ──
    $type = 'inconnu';
    $aid = $login = $nom = $role = null;
    if (!empty($_SESSION['membre']['id'])) {
        $m     = $_SESSION['membre'];
        $type  = 'membre';
        $aid   = (int) $m['id'];
        $login = $m['login'] ?? null;
        $nom   = trim(($m['prenom'] ?? '') . ' ' . ($m['nom'] ?? '')) ?: null;
        $role  = (function_exists('est_superadmin_association') && est_superadmin_association())
            ? 'SUPERADMIN' : 'MEMBRE';
    } elseif (!empty($_SESSION['user']['id'])) {
        $u     = $_SESSION['user'];
        $type  = 'user';
        $aid   = (int) $u['id'];
        $login = $u['login'] ?? null;
        $nom   = trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? '')) ?: null;
        $role  = $u['role'] ?? null;
    } elseif (!empty($ctx['login'])) {
        $login = (string) $ctx['login'];
    }
    if (!empty($ctx['role'])) $role = $ctx['role'];

    // ── École ──
    $id_etab = $ctx['id_etab'] ?? null;
    if ($id_etab === null && function_exists('ecole_courante')) {
        $id_etab = ecole_courante()['id'] ?? null;
    }
    if ($id_etab === null) $id_etab = $_SESSION['ecole']['id'] ?? null;
    $id_etab = $id_etab !== null ? (int) $id_etab : null;

    // ── Contexte technique ──
    $ip     = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua     = audit_ua();
    $geo    = audit_geo($ip);
    $device = appareil_device_id();

    try {
        assoc_exec(
            "INSERT INTO journal_audit
               (evenement, action, cible, acteur_type, acteur_id, acteur_login, acteur_nom, role,
                id_etablissement, ip, ua_navigateur, ua_os, ua_appareil, ua_brut,
                geo_pays, geo_region, geo_ville, geo_operateur, device_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $evenement,
                $ctx['action'] ?? null,
                $ctx['cible'] ?? null,
                $type, $aid, $login, $nom, $role,
                $id_etab, $ip,
                $ua['navigateur'], $ua['os'], $ua['appareil'], $ua['brut'],
                $geo['pays'], $geo['region'], $geo['ville'], $geo['operateur'],
                $device,
            ]
        );
    } catch (\Throwable $e) {
        // Le journal ne doit jamais interrompre la navigation.
    }

    // Reconnaissance d'appareil (appareil_connu) : seulement pour un acteur
    // identifié (membre ou user) — jamais pour une tentative échouée sans
    // compte reconnu. N'écrase JAMAIS `nom` (le compte le donne lui-même
    // depuis « Mon compte ») : seule la dernière vue + le dernier UA bougent.
    if ($type === 'membre' || $type === 'user') {
        try {
            assoc_exec(
                "INSERT INTO appareil_connu
                   (device_id, acteur_type, acteur_id, ua_appareil, ua_navigateur, ua_os, premiere_connexion, derniere_connexion)
                 VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                   ua_appareil = VALUES(ua_appareil), ua_navigateur = VALUES(ua_navigateur),
                   ua_os = VALUES(ua_os), derniere_connexion = NOW()",
                [$device, $type, $aid, $ua['appareil'], $ua['navigateur'], $ua['os']]
            );
        } catch (\Throwable $e) {
        }
    }
}
