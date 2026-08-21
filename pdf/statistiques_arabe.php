<?php
// ── PDF : Statistiques scolaires (piste arabe) ───────────────────────
// Miroir de pdf/statistiques.php — reprend l'onglet actuellement affiché
// (?onglet=) et la période (?vue=), en paysage. Données de
// notes_apc_arabe.php (bilan_classe_genre_arabe(), stats_classe_arabe(),
// stats_par_matiere_arabe()) — les MÊMES fonctions que la page web.
// Onglets : eleves|matieres|effectifs|niveau|matiere (miroir de
// eleves|competences|effectifs|niveau|competence côté français).
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
require_once __DIR__ . '/../notes_apc_arabe.php';
exiger_connexion();

require_once __DIR__ . '/fpdf.php';
require_once __DIR__ . '/header_pdf.php';

$onglet    = $_GET['onglet'] ?? 'eleves';
$vue       = in_array($_GET['vue'] ?? '', ['trim', 'annee'], true) ? $_GET['vue'] : 'trim';
$id_trim   = (int) ($_GET['trim'] ?? 0);
$id_classe = (int) ($_GET['classe'] ?? 0);
$dl        = ($_GET['dl'] ?? '0') === '1';
$avec_sig  = ($_GET['signature'] ?? '0') === '1';

$annee     = get_annee_active();
$val_annee = $annee['val_annee'] ?? '';
$etab      = etab_pour_pdf(get_etablissement());
$trim_lib  = $id_trim ? (string) db_val("SELECT libelle_trim FROM trimestre WHERE id_trim=?", [$id_trim]) : '';
$periode   = $vue === 'annee' ? 'Année scolaire ' . $val_annee : $trim_lib;

$classes = db_all(
    "SELECT c.IDClasses, c.DesignationClasses, c.Niveau, n.OrdreNiveau
     FROM classe c
     LEFT JOIN niveau n ON n.LibelleNiveau = c.Niveau
     JOIN inscrire i ON i.IDClasses = c.IDClasses AND i.val_annee = ?
     GROUP BY c.IDClasses, c.DesignationClasses, c.Niveau, n.OrdreNiveau
     ORDER BY n.OrdreNiveau, c.DesignationClasses",
    [$val_annee]
);
if ($id_classe) $classes = array_values(array_filter($classes, fn($c) => (int) $c['IDClasses'] === $id_classe));
if (empty($classes)) die('Aucune classe pour cette année.');

// Préchargement des absences (notes_apc.php) — même correctif que
// pdf/statistiques.php (trouvé le 21/08/2026) : bilan_classe_genre_arabe()
// appelle jours_absence_non_justifiees_trimestre() par élève, jamais
// préchargée depuis ce fichier.
if (in_array($onglet, ['eleves', 'niveau'], true)) {
    $trims_precharger = $vue === 'annee' ? trimestres_de_annee($val_annee) : ($id_trim ? [$id_trim] : []);
    foreach ($classes as $c) {
        foreach ($trims_precharger as $t) {
            precharger_absences_classe_trim((int) $c['IDClasses'], $t, $val_annee);
        }
    }
}

$fmt = fn(?float $v): string => $v === null ? '-' : rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');

// Enveloppé dans un try/catch — voir fonctions.php::pdf_erreur_generation()
// (jamais de fatal error brut ; ce document est public via QR et/ou
// consulté par du personnel qui ne doit pas voir de trace technique).
try {
$pdf = new FPDF('L', 'mm', 'A4');
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 15);
$pdf->AddPage();
$pw = $pdf->GetPageWidth(); $ph = $pdf->GetPageHeight(); $uw = $pw - 20;

pdf_filigrane($pdf, $etab, $pw, $ph);
pdf_entete($pdf, $etab, $pw, 10);

