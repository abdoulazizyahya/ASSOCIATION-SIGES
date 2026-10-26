<?php
// Changement de chef en cours d'année : chaque document prend la signature (et
// le titre) du chef de SA période ; les documents hors période, le chef actuel.
require __DIR__ . '/_amorce.php';
$fichier_test = 'zz_test_chef_archive.png';
$abs_test = __DIR__ . '/../assets/uploads/' . $fichier_test;
file_put_contents($abs_test, 'png de test');

foreach (['LTM' => 'secondaire', 'EC1' => 'primaire'] as $code => $type) {
    if (!ecole($code)) { echo "  – $code absente\n"; continue; }
    if (!chef_historique_dispo()) { echo "  – $code : migration non appliquée\n"; continue; }
    $an = get_annee_active();
    $debut = chef_annee_debut($an['val_annee']);
    // T1 et T2 de l'année active (identifiants propres à chaque ordre)
    $t = $type === 'secondaire'
        ? array_column(db_all("SELECT id, ordre FROM trimestre WHERE id_annee=? ORDER BY ordre LIMIT 2", [$an['id']]), 'id')
        : array_column(db_all("SELECT id_trim id FROM trimestre WHERE id_annee=? ORDER BY id_trim LIMIT 2", [$an['val_annee']]), 'id');
    if (count($t) < 2) { echo "  – $code : moins de 2 trimestres\n"; continue; }
    $sig = fn() => $type === 'secondaire' ? signature_chemin('chef_etablissement') : signature_etablissement_chemin();
    $doc = function (string $script, array $get) { $_SERVER['SCRIPT_NAME'] = '/SIGES/' . $script; $_GET = $get; };
    $bulletin = $type === 'secondaire' ? 'secondaire/technique/pages/bulletins/pdf.php' : 'primaire/pdf/bulletin_trimestriel.php';
    $certif   = $type === 'secondaire' ? 'secondaire/commun/pdf/certificat_scolarite.php' : 'primaire/pdf/certificat_scolarite.php';

    test_transaction(function () use ($code, $debut, $t, $sig, $doc, $bulletin, $certif, $fichier_test, $abs_test, $type, $an) {
        $doc($certif, []);
        $actuelle = $sig();   // (cache par requête : on ne lit pas le T1 avant l'insertion)
        db_exec("INSERT INTO chef_historique (titre_fr, titre_en, signature, remplace_annee, remplace_trim) VALUES ('Le Principal','The Principal',?,?,2)", [$fichier_test, $debut]);
        $doc($bulletin, ['trim' => $t[0]]);
        egal(realpath($abs_test), realpath((string) $sig()), "$code : bulletin du T1 → signature de l'ancien chef");
        egal('Le Principal', titres_chef_pdf(get_etablissement())[0], "$code : bulletin du T1 → titre de l'ancien chef");
        $doc($bulletin, ['trim' => $t[1]]);
        egal($actuelle, $sig(), "$code : bulletin du T2 → signature actuelle");
        $doc($certif, ['trim' => $t[0]]);
        egal($actuelle, $sig(), "$code : certificat (hors période) → signature actuelle");
        if ($type === 'secondaire') {
            $doc('secondaire/technique/pages/bulletins/pdf_annuel.php', ['annee' => $an['id']]);
            egal($actuelle, $sig(), "$code : bulletin annuel → chef en fin d'année (actuel)");
        }
    });
}
$_SERVER['SCRIPT_NAME'] = '/SIGES/association/tests.php'; $_GET = [];
@unlink($abs_test);

// Flux complet « un nouveau chef à partir du T2 » (même fonction que la page),
// fichiers de signature remis en place à la fin.
foreach (['LTM' => 'secondaire', 'EC1' => 'primaire'] as $code => $type) {
    if (!ecole($code) || !chef_historique_dispo()) continue;
    $base = __DIR__ . '/../assets/uploads/';
    $avant = $type === 'secondaire' ? db_val("SELECT fichier FROM signature_titulaire WHERE code='chef_etablissement'") : (get_etablissement()['signature'] ?? null);
    $octets = $avant && is_file($base . $avant) ? file_get_contents($base . $avant) : null;
    $img = sys_get_temp_dir() . '/siges_test_sig.png';
    $i = imagecreatetruecolor(200, 60); imagefill($i, 0, 0, imagecolorallocate($i, 255, 255, 255)); imagepng($i, $img);
    $dest_nouveau = $base . upload_prefixe_etab() . ($type === 'secondaire' ? 'signature_chef_etablissement.png' : 'signature_directeur.png');
    $octets_dest = is_file($dest_nouveau) ? file_get_contents($dest_nouveau) : null;
    $an = get_annee_active(); $debut = chef_annee_debut($an['val_annee']);
    test_transaction(function () use ($code, $type, $img, $debut, $base, $octets) {
        chef_enregistrer_changement($debut, 2, $img, 'png', '', '', 'test');
        $r = db_one("SELECT * FROM chef_historique WHERE remplace_annee=? AND remplace_trim=2", [$debut]);
        ok((bool) $r, "$code : ancien chef archivé (remplacé au T2)");
        ok($octets === null ? $r['signature'] === null : ($r['signature'] && file_get_contents($base . $r['signature']) === $octets), "$code : copie figée de l'ancienne signature");
        $act = $type === 'secondaire' ? signature_chemin('chef_etablissement') : signature_etablissement_chemin();
        ok($act !== null && (!$r['signature'] || realpath($act) !== realpath($base . $r['signature'])), "$code : la nouvelle signature est la signature actuelle");
        if (!empty($r['signature'])) @unlink($base . $r['signature']);
    });
    // remise en place des fichiers (la transaction a annulé la base)
    if ($octets_dest === null) @unlink($dest_nouveau); else file_put_contents($dest_nouveau, $octets_dest);
    @unlink($img);
}
