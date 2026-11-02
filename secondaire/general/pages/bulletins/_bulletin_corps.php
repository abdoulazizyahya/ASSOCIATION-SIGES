<?php
// Rendu du bulletin trimestriel APC d'UN élève ($_GET['eleve'] ou $id_eleve_bulletin),
// inclus par pdf.php une fois par élève : bulletin individuel, ou toute la
// classe (même document exactement, 30/10/2026). Ajoute ses pages à $pdf.
// ── Paramètres ────────────────────────────────────────────────────
$id_eleve = (int)($id_eleve_bulletin ?? ($_GET['eleve'] ?? 0));
$id_trim  = (int)($_GET['trim']  ?? 0);
$id_seq_u = (int)($_GET['seq']   ?? 0);
$id_annee = (int)($_GET['annee'] ?? 0);
// Mise en page (choix de l'utilisateur, bouton à côté de l'ordre dans
// secondaire/general/pages/bulletins/index.php) : 'page' = tout forcé sur UNE page
// (police et espacements réduits au besoin, comportement historique) ;
// 'lisible' = police et espacements confortables, sur 2 pages au maximum.
$lisible = ($_GET['mise'] ?? '') === 'lisible';

if (!$id_eleve) die('Parametre eleve manquant.');

$eleve = db_one("SELECT * FROM eleve WHERE id=?", [$id_eleve]);
if (!$eleve) die('Eleve introuvable.');

$annee     = db_one("SELECT * FROM annee_scolaire WHERE id=?", [$id_annee]);
$val_annee = $annee['libelle'] ?? '';

$insc = db_one(
    "SELECT i.*, c.designation AS classe, c.id AS id_classe
     FROM inscription i JOIN classe c ON c.id=i.id_classe
     WHERE i.id_eleve=? AND i.id_annee=?",
    [$id_eleve, $id_annee]
);
if (!$insc) die('Inscription introuvable.');
bulletin_fixer_langue((int) $insc['id_classe']);   // section anglophone : anglais / traduction française

// ── Trimestre à afficher ────────────────────────────────────────────
// Chantier APC (voir prompt_continuite_ABZ_MBE_1.md) : le bulletin est
// désormais toujours scopé au trimestre entier, les compétences n'ayant
// pas d'équivalent "séquence unique". Le paramètre ?seq= n'est conservé
// que pour résoudre le trimestre depuis un ancien lien (compatibilité),
// et pour construire l'URL de vérification QR historique (voir §8).
$is_seq = ($id_seq_u > 0);
if ($is_seq) {
    $id_trim = (int) db_val("SELECT id_trim FROM sequence WHERE id=?", [$id_seq_u]) ?: $id_trim;
}
$trim_info = db_one("SELECT * FROM trimestre WHERE id=?", [$id_trim]);
if (!$trim_info) die('Trimestre introuvable.');
$titre_bull = strtoupper($trim_info['libelle'] ?? '');

// ── Établissement ─────────────────────────────────────────────────
$etab = get_etablissement();

// ── Prof principal ────────────────────────────────────────────────
$pp = db_one(
    "SELECT e.nom_ens, e.prenom_ens FROM enseignat_principal ep
     JOIN enseignant e ON e.matricule_ens=ep.matricule_ens
     WHERE ep.IDClasses=? AND ep.val_annee=? LIMIT 1",
    [$insc['id_classe'], $val_annee]
);
$nom_pp = $pp ? trim($pp['nom_ens'] . ' ' . ($pp['prenom_ens'] ?? '')) : '-';

// ── Tuteur ────────────────────────────────────────────────────────
$tuteur = db_one("SELECT * FROM tuteur WHERE id_eleve=? LIMIT 1", [$id_eleve]);
$contacts_parents = $tuteur
    ? trim(($tuteur['nom'] ?? '') . ' ' . ($tuteur['prenom'] ?? '') . ' - ' . ($tuteur['telephone'] ?? ''))
    : '';

// ── Disciplines ───────────────────────────────────────────────────
$disciplines = db_all(
    "SELECT d.id_mat, d.coef, d.ordre, m.libelle AS matiere, m.libelle_en AS matiere_en,
            g.libelle_groupe_comp AS groupe_lib, d.id_groupe,
            TRIM(CONCAT(e.nom_ens,' ',COALESCE(e.prenom_ens,''))) AS enseignant
     FROM discipline d
     JOIN matiere m ON m.id=d.id_mat AND m.actif=1
     LEFT JOIN groupe g ON g.id_groupe_comp=d.id_groupe
     LEFT JOIN dispenser disp ON disp.id_mat=d.id_mat AND disp.IDClasses=d.IDClasses AND disp.val_annee=?
     LEFT JOIN enseignant e ON e.matricule_ens=" . sec_sql_enseignant_trimestre($val_annee, (int) db_val("SELECT ordre FROM trimestre WHERE id=?", [(int) $id_trim])) . "   -- enseignant DE CE TRIMESTRE (remplacements, v22)
     WHERE d.IDClasses=?
     ORDER BY d.id_groupe, d.ordre, m.libelle",
    [$val_annee, $insc['id_classe']]
);

// ── Élèves inscrits dans la classe ───────────────────────────────
$all_enrolled = db_all(
    "SELECT e.id FROM eleve e
     JOIN inscription i ON i.id_eleve=e.id AND i.id_classe=? AND i.id_annee=?
     WHERE e.statut='actif'",
    [$insc['id_classe'], $id_annee]
);
$enrolled_ids = array_column($all_enrolled, 'id');
$nb_inscrits  = count($enrolled_ids);
sec_series_charger((int)$insc['id_classe'], $id_annee); // classe mixte : matières par série

// ── Compétences du trimestre, par matière ───────────────────────────
// Une compétence est définie une seule fois par niveau (voir chantier APC) :
// scopée par matière + niveau de la classe + trimestre, nombre variable.
$code_niveau = db_val("SELECT code_niveau FROM classe WHERE id=?", [$insc['id_classe']]);
$competences_par_mat = []; // [id_mat] => [ {id, libelle, ordre}, ... ]
foreach ($disciplines as $d) {
    $competences_par_mat[$d['id_mat']] = db_all(
        "SELECT * FROM competence WHERE id_matiere=? AND code_niveau=? AND id_trim=? ORDER BY ordre",
        [$d['id_mat'], $code_niveau, $id_trim]
    );
    // Section anglophone : intitulé anglais de la compétence s'il est saisi
    // (Matières → Compétences), sinon l'intitulé saisi.
    if (bulletin_en()) {
        foreach ($competences_par_mat[$d['id_mat']] as &$c_en) {
            if (trim((string) ($c_en['libelle_en'] ?? '')) !== '') $c_en['libelle'] = $c_en['libelle_en'];
        }
        unset($c_en);
    }
}
$all_comp_ids = [];
foreach ($competences_par_mat as $comps) { foreach ($comps as $c) { $all_comp_ids[] = (int)$c['id']; } }

// ── Notes brutes (par compétence) ───────────────────────────────────
$all_notes_raw = [];
if ($all_comp_ids) {
    $in_c = implode(',', array_fill(0, count($all_comp_ids), '?'));
    $all_notes_raw = db_all(
        "SELECT n.id_eleve, n.id_matiere, n.id_competence, n.valeur
         FROM note n
         JOIN inscription i ON i.id_eleve=n.id_eleve AND i.id_annee=? AND i.id_classe=?
         JOIN eleve el ON el.id=n.id_eleve AND el.statut='actif'
         WHERE n.id_competence IN ($in_c)",
        array_merge([$id_annee, $insc['id_classe']], $all_comp_ids)
    );
}
// notes_idx[eid][id_comp] = valeur
$notes_idx = [];
foreach ($all_notes_raw as $row) {
    $notes_idx[(int)$row['id_eleve']][(int)$row['id_competence']] = (float)$row['valeur'];
}
$abs_just = []; // plus de justification par compétence pour l'instant (voir §Phase 6)

// ── Nombre de notes par compétence (seuil 50% "absent = 0") ────────
$notes_count = []; // [id_comp] = nb élèves avec note
foreach ($all_notes_raw as $row) {
    $c = (int)$row['id_competence'];
    $notes_count[$c] = ($notes_count[$c] ?? 0) + 1;
}

// ── Fonctions helpers ─────────────────────────────────────────────

// Moyenne d'une matière (groupe de compétence) pour un élève = moyenne
// arithmétique simple des notes de ses compétences évaluées ce trimestre
// (même algorithme que DANFILI Note_Moy_trim_par_Gpe_comp() — voir analyse
// du 03/08/2026 dans le prompt de continuité).


// ── Décisions du conseil de classe (fournies par l'utilisateur) ────
// Les fonctions Tableau_honneur()/Encouragement()/Felicitation()/
// Avertissement_travail()/Blame_travail()/Avertissement_Conduite_eleve()/
// Blame_Conduite_eleve() ont été déplacées dans fonctions.php (préfixe
// bulletin_*), avec leurs seuils désormais lus dans
// reglage_mention_bulletin (configurable par l'administrateur, voir
// secondaire/commun/pages/parametres/index.php onglet "Mentions") au lieu d'être codés en
// dur — voir section 5 plus bas pour l'appel (get_reglage_mention_bulletin()
// + $heur_jus/$heur_nj lus via eleve_absence_trimestre()).

// ── Matières avec au moins une compétence notée (pour seuil 50% classement) ──
$mats_avec_notes = [];
foreach ($disciplines as $d) {
    foreach ($competences_par_mat[$d['id_mat']] ?? [] as $c) {
        if (($notes_count[(int)$c['id']] ?? 0) > 0) { $mats_avec_notes[] = $d['id_mat']; break; }
    }
}
$nb_mats_avec_notes = count($mats_avec_notes);

