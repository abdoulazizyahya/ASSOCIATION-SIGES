<?php
// secondaire/commun/pages/communication/envoyer.php — messages aux parents
// par SMS (forfait de la puce du téléphone de l'école) ou WhatsApp
// (10/11/2026), commun au primaire et au secondaire.
//
// Aucun service payant : la page prépare les messages, et c'est le téléphone
// qui les envoie (application Messages ou WhatsApp, ouverte avec le numéro et
// le texte déjà remplis) :
//  - message identique pour tous → SMS GROUPÉ par lots de numéros ;
//  - message personnalisé (reste à payer, moyenne, absences) → envoi EN
//    SÉRIE : un appui ouvre le message du parent suivant.
// Numéros : contacts_parents_lot() (fiche, père, mère, tuteur, adresse).
// Prévu pour l'application mobile / le navigateur du téléphone de l'école.
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';

$secondaire = type_enseignement_courant() === 'secondaire';
exiger_role($secondaire ? ['ADMIN', 'PROVISEUR', 'FONDATEUR', 'CENSEUR', 'SG', 'SECRETAIRE', 'INTENDANT'] : ['DIRECTEUR', 'SECRETAIRE', 'COMPTABLE', 'FONDATEUR']);
if (!$secondaire) require_once __DIR__ . '/../../../../primaire/notes_apc.php';

$annee = get_annee_active();
$etab  = etab_pour_pdf(get_etablissement());
$ecole = trim((string) (($etab['sigle'] ?? '') ?: ($etab['nom_fr'] ?? APP_NOM)));

const COMM_TYPES = [
    'libre'     => ['Message libre (même texte pour tous)', 'chat-text'],
    'paiement'  => ['État de paiement (reste à payer)',      'cash-coin'],
    'moyenne'   => ['Moyenne du trimestre',                  'mortarboard'],
    'absences'  => ['Absences non justifiées du trimestre',  'calendar-x'],
];
$type      = array_key_exists($_GET['type'] ?? '', COMM_TYPES) ? $_GET['type'] : 'libre';
$id_classe = (int) ($_GET['classe'] ?? 0);   // 0 = toutes les classes
$tous_nums = ($_GET['nums'] ?? 'premier') === 'tous';
$seuil     = (float) str_replace(',', '.', (string) ($_GET['seuil'] ?? ''));
$genere    = isset($_GET['generer']);

