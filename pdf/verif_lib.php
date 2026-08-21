<?php
// pdf/verif_lib.php — signature et vérification d'authenticité des bulletins.
// Inclus à la fois par bulletins/pdf.php (génération du QR) et verif_bulletin.php
// (vérification lors du scan). Garder ces deux usages synchronisés.

if (!defined('BULLETIN_VERIF_SECRET')) {
    // ⚠️ IMPORTANT — À FAIRE AVANT MISE EN PRODUCTION :
    // Définir une vraie valeur secrète et aléatoire dans config.php, par ex. :
    //   define('BULLETIN_VERIF_SECRET', 'colle_ici_une_chaine_aleatoire_longue');
    // (générable une fois avec : php -r "echo bin2hex(random_bytes(32));")
    // Tant que ce fallback est utilisé, n'importe qui lisant ce fichier peut
    // forger un QR "authentique" : la vérification n'est alors pas fiable.
    define('BULLETIN_VERIF_SECRET', 'CHANGE_ME_ABZ_MBE_INSECURE_DEFAULT_SECRET');
}

/**
 * Calcule la signature d'un bulletin (élève + période + matricule + piste).
 * $vue = 'trim' ou 'annee' ; $id_periode = id_trim ou id_annee selon le cas.
 * $piste = 'fr' ou 'ar' — jaynitaare_v2 a 2 pistes de bulletins distinctes
 * pour un même élève (id_eleve partagé entre les 2, voir eleve/eleve_arabe) ;
 * absent du modèle ABZ_MBE d'origine (une seule piste), ajouté ici pour que
 * verif_bulletin.php sache vers quel générateur PDF rediriger. Paramètre
 * optionnel (défaut 'fr') pour ne pas casser les QR déjà en cache disque
 * générés avant son ajout.
 */
function bulletin_verif_hash(int $id_eleve, string $vue, int $id_periode, string $matricule, string $piste = 'fr'): string {
    $payload = $id_eleve . '|' . $vue . '|' . $id_periode . '|' . $matricule . '|' . $piste;
    return substr(hash_hmac('sha256', $payload, BULLETIN_VERIF_SECRET), 0, 20);
}

/**
 * Base absolue (schéma + hôte + chemin de l'appli) pour les URL encodées dans
 * un QR code : contrairement à un lien HTML classique, un lecteur de QR code
 * externe (téléphone d'un parent, etc.) n'a pas de page de référence pour
 * résoudre une URL relative comme APP_URL ('/ABZ_MBE') — il lui faut l'adresse
 * complète. Définir BULLETIN_VERIF_BASE_URL dans config.php si le nom
 * d'hôte public diffère de celui de la requête courante (ex. reverse proxy) ;
 * sinon déduit automatiquement de la requête en cours.
 */
function bulletin_verif_base_url(): string {
    if (defined('BULLETIN_VERIF_BASE_URL') && BULLETIN_VERIF_BASE_URL !== '') {
        return rtrim(BULLETIN_VERIF_BASE_URL, '/');
    }
    $https  = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https ? 'https' : 'http';
    // hote_verif_reseau() (fonctions.php) remplace "localhost" par l'adresse
    // réseau réelle du serveur — indispensable pour qu'un téléphone qui
    // scanne le QR du bulletin puisse effectivement joindre le serveur.
    return $scheme . '://' . hote_verif_reseau() . APP_URL;
}

/**
 * Construit l'URL de vérification à encoder dans le QR code.
 */
function bulletin_verif_url(int $id_eleve, string $vue, int $id_periode, string $matricule, string $piste = 'fr'): string {
    $h = bulletin_verif_hash($id_eleve, $vue, $id_periode, $matricule, $piste);
    return bulletin_verif_base_url() . '/verif_bulletin.php?e=' . $id_eleve . '&v=' . $vue . '&p=' . $id_periode . '&t=' . $piste . '&h=' . $h;
}

/**
 * Incruste une photo au centre d'une image QR déjà générée (canevas GD en
 * mémoire, pas encore encodé en PNG) — même style visuel qu'ABZ_MBE
 * (pages/bulletins/pdf*.php : qr_incruster_photo()/qr_incruster_photo_c()).
 * Fonction générique et pure (aucune dépendance FPDF/TCPDF/BD).
 */
function qr_incruster_photo($qr_img, string $photo_path): void {
    if ($photo_path === '' || !is_file($photo_path)) return;
    $photo = @imagecreatefromstring(file_get_contents($photo_path));
    if (!$photo) return;

    $w = imagesx($qr_img); $h = imagesy($qr_img);
    $logo_size = (int) round($w * 0.22);
    $pad = (int) round($w * 0.012);
    $box = $logo_size + $pad * 2;
    $bx = (int) (($w - $box) / 2);
    $by = (int) (($h - $box) / 2);

    $blanc = imagecolorallocate($qr_img, 255, 255, 255);
    imagefilledrectangle($qr_img, $bx, $by, $bx + $box - 1, $by + $box - 1, $blanc);

    $pw = imagesx($photo); $ph = imagesy($photo);
    $cote = min($pw, $ph);
    $sx = (int) (($pw - $cote) / 2); $sy = (int) (($ph - $cote) / 2);
    imagecopyresampled($qr_img, $photo, $bx + $pad, $by + $pad, $sx, $sy, $logo_size, $logo_size, $cote, $cote);
    imagedestroy($photo);
}

