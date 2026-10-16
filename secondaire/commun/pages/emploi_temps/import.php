<?php
// secondaire/commun/pages/emploi_temps/import.php — import / export Excel de l'emploi du temps.
//
// Format accepté (une ligne = une classe × une matière × un enseignant) :
//   Classe | Matière | Enseignant | Heures / sem. | Créneaux | Salle | Cours commun | Durée max | Groupe
// « Créneaux » (facultatif) : « Lun 1-2, Mer 4 » → séances placées dans la grille.
// Feuille « Enseignants » (facultative) : Enseignant | Discipline | Grade | Heures dues.
// Le récapitulatif « Recap enseignants par classe » (feuille Affectations) est
// accepté tel quel. Import en deux temps : analyse (aperçu), puis validation.
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
require_once __DIR__ . '/_edt_lib.php';
require_once __DIR__ . '/../../../../vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Style\{Alignment, Border, Fill};
exiger_role(EDT_ROLES_GESTION);

$a = edt_annee_id();
$ecrire = edt_peut_ecrire();
$TMP = 'siges_import_edt_';

// =====================================================================
//  Modèle / export
// =====================================================================
function edt_classeur(int $a, bool $avecDonnees): Spreadsheet {
    $ss = new Spreadsheet();
    $ss->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10);
    $ent = function ($ws, string $rng) {
        $s = $ws->getStyle($rng); $s->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $s->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1F3A5F');
        $s->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER)->setHorizontal(Alignment::HORIZONTAL_CENTER);
    };
    $liste = function ($ws, string $rng, string $f) {
        $v = new DataValidation(); $ws->setDataValidation($rng, $v);
        $v->setType(DataValidation::TYPE_LIST)->setAllowBlank(true)->setShowDropDown(true)->setShowErrorMessage(true)->setErrorTitle('Valeur non prévue')->setError('Choisissez dans la liste.')->setFormula1($f);
    };
    $wsC = $ss->getActiveSheet(); $wsC->setTitle('Cours');
    $wsE = $ss->createSheet(); $wsE->setTitle('Enseignants');
    $wsL = $ss->createSheet(); $wsL->setTitle('Listes');
    $wsM = $ss->createSheet(); $wsM->setTitle("Mode d'emploi");

    $classes = edt_classes(); $ens = edt_enseignants(); $salles = edt_salles();
    $mats = array_values(array_unique(array_column(db_all("SELECT DISTINCT TRIM(m.libelle) l FROM matiere m JOIN discipline d ON d.id_mat=m.id ORDER BY l"), 'l')));
    $wsL->fromArray([['Classes', 'Matières', 'Enseignants', 'Salles']], null, 'A1'); $ent($wsL, 'A1:D1');
    foreach ($classes as $i => $c) $wsL->setCellValue('A' . ($i + 2), $c['designation']);
    foreach ($mats as $i => $m) $wsL->setCellValue('B' . ($i + 2), $m);
    foreach ($ens as $i => $e) $wsL->setCellValue('C' . ($i + 2), $e['nom']);
    foreach ($salles as $i => $s) $wsL->setCellValue('D' . ($i + 2), $s['nom']);
    foreach (['A' => 20, 'B' => 48, 'C' => 38, 'D' => 26] as $k => $w) $wsL->getColumnDimension($k)->setWidth($w);

    $wsC->fromArray([['Classe', 'Matière', 'Enseignant', 'Heures / sem.', 'Créneaux', 'Salle', 'Cours commun', 'Durée max', 'Groupe', 'Remarque']], null, 'A1');
    $ent($wsC, 'A1:J1'); $wsC->getRowDimension(1)->setRowHeight(30);
    $r = 2;
    if ($avecDonnees) {
        $mod = new EdtModele($a);
        $seParCle = [];
        foreach ($mod->seances as $s) $seParCle[$s['id_classe'] . '|' . $s['id_mat'] . '|' . $s['matricule_ens']][] = [$s['jour'], $mod->cours[$s['id_creneau']]['rang'] ?? 0];
        $vus = [];
        foreach (edt_services($a) as $s) {
            $k = $s['id_classe'] . '|' . $s['id_mat'] . '|' . ($s['matricule_ens'] ?? '');
            $vus[$k] = true;
            $wsC->fromArray([$s['classe'], $s['matiere'], $s['enseignant'], (float) $s['heures'], edt_formater_cases($seParCle[$k] ?? []), $s['salle'], $s['code_commun'], (int) $s['duree_max'], '', $s['commentaire']], null, "A$r");
            $r++;
        }
        foreach ($seParCle as $k => $cases) {        // séances sans service
            if (isset($vus[$k])) continue;
            [$c, $m, $e] = explode('|', $k);
            $wsC->fromArray([$mod->classes[(int) $c]['designation'] ?? '', $mod->mat[(int) $m] ?? '', $e !== '' ? ($mod->ens[(int) $e] ?? '') : '', count($cases), edt_formater_cases($cases), '', '', '', '', 'séance sans service'], null, "A$r");
            $r++;
        }
    } else {
        $wsC->fromArray([[$classes[0]['designation'] ?? '1ère A COM', 'Mathématiques', $ens[0]['nom'] ?? '', 4, 'Lun 1-2, Jeu 3-4', '', '', 2, '', 'ligne d\'exemple : à remplacer']], null, 'A2');
        $wsC->getStyle('A2:J2')->getFont()->setItalic(true)->getColor()->setRGB('888888');
    }
    $MAX = max(600, $r + 200);
    $liste($wsC, "A2:A$MAX", '=Listes!$A$2:$A$300'); $liste($wsC, "B2:B$MAX", '=Listes!$B$2:$B$400');
    $liste($wsC, "C2:C$MAX", '=Listes!$C$2:$C$400'); $liste($wsC, "F2:F$MAX", '=Listes!$D$2:$D$200');
    foreach (['A' => 18, 'B' => 34, 'C' => 32, 'D' => 10, 'E' => 26, 'F' => 18, 'G' => 14, 'H' => 9, 'I' => 9, 'J' => 30] as $k => $w) $wsC->getColumnDimension($k)->setWidth($w);
    $wsC->freezePane('B2'); $wsC->setAutoFilter('A1:J' . max(2, $r - 1));

    $wsE->fromArray([['Enseignant', 'Discipline', 'Grade', 'Heures dues', 'Téléphone']], null, 'A1'); $ent($wsE, 'A1:E1');
    $rr = 2;
    $dues = [];
    foreach (db_all("SELECT * FROM edt_enseignant WHERE id_annee=?", [$a]) as $d) $dues[(int) $d['matricule_ens']] = $d;
    foreach ($ens as $e) {
        $d = $dues[(int) $e['matricule_ens']] ?? null;
        if (!$avecDonnees && $rr > 2) break;
        if ($avecDonnees && !$d) continue;
        $wsE->fromArray([$e['nom'], $d['discipline'] ?? '', $d['grade'] ?? '', $d && $d['heures_dues'] !== null ? (float) $d['heures_dues'] : null], null, "A$rr");
        $wsE->getCell("E$rr")->setValueExplicit((string) $e['tel_ens'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $rr++;
    }
    $liste($wsE, 'A2:A400', '=Listes!$C$2:$C$400');
    foreach (['A' => 34, 'B' => 24, 'C' => 10, 'D' => 12, 'E' => 14] as $k => $w) $wsE->getColumnDimension($k)->setWidth($w);

    $aide = [
        ['Une ligne', 'Une classe × une matière × un enseignant. Les listes déroulantes (Classe, Matière, Enseignant, Salle) reprennent exactement les noms de SIGES.'],
        ['Heures / sem.', 'Volume hebdomadaire de la matière pour cette classe. Si vide, il est déduit du nombre de créneaux.'],
        ['Créneaux', 'Facultatif. Jour abrégé + numéro de période : « Lun 1-2, Mer 4 » = lundi 1re et 2e heure + mercredi 4e heure. Les créneaux deviennent des séances ; sans créneaux, la génération automatique les placera.'],
        ['Cours commun', 'Même code sur les lignes de plusieurs classes = une seule séance partagée (EPS, langues…). Le récapitulatif « Cours commun avec » est aussi compris.'],
        ['Durée max', 'Nombre maximum d\'heures consécutives pour une séance (2 par défaut ; 3 à 6 pour les ateliers).'],
        ['Groupe', 'Facultatif : demi-groupe (A, B) quand la classe est partagée en deux pour les travaux pratiques.'],
        ['Enseignants', 'Feuille facultative : discipline, grade et heures dues affichés sur l\'emploi du temps individuel.'],
        ['Import', 'Menu Emploi du temps → Préparation → Importer Excel : déposez le fichier, vérifiez l\'aperçu (noms non reconnus en rouge), puis validez.'],
    ];
    foreach ($aide as $i => [$t, $d]) { $wsM->setCellValue('A' . ($i + 1), $t); $wsM->setCellValue('B' . ($i + 1), $d); }
    $wsM->getStyle('A1:A20')->getFont()->setBold(true); $wsM->getColumnDimension('A')->setWidth(18); $wsM->getColumnDimension('B')->setWidth(110);
    $wsM->getStyle('B1:B20')->getAlignment()->setWrapText(true);
    $ss->setActiveSheetIndex(0);
    return $ss;
}
if (isset($_GET['modele']) || isset($_GET['export'])) {
    $ss = edt_classeur($a, isset($_GET['export']));
    $nom = isset($_GET['export']) ? 'emploi_du_temps_' . str_replace('/', '-', get_annee_active()['val_annee']) . '.xlsx' : 'modele_import_emploi_du_temps.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $nom . '"');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save('php://output');
    exit;
}

// =====================================================================
//  Analyse d'un fichier
// =====================================================================
/** Valeurs d'une feuille SANS recalcul : formules = dernière valeur calculée par le tableur (rapide). */
function edt_feuille_valeurs(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $ws): array {
    $out = [];
    foreach ($ws->getRowIterator() as $row) {
        $ligne = [];
        $it = $row->getCellIterator(); $it->setIterateOnlyExistingCells(false);
        foreach ($it as $cell) {
            $ligne[] = $cell->isFormula() ? $cell->getOldCalculatedValue() : $cell->getValue();
            if (count($ligne) >= 30) break;
        }
        $out[] = $ligne;
    }
    return $out;
}

function edt_import_analyser(int $a, string $fichier): array {
    $r = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($fichier);
    $r->setReadDataOnly(true);
    $wb = $r->load($fichier);
    $syn = [
        'classe' => ['classe'], 'matiere' => ['matiere siges', 'matiere', 'discipline'], 'enseignant' => ['enseignant', 'professeur'],
        'heures' => ['heures sem classe', 'heures sem', 'heures', 'volume', 'h sem'], 'creneaux' => ['creneaux', 'creneau', 'horaire'],
        'salle' => ['salle'], 'commun' => ['cours commun', 'code commun'], 'dmax' => ['duree max'], 'groupe' => ['groupe', 'sous groupe'],
    ];
    $exclus = ['classe telle que lue', 'matiere telle que lue', 'heures comptees pour l enseignant'];
    $feuille = null; $entete = null; $hl = 0; $map = [];
    foreach ($wb->getAllSheets() as $ws) {
        if (in_array(edt_norm($ws->getTitle()), ['listes', 'mode d emploi', 'enseignants', 'programme siges', 'controle', 'par classe', 'par enseignant'], true)) continue;
        $rows = edt_feuille_valeurs($ws);
        foreach (array_slice($rows, 0, 10, true) as $i => $row) {
            $n = array_map(fn($v) => edt_norm((string) $v), $row);
            if (in_array('classe', $n, true) && array_filter($n, fn($v) => str_starts_with($v, 'matiere') || $v === 'discipline')) {
                $feuille = $rows; $hl = $i;
                foreach ($syn as $cle => $cands) {
                    foreach ($cands as $cand) {
                        foreach ($n as $ci => $v) if ($v !== '' && !in_array($v, $exclus, true) && ($v === $cand || str_starts_with($v, $cand . ' ') || ($cle !== 'classe' && str_starts_with($v, $cand)))) { $map[$cle] = $ci; break 2; }
                    }
                }
                break 2;
            }
        }
    }
    // colonne « Matière (telle que lue) » du récapitulatif, en secours quand « Matière SIGES » est vide
    $colLue = null;
    foreach (($feuille[$hl] ?? []) as $ci => $x) if (edt_norm((string) $x) === 'matiere telle que lue') $colLue = $ci;
    if ($feuille === null || !isset($map['classe'], $map['matiere'])) return ['erreur' => 'Aucune feuille avec des colonnes « Classe » et « Matière » n\'a été trouvée.'];

    $classes = []; foreach (edt_classes() as $c) $classes[edt_norm($c['designation'])] = (int) $c['id'];
    $ensList = edt_enseignants();
    $ensIdx = array_map(fn($e) => ['id' => (int) $e['matricule_ens'], 'nom' => $e['nom'], 't' => array_filter(explode(' ', edt_norm($e['nom'])))], $ensList);
    $trouverEns = function (string $nom) use ($ensIdx): ?int {
        $t = array_filter(explode(' ', edt_norm($nom)));
        if (!$t) return null;
        $exact = array_filter($ensIdx, fn($e) => implode(' ', $e['t']) === implode(' ', $t));
        if (count($exact) === 1) return current($exact)['id'];
        $inclus = array_filter($ensIdx, fn($e) => !array_diff($t, $e['t']));
        if (count($inclus) === 1) return current($inclus)['id'];
        $contient = array_filter($ensIdx, fn($e) => count($e['t']) >= 2 && !array_diff($e['t'], $t));
        return count($contient) === 1 ? current($contient)['id'] : null;
    };
    $catalogue = []; foreach (db_all("SELECT id, TRIM(libelle) l FROM matiere WHERE actif=1") as $m) $catalogue[edt_norm($m['l'])] ??= (int) $m['id'];
    $progs = [];
    $trouverMat = function (int $c, string $lib) use (&$progs, $catalogue): ?int {
        $progs[$c] ??= edt_programme_classe($c);
        $n = edt_norm($lib);
        if ($n === '') return null;
        foreach ($progs[$c] as $p) if (edt_norm($p['libelle']) === $n) return (int) $p['id_mat'];
        foreach ($progs[$c] as $p) { $q = edt_norm($p['libelle']); if (str_starts_with($q, $n) || str_starts_with($n, $q)) return (int) $p['id_mat']; }
        return $catalogue[$n] ?? null;
    };
    $salles = []; foreach (edt_salles(false) as $s) $salles[edt_norm($s['nom'])] = (int) $s['id'];
    $rangs = edt_rang_vers_id($a); $jours = edt_jours_ouvres($a);

    $lignes = []; $erreurs = []; $avert = []; $inconnus = [];
    $v = fn(array $row, string $k) => isset($map[$k]) ? trim((string) ($row[$map[$k]] ?? '')) : '';
    foreach ($feuille as $i => $row) {
        if ($i <= $hl) continue;
        $num = $i + 1;
        $cl = $v($row, 'classe');
        if ($cl === '') continue;
        $mat = $v($row, 'matiere'); $en = $v($row, 'enseignant');
        if ($mat === '' && $colLue !== null) $mat = trim((string) ($row[$colLue] ?? ''));   // récapitulatif : matière non rattachée
        $idc = $classes[edt_norm($cl)] ?? null;
        if (!$idc) { $erreurs[] = "Ligne $num : classe « $cl » inconnue."; continue; }
        $idm = $trouverMat($idc, $mat);
        if (!$idm) { $erreurs[] = "Ligne $num : matière « $mat » introuvable pour $cl."; continue; }
        $ide = $en !== '' ? $trouverEns($en) : null;
        if ($en !== '' && !$ide) $inconnus[edt_norm($en)] ??= ['nom' => $en, 'ens' => null, 'disc' => '', 'grade' => '', 'hd' => null, 'tel' => ''];
        $cases = [];
        foreach (edt_parser_cases($v($row, 'creneaux')) as [$j, $rg]) {
            if (!in_array($j, $jours, true)) { $avert[] = "Ligne $num : " . EDT_JOURS[$j] . " n'est pas un jour de cours (créneau ignoré)."; continue; }
            if (!isset($rangs[$rg])) { $avert[] = "Ligne $num : période $rg inexistante dans la grille (ignorée)."; continue; }
            $cases[] = [$j, $rangs[$rg]];
        }
        $hTxt = $v($row, 'heures');
        $h = (float) str_replace(',', '.', $hTxt);
        // « 0 » explicite (récapitulatif : Géographie comptée sur la ligne Histoire) : service gardé
        // pour l'affectation de l'enseignant, mais aucune séance — les créneaux sont déjà ceux de l'autre ligne.
        if ($hTxt !== '' && $h <= 0) $cases = [];
        elseif ($h <= 0) $h = count($cases);
        $commun = $v($row, 'commun');
        $code = null;
        if ($commun !== '') $code = (mb_strlen($commun) <= 20 && !str_contains($commun, '+') && !str_contains($commun, ' '))
            ? $commun : 'C' . substr(md5(edt_norm($en) . '|' . edt_norm($mat) . '|' . edt_norm($v($row, 'creneaux'))), 0, 8);
        $sa = $v($row, 'salle'); $ids = $sa !== '' ? ($salles[edt_norm($sa)] ?? null) : null;
        if ($sa !== '' && !$ids) $avert[] = "Ligne $num : salle « $sa » inconnue (ignorée).";
        $lignes[] = ['num' => $num, 'classe' => $idc, 'mat' => $idm, 'ens' => $ide, 'h' => $h, 'cases' => $cases, 'commun' => $code, 'salle' => $ids,
                     'dmax' => max(1, (int) $v($row, 'dmax') ?: 2), 'groupe' => mb_substr($v($row, 'groupe'), 0, 20), 'ens_nom' => $en, 'lib' => "$cl — $mat — " . ($en ?: 'sans enseignant')];
    }
    // Feuille Enseignants
    $profs = [];
    foreach ($wb->getAllSheets() as $ws) {
        if (edt_norm($ws->getTitle()) !== 'enseignants') continue;
        $rows = edt_feuille_valeurs($ws);
        $h = array_map(fn($x) => edt_norm((string) $x), $rows[0] ?? []);
        $col = fn(string $k) => ($i = array_search($k, $h, true)) === false ? (($i2 = array_keys(array_filter($h, fn($x) => str_starts_with($x, $k)))[0] ?? null) ?? null) : $i;
        $cE = $col('enseignant'); $cD = $col('discipline'); $cG = $col('grade'); $cH = $col('heures dues'); $cT = $col('telephone');
        if ($cE === null) break;
        foreach (array_slice($rows, 1) as $row) {
            $nom = trim((string) ($row[$cE] ?? ''));
            if ($nom === '') continue;
            $id = $trouverEns($nom);
            $hd = $cH !== null ? trim((string) ($row[$cH] ?? '')) : '';
            $tel = $cT !== null ? preg_replace('/[^0-9+ ]/', '', trim((string) ($row[$cT] ?? ''))) : '';
            $sansDoute = fn($v) => trim(rtrim(trim((string) $v), '?'));   // « TCA ? » (lecture incertaine) → « TCA »
            $p = ['ens' => $id, 'nom' => $nom, 'disc' => $cD !== null ? $sansDoute($row[$cD] ?? '') : '', 'grade' => $cG !== null ? $sansDoute($row[$cG] ?? '') : '',
                  'hd' => is_numeric($hd) ? (float) $hd : null, 'tel' => trim($tel)];
            $profs[] = $p;
            if (!$id) $inconnus[edt_norm($nom)] = array_merge($inconnus[edt_norm($nom)] ?? [], $p);
        }
    }
    return ['lignes' => $lignes, 'erreurs' => $erreurs, 'avert' => array_values(array_unique($avert)), 'profs' => $profs,
            'inconnus' => array_values($inconnus),
            'colonnes' => array_keys($map), 'nb_cases' => array_sum(array_map(fn($l) => count($l['cases']), $lignes))];
}

// =====================================================================
//  Enregistrement
// =====================================================================
function edt_import_enregistrer(int $a, array $an, array $opt): array {
    $classesF = array_values(array_unique(array_column($an['lignes'], 'classe')));
    $stat = ['services' => 0, 'seances' => 0, 'refus' => [], 'profs' => 0, 'ens_crees' => []];
    // Enseignants absents de Ressources humaines : fiche créée (nom, téléphone, matière enseignée),
    // pour que l'emploi du temps et le personnel restent une seule et même liste.
    if (!empty($opt['creer_ens'])) {
        $nouveaux = [];
        foreach ($an['inconnus'] ?? [] as $p) {
            $nom = mb_strtoupper(trim(preg_replace('/\s+/', ' ', (string) $p['nom'])));
            if ($nom === '') continue;
            $id = db_val("SELECT matricule_ens FROM enseignant WHERE UPPER(TRIM(CONCAT(nom_ens,' ',IFNULL(prenom_ens,''))))=? LIMIT 1", [$nom]);
            if (!$id) {
                db_exec("INSERT INTO enseignant (nom_ens, tel_ens, matiere_enseignee, id_fonction) VALUES (?,?,?,'ENSEIGNANT')",
                        [$nom, ($p['tel'] ?? '') ?: null, ($p['disc'] ?? '') ?: null]);
                $id = db_last_id();
                $stat['ens_crees'][] = $nom;
            }
            $nouveaux[edt_norm($nom)] = (int) $id;
        }
        foreach ($an['lignes'] as &$l) if (!$l['ens'] && ($l['ens_nom'] ?? '') !== '' && isset($nouveaux[edt_norm($l['ens_nom'])])) $l['ens'] = $nouveaux[edt_norm($l['ens_nom'])];
        unset($l);
        foreach ($an['profs'] as &$p) if (!$p['ens'] && isset($nouveaux[edt_norm($p['nom'] ?? '')])) $p['ens'] = $nouveaux[edt_norm($p['nom'])];
        unset($p);
    }
    if ($opt['services'] && $classesF) db_exec("DELETE FROM edt_service WHERE id_annee=? AND id_classe IN (" . implode(',', $classesF) . ")", [$a]);
    if ($opt['seances'] && $classesF) db_exec("DELETE FROM edt_seance WHERE id_annee=? AND verrou=0 AND id_classe IN (" . implode(',', $classesF) . ")", [$a]);
    // regroupe les lignes identiques (même classe, matière, enseignant, groupe)
    $grp = [];
    foreach ($an['lignes'] as $l) {
        $k = $l['classe'] . '|' . $l['mat'] . '|' . $l['ens'] . '|' . $l['groupe'];
        if (!isset($grp[$k])) $grp[$k] = $l; else { $grp[$k]['h'] += $l['h']; $grp[$k]['cases'] = array_merge($grp[$k]['cases'], $l['cases']); }
    }
    // Occupation des classes tenue en mémoire (séances verrouillées restantes + lignes déjà importées) :
    // une seule requête au lieu d'une par créneau, puis insertions par lots.
    $occ = [];
    $nomMat = [];
    foreach (db_all("SELECT id, TRIM(libelle) l FROM matiere") as $m) $nomMat[(int) $m['id']] = $m['l'];
    foreach (db_all("SELECT id_classe, sous_groupe, jour, id_creneau, id_mat, matricule_ens FROM edt_seance WHERE id_annee=?", [$a]) as $s)
        $occ[$s['id_classe'] . '|' . $s['sous_groupe'] . '|' . $s['jour'] . '|' . $s['id_creneau']] = ['mat' => (int) $s['id_mat'], 'ens' => $s['matricule_ens'] !== null ? (int) $s['matricule_ens'] : null];
    $lot = [];
    $vider = function () use (&$lot) {
        if (!$lot) return;
        db_exec("INSERT INTO edt_seance (id_annee,id_classe,sous_groupe,jour,id_creneau,id_service,id_mat,matricule_ens,id_salle,code_commun,verrou,origine) VALUES "
                . implode(',', array_fill(0, count($lot), "(?,?,?,?,?,?,?,?,?,?,0,'import')")), array_merge(...$lot));
        $lot = [];
    };
    foreach ($grp as $l) {
        $sid = null;
        if ($opt['services']) {
            db_exec("INSERT INTO edt_service (id_annee,id_classe,id_mat,matricule_ens,heures,duree_min,duree_max,id_salle,code_commun) VALUES (?,?,?,?,?,1,?,?,?)",
                    [$a, $l['classe'], $l['mat'], $l['ens'], $l['h'], $l['dmax'], $l['salle'], $l['commun']]);
            $sid = db_last_id(); $stat['services']++;
        }
        if ($opt['seances']) foreach ($l['cases'] as [$j, $cr]) {
            $k = $l['classe'] . '|' . $l['groupe'] . '|' . $j . '|' . $cr;
            if (isset($occ[$k])) {
                // même enseignant au même moment : c'est la même séance sous un autre libellé (ex. Histoire / Géographie)
                if ($occ[$k]['ens'] === null || $occ[$k]['ens'] !== $l['ens'])
                    $stat['refus'][] = $l['lib'] . ' : ' . EDT_JOURS[$j] . ' période déjà occupée par ' . ($nomMat[$occ[$k]['mat']] ?? '?');
                continue;
            }
            $occ[$k] = ['mat' => $l['mat'], 'ens' => $l['ens']];
            $lot[] = [$a, $l['classe'], $l['groupe'], $j, $cr, $sid, $l['mat'], $l['ens'], $l['salle'], $l['commun']];
            $stat['seances']++;
            if (count($lot) >= 200) $vider();
        }
    }
    $vider();
    if ($opt['profs']) foreach ($an['profs'] as $p) {
        if (!$p['ens']) continue;
        db_exec("INSERT INTO edt_enseignant (id_annee,matricule_ens,heures_dues,discipline,grade) VALUES (?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE heures_dues=COALESCE(VALUES(heures_dues),heures_dues), discipline=COALESCE(VALUES(discipline),discipline), grade=COALESCE(VALUES(grade),grade)",
                [$a, $p['ens'], $p['hd'], $p['disc'] ?: null, $p['grade'] ?: null]);
        // Fiche Ressources humaines : complète les champs vides, sans jamais écraser une saisie existante
        db_exec("UPDATE enseignant SET tel_ens=COALESCE(NULLIF(tel_ens,''), ?), matiere_enseignee=COALESCE(NULLIF(matiere_enseignee,''), ?) WHERE matricule_ens=?",
                [($p['tel'] ?? '') ?: null, ($p['disc'] ?? '') ?: null, $p['ens']]);
        $stat['profs']++;
    }
    if ($opt['sync']) edt_synchroniser_dispenser($a);
    return $stat;
}

// ── Actions ──────────────────────────────────────────────────────────
$analyse = null; $nomFichier = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ecrire) {
    csrf_verifier();
    foreach (glob(sys_get_temp_dir() . '/' . $TMP . '*') ?: [] as $f) if (filemtime($f) < time() - 7200) @unlink($f);
    if (post('action') === 'analyser') {
        if (empty($_FILES['fichier']['tmp_name']) || $_FILES['fichier']['error'] !== UPLOAD_ERR_OK) { flash_set('erreur', 'Aucun fichier reçu.'); rediriger('secondaire/commun/pages/emploi_temps/import.php'); }
        $ext = strtolower(pathinfo($_FILES['fichier']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'xls', 'ods', 'csv'], true)) { flash_set('erreur', 'Format non pris en charge (xlsx, xls, ods ou csv).'); rediriger('secondaire/commun/pages/emploi_temps/import.php'); }
        $dest = sys_get_temp_dir() . '/' . $TMP . bin2hex(random_bytes(8)) . '.' . $ext;
        move_uploaded_file($_FILES['fichier']['tmp_name'], $dest);
        try { $analyse = edt_import_analyser($a, $dest); } catch (\Throwable $e) { $analyse = ['erreur' => 'Fichier illisible : ' . $e->getMessage()]; }
        $nomFichier = $_FILES['fichier']['name'];
        if (empty($analyse['erreur'])) $_SESSION['edt_import'] = ['fichier' => $dest, 'nom' => $nomFichier];
        else @unlink($dest);
    }
    if (post('action') === 'valider') {
        $sess = $_SESSION['edt_import'] ?? null;
        if (!$sess || !is_file($sess['fichier']) || !str_starts_with(basename($sess['fichier']), $TMP)) { flash_set('erreur', "Session d'import expirée : déposez à nouveau le fichier."); rediriger('secondaire/commun/pages/emploi_temps/import.php'); }
        @set_time_limit(300);
        $an = edt_import_analyser($a, $sess['fichier']);
        $st = edt_import_enregistrer($a, $an, ['services' => post('services') === '1', 'seances' => post('seances') === '1', 'profs' => post('profs') === '1', 'sync' => post('sync') === '1', 'creer_ens' => post('creer_ens') === '1']);
        @unlink($sess['fichier']); unset($_SESSION['edt_import']);
        $_SESSION['edt_import_rapport'] = $st;
        flash_set('succes', "Import terminé : {$st['services']} service(s), {$st['seances']} période(s) de cours, {$st['profs']} fiche(s) enseignant" . ($st['ens_crees'] ? ", " . count($st['ens_crees']) . " enseignant(s) ajouté(s) dans Ressources humaines : " . implode(', ', $st['ens_crees']) : '') . '.');
        rediriger('secondaire/commun/pages/emploi_temps/import.php');
    }
}
$rapport = $_SESSION['edt_import_rapport'] ?? null; unset($_SESSION['edt_import_rapport']);

