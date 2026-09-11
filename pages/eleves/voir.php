<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_connexion();

$id    = (int)($_GET['id'] ?? 0);
exiger_acces_eleve($id, 'union');   // enseignant restreint : élève hors de ses classes -> refus
$eleve = db_one("SELECT * FROM eleve WHERE id_eleve=?", [$id]);
if (!$eleve) { flash_set('erreur', 'Élève introuvable.'); rediriger('pages/eleves/liste.php'); }

// Écriture déléguée : superadmin association entré en écriture, ou FONDATEUR
// (il gère les élèves — voir ecole_contexte.php::fondateur_ecriture_permise).
$ecriture_deleguee = est_ecriture_deleguee();
$peut_gerer = ($ecriture_deleguee || in_array(role_connecte(), ['DIRECTEUR','SECRETAIRE','COMPTABLE'], true))
            && !(function_exists('est_lecture_seule') && est_lecture_seule());
// Fiche PDF / Certificat de scolarité / Carte scolaire : jamais pour le
// profil COMPTABLE (Agent financier) — demande explicite du 22/08/2026, voir
// interdire_role() dans pdf/fiche_eleve.php, certificat_scolarite.php,
// cartes.php (le blocage réel est là ; ce flag n'évite qu'un clic dans le vide).
$peut_voir_documents = role_connecte() !== 'COMPTABLE';

$inscriptions = db_all(
    "SELECT i.*, c.DesignationClasses
     FROM inscrire i JOIN classe c ON c.IDClasses=i.IDClasses
     WHERE i.id_eleve=? ORDER BY i.val_annee DESC", [$id]
);
$classe_actuelle = $inscriptions[0]['DesignationClasses'] ?? null;

$parents = db_all("SELECT * FROM parent WHERE id_eleve=?", [$id]);
$pere    = null; $mere = null; $autres_tuteurs = [];
foreach ($parents as $p) {
    if ($p['sexe'] === 'Masculin' && !$pere) $pere = $p;
    elseif ($p['sexe'] === 'Feminin' && !$mere) $mere = $p;
    else $autres_tuteurs[] = $p;
}

// Connaître l'arrondissement suffit à retrouver département et région par
// jointure — jamais stockés séparément (voir bd/migration_v5.sql). Repli
// sur arrondissement_elv (texte libre) si l'arrondissement n'est pas
// répertorié dans la liste officielle ou si la fiche n'a pas encore été liée.
$lieu = $eleve['id_arrondissement']
    ? db_one(
        "SELECT a.intitule_arrond, d.intitule_depart, r.intitule_region
         FROM arrondissement a
         JOIN departement d ON d.code_depart = a.code_depart
         JOIN region r ON r.id_region = d.code_region
         WHERE a.code_arrond = ?",
        [$eleve['id_arrondissement']]
      )
    : null;

$info = db_one("SELECT * FROM info_supplementaires WHERE id_eleve=?", [$id]) ?? [];

$documents = db_all("SELECT * FROM dossier_eleve WHERE id_eleve=? ORDER BY type_document, date_ajout DESC", [$id]);
$types_dossier = ['acte_naissance','carnet_vaccination','bulletin','document_transfert','photo_4x4','autre'];

if (empty($_SESSION['csrf_parents'])) $_SESSION['csrf_parents'] = bin2hex(random_bytes(32));
$csrf_parents = $_SESSION['csrf_parents'];

// Mode « partiel » (AJAX) : réponse limitée au contenu de #fiche-eleve-zone,
// sans header/sidebar/footer — même convention que pages/statistiques/index.php.
// Utile pour soumettreFormulaireAjax() (layout/footer.php) : les formulaires
// de cette page (save_info.php, save_parents.php, dossier_upload.php)
// redirigent tous vers CETTE page après enregistrement.
$es_partiel = isset($_GET['partiel']);
if (!$es_partiel) {
    $titre_page = 'Fiche élève';
    require_once __DIR__ . '/../../layout/header.php';
} else {
    header('Content-Type: text/html; charset=utf-8');
}
?>

<div id="fiche-eleve-zone">

