<?php
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_role(['ADMIN','PROVISEUR','CENSEUR']);

$annee_active = get_annee_active();
$id_annee     = (int)($annee_active['id'] ?? 0);
$f_niveau     = trim($_GET['niveau'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $action = post('action');

    if ($action === 'creer' || $action === 'modifier') {
        $id            = (int)post('id');
        $libelle       = post('libelle');
        $portee        = in_array(post('portee'), ['etablissement','cycle','niveau'], true) ? post('portee') : 'niveau';
        $code_niveau   = $portee === 'niveau' ? post('code_niveau') : null;
        $id_cycle      = $portee === 'cycle'  ? post('id_cycle')    : null;
        $montant       = (float)str_replace(',', '.', post('montant'));
        $mode_paiement = post('mode_paiement') === 'operateur' ? 'operateur' : 'cash';
        // Identifie de façon fiable l'obligation APEE / INSCRIPTION pour les
        // reçus dédiés (secondaire/pages/paiements/recu_apee.php, recu_fiche.php) — un
        // simple matching sur le libellé serait cassé par un renommage.
        $code_fixe = in_array(post('code_fixe'), ['APEE', 'INSCRIPTION'], true) ? post('code_fixe') : null;

        $portee_valide = ($portee === 'etablissement')
            || ($portee === 'cycle'  && $id_cycle)
            || ($portee === 'niveau' && $code_niveau);

        if (!$libelle || !$portee_valide || $montant <= 0) {
            flash_set('erreur', 'Libellé, portée (avec cycle/niveau si concerné) et montant (> 0) sont obligatoires.');
            rediriger('secondaire/pages/paiements/obligations.php');
        }
        if ($code_fixe && db_val(
            "SELECT COUNT(*) FROM obligation_frais WHERE id_annee=? AND code_fixe=? AND id <> ?",
            [$id_annee, $code_fixe, $id]
        )) {
            flash_set('erreur', "Un autre frais est déjà marqué « $code_fixe » pour cette année — un seul à la fois.");
            rediriger('secondaire/pages/paiements/obligations.php');
        }

        if ($action === 'creer') {
            db_exec("INSERT INTO obligation_frais (libelle, code_fixe, portee, code_niveau, id_cycle, montant, mode_paiement, id_annee) VALUES (?,?,?,?,?,?,?,?)",
                    [$libelle, $code_fixe, $portee, $code_niveau, $id_cycle, $montant, $mode_paiement, $id_annee]);
            flash_set('succes', 'Frais ajouté.');
        } else {
            db_exec("UPDATE obligation_frais SET libelle=?, code_fixe=?, portee=?, code_niveau=?, id_cycle=?, montant=?, mode_paiement=? WHERE id=?",
                    [$libelle, $code_fixe, $portee, $code_niveau, $id_cycle, $montant, $mode_paiement, $id]);
            flash_set('succes', 'Frais mis à jour.');
        }
        rediriger('secondaire/pages/paiements/obligations.php');
    }

    if ($action === 'toggle_actif') {
        $id = (int)post('id');
        db_exec("UPDATE obligation_frais SET actif = 1 - actif WHERE id = ?", [$id]);
        flash_set('succes', 'Statut mis à jour.');
        rediriger('secondaire/pages/paiements/obligations.php');
    }

    if ($action === 'reglage_paiement') {
        $montant_op = (float) str_replace(',', '.', post('montant_frais_operateur'));
        $valider_couleur = fn($v, $defaut) => preg_match('/^#[0-9a-fA-F]{6}$/', (string)$v) ? $v : $defaut;
        $c1 = $valider_couleur(post('couleur_fond_1'), '#FFF6C8');
        $c2 = $valider_couleur(post('couleur_fond_2'), '#FFCDD2');
        $c3 = $valider_couleur(post('couleur_fond_3'), '#CDE8CD');
        db_exec(
            "INSERT INTO reglage_paiement (id_annee, montant_frais_operateur, couleur_fond_1, couleur_fond_2, couleur_fond_3) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE montant_frais_operateur = VALUES(montant_frais_operateur),
                couleur_fond_1 = VALUES(couleur_fond_1), couleur_fond_2 = VALUES(couleur_fond_2), couleur_fond_3 = VALUES(couleur_fond_3)",
            [$id_annee, $montant_op, $c1, $c2, $c3]
        );
        flash_set('succes', 'Réglages des reçus mis à jour.');
        rediriger('secondaire/pages/paiements/obligations.php');
    }
}

$where  = ['o.id_annee = ?'];
$params = [$id_annee];
if ($f_niveau) { $where[] = 'o.code_niveau = ?'; $params[] = $f_niveau; }
$obligations = db_all(
    "SELECT o.*, n.libelle_niv FROM obligation_frais o
     LEFT JOIN niveau n ON n.code_niveau = o.code_niveau
     WHERE " . implode(' AND ', $where) . "
     ORDER BY o.portee, n.ordre_niveau, o.libelle",
    $params
);

$niveaux = db_all("SELECT * FROM niveau ORDER BY ordre_niveau");
$cycles  = db_all("SELECT DISTINCT id_cycle FROM niveau ORDER BY id_cycle");
$reglage_paiement = get_reglage_paiement($id_annee);

$titre_page = 'Frais exigibles';
require_once __DIR__ . '/../../../layout/header.php';
?>
<div class="page-titre d-flex justify-content-between align-items-center">
  <div>
    <h4><i class="bi bi-cash-coin me-1 text-primary"></i><?= h($titre_page) ?></h4>
    <div class="sub"><?= count($obligations) ?> frais configuré(s) — Année <?= h($annee_active['libelle'] ?? '—') ?></div>
  </div>
  <button class="btn btn-primary btn-sm" onclick="ouvrirCreer()">
    <i class="bi bi-plus-lg me-1"></i>Nouveau frais
  </button>
</div>

<div class="card mb-2">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-4">
        <label class="form-label">Niveau</label>
        <select name="niveau" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="">— Tous —</option>
          <?php foreach ($niveaux as $n): ?>
            <option value="<?= h($n['code_niveau']) ?>" <?= $f_niveau === $n['code_niveau'] ? 'selected' : '' ?>><?= h($n['libelle_niv']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </form>
  </div>
</div>

<div class="card mb-2">
  <div class="card-header py-2" style="background:#f8faff"><span class="fw-semibold" style="font-size:.82rem">Réglages des reçus de paiement</span></div>
  <div class="card-body py-2">
    <form method="post" class="row g-2 align-items-end">
      <?= csrf_champ() ?>
      <input type="hidden" name="action" value="reglage_paiement">
      <div class="col-md-3">
        <label class="form-label">Frais opérateur (FCFA)</label>
        <input type="number" name="montant_frais_operateur" class="form-control form-control-sm" min="0" step="1"
               value="<?= h($reglage_paiement['montant_frais_operateur']) ?>">
        <div class="form-text">Ajouté sur la fiche de préinscription quand l'inscription est réglée via un opérateur (mobile money, banque) plutôt qu'en espèces.</div>
      </div>
      <div class="col-md-6">
        <label class="form-label">Couleurs du fond des reçus (dégradé)</label>
        <div class="d-flex align-items-center gap-2">
          <input type="color" name="couleur_fond_1" class="form-control form-control-color" value="<?= h($reglage_paiement['couleur_fond_1']) ?>" title="Couleur 1">
          <input type="color" name="couleur_fond_2" class="form-control form-control-color" value="<?= h($reglage_paiement['couleur_fond_2']) ?>" title="Couleur 2">
          <input type="color" name="couleur_fond_3" class="form-control form-control-color" value="<?= h($reglage_paiement['couleur_fond_3']) ?>" title="Couleur 3">
        </div>
        <div class="form-text">Dégradé diagonal appliqué en fond du Reçu APEE, Reçu de paiement, Récapitulatif et Reçu + fiche de préinscription.</div>
      </div>
      <div class="col-md-3">
        <button class="btn btn-sm btn-abz-primary"><i class="bi bi-check-lg me-1"></i>Enregistrer</button>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-abz table-hover align-middle mb-0">
      <thead>
        <tr>
          <th>Libellé</th>
          <th>Portée</th>
          <th class="text-end">Montant</th>
          <th class="text-center">Mode de paiement</th>
          <th class="text-center">Statut</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($obligations as $o): ?>
        <tr>
          <td class="fw-semibold">
            <?= h($o['libelle']) ?>
            <?php if ($o['code_fixe']): ?>
              <span class="badge bg-warning text-dark ms-1" title="Utilisé par les reçus dédiés"><?= h($o['code_fixe']) ?></span>
            <?php endif; ?>
          </td>
          <td style="font-size:.8rem">
            <?php if ($o['portee'] === 'etablissement'): ?>
              <span class="badge bg-primary"><i class="bi bi-building me-1"></i>Établissement</span>
            <?php elseif ($o['portee'] === 'cycle'): ?>
              <span class="badge bg-purple" style="background:#7c3aed"><i class="bi bi-diagram-3 me-1"></i><?= h($o['id_cycle']) ?></span>
            <?php else: ?>
              <span class="badge bg-light text-dark border"><?= h($o['libelle_niv'] ?? $o['code_niveau']) ?></span>
            <?php endif; ?>
          </td>
          <td class="text-end"><?= number_format((float)$o['montant'], 0, ',', ' ') ?> F</td>
          <td class="text-center">
            <?php if ($o['mode_paiement'] === 'cash'): ?>
              <span class="badge bg-secondary"><i class="bi bi-cash me-1"></i>Espèces</span>
            <?php else: ?>
              <span class="badge bg-info text-dark"><i class="bi bi-phone me-1"></i>Opérateur</span>
            <?php endif; ?>
          </td>
          <td class="text-center">
            <?php if ($o['actif']): ?>
              <span class="badge bg-success">Actif</span>
            <?php else: ?>
              <span class="badge bg-secondary">Inactif</span>
            <?php endif; ?>
          </td>
          <td class="text-end">
            <button class="btn btn-sm btn-light" style="padding:3px 7px" title="Modifier"
                    onclick='ouvrirModifier(<?= json_encode($o) ?>)'>
              <i class="bi bi-pencil" style="font-size:.78rem"></i>
            </button>
            <form method="post" style="display:inline">
              <?= csrf_champ() ?>
              <input type="hidden" name="action" value="toggle_actif">
              <input type="hidden" name="id" value="<?= $o['id'] ?>">
              <button class="btn btn-sm btn-light" style="padding:3px 7px" title="<?= $o['actif'] ? 'Désactiver' : 'Activer' ?>">
                <i class="bi bi-<?= $o['actif'] ? 'toggle-on text-success' : 'toggle-off text-muted' ?>" style="font-size:.78rem"></i>
              </button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$obligations): ?>
        <tr><td colspan="6" class="text-center text-muted py-4">Aucun frais configuré pour cette sélection.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal Créer/Modifier -->
<div class="modal fade" id="modalObligation" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header py-2" style="background:#f8faff">
        <h6 class="modal-title fw-bold" id="modalObligationTitre"><i class="bi bi-cash-coin me-1 text-primary"></i>Nouveau frais</h6>
        <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal"></button>
      </div>
      <form method="post">
        <?= csrf_champ() ?>
        <input type="hidden" name="action" id="ob-action" value="creer">
        <input type="hidden" name="id" id="ob-id">
        <div class="modal-body row g-2">
          <div class="col-12">
            <label class="form-label">Libellé *</label>
            <input type="text" name="libelle" id="ob-libelle" class="form-control" required placeholder="Ex. Frais de scolarité, APEE, Timbre...">
          </div>

          <div class="col-12">
            <label class="form-label">Rôle spécial</label>
            <select name="code_fixe" id="ob-code-fixe" class="form-select">
              <option value="">— Aucun —</option>
              <option value="APEE">APEE (reçu APEE dédié)</option>
              <option value="INSCRIPTION">INSCRIPTION (fiche de préinscription)</option>
            </select>
            <div class="form-text">Un seul frais par année peut porter chaque rôle — utilisé par les boutons « Reçu APEE » et « Reçu + fiche de préinscription ».</div>
          </div>

          <div class="col-12">
            <label class="form-label">Portée *</label>
            <div class="d-flex gap-3 mt-1">
              <div class="form-check">
                <input class="form-check-input" type="radio" name="portee" id="ob-portee-etab" value="etablissement" onchange="pOnPorteeChange()">
                <label class="form-check-label" for="ob-portee-etab"><i class="bi bi-building me-1"></i>Tout l'établissement</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="radio" name="portee" id="ob-portee-cycle" value="cycle" onchange="pOnPorteeChange()">
                <label class="form-check-label" for="ob-portee-cycle"><i class="bi bi-diagram-3 me-1"></i>Un cycle</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="radio" name="portee" id="ob-portee-niveau" value="niveau" checked onchange="pOnPorteeChange()">
                <label class="form-check-label" for="ob-portee-niveau">Un niveau</label>
              </div>
            </div>
            <div class="form-text">Ex. APEE/Photo/Visite médicale → Établissement. Inscription (montant différent par cycle) → Cycle. BAC (Terminale)/Probatoire (Première) → Niveau.</div>
          </div>

          <div class="col-md-6" id="ob-bloc-cycle" style="display:none">
            <label class="form-label">Cycle *</label>
            <select name="id_cycle" id="ob-cycle" class="form-select">
              <?php foreach ($cycles as $c): ?>
                <option value="<?= h($c['id_cycle']) ?>"><?= h($c['id_cycle']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6" id="ob-bloc-niveau">
            <label class="form-label">Niveau *</label>
            <select name="code_niveau" id="ob-niveau" class="form-select">
              <?php foreach ($niveaux as $n): ?>
                <option value="<?= h($n['code_niveau']) ?>"><?= h($n['libelle_niv']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Montant (FCFA) *</label>
            <input type="number" name="montant" id="ob-montant" class="form-control" required min="1" step="1">
          </div>

          <div class="col-12">
            <label class="form-label">Mode de paiement *</label>
            <div class="d-flex gap-3 mt-1">
              <div class="form-check">
                <input class="form-check-input" type="radio" name="mode_paiement" id="ob-mode-cash" value="cash" checked>
                <label class="form-check-label" for="ob-mode-cash"><i class="bi bi-cash me-1"></i>Espèces uniquement</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="radio" name="mode_paiement" id="ob-mode-operateur" value="operateur">
                <label class="form-check-label" for="ob-mode-operateur"><i class="bi bi-phone me-1"></i>Opérateur uniquement</label>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer py-2">
          <button class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Enregistrer</button>
          <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Annuler</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function pOnPorteeChange() {
    const portee = document.querySelector('input[name="portee"]:checked').value;
    document.getElementById('ob-bloc-cycle').style.display  = portee === 'cycle'  ? '' : 'none';
    document.getElementById('ob-bloc-niveau').style.display = portee === 'niveau' ? '' : 'none';
}
function ouvrirCreer() {
    document.getElementById('modalObligationTitre').innerHTML = '<i class="bi bi-cash-coin me-1 text-primary"></i>Nouveau frais';
    document.getElementById('ob-action').value = 'creer';
    document.getElementById('ob-id').value = '';
    document.getElementById('ob-libelle').value = '';
    document.getElementById('ob-montant').value = '';
    document.getElementById('ob-code-fixe').value = '';
    document.getElementById('ob-mode-cash').checked = true;
    document.getElementById('ob-portee-niveau').checked = true;
    pOnPorteeChange();
    new bootstrap.Modal(document.getElementById('modalObligation')).show();
}
function ouvrirModifier(o) {
    document.getElementById('modalObligationTitre').innerHTML = '<i class="bi bi-pencil me-1 text-primary"></i>Modifier le frais';
    document.getElementById('ob-action').value = 'modifier';
    document.getElementById('ob-id').value = o.id;
    document.getElementById('ob-libelle').value = o.libelle;
    document.getElementById('ob-montant').value = o.montant;
    document.getElementById('ob-code-fixe').value = o.code_fixe || '';
    document.getElementById('ob-portee-' + (o.portee === 'etablissement' ? 'etab' : o.portee)).checked = true;
    if (o.code_niveau) document.getElementById('ob-niveau').value = o.code_niveau;
    if (o.id_cycle)    document.getElementById('ob-cycle').value  = o.id_cycle;
    pOnPorteeChange();
    document.getElementById(o.mode_paiement === 'operateur' ? 'ob-mode-operateur' : 'ob-mode-cash').checked = true;
    new bootstrap.Modal(document.getElementById('modalObligation')).show();
}
</script>

<?php require_once __DIR__ . '/../../../layout/footer.php'; ?>
