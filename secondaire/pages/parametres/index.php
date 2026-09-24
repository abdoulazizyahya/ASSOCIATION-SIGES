<?php
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
// FONDATEUR a les mêmes accès que PROVISEUR sur cette page (Années/
// Évaluations/Mentions/Apparence), SAUF : modifier les infos de
// l'établissement (action='etab'), renommer ou supprimer une année
// existante (annee_modifier/annee_supprimer) — il peut seulement
// activer/désactiver une année, comme PROVISEUR. La création d'année
// (annee_creer) est de toute façon déjà réservée au propriétaire/
// superadministrateur du système pour TOUS les comptes locaux, FONDATEUR
// compris (voir garde POST plus bas). Demande explicite du 17/09/2026.
exiger_role(['ADMIN','PROVISEUR','FONDATEUR']);
$est_fondateur_local = role_connecte() === 'FONDATEUR';

auto_activer_sequences();

$onglet = $_GET['onglet'] ?? 'etablissement';
$etab   = get_etablissement();
$sig_chef_etablissement = get_signature_titulaires()['chef_etablissement'] ?? null;
// Création d'année scolaire réservée au propriétaire/superadmin du système
// (voir garde POST plus bas) — variable réutilisée par le template pour
// masquer le formulaire de création aux comptes locaux ADMIN/PROVISEUR/FONDATEUR.
$est_superadmin = function_exists('est_superadmin_association') && est_superadmin_association();

