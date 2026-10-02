<?php
// pdf/bulletin_paie.php — Bulletin de paie PDF au format CNPS camerounais,
// mise en page reprise du modèle fourni par l'utilisateur
// (BD JAYNITARE/modele/salaire.pdf, style CAMTEL) — migration v35 :
// un seul tableau Code/Rubrique/Nb/Base/Taux %/Gain/Retenue (plutôt que
// deux blocs Gains/Retenues séparés comme avant), en-tête administratif
// complet (matricule CNPS, banque, indice de grille, personnes à charge,
// ancienneté), montant en toutes lettres, bloc "Éléments de présence &
// rubriques indicatives" (cotisations patronales, si saisies).
//
// ⚠️ Les colonnes du modèle sans équivalent dans ce schéma (Direction,
// Mat. CNPS employeur, Ind.fonctionnaire — vides même dans l'exemple
// fourni) sont omises plutôt que remplies de valeurs inventées : les
// montants de cotisations CNPS/IRPP/taxes ne sont JAMAIS calculés
// automatiquement ici (barèmes légaux non fournis/confirmés — risque
// réel de bulletin faux) — seules les lignes explicitement saisies par
// le Directeur (voir pages/paie/periode.php, ligne Gain/Retenue avec
// code/base/taux % optionnels) apparaissent dans le tableau.
//
// Accès : DIRECTEUR (tout bulletin) ou l'enseignant concerné lui-même
// (même règle que pages/paie/bulletin.php).
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
require_once __DIR__ . '/../paie_fonctions.php';
exiger_connexion();

require_once __DIR__ . '/fpdf.php';
require_once __DIR__ . '/header_pdf.php';
require_once __DIR__ . '/bulletin_paie_lib.php';

$id = (int) ($_GET['id'] ?? 0);
$dl = ($_GET['dl'] ?? '0') === '1';
$bulletin = bulletin_paie_charger($id);
if (!$bulletin) die('Bulletin introuvable.');

$role = role_connecte();
$mon_matricule = (int) (utilisateur_connecte()['matricule_ens'] ?? 0);
// Mêmes rôles que pages/paie/periode.php (02/10/2026), ou l'agent lui-même.
if (!in_array($role, ['DIRECTEUR', 'FONDATEUR', 'COMPTABLE'], true) && $mon_matricule !== (int) $bulletin['matricule_ens']) {
    die('Accès refusé.');
}


$etab_brut = get_etablissement();
$etab      = etab_pour_pdf($etab_brut);

// Enveloppé dans un try/catch — voir fonctions.php::pdf_erreur_generation()
// (jamais de fatal error brut ; ce document est public via QR et/ou
// consulté par du personnel qui ne doit pas voir de trace technique).
try {
$pdf = new FPDF('P', 'mm', 'A4');
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 15);
bulletin_paie_page($pdf, $bulletin, $id, $etab);

$nom_fichier = numero_bulletin($id);
$pdf->Output($dl ? 'D' : 'I', $nom_fichier . '.pdf');
} catch (Throwable $e) {
    pdf_erreur_generation($e);
}
