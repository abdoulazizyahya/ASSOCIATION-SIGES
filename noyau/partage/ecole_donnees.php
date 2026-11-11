<?php
// noyau/partage/ecole_donnees.php — accès en lecture aux données de base de
// l'école courante, avec le MÊME format au primaire et au secondaire (les deux
// schémas diffèrent : eleve.id / id_eleve, inscription / inscrire, classe.id /
// IDClasses…). Utilisé par les modules communs (billets, couvertures, centre
// d'impression, analyse des performances — 10/11/2026).

function ed_secondaire(): bool {
    return function_exists('type_enseignement_courant') && type_enseignement_courant() === 'secondaire';
}

/** Année active : ['id' => int (secondaire) | 0, 'cle' => valeur pour les requêtes, 'libelle' => '2026-2027']. */
function ed_annee(): array {
    $a = get_annee_active();
    if (ed_secondaire()) return ['id' => (int) ($a['id'] ?? 0), 'cle' => (int) ($a['id'] ?? 0), 'libelle' => (string) ($a['libelle'] ?? '')];
    return ['id' => 0, 'cle' => (string) ($a['val_annee'] ?? ''), 'libelle' => (string) ($a['val_annee'] ?? '')];
}

/** Classes ayant des élèves inscrits cette année : [['id', 'libelle', 'nb', 'g', 'f']]. */
function ed_classes(): array {
    $an = ed_annee();
    if (ed_secondaire()) {
        return db_all("SELECT c.id, c.designation AS libelle, COUNT(e.id) AS nb,
                              SUM(e.sexe IN ('M','Masculin')) AS g, SUM(e.sexe IN ('F','Feminin','Féminin')) AS f
                       FROM classe c JOIN inscription i ON i.id_classe=c.id AND i.id_annee=?
                       JOIN eleve e ON e.id=i.id_eleve AND e.statut='actif'
                       GROUP BY c.id, c.designation ORDER BY c.libelle_section, c.ordre, c.designation", [$an['cle']]);
    }
    return db_all("SELECT c.IDClasses AS id, c.DesignationClasses AS libelle, COUNT(e.id_eleve) AS nb,
                          SUM(e.Sexe_elv LIKE 'M%') AS g, SUM(e.Sexe_elv LIKE 'F%') AS f
                   FROM classe c JOIN inscrire i ON i.IDClasses=c.IDClasses AND i.val_annee=?
                   JOIN eleve e ON e.id_eleve=i.id_eleve AND e.statut='actif'
                   LEFT JOIN niveau n ON n.LibelleNiveau=c.Niveau
                   GROUP BY c.IDClasses, c.DesignationClasses, n.OrdreNiveau ORDER BY n.OrdreNiveau, c.DesignationClasses", [$an['cle']]);
}

/**
 * Élèves actifs inscrits cette année (une classe, ou toutes si 0), même format :
 * ['id','nom','prenom','sexe' (M|F),'date_naiss','lieu_naiss','matricule','niu','id_classe','classe'].
 */
function ed_eleves(int $id_classe = 0): array {
    $an = ed_annee();
    if (ed_secondaire()) {
        $sql = "SELECT e.id, e.nom, e.prenom, CASE WHEN e.sexe IN ('F','Feminin','Féminin') THEN 'F' ELSE 'M' END AS sexe,
                       e.date_naiss, e.lieu_naiss, e.matricule, e.niu, c.id AS id_classe, c.designation AS classe
                FROM eleve e JOIN inscription i ON i.id_eleve=e.id AND i.id_annee=? JOIN classe c ON c.id=i.id_classe
                WHERE e.statut='actif'" . ($id_classe ? " AND c.id=?" : '') . " ORDER BY c.libelle_section, c.ordre, c.designation, e.nom, e.prenom";
        return db_all($sql, $id_classe ? [$an['cle'], $id_classe] : [$an['cle']]);
    }
    $sql = "SELECT e.id_eleve AS id, e.Nom_elv AS nom, e.Prenom_elv AS prenom, CASE WHEN e.Sexe_elv LIKE 'F%' THEN 'F' ELSE 'M' END AS sexe,
                   e.Date_naiss_elv AS date_naiss, e.Lieu_naiss_elv AS lieu_naiss, e.Mat_elv AS matricule, e.niu,
                   c.IDClasses AS id_classe, c.DesignationClasses AS classe
            FROM eleve e JOIN inscrire i ON i.id_eleve=e.id_eleve AND i.val_annee=? JOIN classe c ON c.IDClasses=i.IDClasses
            LEFT JOIN niveau n ON n.LibelleNiveau=c.Niveau
            WHERE e.statut='actif'" . ($id_classe ? " AND c.IDClasses=?" : '') . " ORDER BY n.OrdreNiveau, c.DesignationClasses, e.Nom_elv, e.Prenom_elv";
    return db_all($sql, $id_classe ? [$an['cle'], $id_classe] : [$an['cle']]);
}

/** Un élève (même format que ed_eleves), ou null. */
function ed_eleve(int $id): ?array {
    foreach (ed_eleves() as $e) if ((int) $e['id'] === $id) return $e;
    return null;
}

/** Personnel actif : [['id' => matricule_ens, 'nom', 'prenom', 'fonction', 'matricule' (officiel), 'tel']]. */
function ed_personnel(): array {
    // Documents imprimés : les comptes propriétaires (système) n'y figurent
    // JAMAIS, même quand c'est le propriétaire qui imprime (11/11/2026).
    $sys = function_exists('sql_hors_compte_systeme') ? ' AND ' . sql_hors_compte_systeme('e') : '';
    if (ed_secondaire()) {
        $actif = function_exists('sec_sql_ens_en_service') ? ' AND ' . sec_sql_ens_en_service('e') : '';
        $hors_sys = " AND NOT EXISTS (SELECT 1 FROM utilisateur u WHERE u.matricule_ens = e.matricule_ens AND u.compte_systeme = 1)";
        return db_all("SELECT e.matricule_ens AS id, e.nom_ens AS nom, e.prenom_ens AS prenom, e.id_fonction AS fonction,
                              e.matricule, e.tel_ens AS tel FROM enseignant e WHERE 1=1 $actif $hors_sys ORDER BY e.nom_ens, e.prenom_ens");
    }
    return db_all("SELECT e.matricule_ens AS id, e.nom_ens AS nom, e.prenom_ens AS prenom, e.id_fonction AS fonction,
                          e.mat_ens AS matricule, e.tel_ens AS tel FROM enseignant e
                   WHERE COALESCE(e.statut_ens, 'actif') = 'actif' $sys ORDER BY e.nom_ens, e.prenom_ens");
}

/** Nom affiché « NOM Prénom ». */
function ed_nom(array $p): string {
    return trim(mb_strtoupper((string) ($p['nom'] ?? '')) . ' ' . ($p['prenom'] ?? ''));
}

/** Identifiant affiché d'un élève : NIU, sinon matricule. */
function ed_ident(array $e): string {
    return trim((string) ($e['niu'] ?? '')) !== '' ? (string) $e['niu'] : (string) ($e['matricule'] ?? '');
}

/** Chef d'établissement (titre) pour les signatures : « Le Proviseur », « Le Directeur »… */
function ed_titre_chef(): string {
    $etab = etab_pour_pdf(get_etablissement());
    $t = trim((string) ($etab['chef_etablissement'] ?? ''));
    return $t !== '' ? $t : (ed_secondaire() ? 'Le Chef d\'établissement' : 'Le Directeur');
}

/** Photo d'un élève en fichier temporaire (JPEG / PNG lisibles par FPDF), ou null. */
function ed_photo_fichier(int $id): ?string {
    try {
        if (ed_secondaire()) {
            $f = photo_sec_fichier_tmp($id);
        } else {
            $f = photo_eleve_fichier_temp(db_val("SELECT Photo_elv FROM eleve WHERE id_eleve=?", [$id]), $id);
        }
    } catch (\Throwable $e) { return null; }
    if (!$f || !is_file($f)) return null;
    $t = @getimagesize($f);
    return ($t && in_array($t[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) ? $f : null;
}

/** Réglages d'un module enregistrés pour l'école courante (annuaire de l'association). */
function ed_reglages(string $module, array $defaut = []): array {
    $e = function_exists('ecole_courante') ? ecole_courante() : null;
    if (!$e || !function_exists('assoc_param')) return $defaut;
    $v = json_decode((string) assoc_param($module . '_' . $e['code'], ''), true);
    return is_array($v) ? $v + $defaut : $defaut;
}
function ed_reglages_enregistrer(string $module, array $valeurs): bool {
    $e = function_exists('ecole_courante') ? ecole_courante() : null;
    if (!$e || !function_exists('assoc_param_set')) return false;
    assoc_param_set($module . '_' . $e['code'], json_encode($valeurs, JSON_UNESCAPED_UNICODE), (string) ($_SESSION['user']['login'] ?? ''));
    return true;
}
