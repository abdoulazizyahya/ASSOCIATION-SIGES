<?php
// bd/lib/licence.php — Système de licence PAR ÉCOLE (chaque base a son
// propre cycle de vie, indépendant des autres — demande explicite du
// 13/09/2026). Migration v56 (bd/migration_v56.sql, 4 tables : licence,
// licence_historique, licence_securite, licence_cles_utilisees).
//
// Rôles (spécifiques à SIGES, pas les rôles génériques du cahier des
// charges) :
//   - PROPRIÉTAIRE de TOUT LE SYSTÈME (est_proprietaire_association(),
//     ecole_contexte.php) : SEUL habilité à générer une clé ou à
//     renouveler directement par dates — jamais bridé par la licence
//     elle-même (comme il ne l'est jamais par est_lecture_seule()). Ni un
//     superadmin association qui n'est PAS le propriétaire, ni le
//     Directeur, ni le Fondateur n'ont cette capacité.
//   - DIRECTEUR ou FONDATEUR de l'école : reçoivent la clé du propriétaire
//     et la SAISISSENT (jamais générée ni renouvelée directement par eux).
//     Aucun autre rôle n'a accès aux actions de licence — tous les rôles
//     voient seulement l'état (lecture).
//
// À inclure APRÈS connexion.php (db_all/db_one/db_val/db_exec) — voir
// le require_once dans connexion.php, juste après ecole_contexte.php.
//
// Fonctions publiques principales :
//   licence_etat(bool $forcer = false): array   'ok'|'alerte'|'expiree' — fail-closed, mémoïsé
//   licence_generer_cle(string $debut, string $fin): string
//   licence_decoder_cle(string $cle): ?array    ['date_debut'=>.., 'date_fin'=>..] ou null
//   licence_appliquer_cle(string $cle, ?string $auteur): array  ['ok'=>bool,'message'=>string]
//   licence_renouveler_direct(string $debut, string $fin, ?string $auteur): void
//   licence_bloque(): bool
//   licence_debloquer(): void
//   licence_tentatives_restantes(): int

// ── Secret cryptographique ───────────────────────────────────────────
// Défini dans config.php (valeur PAR DÉFAUT manifestement insécure) et
// surchargeable dans config.local.php (gitignoré) — voir
// config.local.php.example. Sert À LA FOIS à la signature anti-tamper de
// la ligne `licence` ET au chiffrement des clés générées : compromettre
// ce secret compromet les deux protections.
function licence_secret(): string {
    return defined('LICENCE_SECRET') ? LICENCE_SECRET : 'CHANGEZ-MOI-secret-non-securise-par-defaut';
}

// Clé AES-256 dérivée du secret (toujours exactement 32 octets, quelle
// que soit la longueur de LICENCE_SECRET saisie par l'installateur).
function licence_cle_aes(): string {
    return hash('sha256', licence_secret(), true);
}

// ── Signature anti-tamper de la ligne `licence` ──────────────────────
// HMAC-SHA256(date_expiration|cle_licence|statut, SECRET) — statut est
// DANS le message signé : sans ça, remettre statut='active' à la main en
// base après une expiration contournerait la protection sans invalider
// la signature. hash_equals() (comparaison à temps constant) à la lecture.
function licence_signature(string $date_expiration, ?string $cle_licence, string $statut): string {
    $message = $date_expiration . '|' . ($cle_licence ?? '') . '|' . $statut;
    return hash_hmac('sha256', $message, licence_secret());
}

// ── Chiffrement authentifié de la clé de licence (AES-256-GCM) ───────
// Payload compact : 2 entiers 16 bits (jours écoulés depuis LICENCE_EPOQUE
// jusqu'à date_debut, durée en jours jusqu'à date_fin) — 4 octets. Nonce
// ALÉATOIRE à chaque génération (jamais déterministe) : deux clés pour
// les mêmes dates sont donc toujours différentes. Format transmis :
// nonce(12) + texte chiffré(4) + tag d'authentification(16) = 32 octets,
// hex majuscule découpé en blocs de 4 séparés par des tirets (lisible à
// la main). AUCUNE date n'apparaît en clair, ni approximativement, dans
// le texte de la clé — seul le déchiffrement (avec le secret serveur)
// les révèle.
const LICENCE_EPOQUE = '2020-01-01';

