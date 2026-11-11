<?php
// secondaire/commun/pages/communication/passerelle.php — réglage de la
// passerelle SMS (téléphone de l'école + application « SMS Gateway for
// Android »), 10/11/2026. Voir _passerelle_lib.php.
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
require_once __DIR__ . '/_passerelle_lib.php';

$secondaire = type_enseignement_courant() === 'secondaire';
exiger_role($secondaire ? ['ADMIN', 'PROVISEUR', 'FONDATEUR'] : ['DIRECTEUR', 'FONDATEUR']);
$ici = 'secondaire/commun/pages/communication/passerelle.php';

if (!sms_gw_cle()) {
    flash_set('erreur', 'Passerelle SMS indisponible : école non identifiée dans l\'annuaire de l\'association.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && sms_gw_cle()) {
    csrf_verifier();
    $action = post('action');
    if ($action === 'enregistrer') {
        $url = rtrim(trim((string) post('url')), '/') ?: SMS_GW_URL_DEFAUT;
        if (!preg_match('#^https?://#i', $url)) { flash_set('erreur', 'Adresse du serveur invalide.'); rediriger($ici); }
        $login = trim((string) post('login'));
        if ($login === '') { flash_set('erreur', 'L\'identifiant est obligatoire.'); rediriger($ici); }
        if (post('mot_de_passe') === '' && !sms_gw_config()) { flash_set('erreur', 'Le mot de passe est obligatoire.'); rediriger($ici); }
        sms_gw_enregistrer($url, $login, (string) post('mot_de_passe'), (int) post('sim'), post('actif') === '1');
        journaliser_action('sms_passerelle_reglee', null, $login . ' @ ' . $url);
        flash_set('succes', 'Passerelle SMS enregistrée. Faites un essai d\'envoi ci-dessous.');
    } elseif ($action === 'tester') {
        $tel = tel_normaliser_cm((string) post('tel_test'));
        if (!$tel) { flash_set('erreur', 'Numéro de test invalide (9 chiffres, ex. 696 51 43 46).'); rediriger($ici); }
        $etab = etab_pour_pdf(get_etablissement());
        $r = sms_gw_envoyer(trim((string) (($etab['sigle'] ?? '') ?: ($etab['nom_fr'] ?? 'SIGES'))) . ' : message de test de la passerelle SMS SIGES.', [$tel]);
        journaliser_action('sms_passerelle_test', null, $tel . ' — ' . ($r['ok'] ? 'ok' : $r['erreur']));
        flash_set($r['ok'] ? 'succes' : 'erreur', $r['ok']
            ? 'Message de test transmis au téléphone de l\'école (n° ' . ($r['id'] ?: '—') . ') : il doit arriver sur le ' . tel_affiche($tel) . ' dans quelques secondes.'
            : 'Échec : ' . $r['erreur']);
    } elseif ($action === 'supprimer') {
        sms_gw_supprimer();
        journaliser_action('sms_passerelle_supprimee', null, '');
        flash_set('succes', 'Passerelle SMS supprimée : les envois repassent en mode manuel.');
    }
    rediriger($ici);
}

$c = sms_gw_config();
$titre_page = 'Passerelle SMS';
require_once __DIR__ . '/../../../../noyau/layout/header.php';
?>
<div class="page-titre">
  <h4><i class="bi bi-phone me-1 text-primary"></i>Passerelle SMS — téléphone de l'école</h4>
  <div class="sub">Envoi automatique de tous les SMS en un clic, avec le forfait de la puce du téléphone de l'école.</div>
</div>

<?= flash_html() ?>
<?php $onglet_comm = 'passerelle'; require_once __DIR__ . '/_passerelle_lib.php'; require __DIR__ . '/_onglets.php'; ?>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card">
      <div class="card-body">
        <div class="section-titre"><i class="bi bi-gear me-1"></i>Réglages</div>
        <?php if ($c): ?>
          <div class="alert <?= $c['actif'] ? 'alert-success' : 'alert-warning' ?> py-2" style="font-size:.84rem">
            <?= $c['actif'] ? '<i class="bi bi-check-circle me-1"></i>Passerelle active : « Envoyer à la sélection » envoie automatiquement.' : '<i class="bi bi-pause-circle me-1"></i>Passerelle enregistrée mais désactivée.' ?>
          </div>
        <?php endif; ?>
        <form method="post">
          <?= csrf_champ() ?>
          <input type="hidden" name="action" value="enregistrer">
          <div class="mb-2">
            <label class="form-label">Adresse du serveur</label>
            <input type="url" name="url" class="form-control form-control-sm" value="<?= h($c['url'] ?? SMS_GW_URL_DEFAUT) ?>">
            <div class="form-text">Serveur cloud gratuit de l'application : laisser <code><?= h(SMS_GW_URL_DEFAUT) ?></code>.</div>
          </div>
          <div class="row g-2 mb-2">
            <div class="col-6">
              <label class="form-label">Identifiant (Username)</label>
              <input type="text" name="login" class="form-control form-control-sm" value="<?= h($c['login'] ?? '') ?>" autocomplete="off" required>
            </div>
            <div class="col-6">
              <label class="form-label">Mot de passe (Password)</label>
              <input type="password" name="mot_de_passe" class="form-control form-control-sm" autocomplete="new-password" placeholder="<?= $c ? 'inchangé si vide' : '' ?>">
            </div>
          </div>
          <div class="row g-2 mb-2">
            <div class="col-6">
              <label class="form-label">Puce utilisée</label>
              <select name="sim" class="form-select form-select-sm">
                <?php foreach ([0 => 'Puce par défaut du téléphone', 1 => 'SIM 1', 2 => 'SIM 2'] as $v => $l): ?>
                  <option value="<?= $v ?>" <?= (int) ($c['sim'] ?? 0) === $v ? 'selected' : '' ?>><?= $l ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6 d-flex align-items-end">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="actif" value="1" id="gw-actif" <?= !$c || $c['actif'] ? 'checked' : '' ?>>
                <label class="form-check-label" for="gw-actif">Utiliser la passerelle pour les envois</label>
              </div>
            </div>
          </div>
          <button class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Enregistrer</button>
        </form>

        <?php if ($c): ?>
        <hr>
        <form method="post" class="d-flex gap-2 align-items-end flex-wrap">
          <?= csrf_champ() ?>
          <input type="hidden" name="action" value="tester">
          <div>
            <label class="form-label">Essai : envoyer un SMS au</label>
            <input type="tel" name="tel_test" class="form-control form-control-sm" placeholder="ex. 696 51 43 46" required>
          </div>
          <button class="btn btn-success btn-sm"><i class="bi bi-send me-1"></i>Envoyer l'essai</button>
        </form>
        <form method="post" class="mt-3" onsubmit="return confirm('Supprimer les réglages de la passerelle ?')">
          <?= csrf_champ() ?>
          <input type="hidden" name="action" value="supprimer">
          <button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash me-1"></i>Supprimer la passerelle</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card">
      <div class="card-body" style="font-size:.86rem">
        <div class="section-titre"><i class="bi bi-list-ol me-1"></i>Installation (une seule fois)</div>
        <ol class="ps-3 mb-2">
          <li>Sur le <strong>téléphone Android de l'école</strong> (Android 5 ou plus, avec la puce et son forfait SMS), installez l'application gratuite <strong>SMS Gateway for Android</strong> : fichier APK sur
            <a href="https://github.com/capcom6/android-sms-gateway/releases" target="_blank" rel="noopener">github.com/capcom6/android-sms-gateway</a> (documentation : <a href="https://docs.sms-gate.app" target="_blank" rel="noopener">docs.sms-gate.app</a>).</li>
          <li>Ouvrez-la, autorisez l'envoi de SMS, puis activez <strong>Serveur cloud</strong> (Cloud server) et démarrez le service.</li>
          <li>L'application affiche un <strong>identifiant</strong> et un <strong>mot de passe</strong> : recopiez-les ici, enregistrez, puis faites un <strong>essai</strong> vers votre propre numéro.</li>
          <li>Laissez le téléphone <strong>allumé, connecté à Internet</strong> (données ou Wi-Fi) et l'application en marche. Désactivez l'économie de batterie pour cette application.</li>
        </ol>
        <div class="text-muted" style="font-size:.78rem">
          Les SMS partent du téléphone avec le forfait de sa puce : rien n'est payé à un fournisseur. Les messages transitent par le serveur gratuit de l'application avant d'arriver au téléphone (un message non reçu par le téléphone dans les 24 h est abandonné). Pour de gros volumes, préférez des envois de quelques dizaines à la fois : les opérateurs peuvent limiter les envois en rafale.
        </div>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../../../noyau/layout/footer.php'; ?>
