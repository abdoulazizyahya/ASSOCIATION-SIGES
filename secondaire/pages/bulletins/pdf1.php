<?php
/**
 * Bulletin scolaire — modèle LTM
 * GET : eleve=ID, trim=ID_TRIMESTRE, annee=ID_ANNEE, seq=ID_SEQ (optionnel)
 */
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_connexion();
require_once __DIR__ . '../../pdf/fpdf.php';
require_once __DIR__ . '../../pdf/header_pdf.php';

//function u(string $s): string { return utf8_decode($s); }
function u(string $s): string { 
	//return utf8_decode($s); 
	return mb_convert_encoding($s, 'Windows-1252', 'UTF-8'); 
}

// ── Paramètres ────────────────────────────────────────────────────
$id_eleve = (int)($_GET['eleve'] ?? 0);
$id_trim  = (int)($_GET['trim']  ?? 0);
$id_seq_u = (int)($_GET['seq']   ?? 0);
$id_annee = (int)($_GET['annee'] ?? 0);

if (!$id_eleve) die('Parametre eleve manquant.');

$eleve = db_one("SELECT * FROM eleve WHERE id=?", [$id_eleve]);
if (!$eleve) die('Eleve introuvable.');

$annee     = db_one("SELECT * FROM annee_scolaire WHERE id=?", [$id_annee]);
$val_annee = $annee['libelle'] ?? '';

$insc = db_one(
    "SELECT i.*, c.designation AS classe, c.id AS id_classe
     FROM inscription i JOIN classe c ON c.id=i.id_classe
     WHERE i.id_eleve=? AND i.id_annee=?",
    [$id_eleve, $id_annee]
);
if (!$insc) die('Inscription introuvable.');

// ── Séquences à afficher ──────────────────────────────────────────
$is_seq = ($id_seq_u > 0);
if ($is_seq) {
    $seq_ref = db_one(
        "SELECT s.*, t.libelle AS trimestre, t.id AS id_trim
         FROM sequence s JOIN trimestre t ON t.id=s.id_trim WHERE s.id=?",
        [$id_seq_u]
    );
    $id_trim    = (int)($seq_ref['id_trim'] ?? 0);
    $seqs_trim  = [$seq_ref];
    $titre_bull = $seq_ref['trimestre'] . ' - ' . $seq_ref['libelle'];
} else {
    $trim_info = db_one("SELECT * FROM trimestre WHERE id=?", [$id_trim]);
    if (!$trim_info) die('Trimestre introuvable.');
    $seqs_trim  = db_all(
        "SELECT s.*, t.libelle AS trimestre FROM sequence s JOIN trimestre t ON t.id=s.id_trim WHERE s.id_trim=? ORDER BY s.ordre",
        [$id_trim]
    );
    $titre_bull = strtoupper($trim_info['libelle'] ?? '');
}

$nb_seqs = count($seqs_trim);
$seq_ids = array_column($seqs_trim, 'id');
$in_ph   = $nb_seqs > 0 ? implode(',', array_fill(0, $nb_seqs, '?')) : '0';

// ── Établissement ─────────────────────────────────────────────────
$etab = get_etablissement();

// ── Prof principal ────────────────────────────────────────────────
$pp = db_one(
    "SELECT e.nom_ens, e.prenom_ens FROM enseignat_principal ep
     JOIN enseignant e ON e.matricule_ens=ep.matricule_ens
     WHERE ep.IDClasses=? AND ep.val_annee=? LIMIT 1",
    [$insc['id_classe'], $val_annee]
);
$nom_pp = $pp ? trim($pp['nom_ens'] . ' ' . ($pp['prenom_ens'] ?? '')) : '-';

// ── Tuteur ────────────────────────────────────────────────────────
$tuteur = db_one("SELECT * FROM tuteur WHERE id_eleve=? LIMIT 1", [$id_eleve]);
$contacts_parents = $tuteur
    ? trim(($tuteur['nom'] ?? '') . ' ' . ($tuteur['prenom'] ?? '') . ' - ' . ($tuteur['telephone'] ?? ''))
    : '';

// ── Disciplines ───────────────────────────────────────────────────
$disciplines = db_all(
    "SELECT d.id_mat, d.coef, d.ordre, m.libelle AS matiere,
            g.libelle_groupe_comp AS groupe_lib, d.id_groupe,
            TRIM(CONCAT(e.nom_ens,' ',COALESCE(e.prenom_ens,''))) AS enseignant
     FROM discipline d
     JOIN matiere m ON m.id=d.id_mat AND m.actif=1
     LEFT JOIN groupe g ON g.id_groupe_comp=d.id_groupe
     LEFT JOIN dispenser disp ON disp.id_mat=d.id_mat AND disp.IDClasses=d.IDClasses AND disp.val_annee=?
     LEFT JOIN enseignant e ON e.matricule_ens=disp.matricule_ens
     WHERE d.IDClasses=?
     ORDER BY d.id_groupe, d.ordre, m.libelle",
    [$val_annee, $insc['id_classe']]
);

