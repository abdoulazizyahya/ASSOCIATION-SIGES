<?php
// pages/eleves/_matricules_lib.php — gestion des matricules des élèves du
// PRIMAIRE (onglet « Matricules » de la liste des élèves, 03/10/2026) :
//   - élèves actifs SANS matricule (saisie oubliée en mode manuel, import…)
//     -> génération selon le format défini (matricule_config) ou saisie ;
//   - matricules EN DOUBLE (comparés sans espaces ni casse : « 23m005 » et
//     « 23M005 » sont le même) -> le plus ancien élève garde le sien, les
//     autres reçoivent un nouveau matricule.
// Les données de l'élève (notes, paiements, absences…) sont reliées par
// id_eleve, jamais par le matricule : en changer ne casse rien — seuls les
// QR déjà imprimés (bulletins, cartes) qui l'intègrent sont à réimprimer.

/** Clé de comparaison d'un matricule : sans espaces, en majuscules. */
function matricule_cle(?string $m): string {
    return mb_strtoupper(preg_replace('/\s+/u', '', (string) $m));
}

/** Élèves ACTIFS sans matricule, avec leur classe de l'année active (si inscrits). */
function matricules_manquants(): array {
    $val_annee = get_annee_active()['val_annee'] ?? '';
    return db_all(
        "SELECT e.id_eleve, e.Nom_elv, e.Prenom_elv, e.Sexe_elv, c.DesignationClasses AS classe, c.Niveau
         FROM eleve e
         LEFT JOIN inscrire i ON i.id_eleve = e.id_eleve AND i.val_annee = ?
         LEFT JOIN classe c ON c.IDClasses = i.IDClasses
         WHERE e.statut = 'actif' AND (e.Mat_elv IS NULL OR TRIM(e.Mat_elv) = '')
         ORDER BY c.DesignationClasses IS NULL, c.DesignationClasses, e.Nom_elv, e.Prenom_elv",
        [$val_annee]
    );
}

/**
 * Groupes de matricules en double (tous élèves, actifs ou non) :
 * [ cle => [ [id_eleve, Mat_elv, nom, classe, statut, garde (bool)], … ] ].
 * Dans chaque groupe, l'élève enregistré en premier (plus petit id) garde
 * son matricule ; les autres sont à corriger.
 */
function matricules_doublons(): array {
    $val_annee = get_annee_active()['val_annee'] ?? '';
    $rows = db_all(
        "SELECT e.id_eleve, e.Mat_elv, e.Nom_elv, e.Prenom_elv, e.statut, c.DesignationClasses AS classe
         FROM eleve e
         LEFT JOIN inscrire i ON i.id_eleve = e.id_eleve AND i.val_annee = ?
         LEFT JOIN classe c ON c.IDClasses = i.IDClasses
         WHERE e.Mat_elv IS NOT NULL AND TRIM(e.Mat_elv) <> ''
           AND UPPER(REPLACE(REPLACE(TRIM(e.Mat_elv), ' ', ''), CHAR(9), '')) IN (
               SELECT k FROM (SELECT UPPER(REPLACE(REPLACE(TRIM(Mat_elv), ' ', ''), CHAR(9), '')) AS k
                              FROM eleve WHERE Mat_elv IS NOT NULL AND TRIM(Mat_elv) <> ''
                              GROUP BY k HAVING COUNT(*) > 1) d)
         ORDER BY e.id_eleve",
        [$val_annee]
    );
    $groupes = [];
    foreach ($rows as $r) {
        $k = matricule_cle($r['Mat_elv']);
        $r['garde'] = !isset($groupes[$k]);   // premier rencontré = plus petit id
        $groupes[$k][] = $r;
    }
    ksort($groupes);
    return $groupes;
}

/** Ce matricule est-il libre (comparaison sans espaces/casse) pour cet élève ? */
function matricule_libre(string $mat, int $id_eleve): bool {
    return !(int) db_val(
        "SELECT COUNT(*) FROM eleve WHERE UPPER(REPLACE(TRIM(Mat_elv), ' ', '')) = ? AND id_eleve <> ?",
        [matricule_cle($mat), $id_eleve]
    );
}

/**
 * PROPOSITIONS (rien n'est enregistré) : un matricule selon le format défini
 * pour chaque élève de $ids, tous distincts entre eux et libres en base —
 * l'utilisateur les ajuste puis enregistre (onglet « Matricules »).
 * Retourne [id_eleve => matricule proposé].
 */
