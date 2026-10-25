<?php
// Emploi du temps : cours commun proposé pour une classe du même niveau, même
// matière, même enseignant ; jamais pour une autre matière ; heures communes
// comptées une fois. Données : lycée technique de Mbé (LTM) du poste local.
require __DIR__ . '/_amorce.php';
if (!ecole('LTM')) passer('école LTM absente');
require_once 'secondaire/commun/pages/emploi_temps/_edt_lib.php';
$a = edt_annee_id();

// Une séance placée d'un enseignant, et une autre classe du même niveau qui a la même matière au programme
$q = db_one("SELECT se.id, se.id_classe, se.jour, se.id_creneau, se.id_mat, se.matricule_ens, c.code_niveau
             FROM edt_seance se JOIN classe c ON c.id=se.id_classe
             WHERE se.id_annee=? AND se.matricule_ens IS NOT NULL AND se.code_commun IS NULL
               AND EXISTS (SELECT 1 FROM classe c2 JOIN discipline d ON d.IDClasses=c2.id AND d.id_mat=se.id_mat
                           WHERE c2.code_niveau=c.code_niveau AND c2.id<>c.id AND c2.archivee=0
                             AND NOT EXISTS (SELECT 1 FROM edt_seance x WHERE x.id_annee=se.id_annee AND x.id_classe=c2.id AND x.jour=se.jour AND x.id_creneau=se.id_creneau))
             LIMIT 1", [$a]);
if (!$q) passer('aucune séance exploitable');
$autre = (int) db_val("SELECT c2.id FROM classe c2 JOIN discipline d ON d.IDClasses=c2.id AND d.id_mat=?
                       WHERE c2.code_niveau=? AND c2.id<>? AND c2.archivee=0
                         AND NOT EXISTS (SELECT 1 FROM edt_seance x WHERE x.id_annee=? AND x.id_classe=c2.id AND x.jour=? AND x.id_creneau=?) LIMIT 1",
                      [$q['id_mat'], $q['code_niveau'], $q['id_classe'], $a, $q['jour'], $q['id_creneau']]);

test_transaction(function () use ($a, $q, $autre) {
    $mod = new EdtModele($a);
    $b = ['id_classe' => $autre, 'id_mat' => (int) $q['id_mat'], 'matricule_ens' => (int) $q['matricule_ens'], 'jour' => (int) $q['jour'], 'creneaux' => [(int) $q['id_creneau']], 'code_commun' => null];
    $ens = array_values(array_filter($mod->problemes($b), fn($p) => $p['type'] === 'enseignant'));
    ok($ens && !empty($ens[0]['commun_possible']), 'enseignant occupé, même cours même niveau : cours commun proposé');

    $autreMat = (int) db_val("SELECT id_mat FROM discipline WHERE IDClasses=? AND id_mat<>? LIMIT 1", [$autre, $q['id_mat']]);
    $ens2 = array_values(array_filter($mod->problemes(['id_mat' => $autreMat] + $b), fn($p) => $p['type'] === 'enseignant'));
    ok($ens2 && empty($ens2[0]['commun_possible']), 'autre matière : vrai conflit, pas de cours commun');

    // On rend l'heure commune comme le fait la saisie
    $code = 'TEST' . mt_rand(1000, 9999);
    db_exec("UPDATE edt_seance SET code_commun=? WHERE id=?", [$code, $q['id']]);
    $mod = new EdtModele($a);
    $dur = array_filter($mod->problemes(['code_commun' => $code] + $b), fn($p) => $p['niveau'] !== 'souhaitee' && in_array($p['type'], ['enseignant', 'classe'], true));
    ok(!$dur, 'avec le code commun : plus de conflit enseignant ni classe');

    // Heures : posée dans la grille, l'heure commune n'est comptée qu'une fois
    $avant = edt_charges_enseignants($a)[(int) $q['matricule_ens']]['attribuees'] ?? 0;
    db_exec("INSERT INTO edt_service (id_annee,id_classe,id_mat,matricule_ens,heures,duree_min,duree_max) VALUES (?,?,?,?,1,1,2)", [$a, $autre, $q['id_mat'], $q['matricule_ens']]);
    $sid = (int) db_val("SELECT LAST_INSERT_ID()");
    if (!db_val("SELECT id_service FROM edt_seance WHERE id=?", [$q['id']])) passer('séance sans service');
    db_exec("INSERT INTO edt_seance (id_annee,id_classe,sous_groupe,jour,id_creneau,id_service,id_mat,matricule_ens,code_commun,verrou,origine) VALUES (?,?,'',?,?,?,?,?,?,0,'manuel')",
            [$a, $autre, $q['jour'], $q['id_creneau'], $sid, $q['id_mat'], $q['matricule_ens'], $code]);
    $apres = edt_charges_enseignants($a)[(int) $q['matricule_ens']]['attribuees'] ?? 0;
    egal((float) $avant, (float) $apres, 'heure commune ajoutée dans une 2e classe : charge de l\'enseignant inchangée');
});
