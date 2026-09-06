<?php
// ── PDF : Bulletin annuel — piste française, AFFICHAGE ANGLAIS ──
// Fichier séparé de pdf/bulletin_annuel.php (même principe que
// pdf/bulletin_trimestriel_anglais.php, demande du 26/08/2026 : ne jamais
// toucher le fichier français) — utilisé quand la classe appartient à un
// niveau de section anglophone (niveau.Section='An'). Structure,
// coordonnées, polices, tailles, couleurs STRICTEMENT identiques au
// bulletin français. Chaque paire de libellés bilingues est PERMUTÉE —
// l'anglais occupe la position/le style qu'occupait le français (gras, en
// premier), et le français occupe la position/le style qu'occupait
// l'anglais (italique, en second) — jamais de changement de taille de
// police, de police, de style ou de position/coordonnée (sauf 3 débordements
// mesurés via GetStringWidth() et corrigés — voir commentaires ponctuels).
//
// EXCEPTION explicite (demande utilisateur) : les groupes de compétences et
// les compétences elles-mêmes NE SONT PAS permutés ici — ils viennent déjà
// correctement de la base (jeu langue='An') et s'affichent déjà en anglais
// pour une classe de cette section, sans paire bilingue à côté (voir
// bulletin_annuel.php, $section_en). Logique identique, copiée telle quelle.
//
// Toujours sur la piste de données 'fr' (bulletin_verif_valider(..., 'fr',
// ...), QR, discipline/composer_sequence) : « anglais » est ici une
// préférence d'AFFICHAGE, pas une piste de données distincte comme l'arabe.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
require_once __DIR__ . '/../notes_apc.php';
require_once __DIR__ . '/verif_lib.php';

// Accès public via le QR code du bulletin (jeton "vh") — voir
// pdf/bulletin_annuel.php pour l'explication (même mécanisme).
$acces_public = false;
if (($_GET['vh'] ?? '') !== '' && (int) ($_GET['id'] ?? 0) > 0) {
    $val_annee_pub = get_annee_active()['val_annee'] ?? '';
    $acces_public = bulletin_verif_valider(
        (int) $_GET['id'], 'annee', (int) substr($val_annee_pub, 0, 4), 'fr', (string) $_GET['vh']
    ) !== null;
}
if (!$acces_public) exiger_acces_pedagogie();

require_once __DIR__ . '/fpdf.php';
require_once __DIR__ . '/header_pdf.php';

$id        = (int) ($_GET['id'] ?? 0);
$id_classe = (int) ($_GET['classe'] ?? 0);
$dl        = ($_GET['dl'] ?? '0') === '1';
$avec_sig  = ($_GET['signature'] ?? '0') === '1';
if (!$id && !$id_classe) die('Paramètre id (ou classe) manquant.');

$annee     = get_annee_active();
$val_annee = $annee['val_annee'] ?? '';
if (!$acces_public) {                       // cloisonnement enseignant (piste FR)
    exiger_acces_classe($id_classe, $val_annee, 'fr');
    exiger_acces_eleve($id, 'fr');
}
$id_annee  = (int) substr($val_annee, 0, 4);
$etab_brut = get_etablissement();
$etab      = etab_pour_pdf($etab_brut);
$pays_fr = $etab_brut['pays_etab_fr'] ?: 'REPUBLIQUE DU CAMEROUN';
$pays_en = $etab_brut['pays_etab_en'] ?: 'REPUBLIC OF CAMEROON';
$dept_fr = $etab['departement_fr'] ?: 'DEPARTEMENT DE LA VINA';
$arr_fr  = $etab['arrondissement_fr'] ?: 'ARRONDISSEMENT DE NGAOUNDERE I';
$div_en  = $etab['division_en'] ?: 'VINA DIVISION';
$sub_en  = $etab['subdivision_en'] ?: 'NGAOUNDERE I SUBDIVISION';

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
    $classement = classement_annuel_classe($id_classe, $val_annee);
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

function rang_prefixe_suffixe_ann_an(string $rang): array {
    if ($rang === '') return ['', ''];
    if (preg_match('/^(\d+)(.*)$/', $rang, $m)) return [$m[1], trim($m[2])];
    return [$rang, ''];
}
function pdf_case_ann_an(FPDF $pdf, bool $coche, float $x, float $y, float $taille = 3.0): void {
    $fichier = $coche ? 'case_cochee.jpg' : 'case_a_cocher.jpg';
    $pdf->Image(__DIR__ . '/../assets/img/pdf/' . $fichier, $x, $y, $taille, $taille);
}

