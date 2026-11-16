<?php
// Relance automatique des impayés (16/11/2026) : cibles calculées au primaire
// et au secondaire, rien d'envoyé sans le modèle coché automatique.
require __DIR__ . '/_amorce.php';
require_once 'secondaire/commun/pages/communication/_sms_lib.php';

foreach (['DAD1', 'LTM'] as $code) {
    if (!ecole($code)) { echo "  – $code absente\n"; continue; }
    $c = sms_relance_cibles(1000);
    ok(is_array($c), "$code : " . count($c) . ' élève(s) à relancer (reste ≥ 1 000 F, avec un numéro)');
    if ($c) {
        $x = $c[0];
        ok($x['reste'] >= 1000 && $x['tel'] !== '' && $x['nom'] !== '', "$code : cible complète ({$x['nom']}, " . number_format($x['reste'], 0, ',', ' ') . " F)");
        $texte = sms_rendre(sms_modeles()['relance']['fr'], ['eleve' => $x['nom'], 'classe' => $x['classe'], 'reste' => number_format($x['reste'], 0, ',', ' ')]);
        ok(!str_contains($texte, '{'), "$code : message sans variable oubliée");
    }
    if (empty(sms_modeles()['relance']['auto'])) egal(null, sms_relance_prochaine(), "$code : relance non cochée → jamais lancée");
}
