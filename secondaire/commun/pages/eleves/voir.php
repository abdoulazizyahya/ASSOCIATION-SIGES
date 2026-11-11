<?php
// secondaire/commun/pages/eleves/voir.php — fiche d'un élève (école secondaire),
// alignée sur primaire/pages/eleves/voir.php (30/10/2026) : photo, état civil,
// parents et tuteurs (table `tuteur`, lien « Père »/« Mère »/…), historique
// scolaire, informations complémentaires (info_supplementaires, migration
// secondaire v26), dossier, et documents : fiche PDF, certificat de
// scolarité, carte scolaire (5 modèles, tous les formats de format_carte).
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
exiger_connexion();

$id    = (int) ($_GET['id'] ?? 0);
exiger_acces_eleve_secondaire($id);   // enseignant / SG : seulement leurs classes
$eleve = $id ? db_one("SELECT * FROM eleve WHERE id=?", [$id]) : null;
if (!$eleve) { flash_set('erreur', 'Élève introuvable.'); rediriger('secondaire/commun/pages/eleves/liste.php'); }

$lecture_seule = function_exists('est_lecture_seule') && est_lecture_seule();
$proprio       = function_exists('est_proprietaire_ecole') && est_proprietaire_ecole();
// Mêmes rôles que form.php / save_parents.php / save_info.php / dossier_upload.php.
$peut_gerer     = ($proprio || in_array(role_connecte(), ['ADMIN', 'PROVISEUR', 'FONDATEUR', 'CENSEUR', 'SG', 'SECRETAIRE'], true)) && !$lecture_seule;
$peut_supprimer = sec_peut_supprimer() && !$lecture_seule;
// Paiements : mêmes rôles que le menu (public : paiements/index.php ; privé : paiements_prives/versement.php).
$prive = ecole_est_privee();
$peut_voir_paiements = $proprio || in_array(role_connecte(), $prive
    ? ['ADMIN', 'PROVISEUR', 'FONDATEUR', 'SECRETAIRE', 'INTENDANT']
    : ['ADMIN', 'PROVISEUR', 'FONDATEUR', 'CENSEUR', 'INTENDANT'], true);

$annee    = get_annee_active();
$id_annee = (int) ($annee['id'] ?? 0);
$inscriptions = db_all(
    "SELECT i.*, c.designation AS classe, s.libelle AS serie, a.libelle AS annee_lib
     FROM inscription i JOIN classe c ON c.id=i.id_classe
     LEFT JOIN serie s ON s.id=i.id_serie
     LEFT JOIN annee_scolaire a ON a.id=i.id_annee
     WHERE i.id_eleve=? ORDER BY i.id_annee DESC", [$id]
);
$inscription = null;
foreach ($inscriptions as $i) { if ((int) $i['id_annee'] === $id_annee) { $inscription = $i; break; } }

// Parents / tuteurs : tout est dans `tuteur` (voir save_parents.php).
$tuteur_adresse = db_colonne_existe('tuteur', 'adresse');
$pere = null; $mere = null; $autres_tuteurs = [];
foreach (db_all("SELECT * FROM tuteur WHERE id_eleve=? ORDER BY id", [$id]) as $t) {
    $lien = mb_strtolower((string) ($t['lien'] ?? ''));
    if (!$pere && (str_contains($lien, 'pere') || str_contains($lien, 'père'))) $pere = $t;
    elseif (!$mere && (str_contains($lien, 'mere') || str_contains($lien, 'mère'))) $mere = $t;
    else $autres_tuteurs[] = $t;
}

$info_dispo = db_colonne_existe('info_supplementaires', 'id_eleve');   // migration secondaire v26
$info = $info_dispo ? (db_one("SELECT * FROM info_supplementaires WHERE id_eleve=?", [$id]) ?? []) : [];

// Dossier de l'élève (pièces jointes) — table dossier_eleve (migration secondaire v14).
$types_dossier = ['acte_naissance', 'carnet_vaccination', 'bulletin', 'document_transfert', 'photo_4x4', 'autre'];
try {
    $documents = db_all("SELECT * FROM dossier_eleve WHERE id_eleve=? ORDER BY type_document, date_ajout DESC", [$id]);
} catch (\Throwable $e) {
    $documents = null;
}

$formats_carte = db_all("SELECT id, libelle FROM format_carte ORDER BY id");
$a_photo = photo_sec_presente($eleve['photo'] ?? null);
$nom_complet = trim(mb_strtoupper((string) $eleve['nom']) . ' ' . ($eleve['prenom'] ?? ''));

