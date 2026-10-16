<?php
// secondaire/commun/pages/emploi_temps/saisie.php — saisie manuelle d'un emploi du temps
// de classe. On choisit un service (matière + enseignant) : la grille se colore
// (vert = possible, orange = règle souhaitée non respectée, rouge = conflit ou
// règle obligatoire), puis un clic place la séance. Clic sur une séance :
// déplacer, verrouiller, changer de salle, supprimer.
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
require_once __DIR__ . '/_edt_lib.php';
exiger_role(EDT_ROLES_GESTION);

$a = edt_annee_id();
$classes = edt_classes();
$id_classe = (int) ($_GET['classe'] ?? ($classes[0]['id'] ?? 0));
$ecrire = edt_peut_ecrire();
$services = edt_services($a, $id_classe);
$prog = edt_programme_classe($id_classe);
$ens = edt_enseignants();
$salles = edt_salles();
$titre_page = 'Emploi du temps — Construction';
require_once __DIR__ . '/../../../../noyau/layout/header.php';
echo edt_css();
$base = APP_URL . '/secondaire/commun/pages/emploi_temps/';
?>
<?= edt_onglets('construction', 'saisie') ?>
<?= flash_html() ?>
<div class="card mb-3"><div class="card-body py-2 d-flex flex-wrap gap-2 align-items-center">
  <form method="get" class="m-0 d-flex gap-2 align-items-center"><label class="fw-bold">Classe</label>
    <select class="form-select form-select-sm w-auto" name="classe" onchange="this.form.submit()"><?php foreach ($classes as $c): ?><option value="<?= $c['id'] ?>" <?= (int) $c['id'] === $id_classe ? 'selected' : '' ?>><?= h($c['designation']) ?></option><?php endforeach; ?></select></form>
  <a class="btn btn-sm btn-outline-primary" href="consulter.php?vue=classe&id=<?= $id_classe ?>"><i class="bi bi-eye"></i> Consulter</a>
  <a class="btn btn-sm btn-outline-primary" target="_blank" href="pdf.php?vue=classe&id=<?= $id_classe ?>"><i class="bi bi-file-earmark-pdf"></i> PDF</a>
  <span class="small ms-auto"><span class="badge edt-dispo-ok text-dark border">possible</span> <span class="badge edt-dispo-souhaitee text-dark border">règle souhaitée non respectée</span> <span class="badge edt-dispo-conflit text-dark border">conflit / règle obligatoire</span></span>
</div></div>
<?php if (!$ecrire): ?><div class="alert alert-light border small">Consultation seule : la modification est réservée au Censeur, au Proviseur et à l'administrateur.</div><?php endif; ?>