// ══════════════════════════════════════════════════
//  POST — Établissement
// ══════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $action = post('action');

    // ── Établissement ─────────────────────────────
    // FONDATEUR peut modifier les infos de l'établissement (nom, logo,
    // signature…), comme côté primaire (pages/parametres/index.php, où
    // FONDATEUR n'a aucune restriction sur cet onglet — « structure »,
    // gouvernée par fondateur_ecriture_permise()). La restriction posée ici
    // le 17/09/2026 était une incohérence propre au secondaire, levée le
    // 24/09/2026 (demande explicite).
    if ($action === 'etab') {
        $logo = $etab['logo'] ?? null;
        if (!empty($_FILES['logo']['tmp_name']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $ext_ok = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png'];
            $ext    = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
            $fi     = finfo_open(FILEINFO_MIME_TYPE);
            $mime   = finfo_file($fi, $_FILES['logo']['tmp_name']);
            finfo_close($fi);
            if (isset($ext_ok[$ext]) && $ext_ok[$ext] === $mime) {
                // Préfixe par école (multi-établissement) + génération du
                // filigrane, même logique que pages/parametres/index.php
                // (primaire) — jusqu'ici absente ici, d'où (1) un risque de
                // collision entre écoles secondaire sur le même nom de
                // fichier et (2) un filigrane jamais créé à l'enregistrement
                // (seulement généré à la volée, en retard, par l'association
                // au premier affichage de la case école). Chemin CORRIGÉ :
                // ce fichier est à secondaire/pages/parametres/, donc 3
                // niveaux (pas 2) séparent __DIR__ de la racine assets/
                // uploads/ — l'ancien chemin ('../../assets/uploads/', qui
                // n'existe pas) faisait échouer silencieusement l'upload.
                // Demande explicite du 17/09/2026.
                $logo     = upload_prefixe_etab() . 'logo_etab.' . $ext;
                $logo_abs = upload_dir_etab(__DIR__ . '/../../../assets/uploads') . 'logo_etab.' . $ext;
                move_uploaded_file($_FILES['logo']['tmp_name'], $logo_abs);
                generer_filigrane_logo($logo_abs, __DIR__ . '/../../../assets/uploads/' . chemin_filigrane_logo($logo));
            }
        }
        db_exec(
            "UPDATE etablissement SET nom_fr=?,nom_en=?,sigle=?,immatriculation=?,boite_postale=?,ville=?,
             telephone=?,email=?,region_fr=?,region_en=?,departement_fr=?,division_en=?,
             arrondissement_fr=?,subdivision_en=?,chef_etablissement=?,chef_etablissement_en=?,logo=? WHERE id=?",
            [post('nom_fr'),post('nom_en'),post('sigle'),post('immatriculation'),post('boite_postale'),post('ville'),
             post('telephone'),post('email'),post('region_fr'),post('region_en'),
             post('departement_fr'),post('division_en'),post('arrondissement_fr'),
             post('subdivision_en'),post('chef_etablissement'),post('chef_etablissement_en'),$logo,$etab['id']]
        );

        // Signature numérique du chef d'établissement (même principe que le
        // logo) : jamais appliquée automatiquement sur un document, l'admin
        // la fournit une fois ici, et chaque impression choisit ensuite si
        // elle s'applique à ce document précis (case à cocher à l'aperçu).
        // Stockée dans signature_titulaire (catalogue multi-signataires —
        // voir aussi secondaire/pages/paiements/signatures.php pour Intendant/APEE).
        if (!empty($_FILES['signature']['tmp_name']) && $_FILES['signature']['error'] === UPLOAD_ERR_OK) {
            $ext_ok = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png'];
            $ext    = strtolower(pathinfo($_FILES['signature']['name'], PATHINFO_EXTENSION));
            $fi     = finfo_open(FILEINFO_MIME_TYPE);
            $mime   = finfo_file($fi, $_FILES['signature']['tmp_name']);
            finfo_close($fi);
            if (isset($ext_ok[$ext]) && $ext_ok[$ext] === $mime) {
                // Fond blanc nettoyé/rendu transparent (PNG) : le texte du
                // document reste visible même si la signature est déplacée
                // par-dessus (voir signature_traiter_transparence()).
                // Même correctif de profondeur de chemin que le logo
                // ci-dessus (secondaire/pages/parametres/ est 3 niveaux sous
                // la racine, pas 2) + préfixe par école pour éviter qu'une
                // 2e école secondaire n'écrase la signature de la première.
                $fichier_sig = upload_prefixe_etab() . 'signature_chef_etablissement.png';
                $sig_abs = upload_dir_etab(__DIR__ . '/../../../assets/uploads') . 'signature_chef_etablissement.png';
                if (signature_traiter_transparence($_FILES['signature']['tmp_name'], $sig_abs)) {
                    db_exec("UPDATE signature_titulaire SET fichier=? WHERE code='chef_etablissement'", [$fichier_sig]);
                }
            }
        }
        flash_set('succes', 'Établissement mis à jour.');
        rediriger('secondaire/pages/parametres/index.php?onglet=etablissement');
    }

    // ── Suppression du logo / de la signature ─────
    if ($action === 'etab_media_supprimer') {
        $cible = post('cible');
        if ($cible === 'logo' && !empty($etab['logo'])) {
            $abs = __DIR__ . '/../../../assets/uploads/' . $etab['logo'];
            if (is_file($abs)) @unlink($abs);
            $filigrane = __DIR__ . '/../../../assets/uploads/' . chemin_filigrane_logo($etab['logo']);
            if (is_file($filigrane)) @unlink($filigrane);
            db_exec("UPDATE etablissement SET logo=NULL WHERE id=?", [$etab['id']]);
            flash_set('succes', 'Logo supprimé.');
        } elseif ($cible === 'signature' && !empty($sig_chef_etablissement['fichier'])) {
            $abs = __DIR__ . '/../../../assets/uploads/' . $sig_chef_etablissement['fichier'];
            if (is_file($abs)) @unlink($abs);
            db_exec("UPDATE signature_titulaire SET fichier=NULL WHERE code='chef_etablissement'");
            flash_set('succes', 'Signature supprimée.');
        }
        rediriger('secondaire/pages/parametres/index.php?onglet=etablissement');
    }

    // ── Année scolaire ────────────────────────────
    // Création réservée au propriétaire/superadmin du système (même
    // politique que pages/parametres/index.php côté primaire — demande
    // explicite du 17/09/2026) : ni ADMIN/PROVISEUR local, ni un membre
    // association simplement en visite (sans être superadmin), ne doit
    // pouvoir créer une nouvelle année. Activer/désactiver restent en
    // revanche ouvertes aux comptes locaux (ADMIN/PROVISEUR), inchangées
    // ci-dessous.
    if ($action === 'annee_creer' && !(function_exists('est_superadmin_association') && est_superadmin_association())) {
        flash_set('erreur', 'Seul le propriétaire ou le superadministrateur du système peut créer une année scolaire.');
        rediriger('secondaire/pages/parametres/index.php?onglet=annees');
    }
    if ($action === 'annee_creer') {
        $lib = post('libelle_annee');
        if ($lib) {
            db_exec("INSERT IGNORE INTO annee_scolaire (libelle) VALUES (?)", [$lib]);
            // Trimestres + séquences créés dès la création de l'année (pas
            // seulement à son activation) — même modèle que le bootstrap
            // paresseux de get_trimestre_actif() (fonctions.php), demande
            // explicite du 17/09/2026. Aucun n'est marqué actif ici (l'année
            // elle-même ne l'est pas forcément) : get_trimestre_actif()
            // activera le premier trimestre/la première séquence le jour où
            // cette année deviendra réellement l'année active.
            $id_nouvelle = (int) db_val("SELECT id FROM annee_scolaire WHERE libelle=?", [$lib]);
            if ($id_nouvelle) provisionner_trimestres_annee($id_nouvelle);
            flash_set('succes', "Année $lib créée.");
        }
        rediriger('secondaire/pages/parametres/index.php?onglet=annees');
    }
    if ($action === 'annee_activer') {
        $aid = (int)post('id_annee');
        db_exec("UPDATE annee_scolaire SET active=0");
        db_exec("UPDATE annee_scolaire SET active=1 WHERE id=?", [$aid]);
        flash_set('succes', 'Année scolaire activée.');
        rediriger('secondaire/pages/parametres/index.php?onglet=annees');
    }
    if ($action === 'annee_desactiver') {
        $aid = (int)post('id_annee');
        db_exec("UPDATE annee_scolaire SET active=0 WHERE id=?", [$aid]);
        flash_set('succes', 'Année scolaire désactivée.');
        rediriger('secondaire/pages/parametres/index.php?onglet=annees');
    }
    // FONDATEUR : seulement activer/désactiver une année (ci-dessus), jamais
    // la renommer ni la supprimer — demande explicite du 17/09/2026.
    if (in_array($action, ['annee_supprimer', 'annee_modifier'], true) && $est_fondateur_local) {
        flash_set('erreur', "Le fondateur peut activer/désactiver une année, mais pas la modifier ni la supprimer.");
        rediriger('secondaire/pages/parametres/index.php?onglet=annees');
    }
    if ($action === 'annee_supprimer') {
        $aid = (int)post('id_annee');
        db_exec("DELETE FROM annee_scolaire WHERE id=?", [$aid]);
        flash_set('succes', 'Année supprimée.');
        rediriger('secondaire/pages/parametres/index.php?onglet=annees');
    }
    if ($action === 'annee_modifier') {
        $aid = (int)post('id_annee');
        $lib = post('libelle_annee');
        if ($lib) db_exec("UPDATE annee_scolaire SET libelle=? WHERE id=?", [$lib, $aid]);
        flash_set('succes', 'Année mise à jour.');
        rediriger('secondaire/pages/parametres/index.php?onglet=annees');
    }

    // ── Évaluations / Séquences ───────────────────
    if ($action === 'seq_creer') {
        $lib    = post('seq_libelle');
        $trim   = (int)post('seq_id_trim');
        $ordre  = (int)post('seq_ordre') ?: 1;
        $debut  = post('seq_debut') ?: null;
        $fin    = post('seq_fin')   ?: null;
        if ($lib && $trim) {
            // Max 2 séquences par trimestre : les bulletins (colonnes "Rappel"
            // séquence 1/2) et leur mise en page sont conçus pour ce nombre.
            $nb_existantes = (int)db_val("SELECT COUNT(*) FROM sequence WHERE id_trim=?", [$trim]);
            if ($nb_existantes >= 2) {
                flash_set('erreur', "Un trimestre ne peut avoir plus de 2 séquences (contrainte de mise en page des bulletins).");
                rediriger('secondaire/pages/parametres/index.php?onglet=evaluations');
            }
            db_exec("INSERT INTO sequence (libelle,id_trim,ordre,active,date_debut,date_fin) VALUES (?,?,?,0,?,?)",
                    [$lib, $trim, $ordre, $debut, $fin]);
            flash_set('succes', 'Évaluation créée.');
        }
        rediriger('secondaire/pages/parametres/index.php?onglet=evaluations');
    }
    if ($action === 'seq_modifier') {
        $sid   = (int)post('seq_id');
        $lib   = post('seq_libelle');
        $trim  = (int)post('seq_id_trim');
        $ordre = (int)post('seq_ordre') ?: 1;
        $debut = post('seq_debut') ?: null;
        $fin   = post('seq_fin')   ?: null;
        $nb_existantes = (int)db_val("SELECT COUNT(*) FROM sequence WHERE id_trim=? AND id<>?", [$trim, $sid]);
        if ($nb_existantes >= 2) {
            flash_set('erreur', "Un trimestre ne peut avoir plus de 2 séquences (contrainte de mise en page des bulletins).");
            rediriger('secondaire/pages/parametres/index.php?onglet=evaluations');
        }
        db_exec("UPDATE sequence SET libelle=?,id_trim=?,ordre=?,date_debut=?,date_fin=? WHERE id=?",
                [$lib, $trim, $ordre, $debut, $fin, $sid]);
        flash_set('succes', 'Évaluation mise à jour.');
        rediriger('secondaire/pages/parametres/index.php?onglet=evaluations');
    }
    if ($action === 'seq_activer') {
        $sid = (int)post('seq_id');
        db_exec("UPDATE sequence SET active=0");
        db_exec("UPDATE sequence SET active=1 WHERE id=?", [$sid]);
        flash_set('succes', 'Évaluation activée.');
        rediriger('secondaire/pages/parametres/index.php?onglet=evaluations');
    }
    if ($action === 'seq_desactiver') {
        $sid = (int)post('seq_id');
        db_exec("UPDATE sequence SET active=0 WHERE id=?", [$sid]);
        flash_set('succes', 'Évaluation désactivée.');
        rediriger('secondaire/pages/parametres/index.php?onglet=evaluations');
    }
    if ($action === 'seq_supprimer') {
        $sid = (int)post('seq_id');
        db_exec("DELETE FROM sequence WHERE id=?", [$sid]);
        flash_set('succes', 'Évaluation supprimée.');
        rediriger('secondaire/pages/parametres/index.php?onglet=evaluations');
    }
    if ($action === 'trim_activer') {
        // Trimestre actif (compétences/APC) : distinct de l'activation des
        // séquences ci-dessus — utilisé par secondaire/pages/notes/index.php (onglet
        // « classe ») pour la saisie de notes par compétence. Contrôle
        // déplacé ici depuis secondaire/pages/matieres/liste.php (voir prompt_continuite,
        // mise à jour du 04/08/2026).
        $id_trim_new = (int)post('id_trim');
        $t = db_one("SELECT id_annee FROM trimestre WHERE id=?", [$id_trim_new]);
        $ok = false;
        if ($t) {
            db_exec("UPDATE trimestre SET active=0 WHERE id_annee=?", [$t['id_annee']]);
            db_exec("UPDATE trimestre SET active=1 WHERE id=?", [$id_trim_new]);
            $ok = true;
        }
        // Requête AJAX (bandeau « Trimestre actif ») : réponse JSON, pas de
        // rechargement de page — voir prompt_continuite, 05/08/2026.
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
            $nouveau = $ok ? db_one(
                "SELECT t.*, a.libelle AS annee_lib FROM trimestre t
                 JOIN annee_scolaire a ON a.id=t.id_annee WHERE t.id=?", [$id_trim_new]
            ) : null;
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok'    => $ok,
                'label' => $nouveau ? ($nouveau['libelle'] . ' — ' . $nouveau['annee_lib']) : null,
            ]);
            exit;
        }
        if ($ok) flash_set('succes', 'Trimestre actif mis à jour.');
        rediriger('secondaire/pages/parametres/index.php?onglet=evaluations');
    }
    if ($action === 'forcer_auto_activation') {
        // Force la vérification immédiate avec CURDATE() MySQL
        db_exec("UPDATE sequence SET active=0
                 WHERE date_fin IS NOT NULL AND date_fin < CURDATE()");
        $nb = (int)db_val("SELECT COUNT(*) FROM sequence WHERE active=1");
        if ($nb === 0) {
            $a = db_one("SELECT id FROM sequence
                         WHERE date_debut IS NOT NULL AND date_fin IS NOT NULL
                           AND date_debut <= CURDATE() AND date_fin >= CURDATE()
                         ORDER BY date_debut DESC LIMIT 1");
            if ($a) {
                db_exec("UPDATE sequence SET active=1 WHERE id=?", [$a['id']]);
                flash_set('succes', 'Vérification effectuée — évaluation activée.');
            } else {
                flash_set('info', 'Vérification effectuée — aucune évaluation à activer pour aujourd\'hui.');
            }
        } else {
            flash_set('succes', 'Vérification effectuée — état des évaluations mis à jour.');
        }
        rediriger('secondaire/pages/parametres/index.php?onglet=evaluations');
    }

    // ── Mentions automatiques du bulletin ─────────
    if ($action === 'mentions') {
        $id_a = (int)post('id_annee');
        $num  = fn($k) => (float)str_replace(',', '.', post($k));
        $int  = fn($k) => (int)post($k);
        db_exec(
            "INSERT INTO reglage_mention_bulletin
                (id_annee, moy_tableau_honneur, heures_max_tableau_honneur, moy_encouragement, moy_felicitation,
                 moy_avert_travail_min, moy_avert_travail_max, moy_blame_travail_max,
                 heures_avert_conduite_min, heures_avert_conduite_max, heures_blame_conduite_min)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                moy_tableau_honneur=VALUES(moy_tableau_honneur), heures_max_tableau_honneur=VALUES(heures_max_tableau_honneur),
                moy_encouragement=VALUES(moy_encouragement), moy_felicitation=VALUES(moy_felicitation),
                moy_avert_travail_min=VALUES(moy_avert_travail_min), moy_avert_travail_max=VALUES(moy_avert_travail_max),
                moy_blame_travail_max=VALUES(moy_blame_travail_max),
                heures_avert_conduite_min=VALUES(heures_avert_conduite_min), heures_avert_conduite_max=VALUES(heures_avert_conduite_max),
                heures_blame_conduite_min=VALUES(heures_blame_conduite_min)",
            [
                $id_a, $num('moy_tableau_honneur'), $int('heures_max_tableau_honneur'), $num('moy_encouragement'), $num('moy_felicitation'),
                $num('moy_avert_travail_min'), $num('moy_avert_travail_max'), $num('moy_blame_travail_max'),
                $int('heures_avert_conduite_min'), $int('heures_avert_conduite_max'), $int('heures_blame_conduite_min'),
            ]
        );
        flash_set('succes', 'Seuils des mentions du bulletin mis à jour.');
        rediriger('secondaire/pages/parametres/index.php?onglet=mentions');
    }

    // ── Couleurs des bulletins (palette de rôles nommés, table pdf_couleur,
    //    même principe que côté primaire — pages/parametres/index.php) ────
    if ($action === 'pdf_couleurs_enregistrer') {
        $n = 0;
        foreach (array_keys(pdf_couleurs_defaut_secondaire()) as $cle) {
            $hex = post("couleur_$cle");
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string)$hex)) continue;
            [$r, $g, $b] = [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
            db_exec("UPDATE pdf_couleur SET r=?, g=?, b=? WHERE cle=?", [$r, $g, $b, $cle]);
            $n++;
        }
        flash_set('succes', "$n couleur(s) mise(s) à jour.");
        rediriger('secondaire/pages/parametres/index.php?onglet=couleurs_bulletin');
    }
    if ($action === 'pdf_couleurs_reinitialiser') {
        foreach (pdf_couleurs_defaut_secondaire() as $cle => $d) {
            db_exec("UPDATE pdf_couleur SET r=?, g=?, b=? WHERE cle=?", [$d['r'], $d['g'], $d['b'], $cle]);
        }
        flash_set('succes', 'Couleurs des bulletins réinitialisées.');
        rediriger('secondaire/pages/parametres/index.php?onglet=couleurs_bulletin');
    }
}

