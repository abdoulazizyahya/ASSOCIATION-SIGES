<?php
/**
 * Export EN LOT des dossiers administratifs des enseignants.
 * Un dossier complet par enseignant, concaténés dans un seul PDF.
 *
 * GET (tous facultatifs, cumulables) :
 *   q      = recherche nom / prénom / matricule (même logique que la liste)
 *   region = filtre exact sur region_origine
 *   dep    = filtre exact sur departement_origine
 *   dl     = 1 pour forcer le téléchargement
 *
 * Le rendu de chaque dossier est délégué à _dossier_lib.php (identique à
 * l'export unitaire pdf_dossier.php).
 */
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_connexion();

$role = role_connecte();
if (!in_array($role, ['ADMIN','PROVISEUR','CENSEUR'])) die('Accès refusé.');

$q      = trim($_GET['q'] ?? '');
$region = trim($_GET['region'] ?? '');
$dep    = trim($_GET['dep'] ?? '');
$dl     = ($_GET['dl'] ?? '0') === '1';

// ── Construction du filtre (requête préparée) ────────────────────────
$conds = []; $params = [];
if ($q !== '') {
    $conds[] = "(nom_ens LIKE ? OR prenom_ens LIKE ? OR matricule_ens LIKE ?)";
    array_push($params, "%$q%", "%$q%", "%$q%");
}
if ($region !== '') { $conds[] = "region_origine = ?";      $params[] = $region; }
if ($dep !== '')    { $conds[] = "departement_origine = ?"; $params[] = $dep; }
$where = $conds ? ('WHERE '.implode(' AND ', $conds)) : '';

$enseignants = db_all(
    "SELECT * FROM enseignant $where ORDER BY nom_ens, prenom_ens",
    $params
);

if (empty($enseignants)) {
    die('Aucun enseignant ne correspond aux critères sélectionnés.');
}

$etab      = get_etablissement();
$annee_act = get_annee_active();
$val_annee = $annee_act['libelle'] ?? '';

require_once __DIR__ . '/../../pdf/fpdf.php';
require_once __DIR__ . '/../../pdf/header_pdf.php'; // pour pdf_filigrane()
require_once __DIR__ . '/_dossier_lib.php';

// Enveloppé dans un try/catch — voir fonctions.php::pdf_erreur_generation()
try {
$pdf = new FPDF_Dossier('P','mm','A4');
$pdf->etab = $etab;
$pdf->AliasNbPages();
$pdf->SetMargins(DOSSIER_ML, 10, DOSSIER_ML);
$pdf->SetAutoPageBreak(true, 16);

// Un dossier complet par enseignant (chacun démarre sur une nouvelle page).
foreach ($enseignants as $e) {
    dossier_render_one($pdf, $e, $etab, $val_annee);
}

// Nom de fichier explicite selon le filtre appliqué.
$suffixe = 'tous';
if ($dep !== '')         $suffixe = 'dep_'.preg_replace('/\W/', '_', $dep);
elseif ($region !== '')  $suffixe = 'region_'.preg_replace('/\W/', '_', $region);
elseif ($q !== '')       $suffixe = 'recherche';
$nb = count($enseignants);

$pdf->Output($dl ? 'D' : 'I', "dossiers_enseignants_{$suffixe}_{$nb}.pdf");
} catch (Throwable $e) {
    pdf_erreur_generation($e);
}
