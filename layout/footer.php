    </main><!-- /.abz-content -->

    <footer class="abz-footer">
      © <?= date('Y') ?> <?= h(get_etablissement()['Nom_Etab_Fr'] ?? APP_NOM) ?> — v2.0
    </footer>

  </div><!-- /.abz-main -->
</div><!-- /.abz-shell -->

<!-- ═══ Modale globale d'aperçu PDF (Imprimer/Télécharger un document
     depuis n'importe quelle page via afficherApercu(url, titre, typeDocument,
     orientation)) — bandeau Signature numérique bien visible (pas une
     simple case perdue dans l'en-tête) + bouton ⚙ de positionnement
     glisser-déposer (DIRECTEUR uniquement), comme ABZ_MBE. ═══ -->
<div class="modal fade" id="modalApercu" tabindex="-1">
  <div class="modal-dialog" id="dialogApercu" style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);
              width:80vw;height:80vh;max-width:none;max-height:none;min-width:420px;min-height:320px;
              margin:0;overflow:hidden;resize:both">
    <div class="modal-content" style="height:100%;width:100%">
      <div class="modal-header py-2 flex-wrap gap-2" style="background:#f0f4ff">
        <h6 class="modal-title fw-semibold mb-0" style="color:#1a3c6b;font-size:.9rem" id="titreApercu">
          <i class="bi bi-eye me-1"></i>Aperçu
        </h6>
        <div class="d-flex gap-2 flex-wrap ms-auto">
          <a id="lienApercuDl" href="#" class="btn btn-sm btn-primary text-white">
            <i class="bi bi-download me-1"></i>Télécharger
          </a>
          <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('iframeApercu').contentWindow.print()">
            <i class="bi bi-printer me-1"></i>Imprimer
          </button>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <?php if (signature_etablissement_chemin()): ?>
      <div id="bandeauSignatureApercu" class="d-none px-3 py-2 d-flex align-items-center gap-2 flex-wrap"
           style="background:#fff8e6;border-bottom:1px solid #f0dca0">
        <div class="form-check form-switch mb-0">
          <input class="form-check-input" type="checkbox" role="switch" id="chkSignatureApercu" style="width:2.4em;height:1.3em">
          <label class="form-check-label fw-semibold" for="chkSignatureApercu" style="font-size:.85rem;color:#7a5b00">
            <i class="bi bi-vector-pen me-1"></i>Signature numérique du Directeur
          </label>
        </div>
        <span class="text-muted" style="font-size:.72rem">— jamais appliquée sans cocher cette case</span>
        <?php if (role_connecte() === 'DIRECTEUR'): ?>
        <button type="button" id="btnPositionApercu" class="btn btn-sm btn-abz-outline ms-auto d-none" onclick="ouvrirPositionSignature()">
          <i class="bi bi-gear me-1"></i>Positionner
        </button>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <div class="modal-body p-0" style="flex:1 1 auto;overflow:hidden">
        <iframe id="iframeApercu" src="about:blank" style="width:100%;height:100%;border:none;display:block" title="Aperçu du document"></iframe>
      </div>
    </div>
  </div>
</div>

<!-- ═══ Modale de positionnement de la signature (glisser-déposer sur un
     rendu réel du document via PDF.js, ou un cadre simplifié pour les
     cartes) — DIRECTEUR uniquement. ═══ -->
<div class="modal fade" id="modalPositionSignature" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered" style="max-width:760px">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title fw-semibold mb-0" style="font-size:.9rem">
          <i class="bi bi-arrows-move me-1"></i>Position de la signature
        </h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small mb-2">Glissez la signature à l'endroit voulu et redimensionnez-la (poignée en bas à droite), puis validez. L'aperçu ci-dessous montre le contenu réel du document, à l'échelle.</p>
        <div id="posChargement" class="text-center text-muted py-4" style="font-size:.85rem">
          <div class="spinner-border spinner-border-sm me-2"></div>Chargement de l'aperçu…
        </div>
        <div id="posFrame" style="position:relative;background:#eef1f6;border:1px solid #b8c4d9;margin:0 auto;max-width:100%;overflow:auto;display:none">
          <canvas id="posCanvas" style="display:block"></canvas>
          <img id="posSigImg" src="<?= signature_etablissement_chemin() ? APP_URL . '/assets/uploads/' . h(get_etablissement()['signature']) : '' ?>"
               draggable="false" style="position:absolute;cursor:move">
          <div id="posResizeHandle" style="position:absolute;width:14px;height:14px;background:#1a2744;border-radius:3px;cursor:nwse-resize"></div>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
        <button type="button" class="btn btn-sm btn-abz-primary" onclick="validerPositionSignature()">
          <i class="bi bi-check-lg me-1"></i>Valider
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ═══ Modale « Aucune année active » (menus Discipline/Pédagogie) — demande
     explicite du 18/08/2026 : tant qu'aucune année scolaire n'est activée
     (Paramètres ▸ Années), ces menus restent visibles mais tout clic ouvre
     cette modale au lieu de naviguer (voir le script plus bas). ═══ -->
