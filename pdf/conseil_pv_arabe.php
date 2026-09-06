<?php
// ── PDF : Procès-verbal du Conseil de Classe (piste arabe) ──────────
// Miroir de pdf/conseil_pv.php — GET : classe, type=trimestre|annee, trim
// (si type=trimestre), ordre=alpha|merite, dl=1, signature=1.
// Réutilise les mêmes calculs que pages/conseil_classe_arabe/index.php
// (classement_trimestre_classe_arabe()/classement_annuel_classe_arabe()).
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
require_once __DIR__ . '/../notes_apc_arabe.php';
exiger_acces_pedagogie();

require_once __DIR__ . '/fpdf.php';
require_once __DIR__ . '/header_pdf.php';

$id_classe = (int) ($_GET['classe'] ?? 0);
exiger_acces_classe($id_classe, get_annee_active()['val_annee'] ?? '', 'ar');   // cloisonnement enseignant
$type      = in_array($_GET['type'] ?? '', ['trimestre', 'annee'], true) ? $_GET['type'] : 'trimestre';
$id_trim   = (int) ($_GET['trim'] ?? 0);
$ordre     = in_array($_GET['ordre'] ?? '', ['alpha', 'merite'], true) ? $_GET['ordre'] : 'alpha';
$dl        = ($_GET['dl'] ?? '0') === '1';
$avec_sig  = ($_GET['signature'] ?? '0') === '1';
if (!$id_classe) die('Classe manquante.');

$annee_act = get_annee_active();
$val_annee = $annee_act['val_annee'] ?? '';

$classe_info = db_one("SELECT c.IDClasses AS id, c.DesignationClasses AS designation, c.Niveau AS niveau_lib FROM classe c WHERE c.IDClasses=?", [$id_classe]);
if (!$classe_info) die('Classe introuvable.');

$trimestres = db_all("SELECT id_trim AS id, libelle_trim AS libelle FROM trimestre WHERE id_annee=? ORDER BY id_trim", [$val_annee]);
if ($type === 'trimestre' && !$id_trim) $id_trim = $trimestres[0]['id'] ?? 0;
$trim_info = null;
foreach ($trimestres as $t) if ($t['id'] == $id_trim) { $trim_info = $t; break; }

// ── Mêmes calculs que pages/conseil_classe_arabe/index.php ──────────
$classement = $type === 'trimestre'
    ? classement_trimestre_classe_arabe($id_classe, $id_trim, $val_annee)
    : classement_annuel_classe_arabe($id_classe, $val_annee);
$moy_idx = []; $rang_idx = [];
foreach ($classement['lignes'] as $l) {
    $moy_idx[(int) $l['id_eleve']]  = $l['moy'] !== null ? (float) $l['moy'] : null;
    $rang_idx[(int) $l['id_eleve']] = $l['rang'] ?: '-';
}

$eleves_raw = db_all(
    "SELECT e.id_eleve AS id, e.Mat_elv AS matricule, e.niu, e.Nom_elv AS nom, e.Prenom_elv AS prenom
     FROM eleve e JOIN inscrire i ON i.id_eleve=e.id_eleve AND i.IDClasses=? AND i.val_annee=?
     WHERE e.statut='actif' ORDER BY e.Nom_elv, e.Prenom_elv",
    [$id_classe, $val_annee]
);

$abs_heures = [];
if ($type === 'trimestre') {
    $rows = db_all("SELECT id_eleve, nbre_jour_jus, nbre_jour_non_jus FROM absence WHERE id_trim=? AND classe=? AND val_annee=?", [$id_trim, $id_classe, $val_annee]);
    foreach ($rows as $r) $abs_heures[(int) $r['id_eleve']] = ['jus' => (int) $r['nbre_jour_jus'], 'nj' => (int) $r['nbre_jour_non_jus']];
} else {
    $rows = db_all("SELECT id_eleve, SUM(nbre_jour_jus) AS jus, SUM(nbre_jour_non_jus) AS nj FROM absence WHERE classe=? AND val_annee=? GROUP BY id_eleve", [$id_classe, $val_annee]);
    foreach ($rows as $r) $abs_heures[(int) $r['id_eleve']] = ['jus' => (int) $r['jus'], 'nj' => (int) $r['nj']];
}
$excl_jours = [];
if ($type === 'trimestre') {
    $rows = db_all("SELECT id_eleve, SUM(nbre_jours) AS j FROM exclusion WHERE id_trim=? AND classe=? AND val_annee=? GROUP BY id_eleve", [$id_trim, $id_classe, $val_annee]);
} else {
    $rows = db_all("SELECT id_eleve, SUM(nbre_jours) AS j FROM exclusion WHERE classe=? AND val_annee=? GROUP BY id_eleve", [$id_classe, $val_annee]);
}
foreach ($rows as $r) $excl_jours[(int) $r['id_eleve']] = (int) $r['j'];

