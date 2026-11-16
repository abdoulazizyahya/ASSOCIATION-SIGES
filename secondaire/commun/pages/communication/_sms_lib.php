<?php
// secondaire/commun/pages/communication/_sms_lib.php — modèles de SMS
// (français / anglais), envois automatiques, file d'attente et journal
// (10/11/2026), primaire et secondaire. L'envoi réel passe par la passerelle
// de l'école (_passerelle_lib.php : téléphone de l'école, forfait de sa puce).
require_once __DIR__ . '/_passerelle_lib.php';
require_once __DIR__ . '/../../../../noyau/partage/ecole_donnees.php';

/**
 * Catalogue : clé => [libellé, icône, automatisable ?, modèle FR, modèle EN].
 * Modèles sans ê, ô, î, â, ç (SMS de 160 caractères au lieu de 70).
 */
const SMS_TYPES = [
    'inscription' => ['Confirmation d\'inscription', 'person-check', true,
        "{ecole} : l'inscription de {eleve} en {classe} est enregistree pour l'annee {annee}. Merci de votre confiance.",
        "{ecole}: {eleve} is registered in {classe} for the {annee} school year. Thank you for your trust."],
    'paiement' => ['Paiement reçu + reste à payer', 'cash-coin', true,
        "{ecole} : nous avons recu {montant} FCFA pour {eleve} ({classe}) le {date}. Reste a payer : {reste} FCFA. Merci.",
        "{ecole}: we received {montant} FCFA for {eleve} ({classe}) on {date}. Balance due: {reste} FCFA. Thank you."],
    'relance' => ['Relance de paiement', 'exclamation-circle', true,   // automatique : tous les N jours (16/11/2026)
        "{ecole} : il reste {reste} FCFA a payer pour la scolarite de {eleve} ({classe}). Merci de regulariser rapidement.",
        "{ecole}: {reste} FCFA remain due for the school fees of {eleve} ({classe}). Please settle as soon as possible."],
    'bulletin' => ['Bulletin / moyenne du trimestre', 'mortarboard', false,
        "{ecole} : {eleve} ({classe}) a obtenu {moyenne}/20 au {trimestre}, rang {rang}/{effectif}. {appreciation}.",
        "{ecole}: {eleve} ({classe}) scored {moyenne}/20 in {trimestre}, ranked {rang}/{effectif}. {appreciation}."],
    'absence' => ['Absences non justifiées', 'calendar-x', false,
        "{ecole} : {eleve} ({classe}) totalise {absences} d'absence non justifiee au {trimestre}. Merci de nous contacter.",
        "{ecole}: {eleve} ({classe}) has {absences} of unexcused absence in {trimestre}. Please contact us."],
    'sortie' => ['Sortie de l\'établissement (billet)', 'box-arrow-right', true,
        "{ecole} : votre enfant {eleve} ({classe}) a quitte l'etablissement le {date} a {heure}. Motif : {motif}.",
        "{ecole}: your child {eleve} ({classe}) left the school on {date} at {heure}. Reason: {motif}."],
    'infirmerie' => ['Passage à l\'infirmerie (billet)', 'heart-pulse', true,
        "{ecole} : votre enfant {eleve} ({classe}) est passe(e) a l'infirmerie le {date} a {heure}. Motif : {motif}.",
        "{ecole}: your child {eleve} ({classe}) went to the infirmary on {date} at {heure}. Reason: {motif}."],
    'convocation' => ['Convocation des parents', 'megaphone', false,
        "{ecole} : les parents de {eleve} ({classe}) sont convoques le {date}. Merci de vous presenter a l'administration.",
        "{ecole}: the parents of {eleve} ({classe}) are invited to the school on {date}. Please report to the administration."],
    'reunion' => ['Réunion parents-enseignants', 'people', false,
        "{ecole} : reunion parents-enseignants le {date}. Votre presence est vivement souhaitee.",
        "{ecole}: parents-teachers meeting on {date}. Your attendance is strongly encouraged."],
    'examens' => ['Calendrier des examens', 'calendar-event', false,
        "{ecole} : les examens du {trimestre} commencent le {date}. Merci de veiller a la preparation de votre enfant.",
        "{ecole}: the {trimestre} exams start on {date}. Please help your child prepare."],
    'rentree' => ['Rentrée / reprise des cours', 'door-open', false,
        "{ecole} : la reprise des cours est fixee au {date}. Bonne rentree a tous !",
        "{ecole}: classes resume on {date}. Welcome back to all!"],
    'conges' => ['Congés / vacances', 'sun', false,
        "{ecole} : les cours seront suspendus du {date} pour les conges. Reprise annoncee prochainement.",
        "{ecole}: classes will be suspended from {date} for the holidays. Resumption date to be announced."],
    'felicitations' => ['Félicitations / tableau d\'honneur', 'trophy', false,
        "{ecole} : felicitations ! {eleve} ({classe}) est inscrit(e) au tableau d'honneur du {trimestre}.",
        "{ecole}: congratulations! {eleve} ({classe}) is on the {trimestre} honour roll."],
    'urgence' => ['Message d\'urgence', 'exclamation-triangle', false,
        "{ecole} - URGENT : ",
        "{ecole} - URGENT: "],
    'communique' => ['Communiqué libre', 'chat-text', false,
        "{ecole} : Chers parents, ",
        "{ecole}: Dear parents, "],
];