<!-- En-tête -->
<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <a href="<?= APP_URL ?>/pages/eleves/liste.php" class="btn btn-sm btn-light">
    <i class="bi bi-arrow-left me-1"></i>Retour
  </a>
  <div class="flex-grow-1">
    <h4 class="mb-0" style="font-size:1.05rem;font-weight:700">
      <?= h(mb_strtoupper($eleve['Nom_elv'])) ?> <?= h($eleve['Prenom_elv'] ?? '') ?>
    </h4>
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <span style="font-size:.75rem;color:#6b7280"><?= h($eleve['Mat_elv']) ?></span>
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
  <?php if ($peut_voir_documents): ?>
  <div class="d-flex gap-1 flex-wrap">
    <button type="button" class="btn btn-outline-danger btn-sm"
            onclick="afficherApercu('<?= APP_URL ?>/pdf/fiche_eleve.php?id=<?= $id ?>', 'Fiche élève', 'fiche_eleve', 'portrait')">
      <i class="bi bi-file-earmark-pdf me-1"></i>Fiche PDF
    </button>
    <button type="button" class="btn btn-outline-success btn-sm"
            onclick="afficherApercu('<?= APP_URL ?>/pdf/certificat_scolarite.php?id=<?= $id ?>', 'Certificat de scolarité', 'certificat_scolarite', 'portrait')">
      <i class="bi bi-patch-check me-1"></i>Certificat de scolarité
    </button>
    <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalCarte">
      <i class="bi bi-credit-card me-1"></i>Carte scolaire
    </button>
  </div>
  <?php endif; ?>
  <?php if ($peut_gerer): ?>
  <div class="d-flex gap-1 flex-wrap">
    <a href="<?= APP_URL ?>/pages/eleves/form.php?id=<?= $id ?>" class="btn btn-primary btn-sm">
      <i class="bi bi-pencil me-1"></i>Modifier
    </a>
    <?php if ($eleve['statut'] === 'actif'): ?>
      <a href="<?= APP_URL ?>/pages/eleves/statut.php?id=<?= $id ?>&csrf=<?= csrf_generer() ?>"
         class="btn btn-outline-warning btn-sm" onclick="return confirm('Désactiver cet élève ?')">
        <i class="bi bi-toggle-on me-1"></i>Désactiver
      </a>
    <?php else: ?>
      <a href="<?= APP_URL ?>/pages/eleves/statut.php?id=<?= $id ?>&csrf=<?= csrf_generer() ?>"
         class="btn btn-outline-success btn-sm">
        <i class="bi bi-toggle-off me-1"></i>Réactiver
      </a>
    <?php endif; ?>
    <?php if (role_connecte() === 'DIRECTEUR' || $ecriture_deleguee): ?>
      <a href="<?= APP_URL ?>/pages/eleves/supprimer.php?id=<?= $id ?>&csrf=<?= csrf_generer() ?>"
         class="btn btn-outline-danger btn-sm"
         onclick="return confirm('Supprimer définitivement <?= h(addslashes(mb_strtoupper($eleve['Nom_elv']))) ?> ? Cette action est irréversible (fiche, parents, informations complémentaires, pièces jointes).')">
        <i class="bi bi-trash me-1"></i>Supprimer
      </a>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<div class="row g-3">

  <!-- Photo + identité -->
  <div class="col-lg-3">
    <div class="card text-center h-100">
      <div class="card-body">
        <img src="<?= url_photo_eleve((int)$eleve['id_eleve'], $eleve['Photo_elv'] !== null, $eleve['Sexe_elv']) ?>"
             style="width:100px;height:120px;object-fit:cover;border-radius:8px;border:2px solid #d1daf0;margin-bottom:.6rem">
        <div class="fw-bold" style="font-size:.88rem">
          <?= h(mb_strtoupper($eleve['Nom_elv'])) ?> <?= h($eleve['Prenom_elv'] ?? '') ?>
        </div>
        <div style="font-size:.72rem;color:#6b7280"><?= h($eleve['Mat_elv']) ?></div>
        <div style="font-size:.68rem;color:#9ca3af">ID élève #<?= (int)$eleve['id_eleve'] ?><?= $eleve['niu'] ? ' · NIU ' . h($eleve['niu']) : '' ?></div>
        <div class="mt-1">
          <?= stripos($eleve['Sexe_elv'],'F')===0 ? '<span class="badge-f">Féminin</span>' : '<span class="badge-m">Masculin</span>' ?>
        </div>
        <?php if ($classe_actuelle): ?>
          <div class="mt-2" style="background:#f0f5ff;border-radius:7px;padding:4px 8px;font-size:.74rem">
            <i class="bi bi-door-open me-1 text-primary"></i><?= h($classe_actuelle) ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- État civil + parents -->
  <div class="col-lg-5">
    <div class="card mb-2">
      <div class="card-body">
        <div class="section-titre"><i class="bi bi-person me-1"></i>État civil</div>
        <?php
        $champs = [
          'Nom en arabe'      => $eleve['Nom_arabe_elv'] ?: '—',
          'Date de naissance' => date_fr($eleve['Date_naiss_elv']),
          'Lieu de naissance' => $eleve['Lieu_naiss_elv'] ?: '—',
          "Région d'origine"      => $lieu['intitule_region'] ?? '—',
          "Département d'origine" => $lieu['intitule_depart'] ?? '—',
          "Arrondissement d'origine" => $lieu['intitule_arrond'] ?? ($eleve['arrondissement_elv'] ?: '—'),
          'Adresse'           => $eleve['Adresse_elv'] ?: '—',
        ];
        foreach ($champs as $lbl => $val): ?>
          <div class="d-flex py-1" style="border-bottom:1px solid #f3f4f6;font-size:.8rem">
            <span style="min-width:160px;color:#6b7280;font-size:.74rem"><?= $lbl ?></span>
            <strong><?= h($val) ?></strong>
          </div>
        <?php endforeach; ?>
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
          <div class="col-6">
            <div style="font-size:.72rem;color:#6b7280;font-weight:600">PÈRE</div>
            <?php if ($pere): ?>
              <div class="fw-semibold" style="font-size:.8rem"><?= h($pere['nom'] . ' ' . ($pere['prenom'] ?? '')) ?></div>
              <?php if ($pere['profession']): ?><div style="font-size:.74rem;color:#6b7280"><?= h($pere['profession']) ?></div><?php endif; ?>
              <?php if ($pere['adresse']): ?><div style="font-size:.74rem"><i class="bi bi-geo-alt me-1"></i><?= h($pere['adresse']) ?></div><?php endif; ?>
            <?php else: ?>
              <div class="text-muted" style="font-size:.78rem">—</div>
            <?php endif; ?>
          </div>
          <div class="col-6">
            <div style="font-size:.72rem;color:#6b7280;font-weight:600">MÈRE</div>
            <?php if ($mere): ?>
              <div class="fw-semibold" style="font-size:.8rem"><?= h($mere['nom'] . ' ' . ($mere['prenom'] ?? '')) ?></div>
              <?php if ($mere['profession']): ?><div style="font-size:.74rem;color:#6b7280"><?= h($mere['profession']) ?></div><?php endif; ?>
              <?php if ($mere['adresse']): ?><div style="font-size:.74rem"><i class="bi bi-geo-alt me-1"></i><?= h($mere['adresse']) ?></div><?php endif; ?>
            <?php else: ?>
              <div class="text-muted" style="font-size:.78rem">—</div>
            <?php endif; ?>
          </div>
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
            <span class="badge-code">Tuteur</span>
            <div class="flex-grow-1">
              <div class="fw-semibold"><?= h($t['nom'] . ' ' . ($t['prenom'] ?? '')) ?></div>
              <?php if ($t['profession']): ?><div style="color:#6b7280;font-size:.72rem"><?= h($t['profession']) ?></div><?php endif; ?>
            </div>
            <?php if ($peut_gerer): ?>
            <form method="post" action="<?= APP_URL ?>/pages/eleves/save_parents.php" data-ajax-post-form onsubmit="return confirm('Retirer ce tuteur ?')">
              <input type="hidden" name="csrf" value="<?= h($csrf_parents) ?>">
              <input type="hidden" name="id_eleve" value="<?= $id ?>">
              <input type="hidden" name="action" value="tuteur_supprimer">
              <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
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
    <div class="card">
      <div class="card-body">
        <div class="section-titre"><i class="bi bi-journal-text me-1"></i>Historique scolaire</div>
        <?php if (empty($inscriptions)): ?>
          <p class="text-muted mb-0" style="font-size:.78rem">Aucune inscription.</p>
        <?php else: ?>
          <table class="table table-sm mb-0" style="font-size:.78rem">
            <thead style="background:#f8faff">
              <tr><th style="padding:4px 6px">Année</th><th style="padding:4px 6px">Classe</th><th style="padding:4px 6px">Statut</th></tr>
            </thead>
            <tbody>
              <?php foreach ($inscriptions as $i): ?>
                <tr>
                  <td style="padding:4px 6px"><?= h($i['val_annee']) ?></td>
                  <td style="padding:4px 6px"><?= h($i['DesignationClasses']) ?></td>
                  <td style="padding:4px 6px"><span class="badge-code"><?= h(libelle_statut_insc($i['Statut_elv'])) ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>

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
            <?php foreach ([
              'Dernier établissement' => $info['dernier_etab'] ?? '',
              'Dernière classe'       => $info['derniere_classe'] ?? '',
              'Date de recrutement'   => date_fr($info['date_rec'] ?? null),
            ] as $lbl => $val): ?>
              <div class="d-flex py-1" style="border-bottom:1px solid #f3f4f6;font-size:.78rem">
                <span style="min-width:150px;color:#6b7280;font-size:.72rem"><?= $lbl ?></span>
                <strong><?= h($val ?: '—') ?></strong>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="col-md-4">
            <div style="font-size:.72rem;color:#6b7280;font-weight:600">SANTÉ / DIVERS</div>
            <?php foreach ([
              'Antécédent médical' => $info['antecedent_med'] ?? '',
              'Fréquence'          => $info['frequence'] ?? '',
              'Autres informations'=> $info['autres_infos'] ?? '',
            ] as $lbl => $val): ?>
              <div class="d-flex py-1" style="border-bottom:1px solid #f3f4f6;font-size:.78rem">
                <span style="min-width:150px;color:#6b7280;font-size:.72rem"><?= $lbl ?></span>
                <strong><?= h($val ?: '—') ?></strong>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="col-md-4">
            <div style="font-size:.72rem;color:#6b7280;font-weight:600">SITUATION PARTICULIÈRE</div>
            <div class="d-flex py-1" style="border-bottom:1px solid #f3f4f6;font-size:.78rem">
              <span style="min-width:150px;color:#6b7280;font-size:.72rem">Handicap</span>
              <strong>
                <?= ($info['handicap'] ?? '') === 'OUI' ? '<span class="badge-code">Oui — ' . h(($info['nature_handicap'] ?? '') ?: '—') . '</span>' : h(($info['handicap'] ?? '') ?: '—') ?>
              </strong>
            </div>
            <div class="d-flex py-1" style="border-bottom:1px solid #f3f4f6;font-size:.78rem">
              <span style="min-width:150px;color:#6b7280;font-size:.72rem">Réfugié(e)</span>
              <strong>
                <?= ($info['refugie'] ?? '') === 'OUI' ? '<span class="badge-code">Oui — ' . h(($info['type_refugie'] ?? '') ?: '—') . '</span>' : h(($info['refugie'] ?? '') ?: '—') ?>
              </strong>
            </div>
            <div class="d-flex py-1" style="border-bottom:1px solid #f3f4f6;font-size:.78rem">
              <span style="min-width:150px;color:#6b7280;font-size:.72rem">Cas social</span>
              <strong>
                <?php if (!empty($info['cas_social'])): ?>
                  <span class="badge-code">Oui — réduction <?= h((string) (float) ($info['pourcentage_reduction'] ?? 0)) ?>%</span>
                <?php else: ?>
                  Non
                <?php endif; ?>
              </strong>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

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
        <?php if (empty($documents)): ?>
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
                    <td style="color:#6b7280"><?= date_fr(substr($d['date_ajout'], 0, 10)) ?></td>
                    <td class="text-end">
                      <a href="<?= APP_URL ?>/pages/eleves/dossier_fichier.php?id=<?= (int)$d['id'] ?>" target="_blank"
                         class="btn btn-sm" style="background:#eef2ff;color:#1e4fd8;padding:3px 7px" title="Voir">
                        <i class="bi bi-eye" style="font-size:.78rem"></i>
                      </a>
                      <?php if ($peut_gerer): ?>
                      <a href="<?= APP_URL ?>/pages/eleves/dossier_supprimer.php?id=<?= (int)$d['id'] ?>&csrf=<?= csrf_generer() ?>"
                         class="btn btn-sm btn-light text-danger" style="padding:3px 7px" title="Retirer"
                         onclick="return confirm('Retirer ce document du dossier ?')">
                        <i class="bi bi-trash" style="font-size:.78rem"></i>
                      </a>
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