function licence_generer_cle(string $date_debut, string $date_fin): string {
    $epoque = new DateTimeImmutable(LICENCE_EPOQUE);
    $d0 = new DateTimeImmutable($date_debut);
    $d1 = new DateTimeImmutable($date_fin);
    if ($d0 < $epoque) throw new InvalidArgumentException('date_debut antérieure à l\'époque de référence.');
    if ($d1 <= $d0) throw new InvalidArgumentException('date_fin doit être postérieure à date_debut.');
    $jours_debut = (int) $epoque->diff($d0)->days;
    $duree       = (int) $d0->diff($d1)->days;
    if ($jours_debut > 65535 || $duree > 65535) throw new InvalidArgumentException('Période hors plage représentable.');

    $payload = pack('n', $jours_debut) . pack('n', $duree);
    $nonce   = random_bytes(12);
    $tag     = '';
    $chiffre = openssl_encrypt($payload, 'aes-256-gcm', licence_cle_aes(), OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($chiffre === false) throw new RuntimeException('Échec du chiffrement de la clé de licence.');

    $brut = $nonce . $chiffre . $tag; // 12 + 4 + 16 = 32 octets
    $hex  = strtoupper(bin2hex($brut));
    return implode('-', str_split($hex, 4));
}

// Déchiffre + authentifie une clé de licence. Renvoie null (JAMAIS
// d'exception) sur tout format invalide, longueur incorrecte, ou échec
// d'authentification GCM (un seul caractère altéré → échec détecté).
function licence_decoder_cle(string $cle): ?array {
    $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $cle));
    if (strlen($hex) !== 64) return null; // 32 octets attendus (12+4+16)
    $brut = @hex2bin($hex);
    if ($brut === false || strlen($brut) !== 32) return null;

    $nonce   = substr($brut, 0, 12);
    $chiffre = substr($brut, 12, 4);
    $tag     = substr($brut, 16, 16);
    try {
        $payload = @openssl_decrypt($chiffre, 'aes-256-gcm', licence_cle_aes(), OPENSSL_RAW_DATA, $nonce, $tag);
    } catch (\Throwable $e) {
        return null;
    }
    if ($payload === false || strlen($payload) !== 4) return null;

    $vals = @unpack('njours_debut/nduree', $payload);
    if (!$vals || $vals['duree'] < 1) return null;
    try {
        $epoque     = new DateTimeImmutable(LICENCE_EPOQUE);
        $date_debut = $epoque->modify('+' . $vals['jours_debut'] . ' days');
        $date_fin   = $date_debut->modify('+' . $vals['duree'] . ' days');
    } catch (\Throwable $e) {
        return null;
    }
    return ['date_debut' => $date_debut->format('Y-m-d'), 'date_fin' => $date_fin->format('Y-m-d')];
}

// Hash one-way (anti-rejeu) — normalisation IDENTIQUE à licence_decoder_cle()
// (insensible à la casse/aux espaces/tirets) avant hachage, pour que deux
// saisies équivalentes d'une même clé produisent le même hash.
function licence_cle_hash(string $cle): string {
    $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $cle));
    return hash('sha256', $hex);
}

