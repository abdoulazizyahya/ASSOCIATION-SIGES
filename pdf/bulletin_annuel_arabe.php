<?php
// ── PDF : Bulletin annuel — piste arabe (TCPDF, RTL réel) ──────────
// En-tête/photo/grille élève communs avec pdf/bulletin_trimestriel_arabe.php.
// Tableau des matières 1er/2è/3è Trim + Moyenne/Rang/Appréciations
// (note_matiere_annuelle_arabe() donne le détail par trimestre), bloc
// RESULTATS DE L'ELEVE en 4 colonnes (3 trimestres + annuelle).
//
// GET : id (bulletin d'UN élève) — OU classe (tous les élèves classés).
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
require_once __DIR__ . '/../notes_apc.php';
require_once __DIR__ . '/../notes_apc_arabe.php';
require_once __DIR__ . '/verif_lib.php';

// Accès public via le QR code du bulletin (jeton "vh") — voir
// pdf/bulletin_annuel.php pour l'explication (même mécanisme, piste='ar' ici).
$acces_public = false;
if (($_GET['vh'] ?? '') !== '' && (int) ($_GET['id'] ?? 0) > 0) {
    $val_annee_pub = get_annee_active()['val_annee'] ?? '';
    $acces_public = bulletin_verif_valider(
        (int) $_GET['id'], 'annee', (int) substr($val_annee_pub, 0, 4), 'ar', (string) $_GET['vh']
    ) !== null;
}
if (!$acces_public) exiger_acces_pedagogie();

require_once __DIR__ . '/header_pdf.php';
require_once __DIR__ . '/../pdf/tcpdf/config/tcpdf_config.php';
require_once __DIR__ . '/../pdf/tcpdf/tcpdf.php';
require_once __DIR__ . '/header_pdf_tcpdf.php';

$id        = (int) ($_GET['id'] ?? 0);
$id_classe = (int) ($_GET['classe'] ?? 0);
$dl        = ($_GET['dl'] ?? '0') === '1';
$avec_sig  = ($_GET['signature'] ?? '0') === '1';
if (!$id && !$id_classe) die('Paramètre id (ou classe) manquant.');

$annee     = get_annee_active();
$val_annee = $annee['val_annee'] ?? '';
if (!$acces_public) {                       // cloisonnement enseignant (piste arabe)
    exiger_acces_classe($id_classe, $val_annee, 'ar');
    exiger_acces_eleve($id, 'ar');
}
// 🐛 `annee_scolaire` n'a pas de colonne `id` — ne pas y chercher un id_annee.
$id_annee  = (int) substr($val_annee, 0, 4);
$etab_brut = get_etablissement();
$etab      = etab_pour_pdf($etab_brut);
$etab_ar   = $etab_brut;

if ($id) {
    $eleve = db_one("SELECT * FROM eleve WHERE id_eleve=?", [$id]);
    if (!$eleve) die('Élève introuvable.');
    $insc = db_one(
        "SELECT i.*, c.DesignationClasses AS classe
         FROM inscrire i JOIN classe c ON c.IDClasses=i.IDClasses
         WHERE i.id_eleve=? AND i.val_annee=? LIMIT 1",
        [$id, $val_annee]
    );
    if (!$insc) die("Aucune inscription active pour cet élève sur l'année en cours.");
    $liste = [['id_eleve' => $id, 'id_classe' => (int) $insc['IDClasses'], 'classe_nom' => $insc['classe']]];
    $effectif_classe = (int) db_val("SELECT COUNT(*) FROM inscrire WHERE IDClasses=? AND val_annee=?", [(int) $insc['IDClasses'], $val_annee]);
} else {
    $classe = db_one("SELECT DesignationClasses FROM classe WHERE IDClasses=?", [$id_classe]);
    if (!$classe) die('Classe introuvable.');
    $classement = classement_annuel_classe_arabe($id_classe, $val_annee);
    $lignes_cl = $classement['lignes'];
    if (($_GET['ordre'] ?? '') === 'alpha') {
        usort($lignes_cl, fn($a, $b) => strcmp($a['Nom_elv'] . ' ' . $a['Prenom_elv'], $b['Nom_elv'] . ' ' . $b['Prenom_elv']));
    }
    $liste = [];
    foreach ($lignes_cl as $l) {
        $liste[] = ['id_eleve' => (int) $l['id_eleve'], 'id_classe' => $id_classe, 'classe_nom' => $classe['DesignationClasses']];
    }
    if (empty($liste)) die('Aucun élève classé pour cette classe.');
    $effectif_classe = $classement['effectif'];
}

