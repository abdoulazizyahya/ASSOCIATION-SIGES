<?php
// secondaire/commun/pages/emploi_temps/saisie.php — saisie manuelle d'un emploi du temps
// de classe. On choisit un cours (matière + enseignant) : la grille se colore
// (vert = possible, orange = règle souhaitée non respectée, bleu = cours commun
// possible, rouge = conflit ou règle obligatoire), puis un clic place UNE heure ;
// les heures consécutives du même cours se fusionnent. Clic ou clic droit sur un
// cours : menu (enseignant, salle, déplacer, verrou, supprimer, cours commun).
// Tout se fait ici, sans passer par Services (25/10/2026).
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
require_once __DIR__ . '/_edt_lib.php';
exiger_role(EDT_ROLES_GESTION);

$a = edt_annee_id();
$classes = edt_classes();
$ens = edt_enseignants();
// Deux vues : par classe, ou par enseignant (toute sa semaine, toutes classes : ses cours se placent là)
$vue_ens = ($_GET['vue'] ?? '') === 'enseignant';
$id_ens = $vue_ens ? (int) ($_GET['ens'] ?? ($ens[0]['matricule_ens'] ?? 0)) : 0;
$id_classe = $vue_ens ? 0 : (int) ($_GET['classe'] ?? ($classes[0]['id'] ?? 0));
$ecrire = edt_peut_ecrire();
$services = $vue_ens ? edt_services($a, null, $id_ens) : edt_services($a, $id_classe);
// Charge des enseignants de la classe (alerte pendant la construction, 25/10/2026)
$charges = edt_charges_enseignants($a);
$dues = [];
foreach (db_all("SELECT matricule_ens, heures_dues FROM edt_enseignant WHERE id_annee=? AND heures_dues IS NOT NULL", [$a]) as $d) $dues[(int) $d['matricule_ens']] = (float) $d['heures_dues'];
$fmtH = fn(float $h) => rtrim(rtrim(number_format($h, 1, ',', ''), '0'), ',') . ' h';
$prog = $vue_ens ? [] : edt_programme_classe($id_classe);
// Vue enseignant : programme de chaque classe pour « Nouveau cours » (classe puis matière)
$progParClasse = [];
if ($vue_ens) foreach ($classes as $c) $progParClasse[(int) $c['id']] = array_map(fn($p) => [(int) $p['id_mat'], $p['libelle']], edt_programme_classe((int) $c['id']));
$nomEns = '';
foreach ($ens as $e) if ((int) $e['matricule_ens'] === $id_ens) $nomEns = $e['nom'];
$salles = edt_salles();
$titre_page = 'Emploi du temps — Construction';
require_once __DIR__ . '/../../../../noyau/layout/header.php';
echo edt_css();
$base = APP_URL . '/secondaire/commun/pages/emploi_temps/';
?>
<?= edt_onglets('construction', 'saisie') ?>
<?= flash_html() ?>
<div class="card mb-3"><div class="card-body py-2 d-flex flex-wrap gap-2 align-items-center">
  <div class="btn-group btn-group-sm" role="group" aria-label="Vue">
    <a class="btn <?= $vue_ens ? 'btn-outline-primary' : 'btn-primary' ?>" href="saisie.php"><i class="bi bi-people"></i> Par classe</a>
    <a class="btn <?= $vue_ens ? 'btn-primary' : 'btn-outline-primary' ?>" href="saisie.php?vue=enseignant"><i class="bi bi-person-badge"></i> Par enseignant</a></div>
  <form method="get" class="m-0 d-flex gap-2 align-items-center">
  <?php if ($vue_ens): ?><input type="hidden" name="vue" value="enseignant"><label class="fw-bold">Enseignant</label>
    <select class="form-select form-select-sm w-auto" name="ens" onchange="this.form.submit()"><?php foreach ($ens as $e): ?><option value="<?= $e['matricule_ens'] ?>" <?= (int) $e['matricule_ens'] === $id_ens ? 'selected' : '' ?>><?= h($e['nom']) ?></option><?php endforeach; ?></select>
  <?php else: ?><label class="fw-bold">Classe</label>
    <select class="form-select form-select-sm w-auto" name="classe" onchange="this.form.submit()"><?php foreach ($classes as $c): ?><option value="<?= $c['id'] ?>" <?= (int) $c['id'] === $id_classe ? 'selected' : '' ?>><?= h($c['designation']) ?></option><?php endforeach; ?></select>
  <?php endif; ?></form>
  <?php $qv = $vue_ens ? 'vue=enseignant&id=' . $id_ens : 'vue=classe&id=' . $id_classe; ?>
  <a class="btn btn-sm btn-outline-primary" href="consulter.php?<?= $qv ?>"><i class="bi bi-eye"></i> Consulter</a>
  <a class="btn btn-sm btn-outline-primary" target="_blank" href="pdf.php?<?= $qv ?>"><i class="bi bi-file-earmark-pdf"></i> PDF</a>
  <?php $pile = $ecrire ? edt_annuler_pile($a) : []; if ($pile): $der = end($pile); ?>
  <button type="button" class="btn btn-sm btn-outline-secondary" onclick="annulerDerniere()" title="<?= h('Dernière action (' . $der['quand'] . ') : ' . $der['lib'] . ' — ' . count($pile) . ' action(s) annulable(s)') ?>"><i class="bi bi-arrow-counterclockwise"></i> Annuler : <?= h(mb_strimwidth($der['lib'], 0, 38, '…')) ?></button>
  <?php endif; ?>
  <span class="small ms-auto"><span class="badge edt-dispo-ok text-dark border">possible</span> <span class="badge edt-dispo-souhaitee text-dark border">règle souhaitée non respectée</span> <span class="badge edt-dispo-commun text-dark border">cours commun possible</span> <span class="badge edt-dispo-conflit text-dark border">conflit / règle obligatoire</span></span>
