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
require_once __DIR__ . '/_passerelle_lib.php';
require_once __DIR__ . '/_sms_lib.php';   // modèles de l'école, journal, reste à payer (10/11/2026)

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
// Textes par défaut = modèles de l'école (onglet « Modèles & automatiques »).
$sms_mods = sms_modeles();
$modeles = [
    'libre'    => $sms_mods['communique']['fr'],
    'paiement' => $sms_mods['relance']['fr'],
    'moyenne'  => $sms_mods['bulletin']['fr'],
    'absences' => $sms_mods['absence']['fr'],
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
            // Tous les systèmes de frais (primaire, secondaire public et privé) : sms_finances_lot().
            $extra = sms_finances_lot($ids);
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
$gw_actif    = sms_gw_actif();        // passerelle SMS (téléphone de l'école) : envoi automatique
$gw_peut_cfg = sms_gw_peut_configurer();
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
<?php $onglet_comm = 'envoyer'; require __DIR__ . '/_onglets.php'; ?>

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
        <?php if ($type === 'libre'): ?>
        <div class="d-flex align-items-center gap-2 mt-1" style="font-size:.78rem">
          <span class="text-muted">Charger un modèle :</span>
          <select class="form-select form-select-sm" style="max-width:320px" onchange="if(this.value){document.getElementById('modele').value=this.value;document.getElementById('modele').dispatchEvent(new Event('input'));this.selectedIndex=0;}">
            <option value="">— choisir —</option>
            <?php foreach ($sms_mods as $__m): ?><option value="<?= h($__m['fr']) ?>"><?= h($__m['libelle']) ?> (FR)</option><option value="<?= h($__m['en']) ?>"><?= h($__m['libelle']) ?> (EN)</option><?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
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
<!-- Envoi à la sélection (10/11/2026) : cocher, puis « Envoyer à la sélection » -->
<div class="card">
  <div class="card-body">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
      <div class="d-flex align-items-center gap-2">
        <input type="checkbox" class="form-check-input" id="sel-tous" checked title="Tout cocher / décocher">
        <label for="sel-tous" class="fw-semibold mb-0">Destinataires</label>
        <span class="text-muted" id="avancement" style="font-size:.8rem"></span>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <?php if ($gw_actif): ?>
          <button type="button" class="btn btn-success btn-sm" onclick="envoyerSelection('auto')" title="Envoi automatique par le téléphone de l'école (passerelle SMS)">
            <i class="bi bi-lightning-charge me-1"></i>Envoyer à la sélection (SMS automatique)</button>
        <?php endif; ?>
        <button type="button" class="btn btn-<?= $gw_actif ? 'outline-' : '' ?>success btn-sm" onclick="envoyerSelection('sms')" title="Ouvre l'application Messages du téléphone : appuyer sur Envoyer, revenir — le suivant s'ouvre tout seul">
          <i class="bi bi-chat-dots me-1"></i>Envoyer à la sélection (SMS)</button>
        <button type="button" class="btn btn-sm text-white" style="background:#16a34a" onclick="envoyerSelection('wa')" title="Ouvre WhatsApp pour chaque parent : appuyer sur Envoyer, revenir — le suivant s'ouvre tout seul">
          <i class="bi bi-whatsapp me-1"></i>Envoyer à la sélection (WhatsApp)</button>
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="if(confirm('Remettre tous les messages à « non envoyé » ?')) reinitialiser()" title="Recommencer"><i class="bi bi-arrow-counterclockwise"></i></button>
      </div>
    </div>

    <div id="bandeau-envoi" class="alert alert-info py-2 d-none" style="font-size:.84rem">
      <span id="bandeau-texte"></span>
      <button type="button" class="btn btn-sm btn-outline-primary ms-2" onclick="suivant()"><i class="bi bi-play-fill"></i> Continuer</button>
      <button type="button" class="btn btn-sm btn-outline-dark ms-1" onclick="arreterEnvoi()"><i class="bi bi-pause-fill"></i> Arrêter</button>
    </div>

    <?php if (!$gw_actif): ?>
    <div class="text-muted mb-2" style="font-size:.78rem">
      <i class="bi bi-info-circle me-1"></i>Sans passerelle, le téléphone demande d'appuyer sur « Envoyer » pour chaque message (sécurité d'Android) : revenez ensuite dans SIGES, le message suivant s'ouvre tout seul.
      <?php if ($identique): ?>Message identique pour tous : les numéros sont regroupés par lots dans un seul SMS groupé.<?php endif; ?>
      <?php if ($gw_peut_cfg): ?><br><a href="<?= APP_URL ?>/secondaire/commun/pages/communication/passerelle.php"><i class="bi bi-lightning-charge"></i> Activer l'envoi automatique de tous les SMS en un clic (passerelle SMS gratuite)</a><?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($identique): ?>
    <div class="d-flex flex-wrap align-items-center gap-2 mb-2" style="font-size:.78rem">
      <label>SMS groupé : numéros par envoi <input type="number" id="taille-lot" class="form-control form-control-sm d-inline-block" style="width:70px" min="1" max="100" value="20"></label>
      <label>Séparateur <select id="separateur" class="form-select form-select-sm d-inline-block" style="width:110px"><option value=",">virgule</option><option value=";">point-virgule</option></select></label>
      <span class="text-muted">Si Messages ne garde que le 1er numéro, changez de séparateur. Réglage Android conseillé : groupe envoyé en <em>SMS individuels</em> (pas MMS).</span>
    </div>
    <?php endif; ?>

    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <tbody>
        <?php $k = 0; foreach ($lignes as $l): foreach ($l['nums'] as $n): ?>
          <tr class="comm-ligne" data-k="<?= $k ?>" data-tel="<?= h($n) ?>" data-nom="<?= h($l['eleve']) ?>" data-texte="<?= h($l['texte']) ?>">
            <td style="width:34px"><input type="checkbox" class="form-check-input comm-sel" checked title="Sélectionner"></td>
            <td>
              <div class="fw-semibold" style="font-size:.82rem"><?= h($l['eleve']) ?> <span class="text-muted fw-normal">— <?= h($l['classe']) ?></span>
                <span class="badge comm-etat ms-1"></span></div>
              <div style="font-size:.76rem"><a href="tel:+237<?= h($n) ?>"><?= h(tel_affiche($n)) ?></a> <span class="text-muted"><?= h($l['qui']) ?></span></div>
              <div class="comm-texte mt-1"><?= h($l['texte']) ?></div>
            </td>
            <td class="text-end text-nowrap" style="width:100px">
              <button type="button" class="btn btn-sm btn-outline-success comm-btn" onclick="envoyerLigne(<?= $k ?>,'sms')" title="SMS à ce parent"><i class="bi bi-chat-dots"></i></button>
              <button type="button" class="btn btn-sm btn-outline-success comm-btn" onclick="envoyerLigne(<?= $k ?>,'wa')" title="WhatsApp à ce parent"><i class="bi bi-whatsapp"></i></button>
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
var URL_GW = <?= json_encode(APP_URL . '/secondaire/commun/pages/communication/envoyer_passerelle.php') ?>;
var CSRF = <?= json_encode(csrf_generer()) ?>;
function lireEtat() { try { return JSON.parse(localStorage.getItem(CLE) || '{}'); } catch (e) { return {}; } }
function ecrireEtat(f) { try { localStorage.setItem(CLE, JSON.stringify(f)); } catch (e) {} }
function lireAuto() { try { return JSON.parse(localStorage.getItem(CLE + '_auto') || 'null'); } catch (e) { return null; } }
function ecrireAuto(a) { try { a ? localStorage.setItem(CLE + '_auto', JSON.stringify(a)) : localStorage.removeItem(CLE + '_auto'); } catch (e) {} }
function lignes() { return Array.prototype.slice.call(document.querySelectorAll('.comm-ligne')); }
function ligneK(k) { return document.querySelector('.comm-ligne[data-k="' + k + '"]'); }
function selectionnees() { var f = lireEtat(); return lignes().filter(function (t) { return t.querySelector('.comm-sel').checked && f[t.dataset.k] !== 'ok'; }); }
function lienSms(nums, texte) {
  var sep = (document.getElementById('separateur') || {}).value || ',';
  return 'sms:' + nums.map(function (n) { return '+237' + n; }).join(sep) + '?body=' + encodeURIComponent(texte);
}
function lienWa(n, texte) { return 'https://wa.me/237' + n + '?text=' + encodeURIComponent(texte); }

