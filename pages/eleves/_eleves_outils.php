<?php
// Fragment : onglet « Import & matricules » de la liste des élèves
// (pages/eleves/_liste_resultats.php). Inclus en plein écran ET en réponse
// AJAX (partiel=1). Réservé aux rôles qui peuvent importer / configurer —
// $peut_importer est calculé par pages/eleves/liste.php.
if (empty($peut_importer)) { echo '<div class="text-muted small py-3">Accès réservé.</div>'; return; }

$mc          = matricule_config();
$mc_exemple  = matricule_exemple($mc['format'], (int) $mc['longueur_seq']);
$sequence_lbl = [
    'annee_niveau' => 'par année scolaire et par niveau (1 → M, 1 → primaire)',
    'annee'        => 'par année scolaire (tous niveaux confondus)',
    'globale'      => 'jamais remise à zéro',
];
?>
<div class="row g-3">

  <!-- ── Import Excel ─────────────────────────────────────────── -->
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-body">
        <div class="fw-bold mb-1" style="font-size:.9rem">
          <i class="bi bi-file-earmark-excel me-1 text-success"></i>Importer des élèves depuis Excel
        </div>
        <p class="text-muted mb-2" style="font-size:.8rem">
          Créer plusieurs élèves d'un coup à partir d'un fichier <span class="font-monospace">.xlsx</span>.
          Vous choisirez quelles colonnes du fichier importer.
        </p>
        <a href="<?= APP_URL ?>/pages/eleves/import.php" class="btn btn-outline-primary btn-sm">
          <i class="bi bi-upload me-1"></i>Ouvrir l'import
        </a>
      </div>
    </div>
  </div>

  <!-- ── Configuration du matricule ───────────────────────────── -->
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-body">
        <div class="fw-bold mb-1" style="font-size:.9rem">
          <i class="bi bi-hash me-1 text-primary"></i>Format du matricule des élèves
        </div>
        <p class="text-muted mb-2" style="font-size:.8rem">
          Actuellement :
          <strong><?= match ($mc['mode']) {
              'manuel'    => 'saisi à la main',
              'aleatoire' => 'généré aléatoirement',
              default     => 'généré automatiquement (séquentiel)',
          } ?></strong>
          <?php if ($mc['mode'] !== 'manuel'): ?>
            — exemple : <span class="badge-code font-monospace"><?= h($mc_exemple) ?></span>
          <?php endif; ?>
        </p>

        <form method="post" action="<?= APP_URL ?>/pages/eleves/matricule_config.php" id="formMatriculeConfig">
          <?= csrf_champ() ?>

          <div class="mb-2">
            <div class="form-check">
              <input class="form-check-input" type="radio" name="mode" value="auto" id="mc_auto"
                     <?= $mc['mode'] === 'auto' ? 'checked' : '' ?> onchange="majFormatUI()">
              <label class="form-check-label small" for="mc_auto">
                <strong>Automatique</strong> — le matricule est généré à l'enregistrement, numéro d'ordre croissant.
              </label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="mode" value="aleatoire" id="mc_aleatoire"
                     <?= $mc['mode'] === 'aleatoire' ? 'checked' : '' ?> onchange="majFormatUI()">
              <label class="form-check-label small" for="mc_aleatoire">
                <strong>Aléatoire</strong> — même format, mais le numéro est tiré au hasard (non devinable) plutôt qu'incrémenté.
              </label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="mode" value="manuel" id="mc_manuel"
                     <?= $mc['mode'] === 'manuel' ? 'checked' : '' ?> onchange="majFormatUI()">
              <label class="form-check-label small" for="mc_manuel">
                <strong>Manuel</strong> — le matricule est un champ libre dans la fiche élève, il peut rester vide.
              </label>
            </div>
          </div>

          <div id="mc_bloc_format" class="border rounded p-2 mb-2" style="background:#f8fafc">
            <label class="form-label">Format</label>
            <input type="text" name="format" id="mc_format" class="form-control form-control-sm font-monospace"
                   value="<?= h($mc['format']) ?>" maxlength="60" oninput="majApercuMatricule()">
            <div class="form-text" style="font-size:.7rem">
              Jetons : <span class="font-monospace">{AA}</span> année sur 2 chiffres ·
              <span class="font-monospace">{AAAA}</span> année sur 4 chiffres ·
              <span class="font-monospace">{NIV}</span> M (maternelle) ou P ·
              <span class="font-monospace">{SEQ}</span> numéro d'ordre (obligatoire).
              Texte libre autorisé (préfixe, tirets…).
            </div>

            <div class="row g-2 mt-1">
              <div class="col-6">
                <label class="form-label">Chiffres du numéro</label>
                <select name="longueur_seq" class="form-select form-select-sm" onchange="majApercuMatricule()">
                  <?php for ($n = 2; $n <= 6; $n++): ?>
                    <option value="<?= $n ?>" <?= (int) $mc['longueur_seq'] === $n ? 'selected' : '' ?>><?= $n ?> (ex. <?= str_pad('1', $n, '0', STR_PAD_LEFT) ?>)</option>
                  <?php endfor; ?>
                </select>
              </div>
              <div class="col-6">
                <label class="form-label">Numérotation</label>
                <select name="sequence_par" class="form-select form-select-sm">
                  <?php foreach ($sequence_lbl as $k => $lbl): ?>
                    <option value="<?= $k ?>" <?= $mc['sequence_par'] === $k ? 'selected' : '' ?>><?= h($lbl) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div class="mt-2 small">
              Aperçu : <span class="badge-code font-monospace" id="mc_apercu"><?= h($mc_exemple) ?></span>
              <span class="text-muted" style="font-size:.7rem">(année <?= h($val_annee ?: '2025/2026') ?>, primaire)</span>
            </div>
          </div>

          <div class="alert alert-warning py-1 px-2 small" id="mc_avert_manuel" style="<?= $mc['mode'] === 'manuel' ? '' : 'display:none' ?>">
            <i class="bi bi-info-circle me-1"></i>En mode manuel, les élèves déjà enregistrés gardent leur matricule.
            Les nouveaux élèves n'en auront pas tant qu'il n'est pas saisi.
          </div>

          <button class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Enregistrer</button>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