// Calcul moyenne+classement d'un élève : retourne [moy, est_classe, coef_total]

// Moyennes classe (pour rang)
$all_moys = [];
foreach ($enrolled_ids as $eid) {
    [$m, $ok] = moy_eleve_bull($eid, $disciplines, $competences_par_mat, $notes_idx, $notes_count, $nb_inscrits, $mats_avec_notes, $nb_mats_avec_notes);
    if ($ok && $m !== null) $all_moys[$eid] = $m;
}
arsort($all_moys);

// Données élève courant
[$moy_generale, $est_classe, $total_coef_eleve] = moy_eleve_bull($id_eleve, $disciplines, $competences_par_mat, $notes_idx, $notes_count, $nb_inscrits, $mats_avec_notes, $nb_mats_avec_notes);
$rang_general = null;
if ($est_classe && $moy_generale !== null) {
    $rang_general = 1;
    foreach ($all_moys as $m) { if ($m > $moy_generale) $rang_general++; else break; }
}
$moy_premier   = !empty($all_moys) ? max($all_moys) : null;
$moy_dernier   = !empty($all_moys) ? min($all_moys) : null;
$moy_classe    = !empty($all_moys) ? array_sum($all_moys) / count($all_moys) : null;
$nb_classes    = count($all_moys);
$nb_admis      = count(array_filter($all_moys, fn($m) => $m >= 10));
$taux_reussite = $nb_classes > 0 ? round($nb_admis / $nb_classes * 100, 2) : 0;

// Stats par matière (min/avg/max/rang) — moyenne de chaque élève sur les
// compétences de cette matière ce trimestre.
$mat_stats = [];
foreach ($disciplines as $d) {
    $id_mat = $d['id_mat'];
    $avgs = [];
    foreach ($enrolled_ids as $eid) {
        $avg = mat_avg_comp($eid, $competences_par_mat[$id_mat] ?? [], $notes_idx, $notes_count, $nb_inscrits);
        if ($avg !== null) $avgs[] = $avg;
    }
    if (empty($avgs)) {
        $mat_stats[$id_mat] = ['min' => null, 'avg' => null, 'max' => null, 'avgs_sorted' => []];
    } else {
        rsort($avgs);
        $mat_stats[$id_mat] = ['min' => min($avgs), 'avg' => array_sum($avgs) / count($avgs), 'max' => max($avgs), 'avgs_sorted' => $avgs];
    }
}
// Classe mixte : le bulletin n'affiche que les matières que l'élève suit
// (communes + celles de sa série) — les calculs de classe ci-dessus sont faits.
$disciplines = array_values(array_filter($disciplines, fn($d) => !sec_hors_serie($id_eleve, (int)$d['id_mat'])));

// Le tableau "Rappel Eval1/Eval2" de l'ancien système par séquences n'a pas
// d'équivalent en compétences (pas de sous-période au sein d'un trimestre) —
// supprimé, voir §RESULTATS DE L'ELEVE plus bas.

// ── Génération PDF ────────────────────────────────────────────────
// Un seul document pour toute la classe : créé au premier élève.
if (!isset($pdf)) {
    $pdf = new PDF_LTM('P', 'mm', 'A4');
    $pdf->SetMargins(8, 8, 8);
}
// Marge de saut de page alignée sur le cadre extérieur (4mm) — la mention
// copyright est dessinée volontairement tout en bas, juste au-dessus du
// cadre ; avec l'ancienne marge de 8mm, FPDF déclenchait un saut de page
// automatique dès qu'on dessinait quoi que ce soit sous cette limite,
// envoyant le copyright seul sur une 2e page (bug corrigé).
$pdf->SetAutoPageBreak(true, 4);
$pdf->AddPage();
$pw = $pdf->GetPageWidth();
$ph = $pdf->GetPageHeight();
$ml = 8; $mr = 8;
$uw = $pw - $ml - $mr; // 194

// QR code de vérification d'authenticité : position FIXE sur chaque page,
// centré, son bas collé à la ligne du copyright ($ph - 9), dessiné EN DERNIER
// (au premier plan, par-dessus tableaux et textes — demande explicite). Il ne
// réserve aucune place dans la mise en page. Photo de
// l'élève incrustée au centre (le niveau de correction d'erreur qr-h tolère
// ~30% de perte, une photo sur ~20% de la surface reste donc scannable).
require_once __DIR__ . '/../../../commun/pdf/qrcode.php';
$qr_taille = 20;
$y_qr      = ($ph - 9) - $qr_taille;   // bas du QR collé à la ligne du copyright
$qr_photo  = !empty($eleve['photo']) ? photo_sec_fichier_tmp((int) $eleve['id']) : '';
if (!$qr_photo || !is_file($qr_photo)) {
    $qr_photo = __DIR__ . '/../../../../assets/img/avatars/' . ((strtoupper($eleve['sexe'] ?? '') === 'F') ? 'fille.png' : 'garcon.png');
}
$qr_base = tempnam(sys_get_temp_dir(), 'abzqr_');
$qr_tmp  = $qr_base . '.png';
register_shutdown_function(function () use ($qr_base, $qr_tmp) {
    foreach ([$qr_base, $qr_tmp] as $fic) { if (is_file($fic)) @unlink($fic); }
});
$qr_img = (new QRCode(bulletin_verif_url(
    $id_eleve,
    $is_seq ? 'seq' : 'trim',
    $is_seq ? $id_seq_u : $id_trim,
    id_affichage_eleve($eleve)
), ['s' => 'qr-h']))->render_image();   // qr-h = correction d'erreur élevée (impression papier)
qr_incruster_photo($qr_img, $qr_photo);
imagepng($qr_img, $qr_tmp);
imagedestroy($qr_img);

// Habillage de chaque page : filigrane + cadre extérieur à coins arrondis
// (comme le modèle papier) au début, copyright (+ tampon AUTHENTIQUE si
// ouvert depuis le QR) à la fin. En mode lisible, une 2e page reçoit le
// même habillage (sauts de page gérés à la main, voir section 4).
$decor_page = function () use ($pdf, $etab, $pw, $ph) {
    pdf_filigrane($pdf, $etab, $pw, $ph);
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(0.4);
    $pdf->RoundedRect(4, 4, $pw - 8, $ph - 8, 4, 'D');
    $pdf->SetLineWidth(0.2);
};
$fin_page = function () use ($pdf, $ml, $uw, $pw, $ph, $acces_public, $qr_tmp, $qr_taille, $y_qr) {
    // QR au premier plan : dessiné après tout le contenu de la page.
    $pdf->Image($qr_tmp, ($pw - $qr_taille) / 2, $y_qr, $qr_taille, $qr_taille, 'PNG');
    $pdf->SetFont('Arial', '', 6.5);
    $pdf->SetXY($ml, $ph - 9);
    $pdf->Cell($uw, 4, u('Copyright © SIGES ABZ   , E-mail: abdoulazizyahya@gmail.com'), 0, 1, 'C');
    // Ouvert depuis le scan du QR code : tampon « AUTHENTIQUE » comme sur les
    // reçus, redessiné PAR-DESSUS le bulletin (lettres creuses) — celui posé
    // en fond par pdf_filigrane() serait caché par les cases remplies.
    if ($acces_public) {
        pdf_tampon_authentique($pdf, $pw / 2, $ph * 0.52, $pw * 0.80, 'AUTHENTIQUE', true);
    }
};
$decor_page();
if ($lisible) {
    $pdf->SetAutoPageBreak(false);   // sauts de page décidés à la main (une matière n'est jamais coupée)
    $pdf->SetDisplayMode('real');    // ouverture à 100 %
}

// ── 1. En-tête bilingue ──────────────────────────────────────────
$col3 = $uw / 3;
$y0   = 8;

// Section anglophone : bloc anglais à gauche, bloc français à droite.
$entete_fr = "REPUBLIQUE DU CAMEROUN\nPaix - Travail - Patrie\n***************\n" .
    ($etab['region_fr'] ?? "VOTRE REGION ICI") . "\n" .
    ($etab['departement_fr'] ?? 'DEPARTEMENT ') . "\n" .
    ($etab['arrondissement_fr'] ?? 'ARRONDISSEMENT') . "\n" .
    strtoupper($etab['nom_fr'] ?? 'NOM ECOLE ') . "\n" .
    "B.P. " . ($etab['boite_postale'] ?? 'XX') . " ville Tel.: " . ($etab['telephone'] ?? '') . "\n" .
    ($etab['email'] ?? 'adresse email');

// Logo agrandi et centré horizontalement ET verticalement dans la bande du
// milieu (demande explicite, était calé en haut) — hauteur réelle déduite du
// ratio de l'image pour un centrage vertical exact quel que soit son format.
$logo_path = !empty($etab['logo']) ? __DIR__ . '/../../../../assets/uploads/' . $etab['logo'] : '';
$logo_bande_h = 21; // hauteur du bloc en-tête (y0=8 à 29, voir plus bas)
$logo_w = 28;
$logo_h = $logo_w;
if ($logo_path && is_file($logo_path)) {
    $dim = @getimagesize($logo_path);
    if ($dim && $dim[0] > 0) $logo_h = $logo_w * $dim[1] / $dim[0];
}
$logo_x = $ml + $col3 + ($col3 - $logo_w) / 2;
$logo_y = $y0 + ($logo_bande_h - $logo_h) / 2;
if ($logo_path && is_file($logo_path)) {
    // (logo dessiné plus bas, une fois la position de l'immatriculation connue)
} else {
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetXY($logo_x, $y0 + ($logo_bande_h - 16) / 2);
    $pdf->Cell($logo_w, 16, u($etab['sigle'] ?? ''), 1, 0, 'C');
}

