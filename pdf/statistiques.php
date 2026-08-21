<?php
// ── PDF : Statistiques scolaires (piste française) ──────────────────
// Équivalent de pages/statistiques/pdf_stats.php d'ABZ_MBE : reprend
// l'onglet actuellement affiché (?onglet=) et la période (?vue=), en
// paysage. Les données viennent de notes_apc.php (bilan_classe_genre(),
// stats_classe(), stats_par_competence()) — les MÊMES fonctions que la page
// web, donc aucun risque de divergence entre l'écran et l'impression
// (contrairement à ABZ_MBE, qui recalcule tout inline dans chaque PDF).
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
require_once __DIR__ . '/../notes_apc.php';
exiger_connexion();

require_once __DIR__ . '/fpdf.php';
require_once __DIR__ . '/header_pdf.php';

$onglet    = $_GET['onglet'] ?? 'eleves';
$vue       = in_array($_GET['vue'] ?? '', ['trim', 'annee'], true) ? $_GET['vue'] : 'trim';
$id_trim   = (int) ($_GET['trim'] ?? 0);
$id_classe = (int) ($_GET['classe'] ?? 0);
$dl        = ($_GET['dl'] ?? '0') === '1';
$avec_sig  = ($_GET['signature'] ?? '0') === '1';
// Onglets « Par classe/Résultats » et « Par compétence / Évaluation »
// uniquement — voir pages/statistiques/index.php pour le détail. $seq_stat
// (demande du 18/08/2026) : id_seq précis choisi sur la page web (n'importe
// quelle séquence du trimestre actif, pas seulement la séquence active) —
// repli sur la séquence active si absent (anciens liens/favoris).
$niveau_f  = $_GET['niveau'] ?? '';
$mode_eval = in_array($_GET['mode'] ?? '', ['seq', 'trim'], true) ? $_GET['mode'] : 'trim';
$seq_act_pdf_top = get_sequence_active();
$id_seq_stat = (int) ($_GET['seq_stat'] ?? ($seq_act_pdf_top['id_seq'] ?? 0));

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
if ($id_classe)           $classes = array_values(array_filter($classes, fn($c) => (int) $c['IDClasses'] === $id_classe));
elseif ($niveau_f !== '') $classes = array_values(array_filter($classes, fn($c) => $c['Niveau'] === $niveau_f));
if (empty($classes)) die('Aucune classe pour cette année.');

// Préchargement des absences (notes_apc.php) pour toutes les classes du
// périmètre — trouvé le 21/08/2026 en auditant les requêtes de ce fichier :
// les onglets « Par classe/Résultats » et « Par niveau » appellent
// bilan_classe_genre() par classe, qui interroge `absence` UNE FOIS PAR
// ÉLÈVE de la classe (jours_absence_non_justifiees_trimestre(), jamais
// préchargée depuis ce fichier) — 115 requêtes mesurées pour ~90 élèves sur
// 6 classes. Même fonction de préchargement déjà utilisée par
// pdf/bulletin_trimestriel.php ; jours_absence_non_justifiees_annuel()
// délègue en interne à la version trimestre par trimestre, donc précharger
// les 3 trimestres de l'année couvre aussi la vue annuelle.
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
    'eleves'      => ['RÉSULTATS PAR CLASSE', 'RESULTS BY CLASS'],
    'competences' => ['STATISTIQUES PAR COMPÉTENCE ET PAR CLASSE', 'STATISTICS BY COMPETENCY AND CLASS'],
    'effectifs'   => ['EFFECTIFS PAR NIVEAU', 'ENROLMENT BY LEVEL'],
    'niveau'      => ['BILAN PAR NIVEAU', 'SUMMARY BY LEVEL'],
    'non_evalue'  => ['ÉLÈVES NON ÉVALUÉS', 'NOT YET EVALUATED STUDENTS'],
];
[$t_fr, $t_en] = $titres[$onglet] ?? $titres['eleves'];
pdf_bandeau($pdf, $t_fr, $t_en, $pw, 10);

$pdf->SetFont('Arial', 'B', 9);
$pdf->SetX(10);
$pdf->Cell($uw, 5, pdf_u('Période : ' . $periode), 0, 1, 'C');
$pdf->Ln(2);

