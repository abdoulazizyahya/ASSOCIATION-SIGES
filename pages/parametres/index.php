<?php
// pages/parametres/index.php — Paramètres (admin), à onglets, mêmes
// fonctionnalités que LAM_ABZ (pages/parametres/index.php) mais branché sur
// le schéma réel de jaynitaare_v2_bd (annee_scolaire/trimestre/sequence —
// noms de colonnes et logique "un seul actif à la fois" différents de LAM_ABZ,
// voir fonctions.php get_annee_active()/get_sequence_active()).
//
// Onglets :
//  - Établissement : formulaire déjà existant (identité, logo, signature),
//    simplement replacé dans la structure à onglets.
//  - Années scolaires : créer/activer/désactiver/renommer/supprimer. La
//    création provisionne automatiquement 3 trimestres + 6 séquences
//    (UA1-UA6, 2 par trimestre) pour que la structure de séquences déjà en
//    place pour 2025/2026 se reproduise à l'identique chaque année — c'est
//    cette structure que la saisie de notes (composer_sequence) référence
//    (voir prompt_continuite_jaynitaare_v2.md : « la séquence doit être
//    conservée »).
//  - Évaluations : les séquences ne sont ni créées ni supprimées ici (pour
//    ne jamais casser cette structure) — seulement renommées et activées/
//    désactivées (une seule séquence active à la fois, comme le lit déjà
//    get_sequence_active() dans fonctions.php).
//  - Apparence : sélecteur de thème/police 100% client (localStorage), sans
//    écriture en base — porté depuis LAM_ABZ à l'identique (mêmes variables
//    CSS --primary/--sidebar-bg/--sidebar-act déjà utilisées par ce projet).
//
// Volontairement absent : onglet « Mentions » (seuils de mentions du
// bulletin) — dépend du moteur de calcul des bulletins (fonction_calcule1.php
// / mes_fonctions.php côté jaynitaare legacy), pas encore repris dans
// jaynitaare_v2 (« le gros morceau », hors périmètre de cette session).
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_role(['DIRECTEUR']);

// Années scolaires : la CRÉATION et la SUPPRESSION restent réservées au
// PROPRIÉTAIRE ou au SUPERADMIN de tout le système
// (est_superadmin_association() — englobe le propriétaire, voir sa
// docstring — même principe que la licence, bd/lib/licence.php). Révisé le
// 17/09/2026 : l'ACTIVATION/DÉSACTIVATION d'une année, en revanche, reste
// disponible aux comptes locaux (Directeur, Fondateur en écriture déléguée)
// — seule la création/suppression de la structure (trimestres/séquences,
// promotions, report de barème) est jugée assez sensible pour rester
// réservée au niveau système (amendement à la restriction du 15/09/2026, qui
// bloquait alors les 4 actions).
$est_superadmin = function_exists('est_superadmin_association') && est_superadmin_association();

$onglet = $_GET['onglet'] ?? 'etablissement';
if (!in_array($onglet, ['etablissement', 'annees', 'evaluations', 'apparence', 'couleurs'], true)) $onglet = 'etablissement';

$etab = get_etablissement();

// ══════════════════════════════════════════════════════════════
//  POST
// ══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $action = post('action');

    // ── Établissement (logique inchangée) ─────────────────────
    if ($action === 'etab') {
        $logo = $etab['logo'] ?? null;
        if (!empty($_FILES['logo']['tmp_name']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $ext_ok = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
            $ext    = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
            $fi     = finfo_open(FILEINFO_MIME_TYPE);
            $mime   = finfo_file($fi, $_FILES['logo']['tmp_name']);
            finfo_close($fi);
            if (isset($ext_ok[$ext]) && $ext_ok[$ext] === $mime && $_FILES['logo']['size'] <= 2 * 1024 * 1024) {
                // Préfixe par école (multi-établissement) : sinon l'upload
                // d'une école écrase le logo d'une autre — voir upload_prefixe_etab().
                $logo = upload_prefixe_etab() . 'logo_etab.' . $ext;
                $logo_abs = upload_dir_etab(__DIR__ . '/../../assets/uploads') . 'logo_etab.' . $ext;
                move_uploaded_file($_FILES['logo']['tmp_name'], $logo_abs);
                // Filigrane pour la case école (association/index.php) —
                // best-effort, ne bloque jamais l'enregistrement du reste.
                generer_filigrane_logo($logo_abs, __DIR__ . '/../../assets/uploads/' . chemin_filigrane_logo($logo));
            }
        }

        $signature = $etab['signature'] ?? null;
        if (!empty($_FILES['signature']['tmp_name']) && $_FILES['signature']['error'] === UPLOAD_ERR_OK) {
            $ext_ok = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
            $ext    = strtolower(pathinfo($_FILES['signature']['name'], PATHINFO_EXTENSION));
            $fi     = finfo_open(FILEINFO_MIME_TYPE);
            $mime   = finfo_file($fi, $_FILES['signature']['tmp_name']);
            finfo_close($fi);
            if (isset($ext_ok[$ext]) && $ext_ok[$ext] === $mime && $_FILES['signature']['size'] <= 2 * 1024 * 1024) {
                $signature = upload_prefixe_etab() . 'signature_directeur.' . $ext;
                move_uploaded_file($_FILES['signature']['tmp_name'],
                    upload_dir_etab(__DIR__ . '/../../assets/uploads') . 'signature_directeur.' . $ext);
            }
        }

        // Colonnes réelles de `etablissement` (28/08/2026) : le champ
        // "Département/Arrondissement" de la carte "Localisation
        // administrative" s'écrit dans departement_fr/en et
        // arrondissement_fr/en (pas delegation_regional_fr/en ni
        // delegation_departemental_fr/en, noms hérités du legacy qui
        // n'existent plus dans ce schéma — UPDATE en échec silencieux
        // sinon). La carte "En-tête bilingue" a par ailleurs été réduite à
        // Arrondissement (déjà repris ci-dessus) et Nom de l'école (AR) :
        // République/Devise/Ministère/Délégations/École (FR) faisaient
        // doublon avec Pays/Nom ci-dessus (voir pdf_entete()/tcpdf_entete(),
        // aucun document ne les lit) et leurs colonnes ont été supprimées.
        db_exec(
            "UPDATE etablissement SET
                Nom_Etab_Fr=?, Nom_Etab_An=?, Initial_Etab=?, Immatriculation_Etab=?, boite_postal=?, ville_etab=?,
                tel_etab=?, email_etab=?,
                pays_etab_fr=?, region_etab_fr=?, departement_fr=?, arrondissement_fr=?,
                pays_etab_en=?, region_etab_en=?, departement_en=?, arrondissement_en=?,
                lieu_etab=?, fonction_dirigeant_fr=?, fonction_dirigeant_en=?, logo=?, signature=?,
                arrondissement_ar=?, ecole_ar=?
             WHERE IDEtablissement=?",
            [
                post('nom_fr'), post('nom_en'), post('sigle'), post('immatriculation'), post('boite_postale'), post('ville'),
                post('telephone'), post('email'),
                post('pays_fr'), post('region_fr'), post('delegation_regionale_fr'), post('delegation_departementale_fr'),
                post('pays_en'), post('region_en'), post('delegation_regionale_en'), post('delegation_departementale_en'),
                post('lieu'), post('fonction_dirigeant_fr'), post('fonction_dirigeant_en'), $logo, $signature,
                post('arrondissement_ar'), post('ecole_ar'),
                $etab['IDEtablissement'],
            ]
        );
        flash_set('succes', 'Établissement mis à jour.');
        rediriger('pages/parametres/index.php?onglet=etablissement');
    }

    // ── Suppression du logo / de la signature (retire le fichier + vide la
    //    colonne — jusqu'ici seul un remplacement par upload était possible).
    if ($action === 'etab_media_supprimer') {
        $cible = post('cible');
        if (in_array($cible, ['logo', 'signature'], true) && !empty($etab[$cible])) {
            $abs = __DIR__ . '/../../assets/uploads/' . $etab[$cible];
            if (is_file($abs)) @unlink($abs);
            if ($cible === 'logo') {
                $filigrane = __DIR__ . '/../../assets/uploads/' . chemin_filigrane_logo($etab['logo']);
                if (is_file($filigrane)) @unlink($filigrane);
                db_exec("UPDATE etablissement SET logo=NULL WHERE IDEtablissement=?", [$etab['IDEtablissement']]);
                flash_set('succes', 'Logo supprimé.');
            } else {
                db_exec("UPDATE etablissement SET signature=NULL WHERE IDEtablissement=?", [$etab['IDEtablissement']]);
                flash_set('succes', 'Signature supprimée.');
            }
        }
        rediriger('pages/parametres/index.php?onglet=etablissement');
    }

    // ── Années scolaires — création/suppression réservées au propriétaire/
    //    superadmin (voir garde plus haut) ; activer/désactiver restent
    //    ouvertes aux comptes locaux (Directeur, Fondateur). ──
    if (in_array($action, ['annee_creer', 'annee_supprimer'], true) && !$est_superadmin) {
        flash_set('erreur', "Seul le propriétaire ou le superadministrateur du système peut créer ou supprimer une année scolaire.");
        rediriger('pages/parametres/index.php?onglet=annees');
    }
    if ($action === 'annee_creer') {
        $lib = post('libelle_annee');
        if ($lib && preg_match('#^\d{4}/\d{4}$#', $lib)) {
            // Année active AVANT la création de la nouvelle — c'est elle qui
            // vient de se terminer, donc la source des décisions de passage
            // (migration_v36, appliquer_promotions_annee()). Lue avant
            // l'INSERT ci-dessous pour ne jamais capturer la nouvelle année
            // elle-même si elle était déjà active par erreur.
            $annee_precedente = get_annee_active()['val_annee'] ?? '';

            db_exec("INSERT IGNORE INTO annee_scolaire (val_annee, Etat_annee_scolaire) VALUES (?, 0)", [$lib]);
            $deja_structuree = (int) db_val("SELECT COUNT(*) FROM trimestre WHERE id_annee=?", [$lib]);
            if ($deja_structuree === 0) {
                // Provisionne la structure standard — 3 trimestres, 2 séquences
                // (UA) chacun — identique à celle de 2025/2026, pour que la
                // saisie de notes par séquence fonctionne dès l'activation.
                $ids_trim = [];
                foreach (['1er Trimestre', '2eme Trimestre', '3eme Trimestre'] as $lib_trim) {
                    db_exec("INSERT INTO trimestre (libelle_trim, id_annee) VALUES (?, ?)", [$lib_trim, $lib]);
                    $ids_trim[] = db_last_id();
                }
                $seqs = [
                    [$ids_trim[0], 'UA1'], [$ids_trim[0], 'UA2'],
                    [$ids_trim[1], 'UA3'], [$ids_trim[1], 'UA4'],
                    [$ids_trim[2], 'UA5'], [$ids_trim[2], 'UA6'],
                ];
                foreach ($seqs as [$id_trim, $lib_seq]) {
                    db_exec("INSERT INTO sequence (libelle_seq, etat, id_trim) VALUES (?, 0, ?)", [$lib_seq, $id_trim]);
                }
                // Passage en classe supérieure automatique (migration_v36) :
                // Admis -> next_classe (Statut_elv='Non'), Redoublement -> même
                // classe (Statut_elv='Oui'), d'après les décisions du Conseil de
                // Classe / de la validation automatique de l'année précédente.
                // Idempotent (voir appliquer_promotions_annee()) — sans effet si
                // aucune décision n'a encore été enregistrée pour $annee_precedente.
                $promo = appliquer_promotions_annee($annee_precedente, $lib);
                $msg_promo = $promo['inscrits'] > 0 ? " {$promo['inscrits']} élève(s) déjà inscrit(s) automatiquement (passage en classe supérieure/redoublement)." : '';
                // Report automatique du barème par compétence (demande explicite
                // du 18/08/2026) — voir reporter_bareme_annee().
                $nb_bareme = reporter_bareme_annee($annee_precedente, $lib);
                // École neuve / première année : aucun barème à reporter → on
                // dérive le barème de travail du gabarit de référence livré
                // avec l'application (bareme_reference, seed_ref_ecole.sql).
                if ($nb_bareme === 0) {
                    $nb_bareme = appliquer_bareme_reference($lib);
                }
                $msg_bareme = $nb_bareme > 0 ? " Barème de $nb_bareme compétence(s) provisionné automatiquement." : '';
                flash_set('succes', "Année $lib créée avec sa structure standard (3 trimestres, 6 évaluations UA1-UA6).$msg_promo$msg_bareme");
            } else {
                flash_set('info', "Année $lib existait déjà (structure conservée telle quelle).");
            }
        } else {
            flash_set('erreur', 'Format attendu : AAAA/AAAA (ex. 2026/2027).');
        }
        rediriger('pages/parametres/index.php?onglet=annees');
    }
    if ($action === 'annee_activer') {
        $lib = post('val_annee');
        db_exec("UPDATE annee_scolaire SET Etat_annee_scolaire=0");
        db_exec("UPDATE annee_scolaire SET Etat_annee_scolaire=1 WHERE val_annee=?", [$lib]);

        // Même passage automatique qu'à la création (voir commentaire
        // ci-dessus) — rejoué ici aussi car l'utilisateur peut créer une
        // année puis ne l'activer que plus tard (ou l'activer sans être
        // passé par annee_creer, ex. réactivation) ; année précédente
        // dérivée arithmétiquement de $lib (AAAA/AAAA -> (AAAA-1)/(AAAA-1)+1)
        // plutôt que « l'année active avant ce clic », qui ne serait pas
        // forcément la bonne si l'admin navigue entre plusieurs années.
        $annee_precedente = preg_match('#^(\d{4})/(\d{4})$#', $lib, $m) ? ((int) $m[1] - 1) . '/' . ((int) $m[2] - 1) : '';
        $promo = $annee_precedente ? appliquer_promotions_annee($annee_precedente, $lib) : ['inscrits' => 0];
        $msg_promo = $promo['inscrits'] > 0 ? " {$promo['inscrits']} élève(s) inscrit(s) automatiquement (passage en classe supérieure/redoublement)." : '';
        // Report automatique du barème par compétence (demande explicite du
        // 18/08/2026) — voir reporter_bareme_annee().
        $nb_bareme = $annee_precedente ? reporter_bareme_annee($annee_precedente, $lib) : 0;
        $msg_bareme = $nb_bareme > 0 ? " Barème de $nb_bareme compétence(s) reporté automatiquement." : '';
        flash_set('succes', "Année scolaire activée.$msg_promo$msg_bareme");
        rediriger('pages/parametres/index.php?onglet=annees');
    }
    if ($action === 'annee_desactiver') {
        $lib = post('val_annee');
        db_exec("UPDATE annee_scolaire SET Etat_annee_scolaire=0 WHERE val_annee=?", [$lib]);
        flash_set('succes', 'Année scolaire désactivée.');
        rediriger('pages/parametres/index.php?onglet=annees');
    }
    if ($action === 'annee_supprimer') {
        $lib        = post('val_annee');
        $est_active = (bool) db_val("SELECT Etat_annee_scolaire FROM annee_scolaire WHERE val_annee=?", [$lib]);
        $nb_insc    = (int) db_val("SELECT COUNT(*) FROM inscrire WHERE val_annee=?", [$lib]);
        $nb_notes   = (int) db_val("SELECT COUNT(*) FROM composer_sequence WHERE val_annee=?", [$lib]);
        if ($est_active) {
            flash_set('erreur', 'Impossible : cette année est actuellement active.');
        } elseif ($nb_insc > 0 || $nb_notes > 0) {
            flash_set('erreur', "Impossible : $nb_insc inscription(s) et $nb_notes note(s) existent déjà pour cette année.");
        } else {
            // `sequence.id_trim` n'a pas de contrainte de clé étrangère vers
            // `trimestre` (lacune du schéma legacy) : la cascade ON DELETE de
            // `trimestre` -> `annee_scolaire` ne supprimerait donc pas les
            // séquences, qui resteraient orphelines. Nettoyage explicite.
            db_exec(
                "DELETE s FROM sequence s JOIN trimestre t ON t.id_trim = s.id_trim WHERE t.id_annee = ?",
                [$lib]
            );
            db_exec("DELETE FROM annee_scolaire WHERE val_annee=?", [$lib]); // cascade -> trimestre
            flash_set('succes', 'Année supprimée.');
        }
        rediriger('pages/parametres/index.php?onglet=annees');
    }

    // ── Évaluations (séquences) — renommer/activer seulement : la structure
    //    (nombre et rattachement au trimestre) reste fixe, voir en-tête. ──
    if ($action === 'seq_renommer') {
        $sid = (int) post('seq_id');
        $lib = post('seq_libelle');
        if ($sid && $lib !== '') {
            db_exec("UPDATE sequence SET libelle_seq=? WHERE id_seq=?", [$lib, $sid]);
            flash_set('succes', 'Évaluation renommée.');
        }
        rediriger('pages/parametres/index.php?onglet=evaluations');
    }
    if ($action === 'seq_activer') {
        $sid = (int) post('seq_id');
        db_exec("UPDATE sequence SET etat=0");
        db_exec("UPDATE sequence SET etat=1 WHERE id_seq=?", [$sid]);
        flash_set('succes', 'Évaluation activée.');
        rediriger('pages/parametres/index.php?onglet=evaluations');
    }
    if ($action === 'seq_desactiver') {
        $sid = (int) post('seq_id');
        db_exec("UPDATE sequence SET etat=0 WHERE id_seq=?", [$sid]);
        flash_set('succes', 'Évaluation désactivée.');
        rediriger('pages/parametres/index.php?onglet=evaluations');
    }

    // ── Couleurs du bulletin PDF (table pdf_couleur) ──────────────
    if ($action === 'couleurs_save') {
        foreach (db_all("SELECT cle FROM pdf_couleur") as $c) {
            $cle = $c['cle'];
            // Un <input type="color"> envoie toujours "#rrggbb" — hexdec()
            // ignore silencieusement un format inattendu (défaut 0), pas de
            // risque d'injection ni de valeur hors 0-255.
            $hex = (string) post('c_' . $cle);
            if (preg_match('/^#[0-9a-fA-F]{6}$/', $hex)) {
                $r = hexdec(substr($hex, 1, 2));
                $g = hexdec(substr($hex, 3, 2));
                $b = hexdec(substr($hex, 5, 2));
                db_exec("UPDATE pdf_couleur SET r=?, g=?, b=? WHERE cle=?", [$r, $g, $b, $cle]);
            }
        }
        flash_set('succes', 'Couleurs du bulletin mises à jour.');
        rediriger('pages/parametres/index.php?onglet=couleurs');
    }
    if ($action === 'couleurs_reinit') {
        $defauts = [
            'groupe_competence' => [45, 231, 218],
            'ligne_alternee'    => [227, 227, 227],
            'ligne_rayee'       => [249, 249, 249],
            'entete_section'    => [65, 165, 165],
            'cellule_resultat'  => [228, 228, 228],
            'entete_bleu'       => [146, 220, 255],
            'colonne_annuelle'  => [250, 214, 165],
        ];
        foreach ($defauts as $cle => [$r, $g, $b]) {
            db_exec("UPDATE pdf_couleur SET r=?, g=?, b=? WHERE cle=?", [$r, $g, $b, $cle]);
        }
        flash_set('succes', 'Couleurs réinitialisées aux valeurs par défaut.');
        rediriger('pages/parametres/index.php?onglet=couleurs');
    }
}

