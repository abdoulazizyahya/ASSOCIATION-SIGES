<?php
/**
 * RELEVE DE NOTES DES EVALUATIONS — version ANNUELLE (même forme que
 * pdf_releve.php, voir ce fichier pour le détail) : moyenne par matière et
 * moyenne générale = moyenne des 3 moyennes trimestrielles (même méthode
 * que secondaire/pages/bulletins/pdf_annuel.php). Imprimable avant/après le conseil de
 * fin d'année (decision_conseil, type='annee', id_trim=0).
 * GET : classe, annee, dl (0|1)
 */
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_connexion();

function u(string $s): string {
    return mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
}

$role     = role_connecte();
$is_admin = in_array($role, ['ADMIN', 'PROVISEUR', 'FONDATEUR', 'CENSEUR']) || $role === 'MEMBRE_ASSOCIATION';
$is_ens   = ($role === 'ENSEIGNANT');
$mat_ens  = $is_ens ? get_matricule_ens_connecte() : null;
if (!$is_admin && !$is_ens) die('Acces non autorise.');

$id_classe = (int)($_GET['classe'] ?? 0);
$id_annee  = (int)($_GET['annee']  ?? 0);
$ordre     = in_array($_GET['ordre'] ?? '', ['alpha', 'merite']) ? $_GET['ordre'] : 'alpha';
$dl        = ($_GET['dl'] ?? '0') === '1';

if (!$id_classe) die('Classe manquante.');

$annee_act = get_annee_active();
if (!$id_annee) $id_annee = (int)($annee_act['id'] ?? 0);
$val_annee = $annee_act['libelle'] ?? '';

if ($is_ens && $mat_ens) {
    $ok = db_val(
        "SELECT COUNT(*) FROM enseignat_principal WHERE matricule_ens=? AND IDClasses=? AND val_annee=?",
        [$mat_ens, $id_classe, $val_annee]
    );
    if (!$ok) die('Acces refuse.');
}

$classe = db_one("SELECT * FROM classe WHERE id=?", [$id_classe]);
if (!$classe) die('Classe introuvable.');

$etab = get_etablissement();

$eleves = db_all(
    "SELECT e.id, e.matricule, e.nom, e.prenom FROM eleve e
     JOIN inscription i ON i.id_eleve=e.id AND i.id_classe=? AND i.id_annee=?
     WHERE e.statut='actif'",
    [$id_classe, $id_annee]
);
$nb_inscrits = count($eleves);

$disciplines = db_all(
    "SELECT d.id_mat, d.coef, d.ordre, m.libelle AS matiere
     FROM discipline d JOIN matiere m ON m.id=d.id_mat AND m.actif=1
     WHERE d.IDClasses=? ORDER BY d.id_groupe, d.ordre, m.libelle",
    [$id_classe]
);
$nb_mat = count($disciplines);

// ── Trimestres de l'année (jusqu'à 3) — les compétences (chantier APC)
// sont scopées par trimestre entier, sans sous-division par séquence : la
// moyenne annuelle = moyenne des moyennes trimestrielles existantes, même
// méthode que secondaire/pages/bulletins/pdf_annuel.php (voir prompt_continuite,
// mise à jour du 07/08/2026, Phase 6). ──────────────────────────────────
$trimestres = db_all("SELECT * FROM trimestre WHERE id_annee=? ORDER BY ordre", [$id_annee]);
$trimestres = array_pad(array_slice($trimestres, 0, 3), 3, null);

