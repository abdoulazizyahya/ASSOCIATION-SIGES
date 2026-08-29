<?php
// ── PDF : Fiche individuelle d'un élève ──────────────────────
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
exiger_connexion();
interdire_role('COMPTABLE', "Ce document n'est pas accessible au profil Agent financier / Comptable.");

require_once __DIR__ . '/fpdf.php';
require_once __DIR__ . '/header_pdf.php';

$id    = (int)($_GET['id'] ?? 0);
$dl    = ($_GET['dl'] ?? '0') === '1';
$eleve = db_one("SELECT * FROM eleve WHERE id_eleve=?", [$id]);
if (!$eleve) die('Élève introuvable.');

$inscriptions = db_all(
    "SELECT i.*, c.DesignationClasses AS classe
     FROM inscrire i JOIN classe c ON c.IDClasses=i.IDClasses
     WHERE i.id_eleve=? ORDER BY i.val_annee DESC", [$id]
);
$parents = db_all("SELECT * FROM parent WHERE id_eleve=?", [$id]);

$etab_brut = get_etablissement();
$etab      = etab_pour_pdf($etab_brut);

// Enveloppé dans un try/catch — voir fonctions.php::pdf_erreur_generation()
// (jamais de fatal error brut ; ce document est public via QR et/ou
// consulté par du personnel qui ne doit pas voir de trace technique).
try {
$pdf = new FPDF('P', 'mm', 'A4');
$pdf->SetMargins(12, 10, 12);
$pdf->SetAutoPageBreak(true, 15);
$pdf->AddPage();
$pw = $pdf->GetPageWidth();

pdf_filigrane($pdf, $etab, $pw, $pdf->GetPageHeight());
pdf_entete($pdf, $etab, $pw, 12);
pdf_bandeau($pdf, 'FICHE ÉLÈVE', 'STUDENT RECORD', $pw, 12);

$y_body = $pdf->GetY();

// ── Photo (BLOB écrit dans un fichier temporaire, seulement si c'est
//     réellement une image — voir blob_est_image()) ────────────────────
$photo_tmp = photo_eleve_fichier_temp($eleve['Photo_elv'], $id);
if ($photo_tmp) {
    $pdf->Image($photo_tmp, 12, $y_body, 28, 34);
} else {
    $pdf->Rect(12, $y_body, 28, 34);
    $pdf->SetFont('Arial', 'I', 6.5);
    $pdf->SetXY(12, $y_body + 14);
    $pdf->Cell(28, 5, 'Pas de photo', 0, 0, 'C');
}

// ── Infos personnelles ─────────────────────────────────────────
$xi = 42; $wi = $pw - $xi - 12;
$pdf->SetXY($xi, $y_body);
$pdf->SetFont('Arial', 'B', 11);
$pdf->Cell($wi, 7, pdf_u(mb_strtoupper($eleve['Nom_elv']) . ' ' . ($eleve['Prenom_elv'] ?? '')), 0, 1, 'L');

// Connaître l'arrondissement suffit à retrouver département et région par
// jointure — jamais stockés séparément (voir bd/migration_v5.sql).
$lieu = $eleve['id_arrondissement']
    ? db_one(
        "SELECT a.intitule_arrond, d.intitule_depart, r.intitule_region
         FROM arrondissement a
         JOIN departement d ON d.code_depart = a.code_depart
         JOIN region r ON r.id_region = d.code_region
         WHERE a.code_arrond = ?",
        [$eleve['id_arrondissement']]
      )
    : null;

$champs = [
    'Matricule'         => $eleve['Mat_elv'],
    'NIU'               => $eleve['niu'] ?: '—',
    'Sexe'              => stripos($eleve['Sexe_elv'], 'F') === 0 ? 'Féminin' : 'Masculin',
    'Date de naissance' => $eleve['Date_naiss_elv'] ? date('d/m/Y', strtotime($eleve['Date_naiss_elv'])) : '—',
    'Lieu de naissance' => $eleve['Lieu_naiss_elv'] ?: '—',
    "Région d'origine"      => $lieu['intitule_region'] ?? '—',
    "Département d'origine" => $lieu['intitule_depart'] ?? '—',
    "Arrondissement d'origine" => $lieu['intitule_arrond'] ?? ($eleve['arrondissement_elv'] ?: '—'),
    'Adresse'           => $eleve['Adresse_elv'] ?: '—',
];
foreach ($champs as $lbl => $val) {
    $pdf->SetX($xi);
    $pdf->SetFont('Arial', 'B', 7.5); $pdf->Cell(45, 5, pdf_u($lbl . ' :'), 0, 0);
    $pdf->SetFont('Arial', '', 7.5);  $pdf->Cell($wi - 45, 5, pdf_u((string)$val), 0, 1);
}

// Point de reprise calculé (pas un décalage fixe) : le nombre de champs a
// changé (Région/Arrondissement d'origine ajoutés) — un décalage figé se
// serait chevauché avec la section suivante. On prend aussi la hauteur de
// la photo (34mm) en compte, au cas où le bloc texte serait plus court qu'elle.
$pdf->SetY(max($pdf->GetY(), $y_body + 34) + 2);

// ── Historique scolaire ────────────────────────────────────────
$pdf->SetFont('Arial', 'B', 8.5);
$pdf->SetFillColor(214, 234, 248);
$pdf->Cell(0, 6, pdf_u('  Historique scolaire'), 'LRB', 1, 'L', true);
$pdf->SetFont('Arial', 'B', 7.5);
$pdf->SetFillColor(30, 79, 216); $pdf->SetTextColor(255, 255, 255);
$pdf->Cell(40, 5, pdf_u('Année'), 1, 0, 'C', true);
$pdf->Cell(60, 5, 'Classe', 1, 0, 'C', true);
$pdf->Cell(40, 5, 'Statut', 1, 1, 'C', true);
$pdf->SetTextColor(0); $pdf->SetFont('Arial', '', 7.5);
foreach ($inscriptions as $i) {
    $pdf->Cell(40, 5, pdf_u((string)$i['val_annee']), 1, 0, 'C');
    $pdf->Cell(60, 5, pdf_u((string)$i['classe']), 1, 0, 'L');
    $pdf->Cell(40, 5, pdf_u(libelle_statut_insc($i['Statut_elv'])), 1, 1, 'C');
}

// ── Parents / Tuteurs ────────────────────────────────────────────
if ($parents) {
    $pdf->Ln(3);
    $pdf->SetFont('Arial', 'B', 8.5);
    $pdf->SetFillColor(214, 234, 248);
    $pdf->Cell(0, 6, pdf_u('  Parents / Tuteurs'), 'LRB', 1, 'L', true);
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->SetFillColor(30, 79, 216); $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(25, 5, 'Lien', 1, 0, 'C', true);
    $pdf->Cell(70, 5, pdf_u('Nom et prénom'), 1, 0, 'C', true);
    $pdf->Cell(45, 5, pdf_u('Profession'), 1, 1, 'C', true);
    $pdf->SetTextColor(0); $pdf->SetFont('Arial', '', 7.5);
    $lien_lbl = ['Masculin' => 'Père', 'Feminin' => 'Mère', 'titeur' => 'Tuteur'];
    foreach ($parents as $t) {
        $pdf->Cell(25, 5, pdf_u($lien_lbl[$t['sexe']] ?? '—'), 1, 0, 'C');
        $pdf->Cell(70, 5, pdf_u($t['nom'] . ' ' . ($t['prenom'] ?? '')), 1, 0, 'L');
        $pdf->Cell(45, 5, pdf_u($t['profession'] ?: '—'), 1, 1, 'C');
    }
}

// ── Lieu, date et signature du Directeur (bas de page) ──────
$ph = $pdf->GetPageHeight();
$pdf->Ln(8);
if ($pdf->GetY() > $ph - 30) { $pdf->AddPage(); pdf_filigrane($pdf, $etab, $pw, $ph); }
$w_sign = ($pw - 24) * 0.4;
$x_sign = $pw - 12 - $w_sign;
$pdf->SetFont('Arial', '', 9);
$pdf->SetX($x_sign);
$pdf->Cell($w_sign, 5, pdf_u('Fait à ' . (($etab['lieu'] ?: $etab['ville']) ?: '') . ', le ' . date('d/m/Y')), 0, 1, 'R');
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetX($x_sign);
$pdf->Cell($w_sign, 5, pdf_u(mb_strtoupper($etab['chef_etablissement'] ?: 'LE DIRECTEUR') . ','), 0, 1, 'R');

// Signature numérique (sur demande uniquement, jamais automatique), à la
// position enregistrée par l'utilisateur.
if (($_GET['signature'] ?? '0') === '1') {
    pdf_signature_appliquer_jn($pdf, 'fiche_eleve', 0, 0, $pw, $ph, [
        'x_pct' => ($x_sign + ($w_sign - 22) / 2) / $pw * 100,
        'y_pct' => ($pdf->GetY() + 1) / $ph * 100,
        'w_pct' => 22 / $pw * 100, 'h_pct' => null,
    ]);
}

pdf_copyright($pdf, $pw, $ph);
$pdf->Output($dl ? 'D' : 'I', 'fiche_' . $eleve['Mat_elv'] . '.pdf');
} catch (Throwable $e) {
    pdf_erreur_generation($e);
}

if ($photo_tmp && is_file($photo_tmp)) @unlink($photo_tmp);