$entete_en = "REPUBLIC OF CAMEROON\nPeace - Work - Fatherland\n***************\n" .
    ($etab['region_en'] ?? '') . "\n" .
    ($etab['division_en'] ?? '') . "\n" .
    ($etab['subdivision_en'] ?? '') . "\n" .
    strtoupper($etab['nom_en'] ?? '') . "\n" .
    "P.O. BOX. " . ($etab['boite_postale'] ?? '') . " " . ($etab['ville'] ?? '') . "  Phone: " . ($etab['telephone'] ?? '') . "\n" .
    ($etab['email'] ?? '');
[$entete_g, $entete_d] = bulletin_en() ? [$entete_en, $entete_fr] : [$entete_fr, $entete_en];
$pdf->SetXY($ml, $y0);
$pdf->SetFont('Arial', '', 6.5);
$pdf->MultiCell($col3, 3.0, u($entete_g), 0, 'C');
$xr = $ml + $col3 * 2;
$pdf->SetXY($xr, $y0);
$pdf->SetFont('Arial', '', 6.5);
$pdf->MultiCell($col3, 3.0, u($entete_d), 0, 'C');

$pdf->SetY(max($pdf->GetY(), 29));
// Logo ajusté automatiquement dans l'espace libre entre le haut de l'en-tête
// et l'immatriculation (proportions conservées) : il ne touche jamais
// l'immatriculation, même si les noms FR/EN prennent plus de lignes.
if ($logo_path && is_file($logo_path)) {
    $y_immat = $pdf->GetY();
    pdf_logo_cadre($pdf, $logo_path, $ml + $col3 + ($col3 - 34) / 2, $y0 + 0.5, 34, $y_immat - $y0 - 1.5);
    $pdf->SetY($y_immat);
}
$pdf->SetFont('Arial', 'I', 6.5);
$pdf->SetX($ml);
$pdf->Cell($uw, 3.2, u('IMMATRICULATION : ' . ($etab['immatriculation'] ?? '')), 0, 1, 'C');

// ── 2. Titre (bandeau en pilule, comme le modèle) ──────────────────
// Bandeau titre compacté (demande explicite : gagner de la place du haut
// jusqu'au bas du bulletin plutôt que de trop réduire la police du tableau
// des compétences).
$y_titre = $pdf->GetY();
$h_titre = 6;
pdf_fill($pdf, 'bandeau_titre'); // bleu très clair par défaut, personnalisable (Réglages > Couleurs bulletin)
pdf_draw($pdf, 'bordure_marque'); // bleu marine par défaut, personnalisable
$pdf->SetLineWidth(0.3);
$pdf->RoundedRect($ml, $y_titre, $uw, $h_titre, $h_titre / 2, 'FD');
// Français et anglais sur UNE seule ligne, centrée dans le bandeau
// (« BULLETIN SCOLAIRE DU TRIMESTRE 1 / First Term Report Card », 30/10/2026).
$titre_fr = 'BULLETIN SCOLAIRE DU ' . $titre_bull;
$titre_en = ([1 => 'First', 2 => 'Second', 3 => 'Third'][(int) ($trim_info['ordre'] ?? 0)] ?? '') . ' Term Report Card';
[$titre_fr, $titre_en] = bl($titre_fr, $titre_en);
$t_fr = 13; $t_en = 10.5;
do {   // réduit ensemble si la ligne dépasse le bandeau
    $pdf->SetFont('Arial', 'BI', $t_fr); $w_fr = $pdf->GetStringWidth(u($titre_fr . ' / '));
    $pdf->SetFont('Arial', 'I', $t_en);  $w_en = $pdf->GetStringWidth(u(trim($titre_en)));
    if ($w_fr + $w_en <= $uw - 12 || $t_en <= 7) break;
    $t_fr -= 0.5; $t_en -= 0.5;
} while (true);
$x_t = $ml + ($uw - $w_fr - $w_en) / 2;
$pdf->SetTextColor(26, 60, 107);
$pdf->SetXY($x_t, $y_titre);
$pdf->SetFont('Arial', 'BI', $t_fr);
$pdf->Cell($w_fr, $h_titre, u($titre_fr . ' / '), 0, 0, 'L');
$pdf->SetFont('Arial', 'I', $t_en);
$pdf->Cell($w_en, $h_titre, u(trim($titre_en)), 0, 1, 'L');
$pdf->SetTextColor(0, 0, 0);
$pdf->SetLineWidth(0.2);
$pdf->SetFont('Arial', 'B', 8.5);
$pdf->SetX($ml);
$pdf->Cell($uw, 3.8, u(bt('Année scolaire : ', 'School year: ') . $val_annee), 0, 1, 'C');

// ── 3. Infos élève ───────────────────────────────────────────────
$pdf->Ln(0.3);


// Dessine un rang ("2e/22", "2e", ...) avec le "e" ordinal en exposant
// (demande explicite : tous les rangs affichés) au lieu du "e" en taille
// normale. $rang=null dessine simplement "-". $suffixe est ce qui suit le
// "e" (ex. "/22", " / 22", ou "" si le rang est affiché seul). Le "e" est
// dessiné à ~62% de $taille et surélevé d'environ 12% de $taille (converti
// pt→mm) — ne gère PAS l'ajustement automatique de taille : passer une
// $taille déjà déterminée (ex. via cell2l_taille_ajustee() sur la version
// texte brut "2e/22", qui donne une largeur légèrement supérieure à ce qui
// sera réellement dessiné ici, donc jamais de débordement). Laisse le
// curseur à (x+$w, y) — comme un Cell(ln=0) — à l'appelant de gérer la
// suite (Ln(), SetX()...).

// Réduit par petits pas la taille de police d'un texte (dans le style donné,
// B/I/etc. — l'empattement gras/italique change la largeur réelle du texte)
// jusqu'à ce qu'il tienne dans $largeur_max, jamais en dessous de $taille_min.
// Utilisé par cell2l() pour que $taille_fr/$taille_en soient des tailles
// MAXIMALES : on peut les augmenter sans risque, chaque cellule reçoit alors
// automatiquement la plus grande taille qui tient réellement dans sa largeur.

// Cellule à 2 lignes (libellé FR en gras + traduction EN en italique, plus
// petite, juste en dessous) — reproduit le style bilingue du modèle de
// bulletin fourni par l'utilisateur (section basse : DISCIPLINES/TRAVAIL,
// PROFIL DE LA CLASSE, RESULTATS DE L'ELEVE). $en = '' pour une cellule à
// une seule ligne (centrée verticalement, ex. MOYENNE/RANG). Repositionne le
// curseur en fin de cellule comme le ferait un Cell() normal (ln=0).
// $taille_fr/$taille_en sont des MAXIMUMS (demande explicite : polices plus
// grandes mais sans jamais déborder du cadre) — réduits automatiquement si
// besoin par cell2l_taille_ajustee(), avec un plancher de lisibilité minimal.

// Photo légèrement réduite (demande explicite : gagner de la place du haut
// jusqu'au bas du bulletin plutôt que de trop réduire la police du tableau
// des compétences).
$photo_w = 20;
$photo_h = 22;
// Mode lisible : lignes d'identification plus hautes et espacées, polices
// agrandies ; la case photo suit la hauteur du bloc (photo non déformée).
$h_id  = $lisible ? 6.4 : 4.7;   // hauteur d'une ligne
$g_id  = $lisible ? 0.9 : 0.2;   // espace entre deux lignes
$f_lbl = $lisible ? 8.5 : 8;     // libellé FR (gras)
$f_en  = $lisible ? 7 : 7.5;     // libellé EN (italique)
$f_val = $lisible ? 9 : 8;       // valeurs
if ($lisible) $photo_h = 5 * $h_id + 4 * $g_id;
$x_photo = $ml;
$y_photo = $pdf->GetY();
$x_info  = $ml + $photo_w;
$w_info  = $uw - $photo_w;

// Photo de l'élève si disponible en base, sinon avatar par défaut selon le
// sexe (masculin/féminin) qui indique visuellement l'emplacement réservé à
// la photo — plus de case grise vide "PHOTO" (demande explicite).
$photo_path  = !empty($eleve['photo']) ? photo_sec_fichier_tmp((int) $eleve['id']) : '';
$photo_reelle = $photo_path && is_file($photo_path);
if (!$photo_reelle) {
    $avatar_fichier = (strtoupper($eleve['sexe'] ?? '') === 'F') ? 'fille.png' : 'garcon.png';
    $photo_path = __DIR__ . '/../../../../assets/img/avatars/' . $avatar_fichier;
}
$pdf->SetDrawColor(0, 0, 0);
$pdf->Rect($x_photo, $y_photo, $photo_w, $photo_h);
if (is_file($photo_path)) {
    if ($lisible) {
        $dim_ph = @getimagesize($photo_path);
        $h_img  = ($dim_ph && $dim_ph[0] > 0) ? min($photo_h - 1, ($photo_w - 1) * $dim_ph[1] / $dim_ph[0]) : $photo_h - 1;
        $pdf->Image($photo_path, $x_photo + 0.5, $y_photo + ($photo_h - $h_img) / 2, $photo_w - 1, $h_img);
    } else {
        $pdf->Image($photo_path, $x_photo + 0.5, $y_photo + 0.5, $photo_w - 1, $photo_h - 1);
    }
}

$gris = [230, 230, 230];
$pdf->SetFillColor(230, 230, 230);
$pdf->SetFont('Arial', 'B', 7);