$titres = [
    'eleves'    => ['RÉSULTATS PAR CLASSE (ARABE)', 'RESULTS BY CLASS (ARABIC TRACK)'],
    'matieres'  => ['STATISTIQUES PAR MATIÈRE ET PAR CLASSE (ARABE)', 'STATISTICS BY SUBJECT AND CLASS (ARABIC TRACK)'],
    'effectifs' => ['EFFECTIFS PAR NIVEAU', 'ENROLMENT BY LEVEL'],
    'niveau'    => ['BILAN PAR NIVEAU (ARABE)', 'SUMMARY BY LEVEL (ARABIC TRACK)'],
    'matiere'   => ['STATISTIQUES PAR MATIÈRE (ARABE)', 'STATISTICS BY SUBJECT (ARABIC TRACK)'],
];
[$t_fr, $t_en] = $titres[$onglet] ?? $titres['eleves'];
pdf_bandeau($pdf, $t_fr, $t_en, $pw, 10);

$pdf->SetFont('Arial', 'B', 9);
$pdf->SetX(10);
$pdf->Cell($uw, 5, pdf_u('Période : ' . $periode), 0, 1, 'C');
$pdf->Ln(2);

$entete = function (array $cols) use ($pdf) {
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->SetFillColor(26, 60, 107);
    $pdf->SetTextColor(255);
    $pdf->SetX(10);
    foreach ($cols as [$lbl, $w]) $pdf->Cell($w, 6, pdf_u($lbl), 1, 0, 'C', true);
    $pdf->Ln(6);
    $pdf->SetTextColor(0);
    $pdf->SetFont('Arial', '', 7);
};

