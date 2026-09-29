<?php
// Sert la photo d'un élève stockée en BLOB (Photo_elv) — héritage de
// l'ancien système, jamais migrée en fichier pour l'instant.
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_connexion();

$id   = (int)($_GET['id'] ?? 0);
exiger_acces_eleve($id, 'union');   // enseignant restreint
// Accès vérifié : on libère le verrou de session tout de suite — sinon les
// dizaines de photos d'une même page (liste, grille « Photos par classe »)
// sont servies l'une après l'autre au lieu d'en parallèle.
session_write_close();
// Une seule requête (sexe + photo) au lieu de deux.
$row  = db_one("SELECT Sexe_elv, Photo_elv FROM eleve WHERE id_eleve=?", [$id]);
$sexe = $row['Sexe_elv'] ?? null;
$blob = $row['Photo_elv'] ?? null;

// Repli sur l'avatar générique si le BLOB est absent OU n'est en réalité pas
// une image (bug connu du legacy — voir blob_est_image()) : évite une icône
// d'image cassée dans le navigateur pour les élèves concernés.
if (!blob_est_image($blob)) {
    $avatar = stripos((string)$sexe, 'F') === 0 ? 'fille.png' : 'garcon.png';
    header('Location: ' . APP_URL . '/assets/img/avatars/' . $avatar);
    exit;
}

// Cache navigateur : empreinte (ETag) de la photo — une grille de 60 élèves
// réaffichée ne retélécharge que les photos réellement modifiées (réponse
// « 304 Not Modified » de quelques octets pour les autres). max-age court :
// une photo remplacée depuis un autre poste apparaît au plus 5 min après.
$etag = '"' . md5($blob) . '"';
header('Cache-Control: private, max-age=300');
header('ETag: ' . $etag);
if (trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}

$fi   = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_buffer($fi, $blob);
finfo_close($fi);

header('Content-Type: ' . $mime);
echo $blob;