$wL1 = [24, 34, 18, 14, 18, 60]; // somme = 168 = $w_info
$pdf->SetXY($x_info, $y_photo);
cell2l($pdf, 30, $h_id, 'CLASSE :', 'Class', 'L', true, $f_lbl, $f_en, $gris, true);
$pdf->SetFont('Arial', 'I', $f_val);
$pdf->Cell(45, $h_id, u($insc['classe'] ?? ''), 1, 0, 'L');
cell2l($pdf, $wL1[2], $h_id, 'EFFECTIF :', 'Size', 'L', true, $f_lbl, $f_en, $gris, true);
$pdf->SetFont('Arial', 'I', $f_val);
$pdf->Cell($wL1[3], $h_id, (string)$nb_inscrits, 1, 0, 'C');
cell2l($pdf, 41, $h_id, 'IDENTIFIANT UNIQUE (NIU) :', 'ID No.', 'L', true, $f_lbl, $f_en, $gris, true);
// Taille ajustée à la case (un NIU long ne déborde jamais).
$pdf->SetFont('Arial', 'I', cell2l_taille_ajustee($pdf, niu_eleve($eleve), 'I', 20 - 1.4, $f_val, 5));
$pdf->Cell(20, $h_id, niu_eleve($eleve), 1, 1, 'C');
$pdf->ln($g_id);
$pdf->SetX($x_info);
//$pdf->SetFont('Arial', 'B', 17);
cell2l($pdf, 30, $h_id, 'NOM ET PRENOMS :', 'Name', 'L', true, $f_lbl, $f_en, $gris, true);
//	cell2l($pdf, 26, $h_id, 'NOM ET PRENOMS :', 'Name', 'L', true, 28, 7.5, $gris, true);
$pdf->SetFont('Arial', 'BI', $lisible ? 10.5 : 9);
$pdf->Cell(110, $h_id, u(strtoupper($eleve['nom']) . ' ' . ($eleve['prenom'] ?? '')), 1, 0, 'L');
cell2l($pdf, 16, $h_id, 'GENRE :', 'Gender', 'L', true, $f_lbl, $f_en, $gris, true);
$pdf->SetFont('Arial', 'I', $f_val);
$pdf->Cell(12, $h_id, $eleve['sexe'] ?? '', 1, 1, 'C');

$pdf->ln($g_id);
$pdf->SetX($x_info);
cell2l($pdf, 18, $h_id, 'NE(E) LE :', 'Born on', 'L', true, $f_lbl, $f_en, $gris, true);
$pdf->SetFont('Arial', 'I', $f_val);
$dnaiss = $eleve['date_naiss'] ? date('d/m/Y', strtotime($eleve['date_naiss'])) : '';
$pdf->Cell(22, $h_id, $dnaiss, 1, 0, 'C');
$pdf->SetFont('Arial', 'B', $f_val);
$pdf->Cell(10, $h_id, u(bt('A/at', 'At/à')), 1, 0, 'C', true);
$pdf->SetFont('Arial', 'I', $f_val);
$pdf->Cell(64, $h_id, u($eleve['lieu_naiss'] ?? ''), 1, 0, 'L');
cell2l($pdf, 26, $h_id, 'REDOUBLANT :', 'Repeater', 'L', true, $f_lbl, $f_en, $gris, true);
$is_redoub = (strtolower($insc['statut'] ?? '') === 'redoublant');
$y_row3 = $pdf->GetY();
$x_row3 = $pdf->GetX();
$pdf->Cell(28, $h_id, '', 1, 1, 'L');
$pdf->SetFont('Arial', 'I', $f_val);
$dy3 = ($h_id - 3) / 2;
$pdf->SetXY($x_row3 + 1, $y_row3 + $dy3);
$pdf->Cell(7, 3, bt('Oui', 'Yes'), 0, 0, 'L');
checkbox_ltm($pdf, $x_row3 + 8, $y_row3 + $dy3, $is_redoub);
$pdf->SetXY($x_row3 + 15, $y_row3 + $dy3);
$pdf->Cell(7, 3, bt('Non', 'No'), 0, 0, 'L');
checkbox_ltm($pdf, $x_row3 + 23, $y_row3 + $dy3, !$is_redoub);

//$pdf->ln(20);
$pdf->SetXY($x_info, $y_row3 + $h_id + $g_id + 0.1);
//$pdf->ln();
cell2l($pdf, 30, $h_id, 'PROF PRINCIPAL :', 'Class Teacher', 'L', true, $f_lbl, $f_en, $gris, true);
$pdf->SetFont('Arial', 'I', $f_val);
$pdf->Cell(138, $h_id, u($nom_pp), 1, 1, 'L');

$pdf->ln($g_id);
$pdf->SetX($x_info);
cell2l($pdf, 42, $h_id, 'NOM ET CONTACTS DES PARENTS :', "Parent's Name & Contact", 'L', true, $f_lbl, $f_en, $gris, true);
$pdf->SetFont('Arial', '', $lisible ? 8.5 : 7);
$pdf->Cell(126, $h_id, u($contacts_parents), 1, 1, 'L');

$pdf->SetY(max($pdf->GetY(), $y_photo + $photo_h));

// ── 4. Tableau des compétences ──────────────────────────────────────
// Reproduit le modèle fourni par l'utilisateur (others/Form 1.pdf pour la
// section anglophone, others/6eme A.pdf pour la section francophone) :
// une ligne d'en-tête "COMPETENCE N: <MATIERE>" (matière = groupe de
// compétence) avec le nom de l'enseignant, suivie d'une ligne par
// compétence de cette matière (texte complet + note brute /20). La
// moyenne, le coefficient, le total pondéré, la cote, l'intervalle
// [Min-Max] de la classe et l'appréciation sont calculés une fois par
// matière et affichés sur toute la hauteur du groupe (cellules fusionnées
// dessinées manuellement, FPDF ne supportant pas le rowspan nativement).
// Remplace l'ancien regroupement ENSEIGNEMENT GÉNÉRAL/PROFESSIONNEL/AUTRES
// (absent du modèle de référence) et les colonnes par séquence.
$pdf->Ln(0.6);
$y_table0 = $pdf->GetY();
$hdr_h = 4.6;

// Espacements compactés (demande explicite, 2e passe : la police des
// compétences reste prioritaire sur l'espacement — resserrer encore plus
// le haut et le bas du bulletin plutôt que de continuer à réduire la
// police).
// Lignes du bas plus hautes (30/10/2026) : libellés FR/EN lisibles, sans chevauchement.
$h_bas = 5;
$h_ligne = 5;
$h_dec = 3.2;
$decisions = [
    ['Satisfaisant, doit persévérer', 'Satisfactory, must persevere'],
    ['Travail en baisse', 'Work falling'],
    ['Travail insuffisant', 'Insufficient work'],
    ['Attention à la conduite', 'Watch out for behavior'],
    ["Trop d'absences", 'too much absences'],
    ["Risque l'exclusion définitive", 'Risk the definitive exclusion'],
    ['Exclusion', 'Exclusion'],
];
// Mode lisible : bas du bulletin plus aéré (lignes et cases de décision plus hautes).
if ($lisible) { $h_bas = 7; $h_ligne = 6.6; $h_dec = 4.2; $hdr_h = 5.5; }
// Revenu à $ph - 13 (une tentative à $ph - 10 privait le bloc signature/date
// (offsets fixes dans la boîte OBSERVATIONS) de sa place habituelle et
// provoquait un débordement sur une 2e page presque vide).
$bas_dispo = $ph - 13; // marge bas + mention copyright (le QR, en fond, ne réserve aucune place)
$h_sig_min = 30;   // hauteur mini du cadre OBSERVATIONS : date + « LE PRINCIPAL » + signature

// Largeurs colonnes (somme = $uw = 194mm)
// Colonnes chiffrées resserrées au profit du texte des compétences (moins
// de retours à la ligne, donc plus d'interligne) — demande du 30/10/2026.
$cD      = 101; // COMPÉTENCES ÉVALUÉES / NOM DE L'ENSEIGNANT
$cN      = 11;  // N/20 (note brute de chaque compétence)
$cM      = 11;  // M/20 (moyenne de la matière, fusionnée)
$cCF     = 7;   // Coef (fusionnée)
$cMXC    = 11;  // MxC (fusionnée)
$cCOTE   = 8;   // Cote (fusionnée)
$cMINMAX = 15;  // [Min-Max] (fusionnée)
$cAPPR   = $uw - $cD - $cN - $cM - $cCF - $cMXC - $cCOTE - $cMINMAX; // Appréciations et visa

// Nombre de lignes de texte qu'occupera une compétence dans la colonne $cD
// à une taille de police donnée (simulation du retour à la ligne de FPDF).

// Taille de police + hauteur de ligne des compétences ajustées ENSEMBLE et
// dynamiquement (demande explicite : le bulletin doit tenir sur une seule
// page, quel que soit le nombre de compétences — un élève peut en avoir
// beaucoup plus qu'un autre selon la matière/le trimestre). Réduire la
// police diminue aussi le nombre de lignes nécessaires (texte plus étroit
// par ligne), donc les deux leviers agissent dans le même sens — on réduit
// par petits pas jusqu'à obtenir une hauteur de ligne cohérente avec la
// taille de police (h_unit >= ~0.40 x taille, seuil empirique de lisibilité
// minimale), avec un plancher de police à 6.5pt (= la police de base utilisée
// partout ailleurs dans ce bulletin) — l'espace manquant au-delà
// de ce plancher doit venir de la compression des marges/espacements du
// reste du bulletin (haut/bas de page), pas d'une police illisible.
$footer_fixe = 1 + 6 * $h_ligne + 1 + $h_bas + max(count($decisions) * $h_dec, $h_sig_min);
// Marge de sécurité de 6mm : le bloc date/signature (section 7, plus bas)
// a des décalages fixes non capturés par $footer_fixe — sans cette marge,
// une petite imprécision de prédiction du nombre de lignes suffit à faire
// déborder ce bloc sur une 2e page presque vide.
$dispo_table = $bas_dispo - $y_table0 - $hdr_h - $footer_fixe - 4;