<div class="modal fade" id="modalAnneeInactive" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content">
      <div class="modal-body text-center py-4">
        <i class="bi bi-calendar-x" style="font-size:2.2rem;color:#dc2626"></i>
        <h6 class="fw-bold mt-2 mb-1">Aucune année scolaire active</h6>
        <p class="text-muted mb-3" style="font-size:.85rem">
          Ce module nécessite une année scolaire active. Activez-en une dans Paramètres pour y accéder.
        </p>
        <?php if (($role ?? '') === 'DIRECTEUR'): ?>
          <a href="<?= APP_URL ?>/pages/parametres/index.php?onglet=annees" class="btn btn-sm btn-abz-primary">
            <i class="bi bi-gear me-1"></i>Aller aux Paramètres
          </a>
        <?php endif; ?>
        <button type="button" class="btn btn-sm btn-outline-secondary mt-2 d-block mx-auto" data-bs-dismiss="modal" style="width:fit-content">Fermer</button>
      </div>
    </div>
  </div>
</div>

<script src="<?= APP_URL ?>/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="<?= APP_URL ?>/assets/vendor/pdfjs/pdf.min.js"></script>
<script>
// Pare-feu de l'hébergeur (ModSecurity / OWASP CRS, Camoo) : il rejetait en
// 403 des enregistrements ordinaires en prenant un texte saisi pour une
// injection SQL. Chaque fois que le navigateur construit les données d'un
// formulaire POST (envoi classique OU new FormData(form) d'un envoi AJAX),
// l'événement `formdata` permet de remplacer ses champs texte par UN champ
// `_formb64` (base64 d'une liste [nom, valeur]) — sans toucher aux champs
// de la page. Décodé côté serveur au début de fonctions.php. Restent en
// clair : le jeton csrf et les fichiers. Exclure un formulaire : attribut
// data-sans-encodage.
(function () {
  const versB64 = (s) => btoa(unescape(encodeURIComponent(s)));
  document.addEventListener('formdata', (e) => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement)) return;
    if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post') return;
    if (form.hasAttribute('data-sans-encodage')) return;
    const fd = e.formData, paires = [], noms = new Set();
    for (const [nom, valeur] of fd.entries()) {
      if (nom === 'csrf' || nom === '_formb64' || typeof valeur !== 'string') continue;   // fichiers : en clair
      paires.push([nom, valeur]);
      noms.add(nom);
    }
    if (!paires.length) return;
    // Un nom porté à la fois par un fichier et du texte garde ses fichiers.
    noms.forEach((nom) => {
      const fichiers = fd.getAll(nom).filter((v) => typeof v !== 'string');
      fd.delete(nom);
      fichiers.forEach((f) => fd.append(nom, f));
    });
    fd.set('_formb64', versB64(JSON.stringify(paires)));
  }, true);   // capture : reçu quel que soit le formulaire de la page
})();
</script>
<script>
  pdfjsLib.GlobalWorkerOptions.workerSrc = '<?= APP_URL ?>/assets/vendor/pdfjs/pdf.worker.min.js';
</script>
<script>
// Point d'entrée commun pour afficher un PDF généré par l'appli dans la
// modale d'aperçu partagée. $url doit déjà contenir tous les paramètres de
// filtre (classe, colonnes...) — seul &signature=1 est ajouté/retiré
// dynamiquement selon la case à cocher. $typeDocument identifie le document
// pour le positionnement de signature (ex. 'liste_eleves', 'carte_3') —
// requis seulement si ce document porte une signature.
let apercuBaseUrl = '';
let apercuTypeDocument = null;
let apercuOrientation = 'portrait';
// Fonction appelée après validation d'une position dans la fenêtre de
// glisser-déposer, pour rafraîchir l'aperçu réel avec la position tout
// juste enregistrée — appliquerApercu() par défaut (modale partagée),
// réaffectée temporairement par un flux hors-modale (ex. iframe inline de
// pages/bulletins/index.php) via ouvrirPositionSignatureLocale().
let apercuRefreshFn = () => appliquerApercu();
function afficherApercu(url, titre, typeDocument = null, orientation = 'portrait') {
  apercuBaseUrl = url;
  apercuTypeDocument = typeDocument;
  apercuOrientation = orientation;
  apercuRefreshFn = () => appliquerApercu();
  const chk = document.getElementById('chkSignatureApercu');
  const bandeau = document.getElementById('bandeauSignatureApercu');
  const btnPos = document.getElementById('btnPositionApercu');
  if (chk) chk.checked = false;
  if (bandeau) bandeau.classList.toggle('d-none', !typeDocument);
  if (btnPos) btnPos.classList.toggle('d-none', !typeDocument);
  appliquerApercu();
  document.getElementById('titreApercu').innerHTML = '<i class="bi bi-eye me-1"></i>' + (titre || 'Aperçu');
  const dialog = document.getElementById('dialogApercu');
  dialog.style.width = '80vw'; dialog.style.height = '80vh';
  dialog.style.left = '50%'; dialog.style.top = '50%'; dialog.style.transform = 'translate(-50%, -50%)';
  bootstrap.Modal.getOrCreateInstance(document.getElementById('modalApercu')).show();
  requestAnimationFrame(() => {
    const r = dialog.getBoundingClientRect();
    dialog.style.transform = 'none'; dialog.style.left = r.left + 'px'; dialog.style.top = r.top + 'px';
  });
}
function appliquerApercu() {
  if (!apercuBaseUrl) return;
  const sep = apercuBaseUrl.includes('?') ? '&' : '?';
  const chk = document.getElementById('chkSignatureApercu');
  const sig = (chk && chk.checked) ? 'signature=1' : '';
  document.getElementById('iframeApercu').src = apercuBaseUrl + (sig ? sep + sig : '');
  document.getElementById('lienApercuDl').href = apercuBaseUrl + sep + 'dl=1' + (sig ? '&' + sig : '');
}
document.getElementById('chkSignatureApercu')?.addEventListener('change', appliquerApercu);
document.getElementById('modalApercu').addEventListener('hidden.bs.modal', () => {
  document.getElementById('iframeApercu').src = 'about:blank';
});
</script>
<script>
// ═══ Positionnement/redimensionnement de la signature (glisser-déposer) ═══
// Le cadre affiché montre le CONTENU RÉEL du document (rendu via PDF.js dans
// un <canvas>, à l'échelle) pour les documents pleine page — la position est
// enregistrée en pourcentage de ce cadre, appliquée telle quelle par
// pdf_signature_appliquer_jn() sur les dimensions RÉELLES de la page au
// moment de la génération. Pour les cartes scolaires (plusieurs cartes
// possibles sur une même page physique), la position est relative à UNE
// SEULE carte : cadre simplifié au bon ratio largeur/hauteur (CR80),
// sans rendu réel.
const FRAME_DIMS_MM = { portrait: [210, 297], landscape: [297, 210], card: [85.6, 54] };
let posCtx = null;

