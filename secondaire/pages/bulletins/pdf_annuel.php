<?php
/**
 * Bulletin scolaire — BILAN ANNUEL (modèle LTM, jumeau de pdf.php).
 * GET : eleve=ID, annee=ID_ANNEE
 * Couvre toute l'année (3 trimestres) au lieu d'une séquence/un trimestre :
 * voir secondaire/pages/bulletins/pdf.php pour le détail des sections communes
 * (en-tête, photo, décision/observations, QR, copyright) — identiques ici.
 */
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
require_once __DIR__ . '../../pdf/verif_lib.php';

// Accès public via le QR code du bulletin (jeton "vh") — voir pdf.php pour
// l'explication ; ici la "période" est toujours l'année scolaire elle-même.
$vh_verif = (string)($_GET['vh'] ?? '');
$acces_public = false;
if ($vh_verif !== '' && (int)($_GET['eleve'] ?? 0) > 0 && (int)($_GET['annee'] ?? 0) > 0) {
    $eleve_verif = db_one("SELECT niu, matricule FROM eleve WHERE id=?", [(int)$_GET['eleve']]);
    if ($eleve_verif) {
        $acces_public = hash_equals(bulletin_verif_hash((int)$_GET['eleve'], 'annee', (int)$_GET['annee'], id_affichage_eleve($eleve_verif)), $vh_verif);
    }
}
if (!$acces_public) {
    exiger_connexion();
}
require_once __DIR__ . '../../pdf/fpdf.php';
require_once __DIR__ . '../../pdf/header_pdf.php';

//function u(string $s): string { return utf8_decode($s); }
function u(string $s): string {
	//return utf8_decode($s);
	return mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
}

// Incruste la photo de l'élève au centre de l'image QR déjà générée (demande
// explicite), sur un fond blanc pour ne pas casser la lecture des modules
// environnants. Le niveau de correction d'erreur du QR ('qr-h', ~30%) tolère
// une zone occultée d'environ 20% de la surface sans empêcher le scan. Ne
// fait rien si $photo_path est vide ou illisible (le QR reste utilisable seul).
function qr_incruster_photo($qr_img, string $photo_path): void {
    if ($photo_path === '' || !is_file($photo_path)) return;
    $photo = @imagecreatefromstring(file_get_contents($photo_path));
    if (!$photo) return;

    $w = imagesx($qr_img); $h = imagesy($qr_img);
    $logo_size = (int)round($w * 0.22);
    $pad = (int)round($w * 0.012);
    $box = $logo_size + $pad * 2;
    $bx = (int)(($w - $box) / 2);
    $by = (int)(($h - $box) / 2);

    $blanc = imagecolorallocate($qr_img, 255, 255, 255);
    imagefilledrectangle($qr_img, $bx, $by, $bx + $box - 1, $by + $box - 1, $blanc);

    $pw = imagesx($photo); $ph = imagesy($photo);
    $cote = min($pw, $ph);
    $sx = (int)(($pw - $cote) / 2); $sy = (int)(($ph - $cote) / 2);
    imagecopyresampled($qr_img, $photo, $bx + $pad, $by + $pad, $sx, $sy, $logo_size, $logo_size, $cote, $cote);
    imagedestroy($photo);
}

// Ajuste un texte à une largeur de cellule donnée : réduit d'abord la taille
// de police par petits pas (jamais en dessous de $taille_min), puis, si ça
// ne suffit toujours pas, tronque mot par mot depuis la fin (jamais au
// milieu d'un mot) en ajoutant une ellipse. Retourne [taille_a_utiliser,
// texte_final] — à l'appelant de faire SetFont() avec la taille retournée
// avant d'écrire la cellule, et de restaurer sa taille de police d'origine
// après. Évite tout débordement (ex. noms d'enseignants longs) quelle que
// soit la largeur de colonne réellement disponible.
function fpdf_texte_ajuste(FPDF $pdf, string $texte, float $largeur_max, float $taille_max, float $taille_min = 4.0): array {
    $marge = 1.2; // marge interne gauche+droite approximative d'une Cell()
    $tient = function (string $t) use ($pdf, $largeur_max, $marge): bool {
        return $pdf->GetStringWidth(u($t)) <= $largeur_max - $marge;
    };

    $taille = $taille_max;
    $pdf->SetFont('Arial', '', $taille);
    while ($taille > $taille_min && !$tient($texte)) {
        $taille -= 0.25;
        $pdf->SetFont('Arial', '', $taille);
    }
    if (!$tient($texte)) {
        $mots = explode(' ', $texte);
        while (count($mots) > 1 && !$tient(implode(' ', $mots) . '…')) {
            array_pop($mots);
        }
        $texte = implode(' ', $mots);
        while (mb_strlen($texte) > 1 && !$tient($texte . '…')) {
            $texte = mb_substr($texte, 0, -1);
        }
        $texte .= '…';
    }
    return [$taille, $texte];
}

// Sous-classe FPDF (fpdf.php n'est pas modifié) ajoutant les rectangles
// à coins arrondis utilisés pour le cadre de page et le bandeau du titre.
class PDF_LTM extends FPDF
{
    function RoundedRect($x, $y, $w, $h, $r, $style = '')
    {
        $k = $this->k;
        $hp = $this->h;
        if ($style === 'F') { $op = 'f'; }
        elseif ($style === 'FD' || $style === 'DF') { $op = 'B'; }
        else { $op = 'S'; }
        $myArc = 4 / 3 * (sqrt(2) - 1);
        $this->_out(sprintf('%.2F %.2F m', ($x + $r) * $k, ($hp - $y) * $k));
        $xc = $x + $w - $r; $yc = $y + $r;
        $this->_out(sprintf('%.2F %.2F l', $xc * $k, ($hp - $y) * $k));
        $this->_arcLTM($xc + $r * $myArc, $yc - $r, $xc + $r, $yc - $r * $myArc, $xc + $r, $yc);
        $xc = $x + $w - $r; $yc = $y + $h - $r;
        $this->_out(sprintf('%.2F %.2F l', ($x + $w) * $k, ($hp - $yc) * $k));
        $this->_arcLTM($xc + $r, $yc + $r * $myArc, $xc + $r * $myArc, $yc + $r, $xc, $yc + $r);
        $xc = $x + $r; $yc = $y + $h - $r;
        $this->_out(sprintf('%.2F %.2F l', $xc * $k, ($hp - ($y + $h)) * $k));
        $this->_arcLTM($xc - $r * $myArc, $yc + $r, $xc - $r, $yc + $r * $myArc, $xc - $r, $yc);
        $xc = $x + $r; $yc = $y + $r;
        $this->_out(sprintf('%.2F %.2F l', $x * $k, ($hp - $yc) * $k));
        $this->_arcLTM($xc - $r, $yc - $r * $myArc, $xc - $r * $myArc, $yc - $r, $xc, $yc - $r);
        $this->_out($op);
    }

    private function _arcLTM($x1, $y1, $x2, $y2, $x3, $y3)
    {
        $h = $this->h;
        $this->_out(sprintf(
            '%.2F %.2F %.2F %.2F %.2F %.2F c ',
            $x1 * $this->k, ($h - $y1) * $this->k,
            $x2 * $this->k, ($h - $y2) * $this->k,
            $x3 * $this->k, ($h - $y3) * $this->k
        ));
    }
}

// ── Paramètres ────────────────────────────────────────────────────
$id_eleve = (int)($_GET['eleve'] ?? 0);
$id_annee = (int)($_GET['annee'] ?? 0);

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

// ── Trimestres de l'année (toujours 3 colonnes) ───────────────────
// Complété à 3 emplacements même si un trimestre n'a pas encore été créé (ou
// n'a encore aucune compétence/note) — la colonne correspondante s'affiche
// alors simplement vide, le bulletin annuel reste consultable (demande
// explicite : trimestriel/annuel visualisables même sans note). Chantier
// APC (voir prompt_continuite, Phase 6) : les compétences n'ont pas
// d'équivalent "séquence", chaque trimestre est chargé en bloc plus bas via
// pv_charger_donnees_comp() (même pattern que
// secondaire/pages/conseil_classe/pdf_releve_annuel.php).
$trimestres = db_all("SELECT * FROM trimestre WHERE id_annee=? ORDER BY ordre", [$id_annee]);
$trimestres = array_pad(array_slice($trimestres, 0, 3), 3, null);

// Libellé court d'un trimestre par sa position (1er/2e/3e) — indépendant du
// trimestre existant ou non en base, pour toujours nommer les 3 colonnes.
function trimestre_court(int $position): string {
    return $position . ($position === 1 ? 'er' : 'e') . ' Trim';
}

$titre_bull = 'BILAN ANNUEL';

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
    "SELECT d.id_mat, d.coef, d.ordre, m.libelle AS matiere,
            g.libelle_groupe_comp AS groupe_lib, d.id_groupe,
            TRIM(CONCAT(e.nom_ens,' ',COALESCE(e.prenom_ens,''))) AS enseignant
     FROM discipline d
     JOIN matiere m ON m.id=d.id_mat AND m.actif=1
     LEFT JOIN groupe g ON g.id_groupe_comp=d.id_groupe
     LEFT JOIN dispenser disp ON disp.id_mat=d.id_mat AND disp.IDClasses=d.IDClasses AND disp.val_annee=?
     LEFT JOIN enseignant e ON e.matricule_ens=disp.matricule_ens
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

// ── Chargement compétences par trimestre ────────────────────────────
// Chantier APC : chaque trimestre est chargé séparément via
// pv_charger_donnees_comp() (fonctions.php) — même pattern que
// secondaire/pages/conseil_classe/pdf_releve_annuel.php (Phase 6). $dcomp_par_trim[$i]
// est null si ce trimestre n'existe pas encore en base.
$dcomp_par_trim = [];
foreach ([0, 1, 2] as $i) {
    $t = $trimestres[$i];
    $dcomp_par_trim[$i] = $t ? pv_charger_donnees_comp($insc['id_classe'], (int)$t['id'], $id_annee) : null;
}

