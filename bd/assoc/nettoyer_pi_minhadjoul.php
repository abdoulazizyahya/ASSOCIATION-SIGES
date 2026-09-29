<?php
// =====================================================================
//  bd/assoc/nettoyer_pi_minhadjoul.php
//  Retire de la base « GSB PI MINHADJOUL MOUSLIM » (MHM1) les données qui
//  appartiennent en réalité à deux écoles désormais séparées et déjà
//  copiées dans leur propre base :
//    - GSBPI MINHADJOUL MOUSLIM ANNEXE (MHMAN) : classes CP C, CE1 C,
//      CE2 C, CM1 C, CM2 C, SIL C ;
//    - COLLEGE MINHADJOUL MOUSLIM (MHJ2) : classes 6ème, 5ème, 4ème.
//  Supprime les élèves de ces classes + inscriptions, paiements, notes,
//  absences… puis les classes et leurs disciplines.
//  Équivalent de bd/nettoyage_pi_minhadjoul_retirer_annexe_college.sql, pour
//  l'hébergeur sans phpMyAdmin.
//
//  GARDE-FOUS (rien n'est supprimé si l'un échoue) :
//    - chaque élève à retirer doit exister dans la base de son école
//      (annexe : même id ; collège : même matricule) ;
//    - les paiements à retirer doivent déjà être présents dans la base
//      de l'annexe (mêmes id_pay) ; pour le collège, sa base doit
//      contenir au moins autant de paiements que ceux à retirer ;
//    - aucun élève à retirer n'est inscrit dans une autre classe ;
//    - tout se passe dans UNE transaction, annulée si un contrôle final
//      n'est pas à zéro.
//
//  Sécurité : en HTTP, jeton obligatoire ?token=<BACKUP_TOKEN>.
//  Usage :
//    /bd/assoc/nettoyer_pi_minhadjoul.php?token=XXX          (aperçu, rien n'est modifié)
//    /bd/assoc/nettoyer_pi_minhadjoul.php?token=XXX&go=1     (applique)
//    php bd/assoc/nettoyer_pi_minhadjoul.php [--go]
//  Faire une sauvegarde de la base MHM1 avant (portail association).
//  À SUPPRIMER après usage.
// =====================================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion_assoc.php';

$est_cli = (PHP_SAPI === 'cli');
if (!$est_cli) header('Content-Type: text/plain; charset=utf-8');
if (!$est_cli) {
    $token = (string) ($_GET['token'] ?? '');
    if (!defined('BACKUP_TOKEN') || BACKUP_TOKEN === '' || !hash_equals(BACKUP_TOKEN, $token)) {
        http_response_code(403);
        die("Accès refusé : jeton invalide (BACKUP_TOKEN de config.local.php).\n");
    }
}
$go = $est_cli ? in_array('--go', $argv ?? [], true) : !empty($_GET['go']);
if (!annuaire_dispo()) die("✗ Annuaire indisponible.\n");

const CLASSES_ANNEXE  = ['CP C', 'CE1 C', 'CE2 C', 'CM1 C', 'CM2 C', 'SIL C'];
const CLASSES_COLLEGE = ['6ème', '5ème', '4ème'];

function ouvrir(string $code): mysqli {
    $r = assoc_one("SELECT db_name FROM etablissement WHERE code=?", [$code]);
    if (!$r) die("✗ Établissement $code introuvable dans l'annuaire.\n");
    $l = @mysqli_connect(DB_HOST, DB_USER, DB_PASS, $r['db_name']);
    if (!$l) die("✗ Connexion impossible à {$r['db_name']} : " . mysqli_connect_error() . "\n");
    mysqli_set_charset($l, 'utf8mb4');
    mysqli_report(MYSQLI_REPORT_OFF);
    echo "  $code → {$r['db_name']}\n";
    return $l;
}
function col(mysqli $l, string $sql): array {
    $r = mysqli_query($l, $sql);
    if (!$r) die("✗ Requête échouée : " . mysqli_error($l) . "\n  $sql\n");
    return array_map(fn($x) => $x[0], mysqli_fetch_all($r));
}
function un(mysqli $l, string $sql) { return col($l, $sql)[0] ?? null; }
function ids(array $a): string { return $a ? implode(',', array_map('intval', $a)) : '0'; }
function norm(string $s): string { return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s)), 'UTF-8'); }

echo "=== Nettoyage de la base PI Minhadjoul (" . ($go ? "APPLIQUE" : "APERÇU — rien n'est modifié") . ") ===\n";
$pi  = ouvrir('MHM1');
$ann = ouvrir('MHMAN');
$col = ouvrir('MHJ2');

