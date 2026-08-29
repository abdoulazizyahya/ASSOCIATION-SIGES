<?php
// ── PDF : Relevé de notes d'une classe pour un trimestre ──────────
// Tableau matriciel élèves × compétences (moyenne trimestrielle de chaque
// compétence), moyenne générale, rang, et — si le conseil de classe a déjà
// statué — décision/observation (decision_conseil, bd/migration_v6.sql).
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
require_once __DIR__ . '/../notes_apc.php';
exiger_acces_pedagogie();

require_once __DIR__ . '/fpdf.php';
require_once __DIR__ . '/header_pdf.php';

$id_classe = (int) ($_GET['classe'] ?? 0);
$id_trim   = (int) ($_GET['trim'] ?? 0);
$ordre     = ($_GET['ordre'] ?? 'merite') === 'alpha' ? 'alpha' : 'merite';
$dl        = ($_GET['dl'] ?? '0') === '1';
if (!$id_classe || !$id_trim) die('Paramètres classe/trim manquants.');

$classe = db_one("SELECT * FROM classe WHERE IDClasses=?", [$id_classe]);
if (!$classe) die('Classe introuvable.');
$trimestre = db_one("SELECT * FROM trimestre WHERE id_trim=?", [$id_trim]);
if (!$trimestre) die('Trimestre introuvable.');

$annee     = get_annee_active();
$val_annee = $annee['val_annee'] ?? '';
$etab_brut = get_etablissement();
$etab      = etab_pour_pdf($etab_brut);

// Préchargement en masse (optimisation, voir notes_apc.php) : évite les
// requêtes individuelles que note_competence_trimestre() ferait sinon pour
// chaque (élève × compétence) de la classe — même principe que
// pdf/bulletin_trimestriel.php.
precharger_notes_sequence_classe($id_classe, $val_annee);

$competences = competences_classe($id_classe, $val_annee);
// Section anglophone (même convention que pdf/bulletin_trimestriel.php) :
// seule la légende des compétences bascule en anglais — saisie/calcul
// restent sur le jeu langue='Fr' dans tous les cas (competences_classe()).
// Section rattachée au NIVEAU, pas à la classe (migration_v42).
$section_en = db_val("SELECT n.Section FROM niveau n WHERE n.LibelleNiveau=?", [$classe['Niveau']]) === 'An';
$classement  = classement_trimestre_classe($id_classe, $id_trim, $val_annee);
// $classement['lignes'] est déjà trié au mérite (moyenne décroissante) —
// le rang affiché reste TOUJOURS celui du mérite, seul l'ordre d'AFFICHAGE
// change en 'alpha' (même convention que resultat_annuel_lignes_classe()).
if ($ordre === 'alpha') {
    usort($classement['lignes'], fn($a, $b) => strcmp($a['Nom_elv'] . ' ' . ($a['Prenom_elv'] ?? ''), $b['Nom_elv'] . ' ' . ($b['Prenom_elv'] ?? '')));
}
$decisions   = db_all("SELECT id_eleve, decision, observation FROM decision_conseil WHERE classe=? AND id_trim=? AND val_annee=?", [$id_classe, $id_trim, $val_annee]);
$dec_idx = [];
foreach ($decisions as $d) { $dec_idx[(int) $d['id_eleve']] = $d; }

