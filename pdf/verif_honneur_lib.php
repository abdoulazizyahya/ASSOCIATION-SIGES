<?php
// pdf/verif_honneur_lib.php — signature et vérification d'authenticité des
// certificats de tableau d'honneur. Miroir de pdf/verif_lib.php (bulletins)
// — clé secrète, payload et cache SÉPARÉS de ceux des bulletins : scanner un
// QR de certificat ne doit jamais valider un bulletin, et inversement.
// Réutilise les fonctions génériques qr_incruster_photo()/
// bulletin_photo_pour_qr() de pdf/verif_lib.php (déjà chargé par l'appelant).

if (!defined('HONNEUR_VERIF_SECRET')) {
    // ⚠️ Même remarque que BULLETIN_VERIF_SECRET (pdf/verif_lib.php) : à
    // définir dans config.php avant mise en production.
    define('HONNEUR_VERIF_SECRET', 'CHANGE_ME_JAYNITAARE_INSECURE_DEFAULT_SECRET_HONNEUR');
}

/**
 * $vue = 'trim' ou 'annee' ; $id_periode = id_trim ou année (voir
 * pdf/certificat_tableau_honneur.php pour la dérivation) ; $piste = 'fr'|'ar'.
 */
function honneur_verif_hash(int $id_eleve, string $vue, int $id_periode, string $matricule, string $piste = 'fr'): string {
    $payload = $id_eleve . '|' . $vue . '|' . $id_periode . '|' . $matricule . '|' . $piste;
    return substr(hash_hmac('sha256', $payload, HONNEUR_VERIF_SECRET), 0, 20);
}

function honneur_verif_url(int $id_eleve, string $vue, int $id_periode, string $matricule, string $piste = 'fr'): string {
    $h = honneur_verif_hash($id_eleve, $vue, $id_periode, $matricule, $piste);
    return bulletin_verif_base_url() . '/verif_honneur.php?e=' . $id_eleve . '&v=' . $vue . '&p=' . $id_periode . '&t=' . $piste . '&h=' . $h;
}

/**
 * Recharge toujours l'élève depuis la BD (jamais fait confiance à
 * l'appelant) — même principe que bulletin_verif_valider().
 */
function honneur_verif_valider(int $id_eleve, string $vue, int $id_periode, string $piste, string $h_recu): ?array {
    if (!$id_eleve || !$id_periode || $h_recu === '' || !in_array($vue, ['trim', 'annee'], true)) return null;
    $piste = in_array($piste, ['fr', 'ar'], true) ? $piste : 'fr';
    $eleve = db_one("SELECT * FROM eleve WHERE id_eleve=?", [$id_eleve]);
    if (!$eleve) return null;
    $h_attendu = honneur_verif_hash($id_eleve, $vue, $id_periode, id_affichage_eleve($eleve), $piste);
    return hash_equals($h_attendu, $h_recu) ? $eleve : null;
}

/**
 * Génère le PNG du QR (photo incrustée, cache disque) pour un certificat de
 * tableau d'honneur — même mécanisme que bulletin_qr_fichier_temp() (voir
 * pdf/verif_lib.php), cache et clé de vérification séparés.
 */
function honneur_qr_fichier_temp(array $eleve, string $vue, int $id_periode, string $piste = 'fr'): ?string {
    require_once __DIR__ . '/qrcode.php';
    $id_eleve = (int) ($eleve['id_eleve'] ?? 0);
    if (!$id_eleve) return null;

    [$photo_path, $photo_est_temp] = bulletin_photo_pour_qr($eleve);
    $cle_cache = hash('crc32b', HONNEUR_VERIF_SECRET) . '_' . $id_eleve . '_' . $piste . '_' . $vue . '_' . $id_periode
        // Voir bulletin_qr_fichier_temp() (pdf/verif_lib.php) : intègre l'adresse
        // réseau du serveur pour régénérer le QR après un changement d'IP.
        . '_' . hash('crc32b', bulletin_verif_base_url())
        . '_' . ($photo_path !== '' && is_file($photo_path) ? hash_file('crc32b', $photo_path) : 'none');
    $dossier_cache = __DIR__ . '/../assets/uploads/qr_honneur';
    if (!is_dir($dossier_cache)) @mkdir($dossier_cache, 0755, true);
    $chemin_cache = $dossier_cache . '/' . $cle_cache . '.png';

    if (is_file($chemin_cache)) {
        if ($photo_est_temp) @unlink($photo_path);
        return $chemin_cache;
    }

    $verif_url = honneur_verif_url($id_eleve, $vue, $id_periode, (string) ($eleve['Mat_elv'] ?? ''), $piste);
    $qr_gen = new QRCode($verif_url, ['s' => 'qr-h']);
    $qr_img = $qr_gen->render_image();
    qr_incruster_photo($qr_img, $photo_path);
    imagepng($qr_img, $chemin_cache);
    imagedestroy($qr_img);
    if ($photo_est_temp) @unlink($photo_path);
    return is_file($chemin_cache) ? $chemin_cache : null;
}
