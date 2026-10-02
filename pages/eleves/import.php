<?php
// ── Import Excel des élèves (mapping de colonnes) ─────────────
// Déroulé en 2 étapes :
//   1. « analyser »   : l'utilisateur dépose un fichier .xlsx QUELCONQUE.
//      Le fichier est stocké en zone temporaire, sa 1ère ligne est lue
//      comme en-têtes et un écran de correspondance est présenté.
//   2. « importer »   : l'utilisateur associe chaque champ élève à une
//      colonne de SON fichier (ou « — ne pas importer — »), puis lance
//      l'import. Seules les colonnes associées sont lues ; chaque ligne
//      crée un nouvel élève (jamais de mise à jour d'un élève existant).
//
// Le modèle téléchargeable (import_modele.php) reste proposé et ses
// colonnes sont reconnues automatiquement, mais n'importe quel classeur
// convient : l'ordre et le nom des colonnes n'ont plus d'importance.
//
// Champs élève gérés : Matricule (optionnel, généré si absent), Nom*,
// Prénom(s), Nom en arabe, Sexe, Date de naissance, Lieu de naissance,
// Arrondissement ("Intitulé (Département)"), Adresse, NIU, Classe, Statut.
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../bd/lib/lieux_normalisation.php';
exiger_role(['DIRECTEUR','SECRETAIRE']);
session_init();

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

// ── Champs importables ────────────────────────────────────────
// `syn` : formes normalisées (minuscule, sans accent ni espace, voir
// import_norm_entete()) d'en-têtes reconnues automatiquement.
$CHAMPS = [
    'matricule'      => ['label' => 'Matricule',        'requis' => false, 'aide' => 'Laissé vide → généré automatiquement',
                         'syn' => ['matricule','mat','matelv','matricul','immatriculation']],
    'nom'            => ['label' => 'Nom',              'requis' => true,  'aide' => 'Obligatoire',
                         'syn' => ['nom','nomelv','nomeleve','nomdefamille','nomdefamile','name','lastname','nomfamille']],
    'prenom'         => ['label' => 'Prénom(s)',        'requis' => false, 'aide' => '',
                         'syn' => ['prenom','prenoms','prenomelv','prenomeleve','firstname']],
    'nom_arabe'      => ['label' => 'Nom en arabe',     'requis' => false, 'aide' => '',
                         'syn' => ['nomenarabe','nomarabe','nomar','arabe','arabname','nomarabeeleve']],
    'sexe'           => ['label' => 'Sexe',             'requis' => false, 'aide' => 'M / F',
                         'syn' => ['sexe','genre','sex','mf','sexemf']],
    'date_naiss'     => ['label' => 'Date de naissance','requis' => false, 'aide' => 'AAAA-MM-JJ ou date Excel',
                         'syn' => ['datedenaissance','datenaissance','datenaiss','ddn','naissance','birthdate','dob','datenaissanceaaaammjj']],
    'lieu_naiss'     => ['label' => 'Lieu de naissance','requis' => false, 'aide' => '',
                         'syn' => ['lieudenaissance','lieunaissance','lieunaiss','lieunaissanceeleve','placeofbirth','lieu']],
    'arrondissement' => ['label' => 'Arrondissement',   'requis' => false, 'aide' => '« Intitulé (Département) »',
                         'syn' => ['arrondissement','arrond','commune']],
    'adresse'        => ['label' => 'Adresse',          'requis' => false, 'aide' => '',
                         'syn' => ['adresse','address','domicile','residence','quartier']],
    'niu'            => ['label' => 'NIU',              'requis' => false, 'aide' => '',
                         'syn' => ['niu','identifiantunique','numeroidentifiantunique','numeroidentifiant']],
    'classe'         => ['label' => 'Classe',           'requis' => false, 'aide' => 'Classe existante, ou créée automatiquement si ≥ 5 élèves',
                         'syn' => ['classe','class','classeeleve']],
    'statut'         => ['label' => 'Statut',           'requis' => false, 'aide' => 'Nouveau / Redoublant',
                         'syn' => ['statut','status','situation','statutinscription','statutinsc']],
];
// Ordre de détection automatique : les libellés les plus spécifiques
// réservent leur colonne AVANT les plus génériques (« Nom en arabe » /
// « Lieu de naissance » avant « Nom » / « Date de naissance »).
$ORDRE_DETECTION = ['nom_arabe','lieu_naiss','date_naiss','matricule','niu','arrondissement',
                    'adresse','sexe','statut','classe','prenom','nom'];

