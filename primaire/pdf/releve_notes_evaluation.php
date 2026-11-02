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
// GET : piste (fr|ar), classe (« toutes » = toutes les classes visibles, à la suite), item (id_comp / id_mat ; 0 = feuille générique,
//       -1 = toutes), seq (évaluation ; 0 = à compléter ; « annee » = les 3 trimestres :/n//       critères et total de chaque évaluation, note du trimestre, note annuelle,/n//       cote — A4 paysage),/n//       mode (vierge|rempli), dl.
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../noyau/connexion.php';
require_once __DIR__ . '/../../noyau/fonctions.php';
require_once __DIR__ . '/../notes_apc.php';
require_once __DIR__ . '/../notes_apc_arabe.php';
exiger_acces_pedagogie();
require_once __DIR__ . '/fpdf.php';
require_once __DIR__ . '/header_pdf.php';

$piste = ($_GET['piste'] ?? 'fr') === 'ar' ? 'ar' : 'fr';
// Noms des élèves (piste arabe, 01/11/2026) : deux = arabe + français, ar = arabe, fr = français
$noms_choisis = $piste === 'ar' && in_array($_GET['noms'] ?? 'deux', ['deux', 'ar', 'fr'], true) ? ($_GET['noms'] ?? 'deux') : 'fr';
$noms = $noms_choisis;
$val_annee = (string) (get_annee_active()['val_annee'] ?? '');
// classe=toutes (01/11/2026) : toutes les classes visibles ayant des inscrits, à la suite
$toutes = ($_GET['classe'] ?? '') === 'toutes';
$id_classe = $toutes ? 0 : (int) ($_GET['classe'] ?? 0);
exiger_acces_classe($id_classe, $val_annee, $piste);   // enseignant : seulement ses classes
$item = (int) ($_GET['item'] ?? 0);
$annuel = ($_GET['seq'] ?? '') === 'annee';   // toute l'année : les 3 trimestres (26/10/2026)
$id_seq = $annuel ? 0 : (int) ($_GET['seq'] ?? 0);
$rempli = ($_GET['mode'] ?? '') === 'rempli';
$dl = ($_GET['dl'] ?? '0') === '1';
// Fiche ANONYME (26/10/2026) : ni classe, ni noms, ni sexe — autant de lignes que la plus
// grande classe de l'année, plus des lignes vides (10 par défaut) ; critères génériques.
// « vides » : lignes vides ajoutées sous la liste (aussi sur une fiche nominative vierge).
$anonyme = ($_GET['anonyme'] ?? '') === '1';
$vides = max(0, min(40, (int) ($_GET['vides'] ?? ($anonyme ? 10 : 0))));
if ($anonyme) { $id_classe = 0; $item = 0; $rempli = false; $toutes = false; }
if ($toutes) {
    if ($item > 0) $item = 0;   // compétences / matières propres à chaque classe : aucune, ou toutes
    $ids_classes = array_map('intval', array_column(filtrer_classes_visibles(db_all(
        "SELECT c.IDClasses FROM classe c LEFT JOIN niveau n ON n.LibelleNiveau = c.Niveau
         WHERE EXISTS (SELECT 1 FROM inscrire i WHERE i.IDClasses = c.IDClasses AND i.val_annee = ?)
         ORDER BY n.OrdreNiveau, c.DesignationClasses", [$val_annee]), $val_annee, $piste), 'IDClasses'));
    if (!$ids_classes) die('Aucune classe accessible.');
    @set_time_limit(300);
} else {
    $ids_classes = [$id_classe];
    if (!$anonyme && !db_val("SELECT 1 FROM classe WHERE IDClasses=?", [$id_classe])) die('Classe introuvable.');
}
$seq =$id_seq ? db_one("SELECT s.id_seq, s.libelle_seq, t.id_trim, t.libelle_trim FROM sequence s JOIN trimestre t ON t.id_trim=s.id_trim WHERE s.id_seq=? AND t.id_annee=?", [$id_seq, $val_annee]) : null;
if ($rempli && !$seq && !$annuel) $rempli = false;   // rempli : une évaluation précise, ou toute l'année
// Année : trimestres et leurs évaluations, dans l'ordre
$trims = [];
if ($annuel) foreach (db_all("SELECT id_trim, libelle_trim FROM trimestre WHERE id_annee=? ORDER BY id_trim", [$val_annee]) as $t)
    $trims[] = $t + ['seqs' => db_all("SELECT id_seq, libelle_seq FROM sequence WHERE id_trim=? ORDER BY id_seq", [$t['id_trim']])];

// Critères : clé du barème => [libellé FR, libellé EN, colonne de note]
$criteres = $piste === 'fr'
    ? ['orale' => ['Orale', 'Oral', 'note_orale'], 'ecrite' => ['Écrite', 'Written', 'note_ecrite'], 'pratique' => ['Pratique', 'Practical', 'note_pratique'], 'savoir_etre' => ['Savoir-être', 'Attitude', 'note_savoir_etre']]
    : ['orale' => ['Oral', 'Oral', 'note_orale'], 'ecrite' => ['Écrit', 'Written', 'note_ecrite'], 'pratique' => ['Pratique', 'Practical', 'note_pratique']];

$etab = etab_pour_pdf(get_etablissement());
[$titre_chef, $titre_chef_en] = titres_chef_pdf(get_etablissement());
$nom_ens = fn(?array $t) => $t ? trim(($t['nom_ens'] ?? '') . ' ' . ($t['prenom_ens'] ?? '')) : null;
$periode_lib = $annuel ? "Toute l'année (" . count($trims) . ' trimestres)' : ($seq ? $seq['libelle_seq'] . ' — ' . $seq['libelle_trim'] : null);
$fmt = fn($v) => $v === null || $v === '' ? '' : rtrim(rtrim(number_format((float) $v, 2, ',', ''), '0'), ',');
// Cellule du nom (01/11/2026) : MÊME taille pour tous les noms de la colonne ;
// réduite (de $max à $min pt) seulement pour un nom trop long, tronqué s'il ne
// tient toujours pas à la plus petite taille.
// Piste arabe ($noms) : « deux » = nom arabe en haut à droite, nom français dessous ;
// « ar » = nom arabe seul (français si l'élève n'a pas de nom arabe) ; « fr » = français.
$cellule_nom = function (FPDF $pdf, float $w, float $h, array $el, bool $fill, float $max, float $min) use (&$noms): void {
    $nom = pdf_u(trim(mb_strtoupper((string) $el['Nom_elv']) . ' ' . ($el['Prenom_elv'] ?? '')));
    $ar = $noms !== 'fr' ? trim((string) ($el['Nom_arabe_elv'] ?? '')) : '';
    $x = $pdf->GetX(); $y = $pdf->GetY();
    $pdf->Cell($w, $h, '', 1, 0, 'L', $fill);
    // nom arabe : hauteur commune (pdf_texte_arabe), réduit seulement s'il est trop large
    if ($ar !== '') pdf_texte_arabe($pdf, $ar, $x, $y + 0.2, $w, $noms === 'deux' ? $h * 0.6 - 0.2 : $h - 0.4, 30);
    if ($ar !== '' && $noms === 'ar') { $pdf->SetXY($x + $w, $y); return; }
    $t = $noms === 'deux' ? $max - 1 : $max;
    $pdf->SetFont('Arial', '', $t);
    while ($t > $min && $pdf->GetStringWidth($nom) > $w - 1.5) { $t -= 0.5; $pdf->SetFont('Arial', '', $t); }
    while (strlen($nom) > 4 && $pdf->GetStringWidth($nom) > $w - 1.5) $nom = substr($nom, 0, -2) . '.';
    if ($noms === 'deux') { $pdf->SetXY($x, $y + $h * 0.6); $pdf->Cell($w, $h * 0.4, $nom, 0, 0, 'L'); }   // même place, avec ou sans nom arabe
    else { $pdf->SetXY($x, $y); $pdf->Cell($w, $h, $nom, 0, 0, 'L'); }
    $pdf->SetXY($x + $w, $y);
};

$pdf = new FPDF($annuel ? 'L' : 'P', 'mm', 'A4');   // l'année : paysage (une colonne par évaluation)
$ml = $annuel ? 7 : 10;   // l'année : marges réduites (une trentaine de colonnes)
$pdf->SetMargins($ml, $ml, $ml);
$pdf->SetAutoPageBreak(false);
$pw = $pdf->GetPageWidth(); $ph = $pdf->GetPageHeight(); $uw = $pw - 2 * $ml;

// Tableau statistique des effectifs (01/11/2026, comme le relevé du secondaire) :
// Nouveaux / Redoublants / Total × Garçons / Filles / Total, en haut à droite.
// $eff null (fiche anonyme) : cases vides, à remplir à la main.
$tab_w_lib = 24; $tab_w_n = 14; $tab_h = 5;
$tab_w = $tab_w_lib + 3 * $tab_w_n;
$tableau_effectifs = function (?array $eff, float $x, float $y) use ($pdf, $tab_w_lib, $tab_w_n, $tab_h): void {
    $pdf->SetDrawColor(30, 79, 216); $pdf->SetLineWidth(0.2); $pdf->SetTextColor(18, 42, 96);
    $pdf->SetFillColor(204, 222, 248); $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetXY($x, $y);
    $pdf->Cell($tab_w_lib, $tab_h, 'Effectif', 1, 0, 'C', true);
    foreach (['Garçons', 'Filles', 'Total'] as $t) $pdf->Cell($tab_w_n, $tab_h, pdf_u($t), 1, 0, 'C', true);
    foreach (['nouveau' => 'Nouveaux', 'redoublant' => 'Redoublants', 'total' => 'Total'] as $k => $lib) {
        $y += $tab_h; $pdf->SetXY($x, $y);
        $pdf->SetFillColor(204, 222, 248); $pdf->SetFont('Arial', 'B', 8.5); $pdf->SetTextColor(18, 42, 96);
        $pdf->Cell($tab_w_lib, $tab_h, pdf_u($lib), 1, 0, 'L', true);
        $pdf->SetTextColor(0); $pdf->SetFillColor(236, 241, 252); $pdf->SetFont('Arial', $k === 'total' ? 'B' : '', 9.5);
        foreach (['M', 'F', 'T'] as $c) $pdf->Cell($tab_w_n, $tab_h, $eff === null ? '' : (string) $eff[$k][$c], 1, 0, 'C', $k === 'total');
    }
    $pdf->SetTextColor(0); $pdf->SetDrawColor(0);
};

foreach ($ids_classes as $id_classe) {
$classe = $id_classe ? db_one("SELECT * FROM classe WHERE IDClasses=?", [$id_classe]) : null;

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
if (!$pages) $pages = [null];   // « toutes » sans compétence / matière configurée : feuille générique

$eleves = $anonyme ? [] : db_all("SELECT e.id_eleve, e.Nom_elv, e.Prenom_elv, e.Nom_arabe_elv, e.Sexe_elv, i.Statut_elv FROM eleve e JOIN inscrire i ON i.id_eleve=e.id_eleve
                  WHERE i.IDClasses=? AND i.val_annee=? ORDER BY e.Nom_elv, e.Prenom_elv", [$id_classe, $val_annee]);
$nb_reels = count($eleves);
// aucun nom arabe dans la classe : noms en français, lignes normales
$noms = $noms_choisis !== 'fr' && array_filter(array_column($eleves, 'Nom_arabe_elv'), fn($v) => trim((string) $v) !== '') ? $noms_choisis : 'fr';
$deux_noms = $noms === 'deux';
$eff = null;
if (!$anonyme) {
    $eff = [];
    foreach (['nouveau', 'redoublant', 'total'] as $k) $eff[$k] = ['M' => 0, 'F' => 0, 'T' => 0];
    foreach ($eleves as $e) {
        $sx = stripos((string) $e['Sexe_elv'], 'F') === 0 ? 'F' : 'M';
        foreach ([($e['Statut_elv'] ?? 'Non') === 'Oui' ? 'redoublant' : 'nouveau', 'total'] as $k) { $eff[$k][$sx]++; $eff[$k]['T']++; }
    }
}
$lignes_vides = ($anonyme ? (int) db_val("SELECT MAX(n) FROM (SELECT COUNT(*) n FROM inscrire WHERE val_annee=? GROUP BY IDClasses) t", [$val_annee]) : 0) + $vides;
for ($i = 0; $i < $lignes_vides; $i++) $eleves[] = ['id_eleve' => 0, 'Nom_elv' => '', 'Prenom_elv' => '', 'Nom_arabe_elv' => '', 'Sexe_elv' => '', 'Statut_elv' => ''];
$enseignant = null;
if ($seq) $enseignant = $nom_ens(prim_titulaire_trimestre($id_classe, $val_annee, (int) $seq['id_trim'], $piste));
if ($annuel) {   // un nom, ou un par trimestre si l'enseignant a changé en cours d'année
    $parT = [];
    foreach ($trims as $i => $t) if ($n = $nom_ens(prim_titulaire_trimestre($id_classe, $val_annee, (int) $t['id_trim'], $piste))) $parT['T' . ($i + 1)] = $n;
    $enseignant = count(array_unique($parT)) > 1 ? implode(' ; ', array_map(fn($k, $v) => "$k : $v", array_keys($parT), $parT)) : (array_values($parT)[0] ?? null);
}

/** Notes saisies d'une évaluation pour un item : [id_eleve => ligne] ; null si vierge. */
$notes = function (?array $it) use ($rempli, $piste, $id_classe, $id_seq, $val_annee): array {
    if (!$rempli || !$it) return [];
    $rows = $piste === 'fr'
        ? db_all("SELECT id_eleve, note_orale, note_ecrite, note_pratique, note_savoir_etre FROM composer_sequence WHERE IDClasses=? AND id_comp=? AND id_seq=? AND val_annee=?", [$id_classe, $it['id'], $id_seq, $val_annee])
        : db_all("SELECT id_eleve, note_orale, note_ecrite, note_pratique FROM composer_sequence_arabe WHERE classe=? AND id_mat=? AND id_seq=?", [$id_classe, $it['id'], $id_seq]);
    return array_column($rows, null, 'id_eleve');
};

foreach ($pages as $it) {
    $bar = $it['bareme'] ?? null;
    // Feuille sans compétence / matière choisie (01/11/2026) : barème commun à toutes celles
    // de la classe s'il est identique (ex. arabe : toutes les matières sur 5 / 5 / 10 = 20) ;
    // aussi pour chaque classe de « toutes les classes » (02/11/2026) ; barèmes différents
    // ou fiche anonyme : laissé vide. Lu à chaque
    // impression : un barème modifié dans les paramètres est repris aussitôt.
    if (!$it && !$anonyme && $items) {
        $cles = array_merge(array_keys($criteres), ['total_points']);
        $sig = fn($b) => $b ? implode('|', array_map(fn($k) => (float) ($b[$k] ?? 0), $cles)) : '';
        $sigs = array_unique(array_map(fn($i) => $sig($i['bareme']), $items));
        if (count($sigs) === 1 && reset($sigs) !== '') $bar = $items[0]['bareme'];
    }
    // Colonnes : critères du barème (0 point = absent) ; feuille générique : tous
    $cols = [];
    foreach ($criteres as $k => $c) if (!$bar || (float) ($bar[$k] ?? 0) > 0) $cols[$k] = $c;
    $total_bar = $bar ? (float) $bar['total_points'] : null;
    $donnees = $notes($it);

    $entete = function () use ($pdf, $etab, $pw, $ml, $uw, $classe, $val_annee, $periode_lib, $annuel, $it, $enseignant, $piste, $eff, $tableau_effectifs, $tab_w, $tab_h) {
        pdf_filigrane($pdf, $etab, $pw, $pdf->GetPageHeight());
        pdf_entete($pdf, $etab, $pw, $ml, null, 0.85);
        pdf_bandeau($pdf, 'RELEVÉ DE NOTES' . ($piste === 'ar' ? ' (ARABE)' : ''), 'GRADE SHEET' . ($piste === 'ar' ? ' (ARABIC TRACK)' : ''), $pw, $ml);
        $ligne = function (string $lib, ?string $val, float $w) use ($pdf) {
            $pdf->SetFont('Arial', 'B', 13); $wl = $pdf->GetStringWidth(pdf_u($lib)) + 1.5;   // mesuré en gras, comme imprimé
            $pdf->Cell($wl, 8, pdf_u($lib), 0, 0);
            $pdf->SetFont('Arial', '', 13);
            $reste = $w - $wl;
            if ($val !== null && $val !== '') {
                while (mb_strlen($val) > 4 && $pdf->GetStringWidth(pdf_u($val)) > $reste - 1) $val = mb_substr($val, 0, -2) . '.';   // tient sur la ligne
                $pdf->Cell($reste, 8, pdf_u($val), 0, 0);
            }
            else { $x = $pdf->GetX(); $y = $pdf->GetY() + 6.4; pdf_ligne_pointillee($pdf, $x, $y, $x + $reste - 2, $y); $pdf->Cell($reste, 8, '', 0, 0); }
        };
        // Informations à gauche, tableau statistique des effectifs à droite (01/11/2026)
        $y0 = $pdf->GetY(); $wi = $uw - $tab_w - 4;
        $pdf->SetX($ml); $ligne('Classe : ', $classe['DesignationClasses'] ?? null, $wi * 0.45); $ligne('Année scolaire : ', $val_annee, $wi * 0.55); $pdf->Ln(7.5);
        $pdf->SetX($ml); $ligne($annuel ? 'Période : ' : 'Évaluation : ', $periode_lib, $wi); $pdf->Ln(7.5);
        $pdf->SetX($ml); $ligne('Enseignant(e) : ', $enseignant, $wi); $pdf->Ln(7.5);
        $y1 = $pdf->GetY();
        $tableau_effectifs($eff, $ml + $uw - $tab_w, $y0 + 1);
        $pdf->SetY(max($y1, $y0 + 1 + 4 * $tab_h + 1));
        $pdf->SetX($ml); $ligne($piste === 'fr' ? 'Compétence : ' : 'Matière : ', $it ? $it['libelle'] : null, $uw); $pdf->Ln(8);
        if ($it && $it['groupe']) { $pdf->SetX($ml); $pdf->SetFont('Arial', 'I', 11); $pdf->Cell($uw, 6, pdf_u(($piste === 'fr' ? 'Groupe : ' : 'Groupe de matières : ') . $it['groupe']), 0, 1); }
        $pdf->Ln(2);
    };
    $w_no = 9; $w_sexe = 9; $w_tot = 18; $w_cote = 15;
    $w_nom = 62;
    $w_c = count($cols) ? ($uw - $w_no - $w_nom - $w_sexe - $w_tot - $w_cote) / count($cols) : 0;
    $entete_tableau = function () use ($fmt, $pdf, $ml, $w_no, $w_nom, $w_sexe, $w_c, $w_tot, $w_cote, $cols, $bar, $total_bar) {
        $pdf->SetX($ml); $pdf->SetFont('Arial', 'B', 10.5); $pdf->SetFillColor(204, 222, 248); $pdf->SetTextColor(18, 42, 96);
        $h = 13; $y = $pdf->GetY();
        $cell = function (float $w, string $l1, string $l2 = '') use ($pdf, $h, $y) {
            $x = $pdf->GetX(); $pdf->Rect($x, $y, $w, $h, 'DF');
            $pdf->SetXY($x, $y + ($l2 === '' ? 4 : 1.6)); $pdf->Cell($w, 5, pdf_u($l1), 0, 2, 'C');
            if ($l2 !== '') { $pdf->SetFont('Arial', '', 8.5); $pdf->Cell($w, 4.6, pdf_u($l2), 0, 0, 'C'); $pdf->SetFont('Arial', 'B', 10.5); }
            $pdf->SetXY($x + $w, $y);
        };
        $cell($w_no, 'N°'); $cell($w_nom, 'Noms et prénoms'); $cell($w_sexe, 'Sexe');
        foreach ($cols as $k => [$fr, $en]) $cell($w_c, $fr, $bar ? '/ ' . $fmt($bar[$k]) : '');   // barème inconnu : vide
        $cell($w_tot, 'Total', $total_bar ? '/ ' . $fmt($total_bar) : '');
        $cell($w_cote, 'Cote', 'NA·ECA·A·A+');
        $pdf->SetTextColor(0); $pdf->SetXY($pdf->GetX(), $y + $h); $pdf->Ln(0);
    };

    $pdf->AddPage();
    $entete();

    // ── Toute l'année sur UNE fiche (26/10/2026) : pour chaque trimestre, chaque
    // évaluation (UA) avec le détail de ses critères (Orale / Écrite / Pratique /
    // Savoir-être en français ; Oral / Écrit / Pratique en arabe) et son total,
    // puis la note du trimestre (moyenne de ses évaluations) ; enfin la note
    // annuelle (moyenne des trimestres) et sa cote — mêmes calculs que les bulletins.
    if ($annuel) {
        $abr = $piste === 'fr' ? ['orale' => 'O', 'ecrite' => 'É', 'pratique' => 'P', 'savoir_etre' => 'SE'] : ['orale' => 'O', 'ecrite' => 'É', 'pratique' => 'P'];
        $legende = [];
        foreach ($cols as $k => [$fr]) $legende[] = $abr[$k] . ' = ' . $fr . ($bar ? ' / ' . $fmt($bar[$k]) : '');
        $pdf->SetX($ml); $pdf->SetFont('Arial', '', 10.5);
        // Vierge (26/10/2026) : fiche de l'année à remplir au stylo — ni Tot., ni Annuelle,
        // ni Cote, et des cases plus hautes et plus larges.
        $pdf->Cell($uw, 6, pdf_u(implode(' · ', $legende) . ($rempli ? ' · Tot. = total de l\'évaluation' . ($total_bar ? ' / ' . $fmt($total_bar) : '') : '')
            . ($rempli ? ' · Moy. = note du trimestre.' : '.')), 0, 1);
        $pdf->Ln(0.5);
        if ($rempli && $it) $piste === 'fr' ? precharger_notes_sequence_classe($id_classe, $val_annee) : precharger_notes_sequence_classe_arabe($id_classe);
        $nb_crit = count($cols) + ($rempli ? 1 : 0);   // critères (+ Tot. au rempli) pour chaque évaluation
        // Vierge : ni Moy. — la place va à la colonne des noms, écrits à la main (26/10/2026)
        // Pas de colonne Sexe (26/10/2026) : sa largeur va aux noms
        // Colonne Sexe juste après le nom (01/11/2026), prise sur la largeur des noms
        // Ni « Annuelle » ni « Cote » (01/11/2026) : leur largeur va à la colonne des noms
        $w_no = 6; $w_sexe = $rempli ? 6 : 9; $w_nom = $rempli ? 63.5 : 76.5; $w_an = 0; $w_cote = 0;
        $nb = 0;
        foreach ($trims as $t) $nb += max(1, count($t['seqs'])) * $nb_crit + ($rempli ? 1 : 0);
        $w_c = $nb ? ($uw - $w_no - $w_nom - $w_sexe - $w_an - $w_cote) / $nb : 0;
        $entete_annee = function () use ($rempli, $pdf, $ml, $w_no, $w_nom, $w_sexe, $w_c, $w_an, $w_cote, $trims, $fmt, $total_bar, $cols, $abr, $bar, $nb_crit) {
            $pdf->SetFillColor(204, 222, 248); $pdf->SetTextColor(18, 42, 96); $pdf->SetDrawColor(0); $pdf->SetLineWidth(0.15);
            $y = $pdf->GetY(); $h1 = 7.5; $h2 = 6.5; $h3 = 9.5; $H = $h1 + $h2 + $h3;
            $bloc = function (float $x, float $yy, float $w, float $h, string $t, string $t2 = '', float $taille = 0) use ($pdf, $rempli) {
                $pdf->Rect($x, $yy, $w, $h, 'DF');
                $pdf->SetFont('Arial', 'B', $taille ?: ($rempli ? 9 : 10.5));   // rempli : cases étroites
                $pdf->SetXY($x, $yy + ($t2 === '' ? ($h - 4.6) / 2 : 0.6)); $pdf->Cell($w, 4.6, pdf_u($t), 0, 2, 'C');
                if ($t2 !== '') { $pdf->SetFont('Arial', '', 8); $pdf->Cell($w, 3.8, pdf_u($t2), 0, 0, 'C'); }
            };
            $x = $ml;
            $bloc($x, $y, $w_no, $H, 'N°'); $x += $w_no;
            $bloc($x, $y, $w_nom, $H, 'Noms et prénoms', '', 12); $x += $w_nom;
            $bloc($x, $y, $w_sexe, $H, $rempli ? 'S.' : 'Sexe', '', $rempli ? 8 : 9); $x += $w_sexe;
            foreach ($trims as $t) {
                $seqs = $t['seqs'] ?: [['libelle_seq' => 'Éval.']];
                $bloc($x, $y, $w_c * (count($seqs) * $nb_crit + ($rempli ? 1 : 0)), $h1, $t['libelle_trim'], '', 12);
                foreach ($seqs as $s) {
                    $bloc($x, $y + $h1, $w_c * $nb_crit, $h2, $s['libelle_seq']);
                    foreach ($cols as $k => $_) { $bloc($x, $y + $h1 + $h2, $w_c, $h3, $abr[$k], $bar ? '/' . $fmt($bar[$k]) : ''); $x += $w_c; }
                    if ($rempli) $pdf->SetFillColor(222, 234, 251); if ($rempli) $bloc($x, $y + $h1 + $h2, $w_c, $h3, 'Tot.', $total_bar ? '/' . $fmt($total_bar) : ''); $pdf->SetFillColor(204, 222, 248); if ($rempli) $x += $w_c;
                }
                if ($rempli) $pdf->SetFillColor(184, 207, 243); if ($rempli) $bloc($x, $y + $h1, $w_c, $h2 + $h3, 'Moy.', $total_bar ? '/' . $fmt($total_bar) : ''); $pdf->SetFillColor(204, 222, 248); if ($rempli) $x += $w_c;
            }
            $pdf->SetTextColor(0); $pdf->SetXY($ml, $y + $H);
        };
        // Détail d'une évaluation : [critère => note, 'total' => total] ou 'ann.' / 'abs.' / null
        $detail = function (int $id, ?array $s) use ($rempli, $it, $piste, $id_classe, $val_annee) {
            if (!$rempli || !$it || !$s) return null;
            $sq = (int) $s['id_seq'];
            if (evaluation_annulee_pour_eleve($id, $sq, $val_annee)) return 'ann.';
            if ($piste === 'fr') {
                $d = note_detail_competence_sequence($id, $it['id'], $id_classe, $sq, $val_annee);
                if (!$d) return absence_justifiee_val($id, $it['id'], $sq, $id_classe, $val_annee) ? 'abs.' : null;
                return $d + ['total' => $d['total_points']];
            }
            $r = _cache_composer_sequence_classe_arabe()[$id_classe][$id . '|' . $it['id'] . '|' . $sq] ?? null;
            if (!$r || ($r['note_orale'] === null && $r['note_ecrite'] === null && $r['note_pratique'] === null)) return null;
            return ['orale' => $r['note_orale'], 'ecrite' => $r['note_ecrite'], 'pratique' => $r['note_pratique'],
                    'total' => (float) $r['note_orale'] + (float) $r['note_ecrite'] + (float) $r['note_pratique']];
        };
        $entete_annee();
        $h_row = $rempli ? ($deux_noms ? 8.2 : 6.2) : 9.5; $n = 1; $remplies = 0;   // vierge : de quoi écrire au stylo
        foreach ($eleves as $el) {
            if ($pdf->GetY() + $h_row > $ph - 24) { $pdf->AddPage(); pdf_filigrane($pdf, $etab, $pw, $ph); $pdf->SetY(10); $entete_annee(); }
            $id = (int) $el['id_eleve']; $fill = $n % 2 === 0;
            $pdf->SetX($ml); $pdf->SetFont('Arial', '', $rempli ? 8 : 12); $pdf->SetFillColor(243, 247, 253);
            $pdf->Cell($w_no, $h_row, (string) $n, 1, 0, 'C', $fill);
            $cellule_nom($pdf, $w_nom, $h_row, $el, $fill, $rempli ? 8 : 10.5, $rempli ? 5.5 : 7);
            $pdf->SetFont('Arial', '', $rempli ? 7.5 : 10);
            $pdf->Cell($w_sexe, $h_row, pdf_u(mb_strtoupper(mb_substr((string) $el['Sexe_elv'], 0, 1))), 1, 0, 'C', $fill);
            $moys = [];
            foreach ($trims as $t) {
                foreach ($t['seqs'] ?: [null] as $s) {
                    $d = $detail($id, $s);
                    $pdf->SetFont('Arial', '', 7.5);
                    if (is_string($d)) {   // évaluation annulée / absence justifiée : sur toute la largeur de l'évaluation
                        $pdf->Cell($w_c * $nb_crit, $h_row, pdf_u($d === 'ann.' ? 'annulée' : 'abs. justifiée'), 1, 0, 'C', $fill);
                        continue;
                    }
                    foreach ($cols as $k => $_) $pdf->Cell($w_c, $h_row, $d ? $fmt($d[$k] ?? null) : '', 1, 0, 'C', $fill);
                    if (!$rempli) continue;
                    $pdf->SetFont('Arial', 'B', 7.5); $pdf->SetFillColor(236, 241, 252);
                    $pdf->Cell($w_c, $h_row, $d ? $fmt($d['total']) : '', 1, 0, 'C', true);
                    $pdf->SetFillColor(243, 247, 253);
                }
                $m = null;
                if ($rempli && $it) $m = $piste === 'fr'
                    ? note_competence_trimestre($id, $it['id'], $id_classe, (int) $t['id_trim'], $val_annee)['moyenne']
                    : points_matiere_trimestre_arabe($id, $it['id'], $id_classe, (int) $t['id_trim'], $val_annee);
                if ($m !== null) $moys[] = (float) $m;
                if (!$rempli) continue;
                $pdf->SetFont('Arial', 'B', 7.5); $pdf->SetFillColor(222, 231, 251);
                $pdf->Cell($w_c, $h_row, $fmt($m), 1, 0, 'C', true);
                $pdf->SetFillColor(243, 247, 253);
            }
            // Annuelle : moyenne des trimestres notés (comme note_competence_annuelle / note_matiere_annuelle_arabe)
            $an = $moys ? round(array_sum($moys) / count($moys), 2) : null;
            if ($an !== null) $remplies++;
            $pdf->SetFont('Arial', 'B', 8.5);
            $pdf->Ln($h_row);
            $n++;
        }
        if (!$eleves) { $pdf->SetX($ml); $pdf->Cell($uw, 8, pdf_u('Aucun élève inscrit dans cette classe.'), 1, 1, 'C'); }
    } else {
    $entete_tableau();
    $h_row = $deux_noms ? 9.5 : 8.5; $n = 1; $remplies = 0;
    foreach ($eleves as $el) {
        if ($pdf->GetY() + $h_row > $ph - 28) { $pdf->AddPage(); pdf_filigrane($pdf, $etab, $pw, $ph); $pdf->SetY(12); $entete_tableau(); }
        $id = (int) $el['id_eleve'];
        $d = $donnees[$id] ?? null;
        $annulee = $rempli && evaluation_annulee_pour_eleve($id, $id_seq, $val_annee);
        $abs_j = $rempli && !$d && $piste === 'fr' && $it && absence_justifiee_val($id, $it['id'], $id_seq, $id_classe, $val_annee);
        $pdf->SetX($ml); $pdf->SetFont('Arial', '', 11);
        $pdf->SetFillColor(243, 247, 253); $fill = $n % 2 === 0;
        $pdf->Cell($w_no, $h_row, (string) $n, 1, 0, 'C', $fill);
        $cellule_nom($pdf, $w_nom, $h_row, $el, $fill, 9.5, 6.5);
        $pdf->SetFont('Arial', '', 11);
        $pdf->Cell($w_sexe, $h_row, pdf_u(mb_strtoupper(mb_substr((string) $el['Sexe_elv'], 0, 1))), 1, 0, 'C', $fill);
        $total = null;
        foreach ($cols as $k => [, , $colonne]) {
            $v = $d[$colonne] ?? null;
            if ($v !== null) $total = ($total ?? 0) + (float) $v;
            $pdf->Cell($w_c, $h_row, $annulee || $abs_j ? '' : $fmt($v), 1, 0, 'C', $fill);
        }
        $pdf->SetFont('Arial', 'B', 11);
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

    // Pied : élèves notés, cotes, signatures (enseignant ; chef selon la décision du propriétaire).
    // L'effectif est dans le tableau statistique de l'en-tête (01/11/2026).
    $pdf->Ln(2); $pdf->SetX($ml); $pdf->SetFont('Arial', '', 10.5);
    $pied = array_filter([$rempli ? "Élèves notés : $remplies / $nb_reels" : '',
                          $annuel ? '' : 'Cote : NA non acquis, ECA en cours d\'acquisition, A acquis, A+ acquis avec facilité.']);
    if ($pied) $pdf->MultiCell($uw, 5.5, pdf_u(implode(' — ', $pied)), 0, 'L');   // texte agrandi : retour à la ligne si besoin
    // Signatures 2 lignes sous le tableau (02/11/2026 : plus repoussées en bas de page) ;
    // nouvelle page seulement si le bloc (cachet / signature compris) n'y tient plus.
    $y = $pdf->GetY() + 6;
    if ($y + 34 > $ph - 8) { $pdf->AddPage(); pdf_filigrane($pdf, $etab, $pw, $ph); $y = 15; }
    $pdf->SetFont('Arial', '', 11.5);
    $pdf->SetXY($ml, $y); $pdf->Cell($uw / 2, 4.5, pdf_u('L\'enseignant(e)'), 0, 0, 'C');
    $pdf->Cell($uw / 2, 6, pdf_u(lieu_signature_pdf($etab) . 'le ' . date('d/m/Y')), 0, 1, 'C');
    $pdf->SetX($ml + $uw / 2); $pdf->SetFont('Arial', 'B', 11.5); $pdf->Cell($uw / 2, 6, pdf_u(mb_strtoupper($titre_chef)), 0, 1, 'C');
    if (($_GET['signature'] ?? '0') === '1') {
        $sw = 28;
        pdf_signature_appliquer_jn($pdf, 'releve_notes_evaluation', 0, 0, $pw, $ph,
            ['x_pct' => ($ml + $uw * 0.75 - $sw / 2) / $pw * 100, 'y_pct' => ($pdf->GetY() + 0.5) / $ph * 100, 'w_pct' => $sw / $pw * 100, 'h_pct' => null]);
    }
}   // fin page (compétence / matière)
}   // fin classe

$nom = 'releve_' . ($rempli ? 'rempli' : 'vierge') . '_' . $piste . '_' . ($toutes ? 'toutes_classes' : preg_replace('/\W+/u', '_', $classe['DesignationClasses'] ?? 'anonyme'))
     . ($seq ? '_' . preg_replace('/\W+/u', '_', $seq['libelle_seq']) : '') . '.pdf';
$pdf->Output($dl ? 'D' : 'I', $nom);
