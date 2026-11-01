<?php
// secondaire/commun/pages/emploi_temps/_pdf_modele_heures.php — fiche d'emploi du temps au
// modèle « heures × jours » (01/11/2026, fiche individuelle du Lycée bilingue de Lam) :
//  - titre « EMPLOI DE TEMPS INDIVIDUEL 2026/2027 » souligné + sous-titre anglais ;
//  - bloc d'informations bilingue sur 3 colonnes : noms, grade, discipline / heures
//    faites, dues, supplémentaires / classes tenues, matricule, 1re prise de service
//    (vue classe : classe, professeur principal, effectif / volume horaire, section) ;
//  - grille : une LIGNE par période (horaire « 7h30-8h25 »), une COLONNE par jour
//    (« Lundi /Monday ») ; pauses en bandes grises sur toute la largeur
//    (« Pause/Break (10 minutes) ») ; créneaux bloqués pour tous en gris ;
//  - case : la ou les classes (exposants : 2nde, 1ère, 5e) — vue classe : matière + enseignant ;
//  - pied : « Fait à …, le » et le chef d'établissement.
// Inclus par pdf.php dans la boucle des cibles (variables locales de pdf.php : $pdf, $etab,
// $pw, $ph, $vue, $cid, $blocs, $mod, $a, $annee, $creneaux, $jours, $bloquesTous, $ville).

if (!function_exists('edt_pdf_segments')) {
    /** Découpe un libellé de classe en segments [texte, exposant ?] : « 2nde C » → 2 + ^nde + C. */
    function edt_pdf_segments(string $txt): array {
        $out = []; $pos = 0;
        if (preg_match_all('/(?<![\p{L}\d])(\d{1,2})(ère|ere|ère|re|er|nde|nd|ème|eme|e)(?=[\s\/A-Z]|$)/u', $txt, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $i => [$tout, $off]) {
                $fin_chiffre = $off + strlen($m[1][$i][0]);
                if ($fin_chiffre > $pos) $out[] = [substr($txt, $pos, $fin_chiffre - $pos), false];
                $out[] = [$m[2][$i][0], true];
                $pos = $off + strlen($tout);
            }
        }
        if ($pos < strlen($txt)) $out[] = [substr($txt, $pos), false];
        return $out;
    }
    /** Largeur d'un libellé avec exposants, à la taille $t. */
    function edt_pdf_largeur(FPDF $pdf, array $segs, float $t, string $style): float {
        $w = 0;
        foreach ($segs as [$s, $sup]) { $pdf->SetFont('Arial', $style, $sup ? $t * 0.62 : $t); $w += $pdf->GetStringWidth(pdf_u($s)); }
        return $w;
    }
    /** Coupe des mots en lignes de largeur $W au plus (à la taille $t). */
    function edt_pdf_couper(FPDF $pdf, array $mots, float $t, string $style, float $W): array {
        $lignes = []; $l = '';
        foreach ($mots as $m) {
            $cand = $l === '' ? $m : "$l $m";
            if ($l === '' || edt_pdf_largeur($pdf, edt_pdf_segments($cand), $t, $style) <= $W) $l = $cand;
            else { $lignes[] = $l; $l = $m; }
        }
        if ($l !== '') $lignes[] = $l;
        return $lignes;
    }
    /**
     * Écrit un libellé avec exposants dans la boîte ($x, $y, $w, $h), centré verticalement,
     * aligné C ou L : la plus grande taille entre $tmax et $tmin qui tient, sur autant de
     * lignes que la hauteur le permet ; tronqué (« ... ») seulement en dernier recours.
     */
    function edt_pdf_texte_exposants(FPDF $pdf, float $x, float $y, float $w, float $h, string $txt, float $tmax, float $tmin = 6, string $style = '', string $align = 'C'): void {
        $mots = preg_split('/\s+/u', trim($txt), -1, PREG_SPLIT_NO_EMPTY);
        if (!$mots) return;
        $W = $w - 1.5;
        for ($t = $tmax; ; $t -= 0.5) {
            $lh = $t * 0.3528 * 1.18;
            $maxl = max(1, (int) floor(($h + 0.4) / $lh));
            $lignes = edt_pdf_couper($pdf, $mots, $t, $style, $W);
            $trop_large = array_filter($lignes, fn($l) => edt_pdf_largeur($pdf, edt_pdf_segments($l), $t, $style) > $W);
            if ((count($lignes) <= $maxl && !$trop_large) || $t <= $tmin) break;
        }
        if (count($lignes) > $maxl) { $lignes = array_slice($lignes, 0, $maxl); $lignes[$maxl - 1] .= ' ...'; }
        foreach ($lignes as &$l) while (mb_strlen($l) > 3 && edt_pdf_largeur($pdf, edt_pdf_segments($l), $t, $style) > $W) $l = mb_substr($l, 0, -4) . '...';
        unset($l);
        $y0 = $y + ($h - count($lignes) * $lh) / 2;
        foreach ($lignes as $k => $l) {
            $segs = edt_pdf_segments($l);
            $lw = edt_pdf_largeur($pdf, $segs, $t, $style);
            $xx = $align === 'C' ? $x + ($w - $lw) / 2 : $x + 0.8;
            $base = $y0 + $k * $lh + $lh / 2 + $t * 0.3528 * 0.35;
            foreach ($segs as [$sg, $sup]) {
                $pdf->SetFont('Arial', $style, $sup ? $t * 0.62 : $t);
                $pdf->Text($xx, $sup ? $base - $t * 0.3528 * 0.38 : $base, pdf_u($sg));
                $xx += $pdf->GetStringWidth(pdf_u($sg));
            }
        }
    }
    /** « 07:30 » → « 7h30 ». */
    function edt_pdf_heure(string $hm): string {
        [$h, $m] = array_pad(explode(':', $hm), 2, '00');
        return (int) $h . 'h' . substr($m, 0, 2);
    }
}

