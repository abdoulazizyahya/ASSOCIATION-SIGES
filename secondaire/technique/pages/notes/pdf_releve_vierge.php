<?php
/**
 * RELEVE DE NOTES — feuille VIERGE (Matière/Enseignant à compléter à la
 * main, colonnes EVAL 1 à EVAL 6 vides) avec la liste des élèves d'une
 * classe. Modèle fourni par l'utilisateur (PDF joint, portrait A4).
 * Distinct de secondaire/commun/pages/conseil_classe/pdf_releve.php (matrice remplie
 * automatiquement à partir des notes saisies, une colonne par matière) :
 * ce document ne lit AUCUNE note en base, c'est un formulaire à imprimer.
 * GET : classe (0 = toutes les classes), dl (0|1),
 *       vh+va = accès public via le QR de vérification (voir plus bas).
 */
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
require_once __DIR__ . '/../../../commun/pdf/verif_releve_vierge_lib.php';

// Accès public via le QR imprimé sur le relevé (jeton "vh" = hash de
// vérification déjà calculé pour CETTE classe et CETTE année précises, voir
// secondaire/commun/verif/document_classe.php) : la personne qui scanne n'a pas forcément de
// compte. En dehors de ce cas, connexion + contrôle de rôle normal exigés.
$vh_verif      = (string)($_GET['vh'] ?? '');
$va_verif      = (int)($_GET['va'] ?? 0);
$id_classe_pub = (int)($_GET['classe'] ?? 0);
$acces_public  = $vh_verif !== '' && $id_classe_pub > 0 && $va_verif > 0
    && hash_equals(releve_vierge_verif_hash($id_classe_pub, $va_verif), $vh_verif);

$etab = get_etablissement();

