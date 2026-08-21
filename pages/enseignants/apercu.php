<?php
/**
 * Aperçu PDF enseignant (iframe preview)
 * GET : id=matricule_ens, type=attestation|prise_service|reprise_service
 */
header('Cache-Control: no-store, no-cache, must-revalidate');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_connexion();

$role = role_connecte();
$est_staff = in_array($role, ['ADMIN','PROVISEUR','CENSEUR']);

if ($est_staff) {
    $mat  = $_GET['id']   ?? '';
    $type = $_GET['type'] ?? 'attestation';
} elseif ($role === 'ENSEIGNANT') {
    // Un enseignant ne peut voir que SA propre fiche, jamais celle d'un collègue,
    // et uniquement un document que le circuit Censeur → Proviseur a validé.
    $mat  = matricule_ens_courant() ?? '';
    $type = $_GET['type'] ?? 'attestation';
    if ($type === 'dossier') { flash_set('erreur','Accès réservé au personnel administratif.'); rediriger('dashboard.php'); }
    $type_document = ['attestation'=>'attestation','prise_service'=>'prise','reprise_service'=>'reprise'][$type] ?? '';
    if (!$mat || !demande_validee_existe($mat, $type_document)) {
        flash_set('erreur', 'Ce document n\'est pas (ou plus) disponible : votre demande doit d\'abord être validée par le Censeur puis le Proviseur.');
        rediriger('pages/demandes/index.php');
    }
} else {
    flash_set('erreur','Accès réservé.'); rediriger('dashboard.php');
}

$e = $mat ? db_one("SELECT * FROM enseignant WHERE matricule_ens=?", [$mat]) : null;
if (!$e) { flash_set('erreur','Enseignant introuvable.'); rediriger('pages/enseignants/index.php'); }

$nom_complet = trim(($e['civilite_ens']??'').' '.strtoupper($e['nom_ens']).' '.($e['prenom_ens']??''));

$configs = [
    'attestation'     => ['label'=>'Attestation de Présence Effective',    'url'=>'pdf_attestation.php',   'icon'=>'bi-file-earmark-text'],
    'prise_service'   => ['label'=>'Certificat de Prise de Service',       'url'=>'pdf_prise_service.php?type=prise',  'icon'=>'bi-file-earmark-check'],
    'reprise_service' => ['label'=>'Certificat de Reprise de Service',     'url'=>'pdf_prise_service.php?type=reprise','icon'=>'bi-file-earmark-check'],
    'dossier'         => ['label'=>'Dossier Administratif Complet',        'url'=>'pdf_dossier.php','icon'=>'bi-folder2-open'],
];
$cfg = $configs[$type] ?? $configs['attestation'];
// Séparateur adapté : '?' si l'URL de base n'a pas encore de query string, '&' sinon
// (évite le bug où "pdf_attestation.php" + "&id=..." produisait une URL sans "?").
$sep      = (strpos($cfg['url'], '?') !== false) ? '&' : '?';
$url_view = APP_URL.'/pages/enseignants/'.$cfg['url'].$sep.'id='.urlencode($mat);
$url_dl   = $url_view.'&dl=1';
$etab_sig_ens = signature_configuree('chef_etablissement');
$peut_configurer_sig_ens = signature_role_autorisee('chef_etablissement', $role);
$type_doc_sig_map = [
    'attestation'     => 'attestation_enseignant',
    'prise_service'   => 'prise_reprise_service',
    'reprise_service' => 'prise_reprise_service',
    'dossier'         => 'dossier_enseignant',
];
$type_doc_sig = $type_doc_sig_map[$type] ?? 'attestation_enseignant';

