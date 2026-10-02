<?php
// pages/eleves/photos_seance.php — Séance photo au TÉLÉPHONE pour une classe,
// primaire ET secondaire (secondaire/pages/eleves/photos_seance.php inclut ce
// fichier ; stockage selon le type d'école, voir _photos_lib.php). Ouverte
// via le QR code de l'onglet « Photos par classe » de la liste des élèves.
// Page autonome pensée pour un petit écran : les élèves défilent un par un
// (Précédent / Suivant, balayage du doigt, saut direct vers un élève),
// l'appareil photo s'ouvre, on ajuste le cadre, la photo est envoyée
// (photo_enregistrer.php) et on passe automatiquement au suivant. La photo
// d'un élève peut aussi être supprimée.
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/_photos_lib.php';
session_init();

// Téléphone pas encore connecté : après la connexion, login.php ramène ici
// (et présélectionne l'école du QR code) au lieu du tableau de bord.
if (!est_connecte() && !(function_exists('est_visite_association') && est_visite_association())) {
    $_SESSION['retour_apres_login'] = $_SERVER['REQUEST_URI'] ?? '';
    if (!empty($_GET['ecole'])) $_SESSION['ecole_suggeree'] = (string) $_GET['ecole'];
}
exiger_role(photos_roles());

$id_classe     = (int) ($_GET['classe'] ?? 0);
$lecture_seule = function_exists('est_lecture_seule') && est_lecture_seule();
$base          = photos_base_url();

// QR code généré pour une autre école que celle de la session en cours.
$ecole_qr    = (string) ($_GET['ecole'] ?? '');
$ecole_sess  = function_exists('ecole_courante') ? (string) (ecole_courante()['code'] ?? '') : '';
$autre_ecole = $ecole_qr !== '' && $ecole_sess !== '' && $ecole_qr !== $ecole_sess;