$TMP_PREFIX = 'siges_import_eleves_';

// En-tête normalisée pour le rapprochement automatique : minuscule, sans
// accent (lieu_normaliser) puis sans espace ni ponctuation.
function import_norm_entete(string $s): string {
    return str_replace(' ', '', lieu_normaliser($s));
}

// Ménage des fichiers temporaires abandonnés (session fermée sans import).
function import_gc_temp(string $prefix): void {
    foreach (glob(sys_get_temp_dir() . '/' . $prefix . '*.xlsx') ?: [] as $f) {
        if (is_file($f) && filemtime($f) < time() - 3600) @unlink($f);
    }
}

// Devine le code niveau d'un libellé de classe (heuristique système scolaire
// camerounais). Renvoie '' si indéterminé (la classe n'est alors PAS créée
// automatiquement — l'utilisateur la crée à la main). $niveaux_valides =
// liste des LibelleNiveau réellement présents dans la table `niveau`.
//
// Les classes parallèles / sections sont ramenées au même niveau que la
// classe de base : « CM1 A », « CM1B », « CM1-C », « CE2 B », « SIL 2 »…
// (le suffixe de section n'empêche pas la reconnaissance — d'où (?![0-9])
// plutôt qu'un \b final : seule une AUTRE chiffre invalide « CM1 »).
function import_deviner_niveau(string $libelle, array $niveaux_valides): string {
    $s = mb_strtolower(trim($libelle), 'UTF-8');
    $s = strtr($s, ['è'=>'e','é'=>'e','ê'=>'e','ë'=>'e','à'=>'a','â'=>'a','î'=>'i','ï'=>'i','ô'=>'o','ù'=>'u','û'=>'u','ç'=>'c']);
    $cand = '';
    if (preg_match('~(maternelle|prematernelle|pre[ -]?maternelle|petite\s+section|moyenne\s+section|grande\s+section|1\s*(ere|re|e)?\s*ann?ee|2\s*(eme|e)?\s*ann?ee|nursery|kindergarten|\bkg\b|\bps\b|\bms\b|\bgs\b)~u', $s)) {
        $cand = 'M';
    } elseif (preg_match('~\b(?:sil|c\.?\s*i\.?|cours\s+d.?initiation)\b~u', $s)
           || preg_match('~\bcp(?![a-z0-9])|cours\s+preparatoire~u', $s)) {
        $cand = 'I';
    } elseif (preg_match('~\bce\s*1(?![0-9])|cours\s+elementaire\s*1~u', $s)
           || preg_match('~\bce\s*2(?![0-9])|cours\s+elementaire\s*2~u', $s)) {
        $cand = 'II';
    } elseif (preg_match('~\bcm\s*1(?![0-9])|cours\s+moyen\s*1~u', $s)
           || preg_match('~\bcm\s*2(?![0-9])|cours\s+moyen\s*2~u', $s)) {
        $cand = 'III';
    } elseif (preg_match('~\blevel\s*([1-9])(?![0-9])~u', $s, $m)) {
        // « LEVEL 2 » (ou « LEVEL 2 A ») = niveau LEVEL 2 directement.
        $cand = 'LEVEL ' . min(3, max(1, (int) $m[1]));
    } elseif (preg_match('~\b(?:class|grade|primary|standard|std)\s*([1-9])(?![0-9])~u', $s, $m)) {
        // Piste anglophone : LEVEL 1 = CLASS 1-2, LEVEL 2 = CLASS 3-4,
        // LEVEL 3 = CLASS 5-6 (2 classes par niveau).
        $cand = 'LEVEL ' . min(3, max(1, (int) ceil((int) $m[1] / 2)));
    }
    return ($cand !== '' && in_array($cand, $niveaux_valides, true)) ? $cand : '';
}

