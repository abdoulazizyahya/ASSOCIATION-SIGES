<?php
// pages/eleves/photo_enregistrer.php — Enregistre ou SUPPRIME la photo d'UN
// élève (AJAX, réponse JSON), primaire comme secondaire (voir
// _photos_lib.php ; secondaire/pages/eleves/photo_enregistrer.php inclut ce
// fichier). Utilisé par l'onglet « Photos par classe » de la liste des élèves
// (photo unitaire, import en lot, planche scannée, suppression) et par la
// séance photo au téléphone (photos_seance.php).
// La photo arrive déjà recadrée/réduite côté navigateur (300×400 JPEG, même
// format que le recadrage Cropper.js de la fiche élève).
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/_photos_lib.php';
exiger_role(photos_roles());

header('Content-Type: application/json; charset=utf-8');
function photo_json(bool $ok, string $message = '', array $extra = []): never {
    echo json_encode(['ok' => $ok, 'message' => $message] + $extra);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') photo_json(false, 'Méthode non autorisée.');
csrf_verifier();

$id_eleve = (int) post('id_eleve');
if (!$id_eleve || !photos_eleve($id_eleve)) photo_json(false, 'Élève introuvable.');

// ── Suppression ─────────────────────────────────────────────
if (post('action') === 'supprimer') {
    try {
        $url = photo_eleve_supprimer($id_eleve);
    } catch (Throwable $e) {
        photo_json(false, 'Suppression impossible : ' . $e->getMessage());
    }
    photo_json(true, 'Photo supprimée.', ['url' => $url]);
}

// ── Enregistrement ──────────────────────────────────────────
$bin = photo_postee();   // fichier (multipart) de préférence, base64 en secours
if ($bin === null) photo_json(false, 'Photo absente ou trop volumineuse (2 Mo max).');

// Contrôle du contenu réel (jamais la seule déclaration data:image/… du
// navigateur) : uniquement JPEG/PNG/WebP, dimensions plausibles.
$info = @getimagesizefromstring($bin);
if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)
    || $info[0] < 50 || $info[1] < 50 || $info[0] > 3000 || $info[1] > 3000) {
    photo_json(false, "Le fichier envoyé n'est pas une image valide.");
}

try {
    $url = photo_eleve_enregistrer($id_eleve, $bin, (int) $info[2]);
} catch (Throwable $e) {
    photo_json(false, 'Enregistrement impossible : ' . $e->getMessage());
}
photo_json(true, 'Photo enregistrée.', ['url' => $url]);
