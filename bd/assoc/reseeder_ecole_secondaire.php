<?php
// =====================================================================
//  bd/assoc/reseeder_ecole_secondaire.php
//  Complète une école SECONDAIRE avec les données de référence qui doivent
//  exister avant tout élève / classe / année :
//    - seed_ref_ecole_secondaire.sql : niveaux, sections, groupes, séries,
//      géographie (régions / départements / arrondissements), matières,
//      questions secrètes, formats de carte, grades, couleurs PDF…
//    - seed_competences_secondaire.sql : compétences par matière / niveau /
//      trimestre, appliquées à chaque année scolaire de l'école qui n'en a
//      aucune.
//
//  INSERT seulement (INSERT IGNORE / NOT EXISTS) : rien n'est supprimé ni
//  écrasé, aucun élève / classe / note n'est touché. Rejouable sans risque.
//  (Ne PAS utiliser reseeder_ecole.php ici : il est propre au primaire et
//  fait DELETE puis INSERT.)
//
//  Usage : php bd/assoc/reseeder_ecole_secondaire.php <nom_de_base>
//          ex. php bd/assoc/reseeder_ecole_secondaire.php promeducam_minhadjoul_mouslim
// =====================================================================

require_once __DIR__ . '/../../config.php';

if (PHP_SAPI !== 'cli') { header('Content-Type: text/plain; charset=utf-8'); }
$db = $argv[1] ?? '';
if (!preg_match('/^[A-Za-z0-9_]+$/', $db)) {
    fwrite(STDERR, "Usage : php bd/assoc/reseeder_ecole_secondaire.php <nom_de_base>\n");
    exit(2);
}

$l = mysqli_connect(DB_HOST, DB_USER, DB_PASS, $db);
if (!$l) { fwrite(STDERR, "Connexion impossible à $db.\n"); exit(1); }
mysqli_set_charset($l, 'utf8mb4');

$seed = file_get_contents(__DIR__ . '/seed_ref_ecole_secondaire.sql');
if (mysqli_multi_query($l, $seed)) {
    do { if ($r = mysqli_store_result($l)) mysqli_free_result($r); } while (mysqli_more_results($l) && mysqli_next_result($l));
}
if (mysqli_errno($l)) { fwrite(STDERR, "Seed de référence : " . mysqli_error($l) . "\n"); exit(1); }
echo "Données de référence chargées.\n";

$tpl = file_get_contents(__DIR__ . '/seed_competences_secondaire.sql');
$annees = mysqli_query($l, "SELECT id, libelle FROM annee_scolaire ORDER BY id");
while ($a = mysqli_fetch_assoc($annees)) {
    $id = (int) $a['id'];
    $nb_trim = (int) mysqli_fetch_row(mysqli_query($l, "SELECT COUNT(*) FROM trimestre WHERE id_annee=$id"))[0];
    $nb_comp = (int) mysqli_fetch_row(mysqli_query($l,
        "SELECT COUNT(*) FROM competence c JOIN trimestre t ON t.id=c.id_trim WHERE t.id_annee=$id"))[0];
    if ($nb_trim === 0 || $nb_comp > 0) {
        echo "Année {$a['libelle']} : ignorée (" . ($nb_trim === 0 ? 'sans trimestres' : "$nb_comp compétences déjà présentes") . ").\n";
        continue;
    }
    mysqli_query($l, str_replace(':ID_ANNEE:', (string) $id, $tpl));
    if (mysqli_errno($l)) { fwrite(STDERR, "Compétences : " . mysqli_error($l) . "\n"); exit(1); }
    echo "Année {$a['libelle']} : " . mysqli_affected_rows($l) . " compétences ajoutées.\n";
}