<!-- Modal ajout document dossier -->
<div class="modal fade" id="modalDossier" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" action="<?= APP_URL ?>/pages/eleves/dossier_upload.php" enctype="multipart/form-data" data-ajax-post-form>
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
          <input type="text" name="libelle" class="form-control form-control-sm mb-2" placeholder="Ex. Bulletin 1er trimestre 2025/2026">
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

<!-- Modal carte scolaire (choix modèle/format/verso) -->
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
        </select>
        <label class="form-label">Format</label>
        <select id="selCarteFormat" class="form-select form-select-sm mb-2">
          <option value="1">Carte bancaire (CR80)</option>
          <option value="2">ISO carte étudiant</option>
          <option value="3">A4 pleine page</option>
        </select>
        <div class="form-check mb-1">
          <input class="form-check-input" type="checkbox" id="chkCarteVerso">
          <label class="form-check-label" for="chkCarteVerso" style="font-size:.82rem">Imprimer le verso (recto-verso)</label>
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
  const format = document.getElementById('selCarteFormat').value;
  const verso  = document.getElementById('chkCarteVerso').checked ? '1' : '0';
  const typeDoc = verso === '1' ? 'carte_verso' : ('carte_' + modele);
  afficherApercu(
    '<?= APP_URL ?>/pdf/cartes.php?id=<?= $id ?>&modele=' + modele + '&format=' + format + '&verso=' + verso,
    'Carte scolaire', typeDoc, 'card'
  );
}
</script>

