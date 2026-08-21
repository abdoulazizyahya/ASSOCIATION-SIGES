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
                $logo = 'logo_etab.' . $ext;
                move_uploaded_file($_FILES['logo']['tmp_name'], __DIR__ . '/../../assets/uploads/' . $logo);
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
                $signature = 'signature_directeur.' . $ext;
                move_uploaded_file($_FILES['signature']['tmp_name'], __DIR__ . '/../../assets/uploads/' . $signature);
            }
        }

        db_exec(
            "UPDATE etablissement SET
                Nom_Etab_Fr=?, Nom_Etab_An=?, Initial_Etab=?, Immatriculation_Etab=?, boite_postal=?, ville_etab=?,
                tel_etab=?, email_etab=?,
                pays_etab_fr=?, region_etab_fr=?, delegation_regional_fr=?, delegation_departemental_fr=?,
                pays_etab_en=?, region_etab_en=?, delegation_regional_en=?, delegation_departemental_en=?,
                lieu_etab=?, fonction_dirigeant_fr=?, fonction_dirigeant_en=?, logo=?, signature=?,
                republique_fr=?, devise_fr=?, ministere_fr=?, delegation_reg_fr=?, delegation_dep_fr=?, arrondissement_fr=?, ecole_fr=?,
                republique_ar=?, devise_ar=?, ministere_ar=?, delegation_reg_ar=?, delegation_dep_ar=?, arrondissement_ar=?, ecole_ar=?
             WHERE IDEtablissement=?",
            [
                post('nom_fr'), post('nom_en'), post('sigle'), post('immatriculation'), post('boite_postale'), post('ville'),
                post('telephone'), post('email'),
                post('pays_fr'), post('region_fr'), post('delegation_regionale_fr'), post('delegation_departementale_fr'),
                post('pays_en'), post('region_en'), post('delegation_regionale_en'), post('delegation_departementale_en'),
                post('lieu'), post('fonction_dirigeant_fr'), post('fonction_dirigeant_en'), $logo, $signature,
                post('republique_fr'), post('devise_fr'), post('ministere_fr'), post('delegation_reg_fr'), post('delegation_dep_fr'), post('arrondissement_fr'), post('ecole_fr'),
                post('republique_ar'), post('devise_ar'), post('ministere_ar'), post('delegation_reg_ar'), post('delegation_dep_ar'), post('arrondissement_ar'), post('ecole_ar'),
                $etab['IDEtablissement'],
            ]
        );
        flash_set('succes', 'Établissement mis à jour.');
        rediriger('pages/parametres/index.php?onglet=etablissement');
    }

    // ── Années scolaires ───────────────────────────────────────
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
                $msg_bareme = $nb_bareme > 0 ? " Barème de $nb_bareme compétence(s) reporté automatiquement." : '';
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
              <input type="text" name="delegation_regionale_fr" class="form-control" value="<?= h($ve('delegation_regional_fr')) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Department (EN)</label>
              <input type="text" name="delegation_regionale_en" class="form-control" value="<?= h($ve('delegation_regional_en')) ?>">
            </div>
            <div class="col-md-6">
              <!-- Même correction : delegation_departemental_fr contient en
                   réalité l'ARRONDISSEMENT (ex. "ARRONDISEMNET DE NGAOUNDERE
                   I"), pas une délégation départementale. -->
              <label class="form-label">Arrondissement (FR)</label>
              <input type="text" name="delegation_departementale_fr" class="form-control" value="<?= h($ve('delegation_departemental_fr')) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Arrondissement (EN)</label>
              <input type="text" name="delegation_departementale_en" class="form-control" value="<?= h($ve('delegation_departemental_en')) ?>">
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
            Texte affiché en haut des bulletins/certificats de la piste arabe (colonne française à gauche, arabe à droite —
            voir <code>pdf/header_pdf_tcpdf.php</code>). Indépendant des champs ci-dessus : ces libellés sont ceux affichés
            tels quels sur ces documents précis, pas une reprise automatique de l'identité de l'établissement.
          </p>
          <div class="row g-compact">
            <?php foreach ([
                ['republique_fr',     'republique_ar',     'République',              'REPUBLIQUE DU CAMEROUN'],
                ['devise_fr',         'devise_ar',         'Devise',                  'Paix - Travail - Patrie'],
                ['ministere_fr',      'ministere_ar',      'Ministère',               ''],
                ['delegation_reg_fr', 'delegation_reg_ar', 'Délégation régionale',    ''],
                ['delegation_dep_fr', 'delegation_dep_ar', 'Délégation départementale', ''],
                ['arrondissement_fr', 'arrondissement_ar', 'Arrondissement',          ''],
                ['ecole_fr',          'ecole_ar',          "Nom de l'école",          ''],
            ] as [$champ_fr, $champ_ar, $label, $placeholder]): ?>
            <div class="col-md-6">
              <label class="form-label"><?= h($label) ?> (FR)</label>
              <input type="text" name="<?= $champ_fr ?>" class="form-control" value="<?= h($ve($champ_fr)) ?>" placeholder="<?= h($placeholder) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label"><?= h($label) ?> (AR)</label>
              <input type="text" name="<?= $champ_ar ?>" class="form-control" dir="rtl" value="<?= h($ve($champ_ar)) ?>">
            </div>
            <?php endforeach; ?>
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
                 style="max-width:140px;max-height:140px;border-radius:8px;border:1px solid #e5e7eb;margin-bottom:.6rem">
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
                 style="max-width:140px;max-height:80px;border-radius:8px;border:1px solid #e5e7eb;margin-bottom:.6rem;background:#f8faff">
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