$statut_par_trim   = [[], [], []]; // [i][eid] = statut complet (Règle 3, pv_moy_annuelle_comp())
$moys_mat_par_trim = [[], [], []]; // [i][eid][id_mat] = moyenne matière du trimestre i
foreach ([0, 1, 2] as $i) {
    $t = $trimestres[$i];
    if (!$t) continue;
    $dcomp = pv_charger_donnees_comp($id_classe, (int)$t['id'], $id_annee);
    if (empty($dcomp['competences_par_mat'])) continue;
    $evalue = $dcomp['nb_mats_avec_notes'] > 0;
    foreach ($eleves as $el) {
        $eid = (int)$el['id'];
        [$m, $classable, , $annule] = pv_moy_generale_comp(
            $eid, $dcomp['disciplines'], $dcomp['competences_par_mat'], $dcomp['notes_idx'], $dcomp['notes_count'],
            $dcomp['nb_inscrits'], $dcomp['mats_avec_notes'], $dcomp['nb_mats_avec_notes'], $dcomp['annules']
        );
        $statut_par_trim[$i][$eid] = ['moy' => $m, 'classable' => $classable, 'annule' => $annule, 'evalue' => $evalue];
        foreach ($disciplines as $d) {
            $mm = pv_moy_matiere_comp(
                $eid, $dcomp['competences_par_mat'][$d['id_mat']] ?? [], $dcomp['notes_idx'], $dcomp['notes_count'], $dcomp['nb_inscrits']
            );
            if ($mm !== null) $moys_mat_par_trim[$i][$eid][$d['id_mat']] = $mm;
        }
    }
}
// Règle 3 : moyenne annuelle générale via pv_moy_annuelle_comp() (fonctions.php).
$defaut_statut = ['moy' => null, 'classable' => false, 'annule' => false, 'evalue' => false];
$moys = []; // eid => moyenne annuelle générale
$moys_mat = []; // eid => [id_mat => moyenne annuelle matière]
foreach ($eleves as $el) {
    $eid = (int)$el['id'];
    $par_trim = [
        $statut_par_trim[0][$eid] ?? $defaut_statut,
        $statut_par_trim[1][$eid] ?? $defaut_statut,
        $statut_par_trim[2][$eid] ?? $defaut_statut,
    ];
    $r = pv_moy_annuelle_comp($par_trim);
    if ($r['moy'] !== null) $moys[$eid] = $r['moy'];
    foreach ($disciplines as $d) {
        $vm = [];
        foreach ([0, 1, 2] as $i) { if (isset($moys_mat_par_trim[$i][$eid][$d['id_mat']])) $vm[] = $moys_mat_par_trim[$i][$eid][$d['id_mat']]; }
        $moys_mat[$eid][$d['id_mat']] = !empty($vm) ? array_sum($vm) / count($vm) : null;
    }
}
arsort($moys);
$nb_classes_ = count($moys);
$rangs = []; $rg = 1;
foreach ($moys as $eid => $m) { $rangs[$eid] = $rg++; }

// ── Décision du conseil de fin d'année ──────────────────────────────────
$decisions_idx = [];
$rows = db_all("SELECT * FROM decision_conseil WHERE id_classe=? AND id_annee=? AND type='annee' AND id_trim=0", [$id_classe, $id_annee]);
foreach ($rows as $r) $decisions_idx[(int)$r['id_eleve']] = $r;

if ($ordre === 'merite') {
    usort($eleves, function ($a, $b) use ($moys) {
        $ma = $moys[(int)$a['id']] ?? null; $mb = $moys[(int)$b['id']] ?? null;
        if ($ma === null && $mb === null) return 0;
        if ($ma === null) return 1;
        if ($mb === null) return -1;
        return $mb <=> $ma;
    });
} else {
    usort($eleves, fn($a, $b) => strcmp($a['nom'] . ' ' . ($a['prenom'] ?? ''), $b['nom'] . ' ' . ($b['prenom'] ?? '')));
}

function fmt2(?float $v): string {
    if ($v === null) return '';
    $s = number_format($v, 2, '.', '');
    [$i, $d] = explode('.', $s);
    return str_pad($i, 2, '0', STR_PAD_LEFT) . '.' . $d;
}

