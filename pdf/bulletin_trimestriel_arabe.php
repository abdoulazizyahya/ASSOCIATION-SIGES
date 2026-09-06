<?php
// ── PDF : Bulletin trimestriel — piste arabe (TCPDF, RTL réel) ──────
// Cadre arrondi pleine page, en-tête bilingue (République/Ministère/
// Délégations/École FR+AR), bandeau titre en pilule, grille Matricule/
// Effectif/Redoublant/Nom/Né(e) le/Sexe/Titulaire, tableau des matières
// (Écrit/Orale/Pratique/Moyenne/Coef/Moy×Coef/Appréciations) groupé par
// groupe_matiere_arabe, bloc DISCIPLINES/TRAVAIL/RESULTAT DE L'ELEVE,
// signatures, QR.
//
// Rendu RTL via pdf/header_pdf_tcpdf.php (tcpdf_colonne_lignes/
// tcpdf_cellule_fr_ar) plutôt que writeHTML().
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
if (!$acces_public) exiger_acces_pedagogie();

require_once __DIR__ . '/header_pdf.php'; // pdf_filigrane_chemin() (GD, indépendant de FPDF/TCPDF)
require_once __DIR__ . '/../pdf/tcpdf/config/tcpdf_config.php';
require_once __DIR__ . '/../pdf/tcpdf/tcpdf.php';
require_once __DIR__ . '/header_pdf_tcpdf.php';

$id        = (int) ($_GET['id'] ?? 0);
$id_classe = (int) ($_GET['classe'] ?? 0);
$id_trim   = (int) ($_GET['trim'] ?? 0);
$dl        = ($_GET['dl'] ?? '0') === '1';
$avec_sig  = ($_GET['signature'] ?? '0') === '1';
$chiffres_ar = ($_GET['chiffres_ar'] ?? '0') === '1'; // choix explicite de l'utilisateur (bouton "Convertir", pages/bulletins_arabe/index.php) — pas la valeur par défaut.
if (!$id_trim || (!$id && !$id_classe)) die('Paramètres id (ou classe) / trim manquants.');

$annee     = get_annee_active();
$val_annee = $annee['val_annee'] ?? '';
if (!$acces_public) {                       // cloisonnement enseignant (piste arabe)
    exiger_acces_classe($id_classe, $val_annee, 'ar');
    exiger_acces_eleve($id, 'ar');
}
$trimestre = db_one("SELECT * FROM trimestre WHERE id_trim=?", [$id_trim]);
if (!$trimestre) die('Trimestre introuvable.');

$etab_brut = get_etablissement();
$etab      = etab_pour_pdf($etab_brut);
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

// Icône COTE (❌/⏳/🥇/⭐, assets/img/pdf/cote_*.png) pour le code retourné
// par appreciation_fr_arabe() — dessine l'icône centrée dans une cellule
// $w x $h déjà bordée/remplie ailleurs.
function pdf_cote_icone(TCPDF $pdf, string $code, float $x, float $y, float $w, float $h): void {
    $fichiers = ['na' => 'cote_na.png', 'eca' => 'cote_eca.png', 'a' => 'cote_a.png', 'aplus' => 'cote_aplus.png'];
    if (!isset($fichiers[$code])) return;
    $taille = min($w, $h) - 2;
    $pdf->Image(__DIR__ . '/../assets/img/pdf/' . $fichiers[$code], $x + ($w - $taille) / 2, $y + ($h - $taille) / 2, $taille, $taille);
}

// Dessine un texte contenant un rang scolaire ("...18e...", "...18ex...")
// avec le suffixe e/ex en exposant.
function pdf_texte_rang_exposant(TCPDF $pdf, float $x, float $y, float $w, float $h, string $texte,
                                  string $font, string $style, float $taille, string $align = 'C'): void {
    if (!preg_match('/^(.*?)(\d+)(ex|e)(.*)$/u', $texte, $m)) {
        $pdf->SetFont($font, $style, $taille);
        $pdf->SetXY($x, $y);
        $pdf->Cell($w, $h, $texte, 0, 0, $align);
        return;
    }
    [, $avant, $nombre, $suffixe, $apres] = $m;
    $taille_exp = round($taille * 0.65, 1);
    $pdf->SetFont($font, $style, $taille);
    $w_avant = $avant !== '' ? $pdf->GetStringWidth($avant) : 0;
    $w_nombre = $pdf->GetStringWidth($nombre);
    $w_apres = $apres !== '' ? $pdf->GetStringWidth($apres) : 0;
    $pdf->SetFont($font, $style, $taille_exp);
    $w_suffixe = $pdf->GetStringWidth($suffixe);
    $w_total = $w_avant + $w_nombre + $w_suffixe + $w_apres;
    $lh = $taille / 2.2;
    $xx = $align === 'C' ? $x + ($w - $w_total) / 2 : $x;
    $pdf->SetFont($font, $style, $taille);
    if ($avant !== '') { $pdf->SetXY($xx, $y); $pdf->Cell($w_avant, $lh, $avant, 0, 0, 'L'); $xx += $w_avant; }
    $pdf->SetXY($xx, $y);
    $pdf->Cell($w_nombre, $lh, $nombre, 0, 0, 'L');
    $xx += $w_nombre;
    $pdf->SetFont($font, $style, $taille_exp);
    $pdf->SetXY($xx, $y - $taille * 0.15);
    $pdf->Cell($w_suffixe, $taille_exp / 2.2, $suffixe, 0, 0, 'L');
    $xx += $w_suffixe;
    $pdf->SetFont($font, $style, $taille);
    if ($apres !== '') { $pdf->SetXY($xx, $y); $pdf->Cell($w_apres, $lh, $apres, 0, 0, 'L'); }
}

// Orale/Écrite/Pratique brutes d'une matière pour UNE séquence (UA) donnée —
// null si la matière n'a pas de barème (migration_v46) ou n'a pas été composée.
function detail_sequence_matiere_arabe(int $id_eleve, int $id_mat, int $id_classe, int $id_seq): ?array {
    $row = db_one(
        "SELECT note_orale, note_ecrite, note_pratique FROM composer_sequence_arabe WHERE id_eleve=? AND id_mat=? AND classe=? AND id_seq=?",
        [$id_eleve, $id_mat, $id_classe, $id_seq]
    );
    if (!$row) return null;
    return [
        'orale' => $row['note_orale'] !== null ? (float) $row['note_orale'] : null,
        'ecrite' => $row['note_ecrite'] !== null ? (float) $row['note_ecrite'] : null,
        'pratique' => $row['note_pratique'] !== null ? (float) $row['note_pratique'] : null,
    ];
}

