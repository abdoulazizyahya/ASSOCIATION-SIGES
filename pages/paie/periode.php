<?php
// pages/paie/periode.php — Détail d'une période de paie : aperçu par
// enseignant, génération des bulletins, validation, marquage "Payé"
// (crée une ligne dans `depense`, catégorie "Salaires" — cohérence avec
// solde_caisse(), fonctions.php).
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/../../paie_fonctions.php';
exiger_role(['DIRECTEUR', 'FONDATEUR', 'COMPTABLE']);  // vue accordée à tous ; l'écriture reste réservée à COMPTABLE (ecriture_module_permise)

$id_periode = (int) ($_GET['id'] ?? 0);
$periode = db_one("SELECT * FROM periode_paie WHERE id=?", [$id_periode]);
if (!$periode) { flash_set('erreur', 'Période introuvable.'); rediriger('pages/paie/index.php'); }
$verrouillee = $periode['statut'] === 'Validée';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verifier();
    $action = post('action');

    if ($action === 'generer_un' && !$verrouillee) {
        $mat     = (int) post('mat');
        $avance  = (float) str_replace([' ', ','], ['', '.'], post('avance_a_deduire') ?: '0');
        $libelle_prime = trim(post('prime_libelle'));
        $montant_prime = (float) str_replace([' ', ','], ['', '.'], post('prime_montant') ?: '0');
        // Ligne enrichie optionnelle (code/base/taux %, type Gain ou Retenue) —
        // voir fonctions.php::generer_bulletin() : alimente le bulletin PDF
        // format CNPS (migration v35) avec le même niveau de détail que le
        // modèle fourni, sans jamais calculer de cotisation automatiquement
        // (barèmes légaux non fournis — le Directeur saisit ce qu'il veut
        // voir apparaître, code/base/taux compris).
        $type_ligne = post('ligne_type') === 'Retenue' ? 'Retenue' : 'Gain';
        $ligne_extra = [];
        if ($libelle_prime !== '' && $montant_prime > 0) {
            $ligne_extra = ['libelle' => $libelle_prime, 'montant' => $montant_prime];
            $code = trim(post('ligne_code'));
            $nb   = trim(post('ligne_nb'));
            $base = trim(post('ligne_base'));
            $taux = trim(post('ligne_taux'));
            if ($code !== '') $ligne_extra['code'] = $code;
            if ($nb   !== '') $ligne_extra['nb']   = (float) str_replace(',', '.', $nb);
            if ($base !== '') $ligne_extra['base'] = (float) str_replace([' ', ','], ['', '.'], $base);
            if ($taux !== '') $ligne_extra['taux_pct'] = (float) str_replace(',', '.', $taux);
        }
        $primes    = ($ligne_extra && $type_ligne === 'Gain') ? [$ligne_extra] : [];
        $retenues  = ($ligne_extra && $type_ligne === 'Retenue') ? [$ligne_extra] : [];
        if ($mat) {
            generer_bulletin($id_periode, $mat, (int) $periode['mois'], (int) $periode['annee'], $avance, $primes, $retenues);
            flash_set('succes', 'Bulletin généré.');
        }
        rediriger('pages/paie/periode.php?id=' . $id_periode);
    }

    if ($action === 'generer_manquants' && !$verrouillee) {
        $deja = array_column(db_all("SELECT matricule_ens FROM bulletin_paie WHERE id_periode=?", [$id_periode]), 'matricule_ens');
        $actifs = db_all("SELECT matricule_ens FROM enseignant WHERE COALESCE(statut_ens,'actif')='actif'");
        $nb = 0;
        foreach ($actifs as $e) {
            if (in_array((int) $e['matricule_ens'], array_map('intval', $deja), true)) continue;
            generer_bulletin($id_periode, (int) $e['matricule_ens'], (int) $periode['mois'], (int) $periode['annee']);
            $nb++;
        }
        flash_set('succes', "$nb bulletin(s) généré(s).");
        rediriger('pages/paie/periode.php?id=' . $id_periode);
    }

    if ($action === 'supprimer_bulletin' && !$verrouillee) {
        $id_bulletin = (int) post('id_bulletin');
        $b = db_one("SELECT statut FROM bulletin_paie WHERE id=? AND id_periode=?", [$id_bulletin, $id_periode]);
        if ($b && $b['statut'] === 'Payé') {
            flash_set('erreur', 'Impossible de supprimer un bulletin déjà marqué payé.');
        } else {
            db_exec("DELETE FROM bulletin_paie WHERE id=? AND id_periode=?", [$id_bulletin, $id_periode]);
            flash_set('succes', 'Bulletin supprimé.');
        }
        rediriger('pages/paie/periode.php?id=' . $id_periode);
    }

    if ($action === 'marquer_paye') {
        $id_bulletin = (int) post('id_bulletin');
        $mode        = post('mode_paiement') ?: null;
        $reference   = post('reference_paiement') ?: null;
        $date_pay    = post('date_paiement') ?: date('Y-m-d');
        // Vérifie que le bulletin appartient bien à CETTE période avant de le
        // marquer payé (défense en profondeur — marquer_bulletin_paye() ne le
        // vérifie pas elle-même, réutilisable telle quelle pour le paiement
        // groupé ci-dessous).
        $appartient = (bool) db_val("SELECT COUNT(*) FROM bulletin_paie WHERE id=? AND id_periode=?", [$id_bulletin, $id_periode]);
        if ($appartient) {
            $resultat = marquer_bulletin_paye($id_bulletin, $mode, $reference, $date_pay);
            if ($resultat['ok']) {
                flash_set('succes', 'Bulletin marqué payé' . ($resultat['depense_creee'] ? ' — dépense enregistrée (catégorie Salaires).' : ' (catégorie « Salaires » introuvable, dépense non enregistrée).'));
            }
        }
        rediriger('pages/paie/periode.php?id=' . $id_periode);
    }

    if ($action === 'marquer_payes_groupe') {
        $mode      = post('mode_paiement') ?: null;
        $reference = post('reference_paiement') ?: null;
        $date_pay  = post('date_paiement') ?: date('Y-m-d');
        $ids_brut  = array_values(array_unique(array_filter(array_map('intval', $_POST['ids'] ?? []))));
        $ids = [];
        if ($ids_brut) {
            $in     = implode(',', array_fill(0, count($ids_brut), '?'));
            $valides = db_all("SELECT id FROM bulletin_paie WHERE id_periode=? AND id IN ($in)", array_merge([$id_periode], $ids_brut));
            $ids = array_map('intval', array_column($valides, 'id'));
        }
        $nb = 0;
        foreach ($ids as $id_bulletin) {
            $resultat = marquer_bulletin_paye($id_bulletin, $mode, $reference, $date_pay);
            if ($resultat['ok']) $nb++;
        }
        flash_set($nb > 0 ? 'succes' : 'erreur', $nb > 0 ? "$nb bulletin(s) marqué(s) payé(s)." : 'Aucun bulletin à marquer payé (déjà payé(s) ou sélection vide).');
        rediriger('pages/paie/periode.php?id=' . $id_periode);
    }

    if ($action === 'valider_periode' && !$verrouillee) {
        $nb_bulletins = (int) db_val("SELECT COUNT(*) FROM bulletin_paie WHERE id_periode=?", [$id_periode]);
        if ($nb_bulletins === 0) {
            flash_set('erreur', 'Aucun bulletin généré — rien à valider.');
        } else {
            db_exec("UPDATE periode_paie SET statut='Validée', date_validation=NOW() WHERE id=?", [$id_periode]);
            flash_set('succes', 'Période validée — les bulletins ne peuvent plus être régénérés ni supprimés.');
        }
        rediriger('pages/paie/periode.php?id=' . $id_periode);
    }

    if ($action === 'rouvrir_periode') {
        db_exec("UPDATE periode_paie SET statut='Brouillon', date_validation=NULL WHERE id=?", [$id_periode]);
        flash_set('succes', 'Période rouverte.');
        rediriger('pages/paie/periode.php?id=' . $id_periode);
    }
}

