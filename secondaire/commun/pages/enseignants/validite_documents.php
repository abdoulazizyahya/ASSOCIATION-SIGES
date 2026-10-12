<?php
/**
 * Validité des documents administratifs enseignant (Attestation de présence /
 * Certificat de prise de service / Certificat de reprise de service — voir
 * pages/demandes/). Déplacé depuis secondaire/commun/pages/parametres/index.php (onglet
 * "Documents") pour vivre comme une entrée de menu à part entière, sous
 * Ressources humaines (demande explicite) — même logique, inchangée.
 */
header('Cache-Control: no-store, no-cache, must-revalidate');
require_once __DIR__ . '/../../../../config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'FONDATEUR']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    if (post('action') === 'validite_documents') {
        foreach (['attestation', 'prise', 'reprise'] as $t) {
            $actif        = post("actif_$t") === '1' ? 1 : 0;
            $duree_valeur = max(1, (int)post("duree_valeur_$t", '1'));
            $duree_unite  = post("duree_unite_$t") === 'jour' ? 'jour' : 'mois';
            db_exec(
                "INSERT INTO reglage_validite_document (type_document, actif, duree_valeur, duree_unite, maj_par)
                 VALUES (?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                    actif=VALUES(actif), duree_valeur=VALUES(duree_valeur), duree_unite=VALUES(duree_unite), maj_par=VALUES(maj_par)",
                [$t, $actif, $duree_valeur, $duree_unite, (int)($_SESSION['user_id'] ?? 0)]
            );
        }
        flash_set('succes', 'Validité des documents enseignant mise à jour.');
        rediriger('secondaire/commun/pages/enseignants/validite_documents.php');
    }
}

$reglages_validite = [
    'attestation' => get_reglage_validite_document('attestation'),
    'prise'       => get_reglage_validite_document('prise'),
    'reprise'     => get_reglage_validite_document('reprise'),
];

$titre_page = 'Validité des documents';
require_once __DIR__ . '/../../../../noyau/layout/header.php';
?>

<div class="page-titre">
  <h4><i class="bi bi-file-earmark-lock me-1 text-primary"></i>Validité des documents enseignant</h4>
</div>

<?= flash_html() ?>

<div class="alert alert-light border py-2" style="font-size:.82rem">
  <i class="bi bi-info-circle me-1"></i>
  Pour chaque document que peut demander un enseignant (Attestation de présence,
  Certificat de prise/reprise de service — voir <strong>Demandes de documents</strong>),
  active (ou non) une durée de validité. La validité démarre à la <strong>signature du
  Proviseur</strong> (validation finale de la demande), jamais à la date d'impression.
  Une fois expiré, l'enseignant peut encore <strong>consulter</strong> son document,
  mais plus l'imprimer, l'exporter ni l'enregistrer.
</div>

<form method="post" class="row g-compact">
  <?= csrf_champ() ?>
  <input type="hidden" name="action" value="validite_documents">

  <?php foreach ([
      'attestation' => 'Attestation de présence effective',
      'prise'       => 'Certificat de prise de service',
      'reprise'     => 'Certificat de reprise de service',
  ] as $t => $label): $r = $reglages_validite[$t]; ?>
  <div class="col-12 mt-2">
    <div class="card" style="border-color:#e5e7eb">
      <div class="card-body py-2">
        <div class="row g-2 align-items-center">
          <div class="col-md-4">
            <div class="form-check form-switch mb-0">
              <input class="form-check-input" type="checkbox" role="switch"
                     id="actif_<?= $t ?>" name="actif_<?= $t ?>" value="1" <?= $r['actif'] ? 'checked' : '' ?>>
              <label class="form-check-label fw-semibold" for="actif_<?= $t ?>"><?= h($label) ?></label>
            </div>
            <div class="text-muted" style="font-size:.72rem">
              <?= $r['actif'] ? 'Expire après la durée ci-contre.' : 'Aucune expiration (comportement actuel).' ?>
            </div>
          </div>
          <div class="col-md-4">
            <label class="form-label mb-0" style="font-size:.78rem">Durée de validité</label>
            <input type="number" min="1" class="form-control form-control-sm"
                   name="duree_valeur_<?= $t ?>" value="<?= (int)$r['duree_valeur'] ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label mb-0" style="font-size:.78rem">Unité</label>
            <select class="form-select form-select-sm" name="duree_unite_<?= $t ?>">
              <option value="jour" <?= $r['duree_unite']==='jour'?'selected':'' ?>>Jour(s)</option>
              <option value="mois" <?= $r['duree_unite']==='mois'?'selected':'' ?>>Mois</option>
            </select>
          </div>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>

  <div class="col-12 mt-3">
    <button class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Enregistrer</button>
  </div>
</form>

<?php require_once __DIR__ . '/../../../../noyau/layout/footer.php'; ?>
