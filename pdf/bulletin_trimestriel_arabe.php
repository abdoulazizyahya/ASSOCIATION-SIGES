<?php
// ── PDF : Bulletin trimestriel — piste arabe (TCPDF, RTL réel) ──────
// Port fidèle de jaynitaare/php/Bulletin_1.php (mêmes coordonnées) —
// modèle de référence : ara_trim.pdf. Cadre arrondi pleine page, en-tête
// bilingue complet (République/Devise/Ministère/Délégations/École FR+AR),
// bandeau titre en pilule, grille Matricule/Effectif/Redoublant/Nom/Né(e)
// le/Sexe/Titulaire, tableau des matières (Mois1/Mois2/Moyenne/Coef/
// Moy×Coef/Appréciations) groupé par groupe_matiere_arabe, bloc
// DISCIPLINES/TRAVAIL/RESULTAT DE L'ELEVE, signatures, QR.
//
// Utilise les fonctions RTL déjà éprouvées de pdf/header_pdf_tcpdf.php
// (tcpdf_colonne_lignes/tcpdf_cellule_fr_ar — 3 pièges TCPDF RTL réels déjà
// contournés, voir commentaires en tête de ce fichier) plutôt que
// writeHTML() (jamais exercé en RTL dans ce projet, plus risqué à porter
// correctement que d'étendre les helpers Cell() déjà vérifiés).
//
// GET : id + trim (bulletin d'UN élève) — OU classe + trim (sans id) :
// imprime tous les élèves classés de la classe/du trimestre.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
require_once __DIR__ . '/../notes_apc.php';
require_once __DIR__ . '/../notes_apc_arabe.php';
require_once __DIR__ . '/verif_lib.php';

// Accès public via le QR code du bulletin (jeton "vh") — voir
// pdf/bulletin_annuel.php pour l'explication (même mécanisme, piste='ar' ici).
$acces_public = false;
if (($_GET['vh'] ?? '') !== '' && (int) ($_GET['id'] ?? 0) > 0 && (int) ($_GET['trim'] ?? 0) > 0) {
    $acces_public = bulletin_verif_valider(
        (int) $_GET['id'], 'trim', (int) $_GET['trim'], 'ar', (string) $_GET['vh']
    ) !== null;
}
if (!$acces_public) exiger_connexion();

require_once __DIR__ . '/header_pdf.php'; // pdf_filigrane_chemin() (GD, indépendant de FPDF/TCPDF)
require_once __DIR__ . '/../pdf/tcpdf/config/tcpdf_config.php';
require_once __DIR__ . '/../pdf/tcpdf/tcpdf.php';
require_once __DIR__ . '/header_pdf_tcpdf.php';

$id        = (int) ($_GET['id'] ?? 0);
$id_classe = (int) ($_GET['classe'] ?? 0);
$id_trim   = (int) ($_GET['trim'] ?? 0);
$dl        = ($_GET['dl'] ?? '0') === '1';
$avec_sig  = ($_GET['signature'] ?? '0') === '1';
if (!$id_trim || (!$id && !$id_classe)) die('Paramètres id (ou classe) / trim manquants.');

$annee     = get_annee_active();
$val_annee = $annee['val_annee'] ?? '';
$trimestre = db_one("SELECT * FROM trimestre WHERE id_trim=?", [$id_trim]);
if (!$trimestre) die('Trimestre introuvable.');

$etab_brut = get_etablissement();
$etab      = etab_pour_pdf($etab_brut);
// En-tête bilingue FR/AR absorbé dans `etablissement` (migration v31,
// ex-table etablissement_arabe) — déjà présent dans $etab_brut.
$etab_ar   = $etab_brut;