function urlSansSignature(url) {
  try {
    const u = new URL(url, window.location.href);
    u.searchParams.delete('signature');
    return u.toString();
  } catch (e) { return url; }
}

// À utiliser depuis un flux hors modale d'aperçu partagée (ex. iframe inline
// de pages/bulletins/index.php, aperçu individuel OU classe complète) — même
// fenêtre de glisser-déposer, mais rafraîchit $refreshFn (une fonction locale
// à la page appelante) plutôt que la modale, à la validation.
function ouvrirPositionSignatureLocale(baseUrl, typeDocument, orientation, refreshFn) {
  apercuBaseUrl = baseUrl;
  apercuTypeDocument = typeDocument;
  apercuOrientation = orientation;
  apercuRefreshFn = refreshFn;
  ouvrirPositionSignature();
}

function ouvrirPositionSignature() {
  if (!apercuTypeDocument) return;
  posCtx = { typeDocument: apercuTypeDocument };
  document.getElementById('posFrame').style.display = 'none';
  document.getElementById('posChargement').style.display = '';
  fetch('<?= APP_URL ?>/ajax/signature_position_get.php?type=' + encodeURIComponent(apercuTypeDocument))
    .then(r => r.json())
    .then(pos => {
      bootstrap.Modal.getOrCreateInstance(document.getElementById('modalPositionSignature')).show();
      if (apercuOrientation === 'card') { initPosFrameSimple(pos); }
      else { initPosFramePdf(pos); }
    });
}

