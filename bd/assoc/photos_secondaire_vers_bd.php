<?php
// =====================================================================
//  bd/assoc/photos_secondaire_vers_bd.php
//  Copie EN BASE (eleve.photo_bin, migration secondaire v9) toutes les
//  photos des élèves des écoles SECONDAIRES encore stockées en fichiers
//  dans assets/uploads/eleves/, puis supprime chaque fichier converti.
//  (Les photos sont aussi converties une à une à leur premier affichage —
//  ce script fait tout d'un coup.) Demande du 03/10/2026.
//
//  Rejouable sans risque : une photo déjà en base est ignorée ; un fichier
//  n'est supprimé qu'après l'enregistrement réussi en base.
//
//  Prérequis : migration secondaire v9 appliquée (portail > Migrations).
//  Sécurité : en HTTP, jeton obligatoire ?token=<BACKUP_TOKEN>.
//  Usage :
//    /bd/assoc/photos_secondaire_vers_bd.php?token=XXX          (aperçu)
//    /bd/assoc/photos_secondaire_vers_bd.php?token=XXX&go=1     (applique)
//    php bd/assoc/photos_secondaire_vers_bd.php [--go]
// =====================================================================
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion_assoc.php';

$est_cli = (PHP_SAPI === 'cli');
if (!$est_cli) {
    header('Content-Type: text/plain; charset=utf-8');
    $token = (string) ($_GET['token'] ?? '');
    if (!defined('BACKUP_TOKEN') || BACKUP_TOKEN === '' || !hash_equals(BACKUP_TOKEN, $token)) {
        http_response_code(403);
        die("Accès refusé : jeton invalide (BACKUP_TOKEN de config.local.php).\n");
    }
}
$go = $est_cli ? in_array('--go', $argv ?? [], true) : (($_GET['go'] ?? '') === '1');
if (!annuaire_dispo()) die("Annuaire indisponible.\n");

echo "=== Photos des élèves du secondaire : fichiers -> base ===\n" . ($go ? "MODE APPLICATION\n\n" : "MODE APERÇU (rien n'est modifié — ajouter go=1 / --go)\n\n");
$dossier = rtrim(UPLOAD_DIR, '/\\') . '/';
$tot_conv = 0; $tot_manq = 0;

foreach (assoc_all("SELECT id, code, nom FROM etablissement WHERE actif=1 AND type_enseignement='secondaire' ORDER BY code") as $e) {
    echo "{$e['code']} — {$e['nom']}\n";
    try {
        avec_ecole((int) $e['id'], function (mysqli $l) use ($dossier, $go, &$tot_conv, &$tot_manq) {
            if (!(int) (ecole_one($l, "SELECT COUNT(*) AS n FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='eleve' AND column_name='photo_bin'")['n'] ?? 0)) {
                echo "   ✗ migration secondaire v9 non appliquée — ignorée.\n";
                return;
            }
            $rows = ecole_all($l, "SELECT id, photo FROM eleve WHERE photo IS NOT NULL AND photo <> '' AND photo <> 'bd' AND photo_bin IS NULL");
            $conv = 0; $manq = 0;
            foreach ($rows as $r) {
                $nom = (string) $r['photo'];
                if (basename($nom) !== $nom || !is_file($dossier . $nom)) { $manq++; continue; }
                $bin = (string) file_get_contents($dossier . $nom);
                if ($bin === '' || !@getimagesizefromstring($bin)) { $manq++; continue; }
                if ($go) {
                    $st = mysqli_prepare($l, "UPDATE eleve SET photo_bin=?, photo='bd' WHERE id=?");
                    $null = null; $id = (int) $r['id'];
                    mysqli_stmt_bind_param($st, 'bi', $null, $id);
                    mysqli_stmt_send_long_data($st, 0, $bin);
                    if (mysqli_stmt_execute($st)) @unlink($dossier . $nom);
                    mysqli_stmt_close($st);
                }
                $conv++;
            }
            echo "   " . ($go ? 'converties' : 'à convertir') . " : $conv" . ($manq ? "   (fichier absent ou illisible : $manq)" : '') . "\n";
            $tot_conv += $conv; $tot_manq += $manq;
        });
    } catch (\Throwable $x) {
        echo "   ✗ " . $x->getMessage() . "\n";
    }
}
echo "\nTotal " . ($go ? 'converties' : 'à convertir') . " : $tot_conv" . ($tot_manq ? "   — introuvables : $tot_manq" : '') . "\n";
