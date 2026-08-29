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
require_once __DIR__ . '/verif_commun.php';

/**
 * Calcule la signature d'un bulletin (élève + période + matricule + piste).
 * $vue = 'trim' ou 'annee' ; $id_periode = id_trim ou id_annee selon le cas.
 * $piste = 'fr' ou 'ar' — jaynitaare_v2 a 2 pistes de bulletins distinctes
 * pour un même élève (id_eleve partagé entre les 2, voir eleve/eleve_arabe) ;
 * absent du modèle ABZ_MBE d'origine (une seule piste), ajouté ici pour que
 * verif_bulletin.php sache vers quel générateur PDF rediriger. Paramètre
 * optionnel (défaut 'fr') pour ne pas casser les QR déjà en cache disque
 * générés avant son ajout.
 * $secret : forcé à la vérification pour tester le hash legacy (sans école).
 */
function bulletin_verif_hash(int $id_eleve, string $vue, int $id_periode, string $matricule, string $piste = 'fr', ?string $secret = null): string {
    $payload = $id_eleve . '|' . $vue . '|' . $id_periode . '|' . $matricule . '|' . $piste;
    return substr(hash_hmac('sha256', $payload, $secret ?? verif_secret(BULLETIN_VERIF_SECRET)), 0, 20);
}

