<?php
// secondaire/commun/pages/emploi_temps/pdf.php — emploi du temps imprimable, au format
// des fiches du lycée : en-tête bilingue, « EMPLOI DE TEMPS INDIVIDUEL /
// INDIVIDUAL TIME TABLE », discipline, heures dues / faites / supplémentaires,
// grille jours × périodes avec colonnes RÉCRÉATION et PAUSE, ligne du samedi,
// notes (NB) et signatures du Censeur et du Proviseur.
//   ?vue=enseignant&id=<matricule>   ?vue=classe&id=<classe>   &tous=1 : un document par page
//   &couleur=0 : noir et blanc
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
require_once __DIR__ . '/_edt_lib.php';
require_once __DIR__ . '/../../pdf/fpdf.php';
require_once __DIR__ . '/../../pdf/header_pdf.php';
exiger_role(array_merge(EDT_ROLES_GESTION, ['ENSEIGNANT', 'SECRETAIRE', 'INTENDANT']));

$a = edt_annee_id();
$vue = ($_GET['vue'] ?? '') === 'classe' ? 'classe' : 'enseignant';
$gestion = in_array(role_connecte(), EDT_ROLES_GESTION, true) || (function_exists('est_fondateur') && est_fondateur());
$id = (int) ($_GET['id'] ?? 0);
$tous = !empty($_GET['tous']) && $gestion;
if (!$gestion) {                                   // un enseignant n'imprime que le sien, une fois publié
    $vue = 'enseignant'; $tous = false; $id = (int) (matricule_ens_courant() ?? 0);
    if (!edt_publie($a) || !$id) { http_response_code(403); exit('Emploi du temps non disponible.'); }
}
$couleur = ($_GET['couleur'] ?? '1') !== '0';

$mod = new EdtModele($a);
$etab = edt_etablissement_pdf();   // primaire : colonnes ramenées au format du secondaire
$annee = get_annee_active()['val_annee'];
$creneaux = edt_creneaux($a);
$jours = edt_jours_ouvres($a);
$samediTxt = (string) edt_param($a, 'samedi_texte', 'RATTRAPAGE ET DEVOIRS SURVEILLÉS');
$nb = array_values(array_filter(array_map('trim', explode("\n", (string) edt_param($a, 'pdf_nb', "Cet emploi de temps est essentiellement provisoire.\nTout changement opéré dans cet emploi de temps ne sera valide que s'il est confirmé par le Censeur.\nTous les enseignants qui ont cours à 07h30 min les lundi et vendredi sont tenus d'assister au rassemblement de 07h10 min.")))));
$ville = (string) edt_param($a, 'pdf_ville', $etab['ville'] ?? '');

