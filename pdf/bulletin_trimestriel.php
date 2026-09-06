<?php
// ── PDF : Bulletin trimestriel — piste française (APC) ──────────
// Port fidèle de jaynitaare/php/BULLETIN_TRIMESTRIEL.php (mêmes coordonnées,
// polices, tailles, couleurs) — modèle de référence : bul_trim.pdf. Groupé
// par groupe_competence (langue='Fr' uniquement, comme l'original), chaque
// compétence affichée avec le détail Orale/Écrite/Pratique/Savoir des 2 UA
// (séquences) du trimestre + total/cote par UA + moyenne/cote globales.
// Moyenne/rang/effectif via notes_apc.php (moteur de calcul vérifié).
//
// GET : id + trim (bulletin d'UN élève) — OU classe + trim (sans id) :
// imprime le bulletin de TOUS les élèves classés de cette classe/ce
// trimestre à la suite dans le même PDF (une page par élève).
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
if (!$acces_public) {                       // cloisonnement enseignant (piste FR)
    exiger_acces_classe($id_classe, $val_annee, 'fr');
    exiger_acces_eleve($id, 'fr');
}
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
// trimestre (jamais stocké en base, seul le libellé FR l'est).
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
    // moyenne décroissante) ou "alpha" (nom/prénom). Bug réel du 13/08 :
    // pages/bulletins/index.php transmettait bien Alpha/Mérite pour le
    // TABLEAU mais jamais pour le PDF en lot (?classe=), qui imprimait donc
    // toujours dans l'ordre du classement quel que soit le bouton cliqué.
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
function rang_prefixe_suffixe(string $rang): array {
    if ($rang === '') return ['', ''];
    if (preg_match('/^(\d+)(.*)$/', $rang, $m)) return [$m[1], trim($m[2])];
    return [$rang, ''];
}

// Coche/case vide (mêmes icônes que jaynitaare — voir assets/img/pdf/).
function pdf_case(FPDF $pdf, bool $coche, float $x, float $y, float $taille = 3.0): void {
    $fichier = $coche ? 'case_cochee.jpg' : 'case_a_cocher.jpg';
    $pdf->Image(__DIR__ . '/../assets/img/pdf/' . $fichier, $x, $y, $taille, $taille);
}