// Ligne « libellé : valeur » des blocs d'information.
$ligne = fn(string $lbl, $val, int $w = 160): string =>
    '<div class="d-flex py-1" style="border-bottom:1px solid #f3f4f6;font-size:.8rem">'
  . '<span style="min-width:' . $w . 'px;color:#6b7280;font-size:.74rem">' . h($lbl) . '</span>'
  . '<strong>' . h(($val ?? '') !== '' ? (string) $val : '—') . '</strong></div>';

$titre_page = 'Fiche élève';
require_once __DIR__ . '/../../../../noyau/layout/header.php';
?>

<!-- En-tête -->
<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <a href="<?= APP_URL ?>/secondaire/commun/pages/eleves/liste.php" class="btn btn-sm btn-light">
    <i class="bi bi-arrow-left me-1"></i>Retour
  </a>
  <div class="flex-grow-1">
    <h4 class="mb-0" style="font-size:1.05rem;font-weight:700"><?= h($nom_complet) ?></h4>
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <span style="font-size:.75rem;color:#6b7280"><?= h($eleve['matricule']) ?></span>
      <?php if ($eleve['statut'] === 'actif'): ?>
        <span style="background:#d1fae5;color:#065f46;font-size:.68rem;padding:1px 8px;border-radius:10px;font-weight:600">
          <i class="bi bi-circle-fill me-1" style="font-size:.4rem"></i>Actif
        </span>
      <?php else: ?>
        <span style="background:#fee2e2;color:#991b1b;font-size:.68rem;padding:1px 8px;border-radius:10px;font-weight:600">
          <i class="bi bi-circle-fill me-1" style="font-size:.4rem"></i>Désactivé
        </span>
      <?php endif; ?>
    </div>
  </div>
  <div class="d-flex gap-1 flex-wrap">
    <button type="button" class="btn btn-outline-danger btn-sm"
            onclick="afficherApercu('<?= APP_URL ?>/secondaire/commun/pdf/fiche_eleve.php?id=<?= $id ?>', 'Fiche élève', 'fiche_eleve', 'portrait')">
      <i class="bi bi-file-earmark-pdf me-1"></i>Fiche PDF
    </button>
    <?php if ($inscription): ?>
      <button type="button" class="btn btn-outline-success btn-sm"
              onclick="afficherApercu('<?= APP_URL ?>/secondaire/commun/pdf/certificat_scolarite.php?id=<?= $id ?>', 'Certificat de scolarité', 'certificat_scolarite', 'portrait')">
        <i class="bi bi-patch-check me-1"></i>Certificat de scolarité
      </button>
      <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalCarte">
        <i class="bi bi-credit-card me-1"></i>Carte scolaire
      </button>
    <?php else: ?>
      <span class="btn btn-outline-secondary btn-sm disabled" title="Aucune inscription pour l'année active">
        <i class="bi bi-patch-check me-1"></i>Certificat / carte
      </span>
    <?php endif; ?>
  </div>
  <?php if ($peut_voir_paiements && $inscription): ?>
    <a href="<?= APP_URL ?>/secondaire/commun/pages/<?= $prive ? 'paiements_prives/versement.php' : 'paiements/index.php' ?>?classe=<?= (int) $inscription['id_classe'] ?>&eleve=<?= $id ?>"
       class="btn btn-outline-primary btn-sm">
      <i class="bi bi-cash-coin me-1"></i>Paiements
    </a>
  <?php endif; ?>
  <?php if ($peut_gerer || $peut_supprimer): ?>
  <div class="d-flex gap-1 flex-wrap">
    <?php if ($peut_gerer): ?>
      <a href="<?= APP_URL ?>/secondaire/commun/pages/eleves/form.php?id=<?= $id ?>" class="btn btn-primary btn-sm">
        <i class="bi bi-pencil me-1"></i>Modifier
      </a>
    <?php endif; ?>
    <?php if ($peut_supprimer): ?>
      <a href="<?= APP_URL ?>/secondaire/commun/pages/eleves/statut.php?id=<?= $id ?>&csrf=<?= csrf_generer() ?>"
         class="btn btn-sm <?= $eleve['statut'] === 'actif' ? 'btn-outline-warning' : 'btn-outline-success' ?>"
         onclick="return confirm('<?= $eleve['statut'] === 'actif' ? 'Désactiver' : 'Réactiver' ?> cet élève ?')">
        <i class="bi bi-toggle-<?= $eleve['statut'] === 'actif' ? 'on' : 'off' ?> me-1"></i><?= $eleve['statut'] === 'actif' ? 'Désactiver' : 'Réactiver' ?>
      </a>
      <form method="post" action="<?= APP_URL ?>/secondaire/commun/pages/eleves/supprimer_masse.php" class="d-inline"
            onsubmit="return confirm('Supprimer DÉFINITIVEMENT <?= h(addslashes($nom_complet)) ?> ? Action IRRÉVERSIBLE : ses notes, absences, paiements, parents et inscriptions seront aussi effacés.')">
        <?= csrf_champ() ?>
        <input type="hidden" name="ids[]" value="<?= $id ?>">
        <button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash me-1"></i>Supprimer</button>
      </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<?= flash_html() ?>

