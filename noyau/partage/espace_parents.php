<?php
// noyau/partage/espace_parents.php — Espace parents (16/11/2026), primaire et
// secondaire. LECTURE SEULE pour le parent : reste à payer et versements,
// absences, moyennes et rangs des trimestres que l'école a PUBLIÉS.
//
// Deux façons de se connecter, chacune activable par l'école :
//  - téléphone + code SMS : le numéro doit figurer dans les contacts d'un
//    élève (contacts_parents_lot) ; code à 6 chiffres envoyé par la passerelle
//    SMS de l'école, valable 10 min, 5 essais, 3 envois par heure et par numéro ;
//  - matricule (ou NIU) + code parent : code de 8 caractères imprimé par
//    l'école (Envoyer aux parents → Espace parents), stocké haché, un par élève.
// Toute tentative ratée est comptée (parent_tentative) : 5 échecs en 15 min
// bloquent le numéro / le matricule / l'adresse IP.
require_once __DIR__ . '/ecole_donnees.php';
require_once __DIR__ . '/../../secondaire/commun/pages/communication/_passerelle_lib.php';

const EP_DEFAUT = ['actif' => 0, 'sms' => 1, 'code' => 1, 'trims' => [], 'annee' => 0];
const EP_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // ni 0/O, ni 1/I
const EP_DUREE_SESSION = 7200;                              // 2 h sans activité

function ep_reglages(): array { return ed_reglages('espace_parents', EP_DEFAUT); }

