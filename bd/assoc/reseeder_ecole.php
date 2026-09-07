<?php
// =====================================================================
//  bd/assoc/reseeder_ecole.php
//  (Re)charge les DONNÉES DE RÉFÉRENCE (bd/assoc/seed_ref_ecole.sql) dans
//  la base d'une école, ou de toutes les écoles de l'annuaire :
//    - niveaux, groupes de compétences / compétences (Fr + An),
//      affectation des groupes aux niveaux ;
//    - disciplines / matières arabes, critères de conseil ;
//    - géographie (pays / région / département / arrondissement) ;
//    - grades enseignants, questions secrètes, couleurs PDF, catégories
//      de dépense ;
//    - table `bareme_reference` (gabarit de barème APC par niveau).
//
//  Idempotent (le fichier fait DELETE puis INSERT sur ces tables).
//  N'affecte NI les élèves, NI les classes, NI les notes, NI les années.
//
//  Options :
//   --bareme   applique le gabarit `bareme_reference` à la table de travail
//              `discipline` de l'année active (lignes manquantes seulement).
//   --classes  PROVISIONNE l'école comme une école neuve : 8 classes
//              standard + année scolaire courante active (3 trimestres,
//              UA1-UA6) + barème de travail dérivé. Idempotent : ne crée
//              rien si des classes / cette année existent déjà. Utile pour
//              une école inscrite mais jamais configurée.
//   --sans-backup  saute la sauvegarde de sécurité.
//
//  Une sauvegarde de sécurité de chaque base est écrite dans bd/sauvegardes/
//  avant l'opération (désactivable avec --sans-backup).
//
//  ⚠ Les blocs DELETE + INSERT du seed supposent que ces tables sont
//  RÉFÉRENTIELLES (contenu standard, non personnalisé par l'école). Si une
//  école a ajouté ses propres niveaux / compétences, ils seront écrasés par
//  le jeu de référence.
//
//  Usage :
//    php bd/assoc/reseeder_ecole.php <CODE|--tout> [--bareme] [--classes] [--sans-backup]
// =====================================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion_assoc.php';
require_once __DIR__ . '/../lib/ecole_maintenance.php';

if (PHP_SAPI !== 'cli') { header('Content-Type: text/plain; charset=utf-8'); }
if (!annuaire_dispo()) { fwrite(STDERR, "Annuaire absent.\n"); exit(1); }

$a          = $argv ?? [];
$cible      = $a[1] ?? '';
$bareme     = in_array('--bareme', $a, true);
$classes    = in_array('--classes', $a, true);
$sans_bkp   = in_array('--sans-backup', $a, true);

if ($cible === '') {
    fwrite(STDERR, "Usage : php bd/assoc/reseeder_ecole.php <CODE|--tout> [--bareme]\n");
    exit(2);
}

$seed = @file_get_contents(__DIR__ . '/seed_ref_ecole.sql');
if ($seed === false || trim($seed) === '') {
    fwrite(STDERR, "seed_ref_ecole.sql introuvable ou vide.\n"); exit(1);
}

$ecoles = ($cible === '--tout')
    ? assoc_all("SELECT * FROM etablissement ORDER BY id")
    : assoc_all("SELECT * FROM etablissement WHERE code=?", [strtoupper($cible)]);

if (!$ecoles) { fwrite(STDERR, "Aucune école pour « $cible ».\n"); exit(1); }

$echecs = 0;
foreach ($ecoles as $e) {
    echo "=== {$e['code']} — {$e['nom']} ({$e['db_name']}) ===\n";
    try {
        $l = mysqli_connect(DB_HOST, DB_USER, DB_PASS, $e['db_name']);
        mysqli_set_charset($l, 'utf8mb4');
    } catch (\Throwable $ex) {
        echo "  base injoignable — ignorée\n"; $echecs++; continue;
    }

    // Sauvegarde de sécurité (sauf --sans-backup) — les blocs DELETE/INSERT
    // du seed touchent des tables référencées par des FK ; en cas de pépin
    // on veut pouvoir revenir en arrière.
    if (!$sans_bkp) {
        try {
            $bkp = ecole_maint_backup_securite($e['db_name'], 'avant_reseed');
            echo "  sauvegarde : " . basename($bkp) . "\n";
        } catch (\Throwable $ex) {
            echo "  ERREUR sauvegarde ({$ex->getMessage()}) — école ignorée\n";
            $echecs++; mysqli_close($l); continue;
        }
    }

    if (mysqli_multi_query($l, $seed)) {
        do { /* consommer */ } while (mysqli_next_result($l));
    }
    if (mysqli_errno($l)) {
        echo "  ERREUR : " . mysqli_error($l) . "\n"; $echecs++; mysqli_close($l); continue;
    }

    $stats = [];
    foreach (['niveau', 'groupe_competence', 'competence', 'groupe_competence_niveau',
              'arrondissement', 'matiere_niveau_arabe', 'bareme_reference'] as $t) {
        $r = mysqli_query($l, "SELECT COUNT(*) FROM `$t`");
        $stats[] = "$t=" . ($r ? mysqli_fetch_row($r)[0] : '?');
    }
    echo "  référence OK — " . implode(', ', $stats) . "\n";

    if ($classes) {
        $p = provisionner_ecole_neuve($l);
        echo "  provisionnement : {$p['classes']} classe(s), "
           . ($p['sequences'] ? "année {$p['annee']} active (+6 UA), " : "année déjà présente, ")
           . "{$p['bareme']} ligne(s) barème\n";
    }

    if ($bareme) {
        $va = mysqli_fetch_row(mysqli_query($l,
            "SELECT val_annee FROM annee_scolaire WHERE Etat_annee_scolaire=1 LIMIT 1"));
        if ($va) {
            $vaSafe = mysqli_real_escape_string($l, $va[0]);
            mysqli_query($l,
                "INSERT INTO discipline (IDClasses, id_comp, annee_scol, orale, ecrite, pratique, savoir_etre, total_points, actif)
                 SELECT c.IDClasses, b.id_comp, '$vaSafe', b.orale, b.ecrite, b.pratique, b.savoir_etre, b.total_points, b.actif
                 FROM bareme_reference b JOIN classe c ON c.Niveau = b.code_niveau
                 WHERE NOT EXISTS (SELECT 1 FROM discipline d
                     WHERE d.IDClasses=c.IDClasses AND d.id_comp=b.id_comp AND d.annee_scol='$vaSafe')");
            echo "  barème {$va[0]} : " . mysqli_affected_rows($l) . " ligne(s) discipline créée(s)\n";
        } else {
            echo "  --bareme ignoré : aucune année scolaire active\n";
        }
    }
    mysqli_close($l);
}

echo "\n=== Terminé" . ($echecs ? " ($echecs échec/s)" : '') . ". ===\n";
exit($echecs ? 1 : 0);