$decisions_idx = [];
if ($type === 'trimestre') {
    $rows = db_all("SELECT * FROM decision_conseil_arabe WHERE classe=? AND id_trim=? AND val_annee=?", [$id_classe, $id_trim, $val_annee]);
} else {
    $rows = db_all("SELECT * FROM decision_conseil_annuel_arabe WHERE classe=? AND val_annee=?", [$id_classe, $val_annee]);
}
foreach ($rows as $r) $decisions_idx[(int) $r['id_eleve']] = $r;

foreach ($eleves_raw as &$e) {
    $eid = (int) $e['id'];
    $e['moy']     = $moy_idx[$eid] ?? null;
    $e['rang']    = $rang_idx[$eid] ?? '-';
    $e['abs_jus'] = $abs_heures[$eid]['jus'] ?? 0;
    $e['abs_nj']  = $abs_heures[$eid]['nj']  ?? 0;
    $e['excl_j']  = $excl_jours[$eid] ?? 0;
    $deja = $decisions_idx[$eid] ?? null;
    $e['decision']    = $deja['decision'] ?? '';
    $e['observation'] = $deja['observation'] ?? '';
    $e['next_classe'] = $deja['next_classe'] ?? null;
}
unset($e);

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
    foreach (db_all("SELECT IDClasses, DesignationClasses FROM classe") as $tc) $dest_noms[$tc['IDClasses']] = $tc['DesignationClasses'];
}

$etab_brut = get_etablissement();
$etab      = etab_pour_pdf($etab_brut);

class FPDF_PV_Arabe extends FPDF {
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
        $this->Cell(0, 6, pdf_u('Page ' . $this->PageNo() . '/{nb}'), 0, 0, 'C');
        // Copyright standard du système (pdf/header_pdf.php) — texte unique
        // sur tous les PDF du projet, voir pdf_copyright().
        pdf_copyright($this, $this->GetPageWidth(), $this->GetPageHeight(), 5);
    }
}