// Cellule bilingue FR (gras, en haut) / AR (normal, en bas) — variante
// locale de tcpdf_cell_bilingue() avec resets explicites (RTL/couleur de
// texte) avant le FR, pour garantir l'ordre haut/bas quel que soit l'état
// laissé par l'appel précédent.
function bilingue(TCPDF $pdf, float $x, float $y, float $w, float $h, string $fr, string $ar,
                   int $border = 1, string $align = 'C', bool $fill = false,
                   float $taille_fr = 7.5, float $taille_ar = 6.5, array $couleur_texte = [0, 0, 0],
                   string $style_ar = '', float $gap = 0.1, string $police_fr = 'helvetica'): void {
    if ($fill || $border) {
        $pdf->Rect($x, $y, $w, $h, $fill && $border ? 'DF' : ($fill ? 'F' : 'D'));
    }
    // Rétrécit FR/AR indépendamment si le texte dépasse $w à la taille
    // demandée (Cell() ne retourne jamais à la ligne et déborderait sur
    // les cellules voisines).
    $pdf->SetFont($police_fr, 'B', $taille_fr);
    while ($pdf->GetStringWidth($fr) > $w - 1 && $taille_fr > 5) { $taille_fr -= 0.5; $pdf->SetFontSize($taille_fr); }
    $pdf->SetFont('amirib', $style_ar, $taille_ar);
    while ($pdf->GetStringWidth($ar) > $w - 1 && $taille_ar > 5) { $taille_ar -= 0.5; $pdf->SetFontSize($taille_ar); }
    // Bloc FR+AR compact (interligne réduit à $gap), centré dans $h — plus
    // le découpage en 2 moitiés de $h (qui laissait un grand vide au milieu
    // dès que $h dépassait la hauteur réelle des 2 lignes).
    $lh_fr = $taille_fr / 2.2; $lh_ar = $taille_ar / 2.2;
    $bloc_h = $lh_fr + $gap + $lh_ar;
    $y_fr = $y + ($h - $bloc_h) / 2;
    $y_ar = $y_fr + $lh_fr + $gap;
    $pdf->SetRTL(false, false);
    $pdf->SetTextColor($couleur_texte[0], $couleur_texte[1], $couleur_texte[2]);
    $pdf->SetFont($police_fr, 'B', $taille_fr);
    $pdf->SetXY($x, $y_fr);
    $pdf->Cell($w, $lh_fr, $fr, 0, 0, $align);
    $pdf->SetFont('amirib', $style_ar, $taille_ar);
    $pdf->SetRTL(true, false);
    $pdf->SetXY($x + $w, $y_ar, true);
    $pdf->Cell($w, $lh_ar, $ar, 0, 0, $align);
    $pdf->SetRTL(false, false);
    $pdf->SetTextColor(0, 0, 0);
}

// Cellule bilingue "FR / AR" sur UNE seule ligne, les deux en gras — pour
// l'en-tête du tableau (COMPETENCES/UA1/UA2/MOY/COTE), par opposition à
// bilingue() (empilée, utilisée pour les matières/groupes).
function bilingue_ligne(TCPDF $pdf, float $x, float $y, float $w, float $h, string $fr, string $ar,
                         int $border = 1, string $align = 'C', bool $fill = false, float $taille = 9): void {
    if ($fill || $border) {
        $pdf->Rect($x, $y, $w, $h, $fill && $border ? 'DF' : ($fill ? 'F' : 'D'));
    }
    $texte = $fr . ' / ' . $ar;
    $pdf->SetFont('amirib', 'B', $taille);
    while ($pdf->GetStringWidth($texte) > $w - 1 && $taille > 5) { $taille -= 0.5; $pdf->SetFontSize($taille); }
    $pdf->SetXY($x, $y + ($h - $taille / 2.6) / 2);
    $pdf->Cell($w, $taille / 2.2, $texte, 0, 0, $align);
}

