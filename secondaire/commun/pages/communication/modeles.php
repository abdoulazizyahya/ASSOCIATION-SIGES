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
    if (post('relancer_maintenant')) {   // relance des impayés à la main (16/11/2026)
        if (!sms_gw_actif()) { flash_set('erreur', "Configurez d'abord la passerelle SMS."); rediriger($ici); }
        $r = sms_relance_executer('manuel');
        journaliser_action('sms_relance', null, "{$r['envoyes']} envoyé(s), {$r['attente']} en attente, {$r['echecs']} échec(s)");
        flash_set($r['echecs'] || $r['attente'] ? 'erreur' : 'succes',
            "Relance : {$r['cibles']} parent(s) visé(s) — {$r['envoyes']} envoyé(s), {$r['attente']} en attente, {$r['echecs']} échec(s).");
        rediriger($ici);
    }
    ed_reglages_enregistrer('sms_relance', ['jours' => max(1, min(365, (int) post('relance_jours'))),
                                            'seuil' => max(0, (int) preg_replace('/\D/', '', (string) post('relance_seuil')))] + sms_relance_reglages());
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
                <div class="text-muted" style="font-size:.7rem"><?= ['inscription' => 'à chaque nouvel élève', 'paiement' => 'à chaque paiement', 'sortie' => 'à chaque billet de sortie', 'infirmerie' => 'à chaque billet d\'infirmerie', 'relance' => 'impayés, automatiquement'][$k] ?? '' ?></div>
                <?php if ($k === 'relance'): $rl = sms_relance_reglages(); $prochaine = sms_relance_prochaine(); ?>
                  <div class="text-start mt-1" style="font-size:.72rem">
                    tous les <input type="number" name="relance_jours" min="1" max="365" value="<?= (int) $rl['jours'] ?>" class="form-control form-control-sm d-inline-block p-0 px-1" style="width:52px;font-size:.72rem"> j,<br>
                    si reste ≥ <input type="text" name="relance_seuil" value="<?= (int) $rl['seuil'] ?>" class="form-control form-control-sm d-inline-block p-0 px-1" style="width:62px;font-size:.72rem"> F
                    <div class="text-muted"><?= $rl['dernier'] ? 'dernière : ' . h(date('d/m/Y', strtotime($rl['dernier']))) : 'jamais lancée' ?><?= $prochaine ? '<br>prochaine : ' . h(date('d/m/Y', strtotime($prochaine))) : '' ?></div>
                    <button class="btn btn-outline-warning btn-sm py-0 mt-1" style="font-size:.72rem" name="relancer_maintenant" value="1"
                            onclick="return confirm('Envoyer maintenant un SMS de relance à un parent de chaque élève dont le reste à payer atteint le seuil ?')"><i class="bi bi-send me-1"></i>Relancer maintenant</button>
                  </div>
                <?php endif; ?>
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
