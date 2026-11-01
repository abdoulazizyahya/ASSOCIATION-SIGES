<?php
// secondaire/commun/pages/utilisateurs/export.php — liste des comptes utilisateurs
// (nom du personnel, fonction, identifiant, mot de passe) en PDF (?format=pdf)
// ou Excel (?format=xlsx), avec l'en-tête de l'école. Demande du 30/10/2026.
// Mêmes comptes que liste.php (sec_compte_visible() : jamais l'administrateur
// ni le compte propriétaire). Les mots de passe sont chiffrés (irréversibles) :
// seul celui encore égal à l'identifiant (par défaut ou réinitialisé) est
// imprimable ; sinon « personnalisé ». Chaque export est journalisé.
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
exiger_role(['ADMIN', 'FONDATEUR', 'PROVISEUR']);

$format = ($_GET['format'] ?? 'pdf') === 'xlsx' ? 'xlsx' : 'pdf';
$dl     = ($_GET['dl'] ?? '0') === '1';
$etab   = get_etablissement();

$users = db_all(
    "SELECT u.*, COALESCE(NULLIF(TRIM(u.nom), ''), e.nom_ens, '') AS nom_aff,
            COALESCE(NULLIF(TRIM(u.prenom), ''), e.prenom_ens, '') AS prenom_aff
     FROM utilisateur u LEFT JOIN enseignant e ON e.matricule_ens = u.matricule_ens"
);
$users = array_values(array_filter($users, 'sec_compte_visible'));
// Comptes cochés dans la liste (?ids=3,8,12) ; aucun = tous les comptes visibles.
$ids = array_filter(array_map('intval', explode(',', (string) ($_GET['ids'] ?? ''))));
if ($ids) $users = array_values(array_filter($users, fn($u) => in_array((int) $u['id'], $ids, true)));
usort($users, fn($a, $b) => strcoll(mb_strtoupper(trim($a['nom_aff'] . ' ' . $a['prenom_aff'])) ?: $a['login'],
                                     mb_strtoupper(trim($b['nom_aff'] . ' ' . $b['prenom_aff'])) ?: $b['login']));

$lignes = [];
foreach ($users as $u) {
    if (!sec_compte_gerable($u))                                              $mdp = '—';
    elseif (mdp_est_identifiant((string) $u['login'], (string) $u['mot_de_passe'])) $mdp = (string) $u['login'];
    else                                                                      $mdp = 'Personnalisé';
    $lignes[] = [
        'nom'   => trim(mb_strtoupper((string) $u['nom_aff']) . ' ' . $u['prenom_aff']) ?: '—',
        'role'  => libelle_role((string) $u['role']),
        'login' => (string) $u['login'],
        'mdp'   => $mdp,
        'actif' => $u['actif'] ? 'Actif' : 'Inactif',
    ];
}
journaliser_action('export_comptes_' . $format, null, count($lignes) . ' compte(s)');
$titre = 'LISTE DES COMPTES UTILISATEURS';
$note  = "« Personnalisé » : mot de passe choisi par l'utilisateur, illisible (chiffré) — le réinitialiser depuis Comptes utilisateurs pour l'imprimer. "
       . "Mot de passe imprimé = identifiant : à changer à la première connexion.";