function dessiner_bulletin_trimestriel_arabe(
    TCPDF $pdf, int $id, int $id_classe, string $classe_nom, int $id_trim, string $val_annee,
    array $etab, array $etab_ar, array $trimestre, string $trim_ar_lib, int $effectif_classe, bool $avec_sig,
    bool $chiffres_ar = false
): void {
    $eleve = db_one("SELECT * FROM eleve WHERE id_eleve=?", [$id]);
    if (!$eleve) return;
    $statut = db_val("SELECT Statut_elv FROM inscrire WHERE id_eleve=? AND IDClasses=? AND val_annee=?", [$id, $id_classe, $val_annee]) ?: 'Non';

    // $pdf est réutilisé pour tous les élèves du lot (impression classe entière)
    // — remis à OFF à chaque bulletin, réactivé plus bas seulement après
    // l'entête/identification de CE bulletin.
    if ($pdf instanceof TCPDF_ChiffresArabes) {
        $pdf->convertirChiffres = false;
    }
    // Police STABLE pour tout ce qui suit le tableau de compétences : amirib
    // partout si "Convertir" est actif (elle a les glyphes ٠١٢٣..., contrairement
    // à helvetica), sinon helvetica comme avant — jamais basculée à la volée.
    $police_ch = $chiffres_ar ? 'amirib' : 'helvetica';

    [$lbl_ua1, $lbl_ua2] = match ($id_trim) {
        2 => ['UA3', 'UA4'],
        3 => ['UA5', 'UA6'],
        default => ['UA1', 'UA2'],
    };
    [$lbl_ua1_ar, $lbl_ua2_ar] = match ($id_trim) {
        2 => ['الوحدة 3', 'الوحدة 4'],
        3 => ['الوحدة 5', 'الوحدة 6'],
        default => ['الوحدة 1', 'الوحدة 2'],
    };

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
    //$pdf->SetRTL(false, false);
    $pdf->SetXY(68, 9, true);
    $pdf->Cell(74, 3, 'بسم الله الرحمن الرحيم', 0, 0, 'C');
   // $pdf->SetRTL(false, false);
    if (!empty($etab['logo'])) {
        $logo_path = __DIR__ . '/../assets/uploads/' . $etab['logo'];
        if (is_file($logo_path)) $pdf->Image($logo_path, 92, 18, 26, 25);
    }

    // ── En-tête bilingue complet (République/Devise/Ministère/Délégations/École) ──
    tcpdf_colonne_lignes($pdf, [
        
		[$etab_ar['pays_etab_fr'] ?? '', 'helvetica', '', 7, 3.4],
        [$etab_ar['region_etab_fr'] ?? '', 'helvetica', '', 6.5, 3.4],
        [$etab_ar['departement_fr'] ?? '', 'helvetica', '', 6.5, 3.4],
        [$etab_ar['arrondissement_fr'] ?? '', 'helvetica', '', 6.5, 3.4],
        [$etab_ar['Nom_Etab_Fr'] ?? '', 'helvetica', 'B', 9, 5],
		['B.P. ' . ($etab_ar['boite_postal'] ?? '') . ' ' . ($etab_ar['ville_etab'] ?? '') . ' - Tél.: ' . ($etab_ar['tel_etab'] ?? ''), 'helvetica', '', 6, 3],
		
		
    ], 10, 14, 82, false);

    tcpdf_colonne_lignes($pdf, [
        /*[$etab_ar['republique_ar'] ?? '', 'amirib', '', 8, 3.6],
        [$etab_ar['devise_ar'] ?? '', 'amirib', '', 7.5, 3.6],
        ['**********', 'helvetica', '', 6.5, 3.4],*/
		[$etab_ar['pays_etab_ar'] ?? '', 'amirib', '', 9, 3.3],
        [$etab_ar['region_ar'] ?? '', 'amirib', '', 9, 3.3],
        [$etab_ar['departement_ar'] ?? '', 'amirib', '', 9, 3.3],
        [$etab_ar['arrondissement_ar'] ?? '', 'amirib', '', 9, 3.3],
        [$etab_ar['ecole_ar'] ?? '', 'amirib', 'B', 12, 5],
		['ص.ب ' . ($etab_ar['boite_postal'] ?? '') . ' ' . ($etab_ar['ville_etab'] ?? '') . ' - هاتف: ' . ($etab_ar['tel_etab'] ?? ''), 'amirib', '', 7, 4],
		
    ], 121, 14, 82, true);

   /* $pdf->SetFont('amirib', 'B', 11);
    $pdf->Text(150, $P + 40, 'Année scolaire : ' . $val_annee);
    $pdf->SetRTL(true, false);
    $pdf->SetXY(150 + 45, $P + 43, true);
    $pdf->Cell(45, 4, 'العام الدراسي', 0, 0, 'R');
    $pdf->SetRTL(false, false);*/

    
    $OX = 4; $OY = $P-9;

    // Bandeau titre (pilule arrondie)
    pdf_fill($pdf, 'groupe_competence');
   
	$pdf->RoundedRect(50 + $OX, 33 + $OY, 108, 10, 10, '1111', 'DF');
    $pdf->SetFont('helvetica', 'B', 13);
    $pdf->SetTextColor(0);
    $pdf->Text(56 + $OX, 32.5 + $OY, ' BULLETIN DE NOTES    - ');
    $pdf->SetFont('amirib', '', 15);
    $pdf->SetRTL(true, false);
    $pdf->SetXY(111 + $OX +40, 32.5 + $OY, true);
    $pdf->Cell(47, 5, ' كشف الدرجات', 0, 0, 'R');
    $pdf->SetRTL(false, false);
    $pdf->SetFont('helvetica', 'BI', 13);
    $pdf->Text(68 + $OX, 37 + $OY, pdf_u_ar($trimestre['libelle_trim'].'     - '));
    $pdf->SetFont('amirib', '', 11);
    $pdf->SetRTL(true, false);
    $pdf->SetXY(110 + $OX + 30, 37.5 + $OY, true);
    $pdf->Cell(48, 5, ' ' . $trim_ar_lib, 0, 0, 'R');
    $pdf->SetRTL(false, false);

	
	
	
	
    // Grille CLASSE/NIU/EFFECTIF/REDOUBLANT
    pdf_fill($pdf, 'ligne_alternee');
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetXY(5 + $OX, 44 + $OY);   $pdf->Cell(18, 5.5, ' ', 1, 1, 'C', 0);
    $pdf->SetXY(23.7 + $OX, 44 + $OY); $pdf->Cell(18, 5.5, pdf_u_ar($classe_nom), 1, 1, 'C', 1);
    $pdf->SetXY(42.4 + $OX, 44 + $OY); $pdf->Cell(10, 5.5, '', 1, 1, 'C', 0);
    $pdf->SetXY(53.1 + $OX, 44 + $OY); $pdf->SetFont('helvetica', 'B', 8);
    $pdf->Cell(26, 5.5, pdf_u_ar((string) ($eleve['niu'] ?? '')), 1, 1, 'C', 1);

    $pdf->SetXY(79.8 + $OX, 44 + $OY);  $pdf->Cell(21.3, 5.5, '', 1, 1, 'C', 0);
    $pdf->SetXY(101.8 + $OX, 44 + $OY); $pdf->Cell(12, 5.5, (string) $effectif_classe, 1, 1, 'C', 1);
    $pdf->SetXY(114.6 + $OX, 44 + $OY); $pdf->Cell(27, 5.5, '', 1, 1, 'C', 0);
    $pdf->SetXY(142.3 + $OX, 44 + $OY); $pdf->Cell(16.1, 5.5, pdf_u_ar((string) $statut), 1, 1, 'C', 1);

    $pdf->SetXY(5 + $OX, 50.1 + $OY); $pdf->Cell(40, 5.5, ' ', 1, 1, 'C', 0);
    $pdf->SetXY(45.5 + $OX, 50.1 + $OY);
	
	
	// Nom arabe si renseigné, sinon repli sur le nom français — plus les
	// deux affichés côte à côte comme avant.
	$nom_affiche = !empty($eleve['Nom_arabe_elv'])
		? $eleve['Nom_arabe_elv']
		: mb_strtoupper($eleve['Nom_elv']) . ' ' . ($eleve['Prenom_elv'] ?? '');
	$nom = pdf_u_ar(' ' . $nom_affiche);

	
	  $pdf->SetFont('amirib', '', 12);
    $pdf->Cell(113, 5.5, $nom, 1, 1, 'L', 1);

	$pdf->SetFont('helvetica', 'B', 9);
    $date_naiss = $eleve['Date_naiss_elv'] ? date('d/m/Y', strtotime($eleve['Date_naiss_elv'])) : '';
    $pdf->SetXY(5 + $OX, 56.1 + $OY); $pdf->Cell(24, 5.5, ' ', 1, 1, 'C', 0);
    $pdf->SetXY(29.7 + $OX, 56.1 + $OY);
    $pdf->Cell(95.2, 5.5, pdf_u_ar(' ' . $date_naiss . '  à  ' . ($eleve['Lieu_naiss_elv'] ?? '')), 1, 1, 'L', 1);
    $pdf->SetXY(125.5 + $OX, 56.1 + $OY); $pdf->Cell(16.1, 5.5, '', 1, 1, 'C', 0);
    $pdf->SetXY(142.3 + $OX, 56.1 + $OY); $pdf->Cell(16.1, 5.5, pdf_u_ar((string) ($eleve['Sexe_elv'] ?? '')), 1, 1, 'C', 1);

	
	
    $pdf->SetFont('helvetica', 'I', 7);
	$A=$OY-3;
	
    $pdf->Text(5 + $OX, 46.6 + $OY, ' CLASSE : ');
    $pdf->Text(43 + $OX, 46.6 + $OY, 'NIU');
    $pdf->Text(82.2 + $OX, 46.6 + $OY, 'EFFECTIF : ');
    $pdf->Text(115.5 + $OX, 46.6 + $OY, 'REDOUBLANT : ');
    $pdf->Text(5 + $OX, 52.6 + $OY, ' NOM ET PRENOMS :');
    $pdf->Text(5 + $OX, 58.6 + $OY, ' NE(E) LE :');
    $pdf->Text(126 + $OX, 58.6 + $OY, ' SEXE :');

    // Légende arabe (remplace l'anglais du bulletin français)
	$OY=$OY-5.5;
    $pdf->SetFont('amirib', 'B',11);
    $pdf->SetRTL(true, false);
    $leg = function (string $t, float $x, float $w) use ($pdf, $OX, $OY): void {
        $pdf->SetXY($x + $OX + $w, 49 + $OY, true);
        $pdf->Cell($w, 3.2, $t, 0, 0, 'R');
    };
    $leg('الصف', 5, 18);
    $leg('الرقم', 43, 10);
    $leg('عدد الطلاب', 82.2, 18.3);
    $leg('راسب (ة)', 115.5, 26);
	
	
    $pdf->SetXY(5 + $OX + 40, 55 + $OY, true); $pdf->Cell(40, 3.2, 'اسم الطالب (ة)', 0, 0, 'R');
    $pdf->SetXY(5 + $OX + 24, 61 + $OY, true); $pdf->Cell(24, 3.2, 'وُلِدَ في', 0, 0, 'R');
    $pdf->SetXY(126 + $OX + 16.1, 61 + $OY, true); $pdf->Cell(16.1, 3.2, 'الجنس', 0, 0, 'R');
    $pdf->SetRTL(false, false);

    // Photo
    pdf_fill($pdf, 'groupe_competence');
    $pdf->Rect(167 + $OX, 33 + $OY, 30, 33, 'DF');
    $photo_tmp = photo_eleve_fichier_temp($eleve['Photo_elv'] ?? null, $id);
    if ($photo_tmp) {
        $pdf->Image($photo_tmp, 167.7 + $OX, 33.5 + $OY, 28.6, 31.8);
        @unlink($photo_tmp);
    } else {
        $avatar = stripos($eleve['Sexe_elv'] ?? '', 'F') === 0 ? 'fille.png' : 'garcon.png';
        $avatar_path = __DIR__ . '/../assets/img/avatars/' . $avatar;
        if (is_file($avatar_path)) $pdf->Image($avatar_path, 167.7 + $OX, 33.5 + $OY, 28.6, 31.8);
    }

    // ── Titulaire (hors grille) ──
    $enseignant = db_one(
        "SELECT e.civilite_ens, e.nom_ens, e.prenom_ens FROM enseignant e, enseignat_classe_arabe d
         WHERE e.matricule_ens=d.matricule_ens AND d.IDClasses=? AND d.val_annee=?",
        [$id_classe, $val_annee]
    );
    $pdf->SetFont('helvetica', '', 7);
    $pdf->Text(5 + $OX, 71.5 + $OY, pdf_u_ar(' TITULAIRE :  ' . trim(($enseignant['civilite_ens'] ?? '') . ' ' . ($enseignant['nom_ens'] ?? '') . ' ' . ($enseignant['prenom_ens'] ?? ''))));
    $pdf->SetFont('amirib', '', 11);
    $pdf->SetRTL(true, false);
    $pdf->SetXY(5 + $OX +23, 67 + $OY, true);
    $pdf->Cell(60, 3, 'المعلم الدائم', 0, 0, 'R');
    $pdf->SetRTL(false, false);

    // Activé ici seulement : l'entête + l'identification de l'élève
    // ci-dessus (jusqu'à TITULAIRE) restent TOUJOURS en chiffres occidentaux,
    // quel que soit $chiffres_ar — seul ce qui suit (tableau de compétences
    // → fin du bulletin) peut passer en chiffres arabes-indiens.
    if ($chiffres_ar && $pdf instanceof TCPDF_ChiffresArabes) {
        $pdf->convertirChiffres = true;
    }

    // ── Tableau : COMPETENCES | UA×2 (Oral/Ecrit/Pratique/Total) | MOY |
    // COTE — modèle bd/../modele/centre.pdf (couleurs entete_tableau_arabe/
    // groupe_tableau_arabe/totaux_tableau_arabe, migration_v47). COTE en
    // lettres (NA/ECA/A/A+, appreciation_fr()) comme le bulletin français,
    // pas l'échelle à mots de appreciation_moyenne_arabe().
    $TX0 = 9; $TW = 192; $TX1 = $TX0 + $TW;
    $y0 = 81;
    $w_item = 78; $w_ua = 42; $w_sub = $w_ua / 4; $w_moy = 14; $w_cote = 16;
    $x_ua1 = $TX0 + $w_item; $x_ua2 = $x_ua1 + $w_ua; $x_moy = $x_ua2 + $w_ua; $x_cote = $x_moy + $w_moy;
    $seqs = sequences_du_trimestre($id_trim);
    $id_seq1 = $seqs[0] ?? 0; $id_seq2 = $seqs[1] ?? 0;

    $lw_defaut = $pdf->GetLineWidth();
    $pdf->SetLineWidth(0.1);

    // COMPETENCES/MOY/COTE = $h_entete (une seule cellule). UA1/UA2 partage
    // cette même hauteur totale, mais divisée 1/3 (libellé UA) + 2/3 (ligne
    // Oral/Ecrit/Pratique/Total) — toutes les polices de cet en-tête à 14/gras.
    $h_entete = 12; $h_lbl_ua = $h_entete / 3; $h_sub = $h_entete - $h_lbl_ua;
    pdf_fill($pdf, 'entete_tableau_arabe');
    bilingue($pdf, $TX0, $y0, $w_item, $h_entete, 'COMPETENCES', 'الكفايات', 1, 'C', true, 10, 10, [0, 0, 0], 'B', police_fr: $police_ch);
    if ($id_trim === 2) {
        bilingue_ligne($pdf, $x_ua1, $y0, $w_ua, $h_lbl_ua, $lbl_ua1, $lbl_ua1_ar, 1, 'C', true, 10);
        bilingue_ligne($pdf, $x_ua2, $y0, $w_ua, $h_lbl_ua, $lbl_ua2, $lbl_ua2_ar, 1, 'C', true, 10);
    } else {
        bilingue($pdf, $x_ua1, $y0, $w_ua, $h_lbl_ua, $lbl_ua1, $lbl_ua1_ar, 1, 'C', true, 10, 10, [0, 0, 0], 'B', -1.5, $police_ch);
        bilingue($pdf, $x_ua2, $y0, $w_ua, $h_lbl_ua, $lbl_ua2, $lbl_ua2_ar, 1, 'C', true, 10, 10, [0, 0, 0], 'B', -1.5, $police_ch);
    }
    bilingue($pdf, $x_moy, $y0, $w_moy, $h_entete, 'MOY', 'المعدل', 1, 'C', true, 10, 10, [0, 0, 0], 'B', police_fr: $police_ch);
    bilingue($pdf, $x_cote, $y0, $w_cote, $h_entete, 'COTE', 'التقدير', 1, 'C', true, 10, 10, [0, 0, 0], 'B', police_fr: $police_ch);

    $xx = $x_ua1;
    foreach ([['Oral', 'شفوي'], ['Ecrit', 'كتابي'], ['Pratiq', 'تطبيقي'], ['Total', 'مجموع'],
              ['Oral', 'شفوي'], ['Ecrit', 'كتابي'], ['Pratiq', 'تطبيقي'], ['Total', 'مجموع']] as [$fr, $ar]) {
        bilingue($pdf, $xx, $y0 + $h_lbl_ua, $w_sub, $h_sub, $fr, $ar, 1, 'C', false, 10, 10, [0, 0, 0], 'B', -1.5, $police_ch);
        $xx += $w_sub;
    }

    $y = $y0 + $h_entete;
    // 8.3 -> 7.8 (demande explicite du 29/08/2026, en même temps que l'arabe
    // 10 -> 14pt ci-dessous) : à ce point l'arabe (14pt, ~6.36mm de ligne)
    // occupe déjà la quasi-totalité de $h_row=7.8 à lui seul — le français
    // n'a mécaniquement plus qu'environ 1mm restant (~2.5-3pt, voir calcul
    // de $taille_item_fr plus bas) pour que le bloc tienne SANS agrandir
    // $h_row, comme demandé. Le français devient donc à peine lisible dans
    // le pire cas (nom de matière court, non rétréci) — c'est la conséquence
    // arithmétique directe des deux contraintes posées (14pt fixe + 7.8mm),
    // signalé explicitement plutôt que silencieusement subi.
    $h_row = 7.8;
    $T_bareme = 0.0; $T_points = 0.0;
    $T_bareme_seq1 = 0.0; $T_points_seq1 = 0.0;
    $T_bareme_seq2 = 0.0; $T_points_seq2 = 0.0;
    $cp = 1;

    $h_grp = 6;
    foreach ($groupes as $g) {
        pdf_fill($pdf, 'groupe_tableau_arabe');
        $pdf->RoundedRect($TX0, $y, $TW, $h_grp, 2, '1111', 'DF');
        $pdf->SetFont($police_ch, 'B', 8);
        $pdf->SetXY($TX0 + 2, $y + ($h_grp - 8 / 2.2) / 2);
        $pdf->Cell(110, 8 / 2.2, ' GROUPE ' . $cp . ': ' . $g['fr'], 0, 0, 'L');
        $pdf->SetRTL(true, false);
        $texte_grp_ar = 'المجموعة ' . $cp . ': ' . $g['ar'] . '  ';
        $w_grp_ar = 90; $taille_grp_ar = 10;
        $pdf->SetFont('amirib', 'B', $taille_grp_ar);
        while ($pdf->GetStringWidth($texte_grp_ar) > $w_grp_ar - 1 && $taille_grp_ar > 6) {
            $taille_grp_ar -= 0.5;
            $pdf->SetFontSize($taille_grp_ar);
        }
        $pdf->SetXY($TX1 - 2, $y + ($h_grp - $taille_grp_ar / 2.2) / 2, true);
        $pdf->Cell($w_grp_ar, $taille_grp_ar / 2.2, $texte_grp_ar, 0, 0, 'R');
        $pdf->SetRTL(false, false);
        $y += $h_grp;

        foreach ($g['matieres'] as $m) {
            $id_mat = (int) $m['id_mat'];
            $bareme = bareme_matiere_classe_arabe($id_classe, $id_mat, $val_annee);
            $bareme_pts = $bareme ? (float) $bareme['total_points'] : 20.0;

            $note = note_matiere_trimestre_arabe($id, $id_mat, $id_classe, $id_trim, $val_annee);
            $moy = $note['moyenne'];
            if ($moy !== null) { $T_bareme += $bareme_pts; $T_points += $moy; }

            $d1 = ($id_seq1 && $bareme) ? detail_sequence_matiere_arabe($id, $id_mat, $id_classe, $id_seq1) : null;
            $d2 = ($id_seq2 && $bareme) ? detail_sequence_matiere_arabe($id, $id_mat, $id_classe, $id_seq2) : null;
            $sommeNonNull = fn(?array $d) => $d === null ? null : array_sum(array_filter([$d['orale'], $d['ecrite'], $d['pratique']], fn($v) => $v !== null));
            $pts1 = $bareme ? $sommeNonNull($d1) : $note['note1'];
            $pts2 = $bareme ? $sommeNonNull($d2) : $note['note2'];
            if ($pts1 !== null) { $T_bareme_seq1 += $bareme_pts; $T_points_seq1 += $pts1; }
            if ($pts2 !== null) { $T_bareme_seq2 += $bareme_pts; $T_points_seq2 += $pts2; }

            // Nom de matière sur 2 lignes empilées — arabe en haut, français
            // en bas (demande explicite du 29/08/2026) — plutôt que la seule
            // ligne "FR / AR (Pts)" concaténée d'avant : certains intitulés
            // (ex. "Avoir une foi pure et authentique et mettre en
            // application l'unicité d'Allah", 76 caractères) débordaient de
            // $w_item même réduits au plancher (6pt), le français à lui seul
            // ayant déjà besoin de toute la largeur de la cellule. AR à 14pt
            // FIXE (demande explicite — même taille que l'arabe de l'en-tête
            // COMPETENCES/الكفايات avait été portée à 10pt, puis 14pt ici)
            // SANS agrandir $h_row (doit rester 7.8) : à 14pt l'arabe occupe
            // déjà ~6.36mm de ligne à lui seul, il ne reste donc plus qu'une
            // fraction de mm pour le français — $taille_item_fr est dérivée
            // de l'espace VERTICAL restant (pas seulement de la largeur comme
            // avant), avec un plancher dur à 3pt pour ne jamais aller à 0.
            $pdf->Rect($TX0, $y, $w_item, $h_row, 'D');
            $texte_item_ar = ' ' . $m['matiere_ar'];
            $texte_item_fr = ' ' . $m['matiere_fr'] . ' (' . (int) $bareme_pts . 'Pts)';
            $gap_item = 0.2;
            $taille_item_ar = 14;
            $pdf->SetFont('amirib', 'B', $taille_item_ar);
            while ($pdf->GetStringWidth($texte_item_ar) > $w_item - 2 && $taille_item_ar > 10) {
                $taille_item_ar -= 0.5;
                $pdf->SetFontSize($taille_item_ar);
            }
            $lh_item_ar = $taille_item_ar / 2.2;
            // Espace restant sous l'arabe, converti en taille de police
            // (inverse de lh = taille/2.2) — plafonné à 7 (jamais plus gros
            // que sans la contrainte de hauteur), plancher dur 3.
            $taille_item_fr = max(3, min(7, ($h_row - $lh_item_ar - $gap_item) * 2.2));
            $pdf->SetFont('helvetica', 'B', $taille_item_fr);
            while ($pdf->GetStringWidth($texte_item_fr) > $w_item - 2 && $taille_item_fr > 3) {
                $taille_item_fr -= 0.5;
                $pdf->SetFontSize($taille_item_fr);
            }
            $lh_item_fr = $taille_item_fr / 2.2;
            $y_item_ar = $y + max(0, ($h_row - ($lh_item_ar + $gap_item + $lh_item_fr)) / 2);
            $y_item_fr = $y_item_ar + $lh_item_ar + $gap_item;
            $pdf->SetFont('amirib', 'B', $taille_item_ar);
            $pdf->SetRTL(true, false);
            $pdf->SetXY($TX0 + $w_item, $y_item_ar, true);
            $pdf->Cell($w_item, $lh_item_ar, $texte_item_ar, 0, 0, 'L');
            $pdf->SetRTL(false, false);
            $pdf->SetFont('helvetica', 'B', $taille_item_fr);
            $pdf->SetXY($TX0, $y_item_fr);
            $pdf->Cell($w_item, $lh_item_fr, $texte_item_fr, 0, 0, 'L');

            // Note en rouge si < la moitié de son barème (ex: barème 40 →
            // rouge sous 20) — sous-notes contre le barème de leur propre
            // composante (discipline_arabe.orale/ecrite/pratique), Total
            // contre $bareme_pts (barème total de la matière).
            $xx = $x_ua1;
            $pdf->SetFont($police_ch, '', 9);
            foreach ([['d' => $d1, 'total' => $pts1], ['d' => $d2, 'total' => $pts2]] as $ua) {
                foreach (['orale', 'ecrite', 'pratique'] as $ch) {
                    $v = $ua['d'][$ch] ?? null;
                    $seuil = $bareme ? (float) $bareme[$ch] / 2 : null;
                    $rouge = $v !== null && $seuil !== null && $v < $seuil;
                    $pdf->SetTextColor($rouge ? 255 : 0, 0, 0);
                    $pdf->SetXY($xx, $y);
                    $pdf->Cell($w_sub, $h_row, $v !== null ? rtrim(rtrim(number_format($v, 2), '0'), '.') : '', 1, 0, 'C');
                    $xx += $w_sub;
                }
                $seuil_total = $bareme_pts / 2;
                $rouge_total = $ua['total'] !== null && $ua['total'] < $seuil_total;
                $pdf->SetFont($police_ch, 'B', 10);
                $pdf->SetTextColor($rouge_total ? 255 : 0, 0, 0);
                $pdf->SetXY($xx, $y);
                $pdf->Cell($w_sub, $h_row, $ua['total'] !== null ? rtrim(rtrim(number_format($ua['total'], 2), '0'), '.') : '', 1, 0, 'C');
                $pdf->SetFont($police_ch, '', 9);
                $xx += $w_sub;
            }
            $pdf->SetTextColor(0, 0, 0);

            pdf_fill($pdf, 'ligne_alternee');
            $pdf->SetFont($police_ch, 'B', 10);
            $pdf->SetXY($x_moy, $y);
            $pdf->Cell($w_moy, $h_row, $moy !== null ? number_format($moy, 2) : '', 1, 0, 'C', true);
            $pdf->Rect($x_cote, $y, $w_cote, $h_row, 'DF');
            if ($moy !== null) { pdf_cote_icone($pdf, appreciation_fr_arabe($moy, $bareme_pts), $x_cote, $y, $w_cote, $h_row); }

            // Grille de bordures explicite (renforce les bordures de chaque
            // Cell()/MultiCell() ci-dessus, qui peuvent laisser des segments
            // manquants aux jonctions, notamment sur la cellule de nom de
            // matière dont la hauteur MultiCell() peut différer de $h_row).
            $pdf->Rect($TX0, $y, $TW, $h_row, 'D');
            for ($k = 0; $k <= 7; $k++) {
                $xLigne = $x_ua1 + $k * $w_sub;
                $pdf->Line($xLigne, $y, $xLigne, $y + $h_row);
            }
            $pdf->Line($x_cote, $y, $x_cote, $y + $h_row);

            $y += $h_row;
        }
        $cp++;
    }

    // ── TOTAUX : total/moyenne/rang par UA (comme avant), + MOY et COTE
    // globaux (appreciation_fr($moy_ua_globale, 20), même barème fixe que
    // la colonne MOY du bulletin français). ──
    $moy_seq1 = $id_seq1 ? moyenne_eleve_sur_sequences_arabe($id, $id_classe, [$id_seq1], $val_annee)['moyenne'] : null;
    $moy_seq2 = $id_seq2 ? moyenne_eleve_sur_sequences_arabe($id, $id_classe, [$id_seq2], $val_annee)['moyenne'] : null;
    $moys_dispo = array_filter([$moy_seq1, $moy_seq2], fn($v) => $v !== null);
    $moy_ua_globale = $moys_dispo ? array_sum($moys_dispo) / count($moys_dispo) : null;

    $rang_seq1_txt = ''; $rang_seq2_txt = '';
    if ($id_seq1) {
        $cl1 = classement_sur_sequences_arabe($id_classe, [$id_seq1], $val_annee);
        foreach ($cl1['lignes'] as $l) { if ((int) $l['id_eleve'] === $id) { $rang_seq1_txt = $l['rang'] !== '' ? $l['rang'] . '/' . $cl1['nb_classes'] : ''; break; } }
    }
    if ($id_seq2) {
        $cl2 = classement_sur_sequences_arabe($id_classe, [$id_seq2], $val_annee);
        foreach ($cl2['lignes'] as $l) { if ((int) $l['id_eleve'] === $id) { $rang_seq2_txt = $l['rang'] !== '' ? $l['rang'] . '/' . $cl2['nb_classes'] : ''; break; } }
    }

    pdf_fill($pdf, 'totaux_tableau_arabe');
    bilingue($pdf, $TX0, $y, $w_item, 8, 'TOTAUX', 'مجموع', 1, 'C', true, 10, 10, [0, 0, 0], 'B', -1.5, $police_ch);
    $pdf->SetFont($police_ch, 'B', 6.5);
    $pdf->SetXY($x_ua1, $y);
    $pdf->Cell($w_ua, 4, $moy_seq1 !== null ? 'Total: ' . number_format($T_points_seq1, 2) . '/' . (int) $T_bareme_seq1 : '-', 1, 0, 'C', true);
    $pdf->SetXY($x_ua2, $y);
    $pdf->Cell($w_ua, 4, $moy_seq2 !== null ? 'Total: ' . number_format($T_points_seq2, 2) . '/' . (int) $T_bareme_seq2 : '-', 1, 0, 'C', true);
    $pdf->SetFont($police_ch, 'B', 5.8);
    $pdf->Rect($x_ua1, $y + 4, $w_ua, 4, 'DF');
    pdf_texte_rang_exposant($pdf, $x_ua1, $y + 4, $w_ua, 4, $moy_seq1 !== null ? 'Moy:' . number_format($moy_seq1, 2) . ($rang_seq1_txt !== '' ? '  Rang:' . $rang_seq1_txt : '') : '', $police_ch, 'B', 5.8);
    $pdf->Rect($x_ua2, $y + 4, $w_ua, 4, 'DF');
    pdf_texte_rang_exposant($pdf, $x_ua2, $y + 4, $w_ua, 4, $moy_seq2 !== null ? 'Moy:' . number_format($moy_seq2, 2) . ($rang_seq2_txt !== '' ? '  Rang:' . $rang_seq2_txt : '') : '', $police_ch, 'B', 5.8);

    $pdf->SetFont($police_ch, 'B', 7.5);
    $pdf->SetXY($x_moy, $y);
    $pdf->Cell($w_moy, 8, $moy_ua_globale !== null ? number_format($moy_ua_globale, 2) : '', 1, 0, 'C', true);
    $pdf->Rect($x_cote, $y, $w_cote, 8, 'DF');
    if ($moy_ua_globale !== null) { pdf_cote_icone($pdf, appreciation_fr_arabe($moy_ua_globale, 20), $x_cote, $y, $w_cote, 8); }
    $y += 9;
    // Bordures de tout ce qui suit (DISCIPLINES...signatures) alignées sur
    // celles du tableau de compétences : même épaisseur fine, reset à la fin.

    // ── DISCIPLINES / TRAVAIL / PROFIL DE LA CLASSE / RESULTATS DE L'ELEVE ──
    $jours = jours_absence_non_justifiees_trimestre($id, $id_classe, $id_trim, $val_annee);
    $abs_jus = jours_absence_justifiees_trimestre($id, $id_classe, $id_trim, $val_annee);
    $exclusion = exclusion_jours_trimestre($id, $id_classe, $id_trim, $val_annee);
    $m = mention_travail($resultat['moyenne'], $jours);

    $w4 = [42, 42, 42, 66];
    $x4 = [9, 51, 93, 135];
    pdf_fill($pdf, 'entete_tableau_arabe');
    $pdf->RoundedRect($TX0, $y, array_sum($w4), 8, 3, '1111', 'DF');
    foreach ([['DISCIPLINES', 'السلوك'], ['TRAVAIL', 'العمل'], ['PROFIL DE LA CLASSE', 'ملف القسم'], ["RESULTATS DE L'ELEVE", 'نتيجة الطالب']] as $i => [$fr, $ar]) {
        bilingue($pdf, $x4[$i], $y, $w4[$i], 8, $fr, $ar, 0, 'C', false, 9, 11, [0, 0, 0], 'B', -1.5, $police_ch);
    }
    $y += 8;

    $w_lbl = 29; $w_val = $w4[0] - $w_lbl;
    $ROW5 = 7.0;
    $lignes5 = [
        ['Absences Jus.(Jrs)', 'غياب مبرر', (string) (int) $abs_jus, "Tableau d'honneur", 'لوحة الشرف', $m['tableau_honneur'], 'Moy. de la classe', 'معدل القسم', $resultat['moy_classe']],
        ['Absences NJ.(Jrs)', 'غياب غير مبرر', (string) (int) $jours, 'Encouragement', 'تشجيع', $m['encouragement'], 'Moy. du premier', 'معدل الأول', $resultat['moy_premier']],
        ['Exclusion(Jrs)', 'استبعاد', $exclusion !== null ? (string) $exclusion : '—', 'Félicitations', 'تهنئة', $m['felicitations'], 'Moy. du dernier', 'معدل الأخير', $resultat['moy_dernier']],
        ['Avert. conduite', 'إنذار سلوك', $m['avertissement_conduite'], 'Avert. Travail', 'إنذار عمل', $m['avertissement'], 'Effectif Classé', 'عدد المرتبين', $resultat['nb_classes']],
        ['Blâme conduite', 'لوم سلوك', $m['blame_conduite'], 'Blâme Travail', 'لوم عمل', $m['blame'], 'Taux de réussite', 'نسبة النجاح', $resultat['taux_reussite'] !== null ? $resultat['taux_reussite'] . '%' : null],
    ];
    foreach ($lignes5 as $i => [$l1fr, $l1ar, $v1, $l2fr, $l2ar, $v2, $l3fr, $l3ar, $v3]) {
        $ry = $y + $i * $ROW5;
        foreach ([[$x4[0], $l1fr, $l1ar, $v1], [$x4[1], $l2fr, $l2ar, $v2]] as [$cx, $lfr, $lar, $val]) {
            bilingue($pdf, $cx, $ry, $w_lbl, $ROW5, $lfr, $lar, 1, 'C', false, 9, 11, [0, 0, 0], 'B', -1.5, $police_ch);
            $pdf->SetXY($cx + $w_lbl, $ry);
            if (is_bool($val)) {
                $pdf->Cell($w_val, $ROW5, '', 1, 0, 'C');
                pdf_case_ar($pdf, $val, $cx + $w_lbl + ($w_val - 3) / 2, $ry + ($ROW5 - 3) / 2);
            } else {
                $pdf->SetFont($police_ch, 'B', 10);
                $pdf->Cell($w_val, $ROW5, (string) $val, 1, 0, 'C');
            }
        }
        bilingue($pdf, $x4[2], $ry, $w4[2] - 15, $ROW5, $l3fr, $l3ar, 1, 'C', false, 9, 11, [0, 0, 0], 'B', -1.5, $police_ch);
        $pdf->SetXY($x4[2] + $w4[2] - 15, $ry);
        $pdf->SetFont($police_ch, 'B', 10);
        $pdf->Cell(15, $ROW5, $v3 !== null ? (is_numeric($v3) ? number_format((float) $v3, 2) : (string) $v3) : '—', 1, 0, 'C');
    }
    $bas5 = $y + 5 * $ROW5;

    // ── Bloc RESULTATS DE L'ELEVE (4 lignes, colonne de droite) ─────
    $H_RES = ($bas5 - $y) / 4;
    $lignes_res = [
        ['TOTAL POINTS', 'مجموع النقاط', 'entete_tableau_arabe', number_format($T_points, 2) . ' / ' . (int) $T_bareme],
        ['MOYENNE', 'المعدل', 'cellule_resultat', ($resultat['moyenne'] !== null ? number_format($resultat['moyenne'], 2) : '—') . ' / 20'],
        ['RANG', 'الترتيب', 'entete_tableau_arabe', ($resultat['rang'] ?: '—') . ($resultat['rang'] ? ' / ' . $resultat['effectif'] : '')],
        ['APPRECIATION', 'التقدير', 'cellule_resultat', appreciation_moyenne_arabe($resultat['moyenne'])],
    ];
    $w_res_lbl = 26; $w_res_val = $w4[3] - $w_res_lbl;
    foreach ($lignes_res as $i => [$fr, $ar, $fill, $val]) {
        $ry = $y + $i * $H_RES;
        pdf_fill($pdf, $fill);
        bilingue($pdf, $x4[3], $ry, $w_res_lbl, $H_RES, $fr, $ar, 1, 'C', true, 9, 11, [0, 0, 0], 'B', -1.5, $police_ch);
        if ($fr === 'APPRECIATION') {
            // En RTL, Cell() ancre le rectangle par son bord DROIT — passer
            // le bord droit voulu (x_gauche + largeur) en SetXY(..., true),
            // jamais x_gauche (sinon la cellule se dessine décalée à gauche).
            $pdf->SetFont('amirib', 'B', 11);
            $pdf->SetRTL(true, false);
            $pdf->SetXY($x4[3] + $w_res_lbl + $w_res_val, $ry, true);
            $pdf->Cell($w_res_val, $H_RES, pdf_u_ar_appr($val), 1, 0, 'C', true);
            $pdf->SetRTL(false, false);
        } else {
            $pdf->Rect($x4[3] + $w_res_lbl, $ry, $w_res_val, $H_RES, 'DF');
            pdf_texte_rang_exposant($pdf, $x4[3] + $w_res_lbl, $ry + ($fr === 'RANG' ? 1.5 : 0), $w_res_val, $H_RES, $val, $police_ch, 'B', 11);
        }
    }
    $y = $bas5 + 1;

    // ── Interprétation des cotes + signatures ────────────────────────
    pdf_fill($pdf, 'groupe_tableau_arabe');
    // Ordre : Interprétation des cotes, PARENTS, ENSEIGNANT(E), DIRECTEUR —
    // chaque colonne garde sa propre largeur d'origine (42/38/61), seul
    // l'ordre change. x_sig recalculé en conséquence (cumul des largeurs).
    $w_sig = [51, 42, 38, 61]; $x_sig = [9, 60, 102, 140];
    foreach ([['Interprétation des cotes', 'شرح التقديرات'], ['PARENTS', 'أولياء الأمور'], ['ENSEIGNANT(E)', 'المعلم(ة)'], [mb_strtoupper($etab_ar['chef_etablissement'] ?? 'LE DIRECTEUR'), 'المدير']] as $i => [$fr, $ar]) {
        bilingue($pdf, $x_sig[$i], $y, $w_sig[$i], 6.8, $fr, $ar, 1, 'C', true, 9, 11, [0, 0, 0], 'B', -1.5, $police_ch);
    }
    $y += 6.8;
    $pp = $y;
    $pdf->Rect($x_sig[0], $pp, $w_sig[0], 30, 'D');
    // Intervalle Note/Barème : signification — mêmes seuils que la cote
    // de la colonne COTE ci-dessus (appreciation_fr_arabe(), notes_apc.php).
    $titre_legende = 'Moy( Note / Barème) est comprise entre :';
    $taille_titre = 6.5;
    $pdf->SetFont($police_ch, 'B', $taille_titre);
    while ($pdf->GetStringWidth($titre_legende) > $w_sig[0] - 5 && $taille_titre > 4.5) { $taille_titre -= 0.25; $pdf->SetFontSize($taille_titre); }
    $pdf->SetXY($x_sig[0] + 2, $pp + 1.5);
    $pdf->Cell($w_sig[0] - 4, 4, $titre_legende, 0, 0, 'L');
    // pdf_cote_icone() rend une icône de taille min($w,$h)-2 : pour obtenir
    // la même taille réelle (4.5mm) que dans la colonne COTE (min(16,6.5)-2),
    // on lui passe 6.5 (4.5+2), pas 4.5 directement.
    $w_icone_legende = 4.5;
    $boite_icone_legende = $w_icone_legende + 2;
    foreach ([
        ['na', '[0 ; 0,55[', 'Non acquis'],
        ['eca', '[0,55 ; 0,75[', "En cours d'acquisition"],
        ['a', '[0,75 ; 0,90[', 'Acquis'],
        ['aplus', '[0,9 ; 1]', 'Acquis avec facilité'],
    ] as $i => [$code, $borne, $sign]) {
        $y_ligne = $pp + 6 + $i * 5.7;
        pdf_cote_icone($pdf, $code, $x_sig[0] + 2, $y_ligne, $boite_icone_legende, $boite_icone_legende);
        $ligne = $borne . '  :  ' . $sign;
        $taille = 6.5;
        $pdf->SetFont($police_ch, '', $taille);
        while ($pdf->GetStringWidth($ligne) > $w_sig[0] - 5 - $boite_icone_legende && $taille > 4.5) { $taille -= 0.25; $pdf->SetFontSize($taille); }
        $pdf->SetXY($x_sig[0] + 2 + $boite_icone_legende + 1, $y_ligne + ($boite_icone_legende - 4) / 2);
        $pdf->Cell($w_sig[0] - 4 - $boite_icone_legende - 1, 4, $ligne, 0, 0, 'L');
    }
    $pdf->Rect($x_sig[1], $pp, $w_sig[1], 30, 'D');
    $pdf->Rect($x_sig[2], $pp, $w_sig[2], 30, 'D');
    $pdf->Rect($x_sig[3], $pp, $w_sig[3], 30, 'D');

    if ($avec_sig) {
        // DIRECTEUR est maintenant en dernière position (index 3), pas 2.
        pdf_signature_appliquer_jn_tcpdf($pdf, 'bulletin_trimestriel_arabe', $x_sig[3], $pp, $w_sig[3], 30, [
            'x_pct' => 35, 'y_pct' => 25, 'w_pct' => 35, 'h_pct' => null,
        ]);
    }
    $pdf->SetLineWidth($lw_defaut);

    // ── QR de vérification, centré en bas de page — copyright collé
    // juste en dessous (pas d'écart), voir tcpdf_copyright() plus bas.
    // URL signée ECDSA (bulletin_verif_signature_offline()) : en ligne,
    // ouvre verif_bulletin.php normalement ; hors ligne, vérifiable SANS
    // secret partagé via verif_bulletin_hors_ligne.html (clé PUBLIQUE
    // seulement — un simple "code à comparer à l'œil" serait contournable
    // par quiconque régénère le QR avec des données falsifiées).
    // 35mm : niveau de correction Q (pdf/verif_lib.php), pas H — ~117
    // modules avec le détail par matière inclus (mesuré), contre ~129 à
    // l'ancien niveau H. Toujours assez dense pour garder une grande taille
    // d'impression malgré le contenu complet.
    $qr_taille = 35;
    $qr_y = $ph - 8 - 2 - $qr_taille;
    // Détail par matière repêché (demande du 26/08/2026 — priorité au contenu
    // complet même si le QR est plus dense) : "nom~moyenne~cote", matières
    // séparées par ";", en 10e champ. Recalcul léger (fonctions déjà mises
    // en cache par les boucles d'affichage ci-dessus) plutôt que collecte
    // pendant le rendu — matière non composée exclue (pas de note à montrer).
    $matieres_qr = [];
    foreach ($groupes as $g) {
        foreach ($g['matieres'] as $m) {
            $id_mat_qr = (int) $m['id_mat'];
            $bareme_qr = bareme_matiere_classe_arabe($id_classe, $id_mat_qr, $val_annee);
            $bareme_pts_qr = $bareme_qr ? (float) $bareme_qr['total_points'] : 20.0;
            $note_qr = note_matiere_trimestre_arabe($id, $id_mat_qr, $id_classe, $id_trim, $val_annee);
            if ($note_qr['moyenne'] === null) continue;
            $matieres_qr[] = $m['matiere_fr'] . '~' . number_format($note_qr['moyenne'], 2) . '~' . appreciation_fr($note_qr['moyenne'], $bareme_pts_qr);
        }
    }
    $donnees_offline = [
        (string) ($eleve['Mat_elv'] ?? ''),
        (string) ($eleve['niu'] ?? ''),
        mb_strtoupper($eleve['Nom_elv']) . ' ' . ($eleve['Prenom_elv'] ?? ''),
        $date_naiss . ($eleve['Lieu_naiss_elv'] ? ' à ' . $eleve['Lieu_naiss_elv'] : ''),
        'T' . $id_trim . ' ' . $val_annee,
        $moy_seq1 !== null ? number_format($moy_seq1, 2) : '-',
        $moy_seq2 !== null ? number_format($moy_seq2, 2) : '-',
        $resultat['moyenne'] !== null ? number_format($resultat['moyenne'], 2) . '/20' : '-',
        $resultat['rang'] ? $resultat['rang'] . '/' . $resultat['effectif'] : '-',
        implode(';', $matieres_qr),
    ];
    // $chiffres_ar (préférence d'affichage, pas une donnée de sécurité) est
    // reportée dans l'URL pour que "Ouvrir le bulletin" (verif_bulletin.php)
    // rouvre avec la même préférence que le bulletin scanné.
    $qr_tmp = bulletin_qr_fichier_temp($eleve, 'trim', $id_trim, 'ar', $donnees_offline, $chiffres_ar);
    if ($qr_tmp) {
        $pdf->Image($qr_tmp, ($pw - $qr_taille) / 2, $qr_y, $qr_taille, $qr_taille, 'PNG');
    }

    // tcpdf_copyright() est partagée avec d'autres PDF et toujours en
    // helvetica (glyphes ٠١٢٣... absents) — copyright/branding, pas une
    // donnée du bulletin, désactivé ici pour éviter un "?" à la place du 2.
    if ($pdf instanceof TCPDF_ChiffresArabes) {
        $pdf->convertirChiffres = false;
    }
    tcpdf_copyright($pdf, $pw, $ph, $ph - ($qr_y + $qr_taille) + 1);
}

