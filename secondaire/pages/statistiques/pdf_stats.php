<?php
/**
 * Export PDF des statistiques
 * GET : onglet (eleves|disciplines|effectifs|enseignants), vue, seq, trim, classe, dl
 */
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_connexion();

$role     = role_connecte();
$is_admin = in_array($role, ['ADMIN','PROVISEUR', 'FONDATEUR','CENSEUR']) || $role === 'MEMBRE_ASSOCIATION';
$is_ens   = ($role === 'ENSEIGNANT');
$mat_ens  = $is_ens ? get_matricule_ens_connecte() : null;
if (!$is_admin && !$is_ens) die('Accès refusé.');

$annee_act = get_annee_active();
$id_annee  = (int)($annee_act['id'] ?? 0);
$val_annee = $annee_act['libelle'] ?? '';
$etab      = get_etablissement();

// Séquence active de l'année — toujours celle EN COURS, jamais un choix
// parmi les séquences passées (même principe que le trimestre ci-dessous).
$seq_active = db_one(
    "SELECT s.*, t.id AS id_trim, t.libelle AS trim_lib
     FROM sequence s JOIN trimestre t ON t.id=s.id_trim
     WHERE s.active=1 AND t.id_annee=? LIMIT 1",
    [$id_annee]
);

$onglet    = $_GET['onglet'] ?? 'eleves';
$vue       = $_GET['vue']    ?? 'seq';
$id_seq    = (int)($seq_active['id'] ?? 0);
$id_trim   = (int)($seq_active['id_trim'] ?? 0);
$id_classe = (int)($_GET['classe'] ?? 0);
$dl        = ($_GET['dl'] ?? '0') === '1';

// Trimestre actif (compétences/APC) — remplace la séquence active comme
// proxy de "la période en cours" pour tout calcul de moyenne (les
// compétences n'ont pas de sous-division par séquence : "Séquence" et
// "Trimestre" pointent désormais tous deux vers le même trimestre actif).
// Voir prompt_continuite, mise à jour du 07/08/2026, Phase 6.
$trim_comp_actif = get_trimestre_actif();
$id_trim_comp     = (int)($trim_comp_actif['id'] ?? 0);
if ($vue !== 'annee' && !$id_trim_comp) die('Aucun trimestre actif.');

// Label période
if ($vue === 'annee') {
    $label_periode = 'ANNUELLE — '.$val_annee;
} else {
    $label_periode = $trim_comp_actif['libelle'] ?? '';
}

// Classes disponibles
if ($is_admin) {
    $classes = db_all("SELECT c.* FROM classe c JOIN inscription i ON i.id_classe=c.id AND i.id_annee=? WHERE c.archivee=0 GROUP BY c.id ORDER BY c.ordre,c.designation", [$id_annee]);
} else {
    $classes = db_all("SELECT DISTINCT c.* FROM classe c JOIN dispenser d ON d.IDClasses=c.id AND d.matricule_ens=? AND d.val_annee=? WHERE c.archivee=0 ORDER BY c.ordre,c.designation", [$mat_ens, $val_annee]);
}
if ($id_classe) $classes = array_filter($classes, fn($c) => (int)$c['id'] === $id_classe);

require_once __DIR__ . '/../../pdf/fpdf.php';
require_once __DIR__ . '/../../pdf/header_pdf.php';

function uc(string $s): string {
	return mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
}

$fmt = fn(?float $v): string => $v === null ? uc('—') : rtrim(rtrim(number_format($v,2,'.',''),'0'),'.');

// Calcul stats classe (compétences/APC — voir prompt_continuite du
// 07/08/2026 ; $vue==='annee' moyenne les moyennes trimestrielles).
function calc_stats_pdf(int $id_classe, int $id_annee, string $vue, int $id_trim): array {
    $vide = ['nb'=>0,'moy'=>null,'taux'=>0,'premier'=>null,'dernier'=>null,'admis'=>0,'filles'=>0,'garcons'=>0];
    $eleves = db_all("SELECT e.id, e.sexe FROM eleve e JOIN inscription i ON i.id_eleve=e.id AND i.id_classe=? AND i.id_annee=? WHERE e.statut='actif'",[$id_classe,$id_annee]);
    if (empty($eleves)) return $vide;

    // Règles 1/2/3/4 appliquées via le moteur commun (fonctions.php).
    $moys = ($vue === 'annee' || $id_trim)
        ? calc_moys_classe_periode_comp($id_classe, $id_annee, $vue, $id_trim, array_column($eleves, 'id'))
        : [];

    $filles  = count(array_filter($eleves, fn($e) => $e['sexe'] === 'F'));
    $garcons = count($eleves) - $filles;
    $nb=count($eleves); $admis=count(array_filter($moys,fn($m)=>$m>=10));
    // Règle 2 : taux de réussite sur les classés (count($moys)), pas l'effectif total.
    return ['nb'=>$nb,'moy'=>!empty($moys)?array_sum($moys)/count($moys):null,'taux'=>!empty($moys)?round($admis/count($moys)*100,1):0,'premier'=>!empty($moys)?max($moys):null,'dernier'=>!empty($moys)?min($moys):null,'admis'=>$admis,'filles'=>$filles,'garcons'=>$garcons];
}