// ── Élèves inscrits dans la classe ───────────────────────────────
$all_enrolled = db_all(
    "SELECT e.id FROM eleve e
     JOIN inscription i ON i.id_eleve=e.id AND i.id_classe=? AND i.id_annee=?
     WHERE e.statut='actif'",
    [$insc['id_classe'], $id_annee]
);
$enrolled_ids = array_column($all_enrolled, 'id');
$nb_inscrits  = count($enrolled_ids);

// ── Notes brutes ──────────────────────────────────────────────────
$all_notes_raw = [];
if ($nb_seqs > 0) {
    $params_n = array_merge([$id_annee, $insc['id_classe']], $seq_ids);
    $all_notes_raw = db_all(
        "SELECT n.id_eleve, n.id_matiere, n.id_seq, n.valeur
         FROM note n
         JOIN inscription i ON i.id_eleve=n.id_eleve AND i.id_annee=? AND i.id_classe=?
         JOIN eleve el ON el.id=n.id_eleve AND el.statut='actif'
         WHERE n.id_seq IN ($in_ph)",
        $params_n
    );
}
// notes_idx[eid][id_mat][id_seq] = valeur
$notes_idx = [];
foreach ($all_notes_raw as $row) {
    $notes_idx[(int)$row['id_eleve']][(int)$row['id_matiere']][(int)$row['id_seq']] = (float)$row['valeur'];
}

// ── Absences justifiées ───────────────────────────────────────────
$abs_just = [];
if ($nb_seqs > 0) {
    $all_abs_raw = db_all(
        "SELECT id_eleve, id_matiere, id_seq FROM absence_justifiee WHERE id_seq IN ($in_ph) AND justifie=1",
        $seq_ids
    );
    foreach ($all_abs_raw as $row) {
        $abs_just[(int)$row['id_eleve']][(int)$row['id_matiere']][(int)$row['id_seq']] = 1;
    }
}

// ── Nombre de notes par (matière, seq) ───────────────────────────
$notes_count = []; // [id_mat][id_seq] = nb élèves avec note
foreach ($all_notes_raw as $row) {
    $m = (int)$row['id_matiere']; $s = (int)$row['id_seq'];
    $notes_count[$m][$s] = ($notes_count[$m][$s] ?? 0) + 1;
}

// ── Fonctions helpers ─────────────────────────────────────────────
function note_eff_bull(int $eid, int $id_mat, int $sid, array $ni, array $aj, array $nc, int $nb_ins): array {
    if (isset($ni[$eid][$id_mat][$sid])) return [$ni[$eid][$id_mat][$sid], false];
    if (isset($aj[$eid][$id_mat][$sid])) return [null, true]; // exclu (justifié)
    $cnt = $nc[$id_mat][$sid] ?? 0;
    if ($nb_ins > 0 && $cnt >= ceil($nb_ins / 2)) return [0.0, false]; // absent = 0
    return [null, false];
}

function mat_avg_bull(int $eid, int $id_mat, array $seq_ids, array $ni, array $aj, array $nc, int $nb_ins): ?float {
    $tot = 0; $cnt = 0;
    foreach ($seq_ids as $sid) {
        [$v, $ex] = note_eff_bull($eid, $id_mat, $sid, $ni, $aj, $nc, $nb_ins);
        if ($ex) continue;
        if ($v !== null) { $tot += $v; $cnt++; }
    }
    return $cnt > 0 ? $tot / $cnt : null;
}

function fmt_note_b(?float $v): string {
    if ($v === null) return '';
    return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
}
function mention_mat_b(float $a): string {
    if ($a >= 14) return "Comp. TB\nacquises";
    if ($a >= 12) return "Comp.\nacquises";
    if ($a >= 10) return "Comp. moy.\nacquises";
    return "Comp.\nnon acq.";
}
function lettre_groupe_b(float $a): string {
    if ($a >= 16) return 'A'; if ($a >= 14) return 'B'; if ($a >= 12) return 'C+';
    if ($a >= 10) return 'C'; if ($a >= 8)  return 'D'; return 'E';
}
function mention_gen_b(float $a): string {
    if ($a >= 16) return 'Excellent'; if ($a >= 14) return 'Tres bien';
    if ($a >= 12) return 'Bien'; if ($a >= 10) return 'Assez bien';
    if ($a >= 8)  return 'Passable'; return 'Insuffisant';
}
function rang_eleve_b(float $avg, array $avgs_sorted): int {
    $r = 1; foreach ($avgs_sorted as $a) { if ($a > $avg) $r++; else break; } return $r;
}

// ── Matières avec au moins une note (pour seuil 50% classement) ──
$mats_avec_notes = [];
foreach ($disciplines as $d) {
    foreach ($seq_ids as $sid) {
        if (($notes_count[$d['id_mat']][$sid] ?? 0) > 0) { $mats_avec_notes[] = $d['id_mat']; break; }
    }
}
$nb_mats_avec_notes = count($mats_avec_notes);