</div></div>
<?php if (!$ecrire): ?><div class="alert alert-light border small">Consultation seule : la modification est réservée au Censeur, au Proviseur et à l'administrateur.</div><?php endif; ?>

<div class="row g-3">
  <div class="col-xl-3 col-lg-4 no-print">
    <div class="card"><div class="card-header py-2"><b>Cours à placer</b><div class="small text-muted">Choisissez un cours, puis cliquez une case : <b>une case = une heure</b>. Pour 2 h, cliquez la case suivante : les heures consécutives se fusionnent.</div></div>
      <div class="card-body p-2">
        <div class="list-group list-group-flush small" id="palette">
        <?php foreach ($services as $s): $reste = (int) round((float) $s['heures']) - (int) $s['places']; ?>
          <button type="button" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center px-2 py-1" data-service="<?= $s['id'] ?>" style="border-left:6px solid <?= edt_couleur((int) $s['id_mat']) ?>">
            <span><b><?= h($vue_ens ? $s['classe'] . ' — ' . $s['matiere'] : $s['matiere']) ?></b><br><span class="text-muted"><?= h($vue_ens ? '' : ($s['enseignant'] ?: '— sans enseignant —')) ?><?= $s['code_commun'] ? ' · commun' : '' ?></span>
              <?php $me = (int) $s['matricule_ens']; if ($me && !$vue_ens):$pl = (float) ($charges[$me]['placees'] ?? 0); $du = $dues[$me] ?? null; $at = (float) ($charges[$me]['attribuees'] ?? 0); ?>
              <br><span class="<?= $du !== null && max($pl, $at) > $du ? 'text-danger fw-semibold' : 'text-muted' ?>" style="font-size:.7rem" title="Charge de l'enseignant, toutes classes">
                <?= $du !== null ? (max($pl, $at) > $du ? '⚠ ' : '') . 'charge ' . $fmtH($at) . ' attribuées, ' . $fmtH($pl) . ' placées / ' . $fmtH($du) . ' dues' : 'charge ' . $fmtH($pl) . ' placées' ?></span>
              <?php endif; ?></span>
            <span class="badge <?= $reste > 0 ? 'bg-warning text-dark' : ($reste < 0 ? 'bg-danger' : 'bg-success') ?>" title="Heures restant à placer"><?= $reste > 0 ? "reste $reste h" : ($reste < 0 ? 'trop : ' . (-$reste) . ' h' : 'complet') ?></span>
          </button>
        <?php endforeach; ?>
        <?php if (!$services): ?><div class="text-muted p-2">Aucun cours prévu pour <?= $vue_ens ? 'cet enseignant' : 'cette classe' ?> : ajoutez-en ci-dessous.</div><?php endif; ?>
        </div>
        <?php if ($vue_ens && $id_ens): $pl = (float) ($charges[$id_ens]['placees'] ?? 0); $du = $dues[$id_ens] ?? null; $at = (float) ($charges[$id_ens]['attribuees'] ?? 0); ?>
        <div class="small mt-2 <?= $du !== null && max($pl, $at) > $du ? 'text-danger fw-semibold' : 'text-muted' ?>"><?= h($nomEns) ?> : <?= $fmtH($at) ?> attribuées, <?= $fmtH($pl) ?> placées<?= $du !== null ? ' / ' . $fmtH($du) . ' dues' . (max($pl, $at) > $du ? ' ⚠' : '') : '' ?></div>
        <?php endif; ?>
        <?php if ($ecrire): ?>
        <hr class="my-2"><div class="small fw-bold mb-1">Nouveau cours</div>
        <?php if ($vue_ens): ?>
        <select id="libClasse" class="form-select form-select-sm mb-1" onchange="remplirMatieres()"><option value="">— Classe —</option><?php foreach ($classes as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['designation']) ?></option><?php endforeach; ?></select>
        <select id="libMat" class="form-select form-select-sm mb-1"><option value="">— Matière —</option></select>
        <input type="hidden" id="libEns" value="<?= $id_ens ?>">
        <?php else: ?>
        <select id="libMat" class="form-select form-select-sm mb-1"><option value="">— Matière —</option><?php foreach ($prog as $p): ?><option value="<?= $p['id_mat'] ?>"><?= h($p['libelle']) ?></option><?php endforeach; ?></select>
        <select id="libEns" class="form-select form-select-sm mb-1"><option value="">— Enseignant —</option><?php foreach ($ens as $e): ?><option value="<?= $e['matricule_ens'] ?>"><?= h($e['nom']) ?></option><?php endforeach; ?></select>
        <?php endif; ?>
        <button type="button" class="btn btn-sm btn-outline-primary w-100" onclick="choisirLibre()"><i class="bi bi-cursor"></i> Choisir ce cours</button>
        <div class="small text-muted mt-1">Il est ajouté aux cours de la classe dès la première heure placée.</div>
        <?php endif; ?>
        <div id="etat" class="small mt-2 text-primary"></div>
      </div></div>
  </div>
  <div class="col-xl-9 col-lg-8"><div id="grille"><?= $vue_ens ? edt_grille_html($a, 'enseignant', $id_ens, ['editable' => $ecrire]) : edt_grille_html($a, 'classe', $id_classe, ['editable' => $ecrire]) ?></div>
    <?php if ($ecrire): ?><div class="small text-muted mt-1"><i class="bi bi-mouse"></i> Clic ou clic droit sur un cours : retirer l'enseignant, changer d'enseignant ou de salle, supprimer une heure ou le cours, déplacer, verrouiller, cours commun.</div><?php endif; ?>
  </div>
