<?php
// secondaire/commun/pages/communication/_passerelle_lib.php — envoi
// AUTOMATIQUE de SMS par le téléphone de l'école (10/11/2026) via
// l'application gratuite et open source « SMS Gateway for Android »
// (capcom6/android-sms-gateway, docs.sms-gate.app), mode « serveur cloud » :
// SIGES transmet chaque message à l'API ; le téléphone le reçoit et l'envoie
// avec le FORFAIT DE SA PUCE. Sans cette application, la page d'envoi reste
// en mode manuel (l'application Messages s'ouvre, on appuie sur Envoyer).
//
// API (docs.sms-gate.app/integration/api) : POST {url}/messages, Basic Auth
// (identifiant / mot de passe affichés dans l'application : Paramètres →
// Serveur cloud), corps {"textMessage":{"text":…},"phoneNumbers":["+237…"],
// "simNumber":1|2}. Réponse 202 avec « id ». Les anciennes versions de
// l'application utilisent /message et {"message":…} : repli automatique.
//
// Réglages par école dans l'annuaire (parametre_assoc, clé sms_gw_<code>),
// mot de passe chiffré (ecole_bd_chiffrer : AES-256-GCM).

const SMS_GW_URL_DEFAUT = 'https://api.sms-gate.app/3rdparty/v1';

function sms_gw_cle(): ?string {
    $e = function_exists('ecole_courante') ? ecole_courante() : null;
    return $e && !empty($e['code']) ? 'sms_gw_' . preg_replace('/[^A-Za-z0-9_]/', '', (string) $e['code']) : null;
}

/** Réglages de la passerelle de l'école courante (mot de passe déchiffré), ou null. */
function sms_gw_config(): ?array {
    $cle = sms_gw_cle();
    if (!$cle || !function_exists('assoc_param')) return null;
    $c = json_decode((string) assoc_param($cle, ''), true);
    if (!is_array($c) || empty($c['login']) || empty($c['pass'])) return null;
    $c['mot_de_passe'] = ecole_bd_dechiffrer((string) $c['pass']);
    if ($c['mot_de_passe'] === null) return null;
    $c['url'] = rtrim((string) ($c['url'] ?? '') ?: SMS_GW_URL_DEFAUT, '/');
    $c['sim'] = (int) ($c['sim'] ?? 0);
    $c['actif'] = !empty($c['actif']);
    return $c;
}

function sms_gw_actif(): bool {
    $c = sms_gw_config();
    return $c !== null && $c['actif'];
}

/** Enregistre les réglages ($mot_de_passe vide = garder l'ancien). */
function sms_gw_enregistrer(string $url, string $login, string $mot_de_passe, int $sim, bool $actif): bool {
    $cle = sms_gw_cle();
    if (!$cle) return false;
    $ancien = json_decode((string) assoc_param($cle, ''), true) ?: [];
    $pass = $mot_de_passe !== '' ? ecole_bd_chiffrer($mot_de_passe) : ($ancien['pass'] ?? '');
    assoc_param_set($cle, json_encode(['url' => $url, 'login' => $login, 'pass' => $pass, 'sim' => $sim, 'actif' => $actif ? 1 : 0]),
        (string) ($_SESSION['user']['login'] ?? ''));
    return true;
}

function sms_gw_supprimer(): void {
    if ($cle = sms_gw_cle()) assoc_param_set($cle, null, (string) ($_SESSION['user']['login'] ?? ''));
}