$pdf = new FPDF('L', 'mm', 'A4'); // Paysage pour les stats
$pdf->SetMargins(10, 8, 10);
$pdf->SetAutoPageBreak(true, 12);
$pdf->AddPage();

$pw = $pdf->GetPageWidth();
$ml = 10; $mr = 10; $uw = $pw - $ml - $mr;

pdf_filigrane($pdf, $etab, $pw, $pdf->GetPageHeight());

// En-tête établissement
pdf_entete($pdf, $etab, $pw, 10);

// Titre du rapport
$titres = [
    'eleves'       => 'STATISTIQUES PAR CLASSE',
    'disciplines'  => 'STATISTIQUES PAR MATIÈRE',
    'effectifs'    => 'EFFECTIFS PAR CLASSE / SECTION',
    'enseignants'  => 'TABLEAU DE BORD ENSEIGNANTS',
    'section'      => 'BILAN PAR SECTION',
    'niveau'       => 'BILAN PAR NIVEAU',
    'matiere'      => 'BILAN PAR MATIÈRE (ÉCOLE)',
];
$titres_en = [
    'eleves'       => 'STATISTICS BY CLASS',
    'disciplines'  => 'STATISTICS BY SUBJECT',
    'effectifs'    => 'ENROLLMENT BY CLASS / STREAM',
    'enseignants'  => 'TEACHERS DASHBOARD',
    'section'      => 'RESULTS BY STREAM',
    'niveau'       => 'RESULTS BY LEVEL',
    'matiere'      => 'RESULTS BY SUBJECT (SCHOOL-WIDE)',
];
pdf_bandeau($pdf, $titres[$onglet] ?? 'STATISTIQUES', ($titres_en[$onglet] ?? 'STATISTICS') . ' — ' . $val_annee, $pw, 10);

// Sous-titre période
$pdf->SetFont('Arial','I',9);
$pdf->SetX($ml);
$pdf->Cell($uw, 5, uc('Période/Period : '.$label_periode.' — Année scolaire/Academic year : '.$val_annee), 0, 1, 'C');
$pdf->Ln(2);

$rh = 6; // row height
$bilan_cols_pdf = ['classes', 'moy_lt10', 'moy_ge10', 'felicit', 'encourag', 'tab', 'avert_trav', 'blame_trav'];

// Table M/F/T "bilan classe" (Classés/Moy<10/Moy>=10/Félicit./Encour./T.H/
// Avert.T/Blâme T) + ligne SOUS-TOTAL — même contenu que
// secondaire/pages/statistiques/index.php::render_tableau_bilan_genre(), portée en
// FPDF pour les onglets Section/Niveau (utilisée deux fois, d'où la
// fonction plutôt qu'une duplication du dessin de tableau).
function pdf_tableau_bilan(FPDF $pdf, float $ml, float $uw, float $rh, string $titre, array $lignes, array $bilan_cols): void {
    $labels = ['classes' => 'Classés', 'moy_lt10' => 'Moy<10', 'moy_ge10' => 'Moy>=10', 'felicit' => 'Félicit.', 'encourag' => 'Encour.', 'tab' => 'T.H', 'avert_trav' => 'Avert.T', 'blame_trav' => 'Blâme T.'];
    $w_lbl = 34;
    $w_num = ($uw - $w_lbl) / (count($bilan_cols) * 3);

    $pdf->SetFont('Arial', 'B', 8.5); $pdf->SetFillColor(220, 230, 245); $pdf->SetX($ml);
    $pdf->Cell($uw, 6, uc(strtoupper($titre)), 1, 1, 'L', true);

    $pdf->SetFont('Arial', 'B', 6); $pdf->SetFillColor(26, 60, 107); $pdf->SetTextColor(255, 255, 255);
    $pdf->SetX($ml);
    $pdf->Cell($w_lbl, 8, uc('Classe'), 1, 0, 'C', true);
    foreach ($bilan_cols as $k) $pdf->Cell($w_num * 3, 4, uc($labels[$k]), 1, 0, 'C', true);
    $pdf->Ln();
    $pdf->SetX($ml + $w_lbl);
    foreach ($bilan_cols as $k) { $pdf->Cell($w_num, 4, 'M', 1, 0, 'C', true); $pdf->Cell($w_num, 4, 'F', 1, 0, 'C', true); $pdf->Cell($w_num, 4, 'T', 1, 0, 'C', true); }
    $pdf->Ln();
    $pdf->SetTextColor(0, 0, 0); $pdf->SetFont('Arial', '', 6.5);

    $zero = ['M' => 0, 'F' => 0, 'T' => 0];
    $sous_total = array_fill_keys($bilan_cols, $zero);
    foreach ($lignes as $i => $l) {
        $fill = ($i % 2 === 0);
        $pdf->SetFillColor(245, 248, 255);
        $pdf->SetX($ml);
        $pdf->Cell($w_lbl, $rh - 1, uc($l['classe']), 1, 0, 'L', $fill);
        foreach ($bilan_cols as $k) {
            $b = $l['bilan'][$k];
            $pdf->Cell($w_num, $rh - 1, (string)$b['M'], 1, 0, 'C', $fill);
            $pdf->Cell($w_num, $rh - 1, (string)$b['F'], 1, 0, 'C', $fill);
            $pdf->Cell($w_num, $rh - 1, (string)$b['T'], 1, 0, 'C', $fill);
            foreach (['M', 'F', 'T'] as $g) $sous_total[$k][$g] += $b[$g];
        }
        $pdf->Ln();
    }
    $pdf->SetFont('Arial', 'B', 6.5); $pdf->SetFillColor(200, 214, 235); $pdf->SetX($ml);
    $pdf->Cell($w_lbl, $rh, uc('SOUS-TOTAL'), 1, 0, 'L', true);
    foreach ($bilan_cols as $k) {
        $pdf->Cell($w_num, $rh, (string)$sous_total[$k]['M'], 1, 0, 'C', true);
        $pdf->Cell($w_num, $rh, (string)$sous_total[$k]['F'], 1, 0, 'C', true);
        $pdf->Cell($w_num, $rh, (string)$sous_total[$k]['T'], 1, 0, 'C', true);
    }
    $pdf->Ln($rh + 3);
}

