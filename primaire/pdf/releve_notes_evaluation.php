<?php
// primaire/pdf/releve_notes_evaluation.php — RELEVÉ DE NOTES d'une évaluation
// (26/10/2026), VIERGE (à remplir à la main) ou REMPLI (notes saisies), pour une
// classe du primaire, conforme à la notation APC :
//  - piste française : une COMPÉTENCE, évaluée en Orale / Écrite / Pratique /
//    Savoir-être sur le barème de la classe (table discipline) + total + cote ;
//  - piste arabe : une MATIÈRE, évaluée en Oral / Écrit / Pratique (barème
//    discipline_arabe) + total + cote.
// Une colonne par critère du barème (critère à 0 point = pas de colonne), barème
// rappelé dans l'en-tête. « Toutes » : une page par compétence / matière.
// GET : piste (fr|ar), classe, item (id_comp / id_mat ; 0 = feuille générique,
//       -1 = toutes), seq (évaluation ; 0 = à compléter ; « annee » = les 3 trimestres :/n//       total de chaque évaluation, note du trimestre, note annuelle, cote — paysage),/n//       mode (vierge|rempli), dl.
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../noyau/connexion.php';
require_once __DIR__ . '/../../noyau/fonctions.php';
require_once __DIR__ . '/../notes_apc.php';
require_once __DIR__ . '/../notes_apc_arabe.php';
exiger_acces_pedagogie();
require_once __DIR__ . '/fpdf.php';
require_once __DIR__ . '/header_pdf.php';

$piste = ($_GET['piste'] ?? 'fr') === 'ar' ? 'ar' : 'fr';
$val_annee = (string) (get_annee_active()['val_annee'] ?? '');
$id_classe = (int) ($_GET['classe'] ?? 0);
exiger_acces_classe($id_classe, $val_annee, $piste);   // enseignant : seulement ses classes
$item = (int) ($_GET['item'] ?? 0);
$annuel = ($_GET['seq'] ?? '') === 'annee';   // toute l'année : les 3 trimestres (26/10/2026)
$id_seq = $annuel ? 0 : (int) ($_GET['seq'] ?? 0);
$rempli = ($_GET['mode'] ?? '') === 'rempli';
$dl = ($_GET['dl'] ?? '0') === '1';
$classe = $id_classe ? db_one("SELECT * FROM classe WHERE IDClasses=?", [$id_classe]) : null;
if (!$classe) die('Classe introuvable.');
$seq = $id_seq ? db_one("SELECT s.id_seq, s.libelle_seq, t.id_trim, t.libelle_trim FROM sequence s JOIN trimestre t ON t.id_trim=s.id_trim WHERE s.id_seq=? AND t.id_annee=?", [$id_seq, $val_annee]) : null;
if ($rempli && !$seq && !$annuel) $rempli = false;   // rempli : une évaluation précise, ou toute l'année
// Année : trimestres et leurs évaluations, dans l'ordre
$trims = [];
if ($annuel) foreach (db_all("SELECT id_trim, libelle_trim FROM trimestre WHERE id_annee=? ORDER BY id_trim", [$val_annee]) as $t)
    $trims[] = $t + ['seqs' => db_all("SELECT id_seq, libelle_seq FROM sequence WHERE id_trim=? ORDER BY id_seq", [$t['id_trim']])];

// Critères : clé du barème => [libellé FR, libellé EN, colonne de note]
$criteres = $piste === 'fr'
    ? ['orale' => ['Orale', 'Oral', 'note_orale'], 'ecrite' => ['Écrite', 'Written', 'note_ecrite'], 'pratique' => ['Pratique', 'Practical', 'note_pratique'], 'savoir_etre' => ['Savoir-être', 'Attitude', 'note_savoir_etre']]
    : ['orale' => ['Oral', 'Oral', 'note_orale'], 'ecrite' => ['Écrit', 'Written', 'note_ecrite'], 'pratique' => ['Pratique', 'Practical', 'note_pratique']];

