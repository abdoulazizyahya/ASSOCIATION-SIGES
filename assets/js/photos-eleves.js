// assets/js/photos-eleves.js — outils communs aux photos d'élèves par classe
// (onglet « Photos par classe » de pages/eleves/liste.php et séance photo au
// téléphone pages/eleves/photos_seance.php).
// Format final identique au recadrage de pages/eleves/form.php : portrait
// 3:4, 300×400 px, JPEG — réduit DANS LE NAVIGATEUR avant l'envoi (≈ 30-60 Ko
// au lieu de plusieurs Mo pour une photo de téléphone) : envoi rapide même en
// connexion mobile, base de données (BLOB Photo_elv) qui ne gonfle pas.
window.PhotosEleves = (function () {
  const LARG = 300, HAUT = 400, RATIO = LARG / HAUT, QUALITE = 0.88;

  // Charge un File en <img> (orientation EXIF appliquée par le navigateur).
  function chargerImage(fichier) {
    return new Promise((ok, ko) => {
      const url = URL.createObjectURL(fichier);
      const img = new Image();
      img.onload = () => { ok(img); setTimeout(() => URL.revokeObjectURL(url), 1000); };
      img.onerror = () => { URL.revokeObjectURL(url); ko(new Error('Image illisible : ' + fichier.name)); };
      img.src = url;
    });
  }

  // Copie réduite (côté le plus long ≤ max) — évite de manipuler des photos
  // de 12 Mpx dans Cropper.js / les canvas sur téléphone.
  function reduire(img, max) {
    const w = img.naturalWidth || img.width, h = img.naturalHeight || img.height;
    const k = Math.min(1, max / Math.max(w, h));
    const c = document.createElement('canvas');
    c.width = Math.round(w * k); c.height = Math.round(h * k);
    c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
    return c;
  }

  // Recadrage automatique 3:4 CENTRÉ dans le rectangle (sx,sy,sw,sh) de la
  // source, légèrement décalé vers le haut (visage) — puis 300×400 JPEG.
  function recadrerAuto(src, sx, sy, sw, sh) {
    sx = sx || 0; sy = sy || 0;
    sw = sw || (src.naturalWidth || src.width); sh = sh || (src.naturalHeight || src.height);
    let cw = sw, ch = sw / RATIO;
    if (ch > sh) { ch = sh; cw = sh * RATIO; }
    const cx = sx + (sw - cw) / 2;
    const cy = sy + Math.max(0, (sh - ch) * 0.35);
    const c = document.createElement('canvas');
    c.width = LARG; c.height = HAUT;
    const g = c.getContext('2d');
    g.fillStyle = '#fff'; g.fillRect(0, 0, LARG, HAUT);
    g.imageSmoothingQuality = 'high';
    g.drawImage(src, cx, cy, cw, ch, 0, 0, LARG, HAUT);
    return c.toDataURL('image/jpeg', QUALITE);
  }

  // Zone d'une source en dataURL (sert de base à un recadrage manuel).
  function extraire(src, sx, sy, sw, sh) {
    const c = document.createElement('canvas');
    c.width = Math.max(1, Math.round(sw)); c.height = Math.max(1, Math.round(sh));
    c.getContext('2d').drawImage(src, sx, sy, sw, sh, 0, 0, c.width, c.height);
    return c.toDataURL('image/jpeg', 0.92);
  }

  // Case (quasi) uniforme = emplacement vide d'une planche scannée.
  function estVide(src, sx, sy, sw, sh) {
    const n = 24, c = document.createElement('canvas');
    c.width = n; c.height = n;
    const g = c.getContext('2d');
    g.drawImage(src, sx, sy, sw, sh, 0, 0, n, n);
    const d = g.getImageData(0, 0, n, n).data;
    let s = 0, s2 = 0, k = 0;
    for (let i = 0; i < d.length; i += 4) {
      const v = 0.299 * d[i] + 0.587 * d[i + 1] + 0.114 * d[i + 2];
      s += v; s2 += v * v; k++;
    }
    const moy = s / k, ecart = Math.sqrt(Math.max(0, s2 / k - moy * moy));
    return ecart < 10;
  }

  // Recadrage manuel (overlay .crop-overlay de assets/css/style.css, même
  // présentation que form.php). Crée l'overlay à la première utilisation.
  let cropper = null, rappel = null;
  function overlay() {
    let o = document.getElementById('peCropOverlay');
    if (o) return o;
    o = document.createElement('div');
    o.className = 'crop-overlay'; o.id = 'peCropOverlay';
    o.innerHTML =
      '<div class="crop-box">' +
      '<div class="crop-head"><span id="peCropTitre"><i class="bi bi-crop me-1"></i>Recadrer la photo</span>' +
      '<button type="button" data-a="fermer" style="background:none;border:none;color:#fff;font-size:1rem;cursor:pointer"><i class="bi bi-x-lg"></i></button></div>' +
      '<div style="background:#222;overflow:hidden"><img id="peCropImg" class="crop-canvas" alt=""></div>' +
      '<div class="crop-foot">' +
      '<button type="button" class="btn btn-sm btn-outline-secondary" data-a="g"><i class="bi bi-arrow-counterclockwise"></i></button>' +
      '<button type="button" class="btn btn-sm btn-outline-secondary" data-a="d"><i class="bi bi-arrow-clockwise"></i></button>' +
      '<button type="button" class="btn btn-sm btn-outline-secondary" data-a="zp"><i class="bi bi-zoom-in"></i></button>' +
      '<button type="button" class="btn btn-sm btn-outline-secondary" data-a="zm"><i class="bi bi-zoom-out"></i></button>' +
      '<button type="button" class="btn btn-sm btn-primary ms-auto" data-a="ok"><i class="bi bi-check-lg me-1"></i>Valider</button>' +
      '<button type="button" class="btn btn-sm btn-light" data-a="fermer">Annuler</button>' +
      '</div></div>';
    document.body.appendChild(o);
    o.addEventListener('click', e => {
      const b = e.target.closest('[data-a]');
      if (!b) { if (e.target === o) fermer(); return; }
      const a = b.dataset.a;
      if (a === 'fermer') fermer();
      else if (a === 'g') cropper.rotate(-90);
      else if (a === 'd') cropper.rotate(90);
      else if (a === 'zp') cropper.zoom(0.1);
      else if (a === 'zm') cropper.zoom(-0.1);
      else if (a === 'ok') {
        const url = cropper.getCroppedCanvas({ width: LARG, height: HAUT, fillColor: '#fff' }).toDataURL('image/jpeg', QUALITE);
        const cb = rappel; fermer(); if (cb) cb(url);
      }
    });
    return o;
  }
  function fermer() {
    const o = document.getElementById('peCropOverlay');
    if (o) o.classList.remove('show');
    if (cropper) { cropper.destroy(); cropper = null; }
    rappel = null;
  }
  // src : dataURL/URL de l'image à recadrer ; cb(dataUrl 300×400).
  function recadrer(src, cb, titre) {
    const o = overlay();
    document.getElementById('peCropTitre').innerHTML = '<i class="bi bi-crop me-1"></i>' + (titre || 'Recadrer la photo');
    const img = document.getElementById('peCropImg');
    if (cropper) { cropper.destroy(); cropper = null; }
    rappel = cb;
    img.onload = () => {
      cropper = new Cropper(img, { aspectRatio: RATIO, viewMode: 1, dragMode: 'move', background: false, autoCropArea: 0.9 });
    };
    img.src = src;
    o.classList.add('show');
  }

  // Envoi d'une photo (dataURL) — résout { ok, message, url }.
  function envoyer(urlApi, csrf, idEleve, dataUrl) {
    const fd = new FormData();
    fd.append('csrf', csrf); fd.append('id_eleve', idEleve); fd.append('photo_b64', dataUrl);
    return poster(urlApi, fd);
  }
  // Suppression de la photo — résout { ok, message, url (avatar) }.
  function supprimer(urlApi, csrf, idEleve) {
    const fd = new FormData();
    fd.append('csrf', csrf); fd.append('id_eleve', idEleve); fd.append('action', 'supprimer');
    return poster(urlApi, fd);
  }
  function poster(urlApi, fd) {
    return fetch(urlApi, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(r => r.text())
      .then(t => {
        try { return JSON.parse(t); }
        catch (e) { return { ok: false, message: (t || 'Réponse invalide du serveur').replace(/<[^>]+>/g, ' ').trim().slice(0, 200) }; }
      })
      .catch(() => ({ ok: false, message: 'Connexion au serveur impossible.' }));
  }

  // Majuscules sans accents ni séparateurs (comparaison nom de fichier ↔
  // matricule / nom d'élève).
  function normaliser(s) {
    return String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toUpperCase().replace(/[^A-Z0-9]/g, '');
  }

  return { chargerImage, reduire, recadrerAuto, extraire, estVide, recadrer, envoyer, supprimer, normaliser, LARG, HAUT, RATIO };
})();
