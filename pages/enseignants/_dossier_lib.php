<?php
/**
 * Bibliothèque partagée de rendu du dossier administratif de l'enseignant.
 * À inclure APRÈS : config.php, connexion.php, fonctions.php, pdf/fpdf.php.
 * Fournit :
 *   - la classe FPDF_Dossier (en-tête bilingue + pied de page paginé)
 *   - les helpers de mise en page d_section / d_row / d_full
 *   - dossier_render_one() : rend un dossier complet (une ou plusieurs pages)
 * Utilisée par enseignants/pdf_dossier.php (unitaire) et pdf_dossier_lot.php (lot).
 */

if (!defined('DOSSIER_ML')) define('DOSSIER_ML', 15);   // marge latérale (mm)

/*
if (!function_exists('ud')) {
    function ud(string $s): string { return utf8_decode($s); }
}
*/

if (!function_exists('ud')) {
    function ud(string $s): string {
        // Conversion UTF-8 vers ISO-8859-1 (équivalent à utf8_decode)
        return mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8');
    }
}


if (!class_exists('FPDF_Dossier')) {
class FPDF_Dossier extends FPDF {
    public array  $etab       = [];
    public string $nomComplet = '';
    public string $mat        = '';
    public bool   $newDossier = true;   // true = début d'un nouveau dossier → en-tête complet

    function Header() {
        $ml = DOSSIER_ML; $pw = $this->GetPageWidth(); $uw = $pw - 2*$ml; $col3 = $uw/3; $y0 = 10;
        $etab = $this->etab;

        pdf_filigrane($this, $etab, $pw, $this->GetPageHeight());

        if ($this->newDossier) {
            // ── En-tête complet (bilingue) ───────────────────────────
            $this->SetXY($ml, $y0); $this->SetFont('Arial','',7.2); $this->SetTextColor(30,79,216);
            $this->MultiCell($col3, 3.8,
                "REPUBLIQUE DU CAMEROUN\nPaix - Travail - Patrie\n".
                ($etab['region_fr']         ?? "REGION DE L'ADAMAOUA")."\n".
                ($etab['departement_fr']    ?? 'DEPARTEMENT DE LA VINA')."\n".
                ($etab['arrondissement_fr'] ?? 'ARRONDISSEMENT DE MBE'), 0, 'C');
            $this->SetX($ml); $this->SetFont('Arial','B',8);
            $this->MultiCell($col3, 4, ud(strtoupper($etab['nom_fr'] ?? 'LYCEE TECHNIQUE DE MBE')), 0, 'C');
            $yl = $this->GetY();

            $logo = !empty($etab['logo']) ? __DIR__.'/../../assets/uploads/'.$etab['logo'] : '';
            $lx = $ml + $col3 + ($col3 - 22)/2;
            if ($logo && is_file($logo)) { $this->Image($logo, $lx, $y0, 22); }
            else { $this->SetFont('Arial','B',9); $this->SetXY($lx, $y0+4); $this->Cell(22,10,ud($etab['sigle'] ?? 'LTM'),1,0,'C'); }

            $xr = $ml + $col3*2;
            $this->SetXY($xr, $y0); $this->SetFont('Arial','',7.2); $this->SetTextColor(30,79,216);
            $this->MultiCell($col3, 3.8,
                "REPUBLIC OF CAMEROON\nPeace - Work - Fatherland\n".
                ($etab['region_en']      ?? 'ADAMAWA REGION')."\n".
                ($etab['division_en']    ?? 'VINA DIVISION')."\n".
                ($etab['subdivision_en'] ?? 'MBE SUBDIVISION'), 0, 'C');
            $this->SetX($xr); $this->SetFont('Arial','B',8);
            $this->MultiCell($col3, 4, ud(strtoupper($etab['nom_en'] ?? 'GTHS OF MBE')), 0, 'C');
            $yr = $this->GetY();

            $this->SetY(max($yl, $yr) + 1);
            $this->SetDrawColor(30,79,216); $this->SetLineWidth(0.4);
            $this->Line($ml, $this->GetY(), $pw-$ml, $this->GetY());
            $this->SetLineWidth(0.2); $this->SetDrawColor(0);
            $this->Ln(3);

            $this->SetX($ml); $this->SetFont('Arial','B',14); $this->SetTextColor(20,40,120);
            $this->Cell($uw, 8, ud("DOSSIER ADMINISTRATIF DE L'ENSEIGNANT"), 0, 1, 'C');
            $this->SetX($ml); $this->SetFont('Arial','I',9); $this->SetTextColor(90,90,90);
            $this->Cell($uw, 5, ud("Teacher's Administrative Record"), 0, 1, 'C');
            $this->Ln(1);
            $this->SetX($ml); $this->SetFont('Arial','B',10); $this->SetTextColor(0);
            $this->Cell($uw, 6, ud($this->nomComplet.'    -    Matricule/ID : '.$this->mat), 0, 1, 'C');
            $this->SetTextColor(0);
            $this->Ln(2);

            $this->newDossier = false;   // les pages suivantes du même dossier auront l'en-tête allégé
        } else {
            // ── En-tête allégé (suite d'un même dossier) ─────────────
            $this->SetXY($ml, $y0); $this->SetFont('Arial','B',9); $this->SetTextColor(20,40,120);
            $this->Cell($uw*0.7, 6, ud("Dossier administratif - ".$this->nomComplet), 0, 0, 'L');
            $this->SetFont('Arial','',8); $this->SetTextColor(90,90,90);
            $this->Cell($uw*0.3, 6, ud('Matricule/ID : '.$this->mat), 0, 1, 'R');
            $this->SetDrawColor(30,79,216); $this->Line($ml, 17, $pw-$ml, 17); $this->SetDrawColor(0);
            $this->SetY(20); $this->SetTextColor(0);
        }
    }

    function Footer() {
        $this->SetY(-12);
        $this->SetDrawColor(200); $this->Line(15, $this->GetY(), $this->GetPageWidth()-15, $this->GetY());
        $this->SetDrawColor(0);
        $this->SetFont('Arial','I',7); $this->SetTextColor(120);
        $this->Cell(0, 6, ud('Document généré le/Generated on '.date('d/m/Y à H:i').'  -  '.($this->etab['nom_fr'] ?? 'Lycée Technique de Mbé')), 0, 0, 'L');
        $this->Cell(0, 6, ud('Page '.$this->PageNo().'/{nb}'), 0, 0, 'R');
        $this->SetTextColor(0);
    }
}
}