$bulletins = db_all(
    "SELECT b.*, e.nom_ens, e.prenom_ens FROM bulletin_paie b JOIN enseignant e ON e.matricule_ens=b.matricule_ens
     WHERE b.id_periode=? ORDER BY e.nom_ens", [$id_periode]
);
$mats_avec_bulletin = array_map('intval', array_column($bulletins, 'matricule_ens'));

$sans_bulletin = db_all(
    "SELECT matricule_ens, nom_ens, prenom_ens, id_grade FROM enseignant WHERE COALESCE(statut_ens,'actif')='actif' ORDER BY nom_ens"
);
$sans_bulletin = array_filter($sans_bulletin, fn($e) => !in_array((int) $e['matricule_ens'], $mats_avec_bulletin, true));

// Aperçu pour chaque enseignant sans bulletin — permet d'ajuster l'avance à
// déduire avant génération individuelle sans avoir à ouvrir un autre écran.
foreach ($sans_bulletin as &$e) {
    $e['apercu'] = calculer_apercu_bulletin((int) $e['matricule_ens'], (int) $periode['mois'], (int) $periode['annee']);
}
unset($e);

$total_net = array_sum(array_column($bulletins, 'net_a_payer'));
$reste_a_payer = array_sum(array_column(array_filter($bulletins, fn($b) => $b['statut'] !== 'Payé'), 'net_a_payer'));