$vue         = 'formulaire';   // formulaire | correspondance
$rapport     = null;           // ['ok'=>int, 'erreurs'=>[...], 'total'=>int]
$entetes     = [];             // [idx => libellé] — vue correspondance
$apercu      = [];             // premières lignes de données — vue correspondance
$map_init    = [];             // [champ => idx colonne | -1] — présélection
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
        import_gc_temp($TMP_PREFIX);

        if (empty($_FILES['fichier']['tmp_name']) || $_FILES['fichier']['error'] !== UPLOAD_ERR_OK) {
            flash_set('erreur', 'Aucun fichier valide reçu.');
            rediriger('pages/eleves/import.php');
        }
        $tmp = $_FILES['fichier']['tmp_name'];
        $nom = $_FILES['fichier']['name'];
        if (!preg_match('/\.xlsx$/i', $nom)) {
            flash_set('erreur', 'Le fichier doit être au format .xlsx.');
            rediriger('pages/eleves/import.php');
        }
        if ($_FILES['fichier']['size'] > 5 * 1024 * 1024) {
            flash_set('erreur', 'Fichier trop volumineux (5 Mo max).');
            rediriger('pages/eleves/import.php');
        }

        $dest = sys_get_temp_dir() . '/' . $TMP_PREFIX . bin2hex(random_bytes(8)) . '.xlsx';
        if (!move_uploaded_file($tmp, $dest)) {
            flash_set('erreur', "Impossible d'enregistrer le fichier temporairement.");
            rediriger('pages/eleves/import.php');
        }

        try {
            $reader = new XlsxReader();
            $reader->setReadDataOnly(true);
            $lignes = $reader->load($dest)->getSheet(0)->toArray(null, true, true, false);
        } catch (Throwable $e) {
            @unlink($dest);
            flash_set('erreur', 'Fichier illisible : ' . $e->getMessage());
            rediriger('pages/eleves/import.php');
        }

        $entetes_brutes = array_map(fn($v) => trim((string)$v), (array)($lignes[0] ?? []));
        if (!array_filter($entetes_brutes, fn($v) => $v !== '')) {
            @unlink($dest);
            flash_set('erreur', 'La première ligne du fichier doit contenir les noms des colonnes.');
            rediriger('pages/eleves/import.php');
        }

        $_SESSION['import_eleves'] = ['fichier' => $dest, 'nom' => $nom, 'ts' => time()];

        $entetes = $entetes_brutes;
        $apercu  = array_slice($lignes, 1, 3);

        // Présélection automatique : chaque colonne au plus une fois.
        $deja = [];
        foreach ($CHAMPS as $cle => $_d) { $map_init[$cle] = -1; }
        foreach ($ORDRE_DETECTION as $cle) {
            $syn = $CHAMPS[$cle]['syn'];
            $trouve = -1;
            // 1) égalité exacte avec un synonyme
            foreach ($entetes as $idx => $lbl) {
                if (in_array($idx, $deja, true)) continue;
                $n = import_norm_entete($lbl);
                if ($n !== '' && in_array($n, $syn, true)) { $trouve = $idx; break; }
            }
            // 2) sinon, un synonyme est contenu dans l'en-tête
            if ($trouve === -1) {
                foreach ($entetes as $idx => $lbl) {
                    if (in_array($idx, $deja, true)) continue;
                    $n = import_norm_entete($lbl);
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
        $sess = $_SESSION['import_eleves'] ?? null;
        $dest = $sess['fichier'] ?? '';
        if (!$sess || !is_file($dest) || strpos(basename($dest), $TMP_PREFIX) !== 0) {
            unset($_SESSION['import_eleves']);
            flash_set('erreur', "La session d'import a expiré — merci de redéposer votre fichier.");
            rediriger('pages/eleves/import.php');
        }

        try {
            $reader = new XlsxReader();
            $reader->setReadDataOnly(true);
            $lignes = $reader->load($dest)->getSheet(0)->toArray(null, true, true, false);
        } catch (Throwable $e) {
            @unlink($dest);
            unset($_SESSION['import_eleves']);
            flash_set('erreur', 'Fichier illisible : ' . $e->getMessage());
            rediriger('pages/eleves/import.php');
        }

        $map = [];
        foreach ($CHAMPS as $cle => $_d) {
            $v = $_POST['map'][$cle] ?? '-1';
            $map[$cle] = is_numeric($v) ? (int)$v : -1;
        }
        $ignorer_1ere = !empty($_POST['ignorer_premiere_ligne']);

        // Nom obligatoire : on revient à l'écran de correspondance en
        // conservant les choix de l'utilisateur.
        if ($map['nom'] < 0) {
            $entetes     = array_map(fn($v) => trim((string)$v), (array)($lignes[0] ?? []));
            $apercu      = array_slice($lignes, 1, 3);
            $map_init    = $map;
            $nom_fichier = $sess['nom'];
            $erreur_map  = 'Vous devez au minimum associer la colonne « Nom » à une colonne de votre fichier.';
            $vue = 'correspondance';
        } else {
            $annee     = get_annee_active();
            $val_annee = $annee['val_annee'] ?? '';

            $classes_idx = [];
            foreach (db_all("SELECT IDClasses, DesignationClasses, Niveau FROM classe") as $c) {
                $classes_idx[mb_strtolower(trim($c['DesignationClasses']))] = $c;
            }
            $departements_candidats = [];
            foreach (db_all("SELECT code_depart AS id, intitule_depart AS nom FROM departement") as $d) {
                $departements_candidats[] = $d;
            }
            $arrondissements_globaux = db_all("SELECT code_arrond AS id, intitule_arrond AS nom FROM arrondissement");

            // Valeur d'une cellule pour un champ donné (colonne mappée).
            $val = function (array $ligne, string $cle) use ($map) {
                $i = $map[$cle] ?? -1;
                if ($i < 0 || !array_key_exists($i, $ligne)) return '';
                return trim((string)($ligne[$i] ?? ''));
            };

            $ok = 0; $erreurs = []; $total = 0; $matricules_utilises = [];

            // ── Pré-passe : création automatique des classes absentes ──────
            // Une classe présente dans le fichier mais absente de la base est
            // créée si elle concerne AU MOINS 5 élèves (et si la case est
            // cochée), avec un niveau déduit du nom et le barème par défaut —
            // les élèves sont alors importés directement dans cette classe.
            $creer_classes = !empty($_POST['creer_classes_absentes']);
            $SEUIL_CLASSE  = 5;
            $niveaux_valides = array_column(db_all("SELECT LibelleNiveau FROM niveau"), 'LibelleNiveau');

            $compte_classe_absente = [];
            foreach ($lignes as $i => $ligne) {
                $n = $i + 1;
                if ($n === 1 || ($ignorer_1ere && $n === 2)) continue;
                $lbl = $val($ligne, 'classe');
                if ($lbl === '' || $val($ligne, 'nom') === '') continue;
                if (!isset($classes_idx[mb_strtolower($lbl)])) {
                    $compte_classe_absente[$lbl] = ($compte_classe_absente[$lbl] ?? 0) + 1;
                }
            }

            foreach ($compte_classe_absente as $lbl => $nb) {
                if (!$creer_classes || $nb < $SEUIL_CLASSE) continue;
                $niv = import_deviner_niveau($lbl, $niveaux_valides);
                if ($niv === '') {
                    $erreurs[] = ['ligne' => 0, 'msg' => "Classe « $lbl » : $nb élève(s), mais impossible de déduire le niveau du nom — créez-la dans le menu Classes puis relancez l'import."];
                    continue;
                }
                db_exec("INSERT INTO classe (DesignationClasses, Niveau) VALUES (?, ?)", [trim($lbl), $niv]);
                $new_id = (int) db_last_id();
                if ($val_annee !== '') appliquer_bareme_reference($val_annee, [$new_id]);
                synchroniser_bareme_niveau_arabe($niv, [$new_id]);
                $classes_idx[mb_strtolower($lbl)] = ['IDClasses' => $new_id, 'DesignationClasses' => trim($lbl), 'Niveau' => $niv];
                $erreurs[] = ['ligne' => 0, 'msg' => "Classe « $lbl » créée automatiquement — niveau « $niv », $nb élève(s), barème par défaut appliqué (vérifiez le niveau dans le menu Classes si besoin)."];
            }
            $classe_absente_signalee = [];

            foreach ($lignes as $i => $ligne) {
                $num_ligne = $i + 1;
                if ($num_ligne === 1) continue;                        // en-têtes
                if ($ignorer_1ere && $num_ligne === 2) continue;       // ligne d'exemple

                $mat_col     = $val($ligne, 'matricule');
                $nom_e       = $val($ligne, 'nom');
                $prenom      = $val($ligne, 'prenom');
                $nom_arabe   = $val($ligne, 'nom_arabe');
                $sexe        = $val($ligne, 'sexe');
                $date_naiss  = $val($ligne, 'date_naiss');
                $lieu_naiss  = $val($ligne, 'lieu_naiss');
                $arrond_brut = $val($ligne, 'arrondissement');
                $adresse     = $val($ligne, 'adresse');
                $niu         = $val($ligne, 'niu');
                $classe_lbl  = $val($ligne, 'classe');
                $statut_insc = $val($ligne, 'statut');

                if ($nom_e === '' && $prenom === '') continue;         // ligne vide

                $total++;
                if ($nom_e === '') {
                    $erreurs[] = ['ligne' => $num_ligne, 'msg' => 'Nom manquant — ligne ignorée.'];
                    continue;
                }

                $sexe_norm = (in_array(mb_strtoupper($sexe), ['F','FEMININ','FÉMININ'], true)) ? 'Feminin' : 'Masculin';

                // Date : Excel peut fournir une date native (nombre de série) ou du texte.
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
                if ($classe_lbl !== '' && $classe_trouvee === null && !isset($classe_absente_signalee[mb_strtolower($classe_lbl)])) {
                    $classe_absente_signalee[mb_strtolower($classe_lbl)] = true;
                    $nb_c = $compte_classe_absente[$classe_lbl] ?? 0;
                    $raison = ($creer_classes && $nb_c > 0 && $nb_c < $SEUIL_CLASSE)
                        ? " (moins de $SEUIL_CLASSE élèves — non créée automatiquement)" : '';
                    $erreurs[] = ['ligne' => 0, 'msg' => "Classe « $classe_lbl » introuvable$raison — les élèves concernés sont importés sans classe."];
                }

                $statut_norm = normaliser_statut_insc($statut_insc);

                // Matricule : fourni dans le fichier (migration de données
                // existantes) ; sinon généré — sauf si l'école est en mode
                // « matricule manuel » (matricule_config) : dans ce cas un
                // matricule absent reste NULL.
                $niveau_pour_matricule = $classe_trouvee['Niveau'] ?? 'P';
                if ($mat_col !== '') {
                    $mat = mb_strtoupper($mat_col);
                    if (isset($matricules_utilises[$mat]) || db_val("SELECT COUNT(*) FROM eleve WHERE Mat_elv=?", [$mat])) {
                        $erreurs[] = ['ligne' => $num_ligne, 'msg' => "Matricule « $mat » déjà utilisé — ligne ignorée."];
                        continue;
                    }
                } else {
                    $mat = matricule_manuel() ? null : gen_matricule($val_annee, $niveau_pour_matricule);
                }
                if ($mat !== null) $matricules_utilises[$mat] = true;

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
                    journaliser_mouvement_classe($id_eleve, $val_annee, null, (int) $classe_trouvee['IDClasses'], 'inscription');
                }
                $ok++;
            }

            @unlink($dest);
            unset($_SESSION['import_eleves']);
            $rapport = ['ok' => $ok, 'erreurs' => $erreurs, 'total' => $total];
            $vue = 'formulaire';
        }
    }
}

