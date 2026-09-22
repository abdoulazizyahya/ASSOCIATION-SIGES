<?php
/**
 * RELEVE DE NOTES DES EVALUATIONS — export Excel (.xlsx), même contenu et
 * même mise en forme que pdf_releve.php (en-tête bilingue... voir plus
 * bas — en fait français uniquement, comme le PDF), logo, filigrane en
 * fond de page, tableau matières/moyenne/rang/observations. Voir
 * excel_releve_annuel.php pour la version annuelle.
 * GET : classe, trim|seq, annee
 */
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
require_once __DIR__ . '/../../../vendor/autoload.php';
exiger_connexion();

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

$role     = role_connecte();
$is_admin = in_array($role, ['ADMIN', 'PROVISEUR', 'FONDATEUR', 'CENSEUR']) || $role === 'MEMBRE_ASSOCIATION';
$is_ens   = ($role === 'ENSEIGNANT');
$mat_ens  = $is_ens ? get_matricule_ens_connecte() : null;
if (!$is_admin && !$is_ens) die('Acces non autorise.');

$id_classe = (int)($_GET['classe'] ?? 0);
$id_trim   = (int)($_GET['trim']   ?? 0);
$id_seq    = (int)($_GET['seq']    ?? 0);
$id_annee  = (int)($_GET['annee']  ?? 0);
$ordre     = in_array($_GET['ordre'] ?? '', ['alpha', 'merite']) ? $_GET['ordre'] : 'alpha';

if (!$id_classe) die('Classe manquante.');
if (!$id_trim && !$id_seq) die('Periode manquante.');

$annee_act = get_annee_active();
if (!$id_annee) $id_annee = (int)($annee_act['id'] ?? 0);
$val_annee = $annee_act['libelle'] ?? '';

if ($is_ens && $mat_ens) {
    $ok = db_val(
        "SELECT COUNT(*) FROM enseignat_principal WHERE matricule_ens=? AND IDClasses=? AND val_annee=?",
        [$mat_ens, $id_classe, $val_annee]
    );
    if (!$ok) die('Acces refuse.');
}

$classe = db_one("SELECT * FROM classe WHERE id=?", [$id_classe]);
if (!$classe) die('Classe introuvable.');

// Les compétences (chantier APC) sont scopées par trimestre entier, sans
// sous-division par séquence — ?seq= n'est conservé que pour résoudre un
// ancien lien vers son trimestre (voir prompt_continuite, 07/08/2026).
if ($id_seq && !$id_trim) {
    $seq_info = db_one("SELECT s.*, t.id AS id_trim, t.libelle AS trimestre FROM sequence s JOIN trimestre t ON t.id=s.id_trim WHERE s.id=?", [$id_seq]);
    if (!$seq_info) die('Sequence introuvable.');
    $id_trim = (int)$seq_info['id_trim'];
}
$trim = db_one("SELECT * FROM trimestre WHERE id=?", [$id_trim]);
if (!$trim) die('Trimestre introuvable.');
$titre_periode = 'TRIMESTRE : ' . strtoupper($trim['libelle']);

$etab = get_etablissement();

$eleves = db_all(
    "SELECT e.id, e.matricule, e.nom, e.prenom FROM eleve e
     JOIN inscription i ON i.id_eleve=e.id AND i.id_classe=? AND i.id_annee=?
     WHERE e.statut='actif'",
    [$id_classe, $id_annee]
);
$nb_inscrits = count($eleves);

$disciplines = db_all(
    "SELECT d.id_mat, d.coef, d.ordre, m.libelle AS matiere
     FROM discipline d JOIN matiere m ON m.id=d.id_mat AND m.actif=1
     WHERE d.IDClasses=? ORDER BY d.id_groupe, d.ordre, m.libelle",
    [$id_classe]
);
$nb_mat = count($disciplines);

$dcomp = pv_charger_donnees_comp($id_classe, $id_trim, $id_annee);

$moys = [];
$moys_mat = [];
foreach ($eleves as $el) {
    $eid = (int)$el['id'];
    [$m, $classable] = pv_moy_generale_comp(
        $eid, $dcomp['disciplines'], $dcomp['competences_par_mat'], $dcomp['notes_idx'], $dcomp['notes_count'],
        $dcomp['nb_inscrits'], $dcomp['mats_avec_notes'], $dcomp['nb_mats_avec_notes']
    );
    if ($classable && $m !== null) $moys[$eid] = $m;
    foreach ($disciplines as $d) {
        $moys_mat[$eid][$d['id_mat']] = pv_moy_matiere_comp(
            $eid, $dcomp['competences_par_mat'][$d['id_mat']] ?? [], $dcomp['notes_idx'], $dcomp['notes_count'], $dcomp['nb_inscrits']
        );
    }
}
arsort($moys);
$nb_classes_ = count($moys);
$rangs = []; $rg = 1;
foreach ($moys as $eid => $m) { $rangs[$eid] = $rg++; }

$decisions_idx = [];
$rows = db_all("SELECT * FROM decision_conseil WHERE id_classe=? AND id_annee=? AND type='trimestre' AND id_trim=?", [$id_classe, $id_annee, $id_trim]);
foreach ($rows as $r) $decisions_idx[(int)$r['id_eleve']] = $r;

if ($ordre === 'merite') {
    usort($eleves, function ($a, $b) use ($moys) {
        $ma = $moys[(int)$a['id']] ?? null; $mb = $moys[(int)$b['id']] ?? null;
        if ($ma === null && $mb === null) return 0;
        if ($ma === null) return 1;
        if ($mb === null) return -1;
        return $mb <=> $ma;
    });
} else {
    usort($eleves, fn($a, $b) => strcmp($a['nom'] . ' ' . ($a['prenom'] ?? ''), $b['nom'] . ' ' . ($b['prenom'] ?? '')));
}

function fmt2(?float $v): string {
    if ($v === null) return '';
    $s = number_format($v, 2, '.', '');
    [$i, $d] = explode('.', $s);
    return str_pad($i, 2, '0', STR_PAD_LEFT) . '.' . $d;
}

require_once __DIR__ . '/excel_releve_commun.php';
genererExcelReleve($etab, $classe, $titre_periode, $val_annee, $disciplines, $eleves, $moys, $moys_mat, $rangs, $nb_classes_, $decisions_idx, 'releve_' . preg_replace('/\W+/', '_', $classe['designation'] ?? 'classe'));