// Calcul moyenne+classement d'un élève : retourne [moy, est_classe, coef_total]
function moy_eleve_bull(int $eid, array $discs, array $seq_ids, array $ni, array $aj, array $nc, int $nb_ins, array $mats_avec_notes, int $nb_mats_an): array {
    $tot = 0; $coef = 0; $nb_data = 0;
    foreach ($discs as $d) {
        if (!in_array($d['id_mat'], $mats_avec_notes)) continue;
        $avg = mat_avg_bull($eid, $d['id_mat'], $seq_ids, $ni, $aj, $nc, $nb_ins);
        if ($avg !== null) { $tot += $avg * $d['coef']; $coef += $d['coef']; $nb_data++; }
    }
    $moy = $coef > 0 ? $tot / $coef : null;
    $classe_ok = $nb_mats_an > 0 && $nb_data >= ceil($nb_mats_an / 2);
    return [$moy, $classe_ok, $coef];
}

// Moyennes classe (pour rang)
$all_moys = [];
foreach ($enrolled_ids as $eid) {
    [$m, $ok] = moy_eleve_bull($eid, $disciplines, $seq_ids, $notes_idx, $abs_just, $notes_count, $nb_inscrits, $mats_avec_notes, $nb_mats_avec_notes);
    if ($ok && $m !== null) $all_moys[$eid] = $m;
}
arsort($all_moys);

// Données élève courant
[$moy_generale, $est_classe] = moy_eleve_bull($id_eleve, $disciplines, $seq_ids, $notes_idx, $abs_just, $notes_count, $nb_inscrits, $mats_avec_notes, $nb_mats_avec_notes);
$rang_general = null;
if ($est_classe && $moy_generale !== null) {
    $rang_general = 1;
    foreach ($all_moys as $m) { if ($m > $moy_generale) $rang_general++; else break; }
}
$moy_premier   = !empty($all_moys) ? max($all_moys) : null;
$moy_dernier   = !empty($all_moys) ? min($all_moys) : null;
$moy_classe    = !empty($all_moys) ? array_sum($all_moys) / count($all_moys) : null;
$nb_classes    = count($all_moys);
$nb_admis      = count(array_filter($all_moys, fn($m) => $m >= 10));
$taux_reussite = $nb_classes > 0 ? round($nb_admis / $nb_classes * 100, 2) : 0;

// Stats par matière (min/avg/max/rang)
$mat_stats = [];
foreach ($disciplines as $d) {
    $id_mat = $d['id_mat'];
    $avgs = [];
    foreach ($enrolled_ids as $eid) {
        $avg = mat_avg_bull($eid, $id_mat, $seq_ids, $notes_idx, $abs_just, $notes_count, $nb_inscrits);
        if ($avg !== null) $avgs[] = $avg;
    }
    if (empty($avgs)) {
        $mat_stats[$id_mat] = ['min' => null, 'avg' => null, 'max' => null, 'avgs_sorted' => []];
    } else {
        rsort($avgs);
        $mat_stats[$id_mat] = ['min' => min($avgs), 'avg' => array_sum($avgs) / count($avgs), 'max' => max($avgs), 'avgs_sorted' => $avgs];
    }
}

// Rappel par séquence pour la colonne "RÉSULTATS DE L'ÉLÈVE"
$seq1 = $seqs_trim[0] ?? null;
$seq2 = $seqs_trim[1] ?? null;
$moy_rappel1 = null; $moy_rappel2 = null;
$rang_rappel1 = null; $rang_rappel2 = null;
$moy_prem_s1 = null; $moy_prem_s2 = null;
$moy_dern_s1 = null; $moy_dern_s2 = null;

foreach ([0 => &$moy_rappel1, 1 => &$moy_rappel2] as $idx => &$moy_ref) {
    $s = $seqs_trim[$idx] ?? null;
    if (!$s) continue;
    $sid = (int)$s['id'];
    $tp = 0; $tc = 0;
    foreach ($disciplines as $d) {
        [$v, $ex] = note_eff_bull($id_eleve, $d['id_mat'], $sid, $notes_idx, $abs_just, $notes_count, $nb_inscrits);
        if (!$ex && $v !== null) { $tp += $v * $d['coef']; $tc += $d['coef']; }
    }
    if ($tc > 0) $moy_ref = $tp / $tc;
    // Moyennes classe pour cette seq (rang rappel)
    $moys_s = [];
    foreach ($enrolled_ids as $eid) {
        $tp2 = 0; $tc2 = 0;
        foreach ($disciplines as $d) {
            [$v2, $ex2] = note_eff_bull($eid, $d['id_mat'], $sid, $notes_idx, $abs_just, $notes_count, $nb_inscrits);
            if (!$ex2 && $v2 !== null) { $tp2 += $v2 * $d['coef']; $tc2 += $d['coef']; }
        }
        if ($tc2 > 0) $moys_s[$eid] = $tp2 / $tc2;
    }
    arsort($moys_s);
    if ($idx === 0) { $moy_prem_s1 = !empty($moys_s) ? max($moys_s) : null; $moy_dern_s1 = !empty($moys_s) ? min($moys_s) : null; }
    if ($idx === 1) { $moy_prem_s2 = !empty($moys_s) ? max($moys_s) : null; $moy_dern_s2 = !empty($moys_s) ? min($moys_s) : null; }
    if ($moy_ref !== null) {
        $rr = 1; foreach ($moys_s as $m) { if ($m > $moy_ref) $rr++; else break; }
        if ($idx === 0) $rang_rappel1 = $rr; else $rang_rappel2 = $rr;
    }
}
unset($moy_ref);