// Moyenne annuelle d'une matière = moyenne des moyennes trimestrielles de
// cette matière (trimestres sans note ignorés) — méthode standard retenue
// (demande explicite). Retourne [avg_t1, avg_t2, avg_t3, avg_annuel].
function mat_avg_trim_annuel_comp(int $eid, int $id_mat, array $dcomp_par_trim): array {
    $par_trim = [];
    foreach ($dcomp_par_trim as $dc) {
        $par_trim[] = $dc ? pv_moy_matiere_comp($eid, $dc['competences_par_mat'][$id_mat] ?? [], $dc['notes_idx'], $dc['notes_count'], $dc['nb_inscrits']) : null;
    }
    $valides = array_filter($par_trim, fn($v) => $v !== null);
    $annuel  = !empty($valides) ? array_sum($valides) / count($valides) : null;
    return array_merge($par_trim, [$annuel]);
}

// Mentions automatiques (tableau d'honneur, encouragements, félicitations,
// avertissements/blâmes travail et conduite) pour une période donnée (un
// trimestre ou l'année) — seuils fournis explicitement par l'utilisateur
// pour le bilan annuel (repris de sa fonction Distinctions_Completes()) :
// tableau d'honneur si moyenne >= 12 (refusé au-delà de 10h d'absence non
// justifiée), encouragement/félicitations si en plus moyenne >= 14/15,
// avertissement/blâme travail sous 7.30/5, avertissement/blâme conduite à
// partir de 8h/15h d'absence non justifiée. Vide si aucune moyenne (période
// sans note, demande explicite : jamais de fausse mention par défaut).
function bulletin_mentions_periode(?float $moy, int $heur_nj): array {
    if ($moy === null) {
        return ['tab' => '', 'encourag' => false, 'felicit' => false, 'avert_trav' => false, 'blame_trav' => false, 'avert_cond' => false, 'blame_cond' => false];
    }
    $tab = $moy >= 12 ? ($heur_nj <= 10 ? 'oui' : 'refuse') : '';
    return [
        'tab'        => $tab,
        'encourag'   => $tab === 'oui' && $moy >= 14,
        'felicit'    => $tab === 'oui' && $moy >= 15,
        'avert_trav' => $moy >= 5 && $moy <= 7.30,
        'blame_trav' => $moy < 5,
        'avert_cond' => $heur_nj >= 8 && $heur_nj < 15,
        'blame_cond' => $heur_nj >= 15,
    ];
}

// Appréciation du conseil de fin d'année, générée automatiquement (demande
// explicite) — logique fournie par l'utilisateur : travail (blâme >
// avertissement > tableau d'honneur > refus), puis encouragements/
// félicitations, puis conduite, puis matières où des efforts sont
// nécessaires (moyenne annuelle < 10). Une ligne par appréciation.
function bulletin_appreciation_annuelle(array $m, array $matieres_faibles): string {
    $lignes = [];
    if ($m['blame_trav'])           $lignes[] = 'Blâme pour mauvais travail';
    elseif ($m['avert_trav'])       $lignes[] = 'Avertissement pour mauvais travail';
    elseif ($m['tab'] === 'oui')    $lignes[] = "Tableau d'honneur accordé pour bon travail";
    elseif ($m['tab'] === 'refuse') $lignes[] = "Tableau d'honneur refusé pour trop d'absences";

    if ($m['encourag']) $lignes[] = 'Encouragements pour résultats satisfaisants';
    if ($m['felicit'])  $lignes[] = 'Félicitations pour excellent travail';

    if ($m['blame_cond'])     $lignes[] = 'Blâme pour mauvaise conduite';
    elseif ($m['avert_cond']) $lignes[] = 'Avertissement pour mauvaise conduite';
    else                      $lignes[] = 'Bonne conduite dans l\'ensemble';

    if (!empty($matieres_faibles)) {
        $lignes[] = "Des efforts s'imposent en : " . implode(', ', $matieres_faibles);
    }
    return implode("\n", $lignes);
}

function fmt_note_b(?float $v): string {
    if ($v === null) return '';
    $s = rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    // Zéro de tête sur la partie entière (demande explicite) : "4" -> "04",
    // "8.75" -> "08.75" — appliqué à toutes les notes/NxC/moyennes du bulletin.
    [$int, $dec] = array_pad(explode('.', $s, 2), 2, null);
    $int = str_pad($int, 2, '0', STR_PAD_LEFT);
    return $dec !== null ? "$int.$dec" : $int;
}
function mention_mat_b(float $a): string {
    if ($a >= 14) return "Compétences TB\nacquises";
    if ($a >= 12) return "Compétences\nbien acquises";
    if ($a >= 10) return "Compétences\nacquises";
    return "Compétences\nnon acquises";
}
function lettre_groupe_b(float $a): string {
    if ($a >= 16) return 'A'; if ($a >= 14) return 'B'; if ($a >= 12) return 'C+';
    if ($a >= 10) return 'C'; if ($a >= 8)  return 'D'; return 'E';
}
function mention_gen_b(float $a): string {
    if ($a >= 16) return 'Excellent'; if ($a >= 14) return 'Tres bien';
    if ($a >= 12) return 'Bien'; if ($a >= 10) return 'Assez bien';
    if ($a >= 8)  return 'Passable'; return 'Insuffisant';
}

// ── Décisions du conseil de classe (fournies par l'utilisateur) ────
// Les fonctions Tableau_honneur()/Encouragement()/Felicitation()/
// Avertissement_travail()/Blame_travail()/Avertissement_Conduite_eleve()/
// Blame_Conduite_eleve() ont été déplacées dans fonctions.php (préfixe
// bulletin_*), avec leurs seuils désormais lus dans
// reglage_mention_bulletin (configurable par l'administrateur, voir
// secondaire/pages/parametres/index.php onglet "Mentions") au lieu d'être codés en
// dur — voir section 5 plus bas pour l'appel (get_reglage_mention_bulletin()
// + $heur_jus/$heur_nj lus via eleve_absence_trimestre()).
function rang_eleve_b(float $avg, array $avgs_sorted): int {
    $r = 1; foreach ($avgs_sorted as $a) { if ($a > $avg) $r++; else break; } return $r;
}

// Rappel par trimestre pour la colonne "RÉSULTATS DE L'ÉLÈVE" (3 colonnes :
// 1er/2e/3e trimestre, demande explicite, au lieu de 2 évaluations) — chaque
// valeur est la moyenne générale PONDÉRÉE de l'élève pour ce trimestre
// (pv_moy_generale_comp(), fonctions.php, bornée aux données du trimestre
// courant : matières avec notes/effectif propres à CE trimestre, comme
// secondaire/pages/conseil_classe/pdf_releve_annuel.php). Trimestre pas encore créé (ou
// sans compétence) : reste à null, affiché vide (demande explicite).
$moy_rappel_t  = [null, null, null];
$rang_rappel_t = [null, null, null];
$moy_prem_t    = [null, null, null];
$moy_dern_t    = [null, null, null];
$class_moys_par_trim = [[], [], []]; // [i][eid] = moyenne pondérée de l'élève au trimestre i
// [i][eid] = ['moy'=>?float,'classable'=>bool,'annule'=>bool,'evalue'=>bool] — pour la
// moyenne annuelle Règle 3 (pv_moy_annuelle_comp()), qui a besoin du statut complet de
// CHAQUE élève à CHAQUE trimestre, pas seulement des moyennes des élèves classés.
$statut_par_trim = [[], [], []];

foreach ([0, 1, 2] as $i) {
    $dc = $dcomp_par_trim[$i];
    $evalue = $dc && ($dc['nb_mats_avec_notes'] ?? 0) > 0;
    if (!$dc || empty($dc['competences_par_mat'])) continue;

    foreach ($enrolled_ids as $eid) {
        [$m, $ok, , $annule] = pv_moy_generale_comp($eid, $dc['disciplines'], $dc['competences_par_mat'], $dc['notes_idx'], $dc['notes_count'], $dc['nb_inscrits'], $dc['mats_avec_notes'], $dc['nb_mats_avec_notes'], $dc['annules']);
        if ($ok && $m !== null) $class_moys_par_trim[$i][$eid] = $m;
        $statut_par_trim[$i][$eid] = ['moy' => $m, 'classable' => $ok, 'annule' => $annule, 'evalue' => $evalue];
    }
    arsort($class_moys_par_trim[$i]);

    $moy_rappel_t[$i] = $class_moys_par_trim[$i][$id_eleve] ?? null;
    $moy_prem_t[$i]   = !empty($class_moys_par_trim[$i]) ? max($class_moys_par_trim[$i]) : null;
    $moy_dern_t[$i]   = !empty($class_moys_par_trim[$i]) ? min($class_moys_par_trim[$i]) : null;
    if ($moy_rappel_t[$i] !== null) {
        $rr = 1;
        foreach ($class_moys_par_trim[$i] as $m) { if ($m > $moy_rappel_t[$i]) $rr++; else break; }
        $rang_rappel_t[$i] = $rr;
    }
}

// Moyenne annuelle de l'élève — Règle 3 (pv_moy_annuelle_comp(), fonctions.php) :
// somme des moyennes trimestrielles (0 si non classé ce trimestre) / nombre
// de trimestres réellement évalués pour la classe, jamais le nombre de
// trimestres où CET élève a une moyenne — même calcul pour toute la classe,
// réutilisé pour le rang/moyenne de classe/1er/dernier ci-dessous.
$defaut_statut = ['moy' => null, 'classable' => false, 'annule' => false, 'evalue' => false];
$all_moys = [];
foreach ($enrolled_ids as $eid) {
    $par_trim = [
        $statut_par_trim[0][$eid] ?? $defaut_statut,
        $statut_par_trim[1][$eid] ?? $defaut_statut,
        $statut_par_trim[2][$eid] ?? $defaut_statut,
    ];
    $r = pv_moy_annuelle_comp($par_trim);
    if ($r['moy'] !== null) $all_moys[$eid] = $r['moy'];
}
arsort($all_moys);