// Enveloppé dans un try/catch — voir fonctions.php::pdf_erreur_generation()
// (jamais de fatal error brut ; ce document est public via QR et/ou
// consulté par du personnel qui ne doit pas voir de trace technique).
try {
$pdf = new FPDF('L', 'mm', 'A4'); // Paysage : beaucoup de colonnes
$pdf->SetMargins(8, 8, 8);
$pdf->SetAutoPageBreak(true, 12);
$pdf->AddPage();
$pw = $pdf->GetPageWidth();
$ph = $pdf->GetPageHeight();
$uw = $pw - 16;

pdf_filigrane($pdf, $etab, $pw, $ph);
pdf_entete($pdf, $etab, $pw, 8, null, 0.85);
pdf_bandeau($pdf, 'RELEVÉ DE NOTES', 'GRADE SHEET', $pw, 8);

$pdf->SetFont('Arial', 'B', 8.5);
$pdf->SetX(8);
$pdf->Cell($uw, 4.5, pdf_u('Classe : ' . $classe['DesignationClasses'] . '   —   ' . $trimestre['libelle_trim'] . '   —   Année : ' . $val_annee), 0, 1, 'C');
$pdf->Ln(2);

// ── Colonnes : N° / Élève / une par compétence (code) / Moy. / Rang / Décision ──
$w_no = 8; $w_nom = 42; $w_moy = 14; $w_rang = 12; $w_dec = 20;
$w_reste = $uw - $w_no - $w_nom - $w_moy - $w_rang - $w_dec;
$nb_comp = count($competences);
$w_comp = $nb_comp > 0 ? $w_reste / $nb_comp : 0;

$pdf->SetX(8);
$pdf->SetFont('Arial', 'B', 6.5);
$pdf->SetFillColor(30, 79, 216); $pdf->SetTextColor(255, 255, 255);
$pdf->Cell($w_no, 14, pdf_u('N°'), 1, 0, 'C', true);
$pdf->Cell($w_nom, 14, pdf_u('Nom et prénoms'), 1, 0, 'C', true);
foreach ($competences as $c) {
    $pdf->Cell($w_comp, 14, pdf_u($c['code_comp']), 1, 0, 'C', true);
}
$pdf->Cell($w_moy, 14, pdf_u('Moy.'), 1, 0, 'C', true);
$pdf->Cell($w_rang, 14, 'Rang', 1, 0, 'C', true);
$pdf->Cell($w_dec, 14, pdf_u('Décision'), 1, 1, 'C', true);
$pdf->SetTextColor(0);

// Légende des codes de compétence (pour ne pas répéter les libellés complets
// sur chaque colonne — trop étroit) : imprimée juste après l'entête.
$pdf->SetFont('Arial', '', 6);
$pdf->SetX(8);
$legende = [];
foreach ($competences as $c) {
    $nom_aff = ($section_en && !empty($c['nom_comp_en'])) ? $c['nom_comp_en'] : $c['nom_comp'];
    $legende[] = $c['code_comp'] . '=' . mb_strimwidth($nom_aff, 0, 22, '…');
}
$pdf->MultiCell($uw, 3, pdf_u(implode('  |  ', $legende)), 0, 'L');
$pdf->Ln(1);

$pdf->SetFont('Arial', '', 7);
$no = 1;
$fill = false;
foreach ($classement['lignes'] as $l) {
    if ($pdf->GetY() > $ph - 15) {
        $pdf->AddPage();
        pdf_filigrane($pdf, $etab, $pw, $ph);
    }
    $id_eleve = (int) $l['id_eleve'];
    $pdf->SetX(8);
    $pdf->SetFillColor(234, 244, 251);
    $pdf->Cell($w_no, 5.5, (string) $no, 1, 0, 'C', $fill);
    $pdf->Cell($w_nom, 5.5, pdf_u(mb_strimwidth(mb_strtoupper($l['Nom_elv']) . ' ' . ($l['Prenom_elv'] ?? ''), 0, 30, '…')), 1, 0, 'L', $fill);
    foreach ($competences as $c) {
        $note = note_competence_trimestre($id_eleve, (int) $c['id_comp'], $id_classe, $id_trim, $val_annee);
        $val  = $note['moyenne'] !== null ? number_format($note['moyenne'], 1) : '—';
        $pdf->Cell($w_comp, 5.5, pdf_u($val), 1, 0, 'C', $fill);
    }
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->Cell($w_moy, 5.5, pdf_u($l['moy'] !== null ? number_format((float) $l['moy'], 2) : '—'), 1, 0, 'C', $fill);
    $pdf->SetFont('Arial', '', 7);
    $pdf->Cell($w_rang, 5.5, pdf_u((string) $l['rang']), 1, 0, 'C', $fill);
    $dec = $dec_idx[$id_eleve]['decision'] ?? '';
    $pdf->Cell($w_dec, 5.5, pdf_u($dec ?: '—'), 1, 1, 'C', $fill);
    $no++; $fill = !$fill;
}

$pdf->Ln(6);
if ($pdf->GetY() > $ph - 20) { $pdf->AddPage(); pdf_filigrane($pdf, $etab, $pw, $ph); }
$w_sign = $uw * 0.35;
$x_sign = $pw - 8 - $w_sign;
$pdf->SetFont('Arial', '', 8);
$pdf->SetXY($x_sign, $pdf->GetY());
$pdf->Cell($w_sign, 4.5, pdf_u('Fait à ' . (($etab['lieu'] ?: $etab['ville']) ?: '') . ', le ' . date('d/m/Y')), 0, 1, 'R');
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetX($x_sign);
$pdf->Cell($w_sign, 4.5, pdf_u(mb_strtoupper($etab['chef_etablissement'] ?: 'LE DIRECTEUR') . ','), 0, 1, 'R');

// Copyright standard du système (pdf/header_pdf.php) — texte unique sur
// tous les PDF du projet, voir pdf_copyright().
pdf_copyright($pdf, $pw, $ph);
$pdf->Output($dl ? 'D' : 'I', 'releve_' . $classe['DesignationClasses'] . '_T' . $id_trim . '.pdf');
} catch (Throwable $e) {
    pdf_erreur_generation($e);
}