// Signature établissement pour TCPDF (équivalent pdf_signature_appliquer_jn() côté FPDF).
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

// TCPDF est nativement UTF-8 : pas de conversion nécessaire (contrairement à pdf_u() côté FPDF).
function pdf_u_ar(string $s): string { return $s; }
function pdf_u_ar_appr(string $s): string { return $s; }

// Chiffres occidentaux → arabes-indiens orientaux (٠١٢٣٤٥٦٧٨٩), sur tout le
// texte dessiné (Cell/MultiCell/Text/Write) — SIMPLE substitution de
// caractères, rien d'autre : la police doit déjà être 'amirib' au moment de
// l'appel (choisie par le code appelant, voir dessiner_bulletin_trimestriel_arabe()),
// jamais basculée automatiquement ici. Une bascule de police dynamique à
// l'intérieur de Cell()/etc., testée puis abandonnée, corrompt le subset
// TTF de TCPDF au bout de quelques dizaines d'allers-retours (le texte
// devient des lettres latines aléatoires) — seul un choix de police STABLE,
// fait par l'appelant comme pour tout le reste du fichier, est fiable ici.
// Ne touche pas les données du QR (bulletin_qr_fichier_temp() prend
// $donnees_offline en amont, jamais via ces méthodes).
class TCPDF_ChiffresArabes extends TCPDF {
    private const CHIFFRES = ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩'];