$JOURS_EN = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'];
$annee_aff = str_replace('-', '/', (string) $annee);
$mg = 12; $uw = $pw - 2 * $mg;

$pdf->AddPage();
$pdf->SetAutoPageBreak(false);   // une fiche = une page : le pied ne doit jamais partir sur une 2e page
pdf_filigrane($pdf, $etab, $pw, $ph);
// En-tête à la taille normale (01/11/2026) ; l'immatriculation, écrite trop petite par
// pdf_entete(), est réécrite sous le logo en caractères lisibles.
pdf_entete($pdf, ['immatriculation' => ''] + $etab, $pw, 8, 5, 1.0);
$y_ent = $pdf->GetY();
if (!empty($etab['immatriculation'])) {
    $pdf->SetFont('Arial', 'B', 8.5);
    $pdf->SetXY(8 + ($pw - 16) / 3, 5 + 22 + 0.6);
    $pdf->Cell(($pw - 16) / 3, 4, pdf_u('Immatriculation : ' . $etab['immatriculation']), 0, 0, 'C');
}
$pdf->SetY(max($y_ent, 5 + 22 + 5));

// ── Titre ──────────────────────────────────────────────────────────────
$pdf->SetX($mg);
$pdf->SetFont('Arial', 'BU', 14);
$pdf->Cell($uw, 6.5, pdf_u(($vue === 'classe' ? 'EMPLOI DE TEMPS DE LA CLASSE ' : 'EMPLOI DE TEMPS INDIVIDUEL ') . $annee_aff), 0, 1, 'C');
$pdf->SetFont('Arial', '', 9);
$pdf->Cell($pw - 2 * $mg, 4, pdf_u('(' . ($vue === 'classe' ? 'Class' : 'Individual') . ' Time table ' . $annee_aff . ' School-Year)'), 0, 1, 'C');
$pdf->Ln(1.5);