<?php elseif ($onglet === 'annees'): ?>
<!-- ══════════════════════════════════════════════
     ONGLET 2 — Années scolaires
══════════════════════════════════════════════ -->
<div class="alert alert-light border py-2 mb-3" style="font-size:.8rem">
  <i class="bi bi-info-circle me-1"></i>
  Créer une année provisionne automatiquement sa structure standard (3 trimestres, 6 évaluations UA1-UA6) —
  la même que celle utilisée pour la saisie de notes des années existantes.
</div>

<div class="row g-3">
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
  <div class="col-md-7">
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
                  <?php if (!$a['Etat_annee_scolaire']): ?>
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
<!-- ══════════════════════════════════════════════
     ONGLET 5 — Couleurs du bulletin PDF
══════════════════════════════════════════════ -->
<div class="alert alert-light border py-2 mb-3" style="font-size:.8rem">
  <i class="bi bi-info-circle me-1"></i>
  Couleurs de fond utilisées sur les bulletins PDF (trimestriel/annuel, français/arabe).
  Chaque couleur peut être utilisée à plusieurs endroits — la changer ici met à jour tous les bulletins générés ensuite.
</div>

<form method="post" data-ajax-post-form>
  <?= csrf_champ() ?>
  <input type="hidden" name="action" value="couleurs_save">
  <div class="row g-3">
    <?php foreach ($couleurs as $c):
      $hex = sprintf('#%02x%02x%02x', $c['r'], $c['g'], $c['b']);
    ?>
    <div class="col-md-6">
      <div class="card h-100">
        <div class="card-body d-flex align-items-center gap-3">
          <input type="color" name="c_<?= h($c['cle']) ?>" value="<?= h($hex) ?>"
                 class="form-control form-control-color flex-shrink-0"
                 style="width:52px;height:52px;padding:3px;cursor:pointer"
                 oninput="document.getElementById('apercu_<?= h($c['cle']) ?>').style.background=this.value">
          <div style="flex:1;min-width:0">
            <div class="fw-semibold" style="font-size:.84rem"><?= h($c['libelle']) ?></div>
            <div class="text-muted" style="font-size:.7rem">Aperçu :</div>
          </div>
          <div id="apercu_<?= h($c['cle']) ?>" style="width:60px;height:32px;border-radius:6px;border:1px solid #d1d5db;background:<?= h($hex) ?>;flex-shrink:0"></div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="mt-3 d-flex gap-2">
    <button class="btn btn-primary btn-sm px-4"><i class="bi bi-check-lg me-1"></i>Enregistrer</button>
  </div>
</form>

<form method="post" class="mt-2" data-ajax-post-form onsubmit="return confirm('Réinitialiser toutes les couleurs du bulletin à leurs valeurs par défaut ?')">
  <?= csrf_champ() ?>
  <input type="hidden" name="action" value="couleurs_reinit">
  <button class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-counterclockwise me-1"></i>Réinitialiser aux couleurs par défaut
  </button>
</form>

<?php endif; ?>

</div><!-- /#parametres-zone -->
<?php if ($es_partiel) exit; // rien de plus dans une réponse AJAX partielle. ?>

<?php
$ajax_zone_id = 'parametres-zone'; // voir layout/footer.php — initAjaxZone() y est appelé
require_once __DIR__ . '/../../layout/footer.php';