function b64url_encode(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

// Signature ECDSA P-256/SHA-256 → r||s brut sur 64 octets (format WebCrypto/
// IEEE P1363), PAS le DER que renvoie openssl_sign() nativement (ASN.1
// SEQUENCE{INTEGER r, INTEGER s}, longueur variable ~70-72 octets) —
// crypto.subtle.verify() de la piste offline (verif_bulletin_hors_ligne.html)
// exige le format brut, d'où cette conversion.
function der_vers_raw_ecdsa(string $der, int $taille = 32): string {
    $pos = 0;
    if (ord($der[$pos++]) !== 0x30) throw new RuntimeException('DER invalide (pas une SEQUENCE)');
    $seqLen = ord($der[$pos++]);
    if ($seqLen & 0x80) {
        $n = $seqLen & 0x7F; $seqLen = 0;
        for ($i = 0; $i < $n; $i++) $seqLen = ($seqLen << 8) | ord($der[$pos++]);
    }
    $lireEntier = function () use (&$pos, $der, $taille): string {
        if (ord($der[$pos++]) !== 0x02) throw new RuntimeException('DER invalide (pas un INTEGER)');
        $l = ord($der[$pos++]);
        $octets = substr($der, $pos, $l);
        $pos += $l;
        while (strlen($octets) > $taille && ord($octets[0]) === 0) $octets = substr($octets, 1);
        return str_pad($octets, $taille, "\x00", STR_PAD_LEFT);
    };
    return $lireEntier() . $lireEntier();
}

/**
 * Signe les données clés d'un bulletin (nom/matricule/période/moyenne/rang)
 * avec la clé privée ECDSA P-256 (config.php::BULLETIN_VERIF_PRIVATE_KEY_PEM)
 * — vérifiable HORS LIGNE avec la clé publique correspondante, embarquée
 * dans verif_bulletin_hors_ligne.html (voir ce fichier). Contrairement à
 * bulletin_verif_hash() (HMAC symétrique, nécessite de recontacter le
 * serveur), une signature asymétrique se vérifie sans réseau — c'est le
 * but recherché. N'affecte jamais bulletin_verif_hash()/bulletin_verif_valider()
 * (mode en ligne, inchangé).
 * Retourne '' si BULLETIN_VERIF_PRIVATE_KEY_PEM n'est pas définie (offline
 * désactivé silencieusement plutôt que de faire planter la génération PDF).
 */
function bulletin_verif_signature_offline(array $champs): string {
    $pem = verif_cle_privee_pem();   // clé de l'école si définie, sinon globale
    if (!$pem) return '';
    $cle = openssl_pkey_get_private($pem);
    if (!$cle) return '';

    $donnees = implode('|', array_map(fn($v) => str_replace(['|', "\n"], ' ', (string) $v), $champs));
    if (!openssl_sign($donnees, $sig_der, $cle, OPENSSL_ALGO_SHA256)) return '';
    $sig_raw = der_vers_raw_ecdsa($sig_der);

    return b64url_encode($donnees) . '.' . b64url_encode($sig_raw);
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
 * Construit l'URL de vérification à encoder dans le QR code. $donnees_offline
 * (optionnel) : [matricule, niu, nom, naissance, periode_libelle, moy_ua1,
 * moy_ua2, moyenne, rang, matieres] — le 10e champ (facultatif, appelants plus
 * anciens sans lui) est le détail par matière : "nom~moyenne~cote" séparés
 * par ";" (demande du 26/08/2026 — priorité au contenu complet plutôt qu'à
 * la légèreté du QR, voir pdf/bulletin_trimestriel_arabe.php). Si fourni,
 * ajoute un paramètre `d=`
 * signé (ECDSA, voir bulletin_verif_signature_offline()) vérifiable hors
 * ligne, sans toucher au paramètre `h=` (mode en ligne, inchangé).
 * $chiffres_ar : préférence d'affichage (chiffres arabe oriental ٠١٢٣...,
 * bouton "Convertir" de pages/bulletins_arabe/index.php) — pas une donnée
 * de sécurité, non signée, juste reportée sur le lien "Ouvrir le bulletin"
 * de verif_bulletin.php pour que le PDF rouvert depuis le QR garde la même
 * préférence que celui imprimé/exporté.
 */
function bulletin_verif_url(int $id_eleve, string $vue, int $id_periode, string $matricule, string $piste = 'fr', array $donnees_offline = [], bool $chiffres_ar = false): string {
    $h = bulletin_verif_hash($id_eleve, $vue, $id_periode, $matricule, $piste);
    $url = verif_ajout_ec(bulletin_verif_base_url() . '/verif_bulletin.php?e=' . $id_eleve . '&v=' . $vue . '&p=' . $id_periode . '&t=' . $piste . '&h=' . $h);
    if ($donnees_offline) {
        $d = bulletin_verif_signature_offline($donnees_offline);
        if ($d !== '') $url .= '&d=' . $d;
    }
    if ($chiffres_ar) $url .= '&chiffres_ar=1';
    return $url;
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
function bulletin_qr_fichier_temp(array $eleve, string $vue, int $id_periode, string $piste = 'fr', array $donnees_offline = [], bool $chiffres_ar = false): ?string {
    require_once __DIR__ . '/qrcode.php';
    $id_eleve = (int) ($eleve['id_eleve'] ?? 0);
    if (!$id_eleve) return null;

    [$photo_path, $photo_est_temp] = bulletin_photo_pour_qr($eleve);
    $cle_cache = hash('crc32b', verif_secret(BULLETIN_VERIF_SECRET)) . '_' . $id_eleve . '_' . $piste . '_' . $vue . '_' . $id_periode
        // Hash de la base URL (schéma + hôte réseau + chemin appli) : sans lui,
        // un QR déjà en cache gardait indéfiniment l'ANCIENNE adresse IP même
        // après un changement de réseau ou de SERVEUR_LAN_HOST dans config.php
        // (cause réelle d'échec de scan : le téléphone visait une IP qui
        // n'existait plus sur le réseau). Change automatiquement le fichier de
        // cache dès que l'adresse du serveur change — aucune purge manuelle.
        . '_' . hash('crc32b', bulletin_verif_base_url())
        . '_' . ($photo_path !== '' && is_file($photo_path) ? hash_file('crc32b', $photo_path) : 'none')
        // Inclut les données offline (moyenne/rang...) : un bulletin régénéré
        // après correction de note doit régénérer son QR, sinon la signature
        // offline embarquée resterait périmée silencieusement.
        . '_' . ($donnees_offline ? hash('crc32b', implode('|', $donnees_offline)) : 'noffl')
        . '_' . ($chiffres_ar ? 'car1' : 'car0');
    $dossier_cache = __DIR__ . '/../assets/uploads/qr_bulletins';
    if (!is_dir($dossier_cache)) @mkdir($dossier_cache, 0755, true);
    $chemin_cache = $dossier_cache . '/' . $cle_cache . '.png';

    if (is_file($chemin_cache)) {
        if ($photo_est_temp) @unlink($photo_path);
        return $chemin_cache;
    }

    // Niveau Q (25% de correction), pas H (30%) : la photo incrustée ne
    // couvre qu'environ 5% de la surface (côté de la case = 22% de la
    // largeur, donc 0.22² ≈ 5% de l'aire) — Q laisse une marge confortable
    // (~20%) pour l'usure réelle tout en réduisant nettement le nombre de
    // modules par rapport à H (mesuré : ~117 vs ~129 pour un même contenu),
    // important maintenant que $donnees_offline peut inclure le détail par
    // matière (25/08/2026).
    $verif_url = bulletin_verif_url($id_eleve, $vue, $id_periode, (string) ($eleve['Mat_elv'] ?? ''), $piste, $donnees_offline, $chiffres_ar);
    $qr_gen = new QRCode($verif_url, ['s' => 'qr-q']);
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
    $mat = id_affichage_eleve($eleve);
    // hash « scopé école » (nouveau) OU hash legacy (documents antérieurs au
    // multi-établissement — déjà routés vers la bonne base par ?ec=).
    if (hash_equals(bulletin_verif_hash($id_eleve, $vue, $id_periode, $mat, $piste), $h_recu)) return $eleve;
    if (hash_equals(bulletin_verif_hash($id_eleve, $vue, $id_periode, $mat, $piste, BULLETIN_VERIF_SECRET), $h_recu)) return $eleve;
    return null;
}
