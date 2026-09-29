<?php
// pages/eleves/_photos_lib.php — Photos par classe (primaire ET secondaire).
// Adaptateur unique utilisé par l'onglet « Photos par classe »
// (_eleves_photos.php + _photos_outils.php), la séance photo au téléphone
// (photos_seance.php) et l'enregistrement/suppression (photo_enregistrer.php)
// — les pages secondaire/pages/eleves/ du même nom ne font que les inclure.
// Deux stockages différents :
//   primaire   : BLOB eleve.Photo_elv (servi par pages/eleves/photo.php) ;
//   secondaire : fichier assets/uploads/eleves/<eleve.photo> (UPLOAD_DIR),
//                lu tel quel par les bulletins/cartes du secondaire.
// À inclure après config.php / connexion.php / fonctions.php.

function photos_est_secondaire(): bool {
    return function_exists('type_enseignement_courant') && type_enseignement_courant() === 'secondaire';
}

// Mêmes rôles que la modification d'une fiche élève (save.php de chaque type).
function photos_roles(): array {
    return photos_est_secondaire()
        ? ['ADMIN', 'PROVISEUR', 'FONDATEUR', 'CENSEUR', 'SG', 'SECRETAIRE']
        : ['DIRECTEUR', 'SECRETAIRE', 'COMPTABLE'];
}

// Dossier des pages élèves de l'école courante (liens, API, QR code).
function photos_base_url(): string {
    return APP_URL . (photos_est_secondaire() ? '/secondaire/pages/eleves' : '/pages/eleves');
}

function photos_avatar(string $sexe): string {
    return APP_URL . '/assets/img/avatars/' . ((stripos($sexe, 'F') === 0) ? 'fille.png' : 'garcon.png');
}

// [ ['id' => …, 'nom' => …], … ] dans l'ordre habituel des classes.
function photos_classes(): array {
    if (photos_est_secondaire()) {
        return db_all("SELECT id, designation AS nom FROM classe WHERE archivee=0 ORDER BY ordre, designation");
    }
    return db_all("SELECT c.IDClasses AS id, c.DesignationClasses AS nom FROM classe c
                   LEFT JOIN niveau n ON n.LibelleNiveau=c.Niveau ORDER BY n.OrdreNiveau, c.DesignationClasses");
}

function photos_classe(int $id_classe): ?array {
    foreach (photos_classes() as $c) if ((int) $c['id'] === $id_classe) return $c;
    return null;
}

// Élèves ACTIFS inscrits cette année dans la classe, ordre alphabétique :
// [id, mat, nom, photo (bool), url].
function photos_eleves_classe(int $id_classe): array {
    $annee = get_annee_active();
    if (photos_est_secondaire()) {
        $rows = db_all(
            "SELECT e.id, e.matricule AS mat, e.nom, e.prenom, e.sexe, e.photo
             FROM eleve e JOIN inscription i ON i.id_eleve=e.id AND i.id_annee=?
             WHERE i.id_classe=? AND e.statut='actif' ORDER BY e.nom, e.prenom",
            [(int) ($annee['id'] ?? 0), $id_classe]);
        return array_map(function ($e) {
            $a = !empty($e['photo']) && is_file(UPLOAD_DIR . $e['photo']);
            return [
                'id'    => (int) $e['id'],
                'mat'   => (string) ($e['mat'] ?? ''),
                'nom'   => trim(mb_strtoupper((string) $e['nom']) . ' ' . ($e['prenom'] ?? '')),
                'photo' => $a,
                'url'   => $a ? APP_URL . '/assets/uploads/eleves/' . rawurlencode($e['photo']) : photos_avatar((string) $e['sexe']),
            ];
        }, $rows);
    }
    $rows = db_all(
        "SELECT e.id_eleve, e.Mat_elv, e.Nom_elv, e.Prenom_elv, e.Sexe_elv, (e.Photo_elv IS NOT NULL) AS a_photo
         FROM eleve e JOIN inscrire i ON i.id_eleve=e.id_eleve AND i.val_annee=?
         WHERE i.IDClasses=? AND e.statut='actif' ORDER BY e.Nom_elv, e.Prenom_elv",
        [$annee['val_annee'] ?? '', $id_classe]);
    return array_map(fn($e) => [
        'id'    => (int) $e['id_eleve'],
        'mat'   => (string) ($e['Mat_elv'] ?? ''),
        'nom'   => trim(mb_strtoupper((string) $e['Nom_elv']) . ' ' . ($e['Prenom_elv'] ?? '')),
        'photo' => (bool) $e['a_photo'],
        'url'   => url_photo_eleve((int) $e['id_eleve'], (bool) $e['a_photo'], (string) $e['Sexe_elv']),
    ], $rows);
}

// Ligne élève minimale (id, sexe, photo actuelle au secondaire) ou null.
function photos_eleve(int $id): ?array {
    return photos_est_secondaire()
        ? db_one("SELECT id, sexe, photo FROM eleve WHERE id=?", [$id])
        : db_one("SELECT id_eleve AS id, Sexe_elv AS sexe FROM eleve WHERE id_eleve=?", [$id]);
}

// Enregistre l'image (binaire déjà validé par l'appelant) — retourne l'URL
// à afficher.
function photo_eleve_enregistrer(int $id, string $bin, int $type_image): string {
    if (!photos_est_secondaire()) {
        db_exec("UPDATE eleve SET Photo_elv=? WHERE id_eleve=?", [$bin, $id]);
        return APP_URL . '/pages/eleves/photo.php?id=' . $id . '&v=' . time();
    }
    $ancien = (string) (db_val("SELECT photo FROM eleve WHERE id=?", [$id]) ?? '');
    $ext = $type_image === IMAGETYPE_PNG ? 'png' : ($type_image === IMAGETYPE_WEBP ? 'webp' : 'jpg');
    $nom = 'elv_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0755, true);
    if (@file_put_contents(UPLOAD_DIR . $nom, $bin) === false) {
        throw new RuntimeException('dossier des photos non accessible en écriture.');
    }
    try {
        db_exec("UPDATE eleve SET photo=? WHERE id=?", [$nom, $id]);
    } catch (Throwable $e) {
        @unlink(UPLOAD_DIR . $nom);
        throw $e;
    }
    photos_supprimer_fichier($ancien);
    return APP_URL . '/assets/uploads/eleves/' . rawurlencode($nom);
}

// Retire la photo — retourne l'URL de l'avatar générique.
function photo_eleve_supprimer(int $id): string {
    $el = photos_eleve($id);
    if (!photos_est_secondaire()) {
        db_exec("UPDATE eleve SET Photo_elv=NULL WHERE id_eleve=?", [$id]);
    } else {
        db_exec("UPDATE eleve SET photo=NULL WHERE id=?", [$id]);
        photos_supprimer_fichier((string) ($el['photo'] ?? ''));
    }
    return photos_avatar((string) ($el['sexe'] ?? ''));
}

// Supprime un ancien fichier photo du secondaire — uniquement un nom simple
// (jamais de chemin) et seulement s'il n'est plus référencé par aucun élève.
function photos_supprimer_fichier(string $nom): void {
    if ($nom === '' || basename($nom) !== $nom) return;
    if ((int) db_val("SELECT COUNT(*) FROM eleve WHERE photo=?", [$nom]) > 0) return;
    if (is_file(UPLOAD_DIR . $nom)) @unlink(UPLOAD_DIR . $nom);
}