// ── 1. Classes à retirer, repérées par leur nom ─────────────────────
$rows = mysqli_fetch_all(mysqli_query($pi, "SELECT IDClasses, DesignationClasses FROM classe"), MYSQLI_ASSOC);
$cl_ann = $cl_col = [];
foreach ($rows as $c) {
    $d = norm($c['DesignationClasses']);
    if (in_array($d, array_map('norm', CLASSES_ANNEXE), true))  $cl_ann[] = (int) $c['IDClasses'];
    if (in_array($d, array_map('norm', CLASSES_COLLEGE), true)) $cl_col[] = (int) $c['IDClasses'];
}
// Le nom « 6ème » peut être mal encodé selon l'import : repli sur le niveau.
if (count($cl_col) < 3) {
    foreach (mysqli_fetch_all(mysqli_query($pi, "SELECT IDClasses FROM classe WHERE Niveau IN ('6eme','5eme','4eme')"), MYSQLI_ASSOC) as $c)
        $cl_col[] = (int) $c['IDClasses'];
    $cl_col = array_values(array_unique($cl_col));
}
echo "\nClasses annexe : " . ids($cl_ann) . " (attendu 6) · collège : " . ids($cl_col) . " (attendu 3)\n";
if (count($cl_ann) !== 6 || count($cl_col) !== 3) die("✗ Classes non reconnues comme prévu — abandon, rien modifié.\n");
$cl_all = array_merge($cl_ann, $cl_col);

// ── 2. Élèves concernés ─────────────────────────────────────────────
$el_ann = col($pi, "SELECT DISTINCT id_eleve FROM inscrire WHERE IDClasses IN (" . ids($cl_ann) . ")");
$el_col = col($pi, "SELECT DISTINCT id_eleve FROM inscrire WHERE IDClasses IN (" . ids($cl_col) . ")");
$el_all = array_merge($el_ann, $el_col);
$partages = un($pi, "SELECT COUNT(DISTINCT id_eleve) FROM inscrire WHERE id_eleve IN (" . ids($el_all) . ") AND IDClasses NOT IN (" . ids($cl_all) . ")");
$nb_pay_ann = (int) un($pi, "SELECT COUNT(*) FROM paiement_frais WHERE id_eleve IN (" . ids($el_ann) . ")");
$nb_pay_col = (int) un($pi, "SELECT COUNT(*) FROM paiement_frais WHERE id_eleve IN (" . ids($el_col) . ")");
echo "Élèves annexe : " . count($el_ann) . " · collège : " . count($el_col) . " · paiements annexe : $nb_pay_ann · collège : $nb_pay_col\n";
echo "Élèves aussi inscrits ailleurs : $partages\n";
if ((int) $partages !== 0) die("✗ Des élèves sont inscrits dans d'autres classes — abandon, rien modifié.\n");

// ── 3. Ces données existent-elles bien dans les bases des 2 écoles ? ─
$pb = 0;
$ids_ann = array_flip(col($ann, "SELECT id_eleve FROM eleve"));
$manq = array_filter($el_ann, fn($i) => !isset($ids_ann[$i]));
echo "Élèves annexe absents de la base annexe : " . count($manq) . "\n";
$pb += count($manq);

$mat_col = array_flip(array_map('norm', col($col, "SELECT matricule FROM eleve")));
$mat_pi  = col($pi, "SELECT Mat_elv FROM eleve WHERE id_eleve IN (" . ids($el_col) . ")");
$manq = array_filter($mat_pi, fn($m) => !isset($mat_col[norm((string) $m)]));
echo "Élèves collège absents de la base collège : " . count($manq) . "\n";
$pb += count($manq);

$pay_pi  = col($pi, "SELECT id_pay FROM paiement_frais WHERE id_eleve IN (" . ids($el_ann) . ")");
$pay_ann = array_flip(col($ann, "SELECT id_pay FROM paiement_frais"));
$manq = array_filter($pay_pi, fn($i) => !isset($pay_ann[$i]));
echo "Paiements annexe absents de la base annexe : " . count($manq) . "\n";
$pb += count($manq);