[$trim_ar_lib] = match ($id_trim) {
    2 => ['الفصل الثاني'],
    3 => ['الفصل الثالث'],
    default => ['الفصل الأول'],
};

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
    $classement = classement_trimestre_classe_arabe($id_classe, $id_trim, $val_annee);
    // Ordre d'impression du lot : voir pdf/bulletin_trimestriel.php (même
    // correctif du 13/08, même bug — ordre jamais transmis au PDF en lot).
    $lignes_cl = $classement['lignes'];
    if (($_GET['ordre'] ?? '') === 'alpha') {
        usort($lignes_cl, fn($a, $b) => strcmp($a['Nom_elv'] . ' ' . $a['Prenom_elv'], $b['Nom_elv'] . ' ' . $b['Prenom_elv']));
    }
    $liste = [];
    foreach ($lignes_cl as $l) {
        $liste[] = ['id_eleve' => (int) $l['id_eleve'], 'id_classe' => $id_classe, 'classe_nom' => $classe['DesignationClasses']];
    }
    if (empty($liste)) die('Aucun élève classé pour cette classe/ce trimestre.');
    $effectif_classe = $classement['effectif'];
}

function pdf_case_ar(TCPDF $pdf, bool $coche, float $x, float $y, float $taille = 3.0): void {
    $fichier = $coche ? 'case_cochee.jpg' : 'case_a_cocher.jpg';
    $pdf->Image(__DIR__ . '/../assets/img/pdf/' . $fichier, $x, $y, $taille, $taille);
}