// Compétences (fr) ou matières (ar) de la classe, avec leur barème
if ($piste === 'fr') {
    $section_en = db_val("SELECT n.Section FROM classe c JOIN niveau n ON n.LibelleNiveau=c.Niveau WHERE c.IDClasses=?", [$id_classe]) === 'An';
    $items = array_map(fn($c) => ['id' => (int) $c['id_comp'], 'libelle' => ($section_en && $c['nom_comp_en'] ? $c['nom_comp_en'] : $c['nom_comp']),
                                  'groupe' => ($section_en && $c['libelle_groupe_comp_en'] ? $c['libelle_groupe_comp_en'] : $c['libelle_groupe_comp']), 'bareme' => $c],
                       competences_classe($id_classe, $val_annee));
} else {
    $items = [];   // libellés français : FPDF n'écrit pas l'arabe (même choix que releve_notes_classe_arabe.php)
    foreach (matieres_classe_arabe_avec_bareme($id_classe, $val_annee) as $m)
        $items[] = ['id' => (int) $m['id_mat'], 'libelle' => trim((string) $m['matiere_fr']), 'groupe' => $m['nom_groupe_fr'], 'bareme' => $m['bareme']];
}
$pages = $item === -1 ? $items : ($item > 0 ? array_values(array_filter($items, fn($i) => $i['id'] === $item)) : [null]);
if ($item > 0 && !$pages) die('Compétence ou matière inconnue pour cette classe.');