// Nom de fonction distinct de dessiner_bulletin_annuel() (fichier FR) pour
// éviter tout risque de collision si les deux fichiers étaient un jour
// inclus dans la même requête.
function dessiner_bulletin_annuel_anglais(
    FPDF $pdf, int $id, int $id_classe, string $classe_nom, string $val_annee, int $id_annee,
    array $etab, string $pays_fr, string $pays_en, string $dept_fr, string $arr_fr, string $div_en, string $sub_en,
    int $effectif_classe, bool $avec_sig
): void {
    $eleve = eleve_preload($id, $id_classe, $val_annee);
    if (!$eleve) return;
    $statut = statut_eleve_preload($id, $id_classe, $val_annee);
    // Résolution des libellés de groupes/compétences en anglais — IDENTIQUE
    // à bulletin_annuel.php, non permutée (voir commentaire en tête de
    // fichier). $section_en sera presque toujours `true` ici (ce fichier
    // n'est appelé que pour des classes anglophones — voir
    // pages/bulletins/index.php), requête gardée pour rester correcte si
    // ce fichier était un jour appelé directement sur une classe francophone.
    static $classe_row_cache = [];
    if (!array_key_exists($id_classe, $classe_row_cache)) {
        $classe_row_cache[$id_classe] = db_one(
            "SELECT c.Niveau, n.Section FROM classe c JOIN niveau n ON n.LibelleNiveau = c.Niveau WHERE c.IDClasses=?",
            [$id_classe]
        ) ?: [];
    }
    $classe_row = $classe_row_cache[$id_classe];
    $niveau = $classe_row['Niveau'] ?? '';
    $section_en = ($classe_row['Section'] ?? 'Fr') === 'An';

    $competences = competences_classe($id_classe, $val_annee);
    $resultat    = rang_eleve_annuel($id, $id_classe, $val_annee);

    $groupes = [];
    foreach ($competences as $c) {
        $gid = (int) $c['id_groupe_comp'];
        $groupes[$gid]['libelle'] ??= $section_en
            ? (libelle_groupe_competence_en((int) $c['ordre_affichage']) ?: $c['libelle_groupe_comp'])
            : $c['libelle_groupe_comp'];
        $groupes[$gid]['competences'][] = $c;
    }

    $pdf->AddPage();
    $pw = $pdf->GetPageWidth();
    $ph = $pdf->GetPageHeight();

    $pdf->SetFillColor(255, 255, 255);
    $pdf->RoundedRect(3.5, 3.5, 202, 289, 7, 'DF');
    pdf_filigrane($pdf, $etab, $pw, $ph);
    pdf_fill($pdf, 'groupe_competence');

    // En-tête pays/région/département/arrondissement — PERMUTÉ (EN à
    // gauche, FR à droite — l'original avait FR à gauche/EN à droite).
    $he1 = ['', '', '']; $we1 = [83, 30, 83]; $al1 = ['C', 'C', 'C'];
    $d1 = [
        [pdf_u($pays_en), '', pdf_u($pays_fr)],
        [pdf_u(mb_strtoupper($etab['region_en'] ?: 'ADAMAWA REGION')), '', pdf_u(mb_strtoupper($etab['region_fr'] ?: "REGION DE L'ADAMAOUA"))],
        [pdf_u(mb_strtoupper($div_en)), '', pdf_u(mb_strtoupper($dept_fr))],
        [pdf_u(mb_strtoupper($sub_en)), '', pdf_u(mb_strtoupper($arr_fr))],
    ];
    $pdf->SetXY(8, 5);
    $pdf->SetFont('Arial', '', 8);
    $pdf->table_etab($he1, $we1, $al1, $d1);

    // Nom de l'établissement — PERMUTÉ (mêmes coordonnées, contenu échangé).
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetXY(8, 18.5);
    $pdf->table_etab([''], [83], ['C'], [[pdf_u($etab['nom_en'])]]);
    $pdf->SetXY(121, 18.5);
    $pdf->table_etab([''], [83], ['C'], [[pdf_u($etab['nom_fr'])]]);

    // B.P./Tél + email — PERMUTÉ.
    $d2 = [
        [pdf_u('P.O. BOX. ' . $etab['boite_postale'] . '   Phone: ' . $etab['telephone']), '', pdf_u('B.P. ' . $etab['boite_postale'] . '   Tél.: ' . $etab['telephone'])],
        [pdf_u($etab['email']), '', pdf_u($etab['email'])],
    ];
    $pdf->SetX(8);
    $pdf->SetFont('Arial', '', 7);
    $pdf->table_etab($he1, $we1, $al1, $d2);

    // Année scolaire / School Year — PERMUTÉ.
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Text(9, 40, pdf_u('School Year: ' . $val_annee));
    $pdf->SetFont('Arial', 'I', 8);
    $pdf->Text(9, 42.5, pdf_u('Année scolaire'));

    if (!empty($etab['logo'])) {
        $logo_path = __DIR__ . '/../assets/uploads/' . $etab['logo'];
        if (is_file($logo_path)) $pdf->Image($logo_path, 92, 7, 22, 22);
    }

    // Titre — PERMUTÉ (mesuré : " ANNUAL REPORT CARD" tient à 20pt dans la
    // pilule — 85.4mm sur 88mm disponibles jusqu'au bord droit, x=158).
    $pdf->RoundedRect(50, 30.5, 108, 11.5, 11.5, 'DF');
    $pdf->SetFont('Arial', 'B', 20);
    $pdf->Text(70, 36.5, ' ANNUAL REPORT CARD');
    $pdf->SetFont('Arial', 'I', 14);
    $pdf->Text(75, 41, pdf_u('BULLETIN ANNUEL'));

    pdf_fill($pdf, 'ligne_alternee');
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetXY(5, 44);   $pdf->Cell(18, 5.5, ' ', 1, 1, 'C', 0);
    $pdf->SetXY(23.7, 44); $pdf->Cell(18, 5.5, pdf_u($classe_nom), 1, 1, 'C', 1);
    $pdf->SetXY(42.4, 44); $pdf->Cell(18, 5.5, '', 1, 1, 'C', 0);
    $pdf->SetXY(61.1, 44); $pdf->Cell(18, 5.5, pdf_u($niveau), 1, 1, 'C', 1);
    $pdf->SetXY(79.8, 44); $pdf->Cell(21.3, 5.5, '', 1, 1, 'C', 0);
    $pdf->SetXY(101.8, 44); $pdf->Cell(12, 5.5, (string) $effectif_classe, 1, 1, 'C', 1);
    $pdf->SetXY(114.6, 44); $pdf->Cell(27, 5.5, '', 1, 1, 'C', 0);
    $pdf->SetXY(142.3, 44); $pdf->Cell(16.1, 5.5, pdf_u((string) $statut), 1, 1, 'C', 1);

    $pdf->SetXY(5, 50.1); $pdf->Cell(40, 5.5, ' ', 1, 1, 'C', 0);
    $pdf->SetXY(45.5, 50.1);
    $pdf->Cell(113, 5.5, pdf_u(' ' . mb_strtoupper($eleve['Nom_elv']) . ' ' . ($eleve['Prenom_elv'] ?? '')), 1, 1, 'L', 1);

    $pdf->SetXY(5, 56.1); $pdf->Cell(24, 5.5, ' ', 1, 1, 'C', 0);
    $pdf->SetXY(29.7, 56.1);
    $date_naiss = $eleve['Date_naiss_elv'] ? date('d/m/Y', strtotime($eleve['Date_naiss_elv'])) : '';
    $pdf->Cell(95.2, 5.5, pdf_u(' ' . $date_naiss . '  à  ' . ($eleve['Lieu_naiss_elv'] ?? '')), 1, 1, 'L', 1);
    $pdf->SetXY(125.5, 56.1); $pdf->Cell(16.1, 5.5, '', 1, 1, 'C', 0);
    $pdf->SetXY(142.3, 56.1); $pdf->Cell(16.1, 5.5, pdf_u((string) ($eleve['Sexe_elv'] ?? '')), 1, 1, 'C', 1);

    // Libellés — PERMUTÉS. "LEVEL :" et "ENROLL. :" abrégés (mesurés :
    // "LEVEL : " et "ENROLLMENT : " débordaient de leur cellule de
    // 17.4/18.9mm à 9pt — même correctif que le trimestriel anglais pour
    // "ENROLLMENT"). Le fichier FR n'a jamais traduit "Niveau" (bug
    // pré-existant, non touché là-bas) — "LEVEL" est la traduction
    // naturelle utilisée ici en position primaire.
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Text(5, 47, ' CLASS : ');
    $pdf->Text(43, 47, ' LEVEL : ');
    $pdf->Text(82.2, 47, 'ENROLL. : ');
    $pdf->Text(115.5, 47, 'REPEAT : ');
    $pdf->Text(5, 53, ' SURNAME & NAMES :');
    $pdf->Text(5, 59, ' BORN ON :');
    $pdf->Text(126, 59, ' SEX :');
    $pdf->SetFont('Arial', 'I', 8);
    $pdf->Text(5, 49, pdf_u(' Classe'));
    $pdf->Text(43, 49, pdf_u(' Niveau'));
    $pdf->Text(82.2, 49, pdf_u('Effectif'));
    $pdf->Text(115.5, 49, pdf_u('Redoublant'));
    $pdf->Text(5, 55, pdf_u(' Nom et Prénoms'));
    $pdf->Text(5, 61, pdf_u(' Né(e) le'));
    $pdf->Text(126, 61, pdf_u(' Sexe'));

    static $titulaire_cache = [];
    $cle_titulaire = $id_classe . '|' . $val_annee;
    if (!array_key_exists($cle_titulaire, $titulaire_cache)) {
        $titulaire_cache[$cle_titulaire] = db_one(
            "SELECT e.civilite_ens, e.nom_ens, e.prenom_ens FROM enseignant e, enseignat_classe d
             WHERE e.matricule_ens=d.matricule_ens AND d.IDClasses=? AND d.val_annee=?",
            [$id_classe, $val_annee]
        );
    }
    $enseignant = $titulaire_cache[$cle_titulaire];
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Text(5, 65, pdf_u(' CLASS TEACHER :  ' . trim(($enseignant['civilite_ens'] ?? '') . ' ' . ($enseignant['nom_ens'] ?? '') . ' ' . ($enseignant['prenom_ens'] ?? ''))));
    $pdf->SetFont('Arial', 'I', 8);
    $pdf->Text(5, 67.5, pdf_u('Titulaire'));

    pdf_fill($pdf, 'groupe_competence');
    $pdf->RoundedRect(167, 33, 30, 33, 0, 'DF');
    $photo_tmp = photo_eleve_fichier_temp($eleve['Photo_elv'] ?? null, $id);
    if ($photo_tmp) {
        $pdf->Image($photo_tmp, 167.7, 33.5, 28.6, 31.8);
        @unlink($photo_tmp);
    } else {
        $avatar = stripos($eleve['Sexe_elv'] ?? '', 'F') === 0 ? 'fille.png' : 'garcon.png';
        $avatar_path = __DIR__ . '/../assets/img/avatars/' . $avatar;
        if (is_file($avatar_path)) $pdf->Image($avatar_path, 167.7, 33.5, 28.6, 31.8);
    }

    // ── En-tête tableau compétences — traduit (jamais bilingue dans
    // l'original) : COMPETENCIES/AVG/GRADE/RANK. TRIM1/2/3/TOTAL gardés
    // identiques (codes courts, déjà compris dans les 2 langues, même
    // convention que UA1/UA2 du bulletin trimestriel anglais). GRADE réduit
    // à 7.0pt — vérifié avec NbLines() (l'algorithme de retour à la ligne
    // de MultiCell() lui-même, marge intérieure cMargin=1mm de chaque côté
    // incluse) : "GRADE" repassait encore sur 2 lignes à 8.5pt ET 8.0pt
    // dans la cellule de 11mm, seul 7.0pt tient sur une seule ligne.
    $pdf->SetFont('Arial', 'B', 8.5);
    pdf_fill($pdf, 'entete_bleu');
    $pdf->SetXY(5, 69);
    $pdf->Cell(80, 4, 'COMPETENCIES', 1, 1, 'C', 1);
    $pdf->SetXY(85.6, 69);  $pdf->MultiCell(22, 4, 'TRIM 1', 1, 'C', 1);
    $pdf->SetXY(108.2, 69); $pdf->MultiCell(22, 4, 'TRIM 2', 1, 'C', 1);
    $pdf->SetXY(130.8, 69); $pdf->MultiCell(22, 4, 'TRIM 3', 1, 'C', 1);
    $pdf->SetXY(153.4, 69); $pdf->MultiCell(13.5, 4, 'TOTAL', 1, 'C', 1);
    $pdf->SetXY(167.5, 69); $pdf->MultiCell(13, 4, 'AVG', 1, 'C', 1);
    $pdf->SetFont('Arial', 'B', 7.0);
    $pdf->SetXY(181.1, 69); $pdf->MultiCell(11, 4, 'GRADE', 1, 'C', 1);
    $pdf->SetFont('Arial', 'B', 8.5);
    $pdf->SetXY(192.7, 69); $pdf->MultiCell(11, 4, 'RANK', 1, 'C', 1);

    // ── Groupes de compétences — INCHANGÉ (déjà en anglais via la BD) ──
    pdf_fill($pdf, 'groupe_competence');
    $T_bareme1 = 0.0; $T_points1 = 0.0; $T_bareme2 = 0.0; $T_points2 = 0.0; $T_bareme3 = 0.0; $T_points3 = 0.0;
    $T_baremeAnn = 0.0; $T_pointsAnn = 0.0;
    $cp = 1;
    $y = $pdf->GetY() - 4;
    foreach ($groupes as $g) {
        $pdf->RoundedRect(5, $y + 4.5, 199, 3.5, 3.5, 'DF');
        $pdf->SetXY(8, $y + 4.8);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Arial', 'B', 7.5);
        $pdf->printTableHeader_border_Font([pdf_u(' COMPETENCE ' . $cp . ': ' . $g['libelle'])], [190], 0, 3, null, ['L']);
        $pdf->ln(3.8);

        foreach ($g['competences'] as $comp) {
            $id_comp = (int) $comp['id_comp'];
            $bareme  = (float) $comp['total_points'];
            $note    = note_competence_annuelle($id, $id_comp, $id_classe, $val_annee);
            $rang    = rang_eleve_competence_annuelle($id, $id_comp, $id_classe, $val_annee);
            $t1v = $note['par_trimestre'][1] ?? null;
            $t2v = $note['par_trimestre'][2] ?? null;
            $t3v = $note['par_trimestre'][3] ?? null;

            if ($t1v !== null) { $T_bareme1 += $bareme; $T_points1 += $t1v; }
            if ($t2v !== null) { $T_bareme2 += $bareme; $T_points2 += $t2v; }
            if ($t3v !== null) { $T_bareme3 += $bareme; $T_points3 += $t3v; }
            if ($note['moyenne'] !== null) { $T_baremeAnn += $bareme; $T_pointsAnn += $note['moyenne']; }

            $nom_aff = ($section_en && !empty($comp['nom_comp_en'])) ? $comp['nom_comp_en'] : $comp['nom_comp'];
            $y = $pdf->GetY();
            $pdf->SetXY(5, $y);
            $pdf->SetFont('Arial', 'B', 7.5);
            $pdf->MultiCell(80, 6, pdf_u($nom_aff . ' (' . (int) $bareme . 'Pts)'), 1, 'L', 0);

            $ligne_note = fn(?float $v): string => $v === null ? '' : (string) $v;
            $data1 = [
                [$ligne_note($t1v), '', $ligne_note($t2v), '', $ligne_note($t3v), ''],
            ];
            $w9 = [22, 0.5, 22, 0.5, 22.3, 0.7];
            $al9 = ['C', 'C', 'C', 'C', 'C', 'C'];
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->SetXY(85.6, $y - 6);
            $pdf->table_gd_for_list(['', '', '', '', '', ''], $w9, $al9, 85.6, $data1, 6);

            pdf_fill($pdf, 'ligne_alternee');
            $pdf->SetXY(153.4, $y);
            $total = ($t1v ?? 0) + ($t2v ?? 0) + ($t3v ?? 0);
            $pdf->Cell(13.5, 6, ($t1v === null && $t2v === null && $t3v === null) ? '' : (string) $total, 1, 1, 'C', 1);
            $pdf->SetXY(167.4, $y);
            $pdf->Cell(13.2, 6, $note['moyenne'] !== null ? (string) $note['moyenne'] : '', 1, 1, 'C', 1);
            $pdf->SetXY(181.2, $y);
            $pdf->Cell(11.2, 6, pdf_u($note['moyenne'] !== null ? appreciation_fr($note['moyenne'], $bareme) : ''), 1, 1, 'C', 1);
            $pdf->SetXY(193, $y);
            $pdf->Cell(11, 6, pdf_u($rang), 1, 1, 'C', 1);
        }
        pdf_fill($pdf, 'groupe_competence');
        $y = $y + 2;
        $cp++;
    }

    // ── SUBJECTS SUMMARY / STUDENT RESULTS — PERMUTÉ ──────────────
    pdf_fill($pdf, 'entete_bleu');
    $pos1 = $pdf->GetY() + 10;
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->RoundedRect(5, $pos1 - 9.4, 61, 6, 6, 'DF');
    $pdf->RoundedRect(66.6, $pos1 - 9.4, 137.4, 6, 6, 'DF');
    $pdf->Text(10, $pos1 - 6.3, 'SUBJECTS SUMMARY');
    $pdf->Text(113, $pos1 - 6.3, 'STUDENT RESULTS');
    $pdf->SetFont('Arial', 'I', 9);
    $pdf->Text(22, $pos1 - 4, pdf_u('Récap. disciplines'));
    $pdf->Text(118, $pos1 - 4, pdf_u("Résultats de l'élève"));

    $id_trims = trimestres_de_annee($val_annee);
    $r_trim = [];
    foreach ([0, 1, 2] as $i) {
        $it = $id_trims[$i] ?? 0;
        $r_trim[$i] = $it ? rang_eleve_trimestre($id, $id_classe, $it, $val_annee) : ['moyenne' => null, 'rang' => '', 'effectif' => 0, 'moy_classe' => null, 'moy_premier' => null, 'moy_dernier' => null, 'taux_reussite' => null];
    }
    $jours_trim = [];
    foreach ([0, 1, 2] as $i) {
        $it = $id_trims[$i] ?? 0;
        $jours_trim[$i] = $it ? jours_absence_non_justifiees_trimestre($id, $id_classe, $it, $val_annee) : 0.0;
    }
    $jours_ann = array_sum($jours_trim);

    $row_h = 6.6;
    $tbl_y0 = $pos1 - 2;
    $case_y = fn(int $i): float => $tbl_y0 + $i * $row_h + ($row_h - 3.0) / 2;

    foreach ([0, 1, 2] as $i) {
        $x = 36.5 + $i * 8;
        $mt = mention_travail($r_trim[$i]['moyenne'], $jours_trim[$i]);
        pdf_case_ann_an($pdf, $r_trim[$i]['moyenne'] !== null && $mt['blame_conduite'], $x, $case_y(2));
        pdf_case_ann_an($pdf, $r_trim[$i]['moyenne'] !== null && $mt['blame'], $x, $case_y(3));
        pdf_case_ann_an($pdf, $r_trim[$i]['moyenne'] !== null && $mt['tableau_honneur'], $x, $case_y(4));
        pdf_case_ann_an($pdf, $r_trim[$i]['moyenne'] !== null && $mt['encouragement'], $x, $case_y(5));
        pdf_case_ann_an($pdf, $r_trim[$i]['moyenne'] !== null && $mt['felicitations'], $x, $case_y(6));
    }
    $m_ann = mention_travail($resultat['moyenne'], $jours_ann);
    pdf_case_ann_an($pdf, $resultat['moyenne'] !== null && $m_ann['blame_conduite'], 60.7, $case_y(2));
    pdf_case_ann_an($pdf, $resultat['moyenne'] !== null && $m_ann['blame'], 60.7, $case_y(3));
    pdf_case_ann_an($pdf, $resultat['moyenne'] !== null && $m_ann['tableau_honneur'], 60.7, $case_y(4));
    pdf_case_ann_an($pdf, $resultat['moyenne'] !== null && $m_ann['encouragement'], 60.7, $case_y(5));
    pdf_case_ann_an($pdf, $resultat['moyenne'] !== null && $m_ann['felicitations'], 60.7, $case_y(6));

    $pdf->SetFont('Arial', 'B', 7);
    // 'AN' (abréviation FR de "Année") → 'YR' (même longueur, aucun risque
    // de débordement — traduction, pas une simple permutation ici puisque
    // l'original n'avait pas de pendant anglais pour cette ligne).
    $pdf->Text(34, $tbl_y0 + $row_h - 0.7, 'TRIM1 TRIM2 TRIM3    YR');
    $pdf->SetFont('Arial', 'B', 6.2);
    $header2 = ['', '', '', '', ''];
    $w2 = [29, 8, 8, 8, 8];
    $al2 = ['L', 'C', 'C', 'C', 'C'];
    // Libellés colonne 0 — PERMUTÉS (anglais gras en haut de ligne, français
    // italique dessous — l'original avait FR en haut).
    $lblA    = ['Absences (in days)', 'Conduct reprimand', 'Work reprimand', 'Honour roll', 'Encouragement', 'Congratulations'];
    $lblA_en = ['Absences (en J)', 'Blâme conduite', 'Blâme travail', "Tableau d'Honneur", 'Encouragements', 'Félicitations'];
    $datas2 = [
        ['', '', '', '', ''],
        ['', (string) (int) $jours_trim[0], (string) (int) $jours_trim[1], (string) (int) $jours_trim[2], (string) (int) $jours_ann],
        ['', '', '', '', ''],
        ['', '', '', '', ''],
        ['', '', '', '', ''],
        ['', '', '', '', ''],
        ['', '', '', '', ''],
    ];
    $pdf->SetXY(5, $tbl_y0);
    $pdf->SetFont('Arial', 'B', 7.8);
    $pdf->table_gd($header2, $w2, $al2, $datas2, $row_h);

    $fmt = fn(?float $v): string => $v === null ? '' : sprintf('%05.2f', $v);
    $header3 = ['', '', '', '', ''];
    $w3 = [23.8, 16.8, 16.8, 16.8, 16.8];
    $al3 = ['L', 'C', 'C', 'C', 'C'];
    // Libellés colonne 0 — PERMUTÉS. "Top/Last student's average" débordent
    // de la colonne de 22.8mm à 7.5pt (mesuré, même défaut que le
    // trimestriel anglais) : abrégés en "Top/Last average", même correctif.
    $lblB    = ['Total points', 'Average', 'Rank', 'Class average', 'Top average', 'Last average'];
    $lblB_en = ['Total des Points', 'Moyenne', 'Rang', 'Moy Gén Classe', 'Moy du 1er', 'Moy dernier'];
    $datas3 = [
        ['', '', '', '', ''],
        ['', $T_points1 > 0 ? (string) $T_points1 : '', $T_points2 > 0 ? (string) $T_points2 : '', $T_points3 > 0 ? (string) $T_points3 : '', $T_pointsAnn > 0 ? (string) $T_pointsAnn : ''],
        ['', $fmt($r_trim[0]['moyenne']), $fmt($r_trim[1]['moyenne']), $fmt($r_trim[2]['moyenne']), $fmt($resultat['moyenne'])],
        ['', pdf_u(($r_trim[0]['rang'] ?: '') . ($r_trim[0]['rang'] ? ' /' . $r_trim[0]['effectif'] : '')), pdf_u(($r_trim[1]['rang'] ?: '') . ($r_trim[1]['rang'] ? ' /' . $r_trim[1]['effectif'] : '')), pdf_u(($r_trim[2]['rang'] ?: '') . ($r_trim[2]['rang'] ? ' /' . $r_trim[2]['effectif'] : '')), pdf_u(($resultat['rang'] ?: '') . ($resultat['rang'] ? ' /' . $resultat['effectif'] : ''))],
        ['', $fmt($r_trim[0]['moy_classe']), $fmt($r_trim[1]['moy_classe']), $fmt($r_trim[2]['moy_classe']), $fmt($resultat['moy_classe'])],
        ['', $fmt($r_trim[0]['moy_premier']), $fmt($r_trim[1]['moy_premier']), $fmt($r_trim[2]['moy_premier']), $fmt($resultat['moy_premier'])],
        ['', $fmt($r_trim[0]['moy_dernier']), $fmt($r_trim[1]['moy_dernier']), $fmt($r_trim[2]['moy_dernier']), $fmt($resultat['moy_dernier'])],
    ];
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Text(91, $tbl_y0 + $row_h - 0.7, 'TRIM1' . ' /' . (int) $T_bareme1);
    $pdf->Text(108, $tbl_y0 + $row_h - 0.7, 'TRIM2' . ' /' . (int) $T_bareme2);
    $pdf->Text(125, $tbl_y0 + $row_h - 0.7, 'TRIM3' . ' /' . (int) $T_bareme3);
    $pdf->Text(142, $tbl_y0 + $row_h - 0.7, 'YR.' . ' /' . (int) $T_baremeAnn);

    $pdf->SetXY(66.6, $tbl_y0);
    $pdf->SetFont('Arial', 'B', 7.8);
    $pdf->table_gd($header3, $w3, $al3, $datas3, $row_h);

    for ($i = 1; $i <= 6; $i++) {
        $rowY = $tbl_y0 + $i * $row_h;
        foreach ([[5, $lblA, $lblA_en], [66.6, $lblB, $lblB_en]] as [$colX, $fr, $en]) {
            $pdf->SetFont('Arial', 'B', 7.5);
            $pdf->Text($colX + 1, $rowY + 2.3, pdf_u($fr[$i - 1]));
            $pdf->SetFont('Arial', 'I', 5.8);
            $pdf->Text($colX + 1, $rowY + 5.2, pdf_u($en[$i - 1]));
        }
    }

    // ── Bloc ANNUAL AVERAGE/RANK/APPRECIATION — PERMUTÉ ───────────
    $moy_gen = $resultat['moyenne'];
    $H_ANN = (7 * $row_h - 5 * 0.6) / 6;
    $rtA1 = $pos1 - 1;
    $rtA2 = $rtA1 + $H_ANN + 0.6;
    $rtA3 = $rtA2 + $H_ANN + 0.6;
    $rtA4 = $rtA3 + $H_ANN + 0.6;
    $rtA5 = $rtA4 + $H_ANN + 0.6;
    $rtA6 = $rtA5 + $H_ANN + 0.6;
    $frCentre = function (string $txtFr, float $rt) use ($pdf, $H_ANN): void {
        $pdf->SetFont('Arial', 'I', 7);
        $txt = pdf_u($txtFr);
        $pdf->Text(158.1 + (46 - $pdf->GetStringWidth($txt)) / 2, $rt + $H_ANN - 1, $txt);
    };

    $pdf->SetXY(158.1, $rtA1);
    pdf_fill($pdf, 'cellule_resultat');
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(46, $H_ANN, 'ANNUAL AVERAGE', 1, 1, 'C', 1);
    $frCentre('Moyenne annuelle', $rtA1);

    pdf_fill($pdf, 'entete_bleu');
    $pdf->SetXY(158.1, $rtA2);
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(46, $H_ANN, ($moy_gen !== null ? sprintf('%05.2f', $moy_gen) : pdf_u('—')) . ' / 20', 1, 1, 'C', 1);

    $pdf->SetXY(158.1, $rtA3);
    pdf_fill($pdf, 'cellule_resultat');
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(46, $H_ANN, 'RANK', 1, 1, 'C', 1);
    $frCentre('Rang', $rtA3);

    pdf_fill($pdf, 'entete_bleu');
    $pdf->SetXY(158.1, $rtA4);
    $pdf->Cell(46, $H_ANN, ' ', 1, 1, 'C', 1);

    $pdf->SetXY(158.1, $rtA5);
    pdf_fill($pdf, 'cellule_resultat');
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(46, $H_ANN, 'APPRECIATION', 1, 1, 'C', 1);
    $frCentre('Appréciation', $rtA5);

    pdf_fill($pdf, 'entete_bleu');
    $pdf->SetXY(158.1, $rtA6);
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(46, $H_ANN, pdf_u(appreciation_moyenne($moy_gen)), 1, 1, 'C', 1);

    [$rp, $rs] = rang_prefixe_suffixe_ann_an((string) ($resultat['rang'] ?? ''));
    $suffixeRangAnn = ' / ' . $resultat['effectif'];
    $pdf->SetFont('Arial', 'B', 14);
    $wRangAnn = $pdf->GetStringWidth($rp) + $pdf->GetStringWidth($suffixeRangAnn);
    if ($rs !== '') {
        $pdf->SetFontSize(12);
        $wRangAnn += $pdf->GetStringWidth($rs);
        $pdf->SetFontSize(14);
    }
    $pdf->SetXY(158.1 + (46 - $wRangAnn) / 2, $rtA4 + $H_ANN / 2);
    $pdf->Write(0.5, $rp);
    if ($rs !== '') $pdf->subWrite(1, $rs, '', 12, 6);
    $pdf->Write(0.5, $suffixeRangAnn);

    // ── Décision de fin d'année + observations — PERMUTÉ ──────────
    pdf_fill($pdf, 'groupe_competence');
    $bas_recap = max($tbl_y0 + 7 * $row_h, $pdf->GetY());
    $pdf->SetY($bas_recap + 4);
    $pos2 = $pdf->GetY();
    $pdf->RoundedRect(5, $pos2, 199, 6, 6, 'DF');
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Text(23, $pos2 + 2.8, "END OF YEAR CLASS ADVICE DECISION");
    $pdf->Text(120, $pos2 + 2.8, "PRINCIPAL'S REMARKS");
    $pdf->SetFont('Arial', 'I', 8);
    $pdf->Text(30, $pos2 + 5.2, pdf_u("Décision des conseils de fin d'année"));
    $pdf->Text(145, $pos2 + 5.2, pdf_u("Observations du chef d'établissement"));

    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Text(17, $pos2 + 13, 'PROMOTED TO ');
    $pdf->Image(__DIR__ . '/../assets/img/pdf/case_a_cocher.jpg', 11, $pos2 + 9.7, 4, 4);
    $pdf->Text(17, $pos2 + 20, 'REPEAT CLASS ');
    $pdf->Image(__DIR__ . '/../assets/img/pdf/case_a_cocher.jpg', 11, $pos2 + 16.7, 4, 4);
    $pdf->SetFont('Arial', 'I', 9);
    $pdf->Text(42, $pos2 + 13, pdf_u(' / Promu(e) en : ..............................'));
    $pdf->Text(43, $pos2 + 20, pdf_u(' / Redouble la  : ..............................'));

    $pdf->Line(107.2, $pdf->GetY(), 107.2, 289);

    $pdf->RoundedRect(5, $pos2 + 32, 101, 6, 6, 'DF');
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Text(16, $pos2 + 34.7, "TEACHER'S REMARKS AND SIGNATURE");
    $pdf->SetFont('Arial', 'I', 9);
    $pdf->Text(30, $pos2 + 37.3, pdf_u(" Observations et signature de l'enseignant"));

    // Lieu/date — PERMUTÉ ("on"/"le" — même position dynamique dépendant de
    // la longueur de $lieu, les 2 mots faisant la même taille).
    $pdf->SetFont('Arial', 'B', 8);
    $lieu = $etab['lieu'] ?: $etab['ville'];
    $pdf->Text(150, $pos2 + 11, pdf_u($lieu . ', on  ' . date('d-m-Y') . '.'));
    $pdf->SetFont('Arial', 'I', 7);
    $pdf->Text(150 + 1.7 * mb_strlen($lieu), $pos2 + 14, pdf_u('le'));
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Text(148, $pos2 + 21, pdf_u($etab['chef_etablissement_en'] ?: 'THE DIRECTOR'));
    $pdf->SetFont('Arial', 'I', 9);
    $pdf->Text(148, $pos2 + 24, pdf_u($etab['chef_etablissement'] ?: 'LE DIRECTEUR'));

    if ($avec_sig) {
        // Même clé 'bulletin_annuel' que le fichier FR (mise en page
        // strictement identique) — pas de configuration séparée à créer.
        pdf_signature_appliquer_jn($pdf, 'bulletin_annuel', 148, $pos2 + 21, 46, 10, [
            'x_pct' => 0, 'y_pct' => 30, 'w_pct' => 80, 'h_pct' => null,
        ]);
    }

    // Piste 'fr' inchangée (voir commentaire en tête de fichier) ;
    // verif_bulletin.php choisit le bon fichier de réouverture selon la
    // section du niveau de l'élève.
    $qr_tmp = bulletin_qr_fichier_temp($eleve, 'annee', $id_annee);
    if ($qr_tmp) {
        $pdf->Image($qr_tmp, 99, $ph - 28, 16, 16, 'PNG');
    }

    pdf_copyright($pdf, $pw, $ph);

    $pdf->SetTextColor(0, 0, 0);
}