// ── Génération PDF ────────────────────────────────────────────────
$pdf = new FPDF('P', 'mm', 'A4');
$pdf->SetMargins(8, 8, 8);
$pdf->SetAutoPageBreak(true, 8);
$pdf->AddPage();
$pw = $pdf->GetPageWidth();
$ml = 8; $mr = 8;
$uw = $pw - $ml - $mr; // 194

// ── 1. En-tête bilingue ──────────────────────────────────────────
$col3 = $uw / 3;
$y0   = 8;

$pdf->SetXY($ml, $y0);
$pdf->SetFont('Arial', '', 6.5);
$pdf->MultiCell($col3, 3.5, u(
    "REPUBLIQUE DU CAMEROUN\nPaix - Travail - Patrie\n***************\n" .
    ($etab['region_fr'] ?? "VOTRE REGION ICI") . "\n" .
    ($etab['departement_fr'] ?? 'DEPARTEMENT ') . "\n" .
    ($etab['arrondissement_fr'] ?? 'ARRONDISSEMENT') . "\n" .
    strtoupper($etab['nom_fr'] ?? 'NOM ECOLE ') . "\n" .
    "B.P. " . ($etab['boite_postale'] ?? 'XX') . " ville Tel.: " . ($etab['telephone'] ?? '') . "\n" .
    ($etab['email'] ?? 'adresse email')
), 0, 'C');

$logo_path = !empty($etab['logo']) ? __DIR__ . '/../../../assets/uploads/' . $etab['logo'] : '';
$logo_x = $ml + $col3 + ($col3 - 20) / 2;
if ($logo_path && is_file($logo_path)) {
    $pdf->Image($logo_path, $logo_x, $y0, 20);
} else {
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetXY($logo_x, $y0 + 2);
    $pdf->Cell(20, 16, u($etab['sigle'] ?? 'LTM'), 1, 0, 'C');
}

$xr = $ml + $col3 * 2;
$pdf->SetXY($xr, $y0);
$pdf->SetFont('Arial', '', 6.5);
$pdf->MultiCell($col3, 3.5, u(
    "REPUBLIC OF CAMEROON\nPeace - Work - Fatherland\n***************\n" .
    ($etab['region_en'] ?? 'ADAMAWA REGION') . "\n" .
    ($etab['division_en'] ?? 'VINA DIVISION') . "\n" .
    ($etab['subdivision_en'] ?? 'MBE SUBDIVISION') . "\n" .
    strtoupper($etab['nom_en'] ?? 'GTHS OF MBE') . "\n" .
    "P.O. BOX. " . ($etab['boite_postale'] ?? '32') . " Mbe  Phone: " . ($etab['telephone'] ?? '') . "\n" .
    ($etab['email'] ?? 'lyceetechniquembe@yahoo.fr')
),
0, 'C');

$pdf->SetY(max($pdf->GetY(), 33));
$pdf->SetFont('Arial', 'I', 7);
$pdf->SetX($ml);
$pdf->Cell($uw, 4, u('IMMATRICULATION : ' . ($etab['immatriculation'] ?? '2JH1TEFD110316102')), 0, 1, 'C');

// ── 2. Titre ─────────────────────────────────────────────────────
$pdf->SetFont('Arial', 'BI', 12);
$pdf->SetX($ml);
$pdf->Cell($uw, 7, u('BULLETIN SCOLAIRE DU ' . $titre_bull), 1, 1, 'C');
$pdf->SetFont('Arial', '', 8);
$pdf->SetX($ml);
$pdf->Cell($uw, 5, u('Annee scolaire : ' . $val_annee), 1, 1, 'C');

// ── 3. Infos élève ───────────────────────────────────────────────
$pdf->Ln(0.5);
$pdf->SetFillColor(230, 230, 230);
$pdf->SetFont('Arial', 'B', 7);

$wL1 = [28, 44, 20, 18, 20, 64];
$pdf->SetX($ml);
$pdf->Cell($wL1[0], 5, 'CLASSE :', 1, 0, 'L', true);
$pdf->SetFont('Arial', '', 7);
$pdf->Cell($wL1[1], 5, u($insc['classe'] ?? ''), 1, 0, 'L');
$pdf->SetFont('Arial', 'B', 7);
$pdf->Cell($wL1[2], 5, 'EFFECTIF :', 1, 0, 'L', true);
$pdf->SetFont('Arial', '', 7);
$pdf->Cell($wL1[3], 5, (string)$nb_inscrits, 1, 0, 'C');
$pdf->SetFont('Arial', 'B', 7);
$pdf->Cell($wL1[4], 5, 'MATRICULE :', 1, 0, 'L', true);
$pdf->SetFont('Arial', '', 7);
$pdf->Cell($wL1[5], 5, $eleve['matricule'] ?? '', 1, 1, 'L');

$pdf->SetX($ml);
$pdf->SetFont('Arial', 'B', 7);
$pdf->Cell(30, 5, u('NOM ET PRENOMS :'), 1, 0, 'L', true);
$pdf->Cell(110, 5, u(strtoupper($eleve['nom']) . ' ' . ($eleve['prenom'] ?? '')), 1, 0, 'L');
$pdf->Cell(16, 5, 'GENRE :', 1, 0, 'L', true);
$pdf->SetFont('Arial', '', 7);
$pdf->Cell(38, 5, $eleve['sexe'] ?? '', 1, 1, 'C');

