<?php
// pages/eleves/_fiches_lib.php — « Fiches incomplètes » (primaire ET
// secondaire) : élèves actifs de l'année dont il manque le matricule, le
// NIU, la photo, la date / le lieu de naissance ou le nom arabe (primaire
// seulement — pas de colonne au secondaire). Utilisé par l'onglet
// _eleves_incompletes.php et l'export fiches_incompletes_export.php.
require_once __DIR__ . '/_photos_lib.php';

// Informations contrôlables pour le type d'école courant (clé => libellé).
function fiches_champs(): array {
    $c = [
        'matricule'  => 'Matricule',
        'niu'        => 'NIU',
        'photo'      => 'Photo',
        'date_naiss' => 'Date de naissance',
        'lieu_naiss' => 'Lieu de naissance',
    ];
    if (!photos_est_secondaire()) $c['nom_arabe'] = 'Nom arabe';
    return $c;
}

// Niveaux : [ ['id' => …, 'nom' => …], … ] dans l'ordre habituel.
function fiches_niveaux(): array {
    if (photos_est_secondaire()) {
        return db_all("SELECT code_niveau AS id, COALESCE(NULLIF(libelle_niv,''), code_niveau) AS nom
                       FROM niveau ORDER BY ordre_niveau + 0, code_niveau");
    }
    return db_all("SELECT LibelleNiveau AS id, LibelleNiveau AS nom FROM niveau ORDER BY OrdreNiveau, LibelleNiveau");
}

// Classes (éventuellement limitées à un niveau) : [id, nom, niveau].
function fiches_classes(string $niveau = ''): array {
    if (photos_est_secondaire()) {
        return db_all("SELECT id, designation AS nom, code_niveau AS niveau FROM classe
                       WHERE archivee=0" . ($niveau !== '' ? " AND code_niveau=?" : '') . " ORDER BY ordre, designation",
                      $niveau !== '' ? [$niveau] : []);
    }
    return db_all("SELECT c.IDClasses AS id, c.DesignationClasses AS nom, c.Niveau AS niveau FROM classe c
                   LEFT JOIN niveau n ON n.LibelleNiveau=c.Niveau" . ($niveau !== '' ? " WHERE c.Niveau=?" : '') . "
                   ORDER BY n.OrdreNiveau, c.DesignationClasses",
                  $niveau !== '' ? [$niveau] : []);
}

function fiches_vide($v): bool {
    $v = trim((string) ($v ?? ''));
    return $v === '' || $v === '0000-00-00' || $v === '0000-00-00 00:00:00';
}

// Élèves actifs inscrits cette année (tous / un niveau / une classe), avec
// la liste des informations manquantes : [id, nom, mat, classe, niveau,
// manque => [clé, …]]. Tri : ordre des classes puis nom.
function fiches_eleves(string $niveau = '', int $id_classe = 0): array {
    $annee = get_annee_active();
    if (photos_est_secondaire()) {
        $w = ["e.statut='actif'"]; $p = [(int) ($annee['id'] ?? 0)];
        if ($niveau !== '') { $w[] = 'c.code_niveau=?'; $p[] = $niveau; }
        if ($id_classe)     { $w[] = 'c.id=?';          $p[] = $id_classe; }
        $rows = db_all(
            "SELECT e.id, e.matricule AS mat, e.nom, e.prenom, e.niu, e.photo, e.date_naiss, e.lieu_naiss,
                    c.designation AS classe, COALESCE(NULLIF(n.libelle_niv,''), c.code_niveau) AS niveau
             FROM eleve e JOIN inscription i ON i.id_eleve=e.id AND i.id_annee=?
             JOIN classe c ON c.id=i.id_classe LEFT JOIN niveau n ON n.code_niveau=c.code_niveau
             WHERE " . implode(' AND ', $w) . "
             ORDER BY n.ordre_niveau + 0, c.ordre, c.designation, e.nom, e.prenom", $p);
        $a_photo = fn($r) => !empty($r['photo']) && is_file(UPLOAD_DIR . $r['photo']);
    } else {
        $w = ["e.statut='actif'"]; $p = [$annee['val_annee'] ?? ''];
        if ($niveau !== '') { $w[] = 'c.Niveau=?';    $p[] = $niveau; }
        if ($id_classe)     { $w[] = 'c.IDClasses=?'; $p[] = $id_classe; }
        $rows = db_all(
            "SELECT e.id_eleve AS id, e.Mat_elv AS mat, e.Nom_elv AS nom, e.Prenom_elv AS prenom, e.niu,
                    (e.Photo_elv IS NOT NULL) AS a_photo, e.Date_naiss_elv AS date_naiss, e.Lieu_naiss_elv AS lieu_naiss,
                    e.Nom_arabe_elv AS nom_arabe, c.DesignationClasses AS classe, c.Niveau AS niveau
             FROM eleve e JOIN inscrire i ON i.id_eleve=e.id_eleve AND i.val_annee=?
             JOIN classe c ON c.IDClasses=i.IDClasses LEFT JOIN niveau n ON n.LibelleNiveau=c.Niveau
             WHERE " . implode(' AND ', $w) . "
             ORDER BY n.OrdreNiveau, c.DesignationClasses, e.Nom_elv, e.Prenom_elv", $p);
        $a_photo = fn($r) => (bool) $r['a_photo'];
    }
    $champs = fiches_champs();
    $out = [];
    foreach ($rows as $r) {
        $manque = [];
        foreach (array_keys($champs) as $k) {
            $vide = $k === 'photo' ? !$a_photo($r)
                  : fiches_vide($r[$k === 'matricule' ? 'mat' : $k] ?? null);
            if ($vide) $manque[] = $k;
        }
        $out[] = [
            'id' => (int) $r['id'], 'mat' => (string) ($r['mat'] ?? ''),
            'nom' => trim(mb_strtoupper((string) $r['nom']) . ' ' . ($r['prenom'] ?? '')),
            'classe' => (string) ($r['classe'] ?? ''), 'niveau' => (string) ($r['niveau'] ?? ''),
            'manque' => $manque,
        ];
    }
    return $out;
}

// Garde les élèves à qui il manque AU MOINS UNE ('un') ou TOUTES ('tous')
// les informations cochées.
function fiches_filtrer(array $eleves, array $champs, string $mode = 'un'): array {
    if (!$champs) return [];
    return array_values(array_filter($eleves, function ($e) use ($champs, $mode) {
        $n = count(array_intersect($champs, $e['manque']));
        return $mode === 'tous' ? $n === count($champs) : $n > 0;
    }));
}

// Paramètres communs (onglet + export) lus depuis $_GET.
function fiches_parametres(): array {
    $dispo  = array_keys(fiches_champs());
    // f=1 : formulaire soumis (toutes cases décochées = aucun critère) ;
    // sinon première ouverture = tous les critères cochés.
    $champs = array_values(array_intersect((array) ($_GET['champs'] ?? (isset($_GET['f']) ? [] : $dispo)), $dispo));
    return [
        'niveau' => trim((string) ($_GET['niveau'] ?? '')),
        'classe' => (int) ($_GET['classe'] ?? 0),
        'champs' => $champs,
        'mode'   => ($_GET['mode'] ?? '') === 'tous' ? 'tous' : 'un',
    ];
}
