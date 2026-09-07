<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_connexion();

$partiel = ($_GET['partiel'] ?? '') === '1';

$annee     = get_annee_active();
$val_annee = $annee['val_annee'] ?? '';

// ── Paramètres de filtre / tri / pagination ──────────────
$q         = trim($_GET['q'] ?? '');
$id_classe = (int)($_GET['classe'] ?? 0);
// Écriture autorisée par l'association (superadmin entré en mode écriture) :
// couvre toutes les actions élève, y compris import / config matricule
// (import.php & matricule_config.php ont un exiger_role() qui laisse passer
// la visite association — voir fonctions.php::exiger_role()).
$asso_ecriture = function_exists('est_visite_association') && est_visite_association()
              && function_exists('est_lecture_seule') && !est_lecture_seule();

$peut_gerer    = $asso_ecriture || in_array(role_connecte(), ['DIRECTEUR','SECRETAIRE','COMPTABLE'], true);
// L'import en masse + la config des matricules restent hors périmètre
// COMPTABLE (demande du 22/08/2026) — pages/eleves/import.php et
// matricule_config.php restent ['DIRECTEUR','SECRETAIRE'] (+ visite écriture).
$peut_importer = $asso_ecriture || in_array(role_connecte(), ['DIRECTEUR','SECRETAIRE'], true);