$es_partiel = isset($_GET['partiel']);
if (!$es_partiel) {
    $titre_page = 'Paie — ' . $periode['libelle'];
    require_once __DIR__ . '/../../layout/header.php';
} else {
    header('Content-Type: text/html; charset=utf-8');
}
?>

<div id="periode-paie-zone">

<div class="page-titre">
  <div>
    <h4><i class="bi bi-cash-stack me-1 text-primary"></i>Paie — <?= h($periode['libelle']) ?></h4>
    <div class="sub">
      <span class="badge" style="background:<?= $verrouillee ? '#dcfce7' : '#fef3c7' ?>;color:<?= $verrouillee ? '#166534' : '#92400e' ?>;font-size:.7rem"><?= h($periode['statut']) ?></span>
    </div>
  </div>
  <div class="d-flex gap-2">
    <?php if (!$verrouillee): ?>
      <form method="post" data-ajax-post-form onsubmit="return confirm('Valider cette période ? Les bulletins ne pourront plus être régénérés ni supprimés.')">
        <?= csrf_champ() ?>
        <input type="hidden" name="action" value="valider_periode">
        <button class="btn btn-success btn-sm"><i class="bi bi-check-lg me-1"></i>Valider la période</button>
      </form>
    <?php else: ?>
      <form method="post" data-ajax-post-form onsubmit="return confirm('Rouvrir cette période pour modification ?')">
        <?= csrf_champ() ?>
        <input type="hidden" name="action" value="rouvrir_periode">
        <button class="btn btn-outline-warning btn-sm"><i class="bi bi-unlock me-1"></i>Rouvrir</button>
      </form>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/pages/paie/index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Retour</a>
  </div>
</div>

<div class="row g-2 mb-3">
  <div class="col-6 col-md-3"><div class="card"><div class="card-body py-2"><div class="text-muted" style="font-size:.68rem">BULLETINS</div><div class="fw-bold" style="font-size:1.1rem"><?= count($bulletins) ?></div></div></div></div>
  <div class="col-6 col-md-3"><div class="card"><div class="card-body py-2"><div class="text-muted" style="font-size:.68rem">TOTAL NET</div><div class="fw-bold" style="font-size:1.1rem"><?= number_format($total_net, 0, ',', ' ') ?> F</div></div></div></div>
  <div class="col-6 col-md-3"><div class="card"><div class="card-body py-2"><div class="text-muted" style="font-size:.68rem">PAYÉS</div><div class="fw-bold" style="font-size:1.1rem"><?= count(array_filter($bulletins, fn($b) => $b['statut'] === 'Payé')) ?></div></div></div></div>
  <div class="col-6 col-md-3"><div class="card"><div class="card-body py-2"><div class="text-muted" style="font-size:.68rem">RESTE À PAYER</div><div class="fw-bold" style="font-size:1.1rem;color:<?= $reste_a_payer > 0 ? '#dc2626' : '#16a34a' ?>"><?= number_format($reste_a_payer, 0, ',', ' ') ?> F</div></div></div></div>
  <div class="col-6 col-md-3"><div class="card"><div class="card-body py-2"><div class="text-muted" style="font-size:.68rem">SANS BULLETIN</div><div class="fw-bold" style="font-size:1.1rem"><?= count($sans_bulletin) ?></div></div></div></div>
</div>