function majAffichage() {
  var f = lireEtat(), ls = lignes(), nb = 0, ko = 0;
  ls.forEach(function (tr) {
    var e = f[tr.dataset.k], b = tr.querySelector('.comm-etat');
    tr.classList.toggle('fait', e === 'ok');
    if (e === 'ok') { nb++; b.textContent = '✓ envoyé'; b.style.background = '#d1fae5'; b.style.color = '#065f46'; }
    else if (e && e !== 'ok') { ko++; b.textContent = '✗ échec'; b.title = e; b.style.background = '#fee2e2'; b.style.color = '#991b1b'; }
    else { b.textContent = ''; b.title = ''; }
  });
  var nsel = lignes().filter(function (t) { return t.querySelector('.comm-sel').checked; }).length;
  var a = document.getElementById('avancement');
  if (a) a.textContent = '— ' + nsel + ' sélectionné(s) · ' + nb + ' / ' + ls.length + ' envoyé(s)' + (ko ? ' · ' + ko + ' échec(s)' : '');
}
function marquer(k, etat) { var f = lireEtat(); f[k] = etat; ecrireEtat(f); majAffichage(); }
function bandeau(txt) { var b = document.getElementById('bandeau-envoi'); if (!b) return; b.classList.toggle('d-none', !txt); document.getElementById('bandeau-texte').textContent = txt || ''; }

// Un parent : ouvre Messages ou WhatsApp (le téléphone exige un appui sur « Envoyer »).
function envoyerLigne(k, canal) {
  var tr = ligneK(k); if (!tr) return;
  marquer(k, 'ok');
  window.location.href = canal === 'wa' ? lienWa(tr.dataset.tel, tr.dataset.texte) : lienSms([tr.dataset.tel], tr.dataset.texte);
}