// ── Informations (3 colonnes, libellé français en gras italique, anglais dessous) ──
$parts = [0.43, 0.27, 0.30];
// $hv : hauteur de la valeur (2 lignes possibles) ; $pp : largeurs relatives des colonnes
$ligne_info = function (array $champs, float $hv = 5.5, ?array $pp = null) use ($pdf, $mg, $uw, $parts) {
    $y = $pdf->GetY(); $x = $mg;
    foreach ($champs as $i => [$fr, $en, $val, $souligne]) {
        $w = $uw * ($pp ?? $parts)[$i];
        if ($fr !== '') {
            $pdf->SetFont('Arial', 'BI' . ($souligne ? 'U' : ''), 10.5);
            $lib = pdf_u($fr); $lw = $pdf->GetStringWidth($lib);
            $pdf->SetXY($x, $y); $pdf->Cell($lw, 5.5, $lib, 0, 0, 'L');
            $pdf->SetFont('Arial', '', 10.5); $pdf->Cell(4, 5.5, ' : ', 0, 0, 'L');
            edt_pdf_texte_exposants($pdf, $x + $lw + 4, $y, $w - $lw - 5, $hv, (string) $val, 11, 7, '', 'L');
            $pdf->SetFont('Arial', '', 7.5); $pdf->SetXY($x, $y + 4.4); $pdf->Cell($lw, 3, pdf_u('(' . $en . ')'), 0, 0, 'L');   // anglais juste sous le français
        }
        $x += $w;
    }
    $pdf->SetXY($mg, $y + max(8, $hv + 0.6));
};
$fmt_h = fn($v) => rtrim(rtrim(number_format((float) $v, 1, ',', ''), '0'), ',');
if ($vue === 'enseignant') {
    $e = db_one("SELECT * FROM enseignant WHERE matricule_ens=?", [$cid]) ?? [];
    $d = db_one("SELECT * FROM edt_enseignant WHERE id_annee=? AND matricule_ens=?", [$a, $cid]) ?? [];
    $faites = (int) db_val("SELECT COUNT(DISTINCT jour, id_creneau) FROM edt_seance WHERE id_annee=? AND matricule_ens=?", [$a, $cid]);
    $dues = isset($d['heures_dues']) && $d['heures_dues'] !== null ? (float) $d['heures_dues'] : null;
    $sup = $dues !== null ? max(0, $faites - $dues) : null;
    // Classes tenues : celles de ses séances (cours communs compris), dans l'ordre des classes
    $tenues = [];
    foreach ($blocs as $b) foreach ($mod->classesCommunes($b) as $nomc) $tenues[$nomc] = true;
    $ordre = array_flip(array_values(array_map(fn($c) => $c['designation'], $mod->classes)));
    $tenues = array_keys($tenues);
    usort($tenues, fn($x, $y) => ($ordre[$x] ?? 999) <=> ($ordre[$y] ?? 999) ?: strcmp($x, $y));
    $date_ps = $e['date_1ere_admin'] ?? null ?: ($e['date_prise_service'] ?? null ?: ($e['date_entree_fp'] ?? null));
    $ligne_info([['Noms et prénoms de l\'enseignant', 'Name and surname', $mod->ens[$cid] ?? trim(($e['nom_ens'] ?? '') . ' ' . ($e['prenom_ens'] ?? '')), false],
                 ['Grade', 'Grade', $d['grade'] ?? ($e['id_grade'] ?? ''), false],
                 ['Discipline', 'Subject', $d['discipline'] ?? ($e['matiere_enseignee'] ?? ''), false]]);
    $ligne_info([['Nbre d\'heures faites', 'Hours done', $fmt_h($faites) . ' h', true],
                 ['Nbre d\'heures dues', 'Hours previewed', $dues !== null ? $fmt_h($dues) . ' h' : '', false],
                 ['Nbre d\'heures supplémentaires', 'Additional hours', $sup === null ? '' : ($sup == (int) $sup ? sprintf('%02d', (int) $sup) : $fmt_h($sup)), false]]);
    $cl_txt = implode(', ', $tenues);
    $matr = ['Matricule', 'Registration number', (string) ($e['matricule'] ?? ''), false];
    $ps = ['Année 1ère Prise de service', 'First assumption', $date_ps ? date('d/m/Y', strtotime((string) $date_ps)) : '', false];
    if (edt_pdf_largeur($pdf, edt_pdf_segments($cl_txt), 9.5, '') <= $uw * $parts[0] - 32) $ligne_info([['Classes tenues', 'Classes held', $cl_txt, false], $matr, $ps]);
    else {   // longue liste : sur toute la largeur (2 lignes au besoin), matricule et prise de service dessous
        $ligne_info([['Classes tenues', 'Classes held', $cl_txt, false]], 9, [1]);
        $ligne_info([$matr, $ps, ['', '', '', false]]);
    }
} else {
    $pp = db_val("SELECT TRIM(CONCAT(e.nom_ens,' ',IFNULL(e.prenom_ens,''))) FROM " . edt_t('enseignat_principal') . " p JOIN enseignant e ON e.matricule_ens=p.matricule_ens WHERE p.IDClasses=? AND p.val_annee=?", [$cid, $annee]);
    $eff = edt_primaire()
        ? (int) db_val("SELECT COUNT(*) FROM inscrire i JOIN eleve e ON e.id_eleve=i.id_eleve WHERE i.IDClasses=? AND i.val_annee=? AND COALESCE(e.statut,'actif')='actif'", [$cid, $annee])
        : (int) db_val("SELECT COUNT(*) FROM inscription i JOIN eleve e ON e.id=i.id_eleve WHERE i.id_classe=? AND i.id_annee=? AND e.statut='actif'", [$cid, $a]);
    $vol = (int) db_val("SELECT COUNT(*) FROM edt_seance WHERE id_annee=? AND id_classe=? AND sous_groupe IN ('','A')", [$a, $cid]);
    $ligne_info([['Classe', 'Class', $mod->classes[$cid]['designation'] ?? '', false],
                 [edt_primaire() ? 'Titulaire' : 'Professeur principal', edt_primaire() ? 'Class teacher' : 'Form master', $pp ?: '', false],
                 ['Effectif', 'Enrolment', $eff ?: '', false]]);
    $ligne_info([['Volume horaire hebdomadaire', 'Weekly hours', $vol . ' h', true],
                 ['Section', 'Section', $mod->classes[$cid]['libelle_section'] ?? '', false],
                 ['', '', '', false]]);
}

