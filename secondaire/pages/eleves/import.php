<?php
// secondaire/pages/eleves/import.php — import Excel des élèves (école
// secondaire), même principe que pages/eleves/import.php (primaire) mais
// adapté au schéma secondaire : pas d'arrondissement en table de référence
// (texte libre), pas de création automatique de classe absente (schéma
// classe plus riche — niveau/section/filière/série — une correspondance
// hâtive risquerait de mal classer un élève ; l'admin crée la classe à la
// main si besoin puis relance l'import). Demande explicite du 17/09/2026.
//
// Déroulé en 2 étapes : 1) dépôt d'un fichier .xlsx quelconque, analyse de
// sa 1ère ligne comme en-têtes ; 2) correspondance des colonnes du fichier
// avec les champs élève, puis import. Chaque ligne crée un NOUVEL élève
// (jamais de mise à jour d'un élève existant).
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../bd/lib/lieux_normalisation.php';
exiger_role(['ADMIN', 'PROVISEUR', 'FONDATEUR', 'SECRETAIRE']);
session_init();

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

// ── Champs importables ────────────────────────────────────────
// `syn` : formes normalisées (minuscule, sans accent ni espace) d'en-têtes
// reconnues automatiquement — voir import_norm_entete().
$CHAMPS = [
    'matricule'  => ['label' => 'Matricule',   'requis' => false, 'aide' => 'Laissé vide → généré automatiquement',
                     'syn' => ['matricule','mat','matricul']],
    'nom'        => ['label' => 'Nom',         'requis' => true,  'aide' => 'Obligatoire',
                     'syn' => ['nom','nomeleve','nomdefamille','nomdefamile','name','lastname','nomfamille']],
    'prenom'     => ['label' => 'Prénom(s)',   'requis' => false, 'aide' => '',
                     'syn' => ['prenom','prenoms','prenomeleve','firstname']],
    'sexe'       => ['label' => 'Sexe',        'requis' => false, 'aide' => 'M / F',
                     'syn' => ['sexe','genre','sex','mf','sexemf']],
    'date_naiss' => ['label' => 'Date de naissance', 'requis' => false, 'aide' => 'AAAA-MM-JJ ou date Excel',
                     'syn' => ['datedenaissance','datenaissance','datenaiss','ddn','naissance','birthdate','dob']],
    'lieu_naiss' => ['label' => 'Lieu de naissance', 'requis' => false, 'aide' => '',
                     'syn' => ['lieudenaissance','lieunaissance','lieunaiss','placeofbirth','lieu']],
    'region_naiss' => ['label' => 'Région de naissance', 'requis' => false, 'aide' => '',
                     'syn' => ['regiondenaissance','regionnaissance','region']],
    'departement_naiss' => ['label' => 'Département de naissance', 'requis' => false, 'aide' => '',
                     'syn' => ['departementdenaissance','departementnaissance','departement']],
    'arrondissement_naiss' => ['label' => 'Arrondissement de naissance', 'requis' => false, 'aide' => '',
                     'syn' => ['arrondissementdenaissance','arrondissementnaissance','arrondissement','commune']],
    'adresse'    => ['label' => 'Adresse',     'requis' => false, 'aide' => '',
                     'syn' => ['adresse','address','domicile','residence','quartier']],
    'telephone'  => ['label' => 'Téléphone',   'requis' => false, 'aide' => '',
                     'syn' => ['telephone','tel','phone','numero','contact']],
    'niu'        => ['label' => 'NIU',         'requis' => false, 'aide' => '',
                     'syn' => ['niu','identifiantunique','numeroidentifiantunique']],
    'classe'     => ['label' => 'Classe',      'requis' => false, 'aide' => 'Classe existante uniquement (créez-la avant si besoin)',
                     'syn' => ['classe','class','classeeleve']],
    'statut'     => ['label' => 'Statut',      'requis' => false, 'aide' => 'Nouveau / Ancien / Redoublant / Transféré',
                     'syn' => ['statut','status','situation','statutinscription']],
];
// Ordre de détection automatique : les libellés les plus spécifiques
// réservent leur colonne AVANT les plus génériques.
$ORDRE_DETECTION = ['arrondissement_naiss','departement_naiss','region_naiss','lieu_naiss','date_naiss',
                    'matricule','niu','adresse','telephone','sexe','statut','classe','prenom','nom'];

