<?php
// secondaire/pages/paiements_prives/versement.php — PAIEMENT PRIVÉ >
// Versement : porté de pages/finances/versement.php (primaire), adapté au
// schéma secondaire (id_annee/id_classe/id_eleve entiers, pas val_annee/
// IDClasses/id_eleve texte ; table dédiée `obligation_privee`/
// `paiement_prive`, comptabilité 100% indépendante de PAIEMENT PUBLIQUE —
// voir fonctions.php pour les helpers prive_*). Demande explicite du
// 17/09/2026. Pas de concept "Cas social" (absent du schéma secondaire).
//
// Modèle : un niveau (code_niveau) peut avoir PLUSIEURS obligations
// (INSCRIPTION/SCOLARITÉ/APEE...) ; le total dû par un élève est la somme
// de toutes les obligations de son niveau.
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'SECRETAIRE', 'INTENDANT']);

$annee     = get_annee_active();
$id_annee  = (int) ($annee['id'] ?? 0);
$val_annee = $annee['val_annee'] ?? ($annee['libelle'] ?? '');

// Soldes « réels » par frais d'un niveau pour un élève donné : simule la
// consommation cumulée de tout ce qui a déjà été payé, du plus petit au
// plus grand frais — mêmes principes que finances_soldes_simules()
// (primaire), sans réduction "Cas social" (absente ici).
function prive_soldes_simules(string $code_niveau, int $id_eleve, int $id_annee): array {
    $obligations = db_all("SELECT * FROM obligation_privee WHERE code_niveau=? ORDER BY montant_obligation ASC", [$code_niveau]);
    $consomme = (float) db_val(
        "SELECT COALESCE(SUM(montant_paiement),0) FROM paiement_prive WHERE id_eleve=? AND id_annee=?",
        [$id_eleve, $id_annee]
    );
    $resultat = [];
    foreach ($obligations as $o) {
        $montant_oblig = (float) $o['montant_obligation'];
        $couvert = min($consomme, $montant_oblig);
        $consomme -= $couvert;
        $resultat[] = ['solde' => $montant_oblig - $couvert] + $o;
    }
    return $resultat;
}