// ── Contenu des cases : [jour][créneau] => libellés ─────────────────────
$cases = []; $mats_ens = [];
foreach ($blocs as $b) $mats_ens[$b['id_mat']] = true;
$plusieurs_mat = count($mats_ens) > 1;   // enseignant de plusieurs matières : la matière sous la classe
foreach ($blocs as $b) {
    if ($vue === 'classe') {
        $haut = trim($mod->mat[$b['id_mat']] ?? '?');
        $bas = edt_nom_court($mod->ens[$b['matricule_ens']] ?? '');
        if (!empty($b['sous_groupe'])) $haut .= ' (gr. ' . $b['sous_groupe'] . ')';
    } else {
        $haut = implode(' + ', $mod->classesCommunes($b));
        $bas = $plusieurs_mat ? trim($mod->mat[$b['id_mat']] ?? '') : '';
    }
    if ($b['id_salle']) $bas = trim($bas . ($bas !== '' ? ' · ' : '') . ($mod->salles[$b['id_salle']] ?? ''));
    foreach ($b['creneaux'] as $cr) $cases[(int) $b['jour']][(int) $cr][$haut . '|' . $bas] = [$haut, $bas];
}

// ── Grille ─────────────────────────────────────────────────────────────
$wH = 36; $wJ = ($uw - $wH) / max(1, count($jours));
$hEnt = 6.5; $hP = 5;
$nbC = count(array_filter($creneaux, fn($c) => $c['type'] === 'cours'));
$nbP = count($creneaux) - $nbC;
$yG = $pdf->GetY() + 1.5;
// hauteur des lignes : la grille ET le pied (« Fait à … » + signataire, ~12 mm) tiennent sur la page
$hL = max(6, min(11, ($ph - 8 - 12 - $yG - $hEnt - $nbP * $hP) / max(1, $nbC)));
$pdf->SetLineWidth(0.25); $pdf->SetDrawColor(0, 0, 0); $pdf->SetTextColor(0, 0, 0);