function dessiner_bulletin_trimestriel_arabe(
    TCPDF $pdf, int $id, int $id_classe, string $classe_nom, int $id_trim, string $val_annee,
    array $etab, array $etab_ar, array $trimestre, string $trim_ar_lib, int $effectif_classe, bool $avec_sig
): void {
    $eleve = db_one("SELECT * FROM eleve WHERE id_eleve=?", [$id]);
    if (!$eleve) return;
    $statut = db_val("SELECT Statut_elv FROM inscrire WHERE id_eleve=? AND IDClasses=? AND val_annee=?", [$id, $id_classe, $val_annee]) ?: 'Non';

    $matieres = matieres_classe_arabe($id_classe);
    $resultat = rang_eleve_trimestre_arabe($id, $id_classe, $id_trim, $val_annee);

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
    $P = 20; // décalage vertical de base (voir légende en tête de fichier)

    $style1 = ['width' => 0.8, 'cap' => 'round', 'join' => 'round', 'dash' => 0, 'color' => [0, 0, 0]];
    $style2 = ['width' => 0.3, 'cap' => 'round', 'join' => 'round', 'dash' => 0, 'color' => [0, 0, 0]];
    $pdf->RoundedRect(7, 7, 196, 283, 5, '1111', '', $style1);
    $pdf->RoundedRect(8, 8, 194, 281, 4, '1111', '', $style2);
    tcpdf_filigrane($pdf, $etab, $pw, $ph);

    // ── Bismillah + logo ───────────────────────────────────────────
    $pdf->SetFont('amirib', '', 19);
    $pdf->SetRTL(true, false);
    $pdf->SetXY(200 - 8, 8, true);
    $pdf->Cell(115, 8, 'بسم الله الرحمن الرحيم', 0, 0, 'C');
    $pdf->SetRTL(false, false);
    if (!empty($etab['logo'])) {
        $logo_path = __DIR__ . '/../assets/uploads/' . $etab['logo'];
        if (is_file($logo_path)) $pdf->Image($logo_path, 92, 19, 26, 26);
    }

    // ── En-tête bilingue complet (République/Devise/Ministère/Délégations/École) ──
    tcpdf_colonne_lignes($pdf, [
        [$etab_ar['republique_fr'] ?? 'REPUBLIQUE DU CAMEROUN', 'helvetica', '', 7, 3.4],
        [$etab_ar['devise_fr'] ?? 'Paix - Travail - Patrie', 'helvetica', '', 6.5, 3.4],
        ['**********', 'helvetica', '', 6.5, 3.4],
        [$etab_ar['ministere_fr'] ?? '', 'helvetica', '', 6.5, 3.4],
        [$etab_ar['delegation_reg_fr'] ?? '', 'helvetica', '', 6.5, 3.4],
        [$etab_ar['delegation_dep_fr'] ?? '', 'helvetica', '', 6.5, 3.4],
        [$etab_ar['arrondissement_fr'] ?? '', 'helvetica', '', 6.5, 3.4],
        ['**********', 'helvetica', '', 6.5, 3.4],
        [$etab_ar['ecole_fr'] ?? $etab['nom_fr'], 'helvetica', 'B', 7, 3.6],
    ], 10, 14, 82, false);

    tcpdf_colonne_lignes($pdf, [
        [$etab_ar['republique_ar'] ?? '', 'amirib', '', 8, 3.6],
        [$etab_ar['devise_ar'] ?? '', 'amirib', '', 7.5, 3.6],
        ['**********', 'helvetica', '', 6.5, 3.4],
        [$etab_ar['ministere_ar'] ?? '', 'amirib', '', 7.5, 3.6],
        [$etab_ar['delegation_reg_ar'] ?? '', 'amirib', '', 7.5, 3.6],
        [$etab_ar['delegation_dep_ar'] ?? '', 'amirib', '', 7.5, 3.6],
        [$etab_ar['arrondissement_ar'] ?? '', 'amirib', '', 7.5, 3.6],
        ['**********', 'helvetica', '', 6.5, 3.4],
        [$etab_ar['ecole_ar'] ?? '', 'amirib', 'B', 8.5, 3.8],
    ], 121, 14, 82, true);

    $pdf->SetFont('amirib', 'B', 11);
    $pdf->Text(150, $P + 40, 'Année scolaire : ' . $val_annee);
    $pdf->SetRTL(true, false);
    $pdf->SetXY(150 + 45, $P + 43, true);
    $pdf->Cell(45, 4, 'العام الدراسي', 0, 0, 'R');
    $pdf->SetRTL(false, false);

    // ── Bandeau titre (forme ruban, voir bulletin_annuel_arabe.php) ────
    [$rt, $gt, $bt] = couleur_pdf('groupe_competence');
    $pdf->SetFillColor($rt, $gt, $bt);
    $pdf->SetDrawColor($rt, $gt, $bt);
    $pdf->Polygon([62, $P + 33.5, 54, $P + 36.5, 60, $P + 39, 54, $P + 41.5, 62, $P + 44.5], 'DF');
    $pdf->Polygon([148, $P + 33.5, 156, $P + 36.5, 150, $P + 39, 156, $P + 41.5, 148, $P + 44.5], 'DF');
    $pdf->SetDrawColor(0);
    pdf_fill($pdf, 'groupe_competence');
    $pdf->RoundedRect(62, $P + 33.5, 86, 11, 11, '1111', 'DF');
    $pdf->SetFont('amirib', 'B', 18);
    $pdf->SetXY(70.5, $P + 32.7);
    $pdf->Cell(45, 8, 'BULLETIN DE NOTES', 0, 0, 'L');
    $pdf->SetFont('amirib', 'B', 15);
    $pdf->SetRTL(true, false);
    $pdf->SetTextColor(187, 18, 24);
    $pdf->SetXY(133, $P + 37.8, true);
    $pdf->Cell(35, 6, 'كشف الدرجات', 0, 0, 'R');
    $pdf->SetTextColor(0);
    $pdf->SetRTL(false, false);

    $pdf->SetFont('amirib', 'B', 15);
    $pdf->SetXY(65, $P + 44);
    $pdf->Cell(45, 6, pdf_u_ar($trimestre['libelle_trim']), 0, 0, 'L');
    $pdf->SetXY(130, $P + 43.5);
    $pdf->Cell(45, 6, 'Classe : ' . $classe_nom, 0, 0, 'L');
    $pdf->SetFont('amirib', 'B', 10);
    $pdf->SetRTL(true, false);
    $pdf->SetXY(65 + 40, $P + 48.5, true);
    $pdf->Cell(40, 4, $trim_ar_lib, 0, 0, 'R');
    $pdf->SetXY(130 + 30, $P + 47.5, true);
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

    // ── Champs élève (FR gras + légende AR dessous) ───────────────
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
    $legende = function (string $t, float $x, float $w) use ($pdf): void {
        $pdf->Cell($w, 3.4, $t, 0, 0, 'R');
    };
    $pdf->SetXY(50 + 55, $P + 56, true); $legende('رقم التسجيل', 50, 55);
    $pdf->SetXY(115 + 40, $P + 56, true); $legende('عدد الطلاب', 115, 40);
    $pdf->SetXY(160 + 35, $P + 56, true); $legende('راسب (ة)', 160, 35);
    $pdf->SetXY(50 + 100, $P + 62, true); $legende('اسم الطالب (ة)', 50, 100);
    $pdf->SetXY(50 + 100, $P + 68, true); $legende('تاريخ ومكانة الميلاد', 50, 100);
    $pdf->SetXY(160 + 35, $P + 68, true); $legende('جنس', 160, 35);
    $pdf->SetXY(50 + 100, $P + 74, true); $legende('المعلم الدائم', 50, 100);
    $pdf->SetRTL(false, false);

    // ── Tableau des matières ──────────────────────────────────────
    $y0 = $P + 79;
    $w_grp = 6; $w_mat = 62; $w_note = 15; $w_moy = 16; $w_coef = 11; $w_mc = 15; $w_appr = 199 - 2 * 5 - $w_grp - $w_mat - 2 * $w_note - $w_moy - $w_coef - $w_mc;
    $x0 = 9;

    pdf_fill($pdf, 'groupe_competence');
    $pdf->SetFont('helvetica', 'B', 7.5);
    $pdf->SetXY($x0, $y0);
    $pdf->MultiCell($w_grp + $w_mat, 8, '', 1, 'C', true, 0, $x0, $y0, true);
    $pdf->SetXY($x0, $y0 + 0.5);
    $pdf->Cell($w_grp + $w_mat, 3.5, 'Matieres', 0, 0, 'C');
    $pdf->SetRTL(true, false);
    $pdf->SetFont('amirib', '', 7);
    $pdf->SetXY($x0 + $w_grp + $w_mat, $y0 + 4, true);
    $pdf->Cell($w_grp + $w_mat, 3.5, 'المواد', 0, 0, 'C');
    $pdf->SetRTL(false, false);

    $x = $x0 + $w_grp + $w_mat;
    foreach ([['Mois 1', 'شهر 1', $w_note], ['Mois 2', 'شهر 2', $w_note], ['Moyenne', 'المعدل', $w_moy], ['Coef', 'كوف', $w_coef], ['Moy x Coef', 'موين × كوف', $w_mc], ['Appreciations', 'التقديرات', $w_appr]] as [$fr, $ar, $w]) {
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
    $pdf->SetFillColor(255, 255, 255);
    foreach ($groupes as $g) {
        $y_grp_debut = $y;
        foreach ($g['matieres'] as $m) {
            $id_mat = (int) $m['id_mat'];
            $note = note_matiere_trimestre_arabe($id, $id_mat, $id_classe, $id_trim, $val_annee);
            $moycoef = $note['moyenne'] !== null ? round($note['moyenne'] * (int) $m['coef'], 2) : null;
            $appr = appreciation_moyenne_arabe($note['moyenne']);

            // Nom de la matière bilingue "FR / AR" dans une seule cellule,
            // comme sur le modèle de référence (ex. "Lecture du Coran / القرآن") —
            // l'ancien code positionnait une police/RTL pour le nom arabe
            // mais ne le dessinait jamais (aucun Cell()/Text() entre les 2
            // SetRTL), le nom arabe n'apparaissait donc jamais. Corrigé.
            $pdf->SetXY($x0 + $w_grp, $y);
            $pdf->SetFont('amirib', 'B', 7);
            $pdf->Cell($w_mat, 6, ' ' . $m['matiere_fr'] . ' / ' . $m['matiere_ar'], 1, 0, 'L');

            $xx = $x0 + $w_grp + $w_mat;
            $pdf->SetFont('helvetica', '', 7.5);
            $pdf->SetXY($xx, $y); $pdf->Cell($w_note, 6, $note['note1'] !== null ? (string) $note['note1'] : '', 1, 0, 'C'); $xx += $w_note;
            $pdf->SetXY($xx, $y); $pdf->Cell($w_note, 6, $note['note2'] !== null ? (string) $note['note2'] : '', 1, 0, 'C'); $xx += $w_note;
            $pdf->SetFont('helvetica', 'B', 7.5);
            $pdf->SetXY($xx, $y); $pdf->Cell($w_moy, 6, $note['moyenne'] !== null ? (string) $note['moyenne'] : '', 1, 0, 'C'); $xx += $w_moy;
            $pdf->SetFont('helvetica', '', 7.5);
            $pdf->SetXY($xx, $y); $pdf->Cell($w_coef, 6, (string) (int) $m['coef'], 1, 0, 'C'); $xx += $w_coef;
            $pdf->SetXY($xx, $y); $pdf->Cell($w_mc, 6, $moycoef !== null ? (string) $moycoef : '', 1, 0, 'C'); $xx += $w_mc;
            $pdf->SetFont('amirib', 'B', 6.5);
            $pdf->SetRTL(true, false);
            $pdf->SetXY($xx + $w_appr, $y, true); $pdf->Cell($w_appr, 6, pdf_u_ar_appr($appr), 1, 0, 'C');
            $pdf->SetRTL(false, false);

            $y += 6;
        }
        $h_grp = $y - $y_grp_debut;
        $pdf->Rect($x0, $y_grp_debut, $w_grp, $h_grp, 'D');
        // Libellé de groupe en arabe, en une seule ligne pivotée à 90°
        // (comme sur le modèle de référence), centrée dans la bande —
        // StartTransform()/Rotate() plutôt que l'empilement de mots
        // horizontaux utilisé avant (qui coupait les mots n'importe où).
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
    $total_pts = 0.0; $total_coef = 0;
    foreach ($matieres as $m) {
        $n = note_matiere_trimestre_arabe($id, (int) $m['id_mat'], $id_classe, $id_trim, $val_annee);
        if ($n['moyenne'] !== null) { $total_pts += $n['moyenne'] * (int) $m['coef']; $total_coef += (int) $m['coef']; }
    }
    $pdf->SetXY($x0 + $w_grp + $w_mat + 2 * $w_note + $w_moy, $y);
    $pdf->Cell($w_coef, 6, (string) $total_coef, 1, 0, 'C');
    $pdf->Cell($w_mc, 6, number_format($total_pts, 2), 1, 0, 'C');
    $pdf->Cell($w_appr, 6, '', 1, 0, 'C');
    $y += 6;

    // ── DISCIPLINES / TRAVAIL / RESULTAT DE L'ELEVE ────────────────
    $y += 4;
    $jours = jours_absence_non_justifiees_trimestre($id, $id_classe, $id_trim, $val_annee);
    $abs_jus = (float) db_val("SELECT nbre_jour_jus FROM absence WHERE id_eleve=? AND classe=? AND id_trim=? AND val_annee=?", [$id, $id_classe, $id_trim, $val_annee]);
    $exclusion = db_val("SELECT nbre_jours FROM exclusion WHERE id_eleve=? AND classe=? AND id_trim=? AND val_annee=?", [$id, $id_classe, $id_trim, $val_annee]);
    $m = mention_travail($resultat['moyenne'], $jours);

    pdf_fill($pdf, 'entete_bleu');
    $pdf->Rect($x0, $y, 60, 6, 'DF');
    $pdf->Rect($x0 + 60, $y, 60, 6, 'DF');
    $pdf->Rect($x0 + 120, $y, 199 - 10 - 120, 6, 'DF');
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->Text($x0 + 4, $y + 4, 'DISCIPLINES');
    $pdf->Text($x0 + 68, $y + 4, 'TRAVAIL');
    $pdf->Text($x0 + 128, $y + 4, "RESULTAT DE L'ELEVE");
    // Sous-titres arabes de ces 3 sections, comme sur le modèle de
    // référence (Desktop/BD JAYNITARE/modele/ara_trim.pdf) — absents avant.
    $pdf->SetFont('amirib', 'B', 8);
    $pdf->SetRTL(true, false);
    $pdf->SetXY($x0 + 56, $y + 4.2, true); $pdf->Cell(20, 3.6, 'السلوك', 0, 0, 'R');
    $pdf->SetXY($x0 + 116, $y + 4.2, true); $pdf->Cell(20, 3.6, 'المجموعة', 0, 0, 'R');
    $pdf->SetXY($x0 + 199 - 10 - 4, $y + 4.2, true); $pdf->Cell(35, 3.6, 'نتيجة الطالب', 0, 0, 'R');
    $pdf->SetRTL(false, false);
    $y += 8;

    // Libellés bilingues "FR / AR" sur chacune des 3 colonnes — comme sur le
    // modèle de référence (Desktop/BD JAYNITARE/modele/ara_trim.pdf), absent
    // avant (seuls les 3 sous-titres de section l'étaient).
    $lignes_disc = [
        ['Absences Justifiées / غياب مبرر', (string) (int) $abs_jus, "Tableau d'Honneur / لوحة الشرف", $m['tableau_honneur'], 'Total points / مجمل النقاط', number_format($total_pts, 2)],
        ['Absences Non Justif. / غياب لم يبرر', (string) (int) $jours, 'Encouragements / التشجيع', $m['encouragement'], 'Moyenne / المعدل', $resultat['moyenne'] !== null ? number_format($resultat['moyenne'], 2) : '—'],
        ['Exclusion(jrs) / الاستبعاد', $exclusion !== null ? (string) $exclusion : '---', 'Félicitations / تهنئة', $m['felicitations'], 'Rang / الترتيب', ($resultat['rang'] ?: '—') . ($resultat['rang'] ? ' /' . $resultat['effectif'] : '')],
        ['Avert. Conduite / تحذير القيادة', $m['avertissement_conduite'], 'Avert. Travail / تحذير العمل', $m['avertissement'], 'Appréciation / التقدير', appreciation_moyenne_arabe($resultat['moyenne'])],
        ['Blâme conduite / اللوم على السلوك', $m['blame_conduite'], 'Blâme Travail / اللوم على العمل', $m['blame'], 'Observations / الملاحظات', ''],
    ];
    foreach ($lignes_disc as $ligne) {
        $pdf->SetFont('amirib', '', 6.7);
        $pdf->SetXY($x0, $y);
        $pdf->Cell(40, 5.5, ' ' . $ligne[0], 1, 0, 'L');
        if (is_bool($ligne[1])) { $pdf->Cell(20, 5.5, '', 1, 0, 'C'); pdf_case_ar($pdf, $ligne[1], $x0 + 48, $y + 1.2); }
        else { $pdf->Cell(20, 5.5, $ligne[1], 1, 0, 'C'); }
        $pdf->Cell(40, 5.5, ' ' . $ligne[2], 1, 0, 'L');
        if (is_bool($ligne[3])) { $pdf->Cell(20, 5.5, '', 1, 0, 'C'); pdf_case_ar($pdf, $ligne[3], $x0 + 108, $y + 1.2); }
        else { $pdf->Cell(20, 5.5, $ligne[3], 1, 0, 'C'); }
        $pdf->SetFont('amirib', 'B', 6.7);
        $pdf->Cell(35, 5.5, ' ' . $ligne[4], 1, 0, 'L');
        // La valeur "Appréciation" est en arabe (appreciation_moyenne_arabe) —
        // seule valeur non latine de ce tableau, police/RTL dédiés pour elle.
        if (str_starts_with($ligne[4], 'Appréciation')) {
            $w_last = 199 - 10 - 40 - 20 - 40 - 20 - 35;
            $pdf->SetFont('amirib', 'B', 7.5);
            $pdf->SetRTL(true, false);
            $pdf->SetXY($x0 + 155 + $w_last, $y, true);
            $pdf->Cell($w_last, 5.5, $ligne[5], 1, 0, 'C');
            $pdf->SetRTL(false, false);
        } else {
            $pdf->Cell(199 - 10 - 40 - 20 - 40 - 20 - 35, 5.5, $ligne[5], 1, 0, 'C');
        }
        $y += 5.5;
    }
    $y += 6;

    // ── Signatures + date ──────────────────────────────────────────
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetXY($x0, $y);
    $pdf->Cell(60, 5, 'ENSEIGNANT(E)', 0, 0, 'C');
    $pdf->Cell(60, 5, 'LE DIRECTEUR', 0, 0, 'C');
    $pdf->Cell(199 - 10 - 120, 5, 'PARENT', 0, 0, 'C');
    $y += 5;
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetXY($x0, $y);
    $pdf->Cell(199 - 10, 5, 'Fait a Ngaoundere le, ' . date('d/m/Y'), 0, 0, 'C');

    if ($avec_sig) {
        pdf_signature_appliquer_jn_tcpdf($pdf, 'bulletin_trimestriel_arabe', $x0 + 60, $y - 15, 60, 15, [
            'x_pct' => 25, 'y_pct' => 20, 'w_pct' => 50, 'h_pct' => null,
        ]);
    }

    // Fichier mis en cache disque (voir pdf/verif_lib.php) — pas de unlink.
    $qr_tmp = bulletin_qr_fichier_temp($eleve, 'trim', $id_trim, 'ar');
    if ($qr_tmp) {
        $pdf->Image($qr_tmp, 96, $y + 10, 16, 16, 'PNG');
    }

    // Copyright standard du système (pdf/header_pdf_tcpdf.php) — texte unique
    // sur tous les PDF du projet, voir pdf_copyright()/tcpdf_copyright().
    tcpdf_copyright($pdf, 210, 297);
}

// Signature établissement pour TCPDF (même infra que pdf_signature_appliquer_jn()
// côté FPDF — pdf/header_pdf.php — mais Image() est appelé sur un TCPDF ici).
function pdf_signature_appliquer_jn_tcpdf(TCPDF $pdf, string $type_document,
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

// TCPDF est nativement UTF-8 : aucune conversion nécessaire (contrairement
// à pdf_u() côté FPDF) — ces 2 fonctions existent seulement pour documenter
// l'intention à l'appel (texte déjà en UTF-8 brut, jamais reconverti).
function pdf_u_ar(string $s): string { return $s; }
function pdf_u_ar_appr(string $s): string { return $s; }

// Préchargement en masse (optimisation, voir notes_apc_arabe.php) : même
// principe que pdf/bulletin_trimestriel.php côté français.
precharger_notes_sequence_classe_arabe($id_classe);

// Enveloppé dans un try/catch : accessible publiquement via le QR du
// bulletin — voir fonctions.php::pdf_erreur_generation().
try {
$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(5, 5, 5);
$pdf->SetAutoPageBreak(false, 0);

foreach ($liste as $e) {
    dessiner_bulletin_trimestriel_arabe(
        $pdf, $e['id_eleve'], $e['id_classe'], $e['classe_nom'], $id_trim, $val_annee,
        $etab, $etab_ar, $trimestre, $trim_ar_lib, $effectif_classe, $avec_sig
    );
}

$nom_fichier = $id ? ('bulletin_arabe_' . ($liste[0]['id_eleve']) . '_T' . $id_trim . '.pdf') : ('bulletins_arabe_' . $liste[0]['classe_nom'] . '_T' . $id_trim . '.pdf');
$pdf->Output($nom_fichier, $dl ? 'D' : 'I');
} catch (Throwable $e) {
    pdf_erreur_generation($e);
}
