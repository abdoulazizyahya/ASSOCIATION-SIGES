<?php
// secondaire/commun/pages/communication/modeles.php — modèles de SMS
// (français / anglais) et envois automatiques (10/11/2026).
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
require_once __DIR__ . '/_sms_lib.php';

exiger_role(ed_secondaire() ? ['ADMIN', 'PROVISEUR', 'FONDATEUR', 'CENSEUR', 'SG', 'SECRETAIRE', 'INTENDANT'] : ['DIRECTEUR', 'SECRETAIRE', 'COMPTABLE', 'FONDATEUR']);
$ici = 'secondaire/commun/pages/communication/modeles.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $nv = [];
    foreach (SMS_TYPES as $k => [, , $autom, $fr, $en]) {
        if (post('reinit') === $k) continue;   // modèle remis par défaut
        $tfr = trim((string) ($_POST['fr'][$k] ?? '')); $ten = trim((string) ($_POST['en'][$k] ?? ''));
        $nv[$k] = ['fr' => mb_substr($tfr !== '' ? $tfr : $fr, 0, 480), 'en' => mb_substr($ten !== '' ? $ten : $en, 0, 480),
                   'auto' => $autom && !empty($_POST['auto'][$k]) ? 1 : 0];
    }
    ed_reglages_enregistrer('sms_modeles', $nv)
        ? flash_set('succes', post('reinit') ? 'Modèle remis par défaut.' : 'Modèles enregistrés.')
        : flash_set('erreur', 'Modèles non enregistrés : école non identifiée dans l\'annuaire.');
    rediriger($ici);
}

$mods = sms_modeles();
$gw = sms_gw_actif();
$titre_page = 'Modèles de SMS';
require_once __DIR__ . '/../../../../noyau/layout/header.php';
?>
<div class="page-titre">
  <h4><i class="bi bi-card-text me-1 text-primary"></i>Envoyer aux parents — modèles &amp; envois automatiques</h4>
  <div class="sub">Un modèle en français et en anglais par type de message (la langue suit la section de la classe). Variables : {ecole} {eleve} {classe} {annee} {date} {heure} {montant} {reste} {moyenne} {rang} {effectif} {trimestre} {appreciation} {absences} {motif}</div>
</div>
<?= flash_html() ?>
<?php $onglet_comm = 'modeles'; require __DIR__ . '/_onglets.php'; ?>

<?php if (!$gw): ?>
<div class="alert alert-warning py-2" style="font-size:.85rem"><i class="bi bi-info-circle me-1"></i>Les envois automatiques partent par la <strong>passerelle SMS</strong> (téléphone de l'école). Tant qu'elle n'est pas configurée, ils sont gardés <strong>en attente</strong> dans le journal.</div>
<?php endif; ?>

<form method="post">
  <?= csrf_champ() ?>
  <div class="card">
    <div class="table-responsive">
      <table class="table table-abz align-middle mb-0" style="font-size:.84rem">
        <thead><tr><th style="width:210px">Type</th><th>Modèle — français</th><th>Modèle — anglais</th><th class="text-center" style="width:110px">Automatique</th></tr></thead>
        <tbody>
        <?php foreach ($mods as $k => $m): ?>
          <tr>
            <td>
              <div class="fw-semibold"><i class="bi bi-<?= $m['icone'] ?> me-1 text-primary"></i><?= h($m['libelle']) ?></div>
              <button class="btn btn-link btn-sm p-0" style="font-size:.74rem" name="reinit" value="<?= $k ?>" title="Remettre le texte proposé par SIGES">Remettre par défaut</button>
            </td>
            <td><textarea name="fr[<?= $k ?>]" rows="3" class="form-control form-control-sm sms-modele" maxlength="480"><?= h($m['fr']) ?></textarea><div class="text-muted sms-cpt" style="font-size:.7rem"></div></td>
            <td><textarea name="en[<?= $k ?>]" rows="3" class="form-control form-control-sm sms-modele" maxlength="480"><?= h($m['en']) ?></textarea><div class="text-muted sms-cpt" style="font-size:.7rem"></div></td>
            <td class="text-center">
              <?php if ($m['automatisable']): ?>
                <div class="form-check form-switch d-inline-block"><input class="form-check-input" type="checkbox" name="auto[<?= $k ?>]" value="1" <?= $m['auto'] ? 'checked' : '' ?>></div>
                <div class="text-muted" style="font-size:.7rem"><?= ['inscription' => 'à chaque nouvel élève', 'paiement' => 'à chaque paiement', 'sortie' => 'à chaque billet de sortie', 'infirmerie' => 'à chaque billet d\'infirmerie'][$k] ?? '' ?></div>
              <?php else: ?><span class="text-muted" style="font-size:.74rem">—</span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <button class="btn btn-primary btn-sm mt-2"><i class="bi bi-save me-1"></i>Enregistrer les modèles</button>
</form>

<script>
(function () {
  var GSM = "@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà^{}\\[~]|€";
  function maj(t) {
    var v = t.value, gsm = true;
    for (var i = 0; i < v.length; i++) if (GSM.indexOf(v[i]) < 0) { gsm = false; break; }
    var c = t.nextElementSibling;
    c.textContent = v.length + ' car. (avant variables)' + (gsm ? '' : ' — caractères spéciaux : 70 car. par SMS');
    c.style.color = gsm ? '' : '#b45309';
  }
  document.querySelectorAll('.sms-modele').forEach(function (t) { t.addEventListener('input', function () { maj(t); }); maj(t); });
})();
</script>
<?php require_once __DIR__ . '/../../../../noyau/layout/footer.php'; ?>