$titre_page = 'Emploi du temps — Préparation';
require_once __DIR__ . '/../../../../noyau/layout/header.php';
echo edt_css();
?>
<?= edt_onglets('preparation', 'import') ?>
<?= flash_html() ?>
<?php if ($rapport && $rapport['refus']): ?>
  <div class="alert alert-warning small"><b><?= count($rapport['refus']) ?> créneau(x) non importé(s)</b> (la classe avait déjà un cours à ce moment) :<br><?= h(implode(' ; ', array_slice($rapport['refus'], 0, 40))) ?><?= count($rapport['refus']) > 40 ? ' …' : '' ?></div>
<?php endif; ?>

<?php if ($analyse && empty($analyse['erreur'])): ?>
<div class="card mb-3 border-primary"><div class="card-header"><b>Aperçu de l'import</b> — <?= h($nomFichier) ?></div><div class="card-body">
  <div class="row g-2 mb-3">
    <?php foreach ([['Lignes reconnues', count($analyse['lignes']), 'success'], ['Créneaux (séances)', $analyse['nb_cases'], 'primary'], ['Lignes rejetées', count($analyse['erreurs']), 'danger'], ['Avertissements', count($analyse['avert']), 'warning'], ['Fiches enseignants', count($analyse['profs']), 'info'], ['Enseignants absents des RH', count($analyse['inconnus']), 'secondary']] as [$l, $n, $c]): ?>
      <div class="col"><div class="border rounded p-2 text-center"><div class="small text-muted"><?= $l ?></div><div class="fs-4 fw-bold text-<?= $c ?>"><?= $n ?></div></div></div>
    <?php endforeach; ?>
  </div>
  <div class="small text-muted mb-2">Colonnes reconnues : <?= h(implode(', ', $analyse['colonnes'])) ?></div>
  <?php if ($analyse['erreurs']): ?><details class="mb-2" open><summary class="text-danger fw-bold">Lignes rejetées (non importées)</summary><div class="small"><?= h(implode("\n", $analyse['erreurs'])) ?></div></details><?php endif; ?>
  <?php if ($analyse['avert']): ?><details class="mb-3"><summary class="text-warning fw-bold">Avertissements</summary><div class="small" style="white-space:pre-line"><?= h(implode("\n", array_slice($analyse['avert'], 0, 200))) ?></div></details><?php endif; ?>
  <form method="post"><?= csrf_champ() ?><input type="hidden" name="action" value="valider">
    <div class="form-check"><input class="form-check-input" type="checkbox" name="services" value="1" id="o1" checked><label class="form-check-label" for="o1">Importer les <b>services</b> (remplace les services des classes présentes dans le fichier)</label></div>
    <div class="form-check"><input class="form-check-input" type="checkbox" name="seances" value="1" id="o2" <?= $analyse['nb_cases'] ? 'checked' : '' ?>><label class="form-check-label" for="o2">Importer les <b>créneaux</b> comme séances (remplace les séances non verrouillées de ces classes)</label></div>
    <div class="form-check"><input class="form-check-input" type="checkbox" name="profs" value="1" id="o3" <?= $analyse['profs'] ? 'checked' : '' ?>><label class="form-check-label" for="o3">Importer discipline, grade et heures dues (feuille Enseignants) et compléter les fiches <b>Ressources humaines</b> encore vides (téléphone, matière enseignée)</label></div>
    <?php if ($analyse['inconnus']): ?>
    <div class="form-check"><input class="form-check-input" type="checkbox" name="creer_ens" value="1" id="o5" checked><label class="form-check-label" for="o5">Ajouter dans <b>Ressources humaines</b> les <?= count($analyse['inconnus']) ?> enseignant(s) absent(s) du personnel : <span class="text-muted"><?= h(implode(', ', array_column($analyse['inconnus'], 'nom'))) ?></span> (sinon leurs cours sont importés sans enseignant)</label></div>
    <?php endif; ?>
    <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="sync" value="1" id="o4" checked><label class="form-check-label" for="o4">Mettre à jour les enseignants affectés (module Matières : notes et bulletins)</label></div>
    <button class="btn btn-primary"><i class="bi bi-check2-circle"></i> Valider l'import</button> <a class="btn btn-light" href="import.php">Annuler</a>
  </form>
