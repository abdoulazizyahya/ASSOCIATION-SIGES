<?php
// ── PDF : Bulletin annuel — piste française (APC) ────────────────
// Port fidèle de jaynitaare/php/BULLETIN_ANNUEL_CLASSE.php (mêmes
// coordonnées, polices, tailles) — modèle de référence : bul_ann.pdf.
// Même structure que pdf/bulletin_trimestriel.php pour l'en-tête/grille/
// photo, mais tableau de compétences TRIM1/TRIM2/TRIM3/TOTAL/MOY/COTE/RANG
// (note_competence_annuelle() donne le détail par trimestre,
// rang_eleve_competence_annuelle() le rang par compétence), récapitulatif
// disciplines sur les 3 trimestres + annuel, décision de fin d'année.
// GET : id (bulletin d'UN élève) — OU classe (tous les élèves classés).
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
require_once __DIR__ . '/../notes_apc.php';
require_once __DIR__ . '/verif_lib.php';

// Accès public via le QR code du bulletin (jeton "vh", voir verif_bulletin.php
// et bulletin_verif_valider() dans verif_lib.php) — la personne qui scanne
// n'a pas forcément de compte dans le système ; le hash déjà vérifié une
// fois là-bas suffit à prouver qu'elle a le droit de voir CE bulletin précis
// (même mécanisme qu'ABZ_MBE, pages/bulletins/pdf_annuel.php).
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
// 🐛 CORRIGÉ : `annee_scolaire` n'a pas de colonne `id` (juste `val_annee`
// en clé primaire, voir fonctions.php::get_annee_active()) — cette
// affectation valait donc TOUJOURS 0 (via le repli `?? 0`), ce qui cassait
// silencieusement 2 choses : (1) le rappel TRIM1/TRIM2/TRIM3 du tableau
// RESULTATS DE L'ELEVE restait vide (la requête `trimestre WHERE
// id_annee=0` ne matchait jamais rien, `trimestre.id_annee` étant en
// réalité un VARCHAR contenant "2025/2026", pas un entier) — corrigé plus
// bas en passant par trimestres_de_annee($val_annee) (notes_apc.php) comme
// le reste du moteur ; (2) la période encodée dans le QR de vérification
// était toujours "0", identique pour toutes les années scolaires — sans
// conséquence de sécurité (le hash HMAC reste correct et cohérent entre
// génération/vérification) mais un même élève changeant d'année aurait pu
// halluciner un bulletin d'une autre année comme "vérifié" pour celle-ci.
// Remplacé par un identifiant numérique dérivé de val_annee (ex.
// "2025/2026" → 2025), stable et unique par année scolaire réelle.
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
    if (empty($liste)) die('Aucun élève classé pour cette classe.');
    $effectif_classe = $classement['effectif'];
}

function rang_prefixe_suffixe_ann(string $rang): array {
    if ($rang === '') return ['', ''];
    if (preg_match('/^(\d+)(.*)$/', $rang, $m)) return [$m[1], trim($m[2])];
    return [$rang, ''];
}
function pdf_case_ann(FPDF $pdf, bool $coche, float $x, float $y, float $taille = 3.0): void {
    $fichier = $coche ? 'case_cochee.jpg' : 'case_a_cocher.jpg';
    $pdf->Image(__DIR__ . '/../assets/img/pdf/' . $fichier, $x, $y, $taille, $taille);
}

