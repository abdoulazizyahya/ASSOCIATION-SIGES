<?php
/**
 * RELEVE DE NOTES DES EVALUATIONS — tableau matriciel d'une classe (une
 * ligne par élève, une colonne par matière) pour une séquence ou un
 * trimestre — modèle fourni par l'utilisateur (PDF joint). Imprimable
 * AVANT le conseil de classe (colonne Observations vide) et APRES (la
 * décision enregistrée via secondaire/pages/conseil_classe/index.php — table
 * decision_conseil — y apparaît automatiquement). Voir pdf_releve_annuel.php
 * pour la version annuelle (moyenne = moyenne des 3 moyennes trimestrielles).
 * GET : classe, trim|seq, annee, dl (0|1)
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
$id_trim   = (int)($_GET['trim']   ?? 0);
$id_seq    = (int)($_GET['seq']    ?? 0);
$id_annee  = (int)($_GET['annee']  ?? 0);
$ordre     = in_array($_GET['ordre'] ?? '', ['alpha', 'merite']) ? $_GET['ordre'] : 'alpha';
$dl        = ($_GET['dl'] ?? '0') === '1';

if (!$id_classe) die('Classe manquante.');
if (!$id_trim && !$id_seq) die('Periode manquante.');

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

// Les compétences (chantier APC) sont scopées par trimestre entier, sans
// sous-division par séquence — ?seq= n'est donc conservé que pour résoudre
// un ancien lien vers son trimestre, comme secondaire/pages/bulletins/pdf.php (voir
// prompt_continuite, 07/08/2026, Phase 6). Le conseil de classe ne délibère
// qu'au trimestre/à l'année (table decision_conseil).
if ($id_seq && !$id_trim) {
    $seq_info = db_one("SELECT s.*, t.id AS id_trim, t.libelle AS trimestre FROM sequence s JOIN trimestre t ON t.id=s.id_trim WHERE s.id=?", [$id_seq]);
    if (!$seq_info) die('Sequence introuvable.');
    $id_trim = (int)$seq_info['id_trim'];
}
$trim = db_one("SELECT * FROM trimestre WHERE id=?", [$id_trim]);
if (!$trim) die('Trimestre introuvable.');
$titre_periode = 'TRIMESTRE : ' . strtoupper($trim['libelle']);

$etab = get_etablissement();

// ── Élèves + disciplines de la classe ───────────────────────────────────
$eleves = db_all(
    "SELECT e.id, e.matricule, e.nom, e.prenom FROM eleve e
     JOIN inscription i ON i.id_eleve=e.id AND i.id_classe=? AND i.id_annee=?
     WHERE e.statut='actif'",
    [$id_classe, $id_annee]
);
$nb_inscrits = count($eleves);

// ── Compétences (APC) du trimestre + notes (fonctions.php, chargement
// factorisé) ─────────────────────────────────────────────────────────
$dcomp = pv_charger_donnees_comp($id_classe, $id_trim, $id_annee);
$disciplines = db_all(
    "SELECT d.id_mat, d.coef, d.ordre, m.libelle AS matiere
     FROM discipline d JOIN matiere m ON m.id=d.id_mat AND m.actif=1
     WHERE d.IDClasses=? ORDER BY d.id_groupe, d.ordre, m.libelle",
    [$id_classe]
);
$nb_mat = count($disciplines);

// ── Moyenne pondérée + moyenne par matière de chaque élève (fonctions
// partagées fonctions.php) ──────────────────────────────────────────────
$moys = []; // eid => moyenne générale
$moys_mat = []; // eid => [id_mat => moyenne matière]
foreach ($eleves as $el) {
    $eid = (int)$el['id'];
    [$m, $classable] = pv_moy_generale_comp(
        $eid, $dcomp['disciplines'], $dcomp['competences_par_mat'], $dcomp['notes_idx'], $dcomp['notes_count'],
        $dcomp['nb_inscrits'], $dcomp['mats_avec_notes'], $dcomp['nb_mats_avec_notes']
    );
    if ($classable && $m !== null) $moys[$eid] = $m;
    foreach ($disciplines as $d) {
        $moys_mat[$eid][$d['id_mat']] = pv_moy_matiere_comp(
            $eid, $dcomp['competences_par_mat'][$d['id_mat']] ?? [], $dcomp['notes_idx'], $dcomp['notes_count'], $dcomp['nb_inscrits']
        );
    }
}
arsort($moys);
$nb_classes_ = count($moys);
$rangs = []; $rg = 1;
foreach ($moys as $eid => $m) { $rangs[$eid] = $rg++; }

// ── Décision du conseil de classe (uniquement en mode trimestre — le
// conseil ne délibère jamais au niveau séquence) : vide tant que le
// conseil n'a pas été saisi/enregistré (secondaire/pages/conseil_classe/index.php),
// puis rempli automatiquement après. ────────────────────────────────────
$decisions_idx = [];
$rows = db_all("SELECT * FROM decision_conseil WHERE id_classe=? AND id_annee=? AND type='trimestre' AND id_trim=?", [$id_classe, $id_annee, $id_trim]);
foreach ($rows as $r) $decisions_idx[(int)$r['id_eleve']] = $r;

// ── Ordre d'affichage : mérite (moyenne décroissante, non-classables à la
// fin) ou alphabétique (nom puis prénom) — choix explicite via GET ordre=
// alpha|merite, demande explicite (même convention que
// secondaire/pages/bulletins/pdf_classe.php). ──────────────────────────────────────
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

// Dessine un rang ("2e", ...) avec le "e" ordinal en exposant — voir
// secondaire/pages/bulletins/pdf.php pour le jumeau (cell_rang(), même logique).
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

// ── En-tête : identique à gauche et à droite (français uniquement,
// conforme au modèle fourni — contrairement aux bulletins/fiches
// statistiques qui sont bilingues FR/EN). ────────────────────────────────
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
$pdf->Cell($uw, 5.5, u($titre_periode), 0, 1, 'C');
$pdf->Ln(1);
$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell($uw / 2, 5, u('CLASSE : ' . $classe['designation']), 0, 0, 'L');
$pdf->Cell($uw / 2, 5, u('ANNEE SCOLAIRE : ' . $val_annee), 0, 1, 'R');
$pdf->Ln(1);

// ── Colonnes : fixes + une par matière (largeur restante répartie) ─────
$w_no = 8; $w_mat_id = 20; $w_nom = 45; $w_moy = 11; $w_rang = 13; $w_obs = 26;
$fixe = $w_no + $w_mat_id + $w_nom + $w_moy + $w_rang + $w_obs;
$w_sub = $nb_mat > 0 ? max(8, ($uw - $fixe) / $nb_mat) : 0;
// Si le nombre de matières est trop grand pour la largeur mini de 8mm,
// l'excédent déborde légèrement plutôt que d'écraser les colonnes fixes
// (mêmes déjà réduites au minimum lisible) — cas rare, classes à >21 matières.

$h_hdr1 = 28; $h_hdr2 = 5; $h_hdr = $h_hdr1 + $h_hdr2;
$y_hdr = $pdf->GetY();
$pdf->SetFillColor(26, 60, 107);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetDrawColor(0, 0, 0);

// Colonnes fixes (texte horizontal, centré sur toute la hauteur d'en-tête)
$x = $ml;
foreach ([['N°', $w_no], ['MATRICULE', $w_mat_id], ['NOM ET PRENOMS', $w_nom]] as [$lbl, $w]) {
    $pdf->SetXY($x, $y_hdr);
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->Cell($w, $h_hdr, u($lbl), 1, 0, 'C', true);
    $x += $w;
}

// Colonnes matières (nom en texte vertical + coefficient en dessous)
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

// ── Lignes élèves ────────────────────────────────────────────────────────
$h_row = 5;
$n = 1;
foreach ($eleves as $el) {
    $eid = (int)$el['id'];
    if ($pdf->GetY() + $h_row > $pdf->GetPageHeight() - 12) {
        $pdf->AddPage();
        pdf_filigrane($pdf, $etab, $pw, $pdf->GetPageHeight());
        $y_hdr2 = $pdf->GetY();
        $x = $ml;
        $pdf->SetFillColor(26, 60, 107);
        $pdf->SetTextColor(255, 255, 255);
        foreach ([['N°', $w_no], ['MATRICULE', $w_mat_id], ['NOM ET PRENOMS', $w_nom]] as [$lbl, $w]) {
            $pdf->SetXY($x, $y_hdr2);
            $pdf->SetFont('Arial', 'B', 7.5);
            $pdf->Cell($w, $h_hdr, u($lbl), 1, 0, 'C', true);
            $x += $w;
        }
        foreach ($disciplines as $d) {
            $pdf->Rect($x, $y_hdr2, $w_sub, $h_hdr1, 'DF');
            $taille = 7;
            $pdf->SetFont('Arial', 'B', $taille);
            while ($taille > 4 && $pdf->GetStringWidth(u($d['matiere'])) > $h_hdr1 - 2) {
                $taille -= 0.25;
                $pdf->SetFont('Arial', 'B', $taille);
            }
            $pdf->TextWithDirection($x + $w_sub / 2 + 1, $y_hdr2 + $h_hdr1 - 1, u($d['matiere']), 'U');
            $pdf->SetXY($x, $y_hdr2 + $h_hdr1);
            $pdf->SetFont('Arial', 'B', 7);
            $pdf->Cell($w_sub, $h_hdr2, (string)$d['coef'], 1, 0, 'C', true);
            $x += $w_sub;
        }
        foreach ([['MOY /20', $w_moy], ['RANG', $w_rang], ['OBSERVATIONS', $w_obs]] as [$lbl, $w]) {
            $pdf->SetXY($x, $y_hdr2);
            $pdf->SetFont('Arial', 'B', 7.5);
            $pdf->Cell($w, $h_hdr, u($lbl), 1, 0, 'C', true);
            $x += $w;
        }
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetXY($ml, $y_hdr2 + $h_hdr);
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
    pdf_signature_appliquer($pdf, 'releve_notes', 'chef_etablissement', 0, 0, $pw, $ph, [
        'x_pct' => $sx / $pw * 100, 'y_pct' => $sy / $ph * 100, 'w_pct' => $sig_w / $pw * 100, 'h_pct' => null,
    ]);
}

// ── Sortie ────────────────────────────────────────────────────────────
$mode   = $dl ? 'D' : 'I';
$suffix = preg_replace('/\W+/', '_', $classe['designation'] ?? 'classe');
$pdf->Output($mode, 'releve_' . $suffix . '.pdf');
