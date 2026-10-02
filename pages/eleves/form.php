<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_role(['DIRECTEUR','SECRETAIRE','COMPTABLE']);

$id    = (int)($_GET['id'] ?? 0);
$eleve = $id ? db_one("SELECT * FROM eleve WHERE id_eleve=?", [$id]) : null;
if ($id && !$eleve) { flash_set('erreur', 'Élève introuvable.'); rediriger('pages/eleves/liste.php'); }

$annee     = get_annee_active();
$val_annee = $annee['val_annee'] ?? '';
$classes   = db_all(
    "SELECT c.*, n.OrdreNiveau FROM classe c LEFT JOIN niveau n ON n.LibelleNiveau=c.Niveau
     ORDER BY n.OrdreNiveau, c.DesignationClasses"
);
$inscription = $eleve
    ? db_one("SELECT * FROM inscrire WHERE id_eleve=? AND val_annee=?", [$eleve['id_eleve'], $val_annee])
    : null;

// Cascade région → département → arrondissement (assets/js/lieu-cascade.js) —
// remplace l'ancien select "Département d'origine" + champ texte
// "Arrondissement" : connaître l'arrondissement suffit à retrouver le
// département et la région par jointure, jamais stockés séparément
// (voir bd/migration_v5.sql). Les régions (liste fixe, 10) sont rendues
// ici en PHP ; département/arrondissement sont peuplés en AJAX.
$regions = db_all("SELECT id_region, intitule_region FROM region ORDER BY intitule_region");
$lieu_edit = null;
if ($eleve && $eleve['id_arrondissement']) {
    $lieu_edit = db_one(
        "SELECT a.code_arrond, d.code_depart, r.id_region
         FROM arrondissement a
         JOIN departement d ON d.code_depart = a.code_depart
         JOIN region r ON r.id_region = d.code_region
         WHERE a.code_arrond = ?",
        [$eleve['id_arrondissement']]
    );
}

$ve = fn(string $k) => $eleve[$k] ?? '';

// Matricule : saisie manuelle (champ libre, éventuellement vide) ou
// génération automatique à l'enregistrement — voir matricule_config().
$mat_manuel = matricule_manuel();

// NIU proposé automatiquement pour un NOUVEL élève seulement (voir
// fonctions.php::gen_niu()) — champ texte normal, reste modifiable/
// effaçable si le vrai NIU officiel est déjà connu ou à saisir plus tard.
// $reserver=false : simple aperçu du prochain NIU, sans créer de
// réservation au registre central (la frappe réelle a lieu à
// l'enregistrement, pages/eleves/save.php).
$niu_propose = $eleve ? '' : gen_niu(get_etablissement()['Initial_Etab'] ?? '', 'PMC', false);

$es_partiel = isset($_GET['partiel']);
if (!$es_partiel) {
    $titre_page = $eleve ? 'Modifier un élève' : 'Nouvel élève';
    require_once __DIR__ . '/../../layout/header.php';
} else {
    header('Content-Type: text/html; charset=utf-8');
}
?>
<?php if (!$es_partiel): ?><link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/cropper/cropper.min.css"><?php endif; ?>

<div id="eleve-form-zone">

<div class="d-flex align-items-center gap-2 mb-3">
  <a href="<?= APP_URL ?>/pages/eleves/liste.php" class="btn btn-sm btn-light">
    <i class="bi bi-arrow-left"></i>
  </a>
  <div>
    <h4 class="mb-0" style="font-size:1.05rem;font-weight:700"><?= h($titre_page) ?></h4>
    <div class="sub"><?= $eleve
        ? h($eleve['Mat_elv'] ?: '— sans matricule —')
        : ($mat_manuel ? 'Matricule saisi manuellement (peut rester vide)' : 'Le matricule sera généré automatiquement') ?></div>
  </div>
</div>