// Données élève courant
$moy_generale = $all_moys[$id_eleve] ?? null;
$rang_general = null;
if ($moy_generale !== null) {
    $rang_general = 1;
    foreach ($all_moys as $m) { if ($m > $moy_generale) $rang_general++; else break; }
}
$moy_premier   = !empty($all_moys) ? max($all_moys) : null;
$moy_dernier   = !empty($all_moys) ? min($all_moys) : null;
$moy_classe    = !empty($all_moys) ? array_sum($all_moys) / count($all_moys) : null;
$nb_classes    = count($all_moys);
$nb_admis      = count(array_filter($all_moys, fn($m) => $m >= 10));
$taux_reussite = $nb_classes > 0 ? round($nb_admis / $nb_classes * 100, 2) : 0;

// Stats par matière (min/avg/max/rang) — sur la moyenne ANNUELLE de chaque
// matière (moyenne de ses 3 moyennes trimestrielles, voir mat_avg_trim_annuel()).
$mat_stats = [];
foreach ($disciplines as $d) {
    $id_mat = $d['id_mat'];
    $avgs = [];
    foreach ($enrolled_ids as $eid) {
        [, , , $avg] = mat_avg_trim_annuel_comp($eid, $id_mat, $dcomp_par_trim);
        if ($avg !== null) $avgs[] = $avg;
    }
    if (empty($avgs)) {
        $mat_stats[$id_mat] = ['min' => null, 'avg' => null, 'max' => null, 'avgs_sorted' => []];
    } else {
        rsort($avgs);
        $mat_stats[$id_mat] = ['min' => min($avgs), 'avg' => array_sum($avgs) / count($avgs), 'max' => max($avgs), 'avgs_sorted' => $avgs];
    }
}

// Heures d'absence par trimestre ET cumulées sur l'année (fonctions.php::
// eleve_absence_trimestre()) — nécessaires au récapitulatif (colonnes
// T1/T2/T3/Annuel, section 5) et aux mentions automatiques ci-dessous.
// Calculées ici, AVANT la génération du PDF (déplacé depuis la section 5),
// pour connaître le nombre réel de lignes de l'appréciation générée et
// dimensionner $row_h en conséquence (section 4) — demande explicite :
// hauteur de ligne des matières calculée automatiquement selon l'espace
// réellement disponible, sans sur-réserver une estimation forfaitaire.
$heur_jus_t = [0, 0, 0]; $heur_nj_t = [0, 0, 0];
foreach ($trimestres as $i => $t) {
    if (!$t) continue;
    $abs_reelle_i   = eleve_absence_trimestre($eleve['matricule'] ?? '', (int)$t['id'], $insc['id_classe'], $val_annee);
    $heur_jus_t[$i] = (int) $abs_reelle_i['jus'];
    $heur_nj_t[$i]  = (int) $abs_reelle_i['non_jus'];
}
$heur_jus = array_sum($heur_jus_t);
$heur_nj  = array_sum($heur_nj_t);

// Mentions par trimestre et pour l'année (voir bulletin_mentions_periode()
// plus haut) — utilisées à la fois pour les icônes du récapitulatif et pour
// le texte d'appréciation généré ci-dessous.
$mentions_t = [];
foreach ([0, 1, 2] as $i) { $mentions_t[$i] = bulletin_mentions_periode($moy_rappel_t[$i], $heur_nj_t[$i]); }
$mentions_an = bulletin_mentions_periode($moy_generale, $heur_nj);

// Matières où la moyenne annuelle est insuffisante (<10), pour la ligne
// "Des efforts s'imposent en" de l'appréciation du conseil.
$matieres_faibles = [];
foreach ($disciplines as $d) {
    $avg_an_d = mat_avg_trim_annuel_comp($id_eleve, (int)$d['id_mat'], $dcomp_par_trim);
    $avg_an_d = end($avg_an_d);
    if ($avg_an_d !== null && $avg_an_d < 10) $matieres_faibles[] = $d['matiere'];
}
$appreciation_annuelle = $moy_generale !== null ? bulletin_appreciation_annuelle($mentions_an, $matieres_faibles) : '';

// ── Génération PDF ────────────────────────────────────────────────
$pdf = new PDF_LTM('P', 'mm', 'A4');
$pdf->SetMargins(8, 8, 8);
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

pdf_filigrane($pdf, $etab, $pw, $ph);

// Cadre extérieur à coins arrondis (comme le modèle papier)
$pdf->SetDrawColor(0, 0, 0);
$pdf->SetLineWidth(0.4);
$pdf->RoundedRect(4, 4, $pw - 8, $ph - 8, 4, 'D');
$pdf->SetLineWidth(0.2);

// ── 1. En-tête bilingue ──────────────────────────────────────────
$col3 = $uw / 3;
$y0   = 8;

$pdf->SetXY($ml, $y0);
$pdf->SetFont('Arial', '', 6.5);
$pdf->MultiCell($col3, 3.5, u(
    "REPUBLIQUE DU CAMEROUN\nPaix - Travail - Patrie\n***************\n" .
    ($etab['region_fr'] ?? "VOTRE REGION ICI") . "\n" .
    ($etab['departement_fr'] ?? 'DEPARTEMENT ') . "\n" .
    ($etab['arrondissement_fr'] ?? 'ARRONDISSEMENT') . "\n" .
    strtoupper($etab['nom_fr'] ?? 'NOM ECOLE ') . "\n" .
    "B.P. " . ($etab['boite_postale'] ?? 'XX') . " ville Tel.: " . ($etab['telephone'] ?? '') . "\n" .
    ($etab['email'] ?? 'adresse email')
), 0, 'C');

// Logo agrandi et centré horizontalement ET verticalement dans la bande du
// milieu (demande explicite, était calé en haut) — hauteur réelle déduite du
// ratio de l'image pour un centrage vertical exact quel que soit son format.
$logo_path = !empty($etab['logo']) ? __DIR__ . '/../../../assets/uploads/' . $etab['logo'] : '';
$logo_bande_h = 25; // hauteur du bloc en-tête (y0=8 à 33, voir plus bas)
$logo_w = 28;
$logo_h = $logo_w;
if ($logo_path && is_file($logo_path)) {
    $dim = @getimagesize($logo_path);
    if ($dim && $dim[0] > 0) $logo_h = $logo_w * $dim[1] / $dim[0];
}
$logo_x = $ml + $col3 + ($col3 - $logo_w) / 2;
$logo_y = $y0 + ($logo_bande_h - $logo_h) / 2;
if ($logo_path && is_file($logo_path)) {
    $pdf->Image($logo_path, $logo_x, $logo_y+3, $logo_w);
} else {
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetXY($logo_x, $y0 + ($logo_bande_h - 16) / 2);
    $pdf->Cell($logo_w, 16, u($etab['sigle'] ?? 'LTM'), 1, 0, 'C');
}

$xr = $ml + $col3 * 2;
$pdf->SetXY($xr, $y0);
$pdf->SetFont('Arial', '', 6.5);
$pdf->MultiCell($col3, 3.5, u(
    "REPUBLIC OF CAMEROON\nPeace - Work - Fatherland\n***************\n" .
    ($etab['region_en'] ?? 'ADAMAWA REGION') . "\n" .
    ($etab['division_en'] ?? 'VINA DIVISION') . "\n" .
    ($etab['subdivision_en'] ?? 'MBE SUBDIVISION') . "\n" .
    strtoupper($etab['nom_en'] ?? 'GTHS OF MBE') . "\n" .
    "P.O. BOX. " . ($etab['boite_postale'] ?? '32') . " Mbe  Phone: " . ($etab['telephone'] ?? '') . "\n" .
    ($etab['email'] ?? 'lyceetechniquembe@yahoo.fr')
),
0, 'C');

$pdf->SetY(max($pdf->GetY(), 33)-2.5);
$pdf->SetFont('Arial', 'I', 7);
$pdf->SetX($ml);
$pdf->Cell($uw, 4, u('IMMATRICULATION : ' . ($etab['immatriculation'] ?? '2JH1TEFD110316102')), 0, 1, 'C');

// ── 2. Titre (bandeau en pilule, comme le modèle) ──────────────────
// Titre FR + traduction EN en italique en dessous (demande explicite),
// même principe bilingue que le reste du bulletin — bandeau agrandi (8→9.5)
// pour accueillir les 2 lignes sans les tasser.
$y_titre = $pdf->GetY();
$h_titre = 9.5;
$pdf->SetFillColor(219, 228, 245); // bleu très clair
$pdf->SetDrawColor(26, 60, 107);   // bleu marine (couleur de marque)
$pdf->SetLineWidth(0.3);
$pdf->RoundedRect($ml, $y_titre, $uw, $h_titre, $h_titre / 2, 'FD');
$pdf->SetXY($ml, $y_titre + 0.3);
$pdf->SetFont('Arial', 'BI', 17.5);
$pdf->SetTextColor(26, 60, 107);
$pdf->Cell($uw, $h_titre * 0.6, u('BULLETIN DE NOTES ANNUEL'), 0, 2, 'C');
$pdf->SetFont('Arial', 'I', 11);
$pdf->Cell($uw, $h_titre * 0.38, u('Annual Report Card'), 0, 1, 'C');
$pdf->SetTextColor(0, 0, 0);
$pdf->SetLineWidth(0.2);
// "Année scolaire" (accent rétabli, demande explicite) en gras.
$pdf->SetFont('Arial', 'B', 9);
$pdf->SetX($ml);
$pdf->Cell($uw, 5, u('Année scolaire : ' . $val_annee), 0, 1, 'C');

// ── 3. Infos élève ───────────────────────────────────────────────
$pdf->Ln(0.5);

function checkbox_ltm(FPDF $pdf, float $x, float $y, bool $checked, float $s = 3): void {
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->Rect($x, $y, $s, $s);
    if ($checked) {
        $pdf->SetLineWidth(0.35);
        $pdf->Line($x + 0.4, $y + 0.4, $x + $s - 0.4, $y + $s - 0.4);
        $pdf->Line($x + $s - 0.4, $y + 0.4, $x + 0.4, $y + $s - 0.4);
        $pdf->SetLineWidth(0.2);
    }
}

