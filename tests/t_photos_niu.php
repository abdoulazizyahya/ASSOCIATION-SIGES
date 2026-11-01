<?php
// Photos des élèves nommées par NIU, sinon matricule (01/11/2026) : nom de
// fichier, export ZIP d'une classe, réimport (association par NIU côté navigateur).
require __DIR__ . '/_amorce.php';
require_once 'noyau/partage/eleves/_photos_lib.php';

egal('PMC260014', photo_nom_fichier('PMC260014', 'GS-2026-01', 5), 'NIU prioritaire');
egal('GS-2026-01', photo_nom_fichier('', 'GS/2026/01', 5), 'sans NIU : matricule (caractères « / » remplacés)');
egal('eleve_5', photo_nom_fichier(' ', '', 5), 'ni NIU ni matricule : eleve_<id>');

/** Exporte la classe dans ce processus, renvoie la liste des fichiers du ZIP (ou un message). */
function export_zip(string $classe, string $f): array|string {
    $_GET = ['classe' => $classe];
    ob_start();
    include __DIR__ . "/../$f/photos_export.php";
    $z = (string) ob_get_clean();
    $p = strpos($z, "PK\x03\x04");   // en CLI, avertissements « headers already sent » avant le ZIP
    if ($p === false) return substr(strip_tags($z), 0, 200);
    $z = substr($z, $p);
    $tmp = tempnam(sys_get_temp_dir(), 'tz'); file_put_contents($tmp, $z);
    $a = new ZipArchive(); $a->open($tmp); $n = [];
    for ($i = 0; $i < $a->numFiles; $i++) $n[] = $a->getNameIndex($i);
    $a->close(); @unlink($tmp);
    return $n;
}

$fait = 0;
foreach (assoc_all("SELECT code, type_enseignement t FROM etablissement WHERE actif=1 ORDER BY id") as $e) {
    $sec = $e['t'] === 'secondaire';
    if (($sec && $fait & 2) || (!$sec && $fait & 1)) continue;
    if (!ecole($e['code'])) continue;
    if (!$sec) {
        $u = db_one("SELECT u.* FROM user u JOIN enseignant e ON e.matricule_ens=u.matricule_ens WHERE e.id_fonction='DIRECTEUR' LIMIT 1");
        if (!$u) continue;
        $_SESSION['user_id'] = $u['id_user'];
        $_SESSION['user'] = ['id' => $u['id_user'], 'matricule_ens' => $u['matricule_ens'], 'nom' => '', 'prenom' => '', 'role' => 'DIRECTEUR', 'login' => $u['login_user'], 'compte_systeme' => false];
    }
    // Classe ayant au moins une photo (requête directe : parcourir toutes les classes est lent).
    $id_annee = get_annee_active();
    $idc = $sec
        ? db_val("SELECT i.id_classe FROM inscription i JOIN eleve e ON e.id=i.id_eleve WHERE i.id_annee=? AND e.statut='actif' AND e.photo IS NOT NULL AND e.photo<>'' LIMIT 1", [(int) ($id_annee['id'] ?? 0)])
        : db_val("SELECT i.IDClasses FROM inscrire i JOIN eleve e ON e.id_eleve=i.id_eleve WHERE i.val_annee=? AND e.statut='actif' AND e.Photo_elv IS NOT NULL LIMIT 1", [$id_annee['val_annee'] ?? '']);
    foreach ($idc ? array_filter([photos_classe((int) $idc)]) : [] as $c) {
        $els = array_filter(photos_eleves_classe((int) $c['id']), fn($x) => $x['photo']);
        if (!$els) continue;
        $el = reset($els);
        echo "  ({$e['code']}, {$c['nom']} : " . count($els) . " photo(s))\n";
        egal(photo_nom_fichier($el['niu'], $el['mat'], $el['id']), $el['fichier'], 'champ « fichier » de la grille');
        $z = export_zip((string) $c['id'], $sec ? 'secondaire/commun/pages/eleves' : 'primaire/pages/eleves');
        ok(is_array($z) && in_array('liste.csv', $z, true), ($sec ? 'secondaire' : 'primaire') . ' : ZIP produit avec liste.csv' . (is_array($z) ? '' : " — $z"));
        if (is_array($z)) {
            ok((bool) preg_grep('/^' . preg_quote($el['fichier'], '/') . '\.(jpg|png|webp)$/', $z), "photo nommée {$el['fichier']}.* (" . ($el['niu'] !== '' ? 'NIU' : 'matricule') . ')');
            ok(count($z) - 1 <= count($els), 'une photo au plus par élève ayant une photo');
        }
        $fait |= $sec ? 2 : 1;
        break;
    }
}
if (!$fait) passer('aucune école avec des photos d\'élèves');