</div>

<!-- Menu d'un cours (clic / clic droit) -->
<div id="menuCours" class="card shadow" style="position:fixed;z-index:1080;display:none;width:270px;font-size:.85rem">
  <div class="card-header py-1 px-2"><b id="mTitre">Cours</b><div class="small text-muted" id="mSous"></div></div>
  <div class="list-group list-group-flush">
    <button class="list-group-item list-group-item-action py-1" onclick="debutDeplacer()"><i class="bi bi-arrows-move me-1"></i>Déplacer</button>
    <div class="list-group-item py-1"><div class="small text-muted mb-1"><i class="bi bi-person me-1"></i>Enseignant</div>
      <div class="input-group input-group-sm"><select id="mEns" class="form-select"><?php foreach ($ens as $e): ?><option value="<?= $e['matricule_ens'] ?>"><?= h($e['nom']) ?></option><?php endforeach; ?></select><button class="btn btn-outline-primary" onclick="action('enseignant',{matricule_ens:document.getElementById('mEns').value})">Changer</button></div></div>
    <button class="list-group-item list-group-item-action py-1" id="mRetEns" onclick="if(confirm('Retirer l’enseignant de ce cours ? La matière reste placée.'))action('enseignant',{matricule_ens:0})"><i class="bi bi-person-dash me-1"></i>Retirer l'enseignant</button>
    <div class="list-group-item py-1"><div class="small text-muted mb-1"><i class="bi bi-geo-alt me-1"></i>Salle</div>
      <div class="input-group input-group-sm"><select id="mSalle" class="form-select"><option value="">— Aucune —</option><?php foreach ($salles as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['nom']) ?></option><?php endforeach; ?></select><button class="btn btn-outline-secondary" onclick="action('salle',{id_salle:document.getElementById('mSalle').value})">OK</button></div></div>
    <button class="list-group-item list-group-item-action py-1" id="mVerrou" onclick="action('verrou')"><i class="bi bi-lock me-1"></i>Verrouiller</button>
    <button class="list-group-item list-group-item-action py-1 text-danger" id="mSupH" onclick="supprimer(true)"><i class="bi bi-x-circle me-1"></i>Supprimer cette heure</button>
    <button class="list-group-item list-group-item-action py-1 text-danger" onclick="supprimer(false)"><i class="bi bi-trash me-1"></i><span id="mSupTout">Retirer la matière (supprimer le cours)</span></button>
    <div id="mCommunBloc">
      <button class="list-group-item list-group-item-action py-1" onclick="if(confirm('Cette classe suivra ce cours seule ? Les autres classes gardent le leur.'))action('decommun')"><i class="bi bi-people me-1"></i>Retirer cette classe du cours commun</button>
      <button class="list-group-item list-group-item-action py-1 text-danger" onclick="supprimer(false,true)"><i class="bi bi-trash3 me-1"></i>Supprimer dans toutes les classes communes</button>
    </div>
  </div>