// ══════════════════════════════════════════════════════════════════
if ($onglet === 'eleves') {
    // En-tête tableau
    $pdf->SetFont('Arial','B',8);
    $pdf->SetFillColor(26,60,107); $pdf->SetTextColor(255,255,255);
    $pdf->SetX($ml);
    $cols = [[uc('Classe/Class'),50],[uc('Effectif/Total'),22],[uc('Filles/Girls'),20],[uc('Garçons/Boys'),22],[uc('Moyenne/Average'),26],[uc('Premier/1st'),20],[uc('Dernier/Last'),20],[uc('Admis/Passed'),22],[uc('Taux %/Rate %'),22],[uc('Mention/Remark'),44]];
    $total_w = array_sum(array_column($cols,1));
    $scale   = $uw / $total_w;
    foreach ($cols as [$lbl,$w]) $pdf->Cell($w*$scale,$rh+1,$lbl,1,0,'C',true);
    $pdf->Ln();
    $pdf->SetTextColor(0,0,0); $pdf->SetFont('Arial','',8);

    $g_nb=0; $g_admis=0; $g_moys=[];
    foreach ($classes as $c) {
        $st = calc_stats_pdf((int)$c['id'], $id_annee, $vue, $id_trim_comp);
        $g_nb += $st['nb']; $g_admis += $st['admis'];
        if ($st['moy']!==null) $g_moys[]=$st['moy'];
        $mention = $st['moy']!==null ? ($st['moy']>=16?'Excellent':($st['moy']>=14?'Très bien/Very good':($st['moy']>=12?'Bien/Good':($st['moy']>=10?'Assez bien/Fairly good':'Insuffisant/Poor')))) : '—';
        $fill = ($st['moy']!==null && $st['moy']<10);
        $pdf->SetFillColor(255,235,235);
        $pdf->SetX($ml);
        $pdf->Cell($cols[0][1]*$scale,$rh,uc($c['designation']),1,0,'L',$fill);
        $pdf->Cell($cols[1][1]*$scale,$rh,(string)$st['nb'],1,0,'C',$fill);
        $pdf->Cell($cols[2][1]*$scale,$rh,(string)$st['filles'],1,0,'C',$fill);
        $pdf->Cell($cols[3][1]*$scale,$rh,(string)$st['garcons'],1,0,'C',$fill);
        $st['moy']!==null&&$st['moy']>=10?$pdf->SetTextColor(0,100,0):$pdf->SetTextColor(180,0,0);
        $pdf->Cell($cols[4][1]*$scale,$rh,$fmt($st['moy']),1,0,'C',$fill);
        $pdf->SetTextColor(0,0,0);
        $pdf->Cell($cols[5][1]*$scale,$rh,$fmt($st['premier']??null),1,0,'C',$fill);
        $pdf->Cell($cols[6][1]*$scale,$rh,$fmt($st['dernier']??null),1,0,'C',$fill);
        $pdf->Cell($cols[7][1]*$scale,$rh,$st['admis'].'/'.$st['nb'],1,0,'C',$fill);
        $pdf->Cell($cols[8][1]*$scale,$rh,$st['taux'].'%',1,0,'C',$fill);
        $pdf->Cell($cols[9][1]*$scale,$rh,uc($mention),1,1,'C',$fill);
    }
    // Total
    $g_moy_val = !empty($g_moys)?array_sum($g_moys)/count($g_moys):null;
    $g_taux    = $g_nb>0?round($g_admis/$g_nb*100,1):0;
    $pdf->SetFont('Arial','B',8); $pdf->SetFillColor(220,230,245); $pdf->SetX($ml);
    $pdf->Cell($cols[0][1]*$scale,$rh,uc('TOTAL GÉNÉRAL/GRAND TOTAL'),1,0,'L',true);
    $pdf->Cell($cols[1][1]*$scale,$rh,(string)$g_nb,1,0,'C',true);
    $pdf->Cell(($cols[2][1]+$cols[3][1])*$scale,$rh,'',1,0,'C',true);
    $pdf->SetTextColor(0,0,180);
    $pdf->Cell($cols[4][1]*$scale,$rh,$fmt($g_moy_val),1,0,'C',true);
    $pdf->SetTextColor(0,0,0);
    $pdf->Cell(($cols[5][1]+$cols[6][1])*$scale,$rh,'',1,0,'C',true);
    $pdf->Cell($cols[7][1]*$scale,$rh,$g_admis.'/'.$g_nb,1,0,'C',true);
    $pdf->Cell($cols[8][1]*$scale,$rh,$g_taux.'%',1,0,'C',true);
    $pdf->Cell($cols[9][1]*$scale,$rh,'',1,1,'C',true);

// ══════════════════════════════════════════════════════════════════
} elseif ($onglet === 'disciplines') {
    foreach ($classes as $c) {
        $disc = db_all(
            "SELECT d.id_mat,d.coef,m.libelle AS matiere,TRIM(CONCAT(e.nom_ens,' ',COALESCE(e.prenom_ens,''))) AS enseignant
             FROM discipline d JOIN matiere m ON m.id=d.id_mat AND m.actif=1
             LEFT JOIN dispenser disp ON disp.id_mat=d.id_mat AND disp.IDClasses=d.IDClasses AND disp.val_annee=?
             LEFT JOIN enseignant e ON e.matricule_ens=disp.matricule_ens
             WHERE d.IDClasses=? ORDER BY d.ordre,m.libelle",
            [$val_annee, $c['id']]
        );
        if (empty($disc)) continue;
        // Compétences (par matière) du trimestre actif — ou des trimestres
        // de l'année fusionnés en vue annuelle (chantier APC).
        $enrolled = array_column(db_all("SELECT e.id FROM eleve e JOIN inscription i ON i.id_eleve=e.id AND i.id_classe=? AND i.id_annee=? WHERE e.statut='actif'",[$c['id'],$id_annee]),'id');
        $trims_calc = $vue === 'annee'
            ? array_column(db_all("SELECT id FROM trimestre WHERE id_annee=? ORDER BY ordre", [$id_annee]), 'id')
            : ($id_trim_comp ? [$id_trim_comp] : []);
        $notes_idx_mat = [];
        foreach ($trims_calc as $tid) {
            $dcomp = pv_charger_donnees_comp((int)$c['id'], (int)$tid, $id_annee);
            foreach ($dcomp['competences_par_mat'] as $id_mat => $comps) {
                foreach ($comps as $comp) {
                    $cid = (int)$comp['id'];
                    foreach ($enrolled as $eid) {
                        if (isset($dcomp['notes_idx'][$eid][$cid])) {
                            $notes_idx_mat[$id_mat][$eid][] = $dcomp['notes_idx'][$eid][$cid];
                        }
                    }
                }
            }
        }

        // En-tête classe
        $pdf->SetFont('Arial','B',9); $pdf->SetFillColor(26,60,107); $pdf->SetTextColor(255,255,255);
        $pdf->SetX($ml); $pdf->Cell($uw,6,uc('Classe/Class : '.strtoupper($c['designation'])),1,1,'L',true);
        $pdf->SetTextColor(0,0,0);

        $pdf->SetFont('Arial','B',7.5); $pdf->SetFillColor(200,214,235); $pdf->SetX($ml);
        $c2 = [[uc('Matière/Subject'),58],[uc('Coef.'),14],[uc('Enseignant/Teacher'),54],[uc('Nb notes/Nb grades'),24],[uc('Moy classe/Class avg.'),26],[uc('Min'),18],[uc('Max'),18],[uc('Admis/Passed'),20],[uc('Taux/Rate'),20],[uc('Mention'),28]];
        $tw2 = array_sum(array_column($c2,1)); $sc2=$uw/$tw2;
        foreach($c2 as [$lbl,$w]) $pdf->Cell($w*$sc2,$rh,$lbl,1,0,'C',true);
        $pdf->Ln();
        $pdf->SetFont('Arial','',7.5);

        foreach($disc as $d){
            $avgs=[];
            foreach($enrolled as $eid){
                $vals = $notes_idx_mat[$d['id_mat']][$eid] ?? [];
                if(!empty($vals)) $avgs[]=array_sum($vals)/count($vals);
            }
            $nb_s=count($avgs);
            $moy_cl=!empty($avgs)?array_sum($avgs)/count($avgs):null;
            $admis=count(array_filter($avgs,fn($a)=>$a>=10));
            $taux=$nb_s>0?round($admis/$nb_s*100,1):0;
            $mention=$moy_cl!==null?($moy_cl>=14?'TB':($moy_cl>=12?'B':($moy_cl>=10?'AB':'Insuf'))):'—';
            $fill=($moy_cl!==null&&$moy_cl<10);
            $pdf->SetFillColor(255,240,240);
            $pdf->SetX($ml);
            $pdf->Cell($c2[0][1]*$sc2,$rh,uc($d['matiere']),1,0,'L',$fill);
            $pdf->Cell($c2[1][1]*$sc2,$rh,(string)$d['coef'],1,0,'C',$fill);
            $pdf->Cell($c2[2][1]*$sc2,$rh,uc(mb_strimwidth($d['enseignant']??'—',0,28,'…')),1,0,'L',$fill);
            $pdf->Cell($c2[3][1]*$sc2,$rh,$nb_s.'/'.count($enrolled),1,0,'C',$fill);
            $moy_cl!==null&&$moy_cl>=10?$pdf->SetTextColor(0,100,0):$pdf->SetTextColor(180,0,0);
            $pdf->Cell($c2[4][1]*$sc2,$rh,$fmt($moy_cl),1,0,'C',$fill);
            $pdf->SetTextColor(0,0,0);
            $pdf->Cell($c2[5][1]*$sc2,$rh,$fmt(!empty($avgs)?min($avgs):null),1,0,'C',$fill);
            $pdf->Cell($c2[6][1]*$sc2,$rh,$fmt(!empty($avgs)?max($avgs):null),1,0,'C',$fill);
            $pdf->Cell($c2[7][1]*$sc2,$rh,(string)$admis,1,0,'C',$fill);
            $pdf->Cell($c2[8][1]*$sc2,$rh,$taux.'%',1,0,'C',$fill);
            $pdf->Cell($c2[9][1]*$sc2,$rh,uc($mention),1,1,'C',$fill);
        }
        $pdf->Ln(3);
    }

// ══════════════════════════════════════════════════════════════════
} elseif ($onglet === 'effectifs') {
    $pdf->SetFont('Arial','B',8); $pdf->SetFillColor(26,60,107); $pdf->SetTextColor(255,255,255);
    $pdf->SetX($ml);
    $c3=[[uc('Section/Filière-Stream'),62],[uc('Classe/Class'),44],[uc('Niveau/Level'),30],[uc('Effectif/Total'),24],[uc('Filles/Girls'),22],[uc('Garçons/Boys'),22],[uc('% Filles/% Girls'),28],[uc('Redoublants/Repeaters'),36]];
    $tw3=array_sum(array_column($c3,1));$sc3=$uw/$tw3;
    foreach($c3 as [$lbl,$w]) $pdf->Cell($w*$sc3,$rh+1,$lbl,1,0,'C',true);
    $pdf->Ln(); $pdf->SetTextColor(0,0,0); $pdf->SetFont('Arial','',8);

    $sections=[];
    $all_classes = db_all("SELECT c.* FROM classe c JOIN inscription i ON i.id_classe=c.id AND i.id_annee=? WHERE c.archivee=0 GROUP BY c.id ORDER BY c.libelle_section,c.ordre,c.designation", [$id_annee]);
    foreach($all_classes as $c){
        $sec=$c['libelle_section']??'Non définie/Not defined';
        $nb=(int)db_val("SELECT COUNT(*) FROM inscription i JOIN eleve e ON e.id=i.id_eleve WHERE i.id_classe=? AND i.id_annee=? AND e.statut='actif'",[$c['id'],$id_annee]);
        $nf=(int)db_val("SELECT COUNT(*) FROM inscription i JOIN eleve e ON e.id=i.id_eleve WHERE i.id_classe=? AND i.id_annee=? AND e.statut='actif' AND e.sexe='F'",[$c['id'],$id_annee]);
        $nr=(int)db_val("SELECT COUNT(*) FROM inscription i JOIN eleve e ON e.id=i.id_eleve WHERE i.id_classe=? AND i.id_annee=? AND e.statut='actif' AND i.statut='redoublant'",[$c['id'],$id_annee]);
        $sections[$sec][]=['classe'=>$c['designation'],'niveau'=>$c['code_niveau']??'—','nb'=>$nb,'filles'=>$nf,'garcons'=>$nb-$nf,'redoub'=>$nr];
    }
    $gTotal=['nb'=>0,'filles'=>0,'garcons'=>0,'redoub'=>0];
    foreach($sections as $sec=>$rows){
        $pdf->SetFont('Arial','B',8);$pdf->SetFillColor(220,230,245);$pdf->SetX($ml);
        $pdf->Cell($uw,$rh,uc(strtoupper($sec)),1,1,'L',true);
        $pdf->SetFont('Arial','',8);
        $stot=['nb'=>0,'filles'=>0,'garcons'=>0,'redoub'=>0];
        foreach($rows as $r){
            $pct=$r['nb']>0?round($r['filles']/$r['nb']*100,1):0;
            $pdf->SetFillColor(248,250,255);$pdf->SetX($ml);
            $pdf->Cell($c3[0][1]*$sc3,$rh,'',1,0,'C',true);
            $pdf->Cell($c3[1][1]*$sc3,$rh,uc($r['classe']),1,0,'L',true);
            $pdf->Cell($c3[2][1]*$sc3,$rh,uc($r['niveau']),1,0,'C',true);
            $pdf->Cell($c3[3][1]*$sc3,$rh,(string)$r['nb'],1,0,'C',true);
            $pdf->Cell($c3[4][1]*$sc3,$rh,(string)$r['filles'],1,0,'C',true);
            $pdf->Cell($c3[5][1]*$sc3,$rh,(string)$r['garcons'],1,0,'C',true);
            $pdf->Cell($c3[6][1]*$sc3,$rh,$pct.'%',1,0,'C',true);
            $pdf->Cell($c3[7][1]*$sc3,$rh,(string)$r['redoub'],1,1,'C',true);
            foreach(['nb','filles','garcons','redoub'] as $k){$stot[$k]+=$r[$k];$gTotal[$k]+=$r[$k];}
        }
        $pdf->SetFont('Arial','B',8);$pdf->SetFillColor(200,214,235);$pdf->SetX($ml);
        $pdf->Cell($c3[0][1]*$sc3,$rh,uc('Sous-total/Subtotal'),1,0,'L',true);
        $pdf->Cell($c3[1][1]*$sc3,$rh,'',1,0,'C',true);
        $pdf->Cell($c3[2][1]*$sc3,$rh,'',1,0,'C',true);
        $pdf->Cell($c3[3][1]*$sc3,$rh,(string)$stot['nb'],1,0,'C',true);
        $pdf->Cell($c3[4][1]*$sc3,$rh,(string)$stot['filles'],1,0,'C',true);
        $pdf->Cell($c3[5][1]*$sc3,$rh,(string)$stot['garcons'],1,0,'C',true);
        $pct2=$stot['nb']>0?round($stot['filles']/$stot['nb']*100,1):0;
        $pdf->Cell($c3[6][1]*$sc3,$rh,$pct2.'%',1,0,'C',true);
        $pdf->Cell($c3[7][1]*$sc3,$rh,(string)$stot['redoub'],1,1,'C',true);
    }
    $pdf->SetFont('Arial','B',9);$pdf->SetFillColor(26,60,107);$pdf->SetTextColor(255,255,255);$pdf->SetX($ml);
    $pdf->Cell($c3[0][1]*$sc3,$rh+1,uc('TOTAL GÉNÉRAL/GRAND TOTAL'),1,0,'L',true);
    $pdf->Cell($c3[1][1]*$sc3,$rh+1,'',1,0,'C',true);
    $pdf->Cell($c3[2][1]*$sc3,$rh+1,'',1,0,'C',true);
    $pdf->Cell($c3[3][1]*$sc3,$rh+1,(string)$gTotal['nb'],1,0,'C',true);
    $pdf->Cell($c3[4][1]*$sc3,$rh+1,(string)$gTotal['filles'],1,0,'C',true);
    $pdf->Cell($c3[5][1]*$sc3,$rh+1,(string)$gTotal['garcons'],1,0,'C',true);
    $gPct=$gTotal['nb']>0?round($gTotal['filles']/$gTotal['nb']*100,1):0;
    $pdf->Cell($c3[6][1]*$sc3,$rh+1,$gPct.'%',1,0,'C',true);
    $pdf->Cell($c3[7][1]*$sc3,$rh+1,(string)$gTotal['redoub'],1,1,'C',true);

// ══════════════════════════════════════════════════════════════════
// ══════════════════════════════════════════════════════════════════
} elseif ($onglet === 'section') {
    $all_classes = db_all("SELECT c.* FROM classe c JOIN inscription i ON i.id_classe=c.id AND i.id_annee=? WHERE c.archivee=0 GROUP BY c.id ORDER BY c.libelle_section, c.ordre, c.designation", [$id_annee]);
    $par_section = [];
    foreach ($all_classes as $c) { $par_section[$c['libelle_section'] ?? 'Non définie'][] = $c; }
    ksort($par_section);
    foreach ($par_section as $section => $classes_section) {
        $lignes = [];
        foreach ($classes_section as $c) {
            $lignes[] = ['classe' => $c['designation'], 'bilan' => calc_bilan_classe_genre_comp((int)$c['id'], $id_annee, $val_annee, $vue, $id_trim_comp)];
        }
        pdf_tableau_bilan($pdf, $ml, $uw, $rh, $section, $lignes, $bilan_cols_pdf);
    }

// ══════════════════════════════════════════════════════════════════
} elseif ($onglet === 'niveau') {
    $all_classes = db_all("SELECT c.* FROM classe c JOIN inscription i ON i.id_classe=c.id AND i.id_annee=? WHERE c.archivee=0 GROUP BY c.id ORDER BY c.ordre, c.designation", [$id_annee]);
    $niveaux_ref = db_all("SELECT code_niveau, libelle_niv FROM niveau ORDER BY ordre_niveau");
    $niveaux_map = array_column($niveaux_ref, 'libelle_niv', 'code_niveau');
    $ordre_niv   = array_column($niveaux_ref, 'libelle_niv');
    $par_niveau = [];
    foreach ($all_classes as $c) {
        $niv = $niveaux_map[$c['code_niveau']] ?? ($c['code_niveau'] ?: 'Non défini');
        $par_niveau[$niv][] = $c;
    }
    uksort($par_niveau, function ($a, $b) use ($ordre_niv) {
        $ia = array_search($a, $ordre_niv); $ib = array_search($b, $ordre_niv);
        if ($ia === false) $ia = 999;
        if ($ib === false) $ib = 999;
        return $ia <=> $ib;
    });
    foreach ($par_niveau as $niveau => $classes_niveau) {
        $lignes = [];
        foreach ($classes_niveau as $c) {
            $lignes[] = ['classe' => $c['designation'], 'bilan' => calc_bilan_classe_genre_comp((int)$c['id'], $id_annee, $val_annee, $vue, $id_trim_comp)];
        }
        pdf_tableau_bilan($pdf, $ml, $uw, $rh, $niveau, $lignes, $bilan_cols_pdf);
    }

// ══════════════════════════════════════════════════════════════════
} elseif ($onglet === 'matiere') {
    $classe_ids_mat = array_column($classes, 'id');
    $mat_stats = [];
    // Compétences (chantier APC) : jointure via competence.code_niveau pour
    // ne prendre que les compétences du niveau de chaque classe.
    $trims_calc_mat = $vue === 'annee'
        ? array_column(db_all("SELECT id FROM trimestre WHERE id_annee=? ORDER BY ordre", [$id_annee]), 'id')
        : ($id_trim_comp ? [$id_trim_comp] : []);
    if (!empty($classe_ids_mat) && !empty($trims_calc_mat)) {
        $in_c = implode(',', array_fill(0, count($classe_ids_mat), '?'));
        $in_t = implode(',', array_fill(0, count($trims_calc_mat), '?'));
        $rows = db_all(
            "SELECT n.id_matiere, n.id_eleve, n.valeur, m.libelle AS matiere
             FROM note n
             JOIN competence comp ON comp.id = n.id_competence AND comp.id_trim IN ($in_t)
             JOIN inscription i ON i.id_eleve=n.id_eleve AND i.id_annee=? AND i.id_classe IN ($in_c)
             JOIN classe cl ON cl.id=i.id_classe AND cl.code_niveau=comp.code_niveau
             JOIN matiere m ON m.id=n.id_matiere AND m.actif=1
             JOIN discipline d ON d.id_mat=n.id_matiere AND d.IDClasses=i.id_classe",
            array_merge($trims_calc_mat, [$id_annee], $classe_ids_mat)
        );
        $par_mat = [];
        foreach ($rows as $r) {
            $par_mat[$r['id_matiere']]['libelle'] = $r['matiere'];
            $par_mat[$r['id_matiere']]['vals'][$r['id_eleve']][] = (float)$r['valeur'];
        }
        foreach ($par_mat as $info) {
            $avgs = [];
            foreach ($info['vals'] as $vs) { $avgs[] = array_sum($vs) / count($vs); }
            $nb = count($avgs);
            $admis = count(array_filter($avgs, fn($a) => $a >= 10));
            $mat_stats[] = [
                'matiere' => $info['libelle'], 'nb' => $nb,
                'moy' => $nb > 0 ? array_sum($avgs) / $nb : null,
                'min' => $nb > 0 ? min($avgs) : null, 'max' => $nb > 0 ? max($avgs) : null,
                'taux' => $nb > 0 ? round($admis / $nb * 100, 1) : 0,
            ];
        }
        usort($mat_stats, fn($a, $b) => strcmp($a['matiere'], $b['matiere']));
    }
    $pdf->SetFont('Arial', 'B', 8); $pdf->SetFillColor(26, 60, 107); $pdf->SetTextColor(255, 255, 255); $pdf->SetX($ml);
    $c5 = [[uc('Matière/Subject'), 70], [uc('Nb notes/Nb grades'), 30], [uc('Moyenne/Average'), 30], ['Min', 24], ['Max', 24], [uc('Taux réussite/Pass rate'), 34]];
    $tw5 = array_sum(array_column($c5, 1)); $sc5 = $uw / $tw5;
    foreach ($c5 as [$lbl, $w]) $pdf->Cell($w * $sc5, $rh, $lbl, 1, 0, 'C', true);
    $pdf->Ln(); $pdf->SetTextColor(0, 0, 0); $pdf->SetFont('Arial', '', 8);
    foreach ($mat_stats as $i => $ms) {
        $fill = ($i % 2 === 0);
        $pdf->SetFillColor(245, 248, 255); $pdf->SetX($ml);
        $pdf->Cell($c5[0][1] * $sc5, $rh, uc($ms['matiere']), 1, 0, 'L', $fill);
        $pdf->Cell($c5[1][1] * $sc5, $rh, (string)$ms['nb'], 1, 0, 'C', $fill);
        $ms['moy'] !== null && $ms['moy'] >= 10 ? $pdf->SetTextColor(0, 100, 0) : $pdf->SetTextColor(180, 0, 0);
        $pdf->Cell($c5[2][1] * $sc5, $rh, $fmt($ms['moy']), 1, 0, 'C', $fill);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell($c5[3][1] * $sc5, $rh, $fmt($ms['min']), 1, 0, 'C', $fill);
        $pdf->Cell($c5[4][1] * $sc5, $rh, $fmt($ms['max']), 1, 0, 'C', $fill);
        $pdf->Cell($c5[5][1] * $sc5, $rh, $ms['taux'] . '%', 1, 1, 'C', $fill);
    }

// ══════════════════════════════════════════════════════════════════
} elseif ($onglet === 'enseignants' && $is_admin) {
    $ens_list = db_all(
        "SELECT e.matricule_ens,e.nom_ens,e.prenom_ens,e.civilite_ens,e.id_grade,e.matiere_enseignee,
                COUNT(DISTINCT d.IDClasses) AS nb_classes, COUNT(DISTINCT d.id_mat) AS nb_matieres,
                ep.IDClasses AS id_classe_pp
         FROM enseignant e
         LEFT JOIN dispenser d ON d.matricule_ens=e.matricule_ens AND d.val_annee=?
         LEFT JOIN enseignat_principal ep ON ep.matricule_ens=e.matricule_ens AND ep.val_annee=?
         GROUP BY e.matricule_ens ORDER BY e.nom_ens",
        [$val_annee,$val_annee]
    );
    $pdf->SetFont('Arial','B',8);$pdf->SetFillColor(26,60,107);$pdf->SetTextColor(255,255,255);$pdf->SetX($ml);
    $c4=[[uc('Matricule/ID No.'),32],[uc('Nom et Prénom/Name'),64],['Grade',28],[uc('Matière enseignée/Subject taught'),60],['Classes',20],[uc('Matières/Subjects'),24],[uc('Prof. Principal/Class Teacher'),56]];
    $tw4=array_sum(array_column($c4,1));$sc4=$uw/$tw4;
    foreach($c4 as [$lbl,$w]) $pdf->Cell($w*$sc4,$rh+1,$lbl,1,0,'C',true);
    $pdf->Ln();$pdf->SetTextColor(0,0,0);$pdf->SetFont('Arial','',8);
    foreach($ens_list as $i=>$e){
        $pp_lib='—';
        if($e['id_classe_pp']){ $cl=db_one("SELECT designation FROM classe WHERE id=?",[$e['id_classe_pp']]); $pp_lib=$cl['designation']??'—';}
        $fill=($i%2===0);$pdf->SetFillColor(245,248,255);$pdf->SetX($ml);
        $pdf->Cell($c4[0][1]*$sc4,$rh,$e['matricule_ens'],1,0,'C',$fill);
        $pdf->Cell($c4[1][1]*$sc4,$rh,uc(mb_strimwidth(($e['civilite_ens']??'').' '.strtoupper($e['nom_ens']).' '.($e['prenom_ens']??''),0,38,'…')),1,0,'L',$fill);
        $pdf->Cell($c4[2][1]*$sc4,$rh,uc($e['id_grade']??'—'),1,0,'C',$fill);
        $pdf->Cell($c4[3][1]*$sc4,$rh,uc(mb_strimwidth($e['matiere_enseignee']??'—',0,32,'…')),1,0,'L',$fill);
        $pdf->Cell($c4[4][1]*$sc4,$rh,(string)$e['nb_classes'],1,0,'C',$fill);
        $pdf->Cell($c4[5][1]*$sc4,$rh,(string)$e['nb_matieres'],1,0,'C',$fill);
        $pdf->Cell($c4[6][1]*$sc4,$rh,uc(mb_strimwidth($pp_lib,0,26,'…')),1,1,'C',$fill);
    }
}

// Pied de page date
$pdf->Ln(3);
$pdf->SetFont('Arial','I',8);
$pdf->SetX($ml);
$pdf->Cell($uw,5,uc('Édité le/Issued on '.date('d/m/Y à H:i').' — '.($etab['nom_fr']??'')),0,1,'R');

// Signature numérique du chef d'établissement (sur demande uniquement,
// jamais automatique).
if (($_GET['signature'] ?? '0') === '1') {
    $sig_w = 24;
    $ph = $pdf->GetPageHeight();
    $sx = $pw - $ml - $sig_w;
    $sy = $pdf->GetY() + 1;
    pdf_signature_appliquer($pdf, 'stat_generale', 'chef_etablissement', 0, 0, $pw, $ph, [
        'x_pct' => $sx / $pw * 100, 'y_pct' => $sy / $ph * 100, 'w_pct' => $sig_w / $pw * 100, 'h_pct' => null,
    ]);
}

$mode   = $dl ? 'D' : 'I';
$fname  = 'stats_'.$onglet.'_'.date('Ymd').'.pdf';
$pdf->Output($mode, $fname);