// ── Données ────────────────────────────────────────────────────
$annees     = db_all("SELECT * FROM annee_scolaire ORDER BY libelle DESC");
$annee_act  = get_annee_active();
$trimestres = db_all("SELECT t.*, a.libelle AS annee_lib FROM trimestre t
                       JOIN annee_scolaire a ON a.id=t.id_annee
                       WHERE a.active=1 ORDER BY t.ordre");
$sequences  = db_all("SELECT s.*, t.libelle AS trim_lib, a.libelle AS annee_lib
                       FROM sequence s
                       JOIN trimestre t ON t.id=s.id_trim
                       JOIN annee_scolaire a ON a.id=t.id_annee
                       ORDER BY a.libelle DESC, t.ordre, s.ordre");
$reglage_mention_actuel = get_reglage_mention_bulletin((int)($annee_act['id'] ?? 0));

// Couleurs des bulletins : table pdf_couleur absente tant que la migration
// v3 secondaire n'a pas tourné sur cette école (onglet quand même affichable
// — get_reglage_mention_bulletin() ci-dessus a le même filet ailleurs dans
// ce fichier — juste vide, avec un bandeau d'avertissement dans l'onglet).
try {
    $pdf_couleurs_actuelles = [];
    foreach (db_all("SELECT cle, libelle, r, g, b FROM pdf_couleur ORDER BY cle") as $c) {
        $pdf_couleurs_actuelles[$c['cle']] = [
            'libelle' => $c['libelle'],
            'hex'     => sprintf('#%02x%02x%02x', $c['r'], $c['g'], $c['b']),
        ];
    }
} catch (\Throwable $e) {
    $pdf_couleurs_actuelles = [];
}

$titre_page = 'Paramètres';
require_once __DIR__ . '/../../../layout/header.php';
?>

<div class="page-titre">
  <h4><i class="bi bi-gear me-1 text-primary"></i>Paramètres</h4>
</div>

<!-- Onglets -->
<ul class="nav nav-tabs mb-3" style="border-bottom:2px solid #e5e7eb">
  <?php foreach ([
      'etablissement'     => ['bi-building',   'Établissement'],
      'annees'            => ['bi-calendar3',  'Années scolaires'],
      'evaluations'       => ['bi-list-check', 'Évaluations'],
      'mentions'          => ['bi-award',      'Mentions'],
      'couleurs_bulletin' => ['bi-palette2',   'Couleurs bulletin'],
      'reglages'          => ['bi-palette',    'Apparence'],
  ] as $key => [$ico, $label]): ?>
  <li class="nav-item">
    <a class="nav-link <?= $onglet===$key?'active':'' ?>"
       href="<?= APP_URL ?>/secondaire/pages/parametres/index.php?onglet=<?= $key ?>">
      <i class="bi <?= $ico ?> me-1"></i><?= $label ?>
    </a>
  </li>
  <?php endforeach; ?>
</ul>


<?php if ($onglet === 'etablissement'): ?>
<!-- ══════════════════════════════════════════════════
     ONGLET 1 — Établissement
══════════════════════════════════════════════════ -->
<div class="card">
  <div class="card-body">
    <form method="post" enctype="multipart/form-data">
      <?= csrf_champ() ?>
      <input type="hidden" name="action" value="etab">
      <fieldset>
      <div class="row g-compact">
        <div class="col-md-8">
          <label class="form-label">Nom français <span class="text-danger">*</span></label>
          <input type="text" name="nom_fr" class="form-control" required value="<?= h($etab['nom_fr']??'') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Sigle</label>
          <input type="text" name="sigle" class="form-control" value="<?= h($etab['sigle']??'') ?>">
        </div>
        <div class="col-md-8">
          <label class="form-label">Nom anglais</label>
          <input type="text" name="nom_en" class="form-control" value="<?= h($etab['nom_en']??'') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Immatriculation</label>
          <input type="text" name="immatriculation" class="form-control" value="<?= h($etab['immatriculation']??'') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Boîte postale</label>
          <input type="text" name="boite_postale" class="form-control" value="<?= h($etab['boite_postale']??'') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Ville</label>
          <input type="text" name="ville" class="form-control" value="<?= h($etab['ville']??'') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Téléphone</label>
          <input type="text" name="telephone" class="form-control" value="<?= h($etab['telephone']??'') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Email</label>
          <input type="email" name="email" class="form-control" value="<?= h($etab['email']??'') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Région (FR)</label>
          <input type="text" name="region_fr" class="form-control" value="<?= h($etab['region_fr']??'') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Region (EN)</label>
          <input type="text" name="region_en" class="form-control" value="<?= h($etab['region_en']??'') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Département (FR)</label>
          <input type="text" name="departement_fr" class="form-control" value="<?= h($etab['departement_fr']??'') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Division (EN)</label>
          <input type="text" name="division_en" class="form-control" value="<?= h($etab['division_en']??'') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Arrondissement (FR)</label>
          <input type="text" name="arrondissement_fr" class="form-control" value="<?= h($etab['arrondissement_fr']??'') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Subdivision (EN)</label>
          <input type="text" name="subdivision_en" class="form-control" value="<?= h($etab['subdivision_en']??'') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Chef d'établissement</label>
          <input type="text" name="chef_etablissement" class="form-control" value="<?= h($etab['chef_etablissement']??'') ?>">
          <div class="form-text" style="font-size:.72rem">Nom affiché sous « LE PROVISEUR, » sur les bulletins, certificats, reçus… (documents en français).</div>
        </div>
        <div class="col-md-6">
          <label class="form-label">Chef d'établissement (EN)</label>
          <input type="text" name="chef_etablissement_en" class="form-control" value="<?= h($etab['chef_etablissement_en']??'') ?>">
          <div class="form-text" style="font-size:.72rem">Nom affiché sous « The Principal » sur les documents en anglais.</div>
        </div>
        <div class="col-md-6">
          <label class="form-label">Logo</label>
          <?php if (!empty($etab['logo'])): ?>
            <div class="mb-1">
              <img src="<?= APP_URL ?>/assets/uploads/<?= h($etab['logo']) ?>"
                   style="height:48px;border-radius:6px;border:1px solid #e5e7eb">
              <button type="submit" form="secondaire_supprimer_logo" class="btn btn-sm btn-outline-danger ms-1">
                <i class="bi bi-trash"></i> Supprimer
              </button>
            </div>
          <?php endif; ?>
          <input type="file" name="logo" class="form-control" accept="image/jpeg,image/png">
        </div>
        <div class="col-md-6">
          <label class="form-label">Signature du chef d'établissement</label>
          <?php if (!empty($sig_chef_etablissement['fichier'])): ?>
            <div class="mb-1">
              <img src="<?= APP_URL ?>/assets/uploads/<?= h($sig_chef_etablissement['fichier']) ?>"
                   style="height:48px;border-radius:6px;border:1px solid #e5e7eb;background:#fff">
              <button type="submit" form="secondaire_supprimer_signature" class="btn btn-sm btn-outline-danger ms-1">
                <i class="bi bi-trash"></i> Supprimer
              </button>
            </div>
          <?php endif; ?>
          <input type="file" name="signature" class="form-control" accept="image/jpeg,image/png">
          <div class="form-text" style="font-size:.72rem">
            Image de la signature (idéalement PNG à fond transparent). Jamais appliquée automatiquement :
            chaque impression propose de l'ajouter ou non au document.
          </div>
        </div>
      </div>
      <div class="mt-3">
        <button class="btn btn-primary btn-sm px-4">
          <i class="bi bi-check-lg me-1"></i>Enregistrer
        </button>
      </div>
      </fieldset>
    </form>
    <!-- Boutons "Supprimer" ci-dessus : formulaires séparés (rattachés via
         l'attribut form="...") pour ne pas renvoyer tous les autres champs. -->
    <form id="secondaire_supprimer_logo" method="post" style="display:none"
          onsubmit="return confirm('Supprimer le logo actuel ?')">
      <?= csrf_champ() ?>
      <input type="hidden" name="action" value="etab_media_supprimer">
      <input type="hidden" name="cible" value="logo">
    </form>
    <form id="secondaire_supprimer_signature" method="post" style="display:none"
          onsubmit="return confirm('Supprimer la signature actuelle ?')">
      <?= csrf_champ() ?>
      <input type="hidden" name="action" value="etab_media_supprimer">
      <input type="hidden" name="cible" value="signature">
    </form>
  </div>
</div>


<?php elseif ($onglet === 'annees'): ?>
<!-- ══════════════════════════════════════════════════
     ONGLET 2 — Années scolaires
══════════════════════════════════════════════════ -->
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
        <form method="post" class="d-flex gap-2">
          <?= csrf_champ() ?>
          <input type="hidden" name="action" value="annee_creer">
          <input type="text" name="libelle_annee" class="form-control"
                 placeholder="ex: 2026/2027" pattern="\d{4}/\d{4}" required>
          <button class="btn btn-primary btn-sm px-3">
            <i class="bi bi-plus-lg"></i>
          </button>
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
              <th>Libellé</th>
              <th style="width:80px;text-align:center">Statut</th>
              <th style="width:160px;text-align:center">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($annees as $a): ?>
            <tr>
              <td class="fw-semibold"><?= h($a['libelle']) ?></td>
              <td class="text-center">
                <?php if ($a['active']): ?>
                  <span class="badge" style="background:#d1fae5;color:#065f46;font-size:.7rem">Active</span>
                <?php else: ?>
                  <span class="badge" style="background:#f3f4f6;color:#6b7280;font-size:.7rem">Inactive</span>
                <?php endif; ?>
              </td>
              <td class="text-center">
                <div class="d-flex gap-1 justify-content-center">
                  <!-- Modifier : pas pour le fondateur (activer/désactiver seulement) -->
                  <?php if (!$est_fondateur_local): ?>
                  <button class="btn btn-sm btn-light" style="padding:2px 7px"
                          onclick="editAnnee(<?= $a['id'] ?>,<?= h(json_encode($a['libelle'])) ?>)"
                          title="Modifier"><i class="bi bi-pencil" style="font-size:.72rem"></i></button>
                  <?php endif; ?>
                  <!-- Activer / Désactiver -->
                  <?php if (!$a['active']): ?>
                    <form method="post" style="display:inline">
                      <?= csrf_champ() ?>
                      <input type="hidden" name="action"   value="annee_activer">
                      <input type="hidden" name="id_annee" value="<?= $a['id'] ?>">
                      <button class="btn btn-sm btn-light text-success" style="padding:2px 7px" title="Activer">
                        <i class="bi bi-toggle-off" style="font-size:.72rem"></i>
                      </button>
                    </form>
                  <?php else: ?>
                    <form method="post" style="display:inline">
                      <?= csrf_champ() ?>
                      <input type="hidden" name="action"   value="annee_desactiver">
                      <input type="hidden" name="id_annee" value="<?= $a['id'] ?>">
                      <button class="btn btn-sm btn-light text-warning" style="padding:2px 7px" title="Désactiver">
                        <i class="bi bi-toggle-on" style="font-size:.72rem"></i>
                      </button>
                    </form>
                  <?php endif; ?>
                  <!-- Supprimer : pas pour le fondateur -->
                  <?php if (!$a['active'] && !$est_fondateur_local): ?>
                    <form method="post" style="display:inline">
                      <?= csrf_champ() ?>
                      <input type="hidden" name="action"   value="annee_supprimer">
                      <input type="hidden" name="id_annee" value="<?= $a['id'] ?>">
                      <button class="btn btn-sm btn-light text-danger" style="padding:2px 7px"
                              onclick="return confirm('Supprimer l\'année <?= h(addslashes($a['libelle'])) ?> ?')"
                              title="Supprimer">
                        <i class="bi bi-trash" style="font-size:.72rem"></i>
                      </button>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Modal modifier année -->
<div class="modal fade" id="modalEditAnnee" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title fw-bold" style="font-size:.88rem">
          <i class="bi bi-pencil me-1 text-primary"></i>Modifier l'année
        </h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post">
        <?= csrf_champ() ?>
        <input type="hidden" name="action"   value="annee_modifier">
        <input type="hidden" name="id_annee" id="edit-annee-id">
        <div class="modal-body">
          <input type="text" name="libelle_annee" id="edit-annee-lib" class="form-control"
                 placeholder="ex: 2026/2027" required>
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
function editAnnee(id, lib) {
    document.getElementById('edit-annee-id').value  = id;
    document.getElementById('edit-annee-lib').value = lib;
    new bootstrap.Modal(document.getElementById('modalEditAnnee')).show();
}
</script>


<?php elseif ($onglet === 'evaluations'): ?>
<!-- ══════════════════════════════════════════════════
     ONGLET 3 — Évaluations / Séquences
══════════════════════════════════════════════════ -->

<?php
$seq_active_now  = db_one("SELECT * FROM sequence WHERE active=1 LIMIT 1");
$trimestre_actif = get_trimestre_actif();
$today = date('Y-m-d');
?>

<!-- Trimestre actif (compétences/APC) — distinct des séquences ci-dessous -->
<div class="card mb-3" style="border:1px solid #fde68a;background:#fffbeb">
  <div class="card-body py-2 px-3 d-flex align-items-center gap-3 flex-wrap">
    <span style="font-size:.78rem;color:#92400e">
      <i class="bi bi-calendar-check me-1"></i>Trimestre actif (compétences) :
      <strong id="trim-actif-label"><?= $trimestre_actif ? h($trimestre_actif['libelle'] . ' — ' . $trimestre_actif['annee_lib']) : 'aucun' ?></strong>
      <i id="trim-actif-ok" class="bi bi-check-circle-fill text-success ms-1 d-none"></i>
    </span>
    <form method="post" class="d-flex align-items-center gap-2" id="form-trim-activer">
      <?= csrf_champ() ?>
      <input type="hidden" name="action" value="trim_activer">
      <select name="id_trim" class="form-select form-select-sm" style="width:auto;font-size:.78rem">
        <?php foreach ($trimestres as $t): ?>
          <option value="<?= $t['id'] ?>" <?= ($trimestre_actif['id'] ?? 0) == $t['id'] ? 'selected' : '' ?>>
            <?= h($t['libelle'] . ' — ' . $t['annee_lib']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-sm btn-warning" style="font-size:.75rem" id="btn-trim-activer">Activer</button>
    </form>
  </div>
</div>
<script>
// Envoi en AJAX (pas de rechargement de page) — voir prompt_continuite, 05/08/2026.
document.getElementById('form-trim-activer').addEventListener('submit', function(e) {
    e.preventDefault();
    var form = e.target;
    var btn  = document.getElementById('btn-trim-activer');
    var ok   = document.getElementById('trim-actif-ok');
    btn.disabled = true;
    fetch('', { method: 'POST', body: new FormData(form), headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.ok && data.label) {
                document.getElementById('trim-actif-label').textContent = data.label;
                ok.classList.remove('d-none');
                setTimeout(function() { ok.classList.add('d-none'); }, 2000);
            }
        })
        .finally(function() { btn.disabled = false; });
});
</script>

<?php
// ── Diagnostique auto-activation ─────────────────────────────────
$diag_today    = db_val("SELECT CURDATE()");           // date MySQL réelle
$diag_expired  = db_all("SELECT libelle, date_fin FROM sequence
                          WHERE date_fin IS NOT NULL AND date_fin < CURDATE() AND active=1");
$diag_en_cours = db_one("SELECT libelle, date_debut, date_fin FROM sequence
                          WHERE date_debut IS NOT NULL AND date_fin IS NOT NULL
                            AND date_debut <= CURDATE() AND date_fin >= CURDATE() LIMIT 1");
?>

<!-- Bandeau état auto-activation -->
<div class="mb-3">
  <?php if ($seq_active_now): ?>
  <div class="d-flex align-items-center gap-3 p-3 rounded-3"
       style="background:#f0fdf4;border:1px solid #86efac">
    <i class="bi bi-lightning-charge-fill" style="color:#15803d;font-size:1.2rem;flex-shrink:0"></i>
    <div style="flex:1">
      <div class="fw-bold" style="color:#14532d;font-size:.88rem">
        Évaluation active : <?= h($seq_active_now['libelle']) ?>
      </div>
      <div style="font-size:.73rem;color:#16a34a">
        Aujourd'hui (MySQL) : <strong><?= date('d/m/Y', strtotime($diag_today)) ?></strong>
        <?php if (!empty($seq_active_now['date_debut']) || !empty($seq_active_now['date_fin'])): ?>
          &nbsp;·&nbsp; Période :
          <?= $seq_active_now['date_debut'] ? date('d/m/Y', strtotime($seq_active_now['date_debut'])) : '—' ?>
          → <?= $seq_active_now['date_fin']   ? date('d/m/Y', strtotime($seq_active_now['date_fin']))   : '—' ?>
        <?php endif; ?>
      </div>
    </div>
    <!-- Bouton forcer vérification -->
    <form method="post" style="flex-shrink:0">
      <?= csrf_champ() ?>
      <input type="hidden" name="action" value="forcer_auto_activation">
      <button class="btn btn-sm" style="background:#dcfce7;color:#15803d;border:1px solid #86efac;font-size:.72rem">
        <i class="bi bi-arrow-repeat me-1"></i>Vérifier maintenant
      </button>
    </form>
  </div>
  <?php else: ?>
  <div class="d-flex align-items-center gap-3 p-3 rounded-3"
       style="background:#fff7ed;border:1px solid #fdba74">
    <i class="bi bi-exclamation-triangle-fill" style="color:#ea580c;font-size:1.2rem;flex-shrink:0"></i>
    <div style="flex:1">
      <div class="fw-bold" style="color:#9a3412;font-size:.88rem">Aucune évaluation active</div>
      <div style="font-size:.73rem;color:#c2410c">
        Aujourd'hui (MySQL) : <strong><?= date('d/m/Y', strtotime($diag_today)) ?></strong>
        <?php if ($diag_en_cours): ?>
          &nbsp;·&nbsp; La séquence <strong><?= h($diag_en_cours['libelle']) ?></strong>
          est en période mais inactive — cliquez "Vérifier".
        <?php else: ?>
          &nbsp;·&nbsp; Aucune séquence ne couvre cette date.
        <?php endif; ?>
      </div>
    </div>
    <form method="post" style="flex-shrink:0">
      <?= csrf_champ() ?>
      <input type="hidden" name="action" value="forcer_auto_activation">
      <button class="btn btn-sm" style="background:#fed7aa;color:#9a3412;border:1px solid #fdba74;font-size:.72rem">
        <i class="bi bi-arrow-repeat me-1"></i>Vérifier maintenant
      </button>
    </form>
  </div>
  <?php endif; ?>
</div>

<div class="row g-3">
  <!-- Formulaire créer/modifier -->
  <div class="col-md-5">
    <div class="card">
      <div class="card-header py-2" style="background:#f8faff">
        <span class="fw-semibold" style="font-size:.82rem" id="form-seq-title">
          <i class="bi bi-plus-circle me-1 text-primary"></i>Nouvelle évaluation
        </span>
      </div>
      <div class="card-body py-3">
        <form method="post" id="form-seq">
          <?= csrf_champ() ?>
          <input type="hidden" name="action"  value="seq_creer" id="seq-action">
          <input type="hidden" name="seq_id"  id="seq-id"       value="0">
          <div class="mb-2">
            <label class="form-label">Libellé <span class="text-danger">*</span></label>
            <input type="text" name="seq_libelle" id="seq-lib" class="form-control" required
                   placeholder="ex: Évaluation 1">
          </div>
          <div class="mb-2">
            <label class="form-label">Trimestre <span class="text-danger">*</span></label>
            <select name="seq_id_trim" id="seq-trim" class="form-select" required>
              <option value="">— Choisir —</option>
              <?php foreach ($trimestres as $t): ?>
                <option value="<?= $t['id'] ?>"><?= h($t['libelle']) ?> (<?= h($t['annee_lib']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="row g-2 mb-2">
            <div class="col-6">
              <label class="form-label">Ordre</label>
              <input type="number" name="seq_ordre" id="seq-ordre" class="form-control" min="1" value="1">
            </div>
          </div>
          <div class="mb-2">
            <label class="form-label">
              <i class="bi bi-calendar-event me-1 text-primary"></i>Date de début
              <span class="text-muted" style="font-size:.7rem">(activation auto)</span>
            </label>
            <input type="date" name="seq_debut" id="seq-debut" class="form-control">
          </div>
          <div class="mb-3">
            <label class="form-label">
              <i class="bi bi-calendar-x me-1 text-danger"></i>Date de fin
              <span class="text-muted" style="font-size:.7rem">(désactivation auto)</span>
            </label>
            <input type="date" name="seq_fin" id="seq-fin" class="form-control">
          </div>
          <div class="d-flex gap-2">
            <button class="btn btn-primary btn-sm">
              <i class="bi bi-check-lg me-1"></i><span id="seq-btn-label">Créer</span>
            </button>
            <button type="button" class="btn btn-light btn-sm d-none" id="btn-seq-annuler"
                    onclick="reinitSeqForm()">
              <i class="bi bi-x-lg"></i>
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Liste séquences -->
  <div class="col-md-7">
    <div class="card">
      <div class="card-header py-2" style="background:#f8faff">
        <span class="fw-semibold" style="font-size:.82rem">
          <i class="bi bi-list-check me-1 text-primary"></i>Liste des évaluations
        </span>
      </div>
      <div class="card-body p-0">
        <?php if (empty($sequences)): ?>
          <div class="text-center text-muted py-4" style="font-size:.82rem">Aucune évaluation définie.</div>
        <?php else: ?>
        <table class="table table-abz table-hover mb-0" style="font-size:.79rem">
          <thead>
            <tr>
              <th>Évaluation</th>
              <th>Trimestre</th>
              <th class="text-center">Période</th>
              <th class="text-center" style="width:60px">Statut</th>
              <th class="text-center" style="width:90px">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($sequences as $s):
              $debut_ok  = $s['date_debut'] && $s['date_debut'] <= $today;
              $fin_ok    = $s['date_fin']   && $s['date_fin']   >= $today;
              $en_periode = $s['date_debut'] && $s['date_fin'] && $debut_ok && $fin_ok;
            ?>
            <tr>
              <td class="fw-semibold"><?= h($s['libelle']) ?></td>
              <td style="color:#6b7280"><?= h($s['trim_lib']) ?></td>
              <td class="text-center" style="font-size:.72rem">
                <?php if ($s['date_debut'] || $s['date_fin']): ?>
                  <span style="color:<?= $debut_ok?'#15803d':'#9ca3af' ?>">
                    <?= $s['date_debut'] ? date('d/m/Y', strtotime($s['date_debut'])) : '—' ?>
                  </span>
                  &nbsp;→&nbsp;
                  <span style="color:<?= ($s['date_fin'] && $s['date_fin'] < $today)?'#dc2626':($fin_ok?'#15803d':'#9ca3af') ?>">
                    <?= $s['date_fin'] ? date('d/m/Y', strtotime($s['date_fin'])) : '—' ?>
                  </span>
                <?php else: ?>
                  <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
              <td class="text-center">
                <?php if ($s['active']): ?>
                  <span class="badge" style="background:#d1fae5;color:#065f46;font-size:.68rem">
                    <i class="bi bi-lightning-charge-fill text-warning me-1"></i>Active
                  </span>
                <?php elseif ($en_periode): ?>
                  <span class="badge" style="background:#dbeafe;color:#1e40af;font-size:.68rem">En période</span>
                <?php else: ?>
                  <span class="badge" style="background:#f3f4f6;color:#6b7280;font-size:.68rem">Inactive</span>
                <?php endif; ?>
              </td>
              <td class="text-center">
                <div class="d-flex gap-1 justify-content-center">
                  <!-- Modifier -->
                  <button class="btn btn-sm btn-light" style="padding:2px 6px" title="Modifier"
                          onclick="editSeq(<?= $s['id'] ?>,<?= h(json_encode($s['libelle'])) ?>,<?= $s['id_trim'] ?>,<?= $s['ordre']?:1 ?>,<?= h(json_encode($s['date_debut']??'')) ?>,<?= h(json_encode($s['date_fin']??'')) ?>)">
                    <i class="bi bi-pencil" style="font-size:.72rem"></i>
                  </button>
                  <!-- Activer manuellement -->
                  <?php if (!$s['active']): ?>
                    <form method="post" style="display:inline">
                      <?= csrf_champ() ?>
                      <input type="hidden" name="action" value="seq_activer">
                      <input type="hidden" name="seq_id" value="<?= $s['id'] ?>">
                      <button class="btn btn-sm btn-light text-success" style="padding:2px 6px" title="Activer manuellement">
                        <i class="bi bi-toggle-off" style="font-size:.72rem"></i>
                      </button>
                    </form>
                  <?php else: ?>
                    <form method="post" style="display:inline">
                      <?= csrf_champ() ?>
                      <input type="hidden" name="action" value="seq_desactiver">
                      <input type="hidden" name="seq_id" value="<?= $s['id'] ?>">
                      <button class="btn btn-sm btn-light text-warning" style="padding:2px 6px" title="Désactiver">
                        <i class="bi bi-toggle-on" style="font-size:.72rem"></i>
                      </button>
                    </form>
                  <?php endif; ?>
                  <!-- Supprimer -->
                  <form method="post" style="display:inline">
                    <?= csrf_champ() ?>
                    <input type="hidden" name="action" value="seq_supprimer">
                    <input type="hidden" name="seq_id" value="<?= $s['id'] ?>">
                    <button class="btn btn-sm btn-light text-danger" style="padding:2px 6px"
                            onclick="return confirm('Supprimer «<?= h(addslashes($s['libelle'])) ?>» ? Les notes liées seront perdues.')"
                            title="Supprimer">
                      <i class="bi bi-trash" style="font-size:.72rem"></i>
                    </button>
                  </form>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div><!-- /row -->

<script>
function editSeq(id, lib, trim, ordre, debut, fin) {
    document.getElementById('seq-action').value    = 'seq_modifier';
    document.getElementById('seq-id').value        = id;
    document.getElementById('seq-lib').value       = lib;
    document.getElementById('seq-trim').value      = trim;
    document.getElementById('seq-ordre').value     = ordre;
    document.getElementById('seq-debut').value     = debut;
    document.getElementById('seq-fin').value       = fin;
    document.getElementById('seq-btn-label').textContent = 'Enregistrer';
    document.getElementById('btn-seq-annuler').classList.remove('d-none');
    document.getElementById('form-seq-title').innerHTML =
        '<i class="bi bi-pencil me-1 text-primary"></i>Modifier l\'évaluation';
    window.scrollTo({top: 0, behavior: 'smooth'});
}
function reinitSeqForm() {
    document.getElementById('form-seq').reset();
    document.getElementById('seq-action').value = 'seq_creer';
    document.getElementById('seq-id').value     = '0';
    document.getElementById('seq-btn-label').textContent = 'Créer';
    document.getElementById('btn-seq-annuler').classList.add('d-none');
    document.getElementById('form-seq-title').innerHTML =
        '<i class="bi bi-plus-circle me-1 text-primary"></i>Nouvelle évaluation';
}
// Valider que date_fin >= date_debut
document.getElementById('form-seq').addEventListener('submit', function(e){
    var d = document.getElementById('seq-debut').value;
    var f = document.getElementById('seq-fin').value;
    if (d && f && f < d) {
        e.preventDefault();
        alert('La date de fin doit être supérieure ou égale à la date de début.');
    }
});
</script>

<?php elseif ($onglet === 'mentions'): ?>
<!-- ══════════════════════════════════════════════════
     ONGLET : Mentions automatiques du bulletin
     ══════════════════════════════════════════════════ -->
<div class="alert alert-light border py-2" style="font-size:.82rem">
  <i class="bi bi-info-circle me-1"></i>
  Ces seuils déterminent les mentions calculées automatiquement sur le bulletin (Tableau d'honneur,
  Encouragement, Félicitation, Avertissement/Blâme travail, Avertissement/Blâme conduite) — pour
  l'année scolaire <strong><?= h($annee_act['libelle'] ?? '—') ?></strong> (année active).
  Les heures d'absence viennent de la table saisie dans le module Discipline.
</div>

<form method="post" class="row g-compact">
  <?= csrf_champ() ?>
  <input type="hidden" name="action" value="mentions">
  <input type="hidden" name="id_annee" value="<?= (int)($annee_act['id'] ?? 0) ?>">

  <div class="col-12"><div class="section-titre mb-1" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;color:#9ca3af">Tableau d'honneur / Encouragement / Félicitation</div></div>
  <div class="col-md-3">
    <label class="form-label">Moyenne min. — Tableau d'honneur</label>
    <input type="number" step="0.01" min="0" max="20" name="moy_tableau_honneur" class="form-control" value="<?= h($reglage_mention_actuel['moy_tableau_honneur']) ?>">
  </div>
  <div class="col-md-3">
    <label class="form-label">Heures d'absence NJ max autorisées</label>
    <input type="number" min="0" name="heures_max_tableau_honneur" class="form-control" value="<?= h($reglage_mention_actuel['heures_max_tableau_honneur']) ?>">
  </div>
  <div class="col-md-3">
    <label class="form-label">Moyenne min. — Encouragement</label>
    <input type="number" step="0.01" min="0" max="20" name="moy_encouragement" class="form-control" value="<?= h($reglage_mention_actuel['moy_encouragement']) ?>">
  </div>
  <div class="col-md-3">
    <label class="form-label">Moyenne min. — Félicitation</label>
    <input type="number" step="0.01" min="0" max="20" name="moy_felicitation" class="form-control" value="<?= h($reglage_mention_actuel['moy_felicitation']) ?>">
  </div>

  <div class="col-12 mt-3"><div class="section-titre mb-1" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;color:#9ca3af">Avertissement / Blâme travail (sur la moyenne générale)</div></div>
  <div class="col-md-3">
    <label class="form-label">Avert. travail — moyenne min.</label>
    <input type="number" step="0.01" min="0" max="20" name="moy_avert_travail_min" class="form-control" value="<?= h($reglage_mention_actuel['moy_avert_travail_min']) ?>">
  </div>
  <div class="col-md-3">
    <label class="form-label">Avert. travail — moyenne max.</label>
    <input type="number" step="0.01" min="0" max="20" name="moy_avert_travail_max" class="form-control" value="<?= h($reglage_mention_actuel['moy_avert_travail_max']) ?>">
  </div>
  <div class="col-md-3">
    <label class="form-label">Blâme travail — moyenne max. (en dessous de)</label>
    <input type="number" step="0.01" min="0" max="20" name="moy_blame_travail_max" class="form-control" value="<?= h($reglage_mention_actuel['moy_blame_travail_max']) ?>">
  </div>

  <div class="col-12 mt-3"><div class="section-titre mb-1" style="font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;color:#9ca3af">Avertissement / Blâme conduite (sur les heures d'absence non justifiées)</div></div>
  <div class="col-md-3">
    <label class="form-label">Avert. conduite — heures min.</label>
    <input type="number" min="0" name="heures_avert_conduite_min" class="form-control" value="<?= h($reglage_mention_actuel['heures_avert_conduite_min']) ?>">
  </div>
  <div class="col-md-3">
    <label class="form-label">Avert. conduite — heures max. (en dessous de)</label>
    <input type="number" min="0" name="heures_avert_conduite_max" class="form-control" value="<?= h($reglage_mention_actuel['heures_avert_conduite_max']) ?>">
  </div>
  <div class="col-md-3">
    <label class="form-label">Blâme conduite — heures min. (à partir de)</label>
    <input type="number" min="0" name="heures_blame_conduite_min" class="form-control" value="<?= h($reglage_mention_actuel['heures_blame_conduite_min']) ?>">
  </div>

  <div class="col-12 mt-3">
    <button class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Enregistrer</button>
  </div>
</form>

<?php elseif ($onglet === 'couleurs_bulletin'): ?>
<!-- ══════════════════════════════════════════════════
     ONGLET — Couleurs des bulletins (palette de rôles nommés)
     Maquette interactive : chaque zone colorée du bulletin ci-dessous est
     cliquable et ouvre sa palette (même principe que côté primaire —
     pages/parametres/index.php — porté depuis ABZ_MBE, dont ce bulletin
     secondaire est issu). 5 rôles = ceux RÉELLEMENT peints par
     secondaire/pages/bulletins/pdf.php (bulletin individuel) — voir
     fonctions.php::pdf_couleurs_defaut_secondaire(). Le tableau de notes
     reproduit la vraie structure par compétence (pas le regroupement par
     discipline d'ABZ_MBE, remplacé ici — voir le commentaire du §4 de
     pdf.php).
══════════════════════════════════════════════════ -->
<?php if (!$pdf_couleurs_actuelles): ?>
<div class="alert alert-warning border py-2 mb-3" style="font-size:.8rem">
  <i class="bi bi-exclamation-triangle me-1"></i>
  Table <code>pdf_couleur</code> absente de cette école — migration secondaire v3 pas encore appliquée
  (<a href="<?= APP_URL ?>/association/migrations.php">Espace association &gt; Migrations</a>).
  Les couleurs du bulletin restent celles codées en dur jusque-là.
</div>
<?php else: ?>
<div class="card mb-3">
  <div class="card-body py-2" style="font-size:.78rem;color:#6b7280">
    Pas de personnalisation case par case : chaque couleur ci-dessous est un <strong>rôle visuel nommé</strong>,
    réutilisé partout où il apparaît sur le bulletin. Cliquez une zone colorée du bulletin, ou une ligne du
    panneau à droite, pour la changer : elle s'applique en direct partout où ce rôle est utilisé.
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
.cbk-role .sw{width:24px;height:24px;border-radius:6px;flex:none;border:1px solid rgba(0,0,0,.15)}
.cbk-role .lbl{font-size:.74rem;font-weight:600;color:#1f2937;display:block;line-height:1.25}
.cbk-role .hex{font-family:monospace;font-size:.68rem;color:#8a93a3}
.cbk-role .warn{font-size:.6rem;font-weight:700;color:#a23148;background:#fbe7ea;padding:1px 5px;border-radius:5px;margin-left:6px}

[data-cbk-role]{cursor:pointer}
[data-cbk-role]:not(tr):hover,[data-cbk-role].is-open:not(tr){outline:2px solid #2d5fa3;outline-offset:1px;border-radius:3px}
tr[data-cbk-role]:hover td,tr[data-cbk-role].is-open td{box-shadow:inset 0 0 0 2px #2d5fa3}
.cbk-tag-frame{position:absolute;top:-11px;left:14px;background:var(--cbk-c-bordure_marque,#1a3c6b);color:#fff;font-size:.6rem;font-weight:700;padding:3px 9px;border-radius:20px;box-shadow:0 3px 8px rgba(0,0,0,.25);z-index:2}

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

/* Le "papier" du bulletin : fond blanc et encre noire fixes (comme un vrai
   PDF imprimé) — seules les 5 zones ci-dessous suivent les couleurs choisies. */
.cbk-paper{position:relative;margin-inline:auto;max-width:720px;background:#fdfdfb;color:#111417;border:3px solid var(--cbk-c-bordure_marque);border-radius:12px;padding:18px 16px 14px;box-shadow:0 18px 38px -20px rgba(20,30,50,.35);font-size:11px;line-height:1.3}
.cbk-paper *{box-sizing:border-box}
.cbk-hdr3{display:grid;grid-template-columns:1fr auto 1fr;gap:8px;text-align:center}
.cbk-hdr3 .col{font-size:8.4px;line-height:1.4}
.cbk-hdr3 .col b{display:block;font-size:8.8px;margin:2px 0}
.cbk-logo{width:50px;height:50px;border-radius:50%;border:1.6px solid #1a3c6b;display:grid;place-items:center;font-weight:800;font-size:8.6px;color:#1a3c6b}
.cbk-immat{text-align:center;font-size:7.8px;font-style:italic;margin-top:5px;color:#333}
.cbk-title-pill{margin-top:8px;border:1.6px solid var(--cbk-c-bordure_marque);background:var(--cbk-c-bandeau_titre);border-radius:20px;overflow:hidden}
.cbk-title-pill .main{padding:6px 10px 1px;text-align:center;font-weight:800;font-style:italic;font-size:13.5px;color:#1a3c6b}
.cbk-title-pill .sub{text-align:center;font-size:8.6px;font-style:italic;padding-bottom:4px;color:#1a3c6b}
.cbk-annee{text-align:center;font-weight:700;font-size:9px;margin:6px 0 8px}
.cbk-ident{display:flex;gap:8px;margin-bottom:8px}
.cbk-photo{width:60px;height:68px;border:1px solid #111;border-radius:3px;flex:none;background:#eef1f6}
.cbk-idgrid{flex:1;display:flex;flex-direction:column;gap:2px;font-size:8.2px}
.cbk-idrow{display:flex;gap:2px}
.cbk-idc{border:1px solid #111;padding:2px 4px;display:flex;align-items:center}
.cbk-idlbl{background:#e6e6e6;font-weight:700}
.cbk-idlbl small{display:block;font-weight:400;font-style:italic;font-size:7px}
.cbk-idval{flex:1;font-style:italic}
table.cbk-notes{width:100%;border-collapse:collapse;font-size:7px;margin-top:4px}
table.cbk-notes th,table.cbk-notes td{border:1px solid #111;padding:2.5px 2px;text-align:center}
table.cbk-notes th{font-size:6.6px;font-weight:700}
thead.cbk-hdr-ind th{background:var(--cbk-c-entete_tableau_individuel);color:#fff}
tr.cbk-mat td{background:var(--cbk-c-bandeau_section);font-weight:800;text-align:left;font-size:6.8px}
tr[data-cbk-role="ligne_echec"] td.comp{background:var(--cbk-c-ligne_echec);text-align:left}
td.comp{text-align:left}
.cbk-b4{display:grid;grid-template-columns:1fr 1fr 1fr 1fr;border:1px solid #111;border-top:0;margin-top:6px}
.cbk-b4 .cell{border-left:1px solid #111;padding:4px 5px}
.cbk-b4 .cell:first-child{border-left:0}
.cbk-b4 h5{margin:-4px -5px 4px;padding:3px 5px;background:var(--cbk-c-bandeau_section);font-size:6.6px;font-weight:800;text-align:center}
.cbk-b4 h5 small{display:block;font-weight:400;font-style:italic;font-size:5.8px}
.cbk-kv{display:flex;justify-content:space-between;font-size:6.6px;padding:1.5px 0;border-bottom:1px dotted #ccc}
.cbk-kv:last-child{border-bottom:0}
.cbk-decrow{display:grid;grid-template-columns:1.4fr 1fr;border:1px solid #111;border-top:0}
.cbk-decrow h5{margin:0;padding:3px 6px;background:var(--cbk-c-bandeau_section);font-size:6.8px;font-weight:800;text-align:center;border-right:1px solid #111}
.cbk-decrow h5:last-child{border-right:0}
.cbk-foot{margin-top:7px;padding-top:5px;border-top:1px solid #bbb;display:flex;justify-content:space-between;font-size:7.2px;color:#555}
</style>

<form method="post" id="form-pdf-couleurs">
  <?= csrf_champ() ?>
  <input type="hidden" name="action" value="pdf_couleurs_enregistrer">
  <?php foreach ($pdf_couleurs_actuelles as $cle => $c): ?>
    <input type="hidden" name="couleur_<?= h($cle) ?>" id="cbk-in-<?= h($cle) ?>" value="<?= h($c['hex']) ?>">
  <?php endforeach; ?>

  <div class="cbk-wrap" style="<?php foreach ($pdf_couleurs_actuelles as $cle => $c) echo '--cbk-c-'.h($cle).':'.h($c['hex']).';'; ?>">
    <div class="cbk-canvas">
      <div class="cbk-paper" id="cbkPaper">

        <div class="cbk-tag-frame" data-cbk-role="bordure_marque" tabindex="0" role="button" aria-label="Couleur : Bordure / couleur de marque">Cadre</div>

        <div class="cbk-hdr3">
          <div class="col">RÉPUBLIQUE DU CAMEROUN<b>Paix – Travail – Patrie</b>***<br>Région de l'Adamaoua<br>Département de la Vina<br>Arrondissement de Mbé<br><b>LYCÉE TECHNIQUE DE MBÉ</b>B.P. 32 Mbé — Tél. : 699 00 00 00</div>
          <div class="cbk-logo">LTM</div>
          <div class="col">REPUBLIC OF CAMEROON<b>Peace – Work – Fatherland</b>***<br>Adamawa Region<br>Vina Division<br>Mbe Subdivision<br><b>GTHS OF MBE</b>P.O. Box 32 Mbe — Phone: 699 00 00 00</div>
        </div>
        <div class="cbk-immat">IMMATRICULATION : 2JH1TEFD110316102</div>

        <div class="cbk-title-pill">
          <div class="main" data-cbk-role="bandeau_titre" tabindex="0" role="button" aria-label="Couleur : Bandeau titre (pilule d'en-tête)">BULLETIN SCOLAIRE DU 1er TRIMESTRE</div>
          <div class="sub">Term Report</div>
        </div>
        <div class="cbk-annee">Année scolaire : <?= h($annee_act['libelle'] ?? '2025/2026') ?></div>

        <div class="cbk-ident">
          <div class="cbk-photo" aria-hidden="true"></div>
          <div class="cbk-idgrid">
            <div class="cbk-idrow">
              <div class="cbk-idc cbk-idlbl" style="flex:1.4">CLASSE :<small>Class</small></div>
              <div class="cbk-idval cbk-idc" style="flex:2">3ème Technique A</div>
              <div class="cbk-idc cbk-idlbl" style="flex:1">EFFECTIF :<small>Size</small></div>
              <div class="cbk-idval cbk-idc" style="flex:.6">42</div>
              <div class="cbk-idc cbk-idlbl" style="flex:1.6">IDENTIFIANT UNIQUE (NIU) :<small>ID No.</small></div>
              <div class="cbk-idval cbk-idc" style="flex:1.2">24B0417</div>
            </div>
            <div class="cbk-idrow">
              <div class="cbk-idc cbk-idlbl" style="flex:1.4">NOM ET PRÉNOMS :<small>Name</small></div>
              <div class="cbk-idval cbk-idc" style="flex:4.2">NGUEMO Aïcha Florence</div>
              <div class="cbk-idc cbk-idlbl" style="flex:.8">GENRE :<small>Gender</small></div>
              <div class="cbk-idval cbk-idc" style="flex:.6">F</div>
            </div>
          </div>
        </div>

        <table class="cbk-notes">
          <thead class="cbk-hdr-ind" data-cbk-role="entete_tableau_individuel" tabindex="0" role="button" aria-label="Couleur : en-tête du tableau de compétences">
            <tr>
              <th style="width:34%;text-align:left">COMPÉTENCES ÉVALUÉES / ENSEIGNANT</th>
              <th style="width:8%">N/20</th><th style="width:8%">M/20</th><th style="width:7%">Coef</th>
              <th style="width:7%">MxC</th><th style="width:7%">Cote</th><th style="width:11%">[Min-Max]</th>
              <th style="width:18%">Appréciations et visa</th>
            </tr>
          </thead>
          <tbody>
            <tr class="cbk-mat" data-cbk-role="bandeau_section" tabindex="0" role="button" aria-label="Couleur : bandeaux de section"><td colspan="8">COMPETENCE 1: MATHÉMATIQUES — DJOUMESSI R.</td></tr>
            <tr><td class="comp">Résoudre une équation du 1er degré</td><td>14.00</td><td>12.50</td><td>4</td><td>50.00</td><td>B</td><td>05-18</td><td>Compétences bien acquises</td></tr>
            <tr data-cbk-role="ligne_echec" tabindex="0" role="button" aria-label="Couleur : surlignage moyenne insuffisante"><td class="comp">Étudier une fonction affine</td><td>07.00</td><td>08.25</td><td>4</td><td>33.00</td><td>D</td><td>03-15</td><td>Compétences non acquises</td></tr>
            <tr class="cbk-mat" data-cbk-role="bandeau_section" tabindex="0" role="button" aria-label="Couleur : bandeaux de section"><td colspan="8">COMPETENCE 1: ANGLAIS — ATANGANA C.</td></tr>
            <tr><td class="comp">Reading comprehension</td><td>13.50</td><td>12.25</td><td>3</td><td>36.75</td><td>B</td><td>04-16</td><td>Compétences acquises</td></tr>
          </tbody>
        </table>

        <div class="cbk-b4">
          <div class="cell">
            <h5 data-cbk-role="bandeau_section" tabindex="0" role="button" aria-label="Couleur : bandeaux de section">DISCIPLINES<small>Discipline</small></h5>
            <div class="cbk-kv"><span>Absences justifiées</span><b>3</b></div>
            <div class="cbk-kv"><span>Absences non justifiées</span><b>1</b></div>
          </div>
          <div class="cell">
            <h5 data-cbk-role="bandeau_section" tabindex="0" role="button" aria-label="Couleur : bandeaux de section">TRAVAIL<small>Work</small></h5>
            <div class="cbk-kv"><span>Tableau d'honneur</span><b>☒</b></div>
            <div class="cbk-kv"><span>Encouragement</span><b>☐</b></div>
          </div>
          <div class="cell">
            <h5 data-cbk-role="bandeau_section" tabindex="0" role="button" aria-label="Couleur : bandeaux de section">PROFIL DE LA CLASSE<small>Class profile</small></h5>
            <div class="cbk-kv"><span>Moy. de la classe</span><b>10.85</b></div>
            <div class="cbk-kv"><span>Taux de réussite</span><b>64%</b></div>
          </div>
          <div class="cell">
            <h5 data-cbk-role="bandeau_section" tabindex="0" role="button" aria-label="Couleur : bandeaux de section">RÉSULTATS DE L'ÉLÈVE<small>Student results</small></h5>
            <div class="cbk-kv"><span>Moyenne</span><b>11.94 / 20</b></div>
            <div class="cbk-kv"><span>Rang</span><b>9e / 42</b></div>
          </div>
        </div>

        <div class="cbk-decrow">
          <h5 data-cbk-role="bandeau_section" tabindex="0" role="button" aria-label="Couleur : bandeaux de section">DÉCISION DU CONSEIL DE CLASSE ET DE DISCIPLINE</h5>
          <h5 data-cbk-role="bandeau_section" tabindex="0" role="button" aria-label="Couleur : bandeaux de section">OBSERVATIONS DU CHEF D'ÉTABLISSEMENT</h5>
        </div>

        <div class="cbk-foot">
          <span>Copyright © SIGES – <?= h($annee_act['libelle'] ?? '2025/2026') ?> · Scanner pour vérifier l'authenticité</span>
          <span>Page 1 / 1</span>
        </div>
      </div>
    </div>

    <aside class="cbk-side">
      <div class="cbk-panel">
        <h6>Les 5 rôles de couleur</h6>
        <div id="cbkRoleList"></div>
      </div>
      <div class="cbk-panel d-flex flex-column gap-2">
        <button class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Enregistrer</button>
        <button type="submit" form="form-pdf-couleurs-reset" class="btn btn-outline-secondary btn-sm"
                onclick="return confirm('Réinitialiser les 5 couleurs aux valeurs par défaut ?')">
          <i class="bi bi-arrow-counterclockwise me-1"></i>Réinitialiser aux couleurs par défaut
        </button>
      </div>
    </aside>
  </div>
</form>
<form method="post" id="form-pdf-couleurs-reset" style="display:none">
  <?= csrf_champ() ?>
  <input type="hidden" name="action" value="pdf_couleurs_reinitialiser">
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
  var ROLES = <?= json_encode(array_map(function($cle) use ($pdf_couleurs_actuelles){
      $desc = [
        'bandeau_titre' => "Fond de la pilule du titre du bulletin. Le texte reste toujours bleu marine foncé (couleur fixe).",
        'bordure_marque' => "Cadre extérieur de la page et contour de la pilule de titre. Purement une couleur de trait — pas de texte dessus.",
        'entete_tableau_individuel' => "Ligne d'en-tête « COMPÉTENCES ÉVALUÉES / N/20 / M/20… » du tableau de notes. Texte blanc fixe.",
        'bandeau_section' => "Bandeaux « Disciplines / Travail / Profil / Résultats », ligne d'en-tête de chaque matière (« COMPETENCE : … ») et bandeau « Décision du conseil / Observations ». Texte noir fixe.",
        'ligne_echec' => "Surligne la ligne d'une compétence dont la moyenne de l'élève est inférieure à 10/20. Texte noir fixe.",
      ][$cle] ?? '';
      $textFixed = [
        'bandeau_titre' => '#1a3c6b',
        'entete_tableau_individuel' => '#ffffff',
        'bandeau_section' => '#111111',
        'ligne_echec' => '#111111',
      ][$cle] ?? null;
      return [
        'id' => $cle,
        'label' => $pdf_couleurs_actuelles[$cle]['libelle'],
        'desc' => $desc,
        'textFixed' => $textFixed,
        'border' => $cle === 'bordure_marque',
      ];
  }, array_keys($pdf_couleurs_actuelles)), JSON_UNESCAPED_UNICODE) ?>;

  // Palette riche : 11 neutres + 17 familles de teinte × 5 nuances (clair → foncé),
  // dans l'ordre de l'arc-en-ciel — largement de quoi composer sans passer par le
  // sélecteur système. Le composeur TSL juste au-dessus permet en plus de régler
  // n'importe quelle couleur à la main (pas seulement ces suggestions).
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
    if (role.border) return luminance(hex) > 0.85 ? "Peu visible sur le papier blanc du bulletin." : null;
    if (role.textFixed) return contrast(hex, role.textFixed) < 2.6 ? "Contraste faible avec le texte (couleur fixe) posé dessus." : null;
    return null;
  }

  var openId = null;

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
      var warn = warningFor(r);
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'cbk-role' + (openId===r.id?' is-open':'');
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

  renderList();
})();
</script>
<?php endif; ?>

<?php elseif ($onglet === 'reglages'): ?>
<!-- ══════════════════════════════════════════════════
     ONGLET 4 — Apparence / Thème
══════════════════════════════════════════════════ -->

<div class="row g-3">

  <!-- ── Couleurs ── -->
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
            // ── Classiques ──────────────────────────────────────
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
            'lime'      => ['label'=>'Lime',        'cat'=>'Classique', 'primary'=>'#65a30d','dark'=>'#4d7c0f','sidebar'=>'#0a1a00','sidebar2'=>'#14330a','act'=>'#84cc16'],
            'slate'     => ['label'=>'Ardoise',     'cat'=>'Classique', 'primary'=>'#475569','dark'=>'#334155','sidebar'=>'#0f172a','sidebar2'=>'#1e293b','act'=>'#64748b'],
            // ── Dark / Sombre ────────────────────────────────────
            'dark_ink'  => ['label'=>'Encre',       'cat'=>'Dark',      'primary'=>'#818cf8','dark'=>'#6366f1','sidebar'=>'#08090f','sidebar2'=>'#0e1015','act'=>'#818cf8'],
            'dark_nord' => ['label'=>'Nord',        'cat'=>'Dark',      'primary'=>'#88c0d0','dark'=>'#6ba3b5','sidebar'=>'#2e3440','sidebar2'=>'#3b4252','act'=>'#88c0d0'],
            'dark_mid'  => ['label'=>'Minuit',      'cat'=>'Dark',      'primary'=>'#a78bfa','dark'=>'#8b5cf6','sidebar'=>'#070714','sidebar2'=>'#0d0d2e','act'=>'#c4b5fd'],
            'dark_carb' => ['label'=>'Carbone',     'cat'=>'Dark',      'primary'=>'#38bdf8','dark'=>'#0ea5e9','sidebar'=>'#111111','sidebar2'=>'#1a1a1a','act'=>'#38bdf8'],
            'dark_mat'  => ['label'=>'Matière',     'cat'=>'Dark',      'primary'=>'#4ade80','dark'=>'#22c55e','sidebar'=>'#121212','sidebar2'=>'#1e1e1e','act'=>'#4ade80'],
            'dark_rose' => ['label'=>'Dark Rose',   'cat'=>'Dark',      'primary'=>'#fb7185','dark'=>'#f43f5e','sidebar'=>'#130007','sidebar2'=>'#1e000f','act'=>'#fb7185'],
            'dark_tan'  => ['label'=>'Chocolat',    'cat'=>'Dark',      'primary'=>'#d4a574','dark'=>'#b8864e','sidebar'=>'#1c1008','sidebar2'=>'#2a1a0c','act'=>'#d4a574'],
            'dark_forst'=> ['label'=>'Forêt',       'cat'=>'Dark',      'primary'=>'#6ee7b7','dark'=>'#34d399','sidebar'=>'#041a0c','sidebar2'=>'#082e14','act'=>'#6ee7b7'],
          ];
          ?>
          <?php
          // Grouper par catégorie
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
                     border:2px solid transparent;transition:all .15s;width:72px;text-align:center"
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
                 style="width:44px;height:32px;padding:2px;cursor:pointer" value="#1e4fd8">
          <button class="btn btn-sm btn-outline-secondary" onclick="applyCustomColor()">
            <i class="bi bi-eyedropper me-1"></i>Appliquer
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- ── Police + Aperçu ── -->
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

    <!-- Aperçu live -->
    <div class="card" id="preview-card" style="border:2px solid #e5e7eb;border-radius:12px">
      <div class="card-header py-2 d-flex align-items-center gap-2"
           style="background:var(--preview-sidebar,#0f1a3a);border-radius:10px 10px 0 0">
        <div style="width:24px;height:24px;border-radius:6px;background:var(--preview-act,#2b53e6);display:flex;align-items:center;justify-content:center">
          <i class="bi bi-grid-fill" style="color:#fff;font-size:.55rem"></i>
        </div>
        <span style="color:#fff;font-size:.72rem;font-weight:600;font-family:var(--preview-font,'Inter')">ABZ MBE</span>
      </div>
      <div class="card-body py-2 px-3">
        <div style="font-family:var(--preview-font,'Inter');font-size:.78rem;font-weight:700;color:#1e2a3a;margin-bottom:4px">Aperçu de l'interface</div>
        <div style="font-family:var(--preview-font,'Inter');font-size:.72rem;color:#6b7280;margin-bottom:8px">
          Voici comment votre interface apparaîtra après application du thème.
        </div>
        <div class="d-flex gap-2 flex-wrap">
          <button class="btn btn-sm" style="background:var(--preview-primary,#1e4fd8);color:#fff;border:none;font-family:var(--preview-font,'Inter');font-size:.72rem;padding:3px 10px;border-radius:6px">
            <i class="bi bi-check-lg me-1"></i>Bouton principal
          </button>
          <span style="display:inline-flex;align-items:center;gap:4px;background:#dbeafe;color:var(--preview-primary,#1e4fd8);padding:2px 8px;border-radius:6px;font-size:.7rem;font-family:var(--preview-font,'Inter');font-weight:600">
            <i class="bi bi-lightning-charge-fill"></i>Éval active
          </span>
        </div>
      </div>
    </div>
  </div>

</div>

<!-- Bouton appliquer & réinitialiser -->
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

// Charger préférences au démarrage
(function(){
    var p = JSON.parse(localStorage.getItem('abz_prefs') || '{}');
    if (p.theme) selectThemeCard(p.theme);
    if (p.font)  selectFontCard(p.font);
    if (p.custom_primary) {
        document.getElementById('custom-color').value = p.custom_primary;
    }
})();

function applyTheme(key) {
    var th = THEMES[key];
    if (!th) return;
    document.documentElement.style.setProperty('--primary',      th.primary);
    document.documentElement.style.setProperty('--primary-dark', th.dark);
    document.documentElement.style.setProperty('--sidebar-bg',   th.sidebar);
    document.documentElement.style.setProperty('--sidebar-bg2',  th.sidebar2);
    document.documentElement.style.setProperty('--sidebar-act',  th.act);
    // Aperçu
    document.documentElement.style.setProperty('--preview-primary', th.primary);
    document.documentElement.style.setProperty('--preview-sidebar', th.sidebar);
    document.documentElement.style.setProperty('--preview-act',     th.act);
    selectThemeCard(key);
    // Conserver dans prefs
    var p = JSON.parse(localStorage.getItem('abz_prefs') || '{}');
    p.theme = key; p.custom_primary = null;
    localStorage.setItem('abz_prefs', JSON.stringify(p));
}

function applyCustomColor() {
    var col = document.getElementById('custom-color').value;
    document.documentElement.style.setProperty('--primary', col);
    document.documentElement.style.setProperty('--preview-primary', col);
    // Désélectionner les cartes
    document.querySelectorAll('.theme-card').forEach(function(c){ c.style.border='2px solid transparent'; });
    var p = JSON.parse(localStorage.getItem('abz_prefs') || '{}');
    p.custom_primary = col; p.theme = null;
    localStorage.setItem('abz_prefs', JSON.stringify(p));
}

function applyFont(fname) {
    document.body.style.fontFamily = "'" + fname + "', system-ui, sans-serif";
    document.documentElement.style.setProperty('--preview-font', "'" + fname + "'");
    selectFontCard(fname);
    var p = JSON.parse(localStorage.getItem('abz_prefs') || '{}');
    p.font = fname;
    localStorage.setItem('abz_prefs', JSON.stringify(p));
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
    var p = JSON.parse(localStorage.getItem('abz_prefs') || '{}');
    localStorage.setItem('abz_prefs', JSON.stringify(p));
    var msg = document.getElementById('theme-saved-msg');
    msg.style.display = 'inline';
    setTimeout(function(){ msg.style.display='none'; }, 2500);
}

function reinitTheme() {
    localStorage.removeItem('abz_prefs');
    location.reload();
}
</script>

<?php endif; ?>

<?php require_once __DIR__ . '/../../../layout/footer.php'; ?>
