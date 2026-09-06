<?php
// bd/lib/totp.php — TOTP (RFC 6238) en PHP pur, sans dépendance.
// Utilisé pour la double authentification des membres de l'association
// (association/login.php + association/securite.php).
//
//   totp_secret_nouveau()            → secret Base32 (160 bits)
//   totp_uri($secret, $label, $issuer) → chaîne otpauth:// (à coller dans
//                                        Google Authenticator / Authy…)
//   totp_verifier($secret, $code)    → bool (tolérance ±1 pas de 30 s)
//   totp_code($secret, $t?)          → code à 6 chiffres (tests)

/** Alphabet Base32 (RFC 4648, sans padding). */
const TOTP_B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function totp_secret_nouveau(int $octets = 20): string {
    $raw = random_bytes($octets);
    $bits = '';
    for ($i = 0; $i < strlen($raw); $i++) {
        $bits .= str_pad(decbin(ord($raw[$i])), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= TOTP_B32[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
    }
    return $out;
}

function totp_b32_decode(string $b32): string {
    $b32 = strtoupper(preg_replace('/[^A-Z2-7]/', '', $b32));
    if ($b32 === '') return '';
    $bits = '';
    for ($i = 0; $i < strlen($b32); $i++) {
        $v = strpos(TOTP_B32, $b32[$i]);
        if ($v === false) continue;
        $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
    }
    $bytes = '';
    foreach (str_split($bits, 8) as $chunk) {
        if (strlen($chunk) === 8) $bytes .= chr(bindec($chunk));
    }
    return $bytes;
}

function totp_code(string $secret, ?int $t = null, int $pas = 30, int $chiffres = 6): string {
    $key = totp_b32_decode($secret);
    if ($key === '') return '';
    $compteur = intdiv($t ?? time(), $pas);
    $bin = pack('N*', 0) . pack('N*', $compteur);           // 8 octets big-endian
    $hash = hash_hmac('sha1', $bin, $key, true);
    $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
    $part = (ord($hash[$offset]) & 0x7F) << 24
          | (ord($hash[$offset + 1]) & 0xFF) << 16
          | (ord($hash[$offset + 2]) & 0xFF) << 8
          | (ord($hash[$offset + 3]) & 0xFF);
    return str_pad((string) ($part % (10 ** $chiffres)), $chiffres, '0', STR_PAD_LEFT);
}

function totp_verifier(string $secret, string $code, int $fenetre = 1): bool {
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6) return false;
    $now = time();
    for ($i = -$fenetre; $i <= $fenetre; $i++) {
        if (hash_equals(totp_code($secret, $now + $i * 30), $code)) return true;
    }
    return false;
}

function totp_uri(string $secret, string $label, string $issuer): string {
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $label)
         . '?secret=' . $secret
         . '&issuer=' . rawurlencode($issuer)
         . '&algorithm=SHA1&digits=6&period=30';
}