// ── Excel ───────────────────────────────────────────────────────────
if ($format === 'xlsx') {
    require_once __DIR__ . '/../../../../vendor/autoload.php';
    $sp = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sh = $sp->getActiveSheet();
    $sh->setTitle('Comptes');
    $entete = array_values(array_filter([
        'RÉPUBLIQUE DU CAMEROUN — Paix - Travail - Patrie',
        $etab['region_fr'] ?? '', $etab['departement_fr'] ?? '', $etab['arrondissement_fr'] ?? '',
        mb_strtoupper((string) ($etab['nom_fr'] ?? APP_NOM)),
        trim('B.P. ' . ($etab['boite_postale'] ?? '') . ' ' . ($etab['ville'] ?? '') . ((string) ($etab['telephone'] ?? '') !== '' ? '  Tél. : ' . $etab['telephone'] : '')),
    ], fn($l) => trim($l) !== '' && trim($l) !== 'B.P.'));
    $r = 1;
    foreach ($entete as $i => $l) {
        $sh->mergeCells("A$r:F$r");
        $sh->setCellValue("A$r", $l);
        $sh->getStyle("A$r")->getFont()->setBold($i === count($entete) - 2)->setSize($i === count($entete) - 2 ? 13 : 10);
        $sh->getStyle("A$r")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $r++;
    }
    $r++;
    $sh->mergeCells("A$r:F$r");
    $sh->setCellValue("A$r", $titre . ' — ' . date('d/m/Y'));
    $sh->getStyle("A$r")->getFont()->setBold(true)->setSize(12);
    $sh->getStyle("A$r")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    $r += 2;
    $l_ent = $r;
    foreach (['N°', 'Nom et prénom', 'Fonction', 'Identifiant', 'Mot de passe', 'Statut'] as $i => $lbl) $sh->setCellValue([$i + 1, $r], $lbl);
    $sh->getStyle("A$r:F$r")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $sh->getStyle("A$r:F$r")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('1E4FD8');
    foreach ($lignes as $n => $l) {
        $r++;
        $sh->setCellValue([1, $r], $n + 1);
        $sh->setCellValue([2, $r], $l['nom']);
        $sh->setCellValue([3, $r], $l['role']);
        $sh->setCellValueExplicit([4, $r], $l['login'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sh->setCellValueExplicit([5, $r], $l['mdp'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sh->setCellValue([6, $r], $l['actif']);
    }
    $sh->getStyle("A$l_ent:F$r")->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
    foreach (range('A', 'F') as $c) $sh->getColumnDimension($c)->setAutoSize(true);
    $r += 2;
    $sh->mergeCells("A$r:F$r");
    $sh->setCellValue("A$r", $note);
    $sh->getStyle("A$r")->getFont()->setItalic(true)->setSize(8);
    $sh->getStyle("A$r")->getAlignment()->setWrapText(true);
    $sh->getRowDimension($r)->setRowHeight(30);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="comptes_utilisateurs_' . date('Ymd') . '.xlsx"');
    header('Cache-Control: max-age=0');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($sp))->save('php://output');
    exit;
}

// ── PDF ─────────────────────────────────────────────────────────────
require_once __DIR__ . '/../../pdf/fpdf.php';
require_once __DIR__ . '/../../pdf/header_pdf.php';
$pdf = new FPDF('P', 'mm', 'A4');
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 15);
$pdf->AddPage();
$pw = $pdf->GetPageWidth();
pdf_filigrane($pdf, $etab, $pw, $pdf->GetPageHeight());
pdf_entete($pdf, $etab, $pw);
pdf_bandeau($pdf, $titre, 'USER ACCOUNTS', $pw);
$pdf->SetFont('Arial', '', 8);
$pdf->Cell(0, 5, pdf_u(count($lignes) . ' compte(s) — édité le ' . date('d/m/Y à H:i')), 0, 1, 'L');
$pdf->Ln(1);

$cols = [['N°', 9, 'C'], ['Nom et prénom', 62, 'L'], ['Fonction', 34, 'L'], ['Identifiant', 33, 'L'], ['Mot de passe', 33, 'L'], ['Statut', 19, 'C']];
$entete_tab = function () use ($pdf, $cols) {
    $pdf->SetFillColor(30, 79, 216); $pdf->SetTextColor(255, 255, 255); $pdf->SetFont('Arial', 'B', 8);
    foreach ($cols as [$lbl, $w]) $pdf->Cell($w, 6, pdf_u($lbl), 1, 0, 'C', true);
    $pdf->Ln(); $pdf->SetTextColor(0);
};
$entete_tab();
$fill = false;
foreach ($lignes as $n => $l) {
    if ($pdf->GetY() > $pdf->GetPageHeight() - 25) { $pdf->AddPage(); $entete_tab(); }
    $pdf->SetFillColor(234, 244, 251); $pdf->SetFont('Arial', '', 8);
    $vals = [(string) ($n + 1), $l['nom'], $l['role'], $l['login'], $l['mdp'], $l['actif']];
    foreach ($cols as $i => [, $w, $al]) {
        $pdf->SetFont('Arial', in_array($i, [3, 4], true) && $l['mdp'] !== 'Personnalisé' && $l['mdp'] !== '—' ? 'B' : '', 8);
        $pdf->Cell($w, 6, pdf_u($vals[$i]), 1, 0, $al, $fill);
    }
    $pdf->Ln(); $fill = !$fill;
}
$pdf->Ln(2);
$pdf->SetFont('Arial', 'I', 7);
$pdf->MultiCell(0, 3.5, pdf_u($note), 0, 'L');

// Lieu, date et signataire
$pdf->Ln(6);
if ($pdf->GetY() > $pdf->GetPageHeight() - 30) $pdf->AddPage();
$w_sign = ($pw - 20) * 0.4;
$x_sign = $pw - 25 - $w_sign;
pdf_fait_a_bilingue($pdf, $x_sign, $w_sign, lieu_edition_documents($etab), 'R', 9);
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetX($x_sign);
$pdf->Cell($w_sign, 5, pdf_u(strtoupper($etab['chef_etablissement'] ?? 'LE PROVISEUR')), 0, 1, 'R');

$pdf->Output($dl ? 'D' : 'I', 'comptes_utilisateurs_' . date('Ymd') . '.pdf');
