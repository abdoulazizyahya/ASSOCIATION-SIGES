<?php
// L'enseignant remplaçant est prévenu sur son tableau de bord (secondaire).
require __DIR__ . '/_amorce.php';
if (!ecole('LTM', 'ENSEIGNANT')) passer('école LTM absente');
if (!sec_periodes_dispo()) passer('migration secondaire v22 non appliquée');
$moi = (int) matricule_ens_courant();
if (!$moi) passer('aucun compte enseignant relié à une fiche');
$va = (string) get_annee_active()['val_annee'];
$d = db_one("SELECT IDClasses, id_mat, matricule_ens FROM dispenser WHERE val_annee=? AND matricule_ens IS NOT NULL AND matricule_ens<>? LIMIT 1", [$va, $moi]);
if (!$d) passer('aucune affectation d\'un collègue');

test_transaction(function () use ($d, $va, $moi) {
    egal('', avis_remplacements_html(), 'sans remplacement : aucun avis');
    db_exec("DELETE FROM dispenser_periode WHERE IDClasses=? AND id_mat=? AND val_annee=?", [$d['IDClasses'], $d['id_mat'], $va]);
    sec_affecter_enseignant((int) $d['IDClasses'], (int) $d['id_mat'], $va, $moi, 2);
    $html = avis_remplacements_html();
    $ancien = (string) db_val("SELECT TRIM(CONCAT(nom_ens,' ',COALESCE(prenom_ens,''))) FROM enseignant WHERE matricule_ens=?", [$d['matricule_ens']]);
    ok(str_contains($html, 'Vous remplacez') && str_contains($html, h($ancien)) && str_contains($html, '2e trimestre'), "avis affiché : remplace $ancien à partir du 2e trimestre");
});