function ep_assurer_tables(): void {
    static $faites = [];
    $base = (string) (ecole_courante()['db_name'] ?? '');
    if (isset($faites[$base])) return;
    $faites[$base] = true;
    try {
        db_exec("CREATE TABLE IF NOT EXISTS `parent_code` (
            `id_eleve` int unsigned NOT NULL,
            `code_hash` varchar(255) NOT NULL,
            `cree_le` datetime NOT NULL,
            `auteur` varchar(80) DEFAULT NULL,
            PRIMARY KEY (`id_eleve`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        db_exec("CREATE TABLE IF NOT EXISTS `parent_otp` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `tel` varchar(12) NOT NULL,
            `code_hash` varchar(255) NOT NULL,
            `expire_le` datetime NOT NULL,
            `essais` tinyint unsigned NOT NULL DEFAULT 0,
            `cree_le` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `k_otp_tel` (`tel`, `cree_le`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        db_exec("CREATE TABLE IF NOT EXISTS `parent_tentative` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `cle` varchar(80) NOT NULL,
            `quand` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `k_tent` (`cle`, `quand`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (\Throwable $e) { /* licence expirée : lecture seule */ }
}

// ── Anti-essais ────────────────────────────────────────────────────────
function ep_bloque(array $cles, int $max = 5, int $minutes = 15): bool {
    ep_assurer_tables();
    foreach ($cles as $c) {
        if ((int) db_val("SELECT COUNT(*) FROM parent_tentative WHERE cle=? AND quand > NOW() - INTERVAL $minutes MINUTE", [$c]) >= $max) return true;
    }
    return false;
}
function ep_echec(array $cles): void {
    ep_assurer_tables();
    try {
        foreach ($cles as $c) db_exec("INSERT INTO parent_tentative (cle, quand) VALUES (?, NOW())", [mb_substr($c, 0, 80)]);
        db_exec("DELETE FROM parent_tentative WHERE quand < NOW() - INTERVAL 2 DAY");
    } catch (\Throwable $e) {}
}
function ep_ip(): string { return 'ip:' . ($_SERVER['REMOTE_ADDR'] ?? '?'); }

// ── Codes parents (matricule + code) ───────────────────────────────────
function ep_code_nouveau(): string {
    $c = '';
    for ($i = 0; $i < 8; $i++) $c .= EP_ALPHABET[random_int(0, strlen(EP_ALPHABET) - 1)];
    return substr($c, 0, 4) . '-' . substr($c, 4);
}
function ep_code_normaliser(string $c): string {
    $c = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $c));
    return strlen($c) === 8 ? substr($c, 0, 4) . '-' . substr($c, 4) : $c;
}

/** Crée (ou remplace) les codes des élèves d'une classe. Retour : [['eleve' => ed_eleve, 'code' => 'ABCD-EFGH']]. */
function ep_codes_generer(int $id_classe, ?array $ids = null): array {
    ep_assurer_tables();
    $out = [];
    foreach (ed_eleves($id_classe) as $e) {
        if ($ids !== null && !in_array((int) $e['id'], $ids, true)) continue;
        $code = ep_code_nouveau();
        db_exec("REPLACE INTO parent_code (id_eleve, code_hash, cree_le, auteur) VALUES (?, ?, NOW(), ?)",
            [(int) $e['id'], password_hash($code, PASSWORD_DEFAULT), (string) ($_SESSION['user']['login'] ?? '')]);
        $out[] = ['eleve' => $e, 'code' => $code];
    }
    return $out;
}

/** Élèves d'une classe ayant déjà un code : [id_eleve => date de création]. */
function ep_codes_existants(int $id_classe): array {
    ep_assurer_tables();
    $ids = array_map('intval', array_column(ed_eleves($id_classe), 'id'));
    if (!$ids) return [];
    $out = [];
    foreach (db_all("SELECT id_eleve, cree_le FROM parent_code WHERE id_eleve IN (" . implode(',', $ids) . ")") as $r) $out[(int) $r['id_eleve']] = $r['cree_le'];
    return $out;
}

/** Connexion par matricule (ou NIU) + code. Retour : id_eleve, ou message d'erreur (string). */
function ep_connexion_code(string $ident, string $code) {
    $ident = strtoupper(trim($ident)); $code = ep_code_normaliser($code);
    $cles = ['mat:' . $ident, ep_ip()];
    if (ep_bloque($cles)) return 'Trop d\'essais. Réessayez dans 15 minutes.';
    $eleve = null;
    foreach (ed_eleves() as $e) {
        if ($ident !== '' && ($ident === strtoupper((string) $e['matricule']) || $ident === strtoupper((string) ($e['niu'] ?? '')))) { $eleve = $e; break; }
    }
    $h = $eleve ? db_val("SELECT code_hash FROM parent_code WHERE id_eleve=?", [(int) $eleve['id']]) : null;
    if (!$h || !password_verify($code, (string) $h)) { ep_echec($cles); return 'Matricule ou code parent incorrect.'; }
    return (int) $eleve['id'];
}

// ── Connexion par téléphone + code SMS ─────────────────────────────────
/** Élèves (inscrits cette année) dont les contacts contiennent ce numéro. */
function ep_eleves_du_tel(string $tel): array {
    $ids = array_map('intval', array_column(ed_eleves(), 'id'));
    $out = [];
    foreach (contacts_parents_lot($ids) as $id => $contacts) {
        if (in_array($tel, array_map('strval', array_column($contacts, 'tel')), true)) $out[] = (int) $id;
    }
    return $out;
}

/** Envoie un code. Retour : ['ok' => bool, 'message' => string]. Ne dit jamais si le numéro est connu. */
function ep_otp_envoyer(string $tel_saisi): array {
    $tel = tel_normaliser_cm($tel_saisi);
    if ($tel === null) return ['ok' => false, 'message' => 'Numéro invalide (9 chiffres, ex. 6 96 51 43 46).'];
    if (!sms_gw_actif()) return ['ok' => false, 'message' => 'La connexion par SMS n\'est pas disponible dans cette école : utilisez le code parent.'];
    ep_assurer_tables();
    if (ep_bloque(['tel:' . $tel, ep_ip()])) return ['ok' => false, 'message' => 'Trop d\'essais. Réessayez dans 15 minutes.'];
    $neutre = ['ok' => true, 'message' => 'Si ce numéro est enregistré par l\'école, un code vient de lui être envoyé par SMS.', 'tel' => $tel];
    if ((int) db_val("SELECT COUNT(*) FROM parent_otp WHERE tel=? AND cree_le > NOW() - INTERVAL 1 HOUR", [$tel]) >= 3)
        return ['ok' => false, 'message' => 'Trois codes ont déjà été envoyés à ce numéro dans l\'heure. Patientez avant d\'en redemander un.'];
    if (!ep_eleves_du_tel($tel)) { ep_echec(['tel:' . $tel, ep_ip()]); return $neutre; }   // numéro inconnu : même réponse
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    db_exec("INSERT INTO parent_otp (tel, code_hash, expire_le, cree_le) VALUES (?, ?, NOW() + INTERVAL 10 MINUTE, NOW())", [$tel, password_hash($code, PASSWORD_DEFAULT)]);
    $etab = etab_pour_pdf(get_etablissement());
    $ecole = trim((string) (($etab['sigle'] ?? '') ?: ($etab['nom_fr'] ?? APP_NOM)));
    $r = sms_gw_envoyer("$ecole : votre code de connexion a l'espace parents est $code (valable 10 minutes). Ne le communiquez a personne.", [$tel]);
    if (!$r['ok']) return ['ok' => false, 'message' => 'Le SMS n\'a pas pu partir (' . $r['erreur'] . '). Réessayez plus tard ou utilisez le code parent.'];
    return $neutre;
}

/** Vérifie le code SMS. Retour : liste d'id_eleve, ou message d'erreur (string). */
function ep_otp_verifier(string $tel, string $code) {
    $tel = (string) tel_normaliser_cm($tel);
    $cles = ['tel:' . $tel, ep_ip()];
    if (ep_bloque($cles)) return 'Trop d\'essais. Réessayez dans 15 minutes.';
    ep_assurer_tables();
    $o = db_one("SELECT * FROM parent_otp WHERE tel=? AND expire_le > NOW() ORDER BY id DESC LIMIT 1", [$tel]);
    if (!$o || (int) $o['essais'] >= 5) { ep_echec($cles); return 'Code expiré : demandez-en un nouveau.'; }
    if (!password_verify(preg_replace('/\D/', '', $code), (string) $o['code_hash'])) {
        db_exec("UPDATE parent_otp SET essais=essais+1 WHERE id=?", [$o['id']]);
        ep_echec($cles);
        return 'Code incorrect.';
    }
    db_exec("DELETE FROM parent_otp WHERE tel=?", [$tel]);   // usage unique
    $ids = ep_eleves_du_tel($tel);
    return $ids ?: 'Aucun élève inscrit cette année n\'est rattaché à ce numéro.';
}

// ── Session parent ─────────────────────────────────────────────────────
function ep_session_ouvrir(array $ids, string $mode, ?string $tel = null): void {
    session_init();
    session_regenerate_id(true);
    // Une session parent n'est jamais aussi une session du personnel.
    unset($_SESSION['user'], $_SESSION['user_id'], $_SESSION['membre']);
    $_SESSION['parent'] = ['eleves' => array_values(array_unique(array_map('intval', $ids))), 'mode' => $mode, 'tel' => $tel,
                           'ecole' => (int) (ecole_courante()['id'] ?? 0), 'vu' => time()];
}

/** Garde des pages parents/ : session valide, sinon retour à la connexion. */
function ep_exiger(): array {
    session_init();
    $p = $_SESSION['parent'] ?? null;
    $ok = $p && (int) ($p['ecole'] ?? -1) === (int) (ecole_courante()['id'] ?? 0) && time() - (int) $p['vu'] < EP_DUREE_SESSION && ep_reglages()['actif'];
    if (!$ok) {
        unset($_SESSION['parent']);
        header('Location: ' . APP_URL . '/parents/index.php' . (ecole_courante() ? '?ec=' . urlencode((string) ecole_courante()['code']) : ''));
        exit;
    }
    $_SESSION['parent']['vu'] = time();
    return $_SESSION['parent'];
}

// ── Données d'un enfant ────────────────────────────────────────────────
/** Versements de l'année : [['date', 'montant', 'mode', 'ref']]. */
function ep_versements(int $id_eleve): array {
    $an = ed_annee(); $out = [];
    try {
        if (!ed_secondaire()) {
            foreach (db_all("SELECT date_paiement d, montant_paiement m, mode_paiement mo, ref_paiement r FROM paiement_frais WHERE id_eleve=? AND val_annee=? ORDER BY id_pay DESC", [$id_eleve, $an['cle']]) as $r) $out[] = $r;
        } else {
            try { foreach (db_all("SELECT date_paiement d, montant m, id_operateur mo, numero_recu r FROM paiement_frais WHERE id_eleve=? AND id_annee=?", [$id_eleve, $an['cle']]) as $r) $out[] = $r; } catch (\Throwable $e) {}
            try { foreach (db_all("SELECT date_paiement d, montant_paiement m, mode_paiement mo, ref_paiement r FROM paiement_prive WHERE id_eleve=? AND id_annee=?", [$id_eleve, $an['cle']]) as $r) $out[] = $r; } catch (\Throwable $e) {}
        }
    } catch (\Throwable $e) {}
    $ts = fn($d) => ($t = strtotime(str_replace('/', '-', (string) $d))) ? $t : 0;
    usort($out, fn($a, $b) => $ts($b['d']) <=> $ts($a['d']));
    return array_map(fn($r) => ['date' => ($t = $ts($r['d'])) ? date('d/m/Y', $t) : (string) $r['d'], 'montant' => (float) $r['m'],
                                'mode' => str_replace('_', ' ', (string) $r['mo']), 'ref' => (string) $r['r']], $out);
}

/** Absences par trimestre : [['libelle', 'non_jus', 'jus', 'unite' (h|j)]]. */
function ep_absences(array $e): array {
    require_once __DIR__ . '/../../secondaire/commun/pages/analyse/_analyse_lib.php';
    $an = ed_annee(); $out = [];
    foreach (an_periodes() as $p) {
        if ($p['cle'] === 'annee') continue;
        try {
            if (ed_secondaire()) {
                $a = eleve_absence_trimestre((string) $e['matricule'], $p['id'], (int) $e['id_classe'], (string) $an['libelle']);
                $out[] = ['libelle' => $p['libelle'], 'non_jus' => $a['non_jus'], 'jus' => $a['jus'], 'unite' => 'h'];
            } else {
                $nj = jours_absence_non_justifiees_trimestre((int) $e['id'], (int) $e['id_classe'], $p['id'], (string) $an['cle']);
                $out[] = ['libelle' => $p['libelle'], 'non_jus' => $nj, 'jus' => null, 'unite' => 'j'];
            }
        } catch (\Throwable $ex) {}
    }
    return $out;
}

/** Moyennes des périodes publiées : [['libelle', 'moyenne', 'rang', 'effectif', 'moy_classe']]. */
function ep_resultats(array $e): array {
    require_once __DIR__ . '/../../secondaire/commun/pages/analyse/_analyse_lib.php';
    $reg = ep_reglages(); $publies = array_map('intval', (array) $reg['trims']);
    $out = [];
    foreach (an_periodes() as $p) {
        $visible = $p['cle'] === 'annee' ? !empty($reg['annee']) : in_array($p['id'], $publies, true);
        if (!$visible) continue;
        $moys = an_moyennes((int) $e['id_classe'], $p);
        if ($moys && max($moys) <= 0) $moys = [];   // aucune note saisie : pas un « 0/20 »
        $m = $moys[(int) $e['id']] ?? null;
        $rang = null;
        if ($m !== null) { $rang = 1; foreach ($moys as $v) if ($v > $m) $rang++; }
        $out[] = ['libelle' => $p['libelle'], 'moyenne' => $m, 'rang' => $rang, 'effectif' => count($moys),
                  'moy_classe' => $moys ? array_sum($moys) / count($moys) : null,
                  'mention' => $m !== null ? ep_mention($e, $p, $m) : '', 'detail' => $m !== null ? ep_detail_notes($e, $p) : []];
    }
    return $out;
}

/** Distinction de la période, mêmes règles que les bulletins : « Tableau d'honneur, Félicitations »… ('' si aucune). */
function ep_mention(array $e, array $p, float $m): string {
    $an = ed_annee();
    try {
        if (ed_secondaire()) {
            $nj = $p['cle'] === 'annee' ? 0 : (int) eleve_absence_trimestre((string) $e['matricule'], $p['id'], (int) $e['id_classe'], (string) $an['libelle'])['non_jus'];
            if (pv_felicitation($m, $nj) === 'Oui') return "Tableau d'honneur, Félicitations";
            if (pv_encouragement($m, $nj) === 'Oui') return "Tableau d'honneur, Encouragements";
            return pv_tableau_honneur($m, $nj) === 'Oui' ? "Tableau d'honneur" : '';
        }
        $nj = $p['cle'] === 'annee' ? 0.0 : jours_absence_non_justifiees_trimestre((int) $e['id'], (int) $e['id_classe'], $p['id'], (string) $an['cle']);
        $l = mention_travail($m, $nj)['libelle'];
        return str_starts_with($l, "Tableau d'honneur") && !str_contains($l, 'refusé') ? $l : '';
    } catch (\Throwable $ex) { return ''; }
}

/** Notes du trimestre par compétence (primaire) : [['libelle', 'note' sur 20]]. Secondaire : [] (le bulletin reste la référence). */
function ep_detail_notes(array $e, array $p): array {
    if (ed_secondaire() || $p['cle'] === 'annee') return [];
    $an = (string) ed_annee()['cle']; $out = [];
    try {
        foreach (competences_classe((int) $e['id_classe'], $an) as $c) {
            $n = note_competence_trimestre((int) $e['id'], (int) $c['id_comp'], (int) $e['id_classe'], $p['id'], $an)['moyenne'];
            if ($n === null || (float) $c['total_points'] <= 0) continue;
            $out[] = ['libelle' => trim((string) $c['nom_comp']), 'note' => round($n / (float) $c['total_points'] * 20, 2)];
        }
    } catch (\Throwable $ex) {}
    return $out;
}