// En-tête de tableau réutilisable (fond bleu, texte blanc, comme la page web).
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
    // Bilan M/F/T par niveau — mêmes colonnes que la page web (demande du
    // 18/08/2026 : Félicit./Encour./T.H/Avert.T/Blâme T retirés, remplacés
    // par 7 tranches de moyenne). $seqs_override : même bascule « Évaluation
    // en cours » que les autres onglets (demande du 18/08/2026, suite).
    $seqs_override = ($vue === 'trim' && $mode_eval === 'seq' && $id_seq_stat) ? [$id_seq_stat] : null;
    $cols_lbl = [
        'Classes' => 'classes', 'Moy<7' => 'tr_0_7', '7<=Moy<10' => 'tr_7_10', '10<=Moy<12' => 'tr_10_12',
        '12<=Moy<14' => 'tr_12_14', '14<=Moy<16' => 'tr_14_16', '16<=Moy<18' => 'tr_16_18', '18<=Moy' => 'tr_18_20',
    ];
    $niveaux_map = array_column(db_all("SELECT LibelleNiveau, OrdreNiveau FROM niveau ORDER BY OrdreNiveau"), 'OrdreNiveau', 'LibelleNiveau');
    $par_niveau = [];
    foreach ($classes as $c) { $par_niveau[$c['Niveau'] ?: 'Non defini'][] = $c; }
    uksort($par_niveau, fn($a, $b) => ($niveaux_map[$a] ?? 999) <=> ($niveaux_map[$b] ?? 999));

    $w_cl = 45; $w_g = ($uw - $w_cl) / (count($cols_lbl) * 3);
    foreach ($par_niveau as $niv => $cls) {
        if ($pdf->GetY() > $ph - 40) { $pdf->AddPage(); pdf_filigrane($pdf, $etab, $pw, $ph); }
        $pdf->SetFont('Arial', 'B', 9); $pdf->SetX(10);
        $pdf->Cell($uw, 6, pdf_u('Niveau ' . $niv), 0, 1, 'L');
        // Double ligne d'en-tête (libellé sur 3 colonnes, puis M/F/T)
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
            $b = bilan_classe_genre((int) $c['IDClasses'], $val_annee, $vue, $id_trim, $seqs_override);
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

} elseif ($onglet === 'non_evalue') {
    // Non évalué (demande du 18/08/2026, suite) : liste NOMINATIVE, par
    // classe, des élèves inscrits/actifs qui n'entrent pas dans « Élèves
    // évalués » (onglet Par classe/Résultats), avec la raison — explique
    // l'écart Scolarité > Élèves vs Statistiques. Voir
    // eleves_non_evalues_classe() (notes_apc.php).
    $classes_pdf = $classes;
    if ($niveau_f !== '' && !$id_classe) $classes_pdf = array_values(array_filter($classes_pdf, fn($c) => $c['Niveau'] === $niveau_f));

    $total_inscrits_pdf = 0; $lignes_ne = [];
    foreach ($classes_pdf as $c) {
        $nb = (int) db_val(
            "SELECT COUNT(*) FROM inscrire i JOIN eleve e ON e.id_eleve=i.id_eleve WHERE i.IDClasses=? AND i.val_annee=? AND e.statut='actif'",
            [(int) $c['IDClasses'], $val_annee]
        );
        $total_inscrits_pdf += $nb;
        foreach (eleves_non_evalues_classe((int) $c['IDClasses'], $val_annee, $vue, $id_trim) as $e) {
            $lignes_ne[] = $e + ['classe' => $c['DesignationClasses']];
        }
    }
    $total_evalues_pdf = $total_inscrits_pdf - count($lignes_ne);

    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetX(10);
    $pdf->Cell($uw, 6, pdf_u("Effectif inscrit : $total_inscrits_pdf   —   Élèves évalués : $total_evalues_pdf   —   Non évalué (écart) : " . count($lignes_ne)), 0, 1, 'C');
    $pdf->Ln(2);

    $w = [$uw * 0.20, $uw * 0.14, $uw * 0.36, $uw * 0.08, $uw * 0.22];
    $entete([['Classe', $w[0]], ['Matricule', $w[1]], ['Nom et prenom', $w[2]], ['Sexe', $w[3]], ['Raison', $w[4]]]);
    foreach ($lignes_ne as $e) {
        if ($pdf->GetY() > $ph - 20) { $pdf->AddPage(); pdf_filigrane($pdf, $etab, $pw, $ph); }
        $pdf->SetX(10);
        $pdf->Cell($w[0], 5, pdf_u($e['classe']), 1, 0, 'L');
        $pdf->Cell($w[1], 5, pdf_u($e['Mat_elv']), 1, 0, 'C');
        $pdf->Cell($w[2], 5, pdf_u($e['Nom_elv'] . ' ' . ($e['Prenom_elv'] ?? '')), 1, 0, 'L');
        $pdf->Cell($w[3], 5, stripos($e['Sexe_elv'] ?? '', 'F') === 0 ? 'F' : 'M', 1, 0, 'C');
        $pdf->Cell($w[4], 5, pdf_u($e['raison']), 1, 1, 'L');
    }
    if (!$lignes_ne) {
        $pdf->SetFont('Arial', 'I', 9);
        $pdf->Cell(array_sum($w), 8, pdf_u('Aucun écart pour cette sélection.'), 1, 1, 'C');
    }

} elseif ($onglet === 'competences') {
    // Par compétence / Évaluation (ex Enseignant, demande du 18/08/2026) :
    // code en tête, Nb évalués/Échoués/Admis/Taux ventilés F/M/T, filtre
    // Niveau en plus de Classe, Évaluation en cours ou Trimestre entier —
    // même logique que pages/statistiques/index.php (stats_par_competence_genre()).
    $classes_pdf = $classes;
    if ($niveau_f !== '' && !$id_classe) $classes_pdf = array_values(array_filter($classes_pdf, fn($c) => $c['Niveau'] === $niveau_f));

    if ($vue === 'annee') {
        $seqs_choisis = array_column(db_all("SELECT s.id_seq FROM sequence s JOIN trimestre t ON t.id_trim = s.id_trim WHERE t.id_annee = ?", [$val_annee]), 'id_seq');
    } elseif ($mode_eval === 'seq') {
        $seqs_choisis = $id_seq_stat ? [$id_seq_stat] : [];
    } else {
        $seqs_choisis = sequences_du_trimestre($id_trim);
    }

    $w_code = $uw * 0.05; $w_comp = $uw * 0.22; $w_bar = $uw * 0.05; $w_moy = $uw * 0.06;
    $w_fixe = $w_code + $w_comp + $w_bar + $w_moy;
    $w_g = ($uw - $w_fixe) / 12; // 4 groupes (Nb évalués/Échoués/Admis/Taux) × F/M/T

    foreach ($classes_pdf as $c) {
        $stats = stats_par_competence_genre([(int) $c['IDClasses']], $val_annee, $seqs_choisis);
        if (empty($stats)) continue;
        if ($pdf->GetY() > $ph - 40) { $pdf->AddPage(); pdf_filigrane($pdf, $etab, $pw, $ph); }
        $pdf->SetFont('Arial', 'B', 9); $pdf->SetX(10);
        $pdf->Cell($uw, 6, pdf_u($c['DesignationClasses']), 0, 1, 'L');

        $pdf->SetFont('Arial', 'B', 6.5); $pdf->SetFillColor(26, 60, 107); $pdf->SetTextColor(255);
        $pdf->SetX(10);
        $pdf->Cell($w_code, 10, pdf_u('Code'), 1, 0, 'C', true);
        $pdf->Cell($w_comp, 10, pdf_u('Competence'), 1, 0, 'C', true);
        $pdf->Cell($w_bar, 10, pdf_u('Bareme'), 1, 0, 'C', true);
        $pdf->Cell($w_moy, 10, pdf_u('Moyenne'), 1, 0, 'C', true);
        $x = 10 + $w_fixe; $y = $pdf->GetY();
        foreach (['Nb evalues', 'Echoues', 'Admis', 'Taux reussite'] as $lbl) {
            $pdf->SetXY($x, $y);
            $pdf->Cell($w_g * 3, 5, pdf_u($lbl), 1, 0, 'C', true);
            $pdf->SetXY($x, $y + 5);
            foreach (['F', 'M', 'T'] as $g) $pdf->Cell($w_g, 5, $g, 1, 0, 'C', true);
            $x += $w_g * 3;
        }
        $pdf->SetY($y + 10);
        $pdf->SetTextColor(0); $pdf->SetFont('Arial', '', 6.5);

        foreach ($stats as $s) {
            if ($pdf->GetY() > $ph - 20) { $pdf->AddPage(); pdf_filigrane($pdf, $etab, $pw, $ph); }
            $pdf->SetX(10);
            $pdf->Cell($w_code, 5, pdf_u($s['code'] ?: '-'), 1, 0, 'C');
            $pdf->Cell($w_comp, 5, pdf_u($s['competence']), 1, 0, 'L');
            $pdf->Cell($w_bar, 5, '/' . (int) $s['bareme'], 1, 0, 'C');
            $pdf->Cell($w_moy, 5, $fmt($s['moy']['T']), 1, 0, 'C');
            foreach (['F', 'M', 'T'] as $g) $pdf->Cell($w_g, 5, (string) $s['nb'][$g], 1, 0, 'C');
            foreach (['F', 'M', 'T'] as $g) $pdf->Cell($w_g, 5, (string) $s['echoues'][$g], 1, 0, 'C');
            foreach (['F', 'M', 'T'] as $g) $pdf->Cell($w_g, 5, (string) $s['admis'][$g], 1, 0, 'C');
            foreach (['F', 'M', 'T'] as $g) $pdf->Cell($w_g, 5, $s['taux'][$g] . '%', 1, 0, 'C');
            $pdf->Ln(5);
        }
        $pdf->Ln(3);
    }

} else { // 'eleves' — résultats par classe
    // Demande du 17/08/2026 (+ suivi) : Félicitations/Encouragements/Avert./
    // Blâme retirés (seul T.H reste) ; Recalés/Admis/T.H/Taux de réussite
    // ventilés par genre (F/G/T) — Moy Gen reste une seule valeur (pas de
    // ventilation par genre). Ligne TOTAL en pied de tableau. Même pattern
    // d'en-tête double ligne que l'onglet « Par niveau » ci-dessus.
    // $seqs_override (demande du 18/08/2026) : même bascule « Évaluation en
    // cours » que la page web — voir stats_classe()/bilan_classe_genre().
    $seqs_override = ($vue === 'trim' && $mode_eval === 'seq' && $id_seq_stat) ? [$id_seq_stat] : null;
    $groupes_lbl = ['Recales' => 'moy_lt10', 'Admis' => 'moy_ge10', 'T.H' => 'tab'];
    $w_cl = $uw * 0.20; $w_eff = $uw * 0.05; $w_fg = $uw * 0.04; $w_1er = $uw * 0.04; $w_der = $uw * 0.04; $w_mg = $uw * 0.06;
    $w_fixe = $w_cl + $w_eff + $w_fg * 2 + $w_1er + $w_der + $w_mg;
    $w_g = ($uw - $w_fixe) / ((count($groupes_lbl) + 1) * 3); // +1 pour le groupe "Taux"

    $pdf->SetFont('Arial', 'B', 6.5); $pdf->SetFillColor(26, 60, 107); $pdf->SetTextColor(255);
    $pdf->SetX(10);
    $pdf->Cell($w_cl, 10, pdf_u('Classe'), 1, 0, 'C', true);
    $pdf->Cell($w_eff, 10, pdf_u('Effectif'), 1, 0, 'C', true);
    $pdf->Cell($w_fg, 10, 'F', 1, 0, 'C', true);
    $pdf->Cell($w_fg, 10, 'M', 1, 0, 'C', true);
    $pdf->Cell($w_1er, 10, pdf_u('Moy. 1er'), 1, 0, 'C', true);
    $pdf->Cell($w_der, 10, pdf_u('Moy. dern.'), 1, 0, 'C', true);
    $pdf->Cell($w_mg, 10, pdf_u('Moy Gen'), 1, 0, 'C', true);
    $x = 10 + $w_fixe; $y = $pdf->GetY();
    foreach (array_merge(array_keys($groupes_lbl), ['Taux reussite']) as $lbl) {
        $pdf->SetXY($x, $y);
        $pdf->Cell($w_g * 3, 5, pdf_u($lbl), 1, 0, 'C', true);
        $pdf->SetXY($x, $y + 5);
        foreach (['F', 'M', 'T'] as $g) $pdf->Cell($w_g, 5, $g, 1, 0, 'C', true);
        $x += $w_g * 3;
    }
    $pdf->SetY($y + 10);
    $pdf->SetTextColor(0); $pdf->SetFont('Arial', '', 6.5);

    $g_classes = ['M' => 0, 'F' => 0, 'T' => 0]; $g_admis = ['M' => 0, 'F' => 0, 'T' => 0]; $g_tab = ['M' => 0, 'F' => 0, 'T' => 0];
    $g_nb = 0; $g_filles = 0; $g_garcons = 0; $g_moy_somme = 0.0;
    foreach ($classes as $c) {
        if ($pdf->GetY() > $ph - 25) { $pdf->AddPage(); pdf_filigrane($pdf, $etab, $pw, $ph); }
        $st = stats_classe((int) $c['IDClasses'], $val_annee, $vue, $id_trim, $seqs_override);
        $b  = bilan_classe_genre((int) $c['IDClasses'], $val_annee, $vue, $id_trim, $seqs_override);
        foreach (['M', 'F', 'T'] as $g) { $g_classes[$g] += $b['classes'][$g]; $g_admis[$g] += $b['moy_ge10'][$g]; $g_tab[$g] += $b['tab'][$g]; }
        $g_nb += $st['nb']; $g_filles += $st['filles']; $g_garcons += $st['garcons'];
        if ($b['moy_gen']['T'] !== null) $g_moy_somme += $b['moy_gen']['T'] * $b['classes']['T'];
        $pdf->SetX(10);
        $pdf->Cell($w_cl, 5, pdf_u($c['DesignationClasses']), 1, 0, 'L');
        $pdf->Cell($w_eff, 5, (string) $st['nb'], 1, 0, 'C');
        $pdf->Cell($w_fg, 5, (string) $st['filles'], 1, 0, 'C');
        $pdf->Cell($w_fg, 5, (string) $st['garcons'], 1, 0, 'C');
        $pdf->Cell($w_1er, 5, $fmt($st['premier']), 1, 0, 'C');
        $pdf->Cell($w_der, 5, $fmt($st['dernier']), 1, 0, 'C');
        $pdf->Cell($w_mg, 5, $fmt($b['moy_gen']['T']), 1, 0, 'C');
        foreach ($groupes_lbl as $k) {
            foreach (['F', 'M', 'T'] as $g) $pdf->Cell($w_g, 5, (string) $b[$k][$g], 1, 0, 'C');
        }
        foreach (['F', 'M', 'T'] as $g) {
            $taux = $b['classes'][$g] > 0 ? round($b['moy_ge10'][$g] / $b['classes'][$g] * 100, 1) : 0;
            $pdf->Cell($w_g, 5, $taux . '%', 1, 0, 'C');
        }
        $pdf->Ln(5);
    }
    $pdf->SetFont('Arial', 'B', 6.5); $pdf->SetFillColor(232, 240, 254); $pdf->SetX(10);
    $pdf->Cell($w_cl, 5, pdf_u('TOTAL'), 1, 0, 'L', true);
    $pdf->Cell($w_eff, 5, (string) $g_nb, 1, 0, 'C', true);
    $pdf->Cell($w_fg, 5, (string) $g_filles, 1, 0, 'C', true);
    $pdf->Cell($w_fg, 5, (string) $g_garcons, 1, 0, 'C', true);
    $pdf->Cell($w_1er + $w_der, 5, '', 1, 0, 'C', true);
    $g_moy_gen = $g_classes['T'] > 0 ? round($g_moy_somme / $g_classes['T'], 2) : null;
    $pdf->Cell($w_mg, 5, $fmt($g_moy_gen), 1, 0, 'C', true);
    foreach (['F', 'M', 'T'] as $g) {
        $rec = $g_classes[$g] - $g_admis[$g];
        $pdf->Cell($w_g, 5, (string) $rec, 1, 0, 'C', true);
    }
    foreach (['F', 'M', 'T'] as $g) $pdf->Cell($w_g, 5, (string) $g_admis[$g], 1, 0, 'C', true);
    foreach (['F', 'M', 'T'] as $g) $pdf->Cell($w_g, 5, (string) $g_tab[$g], 1, 0, 'C', true);
    foreach (['F', 'M', 'T'] as $g) {
        $taux = $g_classes[$g] > 0 ? round($g_admis[$g] / $g_classes[$g] * 100, 1) : 0;
        $pdf->Cell($w_g, 5, $taux . '%', 1, 0, 'C', true);
    }
    $pdf->Ln(5);
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
    pdf_signature_appliquer_jn($pdf, 'stat_generale', 0, 0, $pw, $ph, [
        'x_pct' => ($x_sign + ($w_sign - 22) / 2) / $pw * 100,
        'y_pct' => ($pdf->GetY() + 1) / $ph * 100,
        'w_pct' => 22 / $pw * 100, 'h_pct' => null,
    ]);
}

// Copyright standard du système (pdf/header_pdf.php) — texte unique sur
// tous les PDF du projet, voir pdf_copyright().
pdf_copyright($pdf, $pw, $ph);

$pdf->Output($dl ? 'D' : 'I', 'statistiques_' . $onglet . '_' . $vue . '.pdf');
} catch (Throwable $e) {
    pdf_erreur_generation($e);
}