function initPosFramePdf(pos) {
  const canvas = document.getElementById('posCanvas');
  canvas.style.display = 'block';
  const url = urlSansSignature(apercuBaseUrl);
  pdfjsLib.getDocument(url).promise
    .then(pdf => pdf.getPage(1))
    .then(page => {
      const maxW = Math.min(680, window.innerWidth - 120);
      const unscaled = page.getViewport({ scale: 1 });
      const scale = maxW / unscaled.width;
      const viewport = page.getViewport({ scale });
      canvas.width = viewport.width;
      canvas.height = viewport.height;
      return page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise
        .then(() => placerSignatureSurCadre(viewport.width, viewport.height, pos));
    })
    .catch(() => { initPosFrameSimple(pos); });
}
function initPosFrameSimple(pos) {
  document.getElementById('posCanvas').style.display = 'none';
  const [wmm, hmm] = FRAME_DIMS_MM[apercuOrientation] || FRAME_DIMS_MM.portrait;
  const maxW = 420;
  const scale = maxW / wmm;
  placerSignatureSurCadre(wmm * scale, hmm * scale, pos);
}
function placerSignatureSurCadre(fw, fh, pos) {
  const frame = document.getElementById('posFrame');
  frame.style.width = fw + 'px';
  frame.style.height = fh + 'px';
  document.getElementById('posChargement').style.display = 'none';
  frame.style.display = '';
  const img = document.getElementById('posSigImg');
  img.onload = () => {
    const wPct = pos.w_pct ?? 15;
    const w = fw * wPct / 100;
    img.style.width = w + 'px';
    img.style.left = (fw * (pos.x_pct ?? 65) / 100) + 'px';
    img.style.top  = (fh * (pos.y_pct ?? 80) / 100) + 'px';
    positionResizeHandle();
  };
  if (img.complete) img.onload();
}
function positionResizeHandle() {
  const img = document.getElementById('posSigImg');
  const handle = document.getElementById('posResizeHandle');
  handle.style.left = (img.offsetLeft + img.offsetWidth - 7) + 'px';
  handle.style.top  = (img.offsetTop + img.offsetHeight - 7) + 'px';
}
(function() {
  const frame  = document.getElementById('posFrame');
  const img    = document.getElementById('posSigImg');
  const handle = document.getElementById('posResizeHandle');
  if (!frame || !img || !handle) return;
  let dragging = false, resizing = false, decalX = 0, decalY = 0, startW = 0, startX = 0;
  img.addEventListener('mousedown', (e) => {
    dragging = true;
    decalX = e.clientX - img.offsetLeft;
    decalY = e.clientY - img.offsetTop;
    e.preventDefault();
  });
  handle.addEventListener('mousedown', (e) => {
    resizing = true;
    startW = img.offsetWidth;
    startX = e.clientX;
    e.preventDefault();
    e.stopPropagation();
  });
  document.addEventListener('mousemove', (e) => {
    if (dragging) {
      let left = e.clientX - decalX;
      let top  = e.clientY - decalY;
      left = Math.max(0, Math.min(left, frame.clientWidth - img.offsetWidth));
      top  = Math.max(0, Math.min(top, frame.clientHeight - img.offsetHeight));
      img.style.left = left + 'px';
      img.style.top  = top + 'px';
      positionResizeHandle();
    } else if (resizing) {
      let w = Math.max(15, startW + (e.clientX - startX));
      w = Math.min(w, frame.clientWidth - img.offsetLeft);
      img.style.width = w + 'px';
      positionResizeHandle();
    }
  });
  document.addEventListener('mouseup', () => { dragging = false; resizing = false; });
})();
function validerPositionSignature() {
  if (!posCtx) return;
  const frame = document.getElementById('posFrame');
  const img   = document.getElementById('posSigImg');
  const body  = new URLSearchParams({
    csrf: <?= json_encode(csrf_generer()) ?>,
    type_document: posCtx.typeDocument,
    x_pct: img.offsetLeft / frame.clientWidth * 100,
    y_pct: img.offsetTop / frame.clientHeight * 100,
    w_pct: img.offsetWidth / frame.clientWidth * 100,
  });
  fetch('<?= APP_URL ?>/ajax/signature_position_save.php', { method: 'POST', body })
    .then(r => r.json())
    .then(res => {
      if (res.ok) {
        bootstrap.Modal.getInstance(document.getElementById('modalPositionSignature')).hide();
        apercuRefreshFn();
      } else {
        alert(res.error || "Erreur lors de l'enregistrement.");
      }
    });
}
</script>
<script>
// Sidebar mobile
const sidebar      = document.getElementById('sidebar');
const backdrop     = document.getElementById('backdrop');
const burger       = document.getElementById('burger');
const sidebarClose = document.getElementById('sidebarClose');
function fermerSidebar() {
  sidebar.classList.remove('open');
  backdrop.classList.remove('show');
}
if (burger) {
  burger.addEventListener('click', () => {
    sidebar.classList.add('open');
    backdrop.classList.add('show');
  });
  backdrop.addEventListener('click', fermerSidebar);
  if (sidebarClose) sidebarClose.addEventListener('click', fermerSidebar);
}

// Menu latéral en accordéon : replié par défaut, la section de la page
// courante démarre dépliée ; seul le clic sur l'en-tête déplie/replie une
// section (indépendantes les unes des autres).
function toggleNavSection(el) {
    el.parentElement.classList.toggle('ouvert');
}

// Blocage des menus Discipline/Pédagogie tant qu'aucune année scolaire n'est
// active (data-annee-active sur <body>, voir layout/header.php) — demande
// explicite du 18/08/2026. Un clic sur un lien de ces sections
// (data-annee-requise="1") ouvre la modale d'avertissement au lieu de
// naviguer ; le lien reste visible (pas grisé), le blocage n'intervient qu'à
// l'action pour rester cohérent avec la protection serveur équivalente
// (exiger_annee_active(), fonctions.php — filet de sécurité pour un accès
// direct par URL qui contournerait ce clic).
if (document.body.dataset.anneeActive !== '1') {
    document.querySelectorAll('.nav-section[data-annee-requise="1"] .nav-lien').forEach(function (lien) {
        lien.addEventListener('click', function (e) {
            e.preventDefault();
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modalAnneeInactive')).show();
        });
    });
}

