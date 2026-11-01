<?php
// secondaire/commun/pages/eleves/save_parents.php — père, mère et autres tuteurs
// d'un élève (école secondaire), depuis la fiche élève (voir.php). Pendant de
// primaire/pages/eleves/save_parents.php. Tout est dans `tuteur` (lien « Père »,
// « Mère », « Oncle »…) : c'est là que le certificat de scolarité, la fiche PDF,
// les cartes et les bulletins lisent les parents.
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'FONDATEUR', 'CENSEUR', 'SG', 'SECRETAIRE']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') rediriger('secondaire/commun/pages/eleves/liste.php');
csrf_verifier();

$id = (int) post('id_eleve');
if (!$id || !db_val("SELECT COUNT(*) FROM eleve WHERE id=?", [$id])) {
    flash_set('erreur', 'Élève introuvable.');
    rediriger('secondaire/commun/pages/eleves/liste.php');
}
exiger_acces_eleve_secondaire($id);
$retour = 'secondaire/commun/pages/eleves/voir.php?id=' . $id;
$adresse_ok = db_colonne_existe('tuteur', 'adresse');   // migration secondaire v26

// Champs d'une personne (préfixe pere_, mere_, tuteur_) ; null si tout est vide.
$personne = function (string $p) use ($adresse_ok): ?array {
    $c = ['nom' => post($p . 'nom'), 'prenom' => post($p . 'prenom'), 'profession' => post($p . 'profession'),
          'telephone' => post($p . 'telephone')];
    if ($adresse_ok) $c['adresse'] = post($p . 'adresse');
    return implode('', $c) === '' ? null : $c;
};

$action = post('action') ?: 'parents';

if ($action === 'parents') {
    foreach (['Père' => 'pere_', 'Mère' => 'mere_'] as $lien => $p) {
        $c = $personne($p);
        // Ligne existante de ce parent (lien écrit « Père », « pere »…, voir certificat_scolarite.php).
        $motif = $lien === 'Père' ? ['%pere%', '%père%'] : ['%mere%', '%mère%'];
        $existant = db_val("SELECT id FROM tuteur WHERE id_eleve=? AND (LOWER(lien) LIKE ? OR LOWER(lien) LIKE ?) ORDER BY id LIMIT 1",
                           [$id, $motif[0], $motif[1]]);
        if (!$c) {
            if ($existant) db_exec("DELETE FROM tuteur WHERE id=?", [$existant]);
            continue;
        }
        if ($c['nom'] === '') { flash_set('erreur', "Le nom du $lien est obligatoire."); rediriger($retour); }
        $cols = array_keys($c);
        if ($existant) {
            db_exec("UPDATE tuteur SET " . implode('=?, ', $cols) . "=?, lien=? WHERE id=?",
                    array_merge(array_map(fn($v) => $v === '' ? null : $v, array_values($c)), [$lien, $existant]));
        } else {
            db_exec("INSERT INTO tuteur (id_eleve, lien, " . implode(', ', $cols) . ") VALUES (?, ?" . str_repeat(', ?', count($cols)) . ")",
                    array_merge([$id, $lien], array_map(fn($v) => $v === '' ? null : $v, array_values($c))));
        }
    }
    flash_set('succes', 'Parents mis à jour.');
} elseif ($action === 'tuteur_ajouter') {
    $c = $personne('tuteur_');
    if (!$c || $c['nom'] === '') { flash_set('erreur', 'Le nom du tuteur est obligatoire.'); rediriger($retour); }
    $lien = trim(post('tuteur_lien')) ?: 'Tuteur';
    $cols = array_keys($c);
    db_exec("INSERT INTO tuteur (id_eleve, lien, " . implode(', ', $cols) . ") VALUES (?, ?" . str_repeat(', ?', count($cols)) . ")",
            array_merge([$id, $lien], array_map(fn($v) => $v === '' ? null : $v, array_values($c))));
    flash_set('succes', 'Tuteur ajouté.');
} elseif ($action === 'tuteur_supprimer') {
    db_exec("DELETE FROM tuteur WHERE id=? AND id_eleve=?", [(int) post('id'), $id]);
    flash_set('succes', 'Tuteur retiré.');
}

rediriger($retour);
