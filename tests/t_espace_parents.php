<?php
// Espace parents (16/11/2026) : code parent (création, connexion, refus),
// rattachement par téléphone, données de l'enfant — primaire et secondaire.
require __DIR__ . '/_amorce.php';
require_once 'noyau/partage/espace_parents.php';
require_once 'secondaire/commun/pages/communication/_sms_lib.php';

foreach (['MHM1', 'LTM'] as $code_ecole) {
    if (!ecole($code_ecole)) { echo "  – $code_ecole absente\n"; continue; }
    ep_assurer_tables();   // DDL hors transaction
    test_transaction(function () use ($code_ecole) {
        $classes = ed_classes();
        ok((bool) $classes, "$code_ecole : classes trouvées");
        $e = ed_eleves((int) $classes[0]['id'])[0];
        $f = ep_codes_generer((int) $e['id_classe'], [(int) $e['id']]);
        egal(1, count($f), "$code_ecole : un code créé");
        ok((bool) preg_match('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/', $f[0]['code']), "$code_ecole : format du code ({$f[0]['code']})");
        egal((int) $e['id'], ep_connexion_code((string) $e['matricule'], strtolower(str_replace('-', '', $f[0]['code']))), "$code_ecole : connexion matricule + code (saisi en minuscules, sans tiret)");
        ok(is_string(ep_connexion_code((string) $e['matricule'], 'AAAA-AAAA')), "$code_ecole : mauvais code refusé");
        ok(is_string(ep_connexion_code('INCONNU-XYZ', $f[0]['code'])), "$code_ecole : matricule inconnu refusé");
        egal(true, isset(ep_codes_existants((int) $e['id_classe'])[(int) $e['id']]), "$code_ecole : code listé pour la classe");

        // Rattachement par téléphone.
        $ids = array_map('intval', array_column(ed_eleves(), 'id'));
        $tel = null; $avec = null;
        foreach (contacts_parents_lot($ids) as $id => $c) if ($c) { $tel = (string) $c[0]['tel']; $avec = (int) $id; break; }
        if ($tel) ok(in_array($avec, ep_eleves_du_tel($tel), true), "$code_ecole : le numéro $tel retrouve son enfant");
        egal([], ep_eleves_du_tel('699999998'), "$code_ecole : numéro inconnu → aucun enfant");

        // Données affichées au parent.
        ok(is_array(ep_versements((int) $e['id'])), "$code_ecole : versements lus (" . count(ep_versements((int) $e['id'])) . ')');
        $abs = ep_absences($e);
        ok(is_array($abs) && count($abs) > 0, "$code_ecole : absences par trimestre (" . count($abs) . ' période(s))');
        egal([], ep_resultats($e) ?: [], "$code_ecole : aucun trimestre publié → aucun résultat montré");
    });
    db_exec("DELETE FROM parent_tentative WHERE cle IN ('ip:127.0.0.1', 'mat:AAAA-AAAA', 'mat:INCONNU-XYZ') OR cle LIKE 'mat:%'");
}