function pdf_case_ann_ar(TCPDF $pdf, bool $coche, float $x, float $y, float $taille = 3.0): void {
    $fichier = $coche ? 'case_cochee.jpg' : 'case_a_cocher.jpg';
    $pdf->Image(__DIR__ . '/../assets/img/pdf/' . $fichier, $x, $y, $taille, $taille);
}

function dessiner_bulletin_annuel_arabe(
    TCPDF $pdf, int $id, int $id_classe, string $classe_nom, string $val_annee, int $id_annee,
    array $etab, array $etab_ar, int $effectif_classe, bool $avec_sig
): void {
    $eleve = db_one("SELECT * FROM eleve WHERE id_eleve=?", [$id]);
    if (!$eleve) return;
    $statut = db_val("SELECT Statut_elv FROM inscrire WHERE id_eleve=? AND IDClasses=? AND val_annee=?", [$id, $id_classe, $val_annee]) ?: 'Non';

    $matieres = matieres_classe_arabe($id_classe);
    $resultat = rang_eleve_annuel_arabe($id, $id_classe, $val_annee);

    // 🐛 trimestre.id_annee est un VARCHAR (val_annee), pas un entier.
    $trims_ids = trimestres_de_annee($val_annee);
    $r_trim = [];
    foreach ([0, 1, 2] as $i) {
        $it = $trims_ids[$i] ?? 0;
        $r_trim[$i] = $it ? rang_eleve_trimestre_arabe($id, $id_classe, $it, $val_annee) : ['moyenne' => null, 'rang' => '', 'effectif' => 0, 'moy_classe' => null, 'moy_premier' => null, 'moy_dernier' => null, 'taux_reussite' => null];
    }

    $groupes = [];
    foreach ($matieres as $m) {
        $gid = (int) $m['id_groupe'];
        $groupes[$gid]['fr'] ??= $m['nom_groupe_fr'];
        $groupes[$gid]['ar'] ??= $m['nom_groupe_ar'];
        $groupes[$gid]['matieres'][] = $m;
    }

    $pdf->AddPage();
    $pw = $pdf->getPageWidth();
    $ph = $pdf->getPageHeight();
    $P = 20;

    $style1 = ['width' => 0.8, 'cap' => 'round', 'join' => 'round', 'dash' => 0, 'color' => [0, 0, 0]];
    $style2 = ['width' => 0.3, 'cap' => 'round', 'join' => 'round', 'dash' => 0, 'color' => [0, 0, 0]];
    $pdf->RoundedRect(7, 7, 196, 283, 5, '1111', '', $style1);
    $pdf->RoundedRect(8, 8, 194, 281, 4, '1111', '', $style2);
    tcpdf_filigrane($pdf, $etab, $pw, $ph);

    $pdf->SetFont('amirib', '', 19);
    $pdf->SetRTL(true, false);
    $pdf->SetXY(200 - 8, 8, true);
    $pdf->Cell(115, 8, 'بسم الله الرحمن الرحيم', 0, 0, 'C');
    $pdf->SetRTL(false, false);
    if (!empty($etab['logo'])) {
        $logo_path = __DIR__ . '/../assets/uploads/' . $etab['logo'];
        if (is_file($logo_path)) $pdf->Image($logo_path, 92, 19, 26, 26);
    }

    // En-tête FR/AR — colonnes réelles de `etablissement`.
    tcpdf_colonne_lignes($pdf, [
        [$etab_ar['pays_etab_fr'] ?? '', 'helvetica', '', 7, 3.4],
        [$etab_ar['region_etab_fr'] ?? '', 'helvetica', '', 6.5, 3.4],
        [$etab_ar['departement_fr'] ?? '', 'helvetica', '', 6.5, 3.4],
        [$etab_ar['arrondissement_fr'] ?? '', 'helvetica', '', 6.5, 3.4],
        [$etab_ar['Nom_Etab_Fr'] ?? $etab['nom_fr'], 'helvetica', 'B', 9, 3.6],
        ['B.P. ' . ($etab_ar['boite_postal'] ?? '') . ' ' . ($etab_ar['ville_etab'] ?? '') . ' - Tél.: ' . ($etab_ar['tel_etab'] ?? ''), 'helvetica', '', 6, 3.6],
    ], 10, 14, 82, false);

    tcpdf_colonne_lignes($pdf, [
        [$etab_ar['pays_etab_ar'] ?? '', 'amirib', '', 8, 3.6],
        [$etab_ar['region_ar'] ?? '', 'amirib', '', 7.5, 3.6],
        [$etab_ar['departement_ar'] ?? '', 'amirib', '', 7.5, 3.6],
        [$etab_ar['arrondissement_ar'] ?? '', 'amirib', '', 7.5, 3.6],
        [$etab_ar['ecole_ar'] ?? '', 'amirib', 'B', 12, 3.8],
    ], 121, 14, 82, true);

    $pdf->SetFont('amirib', 'B', 11);
    $pdf->Text(150, $P + 40, 'Année scolaire : ' . $val_annee);
    $pdf->SetRTL(true, false);
    $pdf->SetXY(150 + 45, $P + 43, true);
    $pdf->Cell(45, 4, 'العام الدراسي', 0, 0, 'R');
    $pdf->SetRTL(false, false);

    // ── Bandeau titre (forme ruban) ──
    [$rt, $gt, $bt] = couleur_pdf('groupe_competence');
    $pdf->SetFillColor($rt, $gt, $bt);
    $pdf->SetDrawColor($rt, $gt, $bt);
    $pdf->Polygon([62, $P + 33.5, 54, $P + 36.5, 60, $P + 39, 54, $P + 41.5, 62, $P + 44.5], 'DF');
    $pdf->Polygon([148, $P + 33.5, 156, $P + 36.5, 150, $P + 39, 156, $P + 41.5, 148, $P + 44.5], 'DF');
    $pdf->SetDrawColor(0);
    pdf_fill($pdf, 'groupe_competence');
    $pdf->RoundedRect(62, $P + 33.5, 86, 11, 11, '1111', 'DF');
    $pdf->SetFont('amirib', 'B', 16);
    $pdf->SetXY(69, $P + 32.7);
    $pdf->Cell(50, 8, 'BULLETIN ANNUEL', 0, 0, 'L');
    $pdf->SetFont('amirib', 'B', 15);
    $pdf->SetRTL(true, false);
    $pdf->SetTextColor(187, 18, 24);
    $pdf->SetXY(133, $P + 37.8, true);
    $pdf->Cell(35, 6, 'كشف السنوية', 0, 0, 'R');
    $pdf->SetTextColor(0);
    $pdf->SetRTL(false, false);

    $pdf->SetFont('amirib', 'B', 15);
    $pdf->SetXY(80, $P + 44);
    $pdf->Cell(45, 6, 'Classe : ' . $classe_nom, 0, 0, 'L');
    $pdf->SetFont('amirib', 'B', 10);
    $pdf->SetRTL(true, false);
    $pdf->SetXY(80 + 30, $P + 47.5, true);
    $pdf->Cell(30, 4, 'الصف', 0, 0, 'R');
    $pdf->SetRTL(false, false);

    // ── Photo (gauche) ───────────────────────────────────────────
    $pdf->Rect(12.5, $P + 34.5, 31.5, 35, 'D');
    $photo_tmp = photo_eleve_fichier_temp($eleve['Photo_elv'] ?? null, $id);
    if ($photo_tmp) {
        $pdf->Image($photo_tmp, 13.2, $P + 35.3, 30, 33.4);
        @unlink($photo_tmp);
    } else {
        $avatar = stripos($eleve['Sexe_elv'] ?? '', 'F') === 0 ? 'fille.png' : 'garcon.png';
        $avatar_path = __DIR__ . '/../assets/img/avatars/' . $avatar;
        if (is_file($avatar_path)) $pdf->Image($avatar_path, 13.2, $P + 35.3, 30, 33.4);
    }

    $date_naiss = $eleve['Date_naiss_elv'] ? date('d/m/Y', strtotime($eleve['Date_naiss_elv'])) : '';
    $pdf->SetFont('amirib', 'B', 10.5);
    $pdf->Text(50, $P + 53, 'Matricule : ' . $eleve['Mat_elv']);
    $pdf->Text(115, $P + 53, 'Effectif : ' . $effectif_classe);
    $pdf->Text(160, $P + 53, 'Redoublant(e) : ' . $statut);
    $pdf->Text(50, $P + 59, 'Noms et Prénoms : ' . mb_strtoupper($eleve['Nom_elv']) . ' ' . ($eleve['Prenom_elv'] ?? ''));
    $pdf->Text(50, $P + 65, 'Né(e) le : ' . $date_naiss . '  à  ' . ($eleve['Lieu_naiss_elv'] ?? ''));
    $pdf->Text(160, $P + 65, 'Sexe : ' . ($eleve['Sexe_elv'] ?? ''));

    $enseignant = db_one(
        "SELECT e.civilite_ens, e.nom_ens, e.prenom_ens FROM enseignant e, enseignat_classe_arabe d
         WHERE e.matricule_ens=d.matricule_ens AND d.IDClasses=? AND d.val_annee=?",
        [$id_classe, $val_annee]
    );
    $pdf->Text(50, $P + 71, 'Enseignant(e) Titulaire : ' . trim(($enseignant['civilite_ens'] ?? '') . ' ' . ($enseignant['nom_ens'] ?? '') . ' ' . ($enseignant['prenom_ens'] ?? '')));

    $pdf->SetFont('amirib', '', 8);
    $pdf->SetRTL(true, false);
    $legende = function (string $t, float $x, float $w) use ($pdf): void { $pdf->Cell($w, 3.4, $t, 0, 0, 'R'); };
    $pdf->SetXY(50 + 55, $P + 56, true); $legende('رقم التسجيل', 50, 55);
    $pdf->SetXY(115 + 40, $P + 56, true); $legende('عدد الطلاب', 115, 40);
    $pdf->SetXY(160 + 35, $P + 56, true); $legende('راسب (ة)', 160, 35);
    $pdf->SetXY(50 + 100, $P + 62, true); $legende('اسم الطالب (ة)', 50, 100);
    $pdf->SetXY(50 + 100, $P + 68, true); $legende('تاريخ ومكانة الميلاد', 50, 100);
    $pdf->SetXY(160 + 35, $P + 68, true); $legende('جنس', 160, 35);
    $pdf->SetXY(50 + 100, $P + 74, true); $legende('المعلم الدائم', 50, 100);
    $pdf->SetRTL(false, false);

    // ── Tableau des matières (1er/2è/3è Trim + Moyenne + Rang + Appréciations) ──
    $y0 = $P + 79;
    $w_grp = 6; $w_mat = 62; $w_trim = 22; $w_moy = 18; $w_rang = 16; $w_appr = 199 - 2 * 5 - $w_grp - $w_mat - 3 * $w_trim - $w_moy - $w_rang;
    $x0 = 9;

    pdf_fill($pdf, 'groupe_competence');
    $pdf->Rect($x0, $y0, $w_grp + $w_mat, 8, 'DF');
    $pdf->SetFont('helvetica', 'B', 7.5);
    $pdf->SetXY($x0, $y0 + 0.5);
    $pdf->Cell($w_grp + $w_mat, 3.5, 'Matieres', 0, 0, 'C');
    $pdf->SetRTL(true, false);
    $pdf->SetFont('amirib', '', 7);
    $pdf->SetXY($x0 + $w_grp + $w_mat, $y0 + 4, true);
    $pdf->Cell($w_grp + $w_mat, 3.5, 'المواد', 0, 0, 'C');
    $pdf->SetRTL(false, false);

    $x = $x0 + $w_grp + $w_mat;
    foreach ([['1er Trim', 'الفصل الأول', $w_trim], ['2è Trim', 'الفصل الثاني', $w_trim], ['3è Trim', 'الفصل الثالث', $w_trim], ['Moyenne', 'المعدل', $w_moy], ['Rang', 'الترتيب', $w_rang], ['Appreciations', 'التقديرات', $w_appr]] as [$fr, $ar, $w]) {
        $pdf->Rect($x, $y0, $w, 8, 'DF');
        $pdf->SetFont('helvetica', 'B', 6.5);
        $pdf->SetXY($x, $y0 + 0.7);
        $pdf->Cell($w, 3.2, $fr, 0, 0, 'C');
        $pdf->SetRTL(true, false);
        $pdf->SetFont('amirib', '', 6.5);
        $pdf->SetXY($x + $w, $y0 + 4.2, true);
        $pdf->Cell($w, 3.2, $ar, 0, 0, 'C');
        $pdf->SetRTL(false, false);
        $x += $w;
    }

    $y = $y0 + 8;
    foreach ($groupes as $g) {
        $y_grp_debut = $y;
        foreach ($g['matieres'] as $m) {
            $id_mat = (int) $m['id_mat'];
            $note = note_matiere_annuelle_arabe($id, $id_mat, $id_classe, $val_annee);
            $appr = appreciation_moyenne_arabe($note['moyenne']);

            $pdf->SetXY($x0 + $w_grp, $y);
            $pdf->SetFont('amirib', 'B', 7);
            $pdf->Cell($w_mat, 6, ' ' . $m['matiere_fr'] . ' / ' . $m['matiere_ar'], 1, 0, 'L');

            $xx = $x0 + $w_grp + $w_mat;
            $pdf->SetFont('helvetica', '', 7.5);
            foreach ([1, 2, 3] as $t) {
                $v = $note['par_trim'][$t] ?? null;
                $pdf->SetXY($xx, $y); $pdf->Cell($w_trim, 6, $v !== null ? (string) $v : 'Abs', 1, 0, 'C'); $xx += $w_trim;
            }
            $pdf->SetFont('helvetica', 'B', 7.5);
            $pdf->SetXY($xx, $y); $pdf->Cell($w_moy, 6, $note['moyenne'] !== null ? (string) $note['moyenne'] : '', 1, 0, 'C'); $xx += $w_moy;
            $pdf->SetFont('helvetica', '', 7.5);
            $pdf->SetXY($xx, $y); $pdf->Cell($w_rang, 6, '', 1, 0, 'C'); $xx += $w_rang;
            $pdf->SetFont('amirib', 'B', 6.5);
            $pdf->SetRTL(true, false);
            $pdf->SetXY($xx + $w_appr, $y, true); $pdf->Cell($w_appr, 6, $appr, 1, 0, 'C');
            $pdf->SetRTL(false, false);

            $y += 6;
        }
        $h_grp = $y - $y_grp_debut;
        $pdf->Rect($x0, $y_grp_debut, $w_grp, $h_grp, 'D');
        // Libellé de groupe en arabe, pivoté à 90° en une seule ligne.
        $cx = $x0 + $w_grp / 2; $cy = $y_grp_debut + $h_grp / 2;
        $pdf->StartTransform();
        $pdf->Rotate(90, $cx, $cy);
        $pdf->SetFont('amirib', 'B', 6.5);
        $pdf->SetRTL(true, false);
        $pdf->SetXY($cx + $h_grp / 2, $cy - 1.5, true);
        $pdf->Cell($h_grp, 3, $g['ar'], 0, 0, 'C');
        $pdf->SetRTL(false, false);
        $pdf->StopTransform();
    }

    $pdf->SetXY($x0, $y);
    $pdf->SetFont('amirib', 'B', 7);
    $pdf->Cell($w_grp + $w_mat, 6, ' Total points / المجموع', 1, 0, 'L');
    $tot = [1 => 0.0, 2 => 0.0, 3 => 0.0]; $totAnn = 0.0;
    foreach ($matieres as $m) {
        $n = note_matiere_annuelle_arabe($id, (int) $m['id_mat'], $id_classe, $val_annee);
        foreach ([1, 2, 3] as $t) { if (isset($n['par_trim'][$t])) $tot[$t] += $n['par_trim'][$t]; }
        if ($n['moyenne'] !== null) $totAnn += $n['moyenne'];
    }
    $xx = $x0 + $w_grp + $w_mat;
    foreach ([1, 2, 3] as $t) { $pdf->SetXY($xx, $y); $pdf->Cell($w_trim, 6, number_format($tot[$t], 2), 1, 0, 'C'); $xx += $w_trim; }
    $pdf->SetXY($xx, $y); $pdf->Cell($w_moy, 6, number_format($totAnn, 2), 1, 0, 'C'); $xx += $w_moy;
    $pdf->Cell($w_rang, 6, '', 1, 0, 'C');
    $pdf->Cell($w_appr, 6, '', 1, 0, 'C');
    $y += 6;

    // ── RESULTATS DE L'ELEVE : bandeau bilingue + 2 tableaux côte à côte
    // (TRAVAIL à gauche avec cases à cocher, grille 1er/2è/3è Trim/ANNUELLE à droite) ──
    $y += 4;
    $w_all = $w_grp + $w_mat + 3 * $w_trim + $w_moy + $w_rang + $w_appr; // = 189
    pdf_fill($pdf, 'entete_bleu');
    $pdf->Rect($x0, $y, $w_all, 6, 'DF');
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->Text($x0 + 4, $y + 4, "RESULTATS DE L'ELEVE");
    $pdf->SetFont('amirib', 'B', 8);
    $pdf->SetRTL(true, false);
    $pdf->SetXY($x0 + $w_all - 4, $y + 4.2, true);
    $pdf->Cell(40, 3.6, 'نتيجة الطالب', 0, 0, 'R');
    $pdf->SetRTL(false, false);
    $y += 8;

    // -- Tableau de gauche : TRAVAIL (cases à cocher) --
    $w_trav_lbl = 30; $w_trav_chk = 8; $w_trav_ar = 30;
    $w_trav = $w_trav_lbl + $w_trav_chk + $w_trav_ar;
    $y_trav0 = $y;
    $jours_ann = jours_absence_non_justifiees_annuel($id, $id_classe, $val_annee);
    $m_ann = mention_travail($resultat['moyenne'], $jours_ann);
    $lignes_trav = [
        ["Tableau d'Hon.", $m_ann['tableau_honneur'], 'لوحة الشرف'],
        ['Encouragements', $m_ann['encouragement'], 'التشجيع'],
        ['Félicitations', $m_ann['felicitations'], 'تهنئة'],
        ['Avert. Travail', $m_ann['avertissement'], 'تحذير العمل'],
        ['Blâme conduite', $m_ann['blame_conduite'], 'اللوم على السلوك'],
    ];
    $pdf->SetFont('helvetica', 'B', 6.5);
    $pdf->Rect($x0, $y, $w_trav_lbl, 3.6, 'D');
    $pdf->SetXY($x0, $y + 0.3);
    $pdf->Cell($w_trav_lbl, 3, 'TRAVAIL', 0, 0, 'L');
    $pdf->Rect($x0 + $w_trav_lbl, $y, $w_trav_chk, 3.6, 'D');
    $pdf->Rect($x0 + $w_trav_lbl + $w_trav_chk, $y, $w_trav_ar, 3.6, 'D');
    $pdf->SetFont('amirib', 'B', 6.5);
    $pdf->SetRTL(true, false);
    $pdf->SetXY($x0 + $w_trav_lbl + $w_trav_chk + $w_trav_ar, $y + 0.5, true);
    $pdf->Cell($w_trav_ar, 3, 'المجموعة', 0, 0, 'R');
    $pdf->SetRTL(false, false);
    $y += 3.6;
    foreach ($lignes_trav as $lt) {
        $pdf->SetFont('helvetica', '', 7);
        $pdf->SetXY($x0, $y);
        $pdf->Cell($w_trav_lbl, 5.5, ' ' . $lt[0], 1, 0, 'L');
        $pdf->Cell($w_trav_chk, 5.5, '', 1, 0, 'C');
        pdf_case_ann_ar($pdf, $lt[1], $x0 + $w_trav_lbl + 2.5, $y + 1.2);
        $pdf->SetFont('amirib', '', 7);
        $pdf->SetRTL(true, false);
        $pdf->SetXY($x0 + $w_trav_lbl + $w_trav_chk + $w_trav_ar, $y, true);
        $pdf->Cell($w_trav_ar, 5.5, ' ' . $lt[2], 1, 0, 'R');
        $pdf->SetRTL(false, false);
        $y += 5.5;
    }
    $y_trav_fin = $y;

    // -- Tableau de droite : 1er Trim / 2è Trim / 3è Trim / ANNUELLE --
    // Libellé FR à gauche, traduction arabe à droite de la ligne.
    $x_grille = $x0 + $w_trav + 3;
    $w_lbl_ar = 18;
    $w_lbl = 20; $w_col = ($w_all - $w_trav - 3 - $w_lbl - $w_lbl_ar) / 4;
    $y = $y_trav0;
    $fmt = fn(?float $v): string => $v === null ? '' : number_format($v, 2);
    $lignes = [
        ['Moyenne', $fmt($r_trim[0]['moyenne']), $fmt($r_trim[1]['moyenne']), $fmt($r_trim[2]['moyenne']), $fmt($resultat['moyenne']), 'المعدل'],
        ['Rang', ($r_trim[0]['rang'] ?: '') . ($r_trim[0]['rang'] ? '/' . $r_trim[0]['effectif'] : ''), ($r_trim[1]['rang'] ?: '') . ($r_trim[1]['rang'] ? '/' . $r_trim[1]['effectif'] : ''), ($r_trim[2]['rang'] ?: '') . ($r_trim[2]['rang'] ? '/' . $r_trim[2]['effectif'] : ''), ($resultat['rang'] ?: '') . ($resultat['rang'] ? '/' . $resultat['effectif'] : ''), 'الترتيب'],
        ['Moy gén. Classe', $fmt($r_trim[0]['moy_classe']), $fmt($r_trim[1]['moy_classe']), $fmt($r_trim[2]['moy_classe']), $fmt($resultat['moy_classe']), 'المعدل الفصلي'],
        ['Moy. du premier', $fmt($r_trim[0]['moy_premier']), $fmt($r_trim[1]['moy_premier']), $fmt($r_trim[2]['moy_premier']), $fmt($resultat['moy_premier']), 'المعدل الأول'],
        ['Moy. dernier', $fmt($r_trim[0]['moy_dernier']), $fmt($r_trim[1]['moy_dernier']), $fmt($r_trim[2]['moy_dernier']), $fmt($resultat['moy_dernier']), 'المعدل الأخير'],
    ];
    $xx = $x_grille + $w_lbl;
    foreach ([['1er Trim', 'الفصل الأول'], ['2è Trim', 'الفصل الثاني'], ['3è Trim', 'الفصل الثالث'], ['ANNUELLE', 'السنوية']] as [$hfr, $har]) {
        $pdf->Rect($xx, $y, $w_col, 6, 'D');
        $pdf->SetFont('helvetica', 'B', 6.2);
        $pdf->SetXY($xx, $y + 0.3);
        $pdf->Cell($w_col, 3, $hfr, 0, 0, 'C');
        $pdf->SetFont('amirib', 'B', 6);
        $pdf->SetRTL(true, false);
        $pdf->SetXY($xx + $w_col, $y + 3.3, true);
        $pdf->Cell($w_col, 3, $har, 0, 0, 'C');
        $pdf->SetRTL(false, false);
        $xx += $w_col;
    }
    $pdf->Rect($x_grille, $y, $w_lbl, 6, 'D');
    $pdf->Rect($xx, $y, $w_lbl_ar, 6, 'D');
    $y += 6;
    foreach ($lignes as $ligne) {
        $pdf->SetFont('helvetica', 'B', 7);
        $pdf->SetXY($x_grille, $y);
        $pdf->Cell($w_lbl, 5.5, ' ' . $ligne[0], 1, 0, 'L');
        $pdf->SetFont('helvetica', '', 7.5);
        for ($c = 1; $c <= 4; $c++) {
            // Toute la colonne ANNUELLE mise en évidence (fond pêche).
            if ($c === 4) {
                pdf_fill($pdf, 'colonne_annuelle');
                if ($ligne[0] === 'Moyenne') $pdf->SetFont('helvetica', 'B', 9);
                $pdf->Cell($w_col, 5.5, $ligne[$c], 1, 0, 'C', 1);
                $pdf->SetFont('helvetica', '', 7.5);
            } else {
                $pdf->Cell($w_col, 5.5, $ligne[$c], 1, 0, 'C');
            }
        }
        $pdf->SetFont('amirib', 'B', 6.5);
        $pdf->SetRTL(true, false);
        $pdf->SetXY($x_grille + $w_lbl + 4 * $w_col + $w_lbl_ar, $y, true);
        $pdf->Cell($w_lbl_ar, 5.5, $ligne[5], 1, 0, 'C');
        $pdf->SetRTL(false, false);
        $y += 5.5;
    }
    $y = max($y, $y_trav_fin) + 6;

    $w_sig = (199 - 10) / 3;
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetXY($x0, $y);
    $pdf->Cell($w_sig, 5, 'ENSEIGNANT(E)', 0, 0, 'C');
    $pdf->Cell($w_sig, 5, 'LA DIRECTRICE', 0, 0, 'C');
    $pdf->Cell($w_sig, 5, 'PARENT', 0, 0, 'C');
    $y += 5;
    $pdf->SetFont('amirib', '', 8);
    $pdf->SetRTL(true, false);
    foreach ([['المعلم', 0], ['المديرة', 1], ['ولي الأمر', 2]] as [$lib, $i]) {
        $pdf->SetXY($x0 + ($i + 1) * $w_sig, $y, true);
        $pdf->Cell($w_sig, 4, $lib, 0, 0, 'C');
    }
    $pdf->SetRTL(false, false);
    $y += 6;
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetXY($x0, $y);
    $pdf->Cell(199 - 10, 5, 'Fait a Ngaoundere le, ' . date('d/m/Y') . '   /', 0, 0, 'C');
    $pdf->SetFont('amirib', '', 8);
    $pdf->SetRTL(true, false);
    $pdf->SetXY($x0 + (199 - 10) / 2 + 26, $y + 4.5, true);
    $pdf->Cell(20, 4, 'صنع في', 0, 0, 'R');
    $pdf->SetRTL(false, false);

    if ($avec_sig) {
        pdf_signature_appliquer_jn_tcpdf_ann($pdf, 'bulletin_annuel_arabe', $x0 + 60, $y - 15, 60, 15, [
            'x_pct' => 25, 'y_pct' => 20, 'w_pct' => 50, 'h_pct' => null,
        ]);
    }

    // Fichier mis en cache disque (voir pdf/verif_lib.php) — pas de unlink.
    $qr_tmp = bulletin_qr_fichier_temp($eleve, 'annee', $id_annee, 'ar');
    if ($qr_tmp) {
        $pdf->Image($qr_tmp, 96, $y + 10, 16, 16, 'PNG');
    }

    // Copyright standard (pdf/header_pdf_tcpdf.php).
    tcpdf_copyright($pdf, 210, 297);
}