// ══════════════════════════════════════════════════════════════
//  Données
// ══════════════════════════════════════════════════════════════
$etab      = get_etablissement();
$ve        = fn(string $k) => $etab[$k] ?? '';
$couleurs  = db_all("SELECT * FROM pdf_couleur ORDER BY libelle");
$pdf_couleurs_actuelles = [];
foreach ($couleurs as $c) {
    $pdf_couleurs_actuelles[$c['cle']] = [
        'libelle' => $c['libelle'],
        'hex'     => sprintf('#%02x%02x%02x', $c['r'], $c['g'], $c['b']),
    ];
}
$annees    = db_all("SELECT * FROM annee_scolaire ORDER BY val_annee DESC");
$annee_act = get_annee_active();
$sequences = db_all(
    "SELECT s.*, t.libelle_trim
     FROM sequence s JOIN trimestre t ON t.id_trim = s.id_trim
     WHERE t.id_annee = ?
     ORDER BY t.id_trim, s.id_seq",
    [$annee_act['val_annee'] ?? '']
);

$es_partiel = isset($_GET['partiel']);
if (!$es_partiel) {
    $titre_page = 'Paramètres';
    require_once __DIR__ . '/../../layout/header.php';
} else {
    header('Content-Type: text/html; charset=utf-8');
}
?>

<div id="parametres-zone">

<div class="page-titre">
  <h4><i class="bi bi-gear me-1 text-primary"></i>Paramètres</h4>
</div>

<ul class="nav nav-tabs mb-3" style="border-bottom:2px solid #e5e7eb">
  <?php foreach ([
      'etablissement' => ['bi-building',  'Établissement'],
      'annees'        => ['bi-calendar3', 'Années scolaires'],
      'evaluations'   => ['bi-list-check','Évaluations'],
      'apparence'     => ['bi-palette',   'Apparence'],
      'couleurs'      => ['bi-paint-bucket', 'Couleurs bulletin'],
  ] as $key => [$ico, $label]): ?>
  <li class="nav-item">
    <a class="nav-link <?= $onglet === $key ? 'active' : '' ?>" data-ajax-nav
       href="<?= APP_URL ?>/pages/parametres/index.php?onglet=<?= $key ?>">
      <i class="bi <?= $ico ?> me-1"></i><?= $label ?>
    </a>
  </li>
  <?php endforeach; ?>
</ul>

<?php if ($onglet === 'etablissement'): ?>
<!-- ══════════════════════════════════════════════
     ONGLET 1 — Établissement
