<?php
// Sert un document du dossier élève derrière l'authentification — les
// pièces jointes (acte de naissance, carnet de vaccination...) sont
// sensibles, on évite un accès direct par URL devinée dans assets/uploads/.
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_connexion();

$id  = (int)($_GET['id'] ?? 0);
$doc = db_one("SELECT * FROM dossier_eleve WHERE id=?", [$id]);
if (!$doc) { http_response_code(404); exit; }
exiger_acces_eleve((int) $doc['id_eleve'], 'union');   // enseignant restreint : pièce d'un élève hors de ses classes -> refus

$chemin = __DIR__ . '/../../assets/uploads/dossiers_eleves/' . $doc['fichier'];
if (!is_file($chemin)) { http_response_code(404); exit; }

$fi   = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($fi, $chemin) ?: 'application/octet-stream';
finfo_close($fi);

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . libelle_type_dossier($doc['type_document']) . '.' . pathinfo($doc['fichier'], PATHINFO_EXTENSION) . '"');
header('Cache-Control: private, max-age=3600');
readfile($chemin);