<div class="row g-3">
  <div class="col-xl-3 col-lg-4 no-print">
    <div class="card"><div class="card-header py-2"><b>Cours à placer</b><div class="small text-muted">Cliquez un cours, puis une case libre.</div></div>
      <div class="card-body p-2">
        <div class="d-flex align-items-center gap-2 mb-2"><label class="small">Durée de la séance</label>
          <select id="duree" class="form-select form-select-sm w-auto"><?php for ($d = 1; $d <= 6; $d++): ?><option value="<?= $d ?>" <?= $d === 2 ? 'selected' : '' ?>><?= $d ?> h</option><?php endfor; ?></select></div>
        <div class="list-group list-group-flush small" id="palette">
        <?php foreach ($services as $s): $reste = (int) round((float) $s['heures']) - (int) $s['places']; ?>
          <button type="button" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center px-2 py-1" data-service="<?= $s['id'] ?>" data-dmax="<?= (int) $s['duree_max'] ?>" style="border-left:6px solid <?= edt_couleur((int) $s['id_mat']) ?>">
            <span><b><?= h($s['matiere']) ?></b><br><span class="text-muted"><?= h($s['enseignant'] ?: '— sans enseignant —') ?><?= $s['code_commun'] ? ' · commun' : '' ?></span></span>
            <span class="badge <?= $reste > 0 ? 'bg-warning text-dark' : ($reste < 0 ? 'bg-danger' : 'bg-success') ?>" title="Heures restant à placer"><?= $reste > 0 ? "reste $reste h" : ($reste < 0 ? 'trop : ' . (-$reste) . ' h' : 'complet') ?></span>
          </button>
        <?php endforeach; ?>
        <?php if (!$services): ?><div class="text-muted p-2">Aucun service pour cette classe. <a href="services.php?classe=<?= $id_classe ?>">Saisir les services</a> ou utilisez « Cours libre ».</div><?php endif; ?>
        </div>
        <?php if ($ecrire): ?>
        <hr class="my-2"><div class="small fw-bold mb-1">Cours libre (hors service)</div>
        <select id="libMat" class="form-select form-select-sm mb-1"><option value="">— Matière —</option><?php foreach ($prog as $p): ?><option value="<?= $p['id_mat'] ?>"><?= h($p['libelle']) ?></option><?php endforeach; ?></select>
        <select id="libEns" class="form-select form-select-sm mb-1"><option value="">— Enseignant —</option><?php foreach ($ens as $e): ?><option value="<?= $e['matricule_ens'] ?>"><?= h($e['nom']) ?></option><?php endforeach; ?></select>
        <button type="button" class="btn btn-sm btn-outline-primary w-100" onclick="choisirLibre()"><i class="bi bi-cursor"></i> Choisir ce cours</button>
        <?php endif; ?>
        <div id="etat" class="small mt-2 text-primary"></div>
      </div></div>
  </div>
  <div class="col-xl-9 col-lg-8"><div id="grille"><?= edt_grille_html($a, 'classe', $id_classe, ['editable' => $ecrire]) ?></div></div>
</div>

<!-- Fenêtre d'une séance -->
<div class="modal fade" id="mSeance" tabindex="-1"><div class="modal-dialog modal-sm modal-dialog-centered"><div class="modal-content">
  <div class="modal-header py-2"><h6 class="modal-title" id="mTitre">Séance</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body d-grid gap-2">
    <button class="btn btn-sm btn-outline-primary" onclick="debutDeplacer()"><i class="bi bi-arrows-move"></i> Déplacer</button>
    <button class="btn btn-sm btn-outline-secondary" onclick="action('verrou')"><i class="bi bi-lock"></i> Verrouiller / déverrouiller</button>
    <div class="input-group input-group-sm"><select id="mSalle" class="form-select"><option value="">— Salle —</option><?php foreach ($salles as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['nom']) ?></option><?php endforeach; ?></select><button class="btn btn-outline-secondary" onclick="action('salle',{id_salle:document.getElementById('mSalle').value})">OK</button></div>
    <button class="btn btn-sm btn-outline-danger" onclick="action('supprimer')"><i class="bi bi-trash"></i> Supprimer</button>
    <div class="form-check small"><input class="form-check-input" type="checkbox" id="mCommun"><label class="form-check-label" for="mCommun">si cours commun : supprimer dans toutes les classes</label></div>
  </div></div></div></div>

<script>
const BASE=<?= json_encode($base) ?>, CSRF=<?= json_encode(csrf_generer()) ?>, CLASSE=<?= $id_classe ?>, ECRIRE=<?= $ecrire ? 'true' : 'false' ?>;
let choix=null, deplacer=null, idsCourants=null;
const etat=t=>document.getElementById('etat').innerHTML=t||'';
function nettoyer(){document.querySelectorAll('#grille td').forEach(td=>{td.classList.remove('edt-dispo-ok','edt-dispo-souhaitee','edt-dispo-conflit');if(td.dataset.t!==undefined){td.title=td.dataset.t;}});}
async function colorer(params){nettoyer();const r=await fetch(BASE+'ajax.php?action=dispo&classe='+CLASSE+'&'+new URLSearchParams(params));const d=await r.json();if(!d.ok)return;
 document.querySelectorAll('#grille td[data-jour]').forEach(td=>{const c=d.cases[td.dataset.jour+'|'+td.dataset.cr];if(!c)return;if(td.dataset.t===undefined)td.dataset.t=td.title||'';
  td.classList.add('edt-dispo-'+c.etat);td.title=c.msg.length?c.msg.join('\n'):'Possible';});}