══════════════════════════════════════════════ -->
<form method="post" enctype="multipart/form-data" data-ajax-post-form>
  <?= csrf_champ() ?>
  <input type="hidden" name="action" value="etab">

  <div class="row g-3">
    <div class="col-lg-8">
      <div class="card mb-3">
        <div class="card-body">
          <div class="section-titre"><i class="bi bi-building me-1"></i>Identité de l'établissement</div>
          <div class="row g-compact">
            <div class="col-md-8">
              <label class="form-label">Nom (Français)</label>
              <input type="text" name="nom_fr" class="form-control" value="<?= h($ve('Nom_Etab_Fr')) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Sigle</label>
              <input type="text" name="sigle" class="form-control" value="<?= h($ve('Initial_Etab')) ?>">
            </div>
            <div class="col-md-8">
              <label class="form-label">Nom (English)</label>
              <input type="text" name="nom_en" class="form-control" value="<?= h($ve('Nom_Etab_An')) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Immatriculation</label>
              <input type="text" name="immatriculation" class="form-control" value="<?= h($ve('Immatriculation_Etab')) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Boîte postale</label>
              <input type="text" name="boite_postale" class="form-control" value="<?= h($ve('boite_postal')) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Ville</label>
              <input type="text" name="ville" class="form-control" value="<?= h($ve('ville_etab')) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Lieu-dit</label>
              <input type="text" name="lieu" class="form-control" value="<?= h($ve('lieu_etab')) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Téléphone</label>
              <input type="text" name="telephone" class="form-control" value="<?= h($ve('tel_etab')) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Email</label>
              <input type="email" name="email" class="form-control" value="<?= h($ve('email_etab')) ?>">
            </div>
          </div>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-body">
          <div class="section-titre"><i class="bi bi-geo-alt me-1"></i>Localisation administrative</div>
          <div class="row g-compact">
            <div class="col-md-6">
              <label class="form-label">Pays (FR)</label>
              <input type="text" name="pays_fr" class="form-control" value="<?= h($ve('pays_etab_fr')) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Country (EN)</label>
              <input type="text" name="pays_en" class="form-control" value="<?= h($ve('pays_etab_en')) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Région (FR)</label>
              <input type="text" name="region_fr" class="form-control" value="<?= h($ve('region_etab_fr')) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Region (EN)</label>
              <input type="text" name="region_en" class="form-control" value="<?= h($ve('region_etab_en')) ?>">
            </div>
            <div class="col-md-6">
              <!-- Libellé corrigé le 20/08/2026 : le champ delegation_regional_fr
                   contient en réalité le DÉPARTEMENT (ex. "DEPARTEMENT DE LA
                   VINA"), pas une délégation régionale — nom de colonne
                   trompeur hérité du legacy, voir fonctions.php::etab_pour_pdf().
                   Ne pas confondre avec "Délégation régionale (FR)" de la
                   carte "En-tête bilingue" plus bas (delegation_reg_fr),
                   un champ différent au contenu réellement différent
                   ("DELEGATION REGIONALE DE ..."). -->
              <label class="form-label">Département (FR)</label>
              <input type="text" name="delegation_regionale_fr" class="form-control" value="<?= h($ve('departement_fr')) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Department (EN)</label>
              <input type="text" name="delegation_regionale_en" class="form-control" value="<?= h($ve('departement_en')) ?>">
            </div>
            <div class="col-md-6">
              <!-- Même correction : delegation_departemental_fr contient en
                   réalité l'ARRONDISSEMENT (ex. "ARRONDISEMNET DE NGAOUNDERE
                   I"), pas une délégation départementale. -->
              <label class="form-label">Arrondissement (FR)</label>
              <input type="text" name="delegation_departementale_fr" class="form-control" value="<?= h($ve('arrondissement_fr')) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Arrondissement (EN)</label>
              <input type="text" name="delegation_departementale_en" class="form-control" value="<?= h($ve('arrondissement_en')) ?>">
            </div>
          </div>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-body">
          <div class="section-titre"><i class="bi bi-person-badge me-1"></i>Direction</div>
          <div class="row g-compact">
            <div class="col-md-6">
              <label class="form-label">Fonction du dirigeant (FR)</label>
              <input type="text" name="fonction_dirigeant_fr" class="form-control" value="<?= h($ve('fonction_dirigeant_fr')) ?>" placeholder="Le Directeur">
            </div>
            <div class="col-md-6">
              <label class="form-label">Fonction du dirigeant (EN)</label>
              <input type="text" name="fonction_dirigeant_en" class="form-control" value="<?= h($ve('fonction_dirigeant_en')) ?>" placeholder="The Director">
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-body">
          <div class="section-titre"><i class="bi bi-translate me-1"></i>En-tête bilingue (bulletins/certificats — piste arabe)</div>
          <p style="font-size:.75rem;color:#6b7280;margin-top:-4px">
            Colonne arabe affichée en haut des bulletins/certificats de la piste arabe (voir
            <code>pdf/header_pdf_tcpdf.php</code>). Réduit le 28/08/2026 à l'arabe de l'arrondissement et au nom de
            l'école : République/Devise/Ministère/Délégations/École (FR) faisaient doublon avec Pays et Nom de
            l'établissement ci-dessus (aucun document ne les lisait) — leurs colonnes ont été supprimées de la table
            <code>etablissement</code>.
          </p>
          <div class="row g-compact">
            <div class="col-md-6">
              <label class="form-label">Arrondissement (AR)</label>
              <input type="text" name="arrondissement_ar" class="form-control" dir="rtl" value="<?= h($ve('arrondissement_ar')) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Nom de l'école (AR)</label>
              <input type="text" name="ecole_ar" class="form-control" dir="rtl" value="<?= h($ve('ecole_ar')) ?>">
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card mb-3">
        <div class="card-body text-center">
          <div class="section-titre text-start"><i class="bi bi-image me-1"></i>Logo</div>
          <?php if (!empty($etab['logo']) && is_file(__DIR__ . '/../../assets/uploads/' . $etab['logo'])): ?>
            <img src="<?= APP_URL ?>/assets/uploads/<?= h($etab['logo']) ?>?t=<?= time() ?>"
                 style="max-width:140px;max-height:140px;border-radius:8px;border:1px solid #e5e7eb;margin-bottom:.3rem">
            <div class="mb-2">
              <button type="submit" form="form_supprimer_logo" class="btn btn-sm btn-outline-danger">
                <i class="bi bi-trash"></i> Supprimer le logo
              </button>
            </div>
          <?php else: ?>
            <div class="text-muted" style="font-size:.78rem;margin-bottom:.6rem">Aucun logo configuré.</div>
          <?php endif; ?>
          <input type="file" name="logo" accept="image/jpeg,image/png" class="form-control form-control-sm">
          <div class="form-text" style="font-size:.68rem">JPG/PNG — max 2 Mo</div>
        </div>
      </div>

      <div class="card">
        <div class="card-body text-center">
          <div class="section-titre text-start"><i class="bi bi-vector-pen me-1"></i>Signature numérique (Directeur)</div>
          <?php if (!empty($etab['signature']) && is_file(__DIR__ . '/../../assets/uploads/' . $etab['signature'])): ?>
            <img src="<?= APP_URL ?>/assets/uploads/<?= h($etab['signature']) ?>?t=<?= time() ?>"
                 style="max-width:140px;max-height:80px;border-radius:8px;border:1px solid #e5e7eb;margin-bottom:.3rem;background:#f8faff">
            <div class="mb-2">
              <button type="submit" form="form_supprimer_signature" class="btn btn-sm btn-outline-danger">
                <i class="bi bi-trash"></i> Supprimer la signature
              </button>
            </div>
          <?php else: ?>
            <div class="text-muted" style="font-size:.78rem;margin-bottom:.6rem">Aucune signature configurée.</div>
          <?php endif; ?>
          <input type="file" name="signature" accept="image/jpeg,image/png" class="form-control form-control-sm">
          <div class="form-text" style="font-size:.68rem">
            JPG/PNG — max 2 Mo. Jamais appliquée automatiquement : une case à cocher la propose à chaque impression.
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="mt-3">
    <button class="btn btn-primary btn-sm px-4"><i class="bi bi-check-lg me-1"></i>Enregistrer</button>
  </div>
</form>

<!-- Boutons "Supprimer" du logo/signature ci-dessus : formulaires séparés
     (rattachés via l'attribut form="...", pas d'imbrication dans le
     formulaire établissement) pour ne pas envoyer tous les autres champs. -->
<form id="form_supprimer_logo" method="post" style="display:none" data-ajax-post-form
      onsubmit="return confirm('Supprimer le logo actuel ?')">
  <?= csrf_champ() ?>
  <input type="hidden" name="action" value="etab_media_supprimer">
  <input type="hidden" name="cible" value="logo">
</form>
<form id="form_supprimer_signature" method="post" style="display:none" data-ajax-post-form
      onsubmit="return confirm('Supprimer la signature actuelle ?')">
  <?= csrf_champ() ?>
  <input type="hidden" name="action" value="etab_media_supprimer">
  <input type="hidden" name="cible" value="signature">
</form>

<?php elseif ($onglet === 'annees'): ?>
<!-- ══════════════════════════════════════════════
     ONGLET 2 — Années scolaires
══════════════════════════════════════════════ -->
<?php if ($est_superadmin): ?>
<div class="alert alert-light border py-2 mb-3" style="font-size:.8rem">
  <i class="bi bi-info-circle me-1"></i>
  Créer une année provisionne automatiquement sa structure standard (3 trimestres, 6 évaluations UA1-UA6) —
  la même que celle utilisée pour la saisie de notes des années existantes.
</div>
<?php else: ?>
<div class="alert alert-warning border py-2 mb-3" style="font-size:.8rem">
  <i class="bi bi-lock me-1"></i>
  Création et suppression d'une année scolaire sont réservées au <strong>propriétaire</strong> ou au
  <strong>superadministrateur</strong> du système — aucun compte de l'école (Directeur, Fondateur…) ne peut y toucher.
  L'activation/désactivation d'une année existante reste possible ci-dessous.
</div>
<?php endif; ?>

<div class="row g-3">
  <?php if ($est_superadmin): ?>
  <div class="col-md-5">
    <div class="card">
      <div class="card-header py-2" style="background:#f8faff">
        <span class="fw-semibold" style="font-size:.82rem">
          <i class="bi bi-plus-circle me-1 text-primary"></i>Créer une année
        </span>
      </div>
      <div class="card-body py-3">
        <form method="post" class="d-flex gap-2" data-ajax-post-form>
          <?= csrf_champ() ?>
          <input type="hidden" name="action" value="annee_creer">
          <input type="text" name="libelle_annee" class="form-control"
                 placeholder="ex: 2026/2027" pattern="\d{4}/\d{4}" required>
          <button class="btn btn-primary btn-sm px-3"><i class="bi bi-plus-lg"></i></button>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>
  <div class="<?= $est_superadmin ? 'col-md-7' : 'col-12' ?>">
    <div class="card">
      <div class="card-header py-2" style="background:#f8faff">
        <span class="fw-semibold" style="font-size:.82rem">
          <i class="bi bi-list me-1 text-primary"></i>Années scolaires
        </span>
      </div>
      <div class="card-body p-0">
        <table class="table table-abz table-hover mb-0" style="font-size:.82rem">
          <thead>
            <tr>
              <th>Année</th>
              <th style="width:80px;text-align:center">Statut</th>
              <th style="width:110px;text-align:center">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($annees as $a): ?>
            <tr>
              <td class="fw-semibold"><?= h($a['val_annee']) ?></td>
              <td class="text-center">
                <?php if ($a['Etat_annee_scolaire']): ?>
                  <span class="badge" style="background:#d1fae5;color:#065f46;font-size:.7rem">Active</span>
                <?php else: ?>
                  <span class="badge" style="background:#f3f4f6;color:#6b7280;font-size:.7rem">Inactive</span>
                <?php endif; ?>
              </td>
              <td class="text-center">
                <div class="d-flex gap-1 justify-content-center">
                  <?php if (!$a['Etat_annee_scolaire']): ?>
                    <form method="post" style="display:inline" data-ajax-post-form>
                      <?= csrf_champ() ?>
                      <input type="hidden" name="action" value="annee_activer">
                      <input type="hidden" name="val_annee" value="<?= h($a['val_annee']) ?>">
                      <button class="btn btn-sm btn-light text-success" style="padding:2px 7px" title="Activer">
                        <i class="bi bi-toggle-off" style="font-size:.72rem"></i>
                      </button>
                    </form>
                  <?php else: ?>
                    <form method="post" style="display:inline" data-ajax-post-form>
                      <?= csrf_champ() ?>
                      <input type="hidden" name="action" value="annee_desactiver">
                      <input type="hidden" name="val_annee" value="<?= h($a['val_annee']) ?>">
                      <button class="btn btn-sm btn-light text-warning" style="padding:2px 7px" title="Désactiver">
                        <i class="bi bi-toggle-on" style="font-size:.72rem"></i>
                      </button>
                    </form>
                  <?php endif; ?>
                  <?php if ($est_superadmin && !$a['Etat_annee_scolaire']): ?>
                    <form method="post" style="display:inline" data-ajax-post-form>
                      <?= csrf_champ() ?>
                      <input type="hidden" name="action" value="annee_supprimer">
                      <input type="hidden" name="val_annee" value="<?= h($a['val_annee']) ?>">
                      <button class="btn btn-sm btn-light text-danger" style="padding:2px 7px"
                              onclick="return confirm('Supprimer l\'année <?= h(addslashes($a['val_annee'])) ?> ? Impossible si des inscriptions/notes existent déjà.')"
                              title="Supprimer">
                        <i class="bi bi-trash" style="font-size:.72rem"></i>
                      </button>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$annees): ?>
              <tr><td colspan="3" class="text-center text-muted py-3">Aucune année scolaire.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php elseif ($onglet === 'evaluations'): ?>
<!-- ══════════════════════════════════════════════
     ONGLET 3 — Évaluations (séquences UA1-UA6)
══════════════════════════════════════════════ -->
<?php $seq_active_now = db_one("SELECT s.*, t.libelle_trim FROM sequence s JOIN trimestre t ON t.id_trim=s.id_trim WHERE s.etat=1 LIMIT 1"); ?>

<div class="alert alert-light border py-2 mb-3" style="font-size:.8rem">
  <i class="bi bi-info-circle me-1"></i>
  Les évaluations (UA1-UA6) de l'année active <strong><?= h($annee_act['val_annee'] ?? '—') ?></strong> ne peuvent pas être
  créées ni supprimées ici — la saisie de notes s'appuie dessus (voir <code>composer_sequence</code>). Seuls le
  libellé et l'évaluation active peuvent être modifiés.
</div>