// ── Classes et trimestres de l'année active ──
$id_annee = 0; $lib_annee = ''; $val_annee = '';
if ($secondaire) {
    $id_annee = (int) ($annee['id'] ?? 0);
    $lib_annee = (string) ($annee['libelle'] ?? '');
    $classes = db_all("SELECT c.id, c.designation AS libelle, c.code_niveau FROM classe c
                       WHERE EXISTS (SELECT 1 FROM inscription i WHERE i.id_classe=c.id AND i.id_annee=?)
                       ORDER BY c.libelle_section, c.ordre, c.designation", [$id_annee]);
    $trimestres = db_all("SELECT id, libelle FROM trimestre WHERE id_annee=? ORDER BY ordre", [$id_annee]);
} else {
    $val_annee = (string) ($annee['val_annee'] ?? '');
    $classes = db_all("SELECT c.IDClasses AS id, c.DesignationClasses AS libelle FROM classe c
                       WHERE EXISTS (SELECT 1 FROM inscrire i WHERE i.IDClasses=c.IDClasses AND i.val_annee=?)
                       ORDER BY c.DesignationClasses", [$val_annee]);
    $trimestres = db_all("SELECT id_trim AS id, libelle_trim AS libelle FROM trimestre WHERE id_annee=? ORDER BY id_trim", [$val_annee]);
}
$id_trim = (int) ($_GET['trim'] ?? 0);
if (!$id_trim && $trimestres) {
    $actif = $secondaire ? db_val("SELECT id FROM trimestre WHERE id_annee=? AND active=1 LIMIT 1", [$id_annee]) : null;
    $id_trim = (int) ($actif ?: $trimestres[0]['id']);
}
$lib_trim = '';
foreach ($trimestres as $t) if ((int) $t['id'] === $id_trim) $lib_trim = (string) $t['libelle'];

// Modèles par défaut : sans ê, ô, î, â, ç (ils font passer le SMS de 160 à 70 caractères).
$modeles = [
    'libre'    => "{ecole} : Chers parents, ",
    'paiement' => "{ecole} : Bonjour, il reste {reste} FCFA a payer pour la scolarite de {eleve} ({classe}). Merci de regulariser rapidement.",
    'moyenne'  => "{ecole} : {eleve} ({classe}) a obtenu {moyenne}/20 au {trimestre}, rang {rang}/{effectif}. {appreciation}.",
    'absences' => "{ecole} : {eleve} ({classe}) totalise {absences} d'absence non justifiee au {trimestre}. Merci de nous contacter.",
];
$modele = trim((string) ($_GET['modele'] ?? ''));
if ($modele === '' || !isset($_GET['modele_type']) || $_GET['modele_type'] !== $type) $modele = $modeles[$type];
$seuil_defaut = ['moyenne' => '', 'absences' => '1', 'paiement' => '', 'libre' => ''][$type];
if (!isset($_GET['seuil'])) $seuil = (float) $seuil_defaut;

// ── Élèves d'une classe ──
$eleves_de = function (int $cl) use ($secondaire, $id_annee, $val_annee): array {
    if ($secondaire) {
        return db_all("SELECT e.id, e.nom, e.prenom, e.matricule FROM eleve e
                       JOIN inscription i ON i.id_eleve=e.id AND i.id_classe=? AND i.id_annee=? WHERE e.statut='actif' ORDER BY e.nom, e.prenom", [$cl, $id_annee]);
    }
    return array_map(fn($r) => ['id' => (int) $r['id_eleve'], 'nom' => $r['Nom_elv'], 'prenom' => $r['Prenom_elv'], 'matricule' => $r['Mat_elv']],
        db_all("SELECT e.id_eleve, e.Nom_elv, e.Prenom_elv, e.Mat_elv FROM eleve e
                JOIN inscrire i ON i.id_eleve=e.id_eleve AND i.IDClasses=? AND i.val_annee=? WHERE e.statut='actif' ORDER BY e.Nom_elv, e.Prenom_elv", [$cl, $val_annee]));
};
$fmt_f = fn(float $v) => number_format($v, 0, ',', ' ');
$fmt_n = fn(?float $v) => $v === null ? '-' : rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',');

// ── Construction des destinataires ──
$lignes = []; $ignores = 0; $sans_num = 0;
if ($genere) {
    $cibles = $id_classe ? array_values(array_filter($classes, fn($c) => (int) $c['id'] === $id_classe)) : $classes;
    foreach ($cibles as $cl) {
        $eleves = $eleves_de((int) $cl['id']);
        if (!$eleves) continue;
        $ids = array_column($eleves, 'id');
        $contacts = contacts_parents_lot($ids);
        $extra = [];   // valeurs propres au type, par élève

        if ($type === 'paiement') {
            if ($secondaire) {
                foreach ($eleves as $e) {
                    $du = 0.0; $paye = 0.0;
                    foreach (eleve_obligations_annee((int) $e['id'], (string) $cl['code_niveau'], $id_annee) as $o) { $du += (float) $o['montant']; $paye += (float) $o['paye']; }
                    $extra[(int) $e['id']] = ['du' => $du, 'paye' => $paye, 'reste' => max(0.0, $du - $paye)];
                }
            } else {
                $paye_p = [];
                foreach (db_all("SELECT id_eleve, SUM(montant_paiement) AS p FROM paiement_frais WHERE val_annee=? GROUP BY id_eleve", [$val_annee]) as $r) $paye_p[(int) $r['id_eleve']] = (float) $r['p'];
                foreach (finances_du_par_eleve($val_annee, (int) $cl['id'], null) as $r) {
                    $p = $paye_p[(int) $r['id_eleve']] ?? 0.0;
                    $extra[(int) $r['id_eleve']] = ['du' => (float) $r['du'], 'paye' => $p, 'reste' => max(0.0, (float) $r['du'] - $p)];
                }
            }
        } elseif ($type === 'moyenne' && $id_trim) {
            if ($secondaire) {
                $moys = est_technique() ? [] : calc_moys_classe_periode_comp((int) $cl['id'], $id_annee, 'trimestre', $id_trim, $ids);
            } else {
                $moys = [];
                foreach ($ids as $eid) { $m = calculer_moyenne_trimestre_eleve((int) $eid, $id_trim, (int) $cl['id'], $val_annee); if ($m !== null) $moys[(int) $eid] = $m; }
            }
            arsort($moys); $rg = 0;
            foreach ($moys as $eid => $m) $extra[(int) $eid] = ['moyenne' => (float) $m, 'rang' => ++$rg, 'effectif' => count($moys)];
        } elseif ($type === 'absences' && $id_trim) {
            if ($secondaire) {
                precharger_absences_trimestre((int) $cl['id'], $lib_annee, $id_trim);
                foreach ($eleves as $e) $extra[(int) $e['id']] = ['absences' => (float) eleve_absence_trimestre((string) $e['matricule'], $id_trim, (int) $cl['id'], $lib_annee)['non_jus'], 'unite' => 'h'];
            } else {
                precharger_absences_classe_trim((int) $cl['id'], $id_trim, $val_annee);
                foreach ($eleves as $e) $extra[(int) $e['id']] = ['absences' => jours_absence_non_justifiees_trimestre((int) $e['id'], (int) $cl['id'], $id_trim, $val_annee), 'unite' => 'j'];
            }
        }

        foreach ($eleves as $e) {
            $id = (int) $e['id'];
            $x = $extra[$id] ?? null;
            // Filtres : rien à dire à ce parent ?
            if ($type === 'paiement' && (!$x || $x['reste'] <= 0.5)) { $ignores++; continue; }
            if ($type === 'moyenne' && (!$x || ($seuil > 0 && $x['moyenne'] >= $seuil))) { $ignores++; continue; }
            if ($type === 'absences' && (!$x || $x['absences'] < max(0.01, $seuil))) { $ignores++; continue; }
            $nums = array_column($contacts[$id] ?? [], 'tel');
            if (!$nums) { $sans_num++; continue; }
            if (!$tous_nums) $nums = [$nums[0]];
            $appr = '';
            if ($type === 'moyenne' && $x) {
                if ($secondaire) $appr = appreciation_moyenne_apc($x['moyenne'])['fr'] ?? '';
                else $appr = ['NA' => 'Competences non acquises', 'ECA' => "En cours d'acquisition", 'A' => 'Competences acquises', 'A+' => 'Competences tres bien acquises'][appreciation_moyenne($x['moyenne'])] ?? '';
            }
            $vars = [
                '{ecole}'        => $ecole,
                '{eleve}'        => trim(mb_strtoupper((string) $e['nom']) . ' ' . ($e['prenom'] ?? '')),
                '{prenom}'       => trim((string) ($e['prenom'] ?? '')) ?: (string) $e['nom'],
                '{classe}'       => (string) $cl['libelle'],
                '{trimestre}'    => $lib_trim,
                '{reste}'        => $x && isset($x['reste']) ? $fmt_f($x['reste']) : '',
                '{paye}'         => $x && isset($x['paye']) ? $fmt_f($x['paye']) : '',
                '{du}'           => $x && isset($x['du']) ? $fmt_f($x['du']) : '',
                '{moyenne}'      => $x && isset($x['moyenne']) ? $fmt_n($x['moyenne']) : '',
                '{rang}'         => $x && isset($x['rang']) ? (string) $x['rang'] : '',
                '{effectif}'     => $x && isset($x['effectif']) ? (string) $x['effectif'] : '',
                '{appreciation}' => $appr,
                '{absences}'     => $x && isset($x['absences']) ? $fmt_n($x['absences']) . ($x['unite'] === 'h' ? ' h' : ' jour(s)') : '',
                '{date}'         => date('d/m/Y'),
            ];
            $lignes[] = ['eleve' => $vars['{eleve}'], 'classe' => $cl['libelle'], 'nums' => $nums,
                         'qui' => ($contacts[$id][0]['qui'] ?? ''), 'texte' => strtr($modele, $vars)];
        }
    }
    journaliser_action('communication_parents', null, $type . ' — ' . count($lignes) . ' destinataire(s)' . ($id_classe ? '' : ', toutes les classes'));
}
// Message identique pour tous (aucune variable propre à l'élève) → SMS groupé possible.
$identique = $genere && $lignes && count(array_unique(array_column($lignes, 'texte'))) === 1;
$nums_uniques = [];
foreach ($lignes as $l) foreach ($l['nums'] as $n) $nums_uniques[$n] = $n;
$nums_uniques = array_values($nums_uniques);
$cle_campagne = 'comm_' . md5($type . '|' . $id_classe . '|' . $id_trim . '|' . $modele . '|' . date('Y-m-d'));

$titre_page = 'Envoyer aux parents';
require_once __DIR__ . '/../../../../noyau/layout/header.php';
?>
<style>
.comm-ligne.fait { opacity:.5; }
.comm-ligne.fait .comm-texte { text-decoration: line-through; }
.comm-texte { font-size:.8rem; white-space:pre-wrap; background:#f8fafc; border-radius:6px; padding:4px 8px; }
.comm-btn { min-width:42px; }
</style>
<div class="page-titre">
  <h4><i class="bi bi-send me-1 text-primary"></i>Envoyer aux parents — SMS / WhatsApp</h4>
  <div class="sub">Les messages partent du <strong>téléphone de l'école</strong> (forfait SMS de la puce, ou WhatsApp) : ouvrez cette page sur ce téléphone, dans l'application SIGES ou le navigateur.</div>
</div>

<?= flash_html() ?>

<form method="get" class="card mb-3">
  <div class="card-body">
    <input type="hidden" name="generer" value="1">
    <input type="hidden" name="modele_type" value="<?= h($type) ?>">
    <div class="row g-2">
      <div class="col-md-4">
        <label class="form-label">Type de message</label>
        <select name="type" class="form-select form-select-sm" onchange="this.form.modele.value='';this.form.generer.disabled=true;this.form.submit()">
          <?php foreach (COMM_TYPES as $k => [$lib]): ?><option value="<?= $k ?>" <?= $type === $k ? 'selected' : '' ?>><?= h($lib) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Classe</label>
        <select name="classe" class="form-select form-select-sm">
          <option value="0">Toutes les classes</option>
          <?php foreach ($classes as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $id_classe ? 'selected' : '' ?>><?= h($c['libelle']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <?php if (in_array($type, ['moyenne', 'absences'], true)): ?>
      <div class="col-md-2">
        <label class="form-label">Trimestre</label>
        <select name="trim" class="form-select form-select-sm">
          <?php foreach ($trimestres as $t): ?><option value="<?= (int) $t['id'] ?>" <?= (int) $t['id'] === $id_trim ? 'selected' : '' ?>><?= h($t['libelle']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label"><?= $type === 'moyenne' ? 'Seulement moyenne inférieure à (vide = tous)' : 'Au moins (' . ($secondaire ? 'heures' : 'jours') . ' non justifiés)' ?></label>
        <input type="text" name="seuil" class="form-control form-control-sm" value="<?= $seuil > 0 ? h($fmt_n($seuil)) : '' ?>" placeholder="<?= $type === 'moyenne' ? 'ex. 10' : '1' ?>">
      </div>
      <?php endif; ?>
      <div class="col-md-12">
        <label class="form-label d-flex justify-content-between">
          <span>Message <span class="text-muted" style="font-size:.74rem">— variables : {eleve} {prenom} {classe} {ecole} {trimestre} {reste} {paye} {du} {moyenne} {rang} {effectif} {appreciation} {absences} {date}</span></span>
          <span id="compteur" class="text-muted" style="font-size:.76rem"></span>
        </label>
        <textarea name="modele" id="modele" rows="3" class="form-control form-control-sm"><?= h($modele) ?></textarea>
        <div id="alerte-gsm" class="form-text text-warning" style="display:none"><i class="bi bi-exclamation-triangle me-1"></i>Caractères spéciaux (ex. ê, ô, ç, apostrophe typographique) : un SMS ne contient plus que 70 caractères — le coût double ou triple.</div>
      </div>
      <div class="col-md-5">
        <label class="form-label">Numéros</label>
        <select name="nums" class="form-select form-select-sm">
          <option value="premier" <?= !$tous_nums ? 'selected' : '' ?>>Le premier numéro trouvé (père, sinon mère, sinon tuteur…)</option>
          <option value="tous" <?= $tous_nums ? 'selected' : '' ?>>Tous les numéros de chaque élève</option>
        </select>
      </div>
      <div class="col-md-7 d-flex align-items-end gap-2">
        <button class="btn btn-primary btn-sm"><i class="bi bi-list-check me-1"></i>Préparer les messages</button>
        <a href="<?= APP_URL ?>/secondaire/commun/pages/communication/contacts_parents.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-telephone me-1"></i>Contacts des parents</a>
      </div>
    </div>
  </div>
</form>

<?php if ($genere): ?>
<div class="alert alert-light border py-2" style="font-size:.84rem">
  <strong><?= count($lignes) ?></strong> message(s) à envoyer
  <?php if ($ignores): ?> · <?= $ignores ?> élève(s) non concerné(s)<?php endif; ?>
  <?php if ($sans_num): ?> · <span class="text-danger"><?= $sans_num ?> élève(s) sans numéro</span> (<a href="<?= APP_URL ?>/secondaire/commun/pages/communication/contacts_parents.php?classe=<?= $id_classe ?>&filtre=sans">compléter</a>)<?php endif; ?>
  <?php if ($type === 'moyenne' && $secondaire && est_technique()): ?><div class="text-danger mt-1">Moyennes non disponibles ici pour l'enseignement technique.</div><?php endif; ?>
</div>

<?php if ($lignes): ?>
<?php if ($identique): ?>
<!-- Message identique : SMS groupé par lots -->
<div class="card mb-3">
  <div class="card-body">
    <div class="fw-semibold mb-1"><i class="bi bi-people me-1"></i>SMS groupé — <?= count($nums_uniques) ?> numéro(s) différent(s)</div>
    <div class="d-flex flex-wrap align-items-center gap-2 mb-2" style="font-size:.8rem">
      <label>Numéros par envoi <input type="number" id="taille-lot" class="form-control form-control-sm d-inline-block" style="width:80px" min="1" max="100" value="20"></label>
      <label>Séparateur <select id="separateur" class="form-select form-select-sm d-inline-block" style="width:90px"><option value=",">virgule</option><option value=";">point-virgule</option></select></label>
      <span class="text-muted">Si l'application Messages ne prend que le 1er numéro, essayez l'autre séparateur. Réglage Android conseillé : messages de groupe envoyés en <em>SMS individuels</em>, pas en MMS.</span>
    </div>
    <div id="lots" class="d-flex flex-wrap gap-2"></div>
    <button type="button" class="btn btn-outline-secondary btn-sm mt-2" onclick="copierNumeros()"><i class="bi bi-clipboard me-1"></i>Copier tous les numéros</button>
  </div>
</div>
<?php endif; ?>

<!-- Envoi en série (un message par parent) -->
<div class="card">
  <div class="card-body">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
      <div class="fw-semibold"><i class="bi bi-collection me-1"></i>Envoi en série <span class="text-muted fw-normal" id="avancement" style="font-size:.8rem"></span></div>
      <div class="d-flex gap-2">
        <button type="button" class="btn btn-success btn-sm" onclick="envoyerSuivant('sms')"><i class="bi bi-chat-dots me-1"></i>SMS au suivant</button>
        <button type="button" class="btn btn-sm text-white" style="background:#16a34a" onclick="envoyerSuivant('wa')"><i class="bi bi-whatsapp me-1"></i>WhatsApp au suivant</button>
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="if(confirm('Tout remettre à « non envoyé » ?')) reinitialiser()"><i class="bi bi-arrow-counterclockwise"></i></button>
      </div>
    </div>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <tbody>
        <?php $k = 0; foreach ($lignes as $l): foreach ($l['nums'] as $n): ?>
          <tr class="comm-ligne" data-k="<?= $k ?>" data-tel="<?= h($n) ?>" data-texte="<?= h($l['texte']) ?>">
            <td style="width:34px"><input type="checkbox" class="form-check-input comm-fait" title="Envoyé"></td>
            <td>
              <div class="fw-semibold" style="font-size:.82rem"><?= h($l['eleve']) ?> <span class="text-muted fw-normal">— <?= h($l['classe']) ?></span></div>
              <div style="font-size:.76rem"><a href="tel:+237<?= h($n) ?>"><?= h(tel_affiche($n)) ?></a> <span class="text-muted"><?= h($l['qui']) ?></span></div>
              <div class="comm-texte mt-1"><?= h($l['texte']) ?></div>
            </td>
            <td class="text-end text-nowrap" style="width:100px">
              <button type="button" class="btn btn-sm btn-outline-success comm-btn" onclick="envoyerLigne(<?= $k ?>,'sms')" title="SMS"><i class="bi bi-chat-dots"></i></button>
              <button type="button" class="btn btn-sm btn-outline-success comm-btn" onclick="envoyerLigne(<?= $k ?>,'wa')" title="WhatsApp"><i class="bi bi-whatsapp"></i></button>
            </td>
          </tr>
        <?php $k++; endforeach; endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

<script>
(function () {
  // Compteur de caractères / SMS (alphabet GSM 7 bits : 160 car., sinon 70).
  var GSM = "@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà^{}\\[~]|€";
  var ta = document.getElementById('modele'), cpt = document.getElementById('compteur'), al = document.getElementById('alerte-gsm');
  function majCompteur() {
    var t = ta.value, gsm = true;
    for (var i = 0; i < t.length; i++) if (GSM.indexOf(t[i]) < 0) { gsm = false; break; }
    var lim = gsm ? 160 : 70, multi = gsm ? 153 : 67, n = t.length <= lim ? 1 : Math.ceil(t.length / multi);
    cpt.textContent = t.length + ' caractères (modèle) — ' + n + ' SMS par message' + (/\{/.test(t) ? ' (avant remplacement des variables)' : '');
    al.style.display = gsm ? 'none' : '';
  }
  if (ta) { ta.addEventListener('input', majCompteur); majCompteur(); }
})();

var CLE = <?= json_encode($cle_campagne) ?>;
var NUMS = <?= json_encode($nums_uniques) ?>;
var TEXTE_GROUPE = <?= json_encode($identique ? $lignes[0]['texte'] : '') ?>;
function lireFaits() { try { return JSON.parse(localStorage.getItem(CLE) || '{}'); } catch (e) { return {}; } }
function ecrireFaits(f) { try { localStorage.setItem(CLE, JSON.stringify(f)); } catch (e) {} }
function lienSms(nums, texte) {
  var sep = (document.getElementById('separateur') || {}).value || ',';
  return 'sms:' + nums.map(function (n) { return '+237' + n; }).join(sep) + '?body=' + encodeURIComponent(texte);
}
function lienWa(n, texte) { return 'https://wa.me/237' + n + '?text=' + encodeURIComponent(texte); }
function lignes() { return Array.prototype.slice.call(document.querySelectorAll('.comm-ligne')); }
function majAffichage() {
  var f = lireFaits(), ls = lignes(), nb = 0;
  ls.forEach(function (tr) { var ok = !!f[tr.dataset.k]; tr.classList.toggle('fait', ok); tr.querySelector('.comm-fait').checked = ok; if (ok) nb++; });
  var a = document.getElementById('avancement'); if (a) a.textContent = '— ' + nb + ' / ' + ls.length + ' envoyé(s)';
  document.querySelectorAll('.lot-btn').forEach(function (b) { b.classList.toggle('btn-success', !!f['lot' + b.dataset.i]); b.classList.toggle('btn-outline-success', !f['lot' + b.dataset.i]); });
}
function marquer(cle) { var f = lireFaits(); f[cle] = 1; ecrireFaits(f); majAffichage(); }
function envoyerLigne(k, canal) {
  var tr = document.querySelector('.comm-ligne[data-k="' + k + '"]'); if (!tr) return;
  marquer(k);
  window.location.href = canal === 'wa' ? lienWa(tr.dataset.tel, tr.dataset.texte) : lienSms([tr.dataset.tel], tr.dataset.texte);
}
function envoyerSuivant(canal) {
  var f = lireFaits(), tr = lignes().filter(function (t) { return !f[t.dataset.k]; })[0];
  if (!tr) { alert('Tous les messages ont été envoyés.'); return; }
  tr.scrollIntoView({ block: 'center' });
  envoyerLigne(tr.dataset.k, canal);
}
function reinitialiser() { ecrireFaits({}); majAffichage(); }
function construireLots() {
  var zone = document.getElementById('lots'); if (!zone) return;
  var taille = Math.max(1, parseInt(document.getElementById('taille-lot').value, 10) || 20);
  zone.innerHTML = '';
  for (var i = 0; i * taille < NUMS.length; i++) {
    (function (i) {
      var lot = NUMS.slice(i * taille, (i + 1) * taille), b = document.createElement('button');
      b.type = 'button'; b.className = 'btn btn-sm btn-outline-success lot-btn'; b.dataset.i = i;
      b.innerHTML = '<i class="bi bi-chat-dots me-1"></i>Lot ' + (i + 1) + ' (' + lot.length + ')';
      b.onclick = function () { marquer('lot' + i); window.location.href = lienSms(lot, TEXTE_GROUPE); };
      zone.appendChild(b);
    })(i);
  }
  majAffichage();
}
function copierNumeros() {
  var t = NUMS.map(function (n) { return '+237' + n; }).join(', ');
  if (navigator.clipboard) navigator.clipboard.writeText(t).then(function () { alert(NUMS.length + ' numéro(s) copiés.'); });
}
document.addEventListener('change', function (e) {
  if (e.target.classList.contains('comm-fait')) { var f = lireFaits(), k = e.target.closest('tr').dataset.k; if (e.target.checked) f[k] = 1; else delete f[k]; ecrireFaits(f); majAffichage(); }
  if (e.target.id === 'taille-lot' || e.target.id === 'separateur') construireLots();
});
construireLots(); majAffichage();
</script>

<?php require_once __DIR__ . '/../../../../noyau/layout/footer.php'; ?>