// Compte le nombre de lignes PHYSIQUES qu'occupera un texte dans un
// MultiCell() de largeur $w (reproduit l'algorithme de retour à la ligne de
// FPDF — mot par mot, jamais au milieu d'un mot — pour prévoir la hauteur
// réelle AVANT de dessiner, notamment l'appréciation du conseil qui peut
// contenir une longue liste de matières faibles et déborder sur plusieurs
// lignes malgré un seul \n dans le texte source). $largeur_max - 2 reproduit
// la marge interne cMargin (1mm de chaque côté, jamais modifiée dans ce
// fichier) utilisée par MultiCell().
function texte_nb_lignes(FPDF $pdf, string $texte, float $w, string $style, float $taille): int {
    $pdf->SetFont('Arial', $style, $taille);
    $dispo = $w - 2;
    $nb = 0;
    foreach (explode("\n", $texte) as $para) {
        if ($para === '') { $nb++; continue; }
        $ligne = '';
        foreach (explode(' ', $para) as $mot) {
            $essai = $ligne === '' ? $mot : $ligne . ' ' . $mot;
            if ($ligne !== '' && $pdf->GetStringWidth(u($essai)) > $dispo) {
                $nb++;
                $ligne = $mot;
            } else {
                $ligne = $essai;
            }
        }
        $nb++;
    }
    return max(1, $nb);
}

// Dessine un rang ("2e/22", ...) avec le "e" ordinal en exposant (demande
// explicite : tous les rangs affichés) — voir secondaire/pages/bulletins/pdf.php pour
// le détail (même logique, même fonction dupliquée ici par convention du
// fichier).
function cell_rang(FPDF $pdf, float $w, float $h, ?int $rang, string $suffixe, int $border, string $align, float $taille, bool $bold = true): void {
    $x = $pdf->GetX(); $y = $pdf->GetY();
    $style = $bold ? 'B' : '';
    if ($border) { $pdf->Rect($x, $y, $w, $h); }
    if ($rang === null) {
        $pdf->SetFont('Arial', $style, $taille);
        $pdf->SetXY($x, $y);
        $pdf->Cell($w, $h, '-', 0, 0, $align);
        $pdf->SetXY($x + $w, $y);
        return;
    }

    $part1 = (string)$rang;
    $taille_e = round($taille * 0.62, 2);
    $pdf->SetFont('Arial', $style, $taille);
    $w1 = $pdf->GetStringWidth($part1);
    $w2 = $pdf->GetStringWidth(u($suffixe));
    $pdf->SetFont('Arial', $style, $taille_e);
    $we = $pdf->GetStringWidth('e');
    $total = $w1 + $we + $w2;
    if ($align === 'C')     { $x0 = $x + ($w - $total) / 2; }
    elseif ($align === 'R') { $x0 = $x + $w - $total - 1.2; }
    else                    { $x0 = $x + 1.2; }

    $pdf->SetFont('Arial', $style, $taille);
    $pdf->SetXY($x0, $y);
    $pdf->Cell($w1, $h, $part1, 0, 0, 'L');

    $raise = $taille * 0.12;
    $pdf->SetXY($x0 + $w1, $y - $raise);
    $pdf->SetFont('Arial', $style, $taille_e);
    $pdf->Cell($we, $h, 'e', 0, 0, 'L');

    $pdf->SetXY($x0 + $w1 + $we, $y);
    $pdf->SetFont('Arial', $style, $taille);
    $pdf->Cell($w2, $h, u($suffixe), 0, 0, 'L');

    $pdf->SetXY($x + $w, $y);
}

// Réduit par petits pas la taille de police d'un texte (dans le style donné,
// B/I/etc. — l'empattement gras/italique change la largeur réelle du texte)
// jusqu'à ce qu'il tienne dans $largeur_max, jamais en dessous de $taille_min.
// Utilisé par cell2l() pour que $taille_fr/$taille_en soient des tailles
// MAXIMALES : on peut les augmenter sans risque, chaque cellule reçoit alors
// automatiquement la plus grande taille qui tient réellement dans sa largeur.
function cell2l_taille_ajustee(FPDF $pdf, string $texte, string $style, float $largeur_max, float $taille_max, float $taille_min): float {
    $taille = $taille_max;
    $pdf->SetFont('Arial', $style, $taille);
    while ($taille > $taille_min && $pdf->GetStringWidth(u($texte)) > $largeur_max) {
        $taille -= 0.25;
        $pdf->SetFont('Arial', $style, $taille);
    }
    return $taille;
}

// Cellule à 2 lignes (libellé FR en gras + traduction EN en italique, plus
// petite, juste en dessous) — reproduit le style bilingue du modèle de
// bulletin fourni par l'utilisateur (section basse : DISCIPLINES/TRAVAIL,
// PROFIL DE LA CLASSE, RESULTATS DE L'ELEVE). $en = '' pour une cellule à
// une seule ligne (centrée verticalement, ex. MOYENNE/RANG). Repositionne le
// curseur en fin de cellule comme le ferait un Cell() normal (ln=0).
// $taille_fr/$taille_en sont des MAXIMUMS (demande explicite : polices plus
// grandes mais sans jamais déborder du cadre) — réduits automatiquement si
// besoin par cell2l_taille_ajustee(), avec un plancher de lisibilité minimal.
function cell2l(FPDF $pdf, float $w, float $h, string $fr, string $en, string $align = 'L', bool $fill = false, float $taille_fr = 6, float $taille_en = 4.3, array $fill_color = [216, 210, 248], bool $tight = false): void {
    $x = $pdf->GetX(); $y = $pdf->GetY();
    if ($fill) { $pdf->SetFillColor($fill_color[0], $fill_color[1], $fill_color[2]); $pdf->Rect($x, $y, $w, $h, 'F'); }
    $pdf->Rect($x, $y, $w, $h);
    // Marge de sécurité (offset 0.8+0.4 + cMargin interne de Cell()) — même
    // principe que fpdf_texte_ajuste() ailleurs dans ce fichier.
    $dispo = $w - 2.4;
    if ($en === '') {
        $taille_fr = cell2l_taille_ajustee($pdf, $fr, 'B', $dispo, $taille_fr, 5.5);
        $pdf->SetFont('Arial', 'B', $taille_fr);
        $pdf->SetXY($x + 0.8, $y);
        $pdf->Cell($w - 1.2, $h, u($fr), 0, 0, $align);
    } else {
        $taille_fr = cell2l_taille_ajustee($pdf, $fr, 'B', $dispo, $taille_fr, 5.5);
        $taille_en = cell2l_taille_ajustee($pdf, $en, 'I', $dispo, $taille_en, 4.5);
        // Interligne FR/EN resserré (demande explicite) : quasiment plus de
        // marge morte entre les 2 lignes, pour laisser le plus de place
        // possible au texte lui-même. $tight (demande explicite, case
        // d'identification élève) va plus loin : répartition stricte 50/50
        // sans aucun décalage haut, pour 2 lignes de MÊME taille de police.
        if ($tight) {
            [$y_fr, $h_fr, $y_en, $h_en] = [$y, $h * 0.5, $y + $h * 0.5, $h * 0.5];
        } else {
            [$y_fr, $h_fr, $y_en, $h_en] = [$y + $h * 0.02, $h * 0.52, $y + $h * 0.54, $h * 0.44];
        }
        $pdf->SetFont('Arial', 'B', $taille_fr);
        $pdf->SetXY($x + 0.8, $y_fr);
        $pdf->Cell($w - 1.2, $h_fr, u($fr), 0, 0, $align);
        $pdf->SetFont('Arial', 'I', $taille_en);
        $pdf->SetXY($x + 0.8, $y_en);
        $pdf->Cell($w - 1.2, $h_en, u($en), 0, 0, $align);
    }
    $pdf->SetXY($x + $w, $y);
}

// (pas de libelle_seq_court() ici : voir trimestre_court() plus haut, utilisé
// PARTOUT dans ce bulletin — en-tête du tableau de notes et ligne "Rappel".)

$photo_w = 26;
$photo_h = 29.5;
$x_photo = $ml;
$y_photo = $pdf->GetY();
$x_info  = $ml + $photo_w;
$w_info  = $uw - $photo_w;

// Photo de l'élève si disponible en base, sinon avatar par défaut selon le
// sexe (masculin/féminin) qui indique visuellement l'emplacement réservé à
// la photo — plus de case grise vide "PHOTO" (demande explicite).
$photo_path  = !empty($eleve['photo']) ? __DIR__ . '/../../../assets/uploads/eleves/' . $eleve['photo'] : '';
$photo_reelle = $photo_path && is_file($photo_path);
if (!$photo_reelle) {
    $avatar_fichier = (strtoupper($eleve['sexe'] ?? '') === 'F') ? 'fille.png' : 'garcon.png';
    $photo_path = __DIR__ . '/../../../assets/img/avatars/' . $avatar_fichier;
}
$pdf->SetDrawColor(0, 0, 0);
$pdf->Rect($x_photo, $y_photo, $photo_w, $photo_h);
if (is_file($photo_path)) {
    $pdf->Image($photo_path, $x_photo + 0.5, $y_photo + 0.5, $photo_w - 1, $photo_h - 1);
}

$gris = [230, 230, 230];
$pdf->SetFillColor(230, 230, 230);
$pdf->SetFont('Arial', 'BI', 18.5);

$wL1 = [24, 34, 18, 14, 18, 60]; // somme = 168 = $w_info
$pdf->SetXY($x_info, $y_photo);
cell2l($pdf, 30, 5.5, 'CLASSE :', 'Class', 'L', true, 8, 7.5, $gris, true);
$pdf->SetFont('Arial', 'I', 8);
$pdf->Cell(45, 5.5, u($insc['classe'] ?? ''), 1, 0, 'L');
cell2l($pdf, $wL1[2], 5.5, 'EFFECTIF :', 'Size', 'L', true, 8, 7.5, $gris, true);
$pdf->SetFont('Arial', 'I', 8);
$pdf->Cell($wL1[3], 5.5, (string)$nb_inscrits, 1, 0, 'C');
cell2l($pdf, 41, 5.5, 'IDENTIFIANT UNIQUE (NIU) :', 'ID No.', 'L', true, 8, 7.5, $gris, true);
$pdf->SetFont('Arial', 'I', 8);