<div class="mb-3">
  <?php if ($seq_active_now): ?>
  <div class="d-flex align-items-center gap-3 p-3 rounded-3" style="background:#f0fdf4;border:1px solid #86efac">
    <i class="bi bi-lightning-charge-fill" style="color:#15803d;font-size:1.2rem;flex-shrink:0"></i>
    <div>
      <div class="fw-bold" style="color:#14532d;font-size:.88rem">Évaluation active : <?= h($seq_active_now['libelle_seq']) ?></div>
      <div style="font-size:.73rem;color:#16a34a"><?= h($seq_active_now['libelle_trim']) ?></div>
    </div>
  </div>
  <?php else: ?>
  <div class="d-flex align-items-center gap-3 p-3 rounded-3" style="background:#fff7ed;border:1px solid #fdba74">
    <i class="bi bi-exclamation-triangle-fill" style="color:#ea580c;font-size:1.2rem;flex-shrink:0"></i>
    <div class="fw-bold" style="color:#9a3412;font-size:.88rem">Aucune évaluation active</div>
  </div>
  <?php endif; ?>
</div>

<div class="card" style="border:1px solid #e5e7eb">
  <div class="card-header py-2 px-3" style="background:#f8faff">
    <span class="fw-bold" style="font-size:.8rem;color:#374151">Évaluations — <?= h($annee_act['val_annee'] ?? '—') ?></span>
  </div>
  <div class="table-responsive">
    <table class="table table-sm table-hover mb-0" style="font-size:.79rem">
      <thead style="background:#f8faff">
        <tr>
          <th>Évaluation</th>
          <th>Trimestre</th>
          <th class="text-center" style="width:80px">Statut</th>
          <th class="text-center" style="width:150px">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$sequences): ?>
          <tr><td colspan="4" class="text-center text-muted py-3">Aucune évaluation — créez d'abord une année scolaire (onglet précédent).</td></tr>
        <?php else: foreach ($sequences as $s): ?>
        <tr>
          <td class="fw-semibold"><?= h($s['libelle_seq']) ?></td>
          <td style="color:#6b7280"><?= h($s['libelle_trim']) ?></td>
          <td class="text-center">
            <?php if ($s['etat']): ?>
              <span class="badge" style="background:#d1fae5;color:#065f46;font-size:.68rem"><i class="bi bi-lightning-charge-fill text-warning me-1"></i>Active</span>
            <?php else: ?>
              <span class="badge" style="background:#f3f4f6;color:#6b7280;font-size:.68rem">Inactive</span>
            <?php endif; ?>
          </td>
          <td class="text-center">
            <div class="d-flex gap-1 justify-content-center">
              <button class="btn btn-sm btn-light" style="padding:2px 6px" title="Renommer"
                      onclick="editSeq(<?= (int) $s['id_seq'] ?>,<?= h(json_encode($s['libelle_seq'])) ?>)">
                <i class="bi bi-pencil" style="font-size:.72rem"></i>
              </button>
              <?php if (!$s['etat']): ?>
                <form method="post" style="display:inline" data-ajax-post-form>
                  <?= csrf_champ() ?>
                  <input type="hidden" name="action" value="seq_activer">
                  <input type="hidden" name="seq_id" value="<?= (int) $s['id_seq'] ?>">
                  <button class="btn btn-sm btn-light text-success" style="padding:2px 6px" title="Activer">
                    <i class="bi bi-toggle-off" style="font-size:.72rem"></i>
                  </button>
                </form>
              <?php else: ?>
                <form method="post" style="display:inline" data-ajax-post-form>
                  <?= csrf_champ() ?>
                  <input type="hidden" name="action" value="seq_desactiver">
                  <input type="hidden" name="seq_id" value="<?= (int) $s['id_seq'] ?>">
                  <button class="btn btn-sm btn-light text-warning" style="padding:2px 6px" title="Désactiver">
                    <i class="bi bi-toggle-on" style="font-size:.72rem"></i>
                  </button>
                </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal renommer évaluation -->
<div class="modal fade" id="modalEditSeq" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title fw-bold" style="font-size:.88rem"><i class="bi bi-pencil me-1 text-primary"></i>Renommer l'évaluation</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" data-ajax-post-form>
        <?= csrf_champ() ?>
        <input type="hidden" name="action" value="seq_renommer">
        <input type="hidden" name="seq_id" id="edit-seq-id">
        <div class="modal-body">
          <input type="text" name="seq_libelle" id="edit-seq-lib" class="form-control" required>
        </div>
        <div class="modal-footer py-2">
          <button class="btn btn-primary btn-sm">Enregistrer</button>
          <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Annuler</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
function editSeq(id, lib) {
    document.getElementById('edit-seq-id').value  = id;
    document.getElementById('edit-seq-lib').value = lib;
    new bootstrap.Modal(document.getElementById('modalEditSeq')).show();
}
</script>

<?php elseif ($onglet === 'apparence'): ?>
<!-- ══════════════════════════════════════════════
     ONGLET 4 — Apparence / Thème (100% client, localStorage — rien n'est
     écrit en base ; porté depuis LAM_ABZ, mêmes variables CSS déjà en place
     dans assets/css/style.css : --primary/--sidebar-bg/--sidebar-act).
══════════════════════════════════════════════ -->
<div class="row g-3">

  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header py-2 d-flex align-items-center gap-2" style="background:#f8faff">
        <i class="bi bi-droplet-fill text-primary"></i>
        <span class="fw-semibold" style="font-size:.82rem">Couleur principale</span>
      </div>
      <div class="card-body">
        <p style="font-size:.78rem;color:#6b7280">Choisissez la couleur d'accentuation de l'interface. La couleur s'applique aux boutons, liens actifs et éléments interactifs.</p>

        <div id="palette-grid">
          <?php
          $themes = [
            'indigo'    => ['label'=>'Indigo',      'cat'=>'Classique', 'primary'=>'#1e4fd8','dark'=>'#163aa0','sidebar'=>'#0f1a3a','sidebar2'=>'#16235a','act'=>'#2b53e6'],
            'violet'    => ['label'=>'Violet',      'cat'=>'Classique', 'primary'=>'#7c3aed','dark'=>'#5b21b6','sidebar'=>'#1e0842','sidebar2'=>'#2d0f6a','act'=>'#8b5cf6'],
            'teal'      => ['label'=>'Teal',        'cat'=>'Classique', 'primary'=>'#0d9488','dark'=>'#0f766e','sidebar'=>'#042f2e','sidebar2'=>'#0a4b48','act'=>'#14b8a6'],
            'emerald'   => ['label'=>'Émeraude',    'cat'=>'Classique', 'primary'=>'#059669','dark'=>'#047857','sidebar'=>'#052e16','sidebar2'=>'#064e3b','act'=>'#10b981'],
            'rose'      => ['label'=>'Rose',        'cat'=>'Classique', 'primary'=>'#e11d48','dark'=>'#be123c','sidebar'=>'#4c0519','sidebar2'=>'#881337','act'=>'#f43f5e'],
            'amber'     => ['label'=>'Ambre',       'cat'=>'Classique', 'primary'=>'#d97706','dark'=>'#b45309','sidebar'=>'#1c1a04','sidebar2'=>'#3f3608','act'=>'#f59e0b'],
            'cyan'      => ['label'=>'Cyan',        'cat'=>'Classique', 'primary'=>'#0891b2','dark'=>'#0e7490','sidebar'=>'#083344','sidebar2'=>'#0c4a6e','act'=>'#06b6d4'],
            'fuchsia'   => ['label'=>'Fuchsia',     'cat'=>'Classique', 'primary'=>'#a21caf','dark'=>'#86198f','sidebar'=>'#2d0039','sidebar2'=>'#4a0060','act'=>'#d946ef'],
            'orange'    => ['label'=>'Orange',      'cat'=>'Classique', 'primary'=>'#ea580c','dark'=>'#c2410c','sidebar'=>'#1c0600','sidebar2'=>'#431407','act'=>'#f97316'],
            'sky'       => ['label'=>'Ciel',        'cat'=>'Classique', 'primary'=>'#0284c7','dark'=>'#0369a1','sidebar'=>'#082030','sidebar2'=>'#0c3448','act'=>'#38bdf8'],
            'marine'    => ['label'=>'Marine (défaut)', 'cat'=>'Classique', 'primary'=>'#1a2744','dark'=>'#0f1a30','sidebar'=>'#1a2744','sidebar2'=>'#243358','act'=>'#c8960a'],
            'slate'     => ['label'=>'Ardoise',     'cat'=>'Classique', 'primary'=>'#475569','dark'=>'#334155','sidebar'=>'#0f172a','sidebar2'=>'#1e293b','act'=>'#64748b'],
            'dark_ink'  => ['label'=>'Encre',       'cat'=>'Dark',      'primary'=>'#818cf8','dark'=>'#6366f1','sidebar'=>'#08090f','sidebar2'=>'#0e1015','act'=>'#818cf8'],
            'dark_nord' => ['label'=>'Nord',        'cat'=>'Dark',      'primary'=>'#88c0d0','dark'=>'#6ba3b5','sidebar'=>'#2e3440','sidebar2'=>'#3b4252','act'=>'#88c0d0'],
            'dark_mid'  => ['label'=>'Minuit',      'cat'=>'Dark',      'primary'=>'#a78bfa','dark'=>'#8b5cf6','sidebar'=>'#070714','sidebar2'=>'#0d0d2e','act'=>'#c4b5fd'],
            'dark_carb' => ['label'=>'Carbone',     'cat'=>'Dark',      'primary'=>'#38bdf8','dark'=>'#0ea5e9','sidebar'=>'#111111','sidebar2'=>'#1a1a1a','act'=>'#38bdf8'],
            'dark_mat'  => ['label'=>'Matière',     'cat'=>'Dark',      'primary'=>'#4ade80','dark'=>'#22c55e','sidebar'=>'#121212','sidebar2'=>'#1e1e1e','act'=>'#4ade80'],
            'dark_tan'  => ['label'=>'Chocolat',    'cat'=>'Dark',      'primary'=>'#d4a574','dark'=>'#b8864e','sidebar'=>'#1c1008','sidebar2'=>'#2a1a0c','act'=>'#d4a574'],
          ];
          $cats = [];
          foreach ($themes as $key => $th) { $cats[$th['cat']][$key] = $th; }
          foreach ($cats as $cat_name => $cat_themes):
          ?>
          <div class="mb-2">
            <div style="font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;
                        color:#9ca3af;padding:4px 2px;margin-bottom:5px;border-bottom:1px solid #f3f4f6">
              <?= $cat_name === 'Dark' ? '<i class="bi bi-moon-fill me-1" style="color:#374151"></i>' : '<i class="bi bi-sun-fill me-1" style="color:#f59e0b"></i>' ?>
              <?= $cat_name ?>
            </div>
            <div style="display:flex;flex-wrap:wrap;gap:7px">
              <?php foreach ($cat_themes as $key => $th): ?>
              <label class="theme-card" style="cursor:pointer;border-radius:10px;overflow:hidden;
                     border:2px solid transparent;transition:all .15s;width:76px;text-align:center"
                     id="tc-<?= $key ?>">
                <input type="radio" name="theme_color" value="<?= $key ?>" style="position:absolute;opacity:0"
                       onchange="applyTheme('<?= $key ?>')">
                <div style="height:42px;background:linear-gradient(160deg,<?= $th['sidebar'] ?>,<?= $th['sidebar2'] ?>);
                            display:flex;align-items:center;justify-content:center;gap:4px;padding:4px">
                  <div style="width:12px;height:12px;border-radius:3px;background:<?= $th['act'] ?>;flex-shrink:0"></div>
                  <div style="flex:1;display:flex;flex-direction:column;gap:2px">
                    <div style="height:3px;border-radius:2px;background:<?= $th['primary'] ?>"></div>
                    <div style="height:2px;border-radius:2px;background:rgba(255,255,255,.2)"></div>
                    <div style="height:2px;border-radius:2px;background:rgba(255,255,255,.12)"></div>
                  </div>
                </div>
                <div style="padding:3px 2px;background:<?= $cat_name==='Dark'?'#1e2030':'#f8faff' ?>;
                            font-size:.62rem;font-weight:600;color:<?= $cat_name==='Dark'?'#c4c4d4':'#374151' ?>;
                            white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                  <?= h($th['label']) ?>
                </div>
              </label>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <p style="font-size:.72rem;color:#9ca3af"><i class="bi bi-info-circle me-1"></i>Couleur personnalisée :</p>
        <div class="d-flex align-items-center gap-2">
          <input type="color" id="custom-color" class="form-control form-control-sm"
                 style="width:44px;height:32px;padding:2px;cursor:pointer" value="#1a2744">
          <button class="btn btn-sm btn-outline-secondary" onclick="applyCustomColor()">
            <i class="bi bi-eyedropper me-1"></i>Appliquer
          </button>
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card mb-3">
      <div class="card-header py-2 d-flex align-items-center gap-2" style="background:#f8faff">
        <i class="bi bi-fonts text-primary"></i>
        <span class="fw-semibold" style="font-size:.82rem">Police d'écriture</span>
      </div>
      <div class="card-body">
        <div class="row g-2" id="font-grid">
          <?php
          $fonts = [
            'Inter'       => "Épuré, moderne, très lisible",
            'Poppins'     => "Géométrique, convivial, arrondi",
            'Roboto'      => "Neutre, professionnel, compact",
            'Montserrat'  => "Élégant, impactant, design",
          ];
          ?>
          <?php foreach ($fonts as $fname => $fdesc): ?>
          <div class="col-6">
            <label class="font-card d-block" style="cursor:pointer;border:2px solid #e5e7eb;border-radius:10px;padding:10px;transition:all .15s" id="fc-<?= strtolower($fname) ?>">
              <input type="radio" name="theme_font" value="<?= $fname ?>" style="position:absolute;opacity:0"
                     onchange="applyFont('<?= $fname ?>')">
              <div style="font-family:'<?= $fname ?>',sans-serif;font-size:1.1rem;font-weight:700;color:#1e2a3a;margin-bottom:2px">Aa Bb Cc</div>
              <div style="font-family:'<?= $fname ?>',sans-serif;font-size:.72rem;font-weight:600;color:#374151;margin-bottom:1px"><?= $fname ?></div>
              <div style="font-size:.65rem;color:#9ca3af"><?= $fdesc ?></div>
            </label>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="card" id="preview-card" style="border:2px solid #e5e7eb;border-radius:12px">
      <div class="card-header py-2 d-flex align-items-center gap-2"
           style="background:var(--preview-sidebar,#1a2744);border-radius:10px 10px 0 0">
        <div style="width:24px;height:24px;border-radius:6px;background:var(--preview-act,#c8960a);display:flex;align-items:center;justify-content:center">
          <i class="bi bi-grid-fill" style="color:#fff;font-size:.55rem"></i>
        </div>
        <span style="color:#fff;font-size:.72rem;font-weight:600;font-family:var(--preview-font,'Inter')">Jaynitaare</span>
      </div>
      <div class="card-body py-2 px-3">
        <div style="font-family:var(--preview-font,'Inter');font-size:.78rem;font-weight:700;color:#1e2a3a;margin-bottom:4px">Aperçu de l'interface</div>
        <div style="font-family:var(--preview-font,'Inter');font-size:.72rem;color:#6b7280;margin-bottom:8px">
          Voici comment votre interface apparaîtra après application du thème.
        </div>
        <div class="d-flex gap-2 flex-wrap">
          <button class="btn btn-sm" style="background:var(--preview-primary,#1a2744);color:#fff;border:none;font-family:var(--preview-font,'Inter');font-size:.72rem;padding:3px 10px;border-radius:6px">
            <i class="bi bi-check-lg me-1"></i>Bouton principal
          </button>
          <span style="display:inline-flex;align-items:center;gap:4px;background:#dbeafe;color:var(--preview-primary,#1a2744);padding:2px 8px;border-radius:6px;font-size:.7rem;font-family:var(--preview-font,'Inter');font-weight:600">
            <i class="bi bi-lightning-charge-fill"></i>Éval active
          </span>
        </div>
      </div>
    </div>
  </div>

