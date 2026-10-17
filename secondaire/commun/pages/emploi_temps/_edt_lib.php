<?php
// secondaire/commun/pages/emploi_temps/_edt_lib.php — cœur du module
// « Emploi du temps » (migration secondaire v17).
//
//  - grille horaire (edt_creneau) et jours ouvrés (edt_parametre) ;
//  - services : charge horaire classe × matière × enseignant (edt_service) ;
//  - séances : UNE ligne par période occupée (edt_seance) — une séance de
//    2 h = 2 lignes consécutives, regroupées en « blocs » pour l'affichage ;
//  - règles (edt_contrainte) contrôlées à la saisie, à l'import, à la
//    génération et dans « Conflits » ;
//  - générateur glouton multi-essais qui respecte les règles obligatoires
//    et minimise un score (règles souhaitées, trous, étalement).
//
// Conflits durs, toujours contrôlés : un enseignant, une classe (même
// sous-groupe) ou une salle à deux endroits au même moment. Exception : un
// COURS COMMUN (même code_commun) — une seule séance partagée par plusieurs
// classes, avec le même enseignant.
//
// PRIMAIRE (migration v63, 17/10/2026) : même module. Les tables du secondaire
// lues ici (classe, matiere, discipline…) sont remplacées par des sous-requêtes (edt_t())
// à travers edt_t() : cours = groupes de compétences + matières arabes
// (catalogue edt_matiere, id 1000+ = arabe), enseignant = « Affectation des
// classes » (enseignat_classe / enseignat_classe_arabe), année = 2026 pour 2026/2027.

const EDT_JOURS = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi'];
const EDT_JOURS_COURTS = [1 => 'Lun', 2 => 'Mar', 3 => 'Mer', 4 => 'Jeu', 5 => 'Ven', 6 => 'Sam'];
// Rôles des deux ordres (aucun rôle n'existe dans les deux) : DIRECTEUR = primaire.
const EDT_ROLES_ECRITURE = ['ADMIN', 'PROVISEUR', 'CENSEUR', 'DIRECTEUR'];
const EDT_ROLES_GESTION  = ['ADMIN', 'PROVISEUR', 'FONDATEUR', 'CENSEUR', 'SG', 'DIRECTEUR'];
const EDT_PRIM_ARABE = 1000;   // décalage des matières arabes dans edt_matiere / edt_t('discipline')

function edt_primaire(): bool {
    return function_exists('type_enseignement_courant') && type_enseignement_courant() !== 'secondaire';
}
/**
 * Source d'une table de référence : la table du secondaire, ou au primaire une
 * sous-requête qui présente ses données sous la même forme (s'utilise comme
 * « FROM " . edt_t('classe') . " c »). Pas de vues SQL : sur un serveur qui héberge
 * de nombreuses écoles, les requêtes préparées sur des vues échouent (MySQL 1615,
 * cache de définitions saturé).
 */