// ── Dessine le bulletin d'UN élève dans le $pdf déjà ouvert ──────
function dessiner_bulletin_trimestriel(
    FPDF $pdf, int $id, int $id_classe, string $classe_nom, int $id_trim, string $val_annee,
    array $etab, string $pays_fr, string $pays_en, string $dept_fr, string $arr_fr, string $div_en, string $sub_en,
    array $trimestre, string $note_1, string $note_2, string $trim_en, int $effectif_classe, bool $avec_sig
): void {
    // eleve_preload()/statut_eleve_preload() consultent le préchargement de
    // classe (precharger_eleves_classe()/precharger_statuts_classe(), voir
    // l'appel avant la boucle d'impression) au lieu de requêter à chaque
    // élève — trouvé le 21/08/2026 dans la même traque de requêtes.
    $eleve = eleve_preload($id, $id_classe, $val_annee);
    if (!$eleve) return;
    $statut = statut_eleve_preload($id, $id_classe, $val_annee);

    // Section anglophone (demande explicite du 20/08/2026, référence exacte
    // bul_en_trim.pdf) : seuls les libellés de groupe de compétences et de
    // compétence changent (jeu langue='An', déjà apparié par code_comp via
    // competences_classe()) — le reste du bulletin (en-tête bilingue,
    // discipline/travail/résultats, codes, signatures) est strictement
    // identique au modèle français, la saisie/le calcul restent sur le jeu
    // Fr dans tous les cas (`section_en` ne change QUE l'affichage).
    // Section rattachée au NIVEAU, pas à la classe (migration_v42, demande du
    // 20/08/2026 — deux classes d'un même niveau partagent forcément la
    // même section dans cet établissement).
    // Mémoïsé par classe — trouvé le 21/08/2026 : cette valeur est
    // constante pour TOUTE la classe (la section dépend du niveau, pas de
    // l'élève), mais était requêtée pour chaque bulletin d'un tirage en
    // lot (40 requêtes identiques pour une classe de 40).
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
    $he1 = ['', '', '']; $we1 = [83, 30, 83]; $al1 = ['C', 'C', 'C'];
    $d1 = [
        [pdf_u($pays_fr), '', pdf_u($pays_en)],
        [pdf_u(mb_strtoupper($etab['region_fr'] ?: "REGION DE L'ADAMAOUA")), '', pdf_u(mb_strtoupper($etab['region_en'] ?: 'ADAMAWA REGION'))],
        [pdf_u(mb_strtoupper($dept_fr)), '', pdf_u(mb_strtoupper($div_en))],
        [pdf_u(mb_strtoupper($arr_fr)), '', pdf_u(mb_strtoupper($sub_en))],
    ];
    $pdf->SetXY(8, 6);
    $pdf->SetFont('Arial', '', 8);
    $pdf->table_etab($he1, $we1, $al1, $d1);

    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetXY(8, 21);
    $pdf->table_etab([''], [83], ['C'], [[pdf_u($etab['nom_fr'])]]);
    $pdf->SetXY(121, 22);
    $pdf->table_etab([''], [83], ['C'], [[pdf_u($etab['nom_en'])]]);

    $d2 = [
        [pdf_u('B.P. ' . $etab['boite_postale'] . '   Tél.: ' . $etab['telephone']), '', pdf_u('P.O. BOX. ' . $etab['boite_postale'] . '   Phone: ' . $etab['telephone'])],
        [pdf_u($etab['email']), '', pdf_u($etab['email'])],
    ];
    $pdf->SetX(8);
    $pdf->SetFont('Arial', '', 8);
    $pdf->table_etab($he1, $we1, $al1, $d2);

    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Text(9, 40, pdf_u('Année scolaire: ' . $val_annee));
    $pdf->SetFont('Arial', 'I', 8);
    $pdf->Text(9, 42.5, 'School Year');

    // ── Bandeau titre (pilule arrondie) ───────────────────────────
    $pdf->RoundedRect(50, 33, 108, 10, 10, 'DF');
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Text(56, 37.5, ' BULLETIN DE NOTES');
    $pdf->SetFont('Arial', 'I', 14);
    $pdf->Text(111, 37.5, '- REPORT CARD');
    $pdf->SetFont('Arial', 'BI', 14);
    $pdf->Text(73, 42, pdf_u($trimestre['libelle_trim']));
    $pdf->SetFont('Arial', 'I', 14);
    $pdf->Text(110, 42, '- ' . $trim_en);

    // ── Grille CLASSE/MATRICULE/EFFECTIF/REDOUBLANT ───────────────
    pdf_fill($pdf, 'ligne_alternee');
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetXY(5, 44);   $pdf->Cell(18, 5.5, ' ', 1, 1, 'C', 0);
    $pdf->SetXY(23.7, 44); $pdf->Cell(18, 5.5, pdf_u($classe_nom), 1, 1, 'C', 1);
    // Cellule NIU (ex-MATRICULE, demande du 20/08/2026) : libellé réduit
    // (10mm, "NIU" est court) au profit de la cellule valeur élargie
    // (26mm) — la fin du bloc reste à x=79.1 comme avant, pour ne pas
    // décaler EFFECTIF/REDOUBLANT à droite.
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

    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Text(5, 47, ' CLASSE : ');
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Text(43, 47, 'NIU');
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Text(82.2, 47, 'EFFECTIF : ');
    $pdf->Text(115.5, 47, 'REDOUBLANT : ');
    $pdf->Text(5, 53, ' NOM ET PRENOMS :');
    $pdf->Text(5, 59, ' NE(E) LE :');
    $pdf->Text(126, 59, ' SEXE :');

    $pdf->SetFont('Arial', 'I', 8);
    $pdf->Text(5, 49, ' Class');
    $pdf->Text(43, 49, ' ID');
    $pdf->Text(82.2, 49, 'Enrollment');
    $pdf->Text(115.5, 49, 'Repeat');
    $pdf->Text(5, 55, ' Surname and given Names');
    $pdf->Text(5, 61, ' Born on');
    $pdf->Text(126, 61, ' Sex');

    // ── Titulaire ──────────────────────────────────────────────────
    // Mémoïsé par (classe, année) — même raison que $section_en ci-dessus :
    // constant pour toute la classe, requêté à l'identique pour chaque
    // bulletin d'un tirage en lot.
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
    $pdf->Text(5, 65, pdf_u(' TITULAIRE :  ' . trim(($enseignant['civilite_ens'] ?? '') . ' ' . ($enseignant['nom_ens'] ?? '') . ' ' . ($enseignant['prenom_ens'] ?? ''))));
    $pdf->SetFont('Arial', 'I', 8);
    $pdf->Text(5, 67.5, 'Class Teacher');

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

    // ── En-tête tableau compétences ──────────────────────────────
    $pdf->SetFont('Arial', 'B', 8.5);
    pdf_fill($pdf, 'ligne_alternee');
    $pdf->SetXY(5, 68);
    $pdf->Cell(82, 8, 'COMPETENCES', 1, 1, 'C', 1);
    $pdf->SetXY(87, 68);  $pdf->MultiCell(44, 3.5, pdf_u($note_1), 1, 'C', 1);
    $pdf->SetXY(131, 68); $pdf->MultiCell(44, 3.5, pdf_u($note_2), 1, 'C', 1);
    $pdf->SetXY(175, 68); $pdf->MultiCell(15, 8, 'MOY', 1, 'C', 1);
    $pdf->SetXY(190, 68); $pdf->MultiCell(14, 8, 'COTE', 1, 'C', 1);

    $w8 = [11, 11, 11, 11, 11, 11, 11, 11];
    $header8 = [pdf_u('Orale'), pdf_u('Ecrite'), pdf_u('Pratique'), pdf_u('Savoir'), pdf_u('Orale'), pdf_u('Ecrite'), pdf_u('Pratique'), pdf_u('Savoir')];
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->SetFillColor(255, 255, 255);
    $pdf->SetXY(87, 71.5);
    $pdf->printTableHeader_border_Font($header8, $w8, 1, 4.5, null, ['C', 'C', 'C', 'C', 'C', 'C', 'C', 'C']);

    // ── Groupes de compétences ───────────────────────────────────
    pdf_fill($pdf, 'groupe_competence');
    $T_bareme = 0.0; $T_points = 0.0;
    // Totaux par évaluation (UA1/UA2) — demande du 20/08/2026 : ligne
    // récapitulative en fin de tableau avec le total des points et la
    // moyenne de CHAQUE séquence, pas seulement la moyenne combinée par
    // compétence déjà affichée dans la colonne MOY.
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

            // Valeur brute (pas de format forcé) pour le total par UA, comme
            // l'original ($N_seq1[4] concaténé directement, sans sprintf) —
            // seule la MOY globale de la compétence est formatée %05.2f.
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

    // ── Ligne des totaux par évaluation (UA) ──────────────────────
    // Demande du 20/08/2026 : à la fin du tableau des compétences,
    // avant Disciplines/Travail/Profil de la classe/Résultats, une
    // ligne récapitulative avec le total des points et la moyenne de
    // CHAQUE évaluation (UA1/UA2) — pour voir d'un coup d'œil le
    // niveau par séquence, en plus de la moyenne combinée déjà
    // affichée par compétence dans la colonne MOY.
    $moy_seq1 = $T_bareme_seq1 > 0 ? $T_points_seq1 / $T_bareme_seq1 * 20 : null;
    $moy_seq2 = $T_bareme_seq2 > 0 ? $T_points_seq2 / $T_bareme_seq2 * 20 : null;
    $moys_dispo = array_filter([$moy_seq1, $moy_seq2], fn($v) => $v !== null);
    $moy_ua_globale = $moys_dispo ? array_sum($moys_dispo) / count($moys_dispo) : null;

    // Rang de l'élève À CETTE ÉVALUATION précise (UA1/UA2 séparément) —
    // demande du 21/08/2026 : la ligne TOTAL/MOYENNE PAR EVALUATION doit
    // aussi montrer ce rang, pas seulement le rang combiné du trimestre déjà
    // affiché plus bas (bloc RESULTATS DE L'ELEVE, $resultat['rang']). Même
    // moteur de classement (classement_sur_sequences(), notes_apc.php) mais
    // appelé avec UNE SEULE séquence à la fois — coûte un classement de
    // classe en plus par élève et par UA (déjà le principe de
    // rang_eleve_trimestre() plus bas dans cette même fonction ; les notes
    // sont préchargées en masse par précharger_notes_sequence_classe(), voir
    // l'appel avant la boucle d'impression, donc pas de requête SQL en plus).
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
    $pdf->Cell(82, 8, pdf_u($section_en ? 'TOTAL / AVERAGE PER EVALUATION' : 'TOTAL / MOYENNE PAR EVALUATION'), 1, 1, 'L', 1);

    $lbl_total = $section_en ? 'Total' : 'Total';
    $lbl_moy   = $section_en ? 'Avg' : 'Moy';
    $lbl_rang  = $section_en ? 'Rank' : 'Rang';
    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->SetXY(87, $y);
    $pdf->Cell(44, 4, $moy_seq1 !== null ? pdf_u($lbl_total . ': ' . sprintf('%05.2f', $T_points_seq1) . '/' . (int) $T_bareme_seq1) : pdf_u('-'), 1, 1, 'C', 1);
    $pdf->SetXY(131, $y);
    $pdf->Cell(44, 4, $moy_seq2 !== null ? pdf_u($lbl_total . ': ' . sprintf('%05.2f', $T_points_seq2) . '/' . (int) $T_bareme_seq2) : pdf_u('-'), 1, 1, 'C', 1);

    // Ligne Moy+Rang en police légèrement réduite (7.5→6.5) pour que le rang
    // ajouté tienne dans la même largeur de cellule (44mm) sans jamais
    // déborder, même au pire cas ("12e ex/32") — ne touche PAS à la hauteur
    // de ligne (4mm, inchangée) pour ne rien décaler dans le reste du
    // bulletin (tableau DISCIPLINES/TRAVAIL, bloc RESULTATS, QR, copyright —
    // tous positionnés en cascade à partir d'ici, voir les commentaires plus
    // bas sur $ROW_H2/$bas_tableau2).
    $pdf->SetFont('Arial', 'B', 6.5);
    $pdf->SetXY(87, $y + 4);
    $pdf->Cell(44, 4, $moy_seq1 !== null ? pdf_u($lbl_moy . ': ' . sprintf('%05.2f', $moy_seq1) . '/20' . ($rang_seq1_txt !== '' ? '  ' . $lbl_rang . ': ' . $rang_seq1_txt : '')) : '', 1, 1, 'C', 1);
    $pdf->SetXY(131, $y + 4);
    $pdf->Cell(44, 4, $moy_seq2 !== null ? pdf_u($lbl_moy . ': ' . sprintf('%05.2f', $moy_seq2) . '/20' . ($rang_seq2_txt !== '' ? '  ' . $lbl_rang . ': ' . $rang_seq2_txt : '')) : '', 1, 1, 'C', 1);

    // Restaure la taille de police d'origine (7.5) avant les cellules
    // MOY/APPRECIATION ci-dessous — la ligne Moy+Rang au-dessus vient de la
    // réduire à 6.5, sans quoi ces deux cellules hériteraient à tort de
    // cette taille plus petite (aucun SetFont explicite avant elles à
    // l'origine, elles suivaient simplement la police déjà en cours).
    $pdf->SetFont('Arial', 'B', 7.5);
    pdf_fill($pdf, 'ligne_alternee');
    $pdf->SetXY(175, $y);
    $pdf->Cell(15, 8, $moy_ua_globale !== null ? sprintf('%05.2f', $moy_ua_globale) : '', 1, 1, 'C', 1);
    $pdf->SetXY(190, $y);
    $pdf->Cell(14, 8, pdf_u(appreciation_fr($moy_ua_globale, 20)), 1, 1, 'C', 1);
    $pdf->SetY($y + 8);

    // ── DISCIPLINES / TRAVAIL / PROFIL DE LA CLASSE / RESULTATS ──
    $pdf->SetFont('Arial', 'B', 10);
    $pos = $pdf->GetY();
    pdf_fill($pdf, 'entete_section');
    $pdf->RoundedRect(5, $pos + 0.7, 199, 7, 7, 'DF');
    $header4 = [pdf_u('DISCIPLINES'), pdf_u('TRAVAIL'), pdf_u('PROFIL DE LA CLASSE'), pdf_u("RESULTATS DE L'ELEVE")];
    $pdf->SetFont('Arial', 'B', 10);
    $w4 = [26, 40, 44, 72];
    $pdf->Ln(1.2);
    $pdf->SetX(14);
    $pdf->printTableHeader_Font($header4, $w4, null);

    $pdf->SetFont('Arial', 'I', 10);
    $pdf->Text(19, $pos + 6.7, 'Disciplines');
    $pdf->Text(55, $pos + 6.7, 'Work');
    $pdf->Text(93, $pos + 6.7, 'Class profile');
    $pdf->Text(150, $pos + 6.7, 'Student results');
    $pdf->Ln(1.2);

    $moy_gen = $resultat['moyenne'];
    $jours  = jours_absence_non_justifiees_trimestre($id, $id_classe, $id_trim, $val_annee);
    // jours_absence_justifiees_trimestre()/exclusion_jours_trimestre()
    // consultent le préchargement de classe+trimestre (voir l'appel avant
    // la boucle d'impression) — trouvé le 21/08/2026, même traque.
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
    // Libellés des colonnes 0/4/6 (FR) mis à blanc ici — demande du
    // 20/08/2026 : bilingue FR (gras)/EN (italique dessous), comme le bloc
    // d'identification de l'élève plus haut. table_gd()/MultiCell() ne gère
    // qu'UNE police par cellule (pas de gras+italique mélangés), donc le
    // texte est retiré d'ici et redessiné en overlay juste après l'appel
    // (mêmes coordonnées de colonnes X que $w2, lignes de 5.4mm chacune).
    $lbl0    = ['Absences Jus. ', 'Absences NJ.', 'Exclusion(jrs) ', 'Avert. conduite ', 'Blâme conduite'];
    $lbl0_en = ['Justified absences', 'Unjustified absences', 'Exclusion (days)', 'Conduct warning', 'Conduct reprimand'];
    $lbl4    = ["Tableau d'honneur", 'Encouragement', 'Félicitations ', 'Avert. Travail', 'Blâme Travail '];
    $lbl4_en = ['Honour roll', 'Encouragement', 'Congratulations', 'Work warning', 'Work reprimand'];
    $lbl6    = ['Moy. de la classe', 'Moy. du premier', 'Moy. du dernier ', 'Effectif Classé ', 'Taux de réussite '];
    $lbl6_en = ['Class average', "Top student's average", "Last student's average", 'Ranked enrolment', 'Success rate'];
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
    // Hauteur de ligne du bloc DISCIPLINES/TRAVAIL/PROFIL — agrandie le
    // 21/08/2026 (5.4→6.6mm) pour laisser respirer les polices bilingues
    // (elles-mêmes agrandies au même moment, cf. plus bas). Toutes les
    // coordonnées qui en dépendent (barres de séparation, cases à cocher,
    // bloc RESULTATS DE L'ELEVE, position de la table Interprétation) sont
    // calculées à partir de cette seule constante — ne JAMAIS y remettre
    // de nombre magique séparé, sous peine de désaligner tout le bloc.
    $ROW_H2 = 7.4;
    // table_gd() boucle sur count($header) pour savoir combien de colonnes
    // imprimer (son contenu texte n'est jamais affiché — voir pdf/fpdf.php,
    // l'appel à printTableHeader() y est commenté). $header4 n'a que 4
    // éléments (DISCIPLINES/TRAVAIL/PROFIL DE LA CLASSE/RESULTATS) alors que
    // $datas2/$w2/$al8 en ont 8 : les 4 dernières colonnes (Tableau
    // d'honneur.../Moy. de la classe...) n'étaient donc jamais imprimées.
    // Bug réel trouvé le 13/08 (section TRAVAIL/PROFIL DE LA CLASSE vide sur
    // le bulletin trimestriel FR). Un tableau vide de la bonne taille suffit.
    $pdf->table_gd(array_fill(0, count($w2), ''), $w2, $al8, $datas2, $ROW_H2);

    // Overlay bilingue des 3 colonnes de libellés (FR gras en haut de la
    // ligne, EN italique en bas) — colonnes X alignées sur $w2 (5, 48.7,
    // 81.4). Polices ré-agrandies le 21/08/2026 (8→9 / 6.3→7.2).
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

    // Cases à cocher superposées (Tableau d'honneur/Encouragement/
    // Félicitations/Avert./Blâme — conduite ET travail) — centrées
    // verticalement dans chaque ligne de $ROW_H2 (case de 3mm).
    $case_y2 = fn(int $i): float => $tbl_top2 + $i * $ROW_H2 + ($ROW_H2 - 3) / 2;
    pdf_case($pdf, $m['tableau_honneur'], 43.7, $case_y2(0));
    pdf_case($pdf, $m['encouragement'], 43.7, $case_y2(1));
    pdf_case($pdf, $m['felicitations'], 43.7, $case_y2(2));
    pdf_case($pdf, $m['avertissement'], 43.7, $case_y2(3));
    pdf_case($pdf, $m['blame'], 43.7, $case_y2(4));
    pdf_case($pdf, $m['avertissement_conduite'], 35.5, $case_y2(3));
    pdf_case($pdf, $m['blame_conduite'], 35.5, $case_y2(4));

    // ── Bloc RESULTATS DE L'ELEVE (droite) ────────────────────────
    // Libellés bilingues (FR gras 10pt en haut de cellule / EN italique
    // 6.5pt en bas) — polices agrandies le 21/08/2026 (9→10 / 5.5→6.5),
    // cellules rehaussées (6.1→7.4mm) pour les accueillir. Ancrée sur
    // $bas_tableau2 (bas réel du tableau de gauche, cf. $ROW_H2 ci-dessus)
    // plutôt que sur un delta figé — reste alignée quelle que soit la
    // hauteur de ligne choisie côté gauche.
    // Polices ré-agrandies le 21/08/2026 (10→12 / 6.5→8) et EN recentré
    // horizontalement (GetStringWidth, comme l'overlay du header
    // Interprétation des codes) au lieu du Text() gauche-aligné d'origine
    // — demande explicite : l'anglais doit être centré comme le français.
    $y = $pdf->GetY();
    $H_RES = 8.6; $GAP_RES = 0.8;
    $rt1 = $bas_tableau2 - 4 * $H_RES - 3 * $GAP_RES;
    $rt2 = $rt1 + $H_RES + $GAP_RES; $rt3 = $rt2 + $H_RES + $GAP_RES; $rt4 = $rt3 + $H_RES + $GAP_RES;
    $lignes_resultat = [
        [$rt1, 'entete_section', 'TOTAL POINTS', 'Total points'],
        [$rt2, 'cellule_resultat', 'MOYENNE', 'Average'],
        [$rt3, 'entete_section', 'RANG', 'Rank'],
        [$rt4, 'cellule_resultat', 'APPRECIATION', 'Appreciation'],
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

    // Centrage horizontal ET vertical du rang dans sa cellule (166, $rt3,
    // largeur 38) — demande du 21/08/2026 : l'ancien SetXY(176, ...) fixe
    // ne centrait correctement que par coïncidence, pour UN nombre précis
    // de chiffres (ex. "1e / 40") ; il décalait le texte dès que le rang
    // ou l'effectif changeait de largeur (ex. "1e / 15", "12e / 40"...).
    // Largeur totale calculée avec les 3 tailles de police réellement
    // utilisées (15pt pour le rang/l'effectif, 12pt pour l'exposant "e"
    // dessiné par subWrite), puis x centré dessus.
    [$rp, $rs] = rang_prefixe_suffixe((string) ($resultat['rang'] ?? ''));
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

    // Descente explicite avant le tableau Interprétation/Signatures —
    // demande du 20/08/2026 : le curseur PDF, après le bloc RESULTATS DE
    // L'ELEVE (colonne de droite), pouvait se trouver plus HAUT que le
    // bas réel du bloc DISCIPLINES/TRAVAIL/PROFIL (colonne de gauche) et
    // de ses barres de séparation verticales — un Ln(-1.5) relatif au
    // dernier Cell() dessiné faisait alors chevaucher les deux tableaux.
    // On repart explicitement du point le plus bas des deux colonnes
    // (comme $bas_recap dans pdf/bulletin_annuel.php pour le même type de
    // problème) — $bas_tableau2 remplace l'ancien magique "$y2+33".
    $bas_gauche = $bas_tableau2;
    $bas_droite = $pdf->GetY();
    $pdf->SetY(max($bas_gauche, $bas_droite) + 2);

    // ── Interprétation des codes + signatures ─────────────────────
    // En-tête bilingue (FR gras en haut / EN italique en bas de la même
    // cellule) — demande du 20/08/2026. printTableHeader_border_Font_Fill()
    // n'accepte qu'UNE police par cellule (Cell() simple), donc le FR est
    // dessiné normalement (centré automatiquement par Cell) avec une
    // police réduite, puis l'EN est calculé et centré manuellement en
    // overlay (GetStringWidth) dans la même cellule agrandie à 7mm.
    pdf_fill($pdf, 'entete_section');
    $header1 = [pdf_u('Interprétation des codes'), 'ENSEIGNANT(E)', mb_strtoupper(pdf_u($etab['chef_etablissement'] ?: 'LE DIRECTEUR')), 'PARENTS'];
    $header1_en = ['Code interpretation', 'TEACHER', mb_strtoupper(pdf_u($etab['chef_etablissement_en'] ?? '') ?: 'THE DIRECTOR'), 'PARENTS'];
    $w1 = [54, 40, 65, 40];
    $al1b = ['C', 'C', 'C', 'C'];
    $hdr1_y = $pdf->GetY();
    $pdf->SetX(5);
    // Le paramètre $SetFont de printTableHeader_border_Font_Fill() est un
    // simple statement `$SetFont;` côté fpdf.php (PHP n'évalue pas les
    // chaînes comme du code) — sans effet, quoi qu'on lui passe. La police
    // doit donc être fixée AVANT l'appel (convention déjà utilisée partout
    // ailleurs dans ce fichier, qui lui passe toujours `null`).
    // Polices agrandies le 21/08/2026 (8.5→9.5 / 5.5→6.5), cellule
    // rehaussée (7→7.8mm) pour les accueillir.
    $pdf->SetFont('Arial', 'B', 9.5);
    $pdf->printTableHeader_border_Font_Fill($header1, $w1, 1, 7.8, null, $al1b, 1);
    $pdf->SetFont('Arial', 'I', 6.5);
    $colX1 = 5;
    foreach ($w1 as $i => $wCol) {
        $txt = pdf_u($header1_en[$i]);
        $xC = $colX1 + ($wCol - $pdf->GetStringWidth($txt)) / 2;
        $pdf->Text($xC, $hdr1_y + 7, $txt);
        $colX1 += $wCol;
    }

    $pdf->Ln(7.8);
    $pp = $pdf->GetY();
    $pdf->SetX(5);
    $pdf->Cell(54, 35, '', 1, 1, 'C', 0);
    $pdf->SetFont('Arial', 'B', 9.5);
    $pdf->Text(7, $pp + 3, pdf_u('- De 0 à 10= Compétences non'));
    $pdf->Text(7, $pp + 6, pdf_u('    acquises (NA).'));
    $pdf->Text(7, $pp + 12.5, pdf_u('- De 11 à 13= Compétences en'));
    $pdf->Text(7, $pp + 15.5, pdf_u("    cours d'acquisition (ECA)."));
    $pdf->Text(7, $pp + 22, pdf_u('- De 14 à 17= Compétences '));
    $pdf->Text(7, $pp + 25, pdf_u('    acquises (A).'));
    $pdf->Text(7, $pp + 31.5, pdf_u('- De 18 à 20= Expert (A+).'));
    // Traductions EN (italique, sous chaque définition) — demande du
    // 20/08/2026, mêmes 4 paliers que appreciation_fr()/appreciation_moyenne().
    // Police agrandie le 21/08/2026 (5.3→6.2), lignes légèrement réespacées
    // en conséquence (cellule 33→35mm).
    $pdf->SetFont('Arial', 'I', 6.2);
    $pdf->Text(7, $pp + 9.3, pdf_u('0 to 10 = Not Acquired (NA).'));
    $pdf->Text(7, $pp + 18.8, pdf_u('11 to 13 = In Progress (ECA).'));
    $pdf->Text(7, $pp + 28.3, pdf_u('14 to 17 = Acquired (A).'));
    $pdf->Text(7, $pp + 34, pdf_u('18 to 20 = Expert (A+).'));

    $pdf->SetXY(59, $pp);  $pdf->Cell(40, 35, '', 1, 1, 'C', 0);
    $pdf->SetXY(99, $pp);  $pdf->Cell(65, 35, '', 1, 1, 'C', 0);
    $pdf->SetXY(164, $pp); $pdf->Cell(40, 35, '', 1, 1, 'C', 0);

    if ($avec_sig) {
        pdf_signature_appliquer_jn($pdf, 'bulletin_trimestriel', 99, $pp, 65, 35, [
            'x_pct' => 35, 'y_pct' => 30, 'w_pct' => 35, 'h_pct' => null,
        ]);
    }

    // ── QR de vérification ─────────────────────────────────────────
    // Fichier mis en cache disque (voir pdf/verif_lib.php) — pas de unlink.
    // Position FIXE (absolue, ancrée sur le bas de page via $ph) — demande
    // du 21/08/2026 : le QR ne doit plus dépendre de $pp (position du
    // tableau Interprétation/Signatures, elle-même variable selon le
    // nombre de groupes de compétences du niveau) ni d'aucune autre
    // position de tableau/texte au-dessus. Toujours au même endroit, quel
    // que soit le contenu du bulletin.
    $qr_tmp = bulletin_qr_fichier_temp($eleve, 'trim', $id_trim);
    if ($qr_tmp) {
        $pdf->Image($qr_tmp, 96, $ph - 28, 16, 16, 'PNG');
    }

    // Copyright standard du système (pdf/header_pdf.php) — texte unique sur
    // tous les PDF du projet, voir pdf_copyright().
    pdf_copyright($pdf, $pw, $ph);

    $pdf->SetTextColor(0, 0, 0);
}

// Préchargement en masse (optimisation, voir notes_apc.php) : mêmes gains
// qu'expliqué dans pdf/bulletin_annuel.php, pour le mode lot (?classe=).
precharger_notes_sequence_classe((int) $liste[0]['id_classe'], $val_annee);
// Idem pour evaluation_annulee — trouvé le 21/08/2026 en traquant le nombre
// de requêtes d'un bulletin (~1100 pour UN élève !) : classement_sur_sequences()
// (rang UA1/UA2, voir plus haut) boucle sur TOUS les élèves de la classe et
// interroge cette table pour chacun, 2 fois par bulletin — 80 requêtes pour
// une classe de 40. Chargée en une seule requête, ~1100 → ~50/élève.
precharger_evaluations_annulees_annee($val_annee);
// Idem pour absence_justifiee (fonction de préchargement déjà écrite dans
// notes_apc.php pour calculer_moyenne_trimestre_eleve(), mais jamais
// appelée sur ce chemin de lecture) — classement_sur_sequences() (via
// moyenne_eleve_sur_sequences()) l'interroge aussi pour chaque élève, 2 fois
// par bulletin. 80 requêtes de plus économisées sur une classe de 40.
precharger_absences_justifiees_classe((int) $liste[0]['id_classe'], $val_annee);
// Idem pour eleve/statut/absence(2 colonnes)/exclusion — trouvé le
// 21/08/2026, même traque : dessiner_bulletin_trimestriel() requêtait ces 4
// données individuellement PAR ÉLÈVE (4×40 requêtes pour une classe de 40),
// alors qu'une seule requête par table suffit pour toute la classe.
precharger_eleves_classe((int) $liste[0]['id_classe'], $val_annee);
precharger_statuts_classe((int) $liste[0]['id_classe'], $val_annee);
precharger_absences_classe_trim((int) $liste[0]['id_classe'], $id_trim, $val_annee);
precharger_exclusions_classe_trim((int) $liste[0]['id_classe'], $id_trim, $val_annee);

// Enveloppé dans un try/catch : accessible publiquement via le QR du
// bulletin (voir $acces_public plus haut) — voir
// fonctions.php::pdf_erreur_generation() (même principe que bulletin_annuel.php).
try {
$pdf = new FPDF('P', 'mm', 'A4');
$pdf->SetAutoPageBreak(false, 0);

foreach ($liste as $e) {
    dessiner_bulletin_trimestriel(
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