</div>

<div class="d-flex gap-2 mt-3 align-items-center">
  <button class="btn btn-primary" onclick="sauvegarder()">
    <i class="bi bi-check-circle me-1"></i>Appliquer le thème
  </button>
  <button class="btn btn-outline-secondary btn-sm" onclick="reinitTheme()">
    <i class="bi bi-arrow-counterclockwise me-1"></i>Thème par défaut
  </button>
  <span id="theme-saved-msg" style="font-size:.78rem;color:#059669;display:none">
    <i class="bi bi-check-circle-fill me-1"></i>Thème enregistré !
  </span>
</div>

<script>
var THEMES = <?= json_encode($themes) ?>;
var FONTS  = <?= json_encode(array_keys($fonts)) ?>;

(function(){
    var p = JSON.parse(localStorage.getItem('jaynitaare_prefs') || '{}');
    if (p.theme) selectThemeCard(p.theme);
    if (p.font)  selectFontCard(p.font);
    if (p.custom_primary) document.getElementById('custom-color').value = p.custom_primary;
})();

function applyTheme(key) {
    var th = THEMES[key];
    if (!th) return;
    document.documentElement.style.setProperty('--primary',      th.primary);
    document.documentElement.style.setProperty('--primary-dark', th.dark);
    document.documentElement.style.setProperty('--sidebar-bg',   th.sidebar);
    document.documentElement.style.setProperty('--sidebar-bg2',  th.sidebar2);
    document.documentElement.style.setProperty('--sidebar-act',  th.act);
    document.documentElement.style.setProperty('--preview-primary', th.primary);
    document.documentElement.style.setProperty('--preview-sidebar', th.sidebar);
    document.documentElement.style.setProperty('--preview-act',     th.act);
    selectThemeCard(key);
    var p = JSON.parse(localStorage.getItem('jaynitaare_prefs') || '{}');
    p.theme = key; p.custom_primary = null;
    localStorage.setItem('jaynitaare_prefs', JSON.stringify(p));
}

function applyCustomColor() {
    var col = document.getElementById('custom-color').value;
    document.documentElement.style.setProperty('--primary', col);
    document.documentElement.style.setProperty('--preview-primary', col);
    document.querySelectorAll('.theme-card').forEach(function(c){ c.style.border='2px solid transparent'; });
    var p = JSON.parse(localStorage.getItem('jaynitaare_prefs') || '{}');
    p.custom_primary = col; p.theme = null;
    localStorage.setItem('jaynitaare_prefs', JSON.stringify(p));
}

function applyFont(fname) {
    document.body.style.fontFamily = "'" + fname + "', system-ui, sans-serif";
    document.documentElement.style.setProperty('--preview-font', "'" + fname + "'");
    selectFontCard(fname);
    var p = JSON.parse(localStorage.getItem('jaynitaare_prefs') || '{}');
    p.font = fname;
    localStorage.setItem('jaynitaare_prefs', JSON.stringify(p));
}

function selectThemeCard(key) {
    document.querySelectorAll('.theme-card').forEach(function(c){ c.style.border='2px solid transparent'; c.style.transform=''; });
    var card = document.getElementById('tc-' + key);
    if (card) { card.style.border='2px solid var(--primary)'; card.style.transform='scale(1.03)'; }
}

function selectFontCard(fname) {
    document.querySelectorAll('.font-card').forEach(function(c){ c.style.border='2px solid #e5e7eb'; c.style.background='#fff'; });
    var card = document.getElementById('fc-' + fname.toLowerCase());
    if (card) { card.style.border='2px solid var(--primary)'; card.style.background='#f0f4ff'; }
}

function sauvegarder() {
    var msg = document.getElementById('theme-saved-msg');
    msg.style.display = 'inline';
    setTimeout(function(){ msg.style.display='none'; }, 2500);
}

function reinitTheme() {
    localStorage.removeItem('jaynitaare_prefs');
    location.reload();
}
</script>