$pdf->SetX($ml);
$pdf->SetFont('Arial', 'B', 7);
$pdf->Cell(18, 5, u('NE(E) LE :'), 1, 0, 'L', true);
$pdf->SetFont('Arial', '', 7);
$dnaiss = $eleve['date_naiss'] ? date('d/m/Y', strtotime($eleve['date_naiss'])) : '';
$pdf->Cell(24, 5, $dnaiss, 1, 0, 'C');
$pdf->SetFont('Arial', 'B', 7);
$pdf->Cell(10, 5, 'A/at', 1, 0, 'C', true);
$pdf->SetFont('Arial', '', 7);
$pdf->Cell(80, 5, u($eleve['lieu_naiss'] ?? ''), 1, 0, 'L');
$pdf->SetFont('Arial', 'B', 7);
$pdf->Cell(22, 5, 'REDOUBLANT :', 1, 0, 'L', true);
$pdf->SetFont('Arial', '', 7);
$is_redoub = (strtolower($insc['statut'] ?? '') === 'redoublant');
$pdf->Cell(40, 5, $is_redoub ? 'Oui [X]  Non [ ]' : 'Oui [ ]  Non [X]', 1, 1, 'C');

$pdf->SetX($ml);
$pdf->SetFont('Arial', 'B', 7);
$pdf->Cell(30, 5, 'PROF PRINCIPAL :', 1, 0, 'L', true);
$pdf->SetFont('Arial', '', 7);
$pdf->Cell(164, 5, u($nom_pp), 1, 1, 'L');

$pdf->SetX($ml);
$pdf->SetFont('Arial', 'B', 7);
$pdf->Cell(44, 5, u('NOM ET CONTACTS DES PARENTS :'), 1, 0, 'L', true);
$pdf->SetFont('Arial', '', 7);
$pdf->Cell(150, 5, u($contacts_parents), 1, 1, 'L');

// ── 4. Tableau des disciplines ────────────────────────────────────
$pdf->Ln(1);
$row_h = 5; $hdr_h = 5.5;

// Largeurs colonnes selon mode
$cD   = 52;
$cCF  = 9;
$cNXC = 13;
$cRG  = 9;
$cMEN = 26;
$cMIN = 9;
$cMOY = 9;
$cMAX = 9;

if ($is_seq) {
    // Séquence unique : une colonne NOTE, pas de TRIM
    $cNOT = 15;
    $cENS = $uw - $cD - $cNOT - $cCF - $cNXC - $cRG - $cMEN - $cMIN - $cMOY - $cMAX; // 43
} else {
    // Trimestriel : E1, E2, ..., TRIM
    $cEval = 10; // par séquence
    $cTR   = 11;
    $cENS  = $uw - $cD - $cEval * $nb_seqs - $cTR - $cCF - $cNXC - $cRG - $cMEN - $cMIN - $cMOY - $cMAX;
    if ($cENS < 12) $cENS = 12;
}

// En-tête tableau
$pdf->SetFont('Arial', 'B', 6.5);
$pdf->SetFillColor(0, 0, 0);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetX($ml);
$pdf->Cell($cD, $hdr_h, 'DISCIPLINES', 1, 0, 'C', true);
if ($is_seq) {
    $slib = $seqs_trim[0]['libelle'] ?? 'Eval';
    $pdf->Cell($cNOT, $hdr_h, u($slib), 1, 0, 'C', true);
} else {
    foreach ($seqs_trim as $s) {
        $pdf->Cell($cEval, $hdr_h, u($s['libelle']), 1, 0, 'C', true);
    }
    $pdf->Cell($cTR, $hdr_h, 'TRIM', 1, 0, 'C', true);
}
$pdf->Cell($cCF,  $hdr_h, 'COEF',    1, 0, 'C', true);
$pdf->Cell($cNXC, $hdr_h, '(NXC)',   1, 0, 'C', true);
$pdf->Cell($cRG,  $hdr_h, 'RANG',    1, 0, 'C', true);
$pdf->Cell($cMEN, $hdr_h, 'MENTIONS',1, 0, 'C', true);
$pdf->Cell($cMIN, $hdr_h, 'MIN',     1, 0, 'C', true);
$pdf->Cell($cMOY, $hdr_h, 'MOY',     1, 0, 'C', true);
$pdf->Cell($cMAX, $hdr_h, 'MAX',     1, 0, 'C', true);
$pdf->Cell($cENS, $hdr_h, u('ENSEIGNANT ET SIGNATURE'), 1, 1, 'C', true);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFillColor(255, 255, 255);

// Regrouper disciplines par groupe
$grouped = [];
foreach ($disciplines as $d) { $grouped[$d['groupe_lib']][] = $d; }
$ordre_g = ['ENSEIGNEMENT GÉNÉRAL', 'ENSEIGNEMENT PROFESSIONNEL', 'AUTRES ENSEIGNEMENTS'];
$groupes_tries = [];
foreach ($ordre_g as $gl) {
    foreach ($grouped as $k => $v) {
        if (stripos($k, str_replace('ENSEIGNEMENT ', '', $gl)) !== false || $k === $gl) {
            $groupes_tries[$k] = $v; break;
        }
    }
}
foreach ($grouped as $k => $v) { if (!isset($groupes_tries[$k])) $groupes_tries[$k] = $v; }