$pdf->Cell(20, 5.5, id_affichage_eleve($eleve), 1, 1, 'C');
$pdf->ln(0.5);
$pdf->SetX($x_info);
//$pdf->SetFont('Arial', 'B', 17);
cell2l($pdf, 30, 5.5, 'NOM ET PRENOMS :', 'ID No.', 'L', true, 8, 7.5, $gris, true);
//	cell2l($pdf, 26, 5.5, 'NOM ET PRENOMS :', 'Name', 'L', true, 28, 7.5, $gris, true);
$pdf->SetFont('Arial', 'BI', 9);
$pdf->Cell(110, 5.5, u(strtoupper($eleve['nom']) . ' ' . ($eleve['prenom'] ?? '')), 1, 0, 'L');
cell2l($pdf, 16, 5.5, 'GENRE :', 'Gender', 'L', true, 8, 7.5, $gris, true);
$pdf->SetFont('Arial', 'I', 8);
$pdf->Cell(12, 5.5, $eleve['sexe'] ?? '', 1, 1, 'C');

$pdf->ln(0.5);
$pdf->SetX($x_info);
cell2l($pdf, 18, 5.5, 'NE(E) LE :', 'Born on', 'L', true, 8, 7.5, $gris, true);
$pdf->SetFont('Arial', 'I', 8);
$dnaiss = $eleve['date_naiss'] ? date('d/m/Y', strtotime($eleve['date_naiss'])) : '';
$pdf->Cell(22, 5.5, $dnaiss, 1, 0, 'C');
$pdf->SetFont('Arial', 'B', 8);
$pdf->Cell(10, 5.5, 'A/at', 1, 0, 'C', true);
$pdf->SetFont('Arial', 'I', 8);
$pdf->Cell(64, 5.5, u($eleve['lieu_naiss'] ?? ''), 1, 0, 'L');
cell2l($pdf, 26, 5.5, 'REDOUBLANT :', 'Repeater', 'L', true, 8, 7.5, $gris, true);
$is_redoub = (strtolower($insc['statut'] ?? '') === 'redoublant');
$y_row3 = $pdf->GetY();
$x_row3 = $pdf->GetX();
$pdf->Cell(28, 5.5, '', 1, 1, 'L');
$pdf->SetFont('Arial', 'I', 8);
$pdf->SetXY($x_row3 + 1, $y_row3 + 1);
$pdf->Cell(7, 3, 'Oui', 0, 0, 'L');
checkbox_ltm($pdf, $x_row3 + 8, $y_row3 + 1, $is_redoub);
$pdf->SetXY($x_row3 + 15, $y_row3 + 1);
$pdf->Cell(7, 3, 'Non', 0, 0, 'L');
checkbox_ltm($pdf, $x_row3 + 23, $y_row3 + 1, !$is_redoub);

//$pdf->ln(20);
$pdf->SetXY($x_info, $y_row3 + 6);
//$pdf->ln();
cell2l($pdf, 30, 5.5, 'PROF PRINCIPAL :', 'Class Teacher', 'L', true, 8, 7.5, $gris, true);
$pdf->SetFont('Arial', 'I', 8);
$pdf->Cell(138, 5.5, u($nom_pp), 1, 1, 'L');

$pdf->ln(0.5);
$pdf->SetX($x_info);
cell2l($pdf, 42, 5.5, 'NOM ET CONTACTS DES PARENTS :', "Parent's Name & Contact", 'L', true, 8, 7.5, $gris, true);
$pdf->SetFont('Arial', '', 7);
$pdf->Cell(126, 5.5, u($contacts_parents), 1, 1, 'L');

$pdf->SetY(max($pdf->GetY(), $y_photo + $photo_h));

// ── 4. Tableau des disciplines ────────────────────────────────────
$pdf->Ln(1);
$y_table0 = $pdf->GetY();
$hdr_h = 5.5;

// Plus de regroupement par groupe_lib ni de ligne TOTAL par groupe (chantier
// APC — abandonnés en Phase 5 pour le bulletin trimestriel individuel, voir
// secondaire/pages/bulletins/pdf.php, harmonisé ici et dans pdf_classe.php) : une ligne
// par matière directement, dans l'ordre de $disciplines.

// Hauteurs/données de la section basse (bandeaux + décision), déclarées ici
// (avant le tableau) pour pouvoir calculer l'espace fixe qu'elles réservent
// en bas de page — réutilisées telles quelles plus loin, section 5 et 6.
// $h_bas ne sert plus que pour le bandeau DECISION/OBSERVATIONS (section 6).
$h_bas = 7;
// Hauteur harmonisée de toutes les lignes RECAPITULATIF DISCIPLINES/
// RESULTATS DE L'ELEVE (bandeau de titre inclus) — demande explicite : une
// seule hauteur pour toute cette section.
$h_ligne = 4.8;
// Hauteur des lignes de la case DECISION DU CONSEIL DE CLASSE (PROMU/
// REDOUBLE/EXCLU + 6 motifs), même hauteur que l'ancienne liste DECISION du
// trimestriel (cohérence visuelle).
$h_dec = 3.2;
// 13 = marge bas + place réservée à la mention copyright.
// 13 = marge bas + copyright ; +22 = QR code désormais centré en bas de
// page (demande explicite), plus la place jusqu'au copyright, réservés en
// dehors du cadre OBSERVATIONS.
$bas_dispo = $ph - 13 - 22;

// Hauteur de ligne du tableau ajustée dynamiquement (demande explicite : le
// bulletin d'un élève ne doit jamais dépasser une seule page, quel que soit
// le nombre de matières). Calculée à partir de l'espace réellement
// disponible entre le tableau et le bas de page, une fois réservée la
// hauteur fixe des sections qui suivent. Plancher à 3.2mm pour rester
// lisible ; plafond à 4.5mm pour ne pas grossir inutilement quand il y a peu
// de matières.
// RECAPITULATIF DISCIPLINES/RESULTATS DE L'ELEVE : 9 lignes de $h_ligne (1
// bandeau + 1 en-tête colonnes + 7 lignes, modèle fourni par l'utilisateur).
// DECISION DES CONSEILS DE FIN D'ANNEE/OBSERVATIONS : 1 bandeau ($h_bas) +
// hauteur RÉELLE de l'appréciation générée (nombre de lignes PHYSIQUES,
// retours à la ligne automatiques inclus — ex. la liste "Des efforts
// s'imposent en : ..." peut occuper plusieurs lignes malgré un seul \n dans
// le text3 source, voir texte_nb_lignes() — au lieu d'une estimation
// forfaitaire) + case DECISION DU CONSEIL DE CLASSE (9 lignes) pour ne
// jamais déborder sur une 2e page. Le QR code et le copyright ne sont PAS
// recomptés ici : $bas_dispo réserve déjà 22+13mm pour eux tout en bas de
// page (bug corrigé — un ancien +25mm faisait doublon avec cette
// réservation et écrasait inutilement la hauteur de ligne disponible pour
// le tableau des disciplines).
$n_lignes_appr  = texte_nb_lignes($pdf, $appreciation_annuelle ?: '-', $uw * 0.55, '', 7.5);
$h_appreciation = 2 + $n_lignes_appr * 4;
$footer_fixe = 1 + 9 * $h_ligne + 1 + $h_bas + $h_appreciation + 9 * $h_dec /* décision conseil de classe */;
$n_lignes_tab = count($disciplines);
$row_h = $n_lignes_tab > 0
    ? max(3.2, min(4.5, ($bas_dispo - $y_table0 - $hdr_h - $footer_fixe) / $n_lignes_tab))
    : 4.5;

// Largeurs colonnes — toujours 3 colonnes trimestre (T1/T2/T3) + 1 colonne
// MOY ANNUELLE, quel que soit le nombre de séquences par trimestre (demande
// explicite : bulletin annuel, jamais de mode séquence unique).
$cD   = 52;
$cCF  = 9;
$cNXC = 13;
$cRG  = 9;
// Réduite au profit d'ENSEIGNANTS (demande explicite, encore réduite lors
// d'un 2e passage) — vérifié par un test MultiCell réel : le texte le plus
// long ("Compétences TB") tient toujours sur exactement 2 lignes à 20mm (3
// lignes dès 19mm, ce qui casserait l'alignement avec row_h/2).
$cMEN = 20;
$cMIN = 9;
$cMOY = 9;
$cMAX = 9;

// cTR = colonne MOY ANNUELLE ; cENS (ENSEIGNANT ET SIGNATURE) doit rester
// lisible (12mm mini) — même logique que pdf.php, avec un nombre de
// colonnes trimestre toujours fixé à 3 (au lieu de $nb_seqs séquences).
$cTR      = 11;
$cENS_min = 12;
$reste_fixe = $cD + $cTR + $cCF + $cNXC + $cRG + $cMEN + $cMIN + $cMOY + $cMAX + $cENS_min;
$cEval = min(10, max(0, floor(($uw - $reste_fixe) / 3)));
$cENS  = $uw - $cD - $cEval * 3 - $cTR - $cCF - $cNXC - $cRG - $cMEN - $cMIN - $cMOY - $cMAX;

// En-tête tableau
$pdf->SetFont('Arial', 'B', 6.5);
$pdf->SetFillColor(26, 60, 107);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetX($ml);
$pdf->Cell($cD, $hdr_h, 'DISCIPLINES', 1, 0, 'C', true);
foreach ([1, 2, 3] as $pos) {
    $pdf->Cell($cEval, $hdr_h, u(trimestre_court($pos)), 1, 0, 'C', true);
}
$pdf->Cell($cTR, $hdr_h, u('MOY AN.'), 1, 0, 'C', true);
$pdf->Cell($cCF,  $hdr_h, 'COEF',    1, 0, 'C', true);
$pdf->Cell($cNXC, $hdr_h, '(NXC)',   1, 0, 'C', true);
$pdf->Cell($cRG,  $hdr_h, 'RANG',    1, 0, 'C', true);
$pdf->Cell($cMEN, $hdr_h, 'MENTIONS',1, 0, 'C', true);
$pdf->Cell($cMIN, $hdr_h, 'MIN',     1, 0, 'C', true);
$pdf->Cell($cMOY, $hdr_h, 'MOY',     1, 0, 'C', true);
$pdf->Cell($cMAX, $hdr_h, 'MAX',     1, 0, 'C', true);
$pdf->Cell($cENS, $hdr_h, u('ENSEIGNANTS'), 1, 1, 'C', true);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFillColor(255, 255, 255);