<?php elseif ($onglet === 'couleurs'): ?>
<!-- ══════════════════════════════════════════════════
     ONGLET 5 — Couleurs des bulletins (palette de rôles nommés)
     Maquette interactive (même principe que secondaire/pages/parametres/
     index.php, onglet « Couleurs bulletin », porté depuis ABZ_MBE) — 10
     rôles réels, répartis sur 4 gabarits (pdf/bulletin_trimestriel.php,
     bulletin_annuel.php, bulletin_trimestriel_arabe.php,
     bulletin_annuel_arabe.php) : 2 bascules (Langue / Période) montrent
     pour chacun la zone RÉELLE où chaque couleur s'applique. La version
     anglaise (bulletin_trimestriel_anglais.php / bulletin_annuel_anglais.php)
     partage EXACTEMENT les mêmes rôles/zones que la version française (même
     gabarit, juste bilingue dans l'autre sens) — pas de bascule séparée,
     « Français / Anglais » couvre les deux fichiers.
══════════════════════════════════════════════════ -->
<div class="card mb-3">
  <div class="card-body py-2" style="font-size:.78rem;color:#6b7280">
    Pas de personnalisation case par case : chaque couleur ci-dessous est un <strong>rôle visuel nommé</strong>,
    réutilisé partout où il apparaît sur le bulletin (parfois à plusieurs endroits à la fois — cadre photo ET
    bandeau titre ET en-tête de groupe, par exemple). Cliquez une zone colorée du bulletin, ou une ligne du
    panneau à droite, pour la changer : elle s'applique en direct partout où ce rôle est utilisé. Les bascules
    Langue/Période en bas à droite changent le gabarit affiché (mêmes 10 couleurs, réparties différemment).
  </div>
</div>

<style>
/* Préfixe cbk- : scope à cet onglet, pour ne rien casser ailleurs sur la page */
.cbk-wrap{display:grid;grid-template-columns:1fr 300px;gap:18px;align-items:start}
@media (max-width:900px){.cbk-wrap{grid-template-columns:1fr}}
.cbk-canvas{background:#eef1f6;border:1px solid #dbe1ea;border-radius:12px;padding:26px 16px;overflow-x:auto}
.cbk-side{display:flex;flex-direction:column;gap:14px;position:sticky;top:12px}
.cbk-panel{background:#fff;border:1px solid #dbe1ea;border-radius:10px;padding:12px}
.cbk-panel h6{font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;margin:0 0 8px;font-weight:700}
.cbk-role{display:flex;align-items:center;gap:9px;border:1px solid transparent;border-radius:8px;padding:6px;cursor:pointer;background:none;width:100%;text-align:left}
.cbk-role+.cbk-role{margin-top:2px}
.cbk-role:hover{background:#f6f8fb}
.cbk-role.is-open{background:#eaf1fd;border-color:#c7d8f0}
.cbk-role.is-dim{opacity:.4}
.cbk-role .sw{width:24px;height:24px;border-radius:6px;flex:none;border:1px solid rgba(0,0,0,.15)}
.cbk-role .lbl{font-size:.74rem;font-weight:600;color:#1f2937;display:block;line-height:1.25}
.cbk-role .hex{font-family:monospace;font-size:.68rem;color:#8a93a3}
.cbk-role .warn{font-size:.6rem;font-weight:700;color:#a23148;background:#fbe7ea;padding:1px 5px;border-radius:5px;margin-left:6px}
.cbk-seg{display:inline-flex;background:#f6f8fb;border:1px solid #dbe1ea;border-radius:8px;padding:2px;gap:2px;width:100%}
.cbk-seg button{flex:1;border:0;background:transparent;color:#6b7280;font-size:.72rem;font-weight:600;padding:6px 4px;border-radius:6px;cursor:pointer}
.cbk-seg button.is-active{background:#1a3c6b;color:#fff}

[data-cbk-role]{cursor:pointer}
[data-cbk-role]:not(tr):hover,[data-cbk-role].is-open:not(tr){outline:2px solid #2d5fa3;outline-offset:1px;border-radius:3px}
tr[data-cbk-role]:hover td,tr[data-cbk-role].is-open td{box-shadow:inset 0 0 0 2px #2d5fa3}

.cbk-popover{position:fixed;z-index:2000;width:296px;background:#fff;border:1px solid #dbe1ea;border-radius:12px;box-shadow:0 20px 40px -12px rgba(10,15,25,.35);padding:12px;font-size:.76rem;max-height:calc(100vh - 24px);overflow-y:auto}
.cbk-popover h6{font-size:.76rem;font-weight:700;margin:0 0 2px}
.cbk-popover .desc{font-size:.66rem;color:#6b7280;margin-bottom:9px;line-height:1.4}
.cbk-popover .row-cur{display:flex;align-items:center;gap:8px;margin-bottom:10px}
.cbk-popover .sw-big{width:32px;height:32px;border-radius:8px;border:1px solid rgba(0,0,0,.15);flex:none}
.cbk-popover input[type=color]{width:28px;height:28px;border:none;border-radius:7px;padding:0;background:none;cursor:pointer;flex:none}
.cbk-popover input[type=text]{flex:1;min-width:0;font-family:monospace;font-size:.72rem;border:1px solid #dbe1ea;border-radius:6px;padding:5px 7px}
.cbk-popover .sec-lbl{font-size:.62rem;text-transform:uppercase;letter-spacing:.05em;color:#8a93a3;font-weight:700;margin:0 0 6px}
.cbk-popover .composer{margin-bottom:10px}
.cbk-popover .slider-row{display:grid;grid-template-columns:52px 1fr 34px;align-items:center;gap:7px;margin-bottom:6px}
.cbk-popover .slider-row label{font-size:.66rem;color:#5a6377}
.cbk-popover .slider-row output{font-family:monospace;font-size:.64rem;color:#8a93a3;text-align:right}
.cbk-popover input[type=range]{-webkit-appearance:none;appearance:none;width:100%;height:12px;border-radius:6px;background:#eee;cursor:pointer}
.cbk-popover input[type=range]::-webkit-slider-thumb{-webkit-appearance:none;appearance:none;width:15px;height:15px;border-radius:50%;background:#fff;border:2px solid #2d5fa3;box-shadow:0 1px 3px rgba(0,0,0,.35);margin-top:-1.5px}
.cbk-popover input[type=range]::-moz-range-thumb{width:15px;height:15px;border-radius:50%;background:#fff;border:2px solid #2d5fa3;box-shadow:0 1px 3px rgba(0,0,0,.35)}
.cbk-popover .presets{display:grid;grid-template-columns:repeat(11,1fr);gap:4px;margin-bottom:6px;max-height:150px;overflow-y:auto;padding-right:2px}
.cbk-popover .preset{width:100%;aspect-ratio:1;border-radius:4px;border:1px solid rgba(0,0,0,.15);cursor:pointer;padding:0}
.cbk-popover .preset:hover{transform:scale(1.18)}
.cbk-popover .warnbox{display:flex;gap:5px;font-size:.64rem;color:#a23148;background:#fbe7ea;border-radius:7px;padding:6px 7px;margin-top:6px;line-height:1.35}
.cbk-popover .closeb{position:absolute;top:6px;right:8px;border:0;background:none;color:#9aa3b2;cursor:pointer;font-size:13px}

/* Le "papier" du bulletin : fond blanc fixe (comme un vrai PDF imprimé) —
   seules les zones marquées data-cbk-role suivent les couleurs choisies. */
.cbk-paper{position:relative;margin-inline:auto;max-width:760px;background:#fdfdfb;color:#111417;border:1.5px solid #cfd6e0;border-radius:10px;padding:14px 14px 12px;box-shadow:0 18px 38px -20px rgba(20,30,50,.35);font-size:11px;line-height:1.25}
.cbk-paper *{box-sizing:border-box}
.cbk-hdr3{display:grid;grid-template-columns:1fr auto 1fr;gap:6px;text-align:center;font-size:6.6px;line-height:1.35}
.cbk-logo{width:34px;height:34px;border-radius:50%;border:1.4px solid #1a3c6b;display:grid;place-items:center;font-weight:800;font-size:7px;color:#1a3c6b}
.cbk-title-pill{margin-top:6px;border-radius:16px;overflow:hidden;text-align:center}
.cbk-title-pill .main{padding:5px 8px;font-weight:800;font-style:italic;font-size:11.5px}
.cbk-annee{text-align:center;font-weight:700;font-size:7.6px;margin:5px 0}
.cbk-idgrid{display:flex;flex-wrap:wrap;gap:2px;margin-bottom:6px;font-size:6.6px}
.cbk-idc{border:1px solid #111;padding:2px 4px;flex:1 1 90px;font-weight:700}
.cbk-idc small{display:block;font-weight:400;font-style:italic;font-size:5.6px}
table.cbk-notes{width:100%;border-collapse:collapse;font-size:6.6px;margin-top:2px}
table.cbk-notes th,table.cbk-notes td{border:1px solid #111;padding:2.2px 2px;text-align:center}
tr.cbk-grp td{font-weight:800;text-align:left;font-size:6.6px}
tr.cbk-strip:nth-child(odd) td{background:var(--cbk-c-ligne_rayee)}
.cbk-bandeau4{display:grid;grid-template-columns:1fr 1.4fr 1.6fr 1fr;border-radius:12px;overflow:hidden;margin-top:6px;text-align:center;font-weight:800;font-size:6.6px}
.cbk-bandeau4 div{padding:4px 2px}
.cbk-res{display:grid;grid-template-columns:1fr 1fr;margin-top:6px;gap:0}
.cbk-res .lbl{font-weight:800;text-align:center;padding:4px;font-size:6.6px;border:1px solid #111;border-right:0}
.cbk-res .val{text-align:center;padding:4px;font-size:8px;font-weight:800;border:1px solid #111}
/* Arabe : direction RTL, mêmes classes réutilisées où le sens n'importe pas */
.cbk-ar{direction:rtl;font-family:inherit}
.cbk-ar table.cbk-notes th, .cbk-ar table.cbk-notes td{text-align:center}
.cbk-ar tr.cbk-grp td{text-align:right}
</style>

<form method="post" id="form-pdf-couleurs">
  <?= csrf_champ() ?>
  <input type="hidden" name="action" value="couleurs_save">
  <?php foreach ($pdf_couleurs_actuelles as $cle => $c): ?>
    <input type="hidden" name="c_<?= h($cle) ?>" id="cbk-in-<?= h($cle) ?>" value="<?= h($c['hex']) ?>">
  <?php endforeach; ?>

  <div class="cbk-wrap" style="<?php foreach ($pdf_couleurs_actuelles as $cle => $c) echo '--cbk-c-'.h($cle).':'.h($c['hex']).';'; ?>">
    <div class="cbk-canvas">
      <div class="cbk-paper" id="cbkPaper">

        <!-- ═══ Français / Anglais (bulletin_trimestriel.php + bulletin_annuel.php + variantes _anglais) ═══ -->
        <div data-cbk-gabarit="fr">
          <div class="cbk-hdr3">
            <div>RÉPUBLIQUE DU CAMEROUN<br>Paix – Travail – Patrie<br>Région / Département / Arrondissement</div>
            <div class="cbk-logo">LOGO</div>
            <div>REPUBLIC OF CAMEROON<br>Peace – Work – Fatherland<br>Region / Division / Subdivision</div>
          </div>
          <div class="cbk-title-pill" data-cbk-role="groupe_competence" tabindex="0" role="button" aria-label="Couleur : en-tête des groupes de compétences (aussi le bandeau titre et le cadre photo)">
            <div class="main">BULLETIN DE NOTES <span style="font-weight:400;font-style:normal">- REPORT CARD</span></div>
          </div>
          <div class="cbk-annee">Année scolaire : <?= h($annee_act['val_annee'] ?? '2025/2026') ?></div>

          <div class="cbk-idgrid">
            <div class="cbk-idc" data-cbk-role="ligne_alternee" tabindex="0" role="button" aria-label="Couleur : lignes alternées">CLASSE<small>Class</small></div>
            <div class="cbk-idc" data-cbk-role="ligne_alternee" tabindex="0" role="button" aria-label="Couleur : lignes alternées">NIU<small>ID</small></div>
            <div class="cbk-idc" data-cbk-role="ligne_alternee" tabindex="0" role="button" aria-label="Couleur : lignes alternées">EFFECTIF<small>Enrollment</small></div>
            <div class="cbk-idc" data-cbk-role="ligne_alternee" tabindex="0" role="button" aria-label="Couleur : lignes alternées">REDOUBLANT<small>Repeat</small></div>
          </div>

          <!-- Trimestriel : tableau de compétences par groupe, bandeaux TOTAL/DISCIPLINES -->
          <div data-cbk-periode="trim">
            <table class="cbk-notes">
              <thead>
                <tr data-cbk-role="ligne_alternee" tabindex="0" role="button" aria-label="Couleur : lignes alternées (aussi les en-têtes de colonnes)">
                  <th style="width:40%;text-align:left">COMPETENCES</th><th style="width:20%">UA1</th><th style="width:20%">UA2</th><th style="width:10%">MOY</th><th style="width:10%">COTE</th>
                </tr>
              </thead>
              <tbody>
                <tr class="cbk-grp" data-cbk-role="groupe_competence" tabindex="0" role="button" aria-label="Couleur : en-tête des groupes de compétences"><td colspan="5">MATHEMATIQUES</td></tr>
                <tr class="cbk-strip"><td style="text-align:left">Résoudre une équation</td><td>14.00</td><td>12.50</td><td>13.25</td><td>B</td></tr>
                <tr class="cbk-strip"><td style="text-align:left">Étudier une fonction</td><td>08.00</td><td>09.50</td><td>08.75</td><td>D</td></tr>
              </tbody>
            </table>
            <div class="cbk-bandeau4" style="grid-template-columns:1fr">
              <div data-cbk-role="entete_section" tabindex="0" role="button" aria-label="Couleur : en-têtes de section (aussi le bandeau DISCIPLINES/TRAVAIL/PROFIL)">TOTAL / MOYENNE PAR EVALUATION</div>
            </div>
            <div class="cbk-bandeau4">
              <div data-cbk-role="entete_section" tabindex="0" role="button" aria-label="Couleur : en-têtes de section">DISCIPLINES</div>
              <div data-cbk-role="entete_section" tabindex="0" role="button" aria-label="Couleur : en-têtes de section">TRAVAIL</div>
              <div data-cbk-role="entete_section" tabindex="0" role="button" aria-label="Couleur : en-têtes de section">PROFIL DE LA CLASSE</div>
              <div data-cbk-role="entete_section" tabindex="0" role="button" aria-label="Couleur : en-têtes de section">RESULTATS</div>
            </div>
            <div class="cbk-res">
              <div class="lbl" data-cbk-role="entete_section" tabindex="0" role="button" aria-label="Couleur : en-têtes de section">TOTAL POINTS</div>
              <div class="val" data-cbk-role="cellule_resultat" tabindex="0" role="button" aria-label="Couleur : cellules de résultat">154.25 / 200</div>
              <div class="lbl" data-cbk-role="cellule_resultat" tabindex="0" role="button" aria-label="Couleur : cellules de résultat">MOYENNE</div>
              <div class="val" data-cbk-role="entete_section" tabindex="0" role="button" aria-label="Couleur : en-têtes de section">15.42 / 20</div>
              <div class="lbl" data-cbk-role="entete_section" tabindex="0" role="button" aria-label="Couleur : en-têtes de section">RANG</div>
              <div class="val" data-cbk-role="cellule_resultat" tabindex="0" role="button" aria-label="Couleur : cellules de résultat">3e / 42</div>
              <div class="lbl" data-cbk-role="cellule_resultat" tabindex="0" role="button" aria-label="Couleur : cellules de résultat">APPRECIATION</div>
              <div class="val" data-cbk-role="entete_section" tabindex="0" role="button" aria-label="Couleur : en-têtes de section">Très bien</div>
            </div>
          </div>

          <!-- Annuel : tableau récapitulatif TRIM1/TRIM2/TRIM3, bandeaux RECAPITULATIF -->
          <div data-cbk-periode="annuel" hidden>
            <table class="cbk-notes">
              <thead>
                <tr data-cbk-role="entete_bleu" tabindex="0" role="button" aria-label="Couleur : en-têtes (bulletin annuel + arabe)">
                  <th style="width:34%;text-align:left">COMPETENCES</th><th style="width:12%">TRIM1</th><th style="width:12%">TRIM2</th><th style="width:12%">TRIM3</th><th style="width:10%">TOTAL</th><th style="width:10%">MOY</th><th style="width:10%">RANG</th>
                </tr>
              </thead>
              <tbody>
                <tr class="cbk-grp" data-cbk-role="groupe_competence" tabindex="0" role="button" aria-label="Couleur : en-tête des groupes de compétences"><td colspan="7">MATHEMATIQUES</td></tr>
                <tr><td style="text-align:left">Résoudre une équation</td><td>14</td><td>12.5</td><td>15</td>
                  <td data-cbk-role="ligne_alternee" tabindex="0" role="button" aria-label="Couleur : lignes alternées">41.5</td>
                  <td data-cbk-role="ligne_alternee" tabindex="0" role="button" aria-label="Couleur : lignes alternées">13.83</td>
                  <td data-cbk-role="ligne_alternee" tabindex="0" role="button" aria-label="Couleur : lignes alternées">3e</td></tr>
              </tbody>
            </table>
            <div class="cbk-bandeau4" style="grid-template-columns:1fr 1.6fr">
              <div data-cbk-role="entete_bleu" tabindex="0" role="button" aria-label="Couleur : en-têtes (bulletin annuel + arabe)">RECAPITULATIF DISCIPLINES</div>
              <div data-cbk-role="entete_bleu" tabindex="0" role="button" aria-label="Couleur : en-têtes (bulletin annuel + arabe)">RESULTATS DE L'ELEVE</div>
            </div>
            <div class="cbk-res">
              <div class="lbl" data-cbk-role="entete_bleu" tabindex="0" role="button" aria-label="Couleur : en-têtes (bulletin annuel + arabe)">MOYENNE ANNUELLE</div>
              <div class="val" data-cbk-role="cellule_resultat" tabindex="0" role="button" aria-label="Couleur : cellules de résultat">14.60 / 20</div>
              <div class="lbl" data-cbk-role="cellule_resultat" tabindex="0" role="button" aria-label="Couleur : cellules de résultat">RANG ANNUEL</div>
              <div class="val" data-cbk-role="entete_bleu" tabindex="0" role="button" aria-label="Couleur : en-têtes (bulletin annuel + arabe)">2e / 42</div>
            </div>
          </div>
        </div>

        <!-- ═══ Arabe (bulletin_trimestriel_arabe.php + bulletin_annuel_arabe.php) — RTL ═══ -->
        <div data-cbk-gabarit="ar" class="cbk-ar" hidden>
          <div class="cbk-hdr3">
            <div>REPUBLIQUE DU CAMEROUN<br>Paix – Travail – Patrie</div>
            <div class="cbk-logo">LOGO</div>
            <div style="direction:rtl">جمهورية الكاميرون<br>سلم – عمل – وطن</div>
          </div>
          <div class="cbk-title-pill" data-cbk-role="groupe_competence" tabindex="0" role="button" aria-label="Couleur : en-tête des groupes de compétences (aussi le cadre photo)">
            <div class="main" style="direction:rtl">بطاقة النتائج المدرسية</div>
          </div>
          <div class="cbk-annee">السنة الدراسية : <?= h($annee_act['val_annee'] ?? '2025/2026') ?></div>

          <table class="cbk-notes">
            <thead>
              <tr data-cbk-role="entete_tableau_arabe" tabindex="0" role="button" aria-label="Couleur : en-tête du tableau de compétences (bulletin arabe)">
                <th style="width:40%">الكفاءات</th><th style="width:20%">ت1</th><th style="width:20%">ت2</th><th style="width:10%">المعدل</th><th style="width:10%">الرتبة</th>
              </tr>
            </thead>
            <tbody>
              <tr class="cbk-grp" data-cbk-role="groupe_tableau_arabe" tabindex="0" role="button" aria-label="Couleur : bandeaux de groupe (tableau arabe)"><td colspan="5">الرياضيات</td></tr>
              <tr data-cbk-role="ligne_alternee" tabindex="0" role="button" aria-label="Couleur : lignes alternées"><td>حل معادلة</td><td>14.00</td><td>12.50</td><td>13.25</td><td>ب</td></tr>
              <tr data-cbk-role="totaux_tableau_arabe" tabindex="0" role="button" aria-label="Couleur : ligne TOTAUX (tableau arabe)"><td>المجموع</td><td colspan="4">154.25 / 200</td></tr>
            </tbody>
          </table>

          <div data-cbk-periode="trim">
            <div class="cbk-res">
              <div class="lbl" data-cbk-role="entete_tableau_arabe" tabindex="0" role="button" aria-label="Couleur : en-tête du tableau (aussi ce bloc résultats)">مجموع النقاط</div>
              <div class="val" data-cbk-role="cellule_resultat" tabindex="0" role="button" aria-label="Couleur : cellules de résultat">154.25 / 200</div>
              <div class="lbl" data-cbk-role="cellule_resultat" tabindex="0" role="button" aria-label="Couleur : cellules de résultat">المعدل</div>
              <div class="val" data-cbk-role="entete_tableau_arabe" tabindex="0" role="button" aria-label="Couleur : en-tête du tableau">15.42 / 20</div>
            </div>
          </div>
          <div data-cbk-periode="annuel" hidden>
            <div class="cbk-bandeau4" style="grid-template-columns:1fr">
              <div data-cbk-role="colonne_annuelle" tabindex="0" role="button" aria-label="Couleur : colonne ANNUELLE (bulletin annuel arabe)">سنوي — ANNUEL</div>
            </div>
          </div>
        </div>

      </div>
    </div>

    <aside class="cbk-side">
      <div class="cbk-panel">
        <div class="cbk-seg mb-2">
          <button type="button" data-cbkset="gabarit" data-cbkval="fr" class="is-active">Français / Anglais</button>
          <button type="button" data-cbkset="gabarit" data-cbkval="ar">Arabe</button>
        </div>
        <div class="cbk-seg">
          <button type="button" data-cbkset="periode" data-cbkval="trim" class="is-active">Trimestriel</button>
          <button type="button" data-cbkset="periode" data-cbkval="annuel">Bilan annuel</button>
        </div>
      </div>
      <div class="cbk-panel">
        <h6>Les 10 rôles de couleur</h6>
        <div id="cbkRoleList"></div>
      </div>
      <div class="cbk-panel d-flex flex-column gap-2">
        <button class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Enregistrer</button>
        <button type="submit" form="form-pdf-couleurs-reset" class="btn btn-outline-secondary btn-sm"
                onclick="return confirm('Réinitialiser les 10 couleurs aux valeurs par défaut ?')">
          <i class="bi bi-arrow-counterclockwise me-1"></i>Réinitialiser aux couleurs par défaut
        </button>
      </div>
    </aside>
  </div>
</form>
<form method="post" id="form-pdf-couleurs-reset" style="display:none">
  <?= csrf_champ() ?>
  <input type="hidden" name="action" value="couleurs_reinit">
</form>

<div class="cbk-popover" id="cbkPopover" hidden role="dialog" aria-modal="false">
  <button type="button" class="closeb" id="cbkPopClose" aria-label="Fermer">✕</button>
  <h6 id="cbkPopTitle"></h6>
  <p class="desc" id="cbkPopDesc"></p>
  <div class="row-cur">
    <div class="sw-big" id="cbkPopSwatch"></div>
    <input type="text" id="cbkPopHex" maxlength="7" spellcheck="false" aria-label="Code hexadécimal">
    <input type="color" id="cbkPopColor" aria-label="Pipette / sélecteur du système" title="Pipette du système">
  </div>
  <div class="composer">
    <p class="sec-lbl">Composer une couleur</p>
    <div class="slider-row">
      <label for="cbkHue">Teinte</label>
      <input type="range" id="cbkHue" min="0" max="360" step="1">
      <output id="cbkHueOut"></output>
    </div>
    <div class="slider-row">
      <label for="cbkSat">Saturation</label>
      <input type="range" id="cbkSat" min="0" max="100" step="1">
      <output id="cbkSatOut"></output>
    </div>
    <div class="slider-row">
      <label for="cbkLight">Luminosité</label>
      <input type="range" id="cbkLight" min="0" max="100" step="1">
      <output id="cbkLightOut"></output>
    </div>
  </div>
  <p class="sec-lbl">Suggestions</p>
  <div class="presets" id="cbkPopPresets"></div>
  <div class="warnbox" id="cbkPopWarn" hidden></div>
</div>

<script>
(function(){
  "use strict";
  var ROLES_META = {
    'groupe_competence':         { desc: "En-tête des groupes de compétences (ex. « MATHEMATIQUES »), et aussi bandeau titre + cadre photo sur le trimestriel FR/EN. Texte noir fixe.", gabarits: ['fr','ar'], periodes: ['trim','annuel'] },
    'ligne_alternee':            { desc: "Lignes/cellules alternées : grille CLASSE/NIU/EFFECTIF, en-têtes de colonnes (trim. FR/EN), cellules TOTAL/MOY/RANG (annuel FR/EN et arabe). Texte noir fixe.", gabarits: ['fr','ar'], periodes: ['trim','annuel'] },
    'ligne_rayee':                { desc: "Rayures d'une ligne sur deux dans le tableau de compétences du bulletin trimestriel FR/EN uniquement. Texte noir fixe.", gabarits: ['fr'], periodes: ['trim'] },
    'entete_section':             { desc: "En-têtes de section : bandeau TOTAL/MOYENNE PAR EVALUATION, bandeau DISCIPLINES/TRAVAIL/PROFIL, et la moitié du bloc RESULTATS — trimestriel FR/EN uniquement. Texte noir fixe.", gabarits: ['fr'], periodes: ['trim'] },
    'cellule_resultat':           { desc: "L'autre moitié (alternée) du bloc RESULTATS DE L'ELEVE — trimestriel FR/EN, bilan annuel FR/EN et bulletin arabe (les 3 gabarits la partagent). Texte noir fixe.", gabarits: ['fr','ar'], periodes: ['trim','annuel'] },
    'entete_bleu':                 { desc: "En-têtes du bilan annuel (FR/EN et arabe) : ligne COMPETENCES/TRIM1/TRIM2/TRIM3/TOTAL/MOY/RANG, bandeaux RECAPITULATIF DISCIPLINES / RESULTATS DE L'ELEVE. Texte noir fixe.", gabarits: ['fr','ar'], periodes: ['annuel'] },
    'entete_tableau_arabe':       { desc: "En-tête du tableau de compétences du bulletin trimestriel arabe, et la moitié du bloc résultats (alternée avec cellule_resultat). Texte noir fixe.", gabarits: ['ar'], periodes: ['trim','annuel'] },
    'groupe_tableau_arabe':       { desc: "Bandeaux de groupe du tableau de compétences (bulletin arabe) — équivalent de groupe_competence côté FR/EN pour ce gabarit. Texte noir fixe.", gabarits: ['ar'], periodes: ['trim','annuel'] },
    'totaux_tableau_arabe':       { desc: "Ligne TOTAUX du tableau de compétences (bulletin trimestriel arabe uniquement). Texte noir fixe.", gabarits: ['ar'], periodes: ['trim'] },
    'colonne_annuelle':           { desc: "Colonne ANNUEL mise en évidence sur le bilan annuel arabe uniquement. Texte noir fixe.", gabarits: ['ar'], periodes: ['annuel'] },
  };
  var ROLES = <?= json_encode(array_keys($pdf_couleurs_actuelles), JSON_UNESCAPED_UNICODE) ?>.map(function(cle){
    var m = ROLES_META[cle] || { desc: '', gabarits: ['fr','ar'], periodes: ['trim','annuel'] };
    return { id: cle, label: (<?= json_encode(array_map(fn($c) => $c['libelle'], $pdf_couleurs_actuelles), JSON_UNESCAPED_UNICODE) ?>)[cle] || cle, desc: m.desc, gabarits: m.gabarits, periodes: m.periodes, textFixed: '#000000' };
  });

  // Palette riche : 11 neutres + 17 familles de teinte × 5 nuances (clair → foncé).
  var PRESETS = [
    '#ffffff','#f8fafc','#e2e8f0','#cbd5e1','#94a3b8','#64748b','#475569','#334155','#1e293b','#0f172a','#000000',
    '#fee2e2','#fca5a5','#ef4444','#b91c1c','#7f1d1d',
    '#ffedd5','#fdba74','#f97316','#c2410c','#7c2d12',
    '#fef3c7','#fcd34d','#f59e0b','#b45309','#78350f',
    '#fef9c3','#fde047','#eab308','#a16207','#713f12',
    '#ecfccb','#bef264','#84cc16','#4d7c0f','#365314',
    '#dcfce7','#86efac','#22c55e','#15803d','#14532d',
    '#d1fae5','#6ee7b7','#10b981','#047857','#064e3b',
    '#ccfbf1','#5eead4','#14b8a6','#0f766e','#134e4a',
    '#cffafe','#67e8f9','#06b6d4','#0e7490','#164e63',
    '#e0f2fe','#7dd3fc','#0ea5e9','#0369a1','#0c4a6e',
    '#dbeafe','#93c5fd','#3b82f6','#1d4ed8','#1e3a8a',
    '#e0e7ff','#a5b4fc','#6366f1','#4338ca','#312e81',
    '#ede9fe','#c4b5fd','#8b5cf6','#6d28d9','#4c1d95',
    '#f3e8ff','#d8b4fe','#a855f7','#7e22ce','#581c87',
    '#fae8ff','#f0abfc','#d946ef','#a21caf','#701a75',
    '#fce7f3','#f9a8d4','#ec4899','#be185d','#831843',
    '#ffe4e6','#fda4af','#f43f5e','#be123c','#881337'
  ];

  // ── Conversions hex ⇄ TSL (composeur de couleur) ──────────────────
  function hexToRgb(hex){
    var c = hex.replace('#','');
    if (c.length===3) c = c.split('').map(function(ch){return ch+ch;}).join('');
    return { r:parseInt(c.substr(0,2),16), g:parseInt(c.substr(2,2),16), b:parseInt(c.substr(4,2),16) };
  }
  function rgbToHex(r,g,b){
    function h(v){ v=Math.max(0,Math.min(255,Math.round(v))); var s=v.toString(16); return s.length===1?'0'+s:s; }
    return '#'+h(r)+h(g)+h(b);
  }
  function rgbToHsl(r,g,b){
    r/=255; g/=255; b/=255;
    var max=Math.max(r,g,b), min=Math.min(r,g,b), h=0, s=0, l=(max+min)/2;
    if (max!==min){
      var d = max-min;
      s = l>0.5 ? d/(2-max-min) : d/(max+min);
      switch(max){
        case r: h=(g-b)/d+(g<b?6:0); break;
        case g: h=(b-r)/d+2; break;
        default: h=(r-g)/d+4;
      }
      h/=6;
    }
    return { h:h*360, s:s*100, l:l*100 };
  }
  function hslToRgb(h,s,l){
    h=((h%360)+360)%360/360; s/=100; l/=100;
    var r,g,b;
    if (s===0){ r=g=b=l; }
    else{
      var hue2rgb=function(p,q,t){ if(t<0)t+=1; if(t>1)t-=1; if(t<1/6)return p+(q-p)*6*t; if(t<1/2)return q; if(t<2/3)return p+(q-p)*(2/3-t)*6; return p; };
      var q = l<0.5 ? l*(1+s) : l+s-l*s;
      var p = 2*l-q;
      r=hue2rgb(p,q,h+1/3); g=hue2rgb(p,q,h); b=hue2rgb(p,q,h-1/3);
    }
    return { r:r*255, g:g*255, b:b*255 };
  }
  var hueEl = document.getElementById('cbkHue'), satEl = document.getElementById('cbkSat'), lightEl = document.getElementById('cbkLight');
  var hueOut = document.getElementById('cbkHueOut'), satOut = document.getElementById('cbkSatOut'), lightOut = document.getElementById('cbkLightOut');
  hueEl.style.background = 'linear-gradient(to right,#f00,#ff0,#0f0,#0ff,#00f,#f0f,#f00)';
  function updateSliderGradients(h,s,l){
    satEl.style.background  = 'linear-gradient(to right, hsl('+h+',0%,'+l+'%), hsl('+h+',100%,'+l+'%))';
    lightEl.style.background = 'linear-gradient(to right, hsl('+h+','+s+'%,0%), hsl('+h+','+s+'%,50%), hsl('+h+','+s+'%,100%))';
  }
  function setSlidersFromHex(hex){
    var rgb = hexToRgb(hex), hsl = rgbToHsl(rgb.r, rgb.g, rgb.b);
    hueEl.value = hsl.h; satEl.value = hsl.s; lightEl.value = hsl.l;
    hueOut.textContent = Math.round(hsl.h)+'°'; satOut.textContent = Math.round(hsl.s)+'%'; lightOut.textContent = Math.round(hsl.l)+'%';
    updateSliderGradients(hsl.h, hsl.s, hsl.l);
  }
  function hexFromSliders(){
    var rgb = hslToRgb(parseFloat(hueEl.value), parseFloat(satEl.value), parseFloat(lightEl.value));
    return rgbToHex(rgb.r, rgb.g, rgb.b);
  }

  var byId = {}; ROLES.forEach(function(r){ byId[r.id] = r; });
  var root = document.querySelector('.cbk-wrap');
  var state = {};
  ROLES.forEach(function(r){ state[r.id] = document.getElementById('cbk-in-'+r.id).value; root.style.setProperty('--cbk-c-'+r.id, state[r.id]); });
  // Fond de chaque zone cliquable = variable CSS de son rôle. Un même rôle
  // colore parfois des zones à des endroits très différents du bulletin
  // (ex. groupe_competence : bandeau titre ET cadre photo ET en-tête de
  // groupe) — plus simple et plus sûr de le poser une fois ici, par
  // élément, que de dupliquer une règle CSS par zone.
  document.querySelectorAll('[data-cbk-role]').forEach(function(el){
    el.style.background = 'var(--cbk-c-' + el.dataset.cbkRole + ')';
  });

  function luminance(hex){
    var c = hex.replace('#','');
    if (c.length===3) c = c.split('').map(function(ch){return ch+ch;}).join('');
    var r=parseInt(c.substr(0,2),16)/255,g=parseInt(c.substr(2,2),16)/255,b=parseInt(c.substr(4,2),16)/255;
    [r,g,b]=[r,g,b].map(function(v){return v<=0.03928?v/12.92:Math.pow((v+0.055)/1.055,2.4);});
    return 0.2126*r+0.7152*g+0.0722*b;
  }
  function contrast(a,b){ var la=luminance(a)+0.05, lb=luminance(b)+0.05; return la>lb?la/lb:lb/la; }
  function warningFor(role){
    var hex = state[role.id];
    if (role.textFixed) return contrast(hex, role.textFixed) < 2.6 ? "Contraste faible avec le texte (noir fixe) posé dessus." : null;
    return null;
  }

  var openId = null;
  var gabaritActif = 'fr', periodeActive = 'trim';

  function setRoleColor(id, hex){
    state[id] = hex;
    root.style.setProperty('--cbk-c-'+id, hex);
    document.getElementById('cbk-in-'+id).value = hex;
    renderList();
  }
  function applyRole(id, hex){
    setRoleColor(id, hex);
    if (openId === id) fillPopover(id);
  }

  var listEl = document.getElementById('cbkRoleList');
  function renderList(){
    listEl.innerHTML = '';
    ROLES.forEach(function(r){
      var dim = r.gabarits.indexOf(gabaritActif) === -1 || r.periodes.indexOf(periodeActive) === -1;
      var warn = warningFor(r);
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'cbk-role' + (dim?' is-dim':'') + (openId===r.id?' is-open':'');
      btn.dataset.cbkRole = r.id;
      btn.innerHTML = '<span class="sw" style="background:'+state[r.id]+'"></span>'+
        '<span style="flex:1;min-width:0"><span class="lbl">'+r.label+'</span>'+
        '<span class="hex">'+state[r.id]+(warn?'<span class="warn">⚠ contraste</span>':'')+'</span></span>';
      listEl.appendChild(btn);
    });
  }

  var pop = document.getElementById('cbkPopover');
  function fillPopover(id){
    var r = byId[id];
    document.getElementById('cbkPopTitle').textContent = r.label;
    document.getElementById('cbkPopDesc').textContent = r.desc;
    document.getElementById('cbkPopSwatch').style.background = state[id];
    document.getElementById('cbkPopColor').value = state[id];
    document.getElementById('cbkPopHex').value = state[id];
    setSlidersFromHex(state[id]);
    var w = warningFor(r), we = document.getElementById('cbkPopWarn');
    if (w){ we.hidden=false; we.innerHTML='⚠ '+w; } else { we.hidden=true; }
  }
  var presetsEl = document.getElementById('cbkPopPresets');
  presetsEl.innerHTML = PRESETS.map(function(hex){ return '<button type="button" class="preset" style="background:'+hex+'" data-hex="'+hex+'" aria-label="'+hex+'"></button>'; }).join('');
  presetsEl.addEventListener('click', function(e){ var b=e.target.closest('.preset'); if(!b||!openId) return; applyRole(openId,b.dataset.hex); });
  document.getElementById('cbkPopColor').addEventListener('input', function(e){ if(openId) applyRole(openId,e.target.value); });
  document.getElementById('cbkPopHex').addEventListener('input', function(e){ var v=e.target.value.trim(); if(/^#[0-9a-fA-F]{6}$/.test(v)&&openId) applyRole(openId,v); });

  [hueEl, satEl, lightEl].forEach(function(el){
    el.addEventListener('input', function(){
      var h=parseFloat(hueEl.value), s=parseFloat(satEl.value), l=parseFloat(lightEl.value);
      hueOut.textContent = Math.round(h)+'°'; satOut.textContent = Math.round(s)+'%'; lightOut.textContent = Math.round(l)+'%';
      updateSliderGradients(h,s,l);
      if (!openId) return;
      var hex = hexFromSliders();
      setRoleColor(openId, hex);
      document.getElementById('cbkPopSwatch').style.background = hex;
      document.getElementById('cbkPopHex').value = hex;
      document.getElementById('cbkPopColor').value = hex;
      var w = warningFor(byId[openId]), we = document.getElementById('cbkPopWarn');
      if (w){ we.hidden=false; we.innerHTML='⚠ '+w; } else { we.hidden=true; }
    });
  });

  function openPicker(id, anchor){
    openId = id;
    fillPopover(id);
    pop.hidden = false;
    var ar = anchor.getBoundingClientRect();
    var pw=296, ph=pop.offsetHeight||420;
    var left = Math.min(Math.max(8, ar.left), window.innerWidth-pw-8);
    var top = ar.bottom+8;
    if (top+ph > window.innerHeight-8) top = Math.max(8, ar.top-ph-8);
    pop.style.left = left+'px'; pop.style.top = top+'px';
    document.querySelectorAll('[data-cbk-role]').forEach(function(el){ el.classList.toggle('is-open', el.dataset.cbkRole===id); });
    renderList();
  }
  function closePicker(){
    openId = null; pop.hidden = true;
    document.querySelectorAll('[data-cbk-role]').forEach(function(el){ el.classList.remove('is-open'); });
    renderList();
  }
  document.getElementById('cbkPopClose').addEventListener('click', closePicker);
  document.addEventListener('keydown', function(e){ if(e.key==='Escape') closePicker(); });
  document.addEventListener('click', function(e){
    if (!openId) return;
    if (pop.contains(e.target)) return;
    if (e.target.closest('[data-cbk-role]')) return;
    closePicker();
  });
  document.addEventListener('click', function(e){
    var el = e.target.closest('[data-cbk-role]'); if(!el) return;
    openPicker(el.dataset.cbkRole, el);
  });
  document.addEventListener('keydown', function(e){
    if (e.key !== 'Enter' && e.key !== ' ') return;
    var el = e.target.closest('[data-cbk-role]'); if(!el) return;
    e.preventDefault();
    openPicker(el.dataset.cbkRole, el);
  });

  document.querySelectorAll('.cbk-seg').forEach(function(seg){
    seg.addEventListener('click', function(e){
      var b = e.target.closest('button'); if(!b) return;
      seg.querySelectorAll('button').forEach(function(x){ x.classList.remove('is-active'); });
      b.classList.add('is-active');
      var set=b.dataset.cbkset, val=b.dataset.cbkval;
      if (set==='gabarit'){
        gabaritActif = val;
        document.querySelector('[data-cbk-gabarit="fr"]').hidden = (val!=='fr');
        document.querySelector('[data-cbk-gabarit="ar"]').hidden = (val!=='ar');
      }
      if (set==='periode'){
        periodeActive = val;
        document.querySelectorAll('[data-cbk-periode="trim"]').forEach(function(el){ el.hidden = (val!=='trim'); });
        document.querySelectorAll('[data-cbk-periode="annuel"]').forEach(function(el){ el.hidden = (val!=='annuel'); });
      }
      renderList();
    });
  });

  renderList();
})();
</script>

<?php endif; ?>

</div><!-- /#parametres-zone -->
<?php if ($es_partiel) exit; // rien de plus dans une réponse AJAX partielle. ?>

<?php
$ajax_zone_id = 'parametres-zone'; // voir layout/footer.php — initAjaxZone() y est appelé
require_once __DIR__ . '/../../layout/footer.php';