$TMP_PREFIX = 'siges_import_eleves_sec_';

// En-tête normalisée pour le rapprochement automatique.
function import_sec_norm_entete(string $s): string {
    return str_replace(' ', '', lieu_normaliser($s));
}

// Ménage des fichiers temporaires abandonnés (session fermée sans import).
function import_sec_gc_temp(string $prefix): void {
    foreach (glob(sys_get_temp_dir() . '/' . $prefix . '*.xlsx') ?: [] as $f) {
        if (is_file($f) && filemtime($f) < time() - 3600) @unlink($f);
    }
}

// Statut d'inscription reconnu — sinon 'Nouveau' par défaut (même liste que
// secondaire/pages/eleves/form.php).
function import_sec_normaliser_statut(string $s): string {
    $s = trim($s);
    foreach (['Nouveau', 'Ancien', 'Redoublant', 'Transféré'] as $v) {
        if (mb_strtolower($s, 'UTF-8') === mb_strtolower($v, 'UTF-8')) return $v;
    }
    return 'Nouveau';
}

$vue         = 'formulaire';   // formulaire | correspondance
$rapport     = null;           // ['ok'=>int, 'erreurs'=>[...], 'total'=>int]
$entetes     = [];
$apercu      = [];
$map_init    = [];
$erreur_map  = '';
$nom_fichier = '';
$ignorer_1ere = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $etape = $_POST['etape'] ?? '';

    // ─────────────────────────────────────────────────────────────
    // Étape 1 — analyse du fichier déposé
    // ─────────────────────────────────────────────────────────────
    if ($etape === 'analyser') {
        import_sec_gc_temp($TMP_PREFIX);

        if (empty($_FILES['fichier']['tmp_name']) || $_FILES['fichier']['error'] !== UPLOAD_ERR_OK) {
            flash_set('erreur', 'Aucun fichier valide reçu.');
            rediriger('secondaire/pages/eleves/import.php');
        }
        $tmp = $_FILES['fichier']['tmp_name'];
        $nom = $_FILES['fichier']['name'];
        if (!preg_match('/\.xlsx$/i', $nom)) {
            flash_set('erreur', 'Le fichier doit être au format .xlsx.');
            rediriger('secondaire/pages/eleves/import.php');
        }
        if ($_FILES['fichier']['size'] > 5 * 1024 * 1024) {
            flash_set('erreur', 'Fichier trop volumineux (5 Mo max).');
            rediriger('secondaire/pages/eleves/import.php');
        }

        $dest = sys_get_temp_dir() . '/' . $TMP_PREFIX . bin2hex(random_bytes(8)) . '.xlsx';
        if (!move_uploaded_file($tmp, $dest)) {
            flash_set('erreur', "Impossible d'enregistrer le fichier temporairement.");
            rediriger('secondaire/pages/eleves/import.php');
        }

        try {
            $reader = new XlsxReader();
            $reader->setReadDataOnly(true);
            $lignes = $reader->load($dest)->getSheet(0)->toArray(null, true, true, false);
        } catch (Throwable $e) {
            @unlink($dest);
            flash_set('erreur', 'Fichier illisible : ' . $e->getMessage());
            rediriger('secondaire/pages/eleves/import.php');
        }

        $entetes_brutes = array_map(fn($v) => trim((string)$v), (array)($lignes[0] ?? []));
        if (!array_filter($entetes_brutes, fn($v) => $v !== '')) {
            @unlink($dest);
            flash_set('erreur', 'La première ligne du fichier doit contenir les noms des colonnes.');
            rediriger('secondaire/pages/eleves/import.php');
        }

        $_SESSION['import_eleves_sec'] = ['fichier' => $dest, 'nom' => $nom, 'ts' => time()];

        $entetes = $entetes_brutes;
        $apercu  = array_slice($lignes, 1, 3);

        // Présélection automatique : chaque colonne au plus une fois.
        $deja = [];
        foreach ($CHAMPS as $cle => $_d) { $map_init[$cle] = -1; }
        foreach ($ORDRE_DETECTION as $cle) {
            $syn = $CHAMPS[$cle]['syn'];
            $trouve = -1;
            foreach ($entetes as $idx => $lbl) {
                if (in_array($idx, $deja, true)) continue;
                $n = import_sec_norm_entete($lbl);
                if ($n !== '' && in_array($n, $syn, true)) { $trouve = $idx; break; }
            }
            if ($trouve === -1) {
                foreach ($entetes as $idx => $lbl) {
                    if (in_array($idx, $deja, true)) continue;
                    $n = import_sec_norm_entete($lbl);
                    if ($n === '') continue;
                    foreach ($syn as $s) {
                        if (strlen($s) >= 3 && strpos($n, $s) !== false) { $trouve = $idx; break 2; }
                    }
                }
            }
            $map_init[$cle] = $trouve;
            if ($trouve !== -1) $deja[] = $trouve;
        }

        $nom_fichier = $nom;
        $vue = 'correspondance';
    }

    // ─────────────────────────────────────────────────────────────
    // Étape 2 — import avec la correspondance choisie
    // ─────────────────────────────────────────────────────────────
    elseif ($etape === 'importer') {
        $sess = $_SESSION['import_eleves_sec'] ?? null;
        $dest = $sess['fichier'] ?? '';
        if (!$sess || !is_file($dest) || strpos(basename($dest), $TMP_PREFIX) !== 0) {
            unset($_SESSION['import_eleves_sec']);
            flash_set('erreur', "La session d'import a expiré — merci de redéposer votre fichier.");
            rediriger('secondaire/pages/eleves/import.php');
        }

        try {
            $reader = new XlsxReader();
            $reader->setReadDataOnly(true);
            $lignes = $reader->load($dest)->getSheet(0)->toArray(null, true, true, false);
        } catch (Throwable $e) {
            @unlink($dest);
            unset($_SESSION['import_eleves_sec']);
            flash_set('erreur', 'Fichier illisible : ' . $e->getMessage());
            rediriger('secondaire/pages/eleves/import.php');
        }

        $map = [];
        foreach ($CHAMPS as $cle => $_d) {
            $v = $_POST['map'][$cle] ?? '-1';
            $map[$cle] = is_numeric($v) ? (int)$v : -1;
        }
        $ignorer_1ere = !empty($_POST['ignorer_premiere_ligne']);

        if ($map['nom'] < 0) {
            $entetes     = array_map(fn($v) => trim((string)$v), (array)($lignes[0] ?? []));
            $apercu      = array_slice($lignes, 1, 3);
            $map_init    = $map;
            $nom_fichier = $sess['nom'];
            $erreur_map  = 'Vous devez au minimum associer la colonne « Nom » à une colonne de votre fichier.';
            $vue = 'correspondance';
        } else {
            $annee     = get_annee_active();
            $id_annee  = (int) ($annee['id'] ?? 0);

            $classes_idx = [];
            foreach (db_all("SELECT id, designation FROM classe WHERE archivee=0") as $c) {
                $classes_idx[mb_strtolower(trim($c['designation']))] = $c;
            }

            $val = function (array $ligne, string $cle) use ($map) {
                $i = $map[$cle] ?? -1;
                if ($i < 0 || !array_key_exists($i, $ligne)) return '';
                return trim((string)($ligne[$i] ?? ''));
            };

            $ok = 0; $erreurs = []; $total = 0; $matricules_utilises = [];
            $classe_absente_signalee = [];

            foreach ($lignes as $i => $ligne) {
                $num_ligne = $i + 1;
                if ($num_ligne === 1) continue;                        // en-têtes
                if ($ignorer_1ere && $num_ligne === 2) continue;       // ligne d'exemple

                $mat_col     = $val($ligne, 'matricule');
                $nom_e       = $val($ligne, 'nom');
                $prenom      = $val($ligne, 'prenom');
                $sexe        = $val($ligne, 'sexe');
                $date_naiss  = $val($ligne, 'date_naiss');
                $lieu_naiss  = $val($ligne, 'lieu_naiss');
                $region_naiss = $val($ligne, 'region_naiss');
                $departement_naiss = $val($ligne, 'departement_naiss');
                $arrondissement_naiss = $val($ligne, 'arrondissement_naiss');
                $adresse     = $val($ligne, 'adresse');
                $telephone   = $val($ligne, 'telephone');
                $niu         = $val($ligne, 'niu');
                $classe_lbl  = $val($ligne, 'classe');
                $statut_insc = $val($ligne, 'statut');

                if ($nom_e === '' && $prenom === '') continue;         // ligne vide

                $total++;
                if ($nom_e === '') {
                    $erreurs[] = ['ligne' => $num_ligne, 'msg' => 'Nom manquant — ligne ignorée.'];
                    continue;
                }

                $sexe_norm = (in_array(mb_strtoupper($sexe), ['F','FEMININ','FÉMININ'], true)) ? 'F' : 'M';

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

                $classe_trouvee = $classe_lbl !== '' ? ($classes_idx[mb_strtolower($classe_lbl)] ?? null) : null;
                if ($classe_lbl !== '' && $classe_trouvee === null && !isset($classe_absente_signalee[mb_strtolower($classe_lbl)])) {
                    $classe_absente_signalee[mb_strtolower($classe_lbl)] = true;
                    $erreurs[] = ['ligne' => 0, 'msg' => "Classe « $classe_lbl » introuvable — les élèves concernés sont importés sans classe (créez la classe dans le menu Classes puis affectez-les manuellement)."];
                }

                $statut_norm = import_sec_normaliser_statut($statut_insc ?: 'Nouveau');

                // Matricule : fourni dans le fichier (migration de données
                // existantes) ; sinon généré via gen_matricule_secondaire()
                // (pas de mode « manuel » côté secondaire, contrairement au
                // primaire — voir fonctions.php).
                if ($mat_col !== '') {
                    $mat = mb_strtoupper($mat_col);
                    if (isset($matricules_utilises[$mat]) || db_val("SELECT COUNT(*) FROM eleve WHERE matricule=?", [$mat])) {
                        $erreurs[] = ['ligne' => $num_ligne, 'msg' => "Matricule « $mat » déjà utilisé — ligne ignorée."];
                        continue;
                    }
                } else {
                    $mat = gen_matricule_secondaire();
                }
                $matricules_utilises[$mat] = true;

                db_exec(
                    "INSERT INTO eleve (matricule, nom, prenom, sexe, date_naiss, lieu_naiss, region_naiss,
                                         departement_naiss, arrondissement_naiss, adresse, telephone, niu, statut)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'actif')",
                    [$mat, $nom_e, $prenom ?: null, $sexe_norm, $date_sql, $lieu_naiss ?: null, $region_naiss ?: null,
                     $departement_naiss ?: null, $arrondissement_naiss ?: null, $adresse ?: null, $telephone ?: null, $niu ?: null]
                );
                $id_eleve = (int) db_last_id();

                if ($classe_trouvee && $id_annee) {
                    db_exec(
                        "INSERT INTO inscription (id_eleve, id_classe, id_annee, statut, date_inscription) VALUES (?, ?, ?, ?, CURDATE())",
                        [$id_eleve, $classe_trouvee['id'], $id_annee, $statut_norm]
                    );
                }
                $ok++;
            }

            @unlink($dest);
            unset($_SESSION['import_eleves_sec']);
            $rapport = ['ok' => $ok, 'erreurs' => $erreurs, 'total' => $total];
            $vue = 'formulaire';
        }
    }
}