/** Appel HTTP JSON à l'API. Retour : [code HTTP, corps décodé ou texte, erreur réseau]. */
function sms_gw_http(array $c, string $methode, string $chemin, ?array $corps = null): array {
    $url = $c['url'] . $chemin;
    $json = $corps !== null ? json_encode($corps, JSON_UNESCAPED_UNICODE) : null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $methode, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_USERPWD => $c['login'] . ':' . $c['mot_de_passe'], CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        ]);
        if ($json !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        $rep = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = $rep === false ? curl_error($ch) : '';
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => ['method' => $methode, 'timeout' => 20, 'ignore_errors' => true,
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\nAuthorization: Basic " . base64_encode($c['login'] . ':' . $c['mot_de_passe']) . "\r\n",
            'content' => $json ?? '']]);
        $rep = @file_get_contents($url, false, $ctx);
        $code = 0;
        foreach ($http_response_header ?? [] as $l) if (preg_match('#^HTTP/\S+\s+(\d+)#', $l, $m)) $code = (int) $m[1];
        $err = $rep === false ? 'connexion impossible' : '';
    }
    $dec = is_string($rep) ? json_decode($rep, true) : null;
    return [$code, $dec ?? $rep, $err];
}

/**
 * Envoie un même texte à un ou plusieurs numéros (9 chiffres camerounais).
 * Retour : ['ok' => bool, 'id' => ?string, 'erreur' => string].
 */
function sms_gw_envoyer(string $texte, array $tels): array {
    $c = sms_gw_config();
    if (!$c) return ['ok' => false, 'id' => null, 'erreur' => 'Passerelle SMS non configurée.'];
    $nums = [];
    foreach ($tels as $t) if (($n = tel_normaliser_cm((string) $t)) !== null) $nums[] = '+237' . $n;
    if (!$nums) return ['ok' => false, 'id' => null, 'erreur' => 'Aucun numéro valide.'];
    $corps = ['textMessage' => ['text' => $texte], 'phoneNumbers' => array_values(array_unique($nums))];
    if ($c['sim'] > 0) $corps['simNumber'] = $c['sim'];
    [$code, $rep, $err] = sms_gw_http($c, 'POST', '/messages', $corps);
    if ($code === 404) {   // ancienne version de l'application
        $corps_v1 = ['message' => $texte, 'phoneNumbers' => $corps['phoneNumbers']] + (isset($corps['simNumber']) ? ['simNumber' => $corps['simNumber']] : []);
        [$code, $rep, $err] = sms_gw_http($c, 'POST', '/message', $corps_v1);
    }
    if ($code >= 200 && $code < 300) return ['ok' => true, 'id' => is_array($rep) ? (string) ($rep['id'] ?? '') : null, 'erreur' => ''];
    $msg = $err ?: (is_array($rep) ? (string) ($rep['message'] ?? $rep['error'] ?? json_encode($rep)) : trim(strip_tags((string) $rep)));
    if ($code === 401) $msg = 'Identifiant ou mot de passe refusé (voir l\'application : Paramètres → Serveur cloud).';
    return ['ok' => false, 'id' => null, 'erreur' => 'HTTP ' . $code . ($msg !== '' ? ' — ' . mb_substr($msg, 0, 200) : '')];
}

/** État d'un message envoyé (Pending, Processed, Sent, Delivered, Failed…), ou null. */
function sms_gw_etat(string $id): ?string {
    $c = sms_gw_config();
    if (!$c || $id === '') return null;
    [$code, $rep] = sms_gw_http($c, 'GET', '/messages/' . rawurlencode($id));
    if ($code === 404) [$code, $rep] = sms_gw_http($c, 'GET', '/message/' . rawurlencode($id));
    return ($code >= 200 && $code < 300 && is_array($rep)) ? (string) ($rep['state'] ?? '') : null;
}

/** Rôles autorisés à configurer la passerelle (en plus du compte propriétaire). */
function sms_gw_peut_configurer(): bool {
    if (function_exists('est_proprietaire_ecole') && est_proprietaire_ecole()) return true;
    $sec = type_enseignement_courant() === 'secondaire';
    return in_array(role_connecte(), $sec ? ['ADMIN', 'PROVISEUR', 'FONDATEUR'] : ['DIRECTEUR', 'FONDATEUR'], true);
}