if ($onglet === 'effectifs') {
    $niveaux_map = array_column(db_all("SELECT LibelleNiveau, OrdreNiveau FROM niveau ORDER BY OrdreNiveau"), 'OrdreNiveau', 'LibelleNiveau');
    $par_niveau = [];
    foreach ($classes as $c) {
        $nb = (int) db_val("SELECT COUNT(*) FROM inscrire i JOIN eleve e ON e.id_eleve=i.id_eleve WHERE i.IDClasses=? AND i.val_annee=? AND e.statut='actif'", [$c['IDClasses'], $val_annee]);
        $nf = (int) db_val("SELECT COUNT(*) FROM inscrire i JOIN eleve e ON e.id_eleve=i.id_eleve WHERE i.IDClasses=? AND i.val_annee=? AND e.statut='actif' AND e.Sexe_elv LIKE 'F%'", [$c['IDClasses'], $val_annee]);
        $par_niveau[$c['Niveau'] ?: 'Non defini'][] = ['classe' => $c['DesignationClasses'], 'nb' => $nb, 'f' => $nf, 'g' => $nb - $nf];
    }
    uksort($par_niveau, fn($a, $b) => ($niveaux_map[$a] ?? 999) <=> ($niveaux_map[$b] ?? 999));
    $w = [$uw * 0.4, $uw * 0.15, $uw * 0.15, $uw * 0.15, $uw * 0.15];
    foreach ($par_niveau as $niv => $rows) {
        $pdf->SetFont('Arial', 'B', 9); $pdf->SetX(10);
        $pdf->Cell($uw, 6, pdf_u('Niveau ' . $niv), 0, 1, 'L');
        $entete([['Classe', $w[0]], ['Effectif', $w[1]], ['Filles', $w[2]], ['Garcons', $w[3]], ['% Filles', $w[4]]]);
        $tn = 0; $tf = 0;
        foreach ($rows as $r) {
            $tn += $r['nb']; $tf += $r['f'];
            $pdf->SetX(10);
            $pdf->Cell($w[0], 5, pdf_u($r['classe']), 1, 0, 'L');
            $pdf->Cell($w[1], 5, (string) $r['nb'], 1, 0, 'C');
            $pdf->Cell($w[2], 5, (string) $r['f'], 1, 0, 'C');
            $pdf->Cell($w[3], 5, (string) $r['g'], 1, 0, 'C');
            $pdf->Cell($w[4], 5, ($r['nb'] > 0 ? round($r['f'] / $r['nb'] * 100, 1) : 0) . '%', 1, 1, 'C');
        }
        $pdf->SetFont('Arial', 'B', 7); $pdf->SetFillColor(232, 240, 254); $pdf->SetX(10);
        $pdf->Cell($w[0], 5, pdf_u('Total niveau'), 1, 0, 'L', true);
        $pdf->Cell($w[1], 5, (string) $tn, 1, 0, 'C', true);
        $pdf->Cell($w[2], 5, (string) $tf, 1, 0, 'C', true);
        $pdf->Cell($w[3], 5, (string) ($tn - $tf), 1, 0, 'C', true);
        $pdf->Cell($w[4], 5, ($tn > 0 ? round($tf / $tn * 100, 1) : 0) . '%', 1, 1, 'C', true);
        $pdf->Ln(3);
    }

} elseif ($onglet === 'niveau') {
    $cols_lbl = ['Classes' => 'classes', 'Moy<10' => 'moy_lt10', 'Moy>=10' => 'moy_ge10', 'Felicit.' => 'felicit',
                 'Encour.' => 'encourag', 'T.H' => 'tab', 'Avert.T' => 'avert_trav', 'Blame T' => 'blame_trav'];
    $niveaux_map = array_column(db_all("SELECT LibelleNiveau, OrdreNiveau FROM niveau ORDER BY OrdreNiveau"), 'OrdreNiveau', 'LibelleNiveau');
    $par_niveau = [];
    foreach ($classes as $c) { $par_niveau[$c['Niveau'] ?: 'Non defini'][] = $c; }
    uksort($par_niveau, fn($a, $b) => ($niveaux_map[$a] ?? 999) <=> ($niveaux_map[$b] ?? 999));

    $w_cl = 45; $w_g = ($uw - $w_cl) / (count($cols_lbl) * 3);
    foreach ($par_niveau as $niv => $cls) {
        if ($pdf->GetY() > $ph - 40) { $pdf->AddPage(); pdf_filigrane($pdf, $etab, $pw, $ph); }
        $pdf->SetFont('Arial', 'B', 9); $pdf->SetX(10);
        $pdf->Cell($uw, 6, pdf_u('Niveau ' . $niv), 0, 1, 'L');
        $pdf->SetFont('Arial', 'B', 6.5); $pdf->SetFillColor(26, 60, 107); $pdf->SetTextColor(255);
        $pdf->SetX(10);
        $pdf->Cell($w_cl, 10, pdf_u('Classe'), 1, 0, 'C', true);
        $x = 10 + $w_cl; $y = $pdf->GetY();
        foreach ($cols_lbl as $lbl => $k) {
            $pdf->SetXY($x, $y);
            $pdf->Cell($w_g * 3, 5, pdf_u($lbl), 1, 0, 'C', true);
            $pdf->SetXY($x, $y + 5);
            foreach (['M', 'F', 'T'] as $g) $pdf->Cell($w_g, 5, $g, 1, 0, 'C', true);
            $x += $w_g * 3;
        }
        $pdf->SetY($y + 10);
        $pdf->SetTextColor(0); $pdf->SetFont('Arial', '', 6.5);

        $st = array_fill_keys(array_values($cols_lbl), ['M' => 0, 'F' => 0, 'T' => 0]);
        foreach ($cls as $c) {
            $b = bilan_classe_genre_arabe((int) $c['IDClasses'], $val_annee, $vue, $id_trim);
            $pdf->SetX(10);
            $pdf->Cell($w_cl, 5, pdf_u($c['DesignationClasses']), 1, 0, 'L');
            foreach ($cols_lbl as $k) {
                foreach (['M', 'F', 'T'] as $g) {
                    $pdf->Cell($w_g, 5, (string) $b[$k][$g], 1, 0, 'C');
                    $st[$k][$g] += $b[$k][$g];
                }
            }
            $pdf->Ln(5);
        }
        $pdf->SetFont('Arial', 'B', 6.5); $pdf->SetFillColor(232, 240, 254); $pdf->SetX(10);
        $pdf->Cell($w_cl, 5, pdf_u('SOUS-TOTAL'), 1, 0, 'L', true);
        foreach ($cols_lbl as $k) {
            foreach (['M', 'F', 'T'] as $g) $pdf->Cell($w_g, 5, (string) $st[$k][$g], 1, 0, 'C', true);
        }
        $pdf->Ln(8);
    }

} elseif ($onglet === 'matiere' || $onglet === 'matieres') {
    $w = [$uw * 0.40, $uw * 0.10, $uw * 0.12, $uw * 0.10, $uw * 0.10, $uw * 0.18];
    if ($onglet === 'matiere') {
        $stats = stats_par_matiere_arabe(array_map('intval', array_column($classes, 'IDClasses')), $val_annee, $vue, $id_trim);
        $entete([['Matiere', $w[0]], ['Nb evalues', $w[1]], ['Moyenne', $w[2]], ['Min', $w[3]], ['Max', $w[4]], ['Taux reussite', $w[5]]]);
        foreach ($stats as $s) {
            if ($pdf->GetY() > $ph - 20) { $pdf->AddPage(); pdf_filigrane($pdf, $etab, $pw, $ph); }
            $pdf->SetX(10);
            $pdf->Cell($w[0], 5, pdf_u($s['matiere']), 1, 0, 'L');
            $pdf->Cell($w[1], 5, (string) $s['nb'], 1, 0, 'C');
            $pdf->Cell($w[2], 5, $fmt($s['moy']), 1, 0, 'C');
            $pdf->Cell($w[3], 5, $fmt($s['min']), 1, 0, 'C');
            $pdf->Cell($w[4], 5, $fmt($s['max']), 1, 0, 'C');
            $pdf->Cell($w[5], 5, $s['taux'] . '%', 1, 1, 'C');
        }
    } else {
        foreach ($classes as $c) {
            $stats = stats_par_matiere_arabe([(int) $c['IDClasses']], $val_annee, $vue, $id_trim);
            if (empty($stats)) continue;
            if ($pdf->GetY() > $ph - 40) { $pdf->AddPage(); pdf_filigrane($pdf, $etab, $pw, $ph); }
            $pdf->SetFont('Arial', 'B', 9); $pdf->SetX(10);
            $pdf->Cell($uw, 6, pdf_u($c['DesignationClasses']), 0, 1, 'L');
            $entete([['Matiere', $w[0]], ['Nb evalues', $w[1]], ['Moyenne', $w[2]], ['Min', $w[3]], ['Max', $w[4]], ['Taux reussite', $w[5]]]);
            foreach ($stats as $s) {
                if ($pdf->GetY() > $ph - 20) { $pdf->AddPage(); pdf_filigrane($pdf, $etab, $pw, $ph); }
                $pdf->SetX(10);
                $pdf->Cell($w[0], 5, pdf_u($s['matiere']), 1, 0, 'L');
                $pdf->Cell($w[1], 5, (string) $s['nb'], 1, 0, 'C');
                $pdf->Cell($w[2], 5, $fmt($s['moy']), 1, 0, 'C');
                $pdf->Cell($w[3], 5, $fmt($s['min']), 1, 0, 'C');
                $pdf->Cell($w[4], 5, $fmt($s['max']), 1, 0, 'C');
                $pdf->Cell($w[5], 5, $s['taux'] . '%', 1, 1, 'C');
            }
            $pdf->Ln(3);
        }
    }

} else { // 'eleves' — résultats par classe
    $w = [$uw * 0.14, $uw * 0.07, $uw * 0.06, $uw * 0.06, $uw * 0.09, $uw * 0.09, $uw * 0.09, $uw * 0.08, $uw * 0.08,
          $uw * 0.06, $uw * 0.06, $uw * 0.05, $uw * 0.07];
    $entete([
        ['Classe', $w[0]], ['Effectif', $w[1]], ['F', $w[2]], ['G', $w[3]], ['Moyenne', $w[4]],
        ['Moy. 1er', $w[5]], ['Moy. dernier', $w[6]], ['Admis', $w[7]], ['Taux', $w[8]],
        ['Felic.', $w[9]], ['Encour.', $w[10]], ['T.H', $w[11]], ['Avert./Blame', $w[12]],
    ]);
    $g_nb = 0; $g_admis = 0; $g_moys = [];
    foreach ($classes as $c) {
        if ($pdf->GetY() > $ph - 25) { $pdf->AddPage(); pdf_filigrane($pdf, $etab, $pw, $ph); }
        $st = stats_classe_arabe((int) $c['IDClasses'], $val_annee, $vue, $id_trim);
        $b  = bilan_classe_genre_arabe((int) $c['IDClasses'], $val_annee, $vue, $id_trim);
        $g_nb += $st['nb_classes']; $g_admis += $st['admis'];
        if ($st['moy'] !== null) $g_moys[] = $st['moy'];
        $pdf->SetX(10);
        $pdf->Cell($w[0], 5, pdf_u($c['DesignationClasses']), 1, 0, 'L');
        $pdf->Cell($w[1], 5, (string) $st['nb'], 1, 0, 'C');
        $pdf->Cell($w[2], 5, (string) $st['filles'], 1, 0, 'C');
        $pdf->Cell($w[3], 5, (string) $st['garcons'], 1, 0, 'C');
        $pdf->Cell($w[4], 5, $fmt($st['moy']), 1, 0, 'C');
        $pdf->Cell($w[5], 5, $fmt($st['premier']), 1, 0, 'C');
        $pdf->Cell($w[6], 5, $fmt($st['dernier']), 1, 0, 'C');
        $pdf->Cell($w[7], 5, $st['admis'] . '/' . $st['nb_classes'], 1, 0, 'C');
        $pdf->Cell($w[8], 5, $st['taux'] . '%', 1, 0, 'C');
        $pdf->Cell($w[9], 5, (string) $b['felicit']['T'], 1, 0, 'C');
        $pdf->Cell($w[10], 5, (string) $b['encourag']['T'], 1, 0, 'C');
        $pdf->Cell($w[11], 5, (string) $b['tab']['T'], 1, 0, 'C');
        $pdf->Cell($w[12], 5, $b['avert_trav']['T'] . ' / ' . $b['blame_trav']['T'], 1, 1, 'C');
    }
    $pdf->SetFont('Arial', 'B', 7); $pdf->SetFillColor(232, 240, 254); $pdf->SetX(10);
    $pdf->Cell($w[0] + $w[1] + $w[2] + $w[3], 5, pdf_u('ENSEMBLE'), 1, 0, 'L', true);
    $pdf->Cell($w[4], 5, $fmt($g_moys ? array_sum($g_moys) / count($g_moys) : null), 1, 0, 'C', true);
    $pdf->Cell($w[5] + $w[6], 5, '', 1, 0, 'C', true);
    $pdf->Cell($w[7], 5, $g_admis . '/' . $g_nb, 1, 0, 'C', true);
    $pdf->Cell($w[8], 5, ($g_nb > 0 ? round($g_admis / $g_nb * 100, 1) : 0) . '%', 1, 0, 'C', true);
    $pdf->Cell($w[9] + $w[10] + $w[11] + $w[12], 5, '', 1, 1, 'C', true);
}

