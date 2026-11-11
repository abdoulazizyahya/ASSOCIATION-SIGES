<?php
// secondaire/commun/pages/communication/envoyer_passerelle.php — envoi
// AUTOMATIQUE d'un paquet de SMS par la passerelle (téléphone de l'école),
// appelé en JavaScript par envoyer.php (« Envoyer à la sélection »), par
// paquets de 10 au plus pour ne jamais dépasser le délai d'une requête.
// Entrée (POST) : csrf, items = JSON [{k, tel, texte}, …]. Les messages
// identiques du paquet partent en UNE requête (plusieurs numéros).
// Sortie JSON : {ok, resultats: {k: {ok, erreur}}}.
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
require_once __DIR__ . '/_passerelle_lib.php';
require_once __DIR__ . '/_sms_lib.php';

header('Content-Type: application/json; charset=utf-8');
$secondaire = type_enseignement_courant() === 'secondaire';
exiger_role($secondaire ? ['ADMIN', 'PROVISEUR', 'FONDATEUR', 'CENSEUR', 'SG', 'SECRETAIRE', 'INTENDANT'] : ['DIRECTEUR', 'SECRETAIRE', 'COMPTABLE', 'FONDATEUR']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'erreur' => 'Méthode non autorisée.']); exit; }
csrf_verifier();
if (!sms_gw_actif()) { echo json_encode(['ok' => false, 'erreur' => 'Passerelle SMS non configurée ou désactivée.']); exit; }

$items = json_decode((string) ($_POST['items'] ?? '[]'), true);
if (!is_array($items) || !$items) { echo json_encode(['ok' => false, 'erreur' => 'Aucun message.']); exit; }
$items = array_slice($items, 0, 10);

// Regroupement : un même texte → un seul appel avec plusieurs numéros.
$groupes = [];
foreach ($items as $it) {
    $k = (string) ($it['k'] ?? '');
    $tel = tel_normaliser_cm((string) ($it['tel'] ?? ''));
    $texte = trim((string) ($it['texte'] ?? ''));
    if ($k === '' || $tel === null || $texte === '') { $res[$k] = ['ok' => false, 'erreur' => 'Numéro ou texte invalide.']; continue; }
    $texte = mb_substr($texte, 0, 918);   // 6 SMS au plus
    $groupes[$texte][] = ['k' => $k, 'tel' => $tel, 'nom' => mb_substr((string) ($it['nom'] ?? ''), 0, 150)];
}
$res = $res ?? [];
$nb_ok = 0; $nb_ko = 0;
foreach ($groupes as $texte => $dest) {
    $r = sms_gw_envoyer($texte, array_column($dest, 'tel'));
    foreach ($dest as $d) {
        $res[$d['k']] = ['ok' => $r['ok'], 'erreur' => $r['erreur']];
        sms_journaliser(array_key_exists((string) post('type'), SMS_TYPES) ? post('type') : 'communique', 'passerelle', null, $d['nom'], $d['tel'], $texte, $r['ok'] ? 'envoye' : 'echec', $r['erreur']);
        $r['ok'] ? $nb_ok++ : $nb_ko++;
    }
}
journaliser_action('sms_passerelle_envoi', null, $nb_ok . ' envoyé(s)' . ($nb_ko ? ', ' . $nb_ko . ' échec(s)' : ''));
echo json_encode(['ok' => true, 'resultats' => $res], JSON_UNESCAPED_UNICODE);
