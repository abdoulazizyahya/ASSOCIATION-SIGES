<?php
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_role(['ADMIN','PROVISEUR', 'FONDATEUR','CENSEUR','INTENDANT']);

$annee_active = get_annee_active();
$id_annee     = (int)($annee_active['id'] ?? 0);
$id_classe    = (int)($_GET['classe'] ?? 0);
$id_eleve     = (int)($_GET['eleve']  ?? 0);
$libelle_chef = libelle_role('PROVISEUR'); // "Proviseur(e)" (public) ou "Principal(e)" (privé)

$classes = $id_annee
    ? db_all("SELECT c.* FROM classe c
              JOIN inscription i ON i.id_classe=c.id AND i.id_annee=?
              WHERE c.archivee=0 GROUP BY c.id ORDER BY c.ordre, c.designation", [$id_annee])
    : [];

$eleves = ($id_annee && $id_classe)
    ? db_all("SELECT e.* FROM eleve e
              JOIN inscription i ON i.id_eleve=e.id AND i.id_classe=? AND i.id_annee=?
              WHERE e.statut='actif' ORDER BY e.nom, e.prenom", [$id_classe, $id_annee])
    : [];

$eleve = null; $classe = null; $obligations = []; $historique = []; $operateurs_non_cash = [];
if ($id_eleve && $id_classe && $id_annee) {
    $eleve  = db_one("SELECT * FROM eleve WHERE id=?", [$id_eleve]);
    $classe = db_one("SELECT * FROM classe WHERE id=?", [$id_classe]);
    if ($eleve && $classe) {
        $obligations = eleve_obligations_annee($id_eleve, $classe['code_niveau'], $id_annee);
        $historique  = db_all(
            "SELECT p.*, o.libelle AS obligation_libelle, o.mode_paiement AS obligation_mode_paiement,
                    op.libelle AS operateur_libelle, op.logo AS operateur_logo
             FROM paiement_frais p
             JOIN obligation_frais o    ON o.id = p.id_obligation
             JOIN operateur_paiement op ON op.id = p.id_operateur
             WHERE p.id_eleve=? AND p.id_annee=? ORDER BY p.cree_le DESC",
            [$id_eleve, $id_annee]
        );
        $operateurs_non_cash = db_all("SELECT * FROM operateur_paiement WHERE id <> 'CASH' ORDER BY libelle");
    }
}

$titre_page = 'Paiements des frais';
require_once __DIR__ . '/../../../layout/header.php';
?>
<style>
.op-picker { display:flex; gap:5px; flex-wrap:wrap; }
.op-btn { width:36px; height:36px; padding:2px; border:2px solid #e5e7eb; border-radius:8px; background:#fff;
          display:flex; align-items:center; justify-content:center; cursor:pointer; transition:.15s; }
.op-btn:hover { border-color:#c7d2fe; transform:translateY(-1px); }
.op-btn.selected { border-color:#1e4fd8; box-shadow:0 0 0 2px rgba(30,79,216,.2); }
.op-btn img { max-width:100%; max-height:100%; object-fit:contain; }
.op-fallback { font-size:.62rem; font-weight:700; color:#6b7280; }
</style>
<div class="page-titre d-flex justify-content-between align-items-center">
  <h4><i class="bi bi-cash-stack me-1 text-primary"></i><?= h($titre_page) ?></h4>
</div>

<div class="card mb-2">
  <div class="card-body py-2">
    <div class="row g-2 align-items-end">
      <div class="col-md-5">
        <label class="form-label">Classe</label>
        <select id="selClasse" class="form-select form-select-sm">
          <option value="">— Choisir une classe —</option>
          <?php foreach ($classes as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $id_classe === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['designation']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-5">
        <label class="form-label">Élève</label>
        <select id="selEleve" class="form-select form-select-sm" <?= $id_classe ? '' : 'disabled' ?>>
          <option value="">— Choisir un élève —</option>
          <?php foreach ($eleves as $e): ?>
            <option value="<?= $e['id'] ?>" <?= $id_eleve === (int)$e['id'] ? 'selected' : '' ?>><?= h($e['nom'] . ' ' . ($e['prenom'] ?? '')) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
  </div>
</div>

<?php if ($eleve && $classe): ?>

<div class="card mb-2">
  <div class="card-body py-2">
    <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
      <div class="fw-bold" style="font-size:1rem"><?= h(strtoupper($eleve['nom']) . ' ' . ($eleve['prenom'] ?? '')) ?></div>
      <button type="button" class="btn btn-outline-secondary btn-sm" onclick="ouvrirRecap()">
        <i class="bi bi-file-earmark-pdf me-1"></i>Récapitulatif PDF
      </button>
    </div>
    <div class="row g-2">
      <div class="col-6 col-md-2">
        <div class="text-muted" style="font-size:.68rem;text-transform:uppercase;letter-spacing:.03em">NIU</div>
        <div class="fw-semibold" style="font-size:.85rem"><?= h($eleve['niu'] ?: '—') ?></div>
      </div>
      <div class="col-6 col-md-2">
        <div class="text-muted" style="font-size:.68rem;text-transform:uppercase;letter-spacing:.03em">Matricule</div>
        <div class="fw-semibold" style="font-size:.85rem"><?= h($eleve['matricule']) ?></div>
      </div>
      <div class="col-6 col-md-2">
        <div class="text-muted" style="font-size:.68rem;text-transform:uppercase;letter-spacing:.03em">Classe</div>
        <div class="fw-semibold" style="font-size:.85rem"><?= h($classe['designation']) ?></div>
      </div>
      <div class="col-6 col-md-2">
        <div class="text-muted" style="font-size:.68rem;text-transform:uppercase;letter-spacing:.03em">Sexe</div>
        <div class="fw-semibold" style="font-size:.85rem"><?= $eleve['sexe'] === 'F' ? 'Féminin' : 'Masculin' ?></div>
      </div>
      <div class="col-12 col-md-4">
        <div class="text-muted" style="font-size:.68rem;text-transform:uppercase;letter-spacing:.03em">Date et lieu de naissance</div>
        <div class="fw-semibold" style="font-size:.85rem"><?= $eleve['date_naiss'] ? h(date('d/m/Y', strtotime($eleve['date_naiss']))) : '—' ?><?= h($eleve['lieu_naiss'] ? ' à ' . $eleve['lieu_naiss'] : '') ?></div>
      </div>
    </div>
  </div>
</div>

<div class="card mb-2">
  <div class="card-header py-2" style="background:#f8faff"><span class="fw-semibold" style="font-size:.82rem">Enregistrer un versement</span></div>
  <div class="card-body">
    <form method="post" action="<?= APP_URL ?>/secondaire/pages/paiements/save.php">
      <?= csrf_champ() ?>
      <input type="hidden" name="id_eleve" value="<?= $id_eleve ?>">
      <input type="hidden" name="id_classe" value="<?= $id_classe ?>">

      <div class="table-responsive mb-2">
        <table class="table table-sm align-middle mb-0">
          <thead style="font-size:.72rem;text-transform:uppercase;color:#9ca3af">
            <tr><th style="width:24px"></th><th style="min-width:260px">Frais</th><th class="text-end" style="width:90px">Solde dû</th><th style="min-width:200px">Canal de paiement</th></tr>
          </thead>
          <tbody>
            <?php foreach ($obligations as $o): if ($o['solde'] <= 0) continue; ?>
            <tr>
              <td><input type="checkbox" class="form-check-input" name="obligations[]" value="<?= $o['id'] ?>" id="chk-<?= $o['id'] ?>"></td>
              <td style="white-space:nowrap"><label for="chk-<?= $o['id'] ?>"><?= h($o['libelle']) ?></label></td>
              <td class="text-end fw-bold text-danger"><?= number_format($o['solde'], 0, ',', ' ') ?> F</td>
              <td>
                <?php if ($o['mode_paiement'] === 'cash'): ?>
                  <span class="badge bg-secondary"><i class="bi bi-cash me-1"></i>Espèces</span>
                <?php else: ?>
                  <div class="d-flex align-items-center gap-2 flex-wrap">
                    <div class="op-picker" data-target="op-<?= $o['id'] ?>">
                      <?php foreach ($operateurs_non_cash as $op): ?>
                        <button type="button" class="op-btn" data-value="<?= h($op['id']) ?>" title="<?= h($op['libelle']) ?>" onclick="opSelect(this)">
                          <?php if (!empty($op['logo'])): ?>
                            <img src="<?= APP_URL ?>/assets/uploads/operateurs/<?= h($op['logo']) ?>" alt="<?= h($op['libelle']) ?>">
                          <?php else: ?>
                            <span class="op-fallback"><?= h(mb_strtoupper(mb_substr($op['libelle'], 0, 2))) ?></span>
                          <?php endif; ?>
                        </button>
                      <?php endforeach; ?>
                      <input type="hidden" name="operateur[<?= $o['id'] ?>]" id="op-<?= $o['id'] ?>">
                    </div>
                    <input type="text" name="ref[<?= $o['id'] ?>]" class="form-control form-control-sm" style="max-width:130px" placeholder="Référence">
                  </div>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!array_filter($obligations, fn($o) => $o['solde'] > 0)): ?>
              <tr><td colspan="4" class="text-center text-muted py-3">Tous les frais de cet élève sont soldés.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div class="row g-2 align-items-end">
        <div class="col-md-4">
          <label class="form-label">Date du versement *</label>
          <input type="date" name="date_paiement" class="form-control form-control-sm" required value="<?= date('Y-m-d') ?>">
        </div>
        <div class="col-md-8">
          <button class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Enregistrer le(s) paiement(s) coché(s)</button>
          <span class="text-muted ms-2" style="font-size:.7rem;font-style:italic">Chaque frais coché est réglé intégralement (solde restant).</span>
        </div>
      </div>
    </form>
  </div>
</div>

<div class="card mt-2">
  <div class="card-header py-2" style="background:#f8faff"><span class="fw-semibold" style="font-size:.82rem">Historique des versements</span></div>
  <div class="table-responsive">
    <table class="table table-abz table-hover align-middle mb-0" style="font-size:.8rem">
      <thead><tr>
        <th style="width:24px"></th>
        <th>Intitulés</th><th class="text-end">Montant</th><th>Operateurs</th>
        <th>Date paiement</th><th>Reference de paiement</th><th class="text-center">Opérations</th>
      </tr></thead>
      <tbody>
        <?php $total_historique = 0.0; ?>
        <?php foreach ($historique as $p): $total_historique += (float)$p['montant']; ?>
        <tr data-numero-recu="<?= h($p['numero_recu']) ?>">
          <td><input type="checkbox" class="form-check-input chk-versement" onchange="chkVersementChange(this)"></td>
          <td><?= h($p['obligation_libelle']) ?></td>
          <td class="text-end"><?= number_format((float)$p['montant'], 0, ',', ' ') ?> Fcfa</td>
          <td>
            <?php if (!empty($p['operateur_logo'])): ?>
              <img src="<?= APP_URL ?>/assets/uploads/operateurs/<?= h($p['operateur_logo']) ?>" alt="" style="width:18px;height:18px;object-fit:contain;vertical-align:-3px;margin-right:4px">
            <?php endif; ?>
            <?= h($p['operateur_libelle']) ?>
          </td>
          <td><?= h(date('d/m/Y', strtotime($p['date_paiement']))) ?></td>
          <td><?= h($p['ref_paiement'] ?? '') ?></td>
          <td class="text-center">
            <button type="button" class="btn btn-sm btn-light" style="padding:2px 7px" title="Modifier"
                    onclick='ouvrirModifierVersement(<?= json_encode([
                        'id' => $p['id'], 'id_operateur' => $p['id_operateur'], 'ref_paiement' => $p['ref_paiement'],
                        'date_paiement' => $p['date_paiement'], 'obligation_libelle' => $p['obligation_libelle'],
                        'obligation_mode_paiement' => $p['obligation_mode_paiement'],
                    ]) ?>)'>
              <i class="bi bi-pencil-square text-success" style="font-size:.78rem"></i>
            </button>
            <button type="button" class="btn btn-sm btn-light" style="padding:2px 7px" title="Supprimer"
                    onclick="supprimerVersement(<?= (int)$p['id'] ?>, '<?= h(addslashes($p['obligation_libelle'])) ?>')">
              <i class="bi bi-trash text-danger" style="font-size:.78rem"></i>
            </button>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$historique): ?>
          <tr><td colspan="7" class="text-center text-muted py-3">Aucun versement enregistré.</td></tr>
        <?php endif; ?>
      </tbody>
      <?php if ($historique): ?>
      <tfoot>
        <tr class="fw-bold">
          <td colspan="2">TOTAL</td>
          <td class="text-end"><?= number_format($total_historique, 0, ',', ' ') ?> Fcfa</td>
          <td colspan="4"></td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
  </div>
  <div class="card-body d-flex gap-2 flex-wrap py-2">
    <button type="button" class="btn btn-abz-primary btn-sm" onclick="ouvrirRecuApee()">Reçu APEE</button>
    <button type="button" class="btn btn-abz-primary btn-sm" id="btnRecuFiche" onclick="ouvrirRecuFiche()">REÇU + FICHE DE PREINSCRIPTION</button>
    <button type="button" class="btn btn-abz-primary btn-sm" id="btnRecuPaiement" onclick="ouvrirRecuPaiement()">REÇU DE PAIEMENT</button>
    <button type="button" class="btn btn-abz-primary btn-sm" id="btnRecap" onclick="ouvrirRecap()">RECAPITULATIF DE PAIEMENT</button>
  </div>
</div>

<!-- Modale Modifier un versement -->
<div class="modal fade" id="modalVersement" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header py-2" style="background:#f8faff">
        <h6 class="modal-title fw-bold" id="modalVersementTitre"><i class="bi bi-pencil-square me-1 text-primary"></i>Modifier le versement</h6>
        <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" action="<?= APP_URL ?>/secondaire/pages/paiements/versement.php">
        <?= csrf_champ() ?>
        <input type="hidden" name="action" value="modifier">
        <input type="hidden" name="id" id="ve-id">
        <input type="hidden" name="id_classe" value="<?= $id_classe ?>">
        <input type="hidden" name="id_eleve" value="<?= $id_eleve ?>">
        <div class="modal-body row g-2">
          <div class="col-12" id="ve-libelle-bloc">
            <label class="form-label">Frais</label>
            <input type="text" class="form-control" id="ve-libelle" disabled>
          </div>
          <div class="col-12" id="ve-op-bloc">
            <label class="form-label">Opérateur</label>
            <div class="op-picker" data-target="ve-operateur">
              <?php foreach ($operateurs_non_cash as $op): ?>
                <button type="button" class="op-btn" data-value="<?= h($op['id']) ?>" title="<?= h($op['libelle']) ?>" onclick="opSelect(this)">
                  <?php if (!empty($op['logo'])): ?>
                    <img src="<?= APP_URL ?>/assets/uploads/operateurs/<?= h($op['logo']) ?>" alt="<?= h($op['libelle']) ?>">
                  <?php else: ?>
                    <span class="op-fallback"><?= h(mb_strtoupper(mb_substr($op['libelle'], 0, 2))) ?></span>
                  <?php endif; ?>
                </button>
              <?php endforeach; ?>
              <input type="hidden" name="id_operateur" id="ve-operateur">
            </div>
          </div>
          <div class="col-12">
            <label class="form-label">Référence</label>
            <input type="text" name="ref_paiement" id="ve-ref" class="form-control">
          </div>
          <div class="col-12">
            <label class="form-label">Date du versement</label>
            <input type="date" name="date_paiement" id="ve-date" class="form-control" required>
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
<form method="post" action="<?= APP_URL ?>/secondaire/pages/paiements/versement.php" id="formSupprimerVersement" style="display:none">
  <?= csrf_champ() ?>
  <input type="hidden" name="action" value="supprimer">
  <input type="hidden" name="id" id="del-id">
  <input type="hidden" name="id_classe" value="<?= $id_classe ?>">
  <input type="hidden" name="id_eleve" value="<?= $id_eleve ?>">
</form>

<?php endif; ?>

<script>
const selClasse = document.getElementById('selClasse');
const selEleve  = document.getElementById('selEleve');

selClasse.addEventListener('change', async function() {
    const classeId = this.value;
    if (!classeId) {
        selEleve.innerHTML = '<option value="">— Choisir un élève —</option>';
        selEleve.disabled = true;
        return;
    }
    selEleve.disabled = true;
    selEleve.innerHTML = '<option value="">Chargement…</option>';
    try {
        const resp = await fetch('<?= APP_URL ?>/ajax/eleves_par_classe.php?classe=' + encodeURIComponent(classeId));
        const data = await resp.json();
        selEleve.innerHTML = '<option value="">— Choisir un élève —</option>' +
            data.map(e => `<option value="${e.id}">${e.label}</option>`).join('');
    } catch (e) {
        selEleve.innerHTML = '<option value="">Erreur de chargement</option>';
    }
    selEleve.disabled = false;
});

selEleve.addEventListener('change', function() {
    if (!this.value) return;
    window.location = '<?= APP_URL ?>/secondaire/pages/paiements/index.php?classe=' + encodeURIComponent(selClasse.value) + '&eleve=' + encodeURIComponent(this.value);
});

function opSelect(btn) {
    const picker = btn.closest('.op-picker');
    picker.querySelectorAll('.op-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    document.getElementById(picker.dataset.target).value = btn.dataset.value;
}

<?php if ($eleve && $classe): ?>
// ── Sélection du tableau historique : cocher une ligne coche/décoche tout
// son groupe (même numero_recu — un reçu = un numéro unique), exclusif
// entre groupes (un seul numéro sélectionnable à la fois pour les 3
// boutons qui en dépendent).
function chkVersementChange(chk) {
    const numero = chk.closest('tr').dataset.numeroRecu;
    const coche = chk.checked;
    document.querySelectorAll('.chk-versement').forEach(c => {
        const memeGroupe = c.closest('tr').dataset.numeroRecu === numero;
        c.checked = coche && memeGroupe;
    });
}
// Numéro de reçu à utiliser pour les boutons Reçu/Fiche/Récapitulatif : celui
// cochée par l'utilisateur, sinon (aucune case cochée) celui du versement le
// plus récent par défaut — les boutons restent toujours utilisables, cocher
// une ligne ne fait que cibler un AUTRE groupe que le plus récent.
function numeroRecuSelectionne() {
    const chk = document.querySelector('.chk-versement:checked');
    if (chk) return chk.closest('tr').dataset.numeroRecu;
    const premiere = document.querySelector('.chk-versement');
    return premiere ? premiere.closest('tr').dataset.numeroRecu : null;
}

const ID_ELEVE_COURANT = <?= (int)$id_eleve ?>;
const ID_ANNEE_COURANTE = <?= (int)$id_annee ?>;

function ouvrirRecuApee() {
    afficherApercu(
        '<?= APP_URL ?>/secondaire/pages/paiements/recu_apee.php?eleve=' + ID_ELEVE_COURANT + '&annee=' + ID_ANNEE_COURANTE,
        'Reçu APEE',
        [
            { type: 'recu_apee', code: 'president_apee', label: 'Signature Président APEE' },
            { type: 'recu_apee', code: 'tresorier_apee', label: 'Signature Trésorier APEE' },
        ]
    );
}
function ouvrirRecuPaiement() {
    const numero = numeroRecuSelectionne();
    if (!numero) { alert('Aucun versement enregistré pour cet élève.'); return; }
    afficherApercu(
        '<?= APP_URL ?>/secondaire/pages/paiements/recu.php?numero_recu=' + encodeURIComponent(numero) + '&eleve=' + ID_ELEVE_COURANT + '&annee=' + ID_ANNEE_COURANTE,
        'Reçu de paiement N° ' + numero,
        [
            { type: 'recu_paiement', code: 'intendant', label: 'Signature Intendant' },
            { type: 'recu_paiement', code: 'chef_etablissement', label: 'Signature <?= h($libelle_chef) ?>' },
        ]
    );
}
function ouvrirRecap() {
    const numero = numeroRecuSelectionne();
    if (!numero) { alert('Aucun versement enregistré pour cet élève.'); return; }
    const url = '<?= APP_URL ?>/secondaire/pages/paiements/recap_paiement.php?numero_recu=' + encodeURIComponent(numero) + '&eleve=' + ID_ELEVE_COURANT + '&annee=' + ID_ANNEE_COURANTE;
    afficherApercu(url, 'Récapitulatif de paiement', [
        { type: 'recap_paiement', code: 'intendant', label: 'Signature Intendant' },
        { type: 'recap_paiement', code: 'chef_etablissement', label: 'Signature <?= h($libelle_chef) ?>' },
    ]);
}
function ouvrirRecuFiche() {
    const numero = numeroRecuSelectionne();
    if (!numero) { alert('Aucun versement enregistré pour cet élève.'); return; }
    afficherApercu(
        '<?= APP_URL ?>/secondaire/pages/paiements/recu_fiche.php?numero_recu=' + encodeURIComponent(numero) + '&eleve=' + ID_ELEVE_COURANT + '&annee=' + ID_ANNEE_COURANTE,
        'Reçu + fiche de préinscription',
        [
            { type: 'recu_fiche_haut', code: 'intendant', label: 'Signature Intendant (reçu)' },
            { type: 'recu_fiche_haut', code: 'chef_etablissement', label: 'Signature <?= h($libelle_chef) ?> (reçu)' },
            { type: 'recu_fiche_bas', code: 'intendant', label: 'Signature Intendant (fiche)' },
            { type: 'recu_fiche_bas', code: 'chef_etablissement', label: 'Signature <?= h($libelle_chef) ?> (fiche)' },
        ]
    );
}

// ── Modifier / supprimer un versement ────────────────────────────────
function ouvrirModifierVersement(p) {
    document.getElementById('ve-id').value = p.id;
    document.getElementById('ve-libelle').value = p.obligation_libelle;
    document.getElementById('ve-ref').value = p.ref_paiement || '';
    document.getElementById('ve-date').value = p.date_paiement;
    document.getElementById('ve-operateur').value = p.id_operateur;
    document.getElementById('ve-op-bloc').style.display = p.obligation_mode_paiement === 'cash' ? 'none' : '';
    if (p.obligation_mode_paiement !== 'cash') {
        document.querySelectorAll('#ve-op-bloc .op-btn').forEach(b => b.classList.toggle('selected', b.dataset.value === p.id_operateur));
    }
    new bootstrap.Modal(document.getElementById('modalVersement')).show();
}
function supprimerVersement(id, libelle) {
    if (!confirm('Supprimer définitivement le versement « ' + libelle + ' » ? Cette action est irréversible.')) return;
    document.getElementById('del-id').value = id;
    document.getElementById('formSupprimerVersement').submit();
}
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../../../layout/footer.php'; ?>