// Taille de départ relevée à 7.5pt (demande explicite du 17/09/2026) — repli
// automatique vers un plancher de 7pt (voir plus bas) si une classe a trop
// de compétences pour tenir sur une page à 7.5pt.
$taille_comp = 7.5;
$lignes_par_mat = []; // [id_mat] => [ [id_comp, nb_lignes], ... ]
$total_lignes = 0;
$h_unit = 4;

// ── Mode lisible : police et espacements confortables, 2 pages au plus ──
// Chaque compétence prend la hauteur de son texte (+ marge interne), chaque
// matière reste entière sur une page, et le bas du bulletin (résultats,
// décision, observations/signature, QR) reste groupé sur la dernière page.
// On part de 9.5pt et on ne réduit (par pas de 0.25, plancher 7.5pt) que si
// la pagination simulée dépasse 2 pages.
$h_fin_tab   = $ph - 13;         // bas utile d'une page de tableau (sans QR)
$y_haut_suite = 10;              // haut du tableau sur une page suivante
$h_mat_l     = 6.2;              // ligne d'en-tête « COMPETENCE N: MATIERE »
$pad_l       = 1.4;              // marge interne (haut + bas) d'une compétence
$h_grp_min_l = 8;                // hauteur mini des compétences d'une matière (appréciation sur 2 lignes)
$h_pied_l    = 2 + 6 * $h_ligne + $h_bas + 42;   // résultats + décision/observations + signature
$h_txt_l     = 0;
$h_grp_l     = [];               // [id_mat] => hauteur totale de la matière (en-tête compris)
$h_obs_max_l = 42;               // hauteur du cadre OBSERVATIONS (date + signature)
$serre_l     = false;            // 2e niveau : espacements resserrés (classes très chargées)
while ($lisible) {
    // Au-delà du plancher de 7.5pt, on resserre d'abord les espacements
    // (marges internes, en-têtes de matière, cadre OBSERVATIONS), puis on
    // autorise 7pt — toujours plus lisible que le mode « 1 page ».
    if ($serre_l) {
        $h_mat_l = 5.2; $pad_l = 0.8; $h_grp_min_l = 6.5; $h_obs_max_l = 36;
        $h_ligne = 4.6; $h_dec = 3.6; $h_bas = 6;   // bas du bulletin un peu moins haut
        $h_pied_l = 2 + 6 * $h_ligne + $h_bas + $h_obs_max_l;
    }
    $h_txt_l = $taille_comp * ($serre_l ? 0.44 : 0.47);
    $lignes_par_mat = []; $h_grp_l = [];
    foreach ($disciplines as $d) {
        $id_mat = (int)$d['id_mat'];
        $lignes_par_mat[$id_mat] = [];
        $h_lignes = 0;
        foreach ($competences_par_mat[$id_mat] ?? [] as $c) {
            $nl = comp_wrap_lignes($pdf, $c['libelle'], $cD, $taille_comp);
            $lignes_par_mat[$id_mat][] = [(int)$c['id'], $nl];
            $h_lignes += $nl * $h_txt_l + $pad_l;
        }
        $h_grp_l[$id_mat] = $h_mat_l + max($h_lignes, $h_grp_min_l);
    }
    $pages = 1; $y_sim = $y_table0 + $hdr_h;
    foreach ($disciplines as $d) {
        $h = $h_grp_l[(int)$d['id_mat']];
        if ($y_sim + $h > $h_fin_tab) { $pages++; $y_sim = $y_haut_suite + $hdr_h; }
        $y_sim += $h;
    }
    if ($y_sim + $h_pied_l > $bas_dispo) $pages++;   // le bas du bulletin tient au-dessus du QR
    if ($pages <= 2) break;
    if (!$serre_l && $taille_comp <= 7.5) { $serre_l = true; $taille_comp = 8.0; continue; }
    if ($serre_l && $taille_comp <= 7.0) break;   // dernier recours : 3 pages plutôt qu'illisible
    $taille_comp -= 0.25;
}
if ($lisible) $h_unit = $h_mat_l;

// Mode 1 page (30/10/2026) : TOUTES les lignes de texte ont la même hauteur
// $h_unit (plus d'interligne écrasé sur les compétences de 2 lignes), chaque
// compétence a une marge interne $pad_c, l'en-tête de matière $h_unit + 0.8.
// $h_unit = place disponible répartie sur toutes les lignes, plafonnée à
// 0.6 × la police (au-delà, l'espace reste en bas plutôt que des lignes
// trop hautes). Si la place manque : marge réduite, puis police (plancher 6.75pt).
$taille_comp = 8.0;
$pad_c = 1.2;
while (!$lisible) {
    $lignes_par_mat = [];
    $total_lignes = 0; $nb_c = 0; $nb_m = 0;
    foreach ($disciplines as $d) {
        $id_mat = (int)$d['id_mat'];
        $lignes_par_mat[$id_mat] = [];
        $comps = $competences_par_mat[$id_mat] ?? [];
        $nb_m++;
        if (empty($comps)) { $total_lignes += 1; $nb_c++; continue; } // 1 ligne « aucune compétence »
        foreach ($comps as $c) {
            $nl = comp_wrap_lignes($pdf, $c['libelle'], $cD, $taille_comp);
            $lignes_par_mat[$id_mat][] = [(int)$c['id'], $nl];
            $total_lignes += $nl; $nb_c++;
        }
    }
    $h_unit = ($dispo_table - $nb_c * $pad_c - $nb_m * 0.8) / max(1, $total_lignes + $nb_m);
    if ($h_unit >= $taille_comp * 0.45 || $taille_comp <= 6.75) break;
    if ($pad_c > 0.5) { $pad_c = 0.5; continue; }
    $taille_comp -= 0.25;
}
// Filet de sécurité absolu (ne devrait jamais être atteint si la boucle
// ci-dessus a convergé, mais évite toute hauteur nulle/négative en cas de
// classe extrêmement chargée en compétences).
// Le plancher est volontairement bas (1.6, pas 2.6) : un plancher trop haut
// ignorerait le budget réellement disponible et ferait déborder le bloc
// date/signature (section 7) sur une page presque vide — mieux vaut que
// $h_unit suive fidèlement $dispo_table (garantissant une seule page) que
// de forcer une hauteur de ligne "confortable" hors budget.
if (!$lisible) $h_unit = max(1.6, min($taille_comp * 0.6, $h_unit));
$h_mat = $lisible ? $h_unit : $h_unit + 0.8;   // ligne d'en-tête « COMPETENCE N: MATIERE »
$pdf->SetFont('Arial', '', 6.5);

// En-tête tableau (redessiné en haut de chaque page suivante en mode lisible)
$entete_tableau = function () use ($pdf, $ml, $hdr_h, $cD, $cN, $cM, $cCF, $cMXC, $cCOTE, $cMINMAX, $cAPPR, $lisible) {
    pdf_fill($pdf, 'entete_tableau_individuel');
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetX($ml);
    $cols = [
        [$cD, bt("COMPÉTENCES ÉVALUÉES / NOM DE L'ENSEIGNANT", "COMPETENCES ASSESSED / TEACHER'S NAME")], [$cN, 'N/20'], [$cM, 'M/20'], [$cCF, 'Coef'],
        [$cMXC, 'MxC'], [$cCOTE, bt('Cote', 'Grade')], [$cMINMAX, '[Min-Max]'], [$cAPPR, bt("Appréciations et visa de l'enseignant", "Remarks and teacher's signature")],
    ];
    foreach ($cols as $i => [$larg, $txt]) {
        // Chaque intitulé s'ajuste à sa colonne (jamais de débordement).
        $t = cell2l_taille_ajustee($pdf, $txt, 'B', $larg - 1.4, $lisible ? 7.2 : 6.3, 4.2);
        $pdf->SetFont('Arial', 'B', $t);
        $pdf->Cell($larg, $hdr_h, u($txt), 1, $i === count($cols) - 1 ? 1 : 0, 'C', true);
    }
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFillColor(255, 255, 255);
};
// Mode lisible : page suivante avec le même habillage.
$nouvelle_page = function () use ($pdf, $decor_page, $fin_page, $y_haut_suite) {
    $fin_page();
    $pdf->AddPage();
    $decor_page();
    $pdf->SetY($y_haut_suite);
};
$entete_tableau();