foreach ($disciplines as $d) {
    $id_mat = (int)$d['id_mat'];
    // $avg_t = [avg_t1, avg_t2, avg_t3] (moyennes trimestrielles de cette
    // matière), $avg = moyenne annuelle (moyenne des 3, demande explicite).
    $avg_t3v = mat_avg_trim_annuel_comp($id_eleve, $id_mat, $dcomp_par_trim);
    $avg     = array_pop($avg_t3v);
    $avg_t   = $avg_t3v; // [avg_t1, avg_t2, avg_t3]
    $stat   = $mat_stats[$id_mat] ?? ['min' => null, 'avg' => null, 'max' => null, 'avgs_sorted' => []];
    $rang   = ($avg !== null && !empty($stat['avgs_sorted'])) ? rang_eleve_b($avg, $stat['avgs_sorted']) : null;
    $nxc    = ($avg !== null) ? $avg * $d['coef'] : null;
    $men    = ($avg !== null) ? mention_mat_b($avg) : '';

    $bg = ($avg !== null && $avg < 10) ? [255, 235, 235] : [255, 255, 255];
    $pdf->SetFillColor(...$bg);
    // Nom de la matière/compétence : taille agrandie par défaut (8pt) pour
    // les noms courts, réduite automatiquement (jusqu'à 5.5pt) pour les
    // noms longs afin de ne jamais déborder sur une 2e ligne (demande explicite).
    [, $mat_lib] = fpdf_texte_ajuste($pdf, $d['matiere'], $cD, 8.0, 5.5);
    $pdf->SetX($ml);
    $pdf->Cell($cD, $row_h, u($mat_lib), 1, 0, 'L', true);
    $pdf->SetFont('Arial', '', 6.5);

    // Valeurs de notes agrandies pour la lisibilité (demande explicite),
    // sauf MENTIONS et ENSEIGNANTS qui restent inchangées. 3 colonnes
    // trimestre (moyenne de la matière pour ce trimestre) + MOY ANNUELLE.
    $pdf->SetFont('Arial', 'B', 8);
    foreach ($avg_t as $at) {
        $pdf->Cell($cEval, $row_h, $at !== null ? fmt_note_b($at) : '', 1, 0, 'C', true);
    }
    if ($avg !== null) {
        if ($avg >= 10) $pdf->SetTextColor(0, 100, 0); else $pdf->SetTextColor(180, 0, 0);
    }
    $pdf->Cell($cTR, $row_h, $avg !== null ? fmt_note_b($avg) : '', 1, 0, 'C', true);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Arial', '', 6.5);

    // Pas de note = matière non comptée dans les totaux : coefficient
    // laissé vide plutôt qu'affiché.
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell($cCF,  $row_h, $avg !== null ? (string)$d['coef'] : '', 1, 0, 'C', true);
    $pdf->Cell($cNXC, $row_h, $nxc !== null ? fmt_note_b($nxc) : '', 1, 0, 'C', true);
    $pdf->Cell($cRG,  $row_h, $rang !== null ? (string)$rang : '', 1, 0, 'C', true);
    $pdf->SetFont('Arial', '', 6.5);

    if ($men === '') {
        // Pas de mention (aucune note) : une Cell() normale à la hauteur
        // exacte de la ligne — une MultiCell vide dessinerait un cadre
        // plus court que le reste de la ligne (aspect "fragmenté").
        $pdf->Cell($cMEN, $row_h, '', 1, 0, 'C', true);
    } else {
        $x_men = $pdf->GetX(); $y_men = $pdf->GetY();
        // $men contient toujours exactement 2 lignes (séparées par \n,
        // voir mention_mat_b()) : hauteur de ligne = row_h/2 pile, pour
        // que le total corresponde exactement à la hauteur des cellules
        // voisines (pas d'arrondi qui décale le cadre). Taille inchangée
        // (demande explicite : colonne MENTIONS exclue de l'agrandissement).
        $pdf->MultiCell($cMEN, $row_h / 2, u($men), 1, 'C', true);
        $pdf->SetXY($x_men + $cMEN, $y_men);
    }

    $pdf->SetFont('Arial', 'B', 7.5);
    $pdf->Cell($cMIN, $row_h, $stat['min'] !== null ? fmt_note_b($stat['min']) : '', 1, 0, 'C', true);
    $pdf->Cell($cMOY, $row_h, $stat['avg'] !== null ? fmt_note_b($stat['avg']) : '', 1, 0, 'C', true);
    $pdf->Cell($cMAX, $row_h, $stat['max'] !== null ? fmt_note_b($stat['max']) : '', 1, 0, 'C', true);
    $pdf->SetFont('Arial', '', 6.5);

    // Nom de l'enseignant : réduit la taille de police si besoin (jusqu'à
    // 4pt) avant de tronquer mot par mot en dernier recours — ne déborde
    // plus jamais de la colonne, quelle que soit sa largeur réelle. Taille
    // inchangée (demande explicite : colonne ENSEIGNANTS exclue de l'agrandissement).
    [$taille_ens, $ens_lib] = fpdf_texte_ajuste($pdf, $d['enseignant'] ?? '', $cENS, 5.5, 4.0);
    $pdf->Cell($cENS, $row_h, u($ens_lib), 1, 0, 'L', true);
    $pdf->SetFont('Arial', '', 6.5);
    $pdf->Ln();
}

// ── 5. Récapitulatif disciplines + Résultats de l'élève (bilan annuel) ──
// Remplace les bandeaux DISCIPLINES/TRAVAIL/PROFIL DE LA CLASSE du
// trimestriel par 2 tableaux à 4 colonnes (1er/2e/3e trimestre + Annuel) et
// un bloc MOYENNE ANNUELLE/RANG/COTE — modèle fourni par l'utilisateur
// (image), demande explicite. Même hauteur de ligne ($h_ligne) et même
// police auto-ajustée (cell2l/cell2l_taille_ajustee) que le reste du bulletin.
$pdf->Ln(1);
$y_bas = $pdf->GetY();

// $heur_jus_t/$heur_nj_t/$mentions_t/$mentions_an/$matieres_faibles/
// $appreciation_annuelle sont désormais calculés plus haut (avant la
// génération du PDF, avant section 4) — voir juste après $mat_stats — pour
// que $row_h (section 4) connaisse le nombre réel de lignes de
// l'appréciation avant de dessiner le tableau des disciplines.

// Classement par trimestre (nb admis, moyenne de classe, taux de réussite) —
// même principe que les stats annuelles déjà calculées plus haut, mais
// bornées à chaque trimestre via $class_moys_par_trim.
$nb_classes_t = [0, 0, 0]; $nb_admis_t = [0, 0, 0]; $moy_classe_t = [null, null, null]; $taux_reussite_t = [0, 0, 0];
foreach ([0, 1, 2] as $i) {
    $moys_i = $class_moys_par_trim[$i];
    $nb_classes_t[$i] = count($moys_i);
    $moy_classe_t[$i] = $nb_classes_t[$i] > 0 ? array_sum($moys_i) / $nb_classes_t[$i] : null;
    $nb_admis_t[$i]   = count(array_filter($moys_i, fn($m) => $m >= 10));
    $taux_reussite_t[$i] = $nb_classes_t[$i] > 0 ? round($nb_admis_t[$i] / $nb_classes_t[$i] * 100, 2) : 0;
}

// RECAPITULATIF DISCIPLINES réduit à la place juste nécessaire pour des
// valeurs à 4 chiffres max (demande explicite) ; RESULTATS DE L'ELEVE agrandi
// d'autant (ses valeurs — "19e/21", "100.00%" — ont besoin de plus de place).
$wRecap  = 70;
$wResult = 89;
$wFar    = $uw - $wRecap - $wResult; // ~35

// En-têtes des 2 bandeaux (le bloc MOYENNE ANNUELLE/RANG/COTE à droite a son
// propre en-tête "MOYENNE ANNUELLE", pas besoin de bandeau ici). Sous-titre
// EN agrandi (7→8.5, demande explicite) — largement la place (colonnes de
// 70/89mm), toujours sans déborder.
$pdf->SetXY($ml, $y_bas);
cell2l($pdf, $wRecap,  $h_ligne, 'RECAPITULATIF DISCIPLINES', 'Subjects Summary',    'C', true, 9, 8.5);
cell2l($pdf, $wResult, $h_ligne, "RESULTATS DE L'ELEVE",      'Student Performance', 'C', true, 9, 8.5);
$pdf->Ln($h_ligne);

// Colonne des intitulés RECAPITULATIF réduite (34→29mm, toujours largement
// assez pour son texte le plus long "Absences Jus. (h)"/"Conduct reprimand")
// au profit des 4 colonnes de valeurs, pour agrandir l'en-tête 1er/2e/3e
// Trim + Annuel ci-dessous (demande explicite) sans revenir sur la largeur
// $wRecap elle-même (réglée précédemment pour les nombres à 4 chiffres).
$w_rec_lbl = 29; $w_rec_val = ($wRecap - $w_rec_lbl) / 4; // 10.25
$w_res_lbl = 28; $w_res_val = ($wResult - $w_res_lbl) / 4; // ~15.25

// Ligne d'en-têtes de colonnes (1er/2e/3e trimestre + Annuel) — demande
// explicite. Taille UNIFORME sur les 4 cellules de chaque tableau : dans
// RECAPITULATIF, "1er Trim" est le texte le plus large et ne tient qu'à
// 5.5pt max même avec les colonnes élargies ci-dessus (colonnes étroites,
// réservées aux nombres à 4 chiffres) — taille fixée à 5.5 pour les 4
// cellules plutôt que de les laisser à des tailles différentes (5.5 à 6.5)
// selon le texte, ce qui casserait l'alignement visuel entre colonnes.
// RESULTATS DE L'ELEVE, avec des colonnes bien plus larges, reste à 8.5.
$pdf->SetXY($ml, $y_bas + $h_ligne);
$pdf->Cell($w_rec_lbl, $h_ligne, '', 1, 0, 'C');
$pdf->SetFont('Arial', 'B', 10);
foreach ([1, 2, 3] as $pos) { cell2l($pdf, $w_rec_val, $h_ligne, trimestre_court($pos), '', 'C', false, 8, 4); }
cell2l($pdf, $w_rec_val, $h_ligne, 'Annuel', '', 'C', false, 8, 4);
$pdf->SetXY($ml + $wRecap, $y_bas + $h_ligne);
$pdf->Cell($w_res_lbl, $h_ligne, '', 1, 0, 'C');
foreach ([1, 2, 3] as $pos) { cell2l($pdf, $w_res_val, $h_ligne, trimestre_court($pos), '', 'C', false, 8.5, 4); }
cell2l($pdf, $w_res_val, $h_ligne, 'Annuel', '', 'C', false, 8.5, 4);