<form method="post" action="<?= APP_URL ?>/pages/eleves/save.php" enctype="multipart/form-data" data-ajax-post-form>
  <?= csrf_champ() ?>
  <input type="hidden" name="id" value="<?= $eleve ? (int)$eleve['id_eleve'] : '' ?>">
  <input type="hidden" name="photo_b64" id="photo_b64">
  <input type="file" name="photo_fichier" id="photo_fichier" accept="image/jpeg" style="display:none">

  <div class="row g-2">
    <div class="col-lg-8">
      <div class="card mb-2">
        <div class="card-body">
          <div class="section-titre"><i class="bi bi-person me-1"></i>Informations personnelles</div>
          <div class="row g-compact">
            <div class="col-md-6">
              <label class="form-label">Nom <span class="text-danger">*</span></label>
              <input type="text" name="nom" class="form-control" required value="<?= h($ve('Nom_elv')) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Prénom(s)</label>
              <input type="text" name="prenom" class="form-control" value="<?= h($ve('Prenom_elv')) ?>">
            </div>
            <div class="col-md-12">
              <label class="form-label">Nom en arabe</label>
              <input type="text" name="nom_arabe" class="form-control" dir="rtl" value="<?= h($ve('Nom_arabe_elv')) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Sexe</label>
              <select name="sexe" class="form-select">
                <option value="Masculin" <?= ($ve('Sexe_elv') !== 'Feminin') ? 'selected' : '' ?>>Masculin</option>
                <option value="Feminin" <?= ($ve('Sexe_elv') === 'Feminin') ? 'selected' : '' ?>>Féminin</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Date de naissance</label>
              <input type="date" name="date_naiss" class="form-control" value="<?= h($ve('Date_naiss_elv')) ?>">
            </div>
            <div class="col-md-5">
              <label class="form-label">Lieu de naissance</label>
              <input type="text" name="lieu_naiss" class="form-control" value="<?= h($ve('Lieu_naiss_elv')) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Région d'origine</label>
              <select id="sel_region" class="form-select">
                <option value="">— Choisir —</option>
                <?php foreach ($regions as $r): ?>
                  <option value="<?= (int) $r['id_region'] ?>"><?= h($r['intitule_region']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Département d'origine</label>
              <select id="sel_departement" class="form-select" disabled>
                <option value="">— Choisir une région d'abord —</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Arrondissement d'origine</label>
              <select id="sel_arrondissement" name="id_arrondissement" class="form-select" disabled>
                <option value="">— Choisir un département d'abord —</option>
              </select>
            </div>
            <div class="col-md-12">
              <input type="text" id="inp_lieu_libre" name="lieu_libre" class="form-control mt-1"
                     style="display:none" placeholder="Préciser l'arrondissement (non répertorié dans la liste officielle)">
            </div>
            <div class="col-md-6">
              <label class="form-label">
                NIU
                <span class="badge" style="background:var(--purple-bg);color:var(--purple);font-size:.62rem;font-weight:600;vertical-align:1px">
                  <i class="bi bi-lock-fill me-1"></i>Généré automatiquement
                </span>
              </label>
              <!-- Verrouillé : jamais saisi/modifié à la main (voir fonctions.php::gen_niu()) —
                   readonly (pas disabled) pour que la valeur soit quand même transmise à save.php. -->
              <input type="text" name="niu" class="form-control" readonly
                     value="<?= h($ve('niu') ?: $niu_propose) ?>" placeholder="Non attribué"
                     style="font-family:var(--font-mono);font-weight:700;font-size:1.05rem;letter-spacing:.06em;
                            background:var(--purple-bg);color:var(--purple);border:1.5px solid var(--purple);cursor:not-allowed">
            </div>
            <div class="col-md-6">
              <label class="form-label">Matricule<?= $mat_manuel ? '' : ' interne' ?></label>
              <?php if ($mat_manuel): ?>
                <input type="text" name="matricule" class="form-control" maxlength="30"
                       value="<?= h($ve('Mat_elv')) ?>" placeholder="Laisser vide si non attribué"
                       style="font-family:var(--font-mono);letter-spacing:.04em">
                <div class="form-text" style="font-size:.68rem">Saisie manuelle (Configuration → onglet « Import &amp; matricules »).</div>
              <?php else: ?>
                <input type="text" class="form-control" disabled
                       value="<?= $eleve ? h($eleve['Mat_elv'] ?: '—') : 'Généré automatiquement' ?>">
              <?php endif; ?>
            </div>
            <div class="col-md-12">
              <label class="form-label">Adresse</label>
              <input type="text" name="adresse" class="form-control" value="<?= h($ve('Adresse_elv')) ?>">
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-body">
          <div class="section-titre"><i class="bi bi-journal-check me-1"></i>Inscription — <?= h($val_annee) ?></div>
          <div class="row g-compact">
            <div class="col-md-7">
              <label class="form-label">Classe</label>
              <select name="id_classe" class="form-select">
                <option value="">— Non inscrit —</option>
                <?php foreach ($classes as $c): ?>
                  <option value="<?= (int)$c['IDClasses'] ?>" <?= (($inscription['IDClasses'] ?? 0) == $c['IDClasses']) ? 'selected' : '' ?>>
                    <?= h($c['DesignationClasses']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-5">
              <label class="form-label">Statut scolaire</label>
              <select name="statut_insc" class="form-select">
                <option value="Non" <?= (($inscription['Statut_elv'] ?? 'Non') !== 'Oui') ? 'selected' : '' ?>>Nouveau</option>
                <option value="Oui" <?= (($inscription['Statut_elv'] ?? '') === 'Oui') ? 'selected' : '' ?>>Redoublant</option>
              </select>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card">
        <div class="card-body">
          <div class="section-titre"><i class="bi bi-camera me-1"></i>Photo</div>
          <div class="d-flex flex-column align-items-center gap-2">
            <?php $photo_src = $eleve ? url_photo_eleve((int)$eleve['id_eleve'], $eleve['Photo_elv'] !== null, $eleve['Sexe_elv']) : ''; ?>
            <img id="preview" src="<?= h($photo_src) ?>" class="photo-preview"
                 style="<?= $photo_src ? '' : 'display:none' ?>"
                 onclick="document.getElementById('file_photo').click()" alt="Photo">
            <div id="placeholder" class="photo-placeholder" style="<?= $photo_src ? 'display:none' : '' ?>"
                 onclick="document.getElementById('file_photo').click()">
              <i class="bi bi-person-bounding-box"></i>
              <span>Cliquer pour choisir</span>
            </div>
            <input type="file" id="file_photo" name="photo" accept="image/*" style="display:none" onchange="onFichierChoisi(event)">
            <div class="d-flex gap-1">
              <button type="button" class="btn btn-outline-secondary btn-sm" style="font-size:.72rem" onclick="document.getElementById('file_photo').click()">
                <i class="bi bi-upload me-1"></i>Choisir
              </button>
              <button type="button" id="btn_recadrer" class="btn btn-outline-primary btn-sm" style="font-size:.72rem;display:none" onclick="ouvrirCrop()">
                <i class="bi bi-crop me-1"></i>Recadrer
              </button>
            </div>
            <div class="text-muted text-center" style="font-size:.68rem">JPG · PNG · WEBP — max 2 Mo</div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="mt-3 d-flex gap-2">
    <button class="btn btn-primary btn-sm px-4"><i class="bi bi-check-lg me-1"></i>Enregistrer</button>
    <a href="<?= APP_URL ?>/pages/eleves/liste.php" class="btn btn-light btn-sm">Annuler</a>
  </div>
</form>

<div class="crop-overlay" id="cropOverlay">
  <div class="crop-box">
    <div class="crop-head">
      <span><i class="bi bi-crop me-1"></i>Recadrer la photo</span>
      <button type="button" onclick="fermerCrop()" style="background:none;border:none;color:#fff;font-size:1rem;cursor:pointer">
        <i class="bi bi-x-lg"></i>
      </button>
    </div>
    <div style="background:#222;overflow:hidden">
      <img id="cropImg" src="" class="crop-canvas">
    </div>
    <div class="crop-foot">
      <button type="button" class="btn btn-sm btn-outline-secondary" onclick="cropper.rotate(-90)"><i class="bi bi-arrow-counterclockwise"></i></button>
      <button type="button" class="btn btn-sm btn-outline-secondary" onclick="cropper.rotate(90)"><i class="bi bi-arrow-clockwise"></i></button>
      <button type="button" class="btn btn-sm btn-outline-secondary" onclick="cropper.zoom(0.1)"><i class="bi bi-zoom-in"></i></button>
      <button type="button" class="btn btn-sm btn-outline-secondary" onclick="cropper.zoom(-0.1)"><i class="bi bi-zoom-out"></i></button>
      <button type="button" class="btn btn-sm btn-outline-secondary" onclick="cropper.reset()"><i class="bi bi-arrow-repeat"></i></button>
      <button type="button" class="btn btn-sm btn-primary ms-auto" onclick="validerCrop()"><i class="bi bi-check-lg me-1"></i>Valider</button>
      <button type="button" class="btn btn-sm btn-light" onclick="fermerCrop()">Annuler</button>
    </div>
  </div>
</div>

<script src="<?= APP_URL ?>/assets/vendor/cropper/cropper.min.js"></script>
<script>
// var (pas let) : ce script est réexécuté à chaque rechargement AJAX de la
// zone en cas d'erreur de validation (voir injecterHtmlDansZone(),
// layout/footer.php) — une redéclaration via let lèverait une erreur au 2e
// rechargement.
var cropper = null, rawSrc = null;
function onFichierChoisi(e) {
  const file = e.target.files[0];
  if (!file) return;
  const reader = new FileReader();
  reader.onload = ev => {
    rawSrc = ev.target.result;
    const prev = document.getElementById('preview');
    const ph   = document.getElementById('placeholder');
    prev.src = rawSrc; prev.style.display = 'block';
    if (ph) ph.style.display = 'none';
    document.getElementById('btn_recadrer').style.display = 'inline-flex';
    ouvrirCrop();
  };
  reader.readAsDataURL(file);
}
function ouvrirCrop() {
  if (!rawSrc) return;
  document.getElementById('cropImg').src = rawSrc;
  document.getElementById('cropOverlay').classList.add('show');
  if (cropper) { cropper.destroy(); cropper = null; }
  cropper = new Cropper(document.getElementById('cropImg'), {
    aspectRatio: 3/4, viewMode: 1, dragMode: 'move', background: false, autoCropArea: 0.9
  });
}
function fermerCrop() { document.getElementById('cropOverlay').classList.remove('show'); }
function validerCrop() {
  const dataUrl = cropper.getCroppedCanvas({width:300,height:400}).toDataURL('image/jpeg', 0.88);
  document.getElementById('preview').src = dataUrl;
  document.getElementById('file_photo').value = '';
  // Photo recadrée envoyée comme vrai FICHIER (champ photo_fichier) et non
  // comme long texte base64 : le pare-feu de l'hébergeur (Camoo) bloquait
  // le formulaire en 403. Base64 seulement si le navigateur ne sait pas
  // remplir un champ fichier (DataTransfer absent).
  try {
    const [entete, b64] = dataUrl.split(',');
    const bin = atob(b64); const buf = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
    const dt = new DataTransfer();
    dt.items.add(new File([buf], 'photo.jpg', { type: 'image/jpeg' }));
    document.getElementById('photo_fichier').files = dt.files;
    document.getElementById('photo_b64').value = '';
  } catch (e) {
    document.getElementById('photo_b64').value = dataUrl;
  }
  fermerCrop();
}
document.getElementById('cropOverlay').addEventListener('click', e => { if (e.target === e.currentTarget) fermerCrop(); });
</script>

<script src="<?= APP_URL ?>/assets/js/lieu-cascade.js"></script>
<script>
initLieuCascade({
    selRegion:         document.getElementById('sel_region'),
    selDepartement:    document.getElementById('sel_departement'),
    selArrondissement: document.getElementById('sel_arrondissement'),
    inputLibre:        document.getElementById('inp_lieu_libre'),
    appUrl:            '<?= APP_URL ?>',
    valeurs: {
        id_region:         <?= json_encode($lieu_edit['id_region'] ?? null) ?>,
        id_departement:    <?= json_encode($lieu_edit['code_depart'] ?? null) ?>,
        id_arrondissement: <?= json_encode($eleve['id_arrondissement'] ?? null) ?>,
        texte_libre:       <?= json_encode($ve('arrondissement_elv')) ?>
    }
});
</script>

</div><!-- /#eleve-form-zone -->
<?php if ($es_partiel) exit; // rien de plus dans une réponse AJAX partielle. ?>

<?php
$ajax_zone_id = 'eleve-form-zone'; // voir layout/footer.php — initAjaxZone() y est appelé
require_once __DIR__ . '/../../layout/footer.php';