$num_mat = 0;
foreach ($disciplines as $d) {
    $num_mat++;
    $id_mat = (int)$d['id_mat'];
    // Mode lisible : une matière qui ne tient plus sur la page part entière
    // sur la page suivante, sous un en-tête de tableau répété.
    if ($lisible && $pdf->GetY() + $h_grp_l[$id_mat] > $h_fin_tab) {
        $nouvelle_page();
        $entete_tableau();
    }
    $comps  = $competences_par_mat[$id_mat] ?? [];
    $avg    = mat_avg_comp($id_eleve, $comps, $notes_idx, $notes_count, $nb_inscrits);
    $stat   = $mat_stats[$id_mat] ?? ['min' => null, 'avg' => null, 'max' => null];
    $nxc    = ($avg !== null) ? $avg * $d['coef'] : null;
    $appr   = appreciation($avg);

    $bg = ($avg !== null && $avg < 10) ? couleur_pdf('ligne_echec') : [255, 255, 255];

    // Ligne d'en-tête de la matière : "COMPETENCE N: MATIERE" + enseignant,
    // en pleine largeur, fond lavande (même convention que le reste du
    // bulletin — cell2l()).
    $y_hdr = $pdf->GetY();
    $pdf->SetXY($ml, $y_hdr);
    pdf_fill($pdf, 'bandeau_section');
    // Taille mesurée en GRAS (style réellement utilisé) : le titre ne déborde plus dans la colonne voisine.
    $lib_mat = 'COMPETENCE ' . $num_mat . ': ' . mb_strtoupper(bulletin_en() && trim((string) ($d['matiere_en'] ?? '')) !== '' ? $d['matiere_en'] : $d['matiere']);
    $t_mat   = cell2l_taille_ajustee($pdf, $lib_mat, 'B', $cD + $cN - 1.6, $lisible ? 9.5 : 7.5, 5);
    $pdf->SetFont('Arial', 'B', $t_mat);
    $pdf->Cell($cD + $cN, $h_mat, u($lib_mat), 1, 0, 'L', true);
    [$t_ens, $lib_ens] = fpdf_texte_ajuste($pdf, $d['enseignant'] ?? '', $uw - $cD - $cN, $lisible ? 8.5 : 7, 5);
    $pdf->SetFont('Arial', '', $t_ens);
    $pdf->Cell($uw - $cD - $cN, $h_mat, u($lib_ens), 1, 1, 'R', true);
    $pdf->SetFont('Arial', '', $taille_comp);

    $y_grp0 = $pdf->GetY();

    if ($lisible) {
        // Chaque compétence prend la hauteur réelle de son texte + une marge
        // interne ; la dernière s'allonge si la matière est trop courte pour
        // loger l'appréciation sur 2 lignes.
        $h_grp = 0;
        $lignes = $comps ? $lignes_par_mat[$id_mat] : [[0, 1]];
        $nb = count($lignes);
        foreach ($lignes as $k => [$id_comp, $nb_lignes]) {
            $comp = null;
            foreach ($comps as $c) { if ((int)$c['id'] === $id_comp) { $comp = $c; break; } }
            $h_row_reel = $nb_lignes * $h_txt_l + $pad_l;
            if ($k === $nb - 1 && $h_grp + $h_row_reel < $h_grp_min_l) $h_row_reel = $h_grp_min_l - $h_grp;
            $x0 = $ml; $y0 = $pdf->GetY();
            $pdf->SetFillColor(...$bg);
            $pdf->Rect($x0, $y0, $cD, $h_row_reel, 'DF');
            $pdf->SetFont('Arial', $comp ? '' : 'I', $taille_comp);
            $pdf->SetXY($x0, $y0 + $pad_l / 2);
            $pdf->MultiCell($cD, $h_txt_l, u($comp ? ($comp['libelle'] ?? '') : bt('Aucune compétence définie pour ce trimestre.', 'No competence defined for this term.')), 0, 'L', false);
            $pdf->SetXY($x0 + $cD, $y0);
            $v = $comp ? note_eff_comp($id_eleve, $id_comp, $notes_idx, $notes_count, $nb_inscrits) : null;
            $pdf->SetFont('Arial', 'B', 9);
            if ($v !== null) { if ($v < 10) $pdf->SetTextColor(180, 0, 0); else $pdf->SetTextColor(0, 100, 0); }
            $pdf->Cell($cN, $h_row_reel, $v !== null ? fmt_note_b($v) : '', 1, 0, 'C', true);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetXY($x0, $y0 + $h_row_reel);
            $h_grp += $h_row_reel;
        }
    } elseif (empty($comps)) {
        $pdf->SetX($ml);
        $pdf->SetFillColor(...$bg);
        $pdf->Cell($cD, $h_unit + $pad_c, u(bt('Aucune compétence définie pour ce trimestre.', 'No competence defined for this term.')), 1, 0, 'L', true);
        $pdf->Cell($cN, $h_unit + $pad_c, '', 1, 1, 'C', true);
        $h_grp = $h_unit + $pad_c;
    } else {
        $h_grp = 0;
        foreach ($lignes_par_mat[$id_mat] as [$id_comp, $nb_lignes]) {
            $comp = null;
            foreach ($comps as $c) { if ((int)$c['id'] === $id_comp) { $comp = $c; break; } }
            // Hauteur = lignes de texte × interligne commun + marge interne.
            $h_row_reel = $nb_lignes * $h_unit + $pad_c;
            $x0 = $ml; $y0 = $pdf->GetY();
            $pdf->SetFillColor(...$bg);
            $pdf->SetFont('Arial', '', $taille_comp);
            $pdf->Rect($x0, $y0, $cD, $h_row_reel, 'DF');
            $pdf->SetXY($x0, $y0 + $pad_c / 2);
            $pdf->MultiCell($cD, $h_unit, u($comp['libelle'] ?? ''), 0, 'L', false);
            $h_grp += $h_row_reel;
            $pdf->SetXY($x0 + $cD, $y0);

            $v = note_eff_comp($id_eleve, $id_comp, $notes_idx, $notes_count, $nb_inscrits);
            $pdf->SetFont('Arial', 'B', 7.5);
            if ($v !== null) { if ($v < 10) $pdf->SetTextColor(180, 0, 0); else $pdf->SetTextColor(0, 100, 0); }
            $pdf->Cell($cN, $h_row_reel, $v !== null ? fmt_note_b($v) : '', 1, 0, 'C', true);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetXY($x0, $y0 + $h_row_reel);
        }
    }

    // Bloc fusionné (M/20, Coef, MxC, Cote, [Min-Max], Appréciation) sur
    // toute la hauteur du groupe de compétences (positionnement manuel —
    // FPDF n'a pas de rowspan natif). Une bordure PAR colonne (demande
    // explicite, comme sur le modèle de référence) plutôt qu'un seul cadre
    // englobant les 6 colonnes.
    $x_fus = $ml + $cD + $cN;
    $pdf->SetFillColor(...$bg);
    $x_col = $x_fus;
    foreach ([$cM, $cCF, $cMXC, $cCOTE, $cMINMAX, $cAPPR] as $c_larg) {
        $pdf->Rect($x_col, $y_grp0, $c_larg, $h_grp, 'DF');
        $x_col += $c_larg;
    }

    $centrer_y = fn(float $h_texte) => $y_grp0 + max(0, ($h_grp - $h_texte) / 2);

    $pdf->SetFont('Arial', 'B', $lisible ? 9.5 : 8);
    if ($avg !== null) { if ($avg >= 10) $pdf->SetTextColor(0, 100, 0); else $pdf->SetTextColor(180, 0, 0); }
    $pdf->SetXY($x_fus, $centrer_y(4));
    $pdf->Cell($cM, 4, $avg !== null ? fmt_note_b($avg) : '', 0, 0, 'C');
    $pdf->SetTextColor(0, 0, 0);

    $pdf->SetFont('Arial', 'B', $lisible ? 9 : 7.5);
    $pdf->SetXY($x_fus + $cM, $centrer_y(4));
    $pdf->Cell($cCF, 4, $avg !== null ? (string)$d['coef'] : '', 0, 0, 'C');

    $pdf->SetXY($x_fus + $cM + $cCF, $centrer_y(4));
    $pdf->Cell($cMXC, 4, $nxc !== null ? fmt_note_b($nxc) : '', 0, 0, 'C');

    $pdf->SetFont('Arial', 'B', $lisible ? 9.5 : 8);
    $pdf->SetXY($x_fus + $cM + $cCF + $cMXC, $centrer_y(4));
    $pdf->Cell($cCOTE, 4, u($appr['COTE']), 0, 0, 'C');

    $minmax = ($stat['min'] !== null) ? fmt_note_b($stat['min']) . '-' . fmt_note_b($stat['max']) : '';
    $pdf->SetFont('Arial', '', cell2l_taille_ajustee($pdf, $minmax ?: '00-00', '', $cMINMAX - 1, $lisible ? 8 : 7, 4.5));
    $pdf->SetXY($x_fus + $cM + $cCF + $cMXC + $cCOTE, $centrer_y(4));
    $pdf->Cell($cMINMAX, 4, $minmax, 0, 0, 'C');

    // Appréciation bilingue : texte complet si la place le permet (>= 5mm de
    // hauteur), sinon abrégé (CTBA/CVWA...) sur une seule ligne auto-réduite,
    // pour ne jamais déborder d'un groupe à une seule compétence courte.
    $x_appr = $x_fus + $cM + $cCF + $cMXC + $cCOTE + $cMINMAX;
    if ($appr['COTE'] !== '') {
        if (bulletin_en()) {   // section anglophone : l'anglais à la place du français
            [$appr['APPR1_FR'], $appr['APPR1_EN']] = [$appr['APPR1_EN'], $appr['APPR1_FR']];
            [$appr['APPR2_FR'], $appr['APPR2_EN']] = [$appr['APPR2_EN'], $appr['APPR2_FR']];
        }
        if ($h_grp >= 5) {
            $h2 = min($lisible ? 4.2 : 3.4, $h_grp / 2);
            $y_txt = $centrer_y($h2 * 2);
            $t_fr = cell2l_taille_ajustee($pdf, $appr['APPR1_FR'], 'B', $cAPPR - 2, $lisible ? 7.5 : 6, 4.2);
            $pdf->SetFont('Arial', 'B', $t_fr);
            $pdf->SetXY($x_appr, $y_txt);
            $pdf->Cell($cAPPR, $h2, u($appr['APPR1_FR']), 0, 0, 'C');
            $t_en = cell2l_taille_ajustee($pdf, $appr['APPR1_EN'], 'I', $cAPPR - 2, $lisible ? 6.5 : 5.2, 3.8);
            $pdf->SetFont('Arial', 'I', $t_en);
            $pdf->SetXY($x_appr, $y_txt + $h2);
            $pdf->Cell($cAPPR, $h2, u($appr['APPR1_EN']), 0, 0, 'C');
        } else {
            $abrege = $appr['APPR2_FR'] . ' / ' . $appr['APPR2_EN'];
            $t_ab = cell2l_taille_ajustee($pdf, $abrege, 'B', $cAPPR - 2, 5.5, 3.5);
            $pdf->SetFont('Arial', 'B', $t_ab);
            $pdf->SetXY($x_appr, $centrer_y(3));
            $pdf->Cell($cAPPR, 3, u($abrege), 0, 0, 'C');
        }
    }

    $pdf->SetFillColor(255, 255, 255);
    $pdf->SetFont('Arial', '', 6.5);
    $pdf->SetXY($ml, $y_grp0 + $h_grp);
}