</div>

<script>
const BASE=<?= json_encode($base) ?>, CSRF=<?= json_encode(csrf_generer()) ?>, CLASSE=<?= $id_classe ?>, ECRIRE=<?= $ecrire ? 'true' : 'false' ?>;
let choix=null, deplacer=null, idsCourants=null, idHeure=null;
const etat=t=>document.getElementById('etat').innerHTML=t||'';
const menu=document.getElementById('menuCours');
function nettoyer(){document.querySelectorAll('#grille td').forEach(td=>{td.classList.remove('edt-dispo-ok','edt-dispo-souhaitee','edt-dispo-conflit','edt-dispo-commun');if(td.dataset.t!==undefined){td.title=td.dataset.t;}});}
async function colorer(params){nettoyer();const r=await fetch(BASE+'ajax.php?action=dispo&classe='+CLASSE+'&duree=1&'+new URLSearchParams(params));const d=await r.json();if(!d.ok)return;
 document.querySelectorAll('#grille td[data-jour]').forEach(td=>{const c=d.cases[td.dataset.jour+'|'+td.dataset.cr];if(!c)return;if(td.dataset.t===undefined)td.dataset.t=td.title||'';
  td.classList.add('edt-dispo-'+c.etat);td.title=c.msg.length?c.msg.join('\n'):'Possible';});}
// Après chaque action : grille et compteurs rechargés, le cours choisi reste sélectionné
function rafraichir(){const u=new URL(location.href);if(CLASSE)u.searchParams.set('classe',CLASSE);
 if(choix&&choix.service)u.searchParams.set('choix',choix.service);else u.searchParams.delete('choix');location.href=u;}
document.getElementById('palette').addEventListener('click',e=>{const b=e.target.closest('[data-service]');if(!b)return;choisirService(b);});
function choisirService(b){document.querySelectorAll('#palette [data-service]').forEach(x=>x.classList.remove('active'));b.classList.add('active');
 choix={service:b.dataset.service};deplacer=null;etat('Cours choisi : cliquez une case (1 h).');colorer(choix);}