<?php if (!$verrouillee && $sans_bulletin): ?>
<div class="card mb-3">
  <div class="card-header py-2 d-flex justify-content-between align-items-center" style="background:#f8faff">
    <span class="fw-semibold" style="font-size:.82rem"><i class="bi bi-hourglass-split me-1"></i>Personnel sans bulletin (<?= count($sans_bulletin) ?>)</span>
    <form method="post" data-ajax-post-form onsubmit="return confirm('Générer un bulletin standard (sans avance ni prime) pour tout le personnel actif restant ?')">
      <?= csrf_champ() ?>
      <input type="hidden" name="action" value="generer_manquants">
      <button class="btn btn-primary btn-sm"><i class="bi bi-lightning-charge me-1"></i>Générer tout (standard)</button>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table table-abz table-hover align-middle mb-0" style="font-size:.8rem">
      <thead><tr><th>Enseignant</th><th>Grade</th><th class="text-end">Brut estimé</th><th class="text-end">Absences</th><th class="text-end">Avances dues</th><th class="text-end">Action</th></tr></thead>
      <tbody>
        <?php foreach ($sans_bulletin as $e): $a = $e['apercu']; $mat_e = (int) $e['matricule_ens'];
          $nom_complet = trim(mb_strtoupper($e['nom_ens']) . ' ' . ($e['prenom_ens'] ?? ''));
          $donnees_modale = [
              'mat' => $mat_e, 'nom' => $nom_complet, 'grade' => $a['grade']['libelle_grade'] ?? '—',
              'brut' => number_format($a['brut'], 0, ',', ' '),
              'joursAbsence' => $a['jours_absence'], 'montantAbsence' => number_format($a['montant_absence'], 0, ',', ' '),
              'avancesDues' => (float) $a['total_du_avances'], 'avancesDuesFmt' => number_format($a['total_du_avances'], 0, ',', ' '),
          ];
        ?>
        <tr>
          <td class="fw-semibold"><?= h($nom_complet) ?></td>
          <td><?= h($a['grade']['libelle_grade'] ?? '—') ?></td>
          <td class="text-end"><?= number_format($a['brut'], 0, ',', ' ') ?> F</td>
          <td class="text-end" style="color:<?= $a['montant_absence'] > 0 ? '#dc2626' : 'inherit' ?>"><?= $a['jours_absence'] ?> j — <?= number_format($a['montant_absence'], 0, ',', ' ') ?> F</td>
          <td class="text-end"><?= number_format($a['total_du_avances'], 0, ',', ' ') ?> F</td>
          <td class="text-end">
              <button type="button" class="btn btn-sm btn-primary" style="padding:3px 10px" onclick='ouvrirGenerer(<?= json_encode($donnees_modale, JSON_UNESCAPED_UNICODE) ?>)'>
                <i class="bi bi-file-earmark-plus me-1"></i>Générer
              </button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modale « Générer un bulletin » — un enseignant à la fois, remplace
     l'ancienne ligne de tableau surchargée (avance/prime/code/base/taux
     entassés dans des <td> minuscules avec un formulaire caché partagé).
     Même principe que #modalGrade (pages/paie/grille.php) : bouton -> JS
     préremplit la modale via un objet JSON -> vrai <form> normal à
     l'intérieur (plus besoin de recopier des valeurs vers un formulaire
     caché, possible ici car on n'est plus contraint par des <td>). Les
     champs CNPS avancés (Code/Base/Taux %, colonnes NULLABLE — migration_v35,
     utiles seulement ponctuellement) sont repliés sous <details>, fermé par
     défaut. -->