// ── 5. Section bas : reproduit le modèle fourni par l'utilisateur ──
// (bandeaux DISCIPLINES | TRAVAIL | PROFIL DE LA CLASSE | RESULTATS DE
// L'ELEVE, chaque libellé bilingue FR (gras) / EN (italique) sur 2 lignes,
// via cell2l() défini plus haut.)
// Mode lisible : le bas du bulletin reste d'un seul tenant — s'il ne tient
// plus sous le tableau, il part sur la page suivante.
if ($lisible && $pdf->GetY() + $h_pied_l > $bas_dispo) {
    $nouvelle_page();
}
$pdf->Ln(1);
$y_bas    = $pdf->GetY();
$f5_fr = $lisible ? 8.5 : 9;  // libellés FR (gras) — en mode lisible, la place gagnée sert à aérer
$f5_en = 7;                   // libellés EN (italique)
// TRAVAIL agrandi (demande explicite : "Tableau d'honneur" trop à l'étroit)
// au détriment de PROFIL DE LA CLASSE, dont les libellés tiennent déjà
// largement à taille max avec moins de place (vérifié).
$wB_left  = 70;
$wB_mid   = 54;
$wB_right = $uw - $wB_left - $wB_mid; // 70, inchangé
// $h_bas déjà déclaré avant le tableau (section 4), pour le calcul de $row_h.

// Heures d'absence RÉELLES du trimestre, lues dans la table `absence`
// (fonctions.php::eleve_absence_trimestre(), alimentée par
// secondaire/commun/pages/discipline/index.php).
$abs_reelle = eleve_absence_trimestre($eleve['matricule'] ?? '', $id_trim, $insc['id_classe'], $val_annee);
$heur_jus   = (int) $abs_reelle['jus'];
$heur_nj    = (int) $abs_reelle['non_jus'];

// Seuils des mentions automatiques : configurables par l'administrateur
// (secondaire/commun/pages/parametres/index.php, onglet "Mentions") au lieu d'être codés en dur.
$reglage_mention = get_reglage_mention_bulletin($id_annee);
$tab_honneur = bulletin_tableau_honneur($moy_generale ?? 0, $heur_nj, $reglage_mention) === 'Oui';
$encourag    = bulletin_encouragement($moy_generale ?? 0, $heur_nj, $reglage_mention) === 'Oui';
$felicit     = bulletin_felicitation($moy_generale ?? 0, $heur_nj, $reglage_mention) === 'Oui';
$avert_cond  = bulletin_avert_conduite($heur_nj, $reglage_mention) === 'OUI';
$avert_trav  = bulletin_avert_travail($moy_generale ?? 0, $reglage_mention) === 'Oui';
$blame_cond  = bulletin_blame_conduite($heur_nj, $reglage_mention) === 'OUI';
$blame_trav  = bulletin_blame_travail($moy_generale ?? 0, $reglage_mention) === 'Oui';

// ── En-têtes des 4 bandeaux (fond lavande, FR/EN) ──────────────────
// Hauteur harmonisée avec toutes les lignes de cette section, y compris
// Rappel/Eval et MOYENNE (demande explicite : plus de bandeau plus haut que
// le reste) — même hauteur $h_ligne, même plafond de police (9/7) que les
// libellés DISCIPLINES/TRAVAIL/PROFIL juste en dessous.
$pdf->SetXY($ml, $y_bas);
$w_dis = 36; $w_trav = $wB_left - $w_dis; // 36 / 34
cell2l($pdf, $w_dis,  $h_ligne, 'DISCIPLINES', 'Disciplines', 'C', true, $f5_fr, $f5_en);
cell2l($pdf, $w_trav, $h_ligne, 'TRAVAIL',     'Work',        'C', true, $f5_fr, $f5_en);
cell2l($pdf, $wB_mid,   $h_ligne, 'PROFIL DE LA CLASSE', 'Class profile',   'C', true, $f5_fr, $f5_en);
cell2l($pdf, $wB_right, $h_ligne, "RESULTATS DE L'ELEVE", 'Student results', 'C', true, $f5_fr, $f5_en);
$pdf->Ln($h_ligne);

// ── Ligne par ligne : DISCIPLINES | TRAVAIL | PROFIL DE LA CLASSE ──
$w_dis_lbl = 26; $w_dis_val = $w_dis - $w_dis_lbl;   // 26 / 10
$w_tr_chk  = 6;  $w_tr_lbl  = $w_trav - $w_tr_chk;   // 6 / 28
// Colonne valeur réduite (demande explicite : trop large pour des nombres
// courts comme "14.32" ou "45%", vérifié) au profit du libellé.
$w_pr_lbl  = round($wB_mid * 0.72); $w_pr_val = $wB_mid - $w_pr_lbl; // ~39 / 15

$left_rows = [
    ['Absences Jus.', 'Justified Abs.', (string)$heur_jus, "Tableau d'honneur", 'Roll of honor', $tab_honneur, null, true],
    ['Absences NJ.',  'Unjustified Abs', (string)$heur_nj,  'Encouragement',     'Encouragement', $encourag,    null, true],
    ['Exclusion(jrs)', 'Exclu.', '---',                       'Félicitations',     'Congratulation', $felicit, null, false],
    ['Avert. conduite', 'Warning Behavior', null,             'Avert. Travail',   'Warning Work',   $avert_trav, $avert_cond, false],
    ['Blâme conduite',  'Blame Behavior',   null,             'Blâme Travail',    'Blame Work'    ,  $blame_trav, $blame_cond, false],
];
$prof_rows = [
    ['Moy. de la classe', 'Class Average',     $moy_classe  !== null ? fmt_note_b($moy_classe)  : '-'],
    ['Moy. du premier',   'Average of First',  $moy_premier !== null ? fmt_note_b($moy_premier) : '-'],
    ['Moy. du dernier',   'Average of Last',   $moy_dernier !== null ? fmt_note_b($moy_dernier) : '-'],
    ['Effectif Classé',   'Classified Enroll.', (string)$nb_classes],
    ['Taux de réussite',  'Success Rate',      $taux_reussite . '%'],
];
// Taille des libellés DISCIPLINES/TRAVAIL/PROFIL agrandie (demande
// explicite), auto-ajustée par cell2l() donc jamais en débordement.
foreach ($left_rows as $i => $lr) {
    $y0r = $pdf->GetY();
    $pdf->SetXY($ml, $y0r);
    cell2l($pdf, $w_dis_lbl, $h_ligne, $lr[0], $lr[1], 'L', false, $f5_fr, $f5_en);
    if ($lr[2] === null) {
        $pdf->Cell($w_dis_val, $h_ligne, '', 1, 0, 'C');
        checkbox_ltm($pdf, $ml + $w_dis_lbl + $w_dis_val / 2 - 1.25, $y0r + $h_ligne / 2 - 1.25, $lr[6] ?? false, 2.5);
    } else {
        // Nombre d'heures d'absence : en gras, taille agrandie (demande
        // explicite — c'est une donnée clé du bulletin).
        $pdf->SetFont('Arial', $lr[7] ? 'B' : '', $lr[7] ? 8.5 : 7.5);
        $pdf->Cell($w_dis_val, $h_ligne, $lr[2], 1, 0, 'C');
        $pdf->SetFont('Arial', '', 6.5);
    }
    checkbox_ltm($pdf, $pdf->GetX() + 1.5, $y0r + $h_ligne / 2 - 1.25, $lr[5], 2.5);
    $pdf->SetX($pdf->GetX() + $w_tr_chk);
    cell2l($pdf, $w_tr_lbl, $h_ligne, $lr[3], $lr[4], 'L', false, $f5_fr, $f5_en);

    $pr = $prof_rows[$i];
    $pdf->SetXY($ml + $wB_left, $y0r);
    cell2l($pdf, $w_pr_lbl, $h_ligne, $pr[0], $pr[1], 'L', false, $f5_fr, $f5_en);
    // Moyennes du profil de classe : en gras, taille agrandie mais auto-
    // ajustée (colonne réduite, demande explicite : ne doit jamais déborder).
    $taille_pr = cell2l_taille_ajustee($pdf, $pr[2], 'B', $w_pr_val - 2.4, 8.5, 6);
    $pdf->SetFont('Arial', 'B', $taille_pr);
    $pdf->Cell($w_pr_val, $h_ligne, $pr[2], 1, 0, 'C');
    $pdf->SetFont('Arial', '', 6.5);
    $pdf->Ln($h_ligne);
}

// ── RESULTATS DE L'ELEVE : GENERAL TOTAL / COEFFICIENT / MOYENNE / RANG /
//    APPRECIATION ─────────────────────────────────────────────────────
// Reproduit le bloc "STUDENT'S RESULTS" du modèle de référence — plus de
// tableau "Rappel Eval1/Eval2" (sans équivalent en compétences, voir §4),
// le bloc occupe donc toute la largeur $wB_right.
$xR = $ml + $wB_left + $wB_mid;
$y_res0 = $y_bas + $h_ligne;
$total_general = ($moy_generale !== null && $total_coef_eleve > 0) ? $moy_generale * $total_coef_eleve : null;
$moy_str     = $moy_generale !== null ? fmt_note_b($moy_generale) . ' / 20' : '- / 20';
$rang_str    = $rang_general !== null ? $rang_general . 'e / ' . $nb_classes : '-';
$mention_str = $moy_generale !== null ? mention_gen_b($moy_generale) : '';