$titre_page = $cfg['label'];
require_once __DIR__ . '/../../layout/header.php';
?>
<style>
.btn-abz-primary{background:#1a3c6b;color:#fff;border:none;}
.btn-abz-primary:hover{background:#12305a;color:#fff;}
.btn-abz-outline{background:#fff;color:#1a3c6b;border:1.5px solid #1a3c6b;}
.btn-abz-outline:hover{background:#1a3c6b;color:#fff;}
</style>

<div class="page-titre d-flex align-items-center justify-content-between flex-wrap gap-2">
  <h4>
    <i class="bi <?= $cfg['icon'] ?> me-2" style="color:#1a3c6b"></i>
    <?= h($cfg['label']) ?>
  </h4>
</div>

<div class="card" style="border-color:#c7d8f0">
  <div class="card-header py-2 d-flex align-items-center justify-content-between flex-wrap gap-2" style="background:#f0f4ff">
    <span class="fw-semibold" style="color:#1a3c6b;font-size:.9rem">
      <i class="bi bi-person me-1"></i><?= h($nom_complet) ?>
    </span>
    <div class="d-flex gap-2 flex-wrap">
      <?php if ($est_staff): ?>
      <!-- Sélection du type de document (personnel administratif uniquement) -->
      <a href="?id=<?= urlencode($mat) ?>&type=attestation"
         class="btn btn-sm <?= $type==='attestation'?'btn-abz-primary':'btn-abz-outline' ?>">
        <i class="bi bi-file-earmark-text me-1"></i>Attestation présence
      </a>
      <a href="?id=<?= urlencode($mat) ?>&type=prise_service"
         class="btn btn-sm <?= $type==='prise_service'?'btn-abz-primary':'btn-abz-outline' ?>">
        <i class="bi bi-file-earmark-check me-1"></i>Prise de service
      </a>
      <a href="?id=<?= urlencode($mat) ?>&type=reprise_service"
         class="btn btn-sm <?= $type==='reprise_service'?'btn-abz-primary':'btn-abz-outline' ?>">
        <i class="bi bi-file-earmark-arrow-up me-1"></i>Reprise de service
      </a>
      <a href="?id=<?= urlencode($mat) ?>&type=dossier"
         class="btn btn-sm <?= $type==='dossier'?'btn-abz-primary':'btn-abz-outline' ?>">
        <i class="bi bi-folder2-open me-1"></i>Dossier complet
      </a>
      <?php endif; ?>

      <div class="border-start ms-1 ps-2 d-flex align-items-center gap-2">
        <?php if ($etab_sig_ens): ?>
        <div class="chk-signature form-check form-check-inline mb-0" style="user-select:none">
          <input class="form-check-input" type="checkbox" id="chkSigEns" onchange="appliquerSigEns()">
          <label class="form-check-label small" for="chkSigEns">Signature numérique</label>
        </div>
        <?php if ($peut_configurer_sig_ens): ?>
        <button type="button" class="btn btn-sm btn-abz-outline" title="Configurer la position de la signature"
                onclick="ouvrirPositionSignatureLocale(<?= json_encode($url_view) ?>, <?= json_encode($type_doc_sig) ?>, 'portrait', appliquerSigEns)">
          <i class="bi bi-gear"></i>
        </button>
        <?php endif; ?>
        <?php endif; ?>
        <a id="lienDlEns" href="<?= h($url_dl) ?>" class="btn btn-sm btn-abz-primary">
          <i class="bi bi-download me-1"></i>Télécharger
        </a>
        <button onclick="document.getElementById('iframe-doc').contentWindow.print()"
                class="btn btn-sm btn-abz-outline">
          <i class="bi bi-printer me-1"></i>Imprimer
        </button>
        <a href="<?= $est_staff ? 'fiche.php?id='.urlencode($mat) : APP_URL.'/pages/demandes/index.php' ?>" class="btn btn-sm btn-abz-outline">
          <i class="bi bi-arrow-left me-1"></i><?= $est_staff ? 'Retour fiche' : 'Mes demandes' ?>
        </a>
      </div>
    </div>
  </div>
  <div class="card-body p-0">
    <iframe id="iframe-doc"
            src="<?= h($url_view) ?>"
            style="width:100%;height:88vh;border:none;display:block"
            title="<?= h($cfg['label']) ?>">
    </iframe>
  </div>
</div>
<script>
function appliquerSigEns() {
  const base = <?= json_encode($url_view) ?>;
  const baseDl = <?= json_encode($url_dl) ?>;
  const sig = document.getElementById('chkSigEns').checked;
  const sep = base.includes('?') ? '&' : '?';
  const sepDl = baseDl.includes('?') ? '&' : '?';
  document.getElementById('iframe-doc').src = base + (sig ? sep + 'signature=1' : '');
  document.getElementById('lienDlEns').href = baseDl + (sig ? sepDl + 'signature=1' : '');
}
</script>

<?php require_once __DIR__ . '/../../layout/footer.php'; ?>
