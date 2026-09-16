<?php
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_role(['ADMIN','PROVISEUR']);

auto_activer_sequences();

$onglet = $_GET['onglet'] ?? 'etablissement';
$etab   = get_etablissement();
$sig_chef_etablissement = get_signature_titulaires()['chef_etablissement'] ?? null;

// ══════════════════════════════════════════════════
//  POST — Établissement
// ══════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $action = post('action');

    // ── Établissement ─────────────────────────────
    if ($action === 'etab') {
        $logo = $etab['logo'] ?? null;
        if (!empty($_FILES['logo']['tmp_name']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $ext_ok = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png'];
            $ext    = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
            $fi     = finfo_open(FILEINFO_MIME_TYPE);
            $mime   = finfo_file($fi, $_FILES['logo']['tmp_name']);
            finfo_close($fi);
            if (isset($ext_ok[$ext]) && $ext_ok[$ext] === $mime) {
                $logo = 'logo_etab.'.$ext;
                move_uploaded_file($_FILES['logo']['tmp_name'], __DIR__.'/../../assets/uploads/'.$logo);
            }
        }
        db_exec(
            "UPDATE etablissement SET nom_fr=?,nom_en=?,sigle=?,immatriculation=?,boite_postale=?,ville=?,
             telephone=?,email=?,region_fr=?,region_en=?,departement_fr=?,division_en=?,
             arrondissement_fr=?,subdivision_en=?,chef_etablissement=?,logo=? WHERE id=?",
            [post('nom_fr'),post('nom_en'),post('sigle'),post('immatriculation'),post('boite_postale'),post('ville'),
             post('telephone'),post('email'),post('region_fr'),post('region_en'),
             post('departement_fr'),post('division_en'),post('arrondissement_fr'),
             post('subdivision_en'),post('chef_etablissement'),$logo,$etab['id']]
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
                $fichier_sig = 'signature_chef_etablissement.png';
                if (signature_traiter_transparence($_FILES['signature']['tmp_name'], __DIR__.'/../../assets/uploads/'.$fichier_sig)) {
                    db_exec("UPDATE signature_titulaire SET fichier=? WHERE code='chef_etablissement'", [$fichier_sig]);
                }
            }
        }
        flash_set('succes', 'Établissement mis à jour.');
        rediriger('secondaire/pages/parametres/index.php?onglet=etablissement');
    }

    // ── Année scolaire ────────────────────────────
    if ($action === 'annee_creer') {
        $lib = post('libelle_annee');
        if ($lib) {
            db_exec("INSERT IGNORE INTO annee_scolaire (libelle) VALUES (?)", [$lib]);
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

$titre_page = 'Paramètres';
require_once __DIR__ . '/../../../layout/header.php';
?>

<div class="page-titre">
  <h4><i class="bi bi-gear me-1 text-primary"></i>Paramètres</h4>
</div>

<!-- Onglets -->
<ul class="nav nav-tabs mb-3" style="border-bottom:2px solid #e5e7eb">
  <?php foreach ([
      'etablissement' => ['bi-building',   'Établissement'],
      'annees'        => ['bi-calendar3',  'Années scolaires'],
      'evaluations'   => ['bi-list-check', 'Évaluations'],
      'mentions'      => ['bi-award',      'Mentions'],
      'reglages'      => ['bi-palette',    'Apparence'],
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
        </div>
        <div class="col-md-6">
          <label class="form-label">Logo</label>
          <?php if (!empty($etab['logo'])): ?>
            <div class="mb-1">
              <img src="<?= APP_URL ?>/assets/uploads/<?= h($etab['logo']) ?>"
                   style="height:48px;border-radius:6px;border:1px solid #e5e7eb">
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
    </form>
  </div>
</div>


<?php elseif ($onglet === 'annees'): ?>
<!-- ══════════════════════════════════════════════════
     ONGLET 2 — Années scolaires
══════════════════════════════════════════════════ -->
<div class="row g-3">
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
                  <!-- Modifier -->
                  <button class="btn btn-sm btn-light" style="padding:2px 7px"
                          onclick="editAnnee(<?= $a['id'] ?>,<?= h(json_encode($a['libelle'])) ?>)"
                          title="Modifier"><i class="bi bi-pencil" style="font-size:.72rem"></i></button>
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
                  <!-- Supprimer -->
                  <?php if (!$a['active']): ?>
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