// Lignes du récapitulatif : 'num' = 4 valeurs chiffrées (T1/T2/T3/Annuel),
// 'bool' = case à cocher sur T1/T2/T3 seulement (l'appréciation annuelle est
// déjà exprimée en toutes lettres plus bas, pas besoin d'une 4e case).
// Police des libellés alignée sur OBSERVATIONS (8.5/7.5), demande explicite.
$recap_rows = [
    ['num',  'Absences NJ. (h)',  'Unjustified Abs.', [$heur_nj_t[0], $heur_nj_t[1], $heur_nj_t[2]], $heur_nj],
    ['num',  'Absences Jus. (h)', 'Justified Abs.',   [$heur_jus_t[0], $heur_jus_t[1], $heur_jus_t[2]], $heur_jus],
    ['bool', 'Blâme conduite',    'Conduct reprimand', [$mentions_t[0]['blame_cond'], $mentions_t[1]['blame_cond'], $mentions_t[2]['blame_cond']], null],
    ['bool', 'Blâme travail',     'Work reprimand',    [$mentions_t[0]['blame_trav'], $mentions_t[1]['blame_trav'], $mentions_t[2]['blame_trav']], null],
    ['bool', "Tab. d'Honneur",    'Honor Roll',        [$mentions_t[0]['tab'] === 'oui', $mentions_t[1]['tab'] === 'oui', $mentions_t[2]['tab'] === 'oui'], null],
    ['bool', 'Encouragements',    'Encouragements',    [$mentions_t[0]['encourag'], $mentions_t[1]['encourag'], $mentions_t[2]['encourag']], null],
    ['bool', 'Félicitations',     'Congratulations',   [$mentions_t[0]['felicit'], $mentions_t[1]['felicit'], $mentions_t[2]['felicit']], null],
];
foreach ($recap_rows as $i => $rr) {
    [$type, $fr, $en, $vals3, $annuel] = $rr;
    $pdf->SetXY($ml, $y_bas + 2 * $h_ligne + $i * $h_ligne);
    cell2l($pdf, $w_rec_lbl, $h_ligne, $fr, $en, 'L', false, 8.5, 7.5);
    if ($type === 'num') {
        foreach ($vals3 as $v) {
            $taille_v = cell2l_taille_ajustee($pdf, (string)$v, 'B', $w_rec_val - 2.4, 8.5, 6);
            $pdf->SetFont('Arial', 'B', $taille_v);
            $pdf->Cell($w_rec_val, $h_ligne, (string)$v, 1, 0, 'C');
        }
        $taille_v = cell2l_taille_ajustee($pdf, (string)$annuel, 'B', $w_rec_val - 2.4, 8.5, 6);
        $pdf->SetFont('Arial', 'B', $taille_v);
        $pdf->Cell($w_rec_val, $h_ligne, (string)$annuel, 1, 0, 'C');
    } else {
        foreach ($vals3 as $v) {
            $x0 = $pdf->GetX(); $y0 = $pdf->GetY();
            $pdf->Rect($x0, $y0, $w_rec_val, $h_ligne);
            checkbox_ltm($pdf, $x0 + $w_rec_val / 2 - 1.25, $y0 + $h_ligne / 2 - 1.25, (bool)$v, 2.5);
            $pdf->SetXY($x0 + $w_rec_val, $y0);
        }
        $pdf->Cell($w_rec_val, $h_ligne, '', 1, 0, 'C');
    }
    $pdf->SetFont('Arial', '', 6.5);
}

// Lignes des résultats — valeurs déjà formatées en texte (permet un rendu
// générique, auto-ajusté, identique pour les 7 lignes), sauf la ligne 'Rang'
// (type 'rang') qui garde le rang brut pour être dessinée via cell_rang()
// avec le "e" ordinal en exposant (demande explicite). Police des libellés
// alignée sur OBSERVATIONS (8.5/7.5), demande explicite.
$fmt_note = fn($v) => $v !== null ? fmt_note_b($v) : '-';
$fmt_rang = fn($r, $n) => $r !== null ? $r . 'e/' . $n : '-'; // pour le calcul de taille uniquement
$fmt_pct  = fn($v) => $v !== null ? $v . '%' : '-';
$result_rows = [
    ['Moyenne',          'Average',          'txt',  [$fmt_note($moy_rappel_t[0]),  $fmt_note($moy_rappel_t[1]),  $fmt_note($moy_rappel_t[2]),  $fmt_note($moy_generale)], true],
    ['Rang',             'Rank',             'rang', [[$rang_rappel_t[0], $nb_classes_t[0]], [$rang_rappel_t[1], $nb_classes_t[1]], [$rang_rappel_t[2], $nb_classes_t[2]], [$rang_general, $nb_classes]], false],
    ['Moy. du premier',  'Highest average',  'txt',  [$fmt_note($moy_prem_t[0]),    $fmt_note($moy_prem_t[1]),    $fmt_note($moy_prem_t[2]),    $fmt_note($moy_premier)], false],
    ['Moy. du dernier',  'Lowest average',   'txt',  [$fmt_note($moy_dern_t[0]),    $fmt_note($moy_dern_t[1]),    $fmt_note($moy_dern_t[2]),    $fmt_note($moy_dernier)], false],
    ["Nb d'Admis",       'Number of passes', 'txt',  [(string)$nb_admis_t[0], (string)$nb_admis_t[1], (string)$nb_admis_t[2], (string)$nb_admis], false],
    ['Moy. Gén. Classe', 'Class average',    'txt',  [$fmt_note($moy_classe_t[0]),  $fmt_note($moy_classe_t[1]),  $fmt_note($moy_classe_t[2]),  $fmt_note($moy_classe)], false],
    ['Taux de réussite', 'Success rate',     'txt',  [$fmt_pct($taux_reussite_t[0]), $fmt_pct($taux_reussite_t[1]), $fmt_pct($taux_reussite_t[2]), $fmt_pct($taux_reussite)], true],
];
foreach ($result_rows as $i => $rr) {
    [$fr, $en, $type, $vals4, $bold] = $rr;
    $pdf->SetXY($ml + $wRecap, $y_bas + 2 * $h_ligne + $i * $h_ligne);
    cell2l($pdf, $w_res_lbl, $h_ligne, $fr, $en, 'L', false, 8.5, 7.5);
    if ($type === 'rang') {
        foreach ($vals4 as [$r, $n]) {
            $txt = $fmt_rang($r, $n);
            $taille_v = cell2l_taille_ajustee($pdf, $txt, 'B', $w_res_val - 2.4, 8.5, 6);
            cell_rang($pdf, $w_res_val, $h_ligne, $r, '/' . $n, 1, 'C', $taille_v);
        }
    } else {
        foreach ($vals4 as $txt) {
            $taille_v = cell2l_taille_ajustee($pdf, $txt, 'B', $w_res_val - 2.4, 8.5, 6);
            $pdf->SetFont('Arial', 'B', $taille_v);
            $pdf->Cell($w_res_val, $h_ligne, $txt, 1, 0, 'C');
        }
    }
    $pdf->SetFont('Arial', '', 6.5);
}

// Bloc MOYENNE ANNUELLE / RANG / COTE, à droite — 3 paires libellé/valeur
// (6 cellules) réparties sur exactement la même hauteur totale que les 2
// tableaux (9 lignes de $h_ligne : 1 bandeau + 1 en-tête colonnes + 7
// lignes), pour rester aligné.
$xFar = $ml + $wRecap + $wResult;
$hFar = (9 * $h_ligne) / 6;
$moy_str  = $moy_generale !== null ? fmt_note_b($moy_generale) . ' / 20' : '- / 20';
$rang_str = $rang_general !== null ? $rang_general . 'e / ' . $nb_classes : '-';
$cote_str = $moy_generale !== null ? lettre_groupe_b($moy_generale) : '-';

// Traductions EN ajoutées sous chaque intitulé, en italique, à la même
// taille que le reste du texte anglais de ces tableaux (7.5, demande
// explicite) — cell2l() passe alors en mode bilingue (2 lignes).
$pdf->SetXY($xFar, $y_bas);
cell2l($pdf, $wFar, $hFar, 'MOYENNE ANNUELLE', 'Annual Average', 'C', true, 9, 7.5);
$pdf->Ln($hFar); $pdf->SetX($xFar);
$taille_moy = cell2l_taille_ajustee($pdf, $moy_str, 'B', $wFar - 2.4, 13, 8);
$pdf->SetFont('Arial', 'B', $taille_moy);
$pdf->SetTextColor(0, 130, 0);
$pdf->Cell($wFar, $hFar, u($moy_str), 1, 1, 'C');
$pdf->SetTextColor(0, 0, 0);

$pdf->SetX($xFar);
cell2l($pdf, $wFar, $hFar, 'RANG', 'Rank', 'C', true, 9, 7.5);
$pdf->Ln($hFar); $pdf->SetX($xFar);
$taille_rang = cell2l_taille_ajustee($pdf, $rang_str, 'B', $wFar - 2.4, 11, 7);
cell_rang($pdf, $wFar, $hFar, $rang_general, ' / ' . $nb_classes, 1, 'C', $taille_rang);
$pdf->Ln($hFar); $pdf->SetX($xFar);

$pdf->SetX($xFar);
cell2l($pdf, $wFar, $hFar, 'COTE', 'Grade', 'C', true, 9, 7.5);
$pdf->Ln($hFar); $pdf->SetX($xFar);
$pdf->SetFont('Arial', 'B', 12);
$pdf->SetTextColor(0, 130, 0);
$pdf->Cell($wFar, $hFar, u($cote_str), 1, 1, 'C');
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('Arial', '', 6.5);

