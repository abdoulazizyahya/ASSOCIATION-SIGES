<?php
// noyau/chef_etablissement.php — changement de chef d'établissement en cours
// d'année (26/10/2026), primaire et secondaire. On enregistre la signature du
// nouveau Directeur / Proviseur « à partir du » trimestre voulu : l'ancienne
// signature (et l'ancien titre) est gardée pour les documents des trimestres
// précédents — bulletins, PV, relevés, tableaux d'honneur… réimprimés plus tard
// portent la signature du chef de LEUR période. Le nom n'est jamais imprimé.
// Voir chef_archive_document() dans noyau/fonctions.php (table chef_historique,
// migrations primaire v67 / secondaire v23).
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/connexion.php';
require_once __DIR__ . '/fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'FONDATEUR', 'DIRECTEUR']);

$sec  = type_enseignement_courant() === 'secondaire';
$base = __DIR__ . '/../assets/uploads';
$etab = get_etablissement();
$annee = get_annee_active();
$an_debut = chef_annee_debut($annee['val_annee'] ?? '');
// Trimestres de l'année active : [rang => libellé]
$trims = [];
if ($sec) foreach (db_all("SELECT ordre, libelle FROM trimestre WHERE id_annee=? ORDER BY ordre", [(int) ($annee['id'] ?? 0)]) as $t) $trims[(int) $t['ordre']] = $t['libelle'];
else foreach (db_all("SELECT libelle_trim FROM trimestre WHERE id_annee=? ORDER BY id_trim", [(string) ($annee['val_annee'] ?? '')]) as $i => $t) $trims[$i + 1] = $t['libelle_trim'];

/** Signature ACTUELLE (Paramètres) : [fichier relatif, chemin absolu] ou [null, null]. */
$sig_actuelle = function () use ($sec, $base, $etab): array {
    $f = $sec ? db_val("SELECT fichier FROM signature_titulaire WHERE code='chef_etablissement'") : ($etab['signature'] ?? null);
    return $f && is_file("$base/$f") ? [$f, "$base/$f"] : [null, null];
};
[$titre_fr, $titre_en] = titres_chef_pdf($etab);
$dispo = chef_historique_dispo();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $dispo) {
    csrf_verifier();
    $act = post('action');
    if ($act === 'changer') {
        $ordre = (int) post('trim');
        $f = $_FILES['signature'] ?? null;
        $ext = strtolower(pathinfo((string) ($f['name'] ?? ''), PATHINFO_EXTENSION));
        $mime = !empty($f['tmp_name']) && is_uploaded_file($f['tmp_name']) ? (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) : '';
        $types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
        if (!isset($trims[$ordre]) || !$an_debut) {
            flash_set('erreur', 'Choisissez le trimestre à partir duquel le nouveau chef signe.');
        } elseif (($types[$ext] ?? '') !== $mime || ($f['size'] ?? 0) > 2 * 1024 * 1024) {
            flash_set('erreur', 'Envoyez la signature du nouveau chef (image JPG ou PNG, 2 Mo au plus).');
        } elseif (db_val("SELECT 1 FROM chef_historique WHERE remplace_annee=? AND remplace_trim=?", [$an_debut, $ordre])) {
            flash_set('erreur', 'Un changement de chef est déjà enregistré à partir de ce trimestre : supprimez-le d\'abord s\'il est faux.');
        } else {
            chef_enregistrer_changement($an_debut, $ordre, $f['tmp_name'], $ext, trim((string) post('titre_fr')), trim((string) post('titre_en')), trim((string) post('note')));
            journaliser_action('chef_change', null, "nouveau chef à partir du T$ordre " . ($annee['val_annee'] ?? ''));
            flash_set('succes', "Changement enregistré : la nouvelle signature s'applique à partir du " . $trims[$ordre]
                . ' ; les documents des trimestres précédents gardent l\'ancienne.');
        }
        rediriger('noyau/chef_etablissement.php');
    }
    if ($act === 'supprimer') {
        $r = db_one("SELECT * FROM chef_historique WHERE id=?", [(int) post('id')]);
        if ($r) {
            db_exec("DELETE FROM chef_historique WHERE id=?", [$r['id']]);
            if ($r['signature'] && str_contains($r['signature'], 'chef_archive_') && is_file("$base/{$r['signature']}")) @unlink("$base/{$r['signature']}");
            journaliser_action('chef_change_supprime', null, "T{$r['remplace_trim']} {$r['remplace_annee']}");
            flash_set('succes', 'Changement supprimé : la signature actuelle s\'applique de nouveau à ces trimestres.');
        }
        rediriger('noyau/chef_etablissement.php');
    }
}

