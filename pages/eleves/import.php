<?php
// ── Import Excel des élèves ───────────────────────────────────
// Modèle téléchargeable (import_modele.php) → l'utilisateur remplit →
// upload ici → chaque ligne est validée puis insérée (nouvel élève créé,
// jamais de mise à jour d'un élève existant — un import ne modifie rien).
// Colonnes attendues (voir import_modele.php) : Matricule (optionnel,
// généré si vide), Nom*, Prénom(s), Nom en arabe, Sexe, Date de naissance,
// Lieu de naissance, Arrondissement ("Intitulé (Département)" — liste
// déroulante), Adresse, NIU, Classe, Statut.
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../bd/lib/lieux_normalisation.php';
exiger_role(['DIRECTEUR','SECRETAIRE']);

use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

$rapport = null; // ['ok'=>int, 'erreurs'=>[['ligne'=>n,'msg'=>...]], 'total'=>int]

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();

    if (empty($_FILES['fichier']['tmp_name']) || $_FILES['fichier']['error'] !== UPLOAD_ERR_OK) {
        flash_set('erreur', 'Aucun fichier valide reçu.');
        rediriger('pages/eleves/import.php');
    }
    $tmp  = $_FILES['fichier']['tmp_name'];
    $nom  = $_FILES['fichier']['name'];
    if (!preg_match('/\.xlsx$/i', $nom)) {
        flash_set('erreur', 'Le fichier doit être au format .xlsx (utilisez le modèle fourni).');
        rediriger('pages/eleves/import.php');
    }
    if ($_FILES['fichier']['size'] > 5 * 1024 * 1024) {
        flash_set('erreur', "Fichier trop volumineux (5 Mo max).");
        rediriger('pages/eleves/import.php');
    }

    try {
        $reader = new XlsxReader();
        $reader->setReadDataOnly(true);
        $doc   = $reader->load($tmp);
        $sheet = $doc->getSheet(0);
        $lignes = $sheet->toArray(null, true, true, false);
    } catch (Throwable $e) {
        flash_set('erreur', "Fichier illisible : " . $e->getMessage());
        rediriger('pages/eleves/import.php');
    }

    $annee     = get_annee_active();
    $val_annee = $annee['val_annee'] ?? '';

    $classes_idx = [];
    foreach (db_all("SELECT IDClasses, DesignationClasses, Niveau FROM classe") as $c) {
        $classes_idx[mb_strtolower(trim($c['DesignationClasses']))] = $c;
    }
    // Candidats pour la résolution de l'arrondissement (colonne Excel unique
    // "Arrondissement", au format "Intitulé (Département)" fourni par la
    // liste déroulante du modèle) — même méthode de rapprochement que bd/
    // sync_arrondissements.php (exact puis flou), pour ne jamais dupliquer
    // le département/la région en base : seul id_arrondissement est stocké
    // (voir bd/migration_v5.sql), repli en texte libre si non résolu.
    $departements_candidats = [];
    foreach (db_all("SELECT code_depart AS id, intitule_depart AS nom FROM departement") as $d) {
        $departements_candidats[] = $d;
    }
    // Repli si le format "Nom (Département)" n'est pas reconnu (saisie
    // libre hors liste déroulante) : recherche du nom d'arrondissement
    // seul, toutes régions confondues.
    $arrondissements_globaux = db_all("SELECT code_arrond AS id, intitule_arrond AS nom FROM arrondissement");

    $ok = 0; $erreurs = []; $total = 0; $matricules_utilises = [];
    // Ligne 1 = entêtes, ligne 2 = exemple à ignorer si elle correspond
    // exactement à celle du modèle (détecté via le nom en 1ère colonne).
    foreach ($lignes as $i => $ligne) {
        $num_ligne = $i + 1;
        if ($num_ligne === 1) continue; // entête
        [$mat_col, $nom_e, $prenom, $nom_arabe, $sexe, $date_naiss, $lieu_naiss, $arrond_brut, $adresse, $niu, $classe_lbl, $statut_insc] =
            array_pad(array_map('trim', array_map('strval', $ligne)), 12, '');
        if ($num_ligne === 2 && mb_strtoupper($nom_e) === 'DOUKOURE' && $prenom === 'Awa') continue; // ligne d'exemple du modèle

        if ($nom_e === '' && $prenom === '') continue; // ligne vide, ignorée silencieusement

        $total++;
        if ($nom_e === '') {
            $erreurs[] = ['ligne' => $num_ligne, 'msg' => 'Nom manquant — ligne ignorée.'];
            continue;
        }

        $sexe_norm = (in_array(mb_strtoupper($sexe), ['F','FEMININ','FÉMININ'], true)) ? 'Feminin' : 'Masculin';

        // Date : Excel peut fournir soit une date native (nombre de série), soit du texte.
        $date_sql = null;
        if ($date_naiss !== '') {
            if (is_numeric($date_naiss)) {
                $dt = ExcelDate::excelToDateTimeObject((float)$date_naiss);
                $date_sql = $dt->format('Y-m-d');
            } else {
                $t = strtotime($date_naiss);
                $date_sql = $t ? date('Y-m-d', $t) : null;
            }
        }

        $id_arrondissement = null;
        if ($arrond_brut !== '') {
            $arr_nom = $arrond_brut;
            $dept_nom = '';
            if (preg_match('/^(.*)\(([^()]+)\)\s*$/u', $arrond_brut, $m)) {
                $arr_nom  = trim($m[1]);
                $dept_nom = trim($m[2]);
            }
            if ($dept_nom !== '') {
                $res_dept = lieu_trouver_correspondance($dept_nom, $departements_candidats);
                if ($res_dept['candidat']) {
                    $candidats_arrond = db_all(
                        "SELECT code_arrond AS id, intitule_arrond AS nom FROM arrondissement WHERE code_depart=?",
                        [(int) $res_dept['candidat']['id']]
                    );
                    $res_arr = lieu_trouver_correspondance($arr_nom, $candidats_arrond);
                    if ($res_arr['candidat']) $id_arrondissement = (int) $res_arr['candidat']['id'];
                }
            }
            if ($id_arrondissement === null) {
                $res_global = lieu_trouver_correspondance($arr_nom, $arrondissements_globaux);
                if ($res_global['candidat']) {
                    $id_arrondissement = (int) $res_global['candidat']['id'];
                } else {
                    $erreurs[] = ['ligne' => $num_ligne, 'msg' => "Arrondissement « $arrond_brut » non trouvé dans la liste officielle — importé en texte libre."];
                }
            }
        }

        $classe_trouvee = $classe_lbl !== '' ? ($classes_idx[mb_strtolower($classe_lbl)] ?? null) : null;
        if ($classe_lbl !== '' && $classe_trouvee === null) {
            $erreurs[] = ['ligne' => $num_ligne, 'msg' => "Classe « $classe_lbl » introuvable — élève importé sans classe."];
        }

        $statut_norm = normaliser_statut_insc($statut_insc);

        // Matricule : fourni par l'utilisateur (import de données existantes,
        // ex. migration depuis un autre système) ou généré automatiquement
        // si la colonne est laissée vide (comportement d'origine).
        $niveau_pour_matricule = $classe_trouvee['Niveau'] ?? 'P';
        if ($mat_col !== '') {
            $mat = mb_strtoupper($mat_col);
            if (isset($matricules_utilises[$mat]) || db_val("SELECT COUNT(*) FROM eleve WHERE Mat_elv=?", [$mat])) {
                $erreurs[] = ['ligne' => $num_ligne, 'msg' => "Matricule « $mat » déjà utilisé — ligne ignorée."];
                continue;
            }
        } else {
            $mat = gen_matricule($val_annee, $niveau_pour_matricule);
        }
        $matricules_utilises[$mat] = true;

        db_exec(
            "INSERT INTO eleve (Mat_elv, Nom_elv, Nom_arabe_elv, Prenom_elv, Sexe_elv, Date_naiss_elv, Lieu_naiss_elv,
                                 id_arrondissement, arrondissement_elv, Adresse_elv, niu, statut)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'actif')",
            [$mat, $nom_e, $nom_arabe ?: null, $prenom ?: null, $sexe_norm, $date_sql, $lieu_naiss ?: null,
             $id_arrondissement, $id_arrondissement ? null : ($arrond_brut ?: null), $adresse ?: null, $niu ?: null]
        );
        $id_eleve = (int) db_last_id();

        if ($classe_trouvee) {
            db_exec(
                "INSERT INTO inscrire (id_eleve, IDClasses, val_annee, Date_Inscrire, Statut_elv) VALUES (?, ?, ?, CURDATE(), ?)",
                [$id_eleve, $classe_trouvee['IDClasses'], $val_annee, $statut_norm]
            );
        }
        $ok++;
    }

    $rapport = ['ok' => $ok, 'erreurs' => $erreurs, 'total' => $total];
}

