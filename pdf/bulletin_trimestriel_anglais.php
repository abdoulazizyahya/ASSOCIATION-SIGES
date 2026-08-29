<?php
// ── PDF : Bulletin trimestriel — piste française, AFFICHAGE ANGLAIS ──
// Fichier séparé de pdf/bulletin_trimestriel.php (demande explicite du
// 26/08/2026 : ne jamais toucher ce dernier, déjà validé) — utilisé quand
// la classe appartient à un niveau de section anglophone (niveau.Section=
// 'An'). Structure, coordonnées, polices, tailles, couleurs STRICTEMENT
// identiques au bulletin français — bul_trim.pdf reste la référence de
// mise en page pour les DEUX fichiers. La seule différence : chaque paire
// de libellés bilingues (ex. « CLASSE / Class ») est PERMUTÉE — l'anglais
// occupe la position/le style qu'occupait le français (gras, en premier),
// et le français occupe la position/le style qu'occupait l'anglais
// (italique, en second) — jamais de changement de taille de police, de
// police, de style ou de position/coordonnée.
//
// EXCEPTION explicite (demande utilisateur) : les groupes de compétences
// et les compétences elles-mêmes NE SONT PAS permutés ici — ils viennent
// déjà correctement de la base (jeu langue='An', voir notes_apc.php::
// competences_classe() et fonctions.php::libelle_groupe_competence_en())
// et s'affichent déjà en anglais pour une classe de cette section, sans
// paire bilingue à côté (voir bulletin_trimestriel.php, $section_en).
// Cette logique-là reste identique au fichier français, non dupliquée ici
// par erreur — copiée telle quelle.
//
// Toujours sur la piste de données 'fr' (bulletin_verif_valider(..., 'fr',
// ...), QR, discipline/composer_sequence) : « anglais » est ici une
// préférence d'AFFICHAGE, pas une piste de données distincte comme l'arabe
// — mêmes notes, même moteur de calcul (notes_apc.php), seul le papier
// change de langue.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
require_once __DIR__ . '/../notes_apc.php';
require_once __DIR__ . '/verif_lib.php';

// Accès public via le QR code du bulletin (jeton "vh") — voir
// pdf/bulletin_annuel.php pour l'explication (même mécanisme).
$acces_public = false;
if (($_GET['vh'] ?? '') !== '' && (int) ($_GET['id'] ?? 0) > 0 && (int) ($_GET['trim'] ?? 0) > 0) {
    $acces_public = bulletin_verif_valider(
        (int) $_GET['id'], 'trim', (int) $_GET['trim'], 'fr', (string) $_GET['vh']
    ) !== null;
}
if (!$acces_public) exiger_acces_pedagogie();

require_once __DIR__ . '/fpdf.php';
require_once __DIR__ . '/header_pdf.php';

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
// Champs legacy non repris par etab_pour_pdf() (générique, utilisé par
// d'autres documents) — accès direct au besoin, avec le même repli que le
// reste de l'en-tête si la fiche établissement n'a pas encore été complétée.
$pays_fr = $etab_brut['pays_etab_fr'] ?: 'REPUBLIQUE DU CAMEROUN';
$pays_en = $etab_brut['pays_etab_en'] ?: 'REPUBLIC OF CAMEROON';
$dept_fr = $etab['departement_fr'] ?: 'DEPARTEMENT DE LA VINA';
$arr_fr  = $etab['arrondissement_fr'] ?: 'ARRONDISSEMENT DE NGAOUNDERE I';
$div_en  = $etab['division_en'] ?: 'VINA DIVISION';
$sub_en  = $etab['subdivision_en'] ?: 'NGAOUNDERE I SUBDIVISION';

// ── Libellés UA1/UA2 selon le trimestre (port des libellés fixes legacy —
// UA1/UA2 pour T1, UA3/UA4 pour T2, UA5/UA6 pour T3) + libellé anglais du
// trimestre (jamais stocké en base, seul le libellé FR l'est). UA1/UA2...
// restent des codes courts identiques dans les deux langues (pas de
// libellé anglais distinct établi ailleurs dans le projet pour "UA").
[$note_1, $note_2] = match ($id_trim) {
    2 => ['UA3', 'UA4'],
    3 => ['UA5', 'UA6'],
    default => ['UA1', 'UA2'],
};
$trim_en = match ($id_trim) { 2 => '2nd Trimester', 3 => '3rd Trimester', default => '1st Trimester' };