function dessiner_bulletin_annuel(
    FPDF $pdf, int $id, int $id_classe, string $classe_nom, string $val_annee, int $id_annee,
    array $etab, string $pays_fr, string $pays_en, string $dept_fr, string $arr_fr, string $div_en, string $sub_en,
    int $effectif_classe, bool $avec_sig
): void {
    // eleve_preload()/statut_eleve_preload() consultent le préchargement de
    // classe (voir l'appel avant la boucle d'impression) — même correctif
    // de performance que pdf/bulletin_trimestriel.php (21/08/2026).
    $eleve = eleve_preload($id, $id_classe, $val_annee);
    if (!$eleve) return;
    $statut = statut_eleve_preload($id, $id_classe, $val_annee);
    // Section rattachée au NIVEAU, pas à la classe (migration_v42, demande
    // du 20/08/2026 — voir le même commentaire dans
    // pdf/bulletin_trimestriel.php pour le détail). Mémoïsé par classe
    // (21/08/2026) : constant pour toute la classe, requêté à l'identique
    // pour chaque bulletin d'un tirage en lot sinon.
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

    $he1 = ['', '', '']; $we1 = [83, 30, 83]; $al1 = ['C', 'C', 'C'];
    $d1 = [
        [pdf_u($pays_fr), '', pdf_u($pays_en)],
        [pdf_u(mb_strtoupper($etab['region_fr'] ?: "REGION DE L'ADAMAOUA")), '', pdf_u(mb_strtoupper($etab['region_en'] ?: 'ADAMAWA REGION'))],
        [pdf_u(mb_strtoupper($dept_fr)), '', pdf_u(mb_strtoupper($div_en))],
        [pdf_u(mb_strtoupper($arr_fr)), '', pdf_u(mb_strtoupper($sub_en))],
    ];
    $pdf->SetXY(8, 5);
    $pdf->SetFont('Arial', '', 8);
    $pdf->table_etab($he1, $we1, $al1, $d1);

    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetXY(8, 18.5);
    $pdf->table_etab([''], [83], ['C'], [[pdf_u($etab['nom_fr'])]]);
    $pdf->SetXY(121, 18.5);
    $pdf->table_etab([''], [83], ['C'], [[pdf_u($etab['nom_en'])]]);

    $d2 = [
        [pdf_u('B.P. ' . $etab['boite_postale'] . '   Tél.: ' . $etab['telephone']), '', pdf_u('P.O. BOX. ' . $etab['boite_postale'] . '   Phone: ' . $etab['telephone'])],
        [pdf_u($etab['email']), '', pdf_u($etab['email'])],
    ];
    $pdf->SetX(8);
    $pdf->SetFont('Arial', '', 7);
    $pdf->table_etab($he1, $we1, $al1, $d2);

    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Text(9, 40, pdf_u('Année scolaire: ' . $val_annee));
    $pdf->SetFont('Arial', 'I', 8);
    $pdf->Text(9, 42.5, 'School Year');

    if (!empty($etab['logo'])) {
        $logo_path = __DIR__ . '/../assets/uploads/' . $etab['logo'];
        if (is_file($logo_path)) $pdf->Image($logo_path, 92, 7, 22, 22);
    }

    $pdf->RoundedRect(50, 30.5, 108, 11.5, 11.5, 'DF');
    $pdf->SetFont('Arial', 'B', 20);
    $pdf->Text(70, 36.5, ' BULLETIN ANNUEL');
    $pdf->SetFont('Arial', 'I', 14);
    $pdf->Text(75, 41, 'ANNUAL REPORT CARD');

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

    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Text(5, 47, ' CLASSE : ');
    $pdf->Text(43, 47, ' NIVEAU : ');
    $pdf->Text(82.2, 47, 'EFFECTIF : ');
    $pdf->Text(115.5, 47, 'REDOUBLANT : ');
    $pdf->Text(5, 53, ' NOM ET PRENOMS :');
    $pdf->Text(5, 59, ' NE(E) LE :');
    $pdf->Text(126, 59, ' SEXE :');
    $pdf->SetFont('Arial', 'I', 8);
    $pdf->Text(5, 49, ' Class');
    $pdf->Text(43, 49, ' Niveau');
    $pdf->Text(82.2, 49, 'Enrollment');
    $pdf->Text(115.5, 49, 'Repeat');
    $pdf->Text(5, 55, ' Surname and given Names');
    $pdf->Text(5, 61, ' Born on');
    $pdf->Text(126, 61, ' Sex');

    // Mémoïsé par (classe, année) — même correctif que
    // pdf/bulletin_trimestriel.php (21/08/2026) : constant pour toute la
    // classe.
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

    // ── En-tête tableau compétences (TRIM1/TRIM2/TRIM3/TOTAL/MOY/COTE/RANG) ──
    $pdf->SetFont('Arial', 'B', 8.5);
    pdf_fill($pdf, 'entete_bleu');
    $pdf->SetXY(5, 69);
    $pdf->Cell(80, 4, 'COMPETENCES', 1, 1, 'C', 1);
    $pdf->SetXY(85.6, 69);  $pdf->MultiCell(22, 4, 'TRIM 1', 1, 'C', 1);
    $pdf->SetXY(108.2, 69); $pdf->MultiCell(22, 4, 'TRIM 2', 1, 'C', 1);
    $pdf->SetXY(130.8, 69); $pdf->MultiCell(22, 4, 'TRIM 3', 1, 'C', 1);
    $pdf->SetXY(153.4, 69); $pdf->MultiCell(13.5, 4, 'TOTAL', 1, 'C', 1);
    $pdf->SetXY(167.5, 69); $pdf->MultiCell(13, 4, 'MOY', 1, 'C', 1);
    $pdf->SetXY(181.1, 69); $pdf->MultiCell(11, 4, 'COTE', 1, 'C', 1);
    $pdf->SetXY(192.7, 69); $pdf->MultiCell(11, 4, 'RANG', 1, 'C', 1);

    // ── Groupes de compétences ───────────────────────────────────
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

            // Uniquement la note par trimestre (plus de sous-ligne "Cote" —
            // demande du 13/08 : la cote par compétence/trimestre n'apporte
            // rien de plus que la colonne COTE annuelle déjà affichée à
            // droite, elle encombrait sans ajouter d'info).
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

    // ── RECAPITULATIF DISCIPLINES / RESULTATS DE L'ELEVE ──────────
    pdf_fill($pdf, 'entete_bleu');
    $pos1 = $pdf->GetY() + 10;
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->RoundedRect(5, $pos1 - 9.4, 61, 6, 6, 'DF');
    $pdf->RoundedRect(66.6, $pos1 - 9.4, 137.4, 6, 6, 'DF');
    $pdf->Text(10, $pos1 - 6.3, 'RECAPITULATIF DISCIPLINES');
    $pdf->Text(113, $pos1 - 6.3, "RESULTATS DE L'ELEVE");
    $pdf->SetFont('Arial', 'I', 9);
    $pdf->Text(22, $pos1 - 4, 'Summary Subjects');
    $pdf->Text(118, $pos1 - 4, 'Student results');

    // 🐛 Ex-bug : filtrait par l'ex-$id_annee (toujours 0, voir plus haut) sur
    // une colonne `trimestre.id_annee` qui est en réalité un VARCHAR
    // (val_annee) — ne matchait jamais rien, $id_trims était toujours vide,
    // toute la colonne "Rappel" TRIM1/TRIM2/TRIM3 restait blanche dans le
    // PDF (vérifié : texte extrait du PDF réel avant correction). Remplacé
    // par trimestres_de_annee() (notes_apc.php, déjà mémoïsée).
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

    // Hauteur de ligne EXPLICITE (contrairement au legacy qui truque une
    // hauteur de ligne table_gd() minuscule — 1.3mm — en la gonflant avec
    // des chaînes de labels remplies d'espaces qui se mettent à la ligne :
    // fragile, dépend du rendu exact de NbLines(). Ici la hauteur de ligne
    // est le SEUL paramètre qui contrôle l'espacement, les cases à cocher
    // sont positionnées par calcul à partir du même $tbl_y0/$row_h — donc
    // toujours alignées avec leur ligne, quelle que soit la police/les
    // libellés.
    // Agrandie le 21/08/2026 (36/7≈5.14 → 6.6mm) : demande explicite de
    // porter sur le bulletin annuel le même traitement bilingue (FR gras
    // en haut de ligne / EN italique en bas) déjà fait sur le bulletin
    // trimestriel — la ligne de 5.14mm d'origine ne laissait aucune place
    // pour une 2e ligne de texte. Le bloc MOYENNE ANNUELLE/RANG/
    // APPRECIATION à droite (cf. plus bas) est agrandi en proportion pour
    // rester à la même hauteur totale que les 7 lignes de ce tableau
    // ($row_h*7).
    $row_h = 6.6;
    $tbl_y0 = $pos1 - 2;
    // Case à cocher centrée verticalement dans la ligne (taille 3mm, voir
    // pdf_case_ann) — plus de légende à réserver en dessous.
    $case_y = fn(int $i): float => $tbl_y0 + $i * $row_h + ($row_h - 3.0) / 2;

    foreach ([0, 1, 2] as $i) {
        $x = 36.5 + $i * 8;
        $mt = mention_travail($r_trim[$i]['moyenne'], $jours_trim[$i]);
        pdf_case_ann($pdf, $r_trim[$i]['moyenne'] !== null && $mt['blame_conduite'], $x, $case_y(2));
        pdf_case_ann($pdf, $r_trim[$i]['moyenne'] !== null && $mt['blame'], $x, $case_y(3));
        pdf_case_ann($pdf, $r_trim[$i]['moyenne'] !== null && $mt['tableau_honneur'], $x, $case_y(4));
        pdf_case_ann($pdf, $r_trim[$i]['moyenne'] !== null && $mt['encouragement'], $x, $case_y(5));
        pdf_case_ann($pdf, $r_trim[$i]['moyenne'] !== null && $mt['felicitations'], $x, $case_y(6));
    }
    $m_ann = mention_travail($resultat['moyenne'], $jours_ann);
    pdf_case_ann($pdf, $resultat['moyenne'] !== null && $m_ann['blame_conduite'], 60.7, $case_y(2));
    pdf_case_ann($pdf, $resultat['moyenne'] !== null && $m_ann['blame'], 60.7, $case_y(3));
    pdf_case_ann($pdf, $resultat['moyenne'] !== null && $m_ann['tableau_honneur'], 60.7, $case_y(4));
    pdf_case_ann($pdf, $resultat['moyenne'] !== null && $m_ann['encouragement'], 60.7, $case_y(5));
    pdf_case_ann($pdf, $resultat['moyenne'] !== null && $m_ann['felicitations'], 60.7, $case_y(6));

    $pdf->SetFont('Arial', 'B', 7);
    $pdf->Text(34, $tbl_y0 + $row_h - 0.7, 'TRIM1 TRIM2 TRIM3    AN');
    $pdf->SetFont('Arial', 'B', 6.2);
    $header2 = ['', '', '', '', ''];
    $w2 = [29, 8, 8, 8, 8];
    $al2 = ['L', 'C', 'C', 'C', 'C'];
    // Libellés de la colonne 0 mis à blanc — demande du 21/08/2026 :
    // bilingue FR (gras)/EN (italique dessous), même traitement que
    // pdf/bulletin_trimestriel.php. Redessinés en overlay juste après
    // l'appel table_gd() (voir plus bas).
    $lblA    = ['Absences (en J)', 'Blâme conduite', 'Blâme travail', "Tableau d'Honneur", 'Encouragements', 'Félicitations'];
    $lblA_en = ['Absences (in days)', 'Conduct reprimand', 'Work reprimand', 'Honour roll', 'Encouragement', 'Congratulations'];
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

    // ── Récapitulatif des séquences (droite) — mêmes lignes/hauteur que
    // le tableau de gauche pour que les 2 tables restent alignées ──────
    $fmt = fn(?float $v): string => $v === null ? '' : sprintf('%05.2f', $v);
    $header3 = ['', '', '', '', ''];
    $w3 = [23.8, 16.8, 16.8, 16.8, 16.8];
    $al3 = ['L', 'C', 'C', 'C', 'C'];
    $lblB    = ['Total des Points', 'Moyenne', 'Rang', 'Moy Gén Classe', 'Moy du 1er', 'Moy dernier'];
    $lblB_en = ['Total points', 'Average', 'Rank', 'Class average', "Top student's average", "Last student's average"];
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
    $pdf->Text(142, $tbl_y0 + $row_h - 0.7, 'ANN.' . ' /' . (int) $T_baremeAnn);

    $pdf->SetXY(66.6, $tbl_y0);
    $pdf->SetFont('Arial', 'B', 7.8);
    $pdf->table_gd($header3, $w3, $al3, $datas3, $row_h);

    // Overlay bilingue des libellés des 2 tableaux (FR gras en haut de
    // ligne, EN italique en bas) — demande du 21/08/2026, même traitement
    // que pdf/bulletin_trimestriel.php. Lignes 1 à 6 (ligne 0 = espaceur
    // sous l'en-tête TRIM1/TRIM2/TRIM3/AN).
    for ($i = 1; $i <= 6; $i++) {
        $rowY = $tbl_y0 + $i * $row_h;
        foreach ([[5, $lblA, $lblA_en], [66.6, $lblB, $lblB_en]] as [$colX, $fr, $en]) {
            $pdf->SetFont('Arial', 'B', 7.5);
            $pdf->Text($colX + 1, $rowY + 2.3, pdf_u($fr[$i - 1]));
            $pdf->SetFont('Arial', 'I', 5.8);
            $pdf->Text($colX + 1, $rowY + 5.2, pdf_u($en[$i - 1]));
        }
    }

    // Bloc MOYENNE ANNUELLE/RANG/APPRECIATION — bilingue (FR gras en haut
    // de cellule / EN italique en bas, centré) et cellules rehaussées
    // (5.3→7.2mm) — demande du 21/08/2026, même traitement que
    // pdf/bulletin_trimestriel.php. Hauteur totale calée sur les 2
    // tableaux de gauche (6 cellules + 5 interlignes de 0.6 = 7*$row_h)
    // pour que les 3 blocs se terminent au même niveau.
    $moy_gen = $resultat['moyenne'];
    $H_ANN = (7 * $row_h - 5 * 0.6) / 6;
    $rtA1 = $pos1 - 1;
    $rtA2 = $rtA1 + $H_ANN + 0.6;
    $rtA3 = $rtA2 + $H_ANN + 0.6;
    $rtA4 = $rtA3 + $H_ANN + 0.6;
    $rtA5 = $rtA4 + $H_ANN + 0.6;
    $rtA6 = $rtA5 + $H_ANN + 0.6;
    $enCentre = function (string $txtEn, float $rt) use ($pdf, $H_ANN): void {
        $pdf->SetFont('Arial', 'I', 7);
        $txt = pdf_u($txtEn);
        $pdf->Text(158.1 + (46 - $pdf->GetStringWidth($txt)) / 2, $rt + $H_ANN - 1, $txt);
    };

    $pdf->SetXY(158.1, $rtA1);
    pdf_fill($pdf, 'cellule_resultat');
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(46, $H_ANN, 'MOYENNE ANNUELLE', 1, 1, 'C', 1);
    $enCentre('ANNUAL AVERAGE', $rtA1);

    pdf_fill($pdf, 'entete_bleu');
    $pdf->SetXY(158.1, $rtA2);
    $pdf->SetFont('Arial', 'B', 14);
    // pdf_u() indispensable ici : FPDF n'affiche que du Windows-1252, un
    // « — » UTF-8 brut passé à Cell() sans conversion s'affiche en
    // caractères parasites (« â€" ») — bug trouvé le 26/08/2026.
    $pdf->Cell(46, $H_ANN, ($moy_gen !== null ? sprintf('%05.2f', $moy_gen) : pdf_u('—')) . ' / 20', 1, 1, 'C', 1);

    $pdf->SetXY(158.1, $rtA3);
    pdf_fill($pdf, 'cellule_resultat');
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(46, $H_ANN, 'RANG', 1, 1, 'C', 1);
    $enCentre('RANK', $rtA3);

    pdf_fill($pdf, 'entete_bleu');
    $pdf->SetXY(158.1, $rtA4);
    $pdf->Cell(46, $H_ANN, ' ', 1, 1, 'C', 1);

    $pdf->SetXY(158.1, $rtA5);
    pdf_fill($pdf, 'cellule_resultat');
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(46, $H_ANN, 'APPRECIATION', 1, 1, 'C', 1);
    $enCentre('APPRECIATION', $rtA5);

    pdf_fill($pdf, 'entete_bleu');
    $pdf->SetXY(158.1, $rtA6);
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(46, $H_ANN, pdf_u(appreciation_moyenne($moy_gen)), 1, 1, 'C', 1);

    // Centrage horizontal ET vertical du rang dans sa cellule (158.1,
    // $rtA4, largeur 46, hauteur $H_ANN) — demande du 21/08/2026, même
    // correctif que pdf/bulletin_trimestriel.php : l'ancien SetXY(172.5,
    // $pos1+19.5) fixe ne centrait que par coïncidence pour une largeur de
    // texte précise, et décalait dès que le rang/l'effectif changeait de
    // nombre de chiffres. Largeur calculée avec les 2 tailles de police
    // réellement utilisées (14pt pour le rang/effectif, 12pt pour
    // l'exposant "e" dessiné par subWrite), puis x centré dessus.
    [$rp, $rs] = rang_prefixe_suffixe_ann((string) ($resultat['rang'] ?? ''));
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

    // ── Décision de fin d'année + observations ────────────────────
    // SetY explicite (au lieu d'un Ln() relatif au curseur courant) : les 2
    // tableaux du récapitulatif + le bloc MOYENNE ANNUELLE n'avancent pas
    // tous le curseur à la même hauteur — on repart du point le plus bas
    // des trois pour ne jamais chevaucher le récapitulatif.
    pdf_fill($pdf, 'groupe_competence');
    $bas_recap = max($tbl_y0 + 7 * $row_h, $pdf->GetY());
    $pdf->SetY($bas_recap + 4);
    $pos2 = $pdf->GetY();
    $pdf->RoundedRect(5, $pos2, 199, 6, 6, 'DF');
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Text(23, $pos2 + 2.8, "DECISION  DES CONSEILS DE FIN D'ANNEE");
    $pdf->Text(120, $pos2 + 2.8, "OBSERVATIONS DU CHEF D'ETABLISSEMENT");
    $pdf->SetFont('Arial', 'I', 8);
    $pdf->Text(30, $pos2 + 5.2, 'Decision of the class advice for end year');
    $pdf->Text(145, $pos2 + 5.2, "Principal's remarks");

    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Text(17, $pos2 + 13, 'PROMU(E) EN ');
    $pdf->Image(__DIR__ . '/../assets/img/pdf/case_a_cocher.jpg', 11, $pos2 + 9.7, 4, 4);
    $pdf->Text(17, $pos2 + 20, 'REDOUBLE LA ');
    $pdf->Image(__DIR__ . '/../assets/img/pdf/case_a_cocher.jpg', 11, $pos2 + 16.7, 4, 4);
    $pdf->SetFont('Arial', 'I', 9);
    $pdf->Text(42, $pos2 + 13, ' / Promoted to : ..............................');
    $pdf->Text(43, $pos2 + 20, ' / Repeat       : ..............................');

    $pdf->Line(107.2, $pdf->GetY(), 107.2, 289);

    $pdf->RoundedRect(5, $pos2 + 32, 101, 6, 6, 'DF');
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Text(16, $pos2 + 34.7, "OBSERVATIONS ET SIGNATURE DE L'ENSEIGNANT");
    $pdf->SetFont('Arial', 'I', 9);
    $pdf->Text(30, $pos2 + 37.3, ' Teacher remarks and signature');

    $pdf->SetFont('Arial', 'B', 8);
    $lieu = $etab['lieu'] ?: $etab['ville'];
    $pdf->Text(150, $pos2 + 11, pdf_u($lieu . ', le  ' . date('d-m-Y') . '.'));
    $pdf->SetFont('Arial', 'I', 7);
    $pdf->Text(150 + 1.7 * mb_strlen($lieu), $pos2 + 14, 'On');
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Text(148, $pos2 + 21, pdf_u($etab['chef_etablissement'] ?: 'LE DIRECTEUR'));
    $pdf->SetFont('Arial', 'I', 9);
    $pdf->Text(148, $pos2 + 24, pdf_u($etab['chef_etablissement_en'] ?: 'THE DIRECTOR'));

    if ($avec_sig) {
        pdf_signature_appliquer_jn($pdf, 'bulletin_annuel', 148, $pos2 + 21, 46, 10, [
            'x_pct' => 0, 'y_pct' => 30, 'w_pct' => 80, 'h_pct' => null,
        ]);
    }

    // Fichier mis en cache disque (voir bulletin_qr_fichier_temp() dans
    // pdf/verif_lib.php) — ne PAS le supprimer après usage, contrairement à
    // un fichier temporaire classique.
    // Position FIXE (absolue, ancrée sur le bas de page via $ph) — demande
    // du 21/08/2026, même correctif que pdf/bulletin_trimestriel.php : le
    // QR ne doit plus dépendre de $pos2 (position variable selon le nombre
    // de groupes de compétences/lignes récapitulatives au-dessus).
    $qr_tmp = bulletin_qr_fichier_temp($eleve, 'annee', $id_annee);
    if ($qr_tmp) {
        $pdf->Image($qr_tmp, 99, $ph - 28, 16, 16, 'PNG');
    }

    // Copyright standard du système (pdf/header_pdf.php) — texte unique sur
    // tous les PDF du projet, voir pdf_copyright().
    pdf_copyright($pdf, $pw, $ph);

    $pdf->SetTextColor(0, 0, 0);
}