function pdf_signature_appliquer_jn_tcpdf_ann(TCPDF $pdf, string $type_document,
                                               float $frameX, float $frameY, float $frameW, float $frameH,
                                               array $defaut): void {
    $chemin = signature_etablissement_chemin();
    if (!$chemin) return;
    $pos = signature_position_lookup($type_document, $defaut);
    $x = $frameX + $frameW * (float) $pos['x_pct'] / 100;
    $y = $frameY + $frameH * (float) $pos['y_pct'] / 100;
    $w = $frameW * (float) $pos['w_pct'] / 100;
    $h = $pos['h_pct'] !== null ? $frameH * (float) $pos['h_pct'] / 100 : 0;
    $pdf->Image($chemin, $x, $y, $w, $h);
}

// Préchargement en masse : évite les requêtes individuelles par (élève × matière × trimestre).
precharger_notes_sequence_classe_arabe($id_classe);

// Enveloppé dans un try/catch : accessible publiquement via le QR du
// bulletin — voir fonctions.php::pdf_erreur_generation().
try {
$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
// Police arabe (amirib) embarquée en entier, pas en sous-ensemble — voir le
// commentaire identique dans bulletin_trimestriel_arabe.php (glyphes arabes
// vides dans WPS Office et lecteurs PDF non-Adobe avec le sous-ensemble par
// défaut de TCPDF).
$pdf->setFontSubsetting(false);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(5, 5, 5);
$pdf->SetAutoPageBreak(false, 0);

foreach ($liste as $e) {
    dessiner_bulletin_annuel_arabe(
        $pdf, $e['id_eleve'], $e['id_classe'], $e['classe_nom'], $val_annee, $id_annee,
        $etab, $etab_ar, $effectif_classe, $avec_sig
    );
}

$nom_fichier = $id ? ('bulletin_annuel_arabe_' . $liste[0]['id_eleve'] . '.pdf') : ('bulletins_annuels_arabe_' . $liste[0]['classe_nom'] . '.pdf');
$pdf->Output($nom_fichier, $dl ? 'D' : 'I');
} catch (Throwable $e) {
    pdf_erreur_generation($e);
}
