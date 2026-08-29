<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_role(['DIRECTEUR','SECRETAIRE','COMPTABLE']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') rediriger('pages/eleves/liste.php');
csrf_verifier();

$id_existant  = (int) post('id');
$nom          = post('nom');
$nom_arabe    = trim(post('nom_arabe')) ?: null;
$prenom       = post('prenom');
$sexe         = post('sexe') === 'Feminin' ? 'Feminin' : 'Masculin';
$date_naiss   = post('date_naiss');
$lieu_naiss   = post('lieu_naiss');
$adresse      = post('adresse');

// Arrondissement d'origine (cascade région/département/arrondissement,
// assets/js/lieu-cascade.js) : une seule information est réellement
// utilisée — soit id_arrondissement (valeur officielle choisie), soit le
// texte libre (case "Autre, préciser" ou aucune sélection). Département et
// région ne sont jamais stockés séparément : ils se retrouvent toujours par
// jointure à partir de id_arrondissement (voir pages/eleves/voir.php).
$id_arrondissement_brut = post('id_arrondissement');
$lieu_libre             = trim(post('lieu_libre'));
$id_arrondissement      = null;
if (ctype_digit($id_arrondissement_brut) && (int) $id_arrondissement_brut > 0) {
    // On vérifie juste qu'il existe — pas besoin de revalider la cohérence
    // région/département/arrondissement, elle est garantie par la FK elle-même.
    if (db_val("SELECT COUNT(*) FROM arrondissement WHERE code_arrond=?", [(int) $id_arrondissement_brut])) {
        $id_arrondissement = (int) $id_arrondissement_brut;
    }
}
$arrondissement = $id_arrondissement ? null : ($lieu_libre ?: null);
// Champ verrouillé (readonly) côté formulaire, jamais saisi/modifié à la
// main — repris tel quel en modification (ne change pas le NIU déjà
// attribué), mais RE-généré côté serveur à la création (voir plus bas,
// même principe que Mat_elv/gen_matricule() juste en dessous) plutôt que
// de faire confiance à la valeur postée : deux créations simultanées
// verraient sinon le même NIU proposé côté formulaire et l'enverraient
// tel quel, provoquant une collision malgré gen_niu().
$niu          = post('niu') ?: null;
$id_classe    = (int) post('id_classe');
$statut_insc  = normaliser_statut_insc(post('statut_insc'));

if ($nom === '') {
    flash_set('erreur', 'Le nom est obligatoire.');
    rediriger('pages/eleves/form.php' . ($id_existant ? '?id=' . $id_existant : ''));
}

$annee     = get_annee_active();
$val_annee = $annee['val_annee'] ?? '';
$etab      = get_etablissement();

// Photo : décodée depuis le champ caché photo_b64 (recadrage Cropper.js) —
// aucune gestion d'upload "brut" séparée, cohérent avec form.php qui force
// systématiquement le recadrage avant validation.
$photo_bin = decoder_photo_b64($_POST['photo_b64'] ?? null);

if ($id_existant) {
    // ── Modification ──────────────────────────────────────
    $eleve = db_one("SELECT id_eleve FROM eleve WHERE id_eleve=?", [$id_existant]);
    if (!$eleve) { flash_set('erreur', 'Élève introuvable.'); rediriger('pages/eleves/liste.php'); }
    $id = $id_existant;

    if ($photo_bin !== null) {
        db_exec(
            "UPDATE eleve SET Nom_elv=?, Nom_arabe_elv=?, Prenom_elv=?, Sexe_elv=?, Date_naiss_elv=?, Lieu_naiss_elv=?,
                    id_arrondissement=?, arrondissement_elv=?, Adresse_elv=?, niu=?, Photo_elv=? WHERE id_eleve=?",
            [$nom, $nom_arabe, $prenom, $sexe, $date_naiss, $lieu_naiss, $id_arrondissement, $arrondissement, $adresse, $niu, $photo_bin, $id]
        );
    } else {
        db_exec(
            "UPDATE eleve SET Nom_elv=?, Nom_arabe_elv=?, Prenom_elv=?, Sexe_elv=?, Date_naiss_elv=?, Lieu_naiss_elv=?,
                    id_arrondissement=?, arrondissement_elv=?, Adresse_elv=?, niu=? WHERE id_eleve=?",
            [$nom, $nom_arabe, $prenom, $sexe, $date_naiss, $lieu_naiss, $id_arrondissement, $arrondissement, $adresse, $niu, $id]
        );
    }
    // Registre NIU central (multi-établissement) : identité tenue à jour
    if ($niu) {
        niu_synchroniser_identite($niu, ['nom' => $nom, 'prenom' => $prenom,
            'date_naiss' => $date_naiss, 'sexe' => $sexe, 'lieu_naiss' => $lieu_naiss]);
    }
    $msg = 'Élève modifié.';
} else {
    // ── Création ──────────────────────────────────────────
    // id_eleve est AUTO_INCREMENT (toujours attribué, même si le matricule
    // officiel ou le NIU ne sont pas encore connus) — identifiant interne
    // stable de l'élève, indépendant des deux. Voir fonctions.php::gen_matricule()
    // pour le matricule.
    $niveau = $id_classe ? (string) db_val("SELECT Niveau FROM classe WHERE IDClasses=?", [$id_classe]) : '';
    $mat = gen_matricule($val_annee, $niveau ?: 'P');
    $niu = gen_niu($etab['Initial_Etab'] ?? '');

    db_exec(
        "INSERT INTO eleve (Mat_elv, Nom_elv, Nom_arabe_elv, Prenom_elv, Sexe_elv, Date_naiss_elv, Lieu_naiss_elv,
                             id_arrondissement, arrondissement_elv, Adresse_elv, niu, Photo_elv, statut)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'actif')",
        [$mat, $nom, $nom_arabe, $prenom, $sexe, $date_naiss, $lieu_naiss, $id_arrondissement, $arrondissement, $adresse, $niu, $photo_bin]
    );
    $id = (int) db_last_id();
    // Registre NIU central (multi-établissement) : passe la réservation en
    // « actif », renseigne l'identité et l'école courante, trace le mouvement.
    niu_enregistrer_inscription($niu, ['nom' => $nom, 'prenom' => $prenom,
        'date_naiss' => $date_naiss, 'sexe' => $sexe, 'lieu_naiss' => $lieu_naiss]);
    $msg = 'Élève créé — matricule ' . $mat . '.';
}

// Inscription pour l'année active (upsert)
if ($id_classe) {
    $existe_insc = db_val("SELECT COUNT(*) FROM inscrire WHERE id_eleve=? AND val_annee=?", [$id, $val_annee]);
    if ($existe_insc) {
        db_exec("UPDATE inscrire SET IDClasses=?, Statut_elv=? WHERE id_eleve=? AND val_annee=?", [$id_classe, $statut_insc, $id, $val_annee]);
    } else {
        db_exec("INSERT INTO inscrire (id_eleve, IDClasses, val_annee, Date_Inscrire, Statut_elv) VALUES (?, ?, ?, CURDATE(), ?)", [$id, $id_classe, $val_annee, $statut_insc]);
    }
} else {
    db_exec("DELETE FROM inscrire WHERE id_eleve=? AND val_annee=?", [$id, $val_annee]);
}

flash_set('succes', $msg);
rediriger('pages/eleves/voir.php?id=' . $id);