<div class="modal fade" id="modalGenererBulletin" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header py-2" style="background:#f8faff">
        <h6 class="modal-title fw-bold" id="mg-titre"><i class="bi bi-file-earmark-plus me-1 text-primary"></i>Générer un bulletin</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" data-ajax-post-form>
        <?= csrf_champ() ?>
        <input type="hidden" name="action" value="generer_un">
        <input type="hidden" name="mat" id="mg-mat">
        <div class="modal-body">
          <div class="row g-2 mb-3">
            <div class="col-6 col-md-3">
              <div class="text-muted" style="font-size:.68rem">GRADE</div>
              <div class="fw-semibold" id="mg-grade" style="font-size:.85rem">—</div>
            </div>
            <div class="col-6 col-md-3">
              <div class="text-muted" style="font-size:.68rem">BRUT ESTIMÉ</div>
              <div class="fw-semibold" id="mg-brut" style="font-size:.85rem">—</div>
            </div>
            <div class="col-6 col-md-3">
              <div class="text-muted" style="font-size:.68rem">ABSENCES</div>
              <div class="fw-semibold" id="mg-absences" style="font-size:.85rem">—</div>
            </div>
            <div class="col-6 col-md-3">
              <div class="text-muted" style="font-size:.68rem">AVANCES DUES</div>
              <div class="fw-semibold" id="mg-avances-dues" style="font-size:.85rem">—</div>
            </div>
          </div>

          <div class="row g-2 align-items-end mb-2">
            <div class="col-md-5">
              <label class="form-label">Avance à déduire ce mois (FCFA)</label>
              <input type="number" name="avance_a_deduire" id="mg-avance" class="form-control" min="0" step="1" value="0">
              <div class="form-text" style="font-size:.68rem" id="mg-avance-aide">Plafonné automatiquement au total dû.</div>
            </div>
          </div>

          <div class="border-top pt-3 mt-1">
            <label class="form-label mb-1">Ligne complémentaire <span class="text-muted fw-normal">(prime ou retenue ponctuelle — optionnel)</span></label>
            <div class="row g-2">
              <div class="col-md-3">
                <select name="ligne_type" class="form-select">
                  <option value="Gain">Gain (prime)</option>
                  <option value="Retenue">Retenue</option>
                </select>
              </div>
              <div class="col-md-5">
                <input type="text" name="prime_libelle" class="form-control" placeholder="Libellé (ex. Prime de rendement)">
              </div>
              <div class="col-md-4">
                <input type="number" name="prime_montant" class="form-control" min="0" step="1" placeholder="Montant">
              </div>
            </div>
          </div>

          <details class="mt-3">
            <summary style="cursor:pointer;font-size:.82rem;font-weight:600;color:#1e4fd8">Options avancées (format CNPS)</summary>
            <p style="font-size:.72rem;color:#6b7280;margin:.5rem 0">
              Alimentent le tableau détaillé du bulletin PDF format CNPS (colonnes Code/Base/Taux %) — à renseigner
              seulement si nécessaire, jamais calculées automatiquement (barèmes légaux non intégrés).
            </p>
            <div class="row g-2">
              <div class="col-md-4">
                <label class="form-label">Code rubrique</label>
                <input type="text" name="ligne_code" class="form-control">
              </div>
              <div class="col-md-4">
                <label class="form-label">Base de calcul</label>
                <input type="number" name="ligne_base" class="form-control" min="0" step="1">
              </div>
              <div class="col-md-4">
                <label class="form-label">Taux %</label>
                <input type="number" name="ligne_taux" class="form-control" min="0" step="0.01">
              </div>
            </div>
          </details>
        </div>
        <div class="modal-footer py-2">
          <button class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Générer le bulletin</button>
          <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Annuler</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
function ouvrirGenerer(d) {
    document.getElementById('mg-titre').innerHTML = '<i class="bi bi-file-earmark-plus me-1 text-primary"></i>Générer un bulletin — ' + d.nom;
    document.getElementById('mg-mat').value = d.mat;
    document.getElementById('mg-grade').textContent = d.grade;
    document.getElementById('mg-brut').textContent = d.brut + ' F';
    document.getElementById('mg-absences').textContent = d.joursAbsence + ' j — ' + d.montantAbsence + ' F';
    document.getElementById('mg-avances-dues').textContent = d.avancesDuesFmt + ' F';
    var avance = document.getElementById('mg-avance');
    avance.value = 0;
    avance.max = d.avancesDues;
    document.getElementById('mg-avance-aide').textContent = 'Dû : ' + d.avancesDuesFmt + ' F — plafonné automatiquement.';
    new bootstrap.Modal(document.getElementById('modalGenererBulletin')).show();
}
</script>
<?php elseif (!$sans_bulletin): ?>
  <div class="alert alert-success py-2"><i class="bi bi-check-circle me-1"></i>Tout le personnel actif a un bulletin pour cette période.</div>
<?php endif; ?>

