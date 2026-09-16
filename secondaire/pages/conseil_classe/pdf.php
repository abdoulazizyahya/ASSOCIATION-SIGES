<?php
/**
 * secondaire/pages/conseil_classe/pdf.php — Procès-verbal du Conseil de Classe (PDF officiel)
 * GET : classe (obligatoire), type=trimestre|annee, trim (id, si type=trimestre), dl=1
 * Réutilise les mêmes calculs que index.php (pv_moy_generale() etc. de fonctions.php)
 * et les mêmes conventions FPDF que le reste du projet (pdf_entete/pdf_bandeau).
 */
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_connexion();

$role = role_connecte();
$full_access = in_array($role, ['ADMIN', 'CENSEUR', 'PROVISEUR']);
$annee_act   = get_annee_active();
$id_annee    = (int)($annee_act['id'] ?? 0);
$val_annee   = $annee_act['libelle'] ?? '';

$id_classe = (int)($_GET['classe'] ?? 0);
$type      = in_array($_GET['type'] ?? '', ['trimestre','annee'], true) ? $_GET['type'] : 'trimestre';
$id_trim   = (int)($_GET['trim'] ?? 0);
$ordre     = in_array($_GET['ordre'] ?? '', ['alpha', 'merite']) ? $_GET['ordre'] : 'alpha';
$dl        = ($_GET['dl'] ?? '0') === '1';

if (!$id_classe) die('Classe manquante.');

// ── Contrôle d'accès (même politique que index.php / Discipline) ──────
$autorise = false;
if ($full_access) { $autorise = true; }
elseif ($role === 'SG') {
    $mat_ens = get_matricule_ens_connecte();
    $autorise = (bool)db_val("SELECT 1 FROM sg WHERE matricule_ens=? AND IDClasses=? AND val_annee=?", [$mat_ens, $id_classe, $val_annee]);
} elseif ($role === 'ENSEIGNANT') {
    $mat_ens = get_matricule_ens_connecte();
    $autorise = (bool)db_val("SELECT 1 FROM enseignat_principal WHERE matricule_ens=? AND IDClasses=? AND val_annee=?", [$mat_ens, $id_classe, $val_annee]);
}
if (!$autorise) die('Accès refusé.');

$classe_info = db_one(
    "SELECT c.*, n.libelle_niv AS niveau_lib FROM classe c LEFT JOIN niveau n ON n.code_niveau=c.code_niveau WHERE c.id=?",
    [$id_classe]
);
if (!$classe_info) die('Classe introuvable.');

$trimestres = db_all("SELECT * FROM trimestre WHERE id_annee=? ORDER BY ordre", [$id_annee]);
if ($type === 'trimestre' && !$id_trim) $id_trim = $trimestres[0]['id'] ?? 0;
$trim_info = null;
foreach ($trimestres as $t) if ($t['id'] == $id_trim) { $trim_info = $t; break; }
$id_trim_save = $type === 'annee' ? 0 : $id_trim;

// ── Mêmes calculs que index.php — compétences (APC), Phase 6, voir
//    prompt_continuite du 07/08/2026 ─────────────────────────────────
$trims_pour_calc = $type === 'trimestre' ? [$id_trim] : array_column($trimestres, 'id');

$eleves_raw = db_all(
    "SELECT e.id, e.matricule, e.niu, e.nom, e.prenom FROM eleve e
     JOIN inscription i ON i.id_eleve=e.id AND i.id_classe=? AND i.id_annee=?
     WHERE e.statut='actif' ORDER BY e.nom, e.prenom",
    [$id_classe, $id_annee]
);
$enrolled_ids = array_column($eleves_raw, 'id');
$nb_inscrits  = count($enrolled_ids);

$moys_par_trim = [];
foreach ($trims_pour_calc as $tid) {
    if (!$tid || $nb_inscrits === 0) continue;
    $dcomp = pv_charger_donnees_comp($id_classe, (int)$tid, $id_annee);
    $mt = [];
    foreach ($enrolled_ids as $eid) {
        [$m, $classable] = pv_moy_generale_comp(
            $eid, $dcomp['disciplines'], $dcomp['competences_par_mat'], $dcomp['notes_idx'], $dcomp['notes_count'],
            $dcomp['nb_inscrits'], $dcomp['mats_avec_notes'], $dcomp['nb_mats_avec_notes']
        );
        $mt[$eid] = ($classable && $m !== null) ? $m : null;
    }
    $moys_par_trim[] = $mt;
}
$calc_moy_eid = function(int $eid) use ($moys_par_trim): ?float {
    $vals = [];
    foreach ($moys_par_trim as $mt) { if (($mt[$eid] ?? null) !== null) $vals[] = $mt[$eid]; }
    return !empty($vals) ? array_sum($vals) / count($vals) : null;
};