function rafraichirPalette(){location.reload();}
document.getElementById('palette').addEventListener('click',e=>{const b=e.target.closest('[data-service]');if(!b)return;
 document.querySelectorAll('#palette [data-service]').forEach(x=>x.classList.remove('active'));b.classList.add('active');
 const dmax=+b.dataset.dmax||2;const sel=document.getElementById('duree');if(+sel.value>dmax)sel.value=dmax;
 choix={service:b.dataset.service};deplacer=null;etat('Cours choisi : cliquez une case verte.');colorer({...choix,duree:sel.value});});
document.getElementById('duree').addEventListener('change',()=>{if(choix)colorer({...choix,duree:document.getElementById('duree').value});});
function choisirLibre(){const m=document.getElementById('libMat').value;if(!m){etat('Choisissez une matière.');return;}
 choix={mat:m,ens:document.getElementById('libEns').value};deplacer=null;etat('Cours libre choisi : cliquez une case.');colorer({...choix,duree:document.getElementById('duree').value});}
async function poster(action,data){const fd=new FormData();fd.append('action',action);fd.append('csrf',CSRF);fd.append('classe',CLASSE);
 for(const k in data)fd.append(k,data[k]);const r=await fetch(BASE+'ajax.php',{method:'POST',body:fd});return r.json();}
async function avecConfirmation(action,data){let d=await poster(action,data);
 if(!d.ok&&d.forcable&&confirm('Attention :\n- '+d.problemes.join('\n- ')+'\n\nPlacer quand même ? (le problème restera signalé dans l’onglet Contrôles)'))d=await poster(action,{...data,forcer:1});
 else if(!d.ok&&d.problemes&&d.problemes.length&&!d.forcable)alert('Impossible :\n- '+d.problemes.join('\n- '));
 else if(!d.ok&&d.msg)alert(d.msg);
 return d;}
document.getElementById('grille').addEventListener('click',async e=>{if(!ECRIRE)return;
 const bloc=e.target.closest('.edt-bloc[data-ids]');
 if(bloc&&!deplacer){idsCourants=bloc.dataset.ids;document.getElementById('mTitre').textContent=bloc.querySelector('.edt-mat').textContent;new bootstrap.Modal('#mSeance').show();return;}
 const td=e.target.closest('td[data-jour]');if(!td)return;
 if(deplacer){const d=await avecConfirmation('deplacer',{ids:deplacer,jour:td.dataset.jour,cr:td.dataset.cr});if(d.ok)rafraichirPalette();return;}
 if(!choix||!td.classList.contains('edt-vide')&&!td.classList.contains('edt-bloque'))return;
 const d=await avecConfirmation('placer',{...choix,jour:td.dataset.jour,cr:td.dataset.cr,duree:document.getElementById('duree').value});if(d.ok)rafraichirPalette();});
async function action(a,extra={}){const data={ids:idsCourants,...extra};if(a==='supprimer'){if(!confirm('Supprimer cette séance ?'))return;data.commun=document.getElementById('mCommun').checked?1:0;}
 const d=await avecConfirmation(a,data);if(d.ok)rafraichirPalette();}
function debutDeplacer(){deplacer=idsCourants;choix=null;bootstrap.Modal.getInstance('#mSeance').hide();
 const n=deplacer.split(',').length;etat('Déplacement : cliquez la nouvelle case de début ('+n+' h). <a href="#" onclick="location.reload()">Annuler</a>');
 // colore les positions possibles pour la séance déplacée
 colorerDeplacement();}
function colorerDeplacement(){colorer({deplace:deplacer,ignorer:deplacer,duree:deplacer.split(',').length});}
</script>
<?php require_once __DIR__ . '/../../../../noyau/layout/footer.php'; ?>
