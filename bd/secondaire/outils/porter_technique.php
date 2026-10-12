<?php
// =====================================================================
//  bd/secondaire/outils/porter_technique.php  (outil de développement, CLI)
//  Porte une page d'ABZ_MBE (lycée technique, pages/X/Y.php) vers
//  secondaire/technique/pages/X/Y.php : chemins relatifs, URLs internes,
//  rôles, vrai NIU affiché, photos en base, contrôle d'accès au bulletin.
//  A servi à créer les 6 pages techniques (notes + 5 bulletins) le
//  12/10/2026 ; ces pages ont ENSUITE été retouchées à la main (sécurité
//  de la saisie des notes, onglet Relevé vierge masqué…) : ne pas les
//  régénérer par-dessus sans reporter ces retouches.
//  Usage : php bd/secondaire/outils/porter_technique.php <fichier_abz> <destination>
// =====================================================================
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Outil en ligne de commande uniquement.\n"); }
[$_, $src, $dst] = $argv;
$s = str_replace("\r\n", "\n", file_get_contents($src));
if (strncmp($s, "\xEF\xBB\xBF", 3) === 0) $s = substr($s, 3);   // BOM : sortie avant header()

// Pages qui ont une version technique dédiée (le reste pointe vers secondaire/pages/).
$tech = ['pages/notes/index.php', 'pages/bulletins/index.php', 'pages/bulletins/pdf.php',
         'pages/bulletins/pdf_classe.php', 'pages/bulletins/pdf_annuel.php', 'pages/bulletins/pdf_annuel_classe.php'];

// 1. Inclusions : secondaire/pdf/ (deux niveaux au-dessus de technique/pages/X) puis racine.
$s = str_replace("__DIR__ . '/../../pdf/", "__DIR__ . '/../../../pdf/", $s);
$s = preg_replace("#__DIR__ \\. '/\\.\\./\\.\\./(?!\\.\\./pdf/)#", "__DIR__ . '/../../../../", $s);
$s = str_replace("__DIR__.'/../../", "__DIR__.'/../../../../", $s);

// 2. URLs internes « pages/... » (rediriger("pages/..."), APP_URL/pages/..., 'pages/...').
$s = preg_replace_callback('#(^|[^A-Za-z0-9_/.-]|\?>/|APP_URL \. [\'"]/)pages/([a-z_]+)/([A-Za-z0-9_]+\.php)#m', function ($m) use ($tech) {
    $p = "pages/{$m[2]}/{$m[3]}";
    return $m[1] . (in_array($p, $tech, true) ? "secondaire/technique/$p" : "secondaire/$p");
}, $s);

// 3. Rôles : FONDATEUR et MEMBRE_ASSOCIATION (lecture élargie) comme dans le secondaire SIGES.
$s = str_replace("['ADMIN','PROVISEUR','CENSEUR']", "['ADMIN','PROVISEUR','FONDATEUR','CENSEUR']", $s);

// 4. Vrai NIU affiché (SIGES : id_affichage_eleve() = matricule, réservé aux QR).
$s = str_replace("\$pdf->Cell(20, 5.5, id_affichage_eleve(\$eleve), 1, 1, 'C');",
                 "\$pdf->Cell(20, 5.5, niu_eleve(\$eleve), 1, 1, 'C');", $s);
$s = str_replace("<?= h(id_affichage_eleve(\$el)) ?>", "<?= h(niu_eleve(\$el) ?: '—') ?>", $s);
$s = str_replace("NIU <?= h(id_affichage_eleve(\$eleve_info)) ?>", "NIU <?= h(niu_eleve(\$eleve_info) ?: '—') ?>", $s);

// 5. Photos des élèves du secondaire : stockées EN BASE dans SIGES.
$s = str_replace("!empty(\$eleve['photo']) ? __DIR__ . '/../../../../assets/uploads/eleves/' . \$eleve['photo'] : ''",
                 "!empty(\$eleve['photo']) ? photo_sec_fichier_tmp((int) \$eleve['id']) : ''", $s);

// 6. Bulletin individuel : administration ou professeur principal seulement (hors QR).
$s = str_replace("if (!\$acces_public) {\n    exiger_connexion();\n}",
                 "if (!\$acces_public) {\n    exiger_connexion();\n"
               . "    // Hors QR code : administration ou professeur principal de la classe\n"
               . "    // (un identifiant d'élève modifié à la main dans l'adresse est refusé).\n"
               . "    exiger_acces_bulletin_secondaire((int) (\$_GET['eleve'] ?? 0));\n}", $s);

if ($s === '' || $s === null) { fwrite(STDERR, "Résultat vide : $src\n"); exit(1); }
file_put_contents($dst, $s);
echo "$dst\n";
