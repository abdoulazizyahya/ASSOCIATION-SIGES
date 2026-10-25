<?php
// Remplacement d'un enseignant en cours d'année (secondaire v22) : le
// bulletin de chaque trimestre garde l'enseignant qui l'a assuré.
require __DIR__ . '/_amorce.php';
if (!ecole('LTM')) passer('école LTM absente');
if (!sec_periodes_dispo()) passer('migration secondaire v22 non appliquée');
$va = (string) (get_annee_active()['libelle'] ?? '');
$d = db_one("SELECT IDClasses, id_mat, matricule_ens FROM dispenser WHERE val_annee=? AND matricule_ens IS NOT NULL LIMIT 1", [$va]);
if (!$d) passer('aucune affectation cette année');
$autre = (int) db_val("SELECT matricule_ens FROM enseignant WHERE matricule_ens<>? LIMIT 1", [$d['matricule_ens']]);

test_transaction(function () use ($d, $va, $autre) {
    db_exec("DELETE FROM dispenser_periode WHERE IDClasses=? AND id_mat=? AND val_annee=?", [$d['IDClasses'], $d['id_mat'], $va]);
    sec_affecter_enseignant((int) $d['IDClasses'], (int) $d['id_mat'], $va, $autre, 2);   // remplaçant à partir du 2e trimestre
    $ens = fn(int $ordre) => (int) db_val("SELECT " . sec_sql_enseignant_trimestre($va, $ordre) . " FROM discipline d
                                            JOIN dispenser disp ON disp.IDClasses=d.IDClasses AND disp.id_mat=d.id_mat AND disp.val_annee=?
                                            WHERE d.IDClasses=? AND d.id_mat=?", [$va, $d['IDClasses'], $d['id_mat']]);
    egal((int) $d['matricule_ens'], $ens(1), '1er trimestre : enseignant d\'origine');
    egal($autre, $ens(2), '2e trimestre : remplaçant');
    egal($autre, $ens(3), '3e trimestre : remplaçant');
    // L'affectation « en cours » (dispenser) ne passe au remplaçant qu'à partir de son trimestre
    $courant = sec_ordre_trimestre_courant();
    egal($courant >= 2 ? $autre : (int) $d['matricule_ens'], (int) db_val("SELECT matricule_ens FROM dispenser WHERE IDClasses=? AND id_mat=? AND val_annee=?", [$d['IDClasses'], $d['id_mat'], $va]),
         "affectation en cours = enseignant du trimestre actif (T$courant)");
});