$titre_page = 'Importer des élèves';
require_once __DIR__ . '/../../layout/header.php';
?>

<div class="d-flex align-items-center gap-2 mb-3">
  <a href="<?= APP_URL ?>/pages/eleves/liste.php" class="btn btn-sm btn-light">
    <i class="bi bi-arrow-left"></i>
  </a>
  <div>
    <h4 class="mb-0" style="font-size:1.05rem;font-weight:700">Importer des élèves depuis Excel</h4>
    <div class="sub">Créer plusieurs élèves d'un coup à partir d'un fichier .xlsx</div>
  </div>
</div>

<?php if ($rapport): ?>
  <div class="card mb-3">
    <div class="card-body">
      <div class="section-titre"><i class="bi bi-clipboard2-check me-1"></i>Résultat de l'import</div>
      <div class="d-flex gap-3 flex-wrap mt-2 mb-2">
        <div style="background:#d1fae5;color:#065f46;border-radius:8px;padding:8px 14px;font-size:.85rem">
          <i class="bi bi-check-circle me-1"></i><strong><?= $rapport['ok'] ?></strong> élève(s) importé(s) avec succès
        </div>
        <?php if ($rapport['erreurs']): ?>
        <div style="background:#fff3cd;color:#856404;border-radius:8px;padding:8px 14px;font-size:.85rem">
          <i class="bi bi-exclamation-triangle me-1"></i><strong><?= count($rapport['erreurs']) ?></strong> remarque(s)/erreur(s)
        </div>
        <?php endif; ?>
      </div>
      <?php if ($rapport['erreurs']): ?>
        <table class="table table-sm mb-0" style="font-size:.8rem">
          <thead><tr><th style="width:80px">Ligne</th><th>Détail</th></tr></thead>
          <tbody>
            <?php foreach ($rapport['erreurs'] as $e): ?>
              <tr><td><?= (int)$e['ligne'] ?></td><td><?= h($e['msg']) ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-5">
    <div class="card">
      <div class="card-body">
        <div class="section-titre"><i class="bi bi-1-circle me-1"></i>Étape 1 — Télécharger le modèle</div>
        <p style="font-size:.82rem;color:#6b7280">
          Le modèle contient les colonnes attendues et une ligne d'exemple. Les colonnes
          « Arrondissement », « Classe » et « Statut » sont des <strong>listes déroulantes</strong>
          (valeurs sur la 2<sup>e</sup> feuille) — il suffit de choisir dans la liste, sans risque
          de faute de frappe qui ferait échouer le rapprochement. Une 3<sup>e</sup> feuille rappelle
          l'identité de l'établissement (avec son logo).
        </p>
        <a href="<?= APP_URL ?>/pages/eleves/import_modele.php" class="btn btn-outline-primary btn-sm">
          <i class="bi bi-download me-1"></i>Télécharger le modèle (.xlsx)
        </a>
      </div>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="card">
      <div class="card-body">
        <div class="section-titre"><i class="bi bi-2-circle me-1"></i>Étape 2 — Importer le fichier rempli</div>
        <form method="post" enctype="multipart/form-data" class="mt-2">
          <?= csrf_champ() ?>
          <label class="form-label">Fichier Excel (.xlsx)</label>
          <input type="file" name="fichier" accept=".xlsx" class="form-control form-control-sm" required>
          <div class="form-text" style="font-size:.68rem">5 Mo max. Seul le nom est obligatoire ; les lignes vides sont ignorées. Laissez la colonne Matricule vide pour une génération automatique, ou saisissez un matricule existant (import de données déjà attribuées) — un matricule en doublon fait ignorer la ligne.</div>
          <button class="btn btn-primary btn-sm mt-2"><i class="bi bi-upload me-1"></i>Importer</button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../layout/footer.php'; ?>