$res_rows2 = [
    ['TOTAL GENERAL', 'General total', $total_general !== null ? fmt_note_b($total_general) : '-', false],
    ['COEFFICIENT',   'Coefficient',   $total_coef_eleve > 0 ? (string)$total_coef_eleve : '-', false],
];
$pdf->SetXY($xR, $y_res0);
foreach ($res_rows2 as $rr) {
    cell2l($pdf, $wB_right * 0.55, $h_ligne, $rr[0], $rr[1], 'L', false, $f5_fr, $f5_en);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell($wB_right * 0.45, $h_ligne, u($rr[2]), 1, 1, 'C');
    $pdf->SetFont('Arial', '', 6.5);
    $pdf->SetX($xR);
}
cell2l($pdf, $wB_right * 0.55, $h_ligne, 'MOYENNE', 'Average', 'L', false, $f5_fr, $f5_en);
$taille_moy = cell2l_taille_ajustee($pdf, $moy_str, 'B', $wB_right * 0.45 - 2.4, 11, 7);
$pdf->SetFont('Arial', 'B', $taille_moy);
$pdf->SetTextColor(0, 130, 0);
$pdf->Cell($wB_right * 0.45, $h_ligne, u($moy_str), 1, 1, 'C');
$pdf->SetTextColor(0, 0, 0);
$pdf->SetX($xR);
cell2l($pdf, $wB_right * 0.55, $h_ligne, 'RANG', 'Rank', 'L', false, $f5_fr, $f5_en);
$taille_rang = cell2l_taille_ajustee($pdf, $rang_str, 'B', $wB_right * 0.45 - 2.4, 10, 6);
cell_rang($pdf, $wB_right * 0.45, $h_ligne, $rang_general, ' / ' . $nb_classes, 1, 'C', $taille_rang);
$pdf->Ln($h_ligne);
$pdf->SetX($xR);
cell2l($pdf, $wB_right * 0.55, $h_ligne, 'APPRECIATION', 'Appreciation', 'L', false, $f5_fr, $f5_en);
$pdf->SetFont('Arial', 'B', 8.5);
$pdf->SetTextColor(0, 130, 0);
$pdf->Cell($wB_right * 0.45, $h_ligne, u($mention_str), 1, 1, 'C');
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('Arial', '', 6.5);

$pdf->SetY(max($y_res0 + 4 * $h_ligne, $y_bas + 6 * $h_ligne));

// ── 6. Décision du conseil ────────────────────────────────────────
$pdf->Ln(1);
$y_dec = $pdf->GetY();
$w_dec = $uw * 0.55; $w_obs = $uw - $w_dec;
$pdf->SetXY($ml, $y_dec);
// Police agrandie (demande explicite : c'est la partie la plus importante du bulletin).
cell2l($pdf, $w_dec, $h_bas, 'DECISION DU CONSEIL DE CLASSE ET DE DISCIPLINE', 'Decision of the class advice and discipline', 'C', true, 10.5, 8.5);
cell2l($pdf, $w_obs, $h_bas, "OBSERVATIONS DU CHEF D'ETABLISSEMENT", "Principal's remarks", 'C', true, 10.5, 8.5);
$pdf->Ln($h_bas);

// $decisions, $h_dec et $bas_dispo déjà déclarés avant le tableau (section 4).
$x_dec = $ml; $x_obs = $ml + $w_dec; $y_dec2 = $pdf->GetY();
// Le cadre OBSERVATIONS occupe tout l'espace restant jusqu'au bas de page
// (proportionnel à ce qu'il reste, pas une hauteur fixe). Le QR code de
// vérification est désormais dessiné À L'INTÉRIEUR de ce même cadre (voir
// plus bas, section 8), donc plus besoin de réserver de place en dessous.
// Ne descend jamais sous la hauteur de la liste DECISION.
$h_obs_box = max(count($decisions) * $h_dec, $h_sig_min, $bas_dispo - $y_dec2);
// Mode lisible : cadre de hauteur raisonnable (signature + QR) plutôt que
// tout le reste d'une page éventuellement presque vide.
if ($lisible) $h_obs_box = max(count($decisions) * $h_dec, $h_sig_min, min($h_obs_box, $h_obs_max_l));
foreach ($decisions as $i => $dec) {
    $y_row = $y_dec2 + $i * $h_dec;
    // Cases cochées automatiquement sur des règles définies :
    // 0 = moyenne >= 10 (admis) ; 2 = moyenne < 8 (travail insuffisant) ;
    // 3 = avertissement ou blâme de conduite déclenché (voir $avert_cond/$blame_cond).
    // Les autres (absences, exclusion) nécessitent une saisie manuelle du conseil.
    $checked = ($i === 0 && $moy_generale !== null && $moy_generale >= 10)
            || ($i === 2 && $moy_generale !== null && $moy_generale < 8)
            || ($i === 3 && ($avert_cond || $blame_cond));
    // Case à cocher réduite au minimum lisible (demande explicite).
    checkbox_ltm($pdf, $x_dec + 1.2, $y_row + $h_dec / 2 - ($lisible ? 1.25 : 1), $checked, $lisible ? 2.5 : 2);
    $pdf->SetXY($x_dec + 5.5, $y_row);
    $pdf->SetFont('Arial', 'B', $lisible ? 8 : 7.5);
    $dec = bl($dec[0], $dec[1]);
    $txt_fr = u($dec[0] . ' / ');
    $w_fr   = $pdf->GetStringWidth($txt_fr);
    $pdf->Cell($w_fr, $h_dec, $txt_fr, 0, 0, 'L');
    // Texte anglais agrandi pour la lisibilité (demande explicite) — même
    // taille que le français (7.5), seul le style (italique) les distingue.
    $pdf->SetFont('Arial', 'I', $lisible ? 8 : 7.5);
    $pdf->Cell($w_dec - 7.5 - $w_fr, $h_dec, u($dec[1]), 0, 1, 'L');
    $pdf->Rect($x_dec, $y_row, $w_dec, $h_dec); // bordure complète de la ligne
}
// Dernière cellule (vide) qui complète la liste DECISION jusqu'au bas du
// cadre OBSERVATIONS d'en face, pour que les deux colonnes finissent à la
// même hauteur.
$y_fill   = $y_dec2 + count($decisions) * $h_dec;
$h_rempli = $h_obs_box - count($decisions) * $h_dec;
if ($h_rempli > 0.01) {
    $pdf->Rect($x_dec, $y_fill, $w_dec, $h_rempli);
}

$pdf->SetFont('Arial', '', 6);
$pdf->SetXY($x_obs, $y_dec2);
$pdf->MultiCell($w_obs, $h_obs_box, '', 1, 'L');

// ── 7. Date et signature (à l'intérieur du cadre Observations) ────
// Remonté et centré (demande explicite), au lieu d'être bas et aligné à droite.
// Décalage fixe (pas proportionnel à $h_obs_box, qui peut désormais être
// grand) pour que le bloc reste bien en haut du cadre quelle que soit sa
// hauteur réelle. Police agrandie (demande explicite).
// Remonté en haut du cadre et lignes resserrées (30/10/2026) : la place
// libérée en dessous sert à la signature (cadre de 30 mm en mode 1 page).
$pdf->SetFont('Arial', '', 8.5);
$pdf->SetXY($x_obs, $y_dec2 + 1.2);
$pdf->Cell($w_obs, 4.2, u(lieu_edition_documents() . bt(', le ', ', on ') . date('d-m-Y') . '.'), 0, 1, 'C');
$pdf->SetX($x_obs);
$pdf->SetFont('Arial', 'I', 7.5);
$pdf->Cell($w_obs, 3.2, bt('On', 'Le'), 0, 1, 'C');
$pdf->Ln(0.8);
$pdf->SetX($x_obs);
$pdf->SetFont('Arial', 'B', 8.5);
$pdf->Cell($w_obs, 4.2, u(strtoupper(bt($etab['chef_etablissement'] ?? 'LE PROVISEUR', $etab['chef_etablissement_en'] ?? 'The Principal')) . ','), 0, 1, 'C');
$pdf->SetX($x_obs);
$pdf->SetFont('Arial', 'I', 7.5);
$pdf->Cell($w_obs, 3.4, u(bt($etab['chef_etablissement_en'] ?? 'The Principal', $etab['chef_etablissement'] ?? 'Le Proviseur')), 0, 1, 'C');

// Signature numérique (uniquement si demandée à l'impression — jamais
// automatique — et si l'admin en a configuré une dans les paramètres).
if (($_GET['signature'] ?? '0') === '1') {
    $sig_w = 22;
    $sx = $x_obs + ($w_obs - $sig_w) / 2;
    $sy = $pdf->GetY() + 0.5;
    pdf_signature_appliquer($pdf, 'bulletin_trimestriel', 'chef_etablissement', 0, 0, $pw, $ph, [
        'x_pct' => $sx / $pw * 100, 'y_pct' => $sy / $ph * 100, 'w_pct' => $sig_w / $pw * 100, 'h_pct' => null,
    ]);
}

$pdf->SetY($y_dec2 + $h_obs_box);

// Mention copyright en bas de page, centrée, à l'intérieur du cadre extérieur
// (demande explicite) + tampon AUTHENTIQUE éventuel — voir $fin_page.
$fin_page();