$classes = photos_classes();
$classe  = $id_classe ? photos_classe($id_classe) : null;
$donnees = $classe ? photos_eleves_classe($id_classe) : [];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title>Séance photo — <?= h($classe['nom'] ?? APP_NOM) ?></title>
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/bootstrap/css/bootstrap.min.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/cropper/cropper.min.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
  <style>
    body { background:#f3f5fb; }
    .barre { background:#0f1a3a; color:#fff; position:sticky; top:0; z-index:10; }
    .photo-actuelle { width:min(62vw,240px); aspect-ratio:3/4; object-fit:cover; border-radius:12px; border:3px solid #fff; box-shadow:0 6px 20px rgba(0,0,0,.15); background:#e5e7eb; touch-action:pan-y; }
    .btn-xl { padding:.9rem 1rem; font-size:1.05rem; font-weight:600; border-radius:12px; }
    .btn-nav { padding:.7rem .5rem; font-weight:600; border-radius:10px; }
    .vignettes { display:grid; grid-template-columns:repeat(auto-fill, minmax(62px,1fr)); gap:6px; }
    .vignettes button { border:2px solid transparent; padding:0; border-radius:6px; overflow:hidden; background:#fff; position:relative; }
    .vignettes button.courant { border-color:#1e4fd8; }
    .vignettes img { width:100%; aspect-ratio:3/4; object-fit:cover; display:block; }
    .vignettes .sans::after, .vignettes .fait::after { content:''; position:absolute; top:3px; right:3px; width:9px; height:9px; border-radius:50%; border:1px solid #fff; }
    .vignettes .sans::after { background:#f0ad4e; }
    .vignettes .fait::after { background:#198754; }
  </style>
</head>
<body>

<div class="barre px-3 py-2 d-flex align-items-center gap-2">
  <i class="bi bi-camera fs-5"></i>
  <div class="me-auto lh-sm">
    <div class="fw-bold" style="font-size:.95rem">Séance photo<?= $classe ? ' — ' . h($classe['nom']) : '' ?></div>
    <div style="font-size:.75rem;opacity:.8" id="progression"></div>
  </div>
  <a href="<?= $base ?>/liste.php?statut=photos&classe=<?= $id_classe ?>" class="btn btn-sm btn-outline-light" title="Retour à l'application"><i class="bi bi-x-lg"></i></a>
</div>

<div class="container py-3" style="max-width:560px">

  <?php if ($autre_ecole): ?>
    <div class="alert alert-danger py-2" style="font-size:.85rem">
      <i class="bi bi-exclamation-octagon me-1"></i>Ce QR code a été généré pour une <strong>autre école</strong> que celle
      à laquelle ce téléphone est connecté. Déconnectez-vous et reconnectez-vous à la bonne école.
    </div>
  <?php endif; ?>

  <?php if ($lecture_seule): ?>
    <div class="alert alert-warning py-2" style="font-size:.85rem">Votre profil est en consultation seule : l'enregistrement des photos n'est pas possible.</div>
  <?php endif; ?>

  <form method="get" class="mb-3">
    <select name="classe" class="form-select" onchange="this.form.submit()">
      <option value="">— Choisir une classe —</option>
      <?php foreach ($classes as $c): ?>
        <option value="<?= (int) $c['id'] ?>" <?= $id_classe === (int) $c['id'] ? 'selected' : '' ?>><?= h($c['nom']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php if ($ecole_qr !== ''): ?><input type="hidden" name="ecole" value="<?= h($ecole_qr) ?>"><?php endif; ?>
  </form>

  <?php if (!$classe): ?>
    <p class="text-muted text-center">Choisissez la classe à photographier.</p>
  <?php elseif (!$donnees): ?>
    <p class="text-muted text-center">Aucun élève actif inscrit dans cette classe cette année.</p>
  <?php else: ?>

  <div class="form-check form-switch mb-2">
    <input class="form-check-input" type="checkbox" id="seulSans" onchange="changerFiltre()">
    <label class="form-check-label" for="seulSans">Seulement les élèves sans photo</label>
  </div>

  <select id="allerA" class="form-select form-select-sm mb-3" onchange="if (this.value) allerA(parseInt(this.value, 10))" aria-label="Aller directement à un élève"></select>

  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body text-center d-none py-4" id="termine">
      <i class="bi bi-check-circle text-success" style="font-size:3rem"></i>
      <div class="fw-bold mt-2">Tous les élèves ont une photo.</div>
      <div class="text-muted" style="font-size:.85rem">Décochez le filtre pour en remplacer ou en supprimer une.</div>
    </div>
    <div class="card-body text-center" id="contenuEleve">
      <div class="text-muted mb-1" style="font-size:.8rem" id="position"></div>
      <img id="photoActuelle" class="photo-actuelle mb-2" alt="">
      <div class="fw-bold fs-5 lh-sm" id="nomEleve"></div>
      <div class="text-muted mb-3" style="font-size:.85rem" id="matEleve"></div>
      <div class="d-grid gap-2">
        <div class="d-flex gap-2">
          <button type="button" class="btn btn-outline-primary btn-nav flex-fill" onclick="aller(-1)"><i class="bi bi-chevron-left"></i> Précédent</button>
          <button type="button" class="btn btn-outline-primary btn-nav flex-fill" onclick="aller(1)">Suivant <i class="bi bi-chevron-right"></i></button>
        </div>
        <label class="btn btn-primary btn-xl mb-0 <?= $lecture_seule ? 'disabled' : '' ?>">
          <i class="bi bi-camera-fill me-1"></i>Prendre la photo
          <input type="file" accept="image/*" capture="environment" class="d-none" onchange="photoChoisie(this)" <?= $lecture_seule ? 'disabled' : '' ?>>
        </label>
        <div class="d-flex gap-2">
          <label class="btn btn-outline-secondary flex-fill mb-0 <?= $lecture_seule ? 'disabled' : '' ?>">
            <i class="bi bi-image me-1"></i>Galerie
            <input type="file" accept="image/*" class="d-none" onchange="photoChoisie(this)" <?= $lecture_seule ? 'disabled' : '' ?>>
          </label>
          <button type="button" class="btn btn-outline-danger flex-fill" id="btnSuppr" onclick="supprimerPhoto()" <?= $lecture_seule ? 'disabled' : '' ?>>
            <i class="bi bi-trash me-1"></i>Supprimer la photo
          </button>
        </div>
      </div>
      <div class="mt-2" style="font-size:.85rem;min-height:1.3em" id="statut"></div>
      <div class="text-muted mt-1" style="font-size:.72rem">Astuce : balayez la photo vers la gauche ou la droite pour changer d'élève.</div>
    </div>
  </div>

  <div class="fw-semibold mb-1" style="font-size:.85rem">Tous les élèves <span class="text-muted fw-normal">(touchez pour y aller)</span></div>
  <div class="vignettes" id="vignettes"></div>

  <?php endif; ?>
</div>

<?php if ($donnees): ?>
<script src="<?= APP_URL ?>/assets/vendor/cropper/cropper.min.js"></script>
<script src="<?= APP_URL ?>/assets/js/photos-eleves.js?v=<?= (int) @filemtime(__DIR__ . '/../../assets/js/photos-eleves.js') ?>"></script>
<script>
const API    = <?= json_encode($base . '/photo_enregistrer.php') ?>;
const CSRF   = <?= json_encode(csrf_generer()) ?>;
const ELEVES = <?= json_encode($donnees, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
let courant = 0, occupe = false;

// Filtre « sans photo » : les élèves traités pendant cette séance restent
// dans la liste (sinon la position sauterait après chaque photo).
function liste() { return document.getElementById('seulSans').checked ? ELEVES.filter(e => !e.photo || e.faitIci) : ELEVES; }
function echapper(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
function eleveCourant() { return liste()[courant] || null; }

function afficher() {
  const l = liste(), n = ELEVES.length, a = ELEVES.filter(e => e.photo).length;
  document.getElementById('progression').textContent = a + ' / ' + n + ' élève(s) avec photo';
  document.getElementById('termine').classList.toggle('d-none', l.length > 0);
  document.getElementById('contenuEleve').classList.toggle('d-none', !l.length);
  if (l.length) {
    courant = Math.max(0, Math.min(courant, l.length - 1));
    const e = l[courant];
    document.getElementById('position').textContent = 'Élève ' + (courant + 1) + ' / ' + l.length;
    document.getElementById('photoActuelle').src = e.url;
    document.getElementById('nomEleve').textContent = e.nom;
    document.getElementById('matEleve').textContent = e.mat || 'sans matricule';
    document.getElementById('btnSuppr').classList.toggle('d-none', !e.photo);
  }
  rendreListeSaut();
  rendreVignettes();
}
function rendreListeSaut() {
  const e = eleveCourant();
  document.getElementById('allerA').innerHTML = '<option value="">Aller directement à un élève…</option>' +
    ELEVES.map((x, i) => '<option value="' + x.id + '"' + (e && x.id === e.id ? ' selected' : '') + '>' +
      (i + 1) + '. ' + echapper(x.nom) + (x.photo ? ' ✓' : '') + '</option>').join('');
}
function rendreVignettes() {
  const e = eleveCourant();
  document.getElementById('vignettes').innerHTML = ELEVES.map(x =>
    '<button type="button" class="' + (e && x.id === e.id ? 'courant ' : '') + (x.photo ? (x.faitIci ? 'fait' : '') : 'sans') + '" onclick="allerA(' + x.id + ')" title="' + echapper(x.nom) + '">' +
    '<img src="' + x.url + '" alt="" loading="lazy"></button>').join('');
}
function haut() { window.scrollTo({ top: 0, behavior: 'smooth' }); }
function aller(d) {
  const l = liste(); if (!l.length || occupe) return;
  courant = (courant + d + l.length) % l.length; statut(''); afficher(); haut();
}
function allerA(id) {
  if (occupe) return;
  // Élève déjà photographié hors séance : on retire le filtre pour l'afficher.
  if (!liste().some(e => e.id === id)) document.getElementById('seulSans').checked = false;
  courant = Math.max(0, liste().findIndex(e => e.id === id)); statut(''); afficher(); haut();
}
function changerFiltre() { const e = eleveCourant(); courant = 0; if (e) { const i = liste().findIndex(x => x.id === e.id); if (i >= 0) courant = i; } afficher(); }
function statut(html) { document.getElementById('statut').innerHTML = html; }

// Balayage horizontal sur la photo = élève précédent / suivant.
(function () {
  const img = document.getElementById('photoActuelle');
  let x0 = null, y0 = null;
  img.addEventListener('touchstart', ev => { x0 = ev.touches[0].clientX; y0 = ev.touches[0].clientY; }, { passive: true });
  img.addEventListener('touchend', ev => {
    if (x0 === null) return;
    const dx = ev.changedTouches[0].clientX - x0, dy = ev.changedTouches[0].clientY - y0;
    x0 = null;
    if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy) * 1.5) aller(dx < 0 ? 1 : -1);
  });
})();

async function photoChoisie(input) {
  const f = input.files[0]; input.value = '';
  const e = eleveCourant();
  if (!f || !e || occupe) return;
  if (e.photo && !e.faitIci && !confirm('Remplacer la photo actuelle de ' + e.nom + ' ?')) return;
  statut('<span class="text-muted"><span class="spinner-border spinner-border-sm"></span> Préparation…</span>');
  let src;
  try { src = PhotosEleves.reduire(await PhotosEleves.chargerImage(f), 1600).toDataURL('image/jpeg', 0.92); }
  catch (err) { statut('<span class="text-danger">' + echapper(err.message) + '</span>'); return; }
  statut('');
  PhotosEleves.recadrer(src, async url => {
    occupe = true;
    document.getElementById('photoActuelle').src = url;
    statut('<span class="text-primary"><span class="spinner-border spinner-border-sm"></span> Envoi…</span>');
    const r = await PhotosEleves.envoyer(API, CSRF, e.id, url);
    occupe = false;
    if (!r.ok) { document.getElementById('photoActuelle').src = e.url; statut('<span class="text-danger"><i class="bi bi-x-circle"></i> ' + echapper(r.message) + '</span>'); return; }
    e.photo = true; e.faitIci = true; e.url = r.url;
    statut('<span class="text-success"><i class="bi bi-check-circle"></i> Photo de ' + echapper(e.nom) + ' enregistrée</span>');
    // Élève suivant automatiquement (sauf si c'était le dernier).
    setTimeout(() => {
      const l = liste(), i = l.findIndex(x => x.id === e.id);
      if (i + 1 < l.length) { courant = i + 1; statut(''); }
      afficher(); haut();
    }, 700);
  }, e.nom);
}

async function supprimerPhoto() {
  const e = eleveCourant();
  if (!e || !e.photo || occupe) return;
  if (!confirm('Supprimer la photo de ' + e.nom + ' ?')) return;
  occupe = true;
  statut('<span class="text-muted"><span class="spinner-border spinner-border-sm"></span> Suppression…</span>');
  const r = await PhotosEleves.supprimer(API, CSRF, e.id);
  occupe = false;
  if (!r.ok) { statut('<span class="text-danger"><i class="bi bi-x-circle"></i> ' + echapper(r.message) + '</span>'); return; }
  e.photo = false; e.faitIci = true; e.url = r.url;
  statut('<span class="text-success"><i class="bi bi-check-circle"></i> Photo supprimée</span>');
  afficher();
}
afficher();
</script>
<?php endif; ?>
</body>
</html>