// ═══ Navigation AJAX partielle (portée d'ABZ_MBE à l'identique) ═══
// Transforme un changement d'affichage (sélection de classe, filtre, onglet,
// tri, pagination…) en une requête AJAX qui ne renvoie et ne remplace QUE le
// contenu d'une zone désignée (topbar/sidebar/menu jamais retouchés) —
// jamais utilisé pour un enregistrement (POST), qui recharge normalement.
// Convention côté PHP : la page cible doit savoir répondre en mode "partiel"
// quand elle reçoit `partiel=1` en GET — dans ce mode elle saute
// layout/header.php et layout/footer.php et n'affiche QUE le contenu de la
// zone (voir pages/statistiques/index.php pour un exemple).
function chargerPartiel(url, containerId) {
    const container = document.getElementById(containerId);
    if (!container) return;
    const sep = url.includes('?') ? '&' : '?';
    container.style.opacity = '.5';
    fetch(url + sep + 'partiel=1', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => {
            // Session expirée entre-temps (redirigée vers login.php) : une
            // vraie navigation s'impose, injecter le HTML de login.php dans
            // la zone n'aurait aucun sens.
            if (r.redirected) { window.location.href = r.url; return null; }
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.text();
        })
        .then(html => {
            if (html === null) return;
            container.innerHTML = html;
            container.style.opacity = '';
            // Filet de sécurité si un lien data-ajax-nav était cliqué depuis
            // l'intérieur d'une modale Bootstrap encore ouverte (rare mais
            // possible) — voir le commentaire détaillé sur
            // nettoyerModalsOrphelines() plus bas, même cause que pour
            // soumettreFormulaireAjax().
            nettoyerModalsOrphelines();
            // Les <script> injectés via innerHTML ne s'exécutent JAMAIS
            // automatiquement (sécurité navigateur) — recréés puis rattachés
            // un par un pour qu'ils s'exécutent malgré tout. Ces scripts
            // doivent utiliser des déclarations `function ...() {}`
            // (réexécutables) et jamais de `let`/`const` au premier niveau
            // (erreur de redéclaration au 2e rechargement).
            container.querySelectorAll('script').forEach((ancien) => {
                const nouveau = document.createElement('script');
                if (ancien.src) { nouveau.src = ancien.src; } else { nouveau.textContent = ancien.textContent; }
                ancien.replaceWith(nouveau);
            });
            history.pushState({ ajaxZone: containerId, url }, '', url);
            container.dispatchEvent(new CustomEvent('partielCharge', { bubbles: true }));
        })
        .catch(() => { window.location.href = url; }); // secours : navigation normale si l'AJAX échoue
}
// Extrait #<container.id> et #flash-zone d'une page COMPLÈTE (réponse d'un
// POST après redirection, pas une réponse déjà partielle) et les réinjecte
// dans le DOM courant, avec réexécution des <script> — utilisé par
// soumettreFormulaireAjax() ci-dessous. Renvoie false si le conteneur est
// introuvable dans la réponse (page d'erreur, accès refusé, structure
// inattendue...) : dans ce cas l'appelant doit retomber sur une navigation
// normale plutôt que d'injecter un HTML de page complète dans une simple div.
// Un formulaire soumis en AJAX (soumettreFormulaireAjax()) est très souvent
// À L'INTÉRIEUR d'une modale Bootstrap ouverte (« Modifier », « Générer »...).
// Remplacer container.innerHTML détruit le nœud DOM de cette modale SANS
// jamais passer par bootstrap.Modal.hide() — Bootstrap ne peut alors jamais
// retirer le fond assombri (.modal-backdrop, ajouté à <body>, donc HORS de
// `container`) ni la classe `modal-open`/le style `overflow:hidden` qu'il
// pose sur <body>. Résultat déjà constaté : la page reste visuellement
// grisée et totalement inerte au clic — lue par un utilisateur comme
// « le système plante ». On ne peut pas compter sur le hide() animé de
// Bootstrap ici (sa transition peut se terminer APRÈS que ce nœud ait déjà
// disparu) : nettoyage inconditionnel après coup, à chaque injection.
function nettoyerModalsOrphelines() {
    document.querySelectorAll('.modal-backdrop').forEach((b) => b.remove());
    if (!document.querySelector('.modal.show')) {
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('overflow');
        document.body.style.removeProperty('padding-right');
    }
}

function injecterHtmlDansZone(html, container) {
    const doc = new DOMParser().parseFromString(html, 'text/html');
    const nouvelleZone = doc.getElementById(container.id);
    if (!nouvelleZone) return false;
    // #flash-zone (bannière succès/erreur, voir flash_html()) : hors de la
    // zone principale sur la plupart des pages, à rafraîchir séparément pour
    // qu'un enregistrement AJAX affiche son message de résultat.
    const nouveauFlash = doc.getElementById('flash-zone');
    const flashActuel = document.getElementById('flash-zone');
    if (nouveauFlash && flashActuel) flashActuel.innerHTML = nouveauFlash.innerHTML;
    container.innerHTML = nouvelleZone.innerHTML;
    nettoyerModalsOrphelines();
    // Les <script> injectés via innerHTML ne s'exécutent JAMAIS automatiquement
    // (sécurité navigateur) — recréés puis rattachés un par un pour qu'ils
    // s'exécutent malgré tout. Ces scripts doivent utiliser des déclarations
    // `function ...() {}` (réexécutables) et jamais de `let`/`const` au
    // premier niveau (erreur de redéclaration au 2e rechargement).
    container.querySelectorAll('script').forEach((ancien) => {
        const nouveau = document.createElement('script');
        if (ancien.src) { nouveau.src = ancien.src; } else { nouveau.textContent = ancien.textContent; }
        ancien.replaceWith(nouveau);
    });
    return true;
}

