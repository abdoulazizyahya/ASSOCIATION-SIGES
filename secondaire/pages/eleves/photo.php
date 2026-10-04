<?php
// secondaire/pages/eleves/photo.php — sert la photo d'un élève du SECONDAIRE
// stockée en base (eleve.photo_bin, migration secondaire v9 — 03/10/2026),
// même principe que pages/eleves/photo.php au primaire : uniquement à un
// utilisateur connecté, dans les limites de ses classes (enseignant). Une
// photo encore en fichier est convertie en base au passage
// (fonctions.php::photo_sec_binaire()).
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_connexion();

$id = (int) ($_GET['id'] ?? 0);
exiger_acces_eleve_secondaire($id);   // enseignant : seulement ses classes

$bin  = $id ? photo_sec_binaire($id) : null;
// Accès vérifié et conversion faite : on libère le verrou de session (les
// dizaines de photos d'une grille sont alors servies en parallèle).
session_write_close();

if ($bin === null || !@getimagesizefromstring($bin)) {
    $sexe = (string) db_val("SELECT sexe FROM eleve WHERE id=?", [$id]);
    header('Location: ' . url_photo_eleve_sec($id, false, $sexe));
    exit;
}

// Cache navigateur par empreinte (réponse 304 si inchangée).
$etag = '"' . md5($bin) . '"';
header('Cache-Control: private, max-age=300');
header('ETag: ' . $etag);
if (trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}
$fi   = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_buffer($fi, $bin);
finfo_close($fi);
header('Content-Type: ' . $mime);
echo $bin;