foreach ($groupes_tries as $groupe_lib => $mats) {
    $grp_pts = 0; $grp_coef = 0;

    foreach ($mats as $d) {
        $id_mat = (int)$d['id_mat'];
        $avg    = mat_avg_bull($id_eleve, $id_mat, $seq_ids, $notes_idx, $abs_just, $notes_count, $nb_inscrits);
        $stat   = $mat_stats[$id_mat] ?? ['min' => null, 'avg' => null, 'max' => null, 'avgs_sorted' => []];
        $rang   = ($avg !== null && !empty($stat['avgs_sorted'])) ? rang_eleve_b($avg, $stat['avgs_sorted']) : null;
        $nxc    = ($avg !== null) ? $avg * $d['coef'] : null;
        $men    = ($avg !== null) ? mention_mat_b($avg) : '';

        if ($avg !== null) { $grp_pts += $avg * $d['coef']; $grp_coef += $d['coef']; }

        $bg = ($avg !== null && $avg < 10) ? [255, 235, 235] : [255, 255, 255];
        $pdf->SetFillColor(...$bg);
        $pdf->SetFont('Arial', '', 6.5);
        $pdf->SetX($ml);
        $pdf->Cell($cD, $row_h, u($d['matiere']), 1, 0, 'L', true);

        if ($is_seq) {
            $sid0 = $seq_ids[0] ?? 0;
            [$nv, $ex] = note_eff_bull($id_eleve, $id_mat, $sid0, $notes_idx, $abs_just, $notes_count, $nb_inscrits);
            $ns = $ex ? 'Abs.J' : ($nv !== null ? fmt_note_b($nv) : '');
            $pdf->SetFont('Arial', 'B', 6.5);
            if ($avg !== null && $avg < 10) $pdf->SetTextColor(180, 0, 0);
            elseif ($avg !== null)          $pdf->SetTextColor(0, 100, 0);
            $pdf->Cell($cNOT, $row_h, $ns, 1, 0, 'C', true);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont('Arial', '', 6.5);
        } else {
            foreach ($seq_ids as $sid) {
                [$nv, $ex] = note_eff_bull($id_eleve, $id_mat, $sid, $notes_idx, $abs_just, $notes_count, $nb_inscrits);
                $ns = $ex ? 'Abs.J' : ($nv !== null ? fmt_note_b($nv) : '');
                $pdf->Cell($cEval, $row_h, $ns, 1, 0, 'C', true);
            }
            if ($avg !== null) {
                $pdf->SetFont('Arial', 'B', 6.5);
                if ($avg >= 10) $pdf->SetTextColor(0, 100, 0); else $pdf->SetTextColor(180, 0, 0);
            }
            $pdf->Cell($cTR, $row_h, $avg !== null ? fmt_note_b($avg) : '', 1, 0, 'C', true);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont('Arial', '', 6.5);
        }

        $pdf->Cell($cCF,  $row_h, (string)$d['coef'], 1, 0, 'C', true);
        $pdf->Cell($cNXC, $row_h, $nxc !== null ? fmt_note_b($nxc) : '', 1, 0, 'C', true);
        $pdf->Cell($cRG,  $row_h, $rang !== null ? (string)$rang : '', 1, 0, 'C', true);

        $x_men = $pdf->GetX(); $y_men = $pdf->GetY();
        $pdf->MultiCell($cMEN, $row_h / 2 + 0.3, u($men), 1, 'C', true);
        $pdf->SetXY($x_men + $cMEN, $y_men);

        $pdf->Cell($cMIN, $row_h, $stat['min'] !== null ? fmt_note_b($stat['min']) : '', 1, 0, 'C', true);
        $pdf->Cell($cMOY, $row_h, $stat['avg'] !== null ? fmt_note_b($stat['avg']) : '', 1, 0, 'C', true);
        $pdf->Cell($cMAX, $row_h, $stat['max'] !== null ? fmt_note_b($stat['max']) : '', 1, 0, 'C', true);

        $ens_lib = mb_strimwidth($d['enseignant'] ?? '', 0, $is_seq ? 45 : 28, '...');
        $pdf->SetFont('Arial', '', 5.5);
        $pdf->Cell($cENS, $row_h, u($ens_lib), 1, 0, 'L', true);
        $pdf->SetFont('Arial', '', 6.5);
        $pdf->Ln();
    }

    // Ligne total du groupe
    $grp_moy    = $grp_coef > 0 ? $grp_pts / $grp_coef : null;
    $grp_lettre = $grp_moy !== null ? lettre_groupe_b($grp_moy) : '';
    $pdf->SetFont('Arial', 'B', 6.5);
    $pdf->SetFillColor(220, 220, 220);
    $pdf->SetX($ml);
    $pdf->Cell($cD, $row_h, u('TOTAL ' . strtoupper($groupe_lib)), 1, 0, 'L', true);
    if ($is_seq) {
        $pdf->Cell($cNOT, $row_h, '', 1, 0, 'C', true);
    } else {
        $pdf->Cell($cEval * $nb_seqs, $row_h, '', 1, 0, 'C', true);
        $pdf->SetTextColor(0, 0, 180);
        $pdf->Cell($cTR, $row_h, $grp_moy !== null ? fmt_note_b($grp_moy) : '', 1, 0, 'C', true);
        $pdf->SetTextColor(0, 0, 0);
    }
    $pdf->Cell($cCF,  $row_h, (string)$grp_coef, 1, 0, 'C', true);
    $pdf->Cell($cNXC, $row_h, $grp_pts > 0 ? fmt_note_b($grp_pts) : '', 1, 0, 'C', true);
    $pdf->Cell($cRG + $cMEN + $cMIN + $cMOY + $cMAX, $row_h, '', 1, 0, 'C', true);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell($cENS, $row_h, $grp_lettre, 1, 1, 'C', true);
    $pdf->SetFillColor(255, 255, 255);
    $pdf->SetFont('Arial', '', 6.5);
}