/** Modèles de l'école (texte personnalisé, actif, envoi automatique) fusionnés avec le catalogue. */
function sms_modeles(): array {
    $perso = ed_reglages('sms_modeles', []);
    $out = [];
    foreach (SMS_TYPES as $k => [$lib, $ic, $autom, $fr, $en]) {
        $p = $perso[$k] ?? [];
        $out[$k] = ['libelle' => $lib, 'icone' => $ic, 'automatisable' => $autom,
                    'fr' => (string) ($p['fr'] ?? $fr), 'en' => (string) ($p['en'] ?? $en),
                    'auto' => $autom && !empty($p['auto'])];
    }
    return $out;
}

/** Langue des messages d'un élève : anglais pour une école anglophone ou une classe de la section anglophone. */
function sms_langue(int $id_classe): string {
    $l = function_exists('langue_enseignement_courante') ? langue_enseignement_courante() : 'francophone';
    if ($l === 'anglophone') return 'en';
    if ($l !== 'bilingue' || !$id_classe) return 'fr';
    try {
        if (ed_secondaire()) return sec_langue_classe($id_classe) === 'en' ? 'en' : 'fr';
        return primaire_langue_classe($id_classe) === 'en' ? 'en' : 'fr';
    } catch (\Throwable $e) { return 'fr'; }
}

/** Remplace les variables d'un modèle. */
function sms_rendre(string $modele, array $vars): string {
    $etab = etab_pour_pdf(get_etablissement());
    $base = ['{ecole}' => trim((string) (($etab['sigle'] ?? '') ?: ($etab['nom_fr'] ?? APP_NOM))), '{annee}' => ed_annee()['libelle'], '{date}' => date('d/m/Y')];
    $v = [];
    foreach ($vars as $c => $val) $v['{' . trim($c, '{}') . '}'] = (string) $val;
    return trim(preg_replace('/\s{2,}/', ' ', strtr($modele, $v + $base)));
}

/**
 * Montants dus / payés / restants des élèves donnés, quel que soit le système
 * de frais de l'école : primaire (obligation + paiement_frais), secondaire
 * public (obligation_frais + paiement_frais) et secondaire privé
 * (obligation_privee + paiement_prive) — additionnés quand l'école utilise
 * les deux. Retour : [id_eleve => ['du', 'paye', 'reste']].
 */