$eleves = db_all("SELECT e.id_eleve, e.Nom_elv, e.Prenom_elv, e.Sexe_elv FROM eleve e JOIN inscrire i ON i.id_eleve=e.id_eleve
                  WHERE i.IDClasses=? AND i.val_annee=? ORDER BY e.Nom_elv, e.Prenom_elv", [$id_classe, $val_annee]);
$etab = etab_pour_pdf(get_etablissement());
[$titre_chef, $titre_chef_en] = titres_chef_pdf(get_etablissement());
$enseignant = null;
$nom_ens = fn(?array $t) => $t ? trim(($t['nom_ens'] ?? '') . ' ' . ($t['prenom_ens'] ?? '')) : null;
if ($seq) $enseignant = $nom_ens(prim_titulaire_trimestre($id_classe, $val_annee, (int) $seq['id_trim'], $piste));
if ($annuel) {   // un nom, ou un par trimestre si l'enseignant a changé en cours d'année
    $parT = [];
    foreach ($trims as $i => $t) if ($n = $nom_ens(prim_titulaire_trimestre($id_classe, $val_annee, (int) $t['id_trim'], $piste))) $parT['T' . ($i + 1)] = $n;
    $enseignant = count(array_unique($parT)) > 1 ? implode(' ; ', array_map(fn($k, $v) => "$k : $v", array_keys($parT), $parT)) : (array_values($parT)[0] ?? null);
}
$periode_lib = $annuel ? "Toute l'année (" . count($trims) . ' trimestres)' : ($seq ? $seq['libelle_seq'] . ' — ' . $seq['libelle_trim'] : null);

/** Notes saisies d'une évaluation pour un item : [id_eleve => ligne] ; null si vierge. */
$notes = function (?array $it) use ($rempli, $piste, $id_classe, $id_seq, $val_annee): array {
    if (!$rempli || !$it) return [];
    $rows = $piste === 'fr'
        ? db_all("SELECT id_eleve, note_orale, note_ecrite, note_pratique, note_savoir_etre FROM composer_sequence WHERE IDClasses=? AND id_comp=? AND id_seq=? AND val_annee=?", [$id_classe, $it['id'], $id_seq, $val_annee])
        : db_all("SELECT id_eleve, note_orale, note_ecrite, note_pratique FROM composer_sequence_arabe WHERE classe=? AND id_mat=? AND id_seq=?", [$id_classe, $it['id'], $id_seq]);
    return array_column($rows, null, 'id_eleve');
};
$fmt = fn($v) => $v === null || $v === '' ? '' : rtrim(rtrim(number_format((float) $v, 2, ',', ''), '0'), ',');

$pdf = new FPDF($annuel ? 'L' : 'P', 'mm', 'A4');   // l'année : paysage (une colonne par évaluation)
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(false);
$pw = $pdf->GetPageWidth(); $ph = $pdf->GetPageHeight(); $ml = 10; $uw = $pw - 2 * $ml;

foreach ($pages as $it) {
    $bar = $it['bareme'] ?? null;
    // Colonnes : critères du barème (0 point = absent) ; feuille générique : tous
    $cols = [];
    foreach ($criteres as $k => $c) if (!$bar || (float) ($bar[$k] ?? 0) > 0) $cols[$k] = $c;
    $total_bar = $bar ? (float) $bar['total_points'] : null;
    $donnees = $notes($it);

    $entete = function () use ($pdf, $etab, $pw, $ml, $uw, $classe, $val_annee, $periode_lib, $annuel, $it, $enseignant, $piste) {
        pdf_filigrane($pdf, $etab, $pw, $pdf->GetPageHeight());
        pdf_entete($pdf, $etab, $pw, $ml, null, 0.85);
        pdf_bandeau($pdf, 'RELEVÉ DE NOTES' . ($piste === 'ar' ? ' (ARABE)' : ''), 'GRADE SHEET' . ($piste === 'ar' ? ' (ARABIC TRACK)' : ''), $pw, $ml);
        $ligne = function (string $lib, ?string $val, float $w) use ($pdf) {
            $pdf->SetFont('Arial', 'B', 8.5); $pdf->Cell($pdf->GetStringWidth(pdf_u($lib)) + 1.5, 5.5, pdf_u($lib), 0, 0);
            $pdf->SetFont('Arial', '', 8.5);
            $reste = $w - $pdf->GetStringWidth(pdf_u($lib)) - 1.5;
            if ($val !== null && $val !== '') $pdf->Cell($reste, 5.5, pdf_u(mb_strimwidth($val, 0, 95, '…')), 0, 0);
            else { $x = $pdf->GetX(); $y = $pdf->GetY() + 4.2; pdf_ligne_pointillee($pdf, $x, $y, $x + $reste - 2, $y); $pdf->Cell($reste, 5.5, '', 0, 0); }
        };
        $pdf->SetX($ml); $ligne('Classe : ', $classe['DesignationClasses'], $uw * 0.45); $ligne('Année scolaire : ', $val_annee, $uw * 0.55); $pdf->Ln(5.5);
        $pdf->SetX($ml); $ligne($annuel ? 'Période : ' : 'Évaluation : ', $periode_lib, $uw * 0.45); $ligne('Enseignant(e) : ', $enseignant, $uw * 0.55); $pdf->Ln(5.5);
        $pdf->SetX($ml); $ligne($piste === 'fr' ? 'Compétence : ' : 'Matière : ', $it ? $it['libelle'] : null, $uw); $pdf->Ln(5.5);
        if ($it && $it['groupe']) { $pdf->SetX($ml); $pdf->SetFont('Arial', 'I', 7.5); $pdf->Cell($uw, 4, pdf_u(($piste === 'fr' ? 'Groupe : ' : 'Groupe de matières : ') . $it['groupe']), 0, 1); }
        $pdf->Ln(2);
    };
    $w_no = 9; $w_sexe = 9; $w_tot = 18; $w_cote = 15;
    $w_nom = 62;
    $w_c = count($cols) ? ($uw - $w_no - $w_nom - $w_sexe - $w_tot - $w_cote) / count($cols) : 0;
    $entete_tableau = function () use ($fmt, $pdf, $ml, $w_no, $w_nom, $w_sexe, $w_c, $w_tot, $w_cote, $cols, $bar, $total_bar) {
        $pdf->SetX($ml); $pdf->SetFont('Arial', 'B', 7.5); $pdf->SetFillColor(30, 79, 216); $pdf->SetTextColor(255);
        $h = 10; $y = $pdf->GetY();
        $cell = function (float $w, string $l1, string $l2 = '') use ($pdf, $h, $y) {
            $x = $pdf->GetX(); $pdf->Rect($x, $y, $w, $h, 'DF');
            $pdf->SetXY($x, $y + ($l2 === '' ? 3 : 1.4)); $pdf->Cell($w, 3.6, pdf_u($l1), 0, 2, 'C');
            if ($l2 !== '') { $pdf->SetFont('Arial', '', 6.5); $pdf->Cell($w, 3.6, pdf_u($l2), 0, 0, 'C'); $pdf->SetFont('Arial', 'B', 7.5); }
            $pdf->SetXY($x + $w, $y);
        };
        $cell($w_no, 'N°'); $cell($w_nom, 'Noms et prénoms'); $cell($w_sexe, 'Sexe');
        foreach ($cols as $k => [$fr, $en]) $cell($w_c, $fr, $bar ? '/ ' . $fmt($bar[$k]) : $en);
        $cell($w_tot, 'Total', $total_bar ? '/ ' . $fmt($total_bar) : '');
        $cell($w_cote, 'Cote', 'NA·ECA·A·A+');
        $pdf->SetTextColor(0); $pdf->SetXY($pdf->GetX(), $y + $h); $pdf->Ln(0);
    };

    $pdf->AddPage();
    $entete();

    // ── Toute l'année : par trimestre, le total de chaque évaluation (UA) et la
    // note du trimestre (moyenne de ses évaluations), puis la note annuelle
    // (moyenne des trimestres) et sa cote — mêmes calculs que les bulletins.
    if ($annuel) {
        if ($bar) {
            $d = [];
            foreach ($cols as $k => [$fr]) $d[] = $fr . ' ' . $fmt($bar[$k]);
            $pdf->SetX($ml); $pdf->SetFont('Arial', '', 7.5);
            $pdf->Cell($uw, 4, pdf_u('Barème : ' . implode(' · ', $d) . ' = ' . $fmt($total_bar) . ' points. Chaque case : total de l\'évaluation sur ' . $fmt($total_bar) . '.'), 0, 1);
            $pdf->Ln(1);
        }
        $w_no = 9; $w_sexe = 8; $w_nom = 58; $w_an = 18; $w_cote = 14;
        $nb = 0;
        foreach ($trims as $t) $nb += max(1, count($t['seqs'])) + 1;
        $w_c = $nb ? ($uw - $w_no - $w_nom - $w_sexe - $w_an - $w_cote) / $nb : 0;
        $entete_annee = function () use ($pdf, $ml, $w_no, $w_nom, $w_sexe, $w_c, $w_an, $w_cote, $trims, $fmt, $total_bar) {
            $pdf->SetFont('Arial', 'B', 7.5); $pdf->SetFillColor(30, 79, 216); $pdf->SetTextColor(255); $pdf->SetDrawColor(0);
            $y = $pdf->GetY(); $h1 = 6; $h2 = 6;
            $bloc = function (float $x, float $yy, float $w, float $h, string $t, string $t2 = '') use ($pdf) {
                $pdf->Rect($x, $yy, $w, $h, 'DF');
                $pdf->SetXY($x, $yy + ($t2 === '' ? ($h - 3.6) / 2 : 0.8)); $pdf->Cell($w, 3.6, pdf_u($t), 0, 2, 'C');
                if ($t2 !== '') { $pdf->SetFont('Arial', '', 6.5); $pdf->Cell($w, 3.6, pdf_u($t2), 0, 0, 'C'); $pdf->SetFont('Arial', 'B', 7.5); }
            };
            $x = $ml;
            $bloc($x, $y, $w_no, $h1 + $h2, 'N°'); $x += $w_no;
            $bloc($x, $y, $w_nom, $h1 + $h2, 'Noms et prénoms'); $x += $w_nom;
            $bloc($x, $y, $w_sexe, $h1 + $h2, 'Sexe'); $x += $w_sexe;
            foreach ($trims as $t) {
                $nc = max(1, count($t['seqs'])) + 1;
                $bloc($x, $y, $w_c * $nc, $h1, $t['libelle_trim']);
                foreach ($t['seqs'] ?: [['libelle_seq' => 'Éval.']] as $s) { $bloc($x, $y + $h1, $w_c, $h2, $s['libelle_seq']); $x += $w_c; }
                $pdf->SetFillColor(21, 56, 160); $bloc($x, $y + $h1, $w_c, $h2, 'Moy.'); $pdf->SetFillColor(30, 79, 216); $x += $w_c;
            }
            $bloc($x, $y, $w_an, $h1 + $h2, 'Annuelle', $total_bar ? '/ ' . $fmt($total_bar) : ''); $x += $w_an;
            $bloc($x, $y, $w_cote, $h1 + $h2, 'Cote', 'NA·ECA·A·A+');
            $pdf->SetTextColor(0); $pdf->SetXY($ml, $y + $h1 + $h2);
        };
        $entete_annee();
        $h_row = 6; $n = 1; $remplies = 0;
        foreach ($eleves as $el) {
            if ($pdf->GetY() + $h_row > $ph - 26) { $pdf->AddPage(); pdf_filigrane($pdf, $etab, $pw, $ph); $pdf->SetY(12); $entete_annee(); }
            $id = (int) $el['id_eleve']; $fill = $n % 2 === 0;
            $pdf->SetX($ml); $pdf->SetFont('Arial', '', 7.5); $pdf->SetFillColor(243, 247, 253);
            $pdf->Cell($w_no, $h_row, (string) $n, 1, 0, 'C', $fill);
            $pdf->Cell($w_nom, $h_row, pdf_u(mb_strimwidth(mb_strtoupper((string) $el['Nom_elv']) . ' ' . ($el['Prenom_elv'] ?? ''), 0, 38, '…')), 1, 0, 'L', $fill);
            $pdf->Cell($w_sexe, $h_row, pdf_u(mb_strtoupper(mb_substr((string) $el['Sexe_elv'], 0, 1))), 1, 0, 'C', $fill);
            $moys = [];
            foreach ($trims as $t) {
                foreach ($t['seqs'] ?: [null] as $s) {
                    $txt = '';
                    if ($rempli && $it && $s) {
                        if (evaluation_annulee_pour_eleve($id, (int) $s['id_seq'], $val_annee)) $txt = 'ann.';
                        else {
                            $v = $piste === 'fr'
                                ? (note_detail_competence_sequence($id, $it['id'], $id_classe, (int) $s['id_seq'], $val_annee)['total_points'] ?? null)
                                : points_matiere_sequence_arabe($id, $it['id'], $id_classe, (int) $s['id_seq']);
                            $txt = $v !== null ? $fmt($v) : ($piste === 'fr' && absence_justifiee_val($id, $it['id'], (int) $s['id_seq'], $id_classe, $val_annee) ? 'abs.' : '');
                        }
                    }
                    $pdf->Cell($w_c, $h_row, pdf_u($txt), 1, 0, 'C', $fill);
                }
                $m = null;
                if ($rempli && $it) $m = $piste === 'fr'
                    ? note_competence_trimestre($id, $it['id'], $id_classe, (int) $t['id_trim'], $val_annee)['moyenne']
                    : points_matiere_trimestre_arabe($id, $it['id'], $id_classe, (int) $t['id_trim'], $val_annee);
                if ($m !== null) $moys[] = (float) $m;
                $pdf->SetFont('Arial', 'B', 7.5); $pdf->SetFillColor(228, 236, 252);
                $pdf->Cell($w_c, $h_row, $fmt($m), 1, 0, 'C', true);
                $pdf->SetFont('Arial', '', 7.5); $pdf->SetFillColor(243, 247, 253);
            }
            // Annuelle : moyenne des trimestres notés (comme note_competence_annuelle / note_matiere_annuelle_arabe)
            $an = $moys ? round(array_sum($moys) / count($moys), 2) : null;
            if ($an !== null) $remplies++;
            $pdf->SetFont('Arial', 'B', 7.5);
            $pdf->Cell($w_an, $h_row, $fmt($an), 1, 0, 'C', $fill);
            $pdf->Cell($w_cote, $h_row, pdf_u($an !== null && $total_bar ? appreciation_fr($an, $total_bar) : ''), 1, 0, 'C', $fill);
            $pdf->Ln($h_row);
            $n++;
        }
        if (!$eleves) { $pdf->SetX($ml); $pdf->Cell($uw, 8, pdf_u('Aucun élève inscrit dans cette classe.'), 1, 1, 'C'); }
    } else {
    $entete_tableau();
    $h_row = 6.2; $n = 1; $remplies = 0;
    foreach ($eleves as $el) {
        if ($pdf->GetY() + $h_row > $ph - 28) { $pdf->AddPage(); pdf_filigrane($pdf, $etab, $pw, $ph); $pdf->SetY(12); $entete_tableau(); }
        $id = (int) $el['id_eleve'];
        $d = $donnees[$id] ?? null;
        $annulee = $rempli && evaluation_annulee_pour_eleve($id, $id_seq, $val_annee);
        $abs_j = $rempli && !$d && $piste === 'fr' && $it && absence_justifiee_val($id, $it['id'], $id_seq, $id_classe, $val_annee);
        $pdf->SetX($ml); $pdf->SetFont('Arial', '', 7.5);
        $pdf->SetFillColor(243, 247, 253); $fill = $n % 2 === 0;
        $pdf->Cell($w_no, $h_row, (string) $n, 1, 0, 'C', $fill);
        $pdf->Cell($w_nom, $h_row, pdf_u(mb_strimwidth(mb_strtoupper((string) $el['Nom_elv']) . ' ' . ($el['Prenom_elv'] ?? ''), 0, 40, '…')), 1, 0, 'L', $fill);
        $pdf->Cell($w_sexe, $h_row, pdf_u(mb_strtoupper(mb_substr((string) $el['Sexe_elv'], 0, 1))), 1, 0, 'C', $fill);
        $total = null;
        foreach ($cols as $k => [, , $colonne]) {
            $v = $d[$colonne] ?? null;
            if ($v !== null) $total = ($total ?? 0) + (float) $v;
            $pdf->Cell($w_c, $h_row, $annulee || $abs_j ? '' : $fmt($v), 1, 0, 'C', $fill);
        }
        $pdf->SetFont('Arial', 'B', 7.5);
        if ($annulee || $abs_j) {
            $pdf->Cell($w_tot + $w_cote, $h_row, pdf_u($annulee ? 'Évaluation annulée' : 'Absence justifiée'), 1, 0, 'C', $fill);
        } else {
            $cote = $total !== null && $total_bar ? appreciation_fr($total, $total_bar) : '';   // mêmes seuils pour les deux pistes
            $pdf->Cell($w_tot, $h_row, $fmt($total), 1, 0, 'C', $fill);
            $pdf->Cell($w_cote, $h_row, pdf_u($cote), 1, 0, 'C', $fill);
            if ($total !== null) $remplies++;
        }
        $pdf->Ln($h_row);
        $n++;
    }
    if (!$eleves) { $pdf->SetX($ml); $pdf->Cell($uw, 8, pdf_u('Aucun élève inscrit dans cette classe.'), 1, 1, 'C'); }
    }   // fin relevé d'une évaluation

    // Pied : effectif, signatures (enseignant ; chef selon la décision du propriétaire)
    $pdf->Ln(2); $pdf->SetX($ml); $pdf->SetFont('Arial', '', 7.5);
    $nb = count($eleves); $nf = count(array_filter($eleves, fn($e) => mb_strtoupper(mb_substr((string) $e['Sexe_elv'], 0, 1)) === 'F'));
    $pdf->Cell($uw, 4, pdf_u("Effectif : $nb (F : $nf, G : " . ($nb - $nf) . ')' . ($rempli ? " — Élèves notés : $remplies" : '')
        . ' — Cote : NA non acquis, ECA en cours d\'acquisition, A acquis, A+ acquis avec facilité.'), 0, 1);
    $y = max($pdf->GetY() + 3, $ph - 30);
    $pdf->SetFont('Arial', '', 8);
    $pdf->SetXY($ml, $y); $pdf->Cell($uw / 2, 4.5, pdf_u('L\'enseignant(e)'), 0, 0, 'C');
    $pdf->Cell($uw / 2, 4.5, pdf_u(lieu_signature_pdf($etab) . 'le ' . date('d/m/Y')), 0, 1, 'C');
    $pdf->SetX($ml + $uw / 2); $pdf->SetFont('Arial', 'B', 8); $pdf->Cell($uw / 2, 4.5, pdf_u(mb_strtoupper($titre_chef)), 0, 1, 'C');
    if (($_GET['signature'] ?? '0') === '1') {
        $sw = 28;
        pdf_signature_appliquer_jn($pdf, 'releve_notes_evaluation', 0, 0, $pw, $ph,
            ['x_pct' => ($ml + $uw * 0.75 - $sw / 2) / $pw * 100, 'y_pct' => ($pdf->GetY() + 0.5) / $ph * 100, 'w_pct' => $sw / $pw * 100, 'h_pct' => null]);
    }
}

$nom = 'releve_' . ($rempli ? 'rempli' : 'vierge') . '_' . $piste . '_' . preg_replace('/\W+/u', '_', $classe['DesignationClasses'])
     . ($seq ? '_' . preg_replace('/\W+/u', '_', $seq['libelle_seq']) : '') . '.pdf';
$pdf->Output($dl ? 'D' : 'I', $nom);