// « Envoyer à la sélection »
var envoiAutoEnCours = false;
function envoyerSelection(canal) {
  var sel = selectionnees();
  if (!sel.length) { alert('Aucun destinataire sélectionné à envoyer.'); return; }
  if (canal === 'auto') return envoyerParPasserelle(sel);
  if (!confirm(sel.length + ' message(s) à envoyer par ' + (canal === 'wa' ? 'WhatsApp' : 'SMS') + '. Le téléphone ouvrira chaque message : appuyez sur « Envoyer » puis revenez dans SIGES, le suivant s\'ouvrira tout seul.')) return;
  ecrireAuto({ canal: canal });
  suivant();
}
// Ouvre le message suivant de la sélection (SMS : regroupe les messages identiques par lots).
function suivant() {
  var a = lireAuto(); if (!a) return;
  var sel = selectionnees();
  if (!sel.length) { ecrireAuto(null); bandeau(''); alert('Envoi terminé.'); return; }
  bandeau('Envoi en cours (' + (a.canal === 'wa' ? 'WhatsApp' : 'SMS') + ') — reste ' + sel.length + ' message(s). Après chaque envoi, revenez dans SIGES.');
  if (a.canal === 'sms') {
    var texte = sel[0].dataset.texte, taille = Math.max(1, parseInt((document.getElementById('taille-lot') || {}).value, 10) || 20);
    var lot = sel.filter(function (t) { return t.dataset.texte === texte; }).slice(0, taille);
    var nums = [], vus = {};
    lot.forEach(function (t) { if (!vus[t.dataset.tel]) { vus[t.dataset.tel] = 1; nums.push(t.dataset.tel); } marquer(t.dataset.k, 'ok'); });
    window.location.href = lienSms(nums, texte);
  } else {
    marquer(sel[0].dataset.k, 'ok');
    window.location.href = lienWa(sel[0].dataset.tel, sel[0].dataset.texte);
  }
}
function arreterEnvoi() { ecrireAuto(null); envoiAutoEnCours = false; bandeau(''); }
// Retour dans SIGES après un envoi : on ouvre le suivant.
document.addEventListener('visibilitychange', function () {
  if (document.visibilityState === 'visible' && lireAuto()) setTimeout(function () { if (document.visibilityState === 'visible' && lireAuto()) suivant(); }, 1200);
});

// Passerelle : envoi automatique, par paquets de 10.
function envoyerParPasserelle(sel) {
  if (envoiAutoEnCours) return;
  if (!confirm(sel.length + ' SMS vont être envoyés automatiquement par le téléphone de l\'école. Continuer ?')) return;
  envoiAutoEnCours = true;
  var reste = sel.slice(), total = sel.length, faits = 0;
  function paquet() {
    if (!envoiAutoEnCours) { bandeau(''); return; }
    if (!reste.length) { envoiAutoEnCours = false; bandeau(''); majAffichage(); alert('Envoi terminé : ' + faits + ' / ' + total + ' transmis au téléphone de l\'école.'); return; }
    var lot = reste.splice(0, 10);
    bandeau('Envoi automatique : ' + faits + ' / ' + total + '…');
    var fd = new FormData();
    fd.append('csrf', CSRF);
    fd.append('type', <?= json_encode(['libre' => 'communique', 'paiement' => 'relance', 'moyenne' => 'bulletin', 'absences' => 'absence'][$type]) ?>);
    fd.append('items', JSON.stringify(lot.map(function (t) { return { k: t.dataset.k, tel: t.dataset.tel, nom: t.dataset.nom, texte: t.dataset.texte }; })));
    fetch(URL_GW, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j.ok) { envoiAutoEnCours = false; bandeau(''); alert('Envoi interrompu : ' + (j.erreur || 'erreur')); return; }
        lot.forEach(function (t) { var r = j.resultats[t.dataset.k]; marquer(t.dataset.k, r && r.ok ? 'ok' : (r ? r.erreur : 'échec')); if (r && r.ok) faits++; });
        setTimeout(paquet, 300);
      })
      .catch(function () { envoiAutoEnCours = false; bandeau(''); alert('Connexion perdue : envoi interrompu. Relancez « Envoyer à la sélection » pour continuer.'); });
  }
  paquet();
}

function reinitialiser() { ecrireEtat({}); ecrireAuto(null); bandeau(''); majAffichage(); }
document.addEventListener('change', function (e) {
  if (e.target.id === 'sel-tous') lignes().forEach(function (t) { t.querySelector('.comm-sel').checked = e.target.checked; });
  if (e.target.id === 'sel-tous' || e.target.classList.contains('comm-sel')) majAffichage();
});
majAffichage();
if (lireAuto()) bandeau('Envoi en cours — « Continuer » ouvre le message suivant, « Arrêter » met fin à l\'envoi.');
</script>

<?php require_once __DIR__ . '/../../../../noyau/layout/footer.php'; ?>