$pdf->SetY($y_bas + 9 * $h_ligne);

// ── 6. Décision de fin d'année ────────────────────────────────────
$pdf->Ln(1);
$y_dec = $pdf->GetY();
$w_dec = $uw * 0.55; $w_obs = $uw - $w_dec;
$x_dec = $ml; $x_obs = $ml + $w_dec;
$pdf->SetXY($ml, $y_dec);
cell2l($pdf, $w_dec, $h_bas, "DECISION DES CONSEILS DE FIN D'ANNEE", 'End of year council decisions', 'C', true, 10.5, 8.5);
cell2l($pdf, $w_obs, $h_bas, "OBSERVATIONS DU CHEF D'ETABLISSEMENT", "Principal's remarks", 'C', true, 10.5, 8.5);
$pdf->Ln($h_bas);

$y_dec2 = $pdf->GetY();
// Le cadre OBSERVATIONS occupe tout l'espace restant jusqu'au bas de page
// (remplit joliment jusqu'à $bas_dispo quand $row_h n'est pas au plafond),
// avec un plancher de 30mm — juste assez pour la date/signature du
// paragraphe 7 ci-dessous — au lieu de l'ancien plancher de 70mm qui
// dépassait $bas_dispo (donc chevauchait le QR) dès que le tableau des
// disciplines (beaucoup de matières) laissait moins de 70mm de reste.
$h_obs_box = max(30, $bas_dispo - $y_dec2);

// Appréciation du conseil, générée automatiquement (texte libre, centré,
// sans cadre — demande explicite d'après le modèle fourni).
$pdf->SetFont('Arial', '', 7.5);
$pdf->SetXY($ml, $y_dec2 + 2);
$pdf->MultiCell($w_dec, 4, u($appreciation_annuelle ?: '-'), 0, 'C');

// DECISION DU CONSEIL DE CLASSE — promotion/redoublement/exclusion, cochée
// et complétée AUTOMATIQUEMENT (demande explicite) si le conseil de classe
// annuel a déjà été saisi et enregistré pour cet élève (module
// secondaire/pages/conseil_classe/, table decision_conseil : decision='Admis'/
// 'Redoublement'/'Exclu'/'Abandon', type='annee', id_trim=0). Si aucune
// décision n'est enregistrée, tout reste vide/décoché — à remplir à la main
// comme avant.
$decision_row = db_one(
    "SELECT dc.decision, dc.observation, c2.designation AS next_classe_designation
     FROM decision_conseil dc
     LEFT JOIN classe c2 ON c2.id = dc.next_classe
     WHERE dc.id_eleve=? AND dc.id_annee=? AND dc.type='annee' AND dc.id_trim=0",
    [$id_eleve, $id_annee]
);
$dec_val          = $decision_row['decision'] ?? null;
$dec_next_classe  = $decision_row['next_classe_designation'] ?? '';
$dec_observation  = $decision_row['observation'] ?? '';

$y_cc = $pdf->GetY() + 2;
$h_cc_titre = 6;
$w_cc = $w_dec * 0.62;
$pdf->SetFillColor(219, 228, 245);
$pdf->SetDrawColor(26, 60, 107);
$pdf->SetLineWidth(0.3);
$pdf->RoundedRect($ml, $y_cc, $w_cc, $h_cc_titre, $h_cc_titre / 2, 'FD');
$pdf->SetXY($ml, $y_cc + 0.3);
$pdf->SetFont('Arial', 'BI', 7.5);
$pdf->SetTextColor(26, 60, 107);
$pdf->Cell($w_cc, $h_cc_titre / 2, u('DECISION DU CONSEIL DE CLASSE'), 0, 2, 'C');
$pdf->SetFont('Arial', 'I', 6);
$pdf->Cell($w_cc, $h_cc_titre / 2 - 0.3, 'Class council decision', 0, 1, 'C');
$pdf->SetTextColor(0, 0, 0);
$pdf->SetLineWidth(0.2);

$y_cc2 = $y_cc + $h_cc_titre + 2;
// Valeur à écrire sur les pointillés : le nom de la classe suivante pour
// PROMU(E) EN (next_classe, résolu ci-dessus) ; la classe ACTUELLE pour
// REDOUBLE LA (le redoublant reste dans la même classe — next_classe n'est
// pas renseigné dans ce cas) ; le motif saisi (observation) pour EXCLU(E)
// POUR, s'il existe.
$cc_lignes = [
    ['PROMU(E) EN',        false, $dec_val === 'Admis',        $dec_val === 'Admis' ? $dec_next_classe : ''],
    ['REDOUBLE LA',        false, $dec_val === 'Redoublement', $dec_val === 'Redoublement' ? ($insc['classe'] ?? '') : ''],
    ["EXCLU(E) POUR",      false, $dec_val === 'Exclu',        $dec_val === 'Exclu' ? (string)$dec_observation : ''],
    ['AGE', true, false, ''],
    ['TRAVAIL INSUFFISANT', true, false, ''],
    ['CONDUITE DEPLORABLE', true, false, ''],
    ['NE PEUT TRIPLER', true, false, ''],
    ["TROP D'ABSENCES", true, false, ''],
    ['ABANDON', true, $dec_val === 'Abandon', ''],
];
foreach ($cc_lignes as $i => $cl) {
    [$lib, $indente, $checked, $valeur] = $cl;
    $y_row = $y_cc2 + $i * $h_dec;
    $x_chk = $ml + ($indente ? 8 : 2);
    checkbox_ltm($pdf, $x_chk, $y_row + $h_dec / 2 - 1, $checked, 2);
    $pdf->SetXY($x_chk + 3.5, $y_row);
    $pdf->SetFont('Arial', $indente ? '' : 'B', $indente ? 6.5 : 7);
    $suffixe = $indente ? '' : ' ' . ($valeur !== '' ? $valeur : '..........................');
    $pdf->Cell($w_dec - ($x_chk - $ml) - 3.5, $h_dec, u($lib . $suffixe), 0, 1, 'L');
}
$pdf->SetFont('Arial', '', 6.5);

// Cadre OBSERVATIONS — bordure haute seulement (demande explicite : plus de
// bordure gauche/droite/basse), le bandeau de titre au-dessus fait déjà
// office de délimitation visuelle.
$pdf->SetFont('Arial', '', 6);
$pdf->SetXY($x_obs, $y_dec2);
$pdf->MultiCell($w_obs, $h_obs_box, '', 'T', 'L');

// ── 7. Date et signature (à l'intérieur du cadre Observations) ────
$pdf->SetFont('Arial', '', 8.5);
$pdf->SetXY($x_obs, $y_dec2 + 6);
$pdf->Cell($w_obs, 5.5, u('Mbé, le ' . date('d-m-Y') . '.'), 0, 1, 'C');
$pdf->SetX($x_obs);
$pdf->SetFont('Arial', 'I', 7.5);
$pdf->Cell($w_obs, 4.5, 'On', 0, 1, 'C');
$pdf->Ln(2.5);
$pdf->SetX($x_obs);
$pdf->SetFont('Arial', 'B', 8.5);
$pdf->Cell($w_obs, 5.5, 'LE PROVISEUR,', 0, 1, 'C');
$pdf->SetX($x_obs);
$pdf->SetFont('Arial', 'I', 7.5);
$pdf->Cell($w_obs, 4.5, 'The Principal', 0, 1, 'C');

// Signature numérique (uniquement si demandée à l'impression — jamais
// automatique — et si l'admin en a configuré une dans les paramètres).
if (($_GET['signature'] ?? '0') === '1') {
    $sig_w = 22;
    $sx = $x_obs + ($w_obs - $sig_w) / 2;
    $sy = $pdf->GetY() + 0.5;
    pdf_signature_appliquer($pdf, 'bulletin_annuel', 'chef_etablissement', 0, 0, $pw, $ph, [
        'x_pct' => $sx / $pw * 100, 'y_pct' => $sy / $ph * 100, 'w_pct' => $sig_w / $pw * 100, 'h_pct' => null,
    ]);
}

// QR code de vérification d'authenticité — centré horizontalement sur la
// page, tout en bas, à 2-3mm au-dessus du copyright (demande explicite,
// déplacé hors du cadre OBSERVATIONS), avec la photo de l'élève incrustée
// au centre.
require_once __DIR__ . '../../pdf/verif_lib.php';
require_once __DIR__ . '../../pdf/qrcode.php';

$verif_url = bulletin_verif_url($id_eleve, 'annee', $id_annee, id_affichage_eleve($eleve));
$qr_tmp = tempnam(sys_get_temp_dir(), 'abzqr_') . '.png';
try {
    $qr_gen = new QRCode($verif_url, ['s' => 'qr-h']); // qr-h = correction d'erreur élevée (impression papier)
    $qr_img = $qr_gen->render_image();
    qr_incruster_photo($qr_img, $photo_path ?: '');
    imagepng($qr_img, $qr_tmp);
    imagedestroy($qr_img);

    $qr_size = 22;
    $qr_x    = ($pw - $qr_size) / 2;        // centré sur la largeur de la page
    $qr_y    = ($ph - 9) - 2.5 - $qr_size;   // 2.5mm au-dessus du copyright
    $pdf->Image($qr_tmp, $qr_x, $qr_y, $qr_size, $qr_size, 'PNG');
} finally {
    if (is_file($qr_tmp)) unlink($qr_tmp);
}

// Mention copyright en bas de page, centrée, à l'intérieur du cadre extérieur
// (demande explicite).
$pdf->SetFont('Arial', '', 6.5);
$pdf->SetXY($ml, $ph - 9);
$pdf->Cell($uw, 4, u('Copyright © SIGES ABZ   , E-mail: abdoulazizyahya@gmail.com'), 0, 1, 'C');

// ── Sortie ────────────────────────────────────────────────────────
$mode     = ($_GET['dl'] ?? '') === '1' ? 'D' : 'I';
$filename = 'bulletin_' . ($eleve['matricule'] ?? $id_eleve) . '_' . preg_replace('/\W+/', '_', $titre_bull) . '.pdf';
$pdf->Output($mode, $filename);