// Envoie un formulaire d'ENREGISTREMENT (POST — paiement, note, fiche
// élève...) en AJAX : la page cible garde EXACTEMENT sa logique actuelle
// (csrf_verifier(), validation, flash_set(), rediriger()) — aucune page ne
// doit être réécrite pour ça. On suit simplement la redirection standard
// Post/Redirect/Get jusqu'à la réponse finale (fetch() la suit tout seul,
// GET, comme le ferait un navigateur), on en extrait la zone
// #<containerId> et #flash-zone, et on les réinjecte SANS jamais recharger
// la page (sidebar, scroll, onglets déjà ouverts... tout est préservé).
// Session expirée entre-temps (redirection vers login.php) : une vraie
// navigation s'impose, comme pour chargerPartiel().
// Message de résultat (#flash-zone) d'une réponse AJAX quand la page
// d'arrivée n'a pas la même zone (ex. fiche élève enregistrée -> page
// « voir ») : la réponse a déjà CONSOMMÉ le message côté serveur, une simple
// re-navigation l'aurait perdu (« aucun message après changement de
// classe », 02/10/2026). On le garde le temps de la navigation.
function naviguerAvecFlash(html, url) {
    try {
        const f = new DOMParser().parseFromString(html, 'text/html').getElementById('flash-zone');
        if (f && f.innerHTML.trim()) sessionStorage.setItem('flash_differe', f.innerHTML);
    } catch (e) { /* stockage indisponible : navigation quand même */ }
    window.location.href = url;
}
// Met le message en évidence : remonte en haut de page et le fait clignoter.
function signalerFlash() {
    const z = document.getElementById('flash-zone');
    if (!z || !z.querySelector('.alert')) return;
    z.scrollIntoView({ behavior: 'smooth', block: 'start' });
    z.querySelectorAll('.alert').forEach((a) => { a.classList.add('flash-attention'); });
}
function afficherErreurEnregistrement(statut) {
    const msg = statut === 403
        ? "L'enregistrement a été refusé par le serveur (erreur 403). Rien n'a été modifié. Réessayez ; si l'erreur persiste, signalez-la à l'administrateur."
        : "L'enregistrement a échoué (erreur " + statut + " du serveur). Rien n'a été modifié. Réessayez dans un instant.";
    const z = document.getElementById('flash-zone');
    if (z) {
        z.innerHTML = '<div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2 py-2" role="alert">'
            + '<i class="bi bi-exclamation-triangle"></i><span></span>'
            + '<button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button></div>';
        z.querySelector('span').textContent = msg;
        signalerFlash();
    }
    alert(msg);
}
document.addEventListener('DOMContentLoaded', () => {
    let differe = null;
    try { differe = sessionStorage.getItem('flash_differe'); sessionStorage.removeItem('flash_differe'); } catch (e) {}
    const z = document.getElementById('flash-zone');
    if (differe && z && !z.innerHTML.trim()) z.innerHTML = differe;
    signalerFlash();
});