    // OFF par défaut : entête + identification de l'élève (jusqu'à TITULAIRE)
    // restent en chiffres occidentaux quoi qu'il arrive. Activé explicitement
    // juste avant le tableau de compétences (voir dessiner_bulletin_trimestriel_arabe()),
    // seulement si l'utilisateur a coché "Convertir".
    public bool $convertirChiffres = false;

    private function conv(string $s): string {
        return $this->convertirChiffres ? strtr($s, self::CHIFFRES) : $s;
    }

    public function Cell($w, $h = 0, $txt = '', $border = 0, $ln = 0, $align = '', $fill = false, $link = '', $stretch = 0, $ignore_min_height = false, $calign = 'T', $valign = 'M') {
        return parent::Cell($w, $h, $this->conv((string) $txt), $border, $ln, $align, $fill, $link, $stretch, $ignore_min_height, $calign, $valign);
    }

    public function MultiCell($w, $h, $txt, $border = 0, $align = 'J', $fill = false, $ln = 1, $x = null, $y = null, $reseth = true, $stretch = 0, $ishtml = false, $autopadding = true, $maxh = 0, $valign = 'T', $fitcell = false) {
        return parent::MultiCell($w, $h, $this->conv((string) $txt), $border, $align, $fill, $ln, $x, $y, $reseth, $stretch, $ishtml, $autopadding, $maxh, $valign, $fitcell);
    }