// ── Liste des élèves à imprimer ──────────────────────────────────
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
    $effectif_classe = (int) db_val(
        "SELECT COUNT(*) FROM inscrire WHERE IDClasses=? AND val_annee=?",
        [(int) $insc['IDClasses'], $val_annee]
    );
} else {
    $classe = db_one("SELECT DesignationClasses FROM classe WHERE IDClasses=?", [$id_classe]);
    if (!$classe) die('Classe introuvable.');
    $classement = classement_trimestre_classe($id_classe, $id_trim, $val_annee);
    // Ordre d'impression du lot : "merite" (tri natif du classement, par
    // moyenne décroissante) ou "alpha" (nom/prénom) — même convention que
    // bulletin_trimestriel.php.
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

// Sépare un rang formaté ("5e", "1e ex") en préfixe numérique + suffixe
// (pour l'affichage "5" + exposant "e", comme l'original via subWrite()).
function rang_prefixe_suffixe_an(string $rang): array {
    if ($rang === '') return ['', ''];
    if (preg_match('/^(\d+)(.*)$/', $rang, $m)) return [$m[1], trim($m[2])];
    return [$rang, ''];
}

// Coche/case vide (mêmes icônes que jaynitaare — voir assets/img/pdf/).
function pdf_case_an(FPDF $pdf, bool $coche, float $x, float $y, float $taille = 3.0): void {
    $fichier = $coche ? 'case_cochee.jpg' : 'case_a_cocher.jpg';
    $pdf->Image(__DIR__ . '/../assets/img/pdf/' . $fichier, $x, $y, $taille, $taille);
}

// ── Dessine le bulletin d'UN élève dans le $pdf déjà ouvert ──────
// Nom de fonction distinct de dessiner_bulletin_trimestriel() (fichier FR)
// pour éviter tout risque de collision si les deux fichiers étaient un
// jour inclus dans la même requête.
function dessiner_bulletin_trimestriel_anglais(
    FPDF $pdf, int $id, int $id_classe, string $classe_nom, int $id_trim, string $val_annee,
    array $etab, string $pays_fr, string $pays_en, string $dept_fr, string $arr_fr, string $div_en, string $sub_en,
    array $trimestre, string $note_1, string $note_2, string $trim_en, int $effectif_classe, bool $avec_sig
): void {
    // eleve_preload()/statut_eleve_preload() consultent le préchargement de
    // classe (precharger_eleves_classe()/precharger_statuts_classe(), voir
    // l'appel avant la boucle d'impression) au lieu de requêter à chaque
    // élève — même optimisation que bulletin_trimestriel.php.
    $eleve = eleve_preload($id, $id_classe, $val_annee);
    if (!$eleve) return;
    $statut = statut_eleve_preload($id, $id_classe, $val_annee);

    // Résolution des libellés de groupes/compétences en anglais (jeu
    // langue='An', déjà apparié par ordre_affichage/code_comp via
    // competences_classe()) — IDENTIQUE à bulletin_trimestriel.php, non
    // permutée (demande explicite : cette partie vient de la BD, déjà en
    // anglais pour une classe de cette section). $section_en sera presque
    // toujours `true` ici (ce fichier n'est appelé que pour des classes
    // anglophones — voir pages/bulletins/index.php), mais on garde la
    // vraie requête (pas juste `true` en dur) pour rester correct si ce
    // fichier était un jour appelé directement sur une classe francophone.
    static $section_en_cache = [];
    if (!array_key_exists($id_classe, $section_en_cache)) {
        $section_en_cache[$id_classe] = db_val(
            "SELECT n.Section FROM classe c JOIN niveau n ON n.LibelleNiveau = c.Niveau WHERE c.IDClasses=?",
            [$id_classe]
        ) === 'An';
    }
    $section_en = $section_en_cache[$id_classe];

    $competences = competences_classe($id_classe, $val_annee);
    $seqs        = sequences_du_trimestre($id_trim);
    $id_seq1     = $seqs[0] ?? 0;
    $id_seq2     = $seqs[1] ?? 0;
    $resultat    = rang_eleve_trimestre($id, $id_classe, $id_trim, $val_annee);

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

    // ── Cadre + filigrane + logo ──────────────────────────────────
    $pdf->SetFillColor(255, 255, 255);
    $pdf->RoundedRect(3.5, 3.5, 202, 289, 7, 'DF');
    pdf_filigrane($pdf, $etab, $pw, $ph);
    if (!empty($etab['logo'])) {
        $logo_path = __DIR__ . '/../assets/uploads/' . $etab['logo'];
        if (is_file($logo_path)) $pdf->Image($logo_path, 90, 7, 25, 25);
    }
    pdf_fill($pdf, 'groupe_competence');

    // ── En-tête FR/EN (4 lignes pays/région/département/arrondissement) ──
    // PERMUTÉ : anglais à gauche, français à droite (l'original avait FR à
    // gauche/EN à droite) — même 3 colonnes, mêmes largeurs, même police.
    $he1 = ['', '', '']; $we1 = [83, 30, 83]; $al1 = ['C', 'C', 'C'];
    $d1 = [
        [pdf_u($pays_en), '', pdf_u($pays_fr)],
        [pdf_u(mb_strtoupper($etab['region_en'] ?: 'ADAMAWA REGION')), '', pdf_u(mb_strtoupper($etab['region_fr'] ?: "REGION DE L'ADAMAOUA"))],
        [pdf_u(mb_strtoupper($div_en)), '', pdf_u(mb_strtoupper($dept_fr))],
        [pdf_u(mb_strtoupper($sub_en)), '', pdf_u(mb_strtoupper($arr_fr))],
    ];
    $pdf->SetXY(8, 6);
    $pdf->SetFont('Arial', '', 8);
    $pdf->table_etab($he1, $we1, $al1, $d1);

    // Nom de l'établissement — PERMUTÉ (EN à x=8/y=21, FR à x=121/y=22,
    // mêmes coordonnées/police que l'original, contenu échangé).
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetXY(8, 21);
    $pdf->table_etab([''], [83], ['C'], [[pdf_u($etab['nom_en'])]]);
    $pdf->SetXY(121, 22);
    $pdf->table_etab([''], [83], ['C'], [[pdf_u($etab['nom_fr'])]]);

    // B.P./Tél + email — PERMUTÉ (mêmes 3 colonnes que $d1 ci-dessus).
    $d2 = [
        [pdf_u('P.O. BOX. ' . $etab['boite_postale'] . '   Phone: ' . $etab['telephone']), '', pdf_u('B.P. ' . $etab['boite_postale'] . '   Tél.: ' . $etab['telephone'])],
        [pdf_u($etab['email']), '', pdf_u($etab['email'])],
    ];
    $pdf->SetX(8);
    $pdf->SetFont('Arial', '', 8);
    $pdf->table_etab($he1, $we1, $al1, $d2);

    // Année scolaire / School Year — PERMUTÉ (même position, même police).
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Text(9, 40, pdf_u('School Year: ' . $val_annee));
    $pdf->SetFont('Arial', 'I', 8);
    $pdf->Text(9, 42.5, pdf_u('Année scolaire'));

    // ── Bandeau titre (pilule arrondie) — PERMUTÉ ─────────────────
    // "REPORT CARD" est bien plus court que "BULLETIN DE NOTES" : le
    // libellé secondaire (FR) est repositionné juste après la fin du
    // libellé primaire (EN) — demande explicite du 26/08/2026 ("ramener
    // juste à côté de la version anglaise") — au lieu de rester sur les
    // coordonnées fixes du fichier français (calibrées pour le texte
    // français plus long en position primaire), ce qui le faisait déborder
    // de la pilule (108mm de large, jusqu'à x=158).
    $pdf->RoundedRect(50, 33, 108, 10, 10, 'DF');
    $pdf->SetFont('Arial', 'B', 14);
    $titreEn = ' REPORT CARD';
    $pdf->Text(56, 37.5, $titreEn);
    $xTitreFr = 56 + $pdf->GetStringWidth($titreEn) + 3;
    $pdf->SetFont('Arial', 'I', 14);
    $pdf->Text($xTitreFr, 37.5, pdf_u('- BULLETIN DE NOTES'));
    $pdf->SetFont('Arial', 'BI', 14);
    $trimEnTxt = $trim_en;
    $pdf->Text(73, 42, pdf_u($trimEnTxt));
    $xTrimFr = 73 + $pdf->GetStringWidth($trimEnTxt) + 3;
    $pdf->SetFont('Arial', 'I', 14);
    $pdf->Text($xTrimFr, 42, pdf_u('- ' . $trimestre['libelle_trim']));

    // ── Grille CLASSE/MATRICULE/EFFECTIF/REDOUBLANT ───────────────
    pdf_fill($pdf, 'ligne_alternee');
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetXY(5, 44);   $pdf->Cell(18, 5.5, ' ', 1, 1, 'C', 0);
    $pdf->SetXY(23.7, 44); $pdf->Cell(18, 5.5, pdf_u($classe_nom), 1, 1, 'C', 1);
    // Cellule NIU (ex-MATRICULE) : mêmes largeurs que le fichier FR.
    $pdf->SetXY(42.4, 44); $pdf->Cell(10, 5.5, '', 1, 1, 'C', 0);
    $pdf->SetXY(53.1, 44); $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell(26, 5.5, pdf_u((string) ($eleve['niu'] ?? '')), 1, 1, 'C', 1);
    $pdf->SetFont('Arial', 'B', 9);
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

    // Libellés — PERMUTÉS (anglais en gras/position primaire, français en
    // italique/position secondaire, mêmes coordonnées que l'original).
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Text(5, 47, ' CLASS : ');
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Text(43, 47, 'ID');
    $pdf->SetFont('Arial', 'B', 9);
    // "ENROLLMENT : " débordait de la cellule (18.9mm dispo entre x=82.2 et
    // la fin réelle de la cellule à x=101.1) — abrégé, demande du 26/08/2026.
    $pdf->Text(82.2, 47, 'ENROLL. : ');
    $pdf->Text(115.5, 47, 'REPEAT : ');
    // "SURNAME AND GIVEN NAMES :" déborde de l'espace disponible (~40mm
    // avant la cellule du nom) — abrégé en gardant le même sens et la même
    // taille de police (demande du 26/08/2026).
    $pdf->Text(5, 53, ' SURNAME & NAMES :');
    $pdf->Text(5, 59, ' BORN ON :');
    $pdf->Text(126, 59, ' SEX :');

    $pdf->SetFont('Arial', 'I', 8);
    $pdf->Text(5, 49, pdf_u(' Classe'));
    $pdf->Text(43, 49, pdf_u(' NIU'));
    $pdf->Text(82.2, 49, pdf_u('Effectif'));
    $pdf->Text(115.5, 49, pdf_u('Redoublant'));
    $pdf->Text(5, 55, pdf_u(' Nom et Prénoms'));
    $pdf->Text(5, 61, pdf_u(' Né(e) le'));
    $pdf->Text(126, 61, pdf_u(' Sexe'));

    // ── Titulaire — PERMUTÉ ───────────────────────────────────────
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

    // ── Photo ────────────────────────────────────────────────────
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

    // ── En-tête tableau compétences — PERMUTÉ (COMPETENCIES/AVG/GRADE) ──
    $pdf->SetFont('Arial', 'B', 8.5);
    pdf_fill($pdf, 'ligne_alternee');
    $pdf->SetXY(5, 68);
    $pdf->Cell(82, 8, 'COMPETENCIES', 1, 1, 'C', 1);
    $pdf->SetXY(87, 68);  $pdf->MultiCell(44, 3.5, pdf_u($note_1), 1, 'C', 1);
    $pdf->SetXY(131, 68); $pdf->MultiCell(44, 3.5, pdf_u($note_2), 1, 'C', 1);
    $pdf->SetXY(175, 68); $pdf->MultiCell(15, 8, 'AVG', 1, 'C', 1);
    $pdf->SetXY(190, 68); $pdf->MultiCell(14, 8, 'GRADE', 1, 'C', 1);

    $w8 = [11, 11, 11, 11, 11, 11, 11, 11];
    $header8 = ['Oral', 'Written', 'Practical', 'Behavior', 'Oral', 'Written', 'Practical', 'Behavior'];
    // 'Practical'/'Behavior' débordent des colonnes de 11mm à 7.5pt (taille
    // du fichier FR, calibrée pour les mots français plus courts) — réduit
    // à 6.8pt pour cette ligne uniquement, demande du 26/08/2026.
    $pdf->SetFont('Arial', 'B', 6.8);
    $pdf->SetFillColor(255, 255, 255);
    $pdf->SetXY(87, 71.5);
    $pdf->printTableHeader_border_Font($header8, $w8, 1, 4.5, null, ['C', 'C', 'C', 'C', 'C', 'C', 'C', 'C']);

    // ── Groupes de compétences — INCHANGÉ (déjà en anglais via la BD, voir
    // le commentaire en tête de fonction) ─────────────────────────
    pdf_fill($pdf, 'groupe_competence');
    $T_bareme = 0.0; $T_points = 0.0;
    $T_bareme_seq1 = 0.0; $T_points_seq1 = 0.0;
    $T_bareme_seq2 = 0.0; $T_points_seq2 = 0.0;
    $cp = 1;
    $y = $pdf->GetY() + 0.5;
    foreach ($groupes as $g) {
        $pdf->RoundedRect(5, $y + 4.5, 199, 3.5, 3.5, 'DF');
        $pdf->SetXY(8, $y + 4.9);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Arial', 'B', 7.5);
        $pdf->printTableHeader_border_Font([pdf_u(' COMPETENCE ' . $cp . ': ' . $g['libelle'])], [190], 0, 3, null, ['L']);
        $pdf->ln(3.6);

        foreach ($g['competences'] as $comp) {
            $id_comp = (int) $comp['id_comp'];
            $bareme  = (float) $comp['total_points'];
            $note    = note_competence_trimestre($id, $id_comp, $id_classe, $id_trim, $val_annee);
            $moy     = $note['moyenne'];
            $d1n     = $id_seq1 ? note_detail_competence_sequence($id, $id_comp, $id_classe, $id_seq1, $val_annee) : null;
            $d2n     = $id_seq2 ? note_detail_competence_sequence($id, $id_comp, $id_classe, $id_seq2, $val_annee) : null;

            if ($moy !== null) { $T_bareme += $bareme; $T_points += $moy; }
            if ($d1n && $d1n['total_points'] !== null) { $T_bareme_seq1 += $bareme; $T_points_seq1 += $d1n['total_points']; }
            if ($d2n && $d2n['total_points'] !== null) { $T_bareme_seq2 += $bareme; $T_points_seq2 += $d2n['total_points']; }

            $y = $pdf->GetY();
            $nom_aff = ($section_en && !empty($comp['nom_comp_en'])) ? $comp['nom_comp_en'] : $comp['nom_comp'];
            $pdf->SetXY(5, $y);
            $pdf->SetFont('Arial', 'B', 8);
            $pdf->MultiCell(82, 8, pdf_u($nom_aff . ' (' . (int) $bareme . 'Pts)'), 1, 'L', 0);

            $ss = fn(?array $d, string $champ) => ($d !== null && (float) $comp[$champ] != 0 && $d[$champ] !== null) ? rtrim(rtrim(number_format($d[$champ], 2), '0'), '.') : '';
            $data1 = [[
                $ss($d1n, 'orale'), $ss($d1n, 'ecrite'), $ss($d1n, 'pratique'), $ss($d1n, 'savoir_etre'),
                $ss($d2n, 'orale'), $ss($d2n, 'ecrite'), $ss($d2n, 'pratique'), $ss($d2n, 'savoir_etre'),
            ]];
            $pdf->SetFont('Arial', 'B', 10);
            $pdf->SetXY(87, $y - 8);
            $pdf->table_gd_for_list(['', '', '', '', '', '', '', ''], $w8, ['C', 'C', 'C', 'C', 'C', 'C', 'C', 'C'], 87, $data1, 4);

            pdf_fill($pdf, 'ligne_rayee');
            $t1 = ($d1n && $d1n['total_points'] !== null) ? $d1n['total_points'] . '   (' . appreciation_fr($d1n['total_points'], $bareme) . ')' : '';
            $pdf->SetXY(87, $y + 4);
            $pdf->Cell(44, 4, pdf_u($t1), 1, 1, 'C', 1);
            $t2 = ($d2n && $d2n['total_points'] !== null) ? $d2n['total_points'] . '   (' . appreciation_fr($d2n['total_points'], $bareme) . ')' : '';
            $pdf->SetXY(131, $y + 4);
            $pdf->Cell(44, 4, pdf_u($t2), 1, 1, 'C', 1);

            pdf_fill($pdf, 'ligne_alternee');
            $pdf->SetXY(175, $y);
            $pdf->Cell(15, 8, $moy !== null ? sprintf('%05.2f', $moy) : '', 1, 1, 'C', 1);
            $pdf->SetXY(190, $y);
            $pdf->Cell(14, 8, pdf_u(appreciation_fr($moy, $bareme)), 1, 1, 'C', 1);
        }
        pdf_fill($pdf, 'groupe_competence');
        $y = $y + 3.9;
        $cp++;
    }

    // ── Ligne des totaux par évaluation (UA) — PERMUTÉ (anglais fixe) ──
    $moy_seq1 = $T_bareme_seq1 > 0 ? $T_points_seq1 / $T_bareme_seq1 * 20 : null;
    $moy_seq2 = $T_bareme_seq2 > 0 ? $T_points_seq2 / $T_bareme_seq2 * 20 : null;
    $moys_dispo = array_filter([$moy_seq1, $moy_seq2], fn($v) => $v !== null);
    $moy_ua_globale = $moys_dispo ? array_sum($moys_dispo) / count($moys_dispo) : null;

    $rang_seq1_txt = ''; $rang_seq2_txt = '';
    if ($id_seq1) {
        $cl1 = classement_sur_sequences($id_classe, [$id_seq1], $val_annee);
        foreach ($cl1['lignes'] as $l) {
            if ((int) $l['id_eleve'] === $id) { $rang_seq1_txt = $l['rang'] !== '' ? $l['rang'] . '/' . $cl1['nb_classes'] : ''; break; }
        }
    }
    if ($id_seq2) {
        $cl2 = classement_sur_sequences($id_classe, [$id_seq2], $val_annee);
        foreach ($cl2['lignes'] as $l) {
            if ((int) $l['id_eleve'] === $id) { $rang_seq2_txt = $l['rang'] !== '' ? $l['rang'] . '/' . $cl2['nb_classes'] : ''; break; }
        }
    }

    $y = $pdf->GetY();
    pdf_fill($pdf, 'entete_section');
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetXY(5, $y);
    $pdf->Cell(82, 8, 'TOTAL / AVERAGE PER EVALUATION', 1, 1, 'L', 1);

    $lbl_total = 'Total';
    $lbl_moy   = 'Avg';
    $lbl_rang  = 'Rank';
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->SetXY(87, $y);
    $pdf->Cell(44, 4, $moy_seq1 !== null ? pdf_u($lbl_total . ': ' . sprintf('%05.2f', $T_points_seq1) . '/' . (int) $T_bareme_seq1) : pdf_u('-'), 1, 1, 'C', 1);
    $pdf->SetXY(131, $y);
    $pdf->Cell(44, 4, $moy_seq2 !== null ? pdf_u($lbl_total . ': ' . sprintf('%05.2f', $T_points_seq2) . '/' . (int) $T_bareme_seq2) : pdf_u('-'), 1, 1, 'C', 1);

    $pdf->SetFont('Arial', 'B', 6.5);
    $pdf->SetXY(87, $y + 4);
    $pdf->Cell(44, 4, $moy_seq1 !== null ? pdf_u($lbl_moy . ': ' . sprintf('%05.2f', $moy_seq1) . '/20' . ($rang_seq1_txt !== '' ? '  ' . $lbl_rang . ': ' . $rang_seq1_txt : '')) : '', 1, 1, 'C', 1);
    $pdf->SetXY(131, $y + 4);
    $pdf->Cell(44, 4, $moy_seq2 !== null ? pdf_u($lbl_moy . ': ' . sprintf('%05.2f', $moy_seq2) . '/20' . ($rang_seq2_txt !== '' ? '  ' . $lbl_rang . ': ' . $rang_seq2_txt : '')) : '', 1, 1, 'C', 1);

    $pdf->SetFont('Arial', 'B', 7.5);
    pdf_fill($pdf, 'ligne_alternee');
    $pdf->SetXY(175, $y);
    $pdf->Cell(15, 8, $moy_ua_globale !== null ? sprintf('%05.2f', $moy_ua_globale) : '', 1, 1, 'C', 1);
    $pdf->SetXY(190, $y);
    $pdf->Cell(14, 8, pdf_u(appreciation_fr($moy_ua_globale, 20)), 1, 1, 'C', 1);
    $pdf->SetY($y + 8);

    // ── DISCIPLINE / WORK / CLASS PROFILE / STUDENT RESULTS — PERMUTÉ ──
    $pdf->SetFont('Arial', 'B', 10);
    $pos = $pdf->GetY();
    pdf_fill($pdf, 'entete_section');
    $pdf->RoundedRect(5, $pos + 0.7, 199, 7, 7, 'DF');
    $header4 = ['DISCIPLINE', 'WORK', 'CLASS PROFILE', 'STUDENT RESULTS'];
    $pdf->SetFont('Arial', 'B', 10);
    $w4 = [26, 40, 44, 72];
    $pdf->Ln(1.2);
    $pdf->SetX(14);
    $pdf->printTableHeader_Font($header4, $w4, null);

    $pdf->SetFont('Arial', 'I', 10);
    $pdf->Text(19, $pos + 6.7, pdf_u('Disciplines'));
    $pdf->Text(55, $pos + 6.7, pdf_u('Travail'));
    $pdf->Text(93, $pos + 6.7, pdf_u('Profil de la classe'));
    $pdf->Text(150, $pos + 6.7, pdf_u("Résultats de l'élève"));
    $pdf->Ln(1.2);

    $moy_gen = $resultat['moyenne'];
    $jours  = jours_absence_non_justifiees_trimestre($id, $id_classe, $id_trim, $val_annee);
    $abs_jus = jours_absence_justifiees_trimestre($id, $id_classe, $id_trim, $val_annee);
    $exclusion = exclusion_jours_trimestre($id, $id_classe, $id_trim, $val_annee);

    $m = mention_travail($moy_gen, $jours);

    $y2 = $pdf->GetY();
    $al8 = ['L', 'C', 'C', 'C', 'L', 'C', 'L', 'C'];
    $w2  = [29, 7, 1.7, 6, 31, 1.7, 28, 14.5];
    // pdf_u() indispensable ici : FPDF n'affiche que du Windows-1252, un
    // « — » UTF-8 brut passé à Cell()/table_gd() sans conversion s'affiche
    // en caractères parasites (« â€" ») — bug trouvé le 26/08/2026 sur un
    // bulletin sans note encore saisie (placeholder affiché).
    $fmt2 = fn(?float $v): string => $v === null ? pdf_u('—') : sprintf('%05.2f', $v);
    // Libellés des colonnes 0/4/6 — PERMUTÉS (anglais gras en haut de la
    // ligne, français italique dessous — l'original avait FR en haut).
    // $lbl0/$lbl4/$lbl6 = position PRIMAIRE (gras 9pt) malgré le nom (gardé
    // pour limiter le diff avec bulletin_trimestriel.php) — quelques mots
    // anglais plus longs que leur équivalent français débordaient de la
    // colonne (29/31/28mm à 9pt) : abrégés, demande du 26/08/2026.
    $lbl0    = ['Justified absences', 'Unjust. absences', 'Exclusion (days)', 'Conduct warning', 'Conduct reprim.'];
    $lbl0_en = ['Absences Jus. ', 'Absences NJ.', 'Exclusion(jrs) ', 'Avert. conduite ', 'Blâme conduite'];
    $lbl4    = ['Honour roll', 'Encouragement', 'Congratulations', 'Work warning', 'Work reprimand'];
    $lbl4_en = ["Tableau d'honneur", 'Encouragement', 'Félicitations ', 'Avert. Travail', 'Blâme Travail '];
    $lbl6    = ['Class average', 'Top average', 'Last average', 'Ranked enrolment', 'Success rate'];
    $lbl6_en = ['Moy. de la classe', 'Moy. du premier', 'Moy. du dernier ', 'Effectif Classé ', 'Taux de réussite '];
    $datas2 = [
        ['', (string) (int) $abs_jus, '', '', '', '', '', $fmt2($resultat['moy_classe'])],
        ['', (string) (int) $jours, '', '', '', '', '', $fmt2($resultat['moy_premier'])],
        ['', $exclusion !== null ? (string) $exclusion : '', '', '', '', '', '', $fmt2($resultat['moy_dernier'])],
        ['', '', '', '', '', '', '', (string) $resultat['nb_classes']],
        ['', '', '', '', '', '', '', $resultat['taux_reussite'] !== null ? $resultat['taux_reussite'] . '%' : pdf_u('—')],
    ];
    $pdf->Ln(6);
    $pdf->SetX(5);
    $tbl_top2 = $pdf->GetY();
    $pdf->SetFont('Arial', 'B', 9);
    $ROW_H2 = 7.4;
    $pdf->table_gd(array_fill(0, count($w2), ''), $w2, $al8, $datas2, $ROW_H2);

    // Overlay des 3 colonnes de libellés — mêmes coordonnées X que l'original.
    for ($i = 0; $i < 5; $i++) {
        $rowY = $tbl_top2 + $i * $ROW_H2;
        foreach ([[5, $lbl0, $lbl0_en], [48.7, $lbl4, $lbl4_en], [81.4, $lbl6, $lbl6_en]] as [$colX, $fr, $en]) {
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->Text($colX + 1, $rowY + 2.9, pdf_u($fr[$i]));
            $pdf->SetFont('Arial', 'I', 7.2);
            $pdf->Text($colX + 1, $rowY + 6.2, pdf_u($en[$i]));
        }
    }
    $bas_tableau2 = $tbl_top2 + 5 * $ROW_H2;
    pdf_fill($pdf, 'entete_section');
    $pdf->RoundedRect(41, $y2 - 1.5, 1.6, $bas_tableau2 - ($y2 - 1.5), 0, 'DF');
    $pdf->RoundedRect(79.8, $y2 - 1.5, 1.6, $bas_tableau2 - ($y2 - 1.5), 0, 'DF');
    $pdf->RoundedRect(123.5, $y2 - 1.5, 1.6, $bas_tableau2 - ($y2 - 1.5), 0, 'DF');
    $pdf->RoundedRect(163.7, $y2 + 5.5, 1.6, $bas_tableau2 - ($y2 + 5.5), 0, 'DF');

    $case_y2 = fn(int $i): float => $tbl_top2 + $i * $ROW_H2 + ($ROW_H2 - 3) / 2;
    pdf_case_an($pdf, $m['tableau_honneur'], 43.7, $case_y2(0));
    pdf_case_an($pdf, $m['encouragement'], 43.7, $case_y2(1));
    pdf_case_an($pdf, $m['felicitations'], 43.7, $case_y2(2));
    pdf_case_an($pdf, $m['avertissement'], 43.7, $case_y2(3));
    pdf_case_an($pdf, $m['blame'], 43.7, $case_y2(4));
    pdf_case_an($pdf, $m['avertissement_conduite'], 35.5, $case_y2(3));
    pdf_case_an($pdf, $m['blame_conduite'], 35.5, $case_y2(4));

    // ── Bloc RESULTATS DE L'ELEVE (droite) — PERMUTÉ ──────────────
    $y = $pdf->GetY();
    $H_RES = 8.6; $GAP_RES = 0.8;
    $rt1 = $bas_tableau2 - 4 * $H_RES - 3 * $GAP_RES;
    $rt2 = $rt1 + $H_RES + $GAP_RES; $rt3 = $rt2 + $H_RES + $GAP_RES; $rt4 = $rt3 + $H_RES + $GAP_RES;
    // 'TOTAL POINTS' est déjà un terme partagé FR/EN dans le fichier
    // d'origine (pas de vraie paire à permuter) — conservé tel quel.
    $lignes_resultat = [
        [$rt1, 'entete_section', 'TOTAL POINTS', 'Total des points'],
        [$rt2, 'cellule_resultat', 'AVERAGE', 'Moyenne'],
        [$rt3, 'entete_section', 'RANK', 'Rang'],
        [$rt4, 'cellule_resultat', 'APPRECIATION', 'Appréciation'],
    ];
    foreach ($lignes_resultat as [$rt, $fillName, $frLbl, $enLbl]) {
        $pdf->SetXY(125, $rt);
        pdf_fill($pdf, $fillName);
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(38, $H_RES, $frLbl, 1, 1, 'C', 1);
        $pdf->SetFont('Arial', 'I', 8);
        $enTxt = pdf_u($enLbl);
        $xC = 125 + (38 - $pdf->GetStringWidth($enTxt)) / 2;
        $pdf->Text($xC, $rt + $H_RES - 1, $enTxt);
    }

    $pdf->SetFont('Arial', 'B', 13);
    $pdf->SetXY(166, $rt1);
    pdf_fill($pdf, 'cellule_resultat');
    $pdf->Cell(38, $H_RES, sprintf('%05.2f', $T_points) . ' / ' . (int) $T_bareme, 1, 1, 'C', 1);
    pdf_fill($pdf, 'entete_section');
    $pdf->SetXY(166, $rt2);
    $pdf->Cell(38, $H_RES, ($moy_gen !== null ? sprintf('%05.2f', $moy_gen) : pdf_u('—')) . ' / 20', 1, 1, 'C', 1);
    $pdf->SetXY(166, $rt3);
    pdf_fill($pdf, 'cellule_resultat');
    $pdf->Cell(38, $H_RES, '', 1, 1, 'C', 1);

    [$rp, $rs] = rang_prefixe_suffixe_an((string) ($resultat['rang'] ?? ''));
    $suffixeRang = ' / ' . $resultat['effectif'];
    $pdf->SetFont('Arial', 'B', 15);
    $wRang = $pdf->GetStringWidth($rp) + $pdf->GetStringWidth($suffixeRang);
    if ($rs !== '') {
        $pdf->SetFontSize(12);
        $wRang += $pdf->GetStringWidth($rs);
        $pdf->SetFontSize(15);
    }
    $xRang = 166 + (38 - $wRang) / 2;
    $pdf->SetXY($xRang, $rt3 + 2);
    $pdf->Write(0.5, $rp);
    if ($rs !== '') $pdf->subWrite(1, $rs, '', 12, 6);
    $pdf->Write(0.5, $suffixeRang);

    $pdf->SetFont('Arial', 'B', 13);
    pdf_fill($pdf, 'entete_section');
    $pdf->SetXY(166, $rt4);
    $pdf->Cell(38, $H_RES, appreciation_moyenne($moy_gen), 1, 1, 'C', 1);

    $bas_gauche = $bas_tableau2;
    $bas_droite = $pdf->GetY();
    $pdf->SetY(max($bas_gauche, $bas_droite) + 2);

    // ── Interprétation des codes + signatures — PERMUTÉ ───────────
    pdf_fill($pdf, 'entete_section');
    $header1_fr = [pdf_u('Interprétation des codes'), 'ENSEIGNANT(E)', mb_strtoupper(pdf_u($etab['chef_etablissement'] ?: 'LE DIRECTEUR')), 'PARENTS'];
    $header1_en = ['Code interpretation', 'TEACHER', mb_strtoupper(pdf_u($etab['chef_etablissement_en'] ?? '') ?: 'THE DIRECTOR'), 'PARENTS'];
    $w1 = [54, 40, 65, 40];
    $al1b = ['C', 'C', 'C', 'C'];
    $hdr1_y = $pdf->GetY();
    $pdf->SetX(5);
    $pdf->SetFont('Arial', 'B', 9.5);
    $pdf->printTableHeader_border_Font_Fill($header1_en, $w1, 1, 7.8, null, $al1b, 1);
    $pdf->SetFont('Arial', 'I', 6.5);
    $colX1 = 5;
    foreach ($w1 as $i => $wCol) {
        $txt = pdf_u($header1_fr[$i]);
        $xC = $colX1 + ($wCol - $pdf->GetStringWidth($txt)) / 2;
        $pdf->Text($xC, $hdr1_y + 7, $txt);
        $colX1 += $wCol;
    }

    $pdf->Ln(7.8);
    $pp = $pdf->GetY();
    $pdf->SetX(5);
    $pdf->Cell(54, 35, '', 1, 1, 'C', 0);
    $pdf->SetFont('Arial', 'B', 9.5);
    $pdf->Text(7, $pp + 3, pdf_u('- 0 to 10 = Not Acquired'));
    $pdf->Text(7, $pp + 6, pdf_u('    (NA).'));
    $pdf->Text(7, $pp + 12.5, pdf_u('- 11 to 13 = In Progress'));
    $pdf->Text(7, $pp + 15.5, pdf_u('    (ECA).'));
    $pdf->Text(7, $pp + 22, pdf_u('- 14 to 17 = Acquired'));
    $pdf->Text(7, $pp + 25, pdf_u('    (A).'));
    $pdf->Text(7, $pp + 31.5, pdf_u('- 18 to 20 = Expert (A+).'));
    $pdf->SetFont('Arial', 'I', 6.2);
    $pdf->Text(7, $pp + 9.3, pdf_u('De 0 à 10 = Non acquises (NA).'));
    $pdf->Text(7, $pp + 18.8, pdf_u("De 11 à 13 = En cours d'acquisition (ECA)."));
    $pdf->Text(7, $pp + 28.3, pdf_u('De 14 à 17 = Acquises (A).'));
    $pdf->Text(7, $pp + 34, pdf_u('De 18 à 20 = Expert (A+).'));

    $pdf->SetXY(59, $pp);  $pdf->Cell(40, 35, '', 1, 1, 'C', 0);
    $pdf->SetXY(99, $pp);  $pdf->Cell(65, 35, '', 1, 1, 'C', 0);
    $pdf->SetXY(164, $pp); $pdf->Cell(40, 35, '', 1, 1, 'C', 0);

    if ($avec_sig) {
        // Même clé 'bulletin_trimestriel' que le fichier FR (mise en page
        // strictement identique, même cadre de signature) — pas de
        // configuration séparée à créer pour ce fichier.
        pdf_signature_appliquer_jn($pdf, 'bulletin_trimestriel', 99, $pp, 65, 35, [
            'x_pct' => 35, 'y_pct' => 30, 'w_pct' => 35, 'h_pct' => null,
        ]);
    }

    // ── QR de vérification ─────────────────────────────────────────
    // Piste 'fr' inchangée (même donnée, seul l'affichage change — voir
    // le commentaire en tête de fichier) ; verif_bulletin.php choisit le
    // bon fichier de réouverture (français/anglais) selon la section du
    // niveau de l'élève.
    $qr_tmp = bulletin_qr_fichier_temp($eleve, 'trim', $id_trim);
    if ($qr_tmp) {
        $pdf->Image($qr_tmp, 96, $ph - 28, 16, 16, 'PNG');
    }

    pdf_copyright($pdf, $pw, $ph);

    $pdf->SetTextColor(0, 0, 0);
}

// Préchargement en masse (optimisation, voir notes_apc.php) — identique à
// bulletin_trimestriel.php.
precharger_notes_sequence_classe((int) $liste[0]['id_classe'], $val_annee);
precharger_evaluations_annulees_annee($val_annee);
precharger_absences_justifiees_classe((int) $liste[0]['id_classe'], $val_annee);
precharger_eleves_classe((int) $liste[0]['id_classe'], $val_annee);
precharger_statuts_classe((int) $liste[0]['id_classe'], $val_annee);
precharger_absences_classe_trim((int) $liste[0]['id_classe'], $id_trim, $val_annee);
precharger_exclusions_classe_trim((int) $liste[0]['id_classe'], $id_trim, $val_annee);

// Enveloppé dans un try/catch : accessible publiquement via le QR du
// bulletin (voir $acces_public plus haut) — voir
// fonctions.php::pdf_erreur_generation() (même principe que bulletin_trimestriel.php).
try {
$pdf = new FPDF('P', 'mm', 'A4');
$pdf->SetAutoPageBreak(false, 0);

foreach ($liste as $e) {
    dessiner_bulletin_trimestriel_anglais(
        $pdf, $e['id_eleve'], $e['id_classe'], $e['classe_nom'], $id_trim, $val_annee,
        $etab, $pays_fr, $pays_en, $dept_fr, $arr_fr, $div_en, $sub_en,
        $trimestre, $note_1, $note_2, $trim_en, $effectif_classe, $avec_sig
    );
}

$nom_fichier = $id ? ('bulletin_' . ($liste[0]['id_eleve']) . '_T' . $id_trim . '.pdf') : ('bulletins_' . $liste[0]['classe_nom'] . '_T' . $id_trim . '.pdf');
$pdf->Output($dl ? 'D' : 'I', $nom_fichier);
} catch (Throwable $e) {
    pdf_erreur_generation($e);
}