$abs_heures = [];
try {
    if ($type === 'trimestre') {
        $rows = db_all("SELECT mat_elv, nbre_heure_jus, nbre_heure_non_jus FROM absence WHERE id_trim=? AND IDClasses=? AND val_annee=?", [$id_trim, $id_classe, $val_annee]);
        foreach ($rows as $r) $abs_heures[$r['mat_elv']] = ['jus'=>(int)$r['nbre_heure_jus'], 'nj'=>(int)$r['nbre_heure_non_jus']];
    } else {
        $rows = db_all("SELECT mat_elv, SUM(nbre_heure_jus) AS jus, SUM(nbre_heure_non_jus) AS nj FROM absence WHERE IDClasses=? AND val_annee=? GROUP BY mat_elv", [$id_classe, $val_annee]);
        foreach ($rows as $r) $abs_heures[$r['mat_elv']] = ['jus'=>(int)$r['jus'], 'nj'=>(int)$r['nj']];
    }
} catch (Throwable $ex) { $abs_heures = []; }

$excl_jours = [];
try {
    if ($type === 'trimestre') {
        $rows = db_all("SELECT mat_elv, SUM(nbre_jours) AS j FROM exclusion WHERE id_trim=? AND classe=? AND val_annee=? GROUP BY mat_elv", [$id_trim, $id_classe, $val_annee]);
    } else {
        $rows = db_all("SELECT mat_elv, SUM(nbre_jours) AS j FROM exclusion WHERE classe=? AND val_annee=? GROUP BY mat_elv", [$id_classe, $val_annee]);
    }
    foreach ($rows as $r) $excl_jours[$r['mat_elv']] = (int)$r['j'];
} catch (Throwable $ex) { $excl_jours = []; }

$decisions_idx = [];
$rows = db_all("SELECT * FROM decision_conseil WHERE id_classe=? AND id_annee=? AND type=? AND id_trim=?", [$id_classe, $id_annee, $type, $id_trim_save]);
foreach ($rows as $r) $decisions_idx[$r['id_eleve']] = $r;

$all_moys = [];
foreach ($enrolled_ids as $eid) {
    $m = $calc_moy_eid($eid);
    if ($m !== null) $all_moys[$eid] = $m;
}
arsort($all_moys);
$rangs = []; $rg = 1;
foreach ($all_moys as $eid => $m) $rangs[$eid] = $rg++;

foreach ($eleves_raw as &$e) {
    $eid = $e['id'];
    $moy = $calc_moy_eid($eid);
    $e['moy']    = $moy;
    $e['rang']   = $rangs[$eid] ?? '-';
    $e['abs_jus'] = $abs_heures[$e['matricule']]['jus'] ?? 0;
    $e['abs_nj']  = $abs_heures[$e['matricule']]['nj']  ?? 0;
    $e['excl_j']  = $excl_jours[$e['matricule']] ?? 0;
    $deja = $decisions_idx[$eid] ?? null;
    $e['decision']    = $deja['decision'] ?? '';
    $e['observation'] = $deja['observation'] ?? '';
    $e['next_classe'] = $deja['next_classe'] ?? null;
}
unset($e);

// Tri d'affichage : alphabétique (ordre SQL déjà appliqué, ORDER BY nom,
// prenom) ou par mérite (moyenne décroissante, non-classables en dernier)
// — même convention que secondaire/pages/bulletins/pdf_classe.php ($ordre GET).
if ($ordre === 'merite') {
    usort($eleves_raw, function ($a, $b) {
        if ($a['moy'] === null && $b['moy'] === null) return 0;
        if ($a['moy'] === null) return 1;
        if ($b['moy'] === null) return -1;
        return $b['moy'] <=> $a['moy'];
    });
}

$dest_noms = [];
if ($type === 'annee') {
    $toutes_classes = db_all("SELECT id, designation FROM classe");
    foreach ($toutes_classes as $tc) $dest_noms[$tc['id']] = $tc['designation'];
}