// ══════════════════════════════════════════════════════════════
//  POST — enregistrer / modifier / supprimer un versement
// ══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $action    = post('action');
    $id_eleve  = (int) post('id_eleve');
    $id_classe = (int) post('id_classe');

    if ($action === 'verser') {
        $id_obligation = (int) post('id_obligation');
        $montant       = (float) str_replace([' ', ','], ['', '.'], post('montant_paiement'));
        $date_paiement = post('date_paiement');
        $ref           = post('ref_paiement') ?: null;
        $mode_paiement = finances_mode_paiement_normalise(post('mode_paiement'));

        if (!$id_eleve || !$id_classe || !$id_obligation || $montant <= 0 || !$date_paiement) {
            flash_set('erreur', 'Élève, obligation, montant (positif) et date sont requis.');
        } else {
            $oblig = db_one("SELECT * FROM obligation_privee WHERE id=?", [$id_obligation]);
            $classe_row_v = db_one("SELECT code_niveau FROM classe WHERE id=?", [$id_classe]);
            if (!$oblig || !$classe_row_v) {
                flash_set('erreur', 'Obligation ou classe introuvable.');
            } else {
                $soldes_simules = prive_soldes_simules($classe_row_v['code_niveau'], $id_eleve, $id_annee);
                $ligne = current(array_filter($soldes_simules, fn($s) => (int) $s['id'] === $id_obligation));
                $solde = $ligne ? (float) $ligne['solde'] : 0.0;
                if ($montant > $solde + 0.01) {
                    flash_set('erreur', "Le montant saisi (" . number_format($montant, 0, ',', ' ') . " F) dépasse le solde dû pour « {$oblig['nom_obligation']} » (" . number_format($solde, 0, ',', ' ') . " F).");
                } else {
                    db_exec(
                        "INSERT INTO paiement_prive (id_eleve, id_classe, id_annee, id_obligation, montant_paiement, date_paiement, ref_paiement, mode_paiement, id_utilisateur)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                        [$id_eleve, $id_classe, $id_annee, $id_obligation, $montant, $date_paiement, $ref, $mode_paiement, utilisateur_connecte()['id'] ?? null]
                    );
                    journaliser_action('paiement_prive_saisi', null, number_format($montant, 0, ',', ' ') . ' F — ' . ($oblig['nom_obligation'] ?? ''));
                    flash_set('succes', 'Versement enregistré.');
                }
            }
        }
        rediriger("secondaire/pages/paiements_prives/versement.php?onglet=detail&classe=$id_classe&eleve=$id_eleve");
    }

    if ($action === 'modifier') {
        $id_pay        = (int) post('id_pay');
        $id_obligation = (int) post('id_obligation');
        $montant       = (float) str_replace([' ', ','], ['', '.'], post('montant_paiement'));
        $date_paiement = post('date_paiement');
        $ref           = post('ref_paiement') ?: null;
        $mode_paiement = finances_mode_paiement_normalise(post('mode_paiement'));

        $existe = db_val("SELECT COUNT(*) FROM paiement_prive WHERE id=? AND id_eleve=?", [$id_pay, $id_eleve]);
        if ($existe && $id_obligation && $montant > 0 && $date_paiement) {
            db_exec(
                "UPDATE paiement_prive SET id_obligation=?, montant_paiement=?, date_paiement=?, ref_paiement=?, mode_paiement=? WHERE id=?",
                [$id_obligation, $montant, $date_paiement, $ref, $mode_paiement, $id_pay]
            );
            flash_set('succes', 'Versement modifié.');
        } else {
            flash_set('erreur', 'Versement introuvable ou données invalides.');
        }
        rediriger("secondaire/pages/paiements_prives/versement.php?onglet=detail&classe=$id_classe&eleve=$id_eleve");
    }

    if ($action === 'supprimer') {
        $id_pay = (int) post('id_pay');
        db_exec("DELETE FROM paiement_prive WHERE id=? AND id_eleve=?", [$id_pay, $id_eleve]);
        flash_set('succes', 'Versement supprimé.');
        rediriger("secondaire/pages/paiements_prives/versement.php?onglet=detail&classe=$id_classe&eleve=$id_eleve");
    }

    // ── Cotisation : un seul montant, réparti automatiquement sur les
    //    frais du niveau du plus petit au plus grand (même règle que le
    //    primaire) — voir prive_soldes_simules() en tête de fichier.
    if ($action === 'cotiser') {
        $montant       = (float) str_replace([' ', ','], ['', '.'], post('montant_paiement'));
        $date_paiement = post('date_paiement');
        $ref           = post('ref_paiement') ?: null;
        $mode_paiement = finances_mode_paiement_normalise(post('mode_paiement'));

        $classe_row = $id_classe ? db_one("SELECT code_niveau FROM classe WHERE id=?", [$id_classe]) : null;

        if (!$id_eleve || !$id_classe || !$classe_row || $montant <= 0 || !$date_paiement) {
            flash_set('erreur', 'Élève, montant (positif) et date sont requis.');
        } else {
            $soldes = prive_soldes_simules($classe_row['code_niveau'], $id_eleve, $id_annee);
            if (!$soldes) {
                flash_set('erreur', 'Aucune obligation configurée pour ce niveau (onglet Obligations).');
            } else {
                $total_solde = array_sum(array_column($soldes, 'solde'));
                if ($montant > $total_solde + 0.01) {
                    flash_set('erreur', "Le montant saisi (" . number_format($montant, 0, ',', ' ') . " F) dépasse le total restant dû (" . number_format($total_solde, 0, ',', ' ') . " F).");
                } else {
                    $restant = $montant;
                    $repartition = [];
                    foreach ($soldes as $s) {
                        if ($restant <= 0.009) break;
                        if ($s['solde'] <= 0) continue;
                        $part = min($restant, $s['solde']);
                        $repartition[] = ['nom' => $s['nom_obligation'], 'id' => $s['id'], 'montant' => $part];
                        $restant -= $part;
                    }
                    $id_agent = utilisateur_connecte()['id'] ?? null;
                    // Toutes les lignes d'un même versement réparti partagent
                    // le même id_versement (= id de la 1ère ligne insérée) —
                    // un seul numéro de reçu, même principe que le primaire.
                    $id_versement = null;
                    foreach ($repartition as $r) {
                        db_exec(
                            "INSERT INTO paiement_prive (id_versement, id_eleve, id_classe, id_annee, id_obligation, montant_paiement, date_paiement, ref_paiement, mode_paiement, id_utilisateur)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                            [$id_versement, $id_eleve, $id_classe, $id_annee, $r['id'], $r['montant'], $date_paiement, $ref, $mode_paiement, $id_agent]
                        );
                        if ($id_versement === null) {
                            $id_versement = db_last_id();
                            db_exec("UPDATE paiement_prive SET id_versement=? WHERE id=?", [$id_versement, $id_versement]);
                        }
                    }
                    $detail = implode(', ', array_map(
                        fn($r) => $r['nom'] . ' : ' . number_format($r['montant'], 0, ',', ' ') . ' F',
                        $repartition
                    ));
                    flash_set('succes', 'Versement de ' . number_format($montant, 0, ',', ' ') . " F réparti automatiquement — $detail.");
                }
            }
        }
        rediriger("secondaire/pages/paiements_prives/versement.php?onglet=cotisation&classe=$id_classe&eleve=$id_eleve");
    }
}

// ══════════════════════════════════════════════════════════════
//  Affichage
// ══════════════════════════════════════════════════════════════
$id_classe = (int) ($_GET['classe'] ?? 0);
$id_eleve  = (int) ($_GET['eleve'] ?? 0);
$onglet    = $_GET['onglet'] ?? 'cotisation';
if (!in_array($onglet, ['cotisation', 'detail', 'impression'], true)) $onglet = 'cotisation';

$peut_gerer_paiements = !(function_exists('est_lecture_seule') && est_lecture_seule());