function sms_finances_lot(array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    $out = array_fill_keys($ids, ['du' => 0.0, 'paye' => 0.0, 'reste' => 0.0]);
    if (!$ids) return $out;
    $an = ed_annee(); $in = implode(',', $ids);
    $ajout = function (int $id, float $du, float $paye) use (&$out) {
        if (!isset($out[$id])) return;
        $out[$id]['du'] += $du; $out[$id]['paye'] += $paye; $out[$id]['reste'] += max(0.0, $du - $paye);
    };
    try {
        if (!ed_secondaire()) {
            $paye = [];
            foreach (db_all("SELECT id_eleve, SUM(montant_paiement) p FROM paiement_frais WHERE val_annee=? AND id_eleve IN ($in) GROUP BY id_eleve", [$an['cle']]) as $r) $paye[(int) $r['id_eleve']] = (float) $r['p'];
            foreach (finances_du_par_eleve((string) $an['cle']) as $e) if (isset($out[(int) $e['id_eleve']])) $ajout((int) $e['id_eleve'], (float) $e['du'], $paye[(int) $e['id_eleve']] ?? 0.0);
            return $out;
        }
        // Secondaire public : obligations par portée (établissement, cycle, niveau).
        if ((int) db_val("SELECT COUNT(*) FROM obligation_frais WHERE id_annee=? AND actif=1", [$an['cle']]) > 0) {
            foreach (db_all("SELECT e.id, c.code_niveau FROM eleve e JOIN inscription i ON i.id_eleve=e.id AND i.id_annee=? JOIN classe c ON c.id=i.id_classe WHERE e.id IN ($in)", [$an['cle']]) as $e) {
                $du = $p = 0.0;
                foreach (eleve_obligations_annee((int) $e['id'], (string) $e['code_niveau'], (int) $an['cle']) as $o) { $du += (float) $o['montant']; $p += (float) $o['paye']; }
                $ajout((int) $e['id'], $du, $p);
            }
        }
        // Secondaire privé.
        if ((int) db_val("SELECT COUNT(*) FROM obligation_privee") > 0) {
            $paye = [];
            foreach (db_all("SELECT id_eleve, SUM(montant_paiement) p FROM paiement_prive WHERE id_annee=? AND id_eleve IN ($in) GROUP BY id_eleve", [$an['cle']]) as $r) $paye[(int) $r['id_eleve']] = (float) $r['p'];
            foreach (prive_finances_du_par_eleve((int) $an['cle']) as $e) if (isset($out[(int) $e['id']])) $ajout((int) $e['id'], (float) $e['du'], $paye[(int) $e['id']] ?? 0.0);
        }
    } catch (\Throwable $e) { /* module de frais absent */ }
    return $out;
}

// ── Journal et file d'attente ─────────────────────────────────────────────