function matricules_proposer(array $ids): array {
    $cfg = matricule_config();
    $actif = get_annee_active()['val_annee'] ?? '';
    [$avant, $apres] = array_pad(explode('{SEQ}', $cfg['format'], 2), 2, '');
    $props = []; $pris = [];
    $prochain = [];   // [val_annee|niveau => prochain n° de séquence]
    foreach ($ids as $id) {
        $id = (int) $id;
        $ins = db_one(
            "SELECT i.val_annee, c.Niveau FROM inscrire i JOIN classe c ON c.IDClasses = i.IDClasses
             WHERE i.id_eleve = ? ORDER BY (i.val_annee = ?) DESC, i.val_annee DESC LIMIT 1", [$id, $actif]);
        $an_sco = $ins['val_annee'] ?? ($actif ?: date('Y') . '/' . (date('Y') + 1));
        $niv    = trim((string) ($ins['Niveau'] ?? 'P')) === 'M' ? 'M' : 'P';
        $an = explode('/', $an_sco)[0] ?: $an_sco;
        $res = fn(string $s) => strtr($s, ['{AAAA}' => $an, '{AA}' => substr($an, -2), '{NIV}' => $niv]);
        $prefixe = $res($avant); $suffixe = $res($apres);
        $cle = $an_sco . '|' . $niv;
        $mat = '';
        for ($t = 0; $t < 500; $t++) {
            if ($cfg['mode'] === 'aleatoire' || !isset($prochain[$cle])) {
                $cand = gen_matricule($an_sco, $niv, true);          // premier de la série (ou tirage aléatoire)
                if (!isset($prochain[$cle]) && $cfg['mode'] !== 'aleatoire') {
                    $prochain[$cle] = (int) substr($cand, strlen($prefixe), strlen($cand) - strlen($prefixe) - strlen($suffixe));
                }
            } else {
                $cand = $prefixe . str_pad((string) $prochain[$cle], (int) $cfg['longueur_seq'], '0', STR_PAD_LEFT) . $suffixe;
            }
            if (isset($prochain[$cle]) && $cfg['mode'] !== 'aleatoire') $prochain[$cle]++;
            if (!isset($pris[matricule_cle($cand)]) && matricule_libre($cand, $id)) { $mat = $cand; break; }
        }
        if ($mat !== '') { $props[$id] = $mat; $pris[matricule_cle($mat)] = true; }
    }
    return $props;
}

/**
 * Attribue un matricule à un élève : $saisi s'il est fourni (vérifié
 * unique), sinon généré selon le format défini (même en mode manuel).
 * Retourne le matricule attribué ; RuntimeException si impossible.
 */
function matricule_attribuer(int $id_eleve, ?string $saisi = null): string {
    $el = db_one("SELECT id_eleve, Mat_elv FROM eleve WHERE id_eleve = ?", [$id_eleve]);
    if (!$el) throw new RuntimeException('élève introuvable');
    $saisi = trim((string) $saisi);
    if ($saisi !== '') {
        if (mb_strlen($saisi) > 30) throw new RuntimeException('matricule trop long (30 caractères maximum)');
        if (!matricule_libre($saisi, $id_eleve)) throw new RuntimeException("le matricule « $saisi » est déjà attribué à un autre élève");
        $mat = $saisi;
    } else {
        // Classe de l'année active, sinon la plus récente : année et niveau du format.
        $ins = db_one(
            "SELECT i.val_annee, c.Niveau FROM inscrire i JOIN classe c ON c.IDClasses = i.IDClasses
             WHERE i.id_eleve = ? ORDER BY (i.val_annee = ?) DESC, i.val_annee DESC LIMIT 1",
            [$id_eleve, get_annee_active()['val_annee'] ?? '']
        );
        $mat = gen_matricule($ins['val_annee'] ?? (get_annee_active()['val_annee'] ?? date('Y') . '/' . (date('Y') + 1)),
                             (string) ($ins['Niveau'] ?? 'P'), true);
        if ($mat === '' || !matricule_libre($mat, $id_eleve)) throw new RuntimeException('génération impossible');
    }
    db_exec("UPDATE eleve SET Mat_elv = ? WHERE id_eleve = ?", [$mat, $id_eleve]);
    journaliser_action('matricule_attribue', null, "élève #$id_eleve : « " . ($el['Mat_elv'] ?? '') . " » → « $mat »");
    return $mat;
}