// « outils » = onglet Import & matricules (pas une liste d'élèves) — réservé
// aux rôles qui peuvent importer / configurer.
$statut = in_array($_GET['statut'] ?? '', ['actif','desactive','outils'], true) ? $_GET['statut'] : 'actif';
if ($statut === 'outils' && !$peut_importer) $statut = 'actif';
$vue_outils = ($statut === 'outils');
$tri       = in_array($_GET['tri'] ?? '', ['nom','mat','sexe','classe']) ? $_GET['tri'] : 'nom';
$ordre     = ($_GET['ordre'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
$page      = max(1, (int)($_GET['page'] ?? 1));
$pp        = in_array((int)($_GET['pp'] ?? 25), [10,25,50,100]) ? (int)($_GET['pp'] ?? 25) : 25;
$offset    = ($page - 1) * $pp;

$where  = ["e.statut = ?"];
$params = [$statut];

// Cloisonnement enseignant : un(e) ENSEIGNANT ne voit que les élèves
// inscrit(e)s cette année dans SES classes (piste FR ∪ AR). DIRECTEUR /
// SECRETAIRE / FONDATEUR : aucune restriction (classes_ids_visibles = null).
$ids_classes_vis = classes_ids_visibles($val_annee, 'union');
if ($ids_classes_vis !== null) {
    if (!$ids_classes_vis) {
        $where[] = '1=0';                       // aucune classe affectée -> aucune ligne
    } else {
        $ph = implode(',', array_fill(0, count($ids_classes_vis), '?'));
        $where[] = "i.IDClasses IN ($ph)";
        $params  = array_merge($params, $ids_classes_vis);
    }
    if ($id_classe && !in_array($id_classe, $ids_classes_vis, true)) $id_classe = 0;
}

// Recherche : nom, prénom, matricule, NIU ou téléphone. L'élève n'a pas de
// champ téléphone dédié dans le schéma jaynitaare — recherché dans
// l'adresse des parents, seul endroit où un numéro est parfois saisi
// historiquement (voir prompt de continuité).
if ($q !== '') {
    $where[]  = "(e.Nom_elv LIKE ? OR e.Prenom_elv LIKE ? OR e.Mat_elv LIKE ? OR e.niu LIKE ?
                  OR EXISTS (SELECT 1 FROM parent p WHERE p.id_eleve=e.id_eleve AND p.adresse LIKE ?))";
    $like     = "%$q%";
    $params   = array_merge($params, [$like, $like, $like, $like, $like]);
}
if ($id_classe) {
    $where[]  = "i.IDClasses = ?";
    $params[] = $id_classe;
}
$sql_where = 'WHERE ' . implode(' AND ', $where);

$order_map = [
    'nom'    => 'e.Nom_elv, e.Prenom_elv',
    'mat'    => 'e.Mat_elv',
    'sexe'   => 'e.Sexe_elv, e.Nom_elv',
    'classe' => 'c.DesignationClasses, e.Nom_elv',
];
$order_sql = ($order_map[$tri] ?? 'e.Nom_elv') . ' ' . $ordre;

$join_insc = "LEFT JOIN inscrire i ON i.id_eleve=e.id_eleve AND i.val_annee=?";
$params_join = array_merge([$val_annee], $params);

$total  = 0;
$eleves = [];
if (!$vue_outils) {
    $total = (int) db_val(
        "SELECT COUNT(*) FROM eleve e
         $join_insc
         LEFT JOIN classe c ON c.IDClasses = i.IDClasses
         $sql_where", $params_join);

    $eleves = db_all(
        "SELECT e.id_eleve, e.Mat_elv, e.Nom_elv, e.Prenom_elv, e.Sexe_elv, e.Date_naiss_elv, e.Lieu_naiss_elv, e.niu, e.statut,
                (e.Photo_elv IS NOT NULL) AS a_photo,
                c.DesignationClasses, c.IDClasses
         FROM eleve e
         $join_insc
         LEFT JOIN classe c ON c.IDClasses = i.IDClasses
         $sql_where
         ORDER BY $order_sql
         LIMIT $pp OFFSET $offset", $params_join);
}

function th_tri(string $col, string $label, string $tri_actuel, string $ordre_actuel): string {
    $o    = ($tri_actuel === $col && $ordre_actuel === 'ASC') ? 'desc' : 'asc';
    $icon = '';
    if ($tri_actuel === $col) $icon = $ordre_actuel === 'ASC'
        ? '<i class="bi bi-caret-up-fill ms-1" style="font-size:.6rem"></i>'
        : '<i class="bi bi-caret-down-fill ms-1" style="font-size:.6rem"></i>';
    return '<a href="#" onclick="triListe(\'' . $col . '\',\'' . $o . '\');return false" class="text-white text-decoration-none">' . $label . $icon . '</a>';
}


// ═══ Réponse AJAX (partiel=1) : uniquement onglets + tableau + pagination,
// jamais la barre de recherche/filtres (qui reste stable côté client pour
// ne jamais perdre le focus du champ de recherche pendant la frappe). ═══
if ($partiel) {
    require __DIR__ . '/_liste_resultats.php';
    exit;
}

$classes = db_all(
    "SELECT c.IDClasses, c.DesignationClasses,
            (SELECT COUNT(*) FROM inscrire i2 WHERE i2.IDClasses=c.IDClasses AND i2.val_annee=?) AS nb
     FROM classe c LEFT JOIN niveau n ON n.LibelleNiveau=c.Niveau
     ORDER BY n.OrdreNiveau, c.DesignationClasses", [$val_annee]);
// Enseignant restreint : le filtre « Classe » ne propose que ses classes.
if ($ids_classes_vis !== null) {
    $classes = array_values(array_filter($classes, fn($c) => in_array((int) $c['IDClasses'], $ids_classes_vis, true)));
}
$classe_nom_choisie = $id_classe ? (db_one("SELECT DesignationClasses FROM classe WHERE IDClasses=?", [$id_classe])['DesignationClasses'] ?? '') : '';

$titre_page = 'Élèves';
require_once __DIR__ . '/../../layout/header.php';
?>

<div class="page-titre d-flex justify-content-between align-items-center flex-wrap gap-2">
  <div>
    <h4><i class="bi bi-people me-1 text-primary"></i> Élèves</h4>
    <div class="sub">Année <?= h($val_annee) ?></div>
  </div>
  <?php if ($peut_gerer): ?>
  <div class="d-flex gap-2 flex-wrap">
    <?php /* « Importer » a été déplacé dans l'onglet « Import & matricules »
             (voir _liste_resultats.php), avec la configuration des matricules. */ ?>
    <a href="<?= APP_URL ?>/pages/eleves/form.php" class="btn btn-primary btn-sm">
      <i class="bi bi-plus-lg me-1"></i> Nouvel élève
    </a>
  </div>
  <?php endif; ?>
</div>

<!-- Barre de recherche + filtres + actions PDF/Excel/Cartes — STABLE, jamais
     remplacée par l'AJAX (contrairement au tableau ci-dessous), pour que le
     champ de recherche ne perde jamais le focus pendant la frappe. Les
     selects Classe/Par page sont réduits au minimum pour laisser la place
     aux boutons d'export à droite. -->
<div class="card mb-2">
  <div class="card-body py-2">
    <div class="row g-2 align-items-end">
      <div class="col-12 col-md-4 col-lg-5">
        <label class="form-label">Recherche</label>
        <div class="input-group input-group-sm">
          <span class="input-group-text"><i class="bi bi-search"></i></span>
          <input type="text" id="inpRecherche" class="form-control" placeholder="Nom, prénom, matricule, NIU, téléphone…" value="<?= h($q) ?>" autocomplete="off">
        </div>
      </div>
      <div class="col-6 col-md-3 col-lg-2">
        <label class="form-label">Classe</label>
        <select id="selClasseListe" class="form-select form-select-sm">
          <option value="">Toutes</option>
          <?php foreach ($classes as $c): ?>
            <option value="<?= $c['IDClasses'] ?>" <?= $id_classe == $c['IDClasses'] ? 'selected' : '' ?>>
              <?= h($c['DesignationClasses']) ?> (<?= (int)$c['nb'] ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2 col-lg-1">
        <label class="form-label">/page</label>
        <select id="selPpListe" class="form-select form-select-sm">
          <?php foreach ([10,25,50,100] as $n): ?>
            <option value="<?= $n ?>" <?= $pp==$n?'selected':'' ?>><?= $n ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-3 col-lg-4">
        <div class="d-flex gap-1 justify-content-md-end flex-wrap">
          <button type="button" class="btn btn-outline-danger btn-sm" id="btnPdfListe"
                  data-bs-toggle="modal" data-bs-target="#modalExport"
                  title="<?= $id_classe ? h('Liste des élèves de ' . $classe_nom_choisie) : 'Liste des élèves de toutes les classes' ?>">
            <i class="bi bi-file-earmark-pdf me-1"></i>PDF
          </button>
          <a href="<?= APP_URL ?>/pages/eleves/excel.php?classe=<?= $id_classe ?>" class="btn btn-outline-success btn-sm" id="btnExcelListe"
             title="<?= $id_classe ? h('Liste des élèves de ' . $classe_nom_choisie) : 'Liste des élèves de toutes les classes' ?>">
            <i class="bi bi-file-earmark-excel me-1"></i>Excel
          </a>
          <?php if (role_connecte() !== 'COMPTABLE'): ?>
          <button type="button" class="btn btn-outline-primary btn-sm <?= $id_classe ? '' : 'd-none' ?>" id="btnCartesListe"
                  data-bs-toggle="modal" data-bs-target="#modalCartes">
            <i class="bi bi-credit-card me-1"></i>Cartes
          </button>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<div id="zoneResultats">
<?php require __DIR__ . '/_liste_resultats.php'; ?>
</div>

<!-- ═══ Modal export PDF — choix de colonnes, ordre d'affichage et
     alignement (mirroir d'ABZ_MBE, pages/eleves/liste.php) — pdf/
     liste_eleves.php accepte déjà cols=/align= (jamais câblé côté écran
     jusqu'ici : le bouton appelait directement afficherApercu() avec des
     colonnes fixes). ═══ -->
<div class="modal fade" id="modalExport" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title fw-bold"><i class="bi bi-file-earmark-pdf me-1 text-danger"></i>Impression PDF</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body py-2">
        <p class="text-muted mb-2" style="font-size:.75rem">
          Colonnes à inclure (cocher), ordre d'affichage (flèches) et alignement du contenu :
        </p>
        <?php
        $colonnes_export = ['no'=>'N°','nom'=>'Nom et Prénoms','date'=>'Date naiss.',
                             'lieu'=>'Lieu naiss.','sexe'=>'Sexe','matricule'=>'Matricule',
                             'niu'=>'NIU','classe'=>'Classe'];
        $coches_export   = ['no','nom','date','lieu','sexe','matricule'];
        ?>
        <ul class="list-group" id="listeColonnesExport">
          <?php foreach ($colonnes_export as $k => $lbl): ?>
            <li class="list-group-item d-flex align-items-center gap-2 py-1 px-2" data-col="<?= $k ?>">
              <input class="form-check-input mt-0 flex-shrink-0" type="checkbox" id="col_<?= $k ?>" value="<?= $k ?>"
                     <?= in_array($k, $coches_export, true) ? 'checked' : '' ?>>
              <label class="form-check-label flex-grow-1" for="col_<?= $k ?>" style="font-size:.78rem"><?= $lbl ?></label>
              <select class="form-select form-select-sm flex-shrink-0" style="width:auto;font-size:.72rem" data-align title="Alignement">
                <option value="L" selected>⟸ Gauche</option>
                <option value="C">↔ Centre</option>
                <option value="R">Droite ⟹</option>
              </select>
              <button type="button" class="btn btn-sm btn-light py-0 px-1" style="line-height:1"
                      onclick="deplacerColonneExport(this,-1)" title="Monter"><i class="bi bi-arrow-up"></i></button>
              <button type="button" class="btn btn-sm btn-light py-0 px-1" style="line-height:1"
                      onclick="deplacerColonneExport(this,1)" title="Descendre"><i class="bi bi-arrow-down"></i></button>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div class="modal-footer py-2">
        <button class="btn btn-danger btn-sm" onclick="lancerPdfListe()">
          <i class="bi bi-printer me-1"></i>Générer
        </button>
        <button class="btn btn-light btn-sm" data-bs-dismiss="modal">Annuler</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="modalCartes" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title fw-bold"><i class="bi bi-credit-card me-1 text-primary"></i>Cartes scolaires — classe</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body py-2">
        <label class="form-label">Modèle</label>
        <select id="selModele" class="form-select form-select-sm mb-2">
          <option value="1">Modèle 1 — Officiel bilingue</option>
          <option value="2">Modèle 2 — Encadré classique</option>
          <option value="3">Modèle 3 — Bandeau année</option>
          <option value="4">Modèle 4 — Badge centré</option>
          <option value="5">Modèle 5 — Minimaliste</option>
        </select>
        <label class="form-label">Format</label>
        <select id="selFormat" class="form-select form-select-sm mb-2">
          <option value="1">Carte bancaire (CR80)</option>
          <option value="2">ISO carte étudiant</option>
          <option value="3">A4 pleine page</option>
        </select>
        <div class="form-check mb-1">
          <input class="form-check-input" type="checkbox" id="chkVerso" onchange="document.getElementById('optFlip').classList.toggle('d-none', !this.checked)">
          <label class="form-check-label" for="chkVerso" style="font-size:.82rem">Imprimer le verso (recto-verso)</label>
        </div>
        <div id="optFlip" class="d-none">
          <label class="form-label" style="font-size:.78rem">Sens de retournement de la feuille</label>
          <select id="selFlip" class="form-select form-select-sm">
            <option value="long">Bord long (comme un livre)</option>
            <option value="short">Bord court (comme un bloc-notes)</option>
          </select>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button class="btn btn-primary btn-sm" onclick="lancerCartes()">
          <i class="bi bi-printer me-1"></i>Générer
        </button>
        <button class="btn btn-light btn-sm" data-bs-dismiss="modal">Annuler</button>
      </div>
    </div>
  </div>
</div>

<script>
// ═══ Recherche instantanée façon Google : aucun rechargement de page, aucun
// clic sur une icône requis — chaque frappe (après un court débounce),
// changement de classe/page ou de tri recharge uniquement #zoneResultats
// (jamais la barre de recherche elle-même, qui garde le focus). ═══
let etatListe = {
  q: <?= json_encode($q) ?>, classe: <?= (int)$id_classe ?>, pp: <?= (int)$pp ?>,
  statut: <?= json_encode($statut) ?>, tri: <?= json_encode($tri) ?>, ordre: <?= json_encode(strtolower($ordre)) ?>,
  page: <?= (int)$page ?>
};
let debounceRecherche = null;

function urlListeCourante(partiel) {
  const qs = new URLSearchParams();
  if (etatListe.q) qs.set('q', etatListe.q);
  if (etatListe.classe) qs.set('classe', etatListe.classe);
  if (etatListe.statut !== 'actif') qs.set('statut', etatListe.statut);
  qs.set('tri', etatListe.tri);
  qs.set('ordre', etatListe.ordre);
  qs.set('pp', etatListe.pp);
  qs.set('page', etatListe.page);
  if (partiel) qs.set('partiel', '1');
  return '<?= APP_URL ?>/pages/eleves/liste.php?' + qs.toString();
}

function rafraichirListe() {
  const zone = document.getElementById('zoneResultats');
  zone.style.opacity = '.5';
  fetch(urlListeCourante(true), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(r => { if (r.redirected) { window.location.href = r.url; return null; } return r.text(); })
    .then(html => {
      if (html === null) return;
      zone.innerHTML = html;
      zone.style.opacity = '';
      history.replaceState(null, '', urlListeCourante(false));
    })
    .catch(() => { window.location.href = urlListeCourante(false); });
}

document.getElementById('inpRecherche').addEventListener('input', function() {
  etatListe.q = this.value;
  etatListe.page = 1;
  clearTimeout(debounceRecherche);
  debounceRecherche = setTimeout(rafraichirListe, 300);
});
document.getElementById('selClasseListe').addEventListener('change', function() {
  etatListe.classe = this.value ? parseInt(this.value, 10) : 0;
  etatListe.page = 1;
  majBoutonsExport();
  rafraichirListe();
});
document.getElementById('selPpListe').addEventListener('change', function() {
  etatListe.pp = parseInt(this.value, 10);
  etatListe.page = 1;
  rafraichirListe();
});
function changerStatutListe(s) { etatListe.statut = s; etatListe.page = 1; rafraichirListe(); }
function triListe(col, ordre) { etatListe.tri = col; etatListe.ordre = ordre; rafraichirListe(); }
function allerPageListe(p) { etatListe.page = p; rafraichirListe(); window.scrollTo({top:0, behavior:'smooth'}); }

// Libellé de la classe actuellement sélectionnée, pour les infobulles
// PDF/Excel (« liste de toutes les classes » ou « liste de la classe de X »).
const nomsClasses = {};
<?php foreach ($classes as $c): ?>
nomsClasses[<?= (int)$c['IDClasses'] ?>] = <?= json_encode($c['DesignationClasses']) ?>;
<?php endforeach; ?>
function majBoutonsExport() {
  const libelle = etatListe.classe ? ('Liste des élèves de ' + nomsClasses[etatListe.classe]) : 'Liste des élèves de toutes les classes';
  document.getElementById('btnPdfListe').title = libelle;
  document.getElementById('btnExcelListe').title = libelle;
  document.getElementById('btnExcelListe').href = '<?= APP_URL ?>/pages/eleves/excel.php?classe=' + etatListe.classe;
  document.getElementById('btnCartesListe').classList.toggle('d-none', !etatListe.classe);
}

// Le panneau d'aperçu (iframe + Télécharger + Imprimer) est fourni
// globalement par layout/footer.php (fonction afficherApercu(url, titre)),
// identique à la présentation utilisée pour les bulletins/reçus/etc.
// Déplace la <li> du bouton cliqué d'un cran vers le haut (-1) ou le bas (1)
// dans la liste #listeColonnesExport : l'ordre visuel devient l'ordre des
// colonnes dans le PDF généré (ex. mettre NIU en 1re ou dernière colonne).
function deplacerColonneExport(btn, sens) {
  const li = btn.closest('li');
  if (sens === -1 && li.previousElementSibling) {
    li.parentElement.insertBefore(li, li.previousElementSibling);
  } else if (sens === 1 && li.nextElementSibling) {
    li.parentElement.insertBefore(li.nextElementSibling, li);
  }
}
function lancerPdfListe() {
  const lignes = [...document.querySelectorAll('#listeColonnesExport li')]
    .filter(li => li.querySelector('input').checked);
  if (!lignes.length) { alert('Choisissez au moins une colonne.'); return; }
  const cols  = lignes.map(li => li.dataset.col).join(',');
  const align = lignes.map(li => li.querySelector('[data-align]').value).join(',');
  afficherApercuApresFermeture('modalExport',
    '<?= APP_URL ?>/pdf/liste_eleves.php?classe=' + etatListe.classe + '&cols=' + cols + '&align=' + align,
    'Liste des élèves', 'liste_eleves', 'portrait');
}
function lancerCartes() {
  const modele = document.getElementById('selModele').value;
  const format = document.getElementById('selFormat').value;
  const verso  = document.getElementById('chkVerso').checked ? '1' : '0';
  const flip   = document.getElementById('selFlip').value;
  const typeDoc = verso === '1' ? 'carte_verso' : ('carte_' + modele);
  afficherApercu(
    '<?= APP_URL ?>/pdf/cartes.php?classe=' + etatListe.classe + '&modele=' + modele + '&format=' + format + '&verso=' + verso + '&flip=' + flip,
    'Cartes scolaires', typeDoc, 'card'
  );
}
</script>

<?php require_once __DIR__ . '/../../layout/footer.php'; ?>