function cell_rang_releve(FPDF $pdf, float $w, float $h, ?int $rang, string $suffixe, int $border, float $taille): void {
    $x = $pdf->GetX(); $y = $pdf->GetY();
    if ($border) { $pdf->Rect($x, $y, $w, $h); }
    if ($rang === null) {
        $pdf->SetFont('Arial', '', $taille);
        $pdf->Cell($w, $h, '-', 0, 0, 'C');
        $pdf->SetXY($x + $w, $y);
        return;
    }
    $part1 = (string)$rang;
    $taille_e = round($taille * 0.62, 2);
    $pdf->SetFont('Arial', '', $taille);
    $w1 = $pdf->GetStringWidth($part1);
    $w2 = $pdf->GetStringWidth(u($suffixe));
    $pdf->SetFont('Arial', '', $taille_e);
    $we = $pdf->GetStringWidth('e');
    $total = $w1 + $we + $w2;
    $x0 = $x + ($w - $total) / 2;

    $pdf->SetFont('Arial', '', $taille);
    $pdf->SetXY($x0, $y);
    $pdf->Cell($w1, $h, $part1, 0, 0, 'L');
    $raise = $taille * 0.12;
    $pdf->SetXY($x0 + $w1, $y - $raise);
    $pdf->SetFont('Arial', '', $taille_e);
    $pdf->Cell($we, $h, 'e', 0, 0, 'L');
    $pdf->SetXY($x0 + $w1 + $we, $y);
    $pdf->SetFont('Arial', '', $taille);
    $pdf->Cell($w2, $h, u($suffixe), 0, 0, 'L');
    $pdf->SetXY($x + $w, $y);
}

// ── Génération PDF (paysage) ────────────────────────────────────────────
require_once __DIR__ . '/../../pdf/fpdf.php';
require_once __DIR__ . '/../../pdf/header_pdf.php'; // pour pdf_filigrane()

$pdf = new FPDF('L', 'mm', 'A4');
$pdf->SetMargins(8, 8, 8);
$pdf->SetAutoPageBreak(true, 6);
$pdf->AddPage();
$pw = $pdf->GetPageWidth();
$ml = 8; $mr = 8;
$uw = $pw - $ml - $mr;

pdf_filigrane($pdf, $etab, $pw, $pdf->GetPageHeight());

function entete_releve(FPDF $pdf, array $etab, float $ml, float $uw): void {
    $col3 = $uw / 3;
    $y0   = $pdf->GetY();
    $texte_etab =
        "REGION DE L'ADAMAOUA\n" .
        ($etab['departement_fr'] ?? 'DEPARTEMENT DE LA VINA') . "\n" .
        ($etab['arrondissement_fr'] ?? 'ARRONDISSEMENT DE MBE') . "\n" .
        "***********\n";
    $pdf->SetXY($ml, $y0);
    $pdf->SetFont('Arial', '', 8);
    $pdf->MultiCell($col3, 4, u($texte_etab), 0, 'C');
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetX($ml);
    $pdf->MultiCell($col3, 5, u(strtoupper($etab['nom_fr'] ?? 'LYCEE TECHNIQUE DE MBE')), 0, 'C');

    $xr = $ml + $col3 * 2;
    $pdf->SetXY($xr, $y0);
    $pdf->SetFont('Arial', '', 8);
    $pdf->MultiCell($col3, 4, u($texte_etab), 0, 'C');
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetX($xr);
    $pdf->MultiCell($col3, 5, u(strtoupper($etab['nom_fr'] ?? 'LYCEE TECHNIQUE DE MBE')), 0, 'C');

    $logo_path = !empty($etab['logo']) ? __DIR__ . '/../../../assets/uploads/' . $etab['logo'] : '';
    $logo_w = 18;
    $logo_h = $logo_w;
    if ($logo_path && is_file($logo_path)) {
        $dim = @getimagesize($logo_path);
        if ($dim && $dim[0] > 0) $logo_h = $logo_w * $dim[1] / $dim[0];
        $pdf->Image($logo_path, $ml + $col3 + ($col3 - $logo_w) / 2, $y0, $logo_w);
    }
    $pdf->SetY(max($pdf->GetY(), $y0 + 22));
}
entete_releve($pdf, $etab, $ml, $uw);
$pdf->Ln(1);

