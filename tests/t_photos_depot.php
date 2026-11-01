<?php
// Dossier de dépôt des photos par école (01/11/2026) : photos copiées à la main
// dans assets/uploads/photos_depot/<CODE>/, nommées NIU ou matricule, puis
// « Récupérer les photos du dossier ». Écritures annulées (transaction) ;
// fichiers de test dans un sous-dossier zz_test_* supprimé à la fin.
require __DIR__ . '/_amorce.php';
require_once 'noyau/partage/eleves/_photos_depot_lib.php';

function rm_arbre(string $d): void {
    if (!is_dir($d)) return;
    foreach (array_diff(scandir($d), ['.', '..']) as $n) is_dir("$d/$n") ? rm_arbre("$d/$n") : unlink("$d/$n");
    rmdir($d);
}
function jpeg_test(string $chemin, int $w = 800, int $h = 600): void {
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 30, 120, 200));
    imagejpeg($im, $chemin, 80); imagedestroy($im);
}

$fait = 0;
foreach (assoc_all("SELECT code, type_enseignement t FROM etablissement WHERE actif=1 ORDER BY id") as $e) {
    $sec = $e['t'] === 'secondaire';
    if (($sec && $fait & 2) || (!$sec && $fait & 1)) continue;
    if (!ecole($e['code'])) continue;
    $dir = photos_depot_dossier();
    if (photos_depot_fichiers()) { echo "  – {$e['code']} : dossier de dépôt non vide, école ignorée\n"; continue; }
    // deux élèves actifs : l'un avec NIU, l'autre identifié par son matricule ; un troisième avec photo
    $q = $sec ? "SELECT id, niu, matricule AS mat, photo FROM eleve WHERE statut='actif'"
              : "SELECT id_eleve AS id, niu, Mat_elv AS mat, (Photo_elv IS NOT NULL) AS photo FROM eleve WHERE statut='actif'";
    $ix = photos_depot_index();
    $rows = array_filter(db_all($q), fn($r) => !($sec ? photo_sec_presente($r['photo']) : $r['photo']));
    $a = $b = null;
    foreach ($rows as $r) {
        $cn = photos_cle((string) $r['niu']); $cm = photos_cle((string) $r['mat']);
        if (!$a && strlen($cn) >= 3 && ($ix['niu'][$cn] ?? 0) === (int) $r['id']) $a = $r;
        elseif (!$b && strlen($cm) >= 3 && ($ix['mat'][$cm] ?? 0) === (int) $r['id'] && !isset($ix['niu'][$cm])) $b = $r;
        if ($a && $b) break;
    }
    if (!$a || !$b) continue;
    echo "  ({$e['code']} — dossier " . photos_depot_libelle() . ")\n";
    ok(is_file(dirname($dir) . '/.htaccess') && str_contains(file_get_contents(dirname($dir) . '/.htaccess'), 'Require all denied'), 'dossier protégé (.htaccess Require all denied)');
    $sous = 'zz_test_' . getmypid();
    mkdir("$dir$sous/6e A", 0775, true);
    jpeg_test("$dir$sous/6e A/{$a['niu']}.JPG", 1200, 900);
    jpeg_test("$dir$sous/" . str_replace('/', '-', $b['mat']) . '.jpeg', 600, 900);
    jpeg_test("$dir$sous/INCONNU-XYZ-999.jpg");
    file_put_contents("$dir$sous/pas_une_image.txt", 'x');
    egal(3, count(photos_depot_fichiers()), '3 images vues (sous-dossiers compris, .txt ignoré)');
    test_transaction(function () use ($a, $b, $sec, $dir, $sous) {
        $r = photos_depot_traiter(false);
        $etat = array_column($r['lignes'], 'etat', 'fichier');
        egal('ok', $etat["$sous/6e A/{$a['niu']}.JPG"] ?? null, 'photo nommée par NIU (sous-dossier de classe) enregistrée');
        egal('ok', $etat["$sous/" . str_replace('/', '-', $b['mat']) . '.jpeg'] ?? null, 'photo nommée par matricule enregistrée');
        egal('inconnu', $etat["$sous/INCONNU-XYZ-999.jpg"] ?? null, 'fichier non reconnu signalé');
        $bin = photo_eleve_binaire((int) $a['id']);
        $inf = $bin ? getimagesizefromstring($bin) : null;
        ok($inf && $inf[0] === 300 && $inf[1] === 400 && $inf[2] === IMAGETYPE_JPEG, 'photo enregistrée en base, recadrée 300×400 JPEG');
        ok(is_file("$dir$sous/INCONNU-XYZ-999.jpg") && !is_file("$dir$sous/6e A/{$a['niu']}.JPG"), 'non reconnue laissée en place, reconnues déplacées');
        ok(is_file($dir . PHOTOS_DEPOT_TRAITEES . '/' . date('Y-m-d') . "/$sous/6e A/{$a['niu']}.JPG"), 'photo traitée rangée dans traitees/<date>/');
        // une nouvelle photo pour un élève qui en a déjà une : non remplacée par défaut
        jpeg_test("$dir$sous/{$a['niu']}.jpg");
        $r = photos_depot_traiter(false);
        egal('existe', array_column($r['lignes'], 'etat', 'fichier')["$sous/{$a['niu']}.jpg"] ?? null, 'élève ayant déjà une photo : non remplacée sans la case « Remplacer »');
        $r = photos_depot_traiter(true);
        egal('ok', array_column($r['lignes'], 'etat', 'fichier')["$sous/{$a['niu']}.jpg"] ?? null, 'avec « Remplacer » : photo remplacée');
    });
    rm_arbre("$dir$sous");
    rm_arbre($dir . PHOTOS_DEPOT_TRAITEES . '/' . date('Y-m-d') . "/$sous");
    @rmdir($dir . PHOTOS_DEPOT_TRAITEES . '/' . date('Y-m-d')); @rmdir($dir . PHOTOS_DEPOT_TRAITEES);
    $fait |= $sec ? 2 : 1;
}
if (!$fait) passer('aucune école avec des élèves identifiables');