function sms_assurer_journal(): void {
    static $fait = false;
    if ($fait) return;
    try {
        db_exec("CREATE TABLE IF NOT EXISTS `sms_journal` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `cree_le` datetime NOT NULL,
            `envoye_le` datetime DEFAULT NULL,
            `type` varchar(30) NOT NULL,
            `mode` varchar(15) NOT NULL DEFAULT 'auto',
            `id_eleve` int unsigned DEFAULT NULL,
            `nom` varchar(150) DEFAULT NULL,
            `tel` varchar(20) NOT NULL,
            `texte` text NOT NULL,
            `statut` varchar(12) NOT NULL DEFAULT 'attente',
            `erreur` varchar(255) DEFAULT NULL,
            `essais` tinyint unsigned NOT NULL DEFAULT 0,
            `auteur` varchar(80) DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `k_sms_statut` (`statut`),
            KEY `k_sms_date` (`cree_le`),
            KEY `k_sms_eleve` (`id_eleve`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (\Throwable $e) { /* lecture seule */ }
    $fait = true;
}

/** Inscrit un message au journal ; retourne son id. */
function sms_journaliser(string $type, string $mode, ?int $id_eleve, string $nom, string $tel, string $texte, string $statut, string $erreur = ''): int {
    sms_assurer_journal();
    db_exec("INSERT INTO sms_journal (cree_le, envoye_le, type, mode, id_eleve, nom, tel, texte, statut, erreur, essais, auteur)
             VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [$statut === 'envoye' ? date('Y-m-d H:i:s') : null, $type, $mode, $id_eleve, mb_substr($nom, 0, 150), $tel, $texte, $statut,
         $erreur !== '' ? mb_substr($erreur, 0, 255) : null, $statut === 'attente' ? 0 : 1, (string) ($_SESSION['user']['login'] ?? '')]);
    return (int) db_last_id();
}

/**
 * Envoie un SMS par la passerelle et l'inscrit au journal. Passerelle absente
 * ou injoignable : le message reste EN ATTENTE (relancé depuis le journal).
 */
function sms_envoyer(string $type, ?int $id_eleve, string $nom, string $tel, string $texte, string $mode = 'auto'): array {
    if (!sms_gw_actif()) {
        sms_journaliser($type, $mode, $id_eleve, $nom, $tel, $texte, 'attente', 'Passerelle SMS non configurée');
        return ['ok' => false, 'attente' => true, 'erreur' => 'Passerelle SMS non configurée : message mis en attente.'];
    }
    $r = sms_gw_envoyer($texte, [$tel]);
    $reseau = !$r['ok'] && preg_match('/HTTP 0|connexion|timed out|resolve/i', $r['erreur']);
    sms_journaliser($type, $mode, $id_eleve, $nom, $tel, $texte, $r['ok'] ? 'envoye' : ($reseau ? 'attente' : 'echec'), $r['erreur']);
    return $r + ['attente' => $reseau];
}

/** Renvoie les messages en attente (au plus $max). Retour : [envoyés, échecs, restants]. */
function sms_relancer_attente(int $max = 40): array {
    sms_assurer_journal();
    $ok = $ko = 0;
    if (!sms_gw_actif()) return [0, 0, (int) db_val("SELECT COUNT(*) FROM sms_journal WHERE statut='attente'")];
    foreach (db_all("SELECT * FROM sms_journal WHERE statut='attente' ORDER BY id LIMIT " . (int) $max) as $m) {
        $r = sms_gw_envoyer((string) $m['texte'], [(string) $m['tel']]);
        $reseau = !$r['ok'] && preg_match('/HTTP 0|connexion|timed out|resolve/i', $r['erreur']);
        db_exec("UPDATE sms_journal SET statut=?, erreur=?, essais=essais+1, envoye_le=? WHERE id=?",
            [$r['ok'] ? 'envoye' : ($reseau ? 'attente' : 'echec'), $r['ok'] ? null : mb_substr($r['erreur'], 0, 255), $r['ok'] ? date('Y-m-d H:i:s') : null, $m['id']]);
        $r['ok'] ? $ok++ : $ko++;
        if ($reseau) break;   // réseau coupé : inutile d'insister
    }
    return [$ok, $ko, (int) db_val("SELECT COUNT(*) FROM sms_journal WHERE statut='attente'")];
}

/**
 * Envoi AUTOMATIQUE lié à un évènement (inscription, paiement, sortie,
 * infirmerie) : seulement si le modèle est coché « automatique » et que
 * l'élève a un numéro. Silencieux : n'interrompt jamais l'action en cours.
 * Retour : message court pour l'utilisateur, ou ''.
 */
function sms_auto(string $type, int $id_eleve, array $vars = []): string {
    try {
        $mods = sms_modeles();
        if (empty($mods[$type]['auto'])) return '';
        $e = ed_eleve($id_eleve);
        if (!$e) return '';
        $tels = array_column(contacts_parents_lot([$id_eleve])[$id_eleve] ?? [], 'tel');
        if (!$tels) return '';
        if ($type === 'paiement' && !isset($vars['reste'])) {
            $f = sms_finances_lot([$id_eleve])[$id_eleve] ?? ['reste' => 0];
            $vars['reste'] = number_format((float) $f['reste'], 0, ',', ' ');
        }
        $texte = sms_rendre($mods[$type][sms_langue((int) $e['id_classe'])], $vars + ['eleve' => ed_nom($e), 'classe' => $e['classe']]);
        $r = sms_envoyer($type, $id_eleve, ed_nom($e), $tels[0], $texte, 'auto');
        return $r['ok'] ? ' SMS envoyé au parent (' . tel_affiche($tels[0]) . ').' : (!empty($r['attente']) ? ' SMS au parent mis en attente.' : ' SMS au parent non envoyé.');
    } catch (\Throwable $ex) { return ''; }
}

// ── Relance automatique des impayés (16/11/2026) ──────────────────────────
//  Si le modèle « Relance de paiement » est coché automatique : tous les
//  `jours` jours, un SMS à un parent de chaque élève dont le reste à payer
//  atteint `seuil` FCFA. Seulement quand la passerelle est configurée (sinon
//  des centaines de messages s'empileraient en attente). Lancée après
//  l'affichage d'une page de l'école (noyau/layout/footer.php) ou à la main.

const SMS_RELANCE_DEFAUT = ['jours' => 30, 'seuil' => 1000, 'dernier' => ''];
const SMS_RELANCE_MAX = 400;   // messages au plus par passage

function sms_relance_reglages(): array { return ed_reglages('sms_relance', SMS_RELANCE_DEFAUT); }

/** Date de la prochaine relance automatique (Y-m-d), ou null si désactivée. */
function sms_relance_prochaine(): ?string {
    if (empty(sms_modeles()['relance']['auto'])) return null;
    $r = sms_relance_reglages();
    if ($r['dernier'] === '') return date('Y-m-d');
    return date('Y-m-d', strtotime($r['dernier'] . ' +' . max(1, (int) $r['jours']) . ' days'));
}

function sms_relance_due(): bool {
    $p = sms_relance_prochaine();
    return $p !== null && $p <= date('Y-m-d') && sms_gw_actif();
}

/** Élèves à relancer : [['id','nom','classe','id_classe','reste','tel']]. */
function sms_relance_cibles(float $seuil): array {
    $eleves = ed_eleves();
    if (!$eleves) return [];
    $fin = sms_finances_lot(array_column($eleves, 'id'));
    $dus = array_filter($eleves, fn($e) => ($fin[(int) $e['id']]['reste'] ?? 0) >= max(1.0, $seuil));
    $contacts = $dus ? contacts_parents_lot(array_column($dus, 'id')) : [];
    $out = [];
    foreach ($dus as $e) {
        $tels = array_column($contacts[(int) $e['id']] ?? [], 'tel');
        if (!$tels) continue;
        $out[] = ['id' => (int) $e['id'], 'nom' => ed_nom($e), 'classe' => $e['classe'], 'id_classe' => (int) $e['id_classe'],
                  'reste' => $fin[(int) $e['id']]['reste'], 'tel' => $tels[0]];
    }
    return $out;
}

/** Envoie la relance. Retour : ['envoyes', 'attente', 'echecs', 'cibles']. */
function sms_relance_executer(string $mode = 'auto'): array {
    $reg = sms_relance_reglages();
    // Date notée AVANT l'envoi : deux pages ouvertes en même temps ne relancent pas deux fois.
    ed_reglages_enregistrer('sms_relance', ['dernier' => date('Y-m-d')] + $reg);
    $mods = sms_modeles();
    $res = ['envoyes' => 0, 'attente' => 0, 'echecs' => 0, 'cibles' => 0];
    @set_time_limit(600);
    foreach (array_slice(sms_relance_cibles((float) $reg['seuil']), 0, SMS_RELANCE_MAX) as $c) {
        $res['cibles']++;
        $texte = sms_rendre($mods['relance'][sms_langue($c['id_classe'])],
                            ['eleve' => $c['nom'], 'classe' => $c['classe'], 'reste' => number_format($c['reste'], 0, ',', ' ')]);
        $r = sms_envoyer('relance', $c['id'], $c['nom'], $c['tel'], $texte, $mode);
        $r['ok'] ? $res['envoyes']++ : (!empty($r['attente']) ? $res['attente']++ : $res['echecs']++);
        if (!empty($r['attente'])) break;   // passerelle injoignable : le reste attendra le prochain passage
    }
    return $res;
}

/** Appelée en bas de chaque page de l'école : relance après l'affichage, si elle est due. */
function sms_relance_planifier(): void {
    static $fait = false;
    if ($fait || !function_exists('fastcgi_finish_request')) return;   // sans PHP-FPM : bouton « Relancer maintenant »
    $fait = true;
    try { if (!sms_relance_due()) return; } catch (\Throwable $e) { return; }
    register_shutdown_function(function () {
        fastcgi_finish_request();
        try {
            $r = sms_relance_executer('auto');
            if (function_exists('journaliser_action')) journaliser_action('sms_relance_auto', null, "{$r['envoyes']} envoyé(s), {$r['attente']} en attente, {$r['echecs']} échec(s)");
        } catch (\Throwable $e) { /* jamais d'erreur visible */ }
    });
}