<div class="card">
  <div class="card-header py-2 d-flex flex-wrap justify-content-between align-items-center gap-2" style="background:#f8faff">
    <span class="fw-semibold" style="font-size:.82rem"><i class="bi bi-receipt me-1"></i>Bulletins générés (<?= count($bulletins) ?>)</span>
    <div class="d-flex flex-wrap gap-2 align-items-center">
      <input type="text" id="bp-recherche" class="form-control form-control-sm" style="width:170px" placeholder="Rechercher un nom…" oninput="filtrerBulletins()">
      <select id="bp-filtre-statut" class="form-select form-select-sm" style="width:140px" onchange="filtrerBulletins()">
        <option value="">Tous les statuts</option>
        <option value="Payé">Payés</option>
        <option value="En attente">En attente</option>
      </select>
      <button type="button" class="btn btn-success btn-sm" id="bp-btn-groupe" onclick="ouvrirPaiementGroupe()" disabled>
        <i class="bi bi-cash-coin me-1"></i>Marquer payés (<span id="bp-nb-selection">0</span>)
      </button>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table table-abz table-hover align-middle mb-0" style="font-size:.8rem" id="bp-table">
      <thead>
        <tr>
          <th style="width:30px"><input type="checkbox" id="bp-tout" onchange="toggleTout(this)" title="Tout sélectionner"></th>
          <th>N°</th><th>Enseignant</th><th class="text-end">Brut</th><th class="text-end">Retenues</th><th class="text-end">Net à payer</th><th>Statut</th><th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$bulletins): ?>
          <tr><td colspan="8" class="text-center text-muted py-3">Aucun bulletin généré pour l'instant.</td></tr>
        <?php else: foreach ($bulletins as $b):
          $nom_b = trim(mb_strtoupper($b['nom_ens']) . ' ' . ($b['prenom_ens'] ?? ''));
        ?>
          <tr data-nom="<?= h(mb_strtolower($nom_b)) ?>" data-statut="<?= $b['statut'] === 'Payé' ? 'Payé' : 'En attente' ?>">
            <td>
              <?php if ($b['statut'] !== 'Payé'): ?>
                <input type="checkbox" class="bp-case" value="<?= (int) $b['id'] ?>" onchange="majSelection()">
              <?php endif; ?>
            </td>
            <td class="text-muted"><?= h(numero_bulletin((int) $b['id'])) ?></td>
            <td class="fw-semibold"><?= h($nom_b) ?></td>
            <td class="text-end"><?= number_format((float) $b['brut'], 0, ',', ' ') ?> F</td>
            <td class="text-end"><?= number_format((float) $b['total_retenues'], 0, ',', ' ') ?> F</td>
            <td class="text-end fw-bold"><?= number_format((float) $b['net_a_payer'], 0, ',', ' ') ?> F</td>
            <td>
              <span class="badge" style="background:<?= $b['statut'] === 'Payé' ? '#dcfce7' : '#fef3c7' ?>;color:<?= $b['statut'] === 'Payé' ? '#166534' : '#92400e' ?>;font-size:.68rem">
                <?= h($b['statut']) ?>
              </span>
            </td>
            <td class="text-end">
              <a href="<?= APP_URL ?>/pages/paie/bulletin.php?id=<?= (int) $b['id'] ?>" class="btn btn-sm btn-light" style="padding:2px 6px" title="Détail">
                <i class="bi bi-eye" style="font-size:.75rem"></i>
              </a>
              <button type="button" class="btn btn-sm btn-light" style="padding:2px 6px" title="Aperçu PDF"
                      onclick="afficherApercu('<?= APP_URL ?>/pdf/bulletin_paie.php?id=<?= (int) $b['id'] ?>', 'Bulletin <?= h(numero_bulletin((int) $b['id'])) ?>', null, 'portrait')">
                <i class="bi bi-file-earmark-pdf text-danger" style="font-size:.75rem"></i>
              </button>
              <?php if ($b['statut'] !== 'Payé'): ?>
                <button type="button" class="btn btn-sm btn-light" style="padding:2px 6px" title="Marquer payé"
                        onclick='ouvrirPaiement(<?= (int) $b['id'] ?>)'>
                  <i class="bi bi-cash-coin text-success" style="font-size:.75rem"></i>
                </button>
                <?php if (!$verrouillee): ?>
                <form method="post" class="d-inline" data-ajax-post-form onsubmit="return confirm('Supprimer ce bulletin ?')">
                  <?= csrf_champ() ?>
                  <input type="hidden" name="action" value="supprimer_bulletin">
                  <input type="hidden" name="id_bulletin" value="<?= (int) $b['id'] ?>">
                  <button class="btn btn-sm btn-light" style="padding:2px 6px" title="Supprimer"><i class="bi bi-trash text-danger" style="font-size:.75rem"></i></button>
                </form>
                <?php endif; ?>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modale Marquer payé — réutilisée pour un paiement individuel
     (ouvrirPaiement(), inchangé) ET pour un paiement groupé (nouvelle
     ouvrirPaiementGroupe(), depuis la sélection de cases à cocher ci-dessus) :
     seul le champ caché #mp-action change entre les deux, voir le JS plus
     bas. -->