// ── Helpers de mise en page ──────────────────────────────────────────
if (!function_exists('d_section')) {
    function d_section(FPDF $pdf, string $fr, string $en): void {
        $ml = DOSSIER_ML; $uw = $pdf->GetPageWidth() - 2*$ml;
        $pdf->Ln(2);
        $pdf->SetX($ml); $pdf->SetFont('Arial','B',9.5);
        $pdf->SetFillColor(26,60,107); $pdf->SetTextColor(255);
        $pdf->Cell($uw, 6.5, ud('  '.$fr.'   /   '.$en), 0, 1, 'L', true);
        $pdf->SetTextColor(0);
    }
}
if (!function_exists('d_row')) {
    function d_row(FPDF $pdf, string $l1, string $v1, ?string $l2 = null, ?string $v2 = null): void {
        // lw élargie (40→48mm) pour laisser la place aux libellés bilingues
        // FR/EN, vw réduite d'autant pour que lw+vw (donc la largeur totale
        // de la ligne, utilisée 2x) reste égale à $uw comme avant.
        $ml = DOSSIER_ML; $lw = 48; $vw = 42;
        static $alt = false; $alt = !$alt;
        $fill = $alt ? [244,247,252] : [255,255,255];
        $pdf->SetX($ml);
        $pdf->SetFont('Arial','B',8.3); $pdf->SetTextColor(90,90,90); $pdf->SetFillColor(230,236,245);
        $pdf->Cell($lw, 6.4, ud($l1), 1, 0, 'L', true);
        $pdf->SetFont('Arial','',9); $pdf->SetTextColor(20,20,40); $pdf->SetFillColor($fill[0],$fill[1],$fill[2]);
        $pdf->Cell($vw, 6.4, ud($v1), 1, 0, 'L', true);
        if ($l2 !== null) {
            $pdf->SetFont('Arial','B',8.3); $pdf->SetTextColor(90,90,90); $pdf->SetFillColor(230,236,245);
            $pdf->Cell($lw, 6.4, ud($l2), 1, 0, 'L', true);
            $pdf->SetFont('Arial','',9); $pdf->SetTextColor(20,20,40); $pdf->SetFillColor($fill[0],$fill[1],$fill[2]);
            $pdf->Cell($vw, 6.4, ud($v2), 1, 1, 'L', true);
        } else {
            $pdf->SetFillColor($fill[0],$fill[1],$fill[2]);
            $pdf->Cell($lw+$vw, 6.4, '', 1, 1, 'L', true);
        }
        $pdf->SetTextColor(0);
    }
}
if (!function_exists('d_full')) {
    function d_full(FPDF $pdf, string $l, string $v): void {
        $ml = DOSSIER_ML; $lw = 50; $vw = 130;
        $pdf->SetX($ml);
        $pdf->SetFont('Arial','B',8.3); $pdf->SetTextColor(90,90,90); $pdf->SetFillColor(230,236,245);
        $pdf->Cell($lw, 6.4, ud($l), 1, 0, 'L', true);
        $pdf->SetFont('Arial','',9); $pdf->SetTextColor(20,20,40); $pdf->SetFillColor(250,250,252);
        $pdf->Cell($vw, 6.4, ud($v), 1, 1, 'L', true);
        $pdf->SetTextColor(0);
    }
}