const PROG=<?= json_encode($progParClasse, JSON_UNESCAPED_UNICODE) ?>;
function remplirMatieres(){const c=document.getElementById('libClasse').value,s=document.getElementById('libMat');s.innerHTML='<option value="">— Matière —</option>';(PROG[c]||[]).forEach(([id,l])=>s.add(new Option(l,id)));}
function choisirLibre(){const m=document.getElementById('libMat').value;if(!m){etat('Choisissez une matière.');return;}
 const lc=document.getElementById('libClasse');if(lc&&!lc.value){etat('Choisissez la classe.');return;}
 document.querySelectorAll('#palette [data-service]').forEach(x=>x.classList.remove('active'));
 choix={mat:m,ens:document.getElementById('libEns').value};if(lc)choix.classe=lc.value;deplacer=null;etat('Nouveau cours choisi : cliquez une case (1 h).');colorer(choix);}
async function poster(action,data){const fd=new FormData();fd.append('action',action);fd.append('csrf',CSRF);fd.append('classe',CLASSE);
 for(const k in data)fd.append(k,data[k]);const r=await fetch(BASE+'ajax.php',{method:'POST',body:fd});return r.json();}
async function avecConfirmation(action,data){let d=await poster(action,data);
 if(!d.ok&&d.commun){if(!confirm(d.msg+'\n\nOK = oui, cours commun (une seule séance pour ces classes).'))return d;data={...data,commun:1};d=await poster(action,data);}
 if(!d.ok&&d.forcable&&confirm('Attention :\n- '+d.problemes.join('\n- ')+'\n\nPlacer quand même ? (le problème restera signalé dans l’onglet Contrôles)'))d=await poster(action,{...data,forcer:1});
 else if(!d.ok&&d.problemes&&d.problemes.length&&!d.forcable)alert('Impossible :\n- '+d.problemes.join('\n- '));
 else if(!d.ok&&d.msg&&!d.commun)alert(d.msg);
 return d;}
async function annulerDerniere(){const d=await poster('annuler',{});if(d.ok)rafraichir();else if(d.msg)alert(d.msg);}
// Glisser-déposer : on attrape un cours et on le lâche sur sa nouvelle case de début
if(ECRIRE){
 document.querySelectorAll('#grille .edt-bloc[data-ids]').forEach(b=>{b.draggable=true;
  b.addEventListener('dragstart',e=>{fermerMenu();deplacer=b.dataset.ids;choix=null;e.dataTransfer.effectAllowed='move';e.dataTransfer.setData('text/plain',deplacer);
   etat('Déposez le cours sur sa nouvelle case ('+deplacer.split(',').length+' h). Échap pour abandonner.');colorer({deplace:deplacer,ignorer:deplacer,duree:deplacer.split(',').length});});});
 const grille=document.getElementById('grille');
 grille.addEventListener('dragover',e=>{const td=e.target.closest('td[data-jour]');if(td&&deplacer){e.preventDefault();e.dataTransfer.dropEffect='move';}});
 grille.addEventListener('drop',async e=>{const td=e.target.closest('td[data-jour]');if(!td||!deplacer)return;e.preventDefault();
  const d=await avecConfirmation('deplacer',{ids:deplacer,jour:td.dataset.jour,cr:td.dataset.cr});if(d.ok)rafraichir();else{deplacer=null;nettoyer();etat('');}});
}
function fermerMenu(){menu.style.display='none';}
function ouvrirMenu(bloc,e){
 e.preventDefault();idsCourants=bloc.dataset.ids;const ids=idsCourants.split(',');
 // heure visée dans un bloc de plusieurs heures : selon la position du clic
 const r=bloc.getBoundingClientRect();idHeure=ids[Math.min(ids.length-1,Math.max(0,Math.floor((e.clientX-r.left)/(r.width/ids.length))))];
 document.getElementById('mTitre').textContent=bloc.querySelector('.edt-mat').textContent+(ids.length>1?' ('+ids.length+' h)':' (1 h)');
 document.getElementById('mSous').textContent=bloc.querySelector('.edt-sous').textContent;
 document.getElementById('mEns').value=bloc.dataset.ens;document.getElementById('mSalle').value=+bloc.dataset.salle||'';
 document.getElementById('mRetEns').style.display=+bloc.dataset.ens?'':'none';
 document.getElementById('mSupH').style.display=ids.length>1?'':'none';
 document.getElementById('mSupTout').textContent=ids.length>1?'Retirer la matière (supprimer les '+ids.length+' h)':'Retirer la matière (supprimer le cours)';
 document.getElementById('mVerrou').innerHTML='<i class="bi bi-'+(+bloc.dataset.verrou?'unlock':'lock')+' me-1"></i>'+(+bloc.dataset.verrou?'Déverrouiller':'Verrouiller');
 document.getElementById('mCommunBloc').style.display=+bloc.dataset.commun?'':'none';
 menu.style.display='block';const w=menu.offsetWidth,h=menu.offsetHeight;
 menu.style.left=Math.min(e.clientX,innerWidth-w-8)+'px';menu.style.top=Math.max(8,Math.min(e.clientY,innerHeight-h-8))+'px';}