<div class="modal fade" id="modalPaiement" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header py-2" style="background:#f8faff">
        <h6 class="modal-title fw-bold" id="mp-titre"><i class="bi bi-cash-coin me-1 text-success"></i>Marquer le bulletin comme payé</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" data-ajax-post-form>
        <?= csrf_champ() ?>
        <input type="hidden" name="action" id="mp-action" value="marquer_paye">
        <input type="hidden" name="id_bulletin" id="mp-id">
        <div id="mp-ids-conteneur"></div>
        <div class="modal-body row g-2">
          <div class="col-12">
            <label class="form-label">Mode de paiement</label>
            <select name="mode_paiement" class="form-select">
              <?php foreach (['Espèces', 'Virement bancaire', 'Mobile Money'] as $m): ?>
                <option value="<?= $m ?>"><?= $m ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label">Date de paiement</label>
            <input type="date" name="date_paiement" class="form-control" value="<?= date('Y-m-d') ?>">
          </div>
          <div class="col-6">
            <label class="form-label">Référence</label>
            <input type="text" name="reference_paiement" class="form-control" placeholder="optionnel">
          </div>
          <div class="col-12">
            <div class="alert alert-light border py-2 mb-0" style="font-size:.76rem">
              <i class="bi bi-info-circle me-1"></i>Une dépense sera automatiquement enregistrée (catégorie « Salaires »).
            </div>
          </div>
        </div>
        <div class="modal-footer py-2">
          <button class="btn btn-success btn-sm">Confirmer le paiement</button>
          <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Annuler</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// ── Recherche + filtre statut (client, liste déjà chargée) ────────────
function filtrerBulletins() {
    var q = document.getElementById('bp-recherche').value.trim().toLowerCase();
    var statut = document.getElementById('bp-filtre-statut').value;
    document.querySelectorAll('#bp-table tbody tr[data-nom]').forEach(function (tr) {
        var okNom = !q || tr.dataset.nom.indexOf(q) !== -1;
        var okStatut = !statut || tr.dataset.statut === statut;
        tr.style.display = (okNom && okStatut) ? '' : 'none';
    });
}

// ── Sélection multiple pour le paiement groupé ─────────────────────────
function toggleTout(cb) {
    document.querySelectorAll('#bp-table tbody .bp-case').forEach(function (c) {
        if (c.closest('tr').offsetParent !== null) c.checked = cb.checked; // ignore les lignes masquées par le filtre
    });
    majSelection();
}
function majSelection() {
    var n = document.querySelectorAll('.bp-case:checked').length;
    document.getElementById('bp-nb-selection').textContent = n;
    document.getElementById('bp-btn-groupe').disabled = n === 0;
}

// ── Modale "Marquer payé" (individuel ou groupé) ───────────────────────
function ouvrirPaiement(idBulletin) {
    document.getElementById('mp-titre').innerHTML = '<i class="bi bi-cash-coin me-1 text-success"></i>Marquer le bulletin comme payé';
    document.getElementById('mp-action').value = 'marquer_paye';
    document.getElementById('mp-id').value = idBulletin;
    document.getElementById('mp-ids-conteneur').innerHTML = '';
    new bootstrap.Modal(document.getElementById('modalPaiement')).show();
}
function ouvrirPaiementGroupe() {
    var ids = Array.from(document.querySelectorAll('.bp-case:checked')).map(function (c) { return c.value; });
    if (!ids.length) return;
    document.getElementById('mp-titre').innerHTML = '<i class="bi bi-cash-coin me-1 text-success"></i>Marquer ' + ids.length + ' bulletin(s) comme payés';
    document.getElementById('mp-action').value = 'marquer_payes_groupe';
    document.getElementById('mp-id').value = '';
    var conteneur = document.getElementById('mp-ids-conteneur');
    conteneur.innerHTML = '';
    ids.forEach(function (id) {
        var inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = 'ids[]'; inp.value = id;
        conteneur.appendChild(inp);
    });
    new bootstrap.Modal(document.getElementById('modalPaiement')).show();
}
</script>

</div><!-- /#periode-paie-zone -->
<?php if ($es_partiel) exit; // rien de plus dans une réponse AJAX partielle. ?>

<?php
$ajax_zone_id = 'periode-paie-zone'; // voir layout/footer.php — initAjaxZone() y est appelé
require_once __DIR__ . '/../../layout/footer.php';