function edt_t(string $nom): string {
    static $prim = null;
    $prim ??= edt_primaire();
    if (!$prim) return $nom;
    $an = "(SELECT val_annee FROM annee_scolaire WHERE Etat_annee_scolaire = 1 LIMIT 1)";
    $ar = EDT_PRIM_ARABE;
    return match ($nom) {
        'matiere' => 'edt_matiere',
        'annee_scolaire' => "(SELECT CAST(LEFT(a.val_annee, 4) AS UNSIGNED) AS id, a.val_annee AS libelle, a.Etat_annee_scolaire AS active FROM annee_scolaire a)",
        'classe' => "(SELECT c.IDClasses AS id, c.DesignationClasses AS designation, c.Niveau AS code_niveau, NULL AS libelle_section,
                             NULL AS id_filiere, 0 AS archivee, COALESCE(n.OrdreNiveau, 99) AS ordre
                        FROM classe c LEFT JOIN niveau n ON n.LibelleNiveau = c.Niveau)",
        'niveau' => "(SELECT LibelleNiveau AS code_niveau, LibelleNiveau AS libelle_niv, 0 AS id_cycle, OrdreNiveau AS ordre_niveau FROM niveau)",
        'groupe' => "(SELECT id_groupe_comp, TRIM(libelle_groupe_comp) AS libelle_groupe_comp FROM groupe_competence
                      UNION ALL SELECT $ar + id_groupe, TRIM(nom_groupe_fr) FROM groupe_matiere_arabe)",
        // programme de la classe pour l'année active : disciplines de l'année, à défaut celui du niveau ; piste arabe décalée
        'discipline' => "(SELECT DISTINCT d.IDClasses, cp.id_groupe_comp AS id_mat, 1 AS coef, cp.id_groupe_comp AS id_groupe, g.ordre_affichage AS ordre
                            FROM discipline d JOIN competence cp ON cp.id_comp = d.id_comp JOIN groupe_competence g ON g.id_groupe_comp = cp.id_groupe_comp
                           WHERE d.actif = 1 AND d.annee_scol = $an
                          UNION
                          SELECT cl.IDClasses, gn.id_groupe_comp, 1, gn.id_groupe_comp, g.ordre_affichage
                            FROM classe cl JOIN groupe_competence_niveau gn ON gn.code_niveau = cl.Niveau AND gn.actif = 1
                            JOIN groupe_competence g ON g.id_groupe_comp = gn.id_groupe_comp
                           WHERE NOT EXISTS (SELECT 1 FROM discipline d2 WHERE d2.IDClasses = cl.IDClasses AND d2.actif = 1 AND d2.annee_scol = $an)
                          UNION
                          SELECT da.IDClasses, $ar + da.id_mat, 1, $ar + m.id_groupe, 100 + da.id_mat
                            FROM discipline_arabe da JOIN matiere_arabe m ON m.id_mat = da.id_mat
                           WHERE da.actif = 1 AND da.annee_scol = $an
                          UNION
                          SELECT cl.IDClasses, $ar + mn.id_mat, 1, $ar + m.id_groupe, 100 + mn.ordre
                            FROM classe cl JOIN matiere_niveau_arabe mn ON mn.code_niveau = cl.Niveau AND mn.actif = 1
                            JOIN matiere_arabe m ON m.id_mat = mn.id_mat
                           WHERE NOT EXISTS (SELECT 1 FROM discipline_arabe d3 WHERE d3.IDClasses = cl.IDClasses AND d3.actif = 1 AND d3.annee_scol = $an))",
        // enseignant « principal » de la classe = titulaire de la piste française
        'enseignat_principal' => "(SELECT matricule_ens, IDClasses, val_annee FROM enseignat_classe)",
        default => $nom,
    };
}
/** Où sont tenues les affectations enseignant ↔ classe (texte des pages). */
function edt_lib_affectations(): string {
    return edt_primaire() ? 'Affectation des classes' : 'module Matières';
}
/** Signatures du PDF (fiche) : Censeur + Proviseur / Principal au secondaire, Directeur au primaire. */
function edt_signataires(): array {
    if (edt_primaire()) return ['', 'Le Directeur'];
    return ['Le Censeur', (get_etablissement()['statut'] ?? 'public') === 'prive' ? 'Le Principal' : 'Le Proviseur'];
}
/** Établissement pour l'en-tête du PDF (header_pdf.php attend les colonnes du secondaire). */
function edt_etablissement_pdf(): array {
    $e = get_etablissement();
    if (!edt_primaire()) return $e;
    return $e + [
        'nom_fr' => $e['Nom_Etab_Fr'] ?? '', 'nom_en' => $e['Nom_Etab_An'] ?? '', 'sigle' => $e['Initial_Etab'] ?? '',
        'ville' => $e['ville_etab'] ?? '', 'region_fr' => $e['region_etab_fr'] ?? '', 'departement_fr' => $e['departement_fr'] ?? '',
        'arrondissement_fr' => $e['arrondissement_fr'] ?? '', 'region_en' => $e['region_etab_en'] ?? '',
        'division_en' => $e['departement_en'] ?? '', 'subdivision_en' => $e['arrondissement_en'] ?? '',
        'boite_postale' => $e['boite_postal'] ?? '', 'telephone' => $e['tel_etab'] ?? '', 'email' => $e['email_etab'] ?? '',
    ];
}
/** Primaire : ajoute au catalogue les groupes de compétences / matières arabes créés depuis (libellé long par défaut). */
function edt_prim_sync_matieres(): void {
    if (!edt_primaire()) return;
    try {
        db_exec("INSERT IGNORE INTO edt_matiere (id, libelle, libelle_long, source)
                 SELECT id_groupe_comp, LEFT(TRIM(libelle_groupe_comp), 40), TRIM(libelle_groupe_comp), 'fr' FROM groupe_competence");
        db_exec("INSERT IGNORE INTO edt_matiere (id, libelle, libelle_long, source)
                 SELECT " . EDT_PRIM_ARABE . " + id_mat, LEFT(TRIM(COALESCE(NULLIF(matiere_fr, ''), matiere_ar)), 40), TRIM(COALESCE(NULLIF(matiere_fr, ''), matiere_ar)), 'ar' FROM matiere_arabe");
    } catch (\Throwable $e) { /* lecture seule ou migration v63 absente */ }
}

// Types de règles : libellé, cibles possibles, paramètres attendus, aide
function edt_types_contraintes(): array {
    return [
        'plage_horaire' => [
            'libelle' => 'Plage horaire imposée', 'cibles' => ['matiere', 'groupe', 'classe', 'niveau', 'enseignant', 'tous'],
            'params' => ['debut' => 'Heure de début', 'fin' => 'Heure de fin'],
            'aide' => 'Les cours visés doivent se tenir entièrement dans cette plage (ex. Mathématiques en matinée : 07:30 → 12:40).',
        ],
        'creneaux_interdits' => [
            'libelle' => 'Créneaux interdits / indisponibilité', 'cibles' => ['tous', 'classe', 'niveau', 'enseignant', 'salle', 'matiere', 'groupe'],
            'params' => ['cases' => 'Créneaux (ex. Mer 7-9, Ven 1)'],
            'aide' => "Aucun cours visé sur ces créneaux : après-midi APPS, indisponibilité d'un enseignant, atelier fermé…",
        ],
        'max_heures_jour_matiere' => [
            'libelle' => "Heures max par jour d'une matière (par classe)", 'cibles' => ['tous', 'matiere', 'groupe', 'classe', 'niveau'],
            'params' => ['max' => 'Heures maximum'],
            'aide' => "Une matière n'occupe pas plus de N heures dans la même journée d'une classe.",
        ],
        'jours_non_consecutifs' => [
            'libelle' => 'Pas deux jours de suite (même matière)', 'cibles' => ['matiere', 'groupe', 'tous', 'classe', 'niveau'],
            'params' => [],
            'aide' => 'Une matière ne revient pas le lendemain dans la même classe : mieux réparti, pas tous les jours.',
        ],
        'max_jours_matiere' => [
            'libelle' => "Nombre max de jours d'une matière dans la semaine", 'cibles' => ['matiere', 'groupe', 'tous', 'classe', 'niveau'],
            'params' => ['max' => 'Jours maximum'],
            'aide' => 'Évite le saupoudrage : la matière est regroupée sur au plus N jours.',
        ],
        'max_heures_jour_enseignant' => [
            'libelle' => "Heures max par jour d'un enseignant", 'cibles' => ['tous', 'enseignant'],
            'params' => ['max' => 'Heures maximum'],
            'aide' => '',
        ],
        'max_heures_consecutives_enseignant' => [
            'libelle' => "Heures consécutives max d'un enseignant", 'cibles' => ['tous', 'enseignant'],
            'params' => ['max' => 'Heures maximum'],
            'aide' => 'Nombre maximum de périodes enchaînées sans interruption (les pauses ne coupent pas la série).',
        ],
        'max_jours_enseignant' => [
            'libelle' => 'Jours de présence max d\'un enseignant', 'cibles' => ['tous', 'enseignant'],
            'params' => ['max' => 'Jours maximum'],
            'aide' => 'Regroupe le service sur N jours (vacataires, enseignants partagés avec un autre établissement).',
        ],
        'max_heures_jour_classe' => [
            'libelle' => "Heures max par jour d'une classe", 'cibles' => ['tous', 'classe', 'niveau'],
            'params' => ['max' => 'Heures maximum'],
            'aide' => '',
        ],
    ];
}

function edt_libelle_cible_type(string $t): string {
    return ['tous' => 'Tout l\'établissement', 'matiere' => 'Matière', 'groupe' => 'Groupe de matières', 'enseignant' => 'Enseignant',
            'classe' => 'Classe', 'niveau' => 'Niveau', 'salle' => 'Salle'][$t] ?? $t;
}

// ── Accès / année ──────────────────────────────────────────────────────
function edt_peut_ecrire(): bool {
    return in_array(role_connecte(), EDT_ROLES_ECRITURE, true) && !(function_exists('est_lecture_seule') && est_lecture_seule());
}
function edt_annee_id(): int {
    // primaire : pas d'id d'année, on prend la première année du libellé (2026/2027 → 2026), comme edt_t('annee_scolaire')
    if (edt_primaire()) return (int) substr((string) (get_annee_active()['val_annee'] ?? ''), 0, 4);
    return (int) (get_annee_active()['id'] ?? 0);
}

function edt_param(int $a, string $cle, $defaut = null) {
    $v = db_val("SELECT valeur FROM edt_parametre WHERE id_annee=? AND cle=?", [$a, $cle]);
    return $v === null ? $defaut : $v;
}
function edt_param_set(int $a, string $cle, ?string $valeur): void {
    db_exec("INSERT INTO edt_parametre (id_annee, cle, valeur) VALUES (?,?,?) ON DUPLICATE KEY UPDATE valeur=VALUES(valeur)", [$a, $cle, $valeur]);
}
function edt_jours_ouvres(int $a): array {
    $j = array_values(array_filter(array_map('intval', explode(',', (string) edt_param($a, 'jours', '1,2,3,4,5'))), fn($x) => $x >= 1 && $x <= 6));
    return $j ?: [1, 2, 3, 4, 5];
}
function edt_publie(int $a): bool { return edt_param($a, 'publie', '0') === '1'; }

// Grille par défaut : celle des emplois du temps du Lycée technique de Mbé
function edt_grille_defaut(): array {
    return [
        ['1ère H', '07:30', '08:20', 'cours'], ['2ème H', '08:20', '09:10', 'cours'], ['3ème H', '09:10', '10:00', 'cours'],
        ['Récréation', '10:00', '10:10', 'pause'],
        ['4ème H', '10:10', '11:00', 'cours'], ['5ème H', '11:00', '11:50', 'cours'], ['6ème H', '11:50', '12:40', 'cours'],
        ['Pause', '12:40', '13:00', 'pause'],
        ['7ème H', '13:00', '13:50', 'cours'], ['8ème H', '13:50', '14:40', 'cours'], ['9ème H', '14:40', '15:30', 'cours'],
    ];
}

/** Toutes les périodes de l'année (cours + pauses), ordonnées ; grille par défaut créée au premier accès. */
function edt_creneaux(int $a): array {
    static $cache = [];
    if (isset($cache[$a])) return $cache[$a];
    $rows = db_all("SELECT * FROM edt_creneau WHERE id_annee=? ORDER BY ordre, heure_debut", [$a]);
    if (!$rows && $a && edt_peut_ecrire_silencieux()) {
        foreach (edt_grille_defaut() as $i => [$lib, $d, $f, $t]) {
            db_exec("INSERT INTO edt_creneau (id_annee, ordre, libelle, heure_debut, heure_fin, type) VALUES (?,?,?,?,?,?)", [$a, $i + 1, $lib, $d, $f, $t]);
        }
        $rows = db_all("SELECT * FROM edt_creneau WHERE id_annee=? ORDER BY ordre, heure_debut", [$a]);
    }
    $rang = 0;
    foreach ($rows as &$r) {
        $r['rang'] = $r['type'] === 'cours' ? ++$rang : 0;
        $r['debut'] = substr($r['heure_debut'], 0, 5);
        $r['fin'] = substr($r['heure_fin'], 0, 5);
    }
    unset($r);
    return $cache[$a] = $rows;
}
function edt_peut_ecrire_silencieux(): bool {
    try { return !(function_exists('est_lecture_seule') && est_lecture_seule()); } catch (\Throwable $e) { return false; }
}
/** Périodes de cours seulement : [id => créneau], dans l'ordre. */
function edt_creneaux_cours(int $a): array {
    $out = [];
    foreach (edt_creneaux($a) as $c) if ($c['type'] === 'cours') $out[(int) $c['id']] = $c;
    return $out;
}
/** rang (1..n) → id de créneau */
function edt_rang_vers_id(int $a): array {
    $m = [];
    foreach (edt_creneaux_cours($a) as $id => $c) $m[$c['rang']] = $id;
    return $m;
}

// « Mer 7-9, Ven 1 » ⇄ liste de [jour, rang]
function edt_parser_cases(string $txt): array {
    $jours = ['lun' => 1, 'mar' => 2, 'mer' => 3, 'jeu' => 4, 'ven' => 5, 'sam' => 6];
    $out = [];
    foreach (preg_split('/[,;]+/', $txt) as $part) {
        $part = trim(str_replace('?', '', mb_strtolower($part)));
        if (!preg_match('/^(lun|mar|mer|jeu|ven|sam)\w*\.?\s*(\d+)(?:\s*[-àa]\s*(\d+))?/u', $part, $m)) continue;
        $j = $jours[$m[1]];
        $d = (int) $m[2]; $f = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : $d;
        if ($f < $d) [$d, $f] = [$f, $d];
        for ($r = $d; $r <= $f && $r <= 20; $r++) $out[] = [$j, $r];
    }
    return $out;
}
function edt_formater_cases(array $cases): string {
    $par = [];
    foreach ($cases as [$j, $r]) $par[$j][] = $r;
    ksort($par);
    $txt = [];
    foreach ($par as $j => $rs) {
        sort($rs); $rs = array_values(array_unique($rs));
        $deb = $prev = $rs[0];
        $seg = [];
        foreach (array_slice($rs, 1) as $r) {
            if ($r === $prev + 1) { $prev = $r; continue; }
            $seg[] = $deb === $prev ? "$deb" : "$deb-$prev"; $deb = $prev = $r;
        }
        $seg[] = $deb === $prev ? "$deb" : "$deb-$prev";
        foreach ($seg as $s) $txt[] = EDT_JOURS_COURTS[$j] . " $s";
    }
    return implode(', ', $txt);
}

// ── Référentiels ───────────────────────────────────────────────────────
function edt_classes(): array {
    return db_all("SELECT c.id, c.designation, c.code_niveau, c.libelle_section, c.id_filiere FROM " . edt_t('classe') . " c WHERE c.archivee=0 ORDER BY c.ordre, c.designation");
}
function edt_enseignants(): array {
    if (edt_primaire())   // primaire : pas de « matière enseignée » ; ni fiches inactives ni compte propriétaire
        return db_all("SELECT e.matricule_ens, TRIM(CONCAT(e.nom_ens,' ',IFNULL(e.prenom_ens,''))) AS nom, e.tel_ens, e.id_grade, NULL AS matiere_enseignee
                       FROM enseignant e WHERE COALESCE(e.statut_ens, 'actif') = 'actif' ORDER BY e.nom_ens, e.prenom_ens");
    return db_all("SELECT e.matricule_ens, TRIM(CONCAT(e.nom_ens,' ',IFNULL(e.prenom_ens,''))) AS nom, e.tel_ens, e.id_grade, e.matiere_enseignee
                   FROM enseignant e ORDER BY e.nom_ens, e.prenom_ens");
}
function edt_salles(bool $actives = true): array {
    return db_all("SELECT * FROM edt_salle " . ($actives ? "WHERE actif=1 " : '') . "ORDER BY nom");
}
/** Matières du programme d'une classe (table discipline) avec groupe et coefficient. */
function edt_programme_classe(int $id_classe): array {
    return db_all("SELECT d.id_mat, TRIM(m.libelle) libelle, d.coef, d.id_groupe, g.libelle_groupe_comp groupe
                   FROM " . edt_t('discipline') . " d JOIN " . edt_t('matiere') . " m ON m.id=d.id_mat LEFT JOIN " . edt_t('groupe') . " g ON g.id_groupe_comp=d.id_groupe
                   WHERE d.IDClasses=?" . (edt_primaire() ? ' AND m.actif=1' : '') . " ORDER BY d.id_groupe, d.ordre+0, m.libelle", [$id_classe]);
}
function edt_couleur(int $id): string {
    $pal = ['#dbeafe', '#dcfce7', '#fef3c7', '#fce7f3', '#e0e7ff', '#ccfbf1', '#ffedd5', '#ede9fe', '#fee2e2', '#e0f2fe', '#ecfccb', '#f5f5f4', '#fae8ff', '#d1fae5'];
    return $pal[$id % count($pal)];
}
function edt_nom_court(?string $nom): string {
    $nom = trim((string) $nom);
    if ($nom === '') return '—';
    $p = preg_split('/\s+/', $nom);
    return count($p) <= 2 ? $nom : $p[0] . ' ' . $p[1];
}

// ── Normalisation des libellés (import, rapprochements) ────────────────
function edt_norm(?string $s): string {
    $s = trim((string) $s);
    // texte encodé deux fois en UTF-8 (ex. « FAÃGA » pour « FAÏGA » dans d'anciennes fiches) : on le répare avant de comparer
    if (preg_match('/[ÃÂ]/u', $s)) { $r = @mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8'); if ($r !== false && $r !== $s && mb_check_encoding($r, 'UTF-8')) $s = $r; }
    $s = mb_strtolower($s, 'UTF-8');
    if (class_exists('Normalizer')) $s = preg_replace('/\p{Mn}+/u', '', \Normalizer::normalize($s, \Normalizer::FORM_D));
    $s = str_replace('œ', 'oe', $s);
    return trim(preg_replace('/[^a-z0-9]+/', ' ', $s));
}

// =====================================================================
//  MODÈLE EN MÉMOIRE : occupations + règles → contrôle d'un placement
// =====================================================================
final class EdtModele {
    public int $annee;
    public array $cours = [];       // id créneau => créneau (rang, debut, fin)
    public array $rangId = [];      // rang => id
    public array $jours = [];
    public array $classes = [];     // id => [designation, code_niveau]
    public array $mat = [];         // id => libelle
    public array $groupeCm = [];    // "classe|mat" => libellé de groupe
    public array $ens = [];         // matricule => nom
    public array $salles = [];      // id => nom
    public array $regles = [];
    /** séances par id */
    public array $seances = [];
    // index d'occupation
    private array $occC = [];       // classe => jour => creneau => [ids]
    private array $occE = [];       // ens => jour => creneau => [ids]
    private array $occS = [];       // salle => jour => creneau => [ids]

    public function __construct(int $annee, bool $chargerSeances = true) {
        $this->annee = $annee;
        $this->cours = edt_creneaux_cours($annee);
        foreach ($this->cours as $id => $c) $this->rangId[$c['rang']] = $id;
        $this->jours = edt_jours_ouvres($annee);
        foreach (edt_classes() as $c) $this->classes[(int) $c['id']] = $c;
        foreach (db_all("SELECT id, TRIM(libelle) libelle FROM " . edt_t('matiere') . "") as $m) $this->mat[(int) $m['id']] = $m['libelle'];
        foreach (db_all("SELECT d.IDClasses, d.id_mat, g.libelle_groupe_comp FROM " . edt_t('discipline') . " d LEFT JOIN " . edt_t('groupe') . " g ON g.id_groupe_comp=d.id_groupe") as $d)
            $this->groupeCm[$d['IDClasses'] . '|' . $d['id_mat']] = (string) $d['libelle_groupe_comp'];
        foreach (edt_enseignants() as $e) $this->ens[(int) $e['matricule_ens']] = $e['nom'];
        foreach (edt_salles(false) as $s) $this->salles[(int) $s['id']] = $s['nom'];
        $this->regles = db_all("SELECT * FROM edt_contrainte WHERE id_annee=? AND actif=1", [$annee]);
        foreach ($this->regles as &$r) {
            $r['p'] = json_decode((string) $r['params'], true) ?: [];
            if ($r['type'] === 'creneaux_interdits') {
                $r['cases'] = [];
                foreach (edt_parser_cases((string) ($r['p']['cases'] ?? '')) as [$j, $rg]) if (isset($this->rangId[$rg])) $r['cases'][$j][$this->rangId[$rg]] = true;
            }
        }
        unset($r);
        if ($chargerSeances) {
            foreach (db_all("SELECT * FROM edt_seance WHERE id_annee=?", [$annee]) as $s) $this->ajouter($s);
        }
    }

    public function ajouter(array $s): void {
        $id = $s['id'] ?? ('n' . count($this->seances) . '_' . mt_rand());
        $s['id'] = $id;
        foreach (['id_classe', 'jour', 'id_creneau', 'id_mat'] as $k) $s[$k] = (int) $s[$k];
        $s['matricule_ens'] = $s['matricule_ens'] !== null && $s['matricule_ens'] !== '' ? (int) $s['matricule_ens'] : null;
        $s['id_salle'] = !empty($s['id_salle']) ? (int) $s['id_salle'] : null;
        $s['sous_groupe'] = (string) ($s['sous_groupe'] ?? '');
        $s['code_commun'] = ($s['code_commun'] ?? '') !== '' ? (string) $s['code_commun'] : null;
        $this->seances[$id] = $s;
        $this->occC[$s['id_classe']][$s['jour']][$s['id_creneau']][] = $id;
        if ($s['matricule_ens']) $this->occE[$s['matricule_ens']][$s['jour']][$s['id_creneau']][] = $id;
        if ($s['id_salle']) $this->occS[$s['id_salle']][$s['jour']][$s['id_creneau']][] = $id;
    }
    public function retirer($id): void {
        $s = $this->seances[$id] ?? null;
        if (!$s) return;
        $rm = function (array &$idx, $k) use ($s, $id) {
            if ($k === null || !isset($idx[$k][$s['jour']][$s['id_creneau']])) return;
            $idx[$k][$s['jour']][$s['id_creneau']] = array_values(array_filter($idx[$k][$s['jour']][$s['id_creneau']], fn($x) => $x !== $id));
        };
        $rm($this->occC, $s['id_classe']); $rm($this->occE, $s['matricule_ens']); $rm($this->occS, $s['id_salle']);
        unset($this->seances[$id]);
    }

    public function idsClasse(int $c, int $j, int $cr): array { return $this->occC[$c][$j][$cr] ?? []; }
    public function idsEns(int $e, int $j, int $cr): array { return $this->occE[$e][$j][$cr] ?? []; }

    /** La règle s'applique-t-elle à ce cours ? */
    public function regleVise(array $r, int $classe, int $mat, ?int $ens, ?int $salle): bool {
        $id = (string) $r['cible_id'];
        switch ($r['cible_type']) {
            case 'tous': return true;
            case 'matiere': return (string) $mat === $id;
            case 'groupe': return edt_norm($this->groupeCm["$classe|$mat"] ?? '') === edt_norm($id);
            case 'enseignant': return $ens !== null && (string) $ens === $id;
            case 'classe': return (string) $classe === $id;
            case 'niveau': return ($this->classes[$classe]['code_niveau'] ?? '') === $id;
            case 'salle': return $salle !== null && (string) $salle === $id;
        }
        return false;
    }

    /**
     * Problèmes posés par un bloc (une classe, une matière, un enseignant,
     * un jour, des créneaux) si on le plaçait. $ignorer : ids de séances à
     * ne pas compter (le bloc lui-même quand on contrôle l'existant).
     * Retour : liste de ['niveau' => 'conflit'|'obligatoire'|'souhaitee', 'type' => ..., 'msg' => ...].
     */
    public function problemes(array $b, array $ignorer = []): array {
        $c = (int) $b['id_classe']; $m = (int) $b['id_mat']; $j = (int) $b['jour'];
        $e = !empty($b['matricule_ens']) ? (int) $b['matricule_ens'] : null;
        $sa = !empty($b['id_salle']) ? (int) $b['id_salle'] : null;
        $sg = (string) ($b['sous_groupe'] ?? ''); $cc = ($b['code_commun'] ?? '') !== '' ? (string) $b['code_commun'] : null;
        $crs = array_map('intval', $b['creneaux']);
        $ign = array_flip(array_map('strval', $ignorer));
        $out = [];
        $nomJ = EDT_JOURS[$j] ?? "J$j";

        foreach ($crs as $cr) {
            $lib = $nomJ . ' ' . ($this->cours[$cr]['libelle'] ?? '');
            foreach ($this->idsClasse($c, $j, $cr) as $id) {
                if (isset($ign[(string) $id])) continue;
                $o = $this->seances[$id];
                if ($sg !== '' && $o['sous_groupe'] !== '' && $o['sous_groupe'] !== $sg) continue;   // demi-groupes
                $out[] = ['niveau' => 'conflit', 'type' => 'classe', 'msg' => "Classe déjà occupée $lib (" . ($this->mat[$o['id_mat']] ?? '?') . ')'];
            }
            if ($e) foreach ($this->idsEns($e, $j, $cr) as $id) {
                if (isset($ign[(string) $id])) continue;
                $o = $this->seances[$id];
                if ($cc !== null && $o['code_commun'] === $cc) continue;                            // cours commun
                if ($o['id_classe'] === $c && $sg !== '' && $o['sous_groupe'] !== $sg) { /* même classe autre groupe : il ne peut pas être aux deux */ }
                $out[] = ['niveau' => 'conflit', 'type' => 'enseignant', 'msg' => ($this->ens[$e] ?? 'Enseignant') . " déjà en " . ($this->classes[$o['id_classe']]['designation'] ?? '?') . " $lib (" . ($this->mat[$o['id_mat']] ?? '?') . ')'];
            }
            if ($sa) foreach ($this->occS[$sa][$j][$cr] ?? [] as $id) {
                if (isset($ign[(string) $id])) continue;
                $o = $this->seances[$id];
                if ($cc !== null && $o['code_commun'] === $cc) continue;
                $out[] = ['niveau' => 'conflit', 'type' => 'salle', 'msg' => ($this->salles[$sa] ?? 'Salle') . " déjà occupée $lib (" . ($this->classes[$o['id_classe']]['designation'] ?? '?') . ')'];
            }
        }

        foreach ($this->regles as $r) {
            if (!$this->regleVise($r, $c, $m, $e, $sa)) continue;
            $niv = $r['force'] === 'souhaitee' ? 'souhaitee' : 'obligatoire';
            $p = $r['p'];
            $msg = null;
            switch ($r['type']) {
                case 'plage_horaire':
                    $deb = substr((string) ($p['debut'] ?? '00:00'), 0, 5); $fin = substr((string) ($p['fin'] ?? '23:59'), 0, 5);
                    foreach ($crs as $cr) if (($this->cours[$cr]['debut'] ?? '') < $deb || ($this->cours[$cr]['fin'] ?? '') > $fin) { $msg = "hors de la plage $deb – $fin"; break; }
                    break;
                case 'creneaux_interdits':
                    foreach ($crs as $cr) if (!empty($r['cases'][$j][$cr])) { $msg = "créneau interdit ($nomJ " . ($this->cours[$cr]['libelle'] ?? '') . ')'; break; }
                    break;
                case 'max_heures_jour_matiere':
                    $n = count($crs);
                    foreach ($this->occC[$c][$j] ?? [] as $cr => $ids) foreach ($ids as $id) {
                        if (isset($ign[(string) $id]) || in_array($cr, $crs, true)) continue;
                        if ($this->seances[$id]['id_mat'] === $m && ($sg === '' || $this->seances[$id]['sous_groupe'] === $sg || $this->seances[$id]['sous_groupe'] === '')) $n++;
                    }
                    if ($n > (int) ($p['max'] ?? 2)) $msg = "$n h de " . ($this->mat[$m] ?? 'cette matière') . " le $nomJ (max " . (int) ($p['max'] ?? 2) . ')';
                    break;
                case 'jours_non_consecutifs':
                    foreach ([$j - 1, $j + 1] as $jj) {
                        foreach ($this->occC[$c][$jj] ?? [] as $ids) foreach ($ids as $id) {
                            if (!isset($ign[(string) $id]) && $this->seances[$id]['id_mat'] === $m) { $msg = ($this->mat[$m] ?? 'Matière') . ' aussi le ' . (EDT_JOURS[$jj] ?? '') . ' (jours consécutifs)'; break 3; }
                        }
                    }
                    break;
                case 'max_jours_matiere':
                    $js = [$j => true];
                    foreach ($this->occC[$c] ?? [] as $jj => $parCr) foreach ($parCr as $ids) foreach ($ids as $id)
                        if (!isset($ign[(string) $id]) && $this->seances[$id]['id_mat'] === $m) $js[$jj] = true;
                    if (count($js) > (int) ($p['max'] ?? 5)) $msg = ($this->mat[$m] ?? 'Matière') . ' répartie sur ' . count($js) . ' jours (max ' . (int) $p['max'] . ')';
                    break;
                case 'max_heures_jour_enseignant':
                    if (!$e) break;
                    $set = array_flip($crs);
                    foreach ($this->occE[$e][$j] ?? [] as $cr => $ids) {
                        $vrais = array_filter($ids, fn($id) => !isset($ign[(string) $id]));
                        if ($vrais) $set[$cr] = true;
                    }
                    if (count($set) > (int) ($p['max'] ?? 6)) $msg = ($this->ens[$e] ?? 'Enseignant') . ' : ' . count($set) . " h le $nomJ (max " . (int) $p['max'] . ')';
                    break;
                case 'max_heures_consecutives_enseignant':
                    if (!$e) break;
                    $occ = array_flip($crs);
                    foreach ($this->occE[$e][$j] ?? [] as $cr => $ids) if (array_filter($ids, fn($id) => !isset($ign[(string) $id]))) $occ[$cr] = true;
                    $serie = $max = 0;
                    foreach ($this->rangId as $rg => $cr) { $serie = isset($occ[$cr]) ? $serie + 1 : 0; $max = max($max, $serie); }
                    if ($max > (int) ($p['max'] ?? 4)) $msg = ($this->ens[$e] ?? 'Enseignant') . " : $max h d'affilée le $nomJ (max " . (int) $p['max'] . ')';
                    break;
                case 'max_jours_enseignant':
                    if (!$e) break;
                    $js = [$j => true];
                    foreach ($this->occE[$e] ?? [] as $jj => $parCr) foreach ($parCr as $ids) if (array_filter($ids, fn($id) => !isset($ign[(string) $id]))) $js[$jj] = true;
                    if (count($js) > (int) ($p['max'] ?? 5)) $msg = ($this->ens[$e] ?? 'Enseignant') . ' présent ' . count($js) . ' jours (max ' . (int) $p['max'] . ')';
                    break;
                case 'max_heures_jour_classe':
                    $set = array_flip($crs);
                    foreach ($this->occC[$c][$j] ?? [] as $cr => $ids) if (array_filter($ids, fn($id) => !isset($ign[(string) $id]))) $set[$cr] = true;
                    if (count($set) > (int) ($p['max'] ?? 9)) $msg = ($this->classes[$c]['designation'] ?? 'Classe') . ' : ' . count($set) . " h le $nomJ (max " . (int) $p['max'] . ')';
                    break;
            }
            if ($msg !== null) $out[] = ['niveau' => $niv, 'type' => $r['type'], 'msg' => (edt_types_contraintes()[$r['type']]['libelle'] ?? $r['type']) . ' : ' . $msg, 'id_regle' => (int) $r['id']];
        }
        return $out;
    }

    /** Blocs (séances contiguës identiques) de toutes les séances, ou filtrés. */
    public function blocs(?callable $filtre = null): array {
        $rows = array_values(array_filter($this->seances, $filtre ?? fn() => true));
        usort($rows, fn($a, $b) => [$a['id_classe'], $a['sous_groupe'], $a['jour'], $this->cours[$a['id_creneau']]['rang'] ?? 0]
                                 <=> [$b['id_classe'], $b['sous_groupe'], $b['jour'], $this->cours[$b['id_creneau']]['rang'] ?? 0]);
        $blocs = []; $cur = null;
        foreach ($rows as $s) {
            $rg = $this->cours[$s['id_creneau']]['rang'] ?? 0;
            $cle = implode('|', [$s['id_classe'], $s['sous_groupe'], $s['jour'], $s['id_mat'], $s['matricule_ens'], $s['id_salle'], $s['code_commun']]);
            if ($cur && $cur['cle'] === $cle && $rg === $cur['dernier_rang'] + 1) {
                $cur['creneaux'][] = $s['id_creneau']; $cur['ids'][] = $s['id']; $cur['dernier_rang'] = $rg; $cur['verrou'] = $cur['verrou'] || $s['verrou'];
                continue;
            }
            if ($cur) $blocs[] = $cur;
            $cur = $s + ['cle' => $cle, 'creneaux' => [$s['id_creneau']], 'ids' => [$s['id']], 'dernier_rang' => $rg, 'premier_rang' => $rg];
            $cur['verrou'] = (bool) $s['verrou'];
        }
        if ($cur) $blocs[] = $cur;
        return $blocs;
    }
}

// =====================================================================
//  CONTRÔLE GLOBAL (page Conflits, tableau de bord)
// =====================================================================
function edt_controle(int $a, ?EdtModele $mod = null): array {
    $mod ??= new EdtModele($a);
    $res = ['conflit' => [], 'obligatoire' => [], 'souhaitee' => []];
    $vus = [];
    foreach ($mod->blocs() as $b) {
        foreach ($mod->problemes($b, $b['ids']) as $p) {
            // un conflit enseignant/salle apparaît des deux côtés : on ne le garde qu'une fois
            $cle = $p['niveau'] . '|' . $p['type'] . '|' . $b['jour'] . '|' . implode(',', $b['creneaux']) . '|' . ($p['type'] === 'enseignant' ? $b['matricule_ens'] : ($p['type'] === 'salle' ? $b['id_salle'] : $b['id_classe'] . '|' . $p['msg']));
            // règles « par journée / par semaine » : un seul signalement par enseignant, classe ou matière (le message les identifie)
            if (in_array($p['type'], ['max_heures_jour_enseignant', 'max_heures_consecutives_enseignant', 'max_jours_enseignant', 'max_heures_jour_classe', 'max_jours_matiere', 'max_heures_jour_matiere'], true))
                $cle = $p['niveau'] . '|' . $p['type'] . '|' . ($p['type'] === 'max_heures_jour_matiere' || $p['type'] === 'max_jours_matiere' ? $b['id_classe'] . '|' : '') . $p['msg'];
            if (isset($vus[$cle])) continue;
            $vus[$cle] = true;
            $res[$p['niveau']][] = $p + ['bloc' => $b];
        }
    }
    return $res;
}

/** Services de l'année, avec heures placées. */
function edt_services(int $a, ?int $id_classe = null, ?int $ens = null): array {
    $w = ['s.id_annee=?']; $p = [$a];
    if ($id_classe) { $w[] = 's.id_classe=?'; $p[] = $id_classe; }
    if ($ens) { $w[] = 's.matricule_ens=?'; $p[] = $ens; }
    return db_all("SELECT s.*, TRIM(m.libelle) matiere, c.designation classe, TRIM(CONCAT(e.nom_ens,' ',IFNULL(e.prenom_ens,''))) enseignant, sa.nom salle,
                     (SELECT COUNT(*) FROM edt_seance q WHERE q.id_annee=s.id_annee AND q.id_classe=s.id_classe AND q.id_mat=s.id_mat
                        AND (q.matricule_ens<=>s.matricule_ens)) AS places
                   FROM edt_service s JOIN " . edt_t('matiere') . " m ON m.id=s.id_mat JOIN " . edt_t('classe') . " c ON c.id=s.id_classe
                   LEFT JOIN enseignant e ON e.matricule_ens=s.matricule_ens LEFT JOIN edt_salle sa ON sa.id=s.id_salle
                   WHERE " . implode(' AND ', $w) . " ORDER BY c.ordre, m.libelle", $p);
}

/** Heures attribuées par enseignant (cours communs comptés une fois) et heures placées. */
function edt_charges_enseignants(int $a): array {
    $out = [];
    foreach (db_all("SELECT matricule_ens, heures, code_commun FROM edt_service WHERE id_annee=? AND matricule_ens IS NOT NULL", [$a]) as $s) {
        $e = (int) $s['matricule_ens'];
        if ($s['code_commun']) { if (isset($out[$e]['communs'][$s['code_commun']])) continue; $out[$e]['communs'][$s['code_commun']] = true; }
        $out[$e]['attribuees'] = ($out[$e]['attribuees'] ?? 0) + (float) $s['heures'];
    }
    foreach (db_all("SELECT matricule_ens, COUNT(DISTINCT jour, id_creneau) n FROM edt_seance WHERE id_annee=? AND matricule_ens IS NOT NULL GROUP BY matricule_ens", [$a]) as $r)
        $out[(int) $r['matricule_ens']]['placees'] = (int) $r['n'];
    return $out;
}

/** Recopie l'enseignant principal de chaque service dans `dispenser` (affectations utilisées par les notes). */
function edt_synchroniser_dispenser(int $a): int {
    $annee = db_one("SELECT libelle FROM " . edt_t('annee_scolaire') . " an WHERE an.id=?", [$a]);
    if (!$annee) return 0;
    $n = 0;
    $rows = db_all("SELECT id_classe, id_mat, matricule_ens, SUM(heures) h FROM edt_service WHERE id_annee=? AND matricule_ens IS NOT NULL
                    GROUP BY id_classe, id_mat, matricule_ens ORDER BY id_classe, id_mat, h DESC", [$a]);
    $vu = [];
    foreach ($rows as $r) {
        $k = $r['id_classe'] . '|' . $r['id_mat'];
        if (isset($vu[$k])) continue;
        $vu[$k] = true;
        if (edt_primaire()) {
            // primaire : « Affectation des classes » — piste française ou arabe selon le cours
            $t = (int) $r['id_mat'] >= EDT_PRIM_ARABE ? 'enseignat_classe_arabe' : 'enseignat_classe';
            if (!db_val("SELECT 1 FROM $t WHERE matricule_ens=? AND IDClasses=? AND val_annee=?", [$r['matricule_ens'], $r['id_classe'], $annee['libelle']])) {
                db_exec("INSERT INTO $t (matricule_ens, IDClasses, val_annee) VALUES (?,?,?)", [$r['matricule_ens'], $r['id_classe'], $annee['libelle']]);
                $n++;
            }
            continue;
        }
        $n += db_exec("INSERT INTO dispenser (matricule_ens, IDClasses, id_mat, val_annee) VALUES (?,?,?,?)
                       ON DUPLICATE KEY UPDATE matricule_ens=VALUES(matricule_ens)", [$r['matricule_ens'], $r['id_classe'], $r['id_mat'], $annee['libelle']]) > 0 ? 1 : 0;
    }
    return $n;
}

// =====================================================================
//  GÉNÉRATEUR
// =====================================================================
/** Découpe un volume horaire en séances : blocs de duree_max, le reste au moins duree_min. */
function edt_decouper(int $h, int $dmin, int $dmax): array {
    $dmax = max(1, $dmax); $dmin = max(1, min($dmin, $dmax));
    $blocs = [];
    while ($h > 0) {
        $t = min($dmax, $h);
        if ($h - $t > 0 && $h - $t < $dmin) $t = max($dmin, $h - $dmin);
        $blocs[] = $t; $h -= $t;
    }
    return $blocs;
}

/**
 * Génère l'emploi du temps des classes $classes.
 * $opt : effacer (bool, séances non verrouillées des classes visées), essais (int), duree (s).
 * Retour : ['places' => int, 'non_places' => [...], 'score' => float, 'essai' => int, 'seances' => [...]]
 */
function edt_generer(int $a, array $classes, array $opt = []): array {
    $essais = max(1, (int) ($opt['essais'] ?? 8));
    $duree  = max(5, (int) ($opt['duree'] ?? 40));
    $effacer = !empty($opt['effacer']);
    $classes = array_map('intval', $classes);
    $setC = array_flip($classes);
    $t0 = microtime(true);

    $base = new EdtModele($a);
    $aSupprimer = [];
    if ($effacer) foreach ($base->seances as $id => $s) if (isset($setC[$s['id_classe']]) && !$s['verrou']) { $aSupprimer[] = $id; $base->retirer($id); }

    // Demandes restantes par service (heures - déjà placées), cours communs regroupés
    $demandes = [];
    $communsVus = [];
    $prePlaces = [];
    foreach (db_all("SELECT s.*, c.code_niveau FROM edt_service s JOIN " . edt_t('classe') . " c ON c.id=s.id_classe WHERE s.id_annee=? AND s.heures>0", [$a]) as $s) {
        if (!isset($setC[(int) $s['id_classe']])) continue;
        $groupe = [(int) $s['id_classe']];
        if ($s['code_commun']) {
            if (isset($communsVus[$s['code_commun']])) continue;
            $communsVus[$s['code_commun']] = true;
            $groupe = array_map('intval', array_column(db_all("SELECT id_classe FROM edt_service WHERE id_annee=? AND code_commun=?", [$a, $s['code_commun']]), 'id_classe'));
            // Cours commun avec une classe HORS du périmètre déjà construite : ses créneaux font foi,
            // on les reprend pour les classes régénérées au lieu d'en chercher de nouveaux.
            $horsPerimetre = array_filter($groupe, fn($c) => !isset($setC[$c]));
            $fixes = [];
            foreach ($base->seances as $q) if ($q['code_commun'] === $s['code_commun'] && !isset($setC[$q['id_classe']])) $fixes[$q['jour'] . '|' . $q['id_creneau']] = $q;
            if ($horsPerimetre && $fixes) {
                $sids = [];
                foreach (db_all("SELECT id, id_classe FROM edt_service WHERE id_annee=? AND code_commun=?", [$a, $s['code_commun']]) as $x) $sids[(int) $x['id_classe']] = (int) $x['id'];
                foreach ($groupe as $c) {
                    if (!isset($setC[$c])) continue;
                    foreach ($fixes as $q) {
                        $dejaLa = false;
                        foreach ($base->idsClasse($c, $q['jour'], $q['id_creneau']) as $id) { $dejaLa = true; break; }
                        if ($dejaLa) continue;
                        $n = ['id_annee' => $a, 'id_classe' => $c, 'sous_groupe' => '', 'jour' => $q['jour'], 'id_creneau' => $q['id_creneau'], 'id_service' => $sids[$c] ?? null,
                              'id_mat' => $q['id_mat'], 'matricule_ens' => $q['matricule_ens'], 'id_salle' => $q['id_salle'], 'code_commun' => $q['code_commun'], 'verrou' => 0, 'origine' => 'auto'];
                        $base->ajouter($n);
                        $prePlaces[] = $n;
                    }
                }
                continue;
            }
            // jamais d'écriture hors du périmètre : les autres classes du cours commun reprendront
            // ces créneaux quand elles seront générées à leur tour
            $groupe = array_values(array_filter($groupe, fn($c) => isset($setC[$c])));
        }
        $deja = 0;
        foreach ($base->seances as $q) if ($q['id_classe'] === (int) $s['id_classe'] && $q['id_mat'] === (int) $s['id_mat'] && $q['matricule_ens'] === ($s['matricule_ens'] !== null ? (int) $s['matricule_ens'] : null)) $deja++;
        $reste = (int) round((float) $s['heures']) - $deja;
        if ($reste <= 0) continue;
        foreach (edt_decouper($reste, (int) $s['duree_min'], (int) $s['duree_max']) as $t) {
            $demandes[] = ['service' => (int) $s['id'], 'classes' => $groupe, 'id_mat' => (int) $s['id_mat'],
                           'ens' => $s['matricule_ens'] !== null ? (int) $s['matricule_ens'] : null, 'salle' => $s['id_salle'] ? (int) $s['id_salle'] : null,
                           'commun' => $s['code_commun'] ?: null, 'duree' => $t];
        }
    }
    // Charge par enseignant (pour trier les plus contraints d'abord)
    $charge = [];
    foreach ($demandes as $d) if ($d['ens']) $charge[$d['ens']] = ($charge[$d['ens']] ?? 0) + $d['duree'] * count($d['classes']);
    $rangs = array_keys($base->rangId);
    $nbR = count($rangs);

    $meilleur = null;
    for ($essai = 1; $essai <= $essais; $essai++) {
        if (microtime(true) - $t0 > $duree && $meilleur) break;
        $mod = clone $base;
        $ordre = $demandes;
        foreach ($ordre as &$d) $d['tri'] = $d['duree'] * 10 + count($d['classes']) * 6 + ($charge[$d['ens']] ?? 0) * 0.4 + mt_rand(0, 100) / 25;
        unset($d);
        usort($ordre, fn($x, $y) => $y['tri'] <=> $x['tri']);
        $places = $prePlaces; $nonPlaces = []; $scoreTotal = 0.0;
        foreach ($ordre as $d) {
            $best = null; $raisons = [];
            foreach ($mod->jours as $j) {
                for ($i = 0; $i + $d['duree'] <= $nbR; $i++) {
                    $crs = [];
                    for ($k = 0; $k < $d['duree']; $k++) $crs[] = $mod->rangId[$rangs[$i + $k]];
                    $ok = true; $soft = 0;
                    foreach ($d['classes'] as $c) {
                        $b = ['id_classe' => $c, 'id_mat' => $d['id_mat'], 'matricule_ens' => $d['ens'], 'id_salle' => $d['salle'], 'jour' => $j, 'creneaux' => $crs, 'code_commun' => $d['commun']];
                        foreach ($mod->problemes($b) as $p) {
                            if ($p['niveau'] === 'souhaitee') { $soft++; continue; }
                            $ok = false; $raisons[$p['type']] = ($raisons[$p['type']] ?? 0) + 1;
                            break;
                        }
                        if (!$ok) break;
                    }
                    if (!$ok) continue;
                    $score = $soft * 8 + edt_score_placement($mod, $d, $j, $crs) + mt_rand(0, 150) / 100;
                    if ($best === null || $score < $best[0]) $best = [$score, $j, $crs];
                }
            }
            if ($best === null) {
                arsort($raisons);
                $nonPlaces[] = $d + ['raison' => $raisons ? edt_libelle_raison((string) array_key_first($raisons)) : 'aucun créneau libre'];
                continue;
            }
            [$score, $j, $crs] = $best;
            $scoreTotal += $score;
            foreach ($d['classes'] as $c) foreach ($crs as $cr) {
                $s = ['id_annee' => $a, 'id_classe' => $c, 'sous_groupe' => '', 'jour' => $j, 'id_creneau' => $cr, 'id_service' => $d['service'],
                      'id_mat' => $d['id_mat'], 'matricule_ens' => $d['ens'], 'id_salle' => $d['salle'], 'code_commun' => $d['commun'], 'verrou' => 0, 'origine' => 'auto'];
                $mod->ajouter($s);
                $places[] = $s;
            }
        }
        $hNon = array_sum(array_map(fn($d) => $d['duree'] * count($d['classes']), $nonPlaces));
        $cand = ['places' => count($places), 'h_non_placees' => $hNon, 'non_places' => $nonPlaces, 'score' => $scoreTotal, 'essai' => $essai, 'seances' => $places];
        if ($meilleur === null || $hNon < $meilleur['h_non_placees'] || ($hNon === $meilleur['h_non_placees'] && $scoreTotal < $meilleur['score'])) $meilleur = $cand;
        if ($hNon === 0 && $essai >= 3) break;
    }
    $meilleur['supprimees'] = $aSupprimer;
    $meilleur['essais_faits'] = $essai > $essais ? $essais : $essai;
    return $meilleur ?? ['places' => 0, 'h_non_placees' => 0, 'non_places' => [], 'score' => 0, 'essai' => 0, 'seances' => [], 'supprimees' => []];
}

function edt_libelle_raison(string $t): string {
    return ['classe' => 'la classe est déjà occupée sur tous les créneaux possibles', 'enseignant' => "l'enseignant n'est libre sur aucun créneau compatible",
            'salle' => 'la salle est occupée', 'plage_horaire' => 'plage horaire trop restreinte', 'creneaux_interdits' => 'créneaux interdits',
            'max_heures_jour_matiere' => 'maximum d\'heures par jour atteint', 'jours_non_consecutifs' => 'règle « pas deux jours de suite »',
            'max_jours_matiere' => 'nombre de jours de la matière', 'max_heures_jour_enseignant' => "maximum journalier de l'enseignant",
            'max_heures_consecutives_enseignant' => "heures consécutives de l'enseignant", 'max_jours_enseignant' => "jours de présence de l'enseignant",
            'max_heures_jour_classe' => 'maximum journalier de la classe'][$t] ?? $t;
}

/** Score d'un placement (plus bas = meilleur) : étalement, trous, matinée pour le général, fin de journée. */
function edt_score_placement(EdtModele $mod, array $d, int $j, array $crs): float {
    $s = 0.0;
    $rgs = array_map(fn($cr) => $mod->cours[$cr]['rang'], $crs);
    foreach ($d['classes'] as $c) {
        // même matière déjà ce jour-là / la veille / le lendemain
        foreach ([$j => 12, $j - 1 => 3, $j + 1 => 3] as $jj => $pen)
            foreach ($mod->rangId as $cr) foreach ($mod->idsClasse($c, $jj, $cr) as $id) if ($mod->seances[$id]['id_mat'] === $d['id_mat']) { $s += $pen; break 2; }
        // trous dans la journée de la classe
        $occ = [];
        foreach ($mod->rangId as $rg => $cr) if ($mod->idsClasse($c, $j, $cr)) $occ[] = $rg;
        $avant = edt_trous($occ); $apres = edt_trous(array_merge($occ, $rgs));
        $s += ($apres - $avant) * 4;
        if (!$occ) $s += 1.5;                      // ouvrir une journée vide coûte un peu (journées équilibrées)
        // matières générales : de préférence le matin
        $g = edt_norm($mod->groupeCm["$c|{$d['id_mat']}"] ?? '');
        if (str_contains($g, 'general') && max($rgs) >= 7) $s += 2.5;
        if (str_contains($g, 'professionnel') && min($rgs) <= 3 && $d['duree'] <= 2) $s += 0.5;
    }
    // trous de l'enseignant
    if ($d['ens']) {
        $occ = [];
        foreach ($mod->rangId as $rg => $cr) if ($mod->idsEns($d['ens'], $j, $cr)) $occ[] = $rg;
        $s += (edt_trous(array_merge($occ, $rgs)) - edt_trous($occ)) * 1.2;
    }
    $s += max(0, max($rgs) - 8) * 0.8;             // fin de journée
    return $s;
}
function edt_trous(array $rgs): int {
    if (count($rgs) < 2) return 0;
    $rgs = array_unique($rgs);
    return (max($rgs) - min($rgs) + 1) - count($rgs);
}

/** Enregistre le résultat d'une génération. */
function edt_enregistrer_generation(int $a, array $res): void {
    foreach (array_chunk(array_values(array_filter($res['supprimees'], 'is_numeric')), 200) as $lot)
        db_exec("DELETE FROM edt_seance WHERE id_annee=? AND verrou=0 AND id IN (" . implode(',', array_map('intval', $lot)) . ")", [$a]);
    foreach ($res['seances'] as $s) {
        db_exec("INSERT INTO edt_seance (id_annee,id_classe,sous_groupe,jour,id_creneau,id_service,id_mat,matricule_ens,id_salle,code_commun,verrou,origine)
                 VALUES (?,?,?,?,?,?,?,?,?,?,0,'auto') ON DUPLICATE KEY UPDATE id_mat=VALUES(id_mat), matricule_ens=VALUES(matricule_ens), id_service=VALUES(id_service),
                 id_salle=VALUES(id_salle), code_commun=VALUES(code_commun), origine='auto'",
                [$a, $s['id_classe'], '', $s['jour'], $s['id_creneau'], $s['id_service'], $s['id_mat'], $s['matricule_ens'], $s['id_salle'], $s['code_commun']]);
    }
}

// =====================================================================
//  RENDU HTML DE LA GRILLE (consultation, saisie)
// =====================================================================
/**
 * $vue : 'classe' | 'enseignant' | 'salle' ; $id : identifiant correspondant.
 * $opt : editable (bool), sous_groupe.
 */
function edt_grille_html(int $a, string $vue, int $id, array $opt = []): string {
    $mod = $opt['modele'] ?? new EdtModele($a);
    $creneaux = edt_creneaux($a);
    $jours = edt_jours_ouvres($a);
    $editable = !empty($opt['editable']);
    $filtre = match ($vue) {
        'classe' => fn($s) => $s['id_classe'] === $id,
        'enseignant' => fn($s) => $s['matricule_ens'] === $id,
        default => fn($s) => $s['id_salle'] === $id,
    };
    $blocs = $mod->blocs($filtre);
    // Un bloc ne traverse pas une pause à l'affichage : il est coupé en segments.
    $segments = []; $couvert = [];
    foreach ($blocs as $b) {
        $seg = []; $prev = null;
        foreach ($b['creneaux'] as $k => $cr) {
            $rg = $mod->cours[$cr]['rang'];
            if ($prev !== null && edt_pause_entre($creneaux, $prev, $rg)) { $segments[] = ['bloc' => $b, 'cases' => $seg]; $seg = []; }
            $seg[] = ['cr' => $cr, 'id' => $b['ids'][$k]]; $prev = $rg;
        }
        if ($seg) $segments[] = ['bloc' => $b, 'cases' => $seg];
    }
    $parCase = [];
    foreach ($segments as $sg) {
        $cases = $sg['cases']; $b = $sg['bloc'];
        $parCase[$b['jour'] . '|' . $cases[0]['cr']][] = ['bloc' => $b, 'n' => count($cases), 'ids' => array_column($cases, 'id')];
        foreach (array_slice($cases, 1) as $c) $couvert[$b['jour'] . '|' . $c['cr']] = true;
    }
    // créneaux interdits pour tous (affichés grisés : APPS…)
    $bloques = [];
    foreach ($mod->regles as $r) if ($r['type'] === 'creneaux_interdits' && in_array($r['cible_type'], ['tous', 'classe', 'niveau'], true)
        && ($r['cible_type'] === 'tous' || ($vue === 'classe' && $mod->regleVise($r, $id, 0, null, null)))) {
        foreach ($r['cases'] as $j => $crs) foreach ($crs as $cr => $_) $bloques["$j|$cr"] = $r['commentaire'] ?: 'Bloqué';
    }

    $h = '<div class="table-responsive"><table class="table table-bordered edt-grille mb-0"><thead><tr><th class="edt-coin">Jour \\ Heure</th>';
    foreach ($creneaux as $c) {
        if ($c['type'] === 'pause') { $h .= '<th class="edt-pause-th" title="' . h($c['libelle']) . '">' . h(mb_substr($c['libelle'], 0, 1)) . '</th>'; continue; }
        $h .= '<th><div class="fw-bold">' . h($c['libelle']) . '</div><div class="small text-muted">' . h($c['debut'] . '-' . $c['fin']) . '</div></th>';
    }
    $h .= '</tr></thead><tbody>';
    foreach ($jours as $ji => $j) {
        $h .= '<tr><th class="edt-jour">' . h(EDT_JOURS[$j]) . '</th>';
        foreach ($creneaux as $c) {
            $cr = (int) $c['id'];
            if ($c['type'] === 'pause') {
                if ($ji === 0) $h .= '<td class="edt-pause" rowspan="' . count($jours) . '"><span>' . h(mb_strtoupper($c['libelle'])) . '</span></td>';
                continue;
            }
            $k = "$j|$cr";
            if (isset($couvert[$k])) continue;
            if (!empty($parCase[$k])) {
                $items = $parCase[$k];
                $n = max(array_column($items, 'n'));
                // Deux cours au même moment (hors cours commun / demi-groupes) : conflit signalé en rouge
                $communs = array_unique(array_map(fn($it) => $it['bloc']['code_commun'] ?: ('x' . $it['bloc']['id']), $items));
                $enConflit = count($items) > 1 && count($communs) > 1 && !($vue === 'classe' && count(array_filter($items, fn($it) => $it['bloc']['sous_groupe'] !== '')) === count($items));
                $h .= '<td colspan="' . $n . '" class="edt-cours' . ($enConflit ? ' edt-conflit' : '') . '" data-jour="' . $j . '" data-cr="' . $cr . '"'
                    . ($enConflit ? ' title="Conflit : plusieurs cours au même moment"' : '') . '>';
                foreach ($items as $it) {
                    $b = $it['bloc'];
                    $titre = h($mod->mat[$b['id_mat']] ?? '?');
                    $sous = match ($vue) {
                        'classe' => h(edt_nom_court($mod->ens[$b['matricule_ens']] ?? '')),
                        'enseignant' => h($mod->classes[$b['id_classe']]['designation'] ?? '?'),
                        default => h(($mod->classes[$b['id_classe']]['designation'] ?? '?') . ' · ' . edt_nom_court($mod->ens[$b['matricule_ens']] ?? '')),
                    };
                    $extra = ($b['id_salle'] && $vue !== 'salle' ? '<div class="edt-salle"><i class="bi bi-geo-alt"></i> ' . h($mod->salles[$b['id_salle']] ?? '') . '</div>' : '')
                           . ($b['code_commun'] ? '<div class="edt-commun" title="Cours commun"><i class="bi bi-people"></i> commun</div>' : '')
                           . ($b['sous_groupe'] !== '' ? '<div class="edt-salle">Gr. ' . h($b['sous_groupe']) . '</div>' : '');
                    $h .= '<div class="edt-bloc' . ($b['verrou'] ? ' edt-verrou' : '') . '" style="background:' . edt_couleur($b['id_mat']) . '"'
                        . ($editable ? ' data-ids="' . h(implode(',', $it['ids'])) . '" role="button"' : '') . '>'
                        . ($b['verrou'] ? '<i class="bi bi-lock-fill edt-cadenas" title="Verrouillé"></i>' : '')
                        . '<div class="edt-mat">' . $titre . '</div><div class="edt-sous">' . $sous . '</div>' . $extra . '</div>';
                }
                $h .= '</td>';
            } elseif (isset($bloques[$k])) {
                $h .= '<td class="edt-bloque" data-jour="' . $j . '" data-cr="' . $cr . '" title="' . h($bloques[$k]) . '"><span>' . h($bloques[$k]) . '</span></td>';
            } else {
                $h .= '<td class="edt-vide' . ($editable ? ' edt-cliquable' : '') . '" data-jour="' . $j . '" data-cr="' . $cr . '"></td>';
            }
        }
        $h .= '</tr>';
    }
    $h .= '</tbody></table></div>';
    return $h;
}
function edt_pause_entre(array $creneaux, int $rg1, int $rg2): bool {
    $vu = false;
    foreach ($creneaux as $c) {
        if ($c['type'] === 'cours' && $c['rang'] === $rg1) { $vu = true; continue; }
        if ($vu && $c['type'] === 'pause') return true;
        if ($vu && $c['type'] === 'cours' && $c['rang'] === $rg2) return false;
    }
    return false;
}

function edt_css(): string {
    return <<<CSS
<style>
.edt-grille{font-size:.78rem;table-layout:fixed;background:#fff}
.edt-grille th{background:#f1f5f9;text-align:center;vertical-align:middle}
.edt-grille .edt-coin{width:92px;background:#1f3a5f;color:#fff}
.edt-grille .edt-jour{background:#1f3a5f;color:#fff;width:92px}
.edt-grille td{height:62px;padding:2px;vertical-align:top}
.edt-grille .edt-pause-th{width:22px;padding:0;font-size:.7rem}
.edt-grille .edt-pause{width:22px;background:#e2e8f0;padding:0;vertical-align:middle;text-align:center}
.edt-grille .edt-pause span{writing-mode:vertical-rl;transform:rotate(180deg);font-weight:700;letter-spacing:.15em;font-size:.68rem;color:#475569}
.edt-bloc{border-radius:6px;padding:3px 5px;height:100%;min-height:54px;position:relative;border:1px solid rgba(0,0,0,.08);margin-bottom:2px}
.edt-bloc[role=button]:hover{outline:2px solid #1f3a5f;cursor:pointer}
.edt-mat{font-weight:700;line-height:1.15}
.edt-sous{color:#334155;line-height:1.15}
.edt-salle,.edt-commun{font-size:.68rem;color:#64748b}
.edt-verrou{border:2px dashed #7c3aed}
.edt-cadenas{position:absolute;top:2px;right:4px;font-size:.7rem;color:#7c3aed}
.edt-bloque{background:repeating-linear-gradient(45deg,#f1f5f9,#f1f5f9 6px,#e2e8f0 6px,#e2e8f0 12px);text-align:center;vertical-align:middle!important;color:#64748b;font-size:.7rem;font-weight:600}
.edt-cliquable:hover{background:#eef2ff;cursor:pointer}
.edt-dispo-ok{background:#dcfce7!important}
.edt-dispo-souhaitee{background:#fef3c7!important}
.edt-dispo-conflit{background:#fee2e2!important}
.edt-kpi{border-left:4px solid #1f3a5f}
.edt-conflit{background:#fee2e2;outline:2px solid #dc2626;outline-offset:-2px}
@media print{.no-print{display:none!important}}
</style>
CSS;
}

/** Libellé d'un service ou d'un bloc pour les listes. */
function edt_bloc_libelle(EdtModele $mod, array $b): string {
    return ($mod->classes[$b['id_classe']]['designation'] ?? '?') . ' — ' . ($mod->mat[$b['id_mat']] ?? '?') . ' — ' . (EDT_JOURS[$b['jour']] ?? '') . ' '
        . implode(', ', array_map(fn($cr) => $mod->cours[$cr]['libelle'] ?? '', $b['creneaux']));
}

/** Règles recommandées pour un lycée technique (chargées à la demande). */
function edt_regles_recommandees(int $a): int {
    $n = 0;
    $add = function (string $type, string $ct, ?string $cid, array $p, string $force, string $com) use ($a, &$n) {
        $existe = db_val("SELECT COUNT(*) FROM edt_contrainte WHERE id_annee=? AND type=? AND cible_type=? AND cible_id<=>?", [$a, $type, $ct, $cid]);
        if ($existe) return;
        db_exec("INSERT INTO edt_contrainte (id_annee,type,cible_type,cible_id,params,`force`,actif,commentaire) VALUES (?,?,?,?,?,?,1,?)",
                [$a, $type, $ct, $cid, json_encode($p, JSON_UNESCAPED_UNICODE), $force, $com]);
        $n++;
    };
    $add('creneaux_interdits', 'tous', null, ['cases' => 'Mer 7-9'], 'obligatoire', 'A.P.P.S.');
    foreach (db_all("SELECT id FROM " . edt_t('matiere') . " WHERE actif=1 AND (libelle LIKE 'Math%' OR libelle LIKE 'Sciences physiques%' OR libelle LIKE 'Physique%' OR libelle LIKE 'PCT%' OR libelle LIKE 'Mathématique%')") as $m)
        $add('plage_horaire', 'matiere', (string) $m['id'], ['debut' => '07:30', 'fin' => '12:40'], 'obligatoire', 'Matière fondamentale en matinée');
    $add('max_heures_jour_matiere', 'groupe', 'ENSEIGNEMENT GÉNÉRAL', ['max' => 2], 'obligatoire', 'Enseignement général : 2 h maximum par jour');
    $add('jours_non_consecutifs', 'groupe', 'ENSEIGNEMENT GÉNÉRAL', [], 'souhaitee', 'Matières générales réparties sur des jours non consécutifs');
    $add('max_heures_jour_enseignant', 'tous', null, ['max' => 6], 'obligatoire', '6 h de cours maximum par jour');
    $add('max_heures_consecutives_enseignant', 'tous', null, ['max' => 4], 'souhaitee', '4 h d\'affilée au plus');
    $add('max_heures_jour_classe', 'tous', null, ['max' => 9], 'obligatoire', 'Journée de 9 périodes au plus');
    return $n;
}

// ── Navigation : 3 étapes, chacune avec ses onglets (pas de doublon avec le menu latéral) ──
//   Préparation  : grille, règles, salles, services, heures dues, import, année
//   Construction : saisie manuelle, génération automatique, contrôles
//   Consulter / imprimer : onglets propres à consulter.php
const EDT_ETAPES = [
    'preparation' => ['parametres.php', [
        'grille'   => ['Grille horaire', 'clock', 'parametres.php?onglet=grille'],
        'cours'    => ['Cours', 'journal-bookmark', 'parametres.php?onglet=cours'],   // primaire seulement : libellés courts (edt_matiere)
        'regles'   => ['Règles', 'list-check', 'parametres.php?onglet=regles'],
        'salles'   => ['Salles', 'building', 'parametres.php?onglet=salles'],
        'services' => ['Services', 'person-lines-fill', 'services.php?onglet=classe'],
        'heures'   => ['Heures dues', 'hourglass-split', 'services.php?onglet=enseignants'],
        'import'   => ['Importer Excel', 'file-earmark-arrow-up', 'import.php'],
        'annee'    => ['Année', 'calendar-range', 'parametres.php?onglet=annee'],
    ]],
    'construction' => ['saisie.php', [
        'saisie'    => ['Saisie manuelle', 'pencil-square', 'saisie.php'],
        'generer'   => ['Génération automatique', 'magic', 'generer.php'],
        'controles' => ['Contrôles', 'clipboard-check', 'conflits.php'],
    ]],
];
// Entrée du menu latéral à mettre en surbrillance (lue par lien_actif(), noyau/layout/header.php)
(function () {
    $f = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $etape = in_array($f, ['parametres.php', 'services.php', 'import.php'], true) ? 'preparation'
           : (in_array($f, ['saisie.php', 'generer.php', 'conflits.php', 'index.php'], true) ? 'construction' : null);
    if ($etape) $GLOBALS['menu_lien_actif'] = 'secondaire/commun/pages/emploi_temps/' . EDT_ETAPES[$etape][0];
})();

/** Titre de page + onglets de l'étape. */
function edt_onglets(string $etape, string $actif): string {
    edt_prim_sync_matieres();   // primaire : nouveaux groupes de compétences / matières arabes ajoutés au catalogue
    $titres = ['preparation' => ['Préparation', 'sliders'], 'construction' => ['Construction', 'magic']];
    [$titre, $ico] = $titres[$etape];
    $h = '<div class="page-titre"><h4><i class="bi bi-' . $ico . ' me-1 text-primary"></i>Emploi du temps — ' . $titre
       . ' <small class="text-muted fs-6">Année ' . h(get_annee_active()['val_annee']) . '</small></h4></div>';
    $h .= '<ul class="nav nav-tabs mb-3 no-print">';
    $n = 0;
    foreach (EDT_ETAPES[$etape][1] as $k => [$lib, $i, $url]) {
        if ($k === 'cours' && !edt_primaire()) continue;
        $n++;
        $num = $etape === 'preparation' && $k !== 'annee' && $k !== 'import' ? '<span class="badge rounded-pill bg-light text-dark border me-1">' . $n . '</span>' : '';
        $h .= '<li class="nav-item"><a class="nav-link' . ($k === $actif ? ' active' : '') . '" href="' . $url . '">' . $num . '<i class="bi bi-' . $i . ' me-1"></i>' . h($lib) . '</a></li>';
    }
    return $h . '</ul>';
}

/**
 * Carte « Emploi du temps » de la fiche du personnel (Ressources humaines → voir.php) :
 * discipline, grade, heures dues / faites / supplémentaires, classes et matières avec
 * leurs créneaux, grille de la semaine, liens vers la consultation et le PDF.
 */
function edt_fiche_enseignant_html(int $mat): string {
    $a = edt_annee_id();
    if (!$a) return '';
    $base = APP_URL . '/secondaire/commun/pages/emploi_temps/';
    $d = db_one("SELECT * FROM edt_enseignant WHERE id_annee=? AND matricule_ens=?", [$a, $mat]) ?? [];
    $svc = edt_services($a, null, $mat);
    $mod = new EdtModele($a);
    $faites = (int) db_val("SELECT COUNT(DISTINCT jour, id_creneau) FROM edt_seance WHERE id_annee=? AND matricule_ens=?", [$a, $mat]);
    $charge = edt_charges_enseignants($a)[$mat]['attribuees'] ?? 0;
    $dues = isset($d['heures_dues']) && $d['heures_dues'] !== null ? (float) $d['heures_dues'] : null;
    $fmt = fn($v) => rtrim(rtrim(number_format((float) $v, 1, ',', ''), '0'), ',') ?: '0';
    $annee = get_annee_active()['val_annee'];
    $h = '<div class="card mb-2"><div class="card-body">'
       . '<div class="section-titre d-flex align-items-center flex-wrap gap-2"><span><i class="bi bi-calendar3-week me-1"></i>Emploi du temps ' . h($annee) . '</span>'
       . (edt_publie($a) ? '<span class="badge bg-success">publié</span>' : '<span class="badge bg-secondary">en préparation</span>')
       . '<span class="ms-auto d-flex gap-1"><a class="btn btn-sm btn-outline-primary" href="' . $base . 'consulter.php?vue=enseignant&id=' . $mat . '"><i class="bi bi-eye"></i> Consulter</a>'
       . '<a class="btn btn-sm btn-primary" target="_blank" href="' . $base . 'pdf.php?vue=enseignant&id=' . $mat . '"><i class="bi bi-file-earmark-pdf"></i> PDF</a></span></div>';
    if (!$svc && !$faites) return $h . '<p class="text-muted small mb-0">Aucun cours dans l\'emploi du temps de cette année.</p></div></div>';
    $h .= '<div class="row g-2 mb-2" style="font-size:.85rem">';
    foreach ([['Discipline', $d['discipline'] ?? '—'], ['Grade (emploi du temps)', $d['grade'] ?? '—'], ['Heures dues', $dues !== null ? $fmt($dues) . ' h' : '—'],
              ['Heures attribuées', $fmt($charge) . ' h'], ['Heures faites (placées)', $faites . ' h'],
              ['Heures supplémentaires', $dues !== null ? ($faites > $dues ? $fmt($faites - $dues) . ' h' : '0 h') : '—']] as [$l, $v])
        $h .= '<div class="col-6 col-md-4"><span class="text-muted">' . h($l) . ' :</span> <b>' . h((string) $v) . '</b></div>';
    $h .= '</div>';
    // classes × matières × créneaux
    $cases = [];
    foreach ($mod->seances as $s) if ($s['matricule_ens'] === $mat) $cases[$s['id_classe'] . '|' . $s['id_mat']][] = [$s['jour'], $mod->cours[$s['id_creneau']]['rang'] ?? 0];
    $h .= '<div class="table-responsive"><table class="table table-sm mb-2" style="font-size:.8rem"><thead class="table-light"><tr><th>Classe</th><th>Matière</th><th>H / sem.</th><th>Créneaux</th></tr></thead><tbody>';
    $vus = [];
    foreach ($svc as $s) {
        $k = $s['id_classe'] . '|' . $s['id_mat']; $vus[$k] = true;
        $h .= '<tr><td class="fw-semibold">' . h($s['classe']) . '</td><td>' . h($s['matiere']) . ($s['code_commun'] ? ' <span class="badge bg-light text-dark border">commun</span>' : '') . '</td><td>' . $fmt($s['heures'])
            . '</td><td>' . h(edt_formater_cases($cases[$k] ?? [])) . '</td></tr>';
    }
    foreach ($cases as $k => $cs) if (!isset($vus[$k])) {
        [$c, $m] = explode('|', $k);
        $h .= '<tr><td class="fw-semibold">' . h($mod->classes[(int) $c]['designation'] ?? '?') . '</td><td>' . h($mod->mat[(int) $m] ?? '?') . '</td><td>' . count($cs) . '</td><td>' . h(edt_formater_cases($cs)) . '</td></tr>';
    }
    $h .= '</tbody></table></div>';
    $h .= '<details><summary class="small text-primary" style="cursor:pointer">Voir la grille de la semaine</summary><div class="mt-2">' . edt_css() . edt_grille_html($a, 'enseignant', $mat, ['modele' => $mod]) . '</div></details>';
    return $h . '</div></div>';
}