$pdf->SetFont('Arial', 'B', 15);
$pdf->Cell($uw, 8, u('RELEVE DE NOTES DES EVALUATIONS'), 0, 1, 'C');
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell($uw, 5.5, u('ANNUELLE'), 0, 1, 'C');
$pdf->Ln(1);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell($uw / 2, 5, u('CLASSE : ' . $classe['designation']), 0, 0, 'L');
$pdf->Cell($uw / 2, 5, u('ANNEE SCOLAIRE : ' . $val_annee), 0, 1, 'R');
$pdf->Ln(1);

$w_no = 8; $w_mat_id = 20; $w_nom = 45; $w_moy = 11; $w_rang = 13; $w_obs = 26;
$fixe = $w_no + $w_mat_id + $w_nom + $w_moy + $w_rang + $w_obs;
$w_sub = $nb_mat > 0 ? max(8, ($uw - $fixe) / $nb_mat) : 0;

$h_hdr1 = 28; $h_hdr2 = 5; $h_hdr = $h_hdr1 + $h_hdr2;

function dessiner_entete_tableau(FPDF $pdf, array $disciplines, float $ml, float $w_no, float $w_mat_id, float $w_nom, float $w_sub, float $w_moy, float $w_rang, float $w_obs, float $h_hdr1, float $h_hdr2, float $h_hdr): void {
    $y_hdr = $pdf->GetY();
    $pdf->SetFillColor(26, 60, 107);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetDrawColor(0, 0, 0);
    $x = $ml;
    foreach ([['N°', $w_no], ['MATRICULE', $w_mat_id], ['NOM ET PRENOMS', $w_nom]] as [$lbl, $w]) {
        $pdf->SetXY($x, $y_hdr);
        $pdf->SetFont('Arial', 'B', 7.5);
        $pdf->Cell($w, $h_hdr, u($lbl), 1, 0, 'C', true);
        $x += $w;
    }
    foreach ($disciplines as $d) {
        $pdf->Rect($x, $y_hdr, $w_sub, $h_hdr1, 'DF');
        $taille = 7;
        $pdf->SetFont('Arial', 'B', $taille);
        while ($taille > 4 && $pdf->GetStringWidth(u($d['matiere'])) > $h_hdr1 - 2) {
            $taille -= 0.25;
            $pdf->SetFont('Arial', 'B', $taille);
        }
        $pdf->TextWithDirection($x + $w_sub / 2 + 1, $y_hdr + $h_hdr1 - 1, u($d['matiere']), 'U');
        $pdf->SetXY($x, $y_hdr + $h_hdr1);
        $pdf->SetFont('Arial', 'B', 7);
        $pdf->Cell($w_sub, $h_hdr2, (string)$d['coef'], 1, 0, 'C', true);
        $x += $w_sub;
    }
    foreach ([['MOY /20', $w_moy], ['RANG', $w_rang], ['OBSERVATIONS', $w_obs]] as [$lbl, $w]) {
        $pdf->SetXY($x, $y_hdr);
        $pdf->SetFont('Arial', 'B', 7.5);
        $pdf->Cell($w, $h_hdr, u($lbl), 1, 0, 'C', true);
        $x += $w;
    }
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetXY($ml, $y_hdr + $h_hdr);
}
dessiner_entete_tableau($pdf, $disciplines, $ml, $w_no, $w_mat_id, $w_nom, $w_sub, $w_moy, $w_rang, $w_obs, $h_hdr1, $h_hdr2, $h_hdr);