<!-- Modal édition parents -->
<div class="modal fade" id="modalParents" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" action="<?= APP_URL ?>/pages/eleves/save_parents.php" data-ajax-post-form>
        <input type="hidden" name="csrf" value="<?= h($csrf_parents) ?>">
        <input type="hidden" name="id_eleve" value="<?= $id ?>">
        <div class="modal-header py-2">
          <h6 class="modal-title fw-bold"><i class="bi bi-people me-1 text-primary"></i>Parents de l'élève</h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-6">
              <div class="fw-semibold mb-1" style="font-size:.8rem;color:#1a3c6b">Père</div>
              <input type="text" name="pere_nom" class="form-control form-control-sm mb-1" placeholder="Nom" value="<?= h($pere['nom'] ?? '') ?>">
              <input type="text" name="pere_prenom" class="form-control form-control-sm mb-1" placeholder="Prénom" value="<?= h($pere['prenom'] ?? '') ?>">
              <input type="text" name="pere_profession" class="form-control form-control-sm mb-1" placeholder="Profession" value="<?= h($pere['profession'] ?? '') ?>">
              <input type="text" name="pere_adresse" class="form-control form-control-sm" placeholder="Adresse" value="<?= h($pere['adresse'] ?? '') ?>">
            </div>
            <div class="col-6">
              <div class="fw-semibold mb-1" style="font-size:.8rem;color:#1a3c6b">Mère</div>
              <input type="text" name="mere_nom" class="form-control form-control-sm mb-1" placeholder="Nom" value="<?= h($mere['nom'] ?? '') ?>">
              <input type="text" name="mere_prenom" class="form-control form-control-sm mb-1" placeholder="Prénom" value="<?= h($mere['prenom'] ?? '') ?>">
              <input type="text" name="mere_profession" class="form-control form-control-sm mb-1" placeholder="Profession" value="<?= h($mere['profession'] ?? '') ?>">
              <input type="text" name="mere_adresse" class="form-control form-control-sm" placeholder="Adresse" value="<?= h($mere['adresse'] ?? '') ?>">
            </div>
          </div>
          <div class="form-text mt-2" style="font-size:.72rem">Laisser tous les champs vides supprime l'enregistrement correspondant.</div>
        </div>
        <div class="modal-footer py-2">
          <button class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Enregistrer</button>
          <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Annuler</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal ajout tuteur -->