    public function Text($x, $y, $txt, $fstroke = 0, $fclip = false, $ffill = true, $border = 0, $ln = 0, $align = '', $fill = false, $link = '', $stretch = 0, $ignore_min_height = false, $calign = 'T', $valign = 'M', $rtloff = false) {
        return parent::Text($x, $y, $this->conv((string) $txt), $fstroke, $fclip, $ffill, $border, $ln, $align, $fill, $link, $stretch, $ignore_min_height, $calign, $valign, $rtloff);
    }

    // Les largeurs calculées à la main (ex: pdf_texte_rang_exposant()) doivent
    // mesurer les glyphes RÉELLEMENT dessinés (chiffres arabes, pas occidentaux).
    public function GetStringWidth($s, $fontname = '', $fontstyle = '', $fontsize = 0, $getarray = false) {
        return parent::GetStringWidth($this->conv((string) $s), $fontname, $fontstyle, $fontsize, $getarray);
    }

    public function Write($h, $txt, $link = '', $fill = false, $align = '', $ln = false, $stretch = 0, $firstline = false, $firstblock = false, $maxh = 0, $wadj = 0, $margin = null) {
        return parent::Write($h, $this->conv((string) $txt), $link, $fill, $align, $ln, $stretch, $firstline, $firstblock, $maxh, $wadj, $margin);
    }
}