<div class="row g-3">

  <!-- Photo + identité -->
  <div class="col-lg-3">
    <div class="card text-center h-100">
      <div class="card-body">
        <img id="photo-eleve" src="<?= h($a_photo ? url_photo_eleve_sec($id, true, (string) $eleve['sexe']) . '&v=' . substr(md5((string) $eleve['photo'] . $id), 0, 6) : url_photo_eleve_sec($id, false, (string) $eleve['sexe'])) ?>"
             style="width:100px;height:120px;object-fit:cover;border-radius:8px;border:2px solid #d1daf0;margin-bottom:.6rem" alt="">
        <?php if ($peut_gerer && $a_photo): ?>
          <div class="mb-2" id="zone-suppr-photo">
            <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" style="font-size:.72rem" onclick="supprimerPhotoEleve(this)">
              <i class="bi bi-trash me-1"></i>Supprimer la photo
            </button>
          </div>
          <script>
          // Même action que l'onglet « Photos par classe » (photo_enregistrer.php).
          function supprimerPhotoEleve(btn) {
            if (!confirm('Supprimer la photo de cet élève ?')) return;
            btn.disabled = true;
            const fd = new FormData();
            fd.append('csrf', <?= json_encode(csrf_generer()) ?>);
            fd.append('id_eleve', <?= $id ?>);
            fd.append('action', 'supprimer');
            fetch(<?= json_encode(APP_URL . '/secondaire/commun/pages/eleves/photo_enregistrer.php') ?>, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
              .then(r => r.json())
              .then(j => {
                if (!j.ok) throw new Error(j.message || 'Suppression impossible.');
                if (j.url) document.getElementById('photo-eleve').src = j.url;
                document.getElementById('zone-suppr-photo').remove();
              })
              .catch(e => { alert(e.message); btn.disabled = false; });
          }
          </script>
        <?php endif; ?>
        <div class="fw-bold" style="font-size:.88rem"><?= h($nom_complet) ?></div>
        <div style="font-size:.72rem;color:#6b7280"><?= h($eleve['matricule']) ?></div>
        <div style="font-size:.68rem;color:#9ca3af">ID élève #<?= $id ?><?= niu_eleve($eleve) !== '' ? ' · NIU ' . h(niu_eleve($eleve)) : '' ?></div>
        <div class="mt-1"><?= $eleve['sexe'] === 'F' ? '<span class="badge-f">Féminin</span>' : '<span class="badge-m">Masculin</span>' ?></div>
        <?php if ($inscription): ?>
          <div class="mt-2" style="background:#f0f5ff;border-radius:7px;padding:4px 8px;font-size:.74rem">
            <i class="bi bi-door-open me-1 text-primary"></i><?= h($inscription['classe']) ?>
            <?php if ($inscription['serie']): ?><span class="badge-code ms-1"><?= h($inscription['serie']) ?></span><?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- État civil + parents + tuteurs -->
  <div class="col-lg-5">
    <div class="card mb-2">
      <div class="card-body">
        <div class="section-titre"><i class="bi bi-person me-1"></i>État civil</div>
        <?= $ligne('Date de naissance', date_fr($eleve['date_naiss'])) ?>
        <?= $ligne('Lieu de naissance', $eleve['lieu_naiss']) ?>
        <?= $ligne('Région de naissance', $eleve['region_naiss'] ?? '') ?>
        <?= $ligne('Département de naissance', $eleve['departement_naiss'] ?? '') ?>
        <?= $ligne('Arrondissement de naissance', $eleve['arrondissement_naiss'] ?? '') ?>
        <?= $ligne('NIU', niu_eleve($eleve)) ?>
        <?= $ligne('Téléphone', $eleve['telephone']) ?>
        <?= $ligne('Adresse', $eleve['adresse']) ?>
      </div>
    </div>

    <!-- Parents -->
    <div class="card mb-2">
      <div class="card-body">
        <div class="d-flex align-items-center justify-content-between">
          <div class="section-titre mb-0"><i class="bi bi-people me-1"></i>Parents</div>
          <?php if ($peut_gerer): ?>
          <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalParents">
            <i class="bi bi-pencil me-1"></i>Modifier
          </button>
          <?php endif; ?>
        </div>
        <div class="row g-2 mt-1">
          <?php foreach (['PÈRE' => $pere, 'MÈRE' => $mere] as $titre => $p): ?>
          <div class="col-6">
            <div style="font-size:.72rem;color:#6b7280;font-weight:600"><?= $titre ?></div>
            <?php if ($p): ?>
              <div class="fw-semibold" style="font-size:.8rem"><?= h(trim($p['nom'] . ' ' . ($p['prenom'] ?? ''))) ?></div>
              <?php if (!empty($p['profession'])): ?><div style="font-size:.74rem;color:#6b7280"><?= h($p['profession']) ?></div><?php endif; ?>
              <?php if (!empty($p['telephone'])): ?><div style="font-size:.74rem"><i class="bi bi-telephone me-1"></i><?= h($p['telephone']) ?></div><?php endif; ?>
              <?php if (!empty($p['adresse'])): ?><div style="font-size:.74rem"><i class="bi bi-geo-alt me-1"></i><?= h($p['adresse']) ?></div><?php endif; ?>
            <?php else: ?>
              <div class="text-muted" style="font-size:.78rem">—</div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Autres tuteurs -->
    <div class="card">
      <div class="card-body">
        <div class="d-flex align-items-center justify-content-between">
          <div class="section-titre mb-0"><i class="bi bi-person-badge me-1"></i>Autres tuteurs</div>
          <?php if ($peut_gerer): ?>
          <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalTuteur">
            <i class="bi bi-plus-lg me-1"></i>Ajouter
          </button>
          <?php endif; ?>
        </div>
        <?php if (empty($autres_tuteurs)): ?>
          <p class="text-muted mb-0 mt-1" style="font-size:.78rem">Aucun autre tuteur enregistré.</p>
        <?php else: foreach ($autres_tuteurs as $t): ?>
          <div class="d-flex align-items-center gap-2 py-1" style="border-bottom:1px solid #f3f4f6;font-size:.8rem">
            <span class="badge-code"><?= h($t['lien'] ?: 'Tuteur') ?></span>
            <div class="flex-grow-1">
              <div class="fw-semibold"><?= h(trim($t['nom'] . ' ' . ($t['prenom'] ?? ''))) ?></div>
              <div style="color:#6b7280;font-size:.72rem">
                <?= h(implode(' · ', array_filter([$t['profession'] ?? '', $t['telephone'] ?? '', $t['adresse'] ?? '']))) ?>
              </div>
            </div>
            <?php if ($peut_gerer): ?>
            <form method="post" action="<?= APP_URL ?>/secondaire/commun/pages/eleves/save_parents.php" onsubmit="return confirm('Retirer ce tuteur ?')">
              <?= csrf_champ() ?>
              <input type="hidden" name="id_eleve" value="<?= $id ?>">
              <input type="hidden" name="action" value="tuteur_supprimer">
              <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
              <button type="submit" class="btn btn-sm btn-light text-danger" style="padding:2px 6px">
                <i class="bi bi-trash" style="font-size:.72rem"></i>
              </button>
            </form>
            <?php endif; ?>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>

  <!-- Historique scolaire -->
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-body">
        <div class="section-titre"><i class="bi bi-journal-text me-1"></i>Historique scolaire</div>
        <?php if (!$inscriptions): ?>
          <p class="text-muted mb-0" style="font-size:.78rem">Aucune inscription.</p>
        <?php else: foreach ($inscriptions as $ins): ?>
          <div class="mb-2 pb-2" style="border-bottom:1px solid #eef1f7">
            <div class="d-flex justify-content-between align-items-center" style="font-size:.8rem">
              <span class="fw-bold"><?= h($ins['annee_lib'] ?? '—') ?></span>
              <span>
                <span class="fw-semibold"><?= h($ins['classe']) ?></span>
                <span class="badge-code ms-1"><?= h(libelle_statut_insc($ins['statut'])) ?></span>
              </span>
            </div>
            <div class="text-muted ps-2 mt-1" style="font-size:.72rem">
              <?php if ($ins['serie']): ?>Série / LV2 : <strong><?= h($ins['serie']) ?></strong> · <?php endif; ?>
              <?php if (!empty($ins['date_inscription'])): ?>Inscrit le <?= h(date_fr($ins['date_inscription'])) ?><?php endif; ?>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>

</div>

<?php if ($info_dispo): ?>
<!-- Informations complémentaires -->
<div class="row g-3 mt-0">
  <div class="col-12">
    <div class="card">
      <div class="card-body">
        <div class="d-flex align-items-center justify-content-between">
          <div class="section-titre mb-0"><i class="bi bi-clipboard2-pulse me-1"></i>Informations complémentaires</div>
          <?php if ($peut_gerer): ?>
          <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalInfoSuppl">
            <i class="bi bi-pencil me-1"></i>Modifier
          </button>
          <?php endif; ?>
        </div>
        <div class="row g-3 mt-1">
          <div class="col-md-4">
            <div style="font-size:.72rem;color:#6b7280;font-weight:600">SCOLARITÉ ANTÉRIEURE</div>
            <?= $ligne('Dernier établissement', $info['dernier_etab'] ?? '', 150) ?>
            <?= $ligne('Dernière classe', $info['derniere_classe'] ?? '', 150) ?>
            <?= $ligne('Date de recrutement', date_fr($info['date_rec'] ?? null), 150) ?>
          </div>
          <div class="col-md-4">
            <div style="font-size:.72rem;color:#6b7280;font-weight:600">SANTÉ / DIVERS</div>
            <?= $ligne('Antécédent médical', $info['antecedent_med'] ?? '', 150) ?>
            <?= $ligne('Fréquence', $info['frequence'] ?? '', 150) ?>
            <?= $ligne('Autres informations', $info['autres_infos'] ?? '', 150) ?>
          </div>
          <div class="col-md-4">
            <div style="font-size:.72rem;color:#6b7280;font-weight:600">SITUATION PARTICULIÈRE</div>
            <?= $ligne('Handicap', ($info['handicap'] ?? '') === 'OUI' ? 'Oui — ' . (($info['nature_handicap'] ?? '') ?: '—') : (($info['handicap'] ?? '') === 'NON' ? 'Non' : ''), 150) ?>
            <?= $ligne('Réfugié(e)', ($info['refugie'] ?? '') === 'OUI' ? 'Oui — ' . (($info['type_refugie'] ?? '') ?: '—') : (($info['refugie'] ?? '') === 'NON' ? 'Non' : ''), 150) ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($documents !== null): ?>
<!-- Dossier de l'élève -->
<div class="row g-3 mt-0">
  <div class="col-12">
    <div class="card">
      <div class="card-body">
        <div class="d-flex align-items-center justify-content-between">
          <div class="section-titre mb-0"><i class="bi bi-folder2-open me-1"></i>Dossier de l'élève</div>
          <?php if ($peut_gerer): ?>
          <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalDossier">
            <i class="bi bi-plus-lg me-1"></i>Ajouter un document
          </button>
          <?php endif; ?>
        </div>
        <?php if (!$documents): ?>
          <p class="text-muted mb-0 mt-1" style="font-size:.78rem">Aucune pièce jointe (acte de naissance, carnet de vaccination, bulletin, document de transfert, photo 4×4…).</p>
        <?php else: ?>
          <div class="table-responsive mt-1">
            <table class="table table-sm table-abz mb-0" style="font-size:.8rem">
              <thead><tr><th>Type</th><th>Libellé</th><th>Ajouté le</th><th class="text-end">Actions</th></tr></thead>
              <tbody>
                <?php foreach ($documents as $d): ?>
                  <tr>
                    <td><span class="badge-code"><?= h(libelle_type_dossier($d['type_document'])) ?></span></td>
                    <td><?= h($d['libelle'] ?: '—') ?></td>
                    <td style="color:#6b7280"><?= h(date('d/m/Y', strtotime($d['date_ajout']))) ?></td>
                    <td class="text-end text-nowrap">
                      <a href="<?= APP_URL ?>/secondaire/commun/pages/eleves/dossier_fichier.php?id=<?= (int) $d['id'] ?>" target="_blank"
                         class="btn btn-sm" style="background:#eef2ff;color:#1e4fd8;padding:3px 7px" title="Voir">
                        <i class="bi bi-eye" style="font-size:.78rem"></i>
                      </a>
                      <?php if ($peut_gerer): ?>
                      <form method="post" action="<?= APP_URL ?>/secondaire/commun/pages/eleves/dossier_supprimer.php" class="d-inline"
                            onsubmit="return confirm('Retirer ce document du dossier ?')">
                        <?= csrf_champ() ?>
                        <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                        <button class="btn btn-sm btn-light text-danger" style="padding:3px 7px" title="Retirer">
                          <i class="bi bi-trash" style="font-size:.78rem"></i>
                        </button>
                      </form>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($inscription): ?>
<!-- Carte scolaire : modèle, format (format_carte), recto-verso -->
<div class="modal fade" id="modalCarte" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title fw-bold"><i class="bi bi-credit-card me-1 text-primary"></i>Carte scolaire</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body py-2">
        <label class="form-label">Modèle</label>
        <select id="selCarteModele" class="form-select form-select-sm mb-2">
          <option value="1">Modèle 1 — Officiel bilingue</option>
          <option value="2">Modèle 2 — Encadré classique</option>
          <option value="3">Modèle 3 — Bandeau année</option>
          <option value="4">Modèle 4 — Badge centré</option>
          <option value="5">Modèle 5 — Minimaliste</option>
          <option value="6">Modèle 6 — Mon modèle (concepteur)</option>
        </select>
        <label class="form-label">Format</label>
        <select id="selCarteFormat" class="form-select form-select-sm mb-2">
          <?php foreach ($formats_carte as $f): ?>
            <option value="<?= (int) $f['id'] ?>"><?= h(str_replace(' ? ', ' × ', $f['libelle'])) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-check mb-1">
          <input class="form-check-input" type="checkbox" id="chkCarteVerso" onchange="document.getElementById('optCarteFlip').classList.toggle('d-none', !this.checked)">
          <label class="form-check-label" for="chkCarteVerso" style="font-size:.82rem">Imprimer le verso (recto-verso)</label>
        </div>
        <div id="optCarteFlip" class="d-none">
          <label class="form-label" style="font-size:.78rem">Retournement de la feuille</label>
          <select id="selCarteFlip" class="form-select form-select-sm">
            <option value="long">Bord long (comme un livre)</option>
            <option value="short">Bord court (comme un bloc-notes)</option>
          </select>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button class="btn btn-primary btn-sm" onclick="genererCarteUnique()">
          <i class="bi bi-printer me-1"></i>Générer
        </button>
        <button class="btn btn-light btn-sm" data-bs-dismiss="modal">Annuler</button>
      </div>
    </div>
  </div>
</div>
<script>
function genererCarteUnique() {
  const modele = document.getElementById('selCarteModele').value;
  const verso  = document.getElementById('chkCarteVerso').checked ? '1' : '0';
  afficherApercuApresFermeture('modalCarte',
    '<?= APP_URL ?>/secondaire/commun/pdf/cartes.php?annee=<?= $id_annee ?>&eleve=<?= $id ?>&modele=' + modele
      + '&format=' + document.getElementById('selCarteFormat').value + '&verso=' + verso
      + '&flip=' + document.getElementById('selCarteFlip').value,
    'Carte scolaire', verso === '1' ? 'carte_verso' : ('carte_' + modele), 'card');
}
</script>
<?php endif; ?>

<?php if ($peut_gerer): ?>
<!-- Parents -->
<div class="modal fade" id="modalParents" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" action="<?= APP_URL ?>/secondaire/commun/pages/eleves/save_parents.php">
        <?= csrf_champ() ?>
        <input type="hidden" name="id_eleve" value="<?= $id ?>">
        <input type="hidden" name="action" value="parents">
        <div class="modal-header py-2">
          <h6 class="modal-title fw-bold"><i class="bi bi-people me-1 text-primary"></i>Parents de l'élève</h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <?php foreach (['pere_' => ['Père', $pere], 'mere_' => ['Mère', $mere]] as $p => [$titre, $val]): ?>
            <div class="col-6">
              <div class="fw-semibold mb-1" style="font-size:.8rem;color:#1a3c6b"><?= $titre ?></div>
              <input type="text" name="<?= $p ?>nom" class="form-control form-control-sm mb-1" placeholder="Nom" value="<?= h($val['nom'] ?? '') ?>">
              <input type="text" name="<?= $p ?>prenom" class="form-control form-control-sm mb-1" placeholder="Prénom" value="<?= h($val['prenom'] ?? '') ?>">
              <input type="text" name="<?= $p ?>profession" class="form-control form-control-sm mb-1" placeholder="Profession" value="<?= h($val['profession'] ?? '') ?>">
              <input type="text" name="<?= $p ?>telephone" class="form-control form-control-sm mb-1" placeholder="Téléphone" value="<?= h($val['telephone'] ?? '') ?>">
              <?php if ($tuteur_adresse): ?>
              <input type="text" name="<?= $p ?>adresse" class="form-control form-control-sm" placeholder="Adresse" value="<?= h($val['adresse'] ?? '') ?>">
              <?php endif; ?>
            </div>
            <?php endforeach; ?>
          </div>
          <div class="form-text mt-2" style="font-size:.72rem">Laisser tous les champs d'un parent vides le retire. Le premier parent/tuteur et son téléphone apparaissent comme contact sur le bulletin.</div>
        </div>
        <div class="modal-footer py-2">
          <button class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Enregistrer</button>
          <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Annuler</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Ajout d'un tuteur -->
<div class="modal fade" id="modalTuteur" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" action="<?= APP_URL ?>/secondaire/commun/pages/eleves/save_parents.php">
        <?= csrf_champ() ?>
        <input type="hidden" name="id_eleve" value="<?= $id ?>">
        <input type="hidden" name="action" value="tuteur_ajouter">
        <div class="modal-header py-2">
          <h6 class="modal-title fw-bold"><i class="bi bi-person-badge me-1 text-primary"></i>Ajouter un tuteur</h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <select name="tuteur_lien" class="form-select form-select-sm mb-1">
            <?php foreach (['Tuteur', 'Oncle', 'Tante', 'Grand-père', 'Grand-mère', 'Frère', 'Sœur', 'Autre'] as $l): ?>
              <option value="<?= h($l) ?>"><?= h($l) ?></option>
            <?php endforeach; ?>
          </select>
          <input type="text" name="tuteur_nom" class="form-control form-control-sm mb-1" placeholder="Nom" required>
          <input type="text" name="tuteur_prenom" class="form-control form-control-sm mb-1" placeholder="Prénom">
          <input type="text" name="tuteur_profession" class="form-control form-control-sm mb-1" placeholder="Profession">
          <input type="text" name="tuteur_telephone" class="form-control form-control-sm mb-1" placeholder="Téléphone">
          <?php if ($tuteur_adresse): ?>
          <input type="text" name="tuteur_adresse" class="form-control form-control-sm" placeholder="Adresse">
          <?php endif; ?>
        </div>
        <div class="modal-footer py-2">
          <button class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Ajouter</button>
          <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Annuler</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php if ($info_dispo): ?>
<!-- Informations complémentaires -->
<div class="modal fade" id="modalInfoSuppl" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" action="<?= APP_URL ?>/secondaire/commun/pages/eleves/save_info.php">
        <?= csrf_champ() ?>
        <input type="hidden" name="id_eleve" value="<?= $id ?>">
        <div class="modal-header py-2">
          <h6 class="modal-title fw-bold"><i class="bi bi-clipboard2-pulse me-1 text-primary"></i>Informations complémentaires</h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-4">
              <div class="fw-semibold mb-1" style="font-size:.8rem;color:#1a3c6b">Scolarité antérieure</div>
              <input type="text" name="dernier_etab" class="form-control form-control-sm mb-1" placeholder="Dernier établissement fréquenté" value="<?= h($info['dernier_etab'] ?? '') ?>">
              <input type="text" name="derniere_classe" class="form-control form-control-sm mb-1" placeholder="Dernière classe fréquentée" value="<?= h($info['derniere_classe'] ?? '') ?>">
              <label class="form-label" style="font-size:.72rem">Date de recrutement</label>
              <input type="date" name="date_rec" class="form-control form-control-sm" value="<?= h($info['date_rec'] ?? '') ?>">
            </div>
            <div class="col-md-4">
              <div class="fw-semibold mb-1" style="font-size:.8rem;color:#1a3c6b">Santé / Divers</div>
              <input type="text" name="antecedent_med" class="form-control form-control-sm mb-1" placeholder="Antécédent médical" value="<?= h($info['antecedent_med'] ?? '') ?>">
              <input type="text" name="frequence" class="form-control form-control-sm mb-1" placeholder="Fréquence" value="<?= h($info['frequence'] ?? '') ?>">
              <input type="text" name="autres_infos" class="form-control form-control-sm" placeholder="Autres informations" value="<?= h($info['autres_infos'] ?? '') ?>">
            </div>
            <div class="col-md-4">
              <div class="fw-semibold mb-1" style="font-size:.8rem;color:#1a3c6b">Situation particulière</div>
              <label class="form-label" style="font-size:.72rem">Handicap</label>
              <select name="handicap" class="form-select form-select-sm mb-1" onchange="document.getElementById('zoneNature').style.display=this.value==='OUI'?'':'none'">
                <option value="">—</option>
                <option value="OUI" <?= ($info['handicap'] ?? '') === 'OUI' ? 'selected' : '' ?>>Oui</option>
                <option value="NON" <?= ($info['handicap'] ?? '') === 'NON' ? 'selected' : '' ?>>Non</option>
              </select>
              <div id="zoneNature" style="display:<?= ($info['handicap'] ?? '') === 'OUI' ? '' : 'none' ?>">
                <input type="text" name="nature_handicap" class="form-control form-control-sm mb-1" placeholder="Nature du handicap" value="<?= h($info['nature_handicap'] ?? '') ?>">
              </div>
              <label class="form-label" style="font-size:.72rem">Réfugié(e)</label>
              <select name="refugie" class="form-select form-select-sm mb-1" onchange="document.getElementById('zoneType').style.display=this.value==='OUI'?'':'none'">
                <option value="">—</option>
                <option value="OUI" <?= ($info['refugie'] ?? '') === 'OUI' ? 'selected' : '' ?>>Oui</option>
                <option value="NON" <?= ($info['refugie'] ?? '') === 'NON' ? 'selected' : '' ?>>Non</option>
              </select>
              <div id="zoneType" style="display:<?= ($info['refugie'] ?? '') === 'OUI' ? '' : 'none' ?>">
                <select name="type_refugie" class="form-select form-select-sm">
                  <option value="">—</option>
                  <option value="Interne" <?= ($info['type_refugie'] ?? '') === 'Interne' ? 'selected' : '' ?>>Interne</option>
                  <option value="Externe" <?= ($info['type_refugie'] ?? '') === 'Externe' ? 'selected' : '' ?>>Externe</option>
                </select>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer py-2">
          <button class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Enregistrer</button>
          <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Annuler</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($documents !== null): ?>
<!-- Ajout d'un document au dossier de l'élève -->
<div class="modal fade" id="modalDossier" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" action="<?= APP_URL ?>/secondaire/commun/pages/eleves/dossier_upload.php" enctype="multipart/form-data">
        <?= csrf_champ() ?>
        <input type="hidden" name="id_eleve" value="<?= $id ?>">
        <div class="modal-header py-2">
          <h6 class="modal-title fw-bold"><i class="bi bi-folder2-open me-1 text-primary"></i>Ajouter un document</h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <label class="form-label">Type de document</label>
          <select name="type_document" class="form-select form-select-sm mb-2" required>
            <?php foreach ($types_dossier as $t): ?>
              <option value="<?= $t ?>"><?= h(libelle_type_dossier($t)) ?></option>
            <?php endforeach; ?>
          </select>
          <label class="form-label">Libellé (optionnel)</label>
          <input type="text" name="libelle" class="form-control form-control-sm mb-2" placeholder="Ex. Bulletin 1er trimestre">
          <label class="form-label">Fichier</label>
          <input type="file" name="fichier" accept=".pdf,.jpg,.jpeg,.png" class="form-control form-control-sm" required>
          <div class="form-text" style="font-size:.68rem">PDF, JPG ou PNG — 750 Ko max.</div>
        </div>
        <div class="modal-footer py-2">
          <button class="btn btn-primary btn-sm"><i class="bi bi-upload me-1"></i>Ajouter</button>
          <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Annuler</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php // Apparence des documents (11/11/2026) : choix par défaut des cartes de l'école.
require_once __DIR__ . '/../../../../noyau/partage/apparence_docs.php'; $__cd = app_reglages('cartes'); ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var d = <?= json_encode(['modele' => (int) $__cd['modele'], 'format' => (int) $__cd['format'], 'verso' => (int) $__cd['verso'], 'signature' => (int) $__cd['signature']]) ?>;
  var m = document.getElementById('selModele') || document.getElementById('selCarteModele'); if (m) m.value = String(d.modele);
  var f = document.getElementById('selFormat') || document.getElementById('selCarteFormat'); if (f && d.format && f.querySelector('option[value="' + d.format + '"]')) f.value = String(d.format);
  var v = document.getElementById('chkVerso') || document.getElementById('chkCarteVerso'); if (v && d.verso) { v.checked = true; var o = document.getElementById('optFlip') || document.getElementById('optCarteFlip'); if (o) o.classList.remove('d-none'); }
});
</script>
<?php require_once __DIR__ . '/../../../../noyau/layout/footer.php'; ?>
