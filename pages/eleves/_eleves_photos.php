<?php
// Fragment : onglet « Photos par classe » de la liste des élèves — primaire
// (pages/eleves/_liste_resultats.php, rechargé en AJAX) ET secondaire
// (secondaire/pages/eleves/liste.php). AUCUN <script> exécutable ici
// (innerHTML ne les exécute pas) : tout le JavaScript est dans
// _photos_outils.php, qui lit les données de la grille dans le bloc JSON
// #photosData ci-dessous.
// Façons d'ajouter les photos d'une classe :
//   1. clic sur un élève (photo unitaire) — corbeille pour la supprimer ;
//   2. séance photo au téléphone (photos_seance.php, via QR code) ;
//   3. import en lot (appareil numérique / dossier : association par
//      matricule ou nom dans le nom du fichier, sinon par ordre) ;
//   4. planche scannée (photos 4x4 collées en grille sur une feuille).
// Variables attendues : $id_classe, $peut_gerer.
require_once __DIR__ . '/_photos_lib.php';
if (empty($peut_gerer)) { echo '<div class="text-muted small py-3">Accès réservé.</div>'; return; }

if (!$id_classe) { ?>
  <div class="card"><div class="card-body text-center text-muted py-5">
    <i class="bi bi-camera" style="font-size:2.2rem;opacity:.35;display:block;margin-bottom:.5rem"></i>
    Choisissez une <strong>classe</strong> dans le filtre ci-dessus pour gérer les photos de ses élèves.
  </div></div>
<?php return; }

$classe_ph = photos_classe($id_classe);
$eleves_ph = photos_eleves_classe($id_classe);
$nb_ph   = count($eleves_ph);
$nb_avec = count(array_filter($eleves_ph, fn($e) => $e['photo']));
$nb_sans_mat = count(array_filter($eleves_ph, fn($e) => trim($e['mat']) === ''));

// Adresse absolue de la séance photo pour le QR code : sur « localhost »,
// hote_verif_reseau() donne l'IP réseau du PC (un téléphone ne peut pas
// joindre « localhost ») ; en ligne, l'hôte réel.
$https      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$code_ecole = function_exists('ecole_courante') ? (ecole_courante()['code'] ?? '') : '';
$url_seance = ($https ? 'https' : 'http') . '://' . hote_verif_reseau() . photos_base_url()
            . '/photos_seance.php?classe=' . $id_classe . ($code_ecole !== '' ? '&ecole=' . urlencode($code_ecole) : '');
?>
<script type="application/json" id="photosData"><?= json_encode(['classe' => $classe_ph, 'eleves' => $eleves_ph], JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

<div class="card mb-2">
  <div class="card-body py-2">
    <div class="d-flex flex-wrap align-items-center gap-2">
      <div class="me-auto">
        <div class="fw-bold" style="font-size:.92rem"><i class="bi bi-camera me-1 text-primary"></i>Photos — <?= h($classe_ph['nom'] ?? '') ?></div>
        <div style="font-size:.78rem">
          <span id="phCompteur" class="fw-semibold"><?= $nb_avec ?>/<?= $nb_ph ?></span> élève(s) avec photo
          <div class="progress d-inline-flex align-middle ms-1" style="width:110px;height:6px">
            <div class="progress-bar bg-success" id="phBarre" style="width:<?= $nb_ph ? round($nb_avec / $nb_ph * 100) : 0 ?>%"></div>
          </div>
        </div>
      </div>
      <div class="form-check form-switch mb-0 me-2">
        <input class="form-check-input" type="checkbox" id="phSansPhoto" onchange="photosFiltrer()">
        <label class="form-check-label" for="phSansPhoto" style="font-size:.8rem">Seulement sans photo</label>
      </div>
      <button type="button" class="btn btn-primary btn-sm" onclick="photosOuvrirSeance()">
        <i class="bi bi-phone me-1"></i>Séance photo (téléphone)
      </button>
      <button type="button" class="btn btn-outline-primary btn-sm" onclick="document.getElementById('phFichiersLot').click()">
        <i class="bi bi-images me-1"></i>Importer des photos
      </button>
      <button type="button" class="btn btn-outline-secondary btn-sm" onclick="document.getElementById('phFichierPlanche').click()">
        <i class="bi bi-grid-3x3 me-1"></i>Planche scannée
      </button>
      <input type="file" id="phFichiersLot" accept="image/*" multiple class="d-none" onchange="photosImporterLot(this)">
      <input type="file" id="phFichierPlanche" accept="image/*" class="d-none" onchange="photosOuvrirPlanche(this)">
      <input type="file" id="phFichierUnique" accept="image/*" class="d-none" onchange="photosFichierUnique(this)">
    </div>
    <?php if ($nb_sans_mat): ?>
      <div class="alert alert-warning py-1 px-2 mt-2 mb-0" style="font-size:.76rem">
        <i class="bi bi-exclamation-triangle me-1"></i><?= $nb_sans_mat ?> élève(s) sans matricule : leurs photos ne pourront pas être
        associées automatiquement par nom de fichier lors d'un import (association par ordre ou manuelle uniquement).
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if (!$eleves_ph): ?>
  <div class="card"><div class="card-body text-center text-muted py-4">Aucun élève actif inscrit dans cette classe cette année.</div></div>
<?php else: ?>
<div class="row g-2" id="phGrille">
  <?php foreach ($eleves_ph as $e): ?>
  <div class="col-6 col-sm-4 col-md-3 col-lg-2 ph-carte-col" data-id="<?= $e['id'] ?>" data-photo="<?= $e['photo'] ? 1 : 0 ?>">
    <div class="card h-100 ph-carte <?= $e['photo'] ? '' : 'ph-sans' ?>" role="button" onclick="photosChoisirPour(<?= $e['id'] ?>)"
         title="Cliquer pour ajouter / remplacer la photo">
      <div class="ph-cadre">
        <img src="<?= h($e['url']) ?>" alt="" loading="lazy" id="phImg<?= $e['id'] ?>">
        <span class="ph-badge badge <?= $e['photo'] ? 'bg-success' : 'bg-warning text-dark' ?>" id="phBadge<?= $e['id'] ?>">
          <?= $e['photo'] ? '<i class="bi bi-check-lg"></i>' : 'Sans photo' ?>
        </span>
        <button type="button" class="ph-suppr btn btn-sm btn-light text-danger <?= $e['photo'] ? '' : 'd-none' ?>" id="phSuppr<?= $e['id'] ?>"
                title="Supprimer la photo" onclick="event.stopPropagation(); photosSupprimer(<?= $e['id'] ?>)">
          <i class="bi bi-trash"></i>
        </button>
      </div>
      <div class="card-body p-1 text-center">
        <div class="fw-semibold text-truncate" style="font-size:.74rem" title="<?= h($e['nom']) ?>"><?= h($e['nom']) ?></div>
        <div class="text-muted" style="font-size:.66rem"><?= $e['mat'] !== '' ? h($e['mat']) : '<span class="text-danger">sans matricule</span>' ?></div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- QR code de la séance photo — dans le fragment (URL propre à la classe). -->
<div class="d-none" id="phSeanceInfos" data-url="<?= h($url_seance) ?>"
     data-qr="<?= h(APP_URL . '/pdf/qrcode.php?s=6&e=M&d=' . urlencode($url_seance)) ?>"></div>