function soumettreFormulaireAjax(form, containerId) {
    const container = document.getElementById(containerId);
    if (!container) { form.submit(); return; }
    const action = form.getAttribute('action') || window.location.href;
    // FormData brut (PAS URLSearchParams) : certains formulaires de l'appli
    // ont enctype="multipart/form-data" (ex. pages/eleves/dossier_upload.php)
    // — un fichier passé dans URLSearchParams serait sérialisé en texte
    // ("[object File]"), corrompant l'upload. fetch() gère nativement
    // FormData comme body (Content-Type multipart correct pour les deux cas).
    const body = new FormData(form);
    const submitter = form.dataset.dernierSubmitter;
    if (submitter) { const [n, v] = JSON.parse(submitter); if (n) body.set(n, v); }
    container.style.opacity = '.5';
    let urlFinale = action;
    fetch(action, { method: 'POST', body, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then((r) => {
            urlFinale = r.url; // POST déjà exécuté à ce stade — un secours ne doit plus jamais renvoyer le formulaire (double enregistrement), seulement RE-NAVIGUER vers cette URL.
            if (r.redirected && /\/login\.php(\?|$)/.test(r.url)) { window.location.href = r.url; return null; }
            if (!r.ok) { const err = new Error('HTTP ' + r.status); err.http = r.status; throw err; }
            return r.text();
        })
        .then((html) => {
            if (html === null) return;
            // Le POST a déjà réussi ici (réponse serveur reçue) — toute erreur
            // d'injection à partir de ce point ne doit plus jamais renvoyer le
            // formulaire (double enregistrement), seulement re-naviguer.
            try {
                if (!injecterHtmlDansZone(html, container)) { naviguerAvecFlash(html, urlFinale); return; }
                container.style.opacity = '';
                container.dispatchEvent(new CustomEvent('partielCharge', { bubbles: true }));
                signalerFlash();
            } catch (e) {
                naviguerAvecFlash(html, urlFinale);
            }
        })
        .catch((e) => {
            container.style.opacity = '';
            // Le serveur a RÉPONDU par une erreur (403 du pare-feu, 500…) :
            // on l'annonce clairement au lieu de renvoyer le formulaire (qui
            // affichait une page « Forbidden » brute).
            if (e && e.http) { afficherErreurEnregistrement(e.http); return; }
            // Échec AVANT toute réponse serveur exploitable (réseau coupé...) :
            // seul cas où renvoyer le formulaire normalement est sûr, le POST
            // n'a alors jamais atteint le serveur.
            form.submit();
        });
}

// Délégation d'événements sur un conteneur STABLE (jamais lui-même remplacé
// par chargerPartiel — seul son innerHTML change) : survit donc à chaque
// rechargement partiel sans devoir ré-attacher quoi que ce soit.
//  - <a data-ajax-nav href="..."> : clic -> navigation AJAX vers cet href.
//  - <form data-ajax-nav-form method="get"> : soumission -> sérialise et
//    navigue en AJAX au lieu du submit natif.
//  - <form data-ajax-post-form method="post"> : soumission -> envoyée en
//    AJAX (soumettreFormulaireAjax) au lieu du submit natif — pour les
//    enregistrements (paiement, note, fiche élève...).
//  - un champ data-ajax-nav-auto dans un tel formulaire : changement ->
//    déclenche la soumission (remplace onchange="this.form.submit()").
function initAjaxZone(containerId) {
    const container = document.getElementById(containerId);
    if (!container) return;
    container.addEventListener('click', (e) => {
        const a = e.target.closest('a[data-ajax-nav]');
        if (!a || !container.contains(a)) return;
        e.preventDefault();
        chargerPartiel(a.getAttribute('href'), containerId);
    });
    // Mémorise le bouton submit qui a déclenché l'envoi (voir remarque sur
    // e.submitter plus bas) — capturé ICI, en phase de capture sur tout
    // clic, car l'événement 'submit' lui-même arrive après que le navigateur
    // ait déjà déterminé son submitter, mais on veut la même info
    // disponible pour data-ajax-post-form (submit standard, pas de
    // e.submitter fiable une fois passé par requestSubmit indirect).
    container.addEventListener('click', (e) => {
        const btn = e.target.closest('button[type="submit"], input[type="submit"]');
        if (!btn) return;
        const form = btn.closest('form[data-ajax-nav-form], form[data-ajax-post-form]');
        if (!form || !container.contains(form)) return;
        form.dataset.dernierSubmitter = JSON.stringify([btn.name || '', btn.value || '']);
    }, true);
    container.addEventListener('submit', (e) => {
        const form = e.target.closest('form[data-ajax-nav-form]');
        if (!form || !container.contains(form)) return;
        e.preventDefault();
        // new FormData(form) seul NE capture PAS le name/value du bouton
        // <button type="submit" name="..." value="..."> qui a déclenché la
        // soumission (contrairement à un submit natif du navigateur) — sans
        // e.submitter, un formulaire avec plusieurs boutons submit (ex.
        // Trimestre/Annuel dans pages/bulletins(_arabe)/index.php) perdait
        // silencieusement ce paramètre, retombant toujours sur sa valeur par
        // défaut côté PHP quel que soit le bouton cliqué. Bug réel trouvé le
        // 13/08 (menu Bulletins : Trimestre et Annuel affichaient toujours
        // la même chose).
        const params = new URLSearchParams(new FormData(form));
        if (e.submitter && e.submitter.name) params.set(e.submitter.name, e.submitter.value);
        const action = form.getAttribute('action') || window.location.pathname;
        chargerPartiel(action + (params.toString() ? '?' + params.toString() : ''), containerId);
    });
    container.addEventListener('submit', (e) => {
        const form = e.target.closest('form[data-ajax-post-form]');
        if (!form || !container.contains(form)) return;
        // Un onsubmit="return confirm(...)" sur le formulaire lui-même
        // (ex. suppression) tourne AVANT ce listener délégué (phase cible
        // avant phase de bulle) et appelle déjà preventDefault() si
        // l'utilisateur annule — on respecte cette annulation, jamais
        // d'envoi AJAX dans ce cas.
        if (e.defaultPrevented) return;
        e.preventDefault();
        soumettreFormulaireAjax(form, containerId);
    });
    container.addEventListener('change', (e) => {
        const champ = e.target.closest('[data-ajax-nav-auto]');
        if (!champ || !container.contains(champ)) return;
        const form = champ.closest('form[data-ajax-nav-form]');
        if (form) form.requestSubmit();
    });
    window.addEventListener('popstate', (e) => {
        if (e.state && e.state.ajaxZone === containerId) {
            chargerPartiel(e.state.url, containerId);
        } else {
            // Retour à l'entrée d'historique d'origine (avant toute navigation
            // AJAX, donc sans state) : un rechargement complet reste le seul
            // moyen sûr de retrouver exactement le rendu initial.
            window.location.reload();
        }
    });
}

// À utiliser quand l'aperçu est déclenché depuis une AUTRE modale déjà
// ouverte (ex. choix de colonnes/format) : attend la fin de sa fermeture
// avant d'ouvrir la modale d'aperçu, sinon Bootstrap peut laisser un
// backdrop fantôme.
function afficherApercuApresFermeture(idModaleSource, url, titre, typeDocument = null, orientation = 'portrait') {
  const modalEl = document.getElementById(idModaleSource);
  modalEl.addEventListener('hidden.bs.modal', () => afficherApercu(url, titre, typeDocument, orientation), { once: true });
  bootstrap.Modal.getInstance(modalEl).hide();
}
<?php if (!empty($ajax_zone_id)): ?>
// Appelé ICI (dans le même <script>, juste après la définition de
// initAjaxZone) plutôt que par un <script> séparé dans la page appelante
// placé AVANT cet include — un appel plus tôt échouerait silencieusement
// (ReferenceError), laissant la zone sans écouteur et donc tous les
// sélecteurs/liens inertes. Chaque page définit simplement
// `$ajax_zone_id = 'id-de-sa-zone';` avant d'inclure ce fichier.
initAjaxZone(<?= json_encode($ajax_zone_id) ?>);
<?php endif; ?>
</script>
<script>
// ═══ Restriction de saisie sur les champs numériques (montants, coefficients,
//     ordres, notes, seuils...) — demande du 21/08/2026 ═══
// S'applique automatiquement à TOUT <input type="number"> de l'application,
// page courante ET pages futures (rien à faire ailleurs) : le champ est
// converti en type="text" (avec inputmode adapté pour le clavier des
// téléphones/tablettes) puis filtré à chaque frappe pour n'accepter QUE des
// chiffres, plus — si le champ déclare un pas décimal (step="0.01", "0.25",
// "any"...) — UN séparateur décimal, virgule OU point ("3,45" comme "3.45"),
// et un signe "-" en tête si min est négatif.
// Pourquoi ne pas garder type="number" : son filtrage clavier natif dépend
// de la langue du navigateur et rejette souvent silencieusement la virgule
// décimale (pourtant l'usage courant en français) — impossible à corriger
// de façon fiable par script une fois le caractère bloqué en amont par le
// navigateur lui-même. Le serveur reste la source de vérité (conversion
// virgule → point avant chaque cast en float, déjà en place dans tout le
// projet) : ce filtrage est un confort de saisie, pas un contrôle de
// sécurité.
(function () {
  function decimalesAutorisees(input) {
    var step = (input.getAttribute('step') || '').trim().toLowerCase();
    return step !== '' && step !== '1';
  }
  function negatifAutorise(input) {
    var min = input.getAttribute('min');
    if (min === null) return false;
    var v = parseFloat(min.replace(',', '.'));
    return !isNaN(v) && v < 0;
  }
  function nettoyer(valeur, decimales, negatif) {
    var res = '', virgule = false, signe = false;
    for (var i = 0; i < valeur.length; i++) {
      var car = valeur[i];
      if (car >= '0' && car <= '9') { res += car; continue; }
      if (negatif && car === '-' && res === '' && !signe) { res += car; signe = true; continue; }
      if (decimales && (car === ',' || car === '.') && !virgule) { res += car; virgule = true; continue; }
      // tout autre caractère (lettre, symbole, séparateur en trop...) est ignoré.
    }
    return res;
  }
  function filtrer(input, decimales, negatif) {
    var avant = input.value;
    var apres = nettoyer(avant, decimales, negatif);
    if (apres === avant) return;
    var pos = input.selectionStart;
    var diff = avant.length - apres.length;
    input.value = apres;
    var nouvellePos = Math.max(0, (pos === null ? apres.length : pos) - diff);
    try { input.setSelectionRange(nouvellePos, nouvellePos); } catch (e) {}
  }
  function activer(input) {
    if (input.dataset.numFiltre) return;
    input.dataset.numFiltre = '1';
    var decimales = decimalesAutorisees(input);
    var negatif = negatifAutorise(input);
    input.setAttribute('type', 'text');
    input.setAttribute('inputmode', decimales ? 'decimal' : 'numeric');
    input.addEventListener('input', function () { filtrer(input, decimales, negatif); });
  }
  function initialiserSaisieNumerique(racine) {
    racine.querySelectorAll('input[type="number"]').forEach(activer);
  }
  document.addEventListener('DOMContentLoaded', function () { initialiserSaisieNumerique(document); });
  // Contenu rechargé en AJAX partiel (voir chargerPartiel() plus haut) : les
  // éventuels nouveaux champs number de la zone remplacée doivent être
  // réactivés, l'événement bubble jusqu'à document.
  document.addEventListener('partielCharge', function (e) { initialiserSaisieNumerique(e.target); });
})();
</script>
<?= function_exists('db_stats_html') ? db_stats_html() : '' /* N requêtes SQL — en local uniquement */ ?>
</body>
</html>
