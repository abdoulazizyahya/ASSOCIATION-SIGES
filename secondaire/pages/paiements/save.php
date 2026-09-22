<?php
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_role(['ADMIN','PROVISEUR', 'FONDATEUR','CENSEUR','INTENDANT']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rediriger('secondaire/pages/paiements/index.php');
}
csrf_verifier();

$id_eleve  = (int)post('id_eleve');
$id_classe = (int)post('id_classe');
$annee     = get_annee_active();
$id_annee  = (int)($annee['id'] ?? 0);
$date_paiement = post('date_paiement');
$obligations_cochees = array_map('intval', $_POST['obligations'] ?? []);
$operateurs_postes    = $_POST['operateur'] ?? [];
$refs_postees         = $_POST['ref'] ?? [];

$retour = "secondaire/pages/paiements/index.php?classe=$id_classe&eleve=$id_eleve";

$eleve  = db_one("SELECT * FROM eleve WHERE id=?", [$id_eleve]);
$classe = db_one("SELECT * FROM classe WHERE id=?", [$id_classe]);
if (!$eleve || !$classe || !$id_annee || !$date_paiement || !$obligations_cochees) {
    flash_set('erreur', 'Aucun frais sélectionné ou données invalides.');
    rediriger($retour);
}
$id_cycle_classe = db_val("SELECT id_cycle FROM niveau WHERE code_niveau = ?", [$classe['code_niveau']]);

$nb_ok = 0; $nb_skip = 0; $recus = [];
// Un seul numéro de reçu pour toute la soumission (tous les frais cochés
// dans ce même clic sur "Enregistrer" partagent le même numero_recu, pour
// permettre un reçu/récapitulatif groupé) — généré seulement à la première
// insertion qui aboutit réellement, pour ne pas gaspiller de numéro si tout
// est skip (déjà soldé ou canal de paiement invalide).
$numero_recu = null;

foreach ($obligations_cochees as $id_obligation) {
    $obligation = db_one("SELECT * FROM obligation_frais WHERE id=? AND actif=1", [$id_obligation]);
    $portee_ok = $obligation && (
        $obligation['portee'] === 'etablissement'
        || ($obligation['portee'] === 'cycle'  && $obligation['id_cycle']   === $id_cycle_classe)
        || ($obligation['portee'] === 'niveau' && $obligation['code_niveau'] === $classe['code_niveau'])
    );
    if (!$portee_ok || (int)$obligation['id_annee'] !== $id_annee) {
        $nb_skip++; continue;
    }

    $solde = eleve_solde_obligation($id_eleve, $id_obligation, $id_annee);
    if ($solde <= 0) { $nb_skip++; continue; } // déjà soldé entre-temps

    if ($obligation['mode_paiement'] === 'cash') {
        $id_operateur = 'CASH';
        $ref_paiement = null;
    } else {
        $id_operateur = trim($operateurs_postes[$id_obligation] ?? '');
        $ref_paiement = trim($refs_postees[$id_obligation] ?? '') ?: null;
        $operateur_valide = $id_operateur !== 'CASH'
            && db_val("SELECT COUNT(*) FROM operateur_paiement WHERE id=?", [$id_operateur]);
        if (!$operateur_valide) { $nb_skip++; continue; }
    }

    if ($numero_recu === null) { $numero_recu = generer_numero_recu($id_annee); }
    db_exec(
        "INSERT INTO paiement_frais (id_eleve, id_classe, id_annee, id_obligation, id_operateur, montant, ref_paiement, date_paiement, id_utilisateur, numero_recu)
         VALUES (?,?,?,?,?,?,?,?,?,?)",
        [$id_eleve, $id_classe, $id_annee, $id_obligation, $id_operateur, $solde, $ref_paiement, $date_paiement, $_SESSION['user_id'] ?? null, $numero_recu]
    );
    $nb_ok++;
    $recus[] = $numero_recu;
}

if ($nb_ok > 0) {
    $msg = "$nb_ok paiement(s) enregistré(s) — reçu n° " . implode(', ', array_unique($recus)) . '.';
    if ($nb_skip > 0) $msg .= " $nb_skip frais ignoré(s) (déjà soldé ou canal de paiement invalide).";
    flash_set('succes', $msg);
} else {
    flash_set('erreur', "Aucun paiement enregistré ($nb_skip frais ignoré(s) — déjà soldé ou canal de paiement invalide).");
}
rediriger($retour);