// Enveloppé dans un try/catch — voir fonctions.php::pdf_erreur_generation()
// (jamais de fatal error brut ; ce document est public via QR et/ou
// consulté par du personnel qui ne doit pas voir de trace technique).
try {
$pdf = new FPDF_PV_Arabe('L', 'mm', 'A4');
$pdf->AliasNbPages();
$pdf->etabRef = $etab;
$pdf->titreFr = 'PROCÈS-VERBAL DU CONSEIL DE CLASSE (ARABE)';
$pdf->titreEn = 'CLASS COUNCIL MINUTES (ARABIC TRACK)';
$pdf->SetAutoPageBreak(true, 20);
$pdf->AddPage();

$page_w = $pdf->GetPageWidth();
$marge  = 10;
$w      = $page_w - 2 * $marge;

$periode = $type === 'annee' ? "Conseil de fin d'année/Year-end council" : ('Conseil du/Council of ' . ($trim_info['libelle'] ?? ''));
$pdf->SetFont('Arial', 'B', 10);
$pdf->SetTextColor(20, 40, 90);
$pdf->SetX($marge);
$pdf->Cell($w, 6, pdf_u('Classe/Class : ' . $classe_info['designation'] . ($classe_info['niveau_lib'] ? ' (' . $classe_info['niveau_lib'] . ')' : '') .
    '   |   Année scolaire/Academic year : ' . $val_annee . '   |   ' . $periode), 0, 1, 'C');
$pdf->SetTextColor(0);
$pdf->Ln(1);

$cols = [
    ['N°/No', 8, 'C'], ['Matricule', 22, 'C'], ['Nom et Prénom/Name', 58, 'L'],
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
foreach ($cols as $c) $pdf->Cell($c[1], 7, pdf_u($c[0]), 1, 0, 'C', true);
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
        ? ($dest_noms[$e['next_classe']] ?? ($e['next_classe'] ? '#' . $e['next_classe'] : '-'))
        : '-';
    $vals = [$n, $e['matricule'], mb_strtoupper($e['nom']) . ' ' . $e['prenom'], $moyFmt, $e['rang'], $e['abs_jus'] . 'h', $e['abs_nj'] . 'h', $e['excl_j'] . 'j'];
    if ($type === 'annee') { $vals[] = $e['decision'] ?: '-'; $vals[] = $dest; $vals[] = $e['observation']; }
    else { $vals[] = $e['decision'] ?: 'RAS'; $vals[] = $e['observation']; }
    foreach ($cols as $i => $c) {
        $pdf->Cell($c[1], 6, pdf_u((string) $vals[$i]), 1, 0, $c[2], $fill);
    }
    $pdf->Ln();
    $n++;
}
if (empty($eleves_raw)) {
    $pdf->SetX($marge);
    $pdf->Cell($w, 8, pdf_u('Aucun élève inscrit. / No student enrolled.'), 1, 1, 'C');
}

// ── Zone de signatures (même simplification que la piste française —
// jaynitaare n'a qu'un seul signataire configurable) ─────────────────
$pdf->Ln(10);
if ($pdf->GetY() > $pdf->GetPageHeight() - 40) $pdf->AddPage();
$pdf->SetFont('Arial', '', 8);
$pdf->SetX($marge);
$pdf->Cell($w, 5, pdf_u('Fait à/Done at ' . (($etab['lieu'] ?: $etab['ville']) ?? '') . ', le/on ' . date('d/m/Y')), 0, 1, 'L');
$pdf->Ln(6);

$sigCols = [
    "L'Enseignant(e)" => ['Class Teacher', $w / 3],
    'Le/La Secrétaire' => ['Registrar', $w / 3],
    'Le Directeur'     => ['Head Teacher', $w / 3],
];
$pdf->SetFont('Arial', 'B', 8.5);
$pdf->SetX($marge);
foreach ($sigCols as $lib_fr => [$lib_en, $cw]) $pdf->Cell($cw, 5, pdf_u($lib_fr), 0, 0, 'C');
$pdf->Ln();
$pdf->SetFont('Arial', 'I', 7.5);
$pdf->SetX($marge);
foreach ($sigCols as $lib_fr => [$lib_en, $cw]) $pdf->Cell($cw, 4, pdf_u($lib_en), 0, 0, 'C');

if ($avec_sig) {
    $sig_w = 20;
    $ph = $pdf->GetPageHeight();
    $sy = $pdf->GetY() + 1;
    $x_directeur = $marge + 2 * ($w / 3);
    $sx_directeur = $x_directeur + ($w / 3 - $sig_w) / 2;
    pdf_signature_appliquer_jn($pdf, 'pv_conseil_arabe', 0, 0, $page_w, $ph, [
        'x_pct' => $sx_directeur / $page_w * 100, 'y_pct' => $sy / $ph * 100, 'w_pct' => $sig_w / $page_w * 100, 'h_pct' => null,
    ]);
}
$pdf->Ln(18);
$pdf->SetFont('Arial', 'I', 7);
$pdf->SetX($marge);
foreach ($sigCols as $lib_fr => [$lib_en, $cw]) $pdf->Cell($cw, 5, pdf_u('(Nom, signature) / (Name, signature)'), 0, 0, 'C');
$pdf->Ln();

$suffixe = str_replace(' ', '_', $classe_info['designation']) . '_' . $type . ($type === 'trimestre' ? '_' . ($trim_info['libelle'] ?? '') : '');
$pdf->Output($dl ? 'D' : 'I', "pv_conseil_arabe_{$suffixe}.pdf");
} catch (Throwable $e) {
    pdf_erreur_generation($e);
}
