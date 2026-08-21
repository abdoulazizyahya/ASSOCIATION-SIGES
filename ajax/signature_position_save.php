<?php
// ajax/signature_position_save.php — enregistre la position/taille (en %
// du cadre) de la signature pour un type de document précis, depuis la
// fenêtre modale de positionnement (layout/footer.php). Jamais appliqué
// automatiquement ailleurs : chaque document continue de ne dessiner la
// signature que si &signature=1 est explicitement demandé à l'impression —
// cet endpoint ne fait que mémoriser OÙ elle doit apparaître.
ob_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
exiger_role(['DIRECTEUR']);

header('Content-Type: application/json; charset=utf-8');

function repondre_erreur(string $msg): never {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
    repondre_erreur('Requête invalide (CSRF).');
}

$type_document = trim((string)($_POST['type_document'] ?? ''));
$x_pct = isset($_POST['x_pct']) ? (float)$_POST['x_pct'] : null;
$y_pct = isset($_POST['y_pct']) ? (float)$_POST['y_pct'] : null;
$w_pct = isset($_POST['w_pct']) ? (float)$_POST['w_pct'] : null;

if ($type_document === '' || $x_pct === null || $y_pct === null || $w_pct === null) {
    repondre_erreur('Paramètres manquants.');
}

db_exec(
    "INSERT INTO signature_position (type_document, x_pct, y_pct, w_pct, h_pct)
     VALUES (?, ?, ?, ?, NULL)
     ON DUPLICATE KEY UPDATE x_pct=VALUES(x_pct), y_pct=VALUES(y_pct), w_pct=VALUES(w_pct), h_pct=NULL",
    [$type_document, $x_pct, $y_pct, $w_pct]
);

ob_end_clean();
echo json_encode(['ok' => true]);