// Réexécuté à chaque (re)chargement AJAX de #zoneResultats — déclarations
// `function` uniquement (voir layout/footer.php), jamais de const/let au 1er niveau.
function majFormatUI() {
  var manuel = document.getElementById('mc_manuel') && document.getElementById('mc_manuel').checked;
  var avecFormat = !manuel; // 'auto' et 'aleatoire' utilisent tous deux le bloc format
  var bloc = document.getElementById('mc_bloc_format');
  var avert = document.getElementById('mc_avert_manuel');
  if (bloc)  bloc.style.opacity = avecFormat ? '1' : '.45';
  if (bloc)  bloc.querySelectorAll('input,select').forEach(function (el) { el.disabled = !avecFormat; });
  if (avert) avert.style.display = avecFormat ? 'none' : '';
}
function majApercuMatricule() {
  var f = (document.getElementById('mc_format') || {}).value || '';
  var lseqEl = document.querySelector('#mc_bloc_format select[name=longueur_seq]');
  var lseq = lseqEl ? parseInt(lseqEl.value, 10) : 3;
  var an = <?= json_encode(explode('/', $val_annee ?: '2025/2026')[0]) ?>;
  var seq = String(1).padStart(lseq, '0');
  var out = f.replace(/\{AAAA\}/g, an).replace(/\{AA\}/g, an.slice(-2)).replace(/\{NIV\}/g, 'P').replace(/\{SEQ\}/g, seq);
  var el = document.getElementById('mc_apercu');
  if (el) el.textContent = out || '(vide)';
}
majFormatUI();
</script>
