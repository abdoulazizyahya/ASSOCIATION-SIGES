<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_role(['DIRECTEUR','SECRETAIRE']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') rediriger('pages/eleves/liste.php');

// Jeton dédié, même session que les modales Parents/Tuteur de voir.php.
session_init();
$csrf_ok = isset($_POST['csrf'], $_SESSION['csrf_parents']) && hash_equals($_SESSION['csrf_parents'], $_POST['csrf']);
if (!$csrf_ok) {
    flash_set('erreur', 'Jeton de sécurité invalide, veuillez réessayer.');
    rediriger('pages/eleves/liste.php');
}

$id = (int) post('id_eleve');
if (!db_val("SELECT COUNT(*) FROM eleve WHERE id_eleve=?", [$id])) {
    flash_set('erreur', 'Élève introuvable.');
    rediriger('pages/eleves/liste.php');
}

$dernier_etab    = post('dernier_etab');
$derniere_classe = post('derniere_classe');
$date_rec        = post('date_rec') ?: null;
$antecedent_med  = post('antecedent_med');
$frequence       = post('frequence');
$autres_infos    = post('autres_infos');
// handicap/nature_handicap/refugie/type_refugie/indigent sont NOT NULL dans le
// schéma jaynitaare (héritage du système historique) — '' (et non null) pour
// « non renseigné », sinon mysqli lève une exception sur l'INSERT/UPDATE.
$handicap        = in_array(post('handicap'), ['OUI','NON'], true) ? post('handicap') : '';
$nature_handicap = $handicap === 'OUI' ? post('nature_handicap') : '';
$refugie         = in_array(post('refugie'), ['OUI','NON'], true) ? post('refugie') : '';
$type_refugie    = $refugie === 'OUI' ? post('type_refugie') : '';
// indigent : select retiré de l'écran (migration_v39, remplacé par la case
// "Cas social" ci-dessous) — colonne conservée en base (NOT NULL, héritage),
// on n'y écrit plus rien de neuf, toujours ''.
$indigent        = '';
// Cas social (migration_v39) : case à cocher + pourcentage de réduction sur
// le montant dû des frais (pages/finances/*, voir fonctions.php).
$cas_social            = isset($_POST['cas_social']) ? 1 : 0;
$pourcentage_reduction = $cas_social ? max(0.0, min(100.0, (float) str_replace(',', '.', post('pourcentage_reduction')))) : 0.0;

$existant = db_val("SELECT COUNT(*) FROM info_supplementaires WHERE id_eleve=?", [$id]);
if ($existant) {
    db_exec(
        "UPDATE info_supplementaires SET dernier_etab=?, derniere_classe=?, date_rec=?, antecedent_med=?,
                frequence=?, autres_infos=?, handicap=?, nature_handicap=?, refugie=?, type_refugie=?, indigent=?,
                cas_social=?, pourcentage_reduction=?
         WHERE id_eleve=?",
        [$dernier_etab, $derniere_classe, $date_rec, $antecedent_med, $frequence, $autres_infos,
         $handicap, $nature_handicap, $refugie, $type_refugie, $indigent, $cas_social, $pourcentage_reduction, $id]
    );
} else {
    db_exec(
        "INSERT INTO info_supplementaires
            (dernier_etab, derniere_classe, date_rec, antecedent_med, frequence, autres_infos,
             handicap, nature_handicap, refugie, type_refugie, indigent, cas_social, pourcentage_reduction, id_eleve)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [$dernier_etab, $derniere_classe, $date_rec, $antecedent_med, $frequence, $autres_infos,
         $handicap, $nature_handicap, $refugie, $type_refugie, $indigent, $cas_social, $pourcentage_reduction, $id]
    );
}

flash_set('succes', 'Informations complémentaires mises à jour.');
rediriger('pages/eleves/voir.php?id=' . $id);
