<?php
// Sert la photo d'un élève stockée en BLOB (Photo_elv) — héritage de
// l'ancien système, jamais migrée en fichier pour l'instant.
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_connexion();

$id   = (int)($_GET['id'] ?? 0);
$sexe = db_val("SELECT Sexe_elv FROM eleve WHERE id_eleve=?", [$id]);
$blob = db_val("SELECT Photo_elv FROM eleve WHERE id_eleve=?", [$id]);

// Repli sur l'avatar générique si le BLOB est absent OU n'est en réalité pas
// une image (bug connu du legacy — voir blob_est_image()) : évite une icône
// d'image cassée dans le navigateur pour les élèves concernés.
if (!blob_est_image($blob)) {
    $avatar = stripos((string)$sexe, 'F') === 0 ? 'fille.png' : 'garcon.png';
    header('Location: ' . APP_URL . '/assets/img/avatars/' . $avatar);
    exit;
}

$fi   = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_buffer($fi, $blob);
finfo_close($fi);

header('Content-Type: ' . $mime);
header('Cache-Control: private, max-age=86400');
echo $blob;