if (!function_exists('dossier_fmt_date')) {
    function dossier_fmt_date(?string $d): string {
        return ($d && $d !== '0000-00-00') ? date('d/m/Y', strtotime($d)) : '—';
    }
}
if (!function_exists('dossier_val')) {
    function dossier_val($x): string {
        return ($x !== null && trim((string)$x) !== '') ? (string)$x : '—';
    }
}

/**
 * Rend un dossier complet pour un enseignant (crée sa/ses page(s)).
 * @param FPDF   $pdf        instance FPDF_Dossier
 * @param array  $e          ligne enseignant (SELECT *)
 * @param array  $etab       infos établissement
 * @param string $val_annee  libellé de l'année active (pour le service pédagogique)
 */
if (!function_exists('dossier_render_one')) {
function dossier_render_one(FPDF $pdf, array $e, array $etab, string $val_annee): void {
    $ml = DOSSIER_ML; $uw = $pdf->GetPageWidth() - 2*$ml;

    // Robustesse : toutes les colonnes attendues présentes (dégrade en « — »).
    foreach (['civilite_ens','nom_ens','prenom_ens','sexe_ens','tel_ens','mail_ens',
              'id_grade','id_fonction','date_naiss','lieu_naiss','region_origine',
              'departement_origine','arrondissement_origine','tribu','ethnie',
              'situation_matrimoniale','poste_anterieur','lieu_anterieur',
              'num_acte_recrutement','date_acte_recrutement','date_entree_fp',
              'type_affectation','num_note_affectation','date_note_affectation',
              'date_prise_service','qualite','diplome','specialite',
              'date_1ere_admin','date_1ere_etab','matiere_enseignee'] as $__c) {
        if (!array_key_exists($__c, $e)) $e[$__c] = null;
    }

    $mat = (string)($e['matricule_ens'] ?? '');
    $affectations = db_all(
        "SELECT DISTINCT c.designation AS classe, m.libelle AS matiere
         FROM dispenser d
         JOIN classe c  ON c.id = d.IDClasses
         JOIN matiere m ON m.id = d.id_mat
         WHERE d.matricule_ens=? AND d.val_annee=?
         ORDER BY c.designation, m.libelle",
        [$mat, $val_annee]
    );
    $classes_pp = db_all(
        "SELECT c.designation FROM enseignat_principal ep
         JOIN classe c ON c.id=ep.IDClasses
         WHERE ep.matricule_ens=? AND ep.val_annee=?",
        [$mat, $val_annee]
    );
    $compte = db_one("SELECT login, role, actif FROM utilisateur WHERE matricule_ens=? LIMIT 1", [$mat]);

    $nom_complet = trim(($e['civilite_ens'] ?? '').' '.strtoupper($e['nom_ens'] ?? '').' '.($e['prenom_ens'] ?? ''));
    $pdf->nomComplet = $nom_complet;
    $pdf->mat        = $mat;
    $pdf->newDossier = true;   // force l'en-tête complet pour ce dossier
    $pdf->AddPage();

    // 1. État civil
    d_section($pdf, 'ETAT CIVIL', 'Personal details');
    d_row($pdf, 'Matricule/ID', dossier_val($e['matricule_ens']), 'Civilité/Title', dossier_val($e['civilite_ens']));
    d_row($pdf, 'Nom/Surname', dossier_val(strtoupper($e['nom_ens'] ?? '')), 'Prénom/First name', dossier_val($e['prenom_ens']));
    d_row($pdf, 'Sexe/Gender', dossier_val($e['sexe_ens']), 'Sit.matrim./Marital', dossier_val($e['situation_matrimoniale']));
    d_row($pdf, 'Date naiss./D.O.B.', dossier_fmt_date($e['date_naiss']), 'Lieu naiss./Birthplace', dossier_val($e['lieu_naiss']));

    // 2. Origine
    d_section($pdf, 'ORIGINE', 'Origin');
    d_row($pdf, "Région/Region", dossier_val($e['region_origine']), 'Département', dossier_val($e['departement_origine']));
    d_row($pdf, 'Arrondt./Subdiv.', dossier_val($e['arrondissement_origine']), 'Tribu/Tribe', dossier_val($e['tribu']));
    d_row($pdf, 'Ethnie/Ethnic group', dossier_val($e['ethnie']), null, null);

    // 3. Recrutement et carrière
    d_section($pdf, 'RECRUTEMENT ET CARRIERE', 'Recruitment & career');
    d_row($pdf, 'Grade', dossier_val($e['id_grade']), 'Fonction/Function', dossier_val($e['id_fonction']));
    d_row($pdf, 'N° acte/Act No.', dossier_val($e['num_acte_recrutement']), 'Date acte/Act date', dossier_fmt_date($e['date_acte_recrutement']));
    d_row($pdf, 'Entrée FP/Civil svc entry', dossier_fmt_date($e['date_entree_fp']), '1ère p.s.(Admin.)', dossier_fmt_date($e['date_1ere_admin']));
    d_full($pdf, 'Poste antér./Previous post', dossier_val($e['poste_anterieur']).($e['lieu_anterieur'] ? '  ('.$e['lieu_anterieur'].')' : ''));

    // 4. Affectation
    d_section($pdf, "AFFECTATION DANS L'ETABLISSEMENT", 'Posting');
    d_row($pdf, "Type affect./Posting type", dossier_val($e['type_affectation']), 'N° note/décision', dossier_val($e['num_note_affectation']));
    d_row($pdf, 'Date note/Note date', dossier_fmt_date($e['date_note_affectation']), 'Prise de service/Entry date', dossier_fmt_date($e['date_prise_service']));
    d_row($pdf, '1ère p.s.(Étab.)', dossier_fmt_date($e['date_1ere_etab']), 'Qualité/Capacity', dossier_val($e['qualite']));

    // 5. Qualifications
    d_section($pdf, 'QUALIFICATIONS ET ENSEIGNEMENT', 'Qualifications & teaching');
    d_row($pdf, 'Diplôme/Degree', dossier_val($e['diplome']), 'Spécialité/Field', dossier_val($e['specialite']));
    d_full($pdf, 'Matière ens./Subject taught', dossier_val($e['matiere_enseignee']));

    // 6. Contacts et compte
    d_section($pdf, 'CONTACTS ET COMPTE', 'Contacts & account');
    d_row($pdf, 'Téléphone/Phone', dossier_val($e['tel_ens']), 'E-mail', dossier_val($e['mail_ens']));
    if ($compte) {
        d_row($pdf, 'Identifiant/Username', dossier_val($compte['login']), 'Rôle/Role', dossier_val($compte['role']));
    } else {
        d_full($pdf, 'Compte util./User account', 'Aucun compte de connexion associé / No login account');
    }

    // 7. Service pédagogique
    d_section($pdf, "SERVICE PEDAGOGIQUE - ANNEE ".($val_annee ?: '—'), 'Teaching load');
    if (!empty($classes_pp)) {
        d_full($pdf, 'Prof. principal/Class teacher', implode(', ', array_column($classes_pp, 'designation')));
    }
    if (empty($affectations)) {
        $pdf->SetX($ml); $pdf->SetFont('Arial','I',9); $pdf->SetTextColor(120);
        $pdf->Cell($uw, 6.4, ud('Aucune affectation enregistrée pour cette année. / No assignment recorded for this year.'), 1, 1, 'L');
        $pdf->SetTextColor(0);
    } else {
        $pdf->SetX($ml); $pdf->SetFont('Arial','B',8.5); $pdf->SetFillColor(230,236,245); $pdf->SetTextColor(40,40,40);
        $pdf->Cell($uw*0.45, 6.2, ud('Classe/Class'), 1, 0, 'L', true);
        $pdf->Cell($uw*0.55, 6.2, ud('Matière dispensée/Subject taught'), 1, 1, 'L', true);
        $pdf->SetFont('Arial','',9); $pdf->SetTextColor(20,20,40);
        $altr = false;
        foreach ($affectations as $a) {
            $altr = !$altr; $altr ? $pdf->SetFillColor(244,247,252) : $pdf->SetFillColor(255,255,255);
            $pdf->SetX($ml);
            $pdf->Cell($uw*0.45, 6, ud($a['classe']), 1, 0, 'L', true);
            $pdf->Cell($uw*0.55, 6, ud($a['matiere']), 1, 1, 'L', true);
        }
        $pdf->SetTextColor(0);
    }

    // Bloc signature
    $pdf->Ln(8);
    $pdf->SetX($ml + $uw*0.58); $pdf->SetFont('Arial','',9);
    $pdf->Cell($uw*0.42, 5, ud('Mbé, le/on '.date('d/m/Y')), 0, 1, 'C');
    $pdf->SetX($ml + $uw*0.58); $pdf->SetFont('Arial','B',9);
    $pdf->Cell($uw*0.42, 5, ud('Le Proviseur / The Principal'), 0, 1, 'C');
    // Signature numérique (uniquement si demandée à l'impression — jamais
    // automatique — et si l'admin en a configuré une dans les paramètres).
    if (($_GET['signature'] ?? '0') === '1') {
        $sig_w = 24;
        $pw = $pdf->GetPageWidth(); $ph = $pdf->GetPageHeight();
        $sx = $ml + $uw*0.58 + ($uw*0.42 - $sig_w) / 2;
        $sy = $pdf->GetY() + 1;
        pdf_signature_appliquer($pdf, 'dossier_enseignant', 'chef_etablissement', 0, 0, $pw, $ph, [
            'x_pct' => $sx / $pw * 100, 'y_pct' => $sy / $ph * 100, 'w_pct' => $sig_w / $pw * 100, 'h_pct' => null,
        ]);
    }
    $pdf->Ln(16);
    $pdf->SetX($ml + $uw*0.58); $pdf->Cell($uw*0.42, 0, '', 'T', 1, 'C');
}
}