if ($acces_public) {
    $id_annee  = $va_verif;
    $val_annee = (string) db_val("SELECT libelle FROM annee_scolaire WHERE id=?", [$id_annee]);
    $classe_unique = db_one("SELECT * FROM classe WHERE id=?", [$id_classe_pub]);
    if (!$classe_unique) die('Classe introuvable.');
    $classes_a_generer = [$classe_unique];
    $id_classe_demande = $id_classe_pub;
} else {
    exiger_connexion();
    $role     = role_connecte();
    $is_admin = (in_array($role, ['ADMIN', 'PROVISEUR', 'FONDATEUR', 'CENSEUR'], true) || $role === 'MEMBRE_ASSOCIATION');
    $is_ens   = sec_vue_enseignant();   // ENSEIGNANT, ou SG qui a des cours (17/10/2026)
    $mat_ens  = $is_ens ? get_matricule_ens_connecte() : null;
    if (!$is_admin && !$is_ens) die('Acces non autorise.');

    $annee_act = get_annee_active();
    $id_annee  = (int)($annee_act['id'] ?? 0);
    $val_annee = $annee_act['libelle'] ?? '';

    $id_classe_demande = (int)($_GET['classe'] ?? 0);

    // ── Classes accessibles ───────────────────────────────────────────
    if ($is_ens && $mat_ens) {
        $classes = db_all(
            "SELECT DISTINCT c.* FROM classe c
             JOIN dispenser d ON d.IDClasses=c.id AND d.matricule_ens=? AND d.val_annee=?
             WHERE c.archivee=0 ORDER BY c.ordre, c.designation",
            [$mat_ens, $val_annee]
        );
    } else {
        $classes = $id_annee
            ? db_all("SELECT c.* FROM classe c
                      JOIN inscription i ON i.id_classe=c.id AND i.id_annee=?
                      WHERE c.archivee=0 GROUP BY c.id ORDER BY c.ordre, c.designation", [$id_annee])
            : [];
    }
    if (empty($classes)) die('Aucune classe accessible.');

    if ($id_classe_demande > 0) {
        $classes_a_generer = array_values(array_filter($classes, fn($c) => (int)$c['id'] === $id_classe_demande));
        if (empty($classes_a_generer)) die('Acces refuse a cette classe.');
    } else {
        $classes_a_generer = $classes; // toutes les classes accessibles
    }
}

$dl = ($_GET['dl'] ?? '0') === '1';

// Mode REMPLI (24/10/2026) : notes saisies d'UNE matière d'UNE classe, une
// colonne par évaluation (séquence) de l'année. Vierge : comme avant ; la
// matière, si elle est donnée, pré-remplit seulement l'en-tête.
$mode_rv  = ($_GET['mode'] ?? '') === 'rempli' ? 'rempli' : 'vierge';
$id_mat   = (int) ($_GET['mat'] ?? 0);
$matiere_rv = null; $enseignant_rv = null; $seqs_rv = []; $notes_rv = [];
if ($id_mat > 0 && !$acces_public && count($classes_a_generer) === 1) {
    $cl = (int) $classes_a_generer[0]['id'];
    $ok_mat = $is_admin
        ? db_val("SELECT 1 FROM discipline WHERE IDClasses=? AND id_mat=?", [$cl, $id_mat])
        : db_val("SELECT 1 FROM dispenser WHERE IDClasses=? AND id_mat=? AND val_annee=? AND matricule_ens=?", [$cl, $id_mat, $val_annee, (int) $mat_ens]);
    if (!$ok_mat) die('Acces refuse a cette matiere.');
    $matiere_rv = (string) db_val("SELECT libelle FROM matiere WHERE id=?", [$id_mat]);
    $enseignant_rv = (string) db_val("SELECT TRIM(CONCAT(e.nom_ens,' ',COALESCE(e.prenom_ens,''))) FROM dispenser d
                                      JOIN enseignant e ON e.matricule_ens=d.matricule_ens
                                      WHERE d.IDClasses=? AND d.id_mat=? AND d.val_annee=?", [$cl, $id_mat, $val_annee]) ?: null;
    if ($mode_rv === 'rempli') {
        $seqs_rv = db_all("SELECT s.id, s.libelle FROM sequence s JOIN trimestre t ON t.id=s.id_trim
                           WHERE t.id_annee=? ORDER BY t.ordre, s.ordre", [$id_annee]);
        foreach (db_all("SELECT n.id_eleve, n.id_seq, n.valeur FROM note n
                         JOIN inscription i ON i.id_eleve=n.id_eleve AND i.id_classe=? AND i.id_annee=?
                         WHERE n.id_matiere=? AND n.valeur IS NOT NULL", [$cl, $id_annee, $id_mat]) as $n) {
            $notes_rv[(int) $n['id_eleve']][(int) $n['id_seq']] = (float) $n['valeur'];
        }
    }
}
if ($mode_rv === 'rempli' && (!$matiere_rv || !$seqs_rv)) $mode_rv = 'vierge';   // rien à remplir

require_once __DIR__ . '/../../../commun/pdf/fpdf.php';
require_once __DIR__ . '/../../../commun/pdf/header_pdf.php';
require_once __DIR__ . '/../../../commun/pdf/pages_par_classe.php';   // pages numérotées par classe, pied réduit

$pdf = new FPDF_PagesParClasse('P', 'mm', 'A4');
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(false);
$pw = $pdf->GetPageWidth();
$ph = $pdf->GetPageHeight();
$ml = 10;
$uw = $pw - 2 * $ml;

$w_no = 10; $w_nom = 70; $nb_eval = $mode_rv === 'rempli' ? count($seqs_rv) : 6;
$w_eval = ($uw - $w_no - $w_nom) / $nb_eval;
$h_hdr = 8; $h_row = 6;
$titres_rv = array_column($seqs_rv, 'libelle');   // mode rempli : libellé des évaluations

function dessiner_entete_releve_vierge(FPDF $pdf, array $etab, array $classe, string $val_annee, float $ml, float $uw, float $pw, ?array $stats = null,
                                       ?string $matiere = null, ?string $enseignant = null): void {
    pdf_entete($pdf, $etab, $pw, $ml);

    $badge_w = 85; $badge_h = 15;
    $bx = $ml + ($uw - $badge_w) / 2;
    $by = $pdf->GetY();
    $pdf->SetDrawColor(41, 121, 255);
    $pdf->SetLineWidth(0.6);
    $pdf->SetFillColor(255, 255, 255);
    $pdf->RoundedRect($bx, $by, $badge_w, $badge_h, $badge_h / 2, 'DF');
    $pdf->SetXY($bx, $by + 2);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell($badge_w, 6, pdf_u('RELEVE DE NOTES'), 0, 0, 'C');
    $pdf->SetXY($bx, $by + 8);
    $pdf->SetFont('Arial', 'BI', 8);
    $pdf->SetTextColor(30, 90, 220);
    $pdf->Cell($badge_w, 5, pdf_u('GRADE REPORT'), 0, 0, 'C');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetLineWidth(0.2);
    $pdf->SetDrawColor(0, 0, 0);
    // Effectifs H / F / T (nouveaux, redoublants) à droite du bandeau.
    if ($stats) pdf_tableau_effectifs($pdf, $stats, $ml + $uw - 36, $by + ($badge_h - 11.6) / 2);
    $pdf->SetY($by + $badge_h + 2);

    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetX($ml);
    $pdf->Cell($uw / 2, 5, pdf_u('Annee scolaire: ' . $val_annee), 0, 0, 'L');
    $pdf->Cell($uw / 2, 5, pdf_u('Classe : ' . ($classe['designation'] ?? '')), 0, 1, 'R');
    $pdf->Ln(1.5);

    // Double filet horizontal (séparateur, comme le modèle fourni)
    $pdf->SetDrawColor(60, 60, 60);
    $y1 = $pdf->GetY();
    $pdf->Line($ml, $y1, $ml + $uw, $y1);
    $pdf->Line($ml, $y1 + 1, $ml + $uw, $y1 + 1);
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->Ln(3);

    // Matière / Enseignant : champs vierges (ligne pointillée à compléter à la main)
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetX($ml);
    $label1 = pdf_u('MATIERE : ');
    $pdf->Cell($pdf->GetStringWidth($label1) + 1, 5, $label1, 0, 0, 'L');
    if ($matiere) $pdf->Cell(0, 5, pdf_u($matiere), 0, 0, 'L');
    else pdf_ligne_pointillee($pdf, $pdf->GetX(), $pdf->GetY() + 4, $ml + $uw, $pdf->GetY() + 4);
    $pdf->Ln(6);
    $pdf->SetX($ml);
    $label2 = pdf_u('ENSEIGNANT : ');
    $pdf->Cell($pdf->GetStringWidth($label2) + 1, 5, $label2, 0, 0, 'L');
    if ($enseignant) $pdf->Cell(0, 5, pdf_u($enseignant), 0, 0, 'L');
    else pdf_ligne_pointillee($pdf, $pdf->GetX(), $pdf->GetY() + 4, $ml + $uw, $pdf->GetY() + 4);
    $pdf->Ln(7);
}

function dessiner_entete_tableau_rv(FPDF $pdf, float $ml, float $w_no, float $w_nom, float $w_eval, int $nb_eval, float $h_hdr, array $titres = []): void {
    $y = $pdf->GetY();
    $pdf->SetFillColor(65, 145, 225);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetDrawColor(0, 0, 0);
    $x = $ml;
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetXY($x, $y); $pdf->Cell($w_no, $h_hdr, pdf_u('N°'), 1, 0, 'C', true); $x += $w_no;
    $pdf->SetXY($x, $y); $pdf->Cell($w_nom, $h_hdr, pdf_u('NOM ET PRENOMS'), 1, 0, 'L', true); $x += $w_nom;
    for ($i = 1; $i <= $nb_eval; $i++) {
        $pdf->SetXY($x, $y);
        $pdf->Cell($w_eval, $h_hdr, isset($titres[$i - 1]) ? pdf_u($titres[$i - 1]) : 'EVAL ' . $i, 1, 0, 'C', true);
        $x += $w_eval;
    }
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetXY($ml, $y + $h_hdr);
}

foreach ($classes_a_generer as $classe) {
    $eleves = db_all(
        "SELECT e.id, e.nom, e.prenom, e.sexe, i.statut AS statut_insc FROM eleve e
         JOIN inscription i ON i.id_eleve=e.id AND i.id_classe=? AND i.id_annee=?
         WHERE e.statut='actif' ORDER BY e.nom, e.prenom",
        [$classe['id'], $id_annee]
    );

    $pdf->nouvelleClasse();
    $pdf->AddPage();
    pdf_filigrane($pdf, $etab, $pw, $ph);
    dessiner_entete_releve_vierge($pdf, $etab, $classe, $val_annee, $ml, $uw, $pw, pdf_effectifs_classe($eleves), $matiere_rv, $enseignant_rv);
    dessiner_entete_tableau_rv($pdf, $ml, $w_no, $w_nom, $w_eval, $nb_eval, $h_hdr, $titres_rv);

    $n = 1;
    foreach ($eleves as $el) {
        // Marge basse de 10 mm : place du pied de page réduit (pdf_pied_par_classe()).
        if ($pdf->GetY() + $h_row > $ph - 10) {
            pdf_pied_par_classe($pdf, $val_annee, $pw, $ph, $ml);
            $pdf->AddPage();
            pdf_filigrane($pdf, $etab, $pw, $ph);
            $pdf->SetY($ml);
            dessiner_entete_tableau_rv($pdf, $ml, $w_no, $w_nom, $w_eval, $nb_eval, $h_hdr, $titres_rv);
        }
        $y = $pdf->GetY();
        $x = $ml;
        $pdf->SetFont('Arial', '', 8);
        $pdf->SetXY($x, $y); $pdf->Cell($w_no, $h_row, (string)$n, 1, 0, 'C'); $x += $w_no;
        pdf_cellule_nom($pdf, $x, $y, $w_nom, $h_row, pdf_u(strtoupper($el['nom']) . ' ' . ($el['prenom'] ?? '')), pdf_est_redoublant($el)); $x += $w_nom;
        for ($i = 0; $i < $nb_eval; $i++) {
            $pdf->SetXY($x, $y);
            $note = $mode_rv === 'rempli' ? ($notes_rv[(int) $el['id']][(int) $seqs_rv[$i]['id']] ?? null) : null;
            $pdf->Cell($w_eval, $h_row, $note === null ? '' : rtrim(rtrim(number_format($note, 2, ',', ''), '0'), ','), 1, 0, 'C');
            $x += $w_eval;
        }
        $pdf->SetXY($ml, $y + $h_row);
        $n++;
    }
    if (empty($eleves)) {
        $pdf->SetX($ml);
        $pdf->Cell($uw, 8, pdf_u('Aucun eleve inscrit.'), 1, 1, 'C');
    }

    // Plus de QR de vérification sur ce formulaire vierge (22/10/2026). Le
    // lien public (vh/va) reste valable pour les relevés déjà imprimés.
    pdf_pied_par_classe($pdf, $val_annee, $pw, $ph, $ml);
}

$mode   = $dl ? 'D' : 'I';
$suffix = $id_classe_demande ? preg_replace('/\W+/', '_', $classes_a_generer[0]['designation'] ?? 'classe') : 'toutes_classes';
$pdf->Output($mode, 'releve_' . $mode_rv . '_' . $suffix . ($matiere_rv ? '_' . preg_replace('/\W+/', '_', $matiere_rv) : '') . '.pdf');