// ── État courant — POINT D'ENTRÉE UNIQUE, fail-closed sans exception ──
// Mémoïsé pour la durée de la requête (pas de re-lecture SQL répétée) ;
// licence_etat(true) invalide immédiatement ce cache (à appeler après un
// renouvellement, dans la même requête — voir licence_renouveler()).
// Table absente, ligne absente, signature invalide, erreur SQL
// quelconque → TOUJOURS 'expiree', jamais 'ok' par défaut, jamais
// d'exception qui remonterait à l'utilisateur (journalisée en silence).
function licence_etat(bool $forcer = false): array {
    static $cache = null;
    if ($forcer) $cache = null;
    if ($cache !== null) return $cache;
    try {
        $cache = licence_etat_calculer();
    } catch (\Throwable $e) {
        if (function_exists('journaliser_action')) {
            try { journaliser_action('licence_anomalie', null, get_class($e) . ' : ' . $e->getMessage()); }
            catch (\Throwable $e2) { /* ne jamais laisser l'audit lui-même casser le fail-closed */ }
        }
        $cache = ['etat' => 'expiree', 'jours_restants' => 0, 'motif' => 'anomalie_technique', 'licence' => null];
    }
    return $cache;
}

function licence_etat_calculer(): array {
    $lic = db_one("SELECT * FROM licence ORDER BY id DESC LIMIT 1");
    if (!$lic) {
        return ['etat' => 'expiree', 'jours_restants' => 0, 'motif' => 'aucune_licence', 'licence' => null];
    }
    $attendue = licence_signature($lic['date_expiration'], $lic['cle_licence'], $lic['statut']);
    if (!hash_equals($attendue, (string) $lic['signature'])) {
        return ['etat' => 'expiree', 'jours_restants' => 0, 'motif' => 'signature_invalide', 'licence' => $lic];
    }
    if ($lic['statut'] !== 'active') {
        return ['etat' => 'expiree', 'jours_restants' => 0, 'motif' => 'statut_' . $lic['statut'], 'licence' => $lic];
    }
    // Anti-recul d'horloge (migration v57, demande explicite du 13/09/2026,
    // suite à un test de contournement) : la signature HMAC protège
    // date_expiration contre une modification EN BASE, mais rien ne protège
    // par nature contre une horloge SYSTÈME reculée avant date_expiration —
    // reculer l'heure ferait réapparaître une licence expirée comme valide,
    // sans laisser de trace. licence_horloge_reculee() détecte l'anomalie
    // via un watermark qui ne progresse jamais en arrière.
    if (licence_horloge_reculee()) {
        return ['etat' => 'expiree', 'jours_restants' => 0, 'motif' => 'horloge_reculee', 'licence' => $lic];
    }
    $jours = (int) (new DateTimeImmutable('today'))->diff(new DateTimeImmutable($lic['date_expiration']))->format('%r%a');
    if ($jours < 0) {
        return ['etat' => 'expiree', 'jours_restants' => 0, 'motif' => 'date_depassee', 'licence' => $lic];
    }
    return ['etat' => $jours <= 30 ? 'alerte' : 'ok', 'jours_restants' => $jours, 'motif' => null, 'licence' => $lic];
}

// Tolérance (heures) avant de traiter un recul d'horloge comme une anomalie
// — absorbe une resynchronisation NTP légitime ou un changement de fuseau,
// sans laisser passer un recul délibéré de plusieurs jours/semaines/mois
// (le cas réellement visé : contourner une expiration).
const LICENCE_TOLERANCE_HORLOGE_HEURES = 6;