// ── Signature + pied de page ──────────────────────────────────────
$pdf->Ln(4);
if ($pdf->GetY() > $ph - 28) { $pdf->AddPage(); pdf_filigrane($pdf, $etab, $pw, $ph); }
$w_sign = $uw * 0.3; $x_sign = $pw - 10 - $w_sign;
$pdf->SetFont('Arial', '', 9);
$pdf->SetXY($x_sign, $pdf->GetY());
$pdf->Cell($w_sign, 5, pdf_u('Fait à ' . (($etab['lieu'] ?: $etab['ville']) ?: '') . ', le ' . date('d/m/Y')), 0, 1, 'R');
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetX($x_sign);
$pdf->Cell($w_sign, 5, pdf_u(mb_strtoupper($etab['chef_etablissement'] ?: 'LE DIRECTEUR') . ','), 0, 1, 'R');

if ($avec_sig) {
    pdf_signature_appliquer_jn($pdf, 'stat_generale_arabe', 0, 0, $pw, $ph, [
        'x_pct' => ($x_sign + ($w_sign - 22) / 2) / $pw * 100,
        'y_pct' => ($pdf->GetY() + 1) / $ph * 100,
        'w_pct' => 22 / $pw * 100, 'h_pct' => null,
    ]);
}

// Copyright standard du système (pdf/header_pdf.php) — texte unique sur
// tous les PDF du projet, voir pdf_copyright().
pdf_copyright($pdf, $pw, $ph);

$pdf->Output($dl ? 'D' : 'I', 'statistiques_arabe_' . $onglet . '_' . $vue . '.pdf');
} catch (Throwable $e) {
    pdf_erreur_generation($e);
}