// Préchargement en masse (optimisation, voir notes_apc.php) : évite les
// milliers de requêtes individuelles que note_competence_trimestre()/
// rang_eleve_competence_annuelle() feraient sinon pour une classe entière
// (49 élèves × 13 compétences × 3 trimestres ⇒ minutes au lieu de secondes,
// constaté en conditions réelles avant ce correctif).
precharger_notes_sequence_classe((int) $liste[0]['id_classe'], $val_annee);
// Idem pour evaluation_annulee (21/08/2026, même trouvaille que pour
// pdf/bulletin_trimestriel.php) : rang_eleve_competence_annuelle()/
// note_competence_annuelle() bouclent sur tous les élèves × 3 trimestres ×
// séquences, chacun interrogeant cette table au moins une fois.
precharger_evaluations_annulees_annee($val_annee);
// Idem pour eleve/statut/absences des 3 trimestres — trouvé le 21/08/2026,
// même traque que pdf/bulletin_trimestriel.php : dessiner_bulletin_annuel()
// requêtait ces données individuellement PAR ÉLÈVE (et par trimestre pour
// les absences), alors qu'une seule requête par table/trimestre suffit
// pour toute la classe.
precharger_eleves_classe((int) $liste[0]['id_classe'], $val_annee);
precharger_statuts_classe((int) $liste[0]['id_classe'], $val_annee);
foreach (trimestres_de_annee($val_annee) as $__id_trim) {
    precharger_absences_classe_trim((int) $liste[0]['id_classe'], $__id_trim, $val_annee);
}

// Enveloppé dans un try/catch : accessible publiquement via le QR du
// bulletin (voir $acces_public plus haut) — un incident technique (ex.
// image en cache corrompue/incomplète) ne doit jamais renvoyer un fatal
// error brut à un visiteur anonyme. Voir fonctions.php::pdf_erreur_generation().
try {
$pdf = new FPDF('P', 'mm', 'A4');
$pdf->SetAutoPageBreak(false, 0);

foreach ($liste as $e) {
    dessiner_bulletin_annuel(
        $pdf, $e['id_eleve'], $e['id_classe'], $e['classe_nom'], $val_annee, $id_annee,
        $etab, $pays_fr, $pays_en, $dept_fr, $arr_fr, $div_en, $sub_en, $effectif_classe, $avec_sig
    );
}

$nom_fichier = $id ? ('bulletin_annuel_' . $liste[0]['id_eleve'] . '.pdf') : ('bulletins_annuels_' . $liste[0]['classe_nom'] . '.pdf');
$pdf->Output($dl ? 'D' : 'I', $nom_fichier);
} catch (Throwable $e) {
    pdf_erreur_generation($e);
}