// ── 5. Section bas : 3 colonnes ──────────────────────────────────
$pdf->Ln(1);
$y_bas    = $pdf->GetY();
$wB_left  = 52;
$wB_mid   = 72;
$wB_right = $uw - $wB_left - $wB_mid;
$h_bas    = 5;

// Gauche : absences / mentions conduite
$pdf->SetXY($ml, $y_bas);
$pdf->SetFont('Arial', 'B', 6);
$pdf->SetFillColor(200, 200, 200);
$pdf->Cell($wB_left, $h_bas, u('DISCIPLINES / TRAVAIL'), 1, 1, 'C', true);
$pdf->SetFillColor(255, 255, 255);
$pdf->SetFont('Arial', '', 6);
$left_rows = [
    [u('Absences Jus.'), '0', u("Tableau d'honneur"), '[ ]'],
    ['Absences NJ.',     '0', 'Encouragement',        '[ ]'],
    [u('Exclusion(jrs)'), '---', u('Felicitations'),  '[ ]'],
    ['Avert. conduite',  '[ ]', 'Avert. Travail',     '[ ]'],
    [u('Blame conduite'), '[ ]', u('Blame Travail'),  '[ ]'],
];
foreach ($left_rows as $lr) {
    $pdf->SetX($ml);
    $pdf->Cell($wB_left / 4 - 1, $h_bas, $lr[0], 1, 0, 'L');
    $pdf->Cell($wB_left / 4 - 1, $h_bas, $lr[1], 1, 0, 'C');
    $pdf->Cell($wB_left / 2 + 2, $h_bas, $lr[2] . ' ' . $lr[3], 1, 1, 'L');
}

// Centre : profil de la classe
$pdf->SetXY($ml + $wB_left, $y_bas);
$pdf->SetFont('Arial', 'B', 6);
$pdf->SetFillColor(200, 200, 200);
$pdf->Cell($wB_mid, $h_bas, u('PROFIL DE LA CLASSE'), 1, 0, 'C', true);
$pdf->SetXY($ml + $wB_left, $y_bas + $h_bas);
$pdf->SetFillColor(255, 255, 255);
$pdf->SetFont('Arial', '', 6);
$prof_rows = [
    [u('Moy. de la classe'), $moy_classe  !== null ? fmt_note_b($moy_classe)  : '-'],
    [u('Moy. du premier'),   $moy_premier !== null ? fmt_note_b($moy_premier) : '-'],
    [u('Moy. du dernier'),   $moy_dernier !== null ? fmt_note_b($moy_dernier) : '-'],
    [u('Effectif Classe'),   (string)$nb_classes],
    [u('Taux de reussite'),  $taux_reussite . '%'],
];
foreach ($prof_rows as $pr) {
    $pdf->SetX($ml + $wB_left);
    $pdf->Cell($wB_mid * 0.55, $h_bas, $pr[0], 1, 0, 'L');
    $pdf->Cell($wB_mid * 0.45, $h_bas, $pr[1], 1, 1, 'C');
}

// Droite : résultats de l'élève
$xR = $ml + $wB_left + $wB_mid;
$pdf->SetXY($xR, $y_bas);
$pdf->SetFont('Arial', 'B', 6);
$pdf->SetFillColor(200, 200, 200);
$pdf->Cell($wB_right, $h_bas, u("RESULTATS DE L'ELEVE"), 1, 0, 'C', true);
$pdf->SetXY($xR, $y_bas + $h_bas);
$pdf->SetFillColor(255, 255, 255);
$pdf->SetFont('Arial', '', 6);
$wRc = $wB_right / 4;
$pdf->SetX($xR);
$pdf->Cell($wRc * 2, $h_bas, 'Rappel', 1, 0, 'C');
$pdf->Cell($wRc,     $h_bas, u($seq1 ? $seq1['libelle'] : ''), 1, 0, 'C');
$pdf->Cell($wRc,     $h_bas, u($seq2 ? $seq2['libelle'] : ''), 1, 1, 'C');
$res_rows = [
    ['Moy',          $moy_rappel1  !== null ? fmt_note_b($moy_rappel1)  : '-', $moy_rappel2  !== null ? fmt_note_b($moy_rappel2)  : '-'],
    ['Rang',         $rang_rappel1 !== null ? (string)$rang_rappel1     : '-', $rang_rappel2 !== null ? (string)$rang_rappel2     : '-'],
    [u('Moy premier'), $moy_prem_s1 !== null ? fmt_note_b($moy_prem_s1) : '-', $moy_prem_s2 !== null ? fmt_note_b($moy_prem_s2) : '-'],
    [u('Moy Dernier'), $moy_dern_s1 !== null ? fmt_note_b($moy_dern_s1) : '-', $moy_dern_s2 !== null ? fmt_note_b($moy_dern_s2) : '-'],
];
foreach ($res_rows as $rr) {
    $pdf->SetX($xR);
    $pdf->Cell($wRc * 2, $h_bas, $rr[0], 1, 0, 'L');
    $pdf->Cell($wRc,     $h_bas, $rr[1], 1, 0, 'C');
    $pdf->Cell($wRc,     $h_bas, $rr[2], 1, 1, 'C');
}