$hist = $dispo ? db_all("SELECT * FROM chef_historique ORDER BY remplace_annee DESC, remplace_trim DESC") : [];
[, $abs_act] = $sig_actuelle();
$url = fn(?string $f) => $f ? APP_URL . '/assets/uploads/' . $f . '?t=' . time() : null;
$titre_page = 'Changement de chef d\'établissement';
require_once __DIR__ . '/layout/header.php';
?>
<div class="page-titre"><h4><i class="bi bi-person-badge me-1 text-primary"></i>Changement de <?= $sec ? 'Proviseur / Principal' : 'Directeur' ?> en cours d'année</h4></div>
<?= flash_html() ?>
<?php if (!$dispo): ?>
  <div class="alert alert-warning">Mise à jour de la base nécessaire (migration <?= $sec ? 'secondaire v23' : 'primaire v67' ?>) : demandez au propriétaire de lancer les migrations.</div>
<?php else: ?>
<div class="row g-3">
  <div class="col-lg-6">
    <div class="card"><div class="card-body">
      <div class="fw-semibold mb-2">Chef actuel</div>
      <div class="small mb-2"><?= h($titre_fr) ?> <span class="text-muted">/ <?= h($titre_en) ?></span></div>
      <?php if ($abs_act): [$f_act] = $sig_actuelle(); ?><img src="<?= h($url($f_act)) ?>" alt="Signature actuelle" style="max-height:70px;border:1px dashed #ccd;padding:4px;background:#fff">
      <?php else: ?><div class="text-muted small">Aucune signature enregistrée.</div><?php endif; ?>
      <hr>
      <div class="fw-semibold mb-1">Un nouveau chef prend ses fonctions</div>
      <div class="small text-muted mb-2">La signature actuelle est gardée pour les documents des trimestres <b>avant</b> celui que vous choisissez ; la nouvelle s'applique à partir de lui. Seuls la signature et le titre figurent sur les documents (jamais le nom).</div>
      <form method="post" enctype="multipart/form-data" class="row g-2"><?= csrf_champ() ?><input type="hidden" name="action" value="changer">
        <div class="col-md-6"><label class="form-label small fw-semibold">Signe à partir du</label>
          <select name="trim" class="form-select form-select-sm" required><option value="">— Trimestre (<?= h($annee['val_annee'] ?? '') ?>) —</option>
          <?php foreach ($trims as $o => $l): ?><option value="<?= $o ?>"><?= h($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label small fw-semibold">Signature du nouveau chef</label>
          <input type="file" name="signature" accept="image/jpeg,image/png" class="form-control form-control-sm" required></div>
        <div class="col-md-6"><label class="form-label small">Nouveau titre (si différent)</label><input name="titre_fr" class="form-control form-control-sm" placeholder="<?= h($titre_fr) ?>"></div>
        <div class="col-md-6"><label class="form-label small">… en anglais</label><input name="titre_en" class="form-control form-control-sm" placeholder="<?= h($titre_en) ?>"></div>
        <div class="col-12"><label class="form-label small">Note interne (ex. nom de l'ancien chef — jamais imprimée)</label><input name="note" maxlength="150" class="form-control form-control-sm"></div>
        <div class="col-12"><button class="btn btn-primary btn-sm"><i class="bi bi-check2 me-1"></i>Enregistrer le changement</button></div>
      </form>
      <div class="small text-muted mt-2"><i class="bi bi-info-circle me-1"></i>Comptes de connexion : déclarez le départ de l'ancien chef depuis sa fiche (Personnel → Situation), puis créez ou activez le compte du nouveau.</div>
    </div></div>
  </div>
  <div class="col-lg-6">
    <div class="card"><div class="card-body">
      <div class="fw-semibold mb-2">Anciens chefs (signatures gardées)</div>
      <?php if (!$hist): ?><div class="text-muted small">Aucun changement enregistré.</div><?php endif; ?>
      <?php foreach ($hist as $r): $lib = 'T' . (int) $r['remplace_trim'] . ' ' . (int) $r['remplace_annee'] . '/' . ((int) $r['remplace_annee'] + 1); ?>
        <div class="d-flex align-items-center gap-3 border rounded p-2 mb-2">
          <?php if ($r['signature']): ?><img src="<?= h($url($r['signature'])) ?>" alt="" style="max-height:48px;max-width:110px;background:#fff"><?php else: ?><span class="text-muted small" style="width:110px">sans signature</span><?php endif; ?>
          <div class="small flex-grow-1"><b><?= h($r['titre_fr'] ?: '—') ?></b><?= $r['note'] ? ' · ' . h($r['note']) : '' ?><br>
            <span class="text-muted">Signe les documents jusqu'au trimestre avant le <?= h($lib) ?></span></div>
          <form method="post" onsubmit="return confirm('Supprimer ce changement ? Ces trimestres prendront la signature actuelle.')"><?= csrf_champ() ?>
            <input type="hidden" name="action" value="supprimer"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <button class="btn btn-sm btn-outline-danger" title="Supprimer"><i class="bi bi-trash"></i></button></form>
        </div>
      <?php endforeach; ?>
    </div></div>
  </div>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/layout/footer.php'; ?>