</div></div>
<?php elseif ($analyse): ?>
  <div class="alert alert-danger"><?= h($analyse['erreur']) ?></div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-6"><div class="card h-100"><div class="card-header"><b><i class="bi bi-upload me-1"></i>Importer un fichier</b></div><div class="card-body">
    <?php if ($ecrire): ?>
    <form method="post" enctype="multipart/form-data"><?= csrf_champ() ?><input type="hidden" name="action" value="analyser">
      <input type="file" class="form-control mb-2" name="fichier" accept=".xlsx,.xls,.ods,.csv" required>
      <button class="btn btn-primary"><i class="bi bi-search"></i> Analyser le fichier</button>
    </form>
    <?php else: ?><p class="text-muted small">Réservé au Censeur, au Proviseur et à l'administrateur.</p><?php endif; ?>
    <p class="small text-muted mt-3 mb-0">Accepté : le <b>modèle</b> ci-contre, ou le <b>récapitulatif</b> « Recap enseignants par classe » (feuille Affectations) tel quel. Rien n'est enregistré avant la validation de l'aperçu.</p>
  </div></div></div>
  <div class="col-lg-6"><div class="card h-100"><div class="card-header"><b><i class="bi bi-download me-1"></i>Modèle à remplir</b></div><div class="card-body">
    <a class="btn btn-outline-primary mb-2" href="import.php?modele=1"><i class="bi bi-file-earmark-arrow-down"></i> Télécharger le modèle vide</a><br>
    <p class="small text-muted mb-3">Pour repartir de l'emploi du temps existant : « Consulter / imprimer » → Exporter Excel (même format), modifier, puis réimporter.</p>
    <table class="table table-sm small mb-0"><thead><tr><th>Colonne</th><th>Contenu</th></tr></thead><tbody>
      <tr><td><b>Classe</b> *</td><td>nom exact de la classe (liste déroulante)</td></tr>
      <tr><td><b>Matière</b> *</td><td>matière du programme de la classe</td></tr>
      <tr><td><b>Enseignant</b></td><td>nom de l'enseignant (liste déroulante)</td></tr>
      <tr><td><b>Heures / sem.</b></td><td>volume hebdomadaire</td></tr>
      <tr><td><b>Créneaux</b></td><td><code>Lun 1-2, Mer 4</code> (jour + n° de période)</td></tr>
      <tr><td>Salle, Cours commun, Durée max, Groupe</td><td>facultatifs</td></tr>
    </tbody></table>
  </div></div></div>
</div>
<?php require_once __DIR__ . '/../../../../noyau/layout/footer.php'; ?>