// Moyenne + rang encadrés
$pdf->SetX($xR);
$pdf->SetFont('Arial', 'B', 7);
$pdf->SetFillColor(230, 230, 230);
$moy_str     = $moy_generale !== null ? fmt_note_b($moy_generale) . '/20' : '-/20';
$rang_str    = $rang_general !== null ? $rang_general . 'e / ' . $nb_classes : '-';
$mention_str = $moy_generale !== null ? mention_gen_b($moy_generale) : '';
$pdf->Cell($wB_right / 2, 5, u('MOYENNE : ' . $moy_str), 1, 0, 'C', true);
$pdf->Cell($wB_right / 2, 5, u('RANG : ' . $rang_str),   1, 1, 'C', true);
$pdf->SetX($xR);
$pdf->SetFont('Arial', 'B', 8);
$pdf->SetTextColor(0, 0, 180);
$pdf->Cell($wB_right, 5, u($mention_str), 1, 1, 'C', true);
$pdf->SetTextColor(0, 0, 0);

// ── 6. Décision du conseil ────────────────────────────────────────
$pdf->Ln(1);
$y_dec = $pdf->GetY();
$w_dec = $uw * 0.55; $w_obs = $uw - $w_dec;
$pdf->SetX($ml);
$pdf->SetFont('Arial', 'B', 6.5);
$pdf->SetFillColor(200, 200, 200);
$pdf->Cell($w_dec, $h_bas, u('DECISION DU CONSEIL DE CLASSE ET DE DISCIPLINE'), 1, 0, 'C', true);
$pdf->Cell($w_obs, $h_bas, u("OBSERVATIONS DU CHEF D'ETABLISSEMENT"),           1, 1, 'C', true);

$decisions = [
    u('Satisfaisant, doit perseverer / Satisfactory, must persevere'),
    'Travail en baisse / Work falling',
    'Travail insuffisant / Insufficient work',
    u('Attention a la conduite / Watch out for behavior'),
    u("Trop d'absences / too much absences"),
    u("Risque l'exclusion definitive / Risk the definitive exclusion"),
    'Exclusion / Exclusion',
];
$pdf->SetFillColor(255, 255, 255);
$x_dec = $ml; $x_obs = $ml + $w_dec; $y_dec2 = $pdf->GetY();
foreach ($decisions as $i => $dec) {
    $pdf->SetXY($x_dec, $y_dec2 + $i * $h_bas);
    $pdf->SetFont('Arial', '', 6);
    $chk = ($i === 0 && $moy_generale !== null && $moy_generale >= 10) ? '[X]' : '[ ]';
    $pdf->Cell($w_dec, $h_bas, $chk . ' ' . $dec, 1, 0, 'L');
}
$pdf->SetXY($x_obs, $y_dec2);
$pdf->SetFont('Arial', '', 6);
$pdf->MultiCell($w_obs, count($decisions) * $h_bas, '', 1, 'L');

// ── 7. Date et signature ─────────────────────────────────────────
$pdf->Ln(2);
$pdf->SetFont('Arial', '', 7);
$pdf->SetX($ml + $uw * 0.55);
$pdf->Cell($uw * 0.45, 5, u('Mbe, le ' . date('d-m-Y') . '.'), 0, 1, 'R');
$pdf->SetX($ml + $uw * 0.6);
$pdf->SetFont('Arial', 'B', 7);
$pdf->Cell($uw * 0.35, 5, 'LE PROVISEUR,', 0, 1, 'C');
$pdf->SetX($ml + $uw * 0.6);
$pdf->SetFont('Arial', 'I', 6.5);
$pdf->Cell($uw * 0.35, 4, 'The Principal', 0, 1, 'C');
$pdf->Ln(12);
$pdf->SetX($ml + $uw * 0.6);
$pdf->SetFont('Arial', '', 7);
$pdf->Cell($uw * 0.35, 0, '', 'T', 1, 'C');

// ── Sortie ────────────────────────────────────────────────────────
$mode     = ($_GET['dl'] ?? '') === '1' ? 'D' : 'I';
$filename = 'bulletin_' . ($eleve['matricule'] ?? $id_eleve) . '_' . preg_replace('/\W+/', '_', $titre_bull) . '.pdf';
$pdf->Output($mode, $filename);