// Collège (privé) : ses paiements sont dans `paiement_prive` (pas `paiement_frais`).
// Comparaison paiement par paiement : matricule élève + montant + date, en
// tenant compte des doublons éventuels (multiensemble).
$cle = fn($m, $mt, $d) => norm((string) $m) . '|' . number_format((float) $mt, 2, '.', '') . '|' . $d;
$dispo = [];
$r = mysqli_query($col, "SELECT e.matricule, p.montant_paiement, p.date_paiement
                         FROM paiement_prive p JOIN eleve e ON e.id = p.id_eleve");
if (!$r) { echo "  ✗ paiement_prive illisible : " . mysqli_error($col) . "\n"; $pb++; }
else foreach (mysqli_fetch_all($r) as $x) { $k = $cle(...$x); $dispo[$k] = ($dispo[$k] ?? 0) + 1; }
$manq = 0;
$r = mysqli_query($pi, "SELECT e.Mat_elv, p.montant_paiement, p.date_paiement
                        FROM paiement_frais p JOIN eleve e ON e.id_eleve = p.id_eleve
                        WHERE p.id_eleve IN (" . ids($el_col) . ")");
foreach (mysqli_fetch_all($r) as $x) {
    $k = $cle(...$x);
    if (($dispo[$k] ?? 0) > 0) $dispo[$k]--; else $manq++;
}
echo "Paiements collège (PI) absents de paiement_prive du collège : $manq (sur $nb_pay_col)\n";
$pb += $manq;

if ($pb > 0) die("\n✗ $pb anomalie(s) — abandon, RIEN n'a été modifié. Les données ne sont pas toutes copiées.\n");
echo "\n✓ Tous les contrôles sont bons.\n";

if (!$go) {
    echo "\nAperçu terminé. Ajoutez &go=1 (ou --go) pour supprimer.\n";
    exit;
}

// ── 4. Suppression, en une transaction ──────────────────────────────
$E = ids($el_all);
$C = ids($cl_all);
$sup = [
    "paiement_frais" => "id_eleve IN ($E)", "absence" => "id_eleve IN ($E)",
    "absence_justifiee" => "id_eleve IN ($E)", "absence_justifiee_arabe" => "id_eleve IN ($E)",
    "composer_sequence" => "id_eleve IN ($E)", "composer_sequence_arabe" => "id_eleve IN ($E)",
    "decision_conseil" => "id_eleve IN ($E)", "decision_conseil_annuel" => "id_eleve IN ($E)",
    "decision_conseil_annuel_arabe" => "id_eleve IN ($E)", "decision_conseil_arabe" => "id_eleve IN ($E)",
    "dossier_eleve" => "id_eleve IN ($E)", "evaluation_annulee" => "id_eleve IN ($E)",
    "exclusion" => "id_eleve IN ($E)", "info_supplementaires" => "id_eleve IN ($E)",
    "moyenne_annuelle" => "id_eleve IN ($E)", "moyenne_annuelle_arabe" => "id_eleve IN ($E)",
    "moyenne_sequence_arabe" => "id_eleve IN ($E)", "moyenne_trimestre" => "id_eleve IN ($E)",
    "moyenne_trimestre_arabe" => "id_eleve IN ($E)", "parent" => "id_eleve IN ($E)",
    "inscrire" => "id_eleve IN ($E)", "eleve" => "id_eleve IN ($E)",
    "discipline" => "IDClasses IN ($C)", "discipline_arabe" => "IDClasses IN ($C)",
    "dispenser" => "IDClasses IN ($C)", "enseignat_classe" => "IDClasses IN ($C)",
    "enseignat_classe_arabe" => "IDClasses IN ($C)", "critere_conseil" => "id_classe IN ($C)",
    "critere_conseil_arabe" => "id_classe IN ($C)", "classe" => "IDClasses IN ($C)",
];
$avant = [
    'eleves' => (int) un($pi, "SELECT COUNT(*) FROM eleve"),
    'paiements' => (int) un($pi, "SELECT COUNT(*) FROM paiement_frais"),
    'classes' => (int) un($pi, "SELECT COUNT(*) FROM classe"),
];
mysqli_begin_transaction($pi);
$ok = true;
foreach ($sup as $table => $where) {
    if (!mysqli_query($pi, "DELETE FROM `$table` WHERE $where")) {
        echo "✗ $table : " . mysqli_error($pi) . "\n"; $ok = false; break;
    }
    if (mysqli_affected_rows($pi) > 0) echo sprintf("  %-32s %d supprimé(s)\n", $table, mysqli_affected_rows($pi));
}
if ($ok) {
    $reste = (int) un($pi, "SELECT (SELECT COUNT(*) FROM eleve WHERE id_eleve IN ($E))
                                + (SELECT COUNT(*) FROM inscrire WHERE id_eleve IN ($E))
                                + (SELECT COUNT(*) FROM paiement_frais WHERE id_eleve IN ($E))
                                + (SELECT COUNT(*) FROM classe WHERE IDClasses IN ($C))");
    if ($reste !== 0) { echo "✗ Contrôle final : $reste ligne(s) restante(s).\n"; $ok = false; }
}
if (!$ok) {
    mysqli_rollback($pi);
    die("\n✗ ANNULÉ (rollback) — rien n'a été modifié.\n");
}
mysqli_commit($pi);
echo "\n✓ TERMINÉ (validé).\n";
echo "Élèves : {$avant['eleves']} → " . un($pi, "SELECT COUNT(*) FROM eleve")
   . " · Paiements : {$avant['paiements']} → " . un($pi, "SELECT COUNT(*) FROM paiement_frais")
   . " · Classes : {$avant['classes']} → " . un($pi, "SELECT COUNT(*) FROM classe") . "\n";
echo "Pensez à SUPPRIMER ce fichier du serveur.\n";