// ── PDF ────────────────────────────────────────────────────────────
require_once __DIR__ . '/../../pdf/fpdf.php';
require_once __DIR__ . '/../../pdf/header_pdf.php';

function pvpdf_u(string $s): string {
    return mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8');
}

$etab = get_etablissement();

class FPDF_PV extends FPDF {
    public array $etabRef = [];
    public string $titreFr = '';
    public string $titreEn = '';
    function Header() {
        pdf_filigrane($this, $this->etabRef, $this->GetPageWidth(), $this->GetPageHeight());
        pdf_entete($this, $this->etabRef, $this->GetPageWidth());
        pdf_bandeau($this, $this->titreFr, $this->titreEn, $this->GetPageWidth());
    }
    function Footer() {
        $this->SetY(-12);
        $this->SetFont('Arial', 'I', 7);
        $this->SetTextColor(120);
        $this->Cell(0, 6, pvpdf_u('Page ' . $this->PageNo() . '/{nb}'), 0, 0, 'C');
    }
}

$pdf = new FPDF_PV('L', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->etabRef = $etab;
// Bruts (non pré-convertis) : pdf_bandeau() applique désormais sa propre
// conversion en interne — un pré-encodage ici causerait un double encodage.
$pdf->titreFr = 'PROCÈS-VERBAL DU CONSEIL DE CLASSE';
$pdf->titreEn = 'CLASS COUNCIL MINUTES';
$pdf->SetAutoPageBreak(true, 20);
$pdf->AddPage();

$page_w = $pdf->GetPageWidth();
$marge  = 10;
$w      = $page_w - 2 * $marge;

$periode = $type === 'annee' ? "Conseil de fin d'année/Year-end council" : ('Conseil du/Council of ' . ($trim_info['libelle'] ?? ''));
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetTextColor(20, 40, 90);
$pdf->SetX($marge);
$pdf->Cell($w, 6, pvpdf_u('Classe/Class : ' . $classe_info['designation'] . ($classe_info['niveau_lib'] ? ' (' . $classe_info['niveau_lib'] . ')' : '') .
    '   |   Année scolaire/Academic year : ' . $val_annee . '   |   ' . $periode), 0, 1, 'C');
$pdf->SetTextColor(0);
$pdf->Ln(1);

// ── Colonnes du tableau ──────────────────────────────────────────
$cols = [
    ['N°/No', 8, 'C'], ['NIU', 22, 'C'], ['Nom et Prénom/Name', 58, 'L'],
    ['Moy./Avg.', 14, 'C'], ['Rang/Rk', 12, 'C'], ['Abs.J', 13, 'C'], ['Abs.NJ', 13, 'C'], ['Excl.', 12, 'C'],
];
if ($type === 'annee') {
    $cols[] = ['Décision/Decision', 32, 'C']; $cols[] = ['Destination', 40, 'C']; $cols[] = ['Observation', 0, 'L'];
} else {
    $cols[] = ['Mention/Remark', 45, 'C']; $cols[] = ['Observation', 0, 'L'];
}
$fixed_w = array_sum(array_column($cols, 1));
$last_key = count($cols) - 1;
$cols[$last_key][1] = $w - $fixed_w;

$pdf->SetFont('Arial', 'B', 8);
$pdf->SetFillColor(20, 40, 90);
$pdf->SetTextColor(255);
$pdf->SetX($marge);
foreach ($cols as $c) $pdf->Cell($c[1], 7, pvpdf_u($c[0]), 1, 0, 'C', true);
$pdf->Ln();
$pdf->SetTextColor(0);
$pdf->SetFont('Arial', '', 7.5);

$n = 1;
foreach ($eleves_raw as $e) {
    if ($pdf->GetY() > $pdf->GetPageHeight() - 45) { $pdf->AddPage(); }
    $fill = ($n % 2 === 0);
    $pdf->SetFillColor(240, 240, 246);
    $pdf->SetX($marge);
    $moyFmt = $e['moy'] !== null ? number_format($e['moy'], 2) : '-';
    $dest = $type === 'annee' && $e['decision'] === 'Admis'
        ? ($dest_noms[$e['next_classe']] ?? ($e['next_classe'] ? '#'.$e['next_classe'] : '-'))
        : '-';
    $vals = [$n, id_affichage_eleve($e), $e['nom'].' '.$e['prenom'], $moyFmt, $e['rang'], $e['abs_jus'].'h', $e['abs_nj'].'h', $e['excl_j'].'j'];
    if ($type === 'annee') { $vals[] = $e['decision'] ?: '-'; $vals[] = $dest; $vals[] = $e['observation']; }
    else { $vals[] = $e['decision'] ?: 'RAS'; $vals[] = $e['observation']; }
    foreach ($cols as $i => $c) {
        $pdf->Cell($c[1], 6, pvpdf_u((string)$vals[$i]), 1, 0, $c[2], $fill);
    }
    $pdf->Ln();
    $n++;
}
if (empty($eleves_raw)) {
    $pdf->SetX($marge);
    $pdf->Cell($w, 8, pvpdf_u('Aucun élève inscrit. / No student enrolled.'), 1, 1, 'C');
}

// ── Zone de signatures ────────────────────────────────────────────
$pdf->Ln(10);
if ($pdf->GetY() > $pdf->GetPageHeight() - 40) $pdf->AddPage();
$pdf->SetFont('Arial', '', 8);
$pdf->SetX($marge);
$pdf->Cell($w, 5, pvpdf_u('Fait à/Done at ' . ($etab['ville'] ?? '') . ', le/on ' . date('d/m/Y')), 0, 1, 'L');
$pdf->Ln(6);

$sigCols = [
    'Le Professeur Principal' => ['Class Teacher',   $w / 3],
    'Le Censeur'              => ['Vice Principal',  $w / 3],
    'Le Proviseur'            => ['Principal',       $w / 3],
];
$pdf->SetFont('Arial', 'B', 8.5);
$pdf->SetX($marge);
foreach ($sigCols as $lib_fr => [$lib_en, $cw]) $pdf->Cell($cw, 5, pvpdf_u($lib_fr), 0, 0, 'C');
$pdf->Ln();
$pdf->SetFont('Arial', 'I', 7.5);
$pdf->SetX($marge);
foreach ($sigCols as $lib_fr => [$lib_en, $cw]) $pdf->Cell($cw, 4, pvpdf_u($lib_en), 0, 0, 'C');
// Signatures numériques des 3 colonnes (sur demande uniquement, jamais
// automatique) : Professeur Principal (chemin direct — un enseignant par
// classe, pas un rôle fixe unique), Censeur et Proviseur (catalogue
// signature_titulaire, chacun configuré par la personne habilitée).
if (($_GET['signature'] ?? '0') === '1') {
    $sig_w = 20;
    $ph = $pdf->GetPageHeight();
    $sy = $pdf->GetY() + 1;

    $sig_pp = signature_chemin_pp_classe($id_classe, $val_annee);
    pdf_signature_image_directe($pdf, $sig_pp, $marge + ($w / 3 - $sig_w) / 2, $sy, $sig_w);

    $x_censeur = $marge + ($w / 3);
    $sx_censeur = $x_censeur + ($w / 3 - $sig_w) / 2;
    pdf_signature_appliquer($pdf, 'pv_conseil', 'censeur', 0, 0, $page_w, $ph, [
        'x_pct' => $sx_censeur / $page_w * 100, 'y_pct' => $sy / $ph * 100, 'w_pct' => $sig_w / $page_w * 100, 'h_pct' => null,
    ]);

    $x_proviseur = $marge + 2 * ($w / 3);
    $sx_proviseur = $x_proviseur + ($w / 3 - $sig_w) / 2;
    pdf_signature_appliquer($pdf, 'pv_conseil', 'chef_etablissement', 0, 0, $page_w, $ph, [
        'x_pct' => $sx_proviseur / $page_w * 100, 'y_pct' => $sy / $ph * 100, 'w_pct' => $sig_w / $page_w * 100, 'h_pct' => null,
    ]);
}
$pdf->Ln(18);
$pdf->SetFont('Arial', 'I', 7);
$pdf->SetX($marge);
foreach ($sigCols as $lib_fr => [$lib_en, $cw]) $pdf->Cell($cw, 5, pvpdf_u('(Nom, signature) / (Name, signature)'), 0, 0, 'C');
$pdf->Ln();

$suffixe = pvpdf_u(str_replace(' ', '_', $classe_info['designation']) . '_' . $type . ($type==='trimestre' ? '_' . ($trim_info['libelle'] ?? '') : ''));
$pdf->Output($dl ? 'D' : 'I', "pv_conseil_{$suffixe}.pdf");
