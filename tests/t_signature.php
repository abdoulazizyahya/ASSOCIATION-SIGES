<?php
// Signature numérique : la catégorie de chaque document est reconnue par son
// chemin, et la politique par défaut du propriétaire est respectée.
require __DIR__ . '/_amorce.php';
egal('bulletin', signature_categorie('/SIGES/secondaire/technique/pages/bulletins/pdf.php'), 'bulletin technique reconnu');
egal('bulletin', signature_categorie('/SIGES/primaire/pdf/bulletin_trimestriel.php'), 'bulletin primaire reconnu');
egal(null, signature_categorie('/SIGES/secondaire/commun/pages/enseignants/apercu.php'), 'documents du personnel hors politique');
foreach (SIGNATURE_DEFAUTS as $cat => $def) ok(isset(SIGNATURE_CATEGORIES[$cat]), "catégorie « $cat » décrite");
$p = signature_politique();
egal(array_keys(SIGNATURE_DEFAUTS), array_keys($p), 'politique : une décision par catégorie');