precharger_notes_sequence_classe((int) $liste[0]['id_classe'], $val_annee);
precharger_evaluations_annulees_annee($val_annee);
precharger_eleves_classe((int) $liste[0]['id_classe'], $val_annee);
precharger_statuts_classe((int) $liste[0]['id_classe'], $val_annee);
foreach (trimestres_de_annee($val_annee) as $__id_trim) {
    precharger_absences_classe_trim((int) $liste[0]['id_classe'], $__id_trim, $val_annee);
}

try {
$pdf = new FPDF('P', 'mm', 'A4');
$pdf->SetAutoPageBreak(false, 0);

foreach ($liste as $e) {
    dessiner_bulletin_annuel_anglais(
        $pdf, $e['id_eleve'], $e['id_classe'], $e['classe_nom'], $val_annee, $id_annee,
        $etab, $pays_fr, $pays_en, $dept_fr, $arr_fr, $div_en, $sub_en, $effectif_classe, $avec_sig
    );
}

$nom_fichier = $id ? ('bulletin_annuel_' . $liste[0]['id_eleve'] . '.pdf') : ('bulletins_annuels_' . $liste[0]['classe_nom'] . '.pdf');
$pdf->Output($dl ? 'D' : 'I', $nom_fichier);
} catch (Throwable $e) {
    pdf_erreur_generation($e);
}