$es_partiel = isset($_GET['partiel']);
if (!$es_partiel) {
    $titre_page = 'Importer des élèves';
    require_once __DIR__ . '/../../layout/header.php';
} else {
    header('Content-Type: text/html; charset=utf-8');
}

// Aide au rendu d'une <option> de colonne.
function import_option_colonne(int $idx, string $lbl): string {
    $lettre = Coordinate::stringFromColumnIndex($idx + 1);
    $texte  = $lbl !== '' ? $lbl : '(sans titre)';
    return '<option value="' . $idx . '">' . h($texte) . ' — col. ' . h($lettre) . '</option>';
}
?>

<div id="import-eleves-zone">

<div class="d-flex align-items-center gap-2 mb-3">
  <a href="<?= APP_URL ?>/pages/eleves/liste.php" class="btn btn-sm btn-light" data-ajax-nav>
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

      <form method="post" class="mt-2" data-ajax-post-form action="<?= APP_URL ?>/pages/eleves/import.php">
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
                    $opt = import_option_colonne((int)$idx, (string)$lbl);
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

        <div class="form-check mt-1">
          <input class="form-check-input" type="checkbox" id="creer_classes_absentes" name="creer_classes_absentes" value="1"<?= empty($_POST) || !empty($_POST['creer_classes_absentes']) ? ' checked' : '' ?>>
          <label class="form-check-label" for="creer_classes_absentes" style="font-size:.8rem">
            Créer automatiquement une classe absente si le fichier contient au moins
            <strong>5&nbsp;élèves</strong> pour cette classe (niveau déduit du nom, barème par défaut appliqué)
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
          <a href="<?= APP_URL ?>/pages/eleves/import.php" class="btn btn-light btn-sm" data-ajax-nav>Annuler</a>
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
            (Arrondissement, Classe, Statut) qui évitent les fautes de frappe. Il n'est pas obligatoire :
            <strong>n'importe quel fichier .xlsx convient</strong> — vous choisirez ensuite quelles
            colonnes importer.
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
          <div class="section-titre"><i class="bi bi-2-circle me-1"></i>Étape 2 — Déposer votre fichier</div>
          <form method="post" enctype="multipart/form-data" class="mt-2" data-ajax-post-form action="<?= APP_URL ?>/pages/eleves/import.php">
            <?= csrf_champ() ?>
            <input type="hidden" name="etape" value="analyser">
            <label class="form-label">Fichier Excel (.xlsx)</label>
            <input type="file" name="fichier" accept=".xlsx" class="form-control form-control-sm" required>
            <div class="form-text" style="font-size:.68rem">
              5 Mo max. La première ligne doit contenir les noms des colonnes. À l'étape suivante,
              vous associerez chaque champ élève à une colonne de votre fichier ; seules les colonnes
              associées seront importées. Chaque ligne crée un nouvel élève (aucune mise à jour).
            </div>
            <button class="btn btn-primary btn-sm mt-2"><i class="bi bi-arrow-right me-1"></i>Analyser le fichier</button>
          </form>
        </div>
      </div>
    </div>
  </div>

<?php endif; ?>

</div><!-- /#import-eleves-zone -->
<?php if ($es_partiel) exit; // rien de plus dans une réponse AJAX partielle. ?>

<?php
$ajax_zone_id = 'import-eleves-zone'; // voir layout/footer.php — initAjaxZone() y est appelé
require_once __DIR__ . '/../../layout/footer.php';