// en-tête des jours
$pdf->SetXY($mg, $yG);
$pdf->Cell($wH, $hEnt, '', 1, 0, 'C');
$pdf->SetFont('Arial', 'B', 10);
foreach ($jours as $j) $pdf->Cell($wJ, $hEnt, pdf_u(EDT_JOURS[$j] . ' /' . ($JOURS_EN[$j] ?? '')), 1, 0, 'C');
$y = $yG + $hEnt;

foreach ($creneaux as $c) {
    $plage = edt_pdf_heure($c['debut']) . '-' . edt_pdf_heure($c['fin']);
    if ($c['type'] === 'pause') {
        $min = (int) round((strtotime('2000-01-01 ' . $c['fin']) - strtotime('2000-01-01 ' . $c['debut'])) / 60);
        $pdf->SetFillColor(200, 200, 200);
        $pdf->SetXY($mg, $y); $pdf->SetFont('Arial', 'B', 9.5);
        $pdf->Cell($wH, $hP, $plage, 1, 0, 'C', true);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell($wJ * count($jours), $hP, pdf_u('Pause/Break (' . $min . ' minutes)'), 1, 0, 'C', true);
        $y += $hP;
        continue;
    }
    $pdf->SetXY($mg, $y); $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell($wH, $hL, $plage, 1, 0, 'C');
    foreach ($jours as $k => $j) {
        $x = $mg + $wH + $k * $wJ;
        $contenu = array_values($cases[$j][(int) $c['id']] ?? []);
        $bloque = !$contenu && isset($bloquesTous[$j . '|' . $c['id']]);
        if ($bloque) { $pdf->SetFillColor(200, 200, 200); $pdf->Rect($x, $y, $wJ, $hL, 'DF'); } else $pdf->Rect($x, $y, $wJ, $hL);
        if ($bloque && $bloquesTous[$j . '|' . $c['id']] !== '') { edt_pdf_texte_exposants($pdf, $x, $y, $wJ, $hL, $bloquesTous[$j . '|' . $c['id']], 8, 5.5, 'B'); continue; }
        if (!$contenu) continue;
        $haut = implode(' / ', array_unique(array_column($contenu, 0)));
        $bas = implode(' / ', array_filter(array_unique(array_column($contenu, 1))));
        if ($bas === '') edt_pdf_texte_exposants($pdf, $x, $y, $wJ, $hL, $haut, $vue === 'classe' ? 11 : 13, 6, $vue === 'classe' ? 'B' : '');
        else {
            edt_pdf_texte_exposants($pdf, $x, $y + 0.3, $wJ, $hL * 0.58, $haut, $vue === 'classe' ? 10.5 : 12, 6, $vue === 'classe' ? 'B' : '');
            edt_pdf_texte_exposants($pdf, $x, $y + $hL * 0.52, $wJ, $hL * 0.45, $bas, 8, 5, 'I');
        }
    }
    $y += $hL;
}

// ── Pied : lieu, date, chef d'établissement ─────────────────────────────
[, $sig2] = edt_signataires();
$pdf->SetFont('Arial', 'B', 9.5);
$pdf->SetXY($pw - $mg - 95, $y + 2);
$pdf->Cell(80, 4.5, pdf_u('Fait à ' . ($ville !== '' ? $ville : str_repeat('.', 15)) . ', le'), 0, 2, 'C');
$pdf->Cell(80, 4.5, pdf_u($sig2), 0, 0, 'C');