if ($tous) {
    $cibles = $vue === 'classe'
        ? array_map('intval', array_column(edt_classes(), 'id'))
        : array_map('intval', array_column(db_all("SELECT DISTINCT matricule_ens FROM edt_seance WHERE id_annee=? AND matricule_ens IS NOT NULL
                                                  UNION SELECT DISTINCT matricule_ens FROM edt_service WHERE id_annee=? AND matricule_ens IS NOT NULL", [$a, $a]), 'matricule_ens'));
    if ($vue === 'enseignant') usort($cibles, fn($x, $y) => strcmp($mod->ens[$x] ?? '', $mod->ens[$y] ?? ''));
} else {
    $cibles = $id ? [$id] : [];
}
if (!$cibles) exit('Rien à imprimer.');
if ($tous) @set_time_limit(600);

$pdf = new FPDF('L', 'mm', 'A4');
$pdf->SetMargins(8, 8, 8);
$pdf->SetAutoPageBreak(false);
$pw = $pdf->GetPageWidth(); $ph = $pdf->GetPageHeight();
$hex = fn(string $h) => [hexdec(substr($h, 1, 2)), hexdec(substr($h, 3, 2)), hexdec(substr($h, 5, 2))];

// créneaux interdits « pour tous » (APPS…) : texte affiché dans les cases
$bloquesTous = [];
foreach ($mod->regles as $r) if ($r['type'] === 'creneaux_interdits' && $r['cible_type'] === 'tous')
    foreach ($r['cases'] as $j => $crs) foreach ($crs as $cr => $_) $bloquesTous["$j|$cr"] = $r['commentaire'] ?: '';

foreach ($cibles as $cid) {
    $filtre = $vue === 'classe' ? fn($s) => $s['id_classe'] === $cid : fn($s) => $s['matricule_ens'] === $cid;
    $blocs = $mod->blocs($filtre);
    if ($tous && !$blocs && $vue === 'classe') continue;
    $pdf->AddPage();
    pdf_filigrane($pdf, $etab, $pw, $ph);
    pdf_entete($pdf, $etab, $pw, 8, 6, 0.82);

    // ── Titre ──────────────────────────────────────────────────────────
    $y = max($pdf->GetY(), 33);
    $pdf->SetXY(8, $y);
    $pdf->SetFont('Arial', 'B', 12.5);
    $titre = $vue === 'classe' ? 'EMPLOI DU TEMPS DE LA CLASSE / CLASS TIME TABLE' : 'EMPLOI DE TEMPS INDIVIDUEL / INDIVIDUAL TIME TABLE';
    $pdf->Cell($pw - 16, 7, pdf_u($titre . '     ANNÉE SCOLAIRE ' . $annee), 0, 1, 'C');

    // ── Informations ───────────────────────────────────────────────────
    $ligne = function (array $champs) use ($pdf, $pw) {
        $pdf->SetX(14);
        $larg = ($pw - 28) / array_sum(array_column($champs, 2));
        foreach ($champs as [$lib, $val, $part]) {
            $w = $larg * $part;
            $pdf->SetFont('Arial', '', 9); $lw = $pdf->GetStringWidth(pdf_u($lib . ' : ')) + 1;
            $pdf->Cell($lw, 6, pdf_u($lib . ' : '), 0, 0, 'L');
            $txt = pdf_police_ajustee($pdf, pdf_u((string) $val), 'B', 10, $w - $lw, 7);
            $x = $pdf->GetX(); $yy = $pdf->GetY();
            $pdf->Cell($w - $lw, 6, $txt, 0, 0, 'L');
            $pdf->SetDrawColor(120, 120, 120); $pdf->Line($x, $yy + 5.2, $x + $w - $lw - 3, $yy + 5.2); $pdf->SetDrawColor(0, 0, 0);
        }
        $pdf->Ln(6.5);
    };
    if ($vue === 'enseignant') {
        $e = db_one("SELECT * FROM enseignant WHERE matricule_ens=?", [$cid]) ?? [];
        $d = db_one("SELECT * FROM edt_enseignant WHERE id_annee=? AND matricule_ens=?", [$a, $cid]) ?? [];
        $faites = (int) db_val("SELECT COUNT(DISTINCT jour, id_creneau) FROM edt_seance WHERE id_annee=? AND matricule_ens=?", [$a, $cid]);
        $dues = isset($d['heures_dues']) && $d['heures_dues'] !== null ? (float) $d['heures_dues'] : null;
        $fmt = fn($v) => rtrim(rtrim(number_format((float) $v, 1, ',', ''), '0'), ',');
        $ligne([['DISCIPLINE', $d['discipline'] ?? ($e['matiere_enseignee'] ?? ''), 3], ['ENSEIGNANT', $mod->ens[$cid] ?? '', 5], ['GRADE', $d['grade'] ?? ($e['id_grade'] ?? ''), 2]]);
        $ligne([['HEURES DUES', $dues !== null ? $fmt($dues) : '', 2], ['HEURES FAITES', $fmt($faites), 2], ['HEURES SUPPLÉMENTAIRES', $dues !== null && $faites > $dues ? $fmt($faites - $dues) : '', 3], ['N° TEL', $e['tel_ens'] ?? '', 3]]);
    } else {
        $pp = db_val("SELECT TRIM(CONCAT(e.nom_ens,' ',IFNULL(e.prenom_ens,''))) FROM " . edt_t('enseignat_principal') . " p JOIN enseignant e ON e.matricule_ens=p.matricule_ens WHERE p.IDClasses=? AND p.val_annee=?", [$cid, $annee]);
        $eff = edt_primaire()   // primaire : inscrire / eleve (val_annee), secondaire : inscription / eleve (id_annee)
            ? (int) db_val("SELECT COUNT(*) FROM inscrire i JOIN eleve e ON e.id_eleve=i.id_eleve WHERE i.IDClasses=? AND i.val_annee=? AND COALESCE(e.statut,'actif')='actif'", [$cid, $annee])
            : (int) db_val("SELECT COUNT(*) FROM inscription i JOIN eleve e ON e.id=i.id_eleve WHERE i.id_classe=? AND i.id_annee=? AND e.statut='actif'", [$cid, $a]);
        $vol = (int) db_val("SELECT COUNT(*) FROM edt_seance WHERE id_annee=? AND id_classe=? AND sous_groupe IN ('','A')", [$a, $cid]);
        $ligne([['CLASSE', $mod->classes[$cid]['designation'] ?? '', 3], [edt_primaire() ? 'TITULAIRE' : 'PROFESSEUR PRINCIPAL', $pp ?: '', 5], ['EFFECTIF', $eff ?: '', 2]]);
        $ligne([['VOLUME HORAIRE HEBDOMADAIRE', $vol . ' h', 5], ['SECTION', $mod->classes[$cid]['libelle_section'] ?? '', 5]]);
    }

    // ── Grille ─────────────────────────────────────────────────────────
    $x0 = 8; $gw = $pw - 16; $wJour = 28; $wPause = 8;
    $nbCours = count(array_filter($creneaux, fn($c) => $c['type'] === 'cours'));
    $nbPause = count($creneaux) - $nbCours;
    $wCours = ($gw - $wJour - $nbPause * $wPause) / max(1, $nbCours);
    $xs = []; $x = $x0 + $wJour;
    foreach ($creneaux as $c) { $w = $c['type'] === 'pause' ? $wPause : $wCours; $xs[(int) $c['id']] = [$x, $w]; $x += $w; }
    $samedi = $samediTxt !== '' && !in_array(6, $jours, true);
    $lignes = array_merge($jours, $samedi ? [6] : []);
    $yG = $pdf->GetY() + 1.5; $hEnt = 13;
    $basNotes = 8 + 6 + count($nb) * 3.6 + 16;
    $hL = min(19, max(10, ($ph - $yG - $hEnt - $basNotes) / count($lignes)));

    // en-tête de grille
    $pdf->SetLineWidth(0.3);
    $pdf->Rect($x0, $yG, $wJour, $hEnt);
    $pdf->Line($x0, $yG, $x0 + $wJour, $yG + $hEnt);
    $pdf->SetFont('Arial', '', 7.5);
    $pdf->SetXY($x0 + 9, $yG + 1); $pdf->Cell($wJour - 10, 3.5, pdf_u('Périodes &'), 0, 2, 'R'); $pdf->Cell($wJour - 10, 3.5, 'Heures', 0, 0, 'R');
    $pdf->SetXY($x0 + 1, $yG + $hEnt - 4.5); $pdf->Cell(12, 4, 'JOUR', 0, 0, 'L');
    foreach ($creneaux as $c) {
        [$x, $w] = $xs[(int) $c['id']];
        if ($c['type'] === 'pause') {
            $pdf->Rect($x, $yG, $w, $hEnt);
            $pdf->SetFont('Arial', '', 5.5);
            $pdf->TextWithDirection($x + $w / 2 - 1, $yG + $hEnt - 0.8, str_replace(':', 'h', $c['debut']), 'U');
            $pdf->TextWithDirection($x + $w / 2 + 2, $yG + $hEnt - 0.8, str_replace(':', 'h', $c['fin']), 'U');
            continue;
        }
        $pdf->Rect($x, $yG, $w, $hEnt);
        $pdf->SetFont('Arial', 'B', 8.5); $pdf->SetXY($x, $yG + 1.5); $pdf->Cell($w, 4, pdf_u($c['libelle'] . '.'), 0, 2, 'L');
        $pdf->SetFont('Arial', '', 7.5); $pdf->SetXY($x, $yG + 7); $pdf->Cell($w, 4, str_replace(':', 'h', $c['debut']) . '-' . str_replace(':', 'h', $c['fin']), 0, 0, 'C');
    }

    // index des blocs par jour → segments (coupés aux pauses)
    $seg = [];
    foreach ($blocs as $b) {
        $cur = []; $prev = null;
        foreach ($b['creneaux'] as $cr) {
            $rg = $mod->cours[$cr]['rang'];
            if ($prev !== null && edt_pause_entre($creneaux, $prev, $rg)) { $seg[$b['jour']][] = [$b, $cur]; $cur = []; }
            $cur[] = $cr; $prev = $rg;
        }
        if ($cur) $seg[$b['jour']][] = [$b, $cur];
    }

    $yL = $yG + $hEnt;
    foreach ($lignes as $li => $j) {
        $yy = $yL + $li * $hL;
        $pdf->SetFont('Arial', '', 9);
        $pdf->Rect($x0, $yy, $wJour, $hL);
        $pdf->SetXY($x0 + 2, $yy); $pdf->Cell($wJour - 2, $hL, pdf_u(mb_strtoupper(EDT_JOURS[$j])), 0, 0, 'L');
        if ($j === 6 && $samedi) {
            // ligne du samedi : texte sur la matinée (jusqu'à la 2e pause)
            $pauses = array_values(array_filter($creneaux, fn($c) => $c['type'] === 'pause'));
            $fin = isset($pauses[1]) ? $xs[(int) $pauses[1]['id']][0] : $x0 + $gw;
            $pdf->Rect($x0 + $wJour, $yy, $fin - $x0 - $wJour, $hL);
            $pdf->SetFont('Arial', '', 10); $pdf->SetXY($x0 + $wJour + 2, $yy); $pdf->Cell($fin - $x0 - $wJour - 4, $hL, pdf_u($samediTxt), 0, 0, 'L');
            continue;
        }
        // cases vides + cases bloquées
        $occupe = [];
        foreach ($seg[$j] ?? [] as [$b, $crs]) foreach ($crs as $cr) $occupe[$cr] = true;
        $bloqueTxt = [];
        foreach ($creneaux as $c) {
            if ($c['type'] === 'pause') continue;
            [$x, $w] = $xs[(int) $c['id']];
            $pdf->Rect($x, $yy, $w, $hL);
        }
        // texte des créneaux bloqués, regroupé par plage contiguë
        $plage = null;
        $flush = function () use (&$plage, $pdf, $yy, $hL) {
            if (!$plage || $plage['txt'] === '') { $plage = null; return; }
            $pdf->SetFont('Arial', 'B', 10); $pdf->SetXY($plage['x'] + 1, $yy); $pdf->Cell($plage['w'] - 2, $hL / 2, pdf_u($plage['txt']), 0, 0, 'L'); $plage = null;
        };
        foreach ($creneaux as $c) {
            $k = $j . '|' . $c['id'];
            if ($c['type'] === 'pause' || isset($occupe[(int) $c['id']]) || !isset($bloquesTous[$k])) { $flush(); continue; }
            [$x, $w] = $xs[(int) $c['id']];
            if ($plage && $plage['txt'] === $bloquesTous[$k]) $plage['w'] = $x + $w - $plage['x']; else { $flush(); $plage = ['x' => $x, 'w' => $w, 'txt' => $bloquesTous[$k]]; }
        }
        $flush();
        // séances
        foreach ($seg[$j] ?? [] as [$b, $crs]) {
            $xA = $xs[$crs[0]][0]; $xB = $xs[end($crs)][0] + $xs[end($crs)][1]; $w = $xB - $xA;
            if ($couleur) { [$r, $g, $bl] = $hex(edt_couleur($b['id_mat'])); $pdf->SetFillColor($r, $g, $bl); $pdf->Rect($xA + 0.3, $yy + 0.3, $w - 0.6, $hL - 0.6, 'F'); }
            $pdf->Rect($xA, $yy, $w, $hL);
            $mat = $mod->mat[$b['id_mat']] ?? '?';
            $sous = $vue === 'classe' ? edt_nom_court($mod->ens[$b['matricule_ens']] ?? '') : ($mod->classes[$b['id_classe']]['designation'] ?? '');
            if ($b['id_salle']) $sous .= ' · ' . ($mod->salles[$b['id_salle']] ?? '');
            $yT = pdf_texte_ajuste($pdf, $xA, $yy + 1, $w, $mat, 8.5, 5.5, 2, 'B');
            pdf_texte_ajuste($pdf, $xA, max($yT, $yy + $hL / 2), $w, $sous, 7.5, 5, 1, '');
            if (count($crs) > 1) {   // flèches de durée, comme sur la fiche papier
                $pdf->SetDrawColor(90, 90, 90); $ya = $yy + $hL - 1.6;
                $pdf->Line($xA + 1.5, $ya, $xB - 1.5, $ya); $pdf->Line($xA + 1.5, $ya, $xA + 3, $ya - 0.8); $pdf->Line($xA + 1.5, $ya, $xA + 3, $ya + 0.8);
                $pdf->Line($xB - 1.5, $ya, $xB - 3, $ya - 0.8); $pdf->Line($xB - 1.5, $ya, $xB - 3, $ya + 0.8); $pdf->SetDrawColor(0, 0, 0);
            }
        }
    }
    // colonnes de pause sur toute la hauteur des jours
    $hJours = count($jours) * $hL;
    foreach ($creneaux as $c) {
        if ($c['type'] !== 'pause') continue;
        [$x, $w] = $xs[(int) $c['id']];
        $pdf->SetFillColor(240, 240, 240); $pdf->Rect($x, $yL, $w, $hJours, 'DF');
        $pdf->SetFont('Arial', 'B', 10);
        $txt = pdf_u(mb_strtoupper($c['libelle']));
        $pdf->TextWithDirection($x + $w / 2 + 1.5, $yL + $hJours / 2 + $pdf->GetStringWidth($txt) / 2, $txt, 'U');
    }

    // ── Notes et signatures ────────────────────────────────────────────
    $yN = $yL + count($lignes) * $hL + 2;
    $pdf->SetXY(18, $yN);
    foreach ($nb as $i => $t) {
        $pdf->SetFont('Arial', $i === 0 ? 'B' : '', 7.5);
        $pdf->SetX(18); $pdf->Cell(10, 3.6, $i === 0 ? 'NB :' : '', 0, 0);
        $pdf->SetFont('Arial', '', 7.5); $pdf->Cell(180, 3.6, pdf_u(($i + 1) . '- ' . $t), 0, 1);
    }
    $yS = $yN + count($nb) * 3.6 + 1;
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetXY($pw - 90, $yS); $pdf->Cell(80, 4.5, pdf_u(($ville !== '' ? mb_strtoupper($ville) : '') . ', le ' . str_repeat('.', 22)), 0, 2, 'C');
    $pdf->SetFont('Arial', 'B', 9.5);
    [$sig1, $sig2] = edt_signataires();   // Censeur + Proviseur/Principal, ou Directeur (primaire)
    if ($sig1 !== '') { $pdf->SetXY(30, $yS + 4.5); $pdf->Cell(60, 5, pdf_u($sig1), 0, 0, 'C'); }
    $pdf->SetXY($pw - 90, $yS + 4.5); $pdf->Cell(80, 5, pdf_u($sig2), 0, 0, 'C');
}
if ($pdf->PageNo() === 0) exit('Aucun emploi du temps à imprimer.');
$nom = 'emploi_du_temps_' . ($tous ? ($vue === 'classe' ? 'classes' : 'enseignants') : $vue . '_' . $cibles[0]) . '.pdf';
$pdf->Output(!empty($_GET['dl']) ? 'D' : 'I', $nom);