/**
 * Chemin d'une image utilisable pour l'incrustation QR d'un élève : sa
 * vraie photo si elle existe (BLOB Photo_elv → fichier temporaire, voir
 * photo_eleve_fichier_temp() dans fonctions.php), sinon l'avatar générique
 * garçon/fille (fichier statique, jamais à supprimer). Retourne
 * [chemin, est_temporaire] — l'appelant ne supprime le fichier que si le
 * 2e élément est true.
 */
function bulletin_photo_pour_qr(array $eleve): array {
    $id_eleve = (int) ($eleve['id_eleve'] ?? 0);
    $tmp = photo_eleve_fichier_temp($eleve['Photo_elv'] ?? null, $id_eleve);
    if ($tmp) return [$tmp, true];
    $avatar = (stripos($eleve['Sexe_elv'] ?? '', 'F') === 0) ? 'fille.png' : 'garcon.png';
    return [__DIR__ . '/../assets/img/avatars/' . $avatar, false];
}

/**
 * Génère le PNG du QR de vérification d'un bulletin dans un fichier
 * temporaire, photo de l'élève incrustée au centre (même style qu'ABZ_MBE)
 * — ne dépend ni de FPDF ni de TCPDF (juste pdf/qrcode.php et GD), utilisable
 * par les 4
 * générateurs de bulletin (FR/AR × trim/annuel). L'appelant est responsable
 * de charger l'image via Image() puis de supprimer le fichier retourné.
 * Retourne null si l'élève n'a pas d'id exploitable.
 *
 * Mis en cache disque (optimisation, demande explicite) : le contenu du QR
 * (URL de vérification signée HMAC + photo) est entièrement déterministe
 * pour un (élève, vue, période) donné — seule la clé secrète
 * BULLETIN_VERIF_SECRET ou la photo de l'élève changent ça. Le nom de
 * fichier de cache inclut donc un hash de la clé secrète courante (change
 * automatiquement le cache si la clé change un jour) et un hash de la photo
 * (change automatiquement si l'élève change de photo) — jamais besoin
 * d'invalider explicitement. Fichier RENVOYÉ AU CALLER (jamais supprimé
 * ici) : contrairement à l'ancien comportement (fichier temporaire unique
 * détruit après usage), le cache est un fichier PERSISTANT dans
 * assets/uploads/qr_bulletins/ — l'appelant ne doit PLUS le supprimer après
 * usage (voir les 4 générateurs de bulletin, @unlink retiré).
 */
function bulletin_qr_fichier_temp(array $eleve, string $vue, int $id_periode, string $piste = 'fr'): ?string {
    require_once __DIR__ . '/qrcode.php';
    $id_eleve = (int) ($eleve['id_eleve'] ?? 0);
    if (!$id_eleve) return null;

    [$photo_path, $photo_est_temp] = bulletin_photo_pour_qr($eleve);
    $cle_cache = hash('crc32b', BULLETIN_VERIF_SECRET) . '_' . $id_eleve . '_' . $piste . '_' . $vue . '_' . $id_periode
        . '_' . ($photo_path !== '' && is_file($photo_path) ? hash_file('crc32b', $photo_path) : 'none');
    $dossier_cache = __DIR__ . '/../assets/uploads/qr_bulletins';
    if (!is_dir($dossier_cache)) @mkdir($dossier_cache, 0755, true);
    $chemin_cache = $dossier_cache . '/' . $cle_cache . '.png';

    if (is_file($chemin_cache)) {
        if ($photo_est_temp) @unlink($photo_path);
        return $chemin_cache;
    }

    $verif_url = bulletin_verif_url($id_eleve, $vue, $id_periode, (string) ($eleve['Mat_elv'] ?? ''), $piste);
    $qr_gen = new QRCode($verif_url, ['s' => 'qr-h']);
    $qr_img = $qr_gen->render_image();
    qr_incruster_photo($qr_img, $photo_path);
    imagepng($qr_img, $chemin_cache);
    imagedestroy($qr_img);
    if ($photo_est_temp) @unlink($photo_path);
    return is_file($chemin_cache) ? $chemin_cache : null;
}

/**
 * Vérifie un jeton de bulletin reçu (scan QR ou lien "vh" copié) — utilisée
 * à la fois par verif_bulletin.php (page publique de vérification) et par
 * les 4 générateurs de bulletin eux-mêmes (bypass exiger_connexion() pour
 * la personne qui scanne, jeton "vh" — voir leur en-tête). Recharge
 * toujours l'élève depuis la BD (jamais fait confiance à un $eleve fourni
 * par l'appelant) : $id_eleve/$piste/$vue/$id_periode viennent tous de
 * l'URL, potentiellement forgés — seul le hash HMAC recalculé et comparé en
 * temps constant (hash_equals) fait foi.
 */
function bulletin_verif_valider(int $id_eleve, string $vue, int $id_periode, string $piste, string $h_recu): ?array {
    if (!$id_eleve || !$id_periode || $h_recu === '' || !in_array($vue, ['trim', 'annee'], true)) return null;
    $piste = in_array($piste, ['fr', 'ar'], true) ? $piste : 'fr';
    $eleve = db_one("SELECT * FROM eleve WHERE id_eleve=?", [$id_eleve]);
    if (!$eleve) return null;
    $h_attendu = bulletin_verif_hash($id_eleve, $vue, $id_periode, id_affichage_eleve($eleve), $piste);
    return hash_equals($h_attendu, $h_recu) ? $eleve : null;
}