// Compare l'horloge système courante au dernier « maintenant » observé
// (licence_securite.dernier_maintenant_vu, colonne ajoutée en v57) — ce
// watermark ne progresse QUE vers l'avant. Renvoie true si l'horloge
// actuelle est nettement ANTÉRIEURE au watermark (recul détecté), et fait
// progresser le watermark sinon (throttlé : seulement si ≥1h d'écart, pour
// limiter les écritures à chaque vérification de licence). Fail-OPEN si la
// colonne n'existe pas encore (install pas migrée en v57) — cette
// protection est additive, jamais LE mécanisme fail-closed central.
//
// ⚠ L'écart est calculé ENTIÈREMENT CÔTÉ SQL (TIMESTAMPDIFF, MySQL NOW())
// plutôt qu'en comparant un DateTimeImmutable PHP à une valeur écrite par
// NOW() : un décalage de fuseau horaire entre PHP (date.timezone) et MySQL
// (time_zone de session) — réel, constaté en test sur cette machine, ~1h
// d'écart — fausserait sinon SYSTÉMATIQUEMENT la comparaison à chaque
// appel, quelle que soit la tolérance. En ne faisant jamais interagir
// l'horloge de PHP avec une valeur MySQL, la comparaison reste cohérente
// avec elle-même quel que soit le fuseau configuré de chaque côté.
function licence_horloge_reculee(): bool {
    try {
        $row = db_one("SELECT TIMESTAMPDIFF(SECOND, NOW(), dernier_maintenant_vu) AS ecart_s, dernier_maintenant_vu FROM licence_securite WHERE id=1");
    } catch (\Throwable $e) {
        return false;
    }
    if ($row === null || $row['dernier_maintenant_vu'] === null) {
        try { db_exec("UPDATE licence_securite SET dernier_maintenant_vu=NOW() WHERE id=1"); } catch (\Throwable $e) {}
        return false;
    }
    // Positif si le watermark est POSTÉRIEUR à maintenant (donc un "maintenant"
    // plus tardif a déjà été vu — l'horloge courante semble avoir reculé).
    $ecart_h = ((int) $row['ecart_s']) / 3600;
    if ($ecart_h > LICENCE_TOLERANCE_HORLOGE_HEURES) {
        return true;
    }
    if ($ecart_h < -1) {
        try { db_exec("UPDATE licence_securite SET dernier_maintenant_vu=NOW() WHERE id=1 AND (dernier_maintenant_vu IS NULL OR dernier_maintenant_vu < NOW())"); }
        catch (\Throwable $e) {}
    }
    return false;
}

// Vrai si l'écriture COURANTE doit être refusée pour cause de licence
// expirée — TOUTES les exemptions centralisées ici (propriétaire jamais
// bridé, annuaire association SANS table licence — pas de fail-closed
// hors contexte école, scripts de continuité de compte, page Licence
// elle-même) : utilisée par csrf_verifier() (fonctions.php, message
// convivial tôt) ET indirectement par db_exec() (connexion.php, filet de
// sécurité bas niveau par table — logique un peu différente là car elle
// doit encore AUTORISER les écritures sur les 4 tables licence_* même
// quand cette fonction-ci renverrait true).
function licence_ecriture_bloquee(): bool {
    if (function_exists('est_proprietaire_association') && est_proprietaire_association()) return false;
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if (in_array($script, ['configurer_securite.php', 'mot_de_passe_oublie.php'], true)) return false;
    if (function_exists('page_courante_relative') && page_courante_relative() === 'pages/parametres/licence.php') return false;

    global $link;
    if (!($link instanceof mysqli)) return false;
    $r = @mysqli_query($link, 'SELECT DATABASE()');
    $db_courante = $r ? (mysqli_fetch_row($r)[0] ?? null) : null;
    // Base introuvable, ou annuaire association (pas de table licence,
    // cycle de vie par ÉCOLE uniquement — jamais de fail-closed ici) :
    // rien à bloquer.
    if ($db_courante === null || (defined('DB_NAME_ASSOC') && $db_courante === DB_NAME_ASSOC)) return false;

    return licence_etat()['etat'] === 'expiree';
}

// ── Renouvellement (direct ou par clé) ───────────────────────────────
// Écrit une NOUVELLE ligne `licence` (jamais d'UPDATE des dates — la
// ligne la plus récente fait foi, voir licence_etat_calculer()) + son
// entrée d'historique (2 bornes AVANT/APRÈS + méthode). Lève TOUJOURS un
// blocage en cours (demande explicite) et invalide le cache mémoïsé pour
// le reste de la requête courante.
function licence_renouveler(string $date_debut, string $date_fin, string $methode, ?string $cle_licence, ?string $auteur): void {
    $avant = db_one("SELECT date_debut, date_expiration FROM licence ORDER BY id DESC LIMIT 1");
    $statut = 'active';
    $signature = licence_signature($date_fin, $cle_licence, $statut);
    db_exec(
        "INSERT INTO licence (cle_licence, date_debut, date_expiration, statut, derniere_modification_par, date_derniere_modification, signature)
         VALUES (?, ?, ?, ?, ?, NOW(), ?)",
        [$cle_licence, $date_debut, $date_fin, $statut, $auteur, $signature]
    );
    db_exec(
        "INSERT INTO licence_historique (date_debut_avant, date_fin_avant, date_debut_apres, date_fin_apres, methode, modifie_par, date_modification)
         VALUES (?, ?, ?, ?, ?, ?, NOW())",
        [$avant['date_debut'] ?? null, $avant['date_expiration'] ?? null, $date_debut, $date_fin, $methode, $auteur]
    );
    licence_debloquer();
    licence_etat(true);
}