$classes = db_all(
    "SELECT c.id, c.designation, n.ordre_niveau FROM classe c
     LEFT JOIN niveau n ON n.code_niveau = c.code_niveau
     WHERE c.archivee=0 ORDER BY n.ordre_niveau, c.designation"
);

$eleve = null; $classe = null; $obligations_vue = []; $historique = []; $total_du = 0.0; $total_paye = 0.0;
$obligations_niveau = [];
if ($id_classe && $id_eleve && $id_annee) {
    $classe = db_one("SELECT * FROM classe WHERE id=?", [$id_classe]);
    $eleve  = db_one(
        "SELECT e.* FROM eleve e JOIN inscription i ON i.id_eleve=e.id
         WHERE e.id=? AND i.id_classe=? AND i.id_annee=?",
        [$id_eleve, $id_classe, $id_annee]
    );
    if ($eleve && $classe) {
        $obligations_niveau = db_all("SELECT * FROM obligation_privee WHERE code_niveau=? ORDER BY nom_obligation", [$classe['code_niveau']]);

        $paye_par_obligation = [];
        foreach (db_all(
            "SELECT id_obligation, SUM(montant_paiement) AS paye FROM paiement_prive
             WHERE id_eleve=? AND id_annee=? GROUP BY id_obligation",
            [$id_eleve, $id_annee]
        ) as $r) {
            $paye_par_obligation[(int) $r['id_obligation']] = (float) $r['paye'];
        }

        foreach ($obligations_niveau as $o) {
            $paye  = $paye_par_obligation[(int) $o['id']] ?? 0.0;
            $solde = max(0.0, (float) $o['montant_obligation'] - $paye);
            $obligations_vue[] = $o + ['paye' => $paye, 'solde' => $solde];
            $total_du   += (float) $o['montant_obligation'];
            $total_paye += $paye;
        }

        $historique = db_all(
            "SELECT p.*, o.nom_obligation FROM paiement_prive p
             LEFT JOIN obligation_privee o ON o.id = p.id_obligation
             WHERE p.id_eleve=? AND p.id_annee=? ORDER BY p.date_paiement DESC, p.id DESC",
            [$id_eleve, $id_annee]
        );
        $modes_eleve = [];
        foreach ($historique as $p) {
            $mc = finances_mode_paiement_normalise($p['mode_paiement'] ?? null);
            $modes_eleve[$mc] = ($modes_eleve[$mc] ?? 0.0) + (float) $p['montant_paiement'];
        }

        $obligations_simulees = prive_soldes_simules($classe['code_niveau'], $id_eleve, $id_annee);
    }
}
$solde_global = $total_du - $total_paye;
$obligations_simulees ??= [];
$modes_eleve ??= [];

$es_partiel = isset($_GET['partiel']);
if (!$es_partiel) {
    $titre_page = 'Versement (privé)';
    require_once __DIR__ . '/../../../layout/header.php';
} else {
    header('Content-Type: text/html; charset=utf-8');
}
?>

<div id="versement-prive-zone">

<div class="page-titre">
  <h4><i class="bi bi-cash-coin me-1 text-primary"></i>Paiement privé — Versement</h4>
  <div class="sub">Année <?= h($val_annee) ?></div>
</div>

