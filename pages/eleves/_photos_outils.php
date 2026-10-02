<?php
// pages/eleves/_photos_outils.php — fenêtres (revue, planche scannée, QR de
// la séance photo), styles et JavaScript de l'onglet « Photos par classe ».
// Inclus UNE fois par la page liste (primaire : pages/eleves/liste.php ;
// secondaire : secondaire/pages/eleves/liste.php), jamais par le fragment
// _eleves_photos.php (rechargé en AJAX au primaire : ses <script> ne
// s'exécuteraient pas). Requiert $peut_gerer (déjà vérifié par l'appelant).
require_once __DIR__ . '/_photos_lib.php';
?>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/cropper/cropper.min.css">
<style>
  .ph-carte { cursor:pointer; transition:transform .12s, box-shadow .12s; overflow:hidden; }
  .ph-carte:hover { transform:translateY(-2px); box-shadow:0 4px 14px rgba(0,0,0,.12); }
  .ph-carte.ph-sans { border:1px dashed #f0ad4e; }
  .ph-cadre { position:relative; aspect-ratio:3/4; background:#f3f4f6; }
  .ph-cadre img { width:100%; height:100%; object-fit:cover; display:block; }
  .ph-badge { position:absolute; top:4px; right:4px; font-size:.62rem; }
  .ph-suppr { position:absolute; bottom:4px; right:4px; padding:1px 6px; font-size:.75rem; opacity:.85; }
  .ph-suppr:hover { opacity:1; }
  .ph-carte.ph-envoi .ph-cadre::after { content:''; position:absolute; inset:0; background:rgba(255,255,255,.6) url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 50 50"><circle cx="25" cy="25" r="20" fill="none" stroke="%231e4fd8" stroke-width="5" stroke-dasharray="90 60"><animateTransform attributeName="transform" type="rotate" from="0 25 25" to="360 25 25" dur="0.8s" repeatCount="indefinite"/></circle></svg>') center no-repeat; }
  .ph-revue-mini { width:54px; height:72px; object-fit:cover; border-radius:4px; border:1px solid #ddd; }
  #phPlancheCanvas { max-width:100%; border:1px solid #ddd; background:#fafafa; }
</style>

<div class="modal fade" id="phModalRevue" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title fw-bold"><i class="bi bi-list-check me-1 text-primary"></i><span id="phRevueTitre">Vérifier les associations</span></h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body py-2">
        <div class="text-muted mb-2" style="font-size:.78rem" id="phRevueAide"></div>
        <table class="table table-sm align-middle mb-0">
          <thead><tr><th style="width:70px">Photo</th><th>Source</th><th style="width:45%">Élève</th><th style="width:40px"></th></tr></thead>
          <tbody id="phRevueCorps"></tbody>
        </table>
      </div>
      <div class="modal-footer py-2">
        <span class="me-auto text-muted" style="font-size:.78rem" id="phRevueEtat"></span>
        <button class="btn btn-primary btn-sm" id="phRevueBtn" onclick="photosEnregistrerRevue()">
          <i class="bi bi-cloud-upload me-1"></i>Enregistrer les photos
        </button>
        <button class="btn btn-light btn-sm" data-bs-dismiss="modal">Fermer</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="phModalPlanche" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title fw-bold"><i class="bi bi-grid-3x3 me-1 text-primary"></i>Planche scannée — découpage</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body py-2">
        <div class="row g-3">
          <div class="col-lg-8 text-center"><canvas id="phPlancheCanvas"></canvas></div>
          <div class="col-lg-4" style="font-size:.8rem">
            <p class="text-muted mb-2">Réglez la grille pour que chaque case (en bleu) entoure une photo. Les photos sont
              lues <strong>ligne par ligne, de gauche à droite</strong>, et associées aux élèves dans l'ordre de la liste.
              Les cases vides sont ignorées.</p>
            <div class="row g-2">
              <div class="col-6"><label class="form-label mb-0">Colonnes</label><input type="number" min="1" max="12" value="4" class="form-control form-control-sm ph-pl" id="phPlCol"></div>
              <div class="col-6"><label class="form-label mb-0">Lignes</label><input type="number" min="1" max="15" value="5" class="form-control form-control-sm ph-pl" id="phPlLig"></div>
              <div class="col-6"><label class="form-label mb-0">Marge haut %</label><input type="number" min="0" max="40" step="0.5" value="2" class="form-control form-control-sm ph-pl" id="phPlMh"></div>
              <div class="col-6"><label class="form-label mb-0">Marge bas %</label><input type="number" min="0" max="40" step="0.5" value="2" class="form-control form-control-sm ph-pl" id="phPlMb"></div>
              <div class="col-6"><label class="form-label mb-0">Marge gauche %</label><input type="number" min="0" max="40" step="0.5" value="2" class="form-control form-control-sm ph-pl" id="phPlMg"></div>
              <div class="col-6"><label class="form-label mb-0">Marge droite %</label><input type="number" min="0" max="40" step="0.5" value="2" class="form-control form-control-sm ph-pl" id="phPlMd"></div>
              <div class="col-12"><label class="form-label mb-0">Retrait dans chaque case %</label><input type="number" min="0" max="30" step="0.5" value="4" class="form-control form-control-sm ph-pl" id="phPlRet"></div>
            </div>
            <div class="form-text">Astuce : chaque photo sera ensuite ajustable une à une (bouton <i class="bi bi-crop"></i>) avant l'enregistrement.</div>
          </div>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button class="btn btn-primary btn-sm" onclick="photosDecouperPlanche()"><i class="bi bi-scissors me-1"></i>Découper et vérifier</button>
        <button class="btn btn-light btn-sm" data-bs-dismiss="modal">Annuler</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="phModalSeance" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title fw-bold"><i class="bi bi-phone me-1 text-primary"></i>Séance photo au téléphone</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body text-center">
        <p class="mb-2" style="font-size:.85rem">Scannez ce QR code avec le téléphone qui prendra les photos.
          Connectez-vous si demandé : les élèves de la classe défilent un par un, l'appareil photo s'ouvre, on valide et on passe au suivant.</p>
        <img id="phSeanceQr" src="" alt="QR code" width="200" height="200" class="border rounded p-1 bg-white">
        <div class="mt-2"><code id="phSeanceUrl" style="font-size:.72rem;word-break:break-all"></code></div>
        <div class="text-muted mt-2" style="font-size:.74rem">Le téléphone doit pouvoir joindre cette adresse
          (même réseau Wi-Fi que ce PC si l'application tourne en local).</div>
      </div>
      <div class="modal-footer py-2">
        <a id="phSeanceLien" href="#" target="_blank" class="btn btn-outline-primary btn-sm"><i class="bi bi-box-arrow-up-right me-1"></i>Ouvrir sur cet appareil</a>
        <button class="btn btn-light btn-sm" data-bs-dismiss="modal">Fermer</button>
      </div>
    </div>
  </div>
</div>

<script src="<?= APP_URL ?>/assets/vendor/cropper/cropper.min.js"></script>
<script src="<?= APP_URL ?>/assets/js/photos-eleves.js?v=<?= (int) @filemtime(__DIR__ . '/../../assets/js/photos-eleves.js') ?>"></script>
<script>
// ═══ Onglet « Photos par classe » ═══════════════════════════════════
// Les données de la grille viennent du bloc JSON #photosData du fragment
// (rechargé en AJAX à chaque changement de classe) — relu dès qu'il change.
const PH_API  = <?= json_encode(photos_base_url() . '/photo_enregistrer.php') ?>;
const PH_CSRF = <?= json_encode(csrf_generer()) ?>;
let phCache = { el: null, data: null };
function phDonnees() {
  const el = document.getElementById('photosData');
  if (!el) return null;
  if (phCache.el !== el) phCache = { el, data: JSON.parse(el.textContent) };
  return phCache.data;
}
function phEleve(id) { const d = phDonnees(); return d ? d.eleves.find(e => e.id === id) : null; }
function phEchapper(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

function photosFiltrer() {
  const seul = document.getElementById('phSansPhoto')?.checked;
  document.querySelectorAll('.ph-carte-col').forEach(c => {
    c.classList.toggle('d-none', !!seul && c.dataset.photo === '1');
  });
}
function phMajCompteur() {
  const d = phDonnees(); if (!d) return;
  const n = d.eleves.length, a = d.eleves.filter(e => e.photo).length;
  const cpt = document.getElementById('phCompteur'), bar = document.getElementById('phBarre');
  if (cpt) cpt.textContent = a + '/' + n;
  if (bar) bar.style.width = (n ? Math.round(a / n * 100) : 0) + '%';
}
function phMarquerCarte(id, etat, url) {
  const col = document.querySelector('.ph-carte-col[data-id="' + id + '"]');
  if (!col) return;
  const carte = col.querySelector('.ph-carte');
  carte.classList.toggle('ph-envoi', etat === 'envoi');
  if (etat === 'ok' || etat === 'supprime') {
    const avec = etat === 'ok';
    const e = phEleve(id); if (e) { e.photo = avec; e.url = url; }
    col.dataset.photo = avec ? '1' : '0';
    carte.classList.toggle('ph-sans', !avec);
    document.getElementById('phImg' + id).src = url;
    document.getElementById('phSuppr' + id).classList.toggle('d-none', !avec);
    const b = document.getElementById('phBadge' + id);
    b.className = 'ph-badge badge ' + (avec ? 'bg-success' : 'bg-warning text-dark');
    b.innerHTML = avec ? '<i class="bi bi-check-lg"></i>' : 'Sans photo';
    phMajCompteur();
  }
}
// Suppression de la photo d'un élève (corbeille sur sa carte).
async function photosSupprimer(id) {
  const e = phEleve(id); if (!e) return;
  if (!confirm('Supprimer la photo de ' + e.nom + ' ?')) return;
  phMarquerCarte(id, 'envoi');
  const r = await PhotosEleves.supprimer(PH_API, PH_CSRF, id);
  phMarquerCarte(id, r.ok ? 'supprime' : 'fin', r.url);
  if (!r.ok) alert('Photo non supprimée : ' + r.message);
}
async function phEnvoyer(id, dataUrl) {
  phMarquerCarte(id, 'envoi');
  const r = await PhotosEleves.envoyer(PH_API, PH_CSRF, id, dataUrl);
  phMarquerCarte(id, r.ok ? 'ok' : 'fin', r.url);
  return r;
}

// ── 1. Photo d'UN élève (clic sur sa carte) ─────────────────────────
let phCibleUnique = null;
function photosChoisirPour(id) {
  const e = phEleve(id); if (!e) return;
  if (e.photo && !confirm('Remplacer la photo actuelle de ' + e.nom + ' ?')) return;
  phCibleUnique = id;
  document.getElementById('phFichierUnique').click();
}
async function photosFichierUnique(input) {
  const f = input.files[0]; input.value = '';
  if (!f || !phCibleUnique) return;
  const id = phCibleUnique, e = phEleve(id);
  try {
    const src = PhotosEleves.reduire(await PhotosEleves.chargerImage(f), 1600).toDataURL('image/jpeg', 0.92);
    PhotosEleves.recadrer(src, async url => {
      const r = await phEnvoyer(id, url);
      if (!r.ok) alert('Photo non enregistrée : ' + r.message);
    }, e ? e.nom : '');
  } catch (err) { alert(err.message); }
}

// ── Revue commune (import en lot / planche) ─────────────────────────
// Élément : { src (base du recadrage manuel), data (300×400), libelle,
//             id (élève ou 0 = ignorer), methode, vide }
let phRevue = [];
function phOrdreCibles(exclus) {
  const d = phDonnees(), seul = document.getElementById('phSansPhoto')?.checked;
  return d.eleves.filter(e => !exclus.has(e.id) && (!seul || !e.photo));
}
function phOuvrirRevue(titre, aide) {
  document.getElementById('phRevueTitre').textContent = titre;
  document.getElementById('phRevueAide').innerHTML = aide;
  document.getElementById('phRevueBtn').disabled = false;
  phRendreRevue();
  bootstrap.Modal.getOrCreateInstance(document.getElementById('phModalRevue')).show();
}
function phRendreRevue() {
  const d = phDonnees();
  const opts = ['<option value="0">— Ignorer cette photo —</option>']
    .concat(d.eleves.map(e => '<option value="' + e.id + '">' + phEchapper(e.nom + (e.mat ? ' (' + e.mat + ')' : '')) + (e.photo ? ' • a déjà une photo' : '') + '</option>'));
  const badge = { matricule: ['success', 'matricule'], nom: ['success', 'nom'], ordre: ['warning text-dark', 'par ordre — à vérifier'], manuel: ['primary', 'choix manuel'], vide: ['secondary', 'case vide'], aucun: ['secondary', 'non associée'] };
  const comptes = {};
  phRevue.forEach(it => { if (it.id) comptes[it.id] = (comptes[it.id] || 0) + 1; });
  document.getElementById('phRevueCorps').innerHTML = phRevue.map((it, i) => {
    const e = it.id ? phEleve(it.id) : null, b = badge[it.methode] || badge.aucun;
    const alerte = it.id && comptes[it.id] > 1 ? '<div class="text-danger" style="font-size:.7rem"><i class="bi bi-exclamation-triangle"></i> élève choisi plusieurs fois</div>'
                 : (e && e.photo ? '<div class="text-warning" style="font-size:.7rem"><i class="bi bi-arrow-repeat"></i> remplacera la photo existante</div>' : '');
    return '<tr class="' + (it.id ? '' : 'opacity-50') + '">' +
      '<td><img class="ph-revue-mini" src="' + it.data + '" alt=""></td>' +
      '<td style="font-size:.74rem"><div class="text-truncate" style="max-width:190px" title="' + phEchapper(it.libelle) + '">' + phEchapper(it.libelle) + '</div>' +
      '<span class="badge bg-' + b[0] + '" style="font-size:.62rem">' + b[1] + '</span>' + (it.etat ? ' ' + it.etat : '') + '</td>' +
      '<td><select class="form-select form-select-sm" onchange="phChangerEleve(' + i + ', this.value)">' +
      opts.join('').replace('value="' + it.id + '"', 'value="' + it.id + '" selected') + '</select>' + alerte + '</td>' +
      '<td><button type="button" class="btn btn-sm btn-light" title="Recadrer" onclick="phRecadrerRevue(' + i + ')"><i class="bi bi-crop"></i></button></td></tr>';
  }).join('');
  const n = phRevue.filter(it => it.id).length;
  document.getElementById('phRevueEtat').textContent = n + ' photo(s) à enregistrer sur ' + phRevue.length;
}
function phChangerEleve(i, v) { phRevue[i].id = parseInt(v, 10) || 0; if (phRevue[i].id && phRevue[i].methode !== 'matricule' && phRevue[i].methode !== 'nom') phRevue[i].methode = 'manuel'; phRendreRevue(); }
function phRecadrerRevue(i) {
  const it = phRevue[i], e = it.id ? phEleve(it.id) : null;
  PhotosEleves.recadrer(it.src, url => { it.data = url; phRendreRevue(); }, e ? e.nom : it.libelle);
}
async function photosEnregistrerRevue() {
  const aEnvoyer = phRevue.filter(it => it.id && !it.envoye);
  if (!aEnvoyer.length) { alert('Aucune photo associée à un élève.'); return; }
  const vus = new Set();
  for (const it of aEnvoyer) { if (vus.has(it.id)) { alert('Un même élève est choisi pour plusieurs photos : corrigez avant d\'enregistrer.'); return; } vus.add(it.id); }
  const remplace = aEnvoyer.filter(it => phEleve(it.id)?.photo).length;
  if (remplace && !confirm(remplace + ' élève(s) ont déjà une photo, elle sera remplacée. Continuer ?')) return;
  const btn = document.getElementById('phRevueBtn'); btn.disabled = true;
  let ok = 0, ko = 0;
  for (const it of aEnvoyer) {
    it.etat = '<span class="text-primary" style="font-size:.7rem">envoi…</span>'; phRendreRevue();
    const r = await phEnvoyer(it.id, it.data);
    if (r.ok) { ok++; it.envoye = true; it.etat = '<span class="text-success" style="font-size:.7rem"><i class="bi bi-check-circle"></i> enregistrée</span>'; }
    else      { ko++; it.etat = '<span class="text-danger" style="font-size:.7rem">' + phEchapper(r.message) + '</span>'; }
    phRendreRevue();
  }
  document.getElementById('phRevueEtat').textContent = ok + ' enregistrée(s)' + (ko ? ', ' + ko + ' en échec (corrigez puis relancez)' : ' — terminé');
  btn.disabled = false;
}

// ── 2. Import en lot (appareil numérique / dossier) ─────────────────
async function photosImporterLot(input) {
  const fichiers = [...input.files].sort((a, b) => a.name.localeCompare(b.name, undefined, { numeric: true, sensitivity: 'base' }));
  input.value = '';
  if (!fichiers.length) return;
  const d = phDonnees();
  const parMat = new Map(), parNom = new Map();
  d.eleves.forEach(e => {
    const m = PhotosEleves.normaliser(e.mat); if (m) parMat.set(m, e.id);
    const n = PhotosEleves.normaliser(e.nom); if (n) parNom.set(n, e.id);
  });
  document.getElementById('phRevueEtat').textContent = '';
  phRevue = [];
  const pris = new Set();
  for (const f of fichiers) {
    let img;
    try { img = PhotosEleves.reduire(await PhotosEleves.chargerImage(f), 1600); } catch (e) { continue; }
    const base = PhotosEleves.normaliser(f.name.replace(/\.[^.]+$/, ''));
    let id = 0, methode = 'aucun';
    if (parMat.has(base)) { id = parMat.get(base); methode = 'matricule'; }
    else {
      for (const [m, i] of parMat) if (m.length >= 4 && base.includes(m)) { id = i; methode = 'matricule'; break; }
      if (!id) for (const [n, i] of parNom) if (n.length >= 5 && (base === n || base.includes(n))) { id = i; methode = 'nom'; break; }
    }
    if (id && pris.has(id)) { id = 0; methode = 'aucun'; }
    if (id) pris.add(id);
    phRevue.push({ src: img.toDataURL('image/jpeg', 0.92), data: PhotosEleves.recadrerAuto(img), libelle: f.name, id, methode });
  }
  // Fichiers non reconnus : attribués dans l'ordre (nom de fichier = ordre
  // de prise de vue) aux élèves restants, dans l'ordre de la liste.
  const restants = phOrdreCibles(pris);
  phRevue.filter(it => !it.id).forEach(it => { const e = restants.shift(); if (e) { it.id = e.id; it.methode = 'ordre'; } });
  if (!phRevue.length) { alert('Aucune image lisible dans la sélection.'); return; }
  phOuvrirRevue('Import de ' + phRevue.length + ' photo(s)',
    'Association automatique : <strong>matricule</strong> ou <strong>nom</strong> de l\'élève dans le nom du fichier ' +
    '(ex. <code>GSBI-2026-014.jpg</code>), sinon <strong>par ordre</strong> (fichiers triés par nom ↔ élèves dans l\'ordre alphabétique' +
    (document.getElementById('phSansPhoto')?.checked ? ', seulement ceux sans photo' : '') + '). ' +
    'Vérifiez surtout les lignes « par ordre », corrigez avec la liste déroulante, recadrez avec <i class="bi bi-crop"></i>.');
}

// ── 3. Planche scannée (photos 4x4 en grille) ───────────────────────
let phPlanche = null;
async function photosOuvrirPlanche(input) {
  const f = input.files[0]; input.value = '';
  if (!f) return;
  try { phPlanche = PhotosEleves.reduire(await PhotosEleves.chargerImage(f), 3000); }
  catch (e) { alert(e.message); return; }
  bootstrap.Modal.getOrCreateInstance(document.getElementById('phModalPlanche')).show();
  phDessinerPlanche();
}
function phGrille() {
  const v = id => Math.max(0, parseFloat(document.getElementById(id).value.replace(',', '.')) || 0);
  const W = phPlanche.width, H = phPlanche.height;
  const col = Math.max(1, Math.round(v('phPlCol'))), lig = Math.max(1, Math.round(v('phPlLig')));
  const x0 = W * v('phPlMg') / 100, x1 = W * (1 - v('phPlMd') / 100);
  const y0 = H * v('phPlMh') / 100, y1 = H * (1 - v('phPlMb') / 100);
  const cw = (x1 - x0) / col, ch = (y1 - y0) / lig, r = v('phPlRet') / 100;
  const cases = [];
  for (let l = 0; l < lig; l++) for (let c = 0; c < col; c++) {
    cases.push({ x: x0 + c * cw + cw * r, y: y0 + l * ch + ch * r, w: cw * (1 - 2 * r), h: ch * (1 - 2 * r), n: l * col + c + 1 });
  }
  return cases;
}
function phDessinerPlanche() {
  if (!phPlanche) return;
  const cv = document.getElementById('phPlancheCanvas');
  const k = Math.min(1, 900 / phPlanche.width);
  cv.width = Math.round(phPlanche.width * k); cv.height = Math.round(phPlanche.height * k);
  const g = cv.getContext('2d');
  g.drawImage(phPlanche, 0, 0, cv.width, cv.height);
  g.lineWidth = 2; g.font = 'bold 14px sans-serif';
  phGrille().forEach(c => {
    const vide = PhotosEleves.estVide(phPlanche, c.x, c.y, c.w, c.h);
    g.strokeStyle = vide ? 'rgba(150,150,150,.8)' : 'rgba(30,79,216,.95)';
    g.strokeRect(c.x * k, c.y * k, c.w * k, c.h * k);
    g.fillStyle = vide ? 'rgba(150,150,150,.9)' : 'rgba(30,79,216,.95)';
    g.fillText(String(c.n), c.x * k + 4, c.y * k + 16);
  });
}
document.addEventListener('input', e => { if (e.target.classList?.contains('ph-pl')) phDessinerPlanche(); });
function photosDecouperPlanche() {
  if (!phPlanche) return;
  phRevue = [];
  phGrille().forEach(c => {
    if (PhotosEleves.estVide(phPlanche, c.x, c.y, c.w, c.h)) return;
    phRevue.push({
      src: PhotosEleves.extraire(phPlanche, c.x, c.y, c.w, c.h),
      data: PhotosEleves.recadrerAuto(phPlanche, c.x, c.y, c.w, c.h),
      libelle: 'Case n° ' + c.n, id: 0, methode: 'aucun'
    });
  });
  if (!phRevue.length) { alert('Aucune photo détectée : ajustez la grille.'); return; }
  const cibles = phOrdreCibles(new Set());
  phRevue.forEach(it => { const e = cibles.shift(); if (e) { it.id = e.id; it.methode = 'ordre'; } });
  bootstrap.Modal.getInstance(document.getElementById('phModalPlanche')).hide();
  phOuvrirRevue(phRevue.length + ' photo(s) découpée(s)',
    'Les cases sont associées <strong>dans l\'ordre</strong> aux élèves de la liste' +
    (document.getElementById('phSansPhoto')?.checked ? ' (seulement ceux sans photo)' : '') +
    '. Vérifiez chaque ligne, corrigez l\'élève si besoin et ajustez le cadrage avec <i class="bi bi-crop"></i>.');
}

// ── Séance photo au téléphone (QR code) ─────────────────────────────
function photosOuvrirSeance() {
  const inf = document.getElementById('phSeanceInfos'); if (!inf) return;
  document.getElementById('phSeanceQr').src = inf.dataset.qr;
  document.getElementById('phSeanceUrl').textContent = inf.dataset.url;
  document.getElementById('phSeanceLien').href = inf.dataset.url;
  bootstrap.Modal.getOrCreateInstance(document.getElementById('phModalSeance')).show();
}
</script>