$h_row = 5;
$n = 1;
foreach ($eleves as $el) {
    $eid = (int)$el['id'];
    if ($pdf->GetY() + $h_row > $pdf->GetPageHeight() - 12) {
        $pdf->AddPage();
        pdf_filigrane($pdf, $etab, $pw, $pdf->GetPageHeight());
        dessiner_entete_tableau($pdf, $disciplines, $ml, $w_no, $w_mat_id, $w_nom, $w_sub, $w_moy, $w_rang, $w_obs, $h_hdr1, $h_hdr2, $h_hdr);
    }

    $fill = ($n % 2 === 0);
    $pdf->SetFillColor(240, 240, 246);
    $pdf->SetX($ml);
    $pdf->SetFont('Arial', '', 7);
    $pdf->Cell($w_no, $h_row, (string)$n, 1, 0, 'C', $fill);
    $pdf->Cell($w_mat_id, $h_row, u($el['matricule'] ?? ''), 1, 0, 'C', $fill);
    $pdf->Cell($w_nom, $h_row, u(strtoupper($el['nom']) . ' ' . ($el['prenom'] ?? '')), 1, 0, 'L', $fill);
    foreach ($disciplines as $d) {
        $v = $moys_mat[$eid][$d['id_mat']] ?? null;
        $pdf->Cell($w_sub, $h_row, $v !== null ? fmt2($v) : '', 1, 0, 'C', $fill);
    }
    $moy = $moys[$eid] ?? null;
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->Cell($w_moy, $h_row, $moy !== null ? fmt2($moy) : '', 1, 0, 'C', $fill);
    $rang = $rangs[$eid] ?? null;
    $suffixe = $rang !== null ? ' /' . $nb_classes_ : '';
    if ($fill) { $pdf->SetFillColor(240, 240, 246); }
    cell_rang_releve($pdf, $w_rang, $h_row, $rang, $suffixe, 1, 7);
    $deja = $decisions_idx[$eid] ?? null;
    $obs = $deja ? trim((string)($deja['decision'] ?? '') . (!empty($deja['observation']) ? ' - ' . $deja['observation'] : '')) : '';
    $pdf->SetFont('Arial', '', 6.5);
    $pdf->Cell($w_obs, $h_row, u($obs), 1, 1, 'L', $fill);
    $n++;
}
if (empty($eleves)) {
    $pdf->SetX($ml);
    $pdf->Cell($uw, 8, u('Aucun eleve inscrit.'), 1, 1, 'C');
}

// ── Lieu, date et signature du chef d'établissement (bas de page) ──────
$ph = $pdf->GetPageHeight();
$pdf->Ln(8);
if ($pdf->GetY() > $ph - 25) { $pdf->AddPage(); pdf_filigrane($pdf, $etab, $pw, $ph); }
$w_sign = $uw * 0.35;
$x_sign = $pw - 8 - $w_sign;
$pdf->SetFont('Arial', '', 8.5);
$pdf->SetX($x_sign);
$pdf->Cell($w_sign, 5, u('Fait à ' . ($etab['ville'] ?? '') . ', le ' . date('d/m/Y')), 0, 1, 'R');
$pdf->SetFont('Arial', 'B', 8.5);
$pdf->SetX($x_sign);
$pdf->Cell($w_sign, 5, u(strtoupper($etab['chef_etablissement'] ?? 'LE PROVISEUR') . ','), 0, 1, 'R');

// Signature numérique (sur demande uniquement, jamais automatique).
if (($_GET['signature'] ?? '0') === '1') {
    $sig_w = 22;
    $sy = $pdf->GetY() + 1;
    $sx = $x_sign + ($w_sign - $sig_w) / 2;
    pdf_signature_appliquer($pdf, 'releve_notes_annuel', 'chef_etablissement', 0, 0, $pw, $ph, [
        'x_pct' => $sx / $pw * 100, 'y_pct' => $sy / $ph * 100, 'w_pct' => $sig_w / $pw * 100, 'h_pct' => null,
    ]);
}

// ── Sortie ────────────────────────────────────────────────────────────
$mode   = $dl ? 'D' : 'I';
$suffix = preg_replace('/\W+/', '_', $classe['designation'] ?? 'classe');
$pdf->Output($mode, 'releve_annuel_' . $suffix . '.pdf');