function licence_renouveler_direct(string $date_debut, string $date_fin, ?string $auteur): void {
    licence_renouveler($date_debut, $date_fin, 'direct', null, $auteur);
}

// Décode + authentifie + vérifie l'anti-rejeu + applique — dans cet
// ordre exact (voir cahier des charges) : un format invalide compte
// comme tentative (anti-brute-force) ; une clé DÉJÀ appliquée est
// refusée SANS compter comme tentative (ce n'est pas une clé devinée).
function licence_appliquer_cle(string $cle_brute, ?string $auteur): array {
    if (licence_bloque()) {
        return ['ok' => false, 'message' => 'Accès bloqué après trop de tentatives invalides — contactez le propriétaire.'];
    }
    $decode = licence_decoder_cle($cle_brute);
    if ($decode === null) {
        licence_signaler_echec();
        $restantes = licence_tentatives_restantes();
        return ['ok' => false, 'message' => $restantes > 0
            ? "Clé invalide. Tentative(s) restante(s) avant blocage : $restantes."
            : 'Clé invalide. Accès désormais bloqué — contactez le propriétaire.'];
    }
    $hash = licence_cle_hash($cle_brute);
    if (db_val("SELECT COUNT(*) FROM licence_cles_utilisees WHERE cle_hash=?", [$hash])) {
        return ['ok' => false, 'message' => 'Cette clé a déjà été utilisée précédemment — elle ne peut pas être réappliquée.'];
    }
    licence_renouveler($decode['date_debut'], $decode['date_fin'], 'cle', $cle_brute, $auteur);
    db_exec("INSERT INTO licence_cles_utilisees (cle_hash, utilisee_le) VALUES (?, NOW())", [$hash]);
    return ['ok' => true, 'message' => 'Licence activée jusqu\'au ' . $decode['date_fin'] . '.', 'date_fin' => $decode['date_fin']];
}

// ── Blocage anti-brute-force ──────────────────────────────────────────
// Fail-OPEN volontairement (contrairement à licence_etat()) : ce n'est
// qu'une protection additive contre le brute-force de la saisie de clé,
// pas le mécanisme central fail-closed — une table absente (install pas
// encore migrée) ne doit pas empêcher la connexion.
function licence_bloque(): bool {
    try {
        $row = db_one("SELECT bloque_le FROM licence_securite WHERE id=1");
        return $row !== null && $row['bloque_le'] !== null;
    } catch (\Throwable $e) {
        return false;
    }
}

function licence_debloquer(): void {
    db_exec("UPDATE licence_securite SET tentatives_echouees=0, bloque_le=NULL WHERE id=1");
}

function licence_signaler_echec(): void {
    db_exec("UPDATE licence_securite SET tentatives_echouees = tentatives_echouees + 1 WHERE id=1");
    $row = db_one("SELECT tentatives_echouees FROM licence_securite WHERE id=1");
    if ($row && (int) $row['tentatives_echouees'] >= 5) {
        db_exec("UPDATE licence_securite SET bloque_le=NOW() WHERE id=1 AND bloque_le IS NULL");
    }
}

function licence_tentatives_restantes(): int {
    try {
        $row = db_one("SELECT tentatives_echouees FROM licence_securite WHERE id=1");
        return max(0, 5 - ($row ? (int) $row['tentatives_echouees'] : 0));
    } catch (\Throwable $e) {
        return 5;
    }
}