$es_partiel = isset($_GET['partiel']);
if (!$es_partiel) {
    $titre_page = 'Importer des élèves';
    require_once __DIR__ . '/../../../layout/header.php';
} else {
    header('Content-Type: text/html; charset=utf-8');
}

function import_sec_option_colonne(int $idx, string $lbl): string {
    $lettre = Coordinate::stringFromColumnIndex($idx + 1);
    $texte  = $lbl !== '' ? $lbl : '(sans titre)';
    return '<option value="' . $idx . '">' . h($texte) . ' — col. ' . h($lettre) . '</option>';
}
?>

<div id="import-eleves-sec-zone">

<div class="d-flex align-items-center gap-2 mb-3">
  <a href="<?= APP_URL ?>/secondaire/pages/eleves/liste.php" class="btn btn-sm btn-light" data-ajax-nav>
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
              <tr><td><?= (int)$e['ligne'] > 0 ? (int)$e['ligne'] : '<span class="text-muted">—</span>' ?></td><td><?= h($e['msg']) ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php if ($vue === 'correspondance'): ?>

  <?php if ($erreur_map): ?>
    <div class="alert alert-danger py-2" style="font-size:.85rem"><i class="bi bi-exclamation-triangle me-1"></i><?= h($erreur_map) ?></div>
  <?php endif; ?>

  <div class="card">
    <div class="card-body">
      <div class="section-titre"><i class="bi bi-2-circle me-1"></i>Étape 2 — Choisir les colonnes à importer</div>
      <p style="font-size:.82rem;color:#6b7280">
        Fichier : <strong><?= h($nom_fichier) ?></strong>. Pour chaque champ élève, choisissez la
        colonne correspondante de votre fichier — ou laissez sur <em>« — ne pas importer — »</em>.
        <strong>Seules les colonnes associées seront importées.</strong>
      </p>

      <form method="post" class="mt-2" data-ajax-post-form action="<?= APP_URL ?>/secondaire/pages/eleves/import.php">
        <?= csrf_champ() ?>
        <input type="hidden" name="etape" value="importer">

        <div class="row g-2">
          <?php foreach ($CHAMPS as $cle => $def): ?>
            <div class="col-md-6">
              <label class="form-label mb-1" style="font-size:.8rem;font-weight:600">
                <?= h($def['label']) ?><?php if ($def['requis']): ?> <span class="text-danger">*</span><?php endif; ?>
                <?php if ($def['aide']): ?><span style="font-weight:400;color:#9ca3af"> — <?= h($def['aide']) ?></span><?php endif; ?>
              </label>
              <select name="map[<?= h($cle) ?>]" class="form-select form-select-sm"<?= $def['requis'] ? ' required' : '' ?>>
                <option value="-1"<?= (($map_init[$cle] ?? -1) === -1 ? ' selected' : '') ?>>— ne pas importer —</option>
                <?php foreach ($entetes as $idx => $lbl): ?>
                  <?php
                    $opt = import_sec_option_colonne((int)$idx, (string)$lbl);
                    if (($map_init[$cle] ?? -1) === (int)$idx) {
                        $opt = str_replace('<option ', '<option selected ', $opt);
                    }
                    echo $opt;
                  ?>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="form-check mt-3">
          <input class="form-check-input" type="checkbox" id="ignorer_premiere_ligne" name="ignorer_premiere_ligne" value="1"<?= $ignorer_1ere ? ' checked' : '' ?>>
          <label class="form-check-label" for="ignorer_premiere_ligne" style="font-size:.8rem">
            Ignorer la première ligne de données (ligne d'exemple / de commentaire)
          </label>
        </div>

        <?php if ($apercu): ?>
          <div class="mt-3">
            <div style="font-size:.75rem;color:#6b7280;margin-bottom:4px">Aperçu de votre fichier (3 premières lignes) :</div>
            <div style="overflow-x:auto">
              <table class="table table-sm table-bordered" style="font-size:.72rem;white-space:nowrap">
                <thead>
                  <tr>
                    <?php foreach ($entetes as $idx => $lbl): ?>
                      <th><?= h($lbl !== '' ? $lbl : '(sans titre)') ?><br><span style="font-weight:400;color:#9ca3af">col. <?= h(Coordinate::stringFromColumnIndex((int)$idx + 1)) ?></span></th>
                    <?php endforeach; ?>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($apercu as $ligne_ap): ?>
                    <tr>
                      <?php foreach ($entetes as $idx => $lbl): ?>
                        <td><?= h((string)($ligne_ap[$idx] ?? '')) ?></td>
                      <?php endforeach; ?>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        <?php endif; ?>

        <div class="d-flex gap-2 mt-3">
          <button class="btn btn-primary btn-sm"><i class="bi bi-upload me-1"></i>Importer les colonnes choisies</button>
          <a href="<?= APP_URL ?>/secondaire/pages/eleves/import.php" class="btn btn-light btn-sm" data-ajax-nav>Annuler</a>
        </div>
      </form>
    </div>
  </div>

<?php else: ?>

  <div class="row g-3">
    <div class="col-lg-5">
      <div class="card">
        <div class="card-body">
          <div class="section-titre"><i class="bi bi-1-circle me-1"></i>Étape 1 — (facultatif) Télécharger le modèle</div>
          <p style="font-size:.82rem;color:#6b7280">
            Le modèle propose des colonnes prêtes à l'emploi et des <strong>listes déroulantes</strong>
            (Classe, Statut) qui évitent les fautes de frappe. Il n'est pas obligatoire :
            <strong>n'importe quel fichier .xlsx convient</strong> — vous choisirez ensuite quelles
            colonnes importer.
          </p>
          <a href="<?= APP_URL ?>/secondaire/pages/eleves/import_modele.php" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-download me-1"></i>Télécharger le modèle (.xlsx)
          </a>
        </div>
      </div>
    </div>
    <div class="col-lg-7">
      <div class="card">
        <div class="card-body">
          <div class="section-titre"><i class="bi bi-2-circle me-1"></i>Étape 2 — Déposer votre fichier</div>
          <form method="post" enctype="multipart/form-data" class="mt-2" data-ajax-post-form action="<?= APP_URL ?>/secondaire/pages/eleves/import.php">
            <?= csrf_champ() ?>
            <input type="hidden" name="etape" value="analyser">
            <label class="form-label">Fichier Excel (.xlsx)</label>
            <input type="file" name="fichier" accept=".xlsx" class="form-control form-control-sm" required>
            <div class="form-text" style="font-size:.68rem">
              5 Mo max. La première ligne doit contenir les noms des colonnes. À l'étape suivante,
              vous associerez chaque champ élève à une colonne de votre fichier ; seules les colonnes
              associées seront importées. Chaque ligne crée un nouvel élève (aucune mise à jour). Les
              classes doivent déjà exister (menu Classes) — un élève dont la classe est introuvable est
              importé sans inscription, à affecter manuellement ensuite.
            </div>
            <button class="btn btn-primary btn-sm mt-2"><i class="bi bi-arrow-right me-1"></i>Analyser le fichier</button>
          </form>
        </div>
      </div>
    </div>
  </div>

<?php endif; ?>

</div><!-- /#import-eleves-sec-zone -->
<?php if ($es_partiel) exit; // rien de plus dans une réponse AJAX partielle. ?>

<?php
$ajax_zone_id = 'import-eleves-sec-zone';
require_once __DIR__ . '/../../../layout/footer.php';