precharger_notes_sequence_classe_arabe($id_classe);

try {
$pdf = new TCPDF_ChiffresArabes('P', 'mm', 'A4', true, 'UTF-8', false);
// Police arabe embarquée EN ENTIER, pas en sous-ensemble (TCPDF subset des
// TrueType Unicode par défaut) : le sous-ensemble que TCPDF génère pour une
// police complexe comme amirib (formes présentées/ligatures arabes) est mal
// toléré par certains lecteurs PDF non-Adobe (WPS Office notamment — glyphes
// arabes vides à l'ouverture, alors qu'Adobe/Chrome affichent correctement).
// Fichier plus lourd, mais police 100% standard, lisible partout.
$pdf->setFontSubsetting(false);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(5, 5, 5);
$pdf->SetAutoPageBreak(false, 0);

foreach ($liste as $e) {
    dessiner_bulletin_trimestriel_arabe(
        $pdf, $e['id_eleve'], $e['id_classe'], $e['classe_nom'], $id_trim, $val_annee,
        $etab, $etab_ar, $trimestre, $trim_ar_lib, $effectif_classe, $avec_sig, $chiffres_ar
    );
}

$nom_fichier = $id ? ('bulletin_arabe_' . ($liste[0]['id_eleve']) . '_T' . $id_trim . '.pdf') : ('bulletins_arabe_' . $liste[0]['classe_nom'] . '_T' . $id_trim . '.pdf');
$pdf->Output($nom_fichier, $dl ? 'D' : 'I');
} catch (Throwable $e) {
    pdf_erreur_generation($e);
}