document.addEventListener('click',e=>{if(!menu.contains(e.target)&&!e.target.closest('.edt-bloc[data-ids]'))fermerMenu();});
document.addEventListener('keydown',e=>{if(e.key==='Escape'){fermerMenu();if(deplacer){deplacer=null;nettoyer();etat('');}}});
document.getElementById('grille').addEventListener('contextmenu',e=>{if(!ECRIRE)return;const bloc=e.target.closest('.edt-bloc[data-ids]');if(bloc&&!deplacer)ouvrirMenu(bloc,e);});
document.getElementById('grille').addEventListener('click',async e=>{if(!ECRIRE)return;
 const bloc=e.target.closest('.edt-bloc[data-ids]');
 if(bloc&&!deplacer){ouvrirMenu(bloc,e);return;}
 const td=e.target.closest('td[data-jour]');if(!td)return;
 if(deplacer){const d=await avecConfirmation('deplacer',{ids:deplacer,jour:td.dataset.jour,cr:td.dataset.cr});if(d.ok)rafraichir();return;}
 if(!choix){etat('Choisissez d’abord un cours à gauche.');return;}
 if(!td.classList.contains('edt-vide')&&!td.classList.contains('edt-bloque'))return;
 const d=await avecConfirmation('placer',{...choix,jour:td.dataset.jour,cr:td.dataset.cr});if(d.ok){if(d.service)choix={service:d.service};rafraichir();}});
async function action(a,extra={}){fermerMenu();const d=await avecConfirmation(a,{ids:idsCourants,...extra});if(d.ok)rafraichir();}
function supprimer(uneHeure,partout=false){
 const n=idsCourants.split(',').length;
 if(!confirm(uneHeure?'Supprimer cette heure ?':(partout?'Supprimer ce cours dans toutes les classes communes ?':(n>1?'Supprimer ce cours ('+n+' h) ?':'Supprimer ce cours ?'))))return;
 fermerMenu();poster('supprimer',{ids:uneHeure?idHeure:idsCourants,commun:partout?1:0}).then(d=>{if(d.ok)rafraichir();else if(d.msg)alert(d.msg);});}
function debutDeplacer(){deplacer=idsCourants;choix=null;fermerMenu();
 const n=deplacer.split(',').length;etat('Déplacement : cliquez la nouvelle case de début ('+n+' h). <a href="#" onclick="location.reload()">Annuler</a> (ou Échap)');
 colorer({deplace:deplacer,ignorer:deplacer,duree:n});}
// cours choisi avant le rechargement
<?php $choix0 = (int) ($_GET['choix'] ?? 0); if ($choix0): ?>
{const b=document.querySelector('#palette [data-service="<?= $choix0 ?>"]');if(b)choisirService(b);}
<?php endif; ?>
</script>
<?php require_once __DIR__ . '/../../../../noyau/layout/footer.php'; ?>