<div class="card mb-2">
  <div class="card-body py-2">
    <div class="row g-2 align-items-end">
      <div class="col-md-5">
        <label class="form-label">Classe</label>
        <select id="selClasse" class="form-select form-select-sm">
          <option value="">— Choisir une classe —</option>
          <?php foreach ($classes as $c): ?>
            <option value="<?= (int) $c['id'] ?>" <?= $id_classe === (int) $c['id'] ? 'selected' : '' ?>><?= h($c['designation']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-5">
        <label class="form-label">Élève</label>
        <select id="selEleve" class="form-select form-select-sm" <?= $id_classe ? '' : 'disabled' ?>>
          <option value="">— Choisir un élève —</option>
          <?php if ($id_classe): foreach (db_all(
              "SELECT e.id, e.nom, e.prenom, e.matricule FROM eleve e
               JOIN inscription i ON i.id_eleve=e.id AND i.id_classe=? AND i.id_annee=?
               WHERE e.statut='actif' ORDER BY e.nom, e.prenom",
              [$id_classe, $id_annee]
          ) as $e): ?>
            <option value="<?= (int) $e['id'] ?>" <?= $id_eleve === (int) $e['id'] ? 'selected' : '' ?>>
              <?= h($e['nom'] . ' ' . ($e['prenom'] ?? '')) ?> (<?= h($e['matricule']) ?>)
            </option>
          <?php endforeach; endif; ?>
        </select>
      </div>
    </div>
  </div>
</div>

<ul class="nav nav-tabs mb-2" style="border-bottom:2px solid #e5e7eb">
  <li class="nav-item">
    <a class="nav-link <?= $onglet === 'cotisation' ? 'active' : '' ?>" data-ajax-nav
       href="<?= APP_URL ?>/secondaire/pages/paiements_prives/versement.php?onglet=cotisation&classe=<?= $id_classe ?>&eleve=<?= $id_eleve ?>">
      <i class="bi bi-piggy-bank me-1"></i>Cotisation
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $onglet === 'detail' ? 'active' : '' ?>" data-ajax-nav
       href="<?= APP_URL ?>/secondaire/pages/paiements_prives/versement.php?onglet=detail&classe=<?= $id_classe ?>&eleve=<?= $id_eleve ?>">
      <i class="bi bi-list-check me-1"></i>Détail par frais
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $onglet === 'impression' ? 'active' : '' ?>" data-ajax-nav
       href="<?= APP_URL ?>/secondaire/pages/paiements_prives/versement.php?onglet=impression<?= $id_classe ? '&classe=' . $id_classe : '' ?>">
      <i class="bi bi-printer me-1"></i>Imprimer les reçus
    </a>
  </li>
</ul>

<?php if ($onglet !== 'impression' && !($eleve && $classe)): ?>
<div class="alert alert-light border text-center text-muted py-4">
  <i class="bi bi-arrow-up-circle me-1"></i>Choisissez une classe puis un élève pour gérer ses paiements.
</div>
<?php endif; ?>

<?php if ($eleve && $classe): ?>

<div class="card mb-2">
  <div class="card-body py-2">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div class="fw-bold" style="font-size:1rem">
        <?= h(mb_strtoupper($eleve['nom']) . ' ' . ($eleve['prenom'] ?? '')) ?>
      </div>
      <div class="d-flex flex-column gap-1">
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="ouvrirRecuFinancesPrive()"
                <?= empty($historique) ? 'disabled title="Aucun versement enregistré"' : '' ?>>
          <i class="bi bi-file-earmark-pdf me-1"></i>Reçu PDF
        </button>
        <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalFraisPrive">
          <i class="bi bi-list-ul me-1"></i>Détails des frais
        </button>
      </div>
    </div>
    <div class="row g-2 mt-1">
      <div class="col-6 col-md-3"><div class="text-muted" style="font-size:.68rem">MATRICULE</div><div class="fw-semibold" style="font-size:.85rem"><?= h($eleve['matricule']) ?></div></div>
      <div class="col-6 col-md-3"><div class="text-muted" style="font-size:.68rem">CLASSE</div><div class="fw-semibold" style="font-size:.85rem"><?= h($classe['designation']) ?></div></div>
      <div class="col-6 col-md-3">
        <div class="text-muted" style="font-size:.68rem">TOTAL DÛ</div>
        <div class="fw-semibold" style="font-size:.85rem"><?= number_format($total_du, 0, ',', ' ') ?> F</div>
      </div>
      <div class="col-6 col-md-3"><div class="text-muted" style="font-size:.68rem">SOLDE RESTANT</div><div class="fw-bold" style="font-size:.85rem;color:<?= $solde_global > 0 ? '#dc2626' : '#16a34a' ?>"><?= number_format($solde_global, 0, ',', ' ') ?> F</div></div>
    </div>
    <?php if ($modes_eleve): ?>
      <div class="mt-2 pt-2 d-flex flex-wrap align-items-center gap-2" style="border-top:1px dashed #e5e7eb">
        <span class="text-muted" style="font-size:.68rem">PAYÉ VIA</span>
        <?php foreach ($modes_eleve as $code => $mnt): ?>
          <?= finances_mode_paiement_badge($code) ?> <span class="text-muted" style="font-size:.75rem">(<?= number_format($mnt, 0, ',', ' ') ?> F)</span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="modal fade" id="modalFraisPrive" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header py-2" style="background:#f8faff">
        <h6 class="modal-title fw-bold"><i class="bi bi-list-ul me-1 text-primary"></i>Détails des frais — Niveau <?= h($classe['code_niveau']) ?></h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0">
        <div class="table-responsive">
          <table class="table table-sm mb-0" style="font-size:.8rem">
            <thead style="background:#fbfbfd"><tr>
              <th>Frais</th><th class="text-end">Montant dû</th><th class="text-end">Déjà payé</th><th class="text-end">Solde</th>
            </tr></thead>
            <tbody>
              <?php if (!$obligations_simulees): ?>
                <tr><td colspan="4" class="text-center text-muted py-3">Aucune obligation configurée pour ce niveau (onglet Obligations).</td></tr>
              <?php else: foreach ($obligations_simulees as $o): $paye = (float) $o['montant_obligation'] - $o['solde']; ?>
                <tr>
                  <td><?= h($o['nom_obligation']) ?></td>
                  <td class="text-end"><?= number_format((float) $o['montant_obligation'], 0, ',', ' ') ?> F</td>
                  <td class="text-end"><?= number_format($paye, 0, ',', ' ') ?> F</td>
                  <td class="text-end fw-bold" style="color:<?= $o['solde'] > 0 ? '#dc2626' : '#16a34a' ?>"><?= number_format($o['solde'], 0, ',', ' ') ?> F</td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
            <?php if ($obligations_simulees): ?>
            <tfoot><tr class="fw-bold"><td>TOTAL</td><td class="text-end"><?= number_format($total_du, 0, ',', ' ') ?> F</td><td class="text-end"><?= number_format($total_paye, 0, ',', ' ') ?> F</td><td class="text-end"><?= number_format($solde_global, 0, ',', ' ') ?> F</td></tr></tfoot>
            <?php endif; ?>
          </table>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Fermer</button>
      </div>
    </div>
  </div>
</div>

<script>
function ouvrirRecuFinancesPrive() {
    afficherApercu(
        '<?= APP_URL ?>/secondaire/pages/paiements_prives/recu.php?eleve=<?= $id_eleve ?>&classe=<?= $id_classe ?>',
        'Reçu de paiement', null, 'portrait'
    );
}
</script>

<?php if ($onglet === 'cotisation'): ?>
<?php if ($peut_gerer_paiements): ?>
<div class="card mb-2">
  <div class="card-header py-2" style="background:#f8faff"><span class="fw-semibold" style="font-size:.82rem">Enregistrer une cotisation</span></div>
  <div class="card-body">
    <div class="alert alert-light border py-2 mb-3" style="font-size:.78rem">
      <i class="bi bi-info-circle me-1"></i>
      Pas besoin de choisir le frais : le montant saisi est réparti automatiquement sur les frais du niveau,
      du <strong>plus petit au plus grand</strong> montant, jusqu'à épuisement.
    </div>
    <form method="post" id="form-cotiser-prive" data-ajax-post-form>
      <?= csrf_champ() ?>
      <input type="hidden" name="action" value="cotiser">
      <input type="hidden" name="id_eleve" value="<?= $id_eleve ?>">
      <input type="hidden" name="id_classe" value="<?= $id_classe ?>">
      <div class="row g-2 align-items-end">
        <div class="col-md-3">
          <label class="form-label">Montant versé (FCFA)</label>
          <input type="number" name="montant_paiement" id="cot-montant-prive" class="form-control form-control-sm" min="1" step="1" required>
        </div>
        <div class="col-md-2">
          <label class="form-label">Mode de paiement</label>
          <select name="mode_paiement" class="form-select form-select-sm">
            <?php foreach (finances_modes_paiement() as $code => $m): ?>
              <option value="<?= h($code) ?>" <?= $code === 'ESPECES' ? 'selected' : '' ?>><?= h($m['libelle']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Date</label>
          <input type="date" name="date_paiement" class="form-control form-control-sm" required value="<?= date('Y-m-d') ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Référence</label>
          <input type="text" name="ref_paiement" class="form-control form-control-sm" placeholder="optionnel">
        </div>
        <div class="col-md-2">
          <button class="btn btn-primary btn-sm w-100"><i class="bi bi-check-lg me-1"></i>Enregistrer</button>
        </div>
      </div>
    </form>
    <div id="cot-apercu-prive" class="mt-3" style="font-size:.8rem;display:none">
      <div class="fw-semibold mb-1" style="color:#374151">Répartition prévue :</div>
      <div id="cot-apercu-lignes-prive"></div>
    </div>
  </div>
</div>

<script>
var COT_SOLDES_PRIVE = <?= json_encode(array_values(array_map(fn($o) => ['nom' => $o['nom_obligation'], 'solde' => $o['solde']], array_filter($obligations_simulees, fn($o) => $o['solde'] > 0)))) ?>;
document.getElementById('cot-montant-prive').addEventListener('input', function() {
    const zone = document.getElementById('cot-apercu-prive');
    const lignes = document.getElementById('cot-apercu-lignes-prive');
    let montant = parseFloat(this.value) || 0;
    if (montant <= 0) { zone.style.display = 'none'; return; }
    let html = '';
    let restant = montant;
    for (const s of COT_SOLDES_PRIVE) {
        if (restant <= 0.009) break;
        const part = Math.min(restant, s.solde);
        restant -= part;
        html += '<div class="d-flex justify-content-between" style="max-width:320px"><span>' + s.nom + '</span><span class="fw-semibold">' + part.toLocaleString('fr-FR') + ' F</span></div>';
    }
    if (restant > 0.009) {
        html += '<div class="text-danger mt-1"><i class="bi bi-exclamation-triangle me-1"></i>Dépasse le solde restant de ' + restant.toLocaleString('fr-FR') + ' F</div>';
    }
    lignes.innerHTML = html;
    zone.style.display = '';
});
</script>
<?php endif; ?>
<?php endif; ?>

<?php if ($onglet === 'detail'): ?>
<?php if ($peut_gerer_paiements): ?>
<div class="card mb-2">
  <div class="card-header py-2" style="background:#f8faff"><span class="fw-semibold" style="font-size:.82rem">Enregistrer un versement</span></div>
  <div class="card-body">
    <form method="post" id="form-verser-prive" data-ajax-post-form>
      <?= csrf_champ() ?>
      <input type="hidden" name="action" value="verser">
      <input type="hidden" name="id_eleve" value="<?= $id_eleve ?>">
      <input type="hidden" name="id_classe" value="<?= $id_classe ?>">
      <div class="row g-2 align-items-end">
        <div class="col-md-3">
          <label class="form-label">Frais</label>
          <select name="id_obligation" class="form-select form-select-sm" required>
            <option value="">— Choisir —</option>
            <?php foreach ($obligations_simulees as $o): if ($o['solde'] <= 0) continue; ?>
              <option value="<?= (int) $o['id'] ?>"><?= h($o['nom_obligation']) ?> (solde <?= number_format($o['solde'], 0, ',', ' ') ?> F)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Montant (FCFA)</label>
          <input type="number" name="montant_paiement" class="form-control form-control-sm" min="1" step="1" required>
        </div>
        <div class="col-md-2">
          <label class="form-label">Mode de paiement</label>
          <select name="mode_paiement" class="form-select form-select-sm">
            <?php foreach (finances_modes_paiement() as $code => $m): ?>
              <option value="<?= h($code) ?>" <?= $code === 'ESPECES' ? 'selected' : '' ?>><?= h($m['libelle']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Date</label>
          <input type="date" name="date_paiement" class="form-control form-control-sm" required value="<?= date('Y-m-d') ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label">Référence</label>
          <input type="text" name="ref_paiement" class="form-control form-control-sm" placeholder="optionnel">
        </div>
        <div class="col-md-1">
          <button class="btn btn-primary btn-sm w-100"><i class="bi bi-check-lg"></i></button>
        </div>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

<div class="card">
  <div class="card-header py-2" style="background:#f8faff"><span class="fw-semibold" style="font-size:.82rem">Historique des versements</span></div>
  <div class="table-responsive">
    <table class="table table-abz table-hover align-middle mb-0" style="font-size:.8rem">
      <thead><tr><th>N° reçu</th><th>Frais</th><th class="text-end">Montant</th><th>Mode</th><th>Date</th><th>Référence</th><th class="text-center">Actions</th></tr></thead>
      <tbody>
        <?php foreach ($historique as $p): ?>
          <tr>
            <td class="text-muted" style="font-size:.75rem"><?= h(finances_numero_recu(finances_id_versement(['id_versement' => $p['id_versement'], 'id_pay' => $p['id']]))) ?></td>
            <td><?= h($p['nom_obligation']) ?></td>
            <td class="text-end"><?= number_format((float) $p['montant_paiement'], 0, ',', ' ') ?> F</td>
            <td><?= finances_mode_paiement_badge($p['mode_paiement'] ?? null) ?></td>
            <td><?= h(date_fr($p['date_paiement'])) ?></td>
            <td><?= h($p['ref_paiement'] ?: '—') ?></td>
            <td class="text-center">
              <?php if ($peut_gerer_paiements): ?>
              <button type="button" class="btn btn-sm btn-light" style="padding:2px 6px" title="Modifier"
                      onclick='ouvrirModifierPrive(<?= json_encode([
                          "id_pay" => (int) $p["id"], "id_obligation" => (int) $p["id_obligation"],
                          "montant" => (float) $p["montant_paiement"], "date" => $p["date_paiement"], "ref" => $p["ref_paiement"],
                          "mode_paiement" => finances_mode_paiement_normalise($p["mode_paiement"] ?? null),
                      ]) ?>)'>
                <i class="bi bi-pencil-square text-success" style="font-size:.78rem"></i>
              </button>
              <form method="post" class="d-inline" data-ajax-post-form onsubmit="return confirm('Supprimer ce versement ?')">
                <?= csrf_champ() ?>
                <input type="hidden" name="action" value="supprimer">
                <input type="hidden" name="id_pay" value="<?= (int) $p['id'] ?>">
                <input type="hidden" name="id_eleve" value="<?= $id_eleve ?>">
                <input type="hidden" name="id_classe" value="<?= $id_classe ?>">
                <button type="submit" class="btn btn-sm btn-light" style="padding:2px 6px" title="Supprimer">
                  <i class="bi bi-trash text-danger" style="font-size:.78rem"></i>
                </button>
              </form>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$historique): ?>
          <tr><td colspan="7" class="text-center text-muted py-3">Aucun versement enregistré.</td></tr>
        <?php endif; ?>
      </tbody>
      <?php if ($historique): ?>
      <tfoot><tr class="fw-bold"><td></td><td>TOTAL</td><td class="text-end"><?= number_format($total_paye, 0, ',', ' ') ?> F</td><td colspan="4"></td></tr></tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>