<div class="modal fade" id="modalTuteur" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" action="<?= APP_URL ?>/pages/eleves/save_parents.php" data-ajax-post-form>
        <input type="hidden" name="csrf" value="<?= h($csrf_parents) ?>">
        <input type="hidden" name="id_eleve" value="<?= $id ?>">
        <input type="hidden" name="action" value="tuteur_ajouter">
        <div class="modal-header py-2">
          <h6 class="modal-title fw-bold"><i class="bi bi-person-badge me-1 text-primary"></i>Ajouter un tuteur</h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="text" name="tuteur_nom" class="form-control form-control-sm mb-1" placeholder="Nom" required>
          <input type="text" name="tuteur_prenom" class="form-control form-control-sm mb-1" placeholder="Prénom">
          <input type="text" name="tuteur_profession" class="form-control form-control-sm mb-1" placeholder="Profession">
          <input type="text" name="tuteur_adresse" class="form-control form-control-sm" placeholder="Adresse">
        </div>
        <div class="modal-footer py-2">
          <button class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Ajouter</button>
          <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Annuler</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal édition infos complémentaires -->
<div class="modal fade" id="modalInfoSuppl" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post" action="<?= APP_URL ?>/pages/eleves/save_info.php" data-ajax-post-form>
        <input type="hidden" name="csrf" value="<?= h($csrf_parents) ?>">
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
              <select name="handicap" id="selHandicap" class="form-select form-select-sm mb-1" onchange="document.getElementById('zoneNature').style.display=this.value==='OUI'?'':'none'">
                <option value="" <?= empty($info['handicap']) ? 'selected' : '' ?>>—</option>
                <option value="OUI" <?= ($info['handicap'] ?? '')==='OUI' ? 'selected' : '' ?>>Oui</option>
                <option value="NON" <?= ($info['handicap'] ?? '')==='NON' ? 'selected' : '' ?>>Non</option>
              </select>
              <div id="zoneNature" style="display:<?= ($info['handicap'] ?? '')==='OUI' ? '' : 'none' ?>">
                <input type="text" name="nature_handicap" class="form-control form-control-sm mb-1" placeholder="Nature du handicap" value="<?= h($info['nature_handicap'] ?? '') ?>">
              </div>
              <label class="form-label" style="font-size:.72rem">Réfugié(e)</label>
              <select name="refugie" id="selRefugie" class="form-select form-select-sm mb-1" onchange="document.getElementById('zoneType').style.display=this.value==='OUI'?'':'none'">
                <option value="" <?= empty($info['refugie']) ? 'selected' : '' ?>>—</option>
                <option value="OUI" <?= ($info['refugie'] ?? '')==='OUI' ? 'selected' : '' ?>>Oui</option>
                <option value="NON" <?= ($info['refugie'] ?? '')==='NON' ? 'selected' : '' ?>>Non</option>
              </select>
              <div id="zoneType" style="display:<?= ($info['refugie'] ?? '')==='OUI' ? '' : 'none' ?>">
                <select name="type_refugie" class="form-select form-select-sm mb-1">
                  <option value="" <?= empty($info['type_refugie']) ? 'selected' : '' ?>>—</option>
                  <option value="Interne" <?= ($info['type_refugie'] ?? '')==='Interne' ? 'selected' : '' ?>>Interne</option>
                  <option value="Externe" <?= ($info['type_refugie'] ?? '')==='Externe' ? 'selected' : '' ?>>Externe</option>
                </select>
              </div>
              <div class="form-check mb-1 mt-2">
                <input type="checkbox" class="form-check-input" name="cas_social" id="chkCasSocial" value="1"
                       <?= !empty($info['cas_social']) ? 'checked' : '' ?>
                       onchange="document.getElementById('zonePourcentage').style.display=this.checked?'':'none'">
                <label class="form-check-label" for="chkCasSocial" style="font-size:.8rem">Cas social</label>
              </div>
              <div id="zonePourcentage" style="display:<?= !empty($info['cas_social']) ? '' : 'none' ?>">
                <label class="form-label" style="font-size:.72rem">Pourcentage de réduction sur les frais dus</label>
                <div class="input-group input-group-sm">
                  <input type="number" name="pourcentage_reduction" class="form-control form-control-sm" min="0" max="100" step="0.01"
                         value="<?= h((string) (float) ($info['pourcentage_reduction'] ?? 0)) ?>">
                  <span class="input-group-text">%</span>
                </div>
                <div class="form-text">Réduction appliquée au montant total des frais dus par cet élève (Finances &gt; Paiements).</div>
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

</div><!-- /#fiche-eleve-zone -->
<?php if ($es_partiel) exit; // rien de plus dans une réponse AJAX partielle. ?>

<?php
$ajax_zone_id = 'fiche-eleve-zone'; // voir layout/footer.php — initAjaxZone() y est appelé
require_once __DIR__ . '/../../layout/footer.php';