<div class="modal fade" id="modalVersementPrive" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header py-2" style="background:#f8faff">
        <h6 class="modal-title fw-bold"><i class="bi bi-pencil-square me-1 text-primary"></i>Modifier le versement</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" data-ajax-post-form>
        <?= csrf_champ() ?>
        <input type="hidden" name="action" value="modifier">
        <input type="hidden" name="id_pay" id="mvp-id">
        <input type="hidden" name="id_eleve" value="<?= $id_eleve ?>">
        <input type="hidden" name="id_classe" value="<?= $id_classe ?>">
        <div class="modal-body row g-2">
          <div class="col-12">
            <label class="form-label">Frais</label>
            <select name="id_obligation" id="mvp-obligation" class="form-select" required>
              <?php foreach ($obligations_niveau as $o): ?>
                <option value="<?= (int) $o['id'] ?>"><?= h($o['nom_obligation']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label">Montant</label>
            <input type="number" name="montant_paiement" id="mvp-montant" class="form-control" min="1" required>
          </div>
          <div class="col-6">
            <label class="form-label">Mode de paiement</label>
            <select name="mode_paiement" id="mvp-mode" class="form-select">
              <?php foreach (finances_modes_paiement() as $code => $m): ?>
                <option value="<?= h($code) ?>"><?= h($m['libelle']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label">Date</label>
            <input type="date" name="date_paiement" id="mvp-date" class="form-control" required>
          </div>
          <div class="col-6">
            <label class="form-label">Référence</label>
            <input type="text" name="ref_paiement" id="mvp-ref" class="form-control">
          </div>
        </div>
        <div class="modal-footer py-2">
          <button class="btn btn-primary btn-sm">Enregistrer</button>
          <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Annuler</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function ouvrirModifierPrive(p) {
    document.getElementById('mvp-id').value = p.id_pay;
    document.getElementById('mvp-obligation').value = p.id_obligation || '';
    document.getElementById('mvp-montant').value = p.montant;
    document.getElementById('mvp-mode').value = p.mode_paiement || 'ESPECES';
    document.getElementById('mvp-date').value = p.date;
    document.getElementById('mvp-ref').value = p.ref || '';
    new bootstrap.Modal(document.getElementById('modalVersementPrive')).show();
}
</script>

<?php endif; // eleve && classe ?>

<?php if ($onglet === 'impression'): ?>
<?php
$debut_imp = $_GET['debut'] ?? date('Y-m-d');
$fin_imp   = $_GET['fin'] ?? date('Y-m-d');
if ($fin_imp < $debut_imp) { [$debut_imp, $fin_imp] = [$fin_imp, $debut_imp]; }

$eleves_periode = prive_finances_eleves_payes_periode($id_annee, $debut_imp, $fin_imp, $id_classe, $id_eleve);
$imp_nb_eleves  = count($eleves_periode);
$imp_montant    = array_sum(array_column($eleves_periode, 'montant_periode'));
$imp_nom_classe = $id_classe ? db_val("SELECT designation FROM classe WHERE id=?", [$id_classe]) : null;

$imp_solde_par_eleve = [];
if ($eleves_periode) {
    $ids_imp = array_column($eleves_periode, 'id_eleve');
    $paye_annee_imp = [];
    $in_ids = implode(',', array_fill(0, count($ids_imp), '?'));
    foreach (db_all(
        "SELECT id_eleve, SUM(montant_paiement) AS paye FROM paiement_prive WHERE id_annee=? AND id_eleve IN ($in_ids) GROUP BY id_eleve",
        array_merge([$id_annee], $ids_imp)
    ) as $r) {
        $paye_annee_imp[(int) $r['id_eleve']] = (float) $r['paye'];
    }
    foreach (prive_finances_du_par_eleve($id_annee, $id_classe ?: null) as $d) {
        $id = (int) $d['id'];
        if (!in_array($id, $ids_imp, true)) continue;
        $imp_solde_par_eleve[$id] = $d['du'] - ($paye_annee_imp[$id] ?? 0.0);
    }
}
?>
<div class="card mb-2">
  <div class="card-header py-2" style="background:#f8faff"><span class="fw-semibold" style="font-size:.82rem">Date / période</span></div>
  <div class="card-body py-2">
    <form data-ajax-nav-form method="get" action="<?= APP_URL ?>/secondaire/pages/paiements_prives/versement.php" class="row g-2 align-items-end">
      <input type="hidden" name="onglet" value="impression">
      <input type="hidden" name="classe" value="<?= $id_classe ?>">
      <input type="hidden" name="eleve" value="<?= $id_eleve ?>">
      <div class="col-6 col-md-3">
        <label class="form-label">Du</label>
        <input type="date" name="debut" class="form-control form-control-sm" value="<?= h($debut_imp) ?>" data-ajax-nav-auto>
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label">Au</label>
        <input type="date" name="fin" class="form-control form-control-sm" value="<?= h($fin_imp) ?>" data-ajax-nav-auto>
      </div>
      <div class="col-md-3">
        <button class="btn btn-outline-secondary btn-sm"><i class="bi bi-funnel me-1"></i>Filtrer</button>
      </div>
    </form>
    <div class="form-text mt-1" style="font-size:.72rem">
      <i class="bi bi-info-circle me-1"></i>
      <?= $id_eleve && $eleve ? 'Élève : ' . h(mb_strtoupper($eleve['nom']) . ' ' . ($eleve['prenom'] ?? '')) : ($imp_nom_classe ? 'Classe : ' . h($imp_nom_classe) : 'Toutes les classes') ?>
      — utilisez les menus Classe/Élève ci-dessus pour restreindre.
    </div>
  </div>
</div>

<div class="row g-2 mb-2">
  <div class="col-4">
    <div class="card text-center py-2"><div class="text-muted" style="font-size:.68rem">ÉLÈVES CONCERNÉS</div><div class="fw-bold fs-5"><?= $imp_nb_eleves ?></div></div>
  </div>
  <div class="col-4">
    <div class="card text-center py-2"><div class="text-muted" style="font-size:.68rem">REÇUS</div><div class="fw-bold fs-5"><?= $imp_nb_eleves ?></div></div>
  </div>
  <div class="col-4">
    <div class="card text-center py-2"><div class="text-muted" style="font-size:.68rem">MONTANT ENCAISSÉ</div><div class="fw-bold fs-5"><?= number_format($imp_montant, 0, ',', ' ') ?> F</div></div>
  </div>
</div>

<div class="card">
  <div class="card-header py-2 d-flex justify-content-between align-items-center flex-wrap gap-2" style="background:#f8faff">
    <span class="fw-semibold" style="font-size:.82rem">
      Élèves ayant payé du <?= h(date_fr($debut_imp)) ?><?= $fin_imp !== $debut_imp ? ' au ' . h(date_fr($fin_imp)) : '' ?>
    </span>
    <button type="button" class="btn btn-primary btn-sm" <?= $imp_nb_eleves ? '' : 'disabled' ?>
            onclick="afficherApercu('<?= APP_URL ?>/secondaire/pages/paiements_prives/recus_lot.php?debut=<?= urlencode($debut_imp) ?>&fin=<?= urlencode($fin_imp) ?>&classe=<?= $id_classe ?>&eleve=<?= $id_eleve ?>', 'Reçus imprimés', null, 'portrait')">
      <i class="bi bi-printer me-1"></i>Imprimer<?= $imp_nb_eleves ? " ({$imp_nb_eleves})" : '' ?>
    </button>
  </div>
  <div class="table-responsive">
    <table class="table table-abz table-hover align-middle mb-0" style="font-size:.8rem">
      <thead><tr><th>Élève</th><th>Matricule</th><th>Classe</th><th class="text-end">Montant payé</th><th class="text-center">Actions</th></tr></thead>
      <tbody>
        <?php foreach ($eleves_periode as $ep):
          $id_ep    = (int) $ep['id_eleve'];
          $solde_ep = $imp_solde_par_eleve[$id_ep] ?? null;
        ?>
        <tr>
          <td><?= h(mb_strtoupper($ep['nom']) . ' ' . ($ep['prenom'] ?? '')) ?></td>
          <td><?= h($ep['matricule']) ?></td>
          <td><?= h($ep['designation']) ?></td>
          <td class="text-end"><?= number_format((float) $ep['montant_periode'], 0, ',', ' ') ?> F</td>
          <td class="text-center">
            <button type="button" class="btn btn-sm btn-light" style="padding:2px 6px" title="Imprimer le reçu"
                    onclick="afficherApercu('<?= APP_URL ?>/secondaire/pages/paiements_prives/recu.php?eleve=<?= $id_ep ?>&classe=<?= (int) $ep['id_classe'] ?>', 'Reçu de paiement', null, 'portrait')">
              <i class="bi bi-file-earmark-pdf text-danger"></i>
            </button>
            <?php if ($solde_ep !== null && $solde_ep > 0.01): ?>
              <a class="btn btn-sm btn-light" style="padding:2px 6px" title="Solde restant : <?= number_format($solde_ep, 0, ',', ' ') ?> F — enregistrer un paiement"
                 data-ajax-nav
                 href="<?= APP_URL ?>/secondaire/pages/paiements_prives/versement.php?onglet=cotisation&classe=<?= (int) $ep['id_classe'] ?>&eleve=<?= $id_ep ?>">
                <i class="bi bi-cash-coin text-success"></i>
              </a>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$eleves_periode): ?>
          <tr><td colspan="5" class="text-center text-muted py-3">Aucun élève n'a effectué de paiement sur cette période.</td></tr>
        <?php endif; ?>
      </tbody>
      <?php if ($eleves_periode): ?>
      <tfoot><tr class="fw-bold"><td colspan="3">TOTAL</td><td class="text-end"><?= number_format($imp_montant, 0, ',', ' ') ?> F</td><td></td></tr></tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>
<?php endif; // onglet === impression ?>

<script>
var selClassePrive = document.getElementById('selClasse');
var selElevePrive  = document.getElementById('selEleve');
var ONGLET_COURANT_PRIVE = <?= json_encode($onglet) ?>;
selClassePrive.addEventListener('change', function() {
    var classeId = this.value;
    var url = '<?= APP_URL ?>/secondaire/pages/paiements_prives/versement.php?onglet=' + ONGLET_COURANT_PRIVE
        + (classeId ? '&classe=' + encodeURIComponent(classeId) : '');
    chargerPartiel(url, 'versement-prive-zone');
});
selElevePrive.addEventListener('change', function() {
    if (!this.value) return;
    var url = '<?= APP_URL ?>/secondaire/pages/paiements_prives/versement.php?onglet=' + ONGLET_COURANT_PRIVE
        + '&classe=' + encodeURIComponent(selClassePrive.value) + '&eleve=' + encodeURIComponent(this.value);
    chargerPartiel(url, 'versement-prive-zone');
});
</script>

</div><!-- /#versement-prive-zone -->
<?php if ($es_partiel) exit; ?>

<?php
$ajax_zone_id = 'versement-prive-zone';
require_once __DIR__ . '/../../../layout/footer.php';
